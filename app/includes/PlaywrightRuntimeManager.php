<?php
/**
 * PlaywrightRuntimeManager
 *
 * Tracks the Playwright runner image and any active test containers.
 */

require_once __DIR__ . '/AppContext.php';

class PlaywrightRuntimeManager {
    private const IMAGE_NAME = 'doki/playwright-runner:v4';

    private string $statusDir;
    private string $statusFile;
    private AppContext $appContext;

    public function __construct(?AppContext $appContext = null) {
        $this->statusDir = __DIR__ . '/../data/playwright-runtime';
        $this->statusFile = $this->statusDir . '/build.json';
        $this->appContext = $appContext ?? new AppContext('playwright');

        if (!is_dir($this->statusDir)) {
            @mkdir($this->statusDir, 0755, true);
        }
    }

    public function getImageName(): string {
        return self::IMAGE_NAME;
    }

    public function isDockerAvailable(int $timeoutSeconds = 2): bool {
        $result = $this->appContext->runDockerCommandOnTarget(
            ['ps', '--format', '{{.ID}}'],
            $timeoutSeconds
        );
        $output = trim($result['output'] ?? '');

        if ($result['success']) {
            return true;
        }

        if ($output !== '' && !$this->looksLikeDockerError($output)) {
            return true;
        }

        return false;
    }

    public function imageExists(int $timeoutSeconds = 2): bool {
        $imageName = $this->getImageName();
        $result = $this->appContext->checkImageExists($imageName);
        return (bool)($result['exists'] ?? false);
    }

    public function getRunningContainerCount(int $timeoutSeconds = 2): int {
        $result = $this->appContext->runDockerCommandOnTarget(
            ['ps', '--filter', 'name=^playwright-', '--format', '{{.ID}}'],
            $timeoutSeconds
        );
        $output = trim($result['output'] ?? '');

        if ($output === '' || $this->looksLikeDockerError($output)) {
            return 0;
        }

        $lines = array_filter(array_map('trim', explode("\n", $output)));
        return count($lines);
    }

    public function getBuildStatus(): array {
        if (file_exists($this->statusFile)) {
            $data = json_decode(file_get_contents($this->statusFile), true);
            if (is_array($data)) {
                return $data;
            }
        }

        if ($this->imageExists()) {
            return [
                'status' => 'ready',
                'message' => 'Image ready',
                'imageName' => $this->getImageName(),
            ];
        }

        return [
            'status' => 'not_started',
            'message' => 'Build not started',
            'imageName' => $this->getImageName(),
        ];
    }

    public function getStatus(): array {
        $dockerAvailable = $this->isDockerAvailable();
        $imageExists = $dockerAvailable ? $this->imageExists() : false;
        $runningContainers = $dockerAvailable ? $this->getRunningContainerCount() : 0;

        return [
            'dockerAvailable' => $dockerAvailable,
            'imageName' => $this->getImageName(),
            'imageExists' => $imageExists,
            'runningContainers' => $runningContainers,
            'buildStatus' => $this->getBuildStatus(),
            'ready' => $dockerAvailable && $imageExists,
        ];
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
}
