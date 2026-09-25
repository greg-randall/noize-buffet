<?php
declare(strict_types=1);
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/assert.php';

$pdo = fresh_db('test_db');
$A = 'AAAAAAAAAAA';
$B = 'BBBBBBBBBBB';

echo "batches\n";
$r = nb_add_batch($pdo, [
    ['video_id' => $A, 'artist' => 'Art A', 'title' => 'Song A', 'duration_s' => 200, 'bucket' => 'close', 'reason' => 'r', 'source' => 's'],
    ['video_id' => $B, 'artist' => 'Art B', 'title' => 'Song B', 'bucket' => 'wildcard'],
    ['video_id' => 'short', 'title' => 'Bad', 'bucket' => 'close'],
    ['video_id' => 'CCCCCCCCCCC', 'title' => 'No bucket'],
], 'first batch', null);
check($r['added'] === [$A, $B], 'two valid songs added');
check(count($r['invalid']) === 2, 'two invalid reported');
check(is_int($r['batch_id']), 'batch id returned');
$r2 = nb_add_batch($pdo, [['video_id' => $A, 'title' => 'Song A again', 'bucket' => 'close']], 'dup batch', null);
check($r2['added'] === [] && $r2['duplicates'] === [$A] && $r2['batch_id'] === null, 'duplicate-only batch adds nothing and creates no batch');
$q = nb_queue($pdo);
check(array_column($q, 'video_id') === [$A, $B], 'queue in added order');
check($q[0]['batch_summary'] === 'first batch' && $q[0]['rating'] === null, 'queue joins batch and listen columns');

echo "listens\n";
$row = nb_save_listen($pdo, ['video_id' => $A, 'furthest_pct' => 40]);
check((float)$row['furthest_pct'] === 40.0, 'furthest stored');
$row = nb_save_listen($pdo, ['video_id' => $A, 'furthest_pct' => 20]);
check((float)$row['furthest_pct'] === 40.0, 'furthest keeps max');
$row = nb_save_listen($pdo, ['video_id' => $A, 'furthest_pct' => 170]);
check((float)$row['furthest_pct'] === 100.0, 'furthest clamped');
$row = nb_save_listen($pdo, ['video_id' => $A, 'rating' => 'meh']);
$row = nb_save_listen($pdo, ['video_id' => $A, 'rating' => 'top']);
check($row['rating'] === 'top', 'newest rating wins');
$row = nb_save_listen($pdo, ['video_id' => $A, 'off_brief' => true, 'new_to_me' => true]);
check((int)$row['off_brief'] === 1 && (int)$row['new_to_me'] === 1, 'toggles set');
$row = nb_save_listen($pdo, ['video_id' => $A, 'off_brief' => false, 'new_to_me' => null]);
check((int)$row['off_brief'] === 0 && $row['new_to_me'] === null, 'toggles cleared');
$row = nb_save_listen($pdo, ['video_id' => $A, 'skipped' => 1]);
$row = nb_save_listen($pdo, ['video_id' => $A, 'skipped' => 0, 'finished' => 1]);
check((int)$row['skipped'] === 1 && (int)$row['finished'] === 1, 'flags sticky');
nb_save_listen($pdo, ['video_id' => $A, 'notes' => 'draft']);
nb_save_listen($pdo, ['video_id' => $A, 'notes' => 'draft, edited']);
$pdo->exec("UPDATE listens SET notes_updated = '2000-01-01T00:00:00Z'");
$row = nb_save_listen($pdo, ['video_id' => $A, 'notes' => 'later']);
$hist = $pdo->query('SELECT notes FROM note_history')->fetchAll(PDO::FETCH_COLUMN);
check($row['notes'] === 'later' && $hist === ['draft, edited'], 'note history only for old notes');
nb_save_listen($pdo, ['video_id' => $B, 'duration_s' => 321, 'error' => '150']);
$songB = $pdo->query("SELECT duration_s, unplayable_error FROM songs WHERE video_id = '$B'")->fetch();
check((float)$songB['duration_s'] === 321.0 && $songB['unplayable_error'] === '150', 'duration and error recorded on song');
$threw = function (array $in) use ($pdo): bool {
    try { nb_save_listen($pdo, $in); return false; } catch (InvalidArgumentException) { return true; }
};
check($threw(['video_id' => 'ZZZZZZZZZZZ']), 'unknown song rejected');
check($threw(['video_id' => $A, 'rating' => 'amazing']), 'bad rating rejected');

echo "feedback\n";
// Timestamps have 1-second resolution; move the batch into the past so "since the batch" is unambiguous.
$pdo->exec("UPDATE batches SET created_at = '2001-01-01T00:00:00Z'");
$since = nb_last_batch_at($pdo);
check($since !== null, 'last batch time known');
$fb = nb_feedback_since($pdo, null);
check(count($fb) === 2 && $fb[0]['title'] !== null, 'feedback (all) joins song titles');
$pdo->exec("UPDATE listens SET last_played = '2000-01-01T00:00:00Z' WHERE video_id = '$B'");
check(array_column(nb_feedback_since($pdo, $since), 'video_id') === [$A], 'feedback since last batch excludes older listens');

echo "chat, jobs, settings, mutes\n";
$c1 = nb_chat_add($pdo, 'user', 'hello');
$c2 = nb_chat_add($pdo, 'parent', 'hi');
check(array_column(nb_chat_since($pdo, $c1), 'text') === ['hi'], 'chat since id');
$j1 = nb_job_enqueue($pdo, 'chat', ['message' => 'hello']);
$j2 = nb_job_enqueue($pdo, 'interview');
$next = nb_job_next($pdo);
check($next['id'] === $j1 && $next['status'] === 'running', 'oldest job taken and marked running');
$st = nb_job_status($pdo);
check($st['running']['id'] === $j1 && $st['queued'] === 1, 'status shows running + queued');
nb_job_finish($pdo, $j1, true, 'done', null, 'sess');
check(nb_job_next($pdo)['id'] === $j2, 'next job after finish');
check((function () use ($pdo) { try { nb_job_enqueue($pdo, 'bogus'); return false; } catch (InvalidArgumentException) { return true; } })(), 'bad job kind rejected');
nb_setting_set($pdo, 'k', 'v');
check(nb_setting($pdo, 'k') === 'v' && nb_setting($pdo, 'missing', 'd') === 'd', 'settings');
nb_setting_set($pdo, 'k', null);
check(nb_setting($pdo, 'k') === null, 'setting cleared');
nb_mute_add($pdo, 'artist', 'Some Band');
check(nb_mutes($pdo)[0]['value'] === 'Some Band', 'mute stored');

echo "concurrent connections\n";
// Regression: a single-row fetch must not leave its cursor open. An open cursor keeps a read lock,
// so this connection kept seeing a stale snapshot and other processes couldn't write (long-poll bug).
$path = tmp_dir() . '/test_db.sqlite';
$a = nb_db($path);
$b = nb_db($path);
nb_job_status($a);
nb_job_next($a);
nb_listen($a, 'AAAAAAAAAAA');
$before = count(nb_chat_since($a, 0));
$wrote = true;
try { nb_chat_add($b, 'parent', 'written by another connection'); } catch (PDOException) { $wrote = false; }
check($wrote, 'another connection can write after status/next/listen reads');
check(count(nb_chat_since($a, 0)) === $before + 1, 'the first connection sees the new row');

finish();
