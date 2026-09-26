"""Shared helpers for the comment-mining scripts: name keys and the [cN] formats.

Comments appear to the extraction child as "- [c12] @author -- text". The child answers with one line per
name: "- [c12] Burial", '- [c13] Daft Punk — "Get Lucky"', "- [c14] Two Shell [own artist]",
"- [c15] Name [unsure]" or "- [c16] none". Children don't always follow the format exactly (bold markers,
numbered lists, a stray comma-separated id list, prose); MENTION_RE is loosened to accept common near-miss
list styles, and read_mentions() reports lines it still can't parse rather than dropping them.
"""
import re
import unicodedata
from pathlib import Path

# Accepts "- [c12] ...", "* [c12] ...", "1. [c12] ...", "[c12] ...", "- [C12] ...", "- **[c12]** ...",
# "- [c 12]: ...". Deliberately loose: near-miss lines (e.g. "[c12, c13] ...") fail to match and are
# reported as unparsed by read_mentions() rather than silently ignored.
MENTION_RE = re.compile(r"^\s*(?:[-*•]|\d+[.)])?\s*\**\s*\[\s*c\s*(\d+)\s*\]\s*\**\s*:?\s*(.*?)\s*$", re.I)

# Quote pairs the child might use around a song title. Each type pairs only with its own closer, and
# apostrophes are allowed inside all of them (so "Baby's On Fire" or "Boot Scootin' Boogie" survive
# intact). Straight single quotes are handled separately by _STRAIGHT_SINGLE_RE below, since an
# apostrophe is also just an apostrophe (Screamin', Liza 'N' Eliaz, Jay-Z's).
_TYPED_QUOTE_RE = re.compile(r'"([^"]*)"|“([^“”]*)”|«([^«»]*)»|‘([^‘’]*)’')
# A straight single quote only counts as a *candidate* quote -- rather than an apostrophe -- when its
# open side is at the start of the text or after whitespace/a dash, and its close side is at the end of
# the text or before whitespace/punctuation. _valid_straight_single() then requires it to actually look
# like a quoted song (2+ characters, and right after a spaced dash or right before " by "), which is what
# keeps "Screamin'", "Liza 'N' Eliaz" and "Rock 'n' Roll Soldiers" from having a syllable misread as a
# song even when nothing else in the line has a typed quote to take priority.
_STRAIGHT_SINGLE_RE = re.compile(r"(?:^|(?<=[\s\-—–]))'([^']+)'(?=$|[\s,.;:!?)\]}])")
_DASH_BEFORE_RE = re.compile(r"\s[—–-]\s*$")  # text ending "... - " / "... — " right before a quoted song
_BY_AFTER_RE = re.compile(r"^\s+by\b", re.I)  # text starting " by ..." right after a quoted song

# A [...] or (...) note in the child's line. Quote characters are excluded from the content so a note
# never swallows a quoted song that happens to sit inside parentheses, e.g. Daft Punk ("Get Lucky") --
# there the "(" ... ")" around the quote simply isn't a NOTE_RE match, and the quote is found normally;
# the empty leftover parens are cleaned up afterwards by _clean_quote_leftovers().
NOTE_RE = re.compile(r'\[([^\[\]"“”«»‘’]*)\]|\(([^()"“”«»‘’]*)\)')
_HEDGES = ("unsure", "maybe", "probably", "likely")
# Cleanup once a quoted song has been cut out of the text: drop an empty "()"/"[]" left behind when the
# quote was the note's entire content, and a lone "/" left behind when it separated two quoted songs.
_EMPTY_NOTE_RE = re.compile(r"[(\[]\s*[)\]]")
_LONE_SLASH_RE = re.compile(r"(?:(?<=\s)|^)/(?=\s|$)")

# "none" (or "nothing"/"n/a"/"no artist[ mentioned]"), tolerating a leading bullet-ish dash and
# surrounding brackets/bold/punctuation, plus either a trailing "- explanation" or a trailing
# parenthesised/bracketed note, e.g. "none", "[none]", "**none**", "None — no artists", "none (unsure)".
NONE_RE = re.compile(
    r"[\s*\[(\-—–]*(?:none|nothing|n\s*/\s*a|no artist(?:\s+mentioned)?)[\s*\])._]*"
    r"(?:[—–-].*|\s*[(\[][^()\[\]]*[)\]]\s*)?",
    re.I,
)

# A spaced dash separates artist from song ("Daft Punk - Get Lucky"); an unspaced dash inside a name
# ("Jay-Z") must NOT split.
DASH_SPLIT_RE = re.compile(r"\s+[—–-]\s+")
# Trailing/leading punctuation trimmed off the display name once the song (if any) has been pulled out.
STRIP_CHARS = " -—–:*`\"',;"
# A newline appearing inside a comment's or a line's text, in every form we've seen (CRLF, lone CR or
# LF, and NEL \x85, which some transcripts use).
NEWLINE_RE = re.compile(r"\r\n|[\r\n\x85]")

# The trim set used at both ends of norm() and after a leading "the": Unicode separator categories
# (Zs/Zl/Zp, i.e. category starting with "Z") plus the five ASCII whitespace controls tab/LF/VT/FF/CR.
# Deliberately narrower than str.strip()/str.isspace(), which also treat \x1c-\x1f and \x85 (NEL) as
# whitespace; those must NOT be trimmed here, to match PHP's \p{Z}\t\n\x0B\f\r exactly.
_TRIM_CONTROLS = "\t\n\x0b\x0c\r"


def _is_trim_ws(ch: str) -> bool:
    return unicodedata.category(ch).startswith("Z") or ch in _TRIM_CONTROLS


def _strip_ws(s: str) -> str:
    start = 0
    end = len(s)
    while start < end and _is_trim_ws(s[start]):
        start += 1
    while end > start and _is_trim_ws(s[end - 1]):
        end -= 1
    return s[start:end]


def _drop_leading_the(name: str) -> str:
    """Drop a leading "the" only when followed by one or more trim-set whitespace characters."""
    if not name.startswith("the"):
        return name
    rest = name[3:]
    j = 0
    while j < len(rest) and _is_trim_ws(rest[j]):
        j += 1
    return rest[j:] if j > 0 else name


def norm(name: str) -> str:
    """Grouping key for an artist name, used only to group mentions within merge_leads.py's own run.
    Must match nb_name_key() in lib/db.php step for step (tests/test_mining.py checks this on a fixed
    name list, as a sanity check, but the two sides never compare keys at runtime: PHP always computes
    its own nb_name_key() from a lead's stored name when it needs one, e.g. for mutes or the mining
    queue, rather than trusting a key this module produced): trim whitespace (Unicode separators +
    tab/LF/VT/FF/CR, NBSP included), lowercase, NFKD and drop combining marks, lowercase again, final
    sigma to sigma, drop a leading "the" + whitespace, keep letters and digits."""
    # Unicode categories rather than combining()/isalnum(), to match PHP's \p{Mn} and \p{L}\p{N} exactly
    name = unicodedata.normalize("NFKD", _strip_ws(name).lower())
    name = "".join(ch for ch in name if unicodedata.category(ch) != "Mn").lower().replace("ς", "σ")
    name = _drop_leading_the(name)
    return "".join(ch for ch in name if unicodedata.category(ch)[0] in "LN")


def _note_text(m) -> str:
    return m.group(1) if m.group(1) is not None else m.group(2)


def _is_own_artist_note(content: str) -> bool:
    return content.strip().lower().startswith("own artist")


def _is_unsure_note(content: str) -> bool:
    c = content.strip().lower()
    return c.startswith(_HEDGES) or c.endswith("?")


def _remove_matches(s: str, matches) -> str:
    pieces, pos = [], 0
    for m in matches:
        pieces.append(s[pos:m.start()])
        pos = m.end()
    pieces.append(s[pos:])
    return "".join(pieces)


def _song_text(m) -> str:
    return next(g for g in m.groups() if g is not None)


def _valid_straight_single(text: str, m) -> bool:
    """A straight-single-quoted candidate only counts as a song if it's at least 2 characters and sits
    right after a spaced dash or right before " by " -- otherwise it's just an apostrophe in a name
    (Liza 'N' Eliaz, Rock 'n' Roll Soldiers), even one that happens to have whitespace on both sides."""
    if len(_song_text(m)) < 2:
        return False
    return bool(_DASH_BEFORE_RE.search(text[:m.start()])) or bool(_BY_AFTER_RE.match(text[m.end():]))


def _quoted_songs(text: str):
    """Every candidate quoted song title in `text`, as regex match objects in order of appearance. Typed
    quotes (straight/curly double, guillemets, curly single) are tried first; the straight-single-quote
    fallback is only used when none of those appear anywhere in `text` at all -- see _STRAIGHT_SINGLE_RE
    above for why -- and only for matches _valid_straight_single() accepts."""
    matches = list(_TYPED_QUOTE_RE.finditer(text))
    if matches:
        return matches
    return [m for m in _STRAIGHT_SINGLE_RE.finditer(text) if _valid_straight_single(text, m)]


def _clean_quote_leftovers(s: str) -> str:
    """After a quoted song is cut out of the text, tidy up what's left: an empty "()"/"[]" (the quote
    was the note's whole content, e.g. Daft Punk ("Get Lucky")) and a lone "/" (it separated two quoted
    songs, e.g. Daft Punk — "A" / "B")."""
    while True:
        new = _EMPTY_NOTE_RE.sub("", s)
        if new == s:
            break
        s = new
    return _LONE_SLASH_RE.sub("", s).strip()


def parse_mention(raw: str, notes: list = None):
    """(artist, song, tag) from the text after "[cN]"; tag is "", "own artist", "unsure" or "none".

    A [...] or (...) note sets the tag: "own artist" if its content starts with "own artist" (so a note
    explaining *why*, e.g. "(own artist's album)", still counts) -- and wins if more than one kind of
    note is present; otherwise "unsure" if its content is a hedge (starts with "unsure", "maybe",
    "probably" or "likely", or ends in "?"). Every other note removed from the name -- "[song]",
    "(Radio Edit)", a mashup aside, a losing second tag, ... -- is an annotation, not part of the name,
    but it's appended to `notes` (if given) rather than silently dropped.

    A song may be one or more quoted titles (joined with " / " if there's more than one; an empty quote,
    e.g. Kanye West — "", doesn't count as a song) or, failing that, text split on a spaced dash
    ("Artist - Song"); an unspaced dash ("Jay-Z") is left alone. When a quoted song follows a spaced
    dash, the artist is just the text before that dash (so a trailing "(Radio Edit)" or "(2013)" next to
    the quote doesn't matter); otherwise the artist is what's left after cutting the quotes out and
    tidying leftovers (see _clean_quote_leftovers). A leading "by " is stripped from the artist only when
    a quoted song was found before it ("'Get Lucky' by Daft Punk"), so a real name like "By Divine
    Right" is untouched."""
    all_notes = [(m, _note_text(m)) for m in NOTE_RE.finditer(raw)]
    own = [m for m, c in all_notes if _is_own_artist_note(c)]
    unsure = [m for m, c in all_notes if _is_unsure_note(c)]
    if own:
        tag, winners = "own artist", own
    elif unsure:
        tag, winners = "unsure", unsure
    else:
        tag, winners = "", []

    # Remove only the tag-defining note(s) first, so a bracket/paren-wrapped "none" answer (or one with
    # a trailing note, e.g. "none (not sure who)") still reads as "none" below.
    text = _remove_matches(raw, winners).strip()

    if NONE_RE.fullmatch(text):
        return None, None, "none"

    # Any note left at this point is an annotation, not part of the name; drop it, but record it.
    leftover = list(NOTE_RE.finditer(text))
    if notes is not None:
        notes.extend(m.group(0) for m in leftover)
    text = _remove_matches(text, leftover).strip()

    matches = _quoted_songs(text)
    song_matches = [m for m in matches if _song_text(m).strip()]
    if not song_matches and matches:
        # only empty-quoted spans (e.g. Kanye West — ""); they don't count as a song, but their
        # characters must still be cut so they don't corrupt the artist text below
        text = _remove_matches(text, matches).strip()
        matches = []

    if song_matches:
        song = " / ".join(_song_text(m).strip() for m in song_matches)
        prefix = text[:song_matches[0].start()]
        if _DASH_BEFORE_RE.search(prefix):
            artist = _DASH_BEFORE_RE.sub("", prefix)
        else:
            artist = _clean_quote_leftovers(_remove_matches(text, matches))
    else:
        artist, song = text, ""
        parts = DASH_SPLIT_RE.split(artist, maxsplit=1)
        if len(parts) == 2:
            artist, song = (p.strip() for p in parts)

    artist = artist.strip(STRIP_CHARS)
    if song_matches:  # only a quoted "'Song' by Artist" gets its leading "by " dropped
        artist = re.sub(r"^by\s+", "", artist, flags=re.I).strip(STRIP_CHARS)
    if artist.endswith(".") and artist.count(".") == 1:
        artist = artist[:-1]
    if not artist and song:  # the line named only a song
        artist = f'(song) "{song}"'
    return artist, song, tag


def read_mentions(path: Path, unparsed: list, notes: list = None):
    """Every [cN] line in a child's output file, as (cid, artist, song, tag).
    Every other non-blank line is appended to `unparsed` as (line_number, line), so the caller can
    report near-misses (a stray comma-separated id list, prose the child added) instead of silently
    dropping them. If `notes` is a list, every note parse_mention() removed from a [cN] line's name
    (other than the recognised tag) is appended as "path:line: [note]"."""
    out = []
    for lineno, line in enumerate(path.read_text(encoding="utf-8").splitlines(), 1):
        m = MENTION_RE.match(line)
        if m:
            line_notes = [] if notes is not None else None
            artist, song, tag = parse_mention(m.group(2), notes=line_notes)
            out.append((int(m.group(1)), artist, song, tag))
            if notes is not None:
                notes.extend(f"{path.name}:{lineno}: {n}" for n in line_notes)
        elif line.strip():
            unparsed.append((lineno, line))
    return out


def comment_line(cid: int, comment: dict) -> str:
    """One comment as it appears in flagged_comments.md and the chunk files."""
    text = NEWLINE_RE.sub("<br>", comment["text"])
    return f"- [c{cid}] {comment['author']} -- {text}"


def chunk_header(title: str, video_id: str, note: str) -> list:
    return [f"# {title}", "", f"https://www.youtube.com/watch?v={video_id}", "", note, ""]


def video_title(comments, vid: str) -> str:
    """The video's title, taken from the first comment that has one, or the video id if there are no
    comments or none carries a title. Newlines are replaced with spaces since this feeds a Markdown
    header line."""
    for c in comments:
        title = c.get("video_title")
        if title:
            return NEWLINE_RE.sub(" ", str(title))
    return vid
