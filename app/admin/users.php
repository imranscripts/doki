<?php
/**
 * admin/users.php - User Management (Super-Admin Only)
 * 
 * Part of Doki v3 Architecture - Batch 1: Auth & UI Foundation
 */

require_once __DIR__ . '/../includes/middleware.php';
require_once __DIR__ . '/../includes/Layout.php';

// Require super-admin role
$user = requireRole('super-admin');
requireModuleAccess('users');

// Create layout instance
$layout = new Layout($user, 'users');
$currentUserIdJson = json_encode($user['id'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $layout->renderHead('User Management'); ?>
    <style>
        .users-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
        }

        .users-table-container {
            overflow-x: auto;
        }

        .user-avatar-small {
            width: 36px;
            height: 36px;
            background: linear-gradient(135deg, var(--accent-primary), var(--accent-secondary));
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            color: var(--bg-primary);
            font-weight: 600;
        }

        .user-cell {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .user-cell-info {
            display: flex;
            flex-direction: column;
        }

        .user-cell-name {
            font-weight: 500;
        }

        .user-cell-email {
            font-size: 13px;
            color: var(--text-muted);
        }

        .role-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }

        .role-badge.super-admin {
            background: rgba(139, 92, 246, 0.15);
            color: #a78bfa;
        }

        .role-badge.admin {
            background: var(--accent-glow);
            color: var(--accent-primary);
        }

        .role-badge.user {
            background: rgba(63, 185, 80, 0.15);
            color: var(--success);
        }

        .status-active {
            color: var(--success);
        }

        .status-inactive {
            color: var(--error);
        }

        .actions-cell {
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
            max-width: 500px;
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

        .empty-state {
            text-align: center;
            padding: 48px 24px;
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

        .search-input {
            max-width: 300px;
        }

        /* Permissions modal styles */
        .perm-modal { max-width: 650px; }

        .perm-section { margin-bottom: 20px; }
        .perm-section-title {
            font-size: 13px; font-weight: 600; text-transform: uppercase;
            color: var(--text-muted); margin-bottom: 8px; letter-spacing: 0.5px;
        }

        .perm-group-badges { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 8px; }
        .perm-group-badge {
            padding: 4px 12px; border-radius: 16px; font-size: 12px; font-weight: 500;
            background: rgba(56, 139, 253, 0.15); color: #58a6ff;
        }

        .perm-item {
            display: flex; align-items: center; justify-content: space-between;
            padding: 8px 12px; background: var(--bg-tertiary); border-radius: 8px; margin-bottom: 4px;
        }

        .perm-item-info { display: flex; align-items: center; gap: 8px; }

        .perm-type-badge {
            padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; text-transform: uppercase;
        }
        .perm-type-badge.app { background: rgba(56, 139, 253, 0.15); color: #58a6ff; }
        .perm-type-badge.command { background: rgba(139, 92, 246, 0.15); color: #a78bfa; }
        .perm-type-badge.module { background: rgba(227, 179, 65, 0.15); color: #e3b341; }

        .perm-action-badge { padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; }
        .perm-action-badge.deny { background: rgba(248, 81, 73, 0.15); color: var(--error); }
        .perm-action-badge.allow { background: rgba(63, 185, 80, 0.15); color: var(--success); }

        .perm-source { font-size: 11px; color: var(--text-muted); }

        .perm-add-form { display: flex; gap: 8px; margin-bottom: 12px; flex-wrap: wrap; }
        .perm-add-form select { font-size: 13px; }

        .btn-icon-sm {
            width: 28px; height: 28px; padding: 0;
            display: flex; align-items: center; justify-content: center;
            border-radius: 6px; font-size: 12px;
            background: var(--bg-tertiary); border: 1px solid var(--border-primary);
            color: var(--text-secondary); cursor: pointer; transition: all 0.15s;
        }
        .btn-icon-sm:hover { background: var(--bg-hover); color: var(--text-primary); }
        .btn-icon-sm.danger:hover { background: rgba(248, 81, 73, 0.15); color: var(--error); }

        .form-note {
            margin-top: 8px;
            font-size: 13px;
            color: var(--warning);
            line-height: 1.45;
        }

        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            display: none;
        }

        .alert.success {
            background: rgba(63, 185, 80, 0.15);
            color: var(--success);
            display: block;
        }

        .alert.error {
            background: rgba(248, 81, 73, 0.15);
            color: var(--error);
            display: block;
        }
    </style>
</head>
<body>
    <div class="app-container">
        <?php $layout->renderSidebar(); ?>

        <main class="main-content">
            <div class="page-header">
                <div>
                    <h1 class="page-title">User Management</h1>
                    <p class="page-subtitle">Manage user accounts and permissions</p>
                </div>
            </div>

            <div class="card">
                <div class="users-header">
                    <input type="text" class="form-input search-input" id="searchInput" placeholder="Search users...">
                    <button class="btn btn-primary" onclick="openCreateModal()">
                        <i class="fas fa-plus"></i>
                        <span>Add User</span>
                    </button>
                </div>

                <div id="alertContainer"></div>

                <div class="users-table-container">
                    <table class="table" id="usersTable">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Last Login</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="usersTableBody">
                            <tr>
                                <td colspan="5" class="empty-state">
                                    <i class="fas fa-spinner fa-spin"></i>
                                    <p>Loading users...</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <!-- User Modal -->
    <div class="modal-overlay" id="userModal">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title" id="modalTitle">Add User</h3>
                <button class="modal-close" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form id="userForm" onsubmit="saveUser(event)">
                <div class="modal-body">
                    <input type="hidden" id="userId" value="">
                    
                    <div class="form-group">
                        <label class="form-label">Username *</label>
                        <input type="text" class="form-input" id="userUsername" required minlength="3">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Full Name *</label>
                        <input type="text" class="form-input" id="userName" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Email *</label>
                        <input type="email" class="form-input" id="userEmail" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Password <span id="passwordHint">(min 8 characters) *</span></label>
                        <input type="password" class="form-input" id="userPassword" minlength="8">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Role *</label>
                        <select class="form-input" id="userRole" required>
                            <option value="user">User</option>
                            <option value="admin">Admin</option>
                            <option value="super-admin">Super Admin</option>
                        </select>
                        <div class="form-note" id="superAdminGuardNote" style="display: none;">
                            This account is the only active super-admin. Keep at least one active super-admin in Doki.
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            <input type="checkbox" id="userActive" checked>
                            Active
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="saveBtn">Save User</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal-overlay" id="deleteModal">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title">Delete User</h3>
                <button class="modal-close" onclick="closeDeleteModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete <strong id="deleteUserName"></strong>?</p>
                <p style="color: var(--error); margin-top: 8px;">This action cannot be undone.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">Cancel</button>
                <button type="button" class="btn btn-danger" onclick="confirmDelete()">Delete User</button>
            </div>
        </div>
    </div>

    <!-- Permissions Modal -->
    <div class="modal-overlay" id="permModal">
        <div class="modal perm-modal">
            <div class="modal-header">
                <h3 class="modal-title" id="permModalTitle">User Permissions</h3>
                <button class="modal-close" onclick="closePermModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <div class="perm-section">
                    <div class="perm-section-title">Groups</div>
                    <div class="perm-group-badges" id="permGroupBadges">
                        <span style="color: var(--text-muted); font-size: 13px;">No groups</span>
                    </div>
                </div>

                <div class="perm-section">
                    <div class="perm-section-title">Effective Deny Rules</div>
                    <div id="permEffectiveList"></div>
                </div>

                <div class="perm-section">
                    <div class="perm-section-title">Add User Override</div>
                    <div id="permOverrideGuardNote" class="form-note" style="display: none; margin-bottom: 10px;"></div>
                    <div class="perm-add-form">
                        <select class="form-input" id="permOverrideType" style="width: 120px;" onchange="loadPermResourceOptions()">
                            <option value="">Type...</option>
                            <option value="app">App</option>
                            <option value="command">Command</option>
                            <option value="module">Module</option>
                        </select>
                        <select class="form-input" id="permOverrideResource" style="flex: 1;">
                            <option value="">Select resource...</option>
                        </select>
                        <select class="form-input" id="permOverrideAction" style="width: 100px;">
                            <option value="deny">Deny</option>
                            <option value="allow">Allow</option>
                        </select>
                        <button class="btn btn-primary" onclick="addUserOverride()" style="white-space: nowrap;">
                            <i class="fas fa-plus"></i> Add
                        </button>
                    </div>
                </div>

                <div class="perm-section">
                    <div class="perm-section-title">User Overrides</div>
                    <div id="permOverridesList"></div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const currentUserId = <?= $currentUserIdJson ?>;
        let users = [];
        let deleteUserId = null;
        let permUserId = null;
        let permResources = { apps: [], commands: [], modules: [] };

        document.addEventListener('DOMContentLoaded', () => {
            loadUsers();
            
            // Search functionality
            document.getElementById('searchInput').addEventListener('input', (e) => {
                filterUsers(e.target.value);
            });
        });

        async function loadUsers() {
            try {
                const response = await fetch('../api/auth.php?action=users');
                const data = await response.json();
                
                if (data.success) {
                    users = data.users;
                    renderUsers(users);
                } else {
                    showAlert('Failed to load users: ' + data.error, 'error');
                }
            } catch (error) {
                console.error('Error loading users:', error);
                showAlert('Failed to load users', 'error');
            }
        }

        function renderUsers(userList) {
            const tbody = document.getElementById('usersTableBody');
            
            if (userList.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="5" class="empty-state">
                            <i class="fas fa-users"></i>
                            <h3>No users found</h3>
                            <p>Create a new user to get started</p>
                        </td>
                    </tr>
                `;
                return;
            }

            tbody.innerHTML = userList.map(user => `
                <tr>
                    <td>
                        <div class="user-cell">
                            <div class="user-avatar-small">${getInitials(user.name)}</div>
                            <div class="user-cell-info">
                                <span class="user-cell-name">${escapeHtml(user.name)}</span>
                                <span class="user-cell-email">${escapeHtml(user.email || '')}</span>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="role-badge ${user.role}">${formatRole(user.role)}</span>
                    </td>
                    <td>
                        <span class="${user.active ? 'status-active' : 'status-inactive'}">
                            <i class="fas ${user.active ? 'fa-check-circle' : 'fa-times-circle'}"></i>
                            ${user.active ? 'Active' : 'Inactive'}
                        </span>
                    </td>
                    <td>${user.last_login ? formatDate(user.last_login) : 'Never'}</td>
                    <td>
                        <div class="actions-cell">
                            <button class="btn btn-secondary btn-icon" onclick="openPermissions('${user.id}', '${escapeHtml(user.name)}')" title="Permissions">
                                <i class="fas fa-shield-halved"></i>
                            </button>
                            <button class="btn btn-secondary btn-icon" onclick="editUser('${user.id}')" title="Edit">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button class="btn btn-danger btn-icon" onclick="deleteUser('${user.id}', '${escapeHtml(user.name)}')" title="Delete">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>
            `).join('');
        }

        function filterUsers(search) {
            const filtered = users.filter(user => 
                user.name.toLowerCase().includes(search.toLowerCase()) ||
                user.username.toLowerCase().includes(search.toLowerCase()) ||
                (user.email && user.email.toLowerCase().includes(search.toLowerCase()))
            );
            renderUsers(filtered);
        }

        function openCreateModal() {
            document.getElementById('modalTitle').textContent = 'Add User';
            document.getElementById('userId').value = '';
            document.getElementById('userUsername').value = '';
            document.getElementById('userUsername').disabled = false;
            document.getElementById('userName').value = '';
            document.getElementById('userEmail').value = '';
            document.getElementById('userPassword').value = '';
            document.getElementById('userPassword').required = true;
            document.getElementById('passwordHint').textContent = '(min 8 characters) *';
            document.getElementById('userRole').value = 'user';
            document.getElementById('userRole').disabled = false;
            document.getElementById('userActive').checked = true;
            document.getElementById('userActive').disabled = false;
            document.getElementById('superAdminGuardNote').style.display = 'none';
            document.getElementById('userModal').classList.add('active');
        }

        function getActiveSuperAdminCount() {
            return users.filter(user => user.role === 'super-admin' && !!user.active).length;
        }

        function editUser(userId) {
            const user = users.find(u => u.id === userId);
            if (!user) return;

            document.getElementById('modalTitle').textContent = 'Edit User';
            document.getElementById('userId').value = user.id;
            document.getElementById('userUsername').value = user.username;
            document.getElementById('userUsername').disabled = true;
            document.getElementById('userName').value = user.name;
            document.getElementById('userEmail').value = user.email || '';
            document.getElementById('userPassword').value = '';
            document.getElementById('userPassword').required = false;
            document.getElementById('passwordHint').textContent = '(leave blank to keep current)';
            document.getElementById('userRole').value = user.role;
            const isOnlyActiveSuperAdmin = user.id === currentUserId && user.role === 'super-admin' && !!user.active && getActiveSuperAdminCount() === 1;
            document.getElementById('userRole').disabled = isOnlyActiveSuperAdmin;
            document.getElementById('userActive').checked = user.active == 1;
            document.getElementById('userActive').disabled = isOnlyActiveSuperAdmin;
            document.getElementById('superAdminGuardNote').style.display = isOnlyActiveSuperAdmin ? 'block' : 'none';
            document.getElementById('userModal').classList.add('active');
        }

        function closeModal() {
            document.getElementById('userModal').classList.remove('active');
        }

        async function saveUser(event) {
            event.preventDefault();
            
            const userId = document.getElementById('userId').value;
            const isCreate = !userId;
            
            const userData = {
                username: document.getElementById('userUsername').value,
                name: document.getElementById('userName').value,
                email: document.getElementById('userEmail').value,
                role: document.getElementById('userRole').value,
                active: document.getElementById('userActive').checked
            };

            const password = document.getElementById('userPassword').value;
            if (password) {
                userData.password = password;
            }

            try {
                let url, method;
                if (isCreate) {
                    url = '../api/auth.php?action=user';
                    method = 'POST';
                    userData.password = password; // Required for new users
                } else {
                    url = '../api/auth.php?action=user&id=' + userId;
                    method = 'PUT';
                }

                const response = await fetch(url, {
                    method: method,
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(userData)
                });

                const data = await response.json();

                if (data.success) {
                    closeModal();
                    showAlert(isCreate ? 'User created successfully' : 'User updated successfully', 'success');
                    loadUsers();
                } else {
                    showAlert(data.error || 'Failed to save user', 'error');
                }
            } catch (error) {
                console.error('Error saving user:', error);
                showAlert('Failed to save user', 'error');
            }
        }

        function deleteUser(userId, userName) {
            deleteUserId = userId;
            document.getElementById('deleteUserName').textContent = userName;
            document.getElementById('deleteModal').classList.add('active');
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').classList.remove('active');
            deleteUserId = null;
        }

        async function confirmDelete() {
            if (!deleteUserId) return;

            try {
                const response = await fetch('../api/auth.php?action=user&id=' + deleteUserId, {
                    method: 'DELETE'
                });

                const data = await response.json();

                if (data.success) {
                    closeDeleteModal();
                    showAlert('User deleted successfully', 'success');
                    loadUsers();
                } else {
                    showAlert(data.error || 'Failed to delete user', 'error');
                }
            } catch (error) {
                console.error('Error deleting user:', error);
                showAlert('Failed to delete user', 'error');
            }
        }

        // Alias for backwards compatibility - uses global Toast system
        function showAlert(message, type) {
            if (type === 'success') Toast.success(message);
            else if (type === 'error') Toast.error(message);
            else if (type === 'warning') Toast.warning(message);
            else Toast.info(message);
        }

        // Utility functions
        function getInitials(name) {
            return name.split(' ')
                .map(part => part[0])
                .slice(0, 2)
                .join('')
                .toUpperCase();
        }

        function formatRole(role) {
            return role.split('-').map(word => 
                word.charAt(0).toUpperCase() + word.slice(1)
            ).join(' ');
        }

        function formatDate(dateStr) {
            const date = new Date(dateStr);
            return date.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // ===== PERMISSIONS =====
        async function openPermissions(userId, userName) {
            permUserId = userId;
            document.getElementById('permModalTitle').textContent = `Permissions: ${userName}`;

            // Load resources if not yet loaded
            if (permResources.apps.length === 0) {
                const resp = await fetch('../api/permissions.php?action=resources');
                const data = await resp.json();
                if (data.success) permResources = data.resources;
            }

            // Load user permissions
            const resp = await fetch(`../api/permissions.php?action=user-permissions&id=${userId}`);
            const data = await resp.json();

            if (!data.success) {
                showAlert('Failed to load permissions', 'error');
                return;
            }

            // Groups
            const groupBadges = document.getElementById('permGroupBadges');
            const overrideGuardNote = document.getElementById('permOverrideGuardNote');
            const overrideProtected = !!data.overrideProtected;
            if (data.groups && data.groups.length > 0) {
                groupBadges.innerHTML = data.groups.map(g =>
                    `<span class="perm-group-badge">${escapeHtml(g.name)}</span>`
                ).join('');
            } else {
                groupBadges.innerHTML = '<span style="color: var(--text-muted); font-size: 13px;">No groups</span>';
            }

            // Effective deny rules (merged)
            const effectiveList = document.getElementById('permEffectiveList');
            if (data.details && data.details.length > 0) {
                effectiveList.innerHTML = data.details.map(d => {
                    const name = getPermResourceName(d.resource_type, d.resource_id);
                    return `<div class="perm-item">
                        <div class="perm-item-info">
                            <span class="perm-type-badge ${d.resource_type}">${d.resource_type}</span>
                            <span>${escapeHtml(name)}</span>
                            <span class="perm-action-badge ${d.action}">${d.action.toUpperCase()}</span>
                            <span class="perm-source">${d.source === 'group' ? 'from ' + escapeHtml(d.source_name) : 'user override'}</span>
                        </div>
                    </div>`;
                }).join('');
            } else {
                effectiveList.innerHTML = '<div style="color: var(--text-muted); font-size: 13px; padding: 8px;">No restrictions - user can access everything their role allows</div>';
            }

            // User overrides
            const overridesList = document.getElementById('permOverridesList');
            if (data.overrides && data.overrides.length > 0) {
                overridesList.innerHTML = data.overrides.map(o => {
                    const name = getPermResourceName(o.resource_type, o.resource_id);
                    return `<div class="perm-item">
                        <div class="perm-item-info">
                            <span class="perm-type-badge ${o.resource_type}">${o.resource_type}</span>
                            <span>${escapeHtml(name)}</span>
                            <span class="perm-action-badge ${o.action}">${o.action.toUpperCase()}</span>
                        </div>
                        <button class="btn-icon-sm danger" onclick="removeUserOverride('${o.resource_type}', '${o.resource_id}')" title="Remove override">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>`;
                }).join('');
            } else {
                overridesList.innerHTML = '<div style="color: var(--text-muted); font-size: 13px; padding: 8px;">No user-specific overrides</div>';
            }

            document.getElementById('permOverrideType').disabled = overrideProtected;
            document.getElementById('permOverrideResource').disabled = overrideProtected;
            document.getElementById('permOverrideAction').disabled = overrideProtected;
            const addOverrideButton = document.querySelector('.perm-add-form .btn.btn-primary');
            if (addOverrideButton) {
                addOverrideButton.disabled = overrideProtected;
            }
            overrideGuardNote.style.display = overrideProtected ? 'block' : 'none';
            overrideGuardNote.textContent = data.overrideProtectionMessage || '';

            document.getElementById('permModal').classList.add('active');
        }

        function closePermModal() {
            document.getElementById('permModal').classList.remove('active');
            permUserId = null;
        }

        function loadPermResourceOptions() {
            const type = document.getElementById('permOverrideType').value;
            const select = document.getElementById('permOverrideResource');
            select.innerHTML = '<option value="">Select resource...</option>';
            if (!type) return;

            let resources = [];
            if (type === 'app') resources = permResources.apps;
            else if (type === 'command') resources = permResources.commands;
            else if (type === 'module') resources = permResources.modules;

            select.innerHTML = '<option value="">Select resource...</option>' +
                resources.map(r => `<option value="${r.id}">${escapeHtml(r.name)}</option>`).join('');
        }

        async function addUserOverride() {
            const resourceType = document.getElementById('permOverrideType').value;
            const resourceId = document.getElementById('permOverrideResource').value;
            const action = document.getElementById('permOverrideAction').value;
            if (!resourceType || !resourceId || !permUserId) return;

            const resp = await fetch('../api/permissions.php?action=set-user-override', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ userId: permUserId, resourceType, resourceId, action })
            });
            const data = await resp.json();

            if (data.success) {
                showAlert('Override added', 'success');
                const userName = document.getElementById('permModalTitle').textContent.replace('Permissions: ', '');
                openPermissions(permUserId, userName);
            } else {
                showAlert(data.error || 'Failed to add override', 'error');
            }
        }

        async function removeUserOverride(resourceType, resourceId) {
            const resp = await fetch('../api/permissions.php?action=remove-user-override', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ userId: permUserId, resourceType, resourceId })
            });
            const data = await resp.json();

            if (data.success) {
                showAlert('Override removed', 'success');
                const userName = document.getElementById('permModalTitle').textContent.replace('Permissions: ', '');
                openPermissions(permUserId, userName);
            } else {
                showAlert(data.error || 'Failed to remove override', 'error');
            }
        }

        function getPermResourceName(type, id) {
            let list = [];
            if (type === 'app') list = permResources.apps;
            else if (type === 'command') list = permResources.commands;
            else if (type === 'module') list = permResources.modules;
            const found = list.find(r => r.id === id);
            return found ? found.name : id;
        }
    </script>
</body>
</html>
