<?php
/**
 * PermissionManager.php - Granular Access Control
 *
 * Manages groups (blacklist/whitelist), group rules, and per-user overrides.
 * Layered on top of the existing role-based system:
 *   1. Super-admin bypasses all checks
 *   2. User-level override (allow/deny) takes priority
 *   3. Whitelist groups: user can ONLY access resources explicitly allowed
 *   4. Blacklist groups: resources explicitly denied are blocked
 *   5. Default: allow
 *
 * Group modes:
 *   - blacklist (default): rules list what is DENIED. Everything else allowed.
 *   - whitelist: rules list what is ALLOWED. Everything else denied for that type.
 *     If a whitelist group has rules for a resource type, the user needs at
 *     least one whitelist allow for that resource+type to access it.
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/SystemModuleRegistry.php';
require_once __DIR__ . '/SystemSettingsManager.php';

class PermissionManager {
    private PDO $db;
    private ?SystemSettingsManager $systemSettingsManager = null;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    // =========================================================================
    // CORE PERMISSION CHECKS
    // =========================================================================

    /**
     * Check if a user can access a specific resource.
     * Super-admins are only subject to global module visibility settings.
     */
    public function canAccessResource(array $user, string $resourceType, string $resourceId): bool {
        $settingsManager = $this->getSystemSettingsManager();
        $moduleRestrictedResource = in_array($resourceType, ['module', 'app', 'command'], true);
        $role = (string)($user['role'] ?? 'user');
        $userId = (string)($user['id'] ?? '');

        if ($role === 'super-admin' && (!$moduleRestrictedResource || !$settingsManager->shouldApplyModuleSettingsToRole('super-admin'))) {
            return true;
        }

        if ($resourceType === 'module' && !$settingsManager->isModuleEnabledForRole($resourceId, $role)) {
            return false;
        }
        if ($resourceType === 'app' && !$settingsManager->isModuleEnabledForRole('apps', $role)) {
            return false;
        }
        if ($resourceType === 'command' && !$settingsManager->isModuleEnabledForRole('commands', $role)) {
            return false;
        }

        if ($role === 'super-admin' && $userId !== '') {
            return true;
        }

        // 1. Check user-level override (highest priority)
        $stmt = $this->db->prepare(
            "SELECT action FROM user_permissions
             WHERE user_id = ? AND resource_type = ? AND resource_id = ?"
        );
        $stmt->execute([$user['id'], $resourceType, $resourceId]);
        $override = $stmt->fetch();

        if ($override) {
            return $override['action'] === 'allow';
        }

        // 2. Check whitelist groups: if user is in ANY whitelist group,
        //    only explicitly allowed resources are accessible. Everything else is denied.
        $stmt = $this->db->prepare(
            "SELECT pg.id FROM permission_groups pg
             JOIN group_members gm ON gm.group_id = pg.id
             WHERE gm.user_id = ? AND pg.mode = 'whitelist'"
        );
        $stmt->execute([$user['id']]);
        $whitelistGroups = $stmt->fetchAll();

        if (!empty($whitelistGroups)) {
            $whitelistGroupIds = array_column($whitelistGroups, 'id');
            $placeholders = implode(',', array_fill(0, count($whitelistGroupIds), '?'));

            // Check if this specific resource is explicitly allowed in any whitelist group
            $stmt = $this->db->prepare(
                "SELECT 1 FROM group_permissions
                 WHERE group_id IN ($placeholders) AND resource_type = ? AND resource_id = ?
                 LIMIT 1"
            );
            $stmt->execute([...$whitelistGroupIds, $resourceType, $resourceId]);
            if (!$stmt->fetch()) {
                return false; // Not explicitly allowed → denied
            }
        }

        // 3. Check blacklist groups: any deny rule blocks access
        $stmt = $this->db->prepare(
            "SELECT 1 FROM group_permissions gp
             JOIN group_members gm ON gm.group_id = gp.group_id
             JOIN permission_groups pg ON pg.id = gp.group_id
             WHERE gm.user_id = ? AND gp.resource_type = ? AND gp.resource_id = ?
               AND pg.mode = 'blacklist'
             LIMIT 1"
        );
        $stmt->execute([$user['id'], $resourceType, $resourceId]);

        if ($stmt->fetch()) {
            return false;
        }

        // 4. Default: allow
        return true;
    }

    /**
     * Batch filter: return only accessible resource IDs from a list.
     * More efficient than calling canAccessResource() in a loop.
     */
    public function filterAccessibleResources(array $user, string $resourceType, array $resourceIds): array {
        $settingsManager = $this->getSystemSettingsManager();
        $role = (string)($user['role'] ?? 'user');
        $moduleRestrictedResource = in_array($resourceType, ['module', 'app', 'command'], true);
        $userId = (string)($user['id'] ?? '');

        if (($user['role'] === 'super-admin' && (!$moduleRestrictedResource || !$settingsManager->shouldApplyModuleSettingsToRole('super-admin')))
            || empty($resourceIds)) {
            return $resourceIds;
        }

        if ($resourceType === 'module') {
            $resourceIds = array_values(array_filter($resourceIds, function(string $moduleId) use ($role): bool {
                return $this->getSystemSettingsManager()->isModuleEnabledForRole($moduleId, $role);
            }));

            if (empty($resourceIds)) {
                return [];
            }
        }
        if ($resourceType === 'app' && !$settingsManager->isModuleEnabledForRole('apps', $role)) {
            return [];
        }
        if ($resourceType === 'command' && !$settingsManager->isModuleEnabledForRole('commands', $role)) {
            return [];
        }

        if ($role === 'super-admin' && $userId !== '') {
            return $resourceIds;
        }

        // Get all user overrides for this resource type
        $stmt = $this->db->prepare(
            "SELECT resource_id, action FROM user_permissions
             WHERE user_id = ? AND resource_type = ?"
        );
        $stmt->execute([$user['id'], $resourceType]);
        $userOverrides = [];
        while ($row = $stmt->fetch()) {
            $userOverrides[$row['resource_id']] = $row['action'];
        }

        // Check if user is in any whitelist group
        $stmt = $this->db->prepare(
            "SELECT 1 FROM permission_groups pg
             JOIN group_members gm ON gm.group_id = pg.id
             WHERE gm.user_id = ? AND pg.mode = 'whitelist'
             LIMIT 1"
        );
        $stmt->execute([$user['id']]);
        $inWhitelistGroup = (bool)$stmt->fetch();

        // Get whitelist allows for this resource type
        $whitelistAllows = [];
        if ($inWhitelistGroup) {
            $stmt = $this->db->prepare(
                "SELECT DISTINCT gp.resource_id FROM group_permissions gp
                 JOIN group_members gm ON gm.group_id = gp.group_id
                 JOIN permission_groups pg ON pg.id = gp.group_id
                 WHERE gm.user_id = ? AND gp.resource_type = ? AND pg.mode = 'whitelist'"
            );
            $stmt->execute([$user['id'], $resourceType]);
            while ($row = $stmt->fetch()) {
                $whitelistAllows[$row['resource_id']] = true;
            }
        }

        // Get blacklist denies for this resource type
        $stmt = $this->db->prepare(
            "SELECT DISTINCT gp.resource_id FROM group_permissions gp
             JOIN group_members gm ON gm.group_id = gp.group_id
             JOIN permission_groups pg ON pg.id = gp.group_id
             WHERE gm.user_id = ? AND gp.resource_type = ? AND pg.mode = 'blacklist'"
        );
        $stmt->execute([$user['id'], $resourceType]);
        $blacklistDenies = [];
        while ($row = $stmt->fetch()) {
            $blacklistDenies[$row['resource_id']] = true;
        }

        return array_values(array_filter($resourceIds, function($id) use ($userOverrides, $whitelistAllows, $inWhitelistGroup, $blacklistDenies) {
            // User override takes priority
            if (isset($userOverrides[$id])) {
                return $userOverrides[$id] === 'allow';
            }
            // Whitelist: if user is in any whitelist group, only explicitly allowed resources pass
            if ($inWhitelistGroup && !isset($whitelistAllows[$id])) {
                return false;
            }
            // Blacklist deny
            if (isset($blacklistDenies[$id])) {
                return false;
            }
            // Default: allow
            return true;
        }));
    }

    /**
     * Returns true only when access was explicitly granted through a user
     * override or a whitelist group rule for the exact resource.
     */
    public function hasExplicitResourceGrant(array $user, string $resourceType, string $resourceId): bool {
        $userId = trim((string)($user['id'] ?? ''));
        $resourceType = trim($resourceType);
        $resourceId = trim($resourceId);

        if ($userId === '' || $resourceType === '' || $resourceId === '') {
            return false;
        }

        $stmt = $this->db->prepare(
            "SELECT action FROM user_permissions
             WHERE user_id = ? AND resource_type = ? AND resource_id = ?
             LIMIT 1"
        );
        $stmt->execute([$userId, $resourceType, $resourceId]);
        $override = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($override) {
            return ($override['action'] ?? '') === 'allow';
        }

        $stmt = $this->db->prepare(
            "SELECT 1 FROM group_permissions gp
             JOIN group_members gm ON gm.group_id = gp.group_id
             JOIN permission_groups pg ON pg.id = gp.group_id
             WHERE gm.user_id = ? AND gp.resource_type = ? AND gp.resource_id = ?
               AND pg.mode = 'whitelist'
             LIMIT 1"
        );
        $stmt->execute([$userId, $resourceType, $resourceId]);

        return (bool)$stmt->fetchColumn();
    }

    /**
     * Get the first accessible page URL for a user.
     * Checks modules and apps in sidebar order, returns the first allowed one.
     * Returns null if nothing is accessible (shouldn't happen for valid users).
     */
    public function getFirstAccessiblePage(array $user): ?string {
        $settingsManager = $this->getSystemSettingsManager();
        if ($user['role'] === 'super-admin' && !$settingsManager->shouldApplyModuleSettingsToRole('super-admin')) {
            return '/dashboard.php';
        }

        $moduleDefinitions = SystemModuleRegistry::getModuleDefinitions();
        $sidebarModules = $settingsManager->getSidebarModulesBySection();
        $candidates = [];

        $dashboardDefinition = $moduleDefinitions['dashboard'] ?? null;
        if ($dashboardDefinition && !empty($dashboardDefinition['path'])) {
            $candidates[] = [
                'type' => 'module',
                'id' => 'dashboard',
                'url' => '/' . ltrim((string)$dashboardDefinition['path'], '/'),
            ];
        }

        $appsDefinition = $moduleDefinitions['apps'] ?? null;
        if ($appsDefinition && !empty($appsDefinition['path'])) {
            $candidates[] = [
                'type' => 'module',
                'id' => 'apps',
                'url' => '/' . ltrim((string)$appsDefinition['path'], '/'),
            ];
        }

        if ($settingsManager->isModuleEnabled('apps')) {
            // Add enabled apps as candidates (apps are filesystem-based, not DB)
            require_once __DIR__ . '/AppManager.php';
            $appManager = new AppManager();
            foreach ($appManager->getEnabledApps() as $app) {
                $appId = $app['id'] ?? '';
                if ($appId) {
                    $candidates[] = ['type' => 'app', 'id' => $appId, 'url' => '/apps/' . $appId . '/index.php'];
                }
            }
        }

        foreach ($sidebarModules as $section) {
            foreach ($section['modules'] ?? [] as $module) {
                $path = trim((string)($module['path'] ?? ''));
                if ($path === '') {
                    continue;
                }
                $candidates[] = [
                    'type' => 'module',
                    'id' => (string)$module['id'],
                    'url' => '/' . ltrim($path, '/'),
                ];
            }
        }

        // Return the first accessible one
        foreach ($candidates as $candidate) {
            if ($this->canAccessResource($user, $candidate['type'], $candidate['id'])) {
                return $candidate['url'];
            }
        }

        return null;
    }

    /**
     * Get effective permissions for a user (for UI display).
     * Returns denied resources and whitelist info after resolving groups + overrides.
     */
    public function getEffectivePermissions(string $userId): array {
        $result = ['app' => [], 'command' => [], 'module' => [], 'whitelist' => []];

        if ($this->isSuperAdminUser($userId)) {
            return $result;
        }

        // Get user overrides
        $stmt = $this->db->prepare(
            "SELECT resource_type, resource_id, action FROM user_permissions WHERE user_id = ?"
        );
        $stmt->execute([$userId]);
        $userOverrides = [];
        while ($row = $stmt->fetch()) {
            $userOverrides[$row['resource_type']][$row['resource_id']] = $row['action'];
        }

        // Get blacklist group denies
        $stmt = $this->db->prepare(
            "SELECT DISTINCT gp.resource_type, gp.resource_id FROM group_permissions gp
             JOIN group_members gm ON gm.group_id = gp.group_id
             JOIN permission_groups pg ON pg.id = gp.group_id
             WHERE gm.user_id = ? AND pg.mode = 'blacklist'"
        );
        $stmt->execute([$userId]);
        $blacklistDenies = [];
        while ($row = $stmt->fetch()) {
            $blacklistDenies[$row['resource_type']][$row['resource_id']] = true;
        }

        // Get whitelist group allows (and which types are covered)
        $stmt = $this->db->prepare(
            "SELECT DISTINCT gp.resource_type, gp.resource_id FROM group_permissions gp
             JOIN group_members gm ON gm.group_id = gp.group_id
             JOIN permission_groups pg ON pg.id = gp.group_id
             WHERE gm.user_id = ? AND pg.mode = 'whitelist'"
        );
        $stmt->execute([$userId]);
        $whitelistAllows = [];
        while ($row = $stmt->fetch()) {
            $whitelistAllows[$row['resource_type']][$row['resource_id']] = true;
        }

        // Build whitelist info for UI (which types are restricted, what's allowed)
        foreach (['app', 'command', 'module'] as $type) {
            if (!empty($whitelistAllows[$type])) {
                $result['whitelist'][$type] = array_keys($whitelistAllows[$type]);
            }
        }

        // Merge: user overrides take priority
        foreach (['app', 'command', 'module'] as $type) {
            $denied = [];

            // Blacklist denies
            foreach (($blacklistDenies[$type] ?? []) as $resourceId => $_) {
                if (isset($userOverrides[$type][$resourceId])) {
                    if ($userOverrides[$type][$resourceId] === 'deny') {
                        $denied[] = $resourceId;
                    }
                } else {
                    $denied[] = $resourceId;
                }
            }

            // User-only denies
            foreach (($userOverrides[$type] ?? []) as $resourceId => $action) {
                if ($action === 'deny' && !isset($blacklistDenies[$type][$resourceId])) {
                    $denied[] = $resourceId;
                }
            }

            $result[$type] = array_unique($denied);
        }

        return $result;
    }

    /**
     * Get detailed permission info for a user (shows source of each rule).
     */
    public function getUserPermissionDetails(string $userId): array {
        if ($this->isSuperAdminUser($userId)) {
            return [];
        }

        $details = [];

        // User overrides
        $stmt = $this->db->prepare(
            "SELECT resource_type, resource_id, action, created_at FROM user_permissions WHERE user_id = ?"
        );
        $stmt->execute([$userId]);
        while ($row = $stmt->fetch()) {
            $details[] = [
                'resource_type' => $row['resource_type'],
                'resource_id' => $row['resource_id'],
                'action' => $row['action'],
                'source' => 'user',
                'source_name' => 'User Override',
                'created_at' => $row['created_at'],
            ];
        }

        // Group rules
        $stmt = $this->db->prepare(
            "SELECT gp.resource_type, gp.resource_id, gp.action, gp.created_at, pg.name as group_name, pg.id as group_id, pg.mode as group_mode
             FROM group_permissions gp
             JOIN group_members gm ON gm.group_id = gp.group_id
             JOIN permission_groups pg ON pg.id = gp.group_id
             WHERE gm.user_id = ?"
        );
        $stmt->execute([$userId]);
        while ($row = $stmt->fetch()) {
            $details[] = [
                'resource_type' => $row['resource_type'],
                'resource_id' => $row['resource_id'],
                'action' => ($row['group_mode'] === 'whitelist') ? 'allow' : 'deny',
                'source' => 'group',
                'source_name' => $row['group_name'],
                'source_id' => $row['group_id'],
                'group_mode' => $row['group_mode'],
                'created_at' => $row['created_at'],
            ];
        }

        return $details;
    }

    // =========================================================================
    // GROUP CRUD
    // =========================================================================

    public function createGroup(string $name, string $description, string $createdBy, string $mode = 'blacklist'): array {
        if (!in_array($mode, ['blacklist', 'whitelist'])) {
            return ['success' => false, 'error' => 'Mode must be blacklist or whitelist'];
        }

        // Check uniqueness
        $stmt = $this->db->prepare("SELECT id FROM permission_groups WHERE name = ?");
        $stmt->execute([$name]);
        if ($stmt->fetch()) {
            return ['success' => false, 'error' => 'Group name already exists'];
        }

        $id = Database::generateUUID();
        $stmt = $this->db->prepare(
            "INSERT INTO permission_groups (id, name, description, mode, created_by) VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([$id, $name, $description, $mode, $createdBy]);

        return ['success' => true, 'id' => $id];
    }

    public function updateGroup(string $id, array $data): array {
        $group = $this->getGroup($id);
        if (!$group) {
            return ['success' => false, 'error' => 'Group not found'];
        }

        $updates = [];
        $params = [];

        if (isset($data['name'])) {
            $stmt = $this->db->prepare("SELECT id FROM permission_groups WHERE name = ? AND id != ?");
            $stmt->execute([$data['name'], $id]);
            if ($stmt->fetch()) {
                return ['success' => false, 'error' => 'Group name already exists'];
            }
            $updates[] = 'name = ?';
            $params[] = $data['name'];
        }

        if (isset($data['description'])) {
            $updates[] = 'description = ?';
            $params[] = $data['description'];
        }

        if (isset($data['mode']) && in_array($data['mode'], ['blacklist', 'whitelist'])) {
            $updates[] = 'mode = ?';
            $params[] = $data['mode'];
        }

        if (empty($updates)) {
            return ['success' => false, 'error' => 'No valid fields to update'];
        }

        $updates[] = "updated_at = datetime('now')";
        $params[] = $id;

        $stmt = $this->db->prepare("UPDATE permission_groups SET " . implode(', ', $updates) . " WHERE id = ?");
        $stmt->execute($params);

        return ['success' => true];
    }

    public function deleteGroup(string $id): array {
        $group = $this->getGroup($id);
        if (!$group) {
            return ['success' => false, 'error' => 'Group not found'];
        }

        $stmt = $this->db->prepare("DELETE FROM permission_groups WHERE id = ?");
        $stmt->execute([$id]);

        return ['success' => true];
    }

    public function getGroup(string $id): ?array {
        $stmt = $this->db->prepare("SELECT * FROM permission_groups WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function getAllGroups(): array {
        $stmt = $this->db->query("
            SELECT pg.*,
                   (SELECT COUNT(*) FROM group_members gm WHERE gm.group_id = pg.id) as member_count,
                   (SELECT COUNT(*) FROM group_permissions gp WHERE gp.group_id = pg.id) as rule_count,
                   pg.mode
            FROM permission_groups pg
            ORDER BY pg.name ASC
        ");
        return $stmt->fetchAll();
    }

    // =========================================================================
    // GROUP MEMBERSHIP
    // =========================================================================

    public function addUserToGroup(string $groupId, string $userId, string $addedBy): array {
        if ($this->isSuperAdminUser($userId)) {
            return ['success' => false, 'error' => 'Cannot add a super-admin account to a permission group.'];
        }

        $stmt = $this->db->prepare(
            "INSERT OR IGNORE INTO group_members (group_id, user_id, added_by) VALUES (?, ?, ?)"
        );
        $stmt->execute([$groupId, $userId, $addedBy]);

        return ['success' => true];
    }

    public function removeUserFromGroup(string $groupId, string $userId): array {
        $stmt = $this->db->prepare("DELETE FROM group_members WHERE group_id = ? AND user_id = ?");
        $stmt->execute([$groupId, $userId]);

        return ['success' => true];
    }

    public function getGroupMembers(string $groupId): array {
        $stmt = $this->db->prepare("
            SELECT u.id, u.username, u.name, u.email, u.role, u.active, gm.added_at
            FROM group_members gm
            JOIN users u ON u.id = gm.user_id
            WHERE gm.group_id = ?
            ORDER BY u.username ASC
        ");
        $stmt->execute([$groupId]);
        return $stmt->fetchAll();
    }

    public function getUserGroups(string $userId): array {
        if ($this->isSuperAdminUser($userId)) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT pg.*, gm.added_at
            FROM permission_groups pg
            JOIN group_members gm ON gm.group_id = pg.id
            WHERE gm.user_id = ?
            ORDER BY pg.name ASC
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    // =========================================================================
    // GROUP RULES (deny for blacklist, allow for whitelist)
    // =========================================================================

    /**
     * Set a group rule. Action is auto-determined by group mode:
     * blacklist groups → 'deny', whitelist groups → 'allow'.
     */
    public function setGroupRule(string $groupId, string $resourceType, string $resourceId, string $createdBy): array {
        $group = $this->getGroup($groupId);
        if (!$group) {
            return ['success' => false, 'error' => 'Group not found'];
        }

        // Always store 'deny' in DB (CHECK constraint only allows 'deny').
        // The group's mode determines the semantic meaning of the rule.
        $action = 'deny';
        $displayAction = ($group['mode'] === 'whitelist') ? 'allow' : 'deny';

        $stmt = $this->db->prepare(
            "INSERT OR REPLACE INTO group_permissions (group_id, resource_type, resource_id, action, created_by)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([$groupId, $resourceType, $resourceId, $action, $createdBy]);

        return ['success' => true, 'action' => $displayAction];
    }

    /** Backward-compatible alias */
    public function setGroupDeny(string $groupId, string $resourceType, string $resourceId, string $createdBy): array {
        return $this->setGroupRule($groupId, $resourceType, $resourceId, $createdBy);
    }

    public function removeGroupRule(string $groupId, string $resourceType, string $resourceId): array {
        $stmt = $this->db->prepare(
            "DELETE FROM group_permissions WHERE group_id = ? AND resource_type = ? AND resource_id = ?"
        );
        $stmt->execute([$groupId, $resourceType, $resourceId]);

        return ['success' => true];
    }

    /** Backward-compatible alias */
    public function removeGroupDeny(string $groupId, string $resourceType, string $resourceId): array {
        return $this->removeGroupRule($groupId, $resourceType, $resourceId);
    }

    public function getGroupRules(string $groupId): array {
        $group = $this->getGroup($groupId);
        $displayAction = ($group && $group['mode'] === 'whitelist') ? 'allow' : 'deny';

        $stmt = $this->db->prepare(
            "SELECT * FROM group_permissions WHERE group_id = ? ORDER BY resource_type, resource_id"
        );
        $stmt->execute([$groupId]);
        $rules = $stmt->fetchAll();

        // Override the stored action with the display action based on group mode
        foreach ($rules as &$rule) {
            $rule['action'] = $displayAction;
        }
        return $rules;
    }

    /** Backward-compatible alias */
    public function getGroupDenyRules(string $groupId): array {
        return $this->getGroupRules($groupId);
    }

    // =========================================================================
    // USER OVERRIDES
    // =========================================================================

    public function setUserOverride(string $userId, string $resourceType, string $resourceId, string $action, string $createdBy): array {
        if (!in_array($action, ['allow', 'deny'])) {
            return ['success' => false, 'error' => 'Action must be allow or deny'];
        }

        if ($this->isSuperAdminUser($userId)) {
            return ['success' => false, 'error' => 'Cannot set permission overrides for a super-admin account.'];
        }

        $stmt = $this->db->prepare(
            "INSERT OR REPLACE INTO user_permissions (user_id, resource_type, resource_id, action, created_by)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([$userId, $resourceType, $resourceId, $action, $createdBy]);

        return ['success' => true];
    }

    public function removeUserOverride(string $userId, string $resourceType, string $resourceId): array {
        $stmt = $this->db->prepare(
            "DELETE FROM user_permissions WHERE user_id = ? AND resource_type = ? AND resource_id = ?"
        );
        $stmt->execute([$userId, $resourceType, $resourceId]);

        return ['success' => true];
    }

    public function getUserOverrides(string $userId): array {
        if ($this->isSuperAdminUser($userId)) {
            return [];
        }

        $stmt = $this->db->prepare(
            "SELECT * FROM user_permissions WHERE user_id = ? ORDER BY resource_type, resource_id"
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    // =========================================================================
    // UTILITY
    // =========================================================================

    /**
     * Get canonical list of controllable modules.
     */
    public static function getModuleSlugs(): array {
        $labels = [];
        foreach (SystemModuleRegistry::getModuleDefinitions() as $moduleId => $definition) {
            $labels[$moduleId] = $definition['label'] ?? $moduleId;
        }

        return $labels;
    }

    private function getSystemSettingsManager(): SystemSettingsManager {
        if ($this->systemSettingsManager === null) {
            $this->systemSettingsManager = new SystemSettingsManager();
        }

        return $this->systemSettingsManager;
    }

    public function isSuperAdminUser(string $userId): bool {
        if ($userId === '') {
            return false;
        }

        $stmt = $this->db->prepare("
            SELECT 1
            FROM users
            WHERE id = ? AND role = 'super-admin'
            LIMIT 1
        ");
        $stmt->execute([$userId]);

        return (bool)$stmt->fetchColumn();
    }

    public function isOnlyActiveSuperAdmin(string $userId): bool {
        if ($userId === '') {
            return false;
        }

        $stmt = $this->db->prepare("
            SELECT role, active
            FROM users
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user || (string)($user['role'] ?? '') !== 'super-admin' || empty($user['active'])) {
            return false;
        }

        $stmt = $this->db->query("
            SELECT COUNT(*) AS count
            FROM users
            WHERE role = 'super-admin' AND active = 1
        ");

        return (int)($stmt->fetch()['count'] ?? 0) <= 1;
    }
}
