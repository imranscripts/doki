<?php
/**
 * ConfigManager.php - YAML Configuration Management
 * 
 * Part of Doki v3 Architecture - Batch 2: Infrastructure Config
 * 
 * Handles loading, saving, validating, and backing up YAML configuration files.
 */

require_once __DIR__ . '/SecretsStore.php';
require_once __DIR__ . '/Database.php';

class ConfigManager {
    // Config stored inside app/data/config (accessible within Docker container)
    private const CONFIG_DIR = __DIR__ . '/../data/config';
    private const HISTORY_DIR = __DIR__ . '/../data/config/.history';
    
    private array $schemas = [];
    private SecretsStore $secretsStore;
    private PDO $db;

    public function __construct() {
        $this->initializeSchemas();
        $this->secretsStore = new SecretsStore();
        $this->db = Database::getInstance();
    }

    /**
     * Initialize validation schemas for each config type
     */
    private function initializeSchemas(): void {
        $this->schemas = [
            'targets' => [
                'root' => 'targets',
                'item_required' => ['id', 'name', 'type'],
                'item_optional' => ['connection', 'description'],
                'types' => ['ssh', 'docker', 'http', 'kubernetes', 'local', 'docker-exec'],
            ],
            'environments' => [
                'root' => 'environments',
                'item_required' => ['name'],
                'item_optional' => ['color', 'targets', 'policies', 'description'],
            ],
            'secrets' => [
                'root' => 'secrets',
                'is_key_value' => true,
            ],
        ];
    }

    /**
     * Get the path for a config file
     */
    public function getConfigPath(string $type): string {
        return self::CONFIG_DIR . "/{$type}.yaml";
    }

    /**
     * Check if config file exists
     */
    public function configExists(string $type): bool {
        return file_exists($this->getConfigPath($type));
    }

    /**
     * Load configuration from YAML file
     */
    public function load(string $type): array {
        if ($type === 'secrets') {
            $secrets = $this->secretsStore->loadSecrets(function () use ($type) {
                $this->createBackup($type);
            });
            return ['secrets' => $secrets];
        }

        $path = $this->getConfigPath($type);
        
        if (!file_exists($path)) {
            // Return empty structure
            return $this->getEmptyConfig($type);
        }

        $content = file_get_contents($path);
        if (empty(trim($content))) {
            return $this->getEmptyConfig($type);
        }

        $data = yaml_parse($content);
        if ($data === false) {
            throw new Exception("Failed to parse YAML file: {$type}.yaml");
        }

        return $data;
    }

    /**
     * Load raw YAML content
     */
    public function loadRaw(string $type): string {
        if ($type === 'secrets') {
            return $this->secretsStore->loadRawYaml(function () use ($type) {
                $this->createBackup($type);
            });
        }

        $path = $this->getConfigPath($type);
        
        if (!file_exists($path)) {
            return $this->getExampleContent($type);
        }

        return file_get_contents($path);
    }

    /**
     * Get empty config structure
     */
    private function getEmptyConfig(string $type): array {
        $schema = $this->schemas[$type] ?? null;
        if (!$schema) {
            return [];
        }

        if ($schema['is_key_value'] ?? false) {
            return [$schema['root'] => []];
        }

        return [$schema['root'] => []];
    }

    /**
     * Get example content for new config files
     */
    private function getExampleContent(string $type): string {
        $examplePath = self::CONFIG_DIR . "/{$type}.example.yaml";
        if (file_exists($examplePath)) {
            return file_get_contents($examplePath);
        }

        // Default empty templates
        $templates = [
            'targets' => "# Targets Configuration\n# See targets.example.yaml for reference\n\ntargets: []\n",
            'environments' => "# Environments Configuration\n# See environments.example.yaml for reference\n\nenvironments: {}\n",
            'secrets' => "# Secrets Configuration\n# WARNING: This file should never be committed to git!\n\nsecrets: {}\n",
        ];

        return $templates[$type] ?? "# Configuration\n";
    }

    /**
     * Save configuration to YAML file
     */
    public function save(string $type, array $data, ?string $userId = null, ?string $username = null): array {
        $path = $this->getConfigPath($type);
        
        // Check YAML extension
        if (!function_exists('yaml_emit')) {
            return ['success' => false, 'errors' => ['YAML PHP extension not installed']];
        }

        if ($type === 'secrets') {
            $validation = $this->validate($type, $data);
            if (!$validation['valid']) {
                return ['success' => false, 'errors' => $validation['errors']];
            }

            // Create backup (encrypted or plaintext before migration)
            $this->createBackup($type);

            try {
                $secrets = $data['secrets'] ?? [];
                $this->secretsStore->saveSecrets($secrets);
            } catch (RuntimeException $e) {
                return ['success' => false, 'errors' => [$e->getMessage()]];
            }

            // Log the change
            $this->logChange($type, $userId, $username);

            return ['success' => true, 'backup' => $this->getLatestBackup($type)];
        }
        
        // Validate before saving
        $validation = $this->validate($type, $data);
        if (!$validation['valid']) {
            return ['success' => false, 'errors' => $validation['errors']];
        }

        // Create backup
        $this->createBackup($type);

        // Ensure directory exists
        $dir = dirname($path);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true)) {
                return ['success' => false, 'errors' => ['Failed to create directory: ' . $dir]];
            }
        }
        
        // Check directory is writable
        if (!is_writable($dir)) {
            return ['success' => false, 'errors' => ['Directory not writable: ' . $dir]];
        }

        // Convert to YAML and save
        $yaml = yaml_emit($data, YAML_UTF8_ENCODING);
        
        if ($yaml === false) {
            return ['success' => false, 'errors' => ['Failed to convert data to YAML']];
        }
        
        // Clean up YAML output (remove document markers if present)
        $yaml = preg_replace('/^---\n/', '', $yaml);
        $yaml = preg_replace('/\n\.\.\.$/', '', $yaml);
        
        $result = file_put_contents($path, $yaml);
        
        if ($result === false) {
            $error = error_get_last();
            return ['success' => false, 'errors' => ['Failed to write configuration file: ' . ($error['message'] ?? 'Unknown error')]];
        }

        // Log the change
        $this->logChange($type, $userId, $username);

        return ['success' => true, 'backup' => $this->getLatestBackup($type)];
    }

    /**
     * Save raw YAML content
     */
    public function saveRaw(string $type, string $content, ?string $userId = null, ?string $username = null): array {
        // Parse to validate YAML syntax
        $data = @yaml_parse($content);
        if ($data === false) {
            return ['success' => false, 'errors' => ['Invalid YAML syntax']];
        }

        // Validate structure
        $validation = $this->validate($type, $data);
        if (!$validation['valid']) {
            return ['success' => false, 'errors' => $validation['errors']];
        }

        if ($type === 'secrets') {
            // Create backup
            $this->createBackup($type);

            try {
                $secrets = $data['secrets'] ?? [];
                $this->secretsStore->saveSecrets($secrets);
            } catch (RuntimeException $e) {
                return ['success' => false, 'errors' => [$e->getMessage()]];
            }

            // Log the change
            $this->logChange($type, $userId, $username);

            return ['success' => true, 'backup' => $this->getLatestBackup($type)];
        }

        $path = $this->getConfigPath($type);

        // Create backup
        $this->createBackup($type);

        // Ensure directory exists
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $result = file_put_contents($path, $content);
        
        if ($result === false) {
            return ['success' => false, 'errors' => ['Failed to write configuration file']];
        }

        // Log the change
        $this->logChange($type, $userId, $username);

        return ['success' => true, 'backup' => $this->getLatestBackup($type)];
    }

    /**
     * Validate configuration data
     */
    public function validate(string $type, array $data): array {
        $schema = $this->schemas[$type] ?? null;
        if (!$schema) {
            return ['valid' => true, 'errors' => []];
        }

        $errors = [];
        $root = $schema['root'];

        // Check root key exists
        if (!isset($data[$root])) {
            $errors[] = "Missing required root key: '{$root}'";
            return ['valid' => false, 'errors' => $errors];
        }

        // For key-value configs (secrets), just check it's an array
        if ($schema['is_key_value'] ?? false) {
            if (!is_array($data[$root])) {
                $errors[] = "'{$root}' must be an object/array";
            } else {
                foreach ($data[$root] as $key => $value) {
                    if (is_string($key) && str_starts_with($key, '__')) {
                        $errors[] = "Secret keys cannot start with '__' (reserved): '{$key}'";
                    }
                }
            }
            return ['valid' => empty($errors), 'errors' => $errors];
        }

        // For list-based configs (targets, environments)
        $items = $data[$root];
        
        // Handle both array and object formats
        if (is_array($items)) {
            $ids = [];
            
            // Check if it's an associative array (environments style) or indexed array (targets style)
            $isAssoc = !empty($items) && array_keys($items) !== range(0, count($items) - 1);
            
            if ($isAssoc) {
                // Environments style: key is the ID
                foreach ($items as $id => $item) {
                    if (!is_array($item)) {
                        $errors[] = "Environment '{$id}' must be an object";
                        continue;
                    }
                    
                    // Check required fields (except 'id' which is the key)
                    foreach ($schema['item_required'] as $field) {
                        if ($field !== 'id' && !isset($item[$field])) {
                            $errors[] = "Environment '{$id}' missing required field: '{$field}'";
                        }
                    }
                }
            } else {
                // Targets style: array of objects with 'id' field
                foreach ($items as $index => $item) {
                    if (!is_array($item)) {
                        $errors[] = "Item at index {$index} must be an object";
                        continue;
                    }

                    // Check required fields
                    foreach ($schema['item_required'] as $field) {
                        if (!isset($item[$field])) {
                            $errors[] = "Item at index {$index} missing required field: '{$field}'";
                        }
                    }

                    // Check for duplicate IDs
                    if (isset($item['id'])) {
                        if (in_array($item['id'], $ids)) {
                            $errors[] = "Duplicate ID found: '{$item['id']}'";
                        }
                        $ids[] = $item['id'];
                    }

                    // Validate type if schema has types
                    if (isset($schema['types']) && isset($item['type'])) {
                        if (!in_array($item['type'], $schema['types'])) {
                            $itemId = $item['id'] ?? $index;
                            $errors[] = "Invalid type '{$item['type']}' for item '{$itemId}'. Valid types: " . implode(', ', $schema['types']);
                        }
                    }
                }
            }
        }

        return ['valid' => empty($errors), 'errors' => $errors];
    }

    /**
     * Create a backup of the config file
     */
    private function createBackup(string $type): ?string {
        $path = $this->getConfigPath($type);
        
        if (!file_exists($path)) {
            return null;
        }

        // Ensure history directory exists
        if (!is_dir(self::HISTORY_DIR)) {
            mkdir(self::HISTORY_DIR, 0755, true);
        }

        $timestamp = date('Y-m-d_H-i-s');
        $backupPath = self::HISTORY_DIR . "/{$type}.{$timestamp}.yaml";
        
        copy($path, $backupPath);
        
        return $backupPath;
    }

    /**
     * Get the latest backup for a config type
     */
    public function getLatestBackup(string $type): ?string {
        if (!is_dir(self::HISTORY_DIR)) {
            return null;
        }

        $pattern = self::HISTORY_DIR . "/{$type}.*.yaml";
        $files = glob($pattern);
        
        if (empty($files)) {
            return null;
        }

        // Sort by modification time, newest first
        usort($files, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        return basename($files[0]);
    }

    /**
     * Get all backups for a config type
     */
    public function getBackups(string $type): array {
        if (!is_dir(self::HISTORY_DIR)) {
            return [];
        }

        $pattern = self::HISTORY_DIR . "/{$type}.*.yaml";
        $files = glob($pattern);
        
        $backups = [];
        foreach ($files as $file) {
            $backups[] = [
                'filename' => basename($file),
                'timestamp' => filemtime($file),
                'date' => date('Y-m-d H:i:s', filemtime($file)),
                'size' => filesize($file),
            ];
        }

        // Sort by timestamp, newest first
        usort($backups, function($a, $b) {
            return $b['timestamp'] - $a['timestamp'];
        });

        return $backups;
    }

    /**
     * Restore from backup
     */
    public function restoreBackup(string $type, string $backupFilename, ?string $userId = null, ?string $username = null): array {
        $backupPath = self::HISTORY_DIR . "/" . basename($backupFilename);
        
        if (!file_exists($backupPath)) {
            return ['success' => false, 'error' => 'Backup file not found'];
        }

        // Validate the backup file
        $content = file_get_contents($backupPath);
        $data = @yaml_parse($content);
        
        if ($data === false) {
            return ['success' => false, 'error' => 'Backup file contains invalid YAML'];
        }

        // Create backup of current before restoring
        $this->createBackup($type);

        // Copy backup to config
        $path = $this->getConfigPath($type);
        copy($backupPath, $path);

        // Log the restore
        $this->logChange($type, $userId, $username, 'restore', $backupFilename);

        return ['success' => true];
    }

    /**
     * Log configuration change to audit log
     */
    private function logChange(string $type, ?string $userId = null, ?string $username = null, string $action = 'edit', ?string $details = null): void {
        require_once __DIR__ . '/Auth.php';
        
        $auth = new Auth();
        $auth->auditLog(
            $userId,
            $username ?: 'system',
            "config_{$action}",
            'config',
            $type,
            $details ?? "Modified {$type} configuration"
        );
    }

    // =====================
    // Target-specific methods
    // =====================

    /**
     * Get all targets
     */
    public function getTargets(): array {
        $data = $this->load('targets');
        return $data['targets'] ?? [];
    }

    /**
     * Get target by ID
     */
    public function getTarget(string $id): ?array {
        $targets = $this->getTargets();
        foreach ($targets as $target) {
            if ($target['id'] === $id) {
                return $target;
            }
        }
        return null;
    }

    /**
     * Add or update a target
     */
    public function saveTarget(array $target, ?string $userId = null, ?string $username = null): array {
        $data = $this->load('targets');
        $targets = $data['targets'] ?? [];
        
        $found = false;
        foreach ($targets as $i => $t) {
            if ($t['id'] === $target['id']) {
                $targets[$i] = $target;
                $found = true;
                break;
            }
        }
        
        if (!$found) {
            $targets[] = $target;
        }
        
        $data['targets'] = $targets;
        return $this->save('targets', $data, $userId, $username);
    }

    /**
     * Delete a target
     */
    public function deleteTarget(string $id, ?string $userId = null, ?string $username = null): array {
        $data = $this->load('targets');
        $targets = $data['targets'] ?? [];
        
        $targets = array_filter($targets, function($t) use ($id) {
            return $t['id'] !== $id;
        });
        
        $data['targets'] = array_values($targets);
        return $this->save('targets', $data, $userId, $username);
    }

    /**
     * Verify a target's capabilities and persist verification data
     */
    public function verifyTarget(string $id, ?string $userId = null, ?string $username = null, bool $debug = false): array {
        $target = $this->getTarget($id);
        if (!$target) {
            return ['success' => false, 'error' => 'Target not found'];
        }

        $verification = $this->verifyTargetCapabilities($target, $debug);
        $target['verifiedCapabilities'] = $verification['verifiedCapabilities'];
        $target['verification'] = $verification['verification'];

        $saveResult = $this->saveTarget($target, $userId, $username);
        if (!$saveResult['success']) {
            return ['success' => false, 'error' => 'Failed to save verification results', 'errors' => $saveResult['errors'] ?? []];
        }

        return [
            'success' => $verification['success'],
            'target' => $target,
            'verifiedCapabilities' => $verification['verifiedCapabilities'],
            'verification' => $verification['verification'],
            'error' => $verification['error'] ?? null,
        ];
    }

    /**
     * Prepare a temporary SSH HOME dir for Docker SSH transport
     */
    public function prepareDockerSshHome(array $ssh, ?string $keyPath = null, bool $hasPassword = false): ?array {
        $host = $ssh['host'] ?? null;
        if (!$host) {
            return null;
        }
        $port = (int)($ssh['port'] ?? 22);
        $user = $ssh['user'] ?? null;

        $home = sys_get_temp_dir() . '/doki-ssh-home-' . bin2hex(random_bytes(4));
        $sshDir = $home . '/.ssh';
        if (!@mkdir($sshDir, 0700, true) && !is_dir($sshDir)) {
            return null;
        }

        $knownHosts = $sshDir . '/known_hosts';
        $configPath = $sshDir . '/config';
        $config = "Host {$host}\n"
            . "  HostName {$host}\n"
            . "  Port {$port}\n";
        if (!empty($user)) {
            $config .= "  User {$user}\n";
        }
        if (!empty($keyPath)) {
            $config .= "  IdentityFile {$keyPath}\n"
                . "  IdentitiesOnly yes\n";
        }
        if ($hasPassword) {
            $config .= "  PreferredAuthentications publickey,password,keyboard-interactive\n"
                . "  PasswordAuthentication yes\n";
        }
        $config .= "  StrictHostKeyChecking no\n"
            . "  UserKnownHostsFile {$knownHosts}\n"
            . "  GlobalKnownHostsFile /dev/null\n"
            . "  LogLevel ERROR\n";
        file_put_contents($configPath, $config);
        @chmod($configPath, 0600);

        if ($this->commandExists('ssh-keyscan')) {
            $scan = $this->runCommand(['ssh-keyscan', '-p', (string)$port, '-H', $host], 6);
            if ($scan['success'] && !empty($scan['output'])) {
                file_put_contents($knownHosts, trim($scan['output']) . "\n", FILE_APPEND);
                @chmod($knownHosts, 0600);
            }
        }
        if (!file_exists($knownHosts)) {
            @file_put_contents($knownHosts, '');
            @chmod($knownHosts, 0600);
        }

        return [
            'env' => ['HOME' => $home],
            'cleanup' => [$home]
        ];
    }

    /**
     * Ensure host key is present in known_hosts for non-interactive SSH
     */
    public function ensureSshKnownHost(string $host, int $port = 22): void {
        if (!$this->commandExists('ssh-keyscan')) {
            return;
        }
        $homes = [];
        $envHome = getenv('HOME');
        if (!empty($envHome)) {
            $homes[] = $envHome;
        }
        $homes[] = '/var/www/html';
        $homes[] = '/var/www';
        $homes = array_values(array_unique($homes));

        $scanHashed = $this->runCommand(['ssh-keyscan', '-p', (string)$port, '-H', $host], 6);
        $scanPlain = $this->runCommand(['ssh-keyscan', '-p', (string)$port, $host], 6);
        $entries = [];
        if ($scanHashed['success'] && !empty($scanHashed['output'])) {
            $entries[] = trim($scanHashed['output']);
        }
        if ($scanPlain['success'] && !empty($scanPlain['output'])) {
            $entries[] = trim($scanPlain['output']);
        }
        if (empty($entries)) {
            return;
        }

        $entry = implode("\n", $entries) . "\n";
        $canPrune = $this->commandExists('ssh-keygen');
        foreach ($homes as $home) {
            $sshDir = rtrim($home, '/') . '/.ssh';
            $knownHosts = $sshDir . '/known_hosts';
            if (!is_dir($sshDir)) {
                if (!@mkdir($sshDir, 0700, true) && !is_dir($sshDir)) {
                    continue;
                }
            }
            if (!is_writable($sshDir)) {
                continue;
            }
            if ($canPrune && file_exists($knownHosts) && is_writable($knownHosts)) {
                $this->runCommand(['ssh-keygen', '-R', $host, '-f', $knownHosts], 6);
                $this->runCommand(['ssh-keygen', '-R', '[' . $host . ']:' . (string)$port, '-f', $knownHosts], 6);
            }
            @file_put_contents($knownHosts, $entry, FILE_APPEND);
            @chmod($knownHosts, 0600);

            $this->ensureSshConfigForHost($sshDir . '/config', $host, $port);
        }
    }

    /**
     * Ensure SSH config contains a host block that disables strict host key checking
     */
    private function ensureSshConfigForHost(string $configPath, string $host, int $port): void {
        $blockHeader = "Host {$host}";
        $block = $blockHeader . "\n"
            . "  HostName {$host}\n"
            . "  Port {$port}\n"
            . "  StrictHostKeyChecking no\n"
            . "  UserKnownHostsFile /dev/null\n"
            . "  GlobalKnownHostsFile /dev/null\n";

        if (file_exists($configPath)) {
            $current = file_get_contents($configPath);
            if (strpos($current, $blockHeader) !== false) {
                return;
            }
        }

        $entry = "\n# Doki managed\n" . $block;
        @file_put_contents($configPath, $entry, FILE_APPEND);
        @chmod($configPath, 0600);
    }

    /**
     * Attempt to set up a capability on a target (e.g., install Docker remotely)
     */
    public function setupTargetCapability(string $id, string $capability, string $userId, string $username): array {
        $target = $this->getTarget($id);
        if (!$target) {
            return ['success' => false, 'error' => 'Target not found'];
        }

        switch ($capability) {
            case 'exec.docker':
                $install = $this->installDockerForTarget($target);
                if (!$install['success']) {
                    return $install;
                }

                $verify = $this->verifyTarget($id, $userId, $username);
                $result = [
                    'success' => $verify['success'],
                    'message' => $install['message'] ?? 'Docker install completed',
                    'target' => $verify['target'] ?? $target,
                    'verification' => $verify['verification'] ?? null,
                    'verifiedCapabilities' => $verify['verifiedCapabilities'] ?? [],
                    'error' => $verify['error'] ?? null,
                    'output' => $install['output'] ?? null,
                ];

                if (!$verify['success'] && !$result['error']) {
                    $result['error'] = 'Setup completed, but verification failed';
                }
                if (!$verify['success']) {
                    $result['message'] = 'Docker installed, but verification failed';
                }

                return $result;
            default:
                return ['success' => false, 'error' => "Unsupported capability setup: {$capability}"];
        }
    }

    /**
     * Verify capabilities for a target based on its type
     */
    private function verifyTargetCapabilities(array $target, bool $debug = false): array {
        $type = $target['type'] ?? '';
        $verified = [];
        $checks = [];
        $errors = [];

        switch ($type) {
            case 'docker':
            case 'local':
            case 'docker-exec':
                $dockerCheck = $this->checkDockerCapability($target, $debug);
                $checks['exec.docker'] = $dockerCheck;
                if ($dockerCheck['ok']) {
                    $verified[] = 'exec.docker';
                } else {
                    $errors[] = $dockerCheck['message'];
                }
                break;
            case 'ssh':
                $sshCheck = $this->checkSshCapability($target);
                $checks['exec.ssh'] = $sshCheck;
                if ($sshCheck['ok']) {
                    $verified[] = 'exec.ssh';
                } else {
                    $errors[] = $sshCheck['message'];
                }
                break;
            case 'http':
                $httpCheck = $this->checkHttpCapability($target);
                $checks['http.call'] = $httpCheck;
                if ($httpCheck['ok']) {
                    $verified[] = 'http.call';
                } else {
                    $errors[] = $httpCheck['message'];
                }
                break;
            case 'kubernetes':
                $k8sCheck = $this->checkKubernetesCapability($target);
                $checks['kubernetes.exec'] = $k8sCheck;
                if ($k8sCheck['ok']) {
                    $verified[] = 'kubernetes.exec';
                } else {
                    $errors[] = $k8sCheck['message'];
                }
                break;
            default:
                $errors[] = "Unsupported target type: {$type}";
                break;
        }

        $success = empty($errors);
        $verification = [
            'status' => $success ? 'verified' : 'failed',
            'checkedAt' => date('c'),
            'checks' => $checks,
            'error' => $success ? null : implode(' | ', array_filter($errors)),
        ];

        return [
            'success' => $success,
            'verifiedCapabilities' => array_values(array_unique($verified)),
            'verification' => $verification,
            'error' => $verification['error'],
        ];
    }

    /**
     * Install Docker on a remote target via SSH (for docker targets using SSH)
     */
    private function installDockerForTarget(array $target): array {
        if (($target['type'] ?? '') !== 'docker') {
            return ['success' => false, 'error' => 'Docker setup is only supported for docker targets'];
        }

        $resolved = $this->resolveDockerConnection($target);
        if (!$resolved['success']) {
            return ['success' => false, 'error' => $resolved['error']];
        }

        $connection = $resolved['connection'];
        $ssh = $connection['ssh'] ?? null;
        if (!is_array($ssh) || empty($ssh['host']) || empty($ssh['user'])) {
            return ['success' => false, 'error' => 'Docker setup requires an SSH connection'];
        }

        $sshCheck = $this->checkSshCapability(['type' => 'ssh', 'connection' => $ssh]);
        if (!$sshCheck['ok']) {
            return ['success' => false, 'error' => 'SSH not reachable: ' . $sshCheck['message']];
        }

        $script = $this->buildDockerInstallScript();
        $result = $this->runSshScript($ssh, $script, 240);

        if (!$result['success']) {
            return [
                'success' => false,
                'error' => 'Docker install failed: ' . ($result['output'] ?: 'Unknown error'),
                'output' => $result['output'] ?? null,
            ];
        }

        return [
            'success' => true,
            'message' => 'Docker install completed',
            'output' => $result['output'] ?? null,
        ];
    }

    /**
     * Build Docker install script (remote)
     */
    private function buildDockerInstallScript(): string {
        return 'set -e; '
            . 'if command -v docker >/dev/null 2>&1; then docker --version; exit 0; fi; '
            . 'if command -v curl >/dev/null 2>&1; then curl -fsSL https://get.docker.com -o /tmp/get-docker.sh; '
            . 'elif command -v wget >/dev/null 2>&1; then wget -qO /tmp/get-docker.sh https://get.docker.com; '
            . 'else echo "curl or wget required" >&2; exit 1; fi; '
            . 'if [ "$(id -u)" -ne 0 ]; then '
            . 'if command -v sudo >/dev/null 2>&1; then sudo -n sh /tmp/get-docker.sh; '
            . 'else echo "sudo not available; run as root" >&2; exit 1; fi; '
            . 'else sh /tmp/get-docker.sh; fi; '
            . 'if command -v sudo >/dev/null 2>&1; then sudo -n usermod -aG docker "$USER" >/dev/null 2>&1 || true; fi; '
            . 'if command -v sudo >/dev/null 2>&1 && command -v systemctl >/dev/null 2>&1; then sudo -n systemctl enable --now docker >/dev/null 2>&1 || true; fi; '
            . 'docker --version; '
            . 'docker info --format "{{.ServerVersion}}"';
    }

    /**
     * Run a remote SSH script
     */
    private function runSshScript(array $ssh, string $script, int $timeout = 120): array {
        if (!$this->commandExists('ssh')) {
            return ['success' => false, 'output' => 'SSH client not available on server'];
        }

        $host = $ssh['host'] ?? null;
        $user = $ssh['user'] ?? null;
        $port = (int)($ssh['port'] ?? 22);
        if (!$host || !$user) {
            return ['success' => false, 'output' => 'SSH host and user are required'];
        }

        $keyRef = $ssh['keyRef'] ?? $ssh['keySecret'] ?? null;
        $passwordRef = $ssh['passwordRef'] ?? $ssh['passwordSecret'] ?? null;
        $keyPath = null;
        $passwordPath = null;
        $env = [];
        $cleanup = [];

        if ($keyRef) {
            $keyValue = $this->getSecret($keyRef);
            if (!$keyValue) {
                return ['success' => false, 'output' => "SSH key secret '{$keyRef}' not found"];
            }
            $keyPath = $this->writeTempSecretFile($keyValue, 'doki-ssh-');
            $cleanup[] = $keyPath;
        }

        $passwordValue = null;
        if ($passwordRef) {
            $passwordValue = $this->getSecret($passwordRef);
            if ($passwordValue === null) {
                if ($keyPath) {
                    @unlink($keyPath);
                }
                return ['success' => false, 'output' => "SSH password secret '{$passwordRef}' not found"];
            }
            $passwordPath = $this->writeTempSecretFile($passwordValue, 'doki-ssh-pass-');
            $cleanup[] = $passwordPath;
        }

        $cmd = [];
        if ($passwordPath && $this->commandExists('sshpass')) {
            $cmd[] = 'sshpass';
            $cmd[] = '-f';
            $cmd[] = $passwordPath;
        }
        $cmd[] = 'ssh';
        $cmd[] = '-o';
        $cmd[] = 'BatchMode=' . ($passwordPath ? 'no' : 'yes');
        if ($passwordPath) {
            $cmd[] = '-o';
            $cmd[] = 'PreferredAuthentications=publickey,password,keyboard-interactive';
            $cmd[] = '-o';
            $cmd[] = 'PasswordAuthentication=yes';
        }
        $cmd[] = '-o';
        $cmd[] = 'StrictHostKeyChecking=no';
        $cmd[] = '-o';
        $cmd[] = 'UserKnownHostsFile=/dev/null';
        $cmd[] = '-o';
        $cmd[] = 'ConnectTimeout=10';
        $cmd[] = '-p';
        $cmd[] = (string)$port;
        if ($keyPath) {
            $cmd[] = '-i';
            $cmd[] = $keyPath;
            $cmd[] = '-o';
            $cmd[] = 'IdentitiesOnly=yes';
        }
        $cmd[] = "{$user}@{$host}";
        $cmd[] = 'sh';
        $cmd[] = '-c';
        $cmd[] = $script;

        if ($passwordPath && !$this->commandExists('sshpass')) {
            $askpass = $this->prepareSshAskpass($passwordValue);
            $env = array_merge($env, $askpass['env']);
            $cleanup = array_merge($cleanup, $askpass['cleanup']);
        }

        $result = $this->runCommand($cmd, $timeout, $env);

        $this->cleanupPaths($cleanup);

        return $result;
    }

    /**
     * Check Docker capability for a target
     */
    private function checkDockerCapability(array $target, bool $debug = false): array {
        if (!$this->commandExists('docker')) {
            return ['ok' => false, 'message' => 'Docker CLI not available on server'];
        }

        $resolved = $this->resolveDockerConnection($target);
        if (!$resolved['success']) {
            return ['ok' => false, 'message' => $resolved['error']];
        }
        $connection = $resolved['connection'];
        $socket = $connection['socket'] ?? null;
        if ($socket && !file_exists($socket)) {
            return ['ok' => false, 'message' => "Docker socket not found: {$socket}"];
        }

        $env = [];
        $cleanup = [];
        $dockerHost = $this->getDockerHostFromTarget($target);
        if ($dockerHost) {
            $env['DOCKER_HOST'] = $dockerHost;

            if (strpos($dockerHost, 'ssh://') === 0) {
                $ssh = $connection['ssh'] ?? null;
                if (is_array($ssh)) {
                    $keyRef = $ssh['keyRef'] ?? $ssh['keySecret'] ?? null;
                    $passwordRef = $ssh['passwordRef'] ?? $ssh['passwordSecret'] ?? null;
                    $keyPath = null;
                    $passwordPath = null;
                    $passwordValue = null;

                    if ($keyRef) {
                        $keyValue = $this->getSecret($keyRef);
                        if (!$keyValue) {
                            return ['ok' => false, 'message' => "SSH key secret '{$keyRef}' not found"];
                        }
                        $keyPath = $this->writeTempSecretFile($keyValue, 'doki-ssh-');
                        $cleanup[] = $keyPath;
                    }

                    if ($passwordRef) {
                        $passwordValue = $this->getSecret($passwordRef);
                        if ($passwordValue === null) {
                            return ['ok' => false, 'message' => "SSH password secret '{$passwordRef}' not found"];
                        }
                        $passwordPath = $this->writeTempSecretFile($passwordValue, 'doki-ssh-pass-');
                        $cleanup[] = $passwordPath;

                        $askpass = $this->prepareSshAskpass($passwordValue);
                        $env = array_merge($env, $askpass['env']);
                        $cleanup = array_merge($cleanup, $askpass['cleanup']);
                    }

                    $homeBundle = $this->prepareDockerSshHome($ssh, $keyPath, $passwordValue !== null);
                    if ($homeBundle) {
                        $env = array_merge($env, $homeBundle['env']);
                        $cleanup = array_merge($cleanup, $homeBundle['cleanup']);
                    }
                    if (!empty($ssh['host'])) {
                        $this->ensureSshKnownHost($ssh['host'], (int)($ssh['port'] ?? 22));
                    }

                    $env['DOCKER_SSH_COMMAND'] = $this->buildSshCommand($ssh, $keyPath, $passwordPath);
                }
            }
        }

        $timeout = (strpos((string)$dockerHost, 'ssh://') === 0) ? 20 : 8;
        $result = $this->runCommand(['docker', 'info', '--format', '{{.ServerVersion}}'], $timeout, $env);

        $this->cleanupPaths($cleanup);

        $version = trim((string)($result['output'] ?? ''));
        $normalized = strtolower($version);
        $daemonError = $version === ''
            || str_contains($normalized, 'permission denied')
            || str_contains($normalized, 'operation not permitted')
            || str_contains($normalized, 'cannot connect')
            || str_contains($normalized, 'error response from daemon');

        if ($result['success'] && !$daemonError) {
            return ['ok' => true, 'message' => $version ? "Docker reachable ({$version})" : 'Docker reachable'];
        }

        $message = $version !== '' ? $version : ('Docker info failed (exit ' . ($result['exitCode'] ?? 'unknown') . ')');
        if (stripos($message, 'permission denied') !== false || stripos($message, 'got permission denied') !== false) {
            $lower = strtolower($message);
            if (strpos($lower, 'publickey') !== false || strpos($lower, 'keyboard-interactive') !== false) {
                $message .= ' (SSH authentication failed; check the SSH user, key, or password)';
            } else {
                $message .= ' (user lacks access to the Docker daemon; ensure the SSH user is in the docker group or use root)';
            }
        }

        $debugInfo = null;
        if ($debug && strpos((string)$dockerHost, 'ssh://') === 0) {
            $debugInfo = $this->buildDockerSshDebugInfo($dockerHost, $env, $connection['ssh'] ?? null);
        }

        if (strpos((string)$dockerHost, 'ssh://') === 0) {
            $ssh = $connection['ssh'] ?? null;
            if (is_array($ssh) && !empty($ssh['host']) && !empty($ssh['user'])) {
                $sshDiag = $this->runSshScript($ssh, 'docker info >/dev/null 2>&1 && echo ok || (docker info 2>&1 | head -n 3)', 20);
                if ($sshDiag['success'] && !empty($sshDiag['output'])) {
                    $message = 'Remote Docker reachable (' . trim($sshDiag['output']) . '), but Docker SSH transport failed. ' . $message;
                } elseif (!empty($sshDiag['output'])) {
                    $message = 'Docker SSH transport failed. Remote docker check: ' . trim($sshDiag['output']);
                }
            }
        }

        $result = ['ok' => false, 'message' => $message];
        if ($debugInfo !== null) {
            $result['debug'] = $debugInfo;
        }
        return $result;
    }

    /**
     * Build debug info for Docker SSH transport
     */
    private function buildDockerSshDebugInfo(string $dockerHost, array $env, ?array $ssh): array {
        $homeEnv = $env['HOME'] ?? getenv('HOME') ?: null;
        $homes = array_values(array_unique(array_filter([
            $homeEnv,
            '/var/www/html',
            '/var/www'
        ])));

        $knownHostsInfo = [];
        foreach ($homes as $home) {
            $path = rtrim($home, '/') . '/.ssh/known_hosts';
            $entry = [
                'path' => $path,
                'exists' => file_exists($path),
                'size' => file_exists($path) ? filesize($path) : 0,
            ];
            if ($ssh && !empty($ssh['host']) && $this->commandExists('ssh-keygen') && file_exists($path)) {
                $check = $this->runCommand(['ssh-keygen', '-F', $ssh['host'], '-f', $path], 4);
                $entry['hasHost'] = $check['success'] && !empty($check['output']);
            }
            $knownHostsInfo[] = $entry;
        }

        $dockerDebug = null;
        $debugRun = $this->runCommand(['docker', '-D', 'info'], 10, $env);
        if (!empty($debugRun['output'])) {
            $lines = explode("\n", $debugRun['output']);
            $filtered = [];
            foreach ($lines as $line) {
                if (stripos($line, 'ssh') !== false || stripos($line, 'known_hosts') !== false || stripos($line, 'host key') !== false) {
                    $filtered[] = $line;
                }
                if (count($filtered) >= 12) {
                    break;
                }
            }
            $dockerDebug = $filtered ?: array_slice($lines, 0, 6);
        }

        return [
            'dockerHost' => $dockerHost,
            'home' => $homeEnv,
            'dockerSshCommand' => $env['DOCKER_SSH_COMMAND'] ?? null,
            'knownHosts' => $knownHostsInfo,
            'dockerDebug' => $dockerDebug
        ];
    }

    /**
     * Resolve docker connection (supports sshTargetId)
     */
    private function resolveDockerConnection(array $target): array {
        $connection = $target['connection'] ?? [];

        if (!empty($connection['sshTargetId'])) {
            $sshTargetId = $connection['sshTargetId'];
            $sshTarget = $this->getTarget($sshTargetId);
            if (!$sshTarget) {
                return ['success' => false, 'error' => "SSH target '{$sshTargetId}' not found"];
            }
            if (($sshTarget['type'] ?? '') !== 'ssh') {
                return ['success' => false, 'error' => "Target '{$sshTargetId}' is not an SSH target"];
            }
            $connection = [
                'ssh' => $sshTarget['connection'] ?? [],
                'sshTargetId' => $sshTargetId
            ];
        }

        return ['success' => true, 'connection' => $connection];
    }

    /**
     * Check SSH capability for a target
     */
    private function checkSshCapability(array $target): array {
        if (!$this->commandExists('ssh')) {
            return ['ok' => false, 'message' => 'SSH client not available on server'];
        }

        $connection = $target['connection'] ?? [];
        $host = $connection['host'] ?? null;
        $user = $connection['user'] ?? null;
        $port = (int)($connection['port'] ?? 22);
        $keyRef = $connection['keyRef'] ?? $connection['keySecret'] ?? null;
        $passwordRef = $connection['passwordRef'] ?? $connection['passwordSecret'] ?? null;

        if (!$host || !$user) {
            return ['ok' => false, 'message' => 'SSH target requires host and user'];
        }

        $keyPath = null;
        if ($keyRef) {
            $keyValue = $this->getSecret($keyRef);
            if (!$keyValue) {
                return ['ok' => false, 'message' => "SSH key secret '{$keyRef}' not found"];
            }
            $keyPath = $this->writeTempSecretFile($keyValue, 'doki-ssh-');
        }

        $passwordPath = null;
        $passwordValue = null;
        if ($passwordRef) {
            $passwordValue = $this->getSecret($passwordRef);
            if ($passwordValue === null) {
                if ($keyPath) {
                    @unlink($keyPath);
                }
                return ['ok' => false, 'message' => "SSH password secret '{$passwordRef}' not found"];
            }
            $passwordPath = $this->writeTempSecretFile($passwordValue, 'doki-ssh-pass-');
        }

        $cmd = [];
        if ($passwordPath && $this->commandExists('sshpass')) {
            $cmd[] = 'sshpass';
            $cmd[] = '-f';
            $cmd[] = $passwordPath;
        }
        $cmd[] = 'ssh';
        $cmd[] = '-o';
        $cmd[] = 'BatchMode=' . ($passwordValue ? 'no' : 'yes');
        if ($passwordValue) {
            $cmd[] = '-o';
            $cmd[] = 'PreferredAuthentications=publickey,password,keyboard-interactive';
            $cmd[] = '-o';
            $cmd[] = 'PasswordAuthentication=yes';
        }
        $cmd[] = '-o';
        $cmd[] = 'StrictHostKeyChecking=no';
        $cmd[] = '-o';
        $cmd[] = 'UserKnownHostsFile=/dev/null';
        $cmd[] = '-o';
        $cmd[] = 'ConnectTimeout=5';
        $cmd[] = '-p';
        $cmd[] = (string)$port;
        if ($keyPath) {
            $cmd[] = '-i';
            $cmd[] = $keyPath;
            $cmd[] = '-o';
            $cmd[] = 'IdentitiesOnly=yes';
        }
        $cmd[] = "{$user}@{$host}";
        $cmd[] = 'echo ok';

        $env = [];
        $cleanup = [];
        if ($passwordValue && !$this->commandExists('sshpass')) {
            $askpass = $this->prepareSshAskpass($passwordValue);
            $env = array_merge($env, $askpass['env']);
            $cleanup = array_merge($cleanup, $askpass['cleanup']);
        }

        $result = $this->runCommand($cmd, 8, $env);

        if ($keyPath) {
            @unlink($keyPath);
        }
        if ($passwordPath) {
            @unlink($passwordPath);
        }
        foreach ($cleanup as $path) {
            @unlink($path);
        }

        if ($result['success']) {
            return ['ok' => true, 'message' => 'SSH connection successful'];
        }

        return ['ok' => false, 'message' => $result['output'] ?: 'SSH connection failed'];
    }

    /**
     * Check HTTP capability for a target
     */
    private function checkHttpCapability(array $target): array {
        if (!$this->commandExists('curl')) {
            return ['ok' => false, 'message' => 'curl not available on server'];
        }

        $connection = $target['connection'] ?? [];
        $baseUrl = $connection['baseUrl'] ?? null;
        if (!$baseUrl) {
            return ['ok' => false, 'message' => 'HTTP target requires baseUrl'];
        }

        $cmd = ['curl', '-sS', '-o', '/dev/null', '-w', '%{http_code}', '--max-time', '6'];

        $auth = $connection['auth'] ?? [];
        $authType = $auth['type'] ?? 'none';
        if ($authType === 'basic') {
            $username = $auth['username'] ?? null;
            $password = $auth['password'] ?? null;
            if (!empty($auth['usernameRef'])) {
                $username = $this->getSecret($auth['usernameRef']);
            }
            if (!empty($auth['passwordRef'])) {
                $password = $this->getSecret($auth['passwordRef']);
            }
            if ($username === null || $password === null) {
                return ['ok' => false, 'message' => 'HTTP basic auth requires username/password'];
            }
            $cmd[] = '-u';
            $cmd[] = "{$username}:{$password}";
        } elseif ($authType === 'bearer') {
            $token = $auth['token'] ?? null;
            if (!empty($auth['tokenRef'])) {
                $token = $this->getSecret($auth['tokenRef']);
            }
            if (!$token) {
                return ['ok' => false, 'message' => 'HTTP bearer auth requires token'];
            }
            $cmd[] = '-H';
            $cmd[] = 'Authorization: Bearer ' . $token;
        }

        $cmd[] = $baseUrl;

        $result = $this->runCommand($cmd, 8);
        if (!$result['success']) {
            return ['ok' => false, 'message' => $result['output'] ?: 'HTTP probe failed'];
        }

        $status = (int)trim($result['output']);
        if ($status >= 200 && $status < 400) {
            return ['ok' => true, 'message' => "HTTP reachable ({$status})"];
        }

        return ['ok' => false, 'message' => "HTTP returned status {$status}"];
    }

    /**
     * Check Kubernetes capability for a target
     */
    private function checkKubernetesCapability(array $target): array {
        if (!$this->commandExists('kubectl')) {
            return ['ok' => false, 'message' => 'kubectl not available on server'];
        }

        $connection = $target['connection'] ?? [];
        $kubeconfigRef = $connection['kubeconfigRef'] ?? null;
        if (!$kubeconfigRef) {
            return ['ok' => false, 'message' => 'Kubernetes target requires kubeconfigRef'];
        }

        $kubeconfig = $this->getSecret($kubeconfigRef);
        if (!$kubeconfig) {
            return ['ok' => false, 'message' => "Kubeconfig secret '{$kubeconfigRef}' not found"];
        }

        $kubePath = $this->writeTempSecretFile($kubeconfig, 'doki-kubeconfig-');

        $cmd = ['kubectl'];
        if (!empty($connection['context'])) {
            $cmd[] = '--context';
            $cmd[] = $connection['context'];
        }
        $cmd[] = '--kubeconfig';
        $cmd[] = $kubePath;
        $cmd[] = 'cluster-info';

        $result = $this->runCommand($cmd, 12);

        @unlink($kubePath);

        if ($result['success']) {
            return ['ok' => true, 'message' => 'Kubernetes reachable'];
        }

        return ['ok' => false, 'message' => $result['output'] ?: 'Kubernetes probe failed'];
    }

    /**
     * Build DOCKER_HOST from target connection
     */
    private function getDockerHostFromTarget(array $target): ?string {
        $resolved = $this->resolveDockerConnection($target);
        if (!$resolved['success']) {
            return null;
        }
        $connection = $resolved['connection'];
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
     * Build SSH command used by Docker (ssh://) connections
     */
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

    /**
     * Check if a command exists on the system
     */
    private function commandExists(string $command): bool {
        $result = $this->runCommand(['sh', '-c', 'command -v ' . escapeshellarg($command)], 4);
        return $result['success'];
    }

    /**
     * Run a shell command with timeout and optional environment variables
     */
    private function runCommand(array $cmd, int $timeout = 10, array $env = []): array {
        $timeout = max(1, $timeout);
        $command = $this->buildShellCommand($cmd, $env) . ' 2>&1';

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open(['/bin/sh', '-lc', $command], $descriptors, $pipes);
        if (!is_resource($process)) {
            return [
                'success' => false,
                'output' => 'Unable to start command',
                'exitCode' => 1,
            ];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output = '';
        $timedOut = false;
        $deadline = microtime(true) + $timeout;
        $resolvedExitCode = null;

        while (true) {
            $status = proc_get_status($process);
            $running = !empty($status['running']);
            if (!$running && isset($status['exitcode']) && $status['exitcode'] >= 0) {
                $resolvedExitCode = (int)$status['exitcode'];
            }
            $remaining = $deadline - microtime(true);

            $read = [];
            if (!feof($pipes[1])) {
                $read[] = $pipes[1];
            }
            if (!feof($pipes[2])) {
                $read[] = $pipes[2];
            }

            if ($read !== []) {
                if ($running && $remaining > 0) {
                    $seconds = (int)$remaining;
                    $microseconds = (int)(($remaining - $seconds) * 1_000_000);
                    @stream_select($read, $write, $except, $seconds, $microseconds);
                }

                foreach ($read as $stream) {
                    $chunk = stream_get_contents($stream);
                    if ($chunk !== false && $chunk !== '') {
                        $output .= $chunk;
                    }
                }
            }

            if (!$running) {
                break;
            }

            if ($remaining <= 0) {
                $timedOut = true;
                @proc_terminate($process);
                usleep(100000);

                $status = proc_get_status($process);
                if (!empty($status['running'])) {
                    @proc_terminate($process, 9);
                }
                break;
            }
        }

        foreach ([1, 2] as $index) {
            $chunk = stream_get_contents($pipes[$index]);
            if ($chunk !== false && $chunk !== '') {
                $output .= $chunk;
            }
            fclose($pipes[$index]);
        }

        $exitCode = proc_close($process);
        if ($exitCode === -1 && $resolvedExitCode !== null) {
            $exitCode = $resolvedExitCode;
        }
        $output = trim($output);

        if ($timedOut) {
            if ($output === '') {
                $output = 'Command timed out after ' . $timeout . 's';
            } else {
                $output .= "\n" . 'Command timed out after ' . $timeout . 's';
            }
            $exitCode = 124;
        }

        return [
            'success' => $exitCode === 0,
            'output' => $output,
            'exitCode' => $exitCode,
        ];
    }

    private function buildShellCommand(array $cmd, array $env = []): string {
        $parts = [];
        foreach ($env as $key => $value) {
            $parts[] = $key . '=' . escapeshellarg((string)$value);
        }
        foreach ($cmd as $part) {
            $parts[] = escapeshellarg((string)$part);
        }
        return implode(' ', $parts);
    }

    /**
     * Write secret content to a temp file and return path
     */
    private function writeTempSecretFile(string $content, string $prefix): string {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        file_put_contents($path, $content);
        chmod($path, 0600);
        return $path;
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
     * Cleanup temp files/directories
     */
    private function cleanupPaths(array $paths): void {
        foreach ($paths as $path) {
            if (!$path) continue;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
    }

    /**
     * Recursively remove a directory
     */
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

    // =====================
    // Environment-specific methods
    // =====================

    /**
     * Get all environments
     */
    public function getEnvironments(): array {
        $data = $this->load('environments');
        return $data['environments'] ?? [];
    }

    /**
     * Get environment by ID
     */
    public function getEnvironment(string $id): ?array {
        $environments = $this->getEnvironments();
        return $environments[$id] ?? null;
    }

    /**
     * Save environment
     */
    public function saveEnvironment(string $id, array $environment, string $userId, string $username): array {
        $data = $this->load('environments');
        $environments = $data['environments'] ?? [];
        
        $environments[$id] = $environment;
        $data['environments'] = $environments;
        
        return $this->save('environments', $data, $userId, $username);
    }

    /**
     * Delete environment
     */
    public function deleteEnvironment(string $id, string $userId, string $username): array {
        $data = $this->load('environments');
        $environments = $data['environments'] ?? [];
        
        unset($environments[$id]);
        $data['environments'] = $environments;
        
        return $this->save('environments', $data, $userId, $username);
    }

    // =====================
    // Secret-specific methods
    // =====================

    /**
     * Get secrets store status
     */
    public function getSecretsStatus(): array {
        return $this->secretsStore->getStatus();
    }

    /**
     * Get metadata map for secrets
     */
    private function getSecretMetadataMap(): array {
        try {
            $stmt = $this->db->query("SELECT * FROM secret_metadata");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }

        $map = [];
        foreach ($rows as $row) {
            $map[$row['secret_id']] = $row;
        }
        return $map;
    }

    /**
     * Upsert metadata for a secret (versioned)
     */
    private function upsertSecretMetadata(string $key, string $userId, ?string $expiresAt, ?int $rotationDays): void {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO secret_metadata (secret_id, version, updated_at, last_rotated_at, expires_at, rotation_interval_days, updated_by)
                VALUES (?, 1, datetime('now'), datetime('now'), ?, ?, ?)
                ON CONFLICT(secret_id) DO UPDATE SET
                    version = version + 1,
                    updated_at = datetime('now'),
                    last_rotated_at = datetime('now'),
                    expires_at = excluded.expires_at,
                    rotation_interval_days = excluded.rotation_interval_days,
                    updated_by = excluded.updated_by
            ");
            $stmt->execute([$key, $expiresAt, $rotationDays, $userId]);
        } catch (PDOException $e) {
            // Metadata is optional; ignore DB errors
        }
    }

    /**
     * Delete metadata for a secret
     */
    private function deleteSecretMetadata(string $key): void {
        try {
            $stmt = $this->db->prepare("DELETE FROM secret_metadata WHERE secret_id = ?");
            $stmt->execute([$key]);
        } catch (PDOException $e) {
            // Ignore if metadata table doesn't exist
        }
    }

    /**
     * Get all secret keys (not values)
     */
    public function getSecretKeys(): array {
        $data = $this->load('secrets');
        $secrets = $data['secrets'] ?? [];

        $metadata = $this->getSecretMetadataMap();
        $now = new DateTimeImmutable('now');
        
        $keys = [];
        foreach ($secrets as $key => $value) {
            $meta = $metadata[$key] ?? null;
            $expired = false;
            $rotationDue = false;
            $daysToExpiry = null;

            if (!empty($meta['expires_at'])) {
                try {
                    $expiresAt = new DateTimeImmutable($meta['expires_at']);
                    $expired = $expiresAt <= $now;
                    $daysToExpiry = (int)floor(($expiresAt->getTimestamp() - $now->getTimestamp()) / 86400);
                } catch (Exception $e) {
                    $expiresAt = null;
                }
            }

            if (!empty($meta['rotation_interval_days']) && !empty($meta['last_rotated_at'])) {
                try {
                    $lastRotated = new DateTimeImmutable($meta['last_rotated_at']);
                    $nextRotation = $lastRotated->modify('+' . (int)$meta['rotation_interval_days'] . ' days');
                    $rotationDue = $nextRotation <= $now;
                } catch (Exception $e) {
                    $rotationDue = false;
                }
            }

            $keys[] = [
                'id' => $key,
                'type' => $this->detectSecretType($value),
                'length' => is_string($value) ? strlen($value) : null,
                'version' => $meta['version'] ?? null,
                'lastRotatedAt' => $meta['last_rotated_at'] ?? null,
                'expiresAt' => $meta['expires_at'] ?? null,
                'rotationDays' => $meta['rotation_interval_days'] ?? null,
                'rotationDue' => $rotationDue,
                'expired' => $expired,
                'daysToExpiry' => $daysToExpiry,
            ];
        }
        
        return $keys;
    }

    /**
     * Get a secret value (requires re-auth in UI)
     */
    public function getSecret(string $key): ?string {
        $data = $this->load('secrets');
        $secrets = $data['secrets'] ?? [];
        
        return $secrets[$key] ?? null;
    }

    /**
     * Save a secret
     */
    public function saveSecret(string $key, string $value, string $userId, string $username, ?array $meta = null): array {
        $data = $this->load('secrets');
        $secrets = $data['secrets'] ?? [];
        
        $secrets[$key] = $value;
        $data['secrets'] = $secrets;

        $expiresAt = $meta['expiresAt'] ?? null;
        if (is_string($expiresAt) && trim($expiresAt) !== '') {
            $timestamp = strtotime($expiresAt . ' 00:00:00');
            $expiresAt = $timestamp ? date('Y-m-d H:i:s', $timestamp) : null;
        } else {
            $expiresAt = null;
        }

        $rotationDays = $meta['rotationDays'] ?? null;
        $rotationDays = is_numeric($rotationDays) ? (int)$rotationDays : null;
        
        // Log secret access
        $this->logChange('secrets', $userId, $username, 'secret_edit', "Modified secret: {$key}");
        
        $result = $this->save('secrets', $data, $userId, $username);
        if ($result['success']) {
            $this->upsertSecretMetadata($key, $userId, $expiresAt, $rotationDays);
        }

        return $result;
    }

    /**
     * Delete a secret
     */
    public function deleteSecret(string $key, string $userId, string $username): array {
        $data = $this->load('secrets');
        $secrets = $data['secrets'] ?? [];
        
        unset($secrets[$key]);
        $data['secrets'] = $secrets;
        
        $this->logChange('secrets', $userId, $username, 'secret_delete', "Deleted secret: {$key}");
        
        $result = $this->save('secrets', $data, $userId, $username);
        if ($result['success']) {
            $this->deleteSecretMetadata($key);
        }
        return $result;
    }

    /**
     * Detect secret type from value
     */
    private function detectSecretType(mixed $value): string {
        if (!is_string($value)) {
            return 'unknown';
        }
        
        if (strpos($value, '-----BEGIN') !== false) {
            if (strpos($value, 'PRIVATE KEY') !== false) {
                return 'private_key';
            }
            if (strpos($value, 'CERTIFICATE') !== false) {
                return 'certificate';
            }
            return 'pem';
        }
        
        if (strpos($value, 'apiVersion:') !== false && strpos($value, 'kind:') !== false) {
            return 'kubeconfig';
        }
        
        if (strlen($value) < 100) {
            return 'password';
        }
        
        return 'text';
    }
}
