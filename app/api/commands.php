<?php
/**
 * Commands API
 * CRUD endpoints for user-defined commands
 */

// Buffer output to prevent any stray content before JSON
ob_start();

require_once __DIR__ . '/../includes/session-bootstrap.php';
doki_start_session();
header('Content-Type: application/json');

// Clean any buffered output on exit
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        ob_end_clean();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Server error: ' . $error['message']]);
    }
});

require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/PermissionManager.php';
require_once __DIR__ . '/../includes/CommandsManager.php';
require_once __DIR__ . '/../includes/TemplateManager.php';
require_once __DIR__ . '/../includes/ConfigManager.php';
require_once __DIR__ . '/../includes/RuntimeEnvironment.php';

// Initialize
$db = Database::getInstance();
$auth = new Auth($db);
$commands = new CommandsManager($auth);
$templates = new TemplateManager();
$config = new ConfigManager($auth);

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

// Admin check for write operations
function requireAdmin($user) {
    if (!in_array($user['role'], ['super-admin', 'admin'])) {
        jsonResponse(['success' => false, 'error' => 'Admin access required'], 403);
    }
}

// Invalidate Go orchestrator config cache so it picks up changes immediately
function invalidateOrchestratorCache(): void {
    $url = RuntimeEnvironment::getOrchestratorBaseUrl() . '/config/reload';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 3,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => '{}',
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function extractTargetRefTargetId($ref): ?string {
    if (is_string($ref)) {
        $trimmed = trim($ref);
        if ($trimmed === '' || strtolower($trimmed) === 'inherit') {
            return null;
        }
        return $trimmed;
    }
    if (!is_array($ref)) {
        return null;
    }
    $targetId = trim((string)($ref['targetId'] ?? ''));
    return $targetId !== '' ? $targetId : null;
}

switch ($action) {
    case 'list':
        // Get all commands, filtered by granular permissions
        $result = $commands->getCommands();
        if (!empty($result['commands']) && $user['role'] !== 'super-admin') {
            $permManager = new PermissionManager();
            $commandIds = array_column($result['commands'], 'id');
            $allowed = $permManager->filterAccessibleResources($user, 'command', $commandIds);
            $allowedMap = array_flip($allowed);
            $result['commands'] = array_values(array_filter($result['commands'], function($c) use ($allowedMap) {
                return isset($allowedMap[$c['id']]);
            }));
        }
        jsonResponse($result);
        break;
        
    case 'get':
        // Get a single command
        $id = $_GET['id'] ?? '';
        if (empty($id)) {
            jsonResponse(['success' => false, 'error' => 'Command ID required'], 400);
        }

        // Granular permission check
        $permManager = new PermissionManager();
        if (!$permManager->canAccessResource($user, 'command', $id)) {
            jsonResponse(['success' => false, 'error' => 'Access denied'], 403);
        }

        $command = $commands->getCommand($id);
        if (!$command) {
            jsonResponse(['success' => false, 'error' => 'Command not found'], 404);
        }

        jsonResponse(['success' => true, 'command' => $command]);
        break;
        
    case 'save':
        // Create or update a command
        requireAdmin($user);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(['success' => false, 'error' => 'POST required'], 405);
        }

        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($input)) {
            jsonResponse(['success' => false, 'error' => 'Invalid JSON'], 400);
        }
        
        if (!is_array($input['steps'] ?? null) || empty($input['steps'])) {
            jsonResponse(['success' => false, 'errors' => ['steps must contain at least one step']], 400);
        }

        $templateErrors = [];
        foreach ($input['steps'] as $index => $step) {
            if (!is_array($step)) {
                $templateErrors[] = 'Step #' . ($index + 1) . ' must be an object';
                continue;
            }
            $templateId = trim((string)($step['templateId'] ?? ''));
            if ($templateId === '') {
                $templateErrors[] = 'Step #' . ($index + 1) . ' templateId is required';
                continue;
            }
            $template = $templates->getTemplate($templateId);
            if (!$template) {
                $templateErrors[] = 'Template not found: ' . $templateId;
            }
        }
        if (!empty($templateErrors)) {
            jsonResponse(['success' => false, 'errors' => $templateErrors], 400);
        }

        $targetsById = [];
        foreach ($config->getTargets() as $target) {
            $id = (string)($target['id'] ?? '');
            if ($id !== '') {
                $targetsById[$id] = true;
            }
        }

        $targetErrors = [];
        $defaultTargetId = extractTargetRefTargetId($input['defaultTarget'] ?? 'inherit');
        if ($defaultTargetId !== null && !isset($targetsById[$defaultTargetId])) {
            $targetErrors[] = 'Target not found: ' . $defaultTargetId;
        }
        foreach ($input['steps'] as $index => $step) {
            if (!is_array($step)) {
                continue;
            }
            $stepTargetId = extractTargetRefTargetId($step['target'] ?? 'inherit');
            if ($stepTargetId !== null && !isset($targetsById[$stepTargetId])) {
                $targetErrors[] = 'Step #' . ($index + 1) . ' target not found: ' . $stepTargetId;
            }
        }
        if (!empty($targetErrors)) {
            jsonResponse(['success' => false, 'errors' => $targetErrors], 400);
        }

        if (!empty($input['environment'])) {
            $environments = $config->getEnvironments();
            if (!isset($environments[$input['environment']])) {
                jsonResponse(['success' => false, 'errors' => ['Environment not found: ' . $input['environment']]], 400);
            }
        }
        
        try {
            error_log("API: Saving command with secrets: " . json_encode($input['secrets'] ?? 'NOT SET'));
            $result = $commands->saveCommand($input, $user['id'], $user['username']);
            if ($result['success']) { invalidateOrchestratorCache(); }
            jsonResponse($result, $result['success'] ? 200 : 400);
        } catch (Exception $e) {
            error_log("Error saving command: " . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'Server error: ' . $e->getMessage()], 500);
        }
        break;
        
    case 'delete':
        // Delete a command
        requireAdmin($user);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'DELETE') {
            jsonResponse(['success' => false, 'error' => 'POST or DELETE required'], 405);
        }

        $id = $_GET['id'] ?? '';
        if (empty($id)) {
            jsonResponse(['success' => false, 'error' => 'Command ID required'], 400);
        }

        // Granular permission check
        $permManager = $permManager ?? new PermissionManager();
        if (!$permManager->canAccessResource($user, 'command', $id)) {
            jsonResponse(['success' => false, 'error' => 'Access denied'], 403);
        }
        
        try {
            $result = $commands->deleteCommand($id, $user['id'], $user['username']);
            if ($result['success']) { invalidateOrchestratorCache(); }
            jsonResponse($result, $result['success'] ? 200 : 400);
        } catch (Exception $e) {
            error_log("Error deleting command: " . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'Server error: ' . $e->getMessage()], 500);
        }
        break;
        
    case 'by-tag':
        // Get commands grouped by tag
        $byTag = $commands->getCommandsByTag();
        jsonResponse(['success' => true, 'commandsByTag' => $byTag]);
        break;
        
    case 'backups':
        // Get backup history
        requireAdmin($user);
        $backups = $commands->getBackups();
        jsonResponse(['success' => true, 'backups' => $backups]);
        break;
        
    case 'restore':
        // Restore from backup
        requireAdmin($user);
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(['success' => false, 'error' => 'POST required'], 405);
        }
        
        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($input)) {
            jsonResponse(['success' => false, 'error' => 'Invalid JSON'], 400);
        }
        $filename = $input['filename'] ?? '';
        
        if (empty($filename)) {
            jsonResponse(['success' => false, 'error' => 'Backup filename required'], 400);
        }
        
        try {
            $result = $commands->restoreBackup($filename, $user['id'], $user['username']);
            if ($result['success']) { invalidateOrchestratorCache(); }
            jsonResponse($result, $result['success'] ? 200 : 400);
        } catch (Exception $e) {
            error_log("Error restoring backup: " . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'Server error: ' . $e->getMessage()], 500);
        }
        break;
        
    case 'preview':
        // Preview what a command would do (without executing)
        $id = $_GET['id'] ?? '';
        
        if (empty($id)) {
            // If no ID, get from POST body for unsaved commands
            $rawInput = file_get_contents('php://input');
            $input = json_decode($rawInput, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($input)) {
                jsonResponse(['success' => false, 'error' => 'Invalid JSON'], 400);
            }
            $command = $input;
        } else {
            $command = $commands->getCommand($id);
        }
        
        if (!$command) {
            jsonResponse(['success' => false, 'error' => 'Command not found'], 404);
        }
        
        $steps = is_array($command['steps'] ?? null) ? $command['steps'] : [];
        if (empty($steps)) {
            jsonResponse(['success' => false, 'error' => 'Command has no steps'], 400);
        }

        $previewSteps = [];
        foreach ($steps as $index => $step) {
            if (!is_array($step)) {
                continue;
            }
            $templateId = trim((string)($step['templateId'] ?? ''));
            $template = $templateId !== '' ? $templates->getTemplate($templateId) : null;
            $previewSteps[] = [
                'id' => $step['id'] ?? ('step-' . ($index + 1)),
                'index' => $index,
                'templateId' => $templateId,
                'templateName' => $template['name'] ?? $templateId,
                'onFailure' => $step['onFailure'] ?? 'stop',
                'dependsOn' => $step['dependsOn'] ?? [],
                'target' => $step['target'] ?? 'inherit',
                'inputs' => $step['inputs'] ?? [],
            ];
        }

        $preview = [
            'command' => [
                'id' => $command['id'] ?? 'new',
                'name' => $command['name'] ?? 'New Command',
                'executionMode' => $command['executionMode'] ?? 'async',
                'maxParallel' => $command['maxParallel'] ?? 1,
                'finalStatusPolicy' => $command['finalStatusPolicy'] ?? 'fail_if_any_failed',
            ],
            'steps' => $previewSteps,
            'inputs' => $command['inputs'] ?? [],
        ];

        jsonResponse(['success' => true, 'preview' => $preview]);
        break;
        
    default:
        jsonResponse(['success' => false, 'error' => 'Unknown action'], 400);
}
