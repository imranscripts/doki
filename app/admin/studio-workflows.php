<?php
/**
 * admin/studio-workflows.php - Workflows Studio editor and publish flow
 */

require_once __DIR__ . '/../includes/middleware.php';
require_once __DIR__ . '/../includes/Layout.php';
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/AIAdminManager.php';
require_once __DIR__ . '/../includes/AIProviderManager.php';
require_once __DIR__ . '/../includes/CommandsManager.php';
require_once __DIR__ . '/../includes/ConfigManager.php';
require_once __DIR__ . '/../includes/SourcesManager.php';
require_once __DIR__ . '/../includes/TemplateManager.php';
require_once __DIR__ . '/../includes/TemplateStudioManager.php';
require_once __DIR__ . '/../includes/WorkflowStudioManager.php';

$user = requireRole('admin');
requireModuleAccess('studio.workflows');
$layout = new Layout($user, 'studio.workflows');

$auth = new Auth();
$auth->validateSession($_SESSION['auth_token'] ?? '');

$workflowStudioManager = new WorkflowStudioManager($auth);
$templateStudioManager = new TemplateStudioManager($auth);
$commandsManager = new CommandsManager();
$configManager = new ConfigManager();
$templateManager = new TemplateManager();
$sourcesManager = new SourcesManager();
$aiAdminManager = new AIAdminManager();
$aiProviderManager = new AIProviderManager();

$workflowProjects = $workflowStudioManager->listProjects();
$templateProjects = $templateStudioManager->listProjects();
$commandsResult = $commandsManager->getCommands();
$templatesResult = $templateManager->getTemplates();
$targets = $configManager->getTargets();
$environments = $configManager->getEnvironments();
$repositories = $sourcesManager->getRepositories();
$workflowContextBlocks = $aiAdminManager->getContextBlocks(AIAdminManager::SCOPE_COMMANDS);
$studioAiEnabled = $auth->canAccessResource('module', 'studio.ai');
$aiProviders = $studioAiEnabled ? $aiProviderManager->getProviders() : [];

$publishedCommandCount = !empty($commandsResult['success']) ? count($commandsResult['commands'] ?? []) : 0;
$publishedTemplateCount = !empty($templatesResult['success']) ? count($templatesResult['templates'] ?? []) : 0;
$templateRepoCount = count(array_filter(
    $repositories,
    static fn(array $repo): bool => in_array((string)($repo['type'] ?? ''), [SourcesManager::TYPE_TEMPLATES, SourcesManager::TYPE_MIXED], true)
));
$workflowContextCount = count($workflowContextBlocks);

$initialStudioState = [
    'workflowProjects' => $workflowProjects,
    'templateProjects' => $templateProjects,
    'workflowDefaults' => $workflowStudioManager->getDefaultDraft(),
    'templateDefaults' => $templateStudioManager->getDefaultDraft(),
    'stats' => [
        'workflowProjects' => count($workflowProjects),
        'templateProjects' => count($templateProjects),
        'publishedCommands' => $publishedCommandCount,
        'publishedTemplates' => $publishedTemplateCount,
        'templateRepositories' => $templateRepoCount,
        'workflowContextBlocks' => $workflowContextCount,
    ],
    'runtimeTemplates' => !empty($templatesResult['success']) ? ($templatesResult['templates'] ?? []) : [],
    'targets' => $targets,
    'environments' => $environments,
    'links' => [
        'commands' => '../commands.php',
        'templates' => '../templates.php',
        'history' => '../history.php',
        'sources' => 'sources.php',
        'ai' => 'ai.php',
    ],
    'aiEnabled' => $studioAiEnabled,
    'aiProviders' => $aiProviders,
];
$initialStudioStateJson = json_encode($initialStudioState, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $layout->renderHead('Workflows Studio'); ?>
    <style>
        .studio-shell {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .studio-shell [hidden] {
            display: none !important;
        }

        .studio-topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
        }

        .studio-topbar-main {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .studio-topbar-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .studio-icon-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 12px;
            border-radius: 10px;
            border: 1px solid var(--border-primary);
            background: var(--bg-secondary);
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            transition: all 0.15s ease;
        }

        .studio-icon-link:hover {
            color: var(--text-primary);
            border-color: var(--accent-primary);
            background: rgba(88, 166, 255, 0.06);
        }

        .studio-hero {
            display: flex;
            flex-direction: column;
            gap: 18px;
            padding: 24px;
            border: 1px solid var(--border-primary);
            border-radius: 18px;
            background:
                radial-gradient(circle at top right, rgba(88, 166, 255, 0.14), transparent 32%),
                linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01)),
                var(--bg-secondary);
        }

        .studio-hero-top {
            display: flex;
            align-items: start;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
        }

        .studio-kicker {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 10px;
            border-radius: 999px;
            background: rgba(88, 166, 255, 0.12);
            color: var(--accent-primary);
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .studio-title {
            margin: 10px 0 8px;
            font-size: 32px;
            line-height: 1.08;
        }

        .studio-copy {
            margin: 0;
            max-width: 760px;
            color: var(--text-secondary);
            font-size: 14px;
            line-height: 1.7;
        }

        .studio-quicklinks {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .studio-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 14px;
            border-radius: 10px;
            border: 1px solid var(--border-primary);
            background: var(--bg-primary);
            color: var(--text-primary);
            text-decoration: none;
            font-weight: 600;
            transition: all 0.15s ease;
        }

        .studio-link:hover {
            border-color: var(--accent-primary);
            background: rgba(88, 166, 255, 0.06);
        }

        .studio-stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
        }

        .studio-stat {
            padding: 14px 16px;
            border-radius: 14px;
            border: 1px solid var(--border-primary);
            background: rgba(255,255,255,0.02);
        }

        .studio-stat-label {
            color: var(--text-muted);
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .studio-stat-value {
            margin-top: 6px;
            font-size: 24px;
            font-weight: 700;
        }

        .studio-workbench {
            display: grid;
            grid-template-columns: 270px minmax(0, 1fr) 360px;
            gap: 18px;
            min-height: 680px;
        }

        .studio-panel {
            border: 1px solid var(--border-primary);
            border-radius: 18px;
            background: var(--bg-secondary);
            overflow: hidden;
            min-height: 0;
        }

        .studio-panel-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 16px 18px;
            border-bottom: 1px solid var(--border-primary);
            background: rgba(255,255,255,0.02);
        }

        .studio-panel-title {
            margin: 0;
            font-size: 17px;
        }

        .studio-panel-copy {
            margin: 4px 0 0;
            color: var(--text-secondary);
            font-size: 13px;
            line-height: 1.5;
        }

        .studio-panel-copy:empty {
            display: none;
        }

        .studio-tabs {
            display: inline-flex;
            gap: 6px;
            padding: 6px;
            border-radius: 999px;
            background: rgba(255,255,255,0.03);
            border: 1px solid var(--border-primary);
        }

        .studio-tab {
            border: 0;
            background: transparent;
            color: var(--text-secondary);
            border-radius: 999px;
            padding: 8px 12px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
        }

        .studio-tab.active {
            background: rgba(88, 166, 255, 0.14);
            color: var(--accent-primary);
        }

        .studio-tab-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 18px;
            height: 18px;
            margin-left: 6px;
            padding: 0 6px;
            border-radius: 999px;
            background: rgba(255,255,255,0.08);
            color: inherit;
            font-size: 11px;
            line-height: 1;
        }

        .studio-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 14px;
            border-radius: 10px;
            border: 1px solid var(--border-primary);
            background: var(--bg-primary);
            color: var(--text-primary);
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .studio-btn:hover:not(:disabled) {
            border-color: var(--accent-primary);
            background: rgba(88, 166, 255, 0.06);
        }

        .studio-btn.primary {
            background: var(--accent-primary);
            border-color: var(--accent-primary);
            color: #0d1117;
        }

        .studio-btn.secondary {
            background: rgba(255,255,255,0.03);
        }

        .studio-btn.danger:hover:not(:disabled) {
            border-color: rgba(248, 81, 73, 0.55);
            background: rgba(248, 81, 73, 0.08);
            color: #ff7b72;
        }

        .studio-btn:disabled {
            opacity: 0.55;
            cursor: not-allowed;
        }

        .studio-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
            padding: 14px;
            max-height: calc(100vh - 220px);
            overflow: auto;
        }

        .studio-list-empty {
            padding: 14px;
            border: 1px dashed var(--border-primary);
            border-radius: 14px;
            color: var(--text-secondary);
            font-size: 13px;
            line-height: 1.6;
        }

        .studio-list-item {
            display: flex;
            flex-direction: column;
            gap: 8px;
            width: 100%;
            text-align: left;
            border: 1px solid var(--border-primary);
            border-radius: 14px;
            background: var(--bg-primary);
            color: var(--text-primary);
            padding: 14px;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .studio-list-item:hover,
        .studio-list-item.active {
            border-color: var(--accent-primary);
            background: rgba(88, 166, 255, 0.07);
        }

        .studio-list-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }

        .studio-list-title {
            font-size: 14px;
            font-weight: 700;
            line-height: 1.4;
        }

        .studio-list-meta {
            color: var(--text-secondary);
            font-size: 12px;
            line-height: 1.5;
        }

        .studio-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 8px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
        }

        .studio-badge.draft { background: rgba(88, 166, 255, 0.12); color: var(--accent-primary); }
        .studio-badge.published { background: rgba(63, 185, 80, 0.12); color: #3fb950; }
        .studio-badge.archived { background: rgba(139, 148, 158, 0.16); color: var(--text-secondary); }
        .studio-badge.valid { background: rgba(63, 185, 80, 0.12); color: #3fb950; }
        .studio-badge.invalid { background: rgba(248, 81, 73, 0.14); color: #ff7b72; }

        .studio-editor {
            display: flex;
            flex-direction: column;
            min-height: 0;
        }

        .studio-editor-body {
            display: flex;
            flex-direction: column;
            gap: 16px;
            padding: 18px;
            min-height: 0;
        }

        .studio-fields {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }

        .studio-field {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .studio-field.full {
            grid-column: 1 / -1;
        }

        .studio-label {
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.03em;
            text-transform: uppercase;
            color: var(--text-muted);
        }

        .studio-input,
        .studio-textarea,
        .studio-select {
            width: 100%;
            border-radius: 12px;
            border: 1px solid var(--border-primary);
            background: var(--bg-primary);
            color: var(--text-primary);
            padding: 11px 12px;
            font-size: 14px;
        }

        .studio-textarea {
            min-height: 108px;
            resize: vertical;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
            line-height: 1.55;
        }

        .studio-textarea.json {
            min-height: 360px;
        }

        .studio-input:focus,
        .studio-textarea:focus,
        .studio-select:focus {
            outline: none;
            border-color: var(--accent-primary);
            box-shadow: 0 0 0 3px rgba(88, 166, 255, 0.12);
        }

        .studio-helper {
            color: var(--text-secondary);
            font-size: 12px;
            line-height: 1.6;
        }

        .studio-array-editor {
            display: flex;
            flex-direction: column;
            gap: 10px;
            padding: 12px;
            border-radius: 12px;
            border: 1px solid var(--border-primary);
            background: var(--bg-primary);
        }

        .studio-array-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .studio-array-row {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .studio-array-row .studio-input {
            flex: 1;
        }

        .studio-array-actions {
            display: flex;
            justify-content: flex-start;
        }

        .studio-array-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            min-width: 40px;
            border-radius: 10px;
            border: 1px solid var(--border-primary);
            background: rgba(255,255,255,0.03);
            color: var(--text-secondary);
            padding: 9px 12px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .studio-array-btn:hover {
            border-color: var(--accent-primary);
            color: var(--text-primary);
            background: rgba(88, 166, 255, 0.08);
        }

        .studio-editor-toggle {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .studio-mode-toggle {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px;
            border-radius: 999px;
            border: 1px solid var(--border-primary);
            background: rgba(255,255,255,0.03);
        }

        .studio-mode-option {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 72px;
            padding: 8px 12px;
            border-radius: 999px;
            border: 0;
            background: transparent;
            color: var(--text-secondary);
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .studio-mode-option.active {
            background: rgba(88, 166, 255, 0.14);
            color: var(--accent-primary);
        }

        .studio-mode-panel {
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .studio-preview-hero {
            display: flex;
            align-items: start;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .studio-preview-title {
            margin: 0;
            font-size: 20px;
            font-weight: 700;
            line-height: 1.2;
        }

        .studio-preview-copy {
            color: var(--text-secondary);
            font-size: 13px;
            line-height: 1.6;
        }

        .studio-preview-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        .studio-preview-card {
            display: flex;
            flex-direction: column;
            gap: 8px;
            padding: 12px;
            border-radius: 12px;
            border: 1px solid var(--border-primary);
            background: rgba(255,255,255,0.02);
        }

        .studio-preview-label {
            color: var(--text-muted);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .studio-preview-value {
            color: var(--text-primary);
            font-size: 13px;
            line-height: 1.6;
            word-break: break-word;
        }

        .studio-preview-stack {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .studio-step-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
            max-height: min(52vh, 560px);
            overflow: auto;
            padding-right: 4px;
        }

        .studio-step-item {
            display: grid;
            grid-template-columns: 30px minmax(0, 1fr);
            gap: 10px;
            align-items: start;
            padding: 10px 12px;
            border-radius: 12px;
            border: 1px solid var(--border-primary);
            background: rgba(255,255,255,0.02);
        }

        .studio-step-index {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 30px;
            height: 30px;
            border-radius: 999px;
            background: rgba(88, 166, 255, 0.12);
            color: var(--accent-primary);
            font-size: 12px;
            font-weight: 700;
        }

        .studio-step-main {
            min-width: 0;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .studio-step-title-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            flex-wrap: wrap;
        }

        .studio-step-name {
            font-size: 14px;
            font-weight: 700;
            line-height: 1.35;
            color: var(--text-primary);
        }

        .studio-step-subtitle {
            color: var(--text-secondary);
            font-size: 12px;
            line-height: 1.5;
        }

        .studio-step-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .studio-step-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 8px;
            border-radius: 999px;
            background: rgba(255,255,255,0.04);
            color: var(--text-secondary);
            font-size: 11px;
            font-weight: 600;
        }

        .studio-preview-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .studio-preview-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            border-radius: 999px;
            background: rgba(255,255,255,0.04);
            color: var(--text-secondary);
            font-size: 12px;
            font-weight: 600;
        }

        .studio-preview-code {
            margin: 0;
            padding: 10px 12px;
            border-radius: 10px;
            background: rgba(0,0,0,0.22);
            color: var(--text-primary);
            font-size: 12px;
            line-height: 1.6;
            white-space: pre-wrap;
            word-break: break-word;
            overflow: auto;
        }

        .studio-use-shell {
            display: flex;
            flex-direction: column;
            gap: 14px;
            padding: 14px;
            border-radius: 14px;
            border: 1px solid var(--border-primary);
            background: var(--bg-primary);
        }

        .studio-use-inputs {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }

        .studio-use-result-steps {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .studio-use-step {
            padding: 12px;
            border-radius: 12px;
            background: rgba(255,255,255,0.03);
            border: 1px solid var(--border-primary);
        }

        .studio-use-step-head {
            display: flex;
            align-items: start;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 8px;
        }

        .studio-inline-card {
            display: flex;
            flex-direction: column;
            gap: 10px;
            padding: 14px;
            border-radius: 14px;
            border: 1px solid var(--border-primary);
            background: rgba(255,255,255,0.02);
        }

        .studio-builder-card {
            gap: 16px;
        }

        .studio-builder-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 14px;
        }

        .studio-builder-section {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .studio-builder-title-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }

        .studio-toggle-opts .toggle-opt {
            padding: 10px 12px;
            font-size: 12px;
            font-weight: 600;
        }

        .studio-stepper {
            display: inline-flex;
            align-items: center;
        }

        .studio-stepper-btn {
            width: 38px;
            height: 38px;
            border: 1px solid var(--border-primary);
            background: var(--bg-tertiary);
            color: var(--text-primary);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .studio-stepper-btn:first-child {
            border-radius: 8px 0 0 8px;
        }

        .studio-stepper-btn:last-child {
            border-radius: 0 8px 8px 0;
            border-left: none;
        }

        .studio-stepper-value {
            min-width: 52px;
            height: 38px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-top: 1px solid var(--border-primary);
            border-bottom: 1px solid var(--border-primary);
            background: var(--bg-tertiary);
            color: var(--text-primary);
            font-weight: 600;
        }

        .studio-json-details {
            border: 1px solid var(--border-primary);
            border-radius: 14px;
            background: rgba(255,255,255,0.02);
            overflow: hidden;
        }

        .studio-json-details summary {
            padding: 14px;
            cursor: pointer;
            font-weight: 600;
            color: var(--text-primary);
            list-style: none;
        }

        .studio-json-details summary::-webkit-details-marker {
            display: none;
        }

        .studio-json-details-body {
            padding: 0 14px 14px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .toggle-opts {
            display: flex;
            gap: 0;
        }

        .toggle-opt {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            padding: 9px 14px;
            border: 1.5px solid var(--border-primary);
            background: var(--bg-tertiary);
            color: var(--text-secondary);
            font-size: 13px;
            cursor: pointer;
            transition: all .12s ease;
            font-family: inherit;
            position: relative;
            z-index: 0;
        }

        .toggle-opts .toggle-opt:not(:first-child) {
            margin-left: -1.5px;
        }

        .toggle-opts .toggle-opt:first-child {
            border-radius: 8px 0 0 8px;
        }

        .toggle-opts .toggle-opt:last-child {
            border-radius: 0 8px 8px 0;
        }

        .toggle-opt.active {
            background: var(--accent-glow);
            border-color: var(--accent-primary);
            color: var(--accent-primary);
            font-weight: 600;
            z-index: 1;
        }

        .toggle-opt:hover:not(.active) {
            background: var(--bg-hover);
            color: var(--text-primary);
            z-index: 1;
        }

        .step-card {
            border: 1.5px solid var(--border-primary);
            border-radius: 10px;
            overflow: hidden;
            background: var(--bg-primary);
            transition: border-color .15s;
        }

        .step-card:hover {
            border-color: rgba(88,166,255,.25);
        }

        .step-card-header {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 12px;
            background: var(--bg-secondary);
            border-bottom: 1px solid var(--border-primary);
        }

        .step-num-sm {
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background: var(--bg-tertiary);
            color: var(--text-muted);
            font-size: 11px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .step-id-input {
            flex: 1;
            border: none;
            background: none;
            color: var(--text-muted);
            font-size: 12px;
            font-family: 'JetBrains Mono', 'Fira Code', monospace;
            outline: none;
            min-width: 0;
            padding: 2px 0;
        }

        .step-id-input:hover {
            color: var(--text-secondary);
        }

        .step-id-input:focus {
            color: var(--text-primary);
            border-bottom: 1px solid var(--accent-primary);
        }

        .step-card-body {
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .step-options-row {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
        }

        .step-opt-group {
            flex: 1;
            min-width: 180px;
        }

        .step-target-inline {
            background: var(--bg-secondary);
            border: 1px solid var(--border-primary);
            border-radius: 8px;
            padding: 12px;
            margin-top: 2px;
        }

        .step-ctrl {
            display: flex;
            gap: 4px;
        }

        .step-ctrl-btn {
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            padding: 4px 6px;
            border-radius: 4px;
            font-size: 12px;
        }

        .step-ctrl-btn:hover {
            background: var(--bg-hover);
            color: var(--text-primary);
        }

        .step-ctrl-btn:disabled {
            opacity: .3;
            cursor: not-allowed;
        }

        .step-conn {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 4px 0;
            position: relative;
        }

        .step-conn-line {
            width: 2px;
            height: 10px;
            background: var(--border-primary);
        }

        .connector-pill {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            border: 1.5px solid var(--border-primary);
            border-radius: 999px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            background: var(--bg-secondary);
            transition: all .15s ease;
            white-space: nowrap;
        }

        .connector-pill:hover {
            border-color: var(--accent-primary);
            background: var(--bg-hover);
        }

        .connector-pill.success {
            border-color: rgba(63,185,80,.4);
            color: var(--success);
        }

        .connector-pill.always {
            border-color: rgba(210,153,34,.4);
            color: var(--warning);
        }

        .connector-pill.parallel {
            border-color: var(--border-primary);
            color: var(--text-muted);
        }

        .connector-popover {
            position: fixed;
            background: var(--bg-secondary);
            border: 1px solid var(--border-primary);
            border-radius: 12px;
            padding: 8px;
            min-width: 300px;
            z-index: 3000;
            box-shadow: 0 8px 32px rgba(0,0,0,.4);
            display: none;
        }

        .connector-popover.open {
            display: block;
        }

        .cpm-title {
            font-size: 11px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: .5px;
            padding: 6px 10px 8px;
        }

        .cpm-option {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            width: 100%;
            padding: 10px 12px;
            border: none;
            background: none;
            cursor: pointer;
            border-radius: 8px;
            text-align: left;
            transition: background .1s;
        }

        .cpm-option:hover {
            background: var(--bg-hover);
        }

        .cpm-option.active {
            background: var(--accent-glow);
        }

        .cpm-icon {
            width: 28px;
            height: 28px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            flex-shrink: 0;
            margin-top: 1px;
        }

        .cpm-icon.success {
            background: rgba(63,185,80,.15);
            color: var(--success);
        }

        .cpm-icon.always {
            background: rgba(210,153,34,.15);
            color: var(--warning);
        }

        .cpm-icon.parallel {
            background: var(--bg-tertiary);
            color: var(--text-muted);
        }

        .cpm-label {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-primary);
        }

        .cpm-desc {
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 2px;
        }

        .step-add-wrap {
            padding: 8px 0 2px;
        }

        .pipeline-add-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 9px 20px;
            border: 1.5px dashed var(--border-primary);
            border-radius: 8px;
            background: none;
            color: var(--text-muted);
            font-size: 13px;
            cursor: pointer;
            transition: all .15s ease;
            width: 100%;
            justify-content: center;
        }

        .pipeline-add-btn:hover {
            border-color: var(--accent-primary);
            color: var(--accent-primary);
            background: var(--accent-glow);
        }

        .dynamic-input-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .dynamic-input-label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            font-weight: 500;
        }

        .dynamic-input-type {
            font-size: 11px;
            color: var(--text-muted);
            background: var(--bg-primary);
            padding: 2px 6px;
            border-radius: 4px;
        }

        .dynamic-input-hint {
            font-size: 12px;
            color: var(--text-muted);
        }

        .runtime-var-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            background: rgba(88,166,255,.1);
            border: 1px solid rgba(88,166,255,.3);
            border-radius: 999px;
            font-size: 11px;
            color: var(--accent-primary);
            font-weight: 600;
        }

        .runtime-var-toggle {
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            font-size: 11px;
            padding: 4px 8px;
            border-radius: 4px;
            border: 1px solid var(--border-primary);
        }

        .runtime-var-toggle:hover {
            color: var(--accent-primary);
            border-color: var(--accent-primary);
        }

        .tpl-trigger {
            width: 100%;
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 11px 14px;
            border: 1.5px dashed var(--border-primary);
            border-radius: 8px;
            background: none;
            color: var(--text-muted);
            font-size: 14px;
            font-family: inherit;
            cursor: pointer;
            transition: all .15s;
            text-align: left;
        }

        .tpl-trigger:hover {
            border-color: var(--accent-primary);
            color: var(--accent-primary);
            background: var(--accent-glow);
        }

        .tpl-trigger .tpl-req {
            margin-left: auto;
            font-size: 11px;
            color: var(--error);
            font-weight: 600;
        }

        .tpl-picker-open {
            border: 1.5px solid var(--accent-primary);
            border-radius: 8px;
            background: var(--bg-secondary);
            overflow: hidden;
        }

        .tpl-search-wrap {
            position: relative;
        }

        .tpl-search-wrap i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 13px;
            pointer-events: none;
        }

        .tpl-search {
            width: 100%;
            padding: 10px 12px 10px 36px;
            border: none;
            border-bottom: 1px solid var(--border-primary);
            background: var(--bg-tertiary);
            color: var(--text-primary);
            font-size: 14px;
            font-family: inherit;
            outline: none;
        }

        .tpl-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
            padding: 12px;
            max-height: 300px;
            overflow: auto;
        }

        .tpl-card {
            text-align: left;
            padding: 12px;
            border-radius: 10px;
            border: 1px solid var(--border-primary);
            background: var(--bg-primary);
            color: var(--text-primary);
            cursor: pointer;
            transition: all .12s ease;
        }

        .tpl-card:hover {
            border-color: var(--accent-primary);
            background: var(--bg-hover);
        }

        .tpl-card-icon {
            font-size: 20px;
            margin-bottom: 8px;
        }

        .tpl-card-name {
            font-size: 13px;
            font-weight: 600;
        }

        .tpl-card-type {
            font-size: 10px;
            color: var(--accent-primary);
            margin-top: 2px;
            opacity: .85;
        }

        .tpl-card-desc {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 6px;
            line-height: 1.5;
        }

        .tpl-no-results {
            grid-column: 1 / -1;
            padding: 18px;
            text-align: center;
            color: var(--text-muted);
        }

        .tpl-picker-footer {
            display: flex;
            justify-content: flex-end;
            padding: 0 12px 12px;
        }

        .tpl-cancel,
        .tpl-change-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 10px;
            border-radius: 8px;
            border: 1px solid var(--border-primary);
            background: var(--bg-tertiary);
            color: var(--text-secondary);
            cursor: pointer;
        }

        .tpl-selected {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            border-radius: 10px;
            background: var(--bg-primary);
            border: 1px solid var(--border-primary);
        }

        .tpl-selected-icon {
            font-size: 20px;
            flex-shrink: 0;
        }

        .tpl-selected-info {
            flex: 1;
            min-width: 0;
        }

        .tpl-selected-name {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-primary);
        }

        .tpl-selected-desc {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 1px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .tpl-type-badge {
            font-size: 10px;
            font-weight: 600;
            padding: 1px 6px;
            border-radius: 999px;
            background: var(--bg-tertiary);
            color: var(--text-secondary);
            margin-left: 6px;
            vertical-align: middle;
        }

        .tpl-selected .tpl-type-badge {
            background: var(--accent-glow);
            color: var(--accent-primary);
        }

        .target-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 10px;
        }

        .target-chip {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 8px 10px;
            border-radius: 10px;
            border: 1px solid var(--border-primary);
            background: var(--bg-primary);
            color: var(--text-secondary);
            cursor: pointer;
            transition: all .12s ease;
        }

        .target-chip:hover {
            border-color: var(--accent-primary);
            background: var(--bg-hover);
        }

        .target-chip.selected {
            border-color: var(--accent-primary);
            background: var(--accent-glow);
        }

        .target-chip i {
            font-size: 12px;
            color: var(--text-muted);
        }

        .target-chip.selected i {
            color: var(--accent-primary);
        }

        .target-chip-name {
            font-weight: 500;
            color: var(--text-primary);
        }

        .target-chip-type {
            font-size: 11px;
            color: var(--text-muted);
            padding-left: 2px;
        }

        .form-inline-label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .5px;
            color: var(--text-muted);
            margin-bottom: 6px;
            display: block;
        }

        .studio-result-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px;
        }

        .studio-result-card {
            padding: 12px;
            border-radius: 12px;
            background: var(--bg-primary);
            border: 1px solid var(--border-primary);
        }

        .studio-result-card strong {
            display: block;
            margin-bottom: 4px;
        }

        .studio-actions-row {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .studio-status-line {
            min-height: 20px;
            color: var(--text-secondary);
            font-size: 13px;
        }

        .studio-side {
            display: flex;
            flex-direction: column;
            gap: 16px;
            padding: 14px;
        }

        .studio-side-card {
            border: 1px solid var(--border-primary);
            border-radius: 14px;
            background: var(--bg-primary);
            padding: 14px;
        }

        .studio-side-title {
            margin: 0 0 10px;
            font-size: 14px;
        }

        .studio-side-copy,
        .studio-side-card p {
            margin: 0;
            color: var(--text-secondary);
            font-size: 13px;
            line-height: 1.6;
        }

        .studio-ai-form {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-top: 10px;
        }

        .studio-ai-shell {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .studio-ai-toolbar {
            display: flex;
            align-items: end;
            gap: 10px;
        }

        .studio-ai-toolbar .studio-field {
            flex: 1;
        }

        .studio-ai-toolbar-actions {
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .studio-ai-messages {
            display: flex;
            flex-direction: column;
            gap: 12px;
            min-height: 280px;
            max-height: 440px;
            overflow: auto;
            padding: 4px;
        }

        .studio-ai-empty {
            padding: 14px;
            border: 1px dashed var(--border-primary);
            border-radius: 14px;
            color: var(--text-secondary);
            font-size: 13px;
            line-height: 1.6;
            background: rgba(255,255,255,0.02);
        }

        .studio-ai-message {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .studio-ai-message-role {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: var(--text-muted);
        }

        .studio-ai-bubble {
            padding: 12px 14px;
            border-radius: 14px;
            border: 1px solid var(--border-primary);
            background: rgba(255,255,255,0.03);
            color: var(--text-primary);
            font-size: 13px;
            line-height: 1.6;
            white-space: pre-wrap;
            word-break: break-word;
        }

        .studio-ai-message.user .studio-ai-bubble {
            background: rgba(88, 166, 255, 0.1);
            border-color: rgba(88, 166, 255, 0.24);
        }

        .studio-ai-title {
            margin: 0 0 6px;
            font-size: 13px;
            font-weight: 700;
            color: var(--text-primary);
        }

        .studio-ai-notes {
            margin: 10px 0 0;
            padding-left: 16px;
            color: var(--text-secondary);
        }

        .studio-ai-notes li + li {
            margin-top: 4px;
        }

        .studio-ai-meta {
            margin-top: 10px;
            color: var(--text-muted);
            font-size: 11px;
        }

        .studio-ai-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 12px;
        }

        .studio-ai-actions .studio-btn {
            min-height: 36px;
        }

        .studio-ai-plan-box {
            display: none;
            border-top: 1px solid var(--border-primary);
            padding: 12px;
            background: rgba(15, 23, 42, 0.28);
        }

        .studio-ai-plan-box.visible {
            display: block;
        }

        .studio-ai-plan-title {
            margin: 0 0 8px 0;
            font-size: 12px;
            font-weight: 700;
            color: var(--text-primary);
        }

        .studio-ai-plan-summary {
            margin: 0 0 10px 0;
            font-size: 12px;
            color: var(--text-secondary);
        }

        .studio-ai-plan-list {
            margin: 0;
            padding: 0;
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .studio-ai-plan-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            padding: 8px 10px;
            border: 1px solid var(--border-primary);
            border-radius: 10px;
            background: rgba(255,255,255,0.03);
            font-size: 12px;
        }

        .studio-ai-plan-badge {
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

        .studio-ai-plan-path {
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            color: var(--text-primary);
        }

        .studio-ai-plan-notes {
            margin: 10px 0 0 0;
            padding-left: 16px;
            color: var(--text-secondary);
            font-size: 12px;
        }

        .studio-ai-plan-notes li + li {
            margin-top: 4px;
        }

        .studio-ai-plan-lint {
            margin-top: 12px;
            padding: 12px;
            border: 1px solid rgba(245, 158, 11, 0.34);
            border-radius: 12px;
            background: rgba(245, 158, 11, 0.08);
        }

        .studio-ai-plan-lint.danger {
            border-color: rgba(239, 68, 68, 0.36);
            background: rgba(239, 68, 68, 0.08);
        }

        .studio-ai-plan-lint-title {
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 0 0 10px 0;
            font-size: 12px;
            font-weight: 700;
            color: #fbbf24;
        }

        .studio-ai-plan-lint.danger .studio-ai-plan-lint-title {
            color: #fca5a5;
        }

        .studio-ai-plan-lint-list {
            margin: 0;
            padding: 0;
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .studio-ai-plan-lint-item {
            padding: 10px;
            border-radius: 10px;
            background: rgba(15, 23, 42, 0.16);
            border: 1px solid rgba(245, 158, 11, 0.18);
        }

        .studio-ai-plan-lint-item.danger {
            border-color: rgba(239, 68, 68, 0.3);
            background: rgba(127, 29, 29, 0.16);
        }

        .studio-ai-plan-lint-item-title {
            font-size: 12px;
            font-weight: 700;
            color: var(--text-primary);
        }

        .studio-ai-plan-lint-item-meta {
            margin-top: 4px;
            font-size: 11px;
            color: var(--text-muted);
            font-family: "SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace;
        }

        .studio-ai-plan-lint-item-message {
            margin-top: 6px;
            font-size: 12px;
            line-height: 1.5;
            color: var(--text-secondary);
        }

        .studio-ai-plan-actions {
            margin-top: 12px;
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .studio-ai-flag {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            background: rgba(88, 166, 255, 0.12);
            color: var(--accent-primary);
        }

        .studio-ai-flag.reverted {
            background: rgba(255, 184, 108, 0.12);
            color: #ffb86c;
        }

        .modal.studio-diff-modal {
            width: min(96vw, 1680px);
        }

        .studio-diff-layout {
            display: grid;
            grid-template-columns: 260px minmax(0, 1fr);
            gap: 16px;
            min-height: 520px;
        }

        .studio-diff-files {
            border: 1px solid var(--border-primary);
            border-radius: 12px;
            background: var(--bg-primary);
            padding: 8px;
            display: flex;
            flex-direction: column;
            gap: 6px;
            overflow: auto;
        }

        .studio-diff-file {
            display: flex;
            flex-direction: column;
            gap: 4px;
            width: 100%;
            padding: 10px 12px;
            border: 1px solid transparent;
            border-radius: 10px;
            background: transparent;
            color: var(--text-primary);
            cursor: pointer;
            text-align: left;
        }

        .studio-diff-file:hover {
            background: rgba(255,255,255,0.03);
            border-color: var(--border-primary);
        }

        .studio-diff-file.active {
            background: rgba(37, 99, 235, 0.1);
            border-color: rgba(37, 99, 235, 0.35);
        }

        .studio-diff-file-path {
            font-size: 12px;
            font-weight: 600;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .studio-diff-file-meta {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--text-muted);
            font-size: 11px;
        }

        .studio-diff-view {
            border: 1px solid var(--border-primary);
            border-radius: 14px;
            background: #0b1120;
            overflow: hidden;
            min-width: 0;
            min-height: 520px;
            display: flex;
            flex-direction: column;
        }

        .studio-diff-meta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 12px 14px;
            border-bottom: 1px solid #1e293b;
            background: rgba(15, 23, 42, 0.94);
        }

        .studio-diff-meta-main {
            min-width: 0;
        }

        .studio-diff-meta-path {
            font-size: 13px;
            font-weight: 700;
            color: #e2e8f0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .studio-diff-meta-copy {
            margin-top: 4px;
            font-size: 11px;
            color: #94a3b8;
        }

        .studio-diff-toolbar {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .studio-diff-meta-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 9px;
            border-radius: 999px;
            background: rgba(37, 99, 235, 0.18);
            color: #93c5fd;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            flex-shrink: 0;
        }

        .studio-diff-mode-toggle {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px;
            border: 1px solid #334155;
            border-radius: 999px;
            background: rgba(15, 23, 42, 0.72);
        }

        .studio-diff-mode-btn {
            border: 0;
            border-radius: 999px;
            background: transparent;
            color: #94a3b8;
            padding: 6px 10px;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
        }

        .studio-diff-mode-btn.active {
            background: rgba(37, 99, 235, 0.22);
            color: #dbeafe;
        }

        .studio-diff-content {
            flex: 1;
            min-height: 0;
            overflow: auto;
            font: 12px/1.6 "SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace;
            color: #e2e8f0;
        }

        .studio-diff-content-inner {
            min-width: 0;
            width: 100%;
        }

        .studio-diff-empty {
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            color: #94a3b8;
            text-align: center;
        }

        .studio-diff-grid {
            --studio-diff-left-ratio: 0.5;
            min-width: 0;
            width: 100%;
        }

        .studio-diff-header,
        .studio-diff-row {
            display: grid;
            grid-template-columns:
                minmax(0, calc((100% - 12px) * var(--studio-diff-left-ratio)))
                12px
                minmax(0, calc((100% - 12px) * (1 - var(--studio-diff-left-ratio))));
        }

        .studio-diff-header {
            position: sticky;
            top: 0;
            z-index: 1;
            background: rgba(15, 23, 42, 0.98);
            border-bottom: 1px solid #1e293b;
        }

        .studio-diff-header-cell {
            padding: 8px 12px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #94a3b8;
        }

        .studio-diff-row:hover .studio-diff-cell {
            background-color: rgba(255,255,255,0.035);
        }

        .studio-diff-divider-spacer {
            position: relative;
            min-width: 12px;
            background: rgba(15, 23, 42, 0.96);
            border-left: 1px solid #1e293b;
            border-right: 1px solid #1e293b;
        }

        .studio-diff-divider-spacer::before {
            content: "";
            position: absolute;
            top: 0;
            bottom: 0;
            left: 50%;
            width: 1px;
            transform: translateX(-50%);
            background: rgba(148, 163, 184, 0.26);
        }

        .studio-diff-cell {
            display: grid;
            grid-template-columns: 58px 22px minmax(0, 1fr);
            min-width: 0;
            border-bottom: 1px solid rgba(30, 41, 59, 0.72);
        }

        .studio-diff-cell.right {
        }

        .studio-diff-cell.empty {
            background: rgba(15, 23, 42, 0.35);
        }

        .studio-diff-cell.context {
            background: rgba(15, 23, 42, 0.22);
        }

        .studio-diff-cell.delete {
            background: rgba(239, 68, 68, 0.14);
        }

        .studio-diff-cell.add {
            background: rgba(34, 197, 94, 0.14);
        }

        .studio-diff-cell-num,
        .studio-diff-cell-mark {
            user-select: none;
            color: #64748b;
        }

        .studio-diff-cell-num,
        .studio-diff-cell-mark,
        .studio-diff-cell-text {
            padding: 0 10px;
            white-space: pre-wrap;
        }

        .studio-diff-cell-text {
            min-width: 0;
            overflow: hidden;
            text-overflow: clip;
            overflow-wrap: anywhere;
            word-break: break-word;
        }

        .studio-diff-cell.delete .studio-diff-cell-num,
        .studio-diff-cell.delete .studio-diff-cell-mark {
            color: #fca5a5;
        }

        .studio-diff-cell.add .studio-diff-cell-num,
        .studio-diff-cell.add .studio-diff-cell-mark {
            color: #86efac;
        }

        .studio-diff-gap {
            display: grid;
            grid-template-columns:
                minmax(0, calc((100% - 12px) * var(--studio-diff-left-ratio)))
                12px
                minmax(0, calc((100% - 12px) * (1 - var(--studio-diff-left-ratio))));
            color: #64748b;
            background: rgba(15, 23, 42, 0.42);
            border-top: 1px dashed rgba(100, 116, 139, 0.32);
            border-bottom: 1px dashed rgba(100, 116, 139, 0.32);
            font-style: italic;
        }

        .studio-diff-gap-cell {
            padding: 4px 12px;
        }

        .studio-diff-gap-divider {
            position: relative;
            border-left: 1px solid #1e293b;
            border-right: 1px solid #1e293b;
        }

        .studio-diff-gap-cell.right {
        }

        .studio-smart-diff {
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .studio-smart-diff-summary {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .studio-smart-diff-summary-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            border-radius: 999px;
            background: rgba(148, 163, 184, 0.16);
            color: #cbd5e1;
            font-size: 11px;
            font-weight: 700;
        }

        .studio-smart-diff-summary-badge.added {
            background: rgba(34, 197, 94, 0.18);
            color: #bbf7d0;
        }

        .studio-smart-diff-summary-badge.removed {
            background: rgba(239, 68, 68, 0.18);
            color: #fecaca;
        }

        .studio-smart-diff-summary-badge.changed,
        .studio-smart-diff-summary-badge.moved {
            background: rgba(59, 130, 246, 0.18);
            color: #bfdbfe;
        }

        .studio-smart-diff-section {
            border: 1px solid #1e293b;
            border-radius: 14px;
            background: rgba(15, 23, 42, 0.48);
            overflow: hidden;
        }

        .studio-smart-diff-section-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 12px 14px;
            border-bottom: 1px solid #1e293b;
            background: rgba(15, 23, 42, 0.82);
        }

        .studio-smart-diff-section-title {
            font-size: 13px;
            font-weight: 700;
            color: #e2e8f0;
        }

        .studio-smart-diff-section-count {
            font-size: 11px;
            color: #94a3b8;
        }

        .studio-smart-diff-items {
            display: flex;
            flex-direction: column;
        }

        .studio-smart-diff-item {
            padding: 14px;
            border-top: 1px solid rgba(30, 41, 59, 0.72);
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .studio-smart-diff-item:first-child {
            border-top: 0;
        }

        .studio-smart-diff-item-head {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .studio-smart-diff-item-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 8px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }

        .studio-smart-diff-item-badge.added {
            background: rgba(34, 197, 94, 0.18);
            color: #bbf7d0;
        }

        .studio-smart-diff-item-badge.removed {
            background: rgba(239, 68, 68, 0.18);
            color: #fecaca;
        }

        .studio-smart-diff-item-badge.changed {
            background: rgba(59, 130, 246, 0.18);
            color: #bfdbfe;
        }

        .studio-smart-diff-item-badge.moved {
            background: rgba(168, 85, 247, 0.18);
            color: #ddd6fe;
        }

        .studio-smart-diff-item-title {
            font-size: 13px;
            font-weight: 700;
            color: #f8fafc;
        }

        .studio-smart-diff-item-copy {
            font-size: 12px;
            color: #94a3b8;
        }

        .studio-smart-diff-item-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
            gap: 12px;
        }

        .studio-smart-diff-panel {
            min-width: 0;
            border: 1px solid rgba(51, 65, 85, 0.92);
            border-radius: 12px;
            background: rgba(2, 6, 23, 0.62);
            overflow: hidden;
        }

        .studio-smart-diff-panel-label {
            padding: 8px 10px;
            border-bottom: 1px solid rgba(51, 65, 85, 0.92);
            font-size: 11px;
            font-weight: 700;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .studio-smart-diff-code {
            margin: 0;
            padding: 10px 12px;
            color: #e2e8f0;
            white-space: pre-wrap;
            word-break: break-word;
        }

        .studio-smart-diff-placeholder {
            padding: 12px;
            color: #64748b;
            font-style: italic;
        }

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

        .modal {
            width: min(700px, 100%);
            max-height: 90vh;
            overflow-y: auto;
            background: var(--bg-secondary);
            border: 1px solid var(--border-primary);
            border-radius: 16px;
            box-shadow: 0 24px 60px rgba(15, 23, 42, 0.32);
        }

        .modal-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border-primary);
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

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            padding: 16px 20px;
            border-top: 1px solid var(--border-primary);
        }

        .studio-ai-composer {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .studio-ai-composer .studio-textarea {
            min-height: 108px;
            font-family: inherit;
        }

        .studio-ai-composer-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            flex-wrap: wrap;
        }

        .studio-ai-composer-actions {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-left: auto;
        }

        .studio-ai-response {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-top: 12px;
        }

        .studio-ai-reply {
            padding: 12px;
            border-radius: 12px;
            background: rgba(88, 166, 255, 0.08);
            color: var(--text-primary);
            font-size: 13px;
            line-height: 1.6;
        }

        .studio-validation-list,
        .studio-version-list,
        .studio-runtime-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-top: 10px;
        }

        .studio-validation-item,
        .studio-version-item,
        .studio-runtime-item {
            padding: 10px 12px;
            border-radius: 12px;
            background: rgba(255,255,255,0.03);
            font-size: 13px;
            line-height: 1.55;
        }

        .studio-validation-item.error {
            border: 1px solid rgba(248, 81, 73, 0.35);
            color: #ffb4ad;
            background: rgba(248, 81, 73, 0.08);
        }

        .studio-validation-item.warning {
            border: 1px solid rgba(210, 153, 34, 0.32);
            color: #f2cc60;
            background: rgba(210, 153, 34, 0.08);
        }

        .studio-version-meta {
            color: var(--text-secondary);
            font-size: 12px;
            margin-top: 4px;
        }

        .studio-empty-block {
            padding: 12px;
            border: 1px dashed var(--border-primary);
            border-radius: 12px;
            color: var(--text-secondary);
            font-size: 13px;
            line-height: 1.6;
        }

        .studio-output {
            margin-top: 10px;
            padding: 12px;
            border-radius: 12px;
            border: 1px solid var(--border-primary);
            background: var(--bg-primary);
            color: var(--text-primary);
            font-size: 12px;
            line-height: 1.6;
            white-space: pre-wrap;
            word-break: break-word;
            max-height: 280px;
            overflow: auto;
        }

        .studio-link-inline {
            color: var(--accent-primary);
            text-decoration: none;
            font-weight: 600;
        }

        .studio-link-inline:hover {
            text-decoration: underline;
        }

        @media (max-width: 1220px) {
            .studio-workbench {
                grid-template-columns: 260px minmax(0, 1fr);
            }

            .studio-panel.side-panel {
                grid-column: 1 / -1;
            }

            .studio-side {
                display: grid;
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }

            .studio-side-card.studio-ai-shell {
                grid-column: 1 / -1;
            }
        }

        @media (max-width: 940px) {
            .studio-stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .studio-diff-layout {
                grid-template-columns: 1fr;
            }

            .studio-diff-files {
                max-height: 180px;
            }

            .studio-workbench {
                grid-template-columns: 1fr;
            }

            .studio-side {
                grid-template-columns: 1fr;
            }

            .studio-topbar {
                align-items: stretch;
            }

            .studio-topbar-actions {
                width: 100%;
            }

            .studio-icon-link {
                flex: 1 1 calc(50% - 8px);
                justify-content: center;
            }

            .studio-ai-toolbar {
                flex-direction: column;
                align-items: stretch;
            }

            .studio-ai-composer-row {
                align-items: stretch;
            }

            .studio-ai-composer-actions {
                width: 100%;
                margin-left: 0;
            }

            .studio-ai-composer-actions .studio-btn {
                flex: 1;
            }

            .studio-builder-grid,
            .tpl-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 720px) {
            .studio-fields {
                grid-template-columns: 1fr;
            }

            .studio-stats {
                grid-template-columns: 1fr;
            }

            .studio-preview-grid,
            .studio-result-grid,
            .studio-use-inputs {
                grid-template-columns: 1fr;
            }

            .step-options-row {
                flex-direction: column;
            }

            .studio-title {
                font-size: 26px;
            }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <?php $layout->renderSidebar(); ?>
        <main class="main-content">
            <div class="content-area studio-shell">
                <section class="studio-topbar">
                    <div class="studio-topbar-main">
                        <div class="studio-kicker">
                            <i class="fas fa-diagram-project"></i>
                            Studio
                        </div>
                        <h1 class="studio-title">Workflows Studio</h1>
                    </div>
                    <div class="studio-topbar-actions">
                        <a class="studio-icon-link" href="../commands.php"><i class="fas fa-terminal"></i>Commands</a>
                        <a class="studio-icon-link" href="../templates.php"><i class="fas fa-cubes"></i>Templates</a>
                        <a class="studio-icon-link" href="sources.php"><i class="fas fa-code-branch"></i>Sources</a>
                        <a class="studio-icon-link" href="ai.php"><i class="fas fa-robot"></i>Context</a>
                    </div>
                </section>

                <section class="studio-workbench">
                    <aside class="studio-panel">
                        <div class="studio-panel-head">
                            <div>
                                <h2 class="studio-panel-title">Projects</h2>
                            </div>
                            <button class="studio-btn primary" type="button" id="newProjectBtn">
                                <i class="fas fa-plus"></i>
                                New workflow
                            </button>
                        </div>
                        <div class="studio-list">
                            <div class="studio-tabs" role="tablist" aria-label="Studio asset type">
                                <button class="studio-tab active" type="button" data-type-tab="workflow">Workflows <span class="studio-tab-count" data-type-count="workflow"><?= (int)count($workflowProjects) ?></span></button>
                                <button class="studio-tab" type="button" data-type-tab="template">Templates <span class="studio-tab-count" data-type-count="template"><?= (int)count($templateProjects) ?></span></button>
                            </div>
                            <div id="projectList" aria-live="polite"></div>
                        </div>
                    </aside>

                    <section class="studio-panel studio-editor">
                        <div class="studio-panel-head">
                            <div>
                                <h2 class="studio-panel-title" id="editorTitle">New workflow</h2>
                                <p class="studio-panel-copy" id="editorCopy"></p>
                            </div>
                            <div class="studio-editor-toggle">
                                <div class="studio-mode-toggle" role="radiogroup" aria-label="Studio mode">
                                    <button class="studio-mode-option active" type="button" data-editor-mode-btn="use">
                                        Preview
                                    </button>
                                    <button class="studio-mode-option" type="button" data-editor-mode-btn="edit">
                                        Edit
                                    </button>
                                </div>
                                <span class="studio-badge draft" id="editorStatusBadge">Unpublished</span>
                            </div>
                        </div>
                        <div class="studio-editor-body">
                            <section class="studio-mode-panel" id="usePanel">
                                <div class="studio-use-shell" id="useSummary"></div>
                                <section class="studio-inline-card">
                                    <h3 class="studio-side-title">Run</h3>
                                    <div class="studio-use-inputs" id="useInputs"></div>
                                    <div class="studio-actions-row">
                                        <button class="studio-btn primary" type="button" id="useRunBtn"><i class="fas fa-play"></i>Run</button>
                                        <button class="studio-btn secondary" type="button" id="useDryRunBtn"><i class="fas fa-vial-circle-check"></i>Dry run</button>
                                    </div>
                                    <div class="studio-actions-row">
                                        <button class="studio-btn primary" type="button" id="saveBtn"><i class="fas fa-floppy-disk"></i>Save</button>
                                        <button class="studio-btn secondary" type="button" id="validateBtn"><i class="fas fa-check-double"></i>Validate</button>
                                        <button class="studio-btn secondary" type="button" id="publishBtn"><i class="fas fa-upload"></i>Publish</button>
                                        <button class="studio-btn danger" type="button" id="archiveBtn"><i class="fas fa-box-archive"></i>Archive</button>
                                    </div>
                                    <div class="studio-status-line" id="useStatusLine"></div>
                                </section>
                                <section class="studio-inline-card" id="dryRunPanel">
                                    <h3 class="studio-side-title">Last result</h3>
                                    <div id="dryRunResult" class="studio-runtime-list"></div>
                                </section>
                            </section>

                            <section class="studio-mode-panel" id="editPanel" hidden>
                                <div class="studio-fields">
                                    <label class="studio-field">
                                        <span class="studio-label">Name</span>
                                        <input class="studio-input" id="projectName" placeholder="Deploy API">
                                    </label>
                                    <label class="studio-field">
                                        <span class="studio-label" id="runtimeIdLabel">Command ID</span>
                                        <input class="studio-input" id="runtimeId" placeholder="deploy-api">
                                    </label>
                                    <label class="studio-field full">
                                        <span class="studio-label">Description</span>
                                        <textarea class="studio-textarea" id="projectDescription" placeholder="What this workflow or template is for."></textarea>
                                    </label>
                                    <label class="studio-field full" id="sourceTypeField" hidden>
                                        <span class="studio-label">Source mode</span>
                                        <select class="studio-select" id="sourceType">
                                            <option value="local">Local</option>
                                            <option value="repository">Repository-linked</option>
                                        </select>
                                        <span class="studio-helper">Repository-linked projects are coming next.</span>
                                    </label>
                                </div>
                                <section class="studio-inline-card studio-builder-card" id="workflowBuilderCard">
                                    <div class="studio-builder-grid">
                                        <div class="studio-builder-section">
                                            <span class="studio-label">Execution mode</span>
                                            <div class="toggle-opts studio-toggle-opts">
                                                <button class="toggle-opt" type="button" id="workflowModeSyncBtn">
                                                    <i class="fas fa-bolt"></i>
                                                    Sync
                                                </button>
                                                <button class="toggle-opt" type="button" id="workflowModeAsyncBtn">
                                                    <i class="fas fa-clock"></i>
                                                    Async
                                                </button>
                                            </div>
                                        </div>
                                        <div class="studio-builder-section">
                                            <span class="studio-label">Final status policy</span>
                                            <div class="toggle-opts studio-toggle-opts">
                                                <button class="toggle-opt" type="button" id="workflowPolicyFailBtn">
                                                    <i class="fas fa-circle-xmark"></i>
                                                    Fail if any step fails
                                                </button>
                                                <button class="toggle-opt" type="button" id="workflowPolicyWarnBtn">
                                                    <i class="fas fa-triangle-exclamation"></i>
                                                    Complete with warnings
                                                </button>
                                            </div>
                                        </div>
                                        <div class="studio-builder-section">
                                            <span class="studio-label">Max parallel</span>
                                            <div class="studio-stepper">
                                                <button class="studio-stepper-btn" type="button" id="workflowMaxParallelDownBtn" aria-label="Decrease max parallel">
                                                    <i class="fas fa-minus"></i>
                                                </button>
                                                <span class="studio-stepper-value" id="workflowMaxParallelValue">1</span>
                                                <button class="studio-stepper-btn" type="button" id="workflowMaxParallelUpBtn" aria-label="Increase max parallel">
                                                    <i class="fas fa-plus"></i>
                                                </button>
                                            </div>
                                        </div>
                                        <label class="studio-builder-section">
                                            <span class="studio-label">Environment</span>
                                            <select class="studio-select" id="workflowEnvironmentSelect">
                                                <option value="">None</option>
                                            </select>
                                        </label>
                                    </div>
                                    <div class="studio-builder-section">
                                        <span class="studio-label">Default target</span>
                                        <div id="workflowDefaultTargetEditor"></div>
                                    </div>
                                    <div class="studio-builder-section">
                                        <div class="studio-builder-title-row">
                                            <div>
                                            <span class="studio-label">Steps</span>
                                                <span class="studio-helper">Use the same builder pattern as Commands to shape this workflow.</span>
                                            </div>
                                        </div>
                                        <div id="workflowStepsEditor"></div>
                                    </div>
                                </section>
                                <div class="studio-helper" id="templateEditHint" hidden>Templates still use raw JSON here for now.</div>
                                <details class="studio-json-details" id="rawJsonDetails">
                                    <summary>Raw JSON</summary>
                                    <div class="studio-json-details-body">
                                        <label class="studio-field full">
                                            <span class="studio-label">Project JSON</span>
                                            <textarea class="studio-textarea json" id="draftJson" spellcheck="false"></textarea>
                                            <span class="studio-helper" id="draftHelper">Raw project JSON.</span>
                                        </label>
                                        <div class="studio-actions-row">
                                            <button class="studio-btn secondary" type="button" id="applyRawJsonBtn">
                                                <i class="fas fa-wand-magic-sparkles"></i>
                                                Apply JSON to editor
                                            </button>
                                        </div>
                                    </div>
                                </details>
                            </section>
                            <div class="studio-status-line" id="editorStatusLine"></div>
                        </div>
                    </section>

                    <aside class="studio-panel side-panel">
                        <div class="studio-panel-head">
                            <div>
                                <h2 class="studio-panel-title">Assistant</h2>
                            </div>
                        </div>
                        <div class="studio-side">
                            <section class="studio-side-card studio-ai-shell">
                                <h3 class="studio-side-title">AI</h3>
                                <?php if ($studioAiEnabled): ?>
                                    <div class="studio-ai-toolbar">
                                        <label class="studio-field">
                                            <span class="studio-label">Provider</span>
                                            <select class="studio-select" id="aiProviderSelect">
                                                <?php foreach ($aiProviders as $provider): ?>
                                                    <option value="<?= htmlspecialchars((string)($provider['id'] ?? '')) ?>">
                                                        <?= htmlspecialchars((string)($provider['name'] ?? $provider['id'] ?? 'AI Provider')) ?>
                                                        <?php if (!empty($provider['isDefault'])): ?> (default)<?php endif; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <div class="studio-ai-toolbar-actions">
                                            <a class="studio-link-inline" href="ai.php">Context</a>
                                        </div>
                                    </div>
                                    <div id="aiMessages" class="studio-ai-messages" aria-live="polite"></div>
                                    <div class="studio-ai-plan-box" id="aiPlanBox">
                                        <h3 class="studio-ai-plan-title">Pending Changes</h3>
                                        <p class="studio-ai-plan-summary" id="aiPlanSummary">No proposed changes yet.</p>
                                        <ul class="studio-ai-plan-list" id="aiPlanList"></ul>
                                        <ul class="studio-ai-plan-notes" id="aiPlanNotes"></ul>
                                        <div class="studio-ai-plan-lint" id="aiPlanLintBox" style="display:none;">
                                            <div class="studio-ai-plan-lint-title">
                                                <i class="fas fa-triangle-exclamation"></i>
                                                <span>AI Review Warnings</span>
                                            </div>
                                            <ul class="studio-ai-plan-lint-list" id="aiPlanLintList"></ul>
                                        </div>
                                        <div class="studio-ai-plan-actions">
                                            <button class="studio-btn secondary" type="button" id="aiViewDiffBtn">
                                                <i class="fas fa-code-compare"></i>
                                                View Diff
                                            </button>
                                            <button class="studio-btn secondary" type="button" id="aiSmartDiffBtn">
                                                <i class="fas fa-diagram-project"></i>
                                                Smart Diff
                                            </button>
                                            <button class="studio-btn primary" type="button" id="aiApplyBtn">
                                                <i class="fas fa-check"></i>
                                                Apply
                                            </button>
                                            <button class="studio-btn secondary" type="button" id="aiClearPlanBtn">
                                                <i class="fas fa-xmark"></i>
                                                Clear
                                            </button>
                                        </div>
                                    </div>
                                    <form class="studio-ai-composer" id="aiChatForm">
                                        <textarea class="studio-textarea" id="aiPrompt" placeholder="Ask AI to create or edit this workflow. Example: replace the summary with a cleaner output or add one more step."></textarea>
                                        <div class="studio-ai-composer-row">
                                            <span class="studio-helper">AI proposes workflow changes here. Review them before applying to the editor.</span>
                                            <div class="studio-ai-composer-actions">
                                                <button class="studio-btn secondary" type="button" id="clearAiChatBtn">Clear</button>
                                                <button class="studio-btn primary" type="submit" id="generateAiBtn" <?= $aiProviders === [] ? 'disabled' : '' ?>>
                                                    <i class="fas fa-paper-plane"></i>
                                                    Ask AI
                                                </button>
                                            </div>
                                        </div>
                                    </form>
                                <?php else: ?>
                                    <div class="studio-ai-empty">
                                        Studio AI is disabled for this account. Enable the AI feature in Doki Admin to use chat here.
                                    </div>
                                <?php endif; ?>
                            </section>
                        </div>
                    </aside>
                </section>

                <div class="connector-popover" id="workflowConnectorPopover">
                    <div class="cpm-title">Connection</div>
                    <button class="cpm-option" type="button" data-mode="success">
                        <div class="cpm-icon success"><i class="fas fa-check"></i></div>
                        <div>
                            <div class="cpm-label">After success</div>
                            <div class="cpm-desc">Run the next step only if this step succeeds.</div>
                        </div>
                    </button>
                    <button class="cpm-option" type="button" data-mode="always">
                        <div class="cpm-icon always"><i class="fas fa-bolt"></i></div>
                        <div>
                            <div class="cpm-label">Always</div>
                            <div class="cpm-desc">Continue to the next step even if this one fails.</div>
                        </div>
                    </button>
                    <button class="cpm-option" type="button" data-mode="parallel">
                        <div class="cpm-icon parallel"><i class="fas fa-code-branch"></i></div>
                        <div>
                            <div class="cpm-label">Parallel</div>
                            <div class="cpm-desc">Do not depend on the previous step. It can run in parallel.</div>
                        </div>
                    </button>
                </div>

                <div class="modal-backdrop" id="aiDiffModal">
                    <div class="modal studio-diff-modal">
                        <div class="modal-header">
                            <h2><i class="fas fa-code-compare"></i> Proposed AI Changes</h2>
                            <button class="modal-close" type="button" id="closeAiDiffModalBtn">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        <div class="modal-body">
                            <div class="studio-diff-layout">
                                <div class="studio-diff-files" id="aiDiffFiles">
                                    <div class="studio-diff-empty">No pending changes.</div>
                                </div>
                                <div class="studio-diff-view">
                                    <div class="studio-diff-meta" id="aiDiffMeta">
                                        <div class="studio-diff-meta-main">
                                            <div class="studio-diff-meta-path">No file selected</div>
                                            <div class="studio-diff-meta-copy">Select a changed file to inspect the proposed diff.</div>
                                        </div>
                                    </div>
                                    <div class="studio-diff-content" id="aiDiffContent">
                                        <div class="studio-diff-empty">No pending changes.</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button class="studio-btn secondary" type="button" id="closeAiDiffFooterBtn">Close</button>
                            <button class="studio-btn primary" type="button" id="aiDiffApplyBtn">Apply Changes</button>
                        </div>
                    </div>
                </div>

            </div>
        </main>
    </div>

    <script>
        const initialStudioState = <?= $initialStudioStateJson ?: '{}' ?>;
        const studioState = {
            type: 'workflow',
            selectedProjectId: '',
            editorMode: 'use',
            currentValidation: null,
            isDirty: false,
            lastAiResult: null,
            aiMessages: [],
            aiActionBusy: false,
            pendingAiDraftMessageId: '',
            aiDiffMessageId: '',
            aiDiffMode: 'raw',
            lastDryRunResult: null,
            useInputs: {},
            activeJobId: null,
            activeJobIsDryRun: false,
            pollTimer: null,
            pendingWorkflowResume: null,
            data: {
                workflow: Array.isArray(initialStudioState.workflowProjects) ? initialStudioState.workflowProjects : [],
                template: Array.isArray(initialStudioState.templateProjects) ? initialStudioState.templateProjects : [],
            },
            defaults: {
                workflow: initialStudioState.workflowDefaults || {},
                template: initialStudioState.templateDefaults || {},
            },
            stats: initialStudioState.stats || {},
            aiEnabled: !!initialStudioState.aiEnabled,
            aiProviders: Array.isArray(initialStudioState.aiProviders) ? initialStudioState.aiProviders : [],
            runtimeTemplates: Array.isArray(initialStudioState.runtimeTemplates) ? initialStudioState.runtimeTemplates : [],
            targets: Array.isArray(initialStudioState.targets) ? initialStudioState.targets : [],
            environments: initialStudioState.environments && typeof initialStudioState.environments === 'object' ? initialStudioState.environments : {},
        };

        let templateById = {};
        const targetById = Object.fromEntries(studioState.targets.map((target) => [String(target.id || ''), target]).filter(([id]) => id));

        rebuildRuntimeTemplateLookup();

        const projectListEl = document.getElementById('projectList');
        const newProjectBtn = document.getElementById('newProjectBtn');
        const editorTitleEl = document.getElementById('editorTitle');
        const editorCopyEl = document.getElementById('editorCopy');
        const editorStatusBadgeEl = document.getElementById('editorStatusBadge');
        const editorStatusLineEl = document.getElementById('editorStatusLine');
        const usePanelEl = document.getElementById('usePanel');
        const editPanelEl = document.getElementById('editPanel');
        const useSummaryEl = document.getElementById('useSummary');
        const useInputsEl = document.getElementById('useInputs');
        const useRunBtn = document.getElementById('useRunBtn');
        const useDryRunBtn = document.getElementById('useDryRunBtn');
        const useStatusLineEl = document.getElementById('useStatusLine');
        const projectNameEl = document.getElementById('projectName');
        const runtimeIdEl = document.getElementById('runtimeId');
        const runtimeIdLabelEl = document.getElementById('runtimeIdLabel');
        const projectDescriptionEl = document.getElementById('projectDescription');
        const draftJsonEl = document.getElementById('draftJson');
        const applyRawJsonBtn = document.getElementById('applyRawJsonBtn');
        const dryRunPanelEl = document.getElementById('dryRunPanel');
        const dryRunResultEl = document.getElementById('dryRunResult');
        const publishBtn = document.getElementById('publishBtn');
        const archiveBtn = document.getElementById('archiveBtn');
        const sourceTypeFieldEl = document.getElementById('sourceTypeField');
        const sourceTypeEl = document.getElementById('sourceType');
        const draftHelperEl = document.getElementById('draftHelper');
        const workflowBuilderCardEl = document.getElementById('workflowBuilderCard');
        const workflowStepsEditorEl = document.getElementById('workflowStepsEditor');
        const workflowDefaultTargetEditorEl = document.getElementById('workflowDefaultTargetEditor');
        const templateEditHintEl = document.getElementById('templateEditHint');
        const workflowModeSyncBtn = document.getElementById('workflowModeSyncBtn');
        const workflowModeAsyncBtn = document.getElementById('workflowModeAsyncBtn');
        const workflowPolicyFailBtn = document.getElementById('workflowPolicyFailBtn');
        const workflowPolicyWarnBtn = document.getElementById('workflowPolicyWarnBtn');
        const workflowMaxParallelDownBtn = document.getElementById('workflowMaxParallelDownBtn');
        const workflowMaxParallelUpBtn = document.getElementById('workflowMaxParallelUpBtn');
        const workflowMaxParallelValueEl = document.getElementById('workflowMaxParallelValue');
        const workflowEnvironmentSelectEl = document.getElementById('workflowEnvironmentSelect');
        const workflowConnectorPopoverEl = document.getElementById('workflowConnectorPopover');
        const aiProviderSelectEl = document.getElementById('aiProviderSelect');
        const aiChatFormEl = document.getElementById('aiChatForm');
        const aiMessagesEl = document.getElementById('aiMessages');
        const aiPlanBoxEl = document.getElementById('aiPlanBox');
        const aiPlanSummaryEl = document.getElementById('aiPlanSummary');
        const aiPlanListEl = document.getElementById('aiPlanList');
        const aiPlanNotesEl = document.getElementById('aiPlanNotes');
        const aiPlanLintBoxEl = document.getElementById('aiPlanLintBox');
        const aiPlanLintListEl = document.getElementById('aiPlanLintList');
        const aiViewDiffBtn = document.getElementById('aiViewDiffBtn');
        const aiSmartDiffBtn = document.getElementById('aiSmartDiffBtn');
        const aiApplyBtn = document.getElementById('aiApplyBtn');
        const aiClearPlanBtn = document.getElementById('aiClearPlanBtn');
        const aiPromptEl = document.getElementById('aiPrompt');
        const clearAiChatBtn = document.getElementById('clearAiChatBtn');
        const generateAiBtn = document.getElementById('generateAiBtn');
        const aiDiffModalEl = document.getElementById('aiDiffModal');
        const aiDiffFilesEl = document.getElementById('aiDiffFiles');
        const aiDiffMetaEl = document.getElementById('aiDiffMeta');
        const aiDiffContentEl = document.getElementById('aiDiffContent');
        const closeAiDiffModalBtn = document.getElementById('closeAiDiffModalBtn');
        const closeAiDiffFooterBtn = document.getElementById('closeAiDiffFooterBtn');
        const aiDiffApplyBtn = document.getElementById('aiDiffApplyBtn');

        let workflowBuilderSteps = [];
        let workflowBuilderDefaultTarget = { mode: 'inherit', targetId: '', fromEnvironment: '', envId: '' };
        let workflowBuilderExecutionMode = 'sync';
        let workflowBuilderPolicy = 'fail_if_any_failed';
        let workflowBuilderMaxParallel = 1;
        let workflowBuilderEnvironment = '';
        let workflowBuilderDirtySync = false;
        let workflowConnectorStepIndex = -1;

        document.querySelectorAll('[data-type-tab]').forEach((button) => {
            button.addEventListener('click', () => switchType(button.dataset.typeTab || 'workflow'));
        });

        newProjectBtn.addEventListener('click', () => {
            resetAiConversation(false);
            loadDraft(null);
        });
        document.getElementById('saveBtn').addEventListener('click', saveCurrentProject);
        document.getElementById('validateBtn').addEventListener('click', validateCurrentProject);
        publishBtn.addEventListener('click', publishCurrentProject);
        archiveBtn.addEventListener('click', archiveCurrentProject);
        aiChatFormEl?.addEventListener('submit', (event) => {
            event.preventDefault();
            generateAiDraft();
        });
        clearAiChatBtn?.addEventListener('click', resetAiConversation);
        useRunBtn.addEventListener('click', () => executeCurrentProject(false));
        useDryRunBtn.addEventListener('click', () => executeCurrentProject(true));
        document.querySelectorAll('[data-editor-mode-btn]').forEach((button) => {
            button.addEventListener('click', () => switchEditorMode(button.getAttribute('data-editor-mode-btn') || 'use'));
        });
        [projectNameEl, runtimeIdEl, projectDescriptionEl, draftJsonEl, sourceTypeEl].forEach((field) => {
            field?.addEventListener('input', markDirty);
            field?.addEventListener('change', markDirty);
        });
        [projectNameEl, runtimeIdEl, projectDescriptionEl].forEach((field) => {
            field?.addEventListener('input', () => {
                if (isWorkflowBuilderEnabled()) {
                    syncWorkflowDraftJsonFromBuilder(false);
                }
            });
            field?.addEventListener('change', () => {
                if (isWorkflowBuilderEnabled()) {
                    syncWorkflowDraftJsonFromBuilder(false);
                }
            });
        });
        applyRawJsonBtn?.addEventListener('click', applyRawJsonToEditor);
        workflowModeSyncBtn?.addEventListener('click', () => setWorkflowExecutionMode('sync'));
        workflowModeAsyncBtn?.addEventListener('click', () => setWorkflowExecutionMode('async'));
        workflowPolicyFailBtn?.addEventListener('click', () => setWorkflowPolicy('fail_if_any_failed'));
        workflowPolicyWarnBtn?.addEventListener('click', () => setWorkflowPolicy('completed_with_warnings'));
        workflowMaxParallelDownBtn?.addEventListener('click', () => adjustWorkflowMaxParallel(-1));
        workflowMaxParallelUpBtn?.addEventListener('click', () => adjustWorkflowMaxParallel(1));
        workflowEnvironmentSelectEl?.addEventListener('change', () => {
            workflowBuilderEnvironment = String(workflowEnvironmentSelectEl.value || '').trim();
            if (workflowBuilderDefaultTarget.mode === 'fromEnvironment') {
                workflowBuilderDefaultTarget.envId = workflowBuilderEnvironment;
            }
            workflowBuilderSteps.forEach((step) => {
                if (step.target.mode === 'fromEnvironment') {
                    step.target.envId = workflowBuilderEnvironment;
                }
            });
            syncWorkflowDraftJsonFromBuilder(true);
            renderWorkflowEditBuilder();
        });
        document.addEventListener('click', handleWorkflowBuilderGlobalClick);
        workflowConnectorPopoverEl?.addEventListener('click', (event) => {
            const button = event.target.closest('[data-mode]');
            if (!button) {
                return;
            }
            event.stopPropagation();
            if (workflowConnectorStepIndex >= 0) {
                setWorkflowConnectorMode(workflowConnectorStepIndex, button.dataset.mode || 'success');
            }
            closeWorkflowConnectorPopover();
        });
        aiViewDiffBtn?.addEventListener('click', () => openAiDiffModal('raw'));
        aiSmartDiffBtn?.addEventListener('click', () => openAiDiffModal('smart'));
        aiApplyBtn?.addEventListener('click', applyAiPlan);
        aiClearPlanBtn?.addEventListener('click', clearAiPendingChange);
        closeAiDiffModalBtn?.addEventListener('click', closeAiDiffModal);
        closeAiDiffFooterBtn?.addEventListener('click', closeAiDiffModal);
        aiDiffApplyBtn?.addEventListener('click', applyAiPlan);
        aiDiffModalEl?.addEventListener('click', (event) => {
            if (event.target === aiDiffModalEl) {
                closeAiDiffModal();
            }
        });

        function switchType(nextType) {
            studioState.type = nextType === 'template' ? 'template' : 'workflow';
            studioState.selectedProjectId = '';
            studioState.currentValidation = null;
            studioState.lastAiResult = null;
            studioState.aiMessages = [];
            studioState.lastDryRunResult = null;
            studioState.useInputs = {};
            studioState.activeJobId = null;
            studioState.activeJobIsDryRun = false;
            clearJobPoll();
            document.querySelectorAll('[data-type-tab]').forEach((button) => {
                button.classList.toggle('active', (button.dataset.typeTab || 'workflow') === studioState.type);
            });
            renderProjectList();
            loadDraft(null);
        }

        function getProjects() {
            return studioState.data[studioState.type] || [];
        }

        function getSelectedProject() {
            return getProjects().find((project) => project.id === studioState.selectedProjectId) || null;
        }

        function buildDefaultProject() {
            const isTemplate = studioState.type === 'template';
            const draft = deepClone(studioState.defaults[studioState.type] || {});
            return {
                id: '',
                name: '',
                description: '',
                status: 'draft',
                draft,
                templateId: isTemplate ? String(draft.id || '') : '',
                commandId: !isTemplate ? String(draft.id || '') : '',
                sourceType: isTemplate ? 'local' : undefined,
                versions: [],
                lastValidation: null,
                lastPublished: null,
            };
        }

        function loadDraft(project) {
            const previousProjectId = studioState.selectedProjectId;
            const record = project ? deepClone(project) : buildDefaultProject();
            const nextProjectId = project?.id || '';
            closeAiDiffModal();
            if (previousProjectId !== nextProjectId) {
                resetAiConversation(false);
            }
            studioState.selectedProjectId = project?.id || '';
            studioState.currentValidation = record.lastValidation || null;
            studioState.lastDryRunResult = null;
            studioState.useInputs = {};
            studioState.activeJobId = null;
            studioState.activeJobIsDryRun = false;
            clearJobPoll();
            studioState.isDirty = false;

            const isTemplate = studioState.type === 'template';
            const status = String(record.status || 'draft');
            const runtimeId = isTemplate ? (record.templateId || record.draft?.id || '') : (record.commandId || record.draft?.id || '');

            editorTitleEl.textContent = project ? record.name || (isTemplate ? 'Template' : 'Workflow') : `New ${isTemplate ? 'template' : 'workflow'}`;
            editorCopyEl.textContent = runtimeId ? `Runtime ID · ${runtimeId}` : '';
            runtimeIdLabelEl.textContent = isTemplate ? 'Template ID' : 'Command ID';
            projectNameEl.value = record.name || '';
            runtimeIdEl.value = runtimeId;
            projectDescriptionEl.value = record.description || '';
            draftJsonEl.value = JSON.stringify(record.draft || {}, null, 2);
            draftJsonEl.dataset.lastSerialized = draftJsonEl.value;
            sourceTypeFieldEl.hidden = !isTemplate;
            if (isTemplate) {
                sourceTypeEl.value = record.sourceType || 'local';
                if (aiPromptEl && aiPromptEl.value.trim() === '') {
                    aiPromptEl.placeholder = 'Ask AI to create or edit this template.';
                }
            } else {
                if (aiPromptEl && aiPromptEl.value.trim() === '') {
                    aiPromptEl.placeholder = 'Ask AI to create or edit this workflow.';
                }
            }

            setStatusBadge(status);
            if (isTemplate) {
                workflowBuilderEnvironment = '';
                workflowBuilderSteps = [];
            } else {
                loadWorkflowBuilderDraft(record.draft || {});
                syncWorkflowDraftJsonFromBuilder(false);
            }
            renderWorkflowEditBuilder();
            updateEditorMode();
            renderProjectList();
            renderUsePanel();
            renderDryRunResult(studioState.lastDryRunResult);
            renderAiMessages();
            editorStatusLineEl.textContent = project
                ? `Editing ${isTemplate ? 'template' : 'workflow'} project ${project.id}.`
                : `Creating a new ${isTemplate ? 'template' : 'workflow'}.`;
            dryRunPanelEl.hidden = isTemplate;
            useRunBtn.hidden = isTemplate;
            useDryRunBtn.hidden = isTemplate;
            publishBtn.disabled = false;
            archiveBtn.disabled = !project;
        }

        function switchEditorMode(nextMode) {
            studioState.editorMode = nextMode === 'edit' ? 'edit' : 'use';
            updateEditorMode();
            if (studioState.editorMode === 'edit') {
                renderWorkflowEditBuilder();
            }
            renderUsePanel();
        }

        function updateEditorMode() {
            const isEdit = studioState.editorMode === 'edit';
            usePanelEl.hidden = isEdit;
            editPanelEl.hidden = !isEdit;
            document.querySelectorAll('[data-editor-mode-btn]').forEach((button) => {
                const mode = button.getAttribute('data-editor-mode-btn') || 'use';
                button.classList.toggle('active', mode === studioState.editorMode);
            });
            renderDraftHelper();
        }

        function renderDraftHelper() {
            const isTemplate = studioState.type === 'template';
            draftHelperEl.textContent = isTemplate
                ? 'Raw template JSON.'
                : 'Advanced fallback. Use "Apply JSON to editor" after manual changes.';
        }

        function isWorkflowBuilderEnabled() {
            return studioState.type === 'workflow';
        }

        function parseWorkflowTargetRef(raw) {
            if (!raw) {
                return { mode: 'inherit', targetId: '', fromEnvironment: '', envId: '' };
            }
            if (typeof raw === 'string') {
                const trimmed = raw.trim();
                if (!trimmed || trimmed === 'inherit' || trimmed === 'none') {
                    return { mode: 'inherit', targetId: '', fromEnvironment: '', envId: '' };
                }
                return { mode: 'targetId', targetId: trimmed, fromEnvironment: '', envId: '' };
            }
            const targetId = String(raw.targetId || '').trim();
            if (targetId) {
                return { mode: 'targetId', targetId, fromEnvironment: '', envId: '' };
            }
            const fromEnvironment = String(raw.fromEnvironment || '').trim();
            if (fromEnvironment) {
                return { mode: 'fromEnvironment', targetId: '', fromEnvironment, envId: '' };
            }
            return { mode: 'inherit', targetId: '', fromEnvironment: '', envId: '' };
        }

        function serializeWorkflowTargetRef(ref) {
            if (!ref || ref.mode === 'inherit') {
                return 'inherit';
            }
            if (ref.mode === 'targetId') {
                const targetId = String(ref.targetId || '').trim();
                return targetId ? { targetId } : 'inherit';
            }
            if (ref.mode === 'fromEnvironment') {
                const fromEnvironment = String(ref.fromEnvironment || '').trim();
                return fromEnvironment ? { fromEnvironment } : 'inherit';
            }
            return 'inherit';
        }

        function targetTypeIcon(type) {
            const icons = {
                ssh: 'fas fa-server',
                local: 'fas fa-laptop',
                docker: 'fas fa-cube',
                'docker-compose': 'fas fa-layer-group',
                kubernetes: 'fas fa-dharmachakra',
                http: 'fas fa-globe',
                api: 'fas fa-plug',
                webhook: 'fas fa-bolt',
            };
            return icons[String(type || '').trim()] || 'fas fa-bullseye';
        }

        function escapeAttr(value) {
            if (value === undefined || value === null) {
                return '';
            }
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;');
        }

        function escapeJs(value) {
            if (value === undefined || value === null) {
                return '';
            }
            return String(value)
                .replace(/\\/g, '\\\\')
                .replace(/'/g, "\\'")
                .replace(/\n/g, '\\n')
                .replace(/\r/g, '\\r');
        }

        function renderIconHtml(icon, fallback = 'fas fa-puzzle-piece') {
            const value = icon || fallback;
            return /^fa[bsrld]?\s+fa-/.test(String(value))
                ? `<i class="${escapeAttr(value)}" aria-hidden="true"></i>`
                : escapeHtml(value);
        }

        function normalizeInteractiveFieldType(field) {
            return normalizeUseInputType(field);
        }

        function readWorkflowBuilderControlValue(element, type) {
            if (type === 'multiselect' && element instanceof HTMLSelectElement) {
                return Array.from(element.selectedOptions).map((option) => option.value).filter(Boolean);
            }
            return convertInputValue(element.value, type);
        }

        function renderWorkflowBuilderValueField(field, raw, onChangeExpr) {
            const value = raw === undefined || raw === null ? '' : raw;
            const type = normalizeInteractiveFieldType(field);
            if (type === 'select' && Array.isArray(field.options) && field.options.length) {
                return `<select class="studio-select" onchange="${onChangeExpr}">
                    <option value=""></option>
                    ${field.options.map((option) => {
                        const optionValue = option.value ?? option;
                        const optionLabel = option.label ?? optionValue;
                        return `<option value="${escapeAttr(optionValue)}" ${String(value) === String(optionValue) ? 'selected' : ''}>${escapeHtml(optionLabel)}</option>`;
                    }).join('')}
                </select>`;
            }
            if (type === 'multiselect') {
                const selectedValues = Array.isArray(value) ? value.map((item) => String(item)) : [];
                if (Array.isArray(field.options) && field.options.length) {
                    return `<select class="studio-select" multiple onchange="${onChangeExpr}">
                        ${field.options.map((option) => {
                            const optionValue = String(option.value ?? option);
                            const optionLabel = option.label ?? optionValue;
                            return `<option value="${escapeAttr(optionValue)}" ${selectedValues.includes(optionValue) ? 'selected' : ''}>${escapeHtml(optionLabel)}</option>`;
                        }).join('')}
                    </select>`;
                }
                return `<textarea class="studio-textarea" placeholder="${escapeAttr(field.placeholder || 'One value per line')}" oninput="${onChangeExpr}">${escapeHtml(formatMultivalueText(value))}</textarea>`;
            }
            if (type === 'boolean') {
                return `<select class="studio-select" onchange="${onChangeExpr}">
                    <option value=""></option>
                    <option value="true" ${value === true || value === 'true' ? 'selected' : ''}>true</option>
                    <option value="false" ${value === false || value === 'false' ? 'selected' : ''}>false</option>
                </select>`;
            }
            if (type === 'json' || isPlainObject(value) || (Array.isArray(value) && !isStringArray(value))) {
                return `<textarea class="studio-textarea" placeholder="${escapeAttr(field.placeholder || 'Enter valid JSON')}" oninput="${onChangeExpr}">${escapeHtml(prettyJson(value))}</textarea>`;
            }
            if (Array.isArray(value)) {
                return `<textarea class="studio-textarea" placeholder="${escapeAttr(field.placeholder || 'One value per line')}" oninput="${onChangeExpr}">${escapeHtml(formatMultivalueText(value))}</textarea>`;
            }
            if (type === 'number') {
                const min = field.validation?.min !== undefined ? `min="${escapeAttr(field.validation.min)}"` : '';
                const max = field.validation?.max !== undefined ? `max="${escapeAttr(field.validation.max)}"` : '';
                return `<input class="studio-input" type="number" value="${escapeAttr(value)}" ${min} ${max} oninput="${onChangeExpr}">`;
            }
            return `<input class="studio-input" type="text" value="${escapeAttr(value)}" placeholder="${escapeAttr(field.placeholder || '')}" oninput="${onChangeExpr}">`;
        }

        function getWorkflowBuilderEnvironmentEntries() {
            return Object.entries(studioState.environments || {}).map(([id, environment]) => ({
                id,
                name: String(environment?.name || id),
                targets: isPlainObject(environment?.targets) ? environment.targets : {},
            }));
        }

        function getWorkflowBuilderEnvironmentTargets(environmentId) {
            const environment = studioState.environments?.[environmentId];
            return isPlainObject(environment?.targets) ? environment.targets : {};
        }

        function populateWorkflowEnvironmentSelect() {
            if (!workflowEnvironmentSelectEl) {
                return;
            }
            const options = getWorkflowBuilderEnvironmentEntries().map((environment) => (
                `<option value="${escapeAttr(environment.id)}" ${workflowBuilderEnvironment === environment.id ? 'selected' : ''}>${escapeHtml(environment.name)}</option>`
            )).join('');
            workflowEnvironmentSelectEl.innerHTML = `<option value="">None</option>${options}`;
        }

        function normalizeWorkflowBuilderSteps(rawSteps) {
            if (!Array.isArray(rawSteps) || !rawSteps.length) {
                return [newWorkflowBuilderStep(0)];
            }
            const normalized = rawSteps
                .filter((step) => isPlainObject(step))
                .map((step, index) => ({
                    id: String(step.id || `step-${index + 1}`),
                    templateId: String(step.templateId || ''),
                    target: parseWorkflowTargetRef(step.target),
                    dependsOn: Array.isArray(step.dependsOn) ? step.dependsOn.map(String).filter(Boolean) : [],
                    onFailure: String(step.onFailure || 'stop') === 'continue' ? 'continue' : 'stop',
                    inputs: isPlainObject(step.inputs) ? { ...step.inputs } : {},
                    secrets: isPlainObject(step.secrets) ? { ...step.secrets } : {},
                }));
            return normalized.length ? normalized : [newWorkflowBuilderStep(0)];
        }

        function newWorkflowBuilderStep(index) {
            const previous = workflowBuilderSteps[index - 1];
            return {
                id: `step-${index + 1}`,
                templateId: '',
                target: { mode: 'inherit', targetId: '', fromEnvironment: '', envId: '' },
                dependsOn: previous?.id ? [previous.id] : [],
                onFailure: 'stop',
                inputs: {},
                secrets: {},
            };
        }

        function loadWorkflowBuilderDraft(draft) {
            const normalizedDraft = isPlainObject(draft) ? draft : {};
            workflowBuilderExecutionMode = String(normalizedDraft.executionMode || 'sync').toLowerCase() === 'async' ? 'async' : 'sync';
            workflowBuilderPolicy = String(normalizedDraft.finalStatusPolicy || 'fail_if_any_failed') === 'completed_with_warnings'
                ? 'completed_with_warnings'
                : 'fail_if_any_failed';
            workflowBuilderMaxParallel = Math.max(1, Number(normalizedDraft.maxParallel || 1) || 1);
            workflowBuilderEnvironment = String(normalizedDraft.environment || '').trim();
            workflowBuilderDefaultTarget = parseWorkflowTargetRef(normalizedDraft.defaultTarget);
            workflowBuilderSteps = normalizeWorkflowBuilderSteps(normalizedDraft.steps);
            if (workflowBuilderDefaultTarget.mode === 'fromEnvironment' && !workflowBuilderDefaultTarget.envId) {
                workflowBuilderDefaultTarget.envId = workflowBuilderEnvironment;
            }
            workflowBuilderSteps.forEach((step) => {
                if (step.target.mode === 'fromEnvironment' && !step.target.envId) {
                    step.target.envId = workflowBuilderEnvironment;
                }
            });
            renderWorkflowEditBuilder();
        }

        function getWorkflowBuilderSerializedSteps() {
            return workflowBuilderSteps.map((step) => ({
                id: String(step.id || '').trim() || 'step',
                templateId: String(step.templateId || '').trim(),
                target: serializeWorkflowTargetRef(step.target),
                dependsOn: Array.isArray(step.dependsOn) ? step.dependsOn.map(String).filter(Boolean) : [],
                onFailure: String(step.onFailure || 'stop') === 'continue' ? 'continue' : 'stop',
                inputs: isPlainObject(step.inputs) ? { ...step.inputs } : {},
                secrets: isPlainObject(step.secrets) ? { ...step.secrets } : {},
            }));
        }

        function buildWorkflowDraftFromBuilder() {
            const fallback = deepClone(getSelectedProject()?.draft || studioState.defaults.workflow || {});
            const base = isPlainObject(readDraftJson(false)) ? readDraftJson(false) : fallback;
            const draft = isPlainObject(base) ? deepClone(base) : deepClone(fallback);
            draft.id = runtimeIdEl.value.trim();
            draft.name = projectNameEl.value.trim();
            draft.description = projectDescriptionEl.value.trim();
            draft.executionMode = workflowBuilderExecutionMode;
            draft.maxParallel = workflowBuilderMaxParallel;
            draft.finalStatusPolicy = workflowBuilderPolicy;
            draft.defaultTarget = serializeWorkflowTargetRef(workflowBuilderDefaultTarget);
            draft.environment = workflowBuilderEnvironment || null;
            draft.steps = getWorkflowBuilderSerializedSteps();
            if (!Array.isArray(draft.inputs) && !isPlainObject(draft.inputs)) {
                draft.inputs = [];
            }
            if (!Array.isArray(draft.inputContract)) {
                draft.inputContract = [];
            }
            if (!isPlainObject(draft.secrets)) {
                draft.secrets = {};
            }
            if (!Array.isArray(draft.tags)) {
                draft.tags = [];
            }
            if (!Object.prototype.hasOwnProperty.call(draft, 'enabled')) {
                draft.enabled = true;
            }
            return draft;
        }

        function syncWorkflowDraftJsonFromBuilder(markAsDirty = false) {
            if (!isWorkflowBuilderEnabled() || workflowBuilderDirtySync) {
                return;
            }
            workflowBuilderDirtySync = true;
            const draft = buildWorkflowDraftFromBuilder();
            const serialized = JSON.stringify(draft, null, 2);
            draftJsonEl.value = serialized;
            draftJsonEl.dataset.lastSerialized = serialized;
            workflowBuilderDirtySync = false;
            if (markAsDirty) {
                markDirty();
            }
        }

        function syncWorkflowBuilderFromRawJsonIfNeeded(showErrors = true) {
            if (!isWorkflowBuilderEnabled()) {
                return true;
            }
            const raw = draftJsonEl.value.trim();
            const lastSerialized = draftJsonEl.dataset.lastSerialized || '';
            if (!raw || raw === lastSerialized) {
                return true;
            }
            const draft = readDraftJson(showErrors);
            if (!draft) {
                return false;
            }
            loadWorkflowBuilderDraft(draft);
            syncWorkflowDraftJsonFromBuilder(false);
            return true;
        }

        function applyRawJsonToEditor() {
            if (studioState.type === 'template') {
                editorStatusLineEl.textContent = 'Template JSON stays as-is in the editor.';
                return;
            }
            const draft = readDraftJson(true);
            if (!draft) {
                return;
            }
            loadWorkflowBuilderDraft(draft);
            syncWorkflowDraftJsonFromBuilder(true);
            editorStatusLineEl.textContent = 'Applied raw JSON to the workflow editor.';
            window.Toast?.success?.('Applied JSON to editor');
        }

        function renderWorkflowEditBuilder() {
            const isTemplate = studioState.type === 'template';
            workflowBuilderCardEl.hidden = isTemplate;
            templateEditHintEl.hidden = !isTemplate;
            if (isTemplate) {
                return;
            }
            populateWorkflowEnvironmentSelect();
            workflowModeSyncBtn?.classList.toggle('active', workflowBuilderExecutionMode === 'sync');
            workflowModeAsyncBtn?.classList.toggle('active', workflowBuilderExecutionMode === 'async');
            workflowPolicyFailBtn?.classList.toggle('active', workflowBuilderPolicy === 'fail_if_any_failed');
            workflowPolicyWarnBtn?.classList.toggle('active', workflowBuilderPolicy === 'completed_with_warnings');
            if (workflowMaxParallelValueEl) {
                workflowMaxParallelValueEl.textContent = String(workflowBuilderMaxParallel);
            }
            renderWorkflowDefaultTargetSection();
            renderWorkflowStepsEditor();
        }

        function setWorkflowExecutionMode(mode) {
            workflowBuilderExecutionMode = mode === 'async' ? 'async' : 'sync';
            renderWorkflowEditBuilder();
            syncWorkflowDraftJsonFromBuilder(true);
        }

        function setWorkflowPolicy(policy) {
            workflowBuilderPolicy = policy === 'completed_with_warnings' ? 'completed_with_warnings' : 'fail_if_any_failed';
            renderWorkflowEditBuilder();
            syncWorkflowDraftJsonFromBuilder(true);
        }

        function adjustWorkflowMaxParallel(delta) {
            workflowBuilderMaxParallel = Math.max(1, workflowBuilderMaxParallel + delta);
            renderWorkflowEditBuilder();
            syncWorkflowDraftJsonFromBuilder(true);
        }

        function renderWorkflowDefaultTargetSection() {
            if (!workflowDefaultTargetEditorEl) {
                return;
            }
            const ref = workflowBuilderDefaultTarget;
            const mode = ref?.mode || 'inherit';
            const environments = getWorkflowBuilderEnvironmentEntries();
            let html = `<div class="toggle-opts studio-toggle-opts">
                <button class="toggle-opt ${mode === 'inherit' ? 'active' : ''}" type="button" onclick="selectWorkflowDefaultTargetMode('inherit')">
                    <i class="fas fa-question-circle"></i>
                    Ask each time
                </button>
                <button class="toggle-opt ${mode === 'targetId' ? 'active' : ''}" type="button" onclick="selectWorkflowDefaultTargetMode('targetId')">
                    <i class="fas fa-bullseye"></i>
                    Specific target
                </button>
                <button class="toggle-opt ${mode === 'fromEnvironment' ? 'active' : ''}" type="button" onclick="selectWorkflowDefaultTargetMode('fromEnvironment')">
                    <i class="fas fa-cubes"></i>
                    From environment
                </button>
            </div>`;

            if (mode === 'inherit') {
                html += '<div class="studio-helper">The user will choose a target when running this workflow.</div>';
            } else if (mode === 'targetId') {
                if (!studioState.targets.length) {
                    html += '<div class="studio-helper">No targets configured yet.</div>';
                } else {
                    html += '<div class="target-chips">';
                    studioState.targets.forEach((target) => {
                        const selected = ref.targetId === target.id;
                        html += `<button class="target-chip ${selected ? 'selected' : ''}" type="button" onclick="selectWorkflowDefaultTarget('targetId','${escapeJs(target.id)}')">
                            <i class="${escapeAttr(targetTypeIcon(target.type))}"></i>
                            <span class="target-chip-name">${escapeHtml(target.name)}</span>
                            ${target.type ? `<span class="target-chip-type">${escapeHtml(target.type)}</span>` : ''}
                        </button>`;
                    });
                    html += '</div>';
                }
            } else if (mode === 'fromEnvironment') {
                html += `<div class="studio-builder-section">
                    <label class="studio-field">
                        <span class="studio-label">Environment target source</span>
                        <select class="studio-select" onchange="selectWorkflowDefaultTargetEnv(this.value)">
                            <option value="">Select environment</option>
                            ${environments.map((environment) => `<option value="${escapeAttr(environment.id)}" ${workflowBuilderDefaultTarget.envId === environment.id ? 'selected' : ''}>${escapeHtml(environment.name)}</option>`).join('')}
                        </select>
                    </label>
                    <div id="workflowDefaultTargetEnvKeys">${renderWorkflowDefaultTargetEnvKeysHtml()}</div>
                </div>`;
            }

            workflowDefaultTargetEditorEl.innerHTML = html;
        }

        function renderWorkflowDefaultTargetEnvKeysHtml() {
            const environmentId = workflowBuilderDefaultTarget.envId;
            if (!environmentId) {
                return '';
            }
            const envTargets = getWorkflowBuilderEnvironmentTargets(environmentId);
            const keys = Object.keys(envTargets);
            if (!keys.length) {
                return '<div class="studio-helper">No target keys configured for this environment.</div>';
            }
            return `<div class="target-chips">` + keys.map((key) => {
                const target = targetById[envTargets[key]];
                const selected = workflowBuilderDefaultTarget.fromEnvironment === key;
                return `<button class="target-chip ${selected ? 'selected' : ''}" type="button" onclick="selectWorkflowDefaultTargetEnvKey('${escapeJs(key)}')">
                    <i class="${escapeAttr(target ? targetTypeIcon(target.type) : 'fas fa-bullseye')}"></i>
                    <span class="target-chip-name">${escapeHtml(key)}</span>
                    ${target?.type ? `<span class="target-chip-type">${escapeHtml(target.type)}</span>` : ''}
                </button>`;
            }).join('') + '</div>';
        }

        function selectWorkflowDefaultTargetMode(mode) {
            if (mode === 'inherit') {
                workflowBuilderDefaultTarget = { mode: 'inherit', targetId: '', fromEnvironment: '', envId: '' };
            } else if (mode === 'targetId') {
                workflowBuilderDefaultTarget = { mode: 'targetId', targetId: workflowBuilderDefaultTarget.targetId || '', fromEnvironment: '', envId: '' };
            } else {
                workflowBuilderDefaultTarget = { mode: 'fromEnvironment', targetId: '', fromEnvironment: workflowBuilderDefaultTarget.fromEnvironment || '', envId: workflowBuilderEnvironment || workflowBuilderDefaultTarget.envId || '' };
            }
            renderWorkflowEditBuilder();
            syncWorkflowDraftJsonFromBuilder(true);
        }

        function selectWorkflowDefaultTarget(mode, targetId) {
            workflowBuilderDefaultTarget = { mode, targetId: targetId || '', fromEnvironment: '', envId: '' };
            renderWorkflowEditBuilder();
            syncWorkflowDraftJsonFromBuilder(true);
        }

        function selectWorkflowDefaultTargetEnv(environmentId) {
            workflowBuilderDefaultTarget.envId = environmentId;
            workflowBuilderDefaultTarget.fromEnvironment = '';
            renderWorkflowEditBuilder();
            syncWorkflowDraftJsonFromBuilder(true);
        }

        function selectWorkflowDefaultTargetEnvKey(key) {
            workflowBuilderDefaultTarget.fromEnvironment = key;
            renderWorkflowEditBuilder();
            syncWorkflowDraftJsonFromBuilder(true);
        }

        function renderWorkflowStepsEditor() {
            if (!workflowStepsEditorEl) {
                return;
            }
            let html = '';
            workflowBuilderSteps.forEach((step, index) => {
                if (index > 0) {
                    html += renderWorkflowConnectorHtml(index - 1);
                }
                html += renderWorkflowStepCardHtml(index);
            });
            html += `<div class="step-add-wrap">
                <button class="pipeline-add-btn" type="button" onclick="addWorkflowBuilderStep()">
                    <i class="fas fa-plus"></i>
                    Add step
                </button>
            </div>`;
            workflowStepsEditorEl.innerHTML = html;
        }

        function renderWorkflowStepCardHtml(index) {
            const step = workflowBuilderSteps[index];
            const template = templateById[step.templateId];
            const templateInputs = Array.isArray(template?.inputs) ? template.inputs : [];
            const isOverride = step.target.mode !== 'inherit';
            return `<div class="step-card" id="workflow-step-card-${index}">
                <div class="step-card-header">
                    <div class="step-num-sm">${index + 1}</div>
                    <input class="step-id-input" value="${escapeAttr(step.id)}" oninput="updateWorkflowStepId(${index}, this.value)" placeholder="step-id" title="Step ID">
                    <div class="step-ctrl">
                        <button class="step-ctrl-btn" type="button" onclick="moveWorkflowStep(${index}, -1)" ${index === 0 ? 'disabled' : ''} title="Move up"><i class="fas fa-arrow-up"></i></button>
                        <button class="step-ctrl-btn" type="button" onclick="moveWorkflowStep(${index}, 1)" ${index === workflowBuilderSteps.length - 1 ? 'disabled' : ''} title="Move down"><i class="fas fa-arrow-down"></i></button>
                        <button class="step-ctrl-btn" type="button" onclick="duplicateWorkflowStep(${index})" title="Duplicate"><i class="fas fa-copy"></i></button>
                        <button class="step-ctrl-btn" type="button" onclick="removeWorkflowStep(${index})" title="Remove"><i class="fas fa-times"></i></button>
                    </div>
                </div>
                <div class="step-card-body">
                    <div id="workflow-step-template-${index}">${renderWorkflowTemplatePickerHtml(index)}</div>
                    <div id="workflow-step-inputs-${index}" ${templateInputs.length ? '' : 'style="display:none"'}>
                        ${renderWorkflowStepInputsHtml(index)}
                    </div>
                    <div class="step-options-row">
                        <div class="step-opt-group">
                            <span class="form-inline-label">Execute on</span>
                            <div class="toggle-opts studio-toggle-opts">
                                <button class="toggle-opt ${!isOverride ? 'active' : ''}" type="button" onclick="setWorkflowStepTargetMode(${index}, 'inherit')">
                                    <i class="fas fa-link"></i>
                                    Same as workflow
                                </button>
                                <button class="toggle-opt ${isOverride ? 'active' : ''}" type="button" onclick="setWorkflowStepTargetMode(${index}, 'override')">
                                    <i class="fas fa-bullseye"></i>
                                    Different target
                                </button>
                            </div>
                        </div>
                    </div>
                    <div id="workflow-step-target-${index}" style="${isOverride ? '' : 'display:none;'}">
                        ${isOverride ? renderWorkflowStepTargetHtml(index) : ''}
                    </div>
                </div>
            </div>`;
        }

        function renderWorkflowTemplatePickerHtml(index) {
            const step = workflowBuilderSteps[index];
            if (!step) {
                return '';
            }
            return step.templateId
                ? renderWorkflowSelectedTemplateHtml(index)
                : `<button class="tpl-trigger" type="button" onclick="openWorkflowTemplatePicker(${index})">
                    <i class="fas fa-puzzle-piece" style="opacity:.5"></i>
                    Pick a template…
                    <span class="tpl-req">required</span>
                </button>`;
        }

        function renderWorkflowSelectedTemplateHtml(index) {
            const step = workflowBuilderSteps[index];
            const template = templateById[step?.templateId || ''];
            if (!template) {
                return renderWorkflowTemplatePickerHtml(index);
            }
            return `<div class="tpl-selected">
                <div class="tpl-selected-icon">${renderIconHtml(template.icon)}</div>
                <div class="tpl-selected-info">
                    <div class="tpl-selected-name">
                        ${escapeHtml(template.name)}
                        ${template.targetType ? `<span class="tpl-type-badge"><i class="${escapeAttr(targetTypeIcon(template.targetType))}" style="margin-right:3px;"></i>${escapeHtml(template.targetType)}</span>` : ''}
                    </div>
                    ${template.description ? `<div class="tpl-selected-desc">${escapeHtml(template.description)}</div>` : ''}
                </div>
                <button class="tpl-change-btn" type="button" onclick="openWorkflowTemplatePicker(${index})">
                    <i class="fas fa-exchange-alt" style="font-size:10px;margin-right:4px;"></i>
                    Change
                </button>
            </div>`;
        }

        function renderWorkflowTemplatePickerOpenHtml(index, query) {
            return `<div class="tpl-picker-open">
                <div class="tpl-search-wrap">
                    <i class="fas fa-search"></i>
                    <input class="tpl-search" id="workflow-tpl-search-${index}" placeholder="Search templates…" oninput="filterWorkflowTemplates(${index})" autocomplete="off" value="${escapeAttr(query)}">
                </div>
                <div class="tpl-grid" id="workflow-tpl-grid-${index}">
                    ${renderWorkflowTemplateGridItems(index, query)}
                </div>
                ${workflowBuilderSteps[index]?.templateId ? `<div class="tpl-picker-footer"><button class="tpl-cancel" type="button" onclick="closeWorkflowTemplatePicker(${index})">Cancel</button></div>` : ''}
            </div>`;
        }

        function renderWorkflowTemplateGridItems(index, query) {
            const search = String(query || '').trim().toLowerCase();
            const filtered = search
                ? studioState.runtimeTemplates.filter((template) => String(template.name || '').toLowerCase().includes(search) || String(template.description || '').toLowerCase().includes(search))
                : studioState.runtimeTemplates;
            if (!filtered.length) {
                return `<div class="tpl-no-results">No templates match "${escapeHtml(query)}"</div>`;
            }
            return filtered.map((template) => `
                <button class="tpl-card" type="button" onclick="selectWorkflowTemplate(${index}, '${escapeJs(template.id)}')">
                    <div class="tpl-card-icon">${renderIconHtml(template.icon)}</div>
                    <div class="tpl-card-name">${escapeHtml(template.name)}</div>
                    ${template.targetType ? `<div class="tpl-card-type"><i class="${escapeAttr(targetTypeIcon(template.targetType))}" style="margin-right:3px;"></i>${escapeHtml(template.targetType)}</div>` : ''}
                    ${template.description ? `<div class="tpl-card-desc">${escapeHtml(template.description)}</div>` : ''}
                </button>
            `).join('');
        }

        function openWorkflowTemplatePicker(index) {
            const container = document.getElementById(`workflow-step-template-${index}`);
            if (!container) {
                return;
            }
            container.innerHTML = renderWorkflowTemplatePickerOpenHtml(index, '');
            const search = document.getElementById(`workflow-tpl-search-${index}`);
            search?.focus();
        }

        function closeWorkflowTemplatePicker(index) {
            const container = document.getElementById(`workflow-step-template-${index}`);
            if (!container) {
                return;
            }
            container.innerHTML = renderWorkflowTemplatePickerHtml(index);
        }

        function filterWorkflowTemplates(index) {
            const query = document.getElementById(`workflow-tpl-search-${index}`)?.value || '';
            const grid = document.getElementById(`workflow-tpl-grid-${index}`);
            if (grid) {
                grid.innerHTML = renderWorkflowTemplateGridItems(index, query);
            }
        }

        function selectWorkflowTemplate(index, templateId) {
            const step = workflowBuilderSteps[index];
            if (!step) {
                return;
            }
            step.templateId = templateId;
            step.inputs = {};
            renderWorkflowEditBuilder();
            syncWorkflowDraftJsonFromBuilder(true);
        }

        function renderWorkflowStepInputsHtml(index) {
            const step = workflowBuilderSteps[index];
            const template = templateById[step?.templateId || ''];
            const templateInputs = Array.isArray(template?.inputs) ? template.inputs : [];
            if (!templateInputs.length) {
                return '';
            }
            return templateInputs.map((input) => {
                const raw = step.inputs[input.name];
                const isRuntime = typeof raw === 'string' && raw.startsWith('{{inputs.');
                const value = isRuntime ? '' : (raw === undefined ? '' : raw);
                const variableName = isRuntime ? raw.replace(/^\{\{inputs\./, '').replace(/\}\}$/, '') : '';
                const inputType = normalizeInteractiveFieldType(input);
                const onChangeExpr = "updateWorkflowStepInput("
                    + index
                    + ",'"
                    + escapeJs(input.name)
                    + "',readWorkflowBuilderControlValue(this,'"
                    + escapeJs(inputType)
                    + "'),'"
                    + escapeJs(inputType)
                    + "')";
                return `<div class="dynamic-input-group">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
                        <label class="dynamic-input-label" style="margin:0;">
                            ${escapeHtml(input.label || input.name)}
                            <span class="dynamic-input-type">${escapeHtml(inputType)}</span>
                            ${input.required ? '<span style="color:var(--error);">*</span>' : ''}
                        </label>
                        <button class="runtime-var-toggle" type="button" onclick="toggleWorkflowRuntimeVar(${index}, '${escapeJs(input.name)}')" title="${isRuntime ? 'Switch to fixed value' : 'Make this a runtime variable'}">
                            ${isRuntime ? '<i class="fas fa-times"></i> fixed' : '<i class="fas fa-bolt"></i> runtime'}
                        </button>
                    </div>
                    ${isRuntime
                        ? `<div class="runtime-var-pill"><i class="fas fa-bolt"></i> inputs.${escapeHtml(variableName || input.name)}</div>
                           <div class="dynamic-input-hint" style="margin-top:6px;">User fills this value when running the workflow${input.description ? ' · ' + escapeHtml(input.description) : ''}</div>`
                        : renderWorkflowBuilderValueField(input, value, onChangeExpr)
                    }
                </div>`;
            }).join('');
        }

        function renderWorkflowStepTargetHtml(index) {
            const step = workflowBuilderSteps[index];
            const template = templateById[step?.templateId || ''];
            const requiredType = template?.targetType || null;
            const compatibleTargets = requiredType
                ? studioState.targets.filter((target) => target.type === requiredType)
                : studioState.targets;
            const environments = getWorkflowBuilderEnvironmentEntries();
            let html = '<div class="step-target-inline"><div class="target-chips">';
            if (compatibleTargets.length) {
                compatibleTargets.forEach((target) => {
                    const selected = step.target.mode === 'targetId' && step.target.targetId === target.id;
                    html += `<button class="target-chip ${selected ? 'selected' : ''}" type="button" onclick="selectWorkflowStepTarget(${index}, 'targetId', '${escapeJs(target.id)}')">
                        <i class="${escapeAttr(targetTypeIcon(target.type))}"></i>
                        <span class="target-chip-name">${escapeHtml(target.name)}</span>
                        ${target.type ? `<span class="target-chip-type">${escapeHtml(target.type)}</span>` : ''}
                    </button>`;
                });
            } else {
                html += `<span class="studio-helper">No${requiredType ? ` ${escapeHtml(requiredType)}` : ''} targets configured.</span>`;
            }
            if (environments.length) {
                html += `<button class="target-chip ${step.target.mode === 'fromEnvironment' ? 'selected' : ''}" type="button" onclick="selectWorkflowStepTarget(${index}, 'fromEnvironment', '')">
                    <i class="fas fa-cubes"></i>
                    <span class="target-chip-name">From environment</span>
                </button>`;
            }
            html += '</div>';
            if (step.target.mode === 'fromEnvironment') {
                html += `<div class="studio-builder-section" style="margin-top:12px;">
                    <label class="studio-field">
                        <span class="studio-label">Environment target source</span>
                        <select class="studio-select" onchange="selectWorkflowStepEnvironment(${index}, this.value)">
                            <option value="">Select environment</option>
                            ${environments.map((environment) => `<option value="${escapeAttr(environment.id)}" ${step.target.envId === environment.id ? 'selected' : ''}>${escapeHtml(environment.name)}</option>`).join('')}
                        </select>
                    </label>
                    <div id="workflow-step-env-keys-${index}">${renderWorkflowStepEnvironmentKeysHtml(index)}</div>
                </div>`;
            }
            html += '</div>';
            return html;
        }

        function renderWorkflowStepEnvironmentKeysHtml(index) {
            const step = workflowBuilderSteps[index];
            const environmentId = step?.target?.envId || '';
            if (!environmentId) {
                return '';
            }
            const envTargets = getWorkflowBuilderEnvironmentTargets(environmentId);
            const template = templateById[step?.templateId || ''];
            const requiredType = template?.targetType || null;
            const keys = Object.keys(envTargets).filter((key) => {
                if (!requiredType) {
                    return true;
                }
                const target = targetById[envTargets[key]];
                return !target || target.type === requiredType;
            });
            if (!keys.length) {
                return '<div class="studio-helper">No matching target keys in this environment.</div>';
            }
            return `<div class="target-chips">` + keys.map((key) => {
                const target = targetById[envTargets[key]];
                const selected = step.target.fromEnvironment === key;
                return `<button class="target-chip ${selected ? 'selected' : ''}" type="button" onclick="selectWorkflowStepEnvironmentKey(${index}, '${escapeJs(key)}')">
                    <i class="${escapeAttr(target ? targetTypeIcon(target.type) : 'fas fa-bullseye')}"></i>
                    <span class="target-chip-name">${escapeHtml(key)}</span>
                    ${target?.type ? `<span class="target-chip-type">${escapeHtml(target.type)}</span>` : ''}
                </button>`;
            }).join('') + '</div>';
        }

        function addWorkflowBuilderStep() {
            workflowBuilderSteps.push(newWorkflowBuilderStep(workflowBuilderSteps.length));
            renderWorkflowEditBuilder();
            syncWorkflowDraftJsonFromBuilder(true);
            window.setTimeout(() => {
                document.getElementById(`workflow-step-card-${workflowBuilderSteps.length - 1}`)?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }, 30);
        }

        function removeWorkflowStep(index) {
            if (workflowBuilderSteps.length <= 1) {
                window.Toast?.error?.('A workflow needs at least one step');
                return;
            }
            const removedId = workflowBuilderSteps[index]?.id || '';
            workflowBuilderSteps.splice(index, 1);
            workflowBuilderSteps.forEach((step) => {
                step.dependsOn = step.dependsOn.filter((dependencyId) => dependencyId !== removedId);
            });
            renderWorkflowEditBuilder();
            syncWorkflowDraftJsonFromBuilder(true);
        }

        function moveWorkflowStep(index, delta) {
            const nextIndex = index + delta;
            if (nextIndex < 0 || nextIndex >= workflowBuilderSteps.length) {
                return;
            }
            [workflowBuilderSteps[index], workflowBuilderSteps[nextIndex]] = [workflowBuilderSteps[nextIndex], workflowBuilderSteps[index]];
            renderWorkflowEditBuilder();
            syncWorkflowDraftJsonFromBuilder(true);
        }

        function duplicateWorkflowStep(index) {
            const step = workflowBuilderSteps[index];
            if (!step) {
                return;
            }
            const clone = deepClone(step);
            const baseId = String(step.id || 'step').replace(/-copy(-\d+)?$/, '');
            let nextId = `${baseId}-copy`;
            let counter = 2;
            while (workflowBuilderSteps.some((candidate) => candidate.id === nextId)) {
                nextId = `${baseId}-copy-${counter++}`;
            }
            clone.id = nextId;
            clone.dependsOn = step.id ? [step.id] : [];
            workflowBuilderSteps.splice(index + 1, 0, clone);
            renderWorkflowEditBuilder();
            syncWorkflowDraftJsonFromBuilder(true);
        }

        function updateWorkflowStepId(index, value) {
            const step = workflowBuilderSteps[index];
            if (!step) {
                return;
            }
            const previousId = step.id;
            step.id = String(value || '').trim();
            workflowBuilderSteps.forEach((candidate) => {
                candidate.dependsOn = candidate.dependsOn.map((dependencyId) => dependencyId === previousId ? step.id : dependencyId);
            });
            syncWorkflowDraftJsonFromBuilder(true);
        }

        function updateWorkflowStepInput(index, name, value, type) {
            const step = workflowBuilderSteps[index];
            if (!step) {
                return;
            }
            const nextValue = convertInputValue(value, type);
            if (nextValue === '' || nextValue === null || nextValue === undefined) {
                delete step.inputs[name];
            } else {
                step.inputs[name] = nextValue;
            }
            syncWorkflowDraftJsonFromBuilder(true);
        }

        function toggleWorkflowRuntimeVar(index, name) {
            const step = workflowBuilderSteps[index];
            if (!step) {
                return;
            }
            const currentValue = step.inputs[name];
            if (typeof currentValue === 'string' && currentValue.startsWith('{{inputs.')) {
                delete step.inputs[name];
            } else {
                step.inputs[name] = `{{inputs.${name}}}`;
            }
            renderWorkflowEditBuilder();
            syncWorkflowDraftJsonFromBuilder(true);
        }

        function setWorkflowStepTargetMode(index, mode) {
            const step = workflowBuilderSteps[index];
            if (!step) {
                return;
            }
            if (mode === 'inherit') {
                step.target = { mode: 'inherit', targetId: '', fromEnvironment: '', envId: '' };
            } else if (step.target.mode === 'inherit') {
                step.target = { mode: 'targetId', targetId: '', fromEnvironment: '', envId: '' };
            }
            renderWorkflowEditBuilder();
            syncWorkflowDraftJsonFromBuilder(true);
        }

        function selectWorkflowStepTarget(index, mode, targetId) {
            const step = workflowBuilderSteps[index];
            if (!step) {
                return;
            }
            if (mode === 'fromEnvironment') {
                step.target = { mode: 'fromEnvironment', targetId: '', fromEnvironment: '', envId: workflowBuilderEnvironment || '' };
            } else {
                step.target = { mode: 'targetId', targetId: targetId || '', fromEnvironment: '', envId: '' };
            }
            renderWorkflowEditBuilder();
            syncWorkflowDraftJsonFromBuilder(true);
        }

        function selectWorkflowStepEnvironment(index, environmentId) {
            const step = workflowBuilderSteps[index];
            if (!step) {
                return;
            }
            step.target.envId = environmentId;
            step.target.fromEnvironment = '';
            renderWorkflowEditBuilder();
            syncWorkflowDraftJsonFromBuilder(true);
        }

        function selectWorkflowStepEnvironmentKey(index, key) {
            const step = workflowBuilderSteps[index];
            if (!step) {
                return;
            }
            step.target.fromEnvironment = key;
            renderWorkflowEditBuilder();
            syncWorkflowDraftJsonFromBuilder(true);
        }

        function renderWorkflowConnectorHtml(stepIndex) {
            const mode = getWorkflowConnectorMode(stepIndex);
            const labels = {
                success: '<i class="fas fa-check"></i> after success',
                always: '<i class="fas fa-bolt"></i> always',
                parallel: '<i class="fas fa-code-branch"></i> parallel',
            };
            return `<div class="step-conn">
                <div class="step-conn-line"></div>
                <button class="connector-pill ${mode}" type="button" id="workflow-connector-pill-${stepIndex}" onclick="openWorkflowConnectorPopover(${stepIndex}, event)">
                    ${labels[mode] || labels.success}
                </button>
                <div class="step-conn-line"></div>
            </div>`;
        }

        function getWorkflowConnectorMode(stepIndex) {
            const current = workflowBuilderSteps[stepIndex];
            const next = workflowBuilderSteps[stepIndex + 1];
            if (!current || !next) {
                return 'success';
            }
            const dependsOnPrevious = next.dependsOn.includes(current.id);
            if (!dependsOnPrevious) {
                return 'parallel';
            }
            return current.onFailure === 'continue' ? 'always' : 'success';
        }

        function setWorkflowConnectorMode(stepIndex, mode) {
            const current = workflowBuilderSteps[stepIndex];
            const next = workflowBuilderSteps[stepIndex + 1];
            if (!current || !next) {
                return;
            }
            if (mode === 'parallel') {
                next.dependsOn = next.dependsOn.filter((dependencyId) => dependencyId !== current.id);
            } else {
                if (!next.dependsOn.includes(current.id)) {
                    next.dependsOn.push(current.id);
                }
                current.onFailure = mode === 'always' ? 'continue' : 'stop';
            }
            renderWorkflowEditBuilder();
            syncWorkflowDraftJsonFromBuilder(true);
        }

        function openWorkflowConnectorPopover(stepIndex, event) {
            event.stopPropagation();
            workflowConnectorStepIndex = stepIndex;
            const mode = getWorkflowConnectorMode(stepIndex);
            workflowConnectorPopoverEl?.querySelectorAll('.cpm-option').forEach((button) => {
                button.classList.toggle('active', button.dataset.mode === mode);
            });
            const rect = event.currentTarget.getBoundingClientRect();
            if (workflowConnectorPopoverEl) {
                workflowConnectorPopoverEl.style.top = `${rect.bottom + 8}px`;
                workflowConnectorPopoverEl.style.left = `${Math.max(8, rect.left - 140 + rect.width / 2)}px`;
                workflowConnectorPopoverEl.classList.add('open');
            }
        }

        function closeWorkflowConnectorPopover() {
            workflowConnectorPopoverEl?.classList.remove('open');
            workflowConnectorStepIndex = -1;
        }

        function handleWorkflowBuilderGlobalClick(event) {
            if (workflowConnectorPopoverEl?.classList.contains('open') && !workflowConnectorPopoverEl.contains(event.target) && !event.target.closest('.connector-pill')) {
                closeWorkflowConnectorPopover();
            }
        }

        function renderProjectList() {
            const projects = getProjects();
            newProjectBtn.innerHTML = `<i class="fas fa-plus"></i> New ${studioState.type === 'template' ? 'template' : 'workflow'}`;

            if (!projects.length) {
                projectListEl.innerHTML = `<div class="studio-list-empty">No ${studioState.type}s yet.</div>`;
                return;
            }

            projectListEl.innerHTML = projects.map((project) => {
                const runtimeId = studioState.type === 'template'
                    ? escapeHtml(project.templateId || project.draft?.id || '')
                    : escapeHtml(project.commandId || project.draft?.id || '');
                const updatedAt = project.updatedAt ? escapeHtml(formatDate(project.updatedAt)) : 'Just now';
                const activeClass = project.id === studioState.selectedProjectId ? 'active' : '';
                return `
                    <button type="button" class="studio-list-item ${activeClass}" data-project-id="${escapeHtml(project.id)}">
                        <div class="studio-list-row">
                            <div class="studio-list-title">${escapeHtml(project.name || 'Untitled')}</div>
                            <span class="studio-badge ${escapeHtml(project.status || 'draft')}">${escapeHtml(formatProjectStatus(project.status || 'draft'))}</span>
                        </div>
                        <div class="studio-list-meta">${runtimeId || 'No runtime ID yet'}</div>
                        <div class="studio-list-meta">Updated ${updatedAt}</div>
                    </button>
                `;
            }).join('');

            projectListEl.querySelectorAll('[data-project-id]').forEach((button) => {
                button.addEventListener('click', () => {
                    const project = getProjects().find((item) => item.id === button.dataset.projectId);
                    if (project) {
                        loadDraft(project);
                    }
                });
            });
        }

        function buildValidationMessageHtml(validation) {
            if (!validation) {
                return '';
            }

            const items = [];
            items.push(`<div class="studio-validation-item ${validation.valid ? '' : 'error'}"><strong>${validation.valid ? 'Validation passed' : 'Validation blocked publish'}</strong><div class="studio-version-meta">${escapeHtml(formatDate(validation.validatedAt || new Date().toISOString()))}</div></div>`);
            (validation.errors || []).forEach((error) => {
                items.push(`<div class="studio-validation-item error">${escapeHtml(error)}</div>`);
            });
            (validation.warnings || []).forEach((warning) => {
                items.push(`<div class="studio-validation-item warning">${escapeHtml(warning)}</div>`);
            });

            return `
                <div class="studio-ai-meta" style="margin-top:12px;">Validation</div>
                <div class="studio-validation-list">${items.join('')}</div>
            `;
        }

        function buildVersionMessageHtml(versions) {
            if (!Array.isArray(versions) || !versions.length) {
                return '';
            }

            return `
                <div class="studio-ai-meta" style="margin-top:12px;">Versions</div>
                <div class="studio-version-list">
                    ${versions.slice(0, 6).map((version) => `
                        <div class="studio-version-item">
                            <strong>v${escapeHtml(String(version.versionNumber || 0))}</strong> · ${escapeHtml(capitalize(version.source || 'manual-save'))}
                            <div class="studio-version-meta">${escapeHtml(formatDate(version.createdAt || new Date().toISOString()))}</div>
                        </div>
                    `).join('')}
                </div>
            `;
        }

        function renderUsePanel() {
            const draft = readDraftJson(false);
            if (!draft) {
                useSummaryEl.innerHTML = '<div class="studio-empty-block">The project JSON is invalid right now. Switch to Edit, fix it, then come back to Preview to test it.</div>';
                useInputsEl.innerHTML = '';
                useRunBtn.disabled = true;
                useDryRunBtn.disabled = true;
                useStatusLineEl.textContent = 'Fix the project JSON in Edit mode first.';
                dryRunPanelEl.hidden = true;
                return;
            }

            if (studioState.type === 'template') {
                useSummaryEl.innerHTML = buildTemplateUseHtml(draft);
                useInputsEl.innerHTML = '<div class="studio-empty-block">Templates are not run directly here. Publish the template, then use it from a workflow or a runtime command.</div>';
                useRunBtn.disabled = true;
                useDryRunBtn.disabled = true;
                useStatusLineEl.textContent = 'Templates are authored here and executed through workflows later.';
                dryRunPanelEl.hidden = true;
                return;
            }

            const contract = getWorkflowInputContract(draft);
            syncUseInputs(contract, draft.inputs || {});
            useSummaryEl.innerHTML = buildWorkflowUseHtml(draft, contract);
            renderUseInputs(contract, draft.inputs || {});
            useRunBtn.disabled = false;
            useDryRunBtn.disabled = false;
            if (!studioState.lastDryRunResult) {
                useStatusLineEl.textContent = 'Ready to test this workflow.';
            }
            dryRunPanelEl.hidden = !studioState.lastDryRunResult;
        }

        function renderDryRunResult(result) {
            if (studioState.type === 'template') {
                dryRunPanelEl.hidden = true;
                dryRunResultEl.innerHTML = '<div class="studio-empty-block">Workflow execution results appear here. Templates are authored in Studio, then executed through workflows once published.</div>';
                return;
            }

            if (!result) {
                dryRunPanelEl.hidden = true;
                dryRunResultEl.innerHTML = '';
                return;
            }

            dryRunPanelEl.hidden = false;

            const statusValue = String(result.status || 'completed').toLowerCase();
            const status = escapeHtml(capitalize(statusValue || 'completed'));
            const exitCode = Number.isFinite(Number(result.exitCode)) ? String(result.exitCode) : 'n/a';
            const jobId = escapeHtml(result.jobId || '');
            const output = escapeHtml(String(result.output || '').trim() || 'No execution output was returned.');
            const historyLink = initialStudioState.links?.history || '../history.php';
            const statusTone = statusValue === 'failed' ? 'invalid' : statusValue === 'cancelled' ? 'archived' : statusValue === 'pending' || statusValue === 'running' ? 'draft' : 'valid';
            const steps = Array.isArray(result.steps) ? result.steps : [];

            dryRunResultEl.innerHTML = `
                <div class="studio-result-grid">
                    <div class="studio-result-card">
                        <strong>Status</strong>
                        <div class="studio-version-meta"><span class="studio-badge ${statusTone}">${status}</span>${result.dryRun ? ' · dry run' : ''}</div>
                    </div>
                    <div class="studio-result-card">
                        <strong>Exit code</strong>
                        <div class="studio-version-meta">${escapeHtml(exitCode)}</div>
                    </div>
                    <div class="studio-result-card">
                        <strong>Job</strong>
                        <div class="studio-version-meta">${jobId || 'Not recorded'}</div>
                    </div>
                </div>
                <div class="studio-runtime-item">
                    <strong>History</strong>
                    <div class="studio-version-meta"><a class="studio-link-inline" href="${escapeHtml(historyLink)}">Open run history</a></div>
                </div>
                <pre class="studio-output">${output}</pre>
                ${steps.length ? `<div class="studio-use-result-steps">${steps.map(renderResultStep).join('')}</div>` : ''}
            `;
        }

        function buildWorkflowUseHtml(draft, contract) {
            const name = projectNameEl.value.trim() || draft.name || 'Untitled workflow';
            const description = projectDescriptionEl.value.trim() || draft.description || 'No description yet.';
            const steps = Array.isArray(draft.steps) ? draft.steps : [];
            const stepRows = steps.length
                ? steps.map((step, index) => {
                    const target = formatTargetRef(step.target);
                    const dependsOn = Array.isArray(step.dependsOn) ? step.dependsOn.filter(Boolean) : [];
                    const chips = [];
                    if (target && target !== 'inherit' && target !== 'None') {
                        chips.push(`<span class="studio-step-chip">Target: ${escapeHtml(target)}</span>`);
                    }
                    if (dependsOn.length) {
                        chips.push(`<span class="studio-step-chip">After: ${escapeHtml(dependsOn.join(', '))}</span>`);
                    }
                    return `
                        <div class="studio-step-item">
                            <div class="studio-step-index">${index + 1}</div>
                            <div class="studio-step-main">
                                <div class="studio-step-title-row">
                                    <div class="studio-step-name">${escapeHtml(step.id || `step-${index + 1}`)}</div>
                                    <span class="studio-badge draft">${escapeHtml(capitalize(step.onFailure || 'stop'))}</span>
                                </div>
                                <div class="studio-step-subtitle">${escapeHtml(templateById[String(step.templateId || '').trim()]?.name || step.templateId || 'No template selected')}</div>
                                ${chips.length ? `<div class="studio-step-chips">${chips.join('')}</div>` : ''}
                            </div>
                        </div>
                    `;
                }).join('')
                : '<div class="studio-empty-block">No steps yet.</div>';

            return `
                <div class="studio-preview-hero">
                    <div>
                        <div class="studio-preview-title">${escapeHtml(name)}</div>
                        <div class="studio-preview-copy">${escapeHtml(description)}</div>
                    </div>
                    <span class="studio-preview-chip">${escapeHtml(String(steps.length))} step${steps.length === 1 ? '' : 's'}</span>
                </div>
                ${contract.length ? `<div class="studio-version-meta">${escapeHtml(String(contract.length))} runtime input${contract.length === 1 ? '' : 's'}</div>` : ''}
                <div class="studio-step-list">${stepRows}</div>
            `;
        }

        function buildTemplateUseHtml(draft) {
            const name = projectNameEl.value.trim() || draft.name || 'Untitled template';
            const description = projectDescriptionEl.value.trim() || draft.description || 'No description yet.';
            const inputs = Array.isArray(draft.inputs) ? draft.inputs : [];
            const runtimeImage = draft.runtime?.image || 'No image set';
            const executionMode = draft.execution?.mode || 'sync';
            const inputRows = inputs.length
                ? inputs.map((input, index) => `
                    <div class="studio-step-item">
                        <div class="studio-step-index">${index + 1}</div>
                        <div class="studio-step-main">
                            <div class="studio-step-name">${escapeHtml(input.name || 'input')}</div>
                            <div class="studio-step-subtitle">${escapeHtml(input.type || 'string')}${input.required ? ' · required' : ''}</div>
                        </div>
                    </div>
                `).join('')
                : '<div class="studio-empty-block">No template inputs yet.</div>';

            return `
                <div class="studio-preview-hero">
                    <div>
                        <div class="studio-preview-title">${escapeHtml(name)}</div>
                        <div class="studio-preview-copy">${escapeHtml(description)}</div>
                    </div>
                    <span class="studio-badge draft">Template</span>
                </div>
                <div class="studio-preview-meta">
                    <span class="studio-preview-chip">Image: ${escapeHtml(runtimeImage)}</span>
                    <span class="studio-preview-chip">Mode: ${escapeHtml(capitalize(executionMode))}</span>
                    <span class="studio-preview-chip">Inputs: ${escapeHtml(String(inputs.length))}</span>
                </div>
                <div class="studio-step-list">${inputRows}</div>
            `;
        }

        function renderResultStep(step) {
            const stepId = escapeHtml(step.stepId || step.step_id || step.id || 'step');
            const templateId = String(step.templateId || step.template_id || '').trim();
            const templateName = escapeHtml(templateById[templateId]?.name || templateId || 'template');
            const statusValue = String(step.status || 'pending').toLowerCase();
            const exitCode = step.exitCode ?? step.exit_code;
            const error = escapeHtml(step.error || '');
            const output = String(step.output || '').trim();
            return `
                <div class="studio-use-step">
                    <div class="studio-use-step-head">
                        <div>
                            <div class="studio-preview-title" style="font-size:16px;">${stepId}</div>
                            <div class="studio-version-meta">${templateName}</div>
                        </div>
                        <span class="studio-badge ${statusValue === 'failed' ? 'invalid' : statusValue === 'pending' || statusValue === 'running' ? 'draft' : 'valid'}">${escapeHtml(capitalize(statusValue))}</span>
                    </div>
                    <div class="studio-version-meta">${exitCode !== undefined && exitCode !== null ? `Exit: ${escapeHtml(String(exitCode))}` : 'Exit code pending'}${error ? ` · ${error}` : ''}</div>
                    ${output ? `<pre class="studio-output">${escapeHtml(output)}</pre>` : ''}
                </div>
            `;
        }

        function resetAiConversation(render = true) {
            studioState.aiMessages = [];
            studioState.lastAiResult = null;
            studioState.pendingAiDraftMessageId = '';
            studioState.aiDiffMessageId = '';
            closeAiDiffModal();
            if (aiPromptEl) {
                aiPromptEl.value = '';
            }
            if (render) {
                renderAiMessages();
                renderAiPlan();
            }
        }

        function appendAiMessage(role, content, meta = {}) {
            const message = {
                id: `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
                role: role === 'assistant' ? 'assistant' : 'user',
                content: String(content || '').trim(),
                title: meta.title ? String(meta.title) : '',
                notes: Array.isArray(meta.notes) ? meta.notes.map((note) => String(note || '').trim()).filter(Boolean) : [],
                providerName: meta.providerName ? String(meta.providerName) : '',
                modelName: meta.modelName ? String(meta.modelName) : '',
                applied: !!meta.applied,
                reverted: !!meta.reverted,
                validation: meta.validation || null,
                validationBefore: meta.validationBefore || null,
                versions: Array.isArray(meta.versions) ? meta.versions : [],
                actions: Array.isArray(meta.actions) ? meta.actions : [],
                draftBefore: meta.draftBefore && typeof meta.draftBefore === 'object' ? deepClone(meta.draftBefore) : null,
                draftAfter: meta.draftAfter && typeof meta.draftAfter === 'object' ? deepClone(meta.draftAfter) : null,
            };
            studioState.aiMessages.push(message);
            return message;
        }

        function getAiMessageById(messageId) {
            return (Array.isArray(studioState.aiMessages) ? studioState.aiMessages : [])
                .find((message) => String(message?.id || '') === String(messageId || '')) || null;
        }

        function getPendingAiDraftMessage() {
            const message = getAiMessageById(studioState.pendingAiDraftMessageId || '');
            if (!message || !message.draftAfter || typeof message.draftAfter !== 'object') {
                return null;
            }
            return message;
        }

        function normalizeDraftForDiff(value) {
            if (Array.isArray(value)) {
                return value.map((item) => normalizeDraftForDiff(item));
            }

            if (value && typeof value === 'object') {
                const normalized = {};
                Object.keys(value)
                    .sort((left, right) => left.localeCompare(right))
                    .forEach((key) => {
                        normalized[key] = normalizeDraftForDiff(value[key]);
                    });
                return normalized;
            }

            return value;
        }

        function serializeDraftForDiff(draft) {
            return JSON.stringify(normalizeDraftForDiff(draft && typeof draft === 'object' ? draft : {}), null, 2);
        }

        function draftsEqual(left, right) {
            return serializeDraftForDiff(left) === serializeDraftForDiff(right);
        }

        function createAiDiffSide(type, text, lineNumber) {
            return {
                type,
                text: String(text || ''),
                lineNumber: Number.isFinite(Number(lineNumber)) ? Number(lineNumber) : '',
                marker: type === 'delete' ? '-' : (type === 'add' ? '+' : ' '),
            };
        }

        function applyDraftToEditor(draft, validation = null) {
            if (!draft || typeof draft !== 'object') {
                throw new Error('No draft to apply');
            }

            projectNameEl.value = draft.name || projectNameEl.value;
            runtimeIdEl.value = draft.id || runtimeIdEl.value;
            projectDescriptionEl.value = draft.description || projectDescriptionEl.value;
            draftJsonEl.value = JSON.stringify(draft || {}, null, 2);
            draftJsonEl.dataset.lastSerialized = draftJsonEl.value;

            if (studioState.type === 'workflow') {
                loadWorkflowBuilderDraft(draft || {});
                syncWorkflowDraftJsonFromBuilder(false);
            }

            studioState.currentValidation = validation && typeof validation === 'object'
                ? deepClone(validation)
                : null;
            studioState.lastDryRunResult = null;
            studioState.activeJobId = null;
            studioState.activeJobIsDryRun = false;
            clearJobPoll();
            setStatusBadge('draft');
            studioState.isDirty = true;
            renderUsePanel();
            renderDryRunResult(studioState.lastDryRunResult);
        }

        function buildAiDiffPairs(diff) {
            const pairs = [];
            let oldLine = 1;
            let newLine = 1;
            let index = 0;

            while (index < diff.length) {
                const entry = diff[index];
                if (entry.type === 'context') {
                    pairs.push({
                        left: createAiDiffSide('context', entry.text, oldLine),
                        right: createAiDiffSide('context', entry.text, newLine),
                    });
                    oldLine += 1;
                    newLine += 1;
                    index += 1;
                    continue;
                }

                const leftRun = [];
                const rightRun = [];
                while (index < diff.length && diff[index].type !== 'context') {
                    if (diff[index].type === 'delete') {
                        leftRun.push(createAiDiffSide('delete', diff[index].text, oldLine));
                        oldLine += 1;
                    } else if (diff[index].type === 'add') {
                        rightRun.push(createAiDiffSide('add', diff[index].text, newLine));
                        newLine += 1;
                    }
                    index += 1;
                }

                const rowCount = Math.max(leftRun.length, rightRun.length);
                for (let rowIndex = 0; rowIndex < rowCount; rowIndex += 1) {
                    pairs.push({
                        left: leftRun[rowIndex] || null,
                        right: rightRun[rowIndex] || null,
                    });
                }
            }

            return pairs;
        }

        function renderAiDiffCell(side, position) {
            if (!side) {
                return `
                    <div class="studio-diff-cell ${escapeHtml(position)} empty">
                        <span class="studio-diff-cell-num"></span>
                        <span class="studio-diff-cell-mark"></span>
                        <span class="studio-diff-cell-text"></span>
                    </div>
                `;
            }

            return `
                <div class="studio-diff-cell ${escapeHtml(position)} ${escapeHtml(side.type)}">
                    <span class="studio-diff-cell-num">${escapeHtml(side.lineNumber)}</span>
                    <span class="studio-diff-cell-mark">${escapeHtml(side.marker)}</span>
                    <span class="studio-diff-cell-text">${escapeHtml(side.text)}</span>
                </div>
            `;
        }

        function buildAiDiffRows(diff) {
            const pairs = buildAiDiffPairs(diff);
            const changedIndexes = [];

            pairs.forEach((pair, index) => {
                if ((pair.left && pair.left.type !== 'context') || (pair.right && pair.right.type !== 'context')) {
                    changedIndexes.push(index);
                }
            });

            if (!changedIndexes.length) {
                return '';
            }

            const ranges = [];
            const contextWindow = 2;
            changedIndexes.forEach((index) => {
                const start = Math.max(0, index - contextWindow);
                const end = Math.min(pairs.length - 1, index + contextWindow);
                const previous = ranges[ranges.length - 1];
                if (!previous || start > previous.end + 1) {
                    ranges.push({ start, end });
                } else {
                    previous.end = Math.max(previous.end, end);
                }
            });

            const rows = [
                '<div class="studio-diff-content-inner"><div class="studio-diff-grid">',
                '<div class="studio-diff-header"><div class="studio-diff-header-cell">Current</div><div class="studio-diff-divider-spacer" aria-hidden="true"></div><div class="studio-diff-header-cell">Proposed</div></div>',
            ];

            ranges.forEach((range, rangeIndex) => {
                if (rangeIndex > 0) {
                    rows.push(`
                        <div class="studio-diff-gap">
                            <div class="studio-diff-gap-cell">...</div>
                            <div class="studio-diff-gap-divider" aria-hidden="true"></div>
                            <div class="studio-diff-gap-cell right">unchanged lines omitted</div>
                        </div>
                    `);
                }

                for (let index = range.start; index <= range.end; index += 1) {
                    const pair = pairs[index];
                    rows.push(`
                        <div class="studio-diff-row">
                            ${renderAiDiffCell(pair.left, 'left')}
                            <div class="studio-diff-divider-spacer" aria-hidden="true"></div>
                            ${renderAiDiffCell(pair.right, 'right')}
                        </div>
                    `);
                }
            });

            rows.push('</div></div>');
            return rows.join('');
        }

        function diffDraftLines(oldText, newText) {
            const oldLines = String(oldText || '').replace(/\r\n/g, '\n').split('\n');
            const newLines = String(newText || '').replace(/\r\n/g, '\n').split('\n');
            if (oldLines.length && oldLines[oldLines.length - 1] === '') oldLines.pop();
            if (newLines.length && newLines[newLines.length - 1] === '') newLines.pop();

            if (oldLines.length === 0 && newLines.length === 0) {
                return [];
            }

            return diffDraftLinesPatience(oldLines, newLines);
        }

        function diffDraftLinesPatience(oldLines, newLines) {
            return diffDraftLinesPatienceRange(oldLines, 0, oldLines.length, newLines, 0, newLines.length);
        }

        function diffDraftLinesPatienceRange(oldLines, oldStart, oldEnd, newLines, newStart, newEnd) {
            const diff = [];

            while (oldStart < oldEnd && newStart < newEnd && oldLines[oldStart] === newLines[newStart]) {
                diff.push({ type: 'context', text: oldLines[oldStart] });
                oldStart += 1;
                newStart += 1;
            }

            const suffix = [];
            while (oldStart < oldEnd && newStart < newEnd && oldLines[oldEnd - 1] === newLines[newEnd - 1]) {
                suffix.unshift({ type: 'context', text: oldLines[oldEnd - 1] });
                oldEnd -= 1;
                newEnd -= 1;
            }

            if (oldStart === oldEnd && newStart === newEnd) {
                return diff.concat(suffix);
            }

            const anchors = findPatienceAnchors(oldLines, oldStart, oldEnd, newLines, newStart, newEnd);
            if (!anchors.length) {
                return diff
                    .concat(diffDraftLinesLcs(oldLines.slice(oldStart, oldEnd), newLines.slice(newStart, newEnd)))
                    .concat(suffix);
            }

            let oldCursor = oldStart;
            let newCursor = newStart;
            anchors.forEach((anchor) => {
                diff.push(...diffDraftLinesPatienceRange(oldLines, oldCursor, anchor.oldIndex, newLines, newCursor, anchor.newIndex));
                diff.push({ type: 'context', text: oldLines[anchor.oldIndex] });
                oldCursor = anchor.oldIndex + 1;
                newCursor = anchor.newIndex + 1;
            });

            diff.push(...diffDraftLinesPatienceRange(oldLines, oldCursor, oldEnd, newLines, newCursor, newEnd));
            return diff.concat(suffix);
        }

        function findPatienceAnchors(oldLines, oldStart, oldEnd, newLines, newStart, newEnd) {
            const oldCounts = new Map();
            const newCounts = new Map();

            for (let index = oldStart; index < oldEnd; index += 1) {
                const line = oldLines[index];
                const current = oldCounts.get(line);
                if (current) {
                    current.count += 1;
                } else {
                    oldCounts.set(line, { count: 1, index });
                }
            }

            for (let index = newStart; index < newEnd; index += 1) {
                const line = newLines[index];
                const current = newCounts.get(line);
                if (current) {
                    current.count += 1;
                } else {
                    newCounts.set(line, { count: 1, index });
                }
            }

            const candidates = [];
            oldCounts.forEach((oldMeta, line) => {
                const newMeta = newCounts.get(line);
                if (oldMeta.count === 1 && newMeta && newMeta.count === 1) {
                    candidates.push({ oldIndex: oldMeta.index, newIndex: newMeta.index });
                }
            });

            candidates.sort((left, right) => left.oldIndex - right.oldIndex);
            return longestIncreasingPairs(candidates);
        }

        function longestIncreasingPairs(pairs) {
            if (!pairs.length) {
                return [];
            }

            const predecessors = new Array(pairs.length).fill(-1);
            const tails = [];

            pairs.forEach((pair, index) => {
                let low = 0;
                let high = tails.length;
                while (low < high) {
                    const middle = Math.floor((low + high) / 2);
                    if (pairs[tails[middle]].newIndex < pair.newIndex) {
                        low = middle + 1;
                    } else {
                        high = middle;
                    }
                }

                if (low > 0) {
                    predecessors[index] = tails[low - 1];
                }
                tails[low] = index;
            });

            const sequence = [];
            let cursor = tails[tails.length - 1];
            while (cursor !== undefined && cursor !== -1) {
                sequence.push(pairs[cursor]);
                cursor = predecessors[cursor];
            }

            return sequence.reverse();
        }

        function diffDraftLinesLcs(oldLines, newLines) {
            if (oldLines.length === 0 && newLines.length === 0) {
                return [];
            }

            const maxCells = 250000;
            if ((oldLines.length * newLines.length) > maxCells) {
                return diffDraftLinesFallback(oldLines, newLines);
            }

            const rows = oldLines.length + 1;
            const cols = newLines.length + 1;
            const dp = Array.from({ length: rows }, () => new Uint32Array(cols));

            for (let i = oldLines.length - 1; i >= 0; i -= 1) {
                for (let j = newLines.length - 1; j >= 0; j -= 1) {
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
                    i += 1;
                    j += 1;
                } else if (dp[i + 1][j] >= dp[i][j + 1]) {
                    diff.push({ type: 'delete', text: oldLines[i] });
                    i += 1;
                } else {
                    diff.push({ type: 'add', text: newLines[j] });
                    j += 1;
                }
            }

            while (i < oldLines.length) {
                diff.push({ type: 'delete', text: oldLines[i] });
                i += 1;
            }
            while (j < newLines.length) {
                diff.push({ type: 'add', text: newLines[j] });
                j += 1;
            }

            return diff;
        }

        function diffDraftLinesFallback(oldLines, newLines) {
            let prefix = 0;
            while (
                prefix < oldLines.length
                && prefix < newLines.length
                && oldLines[prefix] === newLines[prefix]
            ) {
                prefix += 1;
            }

            let oldSuffix = oldLines.length - 1;
            let newSuffix = newLines.length - 1;
            while (
                oldSuffix >= prefix
                && newSuffix >= prefix
                && oldLines[oldSuffix] === newLines[newSuffix]
            ) {
                oldSuffix -= 1;
                newSuffix -= 1;
            }

            const diff = [];
            for (let index = 0; index < prefix; index += 1) {
                diff.push({ type: 'context', text: oldLines[index] });
            }
            for (let index = prefix; index <= oldSuffix; index += 1) {
                diff.push({ type: 'delete', text: oldLines[index] });
            }
            for (let index = prefix; index <= newSuffix; index += 1) {
                diff.push({ type: 'add', text: newLines[index] });
            }
            for (let index = oldSuffix + 1; index < oldLines.length; index += 1) {
                diff.push({ type: 'context', text: oldLines[index] });
            }

            return diff;
        }

        function serializeSmartDiffValue(value) {
            return JSON.stringify(normalizeDraftForDiff(value));
        }

        function smartDiffValuesEqual(left, right) {
            return serializeSmartDiffValue(left) === serializeSmartDiffValue(right);
        }

        function describeTemplateForSmartDiff(templateId) {
            const value = String(templateId || '').trim();
            if (!value) {
                return '(none)';
            }
            const template = templateById[value];
            return template?.name ? `${template.name} (${value})` : value;
        }

        function describeTargetRefForSmartDiff(raw, inheritLabel = 'Ask each time') {
            const ref = parseWorkflowTargetRef(raw);
            if (ref.mode === 'inherit') {
                return inheritLabel;
            }
            if (ref.mode === 'targetId') {
                const target = targetById[String(ref.targetId || '').trim()];
                if (!target) {
                    return String(ref.targetId || '').trim() || '(none)';
                }
                const type = String(target.type || '').trim();
                return type ? `${target.name} (${type})` : String(target.name || ref.targetId || '');
            }
            return ref.fromEnvironment ? `Environment target "${ref.fromEnvironment}"` : inheritLabel;
        }

        function describeInputContractEntryForSmartDiff(entry) {
            const item = isPlainObject(entry) ? entry : {};
            return {
                name: String(item.name || '').trim(),
                label: String(item.label || '').trim(),
                type: String(item.type || 'string').trim(),
                required: !!item.required,
                default: item.default ?? '',
                description: String(item.description || '').trim(),
                options: Array.isArray(item.options) ? item.options : [],
            };
        }

        function describeStepForSmartDiff(step) {
            const item = isPlainObject(step) ? step : {};
            return {
                template: describeTemplateForSmartDiff(item.templateId),
                target: describeTargetRefForSmartDiff(item.target, 'Workflow default'),
                dependsOn: Array.isArray(item.dependsOn) ? item.dependsOn.map(String).filter(Boolean) : [],
                onFailure: String(item.onFailure || 'stop'),
                inputs: isPlainObject(item.inputs) ? item.inputs : {},
                secrets: isPlainObject(item.secrets) ? item.secrets : {},
            };
        }

        function createSmartDiffItem(kind, title, beforeValue, afterValue, detail = '') {
            return {
                kind: ['added', 'removed', 'changed', 'moved'].includes(kind) ? kind : 'changed',
                title: String(title || '').trim(),
                beforeValue,
                afterValue,
                detail: String(detail || '').trim(),
            };
        }

        function renderSmartDiffValue(value, emptyLabel) {
            if (value === undefined) {
                return `<div class="studio-smart-diff-placeholder">${escapeHtml(emptyLabel || 'No value')}</div>`;
            }
            if (value === null) {
                return '<pre class="studio-smart-diff-code">null</pre>';
            }
            if (typeof value === 'string') {
                if (value === '') {
                    return `<div class="studio-smart-diff-placeholder">${escapeHtml(emptyLabel || '(empty)')}</div>`;
                }
                return `<pre class="studio-smart-diff-code">${escapeHtml(value)}</pre>`;
            }
            return `<pre class="studio-smart-diff-code">${escapeHtml(JSON.stringify(value, null, 2))}</pre>`;
        }

        function renderWorkflowSmartDiff(smartData) {
            if (!smartData || !Array.isArray(smartData.sections) || !smartData.sections.length) {
                return '<div class="studio-diff-empty">No workflow-aware changes detected.</div>';
            }

            const badges = [
                ['added', 'Added'],
                ['removed', 'Removed'],
                ['changed', 'Changed'],
                ['moved', 'Moved'],
            ].filter(([key]) => Number(smartData.summary?.[key] || 0) > 0)
                .map(([key, label]) => `
                    <span class="studio-smart-diff-summary-badge ${escapeHtml(key)}">
                        ${escapeHtml(label)}: ${escapeHtml(String(smartData.summary[key]))}
                    </span>
                `)
                .join('');

            return `
                <div class="studio-smart-diff">
                    <div class="studio-smart-diff-summary">${badges}</div>
                    ${smartData.sections.map((section) => `
                        <section class="studio-smart-diff-section">
                            <div class="studio-smart-diff-section-head">
                                <div class="studio-smart-diff-section-title">${escapeHtml(section.title || 'Changes')}</div>
                                <div class="studio-smart-diff-section-count">${escapeHtml(String((section.items || []).length))} change${(section.items || []).length === 1 ? '' : 's'}</div>
                            </div>
                            <div class="studio-smart-diff-items">
                                ${(section.items || []).map((item) => `
                                    <div class="studio-smart-diff-item">
                                        <div class="studio-smart-diff-item-head">
                                            <span class="studio-smart-diff-item-badge ${escapeHtml(item.kind || 'changed')}">${escapeHtml(item.kind || 'changed')}</span>
                                            <div class="studio-smart-diff-item-title">${escapeHtml(item.title || 'Change')}</div>
                                        </div>
                                        ${item.detail ? `<div class="studio-smart-diff-item-copy">${escapeHtml(item.detail)}</div>` : ''}
                                        <div class="studio-smart-diff-item-grid">
                                            <div class="studio-smart-diff-panel">
                                                <div class="studio-smart-diff-panel-label">Before</div>
                                                ${renderSmartDiffValue(item.beforeValue, item.kind === 'added' ? 'Not present' : 'No value')}
                                            </div>
                                            <div class="studio-smart-diff-panel">
                                                <div class="studio-smart-diff-panel-label">After</div>
                                                ${renderSmartDiffValue(item.afterValue, item.kind === 'removed' ? 'Removed' : 'No value')}
                                            </div>
                                        </div>
                                    </div>
                                `).join('')}
                            </div>
                        </section>
                    `).join('')}
                </div>
            `;
        }

        function addWorkflowObjectFieldSmartDiff(section, titlePrefix, beforeObject, afterObject) {
            const beforeValue = isPlainObject(beforeObject) ? beforeObject : {};
            const afterValue = isPlainObject(afterObject) ? afterObject : {};
            const keys = Array.from(new Set([
                ...Object.keys(beforeValue),
                ...Object.keys(afterValue),
            ])).sort((left, right) => left.localeCompare(right));

            keys.forEach((key) => {
                const hasBefore = Object.prototype.hasOwnProperty.call(beforeValue, key);
                const hasAfter = Object.prototype.hasOwnProperty.call(afterValue, key);
                if (hasBefore && !hasAfter) {
                    section.items.push(createSmartDiffItem('removed', `${titlePrefix} ${key}`, beforeValue[key], undefined));
                    return;
                }
                if (!hasBefore && hasAfter) {
                    section.items.push(createSmartDiffItem('added', `${titlePrefix} ${key}`, undefined, afterValue[key]));
                    return;
                }
                if (!smartDiffValuesEqual(beforeValue[key], afterValue[key])) {
                    section.items.push(createSmartDiffItem('changed', `${titlePrefix} ${key}`, beforeValue[key], afterValue[key]));
                }
            });
        }

        function buildWorkflowSmartDiffData(beforeDraft, afterDraft) {
            const beforeWorkflow = isPlainObject(beforeDraft) ? beforeDraft : {};
            const afterWorkflow = isPlainObject(afterDraft) ? afterDraft : {};
            const sections = [];

            const workflowSection = { title: 'Workflow', items: [] };
            [
                ['id', 'Runtime ID'],
                ['name', 'Name'],
                ['description', 'Description'],
                ['executionMode', 'Execution mode'],
                ['maxParallel', 'Max parallel'],
                ['finalStatusPolicy', 'Final status policy'],
                ['environment', 'Environment'],
                ['enabled', 'Enabled'],
                ['tags', 'Tags'],
                ['icon', 'Icon'],
                ['color', 'Color'],
            ].forEach(([key, label]) => {
                if (!smartDiffValuesEqual(beforeWorkflow[key], afterWorkflow[key])) {
                    workflowSection.items.push(createSmartDiffItem('changed', label, beforeWorkflow[key], afterWorkflow[key]));
                }
            });
            if (!smartDiffValuesEqual(beforeWorkflow.defaultTarget, afterWorkflow.defaultTarget)) {
                workflowSection.items.push(createSmartDiffItem(
                    'changed',
                    'Default target',
                    describeTargetRefForSmartDiff(beforeWorkflow.defaultTarget, 'Ask each time'),
                    describeTargetRefForSmartDiff(afterWorkflow.defaultTarget, 'Ask each time')
                ));
            }
            if (workflowSection.items.length) {
                sections.push(workflowSection);
            }

            const runtimeSection = { title: 'Runtime Contract', items: [] };
            const beforeInputs = Array.isArray(beforeWorkflow.inputContract) ? beforeWorkflow.inputContract : [];
            const afterInputs = Array.isArray(afterWorkflow.inputContract) ? afterWorkflow.inputContract : [];
            const beforeInputMap = new Map(beforeInputs.map((item) => [String(item?.name || '').trim(), item]).filter(([name]) => name));
            const afterInputMap = new Map(afterInputs.map((item) => [String(item?.name || '').trim(), item]).filter(([name]) => name));
            const inputNames = Array.from(new Set([...beforeInputMap.keys(), ...afterInputMap.keys()])).sort((left, right) => left.localeCompare(right));
            inputNames.forEach((name) => {
                const beforeItem = beforeInputMap.get(name);
                const afterItem = afterInputMap.get(name);
                if (beforeItem && !afterItem) {
                    runtimeSection.items.push(createSmartDiffItem('removed', `Removed input ${name}`, describeInputContractEntryForSmartDiff(beforeItem), undefined));
                    return;
                }
                if (!beforeItem && afterItem) {
                    runtimeSection.items.push(createSmartDiffItem('added', `Added input ${name}`, undefined, describeInputContractEntryForSmartDiff(afterItem)));
                    return;
                }
                if (!smartDiffValuesEqual(beforeItem, afterItem)) {
                    runtimeSection.items.push(createSmartDiffItem('changed', `Updated input ${name}`, describeInputContractEntryForSmartDiff(beforeItem), describeInputContractEntryForSmartDiff(afterItem)));
                }
            });

            if (!smartDiffValuesEqual(beforeWorkflow.inputs, afterWorkflow.inputs)) {
                runtimeSection.items.push(createSmartDiffItem('changed', 'Runtime inputs/defaults', beforeWorkflow.inputs, afterWorkflow.inputs));
            }
            if (!smartDiffValuesEqual(beforeWorkflow.secrets, afterWorkflow.secrets)) {
                runtimeSection.items.push(createSmartDiffItem('changed', 'Secret mappings', beforeWorkflow.secrets, afterWorkflow.secrets));
            }
            if (runtimeSection.items.length) {
                sections.push(runtimeSection);
            }

            const stepSection = { title: 'Steps', items: [] };
            const beforeSteps = Array.isArray(beforeWorkflow.steps) ? beforeWorkflow.steps.filter(isPlainObject) : [];
            const afterSteps = Array.isArray(afterWorkflow.steps) ? afterWorkflow.steps.filter(isPlainObject) : [];
            const beforeStepMap = new Map(beforeSteps.map((step, index) => [String(step.id || `step-${index + 1}`), { step, index }]));
            const afterStepMap = new Map(afterSteps.map((step, index) => [String(step.id || `step-${index + 1}`), { step, index }]));
            const stepIds = Array.from(new Set([...beforeStepMap.keys(), ...afterStepMap.keys()])).sort((left, right) => {
                const leftIndex = afterStepMap.get(left)?.index ?? beforeStepMap.get(left)?.index ?? 0;
                const rightIndex = afterStepMap.get(right)?.index ?? beforeStepMap.get(right)?.index ?? 0;
                return leftIndex - rightIndex;
            });

            stepIds.forEach((stepId) => {
                const beforeEntry = beforeStepMap.get(stepId);
                const afterEntry = afterStepMap.get(stepId);
                if (beforeEntry && !afterEntry) {
                    stepSection.items.push(createSmartDiffItem(
                        'removed',
                        `Removed step ${stepId}`,
                        describeStepForSmartDiff(beforeEntry.step),
                        undefined,
                        `Template: ${describeTemplateForSmartDiff(beforeEntry.step.templateId)}`
                    ));
                    return;
                }
                if (!beforeEntry && afterEntry) {
                    stepSection.items.push(createSmartDiffItem(
                        'added',
                        `Added step ${stepId}`,
                        undefined,
                        describeStepForSmartDiff(afterEntry.step),
                        `Template: ${describeTemplateForSmartDiff(afterEntry.step.templateId)}`
                    ));
                    return;
                }

                if (beforeEntry.index !== afterEntry.index) {
                    stepSection.items.push(createSmartDiffItem(
                        'moved',
                        `Moved step ${stepId}`,
                        `Position ${beforeEntry.index + 1}`,
                        `Position ${afterEntry.index + 1}`
                    ));
                }

                if (!smartDiffValuesEqual(beforeEntry.step.templateId, afterEntry.step.templateId)) {
                    stepSection.items.push(createSmartDiffItem(
                        'changed',
                        `Updated ${stepId} template`,
                        describeTemplateForSmartDiff(beforeEntry.step.templateId),
                        describeTemplateForSmartDiff(afterEntry.step.templateId)
                    ));
                }
                if (!smartDiffValuesEqual(beforeEntry.step.target, afterEntry.step.target)) {
                    stepSection.items.push(createSmartDiffItem(
                        'changed',
                        `Updated ${stepId} target`,
                        describeTargetRefForSmartDiff(beforeEntry.step.target, 'Workflow default'),
                        describeTargetRefForSmartDiff(afterEntry.step.target, 'Workflow default')
                    ));
                }
                if (!smartDiffValuesEqual(beforeEntry.step.dependsOn, afterEntry.step.dependsOn)) {
                    stepSection.items.push(createSmartDiffItem(
                        'changed',
                        `Updated ${stepId} dependencies`,
                        Array.isArray(beforeEntry.step.dependsOn) ? beforeEntry.step.dependsOn : [],
                        Array.isArray(afterEntry.step.dependsOn) ? afterEntry.step.dependsOn : []
                    ));
                }
                if (!smartDiffValuesEqual(beforeEntry.step.onFailure, afterEntry.step.onFailure)) {
                    stepSection.items.push(createSmartDiffItem(
                        'changed',
                        `Updated ${stepId} onFailure`,
                        beforeEntry.step.onFailure ?? 'stop',
                        afterEntry.step.onFailure ?? 'stop'
                    ));
                }
                addWorkflowObjectFieldSmartDiff(stepSection, `${stepId} input`, beforeEntry.step.inputs, afterEntry.step.inputs);
                addWorkflowObjectFieldSmartDiff(stepSection, `${stepId} secret`, beforeEntry.step.secrets, afterEntry.step.secrets);
            });

            if (stepSection.items.length) {
                sections.push(stepSection);
            }

            const summary = sections.reduce((acc, section) => {
                (section.items || []).forEach((item) => {
                    acc.total += 1;
                    acc[item.kind] = (acc[item.kind] || 0) + 1;
                });
                return acc;
            }, { total: 0, added: 0, removed: 0, changed: 0, moved: 0 });

            return {
                sections,
                summary,
            };
        }

        function getAiConversationForRequest() {
            return (Array.isArray(studioState.aiMessages) ? studioState.aiMessages : [])
                .slice(-6)
                .map((message) => ({
                    role: message.role === 'assistant' ? 'assistant' : 'user',
                    content: String(message.content || '').trim(),
                }))
                .filter((message) => message.content);
        }

        function getPendingAiDiffData() {
            const message = getPendingAiDraftMessage();
            if (!message) {
                return null;
            }

            const beforeDraft = message.draftBefore && typeof message.draftBefore === 'object'
                ? deepClone(message.draftBefore)
                : {};
            const afterDraft = message.draftAfter && typeof message.draftAfter === 'object'
                ? deepClone(message.draftAfter)
                : {};
            const beforeText = serializeDraftForDiff(message.draftBefore || {});
            const afterText = serializeDraftForDiff(message.draftAfter || {});
            const rows = diffDraftLines(beforeText, afterText);
            const stats = rows.reduce((acc, row) => {
                if (row.type === 'add') acc.additions += 1;
                if (row.type === 'delete') acc.deletions += 1;
                return acc;
            }, { additions: 0, deletions: 0 });
            const smartData = studioState.type === 'workflow'
                ? buildWorkflowSmartDiffData(beforeDraft, afterDraft)
                : null;

            return {
                message,
                path: studioState.type === 'template' ? 'template.json' : 'workflow.json',
                beforeDraft,
                afterDraft,
                rows,
                stats,
                smartData,
            };
        }

        function renderAiMessages() {
            if (!aiMessagesEl) {
                renderAiPlan();
                return;
            }

            const messages = Array.isArray(studioState.aiMessages) ? studioState.aiMessages : [];
            if (!messages.length) {
                if (!studioState.aiEnabled) {
                    aiMessagesEl.innerHTML = '<div class="studio-ai-empty">Studio AI is disabled for this account.</div>';
                    renderAiPlan();
                    return;
                }
                aiMessagesEl.innerHTML = `<div class="studio-ai-empty">${studioState.aiProviders.length ? `Ask AI to create a ${studioState.type} or improve the one you are editing.` : 'No AI providers are configured yet. Add one in AI Administration first.'}</div>`;
                renderAiPlan();
                return;
            }

            aiMessagesEl.innerHTML = messages.map((message) => {
                const notes = Array.isArray(message.notes) ? message.notes : [];
                const validationHtml = buildValidationMessageHtml(message.validation);
                const versionHtml = buildVersionMessageHtml(message.versions);
                const actionHtml = renderAiActions(message);
                const metaParts = [];
                if (message.providerName) metaParts.push(message.providerName);
                if (message.modelName) metaParts.push(message.modelName);
                if (message.applied && !message.reverted) metaParts.push('Applied to editor');

                return `
                    <div class="studio-ai-message ${escapeHtml(message.role)}">
                        <div class="studio-ai-message-role">${escapeHtml(message.role === 'assistant' ? 'Studio AI' : 'You')}</div>
                        <div class="studio-ai-bubble">
                            ${message.title ? `<div class="studio-ai-title">${escapeHtml(message.title)}</div>` : ''}
                            <div>${escapeHtml(message.content || '')}</div>
                            ${notes.length ? `<ul class="studio-ai-notes">${notes.map((note) => `<li>${escapeHtml(note)}</li>`).join('')}</ul>` : ''}
                            ${validationHtml}
                            ${versionHtml}
                            ${metaParts.length ? `<div class="studio-ai-meta">${escapeHtml(metaParts.join(' · '))}</div>` : ''}
                        </div>
                        ${actionHtml}
                        ${message.reverted ? '<div class="studio-ai-actions"><span class="studio-ai-flag reverted"><i class="fas fa-rotate-left"></i> Reverted</span></div>' : ''}
                    </div>
                `;
            }).join('');

            aiMessagesEl.querySelectorAll('[data-ai-action-kind]').forEach((button) => {
                button.addEventListener('click', async () => {
                    try {
                        await handleAiMessageAction(button.dataset.aiActionKind || '', {
                            messageId: button.dataset.aiMessageId || '',
                            capability: button.dataset.aiCapability || '',
                        });
                    } catch (error) {
                        // Action handlers already surface their own status/toast messages.
                    }
                });
            });

            aiMessagesEl.scrollTop = aiMessagesEl.scrollHeight;
            renderAiPlan();
        }

        function renderAiActions(message) {
            const actions = getAiActionsForMessage(message);
            if (!actions.length) {
                return '';
            }

            return `
                <div class="studio-ai-actions">
                    ${actions.map((action) => `
                        <button
                            class="studio-btn secondary"
                            type="button"
                            data-ai-action-kind="${escapeHtml(action.kind || '')}"
                            data-ai-capability="${escapeHtml(action.capability || '')}"
                            data-ai-message-id="${escapeHtml(message.id || '')}"
                            ${studioState.aiActionBusy ? 'disabled' : ''}
                        >
                            ${escapeHtml(action.label || 'Action')}
                        </button>
                    `).join('')}
                </div>
            `;
        }

        function getAiActionsForMessage(message) {
            const actions = [];
            const seen = new Set();

            const addAction = (action) => {
                if (!action || !action.kind) {
                    return;
                }
                const key = `${action.kind}::${action.capability || ''}`;
                if (seen.has(key)) {
                    return;
                }
                seen.add(key);
                actions.push(action);
            };

            (Array.isArray(message.actions) ? message.actions : []).forEach((action) => addAction(action));

            if (message.role === 'assistant' && message.applied && !message.reverted && message.draftBefore) {
                addAction({
                    kind: 'revert-ai-draft',
                    label: 'Revert',
                });
            }

            const validation = message.validation && typeof message.validation === 'object' ? message.validation : null;
            if (studioState.type === 'workflow' && validation && !message.applied) {
                const gaps = Array.isArray(validation.capabilityGaps) ? validation.capabilityGaps : [];
                gaps.forEach((gap) => {
                    const capability = String(gap?.capability || '').trim();
                    if (!capability) {
                        return;
                    }
                    addAction({
                        kind: 'create-missing-template',
                        capability,
                        label: `Create template for ${gap?.label || formatCapabilityLabel(capability)}`,
                    });
                });
            }

            return actions;
        }

        async function handleAiMessageAction(kind, payload = {}) {
            if (kind === 'revert-ai-draft') {
                await revertAiDraftMessage(String(payload.messageId || ''));
                return;
            }

            if (kind === 'create-missing-template') {
                const capability = String(payload.capability || '').trim();
                if (!capability) {
                    return;
                }
                await createTemplateForCapabilityGap(capability);
                return;
            }

            if (kind === 'resume-workflow') {
                await resumeBlockedWorkflow();
            }
        }

        function renderAiPlan() {
            if (!aiPlanBoxEl || !aiPlanSummaryEl || !aiPlanListEl || !aiPlanNotesEl || !aiViewDiffBtn || !aiSmartDiffBtn || !aiApplyBtn || !aiClearPlanBtn) {
                return;
            }

            const diffData = getPendingAiDiffData();
            aiSmartDiffBtn.hidden = studioState.type !== 'workflow';
            if (!diffData) {
                aiPlanBoxEl.classList.remove('visible');
                aiPlanSummaryEl.textContent = 'No proposed changes yet.';
                aiPlanListEl.innerHTML = '';
                aiPlanNotesEl.innerHTML = '';
                aiPlanNotesEl.style.display = 'none';
                if (aiPlanLintBoxEl) aiPlanLintBoxEl.style.display = 'none';
                if (aiPlanLintListEl) aiPlanLintListEl.innerHTML = '';
                aiViewDiffBtn.disabled = true;
                aiSmartDiffBtn.disabled = true;
                aiApplyBtn.disabled = true;
                aiClearPlanBtn.disabled = true;
                return;
            }

            const { message, path, stats } = diffData;
            aiPlanBoxEl.classList.add('visible');
            aiPlanSummaryEl.textContent = String(message.title || 'Proposed workflow changes.');
            aiPlanListEl.innerHTML = `
                <li class="studio-ai-plan-item">
                    <span class="studio-ai-plan-badge">write</span>
                    <span class="studio-ai-plan-path" title="${escapeHtml(path)}">${escapeHtml(path)}</span>
                </li>
            `;

            const notes = Array.isArray(message.notes) ? message.notes.filter(Boolean) : [];
            aiPlanNotesEl.innerHTML = notes.map((note) => `<li>${escapeHtml(note)}</li>`).join('');
            aiPlanNotesEl.style.display = notes.length ? 'block' : 'none';

            const lintItems = [];
            const validation = message.validation && typeof message.validation === 'object' ? message.validation : null;
            (validation?.errors || []).forEach((item) => {
                lintItems.push({
                    title: 'Validation error',
                    message: String(item || ''),
                    severity: 'danger',
                });
            });
            (validation?.warnings || []).forEach((item) => {
                lintItems.push({
                    title: 'Validation warning',
                    message: String(item || ''),
                    severity: 'warning',
                });
            });

            const hasBlockingLint = lintItems.some((item) => item.severity === 'danger');
            if (aiPlanLintBoxEl && aiPlanLintListEl) {
                if (lintItems.length) {
                    aiPlanLintBoxEl.style.display = 'block';
                    aiPlanLintBoxEl.classList.toggle('danger', hasBlockingLint);
                    aiPlanLintListEl.innerHTML = lintItems.map((item) => `
                        <li class="studio-ai-plan-lint-item ${item.severity === 'danger' ? 'danger' : ''}">
                            <div class="studio-ai-plan-lint-item-title">${escapeHtml(item.title)}</div>
                            <div class="studio-ai-plan-lint-item-meta">${escapeHtml(path)}</div>
                            <div class="studio-ai-plan-lint-item-message">${escapeHtml(item.message)}</div>
                        </li>
                    `).join('');
                } else {
                    aiPlanLintBoxEl.style.display = 'none';
                    aiPlanLintBoxEl.classList.remove('danger');
                    aiPlanLintListEl.innerHTML = '';
                }
            }

            const noChanges = stats.additions === 0 && stats.deletions === 0;
            const smartDiffAvailable = studioState.type === 'workflow' && Number(diffData.smartData?.summary?.total || 0) > 0;
            aiViewDiffBtn.disabled = studioState.aiActionBusy || noChanges;
            aiSmartDiffBtn.disabled = studioState.aiActionBusy || !smartDiffAvailable;
            aiApplyBtn.disabled = studioState.aiActionBusy;
            aiClearPlanBtn.disabled = studioState.aiActionBusy;
        }

        function renderAiDiffModal() {
            if (!aiDiffFilesEl || !aiDiffMetaEl || !aiDiffContentEl || !aiDiffApplyBtn) {
                return;
            }

            const diffData = getPendingAiDiffData();
            if (!diffData) {
                aiDiffFilesEl.innerHTML = '<div class="studio-diff-empty">No pending changes.</div>';
                aiDiffMetaEl.innerHTML = `
                    <div class="studio-diff-meta-main">
                        <div class="studio-diff-meta-path">No file selected</div>
                        <div class="studio-diff-meta-copy">Select a changed file to inspect the proposed diff.</div>
                    </div>
                `;
                aiDiffContentEl.innerHTML = '<div class="studio-diff-empty">No pending changes.</div>';
                aiDiffApplyBtn.disabled = true;
                return;
            }

            const { path, rows, stats, smartData } = diffData;
            const smartDiffAvailable = studioState.type === 'workflow' && Number(smartData?.summary?.total || 0) > 0;
            const mode = smartDiffAvailable && studioState.aiDiffMode === 'smart' ? 'smart' : 'raw';
            const smartSummary = smartData?.summary || { total: 0 };
            studioState.aiDiffMode = mode;
            aiDiffFilesEl.innerHTML = `
                <button type="button" class="studio-diff-file active">
                    <span class="studio-diff-file-path" title="${escapeHtml(path)}">${escapeHtml(path)}</span>
                    <span class="studio-diff-file-meta">
                        <span class="studio-ai-plan-badge">write</span>
                        <span>${mode === 'smart' ? `${escapeHtml(String(smartSummary.total || 0))} changes` : `${stats.additions}+`}</span>
                        <span>${mode === 'smart' ? `${escapeHtml(String((smartSummary.added || 0) + (smartSummary.removed || 0)))} structural` : `${stats.deletions}-`}</span>
                    </span>
                </button>
            `;
            aiDiffMetaEl.innerHTML = `
                <div class="studio-diff-meta-main">
                    <div class="studio-diff-meta-path">${escapeHtml(path)}</div>
                    <div class="studio-diff-meta-copy">${escapeHtml(mode === 'smart' ? 'Workflow-aware review of the proposed changes.' : 'Replaces the current editor content with the proposed version.')}</div>
                </div>
                <div class="studio-diff-toolbar">
                    <div class="studio-diff-mode-toggle">
                        <button type="button" class="studio-diff-mode-btn ${mode === 'raw' ? 'active' : ''}" data-ai-diff-mode="raw">Raw Diff</button>
                        <button type="button" class="studio-diff-mode-btn ${mode === 'smart' ? 'active' : ''}" data-ai-diff-mode="smart" ${smartDiffAvailable ? '' : 'disabled'}>Smart Diff</button>
                    </div>
                    <span class="studio-diff-meta-badge">write</span>
                </div>
            `;
            aiDiffContentEl.innerHTML = mode === 'smart'
                ? renderWorkflowSmartDiff(smartData)
                : (rows.length ? buildAiDiffRows(rows) : '<div class="studio-diff-empty">No line changes detected.</div>');
            aiDiffMetaEl.querySelectorAll('[data-ai-diff-mode]').forEach((button) => {
                button.addEventListener('click', () => {
                    const nextMode = button.getAttribute('data-ai-diff-mode') || 'raw';
                    if (nextMode === 'smart' && !smartDiffAvailable) {
                        return;
                    }
                    studioState.aiDiffMode = nextMode;
                    renderAiDiffModal();
                });
            });
            aiDiffApplyBtn.disabled = studioState.aiActionBusy;
        }

        function openAiDiffModal(mode = 'raw') {
            const message = getPendingAiDraftMessage();
            if (!message || !aiDiffModalEl) {
                return;
            }
            studioState.aiDiffMessageId = String(message.id || '');
            studioState.aiDiffMode = mode === 'smart' ? 'smart' : 'raw';
            aiDiffModalEl.classList.add('active');
            renderAiDiffModal();
        }

        function closeAiDiffModal() {
            aiDiffModalEl?.classList.remove('active');
            studioState.aiDiffMessageId = '';
            studioState.aiDiffMode = 'raw';
        }

        function clearAiPendingChange() {
            studioState.pendingAiDraftMessageId = '';
            closeAiDiffModal();
            renderAiPlan();
        }

        async function applyAiPlan() {
            const message = getPendingAiDraftMessage();
            if (!message || !message.draftAfter) {
                return;
            }

            const currentDraft = readDraftJson(false)
                || deepClone(getSelectedProject()?.draft || studioState.defaults[studioState.type] || {});
            const baselineDraft = message.draftBefore || {};

            if ((!message.applied || message.reverted) && !draftsEqual(currentDraft, baselineDraft)) {
                const confirmed = window.confirm('Applying these AI changes will replace the current editor state with the proposed version. Continue?');
                if (!confirmed) {
                    return;
                }
            }

            studioState.aiActionBusy = true;
            renderAiMessages();
            renderAiPlan();
            renderAiDiffModal();

            try {
                applyDraftToEditor(message.draftAfter, message.validation || null);
                appendAiMessage('assistant', 'Applied AI changes.', {
                    providerName: message.providerName,
                    modelName: message.modelName,
                    applied: true,
                    validation: message.validation || null,
                    validationBefore: message.validationBefore || null,
                    draftBefore: message.draftBefore || null,
                    draftAfter: message.draftAfter || null,
                });
                studioState.pendingAiDraftMessageId = '';
                closeAiDiffModal();
                editorStatusLineEl.textContent = 'Applied AI changes to the editor. Review them, then save when you are happy.';
                window.Toast?.success?.('Applied AI changes');
            } finally {
                studioState.aiActionBusy = false;
                renderAiMessages();
                renderAiPlan();
                renderAiDiffModal();
            }
        }

        async function revertAiDraftMessage(messageId) {
            const message = getAiMessageById(messageId);
            if (!message || !message.applied || message.reverted || !message.draftBefore) {
                return;
            }

            const currentDraft = readDraftJson(false)
                || deepClone(getSelectedProject()?.draft || studioState.defaults[studioState.type] || {});
            const expectedAppliedDraft = message.draftAfter || {};
            const confirmMessage = draftsEqual(currentDraft, expectedAppliedDraft)
                ? 'Revert these AI changes and restore the previous editor state?'
                : 'The editor has changed since these AI changes were applied. Reverting will replace the current editor state with the previous version. Continue?';
            if (!window.confirm(confirmMessage)) {
                return;
            }

            studioState.aiActionBusy = true;
            renderAiMessages();
            renderAiPlan();

            try {
                applyDraftToEditor(message.draftBefore, message.validationBefore || null);
                message.reverted = true;
                appendAiMessage('assistant', 'Reverted AI changes.', {
                    providerName: message.providerName,
                    modelName: message.modelName,
                });
                editorStatusLineEl.textContent = 'Reverted the applied AI changes.';
                window.Toast?.success?.('Reverted AI changes');
            } finally {
                studioState.aiActionBusy = false;
                renderAiMessages();
                renderAiPlan();
            }
        }

        async function createTemplateForCapabilityGap(capability) {
            const workflowDraft = readDraftJson(false)
                || deepClone(getSelectedProject()?.draft || studioState.defaults.workflow || {});
            const workflowConversation = deepClone(Array.isArray(studioState.aiMessages) ? studioState.aiMessages : []);
            const workflowProject = getSelectedProject();
            const gap = findCapabilityGapInValidation(studioState.currentValidation, capability);
            const lastUserPrompt = getLastUserAiPrompt(workflowConversation);

            studioState.pendingWorkflowResume = {
                type: 'workflow',
                projectId: studioState.selectedProjectId || '',
                name: projectNameEl?.value?.trim?.() || workflowProject?.name || workflowDraft?.name || '',
                description: projectDescriptionEl?.value?.trim?.() || workflowProject?.description || workflowDraft?.description || '',
                runtimeId: runtimeIdEl?.value?.trim?.() || workflowProject?.commandId || workflowDraft?.id || '',
                draft: deepClone(workflowDraft),
                validation: deepClone(studioState.currentValidation || null),
                aiMessages: workflowConversation,
                versions: deepClone(Array.isArray(workflowProject?.versions) ? workflowProject.versions : []),
                capability,
                capabilityLabel: gap?.label || formatCapabilityLabel(capability),
                capabilitySummary: gap?.summary || `This workflow needs ${capability}.`,
                sourcePrompt: lastUserPrompt,
            };

            switchType('template');

            const prompt = buildCapabilityTemplatePrompt(studioState.pendingWorkflowResume);
            await requestAiDraftUpdate({
                type: 'template',
                prompt,
                displayPrompt: `Create a reusable template for ${studioState.pendingWorkflowResume.capabilityLabel}.`,
                currentDraft: deepClone(studioState.defaults.template || {}),
                conversation: [],
                currentValidation: null,
                editMode: false,
                busyText: 'Creating template with AI...',
                successStatus: 'AI prepared a template to fill the missing capability. Review it, then publish when you are happy.',
                successToast: 'Template draft ready',
                fallbackProviderName: 'AI',
                extraNotes: [
                    `This template was requested because the blocked workflow is missing ${studioState.pendingWorkflowResume.capabilityLabel}.`,
                    'Review and publish the template when it looks right. A resume action will appear after publish.',
                ],
            });
        }

        async function resumeBlockedWorkflow() {
            const pending = studioState.pendingWorkflowResume;
            if (!pending || pending.type !== 'workflow' || !pending.draft) {
                editorStatusLineEl.textContent = 'No blocked workflow is waiting to resume.';
                window.Toast?.error?.('No workflow to resume');
                return;
            }

            const resumePrompt = buildResumeWorkflowPrompt(pending);
            restoreWorkflowFromResumeState(pending);
            studioState.pendingWorkflowResume = null;

            await requestAiDraftUpdate({
                type: 'workflow',
                prompt: resumePrompt,
                displayPrompt: `Continue this workflow now that ${pending.capabilityLabel} is available.`,
                currentDraft: deepClone(pending.draft),
                conversation: getAiConversationForRequest(),
                currentValidation: deepClone(pending.validation || null),
                editMode: true,
                busyText: 'Continuing workflow with AI...',
                successStatus: 'AI continued the workflow with the newly available capability. Review it, then save when you are happy.',
                successToast: 'Workflow resumed',
                fallbackProviderName: 'AI',
            });
        }

        async function saveCurrentProject() {
            const payload = buildPayloadFromEditor();
            if (!payload) return;

            setBusy(true, 'Saving...');
            try {
                const result = await apiPost('save-project', {
                    type: studioState.type,
                    projectId: studioState.selectedProjectId,
                    payload,
                });
                studioState.currentValidation = result.project?.lastValidation || null;
                studioState.isDirty = false;
                await refreshData(result.project?.id || '');
                appendAiMessage('assistant', `Saved the current ${studioState.type}.`, {
                    title: 'Saved',
                    validation: studioState.currentValidation,
                    versions: getSelectedProject()?.versions || [],
                });
                renderAiMessages();
                editorStatusLineEl.textContent = `${studioState.type === 'template' ? 'Template' : 'Workflow'} saved.`;
                window.Toast?.success?.('Saved');
            } catch (error) {
                editorStatusLineEl.textContent = error.message;
                window.Toast?.error?.(error.message);
            } finally {
                setBusy(false);
            }
        }

        async function validateCurrentProject() {
            const payload = buildPayloadFromEditor();
            if (!payload) return;

            setBusy(true, 'Validating...');
            try {
                const result = await apiPost('validate-project', {
                    type: studioState.type,
                    payload,
                });
                studioState.currentValidation = result.validation || null;
                appendAiMessage('assistant', studioState.currentValidation?.valid ? 'This looks good to publish.' : 'This has validation issues to fix.', {
                    title: 'Validation',
                    validation: studioState.currentValidation,
                    versions: getSelectedProject()?.versions || [],
                });
                renderAiMessages();
                editorStatusLineEl.textContent = studioState.currentValidation?.valid
                    ? 'Validation passed.'
                    : 'Validation found issues.';
                window.Toast?.success?.('Validation complete');
            } catch (error) {
                editorStatusLineEl.textContent = error.message;
                window.Toast?.error?.(error.message);
            } finally {
                setBusy(false);
            }
        }

        async function publishCurrentProject() {
            if (!studioState.selectedProjectId) {
                editorStatusLineEl.textContent = 'Save before publishing.';
                window.Toast?.error?.('Save first');
                return;
            }
            if (studioState.isDirty) {
                editorStatusLineEl.textContent = 'Save before publishing these changes.';
                window.Toast?.error?.('Save before publishing');
                return;
            }

            setBusy(true, 'Publishing...');
            try {
                const result = await apiPost('publish-project', {
                    type: studioState.type,
                    projectId: studioState.selectedProjectId,
                });
                studioState.currentValidation = result.validation || null;
                await refreshData(result.project?.id || studioState.selectedProjectId);
                const postPublishActions = studioState.type === 'template' && studioState.pendingWorkflowResume
                    ? [{
                        kind: 'resume-workflow',
                        label: `Return and continue ${studioState.pendingWorkflowResume.name || 'workflow'}`,
                    }]
                    : [];
                appendAiMessage('assistant', `Published the current ${studioState.type} to runtime.`, {
                    title: 'Published',
                    validation: studioState.currentValidation,
                    versions: getSelectedProject()?.versions || [],
                    actions: postPublishActions,
                });
                renderAiMessages();
                editorStatusLineEl.textContent = `${studioState.type === 'template' ? 'Template' : 'Workflow'} published to runtime.`;
                window.Toast?.success?.('Published');
            } catch (error) {
                editorStatusLineEl.textContent = error.message;
                window.Toast?.error?.(error.message);
            } finally {
                setBusy(false);
            }
        }

        async function executeCurrentProject(dryRun = false) {
            if (studioState.type === 'template') {
                useStatusLineEl.textContent = 'Templates are not executed directly from Studio.';
                return;
            }

            const payload = buildPayloadFromEditor();
            if (!payload) return;

            const inputs = collectUseInputs();
            setBusy(true, dryRun ? 'Generating dry run...' : 'Running...');
            try {
                const result = await apiPost('execute-project', {
                    type: 'workflow',
                    projectId: studioState.selectedProjectId,
                    payload,
                    dryRun,
                    inputs,
                });
                studioState.currentValidation = result.validation || null;
                studioState.lastDryRunResult = normalizeExecutionResult(result);
                studioState.activeJobId = result.jobId || null;
                studioState.activeJobIsDryRun = !!result.dryRun || !!dryRun;
                renderDryRunResult(studioState.lastDryRunResult);

                const exitText = Number.isFinite(Number(result.exitCode)) ? ` Exit code ${Number(result.exitCode)}.` : '';
                const summary = dryRun
                    ? `Dry run started.${exitText}`
                    : `Run started.${exitText}`;
                editorStatusLineEl.textContent = summary;
                useStatusLineEl.textContent = summary;
                dryRunPanelEl?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

                if (result.jobId) {
                    scheduleJobPoll(result.jobId, result.status === 'pending' || result.status === 'running' ? 700 : 200);
                }

                window.Toast?.success?.(dryRun ? 'Dry run ready' : 'Run started');
            } catch (error) {
                if (error.data?.validation) {
                    studioState.currentValidation = error.data.validation;
                    appendAiMessage('assistant', error.message, {
                        title: dryRun ? 'Dry run blocked' : 'Run blocked',
                        validation: studioState.currentValidation,
                        versions: getSelectedProject()?.versions || [],
                    });
                    renderAiMessages();
                }
                studioState.lastDryRunResult = null;
                renderDryRunResult(studioState.lastDryRunResult);
                editorStatusLineEl.textContent = error.message;
                useStatusLineEl.textContent = error.message;
                window.Toast?.error?.(error.message);
            } finally {
                setBusy(false);
                if (studioState.lastDryRunResult && ['pending', 'running'].includes(String(studioState.lastDryRunResult.status || '').toLowerCase())) {
                    useRunBtn.disabled = true;
                    useDryRunBtn.disabled = true;
                }
            }
        }

        async function archiveCurrentProject() {
            if (!studioState.selectedProjectId) {
                editorStatusLineEl.textContent = 'Nothing to archive yet.';
                return;
            }

            if (!window.confirm(`Archive this ${studioState.type} project?`)) {
                return;
            }

            setBusy(true, 'Archiving project...');
            try {
                const result = await apiPost('archive-project', {
                    type: studioState.type,
                    projectId: studioState.selectedProjectId,
                });
                await refreshData(result.project?.id || studioState.selectedProjectId);
                appendAiMessage('assistant', `Archived the current ${studioState.type} project.`, {
                    title: 'Archived',
                    versions: getSelectedProject()?.versions || [],
                });
                renderAiMessages();
                editorStatusLineEl.textContent = `${studioState.type === 'template' ? 'Template' : 'Workflow'} project archived.`;
                window.Toast?.success?.('Archived');
            } catch (error) {
                editorStatusLineEl.textContent = error.message;
                window.Toast?.error?.(error.message);
            } finally {
                setBusy(false);
            }
        }

        async function requestAiDraftUpdate(options = {}) {
            const type = options.type === 'template' ? 'template' : 'workflow';
            const prompt = String(options.prompt || '').trim();
            if (!prompt) {
                throw new Error('A prompt is required');
            }

            const currentDraft = isPlainObject(options.currentDraft) || Array.isArray(options.currentDraft)
                ? deepClone(options.currentDraft)
                : (deepClone(readDraftJson(false) || getSelectedProject()?.draft || studioState.defaults[type] || {}));
            const conversation = Array.isArray(options.conversation)
                ? deepClone(options.conversation)
                : getAiConversationForRequest();
            const currentValidation = options.currentValidation && typeof options.currentValidation === 'object'
                ? deepClone(options.currentValidation)
                : deepClone(studioState.currentValidation || null);
            const displayPrompt = String(options.displayPrompt || prompt).trim() || prompt;
            const editMode = typeof options.editMode === 'boolean'
                ? options.editMode
                : (!!studioState.selectedProjectId || conversation.some((message) => message.role === 'assistant'));

            appendAiMessage('user', displayPrompt);
            renderAiMessages();

            if (options.clearPrompt !== false && aiPromptEl) {
                aiPromptEl.value = '';
            }

            setBusy(true, String(options.busyText || 'Updating with AI...'));
            try {
                const result = await apiPost('generate-ai-draft', {
                    type,
                    providerId: aiProviderSelectEl?.value || '',
                    prompt,
                    currentDraft,
                    conversation,
                    currentValidation,
                    editMode,
                });

                studioState.lastAiResult = result;
                const extraNotes = Array.isArray(options.extraNotes) ? options.extraNotes : [];

                const aiMessage = appendAiMessage('assistant', result.reply || `I updated the ${type}.`, {
                    title: result.summary || 'Updated',
                    notes: [...(result.notes || []), ...extraNotes],
                    providerName: result.provider?.name || options.fallbackProviderName || 'AI',
                    modelName: result.model || '',
                    applied: false,
                    reverted: false,
                    validation: result.validation || null,
                    validationBefore: currentValidation,
                    versions: getSelectedProject()?.versions || [],
                    actions: Array.isArray(options.actions) ? options.actions : [],
                    draftBefore: currentDraft,
                    draftAfter: result.draft || {},
                });
                studioState.pendingAiDraftMessageId = aiMessage?.draftAfter ? String(aiMessage.id || '') : '';
                closeAiDiffModal();
                renderAiMessages();
                renderAiPlan();
                editorStatusLineEl.textContent = String(options.successStatus || 'AI proposed changes. Review them in Pending Changes, then apply them to the editor if you want to keep them.');
                window.Toast?.success?.(String(options.successToast || 'AI update ready'));
                return result;
            } catch (error) {
                appendAiMessage('assistant', `I hit an error: ${error.message}`, {
                    providerName: aiProviderSelectEl?.selectedOptions?.[0]?.textContent?.trim() || options.fallbackProviderName || 'AI',
                });
                renderAiMessages();
                renderAiPlan();
                editorStatusLineEl.textContent = error.message;
                window.Toast?.error?.(error.message);
                throw error;
            } finally {
                setBusy(false);
            }
        }

        async function generateAiDraft() {
            if (!studioState.aiEnabled) {
                editorStatusLineEl.textContent = 'Studio AI is disabled for this account.';
                return;
            }

            const prompt = aiPromptEl?.value.trim() || '';
            if (!prompt) {
                editorStatusLineEl.textContent = 'Enter a prompt for AI first.';
                window.Toast?.error?.('Enter an AI prompt');
                return;
            }

            if (isWorkflowBuilderEnabled()) {
                if (!syncWorkflowBuilderFromRawJsonIfNeeded(true)) {
                    return;
                }
                syncWorkflowDraftJsonFromBuilder(false);
            }

            const currentDraft = readDraftJson(false)
                || deepClone(getSelectedProject()?.draft || studioState.defaults[studioState.type] || {});
            const conversation = getAiConversationForRequest();
            const editMode = !!studioState.selectedProjectId
                || conversation.some((message) => message.role === 'assistant');
            try {
                await requestAiDraftUpdate({
                    type: studioState.type,
                    prompt,
                    currentDraft,
                    conversation,
                    currentValidation: studioState.currentValidation || null,
                    editMode,
                    busyText: 'Updating with AI...',
                    successStatus: 'AI updated the editor. Review it, then save when you are happy.',
                    successToast: 'AI update ready',
                    fallbackProviderName: 'AI',
                });
            } catch (error) {
                return;
            }
        }

        async function refreshData(selectProjectId = '') {
            const result = await apiGet('list');
            studioState.data.workflow = Array.isArray(result.workflowProjects) ? result.workflowProjects : [];
            studioState.data.template = Array.isArray(result.templateProjects) ? result.templateProjects : [];
            studioState.defaults.workflow = result.workflowDefaults || studioState.defaults.workflow;
            studioState.defaults.template = result.templateDefaults || studioState.defaults.template;
            studioState.runtimeTemplates = Array.isArray(result.runtimeTemplates) ? result.runtimeTemplates : studioState.runtimeTemplates;
            rebuildRuntimeTemplateLookup();
            studioState.stats = result.stats || studioState.stats;
            updateStats();
            renderProjectList();

            const targetProject = getProjects().find((project) => project.id === selectProjectId);
            if (targetProject) {
                loadDraft(targetProject);
                return;
            }

            loadDraft(null);
        }

        function updateStats() {
            const workflowCount = studioState.data.workflow.length;
            const templateCount = studioState.data.template.length;
            const workflowCountEl = document.querySelector('[data-type-count="workflow"]');
            const templateCountEl = document.querySelector('[data-type-count="template"]');
            if (workflowCountEl) workflowCountEl.textContent = String(workflowCount);
            if (templateCountEl) templateCountEl.textContent = String(templateCount);
        }

        function buildPayloadFromEditor() {
            if (isWorkflowBuilderEnabled()) {
                if (!syncWorkflowBuilderFromRawJsonIfNeeded(true)) {
                    return null;
                }
                syncWorkflowDraftJsonFromBuilder(false);
            }
            const draft = readDraftJson(true);
            if (!draft) {
                return null;
            }

            const payload = {
                name: projectNameEl.value.trim(),
                description: projectDescriptionEl.value.trim(),
                draft,
                status: getSelectedProject()?.status || 'draft',
            };

            if (studioState.type === 'template') {
                payload.templateId = runtimeIdEl.value.trim();
                payload.sourceType = sourceTypeEl.value || 'local';
            } else {
                payload.commandId = runtimeIdEl.value.trim();
            }

            return payload;
        }

        function getWorkflowInputContract(draft) {
            if (Array.isArray(draft?.inputContract) && draft.inputContract.length) {
                return draft.inputContract.map((field) => normalizeUseContractField(field)).filter(Boolean);
            }
            if (isPlainObject(draft?.inputs)) {
                const schemaFields = Object.entries(draft.inputs)
                    .map(([name, value]) => normalizeUseContractField(value, name))
                    .filter(Boolean);
                if (schemaFields.length) {
                    return schemaFields;
                }
            }
            return computeCommandInputContract(Array.isArray(draft?.steps) ? draft.steps : []);
        }

        function syncUseInputs(contract, defaults) {
            const next = {};
            const sourceDefaults = normalizeUseDefaults(defaults, contract);
            (Array.isArray(contract) ? contract : []).forEach((field) => {
                const key = String(field.name || '').trim();
                if (!key) {
                    return;
                }
                if (Object.prototype.hasOwnProperty.call(studioState.useInputs, key)) {
                    next[key] = studioState.useInputs[key];
                    return;
                }
                next[key] = Object.prototype.hasOwnProperty.call(sourceDefaults, key)
                    ? sourceDefaults[key]
                    : (field.default ?? defaultValueForUseType(field.type || 'string'));
            });
            studioState.useInputs = next;
        }

        function renderUseInputs(contract, defaults) {
            const fields = Array.isArray(contract) ? contract : [];
            if (!fields.length) {
                useInputsEl.innerHTML = '<div class="studio-empty-block">This workflow does not require any runtime inputs right now.</div>';
                return;
            }

            useInputsEl.innerHTML = fields.map((field) => {
                const key = String(field.name || '').trim();
                const value = Object.prototype.hasOwnProperty.call(studioState.useInputs, key)
                    ? studioState.useInputs[key]
                    : (defaults?.[key] ?? '');
                const helperText = formatUseFieldHelper(field);
                return `
                    <label class="studio-field">
                        <span class="studio-label">${escapeHtml(field.label || key)}${field.required ? ' *' : ''}</span>
                        ${renderUseValueField(field, value)}
                        ${helperText ? `<span class="studio-helper">${escapeHtml(helperText)}</span>` : ''}
                    </label>
                `;
            }).join('');

            useInputsEl.querySelectorAll('[data-use-input]').forEach((input) => {
                input.addEventListener('input', () => {
                    studioState.useInputs[input.dataset.useInput] = readUseFieldValue(input);
                });
                input.addEventListener('change', () => {
                    studioState.useInputs[input.dataset.useInput] = readUseFieldValue(input);
                });
            });
        }

        function renderUseValueField(field, raw) {
            const value = raw === undefined || raw === null ? '' : raw;
            const name = escapeHtml(field.name || '');
            const type = String(field.type || 'string');
            if (type === 'select' && Array.isArray(field.options) && field.options.length) {
                return `<select class="studio-select" data-use-input="${name}" data-use-type="${escapeHtml(type)}">
                    <option value=""></option>
                    ${field.options.map((option) => {
                        const optionValue = option.value ?? option;
                        const optionLabel = option.label ?? optionValue;
                        return `<option value="${escapeHtml(optionValue)}" ${String(value) === String(optionValue) ? 'selected' : ''}>${escapeHtml(optionLabel)}</option>`;
                    }).join('')}
                </select>`;
            }
            if (type === 'multiselect') {
                const selectedValues = Array.isArray(value) ? value.map((item) => String(item)) : [];
                if (Array.isArray(field.options) && field.options.length) {
                    return `<select class="studio-select" multiple data-use-input="${name}" data-use-type="${escapeHtml(type)}">
                        ${field.options.map((option) => {
                            const optionValue = String(option.value ?? option);
                            const optionLabel = option.label ?? optionValue;
                            return `<option value="${escapeHtml(optionValue)}" ${selectedValues.includes(optionValue) ? 'selected' : ''}>${escapeHtml(optionLabel)}</option>`;
                        }).join('')}
                    </select>`;
                }
                return renderUseArrayEditor(field, selectedValues);
            }
            if (type === 'boolean') {
                return `<select class="studio-select" data-use-input="${name}" data-use-type="${escapeHtml(type)}">
                    <option value=""></option>
                    <option value="true" ${value === true || value === 'true' ? 'selected' : ''}>true</option>
                    <option value="false" ${value === false || value === 'false' ? 'selected' : ''}>false</option>
                </select>`;
            }
            if (type === 'json' || isPlainObject(value) || (Array.isArray(value) && !isStringArray(value))) {
                return `<textarea class="studio-textarea" data-use-input="${name}" data-use-type="json" placeholder="${escapeHtml(field.placeholder || 'Enter valid JSON')}">${escapeHtml(prettyJson(value))}</textarea>`;
            }
            if (Array.isArray(value)) {
                return `<textarea class="studio-textarea" data-use-input="${name}" data-use-type="multiselect" placeholder="${escapeHtml(field.placeholder || 'One value per line')}">${escapeHtml(formatMultivalueText(value))}</textarea>`;
            }
            if (type === 'number') {
                const min = field.validation?.min !== undefined ? `min="${escapeHtml(field.validation.min)}"` : '';
                const max = field.validation?.max !== undefined ? `max="${escapeHtml(field.validation.max)}"` : '';
                return `<input class="studio-input" type="number" data-use-input="${name}" data-use-type="${escapeHtml(type)}" value="${escapeHtml(value)}" ${min} ${max}>`;
            }
            return `<input class="studio-input" type="text" data-use-input="${name}" data-use-type="${escapeHtml(type)}" value="${escapeHtml(value)}" placeholder="${escapeHtml(field.placeholder || '')}">`;
        }

        function collectUseInputs() {
            const values = {};
            useInputsEl.querySelectorAll('[data-use-input]').forEach((input) => {
                const key = String(input.dataset.useInput || '').trim();
                if (!key) {
                    return;
                }
                const value = readUseFieldValue(input);
                if (value !== '' && value !== null && value !== undefined) {
                    values[key] = value;
                }
            });
            studioState.useInputs = values;
            return values;
        }

        function readUseFieldValue(input) {
            const type = input.dataset.useType || 'string';
            if (type === 'multiselect' && input instanceof HTMLSelectElement) {
                return Array.from(input.selectedOptions).map((option) => option.value).filter(Boolean);
            }
            if (type === 'multiselect-list') {
                return Array.from(input.querySelectorAll('[data-array-item]'))
                    .map((field) => String(field.value || '').trim())
                    .filter(Boolean);
            }
            return convertInputValue(input.value, type);
        }

        function renderUseArrayEditor(field, values) {
            const name = escapeHtml(field.name || '');
            const placeholder = escapeHtml(field.placeholder || 'Value');
            const items = Array.isArray(values) && values.length ? values : [''];
            return `<div class="studio-array-editor" data-use-input="${name}" data-use-type="multiselect-list" data-item-placeholder="${placeholder}">
                <div class="studio-array-list">
                    ${renderUseArrayRows(items, placeholder)}
                </div>
                <div class="studio-array-actions">
                    <button type="button" class="studio-array-btn" onclick="addUseArrayItem(this)">
                        <i class="fas fa-plus"></i>
                        Add item
                    </button>
                </div>
            </div>`;
        }

        function renderUseArrayRows(values, placeholder) {
            const items = Array.isArray(values) && values.length ? values : [''];
            return items.map((item, index) => `
                <div class="studio-array-row">
                    <input class="studio-input" type="text" data-array-item value="${escapeHtml(item)}" placeholder="${placeholder}">
                    <button type="button" class="studio-array-btn" onclick="removeUseArrayItem(this)" aria-label="Remove item ${index + 1}">
                        <i class="fas fa-minus"></i>
                    </button>
                </div>
            `).join('');
        }

        function addUseArrayItem(button) {
            const container = button.closest('[data-use-type="multiselect-list"]');
            if (!container) {
                return;
            }
            const values = readUseFieldValue(container);
            values.push('');
            refreshUseArrayEditor(container, values);
            const rows = container.querySelectorAll('[data-array-item]');
            const last = rows[rows.length - 1];
            if (last) {
                last.focus();
            }
            studioState.useInputs[container.dataset.useInput] = readUseFieldValue(container);
        }

        function removeUseArrayItem(button) {
            const container = button.closest('[data-use-type="multiselect-list"]');
            const row = button.closest('.studio-array-row');
            if (!container || !row) {
                return;
            }
            const rows = Array.from(container.querySelectorAll('.studio-array-row'));
            const index = rows.indexOf(row);
            const values = Array.from(container.querySelectorAll('[data-array-item]')).map((field) => field.value);
            if (index >= 0) {
                values.splice(index, 1);
            }
            refreshUseArrayEditor(container, values);
            studioState.useInputs[container.dataset.useInput] = readUseFieldValue(container);
        }

        function refreshUseArrayEditor(container, values) {
            const list = container.querySelector('.studio-array-list');
            if (!list) {
                return;
            }
            const placeholder = container.dataset.itemPlaceholder || 'Value';
            list.innerHTML = renderUseArrayRows(values, placeholder);
        }

        function computeCommandInputContract(steps) {
            const map = {};
            (Array.isArray(steps) ? steps : []).forEach((step, index) => {
                const stepId = step?.id || `step-${index + 1}`;
                const template = templateById[String(step?.templateId || '').trim()];
                if (!template) {
                    return;
                }
                const templateInputs = Array.isArray(template.inputs) ? template.inputs : [];
                const stepInputs = isPlainObject(step?.inputs) ? step.inputs : {};
                templateInputs.forEach((templateInput) => {
                    const stepValue = stepInputs[templateInput.name];
                    const hasValue = stepValue !== undefined && stepValue !== null && stepValue !== '';
                    if (!hasValue) {
                        if (templateInput.required) {
                            mergeContractField(map, templateInput.name, stepId, templateInput, true);
                        }
                        return;
                    }
                    collectInputReferences(stepValue).map(inputNameFromRef).filter(Boolean).forEach((name) => {
                        mergeContractField(map, name, stepId, null, !!templateInput.required);
                    });
                });
                collectInputReferences(stepInputs).map(inputNameFromRef).filter(Boolean).forEach((name) => {
                    mergeContractField(map, name, stepId, null, false);
                });
            });
            return Object.values(map).sort((left, right) => String(left.name || '').localeCompare(String(right.name || '')));
        }

        function collectInputReferences(value, refs = new Set()) {
            if (typeof value === 'string') {
                const conditionalRef = /\{\{#if ([a-zA-Z0-9_.]+)\}\}/g;
                const placeholderRef = /\{\{([a-zA-Z0-9_.]+)\}\}/g;
                let match;
                while ((match = conditionalRef.exec(value)) !== null) refs.add(match[1]);
                while ((match = placeholderRef.exec(value)) !== null) refs.add(match[1]);
                return Array.from(refs);
            }
            if (Array.isArray(value)) {
                value.forEach((item) => collectInputReferences(item, refs));
                return Array.from(refs);
            }
            if (isPlainObject(value)) {
                Object.values(value).forEach((item) => collectInputReferences(item, refs));
            }
            return Array.from(refs);
        }

        function inputNameFromRef(ref) {
            if (!ref || !String(ref).startsWith('inputs.')) {
                return '';
            }
            return String(ref).slice('inputs.'.length).trim();
        }

        function mergeContractField(map, name, sourceStepId, inputDef, required) {
            const normalizedName = String(name || '').trim();
            if (!normalizedName) {
                return;
            }
            const candidate = {
                name: normalizedName,
                label: inputDef?.label || normalizedName,
                type: inputDef?.type || 'string',
                required: !!required,
                description: inputDef?.description || '',
                placeholder: inputDef?.placeholder || '',
                validation: inputDef?.validation || null,
                options: Array.isArray(inputDef?.options) ? inputDef.options : [],
                conflict: false,
                sources: sourceStepId ? [sourceStepId] : [],
            };
            if (!map[normalizedName]) {
                map[normalizedName] = candidate;
                return;
            }
            const existing = map[normalizedName];
            existing.required = existing.required || candidate.required;
            existing.sources = [...new Set([...(existing.sources || []), ...(candidate.sources || [])])];
            if (!existing.description && candidate.description) existing.description = candidate.description;
            if ((existing.type || 'string') !== (candidate.type || 'string')) existing.conflict = true;
        }

        async function pollJobStatus(jobId) {
            if (!jobId) {
                return;
            }
            try {
                const response = await fetch(`../api/execute.php?action=job&id=${encodeURIComponent(jobId)}`, {
                    credentials: 'same-origin',
                });
                const data = await response.json();
                if (!(response.ok && data.success && data.job)) {
                    scheduleJobPoll(jobId, 1000);
                    return;
                }

                studioState.lastDryRunResult = normalizeExecutionResult(data.job);
                renderDryRunResult(studioState.lastDryRunResult);
                useStatusLineEl.textContent = summarizeExecutionStatus(studioState.lastDryRunResult);

                const status = String(data.job.status || '').toLowerCase();
                if (status === 'pending' || status === 'running') {
                    scheduleJobPoll(jobId, 1000);
                    return;
                }

                clearJobPoll();
                useRunBtn.disabled = false;
                useDryRunBtn.disabled = false;
            } catch (error) {
                scheduleJobPoll(jobId, 1000);
            }
        }

        function scheduleJobPoll(jobId, delayMs) {
            clearJobPoll();
            studioState.activeJobId = jobId;
            studioState.pollTimer = window.setTimeout(() => pollJobStatus(jobId), delayMs);
        }

        function clearJobPoll() {
            if (studioState.pollTimer) {
                window.clearTimeout(studioState.pollTimer);
                studioState.pollTimer = null;
            }
        }

        function normalizeExecutionResult(result) {
            return {
                jobId: result.jobId || result.id || '',
                status: result.status || 'completed',
                output: result.output || '',
                exitCode: result.exitCode ?? result.exit_code ?? null,
                dryRun: !!result.dryRun || !!result.isDryRun || !!result.is_dry_run,
                steps: Array.isArray(result.steps) ? result.steps : [],
            };
        }

        function summarizeExecutionStatus(result) {
            const status = String(result?.status || '').toLowerCase();
            const exitCode = result?.exitCode;
            const base = result?.dryRun ? 'Dry run' : 'Run';
            if (status === 'pending' || status === 'running') {
                return `${base} in progress…`;
            }
            if (status === 'failed') {
                return `${base} failed${exitCode !== null && exitCode !== undefined ? ` with exit code ${exitCode}` : ''}.`;
            }
            if (status === 'cancelled') {
                return `${base} was cancelled.`;
            }
            return `${base} completed${exitCode !== null && exitCode !== undefined ? ` with exit code ${exitCode}` : ''}.`;
        }

        function readDraftJson(showErrors = true) {
            const rawDraft = draftJsonEl.value.trim();
            if (rawDraft === '') {
                if (showErrors) {
                    editorStatusLineEl.textContent = 'Project JSON cannot be empty.';
                    window.Toast?.error?.('Project JSON cannot be empty');
                }
                return null;
            }

            let draft;
            try {
                draft = JSON.parse(rawDraft);
            } catch (error) {
                if (showErrors) {
                    editorStatusLineEl.textContent = 'Project JSON is invalid: ' + error.message;
                    window.Toast?.error?.('Project JSON is invalid');
                }
                return null;
            }

            if (!draft || typeof draft !== 'object' || Array.isArray(draft)) {
                if (showErrors) {
                    editorStatusLineEl.textContent = 'Project JSON must be an object.';
                    window.Toast?.error?.('Project JSON must be an object');
                }
                return null;
            }

            return draft;
        }

        function setBusy(isBusy, message = '') {
            [newProjectBtn, publishBtn, archiveBtn, useRunBtn, useDryRunBtn, document.getElementById('saveBtn'), document.getElementById('validateBtn'), generateAiBtn, clearAiChatBtn].forEach((button) => {
                if (!button) {
                    return;
                }
                button.disabled = isBusy || (button === archiveBtn && !studioState.selectedProjectId);
            });
            if (message) {
                editorStatusLineEl.textContent = message;
                useStatusLineEl.textContent = message;
            }
        }

        function markDirty() {
            studioState.isDirty = true;
            if (studioState.selectedProjectId) {
                editorStatusLineEl.textContent = 'Unsaved changes.';
            }
        }

        function setStatusBadge(status) {
            const normalized = ['draft', 'published', 'archived'].includes(status) ? status : 'draft';
            editorStatusBadgeEl.className = `studio-badge ${normalized}`;
            editorStatusBadgeEl.textContent = formatProjectStatus(normalized);
        }

        async function apiGet(action) {
            const response = await fetch(`../api/studio-workflows.php?action=${encodeURIComponent(action)}`, {
                credentials: 'same-origin',
            });
            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.error || 'Request failed');
            }
            return data;
        }

        async function apiPost(action, payload) {
            const response = await fetch(`../api/studio-workflows.php?action=${encodeURIComponent(action)}`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(payload),
            });
            const data = await response.json();
            if (!response.ok || !data.success) {
                const error = new Error(data.error || data.details?.error || 'Request failed');
                error.data = data;
                throw error;
            }
            return data;
        }

        function deepClone(value) {
            return JSON.parse(JSON.stringify(value));
        }

        function rebuildRuntimeTemplateLookup() {
            templateById = Object.fromEntries(
                (Array.isArray(studioState.runtimeTemplates) ? studioState.runtimeTemplates : [])
                    .map((template) => [String(template?.id || ''), template])
                    .filter(([id]) => id)
            );
        }

        function capitalize(value) {
            return String(value || '').replace(/[-_]/g, ' ').replace(/\b\w/g, (char) => char.toUpperCase());
        }

        function formatCapabilityLabel(capability) {
            return String(capability || '')
                .split('.')
                .map((part) => capitalize(String(part || '').trim()))
                .filter(Boolean)
                .join(' / ') || 'Capability';
        }

        function formatProjectStatus(status) {
            const normalized = String(status || 'draft').trim().toLowerCase();
            if (normalized === 'draft') {
                return 'Unpublished';
            }
            return capitalize(normalized);
        }

        function escapeHtml(value) {
            return String(value || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function formatDate(value) {
            try {
                return new Date(value).toLocaleString();
            } catch (error) {
                return String(value || '');
            }
        }

        function prettyJson(value) {
            try {
                return JSON.stringify(value ?? {}, null, 2);
            } catch (error) {
                return String(value ?? '');
            }
        }

        function getLastUserAiPrompt(messages = studioState.aiMessages) {
            const items = Array.isArray(messages) ? messages : [];
            for (let index = items.length - 1; index >= 0; index -= 1) {
                const message = items[index];
                if (message?.role === 'user' && String(message?.content || '').trim()) {
                    return String(message.content).trim();
                }
            }
            return '';
        }

        function findCapabilityGapInValidation(validation, capability) {
            const normalized = String(capability || '').trim();
            const gaps = Array.isArray(validation?.capabilityGaps) ? validation.capabilityGaps : [];
            return gaps.find((gap) => String(gap?.capability || '').trim() === normalized) || null;
        }

        function summarizeWorkflowForCapabilityGap(draft) {
            const safeDraft = draft && typeof draft === 'object' ? draft : {};
            const steps = Array.isArray(safeDraft.steps) ? safeDraft.steps : [];
            return {
                id: safeDraft.id || '',
                name: safeDraft.name || '',
                description: safeDraft.description || '',
                executionMode: safeDraft.executionMode || 'sync',
                tags: Array.isArray(safeDraft.tags) ? safeDraft.tags : [],
                inputContract: Array.isArray(safeDraft.inputContract)
                    ? safeDraft.inputContract.map((input) => ({
                        name: input?.name || '',
                        type: input?.type || 'string',
                        required: !!input?.required,
                        description: input?.description || '',
                    }))
                    : [],
                steps: steps.map((step) => ({
                    id: step?.id || '',
                    templateId: step?.templateId || '',
                    dependsOn: Array.isArray(step?.dependsOn) ? step.dependsOn : [],
                    onFailure: step?.onFailure || 'stop',
                    target: step?.target || 'inherit',
                })),
            };
        }

        function buildCapabilityTemplatePrompt(resumeState) {
            const workflowSummary = prettyJson(summarizeWorkflowForCapabilityGap(resumeState?.draft || {}));
            const workflowIntent = String(resumeState?.sourcePrompt || '').trim();
            const capability = String(resumeState?.capability || '').trim();
            const capabilityLabel = resumeState?.capabilityLabel || formatCapabilityLabel(capability);
            const gapSummary = String(resumeState?.capabilitySummary || '').trim();

            return [
                `Create a reusable template that fills this missing capability for Workflows Studio: ${capability}.`,
                '',
                'Requirements:',
                '- create a reusable template draft, not a workflow draft',
                '- keep it focused on this capability so other workflows can reuse it',
                '- prefer a practical runtime and inputs that fit the job cleanly',
                '- do not assume this template is only for one specific workflow',
                '',
                `Missing capability: ${capabilityLabel} (${capability})`,
                gapSummary ? `Why it is needed: ${gapSummary}` : '',
                workflowIntent ? `Original workflow request: ${workflowIntent}` : '',
                '',
                '[Blocked workflow summary]',
                workflowSummary,
                '[/Blocked workflow summary]',
            ].filter(Boolean).join('\n');
        }

        function buildResumeWorkflowPrompt(resumeState) {
            const capability = String(resumeState?.capability || '').trim();
            const capabilityLabel = resumeState?.capabilityLabel || formatCapabilityLabel(capability);
            const workflowIntent = String(resumeState?.sourcePrompt || '').trim();

            return [
                'Continue improving the current workflow now that the missing capability is available.',
                capability ? `A template for ${capabilityLabel} (${capability}) has now been created and published.` : '',
                workflowIntent ? `Original workflow request: ${workflowIntent}` : '',
                'Update the workflow to use the newly available capability where it fits, while preserving the rest of the workflow unless changes are needed.',
            ].filter(Boolean).join('\n');
        }

        function restoreWorkflowFromResumeState(resumeState) {
            if (!resumeState) {
                return;
            }

            switchType('workflow');

            const transientProject = {
                id: resumeState.projectId || '',
                name: resumeState.name || resumeState.draft?.name || '',
                description: resumeState.description || resumeState.draft?.description || '',
                status: 'draft',
                draft: deepClone(resumeState.draft || {}),
                commandId: resumeState.runtimeId || resumeState.draft?.id || '',
                versions: deepClone(Array.isArray(resumeState.versions) ? resumeState.versions : []),
                lastValidation: deepClone(resumeState.validation || null),
                lastPublished: null,
            };

            loadDraft(transientProject);
            studioState.currentValidation = deepClone(resumeState.validation || null);
            studioState.aiMessages = deepClone(Array.isArray(resumeState.aiMessages) ? resumeState.aiMessages : []);
            renderAiMessages();
            renderUsePanel();
            editorStatusLineEl.textContent = `Returned to ${resumeState.name || 'the blocked workflow'} to continue.`;
        }

        function formatTargetRef(targetRef) {
            if (!targetRef) {
                return 'inherit';
            }
            if (typeof targetRef === 'string') {
                const trimmed = targetRef.trim();
                if (!trimmed || trimmed === 'inherit') {
                    return 'inherit';
                }
                return targetById[trimmed]?.name || trimmed;
            }
            if (typeof targetRef === 'object') {
                if (targetRef.targetId) {
                    const targetId = String(targetRef.targetId);
                    return targetById[targetId]?.name || `target:${targetId}`;
                }
                if (targetRef.fromEnvironment) {
                    const envKey = String(targetRef.fromEnvironment);
                    const environment = studioState.environments?.[envKey];
                    return environment?.name ? `environment:${environment.name}` : `environment:${envKey}`;
                }
            }
            return 'inherit';
        }

        function formatStringList(value) {
            if (!Array.isArray(value) || !value.length) {
                return 'None';
            }
            return value.map((item) => String(item || '').trim()).filter(Boolean).join(', ') || 'None';
        }

        function formatSecretMap(value) {
            if (!value || typeof value !== 'object') {
                return 'No secrets';
            }
            if (Array.isArray(value)) {
                return value.length ? `${value.length} secret mapping(s)` : 'No secrets';
            }
            const entries = Object.entries(value).map(([key, secretId]) => `${key} -> ${secretId}`);
            return entries.length ? entries.join(', ') : 'No secrets';
        }

        function isPlainObject(value) {
            return value !== null && typeof value === 'object' && !Array.isArray(value);
        }

        function looksLikeUseInputDefinition(value) {
            if (!isPlainObject(value)) {
                return false;
            }
            return value.name !== undefined
                || value.type !== undefined
                || value.title !== undefined
                || value.label !== undefined
                || Object.prototype.hasOwnProperty.call(value, 'default');
        }

        function normalizeUseContractField(field, fallbackName = '') {
            if (!isPlainObject(field)) {
                return null;
            }
            const name = String(field.name || fallbackName || '').trim();
            if (!name) {
                return null;
            }
            const type = normalizeUseInputType(field);
            const options = normalizeUseFieldOptions(field, type);
            return {
                ...field,
                name,
                label: String(field.label || field.title || name).trim(),
                type,
                required: !!field.required,
                description: String(field.description || '').trim(),
                placeholder: String(field.placeholder || '').trim(),
                options,
                default: Object.prototype.hasOwnProperty.call(field, 'default')
                    ? normalizeUseDefaultValue(field.default, type)
                    : defaultValueForUseType(type),
            };
        }

        function normalizeUseInputType(field) {
            const type = String(field?.type || 'string').trim().toLowerCase();
            if (type === 'string' && looksLikeStringListField(field)) {
                return 'multiselect';
            }
            if (type === 'integer') {
                return 'number';
            }
            if (type === 'object') {
                return 'json';
            }
            if (type === 'array') {
                const itemType = String(field?.items?.type || 'string').trim().toLowerCase();
                return itemType === 'string' ? 'multiselect' : 'json';
            }
            if (type === 'string' && String(field?.format || '').trim().toLowerCase() === 'json') {
                return 'json';
            }
            return ['string', 'number', 'boolean', 'select', 'multiselect', 'file', 'secret', 'json'].includes(type)
                ? type
                : 'string';
        }

        function looksLikeStringListField(field) {
            const haystack = [
                field?.title,
                field?.label,
                field?.description,
                field?.placeholder,
            ].map((value) => String(value || '').trim().toLowerCase()).filter(Boolean).join(' ');
            if (!haystack) {
                return false;
            }
            return haystack.includes('newline-separated')
                || haystack.includes('list of')
                || haystack.includes('one per line')
                || haystack.includes('multiple values');
        }

        function normalizeUseFieldOptions(field, type) {
            const rawOptions = Array.isArray(field?.options)
                ? field.options
                : (Array.isArray(field?.enum) ? field.enum : (Array.isArray(field?.items?.enum) ? field.items.enum : []));
            if (!rawOptions.length) {
                return [];
            }
            return rawOptions.map((option) => {
                if (isPlainObject(option)) {
                    const value = option.value ?? option.label ?? '';
                    return {
                        value: String(value),
                        label: String(option.label ?? value),
                    };
                }
                return {
                    value: String(option),
                    label: String(option),
                };
            }).filter((option) => option.value !== '');
        }

        function normalizeUseDefaults(defaults, contract) {
            const normalized = {};
            const sourceDefaults = isPlainObject(defaults) ? defaults : {};
            Object.entries(sourceDefaults).forEach(([key, value]) => {
                const contractField = (Array.isArray(contract) ? contract : []).find((field) => String(field?.name || '') === key) || null;
                if (looksLikeUseInputDefinition(value)) {
                    const field = normalizeUseContractField(value, key) || contractField;
                    normalized[key] = field
                        ? normalizeUseDefaultValue(value.default, field.type || 'string')
                        : (value.default ?? '');
                    return;
                }
                normalized[key] = value;
            });
            return normalized;
        }

        function normalizeUseDefaultValue(value, type) {
            if (value === undefined || value === null) {
                return defaultValueForUseType(type);
            }
            if (type === 'multiselect') {
                if (Array.isArray(value)) {
                    return value.map((item) => String(item)).filter(Boolean);
                }
                return String(value)
                    .split(/\r?\n/)
                    .map((line) => line.trim())
                    .filter(Boolean);
            }
            if (type === 'number') {
                const parsed = Number(value);
                return Number.isNaN(parsed) ? '' : parsed;
            }
            if (type === 'boolean') {
                return value === true || value === 'true' || value === 1 || value === '1';
            }
            return value;
        }

        function defaultValueForUseType(type) {
            if (type === 'multiselect') {
                return [];
            }
            if (type === 'boolean') {
                return false;
            }
            return '';
        }

        function formatUseFieldHelper(field) {
            const description = String(field?.description || '').trim();
            if (!description) {
                return '';
            }
            if (String(field?.type || '') !== 'multiselect' || (Array.isArray(field?.options) && field.options.length)) {
                return description;
            }
            return description.replace(/^newline-separated/i, 'Add one item per row');
        }

        function isStringArray(value) {
            return Array.isArray(value) && value.every((item) => typeof item === 'string');
        }

        function formatMultivalueText(values) {
            if (!Array.isArray(values)) {
                return '';
            }
            return values.map((value) => String(value ?? '')).join('\n');
        }

        function convertInputValue(raw, type) {
            if (raw === '' || raw === null || raw === undefined) {
                return '';
            }
            if (type === 'multiselect') {
                if (Array.isArray(raw)) {
                    return raw.map((item) => String(item)).filter(Boolean);
                }
                return String(raw)
                    .split(/\r?\n/)
                    .map((line) => line.trim())
                    .filter(Boolean);
            }
            if (type === 'json') {
                if (typeof raw === 'object') {
                    return raw;
                }
                try {
                    return JSON.parse(String(raw));
                } catch (error) {
                    return raw;
                }
            }
            if (type === 'number') {
                const value = Number(raw);
                return Number.isNaN(value) ? '' : value;
            }
            if (type === 'boolean') {
                if (raw === true || raw === 'true') return true;
                if (raw === false || raw === 'false') return false;
                return '';
            }
            return raw;
        }

        renderProjectList();
        const firstProject = getProjects()[0] || null;
        loadDraft(firstProject);
    </script>
</body>
</html>
