<?php
declare(strict_types=1);
// Test helper: hammer one song with listen saves and notes from its own process.
// Usage: php concurrent_writer.php <db path> <video_id> <count>. Prints {"ok": n, "errors": ["..."]}.
require __DIR__ . '/../lib/db.php';

[, $path, $vid, $count] = $argv;
$pdo = nb_db($path);
$ok = 0;
$errors = [];
for ($i = 0; $i < (int)$count; $i++) {
    $t = microtime(true);
    try {
        if ($i % 2) {
            nb_append_note($pdo, $vid, 'note ' . getmypid() . " $i");
        } else {
            nb_save_listen($pdo, ['video_id' => $vid, 'furthest_pct' => $i, 'rating' => 'yes']);
        }
        $ok++;
    } catch (Throwable $e) {
        $line = $e->getTrace()[0]['line'] ?? $e->getLine();
        $errors[] = sprintf('%s (after %.2fs, db.php line %s)', $e->getMessage(), microtime(true) - $t, $line);
    }
}
echo json_encode(['ok' => $ok, 'errors' => $errors]);
