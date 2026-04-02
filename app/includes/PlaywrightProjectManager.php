<?php
/**
 * PlaywrightProjectManager.php - Manages Playwright test projects
 * 
 * Projects are stored in app/playwright/{project-id}/
 * Each project has:
 * - project.yaml (metadata)
 * - playwright.config.ts
 * - tests/ folder
 */

class PlaywrightProjectManager {
    private const PROJECTS_DIR = __DIR__ . '/../playwright';
    private const MANAGED_PROJECT_PREFIX = 'src-';
    private const COPY_SKIP_NAMES = ['.git', 'node_modules', '.cache', 'test-results', 'playwright-report'];
    
    /**
     * Get all projects
     */
    public function getProjects(): array {
        $projects = [];
        $dir = self::PROJECTS_DIR;

        $this->syncRepositorySources();
        
        if (!is_dir($dir)) {
            return [];
        }
        
        $folders = glob($dir . '/*', GLOB_ONLYDIR);
        
        foreach ($folders as $folder) {
            $projectId = basename($folder);
            
            // Skip system directories
            if (in_array($projectId, ['node_modules', '.cache', 'test-results', 'playwright-report'])) {
                continue;
            }
            
            $project = $this->loadProject($projectId);
            if ($project) {
                $projects[] = $project;
            }
        }
        
        return $projects;
    }
    
    /**
     * Load a single project
     */
    public function loadProject(string $projectId): ?array {
        $path = self::PROJECTS_DIR . '/' . $projectId;
        
        if (!is_dir($path)) {
            return null;
        }
        
        // Load project metadata if exists
        $metaFile = $path . '/project.yaml';
        $meta = [];
        
        if (file_exists($metaFile)) {
            $meta = $this->parseProjectMetaFile($metaFile);
        }
        
        // Count test files
        $testCount = $this->countTestFiles($path);
        $sourceRepositoryAvailable = $this->sourceRepositoryExists($meta['sourceRepositoryId'] ?? null);
        
        // Get directory stats
        $stat = stat($path);
        
        return [
            'id' => $projectId,
            'name' => $meta['name'] ?? $this->formatProjectName($projectId),
            'description' => $meta['description'] ?? '',
            'source' => $meta['source'] ?? 'local',
            'gitUrl' => $meta['gitUrl'] ?? null,
            'gitBranch' => $meta['gitBranch'] ?? 'main',
            'gitProvider' => $meta['gitProvider'] ?? null,
            'environments' => $meta['environments'] ?? [],
            'defaults' => $meta['defaults'] ?? [],
            'wrapperConfig' => $meta['wrapperConfig'] ?? $this->getDefaultWrapperConfig(),
            'sourceRepositoryId' => $meta['sourceRepositoryId'] ?? null,
            'sourceRepositoryName' => $meta['sourceRepositoryName'] ?? null,
            'sourceRepositoryPath' => $meta['sourceRepositoryPath'] ?? null,
            'sourceSyncAt' => $meta['sourceSyncAt'] ?? null,
            'sourceRepositoryAvailable' => $sourceRepositoryAvailable,
            'path' => $path,
            'testCount' => $testCount,
            'createdAt' => $meta['createdAt'] ?? date('c', $stat['ctime']),
            'updatedAt' => $meta['updatedAt'] ?? date('c', $stat['mtime'])
        ];
    }
    
    /**
     * Get default wrapper configuration
     */
    public function getDefaultWrapperConfig(): array {
        return [
            'overrideReporters' => true,
            'reporters' => [
                'html' => true,
                'list' => true,
                'json' => false,
                'junit' => false
            ],
            'overrideRecording' => true,
            'recording' => [
                'video' => 'on-first-retry',
                'screenshot' => 'only-on-failure',
                'trace' => 'on-first-retry'
            ],
            'overrideExecution' => false,
            'execution' => [
                'retries' => 2,
                'workers' => 1,
                'timeout' => 180000
            ]
        ];
    }
    
    /**
     * Create a new project
     */
    public function createProject(array $data, string $userId, string $username, ?array $uploadedFile = null): array {
        $source = $data['source'] ?? 'local';

        // Local projects require a file upload (ZIP or folder)
        if ($source === 'local' && ($uploadedFile === null || ($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK)) {
            return ['success' => false, 'error' => 'A file upload (ZIP or folder) is required for local projects'];
        }

        $projectId = $this->generateProjectId($data['name'] ?? 'new-project');
        $path = self::PROJECTS_DIR . '/' . $projectId;

        // Check if already exists
        if (is_dir($path)) {
            return ['success' => false, 'error' => 'Project already exists'];
        }

        // Create directory structure
        if (!mkdir($path, 0755, true)) {
            return ['success' => false, 'error' => 'Failed to create project directory'];
        }

        // Create project metadata
        $meta = [
            'name' => $data['name'] ?? $projectId,
            'description' => $data['description'] ?? '',
            'source' => $source,
            'gitUrl' => $data['gitUrl'] ?? null,
            'gitBranch' => $data['gitBranch'] ?? 'main',
            'gitProvider' => $data['gitProvider'] ?? null,
            'environments' => $data['environments'] ?? [],
            'defaults' => $data['defaults'] ?? [],
            'wrapperConfig' => $data['wrapperConfig'] ?? $this->getDefaultWrapperConfig(),
            'createdAt' => date('c'),
            'createdBy' => $username,
            'updatedAt' => date('c'),
            'updatedBy' => $username
        ];

        // Save metadata
        $this->saveProjectMeta($projectId, $meta);

        // Handle local source: extract uploaded file into project directory
        if ($source === 'local' && $uploadedFile !== null) {
            $ext = strtolower(pathinfo($uploadedFile['name'], PATHINFO_EXTENSION));
            if ($ext === 'zip') {
                $result = $this->extractZip($uploadedFile['tmp_name'], $path);
                if (!$result['success']) {
                    $this->deleteDirectory($path);
                    return ['success' => false, 'error' => 'Failed to extract ZIP: ' . ($result['error'] ?? 'unknown error')];
                }
            } else {
                // Single file upload — move into project directory
                $filename = basename($uploadedFile['name']);
                if (!move_uploaded_file($uploadedFile['tmp_name'], $path . '/' . $filename)) {
                    $this->deleteDirectory($path);
                    return ['success' => false, 'error' => 'Failed to move uploaded file'];
                }
            }
        }

        // If git source, trigger sync
        if ($source === 'git' && !empty($data['gitUrl'])) {
            $syncResult = $this->syncFromGit($projectId);
            if (!$syncResult['success']) {
                return [
                    'success' => true,
                    'projectId' => $projectId,
                    'warning' => 'Project created but git sync failed: ' . $syncResult['error']
                ];
            }
        }

        return ['success' => true, 'projectId' => $projectId];
    }

    /**
     * Import repository-based Playwright sources into local project folders.
     * This keeps the existing Playwright UI and runner path model working.
     */
    public function syncRepositorySources(?string $repositoryId = null): array {
        require_once __DIR__ . '/SourcesManager.php';

        $sourcesManager = new SourcesManager();
        $repositories = $repositoryId !== null
            ? array_filter([$sourcesManager->getRepository($repositoryId)])
            : $sourcesManager->getRepositories();

        $syncedProjects = [];

        foreach ($repositories as $repository) {
            if (!$repository || !in_array($repository['type'] ?? '', [SourcesManager::TYPE_PLAYWRIGHT, SourcesManager::TYPE_MIXED], true)) {
                continue;
            }

            $cacheDir = $sourcesManager->getRepositoryCacheDir($repository['id']);
            if ($cacheDir === null) {
                continue;
            }

            $content = $sourcesManager->discoverRepositoryContent($repository['id']);
            $tests = $content['tests'] ?? [];
            if (empty($tests)) {
                continue;
            }

            foreach ($tests as $testProject) {
                $syncResult = $this->syncRepositoryTestProject($repository, $cacheDir, $testProject);
                if (!empty($syncResult['success'])) {
                    $syncedProjects[] = $syncResult;
                }
            }
        }

        return [
            'success' => true,
            'projects' => $syncedProjects,
        ];
    }
    
    /**
     * Update project metadata
     */
    public function updateProject(string $projectId, array $data, string $userId, string $username): array {
        $project = $this->loadProject($projectId);
        
        if (!$project) {
            return ['success' => false, 'error' => 'Project not found'];
        }

        if ($this->isActiveRepositoryManagedProject($project)) {
            return ['success' => false, 'error' => 'This project is managed by Doki Sources and cannot be edited here'];
        }
        
        $meta = [
            'name' => $data['name'] ?? $project['name'],
            'description' => $data['description'] ?? $project['description'],
            'source' => $data['source'] ?? $project['source'],
            'gitUrl' => $data['gitUrl'] ?? $project['gitUrl'],
            'gitBranch' => $data['gitBranch'] ?? $project['gitBranch'],
            'gitProvider' => $data['gitProvider'] ?? $project['gitProvider'],
            'environments' => $data['environments'] ?? $project['environments'],
            'defaults' => $data['defaults'] ?? $project['defaults'],
            'wrapperConfig' => $data['wrapperConfig'] ?? $project['wrapperConfig'] ?? $this->getDefaultWrapperConfig(),
            'createdAt' => $project['createdAt'],
            'createdBy' => $project['createdBy'] ?? $username,
            'updatedAt' => date('c'),
            'updatedBy' => $username
        ];
        
        $this->saveProjectMeta($projectId, $meta);
        
        return ['success' => true];
    }
    
    /**
     * Get deletion summary for a project (for confirmation modal)
     */
    public function getDeleteSummary(string $projectId): array {
        $project = $this->loadProject($projectId);
        if (!$project) {
            return ['success' => false, 'error' => 'Project not found'];
        }

        $path = self::PROJECTS_DIR . '/' . $projectId;
        
        // Count project files and size
        $projectSize = $this->getDirectorySize($path);
        $testCount = $project['testCount'];
        
        // Find related reports
        $reportsDir = self::PROJECTS_DIR . '/../playwright-reports-archive';
        $relatedReports = [];
        $reportsSize = 0;
        $videoCount = 0;
        $screenshotCount = 0;
        
        if (is_dir($reportsDir)) {
            $dirs = scandir($reportsDir);
            foreach ($dirs as $dir) {
                if ($dir === '.' || $dir === '..') continue;
                
                $reportPath = $reportsDir . '/' . $dir;
                $projectFile = $reportPath . '/project.txt';
                
                if (file_exists($projectFile)) {
                    $reportProject = trim(file_get_contents($projectFile));
                    if ($reportProject === $projectId) {
                        $relatedReports[] = $dir;
                        $reportsSize += $this->getDirectorySize($reportPath);
                        
                        // Count media
                        $testResults = $reportPath . '/test-results';
                        if (is_dir($testResults)) {
                            $videoCount += count(glob($testResults . '/**/*.webm', GLOB_BRACE) ?: []);
                            $screenshotCount += count(glob($testResults . '/**/*.png', GLOB_BRACE) ?: []);
                        }
                    }
                }
            }
        }
        
        return [
            'success' => true,
            'projectId' => $projectId,
            'projectName' => $project['name'],
            'testCount' => $testCount,
            'projectSize' => $projectSize,
            'projectSizeFormatted' => $this->formatSize($projectSize),
            'reportCount' => count($relatedReports),
            'reportsSize' => $reportsSize,
            'reportsSizeFormatted' => $this->formatSize($reportsSize),
            'videoCount' => $videoCount,
            'screenshotCount' => $screenshotCount
        ];
    }
    
    /**
     * Delete a project with optional cascade delete of reports
     */
    public function deleteProject(string $projectId, bool $deleteReports = true): array {
        $path = self::PROJECTS_DIR . '/' . $projectId;

        $project = $this->loadProject($projectId);
        if ($project && $this->isActiveRepositoryManagedProject($project)) {
            return ['success' => false, 'error' => 'This project is managed by Doki Sources and cannot be deleted here'];
        }
        
        if (!is_dir($path)) {
            return ['success' => false, 'error' => 'Project not found'];
        }
        
        $deletedReports = 0;
        
        // Delete related reports if requested
        if ($deleteReports) {
            $reportsDir = self::PROJECTS_DIR . '/../playwright-reports-archive';
            
            if (is_dir($reportsDir)) {
                $dirs = scandir($reportsDir);
                foreach ($dirs as $dir) {
                    if ($dir === '.' || $dir === '..') continue;
                    
                    $reportPath = $reportsDir . '/' . $dir;
                    $projectFile = $reportPath . '/project.txt';
                    
                    if (file_exists($projectFile)) {
                        $reportProject = trim(file_get_contents($projectFile));
                        if ($reportProject === $projectId) {
                            $this->deleteDirectory($reportPath);
                            $deletedReports++;
                        }
                    }
                }
            }
        }
        
        // Recursively delete project directory
        $this->deleteDirectory($path);
        
        return [
            'success' => true,
            'deletedReports' => $deletedReports
        ];
    }
    
    /**
     * Get directory size in bytes
     */
    private function getDirectorySize(string $path): int {
        $size = 0;
        
        if (!is_dir($path)) {
            return 0;
        }
        
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
        
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }
        
        return $size;
    }
    
    /**
     * Format size in human readable format
     */
    private function formatSize(int $bytes): string {
        $units = ['B', 'KB', 'MB', 'GB'];
        $unitIndex = 0;
        
        while ($bytes >= 1024 && $unitIndex < count($units) - 1) {
            $bytes /= 1024;
            $unitIndex++;
        }
        
        return round($bytes, 1) . ' ' . $units[$unitIndex];
    }
    
    /**
     * Sync project from Git
     */
    public function syncFromGit(string $projectId): array {
        $project = $this->loadProject($projectId);
        
        if (!$project) {
            return ['success' => false, 'error' => 'Project not found'];
        }

        if ($this->isActiveRepositoryManagedProject($project)) {
            return ['success' => false, 'error' => 'This project is managed by Doki Sources. Sync the repository from Sources instead.'];
        }
        
        if ($project['source'] !== 'git' || empty($project['gitUrl'])) {
            return ['success' => false, 'error' => 'Project is not configured for git'];
        }
        
        $path = $project['path'];
        $gitUrl = $project['gitUrl'];
        $branch = $project['gitBranch'] ?? 'main';
        
        // Get authenticated URL if provider is configured
        $authUrl = $this->getAuthenticatedGitUrl($gitUrl, $project['gitProvider'] ?? null);
        $gitSafe = 'git -c safe.directory=' . escapeshellarg($path);
        
        // Check if git is available
        exec('which git 2>/dev/null', $gitCheck, $exitCode);
        if ($exitCode !== 0) {
            return ['success' => false, 'error' => 'Git is not installed'];
        }
        
        $output = [];
        
        // Check if .git exists (already cloned)
        if (is_dir($path . '/.git')) {
            // Update remote URL with auth token (in case it changed)
            $setUrlCmd = sprintf(
                '(cd %s && %s remote set-url origin %s) 2>&1',
                escapeshellarg($path),
                $gitSafe,
                escapeshellarg($authUrl)
            );
            exec($setUrlCmd, $setUrlOutput, $setUrlExitCode);
            if ($setUrlExitCode !== 0) {
                $sanitizedOutput = $this->sanitizeGitOutput($setUrlOutput ?? []);
                $message = trim(implode("\n", $sanitizedOutput));
                if ($message === '') {
                    $message = 'Failed to update git remote URL';
                }
                return ['success' => false, 'error' => $message];
            }
            
            // Pull latest
            $cmd = sprintf(
                '(cd %s && %s fetch origin && %s checkout %s && %s pull origin %s) 2>&1',
                escapeshellarg($path),
                $gitSafe,
                $gitSafe,
                escapeshellarg($branch),
                $gitSafe,
                escapeshellarg($branch)
            );
            exec($cmd, $output, $exitCode);
        } else {
            // Directory exists but no .git - need to clone differently
            // First, save project.yaml if exists
            $metaBackup = null;
            $metaFile = $path . '/project.yaml';
            if (file_exists($metaFile)) {
                $metaBackup = file_get_contents($metaFile);
            }
            
            // Remove existing directory contents
            $this->cleanDirectory($path);
            
            // Clone into the directory with authenticated URL
            $cmd = sprintf(
                'git clone --branch %s %s %s 2>&1',
                escapeshellarg($branch),
                escapeshellarg($authUrl),
                escapeshellarg($path)
            );
            exec($cmd, $output, $exitCode);
            
            // ALWAYS restore project.yaml - even on failure
            // This preserves our metadata regardless of clone success
            if ($metaBackup) {
                // Ensure directory exists (it might not if clone failed completely)
                if (!is_dir($path)) {
                    mkdir($path, 0755, true);
                }
                file_put_contents($metaFile, $metaBackup);
            }
        }
        
        if ($exitCode !== 0) {
            // Sanitize output to hide tokens
            $sanitizedOutput = $this->sanitizeGitOutput($output);
            $message = trim(implode("\n", $sanitizedOutput));
            if ($message === '') {
                $message = 'Git sync failed (no details). Check repository access and git safe.directory settings.';
            }
            return ['success' => false, 'error' => $message];
        }
        
        // Update project metadata with sync time (only save metadata fields, not computed ones)
        // IMPORTANT: Preserve wrapperConfig to keep user's override settings
        $metaToSave = [
            'name' => $project['name'],
            'description' => $project['description'] ?? '',
            'source' => $project['source'],
            'gitUrl' => $project['gitUrl'],
            'gitBranch' => $project['gitBranch'],
            'gitProvider' => $project['gitProvider'],
            'environments' => $project['environments'] ?? [],
            'defaults' => $project['defaults'] ?? [],
            'wrapperConfig' => $project['wrapperConfig'] ?? $this->getDefaultWrapperConfig(),
            'createdAt' => $project['createdAt'],
            'createdBy' => $project['createdBy'] ?? null,
            'updatedAt' => date('c'),
            'lastSync' => date('c')
        ];
        $this->saveProjectMeta($projectId, $metaToSave);
        
        return ['success' => true, 'output' => 'Sync completed successfully'];
    }
    
    /**
     * Get git URL with authentication token
     * Uses SourcesManager for centralized credential management (Batch 13)
     */
    private function getAuthenticatedGitUrl(string $gitUrl, ?string $providerId): string {
        if (empty($providerId)) {
            return $gitUrl;
        }
        
        // Use SourcesManager for centralized Git provider credentials
        require_once __DIR__ . '/SourcesManager.php';
        $sourcesManager = new SourcesManager();
        
        return $sourcesManager->getAuthenticatedUrl($gitUrl, $providerId);
    }
    
    /**
     * Remove tokens from git output for safe display
     */
    private function sanitizeGitOutput(array $output): array {
        return array_map(function($line) {
            // Remove any tokens from URLs in error messages
            return preg_replace('#(https://)[^@]+@#', '$1***@', $line);
        }, $output);
    }
    
    /**
     * Clean directory contents but keep the directory itself
     */
    private function cleanDirectory(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        
        $files = array_diff(scandir($dir), ['.', '..']);
        
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                unlink($path);
            }
        }
    }
    
    /**
     * Get project file tree
     */
    public function getFileTree(string $projectId, string $subPath = ''): array {
        $project = $this->loadProject($projectId);
        
        if (!$project) {
            return [];
        }
        
        $basePath = $project['path'];
        $fullPath = $subPath ? $basePath . '/' . $subPath : $basePath;
        
        // Security: ensure we don't go outside project directory
        $realBase = realpath($basePath);
        $realFull = realpath($fullPath);
        
        if ($realFull === false || strpos($realFull, $realBase) !== 0) {
            return [];
        }
        
        return $this->scanDirectory($realFull, $realBase);
    }
    
    /**
     * Get file content
     */
    public function getFileContent(string $projectId, string $filePath): ?array {
        $project = $this->loadProject($projectId);
        
        if (!$project) {
            return null;
        }
        
        $basePath = $project['path'];
        $fullPath = $basePath . '/' . $filePath;
        
        // Security check
        $realBase = realpath($basePath);
        $realFull = realpath($fullPath);
        
        if ($realFull === false || strpos($realFull, $realBase) !== 0) {
            return null;
        }
        
        if (!is_file($realFull)) {
            return null;
        }
        
        $ext = pathinfo($filePath, PATHINFO_EXTENSION);
        $isBinary = in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'ico', 'woff', 'woff2', 'ttf', 'eot']);
        
        return [
            'path' => $filePath,
            'name' => basename($filePath),
            'extension' => $ext,
            'size' => filesize($realFull),
            'isBinary' => $isBinary,
            'content' => $isBinary ? null : file_get_contents($realFull),
            'modifiedAt' => date('c', filemtime($realFull))
        ];
    }
    
    /**
     * Save file content
     */
    public function saveFileContent(string $projectId, string $filePath, string $content): array {
        $project = $this->loadProject($projectId);
        
        if (!$project) {
            return ['success' => false, 'error' => 'Project not found'];
        }

        if ($this->isActiveRepositoryManagedProject($project)) {
            return ['success' => false, 'error' => 'This project is managed by Doki Sources and is read-only'];
        }
        
        $basePath = $project['path'];
        $fullPath = $basePath . '/' . $filePath;
        
        // Security check - ensure path is within project
        $realBase = realpath($basePath);
        $parentDir = dirname($fullPath);
        
        // Create parent directory if needed
        if (!is_dir($parentDir)) {
            mkdir($parentDir, 0755, true);
        }
        
        $realParent = realpath($parentDir);
        if ($realParent === false || strpos($realParent, $realBase) !== 0) {
            return ['success' => false, 'error' => 'Invalid file path'];
        }
        
        if (file_put_contents($fullPath, $content) === false) {
            return ['success' => false, 'error' => 'Failed to write file'];
        }
        
        return ['success' => true];
    }
    
    /**
     * Create a new file
     */
    public function createFile(string $projectId, string $filePath, string $content = ''): array {
        $project = $this->loadProject($projectId);
        
        if (!$project) {
            return ['success' => false, 'error' => 'Project not found'];
        }

        if ($this->isActiveRepositoryManagedProject($project)) {
            return ['success' => false, 'error' => 'This project is managed by Doki Sources and is read-only'];
        }
        
        $basePath = $project['path'];
        $fullPath = $basePath . '/' . $filePath;
        
        // Check if file already exists
        if (file_exists($fullPath)) {
            return ['success' => false, 'error' => 'File already exists'];
        }
        
        return $this->saveFileContent($projectId, $filePath, $content);
    }
    
    /**
     * Delete a file
     */
    public function deleteFile(string $projectId, string $filePath): array {
        $project = $this->loadProject($projectId);
        
        if (!$project) {
            return ['success' => false, 'error' => 'Project not found'];
        }

        if ($this->isActiveRepositoryManagedProject($project)) {
            return ['success' => false, 'error' => 'This project is managed by Doki Sources and is read-only'];
        }
        
        $basePath = $project['path'];
        $fullPath = $basePath . '/' . $filePath;
        
        // Security check
        $realBase = realpath($basePath);
        $realFull = realpath($fullPath);
        
        if ($realFull === false || strpos($realFull, $realBase) !== 0) {
            return ['success' => false, 'error' => 'Invalid file path'];
        }
        
        if (!file_exists($realFull)) {
            return ['success' => false, 'error' => 'File not found'];
        }
        
        if (is_dir($realFull)) {
            $this->deleteDirectory($realFull);
        } else {
            unlink($realFull);
        }
        
        return ['success' => true];
    }
    
    /**
     * Upload file(s) to project
     */
    public function uploadFile(string $projectId, array $file, string $targetPath = ''): array {
        $project = $this->loadProject($projectId);
        
        if (!$project) {
            return ['success' => false, 'error' => 'Project not found'];
        }

        if ($this->isActiveRepositoryManagedProject($project)) {
            return ['success' => false, 'error' => 'This project is managed by Doki Sources and is read-only'];
        }
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'error' => 'Upload error: ' . $file['error']];
        }
        
        $basePath = $project['path'];
        $targetDir = $targetPath ? $basePath . '/' . $targetPath : $basePath;
        
        // Security check
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }
        
        $realBase = realpath($basePath);
        $realTarget = realpath($targetDir);
        
        if ($realTarget === false || strpos($realTarget, $realBase) !== 0) {
            return ['success' => false, 'error' => 'Invalid target path'];
        }
        
        $filename = basename($file['name']);
        $destination = $realTarget . '/' . $filename;
        
        // Handle ZIP files - extract them
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($ext === 'zip') {
            return $this->extractZip($file['tmp_name'], $realTarget);
        }
        
        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            return ['success' => false, 'error' => 'Failed to move uploaded file'];
        }
        
        return ['success' => true, 'path' => $targetPath . '/' . $filename];
    }
    
    /**
     * Extract ZIP file
     */
    private function extractZip(string $zipPath, string $targetDir): array {
        $zip = new ZipArchive();
        
        if ($zip->open($zipPath) !== true) {
            return ['success' => false, 'error' => 'Failed to open ZIP file'];
        }
        
        $zip->extractTo($targetDir);
        $zip->close();
        
        return ['success' => true, 'extracted' => true];
    }
    
    /**
     * Get test files for a project
     */
    public function getTestFiles(string $projectId): array {
        $project = $this->loadProject($projectId);
        
        if (!$project) {
            return [];
        }

        return $this->discoverTestFiles($project['path']);
    }
    
    // ===== Private Helper Methods =====
    
    private function generateProjectId(string $name): string {
        $id = strtolower(trim($name));
        $id = preg_replace('/[^a-z0-9]+/', '-', $id);
        $id = trim($id, '-');
        
        // If ID already exists, add a number
        $basePath = self::PROJECTS_DIR;
        $counter = 1;
        $finalId = $id;
        
        while (is_dir($basePath . '/' . $finalId)) {
            $finalId = $id . '-' . $counter;
            $counter++;
        }
        
        return $finalId;
    }
    
    private function formatProjectName(string $id): string {
        return ucwords(str_replace(['-', '_'], ' ', $id));
    }
    
    private function countTestFiles(string $path): int {
        return count($this->discoverTestFiles($path));
    }

    /**
     * Check whether a source repository still exists in Doki.
     */
    private function sourceRepositoryExists(?string $repositoryId): bool {
        if ($repositoryId === null || $repositoryId === '') {
            return false;
        }

        require_once __DIR__ . '/SourcesManager.php';

        $sourcesManager = new SourcesManager();
        return $sourcesManager->getRepository($repositoryId) !== null;
    }

    /**
     * Repository-backed projects are only read-only while their source still exists.
     */
    private function isActiveRepositoryManagedProject(array $project): bool {
        if (($project['source'] ?? '') !== 'repository') {
            return false;
        }

        if (array_key_exists('sourceRepositoryAvailable', $project)) {
            return (bool)$project['sourceRepositoryAvailable'];
        }

        return $this->sourceRepositoryExists($project['sourceRepositoryId'] ?? null);
    }

    /**
     * Sync a single discovered repository test project into the local projects area.
     */
    private function syncRepositoryTestProject(array $repository, string $cacheDir, array $testProject): array {
        $relativePath = trim((string)($testProject['path'] ?? '.'), '/');
        $relativePath = $relativePath === '' ? '.' : $relativePath;
        $sourceDir = $relativePath === '.' ? $cacheDir : $cacheDir . '/' . $relativePath;

        if (!is_dir($sourceDir)) {
            return ['success' => false, 'error' => 'Source test directory not found'];
        }

        $projectId = $this->buildManagedProjectId($repository['id'], $relativePath);
        $projectDir = self::PROJECTS_DIR . '/' . $projectId;
        $existingMeta = $this->loadProjectMeta($projectId);
        $syncStamp = (string)($repository['last_sync_at'] ?? '');
        $defaultName = $this->formatManagedProjectName(
            (string)($repository['name'] ?? $repository['id'] ?? $projectId),
            $relativePath
        );
        $defaultDescription = 'Managed from Doki Sources repository ' . ($repository['name'] ?? $repository['id']);
        if ($relativePath !== '.') {
            $defaultDescription .= ' (' . $relativePath . ')';
        }

        $projectManaged = (($existingMeta['sourceRepositoryId'] ?? null) === ($repository['id'] ?? null))
            && (($existingMeta['sourceRepositoryPath'] ?? '.') === $relativePath);
        $needsFileSync = !($projectManaged && is_dir($projectDir) && ($existingMeta['sourceSyncAt'] ?? '') === $syncStamp);
        $metadataChanged = ($existingMeta['name'] ?? null) !== $defaultName
            || ($existingMeta['description'] ?? null) !== $defaultDescription
            || ($existingMeta['source'] ?? null) !== 'repository'
            || ($existingMeta['sourceRepositoryId'] ?? null) !== ($repository['id'] ?? null)
            || ($existingMeta['sourceRepositoryName'] ?? null) !== ($repository['name'] ?? null)
            || ($existingMeta['sourceRepositoryUrl'] ?? null) !== ($repository['url'] ?? null)
            || ($existingMeta['sourceRepositoryPath'] ?? null) !== $relativePath
            || ($existingMeta['sourceSyncAt'] ?? null) !== $syncStamp
            || ($existingMeta['gitUrl'] ?? null) !== ($repository['url'] ?? null)
            || ($existingMeta['gitBranch'] ?? null) !== ($repository['branch'] ?? 'main')
            || ($existingMeta['gitProvider'] ?? null) !== ($repository['provider_id'] ?? null);

        if (!$needsFileSync && !$metadataChanged) {
            return ['success' => true, 'projectId' => $projectId, 'skipped' => true];
        }

        if ($needsFileSync) {
            if (is_dir($projectDir)) {
                $this->cleanDirectory($projectDir);
            } else if (!mkdir($projectDir, 0755, true) && !is_dir($projectDir)) {
                return ['success' => false, 'error' => 'Failed to create project directory'];
            }

            if (!$this->copyDirectoryContents($sourceDir, $projectDir, self::COPY_SKIP_NAMES)) {
                return ['success' => false, 'error' => 'Failed to copy source project files'];
            }
        }

        $meta = [
            'name' => $defaultName,
            'description' => $defaultDescription,
            'source' => 'repository',
            'sourceRepositoryId' => $repository['id'] ?? null,
            'sourceRepositoryName' => $repository['name'] ?? null,
            'sourceRepositoryUrl' => $repository['url'] ?? null,
            'sourceRepositoryPath' => $relativePath,
            'sourceSyncAt' => $syncStamp,
            'gitUrl' => $repository['url'] ?? null,
            'gitBranch' => $repository['branch'] ?? 'main',
            'gitProvider' => $repository['provider_id'] ?? null,
            'defaults' => $existingMeta['defaults'] ?? [],
            'environments' => $existingMeta['environments'] ?? [],
            'wrapperConfig' => $existingMeta['wrapperConfig'] ?? $this->getDefaultWrapperConfig(),
            'createdAt' => $existingMeta['createdAt'] ?? date('c'),
            'createdBy' => $existingMeta['createdBy'] ?? 'system',
            'updatedAt' => date('c'),
            'updatedBy' => 'system',
        ];

        $this->saveProjectMeta($projectId, $meta);

        return ['success' => true, 'projectId' => $projectId, 'path' => $relativePath];
    }

    /**
     * Build a stable project ID for a repository-backed Playwright project.
     */
    private function buildManagedProjectId(string $repositoryId, string $relativePath): string {
        if ($relativePath === '.' || $relativePath === '') {
            return self::MANAGED_PROJECT_PREFIX . $repositoryId;
        }

        $suffix = strtolower($relativePath);
        $suffix = preg_replace('/[^a-z0-9]+/', '-', $suffix);
        $suffix = trim($suffix, '-');

        return self::MANAGED_PROJECT_PREFIX . $repositoryId . '--' . $suffix;
    }

    /**
     * Build a readable name for repository-backed projects.
     */
    private function formatManagedProjectName(string $repositoryName, string $relativePath): string {
        if ($relativePath === '.' || $relativePath === '') {
            return $repositoryName;
        }

        return $repositoryName . ' / ' . str_replace(['-', '_'], ' ', $relativePath);
    }

    /**
     * Load raw project metadata from project.yaml if present.
     */
    private function loadProjectMeta(string $projectId): array {
        $metaFile = self::PROJECTS_DIR . '/' . $projectId . '/project.yaml';
        if (!file_exists($metaFile)) {
            return [];
        }

        return $this->parseProjectMetaFile($metaFile);
    }

    /**
     * Copy a source project into the local project directory.
     */
    private function copyDirectoryContents(string $sourceDir, string $targetDir, array $skipNames = []): bool {
        $entries = scandir($sourceDir);
        if ($entries === false) {
            return false;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || in_array($entry, $skipNames, true)) {
                continue;
            }

            $sourcePath = $sourceDir . '/' . $entry;
            $targetPath = $targetDir . '/' . $entry;

            if (is_dir($sourcePath)) {
                if (!is_dir($targetPath) && !mkdir($targetPath, 0755, true) && !is_dir($targetPath)) {
                    return false;
                }
                if (!$this->copyDirectoryContents($sourcePath, $targetPath, $skipNames)) {
                    return false;
                }
                continue;
            }

            if (!copy($sourcePath, $targetPath)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Recursively discover Playwright test files within a project.
     */
    private function discoverTestFiles(string $basePath): array {
        if (!is_dir($basePath)) {
            return [];
        }

        $tests = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($basePath, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $pathname = $file->getPathname();
            $relativePath = ltrim(substr($pathname, strlen($basePath)), DIRECTORY_SEPARATOR);
            if ($relativePath === '') {
                continue;
            }

            $segments = explode(DIRECTORY_SEPARATOR, $relativePath);
            if (count(array_intersect($segments, self::COPY_SKIP_NAMES)) > 0) {
                continue;
            }

            if (!preg_match('/\.(test|spec)\.(ts|js)$/i', $relativePath)) {
                continue;
            }

            $tests[] = str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);
        }

        sort($tests);
        return array_values(array_unique($tests));
    }
    
    private function saveProjectMeta(string $projectId, array $meta): void {
        $path = self::PROJECTS_DIR . '/' . $projectId . '/project.yaml';
        
        if (function_exists('yaml_emit')) {
            file_put_contents($path, yaml_emit($meta, YAML_UTF8_ENCODING));
        } else {
            // Fallback to JSON if YAML not available
            file_put_contents($path, json_encode($meta, JSON_PRETTY_PRINT));
        }
    }

    /**
     * Read project metadata from YAML when available, otherwise fall back to JSON.
     */
    private function parseProjectMetaFile(string $metaFile): array {
        if (function_exists('yaml_parse_file')) {
            $meta = yaml_parse_file($metaFile);
            if (is_array($meta)) {
                return $meta;
            }
        }

        $raw = file_get_contents($metaFile);
        if ($raw === false) {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
    
    private function scanDirectory(string $path, string $basePath): array {
        $items = [];
        $entries = scandir($path);
        
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            
            // Skip common ignore patterns
            if (in_array($entry, ['node_modules', '.git', '.cache', 'test-results', 'playwright-report'])) {
                continue;
            }
            
            $fullPath = $path . '/' . $entry;
            $relativePath = str_replace($basePath . '/', '', $fullPath);
            $isDir = is_dir($fullPath);
            
            $item = [
                'name' => $entry,
                'path' => $relativePath,
                'isDirectory' => $isDir,
                'extension' => $isDir ? null : pathinfo($entry, PATHINFO_EXTENSION),
                'size' => $isDir ? null : filesize($fullPath),
                'modifiedAt' => date('c', filemtime($fullPath))
            ];
            
            if ($isDir) {
                $item['children'] = $this->scanDirectory($fullPath, $basePath);
            }
            
            $items[] = $item;
        }
        
        // Sort: directories first, then files alphabetically
        usort($items, function($a, $b) {
            if ($a['isDirectory'] && !$b['isDirectory']) return -1;
            if (!$a['isDirectory'] && $b['isDirectory']) return 1;
            return strcasecmp($a['name'], $b['name']);
        });
        
        return $items;
    }
    
    private function deleteDirectory(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        
        $files = array_diff(scandir($dir), ['.', '..']);
        
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        
        rmdir($dir);
    }
}
