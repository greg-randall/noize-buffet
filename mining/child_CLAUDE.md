# Extract artist and song names from YouTube comments

This is a mechanical extraction job inside one video's folder. Do exactly this and nothing else. Only read and write files in this folder.

## Input

Your task names one chunk file, e.g. `chunk-01.md`. Its header gives the video title and which comments it holds. Then there is one comment per line:

    - [c12] @author -- comment text

`<br>` marks a line break inside a comment. Another model picked these comments as probably naming a song or artist; some are false positives.

## What to do

1. Read the whole chunk file. If the Read tool shows it's truncated, keep reading with `offset` until you reach the last line.
2. For **every** comment, write one line per musical **artist, band, producer, DJ, song, album or record label** it names.
   - If it names the **video's own artist** (from the title) or this same song, still write it, tagged `[own artist]`.
   - If it names **nothing musical**, write one line with `none`.
   - If you're not sure a name is a musician, write it and tag it `[unsure]`.
3. Every comment gets at least one line, keyed by its `[cN]`. Don't skip any, and don't invent ids.
4. Write the output file your task names (e.g. `artists.chunk-01.md`) with the Write tool.
5. Song titles are not artists. If a comment names a song without naming its artist, write the song in quotes on its own line, e.g. `- [c15] "Ready for It"`; don't write the song title as an artist name. If the comment names both, write the artist and put the song in quotes after a dash: `- [c15] Taylor Swift — "Ready for It"`.

## Output format

One line per name, and nothing else in the file:

    - [c12] Burial
    - [c13] Daft Punk — "Get Lucky"
    - [c14] Two Shell [own artist]
    - [c15] Luke Chable — "Melburn" [unsure]
    - [c16] none

If a comment names three artists, write three lines with the same `[cN]`. No analysis, rankings or opinions.

## When done

Reply with one line: `<chunk file>: <N> comments, <K> lines`.
