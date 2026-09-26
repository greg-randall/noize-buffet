"""Number a video's flagged comments and split them into chunks for the extraction child.

Reads music_mentions_flagged.json in the video folder and writes there:
    comment_index.json   {"c1": {comment fields}, ...}: what each [cN] refers to
    flagged_comments.md  every flagged comment, one line each: "- [cN] @author -- text"
    chunk-01.md, ...     the same lines in chunks of --chunk-size
Old chunk-*.md and artists.chunk-*.md files from an earlier run are removed first.
Prints {"flagged": N, "chunks": ["chunk-01.md", ...]}.

Usage: python3 mining/prepare.py <video folder> [--chunk-size 150]
"""
import argparse
import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from mentions import chunk_header, comment_line  # noqa: E402


def prepare(folder: Path, chunk_size: int) -> dict:
    flagged = json.loads((folder / "music_mentions_flagged.json").read_text(encoding="utf-8"))["flagged"]
    vid = folder.name
    title = flagged[0].get("video_title", vid) if flagged else vid
    for old in list(folder.glob("chunk-*.md")) + list(folder.glob("artists.chunk-*.md")):
        old.unlink()
    index = {f"c{i}": c for i, c in enumerate(flagged, 1)}
    (folder / "comment_index.json").write_text(json.dumps(index, indent=2, ensure_ascii=False), encoding="utf-8")
    lines = [comment_line(i, c) for i, c in enumerate(flagged, 1)]
    (folder / "flagged_comments.md").write_text(
        "\n".join(chunk_header(title, vid, f"{len(flagged)} flagged comments") + lines) + "\n", encoding="utf-8")
    chunks = []
    total = (len(lines) + chunk_size - 1) // chunk_size
    for n in range(total):
        part = lines[n * chunk_size:(n + 1) * chunk_size]
        first, last = n * chunk_size + 1, n * chunk_size + len(part)
        name = f"chunk-{n + 1:02d}.md"
        note = f"Chunk {n + 1} of {total}: comments c{first} to c{last} ({len(part)} comments)"
        (folder / name).write_text("\n".join(chunk_header(title, vid, note) + part) + "\n", encoding="utf-8")
        chunks.append(name)
    return {"flagged": len(flagged), "chunks": chunks}


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("folder", type=Path)
    ap.add_argument("--chunk-size", type=int, default=150)
    args = ap.parse_args()
    if args.chunk_size < 1:
        sys.exit("--chunk-size must be at least 1")
    print(json.dumps(prepare(args.folder, args.chunk_size)))


if __name__ == "__main__":
    main()
