<?php
/**
 * Templates Page - View available templates (read-only)
 */
require_once __DIR__ . '/includes/middleware.php';
requireAuth();
requireModuleAccess('templates');

$user = getCurrentUser();
$layout = new Layout($user, 'templates');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $layout->renderHead('Templates'); ?>
    <style>
        /* Templates Grid */
        .templates-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 20px;
        }

        /* Template Card */
        .template-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-primary);
            border-radius: 12px;
            padding: 24px;
            transition: all 0.2s ease;
            cursor: pointer;
            position: relative;
            overflow: hidden;
        }

        .template-card:hover {
            border-color: var(--accent-primary);
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2);
        }

        .template-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--card-accent, var(--accent-primary));
        }

        .template-header {
            display: flex;
            align-items: flex-start;
            gap: 16px;
            margin-bottom: 16px;
        }

        .template-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            background: var(--bg-tertiary);
            color: var(--card-accent, var(--text-primary));
            flex-shrink: 0;
        }

        .template-info {
            flex: 1;
            min-width: 0;
        }

        .template-name {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 4px;
            color: var(--text-primary);
        }

        .template-version {
            font-size: 12px;
            color: var(--text-muted);
            background: var(--bg-tertiary);
            padding: 2px 8px;
            border-radius: 4px;
            display: inline-block;
        }

        .template-description {
            color: var(--text-secondary);
            font-size: 14px;
            line-height: 1.5;
            margin-bottom: 16px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .template-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .template-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
            background: var(--bg-tertiary);
            color: var(--text-secondary);
        }

        .template-badge i {
            font-size: 10px;
        }

        /* Category Filter */
        .filter-bar {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }

        .filter-btn {
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 500;
            background: var(--bg-tertiary);
            border: 1px solid var(--border-primary);
            color: var(--text-secondary);
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .filter-btn:hover {
            background: var(--bg-hover);
            color: var(--text-primary);
        }

        .filter-btn.active {
            background: var(--accent-primary);
            border-color: var(--accent-primary);
            color: var(--bg-primary);
        }

        .search-box {
            flex: 1;
            min-width: 200px;
            max-width: 300px;
        }

        /* Template Modal */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.7);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            padding: 24px;
            opacity: 0;
            visibility: hidden;
            transition: all 0.2s ease;
        }

        .modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .modal {
            background: var(--bg-secondary);
            border: 1px solid var(--border-primary);
            border-radius: 16px;
            width: 100%;
            max-width: 700px;
            max-height: 85vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            transform: scale(0.95);
            transition: transform 0.2s ease;
        }

        .modal-overlay.active .modal {
            transform: scale(1);
        }

        .modal-header {
            padding: 24px;
            border-bottom: 1px solid var(--border-primary);
            display: flex;
            align-items: flex-start;
            gap: 16px;
        }

        .modal-header .template-icon {
            width: 56px;
            height: 56px;
            font-size: 24px;
        }

        .modal-header-info {
            flex: 1;
        }

        .modal-title {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 4px;
        }

        .modal-subtitle {
            color: var(--text-secondary);
            font-size: 14px;
        }

        .modal-close {
            background: none;
            border: none;
            color: var(--text-muted);
            font-size: 20px;
            cursor: pointer;
            padding: 4px;
        }

        .modal-close:hover {
            color: var(--text-primary);
        }

        .modal-body {
            padding: 24px;
            overflow-y: auto;
            flex: 1;
        }

        .modal-section {
            margin-bottom: 24px;
        }

        .modal-section:last-child {
            margin-bottom: 0;
        }

        .modal-section-title {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 12px;
        }

        /* Input List */
        .input-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .input-item {
            background: var(--bg-tertiary);
            border-radius: 8px;
            padding: 12px 16px;
        }

        .input-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 4px;
        }

        .input-name {
            font-weight: 600;
            font-size: 14px;
        }

        .input-type {
            font-size: 12px;
            color: var(--text-muted);
            background: var(--bg-primary);
            padding: 2px 8px;
            border-radius: 4px;
        }

        .input-description {
            font-size: 13px;
            color: var(--text-secondary);
        }

        .input-required {
            color: var(--error);
            font-size: 11px;
            margin-left: 6px;
        }

        .input-default {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 4px;
        }

        /* Requirements List */
        .requirements-list {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .requirement-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 13px;
            background: var(--bg-tertiary);
            color: var(--text-secondary);
        }

        .requirement-badge i {
            color: var(--success);
        }

        /* Runtime Info */
        .runtime-info {
            background: var(--bg-tertiary);
            border-radius: 8px;
            padding: 16px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .runtime-info i {
            font-size: 24px;
            color: var(--accent-primary);
        }

        .runtime-details {
            flex: 1;
        }

        .runtime-label {
            font-size: 12px;
            color: var(--text-muted);
        }

        .runtime-image {
            font-family: 'JetBrains Mono', monospace;
            font-size: 14px;
            color: var(--text-primary);
        }

        /* Modal Footer */
        .modal-footer {
            padding: 16px 24px;
            border-top: 1px solid var(--border-primary);
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-secondary);
        }

        .empty-state > i {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.5;
        }

        .empty-state h3 {
            font-size: 18px;
            margin-bottom: 8px;
            color: var(--text-primary);
        }

        /* Loading State */
        .loading {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 60px;
            color: var(--text-secondary);
        }

        .loading i {
            font-size: 24px;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <?php $layout->renderSidebar(); ?>

    <main class="main-content">
        <div class="page-header">
            <div>
                <h1 class="page-title">Templates</h1>
                <p class="page-subtitle">Available tool templates (read-only)</p>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="filter-bar">
            <button class="filter-btn active" data-category="all">All</button>
            <div id="categoryFilters"></div>
            <div class="search-box">
                <input type="text" class="form-input" placeholder="Search templates..." id="searchInput">
            </div>
        </div>

        <!-- Templates Grid -->
        <div class="templates-grid" id="templatesGrid">
            <div class="loading">
                <i class="fas fa-spinner"></i>
            </div>
        </div>
    </main>

    <!-- Template Detail Modal -->
    <div class="modal-overlay" id="templateModal">
        <div class="modal">
            <div class="modal-header">
                <div class="template-icon" id="modalIcon">
                    <i class="fas fa-cog"></i>
                </div>
                <div class="modal-header-info">
                    <h2 class="modal-title" id="modalTitle">Template Name</h2>
                    <p class="modal-subtitle" id="modalSubtitle">v1.0.0</p>
                </div>
                <button class="modal-close" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <div class="modal-section">
                    <div class="modal-section-title">Description</div>
                    <p id="modalDescription" style="color: var(--text-secondary); line-height: 1.6;"></p>
                </div>

                <div class="modal-section">
                    <div class="modal-section-title">Runtime</div>
                    <div class="runtime-info">
                        <i class="fab fa-docker"></i>
                        <div class="runtime-details">
                            <div class="runtime-label">Docker Image</div>
                            <div class="runtime-image" id="modalRuntime">alpine:latest</div>
                        </div>
                    </div>
                </div>

                <div class="modal-section" id="inputsSection">
                    <div class="modal-section-title">Inputs</div>
                    <div class="input-list" id="modalInputs"></div>
                </div>

                <div class="modal-section" id="requirementsSection">
                    <div class="modal-section-title">Requirements</div>
                    <div class="requirements-list" id="modalRequirements"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal()">Close</button>
                <a href="#" class="btn btn-primary" id="createCommandBtn">
                    <i class="fas fa-plus"></i>
                    Create Command
                </a>
            </div>
        </div>
    </div>

    <script>
        let templates = [];
        let categories = [];
        let currentFilter = 'all';
        let searchQuery = '';

        // Initialize
        document.addEventListener('DOMContentLoaded', () => {
            loadTemplates();
            
            // Search input
            document.getElementById('searchInput').addEventListener('input', (e) => {
                searchQuery = e.target.value.toLowerCase();
                renderTemplates();
            });
        });

        // Load templates
        async function loadTemplates() {
            try {
                const response = await fetch('api/templates.php?action=list');
                const data = await response.json();
                
                if (data.success) {
                    templates = data.templates;
                    extractCategories();
                    renderCategoryFilters();
                    renderTemplates();
                } else {
                    showError('Failed to load templates: ' + (data.error || 'Unknown error'));
                }
            } catch (error) {
                console.error('Error loading templates:', error);
                showError('Failed to load templates');
            }
        }

        // Extract unique categories
        function extractCategories() {
            const cats = {};
            templates.forEach(t => {
                if (!cats[t.category]) {
                    cats[t.category] = { id: t.category, name: formatCategoryName(t.category), count: 0 };
                }
                cats[t.category].count++;
            });
            categories = Object.values(cats);
        }

        // Format category name
        function formatCategoryName(cat) {
            return cat.charAt(0).toUpperCase() + cat.slice(1).replace(/-/g, ' ');
        }

        // Render category filter buttons
        function renderCategoryFilters() {
            const container = document.getElementById('categoryFilters');
            container.innerHTML = categories.map(cat => `
                <button class="filter-btn" data-category="${cat.id}">
                    ${escapeHtml(cat.name)} (${cat.count})
                </button>
            `).join('');
            
            // Add click handlers
            document.querySelectorAll('.filter-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    currentFilter = btn.dataset.category;
                    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');
                    renderTemplates();
                });
            });
        }

        // Render templates grid
        function renderTemplates() {
            const grid = document.getElementById('templatesGrid');
            
            // Filter templates
            let filtered = templates.filter(t => {
                if (currentFilter !== 'all' && t.category !== currentFilter) return false;
                if (searchQuery) {
                    const searchFields = [t.name, t.description, t.id].join(' ').toLowerCase();
                    if (!searchFields.includes(searchQuery)) return false;
                }
                return true;
            });
            
            if (filtered.length === 0) {
                grid.innerHTML = `
                    <div class="empty-state" style="grid-column: 1 / -1;">
                        <i class="fas fa-cubes"></i>
                        <h3>No templates found</h3>
                        <p>${searchQuery ? 'Try a different search term' : 'No templates in this category'}</p>
                    </div>
                `;
                return;
            }
            
            grid.innerHTML = filtered.map(template => `
                <div class="template-card" onclick="showTemplate('${template.id}')" style="--card-accent: ${template.color}">
                    <div class="template-header">
                        <div class="template-icon" style="color: ${template.color}">
                            <i class="${template.icon}"></i>
                        </div>
                        <div class="template-info">
                            <div class="template-name">${escapeHtml(template.name)}</div>
                            <span class="template-version">v${escapeHtml(template.version)}</span>
                        </div>
                    </div>
                    <div class="template-description">${escapeHtml(template.description)}</div>
                    <div class="template-meta">
                        <span class="template-badge">
                            <i class="fas fa-layer-group"></i>
                            ${escapeHtml(formatCategoryName(template.category))}
                        </span>
                        <span class="template-badge">
                            <i class="fas fa-sliders-h"></i>
                            ${template.inputs.length} inputs
                        </span>
                        ${template.requirements.length > 0 ? `
                            <span class="template-badge">
                                <i class="fas fa-check-circle"></i>
                                ${template.requirements.length} requirements
                            </span>
                        ` : ''}
                    </div>
                </div>
            `).join('');
        }

        // Show template details
        function showTemplate(id) {
            const template = templates.find(t => t.id === id);
            if (!template) return;
            
            // Set header
            document.getElementById('modalIcon').innerHTML = `<i class="${template.icon}"></i>`;
            document.getElementById('modalIcon').style.color = template.color;
            document.getElementById('modalTitle').textContent = template.name;
            document.getElementById('modalSubtitle').textContent = `v${template.version} • ${formatCategoryName(template.category)}`;
            document.getElementById('modalDescription').textContent = template.description;
            
            // Set runtime
            document.getElementById('modalRuntime').textContent = template.runtime?.image || 'alpine:latest';
            
            // Set inputs
            const inputsContainer = document.getElementById('modalInputs');
            const inputsSection = document.getElementById('inputsSection');
            
            if (template.inputs.length === 0) {
                inputsSection.style.display = 'none';
            } else {
                inputsSection.style.display = 'block';
                inputsContainer.innerHTML = template.inputs.map(input => `
                    <div class="input-item">
                        <div class="input-header">
                            <span class="input-name">
                                ${escapeHtml(input.label || input.name)}
                                ${input.required ? '<span class="input-required">required</span>' : ''}
                            </span>
                            <span class="input-type">${escapeHtml(input.type || 'string')}</span>
                        </div>
                        ${input.description ? `<div class="input-description">${escapeHtml(input.description)}</div>` : ''}
                        ${input.default !== undefined ? `<div class="input-default">Default: <code>${escapeHtml(String(input.default))}</code></div>` : ''}
                    </div>
                `).join('');
            }
            
            // Set requirements
            const requirementsContainer = document.getElementById('modalRequirements');
            const requirementsSection = document.getElementById('requirementsSection');
            
            if (template.requirements.length === 0) {
                requirementsSection.style.display = 'none';
            } else {
                requirementsSection.style.display = 'block';
                requirementsContainer.innerHTML = template.requirements.map(req => `
                    <span class="requirement-badge">
                        <i class="fas fa-check"></i>
                        ${escapeHtml(req)}
                    </span>
                `).join('');
            }
            
            // Set create command link
            document.getElementById('createCommandBtn').href = `commands.php?action=new&template=${template.id}`;
            
            // Show modal
            document.getElementById('templateModal').classList.add('active');
        }

        // Close modal
        function closeModal() {
            document.getElementById('templateModal').classList.remove('active');
        }

        // Close modal on overlay click
        document.getElementById('templateModal').addEventListener('click', (e) => {
            if (e.target.classList.contains('modal-overlay')) {
                closeModal();
            }
        });

        // Close modal on escape
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeModal();
        });

        // Show error
        function showError(message) {
            const grid = document.getElementById('templatesGrid');
            grid.innerHTML = `
                <div class="empty-state" style="grid-column: 1 / -1;">
                    <i class="fas fa-exclamation-triangle" style="color: var(--error);"></i>
                    <h3>Error</h3>
                    <p>${escapeHtml(message)}</p>
                </div>
            `;
        }

        // Escape HTML
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    </script>
</body>
</html>
