<?php
/**
 * WorkspaceManager.php - App Studio workspace management
 *
 * Manages writable app workspaces stored under app/data/workspaces/.
 * This is the first building block for App Studio authoring flows.
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/SourcesManager.php';
require_once __DIR__ . '/AppCapabilities.php';
require_once __DIR__ . '/AppManager.php';

class WorkspaceManager {
    private const WORKSPACES_DIR = __DIR__ . '/../data/workspaces';
    private const PREVIEW_DIR = __DIR__ . '/../studio-previews';
    private const STATE_DIR_NAME = 'state';
    private const REPO_DIR_NAME = 'repo';
    private const SKIP_SCAN_DIRS = ['.git', 'node_modules', '.cache', 'test-results', 'playwright-report'];

    private PDO $db;
    private ?array $installedApps = null;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->ensureWorkspaceRoot();
        $this->ensurePreviewRoot();
    }

    /**
     * List all workspaces.
     */
    public function getWorkspaces(): array {
        $stmt = $this->db->query("
            SELECT w.*, gp.name AS provider_name, r.name AS repository_name
            FROM app_workspaces w
            LEFT JOIN git_providers gp ON gp.id = w.provider_id
            LEFT JOIN repositories r ON r.id = w.repository_id
            ORDER BY datetime(w.updated_at) DESC, datetime(w.created_at) DESC
        ");

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn(array $row) => $this->normalizeWorkspaceRow($row), $rows);
    }

    /**
     * Get a single workspace by ID.
     */
    public function getWorkspace(string $id): ?array {
        $stmt = $this->db->prepare("
            SELECT w.*, gp.name AS provider_name, r.name AS repository_name
            FROM app_workspaces w
            LEFT JOIN git_providers gp ON gp.id = w.provider_id
            LEFT JOIN repositories r ON r.id = w.repository_id
            WHERE w.id = ?
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        return $this->normalizeWorkspaceRow($row);
    }

    /**
     * Create a starter workspace with a minimal valid Doki app skeleton.
     */
    public function createStarterWorkspace(array $data, string $userId, string $username): array {
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            return ['success' => false, 'error' => 'Workspace name is required'];
        }

        $workspaceId = $this->generateWorkspaceId((string)($data['workspaceId'] ?? $name));
        $appId = $this->normalizeAppId((string)($data['appId'] ?? $name));
        if ($appId === '') {
            return ['success' => false, 'error' => 'A valid app ID is required'];
        }

        $appName = trim((string)($data['appName'] ?? $name));
        $description = trim((string)($data['description'] ?? 'Starter app created from App Studio'));
        $icon = $this->sanitizeIcon((string)($data['icon'] ?? 'fa-sparkles'));
        $color = $this->sanitizeColor((string)($data['color'] ?? '#2563eb'));
        $appPath = $this->normalizeWorkspaceRelativePath((string)($data['appPath'] ?? ('apps/' . $appId)));
        if ($appPath === null) {
            return ['success' => false, 'error' => 'Invalid app path'];
        }

        $workspaceRoot = $this->workspaceRoot($workspaceId);
        $repoRoot = $this->workspaceRepoRoot($workspaceId);
        $stateRoot = $this->workspaceStateRoot($workspaceId);
        $appRoot = $repoRoot . '/' . $appPath;

        if (is_dir($workspaceRoot)) {
            return ['success' => false, 'error' => 'Workspace already exists'];
        }

        try {
            $this->ensureDirectory($repoRoot);
            $this->ensureDirectory($stateRoot);
            $this->ensureDirectory($appRoot);

            $this->writeFile($repoRoot . '/doki.project.yaml', $this->buildProjectConfigYaml($appId, $appPath));
            $this->writeFile($repoRoot . '/README.md', $this->buildStarterReadme($name, $appId, $appPath));
            $this->writeFile($appRoot . '/manifest.yaml', $this->buildStarterManifest($appId, $appName, $description, $icon, $color));
            $this->writeFile($appRoot . '/index.php', $this->buildStarterIndexPhp($appId, $appName, $description));

            $stmt = $this->db->prepare("
                INSERT INTO app_workspaces (
                    id, name, description, source_type, app_id, app_path,
                    workspace_root, preview_mode, preview_status, trust_mode,
                    created_by, updated_by
                ) VALUES (?, ?, ?, 'starter', ?, ?, ?, 'private', 'idle', 'sandboxed', ?, ?)
            ");
            $stmt->execute([
                $workspaceId,
                $name,
                $description,
                $appId,
                $appPath,
                $workspaceRoot,
                $userId,
                $userId,
            ]);

            $workspace = $this->getWorkspace($workspaceId);
            if ($workspace === null) {
                throw new RuntimeException('Workspace record was not created');
            }

            $this->writeWorkspaceSnapshot($workspace, $username);

            return [
                'success' => true,
                'workspace' => $workspace,
            ];
        } catch (Throwable $e) {
            $this->deleteDirectory($workspaceRoot);
            $this->db->prepare("DELETE FROM app_workspaces WHERE id = ?")->execute([$workspaceId]);

            return [
                'success' => false,
                'error' => 'Failed to create starter workspace: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Create a repository-backed workspace and clone the repo into it.
     */
    public function importRepositoryWorkspace(array $data, string $userId, string $username): array {
        $sourcesManager = new SourcesManager();

        $repoContext = $this->resolveRepositoryContext($data, $sourcesManager);
        if (!$repoContext['success']) {
            return $repoContext;
        }

        $workspaceName = trim((string)($data['name'] ?? $repoContext['name']));
        if ($workspaceName === '') {
            return ['success' => false, 'error' => 'Workspace name is required'];
        }

        $workspaceId = $this->generateWorkspaceId((string)($data['workspaceId'] ?? $workspaceName));
        $workspaceRoot = $this->workspaceRoot($workspaceId);
        $repoRoot = $this->workspaceRepoRoot($workspaceId);
        $stateRoot = $this->workspaceStateRoot($workspaceId);

        if (is_dir($workspaceRoot)) {
            return ['success' => false, 'error' => 'Workspace already exists'];
        }

        try {
            $this->ensureDirectory($repoRoot);
            $this->ensureDirectory($stateRoot);

            $cloneResult = $this->cloneRepository(
                $repoContext['repoUrl'],
                $repoContext['providerId'],
                $repoContext['branch'],
                $repoRoot,
                $sourcesManager
            );
            if (!$cloneResult['success']) {
                throw new RuntimeException($cloneResult['error']);
            }

            $projectConfig = $this->loadProjectConfig($repoRoot);
            $discoveredApps = $this->discoverWorkspaceApps($repoRoot, $sourcesManager);
            $appPath = $this->resolvePreferredWorkspaceAppPath(
                $repoRoot,
                $projectConfig,
                $discoveredApps,
                trim((string)($data['appPath'] ?? ''))
            );
            $selectedApp = $this->findDiscoveredAppByPath($discoveredApps, $appPath);
            $playwrightPath = $this->detectPlaywrightPath($repoRoot, $projectConfig);
            $appId = $this->detectAppId($repoRoot, $appPath, $projectConfig, $selectedApp);
            $currentBranch = $this->getCurrentGitBranch($repoRoot);

            $stmt = $this->db->prepare("
                INSERT INTO app_workspaces (
                    id, name, description, source_type, provider_id, repository_id,
                    repo_url, default_branch, current_branch, app_id, app_path,
                    playwright_path, workspace_root, preview_mode, preview_status,
                    trust_mode, last_sync_at, created_by, updated_by
                ) VALUES (?, ?, ?, 'repository', ?, ?, ?, ?, ?, ?, ?, ?, ?, 'private', 'idle', 'sandboxed', datetime('now'), ?, ?)
            ");
            $stmt->execute([
                $workspaceId,
                $workspaceName,
                trim((string)($data['description'] ?? $repoContext['description'] ?? '')),
                $repoContext['providerId'],
                $repoContext['repositoryId'],
                $repoContext['repoUrl'],
                $repoContext['branch'],
                $currentBranch,
                $appId,
                $appPath,
                $playwrightPath,
                $workspaceRoot,
                $userId,
                $userId,
            ]);

            $workspace = $this->getWorkspace($workspaceId);
            if ($workspace === null) {
                throw new RuntimeException('Workspace record was not created');
            }

            $this->writeWorkspaceSnapshot($workspace, $username);

            return [
                'success' => true,
                'workspace' => $workspace,
            ];
        } catch (Throwable $e) {
            $this->deleteDirectory($workspaceRoot);
            $this->db->prepare("DELETE FROM app_workspaces WHERE id = ?")->execute([$workspaceId]);

            return [
                'success' => false,
                'error' => 'Failed to import repository workspace: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Delete a workspace and its files.
     */
    public function deleteWorkspace(string $id): array {
        $workspace = $this->getWorkspace($id);
        if ($workspace === null) {
            return ['success' => false, 'error' => 'Workspace not found'];
        }

        $stmt = $this->db->prepare("DELETE FROM app_workspaces WHERE id = ?");
        $stmt->execute([$id]);

        $this->deleteDirectory($workspace['workspace_root']);
        $this->deleteDirectory($this->previewRoot($id));

        return ['success' => true];
    }

    /**
     * Change the active app for a multi-app workspace.
     */
    public function setWorkspaceApp(string $id, string $appPath, string $userId): array {
        $workspace = $this->getWorkspace($id);
        if ($workspace === null) {
            return ['success' => false, 'error' => 'Workspace not found'];
        }

        $normalizedPath = $this->normalizeWorkspaceRelativePath($appPath);
        if ($normalizedPath === null) {
            return ['success' => false, 'error' => 'Invalid app path'];
        }

        $projectConfig = $this->loadProjectConfig($workspace['repoPath']);
        $discoveredApps = $workspace['discoveredApps'] ?? $this->discoverWorkspaceApps($workspace['repoPath']);
        $selectedApp = $this->findDiscoveredAppByPath($discoveredApps, $normalizedPath);

        if (!$this->workspaceAppManifestExists($workspace['repoPath'], $normalizedPath)) {
            return ['success' => false, 'error' => 'manifest.yaml was not found for the selected app path'];
        }

        $appId = $this->detectAppId($workspace['repoPath'], $normalizedPath, $projectConfig, $selectedApp);
        $stmt = $this->db->prepare("
            UPDATE app_workspaces
            SET app_path = ?,
                app_id = ?,
                preview_status = 'idle',
                last_preview_at = NULL,
                updated_at = datetime('now'),
                updated_by = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $normalizedPath,
            $appId,
            $userId,
            $id,
        ]);

        $this->deleteDirectory($this->previewRoot($id));

        $updatedWorkspace = $this->getWorkspace($id);
        if ($updatedWorkspace === null) {
            return ['success' => false, 'error' => 'Workspace update failed'];
        }

        return [
            'success' => true,
            'workspace' => $updatedWorkspace,
        ];
    }

    /**
     * Refresh stored workspace metadata from the currently selected app manifest.
     */
    public function syncWorkspaceMetadataFromManifest(string $id, string $userId, ?string $username = null): array {
        $workspace = $this->getWorkspace($id);
        if ($workspace === null) {
            return ['success' => false, 'error' => 'Workspace not found'];
        }

        $projectConfig = $this->loadProjectConfig($workspace['repoPath']);
        $discoveredApps = $workspace['discoveredApps'] ?? $this->discoverWorkspaceApps($workspace['repoPath']);
        $appPath = $this->resolvePreferredWorkspaceAppPath(
            $workspace['repoPath'],
            $projectConfig,
            $discoveredApps,
            $workspace['app_path'] ?? null
        );
        if ($appPath === null) {
            return ['success' => false, 'error' => 'No Doki app path could be detected for this workspace'];
        }

        $selectedApp = $this->findDiscoveredAppByPath($discoveredApps, $appPath)
            ?? $this->summarizeWorkspaceAppFromManifest($workspace['repoPath'], $appPath);
        $appId = $this->detectAppId($workspace['repoPath'], $appPath, $projectConfig, $selectedApp);
        $name = trim((string)($selectedApp['name'] ?? $workspace['name'] ?? ''));
        $description = trim((string)($selectedApp['description'] ?? ''));
        if ($description === '') {
            $description = trim((string)($workspace['description'] ?? ''));
        }

        $stmt = $this->db->prepare("
            UPDATE app_workspaces
            SET name = ?,
                description = ?,
                app_path = ?,
                app_id = ?,
                preview_status = 'idle',
                last_preview_at = NULL,
                updated_at = datetime('now'),
                updated_by = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $name !== '' ? $name : ($workspace['name'] ?? $id),
            $description,
            $appPath,
            $appId,
            $userId,
            $id,
        ]);

        $this->deleteDirectory($this->previewRoot($id));

        $updatedWorkspace = $this->getWorkspace($id);
        if ($updatedWorkspace === null) {
            return ['success' => false, 'error' => 'Workspace metadata refresh failed'];
        }

        if ($username !== null) {
            $this->writeWorkspaceSnapshot($updatedWorkspace, $username);
        }

        return [
            'success' => true,
            'workspace' => $updatedWorkspace,
        ];
    }

    /**
     * Sync a workspace app into the preview directory and return its URL.
     */
    public function syncPreview(string $workspaceId, string $page = 'index.php'): array {
        $workspace = $this->getWorkspace($workspaceId);
        if ($workspace === null) {
            return ['success' => false, 'error' => 'Workspace not found'];
        }

        $appDir = $this->resolveWorkspaceAppDir($workspace);
        if ($appDir === null) {
            $this->updatePreviewStatus($workspaceId, 'missing_app');
            return ['success' => false, 'error' => 'No Doki app path could be detected for this workspace'];
        }

        $manifestPath = $appDir . '/manifest.yaml';
        if (!file_exists($manifestPath)) {
            $this->updatePreviewStatus($workspaceId, 'missing_manifest');
            return ['success' => false, 'error' => 'The workspace app path does not contain manifest.yaml'];
        }

        if ($this->manifestNeedsCustomRuntime($manifestPath)) {
            $this->deleteDirectory($this->previewRoot($workspaceId));
            $this->updatePreviewStatus($workspaceId, 'needs_runtime');
            return [
                'success' => false,
                'error' => 'Workspace preview for custom-runtime apps is not available yet',
                'previewStatus' => 'needs_runtime',
            ];
        }

        $previewRoot = $this->previewRoot($workspaceId);
        $this->deleteDirectory($previewRoot);
        $this->copyDirectory($appDir, $previewRoot);

        $requestedPage = $this->normalizePreviewPage($page);
        $resolvedPagePath = $previewRoot . '/' . $requestedPage;
        if (!file_exists($resolvedPagePath)) {
            $requestedPage = 'index.php';
        }

        $this->updatePreviewStatus($workspaceId, 'ready', true);

        return [
            'success' => true,
            'previewStatus' => 'ready',
            'url' => $this->previewUrl($workspaceId, $requestedPage),
            'page' => $requestedPage,
            'root' => $previewRoot,
        ];
    }

    /**
     * List workspace files under the repo root.
     */
    public function listFiles(string $workspaceId, string $relativePath = ''): array {
        $workspace = $this->getWorkspace($workspaceId);
        if ($workspace === null) {
            return ['success' => false, 'error' => 'Workspace not found'];
        }

        $basePath = $workspace['repoPath'];
        $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
        $targetPath = $relativePath === '' ? $basePath : $basePath . '/' . $relativePath;

        $realBase = realpath($basePath);
        $realTarget = realpath($targetPath);

        if ($realBase === false || $realTarget === false || strpos($realTarget, $realBase) !== 0) {
            return ['success' => false, 'error' => 'Invalid directory path'];
        }

        if (!is_dir($realTarget)) {
            return ['success' => false, 'error' => 'Directory not found'];
        }

        return [
            'success' => true,
            'files' => $this->scanDirectory($realTarget, $realBase),
        ];
    }

    /**
     * Get file content from a workspace repo.
     */
    public function getFileContent(string $workspaceId, string $filePath): ?array {
        $workspace = $this->getWorkspace($workspaceId);
        if ($workspace === null) {
            return null;
        }

        $resolved = $this->resolveWorkspaceFilePath($workspace['repoPath'], $filePath, false);
        if ($resolved === null || !is_file($resolved)) {
            return null;
        }

        $extension = strtolower((string)pathinfo($resolved, PATHINFO_EXTENSION));
        $isBinary = $this->isBinaryExtension($extension);

        return [
            'path' => trim(str_replace('\\', '/', $filePath), '/'),
            'name' => basename($resolved),
            'extension' => $extension,
            'size' => filesize($resolved),
            'isBinary' => $isBinary,
            'content' => $isBinary ? null : file_get_contents($resolved),
            'modifiedAt' => date('c', filemtime($resolved)),
        ];
    }

    /**
     * Save file content into a workspace repo.
     */
    public function saveFileContent(string $workspaceId, string $filePath, string $content, string $userId): array {
        $workspace = $this->getWorkspace($workspaceId);
        if ($workspace === null) {
            return ['success' => false, 'error' => 'Workspace not found'];
        }

        $resolved = $this->resolveWorkspaceFilePath($workspace['repoPath'], $filePath, true);
        if ($resolved === null) {
            return ['success' => false, 'error' => 'Invalid file path'];
        }

        $this->ensureDirectory(dirname($resolved));
        if (file_put_contents($resolved, $content) === false) {
            return ['success' => false, 'error' => 'Failed to write file'];
        }

        $this->touchWorkspace($workspaceId, $userId);
        $this->refreshPreviewIfPresent($workspaceId);
        return ['success' => true];
    }

    /**
     * Create a new file in a workspace repo.
     */
    public function createFile(string $workspaceId, string $filePath, string $content, string $userId): array {
        $workspace = $this->getWorkspace($workspaceId);
        if ($workspace === null) {
            return ['success' => false, 'error' => 'Workspace not found'];
        }

        $resolved = $this->resolveWorkspaceFilePath($workspace['repoPath'], $filePath, true);
        if ($resolved === null) {
            return ['success' => false, 'error' => 'Invalid file path'];
        }
        if (file_exists($resolved)) {
            return ['success' => false, 'error' => 'File already exists'];
        }

        $this->ensureDirectory(dirname($resolved));
        if (file_put_contents($resolved, $content) === false) {
            return ['success' => false, 'error' => 'Failed to create file'];
        }

        $this->touchWorkspace($workspaceId, $userId);
        $this->refreshPreviewIfPresent($workspaceId);
        return ['success' => true];
    }

    /**
     * Delete a file or directory from a workspace repo.
     */
    public function deleteFile(string $workspaceId, string $filePath, string $userId): array {
        $workspace = $this->getWorkspace($workspaceId);
        if ($workspace === null) {
            return ['success' => false, 'error' => 'Workspace not found'];
        }

        $resolved = $this->resolveWorkspaceFilePath($workspace['repoPath'], $filePath, false);
        if ($resolved === null || !file_exists($resolved)) {
            return ['success' => false, 'error' => 'File not found'];
        }

        if (is_dir($resolved)) {
            $this->deleteDirectory($resolved);
        } else {
            unlink($resolved);
        }

        $this->touchWorkspace($workspaceId, $userId);
        $this->refreshPreviewIfPresent($workspaceId);
        return ['success' => true];
    }

    /**
     * Apply a reviewed batch of file operations with one workspace touch/preview refresh.
     */
    public function applyFileOperations(string $workspaceId, array $operations, string $userId): array {
        $workspace = $this->getWorkspace($workspaceId);
        if ($workspace === null) {
            return ['success' => false, 'error' => 'Workspace not found'];
        }
        if ($operations === []) {
            return ['success' => false, 'error' => 'No file operations were provided'];
        }

        $resolvedOperations = [];
        foreach ($operations as $operation) {
            if (!is_array($operation)) {
                return ['success' => false, 'error' => 'Invalid file operation payload'];
            }

            $type = trim((string)($operation['type'] ?? ''));
            if (!in_array($type, ['write', 'delete'], true)) {
                return ['success' => false, 'error' => 'Unsupported file operation: ' . $type];
            }

            $path = trim((string)($operation['path'] ?? ''));
            $resolved = $this->resolveWorkspaceFilePath($workspace['repoPath'], $path, $type === 'write');
            if ($resolved === null) {
                return ['success' => false, 'error' => 'Invalid file path in operation: ' . $path];
            }

            $resolvedOperations[] = [
                'type' => $type,
                'path' => trim(str_replace('\\', '/', $path), '/'),
                'resolvedPath' => $resolved,
                'content' => is_string($operation['content'] ?? null) ? $operation['content'] : null,
            ];
        }

        $applied = [];
        foreach ($resolvedOperations as $operation) {
            if ($operation['type'] === 'write') {
                $content = $operation['content'];
                if (!is_string($content)) {
                    return ['success' => false, 'error' => 'Write operations require file content'];
                }

                $this->ensureDirectory(dirname($operation['resolvedPath']));
                if (file_put_contents($operation['resolvedPath'], $content) === false) {
                    return ['success' => false, 'error' => 'Failed to write file: ' . $operation['path']];
                }
            } elseif (file_exists($operation['resolvedPath'])) {
                if (is_dir($operation['resolvedPath'])) {
                    $this->deleteDirectory($operation['resolvedPath']);
                } else {
                    @unlink($operation['resolvedPath']);
                }
            }

            $applied[] = [
                'type' => $operation['type'],
                'path' => $operation['path'],
            ];
        }

        $this->touchWorkspace($workspaceId, $userId);
        $this->refreshPreviewIfPresent($workspaceId);

        return [
            'success' => true,
            'operations' => $applied,
        ];
    }

    /**
     * Validate the workspace's detected app manifest and page references.
     */
    public function validateWorkspace(string $workspaceId): array {
        $workspace = $this->getWorkspace($workspaceId);
        if ($workspace === null) {
            return ['success' => false, 'error' => 'Workspace not found'];
        }

        $errors = [];
        $warnings = [];
        $projectConfig = $this->loadProjectConfig($workspace['repoPath']);
        $discoveredApps = $workspace['discoveredApps'] ?? $this->discoverWorkspaceApps($workspace['repoPath']);
        $appPath = $this->resolvePreferredWorkspaceAppPath(
            $workspace['repoPath'],
            $projectConfig,
            $discoveredApps,
            $workspace['app_path'] ?? null
        );

        if ($appPath === null) {
            return [
                'success' => false,
                'errors' => ['No Doki app path could be detected in this workspace'],
                'warnings' => [],
            ];
        }

        $appDir = $appPath === '.' ? $workspace['repoPath'] : $workspace['repoPath'] . '/' . $appPath;
        $manifestPath = $appDir . '/manifest.yaml';
        if (!file_exists($manifestPath)) {
            return [
                'success' => false,
                'errors' => ['The detected app path does not contain manifest.yaml'],
                'warnings' => [],
                'appPath' => $appPath,
            ];
        }

        $manifest = $this->loadManifestForValidation($manifestPath);
        if (!is_array($manifest)) {
            return [
                'success' => false,
                'errors' => ['manifest.yaml could not be parsed'],
                'warnings' => [],
                'appPath' => $appPath,
            ];
        }

        $requiredFields = ['id', 'name', 'description', 'icon', 'color', 'version', 'enabled', 'navigation', 'capabilities'];
        foreach ($requiredFields as $field) {
            if (!array_key_exists($field, $manifest)) {
                $errors[] = "Missing required manifest field: {$field}";
            }
        }

        if (($manifest['id'] ?? '') !== basename($appDir) && $appPath !== '.') {
            $warnings[] = 'Manifest id does not match the app directory name.';
        }

        if (!is_array($manifest['navigation'] ?? null) || empty($manifest['navigation'])) {
            $errors[] = 'navigation must contain at least one page.';
        } else {
            foreach ($manifest['navigation'] as $index => $navItem) {
                if (!is_array($navItem)) {
                    $errors[] = 'navigation entries must be objects.';
                    continue;
                }

                foreach (['id', 'label', 'icon', 'page'] as $key) {
                    if (empty($navItem[$key])) {
                        $errors[] = "navigation[{$index}] is missing {$key}.";
                    }
                }

                if (!empty($navItem['page'])) {
                    $pagePath = $appDir . '/' . ltrim((string)$navItem['page'], '/');
                    if (!file_exists($pagePath)) {
                        $errors[] = "navigation[{$index}] page does not exist: {$navItem['page']}";
                    }
                }
            }
        }

        if (!is_array($manifest['capabilities'] ?? null) || empty($manifest['capabilities'])) {
            $errors[] = 'capabilities must be a non-empty list.';
        } else {
            $validCapabilities = $this->knownCapabilities();
            foreach ($manifest['capabilities'] as $capability) {
                if (!isset($validCapabilities[$capability])) {
                    $errors[] = "Unknown capability declared: {$capability}";
                }
            }
        }

        if (!isset($manifest['tags']) || !is_array($manifest['tags'])) {
            $warnings[] = 'tags is missing or not an array.';
        }

        if (empty($manifest['releaseNotes']) && empty($manifest['releases'])) {
            $warnings[] = 'releaseNotes or releases is recommended for update UX.';
        }

        return [
            'success' => empty($errors),
            'appPath' => $appPath,
            'manifestSummary' => [
                'id' => $manifest['id'] ?? null,
                'name' => $manifest['name'] ?? null,
                'version' => $manifest['version'] ?? null,
                'capabilities' => $manifest['capabilities'] ?? [],
            ],
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Ensure base workspace directory exists.
     */
    private function ensureWorkspaceRoot(): void {
        $this->ensureDirectory(self::WORKSPACES_DIR);
    }

    /**
     * Ensure preview root exists.
     */
    private function ensurePreviewRoot(): void {
        $this->ensureDirectory(self::PREVIEW_DIR);
    }

    /**
     * Normalize workspace row with filesystem-derived metadata.
     */
    private function normalizeWorkspaceRow(array $row): array {
        $workspaceRoot = $row['workspace_root'] ?: $this->workspaceRoot($row['id']);
        $repoPath = $workspaceRoot . '/' . self::REPO_DIR_NAME;
        $statePath = $workspaceRoot . '/' . self::STATE_DIR_NAME;
        $projectConfig = $this->loadProjectConfig($repoPath);
        $discoveredApps = $this->discoverWorkspaceApps($repoPath);
        $selectedAppPath = $this->resolvePreferredWorkspaceAppPath(
            $repoPath,
            $projectConfig,
            $discoveredApps,
            $row['app_path'] ?? null
        );
        $selectedApp = $this->findDiscoveredAppByPath($discoveredApps, $selectedAppPath)
            ?? $this->summarizeWorkspaceAppFromManifest($repoPath, $selectedAppPath);
        $appId = trim((string)($row['app_id'] ?? ''));
        if ($appId === '' && is_array($selectedApp)) {
            $appId = (string)($selectedApp['id'] ?? '');
        }

        $row['workspace_root'] = $workspaceRoot;
        $row['repoPath'] = $repoPath;
        $row['statePath'] = $statePath;
        $row['providerName'] = $row['provider_name'] ?? null;
        $row['repositoryName'] = $row['repository_name'] ?? null;
        $row['exists'] = is_dir($workspaceRoot);
        $row['repoExists'] = is_dir($repoPath);
        $row['stateExists'] = is_dir($statePath);
        $row['discoveredApps'] = $discoveredApps;
        $row['discoveredAppCount'] = count($discoveredApps);
        $row['hasMultipleApps'] = $row['discoveredAppCount'] > 1;
        $row['selectedApp'] = $selectedApp;
        $row['app_path'] = $selectedAppPath;
        $row['app_id'] = $appId !== '' ? $appId : null;
        $row['linked_installed_app_id'] = $this->resolveLinkedInstalledAppId($row['id'], $row['linked_installed_app_id'] ?? null, $row['app_id']);
        $row['hasAppPath'] = is_string($selectedAppPath) && trim($selectedAppPath) !== '';
        $row['appPathResolved'] = $this->resolveAppPathForResponse($repoPath, $selectedAppPath);
        $row['hasManifest'] = $row['appPathResolved'] !== null && file_exists($row['appPathResolved'] . '/manifest.yaml');
        $row['previewRoot'] = $this->previewRoot($row['id']);
        $row['previewExists'] = is_dir($row['previewRoot']);
        $row['previewUrl'] = $row['previewExists'] ? $this->previewUrl($row['id']) : null;

        return $row;
    }

    /**
     * Resolve repository metadata from input.
     */
    private function resolveRepositoryContext(array $data, SourcesManager $sourcesManager): array {
        $repositoryId = trim((string)($data['repositoryId'] ?? ''));
        if ($repositoryId !== '') {
            $repository = $sourcesManager->getRepository($repositoryId);
            if ($repository === null) {
                return ['success' => false, 'error' => 'Repository not found'];
            }

            return [
                'success' => true,
                'repositoryId' => $repository['id'],
                'providerId' => $repository['provider_id'] ?: null,
                'repoUrl' => $repository['url'],
                'branch' => trim((string)($data['branch'] ?? $repository['branch'] ?? 'main')),
                'name' => $repository['name'],
                'description' => $repository['description'] ?? '',
            ];
        }

        $repoUrl = trim((string)($data['repoUrl'] ?? ''));
        if ($repoUrl === '') {
            return ['success' => false, 'error' => 'repoUrl or repositoryId is required'];
        }

        $name = trim((string)($data['name'] ?? basename($repoUrl)));
        return [
            'success' => true,
            'repositoryId' => null,
            'providerId' => trim((string)($data['providerId'] ?? '')) ?: null,
            'repoUrl' => $repoUrl,
            'branch' => trim((string)($data['branch'] ?? 'main')),
            'name' => $name,
            'description' => trim((string)($data['description'] ?? '')),
        ];
    }

    /**
     * Clone a repository into a workspace repo directory.
     */
    private function cloneRepository(string $repoUrl, ?string $providerId, string $branch, string $targetPath, SourcesManager $sourcesManager): array {
        $authUrl = $sourcesManager->getAuthenticatedUrl($repoUrl, $providerId);
        $output = [];
        $exitCode = 0;

        $cmd = 'git clone ';
        if ($branch !== '') {
            $cmd .= '--branch ' . escapeshellarg($branch) . ' ';
        }
        $cmd .= escapeshellarg($authUrl) . ' ' . escapeshellarg($targetPath) . ' 2>&1';

        exec($cmd, $output, $exitCode);
        if ($exitCode !== 0) {
            return [
                'success' => false,
                'error' => implode("\n", $this->sanitizeGitOutput($output)) ?: 'git clone failed',
            ];
        }

        return ['success' => true];
    }

    /**
     * Load optional doki.project.yaml from a repo.
     */
    private function loadProjectConfig(string $repoRoot): array {
        $projectFile = $repoRoot . '/doki.project.yaml';
        if (!file_exists($projectFile) || !function_exists('yaml_parse_file')) {
            return [];
        }

        $config = yaml_parse_file($projectFile);
        return is_array($config) ? $config : [];
    }

    /**
     * Detect the app path for a repo-backed workspace.
     */
    private function detectAppPath(string $repoRoot, array $projectConfig): ?string {
        $configuredPath = trim((string)($projectConfig['app']['path'] ?? ''));
        if ($configuredPath !== '') {
            $normalized = $this->normalizeWorkspaceRelativePath($configuredPath);
            if ($normalized !== null) {
                $manifestPath = ($normalized === '.' ? $repoRoot : $repoRoot . '/' . $normalized) . '/manifest.yaml';
                if (file_exists($manifestPath)) {
                    return $normalized;
                }
            }
        }

        if (file_exists($repoRoot . '/manifest.yaml')) {
            return '.';
        }

        $candidates = [];
        foreach (glob($repoRoot . '/apps/*/manifest.yaml') ?: [] as $file) {
            $candidates[] = str_replace('\\', '/', substr(dirname($file), strlen($repoRoot) + 1));
        }
        foreach (glob($repoRoot . '/*/manifest.yaml') ?: [] as $file) {
            $relative = str_replace('\\', '/', substr(dirname($file), strlen($repoRoot) + 1));
            if ($relative !== '' && !str_contains($relative, '/')) {
                $candidates[] = $relative;
            }
        }

        $candidates = array_values(array_unique($candidates));
        return count($candidates) === 1 ? $candidates[0] : null;
    }

    /**
     * Detect the Playwright path for a repo-backed workspace.
     */
    private function detectPlaywrightPath(string $repoRoot, array $projectConfig): ?string {
        $configuredPath = trim((string)($projectConfig['tests']['playwrightPath'] ?? ''));
        if ($configuredPath !== '') {
            $normalized = $this->normalizeWorkspaceRelativePath($configuredPath);
            if ($normalized !== null) {
                $configTs = ($normalized === '.' ? $repoRoot : $repoRoot . '/' . $normalized) . '/playwright.config.ts';
                $configJs = ($normalized === '.' ? $repoRoot : $repoRoot . '/' . $normalized) . '/playwright.config.js';
                if (file_exists($configTs) || file_exists($configJs)) {
                    return $normalized;
                }
            }
        }

        $paths = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($repoRoot, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $path = $file->getPathname();
            $relative = ltrim(str_replace('\\', '/', substr($path, strlen($repoRoot))), '/');
            $segments = explode('/', $relative);
            if (count(array_intersect($segments, self::SKIP_SCAN_DIRS)) > 0) {
                continue;
            }

            $basename = basename($path);
            if ($basename !== 'playwright.config.ts' && $basename !== 'playwright.config.js') {
                continue;
            }

            $paths[] = str_replace('\\', '/', substr(dirname($path), strlen($repoRoot) + 1)) ?: '.';
        }

        $paths = array_values(array_unique($paths));
        return count($paths) === 1 ? $paths[0] : null;
    }

    /**
     * Detect the app ID for a workspace.
     */
    private function detectAppId(string $repoRoot, ?string $appPath, array $projectConfig, ?array $selectedApp = null): ?string {
        $configured = trim((string)($projectConfig['app']['id'] ?? ''));
        if ($configured !== '') {
            return $configured;
        }

        if (is_array($selectedApp) && !empty($selectedApp['id'])) {
            return (string)$selectedApp['id'];
        }

        if ($appPath === null) {
            return null;
        }

        $appDir = $appPath === '.' ? $repoRoot : $repoRoot . '/' . $appPath;
        $manifestPath = $appDir . '/manifest.yaml';
        if (file_exists($manifestPath)) {
            $manifest = $this->loadManifestMetadata($manifestPath);
            if (!empty($manifest['id'])) {
                return (string)$manifest['id'];
            }
        }

        return $appPath === '.' ? basename($repoRoot) : basename($appDir);
    }

    /**
     * Get the current git branch for a repo.
     */
    private function getCurrentGitBranch(string $repoRoot): ?string {
        if (!is_dir($repoRoot . '/.git')) {
            return null;
        }

        $output = [];
        $exitCode = 0;
        $cmd = sprintf(
            'cd %s && git rev-parse --abbrev-ref HEAD 2>&1',
            escapeshellarg($repoRoot)
        );
        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0 || empty($output)) {
            return null;
        }

        return trim((string)$output[0]) ?: null;
    }

    /**
     * Resolve the workspace app root directory.
     */
    private function resolveWorkspaceAppDir(array $workspace): ?string {
        $projectConfig = $this->loadProjectConfig($workspace['repoPath']);
        $discoveredApps = $workspace['discoveredApps'] ?? $this->discoverWorkspaceApps($workspace['repoPath']);
        $appPath = $this->resolvePreferredWorkspaceAppPath(
            $workspace['repoPath'],
            $projectConfig,
            $discoveredApps,
            $workspace['app_path'] ?? null
        );
        if ($appPath === null) {
            return null;
        }

        return $appPath === '.' ? $workspace['repoPath'] : $workspace['repoPath'] . '/' . $appPath;
    }

    /**
     * Keep workspace updated_at fresh after mutations.
     */
    private function touchWorkspace(string $workspaceId, string $userId): void {
        $stmt = $this->db->prepare("
            UPDATE app_workspaces
            SET updated_at = datetime('now'),
                updated_by = ?
            WHERE id = ?
        ");
        $stmt->execute([$userId, $workspaceId]);
    }

    /**
     * Update preview status in the workspace row.
     */
    private function updatePreviewStatus(string $workspaceId, string $status, bool $updatePreviewTime = false): void {
        $sql = "
            UPDATE app_workspaces
            SET preview_status = ?,
                updated_at = datetime('now')
        ";
        $params = [$status];

        if ($updatePreviewTime) {
            $sql .= ",
                last_preview_at = datetime('now')
            ";
        }

        $sql .= " WHERE id = ?";
        $params[] = $workspaceId;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * Refresh a preview copy if one already exists.
     */
    private function refreshPreviewIfPresent(string $workspaceId): void {
        if (!is_dir($this->previewRoot($workspaceId))) {
            return;
        }

        $this->syncPreview($workspaceId);
    }

    /**
     * Resolve a repo-relative file path safely.
     */
    private function resolveWorkspaceFilePath(string $repoRoot, string $filePath, bool $allowCreate): ?string {
        $relative = trim(str_replace('\\', '/', $filePath), '/');
        if ($relative === '' || str_contains($relative, '../')) {
            return null;
        }

        $base = realpath($repoRoot);
        if ($base === false) {
            return null;
        }

        $target = $repoRoot . '/' . $relative;
        if (!$allowCreate) {
            $realTarget = realpath($target);
            if ($realTarget === false || strpos($realTarget, $base) !== 0) {
                return null;
            }
            return $realTarget;
        }

        $parent = dirname($target);
        $this->ensureDirectory($parent);
        $realParent = realpath($parent);
        if ($realParent === false || strpos($realParent, $base) !== 0) {
            return null;
        }

        return $target;
    }

    /**
     * Normalize a requested preview page path.
     */
    private function normalizePreviewPage(string $page): string {
        $page = trim(str_replace('\\', '/', $page), '/');
        if ($page === '' || str_contains($page, '../')) {
            return 'index.php';
        }

        return $page;
    }

    /**
     * Recursively scan a directory into a flat file list.
     */
    private function scanDirectory(string $dir, string $repoRoot): array {
        $items = [];
        $entries = scandir($dir);
        if ($entries === false) {
            return [];
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || in_array($entry, self::SKIP_SCAN_DIRS, true)) {
                continue;
            }

            $fullPath = $dir . '/' . $entry;
            $relative = ltrim(str_replace('\\', '/', substr($fullPath, strlen($repoRoot))), '/');
            $isDirectory = is_dir($fullPath);
            $item = [
                'path' => $relative,
                'name' => $entry,
                'type' => $isDirectory ? 'directory' : 'file',
                'isDirectory' => $isDirectory,
                'extension' => $isDirectory ? null : strtolower((string)pathinfo($fullPath, PATHINFO_EXTENSION)),
                'modifiedAt' => date('c', filemtime($fullPath)),
            ];

            if ($isDirectory) {
                $item['children'] = $this->scanDirectory($fullPath, $repoRoot);
            } else {
                $item['size'] = filesize($fullPath);
            }

            $items[] = $item;
        }

        usort($items, function(array $a, array $b): int {
            if ($a['type'] !== $b['type']) {
                return $a['type'] === 'directory' ? -1 : 1;
            }
            return strcmp($a['name'], $b['name']);
        });

        return $items;
    }

    /**
     * Build the starter manifest.
     */
    private function buildStarterManifest(string $appId, string $appName, string $description, string $icon, string $color): string {
        return <<<YAML
# App Studio starter manifest
id: {$appId}
name: {$this->yamlQuote($appName)}
description: {$this->yamlQuote($description)}
icon: {$icon}
color: "{$color}"
version: 0.1.0
enabled: true
tags:
  - studio
  - starter
capabilities:
  - ui.layout
  - fs.app_data
  - audit.write
navigation:
  - id: home
    label: Home
    icon: fa-house
    page: index.php
releaseNotes:
  - Initial App Studio starter.
YAML;
    }

    /**
     * Build the starter index.php.
     */
    private function buildStarterIndexPhp(string $appId, string $appName, string $description): string {
        $safeName = addslashes($appName);
        $safeDescription = addslashes($description);
        $safeAppId = addslashes($appId);

        return <<<PHP
<?php
require_once __DIR__ . '/../../includes/AppContext.php';

\$app = new AppContext('{$safeAppId}');
\$user = \$app->requireAuth();
\$layout = \$app->getLayout();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php \$layout->renderHead('{$safeName}'); ?>
</head>
<body>
    <div class="app-container">
        <?php \$layout->renderSidebar(); ?>

        <main class="main-content">
            <div class="content-area">
                <?php \$app->renderAppHeader(); ?>

                <div class="card" style="max-width: 720px; margin: 24px auto;">
                    <div class="card-header">
                        <h2 style="margin: 0;">{$safeName}</h2>
                    </div>
                    <div class="card-body">
                        <p style="margin-top: 0;">{$safeDescription}</p>
                        <p>Welcome, <?= htmlspecialchars(\$user['name'] ?? \$user['username'] ?? 'there') ?>.</p>
                        <p>This starter app is ready to edit in App Studio.</p>
                    </div>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
PHP;
    }

    /**
     * Build the starter workspace README.
     */
    private function buildStarterReadme(string $workspaceName, string $appId, string $appPath): string {
        return <<<MD
# {$workspaceName}

This workspace was generated by App Studio.

- App ID: `{$appId}`
- App path: `{$appPath}`

Next steps:

1. Edit `{$appPath}/manifest.yaml`
2. Edit `{$appPath}/index.php`
3. Validate the workspace
4. Add preview and tests in the next App Studio phases
MD;
    }

    /**
     * Build a minimal doki.project.yaml.
     */
    private function buildProjectConfigYaml(string $appId, string $appPath): string {
        return <<<YAML
app:
  id: {$appId}
  path: {$appPath}
YAML;
    }

    /**
     * Write a workspace snapshot to state/workspace.yaml.
     */
    private function writeWorkspaceSnapshot(array $workspace, string $username): void {
        $snapshot = [
            'id' => $workspace['id'],
            'name' => $workspace['name'],
            'description' => $workspace['description'] ?? '',
            'sourceType' => $workspace['source_type'],
            'repoUrl' => $workspace['repo_url'] ?? '',
            'defaultBranch' => $workspace['default_branch'] ?? '',
            'currentBranch' => $workspace['current_branch'] ?? '',
            'appId' => $workspace['app_id'] ?? '',
            'appPath' => $workspace['app_path'] ?? '',
            'playwrightPath' => $workspace['playwright_path'] ?? '',
            'previewMode' => $workspace['preview_mode'],
            'previewStatus' => $workspace['preview_status'],
            'trustMode' => $workspace['trust_mode'],
            'updatedByUsername' => $username,
            'updatedAt' => $workspace['updated_at'] ?? date('c'),
        ];

        $stateFile = $this->workspaceStateRoot($workspace['id']) . '/workspace.yaml';
        $this->writeFile($stateFile, $this->yamlEmit($snapshot));
    }

    /**
     * Determine whether a manifest declares a custom runtime.
     */
    private function manifestNeedsCustomRuntime(string $manifestPath): bool {
        if (!file_exists($manifestPath)) {
            return false;
        }

        if (function_exists('yaml_parse_file')) {
            $manifest = yaml_parse_file($manifestPath);
            if (is_array($manifest)) {
                $runtime = is_array($manifest['runtime'] ?? null) ? $manifest['runtime'] : [];
                return !empty($runtime['phpExtensions']) || !empty($runtime['systemPackages']);
            }
        }

        $content = file_get_contents($manifestPath);
        if (!is_string($content) || trim($content) === '') {
            return false;
        }

        return preg_match('/^runtime:\s*$/m', $content) === 1;
    }

    /**
     * Return known app capabilities keyed by capability string.
     */
    private function knownCapabilities(): array {
        $ref = new ReflectionClass(AppCapabilities::class);
        $constants = $ref->getConstants();
        $known = [];

        foreach ($constants as $name => $value) {
            if (!is_string($value)) {
                continue;
            }
            if (str_starts_with($name, 'TRUST_')) {
                continue;
            }
            if (str_contains($value, '.')) {
                $known[$value] = true;
            }
        }

        return $known;
    }

    /**
     * Generate a unique workspace ID.
     */
    private function generateWorkspaceId(string $seed): string {
        $base = $this->slugify($seed);
        if ($base === '') {
            $base = 'workspace';
        }

        $candidate = $base;
        $suffix = 2;
        while ($this->workspaceExists($candidate)) {
            $candidate = $base . '-' . $suffix;
            $suffix++;
        }

        return $candidate;
    }

    /**
     * Check if a workspace ID already exists.
     */
    private function workspaceExists(string $id): bool {
        $stmt = $this->db->prepare("SELECT 1 FROM app_workspaces WHERE id = ?");
        $stmt->execute([$id]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Normalize app IDs to kebab-case.
     */
    private function normalizeAppId(string $value): string {
        return $this->slugify($value);
    }

    /**
     * Sanitize a relative workspace path.
     */
    private function normalizeWorkspaceRelativePath(string $path): ?string {
        $trimmed = trim(str_replace('\\', '/', $path));
        if ($trimmed === '' || $trimmed === '.') {
            return '.';
        }

        $trimmed = trim($trimmed, '/');
        if ($trimmed === '' || str_contains($trimmed, '../')) {
            return null;
        }

        return $trimmed;
    }

    /**
     * Discover Doki apps in a workspace repo using the same rules as source repos.
     */
    private function discoverWorkspaceApps(string $repoRoot, ?SourcesManager $sourcesManager = null): array {
        if (!is_dir($repoRoot)) {
            return [];
        }

        $sourcesManager ??= new SourcesManager();
        $apps = [];

        foreach ($sourcesManager->discoverAppsInDirectory($repoRoot) as $candidate) {
            $summary = $this->summarizeDiscoveredWorkspaceApp($repoRoot, $candidate);
            if ($summary !== null) {
                $apps[$summary['path']] = $summary;
            }
        }

        if (file_exists($repoRoot . '/manifest.yaml')) {
            $summary = $this->summarizeWorkspaceAppFromManifest($repoRoot, '.');
            if ($summary !== null) {
                $apps[$summary['path']] = $summary;
            }
        }

        foreach (glob($repoRoot . '/apps/*/manifest.yaml') ?: [] as $file) {
            $relativePath = str_replace('\\', '/', substr(dirname($file), strlen($repoRoot) + 1));
            if (!isset($apps[$relativePath])) {
                $summary = $this->summarizeWorkspaceAppFromManifest($repoRoot, $relativePath);
                if ($summary !== null) {
                    $apps[$summary['path']] = $summary;
                }
            }
        }

        foreach (glob($repoRoot . '/*/manifest.yaml') ?: [] as $file) {
            $relativePath = str_replace('\\', '/', substr(dirname($file), strlen($repoRoot) + 1));
            if ($relativePath === '' || str_contains($relativePath, '/') || isset($apps[$relativePath])) {
                continue;
            }

            $summary = $this->summarizeWorkspaceAppFromManifest($repoRoot, $relativePath);
            if ($summary !== null) {
                $apps[$summary['path']] = $summary;
            }
        }

        uasort($apps, function(array $a, array $b): int {
            return strcmp($a['path'], $b['path']);
        });

        return array_values($apps);
    }

    /**
     * Reduce a discovered app manifest down to workspace-relevant metadata.
     */
    private function summarizeDiscoveredWorkspaceApp(string $repoRoot, array $candidate): ?array {
        $path = trim((string)($candidate['sourcePathRelative'] ?? $candidate['path'] ?? ''), '/');
        $normalizedPath = $this->normalizeWorkspaceRelativePath($path === '' ? '.' : $path);
        if ($normalizedPath === null || !$this->workspaceAppManifestExists($repoRoot, $normalizedPath)) {
            return null;
        }

        return [
            'id' => trim((string)($candidate['id'] ?? '')) ?: ($normalizedPath === '.' ? basename($repoRoot) : basename($normalizedPath)),
            'name' => trim((string)($candidate['name'] ?? '')) ?: ucwords(str_replace(['-', '_'], ' ', basename($normalizedPath === '.' ? basename($repoRoot) : $normalizedPath))),
            'description' => trim((string)($candidate['description'] ?? '')),
            'icon' => $this->sanitizeIcon((string)($candidate['icon'] ?? 'fa-puzzle-piece')),
            'color' => $this->sanitizeColor((string)($candidate['color'] ?? '#2563eb')),
            'path' => $normalizedPath,
        ];
    }

    /**
     * Build app summary from a manifest path inside the workspace repo.
     */
    private function summarizeWorkspaceAppFromManifest(string $repoRoot, ?string $appPath): ?array {
        if (!is_string($appPath) || trim($appPath) === '') {
            return null;
        }

        $normalizedPath = $this->normalizeWorkspaceRelativePath($appPath);
        if ($normalizedPath === null || !$this->workspaceAppManifestExists($repoRoot, $normalizedPath)) {
            return null;
        }

        $appDir = $normalizedPath === '.' ? $repoRoot : $repoRoot . '/' . $normalizedPath;
        $manifestPath = $appDir . '/manifest.yaml';
        $manifest = $this->loadManifestMetadata($manifestPath);

        $fallbackId = $normalizedPath === '.' ? basename($repoRoot) : basename($appDir);
        return [
            'id' => trim((string)($manifest['id'] ?? '')) ?: $fallbackId,
            'name' => trim((string)($manifest['name'] ?? '')) ?: ucwords(str_replace(['-', '_'], ' ', $fallbackId)),
            'description' => trim((string)($manifest['description'] ?? '')),
            'icon' => $this->sanitizeIcon((string)($manifest['icon'] ?? 'fa-puzzle-piece')),
            'color' => $this->sanitizeColor((string)($manifest['color'] ?? '#2563eb')),
            'path' => $normalizedPath,
        ];
    }

    /**
     * Resolve which app path should be treated as active for the workspace.
     */
    private function resolvePreferredWorkspaceAppPath(string $repoRoot, array $projectConfig, array $discoveredApps, ?string $preferredPath = null): ?string {
        $candidates = [];

        if (is_string($preferredPath) && trim($preferredPath) !== '') {
            $normalized = $this->normalizeWorkspaceRelativePath($preferredPath);
            if ($normalized !== null) {
                $candidates[] = $normalized;
            }
        }

        $configuredPath = trim((string)($projectConfig['app']['path'] ?? ''));
        if ($configuredPath !== '') {
            $normalized = $this->normalizeWorkspaceRelativePath($configuredPath);
            if ($normalized !== null) {
                $candidates[] = $normalized;
            }
        }

        if (file_exists($repoRoot . '/manifest.yaml')) {
            $candidates[] = '.';
        }

        foreach ($discoveredApps as $app) {
            $path = $this->normalizeWorkspaceRelativePath((string)($app['path'] ?? ''));
            if ($path !== null) {
                $candidates[] = $path;
            }
        }

        $detectedPath = $this->detectAppPath($repoRoot, $projectConfig);
        if ($detectedPath !== null) {
            $candidates[] = $detectedPath;
        }

        foreach (array_values(array_unique($candidates)) as $candidate) {
            if ($this->workspaceAppManifestExists($repoRoot, $candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Return true if the candidate app path contains a manifest.
     */
    private function workspaceAppManifestExists(string $repoRoot, ?string $appPath): bool {
        if (!is_string($appPath) || trim($appPath) === '') {
            return false;
        }

        $normalizedPath = $this->normalizeWorkspaceRelativePath($appPath);
        if ($normalizedPath === null) {
            return false;
        }

        $manifestPath = ($normalizedPath === '.' ? $repoRoot : $repoRoot . '/' . $normalizedPath) . '/manifest.yaml';
        return file_exists($manifestPath);
    }

    /**
     * Load a small subset of manifest metadata, with a line-based fallback when YAML is unavailable.
     */
    private function loadManifestMetadata(string $manifestPath): array {
        if (!file_exists($manifestPath)) {
            return [];
        }

        if (function_exists('yaml_parse_file')) {
            $parsed = yaml_parse_file($manifestPath);
            if (is_array($parsed)) {
                return $parsed;
            }
        }

        $content = file_get_contents($manifestPath);
        if (!is_string($content) || trim($content) === '') {
            return [];
        }

        $manifest = [];
        foreach (['id', 'name', 'description', 'icon', 'color', 'version'] as $field) {
            if (preg_match('/^' . preg_quote($field, '/') . ':\s*(.+)\s*$/mi', $content, $matches) !== 1) {
                continue;
            }

            $value = trim((string)$matches[1]);
            $manifest[$field] = trim($value, " \t\n\r\0\x0B'\"");
        }

        return $manifest;
    }

    /**
     * Resolve the installed app linked to a workspace from the installed-app registry.
     */
    private function resolveLinkedInstalledAppId(string $workspaceId, ?string $storedAppId, ?string $workspaceAppId): ?string {
        $installedApps = $this->getInstalledApps();

        $storedAppId = is_string($storedAppId) ? trim($storedAppId) : '';
        if ($storedAppId !== '' && isset($installedApps[$storedAppId])) {
            $installed = $installedApps[$storedAppId];
            $sourceWorkspaceId = trim((string)($installed['sourceWorkspaceId'] ?? ''));
            if ($sourceWorkspaceId === '' || $sourceWorkspaceId === $workspaceId) {
                return $storedAppId;
            }
        }

        foreach ($installedApps as $installedAppId => $installed) {
            if (($installed['sourceWorkspaceId'] ?? null) === $workspaceId) {
                return $installedAppId;
            }
        }

        $workspaceAppId = is_string($workspaceAppId) ? trim($workspaceAppId) : '';
        if ($workspaceAppId !== '' && isset($installedApps[$workspaceAppId])) {
            return $workspaceAppId;
        }

        return null;
    }

    /**
     * Load installed apps from the registry.
     */
    private function getInstalledApps(): array {
        if (is_array($this->installedApps)) {
            return $this->installedApps;
        }

        $apps = AppManager::loadInstalledAppsRegistry();
        foreach ($apps as &$installed) {
            if (!is_array($installed)) {
                continue;
            }
            $installed['sourceWorkspaceId'] = trim((string)($installed['sourceWorkspaceId'] ?? ''));
        }
        unset($installed);

        $this->installedApps = $apps;
        return $this->installedApps;
    }

    /**
     * Load a manifest with a small YAML fallback parser suitable for Studio validation.
     */
    private function loadManifestForValidation(string $manifestPath): ?array {
        if (!file_exists($manifestPath)) {
            return null;
        }

        if (function_exists('yaml_parse_file')) {
            $parsed = yaml_parse_file($manifestPath);
            if (is_array($parsed)) {
                return $parsed;
            }
        }

        $content = @file_get_contents($manifestPath);
        if (!is_string($content) || trim($content) === '') {
            return null;
        }

        $manifest = [];
        $lines = preg_split('/\R/', $content) ?: [];
        $currentListKey = null;
        $currentListIndex = null;

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            if (preg_match('/^([A-Za-z0-9_]+):\s*(.*)$/', $line, $matches) === 1) {
                $key = trim((string)$matches[1]);
                $value = trim((string)$matches[2]);
                $currentListKey = null;
                $currentListIndex = null;

                if ($value === '') {
                    $manifest[$key] = [];
                    $currentListKey = $key;
                    continue;
                }

                $manifest[$key] = $this->parseYamlScalarValue($value);
                continue;
            }

            if ($currentListKey === null) {
                continue;
            }

            if (preg_match('/^\s*-\s*(.*)$/', $line, $matches) === 1) {
                $itemValue = trim((string)$matches[1]);

                if ($itemValue === '') {
                    $manifest[$currentListKey][] = [];
                    $currentListIndex = array_key_last($manifest[$currentListKey]);
                    continue;
                }

                if (preg_match('/^([A-Za-z0-9_]+):\s*(.*)$/', $itemValue, $itemMatches) === 1) {
                    $manifest[$currentListKey][] = [
                        trim((string)$itemMatches[1]) => $this->parseYamlScalarValue((string)$itemMatches[2]),
                    ];
                    $currentListIndex = array_key_last($manifest[$currentListKey]);
                    continue;
                }

                $manifest[$currentListKey][] = $this->parseYamlScalarValue($itemValue);
                $currentListIndex = null;
                continue;
            }

            if (
                $currentListIndex !== null
                && isset($manifest[$currentListKey][$currentListIndex])
                && is_array($manifest[$currentListKey][$currentListIndex])
                && preg_match('/^\s{2,}([A-Za-z0-9_]+):\s*(.*)$/', $line, $matches) === 1
            ) {
                $manifest[$currentListKey][$currentListIndex][trim((string)$matches[1])] =
                    $this->parseYamlScalarValue((string)$matches[2]);
            }
        }

        return $manifest;
    }

    /**
     * Convert a small YAML scalar into a PHP value.
     */
    private function parseYamlScalarValue(string $value): mixed {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        if (
            (str_starts_with($trimmed, '"') && str_ends_with($trimmed, '"'))
            || (str_starts_with($trimmed, "'") && str_ends_with($trimmed, "'"))
        ) {
            $trimmed = substr($trimmed, 1, -1);
        }

        $lower = strtolower($trimmed);
        if ($lower === 'true') {
            return true;
        }
        if ($lower === 'false') {
            return false;
        }
        if (is_numeric($trimmed)) {
            return str_contains($trimmed, '.') ? (float)$trimmed : (int)$trimmed;
        }

        return $trimmed;
    }

    /**
     * Find a discovered app summary by repo-relative app path.
     */
    private function findDiscoveredAppByPath(array $discoveredApps, ?string $appPath): ?array {
        if (!is_string($appPath) || trim($appPath) === '') {
            return null;
        }

        $normalizedPath = $this->normalizeWorkspaceRelativePath($appPath);
        if ($normalizedPath === null) {
            return null;
        }

        foreach ($discoveredApps as $app) {
            if (($app['path'] ?? null) === $normalizedPath) {
                return $app;
            }
        }

        return null;
    }

    /**
     * Convert user text to a filesystem-safe slug.
     */
    private function slugify(string $value): string {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        return trim($value, '-');
    }

    /**
     * Resolve app path for response payloads.
     */
    private function resolveAppPathForResponse(string $repoPath, ?string $appPath): ?string {
        if (!is_string($appPath) || trim($appPath) === '') {
            return null;
        }

        return $appPath === '.' ? $repoPath : $repoPath . '/' . $appPath;
    }

    /**
     * Return the workspace root path.
     */
    private function workspaceRoot(string $workspaceId): string {
        return self::WORKSPACES_DIR . '/' . $workspaceId;
    }

    /**
     * Return the workspace preview root path.
     */
    private function previewRoot(string $workspaceId): string {
        return self::PREVIEW_DIR . '/' . $workspaceId;
    }

    /**
     * Return the public preview URL for a workspace.
     */
    private function previewUrl(string $workspaceId, string $page = 'index.php'): string {
        return '/studio-previews/' . rawurlencode($workspaceId) . '/' . ltrim($page, '/');
    }

    /**
     * Return the workspace repo root path.
     */
    private function workspaceRepoRoot(string $workspaceId): string {
        return $this->workspaceRoot($workspaceId) . '/' . self::REPO_DIR_NAME;
    }

    /**
     * Return the workspace state root path.
     */
    private function workspaceStateRoot(string $workspaceId): string {
        return $this->workspaceRoot($workspaceId) . '/' . self::STATE_DIR_NAME;
    }

    /**
     * Ensure a directory exists.
     */
    private function ensureDirectory(string $path): void {
        if (!is_dir($path) && !mkdir($path, 0755, true) && !is_dir($path)) {
            throw new RuntimeException('Failed to create directory: ' . $path);
        }
    }

    /**
     * Write a file and ensure its parent directory exists.
     */
    private function writeFile(string $path, string $content): void {
        $this->ensureDirectory(dirname($path));
        if (file_put_contents($path, $content) === false) {
            throw new RuntimeException('Failed to write file: ' . $path);
        }
    }

    /**
     * Delete a directory recursively.
     */
    private function deleteDirectory(string $path): void {
        if (!file_exists($path)) {
            return;
        }

        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }

        $entries = scandir($path);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->deleteDirectory($path . '/' . $entry);
        }

        @rmdir($path);
    }

    /**
     * Copy a directory recursively.
     */
    private function copyDirectory(string $source, string $destination): void {
        if (!is_dir($source)) {
            throw new RuntimeException('Source directory does not exist: ' . $source);
        }

        $this->ensureDirectory($destination);
        $entries = scandir($source);
        if ($entries === false) {
            throw new RuntimeException('Failed to read directory: ' . $source);
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || in_array($entry, self::SKIP_SCAN_DIRS, true)) {
                continue;
            }

            $sourcePath = $source . '/' . $entry;
            $destinationPath = $destination . '/' . $entry;

            if (is_dir($sourcePath)) {
                $this->copyDirectory($sourcePath, $destinationPath);
                continue;
            }

            $this->ensureDirectory(dirname($destinationPath));
            if (!copy($sourcePath, $destinationPath)) {
                throw new RuntimeException('Failed to copy file: ' . $sourcePath);
            }
        }
    }

    /**
     * Mask credentials from git output.
     */
    private function sanitizeGitOutput(array $output): array {
        return array_map(function(string $line): string {
            return preg_replace('#(https://)[^@]+@#', '$1***@', $line) ?? $line;
        }, $output);
    }

    /**
     * Detect whether a file extension should be treated as binary.
     */
    private function isBinaryExtension(string $extension): bool {
        return in_array($extension, ['png', 'jpg', 'jpeg', 'gif', 'ico', 'woff', 'woff2', 'ttf', 'eot', 'pdf', 'zip'], true);
    }

    /**
     * Convert a value to a YAML-safe string fragment.
     */
    private function yamlQuote(string $value): string {
        $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
        return '"' . $escaped . '"';
    }

    /**
     * Emit a small YAML document without depending on yaml_emit.
     */
    private function yamlEmit(array $data, int $indent = 0): string {
        if (function_exists('yaml_emit')) {
            $yaml = yaml_emit($data, YAML_UTF8_ENCODING, YAML_LN_BREAK);
            if (is_string($yaml)) {
                $yaml = preg_replace('/^---\s*\n/', '', $yaml) ?? $yaml;
                return trim($yaml) . "\n";
            }
        }

        $lines = [];
        $prefix = str_repeat('  ', $indent);

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                if ($this->isSequentialArray($value)) {
                    $lines[] = $prefix . $key . ':';
                    foreach ($value as $item) {
                        if (is_array($item)) {
                            $lines[] = $prefix . '  -';
                            $lines[] = rtrim($this->yamlEmit($item, $indent + 2), "\n");
                        } else {
                            $lines[] = $prefix . '  - ' . $this->yamlScalar($item);
                        }
                    }
                } else {
                    $lines[] = $prefix . $key . ':';
                    $lines[] = rtrim($this->yamlEmit($value, $indent + 1), "\n");
                }
                continue;
            }

            $lines[] = $prefix . $key . ': ' . $this->yamlScalar($value);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Emit a YAML scalar.
     */
    private function yamlScalar($value): string {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }

        return $this->yamlQuote((string)$value);
    }

    /**
     * Detect sequential arrays.
     */
    private function isSequentialArray(array $value): bool {
        return array_keys($value) === range(0, count($value) - 1);
    }

    /**
     * Normalize an icon string to the manifest style.
     */
    private function sanitizeIcon(string $icon): string {
        $icon = trim($icon);
        if ($icon === '') {
            return 'fa-sparkles';
        }
        $icon = preg_replace('/^fas?\s+/', '', $icon) ?? $icon;
        return str_starts_with($icon, 'fa-') ? $icon : 'fa-sparkles';
    }

    /**
     * Normalize a hex color with a safe fallback.
     */
    private function sanitizeColor(string $color): string {
        $color = trim($color);
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            return strtolower($color);
        }
        return '#2563eb';
    }
}
