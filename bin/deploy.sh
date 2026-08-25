#!/usr/bin/env bash
#
# Build a new release without touching live state, then switch to it.
#
#   bin/deploy.sh <git-revision>
#
# The order below is the entire safety property, and it is one-way: everything
# that can fail runs *before* `current` moves. A failed pre-switch step leaves
# the running site exactly as it was — same code, same content, no downtime and
# nothing to undo. Only the smoke test runs after, and if it fails the switch is
# reversed before anyone is told the deploy succeeded.
#
# Configuration, all with defaults that match plan §3:
#
#   DEPLOY_ROOT     /var/www/example-domain
#   DEPLOY_REPO     the git remote to fetch from
#   DEPLOY_KEEP     releases to keep for rollback (default 3)
#   SMOKE_URL       what the post-switch smoke test requests
#   PHP_BIN         php
#   COMPOSER_BIN    composer
#
# The private Composer repository credential is read from shared/auth.json,
# which Composer picks up via COMPOSER_HOME. It is deploy-key scoped to the
# package repository and to nothing else, it never enters a release directory,
# and it is rotated by replacing that one file and re-running this script —
# there is no second place it is cached. Rotate on any operator leaving and at
# least annually; the date of the last rotation belongs in the site's runbook.

set -euo pipefail

root="${DEPLOY_ROOT:-/var/www/example-domain}"
env_file="${ENV_FILE:-$root/shared/.env}"
repo="${DEPLOY_REPO:-}"
keep="${DEPLOY_KEEP:-3}"
php="${PHP_BIN:-php}"
composer="${COMPOSER_BIN:-composer}"
smoke_url="${SMOKE_URL:-http://127.0.0.1/}"
revision="${1:-}"

[ -n "$revision" ] || { echo "usage: bin/deploy.sh <git-revision>" >&2; exit 2; }
[ -n "$repo" ] || { echo "DEPLOY_REPO is not set" >&2; exit 2; }
[ -f "$env_file" ] || { echo "production environment file not found: $env_file" >&2; exit 2; }

# shellcheck source=bin/release.sh
. "$(dirname "$0")/release.sh"
# shellcheck source=bin/site-env.sh
. "$(dirname "$0")/site-env.sh"

step() { printf '\n\033[1m── %s\033[0m\n' "$1"; }

release="$root/releases/$(date -u +%Y%m%d-%H%M%S)"
previous="$(release_current "$root")"

# A failed build must leave nothing half-made behind either — the next deploy
# would otherwise pick a "previous release" that was never live.
cleanup_failed_build() {
  if [ -n "${release:-}" ] && [ -d "$release" ] && [ "$(release_current "$root")" != "$release" ]; then
    rm -rf "$release"
  fi
}
trap cleanup_failed_build EXIT

step "Fetching $revision"
mkdir -p "$release"
# Bundles live outside the release so every hash stays resolvable across
# deploys and rollbacks. php-fpm writes here at runtime too: see README.
mkdir -p "$root/shared/assets"
git -c advice.detachedHead=false clone --quiet --no-checkout "$repo" "$release/.git-tmp"
git --git-dir="$release/.git-tmp/.git" --work-tree="$release" checkout --quiet "$revision" -- .
rm -rf "$release/.git-tmp"
echo "$revision" > "$release/REVISION"

step "Installing dependencies"
COMPOSER_HOME="$root/shared" "$composer" install \
  --working-dir="$release" --no-dev --optimize-autoloader --no-interaction --no-progress

step "Auditing dependencies"
COMPOSER_HOME="$root/shared" "$composer" audit --working-dir="$release" --no-interaction

step "Running the test suite"
if [ -x "$release/tests/run.sh" ]; then
  (cd "$release" && bash tests/run.sh --portable)
else
  # create-project sites intentionally contain no copy of the engine suite.
  # Their locked package version has already passed engine CI; still verify
  # that this server satisfies the exact platform selected by Composer.
  "$composer" check-platform-reqs --working-dir="$release" --no-dev
fi

# Only after tests have run in their isolated fixture environment. Loading this
# earlier would let a test mutation follow CONTENT_PATH into live shared state.
site_env_load "$env_file" 1

# Against the *shared* content this release is about to serve, not against the
# fixtures in the release. A schema rename that breaks a live page is exactly
# the failure this catches, and it can only be seen from here.
step "Checking shared content"
env CONTENT_PATH="$root/shared/content" \
    VAR_PATH="$root/shared/var" \
    UPLOADS_PATH="$root/shared/content/uploads" \
    PUBLIC_ASSETS_PATH="$root/shared/assets" \
    ENV_FILE="$env_file" \
    "$php" "$release/bin/doctor"

# The release serving itself, before anything points at it. Catches a missing
# extension or an unwritable path that every check above passes on the build
# host and fails on this one.
step "Smoke-testing the new release"
smoke_port="${SMOKE_PORT:-8781}"
env CONTENT_PATH="$root/shared/content" \
    VAR_PATH="$root/shared/var" \
    UPLOADS_PATH="$root/shared/content/uploads" \
    PUBLIC_ASSETS_PATH="$root/shared/assets" \
    ENV_FILE="$env_file" \
    "$php" -S "127.0.0.1:$smoke_port" -t "$release/public" "$release/public/router.php" >/dev/null 2>&1 &
smoke_pid=$!
trap 'kill "$smoke_pid" 2>/dev/null || true; cleanup_failed_build' EXIT
sleep 1
curl -fsS -o /dev/null "http://127.0.0.1:$smoke_port/" || {
  echo "the new release does not serve its own home page" >&2
  exit 1
}
kill "$smoke_pid" 2>/dev/null || true
trap cleanup_failed_build EXIT

# ── Everything above this line was reversible by doing nothing. ─────────────

step "Switching to $(basename "$release")"
release_switch "$root" "$release"

step "Smoke-testing the live site"
# Cache-busted: this runs BEFORE the purge, so a plain request can be answered
# from the edge with the *previous* release's HTML and prove nothing about the
# one just switched to.
smoke_html="$(curl -fsS -H 'Cache-Control: no-cache' -H 'Pragma: no-cache' \
  "${smoke_url}?deploy-smoke=$(basename "$release")" 2>/dev/null)" || smoke_html=''
if [ -z "$smoke_html" ]; then
  echo "live smoke test failed — switching back to $(basename "$previous")" >&2
  [ -n "$previous" ] && release_switch "$root" "$previous"
  exit 1
fi

# Writing a bundle is not the same as serving one: if php-fpm writes to
# shared/assets while the web server aliases somewhere else, generation
# succeeds, the page emits links, and there is no inline fallback to catch it.
# Only fetching the URLs the page actually printed proves delivery.
step "Fetching the asset bundles the live page references"
asset_urls="$(printf '%s' "$smoke_html" | grep -oE '/assets/(css|js)/[a-z]+-[0-9a-f]{12}\.(css|js)' | sort -u)"
if [ -n "$asset_urls" ]; then
  base_url="${smoke_url%/}"
  for asset_path in $asset_urls; do
    if ! curl -fsS -o /dev/null "${base_url}${asset_path}"; then
      echo "bundle $asset_path is referenced but not served — switching back to $(basename "$previous")" >&2
      [ -n "$previous" ] && release_switch "$root" "$previous"
      exit 1
    fi
  done
  echo "  · $(printf '%s\n' "$asset_urls" | wc -l | tr -d ' ') bundle(s) served"
else
  # A site delivering inline, or one with no local assets at all, is valid.
  echo "  · no local bundles referenced (inline delivery)"
fi

# Everything, not by tag: a template or CSS change has no page id, and tag
# purging would leave a year of stale HTML on every page it touched.
#
# HTTP 200 is not proof: Cloudflare answers 200 with {"success": false} for a
# token that cannot purge this zone. Pruning depends on this having worked, so
# the body is read rather than discarded.
step "Purging the edge"
purged=0
if [ -n "${CF_ZONE_ID:-}" ] && [ -n "${CF_API_TOKEN:-}" ]; then
  purge_body="$(curl -fsS -X POST "https://api.cloudflare.com/client/v4/zones/$CF_ZONE_ID/purge_cache" \
    -H "Authorization: Bearer $CF_API_TOKEN" \
    -H "Content-Type: application/json" \
    --data '{"purge_everything":true}' 2>/dev/null)" || purge_body=''
  case "$purge_body" in
    *'"success":true'*|*'"success": true'*) purged=1 ;;
    *) echo "  edge purge did not report success: ${purge_body:-no response}" >&2 ;;
  esac
else
  echo "  CF_ZONE_ID/CF_API_TOKEN unset — skipping purge" >&2
fi

# Old bundles are only safe to delete once no cached HTML can still name them,
# which is exactly what the purge guarantees. No purge, no prune — a full disk
# is recoverable, a page whose stylesheet 404s is not.
if [ "$purged" = 1 ]; then
  step "Pruning asset bundles older than seven days"
  env CONTENT_PATH="$root/shared/content" \
      VAR_PATH="$root/shared/var" \
      UPLOADS_PATH="$root/shared/content/uploads" \
      PUBLIC_ASSETS_PATH="$root/shared/assets" \
      ENV_FILE="$env_file" \
      "$php" "$release/bin/doctor" --prune-assets --quiet \
    || echo "  bundle pruning failed — disk maintenance only, the release is healthy" >&2
else
  echo "  · skipping bundle prune: the edge was not purged" >&2
fi

release_prune "$root" "$keep"
trap - EXIT

# The release is live and serving correctly either way; a failed purge is an
# operator task, not a reason to roll a good release back — a rollback's own
# correctness would depend on the very purge that just failed.
if [ "$purged" != 1 ] && [ -n "${CF_ZONE_ID:-}" ]; then
  printf '\n\033[33mDeployed %s, but the edge purge FAILED — re-run it before trusting the cache\033[0m\n' "$(basename "$release")"
  exit 1
fi

printf '\n\033[32mDeployed %s (rollback: bin/rollback.sh)\033[0m\n' "$(basename "$release")"
