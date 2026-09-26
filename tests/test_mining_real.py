"""Mining scripts on real data (no network, no Claude).

tests/fixtures/mining/ holds real YouTube comments from three videos, with TypeSafe's flags and the prototype
Haiku's extractions, and every distinct line the prototype Haiku wrote across 36 videos. Built by the
outer project's scratch/build_mining_fixtures.py: comments with profanity left out, author handles anonymised.
"""
import json
import re
import shutil
import sys
from collections import defaultdict
from pathlib import Path

HERE = Path(__file__).resolve().parent
ROOT = HERE.parent
FIXTURES = HERE / "fixtures" / "mining"
TMP = HERE / "tmp" / "mining_real"
sys.path.insert(0, str(ROOT / "mining"))
import coverage  # noqa: E402
import mentions  # noqa: E402
import prepare  # noqa: E402

fails = 0


def check(cond, msg, examples=None):
    global fails
    print(("  ok    " if cond else "  FAIL  ") + msg)
    for ex in (examples or [])[:10] if not cond else []:
        print(f"          {ex!r}")
    fails += 0 if cond else 1


comments = [json.loads(line) for line in (FIXTURES / "real_comments.jsonl").read_text(encoding="utf-8").splitlines()]
real_lines = (FIXTURES / "real_mentions.txt").read_text(encoding="utf-8").splitlines()

print(f"parse_mention on {len(real_lines)} real Haiku lines")
parsed, crashed = {}, []
for line in real_lines:
    try:
        parsed[line] = mentions.parse_mention(line)
    except Exception as e:  # report every crash, not just the first
        crashed.append(f"{line}: {e}")
check(not crashed, "no line crashes the parser", crashed)
quoted = [(line, m.group(1).strip(), m.group(2)) for line in real_lines
          if (m := re.fullmatch(r'([^"\[]+?) — "([^"]+)"(?: \[(?:own artist|unsure)\])?', line))]
wrong = [(line, parsed[line]) for line, artist, song in quoted if parsed[line][:2] != (artist, song)]
check(quoted and not wrong, f'Artist — "Song" lines ({len(quoted)}) give that artist and song', wrong)
tagged = [line for line in real_lines if "[own artist]" in line]
wrong = [line for line in tagged if parsed[line][2] != "own artist"]
check(tagged and not wrong, f"[own artist] lines ({len(tagged)}) are tagged own artist", wrong)
leftover = [(line, parsed[line]) for line in real_lines if parsed[line][0] and re.search(r"[\[\]]", parsed[line][0])]
check(not leftover, "no brackets left in any artist name", leftover)
nokey = [(line, parsed[line]) for line in real_lines
         if parsed[line][2] != "none" and not mentions.norm(parsed[line][0] or "")]
check(not nokey, "every name that isn't 'none' gets a non-empty key", nokey)

print(f"prepare and coverage on {len(comments)} real comments")
by_video = defaultdict(list)
for c in comments:
    by_video[c["video_id"]].append(c)
for vid, rows in sorted(by_video.items()):
    flagged = [c for c in rows if c["typesafe_flagged"]]
    d = TMP / vid
    shutil.rmtree(d, ignore_errors=True)
    d.mkdir(parents=True)
    (d / "music_mentions_flagged.json").write_text(json.dumps({"flagged": flagged}, ensure_ascii=False),
                                                   encoding="utf-8")
    r = prepare.prepare(d, 10)
    check(r["flagged"] == len(flagged) and len(r["chunks"]) == (len(flagged) + 9) // 10,
          f"{vid}: {len(flagged)} flagged comments in chunks of 10")
    chunk_text = "".join((d / name).read_text(encoding="utf-8") for name in r["chunks"])
    check(all(f"[c{i}] " in chunk_text for i in range(1, len(flagged) + 1)), f"{vid}: every comment is in a chunk")
    # The prototype Haiku's real answers, rewritten in the [cN] format the new child uses.
    out = [f"- [c{i}] {m}" for i, c in enumerate(flagged, 1) for m in c["haiku_mentions"]]
    (d / "artists.chunk-01.md").write_text("\n".join(out) + "\n", encoding="utf-8")
    cov = coverage.coverage(d, write_extra=True)
    answered = {f"c{i}" for i, c in enumerate(flagged, 1) if c["haiku_mentions"]}
    every_id = {f"c{i}" for i in range(1, len(flagged) + 1)}
    check(cov["covered"] == len(answered) and set(cov["missed"]) == every_id - answered,
          f"{vid}: {cov['covered']} of {cov['flagged']} covered by the real answers; the rest listed as missed")
    check(not cov["unparsed"] and not cov["unknown"], f"{vid}: every real answer line parses and refers to a real id",
          cov["unparsed"] + cov["unknown"])

print("ALL PASSED" if fails == 0 else f"FAILED {fails}")
sys.exit(1 if fails else 0)
