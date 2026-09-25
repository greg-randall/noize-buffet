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

function require_post(): array
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(['ok' => false, 'error' => 'POST required'], 405);
    }
    $in = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($in)) {
        respond(['ok' => false, 'error' => 'body must be a JSON object'], 400);
    }
    return $in;
}

try {
    $pdo = nb_db();
    switch ($_GET['action'] ?? '') {
        case 'queue':
            respond(nb_queue($pdo));

        case 'save':
            respond(['ok' => true, 'row' => nb_save_listen($pdo, require_post())]);

        case 'chat':
            // Long-poll: with wait=1, hold the request until there are new messages or the job state changes
            // (compared with the `state` the client last saw), or 25 seconds pass.
            $after = (int)($_GET['after'] ?? 0);
            $known = (string)($_GET['state'] ?? '');
            $deadline = microtime(true) + (empty($_GET['wait']) ? 0 : 25);
            while (true) {
                $messages = nb_chat_since($pdo, $after);
                $jobs = nb_job_status($pdo);
                $state = ($jobs['running']['id'] ?? 0) . ':' . $jobs['queued'];
                if ($messages || $state !== $known || microtime(true) >= $deadline) {
                    respond(['messages' => $messages, 'jobs' => $jobs, 'state' => $state]);
                }
                usleep(200000);
            }

        case 'send':
            $message = trim((string)(require_post()['message'] ?? ''));
            if ($message === '') {
                respond(['ok' => false, 'error' => 'empty message'], 400);
            }
            $chatId = nb_chat_add($pdo, 'user', $message);
            $jobId = nb_job_enqueue($pdo, 'chat', ['message' => $message]);
            respond(['ok' => true, 'chat_id' => $chatId, 'job_id' => $jobId]);

        case 'start':
            require_post();
            $empty = (int)$pdo->query('SELECT COUNT(*) FROM chat')->fetchColumn() === 0
                && (int)$pdo->query('SELECT COUNT(*) FROM jobs')->fetchColumn() === 0;
            if ($empty) {
                nb_job_enqueue($pdo, 'interview');
            }
            respond(['ok' => true, 'started' => $empty]);

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
