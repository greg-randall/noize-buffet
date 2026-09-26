"""Merge every mined video's extraction output into one ranked list of leads (JSON on stdout).

For each <comments folder>/<video_id>/ with a comment_index.json, reads artists.chunk-*.md, looks each [cN] up
in comment_index.json for its author, author id and likes, and groups the names by key (mentions.norm).

Lead strength (one person's comment isn't enough): a lead is "confirmed" when at least 2 different people
(by YouTube author id) name it, or it comes up under at least 2 of the user's liked videos; otherwise it's a
"hint". Entries tagged [own artist] and "none" are skipped and counted. Nothing else is dropped: every comment
behind a lead is in its "examples", and lines that can't be matched are listed under "problems".

Output: {"leads": [...], "skipped": {"own artist": N, "none": N}, "problems": ["..."], "notes": ["..."]}

Usage: python3 mining/merge_leads.py <comments folder>
"""
import json
import sys
from collections import Counter
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from mentions import norm, read_mentions  # noqa: E402


def merge(root: Path) -> dict:
    leads = {}
    skipped = {"own artist": 0, "none": 0}
    problems = []
    notes = []  # bracket notes the child added ("[remix]", "(Radio Edit)"), kept for the record
    for idx_path in sorted(root.glob("*/comment_index.json")):
        folder = idx_path.parent
        vid = folder.name
        index = json.loads(idx_path.read_text(encoding="utf-8"))
        seen = set()  # (key, cid): one comment counts once per lead even if the child listed it twice
        for path in sorted(folder.glob("artists.chunk-*.md")):
            unparsed = []
            mentions = read_mentions(path, unparsed, notes)
            problems += [f"{vid}/{path.name}:{n}: unreadable line: {line}" for n, line in unparsed]
            for cid, artist, song, tag in mentions:
                if tag in skipped:
                    skipped[tag] += 1
                    continue
                c = index.get(f"c{cid}")
                key = norm(artist or "")
                if c is None:
                    problems.append(f"{vid}/{path.name}: [c{cid}] {artist} refers to no comment")
                    continue
                if not key:
                    problems.append(f"{vid}/{path.name}: [c{cid}] name {artist!r} has no letters or digits")
                    continue
                if (key, cid) in seen:
                    continue
                seen.add((key, cid))
                lead = leads.setdefault(key, {"names": Counter(), "videos": set(), "people": set(), "mentions": 0,
                                              "likes": 0, "unsure": 0, "songs": Counter(), "examples": []})
                likes = int(c.get("like_count") or 0)
                lead["names"][artist] += 1
                lead["videos"].add(vid)
                lead["people"].add(c.get("author_id") or c.get("author") or f"{vid}:c{cid}")
                lead["mentions"] += 1
                lead["likes"] += likes
                lead["unsure"] += tag == "unsure"
                if song:
                    lead["songs"][song] += 1
                lead["examples"].append({"video_id": vid, "video_title": c.get("video_title", ""), "cid": f"c{cid}",
                                         "author": c.get("author", ""), "likes": likes, "song": song,
                                         "unsure": tag == "unsure", "text": c.get("text", "")})
    rows = []
    for key, lead in leads.items():
        people, videos = len(lead["people"]), len(lead["videos"])
        rows.append({"name_key": key, "name": lead["names"].most_common(1)[0][0],
                     "strength": "confirmed" if people >= 2 or videos >= 2 else "hint",
                     "people": people, "videos": videos, "mentions": lead["mentions"], "likes": lead["likes"],
                     "unsure_only": lead["unsure"] == lead["mentions"], "songs": dict(lead["songs"].most_common()),
                     "video_ids": sorted(lead["videos"]),
                     "examples": sorted(lead["examples"], key=lambda e: -e["likes"])})
    rows.sort(key=lambda r: (r["strength"] != "confirmed", -r["people"], -r["videos"], -r["likes"], r["name"].lower()))
    return {"leads": rows, "skipped": skipped, "problems": problems, "notes": notes}


def main():
    if len(sys.argv) != 2:
        sys.exit("usage: python3 mining/merge_leads.py <comments folder>")
    result = merge(Path(sys.argv[1]))
    print(json.dumps(result, ensure_ascii=False))
    confirmed = sum(r["strength"] == "confirmed" for r in result["leads"])
    print(f"{len(result['leads'])} leads ({confirmed} confirmed); skipped {result['skipped']}; "
          f"{len(result['problems'])} problems", file=sys.stderr)


if __name__ == "__main__":
    main()
