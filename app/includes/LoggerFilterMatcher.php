<?php
/**
 * LoggerFilterMatcher.php - Shared v1 matching for Logger saved filters
 */

class LoggerFilterMatcher {
    public const MODE_CONTAINS_ALL = 'contains_all';
    public const MODE_CONTAINS_ANY = 'contains_any';

    public static function supportedModes(): array {
        return [
            self::MODE_CONTAINS_ALL,
            self::MODE_CONTAINS_ANY,
        ];
    }

    public static function normalizeMode(string $mode): string {
        $mode = strtolower(trim($mode));
        return in_array($mode, self::supportedModes(), true) ? $mode : self::MODE_CONTAINS_ALL;
    }

    public static function termsForPattern(string $pattern): array {
        $pattern = trim(str_replace(["\0", "\r", "\n"], ' ', $pattern));
        if ($pattern === '') {
            return [];
        }

        preg_match_all('/"([^"]+)"|\'([^\']+)\'|[^\s+,;|]+/', $pattern, $matches, PREG_SET_ORDER);
        $terms = [];
        foreach ($matches as $match) {
            $term = trim((string)($match[1] ?? $match[2] ?? $match[0] ?? ''));
            if ($term !== '') {
                $terms[] = $term;
            }
        }

        return array_values(array_unique($terms));
    }

    public static function matchLine(array $filter, string $line): bool {
        if (!($filter['enabled'] ?? true)) {
            return true;
        }

        $mode = self::normalizeMode((string)($filter['mode'] ?? self::MODE_CONTAINS_ALL));
        $terms = self::termsForPattern((string)($filter['pattern'] ?? ''));
        if ($terms === []) {
            return true;
        }

        $caseSensitive = (bool)($filter['case_sensitive'] ?? false);
        $haystack = $caseSensitive ? $line : strtolower($line);
        $matched = 0;

        foreach ($terms as $term) {
            $needle = $caseSensitive ? $term : strtolower($term);
            $contains = $needle !== '' && str_contains($haystack, $needle);
            if ($mode === self::MODE_CONTAINS_ANY && $contains) {
                return true;
            }
            if ($mode === self::MODE_CONTAINS_ALL && $contains) {
                $matched++;
            }
        }

        return $mode === self::MODE_CONTAINS_ALL && $matched === count($terms);
    }

    public static function matchLineContext(array $filters, array $line): bool {
        $text = implode(' ', array_filter([
            (string)($line['entryName'] ?? ''),
            (string)($line['sourceName'] ?? ''),
            (string)($line['path'] ?? ''),
            (string)($line['line'] ?? ''),
        ], fn(string $value): bool => $value !== ''));

        foreach (self::enabledFilters($filters) as $filter) {
            if (!self::matchLine($filter, $text)) {
                return false;
            }
        }

        return true;
    }

    public static function filterLines(array $lines, array $filters): array {
        $filters = self::enabledFilters($filters);
        if ($filters === []) {
            return $lines;
        }

        return array_values(array_filter($lines, fn(array $line): bool => self::matchLineContext($filters, $line)));
    }

    public static function enabledFilters(array $filters): array {
        return array_values(array_filter($filters, fn($filter): bool => is_array($filter) && (bool)($filter['enabled'] ?? true)));
    }
}
