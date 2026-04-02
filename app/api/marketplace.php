<?php
/**
 * Marketplace API - Manage app sources, installation, and trust levels
 */

ob_start();
require_once __DIR__ . '/../includes/session-bootstrap.php';
doki_start_session();
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/AppManager.php';
require_once __DIR__ . '/../includes/AppCapabilities.php';
require_once __DIR__ . '/../includes/ConfigManager.php';

// Initialize
$db = Database::getInstance();
$auth = new Auth();
$appManager = new AppManager();

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

$action = $_GET['action'] ?? '';

function jsonResponse($data, $code = 200) {
    ob_end_clean();
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function requireAdmin() {
    global $user;
    if (!in_array($user['role'], ['super-admin', 'admin'])) {
        jsonResponse(['success' => false, 'error' => 'Admin access required'], 403);
    }
}

function requireSuperAdmin() {
    global $user;
    if ($user['role'] !== 'super-admin') {
        jsonResponse(['success' => false, 'error' => 'Super-admin access required'], 403);
    }
}

switch ($action) {
    // =====================
    // INSTALLED APPS
    // =====================
    
    case 'installed-apps':
        // Get all installed apps with trust info
        requireAdmin();
        
        $apps = $appManager->getApps();
        $result = [];
        
        foreach ($apps as $app) {
            $appWithTrust = $appManager->getAppWithTrust($app['id']);
            if ($appWithTrust) {
                $result[] = $appWithTrust;
            }
        }
        
        jsonResponse(['success' => true, 'apps' => $result]);
        break;
        
    case 'app-info':
        // Get detailed info for a single app
        requireAdmin();
        
        $appId = $_GET['id'] ?? '';
        if (!$appId) {
            jsonResponse(['success' => false, 'error' => 'App ID required'], 400);
        }
        
        $app = $appManager->getAppWithTrust($appId);
        if (!$app) {
            jsonResponse(['success' => false, 'error' => 'App not found'], 404);
        }
        
        jsonResponse(['success' => true, 'app' => $app]);
        break;
    
    // =====================
    // TRUST LEVELS
    // =====================
    
    case 'trust-levels':
        // Get all trust level definitions
        jsonResponse([
            'success' => true,
            'levels' => AppCapabilities::getAllTrustLevels()
        ]);
        break;
        
    case 'set-trust':
        // Change an app's trust level (super-admin only)
        requireSuperAdmin();
        
        $input = json_decode(file_get_contents('php://input'), true);
        $appId = $input['appId'] ?? '';
        $trustLevel = $input['trustLevel'] ?? null;
        $reason = $input['reason'] ?? '';
        
        if (!$appId || $trustLevel === null) {
            jsonResponse(['success' => false, 'error' => 'App ID and trust level required'], 400);
        }
        
        $result = $appManager->setTrustLevel($appId, (int)$trustLevel, $user['id'], $user['username'], $reason);
        
        if ($result['success']) {
            $auth->auditLog(
                $user['id'],
                $user['username'],
                'app.trust_change',
                'app',
                $appId,
                "Changed trust from {$result['oldLevel']} to {$result['newLevel']}: {$reason}"
            );
        }
        
        jsonResponse($result);
        break;
        
    case 'capabilities':
        // Get capabilities for a trust level
        $level = (int)($_GET['level'] ?? AppCapabilities::TRUST_SANDBOXED);
        $capabilities = AppCapabilities::forTrustLevel($level);
        
        $detailed = [];
        foreach ($capabilities as $cap) {
            if ($cap === '*') {
                $detailed[] = ['capability' => '*', 'name' => 'All capabilities', 'category' => 'Full Access', 'risk' => 'full'];
            } else {
                $info = AppCapabilities::getCapabilityInfo($cap);
                $info['capability'] = $cap;
                $detailed[] = $info;
            }
        }
        
        jsonResponse(['success' => true, 'capabilities' => $detailed]);
        break;
    
    // =====================
    // APP SOURCES
    // =====================
    
    case 'sources':
        // Get configured app sources
        requireAdmin();
        
        $sources = $appManager->getSources();
        jsonResponse(['success' => true, 'sources' => $sources]);
        break;
        
    case 'add-source':
        // Add a new app source
        requireSuperAdmin();
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $result = $appManager->addSource($input, $user['id'], $user['username']);
        
        if ($result['success']) {
            $auth->auditLog(
                $user['id'],
                $user['username'],
                'app.source_add',
                'app_source',
                $input['id'] ?? 'unknown',
                "Added app source: {$input['url']}"
            );
        }
        
        jsonResponse($result);
        break;
        
    case 'remove-source':
        // Remove an app source
        requireSuperAdmin();
        
        $sourceId = $_GET['id'] ?? '';
        if (!$sourceId) {
            jsonResponse(['success' => false, 'error' => 'Source ID required'], 400);
        }
        
        $result = $appManager->removeSource($sourceId);
        
        if ($result['success']) {
            $auth->auditLog(
                $user['id'],
                $user['username'],
                'app.source_remove',
                'app_source',
                $sourceId,
                "Removed app source"
            );
        }
        
        jsonResponse($result);
        break;
        
    case 'sync-source':
        // Sync apps from a source
        requireAdmin();
        
        $sourceId = $_GET['id'] ?? '';
        if (!$sourceId) {
            jsonResponse(['success' => false, 'error' => 'Source ID required'], 400);
        }
        
        $result = $appManager->syncSource($sourceId);
        
        if ($result['success']) {
            $auth->auditLog(
                $user['id'],
                $user['username'],
                'app.source_sync',
                'app_source',
                $sourceId,
                "Synced source, found " . count($result['apps'] ?? []) . " apps"
            );
        }
        
        jsonResponse($result);
        break;
    
    // =====================
    // AVAILABLE APPS
    // =====================
    
    case 'available-apps':
        // Get apps available from sources
        requireAdmin();
        
        $apps = $appManager->getAvailableApps();
        jsonResponse(['success' => true, 'apps' => $apps]);
        break;
    
    // =====================
    // INSTALLATION
    // =====================
    
    case 'install':
        // Install an app from a source
        requireAdmin();
        
        $input = json_decode(file_get_contents('php://input'), true);
        $sourceId = $input['sourceId'] ?? '';
        $appId = $input['appId'] ?? '';
        $trustLevel = $input['trustLevel'] ?? AppCapabilities::TRUST_SANDBOXED;
        
        if (!$sourceId || !$appId) {
            jsonResponse(['success' => false, 'error' => 'Source ID and App ID required'], 400);
        }
        
        // Only super-admin can install with elevated trust
        if ($trustLevel < AppCapabilities::TRUST_SANDBOXED && $user['role'] !== 'super-admin') {
            $trustLevel = AppCapabilities::TRUST_SANDBOXED;
        }
        
        $result = $appManager->installApp($sourceId, $appId, $user['id'], $user['username'], $trustLevel);
        
        if ($result['success']) {
            $trustInfo = AppCapabilities::getTrustLevelInfo($result['trustLevel']);
            $auth->auditLog(
                $user['id'],
                $user['username'],
                'app.install',
                'app',
                $appId,
                "Installed from {$sourceId} with trust level: {$trustInfo['name']}"
            );
        }
        
        jsonResponse($result);
        break;
        
    case 'update':
        // Update an installed app
        requireAdmin();
        
        $appId = $_GET['id'] ?? '';
        if (!$appId) {
            jsonResponse(['success' => false, 'error' => 'App ID required'], 400);
        }
        
        $result = $appManager->updateApp($appId, $user['id'], $user['username']);
        
        if ($result['success']) {
            $details = [];
            $version = $result['version']['current'] ?? null;
            if ($version) {
                $details[] = 'version ' . $version;
            }
            if (!empty($result['runtime']['rebuildRecommended'])) {
                $details[] = 'runtime rebuild required';
            } elseif (!empty($result['runtime']['cleanupPerformed'])) {
                $details[] = 'runtime cleaned up';
            } elseif (!empty($result['runtime']['cleanupRecommended'])) {
                $details[] = 'runtime cleanup recommended';
            }

            $auth->auditLog(
                $user['id'],
                $user['username'],
                'app.update',
                'app',
                $appId,
                'Updated app' . (!empty($details) ? ' (' . implode(', ', $details) . ')' : '')
            );
        }
        
        jsonResponse($result);
        break;

    case 'preview-update':
        // Preview an installed app update
        requireAdmin();

        $appId = $_GET['id'] ?? '';
        if (!$appId) {
            jsonResponse(['success' => false, 'error' => 'App ID required'], 400);
        }

        $result = $appManager->previewAppUpdate($appId);
        jsonResponse($result, $result['success'] ? 200 : 400);
        break;
        
    case 'uninstall':
        // Uninstall an app
        requireAdmin();
        
        $input = json_decode(file_get_contents('php://input'), true);
        $appId = $input['appId'] ?? '';
        $deleteData = $input['deleteData'] ?? false;
        
        if (!$appId) {
            jsonResponse(['success' => false, 'error' => 'App ID required'], 400);
        }
        
        $result = $appManager->uninstallApp($appId, $deleteData, $user['id'], $user['username']);
        
        if ($result['success']) {
            $auth->auditLog(
                $user['id'],
                $user['username'],
                'app.uninstall',
                'app',
                $appId,
                "Uninstalled app" . ($deleteData ? " with data deletion" : "")
            );
        }
        
        jsonResponse($result);
        break;
        
    case 'evaluate-capabilities':
        // Evaluate what capabilities an app would get at different trust levels
        requireAdmin();
        
        $appId = $_GET['id'] ?? '';
        if (!$appId) {
            jsonResponse(['success' => false, 'error' => 'App ID required'], 400);
        }
        
        $app = $appManager->getApp($appId);
        if (!$app) {
            jsonResponse(['success' => false, 'error' => 'App not found'], 404);
        }
        
        $results = [];
        for ($level = AppCapabilities::TRUST_SANDBOXED; $level >= AppCapabilities::TRUST_VERIFIED; $level--) {
            $levelInfo = AppCapabilities::getTrustLevelInfo($level);
            $evaluation = AppCapabilities::evaluateManifest($app, $level);
            $results[$level] = [
                'level' => $level,
                'levelInfo' => $levelInfo,
                'evaluation' => $evaluation,
            ];
        }
        
        jsonResponse(['success' => true, 'evaluations' => $results]);
        break;

    case 'set-exec-target':
        // Set exec target for docker-exec apps
        requireAdmin();
        
        $input = json_decode(file_get_contents('php://input'), true);
        $appId = $input['appId'] ?? '';
        $targetId = trim($input['targetId'] ?? '');
        
        if (!$appId) {
            jsonResponse(['success' => false, 'error' => 'App ID required'], 400);
        }
        
        $app = $appManager->getApp($appId);
        if (!$app) {
            jsonResponse(['success' => false, 'error' => 'App not found'], 404);
        }
        
        if ($targetId !== '') {
            $config = new ConfigManager();
            $target = $config->getTarget($targetId);
            if (!$target) {
                jsonResponse(['success' => false, 'error' => 'Target not found'], 404);
            }
            
            $targetType = $target['type'] ?? '';
            if (!in_array($targetType, ['docker', 'local', 'docker-exec'], true)) {
                jsonResponse(['success' => false, 'error' => 'Target must be docker or local'], 400);
            }

            $verified = $target['verifiedCapabilities'] ?? [];
            if (!in_array('exec.docker', $verified, true)) {
                jsonResponse(['success' => false, 'error' => 'Target is not verified for Docker execution. Verify it in Targets first.'], 400);
            }
        }
        
        $result = $appManager->setAppExecTarget($appId, $targetId !== '' ? $targetId : null, $user['id'], $user['username']);
        
        if ($result['success']) {
            $auth->auditLog(
                $user['id'],
                $user['username'],
                'app.exec_target',
                'app',
                $appId,
                $targetId !== '' ? "Set exec target to {$targetId}" : "Cleared exec target"
            );
        }
        
        jsonResponse($result);
        break;
    
    default:
        jsonResponse(['success' => false, 'error' => 'Unknown action'], 400);
}
