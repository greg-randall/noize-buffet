<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
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
    // Check process environment first (non-empty TYPESAFE_API_KEY wins)
    $envKey = getenv('TYPESAFE_API_KEY');
    if ($envKey && strlen(trim($envKey)) > 0) {
        return true;
    }

    $file = nb_env_file();
    if (!is_file($file)) {
        return false;
    }

    $content = (string)file_get_contents($file);
    // Remove UTF-8 BOM if present
    if (str_starts_with($content, "\xEF\xBB\xBF")) {
        $content = substr($content, 3);
    }

    // Check for TYPESAFE_API or TYPESAFE_API_KEY with a non-empty, non-whitespace-only, non-quote-only value
    // [ \t]* rather than \s*: \s* can run past the line end and take the next line's text as the key
    if (!preg_match('/^[ \t]*TYPESAFE_API(_KEY)?[ \t]*=[ \t]*(.+?)[ \t]*$/m', $content, $m)) {
        return false;
    }

    $value = $m[2];
    // Strip surrounding quotes if both sides match
    if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
        (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
        $value = substr($value, 1, -1);
    }

    return strlen(trim($value)) > 0;
}

/**
 * Run a command (no shell) in $cwd, appending its stdout and stderr to $logFile, with an optional time limit.
 * With a time limit, uses GNU `timeout` command to enforce it and a kill-after grace period.
 * Returns ['exit' => ?int (null if killed), 'out' => stdout text, 'timed_out' => bool, 'duration_s' => float].
 */
function nb_run_logged(array $cmd, string $cwd, string $logFile, ?float $timeoutS = null, float $killAfterS = 5.0): array
{
    if ($timeoutS !== null) {
        // Check if `timeout` is available
        $result = @shell_exec('which timeout 2>&1');
        if (!$result || !str_contains($result, 'timeout')) {
            throw new RuntimeException("time limit requested but GNU coreutils `timeout` not found on PATH");
        }
        // Prepend GNU timeout command with kill-after period
        $cmd = ['timeout', '--kill-after=' . $killAfterS, (string)$timeoutS, ...$cmd];
    }

    $log = fopen($logFile, 'a');
    if ($log === false) {
        throw new RuntimeException("can't open $logFile");
    }
    fwrite($log, "\n[" . nb_now() . '] $ ' . implode(' ', array_map('escapeshellarg', array_map('strval', $cmd))) . "\n");
    $t = microtime(true);
    $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => $log], $pipes, $cwd);
    if (!is_resource($proc)) {
        fclose($log);
        throw new RuntimeException('could not start ' . $cmd[0]);
    }
    stream_set_blocking($pipes[1], false);
    $out = '';
    $timedOut = false;
    $deadline = $timeoutS !== null ? $t + $timeoutS + $killAfterS + 2 : null;
    while (true) {
        $read = [$pipes[1]];
        $write = $except = null;
        $sec = 1;
        $usec = 0;
        if ($deadline !== null) {
            $remaining = max(0.1, min(1, $deadline - microtime(true)));
            $sec = (int)$remaining;
            $usec = (int)(($remaining - $sec) * 1000000);
        }
        if (@stream_select($read, $write, $except, $sec, $usec) > 0) {
            $chunk = fread($pipes[1], 65536);
            if ($chunk !== false && $chunk !== '') {
                $out .= $chunk;
                fwrite($log, $chunk);
            }
        }
        if (feof($pipes[1])) {
            break;
        }
        if ($deadline !== null && microtime(true) >= $deadline) {
            break;
        }
    }
    fclose($pipes[1]);
    $exit = proc_close($proc);
    $duration = round(microtime(true) - $t, 1);

    // Map timeout exit codes to timed_out
    $timeOutExitCodes = [124, 137, 143, 9]; // SIGTERM, SIGKILL, PHP proc_close returns 9 on SIGKILL
    $timedOut = in_array($exit, $timeOutExitCodes, true);

    fwrite($log, sprintf("[%s] exit %s after %.1fs%s\n", nb_now(), $exit, $duration, $timedOut ? ' (timed out, killed)' : ''));
    fclose($log);
    return ['exit' => $timedOut ? null : $exit, 'out' => $out, 'timed_out' => $timedOut, 'duration_s' => $duration];
}

/** Command for one extraction child: headless, only Read and Write, seeing only its own output file, restricted MCP. */
function nb_child_command(string $chunk, string $workDir, array $config): array
{
    $realDir = realpath($workDir) ?: $workDir;
    // Remove leading slash for the //path format
    $absPath = ltrim($realDir, '/') . '/artists.' . $chunk;
    // The child may write only its own output file (Claude only consults Edit(path) rules)
    return [
        getenv('NB_CLAUDE_BIN') ?: 'claude', '-p',
        "Follow CLAUDE.md in this folder. Process $chunk and write artists.$chunk.",
        '--model', (string)$config['mining_child_model'],
        '--tools', 'Read,Write',
        '--restricted',
        '--strict-mcp-config',
        '--max-budget-usd', (string)$config['mining_child_max_budget_usd'],
        '--allowedTools', "Edit(//$absPath)",
        '--output-format', 'json',
        '--settings', json_encode(nb_isolation_settings($workDir), JSON_UNESCAPED_SLASHES),
        '--disable-slash-commands',
    ];
}

/**
 * Run one child on $chunk in $workDir. Appends a summary line to children.jsonl and returns it:
 * chunk, ok, error, duration_s, cost_usd, turns, denials (each "Tool: input"), tampered (array of paths).
 */
function nb_run_child(string $chunk, string $workDir, array $config): array
{
    // Validate chunk name
    if (!preg_match('/^chunk-[\w-]+\.md\z/', $chunk)) {
        throw new InvalidArgumentException("invalid chunk name: $chunk");
    }

    // Snapshot protected files before running
    $protectedFiles = ['CLAUDE.md', 'comment_index.json', 'music_mentions_flagged.json', $chunk];
    $otherArtistsPattern = '/^artists\.chunk-.*\.md$/';
    $snapshot = [];
    $tamperedir = false;

    foreach ($protectedFiles as $name) {
        $path = "$workDir/$name";
        if (is_file($path)) {
            $snapshot[$name] = file_get_contents($path);
        } else {
            $snapshot[$name] = null;
        }
    }

    // Get all existing artists.chunk-*.md files EXCEPT the one we're about to write
    $outputFile = "artists.$chunk";
    $otherArtistsFiles = [];
    if (is_dir($workDir)) {
        $files = @scandir($workDir);
        if ($files) {
            foreach ($files as $f) {
                if ($f !== '.' && $f !== '..' && preg_match($otherArtistsPattern, $f) && $f !== $outputFile) {
                    if (is_file("$workDir/$f")) {
                        $otherArtistsFiles[$f] = file_get_contents("$workDir/$f");
                    }
                }
            }
        }
    }

    // Snapshot all other artist files
    foreach ($otherArtistsFiles as $name => $content) {
        $snapshot[$name] = $content;
    }

    // Remove stale output file before the run
    $outputPath = "$workDir/$outputFile";
    @unlink($outputPath);

    // List all existing files/dirs before the run (excluding protected and old output)
    $filesBefore = [];
    if (is_dir($workDir)) {
        $files = @scandir($workDir);
        if ($files) {
            foreach ($files as $f) {
                if ($f !== '.' && $f !== '..' && $f !== 'mine.log' && $f !== 'children.jsonl' && $f !== $outputFile) {
                    $filesBefore[$f] = is_dir("$workDir/$f");
                }
            }
        }
    }

    $timeout = (float)$config['mining_child_timeout_s'];
    try {
        $r = nb_run_logged(nb_child_command($chunk, $workDir, $config), $workDir, "$workDir/mine.log", $timeout);
    } catch (Throwable $e) {
        $summary = ['chunk' => $chunk, 'ok' => false, 'error' => $e->getMessage(), 'duration_s' => 0.0,
            'cost_usd' => null, 'turns' => null, 'denials' => [], 'tampered' => []];
        file_put_contents("$workDir/children.jsonl", json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n", FILE_APPEND | LOCK_EX);
        return $summary;
    }

    $res = json_decode(trim($r['out']), true);
    $wrote = is_file($outputPath) && filesize($outputPath) > 0;
    $ok = $r['exit'] === 0 && is_array($res) && ($res['type'] ?? null) === 'result' && empty($res['is_error']) && $wrote;
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
        } elseif (($res['type'] ?? null) !== 'result') {
            $error = "no JSON result";
        } elseif (!is_file($outputPath)) {
            $error = "no artists.$chunk written";
        } else {
            $error = "no artists.$chunk written";
        }
    }

    // Check for tampering: detect protected files that were modified/removed or new files
    $tampered = [];
    foreach ($snapshot as $name => $originalContent) {
        $path = "$workDir/$name";
        if ($originalContent === null) {
            // File didn't exist before
            if (is_file($path)) {
                // It exists now - could be tampering (e.g., protected files created by child)
                if (preg_match('/^(CLAUDE\.md|comment_index\.json|music_mentions_flagged\.json|chunk-|artists\.chunk-)/', $name)) {
                    $tampered[] = $name;
                }
            }
        } else {
            // File existed before
            if (!is_file($path)) {
                // It was deleted - tampering
                $tampered[] = $name;
                // Restore it
                file_put_contents($path, $originalContent);
            } else {
                // Check if modified
                if (file_get_contents($path) !== $originalContent) {
                    $tampered[] = $name;
                    // Restore it
                    file_put_contents($path, $originalContent);
                }
            }
        }
    }

    // Check for new files/dirs (excluding runner's own files)
    if (is_dir($workDir)) {
        $files = @scandir($workDir);
        if ($files) {
            foreach ($files as $f) {
                if ($f !== '.' && $f !== '..' && $f !== 'mine.log' && $f !== 'children.jsonl' && $f !== $outputFile) {
                    if (!isset($filesBefore[$f])) {
                        // Any new file/dir created by the child is tampering
                        $tampered[] = $f;
                    }
                }
            }
        }
    }

    sort($tampered);

    if (!empty($tampered)) {
        $ok = false;
        $error = "child changed files it must not touch: " . implode(', ', $tampered);
    }

    $denials = array_map(fn($d) => ($d['tool_name'] ?? '?') . ': ' . json_encode($d['tool_input'] ?? [], JSON_UNESCAPED_SLASHES),
        is_array($res['permission_denials'] ?? null) ? $res['permission_denials'] : []);
    $summary = ['chunk' => $chunk, 'ok' => $ok, 'error' => $error, 'duration_s' => $r['duration_s'],
        'cost_usd' => $res['total_cost_usd'] ?? null, 'turns' => $res['num_turns'] ?? null, 'denials' => $denials, 'tampered' => $tampered];
    file_put_contents("$workDir/children.jsonl", json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n", FILE_APPEND | LOCK_EX);
    return $summary;
}

/** Run mining/coverage.py on a video folder and return its result (throws if it fails). */
function nb_coverage(string $dir, bool $writeExtra, string $logFile, float $timeoutS = 120.0): array
{
    $cmd = array_merge(['python3', nb_root() . '/mining/coverage.py', $dir], $writeExtra ? ['--write-extra'] : []);
    $r = nb_run_logged($cmd, nb_root(), $logFile, $timeoutS);
    $cov = json_decode(trim($r['out']), true);
    if ($r['exit'] !== 0 || !is_array($cov)) {
        throw new RuntimeException("coverage.py failed (exit {$r['exit']}); see $logFile");
    }
    return $cov;
}
