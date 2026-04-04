#!/usr/bin/env bash
set -euo pipefail

# This script now executes the setup process inside the running Docker container
# to ensure the correct PHP environment is used.

# The service name of the PHP container in docker-compose.yml
SERVICE_NAME="php-app"

# The path to the PHP setup script inside the container
# The 'app' directory is mounted at /var/www/html
SCRIPT_PATH="/var/www/html/scripts/setup.php"

echo "Executing setup script inside the '$SERVICE_NAME' container..."

# Use docker-compose to execute the PHP script in the correct container
docker-compose exec "$SERVICE_NAME" php "$SCRIPT_PATH" "$@"

echo "Setup script finished."
