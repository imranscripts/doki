<?php
/**
 * CommandsManager - Manages user-defined commands
 * Commands link templates to targets with specific input defaults
 */

class CommandsManager {
    private const COMMANDS_FILE = __DIR__ . '/../data/config/commands.yaml';
    private const HISTORY_DIR = __DIR__ . '/../data/config/.history';
    
    private $auth;
    
    public function __construct(?Auth $auth = null) {
        $this->auth = $auth;
    }
    
    /**
     * Get all commands
     */
    public function getCommands(): array {
        $file = self::COMMANDS_FILE;
        
        if (!file_exists($file)) {
            return ['success' => true, 'commands' => []];
        }
        
        $content = @file_get_contents($file);
        if ($content === false) {
            return ['success' => false, 'error' => 'Failed to read commands file', 'commands' => []];
        }
        
        if (function_exists('yaml_parse')) {
            $data = @yaml_parse($content);
        } else {
            // Basic fallback
            $data = $this->parseCommandsManual($content);
        }
        
        if ($data === false) {
            return ['success' => false, 'error' => 'Failed to parse commands YAML', 'commands' => []];
        }
        
        $commands = $data['commands'] ?? [];
        
        return ['success' => true, 'commands' => $commands];
    }
    
    /**
     * Get a single command by ID
     */
    public function getCommand(string $id): ?array {
        $result = $this->getCommands();
        if (!$result['success']) return null;
        
        foreach ($result['commands'] as $command) {
            if ($command['id'] === $id) {
                return $command;
            }
        }
        
        return null;
    }
    
    /**
     * Save a command (create or update)
     */
    public function saveCommand(array $command, ?string $userId = null, string $username = 'system'): array {
        // Validate
        $validation = $this->validateCommand($command);
        if (!$validation['valid']) {
            return ['success' => false, 'errors' => $validation['errors']];
        }
        
        // Ensure directory exists
        $dir = dirname(self::COMMANDS_FILE);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true)) {
                return ['success' => false, 'errors' => ['Failed to create config directory']];
            }
        }
        
        // Create backup before modifying
        $backupFile = null;
        if (file_exists(self::COMMANDS_FILE)) {
            $backupFile = $this->createBackup();
        }
        
        // Load existing commands
        $result = $this->getCommands();
        $commands = $result['commands'];
        
        // Normalize the command
        $command = $this->normalizeCommand($command);
        $command['updatedAt'] = date('c');
        $command['updatedBy'] = $username;
        
        // Find and update, or add new
        $found = false;
        foreach ($commands as $i => $existing) {
            if ($existing['id'] === $command['id']) {
                $command['createdAt'] = $existing['createdAt'] ?? date('c');
                $command['createdBy'] = $existing['createdBy'] ?? $username;
                $commands[$i] = $command;
                $found = true;
                break;
            }
        }
        
        if (!$found) {
            $command['createdAt'] = date('c');
            $command['createdBy'] = $username;
            $commands[] = $command;
        }
        
        // Save
        $data = ['commands' => $commands];
        
        if (function_exists('yaml_emit')) {
            $yaml = yaml_emit($data, YAML_UTF8_ENCODING);
            $yaml = $this->fixYamlMapEmpties($yaml);
        } else {
            $yaml = $this->emitYamlManual($data);
        }
        
        if (@file_put_contents(self::COMMANDS_FILE, $yaml) === false) {
            return ['success' => false, 'errors' => ['Failed to write commands file']];
        }
        
        // Log audit
        if ($this->auth) {
            $this->auth->auditLog(
                $userId,
                $username,
                $found ? 'command.update' : 'command.create',
                'command',
                $command['id'],
                json_encode(['name' => $command['name']])
            );
        }
        
        return [
            'success' => true, 
            'command' => $command,
            'backup' => $backupFile ? basename($backupFile) : null
        ];
    }
    
    /**
     * Delete a command
     */
    public function deleteCommand(string $id, ?string $userId = null, string $username = 'system'): array {
        // Create backup before modifying
        $backupFile = null;
        if (file_exists(self::COMMANDS_FILE)) {
            $backupFile = $this->createBackup();
        }
        
        $result = $this->getCommands();
        $commands = $result['commands'];
        
        $found = false;
        $deletedCommand = null;
        foreach ($commands as $i => $command) {
            if ($command['id'] === $id) {
                $deletedCommand = $command;
                array_splice($commands, $i, 1);
                $found = true;
                break;
            }
        }
        
        if (!$found) {
            return ['success' => false, 'error' => 'Command not found'];
        }
        
        // Save
        $data = ['commands' => $commands];
        
        if (function_exists('yaml_emit')) {
            $yaml = yaml_emit($data, YAML_UTF8_ENCODING);
            $yaml = $this->fixYamlMapEmpties($yaml);
        } else {
            $yaml = $this->emitYamlManual($data);
        }
        
        if (@file_put_contents(self::COMMANDS_FILE, $yaml) === false) {
            return ['success' => false, 'error' => 'Failed to write commands file'];
        }
        
        // Log audit
        if ($this->auth) {
            $this->auth->auditLog(
                $userId,
                $username,
                'command.delete',
                'command',
                $id,
                json_encode(['name' => $deletedCommand['name'] ?? $id])
            );
        }
        
        return ['success' => true, 'backup' => $backupFile ? basename($backupFile) : null];
    }
    
    /**
     * Validate a command
     */
    public function validateCommand(array $command): array {
        $errors = [];
        
        // Required fields
        if (empty($command['id'])) {
            $errors[] = 'Command ID is required';
        } elseif (!preg_match('/^[a-z0-9][a-z0-9-]*[a-z0-9]$|^[a-z0-9]$/i', $command['id'])) {
            $errors[] = 'Command ID must contain only alphanumeric characters and hyphens';
        }
        
        if (empty($command['name'])) {
            $errors[] = 'Command name is required';
        }

        // Hard cutover: legacy command shape is not supported.
        if (array_key_exists('templateId', $command)) {
            $errors[] = 'Legacy field templateId is not supported; use steps[]';
        }
        if (array_key_exists('targetId', $command)) {
            $errors[] = 'Legacy field targetId is not supported; use defaultTarget/steps[].target';
        }

        $executionMode = strtolower(trim((string)($command['executionMode'] ?? '')));
        if (!in_array($executionMode, ['sync', 'async'], true)) {
            $errors[] = 'executionMode must be sync or async';
        }

        $maxParallel = (int)($command['maxParallel'] ?? 0);
        if ($maxParallel <= 0) {
            $errors[] = 'maxParallel must be a positive integer';
        }

        $finalStatusPolicy = strtolower(trim((string)($command['finalStatusPolicy'] ?? '')));
        if (!in_array($finalStatusPolicy, ['fail_if_any_failed', 'completed_with_warnings'], true)) {
            $errors[] = 'finalStatusPolicy must be fail_if_any_failed or completed_with_warnings';
        }

        if (!$this->isValidTargetRef($command['defaultTarget'] ?? 'inherit')) {
            $errors[] = 'defaultTarget must be inherit, {targetId}, or {fromEnvironment}';
        }

        if (array_key_exists('environment', $command) && !is_null($command['environment']) && !is_string($command['environment'])) {
            $errors[] = 'environment must be a string or null';
        }

        if (array_key_exists('inputs', $command) && !is_array($command['inputs'])) {
            $errors[] = 'inputs must be an object';
        } elseif (is_array($command['inputs'] ?? null) && $this->isNonEmptyList($command['inputs'])) {
            $errors[] = 'inputs must be an object';
        }
        if (array_key_exists('secrets', $command) && !is_array($command['secrets'])) {
            $errors[] = 'secrets must be an object';
        } elseif (is_array($command['secrets'] ?? null) && $this->isNonEmptyList($command['secrets'])) {
            $errors[] = 'secrets must be an object';
        }
        if (array_key_exists('tags', $command) && !is_array($command['tags'])) {
            $errors[] = 'tags must be an array';
        }

        $steps = $command['steps'] ?? null;
        if (!is_array($steps) || empty($steps)) {
            $errors[] = 'steps must contain at least one step';
            return ['valid' => empty($errors), 'errors' => $errors];
        }

        $stepIds = [];
        foreach ($steps as $index => $step) {
            $label = 'Step #' . ($index + 1);
            if (!is_array($step)) {
                $errors[] = "$label must be an object";
                continue;
            }

            $stepId = trim((string)($step['id'] ?? ''));
            if ($stepId === '') {
                $errors[] = "$label id is required";
            } elseif (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9-_]*$/', $stepId)) {
                $errors[] = "$label id must be alphanumeric with dashes/underscores";
            } elseif (isset($stepIds[$stepId])) {
                $errors[] = "Duplicate step id: $stepId";
            } else {
                $stepIds[$stepId] = $index;
            }

            $templateId = trim((string)($step['templateId'] ?? ''));
            if ($templateId === '') {
                $errors[] = "$label templateId is required";
            }

            $onFailure = strtolower(trim((string)($step['onFailure'] ?? 'stop')));
            if (!in_array($onFailure, ['stop', 'continue'], true)) {
                $errors[] = "$label onFailure must be stop or continue";
            }

            if (!$this->isValidTargetRef($step['target'] ?? 'inherit')) {
                $errors[] = "$label target must be inherit, {targetId}, or {fromEnvironment}";
            }

            if (isset($step['dependsOn']) && !is_array($step['dependsOn'])) {
                $errors[] = "$label dependsOn must be an array";
            }
            if (isset($step['inputs']) && !is_array($step['inputs'])) {
                $errors[] = "$label inputs must be an object";
            } elseif (is_array($step['inputs'] ?? null) && $this->isNonEmptyList($step['inputs'])) {
                $errors[] = "$label inputs must be an object";
            }
            if (isset($step['secrets']) && !is_array($step['secrets'])) {
                $errors[] = "$label secrets must be an object";
            } elseif (is_array($step['secrets'] ?? null) && $this->isNonEmptyList($step['secrets'])) {
                $errors[] = "$label secrets must be an object";
            }
        }

        foreach ($steps as $index => $step) {
            if (!is_array($step)) {
                continue;
            }
            $stepId = trim((string)($step['id'] ?? ''));
            if ($stepId === '') {
                continue;
            }

            $dependsOn = is_array($step['dependsOn'] ?? null) ? $step['dependsOn'] : [];
            foreach ($dependsOn as $dep) {
                $dep = trim((string)$dep);
                if ($dep === '') {
                    continue;
                }
                if ($dep === $stepId) {
                    $errors[] = "Step {$stepId} cannot depend on itself";
                    continue;
                }
                if (!isset($stepIds[$dep])) {
                    $errors[] = "Step {$stepId} depends on unknown step {$dep}";
                }
            }
        }
        
        return ['valid' => empty($errors), 'errors' => $errors];
    }
    
    /**
     * Normalize command data
     */
    private function normalizeCommand(array $command): array {
        $steps = [];
        foreach (($command['steps'] ?? []) as $step) {
            if (!is_array($step)) {
                continue;
            }
            $steps[] = [
                'id' => trim((string)($step['id'] ?? '')),
                'templateId' => trim((string)($step['templateId'] ?? '')),
                'target' => $this->normalizeTargetRef($step['target'] ?? 'inherit'),
                'dependsOn' => $this->normalizeStringList($step['dependsOn'] ?? []),
                'onFailure' => in_array(strtolower(trim((string)($step['onFailure'] ?? 'stop'))), ['stop', 'continue'], true)
                    ? strtolower(trim((string)($step['onFailure'] ?? 'stop')))
                    : 'stop',
                'inputs' => is_array($step['inputs'] ?? null) ? $step['inputs'] : [],
                'secrets' => is_array($step['secrets'] ?? null) ? $step['secrets'] : [],
            ];
        }

        return [
            'id' => trim((string)$command['id']),
            'name' => trim((string)$command['name']),
            'description' => $command['description'] ?? '',
            'executionMode' => strtolower(trim((string)($command['executionMode'] ?? 'async'))),
            'maxParallel' => max(1, (int)($command['maxParallel'] ?? 1)),
            'finalStatusPolicy' => in_array(strtolower(trim((string)($command['finalStatusPolicy'] ?? 'fail_if_any_failed'))), ['fail_if_any_failed', 'completed_with_warnings'], true)
                ? strtolower(trim((string)($command['finalStatusPolicy'] ?? 'fail_if_any_failed')))
                : 'fail_if_any_failed',
            'defaultTarget' => $this->normalizeTargetRef($command['defaultTarget'] ?? 'inherit'),
            'environment' => $command['environment'] ?? null,
            'steps' => $steps,
            'inputs' => is_array($command['inputs'] ?? null) ? $command['inputs'] : [],
            'inputContract' => is_array($command['inputContract'] ?? null) ? $command['inputContract'] : [],
            'secrets' => is_array($command['secrets'] ?? null) ? $command['secrets'] : [], // Map of ENV_VAR_NAME => secret-id
            'tags' => $this->normalizeStringList($command['tags'] ?? []),
            'icon' => $command['icon'] ?? null,
            'color' => $command['color'] ?? null,
            'enabled' => $command['enabled'] ?? true,
            'createdAt' => $command['createdAt'] ?? null,
            'createdBy' => $command['createdBy'] ?? null,
            'updatedAt' => $command['updatedAt'] ?? null,
            'updatedBy' => $command['updatedBy'] ?? null,
        ];
    }
    
    /**
     * Create a backup of the commands file
     */
    private function createBackup(): ?string {
        if (!file_exists(self::COMMANDS_FILE)) return null;
        
        // Ensure history directory exists
        if (!is_dir(self::HISTORY_DIR)) {
            @mkdir(self::HISTORY_DIR, 0755, true);
        }
        
        $timestamp = date('Y-m-d_H-i-s');
        $backupFile = self::HISTORY_DIR . "/commands.$timestamp.yaml";
        
        if (@copy(self::COMMANDS_FILE, $backupFile)) {
            return $backupFile;
        }
        
        return null;
    }
    
    /**
     * Get backup history
     */
    public function getBackups(): array {
        $backups = [];
        
        if (!is_dir(self::HISTORY_DIR)) {
            return $backups;
        }
        
        $files = scandir(self::HISTORY_DIR, SCANDIR_SORT_DESCENDING);
        foreach ($files as $file) {
            if (preg_match('/^commands\.(.+)\.yaml$/', $file, $m)) {
                $backups[] = [
                    'filename' => $file,
                    'timestamp' => str_replace('_', ' ', str_replace('-', ':', $m[1])),
                    'size' => filesize(self::HISTORY_DIR . '/' . $file)
                ];
            }
        }
        
        return $backups;
    }
    
    /**
     * Restore from backup
     */
    public function restoreBackup(string $filename, ?string $userId = null, string $username = 'system'): array {
        $backupPath = self::HISTORY_DIR . '/' . $filename;
        
        if (!file_exists($backupPath)) {
            return ['success' => false, 'error' => 'Backup file not found'];
        }
        
        // Create backup of current state before restoring
        $this->createBackup();
        
        if (!@copy($backupPath, self::COMMANDS_FILE)) {
            return ['success' => false, 'error' => 'Failed to restore backup'];
        }
        
        // Log audit
        if ($this->auth) {
            $this->auth->auditLog(
                $userId,
                $username,
                'commands.restore',
                'commands',
                null,
                json_encode(['backup_file' => $filename])
            );
        }
        
        return ['success' => true];
    }
    
    /**
     * Basic YAML parsing fallback
     */
    private function parseCommandsManual(string $content): array {
        // This is a very simplified parser - the yaml extension should be installed
        error_log("Warning: YAML extension not available, using basic parser");
        return ['commands' => []];
    }
    
    /**
     * Basic YAML emit fallback
     */
    private function emitYamlManual(array $data): string {
        // Simple YAML output
        $yaml = "# Commands configuration\n";
        $yaml .= "# Auto-generated by Doki\n\n";
        $yaml .= "commands:\n";
        
        foreach ($data['commands'] as $cmd) {
            $yaml .= "  - id: " . $this->yamlValue($cmd['id']) . "\n";
            $yaml .= "    name: " . $this->yamlValue($cmd['name']) . "\n";
            if (!empty($cmd['description'])) {
                $yaml .= "    description: " . $this->yamlValue($cmd['description']) . "\n";
            }
            $yaml .= "    executionMode: " . $this->yamlValue($cmd['executionMode'] ?? 'async') . "\n";
            $yaml .= "    maxParallel: " . (int)($cmd['maxParallel'] ?? 1) . "\n";
            $yaml .= "    finalStatusPolicy: " . $this->yamlValue($cmd['finalStatusPolicy'] ?? 'fail_if_any_failed') . "\n";

            $defaultTarget = $cmd['defaultTarget'] ?? 'inherit';
            if (is_string($defaultTarget)) {
                $yaml .= "    defaultTarget: " . $this->yamlValue($defaultTarget) . "\n";
            } elseif (is_array($defaultTarget) && !empty($defaultTarget['targetId'])) {
                $yaml .= "    defaultTarget:\n";
                $yaml .= "      targetId: " . $this->yamlValue((string)$defaultTarget['targetId']) . "\n";
            } elseif (is_array($defaultTarget) && !empty($defaultTarget['fromEnvironment'])) {
                $yaml .= "    defaultTarget:\n";
                $yaml .= "      fromEnvironment: " . $this->yamlValue((string)$defaultTarget['fromEnvironment']) . "\n";
            } else {
                $yaml .= "    defaultTarget: inherit\n";
            }

            if (!empty($cmd['environment'])) {
                $yaml .= "    environment: " . $this->yamlValue($cmd['environment']) . "\n";
            }

            $yaml .= "    steps:\n";
            foreach (($cmd['steps'] ?? []) as $step) {
                if (!is_array($step)) {
                    continue;
                }
                $yaml .= "      - id: " . $this->yamlValue((string)($step['id'] ?? '')) . "\n";
                $yaml .= "        templateId: " . $this->yamlValue((string)($step['templateId'] ?? '')) . "\n";

                $stepTarget = $step['target'] ?? 'inherit';
                if (is_string($stepTarget)) {
                    $yaml .= "        target: " . $this->yamlValue($stepTarget) . "\n";
                } elseif (is_array($stepTarget) && !empty($stepTarget['targetId'])) {
                    $yaml .= "        target:\n";
                    $yaml .= "          targetId: " . $this->yamlValue((string)$stepTarget['targetId']) . "\n";
                } elseif (is_array($stepTarget) && !empty($stepTarget['fromEnvironment'])) {
                    $yaml .= "        target:\n";
                    $yaml .= "          fromEnvironment: " . $this->yamlValue((string)$stepTarget['fromEnvironment']) . "\n";
                } else {
                    $yaml .= "        target: inherit\n";
                }

                $yaml .= "        onFailure: " . $this->yamlValue((string)($step['onFailure'] ?? 'stop')) . "\n";

                $dependsOn = is_array($step['dependsOn'] ?? null) ? $step['dependsOn'] : [];
                if (!empty($dependsOn)) {
                    $yaml .= "        dependsOn: [" . implode(', ', array_map([$this, 'yamlValue'], $dependsOn)) . "]\n";
                }

                $inputs = is_array($step['inputs'] ?? null) ? $step['inputs'] : [];
                if (!empty($inputs)) {
                    $yaml .= "        inputs:\n";
                    foreach ($inputs as $key => $value) {
                        $yaml .= "          " . $key . ": " . $this->yamlValue($value) . "\n";
                    }
                }
            }

            if (!empty($cmd['inputs'])) {
                $yaml .= "    inputs:\n";
                foreach ($cmd['inputs'] as $key => $value) {
                    $yaml .= "      " . $key . ": " . $this->yamlValue($value) . "\n";
                }
            }
            if (!empty($cmd['secrets'])) {
                $yaml .= "    secrets:\n";
                foreach ($cmd['secrets'] as $envVar => $secretId) {
                    $yaml .= "      " . $envVar . ": " . $this->yamlValue((string)$secretId) . "\n";
                }
            }
            if (!empty($cmd['tags'])) {
                $yaml .= "    tags: [" . implode(', ', array_map([$this, 'yamlValue'], $cmd['tags'])) . "]\n";
            }
            $yaml .= "    enabled: " . ($cmd['enabled'] ? 'true' : 'false') . "\n";
            if (!empty($cmd['createdAt'])) {
                $yaml .= "    createdAt: " . $this->yamlValue($cmd['createdAt']) . "\n";
            }
            if (!empty($cmd['createdBy'])) {
                $yaml .= "    createdBy: " . $this->yamlValue($cmd['createdBy']) . "\n";
            }
            if (!empty($cmd['updatedAt'])) {
                $yaml .= "    updatedAt: " . $this->yamlValue($cmd['updatedAt']) . "\n";
            }
            if (!empty($cmd['updatedBy'])) {
                $yaml .= "    updatedBy: " . $this->yamlValue($cmd['updatedBy']) . "\n";
            }
            $yaml .= "\n";
        }
        
        return $yaml;
    }
    
    /**
     * Format value for YAML output
     */
    private function yamlValue($value): string {
        if (is_bool($value)) return $value ? 'true' : 'false';
        if (is_numeric($value)) return (string)$value;
        if (is_null($value)) return 'null';
        
        // Quote strings that need it
        if (preg_match('/[:#\[\]{}|>&*!?@`\'"]|^\s|\s$/', $value)) {
            return '"' . addslashes($value) . '"';
        }
        return $value;
    }

    private function fixYamlMapEmpties(string $yaml): string {
        $fixed = preg_replace('/^([ \t]*(?:inputs|secrets):)\s*\[\]\s*$/m', '$1 {}', $yaml);
        return is_string($fixed) ? $fixed : $yaml;
    }

    private function isNonEmptyList(array $value): bool {
        return $value !== [] && array_is_list($value);
    }

    private function normalizeTargetRef($ref) {
        if (is_string($ref)) {
            $trimmed = trim($ref);
            if ($trimmed === '' || in_array(strtolower($trimmed), ['inherit', 'none'], true)) {
                return 'inherit';
            }
            return ['targetId' => $trimmed];
        }

        if (is_array($ref)) {
            $targetId = trim((string)($ref['targetId'] ?? ''));
            if ($targetId !== '') {
                return ['targetId' => $targetId];
            }
            $fromEnvironment = trim((string)($ref['fromEnvironment'] ?? ''));
            if ($fromEnvironment !== '') {
                return ['fromEnvironment' => $fromEnvironment];
            }
        }

        return 'inherit';
    }

    private function isValidTargetRef($ref): bool {
        if (is_null($ref)) {
            return true;
        }
        if (is_string($ref)) {
            $trimmed = trim($ref);
            // Valid string refs: empty (treated as inherit), 'inherit', or a non-empty targetId
            return $trimmed === '' || strtolower($trimmed) === 'inherit' || strlen($trimmed) > 0;
        }
        if (!is_array($ref)) {
            return false;
        }
        $targetId = trim((string)($ref['targetId'] ?? ''));
        $fromEnvironment = trim((string)($ref['fromEnvironment'] ?? ''));
        if ($targetId !== '' && $fromEnvironment !== '') {
            return false;
        }
        return $targetId !== '' || $fromEnvironment !== '';
    }

    private function normalizeStringList($value): array {
        if (!is_array($value)) {
            return [];
        }
        $result = [];
        foreach ($value as $item) {
            $item = trim((string)$item);
            if ($item === '') {
                continue;
            }
            $result[$item] = true;
        }
        return array_keys($result);
    }
    
    /**
     * Get commands grouped by tag
     */
    public function getCommandsByTag(): array {
        $result = $this->getCommands();
        if (!$result['success']) return [];
        
        $byTag = ['untagged' => []];
        
        foreach ($result['commands'] as $command) {
            if (empty($command['tags'])) {
                $byTag['untagged'][] = $command;
            } else {
                foreach ($command['tags'] as $tag) {
                    if (!isset($byTag[$tag])) {
                        $byTag[$tag] = [];
                    }
                    $byTag[$tag][] = $command;
                }
            }
        }
        
        // Remove empty untagged group
        if (empty($byTag['untagged'])) {
            unset($byTag['untagged']);
        }
        
        return $byTag;
    }
}
