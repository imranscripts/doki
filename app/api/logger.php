<?php
/**
 * Logger API
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);
ini_set('log_errors', '1');

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/PermissionManager.php';
require_once __DIR__ . '/../includes/LoggerManager.php';
require_once __DIR__ . '/../includes/LoggerTargetResolver.php';
require_once __DIR__ . '/../includes/LoggerTargetFileBrowser.php';
require_once __DIR__ . '/../includes/LoggerTargetLogReader.php';
require_once __DIR__ . '/../includes/session-bootstrap.php';

doki_start_session();

function loggerJsonResponse(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function loggerRequestBody(): array {
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        loggerJsonResponse(['success' => false, 'error' => 'Invalid JSON'], 400);
    }

    return $decoded;
}

function loggerRequireAuth(): array {
    $token = $_SESSION['auth_token'] ?? null;
    if (!$token) {
        loggerJsonResponse(['success' => false, 'error' => 'Not authenticated'], 401);
    }

    $auth = new Auth();
    $user = $auth->validateSession($token);
    if (!$user) {
        loggerJsonResponse(['success' => false, 'error' => 'Session expired'], 401);
    }

    if (($user['role'] ?? '') !== 'super-admin') {
        $permManager = new PermissionManager();
        if (!$permManager->canAccessResource($user, 'app', 'logger')) {
            loggerJsonResponse(['success' => false, 'error' => 'Access denied'], 403);
        }
    }

    return $user;
}

function loggerUserHasRole(array $user, string $role): bool {
    $roleHierarchy = ['super-admin' => 3, 'admin' => 2, 'user' => 1];
    $userLevel = $roleHierarchy[$user['role'] ?? 'user'] ?? 0;
    $requiredLevel = $roleHierarchy[$role] ?? 999;
    return $userLevel >= $requiredLevel;
}

function loggerExpandGroupEntries(LoggerManager $manager, array $group): array {
    $entries = [];
    foreach ($group['entries'] ?? [] as $entrySummary) {
        if (!is_array($entrySummary)) {
            continue;
        }
        $entryId = (string)($entrySummary['id'] ?? '');
        if ($entryId === '') {
            continue;
        }
        $entry = $manager->getEntry($entryId);
        if (is_array($entry)) {
            $entries[] = $entry;
        }
    }
    return $entries;
}

function loggerRequestedFilters(LoggerManager $manager, string $ownerType, string $ownerId): array {
    $raw = $_GET['filterIds'] ?? $_GET['filterId'] ?? '';
    if (is_array($raw)) {
        $ids = $raw;
    } else {
        $ids = preg_split('/,/', (string)$raw) ?: [];
    }

    $ids = array_values(array_unique(array_filter(array_map(
        fn($id) => trim((string)$id),
        $ids
    ))));

    if ($ids === []) {
        return [];
    }

    $filters = [];
    foreach ($ids as $id) {
        $filter = $manager->getFilter($id);
        if ($filter === null) {
            loggerJsonResponse(['success' => false, 'error' => "Logger filter '{$id}' not found"], 404);
        }
        if (($filter['owner_type'] ?? '') !== $ownerType || ($filter['owner_id'] ?? '') !== $ownerId) {
            loggerJsonResponse(['success' => false, 'error' => "Logger filter '{$id}' does not belong to this {$ownerType}"], 400);
        }
        if ((bool)($filter['enabled'] ?? false)) {
            $filters[] = $filter;
        }
    }

    return $filters;
}

function loggerShouldAuditTail(): bool {
    return !filter_var($_GET['poll'] ?? false, FILTER_VALIDATE_BOOLEAN);
}

function loggerReadAuditDetails(array $result, int $limit, array $filters): string {
    $sources = is_array($result['sources'] ?? null) ? $result['sources'] : [];
    $sourceErrors = array_values(array_filter($sources, fn($source): bool => is_array($source) && !empty($source['error'])));

    return json_encode([
        'success' => $result['success'] ?? false,
        'lineCount' => is_array($result['lines'] ?? null) ? count($result['lines']) : 0,
        'sourceCount' => count($sources),
        'sourceErrorCount' => count($sourceErrors),
        'limit' => $limit,
        'filterCount' => count($filters),
    ]);
}

$user = loggerRequireAuth();
$manager = new LoggerManager();
$resolver = new LoggerTargetResolver();
$browser = new LoggerTargetFileBrowser(null, $resolver);
$reader = new LoggerTargetLogReader(null, $resolver);
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'entries':
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            loggerJsonResponse(['success' => false, 'error' => 'GET required'], 405);
        }

        loggerJsonResponse([
            'success' => true,
            'entries' => $manager->listEntries(),
        ]);
        break;

    case 'entry':
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $identifier = trim((string)($_GET['id'] ?? $_GET['slug'] ?? ''));
            if ($identifier === '') {
                loggerJsonResponse(['success' => false, 'error' => 'Entry id or slug required'], 400);
            }

            $entry = $manager->getEntry($identifier);
            if ($entry === null) {
                loggerJsonResponse(['success' => false, 'error' => 'Logger entry not found'], 404);
            }

            loggerJsonResponse(['success' => true, 'entry' => $entry]);
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' || $_SERVER['REQUEST_METHOD'] === 'PUT') {
            if (!loggerUserHasRole($user, 'admin')) {
                loggerJsonResponse(['success' => false, 'error' => 'Admin access required'], 403);
            }

            $body = loggerRequestBody();
            $result = $manager->saveEntry($body, $user, $resolver);
            $auth = new Auth();
            $auth->auditLog(
                $user['id'] ?? null,
                $user['username'] ?? 'system',
                empty($body['id']) ? 'logger.entry.create' : 'logger.entry.update',
                'logger',
                $result['entry']['id'] ?? ($body['id'] ?? null),
                json_encode([
                    'success' => $result['success'] ?? false,
                    'name' => $body['name'] ?? null,
                    'mode' => $body['mode'] ?? null,
                    'sourceCount' => is_array($body['sources'] ?? null) ? count($body['sources']) : 0,
                ]),
                $_SERVER['REMOTE_ADDR'] ?? null
            );

            loggerJsonResponse($result, ($result['success'] ?? false) ? 200 : 400);
        }

        if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
            if (!loggerUserHasRole($user, 'admin')) {
                loggerJsonResponse(['success' => false, 'error' => 'Admin access required'], 403);
            }

            $identifier = trim((string)($_GET['id'] ?? $_GET['slug'] ?? ''));
            if ($identifier === '') {
                loggerJsonResponse(['success' => false, 'error' => 'Entry id or slug required'], 400);
            }

            $result = $manager->deleteEntry($identifier);
            $auth = new Auth();
            $auth->auditLog(
                $user['id'] ?? null,
                $user['username'] ?? 'system',
                'logger.entry.delete',
                'logger',
                $identifier,
                json_encode(['success' => $result['success'] ?? false]),
                $_SERVER['REMOTE_ADDR'] ?? null
            );

            loggerJsonResponse($result, ($result['success'] ?? false) ? 200 : 404);
        }

        loggerJsonResponse(['success' => false, 'error' => 'Unsupported method'], 405);
        break;

    case 'groups':
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            loggerJsonResponse(['success' => false, 'error' => 'GET required'], 405);
        }

        loggerJsonResponse([
            'success' => true,
            'groups' => $manager->listGroups(),
        ]);
        break;

    case 'group':
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $identifier = trim((string)($_GET['id'] ?? $_GET['slug'] ?? ''));
            if ($identifier === '') {
                loggerJsonResponse(['success' => false, 'error' => 'Group id or slug required'], 400);
            }

            $group = $manager->getGroup($identifier);
            if ($group === null) {
                loggerJsonResponse(['success' => false, 'error' => 'Logger group not found'], 404);
            }

            loggerJsonResponse(['success' => true, 'group' => $group]);
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' || $_SERVER['REQUEST_METHOD'] === 'PUT') {
            if (!loggerUserHasRole($user, 'admin')) {
                loggerJsonResponse(['success' => false, 'error' => 'Admin access required'], 403);
            }

            $body = loggerRequestBody();
            $result = $manager->saveGroup($body, $user);
            $auth = new Auth();
            $auth->auditLog(
                $user['id'] ?? null,
                $user['username'] ?? 'system',
                empty($body['id']) ? 'logger.group.create' : 'logger.group.update',
                'logger',
                $result['group']['id'] ?? ($body['id'] ?? null),
                json_encode([
                    'success' => $result['success'] ?? false,
                    'name' => $body['name'] ?? null,
                    'entryCount' => is_array($body['entry_ids'] ?? $body['entryIds'] ?? null)
                        ? count($body['entry_ids'] ?? $body['entryIds'])
                        : 0,
                ]),
                $_SERVER['REMOTE_ADDR'] ?? null
            );

            loggerJsonResponse($result, ($result['success'] ?? false) ? 200 : 400);
        }

        if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
            if (!loggerUserHasRole($user, 'admin')) {
                loggerJsonResponse(['success' => false, 'error' => 'Admin access required'], 403);
            }

            $identifier = trim((string)($_GET['id'] ?? $_GET['slug'] ?? ''));
            if ($identifier === '') {
                loggerJsonResponse(['success' => false, 'error' => 'Group id or slug required'], 400);
            }

            $result = $manager->deleteGroup($identifier);
            $auth = new Auth();
            $auth->auditLog(
                $user['id'] ?? null,
                $user['username'] ?? 'system',
                'logger.group.delete',
                'logger',
                $identifier,
                json_encode(['success' => $result['success'] ?? false]),
                $_SERVER['REMOTE_ADDR'] ?? null
            );

            loggerJsonResponse($result, ($result['success'] ?? false) ? 200 : 404);
        }

        loggerJsonResponse(['success' => false, 'error' => 'Unsupported method'], 405);
        break;

    case 'filters':
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            loggerJsonResponse(['success' => false, 'error' => 'GET required'], 405);
        }

        $ownerType = trim((string)($_GET['ownerType'] ?? $_GET['owner_type'] ?? ''));
        $ownerId = trim((string)($_GET['ownerId'] ?? $_GET['owner_id'] ?? ''));
        $filters = ($ownerType !== '' && $ownerId !== '')
            ? $manager->listFilters($ownerType, $ownerId)
            : $manager->listAllFilters();

        loggerJsonResponse([
            'success' => true,
            'filters' => $filters,
        ]);
        break;

    case 'filter':
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $id = trim((string)($_GET['id'] ?? ''));
            if ($id === '') {
                loggerJsonResponse(['success' => false, 'error' => 'Filter id required'], 400);
            }

            $filter = $manager->getFilter($id);
            if ($filter === null) {
                loggerJsonResponse(['success' => false, 'error' => 'Logger filter not found'], 404);
            }

            loggerJsonResponse(['success' => true, 'filter' => $filter]);
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' || $_SERVER['REQUEST_METHOD'] === 'PUT') {
            if (!loggerUserHasRole($user, 'admin')) {
                loggerJsonResponse(['success' => false, 'error' => 'Admin access required'], 403);
            }

            $body = loggerRequestBody();
            $result = $manager->saveFilter($body, $user);
            $auth = new Auth();
            $auth->auditLog(
                $user['id'] ?? null,
                $user['username'] ?? 'system',
                empty($body['id']) ? 'logger.filter.create' : 'logger.filter.update',
                'logger',
                $result['filter']['id'] ?? ($body['id'] ?? null),
                json_encode([
                    'success' => $result['success'] ?? false,
                    'ownerType' => $body['owner_type'] ?? $body['ownerType'] ?? null,
                    'ownerId' => $body['owner_id'] ?? $body['ownerId'] ?? null,
                    'mode' => $body['mode'] ?? null,
                    'patternLength' => strlen(trim((string)($body['pattern'] ?? ''))),
                ]),
                $_SERVER['REMOTE_ADDR'] ?? null
            );

            loggerJsonResponse($result, ($result['success'] ?? false) ? 200 : 400);
        }

        if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
            if (!loggerUserHasRole($user, 'admin')) {
                loggerJsonResponse(['success' => false, 'error' => 'Admin access required'], 403);
            }

            $id = trim((string)($_GET['id'] ?? ''));
            if ($id === '') {
                loggerJsonResponse(['success' => false, 'error' => 'Filter id required'], 400);
            }

            $result = $manager->deleteFilter($id);
            $auth = new Auth();
            $auth->auditLog(
                $user['id'] ?? null,
                $user['username'] ?? 'system',
                'logger.filter.delete',
                'logger',
                $id,
                json_encode(['success' => $result['success'] ?? false]),
                $_SERVER['REMOTE_ADDR'] ?? null
            );

            loggerJsonResponse($result, ($result['success'] ?? false) ? 200 : 404);
        }

        loggerJsonResponse(['success' => false, 'error' => 'Unsupported method'], 405);
        break;

    case 'target-context':
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            loggerJsonResponse(['success' => false, 'error' => 'GET required'], 405);
        }

        if (!loggerUserHasRole($user, 'admin')) {
            loggerJsonResponse(['success' => false, 'error' => 'Admin access required'], 403);
        }

        loggerJsonResponse([
            'success' => true,
            'logCapableTypes' => $resolver->getLogCapableTypes(),
            'targets' => $resolver->listLogCapableTargets(),
            'environments' => $resolver->listEnvironmentContexts(),
        ]);
        break;

    case 'validate-sources':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            loggerJsonResponse(['success' => false, 'error' => 'POST required'], 405);
        }

        if (!loggerUserHasRole($user, 'admin')) {
            loggerJsonResponse(['success' => false, 'error' => 'Admin access required'], 403);
        }

        $body = loggerRequestBody();
        $entry = $body['entry'] ?? [];
        $sources = $body['sources'] ?? [];
        if (!is_array($entry) || !is_array($sources)) {
            loggerJsonResponse(['success' => false, 'error' => 'entry and sources must be objects/arrays'], 400);
        }

        $validation = $resolver->validateEntrySources($entry, $sources);
        $auth = new Auth();
        $auth->auditLog(
            $user['id'] ?? null,
            $user['username'] ?? 'system',
            'logger.sources.validate',
            'logger',
            null,
            json_encode([
                'valid' => $validation['valid'] ?? false,
                'sourceCount' => count($sources),
                'errorCount' => is_array($validation['errors'] ?? null) ? count($validation['errors']) : 0,
                'mode' => $entry['mode'] ?? null,
            ]),
            $_SERVER['REMOTE_ADDR'] ?? null
        );
        loggerJsonResponse([
            'success' => true,
            'validation' => $validation,
        ]);
        break;

    case 'browse':
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            loggerJsonResponse(['success' => false, 'error' => 'GET required'], 405);
        }

        if (!loggerUserHasRole($user, 'admin')) {
            loggerJsonResponse(['success' => false, 'error' => 'Admin access required'], 403);
        }

        $path = (string)($_GET['path'] ?? '/');
        $options = [];
        if (isset($_GET['container'])) {
            $options['container'] = (string)$_GET['container'];
        }

        $targetId = trim((string)($_GET['targetId'] ?? ''));
        $environmentId = trim((string)($_GET['environmentId'] ?? ''));
        $environmentTargetKey = trim((string)($_GET['environmentTargetKey'] ?? ''));

        if ($targetId !== '') {
            $result = $browser->browseTarget($targetId, $path, $options);
            $resourceId = $targetId;
        } elseif ($environmentId !== '' && $environmentTargetKey !== '') {
            $result = $browser->browseEnvironmentTarget($environmentId, $environmentTargetKey, $path, $options);
            $resourceId = $environmentId . ':' . $environmentTargetKey;
        } else {
            loggerJsonResponse(['success' => false, 'error' => 'targetId or environmentId + environmentTargetKey required'], 400);
        }

        $auth = new Auth();
        $auth->auditLog(
            $user['id'] ?? null,
            $user['username'] ?? 'system',
            'logger.browse',
            'logger',
            $resourceId,
            json_encode(['path' => $path, 'success' => $result['success'] ?? false]),
            $_SERVER['REMOTE_ADDR'] ?? null
        );

        loggerJsonResponse($result, ($result['success'] ?? false) ? 200 : 400);
        break;

    case 'preview':
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            loggerJsonResponse(['success' => false, 'error' => 'GET required'], 405);
        }

        if (!loggerUserHasRole($user, 'admin')) {
            loggerJsonResponse(['success' => false, 'error' => 'Admin access required'], 403);
        }

        $path = (string)($_GET['path'] ?? '');
        if (trim($path) === '') {
            loggerJsonResponse(['success' => false, 'error' => 'path required'], 400);
        }

        $options = [
            'limit' => (int)($_GET['limit'] ?? 8192),
        ];
        if (isset($_GET['container'])) {
            $options['container'] = (string)$_GET['container'];
        }

        $targetId = trim((string)($_GET['targetId'] ?? ''));
        $environmentId = trim((string)($_GET['environmentId'] ?? ''));
        $environmentTargetKey = trim((string)($_GET['environmentTargetKey'] ?? ''));

        if ($targetId !== '') {
            $result = $browser->previewTarget($targetId, $path, $options);
            $resourceId = $targetId;
        } elseif ($environmentId !== '' && $environmentTargetKey !== '') {
            $result = $browser->previewEnvironmentTarget($environmentId, $environmentTargetKey, $path, $options);
            $resourceId = $environmentId . ':' . $environmentTargetKey;
        } else {
            loggerJsonResponse(['success' => false, 'error' => 'targetId or environmentId + environmentTargetKey required'], 400);
        }

        $auth = new Auth();
        $auth->auditLog(
            $user['id'] ?? null,
            $user['username'] ?? 'system',
            'logger.preview',
            'logger',
            $resourceId,
            json_encode(['path' => $path, 'success' => $result['success'] ?? false]),
            $_SERVER['REMOTE_ADDR'] ?? null
        );

        loggerJsonResponse($result, ($result['success'] ?? false) ? 200 : 400);
        break;

    case 'tail':
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            loggerJsonResponse(['success' => false, 'error' => 'GET required'], 405);
        }

        $identifier = trim((string)($_GET['id'] ?? $_GET['slug'] ?? ''));
        if ($identifier === '') {
            loggerJsonResponse(['success' => false, 'error' => 'Entry id or slug required'], 400);
        }

        $entry = $manager->getEntry($identifier);
        if ($entry === null) {
            loggerJsonResponse(['success' => false, 'error' => 'Logger entry not found'], 404);
        }

        $lineLimit = (int)($_GET['lines'] ?? 200);
        $filters = loggerRequestedFilters($manager, 'entry', $entry['id']);
        $result = $reader->tailEntry($entry, $lineLimit, $filters);
        if (loggerShouldAuditTail()) {
            $auth = new Auth();
            $auth->auditLog(
                $user['id'] ?? null,
                $user['username'] ?? 'system',
                'logger.tail',
                'logger',
                $entry['id'] ?? $identifier,
                loggerReadAuditDetails($result, $lineLimit, $filters),
                $_SERVER['REMOTE_ADDR'] ?? null
            );
        }
        loggerJsonResponse($result, ($result['success'] ?? false) ? 200 : 400);
        break;

    case 'search':
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            loggerJsonResponse(['success' => false, 'error' => 'GET required'], 405);
        }

        $identifier = trim((string)($_GET['id'] ?? $_GET['slug'] ?? ''));
        if ($identifier === '') {
            loggerJsonResponse(['success' => false, 'error' => 'Entry id or slug required'], 400);
        }

        $entry = $manager->getEntry($identifier);
        if ($entry === null) {
            loggerJsonResponse(['success' => false, 'error' => 'Logger entry not found'], 404);
        }

        $query = (string)($_GET['q'] ?? '');
        $resultLimit = (int)($_GET['limit'] ?? 200);
        $caseSensitive = filter_var($_GET['caseSensitive'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $filters = loggerRequestedFilters($manager, 'entry', $entry['id']);
        $result = $reader->searchEntry($entry, $query, $resultLimit, $caseSensitive, $filters);

        $auth = new Auth();
        $auth->auditLog(
            $user['id'] ?? null,
            $user['username'] ?? 'system',
            'logger.search',
            'logger',
            $entry['id'] ?? $identifier,
            json_encode([
                'success' => $result['success'] ?? false,
                'queryLength' => strlen(trim($query)),
                'caseSensitive' => $caseSensitive,
                'limit' => $resultLimit,
                'filterCount' => count($filters),
            ]),
            $_SERVER['REMOTE_ADDR'] ?? null
        );

        loggerJsonResponse($result, ($result['success'] ?? false) ? 200 : 400);
        break;

    case 'group-tail':
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            loggerJsonResponse(['success' => false, 'error' => 'GET required'], 405);
        }

        $identifier = trim((string)($_GET['id'] ?? $_GET['slug'] ?? ''));
        if ($identifier === '') {
            loggerJsonResponse(['success' => false, 'error' => 'Group id or slug required'], 400);
        }

        $group = $manager->getGroup($identifier);
        if ($group === null) {
            loggerJsonResponse(['success' => false, 'error' => 'Logger group not found'], 404);
        }

        $lineLimit = (int)($_GET['lines'] ?? 200);
        $filters = loggerRequestedFilters($manager, 'group', $group['id']);
        $result = $reader->tailGroup($group, loggerExpandGroupEntries($manager, $group), $lineLimit, $filters);
        if (loggerShouldAuditTail()) {
            $auth = new Auth();
            $auth->auditLog(
                $user['id'] ?? null,
                $user['username'] ?? 'system',
                'logger.group.tail',
                'logger',
                $group['id'] ?? $identifier,
                loggerReadAuditDetails($result, $lineLimit, $filters),
                $_SERVER['REMOTE_ADDR'] ?? null
            );
        }
        loggerJsonResponse($result, ($result['success'] ?? false) ? 200 : 400);
        break;

    case 'group-search':
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            loggerJsonResponse(['success' => false, 'error' => 'GET required'], 405);
        }

        $identifier = trim((string)($_GET['id'] ?? $_GET['slug'] ?? ''));
        if ($identifier === '') {
            loggerJsonResponse(['success' => false, 'error' => 'Group id or slug required'], 400);
        }

        $group = $manager->getGroup($identifier);
        if ($group === null) {
            loggerJsonResponse(['success' => false, 'error' => 'Logger group not found'], 404);
        }

        $query = (string)($_GET['q'] ?? '');
        $resultLimit = (int)($_GET['limit'] ?? 200);
        $caseSensitive = filter_var($_GET['caseSensitive'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $filters = loggerRequestedFilters($manager, 'group', $group['id']);
        $result = $reader->searchGroup($group, loggerExpandGroupEntries($manager, $group), $query, $resultLimit, $caseSensitive, $filters);

        $auth = new Auth();
        $auth->auditLog(
            $user['id'] ?? null,
            $user['username'] ?? 'system',
            'logger.group.search',
            'logger',
            $group['id'] ?? $identifier,
            json_encode([
                'success' => $result['success'] ?? false,
                'queryLength' => strlen(trim($query)),
                'caseSensitive' => $caseSensitive,
                'limit' => $resultLimit,
                'filterCount' => count($filters),
            ]),
            $_SERVER['REMOTE_ADDR'] ?? null
        );

        loggerJsonResponse($result, ($result['success'] ?? false) ? 200 : 400);
        break;

    default:
        loggerJsonResponse(['success' => false, 'error' => 'Unknown action'], 404);
}
