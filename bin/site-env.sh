#!/usr/bin/env bash

# Load the trusted, operator-owned environment shared by every atomic release.
# The generated .env is deliberately shell-compatible as well as compatible
# with Symfony Dotenv: PHP and operational scripts therefore read one file,
# instead of growing two production configurations that can drift.
site_env_load() {
  local default_file="${1:-}"
  local required="${2:-0}"
  local file="${ENV_FILE:-$default_file}"

  if [ -z "$file" ] || [ ! -f "$file" ]; then
    if [ "$required" = "1" ]; then
      echo "site environment file not found: ${file:-ENV_FILE is empty}" >&2
      return 1
    fi

    return 0
  fi

  set -a
  # shellcheck disable=SC1090
  . "$file"
  set +a
  export ENV_FILE="$file"
}
