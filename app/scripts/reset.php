#!/usr/bin/env php
<?php
require_once __DIR__ . '/../includes/RuntimeEnvironment.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$args = array_slice($argv, 1);
$showHelp = in_array('--help', $args, true) || in_array('-h', $args, true);
$dryRun = in_array('--dry-run', $args, true);
$force = in_array('--force', $args, true);

if ($showHelp || (!$dryRun && !$force)) {
    fwrite(STDOUT, "Usage: ./reset.sh --dry-run | --force\n");
    fwrite(STDOUT, "  --dry-run  Show what would be removed for a fresh-install reset\n");
    fwrite(STDOUT, "  --force    Remove runtime state, secrets, users, installed apps, and generated keys\n");
    exit($showHelp ? 0 : 1);
}

$projectRoot = dirname(__DIR__, 2);
$appDataRoot = $projectRoot . '/app/data';
$keepPaths = array_fill_keys(array_map(
    static fn(string $path): string => $projectRoot . $path,
    [
        '/app/data/.gitignore',
        '/app/data/app-build-status/.gitkeep',
        '/app/data/apps/.gitignore',
        '/app/data/config/.gitignore',
        '/app/data/config/environments.example.yaml',
        '/app/data/config/secrets.example.yaml',
        '/app/data/config/targets.example.yaml',
        '/app/data/docker-config/.gitkeep',
        '/app/studio-previews/.gitignore',
    ]
), true);

$directoriesToSweep = array_map(
    static fn(string $path): string => $projectRoot . $path,
    [
        '/app/.cache',
        '/app/.npm-cache',
        '/app/.pki',
        '/app/.playwright-browsers',
        '/app/.ssh',
        '/app/data/app-build-status',
        '/app/data/apps',
        '/app/data/config/.history',
        '/app/data/docker-builds',
        '/app/data/docker-config',
        '/app/data/includes',
        '/app/data/installed-apps',
        '/app/data/keys',
        '/app/data/playwright',
        '/app/data/playwright-runtime',
        '/app/data/sources',
        '/app/data/users',
        '/app/data/workspaces',
        '/app/data/studio-marketplace',
        '/app/data/templates',
        '/app/playwright-jobs',
        '/app/playwright-report',
        '/app/playwright-reports-archive',
        '/app/playwright-results',
        '/app/studio-previews',
        '/app/test-results',
    ]
);

$filesToRemove = array_map(
    static fn(string $path): string => $projectRoot . $path,
    [
        '/app/.DS_Store',
        '/app/data/doki.db',
        '/app/data/doki.sqlite',
        '/app/data/config/commands.yaml',
        '/app/data/config/environments.yaml',
        '/app/data/config/secrets.yaml',
        '/app/data/config/targets.yaml',
    ]
);

$directoriesToEnsure = [
    $appDataRoot,
    $projectRoot . '/app/data/app-build-status',
    $projectRoot . '/app/data/apps',
    $projectRoot . '/app/data/config',
    $projectRoot . '/app/data/config/.history',
    $projectRoot . '/app/data/docker-config',
    $projectRoot . '/app/data/includes',
    $projectRoot . '/app/data/installed-apps',
    $projectRoot . '/app/data/keys',
    $projectRoot . '/app/data/playwright',
    $projectRoot . '/app/data/playwright-runtime',
    $projectRoot . '/app/data/sources',
    $projectRoot . '/app/data/templates',
    $projectRoot . '/app/data/users',
];

$actions = [];

foreach ($directoriesToSweep as $directory) {
    clearDirectory($directory, $keepPaths, $dryRun, $actions);
}

foreach ($filesToRemove as $file) {
    deletePath($file, $dryRun, $actions);
}

if ($dryRun) {
    $actions[] = 'Would remove DOKI_ORCH_HOST_PORT, DOKI_ORCH_URL, DOKI_ORCH_TOKEN, and DOKI_ORCH_TIMEOUT from .env';
} else {
    if (RuntimeEnvironment::removeProjectEnvKeys([
        'DOKI_ORCH_HOST_PORT',
        'DOKI_ORCH_URL',
        'DOKI_ORCH_TOKEN',
        'DOKI_ORCH_TIMEOUT',
    ])) {
        $actions[] = 'Removed orchestrator env overrides from .env';
    } else {
        $actions[] = 'Unable to update .env';
    }
}

foreach ($directoriesToEnsure as $directory) {
    ensureDirectory($directory, $dryRun, $actions);
}

if (!$dryRun) {
    @chmod($projectRoot . '/app/data/keys', 0700);
}

fwrite(STDOUT, $dryRun ? "Doki reset preview\n" : "Doki reset\n");
fwrite(STDOUT, "===================\n");
foreach ($actions as $action) {
    fwrite(STDOUT, '- ' . $action . PHP_EOL);
}

exit(0);

function clearDirectory(string $directory, array $keepPaths, bool $dryRun, array &$actions): void {
    if (!is_dir($directory)) {
        return;
    }

    $entries = scandir($directory);
    if (!is_array($entries)) {
        return;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $directory . '/' . $entry;
        if (isset($keepPaths[$path])) {
            continue;
        }

        deletePath($path, $dryRun, $actions);
    }
}

function deletePath(string $path, bool $dryRun, array &$actions): void {
    if (!file_exists($path) && !is_link($path)) {
        return;
    }

    if ($dryRun) {
        $actions[] = 'Would remove ' . relativePath($path);
        return;
    }

    if (is_dir($path) && !is_link($path)) {
        $entries = scandir($path);
        if (is_array($entries)) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                deletePath($path . '/' . $entry, $dryRun, $actions);
            }
        }
        if (!@rmdir($path) && is_dir($path)) {
            $actions[] = 'Unable to remove ' . relativePath($path);
            return;
        }
        $actions[] = 'Removed ' . relativePath($path);
        return;
    }

    if (!@unlink($path) && (file_exists($path) || is_link($path))) {
        $actions[] = 'Unable to remove ' . relativePath($path);
        return;
    }

    $actions[] = 'Removed ' . relativePath($path);
}

function ensureDirectory(string $directory, bool $dryRun, array &$actions): void {
    if (is_dir($directory)) {
        return;
    }

    if ($dryRun) {
        $actions[] = 'Would create ' . relativePath($directory);
        return;
    }

    if (@mkdir($directory, 0755, true) || is_dir($directory)) {
        $actions[] = 'Created ' . relativePath($directory);
        return;
    }

    $actions[] = 'Unable to create ' . relativePath($directory);
}

function relativePath(string $path): string {
    $projectRoot = dirname(__DIR__, 2);
    if (str_starts_with($path, $projectRoot . '/')) {
        return substr($path, strlen($projectRoot) + 1);
    }
    return $path;
}
