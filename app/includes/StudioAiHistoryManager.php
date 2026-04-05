<?php
/**
 * StudioAiHistoryManager.php - Persistent AI history for Workflows Studio
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/PermissionManager.php';

class StudioAiHistoryManager {
    public const PROJECT_WORKFLOW = 'workflow';
    public const PROJECT_TEMPLATE = 'template';

    private const MODULE_VIEW_ALL = 'studio.ai.history.view_all';
    private const MODULE_REVERT_ANY = 'studio.ai.history.revert_any';

    public const THREAD_STATUS_ACTIVE = 'active';
    public const THREAD_STATUS_ARCHIVED = 'archived';

    public const EVENT_USER_PROMPT = 'user-prompt';
    public const EVENT_ASSISTANT_PROPOSAL = 'assistant-proposal';
    public const EVENT_ASSISTANT_ERROR = 'assistant-error';
    public const EVENT_APPLY = 'apply';
    public const EVENT_DISMISS = 'dismiss';
    public const EVENT_REVERT = 'revert';
    public const EVENT_SAVE = 'save';
    public const EVENT_PUBLISH = 'publish';

    public const PROPOSAL_PROPOSED = 'proposed';
    public const PROPOSAL_APPLIED = 'applied';
    public const PROPOSAL_DISMISSED = 'dismissed';
    public const PROPOSAL_REVERTED = 'reverted';
    public const PROPOSAL_SUPERSEDED = 'superseded';
    public const PROPOSAL_ERROR = 'error';

    private PDO $db;
    private PermissionManager $permissionManager;

    public function __construct(?PermissionManager $permissionManager = null) {
        $this->db = Database::getInstance();
        $this->permissionManager = $permissionManager ?? new PermissionManager();
    }

    public function canViewAllThreads(array $user): bool {
        return $this->hasModuleCapability($user, self::MODULE_VIEW_ALL, ['super-admin']);
    }

    public function canRevertAny(array $user): bool {
        return $this->hasModuleCapability($user, self::MODULE_REVERT_ANY, ['super-admin']);
    }

    public function ensureThread(string $projectType, string $projectId, array $user): array {
        $projectType = $this->normalizeProjectType($projectType);
        $projectId = trim($projectId);
        $ownerUserId = trim((string)($user['id'] ?? ''));
        $ownerUsername = trim((string)($user['username'] ?? ''));

        if ($projectId === '' || $ownerUserId === '') {
            throw new InvalidArgumentException('projectId and user.id are required to create a Studio AI thread');
        }

        $existing = $this->findThreadByOwner($projectType, $projectId, $ownerUserId);
        if ($existing !== null) {
            if (($existing['owner_username'] ?? '') !== $ownerUsername && $ownerUsername !== '') {
                $stmt = $this->db->prepare("
                    UPDATE workflow_studio_ai_threads
                    SET owner_username = ?
                    WHERE id = ?
                ");
                $stmt->execute([$ownerUsername, $existing['id']]);
                $existing = $this->getThreadById((string)$existing['id']);
            }

            return $this->normalizeThreadRow($existing ?? []);
        }

        $threadId = Database::generateUUID();
        $createdAt = date('Y-m-d H:i:s');

        try {
            $stmt = $this->db->prepare("
                INSERT INTO workflow_studio_ai_threads (
                    id, project_type, project_id, owner_user_id, owner_username, title, status, created_at, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $threadId,
                $projectType,
                $projectId,
                $ownerUserId,
                $ownerUsername !== '' ? $ownerUsername : null,
                $ownerUsername !== '' ? ($ownerUsername . ' AI history') : 'AI history',
                self::THREAD_STATUS_ACTIVE,
                $createdAt,
                $ownerUserId,
            ]);
        } catch (PDOException $e) {
            if (stripos($e->getMessage(), 'UNIQUE') === false) {
                throw $e;
            }
        }

        $thread = $this->findThreadByOwner($projectType, $projectId, $ownerUserId);
        if ($thread === null) {
            throw new RuntimeException('Unable to create Studio AI thread');
        }

        return $this->normalizeThreadRow($thread);
    }

    public function appendUserPrompt(
        string $threadId,
        array $user,
        string $promptText,
        string $displayText,
        ?array $draftBefore = null,
        ?array $validationBefore = null
    ): array {
        return $this->appendEvent($threadId, [
            'event_type' => self::EVENT_USER_PROMPT,
            'actor_user_id' => trim((string)($user['id'] ?? '')),
            'actor_username' => trim((string)($user['username'] ?? '')),
            'prompt_text' => trim($promptText),
            'message_text' => trim($displayText) !== '' ? trim($displayText) : trim($promptText),
            'draft_before_json' => $draftBefore,
            'validation_before_json' => $validationBefore,
        ]);
    }

    public function appendAssistantProposal(
        string $threadId,
        array $user,
        array $result,
        ?array $draftBefore = null,
        ?array $draftAfter = null,
        ?array $validationBefore = null,
        ?array $validationAfter = null
    ): array {
        return $this->appendEvent($threadId, [
            'event_type' => self::EVENT_ASSISTANT_PROPOSAL,
            'actor_user_id' => trim((string)($user['id'] ?? '')),
            'actor_username' => trim((string)($user['username'] ?? '')),
            'provider_id' => trim((string)($result['provider']['id'] ?? '')),
            'provider_name' => trim((string)($result['provider']['name'] ?? '')),
            'model' => trim((string)($result['model'] ?? '')),
            'message_text' => trim((string)($result['reply'] ?? '')),
            'summary' => trim((string)($result['summary'] ?? '')),
            'notes_json' => is_array($result['notes'] ?? null) ? array_values($result['notes']) : [],
            'validation_before_json' => $validationBefore,
            'validation_after_json' => $validationAfter,
            'draft_before_json' => $draftBefore,
            'draft_after_json' => $draftAfter,
            'proposal_status' => self::PROPOSAL_PROPOSED,
        ]);
    }

    public function appendAssistantError(
        string $threadId,
        array $user,
        string $errorMessage,
        array $provider = [],
        string $model = '',
        ?array $draftBefore = null,
        ?array $validationBefore = null
    ): array {
        return $this->appendEvent($threadId, [
            'event_type' => self::EVENT_ASSISTANT_ERROR,
            'actor_user_id' => trim((string)($user['id'] ?? '')),
            'actor_username' => trim((string)($user['username'] ?? '')),
            'provider_id' => trim((string)($provider['id'] ?? '')),
            'provider_name' => trim((string)($provider['name'] ?? '')),
            'model' => trim($model),
            'message_text' => trim($errorMessage),
            'draft_before_json' => $draftBefore,
            'validation_before_json' => $validationBefore,
            'proposal_status' => self::PROPOSAL_ERROR,
        ]);
    }

    public function getProjectHistory(string $projectType, string $projectId, array $viewer, ?string $requestedThreadId = null, int $eventLimit = 200): array {
        $projectType = $this->normalizeProjectType($projectType);
        $projectId = trim($projectId);
        $requestedThreadId = trim((string)$requestedThreadId);
        $viewerUserId = trim((string)($viewer['id'] ?? ''));

        $threads = $this->listVisibleThreadsForProject($projectType, $projectId, $viewer);
        $selectedThread = null;

        if ($requestedThreadId !== '') {
            foreach ($threads as $thread) {
                if ((string)($thread['id'] ?? '') === $requestedThreadId) {
                    $selectedThread = $thread;
                    break;
                }
            }
        }

        if ($selectedThread === null && $viewerUserId !== '') {
            foreach ($threads as $thread) {
                if ((string)($thread['ownerUserId'] ?? '') === $viewerUserId) {
                    $selectedThread = $thread;
                    break;
                }
            }
        }

        if ($selectedThread === null && $threads !== []) {
            $selectedThread = $threads[0];
        }

        $events = $selectedThread ? $this->listThreadEvents((string)$selectedThread['id'], $eventLimit) : [];

        return [
            'projectType' => $projectType,
            'projectId' => $projectId,
            'threads' => $threads,
            'selectedThreadId' => $selectedThread['id'] ?? null,
            'selectedThread' => $selectedThread,
            'events' => $events,
            'viewerPermissions' => [
                'canViewAllThreads' => $this->canViewAllThreads($viewer),
                'canRevertAny' => $this->canRevertAny($viewer),
            ],
        ];
    }

    public function buildConversationForThread(string $threadId, int $limitMessages = 6): array {
        $context = $this->buildPromptContextForThread($threadId);
        $conversation = is_array($context['conversation'] ?? null) ? $context['conversation'] : [];

        if ($limitMessages > 0 && count($conversation) > $limitMessages) {
            $conversation = array_slice($conversation, -$limitMessages);
        }

        return array_map(static function(array $message): array {
            return [
                'role' => (string)($message['role'] ?? 'user'),
                'content' => (string)($message['content'] ?? ''),
            ];
        }, array_values(array_filter($conversation, static function(array $message): bool {
            return trim((string)($message['content'] ?? '')) !== '';
        })));
    }

    public function buildPromptContextForThread(string $threadId): array {
        $threadId = trim($threadId);
        if ($threadId === '') {
            return [
                'conversation' => [],
                'proposalHistory' => [],
            ];
        }

        $events = $this->listThreadEvents($threadId, 0);
        $eventIndex = [];
        foreach ($events as $event) {
            $eventId = trim((string)($event['id'] ?? ''));
            if ($eventId !== '') {
                $eventIndex[$eventId] = $event;
            }
        }

        $messages = [];
        $proposalHistory = [];
        foreach ($events as $event) {
            $eventType = (string)($event['eventType'] ?? '');
            if ($eventType === self::EVENT_USER_PROMPT) {
                $content = trim((string)($event['promptText'] ?? $event['messageText'] ?? ''));
                if ($content !== '') {
                    $messages[] = [
                        'role' => 'user',
                        'content' => $content,
                        'kind' => self::EVENT_USER_PROMPT,
                        'createdAt' => $event['createdAt'] ?? null,
                        'actorUsername' => (string)($event['actorUsername'] ?? ''),
                    ];
                }
                continue;
            }

            if ($eventType === self::EVENT_ASSISTANT_PROPOSAL) {
                $content = trim((string)($event['messageText'] ?? ''));
                $proposalStatus = trim((string)($event['proposalStatus'] ?? self::PROPOSAL_PROPOSED));
                $appliedEventId = trim((string)($event['appliedEventId'] ?? ''));
                $revertedEventId = trim((string)($event['revertedEventId'] ?? ''));
                $appliedEvent = $appliedEventId !== '' ? ($eventIndex[$appliedEventId] ?? null) : null;
                $revertedEvent = $revertedEventId !== '' ? ($eventIndex[$revertedEventId] ?? null) : null;

                if ($content !== '') {
                    $messages[] = [
                        'role' => 'assistant',
                        'content' => $content,
                        'kind' => self::EVENT_ASSISTANT_PROPOSAL,
                        'summary' => trim((string)($event['summary'] ?? '')),
                        'proposalStatus' => $proposalStatus,
                        'createdAt' => $event['createdAt'] ?? null,
                        'appliedAt' => $appliedEvent['createdAt'] ?? null,
                        'appliedByUsername' => (string)($appliedEvent['actorUsername'] ?? ''),
                        'revertedAt' => $revertedEvent['createdAt'] ?? null,
                        'revertedByUsername' => (string)($revertedEvent['actorUsername'] ?? ''),
                    ];
                }

                $proposalHistory[] = [
                    'eventId' => (string)($event['id'] ?? ''),
                    'summary' => trim((string)($event['summary'] ?? '')),
                    'content' => $content,
                    'proposalStatus' => $proposalStatus,
                    'createdAt' => $event['createdAt'] ?? null,
                    'appliedAt' => $appliedEvent['createdAt'] ?? null,
                    'appliedByUsername' => (string)($appliedEvent['actorUsername'] ?? ''),
                    'revertedAt' => $revertedEvent['createdAt'] ?? null,
                    'revertedByUsername' => (string)($revertedEvent['actorUsername'] ?? ''),
                ];
                continue;
            }

            if ($eventType === self::EVENT_ASSISTANT_ERROR) {
                $content = trim((string)($event['messageText'] ?? ''));
                if ($content !== '') {
                    $messages[] = [
                        'role' => 'assistant',
                        'content' => $content,
                        'kind' => self::EVENT_ASSISTANT_ERROR,
                        'proposalStatus' => trim((string)($event['proposalStatus'] ?? self::PROPOSAL_ERROR)),
                        'createdAt' => $event['createdAt'] ?? null,
                    ];
                }
            }
        }

        return [
            'conversation' => $messages,
            'proposalHistory' => $proposalHistory,
        ];
    }

    public function getThreadById(string $threadId): ?array {
        $stmt = $this->db->prepare("SELECT * FROM workflow_studio_ai_threads WHERE id = ? LIMIT 1");
        $stmt->execute([trim($threadId)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row : null;
    }

    public function getEventById(string $eventId): ?array {
        $stmt = $this->db->prepare("SELECT * FROM workflow_studio_ai_events WHERE id = ? LIMIT 1");
        $stmt->execute([trim($eventId)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->normalizeEventRow($row) : null;
    }

    public function isThreadVisibleToUser(string $threadId, array $viewer): bool {
        $thread = $this->getThreadById($threadId);
        if ($thread === null) {
            return false;
        }

        if ($this->canViewAllThreads($viewer)) {
            return true;
        }

        return trim((string)($thread['owner_user_id'] ?? '')) === trim((string)($viewer['id'] ?? ''));
    }

    public function getVisibleThreadForUser(string $threadId, array $viewer): ?array {
        if (!$this->isThreadVisibleToUser($threadId, $viewer)) {
            return null;
        }

        $thread = $this->getThreadById($threadId);
        return $thread ? $this->normalizeThreadRow($thread, $viewer) : null;
    }

    public function getVisibleEventForUser(string $threadId, string $eventId, array $viewer): ?array {
        if (!$this->isThreadVisibleToUser($threadId, $viewer)) {
            return null;
        }

        $event = $this->getEventById($eventId);
        if ($event === null) {
            return null;
        }

        return (string)($event['threadId'] ?? '') === trim($threadId) ? $event : null;
    }

    public function appendLifecycleEvent(
        string $threadId,
        string $eventType,
        array $user,
        string $messageText,
        ?array $draftBefore = null,
        ?array $draftAfter = null,
        ?array $validationBefore = null,
        ?array $validationAfter = null,
        ?string $baseVersionId = null,
        ?string $resultVersionId = null
    ): array {
        return $this->appendEvent($threadId, [
            'event_type' => $eventType,
            'actor_user_id' => trim((string)($user['id'] ?? '')),
            'actor_username' => trim((string)($user['username'] ?? '')),
            'message_text' => trim($messageText),
            'draft_before_json' => $draftBefore,
            'draft_after_json' => $draftAfter,
            'validation_before_json' => $validationBefore,
            'validation_after_json' => $validationAfter,
            'base_version_id' => $baseVersionId,
            'result_version_id' => $resultVersionId,
        ]);
    }

    public function appendProjectLifecycleEvent(
        string $projectType,
        string $projectId,
        string $eventType,
        array $user,
        string $messageText,
        ?array $draftBefore = null,
        ?array $draftAfter = null,
        ?array $validationBefore = null,
        ?array $validationAfter = null,
        ?string $baseVersionId = null,
        ?string $resultVersionId = null
    ): array {
        $projectType = $this->normalizeProjectType($projectType);
        $projectId = trim($projectId);
        if ($projectId === '') {
            return [];
        }

        $events = [];
        foreach ($this->listProjectThreadIds($projectType, $projectId) as $threadId) {
            $events[] = $this->appendLifecycleEvent(
                $threadId,
                $eventType,
                $user,
                $messageText,
                $draftBefore,
                $draftAfter,
                $validationBefore,
                $validationAfter,
                $baseVersionId,
                $resultVersionId
            );
        }

        return $events;
    }

    public function markProposalApplied(string $proposalEventId, string $applyEventId): void {
        $this->updateProposalEventStatus($proposalEventId, self::PROPOSAL_APPLIED, 'applied_event_id', $applyEventId);
    }

    public function markProposalDismissed(string $proposalEventId): void {
        $this->updateProposalEventStatus($proposalEventId, self::PROPOSAL_DISMISSED);
    }

    public function markProposalReverted(string $proposalEventId, string $revertEventId): void {
        $this->updateProposalEventStatus($proposalEventId, self::PROPOSAL_REVERTED, 'reverted_event_id', $revertEventId);
    }

    private function listVisibleThreadsForProject(string $projectType, string $projectId, array $viewer): array {
        $sql = "
            SELECT *
            FROM workflow_studio_ai_threads
            WHERE project_type = ?
              AND project_id = ?
        ";
        $params = [$projectType, $projectId];

        if (!$this->canViewAllThreads($viewer)) {
            $sql .= " AND owner_user_id = ?";
            $params[] = trim((string)($viewer['id'] ?? ''));
        }

        $sql .= " ORDER BY COALESCE(last_event_at, created_at) DESC, owner_username COLLATE NOCASE ASC, id ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return array_map(
            fn(array $row): array => $this->normalizeThreadRow($row, $viewer),
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    private function listThreadEvents(string $threadId, int $limit = 200): array {
        $sql = "
            SELECT *
            FROM workflow_studio_ai_events
            WHERE thread_id = ?
            ORDER BY sequence_number ASC
        ";

        if ($limit > 0) {
            $limit = max(1, min($limit, 500));
            $sql .= " LIMIT " . $limit;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$threadId]);

        return array_map(fn(array $row): array => $this->normalizeEventRow($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function listProjectThreadIds(string $projectType, string $projectId): array {
        $stmt = $this->db->prepare("
            SELECT id
            FROM workflow_studio_ai_threads
            WHERE project_type = ?
              AND project_id = ?
            ORDER BY created_at ASC, id ASC
        ");
        $stmt->execute([$projectType, $projectId]);

        return array_values(array_filter(array_map(static function(array $row): string {
            return trim((string)($row['id'] ?? ''));
        }, $stmt->fetchAll(PDO::FETCH_ASSOC))));
    }

    private function findThreadByOwner(string $projectType, string $projectId, string $ownerUserId): ?array {
        $stmt = $this->db->prepare("
            SELECT *
            FROM workflow_studio_ai_threads
            WHERE project_type = ?
              AND project_id = ?
              AND owner_user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$projectType, $projectId, $ownerUserId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $row : null;
    }

    private function appendEvent(string $threadId, array $payload): array {
        $thread = $this->getThreadById($threadId);
        if ($thread === null) {
            throw new RuntimeException('Studio AI thread not found');
        }

        $createdAt = date('Y-m-d H:i:s');
        $eventId = Database::generateUUID();

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("
                SELECT COALESCE(MAX(sequence_number), 0) AS sequence_number
                FROM workflow_studio_ai_events
                WHERE thread_id = ?
            ");
            $stmt->execute([$threadId]);
            $nextSequence = ((int)($stmt->fetch(PDO::FETCH_ASSOC)['sequence_number'] ?? 0)) + 1;

            $insert = $this->db->prepare("
                INSERT INTO workflow_studio_ai_events (
                    id, thread_id, project_type, project_id, sequence_number, event_type,
                    actor_user_id, actor_username, provider_id, provider_name, model,
                    prompt_text, message_text, summary, notes_json, actions_json,
                    validation_before_json, validation_after_json,
                    draft_before_json, draft_after_json,
                    base_version_id, result_version_id, proposal_status,
                    applied_event_id, reverted_event_id, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                )
            ");
            $insert->execute([
                $eventId,
                $threadId,
                (string)($thread['project_type'] ?? ''),
                (string)($thread['project_id'] ?? ''),
                $nextSequence,
                (string)($payload['event_type'] ?? ''),
                $this->nullableString($payload['actor_user_id'] ?? null),
                $this->nullableString($payload['actor_username'] ?? null),
                $this->nullableString($payload['provider_id'] ?? null),
                $this->nullableString($payload['provider_name'] ?? null),
                $this->nullableString($payload['model'] ?? null),
                $this->nullableString($payload['prompt_text'] ?? null),
                $this->nullableString($payload['message_text'] ?? null),
                $this->nullableString($payload['summary'] ?? null),
                $this->encodeJson($payload['notes_json'] ?? null),
                $this->encodeJson($payload['actions_json'] ?? null),
                $this->encodeJson($payload['validation_before_json'] ?? null),
                $this->encodeJson($payload['validation_after_json'] ?? null),
                $this->encodeJson($payload['draft_before_json'] ?? null),
                $this->encodeJson($payload['draft_after_json'] ?? null),
                $this->nullableString($payload['base_version_id'] ?? null),
                $this->nullableString($payload['result_version_id'] ?? null),
                $this->nullableString($payload['proposal_status'] ?? null),
                $this->nullableString($payload['applied_event_id'] ?? null),
                $this->nullableString($payload['reverted_event_id'] ?? null),
                $createdAt,
            ]);

            $updateThread = $this->db->prepare("
                UPDATE workflow_studio_ai_threads
                SET last_event_at = ?
                WHERE id = ?
            ");
            $updateThread->execute([$createdAt, $threadId]);

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        $stmt = $this->db->prepare("SELECT * FROM workflow_studio_ai_events WHERE id = ? LIMIT 1");
        $stmt->execute([$eventId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Studio AI event could not be loaded after insert');
        }

        return $this->normalizeEventRow($row);
    }

    private function normalizeThreadRow(array $row, array $viewer = []): array {
        $ownerUserId = trim((string)($row['owner_user_id'] ?? ''));
        $latestProposal = $this->findLatestProposalForThread((string)($row['id'] ?? ''));

        return [
            'id' => (string)($row['id'] ?? ''),
            'projectType' => (string)($row['project_type'] ?? ''),
            'projectId' => (string)($row['project_id'] ?? ''),
            'ownerUserId' => $ownerUserId,
            'ownerUsername' => (string)($row['owner_username'] ?? ''),
            'title' => (string)($row['title'] ?? ''),
            'status' => (string)($row['status'] ?? self::THREAD_STATUS_ACTIVE),
            'lastEventAt' => $row['last_event_at'] ?? null,
            'createdAt' => $row['created_at'] ?? null,
            'createdBy' => $row['created_by'] ?? null,
            'isOwnedByViewer' => $ownerUserId !== '' && $ownerUserId === trim((string)($viewer['id'] ?? '')),
            'latestProposalStatus' => $latestProposal['proposalStatus'] ?? null,
            'latestProposalAt' => $latestProposal['createdAt'] ?? null,
        ];
    }

    private function normalizeEventRow(array $row): array {
        return [
            'id' => (string)($row['id'] ?? ''),
            'threadId' => (string)($row['thread_id'] ?? ''),
            'projectType' => (string)($row['project_type'] ?? ''),
            'projectId' => (string)($row['project_id'] ?? ''),
            'sequenceNumber' => (int)($row['sequence_number'] ?? 0),
            'eventType' => (string)($row['event_type'] ?? ''),
            'actorUserId' => $row['actor_user_id'] ?? null,
            'actorUsername' => (string)($row['actor_username'] ?? ''),
            'providerId' => (string)($row['provider_id'] ?? ''),
            'providerName' => (string)($row['provider_name'] ?? ''),
            'model' => (string)($row['model'] ?? ''),
            'promptText' => (string)($row['prompt_text'] ?? ''),
            'messageText' => (string)($row['message_text'] ?? ''),
            'summary' => (string)($row['summary'] ?? ''),
            'notes' => $this->decodeJson($row['notes_json'] ?? null, []),
            'actions' => $this->decodeJson($row['actions_json'] ?? null, []),
            'validationBefore' => $this->decodeJson($row['validation_before_json'] ?? null),
            'validationAfter' => $this->decodeJson($row['validation_after_json'] ?? null),
            'draftBefore' => $this->decodeJson($row['draft_before_json'] ?? null),
            'draftAfter' => $this->decodeJson($row['draft_after_json'] ?? null),
            'baseVersionId' => (string)($row['base_version_id'] ?? ''),
            'resultVersionId' => (string)($row['result_version_id'] ?? ''),
            'proposalStatus' => (string)($row['proposal_status'] ?? ''),
            'appliedEventId' => (string)($row['applied_event_id'] ?? ''),
            'revertedEventId' => (string)($row['reverted_event_id'] ?? ''),
            'createdAt' => $row['created_at'] ?? null,
        ];
    }

    private function findLatestProposalForThread(string $threadId): ?array {
        if (trim($threadId) === '') {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT *
            FROM workflow_studio_ai_events
            WHERE thread_id = ?
              AND event_type IN (?, ?)
            ORDER BY sequence_number DESC
            LIMIT 1
        ");
        $stmt->execute([$threadId, self::EVENT_ASSISTANT_PROPOSAL, self::EVENT_ASSISTANT_ERROR]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->normalizeEventRow($row) : null;
    }

    private function hasModuleCapability(array $user, string $moduleId, array $defaultRoles = []): bool {
        if (!$this->permissionManager->canAccessResource($user, 'module', $moduleId)) {
            return false;
        }

        $role = trim((string)($user['role'] ?? ''));
        if ($role !== '' && in_array($role, $defaultRoles, true)) {
            return true;
        }

        return $this->permissionManager->hasExplicitResourceGrant($user, 'module', $moduleId);
    }

    private function normalizeProjectType(string $projectType): string {
        $normalized = strtolower(trim($projectType));
        return $normalized === self::PROJECT_TEMPLATE ? self::PROJECT_TEMPLATE : self::PROJECT_WORKFLOW;
    }

    private function nullableString($value): ?string {
        if ($value === null) {
            return null;
        }

        $text = trim((string)$value);
        return $text === '' ? null : $text;
    }

    private function encodeJson($value): ?string {
        if ($value === null) {
            return null;
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES);
    }

    private function decodeJson($value, $default = null) {
        if ($value === null || $value === '') {
            return $default;
        }

        $decoded = json_decode((string)$value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
    }

    private function updateProposalEventStatus(string $proposalEventId, string $status, ?string $linkColumn = null, ?string $linkValue = null): void {
        $allowed = [
            self::PROPOSAL_PROPOSED,
            self::PROPOSAL_APPLIED,
            self::PROPOSAL_DISMISSED,
            self::PROPOSAL_REVERTED,
            self::PROPOSAL_SUPERSEDED,
            self::PROPOSAL_ERROR,
        ];
        if (!in_array($status, $allowed, true)) {
            throw new InvalidArgumentException('Invalid Studio AI proposal status');
        }

        $sql = "UPDATE workflow_studio_ai_events SET proposal_status = ?";
        $params = [$status];

        if ($linkColumn !== null && in_array($linkColumn, ['applied_event_id', 'reverted_event_id'], true)) {
            $sql .= ", {$linkColumn} = ?";
            $params[] = $this->nullableString($linkValue);
        }

        $sql .= " WHERE id = ?";
        $params[] = trim($proposalEventId);

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }
}
