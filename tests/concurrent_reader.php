<?php
declare(strict_types=1);
// Test helper: read like the chat long-poll does (job status + new messages), until <seconds> pass.
// Usage: php concurrent_reader.php <db path> <seconds>. Prints {"ok": n, "errors": ["..."]}.
require __DIR__ . '/../lib/db.php';

[, $path, $seconds] = $argv;
$pdo = nb_db($path);
$ok = 0;
$errors = [];
$until = microtime(true) + (float)$seconds;
while (microtime(true) < $until) {
    try {
        nb_job_status($pdo);
        nb_chat_since($pdo, 0);
        nb_listen($pdo, 'WWWWWWWWWWW');
        $ok++;
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
    usleep(50000);
}
echo json_encode(['ok' => $ok, 'errors' => $errors]);
