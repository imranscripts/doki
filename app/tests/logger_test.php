<?php

require_once __DIR__ . '/../includes/LoggerFilterMatcher.php';
require_once __DIR__ . '/../includes/LoggerManager.php';
require_once __DIR__ . '/../includes/LoggerTargetLogReader.php';
require_once __DIR__ . '/../includes/LoggerTargetResolver.php';

final class FakeLoggerConfigManager extends ConfigManager {
    /** @var array<string, array<string, mixed>> */
    private array $targets;
    /** @var array<string, array<string, mixed>> */
    private array $environments;
    /** @var array<string, string> */
    private array $secrets;

    /**
     * @param array<int, array<string, mixed>> $targets
     * @param array<string, array<string, mixed>> $environments
     * @param array<string, string> $secrets
     */
    public function __construct(array $targets = [], array $environments = [], array $secrets = []) {
        $this->targets = [];
        foreach ($targets as $target) {
            $this->targets[(string)($target['id'] ?? '')] = $target;
        }
        $this->environments = $environments;
        $this->secrets = $secrets;
    }

    public function getTargets(): array {
        return array_values($this->targets);
    }

    public function getTarget(string $id): ?array {
        return $this->targets[$id] ?? null;
    }

    public function getEnvironments(): array {
        return $this->environments;
    }

    public function getEnvironment(string $id): ?array {
        return $this->environments[$id] ?? null;
    }

    public function getSecret(string $key): ?string {
        return $this->secrets[$key] ?? null;
    }

    public function setEnvironmentTarget(string $environmentId, string $key, string $targetId): void {
        $this->environments[$environmentId]['targets'][$key] = $targetId;
    }
}

function fail_test(string $message): void {
    throw new RuntimeException($message);
}

function assert_true($condition, string $message): void {
    if (!$condition) {
        fail_test($message);
    }
}

function assert_false($condition, string $message): void {
    if ($condition) {
        fail_test($message);
    }
}

function assert_same($expected, $actual, string $message): void {
    if ($expected !== $actual) {
        fail_test($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
    }
}

function assert_contains(string $needle, string $haystack, string $message): void {
    if (!str_contains($haystack, $needle)) {
        fail_test($message . "\nMissing fragment: " . $needle . "\nIn: " . $haystack);
    }
}

function invoke_private(object $object, string $method, array $args = []) {
    $reflection = new ReflectionClass($object);
    $refMethod = $reflection->getMethod($method);
    $refMethod->setAccessible(true);
    return $refMethod->invokeArgs($object, $args);
}

function test_pdo(): PDO {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    return $pdo;
}

function target_fixture(string $id, string $type, array $capabilities = [], array $connection = []): array {
    return [
        'id' => $id,
        'name' => ucfirst($id),
        'type' => $type,
        'connection' => $connection,
        'verifiedCapabilities' => $capabilities,
    ];
}

function fake_config(): FakeLoggerConfigManager {
    return new FakeLoggerConfigManager([
        target_fixture('local', 'local', ['exec.docker']),
        target_fixture('local-alt', 'local', ['exec.docker']),
        target_fixture('ssh-api', 'ssh', ['exec.ssh'], ['host' => 'example.test', 'user' => 'doki']),
        target_fixture('cache', 'redis', []),
    ], [
        'prod' => [
            'name' => 'Production',
            'targets' => [
                'web' => 'local',
                'api' => 'ssh-api',
                'cache' => 'cache',
                'ghost' => 'missing-target',
            ],
        ],
    ]);
}

function test_schema_and_crud_use_app_tables(): void {
    $manager = new LoggerManager(test_pdo());
    $resolver = new LoggerTargetResolver(fake_config());

    $tables = ['app_logger_entries', 'app_logger_entry_sources', 'app_logger_groups', 'app_logger_group_entries', 'app_logger_filters'];
    $db = new ReflectionProperty($manager, 'db');
    $db->setAccessible(true);
    /** @var PDO $pdo */
    $pdo = $db->getValue($manager);

    foreach ($tables as $table) {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?");
        $stmt->execute([$table]);
        assert_same($table, $stmt->fetchColumn(), "Logger schema should create {$table}.");
    }

    $user = ['id' => 'tester', 'username' => 'tester', 'role' => 'admin'];
    $entryResult = $manager->saveEntry([
        'name' => 'Runtime Errors',
        'mode' => 'direct',
        'enabled' => true,
        'sources' => [[
            'source_name' => 'runtime.log',
            'target_id' => 'local',
            'path' => '/tmp/runtime.log',
            'target_type' => 'local',
        ]],
    ], $user, $resolver);

    assert_true($entryResult['success'] ?? false, 'Entry save should succeed.');
    $entry = $entryResult['entry'];
    assert_same(1, count($entry['sources'] ?? []), 'Saved entry should include one source.');

    $groupResult = $manager->saveGroup([
        'name' => 'Runtime Group',
        'entry_ids' => [$entry['id']],
    ], $user);
    assert_true($groupResult['success'] ?? false, 'Group save should succeed.');

    $filterResult = $manager->saveFilter([
        'owner_type' => 'entry',
        'owner_id' => $entry['id'],
        'name' => 'Fatal 500s',
        'mode' => 'contains_all',
        'pattern' => '500 + fatal',
        'enabled' => true,
    ], $user);
    assert_true($filterResult['success'] ?? false, 'Filter save should succeed.');

    assert_same(['entries' => 1, 'sources' => 1, 'groups' => 1, 'filters' => 1], $manager->getStats(), 'Stats should reflect saved logger definitions.');

    $deleteEntry = $manager->deleteEntry($entry['id']);
    assert_true($deleteEntry['success'] ?? false, 'Deleting the entry should succeed.');
    assert_same(['entries' => 0, 'sources' => 0, 'groups' => 1, 'filters' => 0], $manager->getStats(), 'Deleting an entry should delete its sources, group membership, and filters.');
}

function test_target_and_environment_resolution(): void {
    $config = fake_config();
    $resolver = new LoggerTargetResolver($config);

    $targets = $resolver->listLogCapableTargets();
    $targetIds = array_map(static fn(array $target): string => (string)$target['id'], $targets);
    sort($targetIds);
    assert_same(['local', 'local-alt', 'ssh-api'], $targetIds, 'Resolver should expose only log-capable direct targets.');

    $environment = $resolver->resolveEnvironment('prod');
    assert_same(2, count($environment['logCapableTargets'] ?? []), 'Environment should expose two log-capable targets.');
    assert_same(1, count($environment['nonLogTargets'] ?? []), 'Environment should report non-log targets.');
    assert_same(1, count($environment['missingTargets'] ?? []), 'Environment should report missing target mappings.');

    $direct = $resolver->resolveSourceTarget(['mode' => 'direct'], ['target_id' => 'local']);
    assert_true($direct['success'] ?? false, 'Direct source should resolve.');
    assert_same('local', $direct['target']['id'] ?? null, 'Direct source should resolve to the configured target.');

    $environmentSource = $resolver->resolveSourceTarget(
        ['mode' => 'environment', 'environment_id' => 'prod'],
        ['environment_target_key' => 'api']
    );
    assert_true($environmentSource['success'] ?? false, 'Environment source should resolve.');
    assert_same('ssh-api', $environmentSource['target']['id'] ?? null, 'Environment source should resolve through the environment key.');

    $config->setEnvironmentTarget('prod', 'api', 'local-alt');
    $retargeted = $resolver->resolveSourceTarget(
        ['mode' => 'environment', 'environment_id' => 'prod'],
        ['environment_target_key' => 'api']
    );
    assert_same('local-alt', $retargeted['target']['id'] ?? null, 'Environment-backed sources should follow updated environment target mappings.');
}

function test_source_validation_requires_environment_coverage(): void {
    $resolver = new LoggerTargetResolver(fake_config());
    $entry = ['mode' => 'environment', 'environment_id' => 'prod'];

    $invalid = $resolver->validateEntrySources($entry, [[
        'source_name' => 'web.log',
        'environment_target_key' => 'web',
        'path' => '/var/log/web.log',
    ]]);

    assert_false($invalid['valid'] ?? true, 'Environment entry should be invalid when a log-capable target is uncovered.');
    assert_contains("Environment target 'api' requires at least one source file", implode("\n", $invalid['errors'] ?? []), 'Validation should name the uncovered environment target key.');

    $valid = $resolver->validateEntrySources($entry, [[
        'source_name' => 'web.log',
        'environment_target_key' => 'web',
        'path' => '/var/log/web.log',
    ], [
        'source_name' => 'api.log',
        'environment_target_key' => 'api',
        'path' => '/var/log/api.log',
    ]]);

    assert_true($valid['valid'] ?? false, 'Environment entry should be valid when every log-capable target has a source.');
}

function test_filter_matching_modes_and_terms(): void {
    $allFilter = [
        'mode' => 'contains_all',
        'pattern' => '500 + php:fatal',
        'case_sensitive' => false,
        'enabled' => true,
    ];
    assert_same(['500', 'php:fatal'], LoggerFilterMatcher::termsForPattern($allFilter['pattern']), 'Filter parser should split plus-delimited terms.');
    assert_true(LoggerFilterMatcher::matchLine($allFilter, 'GET /checkout 500 PHP:FATAL'), 'Contains-all filters should match every term case-insensitively by default.');
    assert_false(LoggerFilterMatcher::matchLine($allFilter, 'GET /checkout 500 warning'), 'Contains-all filters should reject lines missing one term.');

    $anyFilter = [
        'mode' => 'contains_any',
        'pattern' => 'timeout php:fatal',
        'case_sensitive' => false,
        'enabled' => true,
    ];
    assert_true(LoggerFilterMatcher::matchLine($anyFilter, 'worker timeout waiting'), 'Contains-any filters should match one term.');
    assert_false(LoggerFilterMatcher::matchLine($anyFilter, 'worker completed'), 'Contains-any filters should reject lines with no terms.');

    $caseFilter = [
        'mode' => 'contains_all',
        'pattern' => 'Fatal',
        'case_sensitive' => true,
        'enabled' => true,
    ];
    assert_false(LoggerFilterMatcher::matchLine($caseFilter, 'fatal error'), 'Case-sensitive filters should preserve case.');

    $lines = LoggerFilterMatcher::filterLines([[
        'entryName' => 'Checkout',
        'sourceName' => 'api.log',
        'path' => '/var/log/api.log',
        'line' => 'ok',
    ]], [[
        'mode' => 'contains_all',
        'pattern' => 'checkout api.log',
        'case_sensitive' => false,
        'enabled' => true,
    ]]);
    assert_same(1, count($lines), 'Filter matching should include entry/source/path context.');
}

function test_local_log_reader_handles_shell_sensitive_paths_and_queries(): void {
    $dir = sys_get_temp_dir() . '/doki-logger-test-' . bin2hex(random_bytes(4));
    if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
        fail_test('Failed to create temporary test directory.');
    }

    $path = $dir . '/runtime ; touch pwned.log';
    $pwned = $dir . '/pwned.log';
    file_put_contents($path, "2026-06-08 14:00:00 ok\n2026-06-08 14:00:01 literal needle; \$(bad)\n");

    try {
        $config = new FakeLoggerConfigManager([
            target_fixture('local', 'local', ['exec.docker']),
        ]);
        $resolver = new LoggerTargetResolver($config);
        $reader = new LoggerTargetLogReader($config, $resolver);
        $entry = [
            'id' => 'entry',
            'name' => 'Entry',
            'mode' => 'direct',
            'sources' => [[
                'id' => 'source',
                'source_name' => 'runtime.log',
                'target_id' => 'local',
                'path' => $path,
                'target_type' => 'local',
                'enabled' => true,
            ]],
        ];

        $tail = $reader->tailEntry($entry, 5);
        assert_true($tail['success'] ?? false, 'Tail should succeed for paths with shell-sensitive characters.');
        assert_same(2, count($tail['lines'] ?? []), 'Tail should read the exact file path.');
        assert_false(file_exists($pwned), 'Shell-sensitive path should not execute as a shell command.');

        $search = $reader->searchEntry($entry, 'needle; $(bad)', 10);
        assert_true($search['success'] ?? false, 'Search should treat shell-sensitive queries literally.');
        assert_same(1, count($search['lines'] ?? []), 'Search should find the literal query once.');

        $remoteCommand = invoke_private($reader, 'remoteCommand', ['echo "$1"', ['one; touch /tmp/owned', 'two $(bad)']]);
        assert_contains("'one; touch /tmp/owned'", $remoteCommand, 'Remote command args should be shell-escaped.');
        assert_contains("'two $(bad)'", $remoteCommand, 'Remote command query args should be shell-escaped.');
    } finally {
        @unlink($path);
        @unlink($pwned);
        @rmdir($dir);
    }
}

$tests = [
    'schema and CRUD use app tables' => 'test_schema_and_crud_use_app_tables',
    'target and environment resolution' => 'test_target_and_environment_resolution',
    'source validation requires environment coverage' => 'test_source_validation_requires_environment_coverage',
    'filter matching modes and terms' => 'test_filter_matching_modes_and_terms',
    'local log reader handles shell-sensitive paths and queries' => 'test_local_log_reader_handles_shell_sensitive_paths_and_queries',
];

$failures = [];

foreach ($tests as $label => $test) {
    try {
        $test();
        fwrite(STDOUT, "PASS {$label}\n");
    } catch (Throwable $error) {
        $failures[] = [$label, $error->getMessage()];
        fwrite(STDOUT, "FAIL {$label}\n");
    }
}

if ($failures !== []) {
    fwrite(STDOUT, "\nFailure details:\n");
    foreach ($failures as [$label, $message]) {
        fwrite(STDOUT, "- {$label}: {$message}\n");
    }
    exit(1);
}

fwrite(STDOUT, "\nAll logger tests passed.\n");
