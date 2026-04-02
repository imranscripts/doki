<?php
/**
 * AppImageBuilder
 * 
 * Builds custom PHP-FPM Docker images for apps based on their runtime requirements.
 * Apps with custom extensions run in their own container, proxied by Doki.
 */

class AppImageBuilder {
    private string $appId;
    private array $manifest;
    private string $statusDir;
    
    // Extension dependencies - what system packages each PHP extension needs
    private const EXTENSION_DEPS = [
        'zip' => ['libzip-dev'],
        'gd' => ['libfreetype6-dev', 'libjpeg62-turbo-dev', 'libpng-dev', 'libwebp-dev'],
        'intl' => ['libicu-dev'],
        'soap' => ['libxml2-dev'],
        'xsl' => ['libxslt1-dev'],
        'imagick' => ['libmagickwand-dev'],
        'mcrypt' => ['libmcrypt-dev'],
        'pgsql' => ['libpq-dev'],
        'mysqli' => [],
        'pdo_mysql' => [],
        'pdo_pgsql' => ['libpq-dev'],
        'bcmath' => [],
        'opcache' => [],
        'exif' => [],
        'calendar' => [],
    ];
    
    // Extensions that require PECL installation
    private const PECL_EXTENSIONS = ['imagick', 'redis', 'mongodb', 'xdebug', 'apcu'];
    
    // Extensions that need special configure flags
    private const EXTENSION_CONFIGURE = [
        'gd' => '--with-freetype --with-jpeg --with-webp',
    ];
    
    public function __construct(string $appId, array $manifest) {
        $this->appId = $appId;
        $this->manifest = $manifest;
        $this->statusDir = __DIR__ . '/../data/app-build-status';
        
        if (!is_dir($this->statusDir)) {
            mkdir($this->statusDir, 0755, true);
        }
    }

    /**
     * Run a short-lived command with a timeout to avoid hanging requests.
     */
    private function runCommandQuick(string $cmd, int $timeoutSeconds = 2): array {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            return [
                'success' => false,
                'output' => '',
                'error' => 'Failed to start process',
                'exitCode' => null,
                'timeout' => false,
            ];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output = '';
        $error = '';
        $start = microtime(true);
        $timedOut = false;

        while (true) {
            $status = proc_get_status($process);

            $output .= stream_get_contents($pipes[1]);
            $error .= stream_get_contents($pipes[2]);

            if (!$status['running']) {
                break;
            }

            if ((microtime(true) - $start) > $timeoutSeconds) {
                $timedOut = true;
                proc_terminate($process, 9);
                break;
            }

            usleep(20000);
        }

        $output .= stream_get_contents($pipes[1]);
        $error .= stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($timedOut) {
            return [
                'success' => false,
                'output' => trim($output . $error),
                'error' => 'Command timed out',
                'exitCode' => $exitCode,
                'timeout' => true,
            ];
        }

        $success = ($exitCode === 0);
        return [
            'success' => $success,
            'output' => trim($output . $error),
            'error' => $success ? '' : 'Command failed',
            'exitCode' => $exitCode,
            'timeout' => false,
        ];
    }

    /**
     * Run a Docker command with a consistent local daemon configuration.
     */
    private function runDockerCommand(string $cmd, int $timeoutSeconds = 2): array {
        $dockerConfigDir = __DIR__ . '/../data/docker-config';
        if (!is_dir($dockerConfigDir)) {
            @mkdir($dockerConfigDir, 0755, true);
        }

        $dockerHost = getenv('DOCKER_HOST') ?: 'unix:///var/run/docker.sock';
        $prefix = sprintf(
            'DOCKER_HOST=%s DOCKER_CONFIG=%s HOME=/tmp ',
            escapeshellarg($dockerHost),
            escapeshellarg($dockerConfigDir)
        );

        return $this->runCommandQuick($prefix . $cmd, $timeoutSeconds);
    }

    /**
     * Detect a successful `docker inspect` JSON payload even if the exit code is unreliable.
     */
    private function hasDockerInspectPayload(string $output): bool {
        $trimmed = trim($output);
        if ($trimmed === '') {
            return false;
        }
        $data = json_decode($trimmed, true);
        if (!is_array($data) || empty($data[0]) || !is_array($data[0])) {
            return false;
        }
        return !empty($data[0]['Id']);
    }

    private function looksLikeDockerError(string $output): bool {
        $lower = strtolower($output);
        return $lower !== '' && (
            strpos($lower, 'error') !== false ||
            strpos($lower, 'permission denied') !== false ||
            strpos($lower, 'cannot connect') !== false ||
            strpos($lower, 'is the docker daemon running') !== false ||
            strpos($lower, 'cannot connect to the docker daemon') !== false
        );
    }

    private function isNetworkNotFoundError(string $output): bool {
        return (bool)preg_match('/network\\s+[0-9a-f]{8,64}\\s+not found/i', $output)
            || (bool)preg_match('/network\\s+[^\\s]+\\s+not found/i', $output);
    }

    private function resolveNetworkFromContainer(string $containerName, int $timeoutSeconds = 3): ?string {
        $cmd = "docker inspect {$containerName} --format '{{json .NetworkSettings.Networks}}' 2>&1";
        $result = $this->runDockerCommand($cmd, $timeoutSeconds);
        $json = trim($result['output'] ?? '');
        if ($json === '' || $this->looksLikeDockerError($json)) {
            return null;
        }
        $networks = json_decode($json, true);
        if (!is_array($networks) || empty($networks)) {
            return null;
        }
        $keys = array_keys($networks);
        return $keys[0] ?? null;
    }

    private function extractNetworkName(string $output): ?string {
        $trimmed = trim($output);
        if ($trimmed === '' || $this->looksLikeDockerError($trimmed)) {
            return null;
        }

        // Sometimes output may include multiple network names; take the first token.
        $parts = preg_split('/\s+/', $trimmed);
        $candidate = $parts[0] ?? '';
        if ($candidate === '') {
            return null;
        }

        // If Docker returned a network ID, resolve to a name. If we can't resolve,
        // treat it as invalid so we can fall back to safer defaults.
        if (preg_match('/^[0-9a-f]{64}$/i', $candidate)) {
            $resolve = $this->runDockerCommand(
                "docker network inspect -f '{{.Name}}' {$candidate} 2>&1",
                3
            );
            $resolved = trim($resolve['output'] ?? '');
            if ($resolved !== '' && !$this->looksLikeDockerError($resolved)) {
                return $resolved;
            }
            return null;
        }

        return $candidate;
    }

    private function resolveHostAppPath(): string {
        $candidate = trim((string)(getenv('HOST_APP_PATH') ?: ''));
        if ($candidate !== '' && $candidate !== '/var/www/html') {
            return $candidate;
        }

        $serverCandidate = trim((string)($_SERVER['HOST_APP_PATH'] ?? ''));
        if ($serverCandidate !== '' && $serverCandidate !== '/var/www/html') {
            return $serverCandidate;
        }

        $selfContainer = trim((string)(getenv('HOSTNAME') ?: ''));
        if ($selfContainer === '' && is_readable('/etc/hostname')) {
            $selfContainer = trim((string)file_get_contents('/etc/hostname'));
        }

        if ($selfContainer !== '') {
            $inspectCmd = sprintf(
                "docker inspect %s --format '{{range .Mounts}}{{if eq .Destination \"/var/www/html\"}}{{.Source}}{{end}}{{end}}' 2>&1",
                escapeshellarg($selfContainer)
            );
            $inspectResult = $this->runDockerCommand($inspectCmd, 5);
            $inspectedPath = trim((string)($inspectResult['output'] ?? ''));
            if ($inspectedPath !== '' && !$this->looksLikeDockerError($inspectedPath) && $inspectedPath !== '/var/www/html') {
                return $inspectedPath;
            }
        }

        return '/var/www/html';
    }

    private function inspectContainerMountSource(string $containerName, string $destination): ?string {
        $inspectCmd = sprintf(
            "docker inspect %s --format '{{range .Mounts}}{{if eq .Destination \"%s\"}}{{.Source}}{{end}}{{end}}' 2>&1",
            escapeshellarg($containerName),
            addslashes($destination)
        );
        $result = $this->runDockerCommand($inspectCmd, 5);
        $output = trim((string)($result['output'] ?? ''));
        if ($output === '' || $this->looksLikeDockerError($output)) {
            return null;
        }
        return $output;
    }

    private function containerHasExpectedMounts(string $hostAppPath): bool {
        $containerName = $this->getContainerName();
        $appSource = $this->inspectContainerMountSource($containerName, '/var/www/html');
        $dataSource = $this->inspectContainerMountSource($containerName, '/var/www/html/data');

        if ($appSource === null || $dataSource === null) {
            return false;
        }

        return rtrim($appSource, '/') === rtrim($hostAppPath, '/')
            && rtrim($dataSource, '/') === rtrim($hostAppPath, '/') . '/data';
    }
    
    /**
     * Get the custom image name for this app
     */
    public function getImageName(): string {
        return "doki-app-{$this->appId}:latest";
    }
    
    /**
     * Get the container name for this app's FPM service
     */
    public function getContainerName(): string {
        return "doki-fpm-{$this->appId}";
    }
    
    /**
     * Check if app has custom runtime requirements
     */
    public function needsCustomImage(): bool {
        $runtime = $this->manifest['runtime'] ?? [];
        $extensions = $runtime['phpExtensions'] ?? [];
        $packages = $runtime['systemPackages'] ?? [];
        
        return !empty($extensions) || !empty($packages);
    }
    
    /**
     * Get required PHP extensions
     */
    public function getRequiredExtensions(): array {
        return $this->manifest['runtime']['phpExtensions'] ?? [];
    }
    
    /**
     * Get required system packages
     */
    public function getRequiredPackages(): array {
        return $this->manifest['runtime']['systemPackages'] ?? [];
    }

    /**
     * Normalize runtime config so signatures are stable across ordering-only changes.
     */
    private function normalizeRuntimeValue($value) {
        if (!is_array($value)) {
            return is_string($value) ? trim($value) : $value;
        }

        if (array_is_list($value)) {
            $normalized = array_map(fn($item) => $this->normalizeRuntimeValue($item), $value);
            usort($normalized, function ($left, $right) {
                return strcmp((string)json_encode($left), (string)json_encode($right));
            });
            return $normalized;
        }

        ksort($value);
        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[$key] = $this->normalizeRuntimeValue($item);
        }

        return $normalized;
    }

    /**
     * Build a signature for the current runtime requirements.
     */
    private function getRuntimeSignature(): ?string {
        if (!$this->needsCustomImage()) {
            return null;
        }

        return sha1((string)json_encode($this->normalizeRuntimeValue($this->manifest['runtime'] ?? [])));
    }

    /**
     * Mark this app's runtime as stale after a manifest update.
     */
    public function markRuntimeStale(string $message = 'Runtime requirements changed. Rebuild required.'): void {
        if (!$this->needsCustomImage()) {
            return;
        }

        $this->updateStatus('stale', $message);
    }

    /**
     * Remove the persisted build status marker.
     */
    public function removeStatusFile(): void {
        $statusFile = $this->statusDir . "/{$this->appId}.json";
        @unlink($statusFile);
    }
    
    /**
     * Check if custom image exists
     */
    public function imageExists(int $timeoutSeconds = 2): bool {
        $imageName = $this->getImageName();
        $result = $this->runDockerCommand("docker image inspect {$imageName} 2>&1", $timeoutSeconds);
        if ($result['success']) {
            return true;
        }
        if ($this->hasDockerInspectPayload($result['output'] ?? '')) {
            return true;
        }
        if (!empty($result['timeout'])) {
            error_log("AppImageBuilder[{$this->appId}]: docker image inspect timed out");
        }
        return false;
    }
    
    /**
     * Check if FPM container is running
     */
    public function isContainerRunning(int $timeoutSeconds = 2): bool {
        $containerName = $this->getContainerName();
        $result = $this->runDockerCommand(
            "docker ps --filter 'name=^{$containerName}$' --filter 'status=running' --format '{{.ID}}' 2>&1",
            $timeoutSeconds
        );
        $output = trim($result['output'] ?? '');
        if ($result['success']) {
            return $output !== '';
        }
        if ($output !== '' && !$this->looksLikeDockerError($output)) {
            // Some environments return a non-zero exit code even with valid output.
            return true;
        }
        if (!empty($result['timeout'])) {
            error_log("AppImageBuilder[{$this->appId}]: docker ps timed out");
        }
        return false;
    }
    
    /**
     * Generate Dockerfile content for this app
     */
    public function generateDockerfile(): string {
        $extensions = $this->getRequiredExtensions();
        $packages = $this->getRequiredPackages();
        
        $customParts = [];
        
        // Collect all system dependencies
        $allDeps = $packages;
        foreach ($extensions as $ext) {
            $ext = strtolower(trim($ext));
            if (isset(self::EXTENSION_DEPS[$ext])) {
                $allDeps = array_merge($allDeps, self::EXTENSION_DEPS[$ext]);
            }
        }
        $allDeps = array_unique($allDeps);
        
        // Install system dependencies
        if (!empty($allDeps)) {
            $depsStr = implode(' ', array_map('escapeshellarg', $allDeps));
            $customParts[] = "# Install system dependencies for extensions";
            $customParts[] = "RUN apt-get update && apt-get install -y {$depsStr} && rm -rf /var/lib/apt/lists/*";
            $customParts[] = "";
        }
        
        // Install PHP extensions
        if (!empty($extensions)) {
            $customParts[] = "# Install PHP extensions";
            
            foreach ($extensions as $ext) {
                $ext = strtolower(trim($ext));
                
                if (in_array($ext, self::PECL_EXTENSIONS)) {
                    // PECL extension
                    $customParts[] = "RUN pecl install {$ext} && docker-php-ext-enable {$ext}";
                } else if (isset(self::EXTENSION_CONFIGURE[$ext])) {
                    // Extension with configure flags
                    $flags = self::EXTENSION_CONFIGURE[$ext];
                    $customParts[] = "RUN docker-php-ext-configure {$ext} {$flags} && docker-php-ext-install {$ext}";
                } else {
                    // Standard extension
                    $customParts[] = "RUN docker-php-ext-install {$ext}";
                }
            }
            $customParts[] = "";
        }
        
        // Read the template
        $templatePath = __DIR__ . '/../../docker/app-runtime/Dockerfile.template';
        $template = file_get_contents($templatePath);
        
        // Replace placeholder
        $customBlock = implode("\n", $customParts);
        $dockerfile = str_replace('{{CUSTOM_EXTENSIONS}}', $customBlock, $template);
        
        return $dockerfile;
    }
    
    /**
     * Build the custom image
     */
    public function buildImage(): array {
        if (!$this->needsCustomImage()) {
            return [
                'success' => true,
                'message' => 'No custom runtime required',
                'needed' => false
            ];
        }
        
        $this->updateStatus('building', 'Generating Dockerfile...');
        
        // Generate Dockerfile
        $dockerfile = $this->generateDockerfile();
        $buildBase = __DIR__ . '/../data/docker-builds';
        if (!is_dir($buildBase) && !mkdir($buildBase, 0755, true) && !is_dir($buildBase)) {
            $errorMsg = 'Failed to create build base directory: ' . $buildBase;
            $this->updateStatus('failed', 'Build failed', $errorMsg);
            return [
                'success' => false,
                'error' => 'Failed to build image',
                'output' => $errorMsg
            ];
        }
        
        $buildDir = $buildBase . '/doki-build-' . $this->appId . '-' . time();
        if (!mkdir($buildDir, 0755, true)) {
            $errorMsg = 'Failed to create build directory: ' . $buildDir;
            $this->updateStatus('failed', 'Build failed', $errorMsg);
            return [
                'success' => false,
                'error' => 'Failed to build image',
                'output' => $errorMsg
            ];
        }
        
        $dockerfilePath = $buildDir . '/Dockerfile';
        if (file_put_contents($dockerfilePath, $dockerfile) === false) {
            $errorMsg = 'Failed to write Dockerfile: ' . $dockerfilePath;
            $this->updateStatus('failed', 'Build failed', $errorMsg);
            @rmdir($buildDir);
            return [
                'success' => false,
                'error' => 'Failed to build image',
                'output' => $errorMsg
            ];
        }
        
        error_log("AppImageBuilder[{$this->appId}]: Building image");
        error_log("AppImageBuilder[{$this->appId}]: Dockerfile:\n{$dockerfile}");
        
        $this->updateStatus('building', 'Building Docker image...');
        
        // Build the image
        $imageName = $this->getImageName();
        
        // Set Docker config to writable location
        $dockerConfigDir = __DIR__ . '/../data/docker-config';
        if (!is_dir($dockerConfigDir)) {
            mkdir($dockerConfigDir, 0755, true);
        }
        
        $dockerHost = getenv('DOCKER_HOST') ?: 'unix:///var/run/docker.sock';
        $buildCmd = sprintf(
            'DOCKER_HOST=%s DOCKER_CONFIG=%s HOME=/tmp docker build -t %s %s 2>&1',
            escapeshellarg($dockerHost),
            escapeshellarg($dockerConfigDir),
            escapeshellarg($imageName),
            escapeshellarg($buildDir)
        );
        
        $output = [];
        $exitCode = null;
        exec($buildCmd, $output, $exitCode);
        
        // Cleanup
        @unlink($dockerfilePath);
        @rmdir($buildDir);
        
        $outputStr = implode("\n", $output);
        error_log("AppImageBuilder[{$this->appId}]: Build exit code: {$exitCode}");
        
        if ($exitCode !== 0) {
            error_log("AppImageBuilder[{$this->appId}]: Build failed: {$outputStr}");
            $this->updateStatus('failed', 'Build failed', $outputStr);
            return [
                'success' => false,
                'error' => 'Failed to build image',
                'output' => $outputStr
            ];
        }
        
        $this->updateStatus('ready', 'Image built successfully');
        
        return [
            'success' => true,
            'message' => 'Image built successfully',
            'imageName' => $imageName
        ];
    }
    
    /**
     * Start the FPM container for this app
     */
    public function startContainer(): array {
        if (!$this->needsCustomImage()) {
            return ['success' => false, 'error' => 'App does not need custom runtime'];
        }

        $buildStatus = $this->getBuildStatus();
        if (($buildStatus['status'] ?? '') === 'stale') {
            $this->removeImage();

            $buildResult = $this->buildImage();
            if (!$buildResult['success']) {
                return $buildResult;
            }
        }
        
        $containerName = $this->getContainerName();
        $imageName = $this->getImageName();
        $hostAppPath = $this->resolveHostAppPath();

        $inspectCmd = "docker image inspect {$imageName} 2>&1";
        $inspectResult = $this->runDockerCommand($inspectCmd, 6);
        if (!$inspectResult['success'] && !$this->hasDockerInspectPayload($inspectResult['output'] ?? '')) {
            // Build the image first
            $buildResult = $this->buildImage();
            if (!$buildResult['success']) {
                return $buildResult;
            }
            // Re-check visibility after build (Docker socket issues can hide images)
            $inspectResult = $this->runDockerCommand($inspectCmd, 6);
            if (!$inspectResult['success'] && !$this->hasDockerInspectPayload($inspectResult['output'] ?? '')) {
                return [
                    'success' => false,
                    'error' => 'Image build finished but Docker cannot see the image. Check Docker socket access.',
                    'details' => trim(($inspectResult['output'] ?? '') ?: ($inspectResult['error'] ?? ''))
                ];
            }
        }
        
        // Check if already running
        if ($this->isContainerRunning(3)) {
            if ($hostAppPath !== '/var/www/html' && $this->containerHasExpectedMounts($hostAppPath)) {
                return [
                    'success' => true,
                    'message' => 'Container already running',
                    'containerName' => $containerName
                ];
            }

            error_log("AppImageBuilder[{$this->appId}]: Running container has invalid mounts, recreating");
            $this->removeContainer();
        }
        
        // Check if container exists but stopped
        $existsCmd = "docker ps -a --filter 'name=^{$containerName}$' --format '{{.ID}}' 2>&1";
        $existsResult = $this->runDockerCommand($existsCmd, 5);
        $existsOutput = trim($existsResult['output'] ?? '');
        if (!$existsResult['success'] && $existsOutput !== '' && $this->looksLikeDockerError($existsOutput)) {
            return [
                'success' => false,
                'error' => 'Failed to check existing container: ' . ($existsOutput ?: $existsResult['error'])
            ];
        }
        $exists = $existsOutput;
        
        $shouldCreate = empty($exists);
        if (!empty($exists)) {
            // Start existing container
            $startCmd = "docker start {$containerName} 2>&1";
            $startResult = $this->runDockerCommand($startCmd, 8);
            $startOutput = trim($startResult['output'] ?? '');
            error_log("AppImageBuilder[{$this->appId}]: Starting existing container: " . $startOutput);
            if (!$startResult['success'] && $this->isNetworkNotFoundError($startOutput)) {
                // Container is tied to a stale network - remove and re-create
                $this->removeContainer();
                $shouldCreate = true;
            } elseif (!$startResult['success'] && ($startOutput === '' || $this->looksLikeDockerError($startOutput))) {
                return [
                    'success' => false,
                    'error' => 'Failed to start container: ' . ($startOutput ?: $startResult['error'])
                ];
            }
        }

        if ($shouldCreate) {
            // Create new container
            // Mount the app directory and Doki's data
            // Find the network the main Doki container is on
            $dokiNetwork = $this->getDokiNetwork(5);
            
            // Mount:
            // - /var/www/html:ro for code (read-only)
            // - /var/www/html/data:rw for database, sessions, app data (read-write overlay)
            // - /var/run/docker.sock for Docker execution capability
            // - HOST_APP_PATH env var for volume path translation in executeInContainer
            $runCmd = sprintf(
                'docker run -d --name %s ' .
                '--label "doki.app=%s" ' .
                '--label "doki.type=fpm" ' .
                '-e HOST_APP_PATH=%s ' .
                '-v %s:/var/www/html:ro ' .
                '-v %s/data:/var/www/html/data:rw ' .
                '-v /var/run/docker.sock:/var/run/docker.sock ' .
                '--network %s ' .
                '%s 2>&1',
                escapeshellarg($containerName),
                escapeshellarg($this->appId),
                escapeshellarg($hostAppPath),
                escapeshellarg($hostAppPath),
                escapeshellarg($hostAppPath),
                escapeshellarg($dokiNetwork),
                escapeshellarg($imageName)
            );
            
            error_log("AppImageBuilder[{$this->appId}]: Creating container: {$runCmd}");
            $runResult = $this->runDockerCommand($runCmd, 12);
            $runOutput = trim($runResult['output'] ?? '');
            error_log("AppImageBuilder[{$this->appId}]: Create result: {$runOutput}");
            
            if (!$runResult['success'] && $this->isNetworkNotFoundError($runOutput)) {
                // Retry with resolved network from running container (network IDs can go stale)
                $fallbackNetwork = $this->resolveNetworkFromContainer('php-command-executor', 3) ?: 'doki_default';
                $runCmdRetry = str_replace('--network ' . $dokiNetwork, '--network ' . $fallbackNetwork, $runCmd);
                $retryResult = $this->runDockerCommand($runCmdRetry, 12);
                $retryOutput = trim($retryResult['output'] ?? '');
                error_log("AppImageBuilder[{$this->appId}]: Create retry result: {$retryOutput}");
                if (!$retryResult['success']) {
                    return [
                        'success' => false,
                        'error' => 'Failed to create container: ' . trim($retryOutput ?: $retryResult['error'])
                    ];
                }
            } elseif (!$runResult['success'] && ($runOutput === '' || $this->looksLikeDockerError($runOutput))) {
                return [
                    'success' => false,
                    'error' => 'Failed to create container: ' . trim($runOutput ?: $runResult['error'])
                ];
            }
        }
        
        // Wait for container to be ready
        $maxWaitSeconds = 15;
        $startTime = time();
        while ((time() - $startTime) < $maxWaitSeconds) {
            if ($this->isContainerRunning(3)) {
                // Get container IP
                $ipCmd = "docker inspect -f '{{range.NetworkSettings.Networks}}{{.IPAddress}}{{end}}' {$containerName} 2>&1";
                $ipResult = $this->runDockerCommand($ipCmd, 3);
                $ip = trim($ipResult['output'] ?? '');
                
                return [
                    'success' => true,
                    'message' => 'Container started',
                    'containerName' => $containerName,
                    'ip' => $ip,
                    'port' => 9000
                ];
            }
            sleep(1);
        }

        // Gather diagnostics for timeout cases
        $statusCmd = "docker ps -a --filter 'name=^{$containerName}$' --format '{{.Status}}' 2>&1";
        $statusResult = $this->runDockerCommand($statusCmd, 5);
        $statusText = trim($statusResult['output'] ?? '');

        if ($statusText !== '' && stripos($statusText, 'up ') === 0) {
            // Container is actually running, but our earlier check missed it
            $ipCmd = "docker inspect -f '{{range.NetworkSettings.Networks}}{{.IPAddress}}{{end}}' {$containerName} 2>&1";
            $ipResult = $this->runDockerCommand($ipCmd, 3);
            $ip = trim($ipResult['output'] ?? '');

            return [
                'success' => true,
                'message' => 'Container started',
                'containerName' => $containerName,
                'ip' => $ip,
                'port' => 9000
            ];
        }

        $logs = '';
        if ($statusText !== '') {
            $logsCmd = "docker logs --tail 80 {$containerName} 2>&1";
            $logsResult = $this->runDockerCommand($logsCmd, 5);
            $logs = trim($logsResult['output'] ?? '');
        }

        return [
            'success' => false,
            'error' => 'Container failed to start within timeout',
            'details' => trim($statusText),
            'logs' => $logs
        ];
    }
    
    /**
     * Get the Docker network that the main Doki container is on
     */
    private function getDokiNetwork(int $timeoutSeconds = 2): string {
        // Prefer actual network name from the running php-command-executor container.
        $resolved = $this->resolveNetworkFromContainer('php-command-executor', $timeoutSeconds);
        if (!empty($resolved)) {
            error_log("AppImageBuilder: Resolved Doki network from container: {$resolved}");
            return $resolved;
        }

        // Try to get network from the main Doki container (php-command-executor)
        $networkCmd = "docker inspect php-command-executor --format '{{range \$k, \$v := .NetworkSettings.Networks}}{{\$k}}{{end}}' 2>&1";
        $networkResult = $this->runDockerCommand($networkCmd, $timeoutSeconds);
        $network = $this->extractNetworkName($networkResult['output'] ?? '');
        
        if (!empty($network)) {
            error_log("AppImageBuilder: Found Doki network: {$network}");
            return $network;
        }
        
        // Fallback: try to find by label
        $labelCmd = "docker ps --filter 'label=com.docker.compose.service=php-app' --format '{{.ID}}' 2>&1";
        $labelResult = $this->runDockerCommand($labelCmd, $timeoutSeconds);
        $containerId = trim($labelResult['output'] ?? '');
        
        if (!empty($containerId)) {
            $networkCmd = "docker inspect {$containerId} --format '{{range \$k, \$v := .NetworkSettings.Networks}}{{\$k}}{{end}}' 2>&1";
            $networkResult = $this->runDockerCommand($networkCmd, $timeoutSeconds);
            $network = $this->extractNetworkName($networkResult['output'] ?? '');
            if (!empty($network)) {
                return $network;
            }
        }
        
        // Default fallback
        return 'doki_default';
    }
    
    /**
     * Stop the FPM container
     */
    public function stopContainer(): array {
        $containerName = $this->getContainerName();
        
        if (!$this->isContainerRunning()) {
            return ['success' => true, 'message' => 'Container not running'];
        }
        
        $stopCmd = "docker stop {$containerName} 2>&1";
        $result = $this->runDockerCommand($stopCmd, 10);
        
        // Some Docker environments return non-zero exit codes even on success.
        if (!$result['success']) {
            $output = trim($result['output'] ?? '');
            if ($output === $containerName) {
                $result['success'] = true;
                $result['error'] = '';
            }
        }

        return [
            'success' => $result['success'],
            'message' => $result['success'] ? 'Container stopped' : 'Failed to stop container',
            'output' => trim($result['output'] ?? '')
        ];
    }
    
    /**
     * Get container connection info
     */
    public function getContainerInfo(int $timeoutSeconds = 2): ?array {
        if (!$this->isContainerRunning($timeoutSeconds)) {
            return null;
        }
        
        $containerName = $this->getContainerName();
        $ipCmd = "docker inspect -f '{{range.NetworkSettings.Networks}}{{.IPAddress}}{{end}}' {$containerName} 2>&1";
        $result = $this->runDockerCommand($ipCmd, $timeoutSeconds);
        $ip = trim($result['output'] ?? '');
        if (!$result['success'] && ($ip === '' || $this->looksLikeDockerError($ip))) {
            if (!empty($result['timeout'])) {
                error_log("AppImageBuilder[{$this->appId}]: docker inspect (ip) timed out");
            }
            return null;
        }
        
        if (empty($ip)) {
            return null;
        }
        
        return [
            'containerName' => $containerName,
            'ip' => $ip,
            'port' => 9000,
            'address' => "{$ip}:9000"
        ];
    }
    
    /**
     * Update build status
     */
    private function updateStatus(string $status, string $message, ?string $details = null): void {
        $statusFile = $this->statusDir . "/{$this->appId}.json";
        
        $data = [
            'status' => $status,
            'message' => $message,
            'details' => $details,
            'timestamp' => date('c'),
            'imageName' => $this->getImageName(),
            'runtimeSignature' => $this->getRuntimeSignature(),
        ];
        
        file_put_contents($statusFile, json_encode($data, JSON_PRETTY_PRINT));
    }
    
    /**
     * Get current build status
     */
    public function getBuildStatus(): array {
        if (!$this->needsCustomImage()) {
            return ['status' => 'not_needed', 'message' => 'No custom runtime required'];
        }

        $statusFile = $this->statusDir . "/{$this->appId}.json";
        $currentSignature = $this->getRuntimeSignature();
        
        if (!file_exists($statusFile)) {
            if ($this->imageExists()) {
                return [
                    'status' => 'ready',
                    'message' => 'Image ready',
                    'runtimeSignature' => $currentSignature,
                ];
            }
            return [
                'status' => 'not_started',
                'message' => 'Build not started',
                'runtimeSignature' => $currentSignature,
            ];
        }

        $data = json_decode(file_get_contents($statusFile), true);
        if (!is_array($data)) {
            $data = [];
        }

        if (($data['status'] ?? '') !== 'stale'
            && ($data['status'] ?? '') !== 'building'
            && !empty($data['runtimeSignature'])
            && $currentSignature !== null
            && $data['runtimeSignature'] !== $currentSignature) {
            $data['status'] = 'stale';
            $data['message'] = 'Runtime requirements changed. Rebuild required.';
            $data['stale'] = true;
            $data['currentRuntimeSignature'] = $currentSignature;
        }

        return $data;
    }
    
    /**
     * Get full status including container state
     */
    public function getStatus(): array {
        $needs = $this->needsCustomImage();
        $buildStatus = $this->getBuildStatus();
        $runtimeStale = ($buildStatus['status'] ?? '') === 'stale';
        $imageExists = $needs ? $this->imageExists() : false;
        $containerRunning = $needs ? $this->isContainerRunning() : false;
        if ($containerRunning && $needs) {
            $hostAppPath = $this->resolveHostAppPath();
            if ($hostAppPath !== '/var/www/html' && !$this->containerHasExpectedMounts($hostAppPath)) {
                $containerRunning = false;
                $buildStatus['message'] = 'Container mounts are stale and need to be recreated.';
            }
        }
        $containerInfo = $containerRunning ? $this->getContainerInfo() : null;
        
        return [
            'needsCustomImage' => $needs,
            'imageExists' => $imageExists,
            'imageName' => $needs ? $this->getImageName() : null,
            'containerRunning' => $containerRunning,
            'containerInfo' => $containerInfo,
            'ready' => !$needs || (!$runtimeStale && $imageExists && $containerRunning),
            'runtimeStale' => $runtimeStale,
            'buildStatus' => $buildStatus
        ];
    }
    
    /**
     * Remove container (stop and delete)
     */
    public function removeContainer(): array {
        $containerName = $this->getContainerName();
        
        // Stop if running
        if ($this->isContainerRunning()) {
            $this->runDockerCommand("docker stop {$containerName} 2>&1", 10);
        }
        
        // Remove container
        $result = $this->runDockerCommand("docker rm {$containerName} 2>&1", 10);
        
        return [
            'success' => $result['success'] || strpos($result['output'] ?? '', 'No such container') !== false,
            'message' => 'Container removed',
            'output' => trim($result['output'] ?? '')
        ];
    }
    
    /**
     * Remove the custom image
     */
    public function removeImage(): array {
        // Remove container first
        $this->removeContainer();

        $this->removeStatusFile();
        
        // Remove image
        $imageName = $this->getImageName();
        if (!$this->imageExists()) {
            return ['success' => true, 'message' => 'Image does not exist'];
        }
        
        $removeCmd = sprintf('docker rmi %s 2>&1', escapeshellarg($imageName));
        $result = $this->runDockerCommand($removeCmd, 10);
        
        return [
            'success' => $result['success'],
            'output' => $result['output'] ?? ''
        ];
    }
    
    /**
     * Full cleanup - remove container, image, and status
     */
    public function cleanup(): array {
        $results = [];
        
        $results['container'] = $this->removeContainer();
        $results['image'] = $this->removeImage();
        
        return [
            'success' => $results['container']['success'] && $results['image']['success'],
            'results' => $results
        ];
    }
}
