<?php
/**
 * admin/settings.php - Doki system administration
 */

require_once __DIR__ . '/../includes/middleware.php';
require_once __DIR__ . '/../includes/Layout.php';
require_once __DIR__ . '/../includes/SystemModuleRegistry.php';
require_once __DIR__ . '/../includes/SystemSettingsManager.php';
require_once __DIR__ . '/../includes/StealthGuard.php';

$user = requireRole('admin');
requireModuleAccess('doki.admin');

$layout = new Layout($user, 'doki.admin');
$settingsManager = new SystemSettingsManager();

$initialState = [
    'modules' => $settingsManager->getModuleEditorData(),
    'moduleOptions' => $settingsManager->getModuleOptions(),
    'sections' => array_values(SystemModuleRegistry::getSectionDefinitions()),
    'security' => $settingsManager->getRuntimeStealthSettings(),
    'secret' => [
        'ready' => StealthGuard::isSecretReady(),
        'path' => StealthGuard::getSecretPath(),
    ],
];

$initialStateJson = json_encode($initialState, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $layout->renderHead('Doki Admin'); ?>
    <style>
        .page-shell {
            padding: 28px;
            max-width: 1500px;
            margin: 0 auto;
        }

        .page-header {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 24px;
        }

        .page-title {
            margin: 0;
            font-size: 32px;
            font-weight: 700;
        }

        .page-subtitle {
            margin: 8px 0 0;
            max-width: 800px;
            color: var(--text-secondary);
            line-height: 1.5;
        }

        .page-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 999px;
            border: 1px solid var(--border-primary);
            background: var(--bg-secondary);
            color: var(--text-secondary);
            font-size: 13px;
            white-space: nowrap;
        }

        .tabs {
            display: flex;
            gap: 6px;
            margin-bottom: 22px;
            border-bottom: 1px solid var(--border-primary);
            padding-bottom: 10px;
        }

        .tab {
            border: 0;
            padding: 10px 16px;
            border-radius: 10px 10px 0 0;
            background: transparent;
            color: var(--text-secondary);
            cursor: pointer;
            font: inherit;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .tab:hover {
            background: var(--bg-tertiary);
            color: var(--text-primary);
        }

        .tab.active {
            background: var(--accent-primary);
            color: #fff;
        }

        .tab-panel {
            display: none;
        }

        .tab-panel.active {
            display: block;
        }

        .tab-toolbar {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 18px;
            margin-bottom: 14px;
        }

        .tab-toolbar-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .panel-title {
            margin: 0;
            font-size: 15px;
            font-weight: 700;
        }

        .panel-copy {
            margin: 6px 0 0;
            color: var(--text-secondary);
            font-size: 13px;
            line-height: 1.45;
        }

        .surface {
            background: var(--bg-secondary);
            border: 1px solid var(--border-primary);
            border-radius: 16px;
            overflow: hidden;
        }

        .modules-surface {
            padding: 18px;
        }

        .modules-options {
            margin-bottom: 18px;
        }

        .modules-board {
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .module-section-card {
            display: flex;
            flex-direction: column;
            min-width: 0;
            border: 1px solid var(--border-primary);
            border-radius: 16px;
            background: rgba(255,255,255,0.03);
            overflow: hidden;
        }

        .module-section-head {
            padding: 16px 16px 14px;
            border-bottom: 1px solid var(--border-primary);
        }

        .module-section-title {
            margin: 0;
            font-size: 15px;
            font-weight: 700;
        }

        .module-section-note {
            margin: 6px 0 0;
            color: var(--text-secondary);
            font-size: 12px;
            line-height: 1.45;
        }

        .module-section-list {
            min-height: 0;
            min-width: 0;
            padding: 12px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            transition: background 0.15s ease, box-shadow 0.15s ease;
        }

        .module-section-list.is-drop-target {
            background: rgba(88, 166, 255, 0.08);
            box-shadow: inset 0 0 0 1px rgba(88, 166, 255, 0.22);
        }

        .drop-indicator {
            height: 10px;
            border-radius: 999px;
            background: rgba(88, 166, 255, 0.24);
            border: 1px solid rgba(88, 166, 255, 0.45);
            box-shadow: 0 0 0 1px rgba(88, 166, 255, 0.14);
            pointer-events: none;
        }

        .module-card {
            cursor: default;
        }

        .module-card[draggable="true"] {
            cursor: grab;
        }

        .module-card.dragging {
            opacity: 0.45;
        }

        .empty-drop-hint {
            min-height: 110px;
            border: 1px dashed var(--border-primary);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            color: var(--text-secondary);
            font-size: 13px;
            line-height: 1.45;
            padding: 14px;
        }

        .split-layout {
            display: grid;
            grid-template-columns: 360px minmax(0, 1fr);
            min-height: 760px;
            height: min(80vh, 960px);
        }

        .security-layout {
            display: grid;
            grid-template-columns: minmax(0, 1.4fr) 360px;
            gap: 0;
            min-height: 680px;
        }

        .list-panel,
        .security-side {
            border-right: 1px solid var(--border-primary);
            background: rgba(255,255,255,0.02);
            display: flex;
            flex-direction: column;
            min-height: 0;
        }

        .detail-panel,
        .security-main {
            min-height: 0;
            display: flex;
            flex-direction: column;
        }

        .panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 18px 20px;
            border-bottom: 1px solid var(--border-primary);
        }

        .panel-header.tight {
            align-items: flex-start;
        }

        .detail-scroll,
        .security-scroll,
        .module-list {
            min-height: 0;
            overflow-y: auto;
        }

        .detail-scroll,
        .security-scroll {
            padding: 22px;
        }

        .module-list {
            padding: 14px;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .module-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .module-group-title {
            padding: 0 4px;
            color: var(--text-muted);
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .module-group-items {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .module-item {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            align-items: start;
            gap: 12px;
            min-width: 0;
            padding: 14px;
            border-radius: 14px;
            border: 1px solid var(--border-primary);
            background: var(--bg-primary);
            transition: border-color 0.15s ease, background 0.15s ease, transform 0.15s ease;
            outline: none;
        }

        .module-item:hover,
        .module-item:focus-visible {
            border-color: var(--accent-primary);
            background: rgba(255,255,255,0.03);
        }

        .module-item.selected {
            border-color: var(--accent-primary);
            box-shadow: 0 0 0 1px rgba(88, 166, 255, 0.18);
            background: linear-gradient(180deg, rgba(88, 166, 255, 0.08), rgba(255,255,255,0.02));
        }

        .module-item.is-disabled {
            opacity: 0.7;
        }

        .module-drag-handle {
            width: 30px;
            height: 30px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            align-self: center;
            color: var(--text-secondary);
            flex-shrink: 0;
            border-radius: 10px;
            border: 1px solid rgba(255,255,255,0.08);
            background: rgba(255,255,255,0.05);
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.04);
            font-size: 12px;
        }

        .module-drag-handle.is-static {
            color: var(--text-muted);
            background: rgba(255,255,255,0.03);
        }

        .module-card[draggable="true"] .module-drag-handle {
            cursor: grab;
        }

        .module-card[draggable="true"]:hover .module-drag-handle {
            color: var(--accent-primary);
            border-color: rgba(88, 166, 255, 0.28);
            background: rgba(88, 166, 255, 0.1);
        }

        .module-item-main {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            min-width: 0;
        }

        .module-item-copy {
            min-width: 0;
            flex: 1;
        }

        .module-item-icon,
        .detail-icon {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: rgba(88, 166, 255, 0.12);
            color: var(--accent-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .detail-icon {
            width: 56px;
            height: 56px;
            font-size: 20px;
        }

        .module-item-content {
            min-width: 0;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .module-item-title-row,
        .module-item-footer,
        .module-item-badges,
        .badge-row {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .module-item-footer {
            justify-content: flex-end;
            align-self: center;
        }

        .module-item-badges {
            min-width: 0;
            justify-content: flex-start;
        }

        .module-item-title {
            font-weight: 600;
            color: var(--text-primary);
            min-width: 0;
            overflow-wrap: anywhere;
        }

        .module-item-meta {
            color: var(--text-secondary);
            font-size: 12px;
            line-height: 1.4;
            overflow-wrap: anywhere;
            word-break: break-word;
        }

        .pill,
        .dirty-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
            border: 1px solid transparent;
        }

        .pill.neutral {
            background: rgba(255,255,255,0.06);
            color: var(--text-secondary);
        }

        .pill.info {
            background: rgba(88, 166, 255, 0.12);
            color: #8ec5ff;
        }

        .pill.warning,
        .dirty-pill {
            background: rgba(234, 179, 8, 0.16);
            border-color: rgba(234, 179, 8, 0.28);
            color: #facc15;
        }

        .dirty-pill[hidden] {
            display: none !important;
        }

        .pill.success {
            background: rgba(63, 185, 80, 0.16);
            color: #7ee787;
        }

        .pill.danger {
            background: rgba(248, 81, 73, 0.16);
            color: #fca5a5;
        }

        .toggle-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 8px;
            border-radius: 999px;
            border: 1px solid var(--border-primary);
            background: rgba(255,255,255,0.05);
            color: var(--text-secondary);
            cursor: pointer;
            flex-shrink: 0;
            max-width: 100%;
            font-size: 11px;
            font-weight: 700;
            line-height: 1;
            white-space: nowrap;
        }

        .toggle-pill input {
            margin: 0;
            width: 13px;
            height: 13px;
        }

        .toggle-pill.is-on {
            background: rgba(63, 185, 80, 0.14);
            color: #7ee787;
            border-color: rgba(63, 185, 80, 0.2);
        }

        .toggle-pill.is-off {
            background: rgba(248, 81, 73, 0.08);
            color: var(--text-muted);
        }

        .detail-hero {
            display: flex;
            align-items: flex-start;
            gap: 16px;
            margin-bottom: 18px;
        }

        .detail-title {
            margin: 0;
            font-size: 24px;
            font-weight: 700;
        }

        .detail-description {
            margin: 8px 0 0;
            color: var(--text-secondary);
            line-height: 1.5;
        }

        .note-box {
            margin-top: 16px;
            padding: 14px 16px;
            border-radius: 14px;
            border: 1px solid rgba(88, 166, 255, 0.18);
            background: rgba(88, 166, 255, 0.08);
            color: var(--text-secondary);
            line-height: 1.5;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
            margin-top: 18px;
        }

        .detail-card,
        .summary-card {
            border: 1px solid var(--border-primary);
            border-radius: 14px;
            background: rgba(255,255,255,0.03);
        }

        .detail-card {
            padding: 14px 16px;
        }

        .detail-card-label {
            display: block;
            font-size: 12px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 8px;
        }

        .detail-card-value {
            color: var(--text-primary);
            line-height: 1.45;
            word-break: break-word;
        }

        .detail-card-value.mono,
        .mono-block {
            font-family: ui-monospace, SFMono-Regular, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
            font-size: 12px;
        }

        .detail-section {
            margin-top: 20px;
        }

        .detail-section-title {
            margin: 0 0 8px;
            font-size: 16px;
            font-weight: 700;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }

        .form-field {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .form-label-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }

        .form-label {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-primary);
        }

        .form-input,
        .form-select {
            width: 100%;
            padding: 11px 12px;
            border-radius: 10px;
            border: 1px solid var(--border-primary);
            background: var(--bg-primary);
            color: var(--text-primary);
            font: inherit;
        }

        .form-input:focus,
        .form-select:focus {
            outline: none;
            border-color: var(--accent-primary);
            box-shadow: 0 0 0 1px rgba(88, 166, 255, 0.15);
        }

        .field-hint {
            margin: 10px 0 0;
            color: var(--text-secondary);
            font-size: 13px;
            line-height: 1.45;
        }

        .field-override {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 3px 8px;
            border-radius: 999px;
            background: rgba(234, 179, 8, 0.14);
            color: #facc15;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .field-override[hidden] {
            display: none !important;
        }

        .toggle-card {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 18px;
            border: 1px solid var(--border-primary);
            border-radius: 14px;
            background: rgba(255,255,255,0.03);
        }

        .toggle-copy {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .toggle-label {
            font-size: 15px;
            font-weight: 700;
            color: var(--text-primary);
        }

        .toggle-hint {
            color: var(--text-secondary);
            line-height: 1.5;
        }

        .summary-card {
            margin: 18px;
            padding: 18px;
        }

        .summary-card:first-child {
            margin-top: 20px;
        }

        .summary-title {
            margin: 0 0 12px;
            font-size: 16px;
            font-weight: 700;
        }

        .summary-stack {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .summary-line {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            color: var(--text-secondary);
            font-size: 13px;
        }

        .summary-line strong {
            color: var(--text-primary);
            font-weight: 600;
        }

        .mono-block {
            padding: 10px 12px;
            border-radius: 10px;
            border: 1px solid var(--border-primary);
            background: var(--bg-primary);
            color: var(--text-secondary);
            line-height: 1.5;
            word-break: break-all;
        }

        .override-list {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 14px;
            border-radius: 10px;
            border: 1px solid var(--border-primary);
            background: var(--bg-primary);
            color: var(--text-primary);
            cursor: pointer;
            font: inherit;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
        }

        .btn[hidden] {
            display: none !important;
        }

        .btn:hover {
            border-color: var(--accent-primary);
            color: var(--accent-primary);
        }

        .btn:disabled {
            cursor: not-allowed;
            color: var(--text-muted);
            background: rgba(255,255,255,0.04);
            border-color: rgba(255,255,255,0.08);
            box-shadow: none;
            opacity: 1;
        }

        .btn:disabled:hover {
            color: var(--text-muted);
            border-color: rgba(255,255,255,0.08);
        }

        .btn.primary {
            background: var(--accent-primary);
            border-color: var(--accent-primary);
            color: #fff;
        }

        .btn.primary:hover {
            color: #fff;
            opacity: 0.92;
        }

        .btn.primary:disabled {
            background: rgba(255,255,255,0.06);
            border-color: rgba(255,255,255,0.08);
            color: var(--text-muted);
        }

        .btn.secondary {
            background: var(--bg-primary);
        }

        .btn.danger {
            color: #fca5a5;
        }

        .btn.is-loading {
            opacity: 0.72;
            pointer-events: none;
        }

        .status-line {
            margin-bottom: 14px;
            padding: 11px 14px;
            border-radius: 12px;
            border: 1px solid transparent;
            font-size: 13px;
        }

        .status-line[hidden] {
            display: none !important;
        }

        .status-line.success {
            background: rgba(63, 185, 80, 0.14);
            border-color: rgba(63, 185, 80, 0.2);
            color: #7ee787;
        }

        .status-line.error {
            background: rgba(248, 81, 73, 0.14);
            border-color: rgba(248, 81, 73, 0.18);
            color: #fca5a5;
        }

        .empty-state {
            padding: 22px;
            border: 1px dashed var(--border-primary);
            border-radius: 14px;
            color: var(--text-secondary);
            text-align: center;
            line-height: 1.5;
        }

        @media (max-width: 1180px) {
            .split-layout,
            .security-layout {
                grid-template-columns: 1fr;
                height: auto;
            }

            .list-panel,
            .security-side {
                border-right: 0;
                border-bottom: 1px solid var(--border-primary);
            }

            .detail-grid,
            .form-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .page-shell {
                padding: 18px;
            }

            .page-header,
            .tab-toolbar {
                flex-direction: column;
                align-items: stretch;
            }

            .tab-toolbar-actions {
                justify-content: flex-start;
            }

            .module-item {
                grid-template-columns: 1fr;
            }

            .module-item-footer {
                justify-content: flex-start;
                align-self: stretch;
            }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <?php $layout->renderSidebar(); ?>
        <main class="main-content">
            <div class="content-area">
                <div class="page-shell">
                    <div class="page-header">
                        <div>
                            <h1 class="page-title">Doki Admin</h1>
                            <p class="page-subtitle">Arrange the modules people see across Doki, control which features stay active system-wide, and manage stealth login behavior from one place.</p>
                        </div>
                        <div class="page-chip">
                            <i class="fas fa-sliders"></i>
                            <span>`doki.admin` permission required</span>
                        </div>
                    </div>

                    <div class="tabs">
                        <button class="tab active" type="button" data-tab="modules"><i class="fas fa-table-cells-large"></i> Modules</button>
                        <button class="tab" type="button" data-tab="security"><i class="fas fa-shield-halved"></i> Security</button>
                    </div>

                    <section class="tab-panel active" data-panel="modules">
                        <div class="tab-toolbar">
                            <div>
                                <h2 class="panel-title">Modules</h2>
                                <p class="panel-copy">Decide what appears in navigation, move modules between sections, and control whether module restrictions also apply to super-admins.</p>
                            </div>
                            <div class="tab-toolbar-actions">
                                <span class="dirty-pill" id="modules-dirty-pill" hidden></span>
                                <button class="btn secondary" type="button" id="modules-reset-btn" hidden>Reset</button>
                                <button class="btn primary" type="button" id="modules-save-btn" disabled>
                                    <i class="fas fa-floppy-disk"></i>
                                    Save Modules
                                </button>
                            </div>
                        </div>
                        <div class="status-line" id="modules-status" hidden></div>

                        <div class="surface modules-surface">
                            <div class="modules-options">
                                <label class="toggle-card">
                                    <span class="toggle-copy">
                                        <span class="toggle-label">Also apply to super-admin</span>
                                        <span class="toggle-hint">Keep this on if disabled modules should also disappear for super-admin users and block direct access.</span>
                                    </span>
                                    <span>
                                        <input type="checkbox" id="modules-apply-to-super-admin">
                                    </span>
                                </label>
                            </div>
                            <div class="modules-board" id="modules-board"></div>
                        </div>
                    </section>

                    <section class="tab-panel" data-panel="security">
                        <div class="tab-toolbar">
                            <div>
                                <h2 class="panel-title">Security</h2>
                                <p class="panel-copy">Control stealth login behavior system-wide. Environment variables still win at runtime when they are present.</p>
                            </div>
                            <div class="tab-toolbar-actions">
                                <span class="dirty-pill" id="security-dirty-pill" hidden></span>
                                <button class="btn secondary" type="button" id="security-reset-btn" hidden>Reset</button>
                                <button class="btn primary" type="button" id="security-save-btn" disabled>
                                    <i class="fas fa-floppy-disk"></i>
                                    Save Security
                                </button>
                            </div>
                        </div>
                        <div class="status-line" id="security-status" hidden></div>

                        <div class="surface security-layout">
                            <div class="security-main">
                                <div class="security-scroll">
                                    <div class="detail-section" style="margin-top: 0;">
                                        <h3 class="detail-section-title">Stealth Login</h3>
                                        <label class="toggle-card">
                                            <span class="toggle-copy">
                                                <span class="form-label-row">
                                                    <span class="toggle-label">Enable stealth mode</span>
                                                    <span class="field-override" id="override-enabled" hidden>Env override</span>
                                                </span>
                                                <span class="toggle-hint">When enabled, the root login entrypoint stays hidden behind the stealth flow instead of exposing the normal login page directly.</span>
                                            </span>
                                            <span>
                                                <input type="checkbox" id="security-enabled">
                                            </span>
                                        </label>
                                    </div>

                                    <div class="detail-section">
                                        <h3 class="detail-section-title">Timing And Lockouts</h3>
                                        <div class="form-grid">
                                            <label class="form-field">
                                                <span class="form-label-row">
                                                    <span class="form-label">Unlock Window (seconds)</span>
                                                    <span class="field-override" id="override-windowSeconds" hidden>Env override</span>
                                                </span>
                                                <input class="form-input" id="security-windowSeconds" type="number" min="30" max="3600" step="1">
                                            </label>

                                            <label class="form-field">
                                                <span class="form-label-row">
                                                    <span class="form-label">Keystroke TTL (seconds)</span>
                                                    <span class="field-override" id="override-keystrokeTtl" hidden>Env override</span>
                                                </span>
                                                <input class="form-input" id="security-keystrokeTtl" type="number" min="5" max="300" step="1">
                                            </label>

                                            <label class="form-field">
                                                <span class="form-label-row">
                                                    <span class="form-label">Max Attempts</span>
                                                    <span class="field-override" id="override-maxAttempts" hidden>Env override</span>
                                                </span>
                                                <input class="form-input" id="security-maxAttempts" type="number" min="1" max="20" step="1">
                                            </label>

                                            <label class="form-field">
                                                <span class="form-label-row">
                                                    <span class="form-label">Base Lockout (minutes)</span>
                                                    <span class="field-override" id="override-lockoutBaseMinutes" hidden>Env override</span>
                                                </span>
                                                <input class="form-input" id="security-lockoutBaseMinutes" type="number" min="1" max="180" step="1">
                                            </label>

                                            <label class="form-field">
                                                <span class="form-label-row">
                                                    <span class="form-label">Max Lockout (minutes)</span>
                                                    <span class="field-override" id="override-lockoutMaxMinutes" hidden>Env override</span>
                                                </span>
                                                <input class="form-input" id="security-lockoutMaxMinutes" type="number" min="1" max="720" step="1">
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <aside class="security-side">
                                <div class="summary-card">
                                    <h3 class="summary-title">Runtime Status</h3>
                                    <div class="summary-stack">
                                        <div id="security-runtime-state"></div>
                                        <div class="summary-line">
                                            <span>Unlock window</span>
                                            <strong id="security-runtime-window">-</strong>
                                        </div>
                                        <div class="summary-line">
                                            <span>Keystroke TTL</span>
                                            <strong id="security-runtime-ttl">-</strong>
                                        </div>
                                        <div class="summary-line">
                                            <span>Max attempts</span>
                                            <strong id="security-runtime-attempts">-</strong>
                                        </div>
                                        <div class="summary-line">
                                            <span>Lockout range</span>
                                            <strong id="security-runtime-lockout">-</strong>
                                        </div>
                                    </div>
                                </div>

                                <div class="summary-card">
                                    <h3 class="summary-title">Stealth Secret</h3>
                                    <div id="security-secret-state"></div>
                                    <div class="panel-copy" style="margin-top: 12px;">Stored on disk so the stealth entrypoint stays consistent until you rotate it.</div>
                                    <div class="mono-block" id="security-secret-path" style="margin-top: 12px;">-</div>
                                    <button class="btn secondary" type="button" id="rotate-secret-btn" style="margin-top: 14px;">
                                        <i class="fas fa-rotate"></i>
                                        Rotate Secret
                                    </button>
                                </div>

                                <div class="summary-card" id="security-overrides-card" hidden>
                                    <h3 class="summary-title">Environment Overrides</h3>
                                    <p class="panel-copy">These runtime values are currently forced by environment configuration.</p>
                                    <div class="override-list" id="security-overrides-list"></div>
                                </div>
                            </aside>
                        </div>
                    </section>
                </div>
            </div>
        </main>
    </div>

    <script>
        const initialState = <?= $initialStateJson ?: '{}' ?>;

        const state = {
            activeTab: 'modules',
            sections: Array.isArray(initialState.sections) ? initialState.sections : [],
            modulesInitial: normalizeModules(Array.isArray(initialState.modules) ? initialState.modules : []),
            modulesWorking: normalizeModules(Array.isArray(initialState.modules) ? initialState.modules : []),
            moduleOptionsInitial: normalizeModuleOptions(initialState.moduleOptions || {}),
            moduleOptionsWorking: normalizeModuleOptions(initialState.moduleOptions || {}),
            draggingModuleId: null,
            modulesSaving: false,
            securityInitial: normalizeSecurity(initialState.security && initialState.security.configured ? initialState.security.configured : (initialState.security || {})),
            securityWorking: normalizeSecurity(initialState.security && initialState.security.configured ? initialState.security.configured : (initialState.security || {})),
            securityRuntime: initialState.security || {},
            securitySecret: initialState.secret || { ready: false, path: '' },
            securitySaving: false,
        };

        const dom = {
            tabs: Array.from(document.querySelectorAll('.tab')),
            panels: Array.from(document.querySelectorAll('.tab-panel')),
            moduleBoard: document.getElementById('modules-board'),
            modulesDirtyPill: document.getElementById('modules-dirty-pill'),
            modulesResetBtn: document.getElementById('modules-reset-btn'),
            modulesSaveBtn: document.getElementById('modules-save-btn'),
            modulesStatus: document.getElementById('modules-status'),
            modulesApplyToSuperAdmin: document.getElementById('modules-apply-to-super-admin'),
            securityDirtyPill: document.getElementById('security-dirty-pill'),
            securityResetBtn: document.getElementById('security-reset-btn'),
            securitySaveBtn: document.getElementById('security-save-btn'),
            securityStatus: document.getElementById('security-status'),
            securityEnabled: document.getElementById('security-enabled'),
            securityWindowSeconds: document.getElementById('security-windowSeconds'),
            securityKeystrokeTtl: document.getElementById('security-keystrokeTtl'),
            securityMaxAttempts: document.getElementById('security-maxAttempts'),
            securityLockoutBaseMinutes: document.getElementById('security-lockoutBaseMinutes'),
            securityLockoutMaxMinutes: document.getElementById('security-lockoutMaxMinutes'),
            securityRuntimeState: document.getElementById('security-runtime-state'),
            securityRuntimeWindow: document.getElementById('security-runtime-window'),
            securityRuntimeTtl: document.getElementById('security-runtime-ttl'),
            securityRuntimeAttempts: document.getElementById('security-runtime-attempts'),
            securityRuntimeLockout: document.getElementById('security-runtime-lockout'),
            securitySecretState: document.getElementById('security-secret-state'),
            securitySecretPath: document.getElementById('security-secret-path'),
            securityOverridesCard: document.getElementById('security-overrides-card'),
            securityOverridesList: document.getElementById('security-overrides-list'),
            rotateSecretBtn: document.getElementById('rotate-secret-btn'),
            overrideEnabled: document.getElementById('override-enabled'),
            overrideWindowSeconds: document.getElementById('override-windowSeconds'),
            overrideKeystrokeTtl: document.getElementById('override-keystrokeTtl'),
            overrideMaxAttempts: document.getElementById('override-maxAttempts'),
            overrideLockoutBaseMinutes: document.getElementById('override-lockoutBaseMinutes'),
            overrideLockoutMaxMinutes: document.getElementById('override-lockoutMaxMinutes'),
        };

        const sectionLookup = new Map(state.sections.map((section) => [section.id, section]));
        const sectionOrder = state.sections.map((section) => section.id);

        function cloneData(value) {
            return JSON.parse(JSON.stringify(value));
        }

        function escapeHtml(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function normalizeModules(modules) {
            return modules.map((module, index) => ({
                ...module,
                enabled: !!module.enabled,
                fixedSection: !!module.fixedSection,
                sidebarVisible: !!module.sidebarVisible,
                allowedSections: Array.isArray(module.allowedSections) ? module.allowedSections : [],
                roles: Array.isArray(module.roles) ? module.roles : [],
                order: Number.isFinite(Number(module.order)) ? Number(module.order) : (index + 1) * 10,
            })).sort((a, b) => a.order - b.order);
        }

        function normalizeModuleOptions(options) {
            return {
                applyToSuperAdmin: options.applyToSuperAdmin !== false,
            };
        }

        function normalizeSecurity(security) {
            return {
                enabled: !!security.enabled,
                windowSeconds: Number.parseInt(security.windowSeconds, 10) || 120,
                keystrokeTtl: Number.parseInt(security.keystrokeTtl, 10) || 30,
                maxAttempts: Number.parseInt(security.maxAttempts, 10) || 5,
                lockoutBaseMinutes: Number.parseInt(security.lockoutBaseMinutes, 10) || 5,
                lockoutMaxMinutes: Number.parseInt(security.lockoutMaxMinutes, 10) || 60,
            };
        }

        function getModuleById(moduleId) {
            return state.modulesWorking.find((module) => module.id === moduleId) || null;
        }

        function groupModules(modules) {
            const groups = {};
            sectionOrder.forEach((sectionId) => {
                groups[sectionId] = [];
            });

            modules.forEach((module) => {
                const sectionId = sectionLookup.has(module.section) ? module.section : 'hidden';
                if (!groups[sectionId]) {
                    groups[sectionId] = [];
                }
                groups[sectionId].push(module);
            });

            return groups;
        }

        function flattenModuleGroups(groups) {
            const flattened = [];
            let order = 10;

            sectionOrder.forEach((sectionId) => {
                (groups[sectionId] || []).forEach((module) => {
                    flattened.push({
                        ...module,
                        section: sectionId,
                        order,
                    });
                    order += 10;
                });
            });

            return flattened;
        }

        function updateModule(moduleId, updater) {
            state.modulesWorking = state.modulesWorking.map((module) => {
                if (module.id !== moduleId) {
                    return module;
                }
                return {
                    ...module,
                    ...updater(module),
                };
            });
        }

        function moveModule(moduleId, targetSection, targetIndex) {
            const groups = groupModules(state.modulesWorking);
            let movingModule = null;

            Object.keys(groups).forEach((sectionId) => {
                groups[sectionId] = groups[sectionId].filter((module) => {
                    if (module.id === moduleId) {
                        movingModule = module;
                        return false;
                    }
                    return true;
                });
            });

            if (!movingModule) {
                return;
            }

            if (!groups[targetSection]) {
                groups[targetSection] = [];
            }

            const boundedIndex = Math.max(0, Math.min(targetIndex, groups[targetSection].length));
            groups[targetSection].splice(boundedIndex, 0, {
                ...movingModule,
                section: targetSection,
            });

            state.modulesWorking = flattenModuleGroups(groups);
        }

        function getLisIndexes(values) {
            if (!Array.isArray(values) || values.length === 0) {
                return [];
            }

            const predecessors = new Array(values.length).fill(-1);
            const tails = [];

            for (let index = 0; index < values.length; index += 1) {
                let low = 0;
                let high = tails.length;

                while (low < high) {
                    const mid = Math.floor((low + high) / 2);
                    if (values[tails[mid]] < values[index]) {
                        low = mid + 1;
                    } else {
                        high = mid;
                    }
                }

                if (low > 0) {
                    predecessors[index] = tails[low - 1];
                }

                tails[low] = index;
            }

            const result = [];
            let cursor = tails[tails.length - 1] ?? -1;
            while (cursor >= 0) {
                result.push(cursor);
                cursor = predecessors[cursor];
            }

            return result.reverse();
        }

        function getModuleDirtyIds() {
            const dirtyIds = new Set();
            const initialById = new Map(state.modulesInitial.map((module) => [module.id, module]));
            const currentById = new Map(state.modulesWorking.map((module) => [module.id, module]));

            state.modulesWorking.forEach((module) => {
                const initial = initialById.get(module.id);
                if (!initial) {
                    dirtyIds.add(module.id);
                    return;
                }

                if (!!initial.enabled !== !!module.enabled) {
                    dirtyIds.add(module.id);
                }

                if (initial.section !== module.section) {
                    dirtyIds.add(module.id);
                }
            });

            state.modulesInitial.forEach((module) => {
                if (!currentById.has(module.id)) {
                    dirtyIds.add(module.id);
                }
            });

            const sectionIds = new Set([
                ...sectionOrder,
                ...state.modulesInitial.map((module) => module.section),
                ...state.modulesWorking.map((module) => module.section),
            ]);

            sectionIds.forEach((sectionId) => {
                const initialIds = state.modulesInitial
                    .filter((module) => module.section === sectionId)
                    .map((module) => module.id);
                const currentIds = state.modulesWorking
                    .filter((module) => module.section === sectionId)
                    .map((module) => module.id);

                const currentIdSet = new Set(currentIds);
                const stableIds = initialIds.filter((id) => currentIdSet.has(id));
                if (stableIds.length <= 1) {
                    return;
                }

                const stableSet = new Set(stableIds);
                const currentStableIds = currentIds.filter((id) => stableSet.has(id));
                const initialPositions = new Map(stableIds.map((id, index) => [id, index]));
                const currentPositions = currentStableIds.map((id) => initialPositions.get(id));
                const lisIndexes = new Set(getLisIndexes(currentPositions));

                currentStableIds.forEach((id, index) => {
                    if (!lisIndexes.has(index)) {
                        dirtyIds.add(id);
                    }
                });
            });

            return dirtyIds;
        }

        function getModuleDirtyCount() {
            let dirtyCount = getModuleDirtyIds().size;
            if (state.moduleOptionsInitial.applyToSuperAdmin !== state.moduleOptionsWorking.applyToSuperAdmin) {
                dirtyCount += 1;
            }
            return dirtyCount;
        }

        function getSecurityDirtyCount() {
            const fields = ['enabled', 'windowSeconds', 'keystrokeTtl', 'maxAttempts', 'lockoutBaseMinutes', 'lockoutMaxMinutes'];
            return fields.reduce((count, field) => {
                return count + (state.securityInitial[field] !== state.securityWorking[field] ? 1 : 0);
            }, 0);
        }

        function setStatus(element, type, message) {
            if (!message) {
                element.hidden = true;
                element.className = 'status-line';
                element.textContent = '';
                return;
            }

            element.hidden = false;
            element.className = `status-line ${type}`;
            element.textContent = message;
        }

        function setButtonLoading(button, loading) {
            button.classList.toggle('is-loading', loading);
        }

        function renderModulesToolbar() {
            const dirtyCount = getModuleDirtyCount();
            dom.modulesDirtyPill.hidden = dirtyCount === 0;
            dom.modulesDirtyPill.textContent = dirtyCount === 1 ? '1 unsaved change' : `${dirtyCount} unsaved changes`;
            dom.modulesSaveBtn.disabled = dirtyCount === 0 || state.modulesSaving;
            dom.modulesResetBtn.hidden = dirtyCount === 0;
            dom.modulesResetBtn.disabled = state.modulesSaving;
            dom.modulesApplyToSuperAdmin.checked = !!state.moduleOptionsWorking.applyToSuperAdmin;
            dom.modulesApplyToSuperAdmin.disabled = state.modulesSaving;
        }

        function getSectionNote(sectionId) {
            const notes = {
                home: 'Pinned top-level navigation.',
                apps: 'Apps hub and installed apps entrypoint.',
                workflows: 'Commands, templates, and execution flow.',
                studio: 'Authoring surfaces for apps, workflows, and templates.',
                configuration: 'Targets, environments, and secrets.',
                administration: 'System and admin-facing tools.',
                hidden: 'Keeps modules out of the sidebar.',
            };

            return notes[sectionId] || '';
        }

        function renderModulesBoard() {
            const groups = groupModules(state.modulesWorking);

            if (!Array.isArray(state.sections) || state.sections.length === 0) {
                dom.moduleBoard.innerHTML = '<div class="empty-state">No modules are available to manage right now.</div>';
                return;
            }

            dom.moduleBoard.innerHTML = state.sections.map((section) => {
                const modules = groups[section.id] || [];
                const sectionNote = getSectionNote(section.id);
                return `
                    <section class="module-section-card">
                        <div class="module-section-head">
                            <h3 class="module-section-title">${escapeHtml(section.label)}</h3>
                            ${sectionNote ? `<p class="module-section-note">${escapeHtml(sectionNote)}</p>` : ''}
                        </div>
                        <div class="module-section-list" data-drop-section="${escapeHtml(section.id)}">
                            ${modules.map((module) => {
                                const enabledLabel = module.enabled ? 'Enabled' : 'Disabled';
                                const isDraggable = !module.fixedSection && !state.modulesSaving;
                                const meta = module.sidebarVisible
                                    ? (module.path ? module.path : (module.description || 'Sidebar module'))
                                    : 'Capability toggle';
                                const badges = [];
                                if (module.fixedSection) {
                                    badges.push('<span class="pill neutral">Locked</span>');
                                }
                                if (!module.sidebarVisible) {
                                    badges.push('<span class="pill info">Capability</span>');
                                }

                                return `
                                    <div class="module-item module-card ${module.enabled ? '' : 'is-disabled'}" data-module-id="${escapeHtml(module.id)}" draggable="${isDraggable ? 'true' : 'false'}">
                                        <div class="module-item-main">
                                            <span class="module-drag-handle ${isDraggable ? '' : 'is-static'}" aria-hidden="true">
                                                <i class="fas ${isDraggable ? 'fa-grip-vertical' : 'fa-lock'}"></i>
                                            </span>
                                            <div class="module-item-icon" aria-hidden="true">
                                                <i class="fas ${escapeHtml(module.icon || 'fa-puzzle-piece')}"></i>
                                            </div>
                                            <div class="module-item-copy">
                                                <div class="module-item-content">
                                                    <div class="module-item-title-row">
                                                        <span class="module-item-title">${escapeHtml(module.navLabel || module.label || module.id)}</span>
                                                    </div>
                                                    <div class="module-item-meta">${escapeHtml(meta)}</div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="module-item-footer">
                                            <div class="module-item-badges">
                                                ${badges.join('')}
                                            </div>
                                            <label class="toggle-pill ${module.enabled ? 'is-on' : 'is-off'}" data-toggle-label="${escapeHtml(module.id)}">
                                                <input type="checkbox" data-module-enabled="${escapeHtml(module.id)}" ${module.enabled ? 'checked' : ''} ${state.modulesSaving ? 'disabled' : ''}>
                                                <span>${enabledLabel}</span>
                                            </label>
                                        </div>
                                    </div>
                                `;
                            }).join('') || '<div class="empty-drop-hint">Drag modules here.</div>'}
                        </div>
                    </section>
                `;
            }).join('');
        }

        function canDropModule(module, targetSectionId) {
            if (!module || module.fixedSection) {
                return false;
            }

            return (module.allowedSections || []).some((section) => section.id === targetSectionId);
        }

        function clearDropTargets() {
            dom.moduleBoard.querySelectorAll('.module-section-list.is-drop-target').forEach((list) => {
                list.classList.remove('is-drop-target');
            });
            dom.moduleBoard.querySelectorAll('.drop-indicator').forEach((indicator) => {
                indicator.remove();
            });
        }

        function getDropIndex(list, clientY) {
            const cards = Array.from(list.querySelectorAll('.module-card')).filter((card) => card.dataset.moduleId !== state.draggingModuleId);
            for (let index = 0; index < cards.length; index += 1) {
                const box = cards[index].getBoundingClientRect();
                if (clientY < box.top + (box.height / 2)) {
                    return index;
                }
            }

            return cards.length;
        }

        function renderDropIndicator(list, index) {
            clearDropTargets();
            list.classList.add('is-drop-target');

            const indicator = document.createElement('div');
            indicator.className = 'drop-indicator';

            const cards = Array.from(list.querySelectorAll('.module-card')).filter((card) => card.dataset.moduleId !== state.draggingModuleId);
            const reference = cards[index] || list.querySelector('.empty-drop-hint');
            if (reference) {
                list.insertBefore(indicator, reference);
            } else {
                list.appendChild(indicator);
            }
        }

        function renderSecurityToolbar() {
            const dirtyCount = getSecurityDirtyCount();
            dom.securityDirtyPill.hidden = dirtyCount === 0;
            dom.securityDirtyPill.textContent = dirtyCount === 1 ? '1 unsaved change' : `${dirtyCount} unsaved changes`;
            dom.securitySaveBtn.disabled = dirtyCount === 0 || state.securitySaving;
            dom.securityResetBtn.hidden = dirtyCount === 0;
            dom.securityResetBtn.disabled = state.securitySaving;
        }

        function renderSecurity() {
            dom.securityEnabled.checked = !!state.securityWorking.enabled;
            dom.securityWindowSeconds.value = state.securityWorking.windowSeconds;
            dom.securityKeystrokeTtl.value = state.securityWorking.keystrokeTtl;
            dom.securityMaxAttempts.value = state.securityWorking.maxAttempts;
            dom.securityLockoutBaseMinutes.value = state.securityWorking.lockoutBaseMinutes;
            dom.securityLockoutMaxMinutes.value = state.securityWorking.lockoutMaxMinutes;
            dom.securityEnabled.disabled = state.securitySaving;
            dom.securityWindowSeconds.disabled = state.securitySaving;
            dom.securityKeystrokeTtl.disabled = state.securitySaving;
            dom.securityMaxAttempts.disabled = state.securitySaving;
            dom.securityLockoutBaseMinutes.disabled = state.securitySaving;
            dom.securityLockoutMaxMinutes.disabled = state.securitySaving;
            dom.rotateSecretBtn.disabled = state.securitySaving || dom.rotateSecretBtn.classList.contains('is-loading');

            const runtime = state.securityRuntime || {};
            const overrideSources = runtime.overrideSources || {};
            const overrideFields = {
                enabled: dom.overrideEnabled,
                windowSeconds: dom.overrideWindowSeconds,
                keystrokeTtl: dom.overrideKeystrokeTtl,
                maxAttempts: dom.overrideMaxAttempts,
                lockoutBaseMinutes: dom.overrideLockoutBaseMinutes,
                lockoutMaxMinutes: dom.overrideLockoutMaxMinutes,
            };

            Object.entries(overrideFields).forEach(([field, element]) => {
                element.hidden = overrideSources[field] !== 'env';
            });

            dom.securityRuntimeState.innerHTML = runtime.enabled
                ? '<span class="pill success">Stealth mode is active</span>'
                : '<span class="pill danger">Stealth mode is disabled</span>';
            dom.securityRuntimeWindow.textContent = `${runtime.windowSeconds || state.securityWorking.windowSeconds}s`;
            dom.securityRuntimeTtl.textContent = `${runtime.keystrokeTtl || state.securityWorking.keystrokeTtl}s`;
            dom.securityRuntimeAttempts.textContent = String(runtime.maxAttempts || state.securityWorking.maxAttempts);
            dom.securityRuntimeLockout.textContent = `${runtime.lockoutBaseMinutes || state.securityWorking.lockoutBaseMinutes}m to ${runtime.lockoutMaxMinutes || state.securityWorking.lockoutMaxMinutes}m`;

            dom.securitySecretState.innerHTML = state.securitySecret.ready
                ? '<span class="pill success">Secret ready</span>'
                : '<span class="pill danger">Secret missing</span>';
            dom.securitySecretPath.textContent = state.securitySecret.path || 'Not available';

            const overrides = Object.entries(overrideSources)
                .filter(([, source]) => source === 'env')
                .map(([field]) => field);

            dom.securityOverridesCard.hidden = overrides.length === 0;
            dom.securityOverridesList.innerHTML = overrides.map((field) => {
                const labelMap = {
                    enabled: 'Stealth mode',
                    windowSeconds: 'Unlock window',
                    keystrokeTtl: 'Keystroke TTL',
                    maxAttempts: 'Max attempts',
                    lockoutBaseMinutes: 'Base lockout',
                    lockoutMaxMinutes: 'Max lockout',
                };
                return `<span class="pill warning">${escapeHtml(labelMap[field] || field)}</span>`;
            }).join('');
        }

        function renderModules() {
            renderModulesToolbar();
            renderModulesBoard();
        }

        function renderAll() {
            renderModules();
            renderSecurityToolbar();
            renderSecurity();
        }

        function switchTab(tabId) {
            state.activeTab = tabId;
            dom.tabs.forEach((tab) => {
                tab.classList.toggle('active', tab.dataset.tab === tabId);
            });
            dom.panels.forEach((panel) => {
                panel.classList.toggle('active', panel.dataset.panel === tabId);
            });
        }

        async function postJson(url, payload) {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(payload),
            });

            const data = await response.json().catch(() => ({
                success: false,
                error: 'Invalid server response',
            }));

            if (!response.ok || !data.success) {
                throw new Error(data.error || 'Request failed');
            }

            return data;
        }

        dom.tabs.forEach((tab) => {
            tab.addEventListener('click', () => {
                switchTab(tab.dataset.tab || 'modules');
            });
        });

        dom.moduleBoard.addEventListener('change', (event) => {
            const toggle = event.target.closest('input[data-module-enabled]');
            if (!toggle) {
                return;
            }

            const moduleId = toggle.dataset.moduleEnabled;
            updateModule(moduleId, () => ({ enabled: toggle.checked }));
            renderModules();
        });

        dom.moduleBoard.addEventListener('click', (event) => {
            if (event.target.closest('[data-toggle-label]')) {
                event.stopPropagation();
                return;
            }
        });

        dom.moduleBoard.addEventListener('dragstart', (event) => {
            const card = event.target.closest('.module-card[draggable="true"]');
            if (!card || state.modulesSaving) {
                return;
            }

            state.draggingModuleId = card.dataset.moduleId || null;
            if (!state.draggingModuleId) {
                return;
            }

            card.classList.add('dragging');
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', state.draggingModuleId);
        });

        dom.moduleBoard.addEventListener('dragend', (event) => {
            const card = event.target.closest('.module-card');
            if (card) {
                card.classList.remove('dragging');
            }
            state.draggingModuleId = null;
            clearDropTargets();
        });

        dom.moduleBoard.addEventListener('dragover', (event) => {
            const list = event.target.closest('.module-section-list');
            const module = getModuleById(state.draggingModuleId);
            if (!list || !module) {
                return;
            }

            const targetSectionId = list.dataset.dropSection || '';
            if (!canDropModule(module, targetSectionId)) {
                clearDropTargets();
                return;
            }

            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
            const targetIndex = getDropIndex(list, event.clientY);
            renderDropIndicator(list, targetIndex);
        });

        dom.moduleBoard.addEventListener('drop', (event) => {
            const list = event.target.closest('.module-section-list');
            const module = getModuleById(state.draggingModuleId);
            if (!list || !module) {
                return;
            }

            const targetSectionId = list.dataset.dropSection || '';
            if (!canDropModule(module, targetSectionId)) {
                clearDropTargets();
                return;
            }

            event.preventDefault();
            const targetIndex = getDropIndex(list, event.clientY);
            moveModule(module.id, targetSectionId, targetIndex);
            state.draggingModuleId = null;
            clearDropTargets();
            renderModules();
        });

        dom.moduleBoard.addEventListener('dragleave', (event) => {
            const list = event.target.closest('.module-section-list');
            if (!list || list.contains(event.relatedTarget)) {
                return;
            }

            list.classList.remove('is-drop-target');
        });

        dom.modulesResetBtn.addEventListener('click', () => {
            state.modulesWorking = cloneData(state.modulesInitial);
            state.moduleOptionsWorking = cloneData(state.moduleOptionsInitial);
            state.draggingModuleId = null;
            clearDropTargets();
            setStatus(dom.modulesStatus, '', '');
            renderModules();
        });

        dom.modulesApplyToSuperAdmin.addEventListener('change', () => {
            state.moduleOptionsWorking.applyToSuperAdmin = dom.modulesApplyToSuperAdmin.checked;
            renderModules();
        });

        dom.modulesSaveBtn.addEventListener('click', async () => {
            if (state.modulesSaving || getModuleDirtyCount() === 0) {
                return;
            }

            state.modulesSaving = true;
            setButtonLoading(dom.modulesSaveBtn, true);
            renderModules();

            try {
                const payload = {
                    modules: state.modulesWorking.map((module) => ({
                        id: module.id,
                        enabled: !!module.enabled,
                        section: module.section,
                    })),
                    options: {
                        applyToSuperAdmin: !!state.moduleOptionsWorking.applyToSuperAdmin,
                    },
                };
                const response = await postJson('../api/system-admin.php?action=save-modules', payload);
                state.sections = Array.isArray(response.sections) ? response.sections : state.sections;
                sectionLookup.clear();
                state.sections.forEach((section) => {
                    sectionLookup.set(section.id, section);
                });
                sectionOrder.splice(0, sectionOrder.length, ...state.sections.map((section) => section.id));
                state.modulesInitial = normalizeModules(Array.isArray(response.modules) ? response.modules : []);
                state.modulesWorking = cloneData(state.modulesInitial);
                state.moduleOptionsInitial = normalizeModuleOptions(response.moduleOptions || {});
                state.moduleOptionsWorking = cloneData(state.moduleOptionsInitial);
                state.draggingModuleId = null;
                if (response.redirect) {
                    window.location.assign(response.redirect);
                    return;
                }
                setStatus(dom.modulesStatus, 'success', 'Modules saved.');
            } catch (error) {
                setStatus(dom.modulesStatus, 'error', error.message || 'Unable to save modules.');
            } finally {
                state.modulesSaving = false;
                setButtonLoading(dom.modulesSaveBtn, false);
                clearDropTargets();
                renderModules();
            }
        });

        [
            ['enabled', dom.securityEnabled, 'checked'],
            ['windowSeconds', dom.securityWindowSeconds, 'value'],
            ['keystrokeTtl', dom.securityKeystrokeTtl, 'value'],
            ['maxAttempts', dom.securityMaxAttempts, 'value'],
            ['lockoutBaseMinutes', dom.securityLockoutBaseMinutes, 'value'],
            ['lockoutMaxMinutes', dom.securityLockoutMaxMinutes, 'value'],
        ].forEach(([field, element, property]) => {
            const eventName = property === 'checked' ? 'change' : 'input';
            element.addEventListener(eventName, () => {
                state.securityWorking[field] = property === 'checked'
                    ? !!element.checked
                    : Number.parseInt(element.value, 10) || 0;
                renderSecurityToolbar();
            });
        });

        dom.securityResetBtn.addEventListener('click', () => {
            state.securityWorking = cloneData(state.securityInitial);
            setStatus(dom.securityStatus, '', '');
            renderSecurity();
            renderSecurityToolbar();
        });

        dom.securitySaveBtn.addEventListener('click', async () => {
            if (state.securitySaving || getSecurityDirtyCount() === 0) {
                return;
            }

            state.securitySaving = true;
            setButtonLoading(dom.securitySaveBtn, true);
            renderSecurity();
            renderSecurityToolbar();

            try {
                const response = await postJson('../api/system-admin.php?action=save-security', state.securityWorking);
                state.securityRuntime = response.security || state.securityRuntime;
                state.securitySecret = response.secret || state.securitySecret;
                state.securityInitial = normalizeSecurity(response.security && response.security.configured ? response.security.configured : state.securityWorking);
                state.securityWorking = cloneData(state.securityInitial);
                setStatus(dom.securityStatus, 'success', 'Security settings saved.');
            } catch (error) {
                setStatus(dom.securityStatus, 'error', error.message || 'Unable to save security settings.');
            } finally {
                state.securitySaving = false;
                setButtonLoading(dom.securitySaveBtn, false);
                renderSecurity();
                renderSecurityToolbar();
            }
        });

        dom.rotateSecretBtn.addEventListener('click', async () => {
            if (!window.confirm('Rotate the stealth secret now? Existing stealth URLs will stop working until shared again.')) {
                return;
            }

            dom.rotateSecretBtn.disabled = true;
            setButtonLoading(dom.rotateSecretBtn, true);

            try {
                const response = await postJson('../api/system-admin.php?action=rotate-stealth-secret', {});
                state.securitySecret = response.secret || state.securitySecret;
                setStatus(dom.securityStatus, 'success', 'Stealth secret rotated.');
            } catch (error) {
                setStatus(dom.securityStatus, 'error', error.message || 'Unable to rotate the stealth secret.');
            } finally {
                dom.rotateSecretBtn.disabled = false;
                setButtonLoading(dom.rotateSecretBtn, false);
                renderSecurity();
            }
        });

        renderAll();
    </script>
</body>
</html>
