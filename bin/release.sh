#!/usr/bin/env bash
# Release primitives, sourced by bin/deploy.sh and bin/rollback.sh.
#
# Separate from the deploy pipeline because these three functions are the only
# ones that can destroy a live site, and they are the only ones the suite can
# exercise without a git remote, a Composer registry and a webserver. The
# pipeline around them is ordering; this is the part that must be atomic.
#
# Layout (plan §3):
#
#   $DEPLOY_ROOT/
#     current -> releases/<timestamp>/   code + vendor; disposable
#     releases/<timestamp>/
#     shared/content/                    its own private git repository
#     shared/var/                        cache, locks, submissions
#     shared/.env                        secrets

set -euo pipefail

# Point `current` at a release, atomically.
#
# symlink-then-rename, never rm-then-symlink: rename(2) is atomic, so a request
# arriving mid-deploy sees either the old release or the new one. Removing the
# symlink first leaves a window in which `current` does not exist at all, and
# on a busy site that window is a 500 for real visitors.
release_switch() {
  local root="$1" target="$2"

  [ -d "$target" ] || { echo "release_switch: no such release: $target" >&2; return 1; }

  ln -sfn "$target" "$root/.current.tmp"
  mv -Tf "$root/.current.tmp" "$root/current"
}

# The release `current` points at right now, or empty.
release_current() {
  local root="$1"
  [ -L "$root/current" ] && readlink -f "$root/current" || true
}

# The newest release that is not the current one — what a rollback goes to.
release_previous() {
  local root="$1" current
  current="$(release_current "$root")"

  # `|| true` on the grep: with no match it exits 1, and under pipefail that
  # would make "there is no previous release" indistinguishable from an error.
  { find "$root/releases" -mindepth 1 -maxdepth 1 -type d 2>/dev/null \
    | sort \
    | grep -vFx "$current" \
    | tail -n 1; } || true
}

# Keep the last N releases. Never the current one, whatever N says: a rollback
# target that has been deleted is not a rollback target.
release_prune() {
  local root="$1" keep="${2:-3}" current
  current="$(release_current "$root")"

  { find "$root/releases" -mindepth 1 -maxdepth 1 -type d 2>/dev/null \
    | sort -r \
    | tail -n +"$((keep + 1))" \
    | grep -vFx "$current" \
    | while read -r old; do
        [ -n "$old" ] && rm -rf "$old"
      done; } || true
}
