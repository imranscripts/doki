<?php
/**
 * studio-workflows.php - Workflows Studio draft CRUD, validation, and publish
 */

ob_start();

require_once __DIR__ . '/../includes/session-bootstrap.php';
doki_start_session();
header('Content-Type: application/json');

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        ob_end_clean();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Server error: ' . $error['message']]);
    }
});

require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/AIAdminManager.php';
require_once __DIR__ . '/../includes/CommandsManager.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/PermissionManager.php';
require_once __DIR__ . '/../includes/RuntimeEnvironment.php';
require_once __DIR__ . '/../includes/SourcesManager.php';
require_once __DIR__ . '/../includes/TemplateManager.php';
require_once __DIR__ . '/../includes/TemplateStudioManager.php';
require_once __DIR__ . '/../includes/WorkflowAIManager.php';
require_once __DIR__ . '/../includes/WorkflowStudioManager.php';

$db = Database::getInstance();
$auth = new Auth($db);
$permissionManager = new PermissionManager();
$workflowManager = new WorkflowStudioManager($auth);
$templateStudioManager = new TemplateStudioManager($auth);
$workflowAiManager = new WorkflowAIManager();
$commandsManager = new CommandsManager();
$runtimeTemplateManager = new TemplateManager();
$sourcesManager = new SourcesManager();
$aiAdminManager = new AIAdminManager();

$token = $_SESSION['auth_token'] ?? null;
if (!$token) {
    jsonResponse(['success' => false, 'error' => 'Not authenticated'], 401);
}

$user = $auth->validateSession($token);
if (!$user) {
    jsonResponse(['success' => false, 'error' => 'Session expired'], 401);
}

if (!in_array($user['role'] ?? '', ['super-admin', 'admin'], true)) {
    jsonResponse(['success' => false, 'error' => 'Admin access required'], 403);
}

if (!$permissionManager->canAccessResource($user, 'module', 'studio.workflows')) {
    jsonResponse(['success' => false, 'error' => 'Workflows Studio access required'], 403);
}

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'list':
        jsonResponse([
            'success' => true,
            'workflowProjects' => $workflowManager->listProjects(),
            'templateProjects' => $templateStudioManager->listProjects(),
            'workflowDefaults' => $workflowManager->getDefaultDraft(),
            'templateDefaults' => $templateStudioManager->getDefaultDraft(),
            'stats' => buildStudioStats($commandsManager, $runtimeTemplateManager, $sourcesManager, $aiAdminManager),
        ]);
        break;

    case 'list-ai-providers':
        requireStudioAi($permissionManager, $user);
        jsonResponse([
            'success' => true,
            'providers' => $workflowAiManager->getProviders(),
        ]);
        break;

    case 'get-project':
        $type = normalizeProjectType($_GET['type'] ?? '');
        $projectId = trim((string)($_GET['projectId'] ?? ''));
        if ($projectId === '') {
            jsonResponse(['success' => false, 'error' => 'projectId is required'], 400);
        }
        $project = getManagerForType($type, $workflowManager, $templateStudioManager)->getProject($projectId);
        if ($project === null) {
            jsonResponse(['success' => false, 'error' => 'Project not found'], 404);
        }
        jsonResponse(['success' => true, 'type' => $type, 'project' => $project]);
        break;

    case 'save-project':
        requirePost();
        $input = getJsonInput();
        $type = normalizeProjectType($input['type'] ?? '');
        $payload = is_array($input['payload'] ?? null) ? $input['payload'] : [];
        $projectId = trim((string)($input['projectId'] ?? ''));
        $result = getManagerForType($type, $workflowManager, $templateStudioManager)
            ->saveProject($payload, (string)$user['id'], (string)$user['username'], $projectId !== '' ? $projectId : null);

        jsonResponse($result, !empty($result['success']) ? 200 : 400);
        break;

    case 'validate-project':
        requirePost();
        $input = getJsonInput();
        $type = normalizeProjectType($input['type'] ?? '');
        $projectId = trim((string)($input['projectId'] ?? ''));

        $manager = getManagerForType($type, $workflowManager, $templateStudioManager);
        if ($projectId !== '') {
            $result = $manager->validateProject($projectId);
        } else {
            $payload = is_array($input['payload'] ?? null) ? $input['payload'] : [];
            $result = $manager->validateDraftPayload($payload);
        }

        jsonResponse($result, !empty($result['success']) ? 200 : 400);
        break;

    case 'execute-project':
    case 'dry-run-project':
        requirePost();
        $input = getJsonInput();
        $type = normalizeProjectType($input['type'] ?? '');
        if ($type !== 'workflow') {
            jsonResponse(['success' => false, 'error' => 'Execution is currently available for workflow drafts only'], 400);
        }

        $payload = is_array($input['payload'] ?? null) ? $input['payload'] : [];
        $projectId = trim((string)($input['projectId'] ?? ''));
        $dryRun = $action === 'dry-run-project' || !empty($input['dryRun']);
        $inputs = $input['inputs'] ?? [];
        $normalizedInputs = normalizeJsonMapPayload($inputs, 'inputs');
        if (!$normalizedInputs['ok']) {
            jsonResponse(['success' => false, 'error' => $normalizedInputs['error']], 400);
        }

        $validated = $workflowManager->validateDraftPayload($payload);
        $validation = $validated['validation'] ?? null;

        if (empty($validation['valid'])) {
            jsonResponse([
                'success' => false,
                'error' => 'Workflow draft has validation errors',
                'draft' => $validated['draft'] ?? null,
                'validation' => $validation,
            ], 400);
        }

        $draft = is_array($validated['draft'] ?? null) ? $validated['draft'] : [];
        $executionDraft = normalizeWorkflowDraftForExecutePayload($draft);
        $resp = orchestratorRequest('POST', '/execute', [
            'commandDraft' => $executionDraft,
            'dryRun' => $dryRun,
            'inputs' => $normalizedInputs['value'],
            'user' => [
                'id' => $user['id'],
                'username' => $user['username'],
                'role' => $user['role'] ?? null,
            ],
            'request' => [
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                'userAgent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'source' => 'studio-workflows',
                'projectId' => $projectId !== '' ? $projectId : null,
            ],
        ]);

        $status = $resp['status'] > 0 ? $resp['status'] : 502;
        $data = is_array($resp['data'] ?? null) ? $resp['data'] : ['success' => false, 'error' => 'Invalid response from Go orchestrator'];
        $data['validation'] = $validation;
        $data['draft'] = $draft;

        if (!empty($data['success'])) {
            $auth->auditLog(
                (string)$user['id'],
                (string)$user['username'],
                $dryRun ? 'workflow_studio_project.dry_run' : 'workflow_studio_project.execute',
                'workflow-project',
                $projectId !== '' ? $projectId : ((string)($draft['id'] ?? '') !== '' ? (string)$draft['id'] : null),
                json_encode([
                    'projectId' => $projectId !== '' ? $projectId : null,
                    'commandId' => $draft['id'] ?? null,
                    'jobId' => $data['jobId'] ?? null,
                    'status' => $data['status'] ?? null,
                    'dryRun' => $dryRun,
                ])
            );
        }

        jsonResponse($data, $status);
        break;

    case 'publish-project':
        requirePost();
        $input = getJsonInput();
        $type = normalizeProjectType($input['type'] ?? '');
        $projectId = trim((string)($input['projectId'] ?? ''));
        if ($projectId === '') {
            jsonResponse(['success' => false, 'error' => 'projectId is required'], 400);
        }
        $result = getManagerForType($type, $workflowManager, $templateStudioManager)
            ->publishProject($projectId, (string)$user['id'], (string)$user['username']);
        jsonResponse($result, !empty($result['success']) ? 200 : 400);
        break;

    case 'archive-project':
        requirePost();
        $input = getJsonInput();
        $type = normalizeProjectType($input['type'] ?? '');
        $projectId = trim((string)($input['projectId'] ?? ''));
        if ($projectId === '') {
            jsonResponse(['success' => false, 'error' => 'projectId is required'], 400);
        }
        $result = getManagerForType($type, $workflowManager, $templateStudioManager)
            ->archiveProject($projectId, (string)$user['id'], (string)$user['username']);
        jsonResponse($result, !empty($result['success']) ? 200 : 400);
        break;

    case 'generate-ai-draft':
        requireStudioAi($permissionManager, $user);
        requirePost();
        $input = getJsonInput();
        $type = normalizeProjectType($input['type'] ?? '');
        $prompt = trim((string)($input['prompt'] ?? ''));
        $providerId = trim((string)($input['providerId'] ?? ''));
        $currentDraft = is_array($input['currentDraft'] ?? null) ? $input['currentDraft'] : [];

        $result = $workflowAiManager->generateDraft($type, $prompt, $providerId, [
            'currentDraft' => $currentDraft,
        ]);

        if (!empty($result['success'])) {
            $auth->auditLog(
                (string)$user['id'],
                (string)$user['username'],
                $type === 'template' ? 'studio.ai.generate_template_draft' : 'studio.ai.generate_workflow_draft',
                $type === 'template' ? 'template-draft' : 'workflow-draft',
                trim((string)($result['draft']['id'] ?? '')) ?: null,
                json_encode([
                    'providerId' => $result['provider']['id'] ?? $providerId,
                    'model' => $result['model'] ?? null,
                    'promptLength' => strlen($prompt),
                ])
            );
        }

        jsonResponse($result, !empty($result['success']) ? 200 : 400);
        break;

    default:
        jsonResponse(['success' => false, 'error' => 'Unknown action'], 404);
}

function jsonResponse(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function requirePost(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        jsonResponse(['success' => false, 'error' => 'POST required'], 405);
    }
}

function requireStudioAi(PermissionManager $permissionManager, array $user): void {
    if (!$permissionManager->canAccessResource($user, 'module', 'studio.ai')) {
        jsonResponse(['success' => false, 'error' => 'Studio AI access required'], 403);
    }
}

function getJsonInput(): array {
    $raw = file_get_contents('php://input');
    $decoded = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        jsonResponse(['success' => false, 'error' => 'Invalid JSON body'], 400);
    }
    return $decoded;
}

function normalizeProjectType(string $type): string {
    $type = strtolower(trim($type));
    if (!in_array($type, ['workflow', 'template'], true)) {
        jsonResponse(['success' => false, 'error' => 'type must be workflow or template'], 400);
    }
    return $type;
}

function normalizeJsonMapPayload($value, string $fieldName): array {
    if ($value === null) {
        return ['ok' => true, 'value' => (object)[]];
    }

    if (!is_array($value)) {
        return ['ok' => false, 'error' => $fieldName . ' must be an object'];
    }

    if ($value === []) {
        return ['ok' => true, 'value' => (object)[]];
    }

    if (array_is_list($value)) {
        return ['ok' => false, 'error' => $fieldName . ' must be an object'];
    }

    return ['ok' => true, 'value' => $value];
}

function normalizeWorkflowDraftForExecutePayload(array $draft): array {
    if (array_key_exists('inputs', $draft) && is_array($draft['inputs']) && $draft['inputs'] === []) {
        $draft['inputs'] = (object)[];
    }
    if (array_key_exists('secrets', $draft) && is_array($draft['secrets']) && $draft['secrets'] === []) {
        $draft['secrets'] = (object)[];
    }

    if (isset($draft['steps']) && is_array($draft['steps'])) {
        foreach ($draft['steps'] as $index => $step) {
            if (!is_array($step)) {
                continue;
            }
            if (array_key_exists('inputs', $step) && is_array($step['inputs']) && $step['inputs'] === []) {
                $step['inputs'] = (object)[];
            }
            if (array_key_exists('secrets', $step) && is_array($step['secrets']) && $step['secrets'] === []) {
                $step['secrets'] = (object)[];
            }
            $draft['steps'][$index] = $step;
        }
    }

    return $draft;
}

function getManagerForType(string $type, WorkflowStudioManager $workflowManager, TemplateStudioManager $templateStudioManager) {
    return $type === 'template' ? $templateStudioManager : $workflowManager;
}

function buildStudioStats(
    CommandsManager $commandsManager,
    TemplateManager $templateManager,
    SourcesManager $sourcesManager,
    AIAdminManager $aiAdminManager
): array {
    $commands = $commandsManager->getCommands();
    $templates = $templateManager->getTemplates();
    $repositories = $sourcesManager->getRepositories();
    $workflowContextBlocks = $aiAdminManager->getContextBlocks(AIAdminManager::SCOPE_COMMANDS);

    return [
        'publishedCommands' => !empty($commands['success']) ? count($commands['commands'] ?? []) : 0,
        'publishedTemplates' => !empty($templates['success']) ? count($templates['templates'] ?? []) : 0,
        'templateRepositories' => count(array_filter(
            $repositories,
            static fn(array $repo): bool => in_array((string)($repo['type'] ?? ''), [SourcesManager::TYPE_TEMPLATES, SourcesManager::TYPE_MIXED], true)
        )),
        'workflowContextBlocks' => count($workflowContextBlocks),
    ];
}

function orchestratorBaseUrl(): string {
    return RuntimeEnvironment::getOrchestratorBaseUrl();
}

function orchestratorTimeout(): int {
    $value = getenv('DOKI_ORCH_TIMEOUT');
    if ($value === false || trim($value) === '') {
        return 330;
    }

    $timeout = (int)$value;
    return $timeout > 0 ? $timeout : 330;
}

function orchestratorRequest(string $method, string $path, ?array $payload = null, array $query = []): array {
    $url = orchestratorBaseUrl() . $path;
    if ($query !== []) {
        $url .= '?' . http_build_query($query);
    }

    $options = [
        'http' => [
            'method' => $method,
            'header' => "Content-Type: application/json\r\n",
            'timeout' => orchestratorTimeout(),
            'ignore_errors' => true,
        ],
    ];

    if ($payload !== null) {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($encoded === false) {
            return [
                'status' => 400,
                'data' => [
                    'success' => false,
                    'error' => 'Failed to encode orchestrator request payload: ' . json_last_error_msg(),
                ],
            ];
        }
        $options['http']['content'] = $encoded;
    }

    $context = stream_context_create($options);
    $body = @file_get_contents($url, false, $context);

    $status = 0;
    if (isset($http_response_header[0]) && preg_match('/HTTP\\/(?:\\d\\.\\d)\\s+(\\d+)/', $http_response_header[0], $matches)) {
        $status = (int)$matches[1];
    }

    if ($body === false) {
        return [
            'status' => 502,
            'data' => ['success' => false, 'error' => 'Go orchestrator unavailable'],
        ];
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        $data = ['success' => false, 'error' => 'Invalid response from Go orchestrator'];
    }

    return [
        'status' => $status ?: 502,
        'data' => $data,
    ];
}
