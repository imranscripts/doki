<?php
/**
 * LoggerTargetResolver.php - Target and environment resolution for Logger
 */

require_once __DIR__ . '/ConfigManager.php';

class LoggerTargetResolver {
    private ConfigManager $config;

    private const LOG_CAPABLE_TYPES = ['ssh', 'local', 'docker', 'docker-exec'];

    public function __construct(?ConfigManager $config = null) {
        $this->config = $config ?? new ConfigManager();
    }

    public function getLogCapableTypes(): array {
        return self::LOG_CAPABLE_TYPES;
    }

    public function isLogCapableTarget(?array $target): bool {
        if ($target === null) {
            return false;
        }

        return in_array((string)($target['type'] ?? ''), self::LOG_CAPABLE_TYPES, true);
    }

    public function listLogCapableTargets(): array {
        $targets = [];
        foreach ($this->config->getTargets() as $target) {
            if (!$this->isLogCapableTarget($target)) {
                continue;
            }

            $targets[] = $this->summarizeTarget($target);
        }

        usort($targets, function(array $a, array $b): int {
            return strcasecmp((string)$a['name'], (string)$b['name']);
        });

        return $targets;
    }

    public function getTarget(string $targetId): ?array {
        $target = $this->config->getTarget($targetId);
        if (!$this->isLogCapableTarget($target)) {
            return null;
        }

        return $this->summarizeTarget($target);
    }

    public function listEnvironmentContexts(): array {
        $contexts = [];
        foreach ($this->config->getEnvironments() as $environmentId => $environment) {
            if (!is_array($environment)) {
                continue;
            }

            $contexts[] = $this->resolveEnvironment((string)$environmentId);
        }

        usort($contexts, function(array $a, array $b): int {
            return strcasecmp((string)($a['name'] ?? $a['id']), (string)($b['name'] ?? $b['id']));
        });

        return $contexts;
    }

    public function resolveEnvironment(string $environmentId): array {
        $environment = $this->config->getEnvironment($environmentId);
        if (!is_array($environment)) {
            return [
                'id' => $environmentId,
                'name' => $environmentId,
                'exists' => false,
                'logCapableTargets' => [],
                'nonLogTargets' => [],
                'missingTargets' => [],
                'errors' => ['Environment not found'],
            ];
        }

        $targetMap = $this->normalizeEnvironmentTargets($environment['targets'] ?? []);
        $logCapable = [];
        $nonLog = [];
        $missing = [];

        foreach ($targetMap as $key => $targetId) {
            $target = $this->config->getTarget($targetId);
            if (!is_array($target)) {
                $missing[] = [
                    'key' => $key,
                    'targetId' => $targetId,
                    'error' => 'Target not found',
                ];
                continue;
            }

            $summary = $this->summarizeTarget($target);
            $summary['environmentKey'] = $key;
            if ($this->isLogCapableTarget($target)) {
                $logCapable[] = $summary;
            } else {
                $nonLog[] = $summary;
            }
        }

        return [
            'id' => $environmentId,
            'name' => (string)($environment['name'] ?? $environmentId),
            'description' => (string)($environment['description'] ?? ''),
            'exists' => true,
            'targetCount' => count($targetMap),
            'logCapableTargets' => $logCapable,
            'nonLogTargets' => $nonLog,
            'missingTargets' => $missing,
            'errors' => [],
        ];
    }

    public function resolveEnvironmentTarget(string $environmentId, string $environmentTargetKey): ?array {
        $environment = $this->config->getEnvironment($environmentId);
        if (!is_array($environment)) {
            return null;
        }

        $targetMap = $this->normalizeEnvironmentTargets($environment['targets'] ?? []);
        $targetId = $targetMap[$environmentTargetKey] ?? null;
        if (!is_string($targetId) || trim($targetId) === '') {
            return null;
        }

        return $this->getTarget($targetId);
    }

    public function resolveSourceTarget(array $entry, array $source): array {
        $mode = (string)($entry['mode'] ?? 'direct');

        if ($mode === 'environment') {
            $environmentId = (string)($entry['environment_id'] ?? '');
            $environmentTargetKey = (string)($source['environment_target_key'] ?? '');
            if ($environmentId === '' || $environmentTargetKey === '') {
                return [
                    'success' => false,
                    'target' => null,
                    'error' => 'Environment ID and environment target key are required',
                ];
            }

            $target = $this->resolveEnvironmentTarget($environmentId, $environmentTargetKey);
            if ($target === null) {
                return [
                    'success' => false,
                    'target' => null,
                    'error' => "No log-capable target resolved for environment key '{$environmentTargetKey}'",
                ];
            }

            return ['success' => true, 'target' => $target, 'error' => null];
        }

        $targetId = (string)($source['target_id'] ?? '');
        if ($targetId === '') {
            return [
                'success' => false,
                'target' => null,
                'error' => 'Target ID is required',
            ];
        }

        $target = $this->getTarget($targetId);
        if ($target === null) {
            return [
                'success' => false,
                'target' => null,
                'error' => "Target '{$targetId}' is not log-capable or does not exist",
            ];
        }

        return ['success' => true, 'target' => $target, 'error' => null];
    }

    public function validateEntrySources(array $entry, array $sources): array {
        $errors = [];
        $mode = (string)($entry['mode'] ?? 'direct');

        if (!in_array($mode, ['direct', 'environment'], true)) {
            $errors[] = 'Entry mode must be direct or environment';
        }

        if ($sources === []) {
            $errors[] = 'At least one source is required';
        }

        foreach ($sources as $index => $source) {
            if (!is_array($source)) {
                $errors[] = "Source " . ($index + 1) . " must be an object";
                continue;
            }

            $path = trim((string)($source['path'] ?? ''));
            if ($path === '') {
                $errors[] = "Source " . ($index + 1) . " requires a path";
            }

            $sourceName = trim((string)($source['source_name'] ?? $source['name'] ?? ''));
            if ($sourceName === '') {
                $errors[] = "Source " . ($index + 1) . " requires a name";
            }

            $resolution = $this->resolveSourceTarget($entry, $source);
            if (!$resolution['success']) {
                $errors[] = "Source " . ($index + 1) . ': ' . $resolution['error'];
            }
        }

        if ($mode === 'environment') {
            $environmentId = trim((string)($entry['environment_id'] ?? ''));
            if ($environmentId === '') {
                $errors[] = 'Environment entries require an environment';
            } else {
                $coverageErrors = $this->validateEnvironmentCoverage($environmentId, $sources);
                $errors = array_merge($errors, $coverageErrors);
            }
        }

        return [
            'valid' => $errors === [],
            'errors' => array_values(array_unique($errors)),
        ];
    }

    private function validateEnvironmentCoverage(string $environmentId, array $sources): array {
        $environment = $this->resolveEnvironment($environmentId);
        if (!$environment['exists']) {
            return ["Environment '{$environmentId}' does not exist"];
        }

        $requiredKeys = [];
        foreach ($environment['logCapableTargets'] as $target) {
            $key = (string)($target['environmentKey'] ?? '');
            if ($key !== '') {
                $requiredKeys[$key] = true;
            }
        }

        if ($requiredKeys === []) {
            return ["Environment '{$environmentId}' has no log-capable targets"];
        }

        $coveredKeys = [];
        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }
            $key = trim((string)($source['environment_target_key'] ?? ''));
            $path = trim((string)($source['path'] ?? ''));
            if ($key !== '' && $path !== '') {
                $coveredKeys[$key] = true;
            }
        }

        $errors = [];
        foreach (array_keys($requiredKeys) as $key) {
            if (!isset($coveredKeys[$key])) {
                $errors[] = "Environment target '{$key}' requires at least one source file";
            }
        }

        return $errors;
    }

    private function normalizeEnvironmentTargets($targets): array {
        if (!is_array($targets)) {
            return [];
        }

        $normalized = [];
        foreach ($targets as $key => $targetId) {
            if (is_array($targetId)) {
                $targetId = $targetId['id'] ?? $targetId['targetId'] ?? null;
            }
            if (!is_string($targetId) && !is_numeric($targetId)) {
                continue;
            }

            $key = is_string($key) ? trim($key) : (string)$key;
            $targetId = trim((string)$targetId);
            if ($key === '' || $targetId === '') {
                continue;
            }

            $normalized[$key] = $targetId;
        }

        return $normalized;
    }

    private function summarizeTarget(array $target): array {
        $type = (string)($target['type'] ?? '');
        $verifiedCapabilities = $target['verifiedCapabilities'] ?? [];
        if (!is_array($verifiedCapabilities)) {
            $verifiedCapabilities = [];
        }

        return [
            'id' => (string)($target['id'] ?? ''),
            'name' => (string)($target['name'] ?? $target['id'] ?? ''),
            'type' => $type,
            'description' => (string)($target['description'] ?? ''),
            'transport' => $this->transportForType($type),
            'verified' => $this->isTargetVerifiedForLogs($target),
            'verifiedCapabilities' => array_values(array_map('strval', $verifiedCapabilities)),
            'details' => $this->targetDetails($target),
            'warnings' => $this->targetWarnings($target),
        ];
    }

    private function transportForType(string $type): string {
        return match ($type) {
            'ssh' => 'ssh',
            'local' => 'local',
            'docker', 'docker-exec' => 'docker',
            default => 'unknown',
        };
    }

    private function isTargetVerifiedForLogs(array $target): bool {
        $type = (string)($target['type'] ?? '');
        $capabilities = $target['verifiedCapabilities'] ?? [];
        if (!is_array($capabilities)) {
            return false;
        }

        if ($type === 'ssh') {
            return in_array('exec.ssh', $capabilities, true);
        }

        if (in_array($type, ['local', 'docker', 'docker-exec'], true)) {
            return in_array('exec.docker', $capabilities, true);
        }

        return false;
    }

    private function targetDetails(array $target): array {
        $connection = $target['connection'] ?? [];
        if (!is_array($connection)) {
            $connection = [];
        }

        $type = (string)($target['type'] ?? '');
        $details = [];

        if ($type === 'ssh') {
            if (!empty($connection['host'])) {
                $details[] = 'host: ' . (string)$connection['host'] . (!empty($connection['port']) ? ':' . (string)$connection['port'] : '');
            }
            if (!empty($connection['user'])) {
                $details[] = 'user: ' . (string)$connection['user'];
            }
        } elseif ($type === 'local') {
            $details[] = 'Doki server filesystem';
        } elseif ($type === 'docker-exec') {
            if (!empty($connection['container'])) {
                $details[] = 'container: ' . (string)$connection['container'];
            } else {
                $details[] = 'container required';
            }
        } elseif ($type === 'docker') {
            if (!empty($connection['sshTargetId'])) {
                $details[] = 'docker via ssh target: ' . (string)$connection['sshTargetId'];
            } elseif (!empty($connection['ssh']) && is_array($connection['ssh'])) {
                $details[] = 'docker via ssh';
            } elseif (!empty($connection['host'])) {
                $details[] = 'docker tcp: ' . (string)$connection['host'] . (!empty($connection['port']) ? ':' . (string)$connection['port'] : '');
            } else {
                $details[] = 'docker socket: ' . (string)($connection['socket'] ?? '/var/run/docker.sock');
            }
        }

        return $details;
    }

    private function targetWarnings(array $target): array {
        $type = (string)($target['type'] ?? '');
        $connection = $target['connection'] ?? [];
        if (!is_array($connection)) {
            $connection = [];
        }

        $warnings = [];
        if (!$this->isTargetVerifiedForLogs($target)) {
            $warnings[] = 'Target is not verified for the execution capability logger will use';
        }

        if ($type === 'ssh') {
            if (empty($connection['host']) || empty($connection['user'])) {
                $warnings[] = 'SSH target requires host and user';
            }
        }

        if ($type === 'docker-exec' && empty($connection['container'])) {
            $warnings[] = 'Docker exec target requires a container';
        }

        return $warnings;
    }
}

