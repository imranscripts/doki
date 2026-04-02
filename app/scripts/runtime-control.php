#!/usr/bin/env php
<?php

require_once __DIR__ . '/../includes/RuntimeEnvironment.php';
require_once __DIR__ . '/../includes/AppManager.php';
require_once __DIR__ . '/../includes/AppImageBuilder.php';
require_once __DIR__ . '/../includes/AppContext.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$args = array_slice($argv, 1);
$mode = null;
$filteredArgs = [];
foreach ($args as $arg) {
    if ($mode === null && in_array($arg, ['start', 'stop'], true)) {
        $mode = $arg;
        continue;
    }
    $filteredArgs[] = $arg;
}

$showHelp = in_array('--help', $filteredArgs, true) || in_array('-h', $filteredArgs, true);
$verbose = in_array('--verbose', $filteredArgs, true) || in_array('-v', $filteredArgs, true);

if ($showHelp || $mode === null) {
    fwrite(STDOUT, "Usage: php app/scripts/runtime-control.php <start|stop> [--verbose]\n");
    fwrite(STDOUT, "  start        Start the Doki stack and known app containers\n");
    fwrite(STDOUT, "  stop         Stop the Doki stack and related app/job containers\n");
    fwrite(STDOUT, "  --verbose    Show per-action details\n");
    exit($showHelp ? 0 : 1);
}

$projectRoot = dirname(__DIR__, 2);
if (!@chdir($projectRoot)) {
    fwrite(STDERR, "Unable to switch to project root: {$projectRoot}\n");
    exit(1);
}

$runtimeLogDir = $projectRoot . '/app/data/app-build-status';
if (!is_dir($runtimeLogDir)) {
    @mkdir($runtimeLogDir, 0755, true);
}
$runtimeLogPath = $runtimeLogDir . '/runtime-control.log';
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
@ini_set('error_log', $runtimeLogPath);

$printLine = static function (string $line = ''): void {
    fwrite(STDOUT, $line . PHP_EOL);
};

$runCommand = static function (array $command, int $timeoutSeconds = 60, ?string $cwd = null): array {
    $cwd = $cwd ?: getcwd() ?: null;
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $commandString = implode(' ', array_map(static function ($part): string {
        return escapeshellarg((string)$part);
    }, $command));

    $process = @proc_open(['/bin/sh', '-lc', $commandString], $descriptorSpec, $pipes, $cwd ?: null);
    if (!is_resource($process)) {
        return [
            'success' => false,
            'output' => 'Unable to start command',
            'exitCode' => 1,
            'timedOut' => false,
        ];
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $deadline = microtime(true) + max(1, $timeoutSeconds);
    $output = '';
    $timedOut = false;

    while (true) {
        $status = proc_get_status($process);
        $running = !empty($status['running']);
        $remaining = $deadline - microtime(true);

        $read = [];
        if (!feof($pipes[1])) {
            $read[] = $pipes[1];
        }
        if (!feof($pipes[2])) {
            $read[] = $pipes[2];
        }

        if ($read !== []) {
            if ($running && $remaining > 0) {
                $seconds = (int)$remaining;
                $microseconds = (int)(($remaining - $seconds) * 1000000);
                $write = null;
                $except = null;
                @stream_select($read, $write, $except, $seconds, $microseconds);
            }

            foreach ($read as $stream) {
                $chunk = stream_get_contents($stream);
                if ($chunk !== false && $chunk !== '') {
                    $output .= $chunk;
                }
            }
        }

        if (!$running) {
            break;
        }

        if ($remaining <= 0) {
            $timedOut = true;
            @proc_terminate($process);
            usleep(100000);
            $status = proc_get_status($process);
            if (!empty($status['running'])) {
                @proc_terminate($process, 9);
            }
            break;
        }
    }

    foreach ([1, 2] as $index) {
        $chunk = stream_get_contents($pipes[$index]);
        if ($chunk !== false && $chunk !== '') {
            $output .= $chunk;
        }
        fclose($pipes[$index]);
    }

    $exitCode = proc_close($process);
    $output = trim($output);

    if ($timedOut) {
        $output = $output === ''
            ? 'Command timed out after ' . $timeoutSeconds . 's'
            : $output . "\n" . 'Command timed out after ' . $timeoutSeconds . 's';
        $exitCode = 124;
    }

    return [
        'success' => $exitCode === 0,
        'output' => $output,
        'exitCode' => $exitCode,
        'timedOut' => $timedOut,
    ];
};

$commandExists = static function (string $command) use ($runCommand): bool {
    $result = $runCommand(['sh', '-lc', 'command -v ' . escapeshellarg($command)], 5);
    return $result['success'];
};

$probeHttp = static function (string $url): bool {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 3,
        ]);
        curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return $status >= 200 && $status < 300;
    }

    if (ini_get('allow_url_fopen')) {
        $context = stream_context_create([
            'http' => ['timeout' => 3],
        ]);
        return @file_get_contents($url, false, $context) !== false;
    }

    return false;
};

$waitForOrchestrator = static function (int $timeoutSeconds) use ($probeHttp): ?string {
    $deadline = microtime(true) + max(1, $timeoutSeconds);
    $baseUrls = RuntimeEnvironment::getOrchestratorHealthProbeBaseUrls();

    do {
        foreach ($baseUrls as $baseUrl) {
            $baseUrl = rtrim((string)$baseUrl, '/');
            if ($baseUrl === '') {
                continue;
            }

            if ($probeHttp($baseUrl . '/health')) {
                return $baseUrl;
            }
        }
        usleep(500000);
    } while (microtime(true) < $deadline);

    return null;
};

$formatContainerSummary = static function (array $containers): string {
    if ($containers === []) {
        return 'none';
    }
    return implode(', ', array_values(array_unique($containers)));
};

$listRunningContainers = static function (array $filters) use ($runCommand): array {
    $command = ['docker', 'ps', '--format', '{{.ID}}|{{.Names}}'];
    foreach ($filters as $filter) {
        $command[] = '--filter';
        $command[] = $filter;
    }

    $result = $runCommand($command, 20);
    if (!$result['success'] && trim((string)$result['output']) === '') {
        return [];
    }

    $containers = [];
    foreach (preg_split('/\R/', (string)$result['output']) ?: [] as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        [$id, $name] = array_pad(explode('|', $line, 2), 2, '');
        if ($id !== '') {
            $containers[$id] = $name !== '' ? $name : $id;
        }
    }

    return $containers;
};

$startOutcome = [
    'core' => [],
    'appRuntimes' => ['started' => [], 'already' => [], 'failed' => []],
    'appServices' => ['started' => [], 'already' => [], 'failed' => []],
];
$stopOutcome = [
    'core' => [],
    'containersStopped' => [],
    'containersAlreadyStopped' => [],
    'failed' => [],
];
$issues = [];

if (!$commandExists('docker')) {
    $printLine('Doki runtime');
    $printLine('============');
    $printLine('Docker CLI is not available.');
    exit(1);
}

$composeVersion = $runCommand(['docker', 'compose', 'version'], 20);
if (!$composeVersion['success']) {
    $printLine('Doki runtime');
    $printLine('============');
    $printLine('Docker Compose is not available.');
    $output = trim((string)$composeVersion['output']);
    if ($output !== '') {
        $printLine($output);
    }
    exit(1);
}

if ($mode === 'start') {
    $printLine('Doki start');
    $printLine('==========');

    $composeUp = $runCommand(['docker', 'compose', 'up', '-d'], 240, $projectRoot);
    if (!$composeUp['success']) {
        $issues[] = 'Core stack failed to start: ' . trim((string)$composeUp['output']);
    } else {
        $startOutcome['core'][] = 'Docker Compose stack is running';
    }

    if ($issues === []) {
        $orchestratorBaseUrl = $waitForOrchestrator(60);
        if ($orchestratorBaseUrl === null) {
            $issues[] = 'Orchestrator did not become healthy in time';
        } else {
            $startOutcome['core'][] = 'Orchestrator healthy at ' . $orchestratorBaseUrl;
        }
    }

    try {
        $appManager = new AppManager();
        foreach ($appManager->getApps() as $app) {
            if (empty($app['enabled']) && array_key_exists('enabled', $app)) {
                continue;
            }

            $appId = (string)($app['id'] ?? '');
            if ($appId === '') {
                continue;
            }

            $builder = new AppImageBuilder($appId, $app);
            if ($builder->needsCustomImage()) {
                $result = $builder->startContainer();
                if (!empty($result['success'])) {
                    $message = strtolower((string)($result['message'] ?? ''));
                    if (str_contains($message, 'already running')) {
                        $startOutcome['appRuntimes']['already'][] = $appId;
                    } else {
                        $startOutcome['appRuntimes']['started'][] = $appId;
                    }
                } else {
                    $startOutcome['appRuntimes']['failed'][] = $appId . ': ' . trim((string)($result['error'] ?? 'unknown error'));
                }
            }

            $context = new AppContext($appId, null, false);
            foreach ($context->getRequiredServices() as $service) {
                $serviceName = (string)($service['name'] ?? '');
                if ($serviceName === '') {
                    continue;
                }

                try {
                    $result = $context->ensureServiceRunning($serviceName);
                    if (!empty($result['success'])) {
                        $message = strtolower((string)($result['message'] ?? ''));
                        $entry = $appId . '/' . $serviceName;
                        if (str_contains($message, 'already running')) {
                            $startOutcome['appServices']['already'][] = $entry;
                        } else {
                            $startOutcome['appServices']['started'][] = $entry;
                        }
                    } else {
                        $startOutcome['appServices']['failed'][] = $appId . '/' . $serviceName . ': ' . trim((string)($result['error'] ?? 'unknown error'));
                    }
                } catch (Throwable $e) {
                    $startOutcome['appServices']['failed'][] = $appId . '/' . $serviceName . ': ' . $e->getMessage();
                }
            }
        }
    } catch (Throwable $e) {
        $issues[] = 'App container startup failed: ' . $e->getMessage();
    }

    if ($verbose && $composeUp['output'] !== '') {
        $printLine('Compose output');
        $printLine('--------------');
        $printLine(trim((string)$composeUp['output']));
        $printLine('');
    }

    $printLine('Status');
    $printLine('------');
    foreach ($startOutcome['core'] as $line) {
        $printLine('- ' . $line);
    }

    $runtimeStarted = count($startOutcome['appRuntimes']['started']);
    $runtimeAlready = count($startOutcome['appRuntimes']['already']);
    if ($runtimeStarted > 0 || $runtimeAlready > 0 || $verbose) {
        $printLine('- App runtimes: ' . $runtimeStarted . ' started, ' . $runtimeAlready . ' already running');
        if ($verbose) {
            foreach ($startOutcome['appRuntimes']['started'] as $entry) {
                $printLine('  started: ' . $entry);
            }
            foreach ($startOutcome['appRuntimes']['already'] as $entry) {
                $printLine('  already: ' . $entry);
            }
        }
    }

    $serviceStarted = count($startOutcome['appServices']['started']);
    $serviceAlready = count($startOutcome['appServices']['already']);
    if ($serviceStarted > 0 || $serviceAlready > 0 || $verbose) {
        $printLine('- App services: ' . $serviceStarted . ' started, ' . $serviceAlready . ' already running');
        if ($verbose) {
            foreach ($startOutcome['appServices']['started'] as $entry) {
                $printLine('  started: ' . $entry);
            }
            foreach ($startOutcome['appServices']['already'] as $entry) {
                $printLine('  already: ' . $entry);
            }
        }
    }

    $failures = array_merge($issues, $startOutcome['appRuntimes']['failed'], $startOutcome['appServices']['failed']);
    if ($failures !== []) {
        $printLine('');
        $printLine('Issues');
        $printLine('------');
        foreach ($failures as $failure) {
            $printLine('- ' . $failure);
        }
        exit(1);
    }

    $printLine('');
    $printLine('Doki is running.');
    exit(0);
}

$printLine('Doki stop');
$printLine('=========');

$runningRelatedContainers = [];
foreach (
    [
        ['label=doki.job.id'],
        ['label=doki.app'],
        ['name=^php-command-executor$'],
        ['name=^doki-main-app$'],
        ['name=^doki-go-orchestrator$'],
    ] as $filters
) {
    foreach ($listRunningContainers($filters) as $id => $name) {
        $runningRelatedContainers[$id] = $name;
    }
}

foreach ($runningRelatedContainers as $id => $name) {
    $result = $runCommand(['docker', 'stop', $id], 45);
    if ($result['success']) {
        $stopOutcome['containersStopped'][] = $name;
    } else {
        $stopOutcome['failed'][] = $name . ': ' . trim((string)($result['output'] ?? 'unable to stop'));
    }
}

$composeStop = $runCommand(['docker', 'compose', 'stop'], 120, $projectRoot);
if ($composeStop['success']) {
    $stopOutcome['core'][] = 'Docker Compose stack stopped';
} else {
    $message = trim((string)$composeStop['output']);
    if ($message !== '') {
        $stopOutcome['failed'][] = 'Core stack: ' . $message;
    } else {
        $stopOutcome['failed'][] = 'Core stack: docker compose stop failed';
    }
}

if ($verbose && $composeStop['output'] !== '') {
    $printLine('Compose output');
    $printLine('--------------');
    $printLine(trim((string)$composeStop['output']));
    $printLine('');
}

$printLine('Status');
$printLine('------');
foreach ($stopOutcome['core'] as $line) {
    $printLine('- ' . $line);
}
$printLine('- Related containers stopped: ' . count($stopOutcome['containersStopped']));
if ($verbose && $stopOutcome['containersStopped'] !== []) {
    foreach (array_values(array_unique($stopOutcome['containersStopped'])) as $name) {
        $printLine('  stopped: ' . $name);
    }
}

if ($stopOutcome['failed'] !== []) {
    $printLine('');
    $printLine('Issues');
    $printLine('------');
    foreach ($stopOutcome['failed'] as $failure) {
        $printLine('- ' . $failure);
    }
    exit(1);
}

$printLine('');
$printLine('Doki is stopped.');
exit(0);
