#!/usr/bin/env bash
# Run the whole suite. Exit non-zero if anything fails.
set -u
dir="$(cd "$(dirname "$0")" && pwd)"
fail=0
for t in "$dir"/0*.php; do
  echo "── $(basename "$t") ──────────────────────────────"
  php "$t" || fail=1
done
exit $fail
