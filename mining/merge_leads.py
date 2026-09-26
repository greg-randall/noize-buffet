"""Merge every mined video's extraction output into one ranked list of leads (JSON on stdout).

For each <comments folder>/<video_id>/ with a comment_index.json, reads artists.chunk-*.md, looks each [cN] up
in comment_index.json for its author, author id and likes, and groups the names by key (mentions.norm).

Lead strength (one person's comment isn't enough): a lead is "confirmed" when at least 2 different people
(by YouTube author id) name it, or it comes up under at least 2 of the user's liked videos; otherwise it's a
"hint". Entries tagged [own artist] and "none" are skipped and counted.

Nothing else is dropped:
- Every comment behind a lead is in its "examples".
- Several lines for the same comment and artist ("Deftones", then 'Deftones -- "White Pony"') count as one mention,
  one person and one like total, but merge their songs, spellings and unsure flag into it.
- A song-only line ('"Ready for It"', no artist) is never an artist lead. If the same comment names an artist,
  the song is attached to that artist (its "songs" and that comment's example). Otherwise it becomes a row in
  "songs_only" (same shape as a lead, without "strength"), for the user to see.
- A folder or file that can't be read, lines that can't be matched, and folders with only half their files are
  listed under "problems" or "notes"; one bad folder never fails the merge.

Output: {"leads": [...], "songs_only": [...], "skipped": {"own artist": N, "none": N}, "problems": ["..."],
"notes": ["..."]}

Usage: python3 mining/merge_leads.py <comments folder>
"""
import json
import sys
from collections import Counter
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from mentions import norm, read_mentions  # noqa: E402

READ_ERRORS = (OSError, UnicodeDecodeError, json.JSONDecodeError)


def _new_store() -> dict:
    return {"rows": {}, "seen": {}}  # seen: (video, key, cid) -> what the first line for that comment created


def _attach_song(hit: dict, song: str) -> None:
    if not song or song in hit["songs"]:
        return
    hit["songs"].append(song)
    hit["lead"]["songs"][song] += 1
    hit["example"]["song"] = " / ".join(hit["songs"])


def _add(store: dict, key: str, cid: int, vid: str, c: dict, name: str, song: str, tag: str, notes: list,
         noted: set) -> None:
    """Count one line for (key, comment). A repeat line for the same key and comment adds no mention, person or
    like, but merges its song, spelling and unsure flag into the first one."""
    hit = store["seen"].get((vid, key, cid))
    if hit is None:
        lead = store["rows"].setdefault(key, {"names": Counter(), "videos": set(), "people": set(), "mentions": 0,
                                              "likes": 0, "unsure": 0, "songs": Counter(), "examples": []})
        likes = int(c.get("like_count") or 0)
        person = c.get("author_id") or c.get("author") or f"{vid}:c{cid}"
        if not c.get("author_id") and (vid, cid) not in noted:
            noted.add((vid, cid))
            notes.append(f"{vid}/c{cid}: no author_id; counted by {person}")
        example = {"video_id": vid, "video_title": c.get("video_title", ""), "cid": f"c{cid}",
                   "author": c.get("author", ""), "likes": likes, "song": "", "unsure": tag == "unsure",
                   "text": c.get("text", "")}
        hit = {"lead": lead, "example": example, "songs": [], "names": {name}}
        store["seen"][(vid, key, cid)] = hit
        lead["names"][name] += 1
        lead["videos"].add(vid)
        lead["people"].add(person)
        lead["mentions"] += 1
        lead["likes"] += likes
        lead["unsure"] += tag == "unsure"
        lead["examples"].append(example)
    else:
        lead, example = hit["lead"], hit["example"]
        if name not in hit["names"]:
            hit["names"].add(name)
            lead["names"][name] += 1
        if tag != "unsure" and example["unsure"]:
            example["unsure"] = False
            lead["unsure"] -= 1
    _attach_song(hit, song)


def _rows(store: dict, songs_are_names: bool = False) -> list:
    rows = []
    for key, lead in store["rows"].items():
        rows.append({"name_key": key, "name": lead["names"].most_common(1)[0][0],
                     "people": len(lead["people"]), "videos": len(lead["videos"]), "mentions": lead["mentions"],
                     "likes": lead["likes"], "unsure_only": lead["unsure"] == lead["mentions"],
                     "songs": dict((lead["names"] if songs_are_names else lead["songs"]).most_common()),
                     "video_ids": sorted(lead["videos"]),
                     "examples": sorted(lead["examples"], key=lambda e: -e["likes"])})
    return rows


def _read_video(folder: Path, vid: str, skipped: dict, problems: list, notes: list):
    """(index, {cid: [(file, artist, song, tag), ...]}) for one video, or None if it can't be merged."""
    files = sorted(folder.glob("artists.chunk-*.md"))
    idx_path = folder / "comment_index.json"
    if not idx_path.exists():
        problems.append(f"{vid}/comment_index.json: missing (the folder has artists files)")
        return None
    try:
        index = json.loads(idx_path.read_text(encoding="utf-8"))
    except READ_ERRORS as e:
        problems.append(f"{vid}/comment_index.json: could not read: {e}")
        return None
    if not isinstance(index, dict):
        problems.append(f"{vid}/comment_index.json: could not read: not a JSON object")
        return None
    if not files:
        if index:  # an empty index is a video with no flagged comments: nothing to extract, nothing to report
            notes.append(f"{vid}: no extraction output yet")
        return None
    groups = {}  # cid -> the lines for that comment, in file order
    for path in files:
        unparsed, file_notes = [], []
        try:
            mentions = read_mentions(path, unparsed, file_notes)
        except READ_ERRORS as e:
            problems.append(f"{vid}/{path.name}: could not read: {e}")
            continue
        notes.extend(f"{vid}/{n}" for n in file_notes)
        problems.extend(f"{vid}/{path.name}:{n}: unreadable line: {line}" for n, line in unparsed)
        for cid, artist, song, tag in mentions:
            if tag in skipped:
                skipped[tag] += 1
            else:
                groups.setdefault(cid, []).append((path.name, artist, song, tag))
    return index, groups


def merge(root: Path) -> dict:
    leads, songs_only = _new_store(), _new_store()
    skipped = {"own artist": 0, "none": 0}
    problems = []
    notes = []  # bracket notes the child added ("[remix]", "(Radio Edit)"), author fallbacks, unfinished videos
    noted = set()
    folders = sorted({p.parent for p in root.glob("*/comment_index.json")}
                     | {p.parent for p in root.glob("*/artists.chunk-*.md")})
    for folder in folders:
        vid = folder.name
        video = _read_video(folder, vid, skipped, problems, notes)
        if video is None:
            continue
        index, groups = video
        for cid, lines in groups.items():
            c = index.get(f"c{cid}")
            if not isinstance(c, dict):
                problems.extend(f"{vid}/{fname}: [c{cid}] {artist} refers to no comment"
                                for fname, artist, _, _ in lines)
                continue
            artists, song_lines = [], []
            for fname, artist, song, tag in lines:
                key = norm(artist or "")
                if song and artist == f'(song) "{song}"':  # parse_mention's form for a line naming only a song
                    if norm(song):
                        song_lines.append((norm(song), song, tag))
                        continue
                    key = ""
                if not key:
                    problems.append(f"{vid}/{fname}: [c{cid}] name {artist!r} has no letters or digits")
                    continue
                artists.append((key, artist, song, tag))
            for key, artist, song, tag in artists:
                _add(leads, key, cid, vid, c, artist, song, tag, notes, noted)
            if artists:  # the comment names an artist: its song-only lines are that artist's songs
                for key in dict.fromkeys(a[0] for a in artists):
                    for _, song, _ in song_lines:
                        _attach_song(leads["seen"][(vid, key, cid)], song)
            else:
                for key, song, tag in song_lines:
                    _add(songs_only, key, cid, vid, c, song, "", tag, notes, noted)
    rows = _rows(leads)
    for r in rows:
        r["strength"] = "confirmed" if r["people"] >= 2 or r["videos"] >= 2 else "hint"
    rows.sort(key=lambda r: (r["strength"] != "confirmed", -r["people"], -r["videos"], -r["likes"], r["name"].lower()))
    song_rows = _rows(songs_only, songs_are_names=True)
    song_rows.sort(key=lambda r: (-r["people"], -r["videos"], -r["likes"], r["name"].lower()))
    return {"leads": rows, "songs_only": song_rows, "skipped": skipped, "problems": problems, "notes": notes}


def main():
    if len(sys.argv) != 2:
        sys.exit("usage: python3 mining/merge_leads.py <comments folder>")
    result = merge(Path(sys.argv[1]))
    print(json.dumps(result, ensure_ascii=False))
    confirmed = sum(r["strength"] == "confirmed" for r in result["leads"])
    print(f"{len(result['leads'])} leads ({confirmed} confirmed); {len(result['songs_only'])} songs without an "
          f"artist; skipped {result['skipped']}; {len(result['problems'])} problems", file=sys.stderr)


if __name__ == "__main__":
    main()
