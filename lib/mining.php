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

/** Safely append a line to children.jsonl or mine.log only if the file is safe (doesn't exist or is a regular non-symlink file). */
function nb_append_jsonl(string $path, string $line): bool
{
    if (is_link($path) || (file_exists($path) && !is_file($path))) { // is_link first: file_exists() is false for a dangling link
        return false;
    }
    $result = file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX);
    return $result !== false;
}

/** Restore a protected file from a snapshot, verifying temp file location, write length, and file mode. */
function nb_restore_file(string $workDir, string $path, string $bytes, int $mode): bool
{
    $tempFile = tempnam($workDir, '.nbrestore');
    if ($tempFile === false) {
        return false;
    }

    // Verify temp file is in the correct directory
    $tempDir = realpath(rtrim($workDir, '/'));
    $actualTempDir = realpath(dirname($tempFile));
    if ($tempDir === false || $actualTempDir === false || $tempDir !== $actualTempDir) {
        @unlink($tempFile);
        return false;
    }

    // Write the bytes and verify write length
    $written = file_put_contents($tempFile, $bytes);
    if ($written !== strlen($bytes)) {
        @unlink($tempFile);
        return false;
    }

    // Restore file mode
    if (!chmod($tempFile, $mode)) {
        @unlink($tempFile);
        return false;
    }

    // Rename to target path
    if (!rename($tempFile, $path)) {
        @unlink($tempFile);
        return false;
    }

    return true;
}

/** Find the `timeout` command by scanning PATH (no shell). Skip relative entries. */
function nb_find_timeout(): ?string
{
    return nb_find_bin('timeout');
}

/**
 * The absolute path of program $name on PATH, or null. Relative PATH entries ("", ".", "bin") are skipped: they
 * would resolve against the working folder, and a child's folder is full of files it may have written.
 */
function nb_find_bin(string $name): ?string
{
    foreach (explode(PATH_SEPARATOR, getenv('PATH') ?: '') as $d) {
        if ($d !== '' && $d[0] === '/' && is_file("$d/$name") && is_executable("$d/$name")) {
            return "$d/$name";
        }
    }
    return null;
}

/** The python3 to run scripts with; NB_PYTHON_BIN lets tests stand in a fake (tests/fake_python.py). */
function nb_python_bin(): string
{
    return getenv('NB_PYTHON_BIN') ?: (nb_find_bin('python3') ?? 'python3');
}

/** The yt-dlp to run; NB_YTDLP_BIN lets tests stand in a fake (tests/fake_ytdlp.php). */
function nb_ytdlp_bin(): string
{
    return getenv('NB_YTDLP_BIN') ?: (nb_find_bin('yt-dlp') ?? 'yt-dlp');
}

/**
 * Run a command (no shell) in $cwd, appending its stdout and stderr to $logFile, with an optional time limit.
 * With a time limit, uses GNU `timeout` command to enforce it and a kill-after grace period; a command that still
 * runs after that is killed and reported as timed out.
 * Returns ['exit' => ?int, 'out' => stdout text, 'timed_out' => bool, 'duration_s' => float]; exit is null only when
 * timed_out is true. Without a time limit it waits for stdout to close, so a grandchild that keeps stdout open makes
 * it wait too (the pipeline always passes a limit).
 */
function nb_run_logged(array $cmd, string $cwd, string $logFile, ?float $timeoutS = null, float $killAfterS = 5.0): array
{
    // Validate positive limits
    if ($timeoutS !== null && $timeoutS <= 0) {
        throw new InvalidArgumentException("timeoutS must be positive, got $timeoutS");
    }
    if ($killAfterS <= 0) {
        throw new InvalidArgumentException("killAfterS must be positive, got $killAfterS");
    }

    // Check for symlink in log path (including dangling symlinks)
    if (is_link($logFile)) {
        throw new RuntimeException("log file path is a symlink: $logFile");
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

    // Poll proc_get_status() until process exits or deadline passes, to capture the exit code
    // from the FIRST status that shows running === false (before proc_close reaps it).
    $exit = null;
    $status = null;
    while (true) {
        $status = proc_get_status($proc);
        if (!$status['running']) {
            // Process has finished; extract exit code from status
            if ($status['signaled']) {
                $exit = 128 + $status['termsig'];
            } else {
                $exit = $status['exitcode'];
            }
            break;
        }
        if ($deadlineNs !== null && hrtime(true) >= $deadlineNs) {
            // Past the deadline; forcefully kill the process
            @proc_terminate($proc, 9); // SIGKILL
            // Wait a bit for it to die, bounded by remaining time until absolute deadline + grace
            $waitUntil = min($deadlineNs + (int)(2 * 1e9), hrtime(true) + (int)(10 * 1e9));
            while (hrtime(true) < $waitUntil) {
                $status = proc_get_status($proc);
                if (!$status['running']) {
                    if ($status['signaled']) {
                        $exit = 128 + $status['termsig'];
                    } else {
                        $exit = $status['exitcode'];
                    }
                    break 2; // Exit both loops
                }
                usleep(100000); // 0.1 seconds
            }
            // If still running after grace, it refused to die; mark as timed out
            if ($status['running'] && $exit === null) {
                $timedOut = true;
                $exit = null;
            } elseif ($exit === null) {
                if ($status['signaled']) {
                    $exit = 128 + $status['termsig'];
                } else {
                    $exit = $status['exitcode'];
                }
            }
            break;
        }
        usleep(10000); // 0.01 seconds
    }

    // Call proc_close() only to release the handle; do NOT use its return value for exit code
    proc_close($proc);

    $duration = (hrtime(true) - $tStart) / 1e9;
    $duration = round($duration, 1);

    // Map timeout exit codes to timed_out ONLY if elapsed >= timeoutS - 0.5
    $timeOutExitCodes = [124, 137, 143, 9];
    if (!$timedOut) { // Only if not already marked as timed out
        if ($timeoutS !== null && in_array($exit, $timeOutExitCodes, true) && $duration >= $timeoutS - 0.5) {
            $timedOut = true;
            $exit = null;
        }
    }

    fwrite($log, sprintf("[%s] exit %s after %gs%s\n", nb_now(), $exit ?? 'null', $duration, $timedOut ? ' (timed out, killed)' : ''));
    fclose($log);
    return ['exit' => $exit, 'out' => $out, 'timed_out' => $timedOut, 'duration_s' => $duration];
}

/** Command for one extraction child: headless, only Read and Write, seeing only its own output file, restricted MCP. */
function nb_child_command(string $chunk, string $workDir, array $config): array
{
    $realDir = realpath($workDir) ?: $workDir;
    $absPath = ltrim($realDir, '/') . '/artists.' . $chunk;

    return [
        getenv('NB_CLAUDE_BIN') ?: (nb_find_bin('claude') ?? 'claude'), '-p',
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
        // Otherwise every child saves a transcript (full of untrusted comment text) under ~/.claude/projects.
        '--no-session-persistence',
        ...nb_child_permission_mode($config),
    ];
}

/** --permission-mode for the child from mining_child_permission_mode: none, or dontAsk; nothing looser. */
function nb_child_permission_mode(array $config): array
{
    $mode = (string)($config['mining_child_permission_mode'] ?? '');
    if ($mode === '') {
        return [];
    }
    if ($mode !== 'dontAsk') {
        throw new InvalidArgumentException("mining_child_permission_mode must be '' or 'dontAsk', not '$mode'");
    }
    return ['--permission-mode', 'dontAsk'];
}

/**
 * Run one child on $chunk in $workDir. Appends a summary line to children.jsonl and returns it:
 * chunk, ok, error, duration_s, cost_usd, turns, denials (each "Tool: input"), tampered (array of paths).
 *
 * Before the run it refuses (a not-ok summary, no child started) when anything in the folder could mislead or be
 * abused: a stray instruction file, a log or protected file that is a symlink or not a regular file, an unreadable
 * protected file, or another child already running in the folder. Protected files (CLAUDE.md, the comment files,
 * every chunk and every other artists file) are snapshotted; after the run any change to them is undone and reported
 * as tampering, as is any change to other entries (at the top level and inside folders that were already there).
 * A file the child created is reported but left in place; the caller must stop on `tampered` (mine_video.php does).
 */
function nb_run_child(string $chunk, string $workDir, array $config): array
{
    if (!preg_match('/^chunk-[\w-]+\.md\z/', $chunk)) {
        throw new InvalidArgumentException("invalid chunk name: $chunk");
    }
    $mineLog = "$workDir/mine.log";
    $childrenJsonl = "$workDir/children.jsonl";
    $outputFile = "artists.$chunk";
    $outputPath = "$workDir/$outputFile";
    $refuse = fn(string $error): array => nb_child_summary($childrenJsonl, $chunk, false, $error);

    $timeout = (float)($config['mining_child_timeout_s'] ?? 0);
    if ($timeout <= 0) {
        return $refuse("invalid mining_child_timeout_s: $timeout");
    }
    foreach (nb_stray_instruction_names() as $name) {
        if (file_exists("$workDir/$name") || is_link("$workDir/$name")) {
            return $refuse("refusing to run: instruction file present: $name");
        }
    }
    foreach (['mine.log' => $mineLog, 'children.jsonl' => $childrenJsonl] as $name => $path) {
        if (is_link($path)) {
            return $refuse("refusing to run: $name is a symlink");
        }
        if (file_exists($path) && !is_file($path)) {
            return $refuse("refusing to run: $name is not a regular file");
        }
    }

    // Snapshot every protected file (null: it doesn't exist, and must not appear).
    $names = ['CLAUDE.md', 'comment_index.json', 'music_mentions_flagged.json'];
    foreach (is_dir($workDir) ? (@scandir($workDir) ?: []) : [] as $f) {
        if (preg_match('/^chunk-[\w-]+\.md\z/', $f) || (preg_match('/^artists\.chunk-[\w-]+\.md\z/', $f) && $f !== $outputFile)) {
            $names[] = $f;
        }
    }
    $snapshot = [];
    foreach ($names as $name) {
        $shot = nb_snapshot_file("$workDir/$name", $name);
        if (is_string($shot)) {
            return $refuse($shot);
        }
        $snapshot[$name] = $shot;
    }
    if (!nb_find_timeout()) {
        return $refuse('time limit requested but GNU coreutils `timeout` not found on PATH');
    }
    if (file_exists($outputPath) && !is_file($outputPath) && !is_link($outputPath)) {
        return $refuse("refusing to run: output path is not a regular file: $outputFile");
    }
    $lock = nb_child_folder_lock($workDir);
    if ($lock === null) {
        return $refuse('refusing to run: another child is running in this folder');
    }
    try {
        // Remove a stale output (a symlink itself, never its target), so an old file can't pass for this run's.
        if ((is_link($outputPath) || is_file($outputPath)) && !@unlink($outputPath)) {
            return $refuse("refusing to run: could not remove the old output: $outputFile");
        }
        $skip = array_merge(['mine.log', 'children.jsonl', $outputFile], array_keys($snapshot));
        $before = nb_scan_entries($workDir, $skip, null);
        try {
            $r = nb_run_logged(nb_child_command($chunk, $workDir, $config), $workDir, $mineLog, $timeout);
        } catch (Throwable $e) {
            return $refuse($e->getMessage());
        }
        clearstatcache();
        $res = json_decode(trim($r['out']), true);
        $wrote = is_file($outputPath) && !is_link($outputPath) && filesize($outputPath) > 0
            && dirname((string)realpath($outputPath)) === realpath($workDir);
        $ok = $r['exit'] === 0 && is_array($res) && ($res['type'] ?? null) === 'result' && empty($res['is_error']) && $wrote;
        $error = $ok ? null : nb_child_error($r, $res, $timeout, $outputPath, $outputFile, $workDir);

        // Tampering: protected files changed (put back), other entries created, changed or removed.
        $tampered = [];
        $restoreFailed = [];
        foreach ($snapshot as $name => $original) {
            $path = "$workDir/$name";
            if ($original === null) {
                if (is_file($path) && !is_link($path)) {
                    $tampered[] = $name;
                }
                continue;
            }
            if (!is_link($path) && is_file($path) && sha1_file($path) === $original['hash']) {
                continue;
            }
            $tampered[] = $name;
            if (!is_link($path) && is_dir($path)) {
                $restoreFailed[] = $name; // a folder can't be replaced safely
            } elseif (!nb_restore_file($workDir, $path, $original['bytes'], $original['mode'])) {
                $restoreFailed[] = $name;
            }
        }
        $after = nb_scan_entries($workDir, $skip, array_keys(array_filter($before, fn($e) => $e['type'] === 'dir')));
        foreach ($after as $rel => $entry) {
            if (!array_key_exists($rel, $before) || $entry !== $before[$rel]) {
                $tampered[] = $rel;
            }
        }
        foreach (array_diff_key($before, $after) as $rel => $_) {
            $tampered[] = $rel;
        }
        foreach (['mine.log' => $mineLog, 'children.jsonl' => $childrenJsonl, $outputFile => $outputPath] as $name => $path) {
            if (is_link($path)) {
                $tampered[] = $name;
            }
        }
        $tampered = array_values(array_unique($tampered));
        sort($tampered);
        if ($tampered) {
            $ok = false;
            $error = 'child changed files it must not touch: ' . implode(', ', $tampered)
                . ($restoreFailed ? '; could not restore ' . implode(', ', $restoreFailed) : '');
        }
        $denials = array_map(fn($d) => ($d['tool_name'] ?? '?') . ': ' . json_encode($d['tool_input'] ?? [], JSON_UNESCAPED_SLASHES),
            is_array($res['permission_denials'] ?? null) ? $res['permission_denials'] : []);
        return nb_child_summary($childrenJsonl, $chunk, $ok, $error, $r['duration_s'], $res['total_cost_usd'] ?? null,
            $res['num_turns'] ?? null, $denials, $tampered);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** A run's summary, appended to children.jsonl (unless that isn't a safe regular file) and returned. */
function nb_child_summary(string $childrenJsonl, string $chunk, bool $ok, ?string $error, float $durationS = 0.0,
    mixed $costUsd = null, mixed $turns = null, array $denials = [], array $tampered = []): array
{
    $summary = ['chunk' => $chunk, 'ok' => $ok, 'error' => $error, 'duration_s' => $durationS, 'cost_usd' => $costUsd,
        'turns' => $turns, 'denials' => $denials, 'tampered' => $tampered];
    nb_append_jsonl($childrenJsonl, json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
    return $summary;
}

/** Top-level names of the instruction files a child must never find in its folder (all but its own CLAUDE.md). */
function nb_stray_instruction_names(): array
{
    $names = array_map(fn($f) => explode('/', $f)[0], NB_INSTRUCTION_FILES);
    return array_values(array_unique(array_diff($names, ['CLAUDE.md'])));
}

/**
 * Snapshot one protected file: ['hash', 'bytes', 'mode'], null if it doesn't exist, or a string saying why the child
 * must not run (it's a symlink, not a regular file, or unreadable).
 */
function nb_snapshot_file(string $path, string $name): array|string|null
{
    if (is_link($path)) {
        return "refusing to run: protected file is a symlink: $name";
    }
    if (file_exists($path) && !is_file($path)) {
        return "refusing to run: protected path is not a regular file: $name";
    }
    if (!is_file($path)) {
        return null;
    }
    $bytes = is_readable($path) ? @file_get_contents($path) : false;
    if ($bytes === false) {
        return "refusing to run: protected file is unreadable: $name";
    }
    return ['hash' => sha1($bytes), 'bytes' => $bytes, 'mode' => fileperms($path) & 0777];
}

/**
 * Every entry in $dir except $skip, as relative path => [type, size, mtime] from lstat (a symlink is itself, never
 * followed; a retargeted link changes). Descends into the folders named in $descend (null: every real folder), so a
 * change inside a folder that was already there is seen; a new folder is one entry.
 */
function nb_scan_entries(string $dir, array $skip, ?array $descend, string $prefix = ''): array
{
    $out = [];
    foreach (is_dir($dir) ? (@scandir($dir) ?: []) : [] as $f) {
        $rel = $prefix . $f;
        if ($f === '.' || $f === '..' || ($prefix === '' && in_array($f, $skip, true))) {
            continue;
        }
        $st = @lstat("$dir/$f");
        if ($st === false) {
            continue;
        }
        $type = is_link("$dir/$f") ? 'link' : (is_dir("$dir/$f") ? 'dir' : 'file');
        $out[$rel] = ['type' => $type, 'size' => $type === 'dir' ? null : $st['size'], 'mtime' => $st['mtime']];
        if ($type === 'dir' && ($descend === null || in_array($rel, $descend, true))) {
            $out += nb_scan_entries("$dir/$f", $skip, $descend, "$rel/");
        }
    }
    return $out;
}

/** An exclusive lock on the child folder (held for one run), or null if another run holds it. */
function nb_child_folder_lock(string $workDir)
{
    $key = sha1((string)(realpath($workDir) ?: $workDir));
    $h = @fopen(sys_get_temp_dir() . "/nb-child-$key.lock", 'c');
    if ($h === false) {
        return null;
    }
    if (!flock($h, LOCK_EX | LOCK_NB)) {
        fclose($h);
        return null;
    }
    return $h;
}

/** Why a finished child isn't ok, in words: timed out, its own error result, an exit code, or no usable output. */
function nb_child_error(array $r, mixed $res, float $timeout, string $outputPath, string $outputFile, string $workDir): string
{
    if ($r['timed_out']) {
        return sprintf('timed out after %gs', $timeout);
    }
    if (!empty($res['is_error'])) { // an error result may have no "result": build it from subtype and errors
        $parts = is_string($res['subtype'] ?? null) && $res['subtype'] !== '' ? [$res['subtype']] : [];
        foreach (is_array($res['errors'] ?? null) ? $res['errors'] : [] as $err) {
            if (is_string($err) && $err !== '') {
                $parts[] = $err;
            }
        }
        if (is_string($res['result'] ?? null) && $res['result'] !== '') {
            $parts[] = $res['result'];
        }
        return $parts ? implode('; ', $parts) : 'the child reported an error';
    }
    if ($r['exit'] !== 0) {
        $said = is_array($res) && is_string($res['result'] ?? null) && $res['result'] !== '' ? ", {$res['result']}" : '';
        return "exit {$r['exit']}$said";
    }
    if (!is_array($res) || ($res['type'] ?? null) !== 'result') {
        return 'no JSON result';
    }
    if (is_link($outputPath)) {
        return "$outputFile written as a symlink";
    }
    if (!is_file($outputPath)) {
        $said = is_string($res['result'] ?? null) && trim($res['result']) !== ''
            ? '; the child said: ' . mb_strimwidth(trim($res['result']), 0, 300, '…') : ''; // e.g. out of usage
        return "no $outputFile written$said";
    }
    if (filesize($outputPath) === 0) {
        return "$outputFile is empty";
    }
    return dirname((string)realpath($outputPath)) !== realpath($workDir) ? "$outputFile is outside the folder" : "no $outputFile written";
}

/** Run mining/coverage.py on a video folder and return its result (throws if it fails). */
function nb_coverage(string $dir, bool $writeExtra, string $logFile, float $timeoutS = 120.0): array
{
    if ($timeoutS <= 0) {
        throw new InvalidArgumentException("timeoutS must be positive, got $timeoutS");
    }
    $python = nb_python_bin();
    $cmd = array_merge([$python, nb_root() . '/mining/coverage.py', $dir], $writeExtra ? ['--write-extra'] : []);
    $r = nb_run_logged($cmd, nb_root(), $logFile, $timeoutS);
    $cov = json_decode(trim($r['out']), true);
    if ($r['exit'] !== 0 || !is_array($cov)) {
        throw new RuntimeException("coverage.py failed (exit {$r['exit']}); see $logFile");
    }
    return $cov;
}

/** What to search YouTube for, for a lead: the artist with their most-named song, or the artist alone. */
function nb_lead_query(array $lead): string
{
    $song = (string)(array_key_first($lead['songs'] ?? []) ?? ''); // (string): PHP turns a key like "1999" into an int
    return trim($lead['name'] . ($song !== '' ? " $song" : ''));
}

/**
 * Prework for the next batch: search YouTube for the strongest leads (up to lead_lookup_max per call) that have no
 * saved results for their current query, skipping muted artists and artists already in the queue. Saves the results
 * and max_views: the most views among results whose title or channel has the artist's name (null if none do), the
 * number the agent's hint rule needs. Returns ['looked_up' => n, 'failed' => n, 'error' => ?string].
 */
function nb_lookup_leads(PDO $pdo, array $config, string $logFile): array
{
    $done = ['looked_up' => 0, 'failed' => 0, 'error' => null];
    $max = (int)($config['lead_lookup_max'] ?? 0);
    if ($max <= 0) {
        return $done;
    }
    $saved = nb_lead_youtube_all($pdo);
    $skip = array_flip(array_merge(
        array_map(fn($m) => nb_name_key((string)$m['value']), array_filter(nb_mutes($pdo), fn($m) => $m['kind'] === 'artist')),
        array_map(fn($s) => nb_name_key((string)$s['artist']), nb_queue($pdo))));
    $todo = []; // name key => [name, query]
    foreach (nb_leads($pdo) as $lead) { // strongest first
        $key = nb_name_key($lead['name']);
        $query = nb_lead_query($lead);
        if ($key === '' || isset($skip[$key]) || ($saved[$key]['query'] ?? null) === $query) {
            continue;
        }
        $todo[$key] = [$lead['name'], $query];
        if (count($todo) >= $max) {
            break;
        }
    }
    if (!$todo) {
        return $done;
    }
    $python = nb_python_bin();
    $queries = array_values(array_unique(array_column($todo, 1)));
    $r = nb_run_logged(array_merge([$python, nb_root() . '/scripts/yt_search.py'], $queries, ['-n', '5']),
        nb_root(), $logFile, 900.0);
    $out = json_decode(trim($r['out']), true);
    if (!is_array($out)) {
        return ['error' => 'yt_search.py gave no results' . ($r['timed_out'] ? ' (timed out)' : " (exit {$r['exit']})")] + $done;
    }
    if (count($queries) === 1) {
        $out = [$queries[0] => $out]; // one query: yt_search.py prints the list alone
    }
    foreach ($todo as $key => [$name, $query]) {
        $results = $out[$query] ?? null;
        if (!is_array($results) || !array_is_list($results)) { // {"error": ...}: tried again after the next video
            $done['failed']++;
            continue;
        }
        $views = array_filter(array_map(fn($v) => str_contains(nb_name_key($v['title'] . ' ' . $v['channel']), $key)
            ? $v['views'] : null, $results), 'is_int');
        nb_lead_youtube_save($pdo, (string)$key, $query, $results, $views ? max($views) : null);
        $done['looked_up']++;
    }
    return $done;
}
