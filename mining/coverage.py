"""Check that the extraction child wrote at least one line for every flagged comment.

Reads comment_index.json and every artists.chunk-*.md in the video folder. With --write-extra, writes the
comments nobody covered to chunk-extra.md (same format as the other chunks) for one re-run.
Prints, and writes to coverage.json:
    {"flagged": N, "covered": M, "missed": ["c7", ...], "unknown": ["c99", ...],
     "notes": ["artists.chunk-01.md:5: [song]", ...],
     "unparsed": ["artists.chunk-01.md:3: some prose", ...], "extra_chunk": name or null}
"unknown" lists ids the child wrote that aren't in the index. "notes" lists every annotation a [cN] line
had removed from its name (a bracket/paren note that wasn't the recognised own-artist/unsure tag) --
recorded, not dropped. "unparsed" lists lines that looked like they were meant to be a [cN] line (or just
prose) but didn't parse as one, so nothing is silently dropped either. A line whose [cN] has no artist,
song or tag at all (e.g. "- [c12]" alone) doesn't count as covering that id.

Usage: python3 mining/coverage.py <video folder> [--write-extra]
"""
import argparse
import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from mentions import chunk_header, comment_line, read_mentions, video_title  # noqa: E402


def coverage(folder: Path, write_extra: bool) -> dict:
    index = json.loads((folder / "comment_index.json").read_text(encoding="utf-8"))
    seen = set()      # ids with a mention that actually says something (artist, song or a tag)
    raw_ids = set()    # every id the child wrote a [cN] line for, regardless of content
    unparsed = []
    notes = []
    for path in sorted(folder.glob("artists.chunk-*.md")):
        file_unparsed = []
        file_notes = []
        for cid, artist, song, tag in read_mentions(path, file_unparsed, file_notes):
            key = f"c{cid}"
            raw_ids.add(key)
            if artist or song or tag:
                seen.add(key)
        unparsed.extend(f"{path.name}:{lineno}: {line}" for lineno, line in file_unparsed)
        notes.extend(file_notes)
    missed = [cid for cid in index if cid not in seen]
    unknown = sorted(raw_ids - set(index), key=lambda c: int(c[1:]))
    extra = None
    if write_extra and missed:
        title = video_title(index.values(), folder.name)
        lines = [comment_line(int(cid[1:]), index[cid]) for cid in missed]
        note = f"Re-run: {len(missed)} comments that got no line the first time"
        (folder / "chunk-extra.md").write_text("\n".join(chunk_header(title, folder.name, note) + lines) + "\n",
                                               encoding="utf-8")
        extra = "chunk-extra.md"
    result = {"flagged": len(index), "covered": len(index) - len(missed), "missed": missed,
              "unknown": unknown, "notes": notes, "unparsed": unparsed, "extra_chunk": extra}
    (folder / "coverage.json").write_text(json.dumps(result, indent=2), encoding="utf-8")
    return result


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("folder", type=Path)
    ap.add_argument("--write-extra", action="store_true")
    args = ap.parse_args()
    print(json.dumps(coverage(args.folder, args.write_extra)))


if __name__ == "__main__":
    main()
