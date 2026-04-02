<?php
/**
 * Orchestrator - Core execution engine for Doki
 * 
 * Responsibilities:
 * 1. Load and validate templates
 * 2. Resolve targets and inject secrets
 * 3. Execute commands in isolated Docker containers
 * 4. Stream output and track execution history
 */

// Enable strict error reporting to catch undefined variables etc.
error_reporting(E_ALL);

require_once __DIR__ . '/TemplateManager.php';
require_once __DIR__ . '/ConfigManager.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Auth.php';

class Orchestrator {
    // Debug mode - set to true for verbose logging
    private const DEBUG = true;
    
    /**
     * Log debug message if DEBUG mode is enabled
     */
    private function debug(string $message, array $context = []): void {
        if (self::DEBUG) {
            $contextStr = empty($context) ? '' : ' ' . json_encode($context);
            error_log("Orchestrator [DEBUG]: {$message}{$contextStr}");
        }
    }
    
    /**
     * Log error message (always logged)
     */
    private function logError(string $message, ?\Throwable $e = null): void {
        $errorMsg = "Orchestrator [ERROR]: {$message}";
        if ($e) {
            $errorMsg .= " | Exception: " . $e->getMessage();
            $errorMsg .= " | File: " . $e->getFile() . ":" . $e->getLine();
            $errorMsg .= " | Trace: " . $e->getTraceAsString();
        }
        error_log($errorMsg);
    }
    private TemplateManager $templates;
    private ConfigManager $config;
    private $db;
    
    private const JOBS_TABLE = 'execution_jobs';
    private const MAX_OUTPUT_LENGTH = 1000000; // 1MB max output stored
    
    public function __construct() {
        $this->templates = new TemplateManager();
        $this->config = new ConfigManager();
        $this->db = Database::getInstance();
        $this->ensureJobsTable();
    }
    
    /**
     * Ensure the jobs table exists
     */
    private function ensureJobsTable(): void {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS " . self::JOBS_TABLE . " (
                id TEXT PRIMARY KEY,
                command_id TEXT,
                command_name TEXT,
                template_id TEXT,
                target_id TEXT,
                user_id TEXT,
                username TEXT,
                status TEXT DEFAULT 'pending',
                inputs TEXT,
                output TEXT DEFAULT '',
                exit_code INTEGER,
                error TEXT,
                started_at TEXT,
                completed_at TEXT,
                created_at TEXT DEFAULT (datetime('now'))
            )
        ");
        
        // Create index for listing jobs
        $this->db->exec("
            CREATE INDEX IF NOT EXISTS idx_jobs_user ON " . self::JOBS_TABLE . " (user_id, created_at DESC)
        ");
    }
    
    /**
     * Execute a command
     */
    public function execute(
        string $commandId,
        array $inputs,
        string $userId,
        string $username,
        ?string $targetOverride = null
    ): array {
        // Load command
        require_once __DIR__ . '/CommandsManager.php';
        $commandsManager = new CommandsManager();
        $command = $commandsManager->getCommand($commandId);
        
        if (!$command) {
            return ['success' => false, 'error' => 'Command not found: ' . $commandId];
        }
        
        error_log("Orchestrator: Loaded command: " . json_encode($command));
        
        // Load template
        $template = $this->templates->getTemplate($command['templateId']);
        if (!$template) {
            return ['success' => false, 'error' => 'Template not found: ' . $command['templateId']];
        }
        
        // Determine target (either direct targetId or via environment mapping)
        $targetId = $targetOverride ?? ($command['targetId'] ?? null);
        $environmentId = $command['environment'] ?? null;
        $target = null;
        
        $this->debug("Command targetId = " . ($targetId ?? 'null') . ", environment = " . ($environmentId ?? 'null'));
        
        // Resolve target from environment if no direct targetId
        if (!$targetId && $environmentId) {
            $targetType = $template['targetType'] ?? null;
            $targetId = $this->resolveTargetFromEnvironment($environmentId, $targetType);
            $this->debug("Resolved target from environment '{$environmentId}'" . 
                ($targetType ? " (type: {$targetType})" : "") . 
                ": " . ($targetId ?? 'null'));
        }
        
        if ($targetId) {
            $target = $this->getTargetById($targetId);
            if (!$target) {
                $this->debug("Target not found: " . $targetId);
            }
        } else {
            $this->debug("No target specified for command");
        }
        
        // Merge command defaults with provided inputs
        $mergedInputs = array_merge($command['inputs'] ?? [], $inputs);
        
        // Validate inputs
        $validation = $this->templates->validateInputs($command['templateId'], $mergedInputs);
        if (!$validation['valid']) {
            return ['success' => false, 'error' => 'Validation failed', 'errors' => $validation['errors']];
        }
        
        // Create job record
        $jobId = $this->generateJobId();
        $this->createJob($jobId, $command, $template, $target, $mergedInputs, $userId, $username);
        
        // Execute in background or sync based on template mode
        $mode = $template['execution']['mode'] ?? 'sync';
        
        if ($mode === 'async') {
            // Start background execution
            $this->executeAsync($jobId);
            return ['success' => true, 'jobId' => $jobId, 'status' => 'pending'];
        } else {
            // Synchronous execution - pass command for secrets
            $result = $this->executeSync($jobId, $command, $template, $target, $mergedInputs, $userId, $username);
            return array_merge(['success' => true, 'jobId' => $jobId], $result);
        }
    }
    
    /**
     * Execute a template directly (without a saved command)
     */
    public function executeTemplate(
        string $templateId,
        array $inputs,
        ?string $targetId,
        string $userId,
        string $username
    ): array {
        // Load template
        $template = $this->templates->getTemplate($templateId);
        if (!$template) {
            return ['success' => false, 'error' => 'Template not found: ' . $templateId];
        }
        
        // Load target if specified
        $target = null;
        if ($targetId) {
            $targetsList = $this->config->getTargets(); // Returns array directly
            foreach ($targetsList as $t) {
                if (($t['id'] ?? '') === $targetId) {
                    $target = $t;
                    break;
                }
            }
        }
        
        // Validate inputs
        $validation = $this->templates->validateInputs($templateId, $inputs);
        if (!$validation['valid']) {
            return ['success' => false, 'error' => 'Validation failed', 'errors' => $validation['errors']];
        }
        
        // Create a pseudo-command for tracking (no secrets for direct template execution)
        $command = [
            'id' => 'direct-' . $templateId,
            'name' => 'Direct: ' . $template['name'],
            'templateId' => $templateId,
            'targetId' => $targetId,
            'secrets' => [], // Direct execution doesn't have secrets
        ];
        
        // Create job record
        $jobId = $this->generateJobId();
        $this->createJob($jobId, $command, $template, $target, $inputs, $userId, $username);
        
        // Execute synchronously
        $result = $this->executeSync($jobId, $command, $template, $target, $inputs, $userId, $username);
        return array_merge(['success' => true, 'jobId' => $jobId], $result);
    }
    
    /**
     * Generate a unique job ID
     */
    private function generateJobId(): string {
        return 'job-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4));
    }
    
    /**
     * Create a job record in the database
     */
    private function createJob(
        string $jobId,
        array $command,
        array $template,
        ?array $target,
        array $inputs,
        string $userId,
        string $username
    ): void {
        $stmt = $this->db->prepare("
            INSERT INTO " . self::JOBS_TABLE . " 
            (id, command_id, command_name, template_id, target_id, user_id, username, status, inputs, started_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'running', ?, datetime('now'))
        ");
        $stmt->execute([
            $jobId,
            $command['id'],
            $command['name'],
            $template['id'],
            $target['id'] ?? null,
            $userId,
            $username,
            json_encode($inputs)
        ]);
    }
    
    /**
     * Execute synchronously and return result
     */
    private function executeSync(string $jobId, array $command, array $template, ?array $target, array $inputs, string $userId, string $username): array {
        $output = '';
        $exitCode = 0;
        $error = null;
        
        try {
            // Enforce verified target capabilities
            $this->assertTargetVerified($target);

            // Resolve target bindings for script interpolation
            $resolvedBindings = $this->resolveTargetBindings($template, $target);
            $this->debug("Resolved target bindings", $resolvedBindings);
            
            // Merge inputs with resolved bindings for interpolation
            $interpolationVars = array_merge($inputs, $resolvedBindings);
            
            // Build the script with interpolated values
            $script = $this->interpolateScript($template['execution']['script'] ?? '', $interpolationVars);
            
            // Get secrets mapping from command (format: {ENV_VAR_NAME: secret-id})
            $secrets = $command['secrets'] ?? [];
            $this->debug("Command has " . count($secrets) . " secret mappings", array_keys($secrets));
            
            // Build environment variables (including secrets)
            $secretsUsed = [];
            $secretEnvMap = [];
            $envVars = $this->buildEnvVars($template, $target, $inputs, $secrets, $secretsUsed, $secretEnvMap);
            $this->logSecretUsage($jobId, $userId, $username, $secretsUsed, $secretEnvMap);
            
            // Determine execution method based on target type
            $targetType = $target['type'] ?? 'local';
            $this->debug("Executing with target type: {$targetType}");
            
            switch ($targetType) {
                case 'ssh':
                    // Execute via SSH
                    $result = $this->executeViaSsh($jobId, $template, $target, $script, $envVars);
                    break;
                    
                case 'docker-exec':
                    // Execute inside running container
                    $result = $this->executeViaDockerExec($jobId, $template, $target, $script, $envVars);
                    break;
                    
                case 'local':
                case 'docker':
                case 'http':
                default:
                    // Execute in fresh Docker container (default method)
                    $result = $this->executeViaDockerRun($jobId, $template, $target, $script, $envVars);
                    break;
            }
            
            $output = $result['output'];
            $exitCode = $result['exitCode'];
            $error = $result['error'] ?? null;
            
        } catch (Exception $e) {
            $this->logError("Execution failed", $e);
            $error = $e->getMessage();
            $exitCode = 1;
            $output .= "\nError: " . $error;
        } catch (Error $e) {
            // Catch PHP errors like undefined variables, type errors, etc.
            $this->logError("PHP Error during execution", $e);
            $error = "Internal error: " . $e->getMessage();
            $exitCode = 1;
            $output .= "\nInternal Error: " . $e->getMessage();
        }
        
        // Update job record
        $status = ($exitCode === 0 && !$error) ? 'completed' : 'failed';
        $this->updateJob($jobId, $status, $output, $exitCode, $error);
        
        return [
            'status' => $status,
            'output' => $output,
            'exitCode' => $exitCode,
            'error' => $error
        ];
    }

    /**
     * Enforce verified capabilities for a target
     */
    private function assertTargetVerified(?array $target): void {
        if (!$target) {
            return;
        }

        $required = $this->getRequiredCapabilityForTarget($target);
        if (!$required) {
            return;
        }

        $verified = $target['verifiedCapabilities'] ?? [];
        if (!in_array($required, $verified, true)) {
            $targetId = $target['id'] ?? 'unknown';
            throw new Exception("Target '{$targetId}' is not verified for {$required}. Verify it in Targets.");
        }
    }

    /**
     * Map target type to required capability
     */
    private function getRequiredCapabilityForTarget(array $target): ?string {
        $type = $target['type'] ?? '';
        return match ($type) {
            'docker', 'local', 'docker-exec' => 'exec.docker',
            'ssh' => 'exec.ssh',
            'http' => 'http.call',
            'kubernetes' => 'kubernetes.exec',
            default => null,
        };
    }
    
    /**
     * Execute via Docker run (default method for local/docker/http targets)
     */
    private function executeViaDockerRun(string $jobId, array $template, ?array $target, string $script, array $envVars): array {
        $image = $template['runtime']['image'] ?? 'alpine:latest';
        
        // Prepare container (pull image if needed)
        $this->debug("Preparing container image: {$image}");
        $prepResult = $this->prepareContainer($image);
        if (!$prepResult['success']) {
            throw new Exception("Failed to prepare container: " . $prepResult['error']);
        }
        
        // Build Docker command
        $dockerCmd = $this->buildDockerCommand($image, $script, $envVars, $template, $target, $jobId);
        
        return $this->runCommand($dockerCmd);
    }
    
    /**
     * Execute via SSH into remote host
     */
    private function executeViaSsh(string $jobId, array $template, array $target, string $script, array $envVars): array {
        $connection = $target['connection'] ?? [];
        $host = $connection['host'] ?? null;
        $port = $connection['port'] ?? 22;
        $user = $connection['user'] ?? null;
        $keyRef = $connection['keyRef'] ?? $connection['keySecret'] ?? null;
        $passwordRef = $connection['passwordRef'] ?? $connection['passwordSecret'] ?? null;
        
        if (!$host || !$user) {
            throw new Exception('SSH target requires host and user');
        }
        
        // Get SSH key
        $sshKey = null;
        if ($keyRef) {
            $sshKey = $this->config->getSecret($keyRef);
            if (!$sshKey) {
                throw new Exception("SSH key secret '{$keyRef}' not found");
            }
        }

        $sshPassword = null;
        if ($passwordRef) {
            $sshPassword = $this->config->getSecret($passwordRef);
            if ($sshPassword === null) {
                throw new Exception("SSH password secret '{$passwordRef}' not found");
            }
        }
        
        // Build the SSH wrapper script
        // This runs in a container with SSH client, SSHs to the target, and runs the command
        $sshScript = $this->buildSshExecutionScript($host, $port, $user, $sshKey, $sshPassword, $script, $envVars);
        
        // Use alpine/ssh image for SSH execution
        $image = 'alpine/ssh:latest';
        
        // Prepare container
        $prepResult = $this->prepareContainer($image);
        if (!$prepResult['success']) {
            throw new Exception("Failed to prepare SSH container: " . $prepResult['error']);
        }
        
        // Build minimal Docker run command for SSH execution
        $cmd = ['docker', 'run', '--rm'];
        $cmd[] = '--label';
        $cmd[] = 'doki.job.id=' . $jobId;
        $cmd[] = '--network';
        $cmd[] = 'host';
        $cmd[] = $image;
        $cmd[] = '/bin/sh';
        $cmd[] = '-c';
        $cmd[] = $sshScript;
        
        $timeout = $template['execution']['timeout'] ?? 300;
        $dockerCmd = "timeout {$timeout}s " . implode(' ', array_map('escapeshellarg', $cmd));
        
        $this->debug("SSH Docker command: {$dockerCmd}");
        
        return $this->runCommand($dockerCmd);
    }
    
    /**
     * Build SSH execution script
     */
    private function buildSshExecutionScript(string $host, int $port, string $user, ?string $sshKey, ?string $sshPassword, string $script, array $envVars): string {
        $parts = [];
        
        // Setup SSH key if provided
        if ($sshKey) {
            $parts[] = 'mkdir -p /root/.ssh';
            // Use printf to avoid issues with special characters
            $parts[] = 'printf %s "' . addslashes($sshKey) . '" > /root/.ssh/id_rsa';
            $parts[] = 'chmod 600 /root/.ssh/id_rsa';
        }

        if ($sshPassword !== null) {
            $parts[] = 'printf %s "' . addslashes($sshPassword) . '" > /tmp/ssh_pass';
            $parts[] = 'chmod 600 /tmp/ssh_pass';
            $parts[] = 'printf "%s\n" "#!/bin/sh" "cat /tmp/ssh_pass" > /tmp/ssh_askpass';
            $parts[] = 'chmod 700 /tmp/ssh_askpass';
            $parts[] = 'export SSH_ASKPASS=/tmp/ssh_askpass';
            $parts[] = 'export SSH_ASKPASS_REQUIRE=force';
            $parts[] = 'export DISPLAY=doki';
        }
        
        // Build the remote command with environment variables
        $envExports = [];
        foreach ($envVars as $env) {
            $envExports[] = 'export ' . $env;
        }
        
        $remoteScript = implode("; ", $envExports) . "; " . $script;
        
        // SSH options
        $sshOpts = '-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=30';
        if ($sshPassword !== null) {
            $sshOpts .= ' -o BatchMode=no -o PreferredAuthentications=publickey,password,keyboard-interactive -o PasswordAuthentication=yes';
        } else {
            $sshOpts .= ' -o BatchMode=yes';
        }
        
        // Build SSH command
        $sshCmd = sprintf(
            'ssh %s -p %d %s@%s %s',
            $sshOpts,
            $port,
            escapeshellarg($user),
            escapeshellarg($host),
            escapeshellarg($remoteScript)
        );
        
        $parts[] = $sshCmd;
        
        return implode(' && ', $parts);
    }
    
    /**
     * Execute via docker exec into running container
     */
    private function executeViaDockerExec(string $jobId, array $template, array $target, string $script, array $envVars): array {
        $connection = $target['connection'] ?? [];
        $container = $connection['container'] ?? null;
        $workdir = $connection['workdir'] ?? null;
        $execUser = $connection['user'] ?? null;
        
        if (!$container) {
            throw new Exception('Docker-exec target requires container name/ID');
        }
        
        // Build docker exec command
        $cmd = ['docker', 'exec'];
        
        // Add labels via environment (docker exec doesn't support labels)
        $cmd[] = '-e';
        $cmd[] = 'DOKI_JOB_ID=' . $jobId;
        
        // Add environment variables
        foreach ($envVars as $env) {
            $cmd[] = '-e';
            $cmd[] = $env;
        }
        
        // Working directory
        if ($workdir) {
            $cmd[] = '-w';
            $cmd[] = $workdir;
        }
        
        // User
        if ($execUser) {
            $cmd[] = '-u';
            $cmd[] = $execUser;
        }
        
        // Container
        $cmd[] = $container;
        
        // Command
        $cmd[] = '/bin/sh';
        $cmd[] = '-c';
        $cmd[] = $script;
        
        $timeout = $template['execution']['timeout'] ?? 300;
        $dockerCmd = "timeout {$timeout}s " . implode(' ', array_map('escapeshellarg', $cmd));
        
        $this->debug("Docker exec command: {$dockerCmd}");
        
        return $this->runCommand($dockerCmd);
    }
    
    /**
     * Run a shell command and capture output
     */
    private function runCommand(string $cmd): array {
        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];
        
        $process = proc_open($cmd, $descriptors, $pipes);
        
        if (!is_resource($process)) {
            throw new Exception('Failed to start process');
        }
        
        fclose($pipes[0]); // Close stdin
        
        // Read stdout and stderr
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        
        fclose($pipes[1]);
        fclose($pipes[2]);
        
        $exitCode = proc_close($process);
        
        // Build output
        $output = $stdout;
        if ($stderr) {
            $filteredStderr = $this->filterDockerMessages($stderr);
            if ($filteredStderr) {
                $output .= "\n--- STDERR ---\n" . $filteredStderr;
            }
        }
        
        return [
            'output' => $output,
            'exitCode' => $exitCode,
            'error' => $exitCode !== 0 ? "Command exited with code {$exitCode}" : null
        ];
    }
    
    /**
     * Execute asynchronously (background)
     */
    private function executeAsync(string $jobId): void {
        // For async, we'd spawn a background process
        // For now, we'll use a simple approach with nohup
        $scriptPath = __DIR__ . '/../execute-job.php';
        $cmd = sprintf(
            'nohup php %s %s > /dev/null 2>&1 &',
            escapeshellarg($scriptPath),
            escapeshellarg($jobId)
        );
        exec($cmd);
    }
    
    /**
     * Resolve target bindings from template definition
     * Maps template's targetBindings to actual values from the target
     */
    private function resolveTargetBindings(array $template, ?array $target): array {
        $resolved = [];
        
        if (!$target || !isset($template['targetBindings'])) {
            return $resolved;
        }
        
        foreach ($template['targetBindings'] as $varName => $binding) {
            $value = $this->resolveBinding($binding, $target);
            if ($value !== null) {
                $resolved[$varName] = $value;
            }
        }
        
        return $resolved;
    }
    
    /**
     * Prepare container by pulling the image if needed
     * This separates infrastructure setup from user command execution
     */
    private function prepareContainer(string $image): array {
        $this->debug("Checking/pulling image: {$image}");
        
        // Check if image exists locally
        $checkCmd = sprintf('docker image inspect %s > /dev/null 2>&1', escapeshellarg($image));
        $checkResult = null;
        exec($checkCmd, $output, $checkResult);
        
        if ($checkResult === 0) {
            $this->debug("Image already exists locally: {$image}");
            return ['success' => true, 'pulled' => false];
        }
        
        // Image doesn't exist, need to pull it
        $this->debug("Pulling image: {$image}");
        $pullCmd = sprintf('docker pull %s 2>&1', escapeshellarg($image));
        
        $pullOutput = [];
        $pullResult = null;
        exec($pullCmd, $pullOutput, $pullResult);
        
        if ($pullResult !== 0) {
            $errorMsg = implode("\n", $pullOutput);
            $this->logError("Failed to pull image: {$image}", null);
            return ['success' => false, 'error' => "Failed to pull image {$image}: {$errorMsg}"];
        }
        
        $this->debug("Successfully pulled image: {$image}");
        return ['success' => true, 'pulled' => true];
    }
    
    /**
     * Filter out Docker infrastructure messages from stderr
     * Users should only see their command's output, not Docker pull progress etc.
     */
    private function filterDockerMessages(string $stderr): string {
        $lines = explode("\n", $stderr);
        $filtered = [];
        
        foreach ($lines as $line) {
            // Skip Docker pull/image messages
            if (preg_match('/^(Unable to find image|Pulling from|[a-f0-9]+: (Pulling|Verifying|Download|Pull|Waiting|Extracting)|Digest:|Status:|latest:)/i', trim($line))) {
                continue;
            }
            // Skip empty lines at start
            if (empty($filtered) && trim($line) === '') {
                continue;
            }
            $filtered[] = $line;
        }
        
        // Remove trailing empty lines
        while (!empty($filtered) && trim(end($filtered)) === '') {
            array_pop($filtered);
        }
        
        return implode("\n", $filtered);
    }
    
    /**
     * Build Docker run command
     */
    private function buildDockerCommand(
        string $image,
        string $script,
        array $envVars,
        array $template,
        ?array $target,
        string $jobId
    ): string {
        $cmd = ['docker', 'run', '--rm'];
        
        // Add labels for tracking
        $cmd[] = '--label';
        $cmd[] = 'doki.job.id=' . $jobId;
        $cmd[] = '--label';
        $cmd[] = 'doki.template=' . $template['id'];
        
        // Add environment variables
        foreach ($envVars as $env) {
            $cmd[] = '-e';
            $cmd[] = $env;
        }

        $envMap = $this->buildEnvMap($envVars);

        // Add volumes based on target type
        $needsDockerAccess = false;
        if ($target) {
            $targetVolumes = $this->getTargetVolumes($target);
            foreach ($targetVolumes as $vol) {
                $cmd[] = '-v';
                $cmd[] = $vol;
                // Check if we're mounting docker socket
                if (strpos($vol, 'docker.sock') !== false) {
                    $needsDockerAccess = true;
                }
            }
        }

        $templateVolumes = $this->getTemplateVolumes($template, $envMap);
        foreach ($templateVolumes as $vol) {
            $cmd[] = '-v';
            $cmd[] = $vol;
        }
        
        // If target needs Docker access, grant privileged access
        // This is required for Docker-in-Docker scenarios
        if ($needsDockerAccess) {
            $cmd[] = '--privileged';
        }
        
        // Resource limits
        $timeout = $template['execution']['timeout'] ?? 300;
        
        // Network mode
        $network = $template['runtime']['network'] ?? 'host';
        $cmd[] = '--network';
        $cmd[] = $network;
        
        // Working directory
        $workdir = $template['runtime']['workdir'] ?? '/workspace';
        $cmd[] = '-w';
        $cmd[] = $workdir;
        
        // Image
        $cmd[] = $image;
        
        // Command to run
        $cmd[] = '/bin/sh';
        $cmd[] = '-c';
        $cmd[] = $script;
        
        // Build final command with timeout
        $dockerCmd = implode(' ', array_map('escapeshellarg', $cmd));
        $fullCmd = "timeout {$timeout}s {$dockerCmd}";
        
        // Log the command for debugging
        error_log("Orchestrator: Running Docker command: " . $fullCmd);
        
        return $fullCmd;
    }

    private function buildEnvMap(array $envVars): array {
        $envMap = [];
        foreach ($envVars as $envVar) {
            if (!is_string($envVar) || $envVar === '') {
                continue;
            }
            $parts = explode('=', $envVar, 2);
            $key = trim((string)($parts[0] ?? ''));
            if ($key === '') {
                continue;
            }
            $envMap[$key] = $parts[1] ?? '';
        }
        return $envMap;
    }

    private function getTemplateVolumes(array $template, array $envMap): array {
        $runtimeVolumes = $template['runtime']['volumes'] ?? [];
        if (!is_array($runtimeVolumes) || $runtimeVolumes === []) {
            return [];
        }

        $volumes = [];
        foreach ($runtimeVolumes as $volume) {
            if (!is_array($volume)) {
                continue;
            }
            $host = $this->expandRuntimeValue(trim((string)($volume['host'] ?? '')), $envMap);
            $container = $this->expandRuntimeValue(trim((string)($volume['container'] ?? '')), $envMap);
            if ($host === '' || $container === '') {
                continue;
            }
            $mount = $host . ':' . $container;
            if (!empty($volume['readonly'])) {
                $mount .= ':ro';
            }
            $volumes[] = $mount;
        }

        return $volumes;
    }

    private function expandRuntimeValue(string $value, array $envMap): string {
        if ($value === '') {
            return '';
        }

        return preg_replace_callback('/\$\{([A-Za-z0-9_]+)\}/', static function (array $matches) use ($envMap) {
            $key = $matches[1] ?? '';
            if ($key !== '' && array_key_exists($key, $envMap)) {
                return (string)$envMap[$key];
            }
            return $matches[0];
        }, $value) ?? $value;
    }
    
    /**
     * Get volume mounts for a target
     */
    private function getTargetVolumes(?array $target): array {
        $volumes = [];
        
        if (!$target) {
            error_log("Orchestrator: No target provided for volumes");
            return $volumes;
        }
        
        error_log("Orchestrator: Getting volumes for target type: " . ($target['type'] ?? 'unknown'));
        
        switch ($target['type']) {
            case 'docker':
            case 'local':
                // Mount Docker socket for local/docker targets
                $socket = $target['connection']['socket'] ?? '/var/run/docker.sock';
                $volumes[] = $socket . ':/var/run/docker.sock';
                error_log("Orchestrator: Adding docker socket volume: " . $volumes[0]);
                break;
        }
        
        return $volumes;
    }
    
    /**
     * Build environment variables for execution
     */
    private function buildEnvVars(array $template, ?array $target, array $inputs, array $secrets = [], array &$secretsUsed = [], array &$secretEnvMap = []): array {
        $envVars = [];
        $secretsUsed = [];
        $secretEnvMap = [];
        
        // Add all inputs as environment variables (prefixed)
        foreach ($inputs as $key => $value) {
            if (is_scalar($value)) {
                $envVars[] = 'INPUT_' . strtoupper($key) . '=' . $value;
            }
        }
        
        // Add secrets as environment variables
        // Format: secrets = ['MY_API_KEY' => 'api-key-id', 'DB_PASSWORD' => 'db-prod-password']
        error_log("Orchestrator: Processing " . count($secrets) . " secret mappings: " . json_encode(array_keys($secrets)));
        foreach ($secrets as $envName => $secretId) {
            error_log("Orchestrator: Mapping secret '{$secretId}' to env var '{$envName}'");
            $secretValue = $this->config->getSecret($secretId);
            if ($secretValue !== null) {
                $envVars[] = $envName . '=' . $secretValue;
                error_log("Orchestrator: Secret '{$secretId}' found, injecting as {$envName}");
                $secretsUsed[] = $secretId;
                $secretEnvMap[$envName] = $secretId;
            } else {
                error_log("Orchestrator: Secret not found: {$secretId}");
            }
        }
        
        // Add target bindings
        if ($target && isset($template['targetBindings'])) {
            foreach ($template['targetBindings'] as $envName => $binding) {
                $value = $this->resolveBinding($binding, $target);
                if ($value !== null) {
                    $envVars[] = $envName . '=' . $value;
                }
            }
        }
        
        // Add target connection details
        if ($target) {
            switch ($target['type']) {
                case 'http':
                    if (isset($target['connection']['baseUrl'])) {
                        $envVars[] = 'HTTP_ENDPOINT=' . $target['connection']['baseUrl'];
                    }
                    // Handle auth with secret resolution
                    if (isset($target['connection']['auth'])) {
                        $auth = $target['connection']['auth'];
                        if ($auth['type'] === 'basic') {
                            $envVars[] = 'HTTP_AUTH_TYPE=basic';
                            // Resolve username/password secrets if specified
                            if (isset($auth['usernameSecret'])) {
                                $username = $this->config->getSecret($auth['usernameSecret']);
                                if ($username) {
                                    $envVars[] = 'HTTP_AUTH_USERNAME=' . $username;
                                    $secretsUsed[] = $auth['usernameSecret'];
                                    $secretEnvMap['HTTP_AUTH_USERNAME'] = $auth['usernameSecret'];
                                }
                            }
                            if (isset($auth['passwordSecret'])) {
                                $password = $this->config->getSecret($auth['passwordSecret']);
                                if ($password) {
                                    $envVars[] = 'HTTP_AUTH_PASSWORD=' . $password;
                                    $secretsUsed[] = $auth['passwordSecret'];
                                    $secretEnvMap['HTTP_AUTH_PASSWORD'] = $auth['passwordSecret'];
                                }
                            }
                        } elseif ($auth['type'] === 'bearer') {
                            $envVars[] = 'HTTP_AUTH_TYPE=bearer';
                            if (isset($auth['tokenSecret'])) {
                                $token = $this->config->getSecret($auth['tokenSecret']);
                                if ($token) {
                                    $envVars[] = 'HTTP_AUTH_TOKEN=' . $token;
                                    $secretsUsed[] = $auth['tokenSecret'];
                                    $secretEnvMap['HTTP_AUTH_TOKEN'] = $auth['tokenSecret'];
                                }
                            }
                        }
                    }
                    break;
                    
                case 'ssh':
                    if (isset($target['connection']['host'])) {
                        $envVars[] = 'SSH_HOST=' . $target['connection']['host'];
                    }
                    if (isset($target['connection']['port'])) {
                        $envVars[] = 'SSH_PORT=' . $target['connection']['port'];
                    }
                    if (isset($target['connection']['user'])) {
                        $envVars[] = 'SSH_USER=' . $target['connection']['user'];
                    }
                    // SSH key from secrets
                    $keyRef = $target['connection']['keyRef'] ?? $target['connection']['keySecret'] ?? null;
                    if ($keyRef) {
                        $key = $this->config->getSecret($keyRef);
                        if ($key) {
                            $envVars[] = 'SSH_PRIVATE_KEY=' . $key;
                            $secretsUsed[] = $keyRef;
                            $secretEnvMap['SSH_PRIVATE_KEY'] = $keyRef;
                        }
                    }
                    break;
            }
        }
        
        return $envVars;
    }

    /**
     * Log secret usage for an execution (names only)
     */
    private function logSecretUsage(string $jobId, string $userId, string $username, array $secretsUsed, array $secretEnvMap): void {
        $secretsUsed = array_values(array_unique(array_filter($secretsUsed)));
        if (empty($secretsUsed) && empty($secretEnvMap)) {
            return;
        }

        $details = json_encode([
            'secrets_used' => $secretsUsed,
            'env_map' => $secretEnvMap
        ]);

        try {
            $auth = new Auth();
            $auth->auditLog($userId, $username, 'secret_use', 'execution', $jobId, $details);
        } catch (Exception $e) {
            // Avoid breaking execution if audit log fails
            error_log("Orchestrator: Failed to log secret usage - " . $e->getMessage());
        }
    }
    
    /**
     * Resolve a target binding
     */
    private function resolveBinding(array $binding, array $target): ?string {
        $path = $binding['from'] ?? '';
        $parts = explode('.', $path);
        
        // Navigate to the value
        $value = $target;
        foreach ($parts as $part) {
            if ($part === 'target') continue;
            if (!isset($value[$part])) return null;
            $value = $value[$part];
        }
        
        return is_scalar($value) ? (string)$value : null;
    }
    
    /**
     * Interpolate template variables in script
     */
    private function interpolateScript(string $script, array $inputs): string {
        $result = $script;
        
        // Simple variable replacement: {{varName}}
        // NOTE: Don't escape here - the final command builder handles escaping
        foreach ($inputs as $key => $value) {
            if (is_scalar($value)) {
                $result = str_replace('{{' . $key . '}}', (string)$value, $result);
            }
        }
        
        // Conditional blocks: {{#if condition}}...{{/if}}
        $result = preg_replace_callback(
            '/\{\{#if (\w+)\}\}([\s\S]*?)\{\{\/if\}\}/',
            function($matches) use ($inputs) {
                $condition = $matches[1];
                $content = $matches[2];
                $value = $inputs[$condition] ?? null;
                return ($value && $value !== 'false' && $value !== '') ? $content : '';
            },
            $result
        );
        
        return $result;
    }
    
    /**
     * Escape shell special characters
     */
    private function escapeShell(string $str): string {
        // Don't double-escape already quoted values
        if (preg_match('/^["\'].*["\']$/', $str)) {
            return $str;
        }
        return escapeshellarg($str);
    }
    
    /**
     * Update job record
     */
    private function updateJob(string $jobId, string $status, string $output, int $exitCode, ?string $error): void {
        // Truncate output if too long
        if (strlen($output) > self::MAX_OUTPUT_LENGTH) {
            $output = substr($output, 0, self::MAX_OUTPUT_LENGTH) . "\n... (output truncated)";
        }
        
        $stmt = $this->db->prepare("
            UPDATE " . self::JOBS_TABLE . "
            SET status = ?, output = ?, exit_code = ?, error = ?, completed_at = datetime('now')
            WHERE id = ?
        ");
        $stmt->execute([$status, $output, $exitCode, $error, $jobId]);
    }
    
    /**
     * Get job by ID
     */
    public function getJob(string $jobId): ?array {
        $stmt = $this->db->prepare("SELECT * FROM " . self::JOBS_TABLE . " WHERE id = ?");
        $stmt->execute([$jobId]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($job) {
            $job['inputs'] = json_decode($job['inputs'], true);
        }
        
        return $job ?: null;
    }
    
    /**
     * Get recent jobs for a user
     */
    public function getJobs(?string $userId = null, int $limit = 50): array {
        if ($userId) {
            $stmt = $this->db->prepare("
                SELECT * FROM " . self::JOBS_TABLE . "
                WHERE user_id = ?
                ORDER BY created_at DESC
                LIMIT ?
            ");
            $stmt->execute([$userId, $limit]);
        } else {
            $stmt = $this->db->prepare("
                SELECT * FROM " . self::JOBS_TABLE . "
                ORDER BY created_at DESC
                LIMIT ?
            ");
            $stmt->execute([$limit]);
        }
        
        $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($jobs as &$job) {
            $job['inputs'] = json_decode($job['inputs'], true);
        }
        
        return $jobs;
    }
    
    /**
     * Cancel a running job
     */
    public function cancelJob(string $jobId): array {
        $job = $this->getJob($jobId);
        if (!$job) {
            return ['success' => false, 'error' => 'Job not found'];
        }
        
        if ($job['status'] !== 'running' && $job['status'] !== 'pending') {
            return ['success' => false, 'error' => 'Job is not running'];
        }
        
        // Try to kill the Docker container
        $cmd = sprintf('docker kill $(docker ps -q --filter "label=doki.job.id=%s") 2>/dev/null', escapeshellarg($jobId));
        exec($cmd);
        
        // Update status
        $this->updateJob($jobId, 'cancelled', $job['output'] . "\n--- Cancelled by user ---", -1, 'Cancelled');
        
        return ['success' => true];
    }
    
    /**
     * Clean up old jobs
     */
    public function cleanupOldJobs(int $daysToKeep = 30): int {
        $stmt = $this->db->prepare("
            DELETE FROM " . self::JOBS_TABLE . "
            WHERE created_at < datetime('now', '-' || ? || ' days')
        ");
        $stmt->execute([$daysToKeep]);
        return $stmt->rowCount();
    }
    
    /**
     * Get target by ID
     */
    public function getTargetById(string $targetId): ?array {
        $targetsList = $this->config->getTargets();
        $this->debug("Looking for target '{$targetId}' in " . count($targetsList) . " targets");
        
        foreach ($targetsList as $t) {
            if (($t['id'] ?? '') === $targetId) {
                $this->debug("Found target: " . json_encode($t));
                return $t;
            }
        }
        
        return null;
    }
    
    /**
     * Resolve target ID from environment mapping
     * 
     * Resolution order:
     * 1. If targetType specified, find a target whose actual type matches
     * 2. Fall back to environment.targets['default']
     * 3. Fall back to first target in environment
     */
    public function resolveTargetFromEnvironment(string $environmentId, ?string $targetType = null): ?string {
        $environments = $this->config->getEnvironments();
        
        // Environments use key as ID
        $env = $environments[$environmentId] ?? null;
        if (!$env) {
            $this->debug("Environment not found: {$environmentId}");
            return null;
        }
        
        $envTargets = $env['targets'] ?? [];
        if (empty($envTargets)) {
            $this->debug("Environment '{$environmentId}' has no targets");
            return null;
        }
        
        // 1. If targetType specified, find a target whose ACTUAL type matches
        if ($targetType) {
            foreach ($envTargets as $role => $targetId) {
                $target = $this->getTargetById($targetId);
                if ($target && ($target['type'] ?? '') === $targetType) {
                    $this->debug("Found target '{$targetId}' with type '{$targetType}' (role: {$role})");
                    return $targetId;
                }
            }
            $this->debug("No target with type '{$targetType}' found in environment");
        }
        
        // 2. Fall back to 'default' key
        if (isset($envTargets['default'])) {
            $this->debug("Using default target: " . $envTargets['default']);
            return $envTargets['default'];
        }
        
        // 3. Fall back to first target
        $firstTarget = reset($envTargets);
        $this->debug("Using first target: " . $firstTarget);
        return $firstTarget;
    }
    
    /**
     * Get all environments
     */
    public function getEnvironments(): array {
        return $this->config->getEnvironments();
    }
    
    /**
     * Test connection to a target
     */
    public function testConnection(string $targetId): array {
        $target = $this->getTargetById($targetId);
        
        if (!$target) {
            return ['success' => false, 'error' => 'Target not found'];
        }
        
        $type = $target['type'] ?? 'unknown';
        $connection = $target['connection'] ?? [];
        
        $this->debug("Testing connection for target type: {$type}", $connection);
        
        switch ($type) {
            case 'local':
                // Local always works
                return ['success' => true, 'message' => 'Local target is always available'];
                
            case 'docker':
                return $this->testDockerConnection($connection);
                
            case 'ssh':
                return $this->testSshConnection($connection);
                
            case 'docker-exec':
                return $this->testDockerExecConnection($connection);
                
            case 'http':
                return $this->testHttpConnection($connection);
                
            default:
                return ['success' => false, 'error' => "Unknown target type: {$type}"];
        }
    }
    
    /**
     * Test Docker socket connection
     */
    private function testDockerConnection(array $connection): array {
        $socket = $connection['socket'] ?? '/var/run/docker.sock';
        
        // Test by listing containers
        $cmd = sprintf('docker -H unix://%s info --format "{{.ServerVersion}}" 2>&1', escapeshellarg($socket));
        $output = [];
        $exitCode = null;
        exec($cmd, $output, $exitCode);
        
        if ($exitCode === 0) {
            $version = implode('', $output);
            return ['success' => true, 'message' => "Docker connected (v{$version})"];
        } else {
            return ['success' => false, 'error' => 'Cannot connect to Docker: ' . implode("\n", $output)];
        }
    }
    
    /**
     * Test SSH connection
     */
    private function testSshConnection(array $connection): array {
        $host = $connection['host'] ?? null;
        $port = $connection['port'] ?? 22;
        $user = $connection['user'] ?? null;
        $keyRef = $connection['keyRef'] ?? $connection['keySecret'] ?? null;
        $passwordRef = $connection['passwordRef'] ?? $connection['passwordSecret'] ?? null;
        
        if (!$host || !$user) {
            return ['success' => false, 'error' => 'SSH connection requires host and user'];
        }
        
        // Get SSH key from secrets
        $sshKey = null;
        if ($keyRef) {
            $sshKey = $this->config->getSecret($keyRef);
            if (!$sshKey) {
                return ['success' => false, 'error' => "SSH key secret '{$keyRef}' not found"];
            }
        }

        $sshPassword = null;
        if ($passwordRef) {
            $sshPassword = $this->config->getSecret($passwordRef);
            if ($sshPassword === null) {
                return ['success' => false, 'error' => "SSH password secret '{$passwordRef}' not found"];
            }
        }
        
        // Build SSH test command
        // We'll run a simple command via a Docker container with SSH client
        $testScript = $this->buildSshTestScript($host, $port, $user, $sshKey, $sshPassword);
        
        // Run in Alpine container with openssh-client
        $dockerCmd = sprintf(
            'docker run --rm --network host alpine/ssh:latest /bin/sh -c %s 2>&1',
            escapeshellarg($testScript)
        );
        
        $output = [];
        $exitCode = null;
        exec($dockerCmd, $output, $exitCode);
        
        if ($exitCode === 0) {
            return ['success' => true, 'message' => 'SSH connection successful'];
        } else {
            return ['success' => false, 'error' => 'SSH connection failed: ' . implode("\n", $output)];
        }
    }
    
    /**
     * Build SSH test script
     */
    private function buildSshTestScript(string $host, int $port, string $user, ?string $sshKey, ?string $sshPassword): string {
        $script = '';
        
        if ($sshKey) {
            // Write key to temp file
            $script .= 'mkdir -p /root/.ssh && ';
            $script .= 'echo "' . addslashes($sshKey) . '" > /root/.ssh/id_rsa && ';
            $script .= 'chmod 600 /root/.ssh/id_rsa && ';
        }

        if ($sshPassword !== null) {
            $script .= 'echo "' . addslashes($sshPassword) . '" > /tmp/ssh_pass && ';
            $script .= 'chmod 600 /tmp/ssh_pass && ';
            $script .= 'printf "%s\n" "#!/bin/sh" "cat /tmp/ssh_pass" > /tmp/ssh_askpass && ';
            $script .= 'chmod 700 /tmp/ssh_askpass && ';
            $script .= 'export SSH_ASKPASS=/tmp/ssh_askpass && ';
            $script .= 'export SSH_ASKPASS_REQUIRE=force && ';
            $script .= 'export DISPLAY=doki && ';
        }
        
        // SSH options to skip host key checking for testing
        $sshOpts = '-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=10';
        if ($sshPassword !== null) {
            $sshOpts .= ' -o BatchMode=no -o PreferredAuthentications=publickey,password,keyboard-interactive -o PasswordAuthentication=yes';
        } else {
            $sshOpts .= ' -o BatchMode=yes';
        }
        
        $script .= sprintf(
            'ssh %s -p %d %s@%s echo "connected"',
            $sshOpts,
            $port,
            escapeshellarg($user),
            escapeshellarg($host)
        );
        
        return $script;
    }
    
    /**
     * Test docker-exec connection (check if container is running)
     */
    private function testDockerExecConnection(array $connection): array {
        $container = $connection['container'] ?? null;
        
        if (!$container) {
            return ['success' => false, 'error' => 'Container name/ID required for docker-exec target'];
        }
        
        // Check if container is running
        $cmd = sprintf('docker inspect --format "{{.State.Running}}" %s 2>&1', escapeshellarg($container));
        $output = [];
        $exitCode = null;
        exec($cmd, $output, $exitCode);
        
        if ($exitCode === 0 && trim(implode('', $output)) === 'true') {
            return ['success' => true, 'message' => "Container '{$container}' is running"];
        } else if ($exitCode === 0) {
            return ['success' => false, 'error' => "Container '{$container}' exists but is not running"];
        } else {
            return ['success' => false, 'error' => "Container '{$container}' not found"];
        }
    }
    
    /**
     * Test HTTP endpoint connection
     */
    private function testHttpConnection(array $connection): array {
        $baseUrl = $connection['baseUrl'] ?? null;
        
        if (!$baseUrl) {
            return ['success' => false, 'error' => 'Base URL required for HTTP target'];
        }
        
        // Simple health check - try to reach the URL
        $cmd = sprintf(
            'curl -s -o /dev/null -w "%%{http_code}" --max-time 10 %s 2>&1',
            escapeshellarg($baseUrl)
        );
        
        $output = [];
        $exitCode = null;
        exec($cmd, $output, $exitCode);
        
        $statusCode = intval(implode('', $output));
        
        if ($statusCode > 0 && $statusCode < 500) {
            return ['success' => true, 'message' => "HTTP endpoint reachable (status: {$statusCode})"];
        } else if ($statusCode >= 500) {
            return ['success' => false, 'error' => "HTTP endpoint error (status: {$statusCode})"];
        } else {
            return ['success' => false, 'error' => 'Cannot reach HTTP endpoint'];
        }
    }
}
