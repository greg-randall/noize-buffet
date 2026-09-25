<?php
declare(strict_types=1);
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/config.php';
require __DIR__ . '/../lib/parent.php';
require __DIR__ . '/assert.php';

$pdo = fresh_db('test_parent');
$argsFile = tmp_dir() . '/fake_args.txt';
chmod(__DIR__ . '/fake_claude.sh', 0755);
putenv('NB_CLAUDE_BIN=' . __DIR__ . '/fake_claude.sh');
putenv("NB_FAKE_ARGS=$argsFile");
$config = ['parent_model' => 'sonnet', 'session_rotate_turns' => 3] + nb_config();
$args = fn() => explode("\0", rtrim((string)file_get_contents($argsFile), "\0"));

echo "first job: new session\n";
nb_chat_add($pdo, 'user', 'hi there');
$id = nb_job_enqueue($pdo, 'chat', ['message' => 'hi there']);
nb_run_parent_job($pdo, nb_job_next($pdo), $config);
$a = $args();
check(!in_array('--resume', $a, true), 'no --resume on first job');
check(in_array('--output-format', $a, true) && in_array('json', $a, true), 'json output');
check(in_array('acceptEdits', $a, true) && in_array('sonnet', $a, true), 'permission mode and model');
$tools = $a[array_search('--allowedTools', $a, true) + 1] ?? '';
check(str_contains($tools, 'Bash(php bin/nb.php *)') && str_contains($tools, 'Bash(python3 scripts/yt_search.py *)'), 'narrow allowed tools');
check(str_contains($a[array_search('-p', $a, true) + 1], 'CLAUDE.md'), 'new-session prompt mentions CLAUDE.md');
check(str_contains($a[array_search('-p', $a, true) + 1], 'hi there'), 'prompt carries the user message');
check(in_array("NB_JOB_ID=$id", $a, true), 'NB_JOB_ID passed');
check(in_array('--disable-slash-commands', $a, true), 'skills and commands disabled');
$settings = json_decode($a[array_search('--settings', $a, true) + 1] ?? '', true);
check(is_array($settings) && $settings['disableAllHooks'] === true && $settings['autoMemoryEnabled'] === false, 'isolation settings passed');
check(!in_array(realpath(nb_root()) . '/CLAUDE.md', $settings['claudeMdExcludes'], true), "the repo's own CLAUDE.md is not excluded");
$last = nb_chat_since($pdo, 0);
check(end($last)['role'] === 'parent' && end($last)['text'] === 'hello from fake', 'reply in chat');
check(nb_setting($pdo, 'parent_session_id') === 'sess-123' && nb_setting($pdo, 'parent_turns') === '1', 'session saved, turns 1');
check($pdo->query("SELECT status FROM jobs WHERE id = $id")->fetchColumn() === 'done', 'job done');

echo "second job: resume\n";
nb_job_enqueue($pdo, 'chat', ['message' => 'more please']);
nb_run_parent_job($pdo, nb_job_next($pdo), $config);
$a = $args();
check(($a[array_search('--resume', $a, true) + 1] ?? '') === 'sess-123', 'resumes saved session');
check(!str_contains($a[array_search('-p', $a, true) + 1], 'CLAUDE.md'), 'no re-intro on resumed session');
check(nb_setting($pdo, 'parent_turns') === '2', 'turns 2');

echo "rotation\n";
nb_setting_set($pdo, 'parent_turns', '3');
nb_job_enqueue($pdo, 'chat', ['message' => 'x']);
nb_run_parent_job($pdo, nb_job_next($pdo), $config);
check(!in_array('--resume', $args(), true) && nb_setting($pdo, 'parent_turns') === '1', 'rotates after session_rotate_turns');

echo "interview prompt\n";
nb_job_enqueue($pdo, 'interview');
nb_run_parent_job($pdo, nb_job_next($pdo), $config);
check(str_contains($args()[array_search('-p', $args(), true) + 1], 'interview'), 'interview prompt');

echo "failure\n";
putenv('NB_FAKE_FAIL=1');
$fid = nb_job_enqueue($pdo, 'chat', ['message' => 'will fail']);
nb_run_parent_job($pdo, nb_job_next($pdo), $config);
putenv('NB_FAKE_FAIL');
$job = $pdo->query("SELECT status, error FROM jobs WHERE id = $fid")->fetch();
check($job['status'] === 'failed' && str_contains((string)$job['error'], 'boom'), 'job failed with error');
$msgs = nb_chat_since($pdo, 0);
check(end($msgs)['role'] === 'system' && str_contains(end($msgs)['text'], 'boom'), 'system message in chat');
check(nb_setting($pdo, 'parent_session_id') === null, 'session cleared after failure');
$dbg = json_decode((string)file_get_contents(tmp_dir() . "/jobs/$fid.json"), true);
check(is_array($dbg) && $dbg['ok'] === false && str_contains($dbg['raw_output'], 'boom'), 'failed job debug file has raw output');
check(str_contains(end($msgs)['text'], "jobs/$fid.json"), 'error message points at the debug file');

echo "debug output\n";
$okId = nb_job_enqueue($pdo, 'chat', ['message' => 'debug me']);
$s = nb_run_parent_job($pdo, nb_job_next($pdo), $config);
$dbg = json_decode((string)file_get_contents(tmp_dir() . "/jobs/$okId.json"), true);
check($s['ok'] === true && $s['turns'] === 2 && $s['cost_usd'] === 0.005 && $s['denials'] === [], 'summary has turns and cost');
check($dbg['prompt'] !== '' && str_contains($dbg['prompt'], 'debug me') && $dbg['exit_code'] === 0, 'debug file has prompt and exit code');
check(in_array('--allowedTools', $dbg['command'], true) && is_numeric($dbg['duration_s']), 'debug file has command and duration');
check(array_key_exists('transcript', $dbg), 'debug file has transcript field');

echo "permission denials\n";
putenv('NB_FAKE_DENY=1');
$dId = nb_job_enqueue($pdo, 'chat', ['message' => 'do something sneaky']);
$s = nb_run_parent_job($pdo, nb_job_next($pdo), $config);
putenv('NB_FAKE_DENY');
check($s['denials'] === ['Bash: ls /'], 'denial summarised');
$sys = array_values(array_filter(nb_chat_since($pdo, 0), fn($m) => $m['role'] === 'system' && (int)$m['job_id'] === $dId));
check(count($sys) === 1 && str_contains($sys[0]['text'], 'Bash: ls /'), 'denial posted to chat');
check($pdo->query("SELECT status FROM jobs WHERE id = $dId")->fetchColumn() === 'done', 'job with denials still completes');

echo "transcript path\n";
check(nb_transcript_path(null) === null && nb_transcript_path('no-such-session') === null, 'missing transcript gives null');

finish();
