<?php
declare(strict_types=1);

/**
 * One long-lived `claude -p --input-format stream-json` process. Each message is one JSON line on stdin;
 * the reply is the stream of JSON events on stdout up to and including the `result` event.
 * Keeping the process alive saves the ~2-3s start-up cost on every chat message.
 */
final class NbParentProcess
{
    /** @var resource|null */
    private $proc = null;
    /** @var resource|null */
    private $stdin = null;
    /** @var resource|null */
    private $stdout = null;
    private string $buf = '';

    public ?array $command = null;
    public ?string $sessionId = null; // from the latest result event
    public ?float $lastTotalCost = null; // total_cost_usd from the latest result event (cumulative)
    public int $messages = 0; // messages answered by this process

    public function running(): bool
    {
        return $this->proc !== null && proc_get_status($this->proc)['running'];
    }

    public function pid(): ?int
    {
        return $this->proc !== null ? proc_get_status($this->proc)['pid'] : null;
    }

    /** Start the process in $cwd; stderr is appended to $stderrLog. */
    public function start(array $cmd, string $cwd, string $stderrLog): void
    {
        $this->stop();
        $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $stderrLog, 'a']];
        $proc = proc_open($cmd, $spec, $pipes, $cwd);
        if (!is_resource($proc)) {
            throw new RuntimeException('could not start ' . $cmd[0]);
        }
        $this->proc = $proc;
        [$this->stdin, $this->stdout] = [$pipes[0], $pipes[1]];
        stream_set_blocking($this->stdout, false);
        $this->command = $cmd;
        $this->buf = '';
        $this->sessionId = null;
        $this->lastTotalCost = null;
        $this->messages = 0;
    }

    /**
     * Send one user message and read events until its `result` (or until the process exits or $timeoutS passes).
     * $onEvent is called with each decoded event as it arrives.
     * Returns ['result' => ?array, 'events' => array, 'error' => ?string].
     */
    public function send(string $prompt, float $timeoutS, ?callable $onEvent = null): array
    {
        $events = [];
        $fail = function (string $error) use (&$events): array {
            return ['result' => null, 'events' => $events, 'error' => $error];
        };
        if (!$this->running()) {
            return $fail('the agent process is not running');
        }
        $line = json_encode(['type' => 'user', 'message' => ['role' => 'user', 'content' => $prompt]],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        if (@fwrite($this->stdin, $line) !== strlen($line) || !@fflush($this->stdin)) {
            return $fail('could not send the message to the agent process');
        }

        $deadline = microtime(true) + $timeoutS;
        while (true) {
            while (($nl = strpos($this->buf, "\n")) !== false) {
                $raw = substr($this->buf, 0, $nl);
                $this->buf = substr($this->buf, $nl + 1);
                if (trim($raw) === '') {
                    continue;
                }
                $event = json_decode($raw, true);
                $events[] = is_array($event) ? $event : ['unparsed' => $raw];
                if (!is_array($event)) {
                    continue;
                }
                if ($onEvent) {
                    $onEvent($event);
                }
                if (($event['type'] ?? '') === 'result') {
                    $this->messages++;
                    $this->sessionId = (string)($event['session_id'] ?? '') ?: $this->sessionId;
                    if (isset($event['total_cost_usd'])) {
                        $this->lastTotalCost = (float)$event['total_cost_usd'];
                    }
                    return ['result' => $event, 'events' => $events, 'error' => null];
                }
            }
            $left = $deadline - microtime(true);
            if ($left <= 0) {
                return $fail(sprintf('no reply from the agent after %d seconds', (int)$timeoutS));
            }
            $read = [$this->stdout];
            $write = $except = null;
            $ready = @stream_select($read, $write, $except, 0, (int)(min($left, 1.0) * 1e6));
            if ($ready === false) {
                return $fail('lost the connection to the agent process');
            }
            if ($ready === 0) {
                continue;
            }
            $chunk = fread($this->stdout, 65536);
            if (($chunk === '' || $chunk === false) && feof($this->stdout)) {
                return $fail('the agent process exited (exit code ' . $this->stop() . ')');
            }
            $this->buf .= (string)$chunk;
        }
    }

    /** Close stdin, give the process $graceS seconds to exit, then kill it. Returns its exit code (or null). */
    public function stop(float $graceS = 3.0): ?int
    {
        if ($this->proc === null) {
            return null;
        }
        @fclose($this->stdin);
        $until = microtime(true) + $graceS;
        while (($st = proc_get_status($this->proc))['running'] && microtime(true) < $until) {
            usleep(50000);
        }
        if ($st['running']) {
            proc_terminate($this->proc);
        }
        @fclose($this->stdout);
        $code = proc_close($this->proc);
        // proc_close can't see the code if proc_get_status already collected it.
        $code = !$st['running'] && $st['exitcode'] !== -1 ? $st['exitcode'] : $code;
        $this->proc = $this->stdin = $this->stdout = null;
        return $code;
    }

    public function __destruct()
    {
        $this->stop();
    }
}
