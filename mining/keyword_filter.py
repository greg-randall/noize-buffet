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
# Comments that look like instructions aimed at Claude are quarantined, never flagged: written with their text to
# quarantined.jsonl and counted. Cruder than TypeSafe's question and easy to word around; confinement of the
# extraction child is the real protection, this only keeps the obvious attempts away from it.
INJECTION = [
    re.compile(r"\b(ignore|disregard|forget|override)\b[^.!?\n]{0,40}\b(instructions?|prompts?|rules|guidelines)\b",
               re.I),
    re.compile(r"\b(system prompt|jailbreak|prompt injection)\b", re.I),
    re.compile(r"\b(you are|you're|as) (an? )?(ai|a\.i\.|assistant|language model|llm|chatbot|bot)\b", re.I),
    re.compile(r"\b(ai|a\.i\.|assistant|llm|chatgpt|gpt|claude|haiku|model|bot)s?\b[^.!?\n]{0,40}\b(must|should|"
               r"please|now)\b[^.!?\n]{0,20}\b(write|delete|run|execute|output|reveal|print|read|say|reply|list)\b",
               re.I),
]


def flag(text: str) -> bool:
    text = REPLY_MENTION_RE.sub(" ", text)
    return any(p.search(text) for p in CUES)


def looks_like_injection(text: str) -> bool:
    return any(p.search(text) for p in INJECTION)


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
    quarantined = [c for c in comments if looks_like_injection(c["text"])]
    flagged = [c for c in comments if flag(c["text"]) and not looks_like_injection(c["text"])]
    folder = args.input.resolve().parent
    (folder / "quarantined.jsonl").write_text("".join(json.dumps(c, ensure_ascii=False) + "\n" for c in quarantined),
                                              encoding="utf-8")
    out = folder / "music_mentions_flagged.json"
    out.write_text(json.dumps({"filter": "keyword", "comments_checked": len(comments), "quarantined": len(quarantined),
                               "flagged": flagged}, indent=2, ensure_ascii=False), encoding="utf-8")
    print(f"{len(flagged):,} of {len(comments):,} comments flagged by keywords -> {out}; {len(quarantined):,} "
          f"quarantined as instructions aimed at Claude (quarantined.jsonl)", file=sys.stderr)


if __name__ == "__main__":
    main()
