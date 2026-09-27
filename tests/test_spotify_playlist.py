"""Tests for scripts/spotify_playlist.py (no network: built from a page in the embed page's shape)."""
import json
import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "scripts"))
import spotify_playlist as sp  # noqa: E402

fails = 0


def check(cond, msg):
    global fails
    print(("  ok    " if cond else "  FAIL  ") + msg)
    fails += 0 if cond else 1


def page(name, owner, tracks):
    data = {"props": {"pageProps": {"state": {"data": {"entity": {
        "name": name, "subtitle": owner,
        "trackList": [{"title": t, "subtitle": a, "duration": 171429} for t, a in tracks]}}}}}}
    return ('<html><script id="__NEXT_DATA__" type="application/json">' + json.dumps(data)
            + "</script></html>")


pid = "50wGFRBh1aHH9QM0LMeB3Z"
check(sp.playlist_id(f"https://open.spotify.com/playlist/{pid}?si=abc") == pid
      and sp.playlist_id(f"spotify:playlist:{pid}") == pid and sp.playlist_id(pid) == pid,
      "the id is found in a url, a spotify: uri, or on its own")
try:
    sp.playlist_id("https://example.com/nope")
    check(False, "a url with no playlist id is refused")
except ValueError:
    check(True, "a url with no playlist id is refused")

p = sp.parse_page(page("Cbat - Hudson Mohawke", "ola.wav",
                       [("Cbat", "Hudson Mohawke"), ("One In The Chamber", "NorthSideBenji,\xa0Unknown T")]), pid)
check(p["name"] == "Cbat - Hudson Mohawke" and p["owner"] == "ola.wav" and p["url"].endswith(pid),
      "name, owner and url")
check(p["tracks"][1] == {"title": "One In The Chamber", "artists": ["NorthSideBenji", "Unknown T"], "duration_s": 171},
      "tracks: title, artists split on commas (non-breaking spaces too), duration in seconds")
check(p["tracks_shown"] == 2 and p["may_have_more"] is False, "a short playlist is complete")
check(sp.has_seed(p, "hudson mohawke cbat") is True and sp.has_seed(p, "Cbat") is True,
      "the seed song is found, whatever the case and word order")
check(sp.has_seed(p, "Burial Archangel") is False, "a song that isn't there is false")

long = sp.parse_page(page("Long", "someone", [(f"Song {i}", "Artist") for i in range(100)]), pid)
check(long["may_have_more"] is True and sp.has_seed(long, "Burial Archangel") is None,
      "at 100 tracks (the embed's limit) a missing seed is unknown, not false")
try:
    sp.parse_page("<html>no data</html>", pid)
    check(False, "a page without playlist data raises a clear error")
except ValueError as e:
    check("no playlist data" in str(e), "a page without playlist data raises a clear error")

print("ALL PASSED" if fails == 0 else f"FAILED {fails}")
sys.exit(1 if fails else 0)
