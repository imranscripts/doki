<?php
/**
 * SourcesManager.php - Unified Git Providers and Repositories Management
 * 
 * Part of Doki v3 Architecture - Batch 13: Doki Sources
 * 
 * Provides centralized management for:
 * - Git providers (GitHub, GitLab, Bitbucket, custom)
 * - Repositories with types (apps, templates, playwright-tests, mixed)
 * - Sync operations with progress tracking
 * - Version pinning (branch, tag, commit)
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/ConfigManager.php';

class SourcesManager {
    private PDO $db;
    private ConfigManager $config;
    private const CACHE_DIR = __DIR__ . '/../data/sources/.cache';
    
    // Repository types
    const TYPE_APPS = 'apps';
    const TYPE_TEMPLATES = 'templates';
    const TYPE_PLAYWRIGHT = 'playwright-tests';
    const TYPE_MIXED = 'mixed';
    
    // Pin types
    const PIN_BRANCH = 'branch';
    const PIN_TAG = 'tag';
    const PIN_COMMIT = 'commit';
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->config = new ConfigManager();
        $this->ensureCacheDir();
    }
    
    /**
     * Ensure cache directory exists
     */
    private function ensureCacheDir(): void {
        if (!is_dir(self::CACHE_DIR)) {
            mkdir(self::CACHE_DIR, 0755, true);
        }
    }

    /**
     * Build secret ID for provider token
     */
    private function getProviderTokenSecretId(string $providerId): string {
        return "git-provider-{$providerId}-token";
    }

    /**
     * Migrate legacy plaintext tokens into secrets store
     */
    public function migrateLegacyTokens(string $userId, string $username): void {
        $stmt = $this->db->query("
            SELECT id, token, token_secret_id
            FROM git_providers
            WHERE token IS NOT NULL AND token != ''
              AND (token_secret_id IS NULL OR token_secret_id = '')
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $secretId = $this->getProviderTokenSecretId($row['id']);
            try {
                $this->config->saveSecret($secretId, $row['token'], $userId, $username);
                $update = $this->db->prepare("UPDATE git_providers SET token = NULL, token_secret_id = ? WHERE id = ?");
                $update->execute([$secretId, $row['id']]);
            } catch (Exception $e) {
                // Skip migration if secrets are locked or unavailable
                continue;
            }
        }
    }

    /**
     * Resolve provider token from secrets
     */
    private function resolveProviderToken(array $provider): ?string {
        $secretId = $provider['token_secret_id'] ?? null;
        if ($secretId) {
            return $this->config->getSecret($secretId);
        }
        return $provider['token'] ?? null;
    }
    
    // ==========================================
    // GIT PROVIDERS
    // ==========================================
    
    /**
     * Get all git providers
     */
    public function getProviders(): array {
        $stmt = $this->db->query("
            SELECT * FROM git_providers 
            ORDER BY name ASC
        ");
        
        $providers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Hide tokens from response
        foreach ($providers as &$provider) {
            if (!empty($provider['token_secret_id']) || !empty($provider['token'])) {
                $provider['hasToken'] = true;
                $provider['token'] = '••••••••';
            } else {
                $provider['hasToken'] = false;
            }
        }
        
        return $providers;
    }
    
    /**
     * Get a single provider by ID
     */
    public function getProvider(string $id, bool $includeToken = false): ?array {
        $stmt = $this->db->prepare("SELECT * FROM git_providers WHERE id = ?");
        $stmt->execute([$id]);
        $provider = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$provider) {
            return null;
        }
        
        if (!$includeToken) {
            if (!empty($provider['token_secret_id']) || !empty($provider['token'])) {
                $provider['hasToken'] = true;
                $provider['token'] = '••••••••';
            } else {
                $provider['hasToken'] = false;
            }
        }
        
        return $provider;
    }
    
    /**
     * Create a new git provider
     */
    public function createProvider(array $data, string $userId, string $username): array {
        // Validate required fields
        if (empty($data['name'])) {
            return ['success' => false, 'error' => 'Provider name is required'];
        }
        if (empty($data['type']) || !in_array($data['type'], ['github', 'gitlab', 'bitbucket', 'custom'])) {
            return ['success' => false, 'error' => 'Valid provider type is required'];
        }
        
        $id = !empty($data['id']) ? $data['id'] : $this->generateId($data['name']);
        
        // Check for duplicate ID
        $existing = $this->getProvider($id);
        if ($existing) {
            return ['success' => false, 'error' => 'Provider ID already exists'];
        }
        
        // Set default API URLs based on type (handle empty strings too)
        $apiUrl = !empty($data['apiUrl']) ? $data['apiUrl'] : $this->getDefaultApiUrl($data['type']);
        
        $tokenSecretId = null;
        if (!empty($data['token'])) {
            try {
                $tokenSecretId = $this->getProviderTokenSecretId($id);
                $saveResult = $this->config->saveSecret($tokenSecretId, $data['token'], $userId, $username);
                if (empty($saveResult['success'])) {
                    return ['success' => false, 'error' => 'Failed to store token'];
                }
            } catch (Exception $e) {
                return ['success' => false, 'error' => 'Failed to store token: ' . $e->getMessage()];
            }
        }

        $stmt = $this->db->prepare("
            INSERT INTO git_providers (id, name, type, api_url, token, token_secret_id, username, description, created_by, updated_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        try {
            $stmt->execute([
                $id,
                $data['name'],
                $data['type'],
                $apiUrl,
                null,
                $tokenSecretId,
                !empty($data['username']) ? $data['username'] : null,
                !empty($data['description']) ? $data['description'] : null,
                $userId,
                $userId
            ]);
            
            return ['success' => true, 'id' => $id];
        } catch (PDOException $e) {
            return ['success' => false, 'error' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    /**
     * Update a git provider
     */
    public function updateProvider(string $id, array $data, string $userId, string $username): array {
        $provider = $this->getProvider($id, true);
        if (!$provider) {
            return ['success' => false, 'error' => 'Provider not found'];
        }
        
        // Build update query dynamically
        $updates = [];
        $params = [];
        
        $allowedFields = ['name', 'type', 'api_url', 'username', 'description'];
        foreach ($allowedFields as $field) {
            $camelField = lcfirst(str_replace('_', '', ucwords($field, '_')));
            if (array_key_exists($camelField, $data) || array_key_exists($field, $data)) {
                $value = $data[$camelField] ?? $data[$field] ?? null;

                $updates[] = "$field = ?";
                $params[] = $value;
            }
        }

        // Handle token updates via secrets store
        $tokenValue = $data['token'] ?? null;
        if ($tokenValue !== null) {
            if ($tokenValue === '••••••••') {
                // Keep existing
            } elseif ($tokenValue === '' || $tokenValue === false) {
                $updates[] = "token_secret_id = NULL";
                $updates[] = "token = NULL";
            } else {
                try {
                    $secretId = $this->getProviderTokenSecretId($id);
                    $saveResult = $this->config->saveSecret($secretId, $tokenValue, $userId, $username);
                    if (empty($saveResult['success'])) {
                        return ['success' => false, 'error' => 'Failed to store token'];
                    }
                    $updates[] = "token_secret_id = ?";
                    $params[] = $secretId;
                    $updates[] = "token = NULL";
                } catch (Exception $e) {
                    return ['success' => false, 'error' => 'Failed to store token: ' . $e->getMessage()];
                }
            }
        }
        
        if (empty($updates)) {
            return ['success' => false, 'error' => 'No fields to update'];
        }
        
        $updates[] = "updated_at = datetime('now')";
        $updates[] = "updated_by = ?";
        $params[] = $userId;
        $params[] = $id;
        
        $sql = "UPDATE git_providers SET " . implode(', ', $updates) . " WHERE id = ?";
        
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return ['success' => true];
        } catch (PDOException $e) {
            return ['success' => false, 'error' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    /**
     * Delete a git provider
     */
    public function deleteProvider(string $id): array {
        // Check if provider is in use by any repository
        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM repositories WHERE provider_id = ?");
        $stmt->execute([$id]);
        $result = $stmt->fetch();
        
        if ($result['count'] > 0) {
            return ['success' => false, 'error' => 'Provider is in use by ' . $result['count'] . ' repository(ies)'];
        }
        
        $stmt = $this->db->prepare("DELETE FROM git_providers WHERE id = ?");
        $stmt->execute([$id]);
        
        return ['success' => true];
    }
    
    /**
     * Test provider connection
     */
    public function testProvider(string $id): array {
        $provider = $this->getProvider($id, true);
        if (!$provider) {
            return ['success' => false, 'error' => 'Provider not found'];
        }
        
        // Fix empty api_url - update to default if needed
        if (empty($provider['api_url']) && !empty($provider['type'])) {
            $defaultUrl = $this->getDefaultApiUrl($provider['type']);
            if ($defaultUrl) {
                $stmt = $this->db->prepare("UPDATE git_providers SET api_url = ? WHERE id = ?");
                $stmt->execute([$defaultUrl, $id]);
                $provider['api_url'] = $defaultUrl;
            }
        }
        
        $testResult = $this->testProviderConnection($provider);
        
        // Update test status
        $stmt = $this->db->prepare("
            UPDATE git_providers 
            SET last_tested_at = datetime('now'), last_test_success = ?
            WHERE id = ?
        ");
        $stmt->execute([$testResult['success'] ? 1 : 0, $id]);
        
        return $testResult;
    }
    
    /**
     * Test provider connection using API
     */
    private function testProviderConnection(array $provider): array {
        $type = $provider['type'] ?? '';
        try {
            $token = $this->resolveProviderToken($provider) ?? '';
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Secrets locked: ' . $e->getMessage()];
        }
        
        // Handle empty api_url - use default based on type
        $apiUrl = !empty($provider['api_url']) ? $provider['api_url'] : $this->getDefaultApiUrl($type);
        
        if (empty($token)) {
            return ['success' => false, 'error' => 'No token configured'];
        }
        
        if (empty($apiUrl)) {
            return ['success' => false, 'error' => 'No API URL configured for this provider type'];
        }
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Doki/1.0');
        
        switch ($type) {
            case 'github':
                $url = rtrim($apiUrl, '/') . '/user';
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Authorization: Bearer ' . $token,
                    'Accept: application/vnd.github.v3+json'
                ]);
                break;
                
            case 'gitlab':
                $url = rtrim($apiUrl, '/') . '/api/v4/user';
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'PRIVATE-TOKEN: ' . $token
                ]);
                break;
                
            case 'bitbucket':
                $url = rtrim($apiUrl, '/') . '/2.0/user';
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Authorization: Bearer ' . $token
                ]);
                break;
                
            default:
                return ['success' => false, 'error' => 'Unsupported provider type for testing: ' . $type];
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return ['success' => false, 'error' => 'Connection error: ' . $error];
        }
        
        if ($httpCode === 200 || $httpCode === 201) {
            $data = json_decode($response, true);
            $username = $data['login'] ?? $data['username'] ?? $data['display_name'] ?? 'Unknown';
            return ['success' => true, 'message' => 'Connected as: ' . $username, 'user' => $username];
        }
        
        return ['success' => false, 'error' => 'API returned HTTP ' . $httpCode];
    }
    
    /**
     * Get default API URL for provider type
     */
    private function getDefaultApiUrl(string $type): string {
        switch ($type) {
            case 'github':
                return 'https://api.github.com';
            case 'gitlab':
                return 'https://gitlab.com';
            case 'bitbucket':
                return 'https://api.bitbucket.org';
            default:
                return '';
        }
    }
    
    /**
     * Get authenticated Git URL for a provider
     */
    public function getAuthenticatedUrl(string $url, ?string $providerId): string {
        if (!$providerId) {
            return $url;
        }
        
        $provider = $this->getProvider($providerId, true);
        if (!$provider) {
            return $url;
        }
        
        try {
            $token = $this->resolveProviderToken($provider) ?? '';
        } catch (Exception $e) {
            return $url;
        }
        if ($token === '' || $token === '••••••••') {
            return $url;
        }
        $type = $provider['type'];
        
        if (preg_match('#^https://([^/]+)/(.+)$#', $url, $matches)) {
            $host = $matches[1];
            $path = $matches[2];
            
            switch ($type) {
                case 'github':
                    return "https://{$token}@{$host}/{$path}";
                    
                case 'gitlab':
                    return "https://oauth2:{$token}@{$host}/{$path}";
                    
                case 'bitbucket':
                    $username = $provider['username'] ?? 'x-token-auth';
                    return "https://{$username}:{$token}@{$host}/{$path}";
                    
                default:
                    return "https://{$token}@{$host}/{$path}";
            }
        }
        
        return $url;
    }
    
    // ==========================================
    // REPOSITORIES
    // ==========================================
    
    /**
     * Get all repositories
     */
    public function getRepositories(?string $type = null): array {
        $sql = "
            SELECT r.*, p.name as provider_name, p.type as provider_type
            FROM repositories r
            LEFT JOIN git_providers p ON r.provider_id = p.id
        ";
        
        $params = [];
        if ($type) {
            $sql .= " WHERE r.type = ?";
            $params[] = $type;
        }
        
        $sql .= " ORDER BY r.name ASC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get a single repository by ID
     */
    public function getRepository(string $id): ?array {
        $stmt = $this->db->prepare("
            SELECT r.*, p.name as provider_name, p.type as provider_type
            FROM repositories r
            LEFT JOIN git_providers p ON r.provider_id = p.id
            WHERE r.id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    /**
     * Create a new repository
     */
    public function createRepository(array $data, string $userId, string $username): array {
        // Validate required fields
        if (empty($data['name'])) {
            return ['success' => false, 'error' => 'Repository name is required'];
        }
        if (empty($data['url'])) {
            return ['success' => false, 'error' => 'Repository URL is required'];
        }
        if (empty($data['type']) || !in_array($data['type'], [self::TYPE_APPS, self::TYPE_TEMPLATES, self::TYPE_PLAYWRIGHT, self::TYPE_MIXED])) {
            return ['success' => false, 'error' => 'Valid repository type is required'];
        }
        
        $id = $data['id'] ?? $this->generateId($data['name']);
        
        // Check for duplicate ID
        $existing = $this->getRepository($id);
        if ($existing) {
            return ['success' => false, 'error' => 'Repository ID already exists'];
        }
        
        // Validate provider exists if specified
        if (!empty($data['providerId'])) {
            $provider = $this->getProvider($data['providerId']);
            if (!$provider) {
                return ['success' => false, 'error' => 'Provider not found'];
            }
        }
        
        $stmt = $this->db->prepare("
            INSERT INTO repositories (
                id, name, url, type, provider_id, branch, 
                pinned_ref, pin_type, auto_sync, sync_interval, default_trust,
                description, created_by, updated_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        try {
            $stmt->execute([
                $id,
                $data['name'],
                $data['url'],
                $data['type'],
                $data['providerId'] ?? null,
                $data['branch'] ?? 'main',
                $data['pinnedRef'] ?? null,
                $data['pinType'] ?? null,
                $data['autoSync'] ?? false ? 1 : 0,
                $data['syncInterval'] ?? 3600,
                $data['defaultTrust'] ?? 3,
                $data['description'] ?? null,
                $userId,
                $userId
            ]);
            
            return ['success' => true, 'id' => $id];
        } catch (PDOException $e) {
            return ['success' => false, 'error' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    /**
     * Update a repository
     */
    public function updateRepository(string $id, array $data, string $userId): array {
        $repo = $this->getRepository($id);
        if (!$repo) {
            return ['success' => false, 'error' => 'Repository not found'];
        }
        
        // Build update query
        $updates = [];
        $params = [];
        
        $fieldMapping = [
            'name' => 'name',
            'url' => 'url',
            'type' => 'type',
            'providerId' => 'provider_id',
            'branch' => 'branch',
            'pinnedRef' => 'pinned_ref',
            'pinType' => 'pin_type',
            'autoSync' => 'auto_sync',
            'syncInterval' => 'sync_interval',
            'defaultTrust' => 'default_trust',
            'description' => 'description'
        ];
        
        foreach ($fieldMapping as $camelField => $dbField) {
            if (array_key_exists($camelField, $data)) {
                $value = $data[$camelField];
                if ($dbField === 'auto_sync') {
                    $value = $value ? 1 : 0;
                }
                $updates[] = "$dbField = ?";
                $params[] = $value;
            }
        }
        
        if (empty($updates)) {
            return ['success' => false, 'error' => 'No fields to update'];
        }
        
        $updates[] = "updated_at = datetime('now')";
        $updates[] = "updated_by = ?";
        $params[] = $userId;
        $params[] = $id;
        
        $sql = "UPDATE repositories SET " . implode(', ', $updates) . " WHERE id = ?";
        
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return ['success' => true];
        } catch (PDOException $e) {
            return ['success' => false, 'error' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    /**
     * Delete a repository
     */
    public function deleteRepository(string $id, bool $deleteCache = true): array {
        $repo = $this->getRepository($id);
        if (!$repo) {
            return ['success' => false, 'error' => 'Repository not found'];
        }
        
        // Delete from database
        $stmt = $this->db->prepare("DELETE FROM repositories WHERE id = ?");
        $stmt->execute([$id]);
        
        // Delete cache if requested
        if ($deleteCache) {
            $cacheDir = self::CACHE_DIR . '/' . $id;
            if (is_dir($cacheDir)) {
                $this->deleteDirectory($cacheDir);
            }
        }
        
        return ['success' => true];
    }
    
    /**
     * Sync a repository (clone or pull)
     */
    public function syncRepository(string $id): array {
        $repo = $this->getRepository($id);
        if (!$repo) {
            return ['success' => false, 'error' => 'Repository not found'];
        }
        
        $cacheDir = self::CACHE_DIR . '/' . $id;
        $url = $repo['url'];
        $branch = $repo['branch'] ?? 'main';
        $providerId = $repo['provider_id'];
        
        // Get authenticated URL
        $authUrl = $this->getAuthenticatedUrl($url, $providerId);
        
        // Determine reference to use
        $ref = $repo['pinned_ref'] ?? $branch;
        $pinType = $repo['pin_type'];
        
        $output = [];
        $exitCode = 0;
        
        // Check if .git exists
        if (is_dir($cacheDir . '/.git')) {
            // Update remote URL
            $setUrlCmd = sprintf(
                'cd %s && git remote set-url origin %s 2>&1',
                escapeshellarg($cacheDir),
                escapeshellarg($authUrl)
            );
            exec($setUrlCmd);
            
            // Fetch and checkout
            if ($pinType === self::PIN_COMMIT) {
                // For commit pins, fetch all and checkout specific commit
                $cmd = sprintf(
                    'cd %s && git fetch origin && git checkout %s 2>&1',
                    escapeshellarg($cacheDir),
                    escapeshellarg($ref)
                );
            } elseif ($pinType === self::PIN_TAG) {
                // For tag pins, fetch tags and checkout
                $cmd = sprintf(
                    'cd %s && git fetch origin --tags && git checkout tags/%s 2>&1',
                    escapeshellarg($cacheDir),
                    escapeshellarg($ref)
                );
            } else {
                // For branch, pull latest
                $cmd = sprintf(
                    'cd %s && git fetch origin && git checkout %s && git pull origin %s 2>&1',
                    escapeshellarg($cacheDir),
                    escapeshellarg($ref),
                    escapeshellarg($ref)
                );
            }
            
            exec($cmd, $output, $exitCode);
        } else {
            // Clone
            if (!is_dir(dirname($cacheDir))) {
                mkdir(dirname($cacheDir), 0755, true);
            }
            
            if ($pinType === self::PIN_TAG) {
                $cmd = sprintf(
                    'git clone --branch %s %s %s 2>&1',
                    escapeshellarg($ref),
                    escapeshellarg($authUrl),
                    escapeshellarg($cacheDir)
                );
            } else {
                $cmd = sprintf(
                    'git clone --branch %s %s %s 2>&1',
                    escapeshellarg($branch),
                    escapeshellarg($authUrl),
                    escapeshellarg($cacheDir)
                );
            }
            
            exec($cmd, $output, $exitCode);
            
            // If commit pin, checkout after clone
            if ($exitCode === 0 && $pinType === self::PIN_COMMIT && $ref) {
                $checkoutCmd = sprintf(
                    'cd %s && git checkout %s 2>&1',
                    escapeshellarg($cacheDir),
                    escapeshellarg($ref)
                );
                exec($checkoutCmd, $output, $exitCode);
            }
        }
        
        $sanitizedOutput = $this->sanitizeGitOutput($output);
        $success = $exitCode === 0;
        $message = $success ? 'Sync completed successfully' : implode("\n", $sanitizedOutput);
        
        // Update sync status
        $stmt = $this->db->prepare("
            UPDATE repositories 
            SET last_sync_at = datetime('now'), 
                last_sync_status = ?,
                last_sync_message = ?
            WHERE id = ?
        ");
        $stmt->execute([$success ? 'success' : 'error', $message, $id]);
        
        if (!$success) {
            return ['success' => false, 'error' => $message, 'output' => $sanitizedOutput];
        }
        
        // Discover content based on type
        $discovered = $this->discoverRepositoryContent($id);
        
        return ['success' => true, 'message' => $message, 'discovered' => $discovered];
    }
    
    /**
     * Discover content in a repository based on its type
     */
    public function discoverRepositoryContent(string $id): array {
        $repo = $this->getRepository($id);
        if (!$repo) {
            return [];
        }
        
        $cacheDir = self::CACHE_DIR . '/' . $id;
        if (!is_dir($cacheDir)) {
            return [];
        }
        
        $type = $repo['type'];
        $content = [];
        
        switch ($type) {
            case self::TYPE_APPS:
                $content['apps'] = $this->discoverApps($cacheDir);
                break;
                
            case self::TYPE_TEMPLATES:
                $content['templates'] = $this->discoverTemplates($cacheDir);
                break;
                
            case self::TYPE_PLAYWRIGHT:
                $content['tests'] = $this->discoverPlaywrightTests($cacheDir);
                break;
                
            case self::TYPE_MIXED:
                $content['apps'] = $this->discoverApps($cacheDir);
                $content['templates'] = $this->discoverTemplates($cacheDir);
                $content['tests'] = $this->discoverPlaywrightTests($cacheDir);
                break;
        }
        
        return $content;
    }

    /**
     * Discover Doki apps directly from a local repository directory.
     */
    public function discoverAppsInDirectory(string $dir): array {
        if (!is_dir($dir)) {
            return [];
        }

        return $this->discoverApps($dir);
    }
    
    /**
     * Discover apps in a repository
     */
    private function discoverApps(string $dir): array {
        $apps = [];

        // Check for registry file
        $registryFile = $dir . '/apps.yaml';
        if (file_exists($registryFile) && function_exists('yaml_parse_file')) {
            $registry = yaml_parse_file($registryFile);
            if ($registry && !empty($registry['apps'])) {
                foreach ($registry['apps'] as $app) {
                    if (is_array($app)) {
                        $this->registerDiscoveredApp($apps, $app, $dir);
                    }
                }
            }
        }

        // Support repos with apps at the root and repos with an explicit apps/ directory.
        foreach ([$dir, $dir . '/apps'] as $scanRoot) {
            if (!is_dir($scanRoot)) {
                continue;
            }

            $dirs = glob($scanRoot . '/*', GLOB_ONLYDIR);
            foreach ($dirs as $appDir) {
                if (in_array(basename($appDir), ['.git', 'node_modules', '.cache'])) {
                    continue;
                }

                $manifestFile = $appDir . '/manifest.yaml';
                if (file_exists($manifestFile) && function_exists('yaml_parse_file')) {
                    $manifest = yaml_parse_file($manifestFile);
                    if ($manifest && !empty($manifest['id'])) {
                        $this->registerDiscoveredApp($apps, $manifest, $dir, $appDir, true);
                    }
                }
            }
        }

        return array_values($apps);
    }

    /**
     * Normalize app metadata discovered from a source repo.
     */
    private function registerDiscoveredApp(array &$apps, array $manifest, string $repoRoot, ?string $appDir = null, bool $overwrite = false): void {
        $appId = trim((string)($manifest['id'] ?? ''));
        if ($appId === '') {
            return;
        }

        if (!$overwrite && isset($apps[$appId])) {
            return;
        }

        if ($appDir !== null) {
            $manifest['sourcePath'] = $appDir;
            $manifest['sourcePathRelative'] = ltrim(substr($appDir, strlen($repoRoot)), '/');
        } else {
            $relativePath = trim((string)($manifest['sourcePathRelative'] ?? $manifest['path'] ?? ''), '/');
            if ($relativePath === '') {
                if (is_dir($repoRoot . '/' . $appId)) {
                    $relativePath = $appId;
                } elseif (is_dir($repoRoot . '/apps/' . $appId)) {
                    $relativePath = 'apps/' . $appId;
                }
            }

            if ($relativePath !== '') {
                $manifest['sourcePathRelative'] = $relativePath;
                $manifest['sourcePath'] = $repoRoot . '/' . $relativePath;
            }
        }

        $apps[$appId] = $manifest;
    }
    
    /**
     * Discover templates in a repository
     */
    private function discoverTemplates(string $dir): array {
        $templates = [];
        
        // Check for templates.yaml index
        $indexFile = $dir . '/templates.yaml';
        if (file_exists($indexFile) && function_exists('yaml_parse_file')) {
            $index = yaml_parse_file($indexFile);
            if ($index && !empty($index['templates'])) {
                return $index['templates'];
            }
        }
        
        // Scan for template.yaml files
        $files = glob($dir . '/**/template.yaml', GLOB_BRACE);
        foreach ($files as $file) {
            if (function_exists('yaml_parse_file')) {
                $template = yaml_parse_file($file);
                if ($template && !empty($template['id'])) {
                    $template['sourcePath'] = dirname($file);
                    $templates[] = $template;
                }
            }
        }
        
        // Also scan root for .yaml files that might be templates
        $rootFiles = glob($dir . '/*.yaml');
        foreach ($rootFiles as $file) {
            $basename = basename($file);
            if (in_array($basename, ['templates.yaml', 'apps.yaml', 'config.yaml'])) {
                continue;
            }
            
            if (function_exists('yaml_parse_file')) {
                $data = yaml_parse_file($file);
                if ($data && isset($data['template']) && !empty($data['template']['id'])) {
                    $templates[] = $data['template'];
                }
            }
        }
        
        return $templates;
    }
    
    /**
     * Discover Playwright tests in a repository
     */
    private function discoverPlaywrightTests(string $dir): array {
        $tests = [];

        $skipDirs = ['.git', 'node_modules', '.cache', 'test-results', 'playwright-report'];
        $configProjects = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $pathname = $file->getPathname();
            $relativePath = ltrim(substr($pathname, strlen($dir)), DIRECTORY_SEPARATOR);
            if ($relativePath === '') {
                continue;
            }

            $segments = explode(DIRECTORY_SEPARATOR, $relativePath);
            if (count(array_intersect($segments, $skipDirs)) > 0) {
                continue;
            }

            if (!preg_match('/^playwright\.config\.(ts|js)$/i', basename($pathname))) {
                continue;
            }

            $projectDir = dirname($pathname);
            $projectRelative = ltrim(substr($projectDir, strlen($dir)), DIRECTORY_SEPARATOR);
            $projectRelative = $projectRelative === '' ? '.' : str_replace(DIRECTORY_SEPARATOR, '/', $projectRelative);

            $configProjects[$projectRelative] = [
                'path' => $projectRelative,
                'sourcePath' => $projectDir,
                'configFile' => basename($pathname),
                'testCount' => count($this->findPlaywrightTestFiles($projectDir)),
                'hasPackageJson' => file_exists($projectDir . '/package.json'),
            ];
        }

        if (!empty($configProjects)) {
            return array_values($configProjects);
        }

        $rootTests = $this->findPlaywrightTestFiles($dir);
        if (!empty($rootTests)) {
            $tests[] = [
                'path' => '.',
                'sourcePath' => $dir,
                'testCount' => count($rootTests),
                'hasPackageJson' => file_exists($dir . '/package.json')
            ];
        }

        return $tests;
    }

    /**
     * Recursively find Playwright test files in a directory.
     */
    private function findPlaywrightTestFiles(string $dir): array {
        if (!is_dir($dir)) {
            return [];
        }

        $tests = [];
        $skipDirs = ['.git', 'node_modules', '.cache', 'test-results', 'playwright-report'];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $pathname = $file->getPathname();
            $relativePath = ltrim(substr($pathname, strlen($dir)), DIRECTORY_SEPARATOR);
            if ($relativePath === '') {
                continue;
            }

            $segments = explode(DIRECTORY_SEPARATOR, $relativePath);
            if (count(array_intersect($segments, $skipDirs)) > 0) {
                continue;
            }

            if (preg_match('/\.(test|spec)\.(ts|js)$/i', $relativePath)) {
                $tests[] = str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);
            }
        }

        sort($tests);
        return array_values(array_unique($tests));
    }
    
    /**
     * Get repository cache directory
     */
    public function getRepositoryCacheDir(string $id): ?string {
        $cacheDir = self::CACHE_DIR . '/' . $id;
        return is_dir($cacheDir) ? $cacheDir : null;
    }
    
    // ==========================================
    // UTILITIES
    // ==========================================
    
    /**
     * Generate ID from name
     */
    private function generateId(string $name): string {
        $id = strtolower(trim($name));
        $id = preg_replace('/[^a-z0-9]+/', '-', $id);
        $id = trim($id, '-');
        return $id ?: 'source-' . substr(md5(time()), 0, 8);
    }
    
    /**
     * Sanitize git output to hide tokens
     */
    private function sanitizeGitOutput(array $output): array {
        return array_map(function($line) {
            return preg_replace('#(https://)[^@]+@#', '$1***@', $line);
        }, $output);
    }
    
    /**
     * Delete directory recursively
     */
    private function deleteDirectory(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        
        rmdir($dir);
    }
    
    /**
     * Get repository statistics
     */
    public function getStats(): array {
        $stats = [
            'providers' => [
                'total' => 0,
                'byType' => []
            ],
            'repositories' => [
                'total' => 0,
                'byType' => [],
                'withAutoSync' => 0
            ]
        ];
        
        // Provider stats
        $stmt = $this->db->query("SELECT type, COUNT(*) as count FROM git_providers GROUP BY type");
        while ($row = $stmt->fetch()) {
            $stats['providers']['byType'][$row['type']] = (int)$row['count'];
            $stats['providers']['total'] += (int)$row['count'];
        }
        
        // Repository stats
        $stmt = $this->db->query("SELECT type, COUNT(*) as count FROM repositories GROUP BY type");
        while ($row = $stmt->fetch()) {
            $stats['repositories']['byType'][$row['type']] = (int)$row['count'];
            $stats['repositories']['total'] += (int)$row['count'];
        }
        
        $stmt = $this->db->query("SELECT COUNT(*) as count FROM repositories WHERE auto_sync = 1");
        $row = $stmt->fetch();
        $stats['repositories']['withAutoSync'] = (int)$row['count'];
        
        return $stats;
    }
}
