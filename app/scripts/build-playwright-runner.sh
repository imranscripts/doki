#!/bin/bash
# Build Playwright Runner Image Script
# Runs in background to build the Playwright Docker runner image

ACTION="$1"

STATUS_DIR="/var/www/html/data/playwright-runtime"
STATUS_FILE="${STATUS_DIR}/build.json"
LOG_FILE="${STATUS_DIR}/build.log"

IMAGE_NAME="doki/playwright-runner:v4"
CONTAINER_DOCKERFILE_DIR="/var/www/docker/playwright"

export DOCKER_CONFIG=/var/www/html/data/docker-config
export HOME=/tmp

mkdir -p "$STATUS_DIR"

write_status() {
    local status="$1"
    local message="$2"
    local timestamp
    timestamp=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
    printf '{\n  "status": "%s",\n  "message": "%s",\n  "timestamp": "%s",\n  "imageName": "%s"\n}\n' \
        "$status" "$message" "$timestamp" "$IMAGE_NAME" > "$STATUS_FILE"
}

echo "=== Playwright Runner Build ===" > "$LOG_FILE"
echo "Action: ${ACTION:-build}" >> "$LOG_FILE"
echo "Image: $IMAGE_NAME" >> "$LOG_FILE"
echo "Dockerfile dir: $CONTAINER_DOCKERFILE_DIR" >> "$LOG_FILE"
echo "Started at: $(date)" >> "$LOG_FILE"
echo "" >> "$LOG_FILE"

write_status "building" "Building Playwright runner image"

if [ "$ACTION" = "rebuild" ]; then
    echo "Removing existing image..." >> "$LOG_FILE"
    docker rmi "$IMAGE_NAME" >> "$LOG_FILE" 2>&1 || true
fi

# Try to resolve host path for Docker build context
HOST_APP_PATH=""
if command -v jq &> /dev/null; then
    DETECTED_PATH=$(docker inspect php-command-executor 2>/dev/null | jq -r '.[0].Mounts[] | select(.Destination == "/var/www/html") | .Source' 2>/dev/null)
fi

if [ -z "$DETECTED_PATH" ] || [ "$DETECTED_PATH" = "null" ]; then
    DETECTED_PATH=$(docker inspect php-command-executor --format '{{range .Mounts}}{{if eq .Destination "/var/www/html"}}{{.Source}}{{end}}{{end}}' 2>/dev/null)
fi

if [ -n "$DETECTED_PATH" ] && [ "$DETECTED_PATH" != "/var/www/html" ] && [ "$DETECTED_PATH" != "null" ]; then
    HOST_APP_PATH="$DETECTED_PATH"
fi

if [ -n "$HOST_APP_PATH" ]; then
    HOST_DOCKERFILE_DIR=$(echo "$HOST_APP_PATH" | sed 's|/app$|/docker/playwright|')
else
    HOST_DOCKERFILE_DIR="$CONTAINER_DOCKERFILE_DIR"
fi

DOCKERFILE_DIR="$HOST_DOCKERFILE_DIR"
echo "Resolved build context: $DOCKERFILE_DIR" >> "$LOG_FILE"

if [ ! -f "${DOCKERFILE_DIR}/Dockerfile" ]; then
    echo "ERROR: Dockerfile not found at ${DOCKERFILE_DIR}/Dockerfile" >> "$LOG_FILE"
    write_status "failed" "Dockerfile not found"
    exit 1
fi

echo "Building image..." >> "$LOG_FILE"
docker build -t "$IMAGE_NAME" "$DOCKERFILE_DIR" >> "$LOG_FILE" 2>&1
BUILD_EXIT=$?

if [ $BUILD_EXIT -eq 0 ]; then
    echo "Build successful" >> "$LOG_FILE"
    write_status "ready" "Image ready"
    exit 0
else
    echo "Build failed with exit code $BUILD_EXIT" >> "$LOG_FILE"
    write_status "failed" "Build failed"
    exit 1
fi
