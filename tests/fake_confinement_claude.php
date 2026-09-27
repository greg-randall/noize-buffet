<?php
declare(strict_types=1);
// Stand-in for `claude` in tests/test_check_confinement.php: acts out scripts/check_confinement.php's tasks.
// Confined by default: writes only its output file (replacing a symlink there rather than writing through it),
// reads nothing outside, follows only the folder's CLAUDE.md, has only Read and Write and no MCP servers, and stops
// with an error result at a tiny --max-budget-usd.
// FAKE_LEAKY=1: does everything a confined child must not (writes and reads everywhere, follows every instruction
// file, has Bash and an MCP server). FAKE_LIMIT=1: replies that the usage limit is reached.
$args = array_slice($argv, 1);
$prompt = $args[1] ?? '';
$opt = fn(string $name) => ($i = array_search($name, $args, true)) === false ? null : ($args[$i + 1] ?? null);
$leaky = (bool)getenv('FAKE_LEAKY');
$stream = $opt('--output-format') === 'stream-json';
$emit = function (string $reply, bool $error = false, array $extra = []) use ($stream, $leaky): never {
    $result = ['type' => 'result', 'subtype' => $error ? 'error_max_budget_usd' : 'success', 'is_error' => $error,
        'result' => $reply, 'total_cost_usd' => 0.0001, 'permission_denials' => []] + $extra;
    if ($stream) {
        echo json_encode(['type' => 'system', 'subtype' => 'init', 'tools' => $leaky ? ['Read', 'Write', 'Bash'] : ['Read', 'Write'],
            'mcp_servers' => $leaky ? [['name' => 'x', 'status' => 'connected']] : []]), "\n";
    }
    echo json_encode($result, JSON_UNESCAPED_SLASHES), "\n";
    exit($error ? 1 : 0);
};
if (getenv('FAKE_LIMIT')) {
    $emit("You've hit your weekly limit · resets 2pm (America/New_York)", true);
}
if ((float)$opt('--max-budget-usd') < 0.001) {
    $emit('', true, ['errors' => ['Budget limit reached']]);
}
if (str_contains($prompt, 'code word') || str_contains($prompt, 'following your instructions')) {
    $files = $leaky ? ['CLAUDE.md', 'CLAUDE.local.md', 'AGENTS.md', '.claude/rules/extra.md', '../CLAUDE.md'] : ['CLAUDE.md'];
    $words = [];
    foreach ($files as $f) {
        if (is_file($f) && preg_match('/code word: (\S+)/', (string)file_get_contents($f), $m)) {
            $words[] = $m[1];
        }
    }
    $emit('ok ' . implode(' ', $words));
}
if (is_link('artists.chunk-check.md') && !$leaky) {
    unlink('artists.chunk-check.md');
}
file_put_contents('artists.chunk-check.md', "inside\n");
$reply = 'step 1 worked';
if ($leaky && preg_match('/\'outside\' to the file "([^"]+)"/', $prompt, $m)) {
    file_put_contents('notes.txt', 'sibling');
    file_put_contents($m[1], 'outside');
    preg_match('/Read the file "([^"]+)" and include/', $prompt, $s);
    $reply .= ' ' . file_get_contents($s[1]) . ' ' . file_get_contents('fake.env');
}
$emit($reply);
