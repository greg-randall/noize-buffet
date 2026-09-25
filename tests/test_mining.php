<?php
declare(strict_types=1);
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/assert.php';

$pdo = fresh_db('test_mining');
$song = fn(string $id, string $bucket = 'close') => ['video_id' => $id, 'title' => "Song $id", 'artist' => "Artist $id", 'bucket' => $bucket];
$ids = fn() => array_column(nb_mining_list($pdo), 'video_id');
$row = fn(string $vid) => array_values(array_filter(nb_mining_list($pdo), fn($m) => $m['video_id'] === $vid))[0] ?? null;

echo "queueing\n";
nb_add_batch($pdo, [$song('M0000000001'), $song('M0000000002', 'user')], 'b', null);
check($ids() === ['M0000000002'], 'a song the user named is queued for mining; other buckets are not');
nb_save_listen($pdo, ['video_id' => 'M0000000001', 'rating' => 'ok']);
check(!in_array('M0000000001', $ids(), true), 'an ok rating does not queue mining');
nb_save_listen($pdo, ['video_id' => 'M0000000001', 'rating' => 'yes']);
check(in_array('M0000000001', $ids(), true), 'a yes rating queues mining');
check(nb_mining_enqueue($pdo, 'M0000000001') === false && count($ids()) === 2, 'queued at most once per video');
check($row('M0000000002')['artist'] === 'Artist M0000000002' && $row('M0000000002')['status'] === 'queued', 'list joins the song');
nb_add_batch($pdo, [$song('M0000000005')], 'b1b', null);
nb_save_listen($pdo, ['video_id' => 'M0000000005', 'rating' => 'top']);
check(in_array('M0000000005', $ids(), true), 'a top rating queues mining');

echo "backfill\n";
nb_add_batch($pdo, [$song('M0000000003')], 'b2', null);
$pdo->exec("INSERT INTO listens (video_id, furthest_pct, rating) VALUES ('M0000000003', 50, 'top')"); // rated before mining existed
check(nb_mining_backfill($pdo) === 1 && in_array('M0000000003', $ids(), true), 'backfill queues songs rated top/yes earlier');
check(nb_mining_backfill($pdo) === 0, 'backfill queues nothing twice');
// added straight to songs, bypassing nb_add_batch's own enqueue hook, so only backfill can pick it up
$pdo->exec("INSERT INTO songs (video_id, title, bucket, added_at) VALUES ('M0000000006', 'Song 6', 'user', '2024-01-01T00:00:00Z')");
check(nb_mining_backfill($pdo) === 1 && in_array('M0000000006', $ids(), true), 'backfill queues a user-bucket song added directly to songs');

echo "taking and updating\n";
$pdo->exec("UPDATE mining SET queued_at = '2001-01-01T00:00:00Z' WHERE video_id = 'M0000000002'");
$next = nb_mining_next($pdo);
check($next['video_id'] === 'M0000000002' && $next['status'] === 'downloading', 'next takes the oldest and marks it downloading');
check($row('M0000000002')['status'] === 'downloading', 'the status is saved');
nb_mining_update($pdo, 'M0000000002', ['status' => 'extracting', 'comments' => 120, 'flagged' => 15, 'filter' => 'keyword']);
$r = $row('M0000000002');
check($r['status'] === 'extracting' && (int)$r['comments'] === 120 && $r['filter'] === 'keyword', 'update sets fields');
$threw = false;
try { nb_mining_update($pdo, 'M0000000002', ['bogus' => 1]); } catch (InvalidArgumentException) { $threw = true; }
check($threw, 'unknown field rejected');
$threw = false;
try { nb_mining_update($pdo, 'M0000000002', ['status' => 'exploded']); } catch (InvalidArgumentException) { $threw = true; }
check($threw, 'unknown status rejected');
$threw = false;
try { nb_mining_update($pdo, 'M0000000002', []); } catch (InvalidArgumentException $e) { $threw = $e->getMessage() === 'nothing to update'; }
check($threw, 'empty update rejected');
$threw = false;
try { nb_mining_update($pdo, 'M9999999999', ['status' => 'queued']); } catch (RuntimeException $e) { $threw = str_contains($e->getMessage(), 'M9999999999'); }
check($threw, 'update on a video with no mining row throws');
check(nb_mining_recover_interrupted($pdo) === 1 && $row('M0000000002')['status'] === 'queued', 'an interrupted video goes back to the queue');
check(nb_mining_next(fresh_db('test_mining_empty')) === null, 'next returns null on an empty queue');

echo "recover interrupted leaves done/failed alone\n";
nb_mining_update($pdo, 'M0000000001', ['status' => 'done']);
nb_mining_update($pdo, 'M0000000003', ['status' => 'failed', 'error' => 'boom']);
nb_mining_update($pdo, 'M0000000002', ['status' => 'filtering']); // simulate an interrupted run
check(nb_mining_recover_interrupted($pdo) === 1
    && $row('M0000000002')['status'] === 'queued'
    && $row('M0000000001')['status'] === 'done'
    && $row('M0000000003')['status'] === 'failed', 'recover resets filtering/extracting but leaves done/failed alone');

echo "leads\n";
$lead = fn(string $name, string $strength, int $people, int $likes, int $videos = 1) => [
    'name_key' => nb_name_key($name), 'name' => $name, 'strength' => $strength, 'people' => $people, 'videos' => $videos,
    'mentions' => $people, 'likes' => $likes, 'unsure_only' => false, 'songs' => ['Archangel' => 1],
    'video_ids' => ['M0000000002'], 'examples' => [['author' => '@a', 'text' => "$name vibes"]]];
nb_leads_replace($pdo, [$lead('Foo', 'hint', 1, 900), $lead('Burial', 'confirmed', 2, 80)]);
$l = nb_leads($pdo);
check(array_column($l, 'name') === ['Burial', 'Foo'], 'confirmed leads come before hints, even with fewer likes');
check($l[0]['songs'] === ['Archangel' => 1] && $l[0]['examples'][0]['text'] === 'Burial vibes' && $l[0]['people'] === 2, 'JSON and number fields decoded');
nb_leads_replace($pdo, [$lead('Burial', 'confirmed', 3, 90)]);
check(count(nb_leads($pdo)) === 1 && nb_leads($pdo)[0]['people'] === 3, 'replace drops the old rows');
$threw = false;
try { nb_leads_replace($pdo, [$lead('Bogus', 'maybe', 1, 1)]); } catch (InvalidArgumentException) { $threw = true; }
check($threw, 'unknown lead strength rejected');

echo "lead tie-break order\n";
nb_leads_replace($pdo, [
    $lead('Alpha', 'confirmed', 2, 50, 3),   // people 2, videos 3, likes 50
    $lead('Beta', 'confirmed', 2, 999, 1),   // same people, fewer videos than Alpha (likes shouldn't save it)
    $lead('Gamma', 'confirmed', 2, 999, 3),  // same people & videos as Alpha, more likes -> ranks first
]);
check(array_column(nb_leads($pdo), 'name') === ['Gamma', 'Alpha', 'Beta'],
    'same strength: more people first, then videos, then likes');

echo "invalid UTF-8 in examples is substituted, not dropped\n";
nb_leads_replace($pdo, [[
    'name_key' => nb_name_key('Weirdchar'), 'name' => 'Weirdchar', 'strength' => 'hint', 'people' => 1, 'videos' => 1,
    'mentions' => 1, 'likes' => 1, 'unsure_only' => false, 'songs' => [], 'video_ids' => [],
    'examples' => [['author' => '@a', 'text' => "bad \xB1 byte"]],
]]);
$examples = nb_leads($pdo)[0]['examples'];
check(count($examples) === 1 && str_contains($examples[0]['text'], 'bad') && str_contains($examples[0]['text'], 'byte'),
    'invalid UTF-8 bytes are substituted, not silently dropped');

echo "nested writes\n";
check(nb_write($pdo, fn() => nb_write($pdo, fn() => 42)) === 42, 'a write inside a write joins the outer transaction');
$threw = false;
try {
    nb_write($pdo, function () use ($pdo) {
        nb_chat_add($pdo, 'system', 'rolled back');
        throw new RuntimeException('boom');
    });
} catch (RuntimeException) {
    $threw = true;
}
check($threw && !array_filter(nb_chat_since($pdo, 0), fn($m) => $m['text'] === 'rolled back'), 'an error rolls back the inner write too');
check(nb_chat_add($pdo, 'system', 'after') > 0, 'writes work again after a rollback');

echo "name keys\n";
check(nb_name_key('The Knife') === 'knife' && nb_name_key('Björk') === 'bjork' && nb_name_key('Daft Punk!') === 'daftpunk', 'name key');
check(nb_name_key("\u{00A0}The Knife") === 'knife', 'unicode whitespace (NBSP) trimmed before matching a leading "the"');
check(nb_name_key('ΣΑΣ') === 'σασ', 'a final sigma is normalised to a regular sigma');
check(nb_name_key('Theatre of Tragedy') === 'theatreoftragedy', '"the" is only dropped as a whole word, not as a prefix');
check(nb_name_key('ℌello') === 'hello', 'compatibility-decomposed letter-like symbols fold to plain letters');

finish();
