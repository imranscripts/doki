<?php
/**
 * LoggerTargetFileBrowser.php - Admin-only filesystem browsing for Logger
 */

require_once __DIR__ . '/ConfigManager.php';
require_once __DIR__ . '/LoggerTargetResolver.php';

class LoggerTargetFileBrowser {
    private ConfigManager $config;
    private LoggerTargetResolver $resolver;

    public function __construct(?ConfigManager $config = null, ?LoggerTargetResolver $resolver = null) {
        $this->config = $config ?? new ConfigManager();
        $this->resolver = $resolver ?? new LoggerTargetResolver($this->config);
    }

    public function browseTarget(string $targetId, string $path = '/', array $options = []): array {
        $target = $this->config->getTarget($targetId);
        if (!$this->resolver->isLogCapableTarget($target)) {
            return $this->error("Target '{$targetId}' is not log-capable or does not exist");
        }

        return $this->browseResolvedTarget($target, $path, $options);
    }

    public function browseEnvironmentTarget(string $environmentId, string $environmentTargetKey, string $path = '/', array $options = []): array {
        $resolved = $this->resolver->resolveEnvironmentTarget($environmentId, $environmentTargetKey);
        if ($resolved === null) {
            return $this->error("No log-capable target resolved for environment key '{$environmentTargetKey}'");
        }

        $target = $this->config->getTarget($resolved['id']);
        if (!is_array($target)) {
            return $this->error("Resolved target '{$resolved['id']}' was not found");
        }

        $result = $this->browseResolvedTarget($target, $path, $options);
        $result['environment'] = [
            'id' => $environmentId,
            'targetKey' => $environmentTargetKey,
        ];
        return $result;
    }

    public function previewTarget(string $targetId, string $path, array $options = []): array {
        $target = $this->config->getTarget($targetId);
        if (!$this->resolver->isLogCapableTarget($target)) {
            return $this->error("Target '{$targetId}' is not log-capable or does not exist");
        }

        return $this->previewResolvedTarget($target, $path, $options);
    }

    public function previewEnvironmentTarget(string $environmentId, string $environmentTargetKey, string $path, array $options = []): array {
        $resolved = $this->resolver->resolveEnvironmentTarget($environmentId, $environmentTargetKey);
        if ($resolved === null) {
            return $this->error("No log-capable target resolved for environment key '{$environmentTargetKey}'");
        }

        $target = $this->config->getTarget($resolved['id']);
        if (!is_array($target)) {
            return $this->error("Resolved target '{$resolved['id']}' was not found");
        }

        $result = $this->previewResolvedTarget($target, $path, $options);
        $result['environment'] = [
            'id' => $environmentId,
            'targetKey' => $environmentTargetKey,
        ];
        return $result;
    }

    public function browseResolvedTarget(array $target, string $path = '/', array $options = []): array {
        $type = (string)($target['type'] ?? '');
        $path = $this->normalizeBrowsePath($path);

        return match ($type) {
            'local' => $this->browseLocal($target, $path),
            'ssh' => $this->browseSsh($target, $path),
            'docker-exec' => $this->browseDockerExecTarget($target, $path),
            'docker' => $this->browseDockerTarget($target, $path, $options),
            default => $this->error("Unsupported target type '{$type}'"),
        };
    }

    public function previewResolvedTarget(array $target, string $path, array $options = []): array {
        $type = (string)($target['type'] ?? '');
        $path = $this->normalizeBrowsePath($path);
        $limit = $this->previewLimit($options);

        return match ($type) {
            'local' => $this->previewLocal($target, $path, $limit),
            'ssh' => $this->previewSsh($target, $path, $limit),
            'docker-exec' => $this->previewDockerExecTarget($target, $path, $limit),
            'docker' => $this->previewDockerTarget($target, $path, $limit),
            default => $this->error("Unsupported target type '{$type}'"),
        };
    }

    private function browseLocal(array $target, string $path): array {
        $path = $this->normalizeBrowsePath($path);
        if (!file_exists($path)) {
            return $this->error("Path does not exist: {$path}", $target, $path);
        }
        if (!is_dir($path)) {
            return $this->error("Path is not a directory: {$path}", $target, $path);
        }
        if (!is_readable($path)) {
            return $this->error("Directory is not readable: {$path}", $target, $path);
        }

        $names = @scandir($path);
        if (!is_array($names)) {
            return $this->error("Failed to list directory: {$path}", $target, $path);
        }

        $entries = [];
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $fullPath = rtrim($path, '/') . '/' . $name;
            $entries[] = [
                'name' => $name,
                'path' => $this->normalizeBrowsePath($fullPath),
                'type' => $this->localEntryType($fullPath),
                'readable' => is_readable($fullPath),
                'size' => is_file($fullPath) ? @filesize($fullPath) : null,
                'modifiedAt' => $this->formatTimestamp(@filemtime($fullPath) ?: null),
            ];
        }

        return $this->success($target, $path, $entries);
    }

    private function browseSsh(array $target, string $path): array {
        $connection = $target['connection'] ?? [];
        if (!is_array($connection)) {
            $connection = [];
        }

        $host = trim((string)($connection['host'] ?? ''));
        $user = trim((string)($connection['user'] ?? ''));
        $port = (int)($connection['port'] ?? 22);

        if ($host === '' || $user === '') {
            return $this->error('SSH target requires host and user', $target, $path);
        }

        $remoteCommand = 'sh -c ' . escapeshellarg($this->remoteListScript()) . ' -- ' . escapeshellarg($path);
        $bundle = $this->buildSshCommand($connection, $remoteCommand);
        if (!$bundle['success']) {
            return $this->error($bundle['error'], $target, $path);
        }

        $result = $this->runCommand($bundle['cmd'], 12, $bundle['env']);
        $this->cleanupPaths($bundle['cleanup']);

        if (!$result['success']) {
            return $this->error($result['output'] ?: 'SSH browse failed', $target, $path, $result['exitCode']);
        }

        return $this->parseRemoteListing($target, $path, $result['output']);
    }

    private function browseDockerExecTarget(array $target, string $path): array {
        $connection = $target['connection'] ?? [];
        if (!is_array($connection)) {
            $connection = [];
        }

        $container = trim((string)($connection['container'] ?? ''));
        if ($container === '') {
            return $this->error('Docker exec target requires a container', $target, $path);
        }

        return $this->browseDockerContainer($target, $container, $path, []);
    }

    private function browseDockerTarget(array $target, string $path, array $options): array {
        $path = $this->normalizeBrowsePath($path);
        $container = trim((string)($options['container'] ?? ''));
        $innerPath = $path;

        if ($container === '' && preg_match('#^/containers/([^/]+)(/.*)?$#', $path, $matches) === 1) {
            $container = $matches[1];
            $innerPath = $matches[2] ?? '/';
        }

        if ($container !== '') {
            return $this->browseDockerContainer($target, $container, $innerPath, $this->buildDockerEnv($target));
        }

        $docker = $this->buildDockerCommandPrefix($target);
        if (!$docker['success']) {
            return $this->error($docker['error'], $target, $path);
        }

        $cmd = array_merge($docker['cmd'], ['ps', '--format', '{{.ID}}\t{{.Names}}\t{{.Image}}\t{{.Status}}']);
        $result = $this->runCommand($cmd, 10, $docker['env']);
        $this->cleanupPaths($docker['cleanup']);

        if (!$result['success']) {
            return $this->error($result['output'] ?: 'Failed to list Docker containers', $target, $path, $result['exitCode']);
        }

        $entries = [];
        foreach (preg_split('/\R/', trim($result['output'])) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            $parts = explode("\t", $line, 4);
            $id = $parts[0] ?? '';
            if ($id === '') {
                continue;
            }
            $name = $parts[1] ?? $id;
            $entries[] = [
                'name' => $name,
                'path' => '/containers/' . $id,
                'type' => 'container',
                'readable' => true,
                'size' => null,
                'modifiedAt' => null,
                'details' => array_filter([$parts[2] ?? null, $parts[3] ?? null]),
                'containerId' => $id,
                'containerName' => $name,
            ];
        }

        return $this->success($target, '/', $entries, ['virtualRoot' => 'containers']);
    }

    private function browseDockerContainer(array $target, string $container, string $path, array $dockerBundle): array {
        if ($dockerBundle === []) {
            $dockerBundle = $this->buildDockerCommandPrefix($target);
        }
        if (!$dockerBundle['success']) {
            return $this->error($dockerBundle['error'], $target, $path);
        }

        $remoteCommand = 'sh -c ' . escapeshellarg($this->remoteListScript()) . ' -- ' . escapeshellarg($path);
        $cmd = array_merge($dockerBundle['cmd'], ['exec', $container, 'sh', '-c', $remoteCommand]);
        $result = $this->runCommand($cmd, 12, $dockerBundle['env']);
        $this->cleanupPaths($dockerBundle['cleanup']);

        if (!$result['success']) {
            return $this->error($result['output'] ?: 'Docker browse failed', $target, $path, $result['exitCode'], [
                'container' => $container,
            ]);
        }

        $parsed = $this->parseRemoteListing($target, $path, $result['output']);
        $parsed['container'] = $container;
        if (($target['type'] ?? '') === 'docker') {
            foreach ($parsed['entries'] as &$entry) {
                $entry['path'] = '/containers/' . $container . $entry['path'];
            }
            unset($entry);
            $parsed['path'] = '/containers/' . $container . $path;
            $parsed['parentPath'] = $path === '/' ? '/' : '/containers/' . $container . $this->parentPath($path);
        }

        return $parsed;
    }

    private function previewLocal(array $target, string $path, int $limit): array {
        if (!file_exists($path)) {
            return $this->error("Path does not exist: {$path}", $target, $path);
        }
        if (is_dir($path)) {
            return $this->error("Path is a directory: {$path}", $target, $path);
        }
        if (!is_readable($path)) {
            return $this->error("File is not readable: {$path}", $target, $path);
        }

        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            return $this->error("Failed to open file: {$path}", $target, $path);
        }

        $content = (string)fread($handle, $limit);
        fclose($handle);

        $size = is_file($path) ? @filesize($path) : null;
        return $this->previewSuccess($target, $path, $content, is_int($size) ? $size : null, $limit);
    }

    private function previewSsh(array $target, string $path, int $limit): array {
        $connection = $target['connection'] ?? [];
        if (!is_array($connection)) {
            $connection = [];
        }

        $remoteCommand = 'sh -c ' . escapeshellarg($this->remotePreviewScript()) . ' -- ' . escapeshellarg($path) . ' ' . escapeshellarg((string)$limit);
        $bundle = $this->buildSshCommand($connection, $remoteCommand);
        if (!$bundle['success']) {
            return $this->error($bundle['error'], $target, $path);
        }

        $result = $this->runCommand($bundle['cmd'], 12, $bundle['env']);
        $this->cleanupPaths($bundle['cleanup']);

        if (!$result['success']) {
            return $this->error($result['output'] ?: 'SSH preview failed', $target, $path, $result['exitCode']);
        }

        return $this->parseRemotePreview($target, $path, $result['output'], $limit);
    }

    private function previewDockerExecTarget(array $target, string $path, int $limit): array {
        $connection = $target['connection'] ?? [];
        if (!is_array($connection)) {
            $connection = [];
        }

        $container = trim((string)($connection['container'] ?? ''));
        if ($container === '') {
            return $this->error('Docker exec target requires a container', $target, $path);
        }

        return $this->previewDockerContainer($target, $container, $path, $limit, []);
    }

    private function previewDockerTarget(array $target, string $path, int $limit): array {
        $path = $this->normalizeBrowsePath($path);
        $container = '';
        $innerPath = $path;

        if (preg_match('#^/containers/([^/]+)(/.*)?$#', $path, $matches) === 1) {
            $container = $matches[1];
            $innerPath = $matches[2] ?? '/';
        }

        if ($container === '') {
            return $this->error('Choose a file inside a Docker container to preview it', $target, $path);
        }

        $preview = $this->previewDockerContainer($target, $container, $innerPath, $limit, $this->buildDockerEnv($target));
        if (($preview['success'] ?? false) && ($target['type'] ?? '') === 'docker') {
            $preview['path'] = '/containers/' . $container . $innerPath;
            $preview['container'] = $container;
        }
        return $preview;
    }

    private function previewDockerContainer(array $target, string $container, string $path, int $limit, array $dockerBundle): array {
        if ($dockerBundle === []) {
            $dockerBundle = $this->buildDockerCommandPrefix($target);
        }
        if (!$dockerBundle['success']) {
            return $this->error($dockerBundle['error'], $target, $path);
        }

        $remoteCommand = 'sh -c ' . escapeshellarg($this->remotePreviewScript()) . ' -- ' . escapeshellarg($path) . ' ' . escapeshellarg((string)$limit);
        $cmd = array_merge($dockerBundle['cmd'], ['exec', $container, 'sh', '-c', $remoteCommand]);
        $result = $this->runCommand($cmd, 12, $dockerBundle['env']);
        $this->cleanupPaths($dockerBundle['cleanup']);

        if (!$result['success']) {
            return $this->error($result['output'] ?: 'Docker preview failed', $target, $path, $result['exitCode'], [
                'container' => $container,
            ]);
        }

        $preview = $this->parseRemotePreview($target, $path, $result['output'], $limit);
        $preview['container'] = $container;
        return $preview;
    }

    private function parseRemoteListing(array $target, string $path, string $output): array {
        $lines = preg_split('/\R/', trim($output)) ?: [];
        $entries = [];
        $errors = [];

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            if (str_starts_with($line, 'ERR' . "\t")) {
                $errors[] = substr($line, 4);
                continue;
            }

            $parts = explode("\t", $line, 6);
            if (count($parts) < 5 || $parts[0] !== 'ENTRY') {
                continue;
            }

            $name = base64_decode($parts[1], true);
            if (!is_string($name)) {
                continue;
            }

            $entryPath = $this->joinPath($path, $name);
            $entries[] = [
                'name' => $name,
                'path' => $entryPath,
                'type' => $parts[2],
                'readable' => $parts[3] === '1',
                'size' => $parts[4] !== '' && ctype_digit($parts[4]) ? (int)$parts[4] : null,
                'modifiedAt' => isset($parts[5]) && ctype_digit($parts[5]) ? $this->formatTimestamp((int)$parts[5]) : null,
            ];
        }

        if ($errors !== []) {
            return $this->error(implode(' | ', $errors), $target, $path);
        }

        return $this->success($target, $path, $entries);
    }

    private function parseRemotePreview(array $target, string $path, string $output, int $limit): array {
        $line = trim($output);
        if ($line === '') {
            return $this->error('Preview returned no data', $target, $path);
        }
        if (str_starts_with($line, 'ERR' . "\t")) {
            return $this->error(substr($line, 4), $target, $path);
        }

        $parts = explode("\t", $line, 4);
        if (count($parts) < 4 || $parts[0] !== 'PREVIEW') {
            return $this->error('Preview returned an unexpected response', $target, $path);
        }

        $content = base64_decode($parts[1], true);
        if (!is_string($content)) {
            return $this->error('Preview data could not be decoded', $target, $path);
        }

        $size = ctype_digit($parts[2]) ? (int)$parts[2] : null;
        $truncated = $parts[3] === '1';
        return $this->previewSuccess($target, $path, $content, $size, $limit, [
            'truncated' => $truncated,
        ]);
    }

    private function remoteListScript(): string {
        return <<<'SH'
p="$1"
if [ -z "$p" ]; then p="/"; fi
if [ ! -e "$p" ]; then printf 'ERR\tPath does not exist: %s\n' "$p"; exit 2; fi
if [ ! -d "$p" ]; then printf 'ERR\tPath is not a directory: %s\n' "$p"; exit 3; fi
if [ ! -r "$p" ]; then printf 'ERR\tDirectory is not readable: %s\n' "$p"; exit 4; fi
for item in "$p"/* "$p"/.[!.]* "$p"/..?*; do
  [ -e "$item" ] || continue
  name=${item##*/}
  type=file
  [ -d "$item" ] && type=dir
  [ -L "$item" ] && type=symlink
  readable=0
  [ -r "$item" ] && readable=1
  size=
  if [ -f "$item" ]; then size=$(wc -c < "$item" 2>/dev/null | tr -d ' '); fi
  mtime=$(stat -c %Y "$item" 2>/dev/null || stat -f %m "$item" 2>/dev/null || printf '')
  encoded=$(printf '%s' "$name" | base64 | tr -d '\n')
  printf 'ENTRY\t%s\t%s\t%s\t%s\t%s\n' "$encoded" "$type" "$readable" "$size" "$mtime"
done
SH;
    }

    private function remotePreviewScript(): string {
        return <<<'SH'
p="$1"
limit="$2"
case "$limit" in
  ''|*[!0-9]*) limit=8192 ;;
esac
if [ -z "$p" ]; then printf 'ERR\tPath is required\n'; exit 2; fi
if [ ! -e "$p" ]; then printf 'ERR\tPath does not exist: %s\n' "$p"; exit 2; fi
if [ -d "$p" ]; then printf 'ERR\tPath is a directory: %s\n' "$p"; exit 3; fi
if [ ! -r "$p" ]; then printf 'ERR\tFile is not readable: %s\n' "$p"; exit 4; fi
size=$(wc -c < "$p" 2>/dev/null | tr -d ' ')
case "$size" in
  ''|*[!0-9]*) size= ;;
esac
truncated=0
if [ -n "$size" ] && [ "$size" -gt "$limit" ]; then truncated=1; fi
encoded=$(dd if="$p" bs=1 count="$limit" 2>/dev/null | base64 | tr -d '\n')
printf 'PREVIEW\t%s\t%s\t%s\n' "$encoded" "$size" "$truncated"
SH;
    }

    private function buildSshCommand(array $connection, string $remoteCommand): array {
        $host = trim((string)($connection['host'] ?? ''));
        $user = trim((string)($connection['user'] ?? ''));
        $port = (int)($connection['port'] ?? 22);
        $keyRef = $connection['keyRef'] ?? $connection['keySecret'] ?? null;
        $passwordRef = $connection['passwordRef'] ?? $connection['passwordSecret'] ?? null;

        if ($host === '' || $user === '') {
            return ['success' => false, 'error' => 'SSH target requires host and user'];
        }

        $cleanup = [];
        $env = [];
        $cmd = [];

        $passwordValue = null;
        $passwordPath = null;
        if (is_string($passwordRef) && trim($passwordRef) !== '') {
            $passwordValue = $this->config->getSecret($passwordRef);
            if ($passwordValue === null) {
                return ['success' => false, 'error' => "SSH password secret '{$passwordRef}' not found"];
            }

            if ($this->commandExists('sshpass')) {
                $passwordPath = $this->writeTempSecretFile($passwordValue, 'doki-logger-ssh-pass-');
                $cleanup[] = $passwordPath;
                $cmd[] = 'sshpass';
                $cmd[] = '-f';
                $cmd[] = $passwordPath;
            } else {
                $askpass = $this->prepareSshAskpass($passwordValue);
                $env = array_merge($env, $askpass['env']);
                $cleanup = array_merge($cleanup, $askpass['cleanup']);
            }
        }

        $cmd[] = 'ssh';
        $cmd[] = '-o';
        $cmd[] = 'StrictHostKeyChecking=no';
        $cmd[] = '-o';
        $cmd[] = 'UserKnownHostsFile=/dev/null';
        $cmd[] = '-o';
        $cmd[] = 'ConnectTimeout=8';
        $cmd[] = '-o';
        $cmd[] = 'BatchMode=' . ($passwordValue !== null ? 'no' : 'yes');
        if ($passwordValue !== null) {
            $cmd[] = '-o';
            $cmd[] = 'PreferredAuthentications=publickey,password,keyboard-interactive';
            $cmd[] = '-o';
            $cmd[] = 'PasswordAuthentication=yes';
        }
        $cmd[] = '-p';
        $cmd[] = (string)$port;

        if (is_string($keyRef) && trim($keyRef) !== '') {
            $keyValue = $this->config->getSecret($keyRef);
            if (!$keyValue) {
                $this->cleanupPaths($cleanup);
                return ['success' => false, 'error' => "SSH key secret '{$keyRef}' not found"];
            }
            $keyPath = $this->writeTempSecretFile($keyValue, 'doki-logger-ssh-key-');
            $cleanup[] = $keyPath;
            $cmd[] = '-i';
            $cmd[] = $keyPath;
            $cmd[] = '-o';
            $cmd[] = 'IdentitiesOnly=yes';
        }

        $cmd[] = $user . '@' . $host;
        $cmd[] = $remoteCommand;

        return [
            'success' => true,
            'cmd' => $cmd,
            'env' => $env,
            'cleanup' => $cleanup,
        ];
    }

    private function buildDockerCommandPrefix(array $target): array {
        $connection = $target['connection'] ?? [];
        if (!is_array($connection)) {
            $connection = [];
        }

        $cmd = ['docker'];
        $env = [];
        $cleanup = [];

        if (!empty($connection['socket'])) {
            $cmd[] = '-H';
            $cmd[] = 'unix://' . (string)$connection['socket'];
        } elseif (!empty($connection['host'])) {
            $port = $connection['port'] ?? 2375;
            $cmd[] = '-H';
            $cmd[] = 'tcp://' . (string)$connection['host'] . ':' . (string)$port;
        } elseif (!empty($connection['sshTargetId'])) {
            $sshTarget = $this->config->getTarget((string)$connection['sshTargetId']);
            if (!is_array($sshTarget) || ($sshTarget['type'] ?? '') !== 'ssh') {
                return ['success' => false, 'error' => "Docker SSH target '{$connection['sshTargetId']}' not found"];
            }
            $ssh = $sshTarget['connection'] ?? [];
            $dockerHost = $this->dockerSshHost($ssh);
            if ($dockerHost === null) {
                return ['success' => false, 'error' => 'Docker SSH target requires host and user'];
            }
            $env['DOCKER_HOST'] = $dockerHost;
            $secretBundle = $this->buildDockerSshSecrets($ssh);
            if (!$secretBundle['success']) {
                return $secretBundle;
            }
            $env = array_merge($env, $secretBundle['env']);
            $cleanup = array_merge($cleanup, $secretBundle['cleanup']);
        } elseif (!empty($connection['ssh']) && is_array($connection['ssh'])) {
            $dockerHost = $this->dockerSshHost($connection['ssh']);
            if ($dockerHost === null) {
                return ['success' => false, 'error' => 'Docker SSH connection requires host and user'];
            }
            $env['DOCKER_HOST'] = $dockerHost;
            $secretBundle = $this->buildDockerSshSecrets($connection['ssh']);
            if (!$secretBundle['success']) {
                return $secretBundle;
            }
            $env = array_merge($env, $secretBundle['env']);
            $cleanup = array_merge($cleanup, $secretBundle['cleanup']);
        }

        return [
            'success' => true,
            'cmd' => $cmd,
            'env' => $env,
            'cleanup' => $cleanup,
        ];
    }

    private function buildDockerEnv(array $target): array {
        return $this->buildDockerCommandPrefix($target);
    }

    private function dockerSshHost(array $ssh): ?string {
        $host = trim((string)($ssh['host'] ?? ''));
        $user = trim((string)($ssh['user'] ?? ''));
        if ($host === '' || $user === '') {
            return null;
        }
        $port = (int)($ssh['port'] ?? 22);
        return 'ssh://' . $user . '@' . $host . ($port !== 22 ? ':' . $port : '');
    }

    private function buildDockerSshSecrets(array $ssh): array {
        $env = [];
        $cleanup = [];

        $keyRef = $ssh['keyRef'] ?? $ssh['keySecret'] ?? null;
        if (is_string($keyRef) && trim($keyRef) !== '') {
            $keyValue = $this->config->getSecret($keyRef);
            if (!$keyValue) {
                return ['success' => false, 'error' => "SSH key secret '{$keyRef}' not found"];
            }
            $keyPath = $this->writeTempSecretFile($keyValue, 'doki-logger-docker-ssh-key-');
            $cleanup[] = $keyPath;
            $sshCommand = 'ssh -i ' . escapeshellarg($keyPath) . ' -o IdentitiesOnly=yes -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null';
            $env['DOCKER_SSH_COMMAND'] = $sshCommand;
            $env['GIT_SSH_COMMAND'] = $sshCommand;
        }

        $passwordRef = $ssh['passwordRef'] ?? $ssh['passwordSecret'] ?? null;
        if (is_string($passwordRef) && trim($passwordRef) !== '') {
            $passwordValue = $this->config->getSecret($passwordRef);
            if ($passwordValue === null) {
                $this->cleanupPaths($cleanup);
                return ['success' => false, 'error' => "SSH password secret '{$passwordRef}' not found"];
            }
            $askpass = $this->prepareSshAskpass($passwordValue);
            $env = array_merge($env, $askpass['env']);
            $cleanup = array_merge($cleanup, $askpass['cleanup']);
        }

        return ['success' => true, 'env' => $env, 'cleanup' => $cleanup];
    }

    private function runCommand(array $cmd, int $timeout = 10, array $env = []): array {
        $timeout = max(1, $timeout);
        $command = $this->buildShellCommand($cmd, $env) . ' 2>&1';
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open(['/bin/sh', '-lc', $command], $descriptors, $pipes);
        if (!is_resource($process)) {
            return ['success' => false, 'output' => 'Unable to start command', 'exitCode' => 1];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output = '';
        $deadline = microtime(true) + $timeout;
        $timedOut = false;
        $resolvedExitCode = null;

        while (true) {
            $status = proc_get_status($process);
            $running = !empty($status['running']);
            if (!$running && isset($status['exitcode']) && $status['exitcode'] >= 0) {
                $resolvedExitCode = (int)$status['exitcode'];
            }

            foreach ([1, 2] as $index) {
                $chunk = stream_get_contents($pipes[$index]);
                if ($chunk !== false && $chunk !== '') {
                    $output .= $chunk;
                }
            }

            if (!$running) {
                break;
            }

            if (microtime(true) >= $deadline) {
                $timedOut = true;
                @proc_terminate($process);
                usleep(100000);
                $status = proc_get_status($process);
                if (!empty($status['running'])) {
                    @proc_terminate($process, 9);
                }
                break;
            }

            usleep(25000);
        }

        foreach ([1, 2] as $index) {
            $chunk = stream_get_contents($pipes[$index]);
            if ($chunk !== false && $chunk !== '') {
                $output .= $chunk;
            }
            fclose($pipes[$index]);
        }

        $exitCode = proc_close($process);
        if ($exitCode === -1 && $resolvedExitCode !== null) {
            $exitCode = $resolvedExitCode;
        }

        if ($timedOut) {
            $exitCode = 124;
            $output = trim($output . "\nCommand timed out after {$timeout}s");
        }

        return [
            'success' => $exitCode === 0,
            'output' => trim($output),
            'exitCode' => $exitCode,
        ];
    }

    private function buildShellCommand(array $cmd, array $env = []): string {
        $parts = [];
        foreach ($env as $key => $value) {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string)$key)) {
                continue;
            }
            $parts[] = $key . '=' . escapeshellarg((string)$value);
        }
        foreach ($cmd as $part) {
            $parts[] = escapeshellarg((string)$part);
        }
        return implode(' ', $parts);
    }

    private function commandExists(string $command): bool {
        $result = $this->runCommand(['sh', '-c', 'command -v ' . escapeshellarg($command)], 4);
        return $result['success'];
    }

    private function writeTempSecretFile(string $content, string $prefix): string {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        file_put_contents($path, $content);
        chmod($path, 0600);
        return $path;
    }

    private function prepareSshAskpass(string $password): array {
        $passwordPath = $this->writeTempSecretFile($password, 'doki-logger-ssh-pass-');
        $askpassPath = tempnam(sys_get_temp_dir(), 'doki-logger-ssh-askpass-');
        $script = "#!/bin/sh\ncat " . escapeshellarg($passwordPath) . "\n";
        file_put_contents($askpassPath, $script);
        chmod($askpassPath, 0700);

        return [
            'env' => [
                'SSH_ASKPASS' => $askpassPath,
                'SSH_ASKPASS_REQUIRE' => 'force',
                'DISPLAY' => 'doki',
            ],
            'cleanup' => [$passwordPath, $askpassPath],
        ];
    }

    private function cleanupPaths(array $paths): void {
        foreach ($paths as $path) {
            if (is_string($path) && $path !== '') {
                @unlink($path);
            }
        }
    }

    private function normalizeBrowsePath(string $path): string {
        $path = trim($path);
        if ($path === '') {
            return '/';
        }

        $path = str_replace('\\', '/', $path);
        if ($path[0] !== '/') {
            $path = '/' . $path;
        }

        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }

        return '/' . implode('/', $parts);
    }

    private function joinPath(string $base, string $name): string {
        return $this->normalizeBrowsePath(rtrim($base, '/') . '/' . $name);
    }

    private function parentPath(string $path): string {
        $path = $this->normalizeBrowsePath($path);
        if ($path === '/') {
            return '/';
        }
        $parent = dirname($path);
        return $parent === '\\' || $parent === '.' ? '/' : $this->normalizeBrowsePath($parent);
    }

    private function localEntryType(string $path): string {
        if (is_link($path)) {
            return 'symlink';
        }
        if (is_dir($path)) {
            return 'dir';
        }
        if (is_file($path)) {
            return 'file';
        }
        return 'other';
    }

    private function formatTimestamp($timestamp): ?string {
        if (!is_int($timestamp) || $timestamp <= 0) {
            return null;
        }
        return gmdate('c', $timestamp);
    }

    private function previewLimit(array $options): int {
        $limit = (int)($options['limit'] ?? 8192);
        if ($limit < 1024) {
            return 1024;
        }
        if ($limit > 65536) {
            return 65536;
        }
        return $limit;
    }

    private function previewSuccess(array $target, string $path, string $content, ?int $size, int $limit, array $extra = []): array {
        $binary = str_contains($content, "\0");
        $previewable = !$binary;
        if ($previewable && preg_match('//u', $content) !== 1) {
            $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $content);
            $content = is_string($converted) ? $converted : '';
        }

        $truncated = $size !== null ? $size > strlen($content) && $size > $limit : strlen($content) >= $limit;

        return array_merge([
            'success' => true,
            'target' => [
                'id' => (string)($target['id'] ?? ''),
                'name' => (string)($target['name'] ?? $target['id'] ?? ''),
                'type' => (string)($target['type'] ?? ''),
            ],
            'path' => $path,
            'size' => $size,
            'limit' => $limit,
            'truncated' => $truncated,
            'previewable' => $previewable,
            'content' => $previewable ? $content : '',
            'message' => $previewable ? null : 'Binary files cannot be previewed.',
        ], $extra);
    }

    private function success(array $target, string $path, array $entries, array $extra = []): array {
        usort($entries, function(array $a, array $b): int {
            $aDir = in_array($a['type'] ?? '', ['dir', 'container'], true);
            $bDir = in_array($b['type'] ?? '', ['dir', 'container'], true);
            if ($aDir !== $bDir) {
                return $aDir ? -1 : 1;
            }
            return strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
        });

        return array_merge([
            'success' => true,
            'target' => [
                'id' => (string)($target['id'] ?? ''),
                'name' => (string)($target['name'] ?? $target['id'] ?? ''),
                'type' => (string)($target['type'] ?? ''),
            ],
            'path' => $path,
            'parentPath' => $this->parentPath($path),
            'entries' => $entries,
        ], $extra);
    }

    private function error(string $message, ?array $target = null, ?string $path = null, ?int $exitCode = null, array $extra = []): array {
        return array_merge([
            'success' => false,
            'error' => $message,
            'exitCode' => $exitCode,
            'target' => $target ? [
                'id' => (string)($target['id'] ?? ''),
                'name' => (string)($target['name'] ?? $target['id'] ?? ''),
                'type' => (string)($target['type'] ?? ''),
            ] : null,
            'path' => $path,
            'entries' => [],
        ], $extra);
    }
}
