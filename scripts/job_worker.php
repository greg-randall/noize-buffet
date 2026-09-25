<?php
declare(strict_types=1);
// Runs parent jobs one at a time. Usage: php scripts/job_worker.php [--once]
require dirname(__DIR__) . '/lib/db.php';
require dirname(__DIR__) . '/lib/config.php';
require dirname(__DIR__) . '/lib/parent.php';

$once = in_array('--once', $argv, true);
$pdo = nb_db();
$config = nb_config();
fwrite(STDERR, '[' . nb_now() . "] job worker started (model {$config['parent_model']})\n");

while (true) {
    $job = nb_job_next($pdo);
    if ($job === null) {
        if ($once) {
            exit(0);
        }
        sleep(2);
        continue;
    }
    fwrite(STDERR, '[' . nb_now() . "] job {$job['id']} ({$job['kind']}) started\n");
    $t = microtime(true);
    nb_run_parent_job($pdo, $job, $config);
    $status = $pdo->query('SELECT status FROM jobs WHERE id = ' . (int)$job['id'])->fetchColumn();
    fwrite(STDERR, sprintf("[%s] job %d %s in %.1fs\n", nb_now(), $job['id'], $status, microtime(true) - $t));
}
