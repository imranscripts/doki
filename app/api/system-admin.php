<?php
/**
 * system-admin.php - Doki-wide system administration API
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);
ob_start();

require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/SystemModuleRegistry.php';
require_once __DIR__ . '/../includes/SystemSettingsManager.php';
require_once __DIR__ . '/../includes/StealthGuard.php';
require_once __DIR__ . '/../includes/session-bootstrap.php';

header('Content-Type: application/json');

function jsonResponse(array $data, int $code = 200): void {
    ob_end_clean();
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function readJsonBody(): array {
    $body = file_get_contents('php://input');
    if (!is_string($body) || trim($body) === '') {
        return [];
    }

    $decoded = json_decode($body, true);
    return is_array($decoded) ? $decoded : [];
}

function requireAdminRole(array $user): void {
    if (!in_array($user['role'] ?? '', ['super-admin', 'admin'], true)) {
        jsonResponse(['success' => false, 'error' => 'Admin access required'], 403);
    }
}

function requireDokiAdminAccess(Auth $auth, array $user): void {
    requireAdminRole($user);
    if (!$auth->canAccessResource('module', 'doki.admin')) {
        jsonResponse(['success' => false, 'error' => 'Doki admin access required'], 403);
    }
}

function getStealthSecretStatus(): array {
    return [
        'ready' => StealthGuard::isSecretReady(),
        'path' => StealthGuard::getSecretPath(),
    ];
}

doki_start_session();
$auth = new Auth();
$token = $_SESSION['auth_token'] ?? null;
$user = $token ? $auth->validateSession($token) : null;

if (!$user) {
    jsonResponse(['success' => false, 'error' => 'Unauthorized'], 401);
}

requireDokiAdminAccess($auth, $user);

$settingsManager = new SystemSettingsManager();
$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'state':
            jsonResponse([
                'success' => true,
                'modules' => $settingsManager->getModuleEditorData(),
                'moduleOptions' => $settingsManager->getModuleOptions(),
                'sections' => array_values(SystemModuleRegistry::getSectionDefinitions()),
                'security' => $settingsManager->getRuntimeStealthSettings(),
                'secret' => getStealthSecretStatus(),
            ]);
            break;

        case 'save-modules':
            $input = readJsonBody();
            $modules = is_array($input['modules'] ?? null) ? $input['modules'] : [];
            $options = is_array($input['options'] ?? null) ? $input['options'] : [];
            $settingsManager->saveModuleSettings($modules, $user['id']);
            $settingsManager->saveModuleOptions($options, $user['id']);
            $permissionManager = $auth->getPermissionManager();
            $canReturnToSettings = $permissionManager->canAccessResource($user, 'module', 'doki.admin');
            $redirect = $canReturnToSettings
                ? '/admin/settings.php'
                : ($permissionManager->getFirstAccessiblePage($user) ?? '/dashboard.php');
            $auth->auditLog(
                $user['id'],
                $user['username'],
                'doki.admin.modules.update',
                'system_settings',
                'doki.modules',
                'Updated Doki module visibility and ordering'
            );
            jsonResponse([
                'success' => true,
                'modules' => $settingsManager->getModuleEditorData(),
                'moduleOptions' => $settingsManager->getModuleOptions(),
                'sections' => array_values(SystemModuleRegistry::getSectionDefinitions()),
                'redirect' => $redirect,
            ]);
            break;

        case 'save-security':
            $input = readJsonBody();
            $security = $settingsManager->saveStealthSettings($input, $user['id']);
            $auth->auditLog(
                $user['id'],
                $user['username'],
                'doki.admin.security.update',
                'system_settings',
                'doki.security.stealth',
                'Updated Doki stealth mode settings'
            );
            jsonResponse([
                'success' => true,
                'security' => $security,
                'secret' => getStealthSecretStatus(),
            ]);
            break;

        case 'rotate-stealth-secret':
            if (!StealthGuard::rotateSecret()) {
                jsonResponse(['success' => false, 'error' => 'Unable to rotate stealth secret'], 500);
            }

            $auth->auditLog(
                $user['id'],
                $user['username'],
                'doki.admin.security.rotate_secret',
                'system_settings',
                'doki.security.stealth.secret',
                'Rotated stealth login secret'
            );
            jsonResponse([
                'success' => true,
                'secret' => getStealthSecretStatus(),
            ]);
            break;

        default:
            jsonResponse(['success' => false, 'error' => 'Unknown action'], 404);
    }
} catch (Throwable $e) {
    jsonResponse([
        'success' => false,
        'error' => $e->getMessage(),
    ], 500);
}
