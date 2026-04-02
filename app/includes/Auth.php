<?php
/**
 * Auth.php - Authentication & Session Management
 * 
 * Part of Doki v3 Architecture - Batch 1: Auth & UI Foundation
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/PermissionManager.php';
require_once __DIR__ . '/session-bootstrap.php';

class Auth {
    private const SESSION_LIFETIME = 86400; // 24 hours
    private const MAX_LOGIN_ATTEMPTS = 5;
    private const LOCKOUT_DURATION = 900; // 15 minutes

    private PDO $db;
    private ?array $currentUser = null;
    private ?PermissionManager $permManager = null;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * Attempt to authenticate user with username/password
     */
    public function login(string $username, string $password, string $ip = '', string $userAgent = ''): array {
        // Check rate limiting
        if ($this->isRateLimited($username, $ip)) {
            return [
                'success' => false,
                'error' => 'Too many login attempts. Please try again later.',
                'code' => 429
            ];
        }

        // Find user
        $stmt = $this->db->prepare("SELECT * FROM users WHERE username = ? AND active = 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $this->recordFailedAttempt($username, $ip);
            $this->auditLog(null, $username, 'login_failed', 'auth', null, "Failed login attempt for user: $username", $ip);
            return [
                'success' => false,
                'error' => 'Invalid username or password.',
                'code' => 401
            ];
        }

        // Create session
        $sessionToken = $this->createSession($user['id'], $ip, $userAgent);
        
        // Update last login
        $stmt = $this->db->prepare("UPDATE users SET last_login = datetime('now') WHERE id = ?");
        $stmt->execute([$user['id']]);

        // Clear failed attempts
        $this->clearFailedAttempts($username, $ip);

        // Audit log
        $this->auditLog($user['id'], $user['username'], 'login_success', 'auth', null, null, $ip);

        return [
            'success' => true,
            'user' => [
                'id' => $user['id'],
                'username' => $user['username'],
                'name' => $user['name'],
                'email' => $user['email'],
                'role' => $user['role']
            ],
            'token' => $sessionToken
        ];
    }

    /**
     * Create a new session for user
     */
    private function createSession(string $userId, string $ip, string $userAgent): string {
        // Generate secure token
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $sessionId = Database::generateUUID();
        $expiresAt = date('Y-m-d H:i:s', time() + self::SESSION_LIFETIME);

        $stmt = $this->db->prepare("
            INSERT INTO sessions (id, user_id, token_hash, ip_address, user_agent, expires_at)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$sessionId, $userId, $tokenHash, $ip, $userAgent, $expiresAt]);

        return $token;
    }

    /**
     * Validate session token and return user if valid
     */
    public function validateSession(string $token): ?array {
        $tokenHash = hash('sha256', $token);
        
        $stmt = $this->db->prepare("
            SELECT s.*, u.* 
            FROM sessions s
            JOIN users u ON s.user_id = u.id
            WHERE s.token_hash = ?
            AND s.expires_at > datetime('now')
            AND u.active = 1
        ");
        $stmt->execute([$tokenHash]);
        $result = $stmt->fetch();

        if (!$result) {
            return null;
        }

        // Update last activity
        $stmt = $this->db->prepare("UPDATE sessions SET last_activity = datetime('now') WHERE token_hash = ?");
        $stmt->execute([$tokenHash]);

        $this->currentUser = [
            'id' => $result['user_id'],
            'username' => $result['username'],
            'name' => $result['name'],
            'email' => $result['email'],
            'role' => $result['role']
        ];

        return $this->currentUser;
    }

    /**
     * Get current authenticated user
     */
    public function getCurrentUser(): ?array {
        return $this->currentUser;
    }

    /**
     * Logout - destroy session
     */
    public function logout(string $token): void {
        $tokenHash = hash('sha256', $token);
        
        // Get user info for audit before destroying session
        $stmt = $this->db->prepare("
            SELECT s.user_id, u.username 
            FROM sessions s 
            JOIN users u ON s.user_id = u.id 
            WHERE s.token_hash = ?
        ");
        $stmt->execute([$tokenHash]);
        $session = $stmt->fetch();

        if ($session) {
            $this->auditLog($session['user_id'], $session['username'], 'logout', 'auth', null, null, null);
        }

        $stmt = $this->db->prepare("DELETE FROM sessions WHERE token_hash = ?");
        $stmt->execute([$tokenHash]);
    }

    /**
     * Check if user has required role
     */
    public function hasRole(string $requiredRole): bool {
        if (!$this->currentUser) {
            return false;
        }

        $roleHierarchy = [
            'super-admin' => 3,
            'admin' => 2,
            'user' => 1
        ];

        $userLevel = $roleHierarchy[$this->currentUser['role']] ?? 0;
        $requiredLevel = $roleHierarchy[$requiredRole] ?? 0;

        return $userLevel >= $requiredLevel;
    }

    /**
     * Check if current user can access a resource
     */
    public function canAccess(string $resource): bool {
        $permissions = [
            'users_manage' => ['super-admin'],
            'config_edit' => ['super-admin', 'admin'],
            'secrets_view' => ['super-admin', 'admin'],
            'secrets_edit' => ['super-admin', 'admin'],
            'commands_run' => ['super-admin', 'admin', 'user'],
            'reports_view' => ['super-admin', 'admin', 'user'],
        ];

        if (!isset($permissions[$resource])) {
            return false;
        }

        return in_array($this->currentUser['role'] ?? '', $permissions[$resource]);
    }

    /**
     * Check if current user can access a specific resource (granular permissions).
     * Combines role-based checks with group/user permission overrides.
     */
    public function canAccessResource(string $resourceType, string $resourceId): bool {
        if (!$this->currentUser) {
            return false;
        }
        return $this->getPermissionManager()->canAccessResource(
            $this->currentUser, $resourceType, $resourceId
        );
    }

    /**
     * Get the PermissionManager instance (lazy-loaded).
     */
    public function getPermissionManager(): PermissionManager {
        if ($this->permManager === null) {
            $this->permManager = new PermissionManager();
        }
        return $this->permManager;
    }

    /**
     * Rate limiting - check if user/IP is locked out
     */
    private function isRateLimited(string $username, string $ip): bool {
        // This uses PHP session for simplicity
        // Could be moved to database for distributed systems
        if (session_status() === PHP_SESSION_NONE) {
            doki_start_session();
        }

        $key = "login_attempts_{$username}_{$ip}";
        $attempts = $_SESSION[$key] ?? ['count' => 0, 'first_attempt' => time()];

        // Reset if lockout period has passed
        if (time() - $attempts['first_attempt'] > self::LOCKOUT_DURATION) {
            unset($_SESSION[$key]);
            return false;
        }

        return $attempts['count'] >= self::MAX_LOGIN_ATTEMPTS;
    }

    private function recordFailedAttempt(string $username, string $ip): void {
        if (session_status() === PHP_SESSION_NONE) {
            doki_start_session();
        }

        $key = "login_attempts_{$username}_{$ip}";
        $attempts = $_SESSION[$key] ?? ['count' => 0, 'first_attempt' => time()];
        $attempts['count']++;
        $_SESSION[$key] = $attempts;
    }

    private function clearFailedAttempts(string $username, string $ip): void {
        if (session_status() === PHP_SESSION_NONE) {
            doki_start_session();
        }

        $key = "login_attempts_{$username}_{$ip}";
        unset($_SESSION[$key]);
    }

    /**
     * Audit logging
     */
    public function auditLog(
        ?string $userId,
        ?string $username,
        string $action,
        ?string $resourceType = null,
        ?string $resourceId = null,
        ?string $details = null,
        ?string $ip = null
    ): void {
        $stmt = $this->db->prepare("
            INSERT INTO audit_log (user_id, username, action, resource_type, resource_id, details, ip_address)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$userId, $username, $action, $resourceType, $resourceId, $details, $ip]);
    }

    /**
     * Clean up expired sessions
     */
    public function cleanupExpiredSessions(): int {
        $stmt = $this->db->prepare("DELETE FROM sessions WHERE expires_at < datetime('now')");
        $stmt->execute();
        return $stmt->rowCount();
    }

    /**
     * Revoke all active sessions for a specific user.
     */
    public function revokeUserSessions(string $userId): int {
        $stmt = $this->db->prepare("DELETE FROM sessions WHERE user_id = ?");
        $stmt->execute([$userId]);
        return $stmt->rowCount();
    }

    // =====================
    // User Management
    // =====================

    /**
     * Get all users (for admin panel)
     */
    public function getAllUsers(): array {
        $stmt = $this->db->query("
            SELECT id, username, email, name, role, active, created_at, last_login
            FROM users
            ORDER BY created_at DESC
        ");
        return $stmt->fetchAll();
    }

    /**
     * Get user by ID
     */
    public function getUserById(string $id): ?array {
        $stmt = $this->db->prepare("
            SELECT id, username, email, name, role, active, created_at, last_login
            FROM users WHERE id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Create new user
     */
    public function createUser(string $username, string $email, string $name, string $password, string $role): array {
        // Validate role
        if (!in_array($role, ['super-admin', 'admin', 'user'])) {
            return ['success' => false, 'error' => 'Invalid role'];
        }

        // Check uniqueness
        $stmt = $this->db->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $email]);
        if ($stmt->fetch()) {
            return ['success' => false, 'error' => 'Username or email already exists'];
        }

        $id = Database::generateUUID();
        $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        $stmt = $this->db->prepare("
            INSERT INTO users (id, username, email, name, password_hash, role)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$id, $username, $email, $name, $passwordHash, $role]);

        $this->auditLog(
            $this->currentUser['id'] ?? null,
            $this->currentUser['username'] ?? 'system',
            'user_created',
            'user',
            $id,
            "Created user: $username"
        );

        return ['success' => true, 'id' => $id];
    }

    /**
     * Update user
     */
    public function updateUser(string $id, array $data): array {
        $user = $this->getUserById($id);
        if (!$user) {
            return ['success' => false, 'error' => 'User not found'];
        }

        $currentRole = (string)($user['role'] ?? '');
        $currentActive = !empty($user['active']);
        $nextRole = (isset($data['role']) && in_array($data['role'], ['super-admin', 'admin', 'user'], true))
            ? (string)$data['role']
            : $currentRole;
        $nextActive = array_key_exists('active', $data)
            ? !empty($data['active'])
            : $currentActive;

        if (
            $currentRole === 'super-admin'
            && $currentActive
            && ($nextRole !== 'super-admin' || !$nextActive)
            && $this->countActiveSuperAdmins() <= 1
        ) {
            if ($this->currentUser && $this->currentUser['id'] === $id) {
                return ['success' => false, 'error' => 'Cannot change the role or active status of your account while it is the only active super-admin.'];
            }

            return ['success' => false, 'error' => 'Cannot change the role or active status of the only active super-admin account.'];
        }

        $updates = [];
        $params = [];

        if (isset($data['name'])) {
            $updates[] = 'name = ?';
            $params[] = $data['name'];
        }

        if (isset($data['email'])) {
            // Check uniqueness
            $stmt = $this->db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $stmt->execute([$data['email'], $id]);
            if ($stmt->fetch()) {
                return ['success' => false, 'error' => 'Email already exists'];
            }
            $updates[] = 'email = ?';
            $params[] = $data['email'];
        }

        if (isset($data['role']) && in_array($data['role'], ['super-admin', 'admin', 'user'])) {
            $updates[] = 'role = ?';
            $params[] = $data['role'];
        }

        if (isset($data['active'])) {
            $updates[] = 'active = ?';
            $params[] = $data['active'] ? 1 : 0;
        }

        if (isset($data['password']) && !empty($data['password'])) {
            $updates[] = 'password_hash = ?';
            $params[] = password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => 12]);
        }

        if (empty($updates)) {
            return ['success' => false, 'error' => 'No valid fields to update'];
        }

        $updates[] = "updated_at = datetime('now')";
        $params[] = $id;

        $stmt = $this->db->prepare("UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?");
        $stmt->execute($params);

        $this->auditLog(
            $this->currentUser['id'] ?? null,
            $this->currentUser['username'] ?? 'system',
            'user_updated',
            'user',
            $id,
            "Updated user: {$user['username']}"
        );

        return ['success' => true];
    }

    /**
     * Delete user
     */
    public function deleteUser(string $id): array {
        $user = $this->getUserById($id);
        if (!$user) {
            return ['success' => false, 'error' => 'User not found'];
        }

        // Can't delete self
        if ($this->currentUser && $this->currentUser['id'] === $id) {
            return ['success' => false, 'error' => 'Cannot delete your own account'];
        }

        if (
            (string)($user['role'] ?? '') === 'super-admin'
            && !empty($user['active'])
            && $this->countActiveSuperAdmins() <= 1
        ) {
            return ['success' => false, 'error' => 'Cannot delete the only active super-admin account.'];
        }

        $stmt = $this->db->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$id]);

        $this->auditLog(
            $this->currentUser['id'] ?? null,
            $this->currentUser['username'] ?? 'system',
            'user_deleted',
            'user',
            $id,
            "Deleted user: {$user['username']}"
        );

        return ['success' => true];
    }

    private function countActiveSuperAdmins(): int {
        $stmt = $this->db->query("
            SELECT COUNT(*) AS count
            FROM users
            WHERE role = 'super-admin' AND active = 1
        ");

        return (int)($stmt->fetch()['count'] ?? 0);
    }

    /**
     * Change own password
     */
    public function changePassword(string $userId, string $currentPassword, string $newPassword): array {
        $stmt = $this->db->prepare("SELECT password_hash FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
            return ['success' => false, 'error' => 'Current password is incorrect'];
        }

        $newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        $stmt = $this->db->prepare("UPDATE users SET password_hash = ?, updated_at = datetime('now') WHERE id = ?");
        $stmt->execute([$newHash, $userId]);

        $this->auditLog(
            $userId,
            $this->currentUser['username'] ?? 'unknown',
            'password_changed',
            'user',
            $userId,
            'User changed their password'
        );

        return ['success' => true];
    }
}
