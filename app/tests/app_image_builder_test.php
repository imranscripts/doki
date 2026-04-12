<?php

require_once __DIR__ . '/../includes/AppImageBuilder.php';

function fail_test(string $message): void {
    throw new RuntimeException($message);
}

function assert_true($condition, string $message): void {
    if (!$condition) {
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

function with_env(array $values, callable $callback): void {
    $previous = [];
    foreach ($values as $key => $value) {
        $previous[$key] = getenv($key);
        if ($value === null) {
            putenv($key);
        } else {
            putenv($key . '=' . $value);
        }
    }

    try {
        $callback();
    } finally {
        foreach ($previous as $key => $value) {
            if ($value === false || $value === null || $value === '') {
                putenv($key);
            } else {
                putenv($key . '=' . $value);
            }
        }
    }
}

function test_python_packages_require_custom_image(): void {
    $builder = new AppImageBuilder('python-only-app', [
        'runtime' => [
            'pythonPackages' => ['pdf2docx==0.5.12'],
        ],
    ]);

    assert_true($builder->needsCustomImage(), 'Python runtime packages should trigger a custom image.');
}

function test_generate_dockerfile_installs_python_runtime(): void {
    $builder = new AppImageBuilder('pdf-to-word', [
        'runtime' => [
            'phpExtensions' => ['zip'],
            'pythonPackages' => ['pdf2docx==0.5.12'],
        ],
    ]);

    $dockerfile = $builder->generateDockerfile();

    assert_contains("'python3'", $dockerfile, 'Dockerfile should install Python.');
    assert_contains("'python3-venv'", $dockerfile, 'Dockerfile should install python3-venv.');
    assert_contains("'python3-pip'", $dockerfile, 'Dockerfile should install python3-pip.');
    assert_contains("docker-php-ext-install zip", $dockerfile, 'Dockerfile should keep PHP extension installation.');
    assert_contains("python3 -m venv /opt/doki-python", $dockerfile, 'Dockerfile should create the app Python virtualenv.');
    assert_contains("/opt/doki-python/bin/pip install --no-cache-dir 'pdf2docx==0.5.12'", $dockerfile, 'Dockerfile should install declared Python packages.');
}

function test_docker_env_falls_back_to_writable_paths(): void {
    $invalidDockerConfig = tempnam(sys_get_temp_dir(), 'doki-docker-config-file-');
    $invalidHome = tempnam(sys_get_temp_dir(), 'doki-home-file-');
    if ($invalidDockerConfig === false || $invalidHome === false) {
        fail_test('Failed to create temporary files for docker env test.');
    }

    try {
        with_env([
            'DOCKER_HOST' => 'unix:///tmp/test-docker.sock',
            'DOCKER_CONFIG' => $invalidDockerConfig,
            'HOME' => $invalidHome,
        ], static function () use ($invalidDockerConfig, $invalidHome): void {
            $builder = new AppImageBuilder('env-test', []);
            $env = invoke_private($builder, 'getDockerCommandEnv');

            assert_same('unix:///tmp/test-docker.sock', $env['DOCKER_HOST'] ?? null, 'Docker host should preserve explicit configuration.');
            assert_true(($env['DOCKER_CONFIG'] ?? '') !== $invalidDockerConfig, 'Docker config should not reuse an invalid file path.');
            assert_true(($env['HOME'] ?? '') !== $invalidHome, 'HOME should not reuse an invalid file path.');
            assert_same(
                realpath(__DIR__ . '/../data/docker-config'),
                realpath($env['DOCKER_CONFIG'] ?? ''),
                'Docker config should fall back to the app data directory.'
            );
            assert_same(
                realpath(sys_get_temp_dir()),
                realpath($env['HOME'] ?? ''),
                'HOME should fall back to a writable temp directory.'
            );
        });
    } finally {
        @unlink($invalidDockerConfig);
        @unlink($invalidHome);
    }
}

test_python_packages_require_custom_image();
test_generate_dockerfile_installs_python_runtime();
test_docker_env_falls_back_to_writable_paths();

echo "AppImageBuilder tests passed.\n";
