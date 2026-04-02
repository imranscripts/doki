<?php
/**
 * StudioReleaseManager.php - Immutable App Studio release snapshots
 *
 * Creates packageable snapshots from editable Studio workspaces and exposes
 * published releases as a local marketplace source for the existing app flow.
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/WorkspaceManager.php';

class StudioReleaseManager {
    public const MARKETPLACE_SOURCE_ID = 'studio-local';
    public const MARKETPLACE_SOURCE_NAME = 'App Studio';
    private const RELEASES_DIR = __DIR__ . '/../data/studio-marketplace/releases';

    private PDO $db;
    private WorkspaceManager $workspaceManager;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->workspaceManager = new WorkspaceManager();
        $this->ensureReleaseRoot();
    }

    /**
     * Create an immutable release snapshot from the active app inside a workspace.
     */
    public function createReleaseFromWorkspace(string $workspaceId, string $userId, string $username, array $options = []): array {
        $workspace = $this->workspaceManager->getWorkspace($workspaceId);
        if ($workspace === null) {
            return ['success' => false, 'error' => 'Workspace not found'];
        }

        $validation = $this->workspaceManager->validateWorkspace($workspaceId);
        if (empty($validation['success'])) {
            return [
                'success' => false,
                'error' => 'Workspace validation failed',
                'validation' => $validation,
            ];
        }

        $appDir = $workspace['appPathResolved'] ?? null;
        if (!is_string($appDir) || !is_dir($appDir)) {
            return ['success' => false, 'error' => 'The active workspace app path could not be resolved'];
        }

        $manifestPath = $appDir . '/manifest.yaml';
        $manifest = $this->loadManifest($manifestPath);
        if ($manifest === []) {
            return ['success' => false, 'error' => 'manifest.yaml could not be read for this workspace app'];
        }

        $appId = trim((string)($manifest['id'] ?? $workspace['app_id'] ?? ''));
        if ($appId === '') {
            return ['success' => false, 'error' => 'A valid app ID is required to create a release'];
        }

        $appPath = trim((string)($workspace['app_path'] ?? '.'));
        $version = trim((string)($options['version'] ?? $manifest['version'] ?? '1.0.0'));
        if ($version === '') {
            $version = '1.0.0';
        }

        $releaseNotes = trim((string)($options['releaseNotes'] ?? ''));
        $releaseId = $this->generateReleaseId($appId);
        $releaseRoot = self::RELEASES_DIR . '/' . $releaseId;
        $artifactPath = $releaseRoot . '/app';

        try {
            $this->ensureDirectory($releaseRoot);
            if (!$this->copyDirectory($appDir, $artifactPath)) {
                $this->deleteDirectory($releaseRoot);
                return ['success' => false, 'error' => 'Failed to package workspace files into a release'];
            }

            $metadataPayload = [
                'id' => $releaseId,
                'workspaceId' => $workspaceId,
                'appId' => $appId,
                'appPath' => $appPath,
                'version' => $version,
                'createdAt' => date('c'),
                'createdBy' => $username,
                'manifest' => $manifest,
            ];
            @file_put_contents(
                $releaseRoot . '/release.json',
                json_encode($metadataPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );

            $stmt = $this->db->prepare("
                INSERT INTO studio_marketplace_releases (
                    id, workspace_id, app_id, app_path, version, status,
                    artifact_path, manifest_json, release_notes, created_by, updated_by
                ) VALUES (?, ?, ?, ?, ?, 'draft', ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $releaseId,
                $workspaceId,
                $appId,
                $appPath,
                $version,
                $artifactPath,
                json_encode($manifest, JSON_UNESCAPED_SLASHES),
                $releaseNotes !== '' ? $releaseNotes : null,
                $userId,
                $userId,
            ]);

            $release = $this->getRelease($releaseId);
            if ($release === null) {
                throw new RuntimeException('Release record could not be loaded after creation');
            }

            return [
                'success' => true,
                'release' => $release,
                'validation' => $validation,
            ];
        } catch (Throwable $e) {
            $this->deleteDirectory($releaseRoot);
            $this->db->prepare('DELETE FROM studio_marketplace_releases WHERE id = ?')->execute([$releaseId]);

            return [
                'success' => false,
                'error' => 'Failed to create Studio release: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Create and publish a new release from a workspace.
     */
    public function publishWorkspaceRelease(string $workspaceId, string $userId, string $username, array $options = []): array {
        $created = $this->createReleaseFromWorkspace($workspaceId, $userId, $username, $options);
        if (empty($created['success'])) {
            return $created;
        }

        $releaseId = (string)($created['release']['id'] ?? '');
        if ($releaseId === '') {
            return ['success' => false, 'error' => 'The created release did not include an ID'];
        }

        $published = $this->publishRelease($releaseId, $userId, $username);
        if (empty($published['success'])) {
            return $published;
        }

        $published['validation'] = $created['validation'] ?? null;
        return $published;
    }

    /**
     * Publish an existing release to the local marketplace.
     */
    public function publishRelease(string $releaseId, string $userId, string $username): array {
        $release = $this->getRelease($releaseId);
        if ($release === null) {
            return ['success' => false, 'error' => 'Release not found'];
        }

        $stmt = $this->db->prepare("
            UPDATE studio_marketplace_releases
            SET status = 'published',
                published_at = datetime('now'),
                published_by = ?,
                updated_at = datetime('now'),
                updated_by = ?
            WHERE id = ?
        ");
        $stmt->execute([$userId, $userId, $releaseId]);

        $updated = $this->getRelease($releaseId);
        return [
            'success' => true,
            'release' => $updated,
        ];
    }

    /**
     * Return all releases for a workspace, newest first.
     */
    public function listWorkspaceReleases(string $workspaceId): array {
        $stmt = $this->db->prepare("
            SELECT *
            FROM studio_marketplace_releases
            WHERE workspace_id = ?
            ORDER BY datetime(created_at) DESC, id DESC
        ");
        $stmt->execute([$workspaceId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn(array $row): array => $this->normalizeReleaseRow($row), $rows);
    }

    /**
     * Return a single release by ID.
     */
    public function getRelease(string $releaseId): ?array {
        $stmt = $this->db->prepare("SELECT * FROM studio_marketplace_releases WHERE id = ?");
        $stmt->execute([$releaseId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        return $this->normalizeReleaseRow($row);
    }

    /**
     * Return the latest published release for an app ID.
     */
    public function getLatestPublishedReleaseForApp(string $appId): ?array {
        $stmt = $this->db->prepare("
            SELECT *
            FROM studio_marketplace_releases
            WHERE app_id = ?
              AND status = 'published'
            ORDER BY datetime(COALESCE(published_at, created_at)) DESC, id DESC
            LIMIT 1
        ");
        $stmt->execute([$appId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        return $this->normalizeReleaseRow($row);
    }

    /**
     * Return the latest published release for each app ID as marketplace entries.
     */
    public function listPublishedMarketplaceApps(): array {
        $stmt = $this->db->query("
            SELECT *
            FROM studio_marketplace_releases
            WHERE status = 'published'
            ORDER BY app_id ASC, datetime(COALESCE(published_at, created_at)) DESC, id DESC
        ");

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $latestByApp = [];
        foreach ($rows as $row) {
            $appId = trim((string)($row['app_id'] ?? ''));
            if ($appId === '' || isset($latestByApp[$appId])) {
                continue;
            }
            $latestByApp[$appId] = $this->normalizeReleaseRow($row);
        }

        return array_values($latestByApp);
    }

    /**
     * Link a workspace to an installed app for future Studio affordances.
     */
    public function setWorkspaceLinkedInstalledApp(string $workspaceId, string $appId, string $userId): void {
        $stmt = $this->db->prepare("
            UPDATE app_workspaces
            SET linked_installed_app_id = ?,
                updated_at = datetime('now'),
                updated_by = ?
            WHERE id = ?
        ");
        $stmt->execute([$appId, $userId, $workspaceId]);
    }

    /**
     * Clear the installed-app link for a workspace.
     */
    public function clearWorkspaceLinkedInstalledApp(string $workspaceId, string $userId): void {
        $stmt = $this->db->prepare("
            UPDATE app_workspaces
            SET linked_installed_app_id = NULL,
                updated_at = datetime('now'),
                updated_by = ?
            WHERE id = ?
        ");
        $stmt->execute([$userId, $workspaceId]);
    }

    /**
     * Ensure the releases root exists.
     */
    private function ensureReleaseRoot(): void {
        $this->ensureDirectory(self::RELEASES_DIR);
    }

    /**
     * Normalize a DB release row for API/UI usage.
     */
    private function normalizeReleaseRow(array $row): array {
        $manifest = [];
        if (is_string($row['manifest_json'] ?? null) && trim((string)$row['manifest_json']) !== '') {
            $decoded = json_decode((string)$row['manifest_json'], true);
            if (is_array($decoded)) {
                $manifest = $decoded;
            }
        }

        $row['manifest'] = $manifest;
        $row['artifactPath'] = $row['artifact_path'] ?? null;
        $row['sourceId'] = self::MARKETPLACE_SOURCE_ID;
        $row['sourceName'] = self::MARKETPLACE_SOURCE_NAME;
        $row['published'] = ($row['status'] ?? '') === 'published';

        return $row;
    }

    /**
     * Read a manifest from disk, using a small fallback parser when YAML is unavailable.
     */
    private function loadManifest(string $manifestPath): array {
        if (!file_exists($manifestPath)) {
            return [];
        }

        if (function_exists('yaml_parse_file')) {
            $manifest = yaml_parse_file($manifestPath);
            if (is_array($manifest)) {
                return $manifest;
            }
        }

        $content = @file_get_contents($manifestPath);
        if (!is_string($content) || trim($content) === '') {
            return [];
        }

        $manifest = [];
        $lines = preg_split('/\R/', $content) ?: [];
        $currentListKey = null;

        foreach ($lines as $line) {
            if (preg_match('/^([A-Za-z0-9_]+):\s*(.*)$/', $line, $matches) === 1) {
                $key = trim((string)$matches[1]);
                $value = trim((string)$matches[2]);
                $currentListKey = null;

                if ($value === '') {
                    if (in_array($key, ['capabilities', 'tags'], true)) {
                        $manifest[$key] = [];
                        $currentListKey = $key;
                    }
                    continue;
                }

                $normalized = trim($value, " \t\n\r\0\x0B'\"");
                if ($normalized === 'true') {
                    $manifest[$key] = true;
                } elseif ($normalized === 'false') {
                    $manifest[$key] = false;
                } else {
                    $manifest[$key] = $normalized;
                }
                continue;
            }

            if ($currentListKey !== null && preg_match('/^\s*-\s*(.+)\s*$/', $line, $matches) === 1) {
                $manifest[$currentListKey][] = trim((string)$matches[1], " \t\n\r\0\x0B'\"");
                continue;
            }

            if (trim($line) !== '' && preg_match('/^\S/', $line) === 1) {
                $currentListKey = null;
            }
        }

        return $manifest;
    }

    /**
     * Generate a stable-enough release ID with timestamp ordering.
     */
    private function generateReleaseId(string $appId): string {
        $seed = strtolower(trim($appId));
        $seed = preg_replace('/[^a-z0-9]+/', '-', $seed) ?? 'studio-app';
        $seed = trim($seed, '-');
        if ($seed === '') {
            $seed = 'studio-app';
        }

        return $seed . '-' . gmdate('YmdHis') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
    }

    /**
     * Recursively copy one directory to another.
     */
    private function copyDirectory(string $src, string $dst): bool {
        if (!is_dir($src)) {
            return false;
        }

        $this->ensureDirectory($dst);
        $items = scandir($src);
        if (!is_array($items)) {
            return false;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $srcPath = $src . '/' . $item;
            $dstPath = $dst . '/' . $item;

            if (is_dir($srcPath)) {
                if (!$this->copyDirectory($srcPath, $dstPath)) {
                    return false;
                }
                continue;
            }

            $this->ensureDirectory(dirname($dstPath));
            if (@copy($srcPath, $dstPath) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Recursively delete a directory tree.
     */
    private function deleteDirectory(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if (!is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }

    /**
     * Ensure a directory exists.
     */
    private function ensureDirectory(string $path): void {
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }
}
