<?php
/**
 * AppManager.php - Framework for managing Doki Apps
 * 
 * Apps are self-contained modules with their own UI, config, and storage.
 * Built-in apps live in app/apps/{app-name}/.
 * Source and Studio-installed app code lives in app/data/installed-apps/{app-name}/.
 * Runtime state for every app lives in app/data/apps/{app-name}/.
 * 
 * Trust Levels:
 *   0 - Core: Built-in apps
 *   1 - Verified: Official marketplace (code reviewed)
 *   2 - Trusted: Admin-approved
 *   3 - Sandboxed: Default for new installs
 */

require_once __DIR__ . '/AppCapabilities.php';
require_once __DIR__ . '/StudioReleaseManager.php';

class AppManager {
    private const BUILTIN_APPS_DIR = __DIR__ . '/../apps';
    private const INSTALLED_APPS_CODE_DIR = __DIR__ . '/../data/installed-apps';
    private const APPS_DATA_DIR = __DIR__ . '/../data/apps';
    private const DEFAULT_INSTALLED_FILE = __DIR__ . '/../apps/.installed.yaml';
    private const RUNTIME_INSTALLED_FILE = __DIR__ . '/../data/installed-apps/.installed.yaml';
    private const INSTALLED_APPS_PERMISSIONS_MARKER = __DIR__ . '/../data/installed-apps/.permissions-version';
    private const INSTALLED_APPS_PERMISSIONS_VERSION = '1';
    private const INCLUDE_BRIDGE_DIR = __DIR__ . '/../data/includes';
    private const SOURCES_FILE = __DIR__ . '/../data/apps/sources.yaml';
    private const USERS_DATA_DIR = __DIR__ . '/../data/users';
    
    private array $registeredApps = [];
    
    public function __construct() {
        self::ensureRuntimeLayout();
        $this->migrateLegacyInstalledApps();
        $this->normalizeInstalledAppPermissionsIfNeeded();
        $this->discoverApps();
    }

    public static function getBuiltInAppsDir(): string {
        return self::BUILTIN_APPS_DIR;
    }

    public static function getInstalledAppsCodeDir(): string {
        self::ensureRuntimeLayout();
        return self::INSTALLED_APPS_CODE_DIR;
    }

    public static function getRuntimeInstalledRegistryPath(): string {
        self::ensureRuntimeLayout();
        return self::RUNTIME_INSTALLED_FILE;
    }

    public static function getAppDir(string $appId): ?string {
        $appId = trim($appId);
        if ($appId === '') {
            return null;
        }

        self::ensureRuntimeLayout();
        $candidates = [
            self::INSTALLED_APPS_CODE_DIR . '/' . $appId,
            self::BUILTIN_APPS_DIR . '/' . $appId,
        ];

        foreach ($candidates as $candidate) {
            if (is_dir($candidate) && file_exists($candidate . '/manifest.yaml')) {
                return $candidate;
            }
        }

        return null;
    }

    public static function getAppManifestPath(string $appId): ?string {
        $appDir = self::getAppDir($appId);
        if ($appDir === null) {
            return null;
        }

        $manifestPath = $appDir . '/manifest.yaml';
        return file_exists($manifestPath) ? $manifestPath : null;
    }

    public static function resolveAppFilePath(string $appId, string $relativePath = 'index.php'): ?string {
        $appDir = self::getAppDir($appId);
        if ($appDir === null) {
            return null;
        }

        $normalizedPath = self::normalizeServedAppPath($relativePath);
        if ($normalizedPath === null) {
            return null;
        }

        $target = $appDir . '/' . $normalizedPath;
        if (is_dir($target)) {
            $target = rtrim($target, '/') . '/index.php';
        }

        return is_file($target) ? $target : null;
    }

    public static function buildVirtualAppScriptPath(string $appId, string $relativePath = 'index.php'): string {
        $normalizedPath = self::normalizeServedAppPath($relativePath) ?? 'index.php';
        return self::BUILTIN_APPS_DIR . '/' . trim($appId) . '/' . $normalizedPath;
    }

    public static function buildContainerAppScriptPath(string $appId, string $relativePath = 'index.php'): string {
        $trimmedAppId = trim($appId);
        $normalizedInput = trim(str_replace('\\', '/', $relativePath));
        $routePrefix = '/apps/' . $trimmedAppId;
        $routePrefixWithoutSlash = ltrim($routePrefix, '/');

        if ($normalizedInput === $routePrefix || $normalizedInput === $routePrefixWithoutSlash) {
            $normalizedInput = 'index.php';
        } elseif (str_starts_with($normalizedInput, $routePrefix . '/')) {
            $normalizedInput = substr($normalizedInput, strlen($routePrefix) + 1);
        } elseif (str_starts_with($normalizedInput, $routePrefixWithoutSlash . '/')) {
            $normalizedInput = substr($normalizedInput, strlen($routePrefixWithoutSlash) + 1);
        }

        $normalizedPath = self::normalizeServedAppPath($normalizedInput) ?? 'index.php';
        $appDir = self::getAppDir($trimmedAppId);

        if (is_string($appDir) && str_starts_with($appDir, self::INSTALLED_APPS_CODE_DIR . '/')) {
            return '/var/www/html/data/installed-apps/' . $trimmedAppId . '/' . $normalizedPath;
        }

        return '/var/www/html/apps/' . $trimmedAppId . '/' . $normalizedPath;
    }

    public static function loadInstalledAppsRegistry(): array {
        self::ensureRuntimeLayout();

        $defaults = self::loadInstalledAppsFile(self::DEFAULT_INSTALLED_FILE);
        $runtime = self::loadInstalledAppsFile(self::RUNTIME_INSTALLED_FILE);

        foreach ($runtime as $appId => $installed) {
            if (isset($defaults[$appId]) && is_array($defaults[$appId]) && is_array($installed)) {
                $defaults[$appId] = array_replace($defaults[$appId], $installed);
                continue;
            }

            $defaults[$appId] = $installed;
        }

        return $defaults;
    }

    public static function ensureRuntimeLayout(): void {
        if (!is_dir(self::INSTALLED_APPS_CODE_DIR)) {
            @mkdir(self::INSTALLED_APPS_CODE_DIR, 0755, true);
        }

        if (!is_dir(self::INCLUDE_BRIDGE_DIR)) {
            @mkdir(self::INCLUDE_BRIDGE_DIR, 0755, true);
        }

        foreach (glob(__DIR__ . '/*.php') ?: [] as $sourceFile) {
            $filename = basename($sourceFile);
            $bridgeFile = self::INCLUDE_BRIDGE_DIR . '/' . $filename;
            $bridgeContent = "<?php\nrequire_once __DIR__ . '/../../includes/{$filename}';\n";

            $current = @file_get_contents($bridgeFile);
            if ($current === $bridgeContent) {
                continue;
            }

            @file_put_contents($bridgeFile, $bridgeContent);
        }
    }

    private static function loadInstalledAppsFile(string $path): array {
        if (!file_exists($path)) {
            return [];
        }

        if (function_exists('yaml_parse_file')) {
            $data = yaml_parse_file($path);
            $apps = $data['apps'] ?? [];
            return is_array($apps) ? $apps : [];
        }

        $content = @file_get_contents($path);
        if (!is_string($content) || trim($content) === '') {
            return [];
        }

        $apps = [];
        $currentAppId = null;
        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            if (preg_match('/^  ([^:\s][^:]*):\s*$/', $line, $matches) === 1) {
                $currentAppId = trim((string)$matches[1]);
                $apps[$currentAppId] = [];
                continue;
            }

            if ($currentAppId === null) {
                continue;
            }

            if (preg_match('/^    ([A-Za-z0-9_]+):\s*(.*)$/', $line, $matches) === 1) {
                $apps[$currentAppId][trim((string)$matches[1])] =
                    self::parseInstalledAppsScalar((string)$matches[2]);
            }
        }

        return $apps;
    }

    private static function parseInstalledAppsScalar(string $value) {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        if (
            (str_starts_with($trimmed, '"') && str_ends_with($trimmed, '"'))
            || (str_starts_with($trimmed, "'") && str_ends_with($trimmed, "'"))
        ) {
            return trim($trimmed, "\"'");
        }

        $lower = strtolower($trimmed);
        if ($lower === 'true') {
            return true;
        }
        if ($lower === 'false') {
            return false;
        }
        if ($lower === 'null') {
            return null;
        }
        if (preg_match('/^-?\d+$/', $trimmed) === 1) {
            return (int)$trimmed;
        }
        if (preg_match('/^-?\d+\.\d+$/', $trimmed) === 1) {
            return (float)$trimmed;
        }

        return $trimmed;
    }

    private static function normalizeServedAppPath(string $relativePath): ?string {
        $normalized = trim(str_replace('\\', '/', $relativePath), '/');
        if ($normalized === '') {
            return 'index.php';
        }

        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        return $normalized;
    }
    
    /**
     * Discover apps from the apps directory
     */
    private function discoverApps(): void {
        $this->registeredApps = [];

        foreach ([self::BUILTIN_APPS_DIR, self::INSTALLED_APPS_CODE_DIR] as $appsDir) {
            if (!is_dir($appsDir)) {
                continue;
            }

            $dirs = glob($appsDir . '/*', GLOB_ONLYDIR);
            if (!is_array($dirs)) {
                continue;
            }

            foreach ($dirs as $dir) {
            $appId = basename($dir);
            $manifestFile = $dir . '/manifest.yaml';
            
            // Load manifest if exists, otherwise use defaults
            $manifest = [
                'id' => $appId,
                'name' => ucfirst($appId),
                'description' => '',
                'icon' => 'fas fa-cube',
                'color' => '#6366f1',
                'version' => '1.0.0',
                'enabled' => true,
                'navigation' => [],
                'tags' => []  // Tags for organization and discovery
            ];
            
            if (file_exists($manifestFile) && function_exists('yaml_parse_file')) {
                $loaded = yaml_parse_file($manifestFile);
                if ($loaded) {
                    $manifest = array_merge($manifest, $loaded);
                    $manifest['id'] = $appId; // Force ID from directory name
                }
            }
            
            // Ensure tags is always an array
            if (!isset($manifest['tags']) || !is_array($manifest['tags'])) {
                $manifest['tags'] = [];
            }
            
            // Check for required files
            $manifest['hasIndex'] = file_exists($dir . '/index.php');
            $manifest['hasSettings'] = file_exists($dir . '/settings.php');
            $manifest['path'] = $dir;
            $manifest['installLocation'] = str_starts_with($dir, self::INSTALLED_APPS_CODE_DIR . '/')
                ? 'runtime'
                : 'bundled';
            
            $this->registeredApps[$appId] = $manifest;
        }
        }
    }

    private function migrateLegacyInstalledApps(): void {
        $legacyApps = self::loadInstalledAppsFile(self::DEFAULT_INSTALLED_FILE);
        if ($legacyApps === []) {
            return;
        }

        $runtimeApps = self::loadInstalledAppsFile(self::RUNTIME_INSTALLED_FILE);
        $runtimeChanged = false;

        foreach ($legacyApps as $appId => $installedInfo) {
            if (!$this->shouldUseRuntimeCodeDir($installedInfo)) {
                continue;
            }

            if (!isset($runtimeApps[$appId])) {
                $runtimeApps[$appId] = $installedInfo;
                $runtimeChanged = true;
            }

            $legacyDir = self::BUILTIN_APPS_DIR . '/' . $appId;
            $runtimeDir = self::INSTALLED_APPS_CODE_DIR . '/' . $appId;
            if (!is_dir($legacyDir) || is_dir($runtimeDir)) {
                continue;
            }

            if (@rename($legacyDir, $runtimeDir)) {
                continue;
            }

            if ($this->copyDirectory($legacyDir, $runtimeDir)) {
                continue;
            }
        }

        if ($runtimeChanged) {
            $this->writeYamlFile(self::RUNTIME_INSTALLED_FILE, ['apps' => $runtimeApps]);
        }
    }

    private function shouldUseRuntimeCodeDir(array $installedInfo): bool {
        return in_array((string)($installedInfo['source'] ?? ''), ['git', 'studio'], true);
    }

    private function isBundledAppPath(string $path): bool {
        $realPath = realpath($path);
        $bundledRoot = realpath(self::BUILTIN_APPS_DIR);
        if ($realPath === false || $bundledRoot === false) {
            return false;
        }

        return str_starts_with($realPath, $bundledRoot . DIRECTORY_SEPARATOR);
    }
    
    /**
     * Get all registered apps
     */
    public function getApps(): array {
        return array_values($this->registeredApps);
    }
    
    /**
     * Get enabled apps for navigation
     * Enriched with installed metadata (priority, pinned, lastUsed, tags)
     * 
     * Pinned status resolution:
     *   1. User preference (if userId provided and user has set a preference)
     *   2. Global default (from .installed.yaml, set by admin)
     *   3. false (default if nothing is set)
     * 
     * @param string|null $userId Optional user ID to include user-specific preferences
     * @return array
     */
    public function getEnabledApps(?string $userId = null): array {
        $enabledApps = array_filter($this->registeredApps, fn($app) => $app['enabled'] ?? true);
        $installed = $this->getInstalledApps();
        
        // Load user preferences if userId provided
        $userPrefs = null;
        if ($userId) {
            $userPrefs = $this->getUserPreferences($userId);
        }
        
        // Enrich apps with installed metadata
        $enriched = [];
        foreach ($enabledApps as $app) {
            $appId = $app['id'];
            $installedInfo = $installed[$appId] ?? [];
            
            // Merge installed metadata
            $app['priority'] = $installedInfo['priority'] ?? 50;  // Default priority
            $app['lastUsed'] = $installedInfo['lastUsed'] ?? null;
            
            // Get pinned status: user preference overrides global default
            // Priority: user preference (if exists) > global default > false
            if ($userId && $userPrefs && isset($userPrefs['apps'][$appId]['pinned'])) {
                // User has explicitly set a preference (can be true or false)
                $app['pinned'] = (bool)$userPrefs['apps'][$appId]['pinned'];
            } else {
                // No user preference - use global default (set by admin) or false
                $app['pinned'] = (bool)($installedInfo['pinned'] ?? false);
            }
            
            // Get sidebarOrder: user preference overrides global
            if ($userId && $userPrefs && isset($userPrefs['apps'][$appId]['sidebarOrder'])) {
                $app['sidebarOrder'] = $userPrefs['apps'][$appId]['sidebarOrder'];
            } elseif (isset($installedInfo['sidebarOrder'])) {
                $app['sidebarOrder'] = $installedInfo['sidebarOrder'];
            }
            
            // Ensure tags is an array
            if (!isset($app['tags']) || !is_array($app['tags'])) {
                $app['tags'] = [];
            }
            
            $enriched[] = $app;
        }
        
        return array_values($enriched);
    }
    
    /**
     * Get all unique tags from all enabled apps
     */
    public function getAllTags(): array {
        $apps = $this->getEnabledApps();
        $tags = [];
        
        foreach ($apps as $app) {
            if (isset($app['tags']) && is_array($app['tags'])) {
                $tags = array_merge($tags, $app['tags']);
            }
        }
        
        // Remove duplicates and sort
        $tags = array_unique($tags);
        sort($tags);
        
        return array_values($tags);
    }
    
    /**
     * Track app usage (update lastUsed timestamp)
     * Silently fails if file system is read-only (e.g., in FPM containers)
     */
    public function trackAppUsage(string $appId): void {
        $runtimeRegistry = self::RUNTIME_INSTALLED_FILE;
        $runtimeDir = dirname($runtimeRegistry);

        // Check if we can write to the runtime registry (may be read-only in FPM containers)
        if ((file_exists($runtimeRegistry) && !is_writable($runtimeRegistry))
            || (!file_exists($runtimeRegistry) && (!is_dir($runtimeDir) || !is_writable($runtimeDir)))) {
            // Silently skip tracking in read-only environments
            return;
        }
        
        $installed = $this->getInstalledApps();
        
        if (!isset($installed[$appId])) {
            // Create entry if doesn't exist
            $installed[$appId] = [
                'source' => 'local',
                'installedAt' => date('c'),
                'installedBy' => 'system',
            ];
        }
        
        $installed[$appId]['lastUsed'] = date('c');
        $this->saveInstalledApps($installed);
    }
    
    /**
     * Update app priority
     */
    public function setAppPriority(string $appId, int $priority): bool {
        $installed = $this->getInstalledApps();
        
        if (!isset($installed[$appId])) {
            return false;
        }
        
        $installed[$appId]['priority'] = max(0, min(100, $priority));  // Clamp 0-100
        return $this->saveInstalledApps($installed);
    }
    
    /**
     * Set app pinned status
     * 
     * @param string $appId App ID
     * @param bool $pinned Pinned status
     * @param string|null $userId Optional user ID. 
     *   - If provided: saves to user preferences (personal override)
     *   - If null: saves to global .installed.yaml (admin default for all users)
     * @return bool Success status
     */
    public function setAppPinned(string $appId, bool $pinned, ?string $userId = null): bool {
        if ($userId) {
            // Save to user preferences (personal override)
            // This allows users to override the global default set by admins
            $preferences = $this->getUserPreferences($userId);
            
            if (!isset($preferences['apps'][$appId])) {
                $preferences['apps'][$appId] = [];
            }
            
            $preferences['apps'][$appId]['pinned'] = $pinned;
            return $this->saveUserPreferences($userId, $preferences);
        } else {
            // Save to global installed file (admin default)
            // This sets the default pinned status for all users
            // Individual users can override this in their preferences
            $installed = $this->getInstalledApps();
            
            if (!isset($installed[$appId])) {
                return false;
            }
            
            $installed[$appId]['pinned'] = $pinned;
            return $this->saveInstalledApps($installed);
        }
    }
    
    /**
     * Set app sidebar order
     * Takes an array of app IDs in the desired order
     * 
     * @param array $order Array of app IDs in desired order
     * @param string|null $userId Optional user ID. If provided, saves to user preferences. If null, saves to global (backward compatibility).
     * @return bool Success status
     */
    public function setAppOrder(array $order, ?string $userId = null): bool {
        if ($userId) {
            // Save to user preferences
            $preferences = $this->getUserPreferences($userId);
            
            // Update sidebarOrder for each app based on its position in the array
            foreach ($order as $index => $appId) {
                if (!isset($preferences['apps'][$appId])) {
                    $preferences['apps'][$appId] = [];
                }
                $preferences['apps'][$appId]['sidebarOrder'] = $index;
            }
            
            // Remove sidebarOrder from apps not in the order array (they'll use default sorting)
            foreach ($preferences['apps'] as $appId => &$appPrefs) {
                if (!in_array($appId, $order)) {
                    unset($appPrefs['sidebarOrder']);
                    // Remove app entry entirely if it has no other preferences
                    if (empty($appPrefs)) {
                        unset($preferences['apps'][$appId]);
                    }
                }
            }
            
            return $this->saveUserPreferences($userId, $preferences);
        } else {
            // Save to global installed file (backward compatibility)
            $installed = $this->getInstalledApps();
            
            // Update sidebarOrder for each app based on its position in the array
            foreach ($order as $index => $appId) {
                if (isset($installed[$appId])) {
                    $installed[$appId]['sidebarOrder'] = $index;
                }
            }
            
            // Remove sidebarOrder from apps not in the order array (they'll use default sorting)
            foreach ($installed as $appId => &$info) {
                if (!in_array($appId, $order)) {
                    unset($info['sidebarOrder']);
                }
            }
            
            return $this->saveInstalledApps($installed);
        }
    }
    
    // ==================
    // USER PREFERENCES MANAGEMENT
    // ==================
    
    /**
     * Get path to user preferences file
     * Creates directory if it doesn't exist
     */
    public function getUserPreferencesPath(string $userId): string {
        $userDir = self::USERS_DATA_DIR . '/' . $userId;
        
        if (!is_dir($userDir)) {
            mkdir($userDir, 0755, true);
        }
        
        return $userDir . '/app-preferences.yaml';
    }
    
    /**
     * Get user preferences for apps (pinned, sidebarOrder)
     * Returns array with structure: ['apps' => ['app-id' => ['pinned' => bool, 'sidebarOrder' => int]]]
     */
    public function getUserPreferences(string $userId): array {
        $prefsFile = $this->getUserPreferencesPath($userId);
        
        if (!file_exists($prefsFile)) {
            return ['apps' => []];
        }
        
        if (!function_exists('yaml_parse_file')) {
            return ['apps' => []];
        }
        
        $data = yaml_parse_file($prefsFile);
        if (!$data || !is_array($data)) {
            return ['apps' => []];
        }
        
        // Ensure 'apps' key exists
        if (!isset($data['apps']) || !is_array($data['apps'])) {
            return ['apps' => []];
        }
        
        return $data;
    }
    
    /**
     * Save user preferences
     * Returns false if file system is read-only (e.g., in FPM containers)
     */
    public function saveUserPreferences(string $userId, array $preferences): bool {
        $prefsFile = $this->getUserPreferencesPath($userId);
        
        // Check if file is writable (may be read-only in FPM containers)
        if (file_exists($prefsFile) && !is_writable($prefsFile)) {
            return false;
        }
        
        // Check if directory is writable
        $dir = dirname($prefsFile);
        if (!is_writable($dir) && is_dir($dir)) {
            return false;
        }
        
        // Ensure 'apps' key exists
        if (!isset($preferences['apps']) || !is_array($preferences['apps'])) {
            $preferences['apps'] = [];
        }

        return $this->writeYamlFile($prefsFile, $preferences);
    }

    /**
     * Atomically write YAML to disk to avoid partial/corrupted writes.
     */
    private function writeYamlFile(string $path, array $data): bool {
        if (!function_exists('yaml_emit')) {
            return false;
        }

        // Check if file is writable (may be read-only in FPM containers)
        if (file_exists($path) && !is_writable($path)) {
            return false;
        }

        // Check if directory is writable
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }
        if (!is_writable($dir)) {
            return false;
        }

        $yaml = yaml_emit($data);
        $tmp = tempnam($dir, 'yaml-');
        if ($tmp === false) {
            return false;
        }

        $written = @file_put_contents($tmp, $yaml, LOCK_EX);
        if ($written === false) {
            @unlink($tmp);
            return false;
        }

        if (file_exists($path)) {
            $perms = fileperms($path) & 0777;
            @chmod($tmp, $perms);
        }

        $renamed = @rename($tmp, $path);
        if (!$renamed) {
            @unlink($tmp);
        } else {
            $desiredMode = $this->getWritableModeForPath($path);
            if ($desiredMode !== null) {
                @chmod($path, $desiredMode);
            }
        }

        return $renamed;
    }

    private function getWritableModeForPath(string $path): ?int {
        $normalizedPath = str_replace('\\', '/', $path);
        $installedRoot = str_replace('\\', '/', self::INSTALLED_APPS_CODE_DIR);
        if ($normalizedPath === str_replace('\\', '/', self::RUNTIME_INSTALLED_FILE)) {
            return 0666;
        }

        if (str_starts_with($normalizedPath, $installedRoot . '/')) {
            return is_dir($path) ? 0777 : 0666;
        }

        return null;
    }
    
    /**
     * Get a specific user preference for an app
     * 
     * @param string $userId User ID
     * @param string $appId App ID
     * @param string $key Preference key ('pinned' or 'sidebarOrder')
     * @param mixed $default Default value if not set
     * @return mixed
     */
    public function getUserAppPreference(string $userId, string $appId, string $key, $default = null) {
        $preferences = $this->getUserPreferences($userId);
        
        if (!isset($preferences['apps'][$appId])) {
            return $default;
        }
        
        if (!isset($preferences['apps'][$appId][$key])) {
            return $default;
        }
        
        return $preferences['apps'][$appId][$key];
    }
    
    /**
     * Get a specific app
     */
    public function getApp(string $appId): ?array {
        return $this->registeredApps[$appId] ?? null;
    }
    
    /**
     * Get app config directory (creates if not exists)
     */
    public function getAppDataDir(string $appId): string {
        $dir = self::APPS_DATA_DIR . '/' . $appId;
        
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        return $dir;
    }
    
    /**
     * Load app settings
     */
    public function loadSettings(string $appId): array {
        $settingsFile = $this->getAppDataDir($appId) . '/settings.yaml';
        
        if (!file_exists($settingsFile)) {
            return $this->getDefaultSettings($appId);
        }
        
        if (!function_exists('yaml_parse_file')) {
            error_log("YAML extension not available");
            return $this->getDefaultSettings($appId);
        }
        
        $settings = yaml_parse_file($settingsFile);
        return $settings ?: $this->getDefaultSettings($appId);
    }
    
    /**
     * Save app settings
     */
    public function saveSettings(string $appId, array $settings, string $userId, string $username): array {
        $dataDir = $this->getAppDataDir($appId);
        $settingsFile = $dataDir . '/settings.yaml';
        
        // Create backup if exists
        if (file_exists($settingsFile)) {
            $historyDir = $dataDir . '/.history';
            if (!is_dir($historyDir)) {
                mkdir($historyDir, 0755, true);
            }
            $backupName = 'settings.' . date('Y-m-d_H-i-s') . '.yaml';
            copy($settingsFile, $historyDir . '/' . $backupName);
        }
        
        // Add metadata
        $settings['_metadata'] = [
            'updatedAt' => date('c'),
            'updatedBy' => $username
        ];
        
        if (!function_exists('yaml_emit')) {
            return ['success' => false, 'error' => 'YAML extension not available'];
        }
        
        $yaml = yaml_emit($settings, YAML_UTF8_ENCODING);
        
        if (file_put_contents($settingsFile, $yaml) === false) {
            return ['success' => false, 'error' => 'Failed to write settings file'];
        }
        
        return ['success' => true];
    }
    
    /**
     * Get default settings for an app
     */
    private function getDefaultSettings(string $appId): array {
        switch ($appId) {
            case 'playwright':
                return [
                    'storage' => [
                        'reportsDir' => 'playwright-reports-archive',
                        'resultsDir' => 'playwright-results',
                        'maxReports' => 50,
                        'retentionDays' => 30
                    ],
                    'defaults' => [
                        'timeout' => 30000,
                        'retries' => 0,
                        'workers' => 1,
                        'browser' => 'chromium',
                        'headless' => true
                    ],
                    'notifications' => [
                        'enabled' => false,
                        'onFailure' => true,
                        'onSuccess' => false,
                        'webhookUrl' => ''
                    ],
                    'gitProviders' => [],
                    'testEnvironments' => []
                ];
            default:
                return [];
        }
    }
    
    /**
     * Validate Playwright settings
     */
    public function validatePlaywrightSettings(array $settings): array {
        $errors = [];
        
        // Validate storage settings
        if (isset($settings['storage'])) {
            if (isset($settings['storage']['maxReports']) && 
                (!is_numeric($settings['storage']['maxReports']) || $settings['storage']['maxReports'] < 1)) {
                $errors[] = 'Max reports must be a positive number';
            }
            if (isset($settings['storage']['retentionDays']) && 
                (!is_numeric($settings['storage']['retentionDays']) || $settings['storage']['retentionDays'] < 1)) {
                $errors[] = 'Retention days must be a positive number';
            }
        }
        
        // Validate defaults
        if (isset($settings['defaults'])) {
            if (isset($settings['defaults']['timeout']) && 
                (!is_numeric($settings['defaults']['timeout']) || $settings['defaults']['timeout'] < 1000)) {
                $errors[] = 'Timeout must be at least 1000ms';
            }
            if (isset($settings['defaults']['retries']) && 
                (!is_numeric($settings['defaults']['retries']) || $settings['defaults']['retries'] < 0)) {
                $errors[] = 'Retries must be 0 or more';
            }
            if (isset($settings['defaults']['workers']) && 
                (!is_numeric($settings['defaults']['workers']) || $settings['defaults']['workers'] < 1)) {
                $errors[] = 'Workers must be at least 1';
            }
            if (isset($settings['defaults']['browser']) && 
                !in_array($settings['defaults']['browser'], ['chromium', 'firefox', 'webkit'])) {
                $errors[] = 'Browser must be chromium, firefox, or webkit';
            }
        }
        
        // Validate git providers
        if (isset($settings['gitProviders']) && is_array($settings['gitProviders'])) {
            foreach ($settings['gitProviders'] as $index => $provider) {
                if (empty($provider['id'])) {
                    $errors[] = "Git provider #" . ($index + 1) . " must have an ID";
                }
                if (empty($provider['type']) || !in_array($provider['type'], ['github', 'gitlab', 'bitbucket'])) {
                    $errors[] = "Git provider #" . ($index + 1) . " must have a valid type (github, gitlab, bitbucket)";
                }
            }
        }
        
        // Validate test environments
        if (isset($settings['testEnvironments']) && is_array($settings['testEnvironments'])) {
            foreach ($settings['testEnvironments'] as $index => $env) {
                if (empty($env['id'])) {
                    $errors[] = "Test environment #" . ($index + 1) . " must have an ID";
                }
                if (empty($env['name'])) {
                    $errors[] = "Test environment #" . ($index + 1) . " must have a name";
                }
                if (!empty($env['baseUrl']) && !filter_var($env['baseUrl'], FILTER_VALIDATE_URL)) {
                    $errors[] = "Test environment '{$env['id']}' has an invalid base URL";
                }
            }
        }
        
        return $errors;
    }
    
    // ==================
    // TRUST LEVEL MANAGEMENT
    // ==================
    
    /**
     * Get installed apps registry
     */
    public function getInstalledApps(): array {
        return self::loadInstalledAppsRegistry();
    }
    
    /**
     * Save installed apps registry
     * Returns false if file system is read-only (e.g., in FPM containers)
     */
    private function saveInstalledApps(array $apps): bool {
        $data = ['apps' => $apps];
        return $this->writeYamlFile(self::RUNTIME_INSTALLED_FILE, $data);
    }

    private function normalizeInstalledAppPermissionsIfNeeded(): void {
        $currentVersion = @file_get_contents(self::INSTALLED_APPS_PERMISSIONS_MARKER);
        if (is_string($currentVersion) && trim($currentVersion) === self::INSTALLED_APPS_PERMISSIONS_VERSION) {
            return;
        }

        $this->normalizeInstalledAppTree(self::INSTALLED_APPS_CODE_DIR);
        if (file_exists(self::RUNTIME_INSTALLED_FILE)) {
            @chmod(self::RUNTIME_INSTALLED_FILE, 0666);
        }

        @file_put_contents(self::INSTALLED_APPS_PERMISSIONS_MARKER, self::INSTALLED_APPS_PERMISSIONS_VERSION . "\n");
        @chmod(self::INSTALLED_APPS_PERMISSIONS_MARKER, 0666);
    }

    private function normalizeInstalledAppTree(string $path): void {
        if (!file_exists($path)) {
            return;
        }

        if (is_dir($path)) {
            @chmod($path, 0777);

            $items = @scandir($path);
            if (!is_array($items)) {
                return;
            }

            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }

                $this->normalizeInstalledAppTree($path . '/' . $item);
            }

            return;
        }

        $mode = 0666;
        $currentPerms = @fileperms($path);
        if (is_int($currentPerms) && ($currentPerms & 0111) !== 0) {
            $mode = 0777;
        }

        @chmod($path, $mode);
    }
    
    /**
     * Get trust level for an app
     */
    public function getTrustLevel(string $appId): int {
        $installed = $this->getInstalledApps();
        
        if (isset($installed[$appId])) {
            return $installed[$appId]['trustLevel'] ?? AppCapabilities::TRUST_SANDBOXED;
        }
        
        // Check if it's a core app (has 'core: true' in manifest)
        $app = $this->getApp($appId);
        if ($app && ($app['core'] ?? false)) {
            return AppCapabilities::TRUST_CORE;
        }
        
        return AppCapabilities::TRUST_SANDBOXED;
    }

    /**
     * Set exec target for an app (used by exec.docker apps)
     */
    public function setAppExecTarget(string $appId, ?string $targetId, string $userId, string $username): array {
        $installed = $this->getInstalledApps();
        
        if (!isset($installed[$appId])) {
            // Create entry for existing app
            $installed[$appId] = [
                'source' => 'local',
                'installedAt' => date('c'),
                'installedBy' => $username,
            ];
        }
        
        if ($targetId) {
            $installed[$appId]['execTarget'] = $targetId;
        } else {
            unset($installed[$appId]['execTarget']);
        }
        
        if (!$this->saveInstalledApps($installed)) {
            return ['success' => false, 'error' => 'Failed to save'];
        }
        
        return ['success' => true];
    }
    
    /**
     * Set trust level for an app (super-admin only)
     */
    public function setTrustLevel(string $appId, int $trustLevel, string $userId, string $username, string $reason = ''): array {
        if ($trustLevel < AppCapabilities::TRUST_CORE || $trustLevel > AppCapabilities::TRUST_SANDBOXED) {
            return ['success' => false, 'error' => 'Invalid trust level'];
        }
        
        // Core level can't be set manually
        if ($trustLevel === AppCapabilities::TRUST_CORE) {
            return ['success' => false, 'error' => 'Cannot set Core trust level manually'];
        }
        
        $installed = $this->getInstalledApps();
        
        if (!isset($installed[$appId])) {
            // Create entry for existing app
            $installed[$appId] = [
                'source' => 'local',
                'installedAt' => date('c'),
                'installedBy' => $username,
            ];
        }
        
        $oldLevel = $installed[$appId]['trustLevel'] ?? AppCapabilities::TRUST_SANDBOXED;
        $installed[$appId]['trustLevel'] = $trustLevel;
        $installed[$appId]['trustChangedAt'] = date('c');
        $installed[$appId]['trustChangedBy'] = $username;
        $installed[$appId]['trustChangeReason'] = $reason;
        
        if (!$this->saveInstalledApps($installed)) {
            return ['success' => false, 'error' => 'Failed to save'];
        }
        
        return ['success' => true, 'oldLevel' => $oldLevel, 'newLevel' => $trustLevel];
    }
    
    /**
     * Get app with full info including trust level
     */
    public function getAppWithTrust(string $appId): ?array {
        $app = $this->getApp($appId);
        if (!$app) {
            return null;
        }
        
        $trustLevel = $this->getTrustLevel($appId);
        $app['trustLevel'] = $trustLevel;
        $app['trustInfo'] = AppCapabilities::getTrustLevelInfo($trustLevel);
        $app['capabilities'] = AppCapabilities::forTrustLevel($trustLevel);
        
        // Get installed info
        $installed = $this->getInstalledApps();
        if (isset($installed[$appId])) {
            $app['installedInfo'] = $installed[$appId];
        }
        
        return $app;
    }
    
    // ==================
    // APP SOURCES (Delegates to SourcesManager - Batch 13)
    // ==================
    
    /**
     * Get SourcesManager instance
     */
    private function getSourcesManager(): SourcesManager {
        require_once __DIR__ . '/SourcesManager.php';
        return new SourcesManager();
    }

    /**
     * Get StudioReleaseManager instance.
     */
    private function getStudioReleaseManager(): StudioReleaseManager {
        require_once __DIR__ . '/StudioReleaseManager.php';
        return new StudioReleaseManager();
    }
    
    /**
     * Get configured app sources (from SourcesManager - repositories of type 'apps' or 'mixed')
     */
    public function getSources(): array {
        $sourcesManager = $this->getSourcesManager();
        $allRepos = $sourcesManager->getRepositories();
        
        // Filter to only apps and mixed type repositories
        return array_values(array_filter($allRepos, fn($r) => in_array($r['type'], ['apps', 'mixed'])));
    }
    
    /**
     * Save app sources - deprecated, use SourcesManager directly
     * @deprecated Use SourcesManager::createRepository() instead
     */
    public function saveSources(array $sources): array {
        return ['success' => false, 'error' => 'Use SourcesManager to manage sources'];
    }
    
    /**
     * Add a new app source - now creates a repository in SourcesManager
     */
    public function addSource(array $source, string $userId, string $username): array {
        $sourcesManager = $this->getSourcesManager();
        
        return $sourcesManager->createRepository([
            'id' => $source['id'] ?? null,
            'name' => $source['name'] ?? $source['id'],
            'url' => $source['url'] ?? '',
            'type' => 'apps',
            'providerId' => $source['provider'] ?? null,
            'branch' => $source['branch'] ?? 'main',
            'defaultTrust' => $source['defaultTrust'] ?? AppCapabilities::TRUST_SANDBOXED,
        ], $userId, $username);
    }
    
    /**
     * Remove an app source
     */
    public function removeSource(string $sourceId): array {
        $sourcesManager = $this->getSourcesManager();
        return $sourcesManager->deleteRepository($sourceId);
    }
    
    /**
     * Sync apps from a source
     */
    public function syncSource(string $sourceId): array {
        $sourcesManager = $this->getSourcesManager();
        $result = $sourcesManager->syncRepository($sourceId);
        
        if ($result['success']) {
            // Return apps in the expected format
            $apps = $result['discovered']['apps'] ?? [];
            return ['success' => true, 'apps' => $apps];
        }
        
        return $result;
    }
    
    /**
     * Get apps available from sources
     */
    public function getAvailableApps(): array {
        $available = [];
        $currentApps = array_keys($this->registeredApps);
        $installedRegistry = $this->getInstalledApps();
        $sourcesManager = $this->getSourcesManager();
        
        $sources = $this->getSources();
        foreach ($sources as $source) {
            $cacheDir = $sourcesManager->getRepositoryCacheDir($source['id']);
            if (!$cacheDir) {
                continue;
            }
            
            // Discover apps in the cache
            $content = $sourcesManager->discoverRepositoryContent($source['id']);
            $apps = $content['apps'] ?? [];
            
            foreach ($apps as $app) {
                $appId = $app['id'];
                $installedInfo = $installedRegistry[$appId] ?? null;
                $installedApp = $this->registeredApps[$appId] ?? null;
                $installedVersion = $installedApp['version'] ?? ($installedInfo['version'] ?? null);
                $installedFromThisSource = (($installedInfo['source'] ?? null) === 'git')
                    && (($installedInfo['sourceId'] ?? null) === $source['id']);

                $app['installed'] = in_array($appId, $currentApps);
                $app['installedVersion'] = $installedVersion;
                $app['installedFromThisSource'] = $installedFromThisSource;
                $app['sourceId'] = $source['id'];
                $app['sourceName'] = $source['name'];
                $app['sourceDefaultTrustLevel'] = $this->getSourceDefaultTrust($source);
                $app['trustProfile'] = AppCapabilities::getManifestTrustProfile($app, $app['sourceDefaultTrustLevel']);
                $app['versionDelta'] = $installedFromThisSource
                    ? $this->summarizeAvailableVersionDelta($installedVersion, $app['version'] ?? null)
                    : null;
                $available[] = $app;
            }
        }

        $studioReleaseManager = $this->getStudioReleaseManager();
        foreach ($studioReleaseManager->listPublishedMarketplaceApps() as $release) {
            $manifest = is_array($release['manifest'] ?? null) ? $release['manifest'] : [];
            $appId = trim((string)($release['app_id'] ?? $manifest['id'] ?? ''));
            if ($appId === '') {
                continue;
            }

            $installedInfo = $installedRegistry[$appId] ?? null;
            $installedApp = $this->registeredApps[$appId] ?? null;
            $installedVersion = $installedApp['version'] ?? ($installedInfo['version'] ?? null);
            $installedFromThisSource = (($installedInfo['source'] ?? null) === 'studio')
                && (($installedInfo['sourceId'] ?? null) === StudioReleaseManager::MARKETPLACE_SOURCE_ID);

            $app = array_merge([
                'id' => $appId,
                'name' => $manifest['name'] ?? $appId,
                'description' => $manifest['description'] ?? '',
                'icon' => $manifest['icon'] ?? 'fa-puzzle-piece',
                'color' => $manifest['color'] ?? '#2563eb',
                'version' => $release['version'] ?? ($manifest['version'] ?? '1.0.0'),
                'enabled' => $manifest['enabled'] ?? true,
                'navigation' => $manifest['navigation'] ?? [],
                'tags' => $manifest['tags'] ?? [],
            ], $manifest);

            $app['id'] = $appId;
            $app['installed'] = in_array($appId, $currentApps, true);
            $app['installedVersion'] = $installedVersion;
            $app['installedFromThisSource'] = $installedFromThisSource;
            $app['sourceId'] = StudioReleaseManager::MARKETPLACE_SOURCE_ID;
            $app['sourceName'] = $release['sourceName'] ?? StudioReleaseManager::MARKETPLACE_SOURCE_NAME;
            $app['sourceKind'] = 'studio';
            $app['releaseId'] = $release['id'] ?? null;
            $app['publishedAt'] = $release['published_at'] ?? null;
            $app['sourceDefaultTrustLevel'] = AppCapabilities::TRUST_SANDBOXED;
            $app['trustProfile'] = AppCapabilities::getManifestTrustProfile($manifest, AppCapabilities::TRUST_SANDBOXED);
            $app['versionDelta'] = $installedFromThisSource
                ? $this->summarizeAvailableVersionDelta($installedVersion, $app['version'] ?? null)
                : null;
            $available[] = $app;
        }
        
        return $available;
    }
    
    // ==================
    // APP INSTALLATION
    // ==================
    
    /**
     * Install an app from a source
     */
    public function installApp(string $sourceId, string $appId, string $userId, string $username, ?int $trustLevel = null): array {
        if ($sourceId === StudioReleaseManager::MARKETPLACE_SOURCE_ID) {
            return $this->installStudioMarketplaceApp($appId, $userId, $username, $trustLevel);
        }

        $source = $this->findSourceById($sourceId);
        
        if (!$source) {
            return ['success' => false, 'error' => 'Source not found'];
        }

        $appSourceDir = $this->resolveSourceAppDirectory($sourceId, $appId);
        if ($appSourceDir === null) {
            return ['success' => false, 'error' => 'App not found in source'];
        }
        
        // Validate manifest
        $manifestFile = $appSourceDir . '/manifest.yaml';
        if (!file_exists($manifestFile)) {
            return ['success' => false, 'error' => 'App has no manifest.yaml'];
        }

        $manifest = function_exists('yaml_parse_file') ? (yaml_parse_file($manifestFile) ?: []) : [];
        $sourceDefaultTrust = $this->getSourceDefaultTrust($source);
        $trustProfile = AppCapabilities::getManifestTrustProfile($manifest, $sourceDefaultTrust);
        $minimumTrust = $trustProfile['minimumTrustLevel'] ?? null;
        if ($minimumTrust === null) {
            return ['success' => false, 'error' => 'App declares required capabilities that no installable trust level satisfies'];
        }

        // Copy app to apps directory
        $targetDir = self::INSTALLED_APPS_CODE_DIR . '/' . $appId;
        if (is_dir($targetDir)) {
            return ['success' => false, 'error' => 'App already installed'];
        }

        $finalTrustLevel = $trustLevel ?? ($trustProfile['installDefaultTrustLevel'] ?? $minimumTrust);
        if ($finalTrustLevel > $minimumTrust) {
            $required = $trustProfile['minimumTrustInfo']['name'] ?? 'a higher trust level';
            return [
                'success' => false,
                'error' => "App requires {$required} or higher based on its declared capabilities",
                'requiredTrustLevel' => $minimumTrust,
                'requiredTrustInfo' => $trustProfile['minimumTrustInfo'] ?? null,
                'trustProfile' => $trustProfile,
            ];
        }

        // Copy files
        if (!$this->copyDirectory($appSourceDir, $targetDir)) {
            $this->deleteDirectory($targetDir);
            return ['success' => false, 'error' => 'Failed to copy app files'];
        }
        
        // Register in installed apps
        $installed = $this->getInstalledApps();
        $installed[$appId] = [
            'source' => 'git',
            'sourceId' => $sourceId,
            'sourceUrl' => $source['url'],
            'version' => $this->getAppVersion($targetDir),
            'trustLevel' => $finalTrustLevel,
            'installedAt' => date('c'),
            'installedBy' => $username,
        ];
        
        if (!$this->saveInstalledApps($installed)) {
            $this->deleteDirectory($targetDir);
            return ['success' => false, 'error' => 'Failed to save installed app registry'];
        }
        
        // Create data directory
        $this->getAppDataDir($appId);
        
        // Refresh app list
        $this->discoverApps();
        
        return ['success' => true, 'trustLevel' => $finalTrustLevel, 'trustProfile' => $trustProfile];
    }

    /**
     * Install or update an app from an exact Studio release snapshot.
     */
    public function installStudioRelease(string $releaseId, string $userId, string $username, ?int $trustLevel = null): array {
        $release = $this->getStudioReleaseManager()->getRelease($releaseId);
        if ($release === null) {
            return ['success' => false, 'error' => 'Studio release not found'];
        }

        return $this->installStudioReleaseSnapshot($release, $userId, $username, $trustLevel, true);
    }
    
    /**
     * Build a review payload for an app update without applying it.
     */
    public function previewAppUpdate(string $appId): array {
        $inspection = $this->inspectAppUpdate($appId, true);
        if (!$inspection['success']) {
            return $inspection;
        }

        return $this->stripAppUpdateInternals($inspection);
    }

    /**
     * Update an app from source
     */
    public function updateApp(string $appId, string $userId, string $username): array {
        $inspection = $this->inspectAppUpdate($appId, true);
        if (!$inspection['success']) {
            return $inspection;
        }

        if (!($inspection['canUpdate'] ?? true)) {
            $blocked = $this->stripAppUpdateInternals($inspection);
            $blocked['success'] = false;
            return $blocked;
        }

        $installed = $this->getInstalledApps();
        $targetDir = $inspection['targetDir'];
        $appSourceDir = $inspection['appSourceDir'];
        $newManifest = $inspection['newManifest'];
        $version = $inspection['version'];
        $runtime = $inspection['runtime'];
        $trustProfile = $inspection['trustProfile'];
        $currentTrustLevel = $inspection['currentTrustLevel'];

        $timestamp = date('Y-m-d_H-i-s');
        $backupDir = $targetDir . '.backup.' . $timestamp;
        $stageDir = $targetDir . '.staging.' . $timestamp;

        if (!$this->copyDirectory($appSourceDir, $stageDir)) {
            $this->deleteDirectory($stageDir);
            return ['success' => false, 'error' => 'Failed to stage updated app files'];
        }

        if (!rename($targetDir, $backupDir)) {
            $this->deleteDirectory($stageDir);
            return ['success' => false, 'error' => 'Failed to back up current app files'];
        }

        if (!rename($stageDir, $targetDir)) {
            $this->deleteDirectory($stageDir);
            @rename($backupDir, $targetDir);
            return ['success' => false, 'error' => 'Failed to activate updated app files'];
        }

        $installed[$appId]['version'] = $version['current'] ?? '1.0.0';
        $installed[$appId]['updatedAt'] = date('c');
        $installed[$appId]['updatedBy'] = $username;

        if (!$this->saveInstalledApps($installed)) {
            $this->deleteDirectory($targetDir);
            @rename($backupDir, $targetDir);
            return ['success' => false, 'error' => 'Failed to save installed app registry'];
        }

        if ($runtime['changed']) {
            require_once __DIR__ . '/AppImageBuilder.php';
            $builder = new AppImageBuilder($appId, $newManifest);
            if ($runtime['currentNeedsCustomImage']) {
                $builder->markRuntimeStale('Runtime requirements changed after the app update. Rebuild required.');
            } elseif ($runtime['cleanupRecommended']) {
                $builder->removeImage();
                $runtime['cleanupPerformed'] = true;
                $runtime['cleanupRecommended'] = false;
            } else {
                $builder->removeStatusFile();
            }
        }

        $this->deleteDirectory($backupDir);
        
        // Refresh app list
        $this->discoverApps();
        
        return [
            'success' => true,
            'version' => $version,
            'runtime' => $runtime,
            'trustProfile' => $trustProfile,
            'trustLevel' => $currentTrustLevel,
        ];
    }
    
    /**
     * Uninstall an app
     */
    public function uninstallApp(string $appId, bool $deleteData, string $userId, string $username): array {
        $app = $this->getApp($appId);
        if (!$app) {
            return ['success' => false, 'error' => 'App not found'];
        }
        
        // Don't allow uninstalling core apps
        if ($app['core'] ?? false) {
            return ['success' => false, 'error' => 'Cannot uninstall core apps'];
        }
        
        if ($this->isBundledAppPath((string)($app['path'] ?? ''))) {
            return ['success' => false, 'error' => 'Cannot uninstall bundled apps'];
        }

        $appDir = self::INSTALLED_APPS_CODE_DIR . '/' . $appId;
        $dataDir = self::APPS_DATA_DIR . '/' . $appId;
        
        // Remove app code
        if (is_dir($appDir)) {
            $this->deleteDirectory($appDir);
        }
        
        // Remove data if requested
        if ($deleteData && is_dir($dataDir)) {
            $this->deleteDirectory($dataDir);
        }
        
        // Remove from registry
        $installed = $this->getInstalledApps();
        $installedInfo = $installed[$appId] ?? null;
        unset($installed[$appId]);
        $this->saveInstalledApps($installed);

        if (is_array($installedInfo) && ($installedInfo['source'] ?? null) === 'studio') {
            $workspaceId = trim((string)($installedInfo['sourceWorkspaceId'] ?? ''));
            if ($workspaceId !== '') {
                $this->getStudioReleaseManager()->clearWorkspaceLinkedInstalledApp($workspaceId, $userId);
            }
        }
        
        // Refresh app list
        $this->discoverApps();
        
        return ['success' => true];
    }
    
    // ==================
    // UTILITIES
    // ==================
    
    /**
     * Get authenticated URL for git operations
     * Uses SourcesManager for centralized credential management (Batch 13)
     */
    private function getAuthenticatedUrl(string $url, ?string $providerId): string {
        if (!$providerId) {
            return $url;
        }
        
        // Use SourcesManager for centralized Git provider credentials
        require_once __DIR__ . '/SourcesManager.php';
        $sourcesManager = new SourcesManager();
        
        return $sourcesManager->getAuthenticatedUrl($url, $providerId);
    }
    
    /**
     * Sanitize git output
     */
    private function sanitizeGitOutput(array $output): string {
        $text = implode("\n", $output);
        $text = preg_replace('/https:\/\/[^@]+@/', 'https://***@', $text);
        return $text;
    }
    
    /**
     * Get app version from manifest
     */
    private function getAppVersion(string $appDir): string {
        $manifest = $this->loadManifestFromAppDir($appDir);
        return $manifest['version'] ?? '1.0.0';
    }

    /**
     * Install the latest published Studio marketplace release for an app ID.
     */
    private function installStudioMarketplaceApp(string $appId, string $userId, string $username, ?int $trustLevel = null): array {
        $release = $this->getStudioReleaseManager()->getLatestPublishedReleaseForApp($appId);
        if ($release === null) {
            return ['success' => false, 'error' => 'Published Studio app not found'];
        }

        return $this->installStudioReleaseSnapshot($release, $userId, $username, $trustLevel, false);
    }

    /**
     * Install or update from a Studio release snapshot.
     */
    private function installStudioReleaseSnapshot(array $release, string $userId, string $username, ?int $trustLevel, bool $allowExistingStudioUpdate): array {
        $manifest = is_array($release['manifest'] ?? null) ? $release['manifest'] : [];
        $appId = trim((string)($release['app_id'] ?? $manifest['id'] ?? ''));
        $artifactPath = trim((string)($release['artifactPath'] ?? $release['artifact_path'] ?? ''));
        if ($appId === '' || $artifactPath === '' || !is_dir($artifactPath)) {
            return ['success' => false, 'error' => 'Studio release artifact is missing'];
        }

        $trustProfile = AppCapabilities::getManifestTrustProfile($manifest, AppCapabilities::TRUST_SANDBOXED);
        $minimumTrust = $trustProfile['minimumTrustLevel'] ?? null;
        if ($minimumTrust === null) {
            return ['success' => false, 'error' => 'App declares required capabilities that no installable trust level satisfies'];
        }

        $installed = $this->getInstalledApps();
        $targetDir = self::INSTALLED_APPS_CODE_DIR . '/' . $appId;
        $existingInfo = $installed[$appId] ?? null;
        $isInstalled = is_dir($targetDir) || is_array($existingInfo);

        $defaultTrust = $trustProfile['installDefaultTrustLevel'] ?? $minimumTrust;
        if ($isInstalled && is_array($existingInfo) && $trustLevel === null) {
            $defaultTrust = AppCapabilities::parseTrustLevel(
                $existingInfo['trustLevel'] ?? $defaultTrust,
                false
            ) ?? $defaultTrust;
        }

        $finalTrustLevel = $trustLevel ?? $defaultTrust;
        if ($finalTrustLevel > $minimumTrust) {
            $required = $trustProfile['minimumTrustInfo']['name'] ?? 'a higher trust level';
            return [
                'success' => false,
                'error' => "App requires {$required} or higher based on its declared capabilities",
                'requiredTrustLevel' => $minimumTrust,
                'requiredTrustInfo' => $trustProfile['minimumTrustInfo'] ?? null,
                'trustProfile' => $trustProfile,
            ];
        }

        if (!$isInstalled) {
            if (!$this->copyDirectory($artifactPath, $targetDir)) {
                $this->deleteDirectory($targetDir);
                return ['success' => false, 'error' => 'Failed to copy app files'];
            }

            $installed[$appId] = [
                'source' => 'studio',
                'sourceId' => StudioReleaseManager::MARKETPLACE_SOURCE_ID,
                'sourceReleaseId' => $release['id'] ?? null,
                'sourceWorkspaceId' => $release['workspace_id'] ?? null,
                'version' => $release['version'] ?? ($manifest['version'] ?? '1.0.0'),
                'trustLevel' => $finalTrustLevel,
                'installedAt' => date('c'),
                'installedBy' => $username,
            ];

            if (!$this->saveInstalledApps($installed)) {
                $this->deleteDirectory($targetDir);
                return ['success' => false, 'error' => 'Failed to save installed app registry'];
            }

            $workspaceId = trim((string)($release['workspace_id'] ?? ''));
            if ($workspaceId !== '') {
                $this->getStudioReleaseManager()->setWorkspaceLinkedInstalledApp($workspaceId, $appId, $userId);
            }

            $this->getAppDataDir($appId);
            $this->discoverApps();

            return [
                'success' => true,
                'action' => 'installed',
                'appId' => $appId,
                'trustLevel' => $finalTrustLevel,
                'trustProfile' => $trustProfile,
                'release' => $release,
            ];
        }

        if (!$allowExistingStudioUpdate) {
            return ['success' => false, 'error' => 'App already installed'];
        }

        if (!is_array($existingInfo)) {
            return ['success' => false, 'error' => 'Installed app registry entry is missing'];
        }

        if (!is_dir($targetDir)) {
            return ['success' => false, 'error' => 'Installed app directory is missing'];
        }

        $currentManifest = $this->loadManifestFromAppDir($targetDir);
        $runtime = $this->summarizeRuntimeChange($currentManifest, $manifest);
        $timestamp = date('Y-m-d_H-i-s');
        $backupDir = $targetDir . '.backup.' . $timestamp;
        $stageDir = $targetDir . '.staging.' . $timestamp;

        if (!$this->copyDirectory($artifactPath, $stageDir)) {
            $this->deleteDirectory($stageDir);
            return ['success' => false, 'error' => 'Failed to stage Studio release files'];
        }

        if (!rename($targetDir, $backupDir)) {
            $this->deleteDirectory($stageDir);
            return ['success' => false, 'error' => 'Failed to back up current app files'];
        }

        if (!rename($stageDir, $targetDir)) {
            $this->deleteDirectory($stageDir);
            @rename($backupDir, $targetDir);
            return ['success' => false, 'error' => 'Failed to activate Studio release files'];
        }

        $installed[$appId]['source'] = 'studio';
        $installed[$appId]['sourceId'] = StudioReleaseManager::MARKETPLACE_SOURCE_ID;
        $installed[$appId]['sourceReleaseId'] = $release['id'] ?? null;
        $installed[$appId]['sourceWorkspaceId'] = $release['workspace_id'] ?? null;
        $installed[$appId]['version'] = $release['version'] ?? ($manifest['version'] ?? '1.0.0');
        $installed[$appId]['trustLevel'] = $finalTrustLevel;
        $installed[$appId]['updatedAt'] = date('c');
        $installed[$appId]['updatedBy'] = $username;

        if (!$this->saveInstalledApps($installed)) {
            $this->deleteDirectory($targetDir);
            @rename($backupDir, $targetDir);
            return ['success' => false, 'error' => 'Failed to save installed app registry'];
        }

        $workspaceId = trim((string)($release['workspace_id'] ?? ''));
        if ($workspaceId !== '') {
            $this->getStudioReleaseManager()->setWorkspaceLinkedInstalledApp($workspaceId, $appId, $userId);
        }

        if ($runtime['changed']) {
            require_once __DIR__ . '/AppImageBuilder.php';
            $builder = new AppImageBuilder($appId, $manifest);
            if ($runtime['currentNeedsCustomImage']) {
                $builder->markRuntimeStale('Runtime requirements changed after the Studio release update. Rebuild required.');
            } elseif ($runtime['cleanupRecommended']) {
                $builder->removeImage();
            } else {
                $builder->removeStatusFile();
            }
        }

        $this->deleteDirectory($backupDir);
        $this->discoverApps();

        return [
            'success' => true,
            'action' => 'updated',
            'appId' => $appId,
            'trustLevel' => $finalTrustLevel,
            'trustProfile' => $trustProfile,
            'release' => $release,
            'runtime' => $runtime,
        ];
    }

    /**
     * Resolve the actual app directory inside a synced source repo.
     */
    private function resolveSourceAppDirectory(string $sourceId, string $appId): ?string {
        $sourcesManager = $this->getSourcesManager();
        $cacheDir = $sourcesManager->getRepositoryCacheDir($sourceId);
        if ($cacheDir === null) {
            return null;
        }

        $candidates = [];
        $content = $sourcesManager->discoverRepositoryContent($sourceId);
        foreach (($content['apps'] ?? []) as $app) {
            if (($app['id'] ?? '') !== $appId) {
                continue;
            }

            if (!empty($app['sourcePath']) && is_string($app['sourcePath'])) {
                $candidates[] = $app['sourcePath'];
            }
            if (!empty($app['sourcePathRelative']) && is_string($app['sourcePathRelative'])) {
                $candidates[] = $cacheDir . '/' . trim($app['sourcePathRelative'], '/');
            }
            if (!empty($app['path']) && is_string($app['path'])) {
                $candidates[] = $cacheDir . '/' . trim($app['path'], '/');
            }
        }

        $candidates[] = $cacheDir . '/' . $appId;
        $candidates[] = $cacheDir . '/apps/' . $appId;

        foreach (array_unique($candidates) as $candidate) {
            if (is_dir($candidate) && file_exists($candidate . '/manifest.yaml')) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Find a configured source repository by ID.
     */
    private function findSourceById(string $sourceId): ?array {
        foreach ($this->getSources() as $source) {
            if (($source['id'] ?? '') === $sourceId) {
                return $source;
            }
        }

        return null;
    }

    /**
     * Read a source repository's default trust level from normalized/DB shapes.
     */
    private function getSourceDefaultTrust(array $source): int {
        return AppCapabilities::parseTrustLevel(
            $source['defaultTrust'] ?? $source['default_trust'] ?? AppCapabilities::TRUST_SANDBOXED,
            false
        ) ?? AppCapabilities::TRUST_SANDBOXED;
    }

    /**
     * Inspect an app update and return the data needed for preview/apply.
     */
    private function inspectAppUpdate(string $appId, bool $syncSource = true): array {
        $installed = $this->getInstalledApps();

        if (!isset($installed[$appId])) {
            return ['success' => false, 'error' => 'App not in registry'];
        }

        $info = $installed[$appId];
        if (($info['source'] ?? null) === 'studio') {
            return $this->inspectStudioAppUpdate($appId);
        }

        if (($info['source'] ?? null) !== 'git' || empty($info['sourceId'])) {
            return ['success' => false, 'error' => 'App was not installed from a source'];
        }

        $source = $this->findSourceById($info['sourceId']);
        if (!$source) {
            return ['success' => false, 'error' => 'Source not found'];
        }

        if ($syncSource) {
            $syncResult = $this->syncSource($info['sourceId']);
            if (!$syncResult['success']) {
                return $syncResult;
            }
        }

        $appSourceDir = $this->resolveSourceAppDirectory($info['sourceId'], $appId);
        $targetDir = self::INSTALLED_APPS_CODE_DIR . '/' . $appId;

        if ($appSourceDir === null) {
            return ['success' => false, 'error' => 'App no longer in source'];
        }

        if (!is_dir($targetDir)) {
            return ['success' => false, 'error' => 'Installed app directory is missing'];
        }

        $currentManifest = $this->loadManifestFromAppDir($targetDir);
        $newManifest = $this->loadManifestFromAppDir($appSourceDir);
        if (empty($newManifest)) {
            return ['success' => false, 'error' => 'App manifest could not be read from source'];
        }

        $currentTrustLevel = AppCapabilities::parseTrustLevel(
            $info['trustLevel'] ?? AppCapabilities::TRUST_SANDBOXED,
            false
        ) ?? AppCapabilities::TRUST_SANDBOXED;

        $sourceDefaultTrust = $this->getSourceDefaultTrust($source);
        $currentTrustProfile = AppCapabilities::getManifestTrustProfile($currentManifest, $sourceDefaultTrust);
        $trustProfile = AppCapabilities::getManifestTrustProfile($newManifest, $sourceDefaultTrust);
        $minimumTrust = $trustProfile['minimumTrustLevel'] ?? null;
        if ($minimumTrust === null) {
            return [
                'success' => false,
                'error' => 'Updated app requires capabilities that no installable trust level satisfies',
                'trustProfile' => $trustProfile,
            ];
        }

        $version = $this->summarizeVersionChange(
            $currentManifest['version'] ?? $info['version'] ?? null,
            $newManifest['version'] ?? null
        );
        $runtime = $this->summarizeRuntimeChange($currentManifest, $newManifest);
        $trustChange = $this->summarizeUpdateTrustChange($currentTrustProfile, $trustProfile);
        $releaseCatalog = $this->normalizeManifestReleases($newManifest['releases'] ?? []);
        $relevantReleases = $this->selectRelevantManifestReleases(
            $releaseCatalog,
            $version['previous'] ?? null,
            $version['current'] ?? null,
            $version['direction'] ?? 'same'
        );
        $targetRelease = $this->findManifestReleaseByVersion($releaseCatalog, $version['current'] ?? null);
        $fallbackReleaseNotes = $this->normalizeManifestNotes($newManifest['releaseNotes'] ?? $newManifest['release_notes'] ?? null);
        $fallbackChangelogUrl = $this->normalizeManifestUrl($newManifest['changelogUrl'] ?? $newManifest['changelog_url'] ?? null);
        $fallbackBreaking = (bool)($newManifest['breaking'] ?? $newManifest['breakingChange'] ?? $newManifest['breaking_change'] ?? false);
        $releaseNotes = $targetRelease['notes'] ?? $fallbackReleaseNotes;
        $changelogUrl = $targetRelease['changelogUrl'] ?? $fallbackChangelogUrl;
        $breaking = !empty($relevantReleases)
            ? count(array_filter($relevantReleases, fn($release) => !empty($release['breaking']))) > 0
            : (($targetRelease['breaking'] ?? false) || $fallbackBreaking);

        $inspection = [
            'success' => true,
            'appId' => $appId,
            'appName' => $newManifest['name'] ?? $currentManifest['name'] ?? ($this->registeredApps[$appId]['name'] ?? $appId),
            'sourceId' => $info['sourceId'],
            'sourceName' => $source['name'] ?? $source['id'] ?? $info['sourceId'],
            'sourceUrl' => $source['url'] ?? null,
            'canUpdate' => true,
            'requiresTrustUpgrade' => false,
            'currentTrustLevel' => $currentTrustLevel,
            'currentTrustInfo' => AppCapabilities::getTrustLevelInfo($currentTrustLevel),
            'requiredTrustLevel' => $minimumTrust,
            'requiredTrustInfo' => $trustProfile['minimumTrustInfo'] ?? null,
            'trustProfile' => $trustProfile,
            'trustChange' => $trustChange,
            'version' => $version,
            'runtime' => $runtime,
            'releases' => $relevantReleases,
            'releaseNotes' => $releaseNotes,
            'changelogUrl' => $changelogUrl,
            'breaking' => $breaking,
            'targetDir' => $targetDir,
            'appSourceDir' => $appSourceDir,
            'currentManifest' => $currentManifest,
            'newManifest' => $newManifest,
        ];

        if ($currentTrustLevel > $minimumTrust) {
            $required = $trustProfile['minimumTrustInfo']['name'] ?? 'a higher trust level';
            $inspection['canUpdate'] = false;
            $inspection['requiresTrustUpgrade'] = true;
            $inspection['error'] = "Updated app now requires {$required} or higher based on its declared capabilities";
        }

        return $inspection;
    }

    /**
     * Inspect how an installed Studio app compares to the latest published Studio release.
     */
    private function inspectStudioAppUpdate(string $appId): array {
        $installed = $this->getInstalledApps();
        $info = $installed[$appId] ?? null;
        if (!is_array($info)) {
            return ['success' => false, 'error' => 'App not in registry'];
        }

        $release = $this->getStudioReleaseManager()->getLatestPublishedReleaseForApp($appId);
        if ($release === null) {
            return ['success' => false, 'error' => 'No published Studio release is available for this app'];
        }

        $targetDir = self::INSTALLED_APPS_CODE_DIR . '/' . $appId;
        $appSourceDir = trim((string)($release['artifactPath'] ?? $release['artifact_path'] ?? ''));
        if ($appSourceDir === '' || !is_dir($appSourceDir)) {
            return ['success' => false, 'error' => 'Published Studio release artifact is missing'];
        }

        if (!is_dir($targetDir)) {
            return ['success' => false, 'error' => 'Installed app directory is missing'];
        }

        $currentManifest = $this->loadManifestFromAppDir($targetDir);
        $newManifest = is_array($release['manifest'] ?? null) ? $release['manifest'] : $this->loadManifestFromAppDir($appSourceDir);
        if (empty($newManifest)) {
            return ['success' => false, 'error' => 'Published Studio release manifest could not be read'];
        }

        $currentTrustLevel = AppCapabilities::parseTrustLevel(
            $info['trustLevel'] ?? AppCapabilities::TRUST_SANDBOXED,
            false
        ) ?? AppCapabilities::TRUST_SANDBOXED;

        $sourceDefaultTrust = AppCapabilities::TRUST_SANDBOXED;
        $currentTrustProfile = AppCapabilities::getManifestTrustProfile($currentManifest, $sourceDefaultTrust);
        $trustProfile = AppCapabilities::getManifestTrustProfile($newManifest, $sourceDefaultTrust);
        $minimumTrust = $trustProfile['minimumTrustLevel'] ?? null;
        if ($minimumTrust === null) {
            return [
                'success' => false,
                'error' => 'Updated app requires capabilities that no installable trust level satisfies',
                'trustProfile' => $trustProfile,
            ];
        }

        $version = $this->summarizeVersionChange(
            $currentManifest['version'] ?? $info['version'] ?? null,
            $newManifest['version'] ?? ($release['version'] ?? null)
        );
        $runtime = $this->summarizeRuntimeChange($currentManifest, $newManifest);
        $trustChange = $this->summarizeUpdateTrustChange($currentTrustProfile, $trustProfile);
        $releaseNotes = $this->normalizeManifestNotes($release['release_notes'] ?? null);
        $fallbackReleaseNotes = $this->normalizeManifestNotes($newManifest['releaseNotes'] ?? $newManifest['release_notes'] ?? null);
        if ($releaseNotes === []) {
            $releaseNotes = $fallbackReleaseNotes;
        }
        $breaking = (bool)($newManifest['breaking'] ?? $newManifest['breakingChange'] ?? $newManifest['breaking_change'] ?? false);

        $inspection = [
            'success' => true,
            'appId' => $appId,
            'appName' => $newManifest['name'] ?? $currentManifest['name'] ?? ($this->registeredApps[$appId]['name'] ?? $appId),
            'sourceId' => StudioReleaseManager::MARKETPLACE_SOURCE_ID,
            'sourceName' => $release['sourceName'] ?? StudioReleaseManager::MARKETPLACE_SOURCE_NAME,
            'sourceUrl' => null,
            'canUpdate' => true,
            'requiresTrustUpgrade' => false,
            'currentTrustLevel' => $currentTrustLevel,
            'currentTrustInfo' => AppCapabilities::getTrustLevelInfo($currentTrustLevel),
            'requiredTrustLevel' => $minimumTrust,
            'requiredTrustInfo' => $trustProfile['minimumTrustInfo'] ?? null,
            'trustProfile' => $trustProfile,
            'trustChange' => $trustChange,
            'version' => $version,
            'runtime' => $runtime,
            'releases' => [],
            'releaseNotes' => $releaseNotes,
            'changelogUrl' => null,
            'breaking' => $breaking,
            'targetDir' => $targetDir,
            'appSourceDir' => $appSourceDir,
            'currentManifest' => $currentManifest,
            'newManifest' => $newManifest,
        ];

        if (($info['sourceReleaseId'] ?? '') === ($release['id'] ?? null) && !($version['changed'] ?? false) && !($runtime['changed'] ?? false)) {
            $inspection['canUpdate'] = false;
            $inspection['error'] = 'Installed app already matches the latest published Studio release';
        }

        if ($currentTrustLevel > $minimumTrust) {
            $required = $trustProfile['minimumTrustInfo']['name'] ?? 'a higher trust level';
            $inspection['canUpdate'] = false;
            $inspection['requiresTrustUpgrade'] = true;
            $inspection['error'] = "Updated app now requires {$required} or higher based on its declared capabilities";
        }

        return $inspection;
    }

    /**
     * Remove internal-only fields from an update inspection payload.
     */
    private function stripAppUpdateInternals(array $inspection): array {
        unset(
            $inspection['targetDir'],
            $inspection['appSourceDir'],
            $inspection['currentManifest'],
            $inspection['newManifest']
        );

        return $inspection;
    }

    /**
     * Load an app manifest from disk.
     */
    private function loadManifestFromAppDir(string $appDir): array {
        $manifestFile = $appDir . '/manifest.yaml';
        if (!file_exists($manifestFile) || !function_exists('yaml_parse_file')) {
            return [];
        }

        $manifest = yaml_parse_file($manifestFile);
        return is_array($manifest) ? $manifest : [];
    }

    /**
     * Summarize the manifest version change across an update.
     */
    private function summarizeVersionChange(?string $previousVersion, ?string $currentVersion): array {
        $previous = $this->normalizeManifestVersionString($previousVersion);
        $current = $this->normalizeManifestVersionString($currentVersion);

        $changed = $previous !== $current;
        $direction = 'same';

        if ($previous !== null && $current !== null) {
            $comparison = $this->compareManifestVersions($current, $previous);
            if ($comparison > 0) {
                $direction = 'upgrade';
            } elseif ($comparison < 0) {
                $direction = 'downgrade';
            } elseif ($changed) {
                $direction = 'changed';
            }
        } elseif ($changed) {
            $direction = 'changed';
        }

        return [
            'previous' => $previous,
            'current' => $current ?? '1.0.0',
            'changed' => $changed,
            'direction' => $direction,
        ];
    }

    /**
     * Summarize how a source app version compares to the installed app version.
     */
    private function summarizeAvailableVersionDelta(?string $installedVersion, ?string $sourceVersion): array {
        $summary = $this->summarizeVersionChange($installedVersion, $sourceVersion);
        $status = 'same';
        $message = '';

        switch ($summary['direction']) {
            case 'upgrade':
                $status = 'source_newer';
                $message = sprintf(
                    'Source version %s is newer than installed version %s.',
                    $summary['current'] ?? 'unknown',
                    $summary['previous'] ?? 'unknown'
                );
                break;
            case 'downgrade':
                $status = 'source_older';
                $message = sprintf(
                    'Source version %s is older than installed version %s.',
                    $summary['current'] ?? 'unknown',
                    $summary['previous'] ?? 'unknown'
                );
                break;
            case 'changed':
                $status = 'source_changed';
                $message = 'Source and installed versions differ.';
                break;
            default:
                $status = 'same';
                $message = 'Source and installed versions match.';
                break;
        }

        $summary['status'] = $status;
        $summary['message'] = $message;

        return $summary;
    }

    /**
     * Summarize how trust requirements change between installed and source manifests.
     */
    private function summarizeUpdateTrustChange(array $currentTrustProfile, array $newTrustProfile): array {
        $previousLevel = $currentTrustProfile['minimumTrustLevel'] ?? null;
        $currentLevel = $newTrustProfile['minimumTrustLevel'] ?? null;
        $changed = $previousLevel !== $currentLevel;
        $direction = 'same';

        if ($changed && $previousLevel !== null && $currentLevel !== null) {
            if ($currentLevel < $previousLevel) {
                $direction = 'stricter';
            } elseif ($currentLevel > $previousLevel) {
                $direction = 'looser';
            } else {
                $direction = 'changed';
            }
        } elseif ($changed) {
            $direction = 'changed';
        }

        return [
            'changed' => $changed,
            'direction' => $direction,
            'previousMinimumTrustLevel' => $previousLevel,
            'previousMinimumTrustInfo' => $currentTrustProfile['minimumTrustInfo'] ?? null,
            'currentMinimumTrustLevel' => $currentLevel,
            'currentMinimumTrustInfo' => $newTrustProfile['minimumTrustInfo'] ?? null,
        ];
    }

    /**
     * Summarize runtime changes that affect image rebuild expectations.
     */
    private function summarizeRuntimeChange(array $currentManifest, array $newManifest): array {
        $previousRuntime = $this->normalizeRuntimeConfig($currentManifest['runtime'] ?? []);
        $currentRuntime = $this->normalizeRuntimeConfig($newManifest['runtime'] ?? []);
        $previousNeedsCustomImage = $this->manifestNeedsCustomImage($currentManifest);
        $currentNeedsCustomImage = $this->manifestNeedsCustomImage($newManifest);
        $changed = $previousRuntime !== $currentRuntime;

        return [
            'changed' => $changed,
            'previousNeedsCustomImage' => $previousNeedsCustomImage,
            'currentNeedsCustomImage' => $currentNeedsCustomImage,
            'rebuildRecommended' => $changed && $currentNeedsCustomImage,
            'cleanupRecommended' => $changed && $previousNeedsCustomImage && !$currentNeedsCustomImage,
            'cleanupPerformed' => false,
        ];
    }

    /**
     * Normalize runtime config so change detection ignores ordering noise.
     */
    private function normalizeRuntimeConfig($runtime): array {
        if (!is_array($runtime)) {
            return [];
        }

        return $this->normalizeManifestValue($runtime);
    }

    /**
     * Normalize release notes from common manifest shapes.
     */
    private function normalizeManifestNotes($notes): array {
        if (is_string($notes)) {
            $trimmed = trim($notes);
            return $trimmed === '' ? [] : [$trimmed];
        }

        if (!is_array($notes)) {
            return [];
        }

        $normalized = [];
        foreach ($notes as $note) {
            if (!is_string($note)) {
                continue;
            }

            $trimmed = trim($note);
            if ($trimmed !== '') {
                $normalized[] = $trimmed;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * Normalize manifest release-history entries.
     */
    private function normalizeManifestReleases($releases): array {
        if (!is_array($releases)) {
            return [];
        }

        $normalized = [];
        foreach ($releases as $release) {
            if (!is_array($release)) {
                continue;
            }

            $version = $this->normalizeManifestVersionString($release['version'] ?? null);
            if ($version === null) {
                continue;
            }

            if (isset($normalized[$version])) {
                continue;
            }

            $date = null;
            foreach (['date', 'releasedAt', 'released_at'] as $key) {
                if (!is_string($release[$key] ?? null)) {
                    continue;
                }

                $trimmedDate = trim($release[$key]);
                if ($trimmedDate !== '') {
                    $date = $trimmedDate;
                    break;
                }
            }

            $normalized[$version] = [
                'version' => $version,
                'date' => $date,
                'notes' => $this->normalizeManifestNotes($release['notes'] ?? $release['releaseNotes'] ?? $release['release_notes'] ?? null),
                'changelogUrl' => $this->normalizeManifestUrl($release['changelogUrl'] ?? $release['changelog_url'] ?? null),
                'breaking' => (bool)($release['breaking'] ?? $release['breakingChange'] ?? $release['breaking_change'] ?? false),
            ];
        }

        $normalized = array_values($normalized);
        usort($normalized, fn($left, $right) => $this->compareManifestVersions($right['version'] ?? null, $left['version'] ?? null));

        return $normalized;
    }

    /**
     * Pick the release-history entries relevant to the installed -> target update path.
     */
    private function selectRelevantManifestReleases(array $releases, ?string $installedVersion, ?string $targetVersion, string $direction): array {
        $target = $this->normalizeManifestVersionString($targetVersion);
        if ($target === null || empty($releases)) {
            return [];
        }

        $installed = $this->normalizeManifestVersionString($installedVersion);
        $relevant = [];

        foreach ($releases as $release) {
            $releaseVersion = $this->normalizeManifestVersionString($release['version'] ?? null);
            if ($releaseVersion === null) {
                continue;
            }

            $include = false;
            if ($direction === 'upgrade') {
                $include = $this->compareManifestVersions($releaseVersion, $target) <= 0
                    && ($installed === null || $this->compareManifestVersions($releaseVersion, $installed) > 0);
            } elseif ($direction === 'downgrade') {
                $include = $this->compareManifestVersions($releaseVersion, $target) >= 0
                    && ($installed === null || $this->compareManifestVersions($releaseVersion, $installed) < 0);
            } else {
                $include = $this->compareManifestVersions($releaseVersion, $target) === 0;
            }

            if ($include) {
                $relevant[] = $release;
            }
        }

        usort($relevant, function ($left, $right) use ($direction) {
            $comparison = $this->compareManifestVersions($left['version'] ?? null, $right['version'] ?? null);
            return $direction === 'downgrade' ? -$comparison : $comparison;
        });

        return $relevant;
    }

    /**
     * Find a normalized release entry by exact version match.
     */
    private function findManifestReleaseByVersion(array $releases, ?string $version): ?array {
        $target = $this->normalizeManifestVersionString($version);
        if ($target === null) {
            return null;
        }

        foreach ($releases as $release) {
            if ($this->compareManifestVersions($release['version'] ?? null, $target) === 0) {
                return $release;
            }
        }

        return null;
    }

    /**
     * Normalize optional manifest URLs.
     */
    private function normalizeManifestUrl($url): ?string {
        if (!is_string($url)) {
            return null;
        }

        $trimmed = trim($url);
        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Normalize a manifest version string.
     */
    private function normalizeManifestVersionString($version): ?string {
        if (!is_string($version)) {
            return null;
        }

        $trimmed = trim($version);
        if ($trimmed === '') {
            return null;
        }

        return preg_replace('/^[vV](?=\d)/', '', $trimmed);
    }

    /**
     * Compare two manifest version strings.
     */
    private function compareManifestVersions($left, $right): int {
        $leftVersion = $this->normalizeManifestVersionString($left) ?? '0.0.0';
        $rightVersion = $this->normalizeManifestVersionString($right) ?? '0.0.0';
        return version_compare($leftVersion, $rightVersion);
    }

    /**
     * Normalize nested manifest values for stable comparisons.
     */
    private function normalizeManifestValue($value) {
        if (!is_array($value)) {
            return is_string($value) ? trim($value) : $value;
        }

        if (array_is_list($value)) {
            $normalized = array_map(fn($item) => $this->normalizeManifestValue($item), $value);
            usort($normalized, function ($left, $right) {
                return strcmp((string)json_encode($left), (string)json_encode($right));
            });
            return $normalized;
        }

        ksort($value);
        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[$key] = $this->normalizeManifestValue($item);
        }

        return $normalized;
    }

    /**
     * Determine whether a manifest needs a custom runtime image.
     */
    private function manifestNeedsCustomImage(array $manifest): bool {
        $runtime = is_array($manifest['runtime'] ?? null) ? $manifest['runtime'] : [];
        $extensions = $runtime['phpExtensions'] ?? [];
        $packages = $runtime['systemPackages'] ?? [];
        $pythonPackages = $runtime['pythonPackages'] ?? [];

        return !empty($extensions) || !empty($packages) || !empty($pythonPackages);
    }
    
    /**
     * Copy directory recursively
     */
    private function copyDirectory(string $src, string $dst): bool {
        $dir = @opendir($src);
        if ($dir === false) {
            return false;
        }

        if (!is_dir($dst) && !@mkdir($dst, 0755, true) && !is_dir($dst)) {
            closedir($dir);
            return false;
        }
        @chmod($dst, 0777);
        
        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            
            $srcPath = $src . '/' . $file;
            $dstPath = $dst . '/' . $file;
            
            if (is_dir($srcPath)) {
                if (!$this->copyDirectory($srcPath, $dstPath)) {
                    closedir($dir);
                    return false;
                }
            } else {
                if (!@copy($srcPath, $dstPath)) {
                    closedir($dir);
                    return false;
                }

                $srcPerms = @fileperms($srcPath);
                $mode = (is_int($srcPerms) && ($srcPerms & 0111) !== 0) ? 0777 : 0666;
                @chmod($dstPath, $mode);
            }
        }
        
        closedir($dir);
        return true;
    }
    
    /**
     * Delete directory recursively
     */
    private function deleteDirectory(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                unlink($path);
            }
        }
        
        rmdir($dir);
    }
}
