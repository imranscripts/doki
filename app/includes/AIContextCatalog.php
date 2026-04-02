<?php
/**
 * AIContextCatalog.php - Curated Doki framework context for Studio AI
 *
 * Builds a compact, selective reference pack so models can reason about
 * AppContext, manifest capabilities, and common Doki app patterns without
 * receiving the full source for every request.
 */

require_once __DIR__ . '/AppContext.php';
require_once __DIR__ . '/AppCapabilities.php';
require_once __DIR__ . '/AIAdminManager.php';

class AIContextCatalog {
    private const MAX_GROUPS = 4;
    private const MAX_METHODS_PER_GROUP = 6;
    private const MAX_DETECTED_METHODS = 8;
    private const MAX_EXAMPLES = 3;

    private static ?array $methodCatalog = null;
    private AIAdminManager $adminManager;

    public function __construct() {
        $this->adminManager = new AIAdminManager();
    }

    /**
     * Build a selective Doki reference block for the current request.
     */
    public function buildReferenceBlock(array $workspace, string $currentFilePath, ?string $currentFileContent, array $conversation = []): string {
        $focusAreas = $this->detectFocusAreas($currentFilePath, $currentFileContent, $conversation);
        $detectedMethods = $this->detectAppContextMethods($currentFileContent);
        $sections = [];
        $settings = $this->getAppContextSettings();

        if ($this->isBlockEnabled($settings, 'core_overview', true)) {
            $sections[] = $this->buildCoreOverview($workspace);
        }

        if ($detectedMethods !== [] && $this->isBlockEnabled($settings, 'detected_methods', true)) {
            $sections[] = $this->buildDetectedMethodsSection($detectedMethods);
        }

        $selectedGroups = $this->selectGroups($focusAreas, $detectedMethods);
        if ($selectedGroups !== [] && $this->isBlockEnabled($settings, 'app_context_groups', true)) {
            $sections[] = $this->buildGroupSections($selectedGroups);
        }

        if (
            (in_array('storage', $focusAreas, true) || in_array('config', $focusAreas, true))
            && $this->isBlockEnabled($settings, 'storage_rules', true)
        ) {
            $sections[] = $this->getStaticBlockContent($settings, 'storage_rules', $this->buildStorageSafetySection());
        }

        if (
            (in_array('php', $focusAreas, true) || in_array('manifest', $focusAreas, true))
            && $this->isBlockEnabled($settings, 'ui_design', true)
        ) {
            $sections[] = $this->getStaticBlockContent($settings, 'ui_design', $this->buildUiStylingSection());
        }

        if (
            (in_array('manifest', $focusAreas, true) || in_array('capabilities', $focusAreas, true))
            && $this->isBlockEnabled($settings, 'manifest_capabilities', true)
        ) {
            $sections[] = $this->buildManifestReference($focusAreas);
        }

        $examples = $this->selectExamples($focusAreas);
        if ($examples !== [] && $this->isBlockEnabled($settings, 'examples', true)) {
            $sections[] = $this->buildExamplesSection($examples);
        }

        foreach ($this->getCustomAppContextSections() as $customSection) {
            $sections[] = $customSection;
        }

        return implode("\n\n", array_filter($sections, fn($section) => trim((string)$section) !== ''));
    }

    /**
     * Load app-context block settings without breaking AI if admin settings fail.
     */
    private function getAppContextSettings(): array {
        try {
            return $this->adminManager->getContextBlockMap(AIAdminManager::SCOPE_APPS);
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Return enabled custom app-context blocks as additional prompt sections.
     */
    private function getCustomAppContextSections(): array {
        try {
            $blocks = $this->adminManager->getCustomContextBlocks(AIAdminManager::SCOPE_APPS);
        } catch (Throwable $e) {
            return [];
        }

        $sections = [];
        foreach ($blocks as $block) {
            if (empty($block['enabled'])) {
                continue;
            }

            $title = trim((string)($block['title'] ?? ''));
            $content = trim((string)($block['content'] ?? ''));
            if ($content === '') {
                continue;
            }

            $sections[] = $title !== ''
                ? $title . ":\n" . $content
                : $content;
        }

        return $sections;
    }

    /**
     * Check whether one admin-managed block is enabled.
     */
    private function isBlockEnabled(array $settings, string $key, bool $default): bool {
        if (!isset($settings[$key])) {
            return $default;
        }
        return !empty($settings[$key]['enabled']);
    }

    /**
     * Resolve customizable content for a static block.
     */
    private function getStaticBlockContent(array $settings, string $key, string $fallback): string {
        $configured = trim((string)($settings[$key]['content'] ?? ''));
        return $configured !== '' ? $configured : $fallback;
    }

    /**
     * Build a small always-on Doki mental model.
     */
    private function buildCoreOverview(array $workspace): string {
        $appPath = trim((string)($workspace['app_path'] ?? ''));
        $previewStatus = trim((string)($workspace['preview_status'] ?? 'idle'));

        $lines = [
            'Doki app model:',
            '- Doki apps usually bootstrap with `require_once __DIR__ . \'/../../includes/AppContext.php\';` and `$app = new AppContext(\'app-id\');`.',
            '- Main app files live under the active app path. `manifest.yaml` declares metadata, capabilities, navigation, and runtime needs.',
            '- If code uses an AppContext method with a required capability, make sure `manifest.yaml` includes that capability.',
            '- Preview mode runs from a workspace preview copy and uses workspace-local storage.',
            '- For app data and settings, prefer AppContext helpers over raw PHP filesystem calls.',
            '- Prefer existing AppContext methods and manifest fields over inventing new Doki APIs.',
        ];

        if ($appPath !== '') {
            $lines[] = '- Active app path for this workspace: `' . $appPath . '`.';
        }
        if ($previewStatus !== '') {
            $lines[] = '- Current preview status: `' . $previewStatus . '`.';
        }

        return implode("\n", $lines);
    }

    /**
     * Build a section for AppContext methods already referenced in the current file.
     */
    private function buildDetectedMethodsSection(array $methodNames): string {
        $catalog = $this->getMethodCatalog();
        $lines = ['AppContext methods already used in the current file:'];

        foreach (array_slice($methodNames, 0, self::MAX_DETECTED_METHODS) as $methodName) {
            if (!isset($catalog[$methodName])) {
                continue;
            }

            $entry = $catalog[$methodName];
            $lines[] = $this->formatMethodReference($entry);
        }

        return implode("\n", $lines);
    }

    /**
     * Build grouped AppContext references.
     */
    private function buildGroupSections(array $groupIds): string {
        $groupDefinitions = $this->getGroupDefinitions();
        $catalog = $this->getMethodCatalog();
        $sections = [];

        foreach ($groupIds as $groupId) {
            if (!isset($groupDefinitions[$groupId])) {
                continue;
            }

            $group = $groupDefinitions[$groupId];
            $lines = [];
            $lines[] = sprintf(
                'AppContext group: %s - %s',
                $group['label'],
                $group['description']
            );

            $count = 0;
            foreach ($group['methods'] as $methodName) {
                if (!isset($catalog[$methodName])) {
                    continue;
                }
                $lines[] = $this->formatMethodReference($catalog[$methodName]);
                $count++;
                if ($count >= self::MAX_METHODS_PER_GROUP) {
                    break;
                }
            }

            if ($count > 0) {
                $sections[] = implode("\n", $lines);
            }
        }

        return implode("\n\n", $sections);
    }

    /**
     * Build manifest + capability reference.
     */
    private function buildManifestReference(array $focusAreas): string {
        $commonCapabilities = [
            AppCapabilities::UI_LAYOUT,
            AppCapabilities::FS_APP_DATA,
            AppCapabilities::AUDIT_WRITE,
            AppCapabilities::DB_APP_TABLES,
            AppCapabilities::CONFIG_TARGETS,
            AppCapabilities::CONFIG_ENVIRONMENTS,
            AppCapabilities::GIT_PROVIDERS,
        ];

        if (in_array('runtime', $focusAreas, true)) {
            $commonCapabilities[] = AppCapabilities::EXEC_DOCKER;
            $commonCapabilities[] = AppCapabilities::EXEC_COMMANDS;
        }
        if (in_array('config', $focusAreas, true)) {
            $commonCapabilities[] = AppCapabilities::CONFIG_SECRETS;
        }
        if (in_array('git', $focusAreas, true)) {
            $commonCapabilities[] = AppCapabilities::GIT_TOKENS;
        }

        $capabilityLines = [];
        foreach (array_values(array_unique($commonCapabilities)) as $capability) {
            $info = AppCapabilities::getCapabilityInfo($capability);
            $capabilityLines[] = sprintf(
                '- `%s`: %s (%s risk, %s)',
                $capability,
                $info['name'] ?? $capability,
                $info['risk'] ?? 'unknown',
                strtolower((string)($info['category'] ?? 'Unknown'))
            );
        }

        $trustLines = [];
        foreach ([AppCapabilities::TRUST_SANDBOXED, AppCapabilities::TRUST_TRUSTED, AppCapabilities::TRUST_VERIFIED] as $level) {
            $info = AppCapabilities::getTrustLevelInfo($level);
            $trustLines[] = sprintf(
                '- `%s`: %s',
                strtolower((string)($info['name'] ?? 'sandboxed')),
                (string)($info['description'] ?? '')
            );
        }

        return implode("\n", [
            'Manifest quick reference:',
            '- Common fields: `id`, `name`, `description`, `icon`, `color`, `version`, `capabilities`, `navigation`, `defaultSettings`, `requiredImages`, `requiredServices`.',
            '- A minimal starter app usually includes `ui.layout`, `fs.app_data`, and `audit.write`.',
            '- If the app has user preferences, define `defaultSettings` and use `getSettings()` / `saveSettings()` in code.',
            '- Use the least-privileged capability set that supports the feature.',
            '- If you add runtime or secrets access, expect the minimum trust level to increase.',
            'Trust levels:',
            ...$trustLines,
            'Common capabilities:',
            ...$capabilityLines,
            'Starter manifest example:',
            '```yaml',
            'id: my-tool',
            'name: My Tool',
            'description: Simple internal tool',
            'icon: fa-sparkles',
            'color: "#2563eb"',
            'version: 0.1.0',
            'capabilities:',
            '  - ui.layout',
            '  - fs.app_data',
            '  - audit.write',
            'navigation:',
            '  - id: home',
            '    label: Home',
            '    icon: fa-house',
            '    page: index.php',
            '```',
        ]);
    }

    /**
     * Build storage and settings safety rules for AI code generation.
     */
    private function buildStorageSafetySection(): string {
        return implode("\n", [
            'Storage and settings rules:',
            '- Use `$app->readFile()`, `$app->writeFile()`, and `$app->deleteFile()` for app-local files.',
            '- Use `$app->getSettings()` and `$app->saveSettings()` for settings. Do not read or write `settings.json` manually.',
            '- App storage may be empty on first run and in preview mode. Missing files must resolve to an empty state without warnings.',
            '- Avoid raw filesystem calls like `file_get_contents()`, `file_put_contents()`, `fopen()`, `unlink()`, or `mkdir()` against `$app->getStoragePath()` when an AppContext helper exists.',
            '- Do not hardcode Studio preview or workspace data paths. Let AppContext resolve storage locations.',
        ]);
    }

    /**
     * Build Doki UI and styling guidance.
     */
    private function buildUiStylingSection(): string {
        return implode("\n", [
            'Doki UI styling rules:',
            '- Use the standard Doki shell: `$layout->renderHead(...)`, `$layout->renderSidebar()`, a `main.main-content`, and a `.content-area` wrapper with `$app->renderAppHeader()`.',
            '- Prefer Doki-style classes and structure such as `card`, `card-header`, `card-body`, `form-group`, `form-control`, `btn`, `btn-primary`, `btn-secondary`, `btn-sm`, and simple grid/list sections before inventing a new design system.',
            '- Keep custom CSS small and local to the app. Build on Doki theme variables like `var(--bg-primary)`, `var(--bg-secondary)`, `var(--border-color)`, `var(--text-primary)`, `var(--text-secondary)`, `var(--text-muted)`, and `var(--accent-primary)`.',
            '- Avoid external CSS frameworks or CDN stylesheets such as Bootstrap, Tailwind CDN, or custom web font/CDN dependencies inside apps.',
            '- Match Doki visually: rounded cards, subtle borders, muted secondary text, compact spacing, and restrained accent color use instead of a fully custom brand treatment.',
            '- When adding CSS, prefer app-specific class names for custom sections and use Doki tokens for color, spacing, and focus states.',
        ]);
    }

    /**
     * Build one or two targeted examples the model can imitate.
     */
    private function buildExamplesSection(array $exampleIds): string {
        $examples = $this->getExamples();
        $sections = ['Canonical Doki examples:'];

        foreach ($exampleIds as $exampleId) {
            if (!isset($examples[$exampleId])) {
                continue;
            }

            $example = $examples[$exampleId];
            $sections[] = sprintf('%s - %s', $example['title'], $example['description']);
            $sections[] = '```' . $example['language'];
            $sections[] = rtrim($example['code']);
            $sections[] = '```';
        }

        return implode("\n", $sections);
    }

    /**
     * Select the most relevant AppContext groups.
     */
    private function selectGroups(array $focusAreas, array $detectedMethods): array {
        $groupDefinitions = $this->getGroupDefinitions();
        $selected = ['basics'];

        foreach ($detectedMethods as $methodName) {
            foreach ($groupDefinitions as $groupId => $definition) {
                if (in_array($methodName, $definition['methods'], true) && !in_array($groupId, $selected, true)) {
                    $selected[] = $groupId;
                }
            }
        }

        $focusToGroups = [
            'php' => ['ui'],
            'auth' => ['auth'],
            'storage' => ['storage'],
            'data' => ['data'],
            'config' => ['config'],
            'git' => ['git'],
            'runtime' => ['runtime', 'services'],
            'manifest' => ['ui'],
            'capabilities' => ['ui'],
        ];

        foreach ($focusAreas as $focusArea) {
            foreach ($focusToGroups[$focusArea] ?? [] as $groupId) {
                if (!in_array($groupId, $selected, true)) {
                    $selected[] = $groupId;
                }
            }
        }

        return array_slice($selected, 0, self::MAX_GROUPS);
    }

    /**
     * Choose examples that match the current request.
     */
    private function selectExamples(array $focusAreas): array {
        $selected = ['starter-page'];

        if (in_array('storage', $focusAreas, true)) {
            $selected[] = 'storage-pattern';
        }
        if (in_array('config', $focusAreas, true)) {
            $selected[] = 'settings-pattern';
        }
        if (in_array('php', $focusAreas, true)) {
            $selected[] = 'doki-ui-pattern';
        }
        if (in_array('data', $focusAreas, true)) {
            $selected[] = 'database-pattern';
        }
        if (in_array('auth', $focusAreas, true)) {
            $selected[] = 'auth-pattern';
        }
        if (
            count($selected) < self::MAX_EXAMPLES
            && (in_array('manifest', $focusAreas, true) || in_array('capabilities', $focusAreas, true))
        ) {
            $selected[] = 'starter-manifest';
        }

        return array_slice(array_values(array_unique($selected)), 0, self::MAX_EXAMPLES);
    }

    /**
     * Detect current request focus from the selected file and conversation.
     */
    private function detectFocusAreas(string $currentFilePath, ?string $currentFileContent, array $conversation): array {
        $areas = [];
        $normalizedPath = strtolower(trim($currentFilePath));
        $signal = strtolower($normalizedPath . "\n" . (string)$currentFileContent . "\n" . $this->flattenConversation($conversation));

        if ($normalizedPath !== '' && preg_match('/\.php$/', $normalizedPath)) {
            $areas[] = 'php';
        }
        if (preg_match('/manifest\.ya?ml$/', $normalizedPath)) {
            $areas[] = 'manifest';
            $areas[] = 'capabilities';
        }

        $keywordMap = [
            'auth' => ['auth', 'login', 'user', 'role', 'permission'],
            'storage' => ['storage', 'file', 'files', 'readfile', 'writefile', 'save', 'backup'],
            'data' => ['database', 'query', 'sql', 'table', 'sqlite', 'pdo'],
            'config' => ['setting', 'settings', 'environment', 'target', 'secret', 'config'],
            'git' => ['git', 'github', 'gitlab', 'clone', 'pull', 'repository', 'repo'],
            'runtime' => ['docker', 'container', 'image', 'service', 'services', 'runtime', 'exec'],
            'manifest' => ['manifest', 'navigation', 'defaultsettings', 'requiredimages', 'requiredservices'],
            'capabilities' => ['capability', 'capabilities', 'trust', 'sandboxed', 'verified', 'trusted'],
        ];

        foreach ($keywordMap as $area => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($signal, $keyword)) {
                    $areas[] = $area;
                    break;
                }
            }
        }

        if ($areas === []) {
            $areas = ['php', 'manifest'];
        }

        return array_values(array_unique($areas));
    }

    /**
     * Find AppContext methods already called in the current file.
     */
    private function detectAppContextMethods(?string $currentFileContent): array {
        if (!is_string($currentFileContent) || trim($currentFileContent) === '') {
            return [];
        }

        preg_match_all('/->([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $currentFileContent, $matches);
        $availableMethods = array_fill_keys(array_keys($this->getMethodCatalog()), true);
        $detected = [];

        foreach ($matches[1] ?? [] as $methodName) {
            if (!isset($availableMethods[$methodName]) || isset($detected[$methodName])) {
                continue;
            }
            $detected[$methodName] = true;
        }

        return array_keys($detected);
    }

    /**
     * Build the AppContext method catalog from reflection plus curated metadata.
     */
    private function getMethodCatalog(): array {
        if (self::$methodCatalog !== null) {
            return self::$methodCatalog;
        }

        $reflection = new ReflectionClass(AppContext::class);
        $metadata = $this->getMethodMetadata();
        $catalog = [];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== AppContext::class) {
                continue;
            }

            $name = $method->getName();
            $methodMeta = $metadata[$name] ?? [];
            $catalog[$name] = [
                'name' => $name,
                'signature' => $this->buildMethodSignature($method),
                'description' => $methodMeta['description'] ?? $this->extractDocSummary($method->getDocComment()) ?? 'AppContext helper.',
                'capability' => $methodMeta['capability'] ?? null,
                'previewSafe' => $methodMeta['previewSafe'] ?? true,
                'returnShape' => $methodMeta['returnShape'] ?? null,
                'example' => $methodMeta['example'] ?? null,
            ];
        }

        ksort($catalog);
        self::$methodCatalog = $catalog;

        return self::$methodCatalog;
    }

    /**
     * Format one method entry into compact prompt text.
     */
    private function formatMethodReference(array $entry): string {
        $parts = [];
        $parts[] = '- `' . $entry['signature'] . '`';
        $parts[] = '- ' . $entry['description'];

        if (!empty($entry['capability'])) {
            $parts[] = '- requires capability `' . $entry['capability'] . '`';
        }

        if (array_key_exists('previewSafe', $entry)) {
            $parts[] = '- preview safe: ' . ($entry['previewSafe'] ? 'yes' : 'usually no');
        }

        if (!empty($entry['returnShape'])) {
            $parts[] = '- returns: ' . $entry['returnShape'];
        }

        if (!empty($entry['example'])) {
            $parts[] = '- example: `' . $entry['example'] . '`';
        }

        return implode(' ', $parts);
    }

    /**
     * Build a readable method signature from reflection.
     */
    private function buildMethodSignature(ReflectionMethod $method): string {
        $params = [];
        foreach ($method->getParameters() as $parameter) {
            $segment = '';
            $type = $parameter->getType();
            if ($type !== null) {
                $segment .= $this->formatType($type) . ' ';
            }
            if ($parameter->isPassedByReference()) {
                $segment .= '&';
            }
            if ($parameter->isVariadic()) {
                $segment .= '...';
            }
            $segment .= '$' . $parameter->getName();
            if ($parameter->isOptional() && $parameter->isDefaultValueAvailable()) {
                $segment .= ' = ' . $this->formatDefaultValue($parameter->getDefaultValue());
            }
            $params[] = $segment;
        }

        $returnType = $method->getReturnType();
        $signature = $method->getName() . '(' . implode(', ', $params) . ')';
        if ($returnType !== null) {
            $signature .= ': ' . $this->formatType($returnType);
        }

        return $signature;
    }

    /**
     * Format a reflection type for prompt use.
     */
    private function formatType(ReflectionType $type): string {
        if ($type instanceof ReflectionNamedType) {
            $name = $type->getName();
            if ($type->allowsNull() && $name !== 'mixed' && $name !== 'null') {
                return '?' . $name;
            }
            return $name;
        }

        if ($type instanceof ReflectionUnionType) {
            $parts = [];
            foreach ($type->getTypes() as $part) {
                $parts[] = $this->formatType($part);
            }
            return implode('|', $parts);
        }

        if ($type instanceof ReflectionIntersectionType) {
            $parts = [];
            foreach ($type->getTypes() as $part) {
                $parts[] = $this->formatType($part);
            }
            return implode('&', $parts);
        }

        return 'mixed';
    }

    /**
     * Format default parameter values compactly.
     */
    private function formatDefaultValue($value): string {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_string($value)) {
            return "'" . $value . "'";
        }
        if (is_array($value)) {
            return '[]';
        }
        return (string)$value;
    }

    /**
     * Extract the first sentence from a method docblock.
     */
    private function extractDocSummary($docComment): ?string {
        if (!is_string($docComment) || trim($docComment) === '') {
            return null;
        }

        $lines = preg_split('/\R/', $docComment) ?: [];
        foreach ($lines as $line) {
            $clean = trim(trim($line), "/* \t");
            if ($clean === '' || str_starts_with($clean, '@')) {
                continue;
            }
            return rtrim($clean, '. ') . '.';
        }

        return null;
    }

    /**
     * Flatten conversation text into a single keyword-scannable string.
     */
    private function flattenConversation(array $conversation): string {
        $chunks = [];

        foreach ($conversation as $message) {
            if (!is_array($message)) {
                continue;
            }
            $chunks[] = trim((string)($message['content'] ?? ''));
        }

        return implode("\n", array_filter($chunks, fn($value) => $value !== ''));
    }

    /**
     * Static group definitions for AppContext methods.
     */
    private function getGroupDefinitions(): array {
        return [
            'basics' => [
                'label' => 'Basics',
                'description' => 'Core app identity, trust, preview, and manifest helpers.',
                'methods' => ['getAppId', 'getManifest', 'can', 'require', 'getTrustLevel', 'getTrustLevelInfo', 'isPreviewMode', 'getPreviewWorkspaceId'],
            ],
            'auth' => [
                'label' => 'Auth',
                'description' => 'User gating, roles, and audit logging.',
                'methods' => ['requireAuth', 'requireRole', 'getUser', 'hasRole', 'audit'],
            ],
            'storage' => [
                'label' => 'Storage and Settings',
                'description' => 'Workspace-local file storage, backups, and simple settings persistence.',
                'methods' => ['getStoragePath', 'readFile', 'writeFile', 'deleteFile', 'createBackup', 'getBackups', 'getSettings', 'saveSettings'],
            ],
            'data' => [
                'label' => 'Database',
                'description' => 'App-scoped table helpers and SQL access.',
                'methods' => ['getDatabase', 'query', 'createTable', 'tableName'],
            ],
            'config' => [
                'label' => 'Configuration',
                'description' => 'Read targets, environments, and secrets from Doki config.',
                'methods' => ['getTargets', 'getTarget', 'getEnvironments', 'getEnvironment', 'getSecret'],
            ],
            'git' => [
                'label' => 'Git',
                'description' => 'Repository cloning and pull helpers backed by configured providers.',
                'methods' => ['getGitProviders', 'cloneRepository', 'pullRepository'],
            ],
            'ui' => [
                'label' => 'UI',
                'description' => 'Standard Doki page chrome and header helpers.',
                'methods' => ['getLayout', 'renderAppHeader'],
            ],
            'runtime' => [
                'label' => 'Runtime and Execution',
                'description' => 'Command, container, and image helpers for higher-trust apps.',
                'methods' => ['execute', 'getJob', 'getRequiredImages', 'checkImageExists', 'runDockerCommandOnTarget', 'pullImage', 'executeInContainer'],
            ],
            'services' => [
                'label' => 'Services',
                'description' => 'Manifest-driven service definitions and health helpers.',
                'methods' => ['getDockerHost', 'getRequiredServices', 'getServiceDefinition', 'checkServiceHealth', 'getServiceUrl', 'ensureServiceRunning', 'stopService', 'getServicesStatus'],
            ],
        ];
    }

    /**
     * Curated method metadata layered on top of reflection.
     */
    private function getMethodMetadata(): array {
        return [
            '__construct' => [
                'description' => 'Create the Doki app context for a specific manifest app ID.',
                'example' => '$app = new AppContext(\'my-tool\');',
            ],
            'can' => [
                'description' => 'Check whether the app trust level grants a capability before using it.',
                'example' => '$app->can(AppCapabilities::FS_APP_DATA)',
            ],
            'require' => [
                'description' => 'Throw an AppCapabilityException if the app lacks a capability.',
                'example' => '$app->require(AppCapabilities::FS_APP_DATA)',
            ],
            'getTrustLevel' => [
                'description' => 'Return the current app trust level integer.',
                'returnShape' => '0-3 trust level constant',
            ],
            'getTrustLevelInfo' => [
                'description' => 'Return trust level metadata like name, icon, description, and color.',
                'returnShape' => 'array{name, description, color, icon, ...}',
            ],
            'requireAuth' => [
                'description' => 'Require a logged-in user and return the user record.',
                'capability' => AppCapabilities::AUTH_READ_USER,
                'example' => '$user = $app->requireAuth();',
                'returnShape' => 'user array',
            ],
            'requireRole' => [
                'description' => 'Require authentication plus a minimum role such as admin.',
                'capability' => AppCapabilities::AUTH_CHECK_ROLES,
                'example' => '$app->requireRole(\'admin\');',
                'returnShape' => 'user array',
            ],
            'getUser' => [
                'description' => 'Return the cached authenticated user or null.',
                'returnShape' => 'user array or null',
            ],
            'hasRole' => [
                'description' => 'Check whether the current authenticated user meets a role threshold.',
                'example' => '$app->hasRole(\'admin\')',
                'returnShape' => 'bool',
            ],
            'isPreviewMode' => [
                'description' => 'Tell whether the app is running from App Studio preview instead of installed app runtime.',
                'returnShape' => 'bool',
            ],
            'getPreviewWorkspaceId' => [
                'description' => 'Return the active workspace preview ID when running in preview mode.',
                'returnShape' => 'workspace ID or null',
            ],
            'audit' => [
                'description' => 'Write a Doki audit log entry for sensitive app actions.',
                'capability' => AppCapabilities::AUDIT_WRITE,
                'example' => '$app->audit(\'settings_save\');',
                'previewSafe' => true,
            ],
            'getDatabase' => [
                'description' => 'Get the shared PDO connection with app-table capability checks.',
                'capability' => AppCapabilities::DB_APP_TABLES,
                'returnShape' => 'PDO',
                'example' => '$db = $app->getDatabase();',
            ],
            'query' => [
                'description' => 'Run SQL with automatic restrictions unless the app has full DB access.',
                'capability' => AppCapabilities::DB_APP_TABLES,
                'returnShape' => 'PDOStatement',
                'example' => '$rows = $app->query(\'SELECT * FROM \' . $app->tableName(\'items\'))->fetchAll();',
            ],
            'createTable' => [
                'description' => 'Create an app-namespaced table like `app_<appId>_<table>`.',
                'capability' => AppCapabilities::DB_APP_TABLES,
                'example' => '$app->createTable(\'items\', \'id INTEGER PRIMARY KEY, name TEXT\');',
            ],
            'tableName' => [
                'description' => 'Return the fully qualified app table name for SQL queries.',
                'example' => '$app->tableName(\'items\')',
                'returnShape' => 'string',
            ],
            'getStoragePath' => [
                'description' => 'Return the app data directory. In preview this points to workspace-local preview data.',
                'capability' => AppCapabilities::FS_APP_DATA,
                'returnShape' => 'absolute path string',
            ],
            'readFile' => [
                'description' => 'Read a file from the app storage directory. Missing files return null instead of throwing warnings.',
                'capability' => AppCapabilities::FS_APP_DATA,
                'returnShape' => 'file contents or null',
                'example' => '$json = $app->readFile(\'cache/data.json\');',
            ],
            'writeFile' => [
                'description' => 'Write a file into the app storage directory, creating folders as needed.',
                'capability' => AppCapabilities::FS_APP_DATA,
                'returnShape' => 'bool',
                'example' => '$app->writeFile(\'notes/today.txt\', $content);',
            ],
            'deleteFile' => [
                'description' => 'Delete a file from the app storage directory.',
                'capability' => AppCapabilities::FS_APP_DATA,
                'returnShape' => 'bool',
                'example' => '$app->deleteFile(\'cache/tmp.json\');',
            ],
            'createBackup' => [
                'description' => 'Store a timestamped backup under the app history folder.',
                'capability' => AppCapabilities::FS_APP_DATA,
                'returnShape' => 'backup path or null',
            ],
            'getBackups' => [
                'description' => 'List timestamped backups for a stored file.',
                'capability' => AppCapabilities::FS_APP_DATA,
                'returnShape' => 'array<int, array{filename, path, timestamp, date, size}>',
            ],
            'getSettings' => [
                'description' => 'Load saved app settings or fall back to `defaultSettings` from the manifest. Prefer this over reading `settings.json` directly.',
                'returnShape' => 'settings array',
                'example' => '$settings = $app->getSettings();',
            ],
            'saveSettings' => [
                'description' => 'Persist app settings to storage and create a backup of the previous file. Prefer this over writing `settings.json` manually.',
                'capability' => AppCapabilities::FS_APP_DATA,
                'returnShape' => 'array{success: bool}',
                'example' => '$app->saveSettings($settings);',
            ],
            'getTargets' => [
                'description' => 'Read configured execution targets.',
                'capability' => AppCapabilities::CONFIG_TARGETS,
                'returnShape' => 'array<int, target array>',
            ],
            'getTarget' => [
                'description' => 'Read one configured target definition.',
                'capability' => AppCapabilities::CONFIG_TARGETS,
                'returnShape' => 'target array or null',
            ],
            'getEnvironments' => [
                'description' => 'Read configured environments.',
                'capability' => AppCapabilities::CONFIG_ENVIRONMENTS,
                'returnShape' => 'array<int, environment array>',
            ],
            'getEnvironment' => [
                'description' => 'Read one configured environment definition.',
                'capability' => AppCapabilities::CONFIG_ENVIRONMENTS,
                'returnShape' => 'environment array or null',
            ],
            'getSecret' => [
                'description' => 'Read a named secret value and audit the access.',
                'capability' => AppCapabilities::CONFIG_SECRETS,
                'previewSafe' => false,
                'returnShape' => 'secret value or null',
                'example' => '$token = $app->getSecret(\'my_api_key\');',
            ],
            'execute' => [
                'description' => 'Submit a command to the orchestrator and return job metadata.',
                'capability' => AppCapabilities::EXEC_COMMANDS,
                'previewSafe' => false,
                'returnShape' => 'job result array',
            ],
            'getJob' => [
                'description' => 'Poll an orchestrator job started by `execute()`.',
                'capability' => AppCapabilities::EXEC_COMMANDS,
                'previewSafe' => false,
                'returnShape' => 'job array or null',
            ],
            'getGitProviders' => [
                'description' => 'List configured Git providers from SourcesManager.',
                'capability' => AppCapabilities::GIT_PROVIDERS,
                'previewSafe' => false,
                'returnShape' => 'provider list',
            ],
            'cloneRepository' => [
                'description' => 'Clone a repository into app storage using an optional provider credential.',
                'capability' => AppCapabilities::GIT_PROVIDERS,
                'previewSafe' => false,
                'returnShape' => 'array{success: bool, error?: string}',
            ],
            'pullRepository' => [
                'description' => 'Run `git pull` inside a repository stored in app storage.',
                'capability' => AppCapabilities::GIT_PROVIDERS,
                'previewSafe' => false,
                'returnShape' => 'array{success: bool, output?: string, error?: string}',
            ],
            'getLayout' => [
                'description' => 'Return the shared Doki Layout helper for rendering page head and sidebar.',
                'capability' => AppCapabilities::UI_LAYOUT,
                'returnShape' => 'Layout',
                'example' => '$layout = $app->getLayout();',
            ],
            'getManifest' => [
                'description' => 'Return the parsed app manifest array.',
                'returnShape' => 'manifest array',
            ],
            'getAppId' => [
                'description' => 'Return the resolved app ID.',
                'returnShape' => 'string',
            ],
            'renderAppHeader' => [
                'description' => 'Render the standard app header with manifest name, description, icon, and preview state.',
                'capability' => AppCapabilities::UI_LAYOUT,
                'example' => '$app->renderAppHeader();',
            ],
            'generateId' => [
                'description' => 'Generate a unique ID string with an optional prefix.',
                'returnShape' => 'string',
                'example' => '$id = $app->generateId(\'item_\');',
            ],
            'formatDate' => [
                'description' => 'Format a date string using a PHP date format.',
                'returnShape' => 'string',
            ],
            'formatSize' => [
                'description' => 'Format a byte count as a readable size.',
                'returnShape' => 'string',
            ],
            'getRequiredImages' => [
                'description' => 'Read and normalize `requiredImages` entries from the manifest.',
                'returnShape' => 'normalized image definitions',
            ],
            'checkImageExists' => [
                'description' => 'Check whether a Docker image exists on the configured target.',
                'capability' => AppCapabilities::EXEC_DOCKER,
                'previewSafe' => false,
                'returnShape' => 'array{exists: bool}',
            ],
            'runDockerCommandOnTarget' => [
                'description' => 'Run a raw Docker CLI command against the app exec target.',
                'capability' => AppCapabilities::EXEC_DOCKER,
                'previewSafe' => false,
                'returnShape' => 'docker command result array',
            ],
            'pullImage' => [
                'description' => 'Pull a Docker image to the configured target.',
                'capability' => AppCapabilities::EXEC_DOCKER,
                'previewSafe' => false,
                'returnShape' => 'array{success: bool, pulled?: bool, error?: string}',
            ],
            'executeInContainer' => [
                'description' => 'Run a command in a Docker container with optional volumes, env, and network.',
                'capability' => AppCapabilities::EXEC_DOCKER,
                'previewSafe' => false,
                'returnShape' => 'container execution result array',
            ],
            'getDockerHost' => [
                'description' => 'Return the host name apps should use to reach host services from containers.',
                'returnShape' => 'string',
            ],
            'getRequiredServices' => [
                'description' => 'Read and normalize `requiredServices` definitions from the manifest.',
                'returnShape' => 'normalized service definitions',
            ],
            'getServiceDefinition' => [
                'description' => 'Look up a named service declared in the manifest.',
                'returnShape' => 'service array or null',
            ],
            'checkServiceHealth' => [
                'description' => 'Check a declared service health endpoint over HTTP.',
                'capability' => AppCapabilities::EXEC_DOCKER,
                'previewSafe' => false,
                'returnShape' => 'array{healthy: bool, httpCode?: int, error?: string}',
            ],
            'getServiceUrl' => [
                'description' => 'Build the HTTP URL for a declared service.',
                'returnShape' => 'string or null',
            ],
            'isServiceRunning' => [
                'description' => 'Check whether a declared service container is running.',
                'returnShape' => 'bool',
                'previewSafe' => false,
            ],
            'ensureServiceRunning' => [
                'description' => 'Start a declared service if needed and wait for health.',
                'capability' => AppCapabilities::EXEC_DOCKER,
                'previewSafe' => false,
                'returnShape' => 'array{success: bool, url?: string, message?: string, error?: string}',
            ],
            'stopService' => [
                'description' => 'Stop a declared service container.',
                'capability' => AppCapabilities::EXEC_DOCKER,
                'previewSafe' => false,
                'returnShape' => 'array{success: bool, message?: string}',
            ],
            'getServicesStatus' => [
                'description' => 'Return runtime status for all declared services.',
                'returnShape' => 'map of service status records',
                'previewSafe' => false,
            ],
        ];
    }

    /**
     * Canonical examples the model can imitate.
     */
    private function getExamples(): array {
        return [
            'starter-page' => [
                'title' => 'Starter page',
                'description' => 'Minimal Doki page using AppContext, auth, layout, and app header.',
                'language' => 'php',
                'code' => <<<'PHP'
<?php
require_once __DIR__ . '/../../includes/AppContext.php';

$app = new AppContext('my-tool');
$user = $app->requireAuth();
$layout = $app->getLayout();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php $layout->renderHead('My Tool'); ?>
</head>
<body>
    <div class="app-container">
        <?php $layout->renderSidebar(); ?>
        <main class="main-content">
            <div class="content-area">
                <?php $app->renderAppHeader(); ?>
                <div class="card">
                    <div class="card-body">
                        Welcome, <?= htmlspecialchars($user['name'] ?? $user['username'] ?? 'there') ?>.
                    </div>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
PHP,
            ],
            'starter-manifest' => [
                'title' => 'Starter manifest',
                'description' => 'Minimal safe manifest for a standard App Studio app.',
                'language' => 'yaml',
                'code' => <<<'YAML'
id: my-tool
name: My Tool
description: Simple internal tool
icon: fa-sparkles
color: "#2563eb"
version: 0.1.0
enabled: true
capabilities:
  - ui.layout
  - fs.app_data
  - audit.write
navigation:
  - id: home
    label: Home
    icon: fa-house
    page: index.php
YAML,
            ],
            'storage-pattern' => [
                'title' => 'Storage pattern',
                'description' => 'Simple app data persistence using app-local storage.',
                'language' => 'php',
                'code' => <<<'PHP'
<?php
$notesJson = $app->readFile('notes.json');
$notes = $notesJson ? json_decode($notesJson, true) : [];

$notes[] = [
    'id' => $app->generateId('note_'),
    'text' => trim($_POST['text'] ?? ''),
    'createdAt' => date('c'),
];

$app->writeFile('notes.json', json_encode($notes, JSON_PRETTY_PRINT));
$app->audit('note_created');
PHP,
            ],
            'settings-pattern' => [
                'title' => 'Settings pattern',
                'description' => 'Safe settings loading with defaults and AppContext helpers.',
                'language' => 'php',
                'code' => <<<'PHP'
<?php
$settings = $app->getSettings();
if (!is_array($settings)) {
    $settings = [];
}

$settings = array_merge([
    'view' => 'comfortable',
    'sort' => 'newest',
], $settings);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $settings['view'] = ($_POST['view'] ?? 'comfortable') === 'compact'
        ? 'compact'
        : 'comfortable';
    $app->saveSettings($settings);
    $app->audit('settings_saved');
}
PHP,
            ],
            'doki-ui-pattern' => [
                'title' => 'Doki UI pattern',
                'description' => 'A Doki-looking app page using standard shell helpers, cards, forms, and theme variables.',
                'language' => 'php',
                'code' => <<<'PHP'
<?php
require_once __DIR__ . '/../../includes/AppContext.php';

$app = new AppContext('my-tool');
$user = $app->requireAuth();
$layout = $app->getLayout();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $layout->renderHead('My Tool'); ?>
    <style>
        .tool-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 16px;
        }

        .tool-metric {
            padding: 16px 18px;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            background: var(--bg-secondary);
        }

        .tool-metric-label {
            margin: 0 0 8px 0;
            font-size: 12px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .tool-metric-value {
            margin: 0;
            font-size: 28px;
            font-weight: 700;
            color: var(--text-primary);
        }
    </style>
</head>
<body>
    <div class="app-container">
        <?php $layout->renderSidebar(); ?>
        <main class="main-content">
            <div class="content-area">
                <?php $app->renderAppHeader(); ?>

                <div class="card" style="margin-bottom: 20px;">
                    <div class="card-header">
                        <h2 style="margin: 0;">Quick Action</h2>
                    </div>
                    <div class="card-body">
                        <form method="post" action="">
                            <div class="form-group">
                                <label for="title">Title</label>
                                <input type="text" id="title" name="title" class="form-control" required>
                            </div>
                            <button type="submit" class="btn btn-primary">Save</button>
                        </form>
                    </div>
                </div>

                <section class="tool-grid">
                    <div class="tool-metric">
                        <p class="tool-metric-label">Items</p>
                        <p class="tool-metric-value">12</p>
                    </div>
                    <div class="tool-metric">
                        <p class="tool-metric-label">Owner</p>
                        <p class="tool-metric-value"><?= htmlspecialchars($user['username'] ?? 'you') ?></p>
                    </div>
                </section>
            </div>
        </main>
    </div>
</body>
</html>
PHP,
            ],
            'database-pattern' => [
                'title' => 'Database pattern',
                'description' => 'App-scoped tables with `createTable()` and `tableName()`.',
                'language' => 'php',
                'code' => <<<'PHP'
<?php
$app->createTable('items', 'id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, created_at TEXT NOT NULL');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $app->query(
        'INSERT INTO ' . $app->tableName('items') . ' (name, created_at) VALUES (?, ?)',
        [trim($_POST['name'] ?? ''), date('c')]
    );
}

$items = $app->query(
    'SELECT id, name, created_at FROM ' . $app->tableName('items') . ' ORDER BY created_at DESC'
)->fetchAll(PDO::FETCH_ASSOC);
PHP,
            ],
            'auth-pattern' => [
                'title' => 'Auth pattern',
                'description' => 'Require a logged-in admin before rendering a page.',
                'language' => 'php',
                'code' => <<<'PHP'
<?php
$user = $app->requireRole('admin');

if (!$app->hasRole('super-admin')) {
    $app->audit('admin_view_opened', null, 'Viewed by ' . ($user['username'] ?? 'unknown'));
}
PHP,
            ],
        ];
    }
}
