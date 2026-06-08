#!/usr/bin/env php
<?php

require_once __DIR__ . '/../includes/Database.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$args = array_slice($argv, 1);
$mode = 'apply';
foreach ($args as $arg) {
    if (str_starts_with($arg, '--mode=')) {
        $mode = substr($arg, strlen('--mode='));
    } elseif ($arg === '--dry-run') {
        $mode = 'dry-run';
    }
}

if (!in_array($mode, ['apply', 'dry-run'], true)) {
    fwrite(STDERR, "Usage: php app/scripts/migrate.php [--mode=apply|dry-run]\n");
    exit(1);
}

try {
    $db = Database::getInstance();
    $result = [
        'success' => true,
        'mode' => $mode,
        'databasePath' => Database::getDatabasePath(),
        'declaredSchemaVersion' => Database::getDeclaredSchemaVersion(),
        'appliedSchemaVersion' => Database::getAppliedSchemaVersion($db),
    ];
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    echo json_encode([
        'success' => false,
        'mode' => $mode,
        'error' => $e->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
}
