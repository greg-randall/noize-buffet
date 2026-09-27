"""Read public Spotify playlists (name, owner, tracks) and print JSON. No account or key.

Reads Spotify's embeddable player page (open.spotify.com/embed/playlist/<id>), which carries the track list in its
page data. It shows at most the first 100 tracks. Playlists are read one at a time, a second apart.

Usage: python3 scripts/spotify_playlist.py <playlist url or id> [...] [--seed "artist song"]
Output with one playlist: {"id", "url", "name", "owner", "tracks": [{"title", "artists", "duration_s"}],
                           "tracks_shown", "may_have_more", "has_seed"}
Output with several: {"<url or id as given>": {...}, ...}; a playlist that can't be read gives {"error": "..."}.
--seed checks each playlist still has that song (web search results can be out of date): has_seed is true, false,
or null when the playlist shows 100 tracks and the song wasn't among them (it may be further down).
"""
import argparse
import json
import re
import sys
import time
import unicodedata
import urllib.request

ID_RE = re.compile(r"(?:playlist[/:])?([A-Za-z0-9]{22})")
DATA_RE = re.compile(r'<script id="__NEXT_DATA__" type="application/json">(.*?)</script>', re.S)
EMBED_LIMIT = 100


def playlist_id(text):
    m = ID_RE.search(text)
    if not m:
        raise ValueError(f"not a Spotify playlist url or id: {text}")
    return m.group(1)


def parse_page(html, pid):
    """The playlist from an embed page's HTML."""
    m = DATA_RE.search(html)
    if not m:
        raise ValueError("no playlist data in the page (Spotify may have changed it, or the playlist is private)")
    try:
        entity = json.loads(m.group(1))["props"]["pageProps"]["state"]["data"]["entity"]
    except (KeyError, TypeError, json.JSONDecodeError) as e:
        raise ValueError(f"unexpected page data: {e!r}")
    tracks = [{"title": t.get("title", ""),
               "artists": [a.strip() for a in t.get("subtitle", "").replace("\xa0", " ").split(",") if a.strip()],
               "duration_s": round(t["duration"] / 1000) if isinstance(t.get("duration"), (int, float)) else None}
              for t in entity.get("trackList") or []]
    return {"id": pid, "url": f"https://open.spotify.com/playlist/{pid}", "name": entity.get("name", ""),
            "owner": entity.get("subtitle", ""), "tracks": tracks, "tracks_shown": len(tracks),
            "may_have_more": len(tracks) >= EMBED_LIMIT}


def fold(text):
    text = unicodedata.normalize("NFKD", text.casefold())
    return re.sub(r"[^\w\s]", " ", "".join(ch for ch in text if not unicodedata.combining(ch)))


def has_seed(playlist, seed):
    """True if a track contains every word of `seed` in its title and artists; None if it may be past the first 100."""
    words = fold(seed).split()
    for t in playlist["tracks"]:
        haystack = fold(t["title"] + " " + " ".join(t["artists"])).split()
        if all(w in haystack for w in words):
            return True
    return None if playlist["may_have_more"] else False


def fetch(pid):
    req = urllib.request.Request(f"https://open.spotify.com/embed/playlist/{pid}",
                                 headers={"User-Agent": "Mozilla/5.0", "Accept-Language": "en"})
    with urllib.request.urlopen(req, timeout=30) as r:
        return r.read().decode("utf-8", "replace")


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("playlists", nargs="+", metavar="playlist")
    ap.add_argument("--seed", help="check each playlist has this song, e.g. \"Hudson Mohawke Cbat\"")
    args = ap.parse_args()
    results, failed = {}, False
    for i, given in enumerate(args.playlists):
        if i:
            time.sleep(1)  # gently: one playlist a second
        try:
            pid = playlist_id(given)
            playlist = parse_page(fetch(pid), pid)
            if args.seed:
                playlist["has_seed"] = has_seed(playlist, args.seed)
            results[given] = playlist
        except Exception as e:  # noqa: BLE001  one bad playlist must not lose the others
            results[given] = {"error": str(e)}
            failed = True
    out = results[args.playlists[0]] if len(args.playlists) == 1 else results
    print(json.dumps(out, ensure_ascii=False, indent=2))
    sys.exit(1 if failed else 0)


if __name__ == "__main__":
    main()
