"""Fallback when there's no TypeSafe key: flag comments that look like they name music, by keywords.

Much noisier than TypeSafe: it misses names mentioned without any cue, and flags plenty of comments that
name nothing (the extraction child then writes "none" for those). Writes music_mentions_flagged.json in the
input's folder in the same shape as find_music_mentions.py, so the rest of the pipeline doesn't care which
filter ran.

Usage: python3 mining/keyword_filter.py --input comments/ID/ID.info.json
"""
import argparse
import json
import re
import sys
from pathlib import Path

CUES = [
    re.compile(r"\b(sounds? like|reminds? me of|similar to|if you like|fans? of|check out|recommend\w*|"
               r"vibes? like|in the style of|reminiscent of|sampled?|samples|remix\w*|cover of|"
               r"feat\.?|ft\.?|prod\.?|produced by|album|mixtape|label|playlist)\b", re.I),
    re.compile(r"\S\s+[-–—]\s+\S"),          # "Artist - Song"
    re.compile(r"[\"“][^\"“”]{2,80}[\"”]"),  # a quoted title
]
REPLY_MENTION_RE = re.compile("\xa0@[^\xa0]+\xa0")  # YouTube wraps reply @handles in non-breaking spaces


def flag(text: str) -> bool:
    text = REPLY_MENTION_RE.sub(" ", text)
    return any(p.search(text) for p in CUES)


def load_comments(path: Path) -> list:
    info = json.loads(path.read_text(encoding="utf-8"))
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
