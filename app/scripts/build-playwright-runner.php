#!/usr/bin/env php
<?php
require_once __DIR__ . '/../includes/AppContext.php';

$action = $argv[1] ?? 'build';

$app = new AppContext('playwright');

$statusDir = __DIR__ . '/../data/playwright-runtime';
$statusFile = $statusDir . '/build.json';
$logFile = $statusDir . '/build.log';
$imageName = 'doki/playwright-runner:v4';

$dockerfileDir = realpath(__DIR__ . '/../../docker/playwright');
if ($dockerfileDir === false) {
    $dockerfileDir = realpath(__DIR__ . '/../docker/playwright');
}

if (!is_dir($statusDir)) {
    @mkdir($statusDir, 0755, true);
}

$logLine = function(string $line) use ($logFile): void {
    file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND);
};

$writeStatus = function(string $status, string $message) use ($statusFile, $imageName): void {
    $timestamp = gmdate('Y-m-d\TH:i:s\Z');
    $payload = [
        'status' => $status,
        'message' => $message,
        'timestamp' => $timestamp,
        'imageName' => $imageName,
    ];
    file_put_contents($statusFile, json_encode($payload, JSON_PRETTY_PRINT));
};

file_put_contents($logFile, "=== Playwright Runner Build ===\n");
$logLine("Action: " . ($action ?: 'build'));
$logLine("Image: {$imageName}");
$logLine("Dockerfile dir: " . ($dockerfileDir ?: 'missing'));
$logLine("Started at: " . date('c'));
$logLine("");

$writeStatus('building', 'Building Playwright runner image');

if ($action === 'rebuild') {
    $logLine("Removing existing image...");
    $remove = $app->runDockerCommandOnTarget(['rmi', $imageName], 300);
    if (!empty($remove['output'])) {
        $logLine($remove['output']);
    }
}

if (!$dockerfileDir || !file_exists($dockerfileDir . '/Dockerfile')) {
    $logLine("ERROR: Dockerfile not found at " . ($dockerfileDir ?: '(unknown)'));
    $writeStatus('failed', 'Dockerfile not found');
    exit(1);
}

$logLine("Building image...");
$build = $app->runDockerCommandOnTarget(['build', '-t', $imageName, $dockerfileDir], 1800);
if (!empty($build['output'])) {
    $logLine($build['output']);
}

if (!empty($build['success'])) {
    $logLine("Build successful");
    $writeStatus('ready', 'Image ready');
    exit(0);
}

$logLine("Build failed with exit code " . ($build['exitCode'] ?? 'unknown'));
$writeStatus('failed', 'Build failed');
exit(1);
