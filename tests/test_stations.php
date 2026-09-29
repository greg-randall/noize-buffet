<?php
declare(strict_types=1);
// The job worker serving two stations at once, with the fake agent.
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/assert.php';

$dir = tmp_dir() . '/stations_worker';
exec('rm -rf ' . escapeshellarg($dir));
mkdir($dir);
$fake = "$dir/fake_claude";
file_put_contents($fake, "#!/usr/bin/env bash\nexec " . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/fake_claude_stream.php') . " \"\$@\"\n");
chmod($fake, 0755);
$env = ['NB_PROFILES_DIR' => "$dir/profiles", 'NB_CLAUDE_BIN' => $fake, 'NB_FAKE_ARGS' => "$dir/args.jsonl",
    'NB_FAKE_INPUTS' => "$dir/inputs.jsonl", 'NB_FAKE_ENV' => "$dir/env.txt", 'NB_COMMENTS_DIR' => "$dir/comments"];
foreach ($env as $k => $v) {
    putenv("$k=$v");
}
putenv('NB_DB');
putenv('NB_PROFILE');

$worker = function () use ($env): array {
    $p = proc_open([PHP_BINARY, __DIR__ . '/../scripts/job_worker.php', '--once'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    return [proc_close($p), $out];
};

echo "two stations, one worker\n";
nb_profile_create('Ambient');
nb_profile_create('Jazz');
nb_profile('ambient');
$amb = nb_db();
nb_profile('jazz');
$jazz = nb_db();
nb_job_enqueue($amb, 'chat', ['message' => 'hello from ambient']);
nb_job_enqueue($amb, 'chat', ['message' => 'second ambient']);
nb_job_enqueue($jazz, 'chat', ['message' => 'hello from jazz']);
[$code, $out] = $worker();
check($code === 0, 'the worker finishes' . ($code ? " (exit $code: $out)" : ''));
$status = fn(PDO $p) => $p->query('SELECT status FROM jobs ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
check($status($amb) === ['done', 'done'] && $status($jazz) === ['done'], "every station's messages were answered");
$replies = fn(PDO $p) => array_column(array_filter(nb_chat_since($p, 0), fn($m) => $m['role'] === 'parent'), 'text');
check(count($replies($amb)) === 2 && count($replies($jazz)) === 1, "each reply is in its own station's chat");
check(str_contains($out, '[ambient]') && str_contains($out, '[jazz]'), 'the log says which station each job is for');

$sidA = nb_setting($amb, 'parent_session_id');
$sidJ = nb_setting($jazz, 'parent_session_id');
check($sidA !== null && $sidJ !== null && $sidA !== $sidJ, 'each station has a conversation of its own');
$envs = file("$dir/env.txt", FILE_IGNORE_NEW_LINES);
check(in_array('ambient', $envs, true) && in_array('jazz', $envs, true) && !in_array('', $envs, true),
    'the agent always ran with a station set');
$inputs = array_map('json_decode', file("$dir/inputs.jsonl", FILE_IGNORE_NEW_LINES));
$forJazz = array_values(array_filter($inputs, fn($t) => str_contains($t, 'hello from jazz')))[0] ?? '';
check(str_contains($forJazz, 'the station "Jazz"') && str_contains($forJazz, '/jazz/'),
    'a new conversation is told which station it is and where its files are');

echo "a usage limit found in one station stops both\n";
nb_job_enqueue($amb, 'chat', ['message' => 'FAKE_LIMIT']);
nb_job_enqueue($jazz, 'chat', ['message' => 'jazz waits']);
[$code, $out] = $worker();
check(nb_usage_paused($jazz) !== null && $status($jazz)[1] === 'queued', "the other station's message waits too");
check(str_contains($out, 'out of Claude usage'), 'and the worker says why');

echo "mining for two stations: one video, read once\n";
nb_usage_clear($amb);
nb_usage_clear($jazz);
$wrap = function (string $name, string $script) use ($dir): string {
    $path = "$dir/$name";
    file_put_contents($path, "#!/usr/bin/env bash\nexec " . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . "/$script") . " \"\$@\"\n");
    chmod($path, 0755);
    return $path;
};
$comments = "$dir/fixture_comments.json";
file_put_contents($comments, json_encode([
    ['id' => 'a1', 'author' => '@ann', 'author_id' => 'UCann', 'like_count' => 50, 'text' => 'if you like this check out Burial'],
    ['id' => 'a2', 'author' => '@bob', 'author_id' => 'UCbob', 'like_count' => 5, 'text' => 'Burial - Archangel is the blueprint'],
    ['id' => 'a3', 'author' => '@cat', 'author_id' => 'UCcat', 'like_count' => 1, 'text' => 'sounds like Tomggg'],
]));
file_put_contents("$dir/empty.env", "TYPESAFE_API=\n");
foreach (['NB_YTDLP_BIN' => $wrap('fake_ytdlp', 'fake_ytdlp.php'), 'NB_FAKE_COMMENTS' => $comments, 'NB_FAKE_ARGS' => "$dir/mine_args.jsonl",
    'NB_CLAUDE_BIN' => $wrap('fake_child', 'fake_mining_child.php'), 'NB_ENV_FILE' => "$dir/empty.env", 'NB_MINING_CHUNK_SIZE' => '2'] as $k => $v) {
    putenv("$k=$v");
}
nb_profile('ambient');
nb_add_batch($amb, [['video_id' => 'V0000000001', 'title' => 'Song', 'artist' => 'Artist', 'bucket' => 'user']], 'b', null);
nb_profile('jazz');
nb_add_batch($jazz, [['video_id' => 'V0000000001', 'title' => 'Song', 'artist' => 'Artist', 'bucket' => 'user']], 'b', null);
$m = proc_open([PHP_BINARY, __DIR__ . '/../scripts/mine_worker.php', '--once'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
$mout = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
check(proc_close($m) === 0, 'the mining worker finishes' . (str_contains($mout, 'paused') ? " (paused: $mout)" : ''));
$row = fn(PDO $p) => array_values(nb_mining_list($p))[0];
check($row($amb)['status'] === 'done' && $row($jazz)['status'] === 'done', 'the song is mined for both stations');
$read = array_values(array_filter(file("$dir/mine_args.jsonl", FILE_IGNORE_NEW_LINES), fn($l) => str_contains($l, '--write-comments')));
check(count($read) === 1, 'but downloaded and read only once');
check(($row($amb)['filter'] === 'shared') !== ($row($jazz)['filter'] === 'shared'), 'the second one reused the first one\'s names');
check(in_array('burial', array_column(nb_leads($amb), 'name_key'), true) && in_array('burial', array_column(nb_leads($jazz), 'name_key'), true),
    "and each station has the leads in its own database");
check(is_dir("$dir/comments/V0000000001") && !is_dir("$dir/profiles/ambient/comments"), 'the downloaded comments are shared, in one folder');

finish();
