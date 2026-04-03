#!/usr/bin/env bash
set -euo pipefail

# This script now properly stops containers and executes the reset process
# inside the running Docker container.

# The service name of the PHP container in docker-compose.yml
SERVICE_NAME="php-app"

# The path to the PHP reset script inside the container
SCRIPT_PATH="/var/www/html/scripts/reset.php"

echo "Stopping and removing containers..."
docker-compose down -v

echo "Starting services..."
docker-compose up -d

echo "Executing reset script inside the '$SERVICE_NAME' container..."
docker-compose exec "$SERVICE_NAME" php "$SCRIPT_PATH" "$@"

echo "Reset script finished."
