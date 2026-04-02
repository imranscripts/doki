<?php
/**
 * studio.php - App Studio API endpoints
 *
 * Phase 0 foundation for workspace CRUD, file operations, and validation.
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);
ob_start();

require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/WorkspaceManager.php';
require_once __DIR__ . '/../includes/AIProviderManager.php';
require_once __DIR__ . '/../includes/WorkspaceAIManager.php';
require_once __DIR__ . '/../includes/WorkspaceAIJobManager.php';
require_once __DIR__ . '/../includes/StudioReleaseManager.php';
require_once __DIR__ . '/../includes/AppManager.php';
require_once __DIR__ . '/../includes/session-bootstrap.php';

header('Content-Type: application/json');

function jsonResponse($data, $code = 200) {
    ob_end_clean();
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function readJsonBody(): array {
    $body = file_get_contents('php://input');
    if (!is_string($body) || trim($body) === '') {
        return [];
    }

    $decoded = json_decode($body, true);
    return is_array($decoded) ? $decoded : [];
}

function requireAdmin(array $user): void {
    if (!in_array($user['role'] ?? '', ['super-admin', 'admin'], true)) {
        jsonResponse(['success' => false, 'error' => 'Admin access required'], 403);
    }
}

function requireStudioAi(Auth $auth, array $user): void {
    requireAdmin($user);
    if (!$auth->canAccessResource('module', 'studio.ai')) {
        jsonResponse(['success' => false, 'error' => 'App Studio AI access required'], 403);
    }
}

doki_start_session();
$auth = new Auth();
$token = $_SESSION['auth_token'] ?? null;
$user = $token ? $auth->validateSession($token) : null;

if (!$user) {
    jsonResponse(['success' => false, 'error' => 'Unauthorized'], 401);
}

requireAdmin($user);

$workspaceManager = new WorkspaceManager();
$aiProviderManager = new AIProviderManager();
$workspaceAIManager = new WorkspaceAIManager();
$workspaceAIJobManager = new WorkspaceAIJobManager();
$studioReleaseManager = new StudioReleaseManager();
$appManager = new AppManager();
$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'list-workspaces':
            jsonResponse(['success' => true, 'workspaces' => $workspaceManager->getWorkspaces()]);
            break;

        case 'get-workspace':
            $workspaceId = trim((string)($_GET['id'] ?? ''));
            if ($workspaceId === '') {
                jsonResponse(['success' => false, 'error' => 'Workspace ID required'], 400);
            }

            $workspace = $workspaceManager->getWorkspace($workspaceId);
            if ($workspace === null) {
                jsonResponse(['success' => false, 'error' => 'Workspace not found'], 404);
            }

            jsonResponse(['success' => true, 'workspace' => $workspace]);
            break;

        case 'create-starter-workspace':
            $input = readJsonBody();
            $result = $workspaceManager->createStarterWorkspace($input, $user['id'], $user['username']);

            if (!empty($result['success'])) {
                $workspace = $result['workspace'];
                $auth->auditLog(
                    $user['id'],
                    $user['username'],
                    'workspace.create',
                    'app_workspace',
                    $workspace['id'],
                    'Created starter workspace: ' . ($workspace['name'] ?? $workspace['id'])
                );
                jsonResponse($result, 201);
            }

            jsonResponse($result, 400);
            break;

        case 'import-repo-workspace':
            $input = readJsonBody();
            $result = $workspaceManager->importRepositoryWorkspace($input, $user['id'], $user['username']);

            if (!empty($result['success'])) {
                $workspace = $result['workspace'];
                $auth->auditLog(
                    $user['id'],
                    $user['username'],
                    'workspace.clone',
                    'app_workspace',
                    $workspace['id'],
                    'Imported repository workspace: ' . ($workspace['repo_url'] ?? 'unknown repo')
                );
                jsonResponse($result, 201);
            }

            jsonResponse($result, 400);
            break;

        case 'delete-workspace':
            $input = readJsonBody();
            $workspaceId = trim((string)($input['id'] ?? $_GET['id'] ?? ''));
            if ($workspaceId === '') {
                jsonResponse(['success' => false, 'error' => 'Workspace ID required'], 400);
            }

            $workspace = $workspaceManager->getWorkspace($workspaceId);
            if ($workspace === null) {
                jsonResponse(['success' => false, 'error' => 'Workspace not found'], 404);
            }

            $result = $workspaceManager->deleteWorkspace($workspaceId);
            if (!empty($result['success'])) {
                $auth->auditLog(
                    $user['id'],
                    $user['username'],
                    'workspace.delete',
                    'app_workspace',
                    $workspaceId,
                    'Deleted workspace: ' . ($workspace['name'] ?? $workspaceId)
                );
                jsonResponse($result);
            }

            jsonResponse($result, 400);
            break;

        case 'set-workspace-app':
            $input = readJsonBody();
            $workspaceId = trim((string)($input['id'] ?? $_GET['id'] ?? ''));
            $appPath = trim((string)($input['appPath'] ?? $_GET['appPath'] ?? ''));
            if ($workspaceId === '' || $appPath === '') {
                jsonResponse(['success' => false, 'error' => 'Workspace ID and app path are required'], 400);
            }

            $workspace = $workspaceManager->getWorkspace($workspaceId);
            if ($workspace === null) {
                jsonResponse(['success' => false, 'error' => 'Workspace not found'], 404);
            }

            $result = $workspaceManager->setWorkspaceApp($workspaceId, $appPath, $user['id']);
            if (!empty($result['success'])) {
                $auth->auditLog(
                    $user['id'],
                    $user['username'],
                    'workspace.set_app',
                    'app_workspace',
                    $workspaceId,
                    'Selected workspace app path: ' . $appPath
                );
                jsonResponse($result);
            }

            jsonResponse($result, 400);
            break;

        case 'list-files':
            $workspaceId = trim((string)($_GET['id'] ?? ''));
            $path = trim((string)($_GET['path'] ?? ''));
            if ($workspaceId === '') {
                jsonResponse(['success' => false, 'error' => 'Workspace ID required'], 400);
            }

            $result = $workspaceManager->listFiles($workspaceId, $path);
            jsonResponse($result, !empty($result['success']) ? 200 : 400);
            break;

        case 'get-file':
            $workspaceId = trim((string)($_GET['id'] ?? ''));
            $path = trim((string)($_GET['path'] ?? ''));
            if ($workspaceId === '' || $path === '') {
                jsonResponse(['success' => false, 'error' => 'Workspace ID and file path are required'], 400);
            }

            $file = $workspaceManager->getFileContent($workspaceId, $path);
            if ($file === null) {
                jsonResponse(['success' => false, 'error' => 'File not found'], 404);
            }

            jsonResponse(['success' => true, 'file' => $file]);
            break;

        case 'save-file':
            $input = readJsonBody();
            $workspaceId = trim((string)($input['id'] ?? ''));
            $path = trim((string)($input['path'] ?? ''));
            if ($workspaceId === '' || $path === '') {
                jsonResponse(['success' => false, 'error' => 'Workspace ID and file path are required'], 400);
            }

            $content = (string)($input['content'] ?? '');
            $result = $workspaceManager->saveFileContent($workspaceId, $path, $content, $user['id']);
            jsonResponse($result, !empty($result['success']) ? 200 : 400);
            break;

        case 'create-file':
            $input = readJsonBody();
            $workspaceId = trim((string)($input['id'] ?? ''));
            $path = trim((string)($input['path'] ?? ''));
            if ($workspaceId === '' || $path === '') {
                jsonResponse(['success' => false, 'error' => 'Workspace ID and file path are required'], 400);
            }

            $content = (string)($input['content'] ?? '');
            $result = $workspaceManager->createFile($workspaceId, $path, $content, $user['id']);
            jsonResponse($result, !empty($result['success']) ? 201 : 400);
            break;

        case 'delete-file':
            $input = readJsonBody();
            $workspaceId = trim((string)($input['id'] ?? ''));
            $path = trim((string)($input['path'] ?? ''));
            if ($workspaceId === '' || $path === '') {
                jsonResponse(['success' => false, 'error' => 'Workspace ID and file path are required'], 400);
            }

            $result = $workspaceManager->deleteFile($workspaceId, $path, $user['id']);
            jsonResponse($result, !empty($result['success']) ? 200 : 400);
            break;

        case 'validate-workspace':
            $input = readJsonBody();
            $workspaceId = trim((string)($input['id'] ?? $_GET['id'] ?? ''));
            if ($workspaceId === '') {
                jsonResponse(['success' => false, 'error' => 'Workspace ID required'], 400);
            }

            $result = $workspaceManager->validateWorkspace($workspaceId);
            if (!empty($result['success'])) {
                $auth->auditLog(
                    $user['id'],
                    $user['username'],
                    'workspace.validate',
                    'app_workspace',
                    $workspaceId,
                    'Validated workspace'
                );
            }
            jsonResponse($result, !empty($result['success']) ? 200 : 400);
            break;

        case 'create-workspace-release':
            $input = readJsonBody();
            $workspaceId = trim((string)($input['id'] ?? $_GET['id'] ?? ''));
            if ($workspaceId === '') {
                jsonResponse(['success' => false, 'error' => 'Workspace ID required'], 400);
            }

            $result = $studioReleaseManager->createReleaseFromWorkspace($workspaceId, $user['id'], $user['username'], $input);
            if (!empty($result['success'])) {
                $release = $result['release'] ?? [];
                $auth->auditLog(
                    $user['id'],
                    $user['username'],
                    'workspace.release.create',
                    'app_workspace',
                    $workspaceId,
                    'Created Studio release ' . ($release['id'] ?? 'unknown')
                );
                jsonResponse($result, 201);
            }

            jsonResponse($result, 400);
            break;

        case 'publish-workspace-app':
            $input = readJsonBody();
            $workspaceId = trim((string)($input['id'] ?? $_GET['id'] ?? ''));
            if ($workspaceId === '') {
                jsonResponse(['success' => false, 'error' => 'Workspace ID required'], 400);
            }

            $result = $studioReleaseManager->publishWorkspaceRelease($workspaceId, $user['id'], $user['username'], $input);
            if (!empty($result['success'])) {
                $release = $result['release'] ?? [];
                $auth->auditLog(
                    $user['id'],
                    $user['username'],
                    'workspace.release.publish',
                    'app_workspace',
                    $workspaceId,
                    'Published Studio release ' . ($release['id'] ?? 'unknown') . ' to the local marketplace'
                );
                jsonResponse($result, 201);
            }

            jsonResponse($result, 400);
            break;

        case 'install-workspace-app':
            $input = readJsonBody();
            $workspaceId = trim((string)($input['id'] ?? $_GET['id'] ?? ''));
            if ($workspaceId === '') {
                jsonResponse(['success' => false, 'error' => 'Workspace ID required'], 400);
            }

            $created = $studioReleaseManager->createReleaseFromWorkspace($workspaceId, $user['id'], $user['username'], $input);
            if (empty($created['success'])) {
                jsonResponse($created, 400);
            }

            $release = $created['release'] ?? [];
            $releaseId = trim((string)($release['id'] ?? ''));
            if ($releaseId === '') {
                jsonResponse(['success' => false, 'error' => 'Studio release could not be created'], 500);
            }

            $trustLevel = isset($input['trustLevel']) ? (int)$input['trustLevel'] : null;
            $result = $appManager->installStudioRelease($releaseId, $user['id'], $user['username'], $trustLevel);
            if (!empty($result['success'])) {
                $appId = trim((string)($result['appId'] ?? $release['app_id'] ?? ''));
                if ($appId !== '') {
                    $studioReleaseManager->setWorkspaceLinkedInstalledApp($workspaceId, $appId, $user['id']);
                }

                $auth->auditLog(
                    $user['id'],
                    $user['username'],
                    'workspace.install',
                    'app_workspace',
                    $workspaceId,
                    ucfirst((string)($result['action'] ?? 'installed')) . ' app ' . ($appId !== '' ? $appId : 'unknown') . ' from Studio release ' . $releaseId
                );

                $result['release'] = $release;
                $result['validation'] = $created['validation'] ?? null;
                jsonResponse($result, 201);
            }

            jsonResponse($result, 400);
            break;

        case 'preview-workspace':
            $input = readJsonBody();
            $workspaceId = trim((string)($input['id'] ?? $_GET['id'] ?? ''));
            $page = trim((string)($input['page'] ?? $_GET['page'] ?? 'index.php'));
            if ($workspaceId === '') {
                jsonResponse(['success' => false, 'error' => 'Workspace ID required'], 400);
            }

            $result = $workspaceManager->syncPreview($workspaceId, $page);
            if (!empty($result['success'])) {
                $auth->auditLog(
                    $user['id'],
                    $user['username'],
                    'workspace.preview',
                    'app_workspace',
                    $workspaceId,
                    'Synced workspace preview'
                );
                jsonResponse($result);
            }

            jsonResponse($result, 400);
            break;

        case 'list-ai-providers':
            requireStudioAi($auth, $user);
            jsonResponse(['success' => true, 'providers' => $aiProviderManager->getProviders()]);
            break;

        case 'save-ai-provider':
            requireStudioAi($auth, $user);
            $input = readJsonBody();
            $providerId = trim((string)($input['id'] ?? ''));

            if ($providerId !== '' && $aiProviderManager->getProvider($providerId) !== null) {
                $result = $aiProviderManager->updateProvider($providerId, $input, $user['id'], $user['username']);
                $auditAction = 'studio.ai.provider.update';
                $auditDetails = 'Updated AI provider: ' . $providerId;
            } else {
                $result = $aiProviderManager->createProvider($input, $user['id'], $user['username']);
                $auditAction = 'studio.ai.provider.create';
                $auditDetails = 'Created AI provider: ' . (($result['provider']['id'] ?? $providerId) ?: 'new provider');
            }

            if (!empty($result['success'])) {
                $provider = $result['provider'] ?? null;
                $auth->auditLog(
                    $user['id'],
                    $user['username'],
                    $auditAction,
                    'ai_provider',
                    is_array($provider) ? ($provider['id'] ?? null) : $providerId,
                    $auditDetails
                );
                jsonResponse($result);
            }

            jsonResponse($result, 400);
            break;

        case 'delete-ai-provider':
            requireStudioAi($auth, $user);
            $input = readJsonBody();
            $providerId = trim((string)($input['id'] ?? $_GET['id'] ?? ''));
            if ($providerId === '') {
                jsonResponse(['success' => false, 'error' => 'Provider ID required'], 400);
            }

            $provider = $aiProviderManager->getProvider($providerId);
            if ($provider === null) {
                jsonResponse(['success' => false, 'error' => 'Provider not found'], 404);
            }

            $result = $aiProviderManager->deleteProvider($providerId, $user['id'], $user['username']);
            if (!empty($result['success'])) {
                $auth->auditLog(
                    $user['id'],
                    $user['username'],
                    'studio.ai.provider.delete',
                    'ai_provider',
                    $providerId,
                    'Deleted AI provider: ' . ($provider['name'] ?? $providerId)
                );
                jsonResponse($result);
            }

            jsonResponse($result, 400);
            break;

        case 'test-ai-provider':
            requireStudioAi($auth, $user);
            $input = readJsonBody();
            $providerId = trim((string)($input['id'] ?? $_GET['id'] ?? ''));
            if ($providerId === '') {
                jsonResponse(['success' => false, 'error' => 'Provider ID required'], 400);
            }

            $result = $aiProviderManager->testProvider($providerId);
            if (!empty($result['success'])) {
                $auth->auditLog(
                    $user['id'],
                    $user['username'],
                    'studio.ai.provider.test',
                    'ai_provider',
                    $providerId,
                    'Tested AI provider connectivity'
                );
                jsonResponse($result);
            }

            jsonResponse($result, 400);
            break;

        case 'list-ai-models':
            requireStudioAi($auth, $user);
            $providerId = trim((string)($_GET['id'] ?? ''));
            if ($providerId === '') {
                jsonResponse(['success' => false, 'error' => 'Provider ID required'], 400);
            }

            $result = $aiProviderManager->listModels($providerId);
            jsonResponse($result, !empty($result['success']) ? 200 : 400);
            break;

        case 'list-ai-models-draft':
            requireStudioAi($auth, $user);
            $input = readJsonBody();
            $result = $aiProviderManager->listModelsFromDraft($input);
            jsonResponse($result, !empty($result['success']) ? 200 : 400);
            break;

        case 'ai-chat':
            requireStudioAi($auth, $user);
            $input = readJsonBody();
            $workspaceId = trim((string)($input['id'] ?? ''));
            $providerId = trim((string)($input['providerId'] ?? ''));
            $messages = is_array($input['messages'] ?? null) ? $input['messages'] : [];
            if ($workspaceId === '' || $providerId === '') {
                jsonResponse(['success' => false, 'error' => 'Workspace ID and provider ID are required'], 400);
            }

            $result = $workspaceAIManager->chat($workspaceId, $providerId, $messages, [
                'model' => trim((string)($input['model'] ?? '')),
                'currentFilePath' => trim((string)($input['currentFilePath'] ?? '')),
                'targetFilePath' => trim((string)($input['targetFilePath'] ?? '')),
                'targetStartLine' => $input['targetStartLine'] ?? null,
                'targetEndLine' => $input['targetEndLine'] ?? null,
                'targetSelectedText' => (string)($input['targetSelectedText'] ?? ''),
            ]);
            if (!empty($result['success'])) {
                $auth->auditLog(
                    $user['id'],
                    $user['username'],
                    'studio.ai.chat',
                    'app_workspace',
                    $workspaceId,
                    'Generated AI workspace plan via provider ' . $providerId . ' using model ' . (($result['model'] ?? 'unknown'))
                );
                jsonResponse($result);
            }

            jsonResponse($result, 400);
            break;

        case 'start-ai-chat-job':
            requireStudioAi($auth, $user);
            $input = readJsonBody();
            $workspaceId = trim((string)($input['id'] ?? ''));
            $providerId = trim((string)($input['providerId'] ?? ''));
            $messages = is_array($input['messages'] ?? null) ? $input['messages'] : [];
            if ($workspaceId === '' || $providerId === '') {
                jsonResponse(['success' => false, 'error' => 'Workspace ID and provider ID are required'], 400);
            }

            $result = $workspaceAIJobManager->queueChatJob($workspaceId, $providerId, $messages, [
                'model' => trim((string)($input['model'] ?? '')),
                'currentFilePath' => trim((string)($input['currentFilePath'] ?? '')),
                'targetFilePath' => trim((string)($input['targetFilePath'] ?? '')),
                'targetStartLine' => $input['targetStartLine'] ?? null,
                'targetEndLine' => $input['targetEndLine'] ?? null,
                'targetSelectedText' => (string)($input['targetSelectedText'] ?? ''),
                'targetDomSelection' => is_array($input['targetDomSelection'] ?? null) ? $input['targetDomSelection'] : null,
            ], $user['id'], $user['username']);
            if (!empty($result['success'])) {
                $job = $result['job'] ?? null;
                $auth->auditLog(
                    $user['id'],
                    $user['username'],
                    'studio.ai.chat.async',
                    'app_workspace',
                    $workspaceId,
                    'Queued AI chat job ' . (is_array($job) ? ($job['id'] ?? 'unknown') : 'unknown')
                );
                jsonResponse($result, 202);
            }

            jsonResponse($result, 400);
            break;

        case 'ai-create-workspace':
            requireStudioAi($auth, $user);
            $input = readJsonBody();
            $providerId = trim((string)($input['providerId'] ?? ''));
            $prompt = trim((string)($input['prompt'] ?? ''));
            if ($providerId === '' || $prompt === '') {
                jsonResponse(['success' => false, 'error' => 'Provider ID and app prompt are required'], 400);
            }

            $result = $workspaceAIManager->createWorkspaceFromPrompt($providerId, $prompt, $user['id'], $user['username'], [
                'model' => trim((string)($input['model'] ?? '')),
            ]);
            if (!empty($result['success'])) {
                $workspace = is_array($result['workspace'] ?? null) ? $result['workspace'] : null;
                $workspaceId = is_array($workspace) ? ($workspace['id'] ?? null) : null;
                $auth->auditLog(
                    $user['id'],
                    $user['username'],
                    'studio.ai.create_workspace',
                    'app_workspace',
                    $workspaceId,
                    'Created AI-generated workspace via provider ' . $providerId . ' using model ' . (($result['model'] ?? 'unknown'))
                );
                jsonResponse($result, 201);
            }

            jsonResponse($result, 400);
            break;

        case 'start-ai-create-workspace-job':
            requireStudioAi($auth, $user);
            $input = readJsonBody();
            $providerId = trim((string)($input['providerId'] ?? ''));
            $prompt = trim((string)($input['prompt'] ?? ''));
            if ($providerId === '' || $prompt === '') {
                jsonResponse(['success' => false, 'error' => 'Provider ID and app prompt are required'], 400);
            }

            $result = $workspaceAIJobManager->queueWorkspaceCreationJob($providerId, $prompt, [
                'model' => trim((string)($input['model'] ?? '')),
            ], $user['id'], $user['username']);
            if (!empty($result['success'])) {
                $job = $result['job'] ?? null;
                $auth->auditLog(
                    $user['id'],
                    $user['username'],
                    'studio.ai.create_workspace.async',
                    'ai_provider',
                    $providerId,
                    'Queued AI workspace creation job ' . (is_array($job) ? ($job['id'] ?? 'unknown') : 'unknown')
                );
                jsonResponse($result, 202);
            }

            jsonResponse($result, 400);
            break;

        case 'get-ai-job':
            requireStudioAi($auth, $user);
            $jobId = trim((string)($_GET['id'] ?? ''));
            if ($jobId === '') {
                jsonResponse(['success' => false, 'error' => 'Job ID required'], 400);
            }

            $job = $workspaceAIJobManager->getJobForUser($jobId, $user);
            if ($job === null) {
                jsonResponse(['success' => false, 'error' => 'AI job not found'], 404);
            }

            jsonResponse(['success' => true, 'job' => $job]);
            break;

        case 'ai-apply-changes':
            requireStudioAi($auth, $user);
            $input = readJsonBody();
            $workspaceId = trim((string)($input['id'] ?? ''));
            $operations = is_array($input['operations'] ?? null) ? $input['operations'] : [];
            if ($workspaceId === '') {
                jsonResponse(['success' => false, 'error' => 'Workspace ID required'], 400);
            }

            $result = $workspaceAIManager->applyChanges($workspaceId, $operations, $user['id']);
            if (!empty($result['success'])) {
                $auth->auditLog(
                    $user['id'],
                    $user['username'],
                    'studio.ai.apply',
                    'app_workspace',
                    $workspaceId,
                    'Applied ' . count($result['operations'] ?? []) . ' AI-generated workspace change(s)'
                );
                jsonResponse($result);
            }

            jsonResponse($result, 400);
            break;

        default:
            jsonResponse(['success' => false, 'error' => 'Unknown action'], 404);
    }
} catch (Throwable $e) {
    jsonResponse([
        'success' => false,
        'error' => $e->getMessage(),
    ], 500);
}
// Start session before auth checks
doki_start_session();
