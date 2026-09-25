#!/usr/bin/env bash
# Run every test; exit non-zero if any fails.
cd "$(dirname "$0")" || exit 1
status=0
for t in test_*.php; do
    [ -e "$t" ] || continue
    echo "== $t"; php "$t" || status=1
done
for t in test_*.py; do
    [ -e "$t" ] || continue
    echo "== $t"; python3 "$t" || status=1
done
exit $status
