"""Search YouTube with yt-dlp and print JSON results.

Usage: python3 scripts/yt_search.py "artist song" ["another artist song" ...] [-n 5]
Output with one query: [{"video_id", "title", "channel", "duration_s", "views", "url"}, ...]
(views: the video's view count, a rough measure of how well known the song is; null if YouTube gave none)
Output with several queries: {"query": [results...], ...} in the order given. Searches run yt_search_parallel
(config.json, default 4) at a time; --parallel overrides it.
"""
import argparse
import json
import os
import re
import subprocess
import sys
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path

VIDEO_ID_RE = re.compile(r"^[A-Za-z0-9_-]{11}$")
FIELDS = "%(id)s\t%(title)s\t%(channel)s\t%(duration)s\t%(view_count)s"
CONFIG = Path(__file__).resolve().parent.parent / "config.json"
DEFAULT_PARALLEL = 4


def parallel_setting(config=CONFIG):
    """yt_search_parallel from config.json, at least 1; the default if the file or setting is missing or bad."""
    try:
        value = json.loads(config.read_text(encoding="utf-8")).get("yt_search_parallel", DEFAULT_PARALLEL)
    except (OSError, ValueError, AttributeError):
        return DEFAULT_PARALLEL
    return max(1, value) if isinstance(value, int) and not isinstance(value, bool) else DEFAULT_PARALLEL


def parse_lines(text):
    """Parse yt-dlp --print output (tab-separated) into result dicts; skip non-video rows."""
    rows = []
    for line in text.splitlines():
        parts = line.split("\t")
        if len(parts) != 5 or not VIDEO_ID_RE.match(parts[0]):
            continue
        vid, title, channel, duration, views = parts
        try:
            duration_s = float(duration)
        except ValueError:
            duration_s = None
        rows.append({
            "video_id": vid,
            "title": title,
            "channel": channel,
            "duration_s": duration_s,
            "views": int(views) if views.isdigit() else None,
            "url": f"https://www.youtube.com/watch?v={vid}",
        })
    return rows


def search(query, n):
    cmd = [os.environ.get("NB_YTDLP_BIN") or "yt-dlp", "--flat-playlist", "--print", FIELDS, f"ytsearch{n}:{query}"]
    proc = subprocess.run(cmd, capture_output=True, text=True, timeout=180)
    if proc.returncode != 0 and not proc.stdout.strip():
        raise RuntimeError(proc.stderr.strip() or f"yt-dlp exited {proc.returncode}")
    return parse_lines(proc.stdout)


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("queries", nargs="+", metavar="query")
    ap.add_argument("-n", type=int, default=5, help="number of results (default 5)")
    ap.add_argument("--parallel", type=int, help="searches at the same time (default: yt_search_parallel in "
                    f"config.json, or {DEFAULT_PARALLEL})")
    args = ap.parse_args()
    queries = list(dict.fromkeys(args.queries))  # a repeated query is searched once

    def one(q):
        try:
            return search(q, args.n)
        except (RuntimeError, subprocess.TimeoutExpired, FileNotFoundError) as e:
            return {"error": str(e)}
    with ThreadPoolExecutor(max_workers=max(1, args.parallel or parallel_setting())) as pool:
        results = dict(zip(queries, pool.map(one, queries)))
    failed = any(isinstance(r, dict) for r in results.values())
    out = results[queries[0]] if len(queries) == 1 else results
    print(json.dumps(out, ensure_ascii=False, indent=2))
    sys.exit(1 if failed else 0)


if __name__ == "__main__":
    main()
