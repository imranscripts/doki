<?php
/**
 * Layout.php - Shared Layout Components
 * 
 * Part of Doki v3 Architecture - Batch 1: Auth & UI Foundation
 */

require_once __DIR__ . '/AppManager.php';
require_once __DIR__ . '/PermissionManager.php';
require_once __DIR__ . '/SystemModuleRegistry.php';
require_once __DIR__ . '/SystemSettingsManager.php';

class Layout {
    private array $user;
    private string $currentPage;

    public function __construct(array $user, string $currentPage = '') {
        $this->user = $user;
        $this->currentPage = $currentPage;
    }

    /**
     * Get navigation items based on user role
     */
    public function getNavItems(): array {
        $role = $this->user['role'];
        
        // Determine base path based on current script location
        $scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
        $basePath = '';
        
        // Count directory depth from root
        if (strpos($scriptPath, '/apps/') !== false) {
            // Inside an app: /apps/{appId}/page.php -> need ../../
            $basePath = '../../';
        } elseif (strpos($scriptPath, '/admin/') !== false || strpos($scriptPath, '/config/') !== false) {
            // Inside admin or config: /admin/page.php -> need ../
            $basePath = '../';
        }
        
        // Dynamically load enabled apps (enriched with priority, pinned, tags, lastUsed)
        // Pass user ID to get user-specific preferences
        $appManager = new AppManager();
        $userId = $this->user['id'] ?? null;
        $enabledApps = $appManager->getEnabledApps($userId);
        $appNavItems = [];
        
        foreach ($enabledApps as $app) {
            $appId = $app['id'] ?? '';
            $appIcon = $app['icon'] ?? 'fa-puzzle-piece';
            // Remove 'fas ' prefix if present
            $appIcon = str_replace('fas ', '', $appIcon);
            
            $appNavItems[] = [
                'id' => 'app-' . $appId,
                'icon' => $appIcon,
                'label' => $app['name'] ?? ucfirst($appId),
                'href' => $basePath . 'apps/' . $appId . '/index.php',
                'roles' => ['super-admin', 'admin', 'user'],
                // New fields for organization
                'priority' => $app['priority'] ?? 50,
                'pinned' => $app['pinned'] ?? false,
                'tags' => $app['tags'] ?? [],
                'lastUsed' => $app['lastUsed'] ?? null,
                'sidebarOrder' => $app['sidebarOrder'] ?? null,  // Include user's custom order
                'appId' => $appId,  // For tracking usage
            ];
        }
        
        // Get custom order from enriched apps (includes user preferences)
        // The enriched apps already have sidebarOrder from user preferences or global
        $customOrder = [];
        foreach ($appNavItems as $item) {
            $appId = $item['appId'] ?? '';
            if ($appId && isset($item['sidebarOrder'])) {
                $customOrder[$appId] = $item['sidebarOrder'];
            }
        }
        
        // Sort apps: custom order first, then pinned, then priority, then alphabetically
        usort($appNavItems, function($a, $b) use ($customOrder) {
            $aId = $a['appId'] ?? '';
            $bId = $b['appId'] ?? '';
            
            // Custom order takes precedence (if both have custom order)
            $aOrder = $customOrder[$aId] ?? null;
            $bOrder = $customOrder[$bId] ?? null;
            
            if ($aOrder !== null && $bOrder !== null) {
                return $aOrder <=> $bOrder;
            }
            if ($aOrder !== null) return -1;  // Custom ordered items first
            if ($bOrder !== null) return 1;
            
            // Pinned apps next
            if ($a['pinned'] && !$b['pinned']) return -1;
            if (!$a['pinned'] && $b['pinned']) return 1;
            
            // Then by priority (lower number = higher priority)
            if ($a['priority'] !== $b['priority']) {
                return $a['priority'] <=> $b['priority'];
            }
            
            // Finally alphabetically
            return strcasecmp($a['label'], $b['label']);
        });
        
        $settingsManager = new SystemSettingsManager();
        $moduleDefinitions = SystemModuleRegistry::getModuleDefinitions();
        $sectionDefinitions = SystemModuleRegistry::getSectionDefinitions();
        $configuredSections = $settingsManager->getSidebarModulesBySection($role);
        $applyModuleSettingsToRole = $settingsManager->shouldApplyModuleSettingsToRole($role);

        $homeDefinition = $moduleDefinitions['dashboard'] ?? null;
        $homeItem = null;
        if (is_array($homeDefinition) && !empty($homeDefinition['path'])) {
            $homeItem = [
                'id' => 'dashboard',
                'moduleId' => 'dashboard',
                'icon' => $homeDefinition['icon'] ?? 'fa-house',
                'label' => $homeDefinition['navLabel'] ?? ($homeDefinition['label'] ?? 'Home'),
                'searchLabel' => $homeDefinition['searchLabel'] ?? ($homeDefinition['label'] ?? 'Home'),
                'href' => $basePath . ltrim((string)$homeDefinition['path'], '/'),
                'roles' => $homeDefinition['roles'] ?? ['super-admin', 'admin', 'user'],
            ];
        }

        $items = [];
        foreach ($sectionDefinitions as $sectionId => $sectionDefinition) {
            if ($sectionId === SystemModuleRegistry::SECTION_HOME || $sectionId === SystemModuleRegistry::SECTION_HIDDEN) {
                continue;
            }

            if ($sectionId === SystemModuleRegistry::SECTION_APPS) {
                if (!$settingsManager->isModuleEnabledForRole('apps', $role)) {
                    continue;
                }
                $appsDefinition = $moduleDefinitions['apps'] ?? null;
                $appsSectionItems = [];
                if (is_array($appsDefinition) && !empty($appsDefinition['path'])) {
                    $appsSectionItems[] = [
                        'id' => 'apps-page',
                        'moduleId' => 'apps',
                        'icon' => $appsDefinition['icon'] ?? 'fa-th-large',
                        'label' => $appsDefinition['navLabel'] ?? ($appsDefinition['label'] ?? 'Browse All Apps'),
                        'href' => $basePath . ltrim((string)$appsDefinition['path'], '/'),
                        'roles' => $appsDefinition['roles'] ?? ['super-admin', 'admin', 'user'],
                    ];
                }

                if (!empty($appsSectionItems) || !empty($appNavItems)) {
                    $items[] = [
                        'id' => $sectionId,
                        'section' => $sectionDefinition['label'],
                        'items' => array_merge($appsSectionItems, $appNavItems),
                    ];
                }
                continue;
            }

            $configuredSection = $configuredSections[$sectionId]['modules'] ?? [];
            $sectionItems = [];
            foreach ($configuredSection as $module) {
                $path = trim((string)($module['path'] ?? ''));
                if ($path === '') {
                    continue;
                }

                $sectionItems[] = [
                    'id' => (string)$module['id'],
                    'moduleId' => (string)$module['id'],
                    'icon' => $module['icon'] ?? 'fa-puzzle-piece',
                    'label' => $module['label'] ?? (string)$module['id'],
                    'href' => $basePath . ltrim($path, '/'),
                    'roles' => $module['roles'] ?? ['super-admin', 'admin', 'user'],
                    'dividerBefore' => !empty($module['dividerBefore']),
                ];
            }

            if (!empty($sectionItems)) {
                $items[] = [
                    'id' => $sectionId,
                    'section' => $configuredSections[$sectionId]['label'] ?? $sectionDefinition['label'],
                    'items' => $sectionItems,
                ];
            }
        }

        // Filter based on role
        $filteredItems = [];
        $permManager = new PermissionManager();
        $user = $this->user;

        foreach ($items as $section) {
            if ($section['id'] === SystemModuleRegistry::SECTION_APPS && ($role !== 'super-admin' || $applyModuleSettingsToRole)) {
                if (!$permManager->canAccessResource($user, 'module', 'apps')) {
                    continue;
                }
            }

            // First pass: role-based filter
            $filteredSectionItems = array_filter($section['items'], function($item) use ($role) {
                return in_array($role, $item['roles']);
            });

            // Second pass: granular permission filter (skip for super-admin)
            if ($role !== 'super-admin' || $applyModuleSettingsToRole) {
                $filteredSectionItems = array_filter($filteredSectionItems, function($item) use ($user, $permManager) {
                    // App items: check 'app' resource type
                    if (isset($item['appId'])) {
                        return $permManager->canAccessResource($user, 'app', $item['appId']);
                    }
                    // Module items: check 'module' resource type using the item id
                    $moduleId = $item['moduleId'] ?? ($item['id'] ?? '');
                    if ($moduleId) {
                        return $permManager->canAccessResource($user, 'module', $moduleId);
                    }
                    return true;
                });
            }

            if (!empty($filteredSectionItems)) {
                $filteredItems[] = [
                    'id' => $section['id'] ?? null,
                    'section' => $section['section'],
                    'items' => array_values($filteredSectionItems)
                ];
            }
        }

        $homeVisible = is_array($homeItem) && in_array($role, $homeItem['roles'], true);
        if ($homeVisible && ($role !== 'super-admin' || $applyModuleSettingsToRole) && is_array($homeItem)) {
            $homeVisible = $permManager->canAccessResource($user, 'module', $homeItem['moduleId'] ?? 'dashboard');
        }

        return [
            'home' => $homeVisible ? $homeItem : null,
            'sections' => $filteredItems,
        ];
    }
    
    /**
     * Get all unique tags from enabled apps
     * Used for tag filtering in sidebar (Batch 2)
     */
    public function getAllAppTags(): array {
        $appManager = new AppManager();
        return $appManager->getAllTags();
    }
    
    /**
     * Darken a hex color by 20% for gradient
     */
    public function darkenColor(string $hex): string {
        // Remove # if present
        $hex = ltrim($hex, '#');
        
        // Convert to RGB
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        
        // Darken by 20%
        $r = max(0, min(255, $r * 0.8));
        $g = max(0, min(255, $g * 0.8));
        $b = max(0, min(255, $b * 0.8));
        
        // Convert back to hex
        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }
    
    /**
     * Get apps grouped by pinned/recent/all
     * Used for sidebar organization (Batch 2)
     */
    public function getOrganizedApps(?array $filteredApps = null): array {
        if ($filteredApps !== null) {
            $apps = $filteredApps;
        } else {
            $appManager = new AppManager();
            $userId = $this->user['id'] ?? null;
            $apps = $appManager->getEnabledApps($userId);
        }
        
        $pinned = [];
        $recent = [];
        $all = [];
        
        // Sort by priority first (lower = higher priority)
        usort($apps, function($a, $b) {
            return ($a['priority'] ?? 50) <=> ($b['priority'] ?? 50);
        });
        
        // Get pinned apps (exclude from recent/all)
        $pinned = array_filter($apps, fn($a) => !empty($a['pinned']));
        $pinnedIds = array_map(fn($a) => $a['id'], $pinned);
        
        // Get last 5 used apps (sorted by lastUsed desc, exclude pinned)
        $allWithLastUsed = array_filter($apps, function($a) use ($pinnedIds) {
            return !empty($a['lastUsed']) && !in_array($a['id'], $pinnedIds);
        });
        usort($allWithLastUsed, function($a, $b) {
            return strtotime($b['lastUsed'] ?? '1970-01-01') <=> strtotime($a['lastUsed'] ?? '1970-01-01');
        });
        $recent = array_slice($allWithLastUsed, 0, 5);
        
        // All apps (already sorted by priority)
        $all = $apps;
        
        return [
            'pinned' => array_values($pinned),
            'recent' => array_values($recent),
            'all' => array_values($all),
        ];
    }

    /**
     * Render the head section
     */
    public function renderHead(string $title = 'Doki'): void {
        $embedMode = $this->isEmbedMode();
        ?>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <script>
            // Set sidebar state immediately to prevent flash - runs before body renders
            (function() {
                // Embed mode: hide sidebar entirely
                if (<?= $embedMode ? 'true' : 'false' ?>) {
                    document.documentElement.setAttribute('data-embed-mode', 'true');
                    document.addEventListener('DOMContentLoaded', function() {
                        document.body.classList.add('embed-mode');
                    });
                    return;
                }
                // Check if we should collapse sidebar (desktop only)
                if (window.innerWidth > 1024) {
                    try {
                        const savedState = localStorage.getItem('sidebarCollapsed');
                        if (savedState === 'true') {
                            // Add class to body immediately so CSS can apply
                            document.documentElement.setAttribute('data-sidebar-collapsed', 'true');
                        }
                    } catch(e) {
                        // localStorage not available, ignore
                    }
                }
            })();
        </script>
        <style>
            /* Apply collapsed state immediately if data attribute is set - prevents flash */
            html[data-sidebar-collapsed="true"] .sidebar {
                width: var(--sidebar-collapsed-width) !important;
            }
            
            html[data-sidebar-collapsed="true"] .sidebar .sidebar-logo h1,
            html[data-sidebar-collapsed="true"] .sidebar .nav-item span:not(.icon),
            html[data-sidebar-collapsed="true"] .sidebar .nav-section-title,
            html[data-sidebar-collapsed="true"] .sidebar .user-details,
            html[data-sidebar-collapsed="true"] .sidebar .logout-btn span,
            html[data-sidebar-collapsed="true"] .sidebar .app-pinned-badge,
            html[data-sidebar-collapsed="true"] .sidebar .drag-handle {
                display: none !important;
            }

            html[data-sidebar-collapsed="true"] .sidebar .sidebar-search {
                display: none !important;
            }
            
            html[data-sidebar-collapsed="true"] .sidebar .sidebar-header {
                padding: 16px;
                justify-content: center;
                flex-direction: column;
                gap: 12px;
            }
            
            html[data-sidebar-collapsed="true"] .sidebar .sidebar-logo {
                justify-content: center;
                width: 100%;
            }
            
            html[data-sidebar-collapsed="true"] .sidebar .nav-item {
                justify-content: center;
                align-items: center;
                padding: 0;
                margin: 0 0 8px 0;
                border-radius: 12px;
                height: 48px;
                width: 100%;
            }

            html[data-sidebar-collapsed="true"] .sidebar .apps-list {
                margin: 0;
                padding: 0;
            }
            
            html[data-sidebar-collapsed="true"] .sidebar .sidebar-toggle-btn {
                width: 100% !important;
                height: 40px !important;
                min-width: auto !important;
                font-size: 14px !important;
            }
            
            html[data-sidebar-collapsed="true"] .sidebar .sidebar-toggle-btn i {
                font-size: 14px !important;
            }
            
            html[data-sidebar-collapsed="true"] .main-content {
                margin-left: var(--sidebar-collapsed-width) !important;
            }
            
            html[data-sidebar-collapsed="true"] body.sidebar-collapsed .main-content {
                margin-left: var(--sidebar-collapsed-width) !important;
            }

            /* Embed mode: no sidebar, no app header, full-width content */
            html[data-embed-mode="true"] .sidebar,
            html[data-embed-mode="true"] .mobile-menu-toggle,
            html[data-embed-mode="true"] .app-header,
            body.embed-mode .sidebar,
            body.embed-mode .mobile-menu-toggle,
            body.embed-mode .app-header {
                display: none !important;
            }

            html[data-embed-mode="true"] .main-content,
            body.embed-mode .main-content {
                margin-left: 0 !important;
                width: 100% !important;
                max-width: 100% !important;
                padding: 0 !important;
            }
        </style>
        <title><?= htmlspecialchars($title) ?> - Doki</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>
            <?php $this->renderBaseStyles(); ?>
        </style>
        <?php
    }

    /**
     * Render base CSS styles
     */
    private function renderBaseStyles(): void {
        ?>
        :root {
            --bg-primary: #0d1117;
            --bg-secondary: #161b22;
            --bg-tertiary: #21262d;
            --bg-hover: #30363d;
            --text-primary: #f0f6fc;
            --text-secondary: #8b949e;
            --text-muted: #6e7681;
            --accent-primary: #58a6ff;
            --accent-secondary: #388bfd;
            --accent-glow: rgba(88, 166, 255, 0.15);
            --success: #3fb950;
            --error: #f85149;
            --warning: #d29922;
            --border-primary: #30363d;
            --border-secondary: #21262d;
            --sidebar-width: 260px;
            --sidebar-collapsed-width: 76px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            scrollbar-width: thin;
            scrollbar-color: var(--bg-hover) var(--bg-secondary);
        }

        html {
            background: var(--bg-primary);
            color-scheme: dark;
        }

        body {
            font-family: 'Outfit', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* Chrome/Safari scrollbar styling */
        *::-webkit-scrollbar {
            width: 10px;
            height: 10px;
        }

        *::-webkit-scrollbar-track {
            background: var(--bg-secondary);
        }

        *::-webkit-scrollbar-thumb {
            background-color: var(--bg-hover);
            border-radius: 999px;
            border: 2px solid var(--bg-secondary);
        }

        *::-webkit-scrollbar-thumb:hover {
            background-color: var(--border-primary);
        }

        *::-webkit-scrollbar-corner {
            background: var(--bg-secondary);
        }

        /* Layout */
        .app-container {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar */
        .sidebar {
            width: var(--sidebar-width);
            background: var(--bg-secondary);
            border-right: 1px solid var(--border-primary);
            display: flex;
            flex-direction: column;
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            z-index: 100;
            transition: width 0.3s ease, transform 0.3s ease;
            overflow: hidden;
        }

        .sidebar.collapsed {
            width: var(--sidebar-collapsed-width);
        }

        .sidebar.collapsed .sidebar-logo h1,
        .sidebar.collapsed .nav-item span:not(.icon),
        .sidebar.collapsed .nav-section-title,
        .sidebar.collapsed .user-details,
        .sidebar.collapsed .logout-btn span,
        .sidebar.collapsed .app-pinned-badge,
        .sidebar.collapsed .drag-handle {
            display: none !important;
        }

        .sidebar.collapsed .sidebar-search {
            display: none;
        }

        .sidebar.collapsed .sidebar-header {
            padding: 16px 10px;
            justify-content: center;
            flex-direction: column;
            gap: 12px;
        }

        .sidebar.collapsed .sidebar-logo {
            justify-content: center;
            width: 100%;
            padding: 0;
            margin: 0;
        }

        .sidebar.collapsed .sidebar-logo:hover {
            opacity: 0.8;
        }

        .sidebar.collapsed .sidebar-logo .logo-icon {
            width: 52px;
            height: 52px;
            font-size: 24px;
            margin: 0 auto;
        }

        .sidebar.collapsed .sidebar-toggle-btn {
            width: 100%;
            height: 40px;
            margin-top: 8px;
            border-radius: 10px;
            transition: all 0.2s ease;
        }

        .sidebar.collapsed .sidebar-toggle-btn:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .sidebar.collapsed .sidebar-nav {
            padding: 16px 6px;
        }

        .sidebar.collapsed .nav-section {
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--border-secondary);
        }

        .sidebar.collapsed .nav-section:last-child {
            margin-bottom: 0;
            padding-bottom: 0;
            border-bottom: 0;
        }

        .sidebar.collapsed .nav-item {
            justify-content: center;
            align-items: center;
            padding: 0;
            margin: 0 0 8px 0;
            border-radius: 12px;
            height: 48px;
            width: 100%;
            position: relative;
            display: flex;
            flex-direction: row;
        }

        .sidebar.collapsed .apps-list {
            margin: 0;
            padding: 0;
        }

        .sidebar.collapsed .nav-item:hover {
            background: var(--bg-tertiary);
            transform: scale(1.05);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .sidebar.collapsed .nav-item.active {
            background: var(--accent-glow);
            box-shadow: 0 0 0 2px var(--accent-primary);
        }

        .sidebar.collapsed .nav-item .icon {
            margin: 0;
            padding: 0;
            font-size: 22px;
            width: 22px;
            height: 22px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            line-height: 1;
        }

        .sidebar.collapsed .nav-item .icon i {
            display: flex;
            align-items: center;
            justify-content: center;
            line-height: 1;
            margin: 0;
            padding: 0;
            width: 22px;
            height: 22px;
        }

        /* Enhanced tooltips for collapsed sidebar */
        .sidebar.collapsed {
            overflow: hidden;
        }

        .sidebar.collapsed .nav-item-wrapper {
            position: relative;
        }

        .sidebar.collapsed .nav-item[data-title],
        .sidebar.collapsed .sidebar-logo[data-title],
        .sidebar.collapsed .user-info[data-title],
        .sidebar.collapsed .logout-btn[data-title],
        .sidebar.collapsed .sidebar-toggle-btn[data-title] {
            position: relative;
        }

        .sidebar-tooltip {
            position: fixed;
            background: var(--bg-primary);
            color: var(--text-primary);
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            white-space: nowrap;
            z-index: 10000;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
            border: 1px solid var(--border-primary);
            pointer-events: none;
            opacity: 0;
            transition: opacity 0.2s ease;
            transform: translateY(-50%);
        }

        .sidebar-tooltip.visible {
            opacity: 1;
        }

        .sidebar-tooltip::before {
            content: '';
            position: absolute;
            left: -6px;
            top: 50%;
            transform: translateY(-50%);
            width: 0;
            height: 0;
            border-top: 6px solid transparent;
            border-bottom: 6px solid transparent;
            border-right: 6px solid var(--border-primary);
        }

        /* Calculate tooltip position dynamically via JavaScript */
        .sidebar.collapsed .sidebar-header {
            position: relative;
        }

        .sidebar.collapsed .sidebar-footer {
            position: relative;
        }

        @keyframes tooltipFadeIn {
            from {
                opacity: 0;
                transform: translateY(-50%) translateX(-4px);
            }
            to {
                opacity: 1;
                transform: translateY(-50%) translateX(0);
            }
        }

        .sidebar.collapsed .nav-item-wrapper {
            position: relative;
        }

        .sidebar.collapsed .user-info {
            justify-content: center;
            flex-direction: column;
            gap: 8px;
            padding: 0;
        }

        .sidebar.collapsed .sidebar-footer {
            padding: 14px 6px;
        }

        .sidebar.collapsed .user-avatar {
            margin: 0 auto;
            width: 52px;
            height: 52px;
            font-size: 18px;
            transition: transform 0.2s ease;
        }

        .sidebar.collapsed .user-info:hover .user-avatar {
            transform: scale(1.1);
        }

        .sidebar.collapsed .logout-btn {
            padding: 14px;
            width: 100%;
            min-width: auto;
            justify-content: center;
            border-radius: 12px;
            min-height: 48px;
            transition: all 0.2s ease;
        }

        .sidebar.collapsed .logout-btn:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 12px rgba(248, 81, 73, 0.2);
        }

        .sidebar.collapsed .logout-btn i {
            font-size: 20px;
            margin: 0;
        }

        .sidebar.collapsed .sidebar-toggle-btn i {
            transform: rotate(180deg);
        }

        .sidebar-header {
            padding: 20px;
            border-bottom: 1px solid var(--border-primary);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            transition: padding 0.3s ease;
        }

        .sidebar-logo {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            flex: 1;
            transition: all 0.3s ease;
        }

        .sidebar-logo .logo-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--accent-primary), var(--accent-secondary));
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            color: var(--bg-primary);
        }

        .sidebar-logo h1 {
            font-family: 'JetBrains Mono', monospace;
            font-size: 24px;
            font-weight: 600;
            color: var(--text-primary);
            transition: opacity 0.3s ease, width 0.3s ease;
            white-space: nowrap;
        }

        .sidebar-toggle-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            min-width: 32px;
            min-height: 32px;
            background: var(--bg-tertiary);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            color: var(--text-secondary);
            cursor: pointer;
            transition: all 0.2s ease;
            flex-shrink: 0;
            font-size: 14px;
            box-sizing: border-box;
        }

        .sidebar.collapsed .sidebar-toggle-btn {
            width: 100% !important;
            height: 40px !important;
            min-width: auto !important;
            min-height: 40px !important;
            border-radius: 10px;
            margin-top: 8px;
            transition: all 0.2s ease;
            box-sizing: border-box;
        }

        .sidebar.collapsed .sidebar-toggle-btn:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .sidebar-toggle-btn:hover {
            background: var(--bg-hover);
            border-color: var(--accent-primary);
            color: var(--accent-primary);
        }

        .sidebar-toggle-btn i {
            transition: transform 0.3s ease;
        }

        .sidebar-search {
            padding: 16px;
            border-bottom: 1px solid var(--border-primary);
        }

        .sidebar-search-field {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 0 12px;
            border: 1px solid var(--border-primary);
            border-radius: 10px;
            background: var(--bg-tertiary);
            transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
        }

        .sidebar-search-field:focus-within {
            border-color: var(--accent-primary);
            box-shadow: 0 0 0 3px rgba(88, 166, 255, 0.12);
            background: rgba(33, 38, 45, 0.92);
        }

        .sidebar-search-icon {
            color: var(--text-muted);
            font-size: 14px;
            flex-shrink: 0;
        }

        .sidebar-search-field:focus-within .sidebar-search-icon {
            color: var(--accent-primary);
        }

        .sidebar-search-input {
            width: 100%;
            min-width: 0;
            padding: 12px 0;
            border: 0;
            background: transparent;
            color: var(--text-primary);
            font-size: 14px;
            outline: none;
        }

        .sidebar-search-input::placeholder {
            color: var(--text-muted);
        }

        .sidebar-search-input::-webkit-search-cancel-button {
            display: none;
        }

        .sidebar-search-clear {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 24px;
            height: 24px;
            border: 0;
            border-radius: 6px;
            background: transparent;
            color: var(--text-muted);
            cursor: pointer;
            flex-shrink: 0;
            transition: background 0.2s ease, color 0.2s ease;
        }

        .sidebar-search-clear:hover {
            background: rgba(240, 246, 252, 0.08);
            color: var(--text-primary);
        }

        .sidebar-search-clear[hidden] {
            display: none !important;
        }

        .sidebar-search-empty {
            margin: 4px 12px 0;
            padding: 12px;
            border: 1px dashed var(--border-primary);
            border-radius: 10px;
            color: var(--text-muted);
            font-size: 13px;
            text-align: center;
            background: rgba(22, 27, 34, 0.7);
        }

        .sidebar-search-active .nav-section-divider,
        .sidebar-search-active .apps-drop-zone {
            display: none !important;
        }

        .sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }

        .sidebar-nav {
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
            padding: 16px 12px;
        }

        .apps-list {
            margin: 0 -12px;
            padding: 0 12px;
        }

        .apps-list .nav-item-wrapper {
            margin: 0;
        }

        .apps-list .nav-item {
            padding: 12px;
            margin-bottom: 4px;
        }

        .nav-home {
            margin-bottom: 18px;
            padding-bottom: 14px;
            border-bottom: 1px solid var(--border-secondary);
        }

        .nav-home[hidden] {
            display: none !important;
        }

        .nav-section {
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--border-secondary);
        }

        .nav-section:last-child {
            margin-bottom: 0;
            padding-bottom: 0;
            border-bottom: 0;
        }

        .nav-section-title {
            font-size: 11px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 0 12px;
            margin-bottom: 8px;
            transition: opacity 0.3s ease, width 0.3s ease;
            white-space: nowrap;
        }

        .nav-section-divider {
            height: 1px;
            margin: 10px 12px 12px;
            background: linear-gradient(90deg, transparent 0%, var(--border-secondary) 12%, var(--border-secondary) 88%, transparent 100%);
            opacity: 0.9;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            border-radius: 8px;
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s ease;
            margin-bottom: 4px;
            position: relative;
        }

        .nav-item span:not(.icon) {
            transition: opacity 0.3s ease, width 0.3s ease;
            white-space: nowrap;
        }

        .nav-item:hover {
            background: var(--bg-tertiary);
            color: var(--text-primary);
        }

        .nav-item.active {
            background: var(--accent-glow);
            color: var(--accent-primary);
        }

        .nav-item .icon {
            width: 20px;
            text-align: center;
            font-size: 16px;
            flex-shrink: 0;
        }

        /* Pinned app badge in sidebar */
        .app-pinned-badge {
            display: inline-flex;
            align-items: center;
            margin-left: auto;
            color: var(--accent-primary);
            font-size: 12px;
        }

        /* Drag and Drop for Apps */
        .nav-item-wrapper {
            position: relative;
        }

        .sidebar.collapsed .nav-item-wrapper {
            display: flex;
            justify-content: center;
            align-items: center;
            width: 100%;
            height: 48px;
        }

        .nav-item-wrapper.draggable {
            cursor: move;
        }

        .sidebar.collapsed .nav-item-wrapper.draggable {
            cursor: pointer;
        }

        .nav-item-wrapper.dragging {
            opacity: 0.5;
        }

        .nav-item-wrapper.drag-over {
            border-top: 2px solid var(--accent-primary);
        }

        .nav-item-wrapper.drag-over-bottom {
            border-bottom: 2px solid var(--accent-primary);
            border-top: none;
        }

        .apps-drop-zone {
            height: 4px;
            margin: 2px 0;
            border-radius: 2px;
            background: transparent;
            transition: background 0.2s;
        }

        .apps-drop-zone.drag-over {
            background: var(--accent-primary);
        }

        .drag-handle {
            display: inline-flex;
            align-items: center;
            padding: 4px;
            margin-right: 4px;
            color: var(--text-muted);
            cursor: grab;
            opacity: 0;
            transition: opacity 0.2s;
            width: 0;
            overflow: hidden;
        }

        .nav-item-wrapper:hover .drag-handle,
        .nav-item-wrapper.dragging .drag-handle {
            width: auto;
            overflow: visible;
        }

        .sidebar.collapsed .drag-handle {
            display: none;
        }

        .drag-handle:active {
            cursor: grabbing;
        }

        .nav-item-wrapper:hover .drag-handle {
            opacity: 1;
        }

        .nav-item-wrapper.dragging .drag-handle {
            opacity: 1;
        }

        /* User section at bottom of sidebar */
        .sidebar-footer {
            padding: 16px;
            border-top: 1px solid var(--border-primary);
            transition: padding 0.3s ease;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            transition: all 0.3s ease;
            background: var(--bg-tertiary);
            border-radius: 10px;
            margin-bottom: 12px;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--accent-primary), var(--accent-secondary));
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            color: var(--bg-primary);
            font-weight: 600;
        }

        .user-details {
            flex: 1;
            min-width: 0;
            transition: opacity 0.3s ease, width 0.3s ease;
            white-space: nowrap;
        }

        .user-name {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-primary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .user-role {
            font-size: 12px;
            color: var(--text-muted);
            text-transform: capitalize;
        }

        .logout-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            padding: 12px;
            background: transparent;
            border: 1px solid var(--border-primary);
            border-radius: 8px;
            color: var(--text-secondary);
            font-family: inherit;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .logout-btn:hover {
            background: rgba(248, 81, 73, 0.1);
            border-color: var(--error);
            color: var(--error);
        }

        .logout-btn span {
            transition: opacity 0.3s ease, width 0.3s ease;
            white-space: nowrap;
        }

        /* Main content */
        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            width: calc(100% - var(--sidebar-width));
            max-width: calc(100% - var(--sidebar-width));
            min-width: 0;
            padding: 32px;
            transition: margin-left 0.3s ease;
        }

        .sidebar.collapsed ~ .main-content,
        body.sidebar-collapsed .main-content {
            margin-left: var(--sidebar-collapsed-width);
            width: calc(100% - var(--sidebar-collapsed-width));
            max-width: calc(100% - var(--sidebar-collapsed-width));
        }

        /* Page header */
        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 32px;
        }

        .page-title {
            font-size: 28px;
            font-weight: 600;
        }

        .page-subtitle {
            color: var(--text-secondary);
            font-size: 15px;
            margin-top: 4px;
        }

        /* Cards */
        .card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-primary);
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
        }

        .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
        }

        .card-title {
            font-size: 16px;
            font-weight: 600;
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 16px;
            border-radius: 8px;
            font-family: inherit;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.15s ease;
            text-decoration: none;
            border: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--accent-primary), var(--accent-secondary));
            color: var(--bg-primary);
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(88, 166, 255, 0.3);
        }

        .btn-secondary {
            background: var(--bg-tertiary);
            border: 1px solid var(--border-primary);
            color: var(--text-primary);
        }

        .btn-secondary:hover {
            background: var(--bg-hover);
        }

        .btn-danger {
            background: rgba(248, 81, 73, 0.1);
            border: 1px solid var(--error);
            color: var(--error);
        }

        .btn-danger:hover {
            background: rgba(248, 81, 73, 0.2);
        }

        .btn-info {
            background: rgba(59, 130, 246, 0.1);
            border: 1px solid #3b82f6;
            color: #3b82f6;
        }

        .btn-info:hover {
            background: rgba(59, 130, 246, 0.2);
        }

        /* Tables */
        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table th,
        .table td {
            padding: 12px 16px;
            text-align: left;
            border-bottom: 1px solid var(--border-primary);
        }

        .table th {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .table tr:hover td {
            background: var(--bg-tertiary);
        }

        /* Form elements */
        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            font-size: 13px;
            font-weight: 500;
            color: var(--text-secondary);
            margin-bottom: 8px;
        }

        .form-input,
        .form-select,
        .form-textarea {
            width: 100%;
            padding: 12px 16px;
            background: var(--bg-tertiary);
            border: 2px solid var(--border-primary);
            border-radius: 8px;
            color: var(--text-primary);
            font-family: inherit;
            font-size: 14px;
            transition: all 0.15s ease;
        }

        .form-input:focus,
        .form-select:focus,
        .form-textarea:focus {
            outline: none;
            border-color: var(--accent-primary);
            box-shadow: 0 0 0 3px var(--accent-glow);
        }

        /* Badges */
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }

        .badge-success {
            background: rgba(63, 185, 80, 0.15);
            color: var(--success);
        }

        .badge-warning {
            background: rgba(210, 153, 34, 0.15);
            color: var(--warning);
        }

        .badge-error {
            background: rgba(248, 81, 73, 0.15);
            color: var(--error);
        }

        .badge-info {
            background: var(--accent-glow);
            color: var(--accent-primary);
        }

        /* Toast notifications - Always on top of everything */
        .toast-container {
            position: fixed;
            top: 24px;
            right: 24px;
            z-index: 10000; /* Above modals (z-index: 1000) */
            max-width: 400px;
            pointer-events: none;
        }

        .toast {
            background: var(--bg-secondary);
            border: 1px solid var(--border-primary);
            border-radius: 12px;
            padding: 16px 20px;
            margin-bottom: 12px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.4);
            animation: toastSlideIn 0.3s ease;
            pointer-events: auto;
        }

        .toast.hiding {
            animation: toastSlideOut 0.3s ease forwards;
        }

        @keyframes toastSlideIn {
            from { transform: translateX(120%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        @keyframes toastSlideOut {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(120%); opacity: 0; }
        }

        .toast .toast-icon {
            font-size: 18px;
            flex-shrink: 0;
            margin-top: 2px;
        }

        .toast .toast-content {
            flex: 1;
            min-width: 0;
        }

        .toast .toast-title {
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 4px;
        }

        .toast .toast-message {
            font-size: 13px;
            color: var(--text-secondary);
            word-break: break-word;
        }

        .toast .toast-close {
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            padding: 4px;
            margin: -4px -4px -4px 8px;
            font-size: 14px;
            flex-shrink: 0;
        }

        .toast .toast-close:hover {
            color: var(--text-primary);
        }

        .toast.success { border-left: 4px solid var(--success); }
        .toast.success .toast-icon { color: var(--success); }

        .toast.error { border-left: 4px solid var(--error); }
        .toast.error .toast-icon { color: var(--error); }

        .toast.warning { border-left: 4px solid var(--warning); }
        .toast.warning .toast-icon { color: var(--warning); }

        .toast.info { border-left: 4px solid var(--accent-primary); }
        .toast.info .toast-icon { color: var(--accent-primary); }

        .toast.success .toast-icon { color: var(--success); }
        .toast.error .toast-icon { color: var(--error); }
        .toast.warning .toast-icon { color: var(--warning); }

        /* Mobile menu toggle */
        .mobile-menu-toggle {
            display: none;
            position: fixed;
            top: 16px;
            left: -22px;
            z-index: 101;
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, var(--bg-secondary) 0%, var(--bg-tertiary) 100%);
            border: 2px solid var(--border-primary);
            border-left: none;
            border-radius: 0 12px 12px 0;
            color: var(--text-primary);
            font-size: 20px;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 4px 0 12px rgba(0, 0, 0, 0.15), inset -2px 0 8px rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(10px);
            overflow: hidden;
            mask-image: linear-gradient(to right, transparent 0%, rgba(0, 0, 0, 1) 30%);
            -webkit-mask-image: linear-gradient(to right, transparent 0%, rgba(0, 0, 0, 1) 30%);
        }

        .mobile-menu-toggle::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, var(--accent-primary) 0%, var(--accent-secondary) 100%);
            opacity: 0;
            transition: opacity 0.3s ease;
            z-index: -1;
            mask-image: linear-gradient(to right, transparent 0%, rgba(0, 0, 0, 1) 30%);
            -webkit-mask-image: linear-gradient(to right, transparent 0%, rgba(0, 0, 0, 1) 30%);
        }

        .mobile-menu-toggle:hover {
            left: -16px;
            border-color: var(--accent-primary);
            color: white;
            box-shadow: 6px 0 16px rgba(99, 102, 241, 0.3), inset -2px 0 8px rgba(255, 255, 255, 0.1);
            transform: scale(1.05);
        }

        .mobile-menu-toggle:hover::before {
            opacity: 1;
        }

        .mobile-menu-toggle:active {
            transform: scale(0.95);
            left: -20px;
        }

        .mobile-menu-toggle i {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 1;
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.3s ease;
        }

        .mobile-menu-toggle .icon-close {
            opacity: 0;
            transform: translate(-50%, -50%) translateX(-8px);
        }

        .mobile-menu-toggle .icon-open {
            opacity: 1;
            transform: translate(-50%, -50%) translateX(0);
        }

        .sidebar.open ~ * .mobile-menu-toggle .icon-open,
        body:has(.sidebar.open) .mobile-menu-toggle .icon-open {
            opacity: 0;
            transform: translate(-50%, -50%) translateX(8px);
        }

        .sidebar.open ~ * .mobile-menu-toggle .icon-close,
        body:has(.sidebar.open) .mobile-menu-toggle .icon-close {
            opacity: 1;
            transform: translate(-50%, -50%) translateX(0);
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .sidebar {
                transform: translateX(-100%);
                width: var(--sidebar-width) !important;
            }

            .sidebar.open {
                transform: translateX(0);
            }

            /* Force expanded state on mobile - ignore collapsed class */
            .sidebar.collapsed {
                width: var(--sidebar-width) !important;
            }

            .sidebar.collapsed .sidebar-logo h1,
            .sidebar.collapsed .nav-item span:not(.icon),
            .sidebar.collapsed .nav-section-title,
            .sidebar.collapsed .user-details,
            .sidebar.collapsed .logout-btn span,
            .sidebar.collapsed .app-pinned-badge,
            .sidebar.collapsed .drag-handle {
                display: block !important;
                opacity: 1 !important;
                width: auto !important;
                overflow: visible !important;
            }

            .sidebar.collapsed .sidebar-search {
                display: block !important;
            }

            .sidebar.collapsed .sidebar-logo {
                justify-content: flex-start !important;
            }

            .sidebar.collapsed .nav-item {
                justify-content: flex-start !important;
                padding: 12px !important;
            }

            .sidebar.collapsed .nav-item .icon {
                width: 20px !important;
                font-size: 16px !important;
            }

            .sidebar.collapsed .user-info {
                justify-content: flex-start !important;
                flex-direction: row !important;
            }

            .sidebar.collapsed .sidebar-header {
                flex-direction: row !important;
                justify-content: space-between !important;
            }

            .sidebar.collapsed .logout-btn {
                width: 100% !important;
                justify-content: flex-start !important;
            }

            .main-content {
                margin-left: 0;
                width: 100%;
                max-width: 100%;
            }

            body.sidebar-collapsed .main-content {
                margin-left: 0;
                width: 100%;
                max-width: 100%;
            }

            .mobile-menu-toggle {
                display: flex;
                align-items: center;
                justify-content: center;
                left: -22px;
            }

            .mobile-menu-toggle:hover {
                left: -18px;
            }

            .sidebar-toggle-btn {
                display: none !important;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 24px 16px;
                padding-top: 72px;
            }

            .page-title {
                font-size: 24px;
            }
        }

        /* App Header Styles (Playwright-style) */
        .app-header {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 32px;
            padding-bottom: 24px;
            border-bottom: 1px solid var(--border-color);
        }

        .app-header .app-icon {
            width: 56px;
            height: 56px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
            flex-shrink: 0;
        }

        .app-header .app-title {
            flex: 1;
        }

        .app-header .app-title h1 {
            font-size: 24px;
            margin: 0 0 4px 0;
            font-weight: 600;
            color: var(--text-primary);
        }

        .app-header .app-title p {
            color: var(--text-secondary);
            margin: 0;
            font-size: 14px;
        }

        /* App Navigation Styles */
        .app-nav {
            display: flex;
            gap: 8px;
            margin-bottom: 24px;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 12px;
        }

        .app-nav .app-nav-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 8px;
            color: var(--text-secondary);
            text-decoration: none;
            transition: all 0.2s;
            font-size: 14px;
            font-weight: 500;
        }

        .app-nav .app-nav-item:hover {
            background: var(--bg-tertiary);
            color: var(--text-primary);
        }

        .app-nav .app-nav-item.active {
            background: var(--accent-primary);
            color: white;
        }

        .app-nav .app-nav-item i {
            font-size: 14px;
        }
        <?php
    }

    /**
     * Render the sidebar navigation
     */
    public function isEmbedMode(): bool {
        if (!empty($_GET['embed'])) {
            return true;
        }

        $scriptFilename = $_SERVER['SCRIPT_FILENAME'] ?? '';
        if (!is_string($scriptFilename) || $scriptFilename === '') {
            return false;
        }

        $normalized = str_replace('\\', '/', $scriptFilename);
        return preg_match('#/studio-previews/[a-z0-9_-]+(?:/|$)#i', $normalized) === 1;
    }

    public function renderSidebar(): void {
        if ($this->isEmbedMode()) {
            return;
        }
        $navData = $this->getNavItems();
        $homeItem = $navData['home'] ?? null;
        $navItems = $navData['sections'] ?? [];
        $initials = $this->getInitials($this->user['name']);
        ?>
        <!-- Mobile menu toggle -->
        <button class="mobile-menu-toggle" onclick="toggleSidebar()">
            <i class="fas fa-chevron-right icon-open"></i>
            <i class="fas fa-chevron-left icon-close"></i>
        </button>

        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar"<?php 
            // Set initial collapsed state inline to prevent flash
            if (isset($_COOKIE['sidebarCollapsed']) && $_COOKIE['sidebarCollapsed'] === 'true') {
                // Check localStorage via inline script before body renders
                echo ' data-initial-state="check"';
            }
        ?>>
            <div class="sidebar-header">
                <a href="/" class="sidebar-logo" data-title="Home">
                    <div class="logo-icon">
                        <i class="fas fa-terminal"></i>
                    </div>
                    <h1>doki</h1>
                </a>
                <button class="sidebar-toggle-btn" id="sidebar-toggle" onclick="toggleSidebarCollapse()" data-title="Toggle sidebar">
                    <i class="fas fa-chevron-left"></i>
                </button>
            </div>

            <div class="sidebar-search">
                <label class="sr-only" for="sidebar-search-input">Search</label>
                <div class="sidebar-search-field">
                    <span class="sidebar-search-icon" aria-hidden="true">
                        <i class="fas fa-search"></i>
                    </span>
                    <input
                        type="search"
                        id="sidebar-search-input"
                        class="sidebar-search-input"
                        placeholder="Search"
                        autocomplete="off"
                        spellcheck="false"
                        aria-controls="sidebar-nav"
                    >
                    <button
                        type="button"
                        class="sidebar-search-clear"
                        id="sidebar-search-clear"
                        aria-label="Clear sidebar search"
                        hidden
                    >
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>

            <nav class="sidebar-nav" id="sidebar-nav">
                <?php if (is_array($homeItem)): ?>
                <div class="nav-home" id="sidebar-home-item">
                    <a href="<?= htmlspecialchars($homeItem['href']) ?>"
                       class="nav-item <?= $this->currentPage === $homeItem['id'] ? 'active' : '' ?>"
                       data-search-item="true"
                       data-search-label="<?= htmlspecialchars($homeItem['searchLabel'] ?? $homeItem['label']) ?>"
                       data-title="<?= htmlspecialchars($homeItem['label']) ?>">
                        <span class="icon"><i class="fas <?= $homeItem['icon'] ?>"></i></span>
                        <span><?= htmlspecialchars($homeItem['label']) ?></span>
                    </a>
                </div>
                <?php endif; ?>
                <?php foreach ($navItems as $section): ?>
                <div class="nav-section">
                    <div class="nav-section-title"><?= htmlspecialchars($section['section']) ?></div>
                    <?php if (($section['id'] ?? '') === SystemModuleRegistry::SECTION_APPS): ?>
                        <!-- Apps section with drag-and-drop -->
                        <div class="apps-list" id="apps-list">
                            <?php foreach ($section['items'] as $item): 
                                // Check if this is an app item (has pinned badge or appId)
                                $isApp = isset($item['pinned']) || isset($item['appId']);
                                $showPinnedBadge = ($isApp && ($item['pinned'] ?? false));
                                $isAppItem = isset($item['appId']) && $item['id'] !== 'apps-page';
                                $isDraggable = $isAppItem;
                            ?>
                            <div class="nav-item-wrapper <?= $isDraggable ? 'draggable' : '' ?>" 
                                 data-search-item="true"
                                 data-search-label="<?= htmlspecialchars($section['section'] . ' ' . $item['label']) ?>"
                                 <?= $isDraggable ? 'draggable="true" data-app-id="' . htmlspecialchars($item['appId']) . '"' : '' ?>>
                                <a href="<?= htmlspecialchars($item['href']) ?>" 
                                   class="nav-item <?= $this->currentPage === $item['id'] ? 'active' : '' ?>"
                                   data-title="<?= htmlspecialchars($item['label']) ?>">
                                    <?php if ($isDraggable): ?>
                                    <span class="drag-handle" data-title="Drag to reorder">
                                        <i class="fas fa-grip-vertical"></i>
                                    </span>
                                    <?php endif; ?>
                                    <span class="icon"><i class="fas <?= $item['icon'] ?>"></i></span>
                                    <span><?= htmlspecialchars($item['label']) ?></span>
                                    <?php if ($showPinnedBadge): ?>
                                    <span class="app-pinned-badge" style="margin-left: auto; color: var(--accent-primary); font-size: 12px;">
                                        <i class="fas fa-thumbtack"></i>
                                    </span>
                                    <?php endif; ?>
                                </a>
                            </div>
                            <?php endforeach; ?>
                            <!-- Drop zone for inserting after last item -->
                            <div class="apps-drop-zone" id="apps-drop-zone"></div>
                        </div>
                    <?php else: ?>
                        <!-- Other sections (non-draggable) -->
                        <?php
                            $renderedItemCount = 0;
                        ?>
                        <?php foreach ($section['items'] as $item): ?>
                        <?php if (!empty($item['dividerBefore']) && $renderedItemCount > 0): ?>
                        <div class="nav-section-divider" aria-hidden="true"></div>
                        <?php endif; ?>
                        <a href="<?= htmlspecialchars($item['href']) ?>" 
                           class="nav-item <?= $this->currentPage === $item['id'] ? 'active' : '' ?>"
                           data-search-item="true"
                           data-search-label="<?= htmlspecialchars($section['section'] . ' ' . $item['label']) ?>"
                           data-title="<?= htmlspecialchars($item['label']) ?>">
                            <span class="icon"><i class="fas <?= $item['icon'] ?>"></i></span>
                            <span><?= htmlspecialchars($item['label']) ?></span>
                        </a>
                        <?php $renderedItemCount++; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                <div class="sidebar-search-empty" id="sidebar-search-empty" hidden>No matching navigation items</div>
            </nav>

            <div class="sidebar-footer">
                <div class="user-info" data-title="<?= htmlspecialchars($this->user['name']) . ' (' . htmlspecialchars(str_replace('-', ' ', $this->user['role'])) . ')' ?>">
                    <div class="user-avatar"><?= htmlspecialchars($initials) ?></div>
                    <div class="user-details">
                        <div class="user-name"><?= htmlspecialchars($this->user['name']) ?></div>
                        <div class="user-role"><?= htmlspecialchars(str_replace('-', ' ', $this->user['role'])) ?></div>
                    </div>
                </div>
                <button class="logout-btn" onclick="logout()" data-title="Sign Out">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Sign Out</span>
                </button>
            </div>
        </aside>

        <script>
            // Sync data attribute with class for consistency
            (function() {
                const html = document.documentElement;
                const sidebar = document.getElementById('sidebar');
                if (!sidebar) return;
                
                // Check if data attribute is set (from head script)
                if (html.getAttribute('data-sidebar-collapsed') === 'true') {
                    sidebar.classList.add('collapsed');
                    document.body.classList.add('sidebar-collapsed');
                }
                
                // Remove data attribute after sync (cleanup)
                html.removeAttribute('data-sidebar-collapsed');
            })();
            
            // Tooltip system for collapsed sidebar - declare variables first
            let tooltip = null;
            let tooltipHandler = null;
            
            function initTooltips() {
                const sidebar = document.getElementById('sidebar');
                if (!sidebar) return;
                
                // Create tooltip element
                if (!tooltip) {
                    tooltip = document.createElement('div');
                    tooltip.className = 'sidebar-tooltip';
                    document.body.appendChild(tooltip);
                }
                
                // Remove old handler if exists
                if (tooltipHandler) {
                    sidebar.removeEventListener('mouseover', tooltipHandler);
                    sidebar.removeEventListener('mouseout', tooltipHandler);
                }
                
                // Create new handler
                tooltipHandler = function(e) {
                    if (!sidebar.classList.contains('collapsed')) {
                        tooltip.classList.remove('visible');
                        return;
                    }
                    
                    const target = e.target.closest('[data-title]');
                    if (!target) {
                        tooltip.classList.remove('visible');
                        return;
                    }
                    
                    const title = target.getAttribute('data-title');
                    if (!title) {
                        tooltip.classList.remove('visible');
                        return;
                    }
                    
                    if (e.type === 'mouseover') {
                        const rect = target.getBoundingClientRect();
                        const sidebarWidth = parseInt(getComputedStyle(sidebar).width);
                        
                        tooltip.textContent = title;
                        tooltip.style.left = (sidebarWidth + 12) + 'px';
                        tooltip.style.top = (rect.top + rect.height / 2) + 'px';
                        tooltip.classList.add('visible');
                    } else if (e.type === 'mouseout') {
                        // Only hide if we're leaving the element with data-title
                        if (!target.contains(e.relatedTarget)) {
                            tooltip.classList.remove('visible');
                        }
                    }
                };
                
                // Attach event listeners
                sidebar.addEventListener('mouseover', tooltipHandler, true);
                sidebar.addEventListener('mouseout', tooltipHandler, true);
            }

            // Initialize tooltips when DOM is ready
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initTooltips);
            } else {
                initTooltips();
            }
            
            // Handle window resize - remove collapsed state if switching to mobile
            let resizeTimeout;
            window.addEventListener('resize', function() {
                clearTimeout(resizeTimeout);
                resizeTimeout = setTimeout(function() {
                    const sidebar = document.getElementById('sidebar');
                    if (sidebar && window.innerWidth <= 1024) {
                        if (sidebar.classList.contains('collapsed')) {
                            sidebar.classList.remove('collapsed');
                            document.body.classList.remove('sidebar-collapsed');
                        }
                    }
                }, 100);
            });

            (function() {
                const sidebar = document.getElementById('sidebar');
                const input = document.getElementById('sidebar-search-input');
                const clearButton = document.getElementById('sidebar-search-clear');
                const emptyState = document.getElementById('sidebar-search-empty');

                if (!sidebar || !input || !clearButton || !emptyState) {
                    window.clearSidebarSearch = function() {};
                    return;
                }

                const normalize = function(value) {
                    return (value || '').toLowerCase().replace(/\s+/g, ' ').trim();
                };

                function syncDragState(isSearchActive) {
                    sidebar.querySelectorAll('.nav-item-wrapper.draggable').forEach(function(item) {
                        if (isSearchActive) {
                            item.removeAttribute('draggable');
                        } else {
                            item.setAttribute('draggable', 'true');
                        }
                    });

                    const dropZone = document.getElementById('apps-drop-zone');
                    if (dropZone) {
                        dropZone.hidden = isSearchActive;
                    }
                }

                function applySidebarSearch() {
                    const query = normalize(input.value);
                    let visibleItemCount = 0;

                    sidebar.querySelectorAll('.nav-home').forEach(function(group) {
                        const item = group.querySelector('[data-search-item="true"]');
                        if (!item) {
                            group.hidden = true;
                            return;
                        }

                        const searchLabel = normalize(item.getAttribute('data-search-label'));
                        const isMatch = query === '' || searchLabel.includes(query);
                        item.hidden = !isMatch;
                        group.hidden = !isMatch;

                        if (isMatch) {
                            visibleItemCount += 1;
                        }
                    });

                    sidebar.querySelectorAll('.nav-section').forEach(function(section) {
                        const sectionItems = Array.from(section.querySelectorAll('[data-search-item="true"]'));
                        let sectionVisibleItemCount = 0;

                        sectionItems.forEach(function(item) {
                            const searchLabel = normalize(item.getAttribute('data-search-label'));
                            const isMatch = query === '' || searchLabel.includes(query);

                            item.hidden = !isMatch;
                            if (isMatch) {
                                sectionVisibleItemCount += 1;
                                visibleItemCount += 1;
                            }
                        });

                        section.hidden = sectionVisibleItemCount === 0;
                    });

                    const isSearchActive = query !== '';
                    clearButton.hidden = !isSearchActive;
                    emptyState.hidden = !isSearchActive || visibleItemCount > 0;
                    sidebar.classList.toggle('sidebar-search-active', isSearchActive);
                    syncDragState(isSearchActive);
                }

                function clearSidebarSearch(options) {
                    const shouldFocus = options && options.focus === true;
                    input.value = '';
                    applySidebarSearch();
                    if (shouldFocus) {
                        input.focus();
                    }
                }

                input.addEventListener('input', applySidebarSearch);
                input.addEventListener('keydown', function(event) {
                    if (event.key === 'Escape' && input.value !== '') {
                        event.preventDefault();
                        clearSidebarSearch({ focus: true });
                    }
                });

                clearButton.addEventListener('click', function() {
                    clearSidebarSearch({ focus: true });
                });

                window.applySidebarSearch = applySidebarSearch;
                window.clearSidebarSearch = clearSidebarSearch;
                applySidebarSearch();
            })();

            function setSidebarOpen(isOpen) {
                const sidebar = document.getElementById('sidebar');
                const toggle = document.querySelector('.mobile-menu-toggle');
                if (!sidebar || !toggle) return;

                const iconOpen = toggle.querySelector('.icon-open');
                const iconClose = toggle.querySelector('.icon-close');

                if (isOpen) {
                    sidebar.classList.add('open');
                } else {
                    sidebar.classList.remove('open');
                }

                // Adjust toggle position when sidebar opens/closes (mobile only)
                if (window.innerWidth <= 1024) {
                    if (isOpen) {
                        const sidebarWidth = parseInt(getComputedStyle(sidebar).width);
                        toggle.style.left = (sidebarWidth - 22) + 'px';
                        // Animate icons
                        iconOpen.style.opacity = '0';
                        iconOpen.style.transform = 'translate(-50%, -50%) translateX(8px)';
                        iconClose.style.opacity = '1';
                        iconClose.style.transform = 'translate(-50%, -50%) translateX(0)';
                    } else {
                        toggle.style.left = '-22px';
                        // Animate icons
                        iconOpen.style.opacity = '1';
                        iconOpen.style.transform = 'translate(-50%, -50%) translateX(0)';
                        iconClose.style.opacity = '0';
                        iconClose.style.transform = 'translate(-50%, -50%) translateX(-8px)';
                    }
                } else {
                    toggle.style.left = '';
                }
            }

            function toggleSidebar() {
                const sidebar = document.getElementById('sidebar');
                if (!sidebar) return;
                setSidebarOpen(!sidebar.classList.contains('open'));
            }

            function toggleSidebarCollapse() {
                // Don't allow collapse on mobile
                if (window.innerWidth <= 1024) {
                    return;
                }
                
                const sidebar = document.getElementById('sidebar');
                const isCollapsed = sidebar.classList.toggle('collapsed');
                document.body.classList.toggle('sidebar-collapsed', isCollapsed);

                if (isCollapsed && typeof window.clearSidebarSearch === 'function') {
                    window.clearSidebarSearch();
                }
                
                // Save state to localStorage
                localStorage.setItem('sidebarCollapsed', isCollapsed ? 'true' : 'false');
                
                // Reinitialize tooltips after state change
                setTimeout(initTooltips, 100);
            }

            async function logout() {
                // Calculate relative paths based on current location depth
                const path = window.location.pathname;
                let apiPath = 'api/auth.php';
                let loginPath = 'login.php';
                
                // Count directory depth from root
                if (path.includes('/apps/')) {
                    // Inside an app: /apps/{appId}/page.php -> need ../../
                    apiPath = '../../api/auth.php';
                    loginPath = '../../login.php';
                } else if (path.includes('/admin/') || path.includes('/config/')) {
                    // Inside admin or config: /admin/page.php -> need ../
                    apiPath = '../api/auth.php';
                    loginPath = '../login.php';
                }
                    
                try {
                    const response = await fetch(apiPath + '?action=logout', {
                        method: 'POST'
                    });
                    const data = await response.json();
                    if (data.success) {
                        window.location.href = loginPath;
                    }
                } catch (error) {
                    console.error('Logout error:', error);
                    window.location.href = loginPath;
                }
            }

            // Close sidebar when clicking outside on mobile
            document.addEventListener('click', (e) => {
                const sidebar = document.getElementById('sidebar');
                const toggle = document.querySelector('.mobile-menu-toggle');
                if (!sidebar || !toggle) return;
                if (window.innerWidth <= 1024 && 
                    !sidebar.contains(e.target) && 
                    !toggle.contains(e.target) &&
                    sidebar.classList.contains('open')) {
                    setSidebarOpen(false);
                }
            });

            // Drag and Drop for Apps (Sidebar)
            function initSidebarAppDragAndDrop() {
                const appsList = document.getElementById('apps-list');
                if (!appsList) return;

                let draggedElement = null;

                function getDraggableItems() {
                    return Array.from(appsList.querySelectorAll('.nav-item-wrapper.draggable'));
                }

                function attachDragListeners() {
                    const draggableItems = getDraggableItems();
                    const dropZone = document.getElementById('apps-drop-zone');
                    
                    // Attach listeners to drop zone
                    if (dropZone) {
                        // Remove existing listeners by cloning
                        const newDropZone = dropZone.cloneNode(true);
                        dropZone.parentNode.replaceChild(newDropZone, dropZone);
                        
                        newDropZone.addEventListener('dragover', function(e) {
                            if (e.preventDefault) {
                                e.preventDefault();
                            }
                            e.dataTransfer.dropEffect = 'move';
                            
                            // Remove drag-over from all items
                            getDraggableItems().forEach(i => {
                                i.classList.remove('drag-over', 'drag-over-bottom');
                            });
                            
                            // Show drop zone indicator
                            this.classList.add('drag-over');
                        });
                        
                        newDropZone.addEventListener('dragleave', function(e) {
                            if (!this.contains(e.relatedTarget)) {
                                this.classList.remove('drag-over');
                            }
                        });
                        
                        newDropZone.addEventListener('drop', function(e) {
                            if (e.stopPropagation) {
                                e.stopPropagation();
                            }
                            if (e.preventDefault) {
                                e.preventDefault();
                            }
                            
                            if (draggedElement) {
                                // Remove dragging class immediately
                                draggedElement.classList.remove('dragging');
                                
                                const items = getDraggableItems();
                                const draggedIndex = items.findIndex(item => item === draggedElement);
                                
                                if (draggedIndex !== -1) {
                                    // Remove dragged element
                                    const dragged = items.splice(draggedIndex, 1)[0];
                                    
                                    // Append to end
                                    items.push(dragged);
                                    
                                    // Reorder DOM
                                    items.forEach(item => appsList.appendChild(item));
                                    
                                    // Clear dragged element reference
                                    draggedElement = null;
                                    
                                    // Reattach listeners
                                    setTimeout(() => attachDragListeners(), 0);
                                    
                                    // Save new order
                                    const appIds = items.map(item => item.dataset.appId).filter(id => id);
                                    if (appIds.length > 0) {
                                        saveAppOrder(appIds);
                                    }
                                }
                            }
                            
                            // Clean up drag-over states
                            this.classList.remove('drag-over');
                            getDraggableItems().forEach(i => i.classList.remove('drag-over', 'drag-over-bottom'));
                            return false;
                        });
                    }
                    
                    draggableItems.forEach((item) => {
                        // Clear any existing listeners by removing and re-adding the element
                        // This ensures we don't have duplicate listeners
                        const parent = item.parentNode;
                        const nextSibling = item.nextSibling;
                        const cloned = item.cloneNode(true);
                        parent.removeChild(item);
                        if (nextSibling) {
                            parent.insertBefore(cloned, nextSibling);
                        } else {
                            parent.appendChild(cloned);
                        }
                        
                        // Attach listeners to the cloned element
                        cloned.addEventListener('dragstart', function(e) {
                            draggedElement = this;
                            this.classList.add('dragging');
                            e.dataTransfer.effectAllowed = 'move';
                            e.dataTransfer.setData('text/html', this.innerHTML);
                        });

                        cloned.addEventListener('dragend', function(e) {
                            this.classList.remove('dragging');
                            draggedElement = null;
                            // Remove drag-over from all items and drop zone
                            getDraggableItems().forEach(i => i.classList.remove('drag-over', 'drag-over-bottom'));
                            const dropZone = document.getElementById('apps-drop-zone');
                            if (dropZone) dropZone.classList.remove('drag-over');
                        });

                        cloned.addEventListener('dragover', function(e) {
                            if (e.preventDefault) {
                                e.preventDefault();
                            }
                            e.dataTransfer.dropEffect = 'move';
                            
                            // Remove drag-over from all items and drop zone
                            getDraggableItems().forEach(i => {
                                if (i !== this && i !== draggedElement) {
                                    i.classList.remove('drag-over', 'drag-over-bottom');
                                }
                            });
                            const dropZone = document.getElementById('apps-drop-zone');
                            if (dropZone) dropZone.classList.remove('drag-over');
                            
                            // Determine if we're in the bottom half of the item
                            const rect = this.getBoundingClientRect();
                            const centerY = rect.top + rect.height / 2;
                            const isBottomHalf = e.clientY > centerY;
                            
                            // Check if this is the last item
                            const items = getDraggableItems();
                            const isLastItem = items.indexOf(this) === items.length - 1;
                            
                            // Add drag-over to this item if it's not the dragged item
                            if (this !== draggedElement) {
                                if (isBottomHalf && isLastItem) {
                                    // Show drop zone indicator for last item bottom half
                                    this.classList.remove('drag-over');
                                    this.classList.add('drag-over-bottom');
                                    if (dropZone) dropZone.classList.add('drag-over');
                                } else {
                                    // Show top border for normal insertion
                                    this.classList.remove('drag-over-bottom');
                                    this.classList.add('drag-over');
                                    if (dropZone) dropZone.classList.remove('drag-over');
                                }
                            }
                        });

                        cloned.addEventListener('dragleave', function(e) {
                            // Only remove if we're actually leaving (not entering a child)
                            if (!this.contains(e.relatedTarget)) {
                                this.classList.remove('drag-over', 'drag-over-bottom');
                                const dropZone = document.getElementById('apps-drop-zone');
                                if (dropZone) dropZone.classList.remove('drag-over');
                            }
                        });

                        cloned.addEventListener('drop', function(e) {
                            if (e.stopPropagation) {
                                e.stopPropagation();
                            }
                            if (e.preventDefault) {
                                e.preventDefault();
                            }

                            if (draggedElement && draggedElement !== this) {
                                const items = getDraggableItems();
                                
                                // Find current indices
                                const draggedIndex = items.findIndex(item => item === draggedElement);
                                const targetIndex = items.findIndex(item => item === this);
                                
                                if (draggedIndex !== -1 && targetIndex !== -1 && draggedIndex !== targetIndex) {
                                    // Remove dragged element from array
                                    const dragged = items.splice(draggedIndex, 1)[0];
                                    
                                    // Recalculate target index (may have shifted after splice)
                                    const newTargetIndex = items.findIndex(item => item === this);
                                    
                                    // Determine if we should insert before or after the target
                                    // Check mouse Y position relative to target center
                                    const targetRect = this.getBoundingClientRect();
                                    const targetCenterY = targetRect.top + targetRect.height / 2;
                                    const insertAfter = e.clientY > targetCenterY;
                                    
                                    // Insert at new position
                                    if (newTargetIndex >= 0) {
                                        if (insertAfter && newTargetIndex === items.length - 1) {
                                            // Dropping after the last item - append to end
                                            items.push(dragged);
                                        } else if (insertAfter) {
                                            // Insert after target
                                            items.splice(newTargetIndex + 1, 0, dragged);
                                        } else {
                                            // Insert before target
                                            items.splice(newTargetIndex, 0, dragged);
                                        }
                                    } else {
                                        // Target not found after splice, append to end
                                        items.push(dragged);
                                    }
                                    
                                    // Reorder DOM
                                    items.forEach(item => appsList.appendChild(item));
                                    
                                    // Clear dragged element reference and remove dragging class
                                    if (draggedElement) {
                                        draggedElement.classList.remove('dragging');
                                        draggedElement = null;
                                    }
                                    
                                    // Reattach listeners after DOM change
                                    setTimeout(() => attachDragListeners(), 0);
                                    
                                    // Save new order
                                    const appIds = items.map(item => item.dataset.appId).filter(id => id);
                                    if (appIds.length > 0) {
                                        saveAppOrder(appIds);
                                    }
                                }
                            }

                            // Clean up drag-over states
                            this.classList.remove('drag-over', 'drag-over-bottom');
                            const dropZone = document.getElementById('apps-drop-zone');
                            if (dropZone) dropZone.classList.remove('drag-over');
                            return false;
                        });
                    });
                }

                // Initial attachment
                attachDragListeners();

                async function saveAppOrder(appIds) {
                    try {
                        // Determine API path based on current location
                        const apiPath = window.location.pathname.includes('/admin/') || window.location.pathname.includes('/config/') 
                            ? '../api/apps.php' 
                            : window.location.pathname.includes('/apps/')
                            ? '../../api/apps.php'
                            : 'api/apps.php';
                        
                        const response = await fetch(apiPath + '?action=set-order', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ order: appIds })
                        });
                        const data = await response.json();
                        
                        if (data.success) {
                            window.Toast?.success('App order saved');
                        } else {
                            window.Toast?.error(data.error || 'Failed to save order');
                        }
                    } catch (e) {
                        console.error('Failed to save app order:', e);
                        window.Toast?.error('Failed to save app order');
                    }
                }
            }

            window.initSidebarAppDragAndDrop = initSidebarAppDragAndDrop;
            initSidebarAppDragAndDrop();

            // Global Toast Notification System
            window.Toast = {
                container: null,
                
                init() {
                    if (!this.container) {
                        this.container = document.createElement('div');
                        this.container.className = 'toast-container';
                        this.container.id = 'toast-container';
                        document.body.appendChild(this.container);
                    }
                },

                show(message, type = 'info', options = {}) {
                    this.init();
                    
                    const title = options.title || this.getDefaultTitle(type);
                    const duration = options.duration ?? 5000;
                    const icon = this.getIcon(type);
                    
                    const toast = document.createElement('div');
                    toast.className = `toast ${type}`;
                    toast.innerHTML = `
                        <span class="toast-icon">${icon}</span>
                        <div class="toast-content">
                            <div class="toast-title">${this.escapeHtml(title)}</div>
                            <div class="toast-message">${this.escapeHtml(message)}</div>
                        </div>
                        <button class="toast-close" onclick="Toast.dismiss(this.parentElement)">
                            <i class="fas fa-times"></i>
                        </button>
                    `;
                    
                    this.container.appendChild(toast);
                    
                    if (duration > 0) {
                        setTimeout(() => this.dismiss(toast), duration);
                    }
                    
                    return toast;
                },

                dismiss(toast) {
                    if (!toast || toast.classList.contains('hiding')) return;
                    toast.classList.add('hiding');
                    setTimeout(() => toast.remove(), 300);
                },

                success(message, options = {}) {
                    return this.show(message, 'success', options);
                },

                error(message, options = {}) {
                    return this.show(message, 'error', { duration: 8000, ...options });
                },

                warning(message, options = {}) {
                    return this.show(message, 'warning', options);
                },

                info(message, options = {}) {
                    return this.show(message, 'info', options);
                },

                getDefaultTitle(type) {
                    const titles = {
                        success: 'Success',
                        error: 'Error',
                        warning: 'Warning',
                        info: 'Info'
                    };
                    return titles[type] || 'Notice';
                },

                getIcon(type) {
                    const icons = {
                        success: '<i class="fas fa-check-circle"></i>',
                        error: '<i class="fas fa-exclamation-circle"></i>',
                        warning: '<i class="fas fa-exclamation-triangle"></i>',
                        info: '<i class="fas fa-info-circle"></i>'
                    };
                    return icons[type] || icons.info;
                },

                escapeHtml(text) {
                    const div = document.createElement('div');
                    div.textContent = text;
                    return div.innerHTML;
                }
            };
        </script>
        <?php
    }

    /**
     * Get user initials from name
     */
    private function getInitials(string $name): string {
        $parts = explode(' ', trim($name));
        $initials = '';
        foreach ($parts as $part) {
            if (!empty($part)) {
                $initials .= strtoupper($part[0]);
                if (strlen($initials) >= 2) break;
            }
        }
        return $initials ?: '??';
    }
}
