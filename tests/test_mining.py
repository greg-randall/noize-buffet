"""Tests for the comment-mining scripts in mining/ (no network, no Claude)."""
import json
import shutil
import subprocess
import sys
import unicodedata
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
names = ["The Knife", "Björk", "SOPHIE", "박혜진 Park Hye Jin", "A$AP Rocky", "Daft Punk!", " The Knife",
         "ΣΑΣ", "Theatre of Tragedy", "ℌello", "​The Knife", "\x1fThe Knife", "Björk\u0085", "  Théâtre "]
check(mentions.norm("The Knife") == "knife" and mentions.norm("Björk") == "bjork", "norm drops 'the' and accents")
php_code = 'require $argv[1]; foreach (array_slice($argv, 2) as $n) { echo nb_name_key($n), "\\n"; }'
php = subprocess.run(["php", "-r", php_code, str(ROOT / "lib" / "db.php"), *names],
                     capture_output=True, text=True)
check(php.stdout.split("\n")[:len(names)] == [mentions.norm(n) for n in names], "PHP nb_name_key matches Python norm")
# norm()/nb_name_key() only ever compare within their own language (Python groups within one merge_leads
# run; PHP recomputes its own key from a lead's stored name for mutes/the mining queue) -- the check above
# is a cross-language sanity check, not something either side relies on at runtime. Print the Unicode
# data versions each side is using, as information only; a mismatch here is not itself a test failure.
php_uver = subprocess.run(["php", "-r", 'require $argv[1]; echo IntlChar::UNICODE_VERSION;',
                           str(ROOT / "lib" / "db.php")], capture_output=True, text=True)
print(f"  info    python unicodedata.unidata_version = {unicodedata.unidata_version}")
print(f"  info    php IntlChar::UNICODE_VERSION = {php_uver.stdout.strip()}")
check(mentions.parse_mention('Daft Punk — "Get Lucky"') == ("Daft Punk", "Get Lucky", ""), "artist and song")
check(mentions.parse_mention("Two Shell [own artist]") == ("Two Shell", "", "own artist"), "own artist tag")
check(mentions.parse_mention("Luke Chable [unsure]") == ("Luke Chable", "", "unsure"), "unsure tag")
check(mentions.parse_mention("none")[2] == "none", "none")

print("mention line formats")
forms = ["- [c1] Burial", "* [c1] Burial", "1. [c1] Burial", "[c1] Burial", "- [C1] Burial",
         "- **[c1]** Burial", "- [c 1] Burial"]
for line in forms:
    m = mentions.MENTION_RE.match(line)
    check(m is not None and m.group(1) == "1" and mentions.parse_mention(m.group(2)) == ("Burial", "", ""),
          f"accepted form: {line!r}")

print("unparsed lines")
unparsed_lines = []
unparsed_file = TMP / "unparsed_test.md"
unparsed_file.parent.mkdir(parents=True, exist_ok=True)
unparsed_file.write_text("- [c1] Burial\n- [c12, c13] Burial\nsome prose the child added\n\n", encoding="utf-8")
out = mentions.read_mentions(unparsed_file, unparsed_lines)
check(len(out) == 1 and out[0][0] == 1, "only the well-formed line parses")
check(unparsed_lines == [(2, "- [c12, c13] Burial"), (3, "some prose the child added")],
      "a near-miss id list and prose are reported as unparsed, blank line skipped")
notes_file = TMP / "notes_test.md"
notes_file.write_text('- [c1] Lords of Acid — "Show Me Your" [song]\n- [c2] Two Shell [own artist]\n',
                      encoding="utf-8")
read_notes = []
mentions.read_mentions(notes_file, [], read_notes)
check(read_notes == ["notes_test.md:1: [song]"],
      "read_mentions prefixes a recorded note with the file name and line number")

print("song extraction")
check(mentions.parse_mention("Daft Punk – Get Lucky") == ("Daft Punk", "Get Lucky", ""), "en dash split")
check(mentions.parse_mention("Daft Punk - Get Lucky") == ("Daft Punk", "Get Lucky", ""), "hyphen split")
check(mentions.parse_mention("Jay-Z") == ("Jay-Z", "", ""), "an unspaced dash in a name is not split")
check(mentions.parse_mention('Daft Punk — "Get Lucky" — "One More Time"')
      == ("Daft Punk", "Get Lucky / One More Time", ""), "multiple quoted songs are joined with ' / '")
check(mentions.parse_mention("'Get Lucky' by Daft Punk") == ("Daft Punk", "Get Lucky", ""),
      "'song' by artist keeps the song and drops the leading 'by'")
check(mentions.parse_mention("Daft Punk – “Get Lucky”") == ("Daft Punk", "Get Lucky", ""), "curly quotes")

print("apostrophes are not quote marks")
check(mentions.parse_mention('Brian Eno — "Baby\'s On Fire"') == ("Brian Eno", "Baby's On Fire", ""),
      "an apostrophe inside a double-quoted song survives")
check(mentions.parse_mention('Tom Jones — "It\'s Not Unusual"') == ("Tom Jones", "It's Not Unusual", ""),
      "apostrophe in a contraction inside the quotes")
check(mentions.parse_mention('Screamin\' Jay Hawkins — "I Put A Spell On You"')
      == ("Screamin' Jay Hawkins", "I Put A Spell On You", ""),
      "a trailing apostrophe in the artist name is not mistaken for an opening quote")
check(mentions.parse_mention('Liza \'N\' Eliaz — "Let the Bassdrum Go"')
      == ("Liza 'N' Eliaz", "Let the Bassdrum Go", ""),
      "'N' inside a name is not treated as a second quoted song")
check(mentions.parse_mention('TNGHT — "I\'m in a hole"') == ("TNGHT", "I'm in a hole", ""),
      "apostrophe right after the opening quote")
check(mentions.parse_mention("'Get Lucky' by Daft Punk") == ("Daft Punk", "Get Lucky", ""),
      "a straight-single-quoted song still works when it's the only quote in the line")

print("straight-single-quote fallback stays out of the way of plain apostrophes")
check(mentions.parse_mention("Liza 'N' Eliaz") == ("Liza 'N' Eliaz", "", ""),
      "'N' alone (no dash, no other quote) is still not read as a 1-letter song")
check(mentions.parse_mention("Rock 'n' Roll Soldiers") == ("Rock 'n' Roll Soldiers", "", ""),
      "'n' is too short and not next to a dash or 'by', so the whole name survives")
check(mentions.parse_mention("By Divine Right") == ("By Divine Right", "", ""),
      "a leading 'by ' is only stripped when a quoted song came before it")

print("an empty quoted song counts as no song")
check(mentions.parse_mention('Kanye West — ""') == ("Kanye West", "", ""),
      "an empty pair of quotes is not a song, and doesn't leak into the artist or song text")

print("leftovers around quoted songs")
check(mentions.parse_mention('Daft Punk — "Get Lucky" (Radio Edit)') == ("Daft Punk", "Get Lucky", ""),
      "a trailing note next to the quote doesn't matter when the song follows a spaced dash")
check(mentions.parse_mention('"Get Lucky" by Daft Punk (2013)') == ("Daft Punk", "Get Lucky", ""),
      "a trailing parenthesised note after 'by Artist' is dropped")
check(mentions.parse_mention('Daft Punk — "A" / "B"') == ("Daft Punk", "A / B", ""),
      "a lone '/' between two quoted songs never reaches the artist, because the dash-prefix wins")
check(mentions.parse_mention('Daft Punk ("Get Lucky")') == ("Daft Punk", "Get Lucky", ""),
      "the empty parentheses left behind when a quote was their whole content are cleaned up")

print("brackets and parens inside a quoted song title are kept, not stripped as notes")
check(mentions.parse_mention('Kanye West — "Runaway (feat. Pusha T)"')
      == ("Kanye West", "Runaway (feat. Pusha T)", ""), "a parenthesised feature credit inside the quotes survives")
check(mentions.parse_mention('The Rolling Stones — "(I Can\'t Get No) Satisfaction"')
      == ("The Rolling Stones", "(I Can't Get No) Satisfaction", ""),
      "a leading parenthesised part of the title survives, apostrophe and all")
check(mentions.parse_mention('Artist — "Song (Why?)"') == ("Artist", "Song (Why?)", ""),
      "a '?' inside the quoted title doesn't make it look like a hedge note")
check(mentions.parse_mention('Artist — "Love Song (Maybe Not)" [unsure]') == ("Artist", "Love Song (Maybe Not)",
      "unsure"), "a real [unsure] tag outside the quotes still works, and the title's own paren survives")

print("bracket and parenthesis notes are dropped, not left in the name")
check(mentions.parse_mention("Babylon AD [own artist's album reference]") == ("Babylon AD", "", "own artist"),
      "a bracket note starting with 'own artist' is the own-artist tag, and the whole note is removed")
check(mentions.parse_mention('Lords of Acid — "Show Me Your" [song]') == ("Lords of Acid", "Show Me Your", ""),
      "an unrecognised bracket note ('[song]') is dropped, not left in the name")
check(mentions.parse_mention("Marilyn Manson, Lady Gaga [mashup reference]")
      == ("Marilyn Manson, Lady Gaga", "", ""), "an unrecognised bracket note is dropped here too")
check(mentions.parse_mention("Two Shell (own artist)") == ("Two Shell", "", "own artist"),
      "a parenthesised 'own artist' note is a tag just like the bracket form")
check(mentions.parse_mention("Two Shell (unsure)") == ("Two Shell", "", "unsure"),
      "a parenthesised 'unsure' note is a tag just like the bracket form")

print("hedge words count as unsure")
check(mentions.parse_mention("Some Artist [maybe]") == ("Some Artist", "", "unsure"), "'maybe' is a hedge")
check(mentions.parse_mention("Some Artist [probably wrong]") == ("Some Artist", "", "unsure"),
      "'probably ...' is a hedge")
check(mentions.parse_mention("Some Artist (likely x)") == ("Some Artist", "", "unsure"), "'likely ...' is a hedge")
check(mentions.parse_mention("Some Artist [is this right?]") == ("Some Artist", "", "unsure"),
      "a note ending in '?' is a hedge")

print("removed notes are recorded, not dropped")
notes = []
check(mentions.parse_mention('Lords of Acid — "Show Me Your" [song]', notes=notes) == ("Lords of Acid",
      "Show Me Your", ""), "parse_mention still returns the same result when notes= is given")
check(notes == ["[song]"], "the dropped note is appended, with its brackets, to the notes list")
notes = []
mentions.parse_mention("Two Shell [own artist] [unsure]", notes=notes)
check(notes == ["[unsure]"], "a losing second tag is recorded as a note too, since only one tag wins")

print("none variants")
for text in ["none", "None", "[none]", "(none)", "**none**", "None — no artists", "- none", "nothing",
             "N/A", "n/a", "no artist mentioned", "no artist", "none (not sure who)"]:
    check(mentions.parse_mention(text) == (None, None, "none"), f"none variant: {text!r}")

print("tag precedence")
check(mentions.parse_mention("Two Shell [own artist] [unsure]") == ("Two Shell", "", "own artist"),
      "own artist wins over unsure, and both tags are removed from the name")
check(mentions.parse_mention("Two Shell [unsure] [own artist]") == ("Two Shell", "", "own artist"),
      "tag precedence doesn't depend on order")

print("display name cleanup")
check(mentions.parse_mention("P.E.") == ("P.E.", "", ""), "a name with more than one '.' keeps them all")
check(mentions.parse_mention("Daft Punk.") == ("Daft Punk", "", ""), "a single trailing '.' is dropped")
check(mentions.parse_mention("Daft Punk,") == ("Daft Punk", "", ""), "trailing comma stripped")
check(mentions.parse_mention("`Daft Punk`") == ("Daft Punk", "", ""), "backticks stripped")

print("comment_line newline handling")
newline_comment = comment("idX", "@x", "line1\r\nline2\rline3\nline4\x85line5")
check(mentions.comment_line(1, newline_comment) == "- [c1] @x -- line1<br>line2<br>line3<br>line4<br>line5",
      "CRLF, lone CR, LF and NEL all become <br>")

print("video_title fallback")
check(mentions.video_title([], "VIDEO000009") == "VIDEO000009", "no comments -> falls back to the video id")
check(mentions.video_title([{"other": 1}], "VIDEO000009") == "VIDEO000009", "missing video_title -> falls back")
check(mentions.video_title([{"video_title": None}], "VIDEO000009") == "VIDEO000009", "null video_title -> falls back")
check(mentions.video_title([{"video_title": ""}], "VIDEO000009") == "VIDEO000009", "empty video_title -> falls back")
check(mentions.video_title([{"video_title": "Real\nTitle"}], "x") == "Real Title", "newlines become spaces")

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

print("prepare: empty flagged list")
d4 = video_dir("VIDEO000004", [])
r4 = prepare.prepare(d4, 150)
check(r4 == {"flagged": 0, "chunks": []}, "no flagged comments -> no chunks")
fc = (d4 / "flagged_comments.md").read_text(encoding="utf-8")
check("0 flagged comments" in fc and "- [c" not in fc, "flagged_comments.md has only the header")

print("prepare: chunk_size larger than the comment count")
d5 = video_dir("VIDEO000005", [comment(f"id{i}", f"@u{i}", f"text {i}") for i in range(1, 4)])
r5 = prepare.prepare(d5, 1000)
check(r5["chunks"] == ["chunk-01.md"], "chunk_size bigger than the comment count still makes one chunk")

print("prepare: chunk_size validation")
threw = False
try:
    prepare.prepare(d5, 0)
except ValueError:
    threw = True
check(threw, "prepare() raises ValueError for chunk_size < 1")

print("prepare: removes stale coverage.json and chunk-extra files")
d6 = video_dir("VIDEO000006", [comment("id1", "@u1", "hi")])
(d6 / "coverage.json").write_text("{}", encoding="utf-8")
(d6 / "chunk-extra.md").write_text("stale", encoding="utf-8")
(d6 / "artists.chunk-extra.md").write_text("stale", encoding="utf-8")
prepare.prepare(d6, 150)
check(not (d6 / "coverage.json").exists() and not (d6 / "chunk-extra.md").exists()
      and not (d6 / "artists.chunk-extra.md").exists(), "stale coverage.json and *-extra files removed")

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

print("coverage: empty mentions, and multiple invented ids in numeric order")
d2 = video_dir("VIDEO000002", [comment(f"id{i}", f"@u{i}", f"text {i}") for i in range(1, 6)])
prepare.prepare(d2, 150)
(d2 / "artists.chunk-01.md").write_text(
    "- [c1]\n- [c2] Burial\n- [c5] none\n- [c9] Ghost\n- [c9] Boom\n- [c50] Weird\nprose line\n", encoding="utf-8")
r2 = coverage.coverage(d2, write_extra=True)
check(r2["missed"] == ["c1", "c3", "c4"], "an empty mention (no artist/song/tag) does not count as coverage")
check(r2["unknown"] == ["c9", "c50"], "invented ids are reported in numeric, not lexicographic, order")
check(r2["unparsed"] == ["artists.chunk-01.md:7: prose line"], "the prose line is reported as unparsed")
extra2 = (d2 / "chunk-extra.md").read_text(encoding="utf-8")
check("[c1]" in extra2 and "[c3]" in extra2 and "[c4]" in extra2, "all missed ids go to chunk-extra.md")
check(extra2.index("[c1]") < extra2.index("[c3]") < extra2.index("[c4]"), "missed ids appear in order")

print("coverage: notes")
d7 = video_dir("VIDEO000007", [comment(f"id{i}", f"@u{i}", f"text {i}") for i in range(1, 3)])
prepare.prepare(d7, 150)
(d7 / "artists.chunk-01.md").write_text(
    '- [c1] Lords of Acid — "Show Me Your" [song]\n- [c2] Two Shell [own artist]\n', encoding="utf-8")
r7 = coverage.coverage(d7, write_extra=True)
check(r7["notes"] == ["artists.chunk-01.md:1: [song]"], "a dropped note surfaces in the coverage result")
check(json.loads((d7 / "coverage.json").read_text())["notes"] == ["artists.chunk-01.md:1: [song]"],
      "and in coverage.json")

print("coverage: non-contiguous missed ids")
d3 = video_dir("VIDEO000003", [comment(f"id{i}", f"@u{i}", f"text {i}") for i in range(1, 6)])
prepare.prepare(d3, 150)
(d3 / "artists.chunk-01.md").write_text("- [c1] A\n- [c3] B\n- [c4] C\n", encoding="utf-8")
r3 = coverage.coverage(d3, write_extra=True)
check(r3["missed"] == ["c2", "c5"], "non-contiguous missed ids (c2 and c5)")
extra3 = (d3 / "chunk-extra.md").read_text(encoding="utf-8")
check(extra3.index("[c2]") < extra3.index("[c5]"), "missed ids appear in chunk-extra.md in order")

print("filters")
sys.path.insert(0, str(ROOT / "mining"))
import keyword_filter  # noqa: E402

check(keyword_filter.flag("if you like this check out Burial"), "cue phrase flagged")
check(keyword_filter.flag("Daft Punk - Get Lucky is the blueprint"), "Artist - Song flagged")
check(keyword_filter.flag('that "Archangel" feeling'), "quoted title flagged")
check(not keyword_filter.flag("love this so much 😭"), "plain praise not flagged")
vd = TMP / "VIDEO000002"
shutil.rmtree(vd, ignore_errors=True)
vd.mkdir(parents=True)
info = {"id": "VIDEO000002", "title": "Some - Song", "comments": [
    {"id": "a1", "author": "@ann", "author_id": "UCann", "like_count": 50, "text": "sounds like Burial"},
    {"id": "a2", "author": "@bob", "author_id": "UCbob", "like_count": 1, "text": "nice"}]}
(vd / "VIDEO000002.info.json").write_text(json.dumps(info), encoding="utf-8")
cli = subprocess.run(
    ["python3", str(ROOT / "mining" / "keyword_filter.py"), "--input", str(vd / "VIDEO000002.info.json")],
    capture_output=True, text=True)
out = json.loads((vd / "music_mentions_flagged.json").read_text(encoding="utf-8"))
row = out["flagged"][0] if out["flagged"] else {}
check(cli.returncode == 0 and out["filter"] == "keyword" and out["comments_checked"] == 2 and len(out["flagged"]) == 1,
      "keyword filter writes the flagged file")
check(row.get("author_id") == "UCann" and row.get("like_count") == 50 and row.get("video_title") == "Some - Song",
      "flagged rows carry author id, likes and title")
try:
    import find_music_mentions  # noqa: E402
except ImportError as e:
    print(f"  skip  find_music_mentions (missing package {e.name}; python3 -m pip install -r requirements.txt)")
else:
    rows = find_music_mentions.load_comments(vd / "VIDEO000002.info.json")
    check(rows[0]["author_id"] == "UCann" and rows[0]["like_count"] == 50, "TypeSafe filter keeps author id and likes")

print("ALL PASSED" if fails == 0 else f"FAILED {fails}")
sys.exit(1 if fails else 0)
