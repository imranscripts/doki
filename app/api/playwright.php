<?php
/**
 * playwright.php - API endpoints for Playwright project management
 * 
 * Handles project CRUD, file operations, and git sync.
 */

// Suppress PHP errors from corrupting JSON output
ini_set('display_errors', '0');
error_reporting(E_ALL);
ob_start();

require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/PlaywrightProjectManager.php';
require_once __DIR__ . '/../includes/PlaywrightRuntimeManager.php';
require_once __DIR__ . '/../includes/RuntimeEnvironment.php';
require_once __DIR__ . '/../includes/session-bootstrap.php';

header('Content-Type: application/json');

doki_start_session();

function jsonResponse($data, $code = 200) {
    ob_end_clean();
    http_response_code($code);
    echo json_encode($data);
    exit;
}

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

    $headers = [
        'Content-Type: application/json',
    ];

    $options = [
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
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
        return [
            'status' => 502,
            'data' => ['success' => false, 'error' => 'Go orchestrator unavailable'],
            'raw' => null,
        ];
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        $data = ['success' => false, 'error' => 'Invalid response from Go orchestrator'];
    }

    return [
        'status' => $status ?: 502,
        'data' => $data,
        'raw' => $body,
    ];
}

// Check authentication
doki_start_session();
$db = new Database();
$auth = new Auth($db);
$token = $_SESSION['auth_token'] ?? null;
$user = $auth->validateSession($token);

if (!$user) {
    jsonResponse(['success' => false, 'error' => 'Unauthorized'], 401);
}

$projectManager = new PlaywrightProjectManager();
$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        // ===== Runtime Status =====

        case 'runtime-status':
            $runtimeManager = new PlaywrightRuntimeManager();
            $status = $runtimeManager->getStatus();
            jsonResponse(['success' => true, 'status' => $status]);
            break;

        case 'build-runner':
            // Build Playwright runner image (admin only)
            if ($user['role'] !== 'super-admin' && $user['role'] !== 'admin') {
                jsonResponse(['success' => false, 'error' => 'Admin access required'], 403);
            }

            $runtimeManager = new PlaywrightRuntimeManager();
            if (!$runtimeManager->isDockerAvailable()) {
                jsonResponse(['success' => false, 'error' => 'Docker unavailable'], 503);
            }

            $buildStatus = $runtimeManager->getBuildStatus();
            if (($buildStatus['status'] ?? '') === 'building') {
                jsonResponse(['success' => true, 'message' => 'Build already in progress', 'building' => true]);
            }

            $buildScript = __DIR__ . '/../scripts/build-playwright-runner.php';
            if (!file_exists($buildScript)) {
                jsonResponse(['success' => false, 'error' => 'Build script not found'], 500);
            }
            $phpBinary = PHP_BINARY ?: 'php';
            $cmd = sprintf(
                'nohup %s %s > /dev/null 2>&1 &',
                escapeshellarg($phpBinary),
                escapeshellarg($buildScript)
            );
            exec($cmd);

            $auth->auditLog(
                $user['id'],
                $user['username'],
                'playwright_runner_build',
                'playwright_runtime',
                'runner',
                'Triggered Playwright runner build'
            );

            jsonResponse(['success' => true, 'message' => 'Build started', 'building' => true]);
            break;

        case 'rebuild-runner':
            // Rebuild Playwright runner image (admin only)
            if ($user['role'] !== 'super-admin' && $user['role'] !== 'admin') {
                jsonResponse(['success' => false, 'error' => 'Admin access required'], 403);
            }

            $runtimeManager = new PlaywrightRuntimeManager();
            if (!$runtimeManager->isDockerAvailable()) {
                jsonResponse(['success' => false, 'error' => 'Docker unavailable'], 503);
            }

            $buildStatus = $runtimeManager->getBuildStatus();
            if (($buildStatus['status'] ?? '') === 'building') {
                jsonResponse(['success' => true, 'message' => 'Build already in progress', 'building' => true]);
            }

            $buildScript = __DIR__ . '/../scripts/build-playwright-runner.php';
            if (!file_exists($buildScript)) {
                jsonResponse(['success' => false, 'error' => 'Build script not found'], 500);
            }
            $phpBinary = PHP_BINARY ?: 'php';
            $cmd = sprintf(
                'nohup %s %s %s > /dev/null 2>&1 &',
                escapeshellarg($phpBinary),
                escapeshellarg($buildScript),
                escapeshellarg('rebuild')
            );
            exec($cmd);

            $auth->auditLog(
                $user['id'],
                $user['username'],
                'playwright_runner_rebuild',
                'playwright_runtime',
                'runner',
                'Triggered Playwright runner rebuild'
            );

            jsonResponse(['success' => true, 'message' => 'Rebuild started', 'building' => true]);
            break;

        // ===== Project Operations =====
        
        case 'projects':
            // List all projects
            $projects = $projectManager->getProjects();
            jsonResponse(['success' => true, 'projects' => $projects]);
            break;

        case 'project':
            // Get single project
            $projectId = $_GET['id'] ?? '';
            if (empty($projectId)) {
                jsonResponse(['success' => false, 'error' => 'Project ID required'], 400);
            }
            $project = $projectManager->loadProject($projectId);
            if (!$project) {
                jsonResponse(['success' => false, 'error' => 'Project not found'], 404);
            }
            jsonResponse(['success' => true, 'project' => $project]);
            break;
            
        case 'default-wrapper-config':
            // Get default wrapper configuration (for UI preload)
            jsonResponse([
                'success' => true, 
                'wrapperConfig' => $projectManager->getDefaultWrapperConfig()
            ]);
            break;

        case 'create-project':
            // Create new project (admin only)
            // Accepts multipart/form-data (with file upload) or JSON
            if ($user['role'] !== 'super-admin' && $user['role'] !== 'admin') {
                jsonResponse(['success' => false, 'error' => 'Admin access required'], 403);
            }

            $uploadedFile = $_FILES['file'] ?? null;
            if (!empty($_POST)) {
                // multipart/form-data: metadata in POST fields
                $input = $_POST;
                // wrapperConfig arrives as JSON string from FormData
                if (isset($input['wrapperConfig']) && is_string($input['wrapperConfig'])) {
                    $input['wrapperConfig'] = json_decode($input['wrapperConfig'], true) ?: [];
                }
            } else {
                // JSON body (git projects without file upload)
                $input = json_decode(file_get_contents('php://input'), true);
            }
            if (!$input) {
                jsonResponse(['success' => false, 'error' => 'Invalid input'], 400);
            }

            $result = $projectManager->createProject($input, $user['id'], $user['username'], $uploadedFile);
            
            if ($result['success']) {
                $auth->auditLog($user['id'], $user['username'], 'project_create', 'playwright_project', $result['projectId'], 'Created project: ' . ($input['name'] ?? $result['projectId']));
            }
            
            jsonResponse($result, $result['success'] ? 200 : 400);
            break;

        case 'update-project':
            // Update project (admin only)
            if ($user['role'] !== 'super-admin' && $user['role'] !== 'admin') {
                jsonResponse(['success' => false, 'error' => 'Admin access required'], 403);
            }
            
            $projectId = $_GET['id'] ?? '';
            if (empty($projectId)) {
                jsonResponse(['success' => false, 'error' => 'Project ID required'], 400);
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input) {
                jsonResponse(['success' => false, 'error' => 'Invalid JSON input'], 400);
            }
            
            $result = $projectManager->updateProject($projectId, $input, $user['id'], $user['username']);
            
            if ($result['success']) {
                $auth->auditLog($user['id'], $user['username'], 'project_update', 'playwright_project', $projectId, 'Updated project');
            }
            
            jsonResponse($result, $result['success'] ? 200 : 400);
            break;

        case 'delete-summary':
            // Get deletion summary for confirmation modal (admin only)
            if ($user['role'] !== 'super-admin' && $user['role'] !== 'admin') {
                jsonResponse(['success' => false, 'error' => 'Admin access required'], 403);
            }
            
            $projectId = $_GET['id'] ?? '';
            if (empty($projectId)) {
                jsonResponse(['success' => false, 'error' => 'Project ID required'], 400);
            }
            
            $result = $projectManager->getDeleteSummary($projectId);
            jsonResponse($result, $result['success'] ? 200 : 400);
            break;
            
        case 'delete-project':
            // Delete project with optional cascade delete (admin only)
            if ($user['role'] !== 'super-admin' && $user['role'] !== 'admin') {
                jsonResponse(['success' => false, 'error' => 'Admin access required'], 403);
            }
            
            $projectId = $_GET['id'] ?? '';
            if (empty($projectId)) {
                jsonResponse(['success' => false, 'error' => 'Project ID required'], 400);
            }
            
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            $deleteReports = $input['deleteReports'] ?? true;
            
            $result = $projectManager->deleteProject($projectId, $deleteReports);
            
            if ($result['success']) {
                $details = 'Deleted project';
                if ($result['deletedReports'] > 0) {
                    $details .= " and {$result['deletedReports']} related reports";
                }
                $auth->auditLog($user['id'], $user['username'], 'project_delete', 'playwright_project', $projectId, $details);
            }
            
            jsonResponse($result, $result['success'] ? 200 : 400);
            break;

        case 'sync-project':
            // Sync project from git (admin only)
            if ($user['role'] !== 'super-admin' && $user['role'] !== 'admin') {
                jsonResponse(['success' => false, 'error' => 'Admin access required'], 403);
            }
            
            $projectId = $_GET['id'] ?? '';
            if (empty($projectId)) {
                jsonResponse(['success' => false, 'error' => 'Project ID required'], 400);
            }
            
            $result = $projectManager->syncFromGit($projectId);
            
            if ($result['success']) {
                $auth->auditLog($user['id'], $user['username'], 'project_sync', 'playwright_project', $projectId, 'Synced from git');
            }
            
            jsonResponse($result, $result['success'] ? 200 : 400);
            break;

        // ===== File Operations =====
        
        case 'files':
            // Get file tree for project
            $projectId = $_GET['id'] ?? '';
            $subPath = $_GET['path'] ?? '';
            
            if (empty($projectId)) {
                jsonResponse(['success' => false, 'error' => 'Project ID required'], 400);
            }
            
            $files = $projectManager->getFileTree($projectId, $subPath);
            jsonResponse(['success' => true, 'files' => $files]);
            break;

        case 'file':
            // Get single file content
            $projectId = $_GET['id'] ?? '';
            $filePath = $_GET['path'] ?? '';
            
            if (empty($projectId) || empty($filePath)) {
                jsonResponse(['success' => false, 'error' => 'Project ID and file path required'], 400);
            }
            
            $file = $projectManager->getFileContent($projectId, $filePath);
            if (!$file) {
                jsonResponse(['success' => false, 'error' => 'File not found'], 404);
            }
            
            jsonResponse(['success' => true, 'file' => $file]);
            break;

        case 'save-file':
            // Save file content (admin only)
            if ($user['role'] !== 'super-admin' && $user['role'] !== 'admin') {
                jsonResponse(['success' => false, 'error' => 'Admin access required'], 403);
            }
            
            $projectId = $_GET['id'] ?? '';
            $filePath = $_GET['path'] ?? '';
            
            if (empty($projectId) || empty($filePath)) {
                jsonResponse(['success' => false, 'error' => 'Project ID and file path required'], 400);
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            if (!isset($input['content'])) {
                jsonResponse(['success' => false, 'error' => 'Content required'], 400);
            }
            
            $result = $projectManager->saveFileContent($projectId, $filePath, $input['content']);
            
            if ($result['success']) {
                $auth->auditLog($user['id'], $user['username'], 'file_save', 'playwright_file', $projectId . ':' . $filePath, 'Saved file');
            }
            
            jsonResponse($result, $result['success'] ? 200 : 400);
            break;

        case 'create-file':
            // Create new file (admin only)
            if ($user['role'] !== 'super-admin' && $user['role'] !== 'admin') {
                jsonResponse(['success' => false, 'error' => 'Admin access required'], 403);
            }
            
            $projectId = $_GET['id'] ?? '';
            
            if (empty($projectId)) {
                jsonResponse(['success' => false, 'error' => 'Project ID required'], 400);
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            if (!isset($input['path'])) {
                jsonResponse(['success' => false, 'error' => 'File path required'], 400);
            }
            
            $result = $projectManager->createFile($projectId, $input['path'], $input['content'] ?? '');
            
            if ($result['success']) {
                $auth->auditLog($user['id'], $user['username'], 'file_create', 'playwright_file', $projectId . ':' . $input['path'], 'Created file');
            }
            
            jsonResponse($result, $result['success'] ? 200 : 400);
            break;

        case 'delete-file':
            // Delete file (admin only)
            if ($user['role'] !== 'super-admin' && $user['role'] !== 'admin') {
                jsonResponse(['success' => false, 'error' => 'Admin access required'], 403);
            }
            
            $projectId = $_GET['id'] ?? '';
            $filePath = $_GET['path'] ?? '';
            
            if (empty($projectId) || empty($filePath)) {
                jsonResponse(['success' => false, 'error' => 'Project ID and file path required'], 400);
            }
            
            $result = $projectManager->deleteFile($projectId, $filePath);
            
            if ($result['success']) {
                $auth->auditLog($user['id'], $user['username'], 'file_delete', 'playwright_file', $projectId . ':' . $filePath, 'Deleted file');
            }
            
            jsonResponse($result, $result['success'] ? 200 : 400);
            break;

        case 'upload':
            // Upload file (admin only)
            if ($user['role'] !== 'super-admin' && $user['role'] !== 'admin') {
                jsonResponse(['success' => false, 'error' => 'Admin access required'], 403);
            }
            
            $projectId = $_GET['id'] ?? '';
            $targetPath = $_GET['path'] ?? '';
            
            if (empty($projectId)) {
                jsonResponse(['success' => false, 'error' => 'Project ID required'], 400);
            }
            
            if (!isset($_FILES['file'])) {
                jsonResponse(['success' => false, 'error' => 'No file uploaded'], 400);
            }
            
            $result = $projectManager->uploadFile($projectId, $_FILES['file'], $targetPath);
            
            if ($result['success']) {
                $auth->auditLog($user['id'], $user['username'], 'file_upload', 'playwright_file', $projectId, 'Uploaded file');
            }
            
            jsonResponse($result, $result['success'] ? 200 : 400);
            break;

        case 'tests':
            // Get test files for project
            $projectId = $_GET['id'] ?? '';
            
            if (empty($projectId)) {
                jsonResponse(['success' => false, 'error' => 'Project ID required'], 400);
            }
            
            $tests = $projectManager->getTestFiles($projectId);
            jsonResponse(['success' => true, 'tests' => $tests]);
            break;

        // ===== Test Execution =====
        
        case 'run':
            // Start a test run
            $projectId = $_GET['id'] ?? '';
            
            if (empty($projectId)) {
                jsonResponse(['success' => false, 'error' => 'Project ID required'], 400);
            }
            
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            
            $testFiles = $input['testFiles'] ?? [];
            $testName = $input['testName'] ?? 'Test Run';
            $environment = $input['environment'] ?? null;

            $projectPath = $projectId === '_default' ? '' : $projectId;
            $baseUrl = null;
            if ($environment) {
                require_once __DIR__ . '/../includes/AppManager.php';
                $appManager = new AppManager();
                $settings = $appManager->loadSettings('playwright');
                foreach ($settings['testEnvironments'] ?? [] as $env) {
                    if (($env['id'] ?? '') === $environment) {
                        if (!empty($env['baseUrl'])) {
                            $baseUrl = $env['baseUrl'];
                        }
                        break;
                    }
                }
            }

            require_once __DIR__ . '/../includes/AppManager.php';
            $appManager = new AppManager();
            $installedApps = $appManager->getInstalledApps();
            $installedPlaywright = $installedApps['playwright'] ?? [];
            $execTarget = $installedPlaywright['execTarget'] ?? null;

            $payload = [
                'projectId' => $projectPath,
                'testFiles' => is_array($testFiles) ? $testFiles : [],
                'testName' => $testName,
                'baseUrl' => $baseUrl,
                'targetId' => $input['targetId'] ?? $execTarget,
                'user' => [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'role' => $user['role'] ?? null,
                ],
            ];

            $resp = goRequest('POST', '/playwright/run', $payload);
            if (!empty($resp['data']['success'])) {
                $auth->auditLog($user['id'], $user['username'], 'test_run', 'playwright_test', $projectId, "Started test: {$testName}");
            }
            jsonResponse($resp['data'], $resp['status']);
            break;

        case 'job-status':
            // Get job status from Go orchestrator
            $jobId = $_GET['jobId'] ?? '';
            
            if (empty($jobId)) {
                jsonResponse(['success' => false, 'error' => 'Job ID required'], 400);
            }

            $goResp = goRequest('GET', '/playwright/job/' . urlencode($jobId));
            if (!empty($goResp['data']['success'])) {
                jsonResponse($goResp['data'], $goResp['status']);
            }
            
            $status = 'unknown';
            $output = '';
            $videoPath = null;
            $archivePath = null;
            
            // First, try to find the job in archive folders (new location)
            $archiveDir = __DIR__ . '/../playwright-reports-archive';
            $foundInArchive = false;
            
            if (is_dir($archiveDir)) {
                $dirs = scandir($archiveDir);
                rsort($dirs); // Check newest first
                
                foreach ($dirs as $dir) {
                    if ($dir === '.' || $dir === '..') continue;
                    
                    $jobIdFile = $archiveDir . '/' . $dir . '/jobid.txt';
                    if (file_exists($jobIdFile) && trim(file_get_contents($jobIdFile)) === $jobId) {
                        // Found the archive folder for this job
                        $archivePath = $archiveDir . '/' . $dir;
                        $statusFile = $archivePath . '/status.txt';
                        $logFile = $archivePath . '/output.log';
                        
                        $status = file_exists($statusFile) ? trim(file_get_contents($statusFile)) : 'unknown';
                        $output = file_exists($logFile) ? file_get_contents($logFile) : '';
                        $foundInArchive = true;
                        break;
                    }
                }
            }
            
            // Fallback to legacy job files if not found in archive
            if (!$foundInArchive) {
                $legacyStatusFile = __DIR__ . "/../playwright-jobs/{$jobId}.status";
                $legacyLogFile = __DIR__ . "/../playwright-jobs/{$jobId}.log";
                $legacyResultFile = __DIR__ . "/../playwright-jobs/{$jobId}.result";
                
                if (file_exists($legacyStatusFile)) {
                    $status = trim(file_get_contents($legacyStatusFile));
                    $output = file_exists($legacyLogFile) ? file_get_contents($legacyLogFile) : '';
                    $videoPath = file_exists($legacyResultFile) ? trim(file_get_contents($legacyResultFile)) : null;
                }
            }
            
            if ($status === 'unknown' && empty($output)) {
                jsonResponse(['success' => false, 'error' => 'Job not found'], 404);
            }

            // If the run is still marked running but no Go job exists anymore,
            // treat it as stale and mark as failed to avoid indefinite UI polling.
            if ($status === 'running' && $foundInArchive) {
                $status = 'failed';
                if (!empty($archivePath)) {
                    @file_put_contents($archivePath . '/status.txt', $status);
                    @file_put_contents($archivePath . '/endtime.txt', (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                        ->format('Y-m-d\TH-i-s-') . str_pad((new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('v'), 3, '0', STR_PAD_LEFT) . 'Z');
                }
            }
            
            jsonResponse([
                'success' => true,
                'status' => $status,
                'output' => $output,
                'videoPath' => $videoPath,
                'isComplete' => in_array($status, ['completed', 'failed', 'cancelled'], true)
            ]);
            break;

        case 'cancel-job':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                jsonResponse(['success' => false, 'error' => 'POST required'], 405);
            }

            $jobId = $_GET['jobId'] ?? '';
            if ($jobId === '') {
                $input = json_decode(file_get_contents('php://input'), true) ?: [];
                $jobId = $input['jobId'] ?? '';
            }
            if ($jobId === '') {
                jsonResponse(['success' => false, 'error' => 'Job ID required'], 400);
            }

            $goResp = goRequest('POST', '/playwright/job/' . urlencode($jobId) . '/cancel', [
                'user' => [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'role' => $user['role'] ?? null,
                ],
            ]);

            if (!empty($goResp['data']['success'])) {
                jsonResponse($goResp['data'], $goResp['status']);
            }

            // Fallback: if this is an orphaned archive-only run, mark as cancelled.
            $archiveDir = __DIR__ . '/../playwright-reports-archive';
            $patched = false;
            if (is_dir($archiveDir)) {
                $dirs = scandir($archiveDir);
                rsort($dirs);
                foreach ($dirs as $dir) {
                    if ($dir === '.' || $dir === '..') continue;
                    $candidate = $archiveDir . '/' . $dir;
                    $jobIdFile = $candidate . '/jobid.txt';
                    if (file_exists($jobIdFile) && trim(file_get_contents($jobIdFile)) === $jobId) {
                        @file_put_contents($candidate . '/status.txt', 'cancelled');
                        @file_put_contents($candidate . '/endtime.txt', (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                            ->format('Y-m-d\TH-i-s-') . str_pad((new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('v'), 3, '0', STR_PAD_LEFT) . 'Z');
                        $patched = true;
                        break;
                    }
                }
            }

            $legacyStatusFile = __DIR__ . "/../playwright-jobs/{$jobId}.status";
            if (file_exists($legacyStatusFile)) {
                @file_put_contents($legacyStatusFile, 'cancelled');
                $patched = true;
            }

            if ($patched) {
                jsonResponse(['success' => true, 'status' => 'cancelled']);
            }

            jsonResponse($goResp['data'], $goResp['status']);
            break;

        // ===== Reports =====
        
        case 'reports':
            // List all test runs (including running, completed, and failed)
            $archiveDir = __DIR__ . '/../playwright-reports-archive';
            $reports = [];
            
            // Scan all archive directories (not just those with index.html)
            if (is_dir($archiveDir)) {
                $dirs = scandir($archiveDir);
                rsort($dirs); // Newest first
                
                foreach ($dirs as $dir) {
                    if ($dir === '.' || $dir === '..') continue;
                    
                    $dirPath = $archiveDir . '/' . $dir;
                    if (!is_dir($dirPath)) continue;
                    
                    $metadata = getReportMetadata($dirPath);
                    $hasReport = file_exists($dirPath . '/index.html');
                    $hasLog = file_exists($dirPath . '/output.log');
                    
                    $reports[] = [
                        'id' => $dir,
                        'path' => $hasReport ? 'playwright-reports-archive/' . $dir . '/index.html' : null,
                        'logPath' => $hasLog ? 'playwright-reports-archive/' . $dir . '/output.log' : null,
                        'dataPath' => 'playwright-reports-archive/' . $dir . '/data/',
                        'timestamp' => $metadata['startTimestamp'] ?? filemtime($dirPath),
                        'date' => $metadata['startTime'] ?? date('c', filemtime($dirPath)),
                        'endTime' => $metadata['endTime'],
                        'name' => $metadata['testName'],
                        'project' => $metadata['project'],
                        'testFiles' => $metadata['testFiles'],
                        'testCount' => $metadata['testCount'],
                        'status' => $metadata['status'],
                        'exitCode' => $metadata['exitCode'],
                        'hasReport' => $hasReport,
                        'hasLog' => $hasLog,
                        'videos' => $metadata['videos'],
                        'screenshots' => $metadata['screenshots']
                    ];
                }
            }
            
            jsonResponse(['success' => true, 'reports' => $reports]);
            break;
            
        case 'report-log':
            // Get log content for a specific report/run
            $reportId = $_GET['id'] ?? '';
            
            if (empty($reportId)) {
                jsonResponse(['success' => false, 'error' => 'Report ID required'], 400);
            }
            
            $logFile = __DIR__ . '/../playwright-reports-archive/' . basename($reportId) . '/output.log';
            
            if (!file_exists($logFile)) {
                jsonResponse(['success' => false, 'error' => 'Log not found'], 404);
            }
            
            $statusFile = __DIR__ . '/../playwright-reports-archive/' . basename($reportId) . '/status.txt';
            $status = file_exists($statusFile) ? trim(file_get_contents($statusFile)) : 'unknown';
            
            jsonResponse([
                'success' => true,
                'log' => file_get_contents($logFile),
                'status' => $status,
                'isRunning' => $status === 'running'
            ]);
            break;

        case 'delete-report':
            // Delete a report (admin only)
            if ($user['role'] !== 'super-admin' && $user['role'] !== 'admin') {
                jsonResponse(['success' => false, 'error' => 'Admin access required'], 403);
            }
            
            $reportId = $_GET['id'] ?? '';
            
            if (empty($reportId) || $reportId === 'current') {
                jsonResponse(['success' => false, 'error' => 'Cannot delete current report'], 400);
            }
            
            $reportDir = __DIR__ . '/../playwright-reports-archive/' . basename($reportId);
            
            if (!is_dir($reportDir)) {
                jsonResponse(['success' => false, 'error' => 'Report not found'], 404);
            }
            
            // Delete recursively
            $deleteDir = function($dir) use (&$deleteDir) {
                if (!is_dir($dir)) return;
                $files = array_diff(scandir($dir), ['.', '..']);
                foreach ($files as $file) {
                    $path = $dir . '/' . $file;
                    is_dir($path) ? $deleteDir($path) : unlink($path);
                }
                rmdir($dir);
            };
            
            $deleteDir($reportDir);
            
            $auth->auditLog($user['id'], $user['username'], 'report_delete', 'playwright_report', $reportId, 'Deleted report');
            
            jsonResponse(['success' => true]);
            break;

        case 'cleanup-reports':
            // Cleanup old reports based on retention settings (admin only)
            if ($user['role'] !== 'super-admin' && $user['role'] !== 'admin') {
                jsonResponse(['success' => false, 'error' => 'Admin access required'], 403);
            }
            
            require_once __DIR__ . '/../includes/AppManager.php';
            $appManager = new AppManager();
            $settings = $appManager->loadSettings('playwright');
            
            $retentionDays = $settings['storage']['retentionDays'] ?? 30;
            $maxReports = $settings['storage']['maxReports'] ?? 50;

            $goResp = goRequest('POST', '/playwright/reports/cleanup', [
                'retentionDays' => (int)$retentionDays,
                'maxReports' => (int)$maxReports,
            ]);
            if (!empty($goResp['data']['success'])) {
                jsonResponse($goResp['data'], $goResp['status']);
            }

            $cutoff = time() - ($retentionDays * 24 * 60 * 60);
            
            $archiveDir = __DIR__ . '/../playwright-reports-archive';
            $deleted = 0;
            
            if (is_dir($archiveDir)) {
                $dirs = scandir($archiveDir);
                sort($dirs); // Oldest first
                
                $totalReports = count(array_filter($dirs, fn($d) => $d !== '.' && $d !== '..'));
                
                foreach ($dirs as $dir) {
                    if ($dir === '.' || $dir === '..') continue;
                    
                    $dirPath = $archiveDir . '/' . $dir;
                    $mtime = filemtime($dirPath);
                    
                    // Delete if older than retention OR if we have too many reports
                    if ($mtime < $cutoff || ($totalReports - $deleted) > $maxReports) {
                        $deleteDir = function($d) use (&$deleteDir) {
                            if (!is_dir($d)) return;
                            $files = array_diff(scandir($d), ['.', '..']);
                            foreach ($files as $file) {
                                $path = $d . '/' . $file;
                                is_dir($path) ? $deleteDir($path) : unlink($path);
                            }
                            rmdir($d);
                        };
                        
                        $deleteDir($dirPath);
                        $deleted++;
                    }
                }
            }
            
            jsonResponse(['success' => true, 'deleted' => $deleted]);
            break;

        default:
            jsonResponse(['success' => false, 'error' => 'Unknown action'], 400);
    }
} catch (Exception $e) {
    error_log("Playwright API error: " . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Server error: ' . $e->getMessage()], 500);
} catch (Error $e) {
    error_log("Playwright API fatal error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    jsonResponse(['success' => false, 'error' => 'Server error: ' . $e->getMessage()], 500);
}

// Convert timestamp from file format to ISO 8601
// Input: 2026-01-19T16-51-46-320Z
// Output: 2026-01-19T16:51:46.320Z
function convertTimestamp($timestamp) {
    if (empty($timestamp)) return null;
    
    // If it's already in a valid format, return as-is
    if (strpos($timestamp, ':') !== false) {
        return $timestamp;
    }
    
    // Pattern: YYYY-MM-DDTHH-MM-SS-mmmZ
    if (preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2})-(\d{2})-(\d{2})-(\d{3})Z$/', $timestamp, $matches)) {
        return $matches[1] . 'T' . $matches[2] . ':' . $matches[3] . ':' . $matches[4] . '.' . $matches[5] . 'Z';
    }
    
    // Fallback: try to parse and reformat
    $ts = strtotime($timestamp);
    if ($ts !== false) {
        return date('c', $ts);
    }
    
    return $timestamp;
}

// Helper function for report metadata
function getReportMetadata($reportDir) {
    $metadata = [
        'testName' => 'Unnamed Test',
        'project' => null,
        'testFiles' => null,
        'testCount' => null,
        'status' => 'unknown',
        'exitCode' => null,
        'startTime' => null,
        'startTimestamp' => null,
        'endTime' => null,
        'videos' => [],
        'screenshots' => []
    ];
    
    // Read test name
    $testNameFile = $reportDir . '/testname.txt';
    if (file_exists($testNameFile)) {
        $metadata['testName'] = trim(file_get_contents($testNameFile));
    }
    
    // Read project
    $projectFile = $reportDir . '/project.txt';
    if (file_exists($projectFile)) {
        $metadata['project'] = trim(file_get_contents($projectFile));
    }
    
    // Read test files
    $testFilesFile = $reportDir . '/testfiles.txt';
    if (file_exists($testFilesFile)) {
        $metadata['testFiles'] = trim(file_get_contents($testFilesFile));
    }
    
    // Read test count
    $testCountFile = $reportDir . '/testcount.txt';
    if (file_exists($testCountFile)) {
        $count = trim(file_get_contents($testCountFile));
        if ($count !== '' && $count !== '0') {
            $metadata['testCount'] = intval($count);
        }
    }
    
    // Read status (new format: status.txt with running/completed/failed)
    $statusFile = $reportDir . '/status.txt';
    if (file_exists($statusFile)) {
        $status = trim(file_get_contents($statusFile));
        $metadata['status'] = $status;
    }
    
    // Read exit code
    $exitCodeFile = $reportDir . '/exitcode.txt';
    if (file_exists($exitCodeFile)) {
        $exitCode = trim(file_get_contents($exitCodeFile));
        if ($exitCode !== '') {
            $metadata['exitCode'] = intval($exitCode);
            // If status wasn't set by status.txt, derive from exit code
            if ($metadata['status'] === 'unknown') {
                $metadata['status'] = ($metadata['exitCode'] === 0) ? 'passed' : 'failed';
            }
        }
    }
    
    // Read start time - convert from file format (2026-01-19T16-51-46-320Z) to ISO 8601
    $startTimeFile = $reportDir . '/starttime.txt';
    if (file_exists($startTimeFile)) {
        $startTimeRaw = trim(file_get_contents($startTimeFile));
        // Convert 2026-01-19T16-51-46-320Z to 2026-01-19T16:51:46.320Z
        $startTime = convertTimestamp($startTimeRaw);
        $metadata['startTime'] = $startTime;
        $metadata['startTimestamp'] = strtotime($startTime);
    }
    
    // Read end time
    $endTimeFile = $reportDir . '/endtime.txt';
    if (file_exists($endTimeFile)) {
        $endTimeRaw = trim(file_get_contents($endTimeFile));
        $metadata['endTime'] = convertTimestamp($endTimeRaw);
    }
    
    // Collect media from data/ first (Playwright HTML report assets).
    // If data/ has media, use it as the canonical source to avoid double-counting
    // the same files that also exist under test-results/.
    $dataVideos = [];
    $dataScreenshots = [];
    $dataDir = $reportDir . '/data';
    if (is_dir($dataDir)) {
        $files = scandir($dataDir);
        foreach ($files as $file) {
            if (preg_match('/\.webm$/i', $file)) {
                $dataVideos[] = $file;
            } elseif (preg_match('/\.png$/i', $file)) {
                $dataScreenshots[] = $file;
            }
        }
    }

    if (!empty($dataVideos) || !empty($dataScreenshots)) {
        $metadata['videos'] = array_values(array_unique($dataVideos));
        $metadata['screenshots'] = array_values(array_unique($dataScreenshots));
    } else {
        // Fallback for runs without HTML report assets: use test-results/.
        $testResultsDir = $reportDir . '/test-results';
        if (is_dir($testResultsDir)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($testResultsDir));
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $filename = $file->getFilename();
                    $relativePath = str_replace($reportDir . '/', '', $file->getPathname());
                    if (preg_match('/\.webm$/i', $filename)) {
                        $metadata['videos'][] = $relativePath;
                    } elseif (preg_match('/\.png$/i', $filename)) {
                        $metadata['screenshots'][] = $relativePath;
                    }
                }
            }
            $metadata['videos'] = array_values(array_unique($metadata['videos']));
            $metadata['screenshots'] = array_values(array_unique($metadata['screenshots']));
        }
    }
    
    return $metadata;
}
