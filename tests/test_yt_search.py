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
    "UCabcdefghijklmnopqrstuv\tSome Channel\tSome Channel\tNA\tNA",
    "AAAAAAAAAAA\tSong A (Official Video)\tArtist A\t201\t391278003",
    "BBBBBBBBBBB\tSong B\tLabel\tNA\tNA",
    "garbage line",
])
rows = yt_search.parse_lines(sample)
check([r["video_id"] for r in rows] == ["AAAAAAAAAAA", "BBBBBBBBBBB"], "channel results and garbage dropped")
check(rows[0]["duration_s"] == 201.0 and rows[1]["duration_s"] is None, "durations parsed, NA -> None")
check(rows[0]["url"] == "https://www.youtube.com/watch?v=AAAAAAAAAAA", "url built")
check(rows[0]["title"] == "Song A (Official Video)" and rows[0]["channel"] == "Artist A", "title and channel")
check(rows[0]["views"] == 391278003 and rows[1]["views"] is None, "view counts parsed, NA -> None")
check("%(view_count)s" in yt_search.FIELDS, "and asked of yt-dlp")

import subprocess  # noqa: E402
script = os.path.join(os.path.dirname(__file__), "..", "scripts", "yt_search.py")
help_text = subprocess.run([sys.executable, script, "-h"], capture_output=True, text=True).stdout
check("query" in help_text and "..." in help_text, "CLI accepts several queries")

import json  # noqa: E402
import stat  # noqa: E402
import tempfile  # noqa: E402
import time  # noqa: E402
from pathlib import Path  # noqa: E402

with tempfile.TemporaryDirectory() as tmp:
    tmp = Path(tmp)
    # a fake yt-dlp on PATH: each search takes a second and returns one result named after the query
    fake = tmp / "yt-dlp"
    fake.write_text("#!/bin/sh\nsleep 1\nq=\"${4#ytsearch*:}\"\n"
                    "[ \"$q\" = fail ] && { echo boom >&2; exit 1; }\n"
                    "printf 'aaaaaaaaaaa\\t%s\\tch\\t200\\t5\\n' \"$q\"\n")
    fake.chmod(fake.stat().st_mode | stat.S_IEXEC)
    env = {**os.environ, "PATH": f"{tmp}:{os.environ['PATH']}"}

    def run(*args):
        start = time.monotonic()
        p = subprocess.run([sys.executable, script, *args], capture_output=True, text=True, env=env)
        return p, time.monotonic() - start
    p, took = run("d", "c", "b", "a", "--parallel", "4")
    out = json.loads(p.stdout)
    check(p.returncode == 0 and list(out) == ["d", "c", "b", "a"] and out["b"][0]["title"] == "b",
          "several queries: results in the order given")
    check(took < 2.5, f"4 searches at a time take about as long as one ({took:.1f}s)")
    p, took = run("x", "y", "--parallel", "1")
    check(took >= 2, f"--parallel 1 runs them one after another ({took:.1f}s)")
    p, _ = run("ok", "fail", "--parallel", "2")
    out = json.loads(p.stdout)
    check(p.returncode == 1 and out["fail"] == {"error": "boom"} and out["ok"][0]["title"] == "ok",
          "one failed search is reported without losing the others")

    cfg = tmp / "config.json"
    for text, want in (('{"yt_search_parallel": 6}', 6), ('{"yt_search_parallel": 0}', 1), ('{}', 4),
                       ('{"yt_search_parallel": "x"}', 4), ('not json', 4)):
        cfg.write_text(text)
        check(yt_search.parallel_setting(cfg) == want, f"config {text} -> {want} at a time")
    check(yt_search.parallel_setting(tmp / "missing.json") == 4, "no config.json -> 4 at a time")

print("ALL PASSED" if fails == 0 else f"FAILED {fails}")
sys.exit(1 if fails else 0)
