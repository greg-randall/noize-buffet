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
