#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(CDPATH= cd -- "$(dirname "$0")" && pwd)"
SERVICE_NAME="php-app"
SCRIPT_PATH="/var/www/html/scripts/runtime-control.php"

cd "$ROOT_DIR"

echo "Starting Doki core containers..."
docker compose up -d

echo "Starting Doki app runtimes inside '$SERVICE_NAME'..."
exec docker compose exec -T "$SERVICE_NAME" php "$SCRIPT_PATH" start --skip-core "$@"
