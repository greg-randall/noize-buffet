<?php
declare(strict_types=1);

// Instruction files Claude Code auto-loads from a directory (relative to that directory).
const NB_INSTRUCTION_FILES = ['CLAUDE.md', 'CLAUDE.local.md', '.claude/CLAUDE.md', 'AGENTS.md', '.claude/AGENTS.md', '.claude/rules/**'];

/**
 * Settings (for `claude --settings`) that make a headless Claude see only the instruction files in $workDir.
 *
 * Excludes the user's ~/.claude/CLAUDE.md and ~/.claude/rules, plus every instruction file in the
 * directories above $workDir (so a child in comments/<id>/ doesn't inherit the parent's rulebook, and
 * the parent doesn't inherit anything above the repo). Hooks and auto memory are turned off.
 * Used for the parent (workDir = repo root) and for mining children (workDir = their own folder).
 */
function nb_isolation_settings(string $workDir, ?string $home = null): array
{
    $home = $home ?? (getenv('HOME') ?: '');
    $excludes = [];
    if ($home !== '') {
        $excludes[] = rtrim($home, '/') . '/.claude/CLAUDE.md';
        $excludes[] = rtrim($home, '/') . '/.claude/rules/**';
    }
    $dir = realpath($workDir) ?: $workDir;
    for ($d = dirname($dir); ; $d = dirname($d)) {
        foreach (NB_INSTRUCTION_FILES as $f) {
            $excludes[] = rtrim($d, '/') . '/' . $f;
        }
        if ($d === dirname($d)) {
            break; // reached the filesystem root
        }
    }
    return [
        'claudeMdExcludes' => array_values(array_unique($excludes)),
        'disableAllHooks' => true,
        'autoMemoryEnabled' => false,
    ];
}
