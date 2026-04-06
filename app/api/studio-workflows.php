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
require_once __DIR__ . '/../includes/StudioAiHistoryManager.php';
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
$studioAiHistoryManager = new StudioAiHistoryManager($permissionManager);
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
        $runtimeTemplates = $runtimeTemplateManager->getTemplates();
        jsonResponse([
            'success' => true,
            'workflowProjects' => $workflowManager->listProjects(),
            'templateProjects' => $templateStudioManager->listProjects(),
            'workflowDefaults' => $workflowManager->getDefaultDraft(),
            'templateDefaults' => $templateStudioManager->getDefaultDraft(),
            'runtimeTemplates' => !empty($runtimeTemplates['success']) ? ($runtimeTemplates['templates'] ?? []) : [],
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
        $requestedThreadId = normalizeStudioHistoryThreadId($input['threadId'] ?? '');
        $manager = getManagerForType($type, $workflowManager, $templateStudioManager);
        $projectBefore = $projectId !== '' ? $manager->getProject($projectId) : null;
        $result = $manager
            ->saveProject($payload, (string)$user['id'], (string)$user['username'], $projectId !== '' ? $projectId : null);

        if (!empty($result['success']) && is_array($result['project'] ?? null)) {
            $projectAfter = $result['project'];
            $result['historyEvents'] = $studioAiHistoryManager->appendProjectLifecycleEvent(
                $type,
                (string)($projectAfter['id'] ?? ''),
                StudioAiHistoryManager::EVENT_SAVE,
                $user,
                'Saved the current ' . ($type === 'template' ? 'template' : 'workflow') . '.',
                is_array($projectBefore['draft'] ?? null) ? $projectBefore['draft'] : null,
                is_array($projectAfter['draft'] ?? null) ? $projectAfter['draft'] : null,
                is_array($projectBefore['lastValidation'] ?? null) ? $projectBefore['lastValidation'] : null,
                is_array($projectAfter['lastValidation'] ?? null) ? $projectAfter['lastValidation'] : null,
                $projectBefore ? getLatestStudioProjectVersionId($projectBefore) : null,
                trim((string)($result['versionId'] ?? '')) ?: null
            );

            if (canReadStudioAiHistory($permissionManager, $user)) {
                $result['history'] = $studioAiHistoryManager->getProjectHistory(
                    $type,
                    (string)($projectAfter['id'] ?? ''),
                    $user,
                    $requestedThreadId
                );
            }
        }

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
        $requestedThreadId = normalizeStudioHistoryThreadId($input['threadId'] ?? '');
        if ($projectId === '') {
            jsonResponse(['success' => false, 'error' => 'projectId is required'], 400);
        }
        $manager = getManagerForType($type, $workflowManager, $templateStudioManager);
        $projectBefore = $manager->getProject($projectId);
        $result = $manager
            ->publishProject($projectId, (string)$user['id'], (string)$user['username']);

        if (!empty($result['success']) && is_array($result['project'] ?? null)) {
            $projectAfter = $result['project'];
            $result['historyEvents'] = $studioAiHistoryManager->appendProjectLifecycleEvent(
                $type,
                (string)($projectAfter['id'] ?? ''),
                StudioAiHistoryManager::EVENT_PUBLISH,
                $user,
                'Published the current ' . ($type === 'template' ? 'template' : 'workflow') . ' to runtime.',
                is_array($projectBefore['draft'] ?? null) ? $projectBefore['draft'] : null,
                is_array($projectAfter['draft'] ?? null) ? $projectAfter['draft'] : null,
                is_array($projectBefore['lastValidation'] ?? null) ? $projectBefore['lastValidation'] : null,
                is_array($result['validation'] ?? null)
                    ? $result['validation']
                    : (is_array($projectAfter['lastValidation'] ?? null) ? $projectAfter['lastValidation'] : null),
                $projectBefore ? getLatestStudioProjectVersionId($projectBefore) : null,
                trim((string)($result['versionId'] ?? '')) ?: null
            );

            if (canReadStudioAiHistory($permissionManager, $user)) {
                $result['history'] = $studioAiHistoryManager->getProjectHistory(
                    $type,
                    (string)($projectAfter['id'] ?? ''),
                    $user,
                    $requestedThreadId
                );
            }
        }

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
        if ($prompt === '') {
            jsonResponse(['success' => false, 'error' => 'A prompt is required'], 400);
        }
        $displayPrompt = trim((string)($input['displayPrompt'] ?? ''));
        $providerId = trim((string)($input['providerId'] ?? ''));
        $currentDraft = is_array($input['currentDraft'] ?? null) ? $input['currentDraft'] : [];
        $currentValidation = is_array($input['currentValidation'] ?? null) ? $input['currentValidation'] : [];
        $editMode = !empty($input['editMode']);
        $projectState = ensureStudioProjectForAi(
            $type,
            $input,
            $user,
            $workflowManager,
            $templateStudioManager
        );
        $project = $projectState['project'];
        $projectId = (string)($project['id'] ?? '');
        $thread = $studioAiHistoryManager->ensureThread($type, $projectId, $user);
        $persistedPromptContext = $studioAiHistoryManager->buildPromptContextForThread((string)$thread['id']);
        $fallbackConversation = is_array($input['conversation'] ?? null) ? $input['conversation'] : [];
        $conversation = !empty($persistedPromptContext['conversation']) ? $persistedPromptContext['conversation'] : $fallbackConversation;
        $proposalHistory = is_array($persistedPromptContext['proposalHistory'] ?? null) ? $persistedPromptContext['proposalHistory'] : [];

        $userPromptEvent = $studioAiHistoryManager->appendUserPrompt(
            (string)$thread['id'],
            $user,
            $prompt,
            $displayPrompt,
            $currentDraft !== [] ? $currentDraft : null,
            $currentValidation !== [] ? $currentValidation : null
        );

        $result = $workflowAiManager->generateDraft($type, $prompt, $providerId, [
            'currentDraft' => $currentDraft,
            'conversation' => $conversation,
            'proposalHistory' => $proposalHistory,
            'currentValidation' => $currentValidation,
            'editMode' => $editMode,
        ]);

        if (!empty($result['success'])) {
            $assistantEvent = $studioAiHistoryManager->appendAssistantProposal(
                (string)$thread['id'],
                $user,
                $result,
                $currentDraft !== [] ? $currentDraft : null,
                is_array($result['draft'] ?? null) ? $result['draft'] : null,
                $currentValidation !== [] ? $currentValidation : null,
                is_array($result['validation'] ?? null) ? $result['validation'] : null
            );
            $history = $studioAiHistoryManager->getProjectHistory($type, $projectId, $user, (string)$thread['id']);

            $auth->auditLog(
                (string)$user['id'],
                (string)$user['username'],
                $type === 'template' ? 'studio.ai.generate_template_draft' : 'studio.ai.generate_workflow_draft',
                $type === 'template' ? 'template-draft' : 'workflow-draft',
                $projectId !== '' ? $projectId : (trim((string)($result['draft']['id'] ?? '')) ?: null),
                json_encode([
                    'projectId' => $projectId !== '' ? $projectId : null,
                    'threadId' => $thread['id'] ?? null,
                    'userPromptEventId' => $userPromptEvent['id'] ?? null,
                    'assistantEventId' => $assistantEvent['id'] ?? null,
                    'providerId' => $result['provider']['id'] ?? $providerId,
                    'model' => $result['model'] ?? null,
                    'promptLength' => strlen($prompt),
                ])
            );

            $result['project'] = $project;
            $result['projectAutoCreated'] = !empty($projectState['created']);
            $result['thread'] = $history['selectedThread'] ?? $thread;
            $result['historyEvent'] = $assistantEvent;
            $result['history'] = $history;
            $result['projectId'] = $projectId;
            $result['threadId'] = $thread['id'] ?? null;
            $result['userPromptEventId'] = $userPromptEvent['id'] ?? null;
        }

        if (empty($result['success'])) {
            $assistantErrorEvent = $studioAiHistoryManager->appendAssistantError(
                (string)$thread['id'],
                $user,
                (string)($result['error'] ?? 'The AI request failed'),
                is_array($result['provider'] ?? null) ? $result['provider'] : [],
                (string)($result['model'] ?? ''),
                $currentDraft !== [] ? $currentDraft : null,
                $currentValidation !== [] ? $currentValidation : null
            );
            $history = $studioAiHistoryManager->getProjectHistory($type, $projectId, $user, (string)$thread['id']);
            $result['project'] = $project;
            $result['projectAutoCreated'] = !empty($projectState['created']);
            $result['thread'] = $history['selectedThread'] ?? $thread;
            $result['historyEvent'] = $assistantErrorEvent;
            $result['history'] = $history;
            $result['projectId'] = $projectId;
            $result['threadId'] = $thread['id'] ?? null;
            $result['userPromptEventId'] = $userPromptEvent['id'] ?? null;

            $auth->auditLog(
                (string)$user['id'],
                (string)$user['username'],
                $type === 'template' ? 'studio.ai.generate_template_draft_failed' : 'studio.ai.generate_workflow_draft_failed',
                $type === 'template' ? 'template-draft' : 'workflow-draft',
                $projectId !== '' ? $projectId : null,
                json_encode([
                    'projectId' => $projectId !== '' ? $projectId : null,
                    'threadId' => $thread['id'] ?? null,
                    'userPromptEventId' => $userPromptEvent['id'] ?? null,
                    'assistantEventId' => $assistantErrorEvent['id'] ?? null,
                    'providerId' => $result['provider']['id'] ?? $providerId,
                    'model' => $result['model'] ?? null,
                    'promptLength' => strlen($prompt),
                    'error' => $result['error'] ?? 'The AI request failed',
                ])
            );
        }

        jsonResponse($result, !empty($result['success']) ? 200 : 400);
        break;

    case 'list-ai-history':
        requireStudioAi($permissionManager, $user);
        $type = normalizeProjectType($_GET['type'] ?? '');
        $projectId = trim((string)($_GET['projectId'] ?? ''));
        $threadId = trim((string)($_GET['threadId'] ?? ''));
        if ($projectId === '') {
            jsonResponse(['success' => false, 'error' => 'projectId is required'], 400);
        }

        $project = getManagerForType($type, $workflowManager, $templateStudioManager)->getProject($projectId);
        if ($project === null) {
            jsonResponse(['success' => false, 'error' => 'Project not found'], 404);
        }

        jsonResponse([
            'success' => true,
            'type' => $type,
            'project' => $project,
            'history' => $studioAiHistoryManager->getProjectHistory($type, $projectId, $user, $threadId),
        ]);
        break;

    case 'apply-ai-proposal':
        requireStudioAi($permissionManager, $user);
        requirePost();
        $input = getJsonInput();
        $type = normalizeProjectType($input['type'] ?? '');
        $projectId = trim((string)($input['projectId'] ?? ''));
        $threadId = trim((string)($input['threadId'] ?? ''));
        $eventId = trim((string)($input['eventId'] ?? ''));
        $force = !empty($input['force']);

        if ($projectId === '' || $threadId === '' || $eventId === '') {
            jsonResponse(['success' => false, 'error' => 'projectId, threadId, and eventId are required'], 400);
        }

        $project = getManagerForType($type, $workflowManager, $templateStudioManager)->getProject($projectId);
        if ($project === null) {
            jsonResponse(['success' => false, 'error' => 'Project not found'], 404);
        }

        $thread = $studioAiHistoryManager->getVisibleThreadForUser($threadId, $user);
        if ($thread === null) {
            jsonResponse(['success' => false, 'error' => 'Thread not found'], 404);
        }
        if (
            (string)($thread['projectType'] ?? '') !== $type
            || (string)($thread['projectId'] ?? '') !== $projectId
        ) {
            jsonResponse(['success' => false, 'error' => 'Proposal not found for this project'], 404);
        }
        if (trim((string)($thread['ownerUserId'] ?? '')) !== trim((string)($user['id'] ?? ''))) {
            jsonResponse(['success' => false, 'error' => 'You can only apply proposals from your own AI thread'], 403);
        }

        $proposalEvent = $studioAiHistoryManager->getVisibleEventForUser($threadId, $eventId, $user);
        if ($proposalEvent === null || ($proposalEvent['eventType'] ?? '') !== StudioAiHistoryManager::EVENT_ASSISTANT_PROPOSAL) {
            jsonResponse(['success' => false, 'error' => 'Proposal not found'], 404);
        }
        if (($proposalEvent['proposalStatus'] ?? '') !== StudioAiHistoryManager::PROPOSAL_PROPOSED) {
            jsonResponse(['success' => false, 'error' => 'This proposal is no longer pending'], 409);
        }

        $baselineDraft = is_array($proposalEvent['draftBefore'] ?? null) ? $proposalEvent['draftBefore'] : [];
        $currentDraft = is_array($project['draft'] ?? null) ? $project['draft'] : [];
        if (!studioDraftsEqual($currentDraft, $baselineDraft) && !$force) {
            jsonResponse([
                'success' => false,
                'error' => 'The project draft changed since this AI proposal was created.',
                'code' => 'draft_conflict',
                'project' => $project,
            ], 409);
        }

        $targetSnapshot = is_array($proposalEvent['draftAfter'] ?? null) ? $proposalEvent['draftAfter'] : null;
        if (!is_array($targetSnapshot)) {
            jsonResponse(['success' => false, 'error' => 'The proposal does not include a target draft'], 400);
        }

        $baseVersionId = getLatestStudioProjectVersionId($project);
        $applyResult = getManagerForType($type, $workflowManager, $templateStudioManager)
            ->replaceDraftSnapshot($projectId, $targetSnapshot, (string)$user['id'], 'ai-apply');
        if (empty($applyResult['success'])) {
            jsonResponse($applyResult, 400);
        }

        $applyEvent = $studioAiHistoryManager->appendLifecycleEvent(
            $threadId,
            StudioAiHistoryManager::EVENT_APPLY,
            $user,
            'Applied AI changes.',
            $currentDraft,
            is_array($applyResult['draft'] ?? null) ? $applyResult['draft'] : $targetSnapshot,
            is_array($project['lastValidation'] ?? null) ? $project['lastValidation'] : null,
            is_array($applyResult['validation'] ?? null) ? $applyResult['validation'] : null,
            $baseVersionId,
            (string)($applyResult['versionId'] ?? '')
        );
        $studioAiHistoryManager->markProposalApplied($eventId, (string)($applyEvent['id'] ?? ''));

        $history = $studioAiHistoryManager->getProjectHistory($type, $projectId, $user, $threadId);
        $updatedProposalEvent = $studioAiHistoryManager->getEventById($eventId);

        $auth->auditLog(
            (string)$user['id'],
            (string)$user['username'],
            $type === 'template' ? 'studio.ai.apply_template_draft' : 'studio.ai.apply_workflow_draft',
            $type === 'template' ? 'template-project' : 'workflow-project',
            $projectId,
            json_encode([
                'threadId' => $threadId,
                'proposalEventId' => $eventId,
                'applyEventId' => $applyEvent['id'] ?? null,
                'versionId' => $applyResult['versionId'] ?? null,
                'forced' => $force,
            ])
        );

        jsonResponse([
            'success' => true,
            'type' => $type,
            'project' => $applyResult['project'] ?? $project,
            'validation' => $applyResult['validation'] ?? null,
            'history' => $history,
            'historyEvent' => $applyEvent,
            'proposalEvent' => $updatedProposalEvent,
        ]);
        break;

    case 'dismiss-ai-proposal':
        requireStudioAi($permissionManager, $user);
        requirePost();
        $input = getJsonInput();
        $type = normalizeProjectType($input['type'] ?? '');
        $projectId = trim((string)($input['projectId'] ?? ''));
        $threadId = trim((string)($input['threadId'] ?? ''));
        $eventId = trim((string)($input['eventId'] ?? ''));

        if ($projectId === '' || $threadId === '' || $eventId === '') {
            jsonResponse(['success' => false, 'error' => 'projectId, threadId, and eventId are required'], 400);
        }

        $project = getManagerForType($type, $workflowManager, $templateStudioManager)->getProject($projectId);
        if ($project === null) {
            jsonResponse(['success' => false, 'error' => 'Project not found'], 404);
        }

        $thread = $studioAiHistoryManager->getVisibleThreadForUser($threadId, $user);
        if ($thread === null) {
            jsonResponse(['success' => false, 'error' => 'Thread not found'], 404);
        }
        if (
            (string)($thread['projectType'] ?? '') !== $type
            || (string)($thread['projectId'] ?? '') !== $projectId
        ) {
            jsonResponse(['success' => false, 'error' => 'Proposal not found for this project'], 404);
        }
        if (trim((string)($thread['ownerUserId'] ?? '')) !== trim((string)($user['id'] ?? ''))) {
            jsonResponse(['success' => false, 'error' => 'You can only dismiss proposals from your own AI thread'], 403);
        }

        $proposalEvent = $studioAiHistoryManager->getVisibleEventForUser($threadId, $eventId, $user);
        if ($proposalEvent === null || ($proposalEvent['eventType'] ?? '') !== StudioAiHistoryManager::EVENT_ASSISTANT_PROPOSAL) {
            jsonResponse(['success' => false, 'error' => 'Proposal not found'], 404);
        }
        if (($proposalEvent['proposalStatus'] ?? '') !== StudioAiHistoryManager::PROPOSAL_PROPOSED) {
            jsonResponse(['success' => false, 'error' => 'This proposal is no longer pending'], 409);
        }

        $dismissEvent = $studioAiHistoryManager->appendLifecycleEvent(
            $threadId,
            StudioAiHistoryManager::EVENT_DISMISS,
            $user,
            'Dismissed AI changes.',
            is_array($proposalEvent['draftBefore'] ?? null) ? $proposalEvent['draftBefore'] : null,
            is_array($proposalEvent['draftAfter'] ?? null) ? $proposalEvent['draftAfter'] : null,
            is_array($proposalEvent['validationBefore'] ?? null) ? $proposalEvent['validationBefore'] : null,
            is_array($proposalEvent['validationAfter'] ?? null) ? $proposalEvent['validationAfter'] : null,
            getLatestStudioProjectVersionId($project),
            null
        );
        $studioAiHistoryManager->markProposalDismissed($eventId);
        $history = $studioAiHistoryManager->getProjectHistory($type, $projectId, $user, $threadId);
        $updatedProposalEvent = $studioAiHistoryManager->getEventById($eventId);

        $auth->auditLog(
            (string)$user['id'],
            (string)$user['username'],
            $type === 'template' ? 'studio.ai.dismiss_template_draft' : 'studio.ai.dismiss_workflow_draft',
            $type === 'template' ? 'template-project' : 'workflow-project',
            $projectId,
            json_encode([
                'threadId' => $threadId,
                'proposalEventId' => $eventId,
                'dismissEventId' => $dismissEvent['id'] ?? null,
            ])
        );

        jsonResponse([
            'success' => true,
            'type' => $type,
            'project' => $project,
            'history' => $history,
            'historyEvent' => $dismissEvent,
            'proposalEvent' => $updatedProposalEvent,
        ]);
        break;

    case 'revert-ai-checkpoint':
        requireStudioAi($permissionManager, $user);
        requirePost();
        $input = getJsonInput();
        $type = normalizeProjectType($input['type'] ?? '');
        $projectId = trim((string)($input['projectId'] ?? ''));
        $threadId = trim((string)($input['threadId'] ?? ''));
        $eventId = trim((string)($input['eventId'] ?? ''));
        $target = strtolower(trim((string)($input['target'] ?? 'after')));
        $force = !empty($input['force']);
        if (!in_array($target, ['before', 'after'], true)) {
            $target = 'after';
        }

        if ($projectId === '' || $threadId === '' || $eventId === '') {
            jsonResponse(['success' => false, 'error' => 'projectId, threadId, and eventId are required'], 400);
        }

        $project = getManagerForType($type, $workflowManager, $templateStudioManager)->getProject($projectId);
        if ($project === null) {
            jsonResponse(['success' => false, 'error' => 'Project not found'], 404);
        }

        $thread = $studioAiHistoryManager->getVisibleThreadForUser($threadId, $user);
        if ($thread === null) {
            jsonResponse(['success' => false, 'error' => 'Thread not found'], 404);
        }
        if (
            (string)($thread['projectType'] ?? '') !== $type
            || (string)($thread['projectId'] ?? '') !== $projectId
        ) {
            jsonResponse(['success' => false, 'error' => 'Checkpoint not found for this project'], 404);
        }

        $threadOwnerId = trim((string)($thread['ownerUserId'] ?? ''));
        $viewerId = trim((string)($user['id'] ?? ''));
        if ($threadOwnerId !== $viewerId && !$studioAiHistoryManager->canRevertAny($user)) {
            jsonResponse(['success' => false, 'error' => 'You are not allowed to revert this checkpoint'], 403);
        }

        $proposalEvent = $studioAiHistoryManager->getVisibleEventForUser($threadId, $eventId, $user);
        if ($proposalEvent === null || ($proposalEvent['eventType'] ?? '') !== StudioAiHistoryManager::EVENT_ASSISTANT_PROPOSAL) {
            jsonResponse(['success' => false, 'error' => 'Checkpoint not found'], 404);
        }

        $hasAppliedHistory = trim((string)($proposalEvent['appliedEventId'] ?? '')) !== ''
            || in_array((string)($proposalEvent['proposalStatus'] ?? ''), [
                StudioAiHistoryManager::PROPOSAL_APPLIED,
                StudioAiHistoryManager::PROPOSAL_REVERTED,
            ], true);
        if (!$hasAppliedHistory) {
            jsonResponse(['success' => false, 'error' => 'Only applied checkpoints can be restored'], 409);
        }

        $targetSnapshot = $target === 'before'
            ? (is_array($proposalEvent['draftBefore'] ?? null) ? $proposalEvent['draftBefore'] : null)
            : (is_array($proposalEvent['draftAfter'] ?? null) ? $proposalEvent['draftAfter'] : null);
        if (!is_array($targetSnapshot)) {
            jsonResponse(['success' => false, 'error' => 'The checkpoint does not include the requested snapshot'], 400);
        }

        $currentDraft = is_array($project['draft'] ?? null) ? $project['draft'] : [];
        $expectedCurrentDraft = $target === 'before'
            ? (is_array($proposalEvent['draftAfter'] ?? null) ? $proposalEvent['draftAfter'] : null)
            : null;

        $hasConflict = false;
        if (is_array($expectedCurrentDraft)) {
            $hasConflict = !studioDraftsEqual($currentDraft, $expectedCurrentDraft);
        } else {
            $hasConflict = !studioDraftsEqual($currentDraft, $targetSnapshot);
        }
        if ($hasConflict && !$force) {
            jsonResponse([
                'success' => false,
                'error' => 'The project draft changed since this checkpoint was applied.',
                'code' => 'draft_conflict',
                'project' => $project,
            ], 409);
        }

        $baseVersionId = getLatestStudioProjectVersionId($project);
        $revertResult = getManagerForType($type, $workflowManager, $templateStudioManager)
            ->replaceDraftSnapshot($projectId, $targetSnapshot, (string)$user['id'], 'ai-revert');
        if (empty($revertResult['success'])) {
            jsonResponse($revertResult, 400);
        }

        $message = $target === 'before'
            ? 'Reverted AI changes.'
            : 'Restored the project to this AI checkpoint.';
        $revertEvent = $studioAiHistoryManager->appendLifecycleEvent(
            $threadId,
            StudioAiHistoryManager::EVENT_REVERT,
            $user,
            $message,
            $currentDraft,
            is_array($revertResult['draft'] ?? null) ? $revertResult['draft'] : $targetSnapshot,
            is_array($project['lastValidation'] ?? null) ? $project['lastValidation'] : null,
            is_array($revertResult['validation'] ?? null) ? $revertResult['validation'] : null,
            $baseVersionId,
            (string)($revertResult['versionId'] ?? '')
        );
        if ($target === 'before') {
            $studioAiHistoryManager->markProposalReverted($eventId, (string)($revertEvent['id'] ?? ''));
        } else {
            $studioAiHistoryManager->markProposalApplied($eventId, (string)($proposalEvent['appliedEventId'] ?? ''));
        }
        $history = $studioAiHistoryManager->getProjectHistory($type, $projectId, $user, $threadId);
        $updatedProposalEvent = $studioAiHistoryManager->getEventById($eventId);

        $auth->auditLog(
            (string)$user['id'],
            (string)$user['username'],
            $type === 'template' ? 'studio.ai.revert_template_checkpoint' : 'studio.ai.revert_workflow_checkpoint',
            $type === 'template' ? 'template-project' : 'workflow-project',
            $projectId,
            json_encode([
                'threadId' => $threadId,
                'proposalEventId' => $eventId,
                'revertEventId' => $revertEvent['id'] ?? null,
                'versionId' => $revertResult['versionId'] ?? null,
                'target' => $target,
                'forced' => $force,
            ])
        );

        jsonResponse([
            'success' => true,
            'type' => $type,
            'project' => $revertResult['project'] ?? $project,
            'validation' => $revertResult['validation'] ?? null,
            'history' => $history,
            'historyEvent' => $revertEvent,
            'proposalEvent' => $updatedProposalEvent,
            'target' => $target,
        ]);
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

function canReadStudioAiHistory(PermissionManager $permissionManager, array $user): bool {
    return $permissionManager->canAccessResource($user, 'module', 'studio.ai');
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

function ensureStudioProjectForAi(
    string $type,
    array $input,
    array $user,
    WorkflowStudioManager $workflowManager,
    TemplateStudioManager $templateStudioManager
): array {
    $manager = getManagerForType($type, $workflowManager, $templateStudioManager);
    $projectId = trim((string)($input['projectId'] ?? ''));

    if ($projectId !== '') {
        $project = $manager->getProject($projectId);
        if ($project === null) {
            jsonResponse(['success' => false, 'error' => 'Project not found'], 404);
        }

        return [
            'created' => false,
            'project' => $project,
        ];
    }

    $payload = buildStudioAiProjectPayload($type, $input);
    $result = $manager->saveProject(
        $payload,
        (string)$user['id'],
        (string)$user['username']
    );

    if (empty($result['success']) || !is_array($result['project'] ?? null)) {
        jsonResponse([
            'success' => false,
            'error' => $result['error'] ?? 'Unable to create a Studio project for AI history',
        ], 400);
    }

    return [
        'created' => true,
        'project' => $result['project'],
    ];
}

function buildStudioAiProjectPayload(string $type, array $input): array {
    $projectPayload = is_array($input['projectPayload'] ?? null) ? $input['projectPayload'] : [];
    $currentDraft = is_array($input['currentDraft'] ?? null) ? $input['currentDraft'] : [];

    if (!isset($projectPayload['draft']) || !is_array($projectPayload['draft'])) {
        $projectPayload['draft'] = $currentDraft;
    }

    if (!isset($projectPayload['name'])) {
        $projectPayload['name'] = trim((string)($currentDraft['name'] ?? ''));
    }
    if (!isset($projectPayload['description'])) {
        $projectPayload['description'] = trim((string)($currentDraft['description'] ?? ''));
    }
    if (!isset($projectPayload['status'])) {
        $projectPayload['status'] = 'draft';
    }

    if ($type === 'template') {
        if (!isset($projectPayload['templateId'])) {
            $projectPayload['templateId'] = trim((string)($currentDraft['id'] ?? ''));
        }
        if (!isset($projectPayload['sourceType'])) {
            $projectPayload['sourceType'] = trim((string)($input['sourceType'] ?? 'local')) ?: 'local';
        }
        return $projectPayload;
    }

    if (!isset($projectPayload['commandId'])) {
        $projectPayload['commandId'] = trim((string)($currentDraft['id'] ?? ''));
    }

    return $projectPayload;
}

function getLatestStudioProjectVersionId(array $project): ?string {
    $versions = is_array($project['versions'] ?? null) ? $project['versions'] : [];
    $latest = $versions[0] ?? null;
    $versionId = trim((string)($latest['id'] ?? ''));
    return $versionId !== '' ? $versionId : null;
}

function normalizeStudioHistoryThreadId($threadId): string {
    $value = trim((string)$threadId);
    if ($value === '' || $value === '__viewer_thread__' || str_starts_with($value, 'viewer::')) {
        return '';
    }

    return $value;
}

function studioDraftsEqual($left, $right): bool {
    return studioDraftSignature($left) === studioDraftSignature($right);
}

function studioDraftSignature($value): string {
    return json_encode(studioNormalizeDraft($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: 'null';
}

function studioNormalizeDraft($value) {
    if (is_array($value)) {
        if (array_is_list($value)) {
            return array_map('studioNormalizeDraft', $value);
        }

        $normalized = [];
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        foreach ($keys as $key) {
            $normalized[$key] = studioNormalizeDraft($value[$key]);
        }
        return $normalized;
    }

    return $value;
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
