#!/usr/bin/env bash
set -euo pipefail
exec "$(dirname "$0")/../vendor/dopamine/flatcms/bin/rollback.sh" "$@"
