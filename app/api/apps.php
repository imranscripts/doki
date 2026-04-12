<?php
/**
 * apps.php - API endpoints for Apps system
 * 
 * Handles app configuration and settings management.
 */

// Suppress PHP errors from corrupting JSON output
ini_set('display_errors', '0');
error_reporting(E_ALL);
ob_start();

require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/PermissionManager.php';
require_once __DIR__ . '/../includes/AppManager.php';
require_once __DIR__ . '/../includes/AppImageBuilder.php';
require_once __DIR__ . '/../includes/AppContext.php';
require_once __DIR__ . '/../includes/session-bootstrap.php';

header('Content-Type: application/json');

doki_start_session();

function jsonResponse($data, $code = 200) {
    ob_end_clean();
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function requireAdminRole(array $user): void {
    if (!in_array($user['role'] ?? '', ['super-admin', 'admin'], true)) {
        jsonResponse(['success' => false, 'error' => 'Admin access required'], 403);
    }
}

function requireAppOrStudioAccess(array $user, PermissionManager $permManager, string $appId, string $workspaceId): void {
    if ($workspaceId !== '') {
        requireAdminRole($user);
        if (!$permManager->canAccessResource($user, 'module', 'studio')) {
            jsonResponse(['success' => false, 'error' => 'App Studio access required'], 403);
        }
        return;
    }

    if (!$permManager->canAccessResource($user, 'app', $appId)) {
        jsonResponse(['success' => false, 'error' => 'Access denied'], 403);
    }
}

function createRuntimeAppContext(string $appId, string $workspaceId): AppContext {
    return new AppContext($appId, $workspaceId !== '' ? $workspaceId : null, false);
}

function summarizeServiceFailures(array $results): string {
    $messages = [];
    foreach ($results as $serviceName => $result) {
        if (!is_array($result) || !empty($result['success'])) {
            continue;
        }

        $error = trim((string)($result['error'] ?? ''));
        if ($error === '') {
            $messages[] = $serviceName . ': failed to start';
            continue;
        }

        $messages[] = $serviceName . ': ' . $error;
    }

    if ($messages === []) {
        return 'Required services failed to start.';
    }

    return implode(' | ', $messages);
}

// Check authentication
doki_start_session();
$db = Database::getInstance();
$auth = new Auth();
$token = $_SESSION['auth_token'] ?? null;
$user = $auth->validateSession($token);

if (!$user) {
    jsonResponse(['success' => false, 'error' => 'Unauthorized'], 401);
}

$appManager = new AppManager();
$permManager = new PermissionManager();
$action = $_GET['action'] ?? '';
$appId = $_GET['app'] ?? '';
$workspaceId = trim((string)($_GET['workspace'] ?? ''));

try {
    switch ($action) {
        case 'list':
            // List all apps, filtered by granular permissions
            $apps = $appManager->getApps();
            if (!empty($apps) && $user['role'] !== 'super-admin') {
                $permManager = new PermissionManager();
                $appIds = array_column($apps, 'id');
                $allowed = $permManager->filterAccessibleResources($user, 'app', $appIds);
                $allowedMap = array_flip($allowed);
                $apps = array_values(array_filter($apps, function($a) use ($allowedMap) {
                    return isset($allowedMap[$a['id']]);
                }));
            }
            jsonResponse(['success' => true, 'apps' => $apps]);
            break;

        case 'get':
            // Get single app info
            if (empty($appId)) {
                jsonResponse(['success' => false, 'error' => 'App ID required'], 400);
            }
            // Granular permission check
            if (!$permManager->canAccessResource($user, 'app', $appId)) {
                jsonResponse(['success' => false, 'error' => 'Access denied'], 403);
            }
            $app = $appManager->getApp($appId);
            if (!$app) {
                jsonResponse(['success' => false, 'error' => 'App not found'], 404);
            }
            jsonResponse(['success' => true, 'app' => $app]);
            break;

        case 'get-settings':
            // Get app settings
            if (empty($appId)) {
                jsonResponse(['success' => false, 'error' => 'App ID required'], 400);
            }
            // Granular permission check
            if (!$permManager->canAccessResource($user, 'app', $appId)) {
                jsonResponse(['success' => false, 'error' => 'Access denied'], 403);
            }
            $settings = $appManager->loadSettings($appId);
            jsonResponse(['success' => true, 'settings' => $settings]);
            break;

        case 'save-settings':
            // Save app settings (admin only)
            if ($user['role'] !== 'super-admin' && $user['role'] !== 'admin') {
                jsonResponse(['success' => false, 'error' => 'Admin access required'], 403);
            }
            
            if (empty($appId)) {
                jsonResponse(['success' => false, 'error' => 'App ID required'], 400);
            }
            
            // Get request body
            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input) {
                jsonResponse(['success' => false, 'error' => 'Invalid JSON input'], 400);
            }
            
            // Validate based on app type
            $errors = [];
            switch ($appId) {
                case 'playwright':
                    $errors = $appManager->validatePlaywrightSettings($input);
                    break;
            }
            
            if (!empty($errors)) {
                jsonResponse(['success' => false, 'errors' => $errors], 400);
            }
            
            // Save settings
            $result = $appManager->saveSettings($appId, $input, $user['id'], $user['username']);
            
            if ($result['success']) {
                // Audit log
                $auth->auditLog(
                    $user['id'],
                    $user['username'],
                    'app_settings_update',
                    'app_settings',
                    $appId,
                    'Updated settings for app: ' . $appId
                );
            }
            
            jsonResponse($result, $result['success'] ? 200 : 400);
            break;
        
        case 'image-status':
            // Check app image build status
            if (empty($appId)) {
                jsonResponse(['success' => false, 'error' => 'App ID required'], 400);
            }
            
            $app = $appManager->getApp($appId);
            if (!$app) {
                jsonResponse(['success' => false, 'error' => 'App not found'], 404);
            }
            
            $builder = new AppImageBuilder($appId, $app);
            $status = $builder->getStatus();
            $buildStatus = $builder->getBuildStatus();
            
            jsonResponse([
                'success' => true, 
                'status' => $status,
                'build' => $buildStatus
            ]);
            break;
        
        case 'build-image':
            // Build custom image for app (can be triggered by anyone, runs async)
            if (empty($appId)) {
                jsonResponse(['success' => false, 'error' => 'App ID required'], 400);
            }
            
            $app = $appManager->getApp($appId);
            if (!$app) {
                jsonResponse(['success' => false, 'error' => 'App not found'], 404);
            }
            
            $builder = new AppImageBuilder($appId, $app);
            $buildStatus = $builder->getBuildStatus();
            $isStale = ($buildStatus['status'] ?? '') === 'stale';
            $isBuilding = ($buildStatus['status'] ?? '') === 'building';

            if ($isBuilding) {
                jsonResponse([
                    'success' => true,
                    'message' => 'Build already in progress',
                    'building' => true
                ]);
            }
            
            // Check if already built
            if ($builder->imageExists() && !$isStale) {
                jsonResponse([
                    'success' => true,
                    'message' => 'Image already exists',
                    'rebuild' => false
                ]);
            }

            if ($isStale) {
                $builder->removeImage();
            }
            
            // Trigger build in background
            $buildScript = __DIR__ . '/../scripts/build-app-image.sh';
            $logFile = __DIR__ . '/../data/app-build-status/' . $appId . '-build.log';
            $cmd = sprintf(
                'nohup %s %s > %s 2>&1 &',
                escapeshellarg($buildScript),
                escapeshellarg($appId),
                escapeshellarg($logFile)
            );
            
            exec($cmd);
            
            $auth->auditLog(
                $user['id'],
                $user['username'],
                'app_image_build_trigger',
                'app_runtime',
                $appId,
                'Triggered image build for app: ' . $appId
            );
            
            jsonResponse([
                'success' => true,
                'message' => 'Build started in background',
                'building' => true
            ]);
            break;

        case 'services-status':
            if (empty($appId)) {
                jsonResponse(['success' => false, 'error' => 'App ID required'], 400);
            }

            requireAppOrStudioAccess($user, $permManager, $appId, $workspaceId);

            if ($workspaceId === '') {
                $app = $appManager->getApp($appId);
                if (!$app) {
                    jsonResponse(['success' => false, 'error' => 'App not found'], 404);
                }
            }

            $appContext = createRuntimeAppContext($appId, $workspaceId);
            $overview = $appContext->getRequiredServicesOverview();

            jsonResponse([
                'success' => true,
                'overview' => $overview,
            ]);
            break;

        case 'start-services':
            if (empty($appId)) {
                jsonResponse(['success' => false, 'error' => 'App ID required'], 400);
            }

            requireAppOrStudioAccess($user, $permManager, $appId, $workspaceId);

            if ($workspaceId === '') {
                $app = $appManager->getApp($appId);
                if (!$app) {
                    jsonResponse(['success' => false, 'error' => 'App not found'], 404);
                }
            }

            $appContext = createRuntimeAppContext($appId, $workspaceId);
            $overview = $appContext->getRequiredServicesOverview();
            if (($overview['total'] ?? 0) === 0) {
                jsonResponse([
                    'success' => true,
                    'message' => 'App has no required services',
                    'overview' => $overview,
                ]);
            }

            if (empty($overview['canManage'])) {
                jsonResponse([
                    'success' => false,
                    'error' => $overview['error'] ?? 'This app cannot manage Docker services at its current trust level.',
                    'overview' => $overview,
                ], 403);
            }

            $results = [];
            $allSucceeded = true;
            foreach ($overview['services'] as $service) {
                $serviceName = (string)($service['name'] ?? '');
                if ($serviceName === '') {
                    continue;
                }

                if (!empty($service['running']) && empty($service['healthy'])) {
                    $appContext->stopService($serviceName);
                }

                $result = $appContext->ensureServiceRunning($serviceName);
                $results[$serviceName] = $result;
                if (empty($result['success'])) {
                    $allSucceeded = false;
                }
            }

            $updatedOverview = $appContext->getRequiredServicesOverview();
            $auditTarget = $workspaceId !== '' ? $appId . '@' . $workspaceId : $appId;
            $auth->auditLog(
                $user['id'],
                $user['username'],
                'app_services_start',
                'app_runtime',
                $auditTarget,
                'Started required services for app: ' . $auditTarget
            );

            jsonResponse([
                'success' => $allSucceeded,
                'message' => $allSucceeded ? 'Required services started' : 'Some services failed to start',
                'error' => $allSucceeded ? null : summarizeServiceFailures($results),
                'results' => $results,
                'overview' => $updatedOverview,
            ], $allSucceeded ? 200 : 500);
            break;

        case 'stop-services':
            requireAdminRole($user);

            if (empty($appId)) {
                jsonResponse(['success' => false, 'error' => 'App ID required'], 400);
            }

            requireAppOrStudioAccess($user, $permManager, $appId, $workspaceId);

            if ($workspaceId === '') {
                $app = $appManager->getApp($appId);
                if (!$app) {
                    jsonResponse(['success' => false, 'error' => 'App not found'], 404);
                }
            }

            $appContext = createRuntimeAppContext($appId, $workspaceId);
            $overview = $appContext->getRequiredServicesOverview();
            if (($overview['total'] ?? 0) === 0) {
                jsonResponse([
                    'success' => true,
                    'message' => 'App has no required services',
                    'overview' => $overview,
                ]);
            }

            if (empty($overview['canManage'])) {
                jsonResponse([
                    'success' => false,
                    'error' => $overview['error'] ?? 'This app cannot manage Docker services at its current trust level.',
                    'overview' => $overview,
                ], 403);
            }

            $results = [];
            foreach ($overview['services'] as $service) {
                $serviceName = (string)($service['name'] ?? '');
                if ($serviceName === '') {
                    continue;
                }
                $results[$serviceName] = $appContext->stopService($serviceName);
            }

            $updatedOverview = $appContext->getRequiredServicesOverview();
            $auditTarget = $workspaceId !== '' ? $appId . '@' . $workspaceId : $appId;
            $auth->auditLog(
                $user['id'],
                $user['username'],
                'app_services_stop',
                'app_runtime',
                $auditTarget,
                'Stopped required services for app: ' . $auditTarget
            );

            jsonResponse([
                'success' => true,
                'message' => 'Required services stopped',
                'results' => $results,
                'overview' => $updatedOverview,
            ]);
            break;
        
        case 'start-container':
            // Start FPM container for app
            if (empty($appId)) {
                jsonResponse(['success' => false, 'error' => 'App ID required'], 400);
            }
            
            $app = $appManager->getApp($appId);
            if (!$app) {
                jsonResponse(['success' => false, 'error' => 'App not found'], 404);
            }
            
            $builder = new AppImageBuilder($appId, $app);
            
            if (!$builder->needsCustomImage()) {
                jsonResponse(['success' => true, 'message' => 'App does not need custom runtime']);
            }
            
            $result = $builder->startContainer();
            
            if ($result['success']) {
                $auth->auditLog(
                    $user['id'],
                    $user['username'],
                    'app_container_start',
                    'app_runtime',
                    $appId,
                    'Started container for app: ' . $appId
                );
            }
            
            jsonResponse($result, $result['success'] ? 200 : 500);
            break;
        
        case 'stop-container':
            // Stop FPM container for app (admin only)
            requireAdminRole($user);
            
            if (empty($appId)) {
                jsonResponse(['success' => false, 'error' => 'App ID required'], 400);
            }
            
            $app = $appManager->getApp($appId);
            if (!$app) {
                jsonResponse(['success' => false, 'error' => 'App not found'], 404);
            }
            
            $builder = new AppImageBuilder($appId, $app);
            $result = $builder->stopContainer();
            
            $auth->auditLog(
                $user['id'],
                $user['username'],
                'app_container_stop',
                'app_runtime',
                $appId,
                'Stopped container for app: ' . $appId
            );
            
            jsonResponse($result);
            break;
        
        case 'rebuild-image':
            // Rebuild custom image (admin only)
            requireAdminRole($user);
            
            if (empty($appId)) {
                jsonResponse(['success' => false, 'error' => 'App ID required'], 400);
            }
            
            $app = $appManager->getApp($appId);
            if (!$app) {
                jsonResponse(['success' => false, 'error' => 'App not found'], 404);
            }
            
            $builder = new AppImageBuilder($appId, $app);
            
            // Remove existing image first
            $builder->removeImage();
            
            // Trigger rebuild in background
            $buildScript = __DIR__ . '/../scripts/build-app-image.sh';
            $logFile = __DIR__ . '/../data/app-build-status/' . $appId . '-build.log';
            $cmd = sprintf(
                'nohup %s %s > %s 2>&1 &',
                escapeshellarg($buildScript),
                escapeshellarg($appId),
                escapeshellarg($logFile)
            );
            
            exec($cmd);
            
            $auth->auditLog(
                $user['id'],
                $user['username'],
                'app_image_rebuild',
                'app_runtime',
                $appId,
                'Triggered image rebuild for app: ' . $appId
            );
            
            jsonResponse([
                'success' => true,
                'message' => 'Rebuild started in background',
                'building' => true
            ]);
            break;
        
        case 'cleanup-runtime':
            // Full cleanup - remove container and image (admin only)
            requireAdminRole($user);
            
            if (empty($appId)) {
                jsonResponse(['success' => false, 'error' => 'App ID required'], 400);
            }
            
            $app = $appManager->getApp($appId);
            if (!$app) {
                jsonResponse(['success' => false, 'error' => 'App not found'], 404);
            }
            
            $builder = new AppImageBuilder($appId, $app);
            $result = $builder->cleanup();

            if ($result['success']) {
                $auth->auditLog(
                    $user['id'],
                    $user['username'],
                    'app_runtime_cleanup',
                    'app_runtime',
                    $appId,
                    'Cleaned up runtime for app: ' . $appId
                );
            }
            
            jsonResponse($result, $result['success'] ? 200 : 500);
            break;
        
        case 'set-priority':
            // Set app priority (admin only, global setting)
            // Priority is always global - affects default sorting for all users
            requireAdminRole($user);
            
            if (empty($appId)) {
                jsonResponse(['success' => false, 'error' => 'App ID required'], 400);
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            $priority = $input['priority'] ?? null;
            
            if ($priority === null || !is_numeric($priority)) {
                jsonResponse(['success' => false, 'error' => 'Priority must be a number (0-100)'], 400);
            }
            
            $priority = max(0, min(100, (int)$priority));  // Clamp to 0-100
            
            // Priority is always saved to global (not user preferences)
            $result = $appManager->setAppPriority($appId, $priority);
            
            if ($result) {
                $auth->auditLog(
                    $user['id'],
                    $user['username'],
                    'app_priority_update',
                    'app',
                    $appId,
                    "Set app priority to {$priority} (global)"
                );
                jsonResponse(['success' => true, 'priority' => $priority, 'scope' => 'global']);
            } else {
                jsonResponse(['success' => false, 'error' => 'Failed to update priority'], 500);
            }
            break;
        
        case 'set-pinned':
            // Set app pinned status
            // The 'global' parameter determines where to save:
            // - global: true → Save to global .installed.yaml (default for all users)
            // - global: false → Save to user preferences (personal override)
            // Context matters, not role: Admin page sets defaults (global), Apps page sets preferences (user)
            if (empty($appId)) {
                jsonResponse(['success' => false, 'error' => 'App ID required'], 400);
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            $pinned = $input['pinned'] ?? false;
            $pinned = (bool)$pinned;
            
            // Check if global flag is set
            // If not set, default to user preferences (safer default - doesn't affect all users)
            $saveToGlobal = isset($input['global']) ? (bool)$input['global'] : false;
            
            if ($saveToGlobal) {
                // Save to global .installed.yaml (admin page - sets default for all users)
                $result = $appManager->setAppPinned($appId, $pinned, null);
                $scope = 'global';
            } else {
                // Save to user preferences (apps page - personal preference)
                // Works for both admins and regular users
                $result = $appManager->setAppPinned($appId, $pinned, $user['id']);
                $scope = 'user';
            }
            
            if ($result) {
                $auth->auditLog(
                    $user['id'],
                    $user['username'],
                    'app_pinned_update',
                    'app',
                    $appId,
                    ($pinned ? 'Pinned' : 'Unpinned') . ' app (' . $scope . ')'
                );
                jsonResponse([
                    'success' => true, 
                    'pinned' => $pinned,
                    'scope' => $scope
                ]);
            } else {
                jsonResponse(['success' => false, 'error' => 'Failed to update pinned status'], 500);
            }
            break;
        
        case 'set-order':
            // Set app sidebar order (per-user)
            $input = json_decode(file_get_contents('php://input'), true);
            $order = $input['order'] ?? [];
            
            if (!is_array($order)) {
                jsonResponse(['success' => false, 'error' => 'Order must be an array'], 400);
            }
            
            // Pass user ID to save to user preferences
            $result = $appManager->setAppOrder($order, $user['id']);
            
            if ($result) {
                $auth->auditLog(
                    $user['id'],
                    $user['username'],
                    'app_order_update',
                    'app',
                    '',
                    'Updated app sidebar order'
                );
                jsonResponse(['success' => true, 'order' => $order]);
            } else {
                jsonResponse(['success' => false, 'error' => 'Failed to save order'], 500);
            }
            break;

        default:
            jsonResponse(['success' => false, 'error' => 'Unknown action'], 400);
    }
} catch (Exception $e) {
    error_log("Apps API error: " . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Server error: ' . $e->getMessage()], 500);
} catch (Error $e) {
    error_log("Apps API fatal error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    jsonResponse(['success' => false, 'error' => 'Server error: ' . $e->getMessage()], 500);
}
