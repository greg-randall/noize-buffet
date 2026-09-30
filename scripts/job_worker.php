<?php
declare(strict_types=1);
// Runs parent jobs one at a time. Usage: php scripts/job_worker.php [--once]
require dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/config.php';
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

$config = nb_config();
$pdos = []; // station => its database, opened the first time we see the station

/** Open a station's database (and select the station). The first time: recover what a stop left behind. */
$open = function (string $slug) use (&$pdos): PDO {
    nb_profile($slug);
    if (!isset($pdos[$slug])) {
        $pdos[$slug] = nb_db();
        $tag = "[$slug] ";
        if (($n = nb_jobs_recover_interrupted($pdos[$slug])) > 0) {
            fwrite(STDERR, '[' . nb_now() . "] {$tag}marked $n interrupted job(s) as failed\n");
        }
        if (($n = nb_jobs_requeue_usage_failed($pdos[$slug])) > 0) {
            fwrite(STDERR, '[' . nb_now() . "] {$tag}$n message(s) that hit the usage limit are back in the queue\n");
        }
    }
    return $pdos[$slug];
};

fwrite(STDERR, '[' . nb_now() . "] job worker started (model {$config['parent_model']})\n");

$waitingSince = null;
while (true) {
    nb_profiles_ensure_default(); // a fresh install: make "main"
    $slugs = array_column(nb_profiles(), 'slug');
    foreach ($slugs as $slug) {
        $open($slug);
    }
    // The usage limit belongs to the account: a pause found in one station applies to all.
    nb_usage_share(array_values($pdos));
    $paused = $pdos ? nb_usage_paused(reset($pdos)) : null;
    // Out of Claude usage: run nothing (queued messages wait, no refills) until it resets, then carry on in order.
    if ($paused) {
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

    $busy = false;
    foreach ($slugs as $slug) { // one job per station per pass, so no station waits behind another's queue
        $pdo = $open($slug);
        $tag = count($slugs) > 1 ? "[$slug] " : '';
        $job = nb_job_next($pdo);
        if ($job === null) {
            // Idle: top the queue up before it runs out, so the user doesn't have to ask.
            $refill = nb_refill_check($pdo, (int)$config['refill_when_left']);
            if ($refill['refill']) {
                $id = nb_job_enqueue($pdo, 'refill', ['unplayed' => $refill['unplayed']]);
                fwrite(STDERR, '[' . nb_now() . "] {$tag}queue running low ({$refill['why']}): queued refill job $id\n");
                $busy = true;
            }
            continue;
        }
        $busy = true;
        fwrite(STDERR, '[' . nb_now() . "] {$tag}job {$job['id']} ({$job['kind']}) started\n");
        $t = nb_clock();
        nb_file_snapshot($pdo, null); // edits made by hand since the last job
        $s = nb_run_parent_job($pdo, $job, $config, $parent, $logToolCalls);
        if ($changed = nb_file_snapshot($pdo, (int)$job['id'])) {
            fwrite(STDERR, '    saved a version of ' . implode(', ', $changed) . "\n");
        }
        $status = nb_locked($pdo, fn() => $pdo->query('SELECT status FROM jobs WHERE id = ' . (int)$job['id'])->fetchColumn());
        fwrite(STDERR, sprintf("[%s] %sjob %d %s in %.1fs, %s turns, $%s (API-equivalent), agent process %s (pid %s)\n", nb_now(),
            $tag, $job['id'], $status, nb_clock() - $t, $s['turns'] ?? '?',
            isset($s['cost_usd']) ? number_format((float)$s['cost_usd'], 4) : '?', $s['process'], $s['pid'] ?? '?'));
        if ($s['handoff'] ?? null) {
            fwrite(STDERR, $s['handoff']['written'] ? "    conversation started over; the old one wrote handoff.md for it\n"
                : "    conversation started over without a handoff note: {$s['handoff']['error']}\n");
        }
        foreach ($s['denials'] as $d) {
            fwrite(STDERR, "    BLOCKED: $d\n");
        }
        fwrite(STDERR, "    debug: {$s['debug_file']}\n");
        nb_usage_share(array_values($pdos));
        if (nb_usage_paused($pdo)) {
            break; // this job ran into the limit: the other stations' jobs would too
        }
    }
    if (!$busy) {
        if ($once) {
            $parent->stop();
            exit(0);
        }
        sleep(2);
    }
}
