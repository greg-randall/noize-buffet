<?php
declare(strict_types=1);
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/config.php';
require __DIR__ . '/../lib/parent.php';
require __DIR__ . '/assert.php';

$pdo = fresh_db('test_parent');
$argsFile = tmp_dir() . '/fake_args.jsonl';
$inputsFile = tmp_dir() . '/fake_inputs.jsonl';
@unlink($argsFile);
@unlink($inputsFile);
$fake = tmp_dir() . '/fake_claude';
file_put_contents($fake, "#!/usr/bin/env bash\nexec " . escapeshellarg(PHP_BINARY) . ' '
    . escapeshellarg(__DIR__ . '/fake_claude_stream.php') . " \"\$@\"\n");
chmod($fake, 0755);
putenv("NB_CLAUDE_BIN=$fake");
putenv("NB_FAKE_ARGS=$argsFile");
putenv("NB_FAKE_INPUTS=$inputsFile");
$config = ['parent_model' => 'sonnet', 'session_rotate_turns' => 3, 'job_timeout_s' => 20] + nb_config();

/** Every process start so far, each as its argument list. */
$spawns = fn() => array_map(fn($l) => json_decode($l, true), file($argsFile, FILE_IGNORE_NEW_LINES) ?: []);
$args = function () use ($spawns): array {
    $all = $spawns();
    return end($all) ?: [];
};
$lastInput = function () use ($inputsFile): string {
    $lines = file($inputsFile, FILE_IGNORE_NEW_LINES);
    return json_decode((string)end($lines), true);
};
$opt = fn(array $a, string $flag) => $a[array_search($flag, $a, true) + 1] ?? null;
$run = function (array $payload, ?callable $onEvent = null, ?array $cfg = null) use ($pdo, &$parent, $config): array {
    nb_job_enqueue($pdo, 'chat', $payload);
    return nb_run_parent_job($pdo, nb_job_next($pdo), $cfg ?? $config, $parent, $onEvent);
};
$parent = new NbParentProcess();

echo "first job: starts a process with a new session\n";
nb_chat_add($pdo, 'user', 'hi there');
$id = nb_job_enqueue($pdo, 'chat', ['message' => 'hi there']);
$s = nb_run_parent_job($pdo, nb_job_next($pdo), $config, $parent);
$a = $args();
check(count($spawns()) === 1 && $s['process'] === 'started' && $parent->running(), 'one process started and still running');
check(!in_array('--resume', $a, true), 'no --resume on first job');
check($opt($a, '--input-format') === 'stream-json' && $opt($a, '--output-format') === 'stream-json' && in_array('--verbose', $a, true), 'stream-json in and out');
check($opt($a, '--tools') === 'Read,Edit,Write,WebSearch,WebFetch,Bash', 'built-in tools restricted');
check(in_array('acceptEdits', $a, true) && $opt($a, '--model') === 'sonnet', 'permission mode and model');
$tools = (string)$opt($a, '--allowedTools');
check(str_contains($tools, 'Bash(php bin/nb.php *)') && str_contains($tools, 'Bash(python3 scripts/yt_search.py *)'), 'narrow allowed tools');
check(in_array('--disable-slash-commands', $a, true), 'skills and commands disabled');
$settings = json_decode((string)$opt($a, '--settings'), true);
check(is_array($settings) && $settings['disableAllHooks'] === true && $settings['autoMemoryEnabled'] === false, 'isolation settings passed');
check(!in_array(realpath(nb_root()) . '/CLAUDE.md', $settings['claudeMdExcludes'], true), "the repo's own CLAUDE.md is not excluded");
check(str_contains($lastInput(), 'CLAUDE.md') && str_contains($lastInput(), 'hi there'), 'first message has the intro and the user message');
$last = nb_chat_since($pdo, 0);
check(end($last)['role'] === 'parent' && end($last)['text'] === 'hello from fake', 'reply in chat');
$sid = nb_setting($pdo, 'parent_session_id');
check($sid === $parent->sessionId && str_starts_with((string)$sid, 'sess-') && nb_setting($pdo, 'parent_turns') === '1', 'session saved, turns 1');
check($pdo->query("SELECT status FROM jobs WHERE id = $id")->fetchColumn() === 'done', 'job done');

echo "second job: same process\n";
$events = [];
$s = $run(['message' => 'more please'], function (array $e) use (&$events) { $events[] = $e; });
check(count($spawns()) === 1 && $s['process'] === 'reused', 'no new process');
check(!str_contains($lastInput(), 'CLAUDE.md') && str_contains($lastInput(), 'more please'), 'no re-intro in the same session');
check(nb_setting($pdo, 'parent_turns') === '2' && nb_setting($pdo, 'parent_session_id') === $sid, 'turns 2, same session');
check(abs($s['cost_usd'] - 0.005) < 1e-9, 'job cost is the difference from the previous total');
$tool = array_filter($events, fn($e) => ($e['type'] ?? '') === 'assistant' && ($e['message']['content'][0]['type'] ?? '') === 'tool_use');
check(count($tool) === 1 && end($events)['type'] === 'result', 'onEvent sees tool calls and the result');

echo "per-job cost\n";
$s = $run(['message' => 'FAKE_COST=0.035']);
check(abs($s['cost_usd'] - 0.025) < 1e-9, 'cost from a jump in the total');

echo "rotation starts a fresh process\n";
$oldPid = $parent->pid();
$s = $run(['message' => 'x']); // parent_turns is 3 now, which is session_rotate_turns
check(count($spawns()) === 2 && $s['process'] === 'started' && $parent->pid() !== $oldPid, 'new process');
check(!in_array('--resume', $args(), true) && str_contains($lastInput(), 'CLAUDE.md'), 'fresh session with the intro');
check(nb_setting($pdo, 'parent_turns') === '1' && nb_setting($pdo, 'parent_session_id') !== $sid, 'turns reset, new session id');

echo "song context in the prompt\n";
$run(['message' => 'love the drums', 'song' => nb_song_context(
    ['video_id' => 'AAAAAAAAAAA', 'artist' => 'Some Artist', 'title' => 'Some Song', 'furthest_pct' => 64, 'rating' => 'yes', 'new_to_me' => true])]);
$p = $lastInput();
check(str_contains($p, 'currently listening to: Some Artist - Some Song [video_id AAAAAAAAAAA]') && str_contains($p, 'heard 64%')
    && str_contains($p, 'rating: yes') && str_contains($p, 'new to them') && str_contains($p, 'love the drums'), 'prompt carries the current song');

echo "interview prompt\n";
nb_job_enqueue($pdo, 'interview');
nb_run_parent_job($pdo, nb_job_next($pdo), $config, $parent);
check(str_contains($lastInput(), 'interview'), 'interview prompt');

echo "activity shown in the chat panel\n";
$seen = [];
$run(['message' => 'what are you doing'], function (array $e) use ($pdo, &$seen) {
    $seen[] = nb_job_status($pdo)['running']['activity'] ?? null;
});
check(in_array('Checking the queue', $seen, true), "tool call becomes the running job's activity"); // the fake runs `php bin/nb.php status`
check(nb_setting($pdo, 'agent_activity') === null, 'activity cleared when the job ends');
check(nb_activity_text('Bash', ['command' => 'python3 scripts/yt_search.py "a b" "c d" "e" -n 5']) === 'Searching YouTube (3 songs)', 'YouTube search with a song count');
check(nb_activity_text('Bash', ['command' => "python3 scripts/yt_search.py 'one song'"]) === 'Searching YouTube', 'single search');
check(nb_activity_text('Bash', ['command' => 'php bin/nb.php feedback']) === 'Reading your ratings and notes', 'feedback');
check(nb_activity_text('Bash', ['command' => 'php bin/nb.php say "hi"']) === null, 'say leaves the activity alone');
check(nb_activity_text('Read', ['file_path' => '/x/taste.md']) === 'Reading your taste notes', 'reading taste.md');
check(nb_activity_text('Write', ['file_path' => 'data/pending-batch.json']) === 'Putting the batch together', 'writing the batch');
check(nb_activity_text('WebFetch', ['url' => 'https://example.bandcamp.com/album/x']) === 'Reading example.bandcamp.com', 'web fetch shows the site');
check(nb_activity_text('WebSearch', ['query' => 'hyperpop cheer']) === 'Searching the web: hyperpop cheer', 'web search shows the query');

echo "worker restart resumes the saved session\n";
$parent->stop();
$parent = new NbParentProcess();
$sid = nb_setting($pdo, 'parent_session_id');
nb_setting_set($pdo, 'parent_session_cost', '0.02');
nb_setting_set($pdo, 'parent_turns', '1'); // keep it below session_rotate_turns so the session is resumed
$s = $run(['message' => 'FAKE_COST=0.03 back again']);
check($s['process'] === 'started' && $opt($args(), '--resume') === $sid, 'new process resumes the saved session');
check(!str_contains($lastInput(), 'CLAUDE.md'), 'no re-intro when resuming');
check(abs($s['cost_usd'] - 0.01) < 1e-9, 'first job after resume subtracts the saved session cost');

echo "error result\n";
$before = count($spawns());
$fid = nb_job_enqueue($pdo, 'chat', ['message' => 'FAKE_FAIL']);
nb_run_parent_job($pdo, nb_job_next($pdo), $config, $parent);
$job = $pdo->query("SELECT status, error FROM jobs WHERE id = $fid")->fetch();
check($job['status'] === 'failed' && str_contains((string)$job['error'], 'boom'), 'job failed with error');
$msgs = nb_chat_since($pdo, 0);
check(end($msgs)['role'] === 'system' && str_contains(end($msgs)['text'], 'boom'), 'system message in chat');
check(str_contains(end($msgs)['text'], "jobs/$fid.json"), 'error message points at the debug file');
check(nb_setting($pdo, 'parent_session_id') === null && !$parent->running(), 'session cleared and process stopped');
$dbg = json_decode((string)file_get_contents(tmp_dir() . "/jobs/$fid.json"), true);
check($dbg['ok'] === false && str_contains(json_encode($dbg['events']), 'boom'), 'debug file has the events');
$s = $run(['message' => 'after the error']);
check($s['ok'] && count($spawns()) === $before + 1 && !in_array('--resume', $args(), true), 'next job starts a fresh process');

echo "process exits mid-job\n";
$xid = nb_job_enqueue($pdo, 'chat', ['message' => 'FAKE_EXIT']);
$s = nb_run_parent_job($pdo, nb_job_next($pdo), $config, $parent);
$err = (string)$pdo->query("SELECT error FROM jobs WHERE id = $xid")->fetchColumn();
check(!$s['ok'] && str_contains($err, 'exited') && str_contains($err, 'exit code 3'), 'job failed: process exited');
check(!$parent->running() && nb_setting($pdo, 'parent_session_id') === null, 'process gone, session cleared');

echo "timeout\n";
$t = microtime(true);
$hid = nb_job_enqueue($pdo, 'chat', ['message' => 'FAKE_HANG']);
$s = nb_run_parent_job($pdo, nb_job_next($pdo), ['job_timeout_s' => 1] + $config, $parent);
$err = (string)$pdo->query("SELECT error FROM jobs WHERE id = $hid")->fetchColumn();
check(!$s['ok'] && str_contains($err, 'no reply') && !$parent->running(), 'job failed and process killed');
check(microtime(true) - $t < 4.5, 'gave up near the timeout, not after the hang');

echo "debug output\n";
$okId = nb_job_enqueue($pdo, 'chat', ['message' => 'debug me']);
$s = nb_run_parent_job($pdo, nb_job_next($pdo), $config, $parent);
$dbg = json_decode((string)file_get_contents(tmp_dir() . "/jobs/$okId.json"), true);
check($s['ok'] === true && $s['turns'] === 2 && $s['denials'] === [] && is_int($s['pid']), 'summary has turns, denials, pid');
check(str_contains($dbg['prompt'], 'debug me') && $dbg['process']['started_for_this_job'] === true, 'debug file has prompt and process info');
check(in_array('--allowedTools', $dbg['process']['command'], true) && is_numeric($dbg['duration_s']), 'debug file has command and duration');
check(count($dbg['events']) === 3 && end($dbg['events'])['type'] === 'result', 'debug file has every event');
check(array_key_exists('transcript', $dbg), 'debug file has transcript field');

echo "permission denials\n";
$dId = nb_job_enqueue($pdo, 'chat', ['message' => 'FAKE_DENY']);
$s = nb_run_parent_job($pdo, nb_job_next($pdo), $config, $parent);
check($s['denials'] === ['Bash: ls /'], 'denial summarised');
$sys = array_values(array_filter(nb_chat_since($pdo, 0), fn($m) => $m['role'] === 'system' && (int)$m['job_id'] === $dId));
check(count($sys) === 1 && str_contains($sys[0]['text'], 'Bash: ls /'), 'denial posted to chat');
check($pdo->query("SELECT status FROM jobs WHERE id = $dId")->fetchColumn() === 'done', 'job with denials still completes');

echo "stop\n";
$parent->stop();
check(!$parent->running() && $parent->stop() === null, 'stop is idempotent');

echo "transcript path\n";
check(nb_transcript_path(null) === null && nb_transcript_path('no-such-session') === null, 'missing transcript gives null');

finish();
