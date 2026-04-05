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
        $currentDraft = $this->normalizeExistingDraft($type, $currentDraft);
        $conversation = $this->normalizeConversation($options['conversation'] ?? []);
        $proposalHistory = $this->normalizeProposalHistory($options['proposalHistory'] ?? []);
        $currentValidation = is_array($options['currentValidation'] ?? null) ? $options['currentValidation'] : [];
        $editMode = !empty($options['editMode']) && $currentDraft !== [];
        $model = $this->resolveModel($resolvedProvider, trim((string)($options['model'] ?? '')));
        if (!$model['success']) {
            return $model;
        }

        $messages = [
            ['role' => 'system', 'content' => $this->buildSystemPrompt($type)],
            ['role' => 'user', 'content' => $this->buildUserPrompt($type, $prompt, $currentDraft, $conversation, $proposalHistory, $currentValidation, $editMode)],
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

        $baseDraft = $editMode && $currentDraft !== []
            ? $currentDraft
            : $this->getBaseDraft($type);
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
- The current draft supplied in the prompt is the source of truth.
- Prior AI proposals may appear in the conversation with status metadata. Proposed or dismissed proposals did not change the draft unless they were later applied.
- A reverted proposal was applied at least once and later undone. Use the current draft to determine the latest real state.
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
- If the prompt is underspecified, prefer the simplest valid workflow using specialized templates first. Use a small shell-glue or text-formatting step only when no better capability-matched template fits or for a small final formatting step.
- Keep IDs short and readable.
- `secrets` must always be an object map, never an array. Use `{}` when there are no secret bindings.
- Use `secrets` only for references to already-stored Doki secrets, with the shape `{ "ENV_VAR_NAME": "secret-id" }`.
- If the operator should provide a sensitive value at run time, put it in `inputContract` as a field with `type: "secret"` and keep it out of `secrets`.
- inputs is a runtime values object keyed by input name. Only put actual default values there.
- inputContract defines the input fields. Put field metadata there, not inside inputs.
- Do not put schema objects like {"type":"string","title":"Foo","default":""} inside inputs.
- Generic shell-oriented templates only guarantee the runtime they declare unless the script installs extra tools itself.
- Workflow steps run in separate executions. Do not assume files written in one step will exist in a later step.
- When refining an existing draft, return a full replacement workflow draft, not a partial patch.
- Remove unrelated steps, inputs, tags, or secrets from older drafts instead of carrying them forward.
- Doki supports run-level dry runs that record a redacted command preview without executing, and workflow drafts can be dry-run in Workflows Studio before publish. Do not add a permanent dryRun field to the workflow draft unless the schema explicitly requires it.
- Do not publish anything. You are only creating a draft for review.
- The current draft supplied in the prompt is the source of truth.
- Prior AI proposals may appear in the conversation with status metadata. Proposed or dismissed proposals did not change the draft unless they were later applied.
- A reverted proposal was applied at least once and later undone. Use the current draft to determine the latest real state.
PROMPT;
    }

    private function buildUserPrompt(
        string $type,
        string $prompt,
        array $currentDraft,
        array $conversation,
        array $proposalHistory,
        array $currentValidation,
        bool $editMode
    ): string {
        $reference = $this->truncateBlock($this->aiAdminManager->buildCommandReferenceBlock(), self::MAX_CONTEXT_BYTES);
        $workflowAuthoring = $this->buildWorkflowAuthoringReference($type);
        $templates = $this->buildTemplateReference();
        $capabilities = $this->buildCapabilityReference();
        $targets = $this->buildTargetReference();
        $environments = $this->buildEnvironmentReference();
        $existingWorkflows = $type === self::TYPE_WORKFLOW ? $this->buildPublishedWorkflowReference() : '';
        $dryRunCapability = $this->buildDryRunReference();
        $patterns = $type === self::TYPE_WORKFLOW ? $this->buildWorkflowPatternReference() : '';
        $mode = $editMode
            ? 'Refine the current draft by returning an updated draft that preserves unaffected structure'
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
            '[Available capabilities]',
            $capabilities,
            '[/Available capabilities]',
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
            $lines[] = $editMode
                ? 'Preserve existing IDs, step structure, inputs, targets, and secret bindings unless the request explicitly changes them.'
                : 'If any part of the current draft is unrelated to the new request, remove it in the replacement draft.';
        }

        if ($conversation !== []) {
            $lines[] = '';
            $lines[] = '[Conversation history]';
            foreach ($conversation as $message) {
                $formatted = $this->formatConversationMessageForPrompt($message);
                if ($formatted !== '') {
                    $lines[] = '- ' . $formatted;
                }
            }
            $lines[] = '[/Conversation history]';
        }

        if ($proposalHistory !== []) {
            $lines[] = '';
            $lines[] = '[Proposal state history]';
            $lines[] = 'Use this to understand which prior AI edits changed the draft and which ones did not.';
            foreach ($proposalHistory as $proposal) {
                $formatted = $this->formatProposalHistoryEntry($proposal);
                if ($formatted !== '') {
                    $lines[] = '- ' . $formatted;
                }
            }
            $lines[] = '[/Proposal state history]';
            $lines[] = '';
            $lines[] = 'The current draft is authoritative if any proposal text conflicts with these status annotations.';
        }

        if ($currentValidation !== []) {
            $lines[] = '';
            $lines[] = '[Current validation state]';
            $lines[] = $this->formatValidationForPrompt($currentValidation);
            $lines[] = '[/Current validation state]';
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
            $capabilityHints = is_array($template['capabilityHints'] ?? null) ? $template['capabilityHints'] : [];
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
            $hintParts = [];
            foreach ([
                'capabilities' => 'capabilities',
                'payloadHandling' => 'payloadHandling',
            ] as $field => $label) {
                $values = array_values(array_filter(array_map('strval', (array)($capabilityHints[$field] ?? []))));
                if ($values !== []) {
                    $hintParts[] = $label . '=' . implode(', ', $values);
                }
            }
            $scriptRuntime = trim((string)($capabilityHints['scriptRuntime'] ?? ''));
            if ($scriptRuntime !== '') {
                $hintParts[] = 'scriptRuntime=' . $scriptRuntime;
            }
            $scriptInput = trim((string)($capabilityHints['dataFlow']['scriptInput'] ?? ''));
            if ($scriptInput !== '') {
                $hintParts[] = 'scriptInput=' . $scriptInput;
            }
            $requestPathInput = trim((string)($capabilityHints['dataFlow']['requestPathInput'] ?? ''));
            if ($requestPathInput !== '') {
                $hintParts[] = 'requestPathInput=' . $requestPathInput;
            }
            $requestHeadersInput = trim((string)($capabilityHints['dataFlow']['requestHeadersInput'] ?? ''));
            if ($requestHeadersInput !== '') {
                $hintParts[] = 'requestHeadersInput=' . $requestHeadersInput;
            }
            $payloadInputs = array_values(array_filter(array_map(
                'strval',
                (array)($capabilityHints['dataFlow']['payloadInputs'] ?? [])
            )));
            if ($payloadInputs !== []) {
                $hintParts[] = 'payloadInputs=' . implode(', ', $payloadInputs);
            }
            $payloadValueMode = trim((string)($capabilityHints['dataFlow']['payloadValueMode'] ?? ''));
            if ($payloadValueMode !== '') {
                $hintParts[] = 'payloadValueMode=' . $payloadValueMode;
            }
            $payloadRawSuffix = trim((string)($capabilityHints['dataFlow']['payloadRawSuffix'] ?? ''));
            if ($payloadRawSuffix !== '') {
                $hintParts[] = 'payloadRawSuffix=' . $payloadRawSuffix;
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
                $hintParts !== [] ? ' | ' . implode(' | ', $hintParts) : ''
            );
        }

        return $lines !== [] ? implode("\n", $lines) : 'No templates are available.';
    }

    private function buildCapabilityReference(): string {
        $registryResult = $this->templateManager->getCapabilityRegistry();
        if (empty($registryResult['success'])) {
            return 'No capability registry is available.';
        }

        $registry = is_array($registryResult['registry'] ?? null) ? $registryResult['registry'] : [];
        $sections = [];

        $capabilities = is_array($registry['capabilities'] ?? null) ? $registry['capabilities'] : [];
        if ($capabilities !== []) {
            $lines = [];
            foreach ($capabilities as $capability => $entry) {
                $templates = array_map(
                    static fn(array $template): string => (string)($template['id'] ?? ''),
                    array_slice((array)($entry['templates'] ?? []), 0, 4)
                );
                $templates = array_values(array_filter(array_map('trim', $templates)));
                $lines[] = sprintf(
                    '- %s: %s',
                    $capability,
                    $templates !== [] ? implode(', ', $templates) : 'no installed templates'
                );
            }
            if ($lines !== []) {
                $sections[] = "Capabilities:\n" . implode("\n", $lines);
            }
        }

        $payloadHandling = is_array($registry['payloadHandling'] ?? null) ? $registry['payloadHandling'] : [];
        if ($payloadHandling !== []) {
            $lines = [];
            foreach ($payloadHandling as $kind => $entry) {
                $templates = array_map(
                    static fn(array $template): string => (string)($template['id'] ?? ''),
                    array_slice((array)($entry['templates'] ?? []), 0, 4)
                );
                $templates = array_values(array_filter(array_map('trim', $templates)));
                $lines[] = sprintf(
                    '- %s: %s',
                    $kind,
                    $templates !== [] ? implode(', ', $templates) : 'no installed templates'
                );
            }
            if ($lines !== []) {
                $sections[] = "Payload handling:\n" . implode("\n", $lines);
            }
        }

        $scriptRuntimes = is_array($registry['scriptRuntimes'] ?? null) ? $registry['scriptRuntimes'] : [];
        if ($scriptRuntimes !== []) {
            $lines = [];
            foreach ($scriptRuntimes as $runtime => $entry) {
                $templates = array_map(
                    static fn(array $template): string => (string)($template['id'] ?? ''),
                    array_slice((array)($entry['templates'] ?? []), 0, 4)
                );
                $templates = array_values(array_filter(array_map('trim', $templates)));
                $lines[] = sprintf(
                    '- %s: %s',
                    $runtime,
                    $templates !== [] ? implode(', ', $templates) : 'no installed templates'
                );
            }
            if ($lines !== []) {
                $sections[] = "Script runtimes:\n" . implode("\n", $lines);
            }
        }

        return $sections !== [] ? implode("\n\n", $sections) : 'No capability metadata is available.';
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
            '- Prefer installed templates whose declared capabilities match the job instead of forcing everything through one generic shell step.',
            $this->buildCapabilityPreferenceLine(
                'For external HTTP APIs, webhooks, and JSON endpoints, prefer templates that support `http.fetch`.',
                'http.fetch'
            ),
            '- If a template offers a machine-friendly output format like newline-separated names or JSON lines, prefer that over parsing a visual table in shell.',
            '- After an HTTP fetch step, prefer extractor fields like `steps.<id>.extract.responseBody` and `steps.<id>.extract.statusCode` when the installed template exposes them.',
            '- For installed HTTP-fetch templates, a headers input like `headers` is usually plain text such as `Header1:Value1,Header2:Value2`, not a JSON object string.',
            '- Each HTTP-fetch step performs one concrete request. Do not pass a whole JSON object, array, or record set into the request path input and expect the template to iterate for you.',
            $this->buildCapabilityPreferenceLine(
                'To pull a field like `homeworld` or `name` out of raw JSON text, prefer templates that support `json.extract`.',
                'json.extract',
                ['payloadHandling' => ['structured-json']]
            ),
            $this->buildCapabilityPreferenceLine(
                'To count items in a JSON array response, prefer templates that support `json.count`.',
                'json.count',
                ['payloadHandling' => ['structured-json']]
            ),
            $this->buildCapabilityPreferenceLine(
                'When a workflow needs to sort, filter, join, or reshape a larger JSON/text payload from earlier steps, prefer templates that support `payload.transform` and large payload handling.',
                'payload.transform',
                ['payloadHandling' => ['structured-json', 'large-text'], 'requiresScriptInput' => true, 'requiresPayloadInputs' => true]
            ),
            $this->buildCapabilityPreferenceLine(
                'If the main logic of a step is a payload transform in a script language, prefer a transform-capable template over a generic shell wrapper.',
                'payload.transform',
                ['requiresScriptInput' => true, 'requiresPayloadInputs' => true]
            ),
            '- Templates that support `payload.transform` are for pure data transformation only. Do not use them to fetch URLs, call external APIs, or perform new network requests.',
            '- A transform step should read only its declared payload inputs and emit a reshaped result. If the step needs more external data, fetch that data in dedicated HTTP steps first.',
            '- Never embed workflow placeholders like `{{inputs.foo}}`, `{{steps.prev.output}}`, or `{{steps.prev.extract.responseBody}}` directly into a transform script body. Keep the script static, pass the dynamic value through a declared payload input, then read `payload1`, `payload2`, or the matching `_raw` variable inside the script.',
            '- If the workflow would need one HTTP request per list item and no installed template advertises that behavior, do not fake it by stuffing the whole list into one HTTP path. Keep the draft simpler or surface that a new reusable template is needed first.',
            '- Do not work around missing fan-out or iteration support by dropping to `shell-exec` with Python or shell loops that make all the API calls manually. Either keep the workflow simpler with the installed templates, or surface that a reusable template is needed first.',
            '- If a transform template declares `payloadValueMode=parsed-json-or-raw`, then payload inputs like `payload1` are already parsed JSON values when the input contains valid JSON. Use `payload1` directly for parsed data, and only use `payload1_raw` when you need the original raw string.',
            '- Only use the declared payload slots. If the template exposes `payload1` through `payload4`, do not invent `payload5` or other undefined runtime variables.',
            '- Use one step per meaningful operation, with explicit dependencies only when ordering is actually required.',
            '- Keep `inputs` as runtime default values and `inputContract` as UI field definitions. Do not mix the two.',
            '- Do not invent hidden workflow inputs. If the operator must provide a value, it must be declared in `inputContract`. Only keep an input out of `inputContract` when it has a real internal default in `inputs` and is not meant to be edited by the operator.',
            '- Do not leave unused operator inputs, optional secrets, or “reserved for a future step” placeholders in the draft. If you remove a capability like flight lookup, remove the related runtime inputs too.',
            '- Use `multiselect` when the operator should provide many strings like repositories, services, hosts, or file paths.',
            '- `multiselect` values interpolate into scripts as JSON arrays. If a shell script needs one item per line, convert the JSON inside the script instead of pretending it is already newline text.',
            '- Generic shell templates only guarantee the runtime they declare. If a step needs extra tools like `curl`, `python3`, `jq`, `git`, or `gh`, prefer a template/runtime that already provides them, or install them in that same script.',
            '- If a shell step parses JSON or makes network calls, include the package install in that same script before using the tools, for example `apk add --no-cache curl python3`.',
            '- Steps do not share a local filesystem. Pass data between steps through step outputs, extracted values, or runtime inputs, not temporary files.',
            '- Prior steps only expose `steps.<id>.status`, `steps.<id>.exitCode`, `steps.<id>.error`, `steps.<id>.output`, and `steps.<id>.extract.<name>`. Do not invent paths like `steps.fetch.name`.',
            '- `steps.<id>.output` is raw text, even when the template says its output type is JSON. Do not write `steps.fetch.output.foo`.',
            '- When a later step needs structured data from earlier JSON output, prefer template extractors plus `steps.<id>.extract.<name>`. For dotted field extraction from raw JSON text, use an installed JSON-extraction template if one is available.',
            '- If a transform step emits a JSON object or array, do not wire `steps.<transform>.output` straight into an HTTP request path. Extract the exact scalar field the HTTP step needs first, or make the transform emit one plain scalar string when that step is meant to produce only one URL/query value.',
            '- Never inline whole prior payloads like `{{steps.fetch.output}}` or `{{steps.fetch.extract.responseBody}}` into a later shell script when that payload could be large. That often blows past the shell command length limit. Extract only the scalar values you need first, or use an installed transform-capable template with payload inputs.',
            '- Use `inherit` when a step should not force a specific target. Do not use `none` as a target value.',
            $this->buildCapabilityPreferenceLine(
                'For final summaries, extract each needed value in dedicated prior steps first, then keep the last summary step to plain text formatting only.',
                'text.format',
                ['requiresScriptInput' => true]
            ),
            '- Keep step IDs, workflow IDs, and input names short, stable, and operator-readable.',
            '- If a workflow reads data from a prior step, make that dependency explicit and reference `steps.<id>` carefully.',
            '- Prefer dry-run-friendly workflows whose intent is obvious from the resulting preview and logs.',
            '- When editing an existing workflow, preserve unaffected steps and only change the parts needed to satisfy the new request.',
        ];

        if ($type === self::TYPE_TEMPLATE) {
            $lines[] = '- For templates, keep inputs reusable and neutral so the same template can support many workflows.';
        } else {
            $lines[] = '- For workflows, keep reusable logic in templates and put operator-specific intent in the workflow draft.';
        }

        return implode("\n", $lines);
    }

    private function buildWorkflowPatternReference(): string {
        $httpFetch = $this->getTopCapabilityTemplateIds('http.fetch', [], 2);
        $jsonExtract = $this->getTopCapabilityTemplateIds('json.extract', ['payloadHandling' => ['structured-json']], 1);
        $jsonCount = $this->getTopCapabilityTemplateIds('json.count', ['payloadHandling' => ['structured-json']], 1);
        $payloadTransform = $this->getTopCapabilityTemplateIds('payload.transform', ['payloadHandling' => ['structured-json', 'large-text'], 'requiresScriptInput' => true, 'requiresPayloadInputs' => true], 1);
        $textFormat = $this->getTopCapabilityTemplateIds('text.format', ['requiresScriptInput' => true], 1);

        $httpFetchExample = $httpFetch !== [] ? '`' . implode('` / `', $httpFetch) . '`' : 'an installed `http.fetch` template';
        $jsonExtractExample = $jsonExtract !== [] ? '`' . $jsonExtract[0] . '`' : 'an installed `json.extract` template';
        $jsonCountExample = $jsonCount !== [] ? '`' . $jsonCount[0] . '`' : 'an installed `json.count` template';
        $payloadTransformExample = $payloadTransform !== [] ? '`' . $payloadTransform[0] . '`' : 'an installed `payload.transform` template';
        $textFormatExample = $textFormat !== [] ? '`' . $textFormat[0] . '`' : 'an installed `text.format` template';

        return implode("\n", [
            '- Pattern for API workflows: use ' . $httpFetchExample . ' to fetch JSON, use `steps.<id>.extract.responseBody` for the raw body when available, then use ' . $jsonExtractExample . ' to extract dotted fields like `homeworld`, `name`, or `population`.',
            '- Good example: step 1 uses ' . $httpFetchExample . ' to fetch `https://example.test/api/item/1`; step 2 uses ' . $jsonExtractExample . ' with `json={{steps.step-1.extract.responseBody}}` and `path=id`; step 3 uses that extracted value in another API call or summary.',
            '- Good counting example: step 1 uses ' . $httpFetchExample . ' to fetch a JSON array; step 2 uses ' . $jsonCountExample . ' with `json={{steps.step-1.extract.responseBody}}`; step 3 prints `{{steps.step-2.output}}` in a summary.',
            '- Good summary example: fetch person JSON; extract `homeworld` with ' . $jsonExtractExample . '; fetch planet JSON with ' . $httpFetchExample . '; extract `person-name`, `planet-name`, `climate`, and `population` in separate scalar-extraction steps; final step uses ' . $textFormatExample . ' only for formatting the already-derived values.',
            '- Good transform example: step 1 fetches a large JSON body; step 2 uses ' . $payloadTransformExample . ' with `payload1={{steps.step-1.extract.responseBody}}` and a short script that sorts or filters the parsed payload; step 3 uses the compact result from step 2.',
            '- Good transform example: if a step would otherwise be a generic shell wrapper with a large embedded script, switch that step to ' . $payloadTransformExample . ' and pass upstream data in payload inputs instead of inlining it.',
            '- Good transform example with workflow input: if a transform needs the operator-provided country name, pass `payload2={{inputs.country_name}}`, then read `payload2` or `payload2_raw` inside the script. Do not paste `{{inputs.country_name}}` into the script source.',
            '- Good scalar handoff example: if a transform emits `{"city":"Paris","airportId":"PARI"}`, add ' . $jsonExtractExample . ' steps for `city` and `airportId`, then use `{{steps.city.output}}` or `{{steps.airport-id.output}}` in later HTTP requests. Do not pass the whole transform output into `path`.',
            '- Bad transform example: a `payload.transform` step that calls `urllib`, `requests`, `fetch`, `curl`, or any external URL. Fetch steps and transform steps must stay separate.',
            '- Bad handoff example: step 1 emits JSON like `{"query":"Paris"}` and step 2 calls ' . $httpFetchExample . ' with `path=https://example.test?q={{steps.step-1.output}}`. Extract `query` first or have step 1 emit only `Paris` as plain text.',
            '- Bad workaround example: after a few fetch/transform steps, a giant `shell-exec` step installs Python and loops over a JSON payload to make all remaining API calls. That is not a good workflow shape; keep the workflow within installed template capabilities or ask for a reusable template first.',
            '- Bad example: `{{steps.fetch.output.name}}` or `{{steps.fetch.output.data.id}}`. `output` is not a structured object path.',
            '- Good example for dry runs: keep the last step as a small text-formatting summary that echoes the already-derived values rather than re-fetching anything.',
        ]);
    }

    private function buildCapabilityPreferenceLine(string $intro, string $capability, array $context = []): string {
        $candidates = $this->templateManager->getBestTemplateCandidatesForCapability($capability, $context);
        if ($candidates === []) {
            return $intro . ' No installed template currently advertises `' . $capability . '`, so keep the workflow simple and avoid assuming that capability exists.';
        }

        $ids = array_map(
            static fn(array $template): string => trim((string)($template['id'] ?? '')),
            array_slice($candidates, 0, 3)
        );
        $ids = array_values(array_filter($ids));

        if ($ids === []) {
            return $intro;
        }

        return $intro . ' Installed candidates: `' . implode('`, `', $ids) . '`.';
    }

    private function getTopCapabilityTemplateIds(string $capability, array $context = [], int $limit = 1): array {
        $candidates = $this->templateManager->getBestTemplateCandidatesForCapability($capability, $context);
        $ids = array_map(
            static fn(array $template): string => trim((string)($template['id'] ?? '')),
            array_slice($candidates, 0, max(1, $limit))
        );

        return array_values(array_filter($ids));
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

    private function normalizeExistingDraft(string $type, array $draft): array {
        if ($draft === []) {
            return [];
        }

        $validated = $this->validateDraft($type, $draft);
        return is_array($validated['draft'] ?? null) ? $validated['draft'] : $draft;
    }

    private function normalizeConversation($conversation): array {
        if (!is_array($conversation)) {
            return [];
        }

        $normalized = [];
        foreach ($conversation as $message) {
            if (!is_array($message)) {
                continue;
            }

            $role = strtolower(trim((string)($message['role'] ?? 'user')));
            $role = $role === 'assistant' ? 'assistant' : 'user';
            $content = trim((string)($message['content'] ?? ''));
            if ($content === '') {
                continue;
            }

            $normalizedMessage = [
                'role' => $role,
                'content' => $content,
            ];

            foreach (['kind', 'summary', 'proposalStatus', 'createdAt', 'appliedAt', 'appliedByUsername', 'revertedAt', 'revertedByUsername', 'actorUsername'] as $key) {
                if (isset($message[$key]) && trim((string)$message[$key]) !== '') {
                    $normalizedMessage[$key] = trim((string)$message[$key]);
                }
            }

            $normalized[] = $normalizedMessage;
        }

        return $normalized;
    }

    private function normalizeProposalHistory($proposalHistory): array {
        if (!is_array($proposalHistory)) {
            return [];
        }

        $normalized = [];
        foreach ($proposalHistory as $proposal) {
            if (!is_array($proposal)) {
                continue;
            }

            $proposalStatus = trim((string)($proposal['proposalStatus'] ?? ''));
            $content = trim((string)($proposal['content'] ?? ''));
            $summary = trim((string)($proposal['summary'] ?? ''));
            if ($proposalStatus === '' && $content === '' && $summary === '') {
                continue;
            }

            $normalized[] = [
                'summary' => $summary,
                'content' => $content,
                'proposalStatus' => $proposalStatus,
                'createdAt' => trim((string)($proposal['createdAt'] ?? '')),
                'appliedAt' => trim((string)($proposal['appliedAt'] ?? '')),
                'appliedByUsername' => trim((string)($proposal['appliedByUsername'] ?? '')),
                'revertedAt' => trim((string)($proposal['revertedAt'] ?? '')),
                'revertedByUsername' => trim((string)($proposal['revertedByUsername'] ?? '')),
            ];
        }

        return $normalized;
    }

    private function formatConversationMessageForPrompt(array $message): string {
        $content = trim((string)($message['content'] ?? ''));
        if ($content === '') {
            return '';
        }

        $role = ($message['role'] ?? 'user') === 'assistant' ? 'Assistant' : 'User';
        $details = [];
        $kind = trim((string)($message['kind'] ?? ''));

        if ($kind === 'assistant-proposal') {
            $details[] = 'proposal';
            $statusLabel = $this->formatProposalStatusLabel((string)($message['proposalStatus'] ?? ''));
            if ($statusLabel !== '') {
                $details[] = 'status: ' . $statusLabel;
            }
            $summary = trim((string)($message['summary'] ?? ''));
            if ($summary !== '') {
                $details[] = 'summary: ' . $summary;
            }
            if (trim((string)($message['appliedAt'] ?? '')) !== '') {
                $details[] = 'applied at ' . trim((string)$message['appliedAt']);
            }
            if (trim((string)($message['revertedAt'] ?? '')) !== '') {
                $details[] = 'reverted at ' . trim((string)$message['revertedAt']);
            }
        } elseif ($kind === 'assistant-error') {
            $details[] = 'error';
        }

        if (trim((string)($message['createdAt'] ?? '')) !== '') {
            $details[] = 'at ' . trim((string)$message['createdAt']);
        }

        if ($details !== []) {
            $role .= ' (' . implode(', ', $details) . ')';
        }

        return $role . ': ' . $content;
    }

    private function formatProposalHistoryEntry(array $proposal): string {
        $content = trim((string)($proposal['content'] ?? ''));
        $summary = trim((string)($proposal['summary'] ?? ''));
        $label = $summary !== '' ? $summary : $this->truncateInlineText($content, 160);
        if ($label === '') {
            return '';
        }

        $statusLabel = $this->formatProposalStatusLabel((string)($proposal['proposalStatus'] ?? ''));
        $parts = [$label];
        if ($statusLabel !== '') {
            $parts[] = 'status: ' . $statusLabel;
        }

        $meaning = $this->describeProposalStatusMeaning((string)($proposal['proposalStatus'] ?? ''));
        if ($meaning !== '') {
            $parts[] = $meaning;
        }

        if (trim((string)($proposal['createdAt'] ?? '')) !== '') {
            $parts[] = 'proposed at ' . trim((string)$proposal['createdAt']);
        }
        if (trim((string)($proposal['appliedAt'] ?? '')) !== '') {
            $appliedBy = trim((string)($proposal['appliedByUsername'] ?? ''));
            $parts[] = 'applied at ' . trim((string)$proposal['appliedAt']) . ($appliedBy !== '' ? ' by ' . $appliedBy : '');
        }
        if (trim((string)($proposal['revertedAt'] ?? '')) !== '') {
            $revertedBy = trim((string)($proposal['revertedByUsername'] ?? ''));
            $parts[] = 'reverted at ' . trim((string)$proposal['revertedAt']) . ($revertedBy !== '' ? ' by ' . $revertedBy : '');
        }

        return implode(' | ', array_filter($parts));
    }

    private function formatProposalStatusLabel(string $status): string {
        return match (strtolower(trim($status))) {
            'proposed' => 'pending',
            'applied' => 'applied',
            'dismissed' => 'not applied',
            'reverted' => 'reverted',
            'superseded' => 'superseded',
            'error' => 'error',
            default => '',
        };
    }

    private function describeProposalStatusMeaning(string $status): string {
        return match (strtolower(trim($status))) {
            'proposed' => 'This proposal has not been applied to the draft yet.',
            'dismissed' => 'This proposal was reviewed and not applied to the draft.',
            'applied' => 'This proposal changed the draft at least once.',
            'reverted' => 'This proposal was applied and later undone.',
            'superseded' => 'This proposal was replaced by a later proposal.',
            'error' => 'This was an AI error response, not a usable draft change.',
            default => '',
        };
    }

    private function truncateInlineText(string $text, int $maxLength = 160): string {
        $text = preg_replace('/\s+/', ' ', trim($text)) ?? trim($text);
        if ($text === '' || $maxLength < 4) {
            return $text;
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($text) <= $maxLength) {
                return $text;
            }

            return rtrim(mb_substr($text, 0, $maxLength - 3)) . '...';
        }

        if (strlen($text) <= $maxLength) {
            return $text;
        }

        return rtrim(substr($text, 0, $maxLength - 3)) . '...';
    }

    private function formatValidationForPrompt(array $validation): string {
        $errors = array_slice(array_values(array_filter(array_map('strval', (array)($validation['errors'] ?? [])))), 0, 6);
        $warnings = array_slice(array_values(array_filter(array_map('strval', (array)($validation['warnings'] ?? [])))), 0, 4);

        if ($errors === [] && $warnings === []) {
            return 'No current validation issues.';
        }

        $lines = [];
        foreach ($errors as $error) {
            $lines[] = '- Error: ' . trim($error);
        }
        foreach ($warnings as $warning) {
            $lines[] = '- Warning: ' . trim($warning);
        }

        return implode("\n", $lines);
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
