<?php
/**
 * apps.php - Apps Discovery & Management Page
 * 
 * Dedicated page for discovering, searching, and managing installed apps.
 */

require_once __DIR__ . '/includes/middleware.php';
require_once __DIR__ . '/includes/Layout.php';
require_once __DIR__ . '/includes/AppManager.php';
require_once __DIR__ . '/includes/PermissionManager.php';

// Require authentication
$user = requireAuthentication();
requireModuleAccess('apps');

// Create layout instance
$layout = new Layout($user, 'apps-page');

// Get app manager and organized apps
// Pass user ID to get user-specific preferences
$appManager = new AppManager();
$allApps = $appManager->getEnabledApps($user['id']);

// Filter apps based on granular permissions (whitelist/blacklist groups)
if ($user['role'] !== 'super-admin') {
    $permManager = new PermissionManager();
    $appIds = array_column($allApps, 'id');
    $allowedIds = $permManager->filterAccessibleResources($user, 'app', $appIds);
    $allowedIdSet = array_flip($allowedIds);
    $allApps = array_values(array_filter($allApps, function($app) use ($allowedIdSet) {
        return isset($allowedIdSet[$app['id']]);
    }));
}

$organized = $layout->getOrganizedApps($allApps);
$allTags = $layout->getAllAppTags();

// Get stats
$pinnedCount = count($organized['pinned']);
$recentCount = count($organized['recent']);
$totalCount = count($allApps);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $layout->renderHead('Apps'); ?>
    <style>
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            margin-bottom: 32px;
        }

        .page-header-content {
            flex: 1;
        }

        .page-title {
            font-size: 32px;
            font-weight: 700;
            color: var(--text-primary);
            margin: 0 0 8px 0;
        }

        .page-subtitle {
            font-size: 16px;
            color: var(--text-secondary);
            margin: 0;
        }

        .page-header-actions {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .page-header-actions .btn.active {
            background: var(--accent-primary);
            color: white;
            border-color: var(--accent-primary);
        }

        .stats-bar {
            display: flex;
            gap: 16px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }

        .stat-badge {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-size: 14px;
            color: var(--text-secondary);
        }

        .stat-badge .stat-value {
            font-weight: 600;
            color: var(--text-primary);
        }

        /* Search & Filters */
        .search-filters-container {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
        }

        .search-box {
            position: relative;
            margin-bottom: 16px;
        }

        .search-box input {
            width: 100%;
            padding: 12px 16px 12px 44px;
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            color: var(--text-primary);
            font-size: 15px;
            transition: all 0.2s;
        }

        .search-box input:focus {
            outline: none;
            border-color: var(--accent-primary);
            box-shadow: 0 0 0 3px var(--accent-glow);
        }

        .search-box .search-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 14px;
        }

        .clear-btn {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            padding: 4px;
            display: none;
            align-items: center;
            justify-content: center;
        }

        .clear-btn.visible {
            display: flex;
        }

        .clear-btn:hover {
            color: var(--text-primary);
        }

        .tags-container {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }

        .tags-label {
            font-size: 13px;
            color: var(--text-secondary);
            font-weight: 500;
        }

        .tag-chip {
            display: inline-flex;
            align-items: center;
            padding: 6px 12px;
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            font-size: 12px;
            color: var(--text-secondary);
            cursor: pointer;
            transition: all 0.2s;
        }

        .tag-chip:hover {
            background: var(--bg-tertiary);
            border-color: var(--accent-primary);
            color: var(--accent-primary);
        }

        .tag-chip.active {
            background: var(--accent-primary);
            border-color: var(--accent-primary);
            color: white;
        }

        .clear-filters-btn {
            background: none;
            border: none;
            color: var(--accent-primary);
            font-size: 12px;
            cursor: pointer;
            padding: 6px 12px;
            display: none;
        }

        .clear-filters-btn.visible {
            display: block;
        }

        .clear-filters-btn:hover {
            text-decoration: underline;
        }

        /* Tabs */
        .tabs-container {
            display: flex;
            gap: 4px;
            margin-bottom: 24px;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 8px;
        }

        .tab {
            padding: 10px 20px;
            border-radius: 8px 8px 0 0;
            cursor: pointer;
            color: var(--text-secondary);
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }

        .tab:hover {
            background: var(--bg-tertiary);
            color: var(--text-primary);
        }

        .tab.active {
            background: var(--accent-primary);
            color: white;
        }

        .tab-badge {
            background: var(--bg-tertiary);
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 12px;
        }

        .tab.active .tab-badge {
            background: rgba(255,255,255,0.2);
        }

        /* Apps Grid */
        .apps-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }

        @media (max-width: 768px) {
            .apps-grid {
                grid-template-columns: 1fr;
            }
        }

        .app-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            transition: all 0.2s;
            cursor: pointer;
        }

        .app-card:hover {
            border-color: var(--accent-primary);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            transform: translateY(-2px);
        }

        .app-card-header {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            margin-bottom: 12px;
        }

        .app-card-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: white;
            flex-shrink: 0;
        }

        .app-card-info {
            flex: 1;
            min-width: 0;
        }

        .app-card-name {
            font-size: 16px;
            font-weight: 600;
            margin: 0 0 4px 0;
            color: var(--text-primary);
        }

        .app-card-desc {
            font-size: 13px;
            color: var(--text-secondary);
            margin: 0 0 8px 0;
            line-height: 1.4;
        }

        .app-card-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-bottom: 12px;
        }

        .app-card-tag {
            display: inline-flex;
            align-items: center;
            padding: 4px 8px;
            background: var(--bg-tertiary);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            font-size: 11px;
            color: var(--text-secondary);
        }

        .app-card-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 12px;
            border-top: 1px solid var(--border-color);
        }

        .app-card-meta {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            color: var(--text-muted);
        }

        .app-card-actions {
            display: flex;
            gap: 6px;
        }

        .btn-icon-small {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            padding: 0;
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            color: var(--text-secondary);
            cursor: pointer;
            transition: all 0.2s;
            font-size: 13px;
        }

        .btn-icon-small:hover {
            background: var(--bg-tertiary);
            border-color: var(--accent-primary);
            color: var(--accent-primary);
        }

        .btn-icon-small.active {
            background: var(--accent-primary);
            border-color: var(--accent-primary);
            color: white;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-secondary);
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.5;
        }

        .empty-state h3 {
            font-size: 18px;
            margin: 0 0 8px 0;
            color: var(--text-primary);
        }

        .empty-state p {
            font-size: 14px;
            margin: 0;
        }

        .hidden {
            display: none !important;
        }
    </style>
</head>
<body>
    <div class="app-container">
        <?php $layout->renderSidebar(); ?>
        
        <main class="main-content">
            <div class="content-area">
                <!-- Page Header -->
                <div class="page-header">
                    <div class="page-header-content">
                        <h1 class="page-title">Apps</h1>
                        <p class="page-subtitle">Discover and manage your installed apps</p>
                    </div>
                    <?php if ($pinnedCount > 0): ?>
                    <div class="page-header-actions">
                        <button class="btn btn-secondary" id="header-pin-btn" onclick="toggleHeaderPin()" title="Toggle pin view">
                            <i class="fas fa-thumbtack"></i>
                            <span id="header-pin-text">Show Pinned</span>
                        </button>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Stats Bar -->
                <div class="stats-bar">
                    <div class="stat-badge">
                        <span class="stat-value"><?= $totalCount ?></span>
                        <span>Total Apps</span>
                    </div>
                    <?php if ($pinnedCount > 0): ?>
                    <div class="stat-badge">
                        <span class="stat-value"><?= $pinnedCount ?></span>
                        <span>Pinned</span>
                    </div>
                    <?php endif; ?>
                    <?php if ($recentCount > 0): ?>
                    <div class="stat-badge">
                        <span class="stat-value"><?= $recentCount ?></span>
                        <span>Recently Used</span>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Search & Filters -->
                <div class="search-filters-container">
                    <div class="search-box">
                        <i class="fas fa-search search-icon"></i>
                        <input type="text" 
                               id="app-search-input" 
                               placeholder="Search apps by name, description, or tags..." 
                               autocomplete="off">
                        <button class="clear-btn" id="clear-search" onclick="clearSearch()">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    
                    <?php if (!empty($allTags)): ?>
                    <div class="tags-container">
                        <span class="tags-label">Filter by tag:</span>
                        <?php foreach ($allTags as $tag): ?>
                        <span class="tag-chip" data-tag="<?= htmlspecialchars($tag) ?>" onclick="toggleTag('<?= htmlspecialchars($tag) ?>')">
                            <?= htmlspecialchars($tag) ?>
                        </span>
                        <?php endforeach; ?>
                        <button class="clear-filters-btn" id="clear-filters" onclick="clearFilters()">
                            Clear filters
                        </button>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Tabs -->
                <div class="tabs-container">
                    <div class="tab active" data-tab="all" onclick="switchTab('all')">
                        All Apps
                        <span class="tab-badge"><?= $totalCount ?></span>
                    </div>
                    <?php if ($pinnedCount > 0): ?>
                    <div class="tab" data-tab="pinned" onclick="switchTab('pinned')">
                        <i class="fas fa-thumbtack" style="font-size: 12px;"></i>
                        Pinned
                        <span class="tab-badge"><?= $pinnedCount ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($recentCount > 0): ?>
                    <div class="tab" data-tab="recent" onclick="switchTab('recent')">
                        <i class="fas fa-clock" style="font-size: 12px;"></i>
                        Recently Used
                        <span class="tab-badge"><?= $recentCount ?></span>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- App Cards -->
                <div class="apps-container">
                    <!-- All Apps Tab -->
                    <div class="apps-grid" id="tab-all" data-tab-content="all">
                        <?php foreach ($organized['all'] as $app): 
                            $appId = $app['id'];
                            $icon = str_replace('fas ', '', $app['icon'] ?? 'fa-puzzle-piece');
                            $tags = $app['tags'] ?? [];
                            $pinned = $app['pinned'] ?? false;
                            $lastUsed = $app['lastUsed'] ?? null;
                        ?>
                        <div class="app-card" 
                             data-app-id="<?= htmlspecialchars($appId) ?>"
                             data-app-name="<?= htmlspecialchars(strtolower($app['name'])) ?>"
                             data-app-desc="<?= htmlspecialchars(strtolower($app['description'] ?? '')) ?>"
                             data-app-tags="<?= htmlspecialchars(implode(',', $tags)) ?>"
                             onclick="window.location.href='apps/<?= htmlspecialchars($appId) ?>/index.php'">
                            <div class="app-card-header">
                                <div class="app-card-icon" style="background: linear-gradient(135deg, <?= htmlspecialchars($app['color'] ?? '#6366f1') ?> 0%, <?= htmlspecialchars($layout->darkenColor($app['color'] ?? '#6366f1')) ?> 100%);">
                                    <i class="fas <?= htmlspecialchars($icon) ?>"></i>
                                </div>
                                <div class="app-card-info">
                                    <h3 class="app-card-name">
                                        <?= htmlspecialchars($app['name']) ?>
                                    </h3>
                                    <p class="app-card-desc"><?= htmlspecialchars($app['description'] ?? '') ?></p>
                                    <?php if (!empty($tags)): ?>
                                    <div class="app-card-tags">
                                        <?php foreach (array_slice($tags, 0, 3) as $tag): ?>
                                        <span class="app-card-tag"><?= htmlspecialchars($tag) ?></span>
                                        <?php endforeach; ?>
                                        <?php if (count($tags) > 3): ?>
                                        <span class="app-card-tag">+<?= count($tags) - 3 ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="app-card-footer">
                                <div class="app-card-meta">
                                    <?php if ($lastUsed): ?>
                                    <i class="fas fa-clock"></i>
                                    <?= date('M j, Y', strtotime($lastUsed)) ?>
                                    <?php else: ?>
                                    <i class="fas fa-puzzle-piece"></i>
                                    v<?= htmlspecialchars($app['version'] ?? '1.0.0') ?>
                                    <?php endif; ?>
                                </div>
                                <div class="app-card-actions" onclick="event.stopPropagation()">
                                    <button class="btn-icon-small <?= $pinned ? 'active' : '' ?>" 
                                            onclick="togglePin('<?= htmlspecialchars($appId) ?>', <?= $pinned ? 'false' : 'true' ?>)"
                                            title="<?= $pinned ? 'Unpin' : 'Pin' ?>">
                                        <i class="fas fa-thumbtack"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Pinned Apps Tab -->
                    <?php if ($pinnedCount > 0): ?>
                    <div class="apps-grid hidden" id="tab-pinned" data-tab-content="pinned">
                        <?php foreach ($organized['pinned'] as $app): 
                            $appId = $app['id'];
                            $icon = str_replace('fas ', '', $app['icon'] ?? 'fa-puzzle-piece');
                            $tags = $app['tags'] ?? [];
                            $lastUsed = $app['lastUsed'] ?? null;
                        ?>
                        <div class="app-card" 
                             data-app-id="<?= htmlspecialchars($appId) ?>"
                             data-app-name="<?= htmlspecialchars(strtolower($app['name'])) ?>"
                             data-app-desc="<?= htmlspecialchars(strtolower($app['description'] ?? '')) ?>"
                             data-app-tags="<?= htmlspecialchars(implode(',', $tags)) ?>"
                             onclick="window.location.href='apps/<?= htmlspecialchars($appId) ?>/index.php'">
                            <div class="app-card-header">
                                <div class="app-card-icon" style="background: linear-gradient(135deg, <?= htmlspecialchars($app['color'] ?? '#6366f1') ?> 0%, <?= htmlspecialchars($layout->darkenColor($app['color'] ?? '#6366f1')) ?> 100%);">
                                    <i class="fas <?= htmlspecialchars($icon) ?>"></i>
                                </div>
                                <div class="app-card-info">
                                    <h3 class="app-card-name">
                                        <?= htmlspecialchars($app['name']) ?>
                                    </h3>
                                    <p class="app-card-desc"><?= htmlspecialchars($app['description'] ?? '') ?></p>
                                    <?php if (!empty($tags)): ?>
                                    <div class="app-card-tags">
                                        <?php foreach (array_slice($tags, 0, 3) as $tag): ?>
                                        <span class="app-card-tag"><?= htmlspecialchars($tag) ?></span>
                                        <?php endforeach; ?>
                                        <?php if (count($tags) > 3): ?>
                                        <span class="app-card-tag">+<?= count($tags) - 3 ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="app-card-footer">
                                <div class="app-card-meta">
                                    <i class="fas fa-clock"></i>
                                    <?= $lastUsed ? date('M j, Y g:i A', strtotime($lastUsed)) : 'Never' ?>
                                </div>
                                <div class="app-card-actions" onclick="event.stopPropagation()">
                                    <button class="btn-icon-small active" 
                                            onclick="togglePin('<?= htmlspecialchars($appId) ?>', false)"
                                            title="Unpin">
                                        <i class="fas fa-thumbtack"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Recently Used Tab -->
                    <?php if ($recentCount > 0): ?>
                    <div class="apps-grid hidden" id="tab-recent" data-tab-content="recent">
                        <?php foreach ($organized['recent'] as $app): 
                            $appId = $app['id'];
                            $icon = str_replace('fas ', '', $app['icon'] ?? 'fa-puzzle-piece');
                            $tags = $app['tags'] ?? [];
                            $lastUsed = $app['lastUsed'] ?? null;
                        ?>
                        <div class="app-card" 
                             data-app-id="<?= htmlspecialchars($appId) ?>"
                             data-app-name="<?= htmlspecialchars(strtolower($app['name'])) ?>"
                             data-app-desc="<?= htmlspecialchars(strtolower($app['description'] ?? '')) ?>"
                             data-app-tags="<?= htmlspecialchars(implode(',', $tags)) ?>"
                             onclick="window.location.href='apps/<?= htmlspecialchars($appId) ?>/index.php'">
                            <div class="app-card-header">
                                <div class="app-card-icon" style="background: linear-gradient(135deg, <?= htmlspecialchars($app['color'] ?? '#6366f1') ?> 0%, <?= htmlspecialchars($layout->darkenColor($app['color'] ?? '#6366f1')) ?> 100%);">
                                    <i class="fas <?= htmlspecialchars($icon) ?>"></i>
                                </div>
                                <div class="app-card-info">
                                    <h3 class="app-card-name">
                                        <?= htmlspecialchars($app['name']) ?>
                                    </h3>
                                    <p class="app-card-desc"><?= htmlspecialchars($app['description'] ?? '') ?></p>
                                    <?php if (!empty($tags)): ?>
                                    <div class="app-card-tags">
                                        <?php foreach (array_slice($tags, 0, 3) as $tag): ?>
                                        <span class="app-card-tag"><?= htmlspecialchars($tag) ?></span>
                                        <?php endforeach; ?>
                                        <?php if (count($tags) > 3): ?>
                                        <span class="app-card-tag">+<?= count($tags) - 3 ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="app-card-footer">
                                <div class="app-card-meta">
                                    <i class="fas fa-clock"></i>
                                    <?= $lastUsed ? date('M j, Y g:i A', strtotime($lastUsed)) : 'Never' ?>
                                </div>
                                <div class="app-card-actions" onclick="event.stopPropagation()">
                                    <button class="btn-icon-small <?= ($app['pinned'] ?? false) ? 'active' : '' ?>" 
                                            onclick="togglePin('<?= htmlspecialchars($appId) ?>', <?= ($app['pinned'] ?? false) ? 'false' : 'true' ?>)"
                                            title="<?= ($app['pinned'] ?? false) ? 'Unpin' : 'Pin' ?>">
                                        <i class="fas fa-thumbtack"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Empty State -->
                <div class="empty-state hidden" id="empty-state">
                    <i class="fas fa-search"></i>
                    <h3>No apps found</h3>
                    <p>Try adjusting your search or filters</p>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Search and Filter System
        let currentTab = 'all';
        let searchQuery = '';
        let activeTag = null;

        const searchInput = document.getElementById('app-search-input');
        const clearSearchBtn = document.getElementById('clear-search');
        const clearFiltersBtn = document.getElementById('clear-filters');
        const tagChips = document.querySelectorAll('.tag-chip');

        // Search functionality
        if (searchInput) {
            searchInput.addEventListener('input', function(e) {
                searchQuery = e.target.value.toLowerCase().trim();
                updateClearButton();
                filterApps();
            });
        }

        function updateClearButton() {
            if (clearSearchBtn) {
                clearSearchBtn.classList.toggle('visible', searchQuery.length > 0);
            }
            if (clearFiltersBtn) {
                clearFiltersBtn.classList.toggle('visible', activeTag !== null);
            }
        }

        function clearSearch() {
            searchQuery = '';
            if (searchInput) searchInput.value = '';
            updateClearButton();
            filterApps();
        }

        function clearFilters() {
            searchQuery = '';
            activeTag = null;
            if (searchInput) searchInput.value = '';
            tagChips.forEach(t => t.classList.remove('active'));
            updateClearButton();
            filterApps();
        }

        function toggleTag(tagName) {
            const tagElement = document.querySelector(`[data-tag="${tagName}"]`);
            if (!tagElement) return;
            
            if (activeTag === tagName) {
                activeTag = null;
                tagElement.classList.remove('active');
            } else {
                activeTag = tagName;
                tagChips.forEach(t => t.classList.remove('active'));
                tagElement.classList.add('active');
            }
            
            updateClearButton();
            filterApps();
        }

        function switchTab(tabName) {
            currentTab = tabName;
            
            // Update tab buttons
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.toggle('active', tab.dataset.tab === tabName);
            });
            
            // Update tab content
            document.querySelectorAll('[data-tab-content]').forEach(content => {
                content.classList.toggle('hidden', content.dataset.tabContent !== tabName);
            });
            
            // Update header pin button state
            updateHeaderPinButton();
            
            filterApps();
        }

        function filterApps() {
            const currentTabContent = document.querySelector(`[data-tab-content="${currentTab}"]`);
            if (!currentTabContent) return;
            
            const appCards = currentTabContent.querySelectorAll('.app-card');
            let visibleCount = 0;
            
            appCards.forEach(card => {
                const appName = card.dataset.appName || '';
                const appDesc = card.dataset.appDesc || '';
                const appTags = (card.dataset.appTags || '').toLowerCase();
                const tags = appTags.split(',').map(t => t.trim());
                
                let matchesSearch = true;
                let matchesTag = true;
                
                // Check search query
                if (searchQuery) {
                    matchesSearch = appName.includes(searchQuery) || 
                                  appDesc.includes(searchQuery) ||
                                  tags.some(t => t.includes(searchQuery));
                }
                
                // Check tag filter
                if (activeTag) {
                    matchesTag = tags.includes(activeTag.toLowerCase());
                }
                
                if (matchesSearch && matchesTag) {
                    card.style.display = 'block';
                    visibleCount++;
                } else {
                    card.style.display = 'none';
                }
            });
            
            // Show/hide empty state
            const emptyState = document.getElementById('empty-state');
            if (emptyState) {
                emptyState.classList.toggle('hidden', visibleCount > 0);
            }
        }

        // Header pin button - toggle to pinned tab
        function toggleHeaderPin() {
            const pinnedTab = document.querySelector('.tab[data-tab="pinned"]');
            if (pinnedTab) {
                switchTab('pinned');
            } else {
                window.Toast?.error('No pinned apps available');
            }
        }

        // Update header pin button state based on current tab
        function updateHeaderPinButton() {
            const pinBtn = document.getElementById('header-pin-btn');
            const pinText = document.getElementById('header-pin-text');
            if (pinBtn && pinText) {
                if (currentTab === 'pinned') {
                    pinBtn.classList.add('active');
                    pinText.textContent = 'Show All';
                } else {
                    pinBtn.classList.remove('active');
                    pinText.textContent = 'Show Pinned';
                }
            }
        }

        // Pin/Unpin functionality
        async function togglePin(appId, pin) {
            try {
                // Explicitly set global: false to ensure it saves to user preferences
                // (not global, even if user happens to be admin)
                const response = await fetch(`api/apps.php?action=set-pinned&app=${appId}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ pinned: pin, global: false })
                });
                
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                const data = await response.json();

                if (data.success) {
                    const message = pin ? 'App pinned' : 'App unpinned';
                    window.Toast?.success(message);
                    setTimeout(() => location.reload(), 500);
                } else {
                    window.Toast?.error(data.error || 'Failed to update pinned status');
                    console.error('Pin toggle failed:', data);
                }
            } catch (e) {
                console.error('Pin toggle error:', e);
                window.Toast?.error('Failed to update pinned status: ' + e.message);
            }
        }

        // Clear filters on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                clearFilters();
            }
        });

        // Initialize
        updateClearButton();
        updateHeaderPinButton();
    </script>
</body>
</html>
