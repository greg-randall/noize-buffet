<?php
declare(strict_types=1);
// Stand-in for a long-lived `claude -p --input-format stream-json` process in tests.
// On start it appends its arguments to NB_FAKE_ARGS (one JSON array per line, so the line count is the number of
// processes started). Each message it reads is appended to NB_FAKE_INPUTS; it then emits init, assistant and result
// events. Markers in the message change the reply:
//   FAKE_FAIL: error result "boom"   FAKE_DENY: a permission denial   FAKE_COST=<x>: session total becomes x
//   FAKE_EXIT: exit without a result   FAKE_HANG: go quiet for 5 seconds
$args = array_slice($argv, 1);
file_put_contents((string)getenv('NB_FAKE_ARGS'), json_encode($args, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
$resume = array_search('--resume', $args, true);
$sid = $resume !== false ? $args[$resume + 1] : 'sess-' . getmypid();
$total = 0.0;

function emit(array $event): void
{
    echo json_encode($event, JSON_UNESCAPED_SLASHES), "\n";
    flush();
}

while (($line = fgets(STDIN)) !== false) {
    $in = json_decode($line, true);
    $text = (string)($in['message']['content'] ?? '');
    file_put_contents((string)getenv('NB_FAKE_INPUTS'), json_encode($text) . "\n", FILE_APPEND);
    emit(['type' => 'system', 'subtype' => 'init', 'session_id' => $sid]);
    emit(['type' => 'assistant', 'session_id' => $sid, 'message' => ['content' => [
        ['type' => 'tool_use', 'name' => 'Bash', 'input' => ['command' => 'php bin/nb.php status']],
    ]]]);
    if (str_contains($text, 'FAKE_EXIT')) {
        exit(3);
    }
    if (str_contains($text, 'FAKE_HANG')) {
        sleep(5);
    }
    $total = preg_match('/FAKE_COST=([0-9.]+)/', $text, $m) ? (float)$m[1] : $total + 0.005;
    $result = ['type' => 'result', 'subtype' => 'success', 'is_error' => false, 'result' => 'hello from fake',
        'session_id' => $sid, 'num_turns' => 2, 'total_cost_usd' => $total, 'permission_denials' => []];
    if (str_contains($text, 'FAKE_FAIL')) {
        $result = ['is_error' => true, 'result' => 'boom'] + $result;
    }
    if (str_contains($text, 'FAKE_DENY')) {
        $result['permission_denials'] = [['tool_name' => 'Bash', 'tool_input' => ['command' => 'ls /']]];
    }
    emit($result);
}
