#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(CDPATH= cd -- "$(dirname "$0")" && pwd)"

exec php "$ROOT_DIR/app/scripts/reset.php" "$@"
