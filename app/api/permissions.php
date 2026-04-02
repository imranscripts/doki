<?php
/**
 * api/permissions.php - Groups & Permissions Management API
 *
 * Manages permission groups, group memberships, group deny rules,
 * and per-user permission overrides. Super-admin only.
 */

require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/PermissionManager.php';
require_once __DIR__ . '/../includes/AppManager.php';
require_once __DIR__ . '/../includes/CommandsManager.php';
require_once __DIR__ . '/../includes/session-bootstrap.php';

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Content-Type: application/json');

doki_start_session();

$auth = new Auth();
$permManager = new PermissionManager();
$method = $_SERVER['REQUEST_METHOD'];

// Get request body for POST/PUT
$input = [];
if (in_array($method, ['POST', 'PUT', 'DELETE'])) {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true) ?? [];
}

$action = $_GET['action'] ?? '';

function jsonResponse(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function requireAuth(): array {
    global $auth;

    $token = $_SESSION['auth_token'] ?? null;
    if (!$token) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            $token = $matches[1];
        }
    }

    if (!$token) {
        jsonResponse(['success' => false, 'error' => 'Not authenticated'], 401);
    }

    $user = $auth->validateSession($token);
    if (!$user) {
        jsonResponse(['success' => false, 'error' => 'Session expired'], 401);
    }

    return $user;
}

function requireSuperAdmin(): array {
    global $auth;
    $user = requireAuth();

    if ($user['role'] !== 'super-admin') {
        jsonResponse(['success' => false, 'error' => 'Super-admin access required'], 403);
    }

    return $user;
}

switch ($action) {
    // =========================================================================
    // GROUPS
    // =========================================================================
    case 'groups':
        $user = requireSuperAdmin();
        if ($method === 'GET') {
            $groups = $permManager->getAllGroups();
            jsonResponse(['success' => true, 'groups' => $groups]);
        }
        break;

    case 'group':
        $user = requireSuperAdmin();
        $id = $_GET['id'] ?? '';

        if ($method === 'GET') {
            if (empty($id)) {
                jsonResponse(['success' => false, 'error' => 'Group ID required'], 400);
            }
            $group = $permManager->getGroup($id);
            if (!$group) {
                jsonResponse(['success' => false, 'error' => 'Group not found'], 404);
            }
            $group['members'] = $permManager->getGroupMembers($id);
            $group['rules'] = $permManager->getGroupDenyRules($id);
            jsonResponse(['success' => true, 'group' => $group]);
        }

        if ($method === 'POST') {
            $name = trim($input['name'] ?? '');
            $description = trim($input['description'] ?? '');
            $mode = trim($input['mode'] ?? 'blacklist');
            if (empty($name)) {
                jsonResponse(['success' => false, 'error' => 'Group name is required'], 400);
            }
            if (!in_array($mode, ['blacklist', 'whitelist'])) {
                jsonResponse(['success' => false, 'error' => 'Mode must be blacklist or whitelist'], 400);
            }
            $result = $permManager->createGroup($name, $description, $user['id'], $mode);
            if ($result['success']) {
                $auth->auditLog($user['id'], $user['username'], 'group_created', 'permission_group', $result['id'], "Created $mode group: $name");
            }
            jsonResponse($result, $result['success'] ? 200 : 400);
        }

        if ($method === 'PUT') {
            if (empty($id)) {
                jsonResponse(['success' => false, 'error' => 'Group ID required'], 400);
            }
            $result = $permManager->updateGroup($id, $input);
            if ($result['success']) {
                $auth->auditLog($user['id'], $user['username'], 'group_updated', 'permission_group', $id, "Updated group");
            }
            jsonResponse($result, $result['success'] ? 200 : 400);
        }

        if ($method === 'DELETE') {
            if (empty($id)) {
                jsonResponse(['success' => false, 'error' => 'Group ID required'], 400);
            }
            $group = $permManager->getGroup($id);
            $result = $permManager->deleteGroup($id);
            if ($result['success']) {
                $auth->auditLog($user['id'], $user['username'], 'group_deleted', 'permission_group', $id, "Deleted group: " . ($group['name'] ?? $id));
            }
            jsonResponse($result, $result['success'] ? 200 : 400);
        }
        break;

    // =========================================================================
    // GROUP MEMBERSHIP
    // =========================================================================
    case 'add-member':
        $user = requireSuperAdmin();
        $groupId = $input['groupId'] ?? '';
        $userId = $input['userId'] ?? '';

        if (empty($groupId) || empty($userId)) {
            jsonResponse(['success' => false, 'error' => 'groupId and userId are required'], 400);
        }

        $result = $permManager->addUserToGroup($groupId, $userId, $user['id']);
        if ($result['success']) {
            $auth->auditLog($user['id'], $user['username'], 'group_member_added', 'permission_group', $groupId, "Added user $userId to group");
        }
        jsonResponse($result);
        break;

    case 'remove-member':
        $user = requireSuperAdmin();
        $groupId = $input['groupId'] ?? '';
        $userId = $input['userId'] ?? '';

        if (empty($groupId) || empty($userId)) {
            jsonResponse(['success' => false, 'error' => 'groupId and userId are required'], 400);
        }

        $result = $permManager->removeUserFromGroup($groupId, $userId);
        if ($result['success']) {
            $auth->auditLog($user['id'], $user['username'], 'group_member_removed', 'permission_group', $groupId, "Removed user $userId from group");
        }
        jsonResponse($result);
        break;

    // =========================================================================
    // GROUP DENY RULES
    // =========================================================================
    case 'set-group-deny':
    case 'set-group-rule':
        $user = requireSuperAdmin();
        $groupId = $input['groupId'] ?? '';
        $resourceType = $input['resourceType'] ?? '';
        $resourceId = $input['resourceId'] ?? '';

        if (empty($groupId) || empty($resourceType) || empty($resourceId)) {
            jsonResponse(['success' => false, 'error' => 'groupId, resourceType, and resourceId are required'], 400);
        }
        if (!in_array($resourceType, ['app', 'command', 'module'])) {
            jsonResponse(['success' => false, 'error' => 'resourceType must be app, command, or module'], 400);
        }

        $result = $permManager->setGroupRule($groupId, $resourceType, $resourceId, $user['id']);
        if ($result['success']) {
            $ruleAction = $result['action'] ?? 'deny';
            $auth->auditLog($user['id'], $user['username'], 'group_rule_added', 'permission_group', $groupId, "Set $ruleAction for $resourceType:$resourceId");
        }
        jsonResponse($result);
        break;

    case 'remove-group-deny':
    case 'remove-group-rule':
        $user = requireSuperAdmin();
        $groupId = $input['groupId'] ?? '';
        $resourceType = $input['resourceType'] ?? '';
        $resourceId = $input['resourceId'] ?? '';

        if (empty($groupId) || empty($resourceType) || empty($resourceId)) {
            jsonResponse(['success' => false, 'error' => 'groupId, resourceType, and resourceId are required'], 400);
        }

        $result = $permManager->removeGroupRule($groupId, $resourceType, $resourceId);
        if ($result['success']) {
            $auth->auditLog($user['id'], $user['username'], 'group_rule_removed', 'permission_group', $groupId, "Removed rule for $resourceType:$resourceId");
        }
        jsonResponse($result);
        break;

    // =========================================================================
    // USER PERMISSIONS
    // =========================================================================
    case 'user-permissions':
        $user = requireSuperAdmin();
        $targetUserId = $_GET['id'] ?? '';

        if (empty($targetUserId)) {
            jsonResponse(['success' => false, 'error' => 'User ID required'], 400);
        }

        $groups = $permManager->getUserGroups($targetUserId);
        $overrides = $permManager->getUserOverrides($targetUserId);
        $effective = $permManager->getEffectivePermissions($targetUserId);
        $details = $permManager->getUserPermissionDetails($targetUserId);
        $overrideProtected = $permManager->isSuperAdminUser($targetUserId);

        jsonResponse([
            'success' => true,
            'groups' => $groups,
            'overrides' => $overrides,
            'effective' => $effective,
            'details' => $details,
            'overrideProtected' => $overrideProtected,
            'overrideProtectionMessage' => $overrideProtected
                ? 'Permission overrides and permission-group restrictions do not apply to super-admin accounts.'
                : null,
        ]);
        break;

    case 'set-user-override':
        $user = requireSuperAdmin();
        $targetUserId = $input['userId'] ?? '';
        $resourceType = $input['resourceType'] ?? '';
        $resourceId = $input['resourceId'] ?? '';
        $overrideAction = $input['action'] ?? '';

        if (empty($targetUserId) || empty($resourceType) || empty($resourceId) || empty($overrideAction)) {
            jsonResponse(['success' => false, 'error' => 'userId, resourceType, resourceId, and action are required'], 400);
        }
        if (!in_array($resourceType, ['app', 'command', 'module'])) {
            jsonResponse(['success' => false, 'error' => 'resourceType must be app, command, or module'], 400);
        }
        if (!in_array($overrideAction, ['allow', 'deny'])) {
            jsonResponse(['success' => false, 'error' => 'action must be allow or deny'], 400);
        }

        $result = $permManager->setUserOverride($targetUserId, $resourceType, $resourceId, $overrideAction, $user['id']);
        if ($result['success']) {
            $auth->auditLog($user['id'], $user['username'], 'user_override_set', 'user', $targetUserId, "Set $overrideAction for $resourceType:$resourceId");
        }
        jsonResponse($result);
        break;

    case 'remove-user-override':
        $user = requireSuperAdmin();
        $targetUserId = $input['userId'] ?? '';
        $resourceType = $input['resourceType'] ?? '';
        $resourceId = $input['resourceId'] ?? '';

        if (empty($targetUserId) || empty($resourceType) || empty($resourceId)) {
            jsonResponse(['success' => false, 'error' => 'userId, resourceType, and resourceId are required'], 400);
        }

        $result = $permManager->removeUserOverride($targetUserId, $resourceType, $resourceId);
        if ($result['success']) {
            $auth->auditLog($user['id'], $user['username'], 'user_override_removed', 'user', $targetUserId, "Removed override for $resourceType:$resourceId");
        }
        jsonResponse($result);
        break;

    // =========================================================================
    // RESOURCES (for picker UI)
    // =========================================================================
    case 'resources':
        $user = requireSuperAdmin();

        // Get all apps
        $appManager = new AppManager();
        $apps = $appManager->getApps();
        $appResources = array_map(function($app) {
            return ['id' => $app['id'], 'name' => $app['name'] ?? $app['id'], 'type' => 'app'];
        }, $apps);

        // Get all commands
        $commandsManager = new CommandsManager($auth);
        $commandsResult = $commandsManager->getCommands();
        $commandResources = array_map(function($cmd) {
            return ['id' => $cmd['id'], 'name' => $cmd['name'] ?? $cmd['id'], 'type' => 'command'];
        }, $commandsResult['commands'] ?? []);

        // Get all modules
        $moduleSlugs = PermissionManager::getModuleSlugs();
        $moduleResources = array_map(function($id, $name) {
            return ['id' => $id, 'name' => $name, 'type' => 'module'];
        }, array_keys($moduleSlugs), array_values($moduleSlugs));

        jsonResponse([
            'success' => true,
            'resources' => [
                'apps' => $appResources,
                'commands' => $commandResources,
                'modules' => $moduleResources,
            ]
        ]);
        break;

    default:
        jsonResponse(['success' => false, 'error' => 'Unknown action'], 400);
}
