<?php
declare(strict_types=1);
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/assert.php';

fresh_db('test_cli'); // sets NB_DB for the child processes
$cli = __DIR__ . '/../bin/nb.php';

function nb_cli(string $cli, array $args): array
{
    $cmd = array_merge([PHP_BINARY, $cli], $args);
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    $code = proc_close($p);
    return [$code, json_decode((string)$out, true)];
}

$batchFile = tmp_dir() . '/batch.json';
file_put_contents($batchFile, json_encode(['summary' => 'test batch', 'songs' => [
    ['video_id' => 'AAAAAAAAAAA', 'artist' => 'A', 'title' => 'Song A', 'bucket' => 'close', 'reason' => 'because'],
]]));
putenv('NB_JOB_ID=7');
[$code, $res] = nb_cli($cli, ['add-batch', $batchFile]);
putenv('NB_JOB_ID');
check($code === 0 && $res['ok'] === true && $res['added'] === ['AAAAAAAAAAA'], 'add-batch from file');

[$code, $q] = nb_cli($cli, ['queue']);
check($code === 0 && count($q) === 1 && $q[0]['title'] === 'Song A', 'queue lists the song');

$pdo = nb_db();
check((int)$pdo->query('SELECT job_id FROM batches')->fetchColumn() === 7, 'batch records NB_JOB_ID');
// Timestamps have 1-second resolution; move the batch into the past so "since the batch" is unambiguous.
$pdo->exec("UPDATE batches SET created_at = '2001-01-01T00:00:00Z'");
nb_save_listen($pdo, ['video_id' => 'AAAAAAAAAAA', 'rating' => 'yes']);
[$code, $fb] = nb_cli($cli, ['feedback', 'all']);
check($code === 0 && $fb['since'] === null && $fb['listens'][0]['rating'] === 'yes', 'feedback all');
[$code, $fb] = nb_cli($cli, ['feedback']);
check($code === 0 && $fb['since'] !== null && count($fb['listens']) === 1, 'feedback since last batch');

[$code, $m] = nb_cli($cli, ['mute', 'artist', 'Some', 'Band']);
check($code === 0 && $m['ok'] === true, 'mute');
[$code, $ms] = nb_cli($cli, ['mutes']);
check($ms[0]['value'] === 'Some Band', 'mutes lists value with spaces');

[$code, $st] = nb_cli($cli, ['status']);
check($code === 0 && $st['songs'] === 1 && $st['rated'] === 1 && $st['unplayed'] === 0, 'status counts');

file_put_contents($batchFile, 'not json');
[$code, $res] = nb_cli($cli, ['add-batch', $batchFile]);
check($code === 2 && $res['ok'] === false, 'bad JSON rejected');
[$code, $res] = nb_cli($cli, ['frobnicate']);
check($code === 2 && isset($res['usage']), 'unknown command shows usage');

[$code, $r] = nb_cli($cli, ['say', 'Building', 'a', 'batch', 'now']);
$said = nb_chat_since(nb_db(), 0);
check($code === 0 && $r['ok'] === true && end($said)['role'] === 'parent' && end($said)['text'] === 'Building a batch now', 'say posts a parent chat message');
[$code, $r] = nb_cli($cli, ['say']);
check($code === 2 && $r['ok'] === false, 'say without text rejected');

[$code, $r] = nb_cli($cli, ['note', 'AAAAAAAAAAA', 'love', 'the', 'drums']);
check($code === 0 && str_ends_with($r['notes'], 'love the drums'), 'note appends to the song');
[$code, $r] = nb_cli($cli, ['note', 'ZZZZZZZZZZZ', 'x']);
check($code === 2 && $r['ok'] === false, 'note on unknown song rejected');

echo "nb.log\n";
// The log is append-only across runs, so check the most recent entries.
$log = array_map(fn($l) => json_decode($l, true), file(tmp_dir() . '/nb.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
$last = end($log);
check($last['args'] === ['note', 'ZZZZZZZZZZZ', 'x'] && $last['exit'] === 2, 'last call logged with args and exit code');
$bad = array_values(array_filter($log, fn($e) => ($e['args'][0] ?? '') === 'add-batch' && ($e['input'] ?? '') === 'not json'));
check(count($bad) > 0, 'add-batch logs the exact input it was given');

finish();
