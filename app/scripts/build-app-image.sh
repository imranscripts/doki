#!/bin/bash
# Build App Image Script
# Runs in background to build custom Docker images for apps

APP_ID="$1"

if [ -z "$APP_ID" ]; then
    echo "Error: APP_ID required"
    exit 1
fi

# Set Docker config to writable location
export DOCKER_CONFIG=/var/www/html/data/docker-config
export HOME=/tmp

# Run PHP script to build image
php -r "
require_once '/var/www/html/includes/AppManager.php';
require_once '/var/www/html/includes/AppImageBuilder.php';

\$appManager = new AppManager();
\$app = \$appManager->getApp('$APP_ID');

if (!\$app) {
    echo 'Error: App not found\n';
    exit(1);
}

\$builder = new AppImageBuilder('$APP_ID', \$app);
\$result = \$builder->buildImage();

if (\$result['success']) {
    echo 'Build successful\n';
    exit(0);
} else {
    echo 'Build failed: ' . (\$result['error'] ?? 'Unknown error') . '\n';
    exit(1);
}
"
