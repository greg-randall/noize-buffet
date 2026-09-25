# noize-buffet

An endless, ever-changing stream of music suggestions, built around your taste and run entirely on your own machine.

You chat with an AI agent in a local web page. It asks what you're after, then keeps adding batches of YouTube songs to a queue. You listen in the embedded player. It records how far you got, your rating, your notes and whether a song was new to you, and each new batch learns from that.

## Requirements

- [Claude Code](https://claude.com/claude-code), logged in with your own account (it runs the agent)
- PHP 8.1+ with `pdo_sqlite`
- Python 3 and [yt-dlp](https://github.com/yt-dlp/yt-dlp)
- A TypeSafe API key in `.env` (`start.py` creates `.env` from `.env.example` for you). Used by comment mining, coming in a later stage. `start.py` stops if the key is missing; to run without one, use `python3 start.py --no-typesafe`

## Start

    python3 start.py

It checks the requirements, creates `.env` if needed, starts the web server and the agent worker together, and prints the address: http://localhost:8000, or a random free port between 8001 and 8999 if 8000 is taken. `NB_PORT=8080 python3 start.py` uses exactly that port. Ctrl+C stops both.

## Where things live

- `brief.md`, `taste.md`: what you're after and what the agent has learned (you can edit both)
- `data/music.sqlite`: your queue and listening history (never committed)
- `config.json`: batch size, mix and model settings

## Tests

    bash tests/run.sh

## When something goes wrong

- **Worker terminal**: a `tool:` line for each tool the agent calls, as it happens; then one line per job with status, time, turns, API-equivalent cost and whether the agent process was started or reused; `BLOCKED:` lines if the agent tried a tool it isn't allowed; the path to the job's debug file.
- `data/jobs/<id>.json`: everything about one agent job: prompt, the agent process's pid and command, duration, blocked tools, every event the agent streamed back, and the path to Claude Code's full transcript of the conversation.
- The agent is one long-lived `claude` process, so replies after the first skip Claude Code's start-up time. It is restarted after `session_rotate_turns` messages, after any error, and when a job takes longer than `job_timeout_s` (config.json).
- `data/nb.log`: every database command the agent ran, with its exact input and output (one JSON object per line).
- `data/parent-stderr.log`: anything `claude` printed to stderr.
- `data/api-errors.log`: server-side errors from the web UI, with stack traces.

## License

[PolyForm Noncommercial 1.0.0](LICENSE): free for personal and other noncommercial use. For commercial use, contact me about a separate license.
