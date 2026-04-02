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
        $templatesById = [];
        $roots = $this->getTemplateRoots();

        if ($roots === []) {
            return ['success' => false, 'error' => 'Templates directory not found: ' . $this->getTemplatesDir(), 'templates' => []];
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

        return ['success' => true, 'templates' => $templates];
    }
    
    /**
     * Get a single template by ID
     */
    public function getTemplate(string $id): ?array {
        $result = $this->getTemplates();
        if (!$result['success']) return null;
        
        foreach ($result['templates'] as $template) {
            if ($template['id'] === $id) {
                return $template;
            }
        }
        
        return null;
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
