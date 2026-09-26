"""Check that the extraction child wrote at least one line for every flagged comment.

Reads comment_index.json and every artists.chunk-*.md in the video folder. With --write-extra, writes the
comments nobody covered to chunk-extra.md (same format as the other chunks) for one re-run.
Prints, and writes to coverage.json:
    {"flagged": N, "covered": M, "missed": ["c7", ...], "unknown": ["c99", ...], "extra_chunk": name or null}
"unknown" lists ids the child wrote that aren't in the index.

Usage: python3 mining/coverage.py <video folder> [--write-extra]
"""
import argparse
import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from mentions import chunk_header, comment_line, read_mentions  # noqa: E402


def coverage(folder: Path, write_extra: bool) -> dict:
    index = json.loads((folder / "comment_index.json").read_text(encoding="utf-8"))
    seen = set()
    for path in sorted(folder.glob("artists.chunk-*.md")):
        seen.update(f"c{cid}" for cid, *_ in read_mentions(path))
    missed = [cid for cid in index if cid not in seen]
    unknown = sorted(seen - set(index), key=lambda c: int(c[1:]))
    extra = None
    if write_extra and missed:
        title = next(iter(index.values())).get("video_title", folder.name)
        lines = [comment_line(int(cid[1:]), index[cid]) for cid in missed]
        note = f"Re-run: {len(missed)} comments that got no line the first time"
        (folder / "chunk-extra.md").write_text("\n".join(chunk_header(title, folder.name, note) + lines) + "\n",
                                               encoding="utf-8")
        extra = "chunk-extra.md"
    result = {"flagged": len(index), "covered": len(index) - len(missed), "missed": missed,
              "unknown": unknown, "extra_chunk": extra}
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
