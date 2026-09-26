<?php
declare(strict_types=1);
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/config.php';
require __DIR__ . '/../lib/mining.php';
require __DIR__ . '/assert.php';

// Everything here uses tests/fake_mining_child.php in place of `claude`: no network, no real model.
$base = tmp_dir() . '/mining_child';

/** A fresh, empty folder under tests/tmp/mining_child/. */
$video = function (string $name) use ($base): string {
    $dir = "$base/$name";
    exec('rm -rf ' . escapeshellarg($dir));
    mkdir($dir, 0777, true);
    return $dir;
};
/** A video folder with the given flagged comments, run through mining/prepare.py (comment_index.json, chunk-NN.md). */
$prepared = function (string $name, array $comments, int $chunkSize = 150) use ($video): string {
    $dir = $video($name);
    file_put_contents("$dir/music_mentions_flagged.json",
        json_encode(['flagged' => $comments], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    exec('python3 ' . escapeshellarg(nb_root() . '/mining/prepare.py') . ' ' . escapeshellarg($dir) . " --chunk-size $chunkSize 2>&1",
        $out, $rc);
    if ($rc !== 0) {
        throw new RuntimeException("prepare.py failed: " . implode("\n", $out));
    }
    return $dir;
};
$comment = fn(string $author, string $text): array => ['video_id' => 'VIDEO000001', 'video_title' => 'Two Shell - home',
    'comment_id' => 'id' . md5($text), 'author' => $author, 'author_id' => 'UC' . ltrim($author, '@'), 'like_count' => 0, 'text' => $text];
$opt = function (array $a, string $flag) {
    $i = array_search($flag, $a, true);
    return $i === false ? null : ($a[$i + 1] ?? null);
};
$jsonl = fn(string $file): array => array_map(fn($l) => json_decode($l, true), file($file, FILE_IGNORE_NEW_LINES) ?: []);

$argsFile = tmp_dir() . '/fake_child_args.jsonl';
@unlink($argsFile);
$fake = tmp_dir() . '/fake_child';
file_put_contents($fake, "#!/usr/bin/env bash\nexec " . escapeshellarg(PHP_BINARY) . ' '
    . escapeshellarg(__DIR__ . '/fake_mining_child.php') . " \"\$@\"\n");
chmod($fake, 0755);
putenv("NB_CLAUDE_BIN=$fake");
putenv("NB_FAKE_ARGS=$argsFile");
$config = ['mining_child_model' => 'haiku', 'mining_child_timeout_s' => 20] + nb_config();
$lastCall = function () use ($argsFile, $jsonl): array {
    $calls = $jsonl($argsFile);
    return end($calls);
};

echo "mining settings in the config\n";
$expected = ['mining_workers' => 2, 'mining_comment_cap' => 3000, 'mining_chunk_size' => 150, 'mining_child_model' => 'haiku',
    'mining_child_timeout_s' => 600, 'typesafe_song_threshold' => 0.8, 'typesafe_artist_threshold' => 0.8];
$fromFile = json_decode((string)file_get_contents(nb_root() . '/config.json'), true);
check(is_array($fromFile), 'config.json is valid JSON');
$noFile = nb_config(tmp_dir() . '/no_such_config.json');
foreach ($expected as $key => $value) {
    check(($noFile[$key] ?? null) === $value, "default $key = " . json_encode($value));
    check(($fromFile[$key] ?? null) === $value, "config.json has $key = " . json_encode($value));
    check(nb_config()[$key] === $value, "nb_config() gives $key = " . json_encode($value));
}
check($noFile === NB_CONFIG_DEFAULTS && isset($noFile['batch_size'], $noFile['refill_when_left']), 'the old settings are still in the defaults');
check(($fromFile['batch_size'] ?? null) === 12 && ($fromFile['refill_when_left'] ?? null) === 5 && isset($fromFile['mix']['close']),
    'the old settings are still in config.json');

echo "where things live\n";
$saved = ['NB_COMMENTS_DIR' => getenv('NB_COMMENTS_DIR'), 'NB_ENV_FILE' => getenv('NB_ENV_FILE')];
putenv('NB_COMMENTS_DIR');
putenv('NB_ENV_FILE');
check(nb_comments_dir() === nb_root() . '/comments', 'comments folder defaults to <root>/comments');
check(nb_env_file() === nb_root() . '/.env', '.env defaults to <root>/.env');
putenv('NB_COMMENTS_DIR=/somewhere/else/comments');
putenv('NB_ENV_FILE=/somewhere/else/test.env');
check(nb_comments_dir() === '/somewhere/else/comments', 'NB_COMMENTS_DIR overrides the comments folder');
check(nb_env_file() === '/somewhere/else/test.env', 'NB_ENV_FILE overrides the .env path');
putenv('NB_COMMENTS_DIR=');
putenv('NB_ENV_FILE=');
check(nb_comments_dir() === nb_root() . '/comments' && nb_env_file() === nb_root() . '/.env', 'empty overrides fall back to the defaults');
foreach ($saved as $name => $value) {
    putenv($value === false ? $name : "$name=$value");
}

echo "the child's instructions\n";
$claudeMd = nb_root() . '/mining/child_CLAUDE.md';
check(is_file($claudeMd), 'mining/child_CLAUDE.md exists');
$text = (string)@file_get_contents($claudeMd);
check(str_contains($text, '[c') && str_contains($text, '[cN]'), 'it explains the [cN] numbering');
check(str_contains($text, 'own artist') && str_contains($text, 'unsure'), 'it explains the [own artist] and [unsure] tags');
check((bool)preg_match('/^\s*- \[c\d+\] none\s*$/m', $text), 'it shows the "none" line');
check(str_contains($text, 'artists.chunk-'), 'it names the output file pattern');
check(str_contains($text, 'Read') && str_contains($text, 'Write'), 'it says to read the chunk and write the output');

echo "running a command with a log\n";
$logDir = $video('logged');
$log = "$logDir/run.log";
file_put_contents($log, "EARLIER CONTENT\n");
$r = nb_run_logged(['bash', '-c', 'echo hello out; echo hello err >&2; exit 3'], $logDir, $log);
check($r['exit'] === 3 && $r['timed_out'] === false, 'the exit code is returned');
check($r['out'] === "hello out\n", 'stdout is captured, and stderr is not mixed into it');
check(is_float($r['duration_s']) || is_int($r['duration_s']), 'duration is a number');
$logText = (string)file_get_contents($log);
check(str_starts_with($logText, "EARLIER CONTENT\n"), 'the log is appended to, not overwritten');
check(str_contains($logText, 'echo hello out; echo hello err >&2; exit 3') && str_contains($logText, 'bash'), 'the log has the command line');
check(str_contains($logText, "hello out\n"), 'stdout goes to the log');
check(str_contains($logText, "hello err\n"), 'stderr goes to the log');
check((bool)preg_match('/exit 3 after [\d.]+s/', $logText), 'the log has the exit code and duration');
$r = nb_run_logged(['pwd'], $logDir, $log);
check(trim($r['out']) === realpath($logDir) && $r['exit'] === 0, 'the command runs in the given folder');
$t = microtime(true);
$r = nb_run_logged(['cat'], $logDir, $log);
check($r['exit'] === 0 && $r['out'] === '' && microtime(true) - $t < 3, 'stdin is closed, so a command that reads it ends at once');
check(substr_count((string)file_get_contents($log), "\n[") >= 3, 'each run adds its own start line');
$t = microtime(true);
$r = nb_run_logged(['bash', '-c', 'echo started; exec sleep 30'], $logDir, $log, 1.0);
check($r['timed_out'] === true && $r['exit'] === null, 'a command past its time limit is reported as timed out, with no exit code');
check(microtime(true) - $t < 4, 'and is killed at the limit, not left to finish');
check(str_contains($r['out'], 'started'), 'output before the kill is kept');
check(str_contains((string)file_get_contents($log), 'timed out'), 'the log says it was killed');
$t = microtime(true);
$r = nb_run_logged(['bash', '-c', 'echo quick'], $logDir, $log, 30.0);
check($r['timed_out'] === false && $r['exit'] === 0 && microtime(true) - $t < 4, 'a time limit does not delay a command that finishes early');

echo "the child's command line\n";
$dir = $video('command');
$cmd = nb_child_command('chunk-01.md', $dir, ['mining_child_model' => 'sonnet'] + $config);
check($cmd[0] === $fake, 'NB_CLAUDE_BIN is the program to run');
check($cmd[1] === '-p' && $cmd[2] === 'Follow CLAUDE.md in this folder. Process chunk-01.md and write artists.chunk-01.md.',
    'headless, with a prompt naming the chunk and artists.<chunk>');
check(array_slice($cmd, 3, 8) === ['--model', 'sonnet', '--tools', 'Read,Write', '--permission-mode', 'acceptEdits', '--output-format', 'json'],
    'model from the config, only Read and Write, acceptEdits, JSON output');
check($cmd[11] === '--settings' && $cmd[13] === '--disable-slash-commands' && count($cmd) === 14, '--settings, then --disable-slash-commands, and nothing else');
check(json_decode($cmd[12], true) === nb_isolation_settings($dir), "the settings are the isolation settings for the child's folder");
check(!in_array('--allowedTools', $cmd, true) && !in_array('--dangerously-skip-permissions', $cmd, true) && !in_array('--resume', $cmd, true),
    'no extra allowances, no permission bypass, no session to resume');
$cmd = nb_child_command('chunk-extra.md', $dir, $config);
check($cmd[2] === 'Follow CLAUDE.md in this folder. Process chunk-extra.md and write artists.chunk-extra.md.', 'the re-run chunk gets its own output name');
putenv('NB_CLAUDE_BIN');
check(nb_child_command('chunk-01.md', $dir, $config)[0] === 'claude', 'the program is `claude` when NB_CLAUDE_BIN is unset');
putenv("NB_CLAUDE_BIN=$fake");

echo "a child run\n";
$dir = $video('child_video');
copy(nb_root() . '/mining/child_CLAUDE.md', "$dir/CLAUDE.md");
file_put_contents("$dir/chunk-01.md", "# T\n\n- [c1] @a -- Burial vibes\n- [c2] @b -- lol\n- [c3] @c -- sounds like Aphex\n");
$r = nb_run_child('chunk-01.md', $dir, $config);
check($r['ok'] === true && $r['error'] === null && $r['cost_usd'] === 0.004 && $r['turns'] === 3, 'ok with cost and turns');
check($r['chunk'] === 'chunk-01.md' && $r['denials'] === [] && is_numeric($r['duration_s']), 'summary has the chunk, no denials and a duration');
check(array_keys($r) === ['chunk', 'ok', 'error', 'duration_s', 'cost_usd', 'turns', 'denials'], 'summary has exactly the documented fields');
$out = (string)@file_get_contents("$dir/artists.chunk-01.md");
check($out === "- [c1] Burial\n- [c2] none\n- [c3] Aphex\n", 'the child wrote its output file');
$call = $lastCall();
$a = $call['args'];
check(realpath($call['cwd']) === realpath($dir), 'the child runs inside the video folder');
check($opt($a, '--tools') === 'Read,Write' && $opt($a, '--model') === 'haiku', 'only Read and Write, on the configured model');
check($opt($a, '--permission-mode') === 'acceptEdits' && $opt($a, '--output-format') === 'json', 'accepts edits in its folder, JSON output');
check(in_array('--disable-slash-commands', $a, true), 'skills and commands disabled');
$settings = json_decode((string)$opt($a, '--settings'), true);
check(in_array(realpath(nb_root()) . '/CLAUDE.md', $settings['claudeMdExcludes'], true), "the parent's rulebook is excluded");
check(!in_array(realpath($dir) . '/CLAUDE.md', $settings['claudeMdExcludes'], true), "the folder's own CLAUDE.md is not excluded");
check(str_contains($a[1], 'chunk-01.md') && str_contains($a[1], 'artists.chunk-01.md'), 'the prompt names the chunk and output file');
check(count(file("$dir/children.jsonl")) === 1 && is_file("$dir/mine.log"), 'summary line and log written');
$line = $jsonl("$dir/children.jsonl")[0];
check(array_merge($line, ['duration_s' => (float)$line['duration_s']]) === array_merge($r, ['duration_s' => (float)$r['duration_s']]),
    'the summary line is what nb_run_child returned'); // (0.0 is written as 0, so compare the duration as a float)
check(str_contains((string)file_get_contents("$dir/mine.log"), '"type":"result"'), "the child's output is in mine.log");

echo "children.jsonl and mine.log across runs\n";
$dir = $video('history');
file_put_contents("$dir/chunk-01.md", "# T\n\n- [c1] @a -- Burial vibes\n");
file_put_contents("$dir/mine.log", "EARLIER RUN\n");
putenv('NB_FAKE_CHILD_STDERR=fake stderr line');
nb_run_child('chunk-01.md', $dir, $config);
putenv('NB_FAKE_CHILD_STDERR');
nb_run_child('chunk-01.md', $dir, $config);
putenv('NB_FAKE_CHILD_FAIL=1');
nb_run_child('chunk-01.md', $dir, $config);
putenv('NB_FAKE_CHILD_FAIL');
$lines = $jsonl("$dir/children.jsonl");
check(count($lines) === 3 && array_column($lines, 'chunk') === ['chunk-01.md', 'chunk-01.md', 'chunk-01.md'], 'children.jsonl has one line per run');
check(array_column($lines, 'ok') === [true, true, false], 'each line has its own result');
$logText = (string)file_get_contents("$dir/mine.log");
check(str_starts_with($logText, "EARLIER RUN\n"), 'mine.log is appended to, not overwritten');
check(substr_count($logText, '--output-format') === 3 && preg_match_all('/\] exit \d+ after [\d.]+s/', $logText) === 3, 'mine.log has all three runs');
check(str_contains($logText, 'fake stderr line'), "the child's stderr goes to mine.log");
check(str_contains($logText, 'fake failure'), 'a failed run is in mine.log too');

echo "failures\n";
$dir = $video('failures');
file_put_contents("$dir/chunk-01.md", "# T\n\n- [c1] @a -- Burial vibes\n");
putenv('NB_FAKE_CHILD_FAIL=1');
$r = nb_run_child('chunk-01.md', $dir, $config);
putenv('NB_FAKE_CHILD_FAIL');
check($r['ok'] === false && $r['error'] === 'fake failure', 'an error result is reported');
check($r['cost_usd'] === null && $r['turns'] === null && $r['denials'] === [], 'no cost or turns when the child gave none');
check(!is_file("$dir/artists.chunk-01.md"), 'nothing was written');

putenv('NB_FAKE_CHILD_NOWRITE=1');
$r = nb_run_child('chunk-01.md', $dir, $config);
putenv('NB_FAKE_CHILD_NOWRITE');
check($r['ok'] === false && str_contains((string)$r['error'], 'artists.chunk-01.md'), 'a child that exits 0 but writes no output file is not ok, and the error names the file');
check($r['cost_usd'] === 0.004 && $r['turns'] === 3, 'its cost and turns are still reported');

putenv('NB_FAKE_CHILD_NOTJSON=1');
$r = nb_run_child('chunk-01.md', $dir, $config);
putenv('NB_FAKE_CHILD_NOTJSON');
check($r['ok'] === false && str_contains((string)$r['error'], 'JSON'), 'output that is not JSON is not ok, even though the file was written');
check($r['cost_usd'] === null && $r['turns'] === null, 'no cost or turns from non-JSON output');
@unlink("$dir/artists.chunk-01.md");

putenv('NB_FAKE_CHILD_EXIT=2');
$r = nb_run_child('chunk-01.md', $dir, $config);
putenv('NB_FAKE_CHILD_EXIT');
check($r['ok'] === false && is_string($r['error']) && $r['error'] !== '', 'a non-zero exit is not ok, whatever else it printed');
@unlink("$dir/artists.chunk-01.md");

$lines = $jsonl("$dir/children.jsonl");
check(count($lines) === 4 && array_column($lines, 'ok') === [false, false, false, false], 'every failure is in children.jsonl');
check($lines[0]['error'] === 'fake failure', 'with its error');

putenv('NB_FAKE_CHILD_SLEEP=4');
$t = microtime(true);
$r = nb_run_child('chunk-01.md', $dir, ['mining_child_timeout_s' => 1] + $config);
$took = microtime(true) - $t;
putenv('NB_FAKE_CHILD_SLEEP');
check($r['ok'] === false && str_contains((string)$r['error'], 'timed out'), 'a child past the time limit is not ok');
check($took < 3, 'and the run returned at the limit, not when the child finished');
sleep(3); // a child that was not killed would have finished by now
check(!is_file("$dir/artists.chunk-01.md"), 'the child was killed before it wrote anything');
$lines = $jsonl("$dir/children.jsonl");
check(end($lines)['ok'] === false && str_contains((string)end($lines)['error'], 'timed out'), 'the timeout is in children.jsonl');

echo "permission denials\n";
$dir = $video('denials');
file_put_contents("$dir/chunk-01.md", "# T\n\n- [c1] @a -- Burial vibes\n");
putenv('NB_FAKE_CHILD_DENY=1');
$r = nb_run_child('chunk-01.md', $dir, $config);
putenv('NB_FAKE_CHILD_DENY');
check($r['denials'] === ['Write: {"file_path":"/etc/passwd","content":"x"}', 'Read: {"file_path":"/home/someone/secret.txt"}'],
    'denials come back as "Tool: {input}" strings');
check($r['ok'] === true, 'refused attempts alone do not make the run fail');
check($jsonl("$dir/children.jsonl")[0]['denials'] === $r['denials'], 'and are recorded in children.jsonl');

echo "TypeSafe key\n";
$env = tmp_dir() . '/test.env';
putenv("NB_ENV_FILE=$env");
$cases = [
    ["# comment\nTYPESAFE_API=\nOTHER=1\n", false, 'an empty key is no key, even with another setting on the next line'],
    ["TYPESAFE_API=abc123\n", true, 'a key is found'],
    ["TYPESAFE_API=x", true, 'a one-character key with no newline at the end'],
    ["TYPESAFE_API_KEY=x\n", true, 'the TYPESAFE_API_KEY spelling'],
    ["OTHER=1\nTYPESAFE_API=abc\n", true, 'a key on a later line'],
    ["# TYPESAFE_API=abc123\n", false, 'a commented-out key is no key'],
    ["  TYPESAFE_API = abc123\n", true, 'spaces around the = are fine'],
    ["TYPESAFE_API=   \nOTHER=1\n", false, 'a key of only spaces is no key'],
    ["TYPESAFE_API=\r\nOTHER=1\r\n", false, 'an empty key in a file with Windows line endings'],
    ["TYPESAFE_API=abc123\r\n", true, 'a key in a file with Windows line endings'],
    ["MY_TYPESAFE_API=abc\n", false, 'another setting that merely ends in the name'],
    ["", false, 'an empty file'],
];
foreach ($cases as [$content, $want, $label]) {
    file_put_contents($env, $content);
    check(nb_has_typesafe_key() === $want, $label);
}
putenv('NB_ENV_FILE=' . tmp_dir() . '/no_such.env');
check(nb_has_typesafe_key() === false, 'a missing .env file is no key');
putenv('NB_ENV_FILE');

echo "coverage\n";
$dir = $prepared('coverage', [
    $comment('@a', 'Burial vibes'),
    $comment('@b', 'lol nothing here'),
    $comment('@c', 'SKIPME, sounds like Aphex'),
]);
$covLog = "$dir/mine.log";
$r = nb_run_child('chunk-01.md', $dir, $config); // the fake leaves the SKIPME comment out
check($r['ok'] === true, 'the child ran on a prepared chunk');
$cov = nb_coverage($dir, false, $covLog);
check($cov['flagged'] === 3 && $cov['covered'] === 2 && $cov['missed'] === ['c3'] && $cov['unparsed'] === [] && $cov['extra_chunk'] === null,
    'coverage reports the comment the child skipped');
check(!is_file("$dir/chunk-extra.md"), 'no re-run chunk unless asked');
check(str_contains((string)file_get_contents($covLog), 'coverage.py'), 'the coverage call is logged');
$cov = nb_coverage($dir, true, $covLog);
check($cov['extra_chunk'] === 'chunk-extra.md' && is_file("$dir/chunk-extra.md"), 'with $writeExtra it writes chunk-extra.md');
check(str_contains((string)file_get_contents("$dir/chunk-extra.md"), '- [c3] @c -- SKIPME'), 'which holds the missed comment');
$r = nb_run_child('chunk-extra.md', $dir, $config);
check($r['ok'] === true && str_contains((string)@file_get_contents("$dir/artists.chunk-extra.md"), '- [c3] Aphex'), 'the child re-runs the missed comment');
$cov = nb_coverage($dir, true, $covLog);
check($cov['missed'] === [] && $cov['covered'] === 3 && $cov['extra_chunk'] === null, 'after the re-run every comment is covered');
check($jsonl("$dir/children.jsonl") !== [] && count($jsonl("$dir/children.jsonl")) === 2, 'both child runs are in children.jsonl');

$empty = $video('coverage_empty');
$badLog = "$empty/mine.log";
try {
    nb_coverage($empty, false, $badLog);
    check(false, 'coverage on a folder with no comment_index.json throws');
} catch (RuntimeException $e) {
    check(str_contains($e->getMessage(), $badLog) && str_contains($e->getMessage(), 'coverage.py'), 'the exception names the log to look in');
    check(str_contains((string)file_get_contents($badLog), 'comment_index.json'), "the script's error is in that log");
}

echo "real comments through the child\n";
$rows = array_map(fn($l) => json_decode($l, true), file(__DIR__ . '/fixtures/mining/real_comments.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
check(count($rows) === 564, 'the fixture has 564 real comments');
$flagged = array_values(array_filter($rows, fn($c) => $c['video_id'] === 'Dvvdml84wvc' && $c['typesafe_flagged']));
check(count($flagged) === 18, "18 of the Kids video's comments are TypeSafe-flagged");
$dir = $prepared('Dvvdml84wvc', $flagged);
$chunk = (string)file_get_contents("$dir/chunk-01.md");
preg_match_all('/^- \[c(\d+)\] /m', $chunk, $mm);
$ids = array_map('intval', $mm[1]);
check($ids === range(1, 18), 'prepare.py put all 18 comments in chunk-01.md, numbered c1 to c18');
$r = nb_run_child('chunk-01.md', $dir, $config);
check($r['ok'] === true && is_file("$dir/artists.chunk-01.md"), 'the child ran on the real chunk');
$outFile = "$dir/artists.chunk-01.md";
$py = 'import json, sys; sys.path.insert(0, sys.argv[1]); from pathlib import Path; import mentions; u = []; '
    . 'r = mentions.read_mentions(Path(sys.argv[2]), u); print(json.dumps({"mentions": r, "unparsed": u}))';
$parsed = json_decode((string)shell_exec('python3 -c ' . escapeshellarg($py) . ' ' . escapeshellarg(nb_root() . '/mining')
    . ' ' . escapeshellarg($outFile)), true);
check(is_array($parsed) && $parsed['unparsed'] === [], 'mentions.py reads every line of the output, none left unparsed');
$byId = [];
foreach ($parsed['mentions'] ?? [] as [$cid, $artist, $song, $tag]) {
    $byId[$cid][] = ['artist' => $artist, 'tag' => $tag];
}
$missing = array_values(array_diff($ids, array_keys($byId)));
check($missing === [], 'every comment id in the chunk has a line in the output' . ($missing ? ' (missing ' . implode(',', $missing) . ')' : ''));
check(array_values(array_diff(array_keys($byId), $ids)) === [], 'and no line for an id that is not in the chunk');
$idOf = function (string $needle) use ($flagged): int {
    foreach ($flagged as $i => $c) {
        if (str_contains($c['text'], $needle)) {
            return $i + 1;
        }
    }
    throw new RuntimeException("no flagged comment contains '$needle'");
};
$artists = fn(int $id): array => array_column($byId[$id] ?? [], 'artist');
check($artists($idOf('Gerard Way')) === ['Gerard Way', 'My Chemical Romance'], 'a comment naming two artists gets a line for each');
check($artists($idOf('introduced to Sleigh Bells')) === ['Sleigh Bells'] && $byId[$idOf('introduced to Sleigh Bells')][0]['tag'] === 'own artist',
    'the video\'s own artist is tagged own artist');
check($artists($idOf('million dollar bills')) === ['Lorde'], '"sounds like ... by lorde" names Lorde');
check($artists($idOf('M.I.A.-esque')) === ['M.I.A.'], 'a name with dots survives the round trip');
check($artists($idOf('Danny Harf')) === [null] && $byId[$idOf('Danny Harf')][0]['tag'] === 'none', 'a comment that names nobody gets "none"');
$cov = nb_coverage($dir, false, "$dir/mine.log");
check($cov['flagged'] === 18 && $cov['covered'] === 18 && $cov['missed'] === [], 'coverage reports zero missed');
check($cov['unparsed'] === [] && $cov['unknown'] === [], 'and nothing unparsed or unknown');

finish();
