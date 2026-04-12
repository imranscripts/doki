<?php
/**
 * admin/studio.php - App Studio workspace management and editing
 */

require_once __DIR__ . '/../includes/middleware.php';
require_once __DIR__ . '/../includes/Layout.php';
require_once __DIR__ . '/../includes/WorkspaceManager.php';
require_once __DIR__ . '/../includes/SourcesManager.php';
require_once __DIR__ . '/../includes/AIProviderManager.php';

$user = requireRole('admin');
requireModuleAccess('studio');
$layout = new Layout($user, 'studio');
$workspaceManager = new WorkspaceManager();
$sourcesManager = new SourcesManager();
$auth = new Auth();
$auth->validateSession($_SESSION['auth_token'] ?? '');
$studioAiEnabled = $auth->canAccessResource('module', 'studio.ai');
$aiAdminEnabled = $auth->canAccessResource('module', 'ai.admin');
$aiProviderManager = $studioAiEnabled ? new AIProviderManager() : null;

$workspaces = array_values($workspaceManager->getWorkspaces());
$repositories = array_values(array_filter(
    $sourcesManager->getRepositories(),
    fn(array $repo) => in_array($repo['type'] ?? '', [SourcesManager::TYPE_APPS, SourcesManager::TYPE_MIXED], true)
));
$aiProviders = $studioAiEnabled && $aiProviderManager !== null
    ? array_values($aiProviderManager->getProviders())
    : [];

$starterCount = count(array_filter($workspaces, fn(array $w) => ($w['source_type'] ?? '') === 'starter'));
$repoCount = count(array_filter($workspaces, fn(array $w) => ($w['source_type'] ?? '') === 'repository'));
$previewReadyCount = count(array_filter($workspaces, fn(array $w) => ($w['preview_status'] ?? '') === 'ready'));
$workspacesJson = json_encode($workspaces, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$aiProvidersJson = json_encode($aiProviders, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$starterIconOptions = [
    ['icon' => 'fa-house', 'label' => 'Home'],
    ['icon' => 'fa-chart-line', 'label' => 'Analytics'],
    ['icon' => 'fa-briefcase', 'label' => 'Business'],
    ['icon' => 'fa-comments', 'label' => 'Chat'],
    ['icon' => 'fa-calendar', 'label' => 'Calendar'],
    ['icon' => 'fa-list-check', 'label' => 'Tasks'],
    ['icon' => 'fa-folder-tree', 'label' => 'Files'],
    ['icon' => 'fa-bolt', 'label' => 'Automation'],
    ['icon' => 'fa-robot', 'label' => 'AI'],
    ['icon' => 'fa-heart-pulse', 'label' => 'Health'],
    ['icon' => 'fa-graduation-cap', 'label' => 'Learning'],
    ['icon' => 'fa-shop', 'label' => 'Commerce'],
    ['icon' => 'fa-puzzle-piece', 'label' => 'Utility'],
    ['icon' => 'fa-code', 'label' => 'Developer'],
    ['icon' => 'fa-users', 'label' => 'Team'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $layout->renderHead('App Studio'); ?>
    <style>
        /* Override main-content padding for full-bleed IDE layout */
        .main-content {
            padding: 0 !important;
            display: flex;
            flex-direction: column;
            height: 100vh;
            overflow: hidden;
        }

        /* ── Toolbar ── */
        .studio-toolbar {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0 16px;
            height: 48px;
            min-height: 48px;
            border-bottom: 1px solid var(--border-color);
            background: var(--bg-secondary);
            flex-shrink: 0;
        }

        .studio-toolbar-title {
            font-size: 14px;
            font-weight: 700;
            color: var(--text-primary);
            white-space: nowrap;
            margin-right: 4px;
        }

        .toolbar-separator {
            width: 1px;
            height: 24px;
            background: var(--border-color);
            flex-shrink: 0;
        }

        .toolbar-group {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .toolbar-select {
            padding: 5px 10px;
            font-size: 13px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--bg-primary);
            color: var(--text-primary);
            min-width: 180px;
            max-width: 280px;
        }

        .toolbar-select:focus {
            outline: none;
            border-color: var(--accent-primary);
        }

        .display-mode-toggle {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            background: rgba(255,255,255,0.03);
        }

        .display-mode-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            border: 0;
            border-radius: 8px;
            background: transparent;
            color: var(--text-secondary);
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            white-space: nowrap;
            transition: all 0.15s ease;
        }

        .display-mode-btn:hover {
            color: var(--text-primary);
            background: rgba(255,255,255,0.04);
        }

        .display-mode-btn.active {
            background: rgba(37, 99, 235, 0.14);
            color: var(--accent-primary);
            box-shadow: inset 0 0 0 1px rgba(37, 99, 235, 0.3);
        }

        .toolbar-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 6px 10px;
            font-size: 12px;
            font-weight: 600;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--bg-primary);
            color: var(--text-secondary);
            cursor: pointer;
            white-space: nowrap;
            transition: all 0.15s ease;
        }

        .toolbar-btn:hover {
            border-color: var(--accent-primary);
            color: var(--text-primary);
            background: rgba(37, 99, 235, 0.06);
        }

        .toolbar-btn.active {
            background: rgba(37, 99, 235, 0.1);
            border-color: var(--accent-primary);
            color: var(--accent-primary);
        }

        .toolbar-btn.primary {
            background: var(--accent-primary);
            border-color: var(--accent-primary);
            color: #fff;
        }

        .toolbar-btn.primary:hover {
            opacity: 0.9;
        }

        .toolbar-btn.danger:hover {
            border-color: rgba(239, 68, 68, 0.5);
            color: #dc2626;
            background: rgba(239, 68, 68, 0.08);
        }

        .toolbar-spacer {
            flex: 1;
        }

        .toolbar-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 8px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 600;
        }

        .toolbar-badge.ready { background: rgba(34, 197, 94, 0.12); color: #16a34a; }
        .toolbar-badge.idle { background: rgba(148, 163, 184, 0.14); color: #64748b; }
        .toolbar-badge.needs_runtime { background: rgba(245, 158, 11, 0.14); color: #d97706; }

        /* ── Loading state ── */
        .toolbar-btn.is-loading {
            position: relative;
            color: transparent !important;
            pointer-events: none;
        }

        .toolbar-btn.is-loading::after {
            content: '';
            position: absolute;
            width: 14px;
            height: 14px;
            top: 50%;
            left: 50%;
            margin: -7px 0 0 -7px;
            border: 2px solid rgba(148, 163, 184, 0.3);
            border-top-color: var(--text-primary);
            border-radius: 50%;
            animation: btn-spin 0.6s linear infinite;
        }

        .toolbar-btn.primary.is-loading::after {
            border-color: rgba(255, 255, 255, 0.3);
            border-top-color: #fff;
        }

        .btn.is-loading {
            position: relative;
            color: transparent !important;
            pointer-events: none;
        }

        .btn.is-loading::after {
            content: '';
            position: absolute;
            width: 16px;
            height: 16px;
            top: 50%;
            left: 50%;
            margin: -8px 0 0 -8px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-top-color: #fff;
            border-radius: 50%;
            animation: btn-spin 0.6s linear infinite;
        }

        .btn-secondary.is-loading::after {
            border-color: rgba(148, 163, 184, 0.3);
            border-top-color: var(--text-primary);
        }

        .btn:disabled,
        .btn[disabled],
        .toolbar-btn:disabled,
        .toolbar-btn[disabled] {
            opacity: 0.45;
            cursor: not-allowed !important;
            pointer-events: none;
            filter: saturate(0.35);
            box-shadow: none;
        }

        @keyframes btn-spin {
            to { transform: rotate(360deg); }
        }

        /* ── IDE body ── */
        .studio-body {
            flex: 1;
            display: flex;
            min-height: 0;
            overflow: hidden;
        }

        .studio-body.display-mode-ai .file-panel {
            display: none;
        }

        .studio-body.display-mode-ai .editor-pane {
            display: none;
        }

        .studio-body.display-mode-manual .ai-pane {
            display: none;
        }

        /* ── File tree panel ── */
        .file-panel {
            width: 220px;
            min-width: 0;
            border-right: 1px solid var(--border-color);
            background: var(--bg-secondary);
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
            transition: width 0.2s ease, opacity 0.2s ease;
        }

        .file-panel.collapsed {
            width: 0;
            overflow: hidden;
            border-right: none;
        }

        .file-panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 12px;
            border-bottom: 1px solid var(--border-color);
            gap: 8px;
        }

        .file-panel-title {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--text-muted);
        }

        .file-panel-actions {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .file-panel-actions button {
            width: 26px;
            height: 26px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: none;
            border-radius: 6px;
            background: transparent;
            color: var(--text-muted);
            cursor: pointer;
            font-size: 12px;
        }

        .file-panel-actions button:hover {
            background: rgba(255,255,255,0.06);
            color: var(--text-primary);
        }

        .file-tree-filter {
            padding: 8px 10px;
            border-bottom: 1px solid var(--border-color);
        }

        .file-tree-filter-wrap {
            position: relative;
        }

        .file-tree-filter-wrap i {
            position: absolute;
            left: 8px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 11px;
            color: var(--text-muted);
            pointer-events: none;
        }

        .file-tree-filter input {
            width: 100%;
            padding: 5px 8px 5px 26px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            background: var(--bg-primary);
            color: var(--text-primary);
            font-size: 12px;
        }

        .file-tree-filter input:focus {
            outline: none;
            border-color: var(--accent-primary);
        }

        .file-tree {
            padding: 6px;
            overflow: auto;
            flex: 1;
        }

        .tree-row {
            display: flex;
            align-items: center;
            gap: 6px;
            width: 100%;
            padding: 5px 8px;
            border-radius: 6px;
            border: 0;
            background: transparent;
            color: var(--text-primary);
            text-align: left;
            cursor: pointer;
            font-size: 12px;
        }

        .tree-row:hover {
            background: rgba(255,255,255,0.04);
        }

        .tree-row.active {
            background: rgba(37, 99, 235, 0.14);
            color: #2563eb;
        }

        .tree-row .disclosure {
            width: 12px;
            color: var(--text-muted);
            flex-shrink: 0;
            text-align: center;
            font-size: 10px;
        }

        .tree-row .file-name {
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .tree-row.directory {
            font-weight: 600;
        }

        .tree-row i.fa-folder { color: #d97706; }

        /* ── Editor + Preview split ── */
        .editor-preview-split {
            flex: 1;
            display: flex;
            min-width: 0;
            min-height: 0;
        }

        .editor-pane {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            border-right: 1px solid var(--border-color);
        }

        .preview-pane {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
        }

        .ai-pane {
            width: 360px;
            min-width: 0;
            display: flex;
            flex-direction: column;
            border-left: 1px solid var(--border-color);
            background: var(--bg-secondary);
            flex-shrink: 0;
            transition: width 0.2s ease, opacity 0.2s ease;
        }

        .ai-pane.collapsed {
            width: 0;
            opacity: 0;
            overflow: hidden;
            border-left: none;
        }

        .ai-pane-body {
            flex: 1;
            min-height: 0;
            display: flex;
            flex-direction: column;
        }

        .ai-pane-toolbar {
            display: flex;
            flex-direction: column;
            gap: 8px;
            padding: 10px 12px;
            border-bottom: 1px solid var(--border-color);
        }

        .ai-pane-grid {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 8px;
            align-items: end;
        }

        .ai-inline-toggle {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            font-size: 12px;
            color: var(--text-secondary);
            cursor: pointer;
            user-select: none;
        }

        .ai-inline-toggle input {
            margin: 0;
        }

        .ai-inline-toggle.disabled {
            opacity: 0.45;
            cursor: default;
        }

        .ai-field {
            display: flex;
            flex-direction: column;
            gap: 4px;
            min-width: 0;
        }

        .ai-field-label {
            font-size: 11px;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .ai-select,
        .ai-input,
        .ai-textarea {
            width: 100%;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--bg-primary);
            color: var(--text-primary);
            font-size: 12px;
        }

        .ai-select,
        .ai-input {
            padding: 7px 9px;
        }

        .ai-textarea {
            min-height: 110px;
            padding: 10px 11px;
            resize: vertical;
            line-height: 1.5;
        }

        .ai-select:focus,
        .ai-input:focus,
        .ai-textarea:focus {
            outline: none;
            border-color: var(--accent-primary);
        }

        .ai-helper-text {
            margin: 0;
            font-size: 11px;
            color: var(--text-muted);
            line-height: 1.5;
        }

        .ai-messages {
            flex: 1;
            min-height: 0;
            overflow: auto;
            padding: 12px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            background:
                linear-gradient(180deg, rgba(37, 99, 235, 0.04) 0%, rgba(15, 23, 42, 0) 40%),
                var(--bg-secondary);
        }

        .ai-empty-state {
            margin: auto 0;
            padding: 16px;
            border: 1px dashed var(--border-color);
            border-radius: 14px;
            text-align: center;
            color: var(--text-secondary);
            background: rgba(255,255,255,0.02);
        }

        .ai-empty-state.inline {
            margin: 0;
        }

        .ai-empty-state > i {
            font-size: 24px;
            margin-bottom: 10px;
            color: var(--accent-primary);
        }

        .ai-message {
            display: flex;
            flex-direction: column;
            gap: 6px;
            max-width: 100%;
        }

        .ai-message-role {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--text-muted);
        }

        .ai-message-bubble {
            padding: 10px 12px;
            border-radius: 14px;
            font-size: 12px;
            line-height: 1.55;
            white-space: pre-wrap;
            word-break: break-word;
            border: 1px solid var(--border-color);
            background: rgba(255,255,255,0.03);
        }

        .ai-message.user .ai-message-bubble {
            background: rgba(37, 99, 235, 0.12);
            border-color: rgba(37, 99, 235, 0.28);
        }

        .ai-message.assistant .ai-message-bubble {
            background: rgba(15, 23, 42, 0.38);
        }

        .ai-message.context .ai-message-role {
            color: rgba(96, 165, 250, 0.92);
        }

        .ai-message.context .ai-message-bubble {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
            background: rgba(37, 99, 235, 0.08);
            border-color: rgba(96, 165, 250, 0.24);
        }

        .ai-context-bubble-copy {
            min-width: 0;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            flex: 1;
        }

        .ai-context-bubble-copy i {
            margin-top: 2px;
            color: rgba(96, 165, 250, 0.92);
        }

        .ai-context-bubble-text {
            min-width: 0;
            display: flex;
            flex-direction: column;
            gap: 3px;
        }

        .ai-context-bubble-title {
            font-size: 11px;
            font-weight: 700;
            color: var(--text-primary);
        }

        .ai-context-bubble-description {
            min-width: 0;
            color: var(--text-secondary);
            word-break: break-word;
        }

        .ai-context-bubble-remove {
            flex: 0 0 auto;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 28px;
            height: 28px;
            padding: 0;
            line-height: 1;
            border-radius: 999px;
            border: 1px solid rgba(148, 163, 184, 0.22);
            background: rgba(15, 23, 42, 0.42);
            color: var(--text-secondary);
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .ai-context-bubble-remove:hover {
            border-color: rgba(248, 113, 113, 0.35);
            background: rgba(127, 29, 29, 0.2);
            color: #fca5a5;
        }

        .ai-message-actions {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .ai-message-action {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 9px;
            border: 1px solid var(--border-color);
            border-radius: 999px;
            background: rgba(255,255,255,0.03);
            color: var(--text-secondary);
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .ai-message-action:hover {
            border-color: var(--accent-primary);
            color: var(--text-primary);
            background: rgba(37, 99, 235, 0.08);
        }

        .ai-message-action:disabled,
        .ai-message-action[disabled] {
            opacity: 0.45;
            cursor: not-allowed;
            pointer-events: none;
        }

        .ai-message-flag {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 11px;
            color: var(--text-muted);
        }

        .ai-plan-box {
            display: none;
            border-top: 1px solid var(--border-color);
            padding: 12px;
            background: rgba(15, 23, 42, 0.28);
        }

        .ai-plan-box.visible {
            display: block;
        }

        .ai-plan-title {
            margin: 0 0 8px 0;
            font-size: 12px;
            font-weight: 700;
            color: var(--text-primary);
        }

        .ai-plan-summary {
            margin: 0 0 10px 0;
            font-size: 12px;
            color: var(--text-secondary);
        }

        .ai-plan-list {
            margin: 0;
            padding: 0;
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .ai-plan-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            padding: 8px 10px;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            background: rgba(255,255,255,0.03);
            font-size: 12px;
        }

        .ai-plan-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 7px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            background: rgba(37, 99, 235, 0.14);
            color: #60a5fa;
            flex-shrink: 0;
        }

        .ai-plan-badge.delete {
            background: rgba(239, 68, 68, 0.14);
            color: #f87171;
        }

        .ai-plan-path {
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            color: var(--text-primary);
        }

        .ai-plan-notes {
            margin: 10px 0 0 0;
            padding-left: 16px;
            color: var(--text-secondary);
            font-size: 12px;
        }

        .ai-plan-notes li + li {
            margin-top: 4px;
        }

        .ai-plan-lint {
            margin-top: 12px;
            padding: 12px;
            border: 1px solid rgba(245, 158, 11, 0.34);
            border-radius: 12px;
            background: rgba(245, 158, 11, 0.08);
        }

        .ai-plan-lint.danger {
            border-color: rgba(239, 68, 68, 0.36);
            background: rgba(239, 68, 68, 0.08);
        }

        .ai-plan-lint-title {
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 0 0 10px 0;
            font-size: 12px;
            font-weight: 700;
            color: #fbbf24;
        }

        .ai-plan-lint.danger .ai-plan-lint-title {
            color: #fca5a5;
        }

        .ai-plan-lint-list {
            margin: 0;
            padding: 0;
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .ai-plan-lint-item {
            padding: 10px;
            border-radius: 10px;
            background: rgba(15, 23, 42, 0.16);
            border: 1px solid rgba(245, 158, 11, 0.18);
        }

        .ai-plan-lint-item.danger {
            border-color: rgba(239, 68, 68, 0.3);
            background: rgba(127, 29, 29, 0.16);
        }

        .ai-plan-lint-item-title {
            font-size: 12px;
            font-weight: 700;
            color: var(--text-primary);
        }

        .ai-plan-lint-item-meta {
            margin-top: 4px;
            font-size: 11px;
            color: var(--text-muted);
            font-family: "SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace;
        }

        .ai-plan-lint-item-message {
            margin-top: 6px;
            font-size: 12px;
            line-height: 1.5;
            color: var(--text-secondary);
        }

        .ai-plan-actions {
            margin-top: 12px;
            display: flex;
            gap: 8px;
        }

        .ai-composer {
            border-top: 1px solid var(--border-color);
            padding: 12px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .ai-composer-actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }

        .ai-composer-meta {
            display: flex;
            align-items: center;
            gap: 8px;
            min-width: 0;
            flex: 1;
        }

        .ai-target-summary {
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .ai-provider-actions {
            display: flex;
            gap: 8px;
            justify-content: flex-end;
        }

        .ai-field-row {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 8px;
            align-items: end;
        }

        .ai-target-lines {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px;
        }

        .ai-target-modal {
            width: min(980px, 100%);
        }

        .ai-target-lines-modal {
            width: min(92vw, 1500px);
            height: 88vh;
            max-height: 88vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        @media (max-width: 980px) {
            .ai-composer-actions {
                flex-direction: column;
                align-items: stretch;
            }
        }

        .ai-provider-hint {
            padding: 8px 10px;
            border-radius: 10px;
            background: rgba(255,255,255,0.03);
            color: var(--text-secondary);
            font-size: 12px;
            line-height: 1.5;
        }

        .ai-provider-advanced {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .ai-provider-json {
            min-height: 120px;
            font-family: "SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace;
        }

        .ai-diff-modal {
            width: min(1080px, 100%);
        }

        .ai-target-modal-layout {
            display: grid;
            grid-template-columns: 280px minmax(0, 1fr);
            gap: 16px;
            min-height: 420px;
        }

        .ai-target-modal-sidebar,
        .ai-target-modal-main {
            min-width: 0;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .ai-target-editor {
            border: 1px solid var(--border-color);
            border-radius: 12px;
            background: #0b1120;
            overflow: hidden;
            min-height: 0;
            display: flex;
            flex-direction: column;
            flex: 1;
        }

        .ai-target-editor-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 10px 12px;
            border-bottom: 1px solid #1e293b;
            background: rgba(15, 23, 42, 0.55);
            color: #94a3b8;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .ai-target-editor-path {
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .ai-target-editor-surface {
            flex: 1;
            min-height: 0;
            display: flex;
            position: relative;
            overflow: hidden;
        }

        .ai-target-editor-empty {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            color: #94a3b8;
            font-size: 12px;
            padding: 20px;
        }

        .ai-target-editor-empty.hidden,
        .ai-target-editor-wrap.hidden {
            display: none;
        }

        .ai-target-editor-wrap {
            flex: 1;
            min-height: 0;
            display: flex;
            overflow: hidden;
        }

        .ai-target-editor-lines {
            position: relative;
            width: 56px;
            flex-shrink: 0;
            background: #08101f;
            color: #475569;
            border-right: 1px solid #1e293b;
            overflow: hidden;
        }

        .ai-target-editor-lines-inner {
            position: relative;
            z-index: 1;
            padding: 12px 8px 12px 0;
            text-align: right;
            font: 12px/1.6 "SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace;
            white-space: pre;
            user-select: none;
            pointer-events: none;
        }

        .ai-target-editor-lines-highlight,
        .ai-target-editor-code-highlight {
            position: absolute;
            left: 0;
            right: 0;
            display: none;
            pointer-events: none;
            background: linear-gradient(180deg, rgba(59, 130, 246, 0.34), rgba(96, 165, 250, 0.22));
            border-top: 1px solid rgba(147, 197, 253, 0.95);
            border-bottom: 1px solid rgba(147, 197, 253, 0.95);
            box-shadow:
                inset 0 1px 0 rgba(255, 255, 255, 0.1),
                inset 0 -1px 0 rgba(255, 255, 255, 0.08),
                0 0 0 1px rgba(59, 130, 246, 0.12);
        }

        .ai-target-editor-lines-highlight {
            z-index: 0;
            background: linear-gradient(180deg, rgba(30, 64, 175, 0.45), rgba(37, 99, 235, 0.26));
            border-right: 1px solid rgba(147, 197, 253, 0.35);
        }

        .ai-target-editor-code {
            position: relative;
            flex: 1;
            min-width: 0;
            min-height: 0;
            overflow: hidden;
            background: #0f172a;
        }

        .ai-target-editor-code-highlight {
            z-index: 1;
        }

        .ai-target-editor-textarea {
            position: relative;
            z-index: 2;
            flex: 1;
            width: 100%;
            height: 100%;
            min-height: 0;
            border: 0;
            background: transparent;
            color: #e2e8f0;
            padding: 12px 16px;
            resize: none;
            font: 12px/1.6 "SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace;
            overflow: auto;
        }

        .ai-target-editor-textarea:focus {
            outline: none;
        }

        .ai-target-editor-textarea::selection {
            background: rgba(191, 219, 254, 0.32);
            color: #f8fafc;
        }

        .ai-target-lines-modal .modal-header,
        .ai-target-lines-modal .modal-footer {
            flex-shrink: 0;
        }

        .ai-target-lines-modal .modal-body {
            flex: 1;
            min-height: 0;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .ai-target-file-list {
            flex: 1;
            min-height: 260px;
            overflow: auto;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            background: var(--bg-primary);
            padding: 8px;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .ai-target-file-button {
            width: 100%;
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 9px 10px;
            border: 1px solid transparent;
            border-radius: 10px;
            background: transparent;
            color: var(--text-primary);
            text-align: left;
            cursor: pointer;
            font-size: 12px;
        }

        .ai-target-file-button:hover {
            background: rgba(255,255,255,0.03);
            border-color: var(--border-color);
        }

        .ai-target-file-button.active {
            background: rgba(37, 99, 235, 0.1);
            border-color: rgba(37, 99, 235, 0.35);
        }

        .ai-target-file-button-path {
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .ai-target-empty {
            margin: auto 0;
            padding: 16px;
            border: 1px dashed var(--border-color);
            border-radius: 12px;
            text-align: center;
            color: var(--text-secondary);
            font-size: 12px;
        }

        .ai-target-summary-box {
            padding: 12px;
            border-radius: 12px;
            border: 1px solid var(--border-color);
            background: rgba(255,255,255,0.02);
            color: var(--text-secondary);
            font-size: 12px;
            line-height: 1.6;
        }

        .ai-target-summary-title {
            font-size: 12px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 4px;
        }

        .ai-target-modal-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .ai-target-main-copy {
            margin: 0;
            color: var(--text-secondary);
            font-size: 12px;
            line-height: 1.6;
        }

        @media (max-width: 980px) {
            .ai-target-modal-layout {
                grid-template-columns: 1fr;
                min-height: 0;
            }
        }

        .ai-diff-layout {
            display: grid;
            grid-template-columns: 260px minmax(0, 1fr);
            gap: 16px;
            min-height: 500px;
        }

        .ai-diff-files {
            border: 1px solid var(--border-color);
            border-radius: 12px;
            background: var(--bg-primary);
            overflow: auto;
            padding: 8px;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .ai-diff-file {
            display: flex;
            flex-direction: column;
            gap: 4px;
            width: 100%;
            padding: 10px 12px;
            border: 1px solid transparent;
            border-radius: 10px;
            background: transparent;
            color: var(--text-primary);
            text-align: left;
            cursor: pointer;
        }

        .ai-diff-file:hover {
            background: rgba(255,255,255,0.03);
            border-color: var(--border-color);
        }

        .ai-diff-file.active {
            background: rgba(37, 99, 235, 0.1);
            border-color: rgba(37, 99, 235, 0.35);
        }

        .ai-diff-file-path {
            font-size: 12px;
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .ai-diff-file-meta {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 11px;
            color: var(--text-muted);
        }

        .ai-diff-view {
            border: 1px solid var(--border-color);
            border-radius: 12px;
            background: #0b1120;
            overflow: hidden;
            min-width: 0;
            display: flex;
            flex-direction: column;
        }

        .ai-diff-meta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 12px 14px;
            border-bottom: 1px solid #1e293b;
            background: rgba(15, 23, 42, 0.94);
        }

        .ai-diff-meta-main {
            min-width: 0;
        }

        .ai-diff-meta-path {
            font-size: 13px;
            font-weight: 700;
            color: #e2e8f0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .ai-diff-meta-copy {
            margin-top: 4px;
            font-size: 11px;
            color: #94a3b8;
        }

        .ai-diff-meta-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 9px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            background: rgba(37, 99, 235, 0.18);
            color: #93c5fd;
            flex-shrink: 0;
        }

        .ai-diff-meta-badge.delete {
            background: rgba(239, 68, 68, 0.18);
            color: #fca5a5;
        }

        .ai-diff-meta-badge.create {
            background: rgba(34, 197, 94, 0.18);
            color: #86efac;
        }

        .ai-diff-content {
            flex: 1;
            min-height: 0;
            overflow: auto;
            font: 12px/1.6 "SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace;
            color: #e2e8f0;
        }

        .ai-diff-content-inner {
            min-width: 100%;
            width: max-content;
        }

        .ai-diff-empty {
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            color: #94a3b8;
            text-align: center;
        }

        .ai-diff-line {
            display: grid;
            grid-template-columns: 52px 52px 24px auto;
            gap: 0;
            min-width: max-content;
        }

        .ai-diff-line:hover {
            background: rgba(255,255,255,0.03);
        }

        .ai-diff-line-num,
        .ai-diff-line-mark {
            user-select: none;
            color: #64748b;
        }

        .ai-diff-line-num,
        .ai-diff-line-mark,
        .ai-diff-line-text {
            padding: 0 10px;
            white-space: pre;
        }

        .ai-diff-line-text {
            overflow: visible;
        }

        .ai-diff-line.add {
            background: rgba(34, 197, 94, 0.14);
        }

        .ai-diff-line.add .ai-diff-line-mark,
        .ai-diff-line.add .ai-diff-line-num {
            color: #86efac;
        }

        .ai-diff-line.delete {
            background: rgba(239, 68, 68, 0.14);
        }

        .ai-diff-line.delete .ai-diff-line-mark,
        .ai-diff-line.delete .ai-diff-line-num {
            color: #fca5a5;
        }

        .ai-diff-line.context {
            background: transparent;
        }

        .ai-diff-gap {
            display: grid;
            grid-template-columns: 52px 52px 24px auto;
            min-width: max-content;
            color: #64748b;
            background: rgba(15, 23, 42, 0.42);
            border-top: 1px dashed rgba(100, 116, 139, 0.32);
            border-bottom: 1px dashed rgba(100, 116, 139, 0.32);
            font-style: italic;
        }

        .ai-diff-gap span {
            padding: 2px 10px;
            white-space: pre;
        }

        .ai-diff-toolbar {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .pane-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 12px;
            height: 36px;
            min-height: 36px;
            border-bottom: 1px solid var(--border-color);
            background: rgba(255,255,255,0.015);
            gap: 10px;
        }

        .pane-header-left {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
        }

        .pane-title {
            font-size: 11px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.06em;
            font-weight: 700;
            flex-shrink: 0;
        }

        .pane-file-path {
            font-size: 12px;
            color: var(--text-secondary);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            min-width: 0;
        }

        .pane-header-actions {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-shrink: 0;
        }

        .pane-header-actions button,
        .pane-header-actions a {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            padding: 4px 8px;
            font-size: 11px;
            font-weight: 600;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            background: var(--bg-primary);
            color: var(--text-secondary);
            cursor: pointer;
            white-space: nowrap;
            transition: all 0.15s ease;
            text-decoration: none;
        }

        .pane-header-actions button:hover,
        .pane-header-actions a:hover {
            border-color: var(--accent-primary);
            color: var(--text-primary);
        }

        .pane-header-actions button:disabled {
            opacity: 0.4;
            pointer-events: none;
        }

        .pane-header-actions button.save-btn {
            background: var(--accent-primary);
            border-color: var(--accent-primary);
            color: #fff;
        }

        .pane-header-actions button.save-btn:hover {
            opacity: 0.9;
        }

        .pane-header-actions button.danger-btn:hover {
            border-color: rgba(239, 68, 68, 0.5);
            color: #dc2626;
            background: rgba(239, 68, 68, 0.08);
        }

        .save-state {
            font-size: 11px;
            font-weight: 600;
            color: var(--text-muted);
        }

        .save-state.dirty { color: #d97706; }
        .save-state.saved { color: #16a34a; }

        /* ── Editor surface ── */
        .editor-surface {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 0;
        }

        .editor-placeholder,
        .binary-placeholder {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            color: var(--text-muted);
            font-size: 13px;
            padding: 24px;
        }

        .editor-placeholder.hidden,
        .binary-placeholder.hidden,
        .editor-textarea-wrap.hidden {
            display: none;
        }

        .editor-textarea-wrap {
            flex: 1;
            min-height: 0;
            display: flex;
            position: relative;
        }

        .editor-line-numbers {
            width: 44px;
            flex-shrink: 0;
            background: #0b1120;
            color: #475569;
            border-right: 1px solid #1e293b;
            padding: 12px 6px 12px 0;
            text-align: right;
            font: 13px/1.6 "SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace;
            overflow: hidden;
            user-select: none;
            pointer-events: none;
        }

        .editor-textarea {
            flex: 1;
            width: 0;
            border: 0;
            background: #0f172a;
            color: #e2e8f0;
            padding: 12px 16px;
            resize: none;
            font: 13px/1.6 "SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace;
        }

        .editor-textarea:focus {
            outline: none;
        }

        /* ── Preview surface ── */
        .preview-surface {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 0;
        }

        .preview-address-bar {
            display: none;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-bottom: 1px solid var(--border-color);
        }

        .preview-address-bar.visible {
            display: flex;
        }

        .preview-address-input {
            flex: 1;
            padding: 4px 8px 4px 26px;
            font-size: 12px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            background: var(--bg-primary);
            color: var(--text-primary);
        }

        .preview-address-input:focus {
            outline: none;
            border-color: var(--accent-primary);
        }

        .preview-address-wrap {
            position: relative;
            flex: 1;
        }

        .preview-address-wrap i {
            position: absolute;
            left: 8px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 11px;
            color: var(--text-muted);
            pointer-events: none;
        }

        .preview-placeholder {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            color: var(--text-muted);
            font-size: 13px;
            padding: 24px;
        }

        .preview-placeholder.hidden {
            display: none;
        }

        .preview-frame {
            border: 0;
            width: 100%;
            flex: 1;
            background: white;
        }

        .preview-frame.hidden {
            display: none;
        }

        /* ── Validation bar ── */
        .validation-bar {
            display: none;
            border-top: 1px solid var(--border-color);
            background: var(--bg-secondary);
            max-height: 180px;
            overflow-y: auto;
            padding: 12px 16px;
            font-size: 13px;
        }

        .validation-bar.visible {
            display: block;
        }

        .validation-bar h3 {
            margin: 0 0 8px 0;
            font-size: 13px;
            font-weight: 700;
        }

        .validation-group + .validation-group {
            margin-top: 10px;
        }

        .validation-group-title {
            margin: 0 0 4px 0;
            font-size: 12px;
            font-weight: 700;
        }

        .validation-group-title.error { color: #dc2626; }
        .validation-group-title.warning { color: #d97706; }

        .validation-list {
            margin: 0;
            padding-left: 18px;
        }

        .validation-list li {
            font-size: 12px;
        }

        .validation-list li + li {
            margin-top: 4px;
        }

        /* ── Empty state (no workspace) ── */
        .studio-empty {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            color: var(--text-secondary);
            padding: 40px;
        }

        .studio-empty > i {
            font-size: 40px;
            margin-bottom: 16px;
            opacity: 0.5;
        }

        .studio-empty h3 {
            margin: 0 0 8px 0;
            font-size: 18px;
            color: var(--text-primary);
        }

        .studio-empty p {
            margin: 0;
            max-width: 400px;
        }

        /* ── App switcher bar ── */
        .app-switcher-bar {
            display: none;
            align-items: center;
            gap: 10px;
            padding: 6px 16px;
            border-bottom: 1px solid var(--border-color);
            background: rgba(37, 99, 235, 0.04);
            font-size: 12px;
            color: var(--text-secondary);
        }

        .app-switcher-bar.visible {
            display: flex;
        }

        .app-switcher-bar select {
            padding: 4px 8px;
            font-size: 12px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            background: var(--bg-primary);
            color: var(--text-primary);
        }

        /* ── Modal (preserved from previous version) ── */
        .modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.72);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 24px;
            z-index: 1000;
        }

        .modal-backdrop.active {
            display: flex;
        }

        .modal-backdrop.modal-top-layer {
            z-index: 1020;
            background: rgba(2, 6, 23, 0.82);
        }

        .modal {
            width: min(700px, 100%);
            max-height: 90vh;
            overflow-y: auto;
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            box-shadow: 0 24px 60px rgba(15, 23, 42, 0.32);
        }

        .modal-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }

        .modal-header h2 {
            margin: 0;
            font-size: 18px;
        }

        .modal-close {
            width: 34px;
            height: 34px;
            border: 0;
            border-radius: 8px;
            background: transparent;
            color: var(--text-secondary);
            cursor: pointer;
        }

        .modal-close:hover {
            background: rgba(255,255,255,0.05);
            color: var(--text-primary);
        }

        .modal-body {
            padding: 20px;
        }

        .modal-panel.hidden {
            display: none;
        }

        .modal-form-subtitle {
            margin: 0 0 16px 0;
            color: var(--text-secondary);
            font-size: 14px;
        }

        .form-stack {
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .form-label-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .form-hint {
            margin: 6px 0 0;
            font-size: 12px;
            color: var(--text-muted);
            line-height: 1.45;
        }

        .form-hint.hidden {
            display: none;
        }

        .mode-toggle {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }

        .mode-option {
            position: relative;
            display: flex;
            flex-direction: column;
            gap: 4px;
            padding: 14px;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            background: var(--bg-primary);
            cursor: pointer;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .mode-option:hover {
            border-color: var(--accent-primary);
        }

        .mode-option.active {
            border-color: var(--accent-primary);
            box-shadow: 0 0 0 1px var(--accent-primary);
        }

        .mode-option input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }

        .mode-option-title {
            font-size: 14px;
            font-weight: 700;
            color: var(--text-primary);
        }

        .mode-option-copy {
            font-size: 12px;
            color: var(--text-secondary);
            line-height: 1.45;
        }

        .color-picker-row {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 12px 14px;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            background: var(--bg-primary);
        }

        .color-picker-input {
            width: 48px;
            height: 36px;
            padding: 0;
            border: 0;
            background: transparent;
            cursor: pointer;
        }

        .color-picker-input::-webkit-color-swatch-wrapper { padding: 0; }
        .color-picker-input::-webkit-color-swatch { border: 1px solid var(--border-color); border-radius: 10px; }
        .color-picker-input::-moz-color-swatch { border: 1px solid var(--border-color); border-radius: 10px; }

        .color-value {
            font-size: 13px;
            font-weight: 700;
            font-family: "SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace;
            color: var(--text-primary);
        }

        .icon-preview-chip {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 14px;
            margin-bottom: 10px;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            background: var(--bg-primary);
        }

        .icon-preview-glyph {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(37, 99, 235, 0.12);
            color: #2563eb;
            font-size: 16px;
            flex-shrink: 0;
        }

        .icon-preview-title {
            font-size: 13px;
            font-weight: 700;
            color: var(--text-primary);
        }

        .icon-picker {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(64px, 1fr));
            gap: 8px;
        }

        .icon-choice {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 6px;
            min-height: 70px;
            padding: 8px 6px;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            background: var(--bg-primary);
            color: var(--text-primary);
            cursor: pointer;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .icon-choice:hover {
            border-color: var(--accent-primary);
        }

        .icon-choice.active {
            border-color: var(--accent-primary);
            color: var(--accent-primary);
            box-shadow: 0 0 0 1px var(--accent-primary);
        }

        .icon-choice i { font-size: 16px; }
        .icon-choice span { font-size: 10px; line-height: 1.3; text-align: center; }

        .advanced-fields {
            display: flex;
            flex-direction: column;
            gap: 14px;
            padding-top: 6px;
            margin-top: 2px;
            border-top: 1px solid var(--border-color);
        }

        .advanced-fields.hidden {
            display: none;
        }

        /* ── Responsive ── */
        @media (max-width: 900px) {
            .editor-preview-split {
                flex-direction: column;
            }

            .editor-pane {
                border-right: none;
                border-bottom: 1px solid var(--border-color);
                min-height: 40vh;
            }

            .preview-pane {
                min-height: 30vh;
            }

            .ai-pane {
                width: 100%;
                border-left: none;
                border-top: 1px solid var(--border-color);
                min-height: 360px;
            }

            .ai-pane.collapsed {
                width: 100%;
                min-height: 0;
                height: 0;
                border-top: none;
            }

            .file-panel {
                width: 180px;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .mode-toggle {
                grid-template-columns: 1fr;
            }

            .ai-diff-layout {
                grid-template-columns: 1fr;
            }

            .ai-diff-files {
                max-height: 180px;
            }
        }

        @media (max-width: 600px) {
            .studio-toolbar {
                gap: 8px;
                padding: 0 10px;
            }

            .toolbar-select {
                min-width: 130px;
                max-width: 160px;
            }

            .display-mode-btn span {
                display: none;
            }

            .file-panel {
                position: absolute;
                z-index: 10;
                top: 48px;
                left: 0;
                bottom: 0;
                width: 240px;
                box-shadow: 4px 0 20px rgba(0,0,0,0.3);
            }

            .file-panel.collapsed {
                width: 0;
            }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <?php $layout->renderSidebar(); ?>

        <main class="main-content">
            <!-- Toolbar -->
            <div class="studio-toolbar">
                <span class="studio-toolbar-title"><i class="fas fa-screwdriver-wrench"></i> Studio</span>

                <div class="toolbar-separator"></div>

                <select class="toolbar-select" id="workspace-select">
                    <option value="">Select workspace...</option>
                    <?php foreach ($workspaces as $workspace): ?>
                        <option value="<?= htmlspecialchars($workspace['id']) ?>">
                            <?= ($workspace['source_type'] ?? '') === 'repository' ? '🔀' : '✦' ?>
                            <?= htmlspecialchars($workspace['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <?php if ($studioAiEnabled): ?>
                    <div class="display-mode-toggle" id="display-mode-toggle" aria-label="Studio display mode">
                        <button class="display-mode-btn" id="display-mode-manual-btn" type="button" title="Manual mode">
                            <i class="fas fa-folder-tree"></i>
                            <span>Manual</span>
                        </button>
                        <button class="display-mode-btn" id="display-mode-ai-btn" type="button" title="AI mode">
                            <i class="fas fa-wand-magic-sparkles"></i>
                            <span>AI</span>
                        </button>
                    </div>
                <?php endif; ?>

                <span class="toolbar-badge idle" id="toolbar-preview-badge" style="display:none;">
                    <i class="fas fa-circle-info"></i>
                    <span>idle</span>
                </span>

                <div class="toolbar-separator"></div>

                <div class="toolbar-group">
                    <button class="toolbar-btn" id="toggle-files-btn" title="Toggle file tree">
                        <i class="fas fa-folder-tree"></i>
                    </button>
                    <button class="toolbar-btn" id="toolbar-validate-btn" title="Validate workspace" disabled>
                        <i class="fas fa-check-circle"></i> Validate
                    </button>
                    <button class="toolbar-btn" id="toolbar-sync-preview-btn" title="Sync preview" disabled>
                        <i class="fas fa-rotate"></i> Sync
                    </button>
                    <button class="toolbar-btn" id="toolbar-open-preview-btn" title="Open preview in new tab" disabled>
                        <i class="fas fa-up-right-from-square"></i>
                    </button>
                </div>

                <div class="toolbar-separator"></div>

                <div class="toolbar-group">
                    <button class="toolbar-btn" id="toolbar-install-app-btn" title="Install the selected workspace app" disabled>
                        <i class="fas fa-download"></i>
                        <span id="toolbar-install-app-label">Install App</span>
                    </button>
                    <button class="toolbar-btn" id="toolbar-publish-app-btn" title="Publish the selected workspace app to the local marketplace" disabled>
                        <i class="fas fa-store"></i> Publish
                    </button>
                </div>

                <div class="toolbar-spacer"></div>

                <label style="display:inline-flex; align-items:center; gap:6px; font-size:11px; color:var(--text-muted); cursor:pointer;">
                    <input type="checkbox" id="auto-preview-toggle" checked>
                    Auto-sync
                </label>

                <div class="toolbar-separator"></div>

                <div class="toolbar-group">
                    <button class="toolbar-btn primary" id="toolbar-add-btn" title="Add workspace">
                        <i class="fas fa-plus"></i> New
                    </button>
                    <button class="toolbar-btn danger" id="toolbar-delete-btn" title="Delete workspace" disabled>
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </div>

            <!-- App switcher (for multi-app repos) -->
            <div class="app-switcher-bar" id="app-switcher-bar">
                <i class="fas fa-layer-group"></i>
                <span id="app-switcher-hint">Multiple apps detected.</span>
                <select id="app-switcher-select"></select>
            </div>

            <!-- IDE body -->
            <div class="studio-body" id="studio-body">
                <!-- Empty state -->
                <div class="studio-empty" id="studio-empty">
                    <div>
                        <i class="fas fa-screwdriver-wrench"></i>
                        <h3>Select or create a workspace</h3>
                        <p>Pick a workspace from the dropdown above, or click <strong>New</strong> to create one.</p>
                    </div>
                </div>

                <!-- File tree panel -->
                <div class="file-panel collapsed" id="file-panel">
                    <div class="file-panel-header">
                        <span class="file-panel-title">Explorer</span>
                        <div class="file-panel-actions">
                            <button id="new-file-btn" title="New file"><i class="fas fa-plus"></i></button>
                            <button id="refresh-files-btn" title="Refresh"><i class="fas fa-rotate"></i></button>
                        </div>
                    </div>
                    <div class="file-tree-filter">
                        <div class="file-tree-filter-wrap">
                            <i class="fas fa-search"></i>
                            <input type="text" id="file-tree-filter" placeholder="Filter files..." autocomplete="off">
                        </div>
                    </div>
                    <div class="file-tree" id="file-tree"></div>
                </div>

                <!-- Editor + Preview split -->
                <div class="editor-preview-split" id="editor-preview-split" style="display:none;">
                    <!-- Editor pane -->
                    <section class="editor-pane">
                        <div class="pane-header">
                            <div class="pane-header-left">
                                <span class="pane-title">Editor</span>
                                <span class="pane-file-path" id="current-file-path">No file selected</span>
                            </div>
                            <div class="pane-header-actions">
                                <span class="save-state" id="save-state">No changes</span>
                                <button class="danger-btn" id="delete-file-btn" title="Delete file" disabled>
                                    <i class="fas fa-trash"></i>
                                </button>
                                <button class="save-btn" id="save-file-btn" title="Save (⌘S)" disabled>
                                    <i class="fas fa-save"></i> Save
                                </button>
                            </div>
                        </div>
                        <div class="editor-surface">
                            <div class="editor-placeholder" id="editor-placeholder">
                                Select a file from the explorer.
                            </div>
                            <div class="binary-placeholder hidden" id="binary-placeholder">
                                Binary file — read only.
                            </div>
                            <div class="editor-textarea-wrap hidden" id="editor-textarea-wrap">
                                <div class="editor-line-numbers" id="editor-line-numbers"></div>
                                <textarea class="editor-textarea" id="editor-textarea" spellcheck="false"></textarea>
                            </div>
                        </div>
                    </section>

                    <!-- Preview pane -->
                    <section class="preview-pane">
                        <div class="pane-header">
                            <div class="pane-header-left">
                                <span class="pane-title">Preview</span>
                                <span class="save-state" id="preview-status-text">Not synced</span>
                            </div>
                            <div class="pane-header-actions">
                                <button id="inline-preview-btn" title="Refresh preview">
                                    <i class="fas fa-rotate"></i>
                                </button>
                            </div>
                        </div>
                        <div class="preview-address-bar" id="preview-address-bar">
                            <div class="preview-address-wrap">
                                <i class="fas fa-globe"></i>
                                <input type="text" class="preview-address-input" id="preview-page-input" placeholder="index.php">
                            </div>
                            <button class="toolbar-btn" id="preview-go-btn" style="padding:4px 8px; font-size:11px;">
                                <i class="fas fa-arrow-right"></i>
                            </button>
                        </div>
                        <div class="preview-surface">
                            <div class="preview-placeholder" id="preview-placeholder">
                                Sync preview to load the app here.
                            </div>
                            <iframe class="preview-frame hidden" id="preview-frame" title="Workspace preview"></iframe>
                        </div>
                    </section>

                    <?php if ($studioAiEnabled): ?>
                        <section class="ai-pane collapsed" id="ai-pane">
                            <div class="pane-header">
                                <div class="pane-header-left">
                                    <span class="pane-title">AI</span>
                                    <span class="save-state" id="ai-status-text">Ready</span>
                                </div>
                                <div class="pane-header-actions">
                                    <?php if ($aiAdminEnabled): ?>
                                        <a href="ai.php" title="Open AI administration">
                                            <i class="fas fa-up-right-from-square"></i>
                                        </a>
                                    <?php endif; ?>
                                    <button id="open-ai-provider-modal-btn" title="Manage AI providers">
                                        <i class="fas fa-sliders"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="ai-pane-body">
                                <div class="ai-pane-toolbar">
                                    <div class="ai-pane-grid">
                                        <label class="ai-field">
                                            <span class="ai-field-label">Provider</span>
                                            <select class="ai-select" id="ai-provider-select"></select>
                                        </label>
                                        <button class="toolbar-btn" id="ai-refresh-models-btn" type="button" title="Refresh available models" style="height:32px;">
                                            <i class="fas fa-rotate"></i>
                                        </button>
                                    </div>
                                    <label class="ai-field">
                                        <span class="ai-field-label">Model</span>
                                        <input class="ai-input" id="ai-model-input" list="ai-model-options" placeholder="Auto-select best available model">
                                        <datalist id="ai-model-options"></datalist>
                                    </label>
                                    <label class="ai-inline-toggle" title="For OpenRouter, only show models with 'free' in the name">
                                        <input type="checkbox" id="ai-model-free-only" checked>
                                        <span>Free</span>
                                    </label>
                                    <p class="ai-helper-text" id="ai-context-text">The AI only proposes repo-relative workspace file edits. You review them before applying.</p>
                                </div>

                                <div class="ai-messages" id="ai-messages">
                                    <div class="ai-empty-state" id="ai-empty-state">
                                        <i class="fas fa-robot"></i>
                                        <div id="ai-empty-copy">Connect an AI provider to start building with prompts.</div>
                                    </div>
                                </div>

                                <div class="ai-plan-box" id="ai-plan-box">
                                    <h3 class="ai-plan-title">Pending Changes</h3>
                                    <p class="ai-plan-summary" id="ai-plan-summary">No proposed file changes yet.</p>
                                    <ul class="ai-plan-list" id="ai-plan-list"></ul>
                                    <ul class="ai-plan-notes" id="ai-plan-notes"></ul>
                                    <div class="ai-plan-lint" id="ai-plan-lint-box" style="display:none;">
                                        <div class="ai-plan-lint-title">
                                            <i class="fas fa-triangle-exclamation"></i>
                                            <span>AI Review Warnings</span>
                                        </div>
                                        <ul class="ai-plan-lint-list" id="ai-plan-lint-list"></ul>
                                    </div>
                                    <div class="ai-plan-actions">
                                        <button class="toolbar-btn" id="ai-view-diff-btn" type="button">
                                            <i class="fas fa-code-compare"></i> View Diff
                                        </button>
                                        <button class="toolbar-btn primary" id="ai-apply-btn" type="button" disabled>
                                            <i class="fas fa-check"></i> Apply
                                        </button>
                                        <button class="toolbar-btn" id="ai-clear-plan-btn" type="button">
                                            <i class="fas fa-xmark"></i> Clear
                                        </button>
                                    </div>
                                </div>

                                <form class="ai-composer" id="ai-form">
                                    <textarea class="ai-textarea" id="ai-prompt-input" placeholder="Ask the AI to create or update this app. Example: Create a small dashboard with a KPI card and recent activity list."></textarea>
                                    <div class="ai-composer-actions">
                                        <div class="ai-composer-meta">
                                            <button class="toolbar-btn" id="pick-preview-element-btn" type="button">
                                                <i class="fas fa-arrow-pointer"></i> Pick Element
                                            </button>
                                            <button class="toolbar-btn" id="open-ai-target-modal-btn" type="button">
                                                <i class="fas fa-crosshairs"></i> Target
                                            </button>
                                            <span class="ai-helper-text ai-target-summary" id="ai-selection-hint">Current file context is included when a file is open.</span>
                                        </div>
                                        <button class="toolbar-btn primary" id="ai-send-btn" type="submit">
                                            <i class="fas fa-paper-plane"></i> Ask AI
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </section>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Validation bar (bottom) -->
            <div class="validation-bar" id="validation-box"></div>
        </main>
    </div>

    <!-- Add Workspace Modal -->
    <div class="modal-backdrop" id="workspace-modal">
        <div class="modal">
            <div class="modal-header">
                <h2><i class="fas fa-plus"></i> Add Workspace</h2>
                <button class="modal-close" type="button" id="close-workspace-modal-btn">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <div class="form-stack">
                    <div>
                        <label class="form-label">Mode</label>
                        <div class="mode-toggle" id="workspace-modal-mode-toggle">
                            <label class="mode-option active" data-workspace-modal-mode-option="create">
                                <input type="radio" name="workspaceModalMode" value="create" checked>
                                <span class="mode-option-title"><i class="fas fa-sparkles" style="margin-right:6px; opacity:0.8;"></i> Create</span>
                                <span class="mode-option-copy">Start a new workspace with a generated Doki app scaffold.</span>
                            </label>
                            <label class="mode-option" data-workspace-modal-mode-option="import">
                                <input type="radio" name="workspaceModalMode" value="import">
                                <span class="mode-option-title"><i class="fas fa-code-branch" style="margin-right:6px; opacity:0.8;"></i> Import</span>
                                <span class="mode-option-copy">Clone a repository and discover any Doki apps inside it.</span>
                            </label>
                        </div>
                    </div>

                    <div class="modal-panel" id="workspace-create-panel">
                        <?php if ($studioAiEnabled): ?>
                            <div class="form-stack" style="margin-bottom: 20px;">
                                <p class="modal-form-subtitle" style="margin-bottom: 0;">Describe the app you want and let AI create the initial workspace for you.</p>
                            <div>
                                <label class="form-label" for="starter-ai-prompt">App Request</label>
                                <textarea class="form-input" id="starter-ai-prompt" rows="4" placeholder="Create a notes board that lets me save, edit, and search notes in app-local storage."></textarea>
                            </div>
                            <div class="form-row">
                                <div>
                                    <label class="form-label" for="starter-ai-provider-select">Provider</label>
                                    <select class="form-select" id="starter-ai-provider-select"></select>
                                </div>
                                <div>
                                    <label class="form-label" for="starter-ai-model">Model</label>
                                    <input class="form-input" id="starter-ai-model" list="starter-ai-model-options" placeholder="Auto-select best available model">
                                    <datalist id="starter-ai-model-options"></datalist>
                                </div>
                            </div>
                            <p class="form-hint" id="starter-ai-provider-hint"></p>
                            <div class="form-row">
                                <div>
                                    <button type="button" class="btn btn-primary" id="starter-ai-create-btn">
                                        <i class="fas fa-paper-plane"></i>
                                        Ask AI
                                        </button>
                                    </div>
                                    <div style="align-self:center;">
                                        <p class="form-hint" id="starter-ai-status" style="margin:0;"></p>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                        <p class="modal-form-subtitle">Generate a minimal Doki app scaffold inside a writable workspace.</p>
                        <form class="form-stack" id="starter-form">
                            <div>
                                <label class="form-label">App Setup</label>
                                <div class="mode-toggle" id="starter-mode-toggle">
                                    <label class="mode-option active" data-mode-option="simple">
                                        <input type="radio" name="starterMode" value="simple" checked>
                                        <span class="mode-option-title">Simple</span>
                                        <span class="mode-option-copy">Just the basics: name, color, and icon.</span>
                                    </label>
                                    <label class="mode-option" data-mode-option="advanced">
                                        <input type="radio" name="starterMode" value="advanced">
                                        <span class="mode-option-title">Advanced</span>
                                        <span class="mode-option-copy">Tune the app ID, description, and custom icon class.</span>
                                    </label>
                                </div>
                            </div>
                            <div>
                                <label class="form-label" for="starter-name">Workspace Name</label>
                                <input class="form-input" id="starter-name" name="name" required placeholder="My Internal Tool">
                                <p class="form-hint">App ID and display name are generated from this unless you switch to advanced mode.</p>
                            </div>
                            <div>
                                <label class="form-label" for="starter-color">Color</label>
                                <div class="color-picker-row">
                                    <input class="color-picker-input" type="color" id="starter-color" name="color" value="#2563eb">
                                    <div>
                                        <div class="color-value" id="starter-color-value">#2563EB</div>
                                        <p class="form-hint">Used in the app tile and header.</p>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <label class="form-label">Glyph</label>
                                <input type="hidden" id="starter-icon" name="icon" value="fa-sparkles">
                                <div class="icon-preview-chip">
                                    <span class="icon-preview-glyph">
                                        <i class="fas fa-sparkles" id="starter-icon-preview-glyph"></i>
                                    </span>
                                    <div>
                                        <div class="icon-preview-title" id="starter-icon-preview-label">Sparkles</div>
                                        <p class="form-hint" id="starter-icon-preview-value">fa-sparkles</p>
                                    </div>
                                </div>
                                <div class="icon-picker" id="starter-icon-picker">
                                    <?php foreach ($starterIconOptions as $option): ?>
                                        <button
                                            type="button"
                                            class="icon-choice"
                                            data-icon="<?= htmlspecialchars($option['icon']) ?>"
                                            data-label="<?= htmlspecialchars($option['label']) ?>"
                                            title="<?= htmlspecialchars($option['label']) ?>"
                                            aria-pressed="false"
                                        >
                                            <i class="fas <?= htmlspecialchars($option['icon']) ?>"></i>
                                            <span><?= htmlspecialchars($option['label']) ?></span>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="advanced-fields hidden" id="starter-advanced-fields">
                                <div class="form-row">
                                    <div>
                                        <label class="form-label" for="starter-app-id">App ID</label>
                                        <input class="form-input" id="starter-app-id" name="appId" placeholder="my-internal-tool">
                                    </div>
                                    <div>
                                        <label class="form-label" for="starter-app-name">App Name</label>
                                        <input class="form-input" id="starter-app-name" name="appName" placeholder="My Internal Tool">
                                    </div>
                                </div>
                                <div>
                                    <label class="form-label" for="starter-description">Description</label>
                                    <textarea class="form-input" id="starter-description" name="description" rows="3" placeholder="Short description"></textarea>
                                </div>
                                <div>
                                    <label class="form-label" for="starter-icon-custom">Custom Icon Class</label>
                                    <input class="form-input" id="starter-icon-custom" name="iconCustom" placeholder="fa-bolt">
                                    <p class="form-hint">Optional. Leave blank to use the picker above.</p>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-plus"></i>
                                Create Starter
                            </button>
                        </form>
                    </div>

                    <div class="modal-panel hidden" id="workspace-import-panel">
                        <p class="modal-form-subtitle">Clone an existing Git repository into a workspace and detect any Doki apps inside it.</p>
                        <form class="form-stack" id="repo-form">
                            <div>
                                <label class="form-label" for="repo-name">Workspace Name</label>
                                <input class="form-input" id="repo-name" name="name" required placeholder="Marketing App Workspace">
                            </div>
                            <div>
                                <label class="form-label" for="repo-existing">Existing Repository</label>
                                <select class="form-select" id="repo-existing" name="repositoryId">
                                    <option value="">Use manual repo URL</option>
                                    <?php foreach ($repositories as $repo): ?>
                                        <option value="<?= htmlspecialchars($repo['id']) ?>"><?= htmlspecialchars($repo['name']) ?> (<?= htmlspecialchars($repo['type']) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="form-label" for="repo-url">Repo URL</label>
                                <input class="form-input" id="repo-url" name="repoUrl" placeholder="https://github.com/org/repo.git">
                            </div>
                            <div class="form-row">
                                <div>
                                    <label class="form-label" for="repo-branch">Branch</label>
                                    <input class="form-input" id="repo-branch" name="branch" value="main" placeholder="main">
                                </div>
                                <div>
                                    <label class="form-label" for="repo-description">Description</label>
                                    <input class="form-input" id="repo-description" name="description" placeholder="Optional">
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary" id="repo-import-btn">
                                <i class="fas fa-code-branch"></i>
                                Import Repository
                            </button>
                            <p class="form-hint hidden" id="repo-import-status" style="text-align:center;">
                                <i class="fas fa-spinner fa-spin"></i> Cloning repository... This may take a moment.
                            </p>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($studioAiEnabled): ?>
        <div class="modal-backdrop" id="ai-provider-modal">
            <div class="modal">
                <div class="modal-header">
                    <h2><i class="fas fa-robot"></i> AI Providers</h2>
                    <button class="modal-close" type="button" id="close-ai-provider-modal-btn">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="modal-body">
                    <form class="form-stack" id="ai-provider-form">
                        <input type="hidden" id="ai-provider-id" name="id">

                        <div>
                            <label class="form-label" for="ai-provider-manage-select">Saved Provider</label>
                            <select class="form-select" id="ai-provider-manage-select">
                                <option value="">New provider</option>
                                <?php foreach ($aiProviders as $provider): ?>
                                    <option value="<?= htmlspecialchars($provider['id']) ?>"><?= htmlspecialchars($provider['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-row">
                            <div>
                                <label class="form-label" for="ai-provider-type">Provider</label>
                                <select class="form-select" id="ai-provider-type" name="providerPreset">
                                    <option value="ollama">Ollama</option>
                                    <option value="openai">OpenAI</option>
                                    <option value="claude">Claude</option>
                                    <option value="minimax">MiniMax</option>
                                    <option value="openrouter">OpenRouter</option>
                                    <option value="custom-openai">Custom OpenAI-compatible</option>
                                </select>
                                <input type="hidden" id="ai-provider-type-hidden" name="type" value="ollama">
                            </div>
                            <div>
                                <div class="form-label-row">
                                    <label class="form-label" for="ai-provider-name">Name</label>
                                    <label class="ai-inline-toggle" title="Use this provider whenever Studio AI needs a provider by default">
                                        <input type="checkbox" id="ai-provider-is-default" name="isDefault" value="1">
                                        <span>Default Provider</span>
                                    </label>
                                </div>
                                <input class="form-input" id="ai-provider-name" name="name" placeholder="Local Ollama" required>
                            </div>
                        </div>

                        <div>
                            <label class="form-label" for="ai-provider-base-url">API Base URL</label>
                            <input class="form-input" id="ai-provider-base-url" name="baseUrl" placeholder="http://host.docker.internal:11434">
                        </div>

                        <div>
                            <label class="form-label" for="ai-provider-api-key">API Key</label>
                            <input class="form-input" id="ai-provider-api-key" name="apiKey" type="password" placeholder="Optional for local Ollama">
                        </div>

                        <div class="ai-provider-advanced" id="ai-provider-openai-advanced" hidden>
                            <div class="form-row">
                                <div>
                                    <label class="form-label" for="ai-provider-chat-path">Chat Path</label>
                                    <input class="form-input" id="ai-provider-chat-path" placeholder="/chat/completions">
                                </div>
                                <div>
                                    <label class="form-label" for="ai-provider-models-path">Models Path</label>
                                    <input class="form-input" id="ai-provider-models-path" placeholder="/models">
                                </div>
                            </div>
                            <div>
                                <label class="form-label" for="ai-provider-request-params">Extra Query Params (JSON)</label>
                                <textarea class="form-input ai-provider-json" id="ai-provider-request-params" placeholder="{&#10;  &quot;reasoning_split&quot;: true&#10;}"></textarea>
                                <p class="form-hint">Merged into every OpenAI-compatible chat request. Leave the models path blank if your provider has no listing endpoint and set a default model manually.</p>
                            </div>
                        </div>

                        <div>
                            <div class="ai-field-row">
                                <div>
                                    <label class="form-label" for="ai-provider-default-model">Default Model</label>
                                    <input class="form-input" id="ai-provider-default-model" name="defaultModel" list="ai-provider-default-model-options" placeholder="Optional">
                                    <datalist id="ai-provider-default-model-options"></datalist>
                                </div>
                                <button class="btn btn-secondary" type="button" id="load-ai-provider-models-btn">Load Models</button>
                            </div>
                            <p class="form-hint">Loads models from the current provider settings so you can pick a default quickly.</p>
                        </div>

                        <div class="ai-provider-hint" id="ai-provider-hint">
                            Ollama works best when Doki can reach a host-level instance, commonly at <code>http://host.docker.internal:11434</code> from Docker.
                        </div>

                        <p class="form-hint" id="ai-provider-test-status"></p>

                        <div class="ai-provider-actions">
                            <button class="btn btn-secondary" type="button" id="delete-ai-provider-btn">Delete</button>
                            <button class="btn btn-secondary" type="button" id="test-ai-provider-btn">Test</button>
                            <button class="btn btn-primary" type="submit" id="save-ai-provider-btn">Save Provider</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="modal-backdrop" id="ai-diff-modal">
            <div class="modal ai-diff-modal">
                <div class="modal-header">
                    <h2><i class="fas fa-code-compare"></i> Proposed AI Changes</h2>
                    <button class="modal-close" type="button" id="close-ai-diff-modal-btn">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="ai-diff-layout">
                        <div class="ai-diff-files" id="ai-diff-files">
                            <div class="ai-diff-empty">No pending changes.</div>
                        </div>
                        <div class="ai-diff-view">
                            <div class="ai-diff-meta" id="ai-diff-meta">
                                <div class="ai-diff-meta-main">
                                    <div class="ai-diff-meta-path">No file selected</div>
                                    <div class="ai-diff-meta-copy">Select a changed file to inspect the proposed diff.</div>
                                </div>
                            </div>
                            <div class="ai-diff-content" id="ai-diff-content">
                                <div class="ai-diff-empty">Select a changed file to inspect the proposed diff.</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" type="button" id="close-ai-diff-footer-btn">Close</button>
                    <button class="btn btn-primary" type="button" id="ai-diff-apply-btn">Apply Changes</button>
                </div>
            </div>
        </div>

        <div class="modal-backdrop" id="ai-target-modal">
            <div class="modal ai-target-modal">
                <div class="modal-header">
                    <h2><i class="fas fa-crosshairs"></i> AI Target</h2>
                    <button class="modal-close" type="button" id="close-ai-target-modal-btn">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="ai-target-modal-layout">
                        <div class="ai-target-modal-sidebar">
                            <label class="ai-field">
                                <span class="ai-field-label">Choose File</span>
                                <input class="ai-input" id="ai-target-search-input" placeholder="Search files">
                            </label>
                            <div class="ai-target-file-list" id="ai-target-file-list">
                                <div class="ai-target-empty">Select a workspace to target a file.</div>
                            </div>
                        </div>
                        <div class="ai-target-modal-main">
                            <div class="ai-target-summary-box" id="ai-target-modal-summary">
                                <div class="ai-target-summary-title">No target selected</div>
                                <div>Pick a file, then optionally narrow the edit to a line range.</div>
                            </div>
                            <p class="ai-target-main-copy">Choose the file you want the AI to focus on. If you want to narrow the change to a specific part of that file, use <strong>Select Lines</strong> and highlight the exact lines in the next step.</p>
                            <div class="ai-target-modal-actions">
                                <button class="btn btn-secondary" type="button" id="ai-target-use-current-btn">Use Current File</button>
                                <button class="btn btn-secondary" type="button" id="ai-target-open-lines-btn">Select Lines</button>
                                <button class="btn btn-secondary" type="button" id="ai-target-modal-clear-btn">Clear</button>
                            </div>
                            <p class="ai-helper-text">If you do not select lines, AI will target the whole file. Line selection is optional.</p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" type="button" id="close-ai-target-footer-btn">Cancel</button>
                    <button class="btn btn-primary" type="button" id="ai-target-apply-btn">Use Target</button>
                </div>
            </div>
        </div>

        <div class="modal-backdrop modal-top-layer" id="ai-target-lines-modal">
            <div class="modal ai-target-lines-modal">
                <div class="modal-header">
                    <h2><i class="fas fa-highlighter"></i> Select Lines</h2>
                    <button class="modal-close" type="button" id="close-ai-target-lines-modal-btn">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="ai-target-editor">
                        <div class="ai-target-editor-header">
                            <span class="ai-target-editor-path" id="ai-target-editor-path">No file selected</span>
                            <span id="ai-target-editor-status">Read only</span>
                        </div>
                        <div class="ai-target-editor-surface">
                            <div class="ai-target-editor-empty" id="ai-target-editor-empty">Choose a file in the previous modal first.</div>
                            <div class="ai-target-editor-wrap hidden" id="ai-target-editor-wrap">
                                <div class="ai-target-editor-lines" id="ai-target-editor-lines">
                                    <div class="ai-target-editor-lines-highlight" id="ai-target-editor-lines-highlight"></div>
                                    <div class="ai-target-editor-lines-inner" id="ai-target-editor-lines-inner"></div>
                                </div>
                                <div class="ai-target-editor-code">
                                    <div class="ai-target-editor-code-highlight" id="ai-target-editor-code-highlight"></div>
                                    <textarea class="ai-target-editor-textarea" id="ai-target-editor-textarea" spellcheck="false" readonly wrap="off"></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                    <p class="ai-helper-text">Highlight the lines you want the AI to focus on, then click <strong>Use Highlighted Lines</strong>. If nothing is highlighted, no line range will be applied.</p>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" type="button" id="close-ai-target-lines-footer-btn">Cancel</button>
                    <button class="btn btn-primary" type="button" id="ai-target-use-selection-btn">Use Highlighted Lines</button>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <script>
        const studioApiBase = '../api/studio.php';
        const workspaces = <?= $workspacesJson ?: '[]' ?>;
        const aiProviders = <?= $aiProvidersJson ?: '[]' ?>;
        const studioAiEnabled = <?= $studioAiEnabled ? 'true' : 'false' ?>;
        const initialAiProvider = Array.isArray(aiProviders)
            ? (aiProviders.find(provider => Boolean(provider?.isDefault || Number(provider?.is_default || 0) === 1)) || aiProviders[0] || null)
            : null;

        const studioState = {
            workspaces,
            selectedWorkspaceId: null,
            displayMode: studioAiEnabled ? 'ai' : 'manual',
            fileTree: [],
            expandedDirs: new Set(),
            selectedFile: null,
            currentFileWorkspaceId: null,
            dirty: false,
            previewUrl: null,
            loadingFiles: false,
            savingFile: false,
            loadingWorkspaceId: null,
            filePanelOpen: true,
            installingWorkspace: false,
            publishingWorkspace: false,
            ai: {
                enabled: studioAiEnabled,
                panelOpen: studioAiEnabled,
                providers: Array.isArray(aiProviders) ? aiProviders : [],
                selectedProviderId: initialAiProvider?.id || '',
                allModelOptions: [],
                modelOptions: [],
                selectedModel: '',
                starterSelectedProviderId: initialAiProvider?.id || '',
                starterModelOptions: [],
                starterSelectedModel: '',
                starterLoadingModels: false,
                freeOnly: true,
                messages: [],
                pendingPlan: null,
                sending: false,
                activeChatJobId: null,
                chatPollTimer: null,
                applying: false,
                nextMessageId: 1,
                revertingMessageId: null,
                loadingModels: false,
                creatingWorkspace: false,
                activeCreateJobId: null,
                createPollTimer: null,
                pickerActive: false,
                target: {
                    path: '',
                    startLine: null,
                    endLine: null,
                    text: '',
                },
                domTarget: {
                    pagePath: '',
                    pageUrl: '',
                    selector: '',
                    tagName: '',
                    role: '',
                    label: '',
                    text: '',
                    html: '',
                },
                targetDraft: {
                    path: '',
                    startLine: null,
                    endLine: null,
                    text: '',
                },
                targetViewer: {
                    workspaceId: '',
                    path: '',
                    content: '',
                    isBinary: false,
                    loading: false,
                    error: '',
                    requestId: 0,
                },
                providerFormBaseline: null,
                providerModalModelOptions: [],
                providerModalLoadingModels: false,
                diffState: {
                    loading: false,
                    files: [],
                    selectedPath: '',
                },
            },
        };

        // ── DOM refs ──
        const $ = (id) => document.getElementById(id);

        const workspaceSelect = $('workspace-select');
        const studioBody = $('studio-body');
        const toolbarPreviewBadge = $('toolbar-preview-badge');
        const studioEmpty = $('studio-empty');
        const filePanel = $('file-panel');
        const editorPreviewSplit = $('editor-preview-split');
        const fileTreeEl = $('file-tree');
        const currentFilePathEl = $('current-file-path');
        const editorTextarea = $('editor-textarea');
        const editorTextareaWrap = $('editor-textarea-wrap');
        const editorPlaceholder = $('editor-placeholder');
        const binaryPlaceholder = $('binary-placeholder');
        const editorLineNumbers = $('editor-line-numbers');
        const saveFileBtn = $('save-file-btn');
        const deleteFileBtn = $('delete-file-btn');
        const saveStateEl = $('save-state');
        const previewFrame = $('preview-frame');
        const previewPlaceholder = $('preview-placeholder');
        const previewStatusText = $('preview-status-text');
        const previewAddressBar = $('preview-address-bar');
        const previewPageInput = $('preview-page-input');
        const validationBox = $('validation-box');
        const appSwitcherBar = $('app-switcher-bar');
        const appSwitcherSelect = $('app-switcher-select');
        const appSwitcherHint = $('app-switcher-hint');
        const starterAdvancedFields = $('starter-advanced-fields');
        const starterColorInput = $('starter-color');
        const starterColorValue = $('starter-color-value');
        const starterIconInput = $('starter-icon');
        const starterIconPreviewGlyph = $('starter-icon-preview-glyph');
        const starterIconPreviewLabel = $('starter-icon-preview-label');
        const starterIconPreviewValue = $('starter-icon-preview-value');
        const starterIconCustomInput = $('starter-icon-custom');
        const starterAiPromptInput = $('starter-ai-prompt');
        const starterAiProviderSelect = $('starter-ai-provider-select');
        const starterAiModelInput = $('starter-ai-model');
        const starterAiModelOptions = $('starter-ai-model-options');
        const starterAiCreateBtn = $('starter-ai-create-btn');
        const starterAiProviderHint = $('starter-ai-provider-hint');
        const starterAiStatus = $('starter-ai-status');
        const workspaceModal = $('workspace-modal');
        const workspaceCreatePanel = $('workspace-create-panel');
        const workspaceImportPanel = $('workspace-import-panel');
        const toggleFilesBtn = $('toggle-files-btn');
        const displayModeManualBtn = $('display-mode-manual-btn');
        const displayModeAiBtn = $('display-mode-ai-btn');
        const toolbarInstallAppBtn = $('toolbar-install-app-btn');
        const toolbarInstallAppLabel = $('toolbar-install-app-label');
        const toolbarPublishAppBtn = $('toolbar-publish-app-btn');
        const aiPane = $('ai-pane');
        const aiStatusText = $('ai-status-text');
        const aiProviderSelect = $('ai-provider-select');
        const aiRefreshModelsBtn = $('ai-refresh-models-btn');
        const aiModelInput = $('ai-model-input');
        const aiModelOptions = $('ai-model-options');
        const aiModelFreeOnlyInput = $('ai-model-free-only');
        const aiMessages = $('ai-messages');
        const aiEmptyCopy = $('ai-empty-copy');
        const aiPlanBox = $('ai-plan-box');
        const aiPlanSummary = $('ai-plan-summary');
        const aiPlanList = $('ai-plan-list');
        const aiPlanNotes = $('ai-plan-notes');
        const aiPlanLintBox = $('ai-plan-lint-box');
        const aiPlanLintList = $('ai-plan-lint-list');
        const aiViewDiffBtn = $('ai-view-diff-btn');
        const aiApplyBtn = $('ai-apply-btn');
        const aiClearPlanBtn = $('ai-clear-plan-btn');
        const aiForm = $('ai-form');
        const aiPromptInput = $('ai-prompt-input');
        const aiSelectionHint = $('ai-selection-hint');
        const aiContextText = $('ai-context-text');
        const pickPreviewElementBtn = $('pick-preview-element-btn');
        const openAiTargetModalBtn = $('open-ai-target-modal-btn');
        const aiTargetModal = $('ai-target-modal');
        const aiTargetSearchInput = $('ai-target-search-input');
        const aiTargetFileList = $('ai-target-file-list');
        const aiTargetModalSummary = $('ai-target-modal-summary');
        const aiTargetOpenLinesBtn = $('ai-target-open-lines-btn');
        const aiTargetLinesModal = $('ai-target-lines-modal');
        const aiTargetEditorPath = $('ai-target-editor-path');
        const aiTargetEditorStatus = $('ai-target-editor-status');
        const aiTargetEditorEmpty = $('ai-target-editor-empty');
        const aiTargetEditorWrap = $('ai-target-editor-wrap');
        const aiTargetEditorLines = $('ai-target-editor-lines');
        const aiTargetEditorLinesInner = $('ai-target-editor-lines-inner');
        const aiTargetEditorLinesHighlight = $('ai-target-editor-lines-highlight');
        const aiTargetEditorCodeHighlight = $('ai-target-editor-code-highlight');
        const aiTargetEditorTextarea = $('ai-target-editor-textarea');
        const aiTargetUseCurrentBtn = $('ai-target-use-current-btn');
        const aiTargetUseSelectionBtn = $('ai-target-use-selection-btn');
        const aiTargetModalClearBtn = $('ai-target-modal-clear-btn');
        const closeAiTargetModalBtn = $('close-ai-target-modal-btn');
        const closeAiTargetFooterBtn = $('close-ai-target-footer-btn');
        const closeAiTargetLinesModalBtn = $('close-ai-target-lines-modal-btn');
        const closeAiTargetLinesFooterBtn = $('close-ai-target-lines-footer-btn');
        const aiTargetApplyBtn = $('ai-target-apply-btn');
        const aiProviderModal = $('ai-provider-modal');
        const aiProviderForm = $('ai-provider-form');
        const aiProviderManageSelect = $('ai-provider-manage-select');
        const aiProviderIdInput = $('ai-provider-id');
        const aiProviderTypeInput = $('ai-provider-type');
        const aiProviderTypeHiddenInput = $('ai-provider-type-hidden');
        const aiProviderNameInput = $('ai-provider-name');
        const aiProviderBaseUrlInput = $('ai-provider-base-url');
        const aiProviderApiKeyInput = $('ai-provider-api-key');
        const aiProviderOpenAiAdvanced = $('ai-provider-openai-advanced');
        const aiProviderChatPathInput = $('ai-provider-chat-path');
        const aiProviderModelsPathInput = $('ai-provider-models-path');
        const aiProviderRequestParamsInput = $('ai-provider-request-params');
        const aiProviderDefaultModelInput = $('ai-provider-default-model');
        const aiProviderIsDefaultInput = $('ai-provider-is-default');
        const aiProviderDefaultModelOptions = $('ai-provider-default-model-options');
        const aiProviderHint = $('ai-provider-hint');
        const aiProviderTestStatus = $('ai-provider-test-status');
        const deleteAiProviderBtn = $('delete-ai-provider-btn');
        const testAiProviderBtn = $('test-ai-provider-btn');
        const loadAiProviderModelsBtn = $('load-ai-provider-models-btn');
        const aiDiffModal = $('ai-diff-modal');
        const aiDiffFiles = $('ai-diff-files');
        const aiDiffMeta = $('ai-diff-meta');
        const aiDiffContent = $('ai-diff-content');
        const aiDiffApplyBtn = $('ai-diff-apply-btn');
        let previewElementPickerCleanup = null;

        // ── Helpers ──
        function getWorkspace(id) {
            return studioState.workspaces.find(w => w.id === id) || null;
        }

        function upsertWorkspace(updated) {
            const i = studioState.workspaces.findIndex(w => w.id === updated.id);
            if (i >= 0) studioState.workspaces[i] = { ...studioState.workspaces[i], ...updated };
            else studioState.workspaces.push(updated);
        }

        function currentWorkspace() {
            return getWorkspace(studioState.selectedWorkspaceId);
        }

        async function postStudio(action, payload) {
            const response = await fetch(`${studioApiBase}?action=${encodeURIComponent(action)}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload || {})
            });
            const data = await response.json().catch(() => ({ success: false, error: 'Invalid server response' }));
            if (!response.ok || !data.success) throw new Error(data.error || 'Request failed');
            return data;
        }

        function btnLoading(btn, loading = true) {
            if (!btn) return;
            btn.classList.toggle('is-loading', loading);
            btn.disabled = loading;
        }

        function escapeHtml(v) {
            return String(v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
        }

        function escapeJs(v) {
            return String(v).replace(/\\/g,'\\\\').replace(/'/g,"\\'");
        }

        function parsePositiveInteger(v) {
            const parsed = Number.parseInt(String(v || '').trim(), 10);
            return Number.isFinite(parsed) && parsed > 0 ? parsed : null;
        }

        function slugify(v) {
            return String(v||'').toLowerCase().trim().replace(/[^a-z0-9]+/g,'-').replace(/^-+|-+$/g,'');
        }

        function cacheBustedUrl(url) {
            const sep = url.includes('?') ? '&' : '?';
            return `${url}${sep}embed=1&t=${Date.now()}`;
        }

        function getWorkspaceInstallActionLabel(workspace) {
            return workspace?.linked_installed_app_id ? 'Update App' : 'Install App';
        }

        function setSaveState(state, message) {
            saveStateEl.textContent = message;
            saveStateEl.classList.remove('dirty','saved');
            if (state) saveStateEl.classList.add(state);
        }

        function confirmDiscardChanges() {
            return !studioState.dirty || window.confirm('Discard unsaved changes?');
        }

        function getAiProvider(id) {
            return studioState.ai.providers.find(provider => provider.id === id) || null;
        }

        function isAiProviderDefault(provider) {
            return !!provider && (
                provider.isDefault === true
                || Number(provider.is_default || 0) === 1
            );
        }

        function getDefaultAiProvider() {
            return studioState.ai.providers.find(provider => isAiProviderDefault(provider)) || studioState.ai.providers[0] || null;
        }

        function createAiMessage(role, content, extra = {}) {
            return {
                id: `ai-msg-${studioState.ai.nextMessageId++}`,
                role,
                content,
                ...extra,
            };
        }

        function appendAiMessage(role, content, extra = {}) {
            const message = createAiMessage(role, content, extra);
            studioState.ai.messages.push(message);
            return message;
        }

        function getAiMessage(messageId) {
            return studioState.ai.messages.find(message => String(message.id || '') === String(messageId || '')) || null;
        }

        async function getStudio(action, params = {}) {
            const search = new URLSearchParams({ action, ...params });
            const response = await fetch(`${studioApiBase}?${search.toString()}`);
            const data = await response.json().catch(() => ({ success: false, error: 'Invalid server response' }));
            if (!response.ok || !data.success) throw new Error(data.error || 'Request failed');
            return data;
        }

        function resetAiConversation(clearMessages = false) {
            if (clearMessages) {
                studioState.ai.messages = [];
                studioState.ai.nextMessageId = 1;
                studioState.ai.revertingMessageId = null;
            }
            studioState.ai.pendingPlan = null;
            resetAiDiffState();
            closeAiDiffModal();
            renderAiMessages();
            renderAiPlan();
        }

        function createEmptyAiTarget() {
            return {
                path: '',
                startLine: null,
                endLine: null,
                text: '',
            };
        }

        function createEmptyAiDomTarget() {
            return {
                pagePath: '',
                pageUrl: '',
                selector: '',
                tagName: '',
                role: '',
                label: '',
                text: '',
                html: '',
            };
        }

        function hasAiDomTarget() {
            return !!String(studioState.ai.domTarget?.selector || '').trim();
        }

        function describeAiDomTarget(target = studioState.ai.domTarget) {
            const tag = String(target?.tagName || 'element').toLowerCase();
            const primary = String(target?.label || target?.text || '').trim();
            const selector = String(target?.selector || '').trim();
            const pagePath = String(target?.pagePath || '').trim();

            let description = tag;
            if (primary) {
                description += ` "${primary.length > 48 ? `${primary.slice(0, 45)}...` : primary}"`;
            } else if (selector) {
                description += ` ${selector.length > 56 ? `${selector.slice(0, 53)}...` : selector}`;
            }

            if (pagePath) {
                description += ` on ${pagePath}`;
            }

            return description;
        }

        function clearAiDomTarget(options = {}) {
            studioState.ai.domTarget = createEmptyAiDomTarget();

            if (studioState.ai.pickerActive) {
                stopPreviewElementPicker();
            } else {
                const doc = getPreviewFrameDocument();
                if (doc) clearPreviewPickerMarkers(doc);
            }

            renderAiMessages();
            renderAiSelectionHint();
            renderAiTargetModalSummary();

            if (!options.silent) {
                window.Toast?.success('Selected element cleared');
            }
        }

        function updatePreviewElementPickerButton() {
            if (!pickPreviewElementBtn) return;
            if (studioState.ai.pickerActive) {
                pickPreviewElementBtn.classList.add('active');
                pickPreviewElementBtn.innerHTML = '<i class="fas fa-arrow-pointer"></i> Picking…';
                pickPreviewElementBtn.title = 'Click an element in the live preview to target it';
                return;
            }

            pickPreviewElementBtn.classList.remove('active');
            pickPreviewElementBtn.innerHTML = hasAiDomTarget()
                ? '<i class="fas fa-arrow-pointer"></i> Repick Element'
                : '<i class="fas fa-arrow-pointer"></i> Pick Element';
            pickPreviewElementBtn.title = hasAiDomTarget()
                ? 'Pick a different element from the live preview'
                : 'Pick an element from the live preview';
        }

        function resetAiTarget() {
            studioState.ai.target = createEmptyAiTarget();
            studioState.ai.targetDraft = createEmptyAiTarget();
            studioState.ai.domTarget = createEmptyAiDomTarget();
            studioState.ai.targetViewer = {
                workspaceId: '',
                path: '',
                content: '',
                isBinary: false,
                loading: false,
                error: '',
                requestId: 0,
            };
            if (aiTargetSearchInput) aiTargetSearchInput.value = '';
            stopPreviewElementPicker();
            const previewDoc = getPreviewFrameDocument();
            if (previewDoc) clearPreviewPickerMarkers(previewDoc);
            closeAiTargetLinesModal();
            renderAiTargetEditor();
            renderAiTargetModalSummary();
            renderAiTargetModalFileList();
            renderAiSelectionHint();
            renderAiMessages();
        }

        function setAiStatus(message) {
            if (aiStatusText) aiStatusText.textContent = message;
        }

        function getAiProviderFormSnapshot() {
            return {
                id: aiProviderIdInput?.value.trim() || '',
                providerPreset: aiProviderTypeInput?.value || 'ollama',
                type: aiProviderTypeHiddenInput?.value || 'ollama',
                name: aiProviderNameInput?.value.trim() || '',
                baseUrl: aiProviderBaseUrlInput?.value.trim() || '',
                apiKey: aiProviderApiKeyInput?.value || '',
                chatPath: aiProviderChatPathInput?.value.trim() || '',
                modelsPath: aiProviderModelsPathInput?.value.trim() || '',
                requestParams: aiProviderRequestParamsInput?.value.trim() || '',
                defaultModel: aiProviderDefaultModelInput?.value.trim() || '',
                isDefault: !!aiProviderIsDefaultInput?.checked,
            };
        }

        function markAiProviderFormBaseline() {
            studioState.ai.providerFormBaseline = getAiProviderFormSnapshot();
        }

        function aiProviderFormIsDirty() {
            const baseline = studioState.ai.providerFormBaseline;
            if (!baseline) return false;
            return JSON.stringify(getAiProviderFormSnapshot()) !== JSON.stringify(baseline);
        }

        function updateAiProviderActionButtons() {
            const hasSavedProvider = !!(aiProviderIdInput?.value.trim());
            const isDirty = aiProviderFormIsDirty();

            if (deleteAiProviderBtn) {
                deleteAiProviderBtn.disabled = !hasSavedProvider;
                deleteAiProviderBtn.setAttribute('aria-disabled', deleteAiProviderBtn.disabled ? 'true' : 'false');
                deleteAiProviderBtn.title = hasSavedProvider ? 'Delete this saved provider' : 'No saved provider to delete';
            }
            if (testAiProviderBtn) {
                testAiProviderBtn.disabled = !hasSavedProvider || isDirty;
                testAiProviderBtn.setAttribute('aria-disabled', testAiProviderBtn.disabled ? 'true' : 'false');
                testAiProviderBtn.title = !hasSavedProvider
                    ? 'Save the provider before testing'
                    : (isDirty ? 'Save provider changes before testing' : 'Test provider connectivity');
            }

            if (aiProviderTestStatus && isDirty) {
                aiProviderTestStatus.textContent = 'Save provider changes before testing this configuration.';
            } else if (aiProviderTestStatus && !hasSavedProvider) {
                aiProviderTestStatus.textContent = '';
            }
        }

        function aiProviderDefaults(preset = 'ollama') {
            if (preset === 'openai') {
                return {
                    preset,
                    type: 'openai-compatible',
                    name: 'OpenAI',
                    baseUrl: 'https://api.openai.com/v1',
                    apiKeyPlaceholder: 'Required',
                    hint: 'OpenAI uses the standard API base URL at https://api.openai.com/v1 and Studio will use the native Responses API for OpenAI models.',
                    showOpenAiAdvanced: false,
                    chatPath: '/chat/completions',
                    modelsPath: '/models',
                };
            }

            if (preset === 'claude') {
                return {
                    preset,
                    type: 'openai-compatible',
                    name: 'Claude',
                    baseUrl: 'https://api.anthropic.com/v1',
                    apiKeyPlaceholder: 'Required',
                    hint: 'Claude uses Anthropic\'s Messages API at https://api.anthropic.com/v1 with x-api-key authentication.',
                    showOpenAiAdvanced: false,
                    chatPath: '',
                    modelsPath: '',
                };
            }

            if (preset === 'minimax') {
                return {
                    preset,
                    type: 'openai-compatible',
                    name: 'MiniMax',
                    baseUrl: 'https://api.minimax.io/v1',
                    apiKeyPlaceholder: 'Required',
                    hint: 'MiniMax exposes an OpenAI-compatible base URL at https://api.minimax.io/v1. You can add optional request params such as {"reasoning_split": true}.',
                    showOpenAiAdvanced: true,
                    chatPath: '/chat/completions',
                    modelsPath: '/models',
                };
            }

            if (preset === 'openrouter') {
                return {
                    preset,
                    type: 'openai-compatible',
                    name: 'OpenRouter',
                    baseUrl: 'https://openrouter.ai/api/v1',
                    apiKeyPlaceholder: 'Required',
                    hint: 'OpenRouter uses an OpenAI-compatible API at https://openrouter.ai/api/v1.',
                    showOpenAiAdvanced: false,
                    chatPath: '/chat/completions',
                    modelsPath: '/models/user',
                };
            }

            if (preset === 'custom-openai') {
                return {
                    preset,
                    type: 'openai-compatible',
                    name: 'Custom OpenAI-Compatible',
                    baseUrl: '',
                    apiKeyPlaceholder: 'Usually required',
                    hint: 'Use this for OpenAI-compatible gateways, proxies, or self-hosted endpoints.',
                    showOpenAiAdvanced: true,
                    chatPath: '/chat/completions',
                    modelsPath: '/models',
                };
            }

            return {
                preset: 'ollama',
                type: 'ollama',
                name: 'Local Ollama',
                baseUrl: 'http://host.docker.internal:11434',
                apiKeyPlaceholder: 'Optional for local Ollama',
                hint: 'Ollama works best when Doki can reach a host-level instance, commonly at http://host.docker.internal:11434 from Docker.',
                showOpenAiAdvanced: false,
                chatPath: '',
                modelsPath: '',
            };
        }

        function inferAiProviderPreset(provider = null) {
            if (!provider) return 'ollama';

            const metadataPreset = provider?.metadata?.preset || '';
            if (metadataPreset) return metadataPreset;

            if ((provider.type || '') === 'ollama') return 'ollama';

            const baseUrl = String(provider.baseUrl || provider.base_url || '').toLowerCase();
            if (baseUrl.includes('api.anthropic.com')) return 'claude';
            if (baseUrl.includes('api.minimax.io')) return 'minimax';
            if (baseUrl.includes('openrouter.ai')) return 'openrouter';
            if (baseUrl.includes('api.openai.com')) return 'openai';
            return 'custom-openai';
        }

        function aiProviderKindLabel(provider) {
            const preset = inferAiProviderPreset(provider);
            if (preset === 'openai') return 'OpenAI';
            if (preset === 'claude') return 'Claude';
            if (preset === 'minimax') return 'MiniMax';
            if (preset === 'openrouter') return 'OpenRouter';
            if (preset === 'custom-openai') return 'OpenAI-compatible';
            return 'Ollama';
        }

        function aiProviderUsesOpenAiAdvanced(preset) {
            return ['custom-openai', 'minimax'].includes(preset);
        }

        function normalizeAiProviderApiPath(value) {
            const trimmed = String(value || '').trim();
            if (!trimmed) return '';
            return trimmed.startsWith('/') ? trimmed : `/${trimmed}`;
        }

        function formatAiProviderJsonValue(value) {
            if (!value || typeof value !== 'object' || Array.isArray(value) || !Object.keys(value).length) {
                return '';
            }

            try {
                return JSON.stringify(value, null, 2);
            } catch (error) {
                return '';
            }
        }

        function toggleAiProviderAdvancedFields(preset) {
            if (!aiProviderOpenAiAdvanced) return;
            aiProviderOpenAiAdvanced.hidden = !aiProviderUsesOpenAiAdvanced(preset);
        }

        function buildAiProviderMetadataFromForm() {
            const preset = aiProviderTypeInput?.value || 'ollama';
            const defaults = aiProviderDefaults(preset);
            const metadata = { preset };

            if (preset === 'claude') {
                metadata.anthropicVersion = '2023-06-01';
            }

            if (aiProviderUsesOpenAiAdvanced(preset)) {
                const paths = {
                    chat: normalizeAiProviderApiPath(aiProviderChatPathInput?.value || defaults.chatPath || '/chat/completions'),
                    models: (() => {
                        const rawValue = aiProviderModelsPathInput?.value ?? defaults.modelsPath ?? '';
                        const trimmed = String(rawValue).trim();
                        return trimmed === '' ? '' : normalizeAiProviderApiPath(trimmed);
                    })(),
                };
                metadata.paths = paths;

                const rawParams = String(aiProviderRequestParamsInput?.value || '').trim();
                if (rawParams !== '') {
                    let parsed;
                    try {
                        parsed = JSON.parse(rawParams);
                    } catch (error) {
                        throw new Error('Extra query params must be valid JSON');
                    }

                    if (!parsed || Array.isArray(parsed) || typeof parsed !== 'object') {
                        throw new Error('Extra query params must be a JSON object');
                    }

                    metadata.requestParams = parsed;
                }
            }

            return metadata;
        }

        function providerUsesOpenRouter(provider = null) {
            return inferAiProviderPreset(provider) === 'openrouter';
        }

        function filterOpenRouterFreeModels(models, enabled) {
            if (!enabled) return Array.isArray(models) ? models : [];
            return (Array.isArray(models) ? models : []).filter(model => {
                const haystack = `${model.id || ''} ${model.label || ''} ${model.name || ''}`.toLowerCase();
                return haystack.includes('free');
            });
        }

        function currentAiProvider() {
            const providerId = aiProviderSelect?.value || studioState.ai.selectedProviderId || getDefaultAiProvider()?.id || '';
            return getAiProvider(providerId);
        }

        function currentStarterAiProvider() {
            const providerId = starterAiProviderSelect?.value || studioState.ai.starterSelectedProviderId || getDefaultAiProvider()?.id || '';
            return getAiProvider(providerId);
        }

        function currentAiCreationProvider() {
            return currentStarterAiProvider() || getDefaultAiProvider();
        }

        function renderStarterAiModelOptions() {
            if (!studioState.ai.enabled || !starterAiModelOptions) return;
            starterAiModelOptions.innerHTML = (studioState.ai.starterModelOptions || []).map(model => {
                return `<option value="${escapeHtml(model.id)}">${escapeHtml(model.label || model.id)}</option>`;
            }).join('');
        }

        function renderStarterAiProviders() {
            if (!studioState.ai.enabled || !starterAiProviderSelect) return;

            const providers = studioState.ai.providers;
            if (!providers.length) {
                studioState.ai.starterSelectedProviderId = '';
                studioState.ai.starterSelectedModel = '';
                studioState.ai.starterModelOptions = [];
                starterAiProviderSelect.innerHTML = '<option value="">No providers configured</option>';
                starterAiProviderSelect.disabled = true;
                if (starterAiModelInput) {
                    starterAiModelInput.disabled = true;
                    starterAiModelInput.value = '';
                }
                renderStarterAiModelOptions();
                updateStarterAiState();
                return;
            }

            starterAiProviderSelect.disabled = false;
            if (starterAiModelInput) starterAiModelInput.disabled = false;

            if (!getAiProvider(studioState.ai.starterSelectedProviderId)) {
                studioState.ai.starterSelectedProviderId = getDefaultAiProvider()?.id || providers[0].id;
                studioState.ai.starterSelectedModel = '';
                if (starterAiModelInput) starterAiModelInput.value = '';
            }

            starterAiProviderSelect.innerHTML = providers.map(provider => {
                return `<option value="${escapeHtml(provider.id)}">${escapeHtml(provider.name)}${isAiProviderDefault(provider) ? ' (Default)' : ''}</option>`;
            }).join('');
            starterAiProviderSelect.value = studioState.ai.starterSelectedProviderId || getDefaultAiProvider()?.id || providers[0].id;

            const selectedProvider = currentStarterAiProvider();
            if (starterAiModelInput && !studioState.ai.starterLoadingModels && !starterAiModelInput.value.trim()) {
                const fallbackModel = studioState.ai.starterSelectedModel
                    || selectedProvider?.defaultModel
                    || selectedProvider?.default_model
                    || '';
                if (fallbackModel) starterAiModelInput.value = fallbackModel;
            }

            updateStarterAiState();
        }

        function clearAiChatPoll() {
            if (studioState.ai.chatPollTimer) {
                clearTimeout(studioState.ai.chatPollTimer);
                studioState.ai.chatPollTimer = null;
            }
        }

        function clearAiCreatePoll() {
            if (studioState.ai.createPollTimer) {
                clearTimeout(studioState.ai.createPollTimer);
                studioState.ai.createPollTimer = null;
            }
        }

        function updateStarterAiState() {
            if (!starterAiCreateBtn) return;

            const provider = studioState.ai.enabled ? currentAiCreationProvider() : null;
            starterAiCreateBtn.disabled = !provider || studioState.ai.creatingWorkspace;

            if (!starterAiProviderHint) return;
            if (!studioState.ai.enabled) {
                starterAiProviderHint.textContent = 'App Studio AI is not enabled for this account.';
                return;
            }
            if (!provider) {
                starterAiProviderHint.textContent = 'Add an AI provider first to create a workspace from a prompt.';
                return;
            }

            if (studioState.ai.starterLoadingModels) {
                starterAiProviderHint.textContent = `Loading models for ${provider.name}...`;
                return;
            }

            const chosenModel = (
                starterAiModelInput?.value
                || studioState.ai.starterSelectedModel
                || provider.defaultModel
                || provider.default_model
                || ''
            ).trim();

            starterAiProviderHint.textContent = chosenModel
                ? `Uses ${provider.name} with ${chosenModel}. The AI will create a starter workspace and replace the scaffold with generated files.`
                : `Uses ${provider.name}. If no model is selected, Doki will use the provider default or best available model.`;
        }

        function updateAiModelFilterState() {
            const provider = currentAiProvider();
            if (!aiModelFreeOnlyInput) return;

            const isOpenRouter = providerUsesOpenRouter(provider);
            aiModelFreeOnlyInput.disabled = !isOpenRouter;
            aiModelFreeOnlyInput.parentElement?.classList.toggle('disabled', !isOpenRouter);
            aiModelFreeOnlyInput.title = isOpenRouter
                ? "Only show OpenRouter models with 'free' in the name"
                : 'Free filtering is only used for OpenRouter providers';
        }

        function renderAiProviders() {
            if (!studioState.ai.enabled || !aiProviderSelect) return;

            const providers = studioState.ai.providers;
            if (!providers.length) {
                studioState.ai.selectedProviderId = '';
                studioState.ai.allModelOptions = [];
                studioState.ai.modelOptions = [];
                studioState.ai.selectedModel = '';
                aiProviderSelect.innerHTML = '<option value="">No providers configured</option>';
                aiProviderSelect.disabled = true;
                if (aiProviderManageSelect) aiProviderManageSelect.innerHTML = '<option value="">New provider</option>';
                if (aiEmptyCopy) aiEmptyCopy.textContent = 'Connect an AI provider to start building with prompts.';
                if (aiPromptInput) aiPromptInput.disabled = true;
                if (aiModelInput) {
                    aiModelInput.disabled = true;
                    aiModelInput.value = '';
                }
                if (aiRefreshModelsBtn) aiRefreshModelsBtn.disabled = true;
                updateAiModelFilterState();
                renderAiModelOptions();
                renderStarterAiProviders();
                setAiStatus('No provider');
                return;
            }

            aiProviderSelect.disabled = false;
            if (aiPromptInput) aiPromptInput.disabled = false;
            if (aiModelInput) aiModelInput.disabled = false;
            if (aiRefreshModelsBtn) aiRefreshModelsBtn.disabled = false;

            if (!getAiProvider(studioState.ai.selectedProviderId)) {
                studioState.ai.selectedProviderId = getDefaultAiProvider()?.id || providers[0].id;
                studioState.ai.selectedModel = '';
                if (aiModelInput) aiModelInput.value = '';
            }

            aiProviderSelect.innerHTML = providers.map(provider => {
                const providerLabel = isAiProviderDefault(provider)
                    ? `${aiProviderKindLabel(provider)}, default`
                    : aiProviderKindLabel(provider);
                return `<option value="${escapeHtml(provider.id)}">${escapeHtml(provider.name)} (${escapeHtml(providerLabel)})</option>`;
            }).join('');
            aiProviderSelect.value = studioState.ai.selectedProviderId || getDefaultAiProvider()?.id || providers[0].id;

            const selectedProvider = getAiProvider(aiProviderSelect.value);
            if (aiModelInput && !studioState.ai.loadingModels && !aiModelInput.value.trim()) {
                const fallbackModel = studioState.ai.selectedModel
                    || selectedProvider?.defaultModel
                    || selectedProvider?.default_model
                    || '';
                if (fallbackModel) aiModelInput.value = fallbackModel;
            }

            if (aiProviderManageSelect) {
                aiProviderManageSelect.innerHTML = ['<option value="">New provider</option>'].concat(
                    providers.map(provider => `<option value="${escapeHtml(provider.id)}">${escapeHtml(provider.name)}${isAiProviderDefault(provider) ? ' (Default)' : ''}</option>`)
                ).join('');
            }

            updateAiModelFilterState();
            renderStarterAiProviders();

            if (aiContextText) {
                aiContextText.textContent = currentWorkspace()
                    ? 'The AI only proposes repo-relative workspace file edits. Review them before applying.'
                    : 'Select a workspace first. The AI only edits files inside the active workspace.';
            }

            updateStarterAiState();
        }

        function collectWorkspaceFilePaths(items, into = []) {
            for (const item of items || []) {
                if (!item) continue;
                if (item.isDirectory || item.type === 'directory') {
                    collectWorkspaceFilePaths(item.children || [], into);
                    continue;
                }
                if (item.path) into.push(item.path);
            }
            return into;
        }

        function currentAiTargetEditorLineNumber(offset) {
            if (!aiTargetEditorTextarea) return 1;
            const safeOffset = Math.max(0, Math.min(Number(offset) || 0, aiTargetEditorTextarea.value.length));
            return aiTargetEditorTextarea.value.slice(0, safeOffset).split('\n').length;
        }

        function getAiTargetEditorMetrics() {
            if (!aiTargetEditorTextarea) {
                return { lineHeight: 19.2, paddingTop: 12 };
            }

            const styles = window.getComputedStyle(aiTargetEditorTextarea);
            return {
                lineHeight: parseFloat(styles.lineHeight) || 19.2,
                paddingTop: parseFloat(styles.paddingTop) || 12,
            };
        }

        function hideAiTargetSelectionVisuals() {
            if (aiTargetEditorLinesHighlight) aiTargetEditorLinesHighlight.style.display = 'none';
            if (aiTargetEditorCodeHighlight) aiTargetEditorCodeHighlight.style.display = 'none';
        }

        function syncAiTargetEditorScroll() {
            if (!aiTargetEditorTextarea) return;
            if (aiTargetEditorLinesInner) {
                aiTargetEditorLinesInner.style.transform = `translateY(${-aiTargetEditorTextarea.scrollTop}px)`;
            }
        }

        function renderAiTargetSelectionVisuals() {
            if (!aiTargetEditorTextarea || aiTargetEditorWrap?.classList.contains('hidden')) {
                hideAiTargetSelectionVisuals();
                return;
            }

            const selection = getAiTargetEditorSelectionRange();
            if (!selection) {
                hideAiTargetSelectionVisuals();
                return;
            }

            const { lineHeight, paddingTop } = getAiTargetEditorMetrics();
            const top = paddingTop + ((selection.startLine - 1) * lineHeight) - aiTargetEditorTextarea.scrollTop;
            const height = Math.max(lineHeight, ((selection.endLine - selection.startLine) + 1) * lineHeight);

            [aiTargetEditorLinesHighlight, aiTargetEditorCodeHighlight].forEach((element) => {
                if (!element) return;
                element.style.display = 'block';
                element.style.top = `${top}px`;
                element.style.height = `${height}px`;
            });
        }

        function updateAiTargetEditorLineNumbers() {
            if (!aiTargetEditorLinesInner || !aiTargetEditorTextarea) return;
            const lineCount = aiTargetEditorTextarea.value.split('\n').length;
            const values = [];
            for (let i = 1; i <= lineCount; i++) values.push(i);
            aiTargetEditorLinesInner.textContent = values.join('\n');
            syncAiTargetEditorScroll();
            renderAiTargetSelectionVisuals();
        }

        function renderAiTargetEditor() {
            if (!aiTargetEditorEmpty || !aiTargetEditorWrap || !aiTargetEditorTextarea || !aiTargetEditorPath || !aiTargetEditorStatus) {
                return;
            }

            const viewer = studioState.ai.targetViewer || {};
            if (!viewer.path) {
                aiTargetEditorPath.textContent = 'No file selected';
                aiTargetEditorStatus.textContent = 'Read only';
                aiTargetEditorEmpty.textContent = 'Choose a file to preview it here and select lines directly in this modal.';
                aiTargetEditorEmpty.classList.remove('hidden');
                aiTargetEditorWrap.classList.add('hidden');
                aiTargetEditorTextarea.value = '';
                if (aiTargetEditorLinesInner) aiTargetEditorLinesInner.textContent = '';
                hideAiTargetSelectionVisuals();
                if (aiTargetUseSelectionBtn) aiTargetUseSelectionBtn.disabled = true;
                return;
            }

            aiTargetEditorPath.textContent = viewer.path;

            if (viewer.loading) {
                aiTargetEditorStatus.textContent = 'Loading';
                aiTargetEditorEmpty.textContent = `Loading ${viewer.path}...`;
                aiTargetEditorEmpty.classList.remove('hidden');
                aiTargetEditorWrap.classList.add('hidden');
                hideAiTargetSelectionVisuals();
                if (aiTargetUseSelectionBtn) aiTargetUseSelectionBtn.disabled = true;
                return;
            }

            if (viewer.error) {
                aiTargetEditorStatus.textContent = 'Error';
                aiTargetEditorEmpty.textContent = viewer.error;
                aiTargetEditorEmpty.classList.remove('hidden');
                aiTargetEditorWrap.classList.add('hidden');
                hideAiTargetSelectionVisuals();
                if (aiTargetUseSelectionBtn) aiTargetUseSelectionBtn.disabled = true;
                return;
            }

            if (viewer.isBinary) {
                aiTargetEditorStatus.textContent = 'Binary';
                aiTargetEditorEmpty.textContent = 'This file is binary, so line selection is not available here.';
                aiTargetEditorEmpty.classList.remove('hidden');
                aiTargetEditorWrap.classList.add('hidden');
                hideAiTargetSelectionVisuals();
                if (aiTargetUseSelectionBtn) aiTargetUseSelectionBtn.disabled = true;
                return;
            }

            aiTargetEditorStatus.textContent = 'Read only';
            aiTargetEditorEmpty.classList.add('hidden');
            aiTargetEditorWrap.classList.remove('hidden');
            if (aiTargetEditorTextarea.value !== viewer.content) {
                aiTargetEditorTextarea.value = viewer.content;
            }
            updateAiTargetEditorLineNumbers();
            if (aiTargetUseSelectionBtn) aiTargetUseSelectionBtn.disabled = false;
        }

        async function loadAiTargetViewer(path) {
            const workspaceId = studioState.selectedWorkspaceId || '';
            if (!workspaceId || !path) {
                studioState.ai.targetViewer = {
                    workspaceId: workspaceId,
                    path: '',
                    content: '',
                    isBinary: false,
                    loading: false,
                    error: '',
                    requestId: (studioState.ai.targetViewer?.requestId || 0) + 1,
                };
                renderAiTargetEditor();
                return;
            }

            const requestId = (studioState.ai.targetViewer?.requestId || 0) + 1;
            studioState.ai.targetViewer = {
                workspaceId,
                path,
                content: '',
                isBinary: false,
                loading: true,
                error: '',
                requestId,
            };
            renderAiTargetEditor();

            if (
                studioState.currentFileWorkspaceId === workspaceId
                && studioState.selectedFile?.path === path
                && !studioState.selectedFile?.isBinary
            ) {
                studioState.ai.targetViewer = {
                    workspaceId,
                    path,
                    content: editorTextarea?.value || studioState.selectedFile.content || '',
                    isBinary: false,
                    loading: false,
                    error: '',
                    requestId,
                };
                renderAiTargetEditor();
                return;
            }

            try {
                const response = await fetch(`${studioApiBase}?action=get-file&id=${encodeURIComponent(workspaceId)}&path=${encodeURIComponent(path)}`);
                const data = await response.json().catch(() => null);
                if (studioState.ai.targetViewer.requestId !== requestId || studioState.selectedWorkspaceId !== workspaceId) {
                    return;
                }
                if (!response.ok || !data?.success) {
                    throw new Error(data?.error || `Failed to load ${path}`);
                }

                studioState.ai.targetViewer = {
                    workspaceId,
                    path,
                    content: data.file?.content || '',
                    isBinary: !!data.file?.isBinary,
                    loading: false,
                    error: '',
                    requestId,
                };
            } catch (error) {
                if (studioState.ai.targetViewer.requestId !== requestId || studioState.selectedWorkspaceId !== workspaceId) {
                    return;
                }
                studioState.ai.targetViewer = {
                    workspaceId,
                    path,
                    content: '',
                    isBinary: false,
                    loading: false,
                    error: error.message,
                    requestId,
                };
            }

            renderAiTargetEditor();
        }

        function getAiTargetEditorSelectionRange() {
            if (!aiTargetEditorTextarea || aiTargetEditorWrap?.classList.contains('hidden')) {
                return null;
            }

            const path = String(studioState.ai.targetDraft?.path || '');
            if (!path || studioState.ai.targetViewer?.isBinary || studioState.ai.targetViewer?.loading || studioState.ai.targetViewer?.error) {
                return null;
            }

            const startOffset = Math.min(aiTargetEditorTextarea.selectionStart, aiTargetEditorTextarea.selectionEnd);
            const endOffset = Math.max(aiTargetEditorTextarea.selectionStart, aiTargetEditorTextarea.selectionEnd);
            if (endOffset <= startOffset) {
                return null;
            }

            const startLine = currentAiTargetEditorLineNumber(startOffset);
            const endLine = currentAiTargetEditorLineNumber(Math.max(startOffset, endOffset - 1));

            return {
                path,
                startLine,
                endLine,
                text: aiTargetEditorTextarea.value.slice(startOffset, endOffset),
            };
        }

        function getAiTargetConfig() {
            const target = studioState.ai.target || createEmptyAiTarget();
            const targetFilePath = String(target.path || '').trim();
            let startLine = Number.isInteger(target.startLine) ? target.startLine : null;
            let endLine = Number.isInteger(target.endLine) ? target.endLine : null;

            if (startLine === null && endLine !== null) startLine = endLine;
            if (endLine === null && startLine !== null) endLine = startLine;
            if (startLine !== null && endLine !== null && endLine < startLine) {
                const swap = startLine;
                startLine = endLine;
                endLine = swap;
            }

            return {
                targetFilePath,
                targetStartLine: startLine,
                targetEndLine: endLine,
                targetSelectedText: String(target.text || ''),
                hasLineRange: startLine !== null || endLine !== null,
            };
        }

        function getAiDomTargetConfig() {
            const target = studioState.ai.domTarget || createEmptyAiDomTarget();
            const selector = String(target.selector || '').trim();
            if (!selector) {
                return null;
            }

            return {
                pagePath: String(target.pagePath || '').trim(),
                pageUrl: String(target.pageUrl || '').trim(),
                selector,
                tagName: String(target.tagName || '').trim(),
                role: String(target.role || '').trim(),
                label: String(target.label || '').trim(),
                text: String(target.text || '').trim(),
                html: String(target.html || '').trim(),
            };
        }

        function copyAppliedAiTargetToDraft() {
            const applied = studioState.ai.target || createEmptyAiTarget();
            studioState.ai.targetDraft = {
                path: String(applied.path || ''),
                startLine: Number.isInteger(applied.startLine) ? applied.startLine : null,
                endLine: Number.isInteger(applied.endLine) ? applied.endLine : null,
                text: String(applied.text || ''),
            };
        }

        function getAiTargetDraftConfig() {
            const draft = studioState.ai.targetDraft || createEmptyAiTarget();
            let startLine = Number.isInteger(draft.startLine) ? draft.startLine : null;
            let endLine = Number.isInteger(draft.endLine) ? draft.endLine : null;

            if (startLine === null && endLine !== null) startLine = endLine;
            if (endLine === null && startLine !== null) endLine = startLine;
            if (startLine !== null && endLine !== null && endLine < startLine) {
                const swap = startLine;
                startLine = endLine;
                endLine = swap;
            }

            return {
                path: String(draft.path || '').trim(),
                startLine,
                endLine,
                text: String(draft.text || ''),
            };
        }

        function renderAiTargetModalSummary() {
            if (!aiTargetModalSummary) return;
            const draft = getAiTargetDraftConfig();
            if (aiTargetOpenLinesBtn) aiTargetOpenLinesBtn.disabled = !draft.path;
            const domTarget = studioState.ai.domTarget || createEmptyAiDomTarget();

            if (!draft.path) {
                aiTargetModalSummary.innerHTML = `
                    <div class="ai-target-summary-title">No target selected</div>
                    <div>Pick a file, then optionally narrow the edit by selecting lines in the next modal.</div>
                    ${hasAiDomTarget() ? `<div>Selected preview element: ${escapeHtml(describeAiDomTarget(domTarget))}</div>` : ''}
                `;
                return;
            }

            const lineCopy = draft.startLine !== null
                ? `Lines ${draft.startLine}-${draft.endLine}`
                : 'Whole file';
            const selectionCopy = draft.text.trim() !== ''
                ? '<div>Editor selection snapshot will be included.</div>'
                : '';

            aiTargetModalSummary.innerHTML = `
                <div class="ai-target-summary-title">${escapeHtml(draft.path)}</div>
                <div>${escapeHtml(lineCopy)}</div>
                ${selectionCopy}
                ${hasAiDomTarget() ? `<div>Selected preview element: ${escapeHtml(describeAiDomTarget(domTarget))}</div>` : ''}
            `;
        }

        function renderAiTargetModalFileList() {
            if (!aiTargetFileList) return;

            if (!studioState.selectedWorkspaceId) {
                aiTargetFileList.innerHTML = '<div class="ai-target-empty">Select a workspace first.</div>';
                return;
            }

            const paths = collectWorkspaceFilePaths(studioState.fileTree || []);
            const query = (aiTargetSearchInput?.value || '').trim().toLowerCase();
            const filtered = query
                ? paths.filter(path => path.toLowerCase().includes(query))
                : paths;

            if (!filtered.length) {
                aiTargetFileList.innerHTML = `<div class="ai-target-empty">${query ? 'No files match this search.' : 'No files available.'}</div>`;
                return;
            }

            const draftPath = String(studioState.ai.targetDraft?.path || '');
            aiTargetFileList.innerHTML = filtered.map(path => `
                <button type="button" class="ai-target-file-button ${draftPath === path ? 'active' : ''}" data-ai-target-path="${escapeHtml(path)}">
                    <i class="fas fa-file-code"></i>
                    <span class="ai-target-file-button-path">${escapeHtml(path)}</span>
                </button>
            `).join('');

            aiTargetFileList.querySelectorAll('[data-ai-target-path]').forEach(button => {
                button.addEventListener('click', () => {
                    const path = button.getAttribute('data-ai-target-path') || '';
                    studioState.ai.targetDraft.path = path;
                    studioState.ai.targetDraft.startLine = null;
                    studioState.ai.targetDraft.endLine = null;
                    studioState.ai.targetDraft.text = '';
                    renderAiTargetModalFileList();
                    renderAiTargetModalSummary();
                });
            });
        }

        function openAiTargetModal() {
            if (!studioState.selectedWorkspaceId) {
                window.Toast?.error('Select a workspace first');
                return;
            }

            closeAiTargetLinesModal();
            copyAppliedAiTargetToDraft();
            if (!studioState.ai.targetDraft.path && studioState.selectedFile && !studioState.selectedFile.isBinary) {
                studioState.ai.targetDraft.path = studioState.selectedFile.path;
            }
            if (aiTargetSearchInput) aiTargetSearchInput.value = '';
            renderAiTargetModalFileList();
            renderAiTargetModalSummary();
            aiTargetUseCurrentBtn.disabled = !studioState.selectedFile || studioState.selectedFile.isBinary;
            aiTargetModal?.classList.add('active');
            syncModalOverflow();
            setTimeout(() => aiTargetSearchInput?.focus(), 0);
        }

        function openAiTargetLinesModal() {
            if (!studioState.selectedWorkspaceId) {
                window.Toast?.error('Select a workspace first');
                return;
            }

            if (!studioState.ai.targetDraft.path && studioState.selectedFile && !studioState.selectedFile.isBinary) {
                studioState.ai.targetDraft = {
                    path: studioState.selectedFile.path,
                    startLine: null,
                    endLine: null,
                    text: '',
                };
                renderAiTargetModalFileList();
                renderAiTargetModalSummary();
            }

            if (!studioState.ai.targetDraft.path) {
                window.Toast?.error('Choose a file first');
                return;
            }

            aiTargetLinesModal?.classList.add('active');
            syncModalOverflow();
            loadAiTargetViewer(studioState.ai.targetDraft.path);
        }

        function closeAiTargetLinesModal() {
            aiTargetLinesModal?.classList.remove('active');
            syncModalOverflow();
        }

        function closeAiTargetModal() {
            closeAiTargetLinesModal();
            aiTargetModal?.classList.remove('active');
            syncModalOverflow();
        }

        function clearAiTargetDraft() {
            studioState.ai.targetDraft = createEmptyAiTarget();
            studioState.ai.domTarget = createEmptyAiDomTarget();
            if (aiTargetSearchInput) aiTargetSearchInput.value = '';
            closeAiTargetLinesModal();
            stopPreviewElementPicker();
            const previewDoc = getPreviewFrameDocument();
            if (previewDoc) clearPreviewPickerMarkers(previewDoc);
            renderAiTargetModalFileList();
            renderAiTargetModalSummary();
            loadAiTargetViewer('');
            renderAiSelectionHint();
            renderAiMessages();
        }

        function useCurrentFileAsAiTargetDraft() {
            if (!studioState.selectedFile || studioState.selectedFile.isBinary) {
                window.Toast?.error('Open a text file first');
                return;
            }

            if (aiTargetSearchInput) aiTargetSearchInput.value = '';
            studioState.ai.targetDraft = {
                path: studioState.selectedFile.path,
                startLine: null,
                endLine: null,
                text: '',
            };
            renderAiTargetModalFileList();
            renderAiTargetModalSummary();
        }

        function applyAiTargetLineSelection() {
            const selection = getAiTargetEditorSelectionRange();
            if (!selection) {
                window.Toast?.error('Highlight text in the modal preview first');
                return;
            }

            if (aiTargetSearchInput) aiTargetSearchInput.value = '';
            studioState.ai.targetDraft = {
                path: selection.path,
                startLine: selection.startLine,
                endLine: selection.endLine,
                text: selection.text,
            };
            renderAiTargetModalFileList();
            renderAiTargetModalSummary();
            closeAiTargetLinesModal();
        }

        function applyAiTargetFromModal() {
            const draft = getAiTargetDraftConfig();
            if ((draft.startLine !== null || draft.endLine !== null) && !draft.path) {
                window.Toast?.error('Pick a file before targeting lines');
                return;
            }

            studioState.ai.target = {
                path: draft.path,
                startLine: draft.startLine,
                endLine: draft.endLine,
                text: draft.path ? draft.text : '',
            };
            closeAiTargetModal();
            renderAiSelectionHint();
        }

        function getPreviewFrameDocument() {
            try {
                return previewFrame?.contentDocument || null;
            } catch (error) {
                return null;
            }
        }

        function getPreviewFrameWindow() {
            try {
                return previewFrame?.contentWindow || null;
            } catch (error) {
                return null;
            }
        }

        function getPreviewCurrentPagePath() {
            const fallback = (previewPageInput?.value || 'index.php').trim() || 'index.php';
            const win = getPreviewFrameWindow();
            if (!win?.location) {
                return fallback;
            }

            try {
                const currentUrl = new URL(win.location.href);
                const baseUrl = previewFrame?.dataset.baseUrl
                    ? new URL(previewFrame.dataset.baseUrl, window.location.href)
                    : null;
                if (!baseUrl) {
                    return fallback;
                }

                const baseDir = new URL('./', baseUrl);
                let path = currentUrl.pathname;
                if (path.startsWith(baseDir.pathname)) {
                    path = path.slice(baseDir.pathname.length);
                } else {
                    path = path.split('/').pop() || fallback;
                }

                path = path.replace(/^\/+/, '') || 'index.php';
                return `${path}${currentUrl.search}${currentUrl.hash}`;
            } catch (error) {
                return fallback;
            }
        }

        function normalizePreviewElementText(value, maxLength = 240) {
            const normalized = String(value || '').replace(/\s+/g, ' ').trim();
            if (!normalized) return '';
            return normalized.length > maxLength ? `${normalized.slice(0, maxLength - 3)}...` : normalized;
        }

        function buildPreviewElementSelector(element, doc) {
            if (!(element instanceof doc.defaultView.Element)) {
                return '';
            }

            if (element.id) {
                const escapedId = window.CSS?.escape ? window.CSS.escape(element.id) : element.id.replace(/([^a-zA-Z0-9_-])/g, '\\$1');
                const selector = `#${escapedId}`;
                if (doc.querySelectorAll(selector).length === 1) {
                    return selector;
                }
            }

            const segments = [];
            let node = element;
            while (node && node.nodeType === 1 && node !== doc.body && segments.length < 5) {
                let segment = node.tagName.toLowerCase();

                const classes = Array.from(node.classList || [])
                    .filter(name => /^[a-zA-Z][\w-]*$/.test(name))
                    .slice(0, 2);
                if (classes.length) {
                    segment += '.' + classes.join('.');
                }

                if (node.parentElement) {
                    const siblings = Array.from(node.parentElement.children).filter(child => child.tagName === node.tagName);
                    if (siblings.length > 1) {
                        segment += `:nth-of-type(${siblings.indexOf(node) + 1})`;
                    }
                }

                segments.unshift(segment);
                node = node.parentElement;
            }

            return segments.length ? segments.join(' > ') : element.tagName.toLowerCase();
        }

        function buildPreviewElementTarget(doc, element) {
            const role = element.getAttribute('role') || '';
            const label = normalizePreviewElementText(
                element.getAttribute('aria-label')
                || element.getAttribute('title')
                || element.getAttribute('alt')
                || element.getAttribute('placeholder')
                || element.getAttribute('value')
                || ''
            , 120);
            const text = normalizePreviewElementText(element.innerText || element.textContent || '', 240);
            const outerHtml = normalizePreviewElementText(element.outerHTML || '', 1200);

            return {
                pagePath: getPreviewCurrentPagePath(),
                pageUrl: (() => {
                    try {
                        return doc.location.href || '';
                    } catch (error) {
                        return '';
                    }
                })(),
                selector: buildPreviewElementSelector(element, doc),
                tagName: String(element.tagName || '').toLowerCase(),
                role,
                label,
                text,
                html: outerHtml,
            };
        }

        function ensurePreviewPickerStyle(doc) {
            if (doc.getElementById('doki-ai-preview-picker-style')) {
                return;
            }

            const style = doc.createElement('style');
            style.id = 'doki-ai-preview-picker-style';
            style.textContent = `
                [data-doki-ai-hover="true"] {
                    outline: 2px solid rgba(96, 165, 250, 0.95) !important;
                    outline-offset: 2px !important;
                    background: rgba(59, 130, 246, 0.08) !important;
                    cursor: crosshair !important;
                }

                [data-doki-ai-selected="true"] {
                    outline: 2px solid rgba(34, 197, 94, 0.95) !important;
                    outline-offset: 2px !important;
                    background: rgba(34, 197, 94, 0.10) !important;
                    box-shadow:
                        inset 0 1px 0 rgba(134, 239, 172, 0.95),
                        inset 0 -1px 0 rgba(134, 239, 172, 0.95) !important;
                }
            `;
            doc.head?.appendChild(style);
        }

        function clearPreviewPickerMarkers(doc) {
            if (!doc) return;
            doc.querySelectorAll('[data-doki-ai-hover="true"]').forEach(node => node.removeAttribute('data-doki-ai-hover'));
            doc.querySelectorAll('[data-doki-ai-selected="true"]').forEach(node => node.removeAttribute('data-doki-ai-selected'));
        }

        function getSelectablePreviewElement(target, doc) {
            if (!(target instanceof doc.defaultView.Element)) {
                return null;
            }

            let node = target;
            while (node && node !== doc.body) {
                const tagName = String(node.tagName || '').toLowerCase();
                if (!['html', 'head', 'body', 'script', 'style', 'link', 'meta'].includes(tagName)) {
                    return node;
                }
                node = node.parentElement;
            }

            return null;
        }

        function applyStoredPreviewElementSelection() {
            const doc = getPreviewFrameDocument();
            if (!doc || !hasAiDomTarget()) return;

            ensurePreviewPickerStyle(doc);
            doc.querySelectorAll('[data-doki-ai-selected="true"]').forEach(node => node.removeAttribute('data-doki-ai-selected'));

            const selector = String(studioState.ai.domTarget.selector || '');
            if (!selector) return;

            try {
                const element = doc.querySelector(selector);
                if (element) {
                    element.setAttribute('data-doki-ai-selected', 'true');
                }
            } catch (error) {
                // Ignore invalid selectors recovered from older sessions.
            }
        }

        function stopPreviewElementPicker() {
            studioState.ai.pickerActive = false;
            if (typeof previewElementPickerCleanup === 'function') {
                previewElementPickerCleanup();
            }
            previewElementPickerCleanup = null;
            updatePreviewElementPickerButton();
        }

        function attachPreviewElementPicker() {
            if (!studioState.ai.pickerActive) {
                applyStoredPreviewElementSelection();
                return;
            }

            const doc = getPreviewFrameDocument();
            if (!doc?.body) {
                return;
            }

            stopPreviewElementPicker();
            studioState.ai.pickerActive = true;
            updatePreviewElementPickerButton();
            ensurePreviewPickerStyle(doc);
            clearPreviewPickerMarkers(doc);
            applyStoredPreviewElementSelection();

            let hovered = null;
            const previousBodyCursor = doc.body?.style.cursor || '';
            const setHovered = (element) => {
                if (hovered === element) return;
                if (hovered) hovered.removeAttribute('data-doki-ai-hover');
                hovered = element;
                if (hovered) hovered.setAttribute('data-doki-ai-hover', 'true');
            };

            const onPointerMove = (event) => {
                const element = getSelectablePreviewElement(event.target, doc);
                setHovered(element);
            };

            const onPointerLeave = () => {
                setHovered(null);
            };

            const onClick = (event) => {
                const element = getSelectablePreviewElement(event.target, doc);
                if (!element) return;

                event.preventDefault();
                event.stopPropagation();
                if (typeof event.stopImmediatePropagation === 'function') {
                    event.stopImmediatePropagation();
                }

                studioState.ai.domTarget = buildPreviewElementTarget(doc, element);
                setHovered(null);
                stopPreviewElementPicker();
                applyStoredPreviewElementSelection();
                renderAiMessages();
                renderAiSelectionHint();
                renderAiTargetModalSummary();
                window.Toast?.success(`Selected ${describeAiDomTarget()}`);
            };

            const onKeyDown = (event) => {
                if (event.key !== 'Escape') return;
                event.preventDefault();
                stopPreviewElementPicker();
                applyStoredPreviewElementSelection();
            };

            doc.addEventListener('mousemove', onPointerMove, true);
            doc.addEventListener('mouseover', onPointerMove, true);
            doc.addEventListener('mouseleave', onPointerLeave, true);
            doc.addEventListener('click', onClick, true);
            doc.addEventListener('keydown', onKeyDown, true);

            if (doc.body) {
                doc.body.style.cursor = 'crosshair';
            }

            previewElementPickerCleanup = () => {
                doc.removeEventListener('mousemove', onPointerMove, true);
                doc.removeEventListener('mouseover', onPointerMove, true);
                doc.removeEventListener('mouseleave', onPointerLeave, true);
                doc.removeEventListener('click', onClick, true);
                doc.removeEventListener('keydown', onKeyDown, true);
                if (doc.body) {
                    doc.body.style.cursor = previousBodyCursor;
                }
                clearPreviewPickerMarkers(doc);
                applyStoredPreviewElementSelection();
            };
        }

        function togglePreviewElementPicker() {
            if (!studioState.previewUrl || previewFrame?.classList.contains('hidden')) {
                window.Toast?.error('Load the live preview first');
                return;
            }

            if (studioState.ai.pickerActive) {
                stopPreviewElementPicker();
                applyStoredPreviewElementSelection();
                return;
            }

            studioState.ai.pickerActive = true;
            updatePreviewElementPickerButton();
            attachPreviewElementPicker();
        }

        function renderAiMessages() {
            if (!studioState.ai.enabled || !aiMessages) return;

            const messages = studioState.ai.messages;
            const messageItems = [];
            const emptyStateCopy = aiProviderSelect?.disabled
                ? 'Connect an AI provider to start building with prompts.'
                : 'Ask for a change, then review the proposed file edits before applying them.';

            if (!messages.length) {
                messageItems.push(`
                    <div class="ai-empty-state ${hasAiDomTarget() ? 'inline' : ''}">
                        <i class="fas fa-robot"></i>
                        <div>${escapeHtml(emptyStateCopy)}</div>
                    </div>
                `);
            }

            messageItems.push(...messages.map((message, index) => {
                const messageId = String(message.id || `ai-msg-fallback-${index}`);
                const revertOperations = Array.isArray(message.revertOperations) ? message.revertOperations : [];
                const canRevert = message.role === 'assistant' && revertOperations.length > 0 && !message.reverted;
                const actionHtml = canRevert ? `
                    <div class="ai-message-actions">
                        <button
                            type="button"
                            class="ai-message-action"
                            data-ai-message-revert="${escapeHtml(messageId)}"
                            ${studioState.ai.applying || studioState.ai.revertingMessageId ? 'disabled' : ''}
                        >
                            <i class="fas fa-rotate-left"></i> Revert
                        </button>
                    </div>
                ` : (message.reverted ? `
                    <div class="ai-message-actions">
                        <span class="ai-message-flag"><i class="fas fa-check"></i> Reverted</span>
                    </div>
                ` : '');
                return `
                    <div class="ai-message ${escapeHtml(message.role)}">
                        <div class="ai-message-role">${escapeHtml(message.role === 'user' ? 'You' : 'Studio AI')}</div>
                        <div class="ai-message-bubble">${escapeHtml(message.content)}</div>
                        ${actionHtml}
                    </div>
                `;
            }));

            if (hasAiDomTarget()) {
                messageItems.push(`
                    <div class="ai-message context">
                        <div class="ai-message-role">Selected Element</div>
                        <div class="ai-message-bubble">
                            <div class="ai-context-bubble-copy">
                                <i class="fas fa-arrow-pointer"></i>
                                <div class="ai-context-bubble-text">
                                    <div class="ai-context-bubble-title">Live preview target</div>
                                    <div class="ai-context-bubble-description">${escapeHtml(describeAiDomTarget())}</div>
                                </div>
                            </div>
                            <button
                                type="button"
                                class="ai-context-bubble-remove"
                                data-ai-dom-target-remove="true"
                                aria-label="Remove selected element"
                                title="Remove selected element"
                            >
                                <i class="fas fa-xmark"></i>
                            </button>
                        </div>
                    </div>
                `);
            }

            aiMessages.innerHTML = messageItems.join('');
            aiMessages.querySelectorAll('[data-ai-message-revert]').forEach(button => {
                button.addEventListener('click', () => {
                    const messageId = button.getAttribute('data-ai-message-revert') || '';
                    revertAiMessage(messageId);
                });
            });
            aiMessages.querySelectorAll('[data-ai-dom-target-remove]').forEach(button => {
                button.addEventListener('click', () => {
                    clearAiDomTarget({ silent: true });
                });
            });
            aiMessages.scrollTop = aiMessages.scrollHeight;
        }

        function renderAiPlan() {
            if (!studioState.ai.enabled || !aiPlanBox) return;

            const plan = studioState.ai.pendingPlan;
            if (!plan || !Array.isArray(plan.operations) || !plan.operations.length) {
                aiPlanBox.classList.remove('visible');
                if (aiPlanLintBox) aiPlanLintBox.style.display = 'none';
                if (aiPlanLintList) aiPlanLintList.innerHTML = '';
                if (aiApplyBtn) aiApplyBtn.disabled = true;
                if (aiViewDiffBtn) aiViewDiffBtn.disabled = true;
                return;
            }

            aiPlanBox.classList.add('visible');
            aiPlanSummary.textContent = plan.summary || `Proposed ${plan.operations.length} file change(s).`;
            aiPlanList.innerHTML = plan.operations.map(operation => {
                const isDelete = operation.type === 'delete';
                return `
                    <li class="ai-plan-item">
                        <span class="ai-plan-badge ${isDelete ? 'delete' : ''}">${escapeHtml(operation.type)}</span>
                        <span class="ai-plan-path" title="${escapeHtml(operation.path)}">${escapeHtml(operation.path)}</span>
                    </li>
                `;
            }).join('');

            const notes = Array.isArray(plan.notes) ? plan.notes.filter(Boolean) : [];
            aiPlanNotes.innerHTML = notes.map(note => `<li>${escapeHtml(note)}</li>`).join('');
            aiPlanNotes.style.display = notes.length ? 'block' : 'none';

            const lintWarnings = Array.isArray(plan.lint) ? plan.lint.filter(item => item && item.message) : [];
            const hasBlockingLint = lintWarnings.some(item => ['danger', 'error', 'blocking'].includes(String(item.severity || '').toLowerCase()));
            if (aiPlanLintBox && aiPlanLintList) {
                if (lintWarnings.length) {
                    aiPlanLintBox.style.display = 'block';
                    aiPlanLintBox.classList.toggle('danger', hasBlockingLint);
                    aiPlanLintList.innerHTML = lintWarnings.map(item => {
                        const location = [item.path || '', item.line ? `:${item.line}` : ''].join('');
                        const severityClass = ['danger', 'error', 'blocking'].includes(String(item.severity || '').toLowerCase())
                            ? 'danger'
                            : '';
                        return `
                            <li class="ai-plan-lint-item ${severityClass}">
                                <div class="ai-plan-lint-item-title">${escapeHtml(item.title || 'Warning')}</div>
                                <div class="ai-plan-lint-item-meta">${escapeHtml(location || 'Generated file')}</div>
                                <div class="ai-plan-lint-item-message">${escapeHtml(item.message || '')}</div>
                            </li>
                        `;
                    }).join('');
                } else {
                    aiPlanLintBox.style.display = 'none';
                    aiPlanLintBox.classList.remove('danger');
                    aiPlanLintList.innerHTML = '';
                }
            }

            if (aiApplyBtn) aiApplyBtn.disabled = hasBlockingLint || !studioState.selectedWorkspaceId || studioState.ai.applying;
            if (aiViewDiffBtn) aiViewDiffBtn.disabled = !studioState.selectedWorkspaceId || studioState.ai.applying;
        }

        function renderAiModelOptions() {
            if (!studioState.ai.enabled || !aiModelOptions) return;
            const provider = currentAiProvider();
            const isOpenRouter = providerUsesOpenRouter(provider);
            const filteredModels = isOpenRouter
                ? filterOpenRouterFreeModels(studioState.ai.allModelOptions, !!aiModelFreeOnlyInput?.checked)
                : [...(studioState.ai.allModelOptions || [])];
            studioState.ai.modelOptions = filteredModels;
            aiModelOptions.innerHTML = (studioState.ai.modelOptions || []).map(model => {
                return `<option value="${escapeHtml(model.id)}">${escapeHtml(model.label || model.id)}</option>`;
            }).join('');

            const currentValue = aiModelInput?.value.trim() || '';
            const currentExists = filteredModels.some(model => model.id === currentValue);
            if (aiModelInput && filteredModels.length > 0 && (!currentValue || (isOpenRouter && !!aiModelFreeOnlyInput?.checked && !currentExists))) {
                const fallback = studioState.ai.selectedModel && filteredModels.some(model => model.id === studioState.ai.selectedModel)
                    ? studioState.ai.selectedModel
                    : filteredModels[0].id;
                aiModelInput.value = fallback;
                studioState.ai.selectedModel = fallback;
            } else if (aiModelInput && isOpenRouter && !!aiModelFreeOnlyInput?.checked && !filteredModels.length) {
                aiModelInput.value = '';
                studioState.ai.selectedModel = '';
            }

            updateStarterAiState();
        }

        function renderAiSelectionHint() {
            if (!aiSelectionHint) return;
            const target = getAiTargetConfig();
            const domTarget = studioState.ai.domTarget || createEmptyAiDomTarget();
            if (openAiTargetModalBtn) {
                openAiTargetModalBtn.innerHTML = target.targetFilePath
                    ? '<i class="fas fa-crosshairs"></i> Edit Target'
                    : '<i class="fas fa-crosshairs"></i> Target';
            }
            updatePreviewElementPickerButton();

            const parts = [];
            if (target.targetFilePath && target.targetStartLine !== null) {
                parts.push(`File: ${target.targetFilePath} lines ${target.targetStartLine}-${target.targetEndLine}`);
            } else if (target.targetFilePath) {
                parts.push(`File: ${target.targetFilePath}`);
            }
            if (hasAiDomTarget()) {
                parts.push(`Element: ${describeAiDomTarget(domTarget)}`);
            }

            if (parts.length) {
                aiSelectionHint.textContent = `Target: ${parts.join(' • ')}. AI will still use wider workspace context.`;
                return;
            }

            aiSelectionHint.textContent = studioState.selectedFile?.path
                ? `No target set. Current file context: ${studioState.selectedFile.path}`
                : 'No target set. The AI will use workspace-level context.';
        }

        function renderAiProviderModalModelOptions() {
            if (!aiProviderDefaultModelOptions) return;
            aiProviderDefaultModelOptions.innerHTML = (studioState.ai.providerModalModelOptions || []).map(model => {
                return `<option value="${escapeHtml(model.id)}">${escapeHtml(model.label || model.id)}</option>`;
            }).join('');
        }

        function renderAiProviderManageOptions() {
            if (!aiProviderManageSelect) return;

            const options = ['<option value="">New provider</option>'];
            for (const provider of studioState.ai.providers) {
                options.push(`<option value="${escapeHtml(provider.id)}">${escapeHtml(provider.name)}</option>`);
            }

            aiProviderManageSelect.innerHTML = options.join('');
            aiProviderManageSelect.value = aiProviderIdInput?.value || '';
        }

        function resetAiProviderModalModelOptions() {
            studioState.ai.providerModalModelOptions = [];
            renderAiProviderModalModelOptions();
        }

        function syncModalOverflow() {
            const hasOpenModal = !!(
                workspaceModal?.classList.contains('active')
                || aiProviderModal?.classList.contains('active')
                || aiTargetModal?.classList.contains('active')
                || aiTargetLinesModal?.classList.contains('active')
                || aiDiffModal?.classList.contains('active')
            );
            document.body.style.overflow = hasOpenModal ? 'hidden' : '';
        }

        function resetAiDiffState() {
            studioState.ai.diffState = {
                loading: false,
                files: [],
                selectedPath: '',
            };
        }

        async function fetchWorkspaceFileMaybe(workspaceId, path) {
            const response = await fetch(`${studioApiBase}?action=get-file&id=${encodeURIComponent(workspaceId)}&path=${encodeURIComponent(path)}`);
            const data = await response.json().catch(() => null);

            if (response.status === 404) {
                return { exists: false, isBinary: false, content: '' };
            }
            if (!response.ok || !data?.success) {
                throw new Error(data?.error || `Failed to load ${path}`);
            }

            return {
                exists: true,
                isBinary: !!data.file?.isBinary,
                content: data.file?.content || '',
            };
        }

        function diffLines(oldText, newText) {
            const oldLines = String(oldText || '').replace(/\r\n/g, '\n').split('\n');
            const newLines = String(newText || '').replace(/\r\n/g, '\n').split('\n');
            if (oldLines.length && oldLines[oldLines.length - 1] === '') oldLines.pop();
            if (newLines.length && newLines[newLines.length - 1] === '') newLines.pop();

            if (oldLines.length === 0 && newLines.length === 0) return [];

            const maxCells = 250000;
            if ((oldLines.length * newLines.length) > maxCells) {
                return diffLinesFallback(oldLines, newLines);
            }

            const rows = oldLines.length + 1;
            const cols = newLines.length + 1;
            const dp = Array.from({ length: rows }, () => new Uint32Array(cols));

            for (let i = oldLines.length - 1; i >= 0; i--) {
                for (let j = newLines.length - 1; j >= 0; j--) {
                    dp[i][j] = oldLines[i] === newLines[j]
                        ? dp[i + 1][j + 1] + 1
                        : Math.max(dp[i + 1][j], dp[i][j + 1]);
                }
            }

            const diff = [];
            let i = 0;
            let j = 0;
            while (i < oldLines.length && j < newLines.length) {
                if (oldLines[i] === newLines[j]) {
                    diff.push({ type: 'context', text: oldLines[i] });
                    i++;
                    j++;
                } else if (dp[i + 1][j] >= dp[i][j + 1]) {
                    diff.push({ type: 'delete', text: oldLines[i] });
                    i++;
                } else {
                    diff.push({ type: 'add', text: newLines[j] });
                    j++;
                }
            }

            while (i < oldLines.length) {
                diff.push({ type: 'delete', text: oldLines[i++] });
            }
            while (j < newLines.length) {
                diff.push({ type: 'add', text: newLines[j++] });
            }

            return diff;
        }

        function diffLinesFallback(oldLines, newLines) {
            let prefix = 0;
            while (
                prefix < oldLines.length
                && prefix < newLines.length
                && oldLines[prefix] === newLines[prefix]
            ) {
                prefix++;
            }

            let oldSuffix = oldLines.length - 1;
            let newSuffix = newLines.length - 1;
            while (
                oldSuffix >= prefix
                && newSuffix >= prefix
                && oldLines[oldSuffix] === newLines[newSuffix]
            ) {
                oldSuffix--;
                newSuffix--;
            }

            const diff = [];
            for (let index = 0; index < prefix; index++) {
                diff.push({ type: 'context', text: oldLines[index] });
            }
            for (let index = prefix; index <= oldSuffix; index++) {
                diff.push({ type: 'delete', text: oldLines[index] });
            }
            for (let index = prefix; index <= newSuffix; index++) {
                diff.push({ type: 'add', text: newLines[index] });
            }
            for (let index = oldSuffix + 1; index < oldLines.length; index++) {
                diff.push({ type: 'context', text: oldLines[index] });
            }

            return diff;
        }

        function buildDiffRows(diff) {
            let oldLine = 1;
            let newLine = 1;
            let skippedContext = false;

            const rows = [];

            diff.forEach(entry => {
                if (entry.type === 'context') {
                    oldLine++;
                    newLine++;
                    skippedContext = true;
                    return;
                }

                if (skippedContext && rows.length) {
                    rows.push(`
                        <div class="ai-diff-gap">
                            <span></span>
                            <span></span>
                            <span>…</span>
                            <span>unchanged lines omitted</span>
                        </div>
                    `);
                }
                skippedContext = false;

                const oldNumber = entry.type === 'add' ? '' : oldLine++;
                const newNumber = entry.type === 'delete' ? '' : newLine++;
                const marker = entry.type === 'add' ? '+' : (entry.type === 'delete' ? '-' : ' ');
                rows.push(`
                    <div class="ai-diff-line ${escapeHtml(entry.type)}">
                        <span class="ai-diff-line-num">${oldNumber}</span>
                        <span class="ai-diff-line-num">${newNumber}</span>
                        <span class="ai-diff-line-mark">${escapeHtml(marker)}</span>
                        <span class="ai-diff-line-text">${escapeHtml(entry.text)}</span>
                    </div>
                `);
            });

            return rows.length
                ? `<div class="ai-diff-content-inner">${rows.join('')}</div>`
                : '';
        }

        function diffKindLabel(file) {
            if (file.operation.type === 'delete') return 'delete';
            if (!file.originalExists) return 'create';
            return 'write';
        }

        function diffKindCopy(file) {
            if (file.operation.type === 'delete') return 'Deletes this file from the workspace.';
            if (!file.originalExists) return 'Creates a new file in the workspace.';
            return 'Replaces the current file content with the proposed version.';
        }

        function renderAiDiffModal() {
            if (!aiDiffFiles || !aiDiffContent || !aiDiffMeta) return;

            const diffState = studioState.ai.diffState;
            if (diffState.loading) {
                aiDiffFiles.innerHTML = '<div class="ai-diff-empty">Loading diffs...</div>';
                aiDiffMeta.innerHTML = `
                    <div class="ai-diff-meta-main">
                        <div class="ai-diff-meta-path">Loading...</div>
                        <div class="ai-diff-meta-copy">Fetching the current workspace contents to build a diff.</div>
                    </div>
                `;
                aiDiffContent.innerHTML = '<div class="ai-diff-empty">Loading diffs...</div>';
                return;
            }

            if (!diffState.files.length) {
                aiDiffFiles.innerHTML = '<div class="ai-diff-empty">No pending changes.</div>';
                aiDiffMeta.innerHTML = `
                    <div class="ai-diff-meta-main">
                        <div class="ai-diff-meta-path">No file selected</div>
                        <div class="ai-diff-meta-copy">Select a changed file to inspect the proposed diff.</div>
                    </div>
                `;
                aiDiffContent.innerHTML = '<div class="ai-diff-empty">No pending changes.</div>';
                if (aiDiffApplyBtn) aiDiffApplyBtn.disabled = true;
                return;
            }

            const selectedPath = diffState.selectedPath || diffState.files[0].path;
            const selectedFile = diffState.files.find(file => file.path === selectedPath) || diffState.files[0];
            diffState.selectedPath = selectedFile.path;

            aiDiffFiles.innerHTML = diffState.files.map(file => {
                const kind = diffKindLabel(file);
                return `
                    <button type="button" class="ai-diff-file ${file.path === selectedFile.path ? 'active' : ''}" data-ai-diff-path="${escapeHtml(file.path)}">
                        <span class="ai-diff-file-path" title="${escapeHtml(file.path)}">${escapeHtml(file.path)}</span>
                        <span class="ai-diff-file-meta">
                            <span class="ai-plan-badge ${kind === 'delete' ? 'delete' : ''}">${escapeHtml(kind)}</span>
                            <span>${file.stats.additions}+</span>
                            <span>${file.stats.deletions}-</span>
                        </span>
                    </button>
                `;
            }).join('');

            aiDiffMeta.innerHTML = `
                <div class="ai-diff-meta-main">
                    <div class="ai-diff-meta-path">${escapeHtml(selectedFile.path)}</div>
                    <div class="ai-diff-meta-copy">${escapeHtml(diffKindCopy(selectedFile))}</div>
                </div>
                <div class="ai-diff-toolbar">
                    <span class="ai-diff-meta-badge ${diffKindLabel(selectedFile)}">${escapeHtml(diffKindLabel(selectedFile))}</span>
                </div>
            `;

            if (selectedFile.isBinary) {
                aiDiffContent.innerHTML = '<div class="ai-diff-empty">Binary files cannot be rendered as text diffs.</div>';
            } else {
                const diffHtml = selectedFile.rows.length ? buildDiffRows(selectedFile.rows) : '';
                aiDiffContent.innerHTML = diffHtml || '<div class="ai-diff-empty">No line changes detected.</div>';
            }

            aiDiffFiles.querySelectorAll('[data-ai-diff-path]').forEach(button => {
                button.addEventListener('click', () => {
                    studioState.ai.diffState.selectedPath = button.dataset.aiDiffPath || '';
                    renderAiDiffModal();
                });
            });

            if (aiDiffApplyBtn) aiDiffApplyBtn.disabled = !studioState.ai.pendingPlan?.operations?.length || studioState.ai.applying;
        }

        async function ensureAiDiffData() {
            if (!studioState.ai.enabled) return;
            if (studioState.ai.diffState.files.length || studioState.ai.diffState.loading) {
                return;
            }

            const workspace = currentWorkspace();
            const operations = studioState.ai.pendingPlan?.operations || [];
            if (!workspace || !operations.length) return;

            studioState.ai.diffState.loading = true;
            renderAiDiffModal();

            try {
                const files = await Promise.all(operations.map(async (operation) => {
                    const original = await fetchWorkspaceFileMaybe(workspace.id, operation.path);
                    const nextContent = operation.type === 'write' ? String(operation.content || '') : '';
                    const rows = original.isBinary ? [] : diffLines(original.content || '', nextContent);
                    const stats = rows.reduce((acc, row) => {
                        if (row.type === 'add') acc.additions += 1;
                        if (row.type === 'delete') acc.deletions += 1;
                        return acc;
                    }, { additions: 0, deletions: 0 });

                    return {
                        path: operation.path,
                        operation,
                        originalExists: original.exists,
                        isBinary: original.isBinary,
                        rows,
                        stats,
                    };
                }));

                studioState.ai.diffState.files = files;
                studioState.ai.diffState.selectedPath = files[0]?.path || '';
            } finally {
                studioState.ai.diffState.loading = false;
            }
        }

        async function openAiDiffModal() {
            if (!studioState.ai.enabled || !aiDiffModal) return;
            if (!studioState.ai.pendingPlan?.operations?.length) return;
            aiDiffModal.classList.add('active');
            syncModalOverflow();
            renderAiDiffModal();
            try {
                await ensureAiDiffData();
                renderAiDiffModal();
            } catch (error) {
                aiDiffModal.classList.remove('active');
                syncModalOverflow();
                window.Toast?.error(error.message);
            }
        }

        function closeAiDiffModal() {
            aiDiffModal?.classList.remove('active');
            syncModalOverflow();
        }

        function applyDisplayMode() {
            const mode = studioState.ai.enabled && studioState.displayMode === 'ai' ? 'ai' : 'manual';
            studioState.displayMode = mode;
            studioState.ai.panelOpen = mode === 'ai';

            studioBody?.classList.toggle('display-mode-ai', mode === 'ai');
            studioBody?.classList.toggle('display-mode-manual', mode !== 'ai');
            displayModeManualBtn?.classList.toggle('active', mode === 'manual');
            displayModeAiBtn?.classList.toggle('active', mode === 'ai');

            if (aiPane) {
                aiPane.classList.toggle('collapsed', mode !== 'ai');
            }

            if (toggleFilesBtn) {
                toggleFilesBtn.hidden = mode !== 'manual' || !studioState.selectedWorkspaceId;
                toggleFilesBtn.classList.toggle('active', studioState.filePanelOpen && mode === 'manual');
            }
        }

        function setDisplayMode(mode) {
            const next = mode === 'ai' && studioState.ai.enabled ? 'ai' : 'manual';
            studioState.displayMode = next;
            applyDisplayMode();

            if (next === 'ai' && studioState.ai.selectedProviderId && !studioState.ai.modelOptions.length) {
                loadAiModels(studioState.ai.selectedProviderId);
            }
        }

        async function loadAiModels(providerId, preserveInput = false) {
            if (!studioState.ai.enabled || !providerId) {
                studioState.ai.allModelOptions = [];
                studioState.ai.modelOptions = [];
                renderAiModelOptions();
                return;
            }

            studioState.ai.loadingModels = true;
            setAiStatus('Loading models');
            try {
                const data = await getStudio('list-ai-models', { id: providerId });
                studioState.ai.allModelOptions = Array.isArray(data.models) ? data.models : [];
                renderAiModelOptions();

                if (!preserveInput || !aiModelInput?.value.trim()) {
                    const fallbackModel = aiModelInput?.value.trim()
                        || studioState.ai.modelOptions?.[0]?.id
                        || data.recommendedModel
                        || getAiProvider(providerId)?.defaultModel
                        || data.models?.[0]?.id
                        || '';
                    studioState.ai.selectedModel = fallbackModel;
                    if (aiModelInput) aiModelInput.value = fallbackModel;
                }

                setAiStatus(data.recommendedModel ? `Recommended: ${data.recommendedModel}` : 'Ready');
            } catch (error) {
                studioState.ai.modelOptions = [];
                renderAiModelOptions();
                setAiStatus('Model lookup failed');
                window.Toast?.error(error.message);
            } finally {
                studioState.ai.loadingModels = false;
            }
        }

        async function loadStarterAiModels(providerId, preserveInput = false) {
            if (!studioState.ai.enabled || !providerId) {
                studioState.ai.starterModelOptions = [];
                renderStarterAiModelOptions();
                updateStarterAiState();
                return;
            }

            studioState.ai.starterLoadingModels = true;
            updateStarterAiState();
            try {
                const data = await getStudio('list-ai-models', { id: providerId });
                studioState.ai.starterModelOptions = Array.isArray(data.models) ? data.models : [];
                renderStarterAiModelOptions();

                if (!preserveInput || !starterAiModelInput?.value.trim()) {
                    const fallbackModel = starterAiModelInput?.value.trim()
                        || studioState.ai.starterSelectedModel
                        || data.recommendedModel
                        || getAiProvider(providerId)?.defaultModel
                        || data.models?.[0]?.id
                        || '';
                    studioState.ai.starterSelectedModel = fallbackModel;
                    if (starterAiModelInput) starterAiModelInput.value = fallbackModel;
                }
            } catch (error) {
                studioState.ai.starterModelOptions = [];
                renderStarterAiModelOptions();
                if (!preserveInput) {
                    studioState.ai.starterSelectedModel = '';
                    if (starterAiModelInput) starterAiModelInput.value = '';
                }
                window.Toast?.error(error.message);
            } finally {
                studioState.ai.starterLoadingModels = false;
                updateStarterAiState();
            }
        }

        async function loadAiProviderModalModels() {
            if (!studioState.ai.enabled || !loadAiProviderModelsBtn) return;

            studioState.ai.providerModalLoadingModels = true;
            btnLoading(loadAiProviderModelsBtn, true);
            if (aiProviderTestStatus) aiProviderTestStatus.textContent = '';

            try {
                const payload = {
                    id: aiProviderIdInput?.value.trim() || '',
                    name: aiProviderNameInput?.value.trim() || '',
                    type: aiProviderTypeHiddenInput?.value || 'ollama',
                    baseUrl: aiProviderBaseUrlInput?.value.trim() || '',
                    apiKey: aiProviderApiKeyInput?.value || '',
                    defaultModel: aiProviderDefaultModelInput?.value.trim() || '',
                    metadata: buildAiProviderMetadataFromForm(),
                };

                if (!payload.name) {
                    payload.name = aiProviderDefaults(aiProviderTypeInput?.value || 'ollama').name;
                }

                const data = await postStudio('list-ai-models-draft', payload);
                studioState.ai.providerModalModelOptions = Array.isArray(data.models) ? data.models : [];
                renderAiProviderModalModelOptions();

                if (aiProviderDefaultModelInput && !aiProviderDefaultModelInput.value.trim()) {
                    const fallback = data.recommendedModel || studioState.ai.providerModalModelOptions[0]?.id || '';
                    if (fallback) aiProviderDefaultModelInput.value = fallback;
                }

                if (aiProviderTestStatus) {
                    aiProviderTestStatus.textContent = data.recommendedModel
                        ? `Loaded ${studioState.ai.providerModalModelOptions.length} model(s). Recommended: ${data.recommendedModel}`
                        : `Loaded ${studioState.ai.providerModalModelOptions.length} model(s).`;
                }
            } catch (error) {
                studioState.ai.providerModalModelOptions = [];
                renderAiProviderModalModelOptions();
                if (aiProviderTestStatus) aiProviderTestStatus.textContent = error.message;
                window.Toast?.error(error.message);
            } finally {
                studioState.ai.providerModalLoadingModels = false;
                btnLoading(loadAiProviderModelsBtn, false);
            }
        }

        function populateAiProviderForm(provider = null) {
            if (!aiProviderForm) return;

            const preset = inferAiProviderPreset(provider);
            const defaults = aiProviderDefaults(preset);
            const metadata = provider?.metadata || {};
            const paths = metadata?.paths || {};

            if (aiProviderIdInput) aiProviderIdInput.value = provider?.id || '';
            if (aiProviderTypeInput) aiProviderTypeInput.value = preset;
            if (aiProviderTypeHiddenInput) aiProviderTypeHiddenInput.value = defaults.type;
            if (aiProviderNameInput) aiProviderNameInput.value = provider?.name || defaults.name;
            if (aiProviderBaseUrlInput) aiProviderBaseUrlInput.value = provider?.baseUrl || provider?.base_url || defaults.baseUrl;
            if (aiProviderApiKeyInput) aiProviderApiKeyInput.value = '';
            if (aiProviderApiKeyInput) {
                aiProviderApiKeyInput.placeholder = provider?.hasApiKey
                    ? 'Leave blank to keep the existing key'
                    : defaults.apiKeyPlaceholder;
            }
            if (aiProviderChatPathInput) aiProviderChatPathInput.value = paths.chat || defaults.chatPath || '';
            if (aiProviderModelsPathInput) {
                const modelsPath = Object.prototype.hasOwnProperty.call(paths, 'models')
                    ? (paths.models || '')
                    : (defaults.modelsPath || '');
                aiProviderModelsPathInput.value = modelsPath;
            }
            if (aiProviderRequestParamsInput) aiProviderRequestParamsInput.value = formatAiProviderJsonValue(metadata.requestParams);
            toggleAiProviderAdvancedFields(preset);
            if (aiProviderDefaultModelInput) aiProviderDefaultModelInput.value = provider?.defaultModel || provider?.default_model || '';
            if (aiProviderIsDefaultInput) {
                aiProviderIsDefaultInput.checked = provider
                    ? isAiProviderDefault(provider)
                    : studioState.ai.providers.length === 0;
            }
            if (aiProviderHint) aiProviderHint.innerHTML = defaults.hint;
            if (aiProviderManageSelect) aiProviderManageSelect.value = provider?.id || '';
            if (aiProviderTestStatus) aiProviderTestStatus.textContent = '';
            resetAiProviderModalModelOptions();
            markAiProviderFormBaseline();
            updateAiProviderActionButtons();
        }

        function openAiProviderModal() {
            if (!studioState.ai.enabled || !aiProviderModal) return;
            const provider = studioState.ai.providers.length > 0
                ? getAiProvider(studioState.ai.selectedProviderId)
                : null;
            populateAiProviderForm(provider || null);
            aiProviderModal.classList.add('active');
            syncModalOverflow();
            if (provider?.id) {
                loadAiProviderModalModels();
            }
            setTimeout(() => aiProviderNameInput?.focus(), 0);
        }

        function closeAiProviderModal() {
            aiProviderModal?.classList.remove('active');
            syncModalOverflow();
        }

        async function refreshAiProviders() {
            if (!studioState.ai.enabled) return;
            const data = await getStudio('list-ai-providers');
            studioState.ai.providers = Array.isArray(data.providers) ? data.providers : [];
            renderAiProviders();
            renderAiProviderManageOptions();
        }

        function handleAiChatJobSuccess(job) {
            const result = job?.result || {};
            resetAiDiffState();
            appendAiMessage('assistant', result.reply || 'I drafted a change plan.');
            studioState.ai.pendingPlan = {
                summary: result.summary || '',
                operations: Array.isArray(result.operations) ? result.operations : [],
                notes: Array.isArray(result.notes) ? result.notes : [],
                lint: Array.isArray(result.lint) ? result.lint : [],
            };
            if (result.model && aiModelInput && !aiModelInput.value.trim()) {
                aiModelInput.value = result.model;
            }
            studioState.ai.activeChatJobId = null;
            const lintCount = Array.isArray(result.lint) ? result.lint.length : 0;
            const blockingLintCount = Array.isArray(result.lint)
                ? result.lint.filter(item => ['danger', 'error', 'blocking'].includes(String(item?.severity || '').toLowerCase())).length
                : 0;
            setAiStatus(
                blockingLintCount > 0
                    ? 'Blocked risky plan'
                    : lintCount > 0
                        ? `Review ${lintCount} warning${lintCount === 1 ? '' : 's'}`
                        : (result.model ? `Model: ${result.model}` : 'Ready')
            );
            if (blockingLintCount > 0) {
                window.Toast?.error(
                    `Studio blocked ${blockingLintCount} dangerous AI change${blockingLintCount === 1 ? '' : 's'}. Review the diff or ask again.`
                );
            }
            renderAiMessages();
            renderAiPlan();
        }

        function handleAiChatJobError(message) {
            appendAiMessage('assistant', `I hit an error: ${message}`);
            studioState.ai.activeChatJobId = null;
            setAiStatus('Error');
            renderAiMessages();
            window.Toast?.error(message);
        }

        async function pollAiChatJob(jobId) {
            try {
                const data = await getStudio('get-ai-job', { id: jobId });
                const job = data.job || null;
                if (!job) throw new Error('AI job not found');

                if (job.status === 'completed') {
                    clearAiChatPoll();
                    handleAiChatJobSuccess(job);
                    return;
                }

                if (job.status === 'failed') {
                    clearAiChatPoll();
                    handleAiChatJobError(job.error || 'The AI job failed');
                    return;
                }

                setAiStatus(job.status === 'running' ? 'Thinking' : 'Queued');
                studioState.ai.chatPollTimer = setTimeout(() => pollAiChatJob(jobId), 1500);
            } catch (error) {
                clearAiChatPoll();
                handleAiChatJobError(error.message);
            } finally {
                if (!studioState.ai.activeChatJobId) {
                    studioState.ai.sending = false;
                    btnLoading($('ai-send-btn'), false);
                }
            }
        }

        async function pollAiCreateJob(jobId) {
            try {
                const data = await getStudio('get-ai-job', { id: jobId });
                const job = data.job || null;
                if (!job) throw new Error('AI job not found');

                if (job.status === 'completed') {
                    clearAiCreatePoll();
                    studioState.ai.creatingWorkspace = false;
                    studioState.ai.activeCreateJobId = null;
                    updateStarterAiState();
                    const result = job.result || {};
                    const createdWorkspace = result.workspace || null;
                    const lintCount = Array.isArray(result.lint) ? result.lint.length : 0;
                    const hasIssues = (result.validation && result.validation.success === false) || lintCount > 0;
                    if (starterAiStatus) {
                        starterAiStatus.textContent = lintCount > 0
                            ? `Workspace created with ${lintCount} AI review warning${lintCount === 1 ? '' : 's'} to inspect in Studio.`
                            : (hasIssues
                                ? 'Workspace created with validation issues to review in Studio.'
                                : (result.summary || 'Workspace created'));
                    }
                    if (createdWorkspace?.id) {
                        history.replaceState(null, '', `#workspace-${encodeURIComponent(createdWorkspace.id)}`);
                    }
                    closeWorkspaceModal();
                    window.Toast?.success(
                        lintCount > 0
                            ? `AI workspace created with ${lintCount} warning${lintCount === 1 ? '' : 's'} to review`
                            : (hasIssues ? 'AI workspace created with issues to review' : 'AI workspace created')
                    );
                    window.location.reload();
                    return;
                }

                if (job.status === 'failed') {
                    clearAiCreatePoll();
                    studioState.ai.creatingWorkspace = false;
                    studioState.ai.activeCreateJobId = null;
                    updateStarterAiState();
                    const message = job.error || 'The AI workspace job failed';
                    if (starterAiStatus) starterAiStatus.textContent = message;
                    window.Toast?.error(message);
                    btnLoading(starterAiCreateBtn, false);
                    return;
                }

                if (starterAiStatus) {
                    starterAiStatus.textContent = job.status === 'running'
                        ? 'Generating workspace files...'
                        : 'Queued. Waiting for worker...';
                }
                studioState.ai.createPollTimer = setTimeout(() => pollAiCreateJob(jobId), 1500);
            } catch (error) {
                clearAiCreatePoll();
                studioState.ai.creatingWorkspace = false;
                studioState.ai.activeCreateJobId = null;
                updateStarterAiState();
                if (starterAiStatus) starterAiStatus.textContent = error.message;
                window.Toast?.error(error.message);
                btnLoading(starterAiCreateBtn, false);
            }
        }

        async function sendAiPrompt() {
            if (!studioState.ai.enabled) return;
            if (studioState.ai.sending) return;
            const workspace = currentWorkspace();
            if (!workspace) {
                window.Toast?.error('Select a workspace before using AI');
                return;
            }

            const prompt = aiPromptInput?.value.trim() || '';
            if (!prompt) {
                window.Toast?.error('Enter a prompt first');
                return;
            }

            const providerId = aiProviderSelect?.value || studioState.ai.selectedProviderId;
            if (!providerId) {
                window.Toast?.error('Add an AI provider first');
                return;
            }

            const target = getAiTargetConfig();
            const domTarget = getAiDomTargetConfig();
            if (target.hasLineRange && !target.targetFilePath) {
                window.Toast?.error('Pick a target file or open a file before targeting specific lines');
                return;
            }

            const model = aiModelInput?.value.trim() || '';
            appendAiMessage('user', prompt);
            renderAiMessages();
            aiPromptInput.value = '';
            studioState.ai.sending = true;
            studioState.ai.activeChatJobId = null;
            clearAiChatPoll();
            btnLoading($('ai-send-btn'), true);
            setAiStatus('Queued');

            try {
                const data = await postStudio('start-ai-chat-job', {
                    id: workspace.id,
                    providerId,
                    model,
                    currentFilePath: studioState.selectedFile?.path || '',
                    targetFilePath: target.targetFilePath,
                    targetStartLine: target.targetStartLine,
                    targetEndLine: target.targetEndLine,
                    targetSelectedText: target.targetSelectedText,
                    targetDomSelection: domTarget,
                    messages: studioState.ai.messages,
                });
                const job = data.job || null;
                if (!job?.id) throw new Error('AI job could not be created');
                studioState.ai.activeChatJobId = job.id;
                setAiStatus('Queued');
                studioState.ai.chatPollTimer = setTimeout(() => pollAiChatJob(job.id), 500);
            } catch (error) {
                appendAiMessage('assistant', `I hit an error: ${error.message}`);
                setAiStatus('Error');
                renderAiMessages();
                window.Toast?.error(error.message);
                studioState.ai.sending = false;
                btnLoading($('ai-send-btn'), false);
            }
        }

        async function revertAiMessage(messageId) {
            if (!studioState.ai.enabled || !studioState.selectedWorkspaceId || studioState.ai.applying) return;
            if (studioState.dirty) {
                window.Toast?.error('Save or discard your current file changes before reverting AI edits');
                return;
            }

            const message = getAiMessage(messageId);
            const revertOperations = Array.isArray(message?.revertOperations) ? message.revertOperations : [];
            if (!message || !revertOperations.length || message.reverted) return;

            const confirmed = window.confirm(
                `Revert ${revertOperations.length} AI-applied file change${revertOperations.length === 1 ? '' : 's'}?`
            );
            if (!confirmed) {
                return;
            }

            studioState.ai.applying = true;
            studioState.ai.revertingMessageId = String(messageId);
            setAiStatus('Reverting');
            renderAiMessages();
            renderAiPlan();

            try {
                const data = await postStudio('ai-apply-changes', {
                    id: studioState.selectedWorkspaceId,
                    operations: revertOperations,
                });

                message.reverted = true;
                message.revertedAt = new Date().toISOString();
                appendAiMessage('assistant', `Reverted ${data.operations?.length || revertOperations.length} file change(s).`);

                renderAiMessages();
                await loadWorkspaceFiles(studioState.selectedWorkspaceId);

                const firstPath = data.operations?.[0]?.path;
                if (firstPath) {
                    await openWorkspaceFile(firstPath, true, true);
                }

                try {
                    await previewWorkspace(studioState.selectedWorkspaceId, false, true);
                } catch (previewError) {
                    window.Toast?.error(previewError.message);
                }

                if (data.validation) {
                    renderValidationResult(data.validation);
                }

                setAiStatus('Reverted');
                window.Toast?.success('AI changes reverted');
            } catch (error) {
                setAiStatus('Revert failed');
                window.Toast?.error(error.message);
            } finally {
                studioState.ai.applying = false;
                studioState.ai.revertingMessageId = null;
                renderAiMessages();
                renderAiPlan();
            }
        }

        async function applyAiPlan() {
            if (!studioState.ai.enabled || !studioState.ai.pendingPlan?.operations?.length || !studioState.selectedWorkspaceId) return;
            if (studioState.dirty) {
                window.Toast?.error('Save or discard your current file changes before applying AI edits');
                return;
            }

            const lintWarnings = Array.isArray(studioState.ai.pendingPlan?.lint) ? studioState.ai.pendingPlan.lint : [];
            const blockingLintWarnings = lintWarnings.filter(item => ['danger', 'error', 'blocking'].includes(String(item?.severity || '').toLowerCase()));
            if (blockingLintWarnings.length) {
                window.Toast?.error(
                    blockingLintWarnings[0]?.message
                    || 'Studio blocked this AI plan because it looks destructive for the selected target.'
                );
                return;
            }
            if (lintWarnings.length) {
                const previewLines = lintWarnings.slice(0, 2).map(item => {
                    const location = `${item.path || 'file'}${item.line ? `:${item.line}` : ''}`;
                    return `- ${location}: ${item.title || item.message || 'Warning'}`;
                });
                const extraLine = lintWarnings.length > 2
                    ? `\n- ...and ${lintWarnings.length - 2} more`
                    : '';
                const confirmed = window.confirm(
                    `AI review flagged ${lintWarnings.length} warning${lintWarnings.length === 1 ? '' : 's'}.\n\n`
                    + `${previewLines.join('\n')}${extraLine}\n\n`
                    + 'Apply these changes anyway?'
                );
                if (!confirmed) {
                    return;
                }
            }

            studioState.ai.applying = true;
            btnLoading(aiApplyBtn, true);
            setAiStatus('Applying');

            try {
                const data = await postStudio('ai-apply-changes', {
                    id: studioState.selectedWorkspaceId,
                    operations: studioState.ai.pendingPlan.operations,
                });
                appendAiMessage('assistant', `Applied ${data.operations?.length || 0} file change(s).`, {
                    revertOperations: Array.isArray(data.revertOperations) ? data.revertOperations : [],
                    appliedOperations: Array.isArray(data.operations) ? data.operations : [],
                });
                studioState.ai.pendingPlan = null;
                resetAiDiffState();
                closeAiDiffModal();
                renderAiMessages();
                renderAiPlan();
                await loadWorkspaceFiles(studioState.selectedWorkspaceId);

                const firstPath = data.operations?.[0]?.path;
                if (firstPath) {
                    await openWorkspaceFile(firstPath, true, true);
                }

                try {
                    await previewWorkspace(studioState.selectedWorkspaceId, false, true);
                } catch (previewError) {
                    window.Toast?.error(previewError.message);
                }

                if (data.validation) {
                    renderValidationResult(data.validation);
                }

                setAiStatus('Applied');
                window.Toast?.success('AI changes applied');
            } catch (error) {
                setAiStatus('Apply failed');
                window.Toast?.error(error.message);
            } finally {
                studioState.ai.applying = false;
                btnLoading(aiApplyBtn, false);
                renderAiMessages();
                renderAiPlan();
            }
        }

        // ── Toolbar badge ──
        function renderToolbarBadge(workspace) {
            if (!workspace) {
                toolbarPreviewBadge.style.display = 'none';
                return;
            }
            const status = workspace.preview_status || 'idle';
            toolbarPreviewBadge.style.display = 'inline-flex';
            toolbarPreviewBadge.className = `toolbar-badge ${status}`;
            const icon = status === 'ready' ? 'fa-eye' : (status === 'needs_runtime' ? 'fa-cubes' : 'fa-circle-info');
            toolbarPreviewBadge.innerHTML = `<i class="fas ${icon}"></i><span>${escapeHtml(status.replaceAll('_',' '))}</span>`;
        }

        // ── Toolbar button states ──
        function updateToolbarButtons() {
            const workspace = currentWorkspace();
            const hasWorkspace = !!workspace;
            $('toolbar-validate-btn').disabled = !hasWorkspace;
            $('toolbar-sync-preview-btn').disabled = !hasWorkspace;
            $('toolbar-open-preview-btn').disabled = !hasWorkspace;
            $('toolbar-delete-btn').disabled = !hasWorkspace;
            if (toolbarInstallAppBtn) {
                toolbarInstallAppBtn.disabled = !hasWorkspace || studioState.installingWorkspace || studioState.publishingWorkspace;
                toolbarInstallAppBtn.title = hasWorkspace
                    ? `${getWorkspaceInstallActionLabel(workspace)} from the selected workspace`
                    : 'Select a workspace first';
            }
            if (toolbarInstallAppLabel) {
                toolbarInstallAppLabel.textContent = getWorkspaceInstallActionLabel(workspace);
            }
            if (toolbarPublishAppBtn) {
                toolbarPublishAppBtn.disabled = !hasWorkspace || studioState.installingWorkspace || studioState.publishingWorkspace;
                toolbarPublishAppBtn.title = hasWorkspace
                    ? 'Publish the selected workspace app to the local marketplace'
                    : 'Select a workspace first';
            }
            if (toggleFilesBtn) {
                toggleFilesBtn.hidden = !hasWorkspace || studioState.displayMode !== 'manual';
            }
        }

        async function ensureSavedBeforeRelease(actionLabel) {
            if (!studioState.dirty) return true;

            const confirmed = window.confirm(`You have unsaved changes. Save them before ${actionLabel.toLowerCase()}?`);
            if (!confirmed) {
                return false;
            }

            await saveCurrentFile();
            if (studioState.dirty) {
                window.Toast?.error(`Save your changes before ${actionLabel.toLowerCase()}`);
                return false;
            }

            return true;
        }

        // ── App switcher ──
        function renderAppSwitcher(workspace) {
            const apps = Array.isArray(workspace?.discoveredApps) ? workspace.discoveredApps : [];
            if (apps.length <= 1) {
                appSwitcherBar.classList.remove('visible');
                appSwitcherSelect.innerHTML = '';
                return;
            }
            appSwitcherBar.classList.add('visible');
            appSwitcherHint.textContent = `${apps.length} apps detected —`;
            appSwitcherSelect.innerHTML = apps.map(app => {
                const name = app.name || app.id || app.path;
                return `<option value="${escapeHtml(app.path)}">${escapeHtml(name)} (${escapeHtml(app.path)})</option>`;
            }).join('');
            appSwitcherSelect.value = workspace.app_path || apps[0].path;
        }

        // ── Layout state ──
        function showIDE() {
            studioEmpty.style.display = 'none';
            editorPreviewSplit.style.display = 'flex';
            filePanel.classList.toggle('collapsed', !studioState.filePanelOpen);
            applyDisplayMode();
        }

        function showEmpty() {
            studioEmpty.style.display = 'flex';
            filePanel.classList.add('collapsed');
            editorPreviewSplit.style.display = 'none';
            appSwitcherBar.classList.remove('visible');
            toolbarPreviewBadge.style.display = 'none';
            validationBox.classList.remove('visible');
            closeAiTargetModal();
            resetAiConversation(true);
            resetAiTarget();
            applyDisplayMode();
        }

        function toggleFilePanel() {
            if (studioState.displayMode !== 'manual') return;
            studioState.filePanelOpen = !studioState.filePanelOpen;
            filePanel.classList.toggle('collapsed', !studioState.filePanelOpen);
            toggleFilesBtn?.classList.toggle('active', studioState.filePanelOpen);
        }

        // ── Preview ──
        function renderPreviewState(workspace) {
            if (!workspace) return;
            previewStatusText.textContent = workspace.preview_status === 'ready'
                ? 'Ready'
                : (workspace.preview_status === 'needs_runtime' ? 'Needs runtime' : 'Not synced');

            if (workspace.preview_status === 'ready' && workspace.previewUrl) {
                studioState.previewUrl = workspace.previewUrl;
                previewPlaceholder.classList.add('hidden');
                previewFrame.classList.remove('hidden');
                previewAddressBar.classList.add('visible');
                if (previewPageInput) previewPageInput.value = 'index.php';
                if (previewFrame.dataset.baseUrl !== workspace.previewUrl) {
                    previewFrame.dataset.baseUrl = workspace.previewUrl;
                    previewFrame.src = cacheBustedUrl(workspace.previewUrl);
                }
            } else {
                studioState.previewUrl = null;
                stopPreviewElementPicker();
                previewFrame.classList.add('hidden');
                previewFrame.src = 'about:blank';
                delete previewFrame.dataset.baseUrl;
                previewAddressBar.classList.remove('visible');
                previewPlaceholder.classList.remove('hidden');
                previewPlaceholder.textContent = workspace.preview_status === 'needs_runtime'
                    ? 'Custom runtime — preview not available yet.'
                    : 'Sync preview to load the app here.';
            }
        }

        // ── File tree ──
        function iconForTreeItem(item) {
            if (item.isDirectory || item.type === 'directory') return 'fa-folder';
            const ext = (item.extension || '').toLowerCase();
            if (['php'].includes(ext)) return 'fa-file-code';
            if (['yaml','yml','json','toml','md'].includes(ext)) return 'fa-file-lines';
            if (['js','ts','tsx','jsx','css','html'].includes(ext)) return 'fa-file-code';
            if (['png','jpg','jpeg','gif','svg'].includes(ext)) return 'fa-file-image';
            return 'fa-file';
        }

        function filterTreeItems(items, query) {
            if (!query) return items;
            return items.reduce((acc, item) => {
                if (item.isDirectory || item.type === 'directory') {
                    const filtered = filterTreeItems(item.children || [], query);
                    if (filtered.length > 0) acc.push({ ...item, children: filtered });
                } else if (item.name.toLowerCase().includes(query) || item.path.toLowerCase().includes(query)) {
                    acc.push(item);
                }
                return acc;
            }, []);
        }

        function renderTreeItems(items, depth) {
            return items.map(item => {
                const isDir = item.isDirectory || item.type === 'directory';
                const isExpanded = isDir && studioState.expandedDirs.has(item.path);
                const isActive = studioState.selectedFile?.path === item.path;
                const disclosure = isDir
                    ? `<span class="disclosure"><i class="fas ${isExpanded?'fa-chevron-down':'fa-chevron-right'}"></i></span>`
                    : '<span class="disclosure"></span>';
                const icon = iconForTreeItem(item);
                const row = `<button type="button" class="tree-row ${isDir?'directory':''} ${isActive?'active':''}" style="padding-left:${6+(depth*14)}px" onclick="${isDir?`toggleDirectory('${escapeJs(item.path)}')`:`openWorkspaceFile('${escapeJs(item.path)}')`}">${disclosure}<i class="fas ${icon}"></i><span class="file-name">${escapeHtml(item.name)}</span></button>`;
                if (isDir && isExpanded && Array.isArray(item.children) && item.children.length > 0) {
                    return row + renderTreeItems(item.children, depth + 1);
                }
                return row;
            }).join('');
        }

        function renderFileTree() {
            if (studioState.loadingFiles) {
                fileTreeEl.innerHTML = '<div class="editor-placeholder">Loading...</div>';
                return;
            }
            if (!studioState.selectedWorkspaceId) {
                fileTreeEl.innerHTML = '<div class="editor-placeholder">No workspace selected.</div>';
                return;
            }
            if (!Array.isArray(studioState.fileTree) || studioState.fileTree.length === 0) {
                fileTreeEl.innerHTML = '<div class="editor-placeholder">No files yet.</div>';
                return;
            }
            const q = ($('file-tree-filter')?.value || '').toLowerCase().trim();
            const tree = q ? filterTreeItems(studioState.fileTree, q) : studioState.fileTree;
            if (q && tree.length === 0) {
                fileTreeEl.innerHTML = '<div class="editor-placeholder">No matches.</div>';
                return;
            }
            fileTreeEl.innerHTML = renderTreeItems(tree, 0);
        }

        // ── Line numbers ──
        function updateLineNumbers() {
            if (!editorLineNumbers || !editorTextarea) return;
            const n = editorTextarea.value.split('\n').length;
            const nums = [];
            for (let i = 1; i <= n; i++) nums.push(i);
            editorLineNumbers.textContent = nums.join('\n');
            editorLineNumbers.scrollTop = editorTextarea.scrollTop;
        }

        // ── Editor surface ──
        function openEditorText(file) {
            studioState.selectedFile = file;
            studioState.currentFileWorkspaceId = studioState.selectedWorkspaceId;
            studioState.dirty = false;
            currentFilePathEl.textContent = file.path;
            editorPlaceholder.classList.add('hidden');
            binaryPlaceholder.classList.add('hidden');
            editorTextareaWrap.classList.remove('hidden');
            editorTextarea.value = file.content || '';
            editorTextarea.readOnly = false;
            saveFileBtn.disabled = false;
            deleteFileBtn.disabled = false;
            setSaveState('', 'Saved');
            updateLineNumbers();
            renderAiSelectionHint();
            renderFileTree();
        }

        function openBinaryPlaceholder(file) {
            studioState.selectedFile = file;
            studioState.currentFileWorkspaceId = studioState.selectedWorkspaceId;
            studioState.dirty = false;
            currentFilePathEl.textContent = file.path;
            editorPlaceholder.classList.add('hidden');
            editorTextareaWrap.classList.add('hidden');
            binaryPlaceholder.classList.remove('hidden');
            saveFileBtn.disabled = true;
            deleteFileBtn.disabled = false;
            setSaveState('', 'Read only');
            renderAiSelectionHint();
            renderFileTree();
        }

        function clearEditorSurface() {
            studioState.selectedFile = null;
            studioState.currentFileWorkspaceId = null;
            studioState.dirty = false;
            currentFilePathEl.textContent = 'No file selected';
            editorTextarea.value = '';
            editorTextareaWrap.classList.add('hidden');
            binaryPlaceholder.classList.add('hidden');
            editorPlaceholder.classList.remove('hidden');
            saveFileBtn.disabled = true;
            deleteFileBtn.disabled = true;
            setSaveState('', 'No changes');
            renderAiSelectionHint();
            renderFileTree();
        }

        // ── Workspace file loading ──
        function initializeExpandedDirectories(workspace) {
            const next = new Set();
            if (workspace?.app_path && workspace.app_path !== '.') {
                let current = '';
                for (const seg of workspace.app_path.split('/')) {
                    current = current ? `${current}/${seg}` : seg;
                    next.add(current);
                }
            }
            studioState.expandedDirs = next;
        }

        function preferredFilePathForWorkspace(workspace) {
            if (!workspace) return null;
            const candidates = [];
            if (workspace.app_path) {
                const base = workspace.app_path === '.' ? '' : `${workspace.app_path}/`;
                candidates.push(`${base}manifest.yaml`, `${base}index.php`);
            }
            candidates.push('doki.project.yaml');
            for (const c of candidates) {
                if (findTreeItemByPath(studioState.fileTree, c)) return c;
            }
            return findFirstTextFilePath(studioState.fileTree);
        }

        function findFirstTextFilePath(items) {
            for (const item of items || []) {
                if (item.isDirectory && Array.isArray(item.children)) {
                    const p = findFirstTextFilePath(item.children);
                    if (p) return p;
                } else if (!item.isDirectory) return item.path;
            }
            return null;
        }

        function findTreeItemByPath(items, path) {
            for (const item of items || []) {
                if (item.path === path) return item;
                if (item.isDirectory && Array.isArray(item.children)) {
                    const c = findTreeItemByPath(item.children, path);
                    if (c) return c;
                }
            }
            return null;
        }

        async function loadWorkspaceFiles(workspaceId) {
            studioState.loadingFiles = true;
            studioState.loadingWorkspaceId = workspaceId;
            renderFileTree();
            try {
                const response = await fetch(`${studioApiBase}?action=list-files&id=${encodeURIComponent(workspaceId)}`);
                const data = await response.json();
                if (!response.ok || !data.success) throw new Error(data.error || 'Failed to load files');
                if (studioState.loadingWorkspaceId !== workspaceId || studioState.selectedWorkspaceId !== workspaceId) return;
                studioState.fileTree = data.files || [];
                renderAiTargetModalFileList();
                initializeExpandedDirectories(currentWorkspace());
                renderFileTree();
                const preferred = preferredFilePathForWorkspace(currentWorkspace());
                if (preferred) await openWorkspaceFile(preferred, true, true);
                else clearEditorSurface();
            } catch (error) {
                if (studioState.loadingWorkspaceId !== workspaceId || studioState.selectedWorkspaceId !== workspaceId) return;
                studioState.fileTree = [];
                renderAiTargetModalFileList();
                renderFileTree();
                clearEditorSurface();
                window.Toast?.error(error.message);
            } finally {
                if (studioState.loadingWorkspaceId === workspaceId) {
                    studioState.loadingFiles = false;
                    studioState.loadingWorkspaceId = null;
                    renderFileTree();
                }
            }
        }

        async function openWorkspaceFile(path, silent = false, skipDirtyCheck = false) {
            const workspace = currentWorkspace();
            if (!workspace) return;
            if (!skipDirtyCheck && studioState.selectedFile?.path !== path && !confirmDiscardChanges()) return;
            try {
                const id = workspace.id;
                const response = await fetch(`${studioApiBase}?action=get-file&id=${encodeURIComponent(id)}&path=${encodeURIComponent(path)}`);
                const data = await response.json();
                if (!response.ok || !data.success) throw new Error(data.error || 'Failed to load file');
                if (studioState.selectedWorkspaceId !== id) return;
                if (data.file?.isBinary) openBinaryPlaceholder(data.file);
                else openEditorText(data.file);
            } catch (error) {
                clearEditorSurface();
                if (!silent) window.Toast?.error(error.message);
            }
        }

        // ── File operations ──
        async function saveCurrentFile() {
            if (!studioState.selectedFile || studioState.selectedFile.isBinary || !studioState.selectedWorkspaceId) return;
            if (studioState.savingFile) return;
            studioState.savingFile = true;
            btnLoading(saveFileBtn, true);
            setSaveState('dirty', 'Saving...');
            try {
                await postStudio('save-file', { id: studioState.selectedWorkspaceId, path: studioState.selectedFile.path, content: editorTextarea.value });
                studioState.selectedFile.content = editorTextarea.value;
                studioState.dirty = false;
                setSaveState('saved', 'Saved');
                window.Toast?.success('File saved');
                if ($('auto-preview-toggle').checked) {
                    try { await previewWorkspace(studioState.selectedWorkspaceId, false, true); }
                    catch (e) { window.Toast?.error(e.message); }
                }
            } catch (error) {
                setSaveState('dirty', 'Save failed');
                window.Toast?.error(error.message);
            } finally {
                studioState.savingFile = false;
                btnLoading(saveFileBtn, false);
                saveFileBtn.disabled = !studioState.selectedFile || studioState.selectedFile.isBinary;
            }
        }

        async function createWorkspaceFile() {
            const workspace = currentWorkspace();
            if (!workspace || !confirmDiscardChanges()) return;
            const base = workspace.app_path && workspace.app_path !== '.' ? `${workspace.app_path}/` : '';
            const path = window.prompt('New file path', base);
            if (!path) return;
            try {
                await postStudio('create-file', { id: workspace.id, path, content: '' });
                window.Toast?.success('File created');
                await loadWorkspaceFiles(workspace.id);
                await openWorkspaceFile(path);
            } catch (error) { window.Toast?.error(error.message); }
        }

        async function deleteCurrentFile() {
            const workspace = currentWorkspace();
            if (!workspace || !studioState.selectedFile) return;
            if (!window.confirm(`Delete ${studioState.selectedFile.path}?`)) return;
            try {
                await postStudio('delete-file', { id: workspace.id, path: studioState.selectedFile.path });
                window.Toast?.success('File deleted');
                clearEditorSurface();
                await loadWorkspaceFiles(workspace.id);
                if ($('auto-preview-toggle').checked) {
                    try { await previewWorkspace(workspace.id, false, true); }
                    catch (e) { window.Toast?.error(e.message); }
                }
            } catch (error) { window.Toast?.error(error.message); }
        }

        // ── Preview ──
        async function previewWorkspace(workspaceId, openInTab = false, silent = false) {
            try {
                const data = await postStudio('preview-workspace', { id: workspaceId });
                const workspace = getWorkspace(workspaceId);
                if (workspace) { workspace.preview_status = 'ready'; workspace.previewUrl = data.url; }
                if (studioState.selectedWorkspaceId === workspaceId) {
                    renderToolbarBadge(workspace);
                    studioState.previewUrl = data.url;
                    previewPlaceholder.classList.add('hidden');
                    previewFrame.classList.remove('hidden');
                    previewAddressBar.classList.add('visible');
                    previewFrame.dataset.baseUrl = data.url;
                    previewFrame.src = cacheBustedUrl(data.url);
                    previewStatusText.textContent = 'Ready';
                    if (previewPageInput) previewPageInput.value = 'index.php';
                }
                if (openInTab) window.open(data.url, '_blank', 'noopener');
                if (!silent) window.Toast?.success('Preview synced');
                return data;
            } catch (error) {
                const workspace = getWorkspace(workspaceId);
                if (workspace && /custom-runtime|custom runtime/i.test(error.message)) {
                    workspace.preview_status = 'needs_runtime';
                    workspace.previewUrl = null;
                    if (studioState.selectedWorkspaceId === workspaceId) {
                        renderToolbarBadge(workspace);
                        stopPreviewElementPicker();
                        previewFrame.classList.add('hidden');
                        previewFrame.src = 'about:blank';
                        delete previewFrame.dataset.baseUrl;
                        previewAddressBar.classList.remove('visible');
                        previewPlaceholder.classList.remove('hidden');
                        previewPlaceholder.textContent = 'Custom runtime — preview not available yet.';
                        previewStatusText.textContent = 'Needs runtime';
                    }
                }
                if (!silent) window.Toast?.error(error.message);
                throw error;
            }
        }

        // ── Validate ──
        async function validateWorkspace(workspaceId) {
            const btn = $('toolbar-validate-btn');
            btnLoading(btn, true);
            try {
                const response = await fetch(`${studioApiBase}?action=validate-workspace&id=${encodeURIComponent(workspaceId)}`, {
                    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: workspaceId })
                });
                const data = await response.json();
                renderValidationResult(data);
                if (data.success) window.Toast?.success('Validated');
                else window.Toast?.error('Validation found issues');
                return data;
            } catch (error) { window.Toast?.error(error.message); throw error; }
            finally { btnLoading(btn, false); }
        }

        function renderValidationResult(data) {
            const errors = Array.isArray(data.errors) ? data.errors : [];
            const warnings = Array.isArray(data.warnings) ? data.warnings : [];
            const parts = [`<h3>${data.success ? 'Validation passed' : 'Issues found'}</h3>`];
            if (errors.length > 0) parts.push(`<div class="validation-group"><div class="validation-group-title error">Errors</div><ul class="validation-list">${errors.map(e=>`<li>${escapeHtml(e)}</li>`).join('')}</ul></div>`);
            if (warnings.length > 0) parts.push(`<div class="validation-group"><div class="validation-group-title warning">Warnings</div><ul class="validation-list">${warnings.map(w=>`<li>${escapeHtml(w)}</li>`).join('')}</ul></div>`);
            if (errors.length === 0 && warnings.length === 0) parts.push('<p style="margin:0;">No issues found.</p>');
            validationBox.innerHTML = parts.join('');
            validationBox.classList.add('visible');
        }

        // ── Delete workspace ──
        async function deleteWorkspace(workspaceId) {
            if (!window.confirm('Delete this workspace and its preview copy?')) return;
            try {
                await postStudio('delete-workspace', { id: workspaceId });
                window.Toast?.success('Workspace deleted');
                window.location.reload();
            } catch (error) { window.Toast?.error(error.message); }
        }

        async function publishWorkspaceApp() {
            const workspace = currentWorkspace();
            if (!workspace) return;
            if (!(await ensureSavedBeforeRelease('publishing'))) return;

            studioState.publishingWorkspace = true;
            updateToolbarButtons();
            btnLoading(toolbarPublishAppBtn, true);

            try {
                const data = await postStudio('publish-workspace-app', { id: workspace.id });
                const release = data.release || {};
                window.Toast?.success(`Published ${release.app_id || workspace.app_id || 'app'} to the Apps marketplace`);
            } catch (error) {
                window.Toast?.error(error.message);
            } finally {
                studioState.publishingWorkspace = false;
                btnLoading(toolbarPublishAppBtn, false);
                updateToolbarButtons();
            }
        }

        async function installWorkspaceApp() {
            const workspace = currentWorkspace();
            if (!workspace) return;
            if (!(await ensureSavedBeforeRelease(getWorkspaceInstallActionLabel(workspace)))) return;

            studioState.installingWorkspace = true;
            updateToolbarButtons();
            btnLoading(toolbarInstallAppBtn, true);

            try {
                const data = await postStudio('install-workspace-app', { id: workspace.id });
                const appId = data.appId || data.release?.app_id || workspace.app_id || workspace.id;
                workspace.linked_installed_app_id = appId;
                if (data.release?.app_id) {
                    workspace.app_id = data.release.app_id;
                }
                updateToolbarButtons();
                const action = data.action === 'updated' ? 'updated' : 'installed';
                window.Toast?.success(`App ${action}: ${appId}`);
            } catch (error) {
                window.Toast?.error(error.message);
            } finally {
                studioState.installingWorkspace = false;
                btnLoading(toolbarInstallAppBtn, false);
                updateToolbarButtons();
            }
        }

        // ── App switcher ──
        async function setWorkspaceAppSelection(appPath) {
            const workspace = currentWorkspace();
            if (!workspace || !appPath || appPath === workspace.app_path) return;
            if (!confirmDiscardChanges()) { renderAppSwitcher(workspace); return; }
            try {
                const data = await postStudio('set-workspace-app', { id: workspace.id, appPath });
                upsertWorkspace(data.workspace);
                validationBox.classList.remove('visible');
                validationBox.innerHTML = '';
                renderToolbarBadge(data.workspace);
                renderPreviewState(data.workspace);
                renderAppSwitcher(data.workspace);
                clearEditorSurface();
                await loadWorkspaceFiles(workspace.id);
                try {
                    await previewWorkspace(workspace.id, false, true);
                } catch (previewError) {
                    window.Toast?.error(previewError.message);
                }
                window.Toast?.success('Active app updated');
            } catch (error) { renderAppSwitcher(workspace); window.Toast?.error(error.message); }
        }

        // ── Select workspace ──
        async function selectWorkspace(workspaceId, updateHash = true) {
            if (workspaceId === studioState.selectedWorkspaceId) return;
            if (!confirmDiscardChanges()) {
                workspaceSelect.value = studioState.selectedWorkspaceId || '';
                return;
            }
            if (updateHash) history.replaceState(null, '', `#workspace-${encodeURIComponent(workspaceId)}`);
            studioState.selectedWorkspaceId = workspaceId;
            studioState.selectedFile = null;
            studioState.currentFileWorkspaceId = null;
            studioState.dirty = false;
            validationBox.classList.remove('visible');
            validationBox.innerHTML = '';

            const workspace = currentWorkspace();
            workspaceSelect.value = workspaceId;
            showIDE();
            updateToolbarButtons();
            renderToolbarBadge(workspace);
            renderAppSwitcher(workspace);
            renderPreviewState(workspace);
            closeAiTargetModal();
            resetAiConversation(true);
            resetAiTarget();
            setAiStatus(studioState.ai.selectedProviderId ? 'Ready' : 'No provider');
            clearEditorSurface();
            await loadWorkspaceFiles(workspaceId);
            try {
                await previewWorkspace(workspaceId, false, true);
            } catch (previewError) {
                window.Toast?.error(previewError.message);
            }
        }

        function toggleDirectory(path) {
            if (studioState.expandedDirs.has(path)) studioState.expandedDirs.delete(path);
            else studioState.expandedDirs.add(path);
            renderFileTree();
        }

        // ── Preview address bar ──
        function navigatePreviewToPage() {
            const page = (previewPageInput?.value || '').trim();
            if (!studioState.previewUrl || !page) return;
            const baseUrl = studioState.previewUrl.replace(/\/[^\/]*$/, '/');
            previewFrame.src = cacheBustedUrl(baseUrl + page);
        }

        function handlePreviewFrameLoad() {
            if (!previewFrame || previewFrame.classList.contains('hidden')) {
                stopPreviewElementPicker();
                return;
            }

            if (previewPageInput) {
                previewPageInput.value = getPreviewCurrentPagePath();
            }

            if (studioState.ai.pickerActive) {
                attachPreviewElementPicker();
            } else {
                applyStoredPreviewElementSelection();
                updatePreviewElementPickerButton();
            }
        }

        // ── Modal ──
        function setWorkspaceModalMode(mode) {
            const isImport = mode === 'import';
            workspaceCreatePanel?.classList.toggle('hidden', isImport);
            workspaceImportPanel?.classList.toggle('hidden', !isImport);
            document.querySelectorAll('[data-workspace-modal-mode-option]').forEach(opt => {
                opt.classList.toggle('active', opt.dataset.workspaceModalModeOption === mode);
            });
            document.querySelectorAll('input[name="workspaceModalMode"]').forEach(inp => { inp.checked = inp.value === mode; });
        }

        function openWorkspaceModal(mode = 'create') {
            setWorkspaceModalMode(mode);
            workspaceModal?.classList.add('active');
            syncModalOverflow();
            renderStarterAiProviders();
            updateStarterAiState();
            if (studioState.ai.enabled && mode === 'create') {
                const starterProviderId = starterAiProviderSelect?.value || studioState.ai.starterSelectedProviderId || getDefaultAiProvider()?.id || '';
                if (starterProviderId) {
                    loadStarterAiModels(starterProviderId, true);
                }
            }
            setTimeout(() => {
                (mode === 'import' ? $('repo-name') : (starterAiPromptInput || $('starter-name')))?.focus();
            }, 0);
        }

        function closeWorkspaceModal() {
            workspaceModal?.classList.remove('active');
            syncModalOverflow();
        }

        function setStarterMode(mode) {
            starterAdvancedFields?.classList.toggle('hidden', mode !== 'advanced');
            document.querySelectorAll('[data-mode-option]').forEach(opt => {
                opt.classList.toggle('active', opt.dataset.modeOption === mode);
            });
            if (mode !== 'advanced' && starterIconCustomInput?.value.trim()) {
                starterIconCustomInput.value = '';
                syncStarterCustomIcon();
            }
        }

        function syncStarterColor() {
            if (starterColorInput && starterColorValue) starterColorValue.textContent = starterColorInput.value.toUpperCase();
        }

        function starterIconButtons() {
            return Array.from(document.querySelectorAll('#starter-icon-picker .icon-choice'));
        }

        function renderStarterIconPreview(icon, label, selectedPreset = null) {
            if (!starterIconInput) return;
            starterIconInput.value = icon;
            if (selectedPreset !== null) starterIconInput.dataset.preset = selectedPreset;
            starterIconPreviewGlyph.className = `fas ${icon}`;
            starterIconPreviewLabel.textContent = label;
            starterIconPreviewValue.textContent = icon;
            starterIconButtons().forEach(btn => {
                const isActive = selectedPreset !== null && btn.dataset.icon === selectedPreset;
                btn.classList.toggle('active', isActive);
                btn.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            });
        }

        function selectStarterPresetIcon(icon, label) {
            if (starterIconCustomInput) starterIconCustomInput.value = '';
            renderStarterIconPreview(icon, label, icon);
        }

        function syncStarterCustomIcon() {
            const custom = starterIconCustomInput?.value.trim() || '';
            if (custom !== '') { renderStarterIconPreview(custom, 'Custom icon'); return; }
            const preset = starterIconInput?.dataset.preset || 'fa-sparkles';
            const btn = starterIconButtons().find(b => b.dataset.icon === preset);
            renderStarterIconPreview(preset, btn?.dataset.label || 'Selected icon', preset);
        }

        // ── Event listeners ──

        // Workspace selector
        workspaceSelect?.addEventListener('change', (e) => {
            const id = e.target.value;
            if (id) selectWorkspace(id);
            else {
                studioState.selectedWorkspaceId = null;
                showEmpty();
                updateToolbarButtons();
            }
        });

        // Toolbar buttons
        toggleFilesBtn?.addEventListener('click', toggleFilePanel);
        $('toolbar-validate-btn')?.addEventListener('click', () => { if (studioState.selectedWorkspaceId) validateWorkspace(studioState.selectedWorkspaceId); });
        $('toolbar-sync-preview-btn')?.addEventListener('click', () => { if (studioState.selectedWorkspaceId) previewWorkspace(studioState.selectedWorkspaceId, false); });
        $('toolbar-open-preview-btn')?.addEventListener('click', () => { if (studioState.selectedWorkspaceId) previewWorkspace(studioState.selectedWorkspaceId, true); });
        toolbarInstallAppBtn?.addEventListener('click', installWorkspaceApp);
        toolbarPublishAppBtn?.addEventListener('click', publishWorkspaceApp);
        $('toolbar-add-btn')?.addEventListener('click', () => openWorkspaceModal('create'));
        $('toolbar-delete-btn')?.addEventListener('click', () => { if (studioState.selectedWorkspaceId) deleteWorkspace(studioState.selectedWorkspaceId); });
        displayModeManualBtn?.addEventListener('click', () => setDisplayMode('manual'));
        displayModeAiBtn?.addEventListener('click', () => {
            if (!studioState.ai.enabled) return;
            if (!studioState.selectedWorkspaceId) {
                setDisplayMode('ai');
                if (!studioState.ai.providers.length) {
                    openAiProviderModal();
                }
                return;
            }
            setDisplayMode('ai');
        });

        // File panel
        $('new-file-btn')?.addEventListener('click', createWorkspaceFile);
        $('refresh-files-btn')?.addEventListener('click', () => { if (studioState.selectedWorkspaceId) loadWorkspaceFiles(studioState.selectedWorkspaceId); });
        $('file-tree-filter')?.addEventListener('input', renderFileTree);

        // Editor
        editorTextarea?.addEventListener('scroll', () => { if (editorLineNumbers) editorLineNumbers.scrollTop = editorTextarea.scrollTop; });
        editorTextarea?.addEventListener('keydown', (e) => {
            if (e.key === 'Tab') {
                e.preventDefault();
                const start = editorTextarea.selectionStart, end = editorTextarea.selectionEnd, val = editorTextarea.value;
                if (e.shiftKey) {
                    const before = val.substring(0, start);
                    const lineStart = before.lastIndexOf('\n') + 1;
                    const prefix = val.substring(lineStart, start);
                    if (prefix.startsWith('    ')) {
                        editorTextarea.value = val.substring(0, lineStart) + val.substring(lineStart + 4);
                        editorTextarea.selectionStart = editorTextarea.selectionEnd = Math.max(lineStart, start - 4);
                    } else if (prefix.startsWith('\t')) {
                        editorTextarea.value = val.substring(0, lineStart) + val.substring(lineStart + 1);
                        editorTextarea.selectionStart = editorTextarea.selectionEnd = Math.max(lineStart, start - 1);
                    }
                } else {
                    editorTextarea.value = val.substring(0, start) + '    ' + val.substring(end);
                    editorTextarea.selectionStart = editorTextarea.selectionEnd = start + 4;
                }
                editorTextarea.dispatchEvent(new Event('input'));
            }
        });
        editorTextarea?.addEventListener('input', () => {
            if (!studioState.selectedFile || studioState.selectedFile.isBinary) return;
            studioState.dirty = true;
            setSaveState('dirty', 'Unsaved changes');
            updateLineNumbers();
        });
        aiTargetEditorTextarea?.addEventListener('scroll', () => {
            syncAiTargetEditorScroll();
            renderAiTargetSelectionVisuals();
        });
        aiTargetEditorTextarea?.addEventListener('select', renderAiTargetSelectionVisuals);
        aiTargetEditorTextarea?.addEventListener('mouseup', renderAiTargetSelectionVisuals);
        aiTargetEditorTextarea?.addEventListener('keyup', renderAiTargetSelectionVisuals);

        saveFileBtn?.addEventListener('click', saveCurrentFile);
        deleteFileBtn?.addEventListener('click', deleteCurrentFile);

        // Preview
        $('inline-preview-btn')?.addEventListener('click', () => { if (studioState.selectedWorkspaceId) previewWorkspace(studioState.selectedWorkspaceId, false); });
        $('preview-go-btn')?.addEventListener('click', navigatePreviewToPage);
        previewPageInput?.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); navigatePreviewToPage(); } });
        previewFrame?.addEventListener('load', handlePreviewFrameLoad);

        // App switcher
        appSwitcherSelect?.addEventListener('change', (e) => setWorkspaceAppSelection(e.target.value));

        // AI
        aiProviderSelect?.addEventListener('change', async (e) => {
            studioState.ai.selectedProviderId = e.target.value || '';
            studioState.ai.selectedModel = '';
            if (aiModelInput) aiModelInput.value = '';
            resetAiConversation(true);
            renderAiProviders();
            if (studioState.ai.selectedProviderId) {
                await loadAiModels(studioState.ai.selectedProviderId);
            } else {
                setAiStatus('No provider');
            }
        });
        aiModelFreeOnlyInput?.addEventListener('change', () => {
            studioState.ai.freeOnly = !!aiModelFreeOnlyInput.checked;
            renderAiModelOptions();
        });
        aiRefreshModelsBtn?.addEventListener('click', () => {
            if (studioState.ai.selectedProviderId) loadAiModels(studioState.ai.selectedProviderId, false);
        });
        aiModelInput?.addEventListener('input', () => {
            studioState.ai.selectedModel = aiModelInput.value.trim();
            updateStarterAiState();
        });
        pickPreviewElementBtn?.addEventListener('click', togglePreviewElementPicker);
        openAiTargetModalBtn?.addEventListener('click', openAiTargetModal);
        aiForm?.addEventListener('submit', async (e) => {
            e.preventDefault();
            await sendAiPrompt();
        });
        aiPromptInput?.addEventListener('keydown', async (e) => {
            if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') {
                e.preventDefault();
                await sendAiPrompt();
            }
        });
        aiApplyBtn?.addEventListener('click', applyAiPlan);
        aiViewDiffBtn?.addEventListener('click', openAiDiffModal);
        aiClearPlanBtn?.addEventListener('click', () => {
            studioState.ai.pendingPlan = null;
            resetAiDiffState();
            closeAiDiffModal();
            renderAiPlan();
        });
        $('open-ai-provider-modal-btn')?.addEventListener('click', openAiProviderModal);

        // Modal
        $('close-workspace-modal-btn')?.addEventListener('click', closeWorkspaceModal);
        document.querySelectorAll('input[name="workspaceModalMode"]').forEach(inp => {
            inp.addEventListener('change', () => { if (inp.checked) setWorkspaceModalMode(inp.value); });
        });
        document.querySelectorAll('input[name="starterMode"]').forEach(inp => {
            inp.addEventListener('change', () => { if (inp.checked) setStarterMode(inp.value); });
        });
        starterColorInput?.addEventListener('input', syncStarterColor);
        starterIconButtons().forEach(btn => {
            btn.addEventListener('click', () => selectStarterPresetIcon(btn.dataset.icon || 'fa-sparkles', btn.dataset.label || 'Selected icon'));
        });
        starterIconCustomInput?.addEventListener('input', syncStarterCustomIcon);
        $('starter-name')?.addEventListener('input', (e) => {
            const slug = slugify(e.target.value);
            const appIdInput = $('starter-app-id'), appNameInput = $('starter-app-name');
            if (appIdInput && appIdInput.value.trim() === '') appIdInput.value = slug;
            if (appNameInput && appNameInput.value.trim() === '') appNameInput.value = e.target.value;
        });
        starterAiPromptInput?.addEventListener('input', () => {
            if (starterAiStatus) starterAiStatus.textContent = '';
        });
        starterAiProviderSelect?.addEventListener('change', async (e) => {
            studioState.ai.starterSelectedProviderId = e.target.value || '';
            studioState.ai.starterSelectedModel = '';
            studioState.ai.starterModelOptions = [];
            renderStarterAiModelOptions();
            if (starterAiModelInput) starterAiModelInput.value = '';
            if (starterAiStatus) starterAiStatus.textContent = '';
            updateStarterAiState();
            if (studioState.ai.starterSelectedProviderId) {
                await loadStarterAiModels(studioState.ai.starterSelectedProviderId);
            }
        });
        starterAiModelInput?.addEventListener('input', () => {
            studioState.ai.starterSelectedModel = starterAiModelInput.value.trim();
            if (starterAiStatus) starterAiStatus.textContent = '';
            updateStarterAiState();
        });
        starterAiCreateBtn?.addEventListener('click', async () => {
            const prompt = starterAiPromptInput?.value.trim() || '';
            if (!prompt) {
                window.Toast?.error('Describe the app you want first');
                starterAiPromptInput?.focus();
                return;
            }

            const provider = currentAiCreationProvider();
            if (!provider) {
                updateStarterAiState();
                openAiProviderModal();
                window.Toast?.error('Add an AI provider first');
                return;
            }

            studioState.ai.creatingWorkspace = true;
            studioState.ai.activeCreateJobId = null;
            clearAiCreatePoll();
            updateStarterAiState();
            if (starterAiStatus) starterAiStatus.textContent = 'Queued. Preparing AI workspace job...';
            btnLoading(starterAiCreateBtn, true);

            try {
                const data = await postStudio('start-ai-create-workspace-job', {
                    prompt,
                    providerId: provider.id,
                    model: (starterAiModelInput?.value || studioState.ai.starterSelectedModel || '').trim(),
                });
                const job = data.job || null;
                if (!job?.id) throw new Error('AI workspace job could not be created');
                studioState.ai.activeCreateJobId = job.id;
                if (starterAiStatus) starterAiStatus.textContent = 'Queued. Waiting for worker...';
                studioState.ai.createPollTimer = setTimeout(() => pollAiCreateJob(job.id), 500);
            } catch (error) {
                studioState.ai.creatingWorkspace = false;
                studioState.ai.activeCreateJobId = null;
                updateStarterAiState();
                if (starterAiStatus) starterAiStatus.textContent = error.message;
                window.Toast?.error(error.message);
                btnLoading(starterAiCreateBtn, false);
            }
        });

        workspaceModal?.addEventListener('click', (e) => { if (e.target === workspaceModal) closeWorkspaceModal(); });
        aiProviderModal?.addEventListener('click', (e) => { if (e.target === aiProviderModal) closeAiProviderModal(); });
        aiTargetModal?.addEventListener('click', (e) => { if (e.target === aiTargetModal) closeAiTargetModal(); });
        aiTargetLinesModal?.addEventListener('click', (e) => { if (e.target === aiTargetLinesModal) closeAiTargetLinesModal(); });
        aiDiffModal?.addEventListener('click', (e) => { if (e.target === aiDiffModal) closeAiDiffModal(); });
        $('close-ai-provider-modal-btn')?.addEventListener('click', closeAiProviderModal);
        closeAiTargetModalBtn?.addEventListener('click', closeAiTargetModal);
        closeAiTargetFooterBtn?.addEventListener('click', closeAiTargetModal);
        closeAiTargetLinesModalBtn?.addEventListener('click', closeAiTargetLinesModal);
        closeAiTargetLinesFooterBtn?.addEventListener('click', closeAiTargetLinesModal);
        $('close-ai-diff-modal-btn')?.addEventListener('click', closeAiDiffModal);
        $('close-ai-diff-footer-btn')?.addEventListener('click', closeAiDiffModal);
        aiTargetSearchInput?.addEventListener('input', renderAiTargetModalFileList);
        aiTargetOpenLinesBtn?.addEventListener('click', openAiTargetLinesModal);
        aiTargetUseCurrentBtn?.addEventListener('click', useCurrentFileAsAiTargetDraft);
        aiTargetUseSelectionBtn?.addEventListener('click', applyAiTargetLineSelection);
        aiTargetModalClearBtn?.addEventListener('click', clearAiTargetDraft);
        aiTargetApplyBtn?.addEventListener('click', applyAiTargetFromModal);
        aiDiffApplyBtn?.addEventListener('click', applyAiPlan);
        aiProviderManageSelect?.addEventListener('change', (e) => {
            const provider = getAiProvider(e.target.value);
            populateAiProviderForm(provider || null);
            if (provider?.id) {
                loadAiProviderModalModels();
            }
        });
        aiProviderTypeInput?.addEventListener('change', () => {
            const defaults = aiProviderDefaults(aiProviderTypeInput.value || 'ollama');
            if (aiProviderTypeHiddenInput) aiProviderTypeHiddenInput.value = defaults.type;
            if (aiProviderHint) aiProviderHint.innerHTML = defaults.hint;
            if (aiProviderApiKeyInput) aiProviderApiKeyInput.placeholder = defaults.apiKeyPlaceholder;
            toggleAiProviderAdvancedFields(defaults.preset);
            if (aiProviderBaseUrlInput && !aiProviderIdInput?.value.trim()) {
                aiProviderBaseUrlInput.value = defaults.baseUrl;
            }
            if (aiProviderNameInput && !aiProviderIdInput?.value.trim()) {
                const knownDefaults = ['Local Ollama', 'OpenAI', 'Claude', 'MiniMax', 'OpenRouter', 'Custom OpenAI-Compatible', 'Hosted AI'];
                if (!aiProviderNameInput.value.trim() || knownDefaults.includes(aiProviderNameInput.value.trim())) {
                    aiProviderNameInput.value = defaults.name;
                }
            }
            if (aiProviderChatPathInput && !aiProviderIdInput?.value.trim()) {
                aiProviderChatPathInput.value = defaults.chatPath || '';
            }
            if (aiProviderModelsPathInput && !aiProviderIdInput?.value.trim()) {
                aiProviderModelsPathInput.value = defaults.modelsPath || '';
            }
            if (aiProviderRequestParamsInput && !aiProviderIdInput?.value.trim()) {
                aiProviderRequestParamsInput.value = '';
            }
            resetAiProviderModalModelOptions();
            updateAiProviderActionButtons();
        });
        aiProviderNameInput?.addEventListener('input', updateAiProviderActionButtons);
        aiProviderBaseUrlInput?.addEventListener('input', () => {
            resetAiProviderModalModelOptions();
            updateAiProviderActionButtons();
        });
        aiProviderApiKeyInput?.addEventListener('input', () => {
            resetAiProviderModalModelOptions();
            updateAiProviderActionButtons();
        });
        aiProviderChatPathInput?.addEventListener('input', () => {
            resetAiProviderModalModelOptions();
            updateAiProviderActionButtons();
        });
        aiProviderModelsPathInput?.addEventListener('input', () => {
            resetAiProviderModalModelOptions();
            updateAiProviderActionButtons();
        });
        aiProviderRequestParamsInput?.addEventListener('input', () => {
            resetAiProviderModalModelOptions();
            updateAiProviderActionButtons();
        });
        aiProviderDefaultModelInput?.addEventListener('input', updateAiProviderActionButtons);
        aiProviderIsDefaultInput?.addEventListener('change', updateAiProviderActionButtons);
        loadAiProviderModelsBtn?.addEventListener('click', loadAiProviderModalModels);
        aiProviderForm?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const payload = Object.fromEntries(new FormData(aiProviderForm).entries());
            const existingProvider = getAiProvider(payload.id || '');
            const providerPreset = payload.providerPreset || aiProviderTypeInput?.value || 'ollama';
            const defaults = aiProviderDefaults(providerPreset);
            payload.type = defaults.type;
            payload.isDefault = aiProviderIsDefaultInput?.checked ? '1' : '0';
            const submitBtn = $('save-ai-provider-btn');
            btnLoading(submitBtn, true);
            try {
                payload.metadata = buildAiProviderMetadataFromForm();
                delete payload.providerPreset;
                if ((payload.apiKey || '').trim() === '' && existingProvider?.hasApiKey) {
                    delete payload.apiKey;
                }

                const data = await postStudio('save-ai-provider', payload);
                studioState.ai.selectedProviderId = data.provider.id;
                await refreshAiProviders();
                populateAiProviderForm(getAiProvider(data.provider.id) || data.provider);
                await loadAiProviderModalModels();
                await loadAiModels(data.provider.id);
                setAiStatus('Provider saved');
                window.Toast?.success('AI provider saved');
            } catch (error) {
                window.Toast?.error(error.message);
            } finally {
                btnLoading(submitBtn, false);
            }
        });
        testAiProviderBtn?.addEventListener('click', async () => {
            if (testAiProviderBtn?.disabled) return;
            const providerId = aiProviderIdInput?.value.trim() || '';
            if (!providerId) {
                window.Toast?.error('Save the provider before testing it');
                return;
            }
            btnLoading(testAiProviderBtn, true);
            if (aiProviderTestStatus) aiProviderTestStatus.textContent = '';
            try {
                const data = await postStudio('test-ai-provider', { id: providerId });
                if (aiProviderTestStatus) aiProviderTestStatus.textContent = data.message || 'Provider test succeeded';
                window.Toast?.success(data.message || 'Provider test succeeded');
                await loadAiModels(providerId);
            } catch (error) {
                if (aiProviderTestStatus) aiProviderTestStatus.textContent = error.message;
                window.Toast?.error(error.message);
            } finally {
                btnLoading(testAiProviderBtn, false);
            }
        });
        deleteAiProviderBtn?.addEventListener('click', async () => {
            if (deleteAiProviderBtn?.disabled) return;
            const providerId = aiProviderIdInput?.value.trim() || '';
            if (!providerId) return;
            if (!window.confirm('Delete this AI provider?')) return;
            btnLoading(deleteAiProviderBtn, true);
            try {
                await postStudio('delete-ai-provider', { id: providerId });
                await refreshAiProviders();
                populateAiProviderForm(getAiProvider(studioState.ai.selectedProviderId) || null);
                resetAiConversation(true);
                window.Toast?.success('AI provider deleted');
            } catch (error) {
                window.Toast?.error(error.message);
            } finally {
                btnLoading(deleteAiProviderBtn, false);
            }
        });

        // Starter form
        $('starter-form')?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const form = e.currentTarget;
            const payload = Object.fromEntries(new FormData(form).entries());
            const mode = payload.starterMode || 'simple';
            if (mode === 'advanced' && payload.iconCustom?.trim()) payload.icon = payload.iconCustom.trim();
            if (!payload.icon?.trim()) payload.icon = starterIconInput?.value || 'fa-sparkles';
            if (!payload.color?.trim()) payload.color = starterColorInput?.value || '#2563eb';
            if (mode === 'advanced') {
                if (!payload.appId?.trim()) payload.appId = slugify(payload.name);
                if (!payload.appName?.trim()) payload.appName = String(payload.name || '').trim();
            } else {
                payload.appId = slugify(payload.name);
                payload.appName = String(payload.name || '').trim();
                delete payload.description;
            }
            delete payload.starterMode; delete payload.iconCustom;
            const submitBtn = form.querySelector('button[type="submit"]');
            btnLoading(submitBtn, true);
            try {
                await postStudio('create-starter-workspace', payload);
                window.Toast?.success('Starter workspace created');
                closeWorkspaceModal();
                window.location.reload();
            } catch (error) { window.Toast?.error(error.message); btnLoading(submitBtn, false); }
        });

        // Repo form
        $('repo-form')?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const form = e.currentTarget;
            const payload = Object.fromEntries(new FormData(form).entries());
            if (!payload.repositoryId) delete payload.repositoryId;
            const submitBtn = $('repo-import-btn');
            const statusEl = $('repo-import-status');
            btnLoading(submitBtn, true);
            statusEl?.classList.remove('hidden');
            try {
                await postStudio('import-repo-workspace', payload);
                window.Toast?.success('Repository workspace imported');
                closeWorkspaceModal();
                window.location.reload();
            } catch (error) { window.Toast?.error(error.message); btnLoading(submitBtn, false); statusEl?.classList.add('hidden'); }
        });

        // Global keys
        window.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && workspaceModal?.classList.contains('active')) { closeWorkspaceModal(); return; }
            if (e.key === 'Escape' && aiProviderModal?.classList.contains('active')) { closeAiProviderModal(); return; }
            if (e.key === 'Escape' && aiTargetLinesModal?.classList.contains('active')) { closeAiTargetLinesModal(); return; }
            if (e.key === 'Escape' && aiTargetModal?.classList.contains('active')) { closeAiTargetModal(); return; }
            if (e.key === 'Escape' && aiDiffModal?.classList.contains('active')) { closeAiDiffModal(); return; }
            if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 's') {
                if (!studioState.selectedFile || studioState.selectedFile.isBinary) return;
                e.preventDefault();
                saveCurrentFile();
            }
        });

        // Expose globals
        window.selectWorkspace = selectWorkspace;
        window.toggleDirectory = toggleDirectory;
        window.openWorkspaceFile = openWorkspaceFile;

        // ── Init ──
        (function initStudio() {
            setWorkspaceModalMode('create');
            setStarterMode('simple');
            syncStarterColor();
            syncStarterCustomIcon();
            updateToolbarButtons();
            if (aiModelFreeOnlyInput) aiModelFreeOnlyInput.checked = !!studioState.ai.freeOnly;
            toggleFilesBtn?.classList.add('active');
            applyDisplayMode();
            renderAiProviders();
            renderAiMessages();
            renderAiPlan();
            renderAiTargetEditor();
            renderAiTargetModalFileList();
            renderAiSelectionHint();
            updateStarterAiState();

            const hash = window.location.hash || '';
            const match = hash.match(/^#workspace-([a-z0-9_-]+)$/i);
            const initialId = match?.[1] || null;
            if (initialId && getWorkspace(initialId)) {
                selectWorkspace(initialId, false);
            } else {
                showEmpty();
            }

            if (studioState.ai.enabled && studioState.displayMode === 'ai' && studioState.ai.selectedProviderId) {
                loadAiModels(studioState.ai.selectedProviderId);
            } else if (studioState.ai.enabled && studioState.ai.providers.length > 0) {
                setAiStatus('Ready');
            } else if (studioState.ai.enabled) {
                setAiStatus('No provider');
            }
        })();
    </script>
</body>
</html>
