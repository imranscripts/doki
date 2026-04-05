<?php
/**
 * TemplateManager - Loads and parses templates from the templates directory
 * Templates are read-only and define what tools can do (not where they run)
 */

class TemplateManager {
    // In Docker: /var/www/templates (mounted separately)
    // Local dev: __DIR__/../../templates (relative path)
    private const TEMPLATES_DIR_DOCKER = '/var/www/templates';
    private const TEMPLATES_DIR_LOCAL = __DIR__ . '/../../templates';
    private const CUSTOM_TEMPLATES_DIR = __DIR__ . '/../data/templates';
    private ?array $templatesCache = null;
    private ?array $templateIndexCache = null;
    private ?array $capabilityRegistryCache = null;
    
    private function getTemplatesDir(): string {
        // Check Docker path first
        if (is_dir(self::TEMPLATES_DIR_DOCKER)) {
            return self::TEMPLATES_DIR_DOCKER;
        }
        // Fallback to local development path
        return self::TEMPLATES_DIR_LOCAL;
    }

    public function getCustomTemplatesDir(): string {
        return self::CUSTOM_TEMPLATES_DIR;
    }
    
    /**
     * Get all available templates
     */
    public function getTemplates(): array {
        if ($this->templatesCache !== null) {
            return $this->templatesCache;
        }

        $templatesById = [];
        $roots = $this->getTemplateRoots();

        if ($roots === []) {
            return $this->templatesCache = ['success' => false, 'error' => 'Templates directory not found: ' . $this->getTemplatesDir(), 'templates' => []];
        }

        foreach ($roots as $root) {
            $files = $this->findTemplateFiles($root['path']);
            foreach ($files as $file) {
                $template = $this->parseTemplate($file, $root['source'], $root['path']);
                if ($template) {
                    $templatesById[$template['id']] = $template;
                }
            }
        }

        $templates = array_values($templatesById);
        usort($templates, fn($a, $b) => strcasecmp($a['name'], $b['name']));

        return $this->templatesCache = ['success' => true, 'templates' => $templates];
    }
    
    /**
     * Get a single template by ID
     */
    public function getTemplate(string $id): ?array {
        $index = $this->getTemplateIndex();
        return $index[$id] ?? null;
    }

    public function getCapabilityRegistry(): array {
        if ($this->capabilityRegistryCache !== null) {
            return $this->capabilityRegistryCache;
        }

        $result = $this->getTemplates();
        if (!$result['success']) {
            return $this->capabilityRegistryCache = [
                'success' => false,
                'error' => $result['error'] ?? 'Unable to load templates',
                'registry' => [],
            ];
        }

        $registry = [
            'templates' => [],
            'capabilities' => [],
            'payloadHandling' => [],
            'scriptRuntimes' => [],
        ];

        foreach ($result['templates'] as $template) {
            $summary = $this->buildCapabilityTemplateSummary($template);
            $registry['templates'][] = $summary;

            foreach ($summary['capabilities'] as $capability) {
                $this->addTemplateSummaryToRegistryBucket($registry['capabilities'], $capability, $summary);
            }
            foreach ($summary['payloadHandling'] as $payloadHandling) {
                $this->addTemplateSummaryToRegistryBucket($registry['payloadHandling'], $payloadHandling, $summary);
            }
            if ($summary['scriptRuntime'] !== null) {
                $this->addTemplateSummaryToRegistryBucket($registry['scriptRuntimes'], $summary['scriptRuntime'], $summary);
            }
        }

        usort($registry['templates'], fn($a, $b) => strcasecmp($a['name'], $b['name']));
        foreach (['capabilities', 'payloadHandling', 'scriptRuntimes'] as $bucket) {
            $registry[$bucket] = $this->normalizeRegistryBucket($registry[$bucket]);
        }

        return $this->capabilityRegistryCache = ['success' => true, 'registry' => $registry];
    }

    public function getTemplatesByCapability(string $capability): array {
        return $this->filterTemplatesByCapabilityField('capabilities', $capability);
    }

    public function getTemplatesByPayloadHandling(string $payloadHandling): array {
        return $this->filterTemplatesByCapabilityField('payloadHandling', $payloadHandling);
    }

    public function getBestTemplateCandidatesForCapability(string $capability, array $context = []): array {
        $capability = trim($capability);
        if ($capability === '') {
            return [];
        }

        $candidates = [];
        $payloadHandling = $this->normalizeStringList($context['payloadHandling'] ?? []);
        $scriptRuntime = isset($context['scriptRuntime']) && is_string($context['scriptRuntime'])
            ? trim($context['scriptRuntime'])
            : '';
        $requiresScriptInput = !empty($context['requiresScriptInput']);
        $requiresPayloadInputs = !empty($context['requiresPayloadInputs']);

        foreach ($this->getTemplatesByCapability($capability) as $template) {
            $hints = $template['capabilityHints'] ?? $this->normalizeCapabilityHints([]);
            $score = 100;
            $reasons = ["supports {$capability}"];
            $warnings = [];

            foreach ($payloadHandling as $payloadKind) {
                if (in_array($payloadKind, $hints['payloadHandling'], true)) {
                    $score += 15;
                    $reasons[] = "handles {$payloadKind}";
                }
            }

            if ($scriptRuntime !== '') {
                if ($hints['scriptRuntime'] === $scriptRuntime) {
                    $score += 10;
                    $reasons[] = "matches {$scriptRuntime} runtime";
                } elseif ($hints['scriptRuntime'] !== null) {
                    $warnings[] = "uses {$hints['scriptRuntime']} runtime";
                }
            }

            $scriptInput = trim((string)($hints['dataFlow']['scriptInput'] ?? ''));
            $payloadInputs = array_values(array_filter(
                array_map('strval', (array)($hints['dataFlow']['payloadInputs'] ?? [])),
                static fn(string $value): bool => trim($value) !== ''
            ));

            if ($requiresScriptInput) {
                if ($scriptInput !== '') {
                    $score += 12;
                    $reasons[] = "accepts script input `{$scriptInput}`";
                } else {
                    $score -= 12;
                    $warnings[] = 'does not declare a script input';
                }
            }

            if ($requiresPayloadInputs) {
                if ($payloadInputs !== []) {
                    $score += 18;
                    $reasons[] = 'accepts dedicated payload inputs';
                } else {
                    $score -= 18;
                    $warnings[] = 'does not declare dedicated payload inputs';
                }
            }

            $candidates[] = array_merge($template, [
                'capabilityMatch' => [
                    'capability' => $capability,
                    'score' => $score,
                    'reasons' => $reasons,
                    'warnings' => $warnings,
                ],
            ]);
        }

        usort($candidates, function ($a, $b) {
            $scoreCompare = ($b['capabilityMatch']['score'] ?? 0) <=> ($a['capabilityMatch']['score'] ?? 0);
            if ($scoreCompare !== 0) {
                return $scoreCompare;
            }

            return strcasecmp($a['name'], $b['name']);
        });

        return $candidates;
    }

    private function getTemplateRoots(): array {
        $roots = [];
        $builtin = realpath($this->getTemplatesDir());
        if ($builtin && is_dir($builtin)) {
            $roots[] = ['source' => 'builtin', 'path' => $builtin];
        }

        $custom = realpath(self::CUSTOM_TEMPLATES_DIR);
        if ($custom && is_dir($custom)) {
            $roots[] = ['source' => 'custom', 'path' => $custom];
        }

        return $roots;
    }

    private function getTemplateIndex(): array {
        if ($this->templateIndexCache !== null) {
            return $this->templateIndexCache;
        }

        $result = $this->getTemplates();
        if (!$result['success']) {
            return $this->templateIndexCache = [];
        }

        $index = [];
        foreach ($result['templates'] as $template) {
            $index[$template['id']] = $template;
        }

        return $this->templateIndexCache = $index;
    }
    
    /**
     * Find all template files recursively
     */
    private function findTemplateFiles(string $dir): array {
        $files = [];
        
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            
            $path = $dir . '/' . $item;
            
            if (is_dir($path)) {
                // Skip certain directories (examples contains reference templates, not for production)
                if (in_array($item, ['schema', 'orchestrator', 'node_modules', '.git', 'examples'])) continue;
                
                // Recurse into subdirectories
                $files = array_merge($files, $this->findTemplateFiles($path));
            } elseif (preg_match('/\.template\.ya?ml$/i', $item)) {
                $files[] = $path;
            }
        }
        
        return $files;
    }
    
    /**
     * Parse a template YAML file
     */
    private function parseTemplate(string $filePath, string $source = 'builtin', ?string $basePath = null): ?array {
        if (!function_exists('yaml_parse_file')) {
            // Fallback: try to parse manually if YAML extension is not available
            return $this->parseTemplateManual($filePath, $source, $basePath);
        }
        
        $data = @yaml_parse_file($filePath);
        if ($data === false) {
            error_log("Failed to parse template: $filePath");
            return null;
        }
        
        return $this->normalizeTemplate($data, $filePath, $source, $basePath);
    }
    
    /**
     * Manual YAML parsing fallback (basic implementation)
     */
    private function parseTemplateManual(string $filePath, string $source = 'builtin', ?string $basePath = null): ?array {
        $content = @file_get_contents($filePath);
        if ($content === false) return null;
        
        // Very basic YAML parsing for essential fields
        $data = [];
        
        // Extract top-level scalar values
        if (preg_match('/^id:\s*(.+)$/m', $content, $m)) $data['id'] = trim($m[1]);
        if (preg_match('/^name:\s*(.+)$/m', $content, $m)) $data['name'] = trim($m[1]);
        if (preg_match('/^version:\s*["\']?(.+?)["\']?\s*$/m', $content, $m)) $data['version'] = trim($m[1]);
        if (preg_match('/^description:\s*(.+)$/m', $content, $m)) $data['description'] = trim($m[1]);
        if (preg_match('/^targetType:\s*(.+)$/m', $content, $m)) $data['targetType'] = trim($m[1]);
        if (preg_match('/^icon:\s*(.+)$/m', $content, $m)) $data['icon'] = trim($m[1]);
        if (preg_match('/^color:\s*["\']?(.+?)["\']?\s*$/m', $content, $m)) $data['color'] = trim($m[1]);
        
        // Extract runtime image
        if (preg_match('/runtime:\s*\n\s+image:\s*(.+)$/m', $content, $m)) {
            $data['runtime'] = ['image' => trim($m[1])];
        }
        
        // Extract inputs section (simplified)
        $data['inputs'] = $this->extractInputsManual($content);
        
        // Extract requirements
        if (preg_match('/requirements:\s*\n((?:\s+-\s*.+\n?)+)/m', $content, $m)) {
            $reqs = [];
            preg_match_all('/^\s+-\s*(.+)$/m', $m[1], $reqMatches);
            foreach ($reqMatches[1] as $req) {
                $reqs[] = trim($req);
            }
            $data['requirements'] = $reqs;
        }
        
        // Extract execution mode/timeout
        if (preg_match('/execution:\s*\n((?:\s{2,}.*\n?)*)/m', $content, $m)) {
            $execution = [];
            $executionBlock = $m[1];

            if (preg_match('/^\s+mode:\s*["\']?([a-zA-Z]+)["\']?\s*$/m', $executionBlock, $modeMatch)) {
                $execution['mode'] = trim($modeMatch[1]);
            }
            if (preg_match('/^\s+timeout:\s*(\d+)\s*$/m', $executionBlock, $timeoutMatch)) {
                $execution['timeout'] = (int)$timeoutMatch[1];
            }

            if (!empty($execution)) {
                $data['execution'] = $execution;
            }
        }

        $capabilityHints = $this->extractCapabilityHintsManual($content);
        if ($capabilityHints !== []) {
            $data['capabilityHints'] = $capabilityHints;
        }
        
        if (empty($data['id'])) return null;
        
        return $this->normalizeTemplate($data, $filePath, $source, $basePath);
    }
    
    /**
     * Extract inputs from YAML content manually
     */
    private function extractInputsManual(string $content): array {
        $inputs = [];
        
        // Find the inputs section
        if (!preg_match('/inputs:\s*\n((?:[\s\S]*?))(?=\n[a-z]|\z)/m', $content, $match)) {
            return $inputs;
        }
        
        $inputsSection = $match[1];
        
        // Split by "- name:" to get individual inputs
        $parts = preg_split('/^\s+-\s+name:\s*/m', $inputsSection, -1, PREG_SPLIT_NO_EMPTY);
        
        foreach ($parts as $part) {
            $input = [];
            $lines = explode("\n", $part);
            
            // First line is the name
            $input['name'] = trim($lines[0]);
            
            // Parse remaining properties
            foreach ($lines as $line) {
                if (preg_match('/^\s+(\w+):\s*(.+)$/', $line, $m)) {
                    $key = $m[1];
                    $value = trim($m[2], "\"' ");
                    
                    if ($key === 'required') {
                        $input[$key] = $value === 'true';
                    } elseif ($key === 'default' && is_numeric($value)) {
                        $input[$key] = (int)$value;
                    } else {
                        $input[$key] = $value;
                    }
                }
            }
            
            if (!empty($input['name'])) {
                $inputs[] = $input;
            }
        }
        
        return $inputs;
    }

    private function extractCapabilityHintsManual(string $content): array {
        if (!preg_match('/^capabilityHints:\s*\n((?:\s{2,}.*(?:\n|$))*)/m', $content, $match)) {
            return [];
        }

        $block = $match[1];
        $capabilityHints = [];

        foreach (['capabilities', 'payloadHandling'] as $field) {
            $values = $this->extractYamlListFieldManual($block, $field);
            if ($values !== []) {
                $capabilityHints[$field] = $values;
            }
        }

        if (preg_match('/^\s{2}scriptRuntime:\s*(.+)\s*$/m', $block, $runtimeMatch)) {
            $scriptRuntime = trim($runtimeMatch[1], "\"' ");
            if ($scriptRuntime !== '') {
                $capabilityHints['scriptRuntime'] = $scriptRuntime;
            }
        }

        $dataFlow = $this->extractCapabilityDataFlowManual($block);
        if ($dataFlow !== []) {
            $capabilityHints['dataFlow'] = $dataFlow;
        }

        return $capabilityHints;
    }

    private function extractCapabilityDataFlowManual(string $block): array {
        if (!preg_match('/^\s{2}dataFlow:\s*\n((?:\s{4,}.*(?:\n|$))*)/m', $block, $match)) {
            return [];
        }

        $dataFlowBlock = $match[1];
        $dataFlow = [];

        if (preg_match('/^\s{4}scriptInput:\s*(.+)\s*$/m', $dataFlowBlock, $scriptMatch)) {
            $scriptInput = trim($scriptMatch[1], "\"' ");
            if ($scriptInput !== '') {
                $dataFlow['scriptInput'] = $scriptInput;
            }
        }

        foreach (['requestPathInput', 'requestHeadersInput'] as $field) {
            if (preg_match('/^\s{4}' . preg_quote($field, '/') . ':\s*(.+)\s*$/m', $dataFlowBlock, $fieldMatch)) {
                $value = trim($fieldMatch[1], "\"' ");
                if ($value !== '') {
                    $dataFlow[$field] = $value;
                }
            }
        }

        foreach (['payloadValueMode', 'payloadRawSuffix'] as $field) {
            if (preg_match('/^\s{4}' . preg_quote($field, '/') . ':\s*(.+)\s*$/m', $dataFlowBlock, $fieldMatch)) {
                $value = trim($fieldMatch[1], "\"' ");
                if ($value !== '') {
                    $dataFlow[$field] = $value;
                }
            }
        }

        $payloadInputs = $this->extractYamlListFieldManual($dataFlowBlock, 'payloadInputs');
        if ($payloadInputs !== []) {
            $dataFlow['payloadInputs'] = $payloadInputs;
        }

        return $dataFlow;
    }

    private function extractYamlListFieldManual(string $block, string $fieldName): array {
        $pattern = '/^\s{2}' . preg_quote($fieldName, '/') . ':\s*\n((?:\s{4}-\s*.+(?:\n|$))*)/m';
        if (!preg_match($pattern, $block, $match)) {
            return [];
        }

        $values = [];
        preg_match_all('/^\s{4}-\s*(.+)\s*$/m', $match[1], $valueMatches);
        foreach ($valueMatches[1] as $value) {
            $trimmed = trim($value, "\"' ");
            if ($trimmed !== '' && !in_array($trimmed, $values, true)) {
                $values[] = $trimmed;
            }
        }

        return $values;
    }

    private function normalizeCapabilityHints($capabilityHints): array {
        $normalized = [
            'capabilities' => [],
            'payloadHandling' => [],
            'scriptRuntime' => null,
            'dataFlow' => [
                'scriptInput' => null,
                'payloadInputs' => [],
                'requestPathInput' => null,
                'requestHeadersInput' => null,
                'payloadValueMode' => null,
                'payloadRawSuffix' => null,
            ],
        ];

        if (!is_array($capabilityHints)) {
            return $normalized;
        }

        foreach (['capabilities', 'payloadHandling'] as $field) {
            $normalized[$field] = $this->normalizeStringList($capabilityHints[$field] ?? []);
        }

        if (isset($capabilityHints['scriptRuntime']) && is_string($capabilityHints['scriptRuntime'])) {
            $scriptRuntime = trim($capabilityHints['scriptRuntime']);
            if ($scriptRuntime !== '') {
                $normalized['scriptRuntime'] = $scriptRuntime;
            }
        }

        if (is_array($capabilityHints['dataFlow'] ?? null)) {
            $dataFlow = $capabilityHints['dataFlow'];
            if (isset($dataFlow['scriptInput']) && is_string($dataFlow['scriptInput'])) {
                $scriptInput = trim($dataFlow['scriptInput']);
                if ($scriptInput !== '') {
                    $normalized['dataFlow']['scriptInput'] = $scriptInput;
                }
            }
            $normalized['dataFlow']['payloadInputs'] = $this->normalizeStringList($dataFlow['payloadInputs'] ?? []);
            foreach (['requestPathInput', 'requestHeadersInput', 'payloadValueMode', 'payloadRawSuffix'] as $field) {
                if (isset($dataFlow[$field]) && is_string($dataFlow[$field])) {
                    $value = trim($dataFlow[$field]);
                    if ($value !== '') {
                        $normalized['dataFlow'][$field] = $value;
                    }
                }
            }
        }

        return $normalized;
    }

    private function normalizeStringList($value): array {
        if (is_string($value)) {
            $value = [$value];
        }

        if (!is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                continue;
            }

            $trimmed = trim($item);
            if ($trimmed !== '' && !in_array($trimmed, $normalized, true)) {
                $normalized[] = $trimmed;
            }
        }

        return $normalized;
    }

    private function buildCapabilityTemplateSummary(array $template): array {
        $hints = $template['capabilityHints'] ?? $this->normalizeCapabilityHints([]);

        return [
            'id' => $template['id'],
            'name' => $template['name'],
            'category' => $template['category'] ?? 'core',
            'source' => $template['source'] ?? 'builtin',
            'targetType' => $template['targetType'] ?? null,
            'capabilities' => $hints['capabilities'],
            'payloadHandling' => $hints['payloadHandling'],
            'scriptRuntime' => $hints['scriptRuntime'],
            'dataFlow' => $hints['dataFlow'],
        ];
    }

    private function addTemplateSummaryToRegistryBucket(array &$bucket, string $key, array $summary): void {
        $key = trim($key);
        if ($key === '') {
            return;
        }

        if (!isset($bucket[$key])) {
            $bucket[$key] = [
                'name' => $key,
                'count' => 0,
                'templates' => [],
            ];
        }

        $bucket[$key]['templates'][$summary['id']] = $summary;
        $bucket[$key]['count'] = count($bucket[$key]['templates']);
    }

    private function normalizeRegistryBucket(array $bucket): array {
        ksort($bucket);

        foreach ($bucket as &$entry) {
            $templates = array_values($entry['templates']);
            usort($templates, fn($a, $b) => strcasecmp($a['name'], $b['name']));
            $entry['templates'] = $templates;
            $entry['count'] = count($templates);
        }
        unset($entry);

        return $bucket;
    }

    private function filterTemplatesByCapabilityField(string $field, string $value): array {
        $value = trim($value);
        if ($value === '') {
            return [];
        }

        $result = $this->getTemplates();
        if (!$result['success']) {
            return [];
        }

        return array_values(array_filter($result['templates'], function (array $template) use ($field, $value) {
            $hints = $template['capabilityHints'] ?? $this->normalizeCapabilityHints([]);
            return in_array($value, $hints[$field] ?? [], true);
        }));
    }
    
    /**
     * Normalize and enhance template data
     */
    private function normalizeTemplate(array $data, string $filePath, string $source = 'builtin', ?string $basePath = null): ?array {
        // Required fields
        if (empty($data['id']) || empty($data['name'])) {
            return null;
        }
        
        // Calculate relative path
        $resolvedBasePath = $basePath ? realpath($basePath) : realpath($this->getTemplatesDir());
        $relativePath = $resolvedBasePath
            ? str_replace($resolvedBasePath . '/', '', $filePath)
            : basename($filePath);
        
        // Determine category from path or folder
        $pathParts = explode('/', dirname($relativePath));
        $category = ($pathParts[0] !== '.' && $pathParts[0] !== '')
            ? $pathParts[0]
            : ($source === 'custom' ? 'custom' : 'core');
        
        return [
            'id' => $data['id'],
            'name' => $data['name'],
            'version' => $data['version'] ?? '1.0.0',
            'description' => $data['description'] ?? '',
            'icon' => $data['icon'] ?? 'fas fa-cog',
            'color' => $data['color'] ?? '#6c757d',
            'category' => $category,
            'path' => $relativePath,
            'targetType' => $data['targetType'] ?? null,  // Optional: db, docker, http, etc.
            'runtime' => $data['runtime'] ?? ['image' => 'alpine:latest'],
            'inputs' => $data['inputs'] ?? [],
            'execution' => $data['execution'] ?? [],
            'output' => $data['output'] ?? ['type' => 'text'],
            'requirements' => $data['requirements'] ?? [],
            'targetBindings' => $data['targetBindings'] ?? [],
            'capabilityHints' => $this->normalizeCapabilityHints($data['capabilityHints'] ?? []),
            'source' => $source,
        ];
    }
    
    /**
     * Get template categories
     */
    public function getCategories(): array {
        $result = $this->getTemplates();
        if (!$result['success']) return [];
        
        $categories = [];
        foreach ($result['templates'] as $template) {
            $cat = $template['category'];
            if (!isset($categories[$cat])) {
                $categories[$cat] = [
                    'id' => $cat,
                    'name' => ucfirst($cat),
                    'count' => 0
                ];
            }
            $categories[$cat]['count']++;
        }
        
        return array_values($categories);
    }
    
    /**
     * Validate template inputs against provided values
     */
    public function validateInputs(string $templateId, array $values): array {
        $template = $this->getTemplate($templateId);
        if (!$template) {
            return ['valid' => false, 'errors' => ['Template not found']];
        }
        
        $errors = [];
        
        foreach ($template['inputs'] as $input) {
            $name = $input['name'];
            $value = $values[$name] ?? null;
            
            // Check required
            if (($input['required'] ?? false) && ($value === null || $value === '')) {
                $errors[] = "'{$input['label']}' is required";
                continue;
            }
            
            // Skip validation if not provided and not required
            if ($value === null || $value === '') continue;
            
            // Type validation
            $type = $input['type'] ?? 'string';
            
            if ($type === 'number' && !is_numeric($value)) {
                $errors[] = "'{$input['label']}' must be a number";
            }
            
            // Validation rules
            if (isset($input['validation'])) {
                $validation = $input['validation'];
                
                if (isset($validation['min']) && is_numeric($value) && $value < $validation['min']) {
                    $errors[] = "'{$input['label']}' must be at least {$validation['min']}";
                }
                
                if (isset($validation['max']) && is_numeric($value) && $value > $validation['max']) {
                    $errors[] = "'{$input['label']}' must be at most {$validation['max']}";
                }
                
                if (isset($validation['maxLength']) && strlen($value) > $validation['maxLength']) {
                    $errors[] = "'{$input['label']}' exceeds maximum length of {$validation['maxLength']}";
                }
                
                if (isset($validation['pattern'])) {
                    // Use # as delimiter to avoid issues with patterns containing /
                    $pattern = '#' . $validation['pattern'] . '#';
                    if (@preg_match($pattern, $value) === false || !preg_match($pattern, $value)) {
                        $errors[] = "'{$input['label']}' format is invalid";
                    }
                }
            }
        }
        
        return ['valid' => empty($errors), 'errors' => $errors];
    }
}
