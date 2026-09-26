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
    if ($envKey !== false && strlen(trim($envKey)) > 0) {
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

    // Check every TYPESAFE_API(_KEY)?=value line; any non-empty value counts
    if (!preg_match_all("/^[ \t]*TYPESAFE_API(_KEY)?[ \t]*=[ \t]*([^\r\n]*?)(?:\r\n|\r|\n)?$/m", $content, $matches, PREG_SET_ORDER)) {
        return false;
    }

    foreach ($matches as $m) {
        $value = trim($m[2]);
        // Check if value is quoted
        $isQuoted = (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                    (str_starts_with($value, "'") && str_ends_with($value, "'"));

        // Strip comment if present ONLY for unquoted values
        if (!$isQuoted && preg_match('/^([^\#]*?)\s*#/', $value, $commentMatch)) {
            $value = trim($commentMatch[1]);
        }

        // Strip surrounding quotes if both sides match
        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            $value = substr($value, 1, -1);
        }

        if (strlen($value) > 0) {
            return true;
        }
    }
    return false;
}

/** Find the `timeout` command by scanning PATH (no shell). */
function nb_find_timeout(): ?string
{
    $path = getenv('PATH') ?: '';
    foreach (explode(PATH_SEPARATOR, $path) as $d) {
        if ($d !== '' && is_file("$d/timeout") && is_executable("$d/timeout")) {
            return "$d/timeout";
        }
    }
    return null;
}

/**
 * Run a command (no shell) in $cwd, appending its stdout and stderr to $logFile, with an optional time limit.
 * With a time limit, uses GNU `timeout` command to enforce it and a kill-after grace period.
 * Returns ['exit' => ?int (null if killed), 'out' => stdout text, 'timed_out' => bool, 'duration_s' => float].
 */
function nb_run_logged(array $cmd, string $cwd, string $logFile, ?float $timeoutS = null, float $killAfterS = 5.0): array
{
    // Validate positive limits
    if ($timeoutS !== null && $timeoutS <= 0) {
        throw new InvalidArgumentException("timeoutS must be positive, got $timeoutS");
    }
    if ($killAfterS < 0) {
        throw new InvalidArgumentException("killAfterS must be non-negative, got $killAfterS");
    }

    if ($timeoutS !== null) {
        $timeoutBin = nb_find_timeout();
        if (!$timeoutBin) {
            throw new RuntimeException("time limit requested but GNU coreutils `timeout` not found on PATH");
        }
        $cmd = [$timeoutBin, '--kill-after=' . $killAfterS, (string)$timeoutS, ...$cmd];
    }

    $log = fopen($logFile, 'a');
    if ($log === false) {
        throw new RuntimeException("can't open $logFile");
    }
    fwrite($log, "\n[" . nb_now() . '] $ ' . implode(' ', array_map('escapeshellarg', array_map('strval', $cmd))) . "\n");
    $tStart = hrtime(true);
    $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => $log], $pipes, $cwd);
    if (!is_resource($proc)) {
        fclose($log);
        throw new RuntimeException('could not start ' . $cmd[0]);
    }
    stream_set_blocking($pipes[1], false);
    $out = '';
    $timedOut = false;
    $deadlineNs = $timeoutS !== null ? $tStart + (int)($timeoutS * 1e9) + (int)($killAfterS * 1e9) + (int)(2 * 1e9) : null;
    while (true) {
        $read = [$pipes[1]];
        $write = $except = null;
        $sec = 1;
        $usec = 0;
        if ($deadlineNs !== null) {
            $remainingNs = max(100000000, min((int)(1e9), $deadlineNs - hrtime(true)));
            $sec = (int)($remainingNs / 1e9);
            $usec = (int)(($remainingNs % (int)1e9) / 1000);
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
        if ($deadlineNs !== null && hrtime(true) >= $deadlineNs) {
            break;
        }
    }
    fclose($pipes[1]);
    $exit = proc_close($proc);
    $duration = (hrtime(true) - $tStart) / 1e9;
    $duration = round($duration, 1);

    // Map timeout exit codes to timed_out ONLY if elapsed >= timeoutS - 0.5
    $timeOutExitCodes = [124, 137, 143, 9];
    $timedOut = false;
    if ($timeoutS !== null && in_array($exit, $timeOutExitCodes, true) && $duration >= $timeoutS - 0.5) {
        $timedOut = true;
        $exit = null;
    }

    fwrite($log, sprintf("[%s] exit %s after %.1fs%s\n", nb_now(), $exit ?? 'null', $duration, $timedOut ? ' (timed out, killed)' : ''));
    fclose($log);
    return ['exit' => $exit, 'out' => $out, 'timed_out' => $timedOut, 'duration_s' => $duration];
}

/** Command for one extraction child: headless, only Read and Write, seeing only its own output file, restricted MCP. */
function nb_child_command(string $chunk, string $workDir, array $config): array
{
    $realDir = realpath($workDir) ?: $workDir;
    $absPath = ltrim($realDir, '/') . '/artists.' . $chunk;

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

    // Validate timeout
    $timeout = (float)($config['mining_child_timeout_s'] ?? 0);
    if ($timeout <= 0) {
        $summary = ['chunk' => $chunk, 'ok' => false, 'error' => "invalid mining_child_timeout_s: $timeout",
            'duration_s' => 0.0, 'cost_usd' => null, 'turns' => null, 'denials' => [], 'tampered' => []];
        file_put_contents("$workDir/children.jsonl", json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n", FILE_APPEND | LOCK_EX);
        return $summary;
    }

    // Snapshot protected files with sha1 hashes
    $protectedFiles = ['CLAUDE.md', 'comment_index.json', 'music_mentions_flagged.json'];
    $chunkPattern = '/^chunk-[\w-]+\.md\z/';
    $artistPattern = '/^artists\.chunk-[\w-]+\.md\z/';
    $snapshot = []; // name => ['hash' => sha1, 'bytes' => contents] or null
    $outputFile = "artists.$chunk";

    // Check for symlinks or unreadable files in protected files, and snapshot them
    foreach ($protectedFiles as $name) {
        $path = "$workDir/$name";
        if (is_link($path)) {
            $summary = ['chunk' => $chunk, 'ok' => false, 'error' => "refusing to run: protected file is a symlink: $name",
                'duration_s' => 0.0, 'cost_usd' => null, 'turns' => null, 'denials' => [], 'tampered' => []];
            file_put_contents("$workDir/children.jsonl", json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n", FILE_APPEND | LOCK_EX);
            return $summary;
        }
        if (is_file($path)) {
            if (!is_readable($path)) {
                $summary = ['chunk' => $chunk, 'ok' => false, 'error' => "refusing to run: protected file is unreadable: $name",
                    'duration_s' => 0.0, 'cost_usd' => null, 'turns' => null, 'denials' => [], 'tampered' => []];
                file_put_contents("$workDir/children.jsonl", json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n", FILE_APPEND | LOCK_EX);
                return $summary;
            }
            $bytes = file_get_contents($path);
            $snapshot[$name] = ['hash' => sha1_file($path), 'bytes' => $bytes];
        } else {
            $snapshot[$name] = null;
        }
    }

    // Snapshot all chunk-*.md files
    if (is_dir($workDir)) {
        $files = @scandir($workDir);
        if ($files) {
            foreach ($files as $f) {
                if (preg_match($chunkPattern, $f)) {
                    $path = "$workDir/$f";
                    if (is_link($path)) {
                        $summary = ['chunk' => $chunk, 'ok' => false, 'error' => "refusing to run: protected file is a symlink: $f",
                            'duration_s' => 0.0, 'cost_usd' => null, 'turns' => null, 'denials' => [], 'tampered' => []];
                        file_put_contents("$workDir/children.jsonl", json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n", FILE_APPEND | LOCK_EX);
                        return $summary;
                    }
                    if (is_file($path)) {
                        if (!is_readable($path)) {
                            $summary = ['chunk' => $chunk, 'ok' => false, 'error' => "refusing to run: protected file is unreadable: $f",
                                'duration_s' => 0.0, 'cost_usd' => null, 'turns' => null, 'denials' => [], 'tampered' => []];
                            file_put_contents("$workDir/children.jsonl", json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n", FILE_APPEND | LOCK_EX);
                            return $summary;
                        }
                        $snapshot[$f] = ['hash' => sha1_file($path), 'bytes' => file_get_contents($path)];
                    } else {
                        $snapshot[$f] = null;
                    }
                }
            }
        }
    }

    // Snapshot all artists.chunk-*.md files except this run's output
    if (is_dir($workDir)) {
        $files = @scandir($workDir);
        if ($files) {
            foreach ($files as $f) {
                if (preg_match($artistPattern, $f) && $f !== $outputFile) {
                    $path = "$workDir/$f";
                    if (is_link($path)) {
                        // Symlink in protected files is not allowed - refuse to run
                        $summary = ['chunk' => $chunk, 'ok' => false, 'error' => "refusing to run: protected file is a symlink: $f",
                            'duration_s' => 0.0, 'cost_usd' => null, 'turns' => null, 'denials' => [], 'tampered' => []];
                        file_put_contents("$workDir/children.jsonl", json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n", FILE_APPEND | LOCK_EX);
                        return $summary;
                    } elseif (is_file($path)) {
                        $snapshot[$f] = ['hash' => sha1_file($path), 'bytes' => file_get_contents($path)];
                    } else {
                        $snapshot[$f] = null;
                    }
                }
            }
        }
    }

    // Remove stale output file (the link itself, not its target)
    $outputPath = "$workDir/$outputFile";
    if (is_link($outputPath)) {
        @unlink($outputPath);
    } elseif (is_file($outputPath)) {
        @unlink($outputPath);
    }

    // List all existing files/dirs before the run (excluding protected and runner's files)
    $filesBefore = [];
    if (is_dir($workDir)) {
        $files = @scandir($workDir);
        if ($files) {
            foreach ($files as $f) {
                if ($f !== '.' && $f !== '..' && $f !== 'mine.log' && $f !== 'children.jsonl' && $f !== $outputFile &&
                    !isset($snapshot[$f])) {
                    $filesBefore[$f] = true;
                }
            }
        }
    }

    try {
        $r = nb_run_logged(nb_child_command($chunk, $workDir, $config), $workDir, "$workDir/mine.log", $timeout);
    } catch (Throwable $e) {
        $summary = ['chunk' => $chunk, 'ok' => false, 'error' => $e->getMessage(), 'duration_s' => 0.0,
            'cost_usd' => null, 'turns' => null, 'denials' => [], 'tampered' => []];
        file_put_contents("$workDir/children.jsonl", json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n", FILE_APPEND | LOCK_EX);
        return $summary;
    }

    $res = json_decode(trim($r['out']), true);
    $wrote = is_file($outputPath) && !is_link($outputPath) && filesize($outputPath) > 0 &&
        dirname(realpath($outputPath)) === realpath($workDir);
    $ok = $r['exit'] === 0 && is_array($res) && ($res['type'] ?? null) === 'result' && empty($res['is_error']) && $wrote;
    $error = null;
    if (!$ok) {
        if ($r['timed_out']) {
            $error = sprintf('timed out after %ds', (int)$timeout);
        } elseif (!empty($res['is_error'])) {
            // Build error from subtype and errors list
            $errorParts = [];
            if (!empty($res['subtype'])) {
                $errorParts[] = $res['subtype'];
            }
            if (is_array($res['errors'] ?? null)) {
                $errorParts = array_merge($errorParts, $res['errors']);
            }
            if (!empty($errorParts)) {
                $error = implode('; ', $errorParts);
            } elseif (is_string($res['result'] ?? null) && !empty($res['result'])) {
                $error = $res['result'];
            } else {
                $error = 'the child reported an error';
            }
        } elseif ($r['exit'] !== 0) {
            $error = "exit {$r['exit']}";
            if (is_array($res) && is_string($res['result'] ?? null) && !empty($res['result'])) {
                $error .= ", {$res['result']}";
            }
        } elseif (!is_array($res)) {
            $error = "no JSON result";
        } elseif (($res['type'] ?? null) !== 'result') {
            $error = "no JSON result";
        } elseif (is_link($outputPath)) {
            $error = "artists.$chunk written as a symlink";
        } elseif (!is_file($outputPath)) {
            $error = "no artists.$chunk written";
        } elseif (filesize($outputPath) === 0) {
            $error = "artists.$chunk is empty";
        } elseif (dirname(realpath($outputPath)) !== realpath($workDir)) {
            $error = "artists.$chunk is outside the folder";
        } else {
            $error = "no artists.$chunk written";
        }
    }

    // Check for tampering
    $tampered = [];
    $restoreFailed = [];
    foreach ($snapshot as $name => $original) {
        $path = "$workDir/$name";
        if ($original === null) {
            // File didn't exist before
            if (is_file($path) && !is_link($path)) {
                // It exists now - it's tampering
                $tampered[] = $name;
            }
        } else {
            // File existed before
            if (is_link($path)) {
                // Now it's a symlink - tampering
                $tampered[] = $name;
                unlink($path);
                file_put_contents($path, $original['bytes']);
            } elseif (!is_file($path)) {
                // It was deleted or is something else - tampering
                $tampered[] = $name;
                if (is_dir($path)) {
                    // Can't restore a directory - remember this
                    $restoreFailed[] = $name;
                } else {
                    file_put_contents($path, $original['bytes']);
                }
            } elseif (is_dir($path)) {
                // Now it's a directory - tampering
                $tampered[] = $name;
                $restoreFailed[] = $name;
            } elseif (sha1_file($path) !== $original['hash']) {
                // File modified - tampering
                $tampered[] = $name;
                file_put_contents($path, $original['bytes']);
            }
        }
    }

    // Check for new files/dirs
    if (is_dir($workDir)) {
        $files = @scandir($workDir);
        if ($files) {
            foreach ($files as $f) {
                if ($f !== '.' && $f !== '..' && $f !== 'mine.log' && $f !== 'children.jsonl' && $f !== $outputFile &&
                    !isset($snapshot[$f]) && !isset($filesBefore[$f])) {
                    $tampered[] = $f;
                }
            }
        }
    }

    // Check if output is a symlink (which is tampering)
    if (is_link($outputPath)) {
        if (!in_array($outputFile, $tampered, true)) {
            $tampered[] = $outputFile;
        }
    }

    $tampered = array_unique($tampered);
    sort($tampered);

    if (!empty($tampered)) {
        $ok = false;
        $baseError = "child changed files it must not touch: " . implode(', ', $tampered);
        if (!empty($restoreFailed)) {
            $baseError .= "; could not restore " . implode(', ', $restoreFailed);
        }
        $error = $baseError;
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
    if ($timeoutS <= 0) {
        throw new InvalidArgumentException("timeoutS must be positive, got $timeoutS");
    }
    $cmd = array_merge(['python3', nb_root() . '/mining/coverage.py', $dir], $writeExtra ? ['--write-extra'] : []);
    $r = nb_run_logged($cmd, nb_root(), $logFile, $timeoutS);
    $cov = json_decode(trim($r['out']), true);
    if ($r['exit'] !== 0 || !is_array($cov)) {
        throw new RuntimeException("coverage.py failed (exit {$r['exit']}); see $logFile");
    }
    return $cov;
}
