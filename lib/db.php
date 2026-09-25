<?php
declare(strict_types=1);

const NB_RATINGS = ['top', 'yes', 'good', 'ok', 'meh', 'no'];
const NB_BUCKETS = ['close', 'lead', 'wildcard', 'user'];
const NB_JOB_KINDS = ['interview', 'chat'];
const NB_MUTE_KINDS = ['artist', 'lane'];
const NB_NOTE_HISTORY_AFTER_S = 600; // keep an old note only if last changed 10+ minutes ago
const NB_VIDEO_ID_RE = '/^[A-Za-z0-9_-]{11}$/';

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
    ] as $sql) {
        $pdo->exec($sql);
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
    $deadline = microtime(true) + NB_LOCK_WAIT_S;
    while (!flock($h, LOCK_EX | LOCK_NB)) {
        if (microtime(true) >= $deadline) {
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

/** Run $fn in a write transaction (under the lock) and return its result. */
function nb_write(PDO $pdo, callable $fn): mixed
{
    return nb_locked($pdo, function () use ($pdo, $fn) {
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $result = $fn();
            $pdo->exec('COMMIT');
            return $result;
        } catch (Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
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
