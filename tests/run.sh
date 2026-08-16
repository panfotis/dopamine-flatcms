#!/usr/bin/env bash
# Run the whole suite. Exit non-zero if anything fails.
set -u
dir="$(cd "$(dirname "$0")" && pwd)"

# Production has no DDEV router. Portable mode still runs every test file, but
# the two files with real-nginx probes skip only those probes. Start from a
# clean environment so a site's shared .env can never point a test mutation at
# live content; the explicit values reproduce the safe DDEV test configuration.
if [ "${1:-}" = "--portable" ]; then
  exec env -i \
    PATH="$PATH" \
    LANG="${LANG:-C.UTF-8}" \
    AUTH_DEV_BYPASS=1 \
    AUTH_MODE=cf_access \
    SITE_BASE_URL=https://dopamine-flatcms.ddev.site \
    TEST_LIVE_HTTP=0 \
    bash "$0"
fi

fail=0
for t in "$dir"/0*.php; do
  echo "── $(basename "$t") ──────────────────────────────"
  php "$t" || fail=1
done
exit $fail
