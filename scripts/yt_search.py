"""Search YouTube with yt-dlp and print JSON results.

Usage: python3 scripts/yt_search.py "artist song" ["another artist song" ...] [-n 5]
Output with one query: [{"video_id", "title", "channel", "duration_s", "url"}, ...]
Output with several queries: {"query": [results...], ...} (searches run one after another)
"""
import argparse
import json
import re
import subprocess
import sys

VIDEO_ID_RE = re.compile(r"^[A-Za-z0-9_-]{11}$")
FIELDS = "%(id)s\t%(title)s\t%(channel)s\t%(duration)s"


def parse_lines(text):
    """Parse yt-dlp --print output (tab-separated) into result dicts; skip non-video rows."""
    rows = []
    for line in text.splitlines():
        parts = line.split("\t")
        if len(parts) != 4 or not VIDEO_ID_RE.match(parts[0]):
            continue
        vid, title, channel, duration = parts
        try:
            duration_s = float(duration)
        except ValueError:
            duration_s = None
        rows.append({
            "video_id": vid,
            "title": title,
            "channel": channel,
            "duration_s": duration_s,
            "url": f"https://www.youtube.com/watch?v={vid}",
        })
    return rows


def search(query, n):
    cmd = ["yt-dlp", "--flat-playlist", "--print", FIELDS, f"ytsearch{n}:{query}"]
    proc = subprocess.run(cmd, capture_output=True, text=True, timeout=180)
    if proc.returncode != 0 and not proc.stdout.strip():
        raise RuntimeError(proc.stderr.strip() or f"yt-dlp exited {proc.returncode}")
    return parse_lines(proc.stdout)


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("queries", nargs="+", metavar="query")
    ap.add_argument("-n", type=int, default=5, help="number of results (default 5)")
    args = ap.parse_args()
    results = {}
    failed = False
    for q in args.queries:
        try:
            results[q] = search(q, args.n)
        except (RuntimeError, subprocess.TimeoutExpired, FileNotFoundError) as e:
            results[q] = {"error": str(e)}
            failed = True
    out = results[args.queries[0]] if len(args.queries) == 1 else results
    print(json.dumps(out, ensure_ascii=False, indent=2))
    sys.exit(1 if failed else 0)


if __name__ == "__main__":
    main()
