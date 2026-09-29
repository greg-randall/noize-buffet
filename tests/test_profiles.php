<?php
declare(strict_types=1);
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/parent.php';
require __DIR__ . '/assert.php';

$dir = tmp_dir() . '/profiles_test';
exec('rm -rf ' . escapeshellarg($dir));
putenv("NB_PROFILES_DIR=$dir");
putenv('NB_DB'); // stations, not the single-database override the other tests use
putenv('NB_MEMORY_DIR');
putenv('NB_HANDOFF_FILE');
putenv('NB_PROFILE');

echo "names\n";
check(nb_profile_slug('Late night jazz!') === 'late-night-jazz' && nb_profile_slug('  Ambient ') === 'ambient'
    && nb_profile_slug('???') === '' && nb_profile_slug('Ünïcode') === 'n-code', 'a name becomes a folder-safe slug');
check(nb_profile_valid('jazz') && nb_profile_valid('a-1') && !nb_profile_valid('') && !nb_profile_valid('../x')
    && !nb_profile_valid('-x') && !nb_profile_valid('Jazz') && !nb_profile_valid(str_repeat('a', 41)), 'only safe slugs are accepted');

echo "a fresh install\n";

check(nb_profiles() === [] && nb_profile() === 'main', 'no stations yet; the default is main');
nb_profiles_ensure_default();
check(array_column(nb_profiles(), 'slug') === ['main'] && nb_profile_name('main') === 'Main', 'main is made on first use');
nb_profiles_ensure_default();
check(count(nb_profiles()) === 1, 'and not again');

echo "old data waiting for migrate.sh\n";
$emptyDir = tmp_dir() . '/profiles_empty';
exec('rm -rf ' . escapeshellarg($emptyDir) . ' ' . escapeshellarg(tmp_dir() . '/data'));
putenv("NB_PROFILES_DIR=$emptyDir/profiles");
mkdir("$emptyDir/data", 0777, true);
touch("$emptyDir/data/music.sqlite");
nb_profiles_ensure_default();
check(nb_profiles() === [] && nb_legacy_db_path() === "$emptyDir/data/music.sqlite", "no empty main is made next to un-migrated data");
putenv("NB_PROFILES_DIR=$dir");

echo "creating stations\n";
$slug = nb_profile_create('Late night jazz');
check($slug === 'late-night-jazz' && is_file("$dir/late-night-jazz/music.sqlite") && is_file("$dir/late-night-jazz/profile.json"),
    'a station gets a folder with its own database');
check(nb_profile_name($slug) === 'Late night jazz', 'and keeps its display name');
foreach (['', '   ', '???', 'late NIGHT jazz', str_repeat('x', 61)] as $bad) {
    try {
        nb_profile_create($bad);
        check(false, "refused: '$bad'");
    } catch (InvalidArgumentException $e) {
        check(true, "refused: '" . substr($bad, 0, 20) . "' ({$e->getMessage()})");
    }
}
check(array_column(nb_profiles(), 'name') === ['Late night jazz', 'Main'], 'stations are listed by name');

echo "each station has its own files and database\n";
nb_profile('main');
$mainDb = nb_db();
nb_add_batch($mainDb, [['video_id' => 'AAAAAAAAAAA', 'artist' => 'A', 'title' => 'T', 'bucket' => 'close']], 'b', null);
nb_profile($slug);
$jazzDb = nb_db();
check(nb_db_path() === "$dir/$slug/music.sqlite" && nb_queue($jazzDb) === [] && count(nb_queue($mainDb)) === 1,
    "a song in one station's database isn't in the other's");
check(nb_memory_files()['taste.md'] === "$dir/$slug/taste.md" && nb_memory_files()['handoff.md'] === "$dir/$slug/handoff.md",
    'notes are per station');
file_put_contents("$dir/$slug/taste.md", "jazz taste\n");
nb_file_snapshot($jazzDb, null);
nb_profile('main');
check(nb_file_history($mainDb, 'taste.md') === [] && count(nb_file_history($jazzDb, 'taste.md')) === 1, 'so is the notes history');

echo "the agent can't reach another station's folder\n";
nb_profile('main');
$deny = nb_parent_settings()['permissions']['deny'];
$jazzReal = realpath("$dir/late-night-jazz");
$mainReal = realpath("$dir/main");
check(in_array("Read(//" . ltrim($jazzReal, '/') . "/**)", $deny, true) && in_array("Edit(//" . ltrim($jazzReal, '/') . "/**)", $deny, true),
    "another station's folder is denied to the agent for reading and editing");
check(!in_array("Read(//" . ltrim($mainReal, '/') . "/**)", $deny, true), "its own folder is not");

echo "the current station\n";
putenv('NB_PROFILE=late-night-jazz');
check(nb_profile() === 'main', 'nb_profile_use beats the environment within a run');
putenv('NB_PROFILE');
try {
    nb_profile('../evil');
    check(false, 'a bad station name is refused');
} catch (InvalidArgumentException) {
    check(true, 'a bad station name is refused');
}
check(nb_profile_dir_for_agent('jazz') === 'tests/tmp/profiles_test/jazz', 'inside the repo, the agent gets a relative path');
putenv('NB_PROFILES_DIR=/somewhere/else');
check(nb_profile_dir_for_agent('jazz') === '/somewhere/else/jazz', 'outside the repo, the full path');
putenv("NB_PROFILES_DIR=$dir");

echo "one usage limit for all stations\n";
nb_setting_set($jazzDb, 'usage_paused_until', (string)(time() + 3600));
nb_setting_set($jazzDb, 'usage_pause_message', "You've hit your session limit · resets 3pm");
check(nb_usage_paused($mainDb) === null, 'a pause in one database starts out only there');
nb_usage_share([$mainDb, $jazzDb]);
check(nb_usage_paused($mainDb) !== null && str_contains(nb_usage_paused($mainDb)['message'], 'resets 3pm'), 'sharing copies it to the others');
nb_setting_set($mainDb, 'usage_paused_until', (string)(time() + 7200));
nb_usage_share([$mainDb, $jazzDb]);
check(nb_usage_paused($jazzDb)['until'] === nb_usage_paused($mainDb)['until'], 'the later reset wins');

finish();
