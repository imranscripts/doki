<?php
/**
 * Database.php - SQLite Database Connection & Schema Management
 * 
 * Part of Doki v3 Architecture - Batch 1: Auth & UI Foundation
 * Updated Batch 13: Doki Sources (Unified Git Management)
 */

class Database {
    private static ?PDO $instance = null;
    private const DB_PATH = __DIR__ . '/../data/doki.db';
    private const SCHEMA_VERSION = 17; // Bumped for Workflows Studio AI apply/revert versions

    public static function getInstance(): PDO {
        if (self::$instance === null) {
            // Ensure data directory exists
            $dataDir = dirname(self::DB_PATH);
            if (!is_dir($dataDir)) {
                mkdir($dataDir, 0755, true);
            }

            self::$instance = new PDO(
                'sqlite:' . self::DB_PATH,
                null,
                null,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );

            // Wait up to 5s for locks instead of failing immediately
            self::$instance->exec('PRAGMA busy_timeout = 5000');
            // WAL mode allows concurrent reads during writes (critical for Apache prefork)
            self::$instance->exec('PRAGMA journal_mode = WAL');
            // Enable foreign keys
            self::$instance->exec('PRAGMA foreign_keys = ON');

            // Initialize schema if needed
            self::initializeSchema();
        }

        return self::$instance;
    }

    private static function initializeSchema(): void {
        $db = self::$instance;

        // Check if schema_version table exists
        $result = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='schema_version'");
        $hasSchema = $result->fetch();
        $result->closeCursor();

        if (!$hasSchema) {
            self::createTables();
        } else {
            // Check for schema migrations
            self::runMigrations();
        }
    }
    
    /**
     * Run schema migrations
     */
    private static function runMigrations(): void {
        $db = self::$instance;
        
        // Get current version
        $stmt = $db->query("SELECT MAX(version) as version FROM schema_version");
        $row = $stmt->fetch();
        $stmt->closeCursor();
        $currentVersion = (int)($row['version'] ?? 1);

        $migrations = [
            2 => 'migrateToV2',
            3 => 'migrateToV3',
            4 => 'migrateToV4',
            5 => 'migrateToV5',
            6 => 'migrateToV6',
            7 => 'migrateToV7',
            8 => 'migrateToV8',
            9 => 'migrateToV9',
            10 => 'migrateToV10',
            11 => 'migrateToV11',
            12 => 'migrateToV12',
            13 => 'migrateToV13',
            14 => 'migrateToV14',
            15 => 'migrateToV15',
            16 => 'migrateToV16',
            17 => 'migrateToV17',
        ];

        foreach ($migrations as $targetVersion => $methodName) {
            if ($currentVersion >= $targetVersion) {
                continue;
            }

            self::backupDatabaseBeforeMigration($db, $currentVersion, $targetVersion);
            self::{$methodName}();
            $currentVersion = $targetVersion;
        }
    }
    
    /**
     * Migration to v2: Add Doki Sources tables (Batch 13)
     */
    private static function migrateToV2(): void {
        $db = self::$instance;
        
        // Git Providers table - centralized credential storage
        $db->exec("
            CREATE TABLE IF NOT EXISTS git_providers (
                id TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                type TEXT NOT NULL CHECK (type IN ('github', 'gitlab', 'bitbucket', 'custom')),
                api_url TEXT,
                token TEXT,
                token_secret_id TEXT,
                username TEXT,
                description TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                created_by TEXT,
                updated_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_by TEXT,
                last_tested_at TEXT,
                last_test_success INTEGER,
                FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
            )
        ");
        
        // Repositories table - unified repo management
        $db->exec("
            CREATE TABLE IF NOT EXISTS repositories (
                id TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                url TEXT NOT NULL,
                type TEXT NOT NULL CHECK (type IN ('apps', 'templates', 'playwright-tests', 'mixed')),
                provider_id TEXT,
                branch TEXT NOT NULL DEFAULT 'main',
                pinned_ref TEXT,
                pin_type TEXT CHECK (pin_type IN ('branch', 'tag', 'commit')),
                auto_sync INTEGER NOT NULL DEFAULT 0,
                sync_interval INTEGER DEFAULT 3600,
                default_trust INTEGER DEFAULT 3,
                description TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                created_by TEXT,
                updated_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_by TEXT,
                last_sync_at TEXT,
                last_sync_status TEXT,
                last_sync_message TEXT,
                FOREIGN KEY (provider_id) REFERENCES git_providers(id) ON DELETE SET NULL,
                FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
            )
        ");
        
        // Indexes for git_providers
        $db->exec("CREATE INDEX IF NOT EXISTS idx_providers_type ON git_providers(type)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_providers_name ON git_providers(name)");
        
        // Indexes for repositories
        $db->exec("CREATE INDEX IF NOT EXISTS idx_repos_type ON repositories(type)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_repos_provider ON repositories(provider_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_repos_auto_sync ON repositories(auto_sync)");
        
        // Record migration
        $db->exec("INSERT INTO schema_version (version) VALUES (2)");
    }

    /**
     * Migration to v3: Secrets hardening (metadata + git provider token_secret_id)
     */
    private static function migrateToV3(): void {
        $db = self::$instance;

        // Add token_secret_id to git_providers if missing
        $columns = $db->query("PRAGMA table_info(git_providers)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('token_secret_id', $columns, true)) {
            $db->exec("ALTER TABLE git_providers ADD COLUMN token_secret_id TEXT");
        }

        // Secret metadata table
        $db->exec("
            CREATE TABLE IF NOT EXISTS secret_metadata (
                secret_id TEXT PRIMARY KEY,
                version INTEGER NOT NULL DEFAULT 1,
                updated_at TEXT NOT NULL DEFAULT (datetime('now')),
                last_rotated_at TEXT,
                expires_at TEXT,
                rotation_interval_days INTEGER,
                updated_by TEXT,
                FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
            )
        ");

        $db->exec("INSERT INTO schema_version (version) VALUES (3)");
    }

    /**
     * Migration to v4: Granular access control (groups & per-user permissions)
     */
    private static function migrateToV4(): void {
        $db = self::$instance;

        // Permission groups
        $db->exec("
            CREATE TABLE IF NOT EXISTS permission_groups (
                id TEXT PRIMARY KEY,
                name TEXT UNIQUE NOT NULL,
                description TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now')),
                created_by TEXT,
                FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
            )
        ");

        // Group membership (many-to-many)
        $db->exec("
            CREATE TABLE IF NOT EXISTS group_members (
                group_id TEXT NOT NULL,
                user_id TEXT NOT NULL,
                added_at TEXT NOT NULL DEFAULT (datetime('now')),
                added_by TEXT,
                PRIMARY KEY (group_id, user_id),
                FOREIGN KEY (group_id) REFERENCES permission_groups(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE SET NULL
            )
        ");

        // Group deny rules (groups can only deny, since default is allow)
        $db->exec("
            CREATE TABLE IF NOT EXISTS group_permissions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                group_id TEXT NOT NULL,
                resource_type TEXT NOT NULL CHECK (resource_type IN ('app', 'command', 'module')),
                resource_id TEXT NOT NULL,
                action TEXT NOT NULL DEFAULT 'deny' CHECK (action IN ('deny')),
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                created_by TEXT,
                UNIQUE(group_id, resource_type, resource_id),
                FOREIGN KEY (group_id) REFERENCES permission_groups(id) ON DELETE CASCADE,
                FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
            )
        ");

        // Per-user permission overrides (can deny OR allow to override group denials)
        $db->exec("
            CREATE TABLE IF NOT EXISTS user_permissions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id TEXT NOT NULL,
                resource_type TEXT NOT NULL CHECK (resource_type IN ('app', 'command', 'module')),
                resource_id TEXT NOT NULL,
                action TEXT NOT NULL CHECK (action IN ('deny', 'allow')),
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                created_by TEXT,
                UNIQUE(user_id, resource_type, resource_id),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
            )
        ");

        // Indexes
        $db->exec("CREATE INDEX IF NOT EXISTS idx_group_members_user ON group_members(user_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_group_members_group ON group_members(group_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_group_perms_group ON group_permissions(group_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_group_perms_resource ON group_permissions(resource_type, resource_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_user_perms_user ON user_permissions(user_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_user_perms_resource ON user_permissions(resource_type, resource_id)");

        // Record migration
        $db->exec("INSERT INTO schema_version (version) VALUES (4)");
    }

    /**
     * Migration to v5: Group whitelist/blacklist modes
     *
     * Adds a 'mode' column to permission_groups ('blacklist' or 'whitelist').
     * The group's mode determines how its rules are interpreted — no need to
     * change the group_permissions CHECK constraint. All group_permissions rows
     * store action='deny'; the group mode gives them meaning.
     */
    private static function migrateToV5(): void {
        $db = self::$instance;

        // Add mode column to permission_groups (idempotent)
        $columns = $db->query("PRAGMA table_info(permission_groups)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('mode', $columns, true)) {
            $db->exec("ALTER TABLE permission_groups ADD COLUMN mode TEXT NOT NULL DEFAULT 'blacklist' CHECK (mode IN ('blacklist', 'whitelist'))");
        }

        // Note: group_permissions_new may exist as a leftover from prior failed
        // migration attempts. It's harmless — leave it rather than risk a DDL
        // lock conflict with concurrent Apache workers.

        $db->exec("INSERT INTO schema_version (version) VALUES (5)");
    }

    /**
     * Migration to v6: App Studio workspaces
     */
    private static function migrateToV6(): void {
        $db = self::$instance;

        $db->exec("
            CREATE TABLE IF NOT EXISTS app_workspaces (
                id TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                description TEXT,
                source_type TEXT NOT NULL CHECK (source_type IN ('starter', 'repository', 'local')),
                provider_id TEXT,
                repository_id TEXT,
                repo_url TEXT,
                default_branch TEXT,
                current_branch TEXT,
                app_id TEXT,
                app_path TEXT,
                playwright_path TEXT,
                workspace_root TEXT NOT NULL,
                preview_mode TEXT NOT NULL DEFAULT 'private',
                preview_status TEXT NOT NULL DEFAULT 'idle',
                linked_installed_app_id TEXT,
                trust_mode TEXT NOT NULL DEFAULT 'sandboxed',
                last_test_run_at TEXT,
                last_preview_at TEXT,
                last_sync_at TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                created_by TEXT,
                updated_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_by TEXT,
                FOREIGN KEY (provider_id) REFERENCES git_providers(id) ON DELETE SET NULL,
                FOREIGN KEY (repository_id) REFERENCES repositories(id) ON DELETE SET NULL,
                FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
            )
        ");

        $db->exec("CREATE INDEX IF NOT EXISTS idx_workspaces_source_type ON app_workspaces(source_type)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_workspaces_repository ON app_workspaces(repository_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_workspaces_updated_at ON app_workspaces(updated_at)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_workspaces_created_by ON app_workspaces(created_by)");

        $db->exec("INSERT INTO schema_version (version) VALUES (6)");
    }

    /**
     * Migration to v7: App Studio AI providers
     */
    private static function migrateToV7(): void {
        $db = self::$instance;

        $db->exec("
            CREATE TABLE IF NOT EXISTS ai_providers (
                id TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                type TEXT NOT NULL CHECK (type IN ('ollama', 'openai-compatible')),
                base_url TEXT NOT NULL,
                api_key_secret_id TEXT,
                default_model TEXT,
                metadata_json TEXT,
                last_tested_at TEXT,
                last_test_success INTEGER,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                created_by TEXT,
                updated_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_by TEXT,
                FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
            )
        ");

        $db->exec("CREATE INDEX IF NOT EXISTS idx_ai_providers_type ON ai_providers(type)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_ai_providers_name ON ai_providers(name)");

        $db->exec("INSERT INTO schema_version (version) VALUES (7)");
    }

    /**
     * Migration to v8: App Studio AI async jobs
     */
    private static function migrateToV8(): void {
        $db = self::$instance;

        $db->exec("
            CREATE TABLE IF NOT EXISTS studio_ai_jobs (
                id TEXT PRIMARY KEY,
                kind TEXT NOT NULL CHECK (kind IN ('chat', 'create_workspace')),
                status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'running', 'completed', 'failed')),
                workspace_id TEXT,
                provider_id TEXT NOT NULL,
                model TEXT,
                input_json TEXT NOT NULL,
                result_json TEXT,
                error TEXT,
                started_at TEXT,
                completed_at TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now')),
                created_by TEXT,
                created_by_username TEXT,
                updated_by TEXT,
                FOREIGN KEY (workspace_id) REFERENCES app_workspaces(id) ON DELETE SET NULL,
                FOREIGN KEY (provider_id) REFERENCES ai_providers(id) ON DELETE CASCADE,
                FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
            )
        ");

        $db->exec("CREATE INDEX IF NOT EXISTS idx_studio_ai_jobs_status ON studio_ai_jobs(status)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_studio_ai_jobs_created_by ON studio_ai_jobs(created_by)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_studio_ai_jobs_workspace ON studio_ai_jobs(workspace_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_studio_ai_jobs_created_at ON studio_ai_jobs(created_at)");

        $db->exec("INSERT INTO schema_version (version) VALUES (8)");
    }

    /**
     * Migration to v9: Studio marketplace releases
     */
    private static function migrateToV9(): void {
        $db = self::$instance;

        $db->exec("
            CREATE TABLE IF NOT EXISTS studio_marketplace_releases (
                id TEXT PRIMARY KEY,
                workspace_id TEXT,
                app_id TEXT NOT NULL,
                app_path TEXT NOT NULL,
                version TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'published', 'archived')),
                artifact_path TEXT NOT NULL,
                manifest_json TEXT NOT NULL,
                release_notes TEXT,
                published_at TEXT,
                published_by TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                created_by TEXT,
                updated_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_by TEXT,
                FOREIGN KEY (workspace_id) REFERENCES app_workspaces(id) ON DELETE SET NULL,
                FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
                FOREIGN KEY (published_by) REFERENCES users(id) ON DELETE SET NULL
            )
        ");

        $db->exec("CREATE INDEX IF NOT EXISTS idx_studio_marketplace_releases_app ON studio_marketplace_releases(app_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_studio_marketplace_releases_workspace ON studio_marketplace_releases(workspace_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_studio_marketplace_releases_status ON studio_marketplace_releases(status)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_studio_marketplace_releases_published_at ON studio_marketplace_releases(published_at)");

        $db->exec("INSERT INTO schema_version (version) VALUES (9)");
    }

    /**
     * Migration to v10: Studio AI default providers
     */
    private static function migrateToV10(): void {
        $db = self::$instance;

        $columns = $db->query("PRAGMA table_info(ai_providers)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('is_default', $columns, true)) {
            $db->exec("ALTER TABLE ai_providers ADD COLUMN is_default INTEGER NOT NULL DEFAULT 0");
        }

        $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_ai_providers_single_default ON ai_providers(is_default) WHERE is_default = 1");

        $db->exec("INSERT INTO schema_version (version) VALUES (10)");
    }

    /**
     * Migration to v11: AI admin context blocks
     */
    private static function migrateToV11(): void {
        $db = self::$instance;

        $db->exec("
            CREATE TABLE IF NOT EXISTS ai_context_blocks (
                id TEXT PRIMARY KEY,
                scope TEXT NOT NULL CHECK (scope IN ('apps', 'commands')),
                enabled INTEGER NOT NULL DEFAULT 1,
                content TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                created_by TEXT,
                updated_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_by TEXT,
                FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
            )
        ");

        $db->exec("CREATE INDEX IF NOT EXISTS idx_ai_context_blocks_scope ON ai_context_blocks(scope)");

        $db->exec("INSERT INTO schema_version (version) VALUES (11)");
    }

    /**
     * Migration to v12: Custom AI context block metadata
     */
    private static function migrateToV12(): void {
        $db = self::$instance;

        $columns = $db->query("PRAGMA table_info(ai_context_blocks)")->fetchAll(PDO::FETCH_COLUMN, 1);

        if (!in_array('block_key', $columns, true)) {
            $db->exec("ALTER TABLE ai_context_blocks ADD COLUMN block_key TEXT");
        }
        if (!in_array('title', $columns, true)) {
            $db->exec("ALTER TABLE ai_context_blocks ADD COLUMN title TEXT");
        }
        if (!in_array('description', $columns, true)) {
            $db->exec("ALTER TABLE ai_context_blocks ADD COLUMN description TEXT");
        }
        if (!in_array('editable', $columns, true)) {
            $db->exec("ALTER TABLE ai_context_blocks ADD COLUMN editable INTEGER NOT NULL DEFAULT 1");
        }
        if (!in_array('dynamic', $columns, true)) {
            $db->exec("ALTER TABLE ai_context_blocks ADD COLUMN dynamic INTEGER NOT NULL DEFAULT 0");
        }
        if (!in_array('sort_order', $columns, true)) {
            $db->exec("ALTER TABLE ai_context_blocks ADD COLUMN sort_order INTEGER NOT NULL DEFAULT 0");
        }
        if (!in_array('is_system', $columns, true)) {
            $db->exec("ALTER TABLE ai_context_blocks ADD COLUMN is_system INTEGER NOT NULL DEFAULT 0");
        }

        $db->exec("CREATE INDEX IF NOT EXISTS idx_ai_context_blocks_scope ON ai_context_blocks(scope)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_ai_context_blocks_scope_sort ON ai_context_blocks(scope, sort_order)");
        $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_ai_context_blocks_scope_key ON ai_context_blocks(scope, block_key) WHERE block_key IS NOT NULL");

        $db->exec("INSERT INTO schema_version (version) VALUES (12)");
    }

    /**
     * Migration to v13: Doki-wide system settings
     */
    private static function migrateToV13(): void {
        $db = self::$instance;

        $db->exec("
            CREATE TABLE IF NOT EXISTS system_settings (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL,
                updated_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_by TEXT,
                FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
            )
        ");

        $db->exec("INSERT INTO schema_version (version) VALUES (13)");
    }

    /**
     * Migration to v14: Onboarding/bootstrap install state and recovery metadata
     */
    private static function migrateToV14(): void {
        $db = self::$instance;

        $db->exec("
            CREATE TABLE IF NOT EXISTS install_state (
                id INTEGER PRIMARY KEY CHECK (id = 1),
                state TEXT NOT NULL CHECK (state IN ('fresh', 'onboarding', 'complete')),
                current_step TEXT,
                setup_ran_at TEXT,
                completed_at TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS bootstrap_recovery (
                id INTEGER PRIMARY KEY CHECK (id = 1),
                user_id TEXT NOT NULL,
                recovery_code TEXT NOT NULL,
                issued_at TEXT NOT NULL DEFAULT (datetime('now')),
                last_rotated_at TEXT NOT NULL DEFAULT (datetime('now')),
                last_used_at TEXT,
                failed_attempts INTEGER NOT NULL DEFAULT 0,
                last_failed_at TEXT,
                lockout_until TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now')),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )
        ");

        self::ensureInstallStateRow();

        $db->exec("INSERT INTO schema_version (version) VALUES (14)");
    }

    /**
     * Migration to v15: Workflows Studio projects, versions, and custom templates
     */
    private static function migrateToV15(): void {
        $db = self::$instance;

        self::createWorkflowsStudioTables($db);

        $db->exec("INSERT INTO schema_version (version) VALUES (15)");
    }

    /**
     * Migration to v16: Workflows Studio AI history persistence
     */
    private static function migrateToV16(): void {
        $db = self::$instance;

        self::createWorkflowsStudioAiHistoryTables($db);

        $db->exec("INSERT INTO schema_version (version) VALUES (16)");
    }

    /**
     * Migration to v17: Allow AI-specific Workflow Studio version sources
     */
    private static function migrateToV17(): void {
        $db = self::$instance;

        self::recreateWorkflowsStudioVersionTables($db);

        $db->exec("INSERT INTO schema_version (version) VALUES (17)");
    }

    private static function backupDatabaseBeforeMigration(PDO $db, int $fromVersion, int $toVersion): string {
        $databasePath = self::getSqliteDatabasePath($db);
        if ($databasePath === null || $databasePath === '') {
            throw new RuntimeException('Unable to determine the SQLite database path before migration');
        }

        $backupDir = dirname($databasePath) . '/db-backups';
        if (!is_dir($backupDir) && !@mkdir($backupDir, 0755, true) && !is_dir($backupDir)) {
            throw new RuntimeException('Unable to create database backup directory: ' . $backupDir);
        }

        $baseName = pathinfo($databasePath, PATHINFO_FILENAME);
        $timestamp = date('Ymd_His');
        $backupPath = $backupDir . '/' . $baseName . '.pre-v' . $toVersion . '.' . $timestamp . '.' . substr(bin2hex(random_bytes(4)), 0, 8) . '.db';
        $quotedBackupPath = self::quoteSqliteString($backupPath);

        $backupConnection = null;
        try {
            $backupConnection = new PDO(
                'sqlite:' . $databasePath,
                null,
                null,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
            $backupConnection->exec('PRAGMA busy_timeout = 5000');
            $backupConnection->exec("VACUUM main INTO '{$quotedBackupPath}'");
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Failed to create database backup before migrating from v' . $fromVersion . ' to v' . $toVersion . ': ' . $e->getMessage(),
                0,
                $e
            );
        } finally {
            $backupConnection = null;
        }

        error_log(sprintf(
            'Created Doki database backup before migration v%s->v%s: %s',
            $fromVersion,
            $toVersion,
            $backupPath
        ));

        return $backupPath;
    }

    private static function createTables(): void {
        $db = self::$instance;

        // Schema version tracking
        $db->exec("
            CREATE TABLE schema_version (
                version INTEGER PRIMARY KEY,
                applied_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        ");

        // Users table
        $db->exec("
            CREATE TABLE users (
                id TEXT PRIMARY KEY,
                username TEXT UNIQUE NOT NULL,
                email TEXT UNIQUE,
                name TEXT NOT NULL,
                password_hash TEXT NOT NULL,
                role TEXT NOT NULL CHECK (role IN ('super-admin', 'admin', 'user')),
                active INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now')),
                last_login TEXT
            )
        ");

        // Sessions table for secure session management
        $db->exec("
            CREATE TABLE sessions (
                id TEXT PRIMARY KEY,
                user_id TEXT NOT NULL,
                token_hash TEXT NOT NULL,
                ip_address TEXT,
                user_agent TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                expires_at TEXT NOT NULL,
                last_activity TEXT NOT NULL DEFAULT (datetime('now')),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )
        ");

        // Audit log table
        $db->exec("
            CREATE TABLE audit_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id TEXT,
                username TEXT,
                action TEXT NOT NULL,
                resource_type TEXT,
                resource_id TEXT,
                details TEXT,
                ip_address TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
            )
        ");

        // Git Providers table - centralized credential storage
        $db->exec("
            CREATE TABLE IF NOT EXISTS git_providers (
                id TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                type TEXT NOT NULL CHECK (type IN ('github', 'gitlab', 'bitbucket', 'custom')),
                api_url TEXT,
                token TEXT,
                token_secret_id TEXT,
                username TEXT,
                description TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                created_by TEXT,
                updated_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_by TEXT,
                last_tested_at TEXT,
                last_test_success INTEGER,
                FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
            )
        ");

        // Repositories table - unified repo management
        $db->exec("
            CREATE TABLE IF NOT EXISTS repositories (
                id TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                url TEXT NOT NULL,
                type TEXT NOT NULL CHECK (type IN ('apps', 'templates', 'playwright-tests', 'mixed')),
                provider_id TEXT,
                branch TEXT NOT NULL DEFAULT 'main',
                pinned_ref TEXT,
                pin_type TEXT CHECK (pin_type IN ('branch', 'tag', 'commit')),
                auto_sync INTEGER NOT NULL DEFAULT 0,
                sync_interval INTEGER DEFAULT 3600,
                default_trust INTEGER DEFAULT 3,
                description TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                created_by TEXT,
                updated_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_by TEXT,
                last_sync_at TEXT,
                last_sync_status TEXT,
                last_sync_message TEXT,
                FOREIGN KEY (provider_id) REFERENCES git_providers(id) ON DELETE SET NULL,
                FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
            )
        ");

        // Secret metadata table
        $db->exec("
            CREATE TABLE IF NOT EXISTS secret_metadata (
                secret_id TEXT PRIMARY KEY,
                version INTEGER NOT NULL DEFAULT 1,
                updated_at TEXT NOT NULL DEFAULT (datetime('now')),
                last_rotated_at TEXT,
                expires_at TEXT,
                rotation_interval_days INTEGER,
                updated_by TEXT,
                FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
            )
        ");

        // Permission groups
        $db->exec("
            CREATE TABLE IF NOT EXISTS permission_groups (
                id TEXT PRIMARY KEY,
                name TEXT UNIQUE NOT NULL,
                description TEXT,
                mode TEXT NOT NULL DEFAULT 'blacklist' CHECK (mode IN ('blacklist', 'whitelist')),
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now')),
                created_by TEXT,
                FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
            )
        ");

        // Group membership
        $db->exec("
            CREATE TABLE IF NOT EXISTS group_members (
                group_id TEXT NOT NULL,
                user_id TEXT NOT NULL,
                added_at TEXT NOT NULL DEFAULT (datetime('now')),
                added_by TEXT,
                PRIMARY KEY (group_id, user_id),
                FOREIGN KEY (group_id) REFERENCES permission_groups(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE SET NULL
            )
        ");

        // Group permission rules (action is always 'deny'; the group's mode determines meaning)
        $db->exec("
            CREATE TABLE IF NOT EXISTS group_permissions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                group_id TEXT NOT NULL,
                resource_type TEXT NOT NULL CHECK (resource_type IN ('app', 'command', 'module')),
                resource_id TEXT NOT NULL,
                action TEXT NOT NULL DEFAULT 'deny',
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                created_by TEXT,
                UNIQUE(group_id, resource_type, resource_id),
                FOREIGN KEY (group_id) REFERENCES permission_groups(id) ON DELETE CASCADE,
                FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
            )
        ");

        // Per-user permission overrides
        $db->exec("
            CREATE TABLE IF NOT EXISTS user_permissions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id TEXT NOT NULL,
                resource_type TEXT NOT NULL CHECK (resource_type IN ('app', 'command', 'module')),
                resource_id TEXT NOT NULL,
                action TEXT NOT NULL CHECK (action IN ('deny', 'allow')),
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                created_by TEXT,
                UNIQUE(user_id, resource_type, resource_id),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
            )
        ");

        // App Studio workspaces
        $db->exec("
            CREATE TABLE IF NOT EXISTS app_workspaces (
                id TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                description TEXT,
                source_type TEXT NOT NULL CHECK (source_type IN ('starter', 'repository', 'local')),
                provider_id TEXT,
                repository_id TEXT,
                repo_url TEXT,
                default_branch TEXT,
                current_branch TEXT,
                app_id TEXT,
                app_path TEXT,
                playwright_path TEXT,
                workspace_root TEXT NOT NULL,
                preview_mode TEXT NOT NULL DEFAULT 'private',
                preview_status TEXT NOT NULL DEFAULT 'idle',
                linked_installed_app_id TEXT,
                trust_mode TEXT NOT NULL DEFAULT 'sandboxed',
                last_test_run_at TEXT,
                last_preview_at TEXT,
                last_sync_at TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                created_by TEXT,
                updated_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_by TEXT,
                FOREIGN KEY (provider_id) REFERENCES git_providers(id) ON DELETE SET NULL,
                FOREIGN KEY (repository_id) REFERENCES repositories(id) ON DELETE SET NULL,
                FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
            )
        ");

        // App Studio AI providers
        $db->exec("
            CREATE TABLE IF NOT EXISTS ai_providers (
                id TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                type TEXT NOT NULL CHECK (type IN ('ollama', 'openai-compatible')),
                base_url TEXT NOT NULL,
                api_key_secret_id TEXT,
                default_model TEXT,
                is_default INTEGER NOT NULL DEFAULT 0,
                metadata_json TEXT,
                last_tested_at TEXT,
                last_test_success INTEGER,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                created_by TEXT,
                updated_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_by TEXT,
                FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
            )
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS ai_context_blocks (
                id TEXT PRIMARY KEY,
                scope TEXT NOT NULL CHECK (scope IN ('apps', 'commands')),
                block_key TEXT,
                title TEXT,
                description TEXT,
                editable INTEGER NOT NULL DEFAULT 1,
                dynamic INTEGER NOT NULL DEFAULT 0,
                enabled INTEGER NOT NULL DEFAULT 1,
                content TEXT,
                sort_order INTEGER NOT NULL DEFAULT 0,
                is_system INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                created_by TEXT,
                updated_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_by TEXT,
                FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
            )
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS system_settings (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL,
                updated_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_by TEXT,
                FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
            )
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS install_state (
                id INTEGER PRIMARY KEY CHECK (id = 1),
                state TEXT NOT NULL CHECK (state IN ('fresh', 'onboarding', 'complete')),
                current_step TEXT,
                setup_ran_at TEXT,
                completed_at TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS bootstrap_recovery (
                id INTEGER PRIMARY KEY CHECK (id = 1),
                user_id TEXT NOT NULL,
                recovery_code TEXT NOT NULL,
                issued_at TEXT NOT NULL DEFAULT (datetime('now')),
                last_rotated_at TEXT NOT NULL DEFAULT (datetime('now')),
                last_used_at TEXT,
                failed_attempts INTEGER NOT NULL DEFAULT 0,
                last_failed_at TEXT,
                lockout_until TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now')),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )
        ");

        self::createWorkflowsStudioTables($db);
        self::createWorkflowsStudioAiHistoryTables($db);

        // App Studio AI async jobs
        $db->exec("
            CREATE TABLE IF NOT EXISTS studio_ai_jobs (
                id TEXT PRIMARY KEY,
                kind TEXT NOT NULL CHECK (kind IN ('chat', 'create_workspace')),
                status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'running', 'completed', 'failed')),
                workspace_id TEXT,
                provider_id TEXT NOT NULL,
                model TEXT,
                input_json TEXT NOT NULL,
                result_json TEXT,
                error TEXT,
                started_at TEXT,
                completed_at TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now')),
                created_by TEXT,
                created_by_username TEXT,
                updated_by TEXT,
                FOREIGN KEY (workspace_id) REFERENCES app_workspaces(id) ON DELETE SET NULL,
                FOREIGN KEY (provider_id) REFERENCES ai_providers(id) ON DELETE CASCADE,
                FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
            )
        ");

        // App Studio marketplace releases
        $db->exec("
            CREATE TABLE IF NOT EXISTS studio_marketplace_releases (
                id TEXT PRIMARY KEY,
                workspace_id TEXT,
                app_id TEXT NOT NULL,
                app_path TEXT NOT NULL,
                version TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'published', 'archived')),
                artifact_path TEXT NOT NULL,
                manifest_json TEXT NOT NULL,
                release_notes TEXT,
                published_at TEXT,
                published_by TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                created_by TEXT,
                updated_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_by TEXT,
                FOREIGN KEY (workspace_id) REFERENCES app_workspaces(id) ON DELETE SET NULL,
                FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
                FOREIGN KEY (published_by) REFERENCES users(id) ON DELETE SET NULL
            )
        ");

        // Indexes
        $db->exec("CREATE INDEX idx_sessions_user_id ON sessions(user_id)");
        $db->exec("CREATE INDEX idx_sessions_expires ON sessions(expires_at)");
        $db->exec("CREATE INDEX idx_audit_user ON audit_log(user_id)");
        $db->exec("CREATE INDEX idx_audit_action ON audit_log(action)");
        $db->exec("CREATE INDEX idx_audit_created ON audit_log(created_at)");

        // Indexes for git_providers
        $db->exec("CREATE INDEX IF NOT EXISTS idx_providers_type ON git_providers(type)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_providers_name ON git_providers(name)");

        // Indexes for repositories
        $db->exec("CREATE INDEX IF NOT EXISTS idx_repos_type ON repositories(type)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_repos_provider ON repositories(provider_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_repos_auto_sync ON repositories(auto_sync)");

        // Indexes for permissions
        $db->exec("CREATE INDEX IF NOT EXISTS idx_group_members_user ON group_members(user_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_group_members_group ON group_members(group_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_group_perms_group ON group_permissions(group_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_group_perms_resource ON group_permissions(resource_type, resource_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_user_perms_user ON user_permissions(user_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_user_perms_resource ON user_permissions(resource_type, resource_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_workspaces_source_type ON app_workspaces(source_type)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_workspaces_repository ON app_workspaces(repository_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_workspaces_updated_at ON app_workspaces(updated_at)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_workspaces_created_by ON app_workspaces(created_by)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_ai_providers_type ON ai_providers(type)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_ai_providers_name ON ai_providers(name)");
        $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_ai_providers_single_default ON ai_providers(is_default) WHERE is_default = 1");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_ai_context_blocks_scope ON ai_context_blocks(scope)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_ai_context_blocks_scope_sort ON ai_context_blocks(scope, sort_order)");
        $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_ai_context_blocks_scope_key ON ai_context_blocks(scope, block_key) WHERE block_key IS NOT NULL");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_studio_ai_jobs_status ON studio_ai_jobs(status)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_studio_ai_jobs_created_by ON studio_ai_jobs(created_by)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_studio_ai_jobs_workspace ON studio_ai_jobs(workspace_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_studio_ai_jobs_created_at ON studio_ai_jobs(created_at)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_studio_marketplace_releases_app ON studio_marketplace_releases(app_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_studio_marketplace_releases_workspace ON studio_marketplace_releases(workspace_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_studio_marketplace_releases_status ON studio_marketplace_releases(status)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_studio_marketplace_releases_published_at ON studio_marketplace_releases(published_at)");

        self::ensureInstallStateRow();

        // Record schema version
        $db->exec("INSERT INTO schema_version (version) VALUES (" . self::SCHEMA_VERSION . ")");
    }

    private static function ensureInstallStateRow(): void {
        $db = self::$instance;
        if (!$db) {
            return;
        }

        $table = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='install_state'")->fetch();
        if (!$table) {
            return;
        }

        $existing = $db->query("SELECT state FROM install_state WHERE id = 1")->fetch();
        if ($existing) {
            return;
        }

        $userCount = 0;
        $usersTable = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetch();
        if ($usersTable) {
            $row = $db->query("SELECT COUNT(*) AS count FROM users")->fetch();
            $userCount = (int)($row['count'] ?? 0);
        }

        $state = $userCount > 0 ? 'complete' : 'fresh';
        $currentStep = $state === 'fresh' ? 'account' : null;
        $completedAt = $state === 'complete' ? "datetime('now')" : 'NULL';
        $stmt = $db->prepare("
            INSERT INTO install_state (id, state, current_step, completed_at)
            VALUES (1, ?, ?, " . $completedAt . ")
        ");
        $stmt->execute([$state, $currentStep]);
    }

    public static function generateUUID(): string {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    private static function createWorkflowsStudioTables(PDO $db): void {
        $db->exec("
            CREATE TABLE IF NOT EXISTS workflow_studio_projects (
                id TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                description TEXT,
                status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'published', 'archived')),
                command_id TEXT,
                draft_json TEXT NOT NULL,
                last_published_json TEXT,
                last_validation_json TEXT,
                published_at TEXT,
                published_by TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                created_by TEXT,
                updated_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_by TEXT,
                FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
                FOREIGN KEY (published_by) REFERENCES users(id) ON DELETE SET NULL
            )
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS workflow_studio_versions (
                id TEXT PRIMARY KEY,
                project_id TEXT NOT NULL,
                version_number INTEGER NOT NULL,
                source TEXT NOT NULL CHECK (source IN ('manual-save', 'publish', 'restore', 'ai-apply', 'ai-revert')),
                snapshot_json TEXT NOT NULL,
                validation_json TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                created_by TEXT,
                FOREIGN KEY (project_id) REFERENCES workflow_studio_projects(id) ON DELETE CASCADE,
                FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                UNIQUE(project_id, version_number)
            )
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS template_studio_projects (
                id TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                description TEXT,
                status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'published', 'archived')),
                template_id TEXT,
                source_type TEXT NOT NULL DEFAULT 'local' CHECK (source_type IN ('local', 'repository')),
                repository_id TEXT,
                source_path TEXT,
                source_ref TEXT,
                draft_json TEXT NOT NULL,
                last_published_json TEXT,
                last_validation_json TEXT,
                published_at TEXT,
                published_by TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                created_by TEXT,
                updated_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_by TEXT,
                FOREIGN KEY (repository_id) REFERENCES repositories(id) ON DELETE SET NULL,
                FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
                FOREIGN KEY (published_by) REFERENCES users(id) ON DELETE SET NULL
            )
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS template_studio_versions (
                id TEXT PRIMARY KEY,
                project_id TEXT NOT NULL,
                version_number INTEGER NOT NULL,
                source TEXT NOT NULL CHECK (source IN ('manual-save', 'publish', 'restore', 'ai-apply', 'ai-revert')),
                snapshot_json TEXT NOT NULL,
                validation_json TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                created_by TEXT,
                FOREIGN KEY (project_id) REFERENCES template_studio_projects(id) ON DELETE CASCADE,
                FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                UNIQUE(project_id, version_number)
            )
        ");

        $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_workflow_studio_projects_command_id ON workflow_studio_projects(command_id) WHERE command_id IS NOT NULL AND command_id != ''");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_workflow_studio_projects_status ON workflow_studio_projects(status)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_workflow_studio_projects_updated_at ON workflow_studio_projects(updated_at)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_workflow_studio_versions_project ON workflow_studio_versions(project_id, version_number DESC)");

        $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_template_studio_projects_template_id ON template_studio_projects(template_id) WHERE template_id IS NOT NULL AND template_id != ''");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_template_studio_projects_status ON template_studio_projects(status)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_template_studio_projects_updated_at ON template_studio_projects(updated_at)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_template_studio_projects_repository ON template_studio_projects(repository_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_template_studio_versions_project ON template_studio_versions(project_id, version_number DESC)");
    }

    private static function createWorkflowsStudioAiHistoryTables(PDO $db): void {
        $db->exec("
            CREATE TABLE IF NOT EXISTS workflow_studio_ai_threads (
                id TEXT PRIMARY KEY,
                project_type TEXT NOT NULL CHECK (project_type IN ('workflow', 'template')),
                project_id TEXT NOT NULL,
                owner_user_id TEXT,
                owner_username TEXT,
                title TEXT,
                status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'archived')),
                last_event_at TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                created_by TEXT,
                FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL,
                FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                UNIQUE (project_type, project_id, owner_user_id)
            )
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS workflow_studio_ai_events (
                id TEXT PRIMARY KEY,
                thread_id TEXT NOT NULL,
                project_type TEXT NOT NULL CHECK (project_type IN ('workflow', 'template')),
                project_id TEXT NOT NULL,
                sequence_number INTEGER NOT NULL,
                event_type TEXT NOT NULL CHECK (event_type IN ('user-prompt', 'assistant-proposal', 'assistant-error', 'apply', 'dismiss', 'revert', 'save', 'publish')),
                actor_user_id TEXT,
                actor_username TEXT,
                provider_id TEXT,
                provider_name TEXT,
                model TEXT,
                prompt_text TEXT,
                message_text TEXT,
                summary TEXT,
                notes_json TEXT,
                actions_json TEXT,
                validation_before_json TEXT,
                validation_after_json TEXT,
                draft_before_json TEXT,
                draft_after_json TEXT,
                base_version_id TEXT,
                result_version_id TEXT,
                proposal_status TEXT CHECK (proposal_status IN ('proposed', 'applied', 'dismissed', 'reverted', 'superseded', 'error')),
                applied_event_id TEXT,
                reverted_event_id TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                FOREIGN KEY (thread_id) REFERENCES workflow_studio_ai_threads(id) ON DELETE CASCADE,
                FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
                FOREIGN KEY (applied_event_id) REFERENCES workflow_studio_ai_events(id) ON DELETE SET NULL,
                FOREIGN KEY (reverted_event_id) REFERENCES workflow_studio_ai_events(id) ON DELETE SET NULL,
                UNIQUE (thread_id, sequence_number)
            )
        ");

        $db->exec("CREATE INDEX IF NOT EXISTS idx_workflow_studio_ai_threads_project ON workflow_studio_ai_threads(project_type, project_id, last_event_at DESC, created_at DESC)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_workflow_studio_ai_threads_owner ON workflow_studio_ai_threads(owner_user_id, project_type, project_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_workflow_studio_ai_events_thread_sequence ON workflow_studio_ai_events(thread_id, sequence_number ASC)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_workflow_studio_ai_events_project_type ON workflow_studio_ai_events(project_type, project_id, event_type, created_at DESC)");
    }

    private static function recreateWorkflowsStudioVersionTables(PDO $db): void {
        self::rebuildWorkflowsStudioVersionTable(
            $db,
            'workflow_studio_versions',
            'workflow_studio_versions_old',
            'workflow_studio_projects',
            'idx_workflow_studio_versions_project'
        );

        self::rebuildWorkflowsStudioVersionTable(
            $db,
            'template_studio_versions',
            'template_studio_versions_old',
            'template_studio_projects',
            'idx_template_studio_versions_project'
        );
    }

    private static function rebuildWorkflowsStudioVersionTable(
        PDO $db,
        string $tableName,
        string $backupTableName,
        string $projectTableName,
        string $indexName
    ): void {
        $tableExists = self::sqliteTableExists($db, $tableName);
        $backupExists = self::sqliteTableExists($db, $backupTableName);
        $supportsAiSources = $tableExists && self::versionTableSupportsAiSources($db, $tableName);

        if ($supportsAiSources) {
            // A prior migration may have completed the new table but left behind
            // the old backup table or its index. Normalize that state without
            // forcing the backup cleanup to succeed.
            $db->exec("DROP INDEX IF EXISTS {$indexName}");
            $db->exec("CREATE INDEX IF NOT EXISTS {$indexName} ON {$tableName}(project_id, version_number DESC)");
            self::dropTableIfUnlocked($db, $backupTableName);
            return;
        }

        $db->beginTransaction();
        try {
            if ($tableExists && !$backupExists) {
                $db->exec("ALTER TABLE {$tableName} RENAME TO {$backupTableName}");
                $tableExists = false;
                $backupExists = true;
            }

            // The named index from the pre-migration table prevents creating the
            // replacement index if the old backup table is still around.
            $db->exec("DROP INDEX IF EXISTS {$indexName}");

            if (!self::sqliteTableExists($db, $tableName)) {
                $db->exec(self::buildWorkflowsStudioVersionTableSql($tableName, $projectTableName));
            }

            if (self::sqliteTableExists($db, $backupTableName)) {
                $db->exec("
                    INSERT OR IGNORE INTO {$tableName} (
                        id, project_id, version_number, source, snapshot_json, validation_json, created_at, created_by
                    )
                    SELECT id, project_id, version_number, source, snapshot_json, validation_json, created_at, created_by
                    FROM {$backupTableName}
                ");
            }

            $db->exec("CREATE INDEX IF NOT EXISTS {$indexName} ON {$tableName}(project_id, version_number DESC)");
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        self::dropTableIfUnlocked($db, $backupTableName);
    }

    private static function buildWorkflowsStudioVersionTableSql(string $tableName, string $projectTableName): string {
        return "
            CREATE TABLE {$tableName} (
                id TEXT PRIMARY KEY,
                project_id TEXT NOT NULL,
                version_number INTEGER NOT NULL,
                source TEXT NOT NULL CHECK (source IN ('manual-save', 'publish', 'restore', 'ai-apply', 'ai-revert')),
                snapshot_json TEXT NOT NULL,
                validation_json TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                created_by TEXT,
                FOREIGN KEY (project_id) REFERENCES {$projectTableName}(id) ON DELETE CASCADE,
                FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                UNIQUE(project_id, version_number)
            )
        ";
    }

    private static function versionTableSupportsAiSources(PDO $db, string $tableName): bool {
        $sql = self::getCreateTableSql($db, $tableName);
        if ($sql === null) {
            return false;
        }

        return str_contains($sql, "'ai-apply'") && str_contains($sql, "'ai-revert'");
    }

    private static function sqliteTableExists(PDO $db, string $tableName): bool {
        $stmt = $db->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1");
        $stmt->execute([$tableName]);
        return (bool)$stmt->fetchColumn();
    }

    private static function getCreateTableSql(PDO $db, string $tableName): ?string {
        $stmt = $db->prepare("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1");
        $stmt->execute([$tableName]);
        $sql = $stmt->fetchColumn();

        return is_string($sql) ? $sql : null;
    }

    private static function getSqliteDatabasePath(PDO $db): ?string {
        $stmt = $db->query("PRAGMA database_list");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        foreach ($rows as $row) {
            if (($row['name'] ?? '') !== 'main') {
                continue;
            }

            $path = trim((string)($row['file'] ?? ''));
            if ($path !== '') {
                return $path;
            }
        }

        return null;
    }

    private static function quoteSqliteString(string $value): string {
        return str_replace("'", "''", $value);
    }

    private static function dropTableIfUnlocked(PDO $db, string $tableName): void {
        if (!self::sqliteTableExists($db, $tableName)) {
            return;
        }

        try {
            $db->exec("DROP TABLE {$tableName}");
        } catch (PDOException $e) {
            if (stripos($e->getMessage(), 'locked') === false) {
                throw $e;
            }
        }
    }
}
