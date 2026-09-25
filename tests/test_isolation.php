<?php
declare(strict_types=1);
require __DIR__ . '/../lib/isolation.php';
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/assert.php';

$work = tmp_dir() . '/iso/child';
if (!is_dir($work)) {
    mkdir($work, 0777, true);
}
$real = realpath($work);
$s = nb_isolation_settings($work, '/home/someone');
$ex = $s['claudeMdExcludes'];

check(in_array('/home/someone/.claude/CLAUDE.md', $ex, true), "user's global CLAUDE.md excluded");
check(in_array('/home/someone/.claude/rules/**', $ex, true), "user's rules excluded");
check(in_array(dirname($real) . '/CLAUDE.md', $ex, true), 'parent folder CLAUDE.md excluded');
check(in_array(realpath(nb_root()) . '/CLAUDE.md', $ex, true), "repo CLAUDE.md excluded for a child below the repo");
check(in_array(realpath(nb_root()) . '/AGENTS.md', $ex, true) && in_array(dirname($real) . '/.claude/rules/**', $ex, true), 'AGENTS.md and rules above excluded');
check(in_array('/CLAUDE.md', $ex, true), 'walks up to the filesystem root');
check(!in_array($real . '/CLAUDE.md', $ex, true), "the work folder's own CLAUDE.md is kept");
check($s['disableAllHooks'] === true && $s['autoMemoryEnabled'] === false, 'hooks and auto memory off');
check(count($ex) === count(array_unique($ex)), 'no duplicate patterns');
$noHome = nb_isolation_settings($work, '');
check(!in_array('/.claude/CLAUDE.md', $noHome['claudeMdExcludes'], true) || in_array('/CLAUDE.md', $noHome['claudeMdExcludes'], true), 'works without HOME');

finish();
