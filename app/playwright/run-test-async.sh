#!/bin/bash

# Playwright Test Runner - Container-based execution
# Uses doki/playwright-runner image with pre-installed @playwright/test

# Set Docker config directory to a writable location
export DOCKER_CONFIG="/tmp/.docker"
mkdir -p "$DOCKER_CONFIG" 2>/dev/null

# Get arguments
# Usage: run-test-async.sh <job_id> [project] [test_files] [test_name]
JOB_ID=$1
PROJECT=$2
TEST_FILES=$3
TEST_NAME=$4

if [ -z "$JOB_ID" ]; then
    echo "ERROR: No job ID provided"
    exit 1
fi

# Default test name if not provided
if [ -z "$TEST_NAME" ]; then
    TEST_NAME="Unnamed Test"
fi

# Base paths
APP_DIR="/var/www/html"
ARCHIVE_DIR="${APP_DIR}/playwright-reports-archive"

# Create archive entry IMMEDIATELY at launch (before anything else)
# This ensures the run is visible in reports page right away
TIMESTAMP=$(date -u +"%Y-%m-%dT%H-%M-%S-%3NZ")
ARCHIVE_PATH="${ARCHIVE_DIR}/report-${TIMESTAMP}"
mkdir -p "$ARCHIVE_PATH"

# All output goes directly to archive folder
OUTPUT_FILE="${ARCHIVE_PATH}/output.log"
STATUS_FILE="${ARCHIVE_PATH}/status.txt"

# Legacy job files for backwards compatibility
LEGACY_STATUS_FILE="${APP_DIR}/playwright-jobs/${JOB_ID}.status"
LEGACY_RESULT_FILE="${APP_DIR}/playwright-jobs/${JOB_ID}.result"

# Host path for Docker volume mounts (Docker sees host paths, not container paths)
# Auto-detect from Docker mount info if not set via environment
if [ -z "$HOST_APP_PATH" ]; then
    # Method 1: Use docker inspect with jq (if available)
    if command -v jq &> /dev/null; then
        DETECTED_PATH=$(docker inspect php-command-executor 2>/dev/null | jq -r '.[0].Mounts[] | select(.Destination == "/var/www/html") | .Source' 2>/dev/null)
    fi
    
    # Method 2: Parse JSON manually if jq not available
    if [ -z "$DETECTED_PATH" ] || [ "$DETECTED_PATH" = "null" ]; then
        DETECTED_PATH=$(docker inspect php-command-executor --format '{{range .Mounts}}{{if eq .Destination "/var/www/html"}}{{.Source}}{{end}}{{end}}' 2>/dev/null)
    fi
    
    if [ -n "$DETECTED_PATH" ] && [ "$DETECTED_PATH" != "/var/www/html" ] && [ "$DETECTED_PATH" != "null" ]; then
        HOST_APP_PATH="$DETECTED_PATH"
    else
        # Fallback: use container path (will only work if Docker runs on same host without nested containers)
        HOST_APP_PATH="$APP_DIR"
    fi
fi

# Create directories
mkdir -p "${APP_DIR}/playwright-jobs"
mkdir -p "${APP_DIR}/playwright-report"
mkdir -p "${APP_DIR}/playwright-results"
mkdir -p "${ARCHIVE_DIR}"

# Write metadata to archive IMMEDIATELY so run is visible in reports
echo "running" > "$STATUS_FILE"
echo "$TEST_NAME" > "${ARCHIVE_PATH}/testname.txt"
echo "${PROJECT:-default}" > "${ARCHIVE_PATH}/project.txt"
echo "${TEST_FILES:-all}" > "${ARCHIVE_PATH}/testfiles.txt"
echo "$JOB_ID" > "${ARCHIVE_PATH}/jobid.txt"
echo "$TIMESTAMP" > "${ARCHIVE_PATH}/starttime.txt"
echo "0" > "${ARCHIVE_PATH}/testcount.txt"
echo "" > "${ARCHIVE_PATH}/exitcode.txt"

# Legacy status file for run modal compatibility
mkdir -p "${APP_DIR}/playwright-jobs"
echo "running" > "$LEGACY_STATUS_FILE"

# Start logging - output goes directly to archive
echo "=== Starting Playwright Test (Container Mode) ===" > "$OUTPUT_FILE"
echo "Job ID: $JOB_ID" >> "$OUTPUT_FILE"
echo "Project: ${PROJECT:-default}" >> "$OUTPUT_FILE"
echo "Test Files: ${TEST_FILES:-all}" >> "$OUTPUT_FILE"
echo "Test Name: $TEST_NAME" >> "$OUTPUT_FILE"
echo "Archive: $ARCHIVE_PATH" >> "$OUTPUT_FILE"
echo "Started at: $(date)" >> "$OUTPUT_FILE"
echo "" >> "$OUTPUT_FILE"

# Doki Playwright Runner image
# Version tag ensures rebuild when Dockerfile changes
RUNNER_IMAGE="doki/playwright-runner:v4"
BASE_IMAGE="mcr.microsoft.com/playwright:v1.49.1-noble"
DOCKERFILE_DIR="/var/www/docker/playwright"

# Host path for Docker build context (derive from HOST_APP_PATH)
# HOST_APP_PATH points to 'app/', docker/ is a sibling directory
if [ -n "$HOST_APP_PATH" ] && [ "$HOST_APP_PATH" != "$APP_DIR" ]; then
    # Replace /app with /docker in the path
    HOST_DOCKERFILE_DIR=$(echo "$HOST_APP_PATH" | sed 's|/app$|/docker/playwright|')
else
    HOST_DOCKERFILE_DIR="$DOCKERFILE_DIR"
fi

# Check if doki runner image exists, build if not
echo "Checking Playwright runner image..." >> "$OUTPUT_FILE"
if ! docker image inspect "$RUNNER_IMAGE" > /dev/null 2>&1; then
    echo "Building Doki Playwright runner image..." >> "$OUTPUT_FILE"
    echo "Host Dockerfile path: $HOST_DOCKERFILE_DIR" >> "$OUTPUT_FILE"
    
    BUILD_EXIT=1
    
    # Try building from host path first
    if [ -f "${DOCKERFILE_DIR}/Dockerfile" ]; then
        echo "Attempting build from host path..." >> "$OUTPUT_FILE"
        docker build -t "$RUNNER_IMAGE" "$HOST_DOCKERFILE_DIR" >> "$OUTPUT_FILE" 2>&1
        BUILD_EXIT=$?
    fi
    
    # If that failed, use inline Dockerfile
    if [ $BUILD_EXIT -ne 0 ]; then
        echo "Building with inline Dockerfile..." >> "$OUTPUT_FILE"
        docker build -t "$RUNNER_IMAGE" - >> "$OUTPUT_FILE" 2>&1 << 'DOCKERFILE'
FROM mcr.microsoft.com/playwright:v1.49.1-noble
RUN npm install -g @playwright/test@1.49.1 && mkdir -p /output/report /output/results
ENV NODE_PATH=/usr/lib/node_modules
WORKDIR /tests
ENTRYPOINT ["playwright"]
CMD ["--help"]
DOCKERFILE
        BUILD_EXIT=$?
    fi
    
    if [ $BUILD_EXIT -ne 0 ]; then
        echo "ERROR: Failed to build Playwright runner image" >> "$OUTPUT_FILE"
        echo "failed" > "$STATUS_FILE"
        exit 1
    fi
    echo "Runner image built successfully" >> "$OUTPUT_FILE"
else
    echo "Runner image ready" >> "$OUTPUT_FILE"
fi
echo "" >> "$OUTPUT_FILE"

# Set up project paths (container paths for file access, host paths for Docker mounts)
if [ -n "$PROJECT" ]; then
    PROJECT_DIR="${APP_DIR}/playwright/${PROJECT}"
    HOST_PROJECT_DIR="${HOST_APP_PATH}/playwright/${PROJECT}"
else
    PROJECT_DIR="${APP_DIR}/playwright"
    HOST_PROJECT_DIR="${HOST_APP_PATH}/playwright"
fi

# Check if project directory exists
if [ ! -d "$PROJECT_DIR" ]; then
    echo "ERROR: Project directory not found: $PROJECT_DIR" >> "$OUTPUT_FILE"
    echo "failed" > "$STATUS_FILE"
    exit 1
fi

echo "Project directory: $PROJECT_DIR" >> "$OUTPUT_FILE"

# Check for config file (optional - we can generate one if missing)
CONFIG_FILE=""
HAS_CONFIG="false"
if [ -f "${PROJECT_DIR}/playwright.config.ts" ]; then
    CONFIG_FILE="${PROJECT_DIR}/playwright.config.ts"
    HAS_CONFIG="true"
    echo "Config file: $CONFIG_FILE" >> "$OUTPUT_FILE"
elif [ -f "${PROJECT_DIR}/playwright.config.js" ]; then
    CONFIG_FILE="${PROJECT_DIR}/playwright.config.js"
    HAS_CONFIG="true"
    echo "Config file: $CONFIG_FILE" >> "$OUTPUT_FILE"
else
    echo "No playwright.config found - will generate standalone config" >> "$OUTPUT_FILE"
fi

# Build test file list for logging
TEST_COUNT=0
CONTAINER_TEST_PATHS=""

if [ -n "$TEST_FILES" ]; then
    echo "Preparing specific test files: $TEST_FILES" >> "$OUTPUT_FILE"
    IFS=',' read -ra FILES <<< "$TEST_FILES"
    for test_file in "${FILES[@]}"; do
        test_file=$(echo "$test_file" | xargs)
        if [ -n "$test_file" ]; then
            # Handle paths that already include tests/ prefix
            if [[ "$test_file" == tests/* ]]; then
                host_path="${PROJECT_DIR}/${test_file}"
                container_path="${test_file}"
            else
                host_path="${PROJECT_DIR}/tests/${test_file}"
                container_path="tests/${test_file}"
            fi
            
            if [ -f "$host_path" ]; then
                echo "  - Found: $test_file" >> "$OUTPUT_FILE"
                CONTAINER_TEST_PATHS="$CONTAINER_TEST_PATHS $container_path"
                TEST_COUNT=$((TEST_COUNT + 1))
            else
                echo "  - WARNING: Not found: $host_path" >> "$OUTPUT_FILE"
            fi
        fi
    done
else
    echo "Preparing all tests..." >> "$OUTPUT_FILE"
    SEARCH_DIR="${PROJECT_DIR}/tests"
    if [ ! -d "$SEARCH_DIR" ]; then
        SEARCH_DIR="$PROJECT_DIR"
    fi
    
    for file in ${SEARCH_DIR}/*.test.ts; do
        if [ -f "$file" ]; then
            filename=$(basename "$file")
            echo "  - Found: $filename" >> "$OUTPUT_FILE"
            TEST_COUNT=$((TEST_COUNT + 1))
        fi
    done
    # When running all tests, let Playwright find them via config
    CONTAINER_TEST_PATHS=""
fi

echo "" >> "$OUTPUT_FILE"
echo "Total test files: $TEST_COUNT" >> "$OUTPUT_FILE"

if [ $TEST_COUNT -eq 0 ]; then
    echo "ERROR: No test files found!" >> "$OUTPUT_FILE"
    echo "failed" > "$STATUS_FILE"
    exit 1
fi

# Debug: Check paths
echo "Container project path: ${PROJECT_DIR}" >> "$OUTPUT_FILE"
echo "Host project path: ${HOST_PROJECT_DIR}" >> "$OUTPUT_FILE"
echo "Listing project directory contents:" >> "$OUTPUT_FILE"
ls -la "${PROJECT_DIR}/" >> "$OUTPUT_FILE" 2>&1
echo "" >> "$OUTPUT_FILE"

# Clear old reports and test results from the PROJECT directory before running
# This ensures fresh results for each run
echo "Clearing old report and test data from project..." >> "$OUTPUT_FILE"
rm -rf "${PROJECT_DIR}/playwright-report" 2>/dev/null || true
rm -rf "${PROJECT_DIR}/test-results" 2>/dev/null || true
mkdir -p "${PROJECT_DIR}/playwright-report" 2>/dev/null || true
mkdir -p "${PROJECT_DIR}/test-results" 2>/dev/null || true
echo "Cleaned: ${PROJECT_DIR}/playwright-report/" >> "$OUTPUT_FILE"
echo "Cleaned: ${PROJECT_DIR}/test-results/" >> "$OUTPUT_FILE"

# Generate Doki wrapper config that overrides output settings
# This ensures we control where reports go, regardless of project's config
WRAPPER_CONFIG="${PROJECT_DIR}/doki-wrapper.config.cjs"
echo "Generating Doki wrapper config..." >> "$OUTPUT_FILE"

# Detect if project uses .ts or .js config
ORIGINAL_CONFIG=""
if [ -f "${PROJECT_DIR}/playwright.config.ts" ]; then
    ORIGINAL_CONFIG="playwright.config.ts"
    echo "Original config: ${ORIGINAL_CONFIG}" >> "$OUTPUT_FILE"
elif [ -f "${PROJECT_DIR}/playwright.config.js" ]; then
    ORIGINAL_CONFIG="playwright.config.js"
    echo "Original config: ${ORIGINAL_CONFIG}" >> "$OUTPUT_FILE"
else
    echo "No original config - will generate standalone Doki config" >> "$OUTPUT_FILE"
fi

# Read wrapper config from project.yaml if it exists
# Defaults if not found
OVERRIDE_REPORTERS="true"
REPORTER_HTML="true"
REPORTER_LIST="true"
REPORTER_JSON="false"
REPORTER_JUNIT="false"
OVERRIDE_RECORDING="true"
RECORDING_VIDEO="on-first-retry"
RECORDING_SCREENSHOT="only-on-failure"
RECORDING_TRACE="on-first-retry"
OVERRIDE_EXECUTION="false"
EXECUTION_RETRIES="2"
EXECUTION_WORKERS="1"
EXECUTION_TIMEOUT="180000"

PROJECT_YAML="${PROJECT_DIR}/project.yaml"
if [ -f "$PROJECT_YAML" ]; then
    echo "Reading wrapper config from project.yaml..." >> "$OUTPUT_FILE"
    
    # Use grep/sed to extract values (works without yaml parser)
    extract_yaml_value() {
        local key="$1"
        local default="$2"
        local value=$(grep -E "^\s*${key}:" "$PROJECT_YAML" 2>/dev/null | sed 's/.*:\s*//' | tr -d "'" | tr -d '"' | head -1)
        echo "${value:-$default}"
    }
    
    # Extract nested wrapperConfig values
    OVERRIDE_REPORTERS=$(extract_yaml_value "overrideReporters" "true")
    REPORTER_HTML=$(extract_yaml_value "html" "true")
    REPORTER_LIST=$(extract_yaml_value "list" "true")
    REPORTER_JSON=$(extract_yaml_value "json" "false")
    REPORTER_JUNIT=$(extract_yaml_value "junit" "false")
    OVERRIDE_RECORDING=$(extract_yaml_value "overrideRecording" "true")
    RECORDING_VIDEO=$(extract_yaml_value "video" "on-first-retry")
    RECORDING_SCREENSHOT=$(extract_yaml_value "screenshot" "only-on-failure")
    RECORDING_TRACE=$(extract_yaml_value "trace" "on-first-retry")
    OVERRIDE_EXECUTION=$(extract_yaml_value "overrideExecution" "false")
    EXECUTION_RETRIES=$(extract_yaml_value "retries" "2")
    EXECUTION_WORKERS=$(extract_yaml_value "workers" "1")
    EXECUTION_TIMEOUT=$(extract_yaml_value "timeout" "180000")

    # Extract baseUrl if provided (used to set BASE_URL env var)
    PROJECT_BASE_URL=$(extract_yaml_value "baseUrl" "")
    if [ -z "$BASE_URL" ] && [ -n "$PROJECT_BASE_URL" ]; then
        BASE_URL="$PROJECT_BASE_URL"
    fi
fi

detect_base_url() {
    local candidates=(
        "http://host.docker.internal:8099"
        "http://host.docker.internal:3000"
        "http://localhost:8099"
        "http://localhost:3000"
    )

    if command -v curl >/dev/null 2>&1; then
        for url in "${candidates[@]}"; do
            local code
            code=$(curl -s -o /dev/null -w "%{http_code}" --connect-timeout 1 --max-time 2 "$url")
            if [ -n "$code" ] && [ "$code" != "000" ]; then
                echo "$url"
                return 0
            fi
        done
    elif command -v wget >/dev/null 2>&1; then
        for url in "${candidates[@]}"; do
            if wget -q --spider --timeout=2 "$url"; then
                echo "$url"
                return 0
            fi
        done
    fi

    return 1
}

if [ -z "$BASE_URL" ]; then
    DETECTED_URL=$(detect_base_url)
    if [ -n "$DETECTED_URL" ]; then
        BASE_URL="$DETECTED_URL"
        echo "BASE_URL not set. Auto-detected ${BASE_URL}" >> "$OUTPUT_FILE"
    else
        BASE_URL="http://host.docker.internal:8099"
        echo "BASE_URL not set. Defaulting to ${BASE_URL}" >> "$OUTPUT_FILE"
    fi
fi

# Prepare environment variables
ENV_VARS=""
ENV_VARS="$ENV_VARS -e PLAYWRIGHT_HTML_OPEN=never"
ENV_VARS="$ENV_VARS -e CI=true"
if [ -n "$BASE_URL" ]; then
    ENV_VARS="$ENV_VARS -e BASE_URL=$BASE_URL"
    echo "BASE_URL: $BASE_URL" >> "$OUTPUT_FILE"
fi

echo "" >> "$OUTPUT_FILE"
echo "========================================" >> "$OUTPUT_FILE"
echo "Running Playwright tests in container..." >> "$OUTPUT_FILE"
echo "Image: $RUNNER_IMAGE" >> "$OUTPUT_FILE"
echo "========================================" >> "$OUTPUT_FILE"
echo "" >> "$OUTPUT_FILE"

echo "Wrapper settings:" >> "$OUTPUT_FILE"
echo "  Override reporters: $OVERRIDE_REPORTERS (html:$REPORTER_HTML, list:$REPORTER_LIST, json:$REPORTER_JSON, junit:$REPORTER_JUNIT)" >> "$OUTPUT_FILE"
echo "  Override recording: $OVERRIDE_RECORDING (video:$RECORDING_VIDEO, screenshot:$RECORDING_SCREENSHOT, trace:$RECORDING_TRACE)" >> "$OUTPUT_FILE"
echo "  Override execution: $OVERRIDE_EXECUTION (retries:$EXECUTION_RETRIES, workers:$EXECUTION_WORKERS, timeout:$EXECUTION_TIMEOUT)" >> "$OUTPUT_FILE"

# Build reporters array for the wrapper
REPORTERS_JS="["
if [ "$REPORTER_HTML" = "true" ]; then
    REPORTERS_JS="${REPORTERS_JS}['html', { outputFolder: './playwright-report', open: 'never' }],"
fi
if [ "$REPORTER_LIST" = "true" ]; then
    REPORTERS_JS="${REPORTERS_JS}['list'],"
fi
if [ "$REPORTER_JSON" = "true" ]; then
    REPORTERS_JS="${REPORTERS_JS}['json', { outputFile: './test-results/results.json' }],"
fi
if [ "$REPORTER_JUNIT" = "true" ]; then
    REPORTERS_JS="${REPORTERS_JS}['junit', { outputFile: './test-results/results.xml' }],"
fi
# Remove trailing comma and close array
REPORTERS_JS="${REPORTERS_JS%,}]"

# Create the wrapper config - uses CommonJS for maximum compatibility
# If there's an original config, import it and override
# If not, generate a standalone config

if [ -n "$ORIGINAL_CONFIG" ]; then
    # === WRAPPER MODE: Import original config and override ===
    cat > "$WRAPPER_CONFIG" << WRAPPER_EOF
/**
 * Doki Playwright Wrapper Config
 * 
 * This file is auto-generated by Doki to ensure consistent report output.
 * Settings are loaded from the project's wrapperConfig in project.yaml.
 * 
 * All other settings (tests, browsers, baseURL, etc.) come from the original config.
 */

const path = require('path');

// Dynamic import for ES modules / TypeScript configs
async function loadConfig() {
  try {
    // Try to load the original config
    const originalModule = require('./${ORIGINAL_CONFIG}');
    const originalConfig = originalModule.default || originalModule;
    
    // Build merged config
    const mergedConfig = { ...originalConfig };
    
    // Override reporters if enabled
    if (${OVERRIDE_REPORTERS}) {
      mergedConfig.reporter = ${REPORTERS_JS};
    }
    
    // Always set outputDir for test results
    mergedConfig.outputDir = './test-results';
    
    // Override recording settings if enabled
    if (${OVERRIDE_RECORDING}) {
      mergedConfig.use = {
        ...(originalConfig.use || {}),
        video: '${RECORDING_VIDEO}',
        screenshot: '${RECORDING_SCREENSHOT}',
        trace: '${RECORDING_TRACE}'
      };
    }

    // Override baseURL when provided via environment
    if (process.env.BASE_URL) {
      mergedConfig.use = {
        ...(mergedConfig.use || originalConfig.use || {}),
        baseURL: process.env.BASE_URL
      };
    }
    
    // Override execution settings if enabled
    if (${OVERRIDE_EXECUTION}) {
      mergedConfig.retries = ${EXECUTION_RETRIES};
      mergedConfig.workers = ${EXECUTION_WORKERS};
      mergedConfig.timeout = ${EXECUTION_TIMEOUT};
    }
    
    return mergedConfig;
  } catch (error) {
    console.error('Failed to load original config:', error.message);
    // Fallback minimal config
    return {
      testDir: './tests',
      reporter: ${REPORTERS_JS},
      outputDir: './test-results',
      use: {
        trace: '${RECORDING_TRACE}',
        screenshot: '${RECORDING_SCREENSHOT}',
        video: '${RECORDING_VIDEO}'
      }
    };
  }
}

module.exports = loadConfig();
WRAPPER_EOF
else
    # === STANDALONE MODE: No original config, generate complete config ===
    echo "Generating standalone Doki config (no original playwright.config found)..." >> "$OUTPUT_FILE"
    
    # Try to detect testDir (default to ./tests if it exists, otherwise .)
    if [ -d "${PROJECT_DIR}/tests" ]; then
        TEST_DIR="./tests"
    else
        TEST_DIR="."
    fi
    
    # Read baseUrl from project.yaml if available
    BASE_URL_CONFIG=""
    if [ -f "$PROJECT_YAML" ]; then
        PROJECT_BASE_URL=$(grep -E "^\s*baseUrl:" "$PROJECT_YAML" 2>/dev/null | sed 's/.*:\s*//' | tr -d "'" | tr -d '"' | head -1)
        if [ -n "$PROJECT_BASE_URL" ]; then
            BASE_URL_CONFIG="baseURL: process.env.BASE_URL || '${PROJECT_BASE_URL}',"
        fi
    fi
    if [ -z "$BASE_URL_CONFIG" ]; then
        BASE_URL_CONFIG="baseURL: process.env.BASE_URL,"
    fi
    
    cat > "$WRAPPER_CONFIG" << WRAPPER_EOF
/**
 * Doki Playwright Standalone Config
 * 
 * This file is auto-generated by Doki because no playwright.config was found.
 * Settings are loaded from the project's wrapperConfig in project.yaml.
 */

module.exports = {
  testDir: '${TEST_DIR}',
  testMatch: '**/*.test.{ts,js}',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  
  // Execution settings
  retries: ${OVERRIDE_EXECUTION} ? ${EXECUTION_RETRIES} : 0,
  workers: ${OVERRIDE_EXECUTION} ? ${EXECUTION_WORKERS} : undefined,
  timeout: ${OVERRIDE_EXECUTION} ? ${EXECUTION_TIMEOUT} : 30000,
  
  // Reporter settings
  reporter: ${REPORTERS_JS},
  outputDir: './test-results',
  
  // Browser settings
  use: {
    ${BASE_URL_CONFIG}
    trace: '${RECORDING_TRACE}',
    screenshot: '${RECORDING_SCREENSHOT}',
    video: '${RECORDING_VIDEO}',
  },
  
  // Default to Chromium
  projects: [
    {
      name: 'chromium',
      use: {
        browserName: 'chromium',
      },
    },
  ],
};
WRAPPER_EOF
fi

echo "Wrapper config created: $WRAPPER_CONFIG" >> "$OUTPUT_FILE"

# Build Docker command
# Use HOST paths for Docker volume mounts (Docker sees host filesystem)
# Mount project read-write so Playwright can write test-results and playwright-report
# Use our wrapper config instead of the original
DOCKER_CMD="docker run --rm \
    --name playwright-${JOB_ID} \
    --network host \
    --add-host=host.docker.internal:host-gateway \
    -v ${HOST_PROJECT_DIR}:/tests \
    -e PLAYWRIGHT_HTML_OPEN=never \
    $ENV_VARS \
    $RUNNER_IMAGE \
    test $CONTAINER_TEST_PATHS --config=doki-wrapper.config.cjs"

# Verify the mount works
echo "Verifying mount (listing /tests in container):" >> "$OUTPUT_FILE"
docker run --rm -v ${HOST_PROJECT_DIR}:/tests $RUNNER_IMAGE --version >> "$OUTPUT_FILE" 2>&1
docker run --rm -v ${HOST_PROJECT_DIR}:/tests alpine ls -la /tests >> "$OUTPUT_FILE" 2>&1
echo "" >> "$OUTPUT_FILE"

echo "Docker command: $DOCKER_CMD" >> "$OUTPUT_FILE"
echo "" >> "$OUTPUT_FILE"

# Run the container
eval $DOCKER_CMD 2>&1 | tee -a "$OUTPUT_FILE"
TEST_EXIT_CODE=${PIPESTATUS[0]}

echo "" >> "$OUTPUT_FILE"
echo "Playwright execution finished at $(date)" >> "$OUTPUT_FILE"
echo "Exit code: $TEST_EXIT_CODE" >> "$OUTPUT_FILE"

# Find video files (check both possible locations)
echo "" >> "$OUTPUT_FILE"
echo "Looking for video files..." >> "$OUTPUT_FILE"
VIDEO_FILES=$(find ${PROJECT_DIR}/test-results ${APP_DIR}/playwright-results -name "*.webm" -type f -mmin -10 2>/dev/null | sort | head -20)
if [ -n "$VIDEO_FILES" ]; then
    VIDEO_COUNT=$(echo "$VIDEO_FILES" | wc -l | tr -d ' ')
    echo "Found $VIDEO_COUNT video file(s)" >> "$OUTPUT_FILE"
    FIRST_VIDEO=$(echo "$VIDEO_FILES" | head -1)
    echo "$FIRST_VIDEO" > "$LEGACY_RESULT_FILE"
else
    echo "No video files found" >> "$OUTPUT_FILE"
fi

# Check for report - look in the project directory first (where Playwright actually writes it)
PROJECT_REPORT="${PROJECT_DIR}/playwright-report/index.html"
GLOBAL_REPORT="${APP_DIR}/playwright-report/index.html"

echo "" >> "$OUTPUT_FILE"
echo "Looking for HTML report..." >> "$OUTPUT_FILE"

REPORT_SRC_DIR=""
if [ -f "$PROJECT_REPORT" ]; then
    echo "Report found in project: $PROJECT_REPORT" >> "$OUTPUT_FILE"
    REPORT_SRC_DIR="${PROJECT_DIR}/playwright-report"
elif [ -f "$GLOBAL_REPORT" ]; then
    echo "Report found in global: $GLOBAL_REPORT" >> "$OUTPUT_FILE"
    REPORT_SRC_DIR="${APP_DIR}/playwright-report"
fi

# Copy HTML report to the archive folder that was created at launch
if [ -n "$REPORT_SRC_DIR" ]; then
    echo "Copying report to archive: $ARCHIVE_PATH" >> "$OUTPUT_FILE"
    cp -r "$REPORT_SRC_DIR/"* "$ARCHIVE_PATH/" 2>&1 | tee -a "$OUTPUT_FILE"
    
    if [ -f "$ARCHIVE_PATH/index.html" ]; then
        echo "Report archived successfully" >> "$OUTPUT_FILE"
    else
        echo "WARNING: Failed to copy report" >> "$OUTPUT_FILE"
    fi
else
    echo "No HTML report generated" >> "$OUTPUT_FILE"
    echo "Checked: $PROJECT_REPORT" >> "$OUTPUT_FILE"
    echo "Checked: $GLOBAL_REPORT" >> "$OUTPUT_FILE"
fi

# Copy test results (videos, screenshots) if they exist
if [ -d "${PROJECT_DIR}/test-results" ]; then
    echo "Copying test results (videos, screenshots)..." >> "$OUTPUT_FILE"
    cp -r "${PROJECT_DIR}/test-results" "$ARCHIVE_PATH/test-results" 2>/dev/null || true
fi

# Update metadata in the archive
echo "$TEST_COUNT" > "${ARCHIVE_PATH}/testcount.txt"
echo "$TEST_EXIT_CODE" > "${ARCHIVE_PATH}/exitcode.txt"
echo "$(date -u +"%Y-%m-%dT%H-%M-%S-%3NZ")" > "${ARCHIVE_PATH}/endtime.txt"

echo "" >> "$OUTPUT_FILE"
echo "=== Test Complete ===" >> "$OUTPUT_FILE"
echo "Finished at: $(date)" >> "$OUTPUT_FILE"

# Determine final status
if [ $TEST_EXIT_CODE -eq 0 ]; then
    FINAL_STATUS="completed"
else
    FINAL_STATUS="failed"
fi

# Update status in archive
echo "$FINAL_STATUS" > "$STATUS_FILE"

# Update legacy status file for run modal
echo "$FINAL_STATUS" > "$LEGACY_STATUS_FILE"

# Cleanup: Remove the wrapper config from project directory
if [ -f "$WRAPPER_CONFIG" ]; then
    rm -f "$WRAPPER_CONFIG"
    echo "Cleaned up wrapper config" >> "$OUTPUT_FILE"
fi
