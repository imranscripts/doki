<?php
/**
 * admin/sources.php - Doki Sources: Unified Git Providers & Repositories
 * 
 * Part of Doki v3 Architecture - Batch 13: Doki Sources
 */

require_once __DIR__ . '/../includes/middleware.php';
require_once __DIR__ . '/../includes/Layout.php';
require_once __DIR__ . '/../includes/SourcesManager.php';

$user = requireRole('admin');
requireModuleAccess('sources');
$isSuperAdmin = $user['role'] === 'super-admin';
$layout = new Layout($user, 'sources');
$sourcesManager = new SourcesManager();

$providers = $sourcesManager->getProviders();
$repositories = $sourcesManager->getRepositories();
$stats = $sourcesManager->getStats();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $layout->renderHead('Doki Sources'); ?>
    <style>
        .stats-bar {
            display: flex;
            gap: 24px;
            margin-bottom: 24px;
            padding: 20px;
            background: var(--bg-secondary);
            border: 1px solid var(--border-primary);
            border-radius: 12px;
        }

        .stat-item {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .stat-icon.providers { background: rgba(139, 92, 246, 0.15); color: #8b5cf6; }
        .stat-icon.repos { background: rgba(59, 130, 246, 0.15); color: #3b82f6; }
        .stat-icon.sync { background: rgba(34, 197, 94, 0.15); color: #22c55e; }

        .stat-value { font-size: 24px; font-weight: 700; }
        .stat-label { font-size: 12px; color: var(--text-muted); }

        .tabs {
            display: flex;
            gap: 4px;
            margin-bottom: 24px;
            border-bottom: 1px solid var(--border-primary);
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

        .tab:hover { background: var(--bg-tertiary); color: var(--text-primary); }
        .tab.active { background: var(--accent-primary); color: white; }

        .tab-badge {
            background: var(--bg-tertiary);
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 12px;
        }

        .tab.active .tab-badge { background: rgba(255,255,255,0.2); }

        .tab-content { display: none; }
        .tab-content.active { display: block; }

        .items-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .item-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-primary);
            border-radius: 12px;
            padding: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            transition: all 0.2s;
        }

        .item-card:hover { border-color: var(--accent-primary); }

        .item-info {
            display: flex;
            align-items: center;
            gap: 16px;
            flex: 1;
        }

        .item-icon {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .item-icon.github { background: rgba(255,255,255,0.1); color: var(--text-primary); }
        .item-icon.gitlab { background: rgba(252, 109, 38, 0.15); color: #fc6d26; }
        .item-icon.bitbucket { background: rgba(0, 82, 204, 0.15); color: #0052cc; }
        .item-icon.custom { background: rgba(139, 92, 246, 0.15); color: #8b5cf6; }

        .item-icon.apps { background: rgba(139, 92, 246, 0.15); color: #8b5cf6; }
        .item-icon.templates { background: rgba(59, 130, 246, 0.15); color: #3b82f6; }
        .item-icon.playwright-tests { background: rgba(34, 197, 94, 0.15); color: #22c55e; }
        .item-icon.mixed { background: rgba(245, 158, 11, 0.15); color: #f59e0b; }

        .item-details { flex: 1; }
        .item-name { font-weight: 600; font-size: 15px; margin: 0 0 4px 0; }
        .item-desc { font-size: 13px; color: var(--text-muted); margin: 0; }

        .item-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            margin-top: 8px;
        }

        .meta-item {
            font-size: 12px;
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .meta-item i { color: var(--text-muted); }

        .item-status {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-right: 24px;
        }

        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }

        .status-dot.success { background: var(--success); }
        .status-dot.error { background: var(--error); }
        .status-dot.pending { background: var(--warning); }
        .status-dot.unknown { background: var(--text-muted); }

        .item-actions {
            display: flex;
            gap: 8px;
        }

        .btn-sm {
            padding: 8px 12px;
            font-size: 13px;
        }

        /* Modal styles */
        .modal-backdrop {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }

        .modal-backdrop.active { display: flex; }

        .modal {
            background: var(--bg-secondary);
            border-radius: 12px;
            max-width: 600px;
            width: 95%;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 20px;
            border-bottom: 1px solid var(--border-primary);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h2 { margin: 0; font-size: 18px; }

        .modal-close {
            background: none;
            border: none;
            color: var(--text-secondary);
            cursor: pointer;
            font-size: 20px;
        }

        .modal-body { padding: 20px; }

        .modal-footer {
            padding: 16px 20px;
            border-top: 1px solid var(--border-primary);
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }

        .form-group { margin-bottom: 16px; }
        .form-label { display: block; margin-bottom: 6px; font-size: 13px; font-weight: 500; }
        .form-hint { font-size: 12px; color: var(--text-muted); margin-top: 4px; }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .type-select {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 8px;
        }

        .type-option {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px;
            background: var(--bg-tertiary);
            border: 2px solid var(--border-primary);
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .type-option:hover { border-color: var(--text-muted); }
        .type-option.selected { border-color: var(--accent-primary); background: var(--accent-glow); }
        .type-option input { display: none; }

        .type-option .icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
        }

        .type-option .label { font-weight: 500; font-size: 13px; }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 12px;
            background: var(--bg-tertiary);
            border-radius: 8px;
        }

        .checkbox-group input { width: 18px; height: 18px; }

        .empty-state {
            text-align: center;
            padding: 48px;
            color: var(--text-muted);
        }

        .empty-state > i { font-size: 48px; margin-bottom: 16px; opacity: 0.5; }

        .action-bar {
            display: flex;
            gap: 12px;
            margin-bottom: 20px;
        }

        .sync-status {
            font-size: 12px;
            padding: 4px 10px;
            border-radius: 16px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .sync-status.success { background: rgba(34, 197, 94, 0.15); color: var(--success); }
        .sync-status.error { background: rgba(248, 81, 73, 0.15); color: var(--error); }
        .sync-status.pending { background: rgba(245, 158, 11, 0.15); color: var(--warning); }
    </style>
</head>
<body>
    <div class="app-container">
        <?php $layout->renderSidebar(); ?>

        <main class="main-content">
            <div class="page-header">
                <div>
                    <h1 class="page-title">Doki Sources</h1>
                    <p class="page-subtitle">Manage Git providers and repositories for apps, templates, and tests</p>
                </div>
            </div>

            <!-- Stats Bar -->
            <div class="stats-bar">
                <div class="stat-item">
                    <div class="stat-icon repos">
                        <i class="fas fa-folder-tree"></i>
                    </div>
                    <div>
                        <div class="stat-value"><?= $stats['repositories']['total'] ?></div>
                        <div class="stat-label">Repositories</div>
                    </div>
                </div>
                <div class="stat-item">
                    <div class="stat-icon providers">
                        <i class="fab fa-git-alt"></i>
                    </div>
                    <div>
                        <div class="stat-value"><?= $stats['providers']['total'] ?></div>
                        <div class="stat-label">Git Providers</div>
                    </div>
                </div>
                <div class="stat-item">
                    <div class="stat-icon sync">
                        <i class="fas fa-sync"></i>
                    </div>
                    <div>
                        <div class="stat-value"><?= $stats['repositories']['withAutoSync'] ?></div>
                        <div class="stat-label">Auto-Sync Enabled</div>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="tabs">
                <div class="tab active" data-tab="repositories" onclick="switchTab('repositories')">
                    <i class="fas fa-folder-tree"></i>
                    Repositories
                    <span class="tab-badge"><?= count($repositories) ?></span>
                </div>
                <div class="tab" data-tab="providers" onclick="switchTab('providers')">
                    <i class="fab fa-git-alt"></i>
                    Git Providers
                    <span class="tab-badge"><?= count($providers) ?></span>
                </div>
            </div>

            <!-- Repositories Tab -->
            <div class="tab-content active" id="tab-repositories">
                <?php if ($isSuperAdmin): ?>
                <div class="action-bar">
                    <button class="btn btn-primary" onclick="showRepositoryModal()">
                        <i class="fas fa-plus"></i>
                        Add Repository
                    </button>
                </div>
                <?php endif; ?>

                <?php if (empty($repositories)): ?>
                <div class="empty-state">
                    <i class="fas fa-folder-tree"></i>
                    <h3>No repositories configured</h3>
                    <p>Add repositories to sync apps, templates, or Playwright tests</p>
                    <?php if ($isSuperAdmin): ?>
                    <button class="btn btn-primary" onclick="showRepositoryModal()" style="margin-top: 16px;">
                        <i class="fas fa-plus"></i>
                        Add Repository
                    </button>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <div class="items-list">
                    <?php foreach ($repositories as $repo): ?>
                    <div class="item-card" data-id="<?= htmlspecialchars($repo['id']) ?>">
                        <div class="item-info">
                            <div class="item-icon <?= htmlspecialchars($repo['type']) ?>">
                                <?php
                                $icon = match($repo['type']) {
                                    'apps' => 'fas fa-puzzle-piece',
                                    'templates' => 'fas fa-cubes',
                                    'playwright-tests' => 'fas fa-vial',
                                    default => 'fas fa-layer-group'
                                };
                                ?>
                                <i class="<?= $icon ?>"></i>
                            </div>
                            <div class="item-details">
                                <h4 class="item-name"><?= htmlspecialchars($repo['name']) ?></h4>
                                <p class="item-desc"><?= htmlspecialchars($repo['description'] ?? $repo['url']) ?></p>
                                <div class="item-meta">
                                    <span class="meta-item">
                                        <i class="fas fa-tag"></i>
                                        <?= ucfirst(str_replace('-', ' ', $repo['type'])) ?>
                                    </span>
                                    <span class="meta-item">
                                        <i class="fas fa-code-branch"></i>
                                        <?= htmlspecialchars($repo['pinned_ref'] ?? $repo['branch']) ?>
                                        <?php if ($repo['pin_type']): ?>
                                        (<?= $repo['pin_type'] ?>)
                                        <?php endif; ?>
                                    </span>
                                    <?php if ($repo['provider_name']): ?>
                                    <span class="meta-item">
                                        <i class="fab fa-git-alt"></i>
                                        <?= htmlspecialchars($repo['provider_name']) ?>
                                    </span>
                                    <?php endif; ?>
                                    <?php if ($repo['auto_sync']): ?>
                                    <span class="meta-item">
                                        <i class="fas fa-sync"></i>
                                        Auto-sync
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="item-status">
                            <?php if ($repo['last_sync_status'] === 'success'): ?>
                            <span class="sync-status success">
                                <i class="fas fa-check"></i>
                                Synced <?= $repo['last_sync_at'] ? date('M j', strtotime($repo['last_sync_at'])) : '' ?>
                            </span>
                            <?php elseif ($repo['last_sync_status'] === 'error'): ?>
                            <span class="sync-status error">
                                <i class="fas fa-exclamation"></i>
                                Sync failed
                            </span>
                            <?php else: ?>
                            <span class="sync-status pending">
                                <i class="fas fa-clock"></i>
                                Not synced
                            </span>
                            <?php endif; ?>
                        </div>
                        <div class="item-actions">
                            <button class="btn btn-secondary btn-sm" onclick="syncRepository('<?= $repo['id'] ?>')">
                                <i class="fas fa-sync"></i>
                                Sync
                            </button>
                            <button class="btn btn-secondary btn-sm" onclick="viewContent('<?= $repo['id'] ?>')">
                                <i class="fas fa-eye"></i>
                            </button>
                            <?php if ($isSuperAdmin): ?>
                            <button class="btn btn-secondary btn-sm" onclick="editRepository('<?= $repo['id'] ?>')">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button class="btn btn-danger btn-sm" onclick="deleteRepository('<?= $repo['id'] ?>')">
                                <i class="fas fa-trash"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Providers Tab -->
            <div class="tab-content" id="tab-providers">
                <?php if ($isSuperAdmin): ?>
                <div class="action-bar">
                    <button class="btn btn-primary" onclick="showProviderModal()">
                        <i class="fas fa-plus"></i>
                        Add Provider
                    </button>
                </div>
                <?php endif; ?>

                <?php if (empty($providers)): ?>
                <div class="empty-state">
                    <i class="fab fa-git-alt"></i>
                    <h3>No Git providers configured</h3>
                    <p>Add a provider to authenticate with private repositories</p>
                    <?php if ($isSuperAdmin): ?>
                    <button class="btn btn-primary" onclick="showProviderModal()" style="margin-top: 16px;">
                        <i class="fas fa-plus"></i>
                        Add Provider
                    </button>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <div class="items-list">
                    <?php foreach ($providers as $provider): ?>
                    <div class="item-card" data-id="<?= htmlspecialchars($provider['id']) ?>">
                        <div class="item-info">
                            <div class="item-icon <?= htmlspecialchars($provider['type']) ?>">
                                <?php
                                $icon = match($provider['type']) {
                                    'github' => 'fab fa-github',
                                    'gitlab' => 'fab fa-gitlab',
                                    'bitbucket' => 'fab fa-bitbucket',
                                    default => 'fas fa-code-branch'
                                };
                                ?>
                                <i class="<?= $icon ?>"></i>
                            </div>
                            <div class="item-details">
                                <h4 class="item-name"><?= htmlspecialchars($provider['name']) ?></h4>
                                <p class="item-desc"><?= htmlspecialchars($provider['description'] ?? ucfirst($provider['type']) . ' integration') ?></p>
                                <div class="item-meta">
                                    <span class="meta-item">
                                        <i class="fas fa-link"></i>
                                        <?= htmlspecialchars($provider['api_url'] ?? 'Default API') ?>
                                    </span>
                                    <span class="meta-item">
                                        <i class="fas fa-key"></i>
                                        <?= $provider['hasToken'] ? 'Token configured' : 'No token' ?>
                                    </span>
                                    <?php if ($provider['last_tested_at']): ?>
                                    <span class="meta-item">
                                        <i class="fas fa-check-circle"></i>
                                        Tested <?= date('M j', strtotime($provider['last_tested_at'])) ?>
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="item-status">
                            <?php if ($provider['last_test_success'] === 1): ?>
                            <span class="status-dot success"></span>
                            <span style="font-size: 12px; color: var(--success);">Connected</span>
                            <?php elseif ($provider['last_test_success'] === 0): ?>
                            <span class="status-dot error"></span>
                            <span style="font-size: 12px; color: var(--error);">Failed</span>
                            <?php else: ?>
                            <span class="status-dot unknown"></span>
                            <span style="font-size: 12px; color: var(--text-muted);">Not tested</span>
                            <?php endif; ?>
                        </div>
                        <div class="item-actions">
                            <button class="btn btn-secondary btn-sm" onclick="testProvider('<?= $provider['id'] ?>')">
                                <i class="fas fa-plug"></i>
                                Test
                            </button>
                            <?php if ($isSuperAdmin): ?>
                            <button class="btn btn-secondary btn-sm" onclick="editProvider('<?= $provider['id'] ?>')">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button class="btn btn-danger btn-sm" onclick="deleteProvider('<?= $provider['id'] ?>')">
                                <i class="fas fa-trash"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Provider Modal -->
    <div class="modal-backdrop" id="providerModal">
        <div class="modal">
            <div class="modal-header">
                <h2><i class="fab fa-git-alt"></i> <span id="providerModalTitle">Add Git Provider</span></h2>
                <button class="modal-close" onclick="closeModal('providerModal')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="providerEditId">
                
                <div class="form-group">
                    <label class="form-label">Provider Type *</label>
                    <div class="type-select" id="providerType">
                        <label class="type-option selected" data-type="github">
                            <input type="radio" name="providerType" value="github" checked>
                            <div class="icon" style="background: rgba(255,255,255,0.1); color: var(--text-primary);">
                                <i class="fab fa-github"></i>
                            </div>
                            <span class="label">GitHub</span>
                        </label>
                        <label class="type-option" data-type="gitlab">
                            <input type="radio" name="providerType" value="gitlab">
                            <div class="icon" style="background: rgba(252, 109, 38, 0.15); color: #fc6d26;">
                                <i class="fab fa-gitlab"></i>
                            </div>
                            <span class="label">GitLab</span>
                        </label>
                        <label class="type-option" data-type="bitbucket">
                            <input type="radio" name="providerType" value="bitbucket">
                            <div class="icon" style="background: rgba(0, 82, 204, 0.15); color: #0052cc;">
                                <i class="fab fa-bitbucket"></i>
                            </div>
                            <span class="label">Bitbucket</span>
                        </label>
                        <label class="type-option" data-type="custom">
                            <input type="radio" name="providerType" value="custom">
                            <div class="icon" style="background: rgba(139, 92, 246, 0.15); color: #8b5cf6;">
                                <i class="fas fa-code-branch"></i>
                            </div>
                            <span class="label">Custom</span>
                        </label>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">ID *</label>
                        <input type="text" class="form-input" id="providerId" placeholder="my-github">
                        <div class="form-hint">Unique identifier</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Name *</label>
                        <input type="text" class="form-input" id="providerName" placeholder="My GitHub">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">API URL</label>
                    <input type="text" class="form-input" id="providerApiUrl" placeholder="https://api.github.com">
                    <div class="form-hint">Leave empty for default</div>
                </div>

                <div class="form-group">
                    <label class="form-label">Access Token *</label>
                    <input type="password" class="form-input" id="providerToken" placeholder="ghp_xxxxxxxxxxxx">
                    <div class="form-hint">Personal access token with repo access</div>
                </div>

                <div class="form-group">
                    <label class="form-label">Username (Bitbucket only)</label>
                    <input type="text" class="form-input" id="providerUsername" placeholder="username">
                    <div class="form-hint">Required for Bitbucket app passwords</div>
                </div>

                <div class="form-group">
                    <label class="form-label">Description</label>
                    <input type="text" class="form-input" id="providerDescription" placeholder="Company GitHub account">
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('providerModal')">Cancel</button>
                <button class="btn btn-primary" onclick="saveProvider()">
                    <i class="fas fa-save"></i>
                    Save Provider
                </button>
            </div>
        </div>
    </div>

    <!-- Repository Modal -->
    <div class="modal-backdrop" id="repositoryModal">
        <div class="modal">
            <div class="modal-header">
                <h2><i class="fas fa-folder-tree"></i> <span id="repositoryModalTitle">Add Repository</span></h2>
                <button class="modal-close" onclick="closeModal('repositoryModal')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="repoEditId">
                
                <div class="form-group">
                    <label class="form-label">Repository Type *</label>
                    <div class="type-select" id="repoType">
                        <label class="type-option selected" data-type="apps">
                            <input type="radio" name="repoType" value="apps" checked>
                            <div class="icon" style="background: rgba(139, 92, 246, 0.15); color: #8b5cf6;">
                                <i class="fas fa-puzzle-piece"></i>
                            </div>
                            <span class="label">Apps</span>
                        </label>
                        <label class="type-option" data-type="templates">
                            <input type="radio" name="repoType" value="templates">
                            <div class="icon" style="background: rgba(59, 130, 246, 0.15); color: #3b82f6;">
                                <i class="fas fa-cubes"></i>
                            </div>
                            <span class="label">Templates</span>
                        </label>
                        <label class="type-option" data-type="playwright-tests">
                            <input type="radio" name="repoType" value="playwright-tests">
                            <div class="icon" style="background: rgba(34, 197, 94, 0.15); color: #22c55e;">
                                <i class="fas fa-vial"></i>
                            </div>
                            <span class="label">Tests</span>
                        </label>
                        <label class="type-option" data-type="mixed">
                            <input type="radio" name="repoType" value="mixed">
                            <div class="icon" style="background: rgba(245, 158, 11, 0.15); color: #f59e0b;">
                                <i class="fas fa-layer-group"></i>
                            </div>
                            <span class="label">Mixed</span>
                        </label>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">ID *</label>
                        <input type="text" class="form-input" id="repoId" placeholder="company-apps">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Name *</label>
                        <input type="text" class="form-input" id="repoName" placeholder="Company Apps">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Git URL *</label>
                    <input type="text" class="form-input" id="repoUrl" placeholder="https://github.com/org/doki-apps.git">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Branch</label>
                        <input type="text" class="form-input" id="repoBranch" value="main">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Git Provider</label>
                        <select class="form-select" id="repoProvider">
                            <option value="">-- Public (no auth) --</option>
                            <?php foreach ($providers as $p): ?>
                            <option value="<?= htmlspecialchars($p['id']) ?>"><?= htmlspecialchars($p['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Version Pinning</label>
                    <div class="form-row">
                        <select class="form-select" id="repoPinType">
                            <option value="">-- No pin (follow branch) --</option>
                            <option value="tag">Tag</option>
                            <option value="commit">Commit</option>
                        </select>
                        <input type="text" class="form-input" id="repoPinnedRef" placeholder="v1.0.0 or commit SHA">
                    </div>
                </div>

                <div class="form-group">
                    <label class="checkbox-group">
                        <input type="checkbox" id="repoAutoSync">
                        <span>Enable auto-sync before operations</span>
                    </label>
                </div>

                <div class="form-group">
                    <label class="form-label">Description</label>
                    <input type="text" class="form-input" id="repoDescription" placeholder="Company shared apps repository">
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('repositoryModal')">Cancel</button>
                <button class="btn btn-primary" onclick="saveRepository()">
                    <i class="fas fa-save"></i>
                    Save Repository
                </button>
            </div>
        </div>
    </div>

    <!-- Content Modal -->
    <div class="modal-backdrop" id="contentModal">
        <div class="modal">
            <div class="modal-header">
                <h2><i class="fas fa-folder-open"></i> Repository Content</h2>
                <button class="modal-close" onclick="closeModal('contentModal')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body" id="contentModalBody">
                <div class="empty-state">
                    <i class="fas fa-spinner fa-spin"></i>
                    <p>Loading...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('contentModal')">Close</button>
            </div>
        </div>
    </div>

    <script>
        // Tab switching
        function switchTab(tab) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelector(`[data-tab="${tab}"]`).classList.add('active');
            
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            document.getElementById(`tab-${tab}`).classList.add('active');
        }

        // Modal helpers
        function showModal(id) {
            document.getElementById(id).classList.add('active');
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('active');
        }

        // Type selectors
        document.querySelectorAll('.type-select').forEach(container => {
            container.querySelectorAll('.type-option').forEach(opt => {
                opt.addEventListener('click', function() {
                    container.querySelectorAll('.type-option').forEach(o => o.classList.remove('selected'));
                    this.classList.add('selected');
                    this.querySelector('input').checked = true;
                });
            });
        });

        // ========================
        // PROVIDERS
        // ========================

        function showProviderModal(editId = null) {
            document.getElementById('providerEditId').value = editId || '';
            document.getElementById('providerModalTitle').textContent = editId ? 'Edit Git Provider' : 'Add Git Provider';
            
            // Reset form
            document.getElementById('providerId').value = '';
            document.getElementById('providerName').value = '';
            document.getElementById('providerApiUrl').value = '';
            document.getElementById('providerToken').value = '';
            document.getElementById('providerUsername').value = '';
            document.getElementById('providerDescription').value = '';
            
            document.querySelectorAll('#providerType .type-option').forEach(o => o.classList.remove('selected'));
            document.querySelector('#providerType [data-type="github"]').classList.add('selected');
            document.querySelector('#providerType input[value="github"]').checked = true;
            
            document.getElementById('providerId').disabled = !!editId;
            
            showModal('providerModal');
        }

        async function editProvider(id) {
            try {
                const response = await fetch(`../api/sources.php?action=provider&id=${id}`);
                const data = await response.json();
                
                if (data.success) {
                    const p = data.provider;
                    document.getElementById('providerEditId').value = id;
                    document.getElementById('providerModalTitle').textContent = 'Edit Git Provider';
                    document.getElementById('providerId').value = p.id;
                    document.getElementById('providerId').disabled = true;
                    document.getElementById('providerName').value = p.name;
                    document.getElementById('providerApiUrl').value = p.api_url || '';
                    document.getElementById('providerToken').value = p.token || '';
                    document.getElementById('providerUsername').value = p.username || '';
                    document.getElementById('providerDescription').value = p.description || '';
                    
                    document.querySelectorAll('#providerType .type-option').forEach(o => {
                        o.classList.remove('selected');
                        if (o.dataset.type === p.type) {
                            o.classList.add('selected');
                            o.querySelector('input').checked = true;
                        }
                    });
                    
                    showModal('providerModal');
                }
            } catch (e) {
                Toast.error('Failed to load provider');
            }
        }

        async function saveProvider() {
            const editId = document.getElementById('providerEditId').value;
            const type = document.querySelector('#providerType .type-option.selected').dataset.type;
            
            const data = {
                id: document.getElementById('providerId').value.trim(),
                name: document.getElementById('providerName').value.trim(),
                type: type,
                apiUrl: document.getElementById('providerApiUrl').value.trim() || null,
                token: document.getElementById('providerToken').value.trim() || null,
                username: document.getElementById('providerUsername').value.trim() || null,
                description: document.getElementById('providerDescription').value.trim() || null
            };
            
            if (!data.id || !data.name) {
                Toast.error('ID and Name are required');
                return;
            }
            
            try {
                const action = editId ? `update-provider&id=${editId}` : 'create-provider';
                const response = await fetch(`../api/sources.php?action=${action}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
                const result = await response.json();
                
                if (result.success) {
                    Toast.success('Provider saved');
                    closeModal('providerModal');
                    location.reload();
                } else {
                    Toast.error(result.error || 'Failed to save');
                }
            } catch (e) {
                Toast.error('Failed to save provider');
            }
        }

        async function testProvider(id) {
            Toast.info('Testing connection...');
            
            try {
                const response = await fetch(`../api/sources.php?action=test-provider&id=${id}`, {
                    method: 'POST'
                });
                const data = await response.json();
                
                if (data.success) {
                    Toast.success(data.message || 'Connection successful');
                    location.reload();
                } else {
                    Toast.error(data.error || 'Connection failed');
                }
            } catch (e) {
                Toast.error('Test failed');
            }
        }

        async function deleteProvider(id) {
            if (!confirm('Delete this provider? Repositories using it will lose authentication.')) return;
            
            try {
                const response = await fetch(`../api/sources.php?action=delete-provider&id=${id}`, {
                    method: 'POST'
                });
                const data = await response.json();
                
                if (data.success) {
                    Toast.success('Provider deleted');
                    location.reload();
                } else {
                    Toast.error(data.error || 'Failed to delete');
                }
            } catch (e) {
                Toast.error('Failed to delete provider');
            }
        }

        // ========================
        // REPOSITORIES
        // ========================

        function showRepositoryModal(editId = null) {
            document.getElementById('repoEditId').value = editId || '';
            document.getElementById('repositoryModalTitle').textContent = editId ? 'Edit Repository' : 'Add Repository';
            
            // Reset form
            document.getElementById('repoId').value = '';
            document.getElementById('repoName').value = '';
            document.getElementById('repoUrl').value = '';
            document.getElementById('repoBranch').value = 'main';
            document.getElementById('repoProvider').value = '';
            document.getElementById('repoPinType').value = '';
            document.getElementById('repoPinnedRef').value = '';
            document.getElementById('repoAutoSync').checked = false;
            document.getElementById('repoDescription').value = '';
            
            document.querySelectorAll('#repoType .type-option').forEach(o => o.classList.remove('selected'));
            document.querySelector('#repoType [data-type="apps"]').classList.add('selected');
            document.querySelector('#repoType input[value="apps"]').checked = true;
            
            document.getElementById('repoId').disabled = !!editId;
            
            showModal('repositoryModal');
        }

        async function editRepository(id) {
            try {
                const response = await fetch(`../api/sources.php?action=repository&id=${id}`);
                const data = await response.json();
                
                if (data.success) {
                    const r = data.repository;
                    document.getElementById('repoEditId').value = id;
                    document.getElementById('repositoryModalTitle').textContent = 'Edit Repository';
                    document.getElementById('repoId').value = r.id;
                    document.getElementById('repoId').disabled = true;
                    document.getElementById('repoName').value = r.name;
                    document.getElementById('repoUrl').value = r.url;
                    document.getElementById('repoBranch').value = r.branch || 'main';
                    document.getElementById('repoProvider').value = r.provider_id || '';
                    document.getElementById('repoPinType').value = r.pin_type || '';
                    document.getElementById('repoPinnedRef').value = r.pinned_ref || '';
                    document.getElementById('repoAutoSync').checked = !!r.auto_sync;
                    document.getElementById('repoDescription').value = r.description || '';
                    
                    document.querySelectorAll('#repoType .type-option').forEach(o => {
                        o.classList.remove('selected');
                        if (o.dataset.type === r.type) {
                            o.classList.add('selected');
                            o.querySelector('input').checked = true;
                        }
                    });
                    
                    showModal('repositoryModal');
                }
            } catch (e) {
                Toast.error('Failed to load repository');
            }
        }

        async function saveRepository() {
            const editId = document.getElementById('repoEditId').value;
            const type = document.querySelector('#repoType .type-option.selected').dataset.type;
            
            const data = {
                id: document.getElementById('repoId').value.trim(),
                name: document.getElementById('repoName').value.trim(),
                url: document.getElementById('repoUrl').value.trim(),
                type: type,
                branch: document.getElementById('repoBranch').value.trim() || 'main',
                providerId: document.getElementById('repoProvider').value || null,
                pinType: document.getElementById('repoPinType').value || null,
                pinnedRef: document.getElementById('repoPinnedRef').value.trim() || null,
                autoSync: document.getElementById('repoAutoSync').checked,
                description: document.getElementById('repoDescription').value.trim() || null
            };
            
            if (!data.id || !data.name || !data.url) {
                Toast.error('ID, Name, and URL are required');
                return;
            }
            
            try {
                const action = editId ? `update-repository&id=${editId}` : 'create-repository';
                const response = await fetch(`../api/sources.php?action=${action}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
                const result = await response.json();
                
                if (result.success) {
                    Toast.success('Repository saved');
                    closeModal('repositoryModal');
                    location.reload();
                } else {
                    Toast.error(result.error || 'Failed to save');
                }
            } catch (e) {
                Toast.error('Failed to save repository');
            }
        }

        async function syncRepository(id) {
            Toast.info('Syncing repository...');
            
            try {
                const response = await fetch(`../api/sources.php?action=sync-repository&id=${id}`, {
                    method: 'POST'
                });
                const data = await response.json();
                
                if (data.success) {
                    const discovered = data.discovered || {};
                    const parts = [];
                    if (discovered.apps?.length) parts.push(`${discovered.apps.length} apps`);
                    if (discovered.templates?.length) parts.push(`${discovered.templates.length} templates`);
                    if (discovered.tests?.length) parts.push(`${discovered.tests.length} test projects`);
                    
                    Toast.success('Synced! ' + (parts.length ? 'Found: ' + parts.join(', ') : ''));
                    location.reload();
                } else {
                    Toast.error(data.error || 'Sync failed');
                }
            } catch (e) {
                Toast.error('Sync failed');
            }
        }

        async function viewContent(id) {
            showModal('contentModal');
            document.getElementById('contentModalBody').innerHTML = `
                <div class="empty-state">
                    <i class="fas fa-spinner fa-spin"></i>
                    <p>Loading content...</p>
                </div>
            `;
            
            try {
                const response = await fetch(`../api/sources.php?action=discover-content&id=${id}`);
                const data = await response.json();
                
                if (data.success) {
                    const content = data.content;
                    let html = '';
                    
                    if (content.apps?.length) {
                        html += `<h4 style="margin: 0 0 12px 0;"><i class="fas fa-puzzle-piece"></i> Apps (${content.apps.length})</h4>`;
                        html += '<ul style="margin: 0 0 20px 0; padding-left: 20px;">';
                        content.apps.forEach(app => {
                            html += `<li>${escapeHtml(app.name || app.id)} <span style="color: var(--text-muted);">v${app.version || '1.0'}</span></li>`;
                        });
                        html += '</ul>';
                    }
                    
                    if (content.templates?.length) {
                        html += `<h4 style="margin: 0 0 12px 0;"><i class="fas fa-cubes"></i> Templates (${content.templates.length})</h4>`;
                        html += '<ul style="margin: 0 0 20px 0; padding-left: 20px;">';
                        content.templates.forEach(t => {
                            html += `<li>${escapeHtml(t.name || t.id)}</li>`;
                        });
                        html += '</ul>';
                    }
                    
                    if (content.tests?.length) {
                        html += `<h4 style="margin: 0 0 12px 0;"><i class="fas fa-vial"></i> Test Projects (${content.tests.length})</h4>`;
                        html += '<ul style="margin: 0 0 20px 0; padding-left: 20px;">';
                        content.tests.forEach(t => {
                            html += `<li>${escapeHtml(t.path)} <span style="color: var(--text-muted);">${t.testCount} tests</span></li>`;
                        });
                        html += '</ul>';
                    }
                    
                    if (!html) {
                        html = '<div class="empty-state"><i class="fas fa-folder-open"></i><p>No content discovered. Make sure the repository is synced.</p></div>';
                    }
                    
                    document.getElementById('contentModalBody').innerHTML = html;
                } else {
                    document.getElementById('contentModalBody').innerHTML = `
                        <div class="empty-state">
                            <i class="fas fa-exclamation-triangle"></i>
                            <p>${escapeHtml(data.error || 'Failed to load content')}</p>
                        </div>
                    `;
                }
            } catch (e) {
                document.getElementById('contentModalBody').innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-exclamation-triangle"></i>
                        <p>Failed to load content</p>
                    </div>
                `;
            }
        }

        async function deleteRepository(id) {
            if (!confirm('Delete this repository? This will remove the cached data.')) return;
            
            try {
                const response = await fetch(`../api/sources.php?action=delete-repository&id=${id}`, {
                    method: 'POST'
                });
                const data = await response.json();
                
                if (data.success) {
                    Toast.success('Repository deleted');
                    location.reload();
                } else {
                    Toast.error(data.error || 'Failed to delete');
                }
            } catch (e) {
                Toast.error('Failed to delete repository');
            }
        }

        // Escape HTML
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Close modals on backdrop click
        document.querySelectorAll('.modal-backdrop').forEach(backdrop => {
            backdrop.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.classList.remove('active');
                }
            });
        });

        // Close on Escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal-backdrop.active').forEach(m => m.classList.remove('active'));
            }
        });
    </script>
</body>
</html>
