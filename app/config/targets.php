<?php
/**
 * config/targets.php - Targets Configuration Page
 * 
 * Part of Doki v3 Architecture - Batch 2: Infrastructure Config
 */

require_once __DIR__ . '/../includes/middleware.php';
require_once __DIR__ . '/../includes/Layout.php';

// Require admin role
$user = requireRole('admin');
requireModuleAccess('targets');

// Create layout instance
$layout = new Layout($user, 'targets');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $layout->renderHead('Targets Configuration'); ?>
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
        .targets-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 20px;
        }

        .target-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-primary);
            border-radius: 12px;
            padding: 20px;
            transition: all 0.15s;
        }

        .target-card:hover {
            border-color: var(--accent-primary);
        }

        .target-card-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            margin-bottom: 16px;
        }

        .target-info {
            flex: 1;
            min-width: 0;
        }

        .target-name {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 4px;
        }

        .target-id {
            font-family: 'JetBrains Mono', monospace;
            font-size: 12px;
            color: var(--text-muted);
        }

        .target-type {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            text-transform: uppercase;
        }

        .target-type.ssh { background: rgba(139, 92, 246, 0.15); color: #a78bfa; }
        .target-type.docker { background: rgba(59, 130, 246, 0.15); color: #60a5fa; }
        .target-type.http { background: rgba(34, 197, 94, 0.15); color: #4ade80; }
        .target-type.kubernetes { background: rgba(249, 115, 22, 0.15); color: #fb923c; }
        .target-type.local { background: rgba(156, 163, 175, 0.15); color: #9ca3af; }

        .target-meta {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 6px;
        }

        .target-status {
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .target-status.verified { background: rgba(34, 197, 94, 0.15); color: #22c55e; }
        .target-status.failed { background: rgba(239, 68, 68, 0.15); color: #ef4444; }
        .target-status.unverified { background: rgba(148, 163, 184, 0.15); color: #94a3b8; }

        .capability-badges {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-bottom: 12px;
        }

        .capability-badge {
            padding: 4px 8px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 600;
            background: rgba(14, 165, 233, 0.12);
            color: #0ea5e9;
        }

        .capability-badge.dim {
            background: rgba(148, 163, 184, 0.2);
            color: var(--text-muted);
        }

        .target-detail.error {
            color: #ef4444;
        }

        .target-details {
            margin-bottom: 16px;
        }

        .target-detail {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: var(--text-secondary);
            margin-bottom: 6px;
        }

        .capability-setup {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 10px 12px;
            border-radius: 10px;
            border: 1px dashed rgba(34, 197, 94, 0.4);
            background: rgba(34, 197, 94, 0.08);
            margin-top: 10px;
        }

        .capability-setup-info {
            display: flex;
            flex-direction: column;
            gap: 4px;
            font-size: 12px;
            color: var(--text-secondary);
        }

        .capability-setup-title {
            font-weight: 600;
            color: var(--text-primary);
            font-size: 13px;
        }

        .btn-setup {
            background: rgba(15, 23, 42, 0.9);
            color: #22c55e;
            border: 1px solid rgba(34, 197, 94, 0.6);
        }

        .btn-setup:hover {
            background: rgba(15, 23, 42, 1);
            border-color: rgba(34, 197, 94, 0.9);
        }

        .btn-debug {
            background: rgba(30, 41, 59, 0.9);
            color: #cbd5f5;
            border: 1px solid rgba(148, 163, 184, 0.5);
        }

        .btn-debug:hover {
            background: rgba(30, 41, 59, 1);
            border-color: rgba(148, 163, 184, 0.8);
        }

        .target-detail i {
            width: 16px;
            color: var(--text-muted);
        }

        .target-actions {
            display: flex;
            gap: 8px;
        }

        .target-actions .btn {
            flex: 1;
        }

        .btn-verify {
            background: var(--bg-tertiary);
            color: #22c55e;
            border: 1px solid #22c55e;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-verify:hover {
            background: rgba(34, 197, 94, 0.12);
            border-color: #22c55e;
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

        /* Connection fields */
        .connection-fields {
            background: var(--bg-tertiary);
            border-radius: 8px;
            padding: 16px;
            margin-top: 8px;
        }

        .connection-fields .form-group {
            margin-bottom: 16px;
        }

        .connection-fields .form-group:last-child {
            margin-bottom: 0;
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

        .alert.info {
            background: var(--accent-glow);
            color: var(--accent-primary);
        }
    </style>
</head>
<body>
    <div class="app-container">
        <?php $layout->renderSidebar(); ?>

        <main class="main-content">
            <div class="page-header">
                <div>
                    <h1 class="page-title">Targets</h1>
                    <p class="page-subtitle">Configure execution targets (servers, endpoints, containers)</p>
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
                            <span>Add Target</span>
                        </button>
                    </div>
                </div>

                <div id="alertContainer"></div>

                <!-- Cards View -->
                <div class="cards-view-container active" id="cardsView">
                    <div class="targets-grid" id="targetsGrid">
                        <div class="empty-state">
                            <i class="fas fa-spinner fa-spin"></i>
                            <p>Loading targets...</p>
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

    <!-- Target Modal -->
    <div class="modal-overlay" id="targetModal">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title" id="modalTitle">Add Target</h3>
                <button class="modal-close" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form id="targetForm" onsubmit="saveTarget(event)">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label">ID *</label>
                        <input type="text" class="form-input" id="targetId" required pattern="[a-z0-9-]+" 
                               placeholder="e.g., prod-app-server" title="Lowercase letters, numbers, and hyphens only">
                        <small style="color: var(--text-muted);">Unique identifier (lowercase, no spaces)</small>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Name *</label>
                        <input type="text" class="form-input" id="targetName" required placeholder="e.g., Production App Server">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Type *</label>
                        <select class="form-input" id="targetType" required onchange="updateConnectionFields()">
                            <option value="">Select type...</option>
                            <option value="ssh">SSH</option>
                            <option value="docker">Docker</option>
                            <option value="http">HTTP/API</option>
                            <option value="kubernetes">Kubernetes</option>
                            <option value="local">Local</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Connection Details</label>
                        <div class="connection-fields" id="connectionFields">
                            <p style="color: var(--text-muted); font-size: 14px;">Select a type to configure connection</p>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Description</label>
                        <textarea class="form-input" id="targetDescription" rows="2" placeholder="Optional description"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Target</button>
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
                <h3 class="modal-title">Delete Target</h3>
                <button class="modal-close" onclick="closeDeleteModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete <strong id="deleteTargetName"></strong>?</p>
                <p style="color: var(--warning); margin-top: 8px;">
                    <i class="fas fa-exclamation-triangle"></i>
                    Commands using this target will need to be updated.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">Cancel</button>
                <button type="button" class="btn btn-danger" onclick="confirmDelete()">Delete Target</button>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/codemirror.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/mode/yaml/yaml.min.js"></script>
    <script>
        let targets = [];
        let editor = null;
        let currentView = 'cards';
        let deleteTargetId = null;
        let originalYaml = '';
        let validationTimeout = null;

        // Initialize
        document.addEventListener('DOMContentLoaded', () => {
            loadTargets();
            initYamlEditor();
        });

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

        // Load targets
        async function loadTargets() {
            try {
                const response = await fetch('../api/config.php?action=targets');
                const data = await response.json();
                
                if (data.success) {
                    targets = data.targets;
                    renderTargets();
                } else {
                    showAlert('Failed to load targets: ' + data.error, 'error');
                }
            } catch (error) {
                console.error('Error loading targets:', error);
                showAlert('Failed to load targets', 'error');
            }
        }

        // Render targets grid
        function renderTargets() {
            const grid = document.getElementById('targetsGrid');
            
            if (targets.length === 0) {
                grid.innerHTML = `
                    <div class="empty-state" style="grid-column: 1 / -1;">
                        <i class="fas fa-server"></i>
                        <h3>No targets configured</h3>
                        <p>Add your first target to get started</p>
                        <button class="btn btn-primary" onclick="openCreateModal()" style="margin-top: 16px;">
                            <i class="fas fa-plus"></i> Add Target
                        </button>
                    </div>
                `;
                return;
            }

            grid.innerHTML = targets.map(target => `
                <div class="target-card">
                    <div class="target-card-header">
                        <div class="target-info">
                            <div class="target-name">${escapeHtml(target.name)}</div>
                            <div class="target-id">${escapeHtml(target.id)}</div>
                        </div>
                        <div class="target-meta">
                            <span class="target-type ${target.type}">${target.type}</span>
                            ${renderTargetStatus(target)}
                        </div>
                    </div>
                    ${renderTargetCapabilities(target)}
                    <div class="target-details">
                        ${renderTargetDetails(target)}
                        ${renderVerificationMeta(target)}
                        ${renderCapabilitySetup(target)}
                    </div>
                    <div class="target-actions">
                        <button class="btn btn-verify" onclick="verifyTarget('${target.id}')" title="Verify Capabilities">
                            <i class="fas fa-plug"></i>
                            <span>Verify</span>
                        </button>
                        ${renderDebugVerifyButton(target)}
                        <button class="btn btn-secondary" onclick="editTarget('${target.id}')">
                            <i class="fas fa-edit"></i> Edit
                        </button>
                        <button class="btn btn-danger" onclick="deleteTarget('${target.id}', '${escapeHtml(target.name)}')">
                            <i class="fas fa-trash"></i>
                            <span>Delete</span>
                        </button>
                    </div>
                </div>
            `).join('');
        }

        function renderTargetStatus(target) {
            const status = target.verification?.status || 'unverified';
            const label = status === 'verified' ? 'Verified' : (status === 'failed' ? 'Failed' : 'Unverified');
            return `<span class="target-status ${status}">${label}</span>`;
        }

        function renderTargetCapabilities(target) {
            const caps = target.verifiedCapabilities || [];
            if (!caps.length) {
                return `<div class="capability-badges"><span class="capability-badge dim">No verified capabilities</span></div>`;
            }

            const labels = {
                'exec.docker': 'Docker Exec',
                'exec.ssh': 'SSH Exec',
                'http.call': 'HTTP Call',
                'kubernetes.exec': 'Kubernetes Exec'
            };

            return `<div class="capability-badges">` + caps.map(cap => {
                const label = labels[cap] || cap;
                return `<span class="capability-badge">${label}</span>`;
            }).join('') + `</div>`;
        }

        function renderVerificationMeta(target) {
            const verification = target.verification || {};
            const checkedAt = verification.checkedAt ? formatDateTime(verification.checkedAt) : 'Never';
            let html = `<div class="target-detail"><i class="fas fa-shield-check"></i> Verified: ${escapeHtml(checkedAt)}</div>`;

            if (verification.status === 'failed' && verification.error) {
                html += `<div class="target-detail error"><i class="fas fa-triangle-exclamation"></i> ${escapeHtml(verification.error)}</div>`;
            }

            return html;
        }

        function renderDebugVerifyButton(target) {
            if (target.verification?.status !== 'failed') {
                return '';
            }
            return `
                <button class="btn btn-secondary btn-debug" onclick="verifyTarget('${target.id}', { debug: true })" title="Debug Verify">
                    <i class="fas fa-bug"></i>
                    <span>Debug</span>
                </button>
            `;
        }

        function renderCapabilitySetup(target) {
            const verification = target.verification || {};
            const checks = verification.checks || {};
            const dockerCheck = checks['exec.docker'];

            if (!dockerCheck || dockerCheck.ok !== false) {
                return '';
            }

            if ((dockerCheck.message || '').includes('Docker CLI not available on server')) {
                return '';
            }

            const dockerInstall = canInstallDocker(target);
            if (!dockerInstall.ok) {
                return '';
            }

            const detail = dockerInstall.label ? `via ${dockerInstall.label}` : 'via SSH';
            return `
                <div class="capability-setup">
                    <div class="capability-setup-info">
                        <div class="capability-setup-title">Docker not available</div>
                        <div>Install Docker on this target ${escapeHtml(detail)}.</div>
                    </div>
                    <button class="btn btn-setup" onclick="setupTargetCapability('${target.id}', 'exec.docker')">
                        <i class="fas fa-wrench"></i>
                        <span>Install Docker</span>
                    </button>
                </div>
            `;
        }

        function canInstallDocker(target) {
            if (target.type !== 'docker') {
                return { ok: false };
            }

            const conn = target.connection || {};
            if (conn.sshTargetId) {
                const sshTarget = targets.find(t => t.id === conn.sshTargetId);
                if (!sshTarget) {
                    return { ok: false };
                }
                const verified = (sshTarget.verifiedCapabilities || []).includes('exec.ssh');
                if (!verified) {
                    return { ok: false };
                }
                return { ok: true, label: `${sshTarget.name || sshTarget.id} (${sshTarget.id})` };
            }

            if (conn.ssh && conn.ssh.host && conn.ssh.user) {
                return { ok: true, label: `${conn.ssh.user}@${conn.ssh.host}` };
            }

            return { ok: false };
        }

        function formatDateTime(value) {
            const date = new Date(value);
            if (Number.isNaN(date.getTime())) return value;
            return date.toLocaleString();
        }

        // Render target connection details
        function renderTargetDetails(target) {
            const conn = target.connection || {};
            let details = [];

            switch (target.type) {
                case 'ssh':
                    if (conn.host) details.push(`<div class="target-detail"><i class="fas fa-server"></i> ${escapeHtml(conn.host)}${conn.port ? ':' + conn.port : ''}</div>`);
                    if (conn.user) details.push(`<div class="target-detail"><i class="fas fa-user"></i> ${escapeHtml(conn.user)}</div>`);
                    if (conn.keyRef) details.push(`<div class="target-detail"><i class="fas fa-key"></i> Key: ${escapeHtml(conn.keyRef)}</div>`);
                    if (conn.passwordRef) details.push(`<div class="target-detail"><i class="fas fa-lock"></i> Password: ${escapeHtml(conn.passwordRef)}</div>`);
                    break;
                case 'docker':
                    if (conn.sshTargetId) {
                        const sshTarget = targets.find(t => t.id === conn.sshTargetId);
                        const label = sshTarget ? `${sshTarget.name || sshTarget.id} (${sshTarget.id})` : conn.sshTargetId;
                        details.push(`<div class="target-detail"><i class="fas fa-link"></i> SSH target: ${escapeHtml(label)}</div>`);
                    } else if (conn.ssh) {
                        details.push(`<div class="target-detail"><i class="fas fa-server"></i> via SSH: ${escapeHtml(conn.ssh.host || 'unknown')}</div>`);
                        if (conn.ssh.keyRef) details.push(`<div class="target-detail"><i class="fas fa-key"></i> Key: ${escapeHtml(conn.ssh.keyRef)}</div>`);
                        if (conn.ssh.passwordRef) details.push(`<div class="target-detail"><i class="fas fa-lock"></i> Password: ${escapeHtml(conn.ssh.passwordRef)}</div>`);
                    } else if (conn.host) {
                        const port = conn.port ? ':' + conn.port : '';
                        details.push(`<div class="target-detail"><i class="fas fa-network-wired"></i> TCP: ${escapeHtml(conn.host + port)}</div>`);
                    } else if (conn.socket) {
                        details.push(`<div class="target-detail"><i class="fab fa-docker"></i> ${escapeHtml(conn.socket)}</div>`);
                    } else {
                        details.push(`<div class="target-detail"><i class="fab fa-docker"></i> /var/run/docker.sock</div>`);
                    }
                    break;
                case 'http':
                    if (conn.baseUrl) details.push(`<div class="target-detail"><i class="fas fa-globe"></i> ${escapeHtml(conn.baseUrl)}</div>`);
                    if (conn.auth) details.push(`<div class="target-detail"><i class="fas fa-lock"></i> Auth: ${escapeHtml(conn.auth.type || 'none')}</div>`);
                    break;
                case 'kubernetes':
                    if (conn.context) details.push(`<div class="target-detail"><i class="fas fa-dharmachakra"></i> ${escapeHtml(conn.context)}</div>`);
                    if (conn.namespace) details.push(`<div class="target-detail"><i class="fas fa-folder"></i> ${escapeHtml(conn.namespace)}</div>`);
                    break;
                case 'local':
                    details.push(`<div class="target-detail"><i class="fas fa-laptop"></i> Local execution</div>`);
                    break;
            }

            if (target.description) {
                details.push(`<div class="target-detail"><i class="fas fa-info-circle"></i> ${escapeHtml(target.description)}</div>`);
            }

            return details.length > 0 ? details.join('') : '<div class="target-detail"><i class="fas fa-cog"></i> No details configured</div>';
        }

        // Load YAML content
        async function loadYaml() {
            try {
                const response = await fetch('../api/config.php?action=raw&type=targets');
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
                const response = await fetch('../api/config.php?action=validate&type=targets', {
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
                const response = await fetch('../api/config.php?action=raw&type=targets', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ content: editor.getValue() })
                });
                const data = await response.json();

                if (data.success) {
                    showAlert('Targets saved successfully', 'success');
                    originalYaml = editor.getValue();
                    loadTargets(); // Refresh cards view
                } else {
                    showAlert('Failed to save: ' + (data.errors?.join(', ') || 'Unknown error'), 'error');
                }
            } catch (error) {
                showAlert('Failed to save targets', 'error');
            }
        }

        // Reset YAML
        function resetYaml() {
            editor.setValue(originalYaml);
            validateYaml();
        }

        // Open create modal
        function openCreateModal() {
            document.getElementById('modalTitle').textContent = 'Add Target';
            document.getElementById('targetId').value = '';
            document.getElementById('targetId').disabled = false;
            document.getElementById('targetName').value = '';
            document.getElementById('targetType').value = '';
            document.getElementById('targetDescription').value = '';
            updateConnectionFields();
            document.getElementById('targetModal').classList.add('active');
        }

        // Edit target
        function editTarget(id) {
            const target = targets.find(t => t.id === id);
            if (!target) return;

            document.getElementById('modalTitle').textContent = 'Edit Target';
            document.getElementById('targetId').value = target.id;
            document.getElementById('targetId').disabled = true;
            document.getElementById('targetName').value = target.name;
            document.getElementById('targetType').value = target.type;
            document.getElementById('targetDescription').value = target.description || '';
            updateConnectionFields(target.connection);
            document.getElementById('targetModal').classList.add('active');
        }

        // Update connection fields based on type
        function updateConnectionFields(existingConnection = null) {
            const type = document.getElementById('targetType').value;
            const container = document.getElementById('connectionFields');
            const conn = existingConnection || {};

            let html = '';
            switch (type) {
                case 'ssh':
                    html = `
                        <div class="form-group">
                            <label class="form-label">Host *</label>
                            <input type="text" class="form-input" id="connHost" value="${escapeHtml(conn.host || '')}" placeholder="e.g., 10.0.1.50">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Port</label>
                            <input type="number" class="form-input" id="connPort" value="${conn.port || 22}" placeholder="22">
                        </div>
                        <div class="form-group">
                            <label class="form-label">User *</label>
                            <input type="text" class="form-input" id="connUser" value="${escapeHtml(conn.user || '')}" placeholder="e.g., deploy">
                        </div>
                        <div class="form-group">
                            <label class="form-label">SSH Key Reference</label>
                            <input type="text" class="form-input" id="connKeyRef" value="${escapeHtml(conn.keyRef || '')}" placeholder="e.g., ssh-key-prod">
                            <small style="color: var(--text-muted);">Reference to secret containing the SSH key</small>
                        </div>
                        <div class="form-group">
                            <label class="form-label">SSH Password Reference</label>
                            <input type="text" class="form-input" id="connPasswordRef" value="${escapeHtml(conn.passwordRef || '')}" placeholder="e.g., ssh-password-prod">
                            <small style="color: var(--text-muted);">Optional secret reference for SSH password</small>
                        </div>
                    `;
                    break;
                case 'docker':
                    html = `
                        <div class="form-group">
                            <label class="form-label">Connection Mode</label>
                            <select class="form-input" id="connDockerMode">
                                <option value="socket">Local socket</option>
                                <option value="ssh-target">SSH target (recommended)</option>
                                <option value="ssh-specific">SSH (specific)</option>
                                <option value="tcp">TCP (advanced)</option>
                            </select>
                        </div>
                        <div id="dockerModeFields"></div>
                    `;
                    break;
                case 'http':
                    html = `
                        <div class="form-group">
                            <label class="form-label">Base URL *</label>
                            <input type="url" class="form-input" id="connBaseUrl" value="${escapeHtml(conn.baseUrl || '')}" placeholder="https://api.example.com">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Auth Type</label>
                            <select class="form-input" id="connAuthType">
                                <option value="none" ${(!conn.auth || conn.auth.type === 'none') ? 'selected' : ''}>None</option>
                                <option value="basic" ${conn.auth?.type === 'basic' ? 'selected' : ''}>Basic Auth</option>
                                <option value="bearer" ${conn.auth?.type === 'bearer' ? 'selected' : ''}>Bearer Token</option>
                            </select>
                        </div>
                        <p style="color: var(--text-muted); font-size: 13px; margin-top: 8px;">
                            Configure auth credentials in YAML mode or Secrets.
                        </p>
                    `;
                    break;
                case 'kubernetes':
                    html = `
                        <div class="form-group">
                            <label class="form-label">Kubeconfig Reference</label>
                            <input type="text" class="form-input" id="connKubeconfigRef" value="${escapeHtml(conn.kubeconfigRef || '')}" placeholder="e.g., kubeconfig-prod">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Context</label>
                            <input type="text" class="form-input" id="connContext" value="${escapeHtml(conn.context || '')}" placeholder="e.g., production">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Namespace</label>
                            <input type="text" class="form-input" id="connNamespace" value="${escapeHtml(conn.namespace || 'default')}" placeholder="default">
                        </div>
                    `;
                    break;
                case 'local':
                    html = `<p style="color: var(--text-muted); font-size: 14px;">Local targets execute commands on the Doki server itself.</p>`;
                    break;
                default:
                    html = `<p style="color: var(--text-muted); font-size: 14px;">Select a type to configure connection</p>`;
            }

            container.innerHTML = html;

            if (type === 'docker') {
                const modeSelect = document.getElementById('connDockerMode');
                const currentMode = getDockerModeFromConnection(conn);
                modeSelect.value = currentMode;
                renderDockerModeFields(currentMode, conn);
                modeSelect.addEventListener('change', () => {
                    renderDockerModeFields(modeSelect.value, conn);
                });
            }
        }

        function getDockerModeFromConnection(conn) {
            if (conn?.sshTargetId) return 'ssh-target';
            if (conn?.ssh) return 'ssh-specific';
            if (conn?.host) return 'tcp';
            return 'socket';
        }

        function getVerifiedSshTargets() {
            return (targets || []).filter(t => (t.type === 'ssh') && (t.verifiedCapabilities || []).includes('exec.ssh'));
        }

        function renderDockerModeFields(mode, conn = {}) {
            const container = document.getElementById('dockerModeFields');
            if (!container) return;

            if (mode === 'socket') {
                container.innerHTML = `
                    <div class="form-group">
                        <label class="form-label">Docker Socket</label>
                        <input type="text" class="form-input" id="connSocket" value="${escapeHtml(conn.socket || '/var/run/docker.sock')}" placeholder="/var/run/docker.sock">
                    </div>
                `;
                return;
            }

            if (mode === 'ssh-target') {
                const sshTargets = getVerifiedSshTargets();
                const selected = conn.sshTargetId || (sshTargets[0]?.id || '');
                const options = sshTargets.length
                    ? sshTargets.map(t => `<option value="${escapeHtml(t.id)}" ${t.id === selected ? 'selected' : ''}>${escapeHtml(t.name || t.id)} (${escapeHtml(t.id)})</option>`).join('')
                    : '<option value="">No verified SSH targets</option>';

                container.innerHTML = `
                    <div class="form-group">
                        <label class="form-label">SSH Target</label>
                        <select class="form-input" id="connSshTargetId" ${sshTargets.length ? '' : 'disabled'}>
                            ${options}
                        </select>
                        <small style="color: var(--text-muted);">Uses an existing verified SSH target.</small>
                    </div>
                `;
                return;
            }

            if (mode === 'ssh-specific') {
                const ssh = conn.ssh || {};
                container.innerHTML = `
                    <div class="form-group">
                        <label class="form-label">Host *</label>
                        <input type="text" class="form-input" id="connDockerSshHost" value="${escapeHtml(ssh.host || '')}" placeholder="e.g., 10.0.1.50">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Port</label>
                        <input type="number" class="form-input" id="connDockerSshPort" value="${ssh.port || 22}" placeholder="22">
                    </div>
                    <div class="form-group">
                        <label class="form-label">User *</label>
                        <input type="text" class="form-input" id="connDockerSshUser" value="${escapeHtml(ssh.user || '')}" placeholder="e.g., deploy">
                    </div>
                    <div class="form-group">
                        <label class="form-label">SSH Key Reference</label>
                        <input type="text" class="form-input" id="connDockerSshKeyRef" value="${escapeHtml(ssh.keyRef || '')}" placeholder="e.g., ssh-key-prod">
                    </div>
                    <div class="form-group">
                        <label class="form-label">SSH Password Reference</label>
                        <input type="text" class="form-input" id="connDockerSshPasswordRef" value="${escapeHtml(ssh.passwordRef || '')}" placeholder="e.g., ssh-password-prod">
                    </div>
                    <small style="color: var(--text-muted);">Recommended for remote Docker.</small>
                `;
                return;
            }

            if (mode === 'tcp') {
                container.innerHTML = `
                    <div class="form-group">
                        <label class="form-label">Host *</label>
                        <input type="text" class="form-input" id="connDockerHost" value="${escapeHtml(conn.host || '')}" placeholder="e.g., 10.0.1.50">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Port</label>
                        <input type="number" class="form-input" id="connDockerPort" value="${conn.port || 2376}" placeholder="2376">
                    </div>
                    <small style="color: var(--text-muted);">TCP is advanced. Use SSH unless you have TLS configured.</small>
                `;
            }
        }

        // Save target
        async function saveTarget(event) {
            event.preventDefault();

            const type = document.getElementById('targetType').value;
            const target = {
                id: document.getElementById('targetId').value,
                name: document.getElementById('targetName').value,
                type: type,
            };

            const description = document.getElementById('targetDescription').value;
            if (description) target.description = description;

            // Build connection object based on type
            target.connection = buildConnection(type);

            try {
                console.log('Saving target:', JSON.stringify(target, null, 2));
                
                const response = await fetch('../api/config.php?action=target', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(target)
                });
                
                console.log('Response status:', response.status);
                const text = await response.text();
                console.log('Response body:', text);
                
                if (!text) {
                    showAlert('Server returned empty response', 'error');
                    return;
                }
                
                let data;
                try {
                    data = JSON.parse(text);
                } catch (parseError) {
                    console.error('Failed to parse response as JSON:', text);
                    // Show first 500 chars of response for debugging
                    showAlert('Server error: ' + text.substring(0, 500), 'error');
                    return;
                }

                if (data.success) {
                    closeModal();
                    showAlert('Target saved. Verifying capabilities...', 'success');
                    await verifyTarget(target.id, { silent: true });
                } else {
                    const errorMsg = data.errors?.join(', ') || data.error || 'Unknown error';
                    console.error('Save failed:', data);
                    showAlert('Failed to save: ' + errorMsg, 'error');
                }
            } catch (error) {
                console.error('Save target error:', error);
                showAlert('Network error: ' + error.message, 'error');
            }
        }

        // Build connection object
        function buildConnection(type) {
            const conn = {};
            
            switch (type) {
                case 'ssh':
                    const host = document.getElementById('connHost')?.value;
                    const port = document.getElementById('connPort')?.value;
                    const user = document.getElementById('connUser')?.value;
                    const keyRef = document.getElementById('connKeyRef')?.value;
                    const passwordRef = document.getElementById('connPasswordRef')?.value;
                    if (host) conn.host = host;
                    if (port && port !== '22') conn.port = parseInt(port);
                    if (user) conn.user = user;
                    if (keyRef) conn.keyRef = keyRef;
                    if (passwordRef) conn.passwordRef = passwordRef;
                    break;
                case 'docker':
                    const mode = document.getElementById('connDockerMode')?.value || 'socket';
                    if (mode === 'socket') {
                        const socket = document.getElementById('connSocket')?.value;
                        if (socket) conn.socket = socket;
                    } else if (mode === 'ssh-target') {
                        const sshTargetId = document.getElementById('connSshTargetId')?.value;
                        if (sshTargetId) conn.sshTargetId = sshTargetId;
                    } else if (mode === 'ssh-specific') {
                        const host = document.getElementById('connDockerSshHost')?.value;
                        const port = document.getElementById('connDockerSshPort')?.value;
                        const user = document.getElementById('connDockerSshUser')?.value;
                        const keyRef = document.getElementById('connDockerSshKeyRef')?.value;
                        const passwordRef = document.getElementById('connDockerSshPasswordRef')?.value;
                        conn.ssh = {};
                        if (host) conn.ssh.host = host;
                        if (port && port !== '22') conn.ssh.port = parseInt(port);
                        if (user) conn.ssh.user = user;
                        if (keyRef) conn.ssh.keyRef = keyRef;
                        if (passwordRef) conn.ssh.passwordRef = passwordRef;
                    } else if (mode === 'tcp') {
                        const host = document.getElementById('connDockerHost')?.value;
                        const port = document.getElementById('connDockerPort')?.value;
                        if (host) conn.host = host;
                        if (port) conn.port = parseInt(port);
                    }
                    break;
                case 'http':
                    const baseUrl = document.getElementById('connBaseUrl')?.value;
                    const authType = document.getElementById('connAuthType')?.value;
                    if (baseUrl) conn.baseUrl = baseUrl;
                    if (authType && authType !== 'none') conn.auth = { type: authType };
                    break;
                case 'kubernetes':
                    const kubeconfigRef = document.getElementById('connKubeconfigRef')?.value;
                    const context = document.getElementById('connContext')?.value;
                    const namespace = document.getElementById('connNamespace')?.value;
                    if (kubeconfigRef) conn.kubeconfigRef = kubeconfigRef;
                    if (context) conn.context = context;
                    if (namespace) conn.namespace = namespace;
                    break;
            }

            return conn;
        }

        // Verify target capabilities
        async function verifyTarget(id, options = {}) {
            const target = targets.find(t => t.id === id);
            if (!target) return null;

            if (!options.silent) {
                Toast.info(`Verifying ${target.name}...`);
            }

            try {
                const response = await fetch('../api/config.php?action=verify-target', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id, debug: !!options.debug })
                });
                const data = await response.json();

                if (!options.silent) {
                    if (data.success) {
                        Toast.success(`${target.name} verified`);
                    } else {
                        Toast.error(`${target.name}: ${data.error || 'Verification failed'}`);
                    }
                }

                if (options.refresh !== false) {
                    loadTargets();
                    if (currentView === 'yaml') loadYaml();
                }

                if (options.debug) {
                    const debug = data?.verification?.checks?.['exec.docker']?.debug;
                    if (debug) {
                        console.log('Docker verify debug:', debug);
                        showAlert('Debug details logged to console', 'info');
                    }
                }

                return data;
            } catch (error) {
                if (!options.silent) {
                    Toast.error(`Verification failed: ${error.message}`);
                }
                if (options.refresh !== false) {
                    loadTargets();
                    if (currentView === 'yaml') loadYaml();
                }
                return null;
            }
        }

        async function setupTargetCapability(id, capability) {
            const target = targets.find(t => t.id === id);
            if (!target) return;

            const label = capability === 'exec.docker' ? 'Docker' : capability;
            if (!confirm(`Install ${label} on ${target.name}? This will run setup commands on the target over SSH.`)) {
                return;
            }

            Toast.info(`Setting up ${label} on ${target.name}...`);

            try {
                const response = await fetch('../api/config.php?action=setup-target-capability', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id, capability })
                });
                const data = await response.json();

                if (data.success) {
                    Toast.success(`${label} setup completed`);
                } else {
                    Toast.error(data.error || `${label} setup failed`);
                }

                loadTargets();
                if (currentView === 'yaml') loadYaml();

                return data;
            } catch (error) {
                Toast.error(`${label} setup failed: ${error.message}`);
                loadTargets();
                if (currentView === 'yaml') loadYaml();
                return null;
            }
        }

        // Delete target
        function deleteTarget(id, name) {
            deleteTargetId = id;
            document.getElementById('deleteTargetName').textContent = name;
            document.getElementById('deleteModal').classList.add('active');
        }

        async function confirmDelete() {
            if (!deleteTargetId) return;

            try {
                const response = await fetch('../api/config.php?action=target&id=' + deleteTargetId, {
                    method: 'DELETE'
                });
                const data = await response.json();

                if (data.success) {
                    closeDeleteModal();
                    showAlert('Target deleted successfully', 'success');
                    loadTargets();
                    if (currentView === 'yaml') loadYaml();
                } else {
                    showAlert('Failed to delete: ' + (data.error || 'Unknown error'), 'error');
                }
            } catch (error) {
                showAlert('Failed to delete target', 'error');
            }
        }

        // Backups
        async function showBackups() {
            document.getElementById('backupsModal').classList.add('active');
            const container = document.getElementById('backupsList');
            
            try {
                const response = await fetch('../api/config.php?action=backups&type=targets');
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
                const response = await fetch('../api/config.php?action=restore&type=targets', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ filename })
                });
                const data = await response.json();

                if (data.success) {
                    closeBackupsModal();
                    showAlert('Backup restored successfully', 'success');
                    loadTargets();
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
            document.getElementById('targetModal').classList.remove('active');
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').classList.remove('active');
            deleteTargetId = null;
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
