<?php
/**
 * AppContext - Unified access to Doki infrastructure for apps
 * 
 * Provides capability-gated access to all Doki services.
 * Apps use this class to interact with the platform safely.
 */

require_once __DIR__ . '/AppCapabilities.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Layout.php';
require_once __DIR__ . '/ConfigManager.php';
require_once __DIR__ . '/Orchestrator.php';
require_once __DIR__ . '/AppManager.php';
require_once __DIR__ . '/PermissionManager.php';
require_once __DIR__ . '/OnboardingManager.php';
require_once __DIR__ . '/StealthGuard.php';
require_once __DIR__ . '/session-bootstrap.php';

class AppContext {
    private string $appId;
    private array $manifest;
    private int $trustLevel;
    private array $capabilities;
    private ?array $user = null;
    private Auth $auth;
    private PDO $db;
    private ?Layout $layout = null;
    private ConfigManager $config;
    private ?Orchestrator $orchestrator = null;
    private string $storagePath;
    private ?string $execTargetId = null;
    private bool $isWorkspacePreview = false;
    private ?string $previewWorkspaceId = null;
    private ?string $previewAppRoot = null;
    
    public function __construct(string $appId, ?string $previewWorkspaceId = null, bool $trackUsage = true) {
        $this->appId = $appId;
        $this->db = Database::getInstance();
        $this->auth = new Auth();
        $this->config = new ConfigManager();
        $this->resolvePreviewContext($previewWorkspaceId);
        
        $this->loadAppInfo();
        $this->capabilities = AppCapabilities::forTrustLevel($this->trustLevel);
        if ($this->isWorkspacePreview && $this->previewWorkspaceId !== null) {
            $this->storagePath = __DIR__ . "/../data/workspaces/{$this->previewWorkspaceId}/preview-data";
        } else {
            $this->storagePath = __DIR__ . "/../data/apps/{$this->appId}";
        }
        
        // Ensure storage directory exists
        if (!is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0755, true);
        }
        
        // Track app usage for "Recently Used" feature (Batch 2)
        if ($trackUsage && !$this->isWorkspacePreview) {
            $appManager = new AppManager();
            $appManager->trackAppUsage($this->appId);
        }
    }

    /**
     * Detect if the current request is running from a workspace preview copy.
     */
    private function resolvePreviewContext(?string $previewWorkspaceId = null): void {
        if (is_string($previewWorkspaceId) && $previewWorkspaceId !== '') {
            $this->isWorkspacePreview = true;
            $this->previewWorkspaceId = $previewWorkspaceId;
            $this->previewAppRoot = __DIR__ . '/../studio-previews/' . $previewWorkspaceId;
            return;
        }

        $scriptFilename = $_SERVER['SCRIPT_FILENAME'] ?? '';
        if (!is_string($scriptFilename) || $scriptFilename === '') {
            return;
        }

        $normalized = str_replace('\\', '/', $scriptFilename);
        if (preg_match('#/studio-previews/([a-z0-9_-]+)(?:/|$)#i', $normalized, $matches) !== 1) {
            return;
        }

        $this->isWorkspacePreview = true;
        $this->previewWorkspaceId = $matches[1];
        $this->previewAppRoot = __DIR__ . '/../studio-previews/' . $this->previewWorkspaceId;
    }
    
    /**
     * Load app manifest and trust level
     */
    private function loadAppInfo(): void {
        // Load manifest
        $manifestPath = $this->isWorkspacePreview && $this->previewAppRoot
            ? $this->previewAppRoot . '/manifest.yaml'
            : AppManager::getAppManifestPath($this->appId);
        if (is_string($manifestPath) && file_exists($manifestPath) && function_exists('yaml_parse_file')) {
            $this->manifest = yaml_parse_file($manifestPath) ?: [];
        } else {
            $this->manifest = ['id' => $this->appId, 'name' => $this->appId];
        }

        if (!empty($this->manifest['id']) && is_string($this->manifest['id'])) {
            $this->appId = $this->manifest['id'];
        }

        if ($this->isWorkspacePreview) {
            $trustProfile = AppCapabilities::getManifestTrustProfile($this->manifest);
            $this->trustLevel = $trustProfile['minimumTrustLevel'] ?? AppCapabilities::TRUST_SANDBOXED;
            $this->execTargetId = null;
            return;
        }
        
        // Load trust level from installed apps registry
        $installed = ['apps' => AppManager::loadInstalledAppsRegistry()];
        
        // Default to sandboxed, core apps have explicit level 0
        $appInfo = $installed['apps'][$this->appId] ?? null;
        if ($appInfo) {
            $this->trustLevel = $appInfo['trustLevel'] ?? AppCapabilities::TRUST_SANDBOXED;
            $this->execTargetId = $appInfo['execTarget'] ?? null;
        } else {
            // Check if it's a core app (in manifest)
            $this->trustLevel = $this->manifest['core'] ?? false 
                ? AppCapabilities::TRUST_CORE 
                : AppCapabilities::TRUST_SANDBOXED;
        }
    }
    
    // ==================
    // CAPABILITY CHECKING
    // ==================
    
    /**
     * Check if app has a capability
     */
    public function can(string $capability): bool {
        if (in_array('*', $this->capabilities)) {
            return true;
        }
        return in_array($capability, $this->capabilities);
    }
    
    /**
     * Require a capability or throw exception
     */
    public function require(string $capability): void {
        if (!$this->can($capability)) {
            $this->audit('capability_denied', $capability, "Attempted: {$capability}");
            throw new AppCapabilityException(
                "App '{$this->appId}' lacks capability: {$capability}",
                $capability,
                $this->appId
            );
        }
    }
    
    /**
     * Get app's trust level
     */
    public function getTrustLevel(): int {
        return $this->trustLevel;
    }
    
    /**
     * Get trust level info
     */
    public function getTrustLevelInfo(): array {
        return AppCapabilities::getTrustLevelInfo($this->trustLevel);
    }
    
    // ==================
    // AUTHENTICATION
    // ==================
    
    /**
     * Require authentication
     */
    public function requireAuth(): array {
        $this->require(AppCapabilities::AUTH_READ_USER);

        $onboarding = new OnboardingManager();
        if ($onboarding->requiresOnboarding()) {
            header('Location: /onboarding.php');
            exit;
        }
        
        if (session_status() === PHP_SESSION_NONE) {
            doki_start_session();
        }
        $token = $_SESSION['auth_token'] ?? null;
        
        if (!$token) {
            if (StealthGuard::isEnabled()) {
                header('Location: /');
            } else {
                header('Location: /login.php');
            }
            exit;
        }
        
        $this->user = $this->auth->validateSession($token);
        if (!$this->user) {
            if (StealthGuard::isEnabled()) {
                header('Location: /');
            } else {
                header('Location: /login.php');
            }
            exit;
        }

        // Check granular app permissions (whitelist/blacklist groups)
        if (!$this->isWorkspacePreview && $this->user['role'] !== 'super-admin') {
            $permManager = new PermissionManager();
            if (!$permManager->canAccessResource($this->user, 'app', $this->appId)) {
                http_response_code(403);
                echo '<!DOCTYPE html><html><head><title>Access Denied</title></head>';
                echo '<body style="font-family: sans-serif; text-align: center; padding: 50px;">';
                echo '<h1>403 - Access Denied</h1>';
                echo '<p>You do not have permission to access this app.</p>';
                echo '<a href="/">Return to Home</a>';
                echo '</body></html>';
                exit;
            }
        }

        return $this->user;
    }
    
    /**
     * Require a specific role
     */
    public function requireRole(string $role): array {
        $user = $this->requireAuth();
        
        $this->require(AppCapabilities::AUTH_CHECK_ROLES);
        
        $roleHierarchy = ['super-admin' => 3, 'admin' => 2, 'user' => 1];
        $userLevel = $roleHierarchy[$user['role']] ?? 0;
        $requiredLevel = $roleHierarchy[$role] ?? 999;
        
        if ($userLevel < $requiredLevel) {
            http_response_code(403);
            die('Access denied: insufficient privileges');
        }
        
        return $user;
    }
    
    /**
     * Get current user (null if not authenticated)
     */
    public function getUser(): ?array {
        return $this->user;
    }
    
    /**
     * Check if user has a role
     */
    public function hasRole(string $role): bool {
        if (!$this->user) return false;
        
        $roleHierarchy = ['super-admin' => 3, 'admin' => 2, 'user' => 1];
        $userLevel = $roleHierarchy[$this->user['role']] ?? 0;
        $requiredLevel = $roleHierarchy[$role] ?? 999;
        
        return $userLevel >= $requiredLevel;
    }

    /**
     * Check if the app is running in workspace preview mode.
     */
    public function isPreviewMode(): bool {
        return $this->isWorkspacePreview;
    }

    /**
     * Get the current preview workspace ID, if any.
     */
    public function getPreviewWorkspaceId(): ?string {
        return $this->previewWorkspaceId;
    }
    
    // ==================
    // AUDIT LOGGING
    // ==================
    
    /**
     * Write an audit log entry
     */
    public function audit(string $action, ?string $resourceId = null, ?string $details = null): void {
        $this->require(AppCapabilities::AUDIT_WRITE);
        
        $this->auth->auditLog(
            $this->user['id'] ?? null,
            $this->user['username'] ?? 'system',
            "{$this->appId}.{$action}",
            $this->appId,
            $resourceId,
            $details,
            $_SERVER['REMOTE_ADDR'] ?? null
        );
    }
    
    // ==================
    // DATABASE
    // ==================
    
    /**
     * Get database connection
     */
    public function getDatabase(): PDO {
        $this->require(AppCapabilities::DB_APP_TABLES);
        return $this->db;
    }
    
    /**
     * Execute a query (validates table access for non-full DB)
     */
    public function query(string $sql, array $params = []): \PDOStatement {
        $this->require(AppCapabilities::DB_APP_TABLES);
        
        if (!$this->can(AppCapabilities::DB_FULL)) {
            $this->validateAppQuery($sql);
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
    
    /**
     * Validate query only accesses app tables
     */
    private function validateAppQuery(string $sql): void {
        $appPrefix = "app_{$this->appId}_";
        
        // Check for table names that aren't app-prefixed
        // This is a simple check - could be more robust
        $dangerousTables = ['users', 'sessions', 'audit_log', 'schema_version'];
        
        foreach ($dangerousTables as $table) {
            if (preg_match("/\b{$table}\b/i", $sql)) {
                throw new AppCapabilityException(
                    "Query accesses restricted table: {$table}",
                    AppCapabilities::DB_FULL,
                    $this->appId
                );
            }
        }
    }
    
    /**
     * Create an app-namespaced table
     */
    public function createTable(string $tableName, string $schema): void {
        $this->require(AppCapabilities::DB_APP_TABLES);
        
        $fullTableName = "app_{$this->appId}_{$tableName}";
        $sql = "CREATE TABLE IF NOT EXISTS {$fullTableName} ({$schema})";
        $this->db->exec($sql);
    }
    
    /**
     * Get the full name of an app table
     */
    public function tableName(string $name): string {
        return "app_{$this->appId}_{$name}";
    }
    
    // ==================
    // FILE STORAGE
    // ==================
    
    /**
     * Get app's storage path
     */
    public function getStoragePath(): string {
        $this->require(AppCapabilities::FS_APP_DATA);
        return $this->storagePath;
    }
    
    /**
     * Read a file from app storage
     */
    public function readFile(string $path): ?string {
        $this->require(AppCapabilities::FS_APP_DATA);
        
        $fullPath = $this->resolvePath($path);
        $this->validatePathAccess($fullPath);
        
        if (!file_exists($fullPath)) {
            return null;
        }
        
        return file_get_contents($fullPath);
    }
    
    /**
     * Write a file to app storage
     */
    public function writeFile(string $path, string $content): bool {
        $this->require(AppCapabilities::FS_APP_DATA);
        
        $fullPath = $this->resolvePath($path);
        $this->validatePathAccess($fullPath);
        
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        return file_put_contents($fullPath, $content) !== false;
    }
    
    /**
     * Delete a file from app storage
     */
    public function deleteFile(string $path): bool {
        $this->require(AppCapabilities::FS_APP_DATA);
        
        $fullPath = $this->resolvePath($path);
        $this->validatePathAccess($fullPath);
        
        if (file_exists($fullPath)) {
            return unlink($fullPath);
        }
        
        return false;
    }
    
    /**
     * Resolve a path relative to app storage
     */
    private function resolvePath(string $path): string {
        // If already absolute and within storage, use as-is
        if (strpos($path, $this->storagePath) === 0) {
            return $this->normalizePathString($path);
        }
        
        // Otherwise, make relative to storage
        return $this->normalizePathString($this->storagePath . '/' . ltrim($path, '/'));
    }
    
    /**
     * Validate path access
     */
    private function validatePathAccess(string $path): void {
        $realStoragePath = $this->normalizePathString(realpath($this->storagePath) ?: $this->storagePath);
        $realPath = realpath($path);
        if ($realPath === false) {
            $realPath = $this->normalizePathString($path);
        } else {
            $realPath = $this->normalizePathString($realPath);
        }
        
        // Must be within app storage directory
        if ($realPath !== $realStoragePath && strpos($realPath, $realStoragePath . '/') !== 0) {
            if (!$this->can(AppCapabilities::FS_OTHER_APPS)) {
                throw new AppCapabilityException(
                    "Path access denied: {$path}",
                    AppCapabilities::FS_OTHER_APPS,
                    $this->appId
                );
            }
        }
    }

    /**
     * Normalize a filesystem path even when the target does not exist yet.
     */
    private function normalizePathString(string $path): string {
        $normalized = str_replace('\\', '/', $path);
        if ($normalized === '') {
            return '';
        }

        $prefix = '';
        if (preg_match('/^[A-Za-z]:/', $normalized, $matches) === 1) {
            $prefix = strtoupper($matches[0]);
            $normalized = substr($normalized, strlen($matches[0]));
        }

        if (str_starts_with($normalized, '/')) {
            $prefix .= '/';
            $normalized = ltrim($normalized, '/');
        }

        $parts = [];
        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if (!empty($parts) && end($parts) !== '..') {
                    array_pop($parts);
                    continue;
                }

                if ($prefix === '') {
                    $parts[] = '..';
                }
                continue;
            }

            $parts[] = $segment;
        }

        $result = $prefix . implode('/', $parts);
        if ($result === '') {
            return $prefix !== '' ? $prefix : '.';
        }

        return $result;
    }
    
    // ==================
    // HISTORY & BACKUPS
    // ==================
    
    /**
     * Create a backup of a file
     */
    public function createBackup(string $filename): ?string {
        $this->require(AppCapabilities::FS_APP_DATA);
        
        $historyDir = $this->storagePath . '/.history';
        if (!is_dir($historyDir)) {
            mkdir($historyDir, 0755, true);
        }
        
        $sourcePath = $this->resolvePath($filename);
        if (!file_exists($sourcePath)) {
            return null;
        }
        
        $timestamp = date('Y-m-d_H-i-s');
        $backupName = pathinfo($filename, PATHINFO_FILENAME) . ".{$timestamp}." . pathinfo($filename, PATHINFO_EXTENSION);
        $backupPath = $historyDir . '/' . $backupName;
        
        copy($sourcePath, $backupPath);
        
        return $backupPath;
    }
    
    /**
     * Get backups for a file
     */
    public function getBackups(string $filename): array {
        $this->require(AppCapabilities::FS_APP_DATA);
        
        $historyDir = $this->storagePath . '/.history';
        if (!is_dir($historyDir)) {
            return [];
        }
        
        $baseName = pathinfo($filename, PATHINFO_FILENAME);
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $pattern = $historyDir . "/{$baseName}.*.{$ext}";
        
        $files = glob($pattern);
        $backups = [];
        
        foreach ($files as $file) {
            $backups[] = [
                'filename' => basename($file),
                'path' => $file,
                'timestamp' => filemtime($file),
                'date' => date('Y-m-d H:i:s', filemtime($file)),
                'size' => filesize($file),
            ];
        }
        
        usort($backups, fn($a, $b) => $b['timestamp'] - $a['timestamp']);
        
        return $backups;
    }
    
    // ==================
    // CONFIGURATION
    // ==================
    
    /**
     * Get app settings
     */
    public function getSettings(): array {
        $settingsPath = $this->storagePath . '/settings.yaml';
        
        if (file_exists($settingsPath) && function_exists('yaml_parse_file')) {
            return yaml_parse_file($settingsPath) ?: [];
        }
        
        return $this->manifest['defaultSettings'] ?? [];
    }
    
    /**
     * Save app settings
     */
    public function saveSettings(array $settings): array {
        $this->require(AppCapabilities::FS_APP_DATA);
        
        $settingsPath = $this->storagePath . '/settings.yaml';
        
        // Create backup
        if (file_exists($settingsPath)) {
            $this->createBackup('settings.yaml');
        }
        
        if (function_exists('yaml_emit')) {
            file_put_contents($settingsPath, yaml_emit($settings));
        } else {
            file_put_contents($settingsPath, json_encode($settings, JSON_PRETTY_PRINT));
        }
        
        $this->audit('settings_save', null, 'Saved app settings');
        
        return ['success' => true];
    }
    
    /**
     * Get targets configuration
     */
    public function getTargets(): array {
        $this->require(AppCapabilities::CONFIG_TARGETS);
        return $this->config->getTargets();
    }
    
    /**
     * Get a specific target
     */
    public function getTarget(string $id): ?array {
        $this->require(AppCapabilities::CONFIG_TARGETS);
        return $this->config->getTarget($id);
    }
    
    /**
     * Get environments configuration
     */
    public function getEnvironments(): array {
        $this->require(AppCapabilities::CONFIG_ENVIRONMENTS);
        return $this->config->getEnvironments();
    }
    
    /**
     * Get a specific environment
     */
    public function getEnvironment(string $id): ?array {
        $this->require(AppCapabilities::CONFIG_ENVIRONMENTS);
        return $this->config->getEnvironment($id);
    }
    
    /**
     * Get a secret value (audited)
     */
    public function getSecret(string $key): ?string {
        $this->require(AppCapabilities::CONFIG_SECRETS);
        $this->audit('secret_access', $key, "Accessed secret: {$key}");
        return $this->config->getSecretValue($key);
    }
    
    // ==================
    // EXECUTION
    // ==================
    
    /**
     * Execute a command
     */
    public function execute(array $command): array {
        $this->require(AppCapabilities::EXEC_COMMANDS);
        
        if (!$this->orchestrator) {
            $this->orchestrator = new Orchestrator();
        }
        
        $this->audit('command_execute', $command['id'] ?? 'inline', json_encode($command));
        
        return $this->orchestrator->execute(
            $command,
            $this->user['id'] ?? 'system',
            $this->user['username'] ?? 'system'
        );
    }
    
    /**
     * Get a job status
     */
    public function getJob(string $jobId): ?array {
        $this->require(AppCapabilities::EXEC_COMMANDS);
        
        if (!$this->orchestrator) {
            $this->orchestrator = new Orchestrator();
        }
        
        return $this->orchestrator->getJob($jobId);
    }
    
    // ==================
    // GIT OPERATIONS
    // ==================
    
    /**
     * Get configured Git providers
     * Uses SourcesManager for centralized credential management (Batch 13)
     */
    public function getGitProviders(): array {
        $this->require(AppCapabilities::GIT_PROVIDERS);
        
        // Use SourcesManager for centralized Git providers
        require_once __DIR__ . '/SourcesManager.php';
        $sourcesManager = new SourcesManager();
        
        return $sourcesManager->getProviders();
    }
    
    /**
     * Clone a repository
     */
    public function cloneRepository(string $url, string $targetPath, ?string $providerId = null, string $branch = 'main'): array {
        $this->require(AppCapabilities::GIT_PROVIDERS);
        
        $fullPath = $this->resolvePath($targetPath);
        $this->validatePathAccess($fullPath);
        
        // Get authenticated URL if provider specified
        $authUrl = $this->getAuthenticatedGitUrl($url, $providerId);
        
        // Execute git clone
        $cmd = sprintf(
            'git clone --branch %s --single-branch %s %s 2>&1',
            escapeshellarg($branch),
            escapeshellarg($authUrl),
            escapeshellarg($fullPath)
        );
        
        exec($cmd, $output, $exitCode);
        
        $this->audit('git_clone', $url, "Cloned to: {$targetPath}");
        
        if ($exitCode !== 0) {
            return ['success' => false, 'error' => $this->sanitizeGitOutput($output)];
        }
        
        return ['success' => true];
    }
    
    /**
     * Pull latest from repository
     */
    public function pullRepository(string $path): array {
        $this->require(AppCapabilities::GIT_PROVIDERS);
        
        $fullPath = $this->resolvePath($path);
        $this->validatePathAccess($fullPath);
        
        $cmd = sprintf('cd %s && git pull 2>&1', escapeshellarg($fullPath));
        exec($cmd, $output, $exitCode);
        
        $this->audit('git_pull', $path);
        
        if ($exitCode !== 0) {
            return ['success' => false, 'error' => $this->sanitizeGitOutput($output)];
        }
        
        return ['success' => true, 'output' => implode("\n", $output)];
    }
    
    /**
     * Get authenticated Git URL
     * Uses SourcesManager for centralized credential management (Batch 13)
     */
    private function getAuthenticatedGitUrl(string $url, ?string $providerId): string {
        if (!$providerId) {
            return $url;
        }
        
        if (!$this->can(AppCapabilities::GIT_TOKENS)) {
            return $url; // Return plain URL if no token access
        }
        
        // Use SourcesManager for centralized Git provider credentials
        require_once __DIR__ . '/SourcesManager.php';
        $sourcesManager = new SourcesManager();
        
        return $sourcesManager->getAuthenticatedUrl($url, $providerId);
    }
    
    /**
     * Sanitize git output to remove sensitive info
     */
    private function sanitizeGitOutput(array $output): string {
        $text = implode("\n", $output);
        // Remove any tokens that might be in URLs
        $text = preg_replace('/https:\/\/[^@]+@/', 'https://***@', $text);
        return $text;
    }
    
    // ==================
    // UI
    // ==================
    
    /**
     * Get the Layout instance
     */
    public function getLayout(): Layout {
        $this->require(AppCapabilities::UI_LAYOUT);
        
        if (!$this->layout) {
            $this->layout = new Layout($this->user ?? [], "app-{$this->appId}");
        }
        
        return $this->layout;
    }
    
    /**
     * Get app manifest
     */
    public function getManifest(): array {
        return $this->manifest;
    }
    
    /**
     * Get app ID
     */
    public function getAppId(): string {
        return $this->appId;
    }
    
    /**
     * Render app header with icon and navigation
     */
    public function renderAppHeader(): void {
        $this->renderServiceStatusBanner();
        
        $nav = $this->manifest['navigation'] ?? [];
        $currentPage = basename($_SERVER['PHP_SELF']);
        $isPinned = false;
        $showPinButton = !$this->isWorkspacePreview;

        if ($showPinButton) {
            // Get current pinned status for this app
            $appManager = new AppManager();
            $userId = $this->user['id'] ?? null;
            $enabledApps = $appManager->getEnabledApps($userId);
            $currentApp = null;
            foreach ($enabledApps as $app) {
                if ($app['id'] === $this->appId) {
                    $currentApp = $app;
                    break;
                }
            }
            $isPinned = $currentApp['pinned'] ?? false;
        }
        ?>
        <div class="app-header">
            <div class="app-icon" style="background: linear-gradient(135deg, <?= htmlspecialchars($this->manifest['color'] ?? '#6366f1') ?> 0%, <?= htmlspecialchars($this->darkenColor($this->manifest['color'] ?? '#6366f1')) ?> 100%);">
                <i class="fas <?= htmlspecialchars($this->manifest['icon'] ?? 'fa-puzzle-piece') ?>"></i>
            </div>
            <div class="app-title">
                <h1><?= htmlspecialchars($this->manifest['name'] ?? $this->appId) ?></h1>
                <p><?= htmlspecialchars($this->manifest['description'] ?? '') ?></p>
            </div>
            <div class="app-header-actions">
                <?php if ($this->isWorkspacePreview): ?>
                <span class="preview-pill">
                    <i class="fas fa-flask"></i>
                    Workspace Preview
                </span>
                <a class="btn-icon-header" href="/admin/studio.php#workspace-<?= htmlspecialchars($this->previewWorkspaceId ?? '') ?>" title="Open App Studio">
                    <i class="fas fa-wrench"></i>
                </a>
                <?php endif; ?>
                <?php if ($showPinButton): ?>
                <button class="btn-icon-header <?= $isPinned ? 'active' : '' ?>" 
                        id="app-header-pin-btn"
                        onclick="toggleAppPin('<?= htmlspecialchars($this->appId) ?>', <?= $isPinned ? 'false' : 'true' ?>)"
                        title="<?= $isPinned ? 'Unpin' : 'Pin' ?> app">
                    <i class="fas fa-thumbtack"></i>
                </button>
                <?php endif; ?>
            </div>
        </div>
        
        <style>
            .app-header {
                position: relative;
            }
            
            .app-header-actions {
                margin-left: auto;
                display: flex;
                align-items: center;
                gap: 8px;
            }

            .preview-pill {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                padding: 8px 12px;
                border-radius: 999px;
                background: rgba(37, 99, 235, 0.12);
                border: 1px solid rgba(37, 99, 235, 0.25);
                color: #2563eb;
                font-size: 12px;
                font-weight: 600;
            }
            
            .btn-icon-header {
                display: flex;
                align-items: center;
                justify-content: center;
                width: 36px;
                height: 36px;
                padding: 0;
                background: var(--bg-tertiary);
                border: 1px solid var(--border-color);
                border-radius: 8px;
                color: var(--text-secondary);
                cursor: pointer;
                transition: all 0.2s;
                font-size: 14px;
            }
            
            .btn-icon-header:hover {
                background: var(--bg-hover);
                border-color: var(--accent-primary);
                color: var(--accent-primary);
            }
            
            .btn-icon-header.active {
                background: var(--accent-primary);
                border-color: var(--accent-primary);
                color: white;
            }
        </style>
        
        <script>
            async function toggleAppPin(appId, pin) {
                try {
                    const response = await fetch('/api/apps.php?action=set-pinned&app=' + appId, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ pinned: pin, global: false })
                    });
                    
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    
                    const data = await response.json();
                    
                    if (data.success) {
                        const btn = document.getElementById('app-header-pin-btn');
                        if (btn) {
                            btn.classList.toggle('active', pin);
                            btn.title = pin ? 'Unpin app' : 'Pin app';
                        }
                        if (window.Toast) {
                            window.Toast.success(pin ? 'App pinned' : 'App unpinned');
                        }
                    } else {
                        if (window.Toast) {
                            window.Toast.error(data.error || 'Failed to update pinned status');
                        }
                    }
                } catch (e) {
                    console.error('Pin toggle error:', e);
                    if (window.Toast) {
                        window.Toast.error('Failed to update pinned status: ' + e.message);
                    }
                }
            }
        </script>
        
        <?php if (!empty($nav)): ?>
        <nav class="app-nav">
            <?php foreach ($nav as $item): ?>
                <?php 
                $page = $item['page'] ?? 'index.php';
                $isActive = basename($page) === $currentPage;
                $adminOnly = $item['adminOnly'] ?? false;
                if ($adminOnly && !$this->hasRole('admin')) continue;
                $href = $this->isWorkspacePreview
                    ? '/studio-previews/' . rawurlencode($this->previewWorkspaceId ?? '') . '/' . ltrim($page, '/')
                    : $page;
                ?>
                <a href="<?= htmlspecialchars($href) ?>" 
                   class="app-nav-item <?= $isActive ? 'active' : '' ?>">
                    <i class="fas <?= htmlspecialchars($item['icon'] ?? 'fa-file') ?>"></i>
                    <?= htmlspecialchars($item['label'] ?? $item['id']) ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <?php endif;
    }
    
    /**
     * Darken a hex color by 20% for gradient
     */
    private function darkenColor(string $hex): string {
        // Remove # if present
        $hex = ltrim($hex, '#');
        
        // Convert to RGB
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        
        // Darken by 20%
        $r = max(0, min(255, $r * 0.8));
        $g = max(0, min(255, $g * 0.8));
        $b = max(0, min(255, $b * 0.8));
        
        // Convert back to hex
        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }
    
    /**
     * Render runtime status banner if needed
     * Shows status for image building and container state
     */
    private function renderRuntimeStatusBanner(): void {
        require_once __DIR__ . '/AppImageBuilder.php';
        
        $builder = new AppImageBuilder($this->appId, $this->manifest);
        
        if (!$builder->needsCustomImage()) {
            return; // No custom runtime needed
        }
        
        $fullStatus = $builder->getStatus();
        $buildStatus = $fullStatus['buildStatus'] ?? [];
        $statusType = $buildStatus['status'] ?? 'not_started';
        $imageExists = $fullStatus['imageExists'] ?? false;
        $containerRunning = $fullStatus['containerRunning'] ?? false;
        
        // If image exists and container running, no banner needed
        if ($imageExists && $containerRunning && $statusType !== 'stale') {
            return;
        }
        
        // Determine what to show
        $bannerClass = 'runtime-banner';
        $iconClass = 'fa-cog fa-spin';
        $message = 'Preparing app runtime...';
        $showRetry = false;
        $showStartContainer = false;
        
        if (!$imageExists) {
            // Image not built yet
            switch ($statusType) {
                case 'building':
                    $bannerClass .= ' runtime-banner-building';
                    $message = $buildStatus['message'] ?? 'Building custom runtime...';
                    break;
                case 'stale':
                    $bannerClass .= ' runtime-banner-building';
                    $message = $buildStatus['message'] ?? 'Runtime changed. Rebuilding custom runtime...';
                    break;
                case 'failed':
                    $bannerClass .= ' runtime-banner-error';
                    $iconClass = 'fa-exclamation-triangle';
                    $message = 'Runtime build failed. ';
                    $showRetry = true;
                    break;
                default:
                    $bannerClass .= ' runtime-banner-info';
                    $iconClass = 'fa-info-circle';
                    $message = 'Building app runtime (first time setup)...';
                    break;
            }
        } else if ($statusType === 'stale') {
            $bannerClass .= ' runtime-banner-info';
            $iconClass = 'fa-rotate';
            $message = $buildStatus['message'] ?? 'App runtime changed and needs a rebuild.';
        } else if (!$containerRunning) {
            // Image exists but container not running
            $bannerClass .= ' runtime-banner-info';
            $iconClass = 'fa-play-circle';
            $message = 'Starting app container...';
            $showStartContainer = true;
        }
        
        ?>
        <div class="<?= $bannerClass ?>" id="runtimeBanner" data-app-id="<?= htmlspecialchars($this->appId) ?>">
            <div class="runtime-banner-content">
                <i class="fas <?= $iconClass ?>" id="runtimeIcon"></i>
                <span id="runtimeMessage"><?= htmlspecialchars($message) ?></span>
                <?php if ($showRetry): ?>
                    <button onclick="retryBuild()" class="btn-action">Retry Build</button>
                <?php endif; ?>
                <?php if ($showStartContainer): ?>
                    <button onclick="startContainer()" class="btn-action btn-start">Start</button>
                <?php endif; ?>
            </div>
            <div class="runtime-progress" id="runtimeProgressContainer" style="display: none;">
                <div class="runtime-progress-bar" id="runtimeProgress"></div>
            </div>
        </div>
        
        <style>
            .runtime-banner {
                margin-bottom: 20px;
                padding: 16px 20px;
                border-radius: 12px;
                border: 1px solid;
                animation: slideDown 0.3s ease-out;
            }
            
            @keyframes slideDown {
                from { opacity: 0; transform: translateY(-10px); }
                to { opacity: 1; transform: translateY(0); }
            }
            
            .runtime-banner-building {
                background: rgba(59, 130, 246, 0.1);
                border-color: rgba(59, 130, 246, 0.3);
                color: #3b82f6;
            }
            
            .runtime-banner-info {
                background: rgba(245, 158, 11, 0.1);
                border-color: rgba(245, 158, 11, 0.3);
                color: #f59e0b;
            }
            
            .runtime-banner-error {
                background: rgba(239, 68, 68, 0.1);
                border-color: rgba(239, 68, 68, 0.3);
                color: #ef4444;
            }
            
            .runtime-banner-success {
                background: rgba(34, 197, 94, 0.1);
                border-color: rgba(34, 197, 94, 0.3);
                color: #22c55e;
            }
            
            .runtime-banner-content {
                display: flex;
                align-items: center;
                gap: 12px;
            }
            
            .runtime-banner-content i {
                font-size: 18px;
            }
            
            .runtime-banner-content span {
                flex: 1;
                font-weight: 500;
            }
            
            .btn-action {
                padding: 6px 16px;
                background: rgba(0, 0, 0, 0.1);
                border: 1px solid currentColor;
                border-radius: 6px;
                color: inherit;
                font-size: 13px;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.2s;
            }
            
            .btn-action:hover {
                background: rgba(0, 0, 0, 0.2);
            }
            
            .btn-start {
                background: rgba(34, 197, 94, 0.2);
                border-color: rgba(34, 197, 94, 0.5);
                color: #22c55e;
            }
            
            .runtime-progress {
                margin-top: 12px;
                height: 4px;
                background: rgba(255, 255, 255, 0.2);
                border-radius: 2px;
                overflow: hidden;
            }
            
            .runtime-progress-bar {
                height: 100%;
                background: currentColor;
                width: 0%;
                transition: width 0.3s ease;
                animation: progressPulse 2s ease-in-out infinite;
            }
            
            @keyframes progressPulse {
                0%, 100% { opacity: 1; }
                50% { opacity: 0.6; }
            }
        </style>
        
        <script>
            (function() {
                const banner = document.getElementById('runtimeBanner');
                if (!banner) return;
                
                const appId = banner.dataset.appId;
                let checkInterval;
                let progressSimulation = 0;
                let runtimeRecoveryTriggered = false;
                
                function showProgress() {
                    const container = document.getElementById('runtimeProgressContainer');
                    if (container) container.style.display = 'block';
                }
                
                function simulateProgress() {
                    const bar = document.getElementById('runtimeProgress');
                    if (!bar) return;
                    progressSimulation = Math.min(progressSimulation + Math.random() * 5, 85);
                    bar.style.width = progressSimulation + '%';
                }
                
                function updateBanner(type, icon, message) {
                    banner.className = 'runtime-banner runtime-banner-' + type;
                    document.getElementById('runtimeIcon').className = 'fas ' + icon;
                    document.getElementById('runtimeMessage').textContent = message;
                }
                
                async function checkStatus() {
                    try {
                        const res = await fetch('/api/apps.php?action=image-status&app=' + appId);
                        const data = await res.json();
                        
                        if (!data.success) return;
                        
                        const buildStatus = data.build?.status;
                        const imageExists = data.status?.imageExists;
                        const containerRunning = data.status?.containerRunning;
                        
                        if (buildStatus === 'stale') {
                            updateBanner('building', 'fa-cog fa-spin', data.build?.message || 'Runtime changed. Rebuilding...');
                            showProgress();
                            simulateProgress();
                            if (!runtimeRecoveryTriggered) {
                                runtimeRecoveryTriggered = true;
                                fetch('/api/apps.php?action=build-image&app=' + appId);
                            }
                        } else if (imageExists && containerRunning) {
                            // All ready - reload
                            clearInterval(checkInterval);
                            updateBanner('success', 'fa-check-circle', 'Runtime ready! Reloading...');
                            setTimeout(() => window.location.reload(), 1000);
                        } else if (imageExists && !containerRunning) {
                            // Need to start container
                            updateBanner('info', 'fa-play-circle', 'Starting app container...');
                            startContainer();
                        } else if (buildStatus === 'building') {
                            updateBanner('building', 'fa-cog fa-spin', data.build?.message || 'Building...');
                            showProgress();
                            simulateProgress();
                        } else if (buildStatus === 'failed') {
                            clearInterval(checkInterval);
                            updateBanner('error', 'fa-exclamation-triangle', 'Build failed: ' + (data.build?.message || 'Unknown error'));
                        } else if (!imageExists) {
                            // Trigger build
                            updateBanner('building', 'fa-cog fa-spin', 'Starting build...');
                            showProgress();
                            fetch('/api/apps.php?action=build-image&app=' + appId);
                        }
                    } catch (e) {
                        console.error('Status check error:', e);
                    }
                }
                
                window.retryBuild = function() {
                    updateBanner('building', 'fa-cog fa-spin', 'Retrying build...');
                    showProgress();
                    progressSimulation = 0;
                    fetch('/api/apps.php?action=rebuild-image&app=' + appId);
                    checkInterval = setInterval(checkStatus, 3000);
                };
                
                window.startContainer = async function() {
                    updateBanner('info', 'fa-cog fa-spin', 'Starting container...');
                    try {
                        const res = await fetch('/api/apps.php?action=start-container&app=' + appId);
                        const data = await res.json();
                        if (data.success) {
                            updateBanner('success', 'fa-check-circle', 'Container started! Reloading...');
                            setTimeout(() => window.location.reload(), 1000);
                        } else {
                            updateBanner('error', 'fa-exclamation-triangle', 'Failed to start: ' + (data.error || 'Unknown'));
                        }
                    } catch (e) {
                        updateBanner('error', 'fa-exclamation-triangle', 'Error: ' + e.message);
                    }
                };
                
                // Start checking
                checkStatus();
                checkInterval = setInterval(checkStatus, 3000);
            })();
        </script>
        <?php
    }

    /**
     * Render a shared banner for apps that rely on Docker-backed services.
     */
    private function renderServiceStatusBanner(): void {
        $overview = $this->getRequiredServicesOverview();
        if (($overview['total'] ?? 0) === 0 || !empty($overview['allHealthy'])) {
            return;
        }

        $bannerClass = 'runtime-banner runtime-banner-info';
        $iconClass = 'fa-cubes';
        $actionLabel = (($overview['healthyCount'] ?? 0) > 0 || !empty($overview['anyRunning']))
            ? 'Start Remaining Services'
            : 'Start Services';

        if (empty($overview['canManage'])) {
            $bannerClass = 'runtime-banner runtime-banner-error';
            $iconClass = 'fa-triangle-exclamation';
            $message = $overview['error'] ?? 'This app requires Docker services, but they cannot be managed from the current trust level.';
        } else {
            $total = (int)($overview['total'] ?? 0);
            $healthyCount = (int)($overview['healthyCount'] ?? 0);
            if ($healthyCount > 0) {
                $message = sprintf(
                    '%d of %d required services are ready. Start the remaining services to finish preparing this app.',
                    $healthyCount,
                    $total
                );
            } elseif ($total === 1) {
                $message = 'This app needs its Docker service started before it can run.';
            } else {
                $message = sprintf(
                    'This app needs %d Docker services started before it can run.',
                    $total
                );
            }
        }

        $appId = $this->appId;
        $workspaceId = $this->isWorkspacePreview ? (string)($this->previewWorkspaceId ?? '') : '';
        ?>
        <div
            class="<?= htmlspecialchars($bannerClass) ?>"
            id="serviceRuntimeBanner"
            data-app-id="<?= htmlspecialchars($appId) ?>"
            data-workspace-id="<?= htmlspecialchars($workspaceId) ?>"
        >
            <div class="runtime-banner-content">
                <i class="fas <?= htmlspecialchars($iconClass) ?>" id="serviceRuntimeIcon"></i>
                <span id="serviceRuntimeMessage"><?= htmlspecialchars($message) ?></span>
                <?php if (!empty($overview['canManage'])): ?>
                    <button type="button" class="btn-action btn-start" id="serviceRuntimeStartButton" onclick="startRequiredServices()">
                        <?= htmlspecialchars($actionLabel) ?>
                    </button>
                <?php endif; ?>
            </div>
            <div class="runtime-banner-meta" id="serviceRuntimeMeta">
                <?php foreach (($overview['services'] ?? []) as $service): ?>
                    <?php
                    $serviceHealthy = !empty($service['healthy']);
                    $serviceRunning = !empty($service['running']);
                    $chipClass = $serviceHealthy ? 'ready' : ($serviceRunning ? 'warning' : 'pending');
                    $statusLabel = $serviceHealthy ? 'Ready' : ($serviceRunning ? 'Starting' : 'Stopped');
                    ?>
                    <span class="runtime-service-chip <?= htmlspecialchars($chipClass) ?>">
                        <?= htmlspecialchars((string)($service['name'] ?? 'service')) ?>: <?= htmlspecialchars($statusLabel) ?>
                    </span>
                <?php endforeach; ?>
            </div>
        </div>

        <style>
            .runtime-banner {
                margin-bottom: 20px;
                padding: 16px 20px;
                border-radius: 12px;
                border: 1px solid;
                animation: slideDown 0.3s ease-out;
            }

            @keyframes slideDown {
                from { opacity: 0; transform: translateY(-10px); }
                to { opacity: 1; transform: translateY(0); }
            }

            .runtime-banner-building {
                background: rgba(59, 130, 246, 0.1);
                border-color: rgba(59, 130, 246, 0.3);
                color: #3b82f6;
            }

            .runtime-banner-info {
                background: rgba(245, 158, 11, 0.1);
                border-color: rgba(245, 158, 11, 0.3);
                color: #f59e0b;
            }

            .runtime-banner-error {
                background: rgba(239, 68, 68, 0.1);
                border-color: rgba(239, 68, 68, 0.3);
                color: #ef4444;
            }

            .runtime-banner-success {
                background: rgba(34, 197, 94, 0.1);
                border-color: rgba(34, 197, 94, 0.3);
                color: #22c55e;
            }

            .runtime-banner-content {
                display: flex;
                align-items: center;
                gap: 12px;
            }

            .runtime-banner-content i {
                font-size: 18px;
            }

            .runtime-banner-content span {
                flex: 1;
                font-weight: 500;
            }

            .btn-action {
                padding: 6px 16px;
                background: rgba(0, 0, 0, 0.1);
                border: 1px solid currentColor;
                border-radius: 6px;
                color: inherit;
                font-size: 13px;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.2s;
            }

            .btn-action:hover {
                background: rgba(0, 0, 0, 0.2);
            }

            .btn-action:disabled {
                cursor: wait;
                opacity: 0.7;
            }

            .btn-start {
                background: rgba(34, 197, 94, 0.2);
                border-color: rgba(34, 197, 94, 0.5);
                color: #22c55e;
            }

            .runtime-banner-meta {
                margin-top: 12px;
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
            }

            .runtime-service-chip {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                padding: 6px 10px;
                border-radius: 999px;
                font-size: 12px;
                font-weight: 600;
                border: 1px solid currentColor;
                background: rgba(0, 0, 0, 0.08);
            }

            .runtime-service-chip.ready {
                color: #22c55e;
            }

            .runtime-service-chip.warning {
                color: #f59e0b;
            }

            .runtime-service-chip.pending {
                color: #6b7280;
            }
        </style>

        <script>
            (function() {
                const banner = document.getElementById('serviceRuntimeBanner');
                if (!banner) return;

                const appId = banner.dataset.appId;
                const workspaceId = banner.dataset.workspaceId;
                let checkInterval = null;

                function servicesUrl(action) {
                    const params = new URLSearchParams({ action, app: appId });
                    if (workspaceId) {
                        params.set('workspace', workspaceId);
                    }
                    return '/api/apps.php?' + params.toString();
                }

                function updateBanner(type, icon, message) {
                    banner.className = 'runtime-banner runtime-banner-' + type;
                    const iconNode = document.getElementById('serviceRuntimeIcon');
                    const messageNode = document.getElementById('serviceRuntimeMessage');
                    if (iconNode) {
                        iconNode.className = 'fas ' + icon;
                    }
                    if (messageNode) {
                        messageNode.textContent = message;
                    }
                }

                async function refreshServicesStatus() {
                    try {
                        const response = await fetch(servicesUrl('services-status'));
                        const data = await response.json();
                        if (!data.success) {
                            return;
                        }

                        const overview = data.overview || {};
                        if (overview.allHealthy) {
                            if (checkInterval) {
                                clearInterval(checkInterval);
                            }
                            updateBanner('success', 'fa-check-circle', 'Required services are ready. Reloading...');
                            setTimeout(() => window.location.reload(), 900);
                        }
                    } catch (error) {
                        console.error('Failed to refresh service status', error);
                    }
                }

                window.startRequiredServices = async function() {
                    const button = document.getElementById('serviceRuntimeStartButton');
                    if (button) {
                        button.disabled = true;
                    }
                    updateBanner('building', 'fa-cog fa-spin', 'Starting required services...');

                    try {
                        const response = await fetch(servicesUrl('start-services'));
                        const data = await response.json();
                        if (!data.success) {
                            updateBanner('error', 'fa-triangle-exclamation', data.error || 'Failed to start required services.');
                            if (button) {
                                button.disabled = false;
                            }
                            return;
                        }

                        if (data.overview?.allHealthy) {
                            updateBanner('success', 'fa-check-circle', 'Required services are ready. Reloading...');
                            setTimeout(() => window.location.reload(), 900);
                            return;
                        }

                        updateBanner('building', 'fa-cog fa-spin', 'Waiting for required services to become healthy...');
                        checkInterval = setInterval(refreshServicesStatus, 3000);
                    } catch (error) {
                        updateBanner('error', 'fa-triangle-exclamation', 'Failed to start required services: ' + error.message);
                        if (button) {
                            button.disabled = false;
                        }
                    }
                };
            })();
        </script>
        <?php
    }
    
    // ==================
    // UTILITIES
    // ==================
    
    /**
     * Generate a unique ID
     */
    public function generateId(string $prefix = ''): string {
        $id = uniqid($prefix, true);
        return str_replace('.', '', $id);
    }
    
    /**
     * Format a date
     */
    public function formatDate(string $date, string $format = 'Y-m-d H:i:s'): string {
        return date($format, strtotime($date));
    }
    
    /**
     * Format file size
     */
    public function formatSize(int $bytes): string {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 1) . ' ' . $units[$i];
    }
    
    // ==================
    // CONTAINER MANAGEMENT
    // ==================
    
    /**
     * Get required images from app manifest
     */
    public function getRequiredImages(): array {
        $requiredImages = $this->manifest['requiredImages'] ?? [];
        $normalized = [];
        
        foreach ($requiredImages as $img) {
            if (is_string($img)) {
                $normalized[] = [
                    'image' => $img,
                    'platform' => null,
                    'lifecycle' => 'on-demand',
                    'volumes' => [],
                    'env' => []
                ];
            } else {
                $normalized[] = [
                    'image' => $img['image'] ?? '',
                    'platform' => $img['platform'] ?? null,
                    'lifecycle' => $img['lifecycle'] ?? 'on-demand',
                    'volumes' => $img['volumes'] ?? [],
                    'env' => $img['env'] ?? []
                ];
            }
        }
        
        return $normalized;
    }
    
    /**
     * Check if a Docker image exists locally
     */
    public function checkImageExists(string $image): array {
        $this->require(AppCapabilities::EXEC_DOCKER);
        
        $target = $this->resolveExecTarget();
        return $this->withDockerEnv($target, function() use ($image) {
            $dockerBase = $this->getDockerCommandBase();
            $checkCmd = sprintf('%s image inspect %s 2>&1', $dockerBase, escapeshellarg($image));
            $output = [];
            $exitCode = null;
            exec($checkCmd, $output, $exitCode);
            return ['exists' => ($exitCode === 0)];
        });
    }

    /**
     * Run a raw Docker command on the configured exec target
     */
    public function runDockerCommandOnTarget(array $args, int $timeout = 60): array {
        $this->require(AppCapabilities::EXEC_DOCKER);

        $target = $this->resolveExecTarget();
        return $this->withDockerEnv($target, function() use ($args, $timeout) {
            $cmd = array_merge($this->getDockerCommandBaseArray(), $args);
            return $this->runDockerCommand($cmd, $timeout);
        });
    }

    /**
     * Resolve the configured exec target for this app (if any)
     */
    private function resolveExecTarget(): ?array {
        if (!$this->execTargetId) {
            return null;
        }
        
        $target = $this->config->getTarget($this->execTargetId);
        if (!$target) {
            return null;
        }
        
        $type = $target['type'] ?? '';
        if (!in_array($type, ['docker', 'local', 'docker-exec'], true)) {
            return null;
        }
        
        return $target;
    }

    /**
     * Derive DOCKER_HOST from target config
     */
    private function getDockerHostFromTarget(?array $target): ?string {
        if (!$target) {
            return null;
        }

        $connection = $this->resolveDockerConnection($target);
        $socket = $connection['socket'] ?? null;
        if ($socket) {
            return 'unix://' . $socket;
        }
        
        $host = $connection['host'] ?? null;
        if ($host) {
            $port = $connection['port'] ?? 2375;
            return "tcp://{$host}:{$port}";
        }
        
        $ssh = $connection['ssh'] ?? null;
        if (is_array($ssh)) {
            $sshHost = $ssh['host'] ?? null;
            $sshUser = $ssh['user'] ?? null;
            $sshPort = $ssh['port'] ?? null;
            if ($sshHost && $sshUser) {
                $hostPart = $sshUser . '@' . $sshHost;
                if ($sshPort) {
                    $hostPart .= ':' . $sshPort;
                }
                return 'ssh://' . $hostPart;
            }
        }
        
        return null;
    }

    /**
     * Resolve docker connection (supports sshTargetId)
     */
    private function resolveDockerConnection(array $target): array {
        $connection = $target['connection'] ?? [];
        if (!empty($connection['sshTargetId'])) {
            $sshTargetId = $connection['sshTargetId'];
            $sshTarget = $this->config->getTarget($sshTargetId);
            if ($sshTarget && ($sshTarget['type'] ?? '') === 'ssh') {
                $connection = [
                    'ssh' => $sshTarget['connection'] ?? [],
                    'sshTargetId' => $sshTargetId
                ];
            }
        }
        return $connection;
    }

    /**
     * Build environment variables for docker command (ssh targets)
     */
    private function buildDockerEnvForTarget(?array $target): array {
        $env = [];
        $cleanup = [];

        if (!$target) {
            return ['env' => $env, 'cleanup' => $cleanup];
        }

        $connection = $this->resolveDockerConnection($target);
        $ssh = $connection['ssh'] ?? null;
        if (is_array($ssh)) {
            $keyRef = $ssh['keyRef'] ?? $ssh['keySecret'] ?? null;
            $passwordRef = $ssh['passwordRef'] ?? $ssh['passwordSecret'] ?? null;
            $keyPath = null;
            $passwordPath = null;
            $passwordValue = null;

            if ($keyRef) {
                $keyValue = $this->config->getSecret($keyRef);
                if ($keyValue) {
                    $keyPath = $this->writeTempSecretFile($keyValue, 'doki-ssh-');
                    $cleanup[] = $keyPath;
                }
            }

            if ($passwordRef) {
                $passwordValue = $this->config->getSecret($passwordRef);
                if ($passwordValue !== null) {
                    $passwordPath = $this->writeTempSecretFile($passwordValue, 'doki-ssh-pass-');
                    $cleanup[] = $passwordPath;

                    $askpass = $this->prepareSshAskpass($passwordValue);
                    $env = array_merge($env, $askpass['env']);
                    $cleanup = array_merge($cleanup, $askpass['cleanup']);
                }
            }

            $homeBundle = $this->config->prepareDockerSshHome($ssh, $keyPath, $passwordValue !== null);
            if ($homeBundle) {
                $env = array_merge($env, $homeBundle['env']);
                $cleanup = array_merge($cleanup, $homeBundle['cleanup']);
            }
            if (!empty($ssh['host'])) {
                $this->config->ensureSshKnownHost($ssh['host'], (int)($ssh['port'] ?? 22));
            }

            $env['DOCKER_SSH_COMMAND'] = $this->buildSshCommand($ssh, $keyPath, $passwordPath);
        }

        return ['env' => $env, 'cleanup' => $cleanup];
    }

    /**
     * Run callback with docker env vars applied
     */
    private function withDockerEnv(?array $target, callable $callback): array {
        $bundle = $this->buildDockerEnvForTarget($target);
        $env = $bundle['env'];
        $cleanup = $bundle['cleanup'];
        $previous = [];

        foreach ($env as $key => $value) {
            $previous[$key] = getenv($key);
            putenv($key . '=' . $value);
        }

        try {
            return $callback();
        } finally {
            foreach ($env as $key => $value) {
                if ($previous[$key] === false || $previous[$key] === null || $previous[$key] === '') {
                    putenv($key);
                } else {
                    putenv($key . '=' . $previous[$key]);
                }
            }
            foreach ($cleanup as $path) {
                $this->cleanupPath($path);
            }
        }
    }

    private function writeTempSecretFile(string $content, string $prefix): string {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        file_put_contents($path, $content);
        chmod($path, 0600);
        return $path;
    }

    private function cleanupPath(string $path): void {
        if (is_dir($path)) {
            $this->removeDirectory($path);
            return;
        }
        @unlink($path);
    }

    private function removeDirectory(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function buildSshCommand(array $ssh, ?string $keyPath = null, ?string $passwordPath = null): string {
        $parts = [];
        if ($passwordPath && $this->commandExists('sshpass')) {
            $parts[] = 'sshpass';
            $parts[] = '-f';
            $parts[] = $passwordPath;
        }
        $parts[] = 'ssh';
        $parts[] = '-o';
        $parts[] = 'BatchMode=' . ($passwordPath ? 'no' : 'yes');
        $parts[] = '-o';
        $parts[] = 'StrictHostKeyChecking=no';
        $parts[] = '-o';
        $parts[] = 'UserKnownHostsFile=/dev/null';
        $parts[] = '-o';
        $parts[] = 'ConnectTimeout=5';
        if ($passwordPath) {
            $parts[] = '-o';
            $parts[] = 'PreferredAuthentications=publickey,password,keyboard-interactive';
            $parts[] = '-o';
            $parts[] = 'PasswordAuthentication=yes';
        }
        if (!empty($ssh['port'])) {
            $parts[] = '-p';
            $parts[] = (string)$ssh['port'];
        }
        if ($keyPath) {
            $parts[] = '-i';
            $parts[] = $keyPath;
            $parts[] = '-o';
            $parts[] = 'IdentitiesOnly=yes';
        }
        return implode(' ', $parts);
    }

    private function commandExists(string $command): bool {
        $result = shell_exec('command -v ' . escapeshellarg($command) . ' 2>/dev/null');
        return !empty($result);
    }

    /**
     * Prepare SSH_ASKPASS environment for password-based SSH
     */
    private function prepareSshAskpass(string $password): array {
        $passwordPath = $this->writeTempSecretFile($password, 'doki-ssh-pass-');
        $askpassPath = tempnam(sys_get_temp_dir(), 'doki-ssh-askpass-');
        $script = "#!/bin/sh\ncat " . escapeshellarg($passwordPath) . "\n";
        file_put_contents($askpassPath, $script);
        chmod($askpassPath, 0700);

        return [
            'env' => [
                'SSH_ASKPASS' => $askpassPath,
                'SSH_ASKPASS_REQUIRE' => 'force',
                'DISPLAY' => 'doki'
            ],
            'cleanup' => [$passwordPath, $askpassPath]
        ];
    }

    /**
     * Build base docker command array, honoring exec target
     */
    private function getDockerCommandBaseArray(): array {
        $cmd = ['docker'];
        $target = $this->resolveExecTarget();
        $dockerHost = $this->getDockerHostFromTarget($target);
        if ($dockerHost) {
            $cmd[] = '-H';
            $cmd[] = $dockerHost;
        }
        return $cmd;
    }

    /**
     * Build base docker command string, honoring exec target
     */
    private function getDockerCommandBase(): string {
        $cmd = $this->getDockerCommandBaseArray();
        return implode(' ', array_map('escapeshellarg', $cmd));
    }
    
    /**
     * Pull a Docker image
     */
    public function pullImage(string $image, ?string $platform = null): array {
        $this->require(AppCapabilities::EXEC_DOCKER);
        
        $target = $this->resolveExecTarget();
        return $this->withDockerEnv($target, function() use ($image, $platform) {
            // Build pull command - Docker handles platform automatically
            $dockerBase = $this->getDockerCommandBase();
            $platformArg = ($platform !== null && $platform !== '') ? ' --platform ' . escapeshellarg($platform) : '';
            $pullCmd = sprintf('%s pull%s %s 2>&1', $dockerBase, $platformArg, escapeshellarg($image));
            
            error_log("AppContext: Pulling image {$image}");
            $pullOutput = [];
            $pullResult = null;
            exec($pullCmd, $pullOutput, $pullResult);
            
            if ($pullResult !== 0) {
                $errorMsg = implode("\n", $pullOutput);
                error_log("AppContext: Pull failed: {$errorMsg}");
                return [
                    'success' => false,
                    'pulled' => false,
                    'error' => "Failed to pull image: " . $errorMsg
                ];
            }
            
            error_log("AppContext: Successfully pulled image {$image}");
            return [
                'success' => true,
                'pulled' => true
            ];
        });
    }
    
    /**
     * Execute a command inside a Docker container
     */
    public function executeInContainer(string $image, string $command, array $options = []): array {
        $this->require(AppCapabilities::EXEC_DOCKER);
        $target = $this->resolveExecTarget();
        return $this->withDockerEnv($target, function() use ($image, $command, $options, $target) {
            if ($this->isRemoteDockerTarget($target) && !empty($options['volumes'])) {
                if (!empty($options['warm'])) {
                    return $this->executeInWarmRemoteContainer($image, $command, $options);
                }
                return $this->executeInRemoteContainer($image, $command, $options);
            }
            $cmd = $this->getDockerCommandBaseArray();
            $cmd[] = 'run';
            $cmd[] = '--rm';
            $cmd[] = '--label';
            $cmd[] = 'doki.app=' . $this->appId;
            
            $network = $options['network'] ?? 'none';
            $cmd[] = '--network';
            $cmd[] = $network;
            
            if (isset($options['workdir'])) {
                $cmd[] = '-w';
                $cmd[] = $options['workdir'];
            }
            
            foreach ($options['env'] ?? [] as $env) {
                $cmd[] = '-e';
                $cmd[] = $env;
            }
            
            foreach ($options['volumes'] ?? [] as $volume) {
                $mapping = $this->resolveVolumeMapping($volume, true);
                if (!$mapping['success']) {
                    return [
                        'success' => false,
                        'error' => $mapping['error'],
                        'exitCode' => 1
                    ];
                }
                $mode = $mapping['mode'];
                $mountPoint = $mapping['containerPath'];
                if (!empty($mapping['isVolume'])) {
                    $cmd[] = '-v';
                    $cmd[] = "{$mapping['volumeName']}:{$mountPoint}:{$mode}";
                    continue;
                }
                $hostPath = $mapping['localPath'];
                $cmd[] = '-v';
                $cmd[] = "{$hostPath}:{$mountPoint}:{$mode}";
            }
            
            // Docker handles platform automatically - no need to specify
            
            $useEntrypoint = (bool)($options['useEntrypoint'] ?? false);
            $args = $options['args'] ?? [];
            
            if (!$useEntrypoint) {
                // Override any custom entrypoint to ensure /bin/sh -c works
                $cmd[] = '--entrypoint';
                $cmd[] = '/bin/sh';
            }
            
            $cmd[] = $image;
            
            if ($useEntrypoint) {
                foreach ($args as $arg) {
                    $cmd[] = $arg;
                }
            } else {
                $cmd[] = '-c';
                $cmd[] = $command;
            }
            
            $timeout = $options['timeout'] ?? 300;
            $dockerCmd = "timeout {$timeout}s " . implode(' ', array_map('escapeshellarg', $cmd));
            
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            
            $process = proc_open($dockerCmd, $descriptors, $pipes);
            
            if (!is_resource($process)) {
                return [
                    'success' => false,
                    'error' => 'Failed to start Docker process',
                    'exitCode' => 1,
                    'output' => ''
                ];
            }
            
            fclose($pipes[0]);
            
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            
            fclose($pipes[1]);
            fclose($pipes[2]);
            
            $exitCode = proc_close($process);
            
            $output = $stdout;
            if ($stderr) {
                $filteredStderr = $this->filterDockerMessages($stderr);
                if ($filteredStderr) {
                    $output .= "\n" . $filteredStderr;
                }
            }
            
            return [
                'success' => $exitCode === 0,
                'output' => trim($output),
                'exitCode' => $exitCode,
                'error' => $exitCode !== 0 ? "Command exited with code {$exitCode}" : null
            ];
        });
    }

    /**
     * Execute inside a remote Docker engine by staging files with docker cp
     */
    private function executeInRemoteContainer(string $image, string $command, array $options = []): array {
        $timing = [
            'create' => 0.0,
            'copy_in' => 0.0,
            'run' => 0.0,
            'copy_out' => 0.0,
            'cleanup' => 0.0
        ];
        $totalStart = microtime(true);
        $containerName = 'doki-' . $this->appId . '-' . bin2hex(random_bytes(4));
        $cmd = $this->getDockerCommandBaseArray();
        $cmd[] = 'create';
        $cmd[] = '--name';
        $cmd[] = $containerName;
        $cmd[] = '--label';
        $cmd[] = 'doki.app=' . $this->appId;

        $network = $options['network'] ?? 'none';
        $cmd[] = '--network';
        $cmd[] = $network;

        if (isset($options['workdir'])) {
            $cmd[] = '-w';
            $cmd[] = $options['workdir'];
        }

        foreach ($options['env'] ?? [] as $env) {
            $cmd[] = '-e';
            $cmd[] = $env;
        }

        $useEntrypoint = (bool)($options['useEntrypoint'] ?? false);
        $args = $options['args'] ?? [];
        if (!$useEntrypoint) {
            $cmd[] = '--entrypoint';
            $cmd[] = '/bin/sh';
        }

        $cmd[] = $image;

        if ($useEntrypoint) {
            foreach ($args as $arg) {
                $cmd[] = $arg;
            }
        } else {
            $cmd[] = '-c';
            $cmd[] = $command;
        }

        $timeout = $options['timeout'] ?? 300;
        $volumeMappings = [];
        foreach ($options['volumes'] ?? [] as $volume) {
            $mapping = $this->resolveVolumeMapping($volume, false);
            if (!$mapping['success']) {
                return [
                    'success' => false,
                    'error' => $mapping['error'],
                    'exitCode' => 1
                ];
            }
            $volumeMappings[] = $mapping;
        }

        foreach ($volumeMappings as $mapping) {
            if (empty($mapping['isVolume'])) {
                continue;
            }
            $cmd[] = '-v';
            $cmd[] = "{$mapping['volumeName']}:{$mapping['containerPath']}:{$mapping['mode']}";
        }

        $createStart = microtime(true);
        $create = $this->runDockerCommand($cmd, 30);
        $timing['create'] = microtime(true) - $createStart;
        if (!$create['success']) {
            return [
                'success' => false,
                'output' => $create['output'],
                'exitCode' => $create['exitCode'],
                'error' => 'Failed to create remote container',
                'timing' => $timing
            ];
        }

        $copyInStart = microtime(true);
        foreach ($volumeMappings as $mapping) {
            if (!empty($mapping['isVolume'])) {
                continue;
            }
            $mode = $mapping['mode'];
            $localPath = $mapping['localPath'];
            $containerPath = $mapping['containerPath'];

            $shouldCopyIn = (strpos($mode, 'ro') !== false || strpos($mode, 'rw') !== false) && file_exists($localPath);
            if ($shouldCopyIn) {
                $copyIn = $this->dockerCopyToContainer($containerName, $localPath, $containerPath);
                if (!$copyIn['success']) {
                    $this->runDockerCommand(array_merge($this->getDockerCommandBaseArray(), ['rm', '-f', $containerName]), 10);
                    return [
                        'success' => false,
                        'output' => $copyIn['output'],
                        'exitCode' => $copyIn['exitCode'],
                        'error' => 'Failed to copy input to remote container',
                        'timing' => $timing
                    ];
                }
            }
        }
        $timing['copy_in'] = microtime(true) - $copyInStart;

        $runStart = microtime(true);
        $start = $this->runDockerCommand(array_merge($this->getDockerCommandBaseArray(), ['start', '-a', $containerName]), $timeout);
        $timing['run'] = microtime(true) - $runStart;
        if (!$start['success']) {
            $this->runDockerCommand(array_merge($this->getDockerCommandBaseArray(), ['rm', '-f', $containerName]), 10);
            return [
                'success' => false,
                'output' => $start['output'],
                'exitCode' => $start['exitCode'],
                'error' => 'Command exited with code ' . ($start['exitCode'] ?? 1),
                'timing' => $timing
            ];
        }

        $copyOutStart = microtime(true);
        foreach ($volumeMappings as $mapping) {
            if (!empty($mapping['isVolume'])) {
                continue;
            }
            $mode = $mapping['mode'];
            if (strpos($mode, 'rw') === false && strpos($mode, 'wo') === false) {
                continue;
            }
            $localPath = $mapping['localPath'];
            $containerPath = $mapping['containerPath'];

            if (!file_exists($localPath)) {
                @mkdir($localPath, 0755, true);
            }
            $copyOut = $this->dockerCopyFromContainer($containerName, $containerPath, $localPath);
            if (!$copyOut['success']) {
                $this->runDockerCommand(array_merge($this->getDockerCommandBaseArray(), ['rm', '-f', $containerName]), 10);
                return [
                    'success' => false,
                    'output' => $copyOut['output'],
                    'exitCode' => $copyOut['exitCode'],
                    'error' => 'Failed to copy output from remote container',
                    'timing' => $timing
                ];
            }
        }
        $timing['copy_out'] = microtime(true) - $copyOutStart;

        $cleanupStart = microtime(true);
        $this->runDockerCommand(array_merge($this->getDockerCommandBaseArray(), ['rm', '-f', $containerName]), 10);
        $timing['cleanup'] = microtime(true) - $cleanupStart;
        $timing['total'] = microtime(true) - $totalStart;

        return [
            'success' => $start['success'],
            'output' => trim($start['output'] ?? ''),
            'exitCode' => $start['exitCode'],
            'error' => $start['success'] ? null : 'Command exited with code ' . ($start['exitCode'] ?? 1),
            'timing' => $timing
        ];
    }

    /**
     * Execute inside a warm remote container (reuses a running container)
     */
    private function executeInWarmRemoteContainer(string $image, string $command, array $options = []): array {
        $timing = [
            'ensure' => 0.0,
            'prep' => 0.0,
            'copy_in' => 0.0,
            'run' => 0.0,
            'copy_out' => 0.0,
            'cleanup' => 0.0
        ];
        $totalStart = microtime(true);
        $timeout = $options['timeout'] ?? 300;

        $volumeMappings = [];
        foreach ($options['volumes'] ?? [] as $volume) {
            $mapping = $this->resolveVolumeMapping($volume, false);
            if (!$mapping['success']) {
                return [
                    'success' => false,
                    'error' => $mapping['error'],
                    'exitCode' => 1,
                    'timing' => $timing
                ];
            }
            $volumeMappings[] = $mapping;
        }

        $ensureStart = microtime(true);
        $warm = $this->ensureWarmContainer($image, $options, $volumeMappings);
        $timing['ensure'] = microtime(true) - $ensureStart;
        if (!$warm['success']) {
            return [
                'success' => false,
                'output' => $warm['output'] ?? null,
                'exitCode' => $warm['exitCode'] ?? 1,
                'error' => $warm['error'] ?? 'Failed to prepare warm container',
                'timing' => $timing
            ];
        }
        $containerName = $warm['name'];

        $containerDirs = [];
        foreach ($volumeMappings as $mapping) {
            if (!empty($mapping['isVolume'])) {
                continue;
            }
            $containerDirs[] = rtrim($mapping['containerPath'], '/');
        }
        $containerDirs = array_values(array_unique(array_filter($containerDirs)));

        $prepStart = microtime(true);
        if (!empty($containerDirs)) {
            $clearTargets = array_map(
                fn($dir) => escapeshellarg(rtrim($dir, '/')) . '/*',
                $containerDirs
            );
            $clearCmd = $this->getDockerCommandBaseArray();
            $clearCmd[] = 'exec';
            $clearCmd[] = $containerName;
            $clearCmd[] = 'sh';
            $clearCmd[] = '-c';
            $clearCmd[] = 'rm -rf ' . implode(' ', $clearTargets);
            $this->runDockerCommand($clearCmd, 10);
        }
        $timing['prep'] = microtime(true) - $prepStart;

        $copyInStart = microtime(true);
        foreach ($volumeMappings as $mapping) {
            if (!empty($mapping['isVolume'])) {
                continue;
            }
            $mode = $mapping['mode'];
            $localPath = $mapping['localPath'];
            $containerPath = $mapping['containerPath'];

            $shouldCopyIn = (strpos($mode, 'ro') !== false || strpos($mode, 'rw') !== false) && file_exists($localPath);
            if ($shouldCopyIn) {
                $copyIn = $this->dockerCopyToContainer($containerName, $localPath, $containerPath);
                if (!$copyIn['success']) {
                    return [
                        'success' => false,
                        'output' => $copyIn['output'],
                        'exitCode' => $copyIn['exitCode'],
                        'error' => 'Failed to copy input to warm container',
                        'timing' => $timing
                    ];
                }
            }
        }
        $timing['copy_in'] = microtime(true) - $copyInStart;

        $runStart = microtime(true);
        $execCmd = $this->getDockerCommandBaseArray();
        $execCmd[] = 'exec';
        foreach ($options['env'] ?? [] as $env) {
            $execCmd[] = '-e';
            $execCmd[] = $env;
        }
        if (isset($options['workdir'])) {
            $execCmd[] = '-w';
            $execCmd[] = $options['workdir'];
        }
        $execCmd[] = $containerName;

        $useEntrypoint = (bool)($options['useEntrypoint'] ?? false);
        $args = $options['args'] ?? [];
        if ($useEntrypoint) {
            foreach ($args as $arg) {
                $execCmd[] = $arg;
            }
        } else {
            $execCmd[] = 'sh';
            $execCmd[] = '-c';
            $execCmd[] = $command;
        }

        $run = $this->runDockerCommand($execCmd, $timeout);
        $timing['run'] = microtime(true) - $runStart;
        if (!$run['success']) {
            return [
                'success' => false,
                'output' => $run['output'],
                'exitCode' => $run['exitCode'],
                'error' => 'Command exited with code ' . ($run['exitCode'] ?? 1),
                'timing' => $timing
            ];
        }

        $copyOutStart = microtime(true);
        foreach ($volumeMappings as $mapping) {
            if (!empty($mapping['isVolume'])) {
                continue;
            }
            $mode = $mapping['mode'];
            if (strpos($mode, 'rw') === false && strpos($mode, 'wo') === false) {
                continue;
            }
            $localPath = $mapping['localPath'];
            $containerPath = $mapping['containerPath'];

            if (!file_exists($localPath)) {
                @mkdir($localPath, 0755, true);
            }
            $copyOut = $this->dockerCopyFromContainer($containerName, $containerPath, $localPath);
            if (!$copyOut['success']) {
                return [
                    'success' => false,
                    'output' => $copyOut['output'],
                    'exitCode' => $copyOut['exitCode'],
                    'error' => 'Failed to copy output from warm container',
                    'timing' => $timing
                ];
            }
        }
        $timing['copy_out'] = microtime(true) - $copyOutStart;

        $timing['cleanup'] = 0.0;
        $timing['total'] = microtime(true) - $totalStart;

        return [
            'success' => true,
            'output' => trim($run['output'] ?? ''),
            'exitCode' => $run['exitCode'],
            'error' => null,
            'timing' => $timing
        ];
    }

    private function ensureWarmContainer(string $image, array $options, array $volumeMappings): array {
        $target = $this->resolveExecTarget();
        $containerName = $this->getWarmContainerName($target);
        $base = $this->getDockerCommandBaseArray();

        $containerDirs = [];
        foreach ($volumeMappings as $mapping) {
            if (!empty($mapping['isVolume'])) {
                continue;
            }
            $containerDirs[] = rtrim($mapping['containerPath'], '/');
        }
        $containerDirs = array_values(array_unique(array_filter($containerDirs)));

        $running = $this->runDockerCommand(array_merge($base, ['ps', '--filter', "name=^{$containerName}$", '--filter', 'status=running', '--format', '{{.ID}}']), 10);
        if ($running['success'] && !empty($running['output'])) {
            return ['success' => true, 'name' => $containerName, 'reused' => true];
        }

        $exists = $this->runDockerCommand(array_merge($base, ['ps', '-a', '--filter', "name=^{$containerName}$", '--format', '{{.ID}}']), 10);
        if ($exists['success'] && !empty($exists['output'])) {
            $imageCheck = $this->runDockerCommand(array_merge($base, ['inspect', '-f', '{{.Config.Image}}', $containerName]), 10);
            if ($imageCheck['success'] && trim($imageCheck['output']) !== $image) {
                $this->runDockerCommand(array_merge($base, ['rm', '-f', $containerName]), 10);
            } else {
                $start = $this->runDockerCommand(array_merge($base, ['start', $containerName]), 10);
                if ($start['success']) {
                    if (!empty($containerDirs)) {
                        $dirCmd = $this->getDockerCommandBaseArray();
                        $dirCmd[] = 'exec';
                        $dirCmd[] = $containerName;
                        $dirCmd[] = 'sh';
                        $dirCmd[] = '-c';
                        $dirCmd[] = 'mkdir -p ' . implode(' ', array_map('escapeshellarg', $containerDirs));
                        $this->runDockerCommand($dirCmd, 10);
                    }
                    return ['success' => true, 'name' => $containerName, 'reused' => true];
                }
            }
        }

        $cmd = $base;
        $cmd[] = 'create';
        $cmd[] = '--name';
        $cmd[] = $containerName;
        $cmd[] = '--label';
        $cmd[] = 'doki.app=' . $this->appId;
        $cmd[] = '--label';
        $cmd[] = 'doki.warm=1';

        $network = $options['network'] ?? 'none';
        $cmd[] = '--network';
        $cmd[] = $network;

        foreach ($volumeMappings as $mapping) {
            if (empty($mapping['isVolume'])) {
                continue;
            }
            $cmd[] = '-v';
            $cmd[] = "{$mapping['volumeName']}:{$mapping['containerPath']}:{$mapping['mode']}";
        }

        $cmd[] = '--entrypoint';
        $cmd[] = '/bin/sh';
        $cmd[] = $image;
        $cmd[] = '-c';
        $cmd[] = 'while true; do sleep 3600; done';

        $create = $this->runDockerCommand($cmd, 30);
        if (!$create['success']) {
            return [
                'success' => false,
                'output' => $create['output'],
                'exitCode' => $create['exitCode'],
                'error' => 'Failed to create warm container'
            ];
        }

        $start = $this->runDockerCommand(array_merge($base, ['start', $containerName]), 10);
        if (!$start['success']) {
            return [
                'success' => false,
                'output' => $start['output'],
                'exitCode' => $start['exitCode'],
                'error' => 'Failed to start warm container'
            ];
        }

        if (!empty($containerDirs)) {
            $dirCmd = $this->getDockerCommandBaseArray();
            $dirCmd[] = 'exec';
            $dirCmd[] = $containerName;
            $dirCmd[] = 'sh';
            $dirCmd[] = '-c';
            $dirCmd[] = 'mkdir -p ' . implode(' ', array_map('escapeshellarg', $containerDirs));
            $this->runDockerCommand($dirCmd, 10);
        }

        return ['success' => true, 'name' => $containerName, 'created' => true];
    }

    private function getWarmContainerName(?array $target): string {
        $targetId = $target['id'] ?? 'local';
        $raw = 'doki-warm-' . $this->appId . '-' . $targetId;
        return $this->sanitizeDockerName($raw);
    }

    private function sanitizeDockerName(string $name): string {
        $safe = strtolower(preg_replace('/[^a-zA-Z0-9_.-]+/', '-', $name));
        $safe = trim($safe, '-');
        if (strlen($safe) > 60) {
            $hash = substr(sha1($safe), 0, 8);
            $safe = substr($safe, 0, 51) . '-' . $hash;
        }
        return $safe ?: 'doki-warm-' . substr(sha1($name), 0, 8);
    }

    private function isRemoteDockerTarget(?array $target): bool {
        if (!$target) {
            return false;
        }
        $connection = $this->resolveDockerConnection($target);
        return !empty($connection['ssh']) || !empty($connection['host']);
    }

    private function resolveVolumeMapping(string $volume, bool $translateToHost): array {
        $parts = explode(':', $volume);
        $containerPath = $parts[0];
        $mountPoint = $parts[1] ?? $containerPath;
        $mode = $parts[2] ?? 'rw';

        if ($this->isNamedVolume($containerPath)) {
            if (!str_starts_with($containerPath, 'doki-')) {
                return [
                    'success' => false,
                    'error' => "Volume name not allowed: {$containerPath}"
                ];
            }
            return [
                'success' => true,
                'isVolume' => true,
                'volumeName' => $containerPath,
                'containerPath' => $mountPoint,
                'mode' => $mode
            ];
        }

        $realContainerPath = realpath($containerPath);
        $realStoragePath = realpath($this->storagePath);

        if ($realContainerPath === false) {
            $parentPath = realpath(dirname($containerPath));
            if ($parentPath === false || strpos($parentPath, $realStoragePath) !== 0) {
                return [
                    'success' => false,
                    'error' => "Volume mount not allowed: {$containerPath}"
                ];
            }
            $realContainerPath = $containerPath;
        } else if (strpos($realContainerPath, $realStoragePath) !== 0) {
            if (!$this->can(AppCapabilities::FS_OTHER_APPS) && !$this->can(AppCapabilities::FS_CORE)) {
                return [
                    'success' => false,
                    'error' => "Volume mount not allowed: {$containerPath}"
                ];
            }
        }

        $localPath = $realContainerPath;
        if ($translateToHost) {
            $hostAppPath = getenv('HOST_APP_PATH') ?: '/var/www/html';
            $containerBasePath = '/var/www/html';
            if (strpos($realContainerPath, $containerBasePath) === 0) {
                $localPath = $hostAppPath . substr($realContainerPath, strlen($containerBasePath));
            }
        }

        return [
            'success' => true,
            'isVolume' => false,
            'localPath' => $localPath,
            'containerPath' => $mountPoint,
            'mode' => $mode
        ];
    }

    private function isNamedVolume(string $source): bool {
        if ($source === '' || $source === '.' || $source === '..') {
            return false;
        }
        if ($source[0] === '/' || $source[0] === '~') {
            return false;
        }
        if (strpos($source, DIRECTORY_SEPARATOR) !== false) {
            return false;
        }
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]+$/', $source) === 1;
    }

    private function dockerCopyToContainer(string $containerName, string $localPath, string $containerPath): array {
        $cmd = $this->getDockerCommandBaseArray();
        $cmd[] = 'cp';
        if (is_dir($localPath)) {
            $src = rtrim($localPath, '/') . '/.';
            $cmd[] = $src;
            $cmd[] = $containerName . ':' . rtrim($containerPath, '/');
        } else {
            $cmd[] = $localPath;
            $cmd[] = $containerName . ':' . rtrim($containerPath, '/');
        }
        return $this->runDockerCommand($cmd, 30);
    }

    private function dockerCopyFromContainer(string $containerName, string $containerPath, string $localPath): array {
        $cmd = $this->getDockerCommandBaseArray();
        $cmd[] = 'cp';
        if (is_dir($localPath)) {
            $cmd[] = $containerName . ':' . rtrim($containerPath, '/') . '/.';
            $cmd[] = rtrim($localPath, '/');
        } else {
            $cmd[] = $containerName . ':' . rtrim($containerPath, '/');
            $cmd[] = $localPath;
        }
        return $this->runDockerCommand($cmd, 30);
    }

    private function runDockerCommand(array $cmd, int $timeout = 60): array {
        $dockerCmd = "timeout {$timeout}s " . implode(' ', array_map('escapeshellarg', $cmd)) . ' 2>&1';
        $output = [];
        $exitCode = null;
        exec($dockerCmd, $output, $exitCode);
        return [
            'success' => $exitCode === 0,
            'output' => trim(implode("\n", $output)),
            'exitCode' => $exitCode
        ];
    }
    
    
    /**
     * Filter out Docker infrastructure messages
     */
    private function filterDockerMessages(string $stderr): string {
        $lines = explode("\n", $stderr);
        $filtered = [];
        
        foreach ($lines as $line) {
            if (preg_match('/^(Unable to find image|Pulling from|[a-f0-9]+: (Pulling|Verifying|Download|Pull|Waiting|Extracting)|Digest:|Status:|latest:)/i', trim($line))) {
                continue;
            }
            if (empty($filtered) && trim($line) === '') {
                continue;
            }
            $filtered[] = $line;
        }
        
        while (!empty($filtered) && trim(end($filtered)) === '') {
            array_pop($filtered);
        }
        
        return implode("\n", $filtered);
    }
    
    // ==================
    // SERVICE MANAGEMENT
    // ==================
    
    /**
     * Get the Docker host for container-to-host communication
     */
    public function getDockerHost(): string {
        return getenv('DOCKER_HOST_INTERNAL') ?: 'host.docker.internal';
    }
    
    /**
     * Get required services from app manifest
     */
    public function getRequiredServices(): array {
        $services = $this->manifest['requiredServices'] ?? [];
        $normalized = [];
        
        foreach ($services as $svc) {
            if (is_string($svc)) {
                // Simple format: just image name
                $normalized[] = [
                    'name' => basename($svc),
                    'image' => $svc,
                    'platform' => null,
                    'port' => 80,
                    'hostPort' => 80,
                    'healthcheck' => '/health',
                    'env' => [],
                    'args' => [],
                ];
            } else {
                $args = $svc['args'] ?? [];
                if (is_string($args)) {
                    $args = [$args];
                }
                $hostPort = $svc['hostPort'] ?? ($svc['port'] ?? 80);
                if ($hostPort === '' || $hostPort === null) {
                    $hostPort = 0;
                }
                $normalized[] = [
                    'name' => $svc['name'] ?? basename($svc['image'] ?? 'unknown'),
                    'image' => $svc['image'] ?? '',
                    'platform' => $svc['platform'] ?? null,
                    'port' => $svc['port'] ?? 80,
                    'hostPort' => (int)$hostPort,
                    'healthcheck' => $svc['healthcheck'] ?? $svc['healthPath'] ?? '/health',
                    'env' => $svc['env'] ?? [],
                    'args' => array_values(array_filter(array_map('strval', is_array($args) ? $args : []), static fn(string $value): bool => $value !== '')),
                ];
            }
        }
        
        return $normalized;
    }
    
    /**
     * Get a specific service definition by name
     */
    public function getServiceDefinition(string $serviceName): ?array {
        $services = $this->getRequiredServices();
        foreach ($services as $svc) {
            if ($svc['name'] === $serviceName) {
                return $svc;
            }
        }
        return null;
    }
    
    /**
     * Get the container name for a service
     */
    private function getServiceContainerName(string $serviceName): string {
        return 'doki-' . preg_replace('/[^a-z0-9-]/', '-', strtolower($serviceName));
    }

    /**
     * Get the effective host port for a service container.
     */
    private function getServiceHostPort(string $serviceName): ?int {
        $service = $this->getServiceDefinition($serviceName);
        if (!$service) {
            return null;
        }

        $configuredHostPort = isset($service['hostPort']) ? (int)$service['hostPort'] : (int)($service['port'] ?? 0);
        $containerPort = (int)($service['port'] ?? 0);
        if ($containerPort <= 0) {
            return $configuredHostPort > 0 ? $configuredHostPort : null;
        }

        if (!$this->isServiceRunning($serviceName)) {
            return $configuredHostPort > 0 ? $configuredHostPort : null;
        }

        $containerName = $this->getServiceContainerName($serviceName);
        $portCmd = sprintf(
            'docker port %s %d/tcp 2>&1',
            escapeshellarg($containerName),
            $containerPort
        );
        $output = trim(shell_exec($portCmd) ?? '');
        if ($output !== '') {
            $lines = preg_split('/\r?\n/', $output) ?: [];
            foreach ($lines as $line) {
                if (preg_match('/:(\d+)\s*$/', trim($line), $matches) === 1) {
                    return (int)$matches[1];
                }
            }
        }

        return $configuredHostPort > 0 ? $configuredHostPort : null;
    }
    
    /**
     * Check if a service is healthy
     */
    public function checkServiceHealth(string $serviceName): array {
        $this->require(AppCapabilities::EXEC_DOCKER);
        
        $service = $this->getServiceDefinition($serviceName);
        if (!$service) {
            return ['healthy' => false, 'error' => 'Service not defined in manifest'];
        }
        
        $host = $this->getDockerHost();
        $port = $this->getServiceHostPort($serviceName);
        if ($port === null) {
            return [
                'healthy' => false,
                'httpCode' => 0,
                'error' => 'Service host port is not available',
            ];
        }
        $healthPath = $service['healthcheck'];
        
        $url = "http://{$host}:{$port}{$healthPath}";
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_HTTPGET => true,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        return [
            'healthy' => ($httpCode >= 200 && $httpCode < 400),
            'httpCode' => $httpCode,
            'error' => $error ?: null,
        ];
    }
    
    /**
     * Get the URL to access a service
     */
    public function getServiceUrl(string $serviceName): ?string {
        $service = $this->getServiceDefinition($serviceName);
        if (!$service) {
            return null;
        }
        
        $host = $this->getDockerHost();
        $port = $this->getServiceHostPort($serviceName);
        if ($port === null) {
            return null;
        }
        
        return "http://{$host}:{$port}";
    }
    
    /**
     * Check if a service container is running
     */
    public function isServiceRunning(string $serviceName): bool {
        $containerName = $this->getServiceContainerName($serviceName);
        
        $checkCmd = sprintf(
            'docker ps --filter "name=^%s$" --filter "status=running" --format "{{.ID}}" 2>&1',
            $containerName
        );
        $output = trim(shell_exec($checkCmd) ?? '');
        
        return !empty($output);
    }
    
    /**
     * Ensure a service is running
     * Returns service info including URL
     */
    public function ensureServiceRunning(string $serviceName): array {
        $this->require(AppCapabilities::EXEC_DOCKER);
        
        $service = $this->getServiceDefinition($serviceName);
        if (!$service) {
            return [
                'success' => false,
                'error' => "Service '{$serviceName}' not defined in app manifest",
            ];
        }
        
        $containerName = $this->getServiceContainerName($serviceName);
        $image = $service['image'];
        $platform = isset($service['platform']) && is_string($service['platform']) && $service['platform'] !== ''
            ? $service['platform']
            : null;
        $port = (int)$service['port'];
        $hostPort = isset($service['hostPort']) ? (int)$service['hostPort'] : $port;
        $lastHealth = ['healthy' => false, 'httpCode' => 0, 'error' => null];
        
        // Check if container is already running
        if ($this->isServiceRunning($serviceName)) {
            // Verify it's healthy
            $health = $this->checkServiceHealth($serviceName);
            if ($health['healthy']) {
                return [
                    'success' => true,
                    'url' => $this->getServiceUrl($serviceName),
                    'started' => false,
                    'message' => 'Service already running',
                ];
            }
        }
        
        // Check if container exists but stopped
        $existsCmd = sprintf(
            'docker ps -a --filter "name=^%s$" --format "{{.ID}}" 2>&1',
            $containerName
        );
        $exists = trim(shell_exec($existsCmd) ?? '');
        
        if (!empty($exists)) {
            // Start existing container
            error_log("AppContext: Starting existing service container {$containerName}");
            $startResult = shell_exec(sprintf('docker start %s 2>&1', escapeshellarg($containerName)));
            error_log("AppContext: Start result: " . trim($startResult ?? ''));
            if (strpos((string)$startResult, 'Error') !== false) {
                shell_exec(sprintf('docker rm -f %s 2>&1', escapeshellarg($containerName)));
                $exists = '';
            }
        }

        if (empty($exists)) {
            // Pull image if needed
            $imageCheck = $this->checkImageExists($image);
            if (!$imageCheck['exists']) {
                error_log("AppContext: Pulling service image {$image}");
                $pullResult = $this->pullImage($image, $platform);
                if (!$pullResult['success']) {
                    return [
                        'success' => false,
                        'error' => "Failed to pull image: " . ($pullResult['error'] ?? 'unknown'),
                    ];
                }
            }
            
            // Create and start new container
            error_log("AppContext: Creating service container {$containerName}");
            
            $envArgs = '';
            foreach ($service['env'] ?? [] as $env) {
                $envArgs .= ' -e ' . escapeshellarg($env);
            }

            $args = '';
            foreach ($service['args'] ?? [] as $arg) {
                $args .= ' ' . escapeshellarg((string)$arg);
            }

            $portBinding = $hostPort > 0
                ? escapeshellarg($hostPort . ':' . $port)
                : escapeshellarg((string)$port);
            
            $runCmd = sprintf(
                'docker run -d --name %s%s -p %s --label "doki.app=%s" --label "doki.service=%s" %s %s%s 2>&1',
                escapeshellarg($containerName),
                $platform ? ' --platform ' . escapeshellarg($platform) : '',
                $portBinding,
                escapeshellarg($this->appId),
                escapeshellarg($serviceName),
                $envArgs,
                escapeshellarg($image),
                $args
            );
            
            $createResult = shell_exec($runCmd);
            error_log("AppContext: Create result: " . trim($createResult ?? ''));
            
            if (strpos($createResult ?? '', 'Error') !== false) {
                return [
                    'success' => false,
                    'error' => "Failed to create container: " . trim($createResult ?? ''),
                ];
            }
        }
        
        // Wait for service to be healthy (max 60 seconds)
        $maxWait = 60;
        $waited = 0;
        while ($waited < $maxWait) {
            $lastHealth = $this->checkServiceHealth($serviceName);
            if ($lastHealth['healthy']) {
                error_log("AppContext: Service {$serviceName} is ready after {$waited}s");
                return [
                    'success' => true,
                    'url' => $this->getServiceUrl($serviceName),
                    'started' => true,
                    'message' => "Service started in {$waited}s",
                ];
            }
            sleep(1);
            $waited++;
        }

        $healthContext = [];
        if (!empty($lastHealth['httpCode'])) {
            $healthContext[] = 'last HTTP status ' . $lastHealth['httpCode'];
        }
        if (!empty($lastHealth['error'])) {
            $healthContext[] = $lastHealth['error'];
        }
        $healthSuffix = $healthContext !== [] ? ' (' . implode('; ', $healthContext) . ')' : '';
        
        return [
            'success' => false,
            'error' => "Service {$serviceName} failed to become healthy within {$maxWait}s{$healthSuffix}",
        ];
    }
    
    /**
     * Stop a service container
     */
    public function stopService(string $serviceName): array {
        $this->require(AppCapabilities::EXEC_DOCKER);
        
        $containerName = $this->getServiceContainerName($serviceName);
        
        if (!$this->isServiceRunning($serviceName)) {
            return ['success' => true, 'message' => 'Service was not running'];
        }
        
        $stopCmd = sprintf('docker stop %s 2>&1', escapeshellarg($containerName));
        $result = shell_exec($stopCmd);
        
        return [
            'success' => true,
            'message' => 'Service stopped',
            'output' => trim($result ?? ''),
        ];
    }
    
    /**
     * Get status of all app services
     */
    public function getServicesStatus(): array {
        $services = $this->getRequiredServices();
        $status = [];
        
        foreach ($services as $svc) {
            $name = $svc['name'];
            $running = $this->isServiceRunning($name);
            $health = $running ? $this->checkServiceHealth($name) : ['healthy' => false];
            
            $status[$name] = [
                'name' => $name,
                'image' => $svc['image'],
                'platform' => $svc['platform'] ?? null,
                'port' => $svc['port'],
                'hostPort' => $this->getServiceHostPort($name),
                'running' => $running,
                'healthy' => $health['healthy'],
                'url' => $running ? $this->getServiceUrl($name) : null,
                'httpCode' => $health['httpCode'] ?? 0,
                'error' => $health['error'] ?? null,
            ];
        }
        
        return $status;
    }

    /**
     * Get a normalized status overview for app-required services.
     */
    public function getRequiredServicesOverview(): array {
        $definitions = $this->getRequiredServices();
        $overview = [
            'services' => [],
            'total' => count($definitions),
            'healthyCount' => 0,
            'anyRunning' => false,
            'allHealthy' => count($definitions) === 0,
            'canManage' => $this->can(AppCapabilities::EXEC_DOCKER),
            'error' => null,
        ];

        if ($definitions === []) {
            return $overview;
        }

        $statuses = [];
        if ($overview['canManage']) {
            try {
                $statuses = $this->getServicesStatus();
            } catch (Throwable $e) {
                $overview['canManage'] = false;
                $overview['error'] = $e->getMessage();
            }
        } else {
            $overview['error'] = 'This app requires Docker access, but its current trust level does not allow managing Docker services.';
        }

        foreach ($definitions as $definition) {
            $name = (string)($definition['name'] ?? 'service');
            $serviceStatus = $statuses[$name] ?? [
                'name' => $name,
                'image' => $definition['image'] ?? '',
                'port' => $definition['port'] ?? 80,
                'running' => false,
                'healthy' => false,
                'url' => null,
            ];

            if (!empty($serviceStatus['healthy'])) {
                $overview['healthyCount']++;
            }

            if (!empty($serviceStatus['running'])) {
                $overview['anyRunning'] = true;
            }

            $overview['services'][] = $serviceStatus;
        }

        $overview['allHealthy'] = $overview['healthyCount'] === $overview['total'];

        return $overview;
    }
}
