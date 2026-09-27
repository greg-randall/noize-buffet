<?php
declare(strict_types=1);
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/assert.php';

$pdo = fresh_db('test_mine_video'); // exports NB_DB for the child processes
$comments = tmp_dir() . '/mine_comments';
exec('rm -rf ' . escapeshellarg($comments));
$argsFile = tmp_dir() . '/fake_mine_args.jsonl';
@unlink($argsFile);
$wrap = function (string $name, string $script): string {
    $path = tmp_dir() . "/$name";
    file_put_contents($path, "#!/usr/bin/env bash\nexec " . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . "/$script") . " \"\$@\"\n");
    chmod($path, 0755);
    return $path;
};
$fixture = tmp_dir() . '/mine_fixture_comments.json';
file_put_contents($fixture, json_encode([
    ['id' => 'a1', 'author' => '@ann', 'author_id' => 'UCann', 'like_count' => 50, 'text' => 'if you like this check out Burial'],
    ['id' => 'a2', 'author' => '@bob', 'author_id' => 'UCbob', 'like_count' => 5, 'text' => 'Burial - Archangel is the blueprint'],
    ['id' => 'a3', 'author' => '@cat', 'author_id' => 'UCcat', 'like_count' => 1, 'text' => 'sounds like Tomggg SKIPME'],
    ['id' => 'a4', 'author' => '@dan', 'author_id' => 'UCdan', 'like_count' => 0, 'text' => 'love this so much'],
]));
$emptyEnv = tmp_dir() . '/mine_empty.env';
file_put_contents($emptyEnv, "TYPESAFE_API=\n");
foreach (['NB_COMMENTS_DIR' => $comments, 'NB_ENV_FILE' => $emptyEnv, 'NB_FAKE_ARGS' => $argsFile,
    'NB_FAKE_COMMENTS' => $fixture, 'NB_YTDLP_BIN' => $wrap('fake_ytdlp', 'fake_ytdlp.php'),
    'NB_CLAUDE_BIN' => $wrap('fake_child_for_mine', 'fake_mining_child.php'), 'NB_MINING_CHUNK_SIZE' => '2'] as $k => $v) {
    putenv("$k=$v");
}
$mine = function (string $vid): array {
    $p = proc_open([PHP_BINARY, __DIR__ . '/../scripts/mine_video.php', $vid], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    return [proc_close($p), $out];
};
$row = fn(string $vid) => array_values(array_filter(nb_mining_list($pdo), fn($m) => $m['video_id'] === $vid))[0] ?? null;
$calls = fn() => array_map(fn($l) => json_decode($l, true), file($argsFile, FILE_IGNORE_NEW_LINES) ?: []);

echo "a full run with fakes\n";
nb_add_batch($pdo, [['video_id' => 'V0000000001', 'title' => 'Test Song', 'artist' => 'Test Artist', 'bucket' => 'user']], 'b', null);
[$code, $out] = $mine('V0000000001');
$r = $row('V0000000001');
check($code === 0 && $r['status'] === 'done' && $r['error'] === null, 'done, no error' . ($code ? " (exit $code: $out)" : ''));
check($r['filter'] === 'keyword' && str_contains($out, 'no TypeSafe key'), 'keyword filter without a key, with a warning');
check((int)$r['comments'] === 4 && (int)$r['flagged'] === 3 && (int)$r['covered'] === 3 && (int)$r['mentions'] === 3,
    '4 comments, 3 flagged, all 3 covered after the re-run, 3 mentions');
$yt = array_values(array_filter($calls(), fn($c) => in_array('--write-comments', $c['args'], true)))[0]['args'] ?? [];
check(in_array('--write-info-json', $yt, true) && in_array('--skip-download', $yt, true)
    && in_array('youtube:comment_sort=top;max_comments=3000', $yt, true), 'yt-dlp asked for the top 3,000 comments');
$children = array_values(array_filter($calls(), fn($c) => in_array('--tools', $c['args'], true)));
check(count($children) === 3 && str_contains($children[2]['args'][1], 'chunk-extra.md'), 'two chunks plus one re-run of the missed comment');
check(realpath($children[0]['cwd']) === realpath("$comments/V0000000001"), 'children run in the video folder');
check(file_get_contents("$comments/V0000000001/CLAUDE.md") === file_get_contents(__DIR__ . '/../mining/child_CLAUDE.md'), 'child instructions written into the folder');
$leads = array_column(nb_leads($pdo), null, 'name_key');
check(($leads['burial']['strength'] ?? '') === 'confirmed' && $leads['burial']['people'] === 2, 'Burial confirmed by 2 people');
check(($leads['tomggg']['strength'] ?? '') === 'hint', 'a single-person name is a hint');
check(substr_count($out, 'V0000000001:') >= 5, 'one progress line per step');

echo "a download failure\n";
putenv('NB_FAKE_YTDLP_FAIL=1');
nb_mining_enqueue($pdo, 'V0000000002');
[$code, $out] = $mine('V0000000002');
putenv('NB_FAKE_YTDLP_FAIL');
$r = $row('V0000000002');
check($code === 1 && $r['status'] === 'failed' && str_contains((string)$r['error'], 'Sign in to confirm your age'), "failed, with yt-dlp's error");

echo "the worker\n";
nb_add_batch($pdo, [['video_id' => 'V0000000003', 'title' => 'T3', 'artist' => 'A3', 'bucket' => 'user'],
    ['video_id' => 'V0000000004', 'title' => 'T4', 'artist' => 'A4', 'bucket' => 'user']], 'b3', null);
$p = proc_open([PHP_BINARY, __DIR__ . '/../scripts/mine_worker.php', '--once'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
$out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
$code = proc_close($p);
check($code === 0 && $row('V0000000003')['status'] === 'done' && $row('V0000000004')['status'] === 'done', 'the worker mines every queued video, then exits with --once');
check(str_contains($out, 'mining worker started'), 'the worker says it started');

echo "the TypeSafe path (tests/fake_python.py stands in for python3 and fakes the TypeSafe filter)\n";
$keyEnv = tmp_dir() . '/mine_key.env';
file_put_contents($keyEnv, "TYPESAFE_API=fake-key\n");
$pyLog = tmp_dir() . '/fake_py_calls.jsonl';
$statusLog = tmp_dir() . '/fake_status.jsonl';
$ids = tmp_dir() . '/fake_ts_ids.txt';
file_put_contents($ids, "a1\na2\na3\n"); // TypeSafe flags the three comments that name someone, not a4's plain praise
$probing = function (string $name, string $label, string $script): string { // records the status, then runs the fake
    $path = tmp_dir() . "/$name";
    file_put_contents($path, "#!/usr/bin/env bash\npython3 " . escapeshellarg(__DIR__ . '/fake_python.py') . " --probe $label\n"
        . 'exec ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . "/$script") . " \"\$@\"\n");
    chmod($path, 0755);
    return $path;
};
$pyBin = tmp_dir() . '/fake_python';
file_put_contents($pyBin, "#!/usr/bin/env bash\nexec python3 " . escapeshellarg(__DIR__ . '/fake_python.py') . " \"\$@\"\n");
chmod($pyBin, 0755);
foreach (['NB_ENV_FILE' => $keyEnv, 'NB_PYTHON_BIN' => $pyBin, 'NB_FAKE_PY_LOG' => $pyLog, 'NB_FAKE_STATUS_LOG' => $statusLog,
    'NB_FAKE_TS_FLAGGED_IDS' => $ids, 'NB_YTDLP_BIN' => $probing('probe_ytdlp', 'yt-dlp', 'fake_ytdlp.php'),
    'NB_CLAUDE_BIN' => $probing('probe_child', 'claude', 'fake_mining_child.php')] as $k => $v) {
    putenv("$k=$v");
}
$fakeTs = ['NB_FAKE_TS_FAILED', 'NB_FAKE_TS_EXIT', 'NB_FAKE_TS_NOFILE', 'NB_FAKE_TS_OMIT_FAILED', 'NB_FAKE_TS_MESSAGE'];
/** Mine $vid with the fake TypeSafe switches in $switches; returns [exit code, output, mining row]. */
$mineTs = function (string $vid, array $switches = []) use ($pdo, $mine, $row, $fakeTs, $pyLog, $statusLog): array {
    @unlink($pyLog);
    @unlink($statusLog);
    foreach ($fakeTs as $k) {
        putenv(isset($switches[$k]) ? "$k={$switches[$k]}" : $k);
    }
    nb_mining_enqueue($pdo, $vid);
    [$code, $out] = $mine($vid);
    foreach ($fakeTs as $k) {
        putenv($k);
    }
    return [$code, $out, $row($vid)];
};
$lines = fn(string $file) => array_map(fn($l) => json_decode($l, true), is_file($file) ? file($file, FILE_IGNORE_NEW_LINES) : []);

[$code, $out, $r] = $mineTs('V0000000005');
check($code === 0 && $r['status'] === 'done' && $r['error'] === null, 'TypeSafe: done, no error' . ($code ? " (exit $code: $out)" : ''));
check($r['filter'] === 'typesafe' && !str_contains($out, 'no TypeSafe key'), 'TypeSafe: the typesafe filter, and no keyword warning');
check((int)$r['comments'] === 4 && (int)$r['flagged'] === 3 && (int)$r['covered'] === 3 && (int)$r['mentions'] === 3,
    'TypeSafe: 4 comments, 3 flagged, 3 covered, 3 mentions');
$ts = array_values(array_filter($lines($pyLog), fn($c) => $c['script'] === 'find_music_mentions.py'))[0]['args'] ?? [];
$arg = fn(string $flag) => $ts[array_search($flag, $ts, true) + 1] ?? null;
check(in_array('--input', $ts, true) && $arg('--env') === $keyEnv && $arg('--song-threshold') === '0.8'
    && $arg('--artist-threshold') === '0.8', 'TypeSafe: called with the info file, the .env path and both thresholds');
check(array_column($lines($pyLog), 'script') === ['find_music_mentions.py', 'prepare.py', 'merge_leads.py'],
    'TypeSafe: the pipeline runs the filter, prepare and merge through NB_PYTHON_BIN, in that order');
$seen = [];
foreach ($lines($statusLog) as $p) {
    $seen[$p['tool']][] = $p['status']['V0000000005'] ?? '?';
}
check(array_unique($seen['yt-dlp'] ?? []) === ['downloading'], 'status is downloading while yt-dlp runs');
check(array_unique($seen['find_music_mentions.py'] ?? []) === ['filtering'], 'status is filtering while TypeSafe runs');
check(array_unique($seen['prepare.py'] ?? []) === ['filtering'], 'and still filtering while prepare.py numbers the comments');
check(array_values(array_unique($seen['claude'] ?? [])) === ['extracting'] && count($seen['claude']) === 3,
    'status is extracting for all three children');
check(array_unique($seen['merge_leads.py'] ?? []) === ['extracting'], 'and while the leads are merged; done only after');

[$code, $out, $r] = $mineTs('V0000000006', ['NB_FAKE_TS_FAILED' => '5', 'NB_FAKE_TS_EXIT' => '1']);
check($code === 0 && $r['status'] === 'done' && (int)$r['flagged'] === 3, 'some TypeSafe calls failed: the video still finishes with what did work');
check(str_contains((string)$r['error'], '5 comments failed at TypeSafe'), 'and the failed calls are recorded as a problem');

[$code, $out, $r] = $mineTs('V0000000007', ['NB_FAKE_TS_EXIT' => '1']);
check($code === 1 && $r['status'] === 'failed', 'TypeSafe failed with no failed calls counted (a bad key): the video fails');
check(str_contains((string)$r['error'], 'the API key was rejected (HTTP 401)'),
    "and the error is TypeSafe's own last line, not the runner's exit line" . " (got: {$r['error']})");

[$code, $out, $r] = $mineTs('V0000000008', ['NB_FAKE_TS_NOFILE' => '1']);
check($code === 1 && $r['status'] === 'failed' && str_starts_with((string)$r['error'], 'typesafe filter failed'),
    'TypeSafe wrote no flagged file: the video fails, naming the filter');

[$code, $out, $r] = $mineTs('V0000000009', ['NB_FAKE_TS_OMIT_FAILED' => '1']);
check($code === 0 && $r['status'] === 'done' && $r['error'] === null, 'a flagged file without a "failed" count is read as none failed');

foreach (['NB_PYTHON_BIN', 'NB_FAKE_PY_LOG', 'NB_FAKE_STATUS_LOG', 'NB_FAKE_TS_FLAGGED_IDS'] as $k) {
    putenv($k);
}

finish();
