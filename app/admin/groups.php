<?php
/**
 * admin/groups.php - Permission Groups Management (Super-Admin Only)
 */

require_once __DIR__ . '/../includes/middleware.php';
require_once __DIR__ . '/../includes/Layout.php';

$user = requireRole('super-admin');
requireModuleAccess('groups');

$layout = new Layout($user, 'groups');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $layout->renderHead('Groups & Permissions'); ?>
    <style>
        .groups-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
        }

        .groups-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
            gap: 16px;
        }

        .group-card {
            background: var(--bg-tertiary);
            border: 1px solid var(--border-primary);
            border-radius: 12px;
            padding: 20px;
            cursor: pointer;
            transition: all 0.15s;
        }

        .group-card:hover {
            border-color: var(--accent-primary);
            background: var(--bg-hover);
        }

        .group-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
        }

        .group-card-name {
            font-size: 16px;
            font-weight: 600;
            color: var(--text-primary);
        }

        .group-card-desc {
            font-size: 13px;
            color: var(--text-secondary);
            margin-bottom: 16px;
            line-height: 1.4;
        }

        .group-card-stats {
            display: flex;
            gap: 16px;
            font-size: 13px;
            color: var(--text-muted);
        }

        .group-card-stats i {
            margin-right: 4px;
        }

        .group-card-actions {
            display: flex;
            gap: 8px;
        }

        .btn-icon {
            width: 32px;
            height: 32px;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            font-size: 13px;
            background: var(--bg-tertiary);
            border: 1px solid var(--border-primary);
            color: var(--text-secondary);
            cursor: pointer;
            transition: all 0.15s;
        }

        .btn-icon:hover {
            background: var(--bg-hover);
            color: var(--text-primary);
        }

        .btn-icon.danger:hover {
            background: rgba(248, 81, 73, 0.15);
            color: var(--error);
        }

        /* Modal */
        .modal-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
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
            max-width: 700px;
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

        .modal-title { font-size: 18px; font-weight: 600; }

        .modal-close {
            width: 36px; height: 36px;
            background: var(--bg-tertiary);
            border: none; border-radius: 8px;
            color: var(--text-secondary);
            cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            transition: all 0.15s;
        }

        .modal-close:hover {
            background: var(--bg-hover);
            color: var(--text-primary);
        }

        .modal-body { padding: 24px; }

        .modal-footer {
            display: flex; gap: 12px;
            justify-content: flex-end;
            padding: 16px 24px;
            border-top: 1px solid var(--border-primary);
        }

        /* Tabs */
        .tabs {
            display: flex;
            gap: 4px;
            margin-bottom: 20px;
            border-bottom: 1px solid var(--border-primary);
            padding-bottom: 0;
        }

        .tab {
            padding: 8px 16px;
            font-size: 13px;
            font-weight: 500;
            color: var(--text-secondary);
            cursor: pointer;
            border: none;
            background: none;
            border-bottom: 2px solid transparent;
            margin-bottom: -1px;
            transition: all 0.15s;
        }

        .tab:hover { color: var(--text-primary); }

        .tab.active {
            color: var(--accent-primary);
            border-bottom-color: var(--accent-primary);
        }

        .tab-content { display: none; }
        .tab-content.active { display: block; }

        /* Members list */
        .member-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .member-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 12px;
            background: var(--bg-tertiary);
            border-radius: 8px;
        }

        .member-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .member-avatar {
            width: 32px; height: 32px;
            background: linear-gradient(135deg, var(--accent-primary), var(--accent-secondary));
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            font-size: 12px; color: var(--bg-primary); font-weight: 600;
        }

        .member-name { font-weight: 500; font-size: 14px; }
        .member-role { font-size: 12px; color: var(--text-muted); }

        /* Rules */
        .rule-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 12px;
            background: var(--bg-tertiary);
            border-radius: 8px;
        }

        .rule-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .rule-type-badge {
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .rule-type-badge.app { background: rgba(56, 139, 253, 0.15); color: #58a6ff; }
        .rule-type-badge.command { background: rgba(139, 92, 246, 0.15); color: #a78bfa; }
        .rule-type-badge.module { background: rgba(227, 179, 65, 0.15); color: #e3b341; }

        .deny-badge {
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            background: rgba(248, 81, 73, 0.15);
            color: var(--error);
        }

        .allow-badge {
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            background: rgba(63, 185, 80, 0.15);
            color: #3fb950;
        }

        .mode-badge {
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .mode-badge.blacklist {
            background: rgba(248, 81, 73, 0.15);
            color: var(--error);
        }

        .mode-badge.whitelist {
            background: rgba(63, 185, 80, 0.15);
            color: #3fb950;
        }

        .mode-selector {
            display: flex;
            gap: 8px;
            margin-top: 4px;
        }

        .mode-option {
            flex: 1;
            padding: 12px;
            border: 2px solid var(--border-primary);
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.15s;
            background: var(--bg-tertiary);
        }

        .mode-option:hover {
            border-color: var(--text-muted);
        }

        .mode-option.selected {
            border-color: var(--accent-primary);
            background: rgba(56, 139, 253, 0.08);
        }

        .mode-option-title {
            font-weight: 600;
            font-size: 13px;
            margin-bottom: 4px;
        }

        .mode-option-desc {
            font-size: 12px;
            color: var(--text-secondary);
            line-height: 1.3;
        }

        /* Add rule form */
        .add-rule-form {
            display: flex;
            gap: 8px;
            margin-bottom: 12px;
            flex-wrap: wrap;
        }

        .add-rule-form select, .add-rule-form button {
            font-size: 13px;
        }

        .empty-state {
            text-align: center;
            padding: 32px 24px;
            color: var(--text-secondary);
        }

        .empty-state > i {
            font-size: 36px;
            margin-bottom: 12px;
            opacity: 0.5;
        }

        .search-input { max-width: 300px; }
    </style>
</head>
<body>
    <div class="app-container">
        <?php $layout->renderSidebar(); ?>

        <main class="main-content">
            <div class="page-header">
                <div>
                    <h1 class="page-title">Groups & Permissions</h1>
                    <p class="page-subtitle">Manage permission groups and access control rules</p>
                </div>
            </div>

            <div class="card">
                <div class="groups-header">
                    <input type="text" class="form-input search-input" id="searchInput" placeholder="Search groups...">
                    <button class="btn btn-primary" onclick="openCreateGroupModal()">
                        <i class="fas fa-plus"></i>
                        <span>Create Group</span>
                    </button>
                </div>

                <div class="groups-grid" id="groupsGrid">
                    <div class="empty-state">
                        <i class="fas fa-spinner fa-spin"></i>
                        <p>Loading groups...</p>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Group Create/Edit Modal -->
    <div class="modal-overlay" id="groupFormModal">
        <div class="modal" style="max-width: 500px;">
            <div class="modal-header">
                <h3 class="modal-title" id="groupFormTitle">Create Group</h3>
                <button class="modal-close" onclick="closeModal('groupFormModal')"><i class="fas fa-times"></i></button>
            </div>
            <form id="groupForm" onsubmit="saveGroup(event)">
                <div class="modal-body">
                    <input type="hidden" id="groupId" value="">
                    <div class="form-group">
                        <label class="form-label">Group Name *</label>
                        <input type="text" class="form-input" id="groupName" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Description</label>
                        <textarea class="form-input" id="groupDescription" rows="3" placeholder="What is this group for?"></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Mode</label>
                        <div class="mode-selector">
                            <div class="mode-option selected" data-mode="blacklist" onclick="selectMode(this)">
                                <div class="mode-option-title"><i class="fas fa-ban"></i> Blacklist</div>
                                <div class="mode-option-desc">Allow everything, except what you deny</div>
                            </div>
                            <div class="mode-option" data-mode="whitelist" onclick="selectMode(this)">
                                <div class="mode-option-title"><i class="fas fa-check-circle"></i> Whitelist</div>
                                <div class="mode-option-desc">Deny everything, except what you allow</div>
                            </div>
                        </div>
                        <input type="hidden" id="groupMode" value="blacklist">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('groupFormModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Group Detail Modal -->
    <div class="modal-overlay" id="groupDetailModal">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title" id="groupDetailTitle">Group Details</h3>
                <button class="modal-close" onclick="closeModal('groupDetailModal')"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <div class="tabs">
                    <button class="tab active" onclick="switchTab(this, 'membersTab')">Members</button>
                    <button class="tab" id="rulesTabBtn" onclick="switchTab(this, 'rulesTab')">Rules</button>
                </div>

                <!-- Members Tab -->
                <div class="tab-content active" id="membersTab">
                    <div class="add-rule-form">
                        <select class="form-input" id="addMemberSelect" style="flex: 1;">
                            <option value="">Select user to add...</option>
                        </select>
                        <button class="btn btn-primary" onclick="addMember()" style="white-space: nowrap;">
                            <i class="fas fa-plus"></i> Add
                        </button>
                    </div>
                    <div class="member-list" id="membersList"></div>
                </div>

                <!-- Rules Tab -->
                <div class="tab-content" id="rulesTab">
                    <div class="add-rule-form">
                        <select class="form-input" id="addRuleType" style="width: 130px;" onchange="loadResourceOptions()">
                            <option value="">Type...</option>
                            <option value="app">App</option>
                            <option value="command">Command</option>
                            <option value="module">Module</option>
                        </select>
                        <select class="form-input" id="addRuleResource" style="flex: 1;">
                            <option value="">Select resource...</option>
                        </select>
                        <button class="btn btn-primary" id="addRuleBtn" onclick="addRule()" style="white-space: nowrap;">
                            <i class="fas fa-ban"></i> Deny
                        </button>
                    </div>
                    <div class="member-list" id="rulesList"></div>
                </div>
            </div>
        </div>
    </div>

    <script>
    const API_BASE = '../api/permissions.php';
    let allGroups = [];
    let currentGroupId = null;
    let currentGroupMode = 'blacklist';
    let allUsers = [];
    let allResources = { apps: [], commands: [], modules: [] };

    // ===== INIT =====
    document.addEventListener('DOMContentLoaded', () => {
        loadGroups();
        loadAllUsers();
        loadAllResources();

        document.getElementById('searchInput').addEventListener('input', (e) => {
            renderGroups(allGroups.filter(g =>
                g.name.toLowerCase().includes(e.target.value.toLowerCase()) ||
                (g.description || '').toLowerCase().includes(e.target.value.toLowerCase())
            ));
        });
    });

    // ===== GROUPS =====
    async function loadGroups() {
        const resp = await fetch(`${API_BASE}?action=groups`);
        const data = await resp.json();
        if (data.success) {
            allGroups = data.groups;
            renderGroups(allGroups);
        }
    }

    function renderGroups(groups) {
        const grid = document.getElementById('groupsGrid');
        if (groups.length === 0) {
            grid.innerHTML = `
                <div class="empty-state" style="grid-column: 1/-1;">
                    <i class="fas fa-users-gear"></i>
                    <h3>No groups yet</h3>
                    <p>Create a group to start managing permissions</p>
                </div>`;
            return;
        }

        grid.innerHTML = groups.map(g => {
            const mode = g.mode || 'blacklist';
            const modeLabel = mode === 'whitelist' ? 'Whitelist' : 'Blacklist';
            const modeIcon = mode === 'whitelist' ? 'fa-check-circle' : 'fa-ban';
            const ruleLabel = mode === 'whitelist' ? 'allow' : 'deny';
            return `
            <div class="group-card" onclick="openGroupDetail('${g.id}')">
                <div class="group-card-header">
                    <span class="group-card-name">${escHtml(g.name)}</span>
                    <div class="group-card-actions">
                        <span class="mode-badge ${mode}">${modeLabel}</span>
                        <button class="btn-icon" onclick="event.stopPropagation(); openEditGroupModal('${g.id}')" title="Edit">
                            <i class="fas fa-pen"></i>
                        </button>
                        <button class="btn-icon danger" onclick="event.stopPropagation(); deleteGroup('${g.id}', '${escHtml(g.name)}')" title="Delete">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
                <div class="group-card-desc">${escHtml(g.description || 'No description')}</div>
                <div class="group-card-stats">
                    <span><i class="fas fa-users"></i> ${g.member_count} member${g.member_count != 1 ? 's' : ''}</span>
                    <span><i class="fas ${modeIcon}"></i> ${g.rule_count} ${ruleLabel} rule${g.rule_count != 1 ? 's' : ''}</span>
                </div>
            </div>`;
        }).join('');
    }

    // ===== CREATE / EDIT GROUP =====
    function selectMode(el) {
        el.closest('.mode-selector').querySelectorAll('.mode-option').forEach(o => o.classList.remove('selected'));
        el.classList.add('selected');
        document.getElementById('groupMode').value = el.dataset.mode;
    }

    function openCreateGroupModal() {
        document.getElementById('groupFormTitle').textContent = 'Create Group';
        document.getElementById('groupId').value = '';
        document.getElementById('groupName').value = '';
        document.getElementById('groupDescription').value = '';
        document.getElementById('groupMode').value = 'blacklist';
        document.querySelectorAll('.mode-option').forEach(o => o.classList.toggle('selected', o.dataset.mode === 'blacklist'));
        document.getElementById('groupFormModal').classList.add('active');
    }

    function openEditGroupModal(id) {
        const group = allGroups.find(g => g.id === id);
        if (!group) return;
        const mode = group.mode || 'blacklist';
        document.getElementById('groupFormTitle').textContent = 'Edit Group';
        document.getElementById('groupId').value = id;
        document.getElementById('groupName').value = group.name;
        document.getElementById('groupDescription').value = group.description || '';
        document.getElementById('groupMode').value = mode;
        document.querySelectorAll('.mode-option').forEach(o => o.classList.toggle('selected', o.dataset.mode === mode));
        document.getElementById('groupFormModal').classList.add('active');
    }

    async function saveGroup(e) {
        e.preventDefault();
        const id = document.getElementById('groupId').value;
        const name = document.getElementById('groupName').value.trim();
        const description = document.getElementById('groupDescription').value.trim();
        const mode = document.getElementById('groupMode').value;

        const isEdit = !!id;
        const url = isEdit ? `${API_BASE}?action=group&id=${id}` : `${API_BASE}?action=group`;
        const method = isEdit ? 'PUT' : 'POST';

        const resp = await fetch(url, {
            method,
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ name, description, mode })
        });
        const data = await resp.json();

        if (data.success) {
            closeModal('groupFormModal');
            window.Toast?.success(isEdit ? 'Group updated' : 'Group created');
            loadGroups();
        } else {
            window.Toast?.error(data.error || 'Failed to save group');
        }
    }

    async function deleteGroup(id, name) {
        if (!confirm(`Delete group "${name}"? Members will lose permissions from this group.`)) return;

        const resp = await fetch(`${API_BASE}?action=group&id=${id}`, {
            method: 'DELETE',
            headers: { 'Content-Type': 'application/json' }
        });
        const data = await resp.json();

        if (data.success) {
            window.Toast?.success('Group deleted');
            loadGroups();
        } else {
            window.Toast?.error(data.error || 'Failed to delete group');
        }
    }

    // ===== GROUP DETAIL =====
    async function openGroupDetail(id) {
        currentGroupId = id;
        const resp = await fetch(`${API_BASE}?action=group&id=${id}`);
        const data = await resp.json();

        if (!data.success) {
            window.Toast?.error('Failed to load group');
            return;
        }

        const group = data.group;
        currentGroupMode = group.mode || 'blacklist';

        document.getElementById('groupDetailTitle').innerHTML =
            escHtml(group.name) + ' <span class="mode-badge ' + currentGroupMode + '">' +
            (currentGroupMode === 'whitelist' ? 'Whitelist' : 'Blacklist') + '</span>';

        // Update tab label and button based on mode
        const rulesTabBtn = document.getElementById('rulesTabBtn');
        const addRuleBtn = document.getElementById('addRuleBtn');
        if (currentGroupMode === 'whitelist') {
            rulesTabBtn.textContent = 'Allow Rules';
            addRuleBtn.innerHTML = '<i class="fas fa-check"></i> Allow';
        } else {
            rulesTabBtn.textContent = 'Deny Rules';
            addRuleBtn.innerHTML = '<i class="fas fa-ban"></i> Deny';
        }

        renderMembers(group.members || []);
        renderRules(group.rules || []);

        // Reset tabs
        document.querySelectorAll('#groupDetailModal .tab').forEach((t, i) => t.classList.toggle('active', i === 0));
        document.querySelectorAll('#groupDetailModal .tab-content').forEach((t, i) => t.classList.toggle('active', i === 0));

        document.getElementById('groupDetailModal').classList.add('active');
        updateAddMemberSelect(group.members || []);
    }

    // ===== MEMBERS =====
    function renderMembers(members) {
        const list = document.getElementById('membersList');
        if (members.length === 0) {
            list.innerHTML = '<div class="empty-state"><p>No members in this group</p></div>';
            return;
        }
        list.innerHTML = members.map(m => `
            <div class="member-item">
                <div class="member-info">
                    <div class="member-avatar">${(m.name || m.username).substring(0, 2).toUpperCase()}</div>
                    <div>
                        <div class="member-name">${escHtml(m.name || m.username)}</div>
                        <div class="member-role">${m.role} &middot; ${m.username}</div>
                    </div>
                </div>
                <button class="btn-icon danger" onclick="removeMember('${m.id}')" title="Remove">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        `).join('');
    }

    function updateAddMemberSelect(currentMembers) {
        const select = document.getElementById('addMemberSelect');
        const memberIds = new Set(currentMembers.map(m => m.id));
        select.innerHTML = '<option value="">Select user to add...</option>' +
            allUsers.filter(u => !memberIds.has(u.id) && u.role !== 'super-admin')
                .map(u => `<option value="${u.id}">${escHtml(u.name)} (${u.username}) - ${u.role}</option>`)
                .join('');
    }

    async function addMember() {
        const userId = document.getElementById('addMemberSelect').value;
        if (!userId || !currentGroupId) return;

        const resp = await fetch(`${API_BASE}?action=add-member`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ groupId: currentGroupId, userId })
        });
        const data = await resp.json();

        if (data.success) {
            window.Toast?.success('Member added');
            openGroupDetail(currentGroupId);
            loadGroups();
        } else {
            window.Toast?.error(data.error || 'Failed to add member');
        }
    }

    async function removeMember(userId) {
        const resp = await fetch(`${API_BASE}?action=remove-member`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ groupId: currentGroupId, userId })
        });
        const data = await resp.json();

        if (data.success) {
            window.Toast?.success('Member removed');
            openGroupDetail(currentGroupId);
            loadGroups();
        } else {
            window.Toast?.error(data.error || 'Failed to remove member');
        }
    }

    // ===== DENY RULES =====
    function renderRules(rules) {
        const list = document.getElementById('rulesList');
        if (rules.length === 0) {
            const emptyMsg = currentGroupMode === 'whitelist'
                ? 'No allow rules - members are denied all restricted resources'
                : 'No deny rules - members can access everything';
            list.innerHTML = `<div class="empty-state"><p>${emptyMsg}</p></div>`;
            return;
        }
        list.innerHTML = rules.map(r => {
            const name = getResourceName(r.resource_type, r.resource_id);
            const action = r.action || 'deny';
            const badgeClass = action === 'allow' ? 'allow-badge' : 'deny-badge';
            const badgeLabel = action.toUpperCase();
            return `
            <div class="rule-item">
                <div class="rule-info">
                    <span class="rule-type-badge ${r.resource_type}">${r.resource_type}</span>
                    <span>${escHtml(name)}</span>
                    <span class="${badgeClass}">${badgeLabel}</span>
                </div>
                <button class="btn-icon danger" onclick="removeRule('${r.resource_type}', '${r.resource_id}')" title="Remove rule">
                    <i class="fas fa-times"></i>
                </button>
            </div>`;
        }).join('');
    }

    function loadResourceOptions() {
        const type = document.getElementById('addRuleType').value;
        const select = document.getElementById('addRuleResource');
        select.innerHTML = '<option value="">Select resource...</option>';

        if (!type) return;

        let resources = [];
        if (type === 'app') resources = allResources.apps;
        else if (type === 'command') resources = allResources.commands;
        else if (type === 'module') resources = allResources.modules;

        select.innerHTML = '<option value="">Select resource...</option>' +
            resources.map(r => `<option value="${r.id}">${escHtml(r.name)}</option>`).join('');
    }

    async function addRule() {
        const resourceType = document.getElementById('addRuleType').value;
        const resourceId = document.getElementById('addRuleResource').value;
        if (!resourceType || !resourceId || !currentGroupId) return;

        const resp = await fetch(`${API_BASE}?action=set-group-rule`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ groupId: currentGroupId, resourceType, resourceId })
        });
        const data = await resp.json();

        if (data.success) {
            const action = data.action || (currentGroupMode === 'whitelist' ? 'allow' : 'deny');
            window.Toast?.success(`${action === 'allow' ? 'Allow' : 'Deny'} rule added`);
            openGroupDetail(currentGroupId);
            loadGroups();
        } else {
            window.Toast?.error(data.error || 'Failed to add rule');
        }
    }

    async function removeRule(resourceType, resourceId) {
        const resp = await fetch(`${API_BASE}?action=remove-group-rule`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ groupId: currentGroupId, resourceType, resourceId })
        });
        const data = await resp.json();

        if (data.success) {
            window.Toast?.success('Rule removed');
            openGroupDetail(currentGroupId);
            loadGroups();
        } else {
            window.Toast?.error(data.error || 'Failed to remove rule');
        }
    }

    // ===== UTILITY =====
    async function loadAllUsers() {
        const resp = await fetch('../api/auth.php?action=users');
        const data = await resp.json();
        if (data.success) {
            allUsers = data.users || [];
        }
    }

    async function loadAllResources() {
        const resp = await fetch(`${API_BASE}?action=resources`);
        const data = await resp.json();
        if (data.success) {
            allResources = data.resources;
        }
    }

    function getResourceName(type, id) {
        let list = [];
        if (type === 'app') list = allResources.apps;
        else if (type === 'command') list = allResources.commands;
        else if (type === 'module') list = allResources.modules;
        const found = list.find(r => r.id === id);
        return found ? found.name : id;
    }

    function switchTab(tabEl, contentId) {
        tabEl.closest('.tabs').querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
        tabEl.classList.add('active');
        const modal = tabEl.closest('.modal-body');
        modal.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
        document.getElementById(contentId).classList.add('active');
    }

    function closeModal(id) {
        document.getElementById(id).classList.remove('active');
    }

    function escHtml(str) {
        const div = document.createElement('div');
        div.textContent = str || '';
        return div.innerHTML;
    }
    </script>
</body>
</html>
