# To do

## Ask TypeSafe whether a comment names an artist other than the video's own

**Idea (2026-09-26).** Today TypeSafe answers two yes/no questions per comment: does it name a song, does it name an artist. Every comment either question flags goes to a Haiku agent, which also has to spot comments that only name the video's own artist and tag them `[own artist]`. Add a TypeSafe question so those never reach Haiku:

> Does `youtube_comment` name a musical artist other than the artist of the video called `video_title`?

Not "does it name only one artist": one artist is often the best kind of lead ("Burial vibes"). What matters is whether that artist is someone other than the video's own. A comment naming the own artist and someone else still answers yes, so the "more than one artist" case is covered.

**Numbers from the real fixture** (`tests/fixtures/mining/real_comments.jsonl`, 564 comments, 65 flagged by TypeSafe, Haiku's answers as ground truth):

| Flagged comments | Count |
|---|---|
| Name only the video's own artist | 24 |
| Name nothing musical | 3 |
| Name another artist | 36 (22 of them name exactly one artist; 2 name the own artist and another) |
| No Haiku answer recorded | 2 |

A "names another artist" question would let Haiku skip about 27 of 65 flagged comments (42%), so less Claude usage and smaller chunks.

**How it would work**
- `mining/find_music_mentions.py`: put the video title in each request's `state` (about 20 more input tokens per comment), add a `names_other_artist` question with its own threshold, and record its probability in the results.
- Flag rule: send a comment to Haiku when it answers yes for another artist, or when it names a song (the "song only" case still matters). Comments skipped because they only name the video's own artist are counted and shown per video ("N comments only name the video's own artist, skipped"), never dropped silently.
- Keep the `[own artist]` tag in the Haiku instructions as a safety net.
- Doesn't help the keyword fallback (no TypeSafe key): Haiku still tags own-artist comments there.

**Cost.** TypeSafe bills input tokens only, so one more question is cents per mined video.

**Risks to measure first**
- False skips: a real lead in a comment wrongly called "own artist only" never reaches Haiku. Measure precision and recall of the new question against Haiku's answers on the 564 real comments, then pick the threshold. Prefer keeping borderline comments.
- Video titles that aren't "Artist - Song" (fan uploads, live sets, remixes) may confuse it. Check how often.

**First step.** A small script that asks TypeSafe the new question about all 564 fixture comments and compares with the fixture's Haiku answers. About a cent of TypeSafe credit; needs the TypeSafe key. If the numbers are good, add it as a task before the per-video pipeline (Stage 3, Task 6).
