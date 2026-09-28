# To do

## Resume extraction after a usage pause

When Claude usage runs out mid-video, the video goes back in the queue, and when it's mined again every Haiku chunk runs again, including the ones that already finished. The download and TypeSafe's answers are cached, so only the Haiku work is repeated, but on a big video that's most of the cost. Keep chunks whose output finished cleanly (per `children.jsonl`) and run only the rest.

## Give TypeSafe the video's artist by name

TypeSafe's "another artist than the video's own" question sees the video's title and channel. A title without the artist ("Kids") on a label's channel doesn't say who the artist is. Add a `video_artist` field to the state, filled from the names in `own_artists.json` (the song list's artist, the title before " - ", the channel). Keep it in the state, not in the question text: titles and channels are written by uploaders. Worth doing if the mining panel's Notes show many own-artist comments getting through; then re-run `mining/typesafe_experiment.py` and save its answers as the test fixture again.

## Limit which sites the agent can fetch

The agent reads YouTube comments written by strangers and can fetch any web page, so a comment could still try to steer which pages it reads (it can't read `.env`). An allowlist of research sites (Bandcamp, Last.fm, Discogs, Wikipedia, Reddit, ...) for WebFetch would close that, at the cost of the agent sometimes not reading a page it wanted.

## Phone layout

The page is laid out for a computer screen. On a phone it stacks and scrolls, but nobody has designed for it: the tabs wrap, the playlist's Why column squeezes, and the chat sits below everything. Worth doing if noize-buffet is ever reachable from a phone (it only listens on localhost today).
