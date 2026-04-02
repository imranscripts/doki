<?php
/**
 * TemplateStudioManager.php - Draft/project management for reusable templates
 */

require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/TemplateManager.php';

class TemplateStudioManager {
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED = 'archived';

    private PDO $db;
    private TemplateManager $templateManager;
    private ?Auth $auth;

    public function __construct(?Auth $auth = null) {
        $this->db = Database::getInstance();
        $this->templateManager = new TemplateManager();
        $this->auth = $auth;
    }

    public function listProjects(): array {
        $stmt = $this->db->query("
            SELECT *
            FROM template_studio_projects
            ORDER BY datetime(updated_at) DESC, name COLLATE NOCASE ASC, id ASC
        ");

        return array_map(fn(array $row): array => $this->normalizeProjectRow($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function getProject(string $projectId): ?array {
        $stmt = $this->db->prepare("SELECT * FROM template_studio_projects WHERE id = ? LIMIT 1");
        $stmt->execute([$projectId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->normalizeProjectRow($row) : null;
    }

    public function listVersions(string $projectId): array {
        $stmt = $this->db->prepare("
            SELECT *
            FROM template_studio_versions
            WHERE project_id = ?
            ORDER BY version_number DESC, created_at DESC, id DESC
        ");
        $stmt->execute([$projectId]);

        return array_map(fn(array $row): array => $this->normalizeVersionRow($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function saveProject(array $input, string $userId, string $username, ?string $projectId = null): array {
        $existing = $projectId ? $this->getProject($projectId) : null;
        if ($projectId !== null && $existing === null) {
            return ['success' => false, 'error' => 'Template project not found'];
        }

        $payload = $this->normalizeProjectInput($input, $existing);
        $projectId = $existing['id'] ?? $this->generateProjectId((string)($payload['name'] ?? 'template-project'));

        $conflict = $this->findProjectByTemplateId((string)($payload['templateId'] ?? ''), $projectId);
        if ($conflict !== null) {
            return ['success' => false, 'error' => 'Another template project already uses template ID `' . $payload['templateId'] . '`'];
        }

        $draftJson = json_encode($payload['draft'], JSON_UNESCAPED_SLASHES);

        try {
            $this->db->beginTransaction();

            if ($existing === null) {
                $stmt = $this->db->prepare("
                    INSERT INTO template_studio_projects (
                        id, name, description, status, template_id,
                        source_type, repository_id, source_path, source_ref,
                        draft_json, created_by, updated_by
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $projectId,
                    $payload['name'],
                    $payload['description'],
                    $payload['status'],
                    $payload['templateId'] !== '' ? $payload['templateId'] : null,
                    $payload['sourceType'],
                    $payload['repositoryId'] !== '' ? $payload['repositoryId'] : null,
                    $payload['sourcePath'] !== '' ? $payload['sourcePath'] : null,
                    $payload['sourceRef'] !== '' ? $payload['sourceRef'] : null,
                    $draftJson,
                    $userId,
                    $userId,
                ]);
            } else {
                $stmt = $this->db->prepare("
                    UPDATE template_studio_projects
                    SET name = ?,
                        description = ?,
                        status = ?,
                        template_id = ?,
                        source_type = ?,
                        repository_id = ?,
                        source_path = ?,
                        source_ref = ?,
                        draft_json = ?,
                        updated_at = datetime('now'),
                        updated_by = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $payload['name'],
                    $payload['description'],
                    $payload['status'],
                    $payload['templateId'] !== '' ? $payload['templateId'] : null,
                    $payload['sourceType'],
                    $payload['repositoryId'] !== '' ? $payload['repositoryId'] : null,
                    $payload['sourcePath'] !== '' ? $payload['sourcePath'] : null,
                    $payload['sourceRef'] !== '' ? $payload['sourceRef'] : null,
                    $draftJson,
                    $userId,
                    $projectId,
                ]);
            }

            $this->createVersion($projectId, 'manual-save', $payload['draft'], null, $userId);
            $this->db->commit();

            if ($this->auth) {
                $this->auth->auditLog(
                    $userId,
                    $username,
                    $existing === null ? 'template_studio_project.create' : 'template_studio_project.update',
                    'template-project',
                    $projectId,
                    json_encode(['name' => $payload['name'], 'templateId' => $payload['templateId']])
                );
            }

            return ['success' => true, 'project' => $this->getProject($projectId)];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            return ['success' => false, 'error' => 'Failed to save template project: ' . $e->getMessage()];
        }
    }

    public function validateProject(string $projectId): array {
        $project = $this->getProject($projectId);
        if ($project === null) {
            return ['success' => false, 'error' => 'Template project not found'];
        }

        $validation = $this->validateDraftPayload([
            'name' => $project['name'],
            'description' => $project['description'],
            'templateId' => $project['templateId'],
            'sourceType' => $project['sourceType'],
            'repositoryId' => $project['repositoryId'],
            'sourcePath' => $project['sourcePath'],
            'sourceRef' => $project['sourceRef'],
            'draft' => $project['draft'],
        ]);

        $stmt = $this->db->prepare("
            UPDATE template_studio_projects
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

        if ($draft['id'] === '') {
            $errors[] = 'Template ID is required';
        } elseif (!preg_match('/^[a-z0-9][a-z0-9-]*[a-z0-9]$|^[a-z0-9]$/i', $draft['id'])) {
            $errors[] = 'Template ID must contain only alphanumeric characters and hyphens';
        }

        if (trim((string)($draft['name'] ?? '')) === '') {
            $errors[] = 'Template name is required';
        }

        $runtimeImage = trim((string)($draft['runtime']['image'] ?? ''));
        if ($runtimeImage === '') {
            $errors[] = 'runtime.image is required';
        }

        $inputs = $draft['inputs'] ?? [];
        if (!is_array($inputs)) {
            $errors[] = 'inputs must be an array';
        } else {
            $inputNames = [];
            foreach ($inputs as $index => $inputDef) {
                $label = 'Input #' . ($index + 1);
                if (!is_array($inputDef)) {
                    $errors[] = $label . ' must be an object';
                    continue;
                }

                $name = trim((string)($inputDef['name'] ?? ''));
                if ($name === '') {
                    $errors[] = $label . ' name is required';
                } elseif (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $name)) {
                    $errors[] = $label . ' name must start with a letter and use only letters, numbers, and underscores';
                } elseif (isset($inputNames[$name])) {
                    $errors[] = 'Duplicate input name: ' . $name;
                } else {
                    $inputNames[$name] = true;
                }

                if (trim((string)($inputDef['label'] ?? '')) === '') {
                    $errors[] = $label . ' label is required';
                }
            }
        }

        $execution = $draft['execution'] ?? [];
        if (!is_array($execution)) {
            $errors[] = 'execution must be an object';
        } else {
            $mode = strtolower(trim((string)($execution['mode'] ?? 'sync')));
            if (!in_array($mode, ['sync', 'async', 'streaming'], true)) {
                $errors[] = 'execution.mode must be sync, async, or streaming';
            }

            if (trim((string)($execution['script'] ?? '')) === '') {
                $warnings[] = 'No execution.script is defined yet.';
            }
        }

        $existing = $this->templateManager->getTemplate($draft['id']);
        if ($existing && ($existing['source'] ?? 'builtin') === 'builtin') {
            $warnings[] = 'This template ID already exists as a built-in template. Publishing would shadow the shipped template.';
        }

        return [
            'success' => true,
            'draft' => $draft,
            'validation' => [
                'valid' => $errors === [],
                'errors' => array_values(array_unique($errors)),
                'warnings' => array_values(array_unique($warnings)),
                'validatedAt' => date('c'),
            ],
        ];
    }

    public function publishProject(string $projectId, string $userId, string $username): array {
        $project = $this->getProject($projectId);
        if ($project === null) {
            return ['success' => false, 'error' => 'Template project not found'];
        }

        $validated = $this->validateProject($projectId);
        if (empty($validated['success'])) {
            return $validated;
        }

        $validation = $validated['validation'] ?? ['valid' => false, 'errors' => ['Validation failed']];
        if (empty($validation['valid'])) {
            return [
                'success' => false,
                'error' => 'Template draft has validation errors',
                'validation' => $validation,
            ];
        }

        $draft = $validated['draft'] ?? $project['draft'];
        $writeResult = $this->writePublishedTemplate($draft);
        if (empty($writeResult['success'])) {
            return [
                'success' => false,
                'error' => 'Failed to write published template',
                'details' => $writeResult,
            ];
        }

        $loaded = $this->templateManager->getTemplate($draft['id']);
        if ($loaded === null) {
            return ['success' => false, 'error' => 'Template was written but could not be loaded back into the runtime catalog'];
        }

        try {
            $this->db->beginTransaction();
            $stmt = $this->db->prepare("
                UPDATE template_studio_projects
                SET status = ?,
                    template_id = ?,
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
                json_encode($loaded, JSON_UNESCAPED_SLASHES),
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
                    'template_studio_project.publish',
                    'template-project',
                    $projectId,
                    json_encode(['name' => $project['name'], 'templateId' => $draft['id']])
                );
            }

            return [
                'success' => true,
                'project' => $this->getProject($projectId),
                'validation' => $validation,
                'template' => $loaded,
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            return ['success' => false, 'error' => 'Failed to finalize template publish: ' . $e->getMessage()];
        }
    }

    public function archiveProject(string $projectId, string $userId, string $username): array {
        $project = $this->getProject($projectId);
        if ($project === null) {
            return ['success' => false, 'error' => 'Template project not found'];
        }

        $stmt = $this->db->prepare("
            UPDATE template_studio_projects
            SET status = ?, updated_at = datetime('now'), updated_by = ?
            WHERE id = ?
        ");
        $stmt->execute([self::STATUS_ARCHIVED, $userId, $projectId]);

        if ($this->auth) {
            $this->auth->auditLog(
                $userId,
                $username,
                'template_studio_project.archive',
                'template-project',
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
            $name = 'Untitled Template';
        }

        $description = trim((string)($input['description'] ?? $draft['description'] ?? $existing['description'] ?? ''));
        $templateId = trim((string)($input['templateId'] ?? $draft['id'] ?? $existing['templateId'] ?? ''));
        if ($templateId === '' && $name !== '') {
            $templateId = $this->slugify($name);
        }

        $status = trim((string)($input['status'] ?? $existing['status'] ?? self::STATUS_DRAFT));
        if (!in_array($status, [self::STATUS_DRAFT, self::STATUS_PUBLISHED, self::STATUS_ARCHIVED], true)) {
            $status = self::STATUS_DRAFT;
        }

        $sourceType = trim((string)($input['sourceType'] ?? $existing['sourceType'] ?? 'local'));
        if (!in_array($sourceType, ['local', 'repository'], true)) {
            $sourceType = 'local';
        }

        $draft['id'] = $templateId;
        $draft['name'] = $name;
        $draft['description'] = $description;
        $draft['version'] = trim((string)($draft['version'] ?? '1.0.0')) ?: '1.0.0';
        $draft['icon'] = trim((string)($draft['icon'] ?? 'fas fa-cubes')) ?: 'fas fa-cubes';
        $draft['color'] = trim((string)($draft['color'] ?? '#2563eb')) ?: '#2563eb';
        $draft['category'] = trim((string)($draft['category'] ?? 'custom')) ?: 'custom';
        $draft['targetType'] = $draft['targetType'] ?? null;
        $draft['runtime'] = is_array($draft['runtime'] ?? null) ? $draft['runtime'] : ['image' => 'alpine:latest'];
        $draft['inputs'] = is_array($draft['inputs'] ?? null) ? array_values($draft['inputs']) : [];
        $draft['execution'] = is_array($draft['execution'] ?? null) ? $draft['execution'] : ['mode' => 'sync', 'script' => 'echo "Hello from Doki template"'];
        $draft['output'] = is_array($draft['output'] ?? null) ? $draft['output'] : ['type' => 'text'];
        $draft['requirements'] = is_array($draft['requirements'] ?? null) ? array_values($draft['requirements']) : [];
        $draft['targetBindings'] = is_array($draft['targetBindings'] ?? null) ? $draft['targetBindings'] : [];

        return [
            'name' => $name,
            'description' => $description,
            'templateId' => $templateId,
            'status' => $status,
            'sourceType' => $sourceType,
            'repositoryId' => trim((string)($input['repositoryId'] ?? $existing['repositoryId'] ?? '')),
            'sourcePath' => trim((string)($input['sourcePath'] ?? $existing['sourcePath'] ?? '')),
            'sourceRef' => trim((string)($input['sourceRef'] ?? $existing['sourceRef'] ?? '')),
            'draft' => $draft,
        ];
    }

    private function normalizeProjectRow(array $row): array {
        return [
            'id' => (string)($row['id'] ?? ''),
            'name' => (string)($row['name'] ?? ''),
            'description' => (string)($row['description'] ?? ''),
            'status' => (string)($row['status'] ?? self::STATUS_DRAFT),
            'templateId' => trim((string)($row['template_id'] ?? '')),
            'sourceType' => (string)($row['source_type'] ?? 'local'),
            'repositoryId' => trim((string)($row['repository_id'] ?? '')),
            'sourcePath' => (string)($row['source_path'] ?? ''),
            'sourceRef' => (string)($row['source_ref'] ?? ''),
            'draft' => $this->decodeJsonMap($row['draft_json'] ?? null, $this->buildDefaultDraft()),
            'lastValidation' => $this->decodeJsonMap($row['last_validation_json'] ?? null),
            'lastPublished' => $this->decodeJsonMap($row['last_published_json'] ?? null),
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
        $stmt = $this->db->prepare("SELECT COALESCE(MAX(version_number), 0) AS version_number FROM template_studio_versions WHERE project_id = ?");
        $stmt->execute([$projectId]);
        $nextVersion = ((int)($stmt->fetch(PDO::FETCH_ASSOC)['version_number'] ?? 0)) + 1;

        $insert = $this->db->prepare("
            INSERT INTO template_studio_versions (
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

    private function writePublishedTemplate(array $template): array {
        $root = $this->templateManager->getCustomTemplatesDir();
        if (!is_dir($root) && !@mkdir($root, 0755, true) && !is_dir($root)) {
            return ['success' => false, 'error' => 'Unable to create custom templates directory'];
        }

        $path = $root . '/' . $template['id'] . '.template.yaml';
        $yaml = $this->yamlEmit($template);
        if (@file_put_contents($path, $yaml) === false) {
            return ['success' => false, 'error' => 'Unable to write custom template file'];
        }

        return ['success' => true, 'path' => $path];
    }

    private function yamlEmit(array $data, int $indent = 0): string {
        if (function_exists('yaml_emit')) {
            $yaml = yaml_emit($data, YAML_UTF8_ENCODING, YAML_LN_BREAK);
            if (is_string($yaml)) {
                $yaml = preg_replace('/^---\s*\n/', '', $yaml) ?? $yaml;
                $yaml = preg_replace('/\n\.\.\.\s*$/', '', $yaml) ?? $yaml;
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

        $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], (string)$value);
        return '"' . $escaped . '"';
    }

    private function isSequentialArray(array $value): bool {
        return array_keys($value) === range(0, count($value) - 1);
    }

    private function findProjectByTemplateId(string $templateId, string $excludeProjectId): ?array {
        if ($templateId === '') {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT *
            FROM template_studio_projects
            WHERE template_id = ?
              AND id != ?
            LIMIT 1
        ");
        $stmt->execute([$templateId, $excludeProjectId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->normalizeProjectRow($row) : null;
    }

    private function buildDefaultDraft(): array {
        return [
            'id' => '',
            'name' => '',
            'version' => '1.0.0',
            'description' => '',
            'icon' => 'fas fa-cubes',
            'color' => '#2563eb',
            'category' => 'custom',
            'targetType' => null,
            'runtime' => [
                'image' => 'alpine:latest',
            ],
            'inputs' => [
                [
                    'name' => 'message',
                    'label' => 'Message',
                    'type' => 'string',
                    'required' => true,
                    'description' => 'Message to print from the template',
                ],
            ],
            'execution' => [
                'mode' => 'sync',
                'timeout' => 60,
                'script' => 'echo "{{message}}"',
            ],
            'output' => [
                'type' => 'text',
            ],
            'requirements' => ['sh'],
            'targetBindings' => [],
        ];
    }

    private function decodeJsonMap($value, $default = null) {
        if (!is_string($value) || trim($value) === '') {
            return $default;
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : $default;
    }

    private function slugify(string $value): string {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        $value = trim($value, '-');

        return $value !== '' ? $value : 'template';
    }

    private function generateProjectId(string $seed): string {
        $base = 'template-' . $this->slugify($seed);
        $candidate = $base;
        $suffix = 2;

        while ($this->getProject($candidate) !== null) {
            $candidate = $base . '-' . $suffix;
            $suffix++;
        }

        return $candidate;
    }
}
