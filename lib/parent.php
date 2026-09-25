<?php
declare(strict_types=1);

// Tools the parent may use without asking. Bash is limited to the two helper scripts.
const NB_PARENT_TOOLS = 'Read Edit Write WebSearch WebFetch Bash(php bin/nb.php *) Bash(python3 scripts/yt_search.py *)';

function nb_parent_prompt(array $job, bool $newSession): string
{
    $intro = $newSession
        ? "You are the noize-buffet parent agent. First read CLAUDE.md in the current directory and follow it "
          . "for this whole conversation. Then read brief.md and taste.md if they exist.\n\n"
        : '';
    if ($job['kind'] === 'interview') {
        return $intro . 'The user just opened the web UI for the first time. Start the interview described in CLAUDE.md with your first question.';
    }
    $payload = json_decode((string)($job['payload'] ?? ''), true) ?: [];
    $message = trim((string)($payload['message'] ?? ''));
    return $intro . "Message from the user in the web UI chat:\n\n$message\n\nYour final reply is shown to them in the chat panel.";
}

function nb_parent_command(string $prompt, ?string $sessionId, array $config): array
{
    $cmd = [
        getenv('NB_CLAUDE_BIN') ?: 'claude', '-p', $prompt,
        '--model', (string)$config['parent_model'],
        '--output-format', 'json',
        '--permission-mode', 'acceptEdits',
        '--allowedTools', NB_PARENT_TOOLS,
    ];
    if ($sessionId !== null) {
        $cmd[] = '--resume';
        $cmd[] = $sessionId;
    }
    return $cmd;
}

/** Run a command (no shell) in $cwd with extra env vars; stderr is appended to data/parent-stderr.log. */
function nb_run(array $cmd, string $cwd, array $env): array
{
    $log = dirname(nb_db_path()) . '/parent-stderr.log';
    $spec = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $log, 'a']];
    $proc = proc_open($cmd, $spec, $pipes, $cwd, array_merge(getenv(), $env));
    if (!is_resource($proc)) {
        throw new RuntimeException('could not start ' . $cmd[0]);
    }
    $out = (string)stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    return [proc_close($proc), $out];
}

/** Run one job through the parent conversation and record the outcome. */
function nb_run_parent_job(PDO $pdo, array $job, array $config): void
{
    $jobId = (int)$job['id'];
    $sid = nb_setting($pdo, 'parent_session_id');
    $turns = (int)nb_setting($pdo, 'parent_turns', '0');
    if ($sid !== null && $turns >= (int)$config['session_rotate_turns']) {
        $sid = null; // rotate: start fresh; durable memory is the files + DB
    }
    $prompt = nb_parent_prompt($job, $sid === null);

    try {
        [$code, $out] = nb_run(nb_parent_command($prompt, $sid, $config), nb_root(), ['NB_JOB_ID' => (string)$jobId]);
        $res = json_decode($out, true);
        $ok = $code === 0 && is_array($res) && empty($res['is_error']);
        $error = $ok ? null : (is_array($res) ? (string)($res['result'] ?? "exit $code") : "exit $code: " . trim($out));
    } catch (Throwable $e) {
        $ok = false;
        $res = null;
        $error = $e->getMessage();
    }

    if (!$ok) {
        nb_job_finish($pdo, $jobId, false, null, $error, $sid);
        nb_chat_add($pdo, 'system', "The agent hit an error (job $jobId): $error — send your message again to retry.", $jobId);
        nb_setting_set($pdo, 'parent_session_id', null); // next job starts a fresh session from the files
        return;
    }

    $reply = trim((string)($res['result'] ?? ''));
    $newSid = (string)($res['session_id'] ?? '');
    nb_setting_set($pdo, 'parent_session_id', $newSid !== '' ? $newSid : null);
    nb_setting_set($pdo, 'parent_turns', (string)($sid === null ? 1 : $turns + 1));
    nb_chat_add($pdo, 'parent', $reply, $jobId);
    nb_job_finish($pdo, $jobId, true, $reply, null, $newSid);
}
