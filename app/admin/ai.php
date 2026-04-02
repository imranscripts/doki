<?php
/**
 * admin/ai.php - AI administration
 */

require_once __DIR__ . '/../includes/middleware.php';
require_once __DIR__ . '/../includes/Layout.php';
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/AIProviderManager.php';
require_once __DIR__ . '/../includes/AIAdminManager.php';

$user = requireRole('admin');
requireModuleAccess('ai.admin');
$layout = new Layout($user, 'ai.admin');
$auth = new Auth();
$auth->validateSession($_SESSION['auth_token'] ?? '');

$providerManager = new AIProviderManager();
$aiAdminManager = new AIAdminManager();

$providers = array_values($providerManager->getProviders());
$appBlocks = $aiAdminManager->getContextBlocks(AIAdminManager::SCOPE_APPS);
$commandBlocks = $aiAdminManager->getContextBlocks(AIAdminManager::SCOPE_COMMANDS);

$providersJson = json_encode($providers, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$appBlocksJson = json_encode($appBlocks, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$commandBlocksJson = json_encode($commandBlocks, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $layout->renderHead('AI Administration'); ?>
    <style>
        .page-shell {
            padding: 28px;
            max-width: 1440px;
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
            max-width: 760px;
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

        .surface {
            background: var(--bg-secondary);
            border: 1px solid var(--border-primary);
            border-radius: 16px;
            overflow: hidden;
        }

        .providers-layout {
            display: grid;
            grid-template-columns: 340px minmax(0, 1fr);
            min-height: 720px;
            height: min(78vh, 920px);
        }

        .providers-sidebar {
            border-right: 1px solid var(--border-primary);
            background: rgba(255,255,255,0.02);
            display: flex;
            flex-direction: column;
            min-height: 0;
        }

        .panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 18px 20px;
            border-bottom: 1px solid var(--border-primary);
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
            opacity: 0.7;
            pointer-events: none;
        }

        .provider-list {
            padding: 14px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            min-height: 0;
            overflow-y: auto;
        }

        .provider-list-empty {
            padding: 20px;
            border: 1px dashed var(--border-primary);
            border-radius: 14px;
            color: var(--text-secondary);
            text-align: center;
            line-height: 1.5;
        }

        .provider-item {
            width: 100%;
            text-align: left;
            border: 1px solid var(--border-primary);
            background: var(--bg-primary);
            color: inherit;
            border-radius: 14px;
            padding: 14px;
            cursor: pointer;
        }

        .provider-item:hover {
            border-color: var(--accent-primary);
        }

        .provider-item.active {
            border-color: var(--accent-primary);
            box-shadow: 0 0 0 1px rgba(88, 166, 255, 0.35);
            background: rgba(88, 166, 255, 0.08);
        }

        .provider-item-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
        }

        .provider-item-title {
            margin: 0;
            font-size: 14px;
            font-weight: 700;
            color: var(--text-primary);
        }

        .provider-item-meta {
            margin: 4px 0 0;
            font-size: 12px;
            color: var(--text-secondary);
        }

        .provider-item-bottom {
            margin-top: 10px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }

        .provider-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 8px;
            border-radius: 999px;
            background: rgba(255,255,255,0.06);
            color: var(--text-secondary);
            font-size: 11px;
            font-weight: 700;
        }

        .provider-badge.default {
            background: rgba(63, 185, 80, 0.12);
            color: #86efac;
        }

        .provider-badge.draft {
            background: rgba(251, 191, 36, 0.14);
            color: #fcd34d;
        }

        .provider-form-wrap {
            display: flex;
            flex-direction: column;
            min-width: 0;
            min-height: 0;
            overflow: hidden;
        }

        .provider-form {
            padding: 22px;
            display: flex;
            flex-direction: column;
            gap: 16px;
            min-height: 0;
            overflow-y: auto;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }

        .form-field {
            display: flex;
            flex-direction: column;
            gap: 6px;
            min-width: 0;
        }

        .form-label {
            font-size: 12px;
            font-weight: 700;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .form-label-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .form-input,
        .form-select,
        .form-textarea {
            width: 100%;
            border: 1px solid var(--border-primary);
            border-radius: 12px;
            background: var(--bg-primary);
            color: var(--text-primary);
            padding: 11px 12px;
            font: inherit;
        }

        .form-input:focus,
        .form-select:focus,
        .form-textarea:focus {
            outline: none;
            border-color: var(--accent-primary);
            box-shadow: 0 0 0 3px rgba(88, 166, 255, 0.15);
        }

        .form-textarea {
            min-height: 180px;
            resize: vertical;
            line-height: 1.5;
        }

        .form-textarea.code-input {
            min-height: 120px;
            font-family: "SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace;
        }

        .form-hint {
            margin: 0;
            font-size: 12px;
            color: var(--text-muted);
            line-height: 1.5;
        }

        .inline-toggle {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--text-secondary);
            font-size: 13px;
            cursor: pointer;
            user-select: none;
        }

        .inline-toggle input {
            margin: 0;
        }

        .field-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 12px;
            align-items: end;
        }

        .status-line {
            min-height: 20px;
            color: var(--text-secondary);
            font-size: 13px;
        }

        .provider-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            padding-top: 6px;
        }

        .provider-header-actions {
            justify-content: flex-end;
        }

        .context-shell {
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 18px;
        }

        .context-surface {
            display: flex;
            flex-direction: column;
            min-height: 720px;
            height: min(78vh, 920px);
        }

        .context-tab-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 18px 20px;
            border-bottom: 1px solid var(--border-primary);
            background: rgba(255,255,255,0.02);
        }

        .context-tab-toolbar-copy {
            margin: 6px 0 0;
            color: var(--text-secondary);
            font-size: 13px;
            line-height: 1.45;
        }

        .context-tab-state {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 4px;
            min-width: 0;
        }

        .context-dirty-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 10px;
            border-radius: 999px;
            background: rgba(251, 191, 36, 0.14);
            color: #fcd34d;
            font-size: 12px;
            font-weight: 700;
        }

        .context-dirty-pill[hidden] {
            display: none !important;
        }

        .context-dirty-pill::before {
            content: "";
            width: 8px;
            height: 8px;
            border-radius: 999px;
            background: currentColor;
        }

        .context-tab-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .context-tab-state .context-status {
            min-height: 18px;
            text-align: right;
        }

        .context-editor-layout {
            display: grid;
            grid-template-columns: 340px minmax(0, 1fr);
            flex: 1;
            min-height: 0;
        }

        .context-sidebar {
            border-right: 1px solid var(--border-primary);
            background: rgba(255,255,255,0.02);
            display: flex;
            flex-direction: column;
            min-height: 0;
        }

        .context-list {
            padding: 14px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            min-height: 0;
            overflow-y: auto;
        }

        .context-item {
            border: 1px solid var(--border-primary);
            background: var(--bg-primary);
            color: inherit;
            border-radius: 14px;
            padding: 14px;
            cursor: pointer;
        }

        .context-item:hover {
            border-color: var(--accent-primary);
        }

        .context-item:focus-visible {
            outline: none;
            border-color: var(--accent-primary);
            box-shadow: 0 0 0 3px rgba(88, 166, 255, 0.18);
        }

        .context-item.active {
            border-color: var(--accent-primary);
            box-shadow: 0 0 0 1px rgba(88, 166, 255, 0.35);
            background: rgba(88, 166, 255, 0.08);
        }

        .context-item-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
        }

        .context-item-select {
            flex: 1;
            min-width: 0;
            border: 0;
            background: transparent;
            color: inherit;
            text-align: left;
            padding: 0;
            cursor: pointer;
            font: inherit;
        }

        .context-item-title {
            margin: 0;
            font-size: 14px;
            font-weight: 700;
            color: var(--text-primary);
        }

        .context-item-copy {
            margin: 6px 0 0;
            font-size: 12px;
            color: var(--text-secondary);
            line-height: 1.45;
        }

        .context-item-badges {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 12px;
        }

        .context-item-toggle {
            flex-shrink: 0;
            align-self: center;
            padding: 7px 10px;
            border-radius: 999px;
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.08);
        }

        .context-item-empty {
            padding: 20px;
            border: 1px dashed var(--border-primary);
            border-radius: 14px;
            color: var(--text-secondary);
            text-align: center;
            line-height: 1.5;
        }

        .context-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
        }

        .context-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 16px;
        }

        .context-card {
            border: 1px solid var(--border-primary);
            border-radius: 16px;
            background: var(--bg-primary);
            display: flex;
            flex-direction: column;
            min-width: 0;
        }

        .context-card-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 14px;
            padding: 16px 16px 10px;
        }

        .context-card-title {
            margin: 0;
            font-size: 15px;
            font-weight: 700;
        }

        .context-card-copy {
            margin: 6px 0 0;
            color: var(--text-secondary);
            font-size: 13px;
            line-height: 1.5;
        }

        .context-card-body {
            padding: 0 16px 16px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .context-readonly {
            padding: 12px 13px;
            border: 1px dashed var(--border-primary);
            border-radius: 12px;
            background: rgba(255,255,255,0.02);
            color: var(--text-secondary);
            font-size: 13px;
            line-height: 1.5;
            white-space: pre-wrap;
        }

        .context-status {
            min-height: 20px;
            color: var(--text-secondary);
            font-size: 13px;
        }

        @media (max-width: 1100px) {
            .providers-layout {
                grid-template-columns: 1fr;
            }

            .context-editor-layout {
                grid-template-columns: 1fr;
            }

            .providers-sidebar {
                border-right: 0;
                border-bottom: 1px solid var(--border-primary);
            }

            .context-sidebar {
                border-right: 0;
                border-bottom: 1px solid var(--border-primary);
            }
        }

        @media (max-width: 780px) {
            .page-shell {
                padding: 18px;
            }

            .page-header,
            .context-toolbar,
            .panel-header,
            .context-tab-toolbar,
            .context-tab-state {
                align-items: flex-start;
                flex-direction: column;
            }

            .context-tab-state,
            .context-tab-actions {
                width: 100%;
            }

            .context-tab-state .context-status {
                text-align: left;
            }

            .form-row,
            .field-row {
                grid-template-columns: 1fr;
            }

            .tabs {
                overflow-x: auto;
                padding-bottom: 6px;
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
                            <h1 class="page-title">AI Administration</h1>
                            <p class="page-subtitle">Manage AI providers and the logical context blocks Doki sends to AI for app work today and workflow/template work next.</p>
                        </div>
                        <div class="page-chip">
                            <i class="fas fa-shield-halved"></i>
                            <span>`ai.admin` permission required</span>
                        </div>
                    </div>

                    <div class="tabs">
                        <button class="tab active" type="button" data-tab="providers"><i class="fas fa-robot"></i> Providers</button>
                        <button class="tab" type="button" data-tab="apps"><i class="fas fa-puzzle-piece"></i> Apps</button>
                        <button class="tab" type="button" data-tab="commands"><i class="fas fa-terminal"></i> Workflows</button>
                    </div>

                    <section class="tab-panel active" data-panel="providers">
                        <div class="surface providers-layout">
                            <aside class="providers-sidebar">
                                <div class="panel-header">
                                    <div>
                                        <h2 class="panel-title">Providers</h2>
                                        <p class="panel-copy">Select a provider to edit it, or start a new one. The default provider is preferred across Studio AI flows.</p>
                                    </div>
                                    <button class="btn secondary" type="button" id="provider-new-btn">
                                        <i class="fas fa-plus"></i>
                                        New
                                    </button>
                                </div>
                                <div class="provider-list" id="provider-list"></div>
                            </aside>

                            <div class="provider-form-wrap">
                                <div class="panel-header">
                                    <div>
                                        <h2 class="panel-title">Provider Details</h2>
                                        <p class="panel-copy">Create, edit, test, and set the default model/provider from one place.</p>
                                    </div>
                                </div>

                                <form class="provider-form" id="provider-form">
                                    <input type="hidden" id="provider-id" name="id">

                                    <div class="provider-actions provider-header-actions">
                                        <button class="btn primary" type="submit" id="provider-save-btn">
                                            <i class="fas fa-floppy-disk"></i>
                                            Save Provider
                                        </button>
                                        <button class="btn secondary" type="button" id="provider-test-btn">
                                            <i class="fas fa-plug"></i>
                                            Test
                                        </button>
                                        <button class="btn danger" type="button" id="provider-delete-btn">
                                            <i class="fas fa-trash"></i>
                                            Delete
                                        </button>
                                    </div>

                                    <div class="status-line" id="provider-status"></div>

                                    <div class="form-row">
                                        <label class="form-field">
                                            <span class="form-label">Provider Type</span>
                                            <select class="form-select" id="provider-preset" name="providerPreset">
                                                <option value="ollama">Ollama</option>
                                                <option value="openai">OpenAI</option>
                                                <option value="claude">Claude</option>
                                                <option value="minimax">MiniMax</option>
                                                <option value="openrouter">OpenRouter</option>
                                                <option value="custom-openai">Custom OpenAI-compatible</option>
                                            </select>
                                            <input type="hidden" id="provider-type" name="type" value="ollama">
                                        </label>

                                        <label class="form-field">
                                            <span class="form-label-row">
                                                <span class="form-label">Name</span>
                                                <span class="inline-toggle" title="Use this provider whenever Doki needs a default AI provider">
                                                    <input type="checkbox" id="provider-is-default" name="isDefault" value="1">
                                                    <span>Default Provider</span>
                                                </span>
                                            </span>
                                            <input class="form-input" id="provider-name" name="name" placeholder="Local Ollama" required>
                                        </label>
                                    </div>

                                    <label class="form-field">
                                        <span class="form-label">API Base URL</span>
                                        <input class="form-input" id="provider-base-url" name="baseUrl" placeholder="http://host.docker.internal:11434">
                                    </label>

                                    <label class="form-field">
                                        <span class="form-label">API Key</span>
                                        <input class="form-input" id="provider-api-key" name="apiKey" type="password" placeholder="Optional for local Ollama">
                                    </label>

                                    <div class="form-field" id="provider-openai-advanced" hidden>
                                        <span class="form-label">OpenAI-Compatible Query Settings</span>
                                        <div class="form-row">
                                            <label class="form-field">
                                                <span class="form-label">Chat Path</span>
                                                <input class="form-input" id="provider-chat-path" placeholder="/chat/completions">
                                            </label>
                                            <label class="form-field">
                                                <span class="form-label">Models Path</span>
                                                <input class="form-input" id="provider-models-path" placeholder="/models">
                                            </label>
                                        </div>
                                        <textarea class="form-textarea code-input" id="provider-request-params" placeholder="{&#10;  &quot;reasoning_split&quot;: true&#10;}"></textarea>
                                        <p class="form-hint">These JSON params are merged into every OpenAI-compatible chat request. Leave the models path blank if your provider has no listing endpoint and set a default model manually.</p>
                                    </div>

                                    <div class="form-field">
                                        <span class="form-label">Default Model</span>
                                        <div class="field-row">
                                            <div>
                                                <input class="form-input" id="provider-default-model" name="defaultModel" list="provider-default-model-options" placeholder="Optional">
                                                <datalist id="provider-default-model-options"></datalist>
                                            </div>
                                            <button class="btn secondary" type="button" id="provider-load-models-btn">
                                                <i class="fas fa-rotate"></i>
                                                Load Models
                                            </button>
                                        </div>
                                        <p class="form-hint">Loads models from the current form settings so you can choose a default quickly.</p>
                                    </div>

                                    <div class="form-field">
                                        <span class="form-label">Provider Notes</span>
                                        <div class="context-readonly" id="provider-hint">Ollama works best when Doki can reach a host-level instance, commonly at http://host.docker.internal:11434 from Docker.</div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </section>

                    <section class="tab-panel" data-panel="apps">
                        <div class="surface context-surface">
                            <div class="context-tab-toolbar">
                                <div>
                                    <h2 class="panel-title">Apps Context</h2>
                                    <p class="context-tab-toolbar-copy">Toggle multiple blocks on the left, refine the selected block on the right, then save the whole Apps context in one go.</p>
                                </div>
                                <div class="context-tab-state">
                                    <div class="context-dirty-pill" id="apps-context-dirty-indicator" hidden aria-hidden="true"></div>
                                    <div class="context-status" id="apps-context-status"></div>
                                    <div class="context-tab-actions">
                                        <button class="btn secondary" type="button" id="reset-apps-context-btn" disabled hidden aria-hidden="true">
                                            <i class="fas fa-rotate-left"></i>
                                            Reset
                                        </button>
                                        <button class="btn primary" type="button" id="save-apps-context-btn" disabled>
                                            <i class="fas fa-floppy-disk"></i>
                                            Save Apps Context
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div class="context-editor-layout">
                                <aside class="context-sidebar">
                                    <div class="panel-header">
                                        <div>
                                            <h2 class="panel-title">Apps Context Blocks</h2>
                                            <p class="panel-copy">Select a block to edit it, or create a custom block that will be added to Studio AI app prompts.</p>
                                        </div>
                                        <button class="btn secondary" type="button" id="apps-context-new-btn">
                                            <i class="fas fa-plus"></i>
                                            New
                                        </button>
                                    </div>
                                    <div class="context-list" id="apps-context-list"></div>
                                </aside>

                                <div class="provider-form-wrap">
                                    <div class="panel-header">
                                        <div>
                                            <h2 class="panel-title">Block Details</h2>
                                            <p class="panel-copy">Built-in blocks control Doki defaults. Custom blocks let you layer in extra app-specific AI guidance.</p>
                                        </div>
                                    </div>

                                    <form class="provider-form" id="apps-context-form">
                                        <div class="form-row">
                                            <label class="form-field">
                                                <span class="form-label">Title</span>
                                                <input class="form-input" id="apps-context-title" placeholder="New App Context Block">
                                            </label>

                                            <label class="form-field">
                                                <span class="form-label">Description</span>
                                                <input class="form-input" id="apps-context-description" placeholder="What this block is for">
                                            </label>
                                        </div>

                                        <div class="form-field">
                                            <span class="form-label">Content</span>
                                            <textarea class="form-textarea" id="apps-context-content" placeholder="Add the prompt guidance you want Doki to include for app work."></textarea>
                                            <div class="context-readonly" id="apps-context-readonly" hidden></div>
                                            <p class="form-hint" id="apps-context-meta"></p>
                                        </div>

                                        <div class="provider-actions">
                                            <button class="btn danger" type="button" id="delete-apps-context-btn">
                                                <i class="fas fa-trash"></i>
                                                Delete
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="tab-panel" data-panel="commands">
                        <div class="surface context-surface">
                            <div class="context-tab-toolbar">
                                <div>
                                    <h2 class="panel-title">Workflows Context</h2>
                                    <p class="context-tab-toolbar-copy">Make several enable or disable changes from the list, edit the selected block, and save the whole workflows context together.</p>
                                </div>
                                <div class="context-tab-state">
                                    <div class="context-dirty-pill" id="commands-context-dirty-indicator" hidden aria-hidden="true"></div>
                                    <div class="context-status" id="commands-context-status"></div>
                                    <div class="context-tab-actions">
                                        <button class="btn secondary" type="button" id="reset-commands-context-btn" disabled hidden aria-hidden="true">
                                            <i class="fas fa-rotate-left"></i>
                                            Reset
                                        </button>
                                        <button class="btn primary" type="button" id="save-commands-context-btn" disabled>
                                            <i class="fas fa-floppy-disk"></i>
                                            Save Workflows Context
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div class="context-editor-layout">
                                <aside class="context-sidebar">
                                    <div class="panel-header">
                                        <div>
                                            <h2 class="panel-title">Workflows Context Blocks</h2>
                                            <p class="panel-copy">Select a block to edit it, or create a custom block for workflow and template AI guidance.</p>
                                        </div>
                                        <button class="btn secondary" type="button" id="commands-context-new-btn">
                                            <i class="fas fa-plus"></i>
                                            New
                                        </button>
                                    </div>
                                    <div class="context-list" id="commands-context-list"></div>
                                </aside>

                                <div class="provider-form-wrap">
                                    <div class="panel-header">
                                        <div>
                                            <h2 class="panel-title">Block Details</h2>
                                            <p class="panel-copy">Use built-in blocks for baseline guidance and add custom blocks for workflow-specific rules or reusable template guidance.</p>
                                        </div>
                                    </div>

                                    <form class="provider-form" id="commands-context-form">
                                        <div class="form-row">
                                            <label class="form-field">
                                                <span class="form-label">Title</span>
                                                <input class="form-input" id="commands-context-title" placeholder="New Workflows Context Block">
                                            </label>

                                            <label class="form-field">
                                                <span class="form-label">Description</span>
                                                <input class="form-input" id="commands-context-description" placeholder="What this block is for">
                                            </label>
                                        </div>

                                        <div class="form-field">
                                            <span class="form-label">Content</span>
                                            <textarea class="form-textarea" id="commands-context-content" placeholder="Add the prompt guidance you want Doki to include for workflows and templates."></textarea>
                                            <div class="context-readonly" id="commands-context-readonly" hidden></div>
                                            <p class="form-hint" id="commands-context-meta"></p>
                                        </div>

                                        <div class="provider-actions">
                                            <button class="btn danger" type="button" id="delete-commands-context-btn">
                                                <i class="fas fa-trash"></i>
                                                Delete
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </section>
                </div>
            </div>
        </main>
    </div>

    <script>
        const API_BASE = '../api/ai-admin.php';
        const DRAFT_PROVIDER_ID = '__draft_provider__';
        const initialProviders = <?= $providersJson ?: '[]' ?>;
        const initialAppBlocks = <?= $appBlocksJson ?: '[]' ?>;
        const initialCommandBlocks = <?= $commandBlocksJson ?: '[]' ?>;
        const initialProviderList = Array.isArray(initialProviders) ? initialProviders : [];
        const initialAppBlockList = Array.isArray(initialAppBlocks) ? initialAppBlocks : [];
        const initialCommandBlockList = Array.isArray(initialCommandBlocks) ? initialCommandBlocks : [];

        const state = {
            providers: initialProviderList,
            selectedProviderId: initialProviderList[0] ? initialProviderList[0].id : '',
            providerFormBaseline: null,
            providerModelOptions: [],
            blocks: {
                apps: cloneBlocks(initialAppBlockList),
                commands: cloneBlocks(initialCommandBlockList),
            },
            contextBaselines: {
                apps: cloneBlocks(initialAppBlockList),
                commands: cloneBlocks(initialCommandBlockList),
            },
            selectedAppBlockId: initialAppBlockList[0] ? initialAppBlockList[0].id : '',
            selectedCommandBlockId: initialCommandBlockList[0] ? initialCommandBlockList[0].id : '',
        };

        const $ = (id) => document.getElementById(id);

        const providerList = $('provider-list');
        const providerNewBtn = $('provider-new-btn');
        const providerForm = $('provider-form');
        const providerIdInput = $('provider-id');
        const providerPresetInput = $('provider-preset');
        const providerTypeInput = $('provider-type');
        const providerNameInput = $('provider-name');
        const providerIsDefaultInput = $('provider-is-default');
        const providerBaseUrlInput = $('provider-base-url');
        const providerApiKeyInput = $('provider-api-key');
        const providerOpenAiAdvanced = $('provider-openai-advanced');
        const providerChatPathInput = $('provider-chat-path');
        const providerModelsPathInput = $('provider-models-path');
        const providerRequestParamsInput = $('provider-request-params');
        const providerDefaultModelInput = $('provider-default-model');
        const providerDefaultModelOptions = $('provider-default-model-options');
        const providerLoadModelsBtn = $('provider-load-models-btn');
        const providerSaveBtn = $('provider-save-btn');
        const providerTestBtn = $('provider-test-btn');
        const providerDeleteBtn = $('provider-delete-btn');
        const providerStatus = $('provider-status');
        const providerHint = $('provider-hint');

        const appsContextList = $('apps-context-list');
        const appsContextNewBtn = $('apps-context-new-btn');
        const appsContextForm = $('apps-context-form');
        const appsContextTitleInput = $('apps-context-title');
        const appsContextDescriptionInput = $('apps-context-description');
        const appsContextContentInput = $('apps-context-content');
        const appsContextReadonly = $('apps-context-readonly');
        const appsContextMeta = $('apps-context-meta');
        const appsContextDirtyIndicator = $('apps-context-dirty-indicator');
        const appsContextStatus = $('apps-context-status');
        const resetAppsContextBtn = $('reset-apps-context-btn');
        const deleteAppsContextBtn = $('delete-apps-context-btn');
        const commandsContextList = $('commands-context-list');
        const commandsContextNewBtn = $('commands-context-new-btn');
        const commandsContextForm = $('commands-context-form');
        const commandsContextTitleInput = $('commands-context-title');
        const commandsContextDescriptionInput = $('commands-context-description');
        const commandsContextContentInput = $('commands-context-content');
        const commandsContextReadonly = $('commands-context-readonly');
        const commandsContextMeta = $('commands-context-meta');
        const commandsContextDirtyIndicator = $('commands-context-dirty-indicator');
        const commandsContextStatus = $('commands-context-status');
        const resetCommandsContextBtn = $('reset-commands-context-btn');
        const deleteCommandsContextBtn = $('delete-commands-context-btn');
        const saveAppsContextBtn = $('save-apps-context-btn');
        const saveCommandsContextBtn = $('save-commands-context-btn');

        function escapeHtml(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function setBtnLoading(btn, loading) {
            if (!btn) return;
            btn.classList.toggle('is-loading', !!loading);
            btn.disabled = !!loading;
        }

        function cloneBlocks(blocks) {
            return Array.isArray(blocks)
                ? blocks.map(block => ({ ...(block || {}) }))
                : [];
        }

        async function apiGet(action, params = {}) {
            const search = new URLSearchParams({ action, ...params });
            const response = await fetch(`${API_BASE}?${search.toString()}`);
            const data = await response.json().catch(() => ({ success: false, error: 'Invalid server response' }));
            if (!response.ok || !data.success) throw new Error(data.error || 'Request failed');
            return data;
        }

        async function apiPost(action, payload) {
            const response = await fetch(`${API_BASE}?action=${encodeURIComponent(action)}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload || {}),
            });
            const data = await response.json().catch(() => ({ success: false, error: 'Invalid server response' }));
            if (!response.ok || !data.success) throw new Error(data.error || 'Request failed');
            return data;
        }

        function providerDefaults(preset = 'ollama') {
            if (preset === 'openai') {
                return {
                    preset,
                    type: 'openai-compatible',
                    name: 'OpenAI',
                    baseUrl: 'https://api.openai.com/v1',
                    apiKeyPlaceholder: 'Required',
                    hint: 'OpenAI uses the standard API base URL at https://api.openai.com/v1 and Doki will use the native Responses API for OpenAI models.',
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

        function inferProviderPreset(provider = null) {
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

        function providerKindLabel(provider) {
            const preset = inferProviderPreset(provider);
            if (preset === 'openai') return 'OpenAI';
            if (preset === 'claude') return 'Claude';
            if (preset === 'minimax') return 'MiniMax';
            if (preset === 'openrouter') return 'OpenRouter';
            if (preset === 'custom-openai') return 'OpenAI-compatible';
            return 'Ollama';
        }

        function presetUsesOpenAiAdvanced(preset) {
            return ['custom-openai', 'minimax'].includes(preset);
        }

        function normalizeApiPathInput(value) {
            const trimmed = String(value || '').trim();
            if (!trimmed) return '';
            return trimmed.startsWith('/') ? trimmed : `/${trimmed}`;
        }

        function formatJsonValue(value) {
            if (!value || typeof value !== 'object' || Array.isArray(value) || !Object.keys(value).length) {
                return '';
            }

            try {
                return JSON.stringify(value, null, 2);
            } catch (error) {
                return '';
            }
        }

        function toggleProviderAdvancedFields(preset) {
            if (!providerOpenAiAdvanced) return;
            providerOpenAiAdvanced.hidden = !presetUsesOpenAiAdvanced(preset);
        }

        function buildProviderMetadataFromForm() {
            const preset = providerPresetInput?.value || 'ollama';
            const defaults = providerDefaults(preset);
            const metadata = { preset };

            if (preset === 'claude') {
                metadata.anthropicVersion = '2023-06-01';
            }

            if (presetUsesOpenAiAdvanced(preset)) {
                const paths = {
                    chat: normalizeApiPathInput(providerChatPathInput?.value || defaults.chatPath || '/chat/completions'),
                    models: (() => {
                        const rawValue = providerModelsPathInput?.value ?? defaults.modelsPath ?? '';
                        const trimmed = String(rawValue).trim();
                        return trimmed === '' ? '' : normalizeApiPathInput(trimmed);
                    })(),
                };
                metadata.paths = paths;

                const rawParams = String(providerRequestParamsInput?.value || '').trim();
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

        function getProvider(id) {
            return state.providers.find(provider => provider.id === id) || null;
        }

        function isDefaultProvider(provider) {
            return !!provider && (provider.isDefault === true || Number(provider.is_default || 0) === 1);
        }

        function getDefaultProvider() {
            return state.providers.find(provider => isDefaultProvider(provider)) || state.providers[0] || null;
        }

        function currentProvider() {
            return getProvider(state.selectedProviderId) || getDefaultProvider();
        }

        function draftProviderIsActive() {
            return state.selectedProviderId === DRAFT_PROVIDER_ID;
        }

        function buildDraftProviderListEntry() {
            const snapshot = getProviderFormSnapshot();
            const preset = snapshot.providerPreset || 'ollama';
            const defaults = providerDefaults(preset);
            const displayName = snapshot.name || 'New Provider';
            const displayModel = snapshot.defaultModel || 'Not saved yet';

            return {
                id: DRAFT_PROVIDER_ID,
                name: displayName,
                type: defaults.type,
                defaultModel: snapshot.defaultModel || '',
                default_model: snapshot.defaultModel || '',
                isDefault: !!snapshot.isDefault,
                is_default: snapshot.isDefault ? 1 : 0,
                metadata: { preset },
                _isDraft: true,
                _displayModel: displayModel,
            };
        }

        function getProviderFormSnapshot() {
            return {
                id: providerIdInput?.value.trim() || '',
                providerPreset: providerPresetInput?.value || 'ollama',
                type: providerTypeInput?.value || 'ollama',
                name: providerNameInput?.value.trim() || '',
                baseUrl: providerBaseUrlInput?.value.trim() || '',
                apiKey: providerApiKeyInput?.value || '',
                chatPath: providerChatPathInput?.value.trim() || '',
                modelsPath: providerModelsPathInput?.value.trim() || '',
                requestParams: providerRequestParamsInput?.value.trim() || '',
                defaultModel: providerDefaultModelInput?.value.trim() || '',
                isDefault: !!providerIsDefaultInput?.checked,
            };
        }

        function markProviderBaseline() {
            state.providerFormBaseline = getProviderFormSnapshot();
        }

        function providerFormIsDirty() {
            if (!state.providerFormBaseline) return false;
            return JSON.stringify(getProviderFormSnapshot()) !== JSON.stringify(state.providerFormBaseline);
        }

        function renderProviderModelOptions() {
            if (!providerDefaultModelOptions) return;
            providerDefaultModelOptions.innerHTML = (state.providerModelOptions || []).map(model => {
                return `<option value="${escapeHtml(model.id)}">${escapeHtml(model.label || model.id)}</option>`;
            }).join('');
        }

        function resetProviderModelOptions() {
            state.providerModelOptions = [];
            renderProviderModelOptions();
        }

        function updateProviderButtons() {
            const hasSavedProvider = !!providerIdInput?.value.trim();
            const isDirty = providerFormIsDirty();

            if (providerDeleteBtn) providerDeleteBtn.disabled = !hasSavedProvider;
            if (providerTestBtn) providerTestBtn.disabled = !hasSavedProvider || isDirty;

            if (isDirty && providerStatus) {
                providerStatus.textContent = 'Save provider changes before testing this configuration.';
            } else if (!hasSavedProvider && providerStatus && providerStatus.textContent === 'Save provider changes before testing this configuration.') {
                providerStatus.textContent = '';
            }
        }

        function renderProviderList() {
            if (!providerList) return;

            const providers = [...state.providers];
            if (draftProviderIsActive()) {
                providers.unshift(buildDraftProviderListEntry());
            }

            if (!providers.length) {
                state.selectedProviderId = '';
                providerList.innerHTML = '<div class="provider-list-empty">No AI providers configured yet. Create one to get started.</div>';
                return;
            }

            if (!draftProviderIsActive() && !getProvider(state.selectedProviderId)) {
                state.selectedProviderId = getDefaultProvider()?.id || state.providers[0].id;
            }

            providerList.innerHTML = providers.map(provider => {
                const active = provider.id === state.selectedProviderId;
                const defaultBadge = isDefaultProvider(provider)
                    ? '<span class="provider-badge default">Default</span>'
                    : (provider._isDraft ? '<span class="provider-badge draft"><i class="fas fa-pen"></i> Draft</span>' : '');
                const defaultModel = ((provider._displayModel ?? provider.defaultModel ?? provider.default_model ?? '') + '').trim();
                const providerIdLabel = provider._isDraft ? 'Unsaved' : provider.id;
                return `
                    <button class="provider-item ${active ? 'active' : ''}" type="button" data-provider-id="${escapeHtml(provider.id)}">
                        <div class="provider-item-top">
                            <div>
                                <p class="provider-item-title">${escapeHtml(provider.name)}</p>
                                <p class="provider-item-meta">${escapeHtml(providerKindLabel(provider))}</p>
                            </div>
                            ${defaultBadge}
                        </div>
                        <div class="provider-item-bottom">
                            <span class="provider-badge">${escapeHtml(providerIdLabel)}</span>
                            <span class="provider-item-meta">${escapeHtml(defaultModel || 'No default model')}</span>
                        </div>
                    </button>
                `;
            }).join('');

            providerList.querySelectorAll('[data-provider-id]').forEach(button => {
                button.addEventListener('click', () => {
                    const providerId = button.getAttribute('data-provider-id') || '';
                    if (providerId === DRAFT_PROVIDER_ID && draftProviderIsActive()) {
                        return;
                    }
                    state.selectedProviderId = providerId;
                    populateProviderForm(providerId === DRAFT_PROVIDER_ID ? null : getProvider(providerId));
                    renderProviderList();
                });
            });
        }

        function populateProviderForm(provider = null) {
            const preset = inferProviderPreset(provider);
            const defaults = providerDefaults(preset);
            const metadata = provider?.metadata || {};
            const paths = metadata?.paths || {};

            if (providerIdInput) providerIdInput.value = provider?.id || '';
            if (providerPresetInput) providerPresetInput.value = preset;
            if (providerTypeInput) providerTypeInput.value = defaults.type;
            if (providerNameInput) providerNameInput.value = provider?.name || defaults.name;
            if (providerBaseUrlInput) providerBaseUrlInput.value = provider?.baseUrl || provider?.base_url || defaults.baseUrl;
            if (providerApiKeyInput) {
                providerApiKeyInput.value = '';
                providerApiKeyInput.placeholder = provider?.hasApiKey ? 'Leave blank to keep the existing key' : defaults.apiKeyPlaceholder;
            }
            if (providerIsDefaultInput) {
                providerIsDefaultInput.checked = provider ? isDefaultProvider(provider) : state.providers.length === 0;
            }
            if (providerChatPathInput) providerChatPathInput.value = paths.chat || defaults.chatPath || '';
            if (providerModelsPathInput) {
                const modelsPath = Object.prototype.hasOwnProperty.call(paths, 'models')
                    ? (paths.models || '')
                    : (defaults.modelsPath || '');
                providerModelsPathInput.value = modelsPath;
            }
            if (providerRequestParamsInput) providerRequestParamsInput.value = formatJsonValue(metadata.requestParams);
            toggleProviderAdvancedFields(preset);
            if (providerDefaultModelInput) providerDefaultModelInput.value = provider?.defaultModel || provider?.default_model || '';
            if (providerHint) providerHint.textContent = defaults.hint;
            if (providerStatus) providerStatus.textContent = '';
            resetProviderModelOptions();
            markProviderBaseline();
            updateProviderButtons();
        }

        async function refreshProviders(selectProviderId = '') {
            const data = await apiGet('list-providers');
            state.providers = Array.isArray(data.providers) ? data.providers : [];
            state.selectedProviderId = selectProviderId || state.selectedProviderId || getDefaultProvider()?.id || state.providers[0]?.id || '';
            renderProviderList();
            populateProviderForm(draftProviderIsActive() ? null : (getProvider(state.selectedProviderId) || null));
        }

        async function loadDraftProviderModels() {
            setBtnLoading(providerLoadModelsBtn, true);
            if (providerStatus) providerStatus.textContent = '';

            try {
                const payload = {
                    id: providerIdInput?.value.trim() || '',
                    name: providerNameInput?.value.trim() || '',
                    type: providerTypeInput?.value || 'ollama',
                    baseUrl: providerBaseUrlInput?.value.trim() || '',
                    apiKey: providerApiKeyInput?.value || '',
                    defaultModel: providerDefaultModelInput?.value.trim() || '',
                    metadata: buildProviderMetadataFromForm(),
                };

                if (!payload.name) {
                    payload.name = providerDefaults(providerPresetInput?.value || 'ollama').name;
                }

                const data = await apiPost('list-models-draft', payload);
                state.providerModelOptions = Array.isArray(data.models) ? data.models : [];
                renderProviderModelOptions();

                if (providerDefaultModelInput && !providerDefaultModelInput.value.trim()) {
                    const fallback = data.recommendedModel || state.providerModelOptions[0]?.id || '';
                    if (fallback) providerDefaultModelInput.value = fallback;
                }

                if (providerStatus) {
                    providerStatus.textContent = data.recommendedModel
                        ? `Loaded ${state.providerModelOptions.length} model(s). Recommended: ${data.recommendedModel}`
                        : `Loaded ${state.providerModelOptions.length} model(s).`;
                }
            } catch (error) {
                resetProviderModelOptions();
                if (providerStatus) providerStatus.textContent = error.message;
                window.Toast?.error(error.message);
            } finally {
                setBtnLoading(providerLoadModelsBtn, false);
                updateProviderButtons();
            }
        }

        function appsBlocks() {
            return Array.isArray(state.blocks.apps) ? state.blocks.apps : [];
        }

        function scopeBlocks(scope) {
            return scope === 'commands' ? commandBlocks() : appsBlocks();
        }

        function getContextBaseline(scope) {
            return Array.isArray(state.contextBaselines?.[scope]) ? state.contextBaselines[scope] : [];
        }

        function setContextBaseline(scope, blocks) {
            state.contextBaselines[scope] = cloneBlocks(blocks);
        }

        function normalizeContextBlock(block) {
            return JSON.stringify({
                id: String(block?.id || ''),
                title: String(block?.title || ''),
                description: String(block?.description || ''),
                enabled: !!block?.enabled,
                content: String(block?.content || ''),
            });
        }

        function getContextDirtyCount(scope) {
            const baselineBlocks = getContextBaseline(scope);
            const currentBlocks = scopeBlocks(scope);
            const baselineMap = new Map(baselineBlocks.map(block => [String(block?.id || ''), normalizeContextBlock(block)]));
            const currentMap = new Map(currentBlocks.map(block => [String(block?.id || ''), normalizeContextBlock(block)]));
            const ids = new Set([
                ...baselineMap.keys(),
                ...currentMap.keys(),
            ].filter(Boolean));

            let count = 0;
            ids.forEach(id => {
                if (baselineMap.get(id) !== currentMap.get(id)) {
                    count += 1;
                }
            });

            return count;
        }

        function getContextStatusElement(scope) {
            return scope === 'commands' ? commandsContextStatus : appsContextStatus;
        }

        function getContextSaveButton(scope) {
            return scope === 'commands' ? saveCommandsContextBtn : saveAppsContextBtn;
        }

        function getContextResetButton(scope) {
            return scope === 'commands' ? resetCommandsContextBtn : resetAppsContextBtn;
        }

        function getContextDirtyIndicator(scope) {
            return scope === 'commands' ? commandsContextDirtyIndicator : appsContextDirtyIndicator;
        }

        function clearContextStatus(scope) {
            const statusEl = getContextStatusElement(scope);
            if (statusEl) {
                statusEl.textContent = '';
            }
        }

        function updateContextToolbar(scope) {
            const dirtyCount = getContextDirtyCount(scope);
            const saveBtn = getContextSaveButton(scope);
            const resetBtn = getContextResetButton(scope);
            const dirtyIndicator = getContextDirtyIndicator(scope);
            const isSaving = !!saveBtn && saveBtn.classList.contains('is-loading');

            if (saveBtn && !isSaving) {
                saveBtn.disabled = dirtyCount === 0;
            }
            if (resetBtn) {
                resetBtn.hidden = dirtyCount === 0;
                resetBtn.setAttribute('aria-hidden', dirtyCount > 0 ? 'false' : 'true');
                resetBtn.disabled = dirtyCount === 0 || isSaving;
            }
            if (dirtyIndicator) {
                dirtyIndicator.textContent = dirtyCount > 0
                    ? `${dirtyCount} unsaved ${dirtyCount === 1 ? 'change' : 'changes'}`
                    : '';
                dirtyIndicator.hidden = dirtyCount === 0;
                dirtyIndicator.setAttribute('aria-hidden', dirtyCount > 0 ? 'false' : 'true');
            }
        }

        function mergeContextBlocksWithLocalEdits(savedBlocks, localBlocks) {
            const localById = new Map((Array.isArray(localBlocks) ? localBlocks : []).map(block => [String(block?.id || ''), block]));

            return (Array.isArray(savedBlocks) ? savedBlocks : []).map(block => {
                const local = localById.get(String(block?.id || ''));
                if (!local) {
                    return block;
                }

                return {
                    ...block,
                    title: local.title ?? block.title,
                    description: local.description ?? block.description,
                    enabled: Object.prototype.hasOwnProperty.call(local, 'enabled') ? !!local.enabled : !!block.enabled,
                    content: local.content ?? block.content,
                };
            });
        }

        function resetContextScope(scope) {
            const dirtyCount = getContextDirtyCount(scope);
            if (dirtyCount === 0) {
                return;
            }

            const scopeLabel = scope === 'commands' ? 'Workflows' : 'Apps';
            if (!window.confirm(`Discard the unsaved ${scopeLabel.toLowerCase()} context changes?`)) {
                return;
            }

            const baselineBlocks = cloneBlocks(getContextBaseline(scope));
            const previousSelectedId = scope === 'commands' ? state.selectedCommandBlockId : state.selectedAppBlockId;
            const nextSelectedId = baselineBlocks.some(block => block.id === previousSelectedId)
                ? previousSelectedId
                : (baselineBlocks[0]?.id || '');

            state.blocks[scope] = baselineBlocks;
            if (scope === 'commands') {
                state.selectedCommandBlockId = nextSelectedId;
            } else {
                state.selectedAppBlockId = nextSelectedId;
            }

            clearContextStatus(scope);
            renderContextBlocks(scope);
        }

        function getAppsBlock(id) {
            return appsBlocks().find(block => block.id === id) || null;
        }

        function currentAppsBlock() {
            return getAppsBlock(state.selectedAppBlockId) || appsBlocks()[0] || null;
        }

        function appsBlockIsDraft(block) {
            return !!block && String(block.id || '').startsWith('__draft_app_block__');
        }

        function makeDraftAppsBlock() {
            const blocks = appsBlocks();
            const lastBlock = blocks.length ? blocks[blocks.length - 1] : null;
            return {
                id: `__draft_app_block__${Date.now()}`,
                key: '',
                scope: 'apps',
                title: 'New App Context Block',
                description: '',
                editable: true,
                dynamic: false,
                enabled: true,
                content: '',
                sortOrder: ((lastBlock?.sortOrder) || (blocks.length * 10)) + 10,
                isSystem: false,
            };
        }

        function updateSelectedAppsBlock(patch = {}) {
            const index = appsBlocks().findIndex(block => block.id === state.selectedAppBlockId);
            if (index === -1) return;

            state.blocks.apps[index] = {
                ...state.blocks.apps[index],
                ...patch,
            };

            clearContextStatus('apps');
            renderContextBlocks('apps');
        }

        function renderAppsContextList() {
            if (!appsContextList) return;

            const blocks = appsBlocks();
            if (!blocks.length) {
                appsContextList.innerHTML = '<div class="context-item-empty">No app context blocks yet. Create one to get started.</div>';
                return;
            }

            if (!getAppsBlock(state.selectedAppBlockId)) {
                state.selectedAppBlockId = blocks[0].id;
            }

            appsContextList.innerHTML = blocks.map(block => {
                const active = block.id === state.selectedAppBlockId;
                const badges = [];
                if (block.isSystem) {
                    badges.push('<span class="provider-badge">Built-in</span>');
                } else {
                    badges.push(`<span class="provider-badge ${appsBlockIsDraft(block) ? 'draft' : ''}">${appsBlockIsDraft(block) ? 'Draft' : 'Custom'}</span>`);
                }
                if (block.dynamic) {
                    badges.push('<span class="provider-badge">Dynamic</span>');
                }
                if (!block.enabled) {
                    badges.push('<span class="provider-badge">Disabled</span>');
                }

                return `
                    <div class="context-item ${active ? 'active' : ''}" data-app-block-row="${escapeHtml(block.id)}" role="button" tabindex="0" aria-pressed="${active ? 'true' : 'false'}">
                        <div class="context-item-head">
                            <div class="context-item-select">
                                <p class="context-item-title">${escapeHtml(block.title || 'Untitled Block')}</p>
                                <p class="context-item-copy">${escapeHtml(block.description || (block.dynamic ? 'Generated by Doki internals for the current AI request.' : 'Custom guidance block.'))}</p>
                            </div>
                            <label class="inline-toggle context-item-toggle" title="Enable or disable this block">
                                <input type="checkbox" data-app-block-enabled="${escapeHtml(block.id)}" ${block.enabled ? 'checked' : ''}>
                                <span>Enabled</span>
                            </label>
                        </div>
                        <div class="context-item-badges">${badges.join('')}</div>
                    </div>
                `;
            }).join('');

            appsContextList.querySelectorAll('[data-app-block-row]').forEach(row => {
                const selectBlock = () => {
                    const blockId = row.getAttribute('data-app-block-row') || '';
                    state.selectedAppBlockId = blockId;
                    renderContextBlocks('apps');
                };
                row.addEventListener('click', selectBlock);
                row.addEventListener('keydown', (event) => {
                    if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        selectBlock();
                    }
                });
            });
            appsContextList.querySelectorAll('.context-item-toggle').forEach(toggle => {
                toggle.addEventListener('click', (event) => event.stopPropagation());
            });
            appsContextList.querySelectorAll('[data-app-block-enabled]').forEach(input => {
                input.addEventListener('click', (event) => event.stopPropagation());
                input.addEventListener('change', (event) => {
                    const blockId = event.target.getAttribute('data-app-block-enabled') || '';
                    const enabled = !!event.target.checked;
                    const index = appsBlocks().findIndex(block => block.id === blockId);
                    if (index === -1) return;
                    state.blocks.apps[index] = {
                        ...state.blocks.apps[index],
                        enabled,
                    };
                    clearContextStatus('apps');
                    renderContextBlocks('apps');
                });
            });
        }

        function renderAppsContextForm() {
            const block = currentAppsBlock();
            if (!block) {
                if (appsContextForm) appsContextForm.reset();
                if (deleteAppsContextBtn) {
                    deleteAppsContextBtn.disabled = true;
                    deleteAppsContextBtn.hidden = true;
                    deleteAppsContextBtn.style.display = 'none';
                }
                return;
            }

            const canEditMeta = !block.isSystem;
            const canEditContent = !!block.editable;
            const isDraft = appsBlockIsDraft(block);
            const canDelete = !block.isSystem && !block.dynamic;

            if (appsContextTitleInput) {
                appsContextTitleInput.value = block.title || '';
                appsContextTitleInput.disabled = !canEditMeta;
            }
            if (appsContextDescriptionInput) {
                appsContextDescriptionInput.value = block.description || '';
                appsContextDescriptionInput.disabled = !canEditMeta;
            }
            if (appsContextContentInput) {
                appsContextContentInput.hidden = !canEditContent;
                appsContextContentInput.value = block.content || '';
            }
            if (appsContextReadonly) {
                appsContextReadonly.hidden = canEditContent;
                appsContextReadonly.textContent = block.content || 'Generated from Doki internals. This block can be enabled or disabled, but its contents are not edited here.';
            }
            if (appsContextMeta) {
                if (block.isSystem && block.dynamic) {
                    appsContextMeta.textContent = 'Built-in dynamic block: Doki generates this section for the active request. You can only enable or disable it here.';
                } else if (block.dynamic) {
                    appsContextMeta.textContent = 'Dynamic block: Doki generates this section for the active request. It cannot be deleted here.';
                } else if (block.isSystem) {
                    appsContextMeta.textContent = 'Built-in editable block: you can refine the content, but the title and description stay tied to the shipped Doki block.';
                } else {
                    appsContextMeta.textContent = isDraft
                        ? 'Draft custom block: save Apps Context to persist it.'
                        : 'Custom block: this content is appended to app-focused AI prompts when enabled.';
                }
            }
            if (deleteAppsContextBtn) {
                deleteAppsContextBtn.disabled = !canDelete;
                deleteAppsContextBtn.hidden = !canDelete;
                deleteAppsContextBtn.style.display = canDelete ? '' : 'none';
                deleteAppsContextBtn.innerHTML = `<i class="fas fa-trash"></i> ${isDraft ? 'Discard Draft' : 'Delete'}`;
            }
        }

        function removeAppsBlock(blockId) {
            const blocks = appsBlocks();
            const index = blocks.findIndex(block => block.id === blockId);
            if (index === -1) {
                return;
            }

            state.blocks.apps = blocks.filter(block => block.id !== blockId);
            const nextBlock = state.blocks.apps[index] || state.blocks.apps[index - 1] || state.blocks.apps[0] || null;
            state.selectedAppBlockId = nextBlock?.id || '';
            clearContextStatus('apps');
            renderContextBlocks('apps');
        }

        async function deleteSelectedAppsBlock() {
            const block = currentAppsBlock();
            if (!block || block.isSystem || block.dynamic) {
                return;
            }

            if (appsBlockIsDraft(block)) {
                removeAppsBlock(block.id);
                return;
            }

            if (!window.confirm(`Delete the custom block "${block.title}"?`)) {
                return;
            }

            if (deleteAppsContextBtn) setBtnLoading(deleteAppsContextBtn, true);
            if (appsContextStatus) appsContextStatus.textContent = '';

            try {
                const deletedId = block.id;
                const localBlocks = cloneBlocks(appsBlocks());
                const data = await apiPost('delete-context-block', {
                    scope: 'apps',
                    id: deletedId,
                });
                const savedBlocks = Array.isArray(data.blocks) ? data.blocks : [];
                setContextBaseline('apps', savedBlocks);
                state.blocks.apps = mergeContextBlocksWithLocalEdits(savedBlocks, localBlocks.filter(item => item.id !== deletedId));
                if (state.selectedAppBlockId === deletedId) {
                    state.selectedAppBlockId = state.blocks.apps[0]?.id || '';
                }
                renderContextBlocks('apps');
                if (appsContextStatus) appsContextStatus.textContent = 'Block deleted.';
                window.Toast?.success('App context block deleted');
            } catch (error) {
                if (appsContextStatus) appsContextStatus.textContent = error.message;
                window.Toast?.error(error.message);
            } finally {
                if (deleteAppsContextBtn) setBtnLoading(deleteAppsContextBtn, false);
            }
        }

        function commandBlocks() {
            return Array.isArray(state.blocks.commands) ? state.blocks.commands : [];
        }

        function getCommandBlock(id) {
            return commandBlocks().find(block => block.id === id) || null;
        }

        function currentCommandBlock() {
            return getCommandBlock(state.selectedCommandBlockId) || commandBlocks()[0] || null;
        }

        function commandBlockIsDraft(block) {
            return !!block && String(block.id || '').startsWith('__draft_command_block__');
        }

        function makeDraftCommandBlock() {
            const blocks = commandBlocks();
            const lastBlock = blocks.length ? blocks[blocks.length - 1] : null;
            return {
                id: `__draft_command_block__${Date.now()}`,
                key: '',
                scope: 'commands',
                title: 'New Workflows Context Block',
                description: '',
                editable: true,
                dynamic: false,
                enabled: true,
                content: '',
                sortOrder: ((lastBlock?.sortOrder) || (blocks.length * 10)) + 10,
                isSystem: false,
            };
        }

        function updateSelectedCommandBlock(patch = {}) {
            const index = commandBlocks().findIndex(block => block.id === state.selectedCommandBlockId);
            if (index === -1) return;

            state.blocks.commands[index] = {
                ...state.blocks.commands[index],
                ...patch,
            };

            clearContextStatus('commands');
            renderContextBlocks('commands');
        }

        function renderCommandsContextList() {
            if (!commandsContextList) return;

            const blocks = commandBlocks();
            if (!blocks.length) {
                commandsContextList.innerHTML = '<div class="context-item-empty">No workflows context blocks yet. Create one to get started.</div>';
                return;
            }

            if (!getCommandBlock(state.selectedCommandBlockId)) {
                state.selectedCommandBlockId = blocks[0].id;
            }

            commandsContextList.innerHTML = blocks.map(block => {
                const active = block.id === state.selectedCommandBlockId;
                const badges = [];
                if (block.isSystem) {
                    badges.push('<span class="provider-badge">Built-in</span>');
                } else {
                    badges.push(`<span class="provider-badge ${commandBlockIsDraft(block) ? 'draft' : ''}">${commandBlockIsDraft(block) ? 'Draft' : 'Custom'}</span>`);
                }
                if (block.dynamic) {
                    badges.push('<span class="provider-badge">Dynamic</span>');
                }
                if (!block.enabled) {
                    badges.push('<span class="provider-badge">Disabled</span>');
                }

                return `
                    <div class="context-item ${active ? 'active' : ''}" data-command-block-row="${escapeHtml(block.id)}" role="button" tabindex="0" aria-pressed="${active ? 'true' : 'false'}">
                        <div class="context-item-head">
                            <div class="context-item-select">
                                <p class="context-item-title">${escapeHtml(block.title || 'Untitled Block')}</p>
                                <p class="context-item-copy">${escapeHtml(block.description || (block.dynamic ? 'Generated by Doki internals for future workflow AI flows.' : 'Custom workflow guidance block.'))}</p>
                            </div>
                            <label class="inline-toggle context-item-toggle" title="Enable or disable this block">
                                <input type="checkbox" data-command-block-enabled="${escapeHtml(block.id)}" ${block.enabled ? 'checked' : ''}>
                                <span>Enabled</span>
                            </label>
                        </div>
                        <div class="context-item-badges">${badges.join('')}</div>
                    </div>
                `;
            }).join('');

            commandsContextList.querySelectorAll('[data-command-block-row]').forEach(row => {
                const selectBlock = () => {
                    const blockId = row.getAttribute('data-command-block-row') || '';
                    state.selectedCommandBlockId = blockId;
                    renderContextBlocks('commands');
                };
                row.addEventListener('click', selectBlock);
                row.addEventListener('keydown', (event) => {
                    if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        selectBlock();
                    }
                });
            });
            commandsContextList.querySelectorAll('.context-item-toggle').forEach(toggle => {
                toggle.addEventListener('click', (event) => event.stopPropagation());
            });
            commandsContextList.querySelectorAll('[data-command-block-enabled]').forEach(input => {
                input.addEventListener('click', (event) => event.stopPropagation());
                input.addEventListener('change', (event) => {
                    const blockId = event.target.getAttribute('data-command-block-enabled') || '';
                    const enabled = !!event.target.checked;
                    const index = commandBlocks().findIndex(block => block.id === blockId);
                    if (index === -1) return;
                    state.blocks.commands[index] = {
                        ...state.blocks.commands[index],
                        enabled,
                    };
                    clearContextStatus('commands');
                    renderContextBlocks('commands');
                });
            });
        }

        function renderCommandsContextForm() {
            const block = currentCommandBlock();
            if (!block) {
                if (commandsContextForm) commandsContextForm.reset();
                if (deleteCommandsContextBtn) {
                    deleteCommandsContextBtn.disabled = true;
                    deleteCommandsContextBtn.hidden = true;
                    deleteCommandsContextBtn.style.display = 'none';
                }
                return;
            }

            const canEditMeta = !block.isSystem;
            const canEditContent = !!block.editable;
            const isDraft = commandBlockIsDraft(block);
            const canDelete = !block.isSystem && !block.dynamic;

            if (commandsContextTitleInput) {
                commandsContextTitleInput.value = block.title || '';
                commandsContextTitleInput.disabled = !canEditMeta;
            }
            if (commandsContextDescriptionInput) {
                commandsContextDescriptionInput.value = block.description || '';
                commandsContextDescriptionInput.disabled = !canEditMeta;
            }
            if (commandsContextContentInput) {
                commandsContextContentInput.hidden = !canEditContent;
                commandsContextContentInput.value = block.content || '';
            }
            if (commandsContextReadonly) {
                commandsContextReadonly.hidden = canEditContent;
                commandsContextReadonly.textContent = block.content || 'Generated from Doki internals. This block can be enabled or disabled, but its contents are not edited here.';
            }
            if (commandsContextMeta) {
                if (block.isSystem && block.dynamic) {
                    commandsContextMeta.textContent = 'Built-in dynamic block: Doki generates this section for the active request. You can only enable or disable it here.';
                } else if (block.dynamic) {
                    commandsContextMeta.textContent = 'Dynamic block: Doki generates this section for the active request. It cannot be deleted here.';
                } else if (block.isSystem) {
                    commandsContextMeta.textContent = 'Built-in editable block: you can refine the content, but the title and description stay tied to the shipped Doki block.';
                } else {
                    commandsContextMeta.textContent = isDraft
                        ? 'Draft custom block: save Workflows Context to persist it.'
                        : 'Custom block: this content is stored for workflow and template AI guidance when enabled.';
                }
            }
            if (deleteCommandsContextBtn) {
                deleteCommandsContextBtn.disabled = !canDelete;
                deleteCommandsContextBtn.hidden = !canDelete;
                deleteCommandsContextBtn.style.display = canDelete ? '' : 'none';
                deleteCommandsContextBtn.innerHTML = `<i class="fas fa-trash"></i> ${isDraft ? 'Discard Draft' : 'Delete'}`;
            }
        }

        function removeCommandBlock(blockId) {
            const blocks = commandBlocks();
            const index = blocks.findIndex(block => block.id === blockId);
            if (index === -1) {
                return;
            }

            state.blocks.commands = blocks.filter(block => block.id !== blockId);
            const nextBlock = state.blocks.commands[index] || state.blocks.commands[index - 1] || state.blocks.commands[0] || null;
            state.selectedCommandBlockId = nextBlock?.id || '';
            clearContextStatus('commands');
            renderContextBlocks('commands');
        }

        async function deleteSelectedCommandBlock() {
            const block = currentCommandBlock();
            if (!block || block.isSystem || block.dynamic) {
                return;
            }

            if (commandBlockIsDraft(block)) {
                removeCommandBlock(block.id);
                return;
            }

            if (!window.confirm(`Delete the custom block "${block.title}"?`)) {
                return;
            }

            if (deleteCommandsContextBtn) setBtnLoading(deleteCommandsContextBtn, true);
            if (commandsContextStatus) commandsContextStatus.textContent = '';

            try {
                const deletedId = block.id;
                const localBlocks = cloneBlocks(commandBlocks());
                const data = await apiPost('delete-context-block', {
                    scope: 'commands',
                    id: deletedId,
                });
                const savedBlocks = Array.isArray(data.blocks) ? data.blocks : [];
                setContextBaseline('commands', savedBlocks);
                state.blocks.commands = mergeContextBlocksWithLocalEdits(savedBlocks, localBlocks.filter(item => item.id !== deletedId));
                if (state.selectedCommandBlockId === deletedId) {
                    state.selectedCommandBlockId = state.blocks.commands[0]?.id || '';
                }
                renderContextBlocks('commands');
                if (commandsContextStatus) commandsContextStatus.textContent = 'Block deleted.';
                window.Toast?.success('Command context block deleted');
            } catch (error) {
                if (commandsContextStatus) commandsContextStatus.textContent = error.message;
                window.Toast?.error(error.message);
            } finally {
                if (deleteCommandsContextBtn) setBtnLoading(deleteCommandsContextBtn, false);
            }
        }

        function collectContextBlocks(scope) {
            if (scope === 'apps') {
                return appsBlocks().map(block => ({
                    id: block.id,
                    title: block.title,
                    description: block.description,
                    enabled: !!block.enabled,
                    content: block.content,
                }));
            }

            if (scope === 'commands') {
                return commandBlocks().map(block => ({
                    id: block.id,
                    title: block.title,
                    description: block.description,
                    enabled: !!block.enabled,
                    content: block.content,
                }));
            }

            return [];
        }

        function renderContextBlocks(scope) {
            if (scope === 'apps') {
                renderAppsContextList();
                renderAppsContextForm();
                updateContextToolbar('apps');
                return;
            }

            if (scope === 'commands') {
                renderCommandsContextList();
                renderCommandsContextForm();
                updateContextToolbar('commands');
                return;
            }
        }

        async function saveContextScope(scope) {
            const button = scope === 'commands' ? saveCommandsContextBtn : saveAppsContextBtn;
            const statusEl = getContextStatusElement(scope);
            const previousSelectedId = scope === 'apps' ? state.selectedAppBlockId : state.selectedCommandBlockId;

            setBtnLoading(button, true);
            updateContextToolbar(scope);
            if (statusEl) statusEl.textContent = '';

            try {
                const data = await apiPost('save-context-blocks', {
                    scope,
                    blocks: collectContextBlocks(scope),
                });
                const savedBlocks = Array.isArray(data.blocks) ? data.blocks : [];
                state.blocks[scope] = cloneBlocks(savedBlocks);
                setContextBaseline(scope, savedBlocks);
                const created = data.created && typeof data.created === 'object' ? data.created : {};
                const preferredId = created[previousSelectedId] || previousSelectedId;

                if (scope === 'apps') {
                    state.selectedAppBlockId = getAppsBlock(preferredId)?.id
                        ? preferredId
                        : (state.blocks.apps[0]?.id || '');
                } else if (scope === 'commands') {
                    state.selectedCommandBlockId = getCommandBlock(preferredId)?.id
                        ? preferredId
                        : (state.blocks.commands[0]?.id || '');
                }

                renderContextBlocks(scope);
                if (statusEl) statusEl.textContent = 'Saved.';
                window.Toast?.success(`${scope === 'commands' ? 'Workflows' : 'Apps'} context saved`);
            } catch (error) {
                if (statusEl) statusEl.textContent = error.message;
                window.Toast?.error(error.message);
            } finally {
                setBtnLoading(button, false);
                updateContextToolbar(scope);
            }
        }

        function setActiveTab(tabId) {
            document.querySelectorAll('[data-tab]').forEach(button => {
                button.classList.toggle('active', button.getAttribute('data-tab') === tabId);
            });
            document.querySelectorAll('[data-panel]').forEach(panel => {
                panel.classList.toggle('active', panel.getAttribute('data-panel') === tabId);
            });
        }

        document.querySelectorAll('[data-tab]').forEach(button => {
            button.addEventListener('click', () => setActiveTab(button.getAttribute('data-tab') || 'providers'));
        });

        appsContextNewBtn?.addEventListener('click', () => {
            const draftBlock = makeDraftAppsBlock();
            state.blocks.apps = [...appsBlocks(), draftBlock];
            state.selectedAppBlockId = draftBlock.id;
            clearContextStatus('apps');
            renderContextBlocks('apps');
            appsContextTitleInput?.focus();
            appsContextTitleInput?.select();
        });
        appsContextTitleInput?.addEventListener('input', () => updateSelectedAppsBlock({ title: appsContextTitleInput.value }));
        appsContextDescriptionInput?.addEventListener('input', () => updateSelectedAppsBlock({ description: appsContextDescriptionInput.value }));
        appsContextContentInput?.addEventListener('input', () => updateSelectedAppsBlock({ content: appsContextContentInput.value }));
        deleteAppsContextBtn?.addEventListener('click', deleteSelectedAppsBlock);
        resetAppsContextBtn?.addEventListener('click', () => resetContextScope('apps'));
        commandsContextNewBtn?.addEventListener('click', () => {
            const draftBlock = makeDraftCommandBlock();
            state.blocks.commands = [...commandBlocks(), draftBlock];
            state.selectedCommandBlockId = draftBlock.id;
            clearContextStatus('commands');
            renderContextBlocks('commands');
            commandsContextTitleInput?.focus();
            commandsContextTitleInput?.select();
        });
        commandsContextTitleInput?.addEventListener('input', () => updateSelectedCommandBlock({ title: commandsContextTitleInput.value }));
        commandsContextDescriptionInput?.addEventListener('input', () => updateSelectedCommandBlock({ description: commandsContextDescriptionInput.value }));
        commandsContextContentInput?.addEventListener('input', () => updateSelectedCommandBlock({ content: commandsContextContentInput.value }));
        deleteCommandsContextBtn?.addEventListener('click', deleteSelectedCommandBlock);
        resetCommandsContextBtn?.addEventListener('click', () => resetContextScope('commands'));

        providerNewBtn?.addEventListener('click', () => {
            state.selectedProviderId = DRAFT_PROVIDER_ID;
            populateProviderForm(null);
            renderProviderList();
            providerNameInput?.focus();
        });

        providerPresetInput?.addEventListener('change', () => {
            const defaults = providerDefaults(providerPresetInput.value || 'ollama');
            if (providerTypeInput) providerTypeInput.value = defaults.type;
            if (providerHint) providerHint.textContent = defaults.hint;
            if (providerApiKeyInput) providerApiKeyInput.placeholder = defaults.apiKeyPlaceholder;
            toggleProviderAdvancedFields(defaults.preset);
            if (providerBaseUrlInput && !providerIdInput?.value.trim()) {
                providerBaseUrlInput.value = defaults.baseUrl;
            }
            if (providerNameInput && !providerIdInput?.value.trim()) {
                const knownDefaults = ['Local Ollama', 'OpenAI', 'Claude', 'MiniMax', 'OpenRouter', 'Custom OpenAI-Compatible'];
                if (!providerNameInput.value.trim() || knownDefaults.includes(providerNameInput.value.trim())) {
                    providerNameInput.value = defaults.name;
                }
            }
            if (providerChatPathInput && !providerIdInput?.value.trim()) {
                providerChatPathInput.value = defaults.chatPath || '';
            }
            if (providerModelsPathInput && !providerIdInput?.value.trim()) {
                providerModelsPathInput.value = defaults.modelsPath || '';
            }
            if (providerRequestParamsInput && !providerIdInput?.value.trim()) {
                providerRequestParamsInput.value = '';
            }
            resetProviderModelOptions();
            updateProviderButtons();
        });

        [providerNameInput, providerBaseUrlInput, providerApiKeyInput, providerChatPathInput, providerModelsPathInput, providerRequestParamsInput, providerDefaultModelInput].forEach(input => {
            input?.addEventListener('input', () => {
                if (
                    input === providerBaseUrlInput
                    || input === providerApiKeyInput
                    || input === providerChatPathInput
                    || input === providerModelsPathInput
                    || input === providerRequestParamsInput
                ) {
                    resetProviderModelOptions();
                }
                if (draftProviderIsActive()) {
                    renderProviderList();
                }
                updateProviderButtons();
            });
        });
        providerIsDefaultInput?.addEventListener('change', () => {
            if (draftProviderIsActive()) {
                renderProviderList();
            }
            updateProviderButtons();
        });

        providerLoadModelsBtn?.addEventListener('click', loadDraftProviderModels);

        providerForm?.addEventListener('submit', async (event) => {
            event.preventDefault();
            const payload = Object.fromEntries(new FormData(providerForm).entries());
            const existingProvider = getProvider(payload.id || '');
            const defaults = providerDefaults(payload.providerPreset || providerPresetInput?.value || 'ollama');
            payload.type = defaults.type;
            payload.isDefault = providerIsDefaultInput?.checked ? '1' : '0';
            setBtnLoading(providerSaveBtn, true);
            if (providerStatus) providerStatus.textContent = '';

            try {
                payload.metadata = buildProviderMetadataFromForm();
                delete payload.providerPreset;
                if ((payload.apiKey || '').trim() === '' && existingProvider?.hasApiKey) {
                    delete payload.apiKey;
                }

                const data = await apiPost('save-provider', payload);
                await refreshProviders(data.provider?.id || '');
                if (providerStatus) providerStatus.textContent = 'Provider saved.';
                window.Toast?.success('AI provider saved');
            } catch (error) {
                if (providerStatus) providerStatus.textContent = error.message;
                window.Toast?.error(error.message);
            } finally {
                setBtnLoading(providerSaveBtn, false);
                updateProviderButtons();
            }
        });

        providerTestBtn?.addEventListener('click', async () => {
            const providerId = providerIdInput?.value.trim() || '';
            if (!providerId) {
                window.Toast?.error('Save the provider before testing it');
                return;
            }

            setBtnLoading(providerTestBtn, true);
            if (providerStatus) providerStatus.textContent = '';

            try {
                const data = await apiPost('test-provider', { id: providerId });
                if (providerStatus) providerStatus.textContent = data.message || 'Provider test succeeded';
                window.Toast?.success(data.message || 'Provider test succeeded');
            } catch (error) {
                if (providerStatus) providerStatus.textContent = error.message;
                window.Toast?.error(error.message);
            } finally {
                setBtnLoading(providerTestBtn, false);
                updateProviderButtons();
            }
        });

        providerDeleteBtn?.addEventListener('click', async () => {
            const providerId = providerIdInput?.value.trim() || '';
            if (!providerId) return;
            if (!window.confirm('Delete this AI provider?')) return;

            setBtnLoading(providerDeleteBtn, true);
            if (providerStatus) providerStatus.textContent = '';

            try {
                await apiPost('delete-provider', { id: providerId });
                state.selectedProviderId = '';
                await refreshProviders('');
                window.Toast?.success('AI provider deleted');
            } catch (error) {
                if (providerStatus) providerStatus.textContent = error.message;
                window.Toast?.error(error.message);
            } finally {
                setBtnLoading(providerDeleteBtn, false);
                updateProviderButtons();
            }
        });

        saveAppsContextBtn?.addEventListener('click', () => saveContextScope('apps'));
        saveCommandsContextBtn?.addEventListener('click', () => saveContextScope('commands'));

        renderProviderList();
        populateProviderForm(currentProvider() || null);
        renderContextBlocks('apps');
        renderContextBlocks('commands');
        updateProviderButtons();
    </script>
</body>
</html>
