<?php
declare(strict_types=1);
// Mines queued videos' comments for every station, up to mining_workers at a time. Usage: php scripts/mine_worker.php [--once]
// --once: mine everything queued, then exit (for tests). Each video runs as scripts/mine_video.php, with NB_PROFILE set
// to the station it is for.
require dirname(__DIR__) . '/lib/db.php';
require dirname(__DIR__) . '/lib/config.php';

$once = in_array('--once', $argv, true);
$config = nb_config();
$slots = max(1, (int)$config['mining_workers']);
$pdos = []; // station => its database, opened the first time we see the station

/** Open a station's database. The first time: put back what a stop or a failure left, and queue songs rated earlier. */
$open = function (string $slug) use (&$pdos): PDO {
    nb_profile($slug);
    if (!isset($pdos[$slug])) {
        $pdos[$slug] = nb_db();
        $tag = "[$slug] ";
        foreach ([
            [nb_mining_recover_interrupted($pdos[$slug]), 'interrupted video(s) put back in the mining queue'],
            [nb_mining_retry_failed($pdos[$slug], 86400), 'video(s) that failed more than a day ago put back in the mining queue'],
            [nb_mining_backfill($pdos[$slug]), 'song(s) rated top/yes or named by you earlier queued'],
        ] as [$n, $what]) {
            if ($n > 0) {
                fwrite(STDERR, '[' . nb_now() . "] $tag$n $what\n");
            }
        }
    }
    return $pdos[$slug];
};
fwrite(STDERR, '[' . nb_now() . "] mining worker started ($slots video(s) at a time)\n");

/** A pipeline that died without recording an outcome (e.g. a PHP fatal error) is marked failed, not left in progress. */
$finished = function (PDO $pdo, string $vid, int $exit): void {
    $row = array_values(array_filter(nb_mining_list($pdo), fn($m) => $m['video_id'] === $vid))[0] ?? null;
    if ($row && in_array($row['status'], ['downloading', 'filtering', 'extracting'], true)) {
        $error = "mine_video.php stopped (exit $exit) during {$row['status']}; see comments/$vid/mine.log";
        nb_mining_update($pdo, $vid, ['status' => 'failed', 'error' => $error]);
        fwrite(STDERR, '[' . nb_now() . "] $vid: failed: $error\n");
    }
};

$running = []; // "station/video" => ['proc' => process, 'slug' => station, 'vid' => video_id]
$wasPaused = false;
while (true) {
    nb_profiles_ensure_default();
    $slugs = array_column(nb_profiles(), 'slug');
    foreach ($slugs as $slug) {
        $open($slug);
    }
    nb_usage_share(array_values($pdos));
    foreach ($running as $key => $r) {
        $status = proc_get_status($r['proc']);
        if (!$status['running']) {
            proc_close($r['proc']);
            unset($running[$key]);
            $finished($open($r['slug']), $r['vid'], $status['signaled'] ? 128 + $status['termsig'] : $status['exitcode']);
        }
    }
    // Out of Claude usage (the agent or a child said so): start nothing new until the reset time.
    $paused = $pdos ? nb_usage_paused(reset($pdos)) : null;
    if ($paused && !$wasPaused) {
        fwrite(STDERR, '[' . nb_now() . '] mining paused until ' . date('Y-m-d H:i T', $paused['until'])
            . ": out of Claude usage (\"{$paused['message']}\")\n");
    } elseif (!$paused && $wasPaused) {
        fwrite(STDERR, '[' . nb_now() . "] Claude usage should be back; mining again\n");
    }
    $wasPaused = (bool)$paused;
    // One video at a time per video id, even if two stations want it: the second reuses what the first extracted.
    for ($more = true; $more && !$paused && count($running) < $slots;) {
        $more = false;
        foreach ($slugs as $slug) {
            if (count($running) >= $slots) {
                break;
            }
            $pdo = $open($slug);
            $job = nb_mining_next($pdo, array_column($running, 'vid'));
            if ($job !== null) {
                // Inherits this process's stdout and stderr, so its progress lines show up with the worker's.
                $proc = proc_open([PHP_BINARY, __DIR__ . '/mine_video.php', $job['video_id']], [], $pipes, null,
                    array_merge(getenv(), ['NB_PROFILE' => $slug]));
                $running["$slug/{$job['video_id']}"] = ['proc' => $proc, 'slug' => $slug, 'vid' => $job['video_id']];
                $more = true;
            }
        }
    }
    if ($once && $running === []) {
        exit(0);
    }
    sleep(2);
}
