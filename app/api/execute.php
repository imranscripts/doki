<?php
/**
 * Execution API
 * Run commands and manage jobs
 */

// Enable full error reporting
error_reporting(E_ALL);
ini_set('display_errors', '0'); // Don't display, but log
ini_set('log_errors', '1');

ob_start();
require_once __DIR__ . '/../includes/session-bootstrap.php';
doki_start_session();
header('Content-Type: application/json');

// Custom error handler to log warnings/notices
set_error_handler(function($severity, $message, $file, $line) {
    $severityNames = [
        E_WARNING => 'Warning',
        E_NOTICE => 'Notice', 
        E_USER_WARNING => 'User Warning',
        E_USER_NOTICE => 'User Notice',
        E_STRICT => 'Strict',
        E_DEPRECATED => 'Deprecated',
    ];
    $severityName = $severityNames[$severity] ?? 'Error';
    error_log("PHP {$severityName}: {$message} in {$file}:{$line}");
    
    // Don't execute PHP's internal error handler
    return true;
});

// Error handler for fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        error_log("PHP Fatal Error: " . $error['message'] . " in " . $error['file'] . ":" . $error['line']);
        ob_end_clean();
        http_response_code(500);
        echo json_encode([
            'success' => false, 
            'error' => 'Server error: ' . $error['message'],
            'file' => $error['file'],
            'line' => $error['line']
        ]);
    }
});

require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/PermissionManager.php';
require_once __DIR__ . '/../includes/RuntimeEnvironment.php';

// Initialize
$db = Database::getInstance();
$auth = new Auth($db);

// Check authentication
$token = $_SESSION['auth_token'] ?? null;
if (!$token) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$user = $auth->validateSession($token);
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Session expired']);
    exit;
}

// Get action
$action = $_GET['action'] ?? '';

function jsonResponse($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function logExecuteApi(string $event, array $context = []): void {
    $encoded = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($encoded === false) {
        $encoded = '{"encodingError":"' . addslashes(json_last_error_msg()) . '"}';
    }
    error_log('[execute.php] ' . $event . ' ' . $encoded);
}

function truncateForLog($value, int $max = 600): string {
    if (!is_string($value)) {
        $value = (string)$value;
    }
    $length = strlen($value);
    if ($length <= $max) {
        return $value;
    }
    return substr($value, 0, $max) . '...(truncated ' . ($length - $max) . ' bytes)';
}

function summarizeKeys($value): array {
    if (!is_array($value)) {
        return [];
    }
    return array_values(array_slice(array_map('strval', array_keys($value)), 0, 30));
}

function normalizeJsonMapPayload($value, string $fieldName): array {
    if ($value === null) {
        return ['ok' => true, 'value' => (object)[]];
    }

    if (!is_array($value)) {
        return ['ok' => false, 'error' => $fieldName . ' must be an object'];
    }

    if ($value === []) {
        // json_decode(..., true) turns both {} and [] into [], so preserve map semantics.
        return ['ok' => true, 'value' => (object)[]];
    }

    if (array_is_list($value)) {
        return ['ok' => false, 'error' => $fieldName . ' must be an object'];
    }

    return ['ok' => true, 'value' => $value];
}

function goOrchestratorBaseUrl(): string {
    return RuntimeEnvironment::getOrchestratorBaseUrl();
}

function goOrchestratorTimeout(): int {
    $value = getenv('DOKI_ORCH_TIMEOUT');
    if ($value === false || trim($value) === '') {
        return 330; // default 5.5 minutes
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
    logExecuteApi('go_request.start', [
        'method' => $method,
        'url' => $url,
        'query' => $query,
        'payloadKeys' => summarizeKeys($payload),
        'payloadCommandId' => is_array($payload) ? ($payload['commandId'] ?? null) : null,
        'payloadTemplateId' => is_array($payload) ? ($payload['templateId'] ?? null) : null,
        'hasTargetId' => is_array($payload) ? array_key_exists('targetId', $payload) : false,
    ]);

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
        $encodedPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($encodedPayload === false) {
            logExecuteApi('go_request.payload_encode_failed', [
                'method' => $method,
                'path' => $path,
                'error' => json_last_error_msg(),
                'payloadKeys' => summarizeKeys($payload),
            ]);
            return [
                'status' => 400,
                'data' => [
                    'success' => false,
                    'error' => 'Failed to encode orchestrator request payload: ' . json_last_error_msg(),
                ],
                'raw' => null,
            ];
        }
        $options['http']['content'] = $encodedPayload;
    }

    $context = stream_context_create($options);
    $body = @file_get_contents($url, false, $context);

    $status = 0;
    if (isset($http_response_header[0]) && preg_match('/HTTP\\/(?:\\d\\.\\d)\\s+(\\d+)/', $http_response_header[0], $m)) {
        $status = (int)$m[1];
    }

    if ($body === false) {
        logExecuteApi('go_request.transport_failed', [
            'method' => $method,
            'url' => $url,
            'status' => $status,
            'responseHeader0' => $http_response_header[0] ?? null,
        ]);
        return [
            'status' => 502,
            'data' => ['success' => false, 'error' => 'Go orchestrator unavailable'],
            'raw' => null,
        ];
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        logExecuteApi('go_response.invalid_json', [
            'method' => $method,
            'url' => $url,
            'status' => $status,
            'jsonError' => json_last_error_msg(),
            'rawSnippet' => truncateForLog($body),
        ]);
        $data = ['success' => false, 'error' => 'Invalid response from Go orchestrator'];
    } elseif (($data['success'] ?? false) !== true) {
        logExecuteApi('go_response.error', [
            'method' => $method,
            'url' => $url,
            'status' => $status,
            'error' => $data['error'] ?? null,
            'rawSnippet' => truncateForLog($body),
        ]);
    }

    return [
        'status' => $status ?: 502,
        'data' => $data,
        'raw' => $body,
    ];
}

switch ($action) {
    case 'run':
        // Execute a saved command
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(['success' => false, 'error' => 'POST required'], 405);
        }
        
        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($input)) {
            logExecuteApi('run.invalid_json', [
                'jsonError' => json_last_error_msg(),
                'contentType' => $_SERVER['CONTENT_TYPE'] ?? null,
                'rawSnippet' => truncateForLog((string)$rawInput),
            ]);
            jsonResponse(['success' => false, 'error' => 'Invalid JSON'], 400);
        }
        
        $commandId = $input['commandId'] ?? '';
        $inputs = $input['inputs'] ?? [];
        $targetOverride = $input['targetId'] ?? null;
        $dryRun = !empty($input['dryRun']);

        if (empty($commandId)) {
            jsonResponse(['success' => false, 'error' => 'Command ID required'], 400);
        }

        // Granular permission check
        $permManager = new PermissionManager();
        if (!$permManager->canAccessResource($user, 'command', $commandId)) {
            jsonResponse(['success' => false, 'error' => 'Access denied to this command'], 403);
        }

        $normalizedInputs = normalizeJsonMapPayload($inputs, 'inputs');
        if (!$normalizedInputs['ok']) {
            jsonResponse(['success' => false, 'error' => $normalizedInputs['error']], 400);
        }
        
        try {
            $payload = [
                'commandId' => $commandId,
                'inputs' => $normalizedInputs['value'],
                'user' => [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'role' => $user['role'] ?? null,
                ],
                'request' => [
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                    'userAgent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                ],
                'dryRun' => $dryRun,
            ];
            if (is_string($targetOverride) && trim($targetOverride) !== '') {
                $payload['targetId'] = trim($targetOverride);
            }
            logExecuteApi('run.forward_execute', [
                'commandId' => $commandId,
                'inputKeys' => summarizeKeys($inputs),
                'targetId' => $payload['targetId'] ?? null,
                'dryRun' => $dryRun,
                'userId' => $user['id'] ?? null,
            ]);
            $resp = goRequest('POST', '/execute', $payload);
            jsonResponse($resp['data'], $resp['status']);
        } catch (Exception $e) {
            error_log("Execution error: " . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'Execution failed: ' . $e->getMessage()], 500);
        }
        break;
        
    case 'run-template':
        // Execute a template directly (without a saved command)
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(['success' => false, 'error' => 'POST required'], 405);
        }
        
        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($input)) {
            logExecuteApi('run_template.invalid_json', [
                'jsonError' => json_last_error_msg(),
                'contentType' => $_SERVER['CONTENT_TYPE'] ?? null,
                'rawSnippet' => truncateForLog((string)$rawInput),
            ]);
            jsonResponse(['success' => false, 'error' => 'Invalid JSON'], 400);
        }
        
        $templateId = $input['templateId'] ?? '';
        $inputs = $input['inputs'] ?? [];
        $targetId = $input['targetId'] ?? null;
        $dryRun = !empty($input['dryRun']);
        
        if (empty($templateId)) {
            jsonResponse(['success' => false, 'error' => 'Template ID required'], 400);
        }

        $normalizedInputs = normalizeJsonMapPayload($inputs, 'inputs');
        if (!$normalizedInputs['ok']) {
            jsonResponse(['success' => false, 'error' => $normalizedInputs['error']], 400);
        }
        
        try {
            $payload = [
                'templateId' => $templateId,
                'inputs' => $normalizedInputs['value'],
                'user' => [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'role' => $user['role'] ?? null,
                ],
                'request' => [
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                    'userAgent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                ],
                'dryRun' => $dryRun,
            ];
            if (is_string($targetId) && trim($targetId) !== '') {
                $payload['targetId'] = trim($targetId);
            }
            logExecuteApi('run_template.forward_execute', [
                'templateId' => $templateId,
                'inputKeys' => summarizeKeys($inputs),
                'targetId' => $payload['targetId'] ?? null,
                'dryRun' => $dryRun,
                'userId' => $user['id'] ?? null,
            ]);
            $resp = goRequest('POST', '/execute', $payload);
            jsonResponse($resp['data'], $resp['status']);
        } catch (Exception $e) {
            error_log("Execution error: " . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'Execution failed: ' . $e->getMessage()], 500);
        }
        break;
        
    case 'job':
        // Get job details
        $jobId = $_GET['id'] ?? '';
        if (empty($jobId)) {
            jsonResponse(['success' => false, 'error' => 'Job ID required'], 400);
        }

        $resp = goRequest('GET', '/job/' . urlencode($jobId));
        jsonResponse($resp['data'], $resp['status']);
        break;
        
    case 'jobs':
        // List recent jobs
        $limit = min((int)($_GET['limit'] ?? 50), 100);
        $allUsers = isset($_GET['all']) && in_array($user['role'], ['super-admin', 'admin']);

        $query = ['limit' => $limit];
        if (!$allUsers) {
            $query['userId'] = $user['id'];
        }
        $resp = goRequest('GET', '/jobs', null, $query);
        jsonResponse($resp['data'], $resp['status']);
        break;
        
    case 'cancel':
        // Cancel a running job
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(['success' => false, 'error' => 'POST required'], 405);
        }
        
        $jobId = $_GET['id'] ?? '';
        if (empty($jobId)) {
            jsonResponse(['success' => false, 'error' => 'Job ID required'], 400);
        }
        
        $payload = [
            'user' => [
                'id' => $user['id'],
                'username' => $user['username'],
                'role' => $user['role'] ?? null,
            ],
        ];
        $resp = goRequest('POST', '/job/' . urlencode($jobId) . '/cancel', $payload);
        jsonResponse($resp['data'], $resp['status']);
        break;
        
    default:
        jsonResponse(['success' => false, 'error' => 'Unknown action'], 400);
}
