# To do

## Run on your machine (needs Claude usage or the TypeSafe key)

Everything below is built and tested with stand-ins; these runs check it against the real services.

1. **`php scripts/check_confinement.php`**: first real run 2026-09-27, $0.04. Every escape refused in both permission modes (so `mining_child_permission_mode` stays `''`); symlink not followed; only Read and Write, no MCP servers; budget stop is an error result (`error_max_budget_usd`, exit 1); no transcripts; nothing left in the folders. Under `--restricted` no instruction file loads by itself, not even the folder's CLAUDE.md; the check now tests the way mining uses it (the prompt says to follow CLAUDE.md). **Run it once more** to see that part pass. Original notes: (about five cheap Haiku runs). Expect `PASS`. It also answers the open questions from the research report (2026-09-26):
   - which permission mode confines the child: if only `dontAsk` passes, set `"mining_child_permission_mode": "dontAsk"` in `config.json`;
   - what a tiny `--max-budget-usd` returns (exit code, subtype, is_error): if it doesn't show as an error, the runner would report a budget stop as "no output written";
   - which instruction files reach the child, and its tools and MCP servers (only Read and Write, none);
   - whether a session transcript is still saved, and what a real run leaves in its folder. If `claude` creates `.claude/` or `CLAUDE.local.md` there, every later run in that folder is refused until the file is removed; the runner would need to allow that file.
   - Out of usage, it says `INCONCLUSIVE`; run it again after the reset.
2. **`python3 mining/typesafe_experiment.py`**: first real run 2026-09-27, $0.018. AI instructions: all 10 attacks 0.92-0.99, highest real comment 0.06 (keep 0.5). Other artist: no known lead lost at any threshold 0.1-0.7, and 0.3-0.7 skip the same 61, so keep 0.3; the 38 of those 61 that Haiku never checked were read by hand: all praise the video's own artist ("I love you Alice"), none names another. Seeing the channel makes TypeSafe flag more own-artist comments (109 vs 65) and the new question drops them: 48 go to Haiku instead of 65. Spam: nothing real at 0.9; a genuine comment scored 0.53, so keep 0.9. The answers are saved as `tests/fixtures/mining/typesafe_answers.jsonl`; `tests/test_mining_real.py` checks them. Original notes: (about a cent of TypeSafe credit). Asks the five questions about the 564 saved comments and ten made-up attacks, and writes `data/typesafe-experiment.md`:
   - pick `typesafe_other_artist_threshold` from the table (the highest one that loses no lead; 0.3 now);
   - check no real comment trips the AI-instruction question, and which made-up attacks it misses;
   - read the most spam-like comments before trusting `typesafe_spam_threshold` (0.9 now).
3. **Mine one real video**: first run 2026-09-27 on eN6jkWxxm2Y (Hudson Mohawke's Cbat): 402 s (download 95 s, TypeSafe 157 s for 3,000 comments, one Haiku chunk 150 s); 128 flagged, 21 own-artist skipped, 128 of 128 covered, 54 leads (11 confirmed). Found: Hudson Mohawke himself was a confirmed lead (his pinned promo comment, 25,014 likes): Haiku only saw the title. Fixed: `own_artists.json` (song artist, title before " - ", channel/uploader/artist fields) is dropped in the merge and shown in the chunk header. Haiku: one child, 149 s, 4 turns, $0.12 API-equivalent, no refusals or tampering; coverage 128/128, nothing unparsed. TypeSafe about $0.09 (five questions, ~730 input tokens a comment). Re-merge with own_artists.json confirmed Hudson Mohawke is no longer a lead. Command, in a scratch database so your data isn't touched:
   `NB_DB="$(pwd)/data/mining-trial.sqlite" NB_COMMENTS_DIR="$(pwd)/data/mining-trial" php scripts/mine_video.php <video_id>`
   then `NB_DB="$(pwd)/data/mining-trial.sqlite" php bin/nb.php leads`. Pick a liked song with a few hundred comments. Note the time, `children.jsonl` costs, and any problems.

## Done (2026-09-27)

- TypeSafe asks three more questions in the same call, with the video's title and channel: another artist than the video's own (skips own-artist-only comments), instructions to an AI (quarantined to `quarantined.jsonl`, never sent to Claude), spam (skipped). All counted in the mining row's `notes`. Cached answers from before are asked again. The keyword filter quarantines obvious injection attempts too.
- The child runs with `--no-session-persistence`; `mining_child_permission_mode` can add `dontAsk`.
- `nb_run_child` refactored into helpers; watches entries with `lstat` and inside existing folders; one child per folder; stray instruction names derived from `NB_INSTRUCTION_FILES`; `claude`, `python3`, `yt-dlp` found by absolute path; durations on a monotonic clock (`nb_clock`) everywhere in the app.
- Found in the code review and fixed: a tampering child's output was merged (now the video fails and its outputs are discarded); the merge read failed and half-mined folders (now only finished videos); failed videos were never retried (now after a day); any website could POST to the local API (now refused); the agent and children could read `.env` (now denied by a settings rule).

## Known gaps

- A file the child creates that wasn't there before is reported, not removed; `mine_video.php` stops the video when that happens, so it's never trusted.
- The keyword filter's injection check is crude and easy to word around; without a TypeSafe key, the child's confinement is the real protection.
- Comment text still reaches the agent through `nb.php lead`. Defences: TypeSafe's quarantine, the `CLAUDE.md` rule that comment text is untrusted, and the `.env` deny rule. The agent can still fetch web pages, so a comment could still try to steer which pages it reads.
- If a managed `managed-mcp.json` policy is ever deployed on the machine, `--strict-mcp-config` makes Claude Code exit at start-up and every child fails; drop that flag from `nb_child_command()` if so (see the README).
