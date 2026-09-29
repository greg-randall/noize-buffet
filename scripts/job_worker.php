<?php
declare(strict_types=1);
// Runs parent jobs one at a time. Usage: php scripts/job_worker.php [--once]
require dirname(__DIR__) . '/lib/db.php';
require dirname(__DIR__) . '/lib/config.php';
require dirname(__DIR__) . '/lib/parent.php';

$once = in_array('--once', $argv, true);
$parent = new NbParentProcess(); // one claude process, kept alive between jobs

/** Log each tool call as it streams in, so a long batch isn't silent. */
$logToolCalls = function (array $event): void {
    if (($event['type'] ?? '') !== 'assistant') {
        return;
    }
    foreach ($event['message']['content'] ?? [] as $block) {
        if (($block['type'] ?? '') === 'tool_use') {
            fwrite(STDERR, "    tool: {$block['name']}: " . nb_describe_tool_input($block['input'] ?? []) . "\n");
        }
    }
};

$pdo = nb_db();
$config = nb_config();
$interrupted = nb_jobs_recover_interrupted($pdo);
if ($interrupted > 0) {
    fwrite(STDERR, '[' . nb_now() . "] marked $interrupted interrupted job(s) as failed\n");
}
$requeued = nb_jobs_requeue_usage_failed($pdo);
if ($requeued > 0) {
    fwrite(STDERR, '[' . nb_now() . "] $requeued message(s) that hit the usage limit are back in the queue\n");
}
fwrite(STDERR, '[' . nb_now() . "] job worker started (model {$config['parent_model']})\n");

$waitingSince = null;
while (true) {
    // Out of Claude usage: run nothing (queued messages wait, no refills) until it resets, then carry on in order.
    if ($paused = nb_usage_paused($pdo)) {
        if ($waitingSince === null) {
            $waitingSince = time();
            fwrite(STDERR, '[' . nb_now() . "] out of Claude usage (\"{$paused['message']}\"); waiting until "
                . gmdate('Y-m-d H:i', $paused['until']) . " UTC\n");
        }
        if ($once) {
            $parent->stop();
            exit(0);
        }
        sleep(max(1, min(30, $paused['until'] - time())));
        continue;
    }
    if ($waitingSince !== null) {
        $waitingSince = null;
        fwrite(STDERR, '[' . nb_now() . "] usage is back; sending what's waiting\n");
    }
    $job = nb_job_next($pdo);
    if ($job === null) {
        // Idle: top the queue up before it runs out, so the user doesn't have to ask.
        $refill = nb_refill_check($pdo, (int)$config['refill_when_left']);
        if ($refill['refill']) {
            $id = nb_job_enqueue($pdo, 'refill', ['unplayed' => $refill['unplayed']]);
            fwrite(STDERR, '[' . nb_now() . "] queue running low ({$refill['why']}): queued refill job $id\n");
            continue;
        }
        if ($once) {
            $parent->stop();
            exit(0);
        }
        sleep(2);
        continue;
    }
    fwrite(STDERR, '[' . nb_now() . "] job {$job['id']} ({$job['kind']}) started\n");
    $t = nb_clock();
    nb_file_snapshot($pdo, null); // edits made by hand since the last job
    $s = nb_run_parent_job($pdo, $job, $config, $parent, $logToolCalls);
    if ($changed = nb_file_snapshot($pdo, (int)$job['id'])) {
        fwrite(STDERR, '    saved a version of ' . implode(', ', $changed) . "\n");
    }
    $status = nb_locked($pdo, fn() => $pdo->query('SELECT status FROM jobs WHERE id = ' . (int)$job['id'])->fetchColumn());
    fwrite(STDERR, sprintf("[%s] job %d %s in %.1fs, %s turns, $%s (API-equivalent), agent process %s (pid %s)\n", nb_now(),
        $job['id'], $status, nb_clock() - $t, $s['turns'] ?? '?',
        isset($s['cost_usd']) ? number_format((float)$s['cost_usd'], 4) : '?', $s['process'], $s['pid'] ?? '?'));
    if ($s['handoff'] ?? null) {
        fwrite(STDERR, $s['handoff']['written'] ? "    conversation started over; the old one wrote handoff.md for it\n"
            : "    conversation started over without a handoff note: {$s['handoff']['error']}\n");
    }
    foreach ($s['denials'] as $d) {
        fwrite(STDERR, "    BLOCKED: $d\n");
    }
    fwrite(STDERR, "    debug: {$s['debug_file']}\n");
}
