#!/usr/bin/env python3
"""Ask TypeSafe two yes/no questions about every YouTube comment:
does it name a song, and does it name a musical artist?

Reads one yt-dlp .info.json (--input). Each comment's text alone is one TypeSafe call carrying both
questions. Outputs go in the input file's folder: results are appended to
music_mentions.jsonl as they arrive, so an interrupted run resumes where it
stopped. At the end every row is written to music_mentions.csv, and comments
at or above either threshold to music_mentions_flagged.json.

Usage:
    python3 mining/find_music_mentions.py --input comments/ID/ID.info.json
    python3 mining/find_music_mentions.py --input comments/ID/ID.info.json --debug 100 --verbose
    python3 mining/find_music_mentions.py --input comments/ID/ID.info.json --song-threshold 0.7 --artist-threshold 0.6
"""

import argparse
import asyncio
import csv
import json
import os
import re
import sys
from pathlib import Path

from aiolimiter import AsyncLimiter
from dotenv import load_dotenv
from tqdm import tqdm
from typesafe_sdk import AsyncTypeSafeClient, Noul, NoulCriteria, RetryPolicy, TypeSafeError

HERE = Path(__file__).resolve().parent
# output file names, written in the input file's folder
RESULTS_JSONL = "music_mentions.jsonl"
RESULTS_CSV = "music_mentions.csv"
RESULTS_FLAGGED = "music_mentions_flagged.json"
RESULTS_FLAGGED_DEBUG = "music_mentions_flagged.debug.json"  # --debug writes here, never over the real file

# jev-1.13: $0.042 per million input tokens, output tokens free (https://docs.typesafe.ai/models, 2026-09)
USD_PER_INPUT_TOKEN = 0.042 / 1e6

# jev-1.13 limit is 1,200 requests/minute MAX (docs say it may change). Don't set --rpm above 1200.
# 1150 leaves ~50/min headroom; the limiter meters per second, so bursts can't overshoot by much.
DEFAULT_RPM = 1150
# SDK default is 2 retries with backoff up to 5s; give 429s and blips more room on long runs
RETRY = RetryPolicy(max_retries=6, backoff_max=30.0)

# YouTube wraps reply mentions in non-breaking spaces: "\xa0@handle\xa0"
REPLY_MENTION_RE = re.compile("\xa0@[^\xa0]+\xa0")


def make_state(comment: dict) -> dict:
    """What TypeSafe sees: the comment text with reply @handles replaced by @user."""
    return {"youtube_comment": REPLY_MENTION_RE.sub("@user", comment["text"])}


QUESTIONS = {
    "mentions_song": Noul(
        instructions="Does `youtube_comment` name a specific song by its title?",
        criteria=NoulCriteria(
            true="The comment contains the title of a song.",
            false="The comment contains no song title; it may still refer to a song without naming it.",
        ),
    ),
    "mentions_artist": Noul(
        instructions="Does `youtube_comment` name a musical artist?",
        criteria=NoulCriteria(
            true="The comment contains the name of a musician, singer, band, rapper, DJ, or producer.",
            false="The comment contains no musical artist's name; it may still refer to one without naming them.",
        ),
    ),
}


def load_api_key(env_file: Path) -> str:
    load_dotenv(env_file)
    # the .env uses TYPESAFE_API; the SDK's own name is TYPESAFE_API_KEY
    key = os.environ.get("TYPESAFE_API_KEY") or os.environ.get("TYPESAFE_API")
    if not key:
        exists = "exists" if env_file.is_file() else "does not exist"
        sys.exit(f"No TYPESAFE_API_KEY or TYPESAFE_API found in environment or {env_file} ({exists})")
    return key


def load_comments(path: Path) -> list[dict]:
    if not path.is_file():
        sys.exit(f"no such file: {path}")
    try:
        info = json.loads(path.read_text(encoding="utf-8"))
    except json.JSONDecodeError:
        sys.exit(f"not valid JSON: {path}")
    if "comments" not in info:
        sys.exit(f"no 'comments' key in {path}: was it downloaded with --write-comments?")
    if "id" not in info:
        sys.exit(f"no 'id' key in {path}: is this a yt-dlp .info.json?")
    return [{
        "video_id": info["id"],
        "video_title": info.get("title", ""),
        "comment_id": c["id"],
        "parent": c.get("parent", "root"),
        "author": c.get("author", ""),
        "author_id": c.get("author_id", ""),
        "like_count": c.get("like_count") or 0,
        "text": c.get("text", ""),
    } for c in info.get("comments") or []]


def load_done(jsonl: Path) -> set[tuple[str, str]]:
    done = set()
    if jsonl.exists():
        with jsonl.open(encoding="utf-8") as f:
            for line in f:
                row = json.loads(line)
                done.add((row["video_id"], row["comment_id"]))
    return done


async def ask(client, comment, model, verbose) -> dict:
    """One TypeSafe call for one comment; returns its result row."""
    state = make_state(comment)
    response = await client.system_one(state=state, questions=QUESTIONS, model=model)
    if verbose:
        questions = {k: q.model_dump(mode="json") for k, q in QUESTIONS.items()}
        request = json.dumps({"model": model, "state": state, "questions": questions}, indent=2, ensure_ascii=False)
        tqdm.write(f"\n===== {comment['comment_id']} =====\n--- INPUT ---\n{request}\n"
                   f"--- OUTPUT ---\n{response.model_dump_json(indent=2)}")
    return {
        **comment,
        "p_song": response.answers["mentions_song"].noul,
        "p_artist": response.answers["mentions_artist"].noul,
        "input_tokens": response.usage.input_tokens,
        "output_tokens": response.usage.output_tokens,
        "model": model,
    }


async def run(todo, jsonl, api_key, model, concurrency, rpm, verbose):
    """Ask every comment in parallel, appending each row to the JSONL as it arrives."""
    sem = asyncio.Semaphore(concurrency)
    # rate spread per second so the burst never exceeds rpm/60 requests
    limiter = AsyncLimiter(rpm / 60, 1)
    lock = asyncio.Lock()
    stats = {"failed": 0, "input_tokens": 0}

    async def one(client, comment, out, bar):
        async with sem, limiter:
            try:
                row = await ask(client, comment, model, verbose)
            except TypeSafeError as e:
                stats["failed"] += 1
                tqdm.write(f"FAILED {comment['video_id']}/{comment['comment_id']}: {e!r}")
                bar.update(1)
                return
        async with lock:
            out.write(json.dumps(row, ensure_ascii=False) + "\n")
            out.flush()
        stats["input_tokens"] += row["input_tokens"]
        bar.update(1)

    # a piped/redirected stderr isn't a TTY: update the bar every 5s instead of on every tick, or logs flood
    bar_kwargs = {} if sys.stderr.isatty() else {"mininterval": 5}
    with jsonl.open("a", encoding="utf-8") as out, \
            tqdm(total=len(todo), desc="asking TypeSafe", unit="comment", **bar_kwargs) as bar:
        async with AsyncTypeSafeClient(api_key=api_key, retry=RETRY) as client:
            await asyncio.gather(*(one(client, c, out, bar) for c in todo))
    return stats


async def debug(comments, api_key, model, verbose, song_t, artist_t) -> list[dict]:
    """Ask comments one at a time and print each result. Writes nothing to the JSONL/CSV."""
    rows = []
    async with AsyncTypeSafeClient(api_key=api_key, retry=RETRY) as client:
        for i, comment in enumerate(comments, 1):
            row = await ask(client, comment, model, verbose)
            rows.append(row)
            if not verbose:
                flags = ("S" if row["p_song"] >= song_t else "-") + ("A" if row["p_artist"] >= artist_t else "-")
                text = make_state(comment)["youtube_comment"].replace("\n", " ")
                print(f"{i:>4} {flags} song {row['p_song']:.2f} artist {row['p_artist']:.2f} | {text}")
    return rows


def write_csv(jsonl: Path, csv_path: Path) -> list[dict]:
    rows = []
    if jsonl.exists():  # absent when the video has no comments
        with jsonl.open(encoding="utf-8") as f:
            rows = [json.loads(line) for line in f]
    fields = ["video_id", "video_title", "comment_id", "parent", "author", "author_id", "like_count",
              "p_song", "p_artist", "text", "input_tokens", "output_tokens", "model"]
    with csv_path.open("w", encoding="utf-8-sig", newline="") as f:
        writer = csv.DictWriter(f, fieldnames=fields)
        writer.writeheader()
        writer.writerows(rows)
    return rows


def refresh_rows(rows: list[dict], comments: list[dict]) -> tuple[list[dict], int]:
    """Overlay each row's video/comment fields (title, author, likes, text) with the current info.json,
    since a row may have been written in an earlier run against an older copy of the file. A row whose
    comment id is no longer present (the comment was deleted, or a different --input was passed) is left
    out of the return value; its count is returned too so the caller can report it instead of silently
    dropping it."""
    by_id = {(c["video_id"], c["comment_id"]): c for c in comments}
    refreshed, missing = [], 0
    for row in rows:
        key = (row["video_id"], row["comment_id"])
        if key in by_id:
            refreshed.append({**row, **by_id[key]})
        else:
            missing += 1
    return refreshed, missing


def write_flagged(rows, flagged_path, song_t, artist_t, comments_total, failed) -> list[dict]:
    flagged = []
    for row in rows:
        song, artist = row["p_song"] >= song_t, row["p_artist"] >= artist_t
        if song or artist:
            flagged.append({**row, "song": song, "artist": artist})
    flagged_path.write_text(json.dumps({
        "song_threshold": song_t,
        "artist_threshold": artist_t,
        "comments_checked": len(rows),
        "comments_total": comments_total,
        "failed": failed,
        "flagged": flagged,
    }, indent=2, ensure_ascii=False), encoding="utf-8")
    return flagged


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--input", type=Path, required=True,
                        help="yt-dlp .info.json to read; outputs go in its folder")
    parser.add_argument("--env", type=Path, default=HERE.parent / ".env", help="path to .env with the API key")
    parser.add_argument("--model", default="jev-latest", help="TypeSafe model (default: jev-latest)")
    parser.add_argument("--concurrency", type=int, default=16, help="max requests in flight (default: 16)")
    parser.add_argument("--rpm", type=float, default=DEFAULT_RPM,
                        help=f"max requests per minute (default: {DEFAULT_RPM}; TypeSafe limit is 1,200)")
    parser.add_argument("--song-threshold", type=float, default=0.8,
                        help="flag a comment as naming a song at p >= this (default: 0.8)")
    parser.add_argument("--artist-threshold", type=float, default=0.8,
                        help="flag a comment as naming an artist at p >= this (default: 0.8)")
    parser.add_argument("--debug", type=int, metavar="N",
                        help="ask only the first N comments, one at a time; nothing written to the JSONL/CSV, "
                             f"and flagged rows go to {RESULTS_FLAGGED_DEBUG} instead of {RESULTS_FLAGGED}")
    parser.add_argument("--verbose", action="store_true", help="print the full TypeSafe input and output per comment")
    args = parser.parse_args()

    input_path = args.input
    out_dir = input_path.resolve().parent
    jsonl, csv_path = out_dir / RESULTS_JSONL, out_dir / RESULTS_CSV
    # --debug never touches the real flagged file: it only asks a handful of comments, one at a time
    flagged_path = out_dir / (RESULTS_FLAGGED_DEBUG if args.debug else RESULTS_FLAGGED)

    api_key = load_api_key(args.env)
    comments = load_comments(input_path)
    print(f"input: {input_path}", file=sys.stderr)

    failed = 0
    if args.debug:
        rows = asyncio.run(debug(comments[:args.debug], api_key, args.model, args.verbose,
                                 args.song_threshold, args.artist_threshold))
    else:
        done = load_done(jsonl)
        todo = [c for c in comments if (c["video_id"], c["comment_id"]) not in done]
        print(f"{len(comments):,} comments, {len(comments) - len(todo):,} already done, {len(todo):,} to ask",
              file=sys.stderr)
        if todo:
            print(f"rate limit {args.rpm:,.0f}/min -> at least {len(todo) / args.rpm:,.1f} minutes", file=sys.stderr)
            stats = asyncio.run(run(todo, jsonl, api_key, args.model, args.concurrency, args.rpm, args.verbose))
            failed = stats["failed"]
            print(f"this run: {stats['input_tokens']:,} input tokens, "
                  f"${stats['input_tokens'] * USD_PER_INPUT_TOKEN:.6f}; failed: {stats['failed']:,}"
                  + (" (re-run to retry them)" if stats["failed"] else ""), file=sys.stderr)
        rows = write_csv(jsonl, csv_path)
        print(f"{len(rows):,} rows -> {csv_path}", file=sys.stderr)

    tokens = sum(r["input_tokens"] for r in rows)
    label = "this debug run" if args.debug else "all rows so far"
    print(f"{label}: {tokens:,} input tokens, ${tokens * USD_PER_INPUT_TOKEN:.6f} "
          f"(${tokens * USD_PER_INPUT_TOKEN / max(len(rows), 1) * 1000:.4f} per 1,000 comments)", file=sys.stderr)

    refreshed, missing = refresh_rows(rows, comments)
    if missing:
        print(f"{missing:,} rows in {jsonl} refer to comments no longer in {input_path}; "
              f"excluded from {flagged_path}", file=sys.stderr)

    flagged = write_flagged(refreshed, flagged_path, args.song_threshold, args.artist_threshold,
                            len(comments), failed)
    songs = sum(f["song"] for f in flagged)
    artists = sum(f["artist"] for f in flagged)
    print(f"{len(flagged):,} of {len(refreshed):,} flagged -> {flagged_path} "
          f"(song >= {args.song_threshold}: {songs:,}, artist >= {args.artist_threshold}: {artists:,})",
          file=sys.stderr)

    if failed:
        sys.exit(f"{failed:,} comments failed at TypeSafe; re-run to retry them, finished results are kept")


if __name__ == "__main__":
    main()
