#!/usr/bin/env php
<?php
require_once __DIR__ . '/../includes/OnboardingManager.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$args = array_slice($argv, 1);
$showHelp = in_array('--help', $args, true) || in_array('-h', $args, true);
if ($showHelp) {
    fwrite(STDOUT, "Usage: ./setup.sh [--init] [--check] [--rotate-keys] [--verbose]\n");
    fwrite(STDOUT, "  --init         Create missing runtime state (default)\n");
    fwrite(STDOUT, "  --check        Report health without writing files\n");
    fwrite(STDOUT, "  --rotate-keys  Recreate stealth and secrets keys before reporting\n");
    fwrite(STDOUT, "  --verbose      Show full actions, checks, and paths\n");
    exit(0);
}

$checkOnly = in_array('--check', $args, true);
$rotateKeys = in_array('--rotate-keys', $args, true);
$verbose = in_array('--verbose', $args, true) || in_array('-v', $args, true);

$manager = new OnboardingManager();
$status = $manager->runSetup($checkOnly, $rotateKeys);

$printLine = static function(string $line = ''): void {
    fwrite(STDOUT, $line . PHP_EOL);
};

$printLine('Doki setup');
$printLine('==========');
$printLine('Mode: ' . ($checkOnly ? 'check' : ($rotateKeys ? 'init + rotate keys' : 'init')));
$printLine('Install state: ' . ($status['install']['state'] ?? 'unknown'));
$printLine('');

if (!$checkOnly && !empty($status['stealth']['secret'])) {
    $printLine('Stealth key');
    $printLine('-----------');
    $printLine('Here is your stealth key. Keep it somewhere safe. You will need it after onboarding if you keep the stealth feature enabled.');
    $printLine((string)$status['stealth']['secret']);
    $printLine('');
}

if ($verbose && !$checkOnly && !empty($status['actions'])) {
    $printLine('Actions');
    $printLine('-------');
    foreach ($status['actions'] as $action) {
        $printLine('- ' . $action);
    }
    $printLine('');
}

$checks = $status['bootstrap']['checks'] ?? [];
$blockingFailures = 0;
$issues = [];

foreach ($checks as $name => $check) {
    $ok = !empty($check['ok']);
    $blocking = !empty($check['blocking']);
    if (!$ok && $blocking) {
        $blockingFailures++;
    }
    if (!$ok) {
        $issues[$name] = $check;
    }
}

if ($verbose) {
    $printLine('Health checks');
    $printLine('-------------');
    foreach ($checks as $name => $check) {
        $ok = !empty($check['ok']);
        $blocking = !empty($check['blocking']);
        $state = $ok ? 'OK' : ($blocking ? 'BLOCKING' : 'WARN');
        $printLine(sprintf('[%s] %s: %s', $state, $name, (string)($check['message'] ?? '')));
    }
    $printLine('');
} elseif (!empty($issues)) {
    $printLine('Issues');
    $printLine('------');
    foreach ($issues as $name => $check) {
        $blocking = !empty($check['blocking']);
        $state = $blocking ? 'BLOCKING' : 'WARN';
        $printLine(sprintf('[%s] %s: %s', $state, $name, (string)($check['message'] ?? '')));
    }
    $printLine('');
} else {
    $printLine('All health checks passed.');
    $printLine('');
}

$printLine('Status');
$printLine('------');
$printLine('Bootstrap ready: ' . (!empty($status['bootstrap']['ready']) ? 'yes' : 'no'));
$printLine('Bootstrap healthy: ' . (!empty($status['bootstrap']['healthy']) ? 'yes' : 'no'));
$printLine('Local target configured: ' . (!empty($status['localTarget']['exists']) ? 'yes' : 'no'));

if ($verbose) {
    $printLine('Stealth secret path: ' . (string)($status['stealth']['secretPath'] ?? 'unavailable'));
    $printLine('Secrets key path: ' . (string)($status['secretsKey']['path'] ?? 'unavailable'));
} elseif (!empty($issues)) {
    $printLine('Use ./setup.sh --verbose for full diagnostics.');
}

$printLine('');

exit($blockingFailures === 0 ? 0 : 1);
