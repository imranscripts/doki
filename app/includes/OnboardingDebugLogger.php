<?php
/**
 * OnboardingDebugLogger.php - Temporary onboarding diagnostics
 */

class OnboardingDebugLogger {
    private const LOG_PATH = __DIR__ . '/../data/app-build-status/onboarding-debug.log';

    public static function log(string $event, array $context = []): void {
        $dir = dirname(self::LOG_PATH);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $record = [
            'ts' => date('c'),
            'event' => $event,
            'context' => self::sanitize($context),
        ];

        @file_put_contents(
            self::LOG_PATH,
            json_encode($record, JSON_UNESCAPED_SLASHES) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }

    public static function getLogPath(): string {
        return self::LOG_PATH;
    }

    private static function sanitize(array $context): array {
        $sanitized = [];
        foreach ($context as $key => $value) {
            $normalizedKey = (string)$key;
            if (in_array($normalizedKey, ['password', 'passwordConfirm', 'recoveryCode', 'recovery_code'], true)) {
                $sanitized[$normalizedKey] = '[redacted]';
                continue;
            }

            if (is_array($value)) {
                $sanitized[$normalizedKey] = self::sanitize($value);
                continue;
            }

            if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
                $sanitized[$normalizedKey] = $value;
                continue;
            }

            $sanitized[$normalizedKey] = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_SLASHES);
        }

        return $sanitized;
    }
}
