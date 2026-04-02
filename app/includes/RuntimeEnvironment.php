<?php
/**
 * RuntimeEnvironment.php - Shared runtime env resolution for host and container contexts
 */

class RuntimeEnvironment {
    public const DEFAULT_ORCHESTRATOR_HOST_PORT = 8098;
    private const PROJECT_ENV_PATH = __DIR__ . '/../../.env';

    private static ?array $projectEnvCache = null;

    public static function isContainerRuntime(): bool {
        return getenv('DOKI_FPM_CONTAINER') === 'true' || is_file('/.dockerenv');
    }

    public static function getProjectEnvPath(): string {
        return self::PROJECT_ENV_PATH;
    }

    public static function readProjectEnv(): array {
        if (self::$projectEnvCache !== null) {
            return self::$projectEnvCache;
        }

        $path = self::getProjectEnvPath();
        if (!is_file($path)) {
            return self::$projectEnvCache = [];
        }

        $lines = @file($path, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            return self::$projectEnvCache = [];
        }

        $values = [];
        foreach ($lines as $line) {
            if (!preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)\s*$/', $line, $matches)) {
                continue;
            }

            $value = trim($matches[2]);
            $length = strlen($value);
            if ($length >= 2) {
                $quote = $value[0];
                if (($quote === '"' || $quote === "'") && $value[$length - 1] === $quote) {
                    $value = substr($value, 1, -1);
                }
            }
            $values[$matches[1]] = $value;
        }

        self::$projectEnvCache = $values;
        return $values;
    }

    public static function getProjectEnvValue(string $key): ?string {
        $values = self::readProjectEnv();
        if (!array_key_exists($key, $values)) {
            return null;
        }

        $value = trim((string)$values[$key]);
        return $value !== '' ? $value : null;
    }

    public static function getEnvValue(string $key): ?string {
        $value = getenv($key);
        if ($value !== false) {
            $value = trim((string)$value);
            if ($value !== '') {
                return $value;
            }
        }

        return self::getProjectEnvValue($key);
    }

    public static function writeProjectEnvValues(array $updates): bool {
        if ($updates === []) {
            return true;
        }

        $path = self::getProjectEnvPath();
        $lines = is_file($path) ? @file($path, FILE_IGNORE_NEW_LINES) : [];
        if (!is_array($lines)) {
            $lines = [];
        }

        $pending = [];
        foreach ($updates as $key => $value) {
            $key = trim((string)$key);
            if ($key === '') {
                continue;
            }
            $pending[$key] = (string)$value;
        }

        if ($pending === []) {
            return true;
        }

        $updated = [];
        foreach ($lines as $index => $line) {
            if (!preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=.*$/', $line, $matches)) {
                continue;
            }

            $key = $matches[1];
            if (!array_key_exists($key, $pending)) {
                continue;
            }

            $lines[$index] = $key . '=' . self::encodeEnvValue($pending[$key]);
            $updated[$key] = true;
        }

        foreach ($pending as $key => $value) {
            if (!isset($updated[$key])) {
                $lines[] = $key . '=' . self::encodeEnvValue($value);
            }
        }

        $content = implode(PHP_EOL, $lines);
        if ($content !== '') {
            $content .= PHP_EOL;
        }

        $result = @file_put_contents($path, $content);
        if ($result === false) {
            return false;
        }

        self::$projectEnvCache = null;
        return true;
    }

    public static function removeProjectEnvKeys(array $keys): bool {
        if ($keys === []) {
            return true;
        }

        $path = self::getProjectEnvPath();
        if (!is_file($path)) {
            return true;
        }

        $remove = [];
        foreach ($keys as $key) {
            $key = trim((string)$key);
            if ($key !== '') {
                $remove[$key] = true;
            }
        }
        if ($remove === []) {
            return true;
        }

        $lines = @file($path, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            return false;
        }

        $filtered = [];
        foreach ($lines as $line) {
            if (
                preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=.*$/', $line, $matches)
                && isset($remove[$matches[1]])
            ) {
                continue;
            }
            $filtered[] = $line;
        }

        while ($filtered !== [] && trim((string)end($filtered)) === '') {
            array_pop($filtered);
        }

        $content = $filtered === [] ? '' : implode(PHP_EOL, $filtered) . PHP_EOL;
        $result = @file_put_contents($path, $content);
        if ($result === false) {
            return false;
        }

        self::$projectEnvCache = null;
        return true;
    }

    public static function parsePort(?string $value): ?int {
        if ($value === null || trim($value) === '') {
            return null;
        }

        if (!ctype_digit(trim($value))) {
            return null;
        }

        $port = (int)$value;
        return ($port >= 1 && $port <= 65535) ? $port : null;
    }

    public static function getOrchestratorHostPort(): int {
        $port = self::parsePort(self::getEnvValue('DOKI_ORCH_HOST_PORT'));
        return $port ?? self::DEFAULT_ORCHESTRATOR_HOST_PORT;
    }

    public static function getOrchestratorBaseUrl(): string {
        $configured = self::normalizeUrl(getenv('DOKI_ORCH_URL') ?: null);
        if ($configured !== null) {
            return $configured;
        }

        if (self::isContainerRuntime()) {
            return 'http://go-orchestrator:8098';
        }

        return 'http://127.0.0.1:' . self::getOrchestratorHostPort();
    }

    public static function getOrchestratorHealthProbeBaseUrls(): array {
        $configured = self::normalizeUrl(getenv('DOKI_ORCH_URL') ?: null);
        if ($configured !== null) {
            return [$configured];
        }

        $hostPort = self::getOrchestratorHostPort();
        $hostUrls = [
            'http://127.0.0.1:' . $hostPort,
            'http://localhost:' . $hostPort,
        ];

        if (self::isContainerRuntime()) {
            return array_values(array_unique(array_merge(
                ['http://go-orchestrator:8098'],
                $hostUrls
            )));
        }

        return array_values(array_unique(array_merge(
            $hostUrls,
            ['http://go-orchestrator:8098']
        )));
    }

    private static function normalizeUrl(?string $value): ?string {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        return rtrim($value, '/');
    }

    private static function encodeEnvValue(string $value): string {
        if ($value === '' || preg_match('/[^A-Za-z0-9_:\\/.+-]/', $value)) {
            return '"' . addcslashes($value, "\\\"\n\r$") . '"';
        }

        return $value;
    }
}
