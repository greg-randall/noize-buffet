#!/usr/bin/env bash
# Moves the data from before stations (data/, brief.md, taste.md, handoff.md) into profiles/main/.
# Stop start.py first. A copy of everything is kept in backup-before-stations/ until you delete it.
set -euo pipefail
cd "$(dirname "$0")"

[ -f data/music.sqlite ] || { echo "Nothing to migrate: there is no data/music.sqlite."; exit 0; }
[ -e profiles/main ] && { echo "profiles/main already exists; not touching it." >&2; exit 1; }

mkdir backup-before-stations
cp -a data backup-before-stations/
for f in brief.md taste.md handoff.md; do [ -f "$f" ] && cp -a "$f" backup-before-stations/; done

mkdir -p profiles/main
mv data/* profiles/main/
rmdir data
for f in brief.md taste.md handoff.md; do [ -f "$f" ] && mv "$f" profiles/main/; done
echo '{"name": "Main"}' > profiles/main/profile.json

echo "Done. Your data is now in profiles/main/. Run: python3 start.py"
echo "Once it all looks right, delete backup-before-stations/."
