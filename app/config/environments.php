<?php
/**
 * config/environments.php - Environments Configuration Page
 * 
 * Part of Doki v3 Architecture - Batch 2: Infrastructure Config
 */

require_once __DIR__ . '/../includes/middleware.php';
require_once __DIR__ . '/../includes/Layout.php';

// Require admin role
$user = requireRole('admin');
requireModuleAccess('environments');

// Create layout instance
$layout = new Layout($user, 'environments');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $layout->renderHead('Environments Configuration'); ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/codemirror.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/theme/material-darker.min.css">
    <style>
        .config-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 16px;
        }

        .config-actions {
            display: flex;
            gap: 12px;
        }

        .view-toggle {
            display: flex;
            background: var(--bg-tertiary);
            border-radius: 8px;
            padding: 4px;
        }

        .view-toggle button {
            padding: 8px 16px;
            border: none;
            background: transparent;
            color: var(--text-secondary);
            font-family: inherit;
            font-size: 14px;
            cursor: pointer;
            border-radius: 6px;
            transition: all 0.15s;
        }

        .view-toggle button.active {
            background: var(--accent-primary);
            color: var(--bg-primary);
        }

        .view-toggle button:hover:not(.active) {
            color: var(--text-primary);
        }

        /* Cards View */
        .environments-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
        }

        .env-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-primary);
            border-radius: 12px;
            overflow: hidden;
            transition: all 0.15s;
        }

        .env-card:hover {
            border-color: var(--accent-primary);
        }

        .env-card-header {
            padding: 20px;
            border-bottom: 1px solid var(--border-primary);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .env-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .env-color {
            width: 12px;
            height: 12px;
            border-radius: 50%;
        }

        .env-name {
            font-size: 18px;
            font-weight: 600;
        }

        .env-id {
            font-family: 'JetBrains Mono', monospace;
            font-size: 12px;
            color: var(--text-muted);
            margin-left: 8px;
        }

        .env-card-body {
            padding: 20px;
        }

        .env-targets {
            margin-bottom: 16px;
        }

        .env-targets-title {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 12px;
        }

        .env-target-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 12px;
            background: var(--bg-tertiary);
            border-radius: 6px;
            margin-bottom: 8px;
            font-size: 14px;
        }

        .env-target-item:last-child {
            margin-bottom: 0;
        }

        .env-target-type {
            color: var(--text-muted);
        }

        .env-target-id {
            font-family: 'JetBrains Mono', monospace;
            font-size: 12px;
            color: var(--accent-primary);
        }

        .env-policies {
            padding: 12px;
            background: rgba(210, 153, 34, 0.1);
            border-radius: 8px;
            margin-top: 16px;
        }

        .env-policies-title {
            font-size: 12px;
            font-weight: 600;
            color: var(--warning);
            margin-bottom: 8px;
        }

        .env-policy-item {
            font-size: 13px;
            color: var(--text-secondary);
        }

        .env-card-footer {
            padding: 16px 20px;
            border-top: 1px solid var(--border-primary);
            display: flex;
            gap: 8px;
        }

        .env-card-footer .btn {
            flex: 1;
        }

        /* YAML Editor View */
        .yaml-editor-container {
            display: none;
        }

        .yaml-editor-container.active {
            display: block;
        }

        .cards-view-container.active {
            display: block;
        }

        .cards-view-container {
            display: none;
        }

        .CodeMirror {
            height: auto;
            min-height: 400px;
            max-height: 600px;
            border-radius: 8px;
            font-family: 'JetBrains Mono', monospace;
            font-size: 14px;
        }

        .editor-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 16px;
        }

        .validation-status {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
        }

        .validation-status.valid { color: var(--success); }
        .validation-status.invalid { color: var(--error); }
        .validation-status.checking { color: var(--text-muted); }

        .validation-errors {
            margin-top: 16px;
            padding: 16px;
            background: rgba(248, 81, 73, 0.1);
            border: 1px solid var(--error);
            border-radius: 8px;
        }

        .validation-errors h4 {
            color: var(--error);
            margin-bottom: 8px;
        }

        .validation-errors ul {
            margin: 0;
            padding-left: 20px;
            color: var(--error);
        }

        /* Modal */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal {
            background: var(--bg-secondary);
            border: 1px solid var(--border-primary);
            border-radius: 16px;
            width: 100%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            margin: 20px;
        }

        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 20px 24px;
            border-bottom: 1px solid var(--border-primary);
        }

        .modal-title {
            font-size: 18px;
            font-weight: 600;
        }

        .modal-close {
            width: 36px;
            height: 36px;
            background: var(--bg-tertiary);
            border: none;
            border-radius: 8px;
            color: var(--text-secondary);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.15s;
        }

        .modal-close:hover {
            background: var(--bg-hover);
            color: var(--text-primary);
        }

        .modal-body {
            padding: 24px;
        }

        .modal-footer {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            padding: 16px 24px;
            border-top: 1px solid var(--border-primary);
        }

        /* Target mappings */
        .target-mappings {
            background: var(--bg-tertiary);
            border-radius: 8px;
            padding: 16px;
        }

        .target-mapping-row {
            display: flex;
            gap: 12px;
            margin-bottom: 12px;
            align-items: center;
        }

        .target-mapping-row:last-child {
            margin-bottom: 0;
        }

        .target-mapping-row .form-input,
        .target-mapping-row .form-select {
            flex: 1;
        }
        
        .target-mapping-row .form-input {
            max-width: 150px;
        }

        .target-mapping-row .btn-icon {
            width: 36px;
            height: 36px;
            padding: 0;
            flex-shrink: 0;
        }

        /* Color picker */
        .color-picker-wrapper {
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .color-picker-wrapper input[type="color"] {
            width: 50px;
            height: 38px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
        }

        .color-picker-wrapper input[type="text"] {
            flex: 1;
        }

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 60px 24px;
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

        /* Alert */
        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert.success {
            background: rgba(63, 185, 80, 0.15);
            color: var(--success);
        }

        .alert.error {
            background: rgba(248, 81, 73, 0.15);
            color: var(--error);
        }
    </style>
</head>
<body>
    <div class="app-container">
        <?php $layout->renderSidebar(); ?>

        <main class="main-content">
            <div class="page-header">
                <div>
                    <h1 class="page-title">Environments</h1>
                    <p class="page-subtitle">Configure environment mappings (dev, staging, production)</p>
                </div>
            </div>

            <div class="card">
                <div class="config-header">
                    <div class="view-toggle">
                        <button class="active" onclick="setView('cards')" id="cardsViewBtn">
                            <i class="fas fa-th-large"></i> Cards
                        </button>
                        <button onclick="setView('yaml')" id="yamlViewBtn">
                            <i class="fas fa-code"></i> YAML
                        </button>
                    </div>
                    <div class="config-actions">
                        <button class="btn btn-secondary" onclick="showBackups()">
                            <i class="fas fa-history"></i>
                            <span>History</span>
                        </button>
                        <button class="btn btn-primary" onclick="openCreateModal()">
                            <i class="fas fa-plus"></i>
                            <span>Add Environment</span>
                        </button>
                    </div>
                </div>

                <div id="alertContainer"></div>

                <!-- Cards View -->
                <div class="cards-view-container active" id="cardsView">
                    <div class="environments-grid" id="environmentsGrid">
                        <div class="empty-state">
                            <i class="fas fa-spinner fa-spin"></i>
                            <p>Loading environments...</p>
                        </div>
                    </div>
                </div>

                <!-- YAML Editor View -->
                <div class="yaml-editor-container" id="yamlView">
                    <textarea id="yamlEditor"></textarea>
                    <div class="validation-status checking" id="validationStatus">
                        <i class="fas fa-circle-notch fa-spin"></i>
                        <span>Checking...</span>
                    </div>
                    <div class="validation-errors" id="validationErrors" style="display: none;">
                        <h4><i class="fas fa-exclamation-triangle"></i> Validation Errors</h4>
                        <ul id="errorsList"></ul>
                    </div>
                    <div class="editor-actions">
                        <button class="btn btn-secondary" onclick="resetYaml()">
                            <i class="fas fa-undo"></i> Reset
                        </button>
                        <button class="btn btn-primary" onclick="saveYaml()" id="saveYamlBtn">
                            <i class="fas fa-save"></i> Save Changes
                        </button>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Environment Modal -->
    <div class="modal-overlay" id="envModal">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title" id="modalTitle">Add Environment</h3>
                <button class="modal-close" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form id="envForm" onsubmit="saveEnvironment(event)">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label">ID *</label>
                        <input type="text" class="form-input" id="envId" required pattern="[a-z0-9-]+" 
                               placeholder="e.g., production" title="Lowercase letters, numbers, and hyphens only">
                        <small style="color: var(--text-muted);">Unique identifier (lowercase, no spaces)</small>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Name *</label>
                        <input type="text" class="form-input" id="envName" required placeholder="e.g., Production">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Color</label>
                        <div class="color-picker-wrapper">
                            <input type="color" id="envColorPicker" value="#4CAF50" onchange="document.getElementById('envColor').value = this.value">
                            <input type="text" class="form-input" id="envColor" value="#4CAF50" placeholder="#4CAF50" 
                                   onchange="document.getElementById('envColorPicker').value = this.value">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Target Mappings</label>
                        <div class="form-hint" style="margin-bottom: 8px;">Map roles to targets. The first one is used as default.</div>
                        <div class="target-mappings" id="targetMappings">
                            <!-- Populated by JavaScript -->
                        </div>
                        <button type="button" class="btn btn-secondary" onclick="addMapping()" style="margin-top: 12px;">
                            <i class="fas fa-plus"></i> Add Mapping
                        </button>
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            <input type="checkbox" id="envRequiresApproval">
                            Requires Approval
                        </label>
                        <small style="color: var(--text-muted); display: block; margin-top: 4px;">
                            Commands targeting this environment will require approval
                        </small>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Description</label>
                        <textarea class="form-input" id="envDescription" rows="2" placeholder="Optional description"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Environment</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Backups Modal -->
    <div class="modal-overlay" id="backupsModal">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title">Backup History</h3>
                <button class="modal-close" onclick="closeBackupsModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <div id="backupsList">
                    <div class="empty-state">
                        <i class="fas fa-spinner fa-spin"></i>
                        <p>Loading backups...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal-overlay" id="deleteModal">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title">Delete Environment</h3>
                <button class="modal-close" onclick="closeDeleteModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete <strong id="deleteEnvName"></strong>?</p>
                <p style="color: var(--warning); margin-top: 8px;">
                    <i class="fas fa-exclamation-triangle"></i>
                    Commands using this environment will need to be updated.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">Cancel</button>
                <button type="button" class="btn btn-danger" onclick="confirmDelete()">Delete Environment</button>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/codemirror.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/mode/yaml/yaml.min.js"></script>
    <script>
        let environments = {};
        let targets = [];  // Available targets for dropdown
        let editor = null;
        let currentView = 'cards';
        let deleteEnvId = null;
        let originalYaml = '';
        let validationTimeout = null;
        let editingEnvId = null;

        // Initialize
        document.addEventListener('DOMContentLoaded', async () => {
            await loadTargets();  // Load targets first for dropdowns
            loadEnvironments();
            initYamlEditor();
        });
        
        // Load available targets for dropdown
        async function loadTargets() {
            try {
                const response = await fetch('../api/config.php?action=targets');
                const data = await response.json();
                if (data.success) {
                    targets = data.targets || [];
                }
            } catch (error) {
                console.error('Error loading targets:', error);
            }
        }
        
        // Generate target select options HTML
        function getTargetOptions(selectedId = '') {
            return '<option value="">-- Select Target --</option>' +
                targets.map(t => `<option value="${t.id}" ${t.id === selectedId ? 'selected' : ''}>${escapeHtml(t.name)} (${t.type})</option>`).join('');
        }
        
        // Generate empty mapping row HTML
        function getEmptyMappingRow() {
            return `
                <div class="target-mapping-row">
                    <input type="text" class="form-input" placeholder="Role (e.g., default, web, db)" data-mapping-type value="default">
                    <select class="form-select" data-mapping-target>
                        ${getTargetOptions()}
                    </select>
                    <button type="button" class="btn btn-danger btn-icon" onclick="removeMapping(this)">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            `;
        }

        // Initialize CodeMirror
        function initYamlEditor() {
            editor = CodeMirror.fromTextArea(document.getElementById('yamlEditor'), {
                mode: 'yaml',
                theme: 'material-darker',
                lineNumbers: true,
                lineWrapping: true,
                tabSize: 2,
                indentWithTabs: false,
            });

            editor.on('change', () => {
                clearTimeout(validationTimeout);
                validationTimeout = setTimeout(validateYaml, 500);
            });
        }

        // View switching
        function setView(view) {
            currentView = view;
            
            document.getElementById('cardsViewBtn').classList.toggle('active', view === 'cards');
            document.getElementById('yamlViewBtn').classList.toggle('active', view === 'yaml');
            document.getElementById('cardsView').classList.toggle('active', view === 'cards');
            document.getElementById('yamlView').classList.toggle('active', view === 'yaml');

            if (view === 'yaml') {
                loadYaml();
            }
        }

        // Load environments
        async function loadEnvironments() {
            try {
                const response = await fetch('../api/config.php?action=environments');
                const data = await response.json();
                
                if (data.success) {
                    environments = data.environments;
                    renderEnvironments();
                } else {
                    showAlert('Failed to load environments: ' + data.error, 'error');
                }
            } catch (error) {
                console.error('Error loading environments:', error);
                showAlert('Failed to load environments', 'error');
            }
        }

        // Render environments grid
        function renderEnvironments() {
            const grid = document.getElementById('environmentsGrid');
            const envKeys = Object.keys(environments);
            
            if (envKeys.length === 0) {
                grid.innerHTML = `
                    <div class="empty-state" style="grid-column: 1 / -1;">
                        <i class="fas fa-layer-group"></i>
                        <h3>No environments configured</h3>
                        <p>Add your first environment to get started</p>
                        <button class="btn btn-primary" onclick="openCreateModal()" style="margin-top: 16px;">
                            <i class="fas fa-plus"></i> Add Environment
                        </button>
                    </div>
                `;
                return;
            }

            grid.innerHTML = envKeys.map(id => {
                const env = environments[id];
                return `
                    <div class="env-card">
                        <div class="env-card-header">
                            <div class="env-info">
                                <div class="env-color" style="background: ${escapeHtml(env.color || '#4CAF50')}"></div>
                                <div>
                                    <span class="env-name">${escapeHtml(env.name)}</span>
                                    <span class="env-id">${escapeHtml(id)}</span>
                                </div>
                            </div>
                        </div>
                        <div class="env-card-body">
                            ${renderTargetMappings(env.targets)}
                            ${renderPolicies(env.policies)}
                        </div>
                        <div class="env-card-footer">
                            <button class="btn btn-secondary" onclick="editEnvironment('${id}')">
                                <i class="fas fa-edit"></i> Edit
                            </button>
                            <button class="btn btn-danger" onclick="deleteEnvironment('${id}', '${escapeHtml(env.name)}')">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                `;
            }).join('');
        }

        // Render target mappings
        function renderTargetMappings(targets) {
            if (!targets || Object.keys(targets).length === 0) {
                return '<p style="color: var(--text-muted); font-size: 14px;">No target mappings</p>';
            }

            return `
                <div class="env-targets">
                    <div class="env-targets-title">Target Mappings</div>
                    ${Object.entries(targets).map(([type, targetId]) => `
                        <div class="env-target-item">
                            <span class="env-target-type">${escapeHtml(type)}</span>
                            <span class="env-target-id">${escapeHtml(targetId)}</span>
                        </div>
                    `).join('')}
                </div>
            `;
        }

        // Render policies
        function renderPolicies(policies) {
            if (!policies) return '';

            const items = [];
            if (policies.requiresApproval) {
                items.push('<i class="fas fa-shield-halved"></i> Requires approval');
            }
            if (policies.approvers?.length) {
                items.push(`<i class="fas fa-users"></i> Approvers: ${policies.approvers.join(', ')}`);
            }

            if (items.length === 0) return '';

            return `
                <div class="env-policies">
                    <div class="env-policies-title"><i class="fas fa-lock"></i> Policies</div>
                    ${items.map(item => `<div class="env-policy-item">${item}</div>`).join('')}
                </div>
            `;
        }

        // Load YAML content
        async function loadYaml() {
            try {
                const response = await fetch('../api/config.php?action=raw&type=environments');
                const data = await response.json();
                
                if (data.success) {
                    originalYaml = data.content;
                    editor.setValue(data.content);
                    validateYaml();
                }
            } catch (error) {
                console.error('Error loading YAML:', error);
            }
        }

        // Validate YAML
        async function validateYaml() {
            const status = document.getElementById('validationStatus');
            const errorsDiv = document.getElementById('validationErrors');
            const errorsList = document.getElementById('errorsList');
            
            status.className = 'validation-status checking';
            status.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> <span>Checking...</span>';
            errorsDiv.style.display = 'none';

            try {
                const response = await fetch('../api/config.php?action=validate&type=environments', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ content: editor.getValue() })
                });
                const data = await response.json();

                if (data.valid) {
                    status.className = 'validation-status valid';
                    status.innerHTML = '<i class="fas fa-check-circle"></i> <span>Valid YAML</span>';
                    document.getElementById('saveYamlBtn').disabled = false;
                } else {
                    status.className = 'validation-status invalid';
                    status.innerHTML = '<i class="fas fa-times-circle"></i> <span>Invalid</span>';
                    errorsDiv.style.display = 'block';
                    errorsList.innerHTML = data.errors.map(e => `<li>${escapeHtml(e)}</li>`).join('');
                    document.getElementById('saveYamlBtn').disabled = true;
                }
            } catch (error) {
                status.className = 'validation-status invalid';
                status.innerHTML = '<i class="fas fa-exclamation-triangle"></i> <span>Error checking</span>';
            }
        }

        // Save YAML
        async function saveYaml() {
            try {
                const response = await fetch('../api/config.php?action=raw&type=environments', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ content: editor.getValue() })
                });
                const data = await response.json();

                if (data.success) {
                    showAlert('Environments saved successfully', 'success');
                    originalYaml = editor.getValue();
                    loadEnvironments();
                } else {
                    showAlert('Failed to save: ' + (data.errors?.join(', ') || 'Unknown error'), 'error');
                }
            } catch (error) {
                showAlert('Failed to save environments', 'error');
            }
        }

        // Reset YAML
        function resetYaml() {
            editor.setValue(originalYaml);
            validateYaml();
        }

        // Open create modal
        function openCreateModal() {
            editingEnvId = null;
            document.getElementById('modalTitle').textContent = 'Add Environment';
            document.getElementById('envId').value = '';
            document.getElementById('envId').disabled = false;
            document.getElementById('envName').value = '';
            document.getElementById('envColor').value = '#4CAF50';
            document.getElementById('envColorPicker').value = '#4CAF50';
            document.getElementById('envRequiresApproval').checked = false;
            document.getElementById('envDescription').value = '';
            
            // Reset target mappings with a single empty row
            document.getElementById('targetMappings').innerHTML = getEmptyMappingRow();
            
            document.getElementById('envModal').classList.add('active');
        }

        // Edit environment
        function editEnvironment(id) {
            const env = environments[id];
            if (!env) return;

            editingEnvId = id;
            document.getElementById('modalTitle').textContent = 'Edit Environment';
            document.getElementById('envId').value = id;
            document.getElementById('envId').disabled = true;
            document.getElementById('envName').value = env.name;
            document.getElementById('envColor').value = env.color || '#4CAF50';
            document.getElementById('envColorPicker').value = env.color || '#4CAF50';
            document.getElementById('envRequiresApproval').checked = env.policies?.requiresApproval || false;
            document.getElementById('envDescription').value = env.description || '';
            
            // Populate target mappings
            const mappingsContainer = document.getElementById('targetMappings');
            const targets = env.targets || {};
            const targetEntries = Object.entries(targets);
            
            if (targetEntries.length > 0) {
                mappingsContainer.innerHTML = targetEntries.map(([type, targetId]) => `
                    <div class="target-mapping-row">
                        <input type="text" class="form-input" placeholder="Role (e.g., web, db)" data-mapping-type value="${escapeHtml(type)}">
                        <select class="form-select" data-mapping-target>
                            ${getTargetOptions(targetId)}
                        </select>
                        <button type="button" class="btn btn-danger btn-icon" onclick="removeMapping(this)">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                `).join('');
            } else {
                mappingsContainer.innerHTML = getEmptyMappingRow();
            }
            
            document.getElementById('envModal').classList.add('active');
        }

        // Add mapping row
        function addMapping() {
            const container = document.getElementById('targetMappings');
            const row = document.createElement('div');
            row.className = 'target-mapping-row';
            row.innerHTML = `
                <input type="text" class="form-input" placeholder="Role (e.g., default, web, db)" data-mapping-type>
                <select class="form-select" data-mapping-target>
                    ${getTargetOptions()}
                </select>
                <button type="button" class="btn btn-danger btn-icon" onclick="removeMapping(this)">
                    <i class="fas fa-times"></i>
                </button>
            `;
            container.appendChild(row);
        }

        // Remove mapping row
        function removeMapping(button) {
            const row = button.closest('.target-mapping-row');
            const container = document.getElementById('targetMappings');
            if (container.children.length > 1) {
                row.remove();
            } else {
                // Clear instead of remove if it's the last row
                row.querySelectorAll('input').forEach(input => input.value = '');
            }
        }

        // Save environment
        async function saveEnvironment(event) {
            event.preventDefault();

            const id = document.getElementById('envId').value;
            const env = {
                id: id,
                name: document.getElementById('envName').value,
                color: document.getElementById('envColor').value,
            };

            // Collect target mappings
            const targets = {};
            document.querySelectorAll('.target-mapping-row').forEach(row => {
                const type = row.querySelector('[data-mapping-type]').value.trim();
                const targetId = row.querySelector('[data-mapping-target]').value.trim();
                if (type && targetId) {
                    targets[type] = targetId;
                }
            });
            if (Object.keys(targets).length > 0) {
                env.targets = targets;
            }

            // Policies
            if (document.getElementById('envRequiresApproval').checked) {
                env.policies = { requiresApproval: true };
            }

            const description = document.getElementById('envDescription').value;
            if (description) env.description = description;

            try {
                const response = await fetch('../api/config.php?action=environment&id=' + id, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(env)
                });
                const data = await response.json();

                if (data.success) {
                    closeModal();
                    showAlert('Environment saved successfully', 'success');
                    loadEnvironments();
                    if (currentView === 'yaml') loadYaml();
                } else {
                    showAlert('Failed to save: ' + (data.errors?.join(', ') || data.error || 'Unknown error'), 'error');
                }
            } catch (error) {
                showAlert('Failed to save environment', 'error');
            }
        }

        // Delete environment
        function deleteEnvironment(id, name) {
            deleteEnvId = id;
            document.getElementById('deleteEnvName').textContent = name;
            document.getElementById('deleteModal').classList.add('active');
        }

        async function confirmDelete() {
            if (!deleteEnvId) return;

            try {
                const response = await fetch('../api/config.php?action=environment&id=' + deleteEnvId, {
                    method: 'DELETE'
                });
                const data = await response.json();

                if (data.success) {
                    closeDeleteModal();
                    showAlert('Environment deleted successfully', 'success');
                    loadEnvironments();
                    if (currentView === 'yaml') loadYaml();
                } else {
                    showAlert('Failed to delete: ' + (data.error || 'Unknown error'), 'error');
                }
            } catch (error) {
                showAlert('Failed to delete environment', 'error');
            }
        }

        // Backups
        async function showBackups() {
            document.getElementById('backupsModal').classList.add('active');
            const container = document.getElementById('backupsList');
            
            try {
                const response = await fetch('../api/config.php?action=backups&type=environments');
                const data = await response.json();

                if (data.success && data.backups.length > 0) {
                    container.innerHTML = data.backups.map(backup => `
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px; background: var(--bg-tertiary); border-radius: 8px; margin-bottom: 8px;">
                            <div>
                                <div style="font-weight: 500;">${escapeHtml(backup.date)}</div>
                                <div style="font-size: 12px; color: var(--text-muted);">${escapeHtml(backup.filename)}</div>
                            </div>
                            <button class="btn btn-secondary" onclick="restoreBackup('${escapeHtml(backup.filename)}')">
                                <i class="fas fa-undo"></i> Restore
                            </button>
                        </div>
                    `).join('');
                } else {
                    container.innerHTML = `
                        <div class="empty-state">
                            <i class="fas fa-history"></i>
                            <h3>No backups yet</h3>
                            <p>Backups are created automatically when you save changes</p>
                        </div>
                    `;
                }
            } catch (error) {
                container.innerHTML = '<p style="color: var(--error);">Failed to load backups</p>';
            }
        }

        async function restoreBackup(filename) {
            if (!confirm('Restore this backup? Current configuration will be backed up first.')) return;

            try {
                const response = await fetch('../api/config.php?action=restore&type=environments', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ filename })
                });
                const data = await response.json();

                if (data.success) {
                    closeBackupsModal();
                    showAlert('Backup restored successfully', 'success');
                    loadEnvironments();
                    if (currentView === 'yaml') loadYaml();
                } else {
                    showAlert('Failed to restore: ' + (data.error || 'Unknown error'), 'error');
                }
            } catch (error) {
                showAlert('Failed to restore backup', 'error');
            }
        }

        // Modal helpers
        function closeModal() {
            document.getElementById('envModal').classList.remove('active');
            editingEnvId = null;
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').classList.remove('active');
            deleteEnvId = null;
        }

        function closeBackupsModal() {
            document.getElementById('backupsModal').classList.remove('active');
        }

        // Alias for backwards compatibility - uses global Toast system
        function showAlert(message, type) {
            if (type === 'success') Toast.success(message);
            else if (type === 'error') Toast.error(message);
            else if (type === 'warning') Toast.warning(message);
            else Toast.info(message);
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
