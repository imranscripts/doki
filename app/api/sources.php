<?php
/**
 * Sources API - Unified Git Providers and Repositories Management
 * 
 * Part of Doki v3 Architecture - Batch 13: Doki Sources
 */

ob_start();
require_once __DIR__ . '/../includes/session-bootstrap.php';
doki_start_session();
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/SourcesManager.php';
require_once __DIR__ . '/../includes/ConfigManager.php';
require_once __DIR__ . '/../includes/PlaywrightProjectManager.php';

// Initialize
$db = Database::getInstance();
$auth = new Auth();
$sourcesManager = new SourcesManager();

// Check authentication
$token = $_SESSION['auth_token'] ?? null;
if (!$token) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$user = $auth->validateSession($token);
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Session expired']);
    exit;
}

$action = $_GET['action'] ?? '';

function jsonResponse($data, $code = 200) {
    ob_end_clean();
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function requireAdmin() {
    global $user;
    if (!in_array($user['role'], ['super-admin', 'admin'])) {
        jsonResponse(['success' => false, 'error' => 'Admin access required'], 403);
    }
}

function requireSuperAdmin() {
    global $user;
    if ($user['role'] !== 'super-admin') {
        jsonResponse(['success' => false, 'error' => 'Super-admin access required'], 403);
    }
}

switch ($action) {
    // =====================
    // GIT PROVIDERS
    // =====================
    
    case 'providers':
        // Get all git providers
        requireAdmin();
        $configManager = new ConfigManager();
        $status = $configManager->getSecretsStatus();
        if (empty($status['locked'])) {
            $sourcesManager->migrateLegacyTokens($user['id'], $user['username']);
        }

        $providers = $sourcesManager->getProviders();
        jsonResponse(['success' => true, 'providers' => $providers]);
        break;
        
    case 'provider':
        // Get a single provider
        requireAdmin();
        
        $id = $_GET['id'] ?? '';
        if (!$id) {
            jsonResponse(['success' => false, 'error' => 'Provider ID required'], 400);
        }

        $configManager = new ConfigManager();
        $status = $configManager->getSecretsStatus();
        if (empty($status['locked'])) {
            $sourcesManager->migrateLegacyTokens($user['id'], $user['username']);
        }

        $provider = $sourcesManager->getProvider($id);
        if (!$provider) {
            jsonResponse(['success' => false, 'error' => 'Provider not found'], 404);
        }
        
        jsonResponse(['success' => true, 'provider' => $provider]);
        break;
        
    case 'create-provider':
        // Create a new git provider
        requireSuperAdmin();
        
        $input = json_decode(file_get_contents('php://input'), true);
        $result = $sourcesManager->createProvider($input, $user['id'], $user['username']);
        
        if ($result['success']) {
            $auth->auditLog(
                $user['id'],
                $user['username'],
                'source.provider_create',
                'git_provider',
                $result['id'],
                "Created git provider: {$input['name']} ({$input['type']})"
            );
        }
        
        jsonResponse($result);
        break;
        
    case 'update-provider':
        // Update a git provider
        requireSuperAdmin();
        
        $id = $_GET['id'] ?? '';
        if (!$id) {
            jsonResponse(['success' => false, 'error' => 'Provider ID required'], 400);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        $result = $sourcesManager->updateProvider($id, $input, $user['id'], $user['username']);
        
        if ($result['success']) {
            $auth->auditLog(
                $user['id'],
                $user['username'],
                'source.provider_update',
                'git_provider',
                $id,
                "Updated git provider"
            );
        }
        
        jsonResponse($result);
        break;
        
    case 'delete-provider':
        // Delete a git provider
        requireSuperAdmin();
        
        $id = $_GET['id'] ?? '';
        if (!$id) {
            jsonResponse(['success' => false, 'error' => 'Provider ID required'], 400);
        }
        
        $result = $sourcesManager->deleteProvider($id);
        
        if ($result['success']) {
            $auth->auditLog(
                $user['id'],
                $user['username'],
                'source.provider_delete',
                'git_provider',
                $id,
                "Deleted git provider"
            );
        }
        
        jsonResponse($result);
        break;
        
    case 'test-provider':
        // Test provider connection
        requireAdmin();
        
        $id = $_GET['id'] ?? '';
        if (!$id) {
            jsonResponse(['success' => false, 'error' => 'Provider ID required'], 400);
        }
        
        $result = $sourcesManager->testProvider($id);
        
        $auth->auditLog(
            $user['id'],
            $user['username'],
            'source.provider_test',
            'git_provider',
            $id,
            "Tested provider: " . ($result['success'] ? 'Success' : 'Failed')
        );
        
        jsonResponse($result);
        break;
    
    // =====================
    // REPOSITORIES
    // =====================
    
    case 'repositories':
        // Get all repositories (optionally filtered by type)
        requireAdmin();
        
        $type = $_GET['type'] ?? null;
        $repositories = $sourcesManager->getRepositories($type);
        jsonResponse(['success' => true, 'repositories' => $repositories]);
        break;
        
    case 'repository':
        // Get a single repository
        requireAdmin();
        
        $id = $_GET['id'] ?? '';
        if (!$id) {
            jsonResponse(['success' => false, 'error' => 'Repository ID required'], 400);
        }
        
        $repo = $sourcesManager->getRepository($id);
        if (!$repo) {
            jsonResponse(['success' => false, 'error' => 'Repository not found'], 404);
        }
        
        jsonResponse(['success' => true, 'repository' => $repo]);
        break;
        
    case 'create-repository':
        // Create a new repository
        requireSuperAdmin();
        
        $input = json_decode(file_get_contents('php://input'), true);
        $result = $sourcesManager->createRepository($input, $user['id'], $user['username']);
        
        if ($result['success']) {
            $auth->auditLog(
                $user['id'],
                $user['username'],
                'source.repository_create',
                'repository',
                $result['id'],
                "Created repository: {$input['name']} (type: {$input['type']})"
            );
        }
        
        jsonResponse($result);
        break;
        
    case 'update-repository':
        // Update a repository
        requireSuperAdmin();
        
        $id = $_GET['id'] ?? '';
        if (!$id) {
            jsonResponse(['success' => false, 'error' => 'Repository ID required'], 400);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        $result = $sourcesManager->updateRepository($id, $input, $user['id']);
        
        if ($result['success']) {
            $repository = $sourcesManager->getRepository($id);
            if ($repository && in_array($repository['type'] ?? '', [SourcesManager::TYPE_PLAYWRIGHT, SourcesManager::TYPE_MIXED], true)) {
                $projectManager = new PlaywrightProjectManager();
                $importResult = $projectManager->syncRepositorySources($id);
                $result['importedProjects'] = $importResult['projects'] ?? [];
            }

            $auth->auditLog(
                $user['id'],
                $user['username'],
                'source.repository_update',
                'repository',
                $id,
                "Updated repository" . (!empty($result['importedProjects']) ? ' - Refreshed Playwright projects: ' . count($result['importedProjects']) : '')
            );
        }
        
        jsonResponse($result);
        break;
        
    case 'delete-repository':
        // Delete a repository
        requireSuperAdmin();
        
        $id = $_GET['id'] ?? '';
        if (!$id) {
            jsonResponse(['success' => false, 'error' => 'Repository ID required'], 400);
        }
        
        $deleteCache = ($_GET['deleteCache'] ?? 'true') === 'true';
        $result = $sourcesManager->deleteRepository($id, $deleteCache);
        
        if ($result['success']) {
            $auth->auditLog(
                $user['id'],
                $user['username'],
                'source.repository_delete',
                'repository',
                $id,
                "Deleted repository" . ($deleteCache ? ' with cache' : '')
            );
        }
        
        jsonResponse($result);
        break;
        
    case 'sync-repository':
        // Sync a repository (clone or pull)
        requireAdmin();
        
        $id = $_GET['id'] ?? '';
        if (!$id) {
            jsonResponse(['success' => false, 'error' => 'Repository ID required'], 400);
        }
        
        $result = $sourcesManager->syncRepository($id);

        if (!empty($result['success'])) {
            $repository = $sourcesManager->getRepository($id);
            if ($repository && in_array($repository['type'] ?? '', [SourcesManager::TYPE_PLAYWRIGHT, SourcesManager::TYPE_MIXED], true)) {
                $projectManager = new PlaywrightProjectManager();
                $importResult = $projectManager->syncRepositorySources($id);
                $result['importedProjects'] = $importResult['projects'] ?? [];
            }
        }
        
        $status = $result['success'] ? 'Success' : 'Failed';
        $discovered = $result['discovered'] ?? [];
        $details = [];
        
        if (!empty($discovered['apps'])) {
            $details[] = count($discovered['apps']) . ' apps';
        }
        if (!empty($discovered['templates'])) {
            $details[] = count($discovered['templates']) . ' templates';
        }
        if (!empty($discovered['tests'])) {
            $details[] = count($discovered['tests']) . ' test projects';
        }
        if (!empty($result['importedProjects'])) {
            $details[] = count($result['importedProjects']) . ' imported Playwright projects';
        }
        
        $auth->auditLog(
            $user['id'],
            $user['username'],
            'source.repository_sync',
            'repository',
            $id,
            "Synced repository: {$status}" . (!empty($details) ? ' - Found: ' . implode(', ', $details) : '')
        );
        
        jsonResponse($result);
        break;
        
    case 'discover-content':
        // Discover content in a synced repository
        requireAdmin();
        
        $id = $_GET['id'] ?? '';
        if (!$id) {
            jsonResponse(['success' => false, 'error' => 'Repository ID required'], 400);
        }
        
        $content = $sourcesManager->discoverRepositoryContent($id);
        jsonResponse(['success' => true, 'content' => $content]);
        break;
    
    // =====================
    // STATS
    // =====================

    case 'stats':
        // Get source statistics
        requireAdmin();
        
        $stats = $sourcesManager->getStats();
        jsonResponse(['success' => true, 'stats' => $stats]);
        break;
        
    case 'types':
        // Get available repository types
        jsonResponse([
            'success' => true,
            'types' => [
                ['id' => 'apps', 'name' => 'Apps', 'icon' => 'fa-puzzle-piece', 'description' => 'Doki applications'],
                ['id' => 'templates', 'name' => 'Templates', 'icon' => 'fa-cubes', 'description' => 'Command templates'],
                ['id' => 'playwright-tests', 'name' => 'Playwright Tests', 'icon' => 'fa-vial', 'description' => 'Playwright test projects'],
                ['id' => 'mixed', 'name' => 'Mixed', 'icon' => 'fa-layer-group', 'description' => 'Multiple content types']
            ]
        ]);
        break;
        
    case 'provider-types':
        // Get available provider types
        jsonResponse([
            'success' => true,
            'types' => [
                ['id' => 'github', 'name' => 'GitHub', 'icon' => 'fa-brands fa-github', 'apiUrl' => 'https://api.github.com'],
                ['id' => 'gitlab', 'name' => 'GitLab', 'icon' => 'fa-brands fa-gitlab', 'apiUrl' => 'https://gitlab.com'],
                ['id' => 'bitbucket', 'name' => 'Bitbucket', 'icon' => 'fa-brands fa-bitbucket', 'apiUrl' => 'https://api.bitbucket.org'],
                ['id' => 'custom', 'name' => 'Custom', 'icon' => 'fa-code-branch', 'apiUrl' => '']
            ]
        ]);
        break;
    
    default:
        jsonResponse(['success' => false, 'error' => 'Unknown action'], 400);
}
