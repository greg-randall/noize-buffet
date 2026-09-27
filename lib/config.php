<?php
declare(strict_types=1);

const NB_CONFIG_DEFAULTS = [
    'batch_size' => 12,
    'mix' => ['close' => 0.7, 'lead' => 0.2, 'wildcard' => 0.1],
    'parent_model' => 'sonnet',
    'session_rotate_turns' => 40,
    'job_timeout_s' => 600, // give up on a job (and restart the agent process) after this long
    'memory_picks' => 2, // most songs per batch the agent may pick from its own memory rather than research
    'refill_when_left' => 5, // queue a new batch automatically when this many unplayed songs are left; 0 = off
    'mining_workers' => 2, // videos mined at the same time
    'mining_comment_cap' => 3000, // top comments downloaded per video
    'mining_chunk_size' => 150, // flagged comments per extraction child
    'mining_child_model' => 'haiku',
    'mining_child_timeout_s' => 600, // kill an extraction child after this long
    'mining_child_max_budget_usd' => 1.0,
    // '' (no --permission-mode: headless Claude refuses what isn't allowed) or 'dontAsk' (refuse explicitly).
    // scripts/check_confinement.php tries both; set 'dontAsk' if it passes with it.
    'mining_child_permission_mode' => '',
    'typesafe_song_threshold' => 0.8,
    'typesafe_artist_threshold' => 0.8,
    // A comment naming an artist goes to the extraction child only if TypeSafe thinks it names someone other than
    // the video's own artist at this or more; low on purpose, since a wrong skip loses the lead for good.
    'typesafe_other_artist_threshold' => 0.3,
    'typesafe_injection_threshold' => 0.5, // quarantine comments that look like instructions to an AI
    'typesafe_spam_threshold' => 0.9, // skip spam and self-promotion
];

/** Defaults overlaid with config.json (if present). */
function nb_config(?string $file = null): array
{
    $file = $file ?? dirname(__DIR__) . '/config.json';
    if (!is_file($file)) {
        return NB_CONFIG_DEFAULTS;
    }
    $user = json_decode((string)file_get_contents($file), true);
    if (!is_array($user)) {
        throw new RuntimeException("$file is not valid JSON");
    }
    return array_replace_recursive(NB_CONFIG_DEFAULTS, $user);
}
