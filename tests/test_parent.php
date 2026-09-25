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
$args = fn() => file($argsFile, FILE_IGNORE_NEW_LINES);

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

finish();
