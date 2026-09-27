<?php
declare(strict_types=1);
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/assert.php';

$pdo = fresh_db('test_mine_video'); // exports NB_DB for the child processes
$comments = tmp_dir() . '/mine_comments';
exec('rm -rf ' . escapeshellarg($comments));
$argsFile = tmp_dir() . '/fake_mine_args.jsonl';
@unlink($argsFile);
$wrap = function (string $name, string $script): string {
    $path = tmp_dir() . "/$name";
    file_put_contents($path, "#!/usr/bin/env bash\nexec " . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . "/$script") . " \"\$@\"\n");
    chmod($path, 0755);
    return $path;
};
$fixture = tmp_dir() . '/mine_fixture_comments.json';
file_put_contents($fixture, json_encode([
    ['id' => 'a1', 'author' => '@ann', 'author_id' => 'UCann', 'like_count' => 50, 'text' => 'if you like this check out Burial'],
    ['id' => 'a2', 'author' => '@bob', 'author_id' => 'UCbob', 'like_count' => 5, 'text' => 'Burial - Archangel is the blueprint'],
    ['id' => 'a3', 'author' => '@cat', 'author_id' => 'UCcat', 'like_count' => 1, 'text' => 'sounds like Tomggg SKIPME'],
    ['id' => 'a4', 'author' => '@dan', 'author_id' => 'UCdan', 'like_count' => 0, 'text' => 'love this so much'],
]));
$emptyEnv = tmp_dir() . '/mine_empty.env';
file_put_contents($emptyEnv, "TYPESAFE_API=\n");
foreach (['NB_COMMENTS_DIR' => $comments, 'NB_ENV_FILE' => $emptyEnv, 'NB_FAKE_ARGS' => $argsFile,
    'NB_FAKE_COMMENTS' => $fixture, 'NB_YTDLP_BIN' => $wrap('fake_ytdlp', 'fake_ytdlp.php'),
    'NB_CLAUDE_BIN' => $wrap('fake_child_for_mine', 'fake_mining_child.php'), 'NB_MINING_CHUNK_SIZE' => '2'] as $k => $v) {
    putenv("$k=$v");
}
$mine = function (string $vid): array {
    $p = proc_open([PHP_BINARY, __DIR__ . '/../scripts/mine_video.php', $vid], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    return [proc_close($p), $out];
};
$row = fn(string $vid) => array_values(array_filter(nb_mining_list($pdo), fn($m) => $m['video_id'] === $vid))[0] ?? null;
$calls = fn() => array_map(fn($l) => json_decode($l, true), file($argsFile, FILE_IGNORE_NEW_LINES) ?: []);

echo "a full run with fakes\n";
nb_add_batch($pdo, [['video_id' => 'V0000000001', 'title' => 'Test Song', 'artist' => 'Test Artist', 'bucket' => 'user']], 'b', null);
[$code, $out] = $mine('V0000000001');
$r = $row('V0000000001');
check($code === 0 && $r['status'] === 'done' && $r['error'] === null, 'done, no error' . ($code ? " (exit $code: $out)" : ''));
check($r['filter'] === 'keyword' && str_contains($out, 'no TypeSafe key'), 'keyword filter without a key, with a warning');
check((int)$r['comments'] === 4 && (int)$r['flagged'] === 3 && (int)$r['covered'] === 3 && (int)$r['mentions'] === 3,
    '4 comments, 3 flagged, all 3 covered after the re-run, 3 mentions');
$yt = array_values(array_filter($calls(), fn($c) => in_array('--write-comments', $c['args'], true)))[0]['args'] ?? [];
check(in_array('--write-info-json', $yt, true) && in_array('--skip-download', $yt, true)
    && in_array('youtube:comment_sort=top;max_comments=3000', $yt, true), 'yt-dlp asked for the top 3,000 comments');
$children = array_values(array_filter($calls(), fn($c) => in_array('--tools', $c['args'], true)));
check(count($children) === 3 && str_contains($children[2]['args'][1], 'chunk-extra.md'), 'two chunks plus one re-run of the missed comment');
check(realpath($children[0]['cwd']) === realpath("$comments/V0000000001"), 'children run in the video folder');
check(file_get_contents("$comments/V0000000001/CLAUDE.md") === file_get_contents(__DIR__ . '/../mining/child_CLAUDE.md'), 'child instructions written into the folder');
$leads = array_column(nb_leads($pdo), null, 'name_key');
check(($leads['burial']['strength'] ?? '') === 'confirmed' && $leads['burial']['people'] === 2, 'Burial confirmed by 2 people');
check(($leads['tomggg']['strength'] ?? '') === 'hint', 'a single-person name is a hint');
check(substr_count($out, 'V0000000001:') >= 5, 'one progress line per step');

echo "a download failure\n";
putenv('NB_FAKE_YTDLP_FAIL=1');
nb_mining_enqueue($pdo, 'V0000000002');
[$code, $out] = $mine('V0000000002');
putenv('NB_FAKE_YTDLP_FAIL');
$r = $row('V0000000002');
check($code === 1 && $r['status'] === 'failed' && str_contains((string)$r['error'], 'Sign in to confirm your age'), "failed, with yt-dlp's error");

finish();
