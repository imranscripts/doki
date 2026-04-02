<?php
/**
 * WorkspaceAIManager.php - App Studio AI orchestration
 *
 * Builds workspace-scoped prompts, asks the configured model for structured
 * file edits, and applies accepted changes through WorkspaceManager.
 */

require_once __DIR__ . '/WorkspaceManager.php';
require_once __DIR__ . '/AIProviderManager.php';
require_once __DIR__ . '/AIContextCatalog.php';

class WorkspaceAIManager {
    private const MAX_CONTEXT_FILES = 6;
    private const MAX_CONTEXT_BYTES = 60000;
    private const MAX_FILE_LIST = 120;
    private const MAX_MESSAGES = 10;
    private const MAX_OPERATIONS = 10;

    private WorkspaceManager $workspaceManager;
    private AIProviderManager $providerManager;
    private AIContextCatalog $contextCatalog;

    public function __construct() {
        $this->workspaceManager = new WorkspaceManager();
        $this->providerManager = new AIProviderManager();
        $this->contextCatalog = new AIContextCatalog();
    }

    /**
     * Ask the configured AI provider for a workspace-scoped edit plan.
     */
    public function chat(string $workspaceId, string $providerId, array $messages, array $options = []): array {
        $workspace = $this->workspaceManager->getWorkspace($workspaceId);
        if ($workspace === null) {
            return ['success' => false, 'error' => 'Workspace not found'];
        }

        $provider = $this->providerManager->getResolvedProvider($providerId);
        if ($provider === null) {
            return ['success' => false, 'error' => 'AI provider not found'];
        }

        $conversation = $this->sanitizeConversation($messages);
        if (empty($conversation)) {
            return ['success' => false, 'error' => 'At least one chat message is required'];
        }
        $latestUserMessage = $this->findLatestUserMessage($conversation);

        $model = trim((string)($options['model'] ?? ''));
        if ($model === '') {
            $model = trim((string)($provider['default_model'] ?? $provider['defaultModel'] ?? ''));
        }
        if ($model === '') {
            $catalog = $this->providerManager->listModels($providerId);
            if (empty($catalog['success'])) {
                return [
                    'success' => false,
                    'error' => $catalog['error'] ?? 'No model is configured and the provider model list could not be loaded',
                ];
            }
            $model = trim((string)($catalog['recommendedModel'] ?? ''));
        }
        if ($model === '') {
            return ['success' => false, 'error' => 'No model is configured for this provider'];
        }

        $currentFilePath = trim((string)($options['currentFilePath'] ?? ''));
        $target = $this->normalizeTargetOptions($options, $currentFilePath);
        $conversation = $this->bindTargetToConversation($conversation, $target);
        $promptMessages = [
            ['role' => 'system', 'content' => $this->buildSystemPrompt()],
            ['role' => 'user', 'content' => $this->buildWorkspaceContext($workspace, $currentFilePath, $conversation, $target)],
            ...$conversation,
        ];

        $chatResult = $this->providerManager->sendChat($provider, $model, $promptMessages);
        if (empty($chatResult['success'])) {
            return $chatResult;
        }

        $parsed = $this->parseStructuredResponse((string)($chatResult['content'] ?? ''));
        if ($this->shouldRetryTargetClarification($parsed, $target)) {
            $retryMessages = [
                ...$promptMessages,
                [
                    'role' => 'user',
                    'content' => $this->buildTargetClarificationRetryPrompt($target),
                ],
            ];
            $retryResult = $this->providerManager->sendChat($provider, $model, $retryMessages);
            if (!empty($retryResult['success'])) {
                $parsed = $this->parseStructuredResponse((string)($retryResult['content'] ?? ''));
                $chatResult = $retryResult;
            }
        }
        $lint = $this->lintOperations($parsed['operations'], [
            'workspaceId' => $workspaceId,
            'target' => $target,
            'latestUserMessage' => $latestUserMessage,
        ]);

        return [
            'success' => true,
            'reply' => $parsed['reply'],
            'summary' => $parsed['summary'],
            'operations' => $parsed['operations'],
            'notes' => $parsed['notes'],
            'lint' => $lint,
            'model' => $model,
            'provider' => [
                'id' => $provider['id'],
                'name' => $provider['name'],
                'type' => $provider['type'],
            ],
            'usage' => $chatResult['usage'] ?? [],
        ];
    }

    /**
     * Create a new workspace from a single natural-language app request.
     */
    public function createWorkspaceFromPrompt(string $providerId, string $prompt, string $userId, string $username, array $options = []): array {
        $prompt = trim($prompt);
        if ($prompt === '') {
            return ['success' => false, 'error' => 'An app description is required'];
        }

        $seed = $this->deriveWorkspaceSeed($prompt);
        $starter = $this->workspaceManager->createStarterWorkspace([
            'name' => $seed['name'],
            'appId' => $seed['appId'],
            'appName' => $seed['name'],
            'description' => 'AI-generated workspace created from an App Studio prompt',
            'icon' => 'fa-sparkles',
            'color' => '#2563eb',
        ], $userId, $username);
        if (empty($starter['success'])) {
            return $starter;
        }

        $workspaceId = (string)($starter['workspace']['id'] ?? '');
        if ($workspaceId === '') {
            return ['success' => false, 'error' => 'The starter workspace could not be created'];
        }

        $deleteOnFailure = true;

        try {
            $workspace = $starter['workspace'];
            $appPath = trim((string)($workspace['app_path'] ?? ''));
            $currentFilePath = ($appPath === '' || $appPath === '.')
                ? 'index.php'
                : rtrim($appPath, '/') . '/index.php';

            $chatResult = $this->chat($workspaceId, $providerId, [[
                'role' => 'user',
                'content' => $this->buildWorkspaceCreationPrompt($prompt),
            ]], [
                'model' => trim((string)($options['model'] ?? '')),
                'currentFilePath' => $currentFilePath,
            ]);
            if (empty($chatResult['success'])) {
                throw new RuntimeException($chatResult['error'] ?? 'The AI request failed');
            }

            $operations = is_array($chatResult['operations'] ?? null) ? $chatResult['operations'] : [];
            if ($operations === []) {
                throw new RuntimeException('The AI did not return any workspace files to create');
            }

            $applyResult = $this->applyChanges($workspaceId, $operations, $userId);
            if (empty($applyResult['success'])) {
                throw new RuntimeException($applyResult['error'] ?? 'The AI-generated files could not be applied');
            }

            $deleteOnFailure = false;
            $notes = is_array($chatResult['notes'] ?? null) ? array_values($chatResult['notes']) : [];
            $lint = is_array($chatResult['lint'] ?? null) ? array_values($chatResult['lint']) : [];
            if ($lint !== []) {
                $notes[] = sprintf(
                    'AI review flagged %d warning%s in the generated files. Review the app in Studio before shipping it.',
                    count($lint),
                    count($lint) === 1 ? '' : 's'
                );
            }

            $syncResult = $this->workspaceManager->syncWorkspaceMetadataFromManifest($workspaceId, $userId, $username);
            if (empty($syncResult['success'])) {
                $notes[] = $syncResult['error'] ?? 'Workspace metadata could not be refreshed from the generated manifest.';
            }

            $previewResult = $this->workspaceManager->syncPreview($workspaceId);
            if (empty($previewResult['success'])) {
                $notes[] = $previewResult['error'] ?? 'Preview could not be synced automatically.';
            }

            $workspace = $this->workspaceManager->getWorkspace($workspaceId) ?? $starter['workspace'];
            $validation = $applyResult['validation'] ?? $this->workspaceManager->validateWorkspace($workspaceId);

            return [
                'success' => true,
                'workspace' => $workspace,
                'reply' => $chatResult['reply'] ?? 'I created a new workspace from your prompt.',
                'summary' => $chatResult['summary'] ?? 'Created a new AI-generated workspace',
                'operations' => $operations,
                'notes' => array_values(array_filter(array_map('strval', $notes), fn(string $note) => trim($note) !== '')),
                'lint' => $lint,
                'validation' => $validation,
                'preview' => $previewResult,
                'model' => $chatResult['model'] ?? (string)($options['model'] ?? ''),
                'provider' => $chatResult['provider'] ?? ['id' => $providerId],
                'usage' => $chatResult['usage'] ?? [],
            ];
        } catch (Throwable $e) {
            if ($deleteOnFailure) {
                $this->workspaceManager->deleteWorkspace($workspaceId);
            }

            return [
                'success' => false,
                'error' => 'Failed to create AI workspace: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Apply a reviewed set of AI-generated file operations.
     */
    public function applyChanges(string $workspaceId, array $operations, string $userId): array {
        $normalized = $this->normalizeOperations($operations);
        if (empty($normalized)) {
            return ['success' => false, 'error' => 'No valid file operations were provided'];
        }

        $revertOperations = $this->buildRevertOperations($workspaceId, $normalized);
        $result = $this->workspaceManager->applyFileOperations($workspaceId, $normalized, $userId);
        if (empty($result['success'])) {
            return $result;
        }

        $validation = $this->workspaceManager->validateWorkspace($workspaceId);
        $result['validation'] = $validation;
        $result['lint'] = $this->lintOperations($normalized);
        $result['revertOperations'] = $revertOperations;

        return $result;
    }

    /**
     * Build the model-facing system prompt.
     */
    private function buildSystemPrompt(): string {
        return <<<PROMPT
You are the App Studio AI assistant inside Doki.

You help build and edit Doki apps inside a single workspace repository.

You must only propose filesystem changes inside the current workspace.
You do not have shell, git, network, or package manager access.
Prefer the smallest safe change set that fulfills the request.
You will receive a curated Doki framework reference in the request context.
Prefer documented AppContext methods, manifest fields, and starter patterns over inventing new APIs.
Use AppContext storage helpers for app data and settings instead of raw PHP filesystem calls.
Do not manually read or write `settings.json`; use `getSettings()` and `saveSettings()`.
Storage may be empty on first run and in preview mode, so missing files must be handled without warnings.
If the request is unclear, ask one concise follow-up question and return no operations.
If Studio provides a target file or line range, treat the latest user request as already referring to that target.
Do not ask the user which file to edit when a valid target file is present.
If a valid target file is present and the user asks to remove, change, or improve text in that file, inspect the target file contents and make the best reasonable edit directly.
Do not ask the user to repeat the exact text when the target file already contains a plausible matching string.
If there are multiple plausible matches in the target file, choose the most likely UI-visible one and mention the assumption in `notes`.

Return only valid JSON with this exact shape:
{
  "reply": "short assistant message for the user",
  "summary": "one-line summary of the proposed change",
  "operations": [
    {
      "type": "write" | "delete",
      "path": "repo/relative/path",
      "content": "full file contents for write operations",
      "reason": "short reason"
    }
  ],
  "notes": ["optional follow-up notes"]
}

Rules:
- Use "write" for both create and replace.
- Every "write" operation must include the full file content.
- Paths must be repo-relative and must not contain "..".
- Keep operations to 10 files or fewer.
- Prefer `$app->readFile()`, `$app->writeFile()`, `$app->deleteFile()`, `$app->getSettings()`, and `$app->saveSettings()` for app-local data.
- Avoid raw `file_get_contents()`, `file_put_contents()`, `fopen()`, `unlink()`, or hardcoded preview/workspace data paths when AppContext provides a helper.
- If the request context includes a target file or line range, edit that target first and avoid creating unrelated files unless they are clearly necessary.
- If the latest user request says "this file", "the file", "here", or similar shorthand and a target is present, resolve that shorthand to the selected target.
- Do not wrap the JSON in markdown fences.
- If you are answering a question without code changes, return an empty operations array.
PROMPT;
    }

    /**
     * Build the prompt used when creating a brand-new workspace from a request.
     */
    private function buildWorkspaceCreationPrompt(string $prompt): string {
        return <<<PROMPT
Create the initial implementation for a brand-new Doki app workspace from this request:

{$prompt}

Requirements:
- This workspace starts from the default App Studio starter scaffold. Replace or update starter files as needed.
- Produce a working first version of the requested app, not just a stub.
- Update `manifest.yaml` so the app has a suitable name, description, icon, color, navigation, and minimum required capabilities.
- Prefer safe, low-privilege capabilities unless the request clearly needs more.
- Prefer documented `AppContext` methods and starter patterns from the Doki framework reference.
- Keep the app previewable inside Doki without extra setup.
- Preserve the starter workspace app ID and app path unless the request clearly requires renaming them.
- You may update `README.md` and `doki.project.yaml` if useful, but keep this as a single Doki app workspace.
- Make reasonable UX and product decisions without asking a follow-up question unless the request is impossible to implement safely.
- Return actual file operations for the final workspace files.
PROMPT;
    }

    /**
     * Build a compact workspace context block for the model.
     */
    private function buildWorkspaceContext(array $workspace, string $currentFilePath, array $conversation, array $target = []): string {
        $treeResult = $this->workspaceManager->listFiles($workspace['id']);
        $tree = !empty($treeResult['success']) ? ($treeResult['files'] ?? []) : [];
        $flatFiles = $this->flattenTree($tree);
        $fileList = array_slice(array_map(fn(array $file) => $file['path'], $flatFiles), 0, self::MAX_FILE_LIST);
        $currentFileContent = null;

        if ($currentFilePath !== '') {
            $currentFile = $this->workspaceManager->getFileContent($workspace['id'], $currentFilePath);
            if ($currentFile !== null && empty($currentFile['isBinary'])) {
                $currentFileContent = (string)($currentFile['content'] ?? '');
            }
        }

        $targetPath = trim((string)($target['path'] ?? ''));
        $targetStartLine = isset($target['startLine']) ? (int)$target['startLine'] : null;
        $targetEndLine = isset($target['endLine']) ? (int)$target['endLine'] : null;
        $targetSelectedText = trim((string)($target['selectedText'] ?? ''));
        $domSelection = is_array($target['domSelection'] ?? null) ? $target['domSelection'] : [];
        $targetContext = $this->buildTargetContext($workspace['id'], $targetPath, $targetStartLine, $targetEndLine, $targetSelectedText);
        $domContext = $this->buildDomSelectionContext($domSelection);

        $contextPaths = $this->pickContextFiles($workspace, $flatFiles, $currentFilePath, $targetContext['path']);
        $remainingBytes = self::MAX_CONTEXT_BYTES;
        $fileSnippets = [];

        foreach ($contextPaths as $path) {
            if ($remainingBytes <= 0) {
                break;
            }

            $file = $this->workspaceManager->getFileContent($workspace['id'], $path);
            if ($file === null || !empty($file['isBinary'])) {
                continue;
            }

            $content = (string)($file['content'] ?? '');
            if ($content === '') {
                $snippet = '';
            } elseif (strlen($content) > $remainingBytes) {
                $snippet = substr($content, 0, max(0, $remainingBytes)) . "\n... [truncated]";
            } else {
                $snippet = $content;
            }

            $fileSnippets[] = "FILE: {$path}\n" . $snippet;
            $remainingBytes -= strlen($snippet);
        }

        $summaryLines = [
            'Workspace name: ' . ($workspace['name'] ?? ''),
            'Workspace source type: ' . ($workspace['source_type'] ?? ''),
            'App ID: ' . ($workspace['app_id'] ?? ''),
            'Active app path: ' . ($workspace['app_path'] ?? ''),
            'Current file: ' . ($currentFilePath !== '' ? $currentFilePath : 'none'),
            'Requested edit target: ' . ($targetContext['summary'] !== '' ? $targetContext['summary'] : 'none'),
            'Selected preview element: ' . ($domContext['summary'] !== '' ? $domContext['summary'] : 'none'),
            'Preview status: ' . ($workspace['preview_status'] ?? 'idle'),
            'Discovered app count: ' . (string)($workspace['discoveredAppCount'] ?? 0),
        ];

        if (!empty($workspace['discoveredApps']) && is_array($workspace['discoveredApps'])) {
            foreach ($workspace['discoveredApps'] as $app) {
                $summaryLines[] = sprintf(
                    'Discovered app: %s at %s',
                    (string)($app['name'] ?? $app['id'] ?? 'app'),
                    (string)($app['path'] ?? '.')
                );
            }
        }

        $sections = [];
        $sections[] = "Workspace summary:\n- " . implode("\n- ", $summaryLines);
        $sections[] = "Repo file list:\n" . ($fileList === [] ? '(no files found)' : implode("\n", $fileList));
        $referenceBlock = $this->contextCatalog->buildReferenceBlock($workspace, $currentFilePath, $currentFileContent, $conversation);
        if (trim($referenceBlock) !== '') {
            $sections[] = "Doki framework reference:\n" . $referenceBlock;
        }
        if ($targetContext['details'] !== '') {
            $sections[] = "Requested edit target:\n" . $targetContext['details'];
        }
        if ($domContext['details'] !== '') {
            $sections[] = "Selected preview element:\n" . $domContext['details'];
        }
        if ($fileSnippets !== []) {
            $sections[] = "Relevant file contents:\n\n" . implode("\n\n", $fileSnippets);
        }

        return implode("\n\n", $sections);
    }

    /**
     * Normalize optional AI targeting info from the Studio UI.
     */
    private function normalizeTargetOptions(array $options, string $currentFilePath): array {
        $startLine = $this->normalizeLineNumber($options['targetStartLine'] ?? null);
        $endLine = $this->normalizeLineNumber($options['targetEndLine'] ?? null);
        if ($startLine === null && $endLine !== null) {
            $startLine = $endLine;
        }
        if ($endLine === null && $startLine !== null) {
            $endLine = $startLine;
        }
        if ($startLine !== null && $endLine !== null && $endLine < $startLine) {
            [$startLine, $endLine] = [$endLine, $startLine];
        }

        $rawPath = trim((string)($options['targetFilePath'] ?? ''));
        if ($rawPath === '' && ($startLine !== null || $endLine !== null)) {
            $rawPath = $currentFilePath;
        }

        return [
            'path' => $this->normalizeRelativePath($rawPath) ?? '',
            'startLine' => $startLine,
            'endLine' => $endLine,
            'selectedText' => trim((string)($options['targetSelectedText'] ?? '')),
            'domSelection' => $this->normalizeDomSelection($options['targetDomSelection'] ?? null),
        ];
    }

    /**
     * Normalize an optional DOM element selection captured from the live preview.
     */
    private function normalizeDomSelection(mixed $raw): array {
        if (!is_array($raw)) {
            return [];
        }

        $selector = trim((string)($raw['selector'] ?? ''));
        if ($selector === '') {
            return [];
        }

        return [
            'pagePath' => $this->truncateBlock(trim((string)($raw['pagePath'] ?? '')), 300),
            'pageUrl' => $this->truncateBlock(trim((string)($raw['pageUrl'] ?? '')), 800),
            'selector' => $this->truncateBlock($selector, 500),
            'tagName' => $this->truncateBlock(trim((string)($raw['tagName'] ?? '')), 80),
            'role' => $this->truncateBlock(trim((string)($raw['role'] ?? '')), 120),
            'label' => $this->truncateBlock(trim((string)($raw['label'] ?? '')), 400),
            'text' => $this->truncateBlock(trim((string)($raw['text'] ?? '')), 1200),
            'html' => $this->truncateBlock(trim((string)($raw['html'] ?? '')), 1600),
        ];
    }

    /**
     * Attach Studio target info directly to the latest user turn so weaker
     * models do not treat "this file" style requests as ambiguous.
     */
    private function bindTargetToConversation(array $conversation, array $target): array {
        $targetPath = trim((string)($target['path'] ?? ''));
        $domSelection = is_array($target['domSelection'] ?? null) ? $target['domSelection'] : [];
        if ($targetPath === '' && trim((string)($domSelection['selector'] ?? '')) === '') {
            return $conversation;
        }

        for ($index = count($conversation) - 1; $index >= 0; $index--) {
            if (($conversation[$index]['role'] ?? '') !== 'user') {
                continue;
            }

            $conversation[$index]['content'] = $this->buildTargetBoundUserMessage(
                (string)($conversation[$index]['content'] ?? ''),
                $target
            );
            break;
        }

        return $conversation;
    }

    /**
     * Make the active Studio target explicit in the latest user message.
     */
    private function buildTargetBoundUserMessage(string $content, array $target): string {
        $targetPath = trim((string)($target['path'] ?? ''));
        if ($targetPath === '') {
            return $content;
        }

        $targetStartLine = isset($target['startLine']) ? (int)$target['startLine'] : null;
        $targetEndLine = isset($target['endLine']) ? (int)$target['endLine'] : null;
        $targetSelectedText = trim((string)($target['selectedText'] ?? ''));
        $domSelection = is_array($target['domSelection'] ?? null) ? $target['domSelection'] : [];

        $lines = [
            '[Studio target]',
            $targetPath !== ''
                ? 'The user has already selected the file `' . $targetPath . '` in App Studio.'
                : 'The user has already selected a specific element in the live preview.',
            'Treat the latest request as referring to the selected Studio target.',
        ];

        if ($targetStartLine !== null && $targetEndLine !== null) {
            $lines[] = sprintf('Selected lines: %d-%d.', $targetStartLine, $targetEndLine);
        }

        if ($targetSelectedText !== '') {
            $lines[] = "Selected text snapshot:\n" . $this->truncateBlock($targetSelectedText, 1000);
        }

        if (trim((string)($domSelection['selector'] ?? '')) !== '') {
            $lines[] = 'Selected preview element selector: ' . (string)$domSelection['selector'];
            if (trim((string)($domSelection['label'] ?? '')) !== '') {
                $lines[] = 'Selected preview element label: ' . (string)$domSelection['label'];
            }
            if (trim((string)($domSelection['text'] ?? '')) !== '') {
                $lines[] = "Selected preview element text:\n" . $this->truncateBlock((string)$domSelection['text'], 600);
            }
        }

        $lines[] = 'Do not ask the user to restate the selected Studio target unless it is missing or unusable.';
        $lines[] = '[/Studio target]';
        $lines[] = '';
        $lines[] = $content;

        return implode("\n", $lines);
    }

    /**
     * Turn a target file or line range into a compact context block for the model.
     */
    private function buildTargetContext(string $workspaceId, string $targetPath, ?int $startLine, ?int $endLine, string $selectedText): array {
        if ($targetPath === '') {
            return [
                'path' => '',
                'summary' => '',
                'details' => '',
            ];
        }

        $file = $this->workspaceManager->getFileContent($workspaceId, $targetPath);
        if ($file === null) {
            return [
                'path' => $targetPath,
                'summary' => $targetPath . ' (not found in workspace)',
                'details' => "Target file: {$targetPath}\nStatus: The requested target file was not found in the workspace file list.",
            ];
        }

        if (!empty($file['isBinary'])) {
            return [
                'path' => $targetPath,
                'summary' => $targetPath . ' (binary file)',
                'details' => "Target file: {$targetPath}\nStatus: The requested target is a binary file, so use adjacent source files for implementation context.",
            ];
        }

        $details = ["Target file: {$targetPath}"];
        $summary = $targetPath;
        $content = (string)($file['content'] ?? '');
        if ($startLine !== null && $endLine !== null) {
            $summary .= sprintf(' lines %d-%d', $startLine, $endLine);
            $details[] = sprintf('Target line range: %d-%d', $startLine, $endLine);
            $details[] = "Target file excerpt:\n" . $this->formatLineExcerpt($content, $startLine, $endLine);
        } else {
            $details[] = 'Target scope: whole file';
            $details[] = "Target file contents excerpt:\n" . $this->truncateBlock($content, 8000);
        }

        if ($selectedText !== '') {
            $details[] = "Editor-selected text snapshot:\n" . $this->truncateBlock($selectedText, 4000);
        }

        $details[] = 'Instruction: Prefer editing this target before creating new files.';

        return [
            'path' => $targetPath,
            'summary' => $summary,
            'details' => implode("\n\n", $details),
        ];
    }

    /**
     * Turn a selected live-preview DOM node into compact model context.
     */
    private function buildDomSelectionContext(array $selection): array {
        $selector = trim((string)($selection['selector'] ?? ''));
        if ($selector === '') {
            return [
                'summary' => '',
                'details' => '',
            ];
        }

        $tagName = trim((string)($selection['tagName'] ?? ''));
        $label = trim((string)($selection['label'] ?? ''));
        $text = trim((string)($selection['text'] ?? ''));
        $pagePath = trim((string)($selection['pagePath'] ?? ''));
        $role = trim((string)($selection['role'] ?? ''));
        $html = trim((string)($selection['html'] ?? ''));

        $summary = trim(implode(' ', array_filter([
            $tagName !== '' ? $tagName : 'element',
            $label !== '' ? '"' . $label . '"' : '',
            $pagePath !== '' ? 'on ' . $pagePath : '',
        ])));

        $details = [
            'The user clicked a specific element in the live preview.',
            'Selector: ' . $selector,
        ];

        if ($pagePath !== '') {
            $details[] = 'Preview page: ' . $pagePath;
        }
        if ($role !== '') {
            $details[] = 'ARIA role: ' . $role;
        }
        if ($label !== '') {
            $details[] = "Element label:\n" . $label;
        }
        if ($text !== '') {
            $details[] = "Visible text snapshot:\n" . $text;
        }
        if ($html !== '') {
            $details[] = "Outer HTML snapshot:\n" . $html;
        }

        $details[] = 'Instruction: Treat this selected preview element as the user\'s visual target for the requested UI change.';

        return [
            'summary' => $summary !== '' ? $summary : $selector,
            'details' => implode("\n\n", $details),
        ];
    }

    /**
     * Detect the common weak-model failure where Studio already provided a
     * target but the model still asks the user to clarify file/text selection.
     */
    private function shouldRetryTargetClarification(array $parsed, array $target): bool {
        $targetPath = trim((string)($target['path'] ?? ''));
        $domSelection = is_array($target['domSelection'] ?? null) ? $target['domSelection'] : [];
        if ($targetPath === '' && trim((string)($domSelection['selector'] ?? '')) === '') {
            return false;
        }

        if (!empty($parsed['operations'])) {
            return false;
        }

        $reply = strtolower(trim((string)($parsed['reply'] ?? '')));
        if ($reply === '') {
            return false;
        }

        $patterns = [
            'which file',
            'specify which file',
            'provide the specific',
            'what exactly needs to be removed',
            'please specify',
            'please provide',
        ];

        foreach ($patterns as $pattern) {
            if (str_contains($reply, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Add one explicit correction turn before giving up on target-aware edits.
     */
    private function buildTargetClarificationRetryPrompt(array $target): string {
        $targetPath = trim((string)($target['path'] ?? ''));
        $targetStartLine = isset($target['startLine']) ? (int)$target['startLine'] : null;
        $targetEndLine = isset($target['endLine']) ? (int)$target['endLine'] : null;
        $targetSelectedText = trim((string)($target['selectedText'] ?? ''));
        $domSelection = is_array($target['domSelection'] ?? null) ? $target['domSelection'] : [];

        $lines = [
            '[Studio correction]',
            'A valid App Studio target is already selected.',
            'Do not ask the user which file to edit or to restate the target text.',
            'Inspect the selected Studio context already provided and make the best reasonable edit directly.',
        ];

        if ($targetPath !== '') {
            $lines[] = 'Selected target file: `' . $targetPath . '`.';
        }

        if ($targetStartLine !== null && $targetEndLine !== null) {
            $lines[] = sprintf('Selected lines: %d-%d.', $targetStartLine, $targetEndLine);
        }

        if ($targetSelectedText !== '') {
            $lines[] = "Selected text snapshot:\n" . $this->truncateBlock($targetSelectedText, 1000);
        }

        if (trim((string)($domSelection['selector'] ?? '')) !== '') {
            $lines[] = 'Selected preview element selector: ' . (string)$domSelection['selector'];
            if (trim((string)($domSelection['label'] ?? '')) !== '') {
                $lines[] = 'Selected preview element label: ' . (string)$domSelection['label'];
            }
            if (trim((string)($domSelection['text'] ?? '')) !== '') {
                $lines[] = "Selected preview element text:\n" . $this->truncateBlock((string)$domSelection['text'], 600);
            }
        }

        $lines[] = 'If multiple plausible text matches exist, pick the most likely UI-visible one and mention that assumption in `notes`.';
        $lines[] = 'Return a normal JSON edit plan now.';
        $lines[] = '[/Studio correction]';

        return implode("\n", $lines);
    }

    /**
     * Normalize positive line numbers from Studio UI input.
     */
    private function normalizeLineNumber(mixed $value): ?int {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $value = trim($value);
        }

        if (!is_numeric($value)) {
            return null;
        }

        $line = (int)$value;
        return $line > 0 ? $line : null;
    }

    /**
     * Format a numbered file excerpt for an explicit line range.
     */
    private function formatLineExcerpt(string $content, int $startLine, int $endLine): string {
        $lines = preg_split('/\R/', $content) ?: [''];
        $lineCount = count($lines);
        $startLine = max(1, min($startLine, $lineCount));
        $endLine = max($startLine, min($endLine, $lineCount));
        $slice = array_slice($lines, $startLine - 1, $endLine - $startLine + 1);

        $formatted = [];
        foreach ($slice as $index => $line) {
            $formatted[] = sprintf('%d: %s', $startLine + $index, $line);
        }

        return $this->truncateBlock(implode("\n", $formatted), 12000);
    }

    /**
     * Limit a context block so it stays prompt-friendly.
     */
    private function truncateBlock(string $content, int $maxBytes): string {
        if (strlen($content) <= $maxBytes) {
            return $content;
        }

        return substr($content, 0, max(0, $maxBytes)) . "\n... [truncated]";
    }

    /**
     * Keep only the recent text messages needed for a provider request.
     */
    private function sanitizeConversation(array $messages): array {
        $conversation = [];

        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }

            $role = trim((string)($message['role'] ?? ''));
            $content = trim((string)($message['content'] ?? ''));
            if ($content === '' || !in_array($role, ['user', 'assistant'], true)) {
                continue;
            }

            $conversation[] = [
                'role' => $role,
                'content' => $content,
            ];
        }

        if (count($conversation) > self::MAX_MESSAGES) {
            $conversation = array_slice($conversation, -self::MAX_MESSAGES);
        }

        return $conversation;
    }

    /**
     * Return the most recent user-authored message content.
     */
    private function findLatestUserMessage(array $conversation): string {
        for ($index = count($conversation) - 1; $index >= 0; $index--) {
            if (($conversation[$index]['role'] ?? '') === 'user') {
                return trim((string)($conversation[$index]['content'] ?? ''));
            }
        }

        return '';
    }

    /**
     * Pick a small, useful file set for context.
     */
    private function pickContextFiles(array $workspace, array $flatFiles, string $currentFilePath, string $targetFilePath = ''): array {
        $candidates = [];
        if ($targetFilePath !== '') {
            $candidates[] = $targetFilePath;
        }
        if ($currentFilePath !== '') {
            $candidates[] = $currentFilePath;
        }

        $appPath = trim((string)($workspace['app_path'] ?? ''));
        if ($appPath !== '') {
            $prefix = $appPath === '.' ? '' : rtrim($appPath, '/') . '/';
            $candidates[] = $prefix . 'manifest.yaml';
            $candidates[] = $prefix . 'index.php';
        }

        $candidates[] = 'doki.project.yaml';
        $candidates[] = 'README.md';

        foreach ($flatFiles as $file) {
            $path = $file['path'] ?? '';
            $ext = strtolower((string)($file['extension'] ?? ''));
            if (in_array($ext, ['php', 'yaml', 'yml', 'json', 'md', 'css', 'js'], true)) {
                $candidates[] = $path;
            }
            if (count($candidates) >= self::MAX_CONTEXT_FILES * 3) {
                break;
            }
        }

        $flatPathMap = [];
        foreach ($flatFiles as $file) {
            if (!empty($file['isDirectory'])) {
                continue;
            }
            $flatPathMap[$file['path']] = true;
        }

        $selected = [];
        foreach ($candidates as $path) {
            if (!is_string($path) || $path === '' || isset($selected[$path])) {
                continue;
            }
            if (!isset($flatPathMap[$path])) {
                continue;
            }

            $selected[$path] = true;
            if (count($selected) >= self::MAX_CONTEXT_FILES) {
                break;
            }
        }

        return array_keys($selected);
    }

    /**
     * Flatten the nested tree returned by WorkspaceManager::listFiles().
     */
    private function flattenTree(array $items): array {
        $flat = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            if (!empty($item['isDirectory']) || ($item['type'] ?? null) === 'directory') {
                $children = is_array($item['children'] ?? null) ? $item['children'] : [];
                $flat = array_merge($flat, $this->flattenTree($children));
                continue;
            }

            $flat[] = $item;
        }

        return $flat;
    }

    /**
     * Parse the model response into the structured shape expected by the UI.
     */
    private function parseStructuredResponse(string $content): array {
        $payload = $this->extractJsonPayload($content);
        if (!is_array($payload)) {
            $markdownFallback = $this->extractMarkdownFileOperations($content);
            if ($markdownFallback !== null) {
                return $markdownFallback;
            }

            return [
                'reply' => trim($content) !== '' ? trim($content) : 'I could not produce a structured change plan.',
                'summary' => 'No file changes proposed',
                'operations' => [],
                'notes' => ['The model did not return valid JSON, so no file changes can be applied automatically.'],
            ];
        }

        $operations = $this->normalizeOperations($payload['operations'] ?? []);
        $reply = trim((string)($payload['reply'] ?? $payload['message'] ?? ''));
        $summary = trim((string)($payload['summary'] ?? ''));
        $notes = [];
        if (is_array($payload['notes'] ?? null)) {
            foreach ($payload['notes'] as $note) {
                $note = trim((string)$note);
                if ($note !== '') {
                    $notes[] = $note;
                }
            }
        }

        if ($reply === '') {
            $reply = $operations === []
                ? 'I do not have any file changes to apply yet.'
                : 'I drafted a set of workspace changes for review.';
        }
        if ($summary === '') {
            $summary = $operations === []
                ? 'No file changes proposed'
                : sprintf('Proposed %d file change%s', count($operations), count($operations) === 1 ? '' : 's');
        }

        return [
            'reply' => $reply,
            'summary' => $summary,
            'operations' => $operations,
            'notes' => $notes,
        ];
    }

    /**
     * Recover write operations from markdown file blocks when the model ignores the JSON contract.
     */
    private function extractMarkdownFileOperations(string $content): ?array {
        $trimmed = trim($content);
        if ($trimmed === '') {
            return null;
        }

        $lines = preg_split('/\R/', $trimmed) ?: [];
        $operations = [];
        $firstFileLineIndex = null;
        $index = 0;
        $lineCount = count($lines);

        while ($index < $lineCount) {
            $path = $this->extractMarkdownFilePath((string)$lines[$index]);
            if ($path === null) {
                $index++;
                continue;
            }

            $normalizedPath = $this->normalizeRelativePath($path);
            if ($normalizedPath === null) {
                $index++;
                continue;
            }

            $cursor = $index + 1;
            while ($cursor < $lineCount && trim((string)$lines[$cursor]) === '') {
                $cursor++;
            }

            if ($cursor >= $lineCount || !preg_match('/^\s*```/', (string)$lines[$cursor])) {
                $index++;
                continue;
            }

            $blockLines = [];
            $cursor++;
            while ($cursor < $lineCount && !preg_match('/^\s*```/', (string)$lines[$cursor])) {
                $blockLines[] = (string)$lines[$cursor];
                $cursor++;
            }

            if ($cursor >= $lineCount) {
                break;
            }

            if ($firstFileLineIndex === null) {
                $firstFileLineIndex = $index;
            }

            $operations[] = [
                'type' => 'write',
                'path' => $normalizedPath,
                'content' => implode("\n", $blockLines),
                'reason' => 'Recovered from markdown file block',
            ];

            if (count($operations) >= self::MAX_OPERATIONS) {
                break;
            }

            $index = $cursor + 1;
        }

        $normalizedOperations = $this->normalizeOperations($operations);
        if ($normalizedOperations === []) {
            return null;
        }

        $leadingReplyLines = $firstFileLineIndex === null
            ? []
            : array_slice($lines, 0, $firstFileLineIndex);
        $reply = trim(implode("\n", $leadingReplyLines));
        if ($reply === '') {
            $reply = 'I drafted a set of workspace changes for review.';
        }

        return [
            'reply' => $reply,
            'summary' => sprintf(
                'Recovered %d file change%s from a non-JSON AI response',
                count($normalizedOperations),
                count($normalizedOperations) === 1 ? '' : 's'
            ),
            'operations' => $normalizedOperations,
            'notes' => [
                'The model did not return the required JSON shape, so Studio recovered file changes from markdown code blocks.',
            ],
        ];
    }

    /**
     * Extract a repo-relative path from a markdown file heading line.
     */
    private function extractMarkdownFilePath(string $line): ?string {
        $trimmed = trim($line);
        if ($trimmed === '') {
            return null;
        }

        $patterns = [
            '/^\*\*([^*]+)\*\*\s*$/',
            '/^`([^`]+)`\s*$/',
            '/^(?:File|Path)\s*:\s*`?([^`]+?)`?\s*$/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $trimmed, $matches) === 1) {
                $candidate = trim((string)$matches[1]);
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * Normalize and validate operation payloads.
     */
    private function normalizeOperations(array $operations): array {
        $normalized = [];

        foreach ($operations as $operation) {
            if (!is_array($operation)) {
                continue;
            }

            $type = strtolower(trim((string)($operation['type'] ?? '')));
            if ($type === 'create' || $type === 'update') {
                $type = 'write';
            } elseif ($type === 'remove') {
                $type = 'delete';
            }
            if (!in_array($type, ['write', 'delete'], true)) {
                continue;
            }

            $path = $this->normalizeRelativePath((string)($operation['path'] ?? ''));
            if ($path === null) {
                continue;
            }

            $normalizedOperation = [
                'type' => $type,
                'path' => $path,
            ];

            if ($type === 'write') {
                if (!array_key_exists('content', $operation) || !is_string($operation['content'])) {
                    continue;
                }
                $normalizedOperation['content'] = $operation['content'];
            }

            $reason = trim((string)($operation['reason'] ?? ''));
            if ($reason !== '') {
                $normalizedOperation['reason'] = $reason;
            }

            $normalized[] = $normalizedOperation;
            if (count($normalized) >= self::MAX_OPERATIONS) {
                break;
            }
        }

        return $normalized;
    }

    /**
     * Build inverse operations for a batch so the UI can offer one-click revert.
     */
    private function buildRevertOperations(string $workspaceId, array $operations): array {
        $pathState = [];
        $inverse = [];

        foreach ($operations as $operation) {
            if (!is_array($operation)) {
                continue;
            }

            $path = trim((string)($operation['path'] ?? ''));
            if ($path === '') {
                continue;
            }

            if (!array_key_exists($path, $pathState)) {
                $file = $this->workspaceManager->getFileContent($workspaceId, $path);
                $pathState[$path] = [
                    'exists' => $file !== null,
                    'isBinary' => !empty($file['isBinary']),
                    'content' => ($file !== null && empty($file['isBinary'])) ? (string)($file['content'] ?? '') : null,
                ];
            }

            $previousState = $pathState[$path];
            $undo = $this->buildInverseOperation($operation, $previousState);
            if ($undo !== null) {
                $inverse[] = $undo;
            }

            if (($operation['type'] ?? '') === 'write') {
                $pathState[$path] = [
                    'exists' => true,
                    'isBinary' => false,
                    'content' => (string)($operation['content'] ?? ''),
                ];
            } else {
                $pathState[$path] = [
                    'exists' => false,
                    'isBinary' => false,
                    'content' => null,
                ];
            }
        }

        return array_reverse($inverse);
    }

    /**
     * Build one inverse operation from the pre-change state of a path.
     */
    private function buildInverseOperation(array $operation, array $previousState): ?array {
        $type = trim((string)($operation['type'] ?? ''));
        $path = trim((string)($operation['path'] ?? ''));
        if ($path === '' || !in_array($type, ['write', 'delete'], true)) {
            return null;
        }

        if ($type === 'write') {
            if (!empty($previousState['exists']) && empty($previousState['isBinary']) && is_string($previousState['content'])) {
                return [
                    'type' => 'write',
                    'path' => $path,
                    'content' => $previousState['content'],
                    'reason' => 'Revert AI-applied change',
                ];
            }

            if (empty($previousState['exists'])) {
                return [
                    'type' => 'delete',
                    'path' => $path,
                    'reason' => 'Revert AI-created file',
                ];
            }

            return null;
        }

        if (!empty($previousState['exists']) && empty($previousState['isBinary']) && is_string($previousState['content'])) {
            return [
                'type' => 'write',
                'path' => $path,
                'content' => $previousState['content'],
                'reason' => 'Restore file removed by AI change',
            ];
        }

        return null;
    }

    /**
     * Lint AI-generated operations for common Doki anti-patterns.
     */
    private function lintOperations(array $operations, ?array $context = null): array {
        $warnings = [];
        $seen = [];

        if ($context !== null) {
            $this->lintTargetOperationRisk($warnings, $seen, $operations, $context);
        }

        foreach ($operations as $operation) {
            if (!is_array($operation) || ($operation['type'] ?? '') !== 'write') {
                continue;
            }

            $path = trim((string)($operation['path'] ?? ''));
            $content = (string)($operation['content'] ?? '');
            if ($path === '' || $content === '') {
                continue;
            }

            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (!in_array($extension, ['php', 'inc', 'phtml'], true)) {
                continue;
            }

            $this->lintFileContent($warnings, $seen, $path, $content);
        }

        return $warnings;
    }

    /**
     * Flag obviously destructive plans when the user asked for a narrow edit to
     * a specific target file.
     */
    private function lintTargetOperationRisk(array &$warnings, array &$seen, array $operations, array $context): void {
        $workspaceId = trim((string)($context['workspaceId'] ?? ''));
        $target = is_array($context['target'] ?? null) ? $context['target'] : [];
        $targetPath = trim((string)($target['path'] ?? ''));
        $latestUserMessage = trim((string)($context['latestUserMessage'] ?? ''));

        if ($workspaceId === '' || $targetPath === '' || $latestUserMessage === '') {
            return;
        }

        $isNarrowTargetEdit = $this->isNarrowTargetEditRequest($latestUserMessage);
        if (!$isNarrowTargetEdit) {
            return;
        }

        $targetOperation = null;
        foreach ($operations as $operation) {
            if (!is_array($operation)) {
                continue;
            }
            if (trim((string)($operation['path'] ?? '')) !== $targetPath) {
                continue;
            }
            $targetOperation = $operation;
            break;
        }

        if ($targetOperation === null) {
            return;
        }

        $existingFile = $this->workspaceManager->getFileContent($workspaceId, $targetPath);
        if ($existingFile === null || !empty($existingFile['isBinary'])) {
            return;
        }

        $originalContent = (string)($existingFile['content'] ?? '');
        $originalTrimmedLength = strlen(trim($originalContent));
        if ($originalTrimmedLength === 0) {
            return;
        }

        $type = trim((string)($targetOperation['type'] ?? ''));
        if ($type === 'delete') {
            $this->pushLintWarning(
                $warnings,
                $seen,
                [
                    'code' => 'destructive_target_delete',
                    'severity' => 'danger',
                    'title' => 'Blocked destructive target delete',
                    'message' => 'The AI tried to delete the selected target file even though your request looked like a narrow edit. Review the diff or ask again with a stronger model.',
                    'path' => $targetPath,
                    'line' => 1,
                ]
            );
            return;
        }

        $newContent = (string)($targetOperation['content'] ?? '');
        $newTrimmedLength = strlen(trim($newContent));

        if ($newTrimmedLength === 0) {
            $this->pushLintWarning(
                $warnings,
                $seen,
                [
                    'code' => 'destructive_target_empty_write',
                    'severity' => 'danger',
                    'title' => 'Blocked empty target overwrite',
                    'message' => 'The AI replaced the selected target file with empty content for a narrow edit request. Studio will not apply this plan.',
                    'path' => $targetPath,
                    'line' => 1,
                ]
            );
            return;
        }

        $minimumSafeLength = max(80, (int)floor($originalTrimmedLength * 0.12));
        if ($originalTrimmedLength >= 300 && $newTrimmedLength < $minimumSafeLength) {
            $this->pushLintWarning(
                $warnings,
                $seen,
                [
                    'code' => 'destructive_target_truncation',
                    'severity' => 'danger',
                    'title' => 'Blocked destructive target truncation',
                    'message' => 'The AI shrank the selected target file far more than expected for a narrow edit request. Studio will not apply this plan without a safer retry.',
                    'path' => $targetPath,
                    'line' => 1,
                ]
            );
        }
    }

    /**
     * Identify prompts that sound like scoped edits rather than full rewrites.
     */
    private function isNarrowTargetEditRequest(string $message): bool {
        $message = strtolower(trim($message));
        if ($message === '') {
            return false;
        }

        $broadPatterns = [
            'rewrite the file',
            'rewrite this file',
            'replace the file',
            'from scratch',
            'start over',
            'clear the file',
            'empty the file',
            'delete the file',
            'remove all content',
            'blank file',
        ];
        foreach ($broadPatterns as $pattern) {
            if (str_contains($message, $pattern)) {
                return false;
            }
        }

        $narrowPatterns = [
            'remove ',
            'edit ',
            'change ',
            'update ',
            'fix ',
            'improve ',
            'tweak ',
            'adjust ',
            'replace ',
            'trusted text',
            'text from',
            'copy from',
        ];
        foreach ($narrowPatterns as $pattern) {
            if (str_contains($message, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Apply per-file lint rules to generated PHP.
     */
    private function lintFileContent(array &$warnings, array &$seen, string $path, string $content): void {
        $hasSettingsJson = stripos($content, 'settings.json') !== false;
        $hasStoragePath = preg_match('/getStoragePath\s*\(/i', $content) === 1;
        $hasRawFilesystemCall = preg_match('/(?:file_get_contents|file_put_contents|fopen|fwrite|file_exists|is_file|unlink|mkdir|copy|rename|scandir|glob)\s*\(/i', $content, $matches, PREG_OFFSET_CAPTURE) === 1;
        $hasHardcodedInternalPath = preg_match('/(?:preview-data|studio-previews|data\/workspaces)/i', $content, $internalPathMatch, PREG_OFFSET_CAPTURE) === 1;

        if ($hasSettingsJson && ($hasStoragePath || $hasRawFilesystemCall)) {
            $line = $this->findFirstLine($content, 'settings.json');
            $this->pushLintWarning(
                $warnings,
                $seen,
                [
                    'code' => 'raw_settings_file_access',
                    'severity' => 'warning',
                    'title' => 'Use AppContext settings helpers',
                    'message' => 'This file appears to read or write settings.json directly. Use $app->getSettings() and $app->saveSettings($settings) so preview mode and first-run empty state are handled safely.',
                    'path' => $path,
                    'line' => $line,
                ]
            );
        }

        if ($hasStoragePath && $hasRawFilesystemCall) {
            $line = $this->offsetToLine($content, (int)($matches[0][1] ?? 0));
            $this->pushLintWarning(
                $warnings,
                $seen,
                [
                    'code' => 'raw_storage_file_io',
                    'severity' => 'warning',
                    'title' => 'Prefer AppContext storage helpers',
                    'message' => 'This file uses raw filesystem calls together with $app->getStoragePath(). Prefer $app->readFile(), $app->writeFile(), $app->deleteFile(), and related helpers so folders, preview storage, and missing files are handled consistently.',
                    'path' => $path,
                    'line' => $line,
                ]
            );
        }

        if ($hasHardcodedInternalPath) {
            $line = $this->offsetToLine($content, (int)($internalPathMatch[0][1] ?? 0));
            $this->pushLintWarning(
                $warnings,
                $seen,
                [
                    'code' => 'hardcoded_internal_storage_path',
                    'severity' => 'warning',
                    'title' => 'Avoid hardcoded Studio storage paths',
                    'message' => 'This file references Studio preview or workspace storage directories directly. Use AppContext helpers instead of hardcoded paths like preview-data, studio-previews, or data/workspaces.',
                    'path' => $path,
                    'line' => $line,
                ]
            );
        }
    }

    /**
     * Add one lint warning if it has not already been reported.
     */
    private function pushLintWarning(array &$warnings, array &$seen, array $warning): void {
        $key = implode(':', [
            (string)($warning['code'] ?? 'warning'),
            (string)($warning['path'] ?? ''),
            (string)($warning['line'] ?? 0),
        ]);
        if (isset($seen[$key])) {
            return;
        }

        $seen[$key] = true;
        $warnings[] = $warning;
    }

    /**
     * Find the first line number for a literal needle in file content.
     */
    private function findFirstLine(string $content, string $needle): int {
        $offset = stripos($content, $needle);
        return $this->offsetToLine($content, $offset === false ? 0 : (int)$offset);
    }

    /**
     * Convert a byte offset into a 1-based line number.
     */
    private function offsetToLine(string $content, int $offset): int {
        $offset = max(0, min($offset, strlen($content)));
        return substr_count(substr($content, 0, $offset), "\n") + 1;
    }

    /**
     * Extract the first JSON object from a model response.
     */
    private function extractJsonPayload(string $content): ?array {
        $trimmed = trim($content);
        if ($trimmed === '') {
            return null;
        }

        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/is', $trimmed, $matches) === 1) {
            $decoded = json_decode($matches[1], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $start = strpos($trimmed, '{');
        $end = strrpos($trimmed, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $decoded = json_decode(substr($trimmed, $start, $end - $start + 1), true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Normalize repo-relative file paths.
     */
    private function normalizeRelativePath(string $path): ?string {
        $path = trim(str_replace('\\', '/', $path), '/');
        if ($path === '' || str_contains($path, '../')) {
            return null;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        return $path;
    }

    /**
     * Derive a starter workspace seed before the AI rewrites the scaffold.
     */
    private function deriveWorkspaceSeed(string $prompt): array {
        $candidate = '';

        if (preg_match('/\b(?:called|named)\s+["\']?([A-Za-z0-9][A-Za-z0-9 _-]{1,48})/i', $prompt, $matches) === 1) {
            $candidate = trim((string)$matches[1]);
        }

        if ($candidate === '') {
            $tokens = preg_split('/[^a-z0-9]+/i', strtolower($prompt)) ?: [];
            $ignore = [
                'make', 'build', 'create', 'generate', 'new', 'doki', 'app', 'that', 'which',
                'with', 'for', 'and', 'the', 'a', 'an', 'to', 'lets', 'let', 'me', 'tool',
            ];
            $words = [];

            foreach ($tokens as $token) {
                if ($token === '' || in_array($token, $ignore, true)) {
                    continue;
                }

                $words[] = $token;
                if (count($words) >= 4) {
                    break;
                }
            }

            $candidate = $words !== []
                ? implode(' ', array_map(fn(string $word): string => ucfirst($word), $words))
                : 'AI App';
        }

        $candidate = preg_replace('/\s+/', ' ', $candidate) ?? 'AI App';
        $candidate = trim($candidate, " \t\n\r\0\x0B'\"-");
        if ($candidate === '') {
            $candidate = 'AI App';
        }

        $appId = strtolower($candidate);
        $appId = preg_replace('/[^a-z0-9]+/', '-', $appId) ?? 'ai-app';
        $appId = trim($appId, '-');
        if ($appId === '') {
            $appId = 'ai-app';
        }

        return [
            'name' => $candidate,
            'appId' => $appId,
        ];
    }
}
