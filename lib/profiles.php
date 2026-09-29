<?php
declare(strict_types=1);
// Stations (profiles): each has its own folder under profiles/ with its own database, brief.md, taste.md and
// handoff.md. The downloaded comments in comments/ and config.json are shared.
//
// The current station: nb_profile_use() (the web API, once per request), else the NB_PROFILE environment variable
// (the agent and the mining pipeline run with it set), else "main".

const NB_DEFAULT_PROFILE = 'main';
const NB_PROFILE_RE = '/^[a-z0-9][a-z0-9-]{0,39}$/';

/** Where the stations live (NB_PROFILES_DIR overrides it for tests). */
function nb_profiles_dir(): string
{
    return getenv('NB_PROFILES_DIR') ?: dirname(__DIR__) . '/profiles';
}

function nb_profile_valid(string $slug): bool
{
    return preg_match(NB_PROFILE_RE, $slug) === 1;
}

/** The current station's slug. With $set, makes that the current one (for the rest of this run or request). */
function nb_profile(?string $set = null): string
{
    static $current = null;
    if ($set !== null) {
        if (!nb_profile_valid($set)) {
            throw new InvalidArgumentException("not a station name: $set");
        }
        $current = $set;
    }
    if ($current !== null) {
        return $current;
    }
    $env = (string)getenv('NB_PROFILE');
    return nb_profile_valid($env) ? $env : NB_DEFAULT_PROFILE;
}

/** A station's folder (the current one if none is given). */
function nb_profile_dir(?string $slug = null): string
{
    return nb_profiles_dir() . '/' . ($slug ?? nb_profile());
}

/** The same folder as the agent sees it: relative to the repo when it is inside it. */
function nb_profile_dir_for_agent(?string $slug = null): string
{
    $dir = nb_profile_dir($slug);
    $root = dirname(__DIR__) . '/';
    return str_starts_with($dir, $root) ? substr($dir, strlen($root)) : $dir;
}

/** A station's display name ("Late night jazz"), from its profile.json; its slug if there is none. */
function nb_profile_name(string $slug): string
{
    $meta = json_decode((string)@file_get_contents(nb_profile_dir($slug) . '/profile.json'), true);
    $name = is_array($meta) ? trim((string)($meta['name'] ?? '')) : '';
    return $name !== '' ? $name : $slug;
}

/**
 * Every station, by slug: [['slug' => 'jazz', 'name' => 'Jazz'], ...]. Under NB_DB (tests) there is just the current one.
 */
function nb_profiles(): array
{
    if (getenv('NB_DB')) {
        return [['slug' => nb_profile(), 'name' => nb_profile_name(nb_profile())]];
    }
    $out = [];
    foreach (glob(nb_profiles_dir() . '/*/music.sqlite') ?: [] as $db) {
        $slug = basename(dirname($db));
        if (nb_profile_valid($slug)) {
            $out[] = ['slug' => $slug, 'name' => nb_profile_name($slug)];
        }
    }
    usort($out, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    return $out;
}

/** "Late night jazz!" -> "late-night-jazz". Empty if nothing usable is left. */
function nb_profile_slug(string $name): string
{
    $slug = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($name))), '-');
    return substr($slug, 0, 40);
}

/** Make a new station called $name; returns its slug. Throws InvalidArgumentException if that isn't possible. */
function nb_profile_create(string $name): string
{
    $name = trim($name);
    $slug = nb_profile_slug($name);
    if ($name === '' || mb_strlen($name) > 60 || !nb_profile_valid($slug)) {
        throw new InvalidArgumentException('give the station a name of up to 60 letters or digits');
    }
    if (is_dir(nb_profile_dir($slug))) {
        throw new InvalidArgumentException("there is already a station called \"" . nb_profile_name($slug) . '"');
    }
    mkdir(nb_profile_dir($slug), 0777, true);
    file_put_contents(nb_profile_dir($slug) . '/profile.json', json_encode(['name' => $name], JSON_UNESCAPED_UNICODE));
    nb_db(nb_profile_dir($slug) . '/music.sqlite'); // makes the database and its tables
    return $slug;
}

/** Old-style data (before stations): the database that migrate.sh moves into profiles/main. */
function nb_legacy_db_path(): string
{
    return dirname(nb_profiles_dir()) . '/data/music.sqlite';
}

/**
 * A fresh install has no stations: make "main". Not while old-style data is waiting to be moved in by migrate.sh,
 * or "main" would start empty next to it.
 */
function nb_profiles_ensure_default(): void
{
    if (getenv('NB_DB') || nb_profiles() !== [] || is_file(nb_legacy_db_path())) {
        return;
    }
    nb_profile_create('Main');
}
