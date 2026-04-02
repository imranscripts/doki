<?php
/**
 * WorkspaceAIJobManager.php - Async job management for Studio AI
 *
 * Queues AI chat and workspace-creation requests into SQLite, spawns a
 * background worker, and exposes pollable job state for the Studio UI.
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/WorkspaceManager.php';
require_once __DIR__ . '/AIProviderManager.php';
require_once __DIR__ . '/WorkspaceAIManager.php';

class WorkspaceAIJobManager {
    private const JOBS_TABLE = 'studio_ai_jobs';
    private const STATUS_PENDING = 'pending';
    private const STATUS_RUNNING = 'running';
    private const STATUS_COMPLETED = 'completed';
    private const STATUS_FAILED = 'failed';
    private const KIND_CHAT = 'chat';
    private const KIND_CREATE_WORKSPACE = 'create_workspace';

    private PDO $db;
    private WorkspaceManager $workspaceManager;
    private AIProviderManager $providerManager;
    private WorkspaceAIManager $workspaceAIManager;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->workspaceManager = new WorkspaceManager();
        $this->providerManager = new AIProviderManager();
        $this->workspaceAIManager = new WorkspaceAIManager();
    }

    /**
     * Queue an async workspace-scoped AI chat request.
     */
    public function queueChatJob(string $workspaceId, string $providerId, array $messages, array $options, string $userId, string $username): array {
        if ($this->workspaceManager->getWorkspace($workspaceId) === null) {
            return ['success' => false, 'error' => 'Workspace not found'];
        }

        if ($this->providerManager->getResolvedProvider($providerId) === null) {
            return ['success' => false, 'error' => 'AI provider not found'];
        }

        $payload = [
            'workspaceId' => $workspaceId,
            'providerId' => $providerId,
            'messages' => array_values($messages),
            'options' => [
                'model' => trim((string)($options['model'] ?? '')),
                'currentFilePath' => trim((string)($options['currentFilePath'] ?? '')),
                'targetFilePath' => trim((string)($options['targetFilePath'] ?? '')),
                'targetStartLine' => $options['targetStartLine'] ?? null,
                'targetEndLine' => $options['targetEndLine'] ?? null,
                'targetSelectedText' => (string)($options['targetSelectedText'] ?? ''),
                'targetDomSelection' => is_array($options['targetDomSelection'] ?? null) ? $options['targetDomSelection'] : null,
            ],
        ];

        return $this->createJob(self::KIND_CHAT, $workspaceId, $providerId, trim((string)($options['model'] ?? '')), $payload, $userId, $username);
    }

    /**
     * Queue an async AI-generated workspace creation request.
     */
    public function queueWorkspaceCreationJob(string $providerId, string $prompt, array $options, string $userId, string $username): array {
        if ($this->providerManager->getResolvedProvider($providerId) === null) {
            return ['success' => false, 'error' => 'AI provider not found'];
        }

        $prompt = trim($prompt);
        if ($prompt === '') {
            return ['success' => false, 'error' => 'An app description is required'];
        }

        $payload = [
            'providerId' => $providerId,
            'prompt' => $prompt,
            'options' => [
                'model' => trim((string)($options['model'] ?? '')),
            ],
        ];

        return $this->createJob(self::KIND_CREATE_WORKSPACE, null, $providerId, trim((string)($options['model'] ?? '')), $payload, $userId, $username);
    }

    /**
     * Get a job visible to the requesting user.
     */
    public function getJobForUser(string $jobId, array $user): ?array {
        $job = $this->getJob($jobId);
        if ($job === null) {
            return null;
        }

        $role = (string)($user['role'] ?? '');
        $userId = (string)($user['id'] ?? '');
        if ($role === 'super-admin' || $job['created_by'] === $userId) {
            return $job;
        }

        return null;
    }

    /**
     * Load a job by ID and decode JSON columns.
     */
    public function getJob(string $jobId): ?array {
        $stmt = $this->db->prepare("
            SELECT j.*, p.name AS provider_name
            FROM " . self::JOBS_TABLE . " j
            LEFT JOIN ai_providers p ON p.id = j.provider_id
            WHERE j.id = ?
        ");
        $stmt->execute([$jobId]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$job) {
            return null;
        }

        return $this->decodeJobRow($job);
    }

    /**
     * Execute a queued job in the background worker.
     */
    public function runJob(string $jobId): array {
        $job = $this->getJob($jobId);
        if ($job === null) {
            return ['success' => false, 'error' => 'Job not found'];
        }

        if ($job['status'] === self::STATUS_COMPLETED) {
            return ['success' => true, 'job' => $job];
        }

        if ($job['status'] === self::STATUS_FAILED) {
            return ['success' => false, 'error' => $job['error'] ?? 'Job failed'];
        }

        if (!$this->claimJob($jobId)) {
            $fresh = $this->getJob($jobId);
            return $fresh !== null
                ? ['success' => $fresh['status'] === self::STATUS_COMPLETED, 'job' => $fresh, 'error' => $fresh['error'] ?? null]
                : ['success' => false, 'error' => 'Job could not be claimed'];
        }

        $job = $this->getJob($jobId);
        if ($job === null) {
            return ['success' => false, 'error' => 'Job disappeared'];
        }

        try {
            $result = $this->executeJob($job);
            if (empty($result['success'])) {
                throw new RuntimeException($result['error'] ?? 'The Studio AI job failed');
            }
            $workspaceId = null;
            if ($job['kind'] === self::KIND_CHAT) {
                $workspaceId = trim((string)($job['workspace_id'] ?? '')) ?: trim((string)($job['input']['workspaceId'] ?? ''));
            } else {
                $workspaceId = trim((string)($result['workspace']['id'] ?? '')) ?: null;
            }

            $this->completeJob($jobId, $result, $workspaceId);

            return [
                'success' => true,
                'job' => $this->getJob($jobId),
            ];
        } catch (Throwable $e) {
            $this->failJob($jobId, $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Create and immediately spawn a new async job.
     */
    private function createJob(string $kind, ?string $workspaceId, string $providerId, string $model, array $payload, string $userId, string $username): array {
        $jobId = $this->generateJobId();
        $stmt = $this->db->prepare("
            INSERT INTO " . self::JOBS_TABLE . " (
                id, kind, status, workspace_id, provider_id, model, input_json, created_by, created_by_username, updated_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $jobId,
            $kind,
            self::STATUS_PENDING,
            $workspaceId,
            $providerId,
            $model !== '' ? $model : null,
            $this->encodeJson($payload),
            $userId,
            $username,
            $userId,
        ]);

        $this->spawnWorker($jobId);
        $job = $this->getJob($jobId);

        return [
            'success' => true,
            'job' => $job,
        ];
    }

    /**
     * Atomically claim a pending job for execution.
     */
    private function claimJob(string $jobId): bool {
        $stmt = $this->db->prepare("
            UPDATE " . self::JOBS_TABLE . "
            SET status = ?,
                started_at = datetime('now'),
                updated_at = datetime('now')
            WHERE id = ?
              AND status = ?
        ");
        $stmt->execute([self::STATUS_RUNNING, $jobId, self::STATUS_PENDING]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Execute the concrete AI operation for a job row.
     */
    private function executeJob(array $job): array {
        $input = is_array($job['input'] ?? null) ? $job['input'] : [];

        if ($job['kind'] === self::KIND_CHAT) {
            return $this->workspaceAIManager->chat(
                trim((string)($input['workspaceId'] ?? '')),
                trim((string)($input['providerId'] ?? '')),
                is_array($input['messages'] ?? null) ? $input['messages'] : [],
                is_array($input['options'] ?? null) ? $input['options'] : []
            );
        }

        if ($job['kind'] === self::KIND_CREATE_WORKSPACE) {
            return $this->workspaceAIManager->createWorkspaceFromPrompt(
                trim((string)($input['providerId'] ?? '')),
                trim((string)($input['prompt'] ?? '')),
                trim((string)($job['created_by'] ?? '')),
                trim((string)($job['created_by_username'] ?? '')),
                is_array($input['options'] ?? null) ? $input['options'] : []
            );
        }

        throw new RuntimeException('Unsupported AI job kind: ' . (string)($job['kind'] ?? 'unknown'));
    }

    /**
     * Mark a job as completed and persist the result payload.
     */
    private function completeJob(string $jobId, array $result, ?string $workspaceId): void {
        $resolvedModel = trim((string)($result['model'] ?? ''));
        $stmt = $this->db->prepare("
            UPDATE " . self::JOBS_TABLE . "
            SET status = ?,
                workspace_id = COALESCE(?, workspace_id),
                model = COALESCE(?, model),
                result_json = ?,
                error = NULL,
                completed_at = datetime('now'),
                updated_at = datetime('now')
            WHERE id = ?
        ");
        $stmt->execute([
            self::STATUS_COMPLETED,
            $workspaceId,
            $resolvedModel !== '' ? $resolvedModel : null,
            $this->encodeJson($result),
            $jobId,
        ]);
    }

    /**
     * Mark a job as failed.
     */
    private function failJob(string $jobId, string $error): void {
        $stmt = $this->db->prepare("
            UPDATE " . self::JOBS_TABLE . "
            SET status = ?,
                error = ?,
                completed_at = datetime('now'),
                updated_at = datetime('now')
            WHERE id = ?
        ");
        $stmt->execute([self::STATUS_FAILED, $error, $jobId]);
    }

    /**
     * Spawn the background worker process.
     */
    private function spawnWorker(string $jobId): void {
        $scriptPath = realpath(__DIR__ . '/../studio-ai-job.php') ?: (__DIR__ . '/../studio-ai-job.php');
        $phpBinary = defined('PHP_BINARY') && is_string(PHP_BINARY) && PHP_BINARY !== '' ? PHP_BINARY : 'php';
        $cmd = sprintf(
            'nohup %s %s %s > /dev/null 2>&1 &',
            escapeshellarg($phpBinary),
            escapeshellarg($scriptPath),
            escapeshellarg($jobId)
        );
        exec($cmd);
    }

    /**
     * Generate a unique job ID.
     */
    private function generateJobId(): string {
        return 'studio-ai-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4));
    }

    /**
     * Decode JSON columns into arrays.
     */
    private function decodeJobRow(array $row): array {
        $row['input'] = $this->decodeJson($row['input_json'] ?? null);
        $row['result'] = $this->decodeJson($row['result_json'] ?? null);
        $row['providerName'] = $row['provider_name'] ?? null;
        return $row;
    }

    /**
     * Encode data to JSON for storage.
     */
    private function encodeJson(array $value): string {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    /**
     * Decode JSON safely.
     */
    private function decodeJson($value): ?array {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : null;
    }
}
