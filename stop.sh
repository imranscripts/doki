#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(CDPATH= cd -- "$(dirname "$0")" && pwd)"
SERVICE_NAME="php-app"
SCRIPT_PATH="/var/www/html/scripts/runtime-control.php"

cd "$ROOT_DIR"

stop_host_managed_app_containers() {
    local -a ids=()
    local id

    while IFS= read -r id; do
        [ -n "$id" ] && ids+=("$id")
    done < <(docker ps -q --filter label=doki.app)

    while IFS= read -r id; do
        [ -n "$id" ] && ids+=("$id")
    done < <(docker ps -q --filter label=doki.job.id)

    if [ ${#ids[@]} -eq 0 ]; then
        return 0
    fi

    mapfile -t ids < <(printf '%s\n' "${ids[@]}" | awk 'NF && !seen[$0]++')
    if [ ${#ids[@]} -eq 0 ]; then
        return 0
    fi

    echo "Stopping remaining Doki app containers from the host..."
    docker stop "${ids[@]}"
}

app_exit=0
if [ -n "$(docker compose ps -q "$SERVICE_NAME" 2>/dev/null)" ]; then
    echo "Stopping Doki app runtimes inside '$SERVICE_NAME'..."
    set +e
    docker compose exec -T "$SERVICE_NAME" php "$SCRIPT_PATH" stop --skip-core "$@"
    app_exit=$?
    set -e
else
    echo "Skipping in-container app shutdown because '$SERVICE_NAME' is not running."
fi

host_app_exit=0
set +e
stop_host_managed_app_containers
host_app_exit=$?
set -e
if [ "$app_exit" -eq 0 ] && [ "$host_app_exit" -ne 0 ]; then
    app_exit=$host_app_exit
fi

echo "Stopping Doki core containers..."
docker compose stop

exit "$app_exit"
