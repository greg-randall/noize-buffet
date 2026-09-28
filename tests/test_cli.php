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

nb_leads_replace($pdo, [
    ['name_key' => 'burial', 'name' => 'Burial', 'strength' => 'confirmed', 'people' => 2, 'videos' => 1, 'mentions' => 2,
        'likes' => 55, 'unsure_only' => false, 'songs' => ['Archangel' => 1], 'video_ids' => ['AAAAAAAAAAA'],
        'examples' => [['author' => '@ann', 'likes' => 50, 'text' => 'check out Burial'], ['author' => '@bob', 'likes' => 5, 'text' => 'Burial!']]],
    ['name_key' => 'someband', 'name' => 'Some Band', 'strength' => 'hint', 'people' => 1, 'videos' => 1, 'mentions' => 1,
        'likes' => 1, 'unsure_only' => false, 'songs' => [], 'video_ids' => ['AAAAAAAAAAA'], 'examples' => [['author' => '@c', 'likes' => 1, 'text' => 'Some Band vibes']]],
]);
[$code, $leads] = nb_cli($cli, ['leads']);
check($code === 0 && array_column($leads, 'name') === ['Burial', 'Some Band'] && $leads[0]['top_comment'] === 'check out Burial', 'leads lists every lead, confirmed first');
check($leads[1]['muted'] === true && $leads[0]['muted'] === false, 'muted artists are marked (Some Band was muted above)');
[$code, $one] = nb_cli($cli, ['lead', 'burial']);
check($code === 0 && count($one['examples']) === 2, 'lead shows every comment behind one lead');
[$code, $none] = nb_cli($cli, ['lead', 'Nobody', 'At', 'All']);
check($code === 2 && $none['ok'] === false, 'unknown lead rejected');
[$code, $st] = nb_cli($cli, ['status']);
check($code === 0 && array_key_exists('mining', $st), 'status includes mining counts');

// The agent process outlives any one job, so without NB_JOB_ID the CLI uses the running job.
nb_job_enqueue($pdo, 'chat', ['message' => 'x']);
$running = nb_job_next($pdo);
[$code, $r] = nb_cli($cli, ['say', 'from', 'the', 'running', 'job']);
$said = nb_chat_since(nb_db(), 0);
check($code === 0 && (int)end($said)['job_id'] === (int)$running['id'], 'say without NB_JOB_ID uses the running job');

[$code, $r] = nb_cli($cli, ['set', 'AAAAAAAAAAA', 'rating=top', 'new_to_me=1', 'off_brief=1']);
$l = nb_listen(nb_db(), 'AAAAAAAAAAA');
check($code === 0 && $l['rating'] === 'top' && (int)$l['new_to_me'] === 1 && (int)$l['off_brief'] === 1, 'set rating and toggles');
[$code, $r] = nb_cli($cli, ['set', 'AAAAAAAAAAA', 'new_to_me=unknown', 'off_brief=0']);
$l = nb_listen(nb_db(), 'AAAAAAAAAAA');
check($code === 0 && $l['rating'] === 'top' && $l['new_to_me'] === null && (int)$l['off_brief'] === 0, 'set only the given fields');
[$code, $r] = nb_cli($cli, ['set', 'AAAAAAAAAAA', 'rating=amazing']);
check($code === 2 && $r['ok'] === false && nb_listen(nb_db(), 'AAAAAAAAAAA')['rating'] === 'top', 'bad rating rejected, nothing changed');
[$code, $r] = nb_cli($cli, ['set', 'AAAAAAAAAAA', 'volume=11']);
check($code === 2 && str_contains($r['error'], 'volume=11'), 'unknown field rejected');
[$code, $r] = nb_cli($cli, ['set', 'AAAAAAAAAAA']);
check($code === 2 && $r['ok'] === false, 'set with nothing to set rejected');
[$code, $r] = nb_cli($cli, ['set', 'ZZZZZZZZZZZ', 'rating=yes']);
check($code === 2 && $r['ok'] === false, 'set on unknown song rejected');

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

echo "remine\n";
nb_mining_enqueue(nb_db(), 'AAAAAAAAAAA');
nb_mining_update(nb_db(), 'AAAAAAAAAAA', ['status' => 'done']);
[$code, $r] = nb_cli($cli, ['remine', 'AAAAAAAAAAA']);
$m = array_values(array_filter(nb_mining_list(nb_db()), fn($x) => $x['video_id'] === 'AAAAAAAAAAA'))[0];
check($code === 0 && $r['queued'] === 'AAAAAAAAAAA' && $m['status'] === 'queued', 'remine puts a mined song back in the queue');
[$code, $r] = nb_cli($cli, ['remine', 'ZZZZZZZZZZZ']);
check($code === 2 && $r['ok'] === false, 'remine of a song never mined is refused');

echo "memory file history\n";
$mem = tmp_dir() . '/memory';
exec('rm -rf ' . escapeshellarg($mem));
mkdir($mem);
putenv("NB_MEMORY_DIR=$mem");
putenv("NB_HANDOFF_FILE=$mem/handoff.md");
$pdo = nb_db();
check(nb_file_snapshot($pdo, null) === [], 'no files yet: nothing saved');
file_put_contents("$mem/taste.md", "## You said\n- loves the drums\n");
check(nb_file_snapshot($pdo, 3) === ['taste.md'], 'a new taste.md is saved');
check(nb_file_snapshot($pdo, 4) === [], 'unchanged: not saved again');
file_put_contents("$mem/taste.md", "## You said\n");
file_put_contents("$mem/brief.md", "weird club music");
check(nb_file_snapshot($pdo, 5) === ['brief.md', 'taste.md'], 'each changed file is saved');
unlink("$mem/taste.md");
check(nb_file_snapshot($pdo, null) === ['taste.md'], 'a deleted file is recorded');
[$code, $h] = nb_cli($cli, ['history', 'taste.md']);
check($code === 0 && count($h) === 3 && $h[0]['deleted'] === true && $h[0]['job_id'] === null
    && $h[2]['job_id'] === 3 && $h[2]['bytes'] === strlen("## You said\n- loves the drums\n") && !isset($h[0]['content']),
    'history lists versions newest first, with the job that made each, without their text');
[$code, $v] = nb_cli($cli, ['history', 'taste.md', (string)$h[2]['id']]);
check($code === 0 && $v['content'] === "## You said\n- loves the drums\n" && $v['deleted'] === false,
    "an old version's text can be read back (a line the agent later dropped isn't lost)");
[$code, $r] = nb_cli($cli, ['history', 'brief.md', (string)$h[2]['id']]);
check($code === 2 && $r['ok'] === false, "another file's version id is refused");
[$code, $r] = nb_cli($cli, ['history', '../../etc/passwd']);
check($code === 2 && $r['ok'] === false, 'only the memory files have a history');
putenv('NB_MEMORY_DIR');
putenv('NB_HANDOFF_FILE');

finish();
