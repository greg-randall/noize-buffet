<?php
declare(strict_types=1);
require_once __DIR__ . '/isolation.php';

/** Where mined videos' folders live (NB_COMMENTS_DIR overrides it for tests). */
function nb_comments_dir(): string
{
    return getenv('NB_COMMENTS_DIR') ?: nb_root() . '/comments';
}

/** The .env file holding the TypeSafe key (NB_ENV_FILE overrides it for tests). */
function nb_env_file(): string
{
    return getenv('NB_ENV_FILE') ?: nb_root() . '/.env';
}

/** True if the .env file has a TypeSafe key (TYPESAFE_API=... or TYPESAFE_API_KEY=...), as start.py checks. */
function nb_has_typesafe_key(): bool
{
    $file = nb_env_file();
    // [ \t]* rather than \s*: \s* can run past the line end and take the next line's text as the key
    return is_file($file) && (bool)preg_match('/^[ \t]*TYPESAFE_API(_KEY)?[ \t]*=[ \t]*\S+/m', (string)file_get_contents($file));
}

/**
 * Run a command (no shell) in $cwd, appending its stdout and stderr to $logFile, with an optional time limit.
 * Returns ['exit' => ?int (null if killed), 'out' => stdout text, 'timed_out' => bool, 'duration_s' => float].
 */
function nb_run_logged(array $cmd, string $cwd, string $logFile, ?float $timeoutS = null): array
{
    $log = fopen($logFile, 'a');
    if ($log === false) {
        throw new RuntimeException("can't open $logFile");
    }
    fwrite($log, "\n[" . nb_now() . '] $ ' . implode(' ', array_map('escapeshellarg', $cmd)) . "\n");
    $t = microtime(true);
    $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => $log], $pipes, $cwd);
    if (!is_resource($proc)) {
        fclose($log);
        throw new RuntimeException('could not start ' . $cmd[0]);
    }
    stream_set_blocking($pipes[1], false);
    $out = '';
    $timedOut = false;
    while (true) {
        $read = [$pipes[1]];
        $write = $except = null;
        if (@stream_select($read, $write, $except, 1) > 0) {
            $chunk = fread($pipes[1], 65536);
            if ($chunk !== false && $chunk !== '') {
                $out .= $chunk;
                fwrite($log, $chunk);
            }
        }
        if (feof($pipes[1])) {
            break;
        }
        if ($timeoutS !== null && microtime(true) - $t > $timeoutS) {
            $timedOut = true;
            proc_terminate($proc);
            break;
        }
    }
    fclose($pipes[1]);
    $exit = proc_close($proc);
    $duration = round(microtime(true) - $t, 1);
    fwrite($log, sprintf("[%s] exit %s after %.1fs%s\n", nb_now(), $exit, $duration, $timedOut ? ' (timed out, killed)' : ''));
    fclose($log);
    return ['exit' => $timedOut ? null : $exit, 'out' => $out, 'timed_out' => $timedOut, 'duration_s' => $duration];
}

/** Command for one extraction child: headless, only Read and Write, seeing only its own folder's CLAUDE.md. */
function nb_child_command(string $chunk, string $workDir, array $config): array
{
    return [
        getenv('NB_CLAUDE_BIN') ?: 'claude', '-p',
        "Follow CLAUDE.md in this folder. Process $chunk and write artists.$chunk.",
        '--model', (string)$config['mining_child_model'],
        '--tools', 'Read,Write',
        '--permission-mode', 'acceptEdits',
        '--output-format', 'json',
        '--settings', json_encode(nb_isolation_settings($workDir), JSON_UNESCAPED_SLASHES),
        '--disable-slash-commands',
    ];
}

/**
 * Run one child on $chunk in $workDir. Appends a summary line to children.jsonl and returns it:
 * chunk, ok, error, duration_s, cost_usd, turns, denials (each "Tool: input").
 */
function nb_run_child(string $chunk, string $workDir, array $config): array
{
    $timeout = (float)$config['mining_child_timeout_s'];
    $r = nb_run_logged(nb_child_command($chunk, $workDir, $config), $workDir, "$workDir/mine.log", $timeout);
    $res = json_decode(trim($r['out']), true);
    $wrote = is_file("$workDir/artists.$chunk");
    $ok = $r['exit'] === 0 && is_array($res) && empty($res['is_error']) && $wrote;
    $error = null;
    if (!$ok) {
        if ($r['timed_out']) {
            $error = sprintf('timed out after %ds', (int)$timeout);
        } elseif (!empty($res['is_error'])) {
            $error = (string)($res['result'] ?? 'the child reported an error');
        } elseif ($r['exit'] !== 0) {
            $error = "exit {$r['exit']}";
            if (is_array($res) && !empty($res['result'])) {
                $error .= ", {$res['result']}";
            }
        } elseif (!is_array($res)) {
            $error = "no JSON result";
        } else {
            $error = "no artists.$chunk written";
        }
    }
    $denials = array_map(fn($d) => ($d['tool_name'] ?? '?') . ': ' . json_encode($d['tool_input'] ?? [], JSON_UNESCAPED_SLASHES),
        is_array($res['permission_denials'] ?? null) ? $res['permission_denials'] : []);
    $summary = ['chunk' => $chunk, 'ok' => $ok, 'error' => $error, 'duration_s' => $r['duration_s'],
        'cost_usd' => $res['total_cost_usd'] ?? null, 'turns' => $res['num_turns'] ?? null, 'denials' => $denials];
    file_put_contents("$workDir/children.jsonl", json_encode($summary, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
    return $summary;
}

/** Run mining/coverage.py on a video folder and return its result (throws if it fails). */
function nb_coverage(string $dir, bool $writeExtra, string $logFile): array
{
    $cmd = array_merge(['python3', nb_root() . '/mining/coverage.py', $dir], $writeExtra ? ['--write-extra'] : []);
    $r = nb_run_logged($cmd, nb_root(), $logFile);
    $cov = json_decode(trim($r['out']), true);
    if ($r['exit'] !== 0 || !is_array($cov)) {
        throw new RuntimeException("coverage.py failed (exit {$r['exit']}); see $logFile");
    }
    return $cov;
}
