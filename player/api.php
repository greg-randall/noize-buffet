<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib/db.php';

header('Content-Type: application/json; charset=utf-8');

function respond(mixed $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Only this page may use the API. Refuses a Host other than localhost (DNS rebinding: another site's name pointed
 * at 127.0.0.1) and an Origin other than this server's own (another website in the same browser).
 */
function require_same_site(): void
{
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $name = preg_replace('/:\d+$/', '', $host);
    if (!in_array($name, ['localhost', '127.0.0.1', '[::1]'], true)) {
        respond(['ok' => false, 'error' => 'this server only answers on localhost'], 403);
    }
    $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
    if ($origin !== null) {
        $o = parse_url((string)$origin);
        // parse_url keeps an IPv6 host's brackets ("[::1]"), as the Host header does.
        $originHost = strtolower(($o['host'] ?? '') . (isset($o['port']) ? ":{$o['port']}" : ''));
        if ($originHost !== $host) {
            respond(['ok' => false, 'error' => 'requests from other websites are refused'], 403);
        }
    }
}

function require_post(): array
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(['ok' => false, 'error' => 'POST required'], 405);
    }
    // A JSON body can't be sent cross-site without a CORS preflight, which this server never approves;
    // text/plain and form posts can, so they are refused.
    if (!preg_match('#^application/json\b#i', (string)($_SERVER['CONTENT_TYPE'] ?? ''))) {
        respond(['ok' => false, 'error' => 'the body must be sent as application/json'], 415);
    }
    $in = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($in)) {
        respond(['ok' => false, 'error' => 'body must be a JSON object'], 400);
    }
    return $in;
}

require_same_site();
try {
    $pdo = nb_db();
    switch ($_GET['action'] ?? '') {
        case 'queue':
            respond(nb_queue($pdo));

        case 'save':
            respond(['ok' => true, 'row' => nb_save_listen($pdo, require_post())]);

        case 'chat':
            // Long-poll: with wait=1, hold the request until there are new messages or the job state (or activity) changes
            // (compared with the `state` the client last saw), or 25 seconds pass.
            $after = (int)($_GET['after'] ?? 0);
            $known = (string)($_GET['state'] ?? '');
            $deadline = microtime(true) + (empty($_GET['wait']) ? 0 : 25);
            while (true) {
                $messages = nb_chat_since($pdo, $after);
                $jobs = nb_job_status($pdo);
                $state = ($jobs['running']['id'] ?? 0) . ':' . $jobs['queued'] . ':' . ($jobs['running']['activity'] ?? '');
                if ($messages || $state !== $known || microtime(true) >= $deadline) {
                    respond(['messages' => $messages, 'jobs' => $jobs, 'state' => $state]);
                }
                usleep(200000);
            }

        case 'send':
            $in = require_post();
            $message = trim((string)($in['message'] ?? ''));
            if ($message === '') {
                respond(['ok' => false, 'error' => 'empty message'], 400);
            }
            $chatId = nb_chat_add($pdo, 'user', $message);
            $jobId = nb_job_enqueue($pdo, 'chat', ['message' => $message, 'song' => nb_song_context($in['song'] ?? null)]);
            respond(['ok' => true, 'chat_id' => $chatId, 'job_id' => $jobId]);

        case 'start':
            require_post();
            // Under the lock, so two page loads at once can't both start the interview.
            $empty = nb_locked($pdo, function () use ($pdo): bool {
                $empty = (int)$pdo->query('SELECT COUNT(*) FROM chat')->fetchColumn() === 0
                    && (int)$pdo->query('SELECT COUNT(*) FROM jobs')->fetchColumn() === 0;
                if ($empty) {
                    // Shown straight away: the agent's first real message takes a while (start-up, reading its files).
                    nb_chat_add($pdo, 'parent', 'Loading things up, one moment please…');
                    nb_job_enqueue($pdo, 'interview');
                }
                return $empty;
            });
            respond(['ok' => true, 'started' => $empty]);

        case 'mining':
            $strengths = array_count_values(array_column(nb_leads($pdo), 'strength'));
            respond(['videos' => nb_mining_list($pdo), 'usage_paused' => nb_usage_paused($pdo),
                'leads' => ['confirmed' => $strengths['confirmed'] ?? 0, 'hints' => $strengths['hint'] ?? 0]]);

        default:
            respond(['ok' => false, 'error' => 'unknown action'], 400);
    }
} catch (InvalidArgumentException $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    $msg = get_class($e) . ': ' . $e->getMessage();
    @file_put_contents(dirname(nb_db_path()) . '/api-errors.log', sprintf("[%s] %s %s\n%s\n%s\n\n",
        nb_now(), $_SERVER['REQUEST_METHOD'] ?? '', $_SERVER['REQUEST_URI'] ?? '', $msg, $e->getTraceAsString()), FILE_APPEND);
    respond(['ok' => false, 'error' => $msg], 500);
}
