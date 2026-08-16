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
#   DEPLOY_ROOT     /var/www/pelatis
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

root="${DEPLOY_ROOT:-/var/www/pelatis}"
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
if ! curl -fsS -o /dev/null "$smoke_url"; then
  echo "live smoke test failed — switching back to $(basename "$previous")" >&2
  [ -n "$previous" ] && release_switch "$root" "$previous"
  exit 1
fi

# Everything, not by tag: a template or CSS change has no page id, and tag
# purging would leave a year of stale HTML on every page it touched.
step "Purging the edge"
if [ -n "${CF_ZONE_ID:-}" ] && [ -n "${CF_API_TOKEN:-}" ]; then
  curl -fsS -X POST "https://api.cloudflare.com/client/v4/zones/$CF_ZONE_ID/purge_cache" \
    -H "Authorization: Bearer $CF_API_TOKEN" \
    -H "Content-Type: application/json" \
    --data '{"purge_everything":true}' -o /dev/null
else
  echo "  CF_ZONE_ID/CF_API_TOKEN unset — skipping purge" >&2
fi

release_prune "$root" "$keep"
trap - EXIT

printf '\n\033[32mDeployed %s (rollback: bin/rollback.sh)\033[0m\n' "$(basename "$release")"
