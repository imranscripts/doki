<?php
/**
 * WorkflowAIManager.php - AI-assisted draft creation for Workflows Studio
 */

require_once __DIR__ . '/AIAdminManager.php';
require_once __DIR__ . '/AIProviderManager.php';
require_once __DIR__ . '/CommandsManager.php';
require_once __DIR__ . '/ConfigManager.php';
require_once __DIR__ . '/TemplateManager.php';
require_once __DIR__ . '/TemplateStudioManager.php';
require_once __DIR__ . '/WorkflowStudioManager.php';

class WorkflowAIManager {
    private const TYPE_WORKFLOW = 'workflow';
    private const TYPE_TEMPLATE = 'template';
    private const MAX_TEMPLATE_REFERENCES = 18;
    private const MAX_TARGET_REFERENCES = 16;
    private const MAX_ENV_REFERENCES = 12;
    private const MAX_CONTEXT_BYTES = 12000;

    private AIAdminManager $aiAdminManager;
    private AIProviderManager $providerManager;
    private CommandsManager $commandsManager;
    private ConfigManager $configManager;
    private TemplateManager $templateManager;
    private WorkflowStudioManager $workflowStudioManager;
    private TemplateStudioManager $templateStudioManager;

    public function __construct() {
        $this->aiAdminManager = new AIAdminManager();
        $this->providerManager = new AIProviderManager();
        $this->commandsManager = new CommandsManager();
        $this->configManager = new ConfigManager();
        $this->templateManager = new TemplateManager();
        $this->workflowStudioManager = new WorkflowStudioManager();
        $this->templateStudioManager = new TemplateStudioManager();
    }

    public function getProviders(): array {
        return $this->providerManager->getProviders();
    }

    public function generateDraft(string $type, string $prompt, string $providerId = '', array $options = []): array {
        $type = $this->normalizeType($type);
        $prompt = trim($prompt);
        if ($prompt === '') {
            return ['success' => false, 'error' => 'A prompt is required'];
        }

        $provider = $this->resolveProvider($providerId);
        if (!$provider['success']) {
            return $provider;
        }
        $resolvedProvider = $provider['provider'];

        $currentDraft = is_array($options['currentDraft'] ?? null) ? $options['currentDraft'] : [];
        $model = $this->resolveModel($resolvedProvider, trim((string)($options['model'] ?? '')));
        if (!$model['success']) {
            return $model;
        }

        $messages = [
            ['role' => 'system', 'content' => $this->buildSystemPrompt($type)],
            ['role' => 'user', 'content' => $this->buildUserPrompt($type, $prompt, $currentDraft)],
        ];

        $chatResult = $this->providerManager->sendChat($resolvedProvider, $model['model'], $messages);
        if (empty($chatResult['success'])) {
            return $chatResult;
        }

        $parsed = $this->parseDraftResponse($type, (string)($chatResult['content'] ?? ''));
        if (empty($parsed['draft'])) {
            return [
                'success' => false,
                'error' => 'The AI response did not include a structured draft',
                'reply' => $parsed['reply'] ?? '',
                'notes' => $parsed['notes'] ?? [],
            ];
        }

        $baseDraft = $this->getBaseDraft($type);
        $draft = $this->mergeDrafts($baseDraft, $parsed['draft']);
        $validation = $this->validateDraft($type, $draft);
        $notes = $this->appendValidationNotes(
            is_array($parsed['notes'] ?? null) ? $parsed['notes'] : [],
            is_array($validation['validation'] ?? null) ? $validation['validation'] : []
        );

        return [
            'success' => true,
            'type' => $type,
            'reply' => $parsed['reply'],
            'summary' => $parsed['summary'],
            'notes' => $notes,
            'draft' => $validation['draft'],
            'validation' => $validation['validation'],
            'model' => $model['model'],
            'provider' => [
                'id' => $resolvedProvider['id'],
                'name' => $resolvedProvider['name'],
                'type' => $resolvedProvider['type'],
            ],
            'usage' => $chatResult['usage'] ?? [],
        ];
    }

    private function normalizeType(string $type): string {
        $type = strtolower(trim($type));
        return $type === self::TYPE_TEMPLATE ? self::TYPE_TEMPLATE : self::TYPE_WORKFLOW;
    }

    private function resolveProvider(string $providerId): array {
        $providerId = trim($providerId);
        if ($providerId !== '') {
            $provider = $this->providerManager->getResolvedProvider($providerId);
            if ($provider === null) {
                return ['success' => false, 'error' => 'AI provider not found'];
            }
            return ['success' => true, 'provider' => $provider];
        }

        $providers = $this->providerManager->getProviders();
        if ($providers === []) {
            return ['success' => false, 'error' => 'No AI providers are configured yet'];
        }

        $default = $providers[0];
        $resolved = $this->providerManager->getResolvedProvider((string)($default['id'] ?? ''));
        if ($resolved === null) {
            return ['success' => false, 'error' => 'The default AI provider could not be loaded'];
        }

        return ['success' => true, 'provider' => $resolved];
    }

    private function resolveModel(array $provider, string $requestedModel): array {
        $requestedModel = trim($requestedModel);
        if ($requestedModel !== '') {
            return ['success' => true, 'model' => $requestedModel];
        }

        $defaultModel = trim((string)($provider['default_model'] ?? $provider['defaultModel'] ?? ''));
        if ($defaultModel !== '') {
            return ['success' => true, 'model' => $defaultModel];
        }

        $models = $this->providerManager->listModels((string)$provider['id']);
        if (empty($models['success'])) {
            return ['success' => false, 'error' => $models['error'] ?? 'No AI model is configured for this provider'];
        }

        $recommended = trim((string)($models['recommendedModel'] ?? ''));
        if ($recommended === '') {
            return ['success' => false, 'error' => 'No AI model is configured for this provider'];
        }

        return ['success' => true, 'model' => $recommended];
    }

    private function buildSystemPrompt(string $type): string {
        if ($type === self::TYPE_TEMPLATE) {
            return <<<PROMPT
You are the Workflows Studio AI inside Doki.

You help admins author reusable workflow templates.

Return JSON only. Do not wrap it in markdown.

Return an object with this exact top-level shape:
{
  "reply": "short natural-language explanation",
  "summary": "short summary of what you created",
  "draft": { ...template draft object... },
  "notes": ["optional note"]
}

The draft must be a full Doki template object, not a partial patch.
Use this shape:
{
  "id": "kebab-case-id",
  "name": "Human readable name",
  "version": "1.0.0",
  "description": "What the template does",
  "icon": "fas fa-cubes",
  "color": "#2563eb",
  "category": "custom",
  "targetType": null,
  "runtime": { "image": "alpine:latest" },
  "inputs": [],
  "execution": { "mode": "sync", "timeout": 60, "script": "..." },
  "output": { "type": "text" },
  "requirements": [],
  "targetBindings": []
}

Rules:
- runtime.image is required.
- execution.mode must be sync, async, or streaming.
- execution.script should be practical and safe.
- inputs must be an array of objects with stable machine names.
- Prefer a reusable template when the prompt implies the logic should be reused across many workflows.
- When refining an existing draft, return a full replacement template draft, not a partial patch.
- Remove fields, inputs, requirements, or runtime details that are unrelated to the new request instead of carrying them forward.
- Doki supports run-level dry runs that record a redacted command preview without executing, and workflow drafts can be dry-run in Workflows Studio before publish. Do not add a permanent dryRun field to the template draft unless the schema explicitly requires it.
- Do not publish anything. You are only creating a draft for review.
PROMPT;
        }

        return <<<PROMPT
You are the Workflows Studio AI inside Doki.

You help admins author runnable workflow drafts.

Return JSON only. Do not wrap it in markdown.

Return an object with this exact top-level shape:
{
  "reply": "short natural-language explanation",
  "summary": "short summary of what you created",
  "draft": { ...workflow draft object... },
  "notes": ["optional note"]
}

The draft must be a full Doki workflow object, not a partial patch.
Use this shape:
{
  "id": "kebab-case-id",
  "name": "Human readable name",
  "description": "What the workflow does",
  "executionMode": "sync",
  "maxParallel": 1,
  "finalStatusPolicy": "fail_if_any_failed",
  "defaultTarget": "inherit",
  "environment": null,
  "steps": [],
  "inputs": {},
  "inputContract": [],
  "secrets": {},
  "tags": [],
  "icon": null,
  "color": null,
  "enabled": true
}

Each step must include:
- id
- templateId
- target
- dependsOn
- onFailure
- inputs
- secrets

Rules:
- Use the available template IDs exactly as provided.
- Do not invent targets or environments that are not in the provided context.
- If the prompt is underspecified, prefer the simplest valid workflow using specialized templates first. Use `shell-exec` only when no better template fits or for a small final formatting step.
- Keep IDs short and readable.
- inputs is a runtime values object keyed by input name. Only put actual default values there.
- inputContract defines the input fields. Put field metadata there, not inside inputs.
- Do not put schema objects like {"type":"string","title":"Foo","default":""} inside inputs.
- `shell-exec` only guarantees `sh` in its runtime unless the script installs extra tools itself.
- Workflow steps run in separate executions. Do not assume files written in one step will exist in a later step.
- When refining an existing draft, return a full replacement workflow draft, not a partial patch.
- Remove unrelated steps, inputs, tags, or secrets from older drafts instead of carrying them forward.
- Doki supports run-level dry runs that record a redacted command preview without executing, and workflow drafts can be dry-run in Workflows Studio before publish. Do not add a permanent dryRun field to the workflow draft unless the schema explicitly requires it.
- Do not publish anything. You are only creating a draft for review.
PROMPT;
    }

    private function buildUserPrompt(string $type, string $prompt, array $currentDraft): string {
        $reference = $this->truncateBlock($this->aiAdminManager->buildCommandReferenceBlock(), self::MAX_CONTEXT_BYTES);
        $workflowAuthoring = $this->buildWorkflowAuthoringReference($type);
        $templates = $this->buildTemplateReference();
        $targets = $this->buildTargetReference();
        $environments = $this->buildEnvironmentReference();
        $existingWorkflows = $type === self::TYPE_WORKFLOW ? $this->buildPublishedWorkflowReference() : '';
        $dryRunCapability = $this->buildDryRunReference();
        $patterns = $type === self::TYPE_WORKFLOW ? $this->buildWorkflowPatternReference() : '';
        $mode = $currentDraft !== []
            ? 'Refine the current draft by returning a complete replacement draft'
            : 'Create a new draft';

        $lines = [
            $mode . ' based on this request:',
            $prompt,
            '',
            '[Workflows context settings]',
            $reference !== '' ? $reference : 'No custom workflows context blocks are configured.',
            '[/Workflows context settings]',
            '',
            '[Workflow authoring reference]',
            $workflowAuthoring,
            '[/Workflow authoring reference]',
            '',
            '[Available templates]',
            $templates,
            '[/Available templates]',
            '',
            '[Available targets]',
            $targets,
            '[/Available targets]',
            '',
            '[Available environments]',
            $environments,
            '[/Available environments]',
            '',
            '[Existing workflows]',
            $existingWorkflows !== '' ? $existingWorkflows : 'No published workflows are available yet.',
            '[/Existing workflows]',
            '',
            '[Dry-run capability]',
            $dryRunCapability,
            '[/Dry-run capability]',
        ];

        if ($patterns !== '') {
            $lines[] = '';
            $lines[] = '[Good workflow patterns]';
            $lines[] = $patterns;
            $lines[] = '[/Good workflow patterns]';
        }

        if ($currentDraft !== []) {
            $lines[] = '';
            $lines[] = '[Current draft]';
            $lines[] = json_encode($currentDraft, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
            $lines[] = '[/Current draft]';
            $lines[] = '';
            $lines[] = 'If any part of the current draft is unrelated to the new request, remove it in the replacement draft.';
        }

        $lines[] = '';
        $lines[] = $type === self::TYPE_TEMPLATE
            ? 'Return a reusable template draft that an admin can review and save in Workflows Studio.'
            : 'Return a runnable workflow draft that an admin can review and save in Workflows Studio.';

        return implode("\n", $lines);
    }

    private function buildTemplateReference(): string {
        $templates = $this->templateManager->getTemplates();
        if (empty($templates['success'])) {
            return 'No templates are available.';
        }

        $lines = [];
        foreach (array_slice($templates['templates'] ?? [], 0, self::MAX_TEMPLATE_REFERENCES) as $template) {
            $inputs = [];
            foreach (array_slice($template['inputs'] ?? [], 0, 5) as $input) {
                if (!is_array($input)) {
                    continue;
                }
                $type = trim((string)($input['type'] ?? 'string')) ?: 'string';
                $optionSummary = '';
                if (in_array($type, ['select', 'multiselect'], true)) {
                    $optionValues = [];
                    foreach (array_slice((array)($input['options'] ?? []), 0, 6) as $option) {
                        if (!is_array($option)) {
                            continue;
                        }
                        $optionValue = trim((string)($option['value'] ?? ''));
                        if ($optionValue !== '') {
                            $optionValues[] = $optionValue;
                        }
                    }
                    if ($optionValues !== []) {
                        $optionSummary = '[' . implode('|', $optionValues) . ']';
                    }
                }
                $inputs[] = sprintf(
                    '%s:%s%s',
                    trim((string)($input['name'] ?? 'input')),
                    $type . $optionSummary,
                    !empty($input['required']) ? ' (required)' : ''
                );
            }

            $templateId = trim((string)($template['id'] ?? 'unknown'));
            $requirements = array_map(
                static fn($item): string => trim((string)$item),
                is_array($template['requirements'] ?? null) ? $template['requirements'] : []
            );
            $outputType = trim((string)($template['output']['type'] ?? 'text')) ?: 'text';
            $extractors = [];
            foreach (array_slice((array)($template['output']['extractors'] ?? []), 0, 4) as $extractor) {
                if (!is_array($extractor)) {
                    continue;
                }
                $name = trim((string)($extractor['name'] ?? ''));
                if ($name !== '') {
                    $extractors[] = $name;
                }
            }

            $hint = '';
            if ($templateId === 'shell-exec') {
                $hint = ' | hint=last resort for custom shell; only sh is guaranteed';
            } elseif (in_array($templateId, ['http-request', 'http-api'], true)) {
                $hint = ' | hint=prefer this for external APIs and HTTP JSON calls; use extract.responseBody for the raw body';
            } elseif ($templateId === 'json-field') {
                $hint = ' | hint=prefer this to extract a dotted field from raw JSON text';
            } elseif ($templateId === 'json-count') {
                $hint = ' | hint=prefer this to count JSON array items without custom scripting';
            } elseif ($templateId === 'docker-management') {
                $hint = ' | hint=use action `ps-running` for running containers and `ps-all` for all containers; for workflow chaining prefer listFormat `names` or `jsonl`';
            }

            $lines[] = sprintf(
                '- %s: %s | targetType=%s | mode=%s | source=%s | output=%s | requirements=%s | extractors=%s | inputs=%s%s',
                $templateId,
                trim((string)($template['name'] ?? 'Unnamed template')),
                trim((string)($template['targetType'] ?? 'none')) ?: 'none',
                trim((string)($template['execution']['mode'] ?? 'sync')) ?: 'sync',
                trim((string)($template['source'] ?? 'builtin')) ?: 'builtin',
                $outputType,
                $requirements !== [] ? implode(', ', $requirements) : 'none',
                $extractors !== [] ? implode(', ', $extractors) : 'none',
                $inputs !== [] ? implode(', ', $inputs) : 'none',
                $hint
            );
        }

        return $lines !== [] ? implode("\n", $lines) : 'No templates are available.';
    }

    private function buildTargetReference(): string {
        $targets = $this->configManager->getTargets();
        if ($targets === []) {
            return 'No targets are configured.';
        }

        $lines = [];
        foreach (array_slice($targets, 0, self::MAX_TARGET_REFERENCES) as $target) {
            if (!is_array($target)) {
                continue;
            }
            $lines[] = sprintf(
                '- %s: %s | type=%s',
                trim((string)($target['id'] ?? 'unknown')),
                trim((string)($target['name'] ?? 'Unnamed target')),
                trim((string)($target['type'] ?? 'unknown'))
            );
        }

        return $lines !== [] ? implode("\n", $lines) : 'No targets are configured.';
    }

    private function buildEnvironmentReference(): string {
        $environments = $this->configManager->getEnvironments();
        if (!is_array($environments) || $environments === []) {
            return 'No environments are configured.';
        }

        $lines = [];
        foreach (array_slice(array_keys($environments), 0, self::MAX_ENV_REFERENCES) as $environmentId) {
            $environment = is_array($environments[$environmentId] ?? null) ? $environments[$environmentId] : [];
            $targets = array_keys((array)($environment['targets'] ?? []));
            $lines[] = sprintf(
                '- %s%s',
                trim((string)$environmentId),
                $targets !== [] ? ' | targets=' . implode(', ', $targets) : ''
            );
        }

        return $lines !== [] ? implode("\n", $lines) : 'No environments are configured.';
    }

    private function buildDryRunReference(): string {
        return implode("\n", [
            '- Doki supports run-level dry runs for workflows and templates.',
            '- Workflow drafts can be dry-run in Workflows Studio before publish.',
            '- Dry runs validate the workflow shape, resolve targets, interpolate scripts, and record the redacted command that would run.',
            '- Dry runs do not execute the command.',
            '- Secret values are censored in dry-run logs and previews.',
            '- Dry run is a property of the execution request, not a permanent workflow field.',
        ]);
    }

    private function buildWorkflowAuthoringReference(string $type): string {
        $lines = [
            '- Prefer existing templates over large custom shell scripts whenever a known template already fits the task.',
            '- For external HTTP APIs, webhooks, and JSON endpoints, prefer `http-request` or `http-api` over `shell-exec`.',
            '- If a template offers a machine-friendly output format like newline-separated names or JSON lines, prefer that over parsing a visual table in shell.',
            '- After an HTTP step, prefer `steps.<id>.extract.responseBody` for the raw response body and `steps.<id>.extract.statusCode` for the HTTP status.',
            '- To pull a field like `homeworld` or `name` out of raw JSON text, prefer template `json-field` over ad-hoc Python inside `shell-exec`.',
            '- To count items in a JSON array response, prefer template `json-count` over Python or jq inside `shell-exec`.',
            '- Use one step per meaningful operation, with explicit dependencies only when ordering is actually required.',
            '- Keep `inputs` as runtime default values and `inputContract` as UI field definitions. Do not mix the two.',
            '- Use `multiselect` when the operator should provide many strings like repositories, services, hosts, or file paths.',
            '- `multiselect` values interpolate into scripts as JSON arrays. If a shell script needs one item per line, convert the JSON inside the script instead of pretending it is already newline text.',
            '- `shell-exec` only guarantees `sh` by default. If a step needs tools like `curl`, `python3`, `jq`, `git`, or `gh`, prefer a template/runtime that already provides them or install them explicitly inside that same step.',
            '- If a `shell-exec` step parses JSON or makes network calls, include the package install in that same script before using the tools, for example `apk add --no-cache curl python3`.',
            '- Steps do not share a local filesystem. Pass data between steps through step outputs, extracted values, or runtime inputs, not temporary files.',
            '- Prior steps only expose `steps.<id>.status`, `steps.<id>.exitCode`, `steps.<id>.error`, `steps.<id>.output`, and `steps.<id>.extract.<name>`. Do not invent paths like `steps.fetch.name`.',
            '- `steps.<id>.output` is raw text, even when the template says its output type is JSON. Do not write `steps.fetch.output.foo`.',
            '- When a later step needs structured data from earlier JSON output, prefer template extractors plus `steps.<id>.extract.<name>`. For dotted field extraction from raw JSON text, prefer template `json-field`.',
            '- Use `inherit` when a step should not force a specific target. Do not use `none` as a target value.',
            '- For final summaries, extract each needed value in dedicated prior steps first, then keep the last `shell-exec` step to plain `echo` or `printf` only. Avoid JSON parsing tools in the final summary step.',
            '- Keep step IDs, workflow IDs, and input names short, stable, and operator-readable.',
            '- If a workflow reads data from a prior step, make that dependency explicit and reference `steps.<id>` carefully.',
            '- Prefer dry-run-friendly workflows whose intent is obvious from the resulting preview and logs.',
        ];

        if ($type === self::TYPE_TEMPLATE) {
            $lines[] = '- For templates, keep inputs reusable and neutral so the same template can support many workflows.';
        } else {
            $lines[] = '- For workflows, keep reusable logic in templates and put operator-specific intent in the workflow draft.';
        }

        return implode("\n", $lines);
    }

    private function buildWorkflowPatternReference(): string {
        return implode("\n", [
            '- Pattern for API workflows: use `http-request` / `http-api` to fetch JSON, use `steps.<id>.extract.responseBody` for the raw body, then use `json-field` to extract dotted fields like `homeworld`, `name`, or `population`.',
            '- Good example: step 1 uses `http-request` to fetch `https://example.test/api/item/1`; step 2 uses `json-field` with `json={{steps.step-1.extract.responseBody}}` and `path=id`; step 3 uses that extracted value in another API call or summary.',
            '- Good counting example: step 1 uses `http-request` to fetch a JSON array; step 2 uses `json-count` with `json={{steps.step-1.extract.responseBody}}`; step 3 prints `{{steps.step-2.output}}` in a summary.',
            '- Good summary example: fetch person JSON; extract `homeworld` with `json-field`; fetch planet JSON with `http-request`; extract `person-name`, `planet-name`, `climate`, and `population` with separate `json-field` steps; final step uses `shell-exec` only for `printf` with `{{steps.person-name.output}}`, `{{steps.planet-name.output}}`, and the other extracted scalar outputs.',
            '- Bad example: `{{steps.fetch.output.name}}` or `{{steps.fetch.output.data.id}}`. `output` is not a structured object path.',
            '- Good example for dry runs: keep the last step as a small `shell-exec` summary that echoes the already-derived values rather than re-fetching anything.',
        ]);
    }

    private function buildPublishedWorkflowReference(): string {
        $commands = $this->commandsManager->getCommands();
        if (empty($commands['success'])) {
            return 'Published workflows could not be loaded.';
        }

        $lines = [];
        foreach (array_slice($commands['commands'] ?? [], 0, 12) as $command) {
            if (!is_array($command)) {
                continue;
            }

            $stepRefs = [];
            foreach (array_slice($command['steps'] ?? [], 0, 6) as $step) {
                if (!is_array($step)) {
                    continue;
                }
                $stepId = trim((string)($step['id'] ?? 'step'));
                $templateId = trim((string)($step['templateId'] ?? 'template'));
                $stepRefs[] = $stepId . ':' . $templateId;
            }

            $inputs = [];
            foreach (array_slice($command['inputContract'] ?? [], 0, 6) as $field) {
                if (!is_array($field)) {
                    continue;
                }
                $inputs[] = sprintf(
                    '%s:%s%s',
                    trim((string)($field['name'] ?? 'input')),
                    trim((string)($field['type'] ?? 'string')) ?: 'string',
                    !empty($field['required']) ? ' (required)' : ''
                );
            }

            $lines[] = sprintf(
                '- %s: %s | mode=%s | steps=%s | inputs=%s',
                trim((string)($command['id'] ?? 'unknown')),
                trim((string)($command['name'] ?? 'Unnamed workflow')),
                trim((string)($command['executionMode'] ?? 'sync')) ?: 'sync',
                $stepRefs !== [] ? implode(', ', $stepRefs) : 'none',
                $inputs !== [] ? implode(', ', $inputs) : 'none'
            );
        }

        return $lines !== [] ? implode("\n", $lines) : 'No published workflows are available yet.';
    }

    private function parseDraftResponse(string $type, string $content): array {
        $payload = $this->extractJsonPayload($content);
        if (!is_array($payload)) {
            return [
                'reply' => trim($content) !== '' ? trim($content) : 'I could not produce a structured draft.',
                'summary' => 'No structured draft returned',
                'draft' => null,
                'notes' => ['The model did not return valid JSON.'],
            ];
        }

        $reply = trim((string)($payload['reply'] ?? $payload['message'] ?? ''));
        $summary = trim((string)($payload['summary'] ?? ''));
        $notes = [];
        foreach ((array)($payload['notes'] ?? []) as $note) {
            $note = trim((string)$note);
            if ($note !== '') {
                $notes[] = $note;
            }
        }

        $draft = is_array($payload['draft'] ?? null) ? $payload['draft'] : null;

        if ($reply === '') {
            $reply = $type === self::TYPE_TEMPLATE
                ? 'I drafted a reusable template for review.'
                : 'I drafted a workflow for review.';
        }
        if ($summary === '') {
            $summary = $type === self::TYPE_TEMPLATE
                ? 'Drafted a reusable template'
                : 'Drafted a workflow';
        }

        return [
            'reply' => $reply,
            'summary' => $summary,
            'draft' => $draft,
            'notes' => $notes,
        ];
    }

    private function validateDraft(string $type, array $draft): array {
        if ($type === self::TYPE_TEMPLATE) {
            return $this->templateStudioManager->validateDraftPayload([
                'name' => $draft['name'] ?? '',
                'description' => $draft['description'] ?? '',
                'templateId' => $draft['id'] ?? '',
                'draft' => $draft,
            ]);
        }

        return $this->workflowStudioManager->validateDraftPayload([
            'name' => $draft['name'] ?? '',
            'description' => $draft['description'] ?? '',
            'commandId' => $draft['id'] ?? '',
            'draft' => $draft,
        ]);
    }

    private function getBaseDraft(string $type): array {
        return $type === self::TYPE_TEMPLATE
            ? $this->templateStudioManager->getDefaultDraft()
            : $this->workflowStudioManager->getDefaultDraft();
    }

    private function mergeDrafts(array $base, array $override): array {
        $merged = $base;

        foreach ($override as $key => $value) {
            if (is_array($value) && isset($merged[$key]) && is_array($merged[$key]) && !$this->isSequentialArray($value) && !$this->isSequentialArray($merged[$key])) {
                $merged[$key] = $this->mergeDrafts($merged[$key], $value);
                continue;
            }

            $merged[$key] = $value;
        }

        return $merged;
    }

    private function isSequentialArray(array $value): bool {
        return array_keys($value) === range(0, count($value) - 1);
    }

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

    private function truncateBlock(string $content, int $maxBytes): string {
        if (strlen($content) <= $maxBytes) {
            return $content;
        }

        return substr($content, 0, $maxBytes) . "\n... [truncated]";
    }

    private function appendValidationNotes(array $notes, array $validation): array {
        $merged = [];

        foreach ($notes as $note) {
            $note = trim((string)$note);
            if ($note !== '') {
                $merged[] = $note;
            }
        }

        foreach (array_slice((array)($validation['errors'] ?? []), 0, 3) as $error) {
            $error = trim((string)$error);
            if ($error !== '') {
                $merged[] = 'Validation: ' . $error;
            }
        }

        foreach (array_slice((array)($validation['warnings'] ?? []), 0, 2) as $warning) {
            $warning = trim((string)$warning);
            if ($warning !== '') {
                $merged[] = 'Warning: ' . $warning;
            }
        }

        return array_values(array_unique($merged));
    }
}
