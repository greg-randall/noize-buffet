<?php
declare(strict_types=1);

const NB_RATINGS = ['top', 'yes', 'good', 'ok', 'meh', 'no'];
const NB_BUCKETS = ['close', 'lead', 'sideways', 'wildcard', 'user'];
const NB_JOB_KINDS = ['interview', 'chat', 'refill'];
const NB_MUTE_KINDS = ['artist', 'lane'];
const NB_NOTE_HISTORY_AFTER_S = 600; // keep an old note only if last changed 10+ minutes ago
const NB_VIDEO_ID_RE = '/^[A-Za-z0-9_-]{11}$/';
const NB_MINE_RATINGS = ['top', 'yes']; // rating a song one of these queues its comments for mining
const NB_MINING_STATUSES = ['queued', 'downloading', 'filtering', 'extracting', 'done', 'failed'];
const NB_MINING_FIELDS = ['status', 'filter', 'error', 'comments', 'flagged', 'covered', 'mentions', 'notes'];
const NB_LEAD_STRENGTHS = ['confirmed', 'hint'];

function nb_root(): string
{
    return dirname(__DIR__);
}

function nb_db_path(): string
{
    return getenv('NB_DB') ?: nb_root() . '/data/music.sqlite';
}

function nb_now(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}

/**
 * Seconds on a monotonic clock, for durations and deadlines (not the time of day). microtime() follows the wall
 * clock, which went backwards on WSL here ("exit 124 after -0.5s").
 */
function nb_clock(): float
{
    return hrtime(true) / 1e9;
}

function nb_db(?string $path = null): PDO
{
    $path = $path ?? nb_db_path();
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }
    $pdo = new NbPdo('sqlite:' . $path);
    $pdo->lockFile = "$path.lock";
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    nb_locked($pdo, fn() => nb_create_tables($pdo));
    return $pdo;
}

function nb_create_tables(PDO $pdo): void
{
    foreach ([
        'CREATE TABLE IF NOT EXISTS songs (
            video_id TEXT PRIMARY KEY, artist TEXT, title TEXT, channel TEXT, duration_s REAL,
            added_at TEXT NOT NULL, batch_id INTEGER, bucket TEXT, reason TEXT, source TEXT,
            unplayable_error TEXT)',
        'CREATE TABLE IF NOT EXISTS batches (
            id INTEGER PRIMARY KEY AUTOINCREMENT, created_at TEXT NOT NULL, job_id INTEGER, summary TEXT)',
        'CREATE TABLE IF NOT EXISTS listens (
            video_id TEXT PRIMARY KEY, furthest_pct REAL NOT NULL DEFAULT 0, rating TEXT, notes TEXT,
            notes_updated TEXT, off_brief INTEGER NOT NULL DEFAULT 0, new_to_me INTEGER,
            skipped INTEGER NOT NULL DEFAULT 0, finished INTEGER NOT NULL DEFAULT 0,
            first_played TEXT, last_played TEXT)',
        'CREATE TABLE IF NOT EXISTS note_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT, video_id TEXT NOT NULL, notes TEXT NOT NULL, replaced_at TEXT NOT NULL)',
        'CREATE TABLE IF NOT EXISTS chat (
            id INTEGER PRIMARY KEY AUTOINCREMENT, role TEXT NOT NULL, text TEXT NOT NULL,
            created_at TEXT NOT NULL, job_id INTEGER)',
        'CREATE TABLE IF NOT EXISTS jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT, kind TEXT NOT NULL, status TEXT NOT NULL,
            payload TEXT, result TEXT, error TEXT, created_at TEXT NOT NULL,
            started_at TEXT, finished_at TEXT, session_id TEXT)',
        'CREATE TABLE IF NOT EXISTS mutes (
            id INTEGER PRIMARY KEY AUTOINCREMENT, kind TEXT NOT NULL, value TEXT NOT NULL, created_at TEXT NOT NULL)',
        'CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT)',
        'CREATE TABLE IF NOT EXISTS mining (
            video_id TEXT PRIMARY KEY, status TEXT NOT NULL, filter TEXT, error TEXT,
            comments INTEGER, flagged INTEGER, covered INTEGER, mentions INTEGER,
            queued_at TEXT NOT NULL, updated_at TEXT NOT NULL, notes TEXT)',
        'CREATE TABLE IF NOT EXISTS leads (
            name_key TEXT PRIMARY KEY, name TEXT NOT NULL, strength TEXT NOT NULL,
            people INTEGER NOT NULL, videos INTEGER NOT NULL, mentions INTEGER NOT NULL, likes INTEGER NOT NULL,
            unsure_only INTEGER NOT NULL, songs_json TEXT NOT NULL, video_ids_json TEXT NOT NULL,
            examples_json TEXT NOT NULL, updated_at TEXT NOT NULL)',
    ] as $sql) {
        $pdo->exec($sql);
    }
    // Columns added after a table was first made: add them to databases created before.
    $mining = array_column($pdo->query('PRAGMA table_info(mining)')->fetchAll(), 'name');
    if (!in_array('notes', $mining, true)) {
        $pdo->exec('ALTER TABLE mining ADD COLUMN notes TEXT'); // what the filter skipped, e.g. quarantined comments
    }
}

// ---------- locking ----------

const NB_LOCK_WAIT_S = 30; // give up if another process holds the database lock this long

/** A connection that knows its lock file (see nb_locked). */
final class NbPdo extends PDO
{
    public string $lockFile = '';
}

/**
 * Run $fn while holding this database's lock: an flock() on <db>.lock, held by one process at a time.
 * Every database operation goes through here. SQLite's own locking between processes relies on POSIX file locks,
 * which don't work on WSL's 9p mount of a Windows drive: writes and reads failed at once with "database is locked"
 * or stalled for seconds (tests/test_db.php "concurrent writers" reproduces it). flock() does work there.
 * Re-entrant: a function that already holds the lock can call others.
 */
function nb_locked(PDO $pdo, callable $fn): mixed
{
    static $held = []; // lock file => nesting depth
    $file = $pdo instanceof NbPdo ? $pdo->lockFile : '';
    if ($file === '' || isset($held[$file])) {
        if ($file !== '') {
            $held[$file]++;
        }
        try {
            return $fn();
        } finally {
            if ($file !== '') {
                $held[$file]--;
            }
        }
    }
    $h = fopen($file, 'c');
    if ($h === false) {
        throw new RuntimeException("can't open the database lock file $file");
    }
    $deadline = nb_clock() + NB_LOCK_WAIT_S;
    while (!flock($h, LOCK_EX | LOCK_NB)) {
        if (nb_clock() >= $deadline) {
            fclose($h);
            throw new RuntimeException('database busy: another process held the lock for ' . NB_LOCK_WAIT_S . ' seconds');
        }
        usleep(random_int(2000, 10000));
    }
    $held[$file] = 1;
    try {
        return $fn();
    } finally {
        unset($held[$file]);
        flock($h, LOCK_UN);
        fclose($h);
    }
}

/**
 * Run $fn in a write transaction (under the lock) and return its result. A write inside a write joins it.
 * An inner write's error must propagate: catching it inside an outer write would commit whatever the inner write
 * already did.
 */
function nb_write(PDO $pdo, callable $fn): mixed
{
    static $open = []; // spl_object_id of each connection currently inside nb_write
    $id = spl_object_id($pdo);
    if (isset($open[$id])) {
        return $fn();
    }
    return nb_locked($pdo, function () use ($pdo, $fn, $id, &$open) {
        $pdo->exec('BEGIN IMMEDIATE');
        $open[$id] = true;
        try {
            $result = $fn();
            $pdo->exec('COMMIT');
            return $result;
        } catch (Throwable $e) {
            try {
                $pdo->exec('ROLLBACK');
            } catch (Throwable) {
                // SQLite may have rolled back already; keep the original error
            }
            throw $e;
        } finally {
            unset($open[$id]);
        }
    });
}

// ---------- songs and batches ----------

/**
 * Add a batch of songs. Each song needs an 11-char video_id, a title and a bucket.
 * Returns batch_id (null if nothing was added), added ids, duplicate ids and invalid entries.
 */
function nb_add_batch(PDO $pdo, array $songs, string $summary, ?int $jobId): array
{
    return nb_write($pdo, function () use ($pdo, $songs, $summary, $jobId): array {
        $added = [];
        $duplicates = [];
        $invalid = [];
        $now = nb_now();
        $pdo->prepare('INSERT INTO batches (created_at, job_id, summary) VALUES (?, ?, ?)')->execute([$now, $jobId, $summary]);
        $batchId = (int)$pdo->lastInsertId();
        $exists = $pdo->prepare('SELECT COUNT(*) FROM songs WHERE video_id = ?');
        $insert = $pdo->prepare('INSERT INTO songs (video_id, artist, title, channel, duration_s, added_at, batch_id, bucket, reason, source)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($songs as $i => $s) {
            $vid = is_array($s) ? (string)($s['video_id'] ?? '') : '';
            $bucket = is_array($s) ? (string)($s['bucket'] ?? '') : '';
            $title = is_array($s) ? trim((string)($s['title'] ?? '')) : '';
            if (!preg_match(NB_VIDEO_ID_RE, $vid) || !in_array($bucket, NB_BUCKETS, true) || $title === '') {
                $invalid[] = ['index' => $i, 'song' => $s,
                    'why' => 'needs an 11-character video_id, a title, and bucket one of ' . implode('/', NB_BUCKETS)];
                continue;
            }
            $exists->execute([$vid]);
            $isDup = (int)$exists->fetchColumn() > 0;
            $exists->closeCursor();
            if ($isDup || in_array($vid, $added, true)) {
                $duplicates[] = $vid;
                continue;
            }
            $insert->execute([
                $vid, (string)($s['artist'] ?? ''), $title, (string)($s['channel'] ?? ''),
                isset($s['duration_s']) && (float)$s['duration_s'] > 0 ? (float)$s['duration_s'] : null,
                $now, $batchId, $bucket, (string)($s['reason'] ?? ''), (string)($s['source'] ?? ''),
            ]);
            $added[] = $vid;
            if ($bucket === 'user') {
                nb_mining_enqueue($pdo, $vid); // a song they named: mine its comments for leads
            }
        }
        if ($added === []) {
            $pdo->prepare('DELETE FROM batches WHERE id = ?')->execute([$batchId]);
            $batchId = null;
        }
        return ['batch_id' => $batchId, 'added' => $added, 'duplicates' => $duplicates, 'invalid' => $invalid];
    });
}

/** Every song in the order it was added, with its listen data and batch summary. */
function nb_queue(PDO $pdo): array
{
    return nb_locked($pdo, fn() => $pdo->query('SELECT s.*, l.furthest_pct, l.rating, l.notes, l.off_brief, l.new_to_me,
            l.skipped, l.finished, l.first_played, l.last_played, b.summary AS batch_summary
        FROM songs s
        LEFT JOIN listens l ON l.video_id = s.video_id
        LEFT JOIN batches b ON b.id = s.batch_id
        ORDER BY s.added_at, s.rowid')->fetchAll());
}

function nb_last_batch_at(PDO $pdo): ?string
{
    $v = nb_locked($pdo, fn() => $pdo->query('SELECT MAX(created_at) FROM batches')->fetchColumn());
    return $v === false || $v === null ? null : (string)$v;
}

// ---------- listens ----------

function nb_listen(PDO $pdo, string $videoId): array
{
    return nb_locked($pdo, function () use ($pdo, $videoId): array {
        $st = $pdo->prepare('SELECT * FROM listens WHERE video_id = ?');
        $st->execute([$videoId]);
        $row = $st->fetch() ?: [];
        $st->closeCursor();
        return $row;
    });
}

/** Merge $in into the song's listen row (rules in the spec); returns the saved row. */
function nb_save_listen(PDO $pdo, array $in): array
{
    $vid = (string)($in['video_id'] ?? '');
    if (!preg_match(NB_VIDEO_ID_RE, $vid)) {
        throw new InvalidArgumentException('video_id must be 11 characters [A-Za-z0-9_-]');
    }
    $rating = $in['rating'] ?? null;
    if ($rating !== null && $rating !== '' && !in_array($rating, NB_RATINGS, true)) {
        throw new InvalidArgumentException('rating must be one of ' . implode(', ', NB_RATINGS));
    }
    return nb_write($pdo, function () use ($pdo, $in, $vid, $rating): array {
        $st = $pdo->prepare('SELECT COUNT(*) FROM songs WHERE video_id = ?');
        $st->execute([$vid]);
        $known = (int)$st->fetchColumn() > 0;
        $st->closeCursor();
        if (!$known) {
            throw new InvalidArgumentException("unknown song $vid");
        }
        $now = nb_now();
        $row = nb_listen($pdo, $vid) ?: [
            'video_id' => $vid, 'furthest_pct' => 0.0, 'rating' => null, 'notes' => null, 'notes_updated' => null,
            'off_brief' => 0, 'new_to_me' => null, 'skipped' => 0, 'finished' => 0,
            'first_played' => $now, 'last_played' => $now,
        ];
        if (isset($in['furthest_pct'])) {
            $row['furthest_pct'] = max((float)$row['furthest_pct'], max(0.0, min(100.0, (float)$in['furthest_pct'])));
        }
        if ($rating !== null && $rating !== '') {
            $row['rating'] = $rating;
        }
        $old = (string)($row['notes'] ?? '');
        $new = array_key_exists('notes', $in) && $in['notes'] !== null ? (string)$in['notes'] : null;
        if (isset($in['append_notes'])) { // read and append inside this transaction, so concurrent notes aren't lost
            $new = $old === '' ? (string)$in['append_notes'] : "$old\n{$in['append_notes']}";
        }
        if ($new !== null) {
            if ($new !== $old) {
                $lastChanged = $row['notes_updated'] ? (int)strtotime((string)$row['notes_updated']) : 0;
                if ($old !== '' && time() - $lastChanged > NB_NOTE_HISTORY_AFTER_S) {
                    $pdo->prepare('INSERT INTO note_history (video_id, notes, replaced_at) VALUES (?, ?, ?)')->execute([$vid, $old, $now]);
                }
                $row['notes'] = $new;
                $row['notes_updated'] = $now;
            }
        }
        if (array_key_exists('off_brief', $in)) {
            $row['off_brief'] = $in['off_brief'] ? 1 : 0;
        }
        if (array_key_exists('new_to_me', $in)) {
            $row['new_to_me'] = $in['new_to_me'] === null ? null : ($in['new_to_me'] ? 1 : 0);
        }
        foreach (['skipped', 'finished'] as $k) {
            if (!empty($in[$k])) {
                $row[$k] = 1;
            }
        }
        $row['last_played'] = $now;
        $cols = array_keys($row);
        $pdo->prepare('INSERT OR REPLACE INTO listens (' . implode(', ', $cols) . ') VALUES ('
            . implode(', ', array_fill(0, count($cols), '?')) . ')')->execute(array_values($row));

        if (isset($in['duration_s']) && (float)$in['duration_s'] > 0) {
            $pdo->prepare('UPDATE songs SET duration_s = ? WHERE video_id = ?')->execute([(float)$in['duration_s'], $vid]);
        }
        if (isset($in['error']) && $in['error'] !== '') {
            $pdo->prepare('UPDATE songs SET unplayable_error = ? WHERE video_id = ?')->execute([(string)$in['error'], $vid]);
        }
        if ($rating !== null && in_array($rating, NB_MINE_RATINGS, true)) {
            nb_mining_enqueue($pdo, $vid); // a song they love: mine its comments for leads
        }
        return nb_listen($pdo, $vid);
    });
}

/** Append a note to a song's notes (never overwrites; old versions follow the note-history rule). */
function nb_append_note(PDO $pdo, string $videoId, string $text): array
{
    $text = trim($text);
    if ($text === '') {
        throw new InvalidArgumentException('note text is empty');
    }
    return nb_save_listen($pdo, ['video_id' => $videoId, 'append_notes' => $text]);
}

/** Keep only the known fields of the "current song" the browser sends with a chat message. */
function nb_song_context(mixed $song): ?array
{
    if (!is_array($song) || !preg_match(NB_VIDEO_ID_RE, (string)($song['video_id'] ?? ''))) {
        return null;
    }
    return [
        'video_id' => (string)$song['video_id'],
        'artist' => (string)($song['artist'] ?? ''),
        'title' => (string)($song['title'] ?? ''),
        'furthest_pct' => isset($song['furthest_pct']) ? (int)$song['furthest_pct'] : null,
        'rating' => in_array($song['rating'] ?? null, NB_RATINGS, true) ? $song['rating'] : null,
        'off_brief' => !empty($song['off_brief']),
        'new_to_me' => array_key_exists('new_to_me', $song) && $song['new_to_me'] !== null ? (bool)$song['new_to_me'] : null,
    ];
}

/** Listens (with song info) changed after $since (ISO time), or all if $since is null. */
function nb_feedback_since(PDO $pdo, ?string $since): array
{
    return nb_locked($pdo, function () use ($pdo, $since): array {
        $st = $pdo->prepare('SELECT s.artist, s.title, s.bucket, s.reason, s.source, s.unplayable_error, l.*
            FROM listens l JOIN songs s ON s.video_id = l.video_id
            WHERE :since IS NULL OR l.last_played > :since
            ORDER BY l.last_played');
        $st->execute(['since' => $since]);
        return $st->fetchAll();
    });
}

// ---------- chat ----------

function nb_chat_add(PDO $pdo, string $role, string $text, ?int $jobId = null): int
{
    return nb_write($pdo, function () use ($pdo, $role, $text, $jobId): int {
        $pdo->prepare('INSERT INTO chat (role, text, created_at, job_id) VALUES (?, ?, ?, ?)')->execute([$role, $text, nb_now(), $jobId]);
        return (int)$pdo->lastInsertId();
    });
}

function nb_chat_since(PDO $pdo, int $afterId): array
{
    return nb_locked($pdo, function () use ($pdo, $afterId): array {
        $st = $pdo->prepare('SELECT * FROM chat WHERE id > ? ORDER BY id');
        $st->execute([$afterId]);
        return $st->fetchAll();
    });
}

// ---------- jobs ----------

function nb_job_enqueue(PDO $pdo, string $kind, array $payload = []): int
{
    if (!in_array($kind, NB_JOB_KINDS, true)) {
        throw new InvalidArgumentException("unknown job kind $kind");
    }
    return nb_write($pdo, function () use ($pdo, $kind, $payload): int {
        $pdo->prepare("INSERT INTO jobs (kind, status, payload, created_at) VALUES (?, 'queued', ?, ?)")
            ->execute([$kind, json_encode($payload, JSON_UNESCAPED_UNICODE), nb_now()]);
        return (int)$pdo->lastInsertId();
    });
}

/** Take the oldest queued job and mark it running (atomic). */
function nb_job_next(PDO $pdo): ?array
{
    return nb_write($pdo, function () use ($pdo): ?array {
        $job = $pdo->query("SELECT * FROM jobs WHERE status = 'queued' ORDER BY id LIMIT 1")->fetch();
        if ($job) {
            $now = nb_now();
            $pdo->prepare("UPDATE jobs SET status = 'running', started_at = ? WHERE id = ?")->execute([$now, $job['id']]);
            $job['status'] = 'running';
            $job['started_at'] = $now;
            $job['id'] = (int)$job['id'];
        }
        return $job ?: null;
    });
}

function nb_job_finish(PDO $pdo, int $id, bool $ok, ?string $result, ?string $error, ?string $sessionId): void
{
    nb_write($pdo, fn() => $pdo->prepare('UPDATE jobs SET status = ?, result = ?, error = ?, session_id = ?, finished_at = ? WHERE id = ?')
        ->execute([$ok ? 'done' : 'failed', $result, $error, $sessionId, nb_now(), $id]));
}

/** Jobs still marked running when the worker starts were interrupted (e.g. Ctrl+C); mark them failed and say so. */
function nb_jobs_recover_interrupted(PDO $pdo): int
{
    return nb_locked($pdo, function () use ($pdo): int {
        $ids = $pdo->query("SELECT id FROM jobs WHERE status = 'running'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            nb_job_finish($pdo, (int)$id, false, null, 'interrupted: the worker stopped while this job was running', null);
            nb_chat_add($pdo, 'system', "The agent was stopped in the middle of job $id. Send your message again if you still need it.", (int)$id);
        }
        return count($ids);
    });
}

function nb_job_status(PDO $pdo): array
{
    return nb_locked($pdo, function () use ($pdo): array {
        $running = $pdo->query("SELECT id, kind, started_at FROM jobs WHERE status = 'running' ORDER BY id LIMIT 1")->fetch();
        if ($running) {
            $running['id'] = (int)$running['id'];
            // What the agent is doing right now, written by the worker as tool calls stream in.
            $activity = json_decode((string)nb_setting($pdo, 'agent_activity'), true);
            $running['activity'] = ($activity['job'] ?? null) === $running['id'] ? $activity['text'] : null;
        }
        $queued = (int)$pdo->query("SELECT COUNT(*) FROM jobs WHERE status = 'queued'")->fetchColumn();
        return ['running' => $running ?: null, 'queued' => $queued];
    });
}

/** Songs not played yet (and not known to be unplayable): what's left in the queue. */
function nb_unplayed_count(PDO $pdo): int
{
    return nb_locked($pdo, fn() => (int)$pdo->query('SELECT COUNT(*) FROM songs s LEFT JOIN listens l ON l.video_id = s.video_id
        WHERE l.video_id IS NULL AND s.unplayable_error IS NULL')->fetchColumn());
}

/**
 * Whether the worker should queue a refill now, and why not if it shouldn't.
 * Returns ['refill' => bool, 'unplayed' => int, 'why' => string].
 * Refill when at most $threshold songs are left, but only if: $threshold > 0 (0 turns it off); the first batch exists
 * (the interview is done); no job is queued or running (chat goes first, and a batch may already be on its way);
 * and, if no batch has arrived since the last refill, the user has played a new song since it (so a refill that
 * added nothing isn't retried in a loop) and the last two refills didn't both fail.
 */
function nb_refill_check(PDO $pdo, int $threshold): array
{
    return nb_locked($pdo, function () use ($pdo, $threshold): array {
        $unplayed = nb_unplayed_count($pdo);
        $no = fn(string $why) => ['refill' => false, 'unplayed' => $unplayed, 'why' => $why];
        if ($threshold <= 0) {
            return $no('auto-refill is off (refill_when_left is 0)');
        }
        if ($paused = nb_usage_paused($pdo)) {
            return $no('out of Claude usage until ' . gmdate('Y-m-d H:i', $paused['until']) . ' UTC');
        }
        if ($unplayed > $threshold) {
            return $no("$unplayed songs left");
        }
        if (nb_last_batch_at($pdo) === null) {
            return $no('no batch yet (interview not finished)');
        }
        $jobs = nb_job_status($pdo);
        if ($jobs['running'] || $jobs['queued'] > 0) {
            return $no('the agent is busy');
        }
        $refills = $pdo->query("SELECT status, payload, created_at FROM jobs WHERE kind = 'refill' ORDER BY id DESC LIMIT 2")->fetchAll();
        // A batch since the last refill means the normal cycle: fine to go again. Otherwise the last refill added
        // nothing or failed; only retry once they've played a song that was still new then (fewer unplayed now).
        if ($refills && nb_last_batch_at($pdo) < $refills[0]['created_at']) {
            if (count($refills) === 2 && $refills[0]['status'] === 'failed' && $refills[1]['status'] === 'failed') {
                return $no('paused: the last two refills failed; ask for songs in the chat to restart');
            }
            $then = (int)(json_decode((string)$refills[0]['payload'], true)['unplayed'] ?? 0);
            if ($unplayed >= $then) {
                return $no('the last refill added nothing and no new song has been played since');
            }
        }
        return ['refill' => true, 'unplayed' => $unplayed, 'why' => "$unplayed songs left"];
    });
}

// ---------- comment mining ----------

/** Queue a video's comments for mining (once per video). Returns true if it was newly queued. */
function nb_mining_enqueue(PDO $pdo, string $videoId): bool
{
    return nb_write($pdo, function () use ($pdo, $videoId): bool {
        $now = nb_now();
        $st = $pdo->prepare("INSERT OR IGNORE INTO mining (video_id, status, queued_at, updated_at) VALUES (?, 'queued', ?, ?)");
        $st->execute([$videoId, $now, $now]);
        return $st->rowCount() === 1;
    });
}

/** Queue songs rated top/yes or named by the user that aren't queued yet (e.g. rated before mining existed). */
function nb_mining_backfill(PDO $pdo): int
{
    return nb_write($pdo, function () use ($pdo): int {
        $in = implode(', ', array_fill(0, count(NB_MINE_RATINGS), '?'));
        $st = $pdo->prepare("SELECT s.video_id FROM songs s LEFT JOIN listens l ON l.video_id = s.video_id
            LEFT JOIN mining m ON m.video_id = s.video_id
            WHERE m.video_id IS NULL AND (s.bucket = 'user' OR l.rating IN ($in))");
        $st->execute(NB_MINE_RATINGS);
        $n = 0;
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $vid) {
            $n += nb_mining_enqueue($pdo, (string)$vid) ? 1 : 0;
        }
        return $n;
    });
}

/** Take the oldest queued video and mark it downloading (atomic); null if none is queued. */
function nb_mining_next(PDO $pdo): ?array
{
    return nb_write($pdo, function () use ($pdo): ?array {
        $row = $pdo->query("SELECT * FROM mining WHERE status = 'queued' ORDER BY queued_at, rowid LIMIT 1")->fetch();
        if (!$row) {
            return null;
        }
        $now = nb_now();
        $pdo->prepare("UPDATE mining SET status = 'downloading', error = NULL, updated_at = ? WHERE video_id = ?")
            ->execute([$now, $row['video_id']]);
        return ['status' => 'downloading', 'error' => null, 'updated_at' => $now] + $row;
    });
}

/** Set some of a mining row's fields (NB_MINING_FIELDS). */
function nb_mining_update(PDO $pdo, string $videoId, array $fields): void
{
    if ($fields === []) {
        throw new InvalidArgumentException('nothing to update');
    }
    $bad = array_diff(array_keys($fields), NB_MINING_FIELDS);
    if ($bad) {
        throw new InvalidArgumentException('unknown mining field(s): ' . implode(', ', $bad));
    }
    if (isset($fields['status']) && !in_array($fields['status'], NB_MINING_STATUSES, true)) {
        throw new InvalidArgumentException('mining status must be one of ' . implode(', ', NB_MINING_STATUSES));
    }
    nb_write($pdo, function () use ($pdo, $videoId, $fields): void {
        $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($fields)));
        $st = $pdo->prepare("UPDATE mining SET $sets, updated_at = ? WHERE video_id = ?");
        $st->execute([...array_values($fields), nb_now(), $videoId]);
        if ($st->rowCount() === 0) {
            throw new RuntimeException("no mining row for $videoId");
        }
    });
}

/** Every mining row with its song's artist and title, newest first. */
function nb_mining_list(PDO $pdo): array
{
    return nb_locked($pdo, fn() => $pdo->query('SELECT m.*, s.artist, s.title FROM mining m
        LEFT JOIN songs s ON s.video_id = m.video_id ORDER BY m.queued_at DESC, m.video_id')->fetchAll());
}

/** Videos left mid-mining when the worker stopped go back in the queue; their steps are redone. */
function nb_mining_recover_interrupted(PDO $pdo): int
{
    return nb_write($pdo, function () use ($pdo): int {
        $st = $pdo->prepare("UPDATE mining SET status = 'queued', updated_at = ?
            WHERE status IN ('downloading', 'filtering', 'extracting')");
        $st->execute([nb_now()]);
        return $st->rowCount();
    });
}

/**
 * Failed videos last tried more than $afterS seconds ago go back in the queue (a 429 or a network blip may not
 * happen again). Returns how many. A permanent failure (comments turned off) costs one quick try per $afterS.
 */
function nb_mining_retry_failed(PDO $pdo, int $afterS): int
{
    return nb_write($pdo, function () use ($pdo, $afterS): int {
        $st = $pdo->prepare("UPDATE mining SET status = 'queued', error = 'retrying: ' || COALESCE(error, ''), updated_at = ?
            WHERE status = 'failed' AND updated_at < ?");
        $st->execute([nb_now(), gmdate('Y-m-d\TH:i:s\Z', time() - $afterS)]);
        return $st->rowCount();
    });
}

/** Replace the whole leads table with $leads (the "leads" rows from mining/merge_leads.py). */
function nb_leads_replace(PDO $pdo, array $leads): void
{
    foreach ($leads as $l) {
        if (!in_array($l['strength'] ?? null, NB_LEAD_STRENGTHS, true)) {
            throw new InvalidArgumentException('lead strength must be one of ' . implode(', ', NB_LEAD_STRENGTHS));
        }
    }
    $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;
    nb_write($pdo, function () use ($pdo, $leads, $jsonFlags): void {
        $pdo->exec('DELETE FROM leads');
        $st = $pdo->prepare('INSERT INTO leads (name_key, name, strength, people, videos, mentions, likes, unsure_only,
            songs_json, video_ids_json, examples_json, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $now = nb_now();
        foreach ($leads as $l) {
            $st->execute([$l['name_key'], $l['name'], $l['strength'], (int)$l['people'], (int)$l['videos'],
                (int)$l['mentions'], (int)$l['likes'], empty($l['unsure_only']) ? 0 : 1,
                json_encode($l['songs'] ?? [], $jsonFlags), json_encode($l['video_ids'] ?? [], $jsonFlags),
                json_encode($l['examples'] ?? [], $jsonFlags), $now]);
        }
    });
}

/** Every lead, confirmed before hints, then by people, videos and likes; JSON fields decoded. */
function nb_leads(PDO $pdo): array
{
    $rows = nb_locked($pdo, fn() => $pdo->query("SELECT * FROM leads
        ORDER BY strength = 'confirmed' DESC, people DESC, videos DESC, likes DESC, name COLLATE NOCASE")->fetchAll());
    return array_map(function (array $r): array {
        foreach (['songs', 'video_ids', 'examples'] as $k) {
            $r[$k] = json_decode((string)$r["{$k}_json"], true, 512, JSON_THROW_ON_ERROR);
            unset($r["{$k}_json"]);
        }
        foreach (['people', 'videos', 'mentions', 'likes', 'unsure_only'] as $k) {
            $r[$k] = (int)$r[$k];
        }
        return $r;
    }, $rows);
}

/**
 * Grouping key for an artist name. Steps (must match norm() in mining/mentions.py exactly;
 * tests/test_mining.py checks they agree):
 *   (a) scrub invalid UTF-8 bytes first (mb_scrub), so a malformed byte can't make preg_replace()
 *       return null further down (which would otherwise collapse the whole key to an empty string);
 *   (b) trim whitespace at both ends: Unicode separators (\p{Z}) plus tab/LF/VT/FF/CR
 *       (\t\n\x0B\f\r) — an explicit set, not \s, and NBSP is a \p{Z} character so it's included;
 *   (c) lowercase;
 *   (d) NFKD-normalise and strip combining marks (\p{Mn});
 *   (e) lowercase again (NFKD can surface new uppercase letters, e.g. compatibility decompositions);
 *   (f) replace a final sigma "ς" with a regular sigma "σ";
 *   (g) drop a leading "the" followed by one or more of the same trim-set whitespace characters;
 *   (h) keep only letters and digits.
 * Requires the intl extension (for Normalizer); throws rather than silently degrading if it's missing.
 */
function nb_name_key(string $name): string
{
    if (!class_exists('Normalizer')) {
        throw new RuntimeException('nb_name_key needs the PHP intl extension');
    }
    $name = mb_scrub($name, 'UTF-8');
    $name = (string)preg_replace('/^[\p{Z}\t\n\x0B\f\r]+|[\p{Z}\t\n\x0B\f\r]+$/u', '', $name);
    $name = mb_strtolower($name);
    $name = (string)preg_replace('/\p{Mn}+/u', '', (string)Normalizer::normalize($name, Normalizer::FORM_KD));
    $name = mb_strtolower($name);
    $name = str_replace('ς', 'σ', $name);
    $name = (string)preg_replace('/^the[\p{Z}\t\n\x0B\f\r]+/u', '', $name);
    return (string)preg_replace('/[^\p{L}\p{N}]+/u', '', $name);
}

// ---------- settings and mutes ----------

function nb_setting(PDO $pdo, string $key, ?string $default = null): ?string
{
    $v = nb_locked($pdo, function () use ($pdo, $key) {
        $st = $pdo->prepare('SELECT value FROM settings WHERE key = ?');
        $st->execute([$key]);
        $v = $st->fetchColumn();
        $st->closeCursor();
        return $v;
    });
    return $v === false || $v === null ? $default : (string)$v;
}

function nb_setting_set(PDO $pdo, string $key, ?string $value): void
{
    nb_write($pdo, fn() => $value === null
        ? $pdo->prepare('DELETE FROM settings WHERE key = ?')->execute([$key])
        : $pdo->prepare('INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)')->execute([$key, $value]));
}

// ---------- out of Claude usage ----------
// When the agent or a mining child says the account's usage limit is reached, everything that would use Claude
// waits until the reset time the message gives: mining starts no new videos and automatic refills don't run.

/** The line of $text saying Claude usage is used up (e.g. "You've hit your weekly limit · resets 2pm (America/New_York)"), or null. */
function nb_usage_limit(?string $text): ?string
{
    foreach (preg_split('/\R/u', (string)$text) as $line) {
        if (preg_match("/you[’']ve (hit|reached) your\\b.*\\blimit\\b|\\busage limit reached\\b|\\blimit reached\\s*[·∙•|]/iu", $line)) {
            return trim($line);
        }
    }
    return null;
}

/**
 * When the usage in $message comes back, as a unix time: "resets 2pm (America/New_York)" (the next 2pm there;
 * no zone means this machine's), "resets 3:30am", or "limit reached|<unix time>", plus a minute's slack.
 * No time, or a zone PHP doesn't know: 30 minutes from $now. Never more than 8 days away.
 */
function nb_usage_reset_at(string $message, int $now): int
{
    $cap = $now + 8 * 86400;
    if (preg_match('/limit reached\s*\|\s*(\d{9,})/i', $message, $m)) {
        return min((int)$m[1] + 60, $cap);
    }
    if (preg_match('/resets\s+(\d{1,2})(?::(\d{2}))?\s*(am|pm)\b\s*(?:\(([^)]+)\))?/i', $message, $m)) {
        try {
            $tz = new DateTimeZone(($m[4] ?? '') !== '' ? trim($m[4]) : date_default_timezone_get());
        } catch (Exception) {
            return $now + 1800;
        }
        $hour = (int)$m[1] % 12 + (strtolower($m[3]) === 'pm' ? 12 : 0);
        $minute = (int)($m[2] ?? 0);
        $t = (new DateTimeImmutable("@$now"))->setTimezone($tz)->setTime($hour, $minute);
        if ($t->getTimestamp() <= $now) {
            $t = $t->modify('+1 day')->setTime($hour, $minute);
        }
        return min($t->getTimestamp() + 60, $cap);
    }
    return $now + 1800;
}

/** Record that Claude usage is used up; returns the unix time it's expected back. */
function nb_usage_pause(PDO $pdo, string $message, ?int $now = null): int
{
    $until = nb_usage_reset_at($message, $now ?? time());
    nb_write($pdo, function () use ($pdo, $until, $message): void {
        nb_setting_set($pdo, 'usage_paused_until', (string)$until);
        nb_setting_set($pdo, 'usage_pause_message', $message);
    });
    return $until;
}

/** ['until' => unix time, 'message' => text] while paused for usage, else null. */
function nb_usage_paused(PDO $pdo, ?int $now = null): ?array
{
    $until = (int)nb_setting($pdo, 'usage_paused_until', '0');
    if ($until <= ($now ?? time())) {
        return null;
    }
    return ['until' => $until, 'message' => (string)nb_setting($pdo, 'usage_pause_message', '')];
}

function nb_usage_clear(PDO $pdo): void
{
    nb_write($pdo, function () use ($pdo): void {
        nb_setting_set($pdo, 'usage_paused_until', null);
        nb_setting_set($pdo, 'usage_pause_message', null);
    });
}

function nb_mute_add(PDO $pdo, string $kind, string $value): int
{
    if (!in_array($kind, NB_MUTE_KINDS, true) || trim($value) === '') {
        throw new InvalidArgumentException('mute needs kind ' . implode('/', NB_MUTE_KINDS) . ' and a value');
    }
    return nb_write($pdo, function () use ($pdo, $kind, $value): int {
        $pdo->prepare('INSERT INTO mutes (kind, value, created_at) VALUES (?, ?, ?)')->execute([$kind, trim($value), nb_now()]);
        return (int)$pdo->lastInsertId();
    });
}

function nb_mutes(PDO $pdo): array
{
    return nb_locked($pdo, fn() => $pdo->query('SELECT * FROM mutes ORDER BY id')->fetchAll());
}
