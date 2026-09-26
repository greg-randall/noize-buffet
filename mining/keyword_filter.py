"""Fallback when there's no TypeSafe key: flag comments that look like they name music, by keywords.

Much noisier than TypeSafe, and much less complete: measured on tests/fixtures/mining/real_comments.jsonl
(564 real comments from 3 videos, using TypeSafe's real flags and the prototype Haiku's real extractions),
among TypeSafe's 65 flagged comments, 36 actually name another artist per the real Haiku extraction; this
filter caught only 18 of those 36 (50%). It flagged 34 comments in total, and 15 of those weren't flagged
by TypeSafe at all -- skimming their text, none of the 15 names an artist. It misses names mentioned
without any cue phrase, dash, or quoted title (e.g. "Taylor Swift stole the flow" has none of those).
Writes music_mentions_flagged.json in the input's folder in the same shape as find_music_mentions.py, so
the rest of the pipeline doesn't care which filter ran.

Usage: python3 mining/keyword_filter.py --input comments/ID/ID.info.json
"""
import argparse
import json
import re
import sys
from pathlib import Path

CUES = [
    re.compile(r"\b(sounds? like|reminds? me of|similar to|if you like|fans? of|check out|recommend\w*|"
               r"vibes? like|vibes|think of|brought me here|in the style of|reminiscent of|sampled?|samples|"
               r"remix\w*|cover of|featuring|(?:feat|ft|prod)(?:\.|\b)|produced by|(?:new )?album by|"
               r"on (?:the )?label|mixtape|playlist|stole|ripped off)\b", re.I),
    re.compile(r"\w-esque\b", re.I),                                 # "Burial-esque"
    re.compile(r"(?<![\d:])\s+[-–—]\s+(?!\d)|\w[–—]\w"),             # "Artist - Song", not "3:45 - the drop"
    re.compile(r"[\"“][^\"“”]{2,80}[\"”]"),                          # a quoted title
]
REPLY_MENTION_RE = re.compile("\xa0@[^\xa0]+\xa0")  # YouTube wraps reply @handles in non-breaking spaces


def flag(text: str) -> bool:
    text = REPLY_MENTION_RE.sub(" ", text)
    return any(p.search(text) for p in CUES)


def load_comments(path: Path) -> list:
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
    return [{"video_id": info["id"], "video_title": info.get("title", ""), "comment_id": c["id"],
             "parent": c.get("parent", "root"), "author": c.get("author", ""), "author_id": c.get("author_id", ""),
             "like_count": c.get("like_count") or 0, "text": c.get("text", "")}
            for c in info.get("comments") or []]


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--input", type=Path, required=True, help="yt-dlp .info.json with comments")
    args = ap.parse_args()
    comments = load_comments(args.input)
    flagged = [c for c in comments if flag(c["text"])]
    out = args.input.resolve().parent / "music_mentions_flagged.json"
    out.write_text(json.dumps({"filter": "keyword", "comments_checked": len(comments), "flagged": flagged},
                              indent=2, ensure_ascii=False), encoding="utf-8")
    print(f"{len(flagged):,} of {len(comments):,} comments flagged by keywords -> {out}", file=sys.stderr)


if __name__ == "__main__":
    main()
