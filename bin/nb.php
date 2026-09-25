<?php
declare(strict_types=1);
// Database CLI for the parent agent. All output is JSON.
require dirname(__DIR__) . '/lib/db.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

const NB_USAGE = [
    'queue' => 'every song with its listen data',
    'feedback [ISO-time|all]' => 'listens changed since the last batch (default), a time, or all',
    'add-batch <file.json>' => 'add songs: {"summary": "...", "songs": [{"video_id","artist","title","channel","duration_s","bucket","reason","source"}]}',
    'mute <artist|lane> <value>' => 'stop suggesting an artist or a lane',
    'mutes' => 'list mutes',
    'status' => 'counts and job status',
    'say <text>' => 'post a short message to the user right now, while you keep working (e.g. before a long batch)',
];

/** Print the JSON result, append the call to data/nb.log (one JSON object per line), and exit. */
function out(mixed $data, int $code = 0): never
{
    global $argv;
    $args = array_slice($argv, 1);
    $entry = ['at' => nb_now(), 'job' => getenv('NB_JOB_ID') ?: null, 'args' => $args, 'exit' => $code, 'output' => $data];
    if (($args[0] ?? '') === 'add-batch' && is_file($args[1] ?? '')) {
        $entry['input'] = (string)file_get_contents($args[1]); // exactly what the agent tried to add
    }
    @file_put_contents(dirname(nb_db_path()) . '/nb.log',
        json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    exit($code);
}

$pdo = nb_db();
$cmd = $argv[1] ?? 'help';

try {
    switch ($cmd) {
        case 'queue':
            out(nb_queue($pdo));

        case 'feedback':
            $since = $argv[2] ?? nb_last_batch_at($pdo);
            if ($since === 'all') {
                $since = null;
            }
            out(['since' => $since, 'listens' => nb_feedback_since($pdo, $since)]);

        case 'add-batch':
            $file = $argv[2] ?? '';
            $in = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
            if (!is_array($in) || !isset($in['songs']) || !is_array($in['songs'])) {
                out(['ok' => false, 'error' => 'argument must be a JSON file: {"summary": "...", "songs": [...]}'], 2);
            }
            $jobId = getenv('NB_JOB_ID') ? (int)getenv('NB_JOB_ID') : null;
            out(['ok' => true] + nb_add_batch($pdo, $in['songs'], (string)($in['summary'] ?? ''), $jobId));

        case 'mute':
            out(['ok' => true, 'id' => nb_mute_add($pdo, $argv[2] ?? '', implode(' ', array_slice($argv, 3)))]);

        case 'say':
            $text = trim(implode(' ', array_slice($argv, 2)));
            if ($text === '') {
                out(['ok' => false, 'error' => 'say needs some text'], 2);
            }
            $jobId = getenv('NB_JOB_ID') ? (int)getenv('NB_JOB_ID') : null;
            out(['ok' => true, 'id' => nb_chat_add($pdo, 'parent', $text, $jobId)]);

        case 'mutes':
            out(nb_mutes($pdo));

        case 'status':
            out([
                'songs' => (int)$pdo->query('SELECT COUNT(*) FROM songs')->fetchColumn(),
                'unplayed' => (int)$pdo->query('SELECT COUNT(*) FROM songs s LEFT JOIN listens l ON l.video_id = s.video_id
                    WHERE l.video_id IS NULL AND s.unplayable_error IS NULL')->fetchColumn(),
                'rated' => (int)$pdo->query('SELECT COUNT(*) FROM listens WHERE rating IS NOT NULL')->fetchColumn(),
                'last_batch_at' => nb_last_batch_at($pdo),
                'jobs' => nb_job_status($pdo),
            ]);

        default:
            out(['usage' => NB_USAGE], $cmd === 'help' ? 0 : 2);
    }
} catch (InvalidArgumentException $e) {
    out(['ok' => false, 'error' => $e->getMessage()], 2);
}
