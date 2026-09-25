"""Tests for scripts/yt_search.py (no network)."""
import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "scripts"))
import yt_search  # noqa: E402

fails = 0


def check(cond, msg):
    global fails
    print(("  ok    " if cond else "  FAIL  ") + msg)
    fails += 0 if cond else 1


sample = "\n".join([
    "UCabcdefghijklmnopqrstuv\tSome Channel\tSome Channel\tNA",
    "AAAAAAAAAAA\tSong A (Official Video)\tArtist A\t201",
    "BBBBBBBBBBB\tSong B\tLabel\tNA",
    "garbage line",
])
rows = yt_search.parse_lines(sample)
check([r["video_id"] for r in rows] == ["AAAAAAAAAAA", "BBBBBBBBBBB"], "channel results and garbage dropped")
check(rows[0]["duration_s"] == 201.0 and rows[1]["duration_s"] is None, "durations parsed, NA -> None")
check(rows[0]["url"] == "https://www.youtube.com/watch?v=AAAAAAAAAAA", "url built")
check(rows[0]["title"] == "Song A (Official Video)" and rows[0]["channel"] == "Artist A", "title and channel")

import subprocess  # noqa: E402
script = os.path.join(os.path.dirname(__file__), "..", "scripts", "yt_search.py")
help_text = subprocess.run([sys.executable, script, "-h"], capture_output=True, text=True).stdout
check("query" in help_text and "..." in help_text, "CLI accepts several queries")

print("ALL PASSED" if fails == 0 else f"FAILED {fails}")
sys.exit(1 if fails else 0)
