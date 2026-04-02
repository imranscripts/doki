#!/bin/bash
# Don't exit on error - we want to see all output
set +e

# Set HOME directory to avoid Playwright errors
export HOME=/var/www/html
export NPM_CONFIG_CACHE=/var/www/html/.npm-cache
export PLAYWRIGHT_BROWSERS_PATH=/var/www/html/.playwright-browsers

echo "=== Starting Playwright Test ==="
echo "Current directory: $(pwd)"
echo "HOME: $HOME"
echo "PLAYWRIGHT_BROWSERS_PATH: $PLAYWRIGHT_BROWSERS_PATH"

# Navigate to app directory
cd /var/www/html
echo "Changed to: $(pwd)"

# Install dependencies if node_modules doesn't exist
if [ ! -d "node_modules" ]; then
    echo "Installing dependencies..."
    npm install 2>&1
fi

# Check if Playwright browsers are installed
BROWSER_CHECK=$(ls -d "$PLAYWRIGHT_BROWSERS_PATH"/chromium-* 2>/dev/null | head -n 1)
if [ -z "$BROWSER_CHECK" ]; then
    echo "Playwright browsers not found. Installing..."
    npx playwright install chromium 2>&1
    if [ $? -ne 0 ]; then
        echo "ERROR: Failed to install Playwright browsers"
        exit 1
    fi
else
    echo "Playwright browsers found at: $BROWSER_CHECK"
fi

# Verify browsers are accessible
echo "Verifying browser installation..."
npx playwright install --dry-run chromium 2>&1

# Create playwright-results directory if it doesn't exist
mkdir -p playwright-results
echo "Playwright results directory ready"

# Run the test
echo "Running Playwright test..."
npx playwright test 2>&1

# Wait a moment for file system to sync
sleep 2

# Find the latest video file
echo "Looking for video files in playwright-results..."
if [ -d "playwright-results" ]; then
    ls -la playwright-results/ 2>&1 || echo "Directory exists but is empty"
    
    LATEST_VIDEO=$(find playwright-results -name "test-video.webm" -type f 2>/dev/null | sort | tail -1)
    
    if [ -n "$LATEST_VIDEO" ]; then
        echo "Video available at: $LATEST_VIDEO"
    else
        echo "No video found. Checking for any .webm files..."
        find playwright-results -name "*.webm" -type f 2>/dev/null || echo "No .webm files found"
    fi
else
    echo "playwright-results directory not found!"
fi

echo "=== Test Complete ==="
