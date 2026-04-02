<?php
/**
 * OnboardingManager.php - First-run bootstrap, onboarding, and recovery flow
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/ConfigManager.php';
require_once __DIR__ . '/CommandsManager.php';
require_once __DIR__ . '/SystemSettingsManager.php';
require_once __DIR__ . '/StealthGuard.php';
require_once __DIR__ . '/SecretsStore.php';
require_once __DIR__ . '/AppManager.php';
require_once __DIR__ . '/RuntimeEnvironment.php';
require_once __DIR__ . '/OnboardingDebugLogger.php';

class OnboardingManager {
    public const STATE_FRESH = 'fresh';
    public const STATE_ONBOARDING = 'onboarding';
    public const STATE_COMPLETE = 'complete';

    private const INSTALL_STATE_ID = 1;
    private const RECOVERY_ID = 1;
    private const RECOVERY_MAX_ATTEMPTS = 5;
    private const RECOVERY_LOCKOUT_MINUTES = 15;

    private const RUNTIME_DIRS = [
        'config' => __DIR__ . '/../data/config',
        'keys' => __DIR__ . '/../data/keys',
        'sessions' => __DIR__ . '/../data/sessions',
        'sources' => __DIR__ . '/../data/sources',
        'installed-apps' => __DIR__ . '/../data/installed-apps',
        'playwright-runtime' => __DIR__ . '/../data/playwright-runtime',
        'app-build-status' => __DIR__ . '/../data/app-build-status',
        'templates' => __DIR__ . '/../data/templates',
        'users' => __DIR__ . '/../data/users',
    ];

    private PDO $db;
    private Auth $auth;
    private ConfigManager $config;
    private SystemSettingsManager $settings;
    private SecretsStore $secretsStore;
    private AppManager $appManager;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->auth = new Auth();
        $this->config = new ConfigManager();
        $this->settings = new SystemSettingsManager();
        $this->secretsStore = new SecretsStore();
        $this->appManager = new AppManager();
        $this->ensureInstallStateRow();
    }

    public function getInstallState(): array {
        $stmt = $this->db->prepare("SELECT * FROM install_state WHERE id = ?");
        $stmt->execute([self::INSTALL_STATE_ID]);
        $row = $stmt->fetch();
        if (!$row) {
            $this->ensureInstallStateRow();
            $stmt->execute([self::INSTALL_STATE_ID]);
            $row = $stmt->fetch();
        }

        return $row ?: [
            'id' => self::INSTALL_STATE_ID,
            'state' => self::STATE_FRESH,
            'current_step' => 'account',
            'setup_ran_at' => null,
            'completed_at' => null,
            'created_at' => null,
            'updated_at' => null,
        ];
    }

    public function getState(): string {
        return (string)($this->getInstallState()['state'] ?? self::STATE_FRESH);
    }

    public function requiresOnboarding(): bool {
        return $this->getState() !== self::STATE_COMPLETE;
    }

    public function isComplete(): bool {
        return $this->getState() === self::STATE_COMPLETE;
    }

    public function getStatus(): array {
        $install = $this->getInstallState();
        $bootstrap = $this->getBootstrapStatus();
        $firstAdmin = $this->getFirstSuperAdmin();
        $localTarget = $this->getTargetWithFallback('local');
        $stealthSettings = $this->settings->getRuntimeStealthSettings();
        $moduleSettings = $this->settings->getModuleSettings();
        $recovery = $this->getRecoveryRecord();

        return [
            'install' => [
                'state' => $install['state'] ?? self::STATE_FRESH,
                'currentStep' => $install['current_step'] ?? 'account',
                'setupRanAt' => $install['setup_ran_at'] ?? null,
                'completedAt' => $install['completed_at'] ?? null,
            ],
            'bootstrap' => $bootstrap,
            'firstAdmin' => [
                'exists' => $firstAdmin !== null,
                'username' => $firstAdmin['username'] ?? null,
            ],
            'localTarget' => [
                'exists' => $localTarget !== null,
                'verifiedCapabilities' => $localTarget['verifiedCapabilities'] ?? [],
                'verification' => $localTarget['verification'] ?? null,
            ],
            'stealth' => [
                'enabled' => (bool)($stealthSettings['enabled'] ?? true),
                'secretReady' => StealthGuard::isSecretReady(),
                'secretPath' => StealthGuard::getSecretPath(),
            ],
            'secretsKey' => [
                'ready' => !$this->secretsStore->getStatus()['locked'],
                'path' => $this->secretsStore->getKeyPath(),
            ],
            'modules' => [
                'workflows' => $this->isModuleGroupEnabled($moduleSettings, ['commands', 'templates', 'history']),
                'apps' => !empty($moduleSettings['apps']['enabled']),
                'appStudio' => !empty($moduleSettings['studio']['enabled']),
                'aiFeatures' => $this->isModuleGroupEnabled($moduleSettings, ['ai.admin', 'studio.ai']),
            ],
            'recovery' => [
                'configured' => $recovery !== null,
                'lastRotatedAt' => $recovery['last_rotated_at'] ?? null,
                'lastUsedAt' => $recovery['last_used_at'] ?? null,
            ],
        ];
    }

    public function getBootstrapStatus(): array {
        $checks = [];

        $checks['appDataWritable'] = $this->makeCheck(
            is_dir(__DIR__ . '/../data') && is_writable(__DIR__ . '/../data'),
            'App data directory is writable',
            'app/data must exist and be writable',
            true
        );

        foreach (['yaml', 'pdo_sqlite', 'openssl', 'curl'] as $extension) {
            $checks["phpExtension:$extension"] = $this->makeCheck(
                extension_loaded($extension),
                "PHP extension '$extension' is available",
                "PHP extension '$extension' is missing",
                true
            );
        }

        foreach (self::RUNTIME_DIRS as $label => $path) {
            $checks["runtimeDir:$label"] = $this->makeCheck(
                is_dir($path) && is_writable($path),
                "$label directory is ready",
                "$label directory is missing or not writable",
                true
            );
        }

        $checks['secretsKey'] = $this->makeCheck(
            !$this->secretsStore->getStatus()['locked'],
            'Secrets key is ready',
            $this->secretsStore->getStatus()['reason'] ?? 'Secrets key unavailable',
            true
        );

        $checks['stealthSecret'] = $this->makeCheck(
            StealthGuard::isSecretReady(),
            'Stealth secret is ready',
            'Stealth secret file is missing or unreadable',
            true
        );

        $checks['dockerCli'] = $this->makeCheck(
            $this->commandExists('docker'),
            'Docker CLI is available',
            'Docker CLI is not available',
            false
        );

        $dockerInfo = $this->probeDocker();
        $checks['dockerAccess'] = $this->makeCheck(
            $dockerInfo['ok'],
            $dockerInfo['message'],
            $dockerInfo['message'],
            false
        );

        $orchestrator = $this->probeOrchestrator();
        $checks['orchestrator'] = $this->makeCheck(
            $orchestrator['ok'],
            $orchestrator['message'],
            $orchestrator['message'],
            false
        );

        $localTarget = $this->getTargetWithFallback('local');
        $localReady = is_array($localTarget);
        $checks['localTarget'] = $this->makeCheck(
            $localReady,
            'Local target is configured',
            'Local target is not configured yet',
            false
        );

        if ($localReady) {
            $verified = in_array('exec.docker', $localTarget['verifiedCapabilities'] ?? [], true);
            $checks['localTargetVerified'] = $this->makeCheck(
                $verified,
                'Local target has verified Docker capability',
                (string)(($localTarget['verification']['error'] ?? null) ?: 'Local target is not verified yet'),
                false
            );
        }

        $blockingFailures = array_filter($checks, static function(array $check): bool {
            return !empty($check['blocking']) && empty($check['ok']);
        });
        $warnings = array_filter($checks, static function(array $check): bool {
            return empty($check['blocking']) && empty($check['ok']);
        });

        return [
            'ready' => empty($blockingFailures),
            'healthy' => empty($blockingFailures) && empty($warnings),
            'checks' => $checks,
        ];
    }

    public function runSetup(bool $checkOnly = false, bool $rotateKeys = false): array {
        $actions = [];

        foreach (self::RUNTIME_DIRS as $label => $path) {
            if (!is_dir($path) && !$checkOnly) {
                if (@mkdir($path, $label === 'sessions' ? 01777 : 0755, true) || is_dir($path)) {
                    if ($label === 'keys') {
                        @chmod($path, 0700);
                    }
                    $actions[] = "Created runtime directory: {$path}";
                }
            }
        }

        if (!$checkOnly) {
            $this->ensureOrchestratorHostPort($actions);

            if ($rotateKeys) {
                if ($this->secretsStore->rotateKeyFile()) {
                    $actions[] = 'Rotated secrets encryption key';
                }
                if (StealthGuard::rotateSecret()) {
                    $actions[] = 'Rotated stealth secret';
                }
            } else {
                if ($this->secretsStore->ensureKeyFile()) {
                    $actions[] = 'Secrets encryption key is ready';
                }
                if (StealthGuard::ensureSecretFile()) {
                    $actions[] = 'Stealth secret is ready';
                }
            }

            $this->ensureLiveConfigFiles($actions);
            $this->ensureLocalOrchestrator($actions);
            $this->ensureLocalTarget($actions);
            $this->markSetupRun();
        }

        $status = $this->getStatus();
        $status['actions'] = $actions;
        $status['stealth']['secret'] = !$checkOnly && StealthGuard::isSecretReady()
            ? StealthGuard::getSecret()
            : null;

        return $status;
    }

    public function completeOnboarding(array $input): array {
        $state = $this->getState();
        OnboardingDebugLogger::log('manager.complete.start', [
            'state' => $state,
            'sessionId' => session_id(),
            'username' => $input['username'] ?? null,
            'wizardStep' => $input['wizardStep'] ?? null,
        ]);
        if ($state === self::STATE_COMPLETE) {
            OnboardingDebugLogger::log('manager.complete.already_complete', [
                'sessionId' => session_id(),
            ]);
            return ['success' => false, 'error' => 'Onboarding is already complete.'];
        }

        $bootstrap = $this->getBootstrapStatus();
        if (!$bootstrap['ready']) {
            OnboardingDebugLogger::log('manager.complete.bootstrap_not_ready', [
                'sessionId' => session_id(),
            ]);
            return ['success' => false, 'error' => 'Bootstrap is not ready yet. Run setup.sh and resolve the blocking health checks first.'];
        }

        $username = strtolower(trim((string)($input['username'] ?? 'admin')));
        $name = trim((string)($input['name'] ?? 'System Administrator'));
        $email = trim((string)($input['email'] ?? ''));
        $password = (string)($input['password'] ?? '');
        $passwordConfirm = (string)($input['passwordConfirm'] ?? '');

        if (!preg_match('/^[a-z0-9][a-z0-9._-]{1,31}$/i', $username)) {
            return ['success' => false, 'error' => 'Username must start with a letter or number and use only letters, numbers, dots, underscores, or hyphens.'];
        }
        if ($name === '') {
            return ['success' => false, 'error' => 'Display name is required.'];
        }
        if ($password === '' || $password !== $passwordConfirm) {
            return ['success' => false, 'error' => 'Passwords must be present and match.'];
        }
        $passwordError = $this->validatePassword($password);
        if ($passwordError !== null) {
            return ['success' => false, 'error' => $passwordError];
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ['success' => false, 'error' => 'Email address is not valid.'];
        }

        $enableStealth = $this->readToggleInput($input, 'enableStealth', true);
        $enableWorkflows = $this->readToggleInput($input, 'enableWorkflows', true);
        $enableApps = $this->readToggleInput($input, 'enableApps', true);
        $enableAppStudio = $this->readToggleInput($input, 'enableAppStudio', true);
        $enableAiFeatures = $this->readToggleInput($input, 'enableAiFeatures', true);

        $ip = $_SERVER['REMOTE_ADDR'] ?? null;

        $transactionStarted = false;
        try {
            OnboardingDebugLogger::log('manager.complete.transaction_begin', [
                'sessionId' => session_id(),
                'username' => $username,
            ]);
            $this->beginImmediateTransaction();
            $transactionStarted = true;

            $existingState = $this->getInstallStateForUpdate();
            if (($existingState['state'] ?? null) === self::STATE_COMPLETE) {
                $this->rollbackImmediateTransaction();
                $transactionStarted = false;
                return ['success' => false, 'error' => 'Onboarding is already complete.'];
            }

            $userCount = (int)($this->db->query("SELECT COUNT(*) AS count FROM users")->fetch()['count'] ?? 0);
            if ($userCount > 0) {
                $this->setInstallState(self::STATE_COMPLETE, 'complete');
                $this->commitImmediateTransaction();
                $transactionStarted = false;
                return ['success' => false, 'error' => 'An administrator already exists. Use the normal login flow.'];
            }

            $this->setInstallState(self::STATE_ONBOARDING, 'account');

            $userId = Database::generateUUID();
            $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $stmt = $this->db->prepare("
                INSERT INTO users (id, username, email, name, password_hash, role)
                VALUES (?, ?, ?, ?, ?, 'super-admin')
            ");
            $stmt->execute([
                $userId,
                $username,
                $email !== '' ? $email : null,
                $name,
                $passwordHash,
            ]);
            OnboardingDebugLogger::log('manager.complete.user_inserted', [
                'sessionId' => session_id(),
                'userId' => $userId,
                'username' => $username,
            ]);

            $this->auth->auditLog($userId, $username, 'secrets_key_confirmed', 'bootstrap', null, 'Confirmed setup-generated secrets key', $ip);

            $stealthSettings = $this->settings->getStealthSettings();
            $stealthSettings['enabled'] = $enableStealth;
            $this->settings->saveStealthSettings($stealthSettings, $userId);

            $recoveryCode = $this->generateRecoveryCode();
            $recoveryStmt = $this->db->prepare("
                INSERT INTO bootstrap_recovery (
                    id, user_id, recovery_code, issued_at, last_rotated_at, failed_attempts, updated_at
                ) VALUES (
                    ?, ?, ?, datetime('now'), datetime('now'), 0, datetime('now')
                )
            ");
            $recoveryStmt->execute([self::RECOVERY_ID, $userId, $recoveryCode]);
            OnboardingDebugLogger::log('manager.complete.recovery_created', [
                'sessionId' => session_id(),
                'userId' => $userId,
            ]);

            $localTargetActions = [];
            $this->ensureLocalTarget($localTargetActions);
            $this->applyModuleChoices($userId, [
                'workflows' => $enableWorkflows,
                'apps' => $enableApps,
                'appStudio' => $enableAppStudio,
                'aiFeatures' => $enableAiFeatures,
            ]);
            OnboardingDebugLogger::log('manager.complete.module_choices_applied', [
                'sessionId' => session_id(),
                'workflows' => $enableWorkflows,
                'apps' => $enableApps,
                'appStudio' => $enableAppStudio,
                'aiFeatures' => $enableAiFeatures,
            ]);

            if ($enableWorkflows) {
                $this->seedStarterCommand($userId, $username);
            }
            if ($enableApps) {
                $this->assignDefaultExecTargets($userId, $username);
            }

            $this->setInstallState(self::STATE_COMPLETE, 'complete', true);

            $this->auth->auditLog($userId, $username, 'first_admin_created', 'bootstrap', $userId, 'Created first super-admin during onboarding', $ip);
            $this->auth->auditLog($userId, $username, 'onboarding_completed', 'bootstrap', null, json_encode([
                'stealthEnabled' => $enableStealth,
                'workflowsEnabled' => $enableWorkflows,
                'appsEnabled' => $enableApps,
                'appStudioEnabled' => $enableAppStudio,
                'aiFeaturesEnabled' => $enableAiFeatures,
            ]), $ip);

            $this->commitImmediateTransaction();
            $transactionStarted = false;
            OnboardingDebugLogger::log('manager.complete.committed', [
                'sessionId' => session_id(),
                'userId' => $userId,
                'redirect' => '/login.php?setup=complete',
            ]);

            if ($enableStealth && session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['stealth']['unlocked'] = true;
                $_SESSION['stealth']['unlock_time'] = time();
            }

            return [
                'success' => true,
                'redirect' => '/login.php?setup=complete',
                'username' => $username,
                'recoveryConfigured' => true,
            ];
        } catch (Throwable $e) {
            if ($transactionStarted) {
                $this->rollbackImmediateTransaction();
            }
            OnboardingDebugLogger::log('manager.complete.exception', [
                'sessionId' => session_id(),
                'username' => $username ?? null,
                'error' => $e->getMessage(),
            ]);

            if (stripos($e->getMessage(), 'UNIQUE') !== false) {
                return ['success' => false, 'error' => 'That username or email already exists.'];
            }

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function recoverFirstSuperAdmin(string $recoveryCode, string $newPassword, string $ip = ''): array {
        if ($this->requiresOnboarding()) {
            return ['success' => false, 'error' => 'Finish onboarding before using recovery.', 'code' => 409];
        }

        $passwordError = $this->validatePassword($newPassword);
        if ($passwordError !== null) {
            return ['success' => false, 'error' => $passwordError, 'code' => 400];
        }

        $record = $this->getRecoveryRecord();
        if (!$record) {
            return ['success' => false, 'error' => 'Recovery is not configured yet.', 'code' => 404];
        }

        $firstAdmin = $this->getFirstSuperAdmin();
        if (!$firstAdmin || ($firstAdmin['id'] ?? '') !== ($record['user_id'] ?? '')) {
            return ['success' => false, 'error' => 'Recovery record is out of sync with the first super-admin.', 'code' => 409];
        }

        $lockoutUntil = $record['lockout_until'] ?? null;
        if ($lockoutUntil && strtotime((string)$lockoutUntil) > time()) {
            return ['success' => false, 'error' => 'Too many recovery attempts. Please wait before trying again.', 'code' => 429];
        }

        $suppliedCode = strtoupper(trim($recoveryCode));
        $storedCode = strtoupper(trim((string)($record['recovery_code'] ?? '')));
        if ($suppliedCode === '' || !hash_equals($storedCode, $suppliedCode)) {
            $failedAttempts = (int)($record['failed_attempts'] ?? 0) + 1;
            $lockout = $failedAttempts >= self::RECOVERY_MAX_ATTEMPTS
                ? date('Y-m-d H:i:s', time() + (self::RECOVERY_LOCKOUT_MINUTES * 60))
                : null;

            $stmt = $this->db->prepare("
                UPDATE bootstrap_recovery
                SET failed_attempts = ?, last_failed_at = datetime('now'), lockout_until = ?, updated_at = datetime('now')
                WHERE id = ?
            ");
            $stmt->execute([$failedAttempts, $lockout, self::RECOVERY_ID]);

            $this->auth->auditLog($firstAdmin['id'], $firstAdmin['username'], 'recovery_attempt_failed', 'bootstrap', $firstAdmin['id'], 'Invalid recovery code supplied', $ip ?: null);
            return [
                'success' => false,
                'error' => $lockout ? 'Too many recovery attempts. Please wait before trying again.' : 'Recovery code is invalid.',
                'code' => $lockout ? 429 : 401,
            ];
        }

        $newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        $rotatedCode = $this->generateRecoveryCode();

        $transactionStarted = false;
        try {
            $this->beginImmediateTransaction();
            $transactionStarted = true;

            $stmt = $this->db->prepare("UPDATE users SET password_hash = ?, updated_at = datetime('now') WHERE id = ?");
            $stmt->execute([$newHash, $firstAdmin['id']]);

            $stmt = $this->db->prepare("
                UPDATE bootstrap_recovery
                SET recovery_code = ?, failed_attempts = 0, lockout_until = NULL, last_used_at = datetime('now'),
                    last_rotated_at = datetime('now'), updated_at = datetime('now')
                WHERE id = ?
            ");
            $stmt->execute([$rotatedCode, self::RECOVERY_ID]);

            $revokedSessions = $this->auth->revokeUserSessions($firstAdmin['id']);

            $this->auth->auditLog($firstAdmin['id'], $firstAdmin['username'], 'recovery_succeeded', 'bootstrap', $firstAdmin['id'], 'Recovered first super-admin password', $ip ?: null);
            $this->auth->auditLog($firstAdmin['id'], $firstAdmin['username'], 'recovery_rotated', 'bootstrap', $firstAdmin['id'], 'Rotated recovery code after successful password reset', $ip ?: null);
            $this->auth->auditLog($firstAdmin['id'], $firstAdmin['username'], 'sessions_revoked', 'auth', $firstAdmin['id'], 'Revoked sessions after break-glass recovery (' . $revokedSessions . ')', $ip ?: null);

            $this->commitImmediateTransaction();
            $transactionStarted = false;

            return [
                'success' => true,
                'redirect' => '/login.php?recovered=1',
            ];
        } catch (Throwable $e) {
            if ($transactionStarted) {
                $this->rollbackImmediateTransaction();
            }

            return ['success' => false, 'error' => 'Unable to reset the super-admin password right now.', 'code' => 500];
        }
    }

    public function ensureStealthSessionForAuth(): bool {
        return StealthGuard::isSessionUnlocked();
    }

    private function ensureRuntimeConfigFile(string $type, array $data): void {
        $path = $this->config->getConfigPath($type);
        if (file_exists($path)) {
            return;
        }

        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        if (function_exists('yaml_emit')) {
            $yaml = yaml_emit($data, YAML_UTF8_ENCODING);
            $yaml = preg_replace('/^---\n/', '', (string)$yaml);
            $yaml = preg_replace('/\n\.\.\.$/', '', (string)$yaml);
        } else {
            $yaml = $this->emitSimpleYaml($data);
        }
        file_put_contents($path, $yaml);
    }

    private function ensureRegistryFile(string $path, array $data): void {
        if (file_exists($path)) {
            return;
        }

        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $yaml = function_exists('yaml_emit') ? yaml_emit($data, YAML_UTF8_ENCODING) : $this->emitSimpleYaml($data);
        $yaml = preg_replace('/^---\n/', '', (string)$yaml);
        $yaml = preg_replace('/\n\.\.\.$/', '', (string)$yaml);
        file_put_contents($path, $yaml);
    }

    private function ensureLiveConfigFiles(array &$actions): void {
        $this->ensureRuntimeConfigFile('environments', ['environments' => []]);
        $actions[] = 'Ensured environments.yaml exists';

        if (!file_exists($this->config->getConfigPath('secrets')) && !$this->secretsStore->getStatus()['locked']) {
            try {
                $this->secretsStore->saveSecrets([]);
                $actions[] = 'Created encrypted empty secrets.yaml';
            } catch (Throwable $e) {
                $actions[] = 'Unable to create encrypted secrets.yaml: ' . $e->getMessage();
            }
        }

        $this->ensureRegistryFile(AppManager::getRuntimeInstalledRegistryPath(), ['apps' => []]);
        $actions[] = 'Ensured app registry exists';

        $this->ensureRegistryFile($this->config->getConfigPath('commands'), ['commands' => []]);
        $actions[] = 'Ensured commands registry exists';
    }

    private function ensureLocalTarget(array &$actions): void {
        $target = $this->getTargetWithFallback('local');
        if (!$target) {
            $target = [
                'id' => 'local',
                'name' => 'Local Runtime',
                'type' => 'local',
                'description' => 'Built-in local execution target for this Doki instance.',
                'connection' => [],
            ];
            if ($this->saveTargetWithFallback($target)) {
                $actions[] = 'Seeded built-in local target';
            } else {
                $actions[] = 'Unable to seed built-in local target';
            }
        }

        $verified = $this->verifyTargetWithFallback('local');
        if (!empty($verified['success'])) {
            $actions[] = 'Verified local target capabilities';
        } else {
            $actions[] = 'Local target verification warning: ' . ($verified['error'] ?? 'unknown verification error');
        }
    }

    private function ensureLocalOrchestrator(array &$actions): void {
        if (RuntimeEnvironment::isContainerRuntime()) {
            return;
        }

        if (trim((string)(getenv('DOKI_ORCH_URL') ?: '')) !== '') {
            $actions[] = 'Skipped local orchestrator startup because DOKI_ORCH_URL is explicitly set';
            return;
        }

        if ($this->probeOrchestrator()['ok']) {
            return;
        }

        $docker = $this->probeDocker();
        if (!$docker['ok']) {
            $actions[] = 'Skipped local orchestrator startup: ' . $docker['message'];
            return;
        }

        if (!$this->commandExists('docker')) {
            return;
        }

        $composeVersion = $this->runHostCommand(['docker', 'compose', 'version'], 15);
        if (empty($composeVersion['success'])) {
            $actions[] = 'Unable to start local orchestrator automatically because Docker Compose is unavailable';
            return;
        }

        $up = $this->runHostCommand(['docker', 'compose', 'up', '-d', 'php-app', 'go-orchestrator'], 180);
        if (empty($up['success'])) {
            $message = trim((string)($up['output'] ?? ''));
            $actions[] = 'Local orchestrator startup warning: ' . ($message !== '' ? $message : 'docker compose up failed');
            return;
        }

        if ($this->waitForOrchestratorHealth(30)) {
            $actions[] = 'Ensured local Doki services are running';
            return;
        }

        $actions[] = 'Local orchestrator startup warning: services started but /health did not respond yet';
    }

    private function markSetupRun(): void {
        $userCount = (int)($this->db->query("SELECT COUNT(*) AS count FROM users")->fetch()['count'] ?? 0);
        $state = $userCount > 0 ? self::STATE_COMPLETE : self::STATE_FRESH;
        $step = $state === self::STATE_FRESH ? 'account' : 'complete';

        $stmt = $this->db->prepare("
            UPDATE install_state
            SET state = ?, current_step = ?, setup_ran_at = datetime('now'), completed_at = CASE WHEN ? = 'complete' THEN COALESCE(completed_at, datetime('now')) ELSE completed_at END, updated_at = datetime('now')
            WHERE id = ?
        ");
        $stmt->execute([$state, $step, $state, self::INSTALL_STATE_ID]);
    }

    private function getInstallStateForUpdate(): array {
        $stmt = $this->db->prepare("SELECT * FROM install_state WHERE id = ?");
        $stmt->execute([self::INSTALL_STATE_ID]);
        return $stmt->fetch() ?: [];
    }

    private function setInstallState(string $state, ?string $step = null, bool $completed = false): void {
        $stmt = $this->db->prepare("
            UPDATE install_state
            SET state = ?, current_step = ?, completed_at = CASE WHEN ? THEN datetime('now') ELSE completed_at END, updated_at = datetime('now')
            WHERE id = ?
        ");
        $stmt->execute([$state, $step, $completed ? 1 : 0, self::INSTALL_STATE_ID]);
    }

    private function ensureInstallStateRow(): void {
        $row = $this->db->query("SELECT id FROM install_state WHERE id = " . self::INSTALL_STATE_ID)->fetch();
        if ($row) {
            return;
        }

        $userCount = (int)($this->db->query("SELECT COUNT(*) AS count FROM users")->fetch()['count'] ?? 0);
        $state = $userCount > 0 ? self::STATE_COMPLETE : self::STATE_FRESH;
        $step = $state === self::STATE_FRESH ? 'account' : 'complete';
        $stmt = $this->db->prepare("
            INSERT INTO install_state (id, state, current_step, completed_at)
            VALUES (?, ?, ?, CASE WHEN ? = 'complete' THEN datetime('now') ELSE NULL END)
        ");
        $stmt->execute([self::INSTALL_STATE_ID, $state, $step, $state]);
    }

    private function getFirstSuperAdmin(): ?array {
        $stmt = $this->db->query("
            SELECT id, username, email, name, created_at
            FROM users
            WHERE role = 'super-admin' AND active = 1
            ORDER BY created_at ASC, username ASC
            LIMIT 1
        ");
        return $stmt->fetch() ?: null;
    }

    private function getRecoveryRecord(): ?array {
        $stmt = $this->db->prepare("SELECT * FROM bootstrap_recovery WHERE id = ?");
        $stmt->execute([self::RECOVERY_ID]);
        return $stmt->fetch() ?: null;
    }

    private function applyModuleChoices(string $userId, array $choices): void {
        $settings = $this->settings->getModuleSettings();
        $groups = [
            'workflows' => ['commands', 'templates', 'history'],
            'apps' => ['apps'],
            'appStudio' => ['studio'],
            'aiFeatures' => ['ai.admin', 'studio.ai'],
        ];

        foreach ($groups as $choice => $moduleIds) {
            $enabled = !empty($choices[$choice]);
            foreach ($moduleIds as $moduleId) {
                if (!isset($settings[$moduleId])) {
                    continue;
                }
                $settings[$moduleId]['enabled'] = $enabled;
            }
        }

        $this->settings->saveModuleSettings(array_values($settings), $userId);
    }

    private function seedStarterCommand(string $userId, string $username): void {
        $commands = new CommandsManager($this->auth);
        if ($commands->getCommand('local-hello') !== null) {
            return;
        }

        $commands->saveCommand([
            'id' => 'local-hello',
            'name' => 'Local Hello',
            'description' => 'Quick starter command for the built-in local target.',
            'executionMode' => 'sync',
            'maxParallel' => 1,
            'finalStatusPolicy' => 'fail_if_any_failed',
            'defaultTarget' => ['targetId' => 'local'],
            'environment' => null,
            'steps' => [
                [
                    'id' => 'hello',
                    'templateId' => 'shell-exec',
                    'target' => 'inherit',
                    'dependsOn' => [],
                    'onFailure' => 'stop',
                    'inputs' => [
                        'command' => 'printf "Doki local target ready\\n"; date',
                    ],
                    'secrets' => [],
                ],
            ],
            'inputs' => [],
            'inputContract' => [],
            'secrets' => [],
            'tags' => ['starter', 'local'],
            'enabled' => true,
        ], $userId, $username);
    }

    private function assignDefaultExecTargets(string $userId, string $username): void {
        foreach ($this->appManager->getApps() as $app) {
            $capabilities = $app['capabilities'] ?? [];
            if (!is_array($capabilities) || !in_array('exec.docker', $capabilities, true)) {
                continue;
            }

            $appId = (string)($app['id'] ?? '');
            if ($appId === '') {
                continue;
            }
            $this->appManager->setAppExecTarget($appId, 'local', $userId, $username);
        }
    }

    private function validatePassword(string $password): ?string {
        if (strlen($password) < 12) {
            return 'Password must be at least 12 characters long.';
        }
        if (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            return 'Password must include uppercase, lowercase, and numeric characters.';
        }
        return null;
    }

    private function readToggleInput(array $input, string $key, bool $default): bool {
        if (!array_key_exists($key, $input)) {
            return $default;
        }

        $value = $input[$key];
        if (is_array($value)) {
            $value = end($value);
        }

        $normalized = strtolower(trim((string)$value));
        if ($normalized === '') {
            return false;
        }

        if (in_array($normalized, ['0', 'false', 'off', 'no'], true)) {
            return false;
        }

        if (in_array($normalized, ['1', 'true', 'on', 'yes'], true)) {
            return true;
        }

        return !empty($value);
    }

    private function isModuleGroupEnabled(array $settings, array $moduleIds): bool {
        foreach ($moduleIds as $moduleId) {
            if (empty($settings[$moduleId]['enabled'])) {
                return false;
            }
        }
        return true;
    }

    private function commandExists(string $command): bool {
        $path = trim((string)@shell_exec('command -v ' . escapeshellarg($command) . ' 2>/dev/null'));
        return $path !== '';
    }

    private function beginImmediateTransaction(): void {
        $this->db->exec('BEGIN IMMEDIATE TRANSACTION');
    }

    private function commitImmediateTransaction(): void {
        $this->db->exec('COMMIT');
    }

    private function rollbackImmediateTransaction(): void {
        try {
            $this->db->exec('ROLLBACK');
        } catch (Throwable $e) {
            $message = strtolower($e->getMessage());
            if (
                !str_contains($message, 'no transaction')
                && !str_contains($message, 'no active transaction')
            ) {
                throw $e;
            }
        }
    }

    private function probeDocker(): array {
        if (!$this->commandExists('docker')) {
            return ['ok' => false, 'message' => 'Docker CLI is not available'];
        }

        $output = [];
        $exitCode = 1;
        @exec('docker info --format "{{.ServerVersion}}" 2>&1', $output, $exitCode);
        $version = trim(implode("\n", $output));
        $normalized = strtolower($version);
        if ($exitCode === 0) {
            if (
                $version === ''
                || str_contains($normalized, 'permission denied')
                || str_contains($normalized, 'operation not permitted')
                || str_contains($normalized, 'cannot connect')
                || str_contains($normalized, 'error response from daemon')
            ) {
                return ['ok' => false, 'message' => $version !== '' ? $version : 'Docker is not reachable'];
            }
            return ['ok' => true, 'message' => $version !== '' ? "Docker is reachable ({$version})" : 'Docker is reachable'];
        }

        return ['ok' => false, 'message' => $version !== '' ? $version : 'Docker is not reachable'];
    }

    private function probeOrchestrator(): array {
        $configuredUrl = trim((string)(getenv('DOKI_ORCH_URL') ?: ''));
        $baseUrls = RuntimeEnvironment::getOrchestratorHealthProbeBaseUrls();

        $tried = [];
        foreach ($baseUrls as $baseUrl) {
            $baseUrl = rtrim($baseUrl, '/');
            if ($baseUrl === '') {
                continue;
            }

            $tried[] = $baseUrl;
            if ($this->probeHttpHealthEndpoint($baseUrl . '/health')) {
                return ['ok' => true, 'message' => "Go orchestrator responded to /health at {$baseUrl}"];
            }
        }

        if ($configuredUrl !== '') {
            return ['ok' => false, 'message' => 'Go orchestrator health endpoint is not reachable at ' . rtrim($configuredUrl, '/')];
        }

        return ['ok' => false, 'message' => 'Go orchestrator health endpoint is not reachable (tried: ' . implode(', ', $tried) . ')'];
    }

    private function ensureOrchestratorHostPort(array &$actions): void {
        if (RuntimeEnvironment::isContainerRuntime()) {
            return;
        }

        $configuredUrl = trim((string)(getenv('DOKI_ORCH_URL') ?: ''));
        if ($configuredUrl !== '') {
            $actions[] = 'Using explicit DOKI_ORCH_URL override for orchestrator access';
            return;
        }

        $existingPort = RuntimeEnvironment::parsePort(RuntimeEnvironment::getProjectEnvValue('DOKI_ORCH_HOST_PORT'));
        if ($existingPort !== null) {
            return;
        }

        $selection = $this->selectOrchestratorHostPort();
        if ($selection === null) {
            $actions[] = 'Unable to choose an orchestrator host port automatically';
            return;
        }

        if (!RuntimeEnvironment::writeProjectEnvValues([
            'DOKI_ORCH_HOST_PORT' => (string)$selection['port'],
        ])) {
            $actions[] = 'Unable to persist orchestrator host port selection';
            return;
        }

        if ($selection['port'] === RuntimeEnvironment::DEFAULT_ORCHESTRATOR_HOST_PORT) {
            $actions[] = 'Configured orchestrator host port ' . $selection['port'];
            return;
        }

        $actions[] = 'Port ' . RuntimeEnvironment::DEFAULT_ORCHESTRATOR_HOST_PORT . ' is busy; configured orchestrator host port ' . $selection['port'];
    }

    private function selectOrchestratorHostPort(): ?array {
        $defaultPort = RuntimeEnvironment::DEFAULT_ORCHESTRATOR_HOST_PORT;
        if ($this->probeHttpHealthEndpoint('http://127.0.0.1:' . $defaultPort . '/health')) {
            return ['port' => $defaultPort];
        }

        if ($this->isHostPortAvailable($defaultPort)) {
            return ['port' => $defaultPort];
        }

        for ($port = 8100; $port <= 8999; $port++) {
            if ($this->isHostPortAvailable($port)) {
                return ['port' => $port];
            }
        }

        return null;
    }

    private function isHostPortAvailable(int $port): bool {
        $socket = @stream_socket_client(
            'tcp://127.0.0.1:' . $port,
            $errno,
            $errstr,
            0.2,
            STREAM_CLIENT_CONNECT
        );

        if (is_resource($socket)) {
            fclose($socket);
            return false;
        }

        return true;
    }

    private function probeHttpHealthEndpoint(string $url): bool {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_TIMEOUT => 3,
            ]);
            curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            return $status >= 200 && $status < 300;
        }

        if (ini_get('allow_url_fopen')) {
            $context = stream_context_create([
                'http' => [
                    'timeout' => 3,
                ],
            ]);
            return @file_get_contents($url, false, $context) !== false;
        }

        return false;
    }

    private function waitForOrchestratorHealth(int $timeoutSeconds): bool {
        $deadline = microtime(true) + max(1, $timeoutSeconds);
        do {
            if ($this->probeOrchestrator()['ok']) {
                return true;
            }
            usleep(500000);
        } while (microtime(true) < $deadline);

        return $this->probeOrchestrator()['ok'];
    }

    private function runHostCommand(array $cmd, int $timeoutSeconds = 30): array {
        $cwd = getcwd();
        $root = dirname(__DIR__, 2);

        if ($cwd === false || !@chdir($root)) {
            return ['success' => false, 'output' => 'Unable to change into project root', 'exitCode' => 1];
        }

        $command = implode(' ', array_map(static function($part): string {
            return escapeshellarg((string)$part);
        }, $cmd)) . ' 2>&1';

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open(['/bin/sh', '-lc', $command], $descriptorSpec, $pipes);
        if (!is_resource($process)) {
            @chdir($cwd);
            return ['success' => false, 'output' => 'Unable to start command', 'exitCode' => 1];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output = '';
        $timedOut = false;
        $deadline = microtime(true) + max(1, $timeoutSeconds);

        while (true) {
            $status = proc_get_status($process);
            $running = !empty($status['running']);
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
        if ($timedOut) {
            $output = trim($output);
            $output = $output === ''
                ? 'Command timed out after ' . $timeoutSeconds . 's'
                : $output . "\n" . 'Command timed out after ' . $timeoutSeconds . 's';
            $exitCode = 124;
        }

        @chdir($cwd);

        return [
            'success' => $exitCode === 0,
            'output' => trim($output),
            'exitCode' => $exitCode,
        ];
    }

    private function getTargetWithFallback(string $id): ?array {
        if (function_exists('yaml_parse')) {
            return $this->config->getTarget($id);
        }

        $data = $this->loadSimpleYamlFile($this->config->getConfigPath('targets'));
        $targets = $data['targets'] ?? [];
        if (!is_array($targets)) {
            return null;
        }

        foreach ($targets as $target) {
            if (is_array($target) && (string)($target['id'] ?? '') === $id) {
                return $target;
            }
        }

        return null;
    }

    private function saveTargetWithFallback(array $target): bool {
        if (function_exists('yaml_emit')) {
            $result = $this->config->saveTarget($target, null, 'setup.sh');
            return !empty($result['success']);
        }

        $path = $this->config->getConfigPath('targets');
        $data = $this->loadSimpleYamlFile($path);
        $targets = $data['targets'] ?? [];
        if (!is_array($targets)) {
            $targets = [];
        }

        $updated = false;
        foreach ($targets as $index => $existing) {
            if (is_array($existing) && (string)($existing['id'] ?? '') === (string)($target['id'] ?? '')) {
                $targets[$index] = $target;
                $updated = true;
                break;
            }
        }

        if (!$updated) {
            $targets[] = $target;
        }

        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }

        return file_put_contents($path, $this->emitSimpleYaml(['targets' => array_values($targets)])) !== false;
    }

    private function verifyTargetWithFallback(string $id): array {
        if (function_exists('yaml_emit') && function_exists('yaml_parse')) {
            return $this->config->verifyTarget($id, null, 'setup.sh');
        }

        return ['success' => false, 'error' => "PHP extension 'yaml' is missing"];
    }

    private function loadSimpleYamlFile(string $path): array {
        if (!file_exists($path)) {
            return [];
        }

        $content = @file_get_contents($path);
        if (!is_string($content) || trim($content) === '') {
            return [];
        }

        return $this->parseSimpleYaml($content);
    }

    private function parseSimpleYaml(string $content): array {
        $lines = preg_split('/\R/', $content) ?: [];
        $index = 0;
        $parsed = $this->parseSimpleYamlBlock($lines, $index, 0);
        return is_array($parsed) ? $parsed : [];
    }

    private function parseSimpleYamlBlock(array $lines, int &$index, int $indent): array {
        $result = [];
        $mode = null;

        while ($index < count($lines)) {
            $rawLine = rtrim($lines[$index], "\r");
            $trimmed = trim($rawLine);

            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                $index++;
                continue;
            }

            $lineIndent = $this->yamlIndent($rawLine);
            if ($lineIndent < $indent) {
                break;
            }
            if ($lineIndent > $indent) {
                $index++;
                continue;
            }

            if (preg_match('/^-\s*(.*)$/', ltrim($rawLine), $matches) === 1) {
                $mode = $mode ?? 'list';
                if ($mode !== 'list') {
                    break;
                }

                $index++;
                $value = trim((string)$matches[1]);
                if ($value === '') {
                    $result[] = $this->parseSimpleYamlBlock($lines, $index, $indent + 2);
                    continue;
                }

                $result[] = $this->parseSimpleYamlScalar($value);
                continue;
            }

            if (preg_match('/^([^:]+):\s*(.*)$/', trim(substr($rawLine, $indent)), $matches) !== 1) {
                $index++;
                continue;
            }

            $mode = $mode ?? 'map';
            if ($mode !== 'map') {
                break;
            }

            $key = trim((string)$matches[1]);
            $value = trim((string)$matches[2]);
            $index++;

            if ($value === '') {
                $result[$key] = $this->parseSimpleYamlBlock($lines, $index, $indent + 2);
                continue;
            }

            $result[$key] = $this->parseSimpleYamlScalar($value);
        }

        return $result;
    }

    private function parseSimpleYamlScalar(string $value) {
        $trimmed = trim($value);
        if ($trimmed === '{}' || $trimmed === '[]') {
            return [];
        }
        if (
            (str_starts_with($trimmed, '"') && str_ends_with($trimmed, '"'))
            || (str_starts_with($trimmed, "'") && str_ends_with($trimmed, "'"))
        ) {
            $quote = $trimmed[0];
            $trimmed = substr($trimmed, 1, -1);
            if ($quote === '"') {
                $trimmed = stripcslashes($trimmed);
            }
            return $trimmed;
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

    private function yamlIndent(string $line): int {
        preg_match('/^ */', $line, $matches);
        return strlen($matches[0] ?? '');
    }

    private function generateRecoveryCode(): string {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $parts = [];
        for ($part = 0; $part < 4; $part++) {
            $chunk = '';
            for ($i = 0; $i < 5; $i++) {
                $chunk .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $parts[] = $chunk;
        }
        return implode('-', $parts);
    }

    private function makeCheck(bool $ok, string $okMessage, string $errorMessage, bool $blocking): array {
        return [
            'ok' => $ok,
            'message' => $ok ? $okMessage : $errorMessage,
            'blocking' => $blocking,
        ];
    }

    private function emitSimpleYaml(array $data): string {
        $lines = [];
        $this->appendYamlValue($lines, $data, 0, null);
        return implode("\n", $lines) . "\n";
    }

    private function appendYamlValue(array &$lines, $value, int $indent, ?string $key): void {
        $prefix = str_repeat(' ', $indent);
        if ($key !== null) {
            $prefix .= $key . ':';
        }

        if (is_array($value)) {
            $childIndent = $key === null ? $indent : $indent + 2;
            if ($key !== null) {
                $lines[] = $prefix . (empty($value) ? ' {}' : '');
                if (empty($value)) {
                    return;
                }
            }

            $isList = array_keys($value) === range(0, count($value) - 1);
            foreach ($value as $childKey => $childValue) {
                if ($isList) {
                    if (is_array($childValue)) {
                        $lines[] = str_repeat(' ', $childIndent) . '-';
                        $this->appendYamlNested($lines, $childValue, $childIndent + 2);
                    } else {
                        $lines[] = str_repeat(' ', $childIndent) . '- ' . $this->yamlScalar($childValue);
                    }
                } else {
                    $this->appendYamlValue($lines, $childValue, $childIndent, (string)$childKey);
                }
            }
            return;
        }

        $lines[] = $prefix . ' ' . $this->yamlScalar($value);
    }

    private function appendYamlNested(array &$lines, array $value, int $indent): void {
        foreach ($value as $childKey => $childValue) {
            $this->appendYamlValue($lines, $childValue, $indent, (string)$childKey);
        }
    }

    private function yamlScalar($value): string {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }

        $string = str_replace('"', '\"', (string)$value);
        return '"' . $string . '"';
    }
}
