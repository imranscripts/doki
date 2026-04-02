<?php
/**
 * AIAdminManager.php - Structured AI admin settings for providers and context
 *
 * Stores logical AI context blocks so admins can enable or disable parts of
 * the prompt reference without editing PHP source, while also allowing
 * additional custom blocks to be created in the admin UI.
 */

require_once __DIR__ . '/Database.php';

class AIAdminManager {
    public const SCOPE_APPS = 'apps';
    public const SCOPE_COMMANDS = 'commands';

    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * Return all context blocks for one scope.
     */
    public function getContextBlocks(string $scope): array {
        $scope = $this->normalizeScope($scope);
        $this->seedSystemContextBlocks($scope);

        $stmt = $this->db->prepare("
            SELECT
                id,
                scope,
                block_key,
                title,
                description,
                editable,
                dynamic,
                enabled,
                content,
                sort_order,
                is_system,
                updated_at,
                updated_by
            FROM ai_context_blocks
            WHERE scope = ?
            ORDER BY sort_order ASC, title COLLATE NOCASE ASC, id ASC
        ");
        $stmt->execute([$scope]);

        return array_map(fn(array $row) => $this->mapRowToBlock($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Return context blocks keyed by short block key.
     */
    public function getContextBlockMap(string $scope): array {
        $map = [];
        foreach ($this->getContextBlocks($scope) as $block) {
            $map[$block['key']] = $block;
        }
        return $map;
    }

    /**
     * Return only custom blocks for one scope.
     */
    public function getCustomContextBlocks(string $scope): array {
        return array_values(array_filter(
            $this->getContextBlocks($scope),
            fn(array $block): bool => empty($block['isSystem'])
        ));
    }

    /**
     * Persist enabled flags, editable content, and custom block drafts.
     */
    public function saveContextBlocks(string $scope, array $blocks, string $userId): array {
        $scope = $this->normalizeScope($scope);
        $this->seedSystemContextBlocks($scope, $userId);
        $existingRows = $this->getContextRowsById($scope);
        $created = [];

        try {
            $this->db->beginTransaction();
            $sortOrder = 10;

            foreach ($blocks as $block) {
                if (!is_array($block)) {
                    continue;
                }

                $id = trim((string)($block['id'] ?? ''));
                $existingRow = $id !== '' ? ($existingRows[$id] ?? null) : null;

                if ($existingRow === null) {
                    $createdBlock = $this->createCustomContextBlock($scope, $block, $userId, $sortOrder);
                    if (empty($createdBlock['success'])) {
                        throw new RuntimeException((string)($createdBlock['error'] ?? 'Failed to create custom AI context block'));
                    }
                    if ($id !== '') {
                        $created[$id] = $createdBlock['id'];
                    }
                } else {
                    $updateResult = $this->updateContextBlockRow($scope, $existingRow, $block, $userId, $sortOrder);
                    if (empty($updateResult['success'])) {
                        throw new RuntimeException((string)($updateResult['error'] ?? 'Failed to update AI context block'));
                    }
                }

                $sortOrder += 10;
            }

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['success' => false, 'error' => 'Failed to save AI context settings: ' . $e->getMessage()];
        }

        return [
            'success' => true,
            'scope' => $scope,
            'blocks' => $this->getContextBlocks($scope),
            'created' => $created,
        ];
    }

    /**
     * Delete one custom context block.
     */
    public function deleteContextBlock(string $scope, string $id): array {
        $scope = $this->normalizeScope($scope);
        $this->seedSystemContextBlocks($scope);

        $stmt = $this->db->prepare("
            SELECT id, is_system, dynamic
            FROM ai_context_blocks
            WHERE scope = ? AND id = ?
            LIMIT 1
        ");
        $stmt->execute([$scope, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return ['success' => false, 'error' => 'Context block not found'];
        }
        if ((int)($row['dynamic'] ?? 0) === 1) {
            return ['success' => false, 'error' => 'Dynamic context blocks cannot be deleted'];
        }
        if ((int)($row['is_system'] ?? 0) === 1) {
            return ['success' => false, 'error' => 'Built-in context blocks cannot be deleted'];
        }

        $deleteStmt = $this->db->prepare("DELETE FROM ai_context_blocks WHERE scope = ? AND id = ?");
        $deleteStmt->execute([$scope, $id]);

        return [
            'success' => true,
            'scope' => $scope,
            'id' => $id,
            'blocks' => $this->getContextBlocks($scope),
        ];
    }

    /**
     * Build the stored command/template reference block for future AI flows.
     */
    public function buildCommandReferenceBlock(): string {
        $sections = [];
        foreach ($this->getContextBlocks(self::SCOPE_COMMANDS) as $block) {
            if (empty($block['enabled'])) {
                continue;
            }
            $content = trim((string)($block['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            $sections[] = $content;
        }

        return implode("\n\n", $sections);
    }

    /**
     * Create or update built-in rows for one scope without overriding admin edits.
     */
    private function seedSystemContextBlocks(string $scope, ?string $userId = null): void {
        $definitions = $this->getContextDefinitions($scope);
        $stmt = $this->db->prepare("
            INSERT INTO ai_context_blocks (
                id,
                scope,
                block_key,
                title,
                description,
                editable,
                dynamic,
                enabled,
                content,
                sort_order,
                is_system,
                created_by,
                updated_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)
            ON CONFLICT(id) DO UPDATE SET
                scope = excluded.scope,
                block_key = excluded.block_key,
                title = excluded.title,
                description = excluded.description,
                editable = excluded.editable,
                dynamic = excluded.dynamic,
                content = CASE
                    WHEN ai_context_blocks.editable = 1 THEN ai_context_blocks.content
                    ELSE excluded.content
                END,
                sort_order = excluded.sort_order,
                is_system = 1,
                updated_at = datetime('now'),
                updated_by = excluded.updated_by
        ");

        foreach ($definitions as $definition) {
            $stmt->execute([
                $definition['id'],
                $scope,
                $definition['key'],
                $definition['title'],
                $definition['description'],
                $definition['editable'] ? 1 : 0,
                $definition['dynamic'] ? 1 : 0,
                $definition['enabled'] ? 1 : 0,
                $definition['defaultContent'],
                (int)$definition['sortOrder'],
                $userId,
                $userId,
            ]);
        }
    }

    /**
     * Return canonical block definitions for one scope.
     */
    private function getContextDefinitions(string $scope): array {
        if ($scope === self::SCOPE_COMMANDS) {
            return $this->getCommandContextDefinitions();
        }
        return $this->getAppContextDefinitions();
    }

    /**
     * App-editing context blocks used by Studio AI.
     */
    private function getAppContextDefinitions(): array {
        return [
            $this->makeBlock(
                self::SCOPE_APPS,
                'core_overview',
                'Doki App Overview',
                'Workspace-aware Doki app model and starter conventions.',
                false,
                true,
                'Generated from the active workspace summary and Doki app structure.',
                10
            ),
            $this->makeBlock(
                self::SCOPE_APPS,
                'detected_methods',
                'Detected AppContext Methods',
                'Current-file AppContext methods already in use.',
                false,
                true,
                'Generated from the current file by detecting AppContext method calls.',
                20
            ),
            $this->makeBlock(
                self::SCOPE_APPS,
                'app_context_groups',
                'AppContext Capability Groups',
                'Focus-aware AppContext method groups such as storage, auth, config, and runtime helpers.',
                false,
                true,
                'Generated from focus detection and the AppContext catalog.',
                30
            ),
            $this->makeBlock(
                self::SCOPE_APPS,
                'storage_rules',
                'Storage and Settings Rules',
                'Rules for app-local storage, settings helpers, and preview-safe persistence.',
                true,
                false,
                <<<'TEXT'
Storage and settings rules:
- Use `$app->readFile()`, `$app->writeFile()`, and `$app->deleteFile()` for app-local files.
- Use `$app->getSettings()` and `$app->saveSettings()` for settings. Do not read or write `settings.json` manually.
- App storage may be empty on first run and in preview mode. Missing files must resolve to an empty state without warnings.
- Avoid raw filesystem calls like `file_get_contents()`, `file_put_contents()`, `fopen()`, `unlink()`, or `mkdir()` against `$app->getStoragePath()` when an AppContext helper exists.
- Do not hardcode Studio preview or workspace data paths. Let AppContext resolve storage locations.
TEXT,
                40
            ),
            $this->makeBlock(
                self::SCOPE_APPS,
                'ui_design',
                'UI Design and Styling',
                'Standard Doki shell, styling tokens, and app UI composition rules.',
                true,
                false,
                <<<'TEXT'
Doki UI styling rules:
- Use the standard Doki shell: `$layout->renderHead(...)`, `$layout->renderSidebar()`, a `main.main-content`, and a `.content-area` wrapper with `$app->renderAppHeader()`.
- Prefer Doki-style classes and structure such as `card`, `card-header`, `card-body`, `form-group`, `form-control`, `btn`, `btn-primary`, `btn-secondary`, `btn-sm`, and simple grid/list sections before inventing a new design system.
- Keep custom CSS small and local to the app. Build on Doki theme variables like `var(--bg-primary)`, `var(--bg-secondary)`, `var(--border-color)`, `var(--text-primary)`, `var(--text-secondary)`, `var(--text-muted)`, and `var(--accent-primary)`.
- Avoid external CSS frameworks or CDN stylesheets such as Bootstrap, Tailwind CDN, or custom web font/CDN dependencies inside apps.
- Match Doki visually: rounded cards, subtle borders, muted secondary text, compact spacing, and restrained accent color use instead of a fully custom brand treatment.
- When adding CSS, prefer app-specific class names for custom sections and use Doki tokens for color, spacing, and focus states.
TEXT,
                50
            ),
            $this->makeBlock(
                self::SCOPE_APPS,
                'manifest_capabilities',
                'Manifest and Capabilities',
                'Manifest structure, trust levels, and common capability guidance.',
                false,
                true,
                'Generated from the Doki manifest and AppCapabilities catalog.',
                60
            ),
            $this->makeBlock(
                self::SCOPE_APPS,
                'examples',
                'Canonical Examples',
                'Focused Doki examples the model can imitate.',
                false,
                true,
                'Generated from curated Doki app examples selected for the current request.',
                70
            ),
        ];
    }

    /**
     * Command and template context blocks for future AI command flows.
     */
    private function getCommandContextDefinitions(): array {
        return [
            $this->makeBlock(
                self::SCOPE_COMMANDS,
                'command_overview',
                'Commands Overview',
                'High-level mental model for Doki commands and templates.',
                true,
                false,
                <<<'TEXT'
Commands and templates model:
- Commands are user-defined execution recipes that reference templates, targets, environments, and optional runtime inputs.
- Templates define reusable step structure and inputs. Commands provide the concrete defaults and operating intent.
- Prefer extending an existing command or template shape before inventing a new schema.
- Keep command definitions explicit and auditable so operators can understand what will run and where.
TEXT,
                10
            ),
            $this->makeBlock(
                self::SCOPE_COMMANDS,
                'workflow_structure',
                'Workflow Structure and Authoring',
                'How the model should shape multi-step workflows.',
                true,
                false,
                <<<'TEXT'
Workflow structure and authoring:
- Model each meaningful operation as its own step instead of hiding the whole job in one oversized script.
- Use step IDs and workflow names that describe the actual operator intent.
- Add dependencies only where ordering is required, and leave unrelated steps independent so they can run in parallel.
- Prefer reusable templates for stable repeated logic and keep workflow-specific intent in the workflow itself.
- Use shell-exec when it is the clearest fit or as thin glue between other steps, not as the default answer to every workflow.
TEXT,
                15
            ),
            $this->makeBlock(
                self::SCOPE_COMMANDS,
                'template_schema',
                'Template and Schema Rules',
                'Guidance for inputs, defaults, and step structure.',
                true,
                false,
                <<<'TEXT'
Template and schema rules:
- Keep input names clear, stable, and operator-friendly.
- Prefer typed inputs with safe defaults over free-form shell interpolation.
- Make step intent obvious from names, descriptions, and input labels.
- Reuse existing template patterns when the execution flow already matches a known shape.
TEXT,
                20
            ),
            $this->makeBlock(
                self::SCOPE_COMMANDS,
                'input_modeling',
                'Workflow Input Modeling',
                'How runtime inputs and UI input definitions should be modeled.',
                true,
                false,
                <<<'TEXT'
Workflow input modeling:
- `inputs` is a runtime values object keyed by input name. Put actual default values there, not field metadata.
- `inputContract` is the array of UI field definitions that describes what the operator can fill in at run time.
- Use `string` for a single free-form value, `number` for numeric values, `boolean` for true/false, `select` for one fixed choice, and `multiselect` for many strings.
- If the operator should enter several repository names, paths, hosts, or similar values, prefer `multiselect` over a pseudo-schema string field.
- Arrays and objects interpolate into scripts as JSON. If a script needs one item per line, parse the JSON explicitly inside the script instead of pretending the value is already newline text.
TEXT,
                25
            ),
            $this->makeBlock(
                self::SCOPE_COMMANDS,
                'targets_and_env',
                'Targets, Environments, and Secrets',
                'How command AI should think about infrastructure context.',
                true,
                false,
                <<<'TEXT'
Targets, environments, and secrets:
- Use configured targets and environments instead of hardcoding hosts, URLs, or Docker contexts.
- Prefer secret references over embedding credentials or sensitive defaults in commands or templates.
- If a command depends on a target capability, make that dependency explicit in the template or operator notes.
- Keep environment-specific overrides narrow and predictable.
TEXT,
                30
            ),
            $this->makeBlock(
                self::SCOPE_COMMANDS,
                'interpolation_dependencies',
                'Interpolation and Dependencies',
                'How workflow references and step dependencies really work.',
                true,
                false,
                <<<'TEXT'
Interpolation and dependency rules:
- Step input strings can reference command-level runtime inputs with placeholders like `{{inputs.branch}}`.
- Step inputs can reference earlier step state with placeholders like `{{steps.build.output}}` or `{{steps.build.extract.imageTag}}`.
- Only `status`, `exitCode`, `error`, `output`, and `extract.<name>` are available from prior steps. Paths like `{{steps.build.name}}` are invalid.
- `steps.<id>.output` is always raw text. Even if a template reports JSON output, it is still exposed as a string unless the template defines extractors.
- HTTP templates expose `extract.responseBody` for the raw response body and `extract.statusCode` for the HTTP status code.
- Use `inherit` when a step should not force a target. Do not use `none` as a target value.
- A step should only reference steps it depends on, directly or transitively.
- In template execution scripts, interpolated step inputs are available directly by their input name, such as `{{command}}` in the shell-exec template.
- Keep references explicit and easy to audit so dry-run previews and history stay understandable.
TEXT,
                35
            ),
            $this->makeBlock(
                self::SCOPE_COMMANDS,
                'runtime_execution',
                'Runtime and Execution Safety',
                'Execution and container guidance for commands.',
                true,
                false,
                <<<'TEXT'
Runtime and execution safety:
- Prefer the least-privileged execution path that can complete the task.
- Keep destructive operations explicit and easy to review.
- Separate setup, execution, and cleanup steps when that improves debuggability.
- Be careful with shell interpolation, quoting, and environment propagation.
TEXT,
                40
            ),
            $this->makeBlock(
                self::SCOPE_COMMANDS,
                'dry_run_capability',
                'Dry Run Capability',
                'How Doki dry-run executions behave for workflows and templates.',
                true,
                false,
                <<<'TEXT'
Dry-run behavior:
- Doki supports run-level dry runs for workflows and templates.
- Workflow drafts can also be dry-run directly inside Workflows Studio before publish.
- A dry run validates the workflow shape, resolves targets, interpolates scripts, and records the redacted command that would run, but it does not execute the command.
- Dry-run logs should never reveal secret values. Secret-backed environment variables and target credentials are shown as redacted placeholders.
- Do not invent a permanent `dryRun` field inside workflow or template definitions unless the schema explicitly supports it. Dry run is a property of the execution request, not the saved workflow itself.
- Prefer workflows whose step logic stays understandable when reviewed through dry-run previews.
TEXT,
                45
            ),
            $this->makeBlock(
                self::SCOPE_COMMANDS,
                'safety_permissions',
                'Permissions and Safety',
                'Safety rules for generated command and template suggestions.',
                true,
                false,
                <<<'TEXT'
Permissions and safety:
- Do not assume a command can access secrets, Docker, or remote targets unless the surrounding configuration supports it.
- Prefer commands that are reversible, inspectable, and operationally obvious.
- Flag ambiguous or high-risk behavior instead of silently broadening scope.
- Preserve auditability and avoid hiding important execution details inside opaque scripts.
TEXT,
                50
            ),
            $this->makeBlock(
                self::SCOPE_COMMANDS,
                'examples',
                'Examples and Patterns',
                'Reusable command/template authoring patterns.',
                true,
                false,
                <<<'TEXT'
Examples and patterns:
- Good command suggestions should make it obvious which template is being used, which target they run on, and which inputs the operator must provide.
- Prefer small composable commands over one oversized command that mixes unrelated concerns.
- When in doubt, optimize for readability and operator trust over terseness.
TEXT,
                60
            ),
        ];
    }

    /**
     * Build one default block definition.
     */
    private function makeBlock(
        string $scope,
        string $key,
        string $title,
        string $description,
        bool $editable,
        bool $dynamic,
        string $defaultContent,
        int $sortOrder,
        bool $enabled = true
    ): array {
        return [
            'id' => $scope . '.' . $key,
            'scope' => $scope,
            'key' => $key,
            'title' => $title,
            'description' => $description,
            'editable' => $editable,
            'dynamic' => $dynamic,
            'enabled' => $enabled,
            'defaultContent' => trim($defaultContent),
            'sortOrder' => $sortOrder,
        ];
    }

    /**
     * Return context rows keyed by id.
     */
    private function getContextRowsById(string $scope): array {
        $stmt = $this->db->prepare("
            SELECT *
            FROM ai_context_blocks
            WHERE scope = ?
        ");
        $stmt->execute([$scope]);

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[(string)$row['id']] = $row;
        }
        return $rows;
    }

    /**
     * Update one existing row.
     */
    private function updateContextBlockRow(string $scope, array $existingRow, array $block, string $userId, int $sortOrder): array {
        $id = trim((string)($existingRow['id'] ?? ''));
        if ($id === '') {
            return ['success' => false, 'error' => 'Context block ID is missing'];
        }

        $isSystem = (int)($existingRow['is_system'] ?? 0) === 1;
        $editable = (int)($existingRow['editable'] ?? 0) === 1;

        $title = $isSystem
            ? trim((string)($existingRow['title'] ?? ''))
            : trim((string)($block['title'] ?? $existingRow['title'] ?? ''));
        if ($title === '') {
            return ['success' => false, 'error' => 'Context block title is required'];
        }

        $description = $isSystem
            ? trim((string)($existingRow['description'] ?? ''))
            : trim((string)($block['description'] ?? $existingRow['description'] ?? ''));
        $enabled = $this->toBool($block['enabled'] ?? $existingRow['enabled'] ?? false) ? 1 : 0;
        $content = $editable
            ? trim((string)($block['content'] ?? $existingRow['content'] ?? ''))
            : (string)($existingRow['content'] ?? '');

        $stmt = $this->db->prepare("
            UPDATE ai_context_blocks
            SET
                title = ?,
                description = ?,
                enabled = ?,
                content = ?,
                sort_order = ?,
                updated_at = datetime('now'),
                updated_by = ?
            WHERE scope = ? AND id = ?
        ");
        $stmt->execute([
            $title,
            $description,
            $enabled,
            $content,
            $sortOrder,
            $userId,
            $scope,
            $id,
        ]);

        return ['success' => true];
    }

    /**
     * Insert one new custom block.
     */
    private function createCustomContextBlock(string $scope, array $block, string $userId, int $sortOrder): array {
        $title = trim((string)($block['title'] ?? ''));
        if ($title === '') {
            return ['success' => false, 'error' => 'A title is required for new context blocks'];
        }

        $description = trim((string)($block['description'] ?? ''));
        $content = trim((string)($block['content'] ?? ''));
        $enabled = $this->toBool($block['enabled'] ?? true) ? 1 : 0;
        $key = $this->generateUniqueBlockKey($scope, $title);
        $id = $scope . '.custom.' . $key;

        $stmt = $this->db->prepare("
            INSERT INTO ai_context_blocks (
                id,
                scope,
                block_key,
                title,
                description,
                editable,
                dynamic,
                enabled,
                content,
                sort_order,
                is_system,
                created_by,
                updated_by
            ) VALUES (?, ?, ?, ?, ?, 1, 0, ?, ?, ?, 0, ?, ?)
        ");
        $stmt->execute([
            $id,
            $scope,
            $key,
            $title,
            $description,
            $enabled,
            $content,
            $sortOrder,
            $userId,
            $userId,
        ]);

        return ['success' => true, 'id' => $id, 'key' => $key];
    }

    /**
     * Map one DB row to the API/UI shape.
     */
    private function mapRowToBlock(array $row): array {
        $key = trim((string)($row['block_key'] ?? ''));
        if ($key === '') {
            $key = $this->deriveKeyFromId((string)($row['id'] ?? ''));
        }

        return [
            'id' => (string)($row['id'] ?? ''),
            'key' => $key,
            'scope' => (string)($row['scope'] ?? ''),
            'title' => (string)($row['title'] ?? ''),
            'description' => (string)($row['description'] ?? ''),
            'editable' => (int)($row['editable'] ?? 0) === 1,
            'dynamic' => (int)($row['dynamic'] ?? 0) === 1,
            'enabled' => (int)($row['enabled'] ?? 0) === 1,
            'content' => (string)($row['content'] ?? ''),
            'sortOrder' => is_numeric($row['sort_order'] ?? null) ? (int)$row['sort_order'] : 0,
            'isSystem' => (int)($row['is_system'] ?? 0) === 1,
            'updatedAt' => $row['updated_at'] ?? null,
            'updatedBy' => $row['updated_by'] ?? null,
        ];
    }

    /**
     * Generate a unique block key inside one scope.
     */
    private function generateUniqueBlockKey(string $scope, string $title): string {
        $base = $this->slugify($title);
        if ($base === '') {
            $base = 'custom-block';
        }

        $key = $base;
        $suffix = 2;
        $stmt = $this->db->prepare("
            SELECT 1
            FROM ai_context_blocks
            WHERE scope = ? AND block_key = ?
            LIMIT 1
        ");

        while (true) {
            $stmt->execute([$scope, $key]);
            if (!$stmt->fetch()) {
                return $key;
            }
            $key = $base . '-' . $suffix;
            $suffix++;
        }
    }

    /**
     * Derive a short key from a persisted id when older rows are missing block_key.
     */
    private function deriveKeyFromId(string $id): string {
        $id = trim($id);
        if ($id === '') {
            return '';
        }

        $parts = explode('.', $id);
        return (string)end($parts);
    }

    /**
     * Normalize scope input.
     */
    private function normalizeScope(string $scope): string {
        $scope = strtolower(trim($scope));
        if ($scope === self::SCOPE_COMMANDS) {
            return self::SCOPE_COMMANDS;
        }
        return self::SCOPE_APPS;
    }

    /**
     * Normalize mixed input into a boolean.
     */
    private function toBool(mixed $value): bool {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int)$value === 1;
        }
        $normalized = strtolower(trim((string)$value));
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Convert human text into a stable slug.
     */
    private function slugify(string $value): string {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        return trim($value, '-');
    }
}
