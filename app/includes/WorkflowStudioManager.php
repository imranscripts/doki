<?php
/**
 * WorkflowStudioManager.php - Draft/project management for Workflows Studio
 */

require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/CommandsManager.php';
require_once __DIR__ . '/ConfigManager.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/TemplateManager.php';

class WorkflowStudioManager {
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED = 'archived';

    private PDO $db;
    private CommandsManager $commandsManager;
    private TemplateManager $templateManager;
    private ConfigManager $configManager;
    private ?Auth $auth;

    public function __construct(?Auth $auth = null) {
        $this->db = Database::getInstance();
        $this->auth = $auth;
        $this->commandsManager = new CommandsManager($auth);
        $this->templateManager = new TemplateManager();
        $this->configManager = new ConfigManager();
    }

    public function listProjects(): array {
        $stmt = $this->db->query("
            SELECT *
            FROM workflow_studio_projects
            ORDER BY datetime(updated_at) DESC, name COLLATE NOCASE ASC, id ASC
        ");

        return array_map(fn(array $row): array => $this->normalizeProjectRow($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function getProject(string $projectId): ?array {
        $stmt = $this->db->prepare("SELECT * FROM workflow_studio_projects WHERE id = ? LIMIT 1");
        $stmt->execute([$projectId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->normalizeProjectRow($row) : null;
    }

    public function listVersions(string $projectId): array {
        $stmt = $this->db->prepare("
            SELECT *
            FROM workflow_studio_versions
            WHERE project_id = ?
            ORDER BY version_number DESC, created_at DESC, id DESC
        ");
        $stmt->execute([$projectId]);

        return array_map(fn(array $row): array => $this->normalizeVersionRow($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function saveProject(array $input, string $userId, string $username, ?string $projectId = null): array {
        $existing = $projectId ? $this->getProject($projectId) : null;
        if ($projectId !== null && $existing === null) {
            return ['success' => false, 'error' => 'Workflow project not found'];
        }

        $payload = $this->normalizeProjectInput($input, $existing);
        $projectId = $existing['id'] ?? $this->generateProjectId((string)($payload['name'] ?? 'workflow-project'));

        $conflict = $this->findProjectByCommandId((string)($payload['commandId'] ?? ''), $projectId);
        if ($conflict !== null) {
            return ['success' => false, 'error' => 'Another workflow project already uses command ID `' . $payload['commandId'] . '`'];
        }

        $draftJson = json_encode($payload['draft'], JSON_UNESCAPED_SLASHES);

        try {
            $this->db->beginTransaction();

            if ($existing === null) {
                $stmt = $this->db->prepare("
                    INSERT INTO workflow_studio_projects (
                        id, name, description, status, command_id,
                        draft_json, created_by, updated_by
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $projectId,
                    $payload['name'],
                    $payload['description'],
                    $payload['status'],
                    $payload['commandId'] !== '' ? $payload['commandId'] : null,
                    $draftJson,
                    $userId,
                    $userId,
                ]);
            } else {
                $stmt = $this->db->prepare("
                    UPDATE workflow_studio_projects
                    SET name = ?,
                        description = ?,
                        status = ?,
                        command_id = ?,
                        draft_json = ?,
                        updated_at = datetime('now'),
                        updated_by = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $payload['name'],
                    $payload['description'],
                    $payload['status'],
                    $payload['commandId'] !== '' ? $payload['commandId'] : null,
                    $draftJson,
                    $userId,
                    $projectId,
                ]);
            }

            $project = $this->getProject($projectId);
            if ($project === null) {
                throw new RuntimeException('Workflow project could not be loaded after save');
            }

            $this->createVersion($projectId, 'manual-save', $payload['draft'], null, $userId);
            $this->db->commit();

            $updated = $this->getProject($projectId);
            if ($this->auth) {
                $this->auth->auditLog(
                    $userId,
                    $username,
                    $existing === null ? 'workflow_studio_project.create' : 'workflow_studio_project.update',
                    'workflow-project',
                    $projectId,
                    json_encode(['name' => $payload['name'], 'commandId' => $payload['commandId']])
                );
            }

            return ['success' => true, 'project' => $updated];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            return ['success' => false, 'error' => 'Failed to save workflow project: ' . $e->getMessage()];
        }
    }

    public function validateProject(string $projectId): array {
        $project = $this->getProject($projectId);
        if ($project === null) {
            return ['success' => false, 'error' => 'Workflow project not found'];
        }

        $validation = $this->validateDraftPayload([
            'name' => $project['name'],
            'description' => $project['description'],
            'commandId' => $project['commandId'],
            'draft' => $project['draft'],
        ]);

        $stmt = $this->db->prepare("
            UPDATE workflow_studio_projects
            SET last_validation_json = ?,
                updated_at = datetime('now')
            WHERE id = ?
        ");
        $stmt->execute([
            json_encode($validation['validation'], JSON_UNESCAPED_SLASHES),
            $projectId,
        ]);

        return [
            'success' => true,
            'project' => $this->getProject($projectId),
            'validation' => $validation['validation'],
            'draft' => $validation['draft'],
        ];
    }

    public function validateDraftPayload(array $input): array {
        $payload = $this->normalizeProjectInput($input, null);
        $draft = $payload['draft'];
        $errors = [];
        $warnings = [];
        $templatesById = [];

        $validation = $this->commandsManager->validateCommand($draft);
        if (!$validation['valid']) {
            $errors = array_merge($errors, $validation['errors']);
        }

        $targetsById = [];
        foreach ($this->configManager->getTargets() as $target) {
            $targetId = trim((string)($target['id'] ?? ''));
            if ($targetId !== '') {
                $targetsById[$targetId] = $target;
            }
        }

        $defaultTargetId = $this->extractTargetId($draft['defaultTarget'] ?? 'inherit');
        if ($defaultTargetId !== null && !isset($targetsById[$defaultTargetId])) {
            $errors[] = 'Target not found: ' . $defaultTargetId;
        }

        foreach (($draft['steps'] ?? []) as $index => $step) {
            if (!is_array($step)) {
                continue;
            }
            $label = 'Step #' . ($index + 1);
            $templateId = trim((string)($step['templateId'] ?? ''));
            $template = null;
            if ($templateId !== '') {
                if (!array_key_exists($templateId, $templatesById)) {
                    $templatesById[$templateId] = $this->templateManager->getTemplate($templateId);
                }
                $template = $templatesById[$templateId];
            }

            if ($templateId !== '' && !$template) {
                $errors[] = $label . ' template not found: ' . $templateId;
            }

            $stepTargetId = $this->extractTargetId($step['target'] ?? 'inherit');
            if ($stepTargetId !== null && !isset($targetsById[$stepTargetId])) {
                $errors[] = $label . ' target not found: ' . $stepTargetId;
            }
        }

        ['errors' => $runtimeErrors, 'warnings' => $runtimeWarnings] = $this->lintWorkflowRuntimeRules(
            $draft,
            $templatesById
        );
        $errors = array_merge($errors, $runtimeErrors);
        $warnings = array_merge($warnings, $runtimeWarnings);

        ['errors' => $inputErrors, 'warnings' => $inputWarnings] = $this->lintWorkflowInputUsage($draft);
        $errors = array_merge($errors, $inputErrors);
        $warnings = array_merge($warnings, $inputWarnings);

        if (($draft['enabled'] ?? true) === false) {
            $warnings[] = 'This workflow is disabled and will not appear as runnable until it is enabled.';
        }

        $errors = array_values(array_unique($errors));
        $warnings = array_values(array_unique($warnings));
        $capabilityGaps = $this->extractCapabilityGaps($errors, $warnings);

        return [
            'success' => true,
            'draft' => $draft,
            'validation' => [
                'valid' => $errors === [],
                'errors' => $errors,
                'warnings' => $warnings,
                'capabilityGaps' => $capabilityGaps,
                'validatedAt' => date('c'),
            ],
        ];
    }

    public function publishProject(string $projectId, string $userId, string $username): array {
        $project = $this->getProject($projectId);
        if ($project === null) {
            return ['success' => false, 'error' => 'Workflow project not found'];
        }

        $validated = $this->validateProject($projectId);
        if (empty($validated['success'])) {
            return $validated;
        }

        $validation = $validated['validation'] ?? ['valid' => false, 'errors' => ['Validation failed']];
        if (empty($validation['valid'])) {
            return [
                'success' => false,
                'error' => 'Workflow draft has validation errors',
                'validation' => $validation,
            ];
        }

        $draft = $validated['draft'] ?? $project['draft'];
        $publish = $this->commandsManager->saveCommand($draft, $userId, $username);
        if (empty($publish['success'])) {
            return [
                'success' => false,
                'error' => 'Failed to publish workflow draft',
                'publish' => $publish,
                'validation' => $validation,
            ];
        }

        try {
            $this->db->beginTransaction();
            $stmt = $this->db->prepare("
                UPDATE workflow_studio_projects
                SET status = ?,
                    command_id = ?,
                    draft_json = ?,
                    last_published_json = ?,
                    last_validation_json = ?,
                    published_at = datetime('now'),
                    published_by = ?,
                    updated_at = datetime('now'),
                    updated_by = ?
                WHERE id = ?
            ");
            $stmt->execute([
                self::STATUS_PUBLISHED,
                $draft['id'],
                json_encode($draft, JSON_UNESCAPED_SLASHES),
                json_encode($publish['command'] ?? $draft, JSON_UNESCAPED_SLASHES),
                json_encode($validation, JSON_UNESCAPED_SLASHES),
                $userId,
                $userId,
                $projectId,
            ]);

            $this->createVersion($projectId, 'publish', $draft, $validation, $userId);
            $this->db->commit();

            if ($this->auth) {
                $this->auth->auditLog(
                    $userId,
                    $username,
                    'workflow_studio_project.publish',
                    'workflow-project',
                    $projectId,
                    json_encode(['name' => $project['name'], 'commandId' => $draft['id']])
                );
            }

            return [
                'success' => true,
                'project' => $this->getProject($projectId),
                'validation' => $validation,
                'command' => $publish['command'] ?? null,
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            return ['success' => false, 'error' => 'Failed to finalize workflow publish: ' . $e->getMessage()];
        }
    }

    public function archiveProject(string $projectId, string $userId, string $username): array {
        $project = $this->getProject($projectId);
        if ($project === null) {
            return ['success' => false, 'error' => 'Workflow project not found'];
        }

        $stmt = $this->db->prepare("
            UPDATE workflow_studio_projects
            SET status = ?, updated_at = datetime('now'), updated_by = ?
            WHERE id = ?
        ");
        $stmt->execute([self::STATUS_ARCHIVED, $userId, $projectId]);

        if ($this->auth) {
            $this->auth->auditLog(
                $userId,
                $username,
                'workflow_studio_project.archive',
                'workflow-project',
                $projectId,
                json_encode(['name' => $project['name']])
            );
        }

        return ['success' => true, 'project' => $this->getProject($projectId)];
    }

    public function getDefaultDraft(): array {
        return $this->buildDefaultDraft();
    }

    private function normalizeProjectInput(array $input, ?array $existing): array {
        $draft = $input['draft'] ?? ($existing['draft'] ?? $this->buildDefaultDraft());
        if (is_string($draft)) {
            $decoded = json_decode($draft, true);
            $draft = is_array($decoded) ? $decoded : $this->buildDefaultDraft();
        }
        if (!is_array($draft)) {
            $draft = $this->buildDefaultDraft();
        }

        $name = trim((string)($input['name'] ?? $draft['name'] ?? $existing['name'] ?? ''));
        if ($name === '') {
            $name = 'Untitled Workflow';
        }

        $description = trim((string)($input['description'] ?? $draft['description'] ?? $existing['description'] ?? ''));
        $commandId = trim((string)($input['commandId'] ?? $draft['id'] ?? $existing['commandId'] ?? ''));
        if ($commandId === '' && $name !== '') {
            $commandId = $this->slugify($name);
        }

        $status = trim((string)($input['status'] ?? $existing['status'] ?? self::STATUS_DRAFT));
        if (!in_array($status, [self::STATUS_DRAFT, self::STATUS_PUBLISHED, self::STATUS_ARCHIVED], true)) {
            $status = self::STATUS_DRAFT;
        }

        $draft['id'] = $commandId;
        $draft['name'] = $name;
        $draft['description'] = $description;
        $draft['executionMode'] = in_array(strtolower(trim((string)($draft['executionMode'] ?? 'sync'))), ['sync', 'async'], true)
            ? strtolower(trim((string)($draft['executionMode'] ?? 'sync')))
            : 'sync';
        $draft['maxParallel'] = max(1, (int)($draft['maxParallel'] ?? 1));
        $draft['finalStatusPolicy'] = in_array(
            strtolower(trim((string)($draft['finalStatusPolicy'] ?? 'fail_if_any_failed'))),
            ['fail_if_any_failed', 'completed_with_warnings'],
            true
        ) ? strtolower(trim((string)($draft['finalStatusPolicy'] ?? 'fail_if_any_failed'))) : 'fail_if_any_failed';
        $draft['defaultTarget'] = $this->normalizeTargetRef($draft['defaultTarget'] ?? 'inherit');
        $draft['environment'] = $draft['environment'] ?? null;
        $draft['steps'] = $this->normalizeSteps($draft['steps'] ?? []);
        [$draft['inputs'], $draft['inputContract']] = $this->normalizeWorkflowInputsAndContract(
            $draft['inputs'] ?? [],
            $draft['inputContract'] ?? []
        );
        $draft['secrets'] = $this->normalizeSecretsMap($draft['secrets'] ?? []);
        $draft['tags'] = $this->normalizeStringList($draft['tags'] ?? []);
        $draft['icon'] = $draft['icon'] ?? null;
        $draft['color'] = $draft['color'] ?? null;
        $draft['enabled'] = array_key_exists('enabled', $draft) ? (bool)$draft['enabled'] : true;

        return [
            'name' => $name,
            'description' => $description,
            'commandId' => $commandId,
            'status' => $status,
            'draft' => $draft,
        ];
    }

    private function normalizeProjectRow(array $row): array {
        $draft = $this->decodeJsonMap($row['draft_json'] ?? null, $this->buildDefaultDraft());
        $validation = $this->decodeJsonMap($row['last_validation_json'] ?? null);
        $published = $this->decodeJsonMap($row['last_published_json'] ?? null);
        $normalized = $this->normalizeProjectInput([
            'name' => (string)($row['name'] ?? ''),
            'description' => (string)($row['description'] ?? ''),
            'commandId' => (string)($row['command_id'] ?? ''),
            'status' => (string)($row['status'] ?? self::STATUS_DRAFT),
            'draft' => $draft,
        ], null);
        $draft = $normalized['draft'];

        return [
            'id' => (string)($row['id'] ?? ''),
            'name' => (string)($normalized['name'] ?? $row['name'] ?? ''),
            'description' => (string)($normalized['description'] ?? $row['description'] ?? ''),
            'status' => (string)($normalized['status'] ?? $row['status'] ?? self::STATUS_DRAFT),
            'commandId' => trim((string)($normalized['commandId'] ?? $row['command_id'] ?? '')),
            'draft' => $draft,
            'lastValidation' => $validation,
            'lastPublished' => $published,
            'publishedAt' => $row['published_at'] ?? null,
            'publishedBy' => $row['published_by'] ?? null,
            'createdAt' => $row['created_at'] ?? null,
            'createdBy' => $row['created_by'] ?? null,
            'updatedAt' => $row['updated_at'] ?? null,
            'updatedBy' => $row['updated_by'] ?? null,
            'versions' => $this->listVersions((string)($row['id'] ?? '')),
        ];
    }

    private function normalizeVersionRow(array $row): array {
        return [
            'id' => (string)($row['id'] ?? ''),
            'projectId' => (string)($row['project_id'] ?? ''),
            'versionNumber' => (int)($row['version_number'] ?? 0),
            'source' => (string)($row['source'] ?? 'manual-save'),
            'snapshot' => $this->decodeJsonMap($row['snapshot_json'] ?? null, []),
            'validation' => $this->decodeJsonMap($row['validation_json'] ?? null),
            'createdAt' => $row['created_at'] ?? null,
            'createdBy' => $row['created_by'] ?? null,
        ];
    }

    private function createVersion(string $projectId, string $source, array $snapshot, ?array $validation, string $userId): void {
        $stmt = $this->db->prepare("SELECT COALESCE(MAX(version_number), 0) AS version_number FROM workflow_studio_versions WHERE project_id = ?");
        $stmt->execute([$projectId]);
        $nextVersion = ((int)($stmt->fetch(PDO::FETCH_ASSOC)['version_number'] ?? 0)) + 1;

        $insert = $this->db->prepare("
            INSERT INTO workflow_studio_versions (
                id, project_id, version_number, source, snapshot_json, validation_json, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $insert->execute([
            Database::generateUUID(),
            $projectId,
            $nextVersion,
            $source,
            json_encode($snapshot, JSON_UNESCAPED_SLASHES),
            $validation !== null ? json_encode($validation, JSON_UNESCAPED_SLASHES) : null,
            $userId,
        ]);
    }

    private function findProjectByCommandId(string $commandId, string $excludeProjectId): ?array {
        if ($commandId === '') {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT *
            FROM workflow_studio_projects
            WHERE command_id = ?
              AND id != ?
            LIMIT 1
        ");
        $stmt->execute([$commandId, $excludeProjectId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->normalizeProjectRow($row) : null;
    }

    private function buildDefaultDraft(): array {
        $starterStep = $this->buildDefaultStarterStep();

        return [
            'id' => '',
            'name' => '',
            'description' => '',
            'executionMode' => 'sync',
            'maxParallel' => 1,
            'finalStatusPolicy' => 'fail_if_any_failed',
            'defaultTarget' => 'inherit',
            'environment' => null,
            'steps' => $starterStep !== null ? [$starterStep] : [],
            'inputs' => [],
            'inputContract' => [],
            'secrets' => [],
            'tags' => [],
            'icon' => null,
            'color' => null,
            'enabled' => true,
        ];
    }

    private function buildDefaultStarterStep(): ?array {
        $candidates = $this->templateManager->getBestTemplateCandidatesForCapability('shell.glue', [
            'scriptRuntime' => 'sh',
            'requiresScriptInput' => true,
        ]);

        foreach ($candidates as $template) {
            $scriptInput = $this->templateScriptInputName($template);
            if ($scriptInput === null) {
                continue;
            }

            return [
                'id' => 'step-1',
                'templateId' => trim((string)($template['id'] ?? '')),
                'target' => 'inherit',
                'dependsOn' => [],
                'onFailure' => 'stop',
                'inputs' => [$scriptInput => 'echo "Hello from Workflows Studio"'],
                'secrets' => [],
            ];
        }

        return null;
    }

    private function normalizeSteps(array $steps): array {
        if ($steps === []) {
            return $this->buildDefaultDraft()['steps'];
        }

        $normalized = [];
        foreach ($steps as $index => $step) {
            if (!is_array($step)) {
                continue;
            }
            $normalized[] = [
                'id' => trim((string)($step['id'] ?? ('step-' . ($index + 1)))),
                'templateId' => trim((string)($step['templateId'] ?? '')),
                'target' => $this->normalizeTargetRef($step['target'] ?? 'inherit'),
                'dependsOn' => $this->normalizeStringList($step['dependsOn'] ?? []),
                'onFailure' => in_array(strtolower(trim((string)($step['onFailure'] ?? 'stop'))), ['stop', 'continue'], true)
                    ? strtolower(trim((string)($step['onFailure'] ?? 'stop')))
                    : 'stop',
                'inputs' => is_array($step['inputs'] ?? null) ? $step['inputs'] : [],
                'secrets' => $this->normalizeSecretsMap($step['secrets'] ?? []),
            ];
        }

        return $normalized === [] ? $this->buildDefaultDraft()['steps'] : $normalized;
    }

    private function normalizeSecretsMap($value): array {
        if (!is_array($value) || $value === []) {
            return [];
        }

        if ($this->isAssocArray($value)) {
            $normalized = [];
            foreach ($value as $envVar => $secretId) {
                $envVar = trim((string)$envVar);
                $secretId = trim((string)$secretId);
                if ($envVar === '' || $secretId === '') {
                    continue;
                }
                $normalized[$envVar] = $secretId;
            }
            return $normalized;
        }

        $normalized = [];
        foreach ($value as $entry) {
            if (is_string($entry)) {
                $entry = trim($entry);
                if ($entry !== '') {
                    $normalized[$entry] = $entry;
                }
                continue;
            }

            if (!is_array($entry)) {
                continue;
            }

            $envVar = trim((string)($entry['envVar'] ?? $entry['env'] ?? $entry['name'] ?? $entry['key'] ?? ''));
            $secretId = trim((string)($entry['secretId'] ?? $entry['secret'] ?? $entry['value'] ?? ''));
            if ($envVar === '' || $secretId === '') {
                continue;
            }
            $normalized[$envVar] = $secretId;
        }

        return $normalized;
    }

    private function normalizeTargetRef($targetRef) {
        if (is_string($targetRef)) {
            $trimmed = trim($targetRef);
            if ($trimmed === '' || in_array(strtolower($trimmed), ['inherit', 'none'], true)) {
                return 'inherit';
            }
            return $trimmed;
        }
        if (!is_array($targetRef)) {
            return 'inherit';
        }

        $targetId = trim((string)($targetRef['targetId'] ?? ''));
        if ($targetId !== '') {
            return ['targetId' => $targetId];
        }

        $fromEnvironment = trim((string)($targetRef['fromEnvironment'] ?? ''));
        if ($fromEnvironment !== '') {
            return ['fromEnvironment' => $fromEnvironment];
        }

        return 'inherit';
    }

    private function normalizeWorkflowInputsAndContract($inputs, $inputContract): array {
        $normalizedInputs = $this->isAssocArray($inputs) ? $inputs : [];
        $contractByName = [];

        if (is_array($inputContract)) {
            if ($this->isAssocArray($inputContract)) {
                foreach ($inputContract as $name => $field) {
                    $normalizedField = $this->normalizeInputContractField($field, (string)$name);
                    if ($normalizedField !== null) {
                        $contractByName[$normalizedField['name']] = $normalizedField;
                    }
                }
            } else {
                foreach ($inputContract as $field) {
                    $normalizedField = $this->normalizeInputContractField($field);
                    if ($normalizedField !== null) {
                        $contractByName[$normalizedField['name']] = $normalizedField;
                    }
                }
            }
        }

        if ($this->isAssocArray($inputs)) {
            foreach ($inputs as $name => $value) {
                if (!is_string($name) || !$this->looksLikeInputDefinition($value)) {
                    continue;
                }

                $normalizedField = $this->normalizeInputContractField($value, $name);
                if ($normalizedField === null) {
                    continue;
                }

                if (!isset($contractByName[$normalizedField['name']])) {
                    $contractByName[$normalizedField['name']] = $normalizedField;
                }

                if (array_key_exists('default', $value)) {
                    $normalizedInputs[$normalizedField['name']] = $this->normalizeInputDefaultValue(
                        $value['default'],
                        $normalizedField['type']
                    );
                    continue;
                }

                if (!array_key_exists($normalizedField['name'], $normalizedInputs)) {
                    $normalizedInputs[$normalizedField['name']] = $this->defaultValueForInputType($normalizedField['type']);
                }
            }
        }

        return [$normalizedInputs, array_values($contractByName)];
    }

    private function normalizeInputContractField($field, string $fallbackName = ''): ?array {
        if (!is_array($field)) {
            return null;
        }

        $name = trim((string)($field['name'] ?? $fallbackName));
        if ($name === '') {
            return null;
        }

        $type = $this->normalizeInputType($field);
        $options = [];
        $rawOptions = $field['options'] ?? $field['enum'] ?? (($field['items']['enum'] ?? null));
        if (is_array($rawOptions)) {
            foreach ($rawOptions as $option) {
                if (is_array($option)) {
                    $optionValue = array_key_exists('value', $option) ? $option['value'] : ($option['label'] ?? null);
                    if ($optionValue === null) {
                        continue;
                    }
                    $options[] = [
                        'value' => (string)$optionValue,
                        'label' => (string)($option['label'] ?? $optionValue),
                    ];
                    continue;
                }
                $options[] = [
                    'value' => (string)$option,
                    'label' => (string)$option,
                ];
            }
        }

        return [
            'name' => $name,
            'label' => trim((string)($field['label'] ?? $field['title'] ?? $name)),
            'type' => $type,
            'required' => !empty($field['required']),
            'description' => trim((string)($field['description'] ?? '')),
            'placeholder' => trim((string)($field['placeholder'] ?? '')),
            'validation' => is_array($field['validation'] ?? null) ? $field['validation'] : null,
            'options' => $options,
            'default' => array_key_exists('default', $field)
                ? $this->normalizeInputDefaultValue($field['default'], $type)
                : $this->defaultValueForInputType($type),
        ];
    }

    private function normalizeInputType(array $field): string {
        $type = strtolower(trim((string)($field['type'] ?? 'string')));
        if ($type === 'string' && $this->looksLikeStringListField($field)) {
            return 'multiselect';
        }
        if ($type === 'integer') {
            return 'number';
        }
        if ($type === 'object') {
            return 'json';
        }
        if ($type === 'array') {
            $itemType = strtolower(trim((string)($field['items']['type'] ?? 'string')));
            return $itemType === 'string' ? 'multiselect' : 'json';
        }
        if ($type === 'string' && strtolower(trim((string)($field['format'] ?? ''))) === 'json') {
            return 'json';
        }
        if (in_array($type, ['string', 'number', 'boolean', 'select', 'multiselect', 'file', 'secret', 'json'], true)) {
            return $type;
        }
        return 'string';
    }

    private function looksLikeStringListField(array $field): bool {
        $haystack = strtolower(trim(implode(' ', array_filter([
            is_string($field['title'] ?? null) ? $field['title'] : '',
            is_string($field['label'] ?? null) ? $field['label'] : '',
            is_string($field['description'] ?? null) ? $field['description'] : '',
            is_string($field['placeholder'] ?? null) ? $field['placeholder'] : '',
        ]))));

        if ($haystack === '') {
            return false;
        }

        return str_contains($haystack, 'newline-separated')
            || str_contains($haystack, 'list of')
            || str_contains($haystack, 'one per line')
            || str_contains($haystack, 'multiple values');
    }

    private function normalizeInputDefaultValue($value, string $type) {
        if ($value === null) {
            return $this->defaultValueForInputType($type);
        }

        if ($type === 'multiselect') {
            if (is_array($value)) {
                return array_values(array_map('strval', array_filter($value, static fn($item): bool => $item !== null && $item !== '')));
            }
            if (is_string($value)) {
                $parts = preg_split('/\r?\n/', $value) ?: [];
                return array_values(array_filter(array_map('trim', $parts), static fn(string $part): bool => $part !== ''));
            }
            return [];
        }

        if ($type === 'number') {
            return is_numeric($value) ? 0 + $value : '';
        }

        if ($type === 'boolean') {
            return (bool)$value;
        }

        if ($type === 'json') {
            return $value;
        }

        return is_scalar($value) ? (string)$value : $value;
    }

    private function defaultValueForInputType(string $type) {
        return match ($type) {
            'multiselect' => [],
            'boolean' => false,
            default => '',
        };
    }

    private function looksLikeInputDefinition($value): bool {
        if (!is_array($value) || !$this->isAssocArray($value)) {
            return false;
        }

        if (isset($value['name'])) {
            return true;
        }

        return isset($value['type']) || isset($value['title']) || isset($value['label']) || array_key_exists('default', $value);
    }

    private function isAssocArray($value): bool {
        if (!is_array($value)) {
            return false;
        }
        return array_keys($value) !== range(0, count($value) - 1);
    }

    private function extractTargetId($targetRef): ?string {
        if (is_string($targetRef)) {
            $trimmed = trim($targetRef);
            return $trimmed === '' || strtolower($trimmed) === 'inherit' ? null : $trimmed;
        }
        if (!is_array($targetRef)) {
            return null;
        }

        $targetId = trim((string)($targetRef['targetId'] ?? ''));
        return $targetId !== '' ? $targetId : null;
    }

    private function lintWorkflowRuntimeRules(array $draft, array $templatesById): array {
        $errors = [];
        $warnings = [];
        $steps = array_values(array_filter(
            is_array($draft['steps'] ?? null) ? $draft['steps'] : [],
            static fn($step): bool => is_array($step)
        ));
        $stepById = [];
        foreach ($steps as $step) {
            $stepId = trim((string)($step['id'] ?? ''));
            if ($stepId !== '') {
                $stepById[$stepId] = $step;
            }
        }

        $fileWritesByStep = [];
        $fileReadsByStep = [];

        foreach ($steps as $index => $step) {
            $stepId = trim((string)($step['id'] ?? ('step-' . ($index + 1))));
            $label = 'Step `' . $stepId . '`';
            $templateId = trim((string)($step['templateId'] ?? ''));
            $template = is_array($templatesById[$templateId] ?? null) ? $templatesById[$templateId] : null;
            $commandScript = $this->extractShellExecCommand($step, $template);
            $transformScript = $this->extractTransformScript($step, $template);

            if ($commandScript !== null) {
                $stepErrors = $this->lintShellExecToolUsage($commandScript, $label, $template);
                $errors = array_merge($errors, $stepErrors);
                $stepErrors = $this->lintShellExecInterpolatedPayloadUsage($step, $commandScript, $stepById, $templatesById, $label);
                $errors = array_merge($errors, $stepErrors);

                $writes = $this->extractScriptFileWrites($commandScript);
                if ($writes !== []) {
                    $fileWritesByStep[$stepId] = $writes;
                }
                $reads = $this->extractScriptFileReads($commandScript);
                if ($reads !== []) {
                    $fileReadsByStep[$stepId] = $reads;
                }
            }

            if ($transformScript !== null) {
                $stepErrors = $this->lintTransformScriptInterpolationUsage($step, $transformScript, $label, $template);
                $errors = array_merge($errors, $stepErrors);
                $stepErrors = $this->lintTransformParsedPayloadModeUsage($transformScript, $label, $template);
                $errors = array_merge($errors, $stepErrors);
                $stepErrors = $this->lintTransformReservedPayloadIdentifierUsage($transformScript, $label, $template);
                $errors = array_merge($errors, $stepErrors);
                $stepErrors = $this->lintTransformScriptHttpUsage($transformScript, $label, $template);
                $errors = array_merge($errors, $stepErrors);
            }

            $httpErrors = $this->lintHttpStepUsage($step, $stepById, $templatesById, $label);
            $errors = array_merge($errors, $httpErrors);

            $referenceErrors = $this->lintStepReferenceUsage($step, $stepById, $templatesById, $label);
            $errors = array_merge($errors, $referenceErrors);
        }

        foreach ($fileWritesByStep as $producerStepId => $writtenFiles) {
            foreach ($fileReadsByStep as $consumerStepId => $readFiles) {
                if ($producerStepId === $consumerStepId) {
                    continue;
                }
                if (!$this->stepDependsOn($stepById, $consumerStepId, $producerStepId)) {
                    continue;
                }
                $sharedFiles = array_values(array_intersect($writtenFiles, $readFiles));
                if ($sharedFiles === []) {
                    continue;
                }
                $errors[] = sprintf(
                    'Step `%s` reads %s written by step `%s`, but step containers do not share a local filesystem. Pass data through step outputs or runtime inputs instead.',
                    $consumerStepId,
                    $this->formatQuotedList($sharedFiles),
                    $producerStepId
                );
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    private function lintWorkflowInputUsage(array $draft): array {
        $errors = [];
        $warnings = [];
        $referencedInputNames = [];

        $declaredInputs = [];
        foreach ((array)($draft['inputContract'] ?? []) as $field) {
            if (!is_array($field)) {
                continue;
            }
            $name = trim((string)($field['name'] ?? ''));
            if ($name !== '') {
                $declaredInputs[$name] = $field;
            }
        }

        $defaultInputs = is_array($draft['inputs'] ?? null) ? $draft['inputs'] : [];
        $references = $this->collectInterpolationReferences((array)($draft['steps'] ?? []));

        foreach ($references as $ref) {
            if (!str_starts_with($ref, 'inputs.')) {
                continue;
            }

            $parts = array_values(array_filter(
                array_map(static fn($part): string => trim((string)$part), explode('.', $ref)),
                static fn(string $part): bool => $part !== ''
            ));

            if (count($parts) !== 2) {
                $errors[] = sprintf(
                    'Workflow input reference `%s` is invalid. Use the form `inputs.<name>` only.',
                    $ref
                );
                continue;
            }

            $inputName = $parts[1];
            $referencedInputNames[$inputName] = true;
            $isDeclared = array_key_exists($inputName, $declaredInputs);
            $hasDefault = array_key_exists($inputName, $defaultInputs);
            $defaultValue = $defaultInputs[$inputName] ?? null;
            $hasUsableDefault = $this->hasUsableWorkflowDefault($defaultValue);

            if (!$isDeclared && !$hasDefault) {
                $errors[] = sprintf(
                    'Workflow references `inputs.%s`, but that input is neither declared in `inputContract` nor given a default in `inputs`.',
                    $inputName
                );
                continue;
            }

            if (!$isDeclared && !$hasUsableDefault) {
                $errors[] = sprintf(
                    'Workflow references `inputs.%s`, but that input is not exposed in `inputContract` and its default value is empty. Expose it to the operator or provide a real non-empty default.',
                    $inputName
                );
                continue;
            }

            if (!$isDeclared && $hasUsableDefault) {
                $warnings[] = sprintf(
                    'Workflow uses hidden input `inputs.%s` with an internal default. Prefer exposing operator-provided values in `inputContract` unless the input is intentionally internal.',
                    $inputName
                );
            }
        }

        foreach ($declaredInputs as $inputName => $field) {
            if (isset($referencedInputNames[$inputName])) {
                continue;
            }

            $warnings[] = sprintf(
                'Workflow declares input `%s` in `inputContract`, but no step currently references `inputs.%s`. Remove unused operator inputs instead of reserving them for future steps or unfinished features.',
                $inputName,
                $inputName
            );
        }

        return ['errors' => array_values(array_unique($errors)), 'warnings' => array_values(array_unique($warnings))];
    }

    private function lintHttpStepUsage(array $step, array $stepById, array $templatesById, string $label): array {
        $templateId = trim((string)($step['templateId'] ?? ''));
        $template = is_array($templatesById[$templateId] ?? null) ? $templatesById[$templateId] : null;
        if (!$this->templateSupportsCapability($template, 'http.fetch')) {
            return [];
        }

        $inputs = is_array($step['inputs'] ?? null) ? $step['inputs'] : [];
        $pathInput = $this->templateRequestPathInputName($template) ?? 'path';
        $headersInput = $this->templateRequestHeadersInputName($template) ?? 'headers';
        $pathValue = trim((string)($inputs[$pathInput] ?? ''));
        $headersValue = trim((string)($inputs[$headersInput] ?? ''));
        if ($this->templateExpectsPlainHeaders($template) && $headersValue !== '' && preg_match('/^\s*\{.*\}\s*$/s', $headersValue) === 1) {
            $headerExample = $this->convertJsonHeaderStringToPlainText($headersValue);
            return [
                sprintf(
                    '%s sets `%s` as a JSON object string, but template `%s` expects plain text headers like `Header1:Value1,Header2:Value2`. Put auth secrets in inputs or targets, and only use `%s` for extra literal headers.%s',
                    $label
                    ,
                    $headersInput,
                    $templateId
                    ,
                    $headersInput,
                    $headerExample !== null ? ' Equivalent plain-text value: `' . $headerExample . '`.' : ''
                ),
            ];
        }

        if ($pathValue === '') {
            return [];
        }

        $rawPathErrors = $this->lintHttpPathInterpolationUsage($pathValue, $stepById, $templatesById, $label, $pathInput);
        if ($rawPathErrors !== []) {
            return array_merge($rawPathErrors, []);
        }

        if (preg_match('/^\s*\{\{(steps\.[a-zA-Z0-9_.-]+)\}\}\s*$/', $pathValue, $matches) !== 1) {
            return [];
        }

        $ref = trim((string)($matches[1] ?? ''));
        $parts = explode('.', $ref);
        if (count($parts) !== 3 || $parts[0] !== 'steps' || $parts[2] !== 'output') {
            return [];
        }

        $referencedStepId = trim((string)($parts[1] ?? ''));
        $referencedStep = is_array($stepById[$referencedStepId] ?? null) ? $stepById[$referencedStepId] : null;
        if ($referencedStep === null) {
            return [];
        }

        $referencedTemplateId = trim((string)($referencedStep['templateId'] ?? ''));
        $referencedTemplate = is_array($templatesById[$referencedTemplateId] ?? null) ? $templatesById[$referencedTemplateId] : null;
        $referencedOutputType = trim((string)($referencedTemplate['output']['type'] ?? 'text')) ?: 'text';
        if ($referencedOutputType !== 'json') {
            return [];
        }

        $jsonExtractGuidance = $this->capabilityRecommendationText(
            'json.extract',
            ['payloadHandling' => ['structured-json']],
            2
        );

        return [
            sprintf(
                '%s uses `%s` as the HTTP path, but step `%s` returns a JSON response body, not a URL. Extract the URL first, for example with `steps.%s.extract.responseBody` plus an installed JSON-extraction template, or another step that outputs a plain URL string.%s',
                $label,
                $ref,
                $referencedStepId,
                $referencedStepId,
                $jsonExtractGuidance
            ),
        ];
    }

    private function lintHttpPathInterpolationUsage(
        string $pathValue,
        array $stepById,
        array $templatesById,
        string $label,
        string $pathInput
    ): array {
        $rawPayloadRefs = [];

        foreach ($this->collectInterpolationReferences($pathValue) as $ref) {
            if (!preg_match('/^steps\.([a-zA-Z0-9_-]+)\.(output|extract\.responseBody)$/', $ref, $matches)) {
                continue;
            }

            $referencedStepId = trim((string)($matches[1] ?? ''));
            if ($referencedStepId === '' || !isset($stepById[$referencedStepId])) {
                continue;
            }

            $referencedStep = is_array($stepById[$referencedStepId] ?? null) ? $stepById[$referencedStepId] : [];
            $referencedTemplateId = trim((string)($referencedStep['templateId'] ?? ''));
            $referencedTemplate = is_array($templatesById[$referencedTemplateId] ?? null) ? $templatesById[$referencedTemplateId] : null;
            $refKind = trim((string)($matches[2] ?? ''));
            $isRawResponseBody = $refKind === 'extract.responseBody';

            $looksStructured = $isRawResponseBody
                || trim((string)($referencedTemplate['output']['type'] ?? '')) === 'json'
                || $this->stepLikelyOutputsStructuredText($referencedStep, $referencedTemplate);

            if ($looksStructured) {
                $rawPayloadRefs[] = $ref;
            }
        }

        if ($rawPayloadRefs === []) {
            return [];
        }

        $rawPayloadRefs = array_values(array_unique($rawPayloadRefs));
        $jsonExtractGuidance = $this->capabilityRecommendationBundle([
            ['capability' => 'json.extract', 'context' => ['payloadHandling' => ['structured-json']]],
            ['capability' => 'json.count', 'context' => ['payloadHandling' => ['structured-json']]],
        ], 1);
        $payloadTransformGuidance = $this->capabilityRecommendationText(
            'payload.transform',
            ['payloadHandling' => ['structured-json', 'large-text'], 'requiresScriptInput' => true, 'requiresPayloadInputs' => true],
            1
        );

        return [
            sprintf(
                '%s interpolates raw payload %s into HTTP path input `%s`. An HTTP fetch step needs one scalar URL or path, not a full JSON/text payload. Extract the exact scalar first, or use a transform-capable template to produce one plain URL/query value before this step.%s%s',
                $label,
                count($rawPayloadRefs) === 1 ? 'reference `' . $rawPayloadRefs[0] . '`' : 'references ' . $this->formatQuotedList($rawPayloadRefs),
                $pathInput,
                $jsonExtractGuidance,
                $payloadTransformGuidance
            ),
        ];
    }

    private function lintStepReferenceUsage(array $step, array $stepById, array $templatesById, string $label): array {
        $errors = [];
        $stepId = trim((string)($step['id'] ?? ''));
        $references = $this->collectInterpolationReferences(is_array($step['inputs'] ?? null) ? $step['inputs'] : []);

        foreach ($references as $ref) {
            if (!str_starts_with($ref, 'steps.')) {
                continue;
            }

            $parts = array_values(array_filter(
                array_map(static fn($part): string => trim((string)$part), explode('.', $ref)),
                static fn($part): bool => $part !== ''
            ));

            if (count($parts) < 3) {
                $errors[] = sprintf(
                    '%s has invalid step reference `%s`. Use `steps.<id>.output`, `steps.<id>.status`, `steps.<id>.exitCode`, `steps.<id>.error`, or `steps.<id>.extract.<name>`.',
                    $label,
                    $ref
                );
                continue;
            }

            $referencedStepId = $parts[1];
            if (!isset($stepById[$referencedStepId])) {
                $errors[] = sprintf(
                    '%s references unknown step `%s` in `%s`.',
                    $label,
                    $referencedStepId,
                    $ref
                );
                continue;
            }

            if ($stepId === '' || !$this->stepDependsOn($stepById, $stepId, $referencedStepId)) {
                $errors[] = sprintf(
                    '%s references step `%s`, but it is not a declared dependency. Add `%s` to `dependsOn` or stop referencing it.',
                    $label,
                    $referencedStepId,
                    $referencedStepId
                );
                continue;
            }

            $root = $parts[2];
            if (in_array($root, ['status', 'exitCode', 'error', 'output'], true)) {
                if (count($parts) !== 3) {
                    $referencedStep = is_array($stepById[$referencedStepId] ?? null) ? $stepById[$referencedStepId] : [];
                    $referencedTemplateId = trim((string)($referencedStep['templateId'] ?? ''));
                    $referencedTemplate = is_array($templatesById[$referencedTemplateId] ?? null) ? $templatesById[$referencedTemplateId] : null;
                    $outputType = trim((string)($referencedTemplate['output']['type'] ?? 'text')) ?: 'text';
                    $extraHint = $root === 'output'
                        ? sprintf(
                            ' `%s.%s` is raw text%s. Parse that text in a later step or use `extract.<name>` if the template defines extractors.',
                            $parts[0] . '.' . $parts[1],
                            $root,
                            $outputType !== '' ? ' (template output type: `' . $outputType . '`)' : ''
                        )
                        : '';
                    $errors[] = sprintf(
                        '%s references `%s`, but `%s` is a scalar step field. Use `%s.%s` directly without extra path segments.%s',
                        $label,
                        $ref,
                        $root,
                        $parts[0] . '.' . $parts[1],
                        $root,
                        $extraHint
                    );
                }
                continue;
            }

            if ($root !== 'extract') {
                $errors[] = sprintf(
                    '%s references `%s`, but only `status`, `exitCode`, `error`, `output`, and `extract.<name>` are available from prior steps.',
                    $label,
                    $ref
                );
                continue;
            }

            if (count($parts) !== 4) {
                $errors[] = sprintf(
                    '%s references `%s`, but extracted values must use the form `steps.<id>.extract.<name>`.',
                    $label,
                    $ref
                );
                continue;
            }

            $extractorName = $parts[3];
            $referencedStep = is_array($stepById[$referencedStepId] ?? null) ? $stepById[$referencedStepId] : [];
            $referencedTemplateId = trim((string)($referencedStep['templateId'] ?? ''));
            $referencedTemplate = is_array($templatesById[$referencedTemplateId] ?? null) ? $templatesById[$referencedTemplateId] : null;
            $extractorNames = $this->extractTemplateExtractorNames($referencedTemplate);

            if (!in_array($extractorName, $extractorNames, true)) {
                $templateLabel = $referencedTemplateId !== '' ? 'template `' . $referencedTemplateId . '`' : 'that template';
                $suffix = $extractorNames !== []
                    ? ' Available extractors: ' . $this->formatQuotedList($extractorNames) . '.'
                    : ' That step template does not define any extractors.';
                $errors[] = sprintf(
                    '%s references `%s`, but step `%s` using %s does not expose extractor `%s`.%s',
                    $label,
                    $ref,
                    $referencedStepId,
                    $templateLabel,
                    $extractorName,
                    $suffix
                );
            }
        }

        return $errors;
    }

    private function extractShellExecCommand(array $step, ?array $template): ?string {
        if (!$this->templateSupportsCapability($template, 'shell.glue')) {
            return null;
        }
        $scriptInput = $this->templateScriptInputName($template);
        if ($scriptInput === null) {
            return null;
        }
        $inputs = is_array($step['inputs'] ?? null) ? $step['inputs'] : [];
        $command = $inputs[$scriptInput] ?? null;
        if (!is_string($command)) {
            return null;
        }
        $command = trim($command);
        return $command !== '' ? $command : null;
    }

    private function extractTransformScript(array $step, ?array $template): ?string {
        if (!$this->templateSupportsCapability($template, 'payload.transform')) {
            return null;
        }
        $scriptInput = $this->templateScriptInputName($template);
        if ($scriptInput === null || $this->templateInputType($template, $scriptInput) !== 'string') {
            return null;
        }
        $inputs = is_array($step['inputs'] ?? null) ? $step['inputs'] : [];
        $script = $inputs[$scriptInput] ?? null;
        if (!is_string($script)) {
            return null;
        }
        $script = trim($script);
        return $script !== '' ? $script : null;
    }

    private function lintTransformScriptInterpolationUsage(array $step, string $script, string $label, ?array $template): array {
        $references = array_values(array_unique(array_filter(
            $this->collectInterpolationReferences($script),
            static fn(string $ref): bool => str_starts_with($ref, 'inputs.') || str_starts_with($ref, 'steps.')
        )));

        if ($references === []) {
            return [];
        }

        $payloadInputs = $this->listTemplatePayloadInputs($template);
        $payloadLabel = $payloadInputs !== []
            ? ' dedicated payload inputs like ' . $this->formatQuotedList($payloadInputs)
            : ' dedicated non-script template inputs';
        $runtimeHint = $this->templateScriptRuntime($template) === 'python'
            ? ' Interpolated values are inserted as raw text and often break Python syntax or JSON parsing.'
            : '';

        return [
            sprintf(
                '%s embeds workflow placeholder %s directly into a transform script. Keep transform scripts static and pass dynamic workflow data through%s instead, then read it from the script runtime.%s',
                $label,
                count($references) === 1 ? '`' . $references[0] . '`' : $this->formatQuotedList($references),
                $payloadLabel,
                $runtimeHint
            ),
        ];
    }

    private function lintTransformParsedPayloadModeUsage(string $script, string $label, ?array $template): array {
        if ($this->templatePayloadValueMode($template) !== 'parsed-json-or-raw') {
            return [];
        }

        $payloadInputs = $this->listTemplatePayloadInputs($template);
        if ($payloadInputs === []) {
            return [];
        }

        $errors = [];
        $rawSuffix = $this->templatePayloadRawSuffix($template) ?? '_raw';

        foreach ($payloadInputs as $payloadInput) {
            $pattern = '/json\.loads\(\s*' . preg_quote($payloadInput, '/') . '\b/s';
            if (preg_match($pattern, $script) !== 1) {
                continue;
            }

            $errors[] = sprintf(
                '%s calls `json.loads(%s)` even though `%s` is already parsed when the payload contains valid JSON. Use `%s` directly for parsed data, or use `%s%s` if you truly need the original raw string.',
                $label,
                $payloadInput,
                $payloadInput,
                $payloadInput,
                $payloadInput,
                $rawSuffix
            );
        }

        return $errors;
    }

    private function lintTransformReservedPayloadIdentifierUsage(string $script, string $label, ?array $template): array {
        $payloadInputs = $this->listTemplatePayloadInputs($template);
        if ($payloadInputs === []) {
            return [];
        }

        $prefix = $this->detectNumberedPayloadPrefix($payloadInputs);
        if ($prefix === null) {
            return [];
        }

        $rawSuffix = $this->templatePayloadRawSuffix($template) ?? '_raw';
        $allowed = [];
        foreach ($payloadInputs as $payloadInput) {
            $allowed[$payloadInput] = true;
            $allowed[$payloadInput . $rawSuffix] = true;
        }

        if (preg_match_all('/\b(' . preg_quote($prefix, '/') . '\d+(?:' . preg_quote($rawSuffix, '/') . ')?)\b/', $script, $matches) < 1) {
            return [];
        }

        $unknown = [];
        foreach (($matches[1] ?? []) as $identifier) {
            $identifier = trim((string)$identifier);
            if ($identifier === '' || isset($allowed[$identifier])) {
                continue;
            }
            $unknown[$identifier] = true;
        }

        if ($unknown === []) {
            return [];
        }

        return [
            sprintf(
                '%s references undeclared transform runtime value %s. This template only exposes %s%s. If you need more payload slots, use a different template or reshape the workflow first.',
                $label,
                count($unknown) === 1 ? '`' . array_key_first($unknown) . '`' : $this->formatQuotedList(array_keys($unknown)),
                $this->formatQuotedList($payloadInputs),
                $rawSuffix !== '' ? ' plus the matching raw variants with suffix `' . $rawSuffix . '`' : ''
            ),
        ];
    }

    private function lintTransformScriptHttpUsage(string $script, string $label, ?array $template): array {
        if (!$this->scriptLooksLikeHttpUsage($script)) {
            return [];
        }

        $httpFetchGuidance = $this->capabilityRecommendationText(
            'http.fetch',
            [],
            2
        );

        return [
            sprintf(
                '%s performs HTTP or API calls inside a transform script. Keep transform-capable templates for sorting, filtering, joining, or reshaping already-fetched payloads. Fetch each external response in dedicated HTTP steps first, then pass those results into the transform step via payload inputs.%s',
                $label,
                $httpFetchGuidance
            ),
        ];
    }

    private function lintShellExecToolUsage(string $script, string $label, ?array $template): array {
        $errors = [];
        $requirements = array_map(
            static fn($item): string => strtolower(trim((string)$item)),
            is_array($template['requirements'] ?? null) ? $template['requirements'] : []
        );
        $templateId = trim((string)($template['id'] ?? ''));
        $templateLabel = $templateId !== '' ? 'template `' . $templateId . '`' : 'the selected shell-glue template';
        $looksLikeHttpWorkflow = preg_match('/https?:\/\//i', $script) === 1
            || preg_match('/\b(curl|wget)\b/i', $script) === 1
            || str_contains($script, 'HTTP_ENDPOINT');

        $toolPatterns = [
            'curl' => '/(^|[^a-zA-Z0-9_-])curl(\s|$)/m',
            'wget' => '/(^|[^a-zA-Z0-9_-])wget(\s|$)/m',
            'python3' => '/(^|[^a-zA-Z0-9_-])python3(\s|$)/m',
            'python' => '/(^|[^a-zA-Z0-9_-])python(\s|$)/m',
            'jq' => '/(^|[^a-zA-Z0-9_-])jq(\s|$)/m',
            'node' => '/(^|[^a-zA-Z0-9_-])node(\s|$)/m',
            'npm' => '/(^|[^a-zA-Z0-9_-])npm(\s|$)/m',
            'bash' => '/(^|[^a-zA-Z0-9_-])bash(\s|$)/m',
            'gh' => '/(^|[^a-zA-Z0-9_-])gh(\s|$)/m',
            'git' => '/(^|[^a-zA-Z0-9_-])git(\s|$)/m',
        ];

        foreach ($toolPatterns as $tool => $pattern) {
            if (preg_match($pattern, $script) !== 1) {
                continue;
            }
            if (in_array($tool, $requirements, true)) {
                continue;
            }
            if ($tool === 'python' && in_array('python3', $requirements, true)) {
                continue;
            }
            if ($this->scriptInstallsTool($script, $tool)) {
                continue;
            }
            $suggestion = '';
            $parsesResponseBody = str_contains($script, '.extract.responseBody');
            if (in_array($tool, ['curl', 'wget'], true) && $looksLikeHttpWorkflow) {
                $suggestion = ' For external APIs, prefer an installed HTTP-fetch template instead of generic shell glue.' .
                    $this->capabilityRecommendationText('http.fetch', [], 2);
            } elseif (in_array($tool, ['python3', 'python'], true) && ($parsesResponseBody || str_contains($script, '{{steps.'))) {
                $suggestion = ' This step appears to process upstream workflow payloads in script logic. Prefer an installed transform-capable template for that logic, and only keep shell glue for small orchestration or formatting.' .
                    $this->capabilityRecommendationText(
                        'payload.transform',
                        ['payloadHandling' => ['structured-json', 'large-text'], 'requiresScriptInput' => true, 'requiresPayloadInputs' => true],
                        2
                    );
            } elseif (in_array($tool, ['jq'], true) && $parsesResponseBody) {
                $suggestion = ' This step appears to parse prior HTTP JSON responses. Prefer installed JSON-extraction or JSON-counting templates for the scalar work, then keep the final formatting step small.' .
                    $this->capabilityRecommendationBundle([
                        ['capability' => 'json.extract', 'context' => ['payloadHandling' => ['structured-json']]],
                        ['capability' => 'json.count', 'context' => ['payloadHandling' => ['structured-json']]],
                        ['capability' => 'text.format', 'context' => ['requiresScriptInput' => true]],
                    ], 1);
            } elseif (in_array($tool, ['python3', 'python', 'jq'], true)) {
                $suggestion = ' If this step must parse JSON, either install the parser in the same script or use installed transform, JSON-extraction, or JSON-counting templates, or use a template extractor and reference `steps.<id>.extract.<name>` later.' .
                    $this->capabilityRecommendationBundle([
                        ['capability' => 'payload.transform', 'context' => ['requiresScriptInput' => true, 'requiresPayloadInputs' => true]],
                        ['capability' => 'json.extract', 'context' => ['payloadHandling' => ['structured-json']]],
                        ['capability' => 'json.count', 'context' => ['payloadHandling' => ['structured-json']]],
                    ], 1);
            }
            $errors[] = sprintf(
                '%s uses `%s`, but %s only guarantees %s. Use a template/runtime that provides `%s`, or install it explicitly inside the same step.%s',
                $label,
                $tool,
                $templateLabel,
                $requirements !== [] ? $this->formatQuotedList($requirements) : '`sh`',
                $tool,
                $suggestion
            );
        }

        return $errors;
    }

    private function lintShellExecInterpolatedPayloadUsage(
        array $step,
        string $script,
        array $stepById,
        array $templatesById,
        string $label
    ): array {
        $errors = [];
        $rawPayloadRefs = [];

        foreach ($this->collectInterpolationReferences($script) as $ref) {
            if (!preg_match('/^steps\.([a-zA-Z0-9_-]+)\.(output|extract\.responseBody)$/', $ref, $matches)) {
                continue;
            }

            $referencedStepId = trim((string)($matches[1] ?? ''));
            if ($referencedStepId === '' || !isset($stepById[$referencedStepId])) {
                continue;
            }

            $referencedStep = is_array($stepById[$referencedStepId] ?? null) ? $stepById[$referencedStepId] : [];
            $referencedTemplateId = trim((string)($referencedStep['templateId'] ?? ''));
            $referencedTemplate = is_array($templatesById[$referencedTemplateId] ?? null) ? $templatesById[$referencedTemplateId] : null;
            $extractorRef = ($matches[2] ?? '') === 'extract.responseBody';

            $isLikelyLargePayload = $extractorRef
                || $this->templateSupportsCapability($referencedTemplate, 'shell.glue')
                || $this->templateSupportsCapability($referencedTemplate, 'http.fetch')
                || trim((string)($referencedTemplate['output']['type'] ?? '')) === 'json';

            if ($isLikelyLargePayload) {
                $rawPayloadRefs[] = $ref;
            }
        }

        if ($rawPayloadRefs === []) {
            return [];
        }

        $references = array_values(array_unique($rawPayloadRefs));
        $payloadTransformGuidance = $this->capabilityRecommendationText(
            'payload.transform',
            ['payloadHandling' => ['structured-json', 'large-text'], 'requiresScriptInput' => true, 'requiresPayloadInputs' => true],
            2
        );
        $jsonScalarGuidance = $this->capabilityRecommendationBundle([
            ['capability' => 'json.extract', 'context' => ['payloadHandling' => ['structured-json']]],
            ['capability' => 'json.count', 'context' => ['payloadHandling' => ['structured-json']]],
        ], 1);
        $errors[] = sprintf(
            '%s inlines raw payload %s into a shell-glue command. That often exceeds the shell command length limit and makes the workflow brittle. Extract only the scalar values you need first with installed JSON-extraction or counting templates, or move the payload processing into an installed transform-capable template using payload inputs, then keep the shell step to small formatting or orchestration logic.%s%s',
            $label,
            count($references) === 1 ? 'reference `' . $references[0] . '`' : 'references ' . $this->formatQuotedList($references),
            $jsonScalarGuidance,
            $payloadTransformGuidance
        );

        return $errors;
    }

    private function templateSupportsCapability(?array $template, string $capability): bool {
        if (!is_array($template)) {
            return false;
        }

        $hints = is_array($template['capabilityHints'] ?? null) ? $template['capabilityHints'] : [];
        $capabilities = is_array($hints['capabilities'] ?? null) ? $hints['capabilities'] : [];

        return in_array($capability, $capabilities, true);
    }

    private function capabilityRecommendationText(string $capability, array $context = [], int $limit = 2): string {
        $candidates = $this->templateManager->getBestTemplateCandidatesForCapability($capability, $context);
        $ids = array_map(
            static fn(array $template): string => trim((string)($template['id'] ?? '')),
            array_slice($candidates, 0, max(1, $limit))
        );
        $ids = array_values(array_filter($ids));

        if ($ids === []) {
            return ' No installed template currently advertises `' . $capability . '`.';
        }

        return ' Installed candidates: `' . implode('`, `', $ids) . '`.';
    }

    private function capabilityRecommendationBundle(array $requests, int $limit = 1): string {
        $parts = [];

        foreach ($requests as $request) {
            if (!is_array($request)) {
                continue;
            }

            $capability = trim((string)($request['capability'] ?? ''));
            if ($capability === '') {
                continue;
            }

            $context = is_array($request['context'] ?? null) ? $request['context'] : [];
            $candidates = $this->templateManager->getBestTemplateCandidatesForCapability($capability, $context);
            $ids = array_map(
                static fn(array $template): string => trim((string)($template['id'] ?? '')),
                array_slice($candidates, 0, max(1, $limit))
            );
            $ids = array_values(array_filter($ids));

            $parts[] = $ids === []
                ? '`' . $capability . '` has no installed match'
                : '`' . $capability . '` -> `' . implode('`, `', $ids) . '`';
        }

        if ($parts === []) {
            return '';
        }

        return ' Candidates: ' . implode('; ', $parts) . '.';
    }

    private function extractCapabilityGaps(array $errors, array $warnings): array {
        $messages = array_merge($errors, $warnings);
        $gaps = [];
        $seen = [];

        foreach ($messages as $message) {
            if (!is_string($message) || trim($message) === '') {
                continue;
            }

            foreach ($this->extractCapabilityNamesFromMessage($message) as $capability) {
                if (isset($seen[$capability])) {
                    continue;
                }

                $seen[$capability] = true;
                $gaps[] = [
                    'capability' => $capability,
                    'label' => $this->formatCapabilityLabel($capability),
                    'summary' => sprintf(
                        'This workflow needs `%s`, but no installed template currently advertises that capability.',
                        $capability
                    ),
                ];
            }
        }

        return $gaps;
    }

    private function extractCapabilityNamesFromMessage(string $message): array {
        $patterns = [
            '/No installed template currently advertises `([^`]+)`\./',
            '/`([^`]+)` has no installed match/',
        ];
        $capabilities = [];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $message, $matches) < 1) {
                continue;
            }

            foreach (($matches[1] ?? []) as $capability) {
                $capability = trim((string)$capability);
                if ($capability !== '') {
                    $capabilities[] = $capability;
                }
            }
        }

        return array_values(array_unique($capabilities));
    }

    private function formatCapabilityLabel(string $capability): string {
        $parts = array_values(array_filter(
            array_map(
                static fn(string $part): string => trim(str_replace(['-', '_'], ' ', $part)),
                explode('.', strtolower(trim($capability)))
            ),
            static fn(string $part): bool => $part !== ''
        ));

        if ($parts === []) {
            return 'Capability';
        }

        return implode(' / ', array_map(
            static fn(string $part): string => ucwords($part),
            $parts
        ));
    }

    private function templateScriptInputName(?array $template): ?string {
        if (!is_array($template)) {
            return null;
        }

        $scriptInput = trim((string)($template['capabilityHints']['dataFlow']['scriptInput'] ?? ''));
        if ($scriptInput === '') {
            return null;
        }

        return $scriptInput;
    }

    private function templateScriptRuntime(?array $template): ?string {
        if (!is_array($template)) {
            return null;
        }

        $runtime = trim((string)($template['capabilityHints']['scriptRuntime'] ?? ''));
        return $runtime !== '' ? $runtime : null;
    }

    private function templatePayloadValueMode(?array $template): ?string {
        if (!is_array($template)) {
            return null;
        }

        $mode = trim((string)($template['capabilityHints']['dataFlow']['payloadValueMode'] ?? ''));
        return $mode !== '' ? $mode : null;
    }

    private function templatePayloadRawSuffix(?array $template): ?string {
        if (!is_array($template)) {
            return null;
        }

        $suffix = trim((string)($template['capabilityHints']['dataFlow']['payloadRawSuffix'] ?? ''));
        return $suffix !== '' ? $suffix : null;
    }

    private function listTemplatePayloadInputs(?array $template): array {
        if (!is_array($template)) {
            return [];
        }

        $inputs = array_map(
            static fn($value): string => trim((string)$value),
            (array)($template['capabilityHints']['dataFlow']['payloadInputs'] ?? [])
        );

        return array_values(array_filter($inputs, static fn(string $value): bool => $value !== ''));
    }

    private function templateExpectsPlainHeaders(?array $template): bool {
        $headersInput = $this->templateRequestHeadersInputName($template) ?? 'headers';
        $type = $this->templateInputType($template, $headersInput);
        return in_array($type, ['string', 'text'], true);
    }

    private function templateRequestPathInputName(?array $template): ?string {
        if (!is_array($template)) {
            return null;
        }

        $input = trim((string)($template['capabilityHints']['dataFlow']['requestPathInput'] ?? ''));
        return $input !== '' ? $input : null;
    }

    private function templateRequestHeadersInputName(?array $template): ?string {
        if (!is_array($template)) {
            return null;
        }

        $input = trim((string)($template['capabilityHints']['dataFlow']['requestHeadersInput'] ?? ''));
        return $input !== '' ? $input : null;
    }

    private function stepLikelyOutputsStructuredText(array $step, ?array $template): bool {
        if ($this->templateSupportsCapability($template, 'payload.transform')) {
            $script = $this->extractTransformScript($step, $template);
            if (is_string($script) && preg_match('/json\.dumps\s*\(/', $script) === 1) {
                return true;
            }
        }

        $scriptInput = $this->templateScriptInputName($template);
        if ($scriptInput !== null) {
            $inputs = is_array($step['inputs'] ?? null) ? $step['inputs'] : [];
            $script = $inputs[$scriptInput] ?? null;
            if (is_string($script) && preg_match('/json\.dumps\s*\(/', $script) === 1) {
                return true;
            }
        }

        return false;
    }

    private function templateInputType(?array $template, string $inputName): ?string {
        if (!is_array($template)) {
            return null;
        }

        foreach ((array)($template['inputs'] ?? []) as $input) {
            if (!is_array($input)) {
                continue;
            }

            if (trim((string)($input['name'] ?? '')) !== $inputName) {
                continue;
            }

            $type = strtolower(trim((string)($input['type'] ?? 'string')));
            return $type !== '' ? $type : 'string';
        }

        return null;
    }

    private function scriptLooksLikeHttpUsage(string $script): bool {
        $patterns = [
            '/\burllib\.request\b/',
            '/\bfetch\s*\(/',
            '/\baxios\b/',
            '/\bhttpx\b/',
            '/\brequests?\b/',
            '/\burlopen\s*\(/',
            '/\bXMLHttpRequest\b/',
            '/\bcurl\b/',
            '/\bwget\b/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $script) === 1) {
                return true;
            }
        }

        return false;
    }

    private function convertJsonHeaderStringToPlainText(string $headersValue): ?string {
        $decoded = json_decode($headersValue, true);
        if (!is_array($decoded) || array_keys($decoded) === range(0, count($decoded) - 1)) {
            return null;
        }

        $parts = [];
        foreach ($decoded as $key => $value) {
            $headerName = trim((string)$key);
            if ($headerName === '') {
                continue;
            }
            if (is_array($value) || is_object($value)) {
                return null;
            }
            $parts[] = $headerName . ':' . trim((string)$value);
        }

        return $parts !== [] ? implode(',', $parts) : null;
    }

    private function hasUsableWorkflowDefault($value): bool {
        if (is_string($value)) {
            return trim($value) !== '';
        }

        if (is_array($value)) {
            return $value !== [];
        }

        return $value !== null;
    }

    private function detectNumberedPayloadPrefix(array $payloadInputs): ?string {
        $prefix = null;

        foreach ($payloadInputs as $payloadInput) {
            if (!preg_match('/^([A-Za-z_]+)\d+$/', trim((string)$payloadInput), $matches)) {
                return null;
            }

            $currentPrefix = trim((string)($matches[1] ?? ''));
            if ($currentPrefix === '') {
                return null;
            }

            if ($prefix === null) {
                $prefix = $currentPrefix;
                continue;
            }

            if ($prefix !== $currentPrefix) {
                return null;
            }
        }

        return $prefix;
    }

    private function scriptInstallsTool(string $script, string $tool): bool {
        $toolPattern = preg_quote($tool, '/');
        $installPatterns = [
            '/apk\s+add(?:\s+--[^\s]+)*\s+.*\b' . $toolPattern . '\b/i',
            '/apt(?:-get)?\s+install(?:\s+-[^\s]+)*\s+.*\b' . $toolPattern . '\b/i',
            '/yum\s+install(?:\s+-[^\s]+)*\s+.*\b' . $toolPattern . '\b/i',
            '/dnf\s+install(?:\s+-[^\s]+)*\s+.*\b' . $toolPattern . '\b/i',
        ];
        foreach ($installPatterns as $pattern) {
            if (preg_match($pattern, $script) === 1) {
                return true;
            }
        }
        return false;
    }

    private function collectInterpolationReferences($value): array {
        $seen = [];
        $walk = function ($item) use (&$walk, &$seen): void {
            if (is_string($item)) {
                if (preg_match_all('/\{\{#if\s+([a-zA-Z0-9_.-]+)\}\}/', $item, $ifMatches) > 0) {
                    foreach ($ifMatches[1] as $match) {
                        $ref = trim((string)$match);
                        if ($ref !== '') {
                            $seen[$ref] = true;
                        }
                    }
                }
                if (preg_match_all('/\{\{([a-zA-Z0-9_.-]+)\}\}/', $item, $placeholderMatches) > 0) {
                    foreach ($placeholderMatches[1] as $match) {
                        $ref = trim((string)$match);
                        if ($ref !== '') {
                            $seen[$ref] = true;
                        }
                    }
                }
                return;
            }

            if (is_array($item)) {
                foreach ($item as $child) {
                    $walk($child);
                }
            }
        };

        $walk($value);
        $refs = array_keys($seen);
        sort($refs);
        return $refs;
    }

    private function extractTemplateExtractorNames(?array $template): array {
        $output = is_array($template['output'] ?? null) ? $template['output'] : [];
        $extractors = is_array($output['extractors'] ?? null) ? $output['extractors'] : [];
        $names = [];

        foreach ($extractors as $extractor) {
            if (!is_array($extractor)) {
                continue;
            }
            $name = trim((string)($extractor['name'] ?? ''));
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    private function extractScriptFileWrites(string $script): array {
        $matches = [];
        $files = [];
        if (preg_match_all('/(?:^|[;&|]\s*|\s)(?:tee\s+|cat\s+[^|\n]+\s+>\s*|>\s*|>>\s*)([A-Za-z0-9._\/-]+\.[A-Za-z0-9._-]+)/m', $script, $matches)) {
            $files = array_merge($files, $matches[1]);
        }
        return $this->normalizeScriptPaths($files);
    }

    private function extractScriptFileReads(string $script): array {
        $matches = [];
        $files = [];
        if (preg_match_all('/(?:cat|open|with\s+open)\s*(?:\(\s*)?[\'"]?([A-Za-z0-9._\/-]+\.[A-Za-z0-9._-]+)/m', $script, $matches)) {
            $files = array_merge($files, $matches[1]);
        }
        if (preg_match_all('/<\s*([A-Za-z0-9._\/-]+\.[A-Za-z0-9._-]+)/m', $script, $matches)) {
            $files = array_merge($files, $matches[1]);
        }
        return $this->normalizeScriptPaths($files);
    }

    private function normalizeScriptPaths(array $paths): array {
        $normalized = [];
        foreach ($paths as $path) {
            $value = trim((string)$path);
            if ($value === '' || str_starts_with($value, '/dev/')) {
                continue;
            }
            $normalized[] = $value;
        }
        return array_values(array_unique($normalized));
    }

    private function stepDependsOn(array $stepById, string $consumerStepId, string $producerStepId, array $visited = []): bool {
        if ($consumerStepId === $producerStepId) {
            return false;
        }
        if (isset($visited[$consumerStepId])) {
            return false;
        }
        $visited[$consumerStepId] = true;
        $step = $stepById[$consumerStepId] ?? null;
        if (!is_array($step)) {
            return false;
        }
        $dependsOn = is_array($step['dependsOn'] ?? null) ? $step['dependsOn'] : [];
        foreach ($dependsOn as $dependency) {
            $dependency = trim((string)$dependency);
            if ($dependency === '') {
                continue;
            }
            if ($dependency === $producerStepId) {
                return true;
            }
            if ($this->stepDependsOn($stepById, $dependency, $producerStepId, $visited)) {
                return true;
            }
        }
        return false;
    }

    private function formatQuotedList(array $items): string {
        $quoted = array_map(
            static fn($item): string => '`' . trim((string)$item) . '`',
            array_values(array_filter($items, static fn($item): bool => trim((string)$item) !== ''))
        );
        if ($quoted === []) {
            return '';
        }
        if (count($quoted) === 1) {
            return $quoted[0];
        }
        return implode(', ', $quoted);
    }

    private function decodeJsonMap($value, $default = null) {
        if (!is_string($value) || trim($value) === '') {
            return $default;
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : $default;
    }

    private function normalizeStringList($values): array {
        if (!is_array($values)) {
            return [];
        }

        $normalized = [];
        foreach ($values as $value) {
            $trimmed = trim((string)$value);
            if ($trimmed !== '') {
                $normalized[] = $trimmed;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function slugify(string $value): string {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        $value = trim($value, '-');

        return $value !== '' ? $value : 'workflow';
    }

    private function generateProjectId(string $seed): string {
        $base = 'workflow-' . $this->slugify($seed);
        $candidate = $base;
        $suffix = 2;

        while ($this->getProject($candidate) !== null) {
            $candidate = $base . '-' . $suffix;
            $suffix++;
        }

        return $candidate;
    }
}
