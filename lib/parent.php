<?php
declare(strict_types=1);
require_once __DIR__ . '/isolation.php';
require_once __DIR__ . '/parent_process.php';

// Tools the parent may use without asking. Bash is limited to the two helper scripts.
// Read, Edit and Write are deliberately NOT listed: a bare rule allows them on any path. Without one, reads in the
// repo need no approval and --permission-mode acceptEdits approves edits in the repo; anything outside it would
// need approval, which a headless run can't give, so it is refused.
const NB_PARENT_TOOLS = 'WebSearch WebFetch Bash(php bin/nb.php *) Bash(python3 scripts/yt_search.py *)';
// The only built-in tools the parent has at all (drops Task, Cron, Glob, etc.).
const NB_PARENT_BUILTIN_TOOLS = 'Read,Edit,Write,WebSearch,WebFetch,Bash';

function nb_parent_prompt(array $job, bool $newSession): string
{
    $intro = $newSession
        ? "You are the noize-buffet parent agent. First read CLAUDE.md in the current directory and follow it "
          . "for this whole conversation. Then read brief.md and taste.md if they exist.\n\n"
        : '';
    $sent = strtotime((string)($job['created_at'] ?? '')) ?: time();
    $intro .= sprintf("(Sent at %s, unix %d.)\n\n", gmdate('Y-m-d H:i', $sent) . ' UTC', $sent);
    if ($job['kind'] === 'interview') {
        return $intro . 'The user just opened the web UI for the first time. Start the interview described in CLAUDE.md with your first question.';
    }
    $payload = json_decode((string)($job['payload'] ?? ''), true) ?: [];
    $message = trim((string)($payload['message'] ?? ''));
    $song = $payload['song'] ?? null;
    $context = '';
    if (is_array($song)) {
        $bits = ["heard {$song['furthest_pct']}%", 'rating: ' . ($song['rating'] ?? 'none')];
        if ($song['off_brief']) {
            $bits[] = 'marked off-brief';
        }
        if ($song['new_to_me'] !== null) {
            $bits[] = $song['new_to_me'] ? 'new to them' : 'they already knew it';
        }
        $context = "(They are currently listening to: {$song['artist']} - {$song['title']} [video_id {$song['video_id']}], "
            . implode(', ', $bits) . ".)\n\n";
    }
    return $intro . "Message from the user in the web UI chat:\n\n$context$message\n\nYour final reply is shown to them in the chat panel.";
}

/** The command for a long-lived parent process that reads messages as JSON lines on stdin (see NbParentProcess). */
function nb_parent_command(?string $sessionId, array $config): array
{
    $cmd = [
        getenv('NB_CLAUDE_BIN') ?: 'claude', '-p',
        '--input-format', 'stream-json',
        '--output-format', 'stream-json', '--verbose', // stream-json output requires --verbose
        '--model', (string)$config['parent_model'],
        '--tools', NB_PARENT_BUILTIN_TOOLS,
        '--permission-mode', 'acceptEdits',
        '--allowedTools', NB_PARENT_TOOLS,
        // See only this repo's CLAUDE.md: skip the user's own instructions, hooks, auto memory, skills.
        '--settings', json_encode(nb_isolation_settings(nb_root()), JSON_UNESCAPED_SLASHES),
        '--disable-slash-commands',
    ];
    if ($sessionId !== null) {
        $cmd[] = '--resume';
        $cmd[] = $sessionId;
    }
    return $cmd;
}

/**
 * Where Claude Code saves a session's full transcript for this repo, if it exists.
 * Claude Code names the folder after the working directory with every non-alphanumeric character replaced by "-".
 */
function nb_transcript_path(?string $sessionId): ?string
{
    $home = getenv('HOME');
    if (!$sessionId || !$home) {
        return null;
    }
    $dir = preg_replace('/[^A-Za-z0-9]/', '-', (string)realpath(nb_root()));
    $path = "$home/.claude/projects/$dir/$sessionId.jsonl";
    return is_file($path) ? $path : null;
}

/** The interesting part of a tool call's input: the command, file, URL or query. */
function nb_describe_tool_input(array $input): string
{
    return (string)($input['command'] ?? $input['file_path'] ?? $input['url'] ?? $input['query'] ?? json_encode($input, JSON_UNESCAPED_SLASHES));
}

/** What the agent is doing, in words for the chat panel, from one tool call. Null means "don't change it". */
function nb_activity_text(string $tool, array $input): ?string
{
    $file = basename((string)($input['file_path'] ?? ''));
    switch ($tool) {
        case 'Bash':
            $cmd = trim((string)($input['command'] ?? ''));
            if (str_starts_with($cmd, 'python3 scripts/yt_search.py')) {
                $n = preg_match_all('/"[^"]*"|\'[^\']*\'/', $cmd);
                return 'Searching YouTube' . ($n > 1 ? " ($n songs)" : '');
            }
            if (preg_match('#^php bin/nb\.php\s+(\S+)#', $cmd, $m)) {
                $known = [
                    'feedback' => 'Reading your ratings and notes',
                    'queue' => 'Checking the queue',
                    'status' => 'Checking the queue',
                    'add-batch' => 'Adding songs to your queue',
                    'note' => 'Saving your note on this song',
                    'set' => "Updating the song's rating",
                    'mute' => 'Updating what to avoid',
                    'mutes' => 'Checking what to avoid',
                    'say' => null, // the message itself shows up in the chat
                ];
                return array_key_exists($m[1], $known) ? $known[$m[1]] : 'Working';
            }
            return 'Running a command';
        case 'WebSearch':
            return 'Searching the web: ' . ($input['query'] ?? '');
        case 'WebFetch':
            return 'Reading ' . (parse_url((string)($input['url'] ?? ''), PHP_URL_HOST) ?: 'a web page');
        case 'Read':
            return ['taste.md' => 'Reading your taste notes', 'brief.md' => 'Reading your brief',
                'CLAUDE.md' => 'Reading its instructions', 'config.json' => 'Checking the settings'][$file] ?? "Reading $file";
        case 'Write':
        case 'Edit':
            return ['taste.md' => 'Updating your taste notes', 'brief.md' => 'Writing down your brief',
                'pending-batch.json' => 'Putting the batch together'][$file] ?? "Writing $file";
    }
    return 'Working';
}

/** Save what the agent is doing now (see nb_activity_text) so the chat panel can show it; null clears it. */
function nb_set_activity(PDO $pdo, int $jobId, ?string $text): void
{
    nb_setting_set($pdo, 'agent_activity', $text === null ? null : json_encode(['job' => $jobId, 'text' => $text]));
}

/** One-line description of a denied tool call, e.g. `Bash: ls /`. */
function nb_describe_denial(array $d): string
{
    return ($d['tool_name'] ?? '?') . ': ' . nb_describe_tool_input($d['tool_input'] ?? []);
}

/** Write data/jobs/<id>.json with everything needed to debug this job. */
function nb_write_job_debug(array $info): string
{
    $dir = dirname(nb_db_path()) . '/jobs';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $path = "$dir/{$info['job']['id']}.json";
    file_put_contents($path, json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    return $path;
}

/**
 * Run one job through the parent conversation and record the outcome.
 * $parent is the long-lived claude process; it is started, reused or restarted here as needed.
 * $onEvent gets every stream event as it arrives (the worker uses it to log tool calls).
 * Returns a summary for the worker log: ok, turns, cost_usd, denials, debug_file, process, pid.
 */
function nb_run_parent_job(PDO $pdo, array $job, array $config, NbParentProcess $parent, ?callable $onEvent = null): array
{
    $jobId = (int)$job['id'];
    $sid = nb_setting($pdo, 'parent_session_id');
    $turns = (int)nb_setting($pdo, 'parent_turns', '0');
    if ($sid !== null && $turns >= (int)$config['session_rotate_turns']) {
        $sid = null; // rotate: start fresh; durable memory is the files + DB
    }
    // Reuse the running process only if it is still the saved session.
    if ($parent->running() && ($sid === null || $parent->sessionId !== $sid)) {
        $parent->stop();
    }
    $prompt = nb_parent_prompt($job, $sid === null);

    // Keep the chat panel's "what is the agent doing" line current, then pass the event on.
    nb_set_activity($pdo, $jobId, $job['kind'] === 'interview' ? 'Getting ready' : 'Reading your message');
    $events = function (array $event) use ($pdo, $jobId, $onEvent): void {
        foreach (($event['type'] ?? '') === 'assistant' ? $event['message']['content'] ?? [] : [] as $block) {
            $text = ($block['type'] ?? '') === 'tool_use' ? nb_activity_text((string)$block['name'], $block['input'] ?? []) : null;
            if ($text !== null) {
                nb_set_activity($pdo, $jobId, $text);
            }
        }
        if ($onEvent) {
            $onEvent($event);
        }
    };

    $started = microtime(true);
    $newProcess = false;
    $prevCost = 0.0;
    $pid = null;
    $r = ['result' => null, 'events' => [], 'error' => null];
    try {
        if (!$parent->running()) {
            $parent->start(nb_parent_command($sid, $config), nb_root(), dirname(nb_db_path()) . '/parent-stderr.log');
            $newProcess = true;
            // A resumed session reports the whole conversation's cost so far.
            $prevCost = $sid !== null ? (float)nb_setting($pdo, 'parent_session_cost', '0') : 0.0;
        } else {
            $prevCost = (float)$parent->lastTotalCost;
        }
        $pid = $parent->pid();
        $r = $parent->send($prompt, (float)$config['job_timeout_s'], $events);
    } catch (Throwable $e) {
        $r['error'] = $e->getMessage();
    }
    nb_set_activity($pdo, $jobId, null);
    $res = $r['result'];
    $ok = $res !== null && empty($res['is_error']);
    $error = $ok ? null : ($r['error'] ?? (string)($res['result'] ?? 'the agent reported an error'));
    if (!$ok) {
        $parent->stop(0.5); // it may be hung; the next job starts a fresh process
    }

    // total_cost_usd is cumulative for the session; this job's cost is the difference.
    $sessionCost = isset($res['total_cost_usd']) ? (float)$res['total_cost_usd'] : null;
    $jobCost = $sessionCost === null ? null : round(max(0.0, $sessionCost - $prevCost), 6);
    $denials = is_array($res['permission_denials'] ?? null) ? $res['permission_denials'] : [];
    $newSid = (string)($res['session_id'] ?? '');
    $debugFile = nb_write_job_debug([
        'job' => $job,
        'resumed_session' => $sid,
        'prompt' => $prompt,
        'process' => ['pid' => $pid, 'started_for_this_job' => $newProcess, 'command' => $parent->command],
        'duration_s' => round(microtime(true) - $started, 1),
        'ok' => $ok,
        'error' => $error,
        'num_turns' => $res['num_turns'] ?? null,
        'job_cost_usd' => $jobCost,
        'session_cost_usd' => $sessionCost,
        'permission_denials' => $denials,
        'transcript' => nb_transcript_path($newSid ?: $sid),
        'events' => $r['events'],
    ]);
    $summary = [
        'ok' => $ok,
        'turns' => $res['num_turns'] ?? null,
        'cost_usd' => $jobCost,
        'denials' => array_map('nb_describe_denial', $denials),
        'debug_file' => $debugFile,
        'process' => $newProcess ? 'started' : 'reused',
        'pid' => $pid,
    ];

    if ($summary['denials']) {
        nb_chat_add($pdo, 'system', "The agent was blocked from using a tool (job $jobId):\n- "
            . implode("\n- ", $summary['denials']) . "\nDetails: $debugFile", $jobId);
    }

    if (!$ok) {
        nb_job_finish($pdo, $jobId, false, null, $error, $sid);
        nb_chat_add($pdo, 'system', "The agent hit an error (job $jobId): $error — send your message again to retry. Details: $debugFile", $jobId);
        nb_setting_set($pdo, 'parent_session_id', null); // next job starts a fresh session from the files
        return $summary;
    }

    $reply = trim((string)($res['result'] ?? ''));
    nb_setting_set($pdo, 'parent_session_id', $newSid !== '' ? $newSid : null);
    nb_setting_set($pdo, 'parent_turns', (string)($sid === null ? 1 : $turns + 1));
    nb_setting_set($pdo, 'parent_session_cost', $sessionCost === null ? null : (string)$sessionCost);
    nb_chat_add($pdo, 'parent', $reply, $jobId);
    nb_job_finish($pdo, $jobId, true, $reply, null, $newSid);
    return $summary;
}
