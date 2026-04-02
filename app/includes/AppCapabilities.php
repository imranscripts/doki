<?php
/**
 * AppCapabilities - Defines trust levels and capabilities for apps
 * 
 * Trust Levels:
 *   0 - Core: Built-in apps with full access
 *   1 - Verified: Official marketplace apps (code reviewed)
 *   2 - Trusted: Admin-approved apps
 *   3 - Sandboxed: Default for all new installs (restricted)
 */

class AppCapabilities {
    // Trust levels
    const TRUST_CORE = 0;
    const TRUST_VERIFIED = 1;
    const TRUST_TRUSTED = 2;
    const TRUST_SANDBOXED = 3;
    
    // Capability constants
    // Authentication
    const AUTH_READ_USER = 'auth.read_user';
    const AUTH_CHECK_ROLES = 'auth.check_roles';
    
    // Audit
    const AUDIT_WRITE = 'audit.write';
    const AUDIT_READ = 'audit.read';
    
    // Database
    const DB_FULL = 'db.full';
    const DB_APP_TABLES = 'db.app_tables';
    
    // File System
    const FS_APP_DATA = 'fs.app_data';
    const FS_OTHER_APPS = 'fs.other_apps';
    const FS_CORE = 'fs.core';
    
    // Configuration
    const CONFIG_TARGETS = 'config.targets';
    const CONFIG_ENVIRONMENTS = 'config.environments';
    const CONFIG_SECRETS = 'config.secrets';
    const CONFIG_MODIFY = 'config.modify';
    
    // Execution
    const EXEC_COMMANDS = 'exec.commands';
    const EXEC_DOCKER = 'exec.docker';
    const EXEC_SSH = 'exec.ssh';
    const EXEC_ANY_TARGET = 'exec.any_target';
    
    // Git
    const GIT_PROVIDERS = 'git.providers';
    const GIT_TOKENS = 'git.tokens';
    
    // UI
    const UI_LAYOUT = 'ui.layout';
    const UI_INJECT_GLOBAL = 'ui.inject_global';
    
    // Users
    const USERS_LIST = 'users.list';
    const USERS_MANAGE = 'users.manage';
    
    /**
     * Get trust level info
     */
    public static function getTrustLevelInfo(int $level): array {
        $levels = [
            self::TRUST_CORE => [
                'id' => self::TRUST_CORE,
                'name' => 'Core',
                'icon' => 'fa-cog',
                'color' => '#6366f1',
                'emoji' => '⚙️',
                'description' => 'Built-in apps with full access',
                'canBeSet' => false, // Only for bundled apps
            ],
            self::TRUST_VERIFIED => [
                'id' => self::TRUST_VERIFIED,
                'name' => 'Verified',
                'icon' => 'fa-check-circle',
                'color' => '#22c55e',
                'emoji' => '✅',
                'description' => 'Official marketplace apps (code reviewed)',
                'canBeSet' => true,
            ],
            self::TRUST_TRUSTED => [
                'id' => self::TRUST_TRUSTED,
                'name' => 'Trusted',
                'icon' => 'fa-shield-halved',
                'color' => '#3b82f6',
                'emoji' => '🛡️',
                'description' => 'Admin-approved apps',
                'canBeSet' => true,
            ],
            self::TRUST_SANDBOXED => [
                'id' => self::TRUST_SANDBOXED,
                'name' => 'Sandboxed',
                'icon' => 'fa-lock',
                'color' => '#f59e0b',
                'emoji' => '🔒',
                'description' => 'Restricted capabilities (default)',
                'canBeSet' => true,
            ],
        ];
        
        return $levels[$level] ?? $levels[self::TRUST_SANDBOXED];
    }
    
    /**
     * Get all trust levels
     */
    public static function getAllTrustLevels(): array {
        return [
            self::getTrustLevelInfo(self::TRUST_CORE),
            self::getTrustLevelInfo(self::TRUST_VERIFIED),
            self::getTrustLevelInfo(self::TRUST_TRUSTED),
            self::getTrustLevelInfo(self::TRUST_SANDBOXED),
        ];
    }

    /**
     * Parse a trust level from int/string form.
     */
    public static function parseTrustLevel($value, bool $allowCore = true): ?int {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $level = (int)$value;
            $valid = $allowCore ? [self::TRUST_CORE, self::TRUST_VERIFIED, self::TRUST_TRUSTED, self::TRUST_SANDBOXED]
                : [self::TRUST_VERIFIED, self::TRUST_TRUSTED, self::TRUST_SANDBOXED];
            return in_array($level, $valid, true) ? $level : null;
        }

        if (!is_string($value)) {
            return null;
        }

        $normalized = strtolower(trim($value));
        $map = [
            'core' => self::TRUST_CORE,
            'verified' => self::TRUST_VERIFIED,
            'trusted' => self::TRUST_TRUSTED,
            'sandboxed' => self::TRUST_SANDBOXED,
        ];

        if (!array_key_exists($normalized, $map)) {
            return null;
        }

        $level = $map[$normalized];
        if (!$allowCore && $level === self::TRUST_CORE) {
            return null;
        }

        return $level;
    }
    
    /**
     * Get capabilities for a trust level
     */
    public static function forTrustLevel(int $level): array {
        $capabilities = [
            // Core - everything
            self::TRUST_CORE => ['*'],
            
            // Verified - almost everything except user management
            self::TRUST_VERIFIED => [
                self::AUTH_READ_USER, self::AUTH_CHECK_ROLES,
                self::AUDIT_WRITE, self::AUDIT_READ,
                self::DB_FULL, self::DB_APP_TABLES,
                self::FS_APP_DATA, self::FS_OTHER_APPS, self::FS_CORE,
                self::CONFIG_TARGETS, self::CONFIG_ENVIRONMENTS, self::CONFIG_SECRETS, self::CONFIG_MODIFY,
                self::EXEC_COMMANDS, self::EXEC_DOCKER, self::EXEC_SSH, self::EXEC_ANY_TARGET,
                self::GIT_PROVIDERS, self::GIT_TOKENS,
                self::UI_LAYOUT, self::UI_INJECT_GLOBAL,
                self::USERS_LIST,
            ],
            
            // Trusted - can execute and read config, but restricted
            self::TRUST_TRUSTED => [
                self::AUTH_READ_USER, self::AUTH_CHECK_ROLES,
                self::AUDIT_WRITE, self::AUDIT_READ,
                self::DB_APP_TABLES,
                self::FS_APP_DATA,
                self::CONFIG_TARGETS, self::CONFIG_ENVIRONMENTS, self::CONFIG_SECRETS,
                self::EXEC_COMMANDS, self::EXEC_DOCKER, self::EXEC_SSH,
                self::GIT_PROVIDERS,
                self::UI_LAYOUT,
            ],
            
            // Sandboxed - minimal, UI only
            self::TRUST_SANDBOXED => [
                self::AUTH_READ_USER, self::AUTH_CHECK_ROLES,
                self::AUDIT_WRITE,
                self::DB_APP_TABLES,
                self::FS_APP_DATA,
                self::UI_LAYOUT,
            ],
        ];
        
        return $capabilities[$level] ?? $capabilities[self::TRUST_SANDBOXED];
    }
    
    /**
     * Check if a trust level has a capability
     */
    public static function hasCapability(int $trustLevel, string $capability): bool {
        $capabilities = self::forTrustLevel($trustLevel);
        
        if (in_array('*', $capabilities)) {
            return true;
        }
        
        return in_array($capability, $capabilities);
    }
    
    /**
     * Get capability info
     */
    public static function getCapabilityInfo(string $capability): array {
        $info = [
            self::AUTH_READ_USER => ['name' => 'Read current user', 'category' => 'Authentication', 'risk' => 'low'],
            self::AUTH_CHECK_ROLES => ['name' => 'Check user roles', 'category' => 'Authentication', 'risk' => 'low'],
            self::AUDIT_WRITE => ['name' => 'Write audit logs', 'category' => 'Audit', 'risk' => 'low'],
            self::AUDIT_READ => ['name' => 'Read audit logs', 'category' => 'Audit', 'risk' => 'medium'],
            self::DB_FULL => ['name' => 'Full database access', 'category' => 'Database', 'risk' => 'high'],
            self::DB_APP_TABLES => ['name' => 'App-specific tables', 'category' => 'Database', 'risk' => 'low'],
            self::FS_APP_DATA => ['name' => 'App data directory', 'category' => 'File System', 'risk' => 'low'],
            self::FS_OTHER_APPS => ['name' => 'Other apps data', 'category' => 'File System', 'risk' => 'high'],
            self::FS_CORE => ['name' => 'Core Doki files', 'category' => 'File System', 'risk' => 'high'],
            self::CONFIG_TARGETS => ['name' => 'Read targets', 'category' => 'Configuration', 'risk' => 'medium'],
            self::CONFIG_ENVIRONMENTS => ['name' => 'Read environments', 'category' => 'Configuration', 'risk' => 'medium'],
            self::CONFIG_SECRETS => ['name' => 'Read secrets', 'category' => 'Configuration', 'risk' => 'high'],
            self::CONFIG_MODIFY => ['name' => 'Modify configuration', 'category' => 'Configuration', 'risk' => 'high'],
            self::EXEC_COMMANDS => ['name' => 'Execute commands', 'category' => 'Execution', 'risk' => 'high'],
            self::EXEC_DOCKER => ['name' => 'Docker execution', 'category' => 'Execution', 'risk' => 'high'],
            self::EXEC_SSH => ['name' => 'SSH execution', 'category' => 'Execution', 'risk' => 'high'],
            self::EXEC_ANY_TARGET => ['name' => 'Any target access', 'category' => 'Execution', 'risk' => 'high'],
            self::GIT_PROVIDERS => ['name' => 'Use Git providers', 'category' => 'Git', 'risk' => 'medium'],
            self::GIT_TOKENS => ['name' => 'Access Git tokens', 'category' => 'Git', 'risk' => 'high'],
            self::UI_LAYOUT => ['name' => 'Use Doki layout', 'category' => 'UI', 'risk' => 'low'],
            self::UI_INJECT_GLOBAL => ['name' => 'Inject global JS/CSS', 'category' => 'UI', 'risk' => 'high'],
            self::USERS_LIST => ['name' => 'List users', 'category' => 'Users', 'risk' => 'medium'],
            self::USERS_MANAGE => ['name' => 'Manage users', 'category' => 'Users', 'risk' => 'high'],
        ];
        
        return $info[$capability] ?? ['name' => $capability, 'category' => 'Unknown', 'risk' => 'high'];
    }
    
    /**
     * Get what capabilities a trust level grants vs denies based on manifest
     */
    public static function evaluateManifest(array $manifest, int $trustLevel): array {
        $required = $manifest['capabilities'] ?? [];
        $optional = $manifest['optionalCapabilities'] ?? [];
        
        $granted = [];
        $denied = [];
        $optionalGranted = [];
        $optionalDenied = [];
        
        foreach ($required as $cap) {
            if (self::hasCapability($trustLevel, $cap)) {
                $granted[] = $cap;
            } else {
                $denied[] = $cap;
            }
        }
        
        foreach ($optional as $cap) {
            if (self::hasCapability($trustLevel, $cap)) {
                $optionalGranted[] = $cap;
            } else {
                $optionalDenied[] = $cap;
            }
        }
        
        return [
            'granted' => $granted,
            'denied' => $denied,
            'optionalGranted' => $optionalGranted,
            'optionalDenied' => $optionalDenied,
            'willWork' => empty($denied), // App will work if all required are granted
        ];
    }

    /**
     * Build an install-time trust profile for an app manifest.
     * Supports optional manifest overrides:
     * - minimumTrustLevel
     * - recommendedTrustLevel
     */
    public static function getManifestTrustProfile(array $manifest, ?int $sourceDefaultTrust = null): array {
        $evaluations = [];
        $derivedMinimum = null;

        foreach ([self::TRUST_SANDBOXED, self::TRUST_TRUSTED, self::TRUST_VERIFIED] as $level) {
            $evaluation = self::evaluateManifest($manifest, $level);
            $evaluations[$level] = [
                'level' => $level,
                'levelInfo' => self::getTrustLevelInfo($level),
                'evaluation' => $evaluation,
            ];

            if ($derivedMinimum === null && $evaluation['willWork']) {
                $derivedMinimum = $level;
            }
        }

        $minimum = self::parseTrustLevel($manifest['minimumTrustLevel'] ?? null, false) ?? $derivedMinimum;
        $recommended = self::parseTrustLevel($manifest['recommendedTrustLevel'] ?? null, false) ?? $minimum;
        $sourceDefault = self::parseTrustLevel($sourceDefaultTrust, false);

        if ($minimum !== null && $recommended !== null && $recommended > $minimum) {
            $recommended = $minimum;
        }

        $installDefault = $recommended ?? $sourceDefault;
        if ($sourceDefault !== null && $installDefault !== null) {
            // Lower number = more privilege. Respect stricter app/source requirement.
            $installDefault = min($installDefault, $sourceDefault);
        }
        if ($minimum !== null && $installDefault !== null && $installDefault > $minimum) {
            $installDefault = $minimum;
        }

        $reason = null;
        if ($minimum !== null) {
            $deniedAtSandbox = $evaluations[self::TRUST_SANDBOXED]['evaluation']['denied'] ?? [];
            if ($minimum === self::TRUST_SANDBOXED) {
                $reason = 'Sandboxed is sufficient for this app.';
            } else {
                $requiredInfo = self::getTrustLevelInfo($minimum);
                $reason = $requiredInfo['name'] . ' is the minimum level that grants the app\'s required capabilities.';
                if (!empty($deniedAtSandbox)) {
                    $reason .= ' Sandboxed would deny: ' . implode(', ', $deniedAtSandbox) . '.';
                }
            }
        } else {
            $reason = 'No installable trust level satisfies the app\'s declared required capabilities.';
        }

        return [
            'minimumTrustLevel' => $minimum,
            'minimumTrustInfo' => $minimum !== null ? self::getTrustLevelInfo($minimum) : null,
            'recommendedTrustLevel' => $recommended,
            'recommendedTrustInfo' => $recommended !== null ? self::getTrustLevelInfo($recommended) : null,
            'installDefaultTrustLevel' => $installDefault,
            'installDefaultTrustInfo' => $installDefault !== null ? self::getTrustLevelInfo($installDefault) : null,
            'sourceDefaultTrustLevel' => $sourceDefault,
            'sourceDefaultTrustInfo' => $sourceDefault !== null ? self::getTrustLevelInfo($sourceDefault) : null,
            'evaluations' => $evaluations,
            'reason' => $reason,
        ];
    }
}

/**
 * Exception for capability violations
 */
class AppCapabilityException extends Exception {
    private string $capability;
    private string $appId;
    
    public function __construct(string $message, string $capability = '', string $appId = '') {
        parent::__construct($message);
        $this->capability = $capability;
        $this->appId = $appId;
    }
    
    public function getCapability(): string {
        return $this->capability;
    }
    
    public function getAppId(): string {
        return $this->appId;
    }
}
