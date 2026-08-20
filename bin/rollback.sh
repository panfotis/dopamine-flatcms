#!/usr/bin/env bash
#
# Back to the previous release, in one command.
#
#   bin/rollback.sh
#
# Content is untouched, because no client-owned state lives inside a release —
# that is the whole reason the layout is shaped this way. Rolling back code
# never rolls back what the client wrote.

set -euo pipefail

root="${DEPLOY_ROOT:-/var/www/example-domain}"

# shellcheck source=bin/release.sh
. "$(dirname "$0")/release.sh"
# shellcheck source=bin/site-env.sh
. "$(dirname "$0")/site-env.sh"

site_env_load "$root/shared/.env" 1

previous="$(release_previous "$root")"
[ -n "$previous" ] || { echo "no previous release to roll back to" >&2; exit 1; }

release_switch "$root" "$previous"

if [ -n "${CF_ZONE_ID:-}" ] && [ -n "${CF_API_TOKEN:-}" ]; then
  curl -fsS -X POST "https://api.cloudflare.com/client/v4/zones/$CF_ZONE_ID/purge_cache" \
    -H "Authorization: Bearer $CF_API_TOKEN" -H "Content-Type: application/json" \
    --data '{"purge_everything":true}' -o /dev/null
fi

echo "Rolled back to $(basename "$previous")"
