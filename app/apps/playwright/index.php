<?php
/**
 * Playwright App - Projects Page
 * 
 * Lists and manages Playwright test projects.
 */

require_once __DIR__ . '/../../includes/AppContext.php';
require_once __DIR__ . '/../../includes/PlaywrightProjectManager.php';
require_once __DIR__ . '/../../includes/SourcesManager.php';

$app = new AppContext('playwright');
$user = $app->requireAuth();
$layout = $app->getLayout();
$settings = $app->getSettings();
$isAdmin = $app->hasRole('admin');

// Load git providers from unified SourcesManager (Batch 13)
$sourcesManager = new SourcesManager();
$gitProviders = $sourcesManager->getProviders();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $layout->renderHead('Playwright - Projects'); ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/codemirror.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/theme/dracula.min.css">
    <style>
        .app-header {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 32px;
            padding-bottom: 24px;
            border-bottom: 1px solid var(--border-color);
        }

        .app-icon {
            width: 56px;
            height: 56px;
            border-radius: 12px;
            background: linear-gradient(135deg, #a855f7 0%, #7c3aed 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
        }

        .app-title h1 {
            font-size: 24px;
            margin: 0 0 4px 0;
        }

        .app-title p {
            color: var(--text-secondary);
            margin: 0;
            font-size: 14px;
        }

        .app-nav {
            display: flex;
            gap: 8px;
            margin-bottom: 24px;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 12px;
        }

        .app-nav-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 8px;
            color: var(--text-secondary);
            text-decoration: none;
            transition: all 0.2s;
        }

        .app-nav-item:hover {
            background: var(--bg-tertiary);
            color: var(--text-primary);
        }

        .app-nav-item.active {
            background: var(--accent-primary);
            color: white;
        }

        /* Toolbar */
        .toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
        }

        .toolbar-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .toolbar-right {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .search-box {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 16px;
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            min-width: 280px;
        }

        .search-box input {
            flex: 1;
            background: transparent;
            border: none;
            outline: none;
            color: var(--text-primary);
            font-size: 14px;
        }

        .search-box i {
            color: var(--text-muted);
        }

        .run-session-btn {
            min-width: 220px;
            justify-content: flex-start;
        }

        .run-session-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--text-muted);
            flex-shrink: 0;
        }

        .run-session-btn.running {
            border-color: var(--accent-primary);
            color: var(--accent-primary);
        }

        .run-session-btn.running .run-session-dot {
            background: var(--accent-primary);
            animation: runPulse 1.2s ease-in-out infinite;
        }

        .run-session-btn.completed {
            border-color: rgba(34, 197, 94, 0.35);
            color: #22c55e;
        }

        .run-session-btn.completed .run-session-dot {
            background: #22c55e;
        }

        .run-session-btn.failed {
            border-color: rgba(239, 68, 68, 0.35);
            color: #ef4444;
        }

        .run-session-btn.failed .run-session-dot {
            background: #ef4444;
        }

        .run-session-btn.cancelled {
            border-color: rgba(245, 158, 11, 0.35);
            color: #f59e0b;
        }

        .run-session-btn.cancelled .run-session-dot {
            background: #f59e0b;
        }

        @keyframes runPulse {
            0% { opacity: 0.4; transform: scale(0.9); }
            50% { opacity: 1; transform: scale(1.15); }
            100% { opacity: 0.4; transform: scale(0.9); }
        }

        /* Projects Grid */
        .projects-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 20px;
        }

        .project-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            overflow: hidden;
            transition: all 0.2s;
        }

        .project-card:hover {
            border-color: var(--accent-primary);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .project-card-header {
            padding: 20px;
            border-bottom: 1px solid var(--border-color);
        }

        .project-card-title {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 8px;
        }

        .project-card-title h3 {
            font-size: 16px;
            font-weight: 600;
            margin: 0;
            flex: 1;
        }

        .project-source-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .project-source-badge.local {
            background: rgba(59, 130, 246, 0.1);
            color: #3b82f6;
        }

        .project-source-badge.git {
            background: rgba(249, 115, 22, 0.1);
            color: #f97316;
        }

        .project-source-badge.repository {
            background: rgba(14, 165, 233, 0.12);
            color: #0ea5e9;
        }

        .project-card-desc {
            font-size: 13px;
            color: var(--text-secondary);
            margin: 0;
            line-height: 1.5;
        }

        .project-card-body {
            padding: 16px 20px;
        }

        .project-stats {
            display: flex;
            gap: 24px;
        }

        .project-stat {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .project-stat i {
            color: var(--text-muted);
        }

        .project-stat span {
            font-size: 13px;
            color: var(--text-secondary);
        }

        .project-card-footer {
            display: flex;
            gap: 8px;
            padding: 12px 20px;
            background: var(--bg-tertiary);
            border-top: 1px solid var(--border-color);
        }

        .project-card-footer .btn {
            flex: 1;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 24px;
            background: var(--bg-secondary);
            border: 2px dashed var(--border-color);
            border-radius: 12px;
        }

        .empty-state i {
            font-size: 48px;
            color: var(--text-muted);
            margin-bottom: 16px;
        }

        .empty-state h3 {
            font-size: 18px;
            margin-bottom: 8px;
        }

        .empty-state p {
            color: var(--text-secondary);
            margin-bottom: 24px;
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 16px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
        }

        .btn-primary {
            background: var(--accent-primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--accent-hover);
        }

        .btn-secondary {
            background: var(--bg-tertiary);
            color: var(--text-primary);
            border: 1px solid var(--border-color);
        }

        .btn-secondary:hover {
            background: var(--bg-primary);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 13px;
        }

        /* Modal */
        .modal-backdrop {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.2s;
        }

        .modal-backdrop.active {
            opacity: 1;
            visibility: visible;
        }

        .modal {
            background: var(--bg-secondary);
            border-radius: 16px;
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            transform: translateY(20px);
            transition: transform 0.2s;
        }

        .modal-backdrop.active .modal {
            transform: translateY(0);
        }

        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 20px 24px;
            border-bottom: 1px solid var(--border-color);
        }

        .modal-header h2 {
            font-size: 18px;
            margin: 0;
        }

        .modal-close {
            width: 32px;
            height: 32px;
            border: none;
            background: var(--bg-tertiary);
            border-radius: 8px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-secondary);
        }

        .modal-close:hover {
            background: var(--danger);
            color: white;
        }

        .modal-body {
            padding: 24px;
            overflow-y: auto;
            flex: 1;
        }

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            padding: 16px 24px;
            border-top: 1px solid var(--border-color);
        }

        /* Form */
        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            font-size: 13px;
            font-weight: 500;
            color: var(--text-secondary);
            margin-bottom: 8px;
        }

        .form-input, .form-select, .form-textarea {
            width: 100%;
            padding: 10px 12px;
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            color: var(--text-primary);
            font-size: 14px;
            font-family: inherit;
        }

        .form-input:focus, .form-select:focus, .form-textarea:focus {
            outline: none;
            border-color: var(--accent-primary);
        }

        .form-textarea {
            min-height: 80px;
            resize: vertical;
        }

        .form-hint {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 4px;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
        }

        /* Source selector */
        .source-selector {
            display: flex;
            gap: 12px;
            margin-bottom: 20px;
        }

        .source-option {
            flex: 1;
            padding: 16px;
            background: var(--bg-primary);
            border: 2px solid var(--border-color);
            border-radius: 12px;
            cursor: pointer;
            text-align: center;
            transition: all 0.2s;
        }

        .source-option:hover {
            border-color: var(--accent-primary);
        }

        .source-option.selected {
            border-color: var(--accent-primary);
            background: var(--accent-glow);
        }

        /* File drop zone */
        .file-drop-zone {
            border: 2px dashed var(--border-color);
            border-radius: 12px;
            padding: 24px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
        }
        .file-drop-zone:hover, .file-drop-zone.dragover {
            border-color: var(--accent-primary);
            background: var(--accent-glow);
        }

        .source-option i {
            font-size: 24px;
            margin-bottom: 8px;
            color: var(--text-muted);
        }

        .source-option.selected i {
            color: var(--accent-primary);
        }

        .source-option span {
            display: block;
            font-size: 14px;
            font-weight: 500;
        }

        /* Wrapper Settings Accordion */
        .settings-accordion {
            border: 1px solid var(--border-color);
            border-radius: 8px;
            margin-top: 16px;
            overflow: hidden;
        }

        .accordion-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 16px;
            background: var(--bg-tertiary);
            cursor: pointer;
            user-select: none;
        }

        .accordion-header:hover {
            background: var(--bg-primary);
        }

        .accordion-title {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
            font-size: 14px;
        }

        .accordion-toggle {
            transition: transform 0.2s;
        }

        .accordion-toggle.open {
            transform: rotate(180deg);
        }

        .accordion-body {
            padding: 16px;
            background: var(--bg-secondary);
        }

        .setting-section {
            margin-bottom: 16px;
        }

        .setting-section:last-child {
            margin-bottom: 0;
        }

        .setting-section-header {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 10px;
        }

        .setting-section-header label {
            font-size: 13px;
            font-weight: 500;
            color: var(--text-primary);
        }

        .setting-section-header input[type="checkbox"] {
            width: 16px;
            height: 16px;
        }

        .setting-row {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 8px;
            padding-left: 24px;
        }

        .setting-row label {
            min-width: 100px;
            font-size: 12px;
            color: var(--text-secondary);
        }

        .setting-row select,
        .setting-row input[type="number"] {
            flex: 1;
            padding: 6px 10px;
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            color: var(--text-primary);
            font-size: 13px;
        }

        .checkbox-group {
            display: flex;
            gap: 16px;
            padding-left: 24px;
            flex-wrap: wrap;
        }

        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
        }

        .checkbox-item input[type="checkbox"] {
            width: 14px;
            height: 14px;
        }

        /* Delete Confirmation Modal */
        .delete-summary {
            background: var(--bg-tertiary);
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 16px;
        }

        .delete-summary-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 8px 0;
            border-bottom: 1px solid var(--border-color);
        }

        .delete-summary-item:last-child {
            border-bottom: none;
        }

        .delete-summary-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
        }

        .delete-summary-icon.files {
            background: rgba(59, 130, 246, 0.1);
            color: #3b82f6;
        }

        .delete-summary-icon.reports {
            background: rgba(168, 85, 247, 0.1);
            color: #a855f7;
        }

        .delete-summary-icon.media {
            background: rgba(34, 197, 94, 0.1);
            color: #22c55e;
        }

        .delete-summary-content {
            flex: 1;
        }

        .delete-summary-content strong {
            font-size: 14px;
            display: block;
        }

        .delete-summary-content span {
            font-size: 12px;
            color: var(--text-muted);
        }

        .delete-confirm-input {
            width: 100%;
            padding: 12px;
            background: var(--bg-primary);
            border: 2px solid var(--border-color);
            border-radius: 8px;
            color: var(--text-primary);
            font-size: 14px;
            margin-top: 12px;
        }

        .delete-confirm-input:focus {
            outline: none;
            border-color: var(--danger);
        }

        .delete-checkbox-option {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 12px;
            background: var(--bg-tertiary);
            border-radius: 8px;
            margin-bottom: 12px;
            cursor: pointer;
        }

        .delete-checkbox-option input {
            width: 18px;
            height: 18px;
        }

        .delete-checkbox-option span {
            font-size: 14px;
        }

        /* File Browser Modal - larger */
        .modal.file-browser {
            max-width: 1000px;
            height: 80vh;
        }

        .file-browser-content {
            display: flex;
            height: 100%;
        }

        .file-tree {
            width: 280px;
            border-right: 1px solid var(--border-color);
            overflow-y: auto;
            padding: 12px;
        }

        .file-tree-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            color: var(--text-secondary);
        }

        .file-tree-item:hover {
            background: var(--bg-tertiary);
            color: var(--text-primary);
        }

        .file-tree-item.active {
            background: var(--accent-glow);
            color: var(--accent-primary);
        }

        .file-tree-item.directory {
            font-weight: 500;
        }

        .file-tree-item .indent {
            width: 16px;
        }

        .file-editor {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .file-editor-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 16px;
            background: var(--bg-tertiary);
            border-bottom: 1px solid var(--border-color);
        }

        .file-editor-path {
            font-size: 13px;
            color: var(--text-secondary);
            font-family: 'JetBrains Mono', monospace;
        }

        .file-editor-content {
            flex: 1;
            overflow: hidden;
        }

        .CodeMirror {
            height: 100%;
            font-size: 13px;
        }

        .no-file-selected {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-muted);
        }

        /* File upload area */
        .upload-area {
            border: 2px dashed var(--border-color);
            border-radius: 12px;
            padding: 40px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
        }

        .upload-area:hover, .upload-area.dragover {
            border-color: var(--accent-primary);
            background: var(--accent-glow);
        }

        .upload-area i {
            font-size: 40px;
        }

        /* Scroll to bottom button */
        .scroll-to-bottom-btn {
            position: absolute;
            bottom: 16px;
            right: 24px;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: var(--accent-primary);
            color: white;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
            transition: all 0.2s;
            z-index: 10;
        }

        .scroll-to-bottom-btn:hover {
            background: var(--accent-hover);
            transform: scale(1.1);
            color: var(--text-muted);
            margin-bottom: 12px;
        }

        .upload-area p {
            margin: 0;
            color: var(--text-secondary);
        }

        .upload-area input {
            display: none;
        }
    </style>
</head>
<body>
    <?php $layout->renderSidebar(); ?>
    
    <main class="main-content">
        <div class="content-area">
            <!-- App Header & Navigation (from AppContext) -->
            <?php $app->renderAppHeader(); ?>

            <div id="projects-app">
                <!-- Toolbar -->
                <div class="toolbar">
                    <div class="toolbar-left">
                        <div class="search-box">
                            <i class="fas fa-search"></i>
                            <input type="text" v-model="searchQuery" placeholder="Search projects...">
                        </div>
                    </div>
                    <div class="toolbar-right">
                        <button
                            v-for="session in activeRunSessions"
                            :key="session.sessionId"
                            class="btn btn-secondary run-session-btn"
                            :class="sessionIndicatorClass(session)"
                            @click="reopenRunModal(session.sessionId)"
                        >
                            <span class="run-session-dot"></span>
                            <i :class="sessionIndicatorIcon(session)"></i>
                            {{ sessionIndicatorText(session) }}
                        </button>
                        <button
                            v-if="activeRunSessions.length === 0 && latestRunSession"
                            class="btn btn-secondary run-session-btn"
                            :class="sessionIndicatorClass(latestRunSession)"
                            @click="reopenRunModal(latestRunSession.sessionId)"
                        >
                            <span class="run-session-dot"></span>
                            <i :class="sessionIndicatorIcon(latestRunSession)"></i>
                            {{ sessionIndicatorText(latestRunSession) }}
                        </button>
                        <?php if ($isAdmin): ?>
                        <button class="btn btn-primary" @click="openCreateModal">
                            <i class="fas fa-plus"></i>
                            New Project
                        </button>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Loading -->
                <div v-if="loading" class="empty-state">
                    <i class="fas fa-spinner fa-spin"></i>
                    <h3>Loading projects...</h3>
                </div>

                <!-- Empty State -->
                <div v-else-if="filteredProjects.length === 0 && !searchQuery" class="empty-state">
                    <i class="fas fa-folder-open"></i>
                    <h3>No Projects Yet</h3>
                    <p>Create your first Playwright test project to get started.</p>
                    <?php if ($isAdmin): ?>
                    <button class="btn btn-primary" @click="openCreateModal">
                        <i class="fas fa-plus"></i>
                        Create Project
                    </button>
                    <?php endif; ?>
                </div>

                <!-- No Results -->
                <div v-else-if="filteredProjects.length === 0" class="empty-state">
                    <i class="fas fa-search"></i>
                    <h3>No Results</h3>
                    <p>No projects match "{{ searchQuery }}"</p>
                </div>

                <!-- Projects Grid -->
                <div v-else class="projects-grid">
                    <div v-for="project in filteredProjects" :key="project.id" class="project-card">
                        <div class="project-card-header">
                            <div class="project-card-title">
                                <h3>{{ project.name }}</h3>
                                <span class="project-source-badge" :class="project.source">
                                    <i :class="project.source === 'git' || project.source === 'repository' ? 'fab fa-git-alt' : 'fas fa-folder'"></i>
                                    {{ project.source }}
                                </span>
                            </div>
                            <p class="project-card-desc">{{ project.description || 'No description' }}</p>
                        </div>
                        <div class="project-card-body">
                            <div class="project-stats">
                                <div class="project-stat">
                                    <i class="fas fa-vial"></i>
                                    <span>{{ project.testCount }} tests</span>
                                </div>
                                <div class="project-stat" v-if="project.updatedAt">
                                    <i class="fas fa-clock"></i>
                                    <span>{{ formatDate(project.updatedAt) }}</span>
                                </div>
                                <div class="project-stat" v-if="project.source === 'repository' && project.sourceRepositoryAvailable === false">
                                    <i class="fas fa-unlink"></i>
                                    <span>Source removed</span>
                                </div>
                            </div>
                        </div>
                        <div class="project-card-footer">
                            <button class="btn btn-primary btn-sm" @click="openRunModal(project)" :disabled="project.testCount === 0">
                                <i class="fas fa-play"></i>
                                Run
                            </button>
                            <button class="btn btn-secondary btn-sm" @click="openFileBrowser(project)">
                                <i class="fas fa-folder-open"></i>
                                Browse
                            </button>
                            <?php if ($isAdmin): ?>
                            <button class="btn btn-secondary btn-sm" v-if="project.source === 'git'" @click="syncProject(project)" :disabled="syncing === project.id">
                                <i class="fas fa-sync" :class="{ 'fa-spin': syncing === project.id }"></i>
                                Sync
                            </button>
                            <button class="btn btn-secondary btn-sm" v-if="!isRepositoryManagedProject(project)" @click="openEditModal(project)">
                                <i class="fas fa-edit"></i>
                                Edit
                            </button>
                            <button class="btn btn-danger btn-sm" v-if="!isRepositoryManagedProject(project)" @click="openDeleteModal(project)">
                                <i class="fas fa-trash"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Create/Edit Project Modal -->
                <div class="modal-backdrop" :class="{ active: showProjectModal }" @click.self="closeProjectModal">
                    <div class="modal">
                        <div class="modal-header">
                            <h2>{{ editingProject ? 'Edit Project' : 'Create Project' }}</h2>
                            <button class="modal-close" @click="closeProjectModal">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        <div class="modal-body">
                            <div class="form-group">
                                <label class="form-label">Project Name *</label>
                                <input type="text" class="form-input" v-model="projectForm.name" placeholder="My Test Project">
                            </div>

                            <div class="form-group">
                                <label class="form-label">Description</label>
                                <textarea class="form-textarea" v-model="projectForm.description" placeholder="What does this project test?"></textarea>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Source Type</label>
                                <div class="source-selector">
                                    <div class="source-option" :class="{ selected: projectForm.source === 'local' }" @click="projectForm.source = 'local'">
                                        <i class="fas fa-folder"></i>
                                        <span>Local</span>
                                    </div>
                                    <div class="source-option" :class="{ selected: projectForm.source === 'git' }" @click="projectForm.source = 'git'">
                                        <i class="fab fa-git-alt"></i>
                                        <span>Git Repository</span>
                                    </div>
                                </div>
                            </div>

                            <template v-if="projectForm.source === 'local' && !editingProject">
                                <div class="form-group">
                                    <label class="form-label">Upload Tests *</label>
                                    <div class="file-drop-zone" @click="$refs.projectFileInput.click()" @dragover.prevent="$event.currentTarget.classList.add('dragover')" @dragleave="$event.currentTarget.classList.remove('dragover')" @drop.prevent="$event.currentTarget.classList.remove('dragover'); projectFile = $event.dataTransfer.files[0]">
                                        <template v-if="projectFile">
                                            <i class="fas fa-file-archive" style="font-size:24px;color:var(--accent-primary);"></i>
                                            <span style="font-weight:600;">{{ projectFile.name }}</span>
                                            <span style="font-size:12px;color:var(--text-muted);">{{ (projectFile.size / 1024).toFixed(1) }} KB</span>
                                            <button class="btn btn-secondary btn-sm" @click.stop="projectFile = null" style="margin-top:4px;">
                                                <i class="fas fa-times"></i> Remove
                                            </button>
                                        </template>
                                        <template v-else>
                                            <i class="fas fa-cloud-upload-alt" style="font-size:24px;color:var(--text-muted);"></i>
                                            <span>Drop a ZIP file here or click to browse</span>
                                            <span style="font-size:12px;color:var(--text-muted);">ZIP containing your Playwright tests</span>
                                        </template>
                                    </div>
                                    <input type="file" ref="projectFileInput" accept=".zip" style="display:none;" @change="projectFile = $event.target.files[0]">
                                </div>
                            </template>

                            <template v-if="projectForm.source === 'git'">
                                <div class="form-group">
                                    <label class="form-label">Git Provider</label>
                                    <select class="form-select" v-model="projectForm.gitProvider">
                                        <option value="">-- Select Provider --</option>
                                        <option v-for="provider in gitProviders" :key="provider.id" :value="provider.id">
                                            {{ provider.name }}
                                        </option>
                                    </select>
                                    <span class="form-hint">Configure providers in <a href="../../admin/sources.php" style="color: var(--accent-primary);">Admin &gt; Sources</a></span>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Repository URL *</label>
                                    <input type="text" class="form-input" v-model="projectForm.gitUrl" placeholder="https://github.com/org/repo.git">
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Branch</label>
                                    <input type="text" class="form-input" v-model="projectForm.gitBranch" placeholder="main">
                                </div>
                            </template>

                            <!-- Wrapper Settings Accordion -->
                            <div class="settings-accordion">
                                <div class="accordion-header" @click="showWrapperSettings = !showWrapperSettings">
                                    <div class="accordion-title">
                                        <i class="fas fa-cog"></i>
                                        Playwright Override Settings
                                    </div>
                                    <i class="fas fa-chevron-down accordion-toggle" :class="{ open: showWrapperSettings }"></i>
                                </div>
                                <div class="accordion-body" v-show="showWrapperSettings">
                                    <!-- Reporters -->
                                    <div class="setting-section">
                                        <div class="setting-section-header">
                                            <input type="checkbox" id="overrideReporters" v-model="projectForm.wrapperConfig.overrideReporters">
                                            <label for="overrideReporters">Override Reporters</label>
                                        </div>
                                        <div class="checkbox-group" v-if="projectForm.wrapperConfig.overrideReporters">
                                            <label class="checkbox-item">
                                                <input type="checkbox" v-model="projectForm.wrapperConfig.reporters.html">
                                                HTML Report
                                            </label>
                                            <label class="checkbox-item">
                                                <input type="checkbox" v-model="projectForm.wrapperConfig.reporters.list">
                                                List (Console)
                                            </label>
                                            <label class="checkbox-item">
                                                <input type="checkbox" v-model="projectForm.wrapperConfig.reporters.json">
                                                JSON
                                            </label>
                                            <label class="checkbox-item">
                                                <input type="checkbox" v-model="projectForm.wrapperConfig.reporters.junit">
                                                JUnit XML
                                            </label>
                                        </div>
                                    </div>

                                    <!-- Recording -->
                                    <div class="setting-section">
                                        <div class="setting-section-header">
                                            <input type="checkbox" id="overrideRecording" v-model="projectForm.wrapperConfig.overrideRecording">
                                            <label for="overrideRecording">Override Recording Settings</label>
                                        </div>
                                        <template v-if="projectForm.wrapperConfig.overrideRecording">
                                            <div class="setting-row">
                                                <label>Video:</label>
                                                <select v-model="projectForm.wrapperConfig.recording.video">
                                                    <option value="on">Always On</option>
                                                    <option value="off">Off</option>
                                                    <option value="on-first-retry">On First Retry</option>
                                                    <option value="retain-on-failure">Retain on Failure</option>
                                                </select>
                                            </div>
                                            <div class="setting-row">
                                                <label>Screenshot:</label>
                                                <select v-model="projectForm.wrapperConfig.recording.screenshot">
                                                    <option value="on">Always On</option>
                                                    <option value="off">Off</option>
                                                    <option value="only-on-failure">Only on Failure</option>
                                                </select>
                                            </div>
                                            <div class="setting-row">
                                                <label>Trace:</label>
                                                <select v-model="projectForm.wrapperConfig.recording.trace">
                                                    <option value="on">Always On</option>
                                                    <option value="off">Off</option>
                                                    <option value="on-first-retry">On First Retry</option>
                                                    <option value="retain-on-failure">Retain on Failure</option>
                                                </select>
                                            </div>
                                        </template>
                                    </div>

                                    <!-- Execution -->
                                    <div class="setting-section">
                                        <div class="setting-section-header">
                                            <input type="checkbox" id="overrideExecution" v-model="projectForm.wrapperConfig.overrideExecution">
                                            <label for="overrideExecution">Override Execution Settings</label>
                                        </div>
                                        <template v-if="projectForm.wrapperConfig.overrideExecution">
                                            <div class="setting-row">
                                                <label>Retries:</label>
                                                <input type="number" v-model.number="projectForm.wrapperConfig.execution.retries" min="0" max="10">
                                            </div>
                                            <div class="setting-row">
                                                <label>Workers:</label>
                                                <input type="number" v-model.number="projectForm.wrapperConfig.execution.workers" min="1" max="16">
                                            </div>
                                            <div class="setting-row">
                                                <label>Timeout (ms):</label>
                                                <input type="number" v-model.number="projectForm.wrapperConfig.execution.timeout" min="1000" step="1000">
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button class="btn btn-secondary" @click="closeProjectModal">Cancel</button>
                            <button class="btn btn-primary" @click="saveProject" :disabled="saving">
                                <i class="fas fa-save"></i>
                                {{ saving ? 'Saving...' : (editingProject ? 'Update' : 'Create') }}
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Delete Confirmation Modal -->
                <div class="modal-backdrop" :class="{ active: showDeleteModal }" @click.self="closeDeleteModal">
                    <div class="modal">
                        <div class="modal-header">
                            <h2 style="color: var(--danger);">
                                <i class="fas fa-exclamation-triangle"></i>
                                Delete Project
                            </h2>
                            <button class="modal-close" @click="closeDeleteModal">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        <div class="modal-body">
                            <p style="margin-bottom: 16px;">
                                <strong>Delete "{{ deleteTarget?.projectName }}"?</strong><br>
                                <span style="color: var(--text-secondary);">This action cannot be undone.</span>
                            </p>

                            <div class="delete-summary" v-if="deleteTarget">
                                <div class="delete-summary-item">
                                    <div class="delete-summary-icon files">
                                        <i class="fas fa-folder"></i>
                                    </div>
                                    <div class="delete-summary-content">
                                        <strong>Project Files</strong>
                                        <span>{{ deleteTarget.testCount }} test file(s) • {{ deleteTarget.projectSizeFormatted }}</span>
                                    </div>
                                </div>
                                <div class="delete-summary-item" v-if="deleteTarget.reportCount > 0">
                                    <div class="delete-summary-icon reports">
                                        <i class="fas fa-chart-bar"></i>
                                    </div>
                                    <div class="delete-summary-content">
                                        <strong>Related Reports</strong>
                                        <span>{{ deleteTarget.reportCount }} report(s) • {{ deleteTarget.reportsSizeFormatted }}</span>
                                    </div>
                                </div>
                                <div class="delete-summary-item" v-if="deleteTarget.videoCount > 0 || deleteTarget.screenshotCount > 0">
                                    <div class="delete-summary-icon media">
                                        <i class="fas fa-video"></i>
                                    </div>
                                    <div class="delete-summary-content">
                                        <strong>Media Files</strong>
                                        <span>{{ deleteTarget.videoCount }} video(s) • {{ deleteTarget.screenshotCount }} screenshot(s)</span>
                                    </div>
                                </div>
                            </div>

                            <label class="delete-checkbox-option" v-if="deleteTarget?.reportCount > 0">
                                <input type="checkbox" v-model="deleteReports">
                                <span>Also delete all related reports and media</span>
                            </label>

                            <div style="font-size: 13px; color: var(--text-muted); margin-bottom: 8px;">
                                Type <strong>{{ deleteTarget?.projectName }}</strong> to confirm:
                            </div>
                            <input 
                                type="text" 
                                class="delete-confirm-input" 
                                v-model="deleteConfirmText"
                                :placeholder="deleteTarget?.projectName"
                            >
                        </div>
                        <div class="modal-footer">
                            <button class="btn btn-secondary" @click="closeDeleteModal">Cancel</button>
                            <button 
                                class="btn btn-danger" 
                                @click="confirmDelete" 
                                :disabled="deleteConfirmText !== deleteTarget?.projectName || deleting"
                            >
                                <i class="fas fa-trash"></i>
                                {{ deleting ? 'Deleting...' : 'Delete Permanently' }}
                            </button>
                        </div>
                    </div>
                </div>

                <!-- File Browser Modal -->
                <div class="modal-backdrop" :class="{ active: showFileBrowser }" @click.self="closeFileBrowser">
                    <div class="modal file-browser">
                        <div class="modal-header">
                            <h2>{{ browsingProject?.name || 'Files' }}</h2>
                            <div style="display: flex; gap: 8px;">
                                <?php if ($isAdmin): ?>
                                <button class="btn btn-secondary btn-sm" v-if="!isRepositoryManagedProject(browsingProject)" @click="showUploadModal = true">
                                    <i class="fas fa-upload"></i>
                                    Upload
                                </button>
                                <button class="btn btn-secondary btn-sm" v-if="!isRepositoryManagedProject(browsingProject)" @click="createNewFile">
                                    <i class="fas fa-plus"></i>
                                    New File
                                </button>
                                <?php endif; ?>
                                <button class="modal-close" @click="closeFileBrowser">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="modal-body" style="padding: 0; display: flex; height: calc(100% - 70px);">
                            <div class="file-browser-content">
                                <!-- File Tree -->
                                <div class="file-tree">
                                    <div v-if="loadingFiles" style="padding: 20px; text-align: center; color: var(--text-muted);">
                                        <i class="fas fa-spinner fa-spin"></i> Loading...
                                    </div>
                                    <template v-else>
                                        <div v-for="item in fileTree" :key="item.path">
                                            <div class="file-tree-item" 
                                                 :class="{ directory: item.isDirectory, active: selectedFile?.path === item.path }"
                                                 @click="selectFileItem(item)">
                                                <i :class="getFileIcon(item)"></i>
                                                {{ item.name }}
                                            </div>
                                            <template v-if="item.isDirectory && item.expanded">
                                                <div v-for="child in item.children" :key="child.path">
                                                    <div class="file-tree-item"
                                                         :class="{ directory: child.isDirectory, active: selectedFile?.path === child.path }"
                                                         style="padding-left: 28px;"
                                                         @click="selectFileItem(child)">
                                                        <i :class="getFileIcon(child)"></i>
                                                        {{ child.name }}
                                                    </div>
                                                </div>
                                            </template>
                                        </div>
                                    </template>
                                </div>

                                <!-- File Editor -->
                                <div class="file-editor">
                                    <template v-if="selectedFile">
                                        <div class="file-editor-header">
                                            <span class="file-editor-path">{{ selectedFile.path }}</span>
                                            <div style="display: flex; gap: 8px;">
                                                <?php if ($isAdmin): ?>
                                                <button class="btn btn-sm btn-primary" v-if="!isRepositoryManagedProject(browsingProject)" @click="saveFile" :disabled="savingFile">
                                                    <i class="fas fa-save"></i>
                                                    {{ savingFile ? 'Saving...' : 'Save' }}
                                                </button>
                                                <button class="btn btn-sm btn-danger" v-if="!isRepositoryManagedProject(browsingProject)" @click="deleteFile">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="file-editor-content">
                                            <textarea id="code-editor"></textarea>
                                        </div>
                                    </template>
                                    <div v-else class="no-file-selected">
                                        <span>Select a file to view or edit</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Upload Modal -->
                <div class="modal-backdrop" :class="{ active: showUploadModal }" @click.self="showUploadModal = false">
                    <div class="modal" style="max-width: 500px;">
                        <div class="modal-header">
                            <h2>Upload Files</h2>
                            <button class="modal-close" @click="showUploadModal = false">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        <div class="modal-body">
                            <div class="upload-area" 
                                 :class="{ dragover: isDragging }"
                                 @dragover.prevent="isDragging = true"
                                 @dragleave="isDragging = false"
                                 @drop.prevent="handleDrop"
                                 @click="$refs.fileInput.click()">
                                <i class="fas fa-cloud-upload-alt"></i>
                                <p>Drag & drop files here or click to browse</p>
                                <p style="font-size: 12px; margin-top: 8px;">Supports .ts, .js, .json, .yaml, or .zip</p>
                                <input type="file" ref="fileInput" @change="handleFileSelect" multiple accept=".ts,.js,.json,.yaml,.yml,.zip">
                            </div>
                            <div v-if="uploadProgress" style="margin-top: 16px; text-align: center;">
                                <i class="fas fa-spinner fa-spin"></i> Uploading...
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Run Tests Modal -->
                <div class="modal-backdrop" :class="{ active: showRunModal }" @click.self="closeRunModal">
                    <div class="modal" style="max-width: 700px;">
                        <div class="modal-header">
                            <h2>
                                <i class="fas fa-play" style="color: var(--accent-primary); margin-right: 8px;"></i>
                                Run Tests - {{ runProject?.name }}
                            </h2>
                            <button class="modal-close" @click="closeRunModal" title="Close (run continues in background)">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        <div class="modal-body">
                            <!-- Configuration (hidden during run) -->
                            <div v-if="!running && !runComplete">
                                <div class="form-group">
                                    <label class="form-label">Test Name</label>
                                    <input type="text" class="form-input" v-model="runForm.testName" placeholder="e.g., Smoke Test, Regression">
                                    <span class="form-hint">A descriptive name for this test run</span>
                                </div>

                                <div class="form-group" v-if="testEnvironments.length > 0">
                                    <label class="form-label">Environment</label>
                                    <select class="form-select" v-model="runForm.environment">
                                        <option value="">-- Default --</option>
                                        <option v-for="env in testEnvironments" :key="env.id" :value="env.id">
                                            {{ env.name }} ({{ env.baseUrl }})
                                        </option>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Test Files</label>
                                    <div style="margin-bottom: 8px;">
                                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                                            <input type="checkbox" v-model="runForm.runAll" style="accent-color: var(--accent-primary);">
                                            <span>Run all tests</span>
                                        </label>
                                    </div>
                                    <div v-if="!runForm.runAll" style="max-height: 200px; overflow-y: auto; border: 1px solid var(--border-color); border-radius: 8px; padding: 12px;">
                                        <div v-if="loadingTests" style="text-align: center; color: var(--text-muted);">
                                            <i class="fas fa-spinner fa-spin"></i> Loading tests...
                                        </div>
                                        <div v-else-if="availableTests.length === 0" style="color: var(--text-muted);">
                                            No test files found
                                        </div>
                                        <label v-else v-for="test in availableTests" :key="test" style="display: flex; align-items: center; gap: 8px; padding: 6px 0; cursor: pointer;">
                                            <input type="checkbox" :value="test" v-model="runForm.selectedTests" style="accent-color: var(--accent-primary);">
                                            <span style="font-family: monospace; font-size: 13px;">{{ test }}</span>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <!-- Running / Output -->
                            <div v-if="running || runComplete">
                                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px;">
                                    <div style="display: flex; align-items: center; gap: 12px;">
                                        <div v-if="running" style="display: flex; align-items: center; gap: 8px; color: var(--accent-primary);">
                                            <i class="fas fa-spinner fa-spin"></i>
                                            <span>Running tests...</span>
                                        </div>
                                        <div v-else-if="runStatus === 'completed'" style="display: flex; align-items: center; gap: 8px; color: var(--success);">
                                            <i class="fas fa-check-circle"></i>
                                            <span>Tests passed!</span>
                                        </div>
                                        <div v-else-if="runStatus === 'cancelled'" style="display: flex; align-items: center; gap: 8px; color: #f59e0b;">
                                            <i class="fas fa-ban"></i>
                                            <span>Run cancelled</span>
                                        </div>
                                        <div v-else style="display: flex; align-items: center; gap: 8px; color: var(--danger);">
                                            <i class="fas fa-times-circle"></i>
                                            <span>Tests failed</span>
                                        </div>
                                    </div>
                                    <button class="btn btn-secondary btn-sm" @click="copyOutput" title="Copy to clipboard">
                                        <i class="fas fa-copy"></i>
                                        Copy
                                    </button>
                                </div>
                                
                                <div style="position: relative;">
                                    <div ref="outputContainer" 
                                         style="background: #1e1e2e; border-radius: 8px; padding: 16px; max-height: 400px; overflow-y: auto;"
                                         @scroll="handleOutputScroll">
                                        <pre style="color: #cdd6f4; font-family: 'JetBrains Mono', monospace; font-size: 12px; margin: 0; white-space: pre-wrap;">{{ runOutput }}</pre>
                                    </div>
                                    <button v-if="!autoScroll && (running || runOutput)" 
                                            class="scroll-to-bottom-btn"
                                            @click="scrollToBottom"
                                            title="Scroll to bottom">
                                        <i class="fas fa-arrow-down"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button v-if="running" class="btn btn-secondary" @click="closeRunModal">
                                <i class="fas fa-times"></i>
                                Close
                            </button>
                            <button v-if="running" class="btn btn-danger" @click="cancelTestRun">
                                <i class="fas fa-stop"></i>
                                Stop Run
                            </button>
                            <button v-if="!running && !runComplete" class="btn btn-secondary" @click="closeRunModal">Cancel</button>
                            <button v-if="!running && !runComplete" class="btn btn-primary" @click="startTestRun" :disabled="!runForm.runAll && runForm.selectedTests.length === 0">
                                <i class="fas fa-play"></i>
                                Start Test Run
                            </button>
                            <button v-if="runComplete" class="btn btn-secondary" @click="closeRunModal">Close</button>
                            <a v-if="runComplete" href="reports.php" class="btn btn-primary">
                                <i class="fas fa-chart-bar"></i>
                                View Reports
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/vue@3/dist/vue.global.prod.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/codemirror.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/mode/javascript/javascript.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/mode/yaml/yaml.min.js"></script>
    <script>
        const settings = <?php echo json_encode($settings); ?>;
        const gitProviders = <?php echo json_encode($gitProviders); ?>;

        const app = Vue.createApp({
            data() {
                return {
                    projects: [],
                    loading: true,
                    searchQuery: '',
                    showProjectModal: false,
                    editingProject: null,
                    projectForm: {
                        name: '',
                        description: '',
                        source: 'local',
                        gitProvider: '',
                        gitUrl: '',
                        gitBranch: 'main',
                        wrapperConfig: {
                            overrideReporters: true,
                            reporters: { html: true, list: true, json: false, junit: false },
                            overrideRecording: true,
                            recording: { video: 'on-first-retry', screenshot: 'only-on-failure', trace: 'on-first-retry' },
                            overrideExecution: false,
                            execution: { retries: 2, workers: 1, timeout: 180000 }
                        }
                    },
                    projectFile: null,
                    saving: false,
                    syncing: null,
                    gitProviders: gitProviders || [],
                    showWrapperSettings: false,
                    defaultWrapperConfig: null,
                    
                    // Delete modal
                    showDeleteModal: false,
                    deleteTarget: null,
                    deleteReports: true,
                    deleteConfirmText: '',
                    deleting: false,
                    
                    // File browser
                    showFileBrowser: false,
                    browsingProject: null,
                    fileTree: [],
                    loadingFiles: false,
                    selectedFile: null,
                    fileContent: '',
                    savingFile: false,
                    editor: null,

                    // Upload
                    showUploadModal: false,
                    isDragging: false,
                    uploadProgress: false,

                    // Run tests
                    showRunModal: false,
                    runProject: null,
                    runForm: {
                        testName: '',
                        environment: '',
                        runAll: true,
                        selectedTests: []
                    },
                    availableTests: [],
                    loadingTests: false,
                    running: false,
                    runComplete: false,
                    runStatus: '',
                    runOutput: '',
                    runJobId: null,
                    runPolling: null,
                    runSessions: [],
                    activeRunSessionId: null,
                    testEnvironments: settings.testEnvironments || [],
                    autoScroll: true
                };
            },

            computed: {
                filteredProjects() {
                    if (!this.searchQuery) return this.projects;
                    const q = this.searchQuery.toLowerCase();
                    return this.projects.filter(p => 
                        p.name.toLowerCase().includes(q) || 
                        (p.description && p.description.toLowerCase().includes(q))
                    );
                },

                activeRunSessions() {
                    return this.runSessions
                        .filter(session => session.running)
                        .sort((a, b) => b.updatedAt - a.updatedAt);
                },

                latestRunSession() {
                    if (this.runSessions.length === 0) return null;
                    return [...this.runSessions].sort((a, b) => b.updatedAt - a.updatedAt)[0];
                }
            },

            methods: {
                async loadProjects() {
                    this.loading = true;
                    try {
                        const response = await fetch('../../api/playwright.php?action=projects');
                        const data = await response.json();
                        if (data.success) {
                            this.projects = data.projects;
                        }
                    } catch (error) {
                        console.error('Failed to load projects:', error);
                        window.Toast?.error('Failed to load projects');
                    } finally {
                        this.loading = false;
                    }
                },

                formatDate(dateStr) {
                    if (!dateStr) return '';
                    const date = new Date(dateStr);
                    return date.toLocaleDateString();
                },

                isRepositoryManagedProject(project) {
                    return !!project && project.source === 'repository' && project.sourceRepositoryAvailable !== false;
                },

                async openCreateModal() {
                    this.editingProject = null;
                    // Load default wrapper config if not cached
                    if (!this.defaultWrapperConfig) {
                        await this.loadDefaultWrapperConfig();
                    }
                    this.projectForm = {
                        name: '',
                        description: '',
                        source: 'local',
                        gitProvider: '',
                        gitUrl: '',
                        gitBranch: 'main',
                        wrapperConfig: JSON.parse(JSON.stringify(this.defaultWrapperConfig))
                    };
                    this.showWrapperSettings = false;
                    this.showProjectModal = true;
                },

                openEditModal(project) {
                    this.editingProject = project;
                    // Use project's wrapperConfig or fall back to defaults
                    const wrapperConfig = project.wrapperConfig || this.defaultWrapperConfig || {
                        overrideReporters: true,
                        reporters: { html: true, list: true, json: false, junit: false },
                        overrideRecording: true,
                        recording: { video: 'on-first-retry', screenshot: 'only-on-failure', trace: 'on-first-retry' },
                        overrideExecution: false,
                        execution: { retries: 2, workers: 1, timeout: 180000 }
                    };
                    this.projectForm = {
                        name: project.name,
                        description: project.description || '',
                        source: project.source === 'repository' ? 'local' : (project.source || 'local'),
                        gitProvider: project.gitProvider || '',
                        gitUrl: project.gitUrl || '',
                        gitBranch: project.gitBranch || 'main',
                        wrapperConfig: JSON.parse(JSON.stringify(wrapperConfig))
                    };
                    this.showWrapperSettings = false;
                    this.showProjectModal = true;
                },

                async loadDefaultWrapperConfig() {
                    try {
                        const response = await fetch('../../api/playwright.php?action=default-wrapper-config');
                        const data = await response.json();
                        if (data.success) {
                            this.defaultWrapperConfig = data.wrapperConfig;
                        }
                    } catch (error) {
                        console.error('Failed to load default wrapper config:', error);
                        // Use hardcoded defaults
                        this.defaultWrapperConfig = {
                            overrideReporters: true,
                            reporters: { html: true, list: true, json: false, junit: false },
                            overrideRecording: true,
                            recording: { video: 'on-first-retry', screenshot: 'only-on-failure', trace: 'on-first-retry' },
                            overrideExecution: false,
                            execution: { retries: 2, workers: 1, timeout: 180000 }
                        };
                    }
                },

                closeProjectModal() {
                    this.showProjectModal = false;
                    this.editingProject = null;
                    this.projectFile = null;
                },

                async saveProject() {
                    if (!this.projectForm.name) {
                        window.Toast?.error('Project name is required');
                        return;
                    }
                    if (!this.editingProject && this.projectForm.source === 'local' && !this.projectFile) {
                        window.Toast?.error('Please upload a ZIP file with your tests');
                        return;
                    }

                    this.saving = true;
                    try {
                        const action = this.editingProject ? 'update-project' : 'create-project';
                        const url = this.editingProject
                            ? `../../api/playwright.php?action=${action}&id=${this.editingProject.id}`
                            : `../../api/playwright.php?action=${action}`;

                        let fetchOpts;
                        if (!this.editingProject && this.projectForm.source === 'local' && this.projectFile) {
                            const fd = new FormData();
                            fd.append('file', this.projectFile);
                            fd.append('name', this.projectForm.name);
                            fd.append('description', this.projectForm.description || '');
                            fd.append('source', 'local');
                            fd.append('wrapperConfig', JSON.stringify(this.projectForm.wrapperConfig));
                            fetchOpts = { method: 'POST', body: fd };
                        } else {
                            fetchOpts = {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify(this.projectForm)
                            };
                        }

                        const response = await fetch(url, fetchOpts);

                        const data = await response.json();

                        if (data.success) {
                            window.Toast?.success(this.editingProject ? 'Project updated' : 'Project created');
                            this.closeProjectModal();
                            this.loadProjects();
                        } else {
                            window.Toast?.error(data.error || 'Failed to save project');
                        }
                    } catch (error) {
                        console.error('Save error:', error);
                        window.Toast?.error('Failed to save project');
                    } finally {
                        this.saving = false;
                    }
                },

                async syncProject(project) {
                    this.syncing = project.id;
                    try {
                        const response = await fetch(`../../api/playwright.php?action=sync-project&id=${project.id}`, {
                            method: 'POST'
                        });
                        const data = await response.json();

                        if (data.success) {
                            window.Toast?.success('Project synced from git');
                            this.loadProjects();
                        } else {
                            window.Toast?.error(data.error || 'Sync failed');
                        }
                    } catch (error) {
                        console.error('Sync error:', error);
                        window.Toast?.error('Failed to sync project');
                    } finally {
                        this.syncing = null;
                    }
                },

                async openDeleteModal(project) {
                    // Load deletion summary
                    try {
                        const response = await fetch(`../../api/playwright.php?action=delete-summary&id=${project.id}`);
                        const data = await response.json();
                        
                        if (data.success) {
                            this.deleteTarget = data;
                            this.deleteReports = true;
                            this.deleteConfirmText = '';
                            this.showDeleteModal = true;
                        } else {
                            window.Toast?.error(data.error || 'Failed to load project info');
                        }
                    } catch (error) {
                        console.error('Delete summary error:', error);
                        window.Toast?.error('Failed to load project info');
                    }
                },

                closeDeleteModal() {
                    this.showDeleteModal = false;
                    this.deleteTarget = null;
                    this.deleteConfirmText = '';
                },

                async confirmDelete() {
                    if (!this.deleteTarget || this.deleteConfirmText !== this.deleteTarget.projectName) {
                        return;
                    }

                    this.deleting = true;
                    try {
                        const response = await fetch(`../../api/playwright.php?action=delete-project&id=${this.deleteTarget.projectId}`, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ deleteReports: this.deleteReports })
                        });
                        const data = await response.json();

                        if (data.success) {
                            let message = 'Project deleted';
                            if (data.deletedReports > 0) {
                                message += ` along with ${data.deletedReports} report(s)`;
                            }
                            window.Toast?.success(message);
                            this.closeDeleteModal();
                            this.loadProjects();
                        } else {
                            window.Toast?.error(data.error || 'Failed to delete project');
                        }
                    } catch (error) {
                        console.error('Delete error:', error);
                        window.Toast?.error('Failed to delete project');
                    } finally {
                        this.deleting = false;
                    }
                },

                async openFileBrowser(project) {
                    this.browsingProject = project;
                    this.showFileBrowser = true;
                    this.selectedFile = null;
                    this.fileTree = [];
                    this.loadingFiles = true;

                    try {
                        const response = await fetch(`../../api/playwright.php?action=files&id=${project.id}`);
                        const data = await response.json();
                        if (data.success) {
                            this.fileTree = data.files.map(f => ({ ...f, expanded: false }));
                        }
                    } catch (error) {
                        console.error('Failed to load files:', error);
                    } finally {
                        this.loadingFiles = false;
                    }
                },

                closeFileBrowser() {
                    this.showFileBrowser = false;
                    this.browsingProject = null;
                    this.selectedFile = null;
                    if (this.editor) {
                        this.editor.toTextArea();
                        this.editor = null;
                    }
                },

                getFileIcon(item) {
                    if (item.isDirectory) {
                        return item.expanded ? 'fas fa-folder-open' : 'fas fa-folder';
                    }
                    const ext = item.extension?.toLowerCase();
                    const icons = {
                        ts: 'fab fa-js text-blue',
                        js: 'fab fa-js text-yellow',
                        json: 'fas fa-file-code',
                        yaml: 'fas fa-file-alt',
                        yml: 'fas fa-file-alt',
                        md: 'fas fa-file-alt'
                    };
                    return icons[ext] || 'fas fa-file';
                },

                async selectFileItem(item) {
                    if (item.isDirectory) {
                        item.expanded = !item.expanded;
                        return;
                    }

                    // Load file content
                    try {
                        const response = await fetch(`../../api/playwright.php?action=file&id=${this.browsingProject.id}&path=${encodeURIComponent(item.path)}`);
                        const data = await response.json();

                        if (data.success && data.file) {
                            this.selectedFile = data.file;
                            this.$nextTick(() => {
                                this.initEditor(data.file.content || '');
                            });
                        }
                    } catch (error) {
                        console.error('Failed to load file:', error);
                        window.Toast?.error('Failed to load file');
                    }
                },

                initEditor(content) {
                    const textarea = document.getElementById('code-editor');
                    if (!textarea) return;

                    if (this.editor) {
                        this.editor.toTextArea();
                    }

                    const mode = this.selectedFile?.extension === 'yaml' || this.selectedFile?.extension === 'yml' 
                        ? 'yaml' 
                        : 'javascript';

                    this.editor = CodeMirror.fromTextArea(textarea, {
                        mode: mode,
                        theme: 'dracula',
                        lineNumbers: true,
                        tabSize: 2,
                        indentWithTabs: false,
                        readOnly: this.isRepositoryManagedProject(this.browsingProject)
                    });

                    this.editor.setValue(content);
                },

                async saveFile() {
                    if (!this.selectedFile || !this.editor) return;

                    this.savingFile = true;
                    try {
                        const response = await fetch(`../../api/playwright.php?action=save-file&id=${this.browsingProject.id}&path=${encodeURIComponent(this.selectedFile.path)}`, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ content: this.editor.getValue() })
                        });

                        const data = await response.json();

                        if (data.success) {
                            window.Toast?.success('File saved');
                        } else {
                            window.Toast?.error(data.error || 'Failed to save file');
                        }
                    } catch (error) {
                        console.error('Save error:', error);
                        window.Toast?.error('Failed to save file');
                    } finally {
                        this.savingFile = false;
                    }
                },

                async deleteFile() {
                    if (!this.selectedFile) return;
                    if (!confirm(`Delete ${this.selectedFile.name}?`)) return;

                    try {
                        const response = await fetch(`../../api/playwright.php?action=delete-file&id=${this.browsingProject.id}&path=${encodeURIComponent(this.selectedFile.path)}`, {
                            method: 'POST'
                        });

                        const data = await response.json();

                        if (data.success) {
                            window.Toast?.success('File deleted');
                            this.selectedFile = null;
                            this.openFileBrowser(this.browsingProject); // Refresh
                        } else {
                            window.Toast?.error(data.error || 'Failed to delete file');
                        }
                    } catch (error) {
                        console.error('Delete error:', error);
                        window.Toast?.error('Failed to delete file');
                    }
                },

                createNewFile() {
                    const filename = prompt('Enter file name (e.g., tests/mytest.test.ts):');
                    if (!filename) return;

                    this.saveNewFile(filename, '');
                },

                async saveNewFile(path, content) {
                    try {
                        const response = await fetch(`../../api/playwright.php?action=create-file&id=${this.browsingProject.id}`, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ path, content })
                        });

                        const data = await response.json();

                        if (data.success) {
                            window.Toast?.success('File created');
                            this.openFileBrowser(this.browsingProject); // Refresh
                        } else {
                            window.Toast?.error(data.error || 'Failed to create file');
                        }
                    } catch (error) {
                        console.error('Create error:', error);
                        window.Toast?.error('Failed to create file');
                    }
                },

                handleDrop(e) {
                    this.isDragging = false;
                    const files = e.dataTransfer.files;
                    this.uploadFiles(files);
                },

                handleFileSelect(e) {
                    const files = e.target.files;
                    this.uploadFiles(files);
                },

                async uploadFiles(files) {
                    if (!files.length || !this.browsingProject) return;

                    this.uploadProgress = true;

                    for (const file of files) {
                        const formData = new FormData();
                        formData.append('file', file);

                        try {
                            const response = await fetch(`../../api/playwright.php?action=upload&id=${this.browsingProject.id}`, {
                                method: 'POST',
                                body: formData
                            });

                            const data = await response.json();

                            if (!data.success) {
                                window.Toast?.error(`Failed to upload ${file.name}: ${data.error}`);
                            }
                        } catch (error) {
                            console.error('Upload error:', error);
                            window.Toast?.error(`Failed to upload ${file.name}`);
                        }
                    }

                    this.uploadProgress = false;
                    this.showUploadModal = false;
                    window.Toast?.success('Upload complete');
                    this.openFileBrowser(this.browsingProject); // Refresh
                },

                // ===== Run Tests Methods =====

                normalizeSelectedTests(selectedTests) {
                    return [...new Set((selectedTests || [])
                        .map(test => (test || '').trim())
                        .filter(Boolean))]
                        .sort();
                },

                findConflictingRun(projectId, runAll, selectedTests) {
                    const normalized = this.normalizeSelectedTests(selectedTests);
                    return this.runSessions.find(session => {
                        if (!session.running || session.projectId !== projectId) {
                            return false;
                        }
                        if (runAll || session.runAll) {
                            return true;
                        }
                        const existing = new Set(session.selectedTests || []);
                        return normalized.some(test => existing.has(test));
                    }) || null;
                },

                createRunSession(payload) {
                    return {
                        sessionId: `run-${Date.now()}-${Math.random().toString(16).slice(2, 8)}`,
                        projectId: payload.projectId,
                        projectName: payload.projectName,
                        testName: payload.testName,
                        environment: payload.environment || '',
                        runAll: payload.runAll,
                        selectedTests: this.normalizeSelectedTests(payload.selectedTests),
                        running: true,
                        runComplete: false,
                        status: 'running',
                        output: 'Starting test run...\n',
                        jobId: null,
                        polling: null,
                        autoScroll: true,
                        createdAt: Date.now(),
                        updatedAt: Date.now()
                    };
                },

                getRunSession(sessionId) {
                    return this.runSessions.find(session => session.sessionId === sessionId) || null;
                },

                syncModalFromSession(session) {
                    if (!session) return;
                    this.activeRunSessionId = session.sessionId;
                    this.runProject = {
                        id: session.projectId,
                        name: session.projectName
                    };
                    this.running = !!session.running;
                    this.runComplete = !!session.runComplete;
                    this.runStatus = session.status || '';
                    this.runOutput = session.output || '';
                    this.runJobId = session.jobId || null;
                    this.autoScroll = session.autoScroll !== false;
                },

                appendSessionOutput(session, text) {
                    if (!session) return;
                    session.output = (session.output || '') + text;
                    session.updatedAt = Date.now();
                    if (this.activeRunSessionId === session.sessionId) {
                        this.runOutput = session.output;
                    }
                },

                sessionIndicatorText(session) {
                    if (!session) return 'Run';
                    if (session.running) return `Running: ${session.projectName}`;
                    if (session.status === 'completed') return `Passed: ${session.projectName}`;
                    if (session.status === 'cancelled') return `Cancelled: ${session.projectName}`;
                    if (session.status === 'failed') return `Failed: ${session.projectName}`;
                    return `Last Run: ${session.projectName}`;
                },

                sessionIndicatorIcon(session) {
                    if (!session) return 'fas fa-terminal';
                    if (session.running) return 'fas fa-spinner fa-spin';
                    if (session.status === 'completed') return 'fas fa-check-circle';
                    if (session.status === 'cancelled') return 'fas fa-ban';
                    if (session.status === 'failed') return 'fas fa-times-circle';
                    return 'fas fa-terminal';
                },

                sessionIndicatorClass(session) {
                    return {
                        running: !!session?.running,
                        completed: !session?.running && session?.status === 'completed',
                        cancelled: !session?.running && session?.status === 'cancelled',
                        failed: !session?.running && session?.status === 'failed'
                    };
                },

                async openRunModal(project) {
                    this.runProject = project;
                    this.activeRunSessionId = null;
                    this.runForm = {
                        testName: `${project.name} - ${new Date().toLocaleDateString()}`,
                        environment: '',
                        runAll: true,
                        selectedTests: []
                    };
                    this.availableTests = [];
                    this.running = false;
                    this.runComplete = false;
                    this.runOutput = '';
                    this.runStatus = '';
                    this.runJobId = null;
                    this.autoScroll = true;
                    this.showRunModal = true;

                    // Load available tests
                    this.loadingTests = true;
                    try {
                        const response = await fetch(`../../api/playwright.php?action=tests&id=${project.id}`);
                        const data = await response.json();
                        if (data.success) {
                            this.availableTests = data.tests;
                        }
                    } catch (error) {
                        console.error('Failed to load tests:', error);
                    } finally {
                        this.loadingTests = false;
                    }
                },

                closeRunModal() {
                    this.showRunModal = false;
                },

                reopenRunModal(sessionId = null) {
                    let session = sessionId ? this.getRunSession(sessionId) : null;
                    if (!session) {
                        session = this.activeRunSessions[0] || this.latestRunSession;
                    }
                    if (!session) return;

                    this.syncModalFromSession(session);
                    this.showRunModal = true;
                    this.$nextTick(() => {
                        if (this.autoScroll || this.running) {
                            this.scrollToBottom();
                        }
                    });
                },

                stopRunPolling(sessionId = null) {
                    if (!sessionId) {
                        this.runSessions.forEach(session => {
                            if (session.polling) {
                                clearInterval(session.polling);
                                session.polling = null;
                            }
                        });
                        this.runPolling = null;
                        return;
                    }
                    const session = this.getRunSession(sessionId);
                    if (session && session.polling) {
                        clearInterval(session.polling);
                        session.polling = null;
                    }
                },

                async startTestRun() {
                    if (!this.runProject) return;

                    const selectedTests = this.normalizeSelectedTests(this.runForm.selectedTests);
                    const runAll = !!this.runForm.runAll;
                    const conflict = this.findConflictingRun(this.runProject.id, runAll, selectedTests);
                    if (conflict) {
                        window.Toast?.error('A run for one or more of these tests is already active.');
                        this.reopenRunModal(conflict.sessionId);
                        return;
                    }

                    const session = this.createRunSession({
                        projectId: this.runProject.id,
                        projectName: this.runProject.name,
                        testName: this.runForm.testName,
                        environment: this.runForm.environment,
                        runAll: runAll,
                        selectedTests: selectedTests
                    });
                    this.runSessions.unshift(session);
                    this.syncModalFromSession(session);
                    this.showRunModal = true;

                    try {
                        const response = await fetch(`../../api/playwright.php?action=run&id=${this.runProject.id}`, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                testFiles: runAll ? [] : selectedTests,
                                testName: this.runForm.testName,
                                environment: this.runForm.environment
                            })
                        });

                        const data = await response.json();

                        if (data.success) {
                            session.jobId = data.jobId;
                            session.updatedAt = Date.now();
                            this.syncModalFromSession(session);
                            this.startSessionPolling(session.sessionId);
                        } else {
                            session.running = false;
                            session.runComplete = true;
                            session.status = 'failed';
                            session.updatedAt = Date.now();
                            this.appendSessionOutput(session, `\nError: ${data.error}`);
                            this.syncModalFromSession(session);
                            window.Toast?.error(data.error || 'Failed to start test');
                        }
                    } catch (error) {
                        session.running = false;
                        session.runComplete = true;
                        session.status = 'failed';
                        session.updatedAt = Date.now();
                        this.appendSessionOutput(session, `\nError: ${error.message}`);
                        this.syncModalFromSession(session);
                        console.error('Run error:', error);
                        window.Toast?.error('Failed to start test');
                    }
                },

                startSessionPolling(sessionId) {
                    const session = this.getRunSession(sessionId);
                    if (!session || !session.jobId) return;

                    this.stopRunPolling(sessionId);
                    session.autoScroll = true;
                    session.polling = setInterval(async () => {
                        const current = this.getRunSession(sessionId);
                        if (!current || !current.jobId) {
                            this.stopRunPolling(sessionId);
                            return;
                        }
                        try {
                            const response = await fetch(`../../api/playwright.php?action=job-status&jobId=${current.jobId}`);
                            const data = await response.json();

                            if (data.success) {
                                current.output = data.output || '';
                                current.status = data.status || current.status;
                                current.running = !data.isComplete;
                                current.runComplete = !!data.isComplete;
                                current.updatedAt = Date.now();

                                if (this.activeRunSessionId === sessionId) {
                                    this.syncModalFromSession(current);
                                    if (this.autoScroll) {
                                        this.$nextTick(() => this.scrollToBottom());
                                    }
                                }

                                if (data.isComplete) {
                                    current.autoScroll = false;
                                    this.stopRunPolling(sessionId);
                                    if (this.activeRunSessionId === sessionId) {
                                        this.syncModalFromSession(current);
                                        this.autoScroll = false;
                                    }
                                }
                            }
                        } catch (error) {
                            console.error('Polling error:', error);
                        }
                    }, 2000);
                },

                async cancelTestRun() {
                    const session = this.getRunSession(this.activeRunSessionId);
                    if (!session || !session.jobId) {
                        return;
                    }

                    try {
                        const response = await fetch(`../../api/playwright.php?action=cancel-job&jobId=${encodeURIComponent(session.jobId)}`, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' }
                        });
                        const data = await response.json();

                        if (data.success) {
                            this.appendSessionOutput(session, '\n\nRun cancellation requested.\n');
                            window.Toast?.success('Run cancellation requested');
                        } else {
                            this.appendSessionOutput(session, `\n\nCancel failed: ${data.error || 'Unknown error'}\n`);
                            window.Toast?.error(data.error || 'Failed to cancel run');
                        }
                    } catch (error) {
                        this.appendSessionOutput(session, `\n\nCancel failed: ${error.message}\n`);
                        window.Toast?.error('Failed to cancel run');
                    }
                },

                scrollToBottom() {
                    const container = this.$refs.outputContainer;
                    if (container) {
                        container.scrollTop = container.scrollHeight;
                        this.autoScroll = true;
                        const session = this.getRunSession(this.activeRunSessionId);
                        if (session) {
                            session.autoScroll = true;
                        }
                    }
                },

                handleOutputScroll() {
                    const container = this.$refs.outputContainer;
                    if (!container) return;
                    
                    // Check if user scrolled away from bottom
                    const isAtBottom = container.scrollHeight - container.scrollTop <= container.clientHeight + 50;
                    
                    // Update auto-scroll while actively running this session.
                    if (this.running) {
                        this.autoScroll = isAtBottom;
                        const session = this.getRunSession(this.activeRunSessionId);
                        if (session) {
                            session.autoScroll = isAtBottom;
                        }
                    }
                },

                async copyOutput() {
                    try {
                        await navigator.clipboard.writeText(this.runOutput);
                        window.Toast?.success('Output copied to clipboard');
                    } catch (error) {
                        // Fallback for older browsers
                        const textarea = document.createElement('textarea');
                        textarea.value = this.runOutput;
                        document.body.appendChild(textarea);
                        textarea.select();
                        document.execCommand('copy');
                        document.body.removeChild(textarea);
                        window.Toast?.success('Output copied to clipboard');
                    }
                }
            },

            mounted() {
                this.loadProjects();
                this.loadDefaultWrapperConfig();
            },

            beforeUnmount() {
                this.stopRunPolling();
            }
        });

        app.mount('#projects-app');
    </script>
</body>
</html>
