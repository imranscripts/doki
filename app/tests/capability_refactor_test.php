<?php

require_once __DIR__ . '/../includes/CommandsManager.php';
require_once __DIR__ . '/../includes/ConfigManager.php';
require_once __DIR__ . '/../includes/TemplateManager.php';
require_once __DIR__ . '/../includes/WorkflowAIManager.php';
require_once __DIR__ . '/../includes/WorkflowStudioManager.php';

final class FakeTemplateManager extends TemplateManager {
    /** @var array<int, array<string, mixed>> */
    private array $templates;

    /**
     * @param array<int, array<string, mixed>> $templates
     */
    public function __construct(array $templates) {
        $this->templates = array_values($templates);
    }

    public function getTemplates(): array {
        return [
            'success' => true,
            'templates' => $this->templates,
        ];
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

function assert_not_contains(string $needle, string $haystack, string $message): void {
    if (str_contains($haystack, $needle)) {
        fail_test($message . "\nUnexpected fragment: " . $needle . "\nIn: " . $haystack);
    }
}

function assert_capability_gap(array $validation, string $capability, string $message): void {
    $gaps = array_map(
        static fn(array $gap): string => (string)($gap['capability'] ?? ''),
        array_filter($validation['capabilityGaps'] ?? [], 'is_array')
    );
    if (!in_array($capability, $gaps, true)) {
        fail_test($message . "\nMissing capability gap: " . $capability . "\nActual gaps: " . implode(', ', $gaps));
    }
}

function assert_no_capability_gap(array $validation, string $capability, string $message): void {
    $gaps = array_map(
        static fn(array $gap): string => (string)($gap['capability'] ?? ''),
        array_filter($validation['capabilityGaps'] ?? [], 'is_array')
    );
    if (in_array($capability, $gaps, true)) {
        fail_test($message . "\nUnexpected capability gap: " . $capability);
    }
}

function invoke_private(object $object, string $method, array $args = []) {
    $reflection = new ReflectionClass($object);
    $refMethod = $reflection->getMethod($method);
    $refMethod->setAccessible(true);
    return $refMethod->invokeArgs($object, $args);
}

function set_private(object $object, string $property, $value): void {
    $reflection = new ReflectionClass($object);
    $refProperty = $reflection->getProperty($property);
    $refProperty->setAccessible(true);
    $refProperty->setValue($object, $value);
}

function make_ai_manager(TemplateManager $templateManager): WorkflowAIManager {
    $reflection = new ReflectionClass(WorkflowAIManager::class);
    /** @var WorkflowAIManager $manager */
    $manager = $reflection->newInstanceWithoutConstructor();
    set_private($manager, 'templateManager', $templateManager);
    return $manager;
}

function make_validation_manager(TemplateManager $templateManager): WorkflowStudioManager {
    $reflection = new ReflectionClass(WorkflowStudioManager::class);
    /** @var WorkflowStudioManager $manager */
    $manager = $reflection->newInstanceWithoutConstructor();
    set_private($manager, 'commandsManager', new CommandsManager());
    set_private($manager, 'configManager', new ConfigManager());
    set_private($manager, 'templateManager', $templateManager);
    return $manager;
}

function build_custom_template(string $id, array $capabilityHints, string $name = 'Custom Template'): array {
    return [
        'id' => $id,
        'name' => $name,
        'description' => 'Custom test template',
        'category' => 'custom',
        'targetType' => null,
        'runtime' => ['image' => 'python:3.12-alpine'],
        'inputs' => [],
        'execution' => [
            'mode' => 'sync',
            'timeout' => 60,
            'script' => 'echo ok',
        ],
        'output' => ['type' => 'text'],
        'requirements' => [],
        'targetBindings' => [],
        'source' => 'custom',
        'capabilityHints' => $capabilityHints,
    ];
}

/**
 * @param array<string, array<string, mixed>> $templatesById
 * @param list<string> $ids
 * @param list<array<string, mixed>> $extras
 */
function fake_template_manager(array $templatesById, array $ids, array $extras = []): FakeTemplateManager {
    $templates = [];
    foreach ($ids as $id) {
        if (!isset($templatesById[$id])) {
            fail_test("Missing template fixture: {$id}");
        }
        $templates[] = $templatesById[$id];
    }
    foreach ($extras as $extra) {
        $templates[] = $extra;
    }
    return new FakeTemplateManager($templates);
}

function payload_transform_draft(): array {
    return [
        'id' => 'payload-transform-check',
        'name' => 'Payload Transform Check',
        'description' => 'Validation fixture',
        'executionMode' => 'sync',
        'maxParallel' => 1,
        'finalStatusPolicy' => 'fail_if_any_failed',
        'defaultTarget' => 'inherit',
        'environment' => null,
        'steps' => [
            [
                'id' => 'fetch',
                'templateId' => 'http-request',
                'target' => 'inherit',
                'dependsOn' => [],
                'onFailure' => 'stop',
                'inputs' => [
                    'path' => 'https://example.test/api/items',
                    'method' => 'GET',
                    'body' => '',
                    'headers' => '',
                ],
                'secrets' => [],
            ],
            [
                'id' => 'transform',
                'templateId' => 'shell-exec',
                'target' => 'inherit',
                'dependsOn' => ['fetch'],
                'onFailure' => 'stop',
                'inputs' => [
                    'command' => "python3 - <<'PY'\nprint('''{{steps.fetch.extract.responseBody}}''')\nPY",
                ],
                'secrets' => [],
            ],
        ],
        'inputs' => [],
        'inputContract' => [],
        'secrets' => [],
        'tags' => [],
        'icon' => null,
        'color' => null,
        'enabled' => true,
    ];
}

function http_path_payload_draft(): array {
    return [
        'id' => 'http-path-payload-check',
        'name' => 'HTTP Path Payload Check',
        'description' => 'Validation fixture',
        'executionMode' => 'sync',
        'maxParallel' => 1,
        'finalStatusPolicy' => 'fail_if_any_failed',
        'defaultTarget' => 'inherit',
        'environment' => null,
        'steps' => [
            [
                'id' => 'prepare',
                'templateId' => 'python-transform',
                'target' => 'inherit',
                'dependsOn' => [],
                'onFailure' => 'stop',
                'inputs' => [
                    'script' => "import json\nprint(json.dumps({'query': 'paris'}))",
                    'payload1' => '',
                    'payload2' => '',
                    'payload3' => '',
                    'payload4' => '',
                ],
                'secrets' => [],
            ],
            [
                'id' => 'fetch',
                'templateId' => 'http-request',
                'target' => 'inherit',
                'dependsOn' => ['prepare'],
                'onFailure' => 'stop',
                'inputs' => [
                    'path' => 'https://example.test/search?q={{steps.prepare.output}}',
                    'method' => 'GET',
                    'body' => '',
                    'headers' => '',
                ],
                'secrets' => [],
            ],
        ],
        'inputs' => [],
        'inputContract' => [],
        'secrets' => [],
        'tags' => [],
        'icon' => null,
        'color' => null,
        'enabled' => true,
    ];
}

function transform_double_parse_draft(): array {
    return [
        'id' => 'transform-double-parse',
        'name' => 'Transform Double Parse',
        'description' => 'Validation fixture',
        'executionMode' => 'sync',
        'maxParallel' => 1,
        'finalStatusPolicy' => 'fail_if_any_failed',
        'defaultTarget' => 'inherit',
        'environment' => null,
        'steps' => [
            [
                'id' => 'fetch',
                'templateId' => 'http-request',
                'target' => 'inherit',
                'dependsOn' => [],
                'onFailure' => 'stop',
                'inputs' => [
                    'path' => 'https://example.test/api/item',
                    'method' => 'GET',
                    'body' => '',
                    'headers' => '',
                ],
                'secrets' => [],
            ],
            [
                'id' => 'transform',
                'templateId' => 'python-transform',
                'target' => 'inherit',
                'dependsOn' => ['fetch'],
                'onFailure' => 'stop',
                'inputs' => [
                    'script' => "import json\npayload = json.loads(payload1 or '{}')\nprint(payload.get('id', ''))",
                    'payload1' => '{{steps.fetch.extract.responseBody}}',
                    'payload2' => '',
                    'payload3' => '',
                    'payload4' => '',
                ],
                'secrets' => [],
            ],
        ],
        'inputs' => [],
        'inputContract' => [],
        'secrets' => [],
        'tags' => [],
        'icon' => null,
        'color' => null,
        'enabled' => true,
    ];
}

function hidden_empty_input_draft(): array {
    return [
        'id' => 'hidden-empty-input',
        'name' => 'Hidden Empty Input',
        'description' => 'Validation fixture',
        'executionMode' => 'sync',
        'maxParallel' => 1,
        'finalStatusPolicy' => 'fail_if_any_failed',
        'defaultTarget' => 'inherit',
        'environment' => null,
        'steps' => [
            [
                'id' => 'fetch',
                'templateId' => 'http-request',
                'target' => 'inherit',
                'dependsOn' => [],
                'onFailure' => 'stop',
                'inputs' => [
                    'path' => 'https://example.test/api?q={{inputs.hidden_query}}',
                    'method' => 'GET',
                    'body' => '',
                    'headers' => '',
                ],
                'secrets' => [],
            ],
        ],
        'inputs' => [
            'hidden_query' => '',
        ],
        'inputContract' => [],
        'secrets' => [],
        'tags' => [],
        'icon' => null,
        'color' => null,
        'enabled' => true,
    ];
}

function scalar_transform_path_draft(): array {
    return [
        'id' => 'scalar-transform-path',
        'name' => 'Scalar Transform Path',
        'description' => 'Validation fixture',
        'executionMode' => 'sync',
        'maxParallel' => 1,
        'finalStatusPolicy' => 'fail_if_any_failed',
        'defaultTarget' => 'inherit',
        'environment' => null,
        'steps' => [
            [
                'id' => 'prepare',
                'templateId' => 'python-transform',
                'target' => 'inherit',
                'dependsOn' => [],
                'onFailure' => 'stop',
                'inputs' => [
                    'script' => "print('paris')",
                    'payload1' => '',
                    'payload2' => '',
                    'payload3' => '',
                    'payload4' => '',
                ],
                'secrets' => [],
            ],
            [
                'id' => 'fetch',
                'templateId' => 'http-request',
                'target' => 'inherit',
                'dependsOn' => ['prepare'],
                'onFailure' => 'stop',
                'inputs' => [
                    'path' => 'https://example.test/search?q={{steps.prepare.output}}',
                    'method' => 'GET',
                    'body' => '',
                    'headers' => '',
                ],
                'secrets' => [],
            ],
        ],
        'inputs' => [],
        'inputContract' => [],
        'secrets' => [],
        'tags' => [],
        'icon' => null,
        'color' => null,
        'enabled' => true,
    ];
}

function undeclared_payload_slot_draft(): array {
    return [
        'id' => 'undeclared-payload-slot',
        'name' => 'Undeclared Payload Slot',
        'description' => 'Validation fixture',
        'executionMode' => 'sync',
        'maxParallel' => 1,
        'finalStatusPolicy' => 'fail_if_any_failed',
        'defaultTarget' => 'inherit',
        'environment' => null,
        'steps' => [
            [
                'id' => 'transform',
                'templateId' => 'python-transform',
                'target' => 'inherit',
                'dependsOn' => [],
                'onFailure' => 'stop',
                'inputs' => [
                    'script' => "print(payload5)",
                    'payload1' => '',
                    'payload2' => '',
                    'payload3' => '',
                    'payload4' => '',
                ],
                'secrets' => [],
            ],
        ],
        'inputs' => [],
        'inputContract' => [],
        'secrets' => [],
        'tags' => [],
        'icon' => null,
        'color' => null,
        'enabled' => true,
    ];
}

function unused_declared_input_draft(): array {
    return [
        'id' => 'unused-declared-input',
        'name' => 'Unused Declared Input',
        'description' => 'Validation fixture',
        'executionMode' => 'sync',
        'maxParallel' => 1,
        'finalStatusPolicy' => 'fail_if_any_failed',
        'defaultTarget' => 'inherit',
        'environment' => null,
        'steps' => [
            [
                'id' => 'fetch',
                'templateId' => 'http-request',
                'target' => 'inherit',
                'dependsOn' => [],
                'onFailure' => 'stop',
                'inputs' => [
                    'path' => 'https://example.test/api?q={{inputs.country}}',
                    'method' => 'GET',
                    'body' => '',
                    'headers' => '',
                ],
                'secrets' => [],
            ],
        ],
        'inputs' => [],
        'inputContract' => [
            [
                'name' => 'country',
                'label' => 'Country',
                'type' => 'string',
                'required' => true,
            ],
            [
                'name' => 'rapidapi_key',
                'label' => 'RapidAPI key (reserved for a future step)',
                'type' => 'secret',
                'required' => false,
            ],
        ],
        'secrets' => [],
        'tags' => [],
        'icon' => null,
        'color' => null,
        'enabled' => true,
    ];
}

function validate_workflow_draft(WorkflowStudioManager $manager, array $draft): array {
    return $manager->validateDraftPayload([
        'name' => (string)($draft['name'] ?? ''),
        'description' => (string)($draft['description'] ?? ''),
        'commandId' => (string)($draft['id'] ?? ''),
        'draft' => $draft,
    ]);
}

function json_extract_gap_draft(): array {
    return [
        'id' => 'json-extract-gap',
        'name' => 'JSON Extract Gap',
        'description' => 'Validation fixture',
        'executionMode' => 'sync',
        'maxParallel' => 1,
        'finalStatusPolicy' => 'fail_if_any_failed',
        'defaultTarget' => 'inherit',
        'environment' => null,
        'steps' => [
            [
                'id' => 'fetch',
                'templateId' => 'http-request',
                'target' => 'inherit',
                'dependsOn' => [],
                'onFailure' => 'stop',
                'inputs' => [
                    'path' => 'https://example.test/api/items',
                    'method' => 'GET',
                    'body' => '',
                    'headers' => '',
                ],
                'secrets' => [],
            ],
            [
                'id' => 'follow-up',
                'templateId' => 'http-request',
                'target' => 'inherit',
                'dependsOn' => ['fetch'],
                'onFailure' => 'stop',
                'inputs' => [
                    'path' => '{{steps.fetch.output}}',
                    'method' => 'GET',
                    'body' => '',
                    'headers' => '',
                ],
                'secrets' => [],
            ],
        ],
        'inputs' => [],
        'inputContract' => [],
        'secrets' => [],
        'tags' => [],
        'icon' => null,
        'color' => null,
        'enabled' => true,
    ];
}

function json_count_gap_draft(): array {
    return [
        'id' => 'json-count-gap',
        'name' => 'JSON Count Gap',
        'description' => 'Validation fixture',
        'executionMode' => 'sync',
        'maxParallel' => 1,
        'finalStatusPolicy' => 'fail_if_any_failed',
        'defaultTarget' => 'inherit',
        'environment' => null,
        'steps' => [
            [
                'id' => 'fetch',
                'templateId' => 'http-request',
                'target' => 'inherit',
                'dependsOn' => [],
                'onFailure' => 'stop',
                'inputs' => [
                    'path' => 'https://example.test/api/items',
                    'method' => 'GET',
                    'body' => '',
                    'headers' => '',
                ],
                'secrets' => [],
            ],
            [
                'id' => 'count',
                'templateId' => 'shell-exec',
                'target' => 'inherit',
                'dependsOn' => ['fetch'],
                'onFailure' => 'stop',
                'inputs' => [
                    'command' => "count=\$(printf '%s' '{{steps.fetch.extract.responseBody}}' | jq '. | length')\nprintf '%s' \"\$count\"",
                ],
                'secrets' => [],
            ],
        ],
        'inputs' => [],
        'inputContract' => [],
        'secrets' => [],
        'tags' => [],
        'icon' => null,
        'color' => null,
        'enabled' => true,
    ];
}

$realTemplateManager = new TemplateManager();
$templatesResult = $realTemplateManager->getTemplates();
assert_true(!empty($templatesResult['success']), 'Real templates must load for the capability tests.');

$templatesById = [];
foreach ($templatesResult['templates'] as $template) {
    $templatesById[(string)$template['id']] = $template;
}

$tests = [
    'builtin capability parsing exposes hints' => function () use ($templatesById): void {
        assert_true(isset($templatesById['python-transform']), 'python-transform must exist in built-in templates.');
        assert_true(isset($templatesById['shell-exec']), 'shell-exec must exist in built-in templates.');
        assert_true(
            in_array('payload.transform', $templatesById['python-transform']['capabilityHints']['capabilities'] ?? [], true),
            'python-transform should advertise payload.transform.'
        );
        assert_true(
            (string)($templatesById['shell-exec']['capabilityHints']['dataFlow']['scriptInput'] ?? '') === 'command',
            'shell-exec should declare command as its script input.'
        );
        assert_true(
            in_array('payload1', $templatesById['python-transform']['capabilityHints']['dataFlow']['payloadInputs'] ?? [], true),
            'python-transform should declare payload inputs.'
        );
    },
    'registry prefers the best capability candidate' => function () use ($templatesById): void {
        $manager = fake_template_manager($templatesById, ['shell-exec'], [
            build_custom_template('fallback-transform', [
                'capabilities' => ['payload.transform'],
                'payloadHandling' => ['small-text'],
                'scriptRuntime' => 'python',
                'dataFlow' => [
                    'scriptInput' => 'script',
                    'payloadInputs' => [],
                ],
            ], 'Fallback Transform'),
            build_custom_template('custom-transform', [
                'capabilities' => ['payload.transform'],
                'payloadHandling' => ['structured-json', 'large-text'],
                'scriptRuntime' => 'node',
                'dataFlow' => [
                    'scriptInput' => 'script',
                    'payloadInputs' => ['payload1', 'payload2'],
                ],
            ], 'Custom Transform'),
        ]);

        $candidates = $manager->getBestTemplateCandidatesForCapability('payload.transform', [
            'payloadHandling' => ['structured-json'],
            'requiresScriptInput' => true,
            'requiresPayloadInputs' => true,
        ]);

        assert_true(count($candidates) >= 2, 'Expected at least two payload.transform candidates.');
        assert_same('custom-transform', $candidates[0]['id'] ?? null, 'Registry should rank the custom preferred transform first.');
    },
    'workflow AI prompt uses installed built-in capability hints' => function () use ($realTemplateManager): void {
        $manager = make_ai_manager($realTemplateManager);
        $reference = invoke_private($manager, 'buildWorkflowAuthoringReference', ['workflow']);

        assert_contains('Installed candidates: `json-field`', $reference, 'Workflow authoring reference should mention installed json.extract candidates.');
        assert_not_contains('No installed template currently advertises `json.extract`', $reference, 'Workflow authoring reference should not report json.extract as missing when built-ins are present.');
        assert_not_contains('api-contract-smoke-test', $reference, 'Generic http.fetch guidance should not treat api-contract-smoke-test as a reusable HTTP fetch step.');
        assert_contains('Never embed workflow placeholders like `{{inputs.foo}}`, `{{steps.prev.output}}`, or `{{steps.prev.extract.responseBody}}` directly into a transform script body.', $reference, 'Workflow authoring reference should tell the model to keep transform scripts static.');
        assert_contains('If a transform step emits a JSON object or array, do not wire `steps.<transform>.output` straight into an HTTP request path.', $reference, 'Workflow authoring reference should explain scalar handoff before later HTTP steps.');
        assert_contains('Do not work around missing fan-out or iteration support by dropping to `shell-exec` with Python or shell loops', $reference, 'Workflow authoring reference should discourage shell-based fan-out workarounds.');
        assert_contains('Do not leave unused operator inputs, optional secrets, or “reserved for a future step” placeholders in the draft.', $reference, 'Workflow authoring reference should discourage speculative future-step placeholders.');
    },
    'workflow AI prompt falls back cleanly when only shell-exec is available' => function () use ($templatesById): void {
        $manager = make_ai_manager(fake_template_manager($templatesById, ['shell-exec']));
        $reference = invoke_private($manager, 'buildWorkflowAuthoringReference', ['workflow']);

        assert_contains('No installed template currently advertises `http.fetch`', $reference, 'Workflow authoring reference should explain missing http.fetch capability.');
        assert_contains('No installed template currently advertises `payload.transform`', $reference, 'Workflow authoring reference should explain missing payload.transform capability.');
        assert_not_contains('python-transform', $reference, 'Workflow authoring reference should not mention python-transform when it is not installed.');
    },
    'workflow AI prompt uses a custom replacement transform template when available' => function () use ($templatesById): void {
        $manager = make_ai_manager(fake_template_manager($templatesById, ['shell-exec', 'http-request', 'json-field', 'json-count'], [
            build_custom_template('custom-transform', [
                'capabilities' => ['payload.transform'],
                'payloadHandling' => ['structured-json', 'large-text'],
                'scriptRuntime' => 'node',
                'dataFlow' => [
                    'scriptInput' => 'script',
                    'payloadInputs' => ['payload1', 'payload2'],
                ],
            ], 'Custom Transform'),
        ]));

        $patterns = invoke_private($manager, 'buildWorkflowPatternReference');

        assert_contains('`custom-transform`', $patterns, 'Workflow pattern reference should use the installed replacement transform template.');
        assert_not_contains('`python-transform`', $patterns, 'Workflow pattern reference should not mention python-transform when it is not installed.');
        assert_contains('pass `payload2={{inputs.country_name}}`', $patterns, 'Workflow pattern reference should show passing workflow inputs through payload slots.');
        assert_contains('Do not pass the whole transform output into `path`.', $patterns, 'Workflow pattern reference should show scalar extraction before HTTP paths.');
        assert_contains('a giant `shell-exec` step installs Python and loops over a JSON payload', $patterns, 'Workflow pattern reference should call out shell-based API fan-out as a bad workaround.');
    },
    'workflow AI prompt reports json.extract as missing when json-field is removed' => function () use ($templatesById): void {
        $manager = make_ai_manager(fake_template_manager($templatesById, ['shell-exec', 'http-request', 'json-count', 'python-transform']));
        $reference = invoke_private($manager, 'buildWorkflowAuthoringReference', ['workflow']);

        assert_contains('No installed template currently advertises `json.extract`', $reference, 'Workflow authoring reference should report json.extract as missing.');
        assert_not_contains('json-field', $reference, 'Workflow authoring reference should not mention json-field when it is removed.');
    },
    'validation with built-ins uses installed candidates instead of capability gaps' => function () use ($realTemplateManager): void {
        $manager = make_validation_manager($realTemplateManager);
        $result = validate_workflow_draft($manager, payload_transform_draft());
        $validation = $result['validation'] ?? [];
        $joinedErrors = implode("\n", $validation['errors'] ?? []);

        assert_true(($validation['valid'] ?? true) === false, 'Payload-transform fixture should fail validation.');
        assert_same([], $validation['capabilityGaps'] ?? [], 'Built-in templates should avoid missing-capability gaps for this fixture.');
        assert_contains('Installed candidates:', $joinedErrors, 'Validation should suggest installed candidates when the capability exists.');
    },
    'validation reports json.extract gap when json-field is removed' => function () use ($templatesById): void {
        $manager = make_validation_manager(fake_template_manager($templatesById, ['shell-exec', 'http-request', 'json-count', 'python-transform']));
        $result = validate_workflow_draft($manager, json_extract_gap_draft());
        $validation = $result['validation'] ?? [];

        assert_capability_gap($validation, 'json.extract', 'Validation should surface a structured json.extract gap when json-field is unavailable.');
    },
    'validation reports json.count gap when json-count is removed' => function () use ($templatesById): void {
        $manager = make_validation_manager(fake_template_manager($templatesById, ['shell-exec', 'http-request', 'json-field', 'python-transform']));
        $result = validate_workflow_draft($manager, json_count_gap_draft());
        $validation = $result['validation'] ?? [];

        assert_capability_gap($validation, 'json.count', 'Validation should surface a structured json.count gap when json-count is unavailable.');
        assert_no_capability_gap($validation, 'json.extract', 'Validation should not report json.extract as missing when json-field is still installed.');
    },
    'validation reports payload.transform gap when python-transform is removed' => function () use ($templatesById): void {
        $manager = make_validation_manager(fake_template_manager($templatesById, ['shell-exec', 'http-request', 'json-field', 'json-count']));
        $result = validate_workflow_draft($manager, payload_transform_draft());
        $validation = $result['validation'] ?? [];

        assert_capability_gap($validation, 'payload.transform', 'Validation should surface a structured payload.transform gap when no transform template is installed.');
    },
    'validation flags raw payload interpolation inside http paths' => function () use ($realTemplateManager): void {
        $manager = make_validation_manager($realTemplateManager);
        $result = validate_workflow_draft($manager, http_path_payload_draft());
        $validation = $result['validation'] ?? [];
        $joinedErrors = implode("\n", $validation['errors'] ?? []);

        assert_true(($validation['valid'] ?? true) === false, 'HTTP path payload fixture should fail validation.');
        assert_contains('interpolates raw payload reference `steps.prepare.output` into HTTP path input `path`', $joinedErrors, 'Validation should reject raw payload interpolation inside HTTP paths.');
    },
    'validation flags double parsing of transform payload inputs' => function () use ($realTemplateManager): void {
        $manager = make_validation_manager($realTemplateManager);
        $result = validate_workflow_draft($manager, transform_double_parse_draft());
        $validation = $result['validation'] ?? [];
        $joinedErrors = implode("\n", $validation['errors'] ?? []);

        assert_true(($validation['valid'] ?? true) === false, 'Transform double-parse fixture should fail validation.');
        assert_contains('calls `json.loads(payload1)` even though `payload1` is already parsed', $joinedErrors, 'Validation should reject double parsing of transform payload inputs.');
    },
    'validation flags hidden referenced inputs with empty defaults' => function () use ($realTemplateManager): void {
        $manager = make_validation_manager($realTemplateManager);
        $result = validate_workflow_draft($manager, hidden_empty_input_draft());
        $validation = $result['validation'] ?? [];
        $joinedErrors = implode("\n", $validation['errors'] ?? []);

        assert_true(($validation['valid'] ?? true) === false, 'Hidden empty input fixture should fail validation.');
        assert_contains('Workflow references `inputs.hidden_query`, but that input is not exposed in `inputContract` and its default value is empty.', $joinedErrors, 'Validation should reject hidden referenced inputs without usable defaults.');
    },
    'validation allows scalar transform outputs inside http paths' => function () use ($realTemplateManager): void {
        $manager = make_validation_manager($realTemplateManager);
        $result = validate_workflow_draft($manager, scalar_transform_path_draft());
        $validation = $result['validation'] ?? [];
        $joinedErrors = implode("\n", $validation['errors'] ?? []);

        assert_not_contains('interpolates raw payload reference `steps.prepare.output` into HTTP path input `path`', $joinedErrors, 'Scalar transform outputs should not be treated as raw payloads in HTTP paths.');
    },
    'validation flags undeclared transform payload slots' => function () use ($realTemplateManager): void {
        $manager = make_validation_manager($realTemplateManager);
        $result = validate_workflow_draft($manager, undeclared_payload_slot_draft());
        $validation = $result['validation'] ?? [];
        $joinedErrors = implode("\n", $validation['errors'] ?? []);

        assert_true(($validation['valid'] ?? true) === false, 'Undeclared payload slot fixture should fail validation.');
        assert_contains('references undeclared transform runtime value `payload5`', $joinedErrors, 'Validation should reject undeclared transform payload slots.');
    },
    'validation warns about unused declared operator inputs' => function () use ($realTemplateManager): void {
        $manager = make_validation_manager($realTemplateManager);
        $result = validate_workflow_draft($manager, unused_declared_input_draft());
        $validation = $result['validation'] ?? [];
        $joinedWarnings = implode("\n", $validation['warnings'] ?? []);

        assert_contains('Workflow declares input `rapidapi_key` in `inputContract`, but no step currently references `inputs.rapidapi_key`.', $joinedWarnings, 'Validation should warn when AI leaves unused operator inputs behind.');
    },
    'http usage detector ignores urllib.parse helpers' => function () use ($realTemplateManager): void {
        $manager = make_validation_manager($realTemplateManager);
        $script = "from urllib.parse import quote\nprint(quote('Paris, FR'))";

        $looksLikeHttp = invoke_private($manager, 'scriptLooksLikeHttpUsage', [$script]);

        assert_same(false, $looksLikeHttp, 'URL encoding helpers alone should not be treated as network calls.');
    },
    'http usage detector ignores scripts that only build URL strings' => function () use ($realTemplateManager): void {
        $manager = make_validation_manager($realTemplateManager);
        $script = "code_list = ','.join(['FR', 'DE'])\nurl = f'https://example.test/api?codes={code_list}'\nprint(url)";

        $looksLikeHttp = invoke_private($manager, 'scriptLooksLikeHttpUsage', [$script]);

        assert_same(false, $looksLikeHttp, 'Building a URL string for later use should not be treated as network access.');
    },
    'http usage detector still catches urllib.request calls' => function () use ($realTemplateManager): void {
        $manager = make_validation_manager($realTemplateManager);
        $script = "import urllib.request\nwith urllib.request.urlopen('https://example.test') as resp:\n    print(resp.read())";

        $looksLikeHttp = invoke_private($manager, 'scriptLooksLikeHttpUsage', [$script]);

        assert_same(true, $looksLikeHttp, 'Real urllib.request usage should still be treated as network access.');
    },
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

fwrite(STDOUT, "\nAll capability refactor tests passed.\n");
