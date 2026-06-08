<?php
/**
 * LoggerTargetLogReader.php - Runtime log tail/search for Logger entries
 */

require_once __DIR__ . '/ConfigManager.php';
require_once __DIR__ . '/LoggerFilterMatcher.php';
require_once __DIR__ . '/LoggerTargetResolver.php';

class LoggerTargetLogReader {
    private ConfigManager $config;
    private LoggerTargetResolver $resolver;

    public function __construct(?ConfigManager $config = null, ?LoggerTargetResolver $resolver = null) {
        $this->config = $config ?? new ConfigManager();
        $this->resolver = $resolver ?? new LoggerTargetResolver($this->config);
    }

    public function tailEntry(array $entry, int $lineLimit = 200, array $filters = []): array {
        $lineLimit = $this->boundedInt($lineLimit, 1, 1000, 200);
        return $this->readEntry($entry, 'tail', '', $lineLimit, false, $filters);
    }

    public function searchEntry(array $entry, string $query, int $resultLimit = 200, bool $caseSensitive = false, array $filters = []): array {
        $query = $this->normalizeQuery($query);
        $filters = LoggerFilterMatcher::enabledFilters($filters);
        if ($query === '' && $filters === []) {
            return [
                'success' => false,
                'error' => 'Search query or saved filter is required',
                'lines' => [],
                'sources' => [],
            ];
        }

        $resultLimit = $this->boundedInt($resultLimit, 1, 500, 200);
        return $this->readEntry($entry, $query === '' ? 'filter' : 'search', $query, $resultLimit, $caseSensitive, $filters);
    }

    public function tailGroup(array $group, array $entries, int $lineLimit = 200, array $filters = []): array {
        $lineLimit = $this->boundedInt($lineLimit, 1, 1000, 200);
        return $this->readGroup($group, $entries, 'tail', '', $lineLimit, false, $filters);
    }

    public function searchGroup(array $group, array $entries, string $query, int $resultLimit = 200, bool $caseSensitive = false, array $filters = []): array {
        $query = $this->normalizeQuery($query);
        $filters = LoggerFilterMatcher::enabledFilters($filters);
        if ($query === '' && $filters === []) {
            return [
                'success' => false,
                'error' => 'Search query or saved filter is required',
                'lines' => [],
                'sources' => [],
                'entries' => [],
            ];
        }

        $resultLimit = $this->boundedInt($resultLimit, 1, 500, 200);
        return $this->readGroup($group, $entries, $query === '' ? 'filter' : 'search', $query, $resultLimit, $caseSensitive, $filters);
    }

    private function readEntry(array $entry, string $mode, string $query, int $limit, bool $caseSensitive, array $filters = []): array {
        $lines = [];
        $sourceResults = [];
        $filters = LoggerFilterMatcher::enabledFilters($filters);
        $sources = array_values(array_filter($entry['sources'] ?? [], function($source): bool {
            return is_array($source) && (bool)($source['enabled'] ?? true);
        }));

        foreach ($sources as $sourceIndex => $source) {
            $sourceRuntime = $this->resolveRuntimeSource($entry, $source);
            if (!$sourceRuntime['success']) {
                $sourceResults[] = $this->sourceSummary($source, null, $sourceRuntime['error']);
                continue;
            }

            $target = $sourceRuntime['target'];
            $path = trim((string)($source['path'] ?? ''));
            $readResult = match ($mode) {
                'search' => $this->searchSource($target, $path, $query, $limit, $caseSensitive),
                'filter' => $this->filterSource($target, $path, $filters, $limit),
                default => $this->tailSource($target, $path, $limit),
            };

            $sourceSummary = $this->sourceSummary($source, $target, $readResult['success'] ? null : ($readResult['error'] ?? 'Read failed'));
            $sourceLines = [];

            foreach ($readResult['lines'] ?? [] as $lineIndex => $line) {
                $sourceLines[] = [
                    'id' => $this->lineId($source, $line, $sourceIndex, $lineIndex),
                    'sourceId' => (string)($source['id'] ?? ''),
                    'sourceName' => (string)($source['source_name'] ?? $source['name'] ?? 'source'),
                    'targetId' => (string)($target['id'] ?? ''),
                    'targetType' => (string)($target['type'] ?? ''),
                    'path' => $path,
                    'line' => (string)($line['line'] ?? ''),
                    'lineNumber' => $line['lineNumber'] ?? null,
                    'timestamp' => $this->detectTimestamp((string)($line['line'] ?? '')),
                    'sourceOrder' => $sourceIndex,
                    'lineOrder' => $lineIndex,
                ];
            }

            if ($filters !== []) {
                $sourceLines = LoggerFilterMatcher::filterLines($sourceLines, $filters);
            }

            $sourceSummary['lineCount'] = count($sourceLines);
            $sourceResults[] = $sourceSummary;
            foreach ($sourceLines as $line) {
                $lines[] = $line;
            }
        }

        $this->sortLines($lines);

        return [
            'success' => true,
            'entryId' => (string)($entry['id'] ?? ''),
            'entryName' => (string)($entry['name'] ?? ''),
            'mode' => $mode,
            'query' => $mode === 'search' ? $query : null,
            'caseSensitive' => $caseSensitive,
            'lineLimit' => $limit,
            'generatedAt' => gmdate('c'),
            'appliedFilters' => $this->summarizeFilters($filters),
            'sources' => $sourceResults,
            'lines' => $lines,
        ];
    }

    private function readGroup(array $group, array $entries, string $mode, string $query, int $limit, bool $caseSensitive, array $filters = []): array {
        $lines = [];
        $sources = [];
        $entrySummaries = [];
        $filters = LoggerFilterMatcher::enabledFilters($filters);

        foreach (array_values($entries) as $entryIndex => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $result = $this->readEntry($entry, $mode, $query, $limit, $caseSensitive, $filters);
            $entrySummaries[] = [
                'id' => (string)($entry['id'] ?? ''),
                'name' => (string)($entry['name'] ?? ''),
                'slug' => (string)($entry['slug'] ?? ''),
                'sourceCount' => (int)($entry['source_count'] ?? count($entry['sources'] ?? [])),
                'lineCount' => count($result['lines'] ?? []),
            ];

            foreach ($result['sources'] ?? [] as $source) {
                $source['entryId'] = (string)($entry['id'] ?? '');
                $source['entryName'] = (string)($entry['name'] ?? '');
                $sources[] = $source;
            }

            foreach ($result['lines'] ?? [] as $line) {
                $line['entryId'] = (string)($entry['id'] ?? '');
                $line['entryName'] = (string)($entry['name'] ?? '');
                $line['sourceOrder'] = ($entryIndex * 10000) + (int)($line['sourceOrder'] ?? 0);
                $line['lineOrder'] = (int)($line['lineOrder'] ?? 0);
                $lines[] = $line;
            }
        }

        $this->sortLines($lines);

        return [
            'success' => true,
            'groupId' => (string)($group['id'] ?? ''),
            'groupName' => (string)($group['name'] ?? ''),
            'mode' => $mode,
            'query' => $mode === 'search' ? $query : null,
            'caseSensitive' => $caseSensitive,
            'lineLimit' => $limit,
            'generatedAt' => gmdate('c'),
            'appliedFilters' => $this->summarizeFilters($filters),
            'entries' => $entrySummaries,
            'sources' => $sources,
            'lines' => $lines,
        ];
    }

    private function resolveRuntimeSource(array $entry, array $source): array {
        $resolution = $this->resolver->resolveSourceTarget($entry, $source);
        if (!$resolution['success']) {
            return [
                'success' => false,
                'target' => null,
                'error' => $resolution['error'] ?? 'Target resolution failed',
            ];
        }

        $targetId = (string)($resolution['target']['id'] ?? '');
        $target = $targetId !== '' ? $this->config->getTarget($targetId) : null;
        if (!is_array($target)) {
            return [
                'success' => false,
                'target' => null,
                'error' => "Resolved target '{$targetId}' was not found",
            ];
        }

        return [
            'success' => true,
            'target' => $target,
            'error' => null,
        ];
    }

    private function tailSource(array $target, string $path, int $lineLimit): array {
        return match ((string)($target['type'] ?? '')) {
            'local' => $this->tailLocal($target, $path, $lineLimit),
            'ssh' => $this->tailSsh($target, $path, $lineLimit),
            'docker-exec' => $this->tailDockerExec($target, $path, $lineLimit),
            'docker' => $this->tailDocker($target, $path, $lineLimit),
            default => $this->readError("Unsupported target type '" . (string)($target['type'] ?? '') . "'"),
        };
    }

    private function searchSource(array $target, string $path, string $query, int $resultLimit, bool $caseSensitive): array {
        return match ((string)($target['type'] ?? '')) {
            'local' => $this->searchLocal($target, $path, $query, $resultLimit, $caseSensitive),
            'ssh' => $this->searchSsh($target, $path, $query, $resultLimit, $caseSensitive),
            'docker-exec' => $this->searchDockerExec($target, $path, $query, $resultLimit, $caseSensitive),
            'docker' => $this->searchDocker($target, $path, $query, $resultLimit, $caseSensitive),
            default => $this->readError("Unsupported target type '" . (string)($target['type'] ?? '') . "'"),
        };
    }

    private function filterSource(array $target, string $path, array $filters, int $resultLimit): array {
        return match ((string)($target['type'] ?? '')) {
            'local' => $this->filterLocal($target, $path, $filters, $resultLimit),
            'ssh' => $this->filterSsh($target, $path, $filters, $resultLimit),
            'docker-exec' => $this->filterDockerExec($target, $path, $filters, $resultLimit),
            'docker' => $this->filterDocker($target, $path, $filters, $resultLimit),
            default => $this->readError("Unsupported target type '" . (string)($target['type'] ?? '') . "'"),
        };
    }

    private function tailLocal(array $target, string $path, int $lineLimit): array {
        $check = $this->validateLocalFile($path);
        if (!$check['success']) {
            return $check;
        }

        $result = $this->runCommand(['tail', '-n', (string)$lineLimit, '--', $path], 8);
        if (!$result['success']) {
            return $this->readError($result['output'] ?: 'Local tail failed');
        }

        return $this->readSuccess($this->plainOutputLines($result['output']));
    }

    private function searchLocal(array $target, string $path, string $query, int $resultLimit, bool $caseSensitive): array {
        $check = $this->validateLocalFile($path);
        if (!$check['success']) {
            return $check;
        }

        $needle = $caseSensitive ? $query : strtolower($query);
        $file = new SplFileObject($path, 'rb');
        $lineNumber = 0;
        $matches = [];
        while (!$file->eof() && count($matches) < $resultLimit) {
            $line = rtrim((string)$file->fgets(), "\r\n");
            $lineNumber++;
            $haystack = $caseSensitive ? $line : strtolower($line);
            if ($needle !== '' && str_contains($haystack, $needle)) {
                $matches[] = [
                    'line' => $line,
                    'lineNumber' => $lineNumber,
                ];
            }
        }

        return $this->readSuccess($matches);
    }

    private function filterLocal(array $target, string $path, array $filters, int $resultLimit): array {
        $check = $this->validateLocalFile($path);
        if (!$check['success']) {
            return $check;
        }

        $filters = LoggerFilterMatcher::enabledFilters($filters);
        if ($filters === []) {
            return $this->readError('Saved filter is required');
        }

        $file = new SplFileObject($path, 'rb');
        $lineNumber = 0;
        $matches = [];
        while (!$file->eof() && count($matches) < $resultLimit) {
            $line = rtrim((string)$file->fgets(), "\r\n");
            $lineNumber++;
            if (LoggerFilterMatcher::matchLineContext($filters, ['line' => $line, 'path' => $path])) {
                $matches[] = [
                    'line' => $line,
                    'lineNumber' => $lineNumber,
                ];
            }
        }

        return $this->readSuccess($matches);
    }

    private function tailSsh(array $target, string $path, int $lineLimit): array {
        return $this->runSshScript($target, $this->remoteTailScript(), [$path, (string)$lineLimit]);
    }

    private function searchSsh(array $target, string $path, string $query, int $resultLimit, bool $caseSensitive): array {
        return $this->runSshScript($target, $this->remoteSearchScript(), [
            $path,
            $query,
            (string)$resultLimit,
            $caseSensitive ? '1' : '0',
        ]);
    }

    private function filterSsh(array $target, string $path, array $filters, int $resultLimit): array {
        $primaryFilter = $this->primaryRemoteFilter($filters);
        if ($primaryFilter === null) {
            return $this->readError('Saved filter is required');
        }

        return $this->runSshScript($target, $this->remoteFilterScript(), [
            $path,
            (string)$primaryFilter['mode'],
            (string)$primaryFilter['pattern'],
            (string)$resultLimit,
            (bool)$primaryFilter['case_sensitive'] ? '1' : '0',
        ]);
    }

    private function tailDockerExec(array $target, string $path, int $lineLimit): array {
        $connection = $target['connection'] ?? [];
        $container = is_array($connection) ? trim((string)($connection['container'] ?? '')) : '';
        if ($container === '') {
            return $this->readError('Docker exec target requires a container');
        }

        return $this->runDockerScript($target, $container, $this->remoteTailScript(), [$path, (string)$lineLimit]);
    }

    private function searchDockerExec(array $target, string $path, string $query, int $resultLimit, bool $caseSensitive): array {
        $connection = $target['connection'] ?? [];
        $container = is_array($connection) ? trim((string)($connection['container'] ?? '')) : '';
        if ($container === '') {
            return $this->readError('Docker exec target requires a container');
        }

        return $this->runDockerScript($target, $container, $this->remoteSearchScript(), [
            $path,
            $query,
            (string)$resultLimit,
            $caseSensitive ? '1' : '0',
        ]);
    }

    private function filterDockerExec(array $target, string $path, array $filters, int $resultLimit): array {
        $connection = $target['connection'] ?? [];
        $container = is_array($connection) ? trim((string)($connection['container'] ?? '')) : '';
        if ($container === '') {
            return $this->readError('Docker exec target requires a container');
        }

        $primaryFilter = $this->primaryRemoteFilter($filters);
        if ($primaryFilter === null) {
            return $this->readError('Saved filter is required');
        }

        return $this->runDockerScript($target, $container, $this->remoteFilterScript(), [
            $path,
            (string)$primaryFilter['mode'],
            (string)$primaryFilter['pattern'],
            (string)$resultLimit,
            (bool)$primaryFilter['case_sensitive'] ? '1' : '0',
        ]);
    }

    private function tailDocker(array $target, string $path, int $lineLimit): array {
        $context = $this->dockerPathContext($path);
        if (!$context['success']) {
            return $this->readError($context['error']);
        }

        return $this->runDockerScript($target, $context['container'], $this->remoteTailScript(), [$context['path'], (string)$lineLimit]);
    }

    private function searchDocker(array $target, string $path, string $query, int $resultLimit, bool $caseSensitive): array {
        $context = $this->dockerPathContext($path);
        if (!$context['success']) {
            return $this->readError($context['error']);
        }

        return $this->runDockerScript($target, $context['container'], $this->remoteSearchScript(), [
            $context['path'],
            $query,
            (string)$resultLimit,
            $caseSensitive ? '1' : '0',
        ]);
    }

    private function filterDocker(array $target, string $path, array $filters, int $resultLimit): array {
        $context = $this->dockerPathContext($path);
        if (!$context['success']) {
            return $this->readError($context['error']);
        }

        $primaryFilter = $this->primaryRemoteFilter($filters);
        if ($primaryFilter === null) {
            return $this->readError('Saved filter is required');
        }

        return $this->runDockerScript($target, $context['container'], $this->remoteFilterScript(), [
            $context['path'],
            (string)$primaryFilter['mode'],
            (string)$primaryFilter['pattern'],
            (string)$resultLimit,
            (bool)$primaryFilter['case_sensitive'] ? '1' : '0',
        ]);
    }

    private function runSshScript(array $target, string $script, array $args): array {
        $connection = $target['connection'] ?? [];
        if (!is_array($connection)) {
            $connection = [];
        }

        $remoteCommand = $this->remoteCommand($script, $args);
        $bundle = $this->buildSshCommand($connection, $remoteCommand);
        if (!$bundle['success']) {
            return $this->readError($bundle['error']);
        }

        $result = $this->runCommand($bundle['cmd'], 14, $bundle['env']);
        $this->cleanupPaths($bundle['cleanup']);
        if (!$result['success']) {
            return $this->readError($result['output'] ?: 'SSH command failed');
        }

        return $this->parseEncodedLineOutput($result['output']);
    }

    private function runDockerScript(array $target, string $container, string $script, array $args): array {
        $docker = $this->buildDockerCommandPrefix($target);
        if (!$docker['success']) {
            return $this->readError($docker['error']);
        }

        $cmd = array_merge($docker['cmd'], ['exec', $container, 'sh', '-c', $this->remoteCommand($script, $args)]);
        $result = $this->runCommand($cmd, 14, $docker['env']);
        $this->cleanupPaths($docker['cleanup']);
        if (!$result['success']) {
            return $this->readError($result['output'] ?: 'Docker command failed');
        }

        return $this->parseEncodedLineOutput($result['output']);
    }

    private function remoteCommand(string $script, array $args): string {
        $parts = ['sh', '-c', escapeshellarg($script), '--'];
        foreach ($args as $arg) {
            $parts[] = escapeshellarg((string)$arg);
        }
        return implode(' ', $parts);
    }

    private function parseEncodedLineOutput(string $output): array {
        $output = rtrim($output, "\r\n");
        if ($output === '') {
            return $this->readSuccess([]);
        }

        $lines = [];
        $errors = [];
        foreach (preg_split('/\R/', $output) ?: [] as $row) {
            if ($row === '') {
                continue;
            }
            if (str_starts_with($row, 'ERR' . "\t")) {
                $errors[] = substr($row, 4);
                continue;
            }

            $parts = explode("\t", $row, 3);
            if (count($parts) !== 3 || $parts[0] !== 'LINE') {
                continue;
            }

            $decoded = base64_decode($parts[2], true);
            if (!is_string($decoded)) {
                continue;
            }

            $lines[] = [
                'lineNumber' => $parts[1] !== '' && ctype_digit($parts[1]) ? (int)$parts[1] : null,
                'line' => $decoded,
            ];
        }

        if ($errors !== []) {
            return $this->readError(implode(' | ', $errors));
        }

        return $this->readSuccess($lines);
    }

    private function validateLocalFile(string $path): array {
        if ($path === '') {
            return $this->readError('Path is required');
        }
        if (!file_exists($path)) {
            return $this->readError("Path does not exist: {$path}");
        }
        if (is_dir($path)) {
            return $this->readError("Path is a directory: {$path}");
        }
        if (!is_readable($path)) {
            return $this->readError("File is not readable: {$path}");
        }
        return ['success' => true];
    }

    private function plainOutputLines(string $output): array {
        $output = rtrim($output, "\r\n");
        if ($output === '') {
            return [];
        }

        return array_map(
            fn(string $line): array => ['line' => $line, 'lineNumber' => null],
            preg_split('/\R/', $output) ?: []
        );
    }

    private function sourceSummary(array $source, ?array $target, ?string $error): array {
        return [
            'id' => (string)($source['id'] ?? ''),
            'name' => (string)($source['source_name'] ?? $source['name'] ?? 'source'),
            'path' => (string)($source['path'] ?? ''),
            'targetId' => (string)($target['id'] ?? $source['target_id'] ?? ''),
            'targetType' => (string)($target['type'] ?? $source['target_type'] ?? ''),
            'transport' => $this->transportForTarget((string)($target['type'] ?? $source['target_type'] ?? '')),
            'error' => $error,
            'lineCount' => 0,
        ];
    }

    private function primaryRemoteFilter(array $filters): ?array {
        $filters = LoggerFilterMatcher::enabledFilters($filters);
        if ($filters === []) {
            return null;
        }

        $filter = $filters[0];
        $filter['mode'] = LoggerFilterMatcher::normalizeMode((string)($filter['mode'] ?? 'contains_all'));
        return $filter;
    }

    private function summarizeFilters(array $filters): array {
        return array_map(function(array $filter): array {
            return [
                'id' => (string)($filter['id'] ?? ''),
                'name' => (string)($filter['name'] ?? ''),
                'mode' => LoggerFilterMatcher::normalizeMode((string)($filter['mode'] ?? 'contains_all')),
                'pattern' => (string)($filter['pattern'] ?? ''),
                'case_sensitive' => (bool)($filter['case_sensitive'] ?? false),
                'terms' => LoggerFilterMatcher::termsForPattern((string)($filter['pattern'] ?? '')),
            ];
        }, LoggerFilterMatcher::enabledFilters($filters));
    }

    private function transportForTarget(string $type): string {
        return match ($type) {
            'ssh' => 'ssh',
            'local' => 'local',
            'docker', 'docker-exec' => 'docker',
            default => 'unknown',
        };
    }

    private function sortLines(array &$lines): void {
        usort($lines, function(array $a, array $b): int {
            $aTime = $a['timestamp'] ?? null;
            $bTime = $b['timestamp'] ?? null;
            if ($aTime !== null && $bTime !== null && $aTime !== $bTime) {
                return strcmp($aTime, $bTime);
            }
            if ($aTime !== null && $bTime === null) {
                return -1;
            }
            if ($aTime === null && $bTime !== null) {
                return 1;
            }
            return ($a['sourceOrder'] <=> $b['sourceOrder']) ?: ($a['lineOrder'] <=> $b['lineOrder']);
        });
    }

    private function detectTimestamp(string $line): ?string {
        if (preg_match('/\b(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?\b/', $line, $matches) === 1) {
            $timestamp = strtotime(str_replace('T', ' ', $matches[1]));
            return $timestamp !== false ? gmdate('c', $timestamp) : null;
        }
        if (preg_match('/\b([A-Z][a-z]{2}\s+\d{1,2}\s+\d{2}:\d{2}:\d{2})\b/', $line, $matches) === 1) {
            $timestamp = strtotime($matches[1] . ' ' . gmdate('Y'));
            return $timestamp !== false ? gmdate('c', $timestamp) : null;
        }
        return null;
    }

    private function lineId(array $source, array $line, int $sourceIndex, int $lineIndex): string {
        return hash('sha1', implode('|', [
            (string)($source['id'] ?? $sourceIndex),
            (string)($source['path'] ?? ''),
            (string)($line['lineNumber'] ?? ''),
            (string)($line['line'] ?? ''),
            (string)$lineIndex,
        ]));
    }

    private function normalizeQuery(string $query): string {
        $query = trim(str_replace(["\0", "\r", "\n"], ' ', $query));
        return substr($query, 0, 200);
    }

    private function boundedInt(int $value, int $min, int $max, int $default): int {
        if ($value <= 0) {
            return $default;
        }
        return max($min, min($max, $value));
    }

    private function readSuccess(array $lines): array {
        return [
            'success' => true,
            'error' => null,
            'lines' => $lines,
        ];
    }

    private function readError(string $error): array {
        return [
            'success' => false,
            'error' => $error,
            'lines' => [],
        ];
    }

    private function remoteTailScript(): string {
        return <<<'SH'
p="$1"
n="$2"
case "$n" in ''|*[!0-9]*) n=200 ;; esac
if [ -z "$p" ]; then printf 'ERR\tPath is required\n'; exit 2; fi
if [ ! -e "$p" ]; then printf 'ERR\tPath does not exist: %s\n' "$p"; exit 2; fi
if [ -d "$p" ]; then printf 'ERR\tPath is a directory: %s\n' "$p"; exit 3; fi
if [ ! -r "$p" ]; then printf 'ERR\tFile is not readable: %s\n' "$p"; exit 4; fi
tail -n "$n" -- "$p" 2>/dev/null | while IFS= read -r line || [ -n "$line" ]; do
  encoded=$(printf '%s' "$line" | base64 | tr -d '\n')
  printf 'LINE\t\t%s\n' "$encoded"
done
SH;
    }

    private function remoteSearchScript(): string {
        return <<<'SH'
p="$1"
q="$2"
limit="$3"
case_sensitive="$4"
case "$limit" in ''|*[!0-9]*) limit=200 ;; esac
if [ -z "$p" ]; then printf 'ERR\tPath is required\n'; exit 2; fi
if [ -z "$q" ]; then printf 'ERR\tSearch query is required\n'; exit 2; fi
if [ ! -e "$p" ]; then printf 'ERR\tPath does not exist: %s\n' "$p"; exit 2; fi
if [ -d "$p" ]; then printf 'ERR\tPath is a directory: %s\n' "$p"; exit 3; fi
if [ ! -r "$p" ]; then printf 'ERR\tFile is not readable: %s\n' "$p"; exit 4; fi
count=0
line_number=0
while IFS= read -r line || [ -n "$line" ]; do
  line_number=$((line_number + 1))
  if [ "$case_sensitive" = "1" ]; then
    printf '%s\n' "$line" | grep -F -- "$q" >/dev/null 2>&1
  else
    printf '%s\n' "$line" | grep -Fi -- "$q" >/dev/null 2>&1
  fi
  if [ "$?" -eq 0 ]; then
    encoded=$(printf '%s' "$line" | base64 | tr -d '\n')
    printf 'LINE\t%s\t%s\n' "$line_number" "$encoded"
    count=$((count + 1))
    if [ "$count" -ge "$limit" ]; then exit 0; fi
  fi
done < "$p"
SH;
    }

    private function remoteFilterScript(): string {
        return <<<'SH'
p="$1"
mode="$2"
pattern="$3"
limit="$4"
case_sensitive="$5"
case "$limit" in ''|*[!0-9]*) limit=200 ;; esac
if [ -z "$p" ]; then printf 'ERR\tPath is required\n'; exit 2; fi
if [ -z "$pattern" ]; then printf 'ERR\tSaved filter pattern is required\n'; exit 2; fi
if [ ! -e "$p" ]; then printf 'ERR\tPath does not exist: %s\n' "$p"; exit 2; fi
if [ -d "$p" ]; then printf 'ERR\tPath is a directory: %s\n' "$p"; exit 3; fi
if [ ! -r "$p" ]; then printf 'ERR\tFile is not readable: %s\n' "$p"; exit 4; fi
terms=$(printf '%s' "$pattern" | tr '+,;|\r\n\t' '       ')
if [ -z "$(printf '%s' "$terms" | tr -d ' ')" ]; then printf 'ERR\tSaved filter pattern needs searchable terms\n'; exit 2; fi

line_matches() {
  candidate="$1"
  matched=0
  total=0
  for term in $terms; do
    [ -z "$term" ] && continue
    total=$((total + 1))
    if [ "$case_sensitive" = "1" ]; then
      printf '%s\n' "$candidate" | grep -F -- "$term" >/dev/null 2>&1
    else
      printf '%s\n' "$candidate" | grep -Fi -- "$term" >/dev/null 2>&1
    fi
    if [ "$?" -eq 0 ]; then
      if [ "$mode" = "contains_any" ]; then return 0; fi
      matched=$((matched + 1))
    elif [ "$mode" != "contains_any" ]; then
      return 1
    fi
  done
  if [ "$mode" = "contains_any" ]; then return 1; fi
  [ "$total" -gt 0 ] && [ "$matched" -eq "$total" ]
}

count=0
line_number=0
while IFS= read -r line || [ -n "$line" ]; do
  line_number=$((line_number + 1))
  if line_matches "$line"; then
    encoded=$(printf '%s' "$line" | base64 | tr -d '\n')
    printf 'LINE\t%s\t%s\n' "$line_number" "$encoded"
    count=$((count + 1))
    if [ "$count" -ge "$limit" ]; then exit 0; fi
  fi
done < "$p"
SH;
    }

    private function buildSshCommand(array $connection, string $remoteCommand): array {
        $host = trim((string)($connection['host'] ?? ''));
        $user = trim((string)($connection['user'] ?? ''));
        $port = (int)($connection['port'] ?? 22);
        $keyRef = $connection['keyRef'] ?? $connection['keySecret'] ?? null;
        $passwordRef = $connection['passwordRef'] ?? $connection['passwordSecret'] ?? null;

        if ($host === '' || $user === '') {
            return ['success' => false, 'error' => 'SSH target requires host and user'];
        }

        $cleanup = [];
        $env = [];
        $cmd = [];
        $passwordValue = null;

        if (is_string($passwordRef) && trim($passwordRef) !== '') {
            $passwordValue = $this->config->getSecret($passwordRef);
            if ($passwordValue === null) {
                return ['success' => false, 'error' => "SSH password secret '{$passwordRef}' not found"];
            }
            if ($this->commandExists('sshpass')) {
                $passwordPath = $this->writeTempSecretFile($passwordValue, 'doki-logger-ssh-pass-');
                $cleanup[] = $passwordPath;
                $cmd[] = 'sshpass';
                $cmd[] = '-f';
                $cmd[] = $passwordPath;
            } else {
                $askpass = $this->prepareSshAskpass($passwordValue);
                $env = array_merge($env, $askpass['env']);
                $cleanup = array_merge($cleanup, $askpass['cleanup']);
            }
        }

        $cmd[] = 'ssh';
        $cmd[] = '-o';
        $cmd[] = 'StrictHostKeyChecking=no';
        $cmd[] = '-o';
        $cmd[] = 'UserKnownHostsFile=/dev/null';
        $cmd[] = '-o';
        $cmd[] = 'ConnectTimeout=8';
        $cmd[] = '-o';
        $cmd[] = 'BatchMode=' . ($passwordValue !== null ? 'no' : 'yes');
        if ($passwordValue !== null) {
            $cmd[] = '-o';
            $cmd[] = 'PreferredAuthentications=publickey,password,keyboard-interactive';
            $cmd[] = '-o';
            $cmd[] = 'PasswordAuthentication=yes';
        }
        $cmd[] = '-p';
        $cmd[] = (string)$port;

        $keyRef = is_string($keyRef) ? trim($keyRef) : '';
        if ($keyRef !== '') {
            $keyValue = $this->config->getSecret($keyRef);
            if (!$keyValue) {
                $this->cleanupPaths($cleanup);
                return ['success' => false, 'error' => "SSH key secret '{$keyRef}' not found"];
            }
            $keyPath = $this->writeTempSecretFile($keyValue, 'doki-logger-ssh-key-');
            $cleanup[] = $keyPath;
            $cmd[] = '-i';
            $cmd[] = $keyPath;
            $cmd[] = '-o';
            $cmd[] = 'IdentitiesOnly=yes';
        }

        $cmd[] = $user . '@' . $host;
        $cmd[] = $remoteCommand;

        return [
            'success' => true,
            'cmd' => $cmd,
            'env' => $env,
            'cleanup' => $cleanup,
        ];
    }

    private function buildDockerCommandPrefix(array $target): array {
        $connection = $target['connection'] ?? [];
        if (!is_array($connection)) {
            $connection = [];
        }

        $cmd = ['docker'];
        $env = [];
        $cleanup = [];

        if (!empty($connection['socket'])) {
            $cmd[] = '-H';
            $cmd[] = 'unix://' . (string)$connection['socket'];
        } elseif (!empty($connection['host'])) {
            $port = $connection['port'] ?? 2375;
            $cmd[] = '-H';
            $cmd[] = 'tcp://' . (string)$connection['host'] . ':' . (string)$port;
        } elseif (!empty($connection['sshTargetId'])) {
            $sshTarget = $this->config->getTarget((string)$connection['sshTargetId']);
            if (!is_array($sshTarget) || ($sshTarget['type'] ?? '') !== 'ssh') {
                return ['success' => false, 'error' => "Docker SSH target '{$connection['sshTargetId']}' not found"];
            }
            $secretBundle = $this->buildDockerSshSecrets($sshTarget['connection'] ?? []);
            if (!$secretBundle['success']) {
                return $secretBundle;
            }
            $env = array_merge($env, $secretBundle['env']);
            $cleanup = array_merge($cleanup, $secretBundle['cleanup']);
            $dockerHost = $this->dockerSshHost($sshTarget['connection'] ?? []);
            if ($dockerHost === null) {
                return ['success' => false, 'error' => 'Docker SSH target requires host and user'];
            }
            $env['DOCKER_HOST'] = $dockerHost;
        } elseif (!empty($connection['ssh']) && is_array($connection['ssh'])) {
            $secretBundle = $this->buildDockerSshSecrets($connection['ssh']);
            if (!$secretBundle['success']) {
                return $secretBundle;
            }
            $env = array_merge($env, $secretBundle['env']);
            $cleanup = array_merge($cleanup, $secretBundle['cleanup']);
            $dockerHost = $this->dockerSshHost($connection['ssh']);
            if ($dockerHost === null) {
                return ['success' => false, 'error' => 'Docker SSH connection requires host and user'];
            }
            $env['DOCKER_HOST'] = $dockerHost;
        }

        return [
            'success' => true,
            'cmd' => $cmd,
            'env' => $env,
            'cleanup' => $cleanup,
        ];
    }

    private function dockerPathContext(string $path): array {
        if (preg_match('#^/containers/([^/]+)(/.*)$#', $path, $matches) !== 1) {
            return [
                'success' => false,
                'error' => 'Docker targets require a /containers/<id>/<path> source path',
            ];
        }

        return [
            'success' => true,
            'container' => $matches[1],
            'path' => $matches[2],
        ];
    }

    private function dockerSshHost(array $ssh): ?string {
        $host = trim((string)($ssh['host'] ?? ''));
        $user = trim((string)($ssh['user'] ?? ''));
        if ($host === '' || $user === '') {
            return null;
        }
        $port = (int)($ssh['port'] ?? 22);
        return 'ssh://' . $user . '@' . $host . ($port !== 22 ? ':' . $port : '');
    }

    private function buildDockerSshSecrets(array $ssh): array {
        $env = [];
        $cleanup = [];

        $keyRef = $ssh['keyRef'] ?? $ssh['keySecret'] ?? null;
        $keyRef = is_string($keyRef) ? trim($keyRef) : '';
        if ($keyRef !== '') {
            $keyValue = $this->config->getSecret($keyRef);
            if (!$keyValue) {
                return ['success' => false, 'error' => "SSH key secret '{$keyRef}' not found"];
            }
            $keyPath = $this->writeTempSecretFile($keyValue, 'doki-logger-docker-ssh-key-');
            $cleanup[] = $keyPath;
            $sshCommand = 'ssh -i ' . escapeshellarg($keyPath) . ' -o IdentitiesOnly=yes -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null';
            $env['DOCKER_SSH_COMMAND'] = $sshCommand;
            $env['GIT_SSH_COMMAND'] = $sshCommand;
        }

        $passwordRef = $ssh['passwordRef'] ?? $ssh['passwordSecret'] ?? null;
        $passwordRef = is_string($passwordRef) ? trim($passwordRef) : '';
        if ($passwordRef !== '') {
            $passwordValue = $this->config->getSecret($passwordRef);
            if ($passwordValue === null) {
                $this->cleanupPaths($cleanup);
                return ['success' => false, 'error' => "SSH password secret '{$passwordRef}' not found"];
            }
            $askpass = $this->prepareSshAskpass($passwordValue);
            $env = array_merge($env, $askpass['env']);
            $cleanup = array_merge($cleanup, $askpass['cleanup']);
        }

        return ['success' => true, 'env' => $env, 'cleanup' => $cleanup];
    }

    private function runCommand(array $cmd, int $timeout = 10, array $env = []): array {
        $command = $this->buildShellCommand($cmd, $env) . ' 2>&1';
        $process = @proc_open(['/bin/sh', '-lc', $command], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($process)) {
            return ['success' => false, 'output' => 'Unable to start command', 'exitCode' => 1];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output = '';
        $deadline = microtime(true) + max(1, $timeout);
        $timedOut = false;
        $resolvedExitCode = null;

        while (true) {
            $status = proc_get_status($process);
            $running = !empty($status['running']);
            if (!$running && isset($status['exitcode']) && $status['exitcode'] >= 0) {
                $resolvedExitCode = (int)$status['exitcode'];
            }

            foreach ([1, 2] as $index) {
                $chunk = stream_get_contents($pipes[$index]);
                if ($chunk !== false && $chunk !== '') {
                    $output .= $chunk;
                }
            }

            if (!$running) {
                break;
            }
            if (microtime(true) >= $deadline) {
                $timedOut = true;
                @proc_terminate($process);
                usleep(100000);
                $status = proc_get_status($process);
                if (!empty($status['running'])) {
                    @proc_terminate($process, 9);
                }
                break;
            }
            usleep(25000);
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
        if ($timedOut) {
            $exitCode = 124;
            $output = trim($output . "\nCommand timed out after {$timeout}s");
        }

        return [
            'success' => $exitCode === 0,
            'output' => trim($output),
            'exitCode' => $exitCode,
        ];
    }

    private function buildShellCommand(array $cmd, array $env = []): string {
        $parts = [];
        foreach ($env as $key => $value) {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string)$key)) {
                continue;
            }
            $parts[] = $key . '=' . escapeshellarg((string)$value);
        }
        foreach ($cmd as $part) {
            $parts[] = escapeshellarg((string)$part);
        }
        return implode(' ', $parts);
    }

    private function commandExists(string $command): bool {
        $result = $this->runCommand(['sh', '-c', 'command -v ' . escapeshellarg($command)], 4);
        return $result['success'];
    }

    private function writeTempSecretFile(string $content, string $prefix): string {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        file_put_contents($path, $content);
        chmod($path, 0600);
        return $path;
    }

    private function prepareSshAskpass(string $password): array {
        $passwordPath = $this->writeTempSecretFile($password, 'doki-logger-ssh-pass-');
        $askpassPath = tempnam(sys_get_temp_dir(), 'doki-logger-ssh-askpass-');
        $script = "#!/bin/sh\ncat " . escapeshellarg($passwordPath) . "\n";
        file_put_contents($askpassPath, $script);
        chmod($askpassPath, 0700);

        return [
            'env' => [
                'SSH_ASKPASS' => $askpassPath,
                'SSH_ASKPASS_REQUIRE' => 'force',
                'DISPLAY' => 'doki',
            ],
            'cleanup' => [$passwordPath, $askpassPath],
        ];
    }

    private function cleanupPaths(array $paths): void {
        foreach ($paths as $path) {
            if (is_string($path) && $path !== '') {
                @unlink($path);
            }
        }
    }
}
