<?php
/**
 * config/secrets.php - Secrets Configuration Page
 * 
 * Part of Doki v3 Architecture - Batch 2: Infrastructure Config
 */

require_once __DIR__ . '/../includes/middleware.php';
require_once __DIR__ . '/../includes/Layout.php';

// Require admin role
$user = requireRole('admin');
requireModuleAccess('secrets');

// Create layout instance
$layout = new Layout($user, 'secrets');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $layout->renderHead('Secrets Configuration'); ?>
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

        .security-banner {
            background: rgba(210, 153, 34, 0.1);
            border: 1px solid var(--warning);
            border-radius: 12px;
            padding: 16px 20px;
            margin-bottom: 24px;
            display: flex;
            align-items: flex-start;
            gap: 16px;
        }

        .security-banner i {
            font-size: 24px;
            color: var(--warning);
            flex-shrink: 0;
        }

        .security-banner-content h3 {
            color: var(--warning);
            font-size: 16px;
            margin-bottom: 4px;
        }

        .security-banner-content p {
            color: var(--text-secondary);
            font-size: 14px;
            margin: 0;
        }

        /* Secrets Table */
        .secrets-table {
            width: 100%;
            border-collapse: collapse;
        }

        .secrets-table th,
        .secrets-table td {
            padding: 16px;
            text-align: left;
            border-bottom: 1px solid var(--border-primary);
        }

        .secrets-table th {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .secrets-table tr:hover td {
            background: var(--bg-tertiary);
        }

        .secret-key {
            font-family: 'JetBrains Mono', monospace;
            font-size: 14px;
            color: var(--accent-primary);
        }

        .secret-type {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }

        .secret-type.password { background: rgba(139, 92, 246, 0.15); color: #a78bfa; }
        .secret-type.private_key { background: rgba(248, 81, 73, 0.15); color: var(--error); }
        .secret-type.certificate { background: rgba(34, 197, 94, 0.15); color: #4ade80; }
        .secret-type.kubeconfig { background: rgba(249, 115, 22, 0.15); color: #fb923c; }
        .secret-type.text { background: rgba(156, 163, 175, 0.15); color: #9ca3af; }
        .secret-type.pem { background: rgba(59, 130, 246, 0.15); color: #60a5fa; }

        .secret-value-masked {
            font-family: 'JetBrains Mono', monospace;
            color: var(--text-muted);
            letter-spacing: 2px;
        }

        .secret-actions {
            display: flex;
            gap: 8px;
        }

        .btn-icon {
            width: 36px;
            height: 36px;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            font-size: 14px;
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

        /* Secret value textarea */
        .secret-value-input {
            font-family: 'JetBrains Mono', monospace;
            font-size: 13px;
            min-height: 150px;
            resize: vertical;
        }

        /* Password re-auth */
        .reauth-form {
            background: var(--bg-tertiary);
            border-radius: 8px;
            padding: 20px;
            text-align: center;
        }

        .reauth-form p {
            margin-bottom: 16px;
            color: var(--text-secondary);
        }

        .reauth-form .form-input {
            max-width: 300px;
            margin: 0 auto 16px;
        }

        /* Revealed secret */
        .secret-revealed {
            background: var(--bg-tertiary);
            border-radius: 8px;
            padding: 16px;
            position: relative;
        }

        .secret-revealed pre {
            font-family: 'JetBrains Mono', monospace;
            font-size: 13px;
            white-space: pre-wrap;
            word-break: break-all;
            margin: 0;
            max-height: 300px;
            overflow-y: auto;
        }

        .secret-revealed .copy-btn {
            position: absolute;
            top: 8px;
            right: 8px;
        }

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 60px 24px;
            color: var(--text-secondary);
        }

        .empty-state i {
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

        /* Password visibility toggle */
        .password-input-wrapper {
            position: relative;
        }

        .password-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            padding: 4px;
        }

        .password-toggle:hover {
            color: var(--text-primary);
        }
    </style>
</head>
<body>
    <div class="app-container">
        <?php $layout->renderSidebar(); ?>

        <main class="main-content">
            <div class="page-header">
                <div>
                    <h1 class="page-title">Secrets</h1>
                    <p class="page-subtitle">Manage credentials and sensitive configuration</p>
                </div>
            </div>

            <div class="card">
                <div class="security-banner">
                    <i class="fas fa-shield-halved"></i>
                    <div class="security-banner-content">
                        <h3>Security Notice</h3>
                        <p>Secrets are stored locally and should never be committed to git. For production, consider using a secrets manager like HashiCorp Vault.</p>
                    </div>
                </div>

                <div class="config-header">
                    <div></div>
                    <div class="config-actions">
                        <button class="btn btn-secondary" onclick="showBackups()">
                            <i class="fas fa-history"></i>
                            <span>History</span>
                        </button>
                        <button class="btn btn-primary" onclick="openCreateModal()">
                            <i class="fas fa-plus"></i>
                            <span>Add Secret</span>
                        </button>
                    </div>
                </div>

                <div id="alertContainer"></div>

                <div id="secretsContainer">
                    <div class="empty-state">
                        <i class="fas fa-spinner fa-spin"></i>
                        <p>Loading secrets...</p>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Secret Modal (Create/Edit) -->
    <div class="modal-overlay" id="secretModal">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title" id="modalTitle">Add Secret</h3>
                <button class="modal-close" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form id="secretForm" onsubmit="saveSecret(event)">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label">Key *</label>
                        <input type="text" class="form-input" id="secretKey" required pattern="[a-zA-Z0-9_-]+" 
                               placeholder="e.g., ssh-key-prod" title="Letters, numbers, hyphens, and underscores only">
                        <small style="color: var(--text-muted);">Unique identifier for this secret</small>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Value *</label>
                        <textarea class="form-input secret-value-input" id="secretValue" required 
                                  placeholder="Enter the secret value..."></textarea>
                        <small style="color: var(--text-muted);">
                            For SSH keys, paste the entire private key including BEGIN/END lines
                        </small>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Expires At (optional)</label>
                        <input type="date" class="form-input" id="secretExpiresAt">
                        <small style="color: var(--text-muted);">Leave blank for no expiry</small>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Rotation Interval (days, optional)</label>
                        <input type="number" class="form-input" id="secretRotationDays" min="1" step="1" placeholder="e.g., 90">
                        <small style="color: var(--text-muted);">Leave blank to disable rotation reminders</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Secret</button>
                </div>
            </form>
        </div>
    </div>

    <!-- View Secret Modal -->
    <div class="modal-overlay" id="viewModal">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title">View Secret: <span id="viewSecretKey"></span></h3>
                <button class="modal-close" onclick="closeViewModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <!-- Re-authentication form -->
                <div id="reauthForm" class="reauth-form">
                    <p><i class="fas fa-lock"></i> Enter your password to view this secret</p>
                    <div class="password-input-wrapper">
                        <input type="password" class="form-input" id="reauthPassword" placeholder="Your password">
                        <button type="button" class="password-toggle" onclick="toggleReauthPassword()">
                            <i class="fas fa-eye" id="reauthToggleIcon"></i>
                        </button>
                    </div>
                    <button class="btn btn-primary" onclick="revealSecret()">
                        <i class="fas fa-unlock"></i> Reveal Secret
                    </button>
                </div>

                <!-- Revealed secret value -->
                <div id="secretRevealed" class="secret-revealed" style="display: none;">
                    <button class="btn btn-secondary btn-icon copy-btn" onclick="copySecret()" title="Copy to clipboard">
                        <i class="fas fa-copy"></i>
                    </button>
                    <pre id="revealedValue"></pre>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeViewModal()">Close</button>
            </div>
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
                <h3 class="modal-title">Delete Secret</h3>
                <button class="modal-close" onclick="closeDeleteModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete <strong id="deleteSecretKey"></strong>?</p>
                <p style="color: var(--error); margin-top: 8px;">
                    <i class="fas fa-exclamation-triangle"></i>
                    This action cannot be undone. Targets using this secret will fail.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">Cancel</button>
                <button type="button" class="btn btn-danger" onclick="confirmDelete()">Delete Secret</button>
            </div>
        </div>
    </div>

    <script>
        let secrets = [];
        let secretsLocked = false;
        let deleteSecretKey = null;
        let viewSecretKey = null;

        // Initialize
        document.addEventListener('DOMContentLoaded', () => {
            loadSecrets();
        });

        // Load secrets
        async function loadSecrets() {
            try {
                const response = await fetch('../api/config.php?action=secrets');
                const data = await response.json();

                if (data.locked || response.status === 423) {
                    secretsLocked = true;
                    secrets = [];
                    renderSecrets();
                    return;
                }

                if (data.success) {
                    secretsLocked = false;
                    secrets = data.secrets;
                    renderSecrets();
                } else {
                    showAlert('Failed to load secrets: ' + data.error, 'error');
                }
            } catch (error) {
                console.error('Error loading secrets:', error);
                showAlert('Failed to load secrets', 'error');
            }
        }

        // Render secrets table
        function renderSecrets() {
            const container = document.getElementById('secretsContainer');

            if (secretsLocked) {
                container.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-lock"></i>
                        <h3>Secrets are locked</h3>
                        <p>The secrets key file is missing or unreadable.</p>
                        <p style="margin-top: 8px; color: var(--text-muted);">Expected key: <code>app/data/keys/secrets.key</code></p>
                    </div>
                `;
                return;
            }
            
            if (secrets.length === 0) {
                container.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-key"></i>
                        <h3>No secrets configured</h3>
                        <p>Add your first secret to get started</p>
                        <button class="btn btn-primary" onclick="openCreateModal()" style="margin-top: 16px;">
                            <i class="fas fa-plus"></i> Add Secret
                        </button>
                    </div>
                `;
                return;
            }

            container.innerHTML = `
                <table class="secrets-table">
                    <thead>
                        <tr>
                            <th>Key</th>
                            <th>Type</th>
                            <th>Rotation</th>
                            <th>Value</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${secrets.map(secret => `
                            <tr>
                                <td><span class="secret-key">${escapeHtml(secret.id)}</span></td>
                                <td><span class="secret-type ${secret.type}">${formatType(secret.type)}</span></td>
                                <td>${formatRotation(secret)}</td>
                                <td><span class="secret-value-masked">••••••••••••</span></td>
                                <td>
                                    <div class="secret-actions">
                                        <button class="btn btn-secondary btn-icon" onclick="viewSecret('${escapeHtml(secret.id)}')" title="View">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button class="btn btn-secondary btn-icon" onclick="editSecret('${escapeHtml(secret.id)}')" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-danger btn-icon" onclick="deleteSecret('${escapeHtml(secret.id)}')" title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            `;
        }

        // Format secret type for display
        function formatType(type) {
            const types = {
                'password': 'Password',
                'private_key': 'Private Key',
                'certificate': 'Certificate',
                'kubeconfig': 'Kubeconfig',
                'text': 'Text',
                'pem': 'PEM',
                'unknown': 'Unknown'
            };
            return types[type] || type;
        }

        function formatRotation(secret) {
            if (secret.expired) {
                return '<span style="color: var(--error); font-weight: 600;">Expired</span>';
            }
            if (secret.rotationDue) {
                return '<span style="color: var(--warning); font-weight: 600;">Rotation due</span>';
            }
            if (secret.expiresAt) {
                return `<span style="color: var(--text-muted);">Expires ${escapeHtml(secret.expiresAt.split(' ')[0])}</span>`;
            }
            return '<span style="color: var(--text-muted);">OK</span>';
        }

        // Open create modal
        function openCreateModal() {
            if (secretsLocked) {
                showAlert('Secrets are locked. Check the secrets key file.', 'error');
                return;
            }
            document.getElementById('modalTitle').textContent = 'Add Secret';
            document.getElementById('secretKey').value = '';
            document.getElementById('secretKey').disabled = false;
            document.getElementById('secretValue').value = '';
            document.getElementById('secretExpiresAt').value = '';
            document.getElementById('secretRotationDays').value = '';
            document.getElementById('secretModal').classList.add('active');
        }

        // Edit secret (need to view first to get value)
        function editSecret(key) {
            if (secretsLocked) {
                showAlert('Secrets are locked. Check the secrets key file.', 'error');
                return;
            }
            const meta = secrets.find(s => s.id === key) || {};
            document.getElementById('modalTitle').textContent = 'Edit Secret';
            document.getElementById('secretKey').value = key;
            document.getElementById('secretKey').disabled = true;
            document.getElementById('secretValue').value = '';
            document.getElementById('secretValue').placeholder = 'Enter new value to replace existing...';
            document.getElementById('secretExpiresAt').value = (meta.expiresAt || '').split(' ')[0];
            document.getElementById('secretRotationDays').value = meta.rotationDays || '';
            document.getElementById('secretModal').classList.add('active');
        }

        // Save secret
        async function saveSecret(event) {
            event.preventDefault();
            if (secretsLocked) {
                showAlert('Secrets are locked. Check the secrets key file.', 'error');
                return;
            }

            const key = document.getElementById('secretKey').value;
            const value = document.getElementById('secretValue').value;
            const expiresAt = document.getElementById('secretExpiresAt').value;
            const rotationDays = document.getElementById('secretRotationDays').value;

            try {
                const response = await fetch('../api/config.php?action=secret&key=' + encodeURIComponent(key), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ key, value, expiresAt, rotationDays })
                });
                const data = await response.json();

                if (data.success) {
                    closeModal();
                    showAlert('Secret saved successfully', 'success');
                    loadSecrets();
                } else {
                    showAlert('Failed to save: ' + (data.errors?.join(', ') || data.error || 'Unknown error'), 'error');
                }
            } catch (error) {
                showAlert('Failed to save secret', 'error');
            }
        }

        // View secret
        function viewSecret(key) {
            if (secretsLocked) {
                showAlert('Secrets are locked. Check the secrets key file.', 'error');
                return;
            }
            viewSecretKey = key;
            document.getElementById('viewSecretKey').textContent = key;
            document.getElementById('reauthForm').style.display = 'block';
            document.getElementById('secretRevealed').style.display = 'none';
            document.getElementById('reauthPassword').value = '';
            document.getElementById('viewModal').classList.add('active');
        }

        // Toggle password visibility in reauth form
        function toggleReauthPassword() {
            const input = document.getElementById('reauthPassword');
            const icon = document.getElementById('reauthToggleIcon');
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }

        // Reveal secret after re-auth
        async function revealSecret() {
            const password = document.getElementById('reauthPassword').value;
            
            if (!password) {
                showAlert('Please enter your password', 'error');
                return;
            }

            try {
                const response = await fetch(`../api/config.php?action=secret&key=${encodeURIComponent(viewSecretKey)}&password=${encodeURIComponent(password)}`);
                const data = await response.json();

                if (data.success) {
                    document.getElementById('reauthForm').style.display = 'none';
                    document.getElementById('secretRevealed').style.display = 'block';
                    document.getElementById('revealedValue').textContent = data.value;
                } else {
                    showAlert(data.error || 'Failed to reveal secret', 'error');
                }
            } catch (error) {
                showAlert('Failed to reveal secret', 'error');
            }
        }

        // Copy secret to clipboard
        function copySecret() {
            const value = document.getElementById('revealedValue').textContent;
            navigator.clipboard.writeText(value).then(() => {
                showAlert('Copied to clipboard', 'success');
            }).catch(() => {
                showAlert('Failed to copy', 'error');
            });
        }

        // Delete secret
        function deleteSecret(key) {
            if (secretsLocked) {
                showAlert('Secrets are locked. Check the secrets key file.', 'error');
                return;
            }
            deleteSecretKey = key;
            document.getElementById('deleteSecretKey').textContent = key;
            document.getElementById('deleteModal').classList.add('active');
        }

        async function confirmDelete() {
            if (!deleteSecretKey) return;

            try {
                const response = await fetch('../api/config.php?action=secret&key=' + encodeURIComponent(deleteSecretKey), {
                    method: 'DELETE'
                });
                const data = await response.json();

                if (data.success) {
                    closeDeleteModal();
                    showAlert('Secret deleted successfully', 'success');
                    loadSecrets();
                } else {
                    showAlert('Failed to delete: ' + (data.error || 'Unknown error'), 'error');
                }
            } catch (error) {
                showAlert('Failed to delete secret', 'error');
            }
        }

        // Backups
        async function showBackups() {
            document.getElementById('backupsModal').classList.add('active');
            const container = document.getElementById('backupsList');
            
            try {
                const response = await fetch('../api/config.php?action=backups&type=secrets');
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
            if (!confirm('Restore this backup? Current secrets will be backed up first.')) return;

            try {
                const response = await fetch('../api/config.php?action=restore&type=secrets', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ filename })
                });
                const data = await response.json();

                if (data.success) {
                    closeBackupsModal();
                    showAlert('Backup restored successfully', 'success');
                    loadSecrets();
                } else {
                    showAlert('Failed to restore: ' + (data.error || 'Unknown error'), 'error');
                }
            } catch (error) {
                showAlert('Failed to restore backup', 'error');
            }
        }

        // Modal helpers
        function closeModal() {
            document.getElementById('secretModal').classList.remove('active');
        }

        function closeViewModal() {
            document.getElementById('viewModal').classList.remove('active');
            viewSecretKey = null;
            // Clear revealed value for security
            document.getElementById('revealedValue').textContent = '';
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').classList.remove('active');
            deleteSecretKey = null;
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
