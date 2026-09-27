<?php
declare(strict_types=1);
// Stand-in for `yt-dlp --write-comments --write-info-json` in tests. Appends {"args", "cwd"} to NB_FAKE_ARGS and
// writes <id>.info.json at the -o template with the comments in NB_FAKE_COMMENTS (a JSON file).
// NB_FAKE_YTDLP_FAIL=1: print an error the way yt-dlp does and write nothing.
$args = array_slice($argv, 1);
file_put_contents((string)getenv('NB_FAKE_ARGS'), json_encode(['args' => $args, 'cwd' => getcwd()], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
if (getenv('NB_FAKE_YTDLP_FAIL')) {
    fwrite(STDERR, "ERROR: [youtube] XXXXXXXXXXX: Sign in to confirm your age\n");
    exit(1);
}
preg_match('/v=([\w-]{11})/', (string)end($args), $m);
$path = str_replace(['%(id)s', '%(ext)s'], [$m[1], 'info.json'], $args[array_search('-o', $args, true) + 1]);
file_put_contents($path, json_encode(['id' => $m[1], 'title' => 'Test Artist - Test Song', 'channel' => 'TestLabelVEVO',
    'uploader' => 'Test Artist - Topic',
    'comments' => json_decode((string)file_get_contents((string)getenv('NB_FAKE_COMMENTS')), true)]));
