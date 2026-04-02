<?php
require_once __DIR__ . '/includes/session-bootstrap.php';

doki_start_session();

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// Check authentication
if (!isset($_SESSION['authenticated']) || !$_SESSION['authenticated']) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

require_once __DIR__ . '/includes/RuntimeEnvironment.php';

$action = $_GET['action'] ?? '';

function goOrchestratorBaseUrl(): string {
    return RuntimeEnvironment::getOrchestratorBaseUrl();
}

function goOrchestratorTimeout(): int {
    $value = getenv('DOKI_ORCH_TIMEOUT');
    if ($value === false || trim($value) === '') {
        return 330;
    }
    $timeout = (int)$value;
    return $timeout > 0 ? $timeout : 330;
}

function goRequest(string $method, string $path, ?array $payload = null, array $query = []): array {
    $baseUrl = goOrchestratorBaseUrl();
    $url = $baseUrl . $path;
    if (!empty($query)) {
        $url .= '?' . http_build_query($query);
    }

    $options = [
        'http' => [
            'method' => $method,
            'header' => 'Content-Type: application/json',
            'timeout' => goOrchestratorTimeout(),
            'ignore_errors' => true,
        ],
    ];
    if ($payload !== null) {
        $options['http']['content'] = json_encode($payload);
    }

    $context = stream_context_create($options);
    $body = @file_get_contents($url, false, $context);
    $status = 0;
    if (isset($http_response_header[0]) && preg_match('/HTTP\\/(?:\\d\\.\\d)\\s+(\\d+)/', $http_response_header[0], $m)) {
        $status = (int)$m[1];
    }

    if ($body === false) {
        return ['status' => 502, 'data' => ['success' => false, 'error' => 'Go orchestrator unavailable']];
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        $data = ['success' => false, 'error' => 'Invalid response from Go orchestrator'];
    }

    return ['status' => $status ?: 502, 'data' => $data];
}

// List available test projects and test files
if ($action === 'list-tests') {
    $projects = [];
    
    // Default project (root playwright folder)
    $defaultTests = glob(__DIR__ . '/playwright/*.test.ts');
    $defaultTestFiles = array_map(function($file) {
        return basename($file);
    }, $defaultTests);
    
    $projects[] = [
        'id' => '',
        'name' => '(default - playwright/)',
        'tests' => $defaultTestFiles
    ];
    
    // Scan for project folders in playwright/
    $projectDirs = glob(__DIR__ . '/playwright/*', GLOB_ONLYDIR);
    foreach ($projectDirs as $dir) {
        $projectId = basename($dir);
        
        // Skip non-project directories (like node_modules, etc.)
        if (in_array($projectId, ['node_modules', '.cache', 'test-results'])) {
            continue;
        }
        
        // Check if this directory has a tests folder with test files
        $testFiles = glob($dir . '/tests/*.test.ts');
        if (empty($testFiles)) {
            // Also check root of project folder
            $testFiles = glob($dir . '/*.test.ts');
        }
        
        if (!empty($testFiles)) {
            $testFileNames = array_map(function($file) {
                return basename($file);
            }, $testFiles);
            
            // Create a friendly name from the project ID
            $friendlyName = ucwords(str_replace(['-', '_'], ' ', $projectId));
            
            $projects[] = [
                'id' => $projectId,
                'name' => $friendlyName,
                'tests' => $testFileNames
            ];
        }
    }
    
    echo json_encode([
        'success' => true,
        'projects' => $projects
    ]);
    exit;
}

// Start a new job
if ($action === 'start') {
    // Get parameters
    $project = $_GET['project'] ?? '';
    $testFiles = $_GET['testFiles'] ?? '';  // Comma-separated list of test files
    $testName = $_GET['testName'] ?? 'Unnamed Test';
    
    // Validate project name (alphanumeric, dashes, underscores only)
    if ($project && !preg_match('/^[a-zA-Z0-9_-]+$/', $project)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid project name']);
        exit;
    }
    
    // Validate test file names
    $validatedTestFiles = [];
    if ($testFiles) {
        $files = explode(',', $testFiles);
        foreach ($files as $file) {
            $file = trim($file);
            if ($file && preg_match('/^[a-zA-Z0-9._-]+\.test\.(ts|js)$/', $file)) {
                $validatedTestFiles[] = $file;
            }
        }
    }
    
    $payload = [
        'projectId' => $project,
        'testFiles' => $validatedTestFiles,
        'testName' => $testName,
        'user' => [
            'id' => $_SESSION['user_id'] ?? null,
            'username' => $_SESSION['username'] ?? 'legacy-session',
            'role' => $_SESSION['role'] ?? null,
        ],
    ];

    $resp = goRequest('POST', '/playwright/run', $payload);
    http_response_code($resp['status']);
    echo json_encode($resp['data']);
    exit;
}

// Check job status
if ($action === 'status') {
    $jobId = $_GET['jobId'] ?? '';
    
    if (empty($jobId)) {
        http_response_code(400);
        echo json_encode(['error' => 'Job ID required']);
        exit;
    }
    
    $resp = goRequest('GET', '/playwright/job/' . urlencode($jobId));
    http_response_code($resp['status']);
    echo json_encode($resp['data']);
    exit;
}

// Delete old jobs
if ($action === 'cleanup') {
    $resp = goRequest('POST', '/playwright/reports/cleanup', [
        'retentionDays' => 1,
        'maxReports' => 500,
    ]);
    http_response_code($resp['status']);
    echo json_encode($resp['data']);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Invalid action']);
