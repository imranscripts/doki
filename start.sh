#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(CDPATH= cd -- "$(dirname "$0")" && pwd)"
SERVICE_NAME="php-app"
SCRIPT_PATH="/var/www/html/scripts/runtime-control.php"
APP_SERVICE_PORT="80"
DEFAULT_APP_URL="http://localhost:8099"

cd "$ROOT_DIR"

if ! command -v docker >/dev/null 2>&1; then
    echo "Docker CLI is not available."
    echo "Install Docker Desktop, then rerun ./start.sh."
    exit 1
fi

if ! docker info >/dev/null 2>&1; then
    echo "Docker is installed, but the Docker daemon is not reachable."
    echo "Start Docker Desktop, then rerun ./start.sh."
    exit 1
fi

if docker compose version >/dev/null 2>&1; then
    COMPOSE=(docker compose)
elif command -v docker-compose >/dev/null 2>&1; then
    COMPOSE=(docker-compose)
else
    echo "Docker Compose is not available."
    echo "Install Docker Desktop with Compose v2, then rerun ./start.sh."
    exit 1
fi

resolve_doki_url() {
    local published host port

    published="$("${COMPOSE[@]}" port "$SERVICE_NAME" "$APP_SERVICE_PORT" 2>/dev/null | tail -n 1 || true)"
    if [ -z "$published" ]; then
        echo "$DEFAULT_APP_URL"
        return
    fi

    port="${published##*:}"
    host="${published%:*}"
    host="${host#[}"
    host="${host%]}"

    if [[ ! "$port" =~ ^[0-9]+$ ]]; then
        echo "$DEFAULT_APP_URL"
        return
    fi

    if [ "$host" = "0.0.0.0" ] || [ "$host" = "::" ] || [ "$host" = "" ] || [ "$host" = "127.0.0.1" ]; then
        host="localhost"
    fi

    echo "http://$host:$port"
}

echo "Starting Doki core containers..."
"${COMPOSE[@]}" up -d

echo "Starting Doki app runtimes inside '$SERVICE_NAME'..."
set +e
"${COMPOSE[@]}" exec -T "$SERVICE_NAME" php "$SCRIPT_PATH" start --skip-core "$@"
app_exit=$?
set -e

if [ "$app_exit" -eq 0 ]; then
    echo
    echo "Open Doki: $(resolve_doki_url)"
fi

exit "$app_exit"
