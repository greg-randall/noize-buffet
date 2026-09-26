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
_TYPED_QUOTE_RE = re.compile(r'"([^"]+)"|“([^“”]+)”|«([^«»]+)»|‘([^‘’]+)’')
# A straight single quote only counts as a quote -- rather than an apostrophe -- when its open side is
# at the start of the text or after whitespace/a dash, and its close side is at the end of the text or
# before whitespace/punctuation. Used only as a fallback (see _quoted_songs) when the text has no typed
# quote at all, e.g. 'Get Lucky' by Daft Punk; this keeps Liza 'N' Eliaz -- "Let the Bassdrum Go" from
# having "N" misread as a second song, since that line already has a typed (straight-double) quote.
_STRAIGHT_SINGLE_RE = re.compile(r"(?:^|(?<=[\s\-—–]))'([^']+)'(?=$|[\s,.;:!?)\]}])")
# A [...] note in the child's line. Exactly "own artist" (or starting with it, e.g. "[own artist's
# album]") or exactly "unsure" is a recognised tag; anything else ("[song]", "[mashup reference]", ...)
# is just an annotation and is dropped from the name, never left in place.
BRACKET_RE = re.compile(r"\[([^\[\]]*)\]")
OWN_ARTIST_TAG_RE = re.compile(r"\[\s*own artist[^\]]*\]", re.I)
UNSURE_TAG_RE = re.compile(r"\[\s*unsure\s*\]", re.I)
# "none" with optional surrounding brackets/bold/punctuation, and an optional trailing explanation
# after a dash, e.g. "none", "[none]", "**none**", "None — no artists".
NONE_RE = re.compile(r"[\s*\[(]*none[\s*\])._]*(?:[—–-].*)?", re.I)
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


def _quoted_songs(text: str):
    """Every quoted song title in `text`, as regex match objects in order of appearance. Typed quotes
    (straight/curly double, guillemets, curly single) are tried first; the straight-single-quote
    fallback is only used when none of those appear anywhere in `text` at all -- see _STRAIGHT_SINGLE_RE
    above for why."""
    matches = list(_TYPED_QUOTE_RE.finditer(text))
    return matches if matches else list(_STRAIGHT_SINGLE_RE.finditer(text))


def parse_mention(raw: str):
    """(artist, song, tag) from the text after "[cN]"; tag is "", "own artist", "unsure" or "none".
    If both an "own artist" and an "unsure" bracket note appear, "own artist" wins; either way both are
    removed from the name, and so is any other bracket note (an annotation, not part of the name). A
    song may be quoted (one or more quoted titles, joined with " / " if there's more than one) or follow
    a spaced dash ("Artist - Song"); an unspaced dash ("Jay-Z") is left alone."""
    notes = [m.group(1).strip().lower() for m in BRACKET_RE.finditer(raw)]
    if any(n.startswith("own artist") for n in notes):
        tag = "own artist"
        text = OWN_ARTIST_TAG_RE.sub("", raw)
    elif any(n == "unsure" for n in notes):
        tag = "unsure"
        text = UNSURE_TAG_RE.sub("", raw)
    else:
        tag = ""
        text = raw
    text = text.strip()

    if NONE_RE.fullmatch(text):
        return None, None, "none"

    text = BRACKET_RE.sub("", text).strip()  # drop any other bracket note; it's not part of the name

    matches = _quoted_songs(text)
    if matches:
        song = " / ".join(next(g for g in m.groups() if g is not None).strip() for m in matches)
        pieces, pos = [], 0
        for m in matches:
            pieces.append(text[pos:m.start()])
            pos = m.end()
        pieces.append(text[pos:])
        artist = "".join(pieces)
    else:
        artist, song = text, ""
        parts = DASH_SPLIT_RE.split(artist, maxsplit=1)
        if len(parts) == 2:
            artist, song = (p.strip() for p in parts)

    artist = artist.strip(STRIP_CHARS)
    artist = re.sub(r"^by\s+", "", artist, flags=re.I).strip(STRIP_CHARS)
    if artist.endswith(".") and artist.count(".") == 1:
        artist = artist[:-1]
    if not artist and song:  # the line named only a song
        artist = f'(song) "{song}"'
    return artist, song, tag


def read_mentions(path: Path, unparsed: list = None):
    """Every [cN] line in a child's output file, as (cid, artist, song, tag).
    If `unparsed` is a list, every other non-blank line is appended to it as (line_number, line), so
    the caller can report near-misses (a stray comma-separated id list, prose the child added) instead
    of silently dropping them."""
    out = []
    for lineno, line in enumerate(path.read_text(encoding="utf-8").splitlines(), 1):
        m = MENTION_RE.match(line)
        if m:
            artist, song, tag = parse_mention(m.group(2))
            out.append((int(m.group(1)), artist, song, tag))
        elif unparsed is not None and line.strip():
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
