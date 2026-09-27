<?php
declare(strict_types=1);
// Mine one video's YouTube comments for leads. Usage: php scripts/mine_video.php <video_id>
// Steps (the mining table's status): downloading -> filtering -> extracting -> done, or failed.
// Out of Claude usage: the video goes back to queued and the script exits 75; nothing runs until the reset.
// Subprocess output goes to comments/<video_id>/mine.log; this prints one progress line per step.
require dirname(__DIR__) . '/lib/db.php';
require dirname(__DIR__) . '/lib/config.php';
require dirname(__DIR__) . '/lib/mining.php';

$vid = $argv[1] ?? '';
if (!preg_match(NB_VIDEO_ID_RE, $vid)) {
    fwrite(STDERR, "usage: php scripts/mine_video.php <video_id>\n");
    exit(2);
}
$pdo = nb_db();
$config = nb_config();
$chunkSize = (int)(getenv('NB_MINING_CHUNK_SIZE') ?: $config['mining_chunk_size']);
$dir = nb_comments_dir() . "/$vid";
if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
}
$log = "$dir/mine.log";
$logSize = function () use ($log): int { // where the next command's output will start in the log
    clearstatcache(true, $log);
    return (int)filesize($log);
};
$started = nb_clock();
$say = function (string $msg) use ($vid, $started): void {
    printf("[%s] %s: %s (%.0fs)\n", nb_now(), $vid, $msg, nb_clock() - $started);
};
// NB_PYTHON_BIN lets tests stand in for python3 (tests/fake_python.py fakes the TypeSafe filter).
$python = getenv('NB_PYTHON_BIN') ?: (nb_find_bin('python3') ?? 'python3');
$py = fn(string $script, array $args): array => array_merge([$python, nb_root() . "/mining/$script"], $args);
// Time limits, so a hung subprocess can't hold a worker forever (children have mining_child_timeout_s).
const NB_YTDLP_TIMEOUT_S = 1800.0;
const NB_FILTER_TIMEOUT_S = 3600.0;
const NB_SCRIPT_TIMEOUT_S = 300.0;
/** Out of Claude usage: back in the queue, to be mined again once usage resets (the worker waits until then). */
$waitForUsage = function (array $paused) use ($pdo, $vid, $say): never {
    $when = date('Y-m-d H:i T', $paused['until']);
    nb_mining_update($pdo, $vid, ['status' => 'queued',
        'error' => "waiting: out of Claude usage until $when (\"{$paused['message']}\"); it will be mined again then"]);
    $say("out of Claude usage until $when; back in the queue");
    exit(75);
};
$fail = function (string $error) use ($pdo, $vid, $say): never {
    nb_mining_update($pdo, $vid, ['status' => 'failed', 'error' => $error]);
    $say("failed: $error");
    exit(1);
};
/**
 * The part of the log written since $offset: the last line mentioning ERROR, else its last non-empty line.
 * nb_run_logged's own "[time] $ command" and "[time] exit N after Ns" lines are skipped: they say nothing about why.
 */
$errorLine = function (int $offset) use ($log): string {
    $lines = array_values(array_filter(array_map('trim', explode("\n", (string)file_get_contents($log, false, null, $offset))),
        fn($l) => $l !== '' && !preg_match('/^\[[^\]]+\] (\$ |exit \S+ after )/', $l)));
    $errors = array_values(array_filter($lines, fn($l) => str_contains($l, 'ERROR')));
    return $errors ? end($errors) : ($lines ? end($lines) : 'no output');
};

try {
    nb_mining_enqueue($pdo, $vid); // no-op if queued; lets the script also be run by hand
    touch($log);
    if ($paused = nb_usage_paused($pdo)) {
        $waitForUsage($paused);
    }

    // 1. Download the top comments.
    nb_mining_update($pdo, $vid, ['status' => 'downloading', 'error' => null]);
    $say("downloading up to {$config['mining_comment_cap']} top comments with yt-dlp, this can take a few minutes…");
    $info = "$dir/$vid.info.json";
    @unlink($info);
    $yt = [getenv('NB_YTDLP_BIN') ?: (nb_find_bin('yt-dlp') ?? 'yt-dlp'), '--skip-download', '--write-comments', '--write-info-json',
        '--no-write-playlist-metafiles', '--extractor-args', "youtube:comment_sort=top;max_comments={$config['mining_comment_cap']}",
        '-o', "$dir/%(id)s.%(ext)s", "https://www.youtube.com/watch?v=$vid"];
    for ($attempt = 1; ; $attempt++) {
        $offset = $logSize();
        nb_run_logged($yt, $dir, $log, NB_YTDLP_TIMEOUT_S);
        clearstatcache(true, $info);
        if (is_file($info) || $attempt === 2 || !str_contains((string)file_get_contents($log, false, null, $offset), '429')) {
            break;
        }
        $wait = (int)(getenv('NB_YTDLP_RETRY_WAIT') ?: 120);
        $say("YouTube is rate-limiting (429); waiting {$wait}s, then trying once more…");
        sleep($wait);
    }
    if (!is_file($info)) {
        $fail('yt-dlp got no comments: ' . $errorLine($offset));
    }
    $infoData = json_decode((string)file_get_contents($info), true) ?: [];
    $comments = count($infoData['comments'] ?? []);
    // The video's own artist by every name we know: the merge never makes it a lead under this video, and the
    // chunks tell the child. From the song list, the title before " - ", and yt-dlp's channel and artist fields.
    $songArtist = nb_locked($pdo, function () use ($pdo, $vid) {
        $st = $pdo->prepare('SELECT artist FROM songs WHERE video_id = ?');
        $st->execute([$vid]);
        return (string)$st->fetchColumn();
    });
    $title = (string)($infoData['title'] ?? '');
    $own = [];
    foreach ([$songArtist, str_contains($title, ' - ') ? explode(' - ', $title, 2)[0] : '', $infoData['channel'] ?? '',
        $infoData['uploader'] ?? '', $infoData['artist'] ?? '', $infoData['creator'] ?? '', ...(array)($infoData['creators'] ?? []),
        ...(array)($infoData['artists'] ?? [])] as $name) {
        $name = trim((string)preg_replace(['/\s*-\s*Topic$/i', '/VEVO$/'], '', trim((string)$name)));
        $key = $name === '' ? '' : nb_name_key($name);
        if ($key !== '' && !isset($own[$key])) {
            $own[$key] = $name;
        }
    }
    file_put_contents("$dir/own_artists.json", json_encode(array_values($own), JSON_UNESCAPED_UNICODE));
    nb_mining_update($pdo, $vid, ['comments' => $comments]);
    if ($comments === 0) {
        $fail('no comments downloaded (comments may be turned off for this video)');
    }
    $say("$comments comments downloaded");

    // 2. Flag the comments that seem to name music.
    nb_mining_update($pdo, $vid, ['status' => 'filtering']);
    @unlink("$dir/music_mentions_flagged.json");
    if (nb_has_typesafe_key()) {
        $filter = 'typesafe';
        $say(sprintf('filtering with TypeSafe, at least %.1f min at its rate limit…', $comments / 1150));
        $cmd = $py('find_music_mentions.py', ['--input', $info, '--env', nb_env_file(),
            '--song-threshold', (string)$config['typesafe_song_threshold'],
            '--artist-threshold', (string)$config['typesafe_artist_threshold'],
            '--other-artist-threshold', (string)$config['typesafe_other_artist_threshold'],
            '--injection-threshold', (string)$config['typesafe_injection_threshold'],
            '--spam-threshold', (string)$config['typesafe_spam_threshold'],
            '--min-chars', (string)$config['typesafe_min_comment_chars']]);
    } else {
        $filter = 'keyword';
        $say('WARNING: no TypeSafe key in .env, so using the keyword filter: it finds only about half of the comments that name other artists, and about half of what it flags names nothing');
        $cmd = $py('keyword_filter.py', ['--input', $info]);
    }
    $offset = $logSize();
    $r = nb_run_logged($cmd, nb_root(), $log, NB_FILTER_TIMEOUT_S);
    $flaggedFile = "$dir/music_mentions_flagged.json";
    if (!is_file($flaggedFile)) {
        $fail("$filter filter failed: " . $errorLine($offset));
    }
    $problems = [];
    // TypeSafe exits non-zero when some calls failed but still writes what it finished; carry on with that
    // and record it, so one comment TypeSafe always rejects can't stop the video from ever finishing.
    $filtered = json_decode((string)file_get_contents($flaggedFile), true);
    $failedCalls = (int)($filtered['failed'] ?? 0);
    if ($r['exit'] !== 0 && $failedCalls === 0) {
        $fail("$filter filter failed: " . $errorLine($offset));
    }
    if ($failedCalls > 0) {
        $problems[] = "$failedCalls comments failed at TypeSafe and were not checked (mining this video again retries them)";
    }
    // What the filter kept away from the extraction child, counted so nothing disappears silently.
    $notes = [];
    if (($n = (int)($filtered['quarantined'] ?? 0)) > 0) {
        $notes[] = "$n comments looked like instructions aimed at Claude and were not sent to it (their text is in "
            . "comments/$vid/quarantined.jsonl)";
    }
    if (($n = (int)($filtered['own_artist_skipped'] ?? 0)) > 0) {
        $notes[] = "$n only name the video's own artist, skipped";
    }
    if (($n = (int)($filtered['too_short_skipped'] ?? 0)) > 0) {
        $notes[] = "$n too short to name anything, skipped";
    }
    if (($n = (int)($filtered['spam_skipped'] ?? 0)) > 0) {
        $notes[] = "$n spam or self-promotion, skipped";
    }
    nb_mining_update($pdo, $vid, ['filter' => $filter, 'notes' => $notes ? implode('; ', $notes) : null]);
    foreach ($notes as $note) {
        $say($note);
    }

    // 3. Number the flagged comments and split them into chunks.
    $r = nb_run_logged($py('prepare.py', [$dir, '--chunk-size', (string)$chunkSize]), nb_root(), $log, NB_SCRIPT_TIMEOUT_S);
    $prep = json_decode(trim($r['out']), true);
    if ($r['exit'] !== 0 || !is_array($prep)) {
        $fail("prepare.py failed; see $log");
    }
    nb_mining_update($pdo, $vid, ['flagged' => $prep['flagged']]);
    if ($prep['flagged'] === 0) {
        nb_mining_update($pdo, $vid, ['status' => 'done', 'covered' => 0, 'mentions' => 0]);
        $say("done: none of the $comments comments seem to name music");
        exit(0);
    }
    $say("{$prep['flagged']} comments flagged, in " . count($prep['chunks']) . ' chunk(s)');

    // 4. One confined child per chunk lists the names.
    nb_mining_update($pdo, $vid, ['status' => 'extracting']);
    copy(nb_root() . '/mining/child_CLAUDE.md', "$dir/CLAUDE.md");
    $runChild = function (string $chunk) use ($pdo, $dir, $config, &$problems, $waitForUsage, $fail): void {
        $c = nb_run_child($chunk, $dir, $config);
        if ($c['tampered']) {
            // It did something a confined child must never do (its files are restored): nothing it wrote is
            // trusted, so every output in this folder is thrown away and the video fails.
            foreach (glob("$dir/artists.chunk-*.md") ?: [] as $f) {
                @unlink($f);
            }
            $fail("$chunk: {$c['error']}; all extraction output for this video was thrown away");
        }
        if (!$c['ok'] && ($limit = nb_usage_limit($c['error'])) !== null) {
            nb_usage_pause($pdo, $limit);
            $waitForUsage(nb_usage_paused($pdo) ?? ['until' => time() + 1800, 'message' => $limit]);
        }
        if (!$c['ok']) {
            $problems[] = "$chunk: {$c['error']}";
        }
        foreach ($c['denials'] as $d) {
            $problems[] = "$chunk: blocked $d";
        }
    };
    foreach ($prep['chunks'] as $i => $chunk) {
        $say(sprintf('extracting names, chunk %d of %d…', $i + 1, count($prep['chunks'])));
        $runChild($chunk);
    }

    // 5. Every comment should have a line; re-run the missed ones once.
    $cov = nb_coverage($dir, true, $log);
    if ($cov['missed'] && $cov['extra_chunk']) {
        $say(count($cov['missed']) . ' comment(s) got no line; re-running them once…');
        $runChild($cov['extra_chunk']);
        $cov = nb_coverage($dir, false, $log);
    }
    if ($cov['missed']) {
        $problems[] = count($cov['missed']) . " of {$cov['flagged']} flagged comments still not covered after one re-run (see coverage.json)";
    }
    if ($cov['unknown']) {
        $problems[] = 'the child listed comment ids that do not exist: ' . implode(', ', $cov['unknown']);
    }

    // 6. Merge every mined video into the leads table. One merge at a time: workers run in parallel, and an older
    // snapshot must not overwrite a newer one, nor a merge read another video's half-written files.
    // Only videos that finished mining (and this one) are merged: a failed or half-mined folder's files never
    // become leads.
    $lock = fopen(nb_comments_dir() . '/merge.lock', 'c');
    flock($lock, LOCK_EX);
    $finished = array_column(array_filter(nb_mining_list($pdo), fn($m) => $m['status'] === 'done'), 'video_id');
    $onlyFile = nb_comments_dir() . '/merge-only.txt';
    file_put_contents($onlyFile, implode("\n", array_unique([...$finished, $vid])) . "\n");
    $r = nb_run_logged($py('merge_leads.py', [nb_comments_dir(), '--only', $onlyFile]), nb_root(), $log, NB_SCRIPT_TIMEOUT_S);
    $merged = json_decode($r['out'], true);
    if ($r['exit'] !== 0 || !is_array($merged)) {
        $fail("merge_leads.py failed; see $log");
    }
    nb_leads_replace($pdo, $merged['leads']);
    // Songs named without an artist aren't artist leads; keep them, visible, for the record.
    file_put_contents(nb_comments_dir() . '/songs_only.jsonl', implode('', array_map(
        fn($row) => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n", $merged['songs_only'] ?? [])));
    flock($lock, LOCK_UN);
    fclose($lock);
    foreach ($merged['problems'] as $p) {
        if (str_starts_with($p, "$vid/")) {
            $problems[] = $p;
        }
    }
    $mentions = 0;
    foreach ($merged['leads'] as $lead) {
        $mentions += count(array_filter($lead['examples'], fn($e) => $e['video_id'] === $vid));
    }
    nb_mining_update($pdo, $vid, ['status' => 'done', 'covered' => $cov['covered'], 'mentions' => $mentions,
        'error' => $problems ? implode('; ', $problems) : null]);
    $say(sprintf('done: %d of %d flagged comments covered, %d mentions of other artists; %d leads in total, %d songs named without an artist (songs_only.jsonl)%s',
        $cov['covered'], $cov['flagged'], $mentions, count($merged['leads']), count($merged['songs_only'] ?? []),
        $problems ? '; problems: ' . implode('; ', $problems) : ''));
} catch (Throwable $e) {
    $fail(get_class($e) . ': ' . $e->getMessage());
}
