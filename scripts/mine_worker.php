<?php
declare(strict_types=1);
// Mines queued videos' comments, up to mining_workers at a time. Usage: php scripts/mine_worker.php [--once]
// --once: mine everything queued, then exit (for tests). Each video runs as scripts/mine_video.php.
require dirname(__DIR__) . '/lib/db.php';
require dirname(__DIR__) . '/lib/config.php';

$once = in_array('--once', $argv, true);
$pdo = nb_db();
$config = nb_config();
$slots = max(1, (int)$config['mining_workers']);
$n = nb_mining_recover_interrupted($pdo);
if ($n > 0) {
    fwrite(STDERR, '[' . nb_now() . "] $n interrupted video(s) put back in the mining queue\n");
}
$n = nb_mining_backfill($pdo);
if ($n > 0) {
    fwrite(STDERR, '[' . nb_now() . "] queued $n song(s) rated top/yes or named by you earlier\n");
}
fwrite(STDERR, '[' . nb_now() . "] mining worker started ($slots video(s) at a time)\n");

/** A pipeline that died without recording an outcome (e.g. a PHP fatal error) is marked failed, not left in progress. */
$finished = function (string $vid, int $exit) use ($pdo): void {
    $row = array_values(array_filter(nb_mining_list($pdo), fn($m) => $m['video_id'] === $vid))[0] ?? null;
    if ($row && in_array($row['status'], ['downloading', 'filtering', 'extracting'], true)) {
        $error = "mine_video.php stopped (exit $exit) during {$row['status']}; see comments/$vid/mine.log";
        nb_mining_update($pdo, $vid, ['status' => 'failed', 'error' => $error]);
        fwrite(STDERR, '[' . nb_now() . "] $vid: failed: $error\n");
    }
};

$running = []; // video_id => process
while (true) {
    foreach ($running as $vid => $proc) {
        $status = proc_get_status($proc);
        if (!$status['running']) {
            proc_close($proc);
            unset($running[$vid]);
            $finished((string)$vid, $status['signaled'] ? 128 + $status['termsig'] : $status['exitcode']);
        }
    }
    while (count($running) < $slots && ($job = nb_mining_next($pdo)) !== null) {
        // Inherits this process's stdout and stderr, so its progress lines show up with the worker's.
        $running[$job['video_id']] = proc_open([PHP_BINARY, __DIR__ . '/mine_video.php', $job['video_id']], [], $pipes);
    }
    if ($once && $running === []) {
        exit(0);
    }
    sleep(2);
}
