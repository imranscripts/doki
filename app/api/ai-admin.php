<?php
/**
 * ai-admin.php - AI administration API endpoints
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);
ob_start();

require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/AIProviderManager.php';
require_once __DIR__ . '/../includes/AIAdminManager.php';
require_once __DIR__ . '/../includes/session-bootstrap.php';

header('Content-Type: application/json');

doki_start_session();

function jsonResponse($data, int $code = 200): void {
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

function requireAdminRole(array $user): void {
    if (!in_array($user['role'] ?? '', ['super-admin', 'admin'], true)) {
        jsonResponse(['success' => false, 'error' => 'Admin access required'], 403);
    }
}

function requireAiAdminAccess(Auth $auth, array $user): void {
    requireAdminRole($user);
    if (!$auth->canAccessResource('module', 'ai.admin')) {
        jsonResponse(['success' => false, 'error' => 'AI admin access required'], 403);
    }
}

doki_start_session();
$auth = new Auth();
$token = $_SESSION['auth_token'] ?? null;
$user = $token ? $auth->validateSession($token) : null;

if (!$user) {
    jsonResponse(['success' => false, 'error' => 'Unauthorized'], 401);
}

requireAiAdminAccess($auth, $user);

$aiProviderManager = new AIProviderManager();
$aiAdminManager = new AIAdminManager();
$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'list-providers':
            jsonResponse(['success' => true, 'providers' => $aiProviderManager->getProviders()]);
            break;

        case 'save-provider':
            $input = readJsonBody();
            $providerId = trim((string)($input['id'] ?? ''));

            if ($providerId !== '' && $aiProviderManager->getProvider($providerId) !== null) {
                $result = $aiProviderManager->updateProvider($providerId, $input, $user['id'], $user['username']);
                $auditAction = 'ai.admin.provider.update';
                $auditDetails = 'Updated AI provider from AI admin: ' . $providerId;
            } else {
                $result = $aiProviderManager->createProvider($input, $user['id'], $user['username']);
                $auditAction = 'ai.admin.provider.create';
                $auditDetails = 'Created AI provider from AI admin: ' . (($result['provider']['id'] ?? $providerId) ?: 'new provider');
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

        case 'delete-provider':
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
                    'ai.admin.provider.delete',
                    'ai_provider',
                    $providerId,
                    'Deleted AI provider from AI admin: ' . ($provider['name'] ?? $providerId)
                );
                jsonResponse($result);
            }

            jsonResponse($result, 400);
            break;

        case 'test-provider':
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
                    'ai.admin.provider.test',
                    'ai_provider',
                    $providerId,
                    'Tested AI provider connectivity from AI admin'
                );
                jsonResponse($result);
            }

            jsonResponse($result, 400);
            break;

        case 'list-models':
            $providerId = trim((string)($_GET['id'] ?? ''));
            if ($providerId === '') {
                jsonResponse(['success' => false, 'error' => 'Provider ID required'], 400);
            }

            $result = $aiProviderManager->listModels($providerId);
            jsonResponse($result, !empty($result['success']) ? 200 : 400);
            break;

        case 'list-models-draft':
            $input = readJsonBody();
            $result = $aiProviderManager->listModelsFromDraft($input);
            jsonResponse($result, !empty($result['success']) ? 200 : 400);
            break;

        case 'list-context-blocks':
            $scope = trim((string)($_GET['scope'] ?? AIAdminManager::SCOPE_APPS));
            jsonResponse([
                'success' => true,
                'scope' => $scope,
                'blocks' => $aiAdminManager->getContextBlocks($scope),
            ]);
            break;

        case 'save-context-blocks':
            $input = readJsonBody();
            $scope = trim((string)($input['scope'] ?? AIAdminManager::SCOPE_APPS));
            $blocks = is_array($input['blocks'] ?? null) ? $input['blocks'] : [];
            $result = $aiAdminManager->saveContextBlocks($scope, $blocks, $user['id']);

            if (!empty($result['success'])) {
                $auth->auditLog(
                    $user['id'],
                    $user['username'],
                    'ai.admin.context.save',
                    'ai_context_scope',
                    $result['scope'] ?? $scope,
                    'Updated AI admin context blocks for scope ' . ($result['scope'] ?? $scope)
                );
                jsonResponse($result);
            }

            jsonResponse($result, 400);
            break;

        case 'delete-context-block':
            $input = readJsonBody();
            $scope = trim((string)($input['scope'] ?? AIAdminManager::SCOPE_APPS));
            $blockId = trim((string)($input['id'] ?? ''));
            if ($blockId === '') {
                jsonResponse(['success' => false, 'error' => 'Context block ID required'], 400);
            }

            $result = $aiAdminManager->deleteContextBlock($scope, $blockId);
            if (!empty($result['success'])) {
                $auth->auditLog(
                    $user['id'],
                    $user['username'],
                    'ai.admin.context.delete',
                    'ai_context_block',
                    $blockId,
                    'Deleted AI admin context block from scope ' . ($result['scope'] ?? $scope)
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
