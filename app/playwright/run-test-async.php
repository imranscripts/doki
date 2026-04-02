#!/usr/bin/env php
<?php
require_once __DIR__ . '/../includes/AppContext.php';
require_once __DIR__ . '/../includes/PlaywrightProjectManager.php';

$jobId = $argv[1] ?? '';
$projectId = $argv[2] ?? '';
$testFilesArg = $argv[3] ?? '';
$testName = $argv[4] ?? '';

if ($jobId === '') {
    echo "ERROR: No job ID provided\n";
    exit(1);
}

if ($testName === '') {
    $testName = 'Unnamed Test';
}

$appDir = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
$archiveDir = $appDir . '/playwright-reports-archive';

$timestamp = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
    ->format('Y-m-d\TH-i-s-') . str_pad((new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('v'), 3, '0', STR_PAD_LEFT) . 'Z';
$archivePath = $archiveDir . '/report-' . $timestamp;
@mkdir($archivePath, 0755, true);

$outputFile = $archivePath . '/output.log';
$statusFile = $archivePath . '/status.txt';
$legacyStatusFile = $appDir . "/playwright-jobs/{$jobId}.status";
$legacyResultFile = $appDir . "/playwright-jobs/{$jobId}.result";

$logLine = function(string $line) use ($outputFile): void {
    file_put_contents($outputFile, $line . PHP_EOL, FILE_APPEND);
};

$writeStatus = function(string $status) use ($statusFile): void {
    file_put_contents($statusFile, $status);
};

$writeFile = function(string $path, string $content): void {
    file_put_contents($path, $content);
};

$removeDir = function(string $dir) use (&$removeDir): void {
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            $removeDir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
};

$copyDir = function(string $src, string $dst) use (&$copyDir): void {
    if (!is_dir($src)) {
        return;
    }
    @mkdir($dst, 0755, true);
    $items = scandir($src);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $srcPath = $src . DIRECTORY_SEPARATOR . $item;
        $dstPath = $dst . DIRECTORY_SEPARATOR . $item;
        if (is_dir($srcPath)) {
            $copyDir($srcPath, $dstPath);
        } else {
            @copy($srcPath, $dstPath);
        }
    }
};

// Prepare directories and metadata
@mkdir($appDir . '/playwright-jobs', 0777, true);
@mkdir($appDir . '/playwright-report', 0777, true);
@mkdir($appDir . '/playwright-results', 0777, true);
@mkdir($archiveDir, 0777, true);

$writeStatus('running');
$writeFile($archivePath . '/testname.txt', $testName);
$writeFile($archivePath . '/project.txt', $projectId ?: 'default');
$writeFile($archivePath . '/testfiles.txt', $testFilesArg ?: 'all');
$writeFile($archivePath . '/jobid.txt', $jobId);
$writeFile($archivePath . '/starttime.txt', $timestamp);
$writeFile($archivePath . '/testcount.txt', '0');
$writeFile($archivePath . '/exitcode.txt', '');
file_put_contents($legacyStatusFile, 'running');

// Start log
file_put_contents($outputFile, "=== Starting Playwright Test (Container Mode) ===\n");
$logLine("Job ID: {$jobId}");
$logLine("Project: " . ($projectId ?: 'default'));
$logLine("Test Files: " . ($testFilesArg ?: 'all'));
$logLine("Test Name: {$testName}");
$logLine("Archive: {$archivePath}");
$logLine("Started at: " . date('r'));
$logLine("");

$runnerImage = 'doki/playwright-runner:v4';

// Resolve project directory
if ($projectId !== '') {
    $projectDir = $appDir . '/playwright/' . $projectId;
} else {
    $projectDir = $appDir . '/playwright';
}

if (!is_dir($projectDir)) {
    $logLine("ERROR: Project directory not found: {$projectDir}");
    $writeStatus('failed');
    file_put_contents($legacyStatusFile, 'failed');
    exit(1);
}

$logLine("Project directory: {$projectDir}");

// Detect config file
$originalConfig = '';
if (file_exists($projectDir . '/playwright.config.ts')) {
    $originalConfig = 'playwright.config.ts';
    $logLine("Config file: {$projectDir}/playwright.config.ts");
} elseif (file_exists($projectDir . '/playwright.config.js')) {
    $originalConfig = 'playwright.config.js';
    $logLine("Config file: {$projectDir}/playwright.config.js");
} else {
    $logLine("No playwright.config found - will generate standalone config");
}

// Load wrapper config defaults
$projectManager = new PlaywrightProjectManager();
$defaultWrapper = $projectManager->getDefaultWrapperConfig();

$projectYaml = [];
$projectYamlPath = $projectDir . '/project.yaml';
if (file_exists($projectYamlPath) && function_exists('yaml_parse_file')) {
    $projectYaml = yaml_parse_file($projectYamlPath) ?: [];
}

$wrapperConfig = $defaultWrapper;
if (!empty($projectYaml['wrapperConfig']) && is_array($projectYaml['wrapperConfig'])) {
    $wrapperConfig = array_replace_recursive($defaultWrapper, $projectYaml['wrapperConfig']);
}

$overrideReporters = !empty($wrapperConfig['overrideReporters']);
$reporters = $wrapperConfig['reporters'] ?? $defaultWrapper['reporters'];
$overrideRecording = !empty($wrapperConfig['overrideRecording']);
$recording = $wrapperConfig['recording'] ?? $defaultWrapper['recording'];
$overrideExecution = !empty($wrapperConfig['overrideExecution']);
$execution = $wrapperConfig['execution'] ?? $defaultWrapper['execution'];

$baseUrl = getenv('BASE_URL') ?: '';
if ($baseUrl === '' && !empty($projectYaml['baseUrl'])) {
    $baseUrl = (string)$projectYaml['baseUrl'];
}

$app = new AppContext('playwright');
$imageCheck = $app->checkImageExists($runnerImage);
if (empty($imageCheck['exists'])) {
    $logLine("ERROR: Playwright runner image not found. Ask an admin to build it in App Marketplace.");
    $writeStatus('failed');
    file_put_contents($legacyStatusFile, 'failed');
    exit(1);
}

$detectBaseUrl = function() use ($app, $runnerImage, $logLine): string {
    $candidates = [
        'http://host.docker.internal:8099',
        'http://host.docker.internal:3000',
        'http://localhost:8099',
        'http://localhost:3000',
    ];

    $probeScript = <<<JS
const urls = JSON.parse(process.env.DOKI_BASE_URLS || '[]');
const http = require('http');
const https = require('https');
const { URL } = require('url');

const timeoutMs = 1500;

const tryUrl = (url) => new Promise((resolve) => {
  let parsed;
  try {
    parsed = new URL(url);
  } catch (e) {
    resolve(false);
    return;
  }
  const client = parsed.protocol === 'https:' ? https : http;
  const req = client.request(
    {
      hostname: parsed.hostname,
      port: parsed.port || (parsed.protocol === 'https:' ? 443 : 80),
      path: parsed.pathname || '/',
      method: 'GET',
      timeout: timeoutMs,
    },
    (res) => {
      res.resume();
      resolve(true);
    }
  );
  req.on('timeout', () => {
    req.destroy();
    resolve(false);
  });
  req.on('error', () => resolve(false));
  req.end();
});

(async () => {
  for (const url of urls) {
    if (await tryUrl(url)) {
      console.log(url);
      process.exit(0);
    }
  }
  process.exit(1);
})();
JS;

    $command = 'node -e ' . escapeshellarg($probeScript);
    $probe = $app->executeInContainer($runnerImage, $command, [
        'network' => 'host',
        'timeout' => 20,
        'env' => [
            'DOKI_BASE_URLS=' . json_encode($candidates),
            'NODE_OPTIONS=--unhandled-rejections=strict',
        ],
    ]);

    $output = trim((string)($probe['output'] ?? ''));
    if (!empty($probe['success']) && $output !== '') {
        $firstLine = trim(strtok($output, "\n"));
        if ($firstLine && filter_var($firstLine, FILTER_VALIDATE_URL)) {
            return $firstLine;
        }
    }

    if (!empty($output)) {
        $logLine("BASE_URL probe output: {$output}");
    }

    return '';
};

if ($baseUrl === '') {
    $detected = $detectBaseUrl();
    if ($detected !== '') {
        $baseUrl = $detected;
        $logLine("BASE_URL not set. Auto-detected {$baseUrl} (target probe)");
    } else {
        $baseUrl = 'http://host.docker.internal:8099';
        $logLine("BASE_URL not set. Defaulting to {$baseUrl}");
    }
}

// Prepare environment variables
$envVars = [
    'PLAYWRIGHT_HTML_OPEN=never',
    'CI=true',
];
if ($baseUrl !== '') {
    $envVars[] = 'BASE_URL=' . $baseUrl;
    $logLine("BASE_URL: {$baseUrl}");
}

// Build test file list
$testCount = 0;
$containerTestPaths = [];

if ($testFilesArg !== '') {
    $logLine("Preparing specific test files: {$testFilesArg}");
    $files = array_filter(array_map('trim', explode(',', $testFilesArg)));
    foreach ($files as $file) {
        $containerPath = str_starts_with($file, 'tests/') ? $file : 'tests/' . $file;
        $hostPath = $projectDir . '/' . $containerPath;
        if (file_exists($hostPath)) {
            $logLine("  - Found: {$file}");
            $containerTestPaths[] = $containerPath;
            $testCount++;
        } else {
            $logLine("  - WARNING: Not found: {$hostPath}");
        }
    }
} else {
    $logLine("Preparing all tests...");
    $searchDir = is_dir($projectDir . '/tests') ? $projectDir . '/tests' : $projectDir;
    $files = glob($searchDir . '/*.test.ts') ?: [];
    foreach ($files as $file) {
        $logLine('  - Found: ' . basename($file));
        $testCount++;
    }
}

$logLine("");
$logLine("Total test files: {$testCount}");

if ($testCount === 0) {
    $logLine("ERROR: No test files found!");
    $writeStatus('failed');
    file_put_contents($legacyStatusFile, 'failed');
    exit(1);
}

$logLine("");
$logLine("Container project path: {$projectDir}");
$logLine("Listing project directory contents:");
$listing = shell_exec('ls -la ' . escapeshellarg($projectDir) . ' 2>&1');
if ($listing) {
    $logLine(trim($listing));
}
$logLine("");

// Clear old report and test data
$logLine("Clearing old report and test data from project...");
$removeDir($projectDir . '/playwright-report');
$removeDir($projectDir . '/test-results');
@mkdir($projectDir . '/playwright-report', 0755, true);
@mkdir($projectDir . '/test-results', 0755, true);
$logLine("Cleaned: {$projectDir}/playwright-report/");
$logLine("Cleaned: {$projectDir}/test-results/");

// Build reporter config
$reportersJs = [];
if (!empty($reporters['html'])) {
    $reportersJs[] = "['html', { outputFolder: './playwright-report', open: 'never' }]";
}
if (!empty($reporters['list'])) {
    $reportersJs[] = "['list']";
}
if (!empty($reporters['json'])) {
    $reportersJs[] = "['json', { outputFile: './test-results/results.json' }]";
}
if (!empty($reporters['junit'])) {
    $reportersJs[] = "['junit', { outputFile: './test-results/results.xml' }]";
}
$reportersJsString = '[' . implode(',', $reportersJs) . ']';

$wrapperConfigPath = $projectDir . '/doki-wrapper.config.cjs';
$overrideReportersJs = $overrideReporters ? 'true' : 'false';
$overrideRecordingJs = $overrideRecording ? 'true' : 'false';
$overrideExecutionJs = $overrideExecution ? 'true' : 'false';
$executionRetries = (int)($execution['retries'] ?? 2);
$executionWorkers = (int)($execution['workers'] ?? 1);
$executionTimeout = (int)($execution['timeout'] ?? 180000);
$recordingVideo = (string)($recording['video'] ?? 'on-first-retry');
$recordingScreenshot = (string)($recording['screenshot'] ?? 'only-on-failure');
$recordingTrace = (string)($recording['trace'] ?? 'on-first-retry');

$logLine("");
$logLine("Generating Doki wrapper config...");
if ($originalConfig !== '') {
    $template = <<<'JS'
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
    const originalModule = require('./__ORIGINAL_CONFIG__');
    const originalConfig = originalModule.default || originalModule;

    // Build merged config
    const mergedConfig = { ...originalConfig };

    // Override reporters if enabled
    if (__OVERRIDE_REPORTERS__) {
      mergedConfig.reporter = __REPORTERS_JS__;
    }

    // Always set outputDir for test results
    mergedConfig.outputDir = './test-results';

    // Override recording settings if enabled
    if (__OVERRIDE_RECORDING__) {
      mergedConfig.use = {
        ...(originalConfig.use || {}),
        video: '__RECORDING_VIDEO__',
        screenshot: '__RECORDING_SCREENSHOT__',
        trace: '__RECORDING_TRACE__'
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
    if (__OVERRIDE_EXECUTION__) {
      mergedConfig.retries = __EXECUTION_RETRIES__;
      mergedConfig.workers = __EXECUTION_WORKERS__;
      mergedConfig.timeout = __EXECUTION_TIMEOUT__;
    }

    return mergedConfig;
  } catch (error) {
    console.error('Failed to load original config:', error.message);
    // Fallback minimal config
    return {
      testDir: './tests',
      reporter: __REPORTERS_JS__,
      outputDir: './test-results',
      use: {
        trace: '__RECORDING_TRACE__',
        screenshot: '__RECORDING_SCREENSHOT__',
        video: '__RECORDING_VIDEO__'
      }
    };
  }
}

module.exports = loadConfig();
JS;

    $content = str_replace(
        [
            '__ORIGINAL_CONFIG__',
            '__OVERRIDE_REPORTERS__',
            '__REPORTERS_JS__',
            '__OVERRIDE_RECORDING__',
            '__RECORDING_VIDEO__',
            '__RECORDING_SCREENSHOT__',
            '__RECORDING_TRACE__',
            '__OVERRIDE_EXECUTION__',
            '__EXECUTION_RETRIES__',
            '__EXECUTION_WORKERS__',
            '__EXECUTION_TIMEOUT__',
        ],
        [
            $originalConfig,
            $overrideReportersJs,
            $reportersJsString,
            $overrideRecordingJs,
            $recordingVideo,
            $recordingScreenshot,
            $recordingTrace,
            $overrideExecutionJs,
            (string)$executionRetries,
            (string)$executionWorkers,
            (string)$executionTimeout,
        ],
        $template
    );
    file_put_contents($wrapperConfigPath, $content);
} else {
    $logLine("Generating standalone Doki config (no original playwright.config found)...");

    $testDir = is_dir($projectDir . '/tests') ? './tests' : '.';
    $baseUrlConfig = "baseURL: process.env.BASE_URL,";
    if (!empty($projectYaml['baseUrl'])) {
        $baseUrlConfig = "baseURL: process.env.BASE_URL || '" . addslashes((string)$projectYaml['baseUrl']) . "',";
    }

    $template = <<<'JS'
/**
 * Doki Playwright Standalone Config
 *
 * This file is auto-generated by Doki because no playwright.config was found.
 * Settings are loaded from the project's wrapperConfig in project.yaml.
 */

module.exports = {
  testDir: '__TEST_DIR__',
  testMatch: '**/*.test.{ts,js}',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,

  // Execution settings
  retries: __OVERRIDE_EXECUTION__ ? __EXECUTION_RETRIES__ : 0,
  workers: __OVERRIDE_EXECUTION__ ? __EXECUTION_WORKERS__ : undefined,
  timeout: __OVERRIDE_EXECUTION__ ? __EXECUTION_TIMEOUT__ : 30000,

  // Reporter settings
  reporter: __REPORTERS_JS__,
  outputDir: './test-results',

  // Browser settings
  use: {
    __BASE_URL_CONFIG__
    trace: '__RECORDING_TRACE__',
    screenshot: '__RECORDING_SCREENSHOT__',
    video: '__RECORDING_VIDEO__',
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
JS;

    $content = str_replace(
        [
            '__TEST_DIR__',
            '__OVERRIDE_EXECUTION__',
            '__EXECUTION_RETRIES__',
            '__EXECUTION_WORKERS__',
            '__EXECUTION_TIMEOUT__',
            '__REPORTERS_JS__',
            '__BASE_URL_CONFIG__',
            '__RECORDING_TRACE__',
            '__RECORDING_SCREENSHOT__',
            '__RECORDING_VIDEO__',
        ],
        [
            $testDir,
            $overrideExecutionJs,
            (string)$executionRetries,
            (string)$executionWorkers,
            (string)$executionTimeout,
            $reportersJsString,
            $baseUrlConfig,
            $recordingTrace,
            $recordingScreenshot,
            $recordingVideo,
        ],
        $template
    );
    file_put_contents($wrapperConfigPath, $content);
}

$logLine("Wrapper config created: {$wrapperConfigPath}");

$logLine("");
$logLine("========================================");
$logLine("Running Playwright tests in container...");
$logLine("Image: {$runnerImage}");
$logLine("========================================");
$logLine("");
$logLine("Wrapper settings:");
$logLine("  Override reporters: {$overrideReportersJs} (html:" . (!empty($reporters['html']) ? 'true' : 'false') . ", list:" . (!empty($reporters['list']) ? 'true' : 'false') . ", json:" . (!empty($reporters['json']) ? 'true' : 'false') . ", junit:" . (!empty($reporters['junit']) ? 'true' : 'false') . ")");
$logLine("  Override recording: {$overrideRecordingJs} (video:{$recordingVideo}, screenshot:{$recordingScreenshot}, trace:{$recordingTrace})");
$logLine("  Override execution: {$overrideExecutionJs} (retries:{$executionRetries}, workers:{$executionWorkers}, timeout:{$executionTimeout})");
$logLine("");

$commandArgs = ['test'];
foreach ($containerTestPaths as $path) {
    $commandArgs[] = $path;
}
$commandArgs[] = '--config=doki-wrapper.config.cjs';

$containerTimeout = 3600;

$result = $app->executeInContainer($runnerImage, '', [
    'useEntrypoint' => true,
    'args' => $commandArgs,
    'workdir' => '/tests',
    'network' => 'host',
    'env' => $envVars,
    'volumes' => [$projectDir . ':/tests:rw'],
    'timeout' => $containerTimeout,
]);

if (!empty($result['output'])) {
    $logLine($result['output']);
}

$exitCode = (int)($result['exitCode'] ?? 1);

$logLine("");
$logLine("Playwright execution finished at " . date('r'));
$logLine("Exit code: {$exitCode}");

// Look for video files
$logLine("");
$logLine("Looking for video files...");
$videoFiles = [];
$resultsDir = $projectDir . '/test-results';
if (is_dir($resultsDir)) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resultsDir));
    foreach ($iterator as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.webm')) {
            $videoFiles[] = $file->getPathname();
        }
    }
}

if (!empty($videoFiles)) {
    $logLine("Found " . count($videoFiles) . " video file(s)");
    $writeFile($legacyResultFile, $videoFiles[0]);
} else {
    $logLine("No video files found");
}

// Copy HTML report to archive
$projectReport = $projectDir . '/playwright-report/index.html';
$globalReport = $appDir . '/playwright-report/index.html';

$logLine("");
$logLine("Looking for HTML report...");

$reportSrcDir = '';
if (file_exists($projectReport)) {
    $logLine("Report found in project: {$projectReport}");
    $reportSrcDir = $projectDir . '/playwright-report';
} elseif (file_exists($globalReport)) {
    $logLine("Report found in global: {$globalReport}");
    $reportSrcDir = $appDir . '/playwright-report';
}

if ($reportSrcDir !== '') {
    $logLine("Copying report to archive: {$archivePath}");
    $copyDir($reportSrcDir, $archivePath);
    if (file_exists($archivePath . '/index.html')) {
        $logLine("Report archived successfully");
    } else {
        $logLine("WARNING: Failed to copy report");
    }
} else {
    $logLine("No HTML report generated");
    $logLine("Checked: {$projectReport}");
    $logLine("Checked: {$globalReport}");
}

// Copy test results
if (is_dir($projectDir . '/test-results')) {
    $logLine("Copying test results (videos, screenshots)...");
    $copyDir($projectDir . '/test-results', $archivePath . '/test-results');
}

// Update metadata
$writeFile($archivePath . '/testcount.txt', (string)$testCount);
$writeFile($archivePath . '/exitcode.txt', (string)$exitCode);
$writeFile($archivePath . '/endtime.txt', (new DateTimeImmutable('now', new DateTimeZone('UTC')))
    ->format('Y-m-d\TH-i-s-') . str_pad((new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('v'), 3, '0', STR_PAD_LEFT) . 'Z');

$logLine("");
$logLine("=== Test Complete ===");
$logLine("Finished at: " . date('r'));

$finalStatus = $exitCode === 0 ? 'completed' : 'failed';
$writeStatus($finalStatus);
file_put_contents($legacyStatusFile, $finalStatus);

if (file_exists($wrapperConfigPath)) {
    @unlink($wrapperConfigPath);
    $logLine("Cleaned up wrapper config");
}
