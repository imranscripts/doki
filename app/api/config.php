<?php
/**
 * api/config.php - Configuration API Endpoints
 * 
 * Part of Doki v3 Architecture - Batch 2: Infrastructure Config
 */

// Ensure errors go to log, not output (for clean JSON responses)
ini_set('display_errors', '0');
error_reporting(E_ALL);
ini_set('log_errors', '1');

require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/PermissionManager.php';
require_once __DIR__ . '/../includes/ConfigManager.php';
require_once __DIR__ . '/../includes/session-bootstrap.php';

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
doki_start_session();
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Content-Type: application/json');

$auth = new Auth();
$config = new ConfigManager();
$method = $_SERVER['REQUEST_METHOD'];

// Get request body for POST/PUT
$input = [];
if (in_array($method, ['POST', 'PUT'])) {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true) ?? [];
}

// Get action and type from query string
$action = $_GET['action'] ?? '';
$type = $_GET['type'] ?? '';

// Helper functions
function jsonResponse(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function requireAuth(): array {
    global $auth;
    
    $token = $_SESSION['auth_token'] ?? null;
    if (!$token) {
        jsonResponse(['success' => false, 'error' => 'Not authenticated'], 401);
    }
    
    $user = $auth->validateSession($token);
    if (!$user) {
        jsonResponse(['success' => false, 'error' => 'Session expired'], 401);
    }
    
    return $user;
}

function requireAdmin(): array {
    global $auth;
    $user = requireAuth();

    if (!$auth->hasRole('admin')) {
        jsonResponse(['success' => false, 'error' => 'Admin access required'], 403);
    }

    return $user;
}

function requireModulePermission(string $moduleSlug): void {
    global $auth;
    $user = $auth->getCurrentUser();
    if (!$user) return;
    $permManager = new PermissionManager();
    if (!$permManager->canAccessResource($user, 'module', $moduleSlug)) {
        jsonResponse(['success' => false, 'error' => 'Access denied'], 403);
    }
}

function requireSecretsUnlocked(): void {
    global $config;
    $status = $config->getSecretsStatus();
    if (!empty($status['locked'])) {
        jsonResponse(['success' => false, 'locked' => true, 'error' => $status['reason'] ?? 'Secrets are locked'], 423);
    }
}

// Route actions
switch ($action) {
    // =====================
    // TARGETS
    // =====================
    case 'targets':
        if ($method === 'GET') {
            requireAdmin();
            requireModulePermission('targets');
            $targets = $config->getTargets();
            jsonResponse(['success' => true, 'targets' => $targets]);
        }
        break;

    case 'target':
        $id = $_GET['id'] ?? '';

        if ($method === 'GET') {
            requireAdmin();
            requireModulePermission('targets');
            try {
                $target = $config->getTarget($id);
                if ($target) {
                    jsonResponse(['success' => true, 'target' => $target]);
                } else {
                    jsonResponse(['success' => false, 'error' => 'Target not found'], 404);
                }
            } catch (Exception $e) {
                jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
            }
        } elseif ($method === 'POST' || $method === 'PUT') {
            $user = requireAdmin();
            try {
                // Validate input
                if (empty($input['id']) || empty($input['name']) || empty($input['type'])) {
                    jsonResponse(['success' => false, 'error' => 'Missing required fields: id, name, type. Received: ' . json_encode($input)], 400);
                }
                
                // Debug: log what we're saving
                error_log('Saving target: ' . json_encode($input));
                
                $result = $config->saveTarget($input, $user['id'], $user['username']);
                
                // Debug: log result
                error_log('Save result: ' . json_encode($result));
                
                jsonResponse($result, $result['success'] ? 200 : 400);
            } catch (Exception $e) {
                error_log('Exception saving target: ' . $e->getMessage());
                jsonResponse(['success' => false, 'error' => 'Server error: ' . $e->getMessage()], 500);
            }
        } elseif ($method === 'DELETE') {
            $user = requireAdmin();
            try {
                $result = $config->deleteTarget($id, $user['id'], $user['username']);
                jsonResponse($result, $result['success'] ? 200 : 400);
            } catch (Exception $e) {
                jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
            }
        }
        break;

    case 'verify-target':
        if ($method !== 'POST') {
            jsonResponse(['success' => false, 'error' => 'POST required'], 405);
        }

        $user = requireAdmin();
        $id = $input['id'] ?? ($_GET['id'] ?? '');
        if (empty($id)) {
            jsonResponse(['success' => false, 'error' => 'Target ID required'], 400);
        }

        $debug = !empty($input['debug']);
        $result = $config->verifyTarget($id, $user['id'], $user['username'], $debug);
        jsonResponse($result, $result['success'] ? 200 : 400);
        break;

    case 'setup-target-capability':
        if ($method !== 'POST') {
            jsonResponse(['success' => false, 'error' => 'POST required'], 405);
        }

        $user = requireAdmin();
        $id = $input['id'] ?? ($_GET['id'] ?? '');
        $capability = $input['capability'] ?? '';
        if (empty($id) || empty($capability)) {
            jsonResponse(['success' => false, 'error' => 'Target ID and capability are required'], 400);
        }

        $result = $config->setupTargetCapability($id, $capability, $user['id'], $user['username']);
        jsonResponse($result, $result['success'] ? 200 : 400);
        break;

    // =====================
    // ENVIRONMENTS
    // =====================
    case 'environments':
        if ($method === 'GET') {
            requireAdmin();
            requireModulePermission('environments');
            $environments = $config->getEnvironments();
            jsonResponse(['success' => true, 'environments' => $environments]);
        }
        break;

    case 'environment':
        $id = $_GET['id'] ?? '';

        if ($method === 'GET') {
            requireAdmin();
            requireModulePermission('environments');
            $environment = $config->getEnvironment($id);
            if ($environment) {
                jsonResponse(['success' => true, 'environment' => $environment, 'id' => $id]);
            } else {
                jsonResponse(['success' => false, 'error' => 'Environment not found'], 404);
            }
        } elseif ($method === 'POST' || $method === 'PUT') {
            $user = requireAdmin();
            $envId = $input['id'] ?? $id;
            unset($input['id']); // Remove id from data, it's the key
            $result = $config->saveEnvironment($envId, $input, $user['id'], $user['username']);
            jsonResponse($result, $result['success'] ? 200 : 400);
        } elseif ($method === 'DELETE') {
            $user = requireAdmin();
            $result = $config->deleteEnvironment($id, $user['id'], $user['username']);
            jsonResponse($result, $result['success'] ? 200 : 400);
        }
        break;

    // =====================
    // SECRETS
    // =====================
    case 'secrets':
        if ($method === 'GET') {
            requireAdmin();
            requireModulePermission('secrets');
            requireSecretsUnlocked();
            try {
                $secrets = $config->getSecretKeys();
                jsonResponse(['success' => true, 'secrets' => $secrets, 'locked' => false]);
            } catch (Exception $e) {
                jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
            }
        }
        break;

    case 'secret':
        $key = $_GET['key'] ?? '';
        
        if ($method === 'GET') {
            // Getting a secret value requires re-authentication
            $user = requireAdmin();
            requireSecretsUnlocked();
            
            // Require password re-entry for viewing secrets
            $password = $_GET['password'] ?? '';
            if (empty($password)) {
                jsonResponse(['success' => false, 'error' => 'Password required to view secret', 'requirePassword' => true], 403);
            }
            
            // Verify password
            require_once __DIR__ . '/../includes/Database.php';
            $db = Database::getInstance();
            $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = ?");
            $stmt->execute([$user['id']]);
            $userData = $stmt->fetch();
            
            if (!$userData || !password_verify($password, $userData['password_hash'])) {
                jsonResponse(['success' => false, 'error' => 'Invalid password'], 403);
            }
            
            // Log secret access
            $auth->auditLog($user['id'], $user['username'], 'secret_view', 'secret', $key, "Viewed secret: {$key}");
            
            $value = $config->getSecret($key);
            if ($value !== null) {
                jsonResponse(['success' => true, 'key' => $key, 'value' => $value]);
            } else {
                jsonResponse(['success' => false, 'error' => 'Secret not found'], 404);
            }
        } elseif ($method === 'POST' || $method === 'PUT') {
            $user = requireAdmin();
            requireSecretsUnlocked();
            $secretKey = $input['key'] ?? $key;
            $secretValue = $input['value'] ?? '';
            $meta = [
                'expiresAt' => $input['expiresAt'] ?? null,
                'rotationDays' => $input['rotationDays'] ?? null
            ];
            
            if (empty($secretKey)) {
                jsonResponse(['success' => false, 'error' => 'Secret key is required'], 400);
            }
            
            $result = $config->saveSecret($secretKey, $secretValue, $user['id'], $user['username'], $meta);
            jsonResponse($result, $result['success'] ? 200 : 400);
        } elseif ($method === 'DELETE') {
            $user = requireAdmin();
            requireSecretsUnlocked();
            $result = $config->deleteSecret($key, $user['id'], $user['username']);
            jsonResponse($result, $result['success'] ? 200 : 400);
        }
        break;

    // =====================
    // RAW YAML EDITING
    // =====================
    case 'raw':
        if (!in_array($type, ['targets', 'environments', 'secrets'])) {
            jsonResponse(['success' => false, 'error' => 'Invalid config type'], 400);
        }
        
        if ($method === 'GET') {
            requireAdmin();
            if ($type === 'secrets') {
                requireSecretsUnlocked();
            }
            $content = $config->loadRaw($type);
            jsonResponse(['success' => true, 'content' => $content, 'type' => $type]);
        } elseif ($method === 'POST') {
            $user = requireAdmin();
            if ($type === 'secrets') {
                requireSecretsUnlocked();
            }
            $content = $input['content'] ?? '';
            $result = $config->saveRaw($type, $content, $user['id'], $user['username']);
            jsonResponse($result, $result['success'] ? 200 : 400);
        }
        break;

    // =====================
    // BACKUPS
    // =====================
    case 'backups':
        if (!in_array($type, ['targets', 'environments', 'secrets'])) {
            jsonResponse(['success' => false, 'error' => 'Invalid config type'], 400);
        }
        
        if ($method === 'GET') {
            requireAdmin();
            $backups = $config->getBackups($type);
            jsonResponse(['success' => true, 'backups' => $backups, 'type' => $type]);
        }
        break;

    case 'restore':
        if (!in_array($type, ['targets', 'environments', 'secrets'])) {
            jsonResponse(['success' => false, 'error' => 'Invalid config type'], 400);
        }
        
        if ($method === 'POST') {
            $user = requireAdmin();
            $filename = $input['filename'] ?? '';
            
            if (empty($filename)) {
                jsonResponse(['success' => false, 'error' => 'Backup filename required'], 400);
            }
            
            $result = $config->restoreBackup($type, $filename, $user['id'], $user['username']);
            jsonResponse($result, $result['success'] ? 200 : 400);
        }
        break;

    // =====================
    // VALIDATION
    // =====================
    case 'validate':
        if (!in_array($type, ['targets', 'environments', 'secrets'])) {
            jsonResponse(['success' => false, 'error' => 'Invalid config type'], 400);
        }
        
        if ($method === 'POST') {
            requireAdmin();
            $content = $input['content'] ?? '';
            
            // Parse YAML
            $data = @yaml_parse($content);
            if ($data === false) {
                jsonResponse(['success' => true, 'valid' => false, 'errors' => ['Invalid YAML syntax']]);
            }
            
            // Validate structure
            $result = $config->validate($type, $data);
            jsonResponse(['success' => true, 'valid' => $result['valid'], 'errors' => $result['errors']]);
        }
        break;

    // =====================
    // CONNECTION TESTING
    // =====================
    case 'test-connection':
        if ($method === 'POST') {
            $user = requireAdmin();
            
            $targetId = $input['targetId'] ?? null;
            if (!$targetId) {
                jsonResponse(['success' => false, 'error' => 'Target ID required'], 400);
            }
            
            // Buffer output to prevent debug logs from corrupting JSON
            ob_start();
            
            try {
                require_once __DIR__ . '/../includes/Orchestrator.php';
                $orchestrator = new Orchestrator();
                $result = $orchestrator->testConnection($targetId);
                
                // Log the test attempt
                $auth->auditLog($user['id'], $user['username'], 'test_connection', 'target', $targetId, 
                    json_encode(['success' => $result['success'], 'message' => $result['message'] ?? $result['error'] ?? '']));
                
                ob_end_clean();
                jsonResponse($result);
            } catch (Exception $e) {
                ob_end_clean();
                error_log("Test connection error: " . $e->getMessage());
                jsonResponse(['success' => false, 'error' => 'Connection test failed: ' . $e->getMessage()], 500);
            }
        }
        break;

    // =====================
    // ENVIRONMENTS LIST (for dropdowns)
    // =====================
    case 'environments-list':
        if ($method === 'GET') {
            requireAuth();
            try {
                $envs = $config->getEnvironments();
                $list = [];
                // Environments use the key as ID
                foreach ($envs as $id => $env) {
                    $list[] = [
                        'id' => $id,
                        'name' => $env['name'] ?? $id,
                        'color' => $env['color'] ?? '#6b7280'
                    ];
                }
                jsonResponse(['success' => true, 'environments' => $list]);
            } catch (Exception $e) {
                jsonResponse(['success' => false, 'error' => $e->getMessage(), 'environments' => []]);
            }
        }
        break;

    // =====================
    // HISTORY & BACKUPS
    // =====================
    case 'backup-content':
        requireAdmin();
        $type = $_GET['type'] ?? '';
        $filename = $_GET['filename'] ?? '';
        
        if (!$type || !$filename) {
            jsonResponse(['success' => false, 'error' => 'Missing type or filename']);
        }
        
        // Security: only allow known config types
        $allowedTypes = ['targets', 'environments', 'secrets', 'commands'];
        if (!in_array($type, $allowedTypes)) {
            jsonResponse(['success' => false, 'error' => 'Invalid config type']);
        }
        
        // Security: sanitize filename
        $filename = basename($filename);
        // Filename format: {type}.{YYYY-MM-DD_HH-MM-SS}.yaml
        if (!preg_match('/^' . preg_quote($type, '/') . '\.\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.yaml$/', $filename)) {
            jsonResponse(['success' => false, 'error' => 'Invalid backup filename: ' . $filename]);
        }
        
        $historyDir = __DIR__ . '/../data/config/.history';
        $backupPath = $historyDir . '/' . $filename;
        $currentPath = __DIR__ . '/../data/config/' . $type . '.yaml';
        
        if (!file_exists($backupPath)) {
            jsonResponse(['success' => false, 'error' => 'Backup not found']);
        }
        
        $backupContent = file_get_contents($backupPath);
        $currentContent = file_exists($currentPath) ? file_get_contents($currentPath) : '';
        $backupDate = date('Y-m-d H:i:s', filemtime($backupPath));
        
        // Log view access for secrets
        if ($type === 'secrets') {
            $auth->auditLog($user['id'], $user['username'], 'secret_view', 'backup', $filename, 'Viewed secrets backup');
        }
        
        jsonResponse([
            'success' => true,
            'backupContent' => $backupContent,
            'currentContent' => $currentContent,
            'backupDate' => $backupDate
        ]);
        break;
        
    case 'backups-list':
        requireAdmin();
        $type = $_GET['type'] ?? '';
        
        $allowedTypes = ['targets', 'environments', 'secrets', 'commands'];
        if (!in_array($type, $allowedTypes)) {
            jsonResponse(['success' => false, 'error' => 'Invalid config type']);
        }
        
        $backups = $config->getBackups($type);
        jsonResponse(['success' => true, 'backups' => $backups]);
        break;
        
    case 'restore-backup':
        requireRole('super-admin');
        $type = $_POST['type'] ?? '';
        $filename = $_POST['filename'] ?? '';
        
        $allowedTypes = ['targets', 'environments', 'secrets', 'commands'];
        if (!in_array($type, $allowedTypes)) {
            jsonResponse(['success' => false, 'error' => 'Invalid config type']);
        }
        
        $result = $config->restoreBackup($type, $filename, $user['id'], $user['username']);
        
        if ($result['success']) {
            $auth->auditLog($user['id'], $user['username'], 'config_restore', $type, $filename, "Restored {$type} from backup");
        }
        
        jsonResponse($result);
        break;

    default:
        jsonResponse(['success' => false, 'error' => 'Unknown action'], 400);
}
