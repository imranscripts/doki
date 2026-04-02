<?php
/**
 * SystemSettingsManager.php - Doki-wide settings storage and normalization
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/SystemModuleRegistry.php';

class SystemSettingsManager {
    private const KEY_MODULES = 'doki.modules';
    private const KEY_MODULES_OPTIONS = 'doki.modules.options';
    private const KEY_STEALTH = 'doki.security.stealth';

    private PDO $db;
    private ?array $moduleSettingsCache = null;
    private ?array $stealthSettingsCache = null;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function getModuleSettings(): array {
        if ($this->moduleSettingsCache !== null) {
            return $this->moduleSettingsCache;
        }

        $definitions = SystemModuleRegistry::getModuleDefinitions();
        $stored = $this->getJsonSetting(self::KEY_MODULES, []);
        $normalized = [];
        $defaultOrder = 10;

        foreach ($definitions as $moduleId => $definition) {
            $raw = is_array($stored[$moduleId] ?? null) ? $stored[$moduleId] : [];
            $allowedSections = $this->getAllowedSectionsForModule($definition);
            $defaultSection = $definition['defaultSection'] ?? SystemModuleRegistry::SECTION_HIDDEN;

            $section = (string)($raw['section'] ?? $defaultSection);
            if (
                $moduleId === 'studio'
                && $section === SystemModuleRegistry::SECTION_ADMINISTRATION
                && in_array(SystemModuleRegistry::SECTION_STUDIO, $allowedSections, true)
            ) {
                // Migrate older installs that still remember App Studio under Administration.
                $section = SystemModuleRegistry::SECTION_STUDIO;
            }
            if (!in_array($section, $allowedSections, true)) {
                $section = $defaultSection;
            }

            $order = isset($raw['order']) && is_numeric($raw['order'])
                ? (int)$raw['order']
                : $defaultOrder;

            $normalized[$moduleId] = [
                'id' => $moduleId,
                'enabled' => array_key_exists('enabled', $raw) ? (bool)$raw['enabled'] : true,
                'section' => $section,
                'order' => $order,
            ];

            $defaultOrder += 10;
        }

        $this->moduleSettingsCache = $this->normalizeModuleOrdering($normalized);
        return $this->moduleSettingsCache;
    }

    public function getModuleEditorData(): array {
        $definitions = SystemModuleRegistry::getModuleDefinitions();
        $sectionDefinitions = SystemModuleRegistry::getSectionDefinitions();
        $sectionOrder = array_flip(array_keys($sectionDefinitions));
        $settings = $this->getModuleSettings();
        $modules = [];

        foreach ($settings as $moduleId => $setting) {
            $definition = $definitions[$moduleId] ?? null;
            if (!$definition) {
                continue;
            }

            $allowedSections = $this->getAllowedSectionsForModule($definition);
            $modules[] = [
                'id' => $moduleId,
                'label' => $definition['label'] ?? $moduleId,
                'navLabel' => $definition['navLabel'] ?? ($definition['label'] ?? $moduleId),
                'description' => $definition['description'] ?? '',
                'icon' => $definition['icon'] ?? 'fa-puzzle-piece',
                'path' => $definition['path'] ?? null,
                'roles' => $definition['roles'] ?? [],
                'sidebarVisible' => !empty($definition['sidebarVisible']),
                'fixedSection' => !empty($definition['fixedSection']),
                'section' => $setting['section'],
                'sectionLabel' => $sectionDefinitions[$setting['section']]['label'] ?? ucfirst($setting['section']),
                'enabled' => !empty($setting['enabled']),
                'order' => (int)$setting['order'],
                'allowedSections' => array_map(static function(string $sectionId) use ($sectionDefinitions): array {
                    return [
                        'id' => $sectionId,
                        'label' => $sectionDefinitions[$sectionId]['label'] ?? ucfirst($sectionId),
                    ];
                }, $allowedSections),
            ];
        }

        usort($modules, static function(array $a, array $b) use ($sectionOrder): int {
            $aSectionOrder = $sectionOrder[$a['section']] ?? PHP_INT_MAX;
            $bSectionOrder = $sectionOrder[$b['section']] ?? PHP_INT_MAX;
            $sectionCompare = $aSectionOrder <=> $bSectionOrder;
            if ($sectionCompare !== 0) {
                return $sectionCompare;
            }
            if ((int)$a['order'] !== (int)$b['order']) {
                return (int)$a['order'] <=> (int)$b['order'];
            }
            return strcasecmp((string)$a['label'], (string)$b['label']);
        });

        return $modules;
    }

    public function getModuleOptions(): array {
        $stored = $this->getJsonSetting(self::KEY_MODULES_OPTIONS, []);

        return [
            'applyToSuperAdmin' => array_key_exists('applyToSuperAdmin', $stored)
                ? (bool)$stored['applyToSuperAdmin']
                : true,
        ];
    }

    public function saveModuleSettings(array $modules, string $userId): array {
        $definitions = SystemModuleRegistry::getModuleDefinitions();
        $current = $this->getModuleSettings();
        $sectionBuckets = [];
        $prepared = [];

        foreach ($modules as $module) {
            if (!is_array($module)) {
                continue;
            }

            $moduleId = trim((string)($module['id'] ?? ''));
            if ($moduleId === '' || !isset($definitions[$moduleId])) {
                continue;
            }

            $definition = $definitions[$moduleId];
            $allowedSections = $this->getAllowedSectionsForModule($definition);
            $defaultSection = $definition['defaultSection'] ?? SystemModuleRegistry::SECTION_HIDDEN;
            $requestedSection = trim((string)($module['section'] ?? $defaultSection));
            $section = in_array($requestedSection, $allowedSections, true) ? $requestedSection : $defaultSection;

            $prepared[$moduleId] = [
                'id' => $moduleId,
                'enabled' => array_key_exists('enabled', $module) ? (bool)$module['enabled'] : !empty($current[$moduleId]['enabled']),
                'section' => $section,
            ];
            $sectionBuckets[$section][] = $moduleId;
        }

        foreach ($definitions as $moduleId => $definition) {
            if (isset($prepared[$moduleId])) {
                continue;
            }

            $prepared[$moduleId] = [
                'id' => $moduleId,
                'enabled' => !empty($current[$moduleId]['enabled']),
                'section' => $current[$moduleId]['section'] ?? ($definition['defaultSection'] ?? SystemModuleRegistry::SECTION_HIDDEN),
            ];
            $sectionBuckets[$prepared[$moduleId]['section']][] = $moduleId;
        }

        $order = 10;
        $ordered = [];
        $sectionOrder = array_keys(SystemModuleRegistry::getSectionDefinitions());
        foreach ($sectionOrder as $sectionId) {
            $moduleIds = $sectionBuckets[$sectionId] ?? [];
            foreach ($moduleIds as $moduleId) {
                $ordered[$moduleId] = [
                    'enabled' => !empty($prepared[$moduleId]['enabled']),
                    'section' => $prepared[$moduleId]['section'],
                    'order' => $order,
                ];
                $order += 10;
            }
        }

        $this->setJsonSetting(self::KEY_MODULES, $ordered, $userId);
        $this->moduleSettingsCache = null;

        return $this->getModuleSettings();
    }

    public function saveModuleOptions(array $options, string $userId): array {
        $normalized = [
            'applyToSuperAdmin' => array_key_exists('applyToSuperAdmin', $options)
                ? (bool)$options['applyToSuperAdmin']
                : true,
        ];

        $this->setJsonSetting(self::KEY_MODULES_OPTIONS, $normalized, $userId);
        return $this->getModuleOptions();
    }

    public function isModuleEnabled(string $moduleId): bool {
        $settings = $this->getModuleSettings();
        return !empty($settings[$moduleId]['enabled']);
    }

    public function shouldApplyModuleSettingsToRole(string $role): bool {
        if ($role !== 'super-admin') {
            return true;
        }

        return !empty($this->getModuleOptions()['applyToSuperAdmin']);
    }

    public function isModuleEnabledForRole(string $moduleId, string $role): bool {
        if (!$this->shouldApplyModuleSettingsToRole($role)) {
            return true;
        }

        return $this->isModuleEnabled($moduleId);
    }

    public function getSidebarModulesBySection(?string $role = null): array {
        $definitions = SystemModuleRegistry::getModuleDefinitions();
        $sectionDefinitions = SystemModuleRegistry::getSectionDefinitions();
        $settings = $this->getModuleSettings();
        $grouped = [];
        $effectiveRole = is_string($role) && $role !== '' ? $role : null;

        foreach ($sectionDefinitions as $sectionId => $sectionDefinition) {
            if (empty($sectionDefinition['sidebar'])) {
                continue;
            }
            $grouped[$sectionId] = [
                'id' => $sectionId,
                'label' => $sectionDefinition['label'],
                'modules' => [],
            ];
        }

        foreach ($settings as $moduleId => $setting) {
            $definition = $definitions[$moduleId] ?? null;
            if (!$definition || empty($definition['sidebarVisible'])) {
                continue;
            }
            if ($effectiveRole !== null) {
                if (!$this->isModuleEnabledForRole($moduleId, $effectiveRole)) {
                    continue;
                }
            } elseif (empty($setting['enabled'])) {
                continue;
            }
            if (($setting['section'] ?? '') === SystemModuleRegistry::SECTION_HIDDEN) {
                continue;
            }
            if ($moduleId === 'dashboard' || $moduleId === 'apps') {
                continue;
            }

            $sectionId = $setting['section'] ?? ($definition['defaultSection'] ?? SystemModuleRegistry::SECTION_ADMINISTRATION);
            if (!isset($grouped[$sectionId])) {
                continue;
            }

            $grouped[$sectionId]['modules'][] = [
                'id' => $moduleId,
                'label' => $definition['navLabel'] ?? $definition['label'] ?? $moduleId,
                'icon' => $definition['icon'] ?? 'fa-puzzle-piece',
                'path' => $definition['path'] ?? '',
                'roles' => $definition['roles'] ?? [],
                'dividerBefore' => !empty($definition['dividerBefore']),
                'order' => (int)$setting['order'],
            ];
        }

        foreach ($grouped as &$section) {
            usort($section['modules'], static function(array $a, array $b): int {
                if ((int)$a['order'] !== (int)$b['order']) {
                    return (int)$a['order'] <=> (int)$b['order'];
                }
                return strcasecmp((string)$a['label'], (string)$b['label']);
            });
        }
        unset($section);

        return $grouped;
    }

    public function getStealthSettings(): array {
        if ($this->stealthSettingsCache !== null) {
            return $this->stealthSettingsCache;
        }

        $stored = $this->getJsonSetting(self::KEY_STEALTH, []);
        $defaults = $this->getDefaultStealthSettings();
        $settings = [
            'enabled' => array_key_exists('enabled', $stored) ? (bool)$stored['enabled'] : $defaults['enabled'],
            'windowSeconds' => $this->normalizeIntSetting($stored['windowSeconds'] ?? null, $defaults['windowSeconds'], 30, 3600),
            'keystrokeTtl' => $this->normalizeIntSetting($stored['keystrokeTtl'] ?? null, $defaults['keystrokeTtl'], 5, 300),
            'maxAttempts' => $this->normalizeIntSetting($stored['maxAttempts'] ?? null, $defaults['maxAttempts'], 1, 20),
            'lockoutBaseMinutes' => $this->normalizeIntSetting($stored['lockoutBaseMinutes'] ?? null, $defaults['lockoutBaseMinutes'], 1, 180),
            'lockoutMaxMinutes' => $this->normalizeIntSetting($stored['lockoutMaxMinutes'] ?? null, $defaults['lockoutMaxMinutes'], 1, 720),
        ];

        if ($settings['lockoutMaxMinutes'] < $settings['lockoutBaseMinutes']) {
            $settings['lockoutMaxMinutes'] = $settings['lockoutBaseMinutes'];
        }

        $this->stealthSettingsCache = $settings;
        return $settings;
    }

    public function getRuntimeStealthSettings(): array {
        $configured = $this->getStealthSettings();
        $enabledOverride = $this->getBoolEnvOverride('STEALTH_MODE');
        $windowOverride = $this->getIntEnvOverride('STEALTH_WINDOW');
        $keystrokeTtlOverride = $this->getIntEnvOverride('STEALTH_KEYSTROKE_TTL');
        $maxAttemptsOverride = $this->getIntEnvOverride('STEALTH_MAX_ATTEMPTS');
        $lockoutBaseOverride = $this->getIntEnvOverride('STEALTH_LOCKOUT_BASE');
        $lockoutMaxOverride = $this->getIntEnvOverride('STEALTH_LOCKOUT_MAX');

        $runtime = [
            'enabled' => $enabledOverride ?? $configured['enabled'],
            'windowSeconds' => $windowOverride ?? $configured['windowSeconds'],
            'keystrokeTtl' => $keystrokeTtlOverride ?? $configured['keystrokeTtl'],
            'maxAttempts' => $maxAttemptsOverride ?? $configured['maxAttempts'],
            'lockoutBaseMinutes' => $lockoutBaseOverride ?? $configured['lockoutBaseMinutes'],
            'lockoutMaxMinutes' => $lockoutMaxOverride ?? $configured['lockoutMaxMinutes'],
            'configured' => $configured,
            'overrideSources' => [
                'enabled' => $enabledOverride !== null ? 'env' : 'settings',
                'windowSeconds' => $windowOverride !== null ? 'env' : 'settings',
                'keystrokeTtl' => $keystrokeTtlOverride !== null ? 'env' : 'settings',
                'maxAttempts' => $maxAttemptsOverride !== null ? 'env' : 'settings',
                'lockoutBaseMinutes' => $lockoutBaseOverride !== null ? 'env' : 'settings',
                'lockoutMaxMinutes' => $lockoutMaxOverride !== null ? 'env' : 'settings',
            ],
        ];

        if ($runtime['lockoutMaxMinutes'] < $runtime['lockoutBaseMinutes']) {
            $runtime['lockoutMaxMinutes'] = $runtime['lockoutBaseMinutes'];
        }

        return $runtime;
    }

    public function saveStealthSettings(array $input, string $userId): array {
        $settings = [
            'enabled' => !empty($input['enabled']),
            'windowSeconds' => $this->normalizeIntSetting($input['windowSeconds'] ?? null, 120, 30, 3600),
            'keystrokeTtl' => $this->normalizeIntSetting($input['keystrokeTtl'] ?? null, 30, 5, 300),
            'maxAttempts' => $this->normalizeIntSetting($input['maxAttempts'] ?? null, 5, 1, 20),
            'lockoutBaseMinutes' => $this->normalizeIntSetting($input['lockoutBaseMinutes'] ?? null, 5, 1, 180),
            'lockoutMaxMinutes' => $this->normalizeIntSetting($input['lockoutMaxMinutes'] ?? null, 60, 1, 720),
        ];

        if ($settings['lockoutMaxMinutes'] < $settings['lockoutBaseMinutes']) {
            $settings['lockoutMaxMinutes'] = $settings['lockoutBaseMinutes'];
        }

        $this->setJsonSetting(self::KEY_STEALTH, $settings, $userId);
        $this->stealthSettingsCache = null;

        return $this->getRuntimeStealthSettings();
    }

    private function getAllowedSectionsForModule(array $definition): array {
        if (empty($definition['sidebarVisible'])) {
            return [SystemModuleRegistry::SECTION_HIDDEN];
        }

        if (!empty($definition['fixedSection'])) {
            return [$definition['defaultSection'] ?? SystemModuleRegistry::SECTION_HIDDEN];
        }

        if (!empty($definition['allowedSections']) && is_array($definition['allowedSections'])) {
            $validSections = array_keys(SystemModuleRegistry::getSectionDefinitions());
            $allowed = array_values(array_filter(
                array_map(static fn($section): string => (string)$section, $definition['allowedSections']),
                static fn(string $section): bool => in_array($section, $validSections, true)
            ));

            if ($allowed !== []) {
                return array_values(array_unique($allowed));
            }
        }

        return [
            SystemModuleRegistry::SECTION_WORKFLOWS,
            SystemModuleRegistry::SECTION_CONFIGURATION,
            SystemModuleRegistry::SECTION_ADMINISTRATION,
            SystemModuleRegistry::SECTION_HIDDEN,
        ];
    }

    private function normalizeModuleOrdering(array $settings): array {
        $grouped = [];
        foreach ($settings as $moduleId => $setting) {
            $grouped[$setting['section']][] = [
                'id' => $moduleId,
                'enabled' => !empty($setting['enabled']),
                'section' => $setting['section'],
                'order' => (int)$setting['order'],
            ];
        }

        $normalized = [];
        $sectionOrder = array_keys(SystemModuleRegistry::getSectionDefinitions());
        foreach ($sectionOrder as $sectionId) {
            $modules = $grouped[$sectionId] ?? [];
            usort($modules, static function(array $a, array $b): int {
                if ((int)$a['order'] !== (int)$b['order']) {
                    return (int)$a['order'] <=> (int)$b['order'];
                }
                return strcmp((string)$a['id'], (string)$b['id']);
            });

            $order = 10;
            foreach ($modules as $module) {
                $normalized[$module['id']] = [
                    'id' => $module['id'],
                    'enabled' => !empty($module['enabled']),
                    'section' => $sectionId,
                    'order' => $order,
                ];
                $order += 10;
            }
        }

        return $normalized;
    }

    private function getDefaultStealthSettings(): array {
        return [
            'enabled' => true,
            'windowSeconds' => 120,
            'keystrokeTtl' => 30,
            'maxAttempts' => 5,
            'lockoutBaseMinutes' => 5,
            'lockoutMaxMinutes' => 60,
        ];
    }

    private function getJsonSetting(string $key, $default) {
        $stmt = $this->db->prepare("SELECT value FROM system_settings WHERE key = ? LIMIT 1");
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return $default;
        }

        $decoded = json_decode((string)$row['value'], true);
        return $decoded === null && json_last_error() !== JSON_ERROR_NONE ? $default : $decoded;
    }

    private function setJsonSetting(string $key, $value, string $userId): void {
        $stmt = $this->db->prepare("
            INSERT INTO system_settings (key, value, updated_by)
            VALUES (?, ?, ?)
            ON CONFLICT(key) DO UPDATE SET
                value = excluded.value,
                updated_at = datetime('now'),
                updated_by = excluded.updated_by
        ");
        $stmt->execute([
            $key,
            json_encode($value, JSON_UNESCAPED_SLASHES),
            $userId,
        ]);
    }

    private function normalizeIntSetting($value, int $default, int $min, int $max): int {
        if (!is_numeric($value)) {
            return $default;
        }
        $intValue = (int)$value;
        return max($min, min($max, $intValue));
    }

    private function getBoolEnvOverride(string $envName): ?bool {
        if (!$this->envIsSet($envName)) {
            return null;
        }

        $value = strtolower(trim((string)getenv($envName)));
        if ($value === '') {
            return null;
        }

        if (in_array($value, ['0', 'false', 'off', 'no'], true)) {
            return false;
        }
        if (in_array($value, ['1', 'true', 'on', 'yes'], true)) {
            return true;
        }

        return null;
    }

    private function getIntEnvOverride(string $envName): ?int {
        if (!$this->envIsSet($envName)) {
            return null;
        }

        $value = trim((string)getenv($envName));
        if ($value === '' || !is_numeric($value)) {
            return null;
        }

        return (int)$value;
    }

    private function envIsSet(string $envName): bool {
        return getenv($envName) !== false;
    }
}
