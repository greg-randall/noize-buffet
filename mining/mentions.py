"""Shared helpers for the comment-mining scripts: name keys and the [cN] formats.

Comments appear to the extraction child as "- [c12] @author -- text". The child answers with one line per
name: "- [c12] Burial", '- [c13] Daft Punk — "Get Lucky"', "- [c14] Two Shell [own artist]",
"- [c15] Name [unsure]" or "- [c16] none".
"""
import re
import unicodedata
from pathlib import Path

MENTION_RE = re.compile(r"^\s*-\s*\[c(\d+)\]\s*(.*?)\s*$")
SONG_RE = re.compile(r"[\"“”]([^\"“”]+)[\"“”]")
TAG_RE = re.compile(r"[\[(](own artist|unsure)[\])]", re.I)

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
    """Grouping key for an artist name. Must match nb_name_key() in lib/db.php step for step:
    trim whitespace (Unicode separators + tab/LF/VT/FF/CR, NBSP included), lowercase, NFKD and drop
    combining marks, lowercase again, final sigma to sigma, drop a leading "the" + whitespace, keep
    letters and digits."""
    # Unicode categories rather than combining()/isalnum(), to match PHP's \p{Mn} and \p{L}\p{N} exactly
    name = unicodedata.normalize("NFKD", _strip_ws(name).lower())
    name = "".join(ch for ch in name if unicodedata.category(ch) != "Mn").lower().replace("ς", "σ")
    name = _drop_leading_the(name)
    return "".join(ch for ch in name if unicodedata.category(ch)[0] in "LN")


def parse_mention(raw: str):
    """(artist, song, tag) from the text after "[cN]"; tag is "", "own artist", "unsure" or "none"."""
    tag_match = TAG_RE.search(raw)
    tag = tag_match.group(1).lower() if tag_match else ""
    text = TAG_RE.sub("", raw).strip()
    if text.lower().strip(" .") == "none":
        return None, None, "none"
    song_match = SONG_RE.search(text)
    song = song_match.group(1).strip() if song_match else ""
    artist = SONG_RE.sub("", text)
    artist = re.split(r"\s+[—–-]\s*$|\s+[—–-]\s+", artist)[0].strip(" -—–:*").strip()
    if not artist and song:  # the line named only a song
        artist = f'(song) "{song}"'
    return artist, song, tag


def read_mentions(path: Path):
    """Every [cN] line in a child's output file, as (cid, artist, song, tag); other lines are ignored."""
    out = []
    for line in path.read_text(encoding="utf-8").splitlines():
        m = MENTION_RE.match(line)
        if m:
            artist, song, tag = parse_mention(m.group(2))
            out.append((int(m.group(1)), artist, song, tag))
    return out


def comment_line(cid: int, comment: dict) -> str:
    """One comment as it appears in flagged_comments.md and the chunk files."""
    text = comment["text"].replace("\r\n", "\n").replace("\n", "<br>")
    return f"- [c{cid}] {comment['author']} -- {text}"


def chunk_header(title: str, video_id: str, note: str) -> list:
    return [f"# {title}", "", f"https://www.youtube.com/watch?v={video_id}", "", note, ""]
