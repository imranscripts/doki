#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(CDPATH= cd -- "$(dirname "$0")" && pwd)"
cd "$ROOT_DIR"

# The service name of the PHP container in docker-compose.yml
SERVICE_NAME="php-app"

# The path to the PHP setup script inside the container
# The 'app' directory is mounted at /var/www/html
SCRIPT_PATH="/var/www/html/scripts/setup.php"

has_local_setup_data() {
    [ -s "$ROOT_DIR/app/data/doki.db" ]
}

print_container_not_running() {
    if has_local_setup_data; then
        echo "Doki looks already set up locally, but the app container is stopped."
        echo "Run ./start.sh to start Doki."
        echo "After it starts, use ./setup.sh --check if you want to inspect setup health."
        return
    fi

    echo "Doki containers are not running yet, so setup cannot run inside the app container."
    echo "Run ./start.sh to start the Doki containers, then rerun ./setup.sh."
}

if ! command -v docker >/dev/null 2>&1; then
    echo "Docker CLI is not available."
    echo "Install Docker Desktop, then rerun ./setup.sh."
    exit 1
fi

if ! docker info >/dev/null 2>&1; then
    echo "Docker is installed, but the Docker daemon is not reachable."
    echo "Start Docker Desktop, then rerun ./setup.sh."
    exit 1
fi

if docker compose version >/dev/null 2>&1; then
    COMPOSE=(docker compose)
elif command -v docker-compose >/dev/null 2>&1; then
    COMPOSE=(docker-compose)
else
    echo "Docker Compose is not available."
    echo "Install Docker Desktop with Compose v2, then rerun ./setup.sh."
    exit 1
fi

CONTAINER_ID="$("${COMPOSE[@]}" ps -q "$SERVICE_NAME" 2>/dev/null | head -n 1 || true)"
if [ -z "$CONTAINER_ID" ]; then
    CONTAINER_ID="$("${COMPOSE[@]}" ps -a -q "$SERVICE_NAME" 2>/dev/null | head -n 1 || true)"
fi

if [ -z "$CONTAINER_ID" ]; then
    print_container_not_running
    exit 1
fi

RUNNING="$({ docker inspect -f '{{.State.Running}}' "$CONTAINER_ID" 2>/dev/null || true; } | head -n 1)"
if [ "$RUNNING" != "true" ]; then
    print_container_not_running
    exit 1
fi

echo "Executing setup script inside the '$SERVICE_NAME' container..."

# Use Docker Compose to execute the PHP script in the correct container.
"${COMPOSE[@]}" exec -T "$SERVICE_NAME" php "$SCRIPT_PATH" "$@"

echo "Setup script finished."
