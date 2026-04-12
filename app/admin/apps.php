<?php
/**
 * admin/apps.php - App Marketplace & Management
 * 
 * Part of Doki v3 Architecture - Batch 10: App Framework
 * Updated Batch 13: Now uses SourcesManager for repositories
 */

require_once __DIR__ . '/../includes/middleware.php';
require_once __DIR__ . '/../includes/Layout.php';
require_once __DIR__ . '/../includes/AppManager.php';
require_once __DIR__ . '/../includes/AppCapabilities.php';
require_once __DIR__ . '/../includes/AppContext.php';
require_once __DIR__ . '/../includes/SourcesManager.php';
require_once __DIR__ . '/../includes/AppImageBuilder.php';
require_once __DIR__ . '/../includes/PlaywrightRuntimeManager.php';
require_once __DIR__ . '/../includes/ConfigManager.php';

$user = requireRole('admin');
requireModuleAccess('admin-apps');
$isSuperAdmin = $user['role'] === 'super-admin';
$layout = new Layout($user, 'admin-apps');
$appManager = new AppManager();
$sourcesManager = new SourcesManager();
$configManager = new ConfigManager();
$targets = $configManager->getTargets();
$dockerTargets = array_values(array_filter($targets, function($t) {
    $type = $t['type'] ?? '';
    if (!in_array($type, ['docker', 'local', 'docker-exec'], true)) {
        return false;
    }
    $verified = $t['verifiedCapabilities'] ?? [];
    return in_array('exec.docker', $verified, true);
}));

$installedApps = $appManager->getApps();
$availableAppsCount = count($appManager->getAvailableApps());
// Load app sources from unified SourcesManager (type: apps or mixed)
$allRepos = $sourcesManager->getRepositories();
$sources = array_filter($allRepos, fn($r) => in_array($r['type'], ['apps', 'mixed']));
$trustLevels = AppCapabilities::getAllTrustLevels();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $layout->renderHead('App Marketplace'); ?>
    <style>
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }

        .tabs {
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

        .apps-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
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
            overflow: visible;
            transition: all 0.2s;
            position: relative;
        }
        
        /* Ensure card content doesn't overflow, but dropdown can */
        .app-card-header,
        .app-card-body,
        .app-card-footer {
            overflow: visible;
        }

        .app-card:hover {
            border-color: var(--accent-primary);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .app-card-header {
            padding: 18px 20px;
            display: flex;
            align-items: flex-start;
            gap: 14px;
        }

        .app-icon {
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

        .app-info {
            flex: 1;
            min-width: 0;
        }

        .app-name {
            font-size: 16px;
            font-weight: 600;
            margin: 0 0 4px 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .app-version {
            font-size: 11px;
            color: var(--text-muted);
            background: var(--bg-tertiary);
            padding: 2px 6px;
            border-radius: 4px;
        }

        .app-desc {
            font-size: 13px;
            color: var(--text-secondary);
            margin: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .app-version-delta {
            margin-top: 8px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 11px;
            font-weight: 500;
            padding: 4px 8px;
            border-radius: 999px;
        }

        .app-version-delta.source-newer {
            color: #2563eb;
            background: rgba(37, 99, 235, 0.12);
        }

        .app-version-delta.source-older {
            color: #b45309;
            background: rgba(245, 158, 11, 0.16);
        }

        .app-version-delta.source-changed {
            color: #0f766e;
            background: rgba(20, 184, 166, 0.14);
        }

        .app-trust {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 16px;
            font-size: 12px;
            font-weight: 500;
            margin-top: 8px;
        }

        .app-trust.core { background: rgba(99, 102, 241, 0.1); color: #6366f1; }
        .app-trust.verified { background: rgba(34, 197, 94, 0.1); color: #22c55e; }
        .app-trust.trusted { background: rgba(59, 130, 246, 0.1); color: #3b82f6; }
        .app-trust.sandboxed { background: rgba(245, 158, 11, 0.1); color: #f59e0b; }

        .app-card-divider {
            height: 1px;
            background: var(--border-color);
            margin: 0;
            border: none;
        }

        .app-card-footer {
            padding: 10px 20px;
            background: var(--bg-tertiary);
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .app-source {
            font-size: 12px;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .app-actions {
            display: flex;
            gap: 6px;
            position: relative;
        }

        /* Dropdown menu for app actions */
        .app-actions-menu {
            position: relative;
        }

        .app-actions-toggle {
            padding: 8px 14px;
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            color: var(--text-primary);
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
            position: relative;
        }

        .app-actions-toggle:hover {
            background: var(--bg-tertiary);
            border-color: var(--accent-primary);
            color: var(--accent-primary);
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .app-actions-toggle:active {
            transform: translateY(0);
        }

        .app-actions-menu.open .app-actions-toggle {
            background: var(--accent-primary);
            border-color: var(--accent-primary);
            color: white;
            box-shadow: 0 2px 8px rgba(99, 102, 241, 0.3);
        }

        .app-actions-menu.open .app-actions-toggle i {
            transform: rotate(180deg);
        }

        .app-actions-toggle i {
            font-size: 11px;
            transition: transform 0.2s;
        }

        .app-actions-dropdown {
            position: absolute;
            bottom: 100%;
            right: 0;
            margin-bottom: 10px;
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15), 0 2px 8px rgba(0, 0, 0, 0.1);
            min-width: 220px;
            max-height: calc(100vh - 20px);
            display: none;
            z-index: 1000;
            overflow-y: auto;
            overflow-x: hidden;
            animation: dropdownFadeIn 0.15s ease-out;
        }

        @keyframes dropdownFadeIn {
            from {
                opacity: 0;
                transform: translateY(4px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .app-actions-menu.open .app-actions-dropdown {
            display: block;
        }

        .app-actions-dropdown-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            color: var(--text-primary);
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.15s;
            cursor: pointer;
            border: none;
            background: none;
            width: 100%;
            text-align: left;
            position: relative;
        }

        .app-actions-dropdown-item:hover {
            background: var(--bg-tertiary);
            color: var(--text-primary);
        }

        .app-actions-dropdown-item.danger {
            color: #ef4444;
        }

        .app-actions-dropdown-item.danger:hover {
            background: rgba(239, 68, 68, 0.1);
            color: #dc2626;
        }

        .app-actions-dropdown-item i {
            width: 20px;
            text-align: center;
            font-size: 14px;
            opacity: 0.9;
        }

        .app-actions-dropdown-item:hover i {
            opacity: 1;
        }

        /* App Organization Controls (Batch 3) */
        .app-org-controls {
            padding: 12px 16px;
            border-bottom: 1px solid var(--border-color);
        }

        .app-org-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 12px;
        }

        .app-org-item:last-child {
            margin-bottom: 0;
        }

        .app-org-item label {
            font-size: 13px;
            font-weight: 500;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
        }

        .app-priority-input {
            width: 60px;
            padding: 6px 8px;
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            color: var(--text-primary);
            font-size: 13px;
            text-align: center;
        }

        .app-priority-input:focus {
            outline: none;
            border-color: var(--accent-primary);
        }

        .app-pinned-checkbox {
            width: 16px;
            height: 16px;
            cursor: pointer;
            accent-color: var(--accent-primary);
        }

        .app-actions-dropdown-divider {
            height: 1px;
            background: var(--border-color);
            margin: 4px 0;
        }

        /* Icon-only buttons with tooltips */
        .btn-icon {
            padding: 6px 8px;
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            color: var(--text-secondary);
            cursor: pointer;
            font-size: 13px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
            position: relative;
        }

        .btn-icon:hover {
            background: var(--bg-tertiary);
            border-color: var(--text-muted);
            color: var(--text-primary);
        }

        .btn-icon[title]:hover::after {
            content: attr(title);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            margin-bottom: 6px;
            padding: 4px 8px;
            background: rgba(0, 0, 0, 0.9);
            color: white;
            font-size: 12px;
            white-space: nowrap;
            border-radius: 4px;
            pointer-events: none;
            z-index: 1000;
        }

        .btn-icon[title]:hover::before {
            content: '';
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            margin-bottom: 2px;
            border: 4px solid transparent;
            border-top-color: rgba(0, 0, 0, 0.9);
            pointer-events: none;
            z-index: 1000;
        }

        /* Sources section */
        .sources-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .source-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 16px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .source-card.is-busy {
            border-color: rgba(14, 165, 233, 0.45);
            box-shadow: 0 0 0 1px rgba(14, 165, 233, 0.12), 0 8px 24px rgba(14, 165, 233, 0.08);
        }

        .source-info {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .source-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            background: var(--bg-tertiary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            color: var(--text-muted);
        }

        .source-name {
            font-weight: 600;
            margin: 0 0 4px 0;
        }

        .source-url {
            font-size: 12px;
            color: var(--text-muted);
            font-family: 'JetBrains Mono', monospace;
        }

        .source-actions {
            display: flex;
            gap: 8px;
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

        .modal-backdrop.active {
            display: flex;
        }

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
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h2 {
            margin: 0;
            font-size: 18px;
        }

        .modal-close {
            background: none;
            border: none;
            color: var(--text-secondary);
            cursor: pointer;
            font-size: 20px;
        }

        .modal-body {
            padding: 20px;
        }

        .modal-footer {
            padding: 16px 20px;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-label {
            display: block;
            margin-bottom: 6px;
            font-size: 13px;
            font-weight: 500;
        }

        .form-input, .form-select {
            width: 100%;
            padding: 10px 14px;
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            color: var(--text-primary);
            font-size: 14px;
        }

        .form-hint {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 4px;
        }

        /* Trust selector */
        .trust-options {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .trust-option {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            background: var(--bg-primary);
            border: 2px solid var(--border-color);
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .trust-option:hover {
            border-color: var(--text-muted);
        }

        .trust-option.selected {
            border-color: var(--accent-primary);
            background: var(--accent-glow);
        }

        .trust-option input {
            display: none;
        }

        .trust-option-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
        }

        .trust-option-content {
            flex: 1;
        }

        .trust-option-name {
            font-weight: 600;
            margin-bottom: 2px;
        }

        .trust-option-desc {
            font-size: 12px;
            color: var(--text-muted);
        }

        /* Capabilities list */
        .capabilities-list {
            max-height: 200px;
            overflow-y: auto;
            background: var(--bg-primary);
            border-radius: 8px;
            padding: 12px;
        }

        .capability-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 0;
            font-size: 13px;
        }

        .capability-item.granted { color: var(--success); }
        .capability-item.denied { color: var(--text-muted); text-decoration: line-through; }

        .empty-state {
            text-align: center;
            padding: 48px;
            color: var(--text-muted);
        }

        .empty-state > i {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.5;
        }

        /* Runtime management - collapsible */
        .app-runtime {
            padding: 12px 20px;
            background: var(--bg-tertiary);
            border-top: 1px solid var(--border-color);
        }

        .runtime-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
            user-select: none;
        }

        .runtime-header:hover {
            opacity: 0.8;
        }

        .runtime-header-left {
            display: flex;
            align-items: center;
            gap: 10px;
            flex: 1;
        }

        .runtime-toggle {
            color: var(--text-muted);
            font-size: 12px;
            transition: transform 0.2s;
        }

        .runtime-toggle.expanded {
            transform: rotate(90deg);
        }

        .runtime-docker-icon {
            color: #2496ed;
            font-size: 16px;
        }

        .runtime-label-text {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-secondary);
            margin-left: 4px;
        }

        .runtime-summary {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
        }

        .runtime-summary-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
        }

        .runtime-summary-badge.running {
            color: #22c55e;
            background: rgba(34, 197, 94, 0.1);
        }

        .runtime-summary-badge.stopped {
            color: #f59e0b;
            background: rgba(245, 158, 11, 0.1);
        }

        .runtime-summary-badge.not-built {
            color: #6b7280;
            background: rgba(107, 114, 128, 0.1);
        }

        .runtime-summary-badge.building {
            color: #0ea5e9;
            background: rgba(14, 165, 233, 0.1);
        }

        .runtime-summary-badge.error {
            color: #ef4444;
            background: rgba(239, 68, 68, 0.1);
        }

        .runtime-summary-badge.stale {
            color: #f97316;
            background: rgba(249, 115, 22, 0.12);
        }

        .runtime-details {
            display: none;
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid var(--border-color);
        }

        .runtime-details.expanded {
            display: block;
        }

        .runtime-status {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            margin-bottom: 12px;
        }

        .runtime-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 12px;
            padding: 6px 10px;
            background: var(--bg-secondary);
            border-radius: 6px;
        }

        .runtime-item-label {
            color: var(--text-muted);
            font-size: 11px;
        }

        .status-badge {
            font-weight: 500;
            font-size: 11px;
            padding: 2px 6px;
            border-radius: 4px;
        }

        .status-badge.ready {
            color: #22c55e;
            background: rgba(34, 197, 94, 0.1);
        }

        .status-badge.pending {
            color: #f59e0b;
            background: rgba(245, 158, 11, 0.1);
        }

        .status-badge.building {
            color: #0ea5e9;
            background: rgba(14, 165, 233, 0.1);
        }

        .status-badge.error {
            color: #ef4444;
            background: rgba(239, 68, 68, 0.1);
        }

        .status-badge.stale {
            color: #f97316;
            background: rgba(249, 115, 22, 0.12);
        }

        .runtime-muted {
            font-size: 12px;
            color: var(--text-muted);
        }

        .runtime-actions {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .runtime-actions .btn-sm {
            font-size: 12px;
            padding: 5px 10px;
        }

        .btn.is-busy,
        .btn-sm.is-busy,
        .app-actions-dropdown-item.is-busy {
            position: relative;
            opacity: 0.92;
        }

        .form-input.is-busy,
        .form-select.is-busy,
        .exec-target-select.is-busy,
        .app-priority-input.is-busy,
        .app-pinned-checkbox.is-busy {
            opacity: 0.7;
            cursor: progress;
        }

        .btn.is-busy,
        .btn-sm.is-busy {
            box-shadow: 0 0 0 1px rgba(255, 255, 255, 0.12), 0 0 0 6px rgba(14, 165, 233, 0.08);
        }

        .btn.is-busy::after,
        .btn-sm.is-busy::after,
        .app-actions-dropdown-item.is-busy::after {
            content: '';
            position: absolute;
            inset: -1px;
            border-radius: inherit;
            border: 1px solid rgba(14, 165, 233, 0.25);
            animation: busyPulse 1.2s ease-in-out infinite;
            pointer-events: none;
        }

        @keyframes busyPulse {
            0%, 100% {
                opacity: 0.35;
                transform: scale(1);
            }
            50% {
                opacity: 0.9;
                transform: scale(1.015);
            }
        }

        .btn-success {
            background: #22c55e;
            color: white;
        }

        .btn-success:hover {
            background: #16a34a;
        }

        .btn-warning {
            background: #f59e0b;
            color: white;
        }

        .btn-warning:hover {
            background: #d97706;
        }

        .app-exec-target {
            padding: 12px 20px;
            background: var(--bg-secondary);
            border-top: 1px solid var(--border-color);
        }

        .exec-target-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .exec-target-label {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-secondary);
        }

        .exec-target-select {
            min-width: 200px;
            padding: 6px 8px;
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            color: var(--text-primary);
            font-size: 12px;
        }

        .exec-target-select:focus {
            outline: none;
            border-color: var(--accent-primary);
        }

        .exec-target-hint {
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 6px;
        }

        .update-review {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .update-review-hero {
            padding: 14px 16px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            background: var(--bg-tertiary);
        }

        .update-review-hero.warning {
            border-color: rgba(245, 158, 11, 0.35);
            background: rgba(245, 158, 11, 0.08);
        }

        .update-review-hero.danger {
            border-color: rgba(239, 68, 68, 0.35);
            background: rgba(239, 68, 68, 0.08);
        }

        .update-review-hero h3 {
            margin: 0 0 6px 0;
            font-size: 16px;
        }

        .update-review-hero p {
            margin: 0;
            color: var(--text-secondary);
            font-size: 13px;
        }

        .update-review-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
        }

        .update-review-card {
            padding: 14px 16px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            background: var(--bg-tertiary);
        }

        .update-review-label {
            display: block;
            margin-bottom: 8px;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: var(--text-muted);
        }

        .update-review-value {
            font-size: 16px;
            font-weight: 600;
        }

        .update-review-subvalue {
            margin-top: 6px;
            font-size: 13px;
            color: var(--text-secondary);
        }

        .update-review-flags {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .update-review-flag {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 500;
        }

        .update-review-flag.upgrade,
        .update-review-flag.success {
            color: #166534;
            background: rgba(34, 197, 94, 0.14);
        }

        .update-review-flag.downgrade,
        .update-review-flag.warning {
            color: #b45309;
            background: rgba(245, 158, 11, 0.16);
        }

        .update-review-flag.danger {
            color: #b91c1c;
            background: rgba(239, 68, 68, 0.14);
        }

        .update-review-section {
            padding: 14px 16px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            background: var(--bg-tertiary);
        }

        .update-review-section h4 {
            margin: 0 0 8px 0;
            font-size: 14px;
        }

        .update-review-section p {
            margin: 0;
            color: var(--text-secondary);
            font-size: 13px;
        }

        .update-review-list {
            margin: 0;
            padding-left: 18px;
            color: var(--text-secondary);
            font-size: 13px;
        }

        .update-review-list li + li {
            margin-top: 6px;
        }

        .update-review-link {
            color: var(--accent-primary);
            text-decoration: none;
        }

        .update-review-link:hover {
            text-decoration: underline;
        }

        .update-release-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .update-release-item {
            padding: 12px 14px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            background: var(--bg-secondary);
        }

        .update-release-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 8px;
        }

        .update-release-title {
            font-size: 14px;
            font-weight: 600;
        }

        .update-release-meta {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            font-size: 12px;
            color: var(--text-muted);
        }
    </style>
</head>
<body>
    <div class="app-container">
        <?php $layout->renderSidebar(); ?>

        <main class="main-content" id="marketplace-app">
            <div class="page-header">
                <div>
                    <h1 class="page-title">App Marketplace</h1>
                    <p class="page-subtitle">Install and manage Doki applications</p>
                </div>
                <?php if ($isSuperAdmin): ?>
                <button class="btn btn-primary" onclick="showAddSourceModal()">
                    <i class="fas fa-plus"></i>
                    Add Source
                </button>
                <?php endif; ?>
            </div>

            <!-- Tabs -->
            <div class="tabs">
                <div class="tab active" data-tab="installed" onclick="switchTab('installed')">
                    <i class="fas fa-puzzle-piece"></i>
                    Installed
                    <span class="tab-badge" id="installed-count"><?= count($installedApps) ?></span>
                </div>
                <div class="tab" data-tab="available" onclick="switchTab('available')">
                    <i class="fas fa-download"></i>
                    Available
                    <span class="tab-badge" id="available-count"><?= $availableAppsCount ?></span>
                </div>
                <div class="tab" data-tab="sources" onclick="switchTab('sources')">
                    <i class="fas fa-code-branch"></i>
                    Sources
                    <span class="tab-badge" id="sources-count"><?= count($sources) ?></span>
                </div>
            </div>

            <!-- Installed Apps Tab -->
            <div class="tab-content" id="tab-installed">
                <?php if (empty($installedApps)): ?>
                <div class="empty-state">
                    <i class="fas fa-puzzle-piece"></i>
                    <h3>No apps installed</h3>
                    <p>Add a source and install apps from the Available tab</p>
                </div>
                <?php else: ?>
                <div class="apps-grid">
                    <?php foreach ($installedApps as $app): 
                        $trustLevel = $appManager->getTrustLevel($app['id']);
                        $trustInfo = AppCapabilities::getTrustLevelInfo($trustLevel);
                        $installed = $appManager->getInstalledApps()[$app['id']] ?? [];
                        $declaredCaps = $app['capabilities'] ?? [];
                        $usesExecDocker = in_array(AppCapabilities::EXEC_DOCKER, $declaredCaps, true);
                        $execTargetId = $installed['execTarget'] ?? '';
                        
                        // Get organization metadata (Batch 3)
                        $priority = $installed['priority'] ?? 50;
                        $pinned = $installed['pinned'] ?? false;
                        
                        // Check if app needs custom runtime
                        $runtimeExtensions = $app['runtime']['phpExtensions'] ?? [];
                        $runtimePackages = $app['runtime']['systemPackages'] ?? [];
                        $hasCustomRuntime = !empty($runtimeExtensions) || !empty($runtimePackages);
                        $runtimeStatus = null;
                        if ($hasCustomRuntime) {
                            $builder = new AppImageBuilder($app['id'], $app);
                            $runtimeStatus = $builder->getStatus();
                        }

                        $hasRequiredServices = !empty($app['requiredServices'] ?? []);
                        $servicesOverview = [
                            'services' => [],
                            'total' => 0,
                            'healthyCount' => 0,
                            'anyRunning' => false,
                            'allHealthy' => true,
                            'canManage' => false,
                            'error' => null,
                        ];
                        $servicesOverallStatus = 'stopped';
                        $servicesOverallText = 'Not needed';
                        if ($hasRequiredServices) {
                            try {
                                $serviceContext = new AppContext($app['id'], null, false);
                                $servicesOverview = $serviceContext->getRequiredServicesOverview();
                            } catch (Throwable $e) {
                                $servicesOverview = [
                                    'services' => [],
                                    'total' => count($app['requiredServices'] ?? []),
                                    'healthyCount' => 0,
                                    'anyRunning' => false,
                                    'allHealthy' => false,
                                    'canManage' => false,
                                    'error' => $e->getMessage(),
                                ];
                            }

                            if (!$servicesOverview['canManage']) {
                                $servicesOverallStatus = 'error';
                                $servicesOverallText = 'Trust required';
                            } elseif ($servicesOverview['allHealthy']) {
                                $servicesOverallStatus = 'running';
                                $servicesOverallText = 'Healthy';
                            } elseif ($servicesOverview['anyRunning'] || $servicesOverview['healthyCount'] > 0) {
                                $servicesOverallStatus = 'building';
                                $servicesOverallText = 'Starting';
                            } else {
                                $servicesOverallStatus = 'stopped';
                                $servicesOverallText = 'Stopped';
                            }
                        }

                        // Playwright has a separate Docker runner image
                        $isPlaywright = $app['id'] === 'playwright';
                        $playwrightRuntimeStatus = null;
                        if ($isPlaywright) {
                            $playwrightRuntimeManager = new PlaywrightRuntimeManager();
                            $playwrightRuntimeStatus = $playwrightRuntimeManager->getStatus();
                        }
                    ?>
                    <div class="app-card" id="app-card-<?= htmlspecialchars($app['id']) ?>" data-app-id="<?= htmlspecialchars($app['id']) ?>">
                        <div class="app-card-header">
                            <div class="app-icon" style="background: <?= htmlspecialchars($app['color'] ?? '#6366f1') ?>">
                                <i class="fas <?= htmlspecialchars($app['icon'] ?? 'fa-puzzle-piece') ?>"></i>
                            </div>
                            <div class="app-info">
                                <h3 class="app-name">
                                    <?= htmlspecialchars($app['name']) ?>
                                    <span class="app-version">v<?= htmlspecialchars($app['version'] ?? '1.0.0') ?></span>
                                </h3>
                                <p class="app-desc"><?= htmlspecialchars($app['description'] ?? '') ?></p>
                                <div class="app-trust <?= strtolower($trustInfo['name']) ?>">
                                    <i class="fas <?= $trustInfo['icon'] ?>"></i>
                                    <?= $trustInfo['name'] ?>
                                </div>
                            </div>
                        </div>
                        <?php if ($hasCustomRuntime || $isPlaywright): ?>
                        <?php
                            if ($isPlaywright) {
                                $dockerAvailable = $playwrightRuntimeStatus['dockerAvailable'] ?? false;
                                $imageExists = $playwrightRuntimeStatus['imageExists'] ?? false;
                                $runningContainers = (int)($playwrightRuntimeStatus['runningContainers'] ?? 0);
                                $buildStatus = $playwrightRuntimeStatus['buildStatus']['status'] ?? '';
                                $isBuilding = $buildStatus === 'building';
                                $buildFailed = $buildStatus === 'failed';

                                $overallStatus = 'not-built';
                                $overallStatusText = 'Not built';

                                if (!$dockerAvailable) {
                                    $overallStatus = 'error';
                                    $overallStatusText = 'Docker unavailable';
                                } elseif ($runningContainers > 0) {
                                    $overallStatus = 'running';
                                    $overallStatusText = 'Running (' . $runningContainers . ')';
                                } elseif ($imageExists) {
                                    $overallStatus = 'running';
                                    $overallStatusText = 'Ready';
                                } elseif ($isBuilding) {
                                    $overallStatus = 'building';
                                    $overallStatusText = 'Building';
                                } elseif ($buildFailed) {
                                    $overallStatus = 'error';
                                    $overallStatusText = 'Build failed';
                                }
                            } else {
                                // Determine overall status
                                $overallStatus = 'not-built';
                                $overallStatusText = 'Not built';
                                $imageExists = $runtimeStatus['imageExists'] ?? false;
                                $containerRunning = $runtimeStatus['containerRunning'] ?? false;
                                $buildStatus = $runtimeStatus['buildStatus']['status'] ?? '';
                                $buildReady = $buildStatus === 'ready';
                                $runtimeStale = $buildStatus === 'stale';

                                if ($runtimeStale) {
                                    $overallStatus = 'stale';
                                    $overallStatusText = 'Rebuild required';
                                } elseif ($imageExists) {
                                    if ($containerRunning) {
                                        $overallStatus = 'running';
                                        $overallStatusText = 'Running';
                                    } else {
                                        $overallStatus = 'stopped';
                                        $overallStatusText = 'Stopped';
                                    }
                                } elseif ($buildReady) {
                                    $overallStatus = 'stopped';
                                    $overallStatusText = 'Built';
                                }
                            }
                        ?>
                        <div class="app-runtime" id="runtime-<?= $app['id'] ?>">
                            <div class="runtime-header" onclick="toggleRuntime('<?= $app['id'] ?>')">
                                <div class="runtime-header-left">
                                    <i class="fas fa-chevron-right runtime-toggle" id="toggle-<?= $app['id'] ?>"></i>
                                    <i class="fab fa-docker runtime-docker-icon"></i>
                                    <span class="runtime-label-text">Runtime</span>
                                </div>
                                <div class="runtime-summary">
                                    <span class="runtime-summary-badge <?= $overallStatus ?>">
                                        <?= $overallStatusText ?>
                                    </span>
                                </div>
                            </div>
                            <div class="runtime-details" id="details-<?= $app['id'] ?>" onclick="event.stopPropagation()">
                                <?php if ($isPlaywright): ?>
                                    <?php
                                        $dockerAvailable = $playwrightRuntimeStatus['dockerAvailable'] ?? false;
                                        $imageExists = $playwrightRuntimeStatus['imageExists'] ?? false;
                                        $runningContainers = (int)($playwrightRuntimeStatus['runningContainers'] ?? 0);
                                        $buildStatus = $playwrightRuntimeStatus['buildStatus']['status'] ?? '';
                                        $isBuilding = $buildStatus === 'building';
                                        $buildFailed = $buildStatus === 'failed';
                                    ?>
                                    <div class="runtime-status">
                                        <div class="runtime-item">
                                            <span class="runtime-item-label">Runner Image</span>
                                            <span class="status-badge <?=
                                                !$dockerAvailable ? 'error' :
                                                ($imageExists ? 'ready' :
                                                ($isBuilding ? 'building' :
                                                ($buildFailed ? 'error' : 'pending')))
                                            ?>">
                                                <?=
                                                    !$dockerAvailable ? 'Docker unavailable' :
                                                    ($imageExists ? '✓ Built' :
                                                    ($isBuilding ? '… Building' :
                                                    ($buildFailed ? '✕ Failed' : '○ Not built')))
                                                ?>
                                            </span>
                                        </div>
                                        <div class="runtime-item">
                                            <span class="runtime-item-label">Active Runs</span>
                                            <span class="status-badge <?= $runningContainers > 0 ? 'ready' : ($dockerAvailable ? 'pending' : 'error') ?>">
                                                <?=
                                                    !$dockerAvailable ? 'Docker unavailable' :
                                                    ($runningContainers > 0 ? '✓ ' . $runningContainers . ' running' : '○ None')
                                                ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="runtime-actions">
                                        <?php if (!$dockerAvailable): ?>
                                            <span class="runtime-muted">Docker unavailable</span>
                                        <?php elseif ($isBuilding): ?>
                                            <span class="runtime-muted">Build in progress...</span>
                                        <?php elseif (!$imageExists): ?>
                                            <button class="btn btn-primary btn-sm" data-action="build-playwright-runner" onclick="event.stopPropagation(); buildPlaywrightRunner('<?= $app['id'] ?>', this)" title="Build Playwright Runner">
                                                <i class="fas fa-hammer"></i>
                                                <span>Build</span>
                                            </button>
                                        <?php else: ?>
                                            <button class="btn btn-secondary btn-sm" data-action="rebuild-playwright-runner" onclick="event.stopPropagation(); rebuildPlaywrightRunner('<?= $app['id'] ?>', this)" title="Rebuild Playwright Runner">
                                                <i class="fas fa-redo"></i>
                                                <span>Rebuild</span>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="runtime-status">
                                        <div class="runtime-item">
                                            <span class="runtime-item-label">Image</span>
                                            <span class="status-badge <?= $runtimeStale ? 'stale' : ($imageExists ? 'ready' : 'pending') ?>">
                                                <?= $runtimeStale ? '! Rebuild required' : ($imageExists ? '✓ Built' : ($buildReady ? '✓ Built (check)' : '○ Not built')) ?>
                                            </span>
                                        </div>
                                        <div class="runtime-item">
                                            <span class="runtime-item-label">Container</span>
                                            <span class="status-badge <?= $runtimeStale ? 'stale' : ($containerRunning ? 'ready' : 'pending') ?>">
                                                <?= $runtimeStale ? ($containerRunning ? '! Running old runtime' : '! Restart after rebuild') : ($containerRunning ? '✓ Running' : '○ Stopped') ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="runtime-actions">
                                        <?php if ($runtimeStale): ?>
                                            <button class="btn btn-warning btn-sm" data-action="rebuild-image" onclick="event.stopPropagation(); rebuildAppImage('<?= $app['id'] ?>', this)" title="Rebuild Runtime Image">
                                                <i class="fas fa-rotate"></i>
                                                <span>Rebuild</span>
                                            </button>
                                        <?php elseif (!$imageExists && !$buildReady): ?>
                                            <button class="btn btn-primary btn-sm" data-action="build-image" onclick="event.stopPropagation(); buildAppImage('<?= $app['id'] ?>', this)" title="Build Runtime Image">
                                                <i class="fas fa-hammer"></i>
                                                <span>Build</span>
                                            </button>
                                        <?php elseif (!$containerRunning): ?>
                                            <button class="btn btn-success btn-sm" data-action="start-container" onclick="event.stopPropagation(); startAppContainer('<?= $app['id'] ?>', this)" title="Start Container">
                                                <i class="fas fa-play"></i>
                                                <span>Start</span>
                                            </button>
                                            <button class="btn btn-secondary btn-sm" data-action="rebuild-image" onclick="event.stopPropagation(); rebuildAppImage('<?= $app['id'] ?>', this)" title="Rebuild Image">
                                                <i class="fas fa-redo"></i>
                                                <span>Rebuild</span>
                                            </button>
                                        <?php else: ?>
                                            <button class="btn btn-warning btn-sm" data-action="stop-container" onclick="event.stopPropagation(); stopAppContainer('<?= $app['id'] ?>', this)" title="Stop Container">
                                                <i class="fas fa-stop"></i>
                                                <span>Stop</span>
                                            </button>
                                            <button class="btn btn-secondary btn-sm" data-action="rebuild-image" onclick="event.stopPropagation(); rebuildAppImage('<?= $app['id'] ?>', this)" title="Rebuild Image">
                                                <i class="fas fa-redo"></i>
                                                <span>Rebuild</span>
                                            </button>
                                        <?php endif; ?>
                                        <button class="btn btn-danger btn-sm" data-action="cleanup-runtime" onclick="event.stopPropagation(); cleanupRuntime('<?= $app['id'] ?>', this)" title="Cleanup Runtime">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if ($hasRequiredServices): ?>
                        <div class="app-runtime" id="services-<?= $app['id'] ?>">
                            <div class="runtime-header" onclick="toggleRuntime('services-<?= $app['id'] ?>')">
                                <div class="runtime-header-left">
                                    <i class="fas fa-chevron-right runtime-toggle" id="toggle-services-<?= $app['id'] ?>"></i>
                                    <i class="fas fa-cubes runtime-docker-icon"></i>
                                    <span class="runtime-label-text">Services</span>
                                </div>
                                <div class="runtime-summary">
                                    <span class="runtime-summary-badge <?= $servicesOverallStatus ?>">
                                        <?= htmlspecialchars($servicesOverallText) ?>
                                    </span>
                                </div>
                            </div>
                            <div class="runtime-details" id="details-services-<?= $app['id'] ?>" onclick="event.stopPropagation()">
                                <?php if (!$servicesOverview['canManage']): ?>
                                    <div class="runtime-muted"><?= htmlspecialchars($servicesOverview['error'] ?? 'This app cannot manage Docker services at its current trust level.') ?></div>
                                <?php else: ?>
                                    <div class="runtime-status">
                                        <?php foreach ($servicesOverview['services'] as $service): ?>
                                        <?php
                                            $serviceHealthy = !empty($service['healthy']);
                                            $serviceRunning = !empty($service['running']);
                                            $serviceBadge = $serviceHealthy ? 'ready' : ($serviceRunning ? 'building' : 'pending');
                                            $serviceLabel = $serviceHealthy ? 'Ready' : ($serviceRunning ? 'Starting' : 'Stopped');
                                        ?>
                                        <div class="runtime-item">
                                            <span class="runtime-item-label"><?= htmlspecialchars((string)($service['name'] ?? 'service')) ?></span>
                                            <span class="status-badge <?= $serviceBadge ?>">
                                                <?= htmlspecialchars($serviceLabel) ?>
                                            </span>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="runtime-actions">
                                        <?php if (!$servicesOverview['allHealthy']): ?>
                                            <button class="btn btn-success btn-sm" data-action="start-services" onclick="event.stopPropagation(); startAppServices('<?= $app['id'] ?>', this)" title="Start Required Services">
                                                <i class="fas fa-play"></i>
                                                <span>Start</span>
                                            </button>
                                        <?php endif; ?>
                                        <?php if (!empty($servicesOverview['anyRunning'])): ?>
                                            <button class="btn btn-warning btn-sm" data-action="stop-services" onclick="event.stopPropagation(); stopAppServices('<?= $app['id'] ?>', this)" title="Stop Required Services">
                                                <i class="fas fa-stop"></i>
                                                <span>Stop</span>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                    <div class="runtime-muted" style="margin-top: 10px;">
                                        Required service images are pulled automatically the first time they are started.
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($hasCustomRuntime || $isPlaywright || $hasRequiredServices): ?>
                        <hr class="app-card-divider">
                        <?php endif; ?>

                        <?php if ($usesExecDocker): ?>
                        <div class="app-exec-target">
                            <div class="exec-target-row">
                                <span class="exec-target-label">Docker Target</span>
                                <select class="exec-target-select" data-committed-value="<?= htmlspecialchars($execTargetId) ?>" onchange="updateAppExecTarget('<?= $app['id'] ?>', this.value, this)">
                                    <option value="">Local (default)</option>
                                    <?php if (empty($dockerTargets)): ?>
                                        <option value="" disabled>No verified Docker targets</option>
                                    <?php endif; ?>
                                    <?php foreach ($dockerTargets as $target): ?>
                                        <option value="<?= htmlspecialchars($target['id']) ?>" <?= $execTargetId === $target['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($target['name'] ?? $target['id']) ?> (<?= htmlspecialchars($target['id']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="exec-target-hint">
                                Used by apps that run Docker workloads. Manage targets in <a href="../config/targets.php">Targets</a>.
                                <?php if (empty($dockerTargets)): ?>
                                    <span style="display:block; color: #f97316; margin-top: 6px;">No verified Docker targets found. Verify a target to use it here.</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="app-card-footer">
                            <div class="app-source">
                                <?php if ($installed && $installed['source'] === 'git'): ?>
                                <i class="fab fa-git-alt"></i>
                                <?= htmlspecialchars($installed['sourceId'] ?? 'git') ?>
                                <?php elseif ($installed && $installed['source'] === 'studio'): ?>
                                <i class="fas fa-screwdriver-wrench"></i>
                                App Studio
                                <?php elseif ($app['core'] ?? false): ?>
                                <i class="fas fa-cog"></i>
                                Core
                                <?php else: ?>
                                <i class="fas fa-folder"></i>
                                Local
                                <?php endif; ?>
                            </div>
                            <div class="app-actions">
                                <?php 
                                $isCore = ($app['core'] ?? false);
                                $hasOtherActions = !$isCore && ($isSuperAdmin || ($installed && in_array(($installed['source'] ?? ''), ['git', 'studio'], true)));
                                
                                // Show actions menu for all apps (core apps can have priority/pinned, but not other actions)
                                ?>
                                <div class="app-actions-menu" id="menu-<?= $app['id'] ?>">
                                    <button class="app-actions-toggle" onclick="toggleActionsMenu('<?= $app['id'] ?>')" title="App actions">
                                        <span>Actions</span>
                                        <i class="fas fa-chevron-down"></i>
                                    </button>
                                    <div class="app-actions-dropdown">
                                        <?php 
                                        // Organization controls (Batch 3) - at top of dropdown (available for all apps)
                                        ?>
                                        <div class="app-org-controls">
                                            <div class="app-org-item">
                                                <label>Priority</label>
                                                <input type="number" 
                                                       class="app-priority-input" 
                                                       value="<?= $priority ?>" 
                                                       min="0" 
                                                       max="100"
                                                       data-committed-value="<?= $priority ?>"
                                                       data-app-id="<?= $app['id'] ?>"
                                                       onchange="updateAppPriority('<?= $app['id'] ?>', this.value, this)">
                                            </div>
                                            <div class="app-org-item">
                                                <label>
                                                    <input type="checkbox" 
                                                           class="app-pinned-checkbox" 
                                                           <?= $pinned ? 'checked' : '' ?>
                                                           data-committed-value="<?= $pinned ? '1' : '0' ?>"
                                                           data-app-id="<?= $app['id'] ?>"
                                                           onchange="updateAppPinned('<?= $app['id'] ?>', this.checked, this)">
                                                    Pin to Top
                                                </label>
                                            </div>
                                        </div>
                                        
                                        <?php if ($hasOtherActions): ?>
                                        <div class="app-actions-dropdown-divider"></div>
                                        <?php endif; ?>
                                        
                                        <?php if (!$isCore && $isSuperAdmin): ?>
                                        <button class="app-actions-dropdown-item" onclick="showTrustModal('<?= $app['id'] ?>', <?= $trustLevel ?>); closeActionsMenu('<?= $app['id'] ?>')">
                                            <i class="fas fa-shield-halved"></i>
                                            <span>Change Trust Level</span>
                                        </button>
                                        <?php endif; ?>
                                        <?php if (!$isCore && $installed && in_array(($installed['source'] ?? ''), ['git', 'studio'], true)): ?>
                                        <button class="app-actions-dropdown-item" onclick="showUpdateModal('<?= $app['id'] ?>'); closeActionsMenu('<?= $app['id'] ?>')">
                                            <i class="fas fa-sync"></i>
                                            <span>Update App</span>
                                        </button>
                                        <?php endif; ?>
                                        <?php if ($hasOtherActions): ?>
                                        <div class="app-actions-dropdown-divider"></div>
                                        <?php endif; ?>
                                        <?php if (!$isCore): ?>
                                        <button class="app-actions-dropdown-item danger" onclick="confirmUninstall('<?= $app['id'] ?>', '<?= htmlspecialchars($app['name']) ?>'); closeActionsMenu('<?= $app['id'] ?>')">
                                            <i class="fas fa-trash"></i>
                                            <span>Uninstall</span>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Available Apps Tab -->
            <div class="tab-content" id="tab-available" style="display: none;">
                <div id="available-apps-container">
                    <div class="empty-state">
                        <i class="fas fa-spinner fa-spin"></i>
                        <h3>Loading available apps...</h3>
                    </div>
                </div>
            </div>

            <!-- Sources Tab -->
            <div class="tab-content" id="tab-sources" style="display: none;">
                <!-- Notice about Doki Sources -->
                <div style="padding: 16px 20px; background: var(--accent-glow); border: 1px solid var(--accent-primary); border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between;">
                    <div>
                        <strong style="color: var(--accent-primary);">App Sources are now managed in Doki Sources</strong>
                        <p style="margin: 4px 0 0 0; color: var(--text-secondary); font-size: 13px;">
                            Repositories are shared across Apps, Templates, and Playwright projects.
                        </p>
                    </div>
                    <a href="sources.php" class="btn btn-primary">
                        <i class="fas fa-external-link-alt"></i>
                        Manage Sources
                    </a>
                </div>

                <?php if (empty($sources)): ?>
                <div class="empty-state">
                    <i class="fas fa-code-branch"></i>
                    <h3>No app repositories configured</h3>
                    <p>Add a repository with type "Apps" or "Mixed" in Doki Sources</p>
                </div>
                <?php else: ?>
                <div class="sources-list">
                    <?php foreach ($sources as $source): ?>
                    <div class="source-card" id="source-card-<?= htmlspecialchars($source['id']) ?>" data-source-id="<?= htmlspecialchars($source['id']) ?>">
                        <div class="source-info">
                            <div class="source-icon">
                                <i class="fab fa-git-alt"></i>
                            </div>
                            <div>
                                <h4 class="source-name"><?= htmlspecialchars($source['name'] ?? $source['id']) ?></h4>
                                <div class="source-url"><?= htmlspecialchars($source['url']) ?></div>
                            </div>
                        </div>
                        <div class="source-actions">
                            <button class="btn btn-secondary btn-sm" onclick="syncSource('<?= $source['id'] ?>', this)">
                                <i class="fas fa-sync"></i>
                                Sync
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Add Source Modal -->
    <div class="modal-backdrop" id="addSourceModal">
        <div class="modal">
            <div class="modal-header">
                <h2><i class="fas fa-plus"></i> Add App Source</h2>
                <button class="modal-close" onclick="closeModal('addSourceModal')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Source ID *</label>
                    <input type="text" class="form-input" id="sourceId" placeholder="my-company-apps">
                    <div class="form-hint">Unique identifier for this source</div>
                </div>
                <div class="form-group">
                    <label class="form-label">Name</label>
                    <input type="text" class="form-input" id="sourceName" placeholder="My Company Apps">
                </div>
                <div class="form-group">
                    <label class="form-label">Git URL *</label>
                    <input type="text" class="form-input" id="sourceUrl" placeholder="https://github.com/org/doki-apps.git">
                </div>
                <div class="form-group">
                    <label class="form-label">Branch</label>
                    <input type="text" class="form-input" id="sourceBranch" value="main">
                </div>
                <div class="form-group">
                    <label class="form-label">Git Provider (for private repos)</label>
                    <select class="form-select" id="sourceProvider">
                        <option value="">-- None (public repo) --</option>
                        <!-- Populated dynamically -->
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Default Trust Level for Apps</label>
                    <select class="form-select" id="sourceDefaultTrust">
                        <option value="3" selected>🔒 Sandboxed (Default)</option>
                        <option value="2">🛡️ Trusted</option>
                        <option value="1">✅ Verified</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('addSourceModal')">Cancel</button>
                <button class="btn btn-primary" id="addSourceButton" onclick="addSource()">
                    <i class="fas fa-plus"></i>
                    Add Source
                </button>
            </div>
        </div>
    </div>

    <!-- Trust Level Modal -->
    <div class="modal-backdrop" id="trustModal">
        <div class="modal">
            <div class="modal-header">
                <h2><i class="fas fa-shield-halved"></i> Change Trust Level</h2>
                <button class="modal-close" onclick="closeModal('trustModal')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="trustAppId">
                
                <div class="trust-options">
                    <label class="trust-option" data-level="3">
                        <input type="radio" name="trustLevel" value="3">
                        <div class="trust-option-icon" style="background: rgba(245, 158, 11, 0.1); color: #f59e0b;">
                            <i class="fas fa-lock"></i>
                        </div>
                        <div class="trust-option-content">
                            <div class="trust-option-name">Sandboxed</div>
                            <div class="trust-option-desc">Restricted - UI only, no execution or config access</div>
                        </div>
                    </label>
                    <label class="trust-option" data-level="2">
                        <input type="radio" name="trustLevel" value="2">
                        <div class="trust-option-icon" style="background: rgba(59, 130, 246, 0.1); color: #3b82f6;">
                            <i class="fas fa-shield-halved"></i>
                        </div>
                        <div class="trust-option-content">
                            <div class="trust-option-name">Trusted</div>
                            <div class="trust-option-desc">Can execute commands, access targets and secrets</div>
                        </div>
                    </label>
                    <label class="trust-option" data-level="1">
                        <input type="radio" name="trustLevel" value="1">
                        <div class="trust-option-icon" style="background: rgba(34, 197, 94, 0.1); color: #22c55e;">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="trust-option-content">
                            <div class="trust-option-name">Verified</div>
                            <div class="trust-option-desc">Full access except user management (official apps)</div>
                        </div>
                    </label>
                </div>
                
                <div class="form-group" style="margin-top: 16px;">
                    <label class="form-label">Reason for change *</label>
                    <input type="text" class="form-input" id="trustReason" placeholder="Code reviewed by security team">
                    <div class="form-hint">This will be logged in the audit trail</div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('trustModal')">Cancel</button>
                <button class="btn btn-primary" id="saveTrustButton" onclick="saveTrustLevel()">
                    <i class="fas fa-save"></i>
                    Save
                </button>
            </div>
        </div>
    </div>

    <!-- Install Modal -->
    <div class="modal-backdrop" id="installModal">
        <div class="modal">
            <div class="modal-header">
                <h2><i class="fas fa-download"></i> Install App</h2>
                <button class="modal-close" onclick="closeModal('installModal')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <div id="installAppInfo"></div>
                
                <div class="form-group" style="margin-top: 16px;">
                    <label class="form-label">Trust Level</label>
                    <select class="form-select" id="installTrustLevel">
                        <option value="3" selected>🔒 Sandboxed (Recommended)</option>
                        <?php if ($isSuperAdmin): ?>
                        <option value="2">🛡️ Trusted</option>
                        <option value="1">✅ Verified</option>
                        <?php endif; ?>
                    </select>
                    <div class="form-hint" id="installTrustHint">Sandboxed apps have restricted capabilities</div>
                    <div class="form-hint" id="installTrustRequirements" style="margin-top: 8px;"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('installModal')">Cancel</button>
                <button class="btn btn-primary" id="installAppButton" onclick="installApp()">
                    <i class="fas fa-download"></i>
                    Install
                </button>
            </div>
        </div>
    </div>

    <!-- Update Modal -->
    <div class="modal-backdrop" id="updateModal">
        <div class="modal">
            <div class="modal-header">
                <h2><i class="fas fa-sync"></i> Review Update</h2>
                <button class="modal-close" onclick="closeUpdateModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <div id="updatePreviewContent">
                    <div class="empty-state">
                        <i class="fas fa-spinner fa-spin"></i>
                        <h3>Loading update preview...</h3>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeUpdateModal()">Cancel</button>
                <button class="btn btn-primary" id="confirmUpdateButton" onclick="confirmUpdateApp()" disabled>
                    <i class="fas fa-sync"></i>
                    Update App
                </button>
            </div>
        </div>
    </div>

    <script>
        let currentTab = 'installed';
        let availableApps = [];
        let installTarget = null;
        let updateTarget = null;
        let updatePreview = null;
        const isSuperAdmin = <?= $isSuperAdmin ? 'true' : 'false' ?>;
        const appBusyStates = new Map();
        const busyButtonStates = new WeakMap();

        function delay(ms) {
            return new Promise(resolve => setTimeout(resolve, ms));
        }

        function parseHtmlDocument(html) {
            return new DOMParser().parseFromString(html, 'text/html');
        }

        async function fetchAdminPageDocument() {
            const response = await fetch(window.location.pathname + window.location.search, {
                headers: { 'X-Requested-With': 'fetch' }
            });

            if (!response.ok) {
                throw new Error('Failed to refresh Apps admin page');
            }

            return parseHtmlDocument(await response.text());
        }

        function importElementFromDocument(doc, selector) {
            const element = doc.querySelector(selector);
            return element ? document.importNode(element, true) : null;
        }

        function syncTabCountsFromDocument(doc) {
            ['installed-count', 'sources-count'].forEach(id => {
                const current = document.getElementById(id);
                const fresh = doc.getElementById(id);
                if (current && fresh) {
                    current.textContent = fresh.textContent;
                }
            });
        }

        function captureCardExpandedState(appId) {
            return {
                runtime: document.getElementById(`details-${appId}`)?.classList.contains('expanded') ?? false,
                services: document.getElementById(`details-services-${appId}`)?.classList.contains('expanded') ?? false,
            };
        }

        function setExpandedState(toggleId, detailsId, expanded) {
            const toggle = document.getElementById(toggleId);
            const details = document.getElementById(detailsId);
            if (!toggle || !details) {
                return;
            }

            toggle.classList.toggle('expanded', expanded);
            details.classList.toggle('expanded', expanded);
        }

        function restoreCardExpandedState(appId, state) {
            setExpandedState(`toggle-${appId}`, `details-${appId}`, !!state?.runtime);
            setExpandedState(`toggle-services-${appId}`, `details-services-${appId}`, !!state?.services);
        }

        function setButtonBusy(button, label) {
            if (!button) {
                return;
            }

            if (!busyButtonStates.has(button)) {
                busyButtonStates.set(button, {
                    html: button.innerHTML,
                    disabled: button.disabled,
                });
            }

            button.disabled = true;
            button.classList.add('is-busy');
            button.innerHTML = `<i class="fas fa-spinner fa-spin"></i><span>${escapeHtml(label)}</span>`;
        }

        function restoreButton(button) {
            if (!button) {
                return;
            }

            const previous = busyButtonStates.get(button);
            if (!previous) {
                return;
            }

            button.innerHTML = previous.html;
            button.disabled = previous.disabled;
            button.classList.remove('is-busy');
            busyButtonStates.delete(button);
        }

        function applyAppBusyState(appId) {
            const card = document.getElementById(`app-card-${appId}`);
            if (!card) {
                return;
            }

            const state = appBusyStates.get(appId);

            card.querySelectorAll('[data-action]').forEach(button => {
                if (state && button.dataset.action === state.action) {
                    setButtonBusy(button, state.buttonLabel || state.message);
                    delete button.dataset.wasAppBusyDisabled;
                } else {
                    restoreButton(button);
                    if (state) {
                        if (!Object.prototype.hasOwnProperty.call(button.dataset, 'wasAppBusyDisabled')) {
                            button.dataset.wasAppBusyDisabled = button.disabled ? '1' : '0';
                        }
                        button.disabled = true;
                    } else if (Object.prototype.hasOwnProperty.call(button.dataset, 'wasAppBusyDisabled')) {
                        button.disabled = button.dataset.wasAppBusyDisabled === '1';
                        delete button.dataset.wasAppBusyDisabled;
                    }
                }
            });
        }

        function setAppBusyState(appId, state) {
            appBusyStates.set(appId, state);
            applyAppBusyState(appId);
        }

        function clearAppBusyState(appId) {
            appBusyStates.delete(appId);
            applyAppBusyState(appId);
        }

        function setControlBusy(control, busy) {
            if (!control) {
                return;
            }

            if (busy) {
                control.dataset.wasDisabled = control.disabled ? '1' : '0';
                control.disabled = true;
                control.classList.add('is-busy');
                return;
            }

            if (Object.prototype.hasOwnProperty.call(control.dataset, 'wasDisabled')) {
                control.disabled = control.dataset.wasDisabled === '1';
                delete control.dataset.wasDisabled;
            }
            control.classList.remove('is-busy');
        }

        async function refreshInstalledTab(doc = null) {
            const pageDoc = doc || await fetchAdminPageDocument();
            const fresh = importElementFromDocument(pageDoc, '#tab-installed');
            const current = document.getElementById('tab-installed');

            if (fresh && current) {
                fresh.style.display = current.style.display;
                current.replaceWith(fresh);
            }

            syncTabCountsFromDocument(pageDoc);
            appBusyStates.forEach((state, appId) => applyAppBusyState(appId));
        }

        async function refreshSidebarAppsNav(doc = null) {
            const pageDoc = doc || await fetchAdminPageDocument();
            const current = document.getElementById('apps-list');
            const fresh = importElementFromDocument(pageDoc, '#apps-list');

            if (fresh && current) {
                current.replaceWith(fresh);
            }

            if (typeof window.initSidebarAppDragAndDrop === 'function') {
                window.initSidebarAppDragAndDrop();
            }

            if (typeof window.applySidebarSearch === 'function') {
                window.applySidebarSearch();
            }
        }

        async function refreshSourcesTab() {
            const doc = await fetchAdminPageDocument();
            const fresh = importElementFromDocument(doc, '#tab-sources');
            const current = document.getElementById('tab-sources');

            if (fresh && current) {
                fresh.style.display = current.style.display;
                current.replaceWith(fresh);
            }

            syncTabCountsFromDocument(doc);
        }

        async function refreshAppCard(appId) {
            const current = document.getElementById(`app-card-${appId}`);
            if (!current) {
                await refreshInstalledTab();
                return;
            }

            const expandedState = captureCardExpandedState(appId);
            const doc = await fetchAdminPageDocument();
            const fresh = importElementFromDocument(doc, `#app-card-${appId}`);

            syncTabCountsFromDocument(doc);

            if (!fresh) {
                await refreshInstalledTab();
                return;
            }

            current.replaceWith(fresh);
            restoreCardExpandedState(appId, expandedState);
            applyAppBusyState(appId);
        }

        function setSourceBusyState(sourceId, busy) {
            const card = document.getElementById(`source-card-${sourceId}`);
            if (!card) {
                return;
            }

            card.classList.toggle('is-busy', busy);
        }

        async function runJsonRequest(url, options = {}) {
            const response = await fetch(url, options);
            let data = null;

            try {
                data = await response.json();
            } catch (e) {
                throw new Error('Unexpected server response');
            }

            if (!response.ok || !data?.success) {
                const error = new Error(data?.error || 'Request failed');
                error.data = data;
                throw error;
            }

            return data;
        }

        function getAppActionMeta(action) {
            const meta = {
                'build-image': {
                    message: 'Building runtime image...',
                    buttonLabel: 'Building...',
                    success: 'Build complete!',
                    error: 'Build failed',
                },
                'rebuild-image': {
                    message: 'Rebuilding runtime image...',
                    buttonLabel: 'Rebuilding...',
                    success: 'Rebuild complete!',
                    error: 'Rebuild failed',
                },
                'start-container': {
                    message: 'Starting container...',
                    buttonLabel: 'Starting...',
                    success: 'Container started',
                    error: 'Failed to start container',
                },
                'stop-container': {
                    message: 'Stopping container...',
                    buttonLabel: 'Stopping...',
                    success: 'Container stopped',
                    error: 'Failed to stop container',
                },
                'start-services': {
                    message: 'Starting required services...',
                    buttonLabel: 'Starting...',
                    success: 'Required services are ready.',
                    error: 'Failed to start required services',
                },
                'stop-services': {
                    message: 'Stopping required services...',
                    buttonLabel: 'Stopping...',
                    success: 'Required services stopped.',
                    error: 'Failed to stop required services',
                },
                'cleanup-runtime': {
                    message: 'Cleaning up runtime...',
                    buttonLabel: 'Cleaning...',
                    success: 'Runtime cleaned up',
                    error: 'Cleanup failed',
                },
                'build-playwright-runner': {
                    message: 'Building Playwright runner...',
                    buttonLabel: 'Building...',
                    success: 'Build complete!',
                    error: 'Build failed',
                },
                'rebuild-playwright-runner': {
                    message: 'Rebuilding Playwright runner...',
                    buttonLabel: 'Rebuilding...',
                    success: 'Rebuild complete!',
                    error: 'Rebuild failed',
                },
            };

            return meta[action] || {
                message: 'Working...',
                buttonLabel: 'Working...',
                success: 'Done',
                error: 'Action failed',
            };
        }

        function formatVersionMessage(version) {
            if (!version?.current) return '';

            if (version.direction === 'upgrade') {
                return `Updated to v${version.current}.`;
            }

            if (version.direction === 'downgrade') {
                return `Updated to v${version.current} (lower than the previous manifest version).`;
            }

            if (version.changed) {
                return `Updated to v${version.current}.`;
            }

            return `App updated. Manifest version remains v${version.current}.`;
        }

        function formatUpdateSuccessMessage(data) {
            const parts = [];
            const versionMessage = formatVersionMessage(data.version);
            if (versionMessage) {
                parts.push(versionMessage);
            }

            if (data.runtime?.rebuildRecommended) {
                parts.push('Runtime changed. Rebuild the app image before using the new runtime.');
            } else if (data.runtime?.cleanupPerformed) {
                parts.push('Old custom runtime was cleaned up automatically.');
            } else if (data.runtime?.cleanupRecommended) {
                parts.push('Custom runtime is no longer needed. Cleanup is recommended.');
            }

            return parts.join(' ') || 'App updated.';
        }

        function formatTrustUpgradeMessage(data) {
            const required = data.requiredTrustInfo?.name || 'a higher trust level';
            const current = data.currentTrustInfo?.name || 'the current trust level';
            const action = isSuperAdmin
                ? 'Raise the app trust level, then run the update again.'
                : 'A super-admin must raise the app trust level before this update can be applied.';

            return `${data.error || 'Update blocked.'} Current trust is ${current}; required trust is ${required}. ${action}`;
        }

        // Tab switching
        function switchTab(tab) {
            currentTab = tab;
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelector(`[data-tab="${tab}"]`).classList.add('active');
            
            document.querySelectorAll('.tab-content').forEach(c => c.style.display = 'none');
            document.getElementById(`tab-${tab}`).style.display = 'block';
            
            if (tab === 'available') {
                loadAvailableApps();
            }
        }

        // Load available apps
        async function loadAvailableApps() {
            try {
                const response = await fetch('../api/marketplace.php?action=available-apps');
                const data = await response.json();
                
                if (data.success) {
                    availableApps = data.apps;
                    document.getElementById('available-count').textContent = availableApps.length;
                    renderAvailableApps();
                }
            } catch (error) {
                console.error('Failed to load available apps:', error);
            }
        }

        function renderAvailableApps() {
            const container = document.getElementById('available-apps-container');
            
            if (availableApps.length === 0) {
                container.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-box-open"></i>
                        <h3>No apps available</h3>
                        <p>Sync your sources or publish an app from Studio</p>
                    </div>
                `;
                return;
            }
            
            container.innerHTML = `
                <div class="apps-grid">
                    ${availableApps.map(app => `
                        <div class="app-card">
                            <div class="app-card-header">
                                <div class="app-icon" style="background: ${app.color || '#6366f1'}">
                                    <i class="fas ${app.icon || 'fa-puzzle-piece'}"></i>
                                </div>
                                <div class="app-info">
                                    <h3 class="app-name">
                                        ${escapeHtml(app.name || app.id)}
                                        <span class="app-version">v${getAvailableAppDisplayVersion(app)}</span>
                                    </h3>
                                    <p class="app-desc">${escapeHtml(app.description || '')}</p>
                                    ${renderAvailableVersionDelta(app)}
                                </div>
                            </div>
                            <div class="app-card-footer">
                                <div class="app-source">
                                    <i class="${escapeHtml(getAvailableSourceMeta(app).icon)}"></i>
                                    ${escapeHtml(getAvailableSourceMeta(app).label)}
                                </div>
                                <div class="app-actions">
                                    ${app.installed 
                                        ? '<span style="color: var(--success); font-size: 12px;"><i class="fas fa-check"></i> Installed</span>'
                                        : `<button class="btn btn-primary btn-sm" onclick="showInstallModal('${app.sourceId}', '${app.id}')">
                                            <i class="fas fa-download"></i> Install
                                           </button>`
                                    }
                                </div>
                            </div>
                        </div>
                    `).join('')}
                </div>
            `;
        }

        function getAvailableSourceMeta(app) {
            if (app?.sourceKind === 'studio' || app?.sourceId === 'studio-local') {
                return {
                    icon: 'fas fa-screwdriver-wrench',
                    label: app.sourceName || 'App Studio',
                };
            }

            return {
                icon: 'fab fa-git-alt',
                label: app?.sourceName || app?.sourceId || 'Source',
            };
        }

        function getAvailableAppDisplayVersion(app) {
            const displayVersion = app.installed ? (app.installedVersion || app.version) : app.version;
            return escapeHtml(displayVersion || '1.0.0');
        }

        function renderAvailableVersionDelta(app) {
            const delta = app.versionDelta;
            if (!app.installed || !delta?.changed) {
                return '';
            }

            let badgeClass = 'source-changed';
            let icon = 'fa-code-compare';
            let text = `Source version: v${escapeHtml(delta.current || 'unknown')}`;

            if (delta.status === 'source_newer') {
                badgeClass = 'source-newer';
                icon = 'fa-arrow-up';
                text = `Source newer: v${escapeHtml(delta.current || 'unknown')}`;
            } else if (delta.status === 'source_older') {
                badgeClass = 'source-older';
                icon = 'fa-arrow-down';
                text = `Source older: v${escapeHtml(delta.current || 'unknown')}`;
            }

            return `<div class="app-version-delta ${badgeClass}"><i class="fas ${icon}"></i><span>${text}</span></div>`;
        }

        // Modal helpers
        function showModal(id) {
            document.getElementById(id).classList.add('active');
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('active');
        }

        function closeUpdateModal() {
            updateTarget = null;
            updatePreview = null;
            document.getElementById('updatePreviewContent').innerHTML = `
                <div class="empty-state">
                    <i class="fas fa-spinner fa-spin"></i>
                    <h3>Loading update preview...</h3>
                </div>
            `;
            const button = document.getElementById('confirmUpdateButton');
            button.disabled = true;
            button.innerHTML = '<i class="fas fa-sync"></i> Update App';
            closeModal('updateModal');
        }

        function showAddSourceModal() {
            // Load git providers for private repos
            loadGitProviders();
            showModal('addSourceModal');
        }

        async function loadGitProviders() {
            try {
                // Use SourcesManager API for unified git providers
                const response = await fetch('../api/sources.php?action=providers');
                const data = await response.json();
                
                const select = document.getElementById('sourceProvider');
                select.innerHTML = '<option value="">-- None (public repo) --</option>';
                
                if (data.success && data.providers) {
                    data.providers.forEach(p => {
                        select.innerHTML += `<option value="${p.id}">${p.name || p.id}</option>`;
                    });
                }
            } catch (e) {
                console.error('Failed to load git providers:', e);
            }
        }

        function getUpdateDirectionMeta(version = {}) {
            switch (version.direction) {
                case 'upgrade':
                    return {
                        label: 'Upgrade',
                        icon: 'fa-arrow-up',
                        badgeClass: 'upgrade',
                        detail: `Installed v${version.previous || 'unknown'} to source v${version.current || 'unknown'}.`,
                    };
                case 'downgrade':
                    return {
                        label: 'Downgrade',
                        icon: 'fa-arrow-down',
                        badgeClass: 'downgrade',
                        detail: `Installed v${version.previous || 'unknown'} to older source v${version.current || 'unknown'}.`,
                    };
                case 'changed':
                    return {
                        label: 'Changed',
                        icon: 'fa-code-compare',
                        badgeClass: 'warning',
                        detail: `Installed and source manifests differ (${version.previous || 'unknown'} -> ${version.current || 'unknown'}).`,
                    };
                default:
                    return {
                        label: 'Refresh',
                        icon: 'fa-rotate',
                        badgeClass: 'success',
                        detail: `Source manifest remains v${version.current || 'unknown'}.`,
                    };
            }
        }

        function getTrustReviewText(preview) {
            if (preview.requiresTrustUpgrade) {
                return `Blocked. Current trust is ${preview.currentTrustInfo?.name || 'unknown'} and the new manifest requires ${preview.requiredTrustInfo?.name || 'a higher level'}.`;
            }

            if (preview.trustChange?.changed) {
                const from = preview.trustChange.previousMinimumTrustInfo?.name || 'unknown';
                const to = preview.trustChange.currentMinimumTrustInfo?.name || 'unknown';
                return `Minimum required trust changes from ${from} to ${to}.`;
            }

            return `Minimum required trust remains ${preview.requiredTrustInfo?.name || 'unknown'}.`;
        }

        function getRuntimeReviewText(runtime = {}) {
            if (runtime.rebuildRecommended) {
                return 'Runtime requirements changed. Rebuild the app image after the update.';
            }

            if (runtime.cleanupRecommended) {
                return 'Custom runtime is no longer needed. Cleanup will be recommended after the update.';
            }

            if (runtime.changed) {
                return 'Runtime configuration changed.';
            }

            return 'No runtime changes detected.';
        }

        function getSafeExternalUrl(url) {
            if (typeof url !== 'string') return null;
            return /^https?:\/\//i.test(url.trim()) ? url.trim() : null;
        }

        function renderUpdatePreview(preview) {
            const direction = getUpdateDirectionMeta(preview.version);
            const heroClass = preview.requiresTrustUpgrade || preview.breaking
                ? 'danger'
                : (preview.version?.direction === 'downgrade' || preview.runtime?.rebuildRecommended ? 'warning' : '');
            const flags = [];
            const releases = Array.isArray(preview.releases) ? preview.releases : [];

            flags.push(`<span class="update-review-flag ${direction.badgeClass}"><i class="fas ${direction.icon}"></i>${escapeHtml(direction.label)}</span>`);

            if (preview.breaking) {
                flags.push('<span class="update-review-flag danger"><i class="fas fa-triangle-exclamation"></i>Breaking change</span>');
            }

            if (preview.runtime?.rebuildRecommended) {
                flags.push('<span class="update-review-flag warning"><i class="fas fa-hammer"></i>Runtime rebuild</span>');
            } else if (preview.runtime?.cleanupRecommended) {
                flags.push('<span class="update-review-flag warning"><i class="fas fa-broom"></i>Runtime cleanup</span>');
            }

            if (preview.requiresTrustUpgrade) {
                flags.push('<span class="update-review-flag danger"><i class="fas fa-shield-halved"></i>Trust upgrade required</span>');
            } else {
                flags.push('<span class="update-review-flag success"><i class="fas fa-circle-check"></i>Can update now</span>');
            }

            const releaseNotes = Array.isArray(preview.releaseNotes) ? preview.releaseNotes : [];
            const currentSourceVersion = typeof preview.version?.current === 'string' ? preview.version.current.trim() : '';
            const targetReleaseInHistory = currentSourceVersion
                ? releases.find(release => typeof release.version === 'string' && release.version.trim() === currentSourceVersion)
                : null;
            const shouldShowTargetReleaseNotes = releaseNotes.length > 0 && (!targetReleaseInHistory || !Array.isArray(targetReleaseInHistory.notes) || targetReleaseInHistory.notes.length === 0);
            const changelogUrl = getSafeExternalUrl(preview.changelogUrl);
            const updateScopeText = releases.length > 0
                ? `This update includes ${releases.length} release${releases.length === 1 ? '' : 's'}.`
                : (changelogUrl ? 'Changelog is available for this source release.' : 'No changelog URL provided by the manifest.');
            const releasesSection = releases.length > 0 ? `
                <div class="update-review-section">
                    <h4>Included releases</h4>
                    <div class="update-release-list">
                        ${releases.map(release => {
                            const releaseChangelogUrl = getSafeExternalUrl(release.changelogUrl);
                            return `
                                <div class="update-release-item">
                                    <div class="update-release-header">
                                        <div class="update-release-title">v${escapeHtml(release.version || 'unknown')}</div>
                                        <div class="update-release-meta">
                                            ${release.date ? `<span>${escapeHtml(release.date)}</span>` : ''}
                                            ${release.breaking ? '<span class="update-review-flag danger"><i class="fas fa-triangle-exclamation"></i>Breaking</span>' : ''}
                                        </div>
                                    </div>
                                    ${Array.isArray(release.notes) && release.notes.length > 0 ? `
                                        <ul class="update-review-list">
                                            ${release.notes.map(note => `<li>${escapeHtml(note)}</li>`).join('')}
                                        </ul>
                                    ` : '<p>No release notes provided.</p>'}
                                    ${releaseChangelogUrl ? `<p style="margin-top: 10px;"><a class="update-review-link" href="${escapeHtml(releaseChangelogUrl)}" target="_blank" rel="noopener noreferrer">${escapeHtml(releaseChangelogUrl)}</a></p>` : ''}
                                </div>
                            `;
                        }).join('')}
                    </div>
                </div>
            ` : '';

            return `
                <div class="update-review">
                    <div class="update-review-hero ${heroClass}">
                        <h3>${escapeHtml(preview.appName || preview.appId)}</h3>
                        <p>${escapeHtml(direction.detail)}</p>
                    </div>
                    <div class="update-review-grid">
                        <div class="update-review-card">
                            <span class="update-review-label">Installed</span>
                            <div class="update-review-value">v${escapeHtml(preview.version?.previous || 'unknown')}</div>
                            <div class="update-review-subvalue">${escapeHtml(preview.currentTrustInfo?.name || 'Unknown trust')}</div>
                        </div>
                        <div class="update-review-card">
                            <span class="update-review-label">Source</span>
                            <div class="update-review-value">v${escapeHtml(preview.version?.current || 'unknown')}</div>
                            <div class="update-review-subvalue">${escapeHtml(preview.sourceName || preview.sourceId || 'Unknown source')}</div>
                        </div>
                    </div>
                    <div class="update-review-flags">
                        ${flags.join('')}
                    </div>
                    <div class="update-review-section">
                        <h4>What changes</h4>
                        <ul class="update-review-list">
                            <li>${escapeHtml(getTrustReviewText(preview))}</li>
                            <li>${escapeHtml(getRuntimeReviewText(preview.runtime || {}))}</li>
                            <li>${escapeHtml(updateScopeText)}</li>
                        </ul>
                    </div>
                    ${releasesSection}
                    ${shouldShowTargetReleaseNotes ? `
                        <div class="update-review-section">
                            <h4>${releases.length > 0 ? 'Target release notes' : 'Release notes'}</h4>
                            <ul class="update-review-list">
                                ${releaseNotes.map(note => `<li>${escapeHtml(note)}</li>`).join('')}
                            </ul>
                        </div>
                    ` : ''}
                    ${changelogUrl ? `
                        <div class="update-review-section">
                            <h4>Changelog</h4>
                            <p><a class="update-review-link" href="${escapeHtml(changelogUrl)}" target="_blank" rel="noopener noreferrer">${escapeHtml(changelogUrl)}</a></p>
                        </div>
                    ` : ''}
                </div>
            `;
        }

        async function showUpdateModal(appId) {
            updateTarget = appId;
            updatePreview = null;

            document.getElementById('updatePreviewContent').innerHTML = `
                <div class="empty-state">
                    <i class="fas fa-spinner fa-spin"></i>
                    <h3>Loading update preview...</h3>
                </div>
            `;

            const button = document.getElementById('confirmUpdateButton');
            button.disabled = true;
            button.innerHTML = '<i class="fas fa-sync"></i> Update App';
            showModal('updateModal');

            try {
                const response = await fetch(`../api/marketplace.php?action=preview-update&id=${appId}`);
                const data = await response.json();

                if (!data.success) {
                    closeUpdateModal();
                    window.Toast?.error(data.error || 'Failed to load update preview');
                    return;
                }

                updatePreview = data;
                document.getElementById('updatePreviewContent').innerHTML = renderUpdatePreview(data);
                button.disabled = !data.canUpdate;
            } catch (e) {
                closeUpdateModal();
                window.Toast?.error('Failed to load update preview');
            }
        }

        // Add source
        async function addSource() {
            const button = document.getElementById('addSourceButton');
            const source = {
                id: document.getElementById('sourceId').value.trim(),
                name: document.getElementById('sourceName').value.trim(),
                url: document.getElementById('sourceUrl').value.trim(),
                branch: document.getElementById('sourceBranch').value.trim() || 'main',
                provider: document.getElementById('sourceProvider').value || null,
                defaultTrust: parseInt(document.getElementById('sourceDefaultTrust').value)
            };
            
            if (!source.id || !source.url) {
                window.Toast?.error('ID and URL are required');
                return;
            }

            setButtonBusy(button, 'Adding...');
            try {
                await runJsonRequest('../api/marketplace.php?action=add-source', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(source)
                });

                closeModal('addSourceModal');
                await refreshSourcesTab();
                await loadAvailableApps();
                window.Toast?.success('Source added');
            } catch (e) {
                window.Toast?.error(e.message || 'Failed to add source');
            } finally {
                restoreButton(button);
            }
        }

        // Sync source (uses SourcesManager API)
        async function syncSource(sourceId, button) {
            if (button?.classList.contains('is-busy')) {
                return;
            }

            setSourceBusyState(sourceId, true);
            setButtonBusy(button, 'Syncing...');
            window.Toast?.info('Syncing source...');

            try {
                const data = await runJsonRequest(`../api/sources.php?action=sync-repository&id=${sourceId}`, {
                    method: 'POST'
                });

                const apps = data.discovered?.apps || [];
                await loadAvailableApps();
                window.Toast?.success(`Synced! Found ${apps.length} app(s)`);
            } catch (e) {
                window.Toast?.error(e.message || 'Sync failed');
            } finally {
                restoreButton(button);
                setSourceBusyState(sourceId, false);
            }
        }

        // Remove source
        async function removeSource(sourceId) {
            if (!confirm('Remove this source? Installed apps will remain.')) return;
            
            try {
                await runJsonRequest(`../api/marketplace.php?action=remove-source&id=${sourceId}`, {
                    method: 'POST'
                });

                await refreshSourcesTab();
                await loadAvailableApps();
                window.Toast?.success('Source removed');
            } catch (e) {
                window.Toast?.error(e.message || 'Failed to remove source');
            }
        }

        // Trust level
        function showTrustModal(appId, currentLevel) {
            document.getElementById('trustAppId').value = appId;
            document.getElementById('trustReason').value = '';
            
            document.querySelectorAll('.trust-option').forEach(opt => {
                opt.classList.remove('selected');
                if (parseInt(opt.dataset.level) === currentLevel) {
                    opt.classList.add('selected');
                    opt.querySelector('input').checked = true;
                }
            });
            
            showModal('trustModal');
        }

        document.querySelectorAll('.trust-option').forEach(opt => {
            opt.addEventListener('click', function() {
                document.querySelectorAll('.trust-option').forEach(o => o.classList.remove('selected'));
                this.classList.add('selected');
                this.querySelector('input').checked = true;
            });
        });

        async function saveTrustLevel() {
            const appId = document.getElementById('trustAppId').value;
            const level = parseInt(document.querySelector('.trust-option.selected input').value);
            const reason = document.getElementById('trustReason').value.trim();
            const button = document.getElementById('saveTrustButton');
            
            if (!reason) {
                window.Toast?.error('Reason is required');
                return;
            }

            setButtonBusy(button, 'Saving...');
            setAppBusyState(appId, {
                action: 'trust-level',
                message: 'Saving trust level...',
                buttonLabel: 'Saving...',
            });
            try {
                await runJsonRequest('../api/marketplace.php?action=set-trust', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ appId, trustLevel: level, reason })
                });

                closeModal('trustModal');
                await refreshAppCard(appId);
                clearAppBusyState(appId);
                window.Toast?.success('Trust level updated');
            } catch (e) {
                await refreshAppCard(appId).catch(() => {});
                clearAppBusyState(appId);
                const message = e.message || 'Failed to update trust level';
                window.Toast?.error(message);
            } finally {
                restoreButton(button);
            }
        }

        // Install
        function showInstallModal(sourceId, appId) {
            installTarget = { sourceId, appId };
            
            const app = availableApps.find(a => a.id === appId && a.sourceId === sourceId);
            if (app) {
                document.getElementById('installAppInfo').innerHTML = `
                    <div style="display: flex; align-items: center; gap: 16px; padding: 16px; background: var(--bg-tertiary); border-radius: 8px;">
                        <div class="app-icon" style="background: ${app.color || '#6366f1'}">
                            <i class="fas ${app.icon || 'fa-puzzle-piece'}"></i>
                        </div>
                        <div>
                            <h3 style="margin: 0 0 4px 0;">${escapeHtml(app.name || app.id)}</h3>
                            <p style="margin: 0; font-size: 13px; color: var(--text-muted);">${escapeHtml(app.description || '')}</p>
                        </div>
                    </div>
                `;

                const select = document.getElementById('installTrustLevel');
                const hint = document.getElementById('installTrustHint');
                const requirements = document.getElementById('installTrustRequirements');
                const installButton = document.getElementById('installAppButton');
                const trustProfile = app.trustProfile || {};
                const minimumLevel = trustProfile.minimumTrustLevel;
                const defaultLevel = trustProfile.installDefaultTrustLevel ?? minimumLevel ?? 3;
                const minimumName = trustProfile.minimumTrustInfo?.name || 'Unknown';
                const reason = trustProfile.reason || '';

                Array.from(select.options).forEach(option => {
                    const level = parseInt(option.value, 10);
                    const blockedByRequirement = Number.isInteger(minimumLevel) && level > minimumLevel;
                    option.disabled = blockedByRequirement;
                    option.textContent = option.textContent.replace(' (Recommended)', '');
                });

                const selectedOption = select.querySelector(`option[value="${defaultLevel}"]`) || select.options[0];
                if (selectedOption) {
                    selectedOption.selected = true;
                    if (!selectedOption.textContent.includes('(Recommended)')) {
                        selectedOption.textContent += ' (Recommended)';
                    }
                }

                if (minimumLevel === null) {
                    hint.textContent = 'This app cannot be installed safely because its declared capabilities exceed the supported installable trust levels.';
                    requirements.textContent = '';
                    installButton.disabled = true;
                    installButton.title = 'Unsupported capability requirement';
                } else if (minimumLevel < 3 && select.querySelector(`option[value="${minimumLevel}"]`) === null) {
                    hint.textContent = `This app requires ${minimumName}, but only a super-admin can install it at that level.`;
                    requirements.textContent = reason;
                    installButton.disabled = true;
                    installButton.title = `Requires ${minimumName}`;
                } else {
                    hint.textContent = `Minimum required trust for this app: ${minimumName}.`;
                    requirements.textContent = reason;
                    installButton.disabled = false;
                    installButton.title = '';
                }
            }
            
            showModal('installModal');
        }

        async function installApp() {
            if (!installTarget) return;
            
            const trustLevel = parseInt(document.getElementById('installTrustLevel').value);
            const button = document.getElementById('installAppButton');

            setButtonBusy(button, 'Installing...');
            try {
                const data = await runJsonRequest('../api/marketplace.php?action=install', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        sourceId: installTarget.sourceId,
                        appId: installTarget.appId,
                        trustLevel
                    })
                });

                closeModal('installModal');
                const doc = await fetchAdminPageDocument();
                await refreshInstalledTab(doc);
                await refreshSidebarAppsNav(doc);
                await loadAvailableApps();
                window.Toast?.success('App installed!');
            } catch (e) {
                window.Toast?.error(e.message || 'Installation failed');
            } finally {
                restoreButton(button);
            }
        }

        // Update
        async function confirmUpdateApp() {
            if (!updateTarget || !updatePreview?.canUpdate) {
                return;
            }

            const button = document.getElementById('confirmUpdateButton');
            setButtonBusy(button, 'Updating...');
            setAppBusyState(updateTarget, {
                action: 'update-app',
                message: 'Updating app...',
                buttonLabel: 'Updating...',
            });
            
            try {
                const data = await runJsonRequest(`../api/marketplace.php?action=update&id=${updateTarget}`, {
                    method: 'POST'
                });

                closeUpdateModal();
                await refreshAppCard(updateTarget);
                await loadAvailableApps();
                clearAppBusyState(updateTarget);
                const message = formatUpdateSuccessMessage(data);
                window.Toast?.success(message);
            } catch (e) {
                await refreshAppCard(updateTarget).catch(() => {});
                clearAppBusyState(updateTarget);
                const data = e.data || null;
                const message = data?.requiresTrustUpgrade
                    ? formatTrustUpgradeMessage(data)
                    : (e.message || 'Update failed');
                window.Toast?.error(message);
            } finally {
                restoreButton(button);
            }
        }

        // Uninstall
        async function confirmUninstall(appId, appName) {
            if (!confirm(`Uninstall "${appName}"? This will remove the app code.`)) return;
            
            const deleteData = confirm('Also delete app data?');

            setAppBusyState(appId, {
                action: 'uninstall-app',
                message: 'Uninstalling app...',
                buttonLabel: 'Uninstalling...',
            });
            try {
                await runJsonRequest('../api/marketplace.php?action=uninstall', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ appId, deleteData })
                });

                const doc = await fetchAdminPageDocument();
                await refreshInstalledTab(doc);
                await refreshSidebarAppsNav(doc);
                await loadAvailableApps();
                clearAppBusyState(appId);
                window.Toast?.success('App uninstalled');
            } catch (e) {
                await refreshAppCard(appId).catch(() => {});
                clearAppBusyState(appId);
                const message = e.message || 'Uninstall failed';
                window.Toast?.error(message);
            }
        }

        // Escape HTML
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Runtime management functions
        async function pollRuntimeStatus(appId, meta) {
            let attempts = 0;
            const maxAttempts = 60;

            while (attempts < maxAttempts) {
                try {
                    const response = await fetch(`../api/apps.php?action=image-status&app=${appId}`);
                    const data = await response.json();

                    if (data.success) {
                        const buildStatus = data.build?.status;

                        if (buildStatus === 'ready' && data.status?.imageExists) {
                            clearAppBusyState(appId);
                            await refreshAppCard(appId);
                            window.Toast?.success(meta.success);
                            return;
                        }

                        if (buildStatus === 'failed') {
                            clearAppBusyState(appId);
                            await refreshAppCard(appId);
                            const message = 'Build failed: ' + (data.build?.message || 'Unknown error');
                            window.Toast?.error(message);
                            return;
                        }
                    }
                } catch (e) {
                    console.error('Status poll error:', e);
                }

                attempts += 1;
                await delay(2000);
            }

            clearAppBusyState(appId);
            await refreshAppCard(appId).catch(() => {});
            const message = 'Build is still running. The card can be refreshed again in place.';
            window.Toast?.info(message);
        }

        async function pollPlaywrightRuntimeStatus(appId, meta) {
            let attempts = 0;
            const maxAttempts = 60;

            while (attempts < maxAttempts) {
                try {
                    const response = await fetch('../api/playwright.php?action=runtime-status');
                    const data = await response.json();

                    if (data.success) {
                        const buildStatus = data.status?.buildStatus?.status;
                        const imageExists = data.status?.imageExists;

                        if (buildStatus === 'ready' && imageExists) {
                            clearAppBusyState(appId);
                            await refreshAppCard(appId);
                            window.Toast?.success(meta.success);
                            return;
                        }

                        if (buildStatus === 'failed') {
                            clearAppBusyState(appId);
                            await refreshAppCard(appId);
                            const message = 'Build failed: ' + (data.status?.buildStatus?.message || 'Unknown error');
                            window.Toast?.error(message);
                            return;
                        }
                    }
                } catch (e) {
                    console.error('Playwright status poll error:', e);
                }

                attempts += 1;
                await delay(2000);
            }

            clearAppBusyState(appId);
            await refreshAppCard(appId).catch(() => {});
            const message = 'Build is still running. The card can be refreshed again in place.';
            window.Toast?.info(message);
        }

        async function buildAppImage(appId, button) {
            if (appBusyStates.has(appId)) return;

            const meta = getAppActionMeta('build-image');
            setButtonBusy(button, meta.buttonLabel);
            setAppBusyState(appId, { action: 'build-image', message: meta.message, buttonLabel: meta.buttonLabel });
            window.Toast?.info(meta.message);

            try {
                const data = await runJsonRequest(`../api/apps.php?action=build-image&app=${appId}`);
                await refreshAppCard(appId).catch(() => {});

                if (data.building) {
                    window.Toast?.success(data.message || 'Build started');
                    await pollRuntimeStatus(appId, meta);
                    return;
                }

                clearAppBusyState(appId);
                await refreshAppCard(appId);
                const message = data.message || 'Image already exists';
                window.Toast?.success(message);
            } catch (e) {
                clearAppBusyState(appId);
                await refreshAppCard(appId).catch(() => {});
                const message = e.message || meta.error;
                window.Toast?.error(message);
            } finally {
                restoreButton(button);
            }
        }

        async function startAppContainer(appId, button) {
            if (appBusyStates.has(appId)) return;

            const meta = getAppActionMeta('start-container');
            setButtonBusy(button, meta.buttonLabel);
            setAppBusyState(appId, { action: 'start-container', message: meta.message, buttonLabel: meta.buttonLabel });
            window.Toast?.info(meta.message);

            try {
                const data = await runJsonRequest(`../api/apps.php?action=start-container&app=${appId}`);
                clearAppBusyState(appId);
                await refreshAppCard(appId);
                const message = data.message || meta.success;
                window.Toast?.success(message);
            } catch (e) {
                clearAppBusyState(appId);
                await refreshAppCard(appId).catch(() => {});
                const data = e.data || null;
                if (data?.logs) {
                    console.error('App container logs:', data.logs);
                }
                const details = data?.details ? ` (${data.details})` : '';
                const logHint = data?.logs ? ' (see console for logs)' : '';
                const message = (data?.error || e.message || meta.error) + details + logHint;
                window.Toast?.error(message);
            } finally {
                restoreButton(button);
            }
        }

        async function stopAppContainer(appId, button) {
            if (!confirm('Stop this app container? Users will see "unavailable" until restarted.')) return;
            if (appBusyStates.has(appId)) return;

            const meta = getAppActionMeta('stop-container');
            setButtonBusy(button, meta.buttonLabel);
            setAppBusyState(appId, { action: 'stop-container', message: meta.message, buttonLabel: meta.buttonLabel });

            try {
                const data = await runJsonRequest(`../api/apps.php?action=stop-container&app=${appId}`);
                clearAppBusyState(appId);
                await refreshAppCard(appId);
                const message = data.message || meta.success;
                window.Toast?.success(message);
            } catch (e) {
                clearAppBusyState(appId);
                await refreshAppCard(appId).catch(() => {});
                const message = e.message || meta.error;
                window.Toast?.error(message);
            } finally {
                restoreButton(button);
            }
        }

        async function startAppServices(appId, button) {
            if (appBusyStates.has(appId)) return;

            const meta = getAppActionMeta('start-services');
            setButtonBusy(button, meta.buttonLabel);
            setAppBusyState(appId, { action: 'start-services', message: meta.message, buttonLabel: meta.buttonLabel });
            window.Toast?.info(meta.message);

            try {
                const data = await runJsonRequest(`../api/apps.php?action=start-services&app=${encodeURIComponent(appId)}`);
                clearAppBusyState(appId);
                await refreshAppCard(appId);
                const message = data.overview?.allHealthy ? 'Required services are ready.' : 'Required services started.';
                window.Toast?.success(message);
            } catch (e) {
                clearAppBusyState(appId);
                await refreshAppCard(appId).catch(() => {});
                const message = e.message || meta.error;
                window.Toast?.error(message);
            } finally {
                restoreButton(button);
            }
        }

        async function stopAppServices(appId, button) {
            if (!confirm('Stop the Docker services required by this app?')) return;
            if (appBusyStates.has(appId)) return;

            const meta = getAppActionMeta('stop-services');
            setButtonBusy(button, meta.buttonLabel);
            setAppBusyState(appId, { action: 'stop-services', message: meta.message, buttonLabel: meta.buttonLabel });

            try {
                const data = await runJsonRequest(`../api/apps.php?action=stop-services&app=${encodeURIComponent(appId)}`);
                clearAppBusyState(appId);
                await refreshAppCard(appId);
                const message = data.message || meta.success;
                window.Toast?.success(message);
            } catch (e) {
                clearAppBusyState(appId);
                await refreshAppCard(appId).catch(() => {});
                const message = e.message || meta.error;
                window.Toast?.error(message);
            } finally {
                restoreButton(button);
            }
        }

        async function rebuildAppImage(appId, button) {
            if (!confirm('Rebuild will stop the container and rebuild the image. Continue?')) return;
            if (appBusyStates.has(appId)) return;

            const meta = getAppActionMeta('rebuild-image');
            setButtonBusy(button, meta.buttonLabel);
            setAppBusyState(appId, { action: 'rebuild-image', message: meta.message, buttonLabel: meta.buttonLabel });
            window.Toast?.info(meta.message);

            try {
                const data = await runJsonRequest(`../api/apps.php?action=rebuild-image&app=${appId}`);
                await refreshAppCard(appId).catch(() => {});
                if (data.building) {
                    window.Toast?.success(data.message || 'Rebuild started');
                    await pollRuntimeStatus(appId, meta);
                    return;
                }

                clearAppBusyState(appId);
                await refreshAppCard(appId);
                const message = data.message || meta.success;
                window.Toast?.success(message);
            } catch (e) {
                clearAppBusyState(appId);
                await refreshAppCard(appId).catch(() => {});
                const message = e.message || meta.error;
                window.Toast?.error(message);
            } finally {
                restoreButton(button);
            }
        }

        async function cleanupRuntime(appId, button) {
            if (!confirm('Remove container and image for this app? You can rebuild later.')) return;
            if (appBusyStates.has(appId)) return;

            const meta = getAppActionMeta('cleanup-runtime');
            setButtonBusy(button, meta.buttonLabel);
            setAppBusyState(appId, { action: 'cleanup-runtime', message: meta.message, buttonLabel: meta.buttonLabel });

            try {
                const data = await runJsonRequest(`../api/apps.php?action=cleanup-runtime&app=${appId}`);
                clearAppBusyState(appId);
                await refreshAppCard(appId);
                const message = data.message || meta.success;
                window.Toast?.success(message);
            } catch (e) {
                clearAppBusyState(appId);
                await refreshAppCard(appId).catch(() => {});
                const message = e.message || meta.error;
                window.Toast?.error(message);
            } finally {
                restoreButton(button);
            }
        }

        async function buildPlaywrightRunner(appId, button) {
            if (appBusyStates.has(appId)) return;

            const meta = getAppActionMeta('build-playwright-runner');
            setButtonBusy(button, meta.buttonLabel);
            setAppBusyState(appId, { action: 'build-playwright-runner', message: meta.message, buttonLabel: meta.buttonLabel });
            window.Toast?.info(meta.message);

            try {
                const data = await runJsonRequest('../api/playwright.php?action=build-runner');
                await refreshAppCard(appId).catch(() => {});
                if (data.building || data.success) {
                    window.Toast?.success(data.message || 'Build started');
                    await pollPlaywrightRuntimeStatus(appId, meta);
                    return;
                }
            } catch (e) {
                clearAppBusyState(appId);
                await refreshAppCard(appId).catch(() => {});
                const message = e.message || meta.error;
                window.Toast?.error(message);
            } finally {
                restoreButton(button);
            }
        }

        async function rebuildPlaywrightRunner(appId, button) {
            if (!confirm('Rebuild the Playwright runner image?')) return;
            if (appBusyStates.has(appId)) return;

            const meta = getAppActionMeta('rebuild-playwright-runner');
            setButtonBusy(button, meta.buttonLabel);
            setAppBusyState(appId, { action: 'rebuild-playwright-runner', message: meta.message, buttonLabel: meta.buttonLabel });
            window.Toast?.info(meta.message);

            try {
                const data = await runJsonRequest('../api/playwright.php?action=rebuild-runner');
                await refreshAppCard(appId).catch(() => {});
                if (data.building || data.success) {
                    window.Toast?.success(data.message || 'Rebuild started');
                    await pollPlaywrightRuntimeStatus(appId, meta);
                    return;
                }
            } catch (e) {
                clearAppBusyState(appId);
                await refreshAppCard(appId).catch(() => {});
                const message = e.message || meta.error;
                window.Toast?.error(message);
            } finally {
                restoreButton(button);
            }
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
                document.querySelectorAll('.app-actions-menu.open').forEach(m => m.classList.remove('open'));
            }
        });

        // Actions menu toggle
        function toggleActionsMenu(appId) {
            const menu = document.getElementById('menu-' + appId);
            const isOpen = menu.classList.contains('open');
            
            // Close all other menus
            document.querySelectorAll('.app-actions-menu.open').forEach(m => m.classList.remove('open'));
            
            // Toggle this menu
            if (!isOpen) {
                menu.classList.add('open');
            }
        }

        function closeActionsMenu(appId) {
            const menu = document.getElementById('menu-' + appId);
            menu.classList.remove('open');
        }

        // Close menus when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.app-actions-menu')) {
                document.querySelectorAll('.app-actions-menu.open').forEach(m => m.classList.remove('open'));
            }
        });

        // App Organization Functions (Batch 3)
        async function updateAppPriority(appId, priority, control) {
            const priorityNum = parseInt(priority);
            if (isNaN(priorityNum) || priorityNum < 0 || priorityNum > 100) {
                window.Toast?.error('Priority must be between 0 and 100');
                if (control) {
                    const previous = control.dataset.committedValue ?? control.defaultValue ?? '50';
                    control.value = previous;
                }
                return;
            }

            const previous = control ? (control.dataset.committedValue ?? control.defaultValue ?? String(priorityNum)) : String(priorityNum);
            setControlBusy(control, true);
            try {
                await runJsonRequest(`../api/apps.php?action=set-priority&app=${appId}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ priority: priorityNum })
                });

                if (control) {
                    control.dataset.committedValue = String(priorityNum);
                }
                window.Toast?.success('Priority updated');
            } catch (e) {
                if (control) {
                    control.value = previous;
                }
                window.Toast?.error(e.message || 'Failed to update priority');
            } finally {
                setControlBusy(control, false);
            }
        }

        async function updateAppPinned(appId, pinned, control) {
            const previous = control ? (control.dataset.committedValue ?? (control.defaultChecked ? '1' : '0')) : (pinned ? '1' : '0');
            setControlBusy(control, true);
            try {
                // Admin page: set global default (affects all users)
                await runJsonRequest(`../api/apps.php?action=set-pinned&app=${appId}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ pinned: pinned, global: true })
                });

                if (control) {
                    control.dataset.committedValue = pinned ? '1' : '0';
                    control.defaultChecked = pinned;
                }
                window.Toast?.success(pinned ? 'App pinned' : 'App unpinned');
            } catch (e) {
                if (control) {
                    control.checked = previous === '1';
                }
                window.Toast?.error(e.message || 'Failed to update pinned status');
            } finally {
                setControlBusy(control, false);
            }
        }

        async function updateAppExecTarget(appId, targetId, control) {
            const previous = control ? (control.dataset.committedValue ?? control.value) : targetId;
            setControlBusy(control, true);
            try {
                await runJsonRequest('../api/marketplace.php?action=set-exec-target', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ appId, targetId })
                });

                if (control) {
                    control.dataset.committedValue = targetId;
                }
                window.Toast?.success(targetId ? 'Exec target updated' : 'Exec target cleared');
            } catch (e) {
                if (control) {
                    control.value = previous;
                }
                window.Toast?.error(e.message || 'Failed to update exec target');
            } finally {
                setControlBusy(control, false);
            }
        }

        // Runtime toggle
        function toggleRuntime(appId) {
            const toggle = document.getElementById('toggle-' + appId);
            const details = document.getElementById('details-' + appId);
            
            const isExpanded = details.classList.contains('expanded');
            
            if (isExpanded) {
                details.classList.remove('expanded');
                toggle.classList.remove('expanded');
            } else {
                details.classList.add('expanded');
                toggle.classList.add('expanded');
            }
        }
    </script>
</body>
</html>
