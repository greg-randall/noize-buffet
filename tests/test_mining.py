"""Tests for the comment-mining scripts in mining/ (no network, no Claude)."""
import json
import shutil
import subprocess
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
ROOT = HERE.parent
sys.path.insert(0, str(ROOT / "mining"))
import coverage  # noqa: E402
import mentions  # noqa: E402
import prepare  # noqa: E402

fails = 0
TMP = HERE / "tmp" / "mining_py"


def check(cond, msg):
    global fails
    print(("  ok    " if cond else "  FAIL  ") + msg)
    fails += 0 if cond else 1


def comment(cid, author, text, likes=0):
    return {"video_id": "VIDEO000001", "video_title": "Two Shell - home", "comment_id": cid, "parent": "root",
            "author": author, "author_id": "UC" + author.strip("@"), "like_count": likes, "text": text}


def video_dir(vid, flagged):
    d = TMP / vid
    shutil.rmtree(d, ignore_errors=True)
    d.mkdir(parents=True)
    (d / "music_mentions_flagged.json").write_text(json.dumps({"flagged": flagged}), encoding="utf-8")
    return d


print("names")
names = ["The Knife", "Björk", "SOPHIE", "박혜진 Park Hye Jin", "A$AP Rocky", "Daft Punk!", " The Knife",
         "ΣΑΣ", "Theatre of Tragedy", "ℌello", "​The Knife", "\x1fThe Knife", "Björk\u0085", "  Théâtre "]
check(mentions.norm("The Knife") == "knife" and mentions.norm("Björk") == "bjork", "norm drops 'the' and accents")
php_code = 'require $argv[1]; foreach (array_slice($argv, 2) as $n) { echo nb_name_key($n), "\\n"; }'
php = subprocess.run(["php", "-r", php_code, str(ROOT / "lib" / "db.php"), *names],
                     capture_output=True, text=True)
check(php.stdout.split("\n")[:len(names)] == [mentions.norm(n) for n in names], "PHP nb_name_key matches Python norm")
check(mentions.parse_mention('Daft Punk — "Get Lucky"') == ("Daft Punk", "Get Lucky", ""), "artist and song")
check(mentions.parse_mention("Two Shell [own artist]") == ("Two Shell", "", "own artist"), "own artist tag")
check(mentions.parse_mention("Luke Chable [unsure]") == ("Luke Chable", "", "unsure"), "unsure tag")
check(mentions.parse_mention("none")[2] == "none", "none")

print("prepare")
d = video_dir("VIDEO000001", [comment(f"id{i}", f"@u{i}", f"text {i}\nline two") for i in range(1, 8)])
(d / "chunk-09.md").write_text("stale")
(d / "artists.chunk-09.md").write_text("stale")
r = prepare.prepare(d, 3)
check(r == {"flagged": 7, "chunks": ["chunk-01.md", "chunk-02.md", "chunk-03.md"]}, "7 comments in chunks of 3")
check(not (d / "chunk-09.md").exists() and not (d / "artists.chunk-09.md").exists(),
      "stale files from an earlier run removed")
c3 = (d / "chunk-03.md").read_text(encoding="utf-8")
check("- [c7] @u7 -- text 7<br>line two" in c3 and "comments c7 to c7" in c3, "numbered lines, newlines as <br>")
idx = json.loads((d / "comment_index.json").read_text(encoding="utf-8"))
check(len(idx) == 7 and idx["c1"]["comment_id"] == "id1" and idx["c1"]["like_count"] == 0,
      "index maps cN to the comment")
check((d / "flagged_comments.md").read_text(encoding="utf-8").count("- [c") == 7,
      "flagged_comments.md has every comment")
cli = subprocess.run(["python3", str(ROOT / "mining" / "prepare.py"), str(d), "--chunk-size", "4"],
                     capture_output=True, text=True)
check(cli.returncode == 0 and json.loads(cli.stdout)["chunks"] == ["chunk-01.md", "chunk-02.md"], "CLI prints JSON")

print("coverage")
(d / "artists.chunk-01.md").write_text("- [c1] Burial\n- [c2] none\n- [c3] SOPHIE\n- [c3] Burial\n", encoding="utf-8")
(d / "artists.chunk-02.md").write_text("- [c4] Four Tet\n- [c99] Ghost\nsome prose the child added\n", encoding="utf-8")
r = coverage.coverage(d, write_extra=True)
check(r["covered"] == 4 and r["missed"] == ["c5", "c6", "c7"], "missed comments listed")
check(r["unknown"] == ["c99"], "ids the child made up are reported")
extra = (d / "chunk-extra.md").read_text(encoding="utf-8")
check("- [c5] @u5" in extra and "- [c7] @u7" in extra and "[c4]" not in extra,
      "extra chunk has only the missed comments")
check(json.loads((d / "coverage.json").read_text())["missed"] == ["c5", "c6", "c7"], "coverage.json written")
(d / "artists.chunk-extra.md").write_text("- [c5] none\n- [c6] X\n- [c7] Y\n", encoding="utf-8")
r = coverage.coverage(d, write_extra=False)
check(r["missed"] == [] and r["extra_chunk"] is None, "the re-run's output counts toward coverage")

print("ALL PASSED" if fails == 0 else f"FAILED {fails}")
sys.exit(1 if fails else 0)
