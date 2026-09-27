# noize-buffet: architecture

How noize-buffet is built, for anyone changing it or debugging it. [README.md](README.md) covers what it does and how to start it.

## Processes

`start.py` checks the requirements (PHP 8.1+ with `pdo_sqlite`, `intl` and `mbstring`, GNU `timeout`, Python 3.10+ with pip, yt-dlp, the TypeSafe Python packages unless `--no-typesafe`, Claude Code and that it's logged in, and the TypeSafe key); for anything missing it prints the install commands for apt or brew. Then it starts three processes in their own process group and prefixes their output:

| Prefix | Process | Job |
|---|---|---|
| `[web]` | `php -S localhost:<port> -t player` (4 workers) | the page and `player/api.php` |
| `[agent]` | `php scripts/job_worker.php` | runs agent jobs one at a time |
| `[mine]` | `php scripts/mine_worker.php` | mines queued videos' comments, `mining_workers` at a time |

Ctrl+C sends SIGTERM to each group. `start.py --reset` finds this folder's leftover processes through `/proc` (the web server, the workers, `claude -p` processes, anything under `scripts/` or `mining/`, and `yt-dlp`), stops them, and deletes `data/`, `comments/`, `brief.md`, `taste.md` and `handoff.md` after a typed `RESET`.

## Database

One SQLite file, `data/music.sqlite` (`NB_DB` overrides it; the tests use this). Every access goes through `nb_locked()` in `lib/db.php`, an `flock()` on `<db>.lock`: SQLite's own locking relies on POSIX locks, which fail on WSL's 9p mount of a Windows drive ("database is locked"). `nb_write()` wraps a write in `BEGIN IMMEDIATE`; a write inside a write joins it.

| Table | Holds |
|---|---|
| `songs` | every song added: video id, artist, title, bucket (close, lead, sideways, wildcard, user), reason, source, batch |
| `batches` | each batch's summary and the job that made it |
| `listens` | per song: furthest %, rating, notes, off-brief, new-to-me, skipped, finished |
| `note_history` | notes replaced after they had stood for 10 minutes |
| `chat` | every message (user, parent, system) |
| `jobs` | agent jobs (interview, chat, refill): status, payload, result, error, session id |
| `mutes` | artists and lanes to stop suggesting |
| `settings` | key/value: the agent's session id, its current activity, the usage pause |
| `mining` | per video: status (queued, downloading, filtering, extracting, done, failed), filter used, counts, notes, error |
| `leads` | the merged leads, replaced after each mined video |
| `lead_youtube` | per lead (by `nb_name_key()`): the YouTube search looked up for it, its results and `max_views`; kept across merges |

Columns added after a table was created are added to older databases on start (`nb_create_tables()`).

## The agent

`scripts/job_worker.php` takes the oldest queued job and passes it to `nb_run_parent_job()` (`lib/parent.php`). When idle, it checks `nb_refill_check()` and queues a refill when `refill_when_left` or fewer songs are unplayed, unless the agent is busy, usage is paused, or the last two refills failed.

The agent is one long-lived `claude -p --input-format stream-json --output-format stream-json` process (`NbParentProcess`, `lib/parent_process.php`), kept between jobs to skip Claude Code's start-up time. Each job is one JSON line on stdin; the reply is the event stream up to the `result` event. The process is replaced after `session_rotate_turns` jobs, after an error, and after a job runs past `job_timeout_s`; the next one starts fresh, or with `--resume` when a worker restart finds a saved session id. Before a rotation, `nb_write_handoff()` asks the old conversation (resumed if the worker restarted) to write `handoff.md`: recent topics, unfinished work, open questions, and anything to add to `taste.md` or `brief.md`. A new conversation's first prompt tells it to read `brief.md`, `taste.md` and `handoff.md`. A handoff that fails is recorded in the job's debug file and the rotation goes ahead. Durable memory is those files and the database, not the conversation.

Its command line (`nb_parent_command()`):

- `--tools Read,Edit,Write,WebSearch,WebFetch,Bash` and `--allowedTools "WebSearch WebFetch Bash(php bin/nb.php *) Bash(python3 scripts/yt_search.py *) Bash(python3 scripts/spotify_playlist.py *)"`, with `--permission-mode acceptEdits`. Read, Edit and Write have no allow rule on purpose (a bare rule allows any path): reads in the repo need no approval, edits in the repo are accepted, and anything outside would need an approval a headless run can't give, so it is refused.
- `--settings` with `nb_parent_settings()`: `claudeMdExcludes` for every instruction file above the repo, in the user's `~/.claude`, and under `comments/` (so a mined video's `CLAUDE.md` never reaches it); hooks and auto memory off; and `permissions.deny` for reading or editing `.env` (and `NB_ENV_FILE`), since the agent reads comments written by strangers and can fetch web pages.
- `--disable-slash-commands`.

Tool calls stream into the `settings` table as the agent's current activity ("Searching YouTube (12 songs)"), which the page shows. Refused tools are posted to the chat. Each job writes `data/jobs/<id>.json`: prompt, process, duration, denials, every event, and the path to Claude Code's transcript.

The agent's only commands are `bin/nb.php` (JSON in and out, every call logged to `data/nb.log`), `scripts/yt_search.py` (yt-dlp search, several queries per call, with each video's view count) and `scripts/spotify_playlist.py` (reads public Spotify playlists from the embeddable player page, `open.spotify.com/embed/playlist/<id>`, whose page data carries the first 100 tracks; no account or key; one playlist a second; `--seed` checks a song is still on it):

| `nb.php` | Does |
|---|---|
| `queue`, `feedback [time\|all]`, `status` | read songs, listens since the last batch, counts (including mining) |
| `add-batch <file>` | add songs; reports added, duplicates and invalid ones |
| `note`, `set` | append a note; set rating and toggles from what the user said |
| `mute`, `mutes` | stop suggesting an artist or lane |
| `say <text>` | post to the chat at once, mid-job |
| `leads`, `lead <name>` | the mined leads (with `already_in_queue` and `muted`), and every comment behind one |

Its rules are in `CLAUDE.md`, including the **sideways** picks (Spotify playlists found by web search, DJ tracklists, Bandcamp buyers' collections, samples) and when a single person's recommendation may be used: specific (names a song) or liked 10+ times, and the artist under about a million YouTube views; one-person mentions of artists with 10 million+ views are skipped.

### Running out of usage

When the agent's error or a Haiku child's reply is a usage-limit message ("You've hit your weekly limit · resets 2pm (America/New_York)"), `nb_usage_pause()` stores the reset time from the message (30 minutes if it gives none, at most 8 days). Until then: the chat says so plainly and the agent's session is kept; the mining worker starts nothing, and the video in progress goes back to `queued` (the pipeline exits 75); refills don't run. `check_confinement.php` reports `INCONCLUSIVE`.

## The web API

`player/api.php` actions: `queue`, `save` (a listen), `chat` (long-poll for new messages and job state), `send` (a chat message), `start` (the interview, once), `mining` (rows, lead counts, usage pause). Every request must have a `Host` of `localhost`, `127.0.0.1` or `[::1]` (against DNS rebinding) and, if it has an `Origin`, the server's own; every POST must be `application/json`, which a cross-site page can't send without a CORS preflight the server never approves. Agent replies are rendered as Markdown through DOMPurify.

## Comment mining

Rating a song top or yes (`nb_save_listen()`), or adding it with bucket `user`, queues it in `mining`. On start, the worker also queues older top/yes songs (`nb_mining_backfill()`), puts videos interrupted mid-mining back in the queue, and retries videos that failed more than a day ago. It runs `scripts/mine_video.php <video_id>` for each, `mining_workers` at once, and marks a video `failed` if its pipeline dies without recording an outcome.

`mine_video.php`, in `comments/<video_id>/` (`NB_COMMENTS_DIR` overrides the folder), with subprocess output appended to `mine.log`:

1. **Download** (`downloading`): `yt-dlp --skip-download --write-comments --write-info-json --extractor-args youtube:comment_sort=top;max_comments=<mining_comment_cap>`, 30-minute limit, one retry after 2 minutes on HTTP 429. It then writes `own_artists.json`: the video's own artist by every name known (the song's artist, the title before " - ", yt-dlp's channel, uploader and artist fields, without " - Topic" or "VEVO").
2. **Filter** (`filtering`): `mining/find_music_mentions.py` with a TypeSafe key, else `mining/keyword_filter.py`. Writes `music_mentions_flagged.json` and `quarantined.jsonl`. What was skipped or quarantined goes in the row's `notes`.
3. **Number and chunk**: `mining/prepare.py` numbers the flagged comments `[c1]…`, writes `comment_index.json`, `flagged_comments.md` and `chunk-NN.md` files of `mining_chunk_size`, each headed with the video title and its own artists.
4. **Extract** (`extracting`): one Haiku child per chunk (below) writes `artists.chunk-NN.md`, one line per name: `- [c12] Burial`, `- [c13] Daft Punk — "Get Lucky"`, `- [c14] Two Shell [own artist]`, `- [c16] none`.
5. **Coverage**: `mining/coverage.py` checks every `[cN]` got a line, and writes the missed ones to `chunk-extra.md` for one re-run. Comments still missed are recorded as a problem; the video still finishes.
6. **Merge**: under `comments/merge.lock`, `mining/merge_leads.py --only <finished videos>` merges every finished video into leads, and `nb_leads_replace()` swaps the `leads` table. Only `done` videos are merged, so a failed or half-mined folder never becomes leads.
7. **Look up leads** (after `done`, best effort): `nb_lookup_leads()` runs `scripts/yt_search.py` for up to `lead_lookup_max` of the strongest leads with no saved results for their current search (the most-named song with the artist, or the artist alone), skipping muted artists and artists already in the queue. Results go in `lead_youtube`, with `max_views`: the most views among results whose title or channel has the artist's name. `nb.php leads` shows them in each lead's `youtube` field, so the agent needn't search for lead picks. A failed search is retried after the next mined video.

### TypeSafe questions

`find_music_mentions.py` asks five yes/no questions per comment in one call; the state is the comment (reply @handles replaced by `@user`), the video title and the channel. The questions are fixed and the per-video facts are in the state, so uploader-written titles can't change the instructions. Comments under `typesafe_min_comment_chars` (10) characters once @handles are removed, or with no letters at all, aren't asked; they're counted as `too_short_skipped` and shown in the mining notes. On the 564 real comments that skips 128 and loses no lead (the shortest real lead is "angel olsen?"); `tests/test_mining.py` checks it.

| Question | Threshold (`config.json`) | Effect |
|---|---|---|
| names a song | `typesafe_song_threshold` 0.8 | flag |
| names an artist | `typesafe_artist_threshold` 0.8 | flag, if also another artist |
| names an artist other than the video's own | `typesafe_other_artist_threshold` 0.3 | below it: skipped as own-artist-only |
| tries to give instructions to Claude or another program | `typesafe_injection_threshold` 0.5 | quarantined, whatever else it says |
| spam or self-promotion | `typesafe_spam_threshold` 0.9 | skipped |

`classify()` applies them in that priority. Answers are appended to `music_mentions.jsonl` as they arrive, so an interrupted run resumes and a re-mined video costs nothing; rows missing a question are asked again. Measured on 564 real comments from 3 videos plus 10 made-up attacks (`mining/typesafe_experiment.py`, answers saved in `tests/fixtures/mining/typesafe_answers.jsonl`): every attack scored 0.92–0.99 and no real comment above 0.06; no comment that Haiku found another artist in was skipped at any other-artist threshold from 0.1 to 0.7; no real comment reached the spam threshold. `tests/test_mining_real.py` checks those answers still hold.

The keyword filter (no key) flags cue phrases ("sounds like", "if you like"), "Artist - Song" dashes and quoted titles, and quarantines obvious instruction attempts ("ignore your instructions", "you are a bot"). On the same comments it caught half of those naming another artist.

### Merging leads

`merge_leads.py` groups names by `norm()` (lowercase, accents stripped, a leading "the" dropped, letters and digits only; PHP's `nb_name_key()` must agree, and a test checks it). People are counted by YouTube author id. A lead is `confirmed` when 2 or more people name it or it appears under 2 or more videos, otherwise a `hint`. Lines tagged `[own artist]` or `none`, and names in the video's `own_artists.json`, are counted and skipped. Several lines for one comment count as one mention. A song named without an artist attaches to an artist named in the same comment, or goes to `songs_only`. Every comment behind a lead is kept in its `examples`, most-liked first.

### Confinement of the Haiku children

`nb_run_child()` (`lib/mining.php`) runs, with the video folder as working directory:

    claude -p "Follow CLAUDE.md in this folder. Process chunk-01.md and write artists.chunk-01.md."
      --model haiku --tools Read,Write --restricted --strict-mcp-config --max-budget-usd 1.0
      --allowedTools "Edit(//<absolute path>/artists.chunk-01.md)" --output-format json
      --settings <isolation settings> --disable-slash-commands --no-session-persistence

`CLAUDE.md` in the folder is a copy of `mining/child_CLAUDE.md`. Under `--restricted` no instruction file loads by itself; the child reads `CLAUDE.md` because the prompt says to. The isolation settings exclude every other instruction file, turn off hooks and auto memory, and deny `.env`. `claude`, `python3`, `yt-dlp` and `timeout` are run by absolute path from `PATH`, skipping relative entries.

Around each run, the runner:

- **refuses to start** if a stray instruction file (`CLAUDE.local.md`, `AGENTS.md`, `.claude`) is in the folder, if a log or protected file is a symlink or not a regular file, or if another child holds the folder's lock;
- **snapshots** the protected files (`CLAUDE.md`, the comment files, every chunk, every other artists file) and records every other entry with `lstat`, inside existing subfolders too;
- **runs** the child under GNU `timeout` (`mining_child_timeout_s`, then SIGKILL);
- **checks** that the output is a regular, non-empty file inside the folder, and reads the result: timed out, the error result's subtype and messages, the exit code, or no output;
- **restores** any changed protected file, and reports every created, changed or removed entry as `tampered`; `mine_video.php` then fails the video and deletes all its extraction output;
- appends a summary (ok, error, time, cost, turns, refused tools, tampered) to `children.jsonl`.

`php scripts/check_confinement.php` checks this against the real `claude` (about five Haiku runs, about $0.05). It prints `PASS` when, in both permission modes, the child can write its output and can't write another file, write outside, read outside, or read a secret covered by the deny rule; a symlink at the output isn't followed; only the folder's `CLAUDE.md` reaches it, and only when told; it has only Read and Write and no MCP servers; and no session transcript is saved. It also shows what a tiny budget returns. With Claude Code 2.1.283 it passed with `mining_child_permission_mode` `""` and `"dontAsk"`.

## Settings (`config.json`)

| Key | Default | Meaning |
|---|---|---|
| `batch_size` | 12 | songs per batch |
| `mix` | 0.6 / 0.2 / 0.1 / 0.1 | share of close, lead, sideways and wildcard songs |
| `parent_model` | `sonnet` | the agent's Claude model |
| `session_rotate_turns` | 40 | jobs before the agent's conversation starts over |
| `job_timeout_s` | 600 | give up on a job (and restart the agent) after this long |
| `memory_picks` | 2 | most songs per batch picked from memory rather than research |
| `refill_when_left` | 5 | queue a batch automatically at this many unplayed songs; 0 turns it off |
| `mining_workers` | 2 | videos mined at once |
| `lead_lookup_max` | 20 | after mining a song, YouTube lookups for up to this many leads without one; 0 = off |
| `yt_search_parallel` | 4 | YouTube searches run at the same time when the agent looks up songs |
| `mining_comment_cap` | 3000 | top comments downloaded per video |
| `mining_chunk_size` | 150 | flagged comments per Haiku child |
| `mining_child_model` | `haiku` | the children's model |
| `mining_child_timeout_s` | 600 | kill a child after this long |
| `mining_child_max_budget_usd` | 1.0 | a child's spending cap |
| `mining_child_permission_mode` | `""` | `""` or `"dontAsk"` |
| `typesafe_*_threshold` | see above | TypeSafe cut-offs |
| `typesafe_min_comment_chars` | 10 | shorter comments (@handles aside) and letterless ones aren't sent to TypeSafe; 0 sends everything with a letter |

## Files

| Path | What |
|---|---|
| `start.py` | requirement checks, the three processes, `--reset` |
| `CLAUDE.md` | the agent's rulebook |
| `brief.md`, `taste.md`, `handoff.md` | the user's goal, the agent's notes, and its note to its next conversation (never committed) |
| `bin/nb.php` | the agent's database commands |
| `lib/db.php` | database, locking, songs, listens, jobs, mining queue, leads, usage pause |
| `lib/parent.php`, `lib/parent_process.php` | the agent's command, prompts, job runner, process |
| `lib/mining.php` | logged subprocess runner with time limits, the child command and runner, coverage |
| `lib/isolation.php` | the settings that hide other instruction files and deny `.env` |
| `lib/config.php` | defaults overlaid with `config.json` |
| `scripts/job_worker.php`, `scripts/mine_worker.php`, `scripts/mine_video.php` | the workers and the per-video pipeline |
| `scripts/yt_search.py` | YouTube search for the agent, with view counts |
| `scripts/spotify_playlist.py` | reads public Spotify playlists for the agent |
| `scripts/check_confinement.php` | the real confinement check |
| `mining/` | filters, `prepare.py`, `coverage.py`, `merge_leads.py`, `mentions.py` (shared parsing), `child_CLAUDE.md`, `typesafe_experiment.py` |
| `player/` | the page, `api.php`, `app.js` |
| `data/` | database, `jobs/<id>.json`, `nb.log`, `parent-stderr.log`, `api-errors.log` (never committed) |
| `comments/<video_id>/` | one mined video's files, described above (never committed) |
| `tests/` | the tests, their stand-ins and fixtures |

## Tests

    bash tests/run.sh

Runs every `tests/test_*.php` and `tests/test_*.py`. They use stand-ins, so they cost nothing and need no key: `tests/fake_claude_stream.php` (the agent), `tests/fake_mining_child.php` (a Haiku child, with switches to fail, time out, tamper or hit the usage limit), `tests/fake_ytdlp.php`, `tests/fake_python.py` (fakes the TypeSafe filter and records the mining status at every step), and `tests/fake_confinement_claude.php` (confined, leaky, or out of usage). `tests/fixtures/mining/` holds 564 real comments with Haiku's real answers, and TypeSafe's real answers to its five questions. Some checks skip themselves on filesystems that can't make FIFOs or ignore `chmod` (a Windows drive under WSL).

## When something goes wrong

- **The agent:** the `[agent]` lines show each tool call as it happens, then a line per job with status, time, turns, cost at API prices and whether the process was reused, then any `BLOCKED:` tools and the job's debug file. `data/jobs/<id>.json` has everything about one job; `data/nb.log` every database command with its input and output; `data/parent-stderr.log` anything `claude` printed to stderr.
- **The page:** `data/api-errors.log` has server errors with stack traces. The browser console logs every event with an `[nb]` prefix; type `nb` there to inspect the page's state.
- **Mining:** the `[mine]` lines show each video's steps. For one video, `comments/<video_id>/mine.log` has everything yt-dlp, the filter and the children printed; `children.jsonl` each child's result; `coverage.json` which comments got no line. The panel's Problems column shows the row's error, and Notes what the filter skipped.
- **Every Haiku child fails at start-up** and `mine.log` mentions MCP or a managed policy: a managed `managed-mcp.json` makes `--strict-mcp-config` exit at once. Remove that flag from `nb_child_command()`.
- **Mining never starts:** check the navbar for "mining paused … (out of Claude usage)".
