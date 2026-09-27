"""Stand-in for the `python3` the mining pipeline runs its mining/*.py scripts with (NB_PYTHON_BIN), in tests.

Called as `fake_python.py <script> <args...>`, the way the pipeline calls python3:
- every call is logged: one JSON line {"script": basename, "args": [...], "cwd": ...} appended to NB_FAKE_PY_LOG;
- mining/find_music_mentions.py (the TypeSafe filter, which needs the network and an API key) is replaced by a fake
  that writes music_mentions_flagged.json in the same shape, in the input file's folder;
- any other script is run by the real python3, unchanged.

The fake TypeSafe filter flags the comments whose ids are listed, one per line, in the file NB_FAKE_TS_FLAGGED_IDS,
or, if that is not set, the comments mining/keyword_filter.py would flag. Switches (environment variables):
  NB_FAKE_TS_FAILED=N        write "failed": N (default 0)
  NB_FAKE_TS_EXIT=N          exit with code N (default 0). The real script exits non-zero when some calls failed
  NB_FAKE_TS_NOFILE=1        write no flagged file
  NB_FAKE_TS_OMIT_FAILED=1   leave the "failed" key out of the flagged file
  NB_FAKE_TS_MESSAGE=text    the last line printed to stderr when the exit code is not 0 (like the real script's
                             sys.exit("message"): plain text, no "ERROR"). Default: an API key message, or, with
                             NB_FAKE_TS_FAILED set, the real script's "N comments failed at TypeSafe; ..." message
Also, to show what step the pipeline was in when a tool was called:
  NB_FAKE_STATUS_LOG=file    every call appends {"tool": label, "status": {video id: mining status, ...}}, read from
                             the NB_DB database; label is the script's basename
  fake_python.py --probe LABEL   only does that (for the fake yt-dlp and claude wrappers to call)

Usage in a test: NB_PYTHON_BIN = a wrapper script that runs `python3 tests/fake_python.py "$@"`.
"""
import json
import os
import sqlite3
import sys
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent


def probe(label: str) -> None:
    log = os.environ.get("NB_FAKE_STATUS_LOG")
    if not log:
        return
    try:
        db = sqlite3.connect(os.environ["NB_DB"], timeout=5)
        status = dict(db.execute("SELECT video_id, status FROM mining").fetchall())
        db.close()
    except Exception as e:  # noqa: BLE001  the probe must never break the call it observes
        status = {"error": str(e)}
    with open(log, "a", encoding="utf-8") as f:
        f.write(json.dumps({"tool": label, "status": status}) + "\n")


def fake_typesafe(args: list) -> None:
    if "--input" not in args or args.index("--input") + 1 >= len(args):
        sys.exit("fake find_music_mentions.py: --input <info.json> is required")
    info_path = Path(args[args.index("--input") + 1])
    info = json.loads(info_path.read_text(encoding="utf-8"))
    comments = info.get("comments") or []
    ids_file = os.environ.get("NB_FAKE_TS_FLAGGED_IDS")
    if ids_file:
        wanted = set(Path(ids_file).read_text(encoding="utf-8").split())
        picked = [c for c in comments if c["id"] in wanted]
    else:
        sys.path.insert(0, str(REPO / "mining"))
        import keyword_filter
        picked = [c for c in comments if keyword_filter.flag(c.get("text", ""))]
    failed = int(os.environ.get("NB_FAKE_TS_FAILED", "0"))
    exit_code = int(os.environ.get("NB_FAKE_TS_EXIT", "0"))
    flagged = [{"video_id": info["id"], "video_title": info.get("title", ""), "comment_id": c["id"],
                "parent": c.get("parent", "root"), "author": c.get("author", ""), "author_id": c.get("author_id", ""),
                "like_count": c.get("like_count") or 0, "text": c.get("text", ""), "p_song": 0.1, "p_artist": 0.95,
                "input_tokens": 10, "song": False, "artist": True} for c in picked]
    print(f"input: {info_path}", file=sys.stderr)
    print(f"fake TypeSafe: {len(comments):,} comments, {len(flagged):,} flagged, {failed:,} failed", file=sys.stderr)
    if not os.environ.get("NB_FAKE_TS_NOFILE"):
        out = {"song_threshold": 0.8, "artist_threshold": 0.8, "comments_checked": len(comments) - failed,
               "comments_total": len(comments), "failed": failed, "flagged": flagged}
        if os.environ.get("NB_FAKE_TS_OMIT_FAILED"):
            del out["failed"]
        (info_path.resolve().parent / "music_mentions_flagged.json").write_text(
            json.dumps(out, indent=2, ensure_ascii=False), encoding="utf-8")
    if exit_code:
        message = os.environ.get("NB_FAKE_TS_MESSAGE") or (
            f"{failed:,} comments failed at TypeSafe; re-run to retry them, finished results are kept" if failed
            else "fake TypeSafe: the API key was rejected (HTTP 401)")
        print(message, file=sys.stderr)
        sys.exit(exit_code)


def main() -> None:
    argv = sys.argv[1:]
    if argv[:1] == ["--probe"]:
        probe(argv[1])
        return
    if not argv:
        sys.exit("usage: fake_python.py <script> [args...]")
    script = Path(argv[0]).name
    if os.environ.get("NB_FAKE_PY_LOG"):
        with open(os.environ["NB_FAKE_PY_LOG"], "a", encoding="utf-8") as f:
            f.write(json.dumps({"script": script, "args": argv[1:], "cwd": os.getcwd()}) + "\n")
    probe(script)
    if script == "find_music_mentions.py":
        fake_typesafe(argv[1:])
        return
    os.execvp("python3", ["python3"] + argv)


if __name__ == "__main__":
    main()
