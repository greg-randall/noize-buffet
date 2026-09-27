<?php
declare(strict_types=1);
// scripts/check_confinement.php tells a confined child from a leaky one, and out-of-usage from either
// (with tests/fake_confinement_claude.php in place of claude; the real check needs the real one).
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/assert.php';

$fake = tmp_dir() . '/fake_confinement_claude';
file_put_contents($fake, "#!/usr/bin/env bash\nexec " . escapeshellarg(PHP_BINARY) . ' '
    . escapeshellarg(__DIR__ . '/fake_confinement_claude.php') . " \"\$@\"\n");
chmod($fake, 0755);
$check = function (array $env) use ($fake): array {
    $p = proc_open([PHP_BINARY, __DIR__ . '/../scripts/check_confinement.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes, null, ['NB_CLAUDE_BIN' => $fake, 'PATH' => getenv('PATH'), 'HOME' => tmp_dir() . '/fakehome'] + $env);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    return [proc_close($p), $out];
};

[$code, $out] = $check([]);
check($code === 0 && str_ends_with(trim($out), 'PASS'), 'a confined child passes' . ($code ? ":\n$out" : ''));
check(str_contains($out, "yes: mining_child_permission_mode '' or 'dontAsk'"), 'both permission modes are tried and named');
check(preg_match("/the folder's CLAUDE.md reaches the child:\\s+yes/", $out) && preg_match('/CLAUDE.local.md stays out:\\s+yes/', $out)
    && preg_match('/tools the child has:\\s+\\["Read","Write"\\]/', $out) && preg_match('/MCP servers the child has:\\s+none/', $out),
    'instructions, tools and MCP servers are checked');
check(str_contains($out, 'tiny budget: exit 1, subtype "error_max_budget_usd", is_error true'), 'the budget stop is shown as it came back');

[$code, $out] = $check(['FAKE_LEAKY' => '1']);
check($code === 1 && str_contains($out, 'FAIL:'), 'a leaky child fails');
foreach (['write another file in its folder:    ' => 'WORKED', 'write outside its folder' => 'WORKED',
    'read outside its folder' => 'WORKED', 'read a denied secret in its folder' => 'the .env deny rule does not hold',
    'CLAUDE.local.md stays out' => 'NO (it reached the child!)', 'AGENTS.md stays out' => 'NO',
    '.claude/rules/extra.md stays out' => 'NO',
    '../CLAUDE.md (parent folder) stays out' => 'NO', 'tools the child has' => 'MORE THAN Read and Write',
    'MCP servers the child has' => 'SOME'] as $what => $says) {
    $line = array_values(preg_grep('/' . preg_quote(trim($what, ' :'), '/') . '/', explode("\n", $out)))[0] ?? '';
    check(str_contains($line, $says), "leaky: $what is caught ($line)");
}
check((bool)preg_match('/at least one permission mode confines the child:\\s+NO/', $out), 'and neither permission mode is good enough');

[$code, $out] = $check(['FAKE_LIMIT' => '1']);
check($code === 2 && str_contains($out, 'INCONCLUSIVE: out of Claude usage'), 'out of usage: inconclusive, not a failure');

finish();
