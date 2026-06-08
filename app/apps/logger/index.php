<?php
/**
 * Logger App - Workspace
 */

require_once __DIR__ . '/../../includes/AppContext.php';
require_once __DIR__ . '/../../includes/LoggerManager.php';
require_once __DIR__ . '/../../includes/LoggerTargetResolver.php';

$app = new AppContext('logger');
$user = $app->requireAuth();
$layout = $app->getLayout();
$isAdmin = $app->hasRole('admin');
$manager = new LoggerManager();
$resolver = $isAdmin ? new LoggerTargetResolver() : null;
$entries = $manager->listEntries();
$groups = $manager->listGroups();
$filters = $isAdmin ? $manager->listAllFilters() : [];
$logCapableTargets = $resolver ? $resolver->listLogCapableTargets() : [];
$environmentContexts = $resolver ? $resolver->listEnvironmentContexts() : [];

function logger_json($value): string {
    return json_encode($value, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?: 'null';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $layout->renderHead('Logger'); ?>
    <style>
        .logger-shell {
            display: grid;
            gap: 20px;
        }

        .logger-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            flex-wrap: wrap;
        }

        .toolbar-left,
        .toolbar-right {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .search-box {
            display: flex;
            align-items: center;
            gap: 8px;
            min-width: min(320px, 100%);
            padding: 10px 14px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--bg-secondary);
            color: var(--text-secondary);
        }

        .search-box input {
            width: 100%;
            border: 0;
            outline: 0;
            background: transparent;
            color: var(--text-primary);
            font: inherit;
            font-size: 14px;
        }

        .view-switch {
            display: inline-flex;
            gap: 4px;
            padding: 4px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--bg-secondary);
        }

        .view-switch button,
        .btn,
        .icon-button {
            font: inherit;
        }

        .view-switch button {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            min-height: 34px;
            padding: 7px 11px;
            border: 0;
            border-radius: 6px;
            background: transparent;
            color: var(--text-secondary);
            cursor: pointer;
            font-size: 13px;
            font-weight: 700;
        }

        .view-switch button.active {
            background: var(--accent-primary);
            color: white;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 40px;
            padding: 10px 14px;
            border: 1px solid transparent;
            border-radius: 8px;
            color: var(--text-primary);
            cursor: pointer;
            font-size: 14px;
            font-weight: 700;
            text-decoration: none;
            transition: background 0.2s, border-color 0.2s, color 0.2s;
        }

        .btn:disabled {
            cursor: not-allowed;
            opacity: 0.55;
        }

        .btn-primary {
            background: var(--accent-primary);
            color: white;
        }

        .btn-primary:hover:not(:disabled) {
            background: var(--accent-hover);
        }

        .btn-secondary {
            border-color: var(--border-color);
            background: var(--bg-tertiary);
        }

        .btn-secondary:hover:not(:disabled) {
            border-color: var(--accent-primary);
            color: var(--accent-primary);
        }

        .btn-danger {
            background: rgba(239, 68, 68, 0.12);
            border-color: rgba(239, 68, 68, 0.35);
            color: #ef4444;
        }

        .btn-sm {
            min-height: 34px;
            padding: 7px 10px;
            font-size: 13px;
        }

        .summary-strip {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .summary-pill,
        .logger-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 9px;
            border: 1px solid var(--border-color);
            border-radius: 999px;
            background: var(--bg-secondary);
            color: var(--text-secondary);
            font-size: 12px;
            line-height: 1;
            white-space: nowrap;
        }

        .summary-pill strong {
            color: var(--text-primary);
        }

        .logger-pill.ok {
            border-color: rgba(34, 197, 94, 0.35);
            color: #22c55e;
        }

        .logger-pill.warn {
            border-color: rgba(245, 158, 11, 0.35);
            color: #f59e0b;
        }

        .workspace-section {
            display: grid;
            gap: 14px;
        }

        .section-heading {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .section-heading h2 {
            display: flex;
            align-items: center;
            gap: 9px;
            margin: 0;
            font-size: 18px;
        }

        .logger-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 16px;
        }

        .logger-card {
            display: flex;
            flex-direction: column;
            min-height: 164px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--bg-secondary);
            overflow: hidden;
            transition: border-color 0.2s, background 0.2s, transform 0.2s;
        }

        .logger-card:hover {
            border-color: var(--accent-primary);
            background: var(--bg-tertiary);
            transform: translateY(-1px);
        }

        .logger-card-link {
            display: grid;
            gap: 22px;
            flex: 1;
            padding: 18px;
            color: var(--text-primary);
            text-decoration: none;
        }

        .logger-card-title {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
        }

        .logger-card-title h3 {
            min-width: 0;
            margin: 0;
            font-size: 16px;
            line-height: 1.35;
            overflow-wrap: anywhere;
        }

        .logger-card-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            border-radius: 8px;
            background: rgba(20, 184, 166, 0.12);
            color: #14b8a6;
            flex: 0 0 auto;
        }

        .logger-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 7px;
        }

        .logger-card-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 11px 14px;
            border-top: 1px solid var(--border-color);
            background: var(--bg-tertiary);
        }

        .logger-card-actions .btn {
            flex: 1;
        }

        .icon-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 34px;
            height: 34px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--bg-secondary);
            color: var(--text-secondary);
            cursor: pointer;
            transition: border-color 0.2s, color 0.2s, background 0.2s;
        }

        .icon-button:hover {
            border-color: var(--accent-primary);
            color: var(--accent-primary);
        }

        .icon-button.danger:hover {
            border-color: rgba(239, 68, 68, 0.45);
            color: #ef4444;
        }

        .empty-state {
            display: grid;
            place-items: center;
            min-height: 210px;
            padding: 28px;
            border: 1px dashed var(--border-color);
            border-radius: 8px;
            background: var(--bg-secondary);
            color: var(--text-secondary);
            text-align: center;
        }

        .empty-state i {
            display: block;
            margin-bottom: 10px;
            color: #14b8a6;
            font-size: 28px;
        }

        .filter-list {
            display: grid;
            gap: 10px;
        }

        .filter-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 12px;
            align-items: center;
            padding: 14px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--bg-secondary);
        }

        .filter-row h3 {
            margin: 0 0 8px;
            font-size: 15px;
        }

        .filter-pattern,
        .source-path,
        .browser-path,
        .preview-path {
            color: var(--text-secondary);
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 12px;
            overflow-wrap: anywhere;
        }

        .modal-backdrop {
            position: fixed;
            inset: 0;
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 22px;
            background: rgba(0, 0, 0, 0.58);
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.2s, visibility 0.2s;
        }

        .modal-backdrop.active {
            opacity: 1;
            visibility: visible;
        }

        .modal {
            display: flex;
            flex-direction: column;
            width: min(760px, 100%);
            max-height: min(92vh, 980px);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            background: var(--bg-secondary);
            box-shadow: 0 24px 80px rgba(0, 0, 0, 0.36);
            overflow: hidden;
            transform: translateY(14px);
            transition: transform 0.2s;
        }

        .modal-backdrop.active .modal {
            transform: translateY(0);
        }

        .entry-modal {
            width: min(1500px, 100%);
        }

        .modal-header,
        .modal-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 16px 18px;
            border-bottom: 1px solid var(--border-color);
            background: var(--bg-tertiary);
        }

        .modal-footer {
            justify-content: flex-end;
            border-top: 1px solid var(--border-color);
            border-bottom: 0;
        }

        .modal-title {
            min-width: 0;
        }

        .modal-title h2 {
            margin: 0;
            font-size: 18px;
        }

        .modal-title p {
            margin: 4px 0 0;
            color: var(--text-secondary);
            font-size: 13px;
        }

        .modal-body {
            flex: 1;
            overflow: auto;
            padding: 18px;
        }

        .entry-builder {
            display: grid;
            grid-template-columns: minmax(270px, 0.8fr) minmax(360px, 1.08fr) minmax(330px, 0.92fr);
            gap: 14px;
            min-height: 620px;
        }

        .builder-column,
        .form-panel {
            display: flex;
            flex-direction: column;
            min-width: 0;
            min-height: 0;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--bg-primary);
            overflow: hidden;
        }

        .builder-column-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 12px;
            border-bottom: 1px solid var(--border-color);
            background: var(--bg-tertiary);
        }

        .builder-column-header h3 {
            margin: 0;
            font-size: 14px;
        }

        .builder-content {
            display: grid;
            gap: 12px;
            padding: 12px;
            overflow: auto;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        .form-field {
            display: grid;
            gap: 6px;
        }

        .form-field.full {
            grid-column: 1 / -1;
        }

        .form-label,
        .toggle-field {
            color: var(--text-secondary);
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .form-input,
        .form-select,
        .form-textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--bg-secondary);
            color: var(--text-primary);
            font: inherit;
            font-size: 14px;
        }

        .form-input:focus,
        .form-select:focus,
        .form-textarea:focus {
            outline: none;
            border-color: var(--accent-primary);
        }

        .form-textarea {
            min-height: 94px;
            resize: vertical;
        }

        .toggle-field {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            min-height: 40px;
        }

        .mode-switch {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px;
        }

        .mode-option,
        .target-option,
        .entry-option {
            display: flex;
            align-items: center;
            gap: 10px;
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--bg-secondary);
            color: var(--text-primary);
            cursor: pointer;
            text-align: left;
        }

        .mode-option.active,
        .target-option.active,
        .entry-option.active {
            border-color: var(--accent-primary);
            background: var(--accent-glow);
            color: var(--accent-primary);
        }

        .target-stack,
        .source-stack,
        .entry-picker {
            display: grid;
            gap: 8px;
        }

        .target-option {
            align-items: flex-start;
        }

        .target-main,
        .source-main {
            display: grid;
            gap: 5px;
            min-width: 0;
            flex: 1;
        }

        .target-name,
        .source-name {
            color: var(--text-primary);
            font-weight: 800;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .target-detail {
            color: var(--text-secondary);
            font-size: 12px;
        }

        .browser-controls {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 8px;
            padding: 12px;
            border-bottom: 1px solid var(--border-color);
            background: var(--bg-tertiary);
        }

        .browser-status {
            padding: 0 12px 10px;
            border-bottom: 1px solid var(--border-color);
            background: var(--bg-tertiary);
            color: var(--text-secondary);
            font-size: 12px;
        }

        .browser-list {
            display: grid;
            gap: 6px;
            padding: 10px;
            overflow: auto;
        }

        .browser-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 10px;
            align-items: center;
            padding: 10px 11px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--bg-secondary);
            color: var(--text-primary);
            cursor: pointer;
        }

        .browser-row:hover,
        .browser-row.active {
            border-color: var(--accent-primary);
        }

        .browser-row-main {
            display: flex;
            align-items: center;
            gap: 8px;
            min-width: 0;
        }

        .browser-row-main span {
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .browser-meta {
            color: var(--text-secondary);
            font-size: 12px;
            white-space: nowrap;
        }

        .preview-content {
            min-height: 240px;
            max-height: 380px;
            margin: 0;
            padding: 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: #0b1020;
            color: #d1d5db;
            overflow: auto;
            white-space: pre-wrap;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 12px;
            line-height: 1.5;
        }

        .source-card {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 10px;
            align-items: center;
            padding: 10px 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--bg-secondary);
        }

        .message-line {
            min-height: 18px;
            color: var(--text-secondary);
            font-size: 13px;
        }

        .message-line.error {
            color: #ef4444;
        }

        .message-line.success {
            color: #22c55e;
        }

        .checkbox-card {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--bg-primary);
            color: var(--text-primary);
            cursor: pointer;
        }

        .checkbox-card input {
            width: 16px;
            height: 16px;
            accent-color: var(--accent-primary);
        }

        [v-cloak] {
            display: none;
        }

        @media (max-width: 1180px) {
            .entry-builder {
                grid-template-columns: 1fr;
                min-height: 0;
            }

            .builder-column {
                min-height: 360px;
            }
        }

        @media (max-width: 760px) {
            .logger-toolbar,
            .toolbar-left,
            .toolbar-right,
            .modal-header,
            .modal-footer {
                align-items: stretch;
                flex-direction: column;
            }

            .search-box,
            .btn,
            .view-switch {
                width: 100%;
            }

            .view-switch button {
                flex: 1;
                justify-content: center;
            }

            .form-grid,
            .mode-switch {
                grid-template-columns: 1fr;
            }

            .modal-backdrop {
                padding: 10px;
            }

            .modal {
                max-height: 96vh;
            }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <?php $layout->renderSidebar(); ?>

        <main class="main-content">
            <div class="content-area">
                <?php $app->renderAppHeader(); ?>

                <div id="loggerWorkspace" class="logger-shell" v-cloak>
                    <div class="logger-toolbar">
                        <div class="toolbar-left">
                            <div class="search-box">
                                <i class="fas fa-search"></i>
                                <input type="text" v-model="searchQuery" placeholder="Search logs...">
                            </div>
                            <div class="view-switch">
                                <button type="button" :class="{ active: activeView === 'entries' }" @click="activeView = 'entries'">
                                    <i class="fas fa-file-lines"></i>
                                    Entries
                                </button>
                                <button type="button" :class="{ active: activeView === 'groups' }" @click="activeView = 'groups'">
                                    <i class="fas fa-object-group"></i>
                                    Groups
                                </button>
                                <button v-if="isAdmin" type="button" :class="{ active: activeView === 'filters' }" @click="activeView = 'filters'">
                                    <i class="fas fa-filter"></i>
                                    Filters
                                </button>
                            </div>
                        </div>
                        <div class="toolbar-right" v-if="isAdmin">
                            <button class="btn btn-primary" type="button" @click="openEntryModal()">
                                <i class="fas fa-file-circle-plus"></i>
                                Create Entry
                            </button>
                            <button class="btn btn-secondary" type="button" @click="openGroupModal()">
                                <i class="fas fa-object-group"></i>
                                Create Group
                            </button>
                            <button class="btn btn-secondary" type="button" @click="openFilterModal()">
                                <i class="fas fa-filter-circle-plus"></i>
                                Create Filter
                            </button>
                        </div>
                    </div>

                    <div class="summary-strip">
                        <span class="summary-pill"><strong>{{ computedStats.entries }}</strong> entries</span>
                        <span class="summary-pill"><strong>{{ computedStats.sources }}</strong> sources</span>
                        <span class="summary-pill"><strong>{{ computedStats.groups }}</strong> groups</span>
                        <span class="summary-pill" v-if="isAdmin"><strong>{{ computedStats.filters }}</strong> filters</span>
                    </div>

                    <section class="workspace-section" v-if="activeView === 'entries'">
                        <div class="section-heading">
                            <h2><i class="fas fa-file-lines"></i> Entries</h2>
                        </div>
                        <div class="empty-state" v-if="filteredEntries.length === 0">
                            <div>
                                <i class="fas" :class="searchQuery ? 'fa-search' : 'fa-file-circle-plus'"></i>
                                <div>{{ searchQuery ? 'No matching entries' : 'No logger entries' }}</div>
                            </div>
                        </div>
                        <div class="logger-grid" v-else>
                            <article class="logger-card" v-for="entry in filteredEntries" :key="entry.id">
                                <a class="logger-card-link" :href="entryHref(entry)">
                                    <div class="logger-card-title">
                                        <h3>{{ entry.name }}</h3>
                                        <span class="logger-card-icon"><i class="fas fa-file-lines"></i></span>
                                    </div>
                                    <div class="logger-meta">
                                        <span class="logger-pill"><i class="fas fa-layer-group"></i>{{ Number(entry.source_count || 0) }} sources</span>
                                        <span class="logger-pill"><i class="fas fa-filter"></i>{{ Number(entry.filter_count || 0) }} filters</span>
                                        <span class="logger-pill"><i class="fas fa-route"></i>{{ entry.mode }}</span>
                                        <span class="logger-pill warn" v-if="!entry.enabled"><i class="fas fa-pause"></i>disabled</span>
                                    </div>
                                </a>
                                <div class="logger-card-actions" v-if="isAdmin">
                                    <a class="btn btn-primary btn-sm" :href="entryHref(entry)">
                                        <i class="fas fa-terminal"></i>
                                        Open
                                    </a>
                                    <button class="icon-button" type="button" @click="openEntryModal(entry)" title="Edit">
                                        <i class="fas fa-pen"></i>
                                    </button>
                                    <button class="icon-button" type="button" @click="openFilterModal('entry', entry)" title="Add filter">
                                        <i class="fas fa-filter-circle-plus"></i>
                                    </button>
                                    <button class="icon-button danger" type="button" @click="deleteEntry(entry)" title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </article>
                        </div>
                    </section>

                    <section class="workspace-section" v-if="activeView === 'groups'">
                        <div class="section-heading">
                            <h2><i class="fas fa-object-group"></i> Groups</h2>
                        </div>
                        <div class="empty-state" v-if="filteredGroups.length === 0">
                            <div>
                                <i class="fas" :class="searchQuery ? 'fa-search' : 'fa-folder-plus'"></i>
                                <div>{{ searchQuery ? 'No matching groups' : 'No logger groups' }}</div>
                            </div>
                        </div>
                        <div class="logger-grid" v-else>
                            <article class="logger-card" v-for="group in filteredGroups" :key="group.id">
                                <a class="logger-card-link" :href="groupHref(group)">
                                    <div class="logger-card-title">
                                        <h3>{{ group.name }}</h3>
                                        <span class="logger-card-icon"><i class="fas fa-object-group"></i></span>
                                    </div>
                                    <div class="logger-meta">
                                        <span class="logger-pill"><i class="fas fa-list"></i>{{ Number(group.entry_count || 0) }} entries</span>
                                        <span class="logger-pill"><i class="fas fa-layer-group"></i>{{ Number(group.source_count || 0) }} sources</span>
                                        <span class="logger-pill"><i class="fas fa-filter"></i>{{ Number(group.filter_count || 0) }} filters</span>
                                    </div>
                                </a>
                                <div class="logger-card-actions" v-if="isAdmin">
                                    <a class="btn btn-primary btn-sm" :href="groupHref(group)">
                                        <i class="fas fa-terminal"></i>
                                        Open
                                    </a>
                                    <button class="icon-button" type="button" @click="openGroupModal(group)" title="Edit">
                                        <i class="fas fa-pen"></i>
                                    </button>
                                    <button class="icon-button" type="button" @click="openFilterModal('group', group)" title="Add filter">
                                        <i class="fas fa-filter-circle-plus"></i>
                                    </button>
                                    <button class="icon-button danger" type="button" @click="deleteGroup(group)" title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </article>
                        </div>
                    </section>

                    <section class="workspace-section" v-if="isAdmin && activeView === 'filters'">
                        <div class="section-heading">
                            <h2><i class="fas fa-filter"></i> Filters</h2>
                        </div>
                        <div class="empty-state" v-if="filteredFilters.length === 0">
                            <div>
                                <i class="fas" :class="searchQuery ? 'fa-search' : 'fa-filter-circle-plus'"></i>
                                <div>{{ searchQuery ? 'No matching filters' : 'No saved filters' }}</div>
                            </div>
                        </div>
                        <div class="filter-list" v-else>
                            <article class="filter-row" v-for="filter in filteredFilters" :key="filter.id">
                                <div>
                                    <h3>{{ filter.name }}</h3>
                                    <div class="logger-meta">
                                        <span class="logger-pill"><i class="fas fa-sitemap"></i>{{ filter.owner_type }}: {{ filter.owner_name || filter.owner_id }}</span>
                                        <span class="logger-pill"><i class="fas fa-code-branch"></i>{{ filter.mode }}</span>
                                        <span class="logger-pill" v-if="filter.case_sensitive"><i class="fas fa-font"></i>case</span>
                                        <span class="logger-pill warn" v-if="!filter.enabled"><i class="fas fa-pause"></i>disabled</span>
                                    </div>
                                    <div class="filter-pattern">{{ filter.pattern }}</div>
                                </div>
                                <div class="logger-card-actions">
                                    <button class="icon-button" type="button" @click="openFilterModal(null, null, filter)" title="Edit">
                                        <i class="fas fa-pen"></i>
                                    </button>
                                    <button class="icon-button danger" type="button" @click="deleteFilter(filter)" title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </article>
                        </div>
                    </section>

                    <div class="modal-backdrop" :class="{ active: showEntryModal }" @click.self="closeEntryModal">
                        <div class="modal entry-modal">
                            <div class="modal-header">
                                <div class="modal-title">
                                    <h2>{{ entryForm.id ? 'Edit Entry' : 'Create Entry' }}</h2>
                                    <p>{{ entryForm.mode === 'environment' ? 'Environment-backed source coverage' : 'Direct target source selection' }}</p>
                                </div>
                                <button class="icon-button" type="button" @click="closeEntryModal" title="Close">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                            <div class="modal-body">
                                <div class="entry-builder">
                                    <aside class="builder-column">
                                        <div class="builder-column-header">
                                            <h3>Scope</h3>
                                            <span class="logger-pill" :class="canSaveEntry() ? 'ok' : 'warn'">
                                                <i class="fas" :class="canSaveEntry() ? 'fa-check' : 'fa-triangle-exclamation'"></i>
                                                {{ entryForm.sources.length }} sources
                                            </span>
                                        </div>
                                        <div class="builder-content">
                                            <div class="form-field">
                                                <label class="form-label" for="entryNameInput">Entry Name</label>
                                                <input id="entryNameInput" class="form-input" type="text" v-model.trim="entryForm.name" placeholder="Production PHP errors">
                                            </div>
                                            <div class="mode-switch">
                                                <button class="mode-option" :class="{ active: entryForm.mode === 'direct' }" type="button" @click="setEntryMode('direct')">
                                                    <i class="fas fa-server"></i>
                                                    <span>Direct Target</span>
                                                </button>
                                                <button class="mode-option" :class="{ active: entryForm.mode === 'environment' }" type="button" @click="setEntryMode('environment')">
                                                    <i class="fas fa-layer-group"></i>
                                                    <span>Environment</span>
                                                </button>
                                            </div>
                                            <label class="toggle-field">
                                                <input type="checkbox" v-model="entryForm.enabled">
                                                Enabled
                                            </label>

                                            <div class="form-field" v-if="entryForm.mode === 'environment'">
                                                <label class="form-label" for="entryEnvironmentInput">Environment</label>
                                                <select id="entryEnvironmentInput" class="form-select" v-model="entryForm.environment_id" @change="selectFirstEnvironmentTarget(true)">
                                                    <option v-for="environment in environments" :key="environment.id" :value="environment.id">
                                                        {{ environment.name }}
                                                    </option>
                                                </select>
                                            </div>

                                            <div class="target-stack" v-if="entryForm.mode === 'direct'">
                                                <button
                                                    v-for="target in targets"
                                                    :key="target.id"
                                                    class="target-option"
                                                    :class="{ active: selectedTargetId === target.id }"
                                                    type="button"
                                                    @click="selectDirectTarget(target.id)"
                                                >
                                                    <i class="fas fa-server"></i>
                                                    <span class="target-main">
                                                        <span class="target-name">{{ target.name }}</span>
                                                        <span class="target-detail">{{ target.type }} · {{ target.transport }}</span>
                                                    </span>
                                                </button>
                                                <div class="empty-state" v-if="targets.length === 0">
                                                    <div>
                                                        <i class="fas fa-server"></i>
                                                        <div>No log-capable targets</div>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="target-stack" v-if="entryForm.mode === 'environment'">
                                                <button
                                                    v-for="target in currentEnvironmentTargets"
                                                    :key="target.environmentKey"
                                                    class="target-option"
                                                    :class="{ active: selectedEnvironmentKey === target.environmentKey }"
                                                    type="button"
                                                    @click="selectEnvironmentTarget(target.environmentKey)"
                                                >
                                                    <i class="fas fa-layer-group"></i>
                                                    <span class="target-main">
                                                        <span class="target-name">{{ target.environmentKey }} -> {{ target.name }}</span>
                                                        <span class="target-detail">{{ target.type }} · {{ target.transport }}</span>
                                                    </span>
                                                    <span class="logger-pill" :class="environmentKeyCovered(target.environmentKey) ? 'ok' : 'warn'">
                                                        <i class="fas" :class="environmentKeyCovered(target.environmentKey) ? 'fa-check' : 'fa-circle'"></i>
                                                        {{ environmentKeyCovered(target.environmentKey) ? 'covered' : 'needs file' }}
                                                    </span>
                                                </button>
                                                <div class="empty-state" v-if="currentEnvironmentTargets.length === 0">
                                                    <div>
                                                        <i class="fas fa-layer-group"></i>
                                                        <div>No log-capable environment targets</div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </aside>

                                    <section class="builder-column">
                                        <div class="builder-column-header">
                                            <h3>Files</h3>
                                            <span class="logger-pill">
                                                <i class="fas fa-location-dot"></i>
                                                {{ selectedBrowseLabel }}
                                            </span>
                                        </div>
                                        <div class="browser-controls">
                                            <input class="form-input" type="text" v-model="browserPath" @keydown.enter.prevent="loadBrowserPath()">
                                            <button class="btn btn-secondary btn-sm" type="button" @click="loadBrowserPath()" :disabled="browserLoading || !canBrowse">
                                                <i class="fas" :class="browserLoading ? 'fa-spinner fa-spin' : 'fa-folder-open'"></i>
                                                Browse
                                            </button>
                                        </div>
                                        <div class="browser-status">{{ browserStatus }}</div>
                                        <div class="browser-list">
                                            <button
                                                v-for="row in browserRows"
                                                :key="row.key"
                                                class="browser-row"
                                                :class="{ active: previewedFile && previewedFile.path === row.path }"
                                                type="button"
                                                @click="openBrowserRow(row)"
                                            >
                                                <span class="browser-row-main">
                                                    <i class="fas" :class="browserIcon(row)"></i>
                                                    <span>{{ row.name }}</span>
                                                </span>
                                                <span class="browser-meta">{{ row.meta }}</span>
                                            </button>
                                            <div class="empty-state" v-if="!browserLoading && browserRows.length === 0">
                                                <div>
                                                    <i class="fas fa-folder-open"></i>
                                                    <div>No directory loaded</div>
                                                </div>
                                            </div>
                                        </div>
                                    </section>

                                    <aside class="builder-column">
                                        <div class="builder-column-header">
                                            <h3>Preview</h3>
                                            <button class="btn btn-primary btn-sm" type="button" @click="usePreviewedFile" :disabled="!previewedFile || previewLoading || previewBlocked">
                                                <i class="fas fa-plus"></i>
                                                Use File
                                            </button>
                                        </div>
                                        <div class="builder-content">
                                            <div>
                                                <div class="target-name">{{ previewedFile?.name || 'No file selected' }}</div>
                                                <div class="preview-path">{{ previewedFile?.path || selectedBrowseLabel }}</div>
                                            </div>
                                            <div class="form-field" v-if="previewedFile">
                                                <label class="form-label" for="sourceNameInput">Source Name</label>
                                                <input id="sourceNameInput" class="form-input" type="text" v-model.trim="sourceDraftName" placeholder="php-error.log">
                                            </div>
                                            <pre class="preview-content">{{ previewContent }}</pre>

                                            <div class="source-stack">
                                                <div class="source-card" v-for="(source, index) in entryForm.sources" :key="sourceKey(source, index)">
                                                    <div class="source-main">
                                                        <span class="source-name">{{ source.source_name }}</span>
                                                        <span class="source-path">{{ source.path }}</span>
                                                        <span class="logger-meta">
                                                            <span class="logger-pill"><i class="fas fa-server"></i>{{ sourceTargetLabel(source) }}</span>
                                                            <span class="logger-pill" v-if="source.target_type"><i class="fas fa-plug"></i>{{ source.target_type }}</span>
                                                        </span>
                                                    </div>
                                                    <button class="icon-button danger" type="button" @click="removeEntrySource(index)" title="Remove source">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            </div>
                                            <div class="message-line" :class="entryMessageType">{{ entryMessage }}</div>
                                        </div>
                                    </aside>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button class="btn btn-secondary" type="button" @click="closeEntryModal">Cancel</button>
                                <button class="btn btn-secondary" type="button" @click="checkEntrySources" :disabled="entryForm.sources.length === 0 || savingEntry">
                                    <i class="fas fa-stethoscope"></i>
                                    Check Sources
                                </button>
                                <button class="btn btn-primary" type="button" @click="saveEntry" :disabled="!canSaveEntry() || savingEntry">
                                    <i class="fas" :class="savingEntry ? 'fa-spinner fa-spin' : 'fa-floppy-disk'"></i>
                                    {{ savingEntry ? 'Saving...' : 'Save Entry' }}
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="modal-backdrop" :class="{ active: showGroupModal }" @click.self="closeGroupModal">
                        <div class="modal">
                            <div class="modal-header">
                                <div class="modal-title">
                                    <h2>{{ groupForm.id ? 'Edit Group' : 'Create Group' }}</h2>
                                    <p>{{ groupForm.entry_ids.length }} entries selected</p>
                                </div>
                                <button class="icon-button" type="button" @click="closeGroupModal" title="Close">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                            <div class="modal-body">
                                <div class="form-grid">
                                    <div class="form-field full">
                                        <label class="form-label" for="groupNameInput">Group Name</label>
                                        <input id="groupNameInput" class="form-input" type="text" v-model.trim="groupForm.name" placeholder="Production triage">
                                    </div>
                                    <div class="form-field full">
                                        <label class="form-label" for="groupDescriptionInput">Description</label>
                                        <textarea id="groupDescriptionInput" class="form-textarea" v-model.trim="groupForm.description"></textarea>
                                    </div>
                                    <div class="form-field full">
                                        <label class="form-label" for="groupSearchInput">Entries</label>
                                        <input id="groupSearchInput" class="form-input" type="text" v-model="groupSearch" placeholder="Search entries...">
                                    </div>
                                </div>
                                <div class="entry-picker" style="margin-top: 12px;">
                                    <label class="checkbox-card" v-for="entry in filteredGroupPickerEntries" :key="entry.id">
                                        <input type="checkbox" :value="entry.id" v-model="groupForm.entry_ids">
                                        <span class="source-main">
                                            <span class="source-name">{{ entry.name }}</span>
                                            <span class="target-detail">{{ Number(entry.source_count || 0) }} sources · {{ entry.mode }}</span>
                                        </span>
                                    </label>
                                    <div class="empty-state" v-if="filteredGroupPickerEntries.length === 0">
                                        <div>
                                            <i class="fas fa-file-lines"></i>
                                            <div>No entries available</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="message-line" :class="groupMessageType" style="margin-top: 12px;">{{ groupMessage }}</div>
                            </div>
                            <div class="modal-footer">
                                <button class="btn btn-secondary" type="button" @click="closeGroupModal">Cancel</button>
                                <button class="btn btn-primary" type="button" @click="saveGroup" :disabled="!groupForm.name || savingGroup">
                                    <i class="fas" :class="savingGroup ? 'fa-spinner fa-spin' : 'fa-floppy-disk'"></i>
                                    {{ savingGroup ? 'Saving...' : 'Save Group' }}
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="modal-backdrop" :class="{ active: showFilterModal }" @click.self="closeFilterModal">
                        <div class="modal">
                            <div class="modal-header">
                                <div class="modal-title">
                                    <h2>{{ filterForm.id ? 'Edit Filter' : 'Create Filter' }}</h2>
                                    <p>{{ filterOwnerLabel }}</p>
                                </div>
                                <button class="icon-button" type="button" @click="closeFilterModal" title="Close">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                            <div class="modal-body">
                                <div class="form-grid">
                                    <div class="form-field">
                                        <label class="form-label" for="filterNameInput">Filter Name</label>
                                        <input id="filterNameInput" class="form-input" type="text" v-model.trim="filterForm.name" placeholder="PHP fatals">
                                    </div>
                                    <div class="form-field">
                                        <label class="form-label" for="filterModeInput">Mode</label>
                                        <select id="filterModeInput" class="form-select" v-model="filterForm.mode">
                                            <option value="contains_all">Contains all terms</option>
                                            <option value="contains_any">Contains any term</option>
                                        </select>
                                    </div>
                                    <div class="form-field">
                                        <label class="form-label" for="filterScopeInput">Scope</label>
                                        <select id="filterScopeInput" class="form-select" v-model="filterForm.owner_type" @change="selectFirstFilterOwner">
                                            <option value="entry">Entry</option>
                                            <option value="group">Group</option>
                                        </select>
                                    </div>
                                    <div class="form-field">
                                        <label class="form-label" for="filterOwnerInput">Owner</label>
                                        <select id="filterOwnerInput" class="form-select" v-model="filterForm.owner_id">
                                            <option v-for="owner in filterOwnerOptions" :key="owner.id" :value="owner.id">{{ owner.name }}</option>
                                        </select>
                                    </div>
                                    <div class="form-field full">
                                        <label class="form-label" for="filterPatternInput">Pattern</label>
                                        <input id="filterPatternInput" class="form-input" type="text" v-model.trim="filterForm.pattern" placeholder="500 + php:fatal">
                                    </div>
                                    <label class="toggle-field">
                                        <input type="checkbox" v-model="filterForm.case_sensitive">
                                        Case sensitive
                                    </label>
                                    <label class="toggle-field">
                                        <input type="checkbox" v-model="filterForm.enabled">
                                        Enabled
                                    </label>
                                </div>
                                <div class="message-line" :class="filterMessageType" style="margin-top: 12px;">{{ filterMessage }}</div>
                            </div>
                            <div class="modal-footer">
                                <button class="btn btn-secondary" type="button" @click="closeFilterModal">Cancel</button>
                                <button class="btn btn-primary" type="button" @click="saveFilter" :disabled="!canSaveFilter() || savingFilter">
                                    <i class="fas" :class="savingFilter ? 'fa-spinner fa-spin' : 'fa-floppy-disk'"></i>
                                    {{ savingFilter ? 'Saving...' : 'Save Filter' }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/vue@3/dist/vue.global.prod.js"></script>
    <script>
        const loggerInitial = <?= logger_json([
            'isAdmin' => $isAdmin,
            'entries' => $entries,
            'groups' => $groups,
            'filters' => $filters,
            'targets' => $logCapableTargets,
            'environments' => $environmentContexts,
        ]) ?>;

        Vue.createApp({
            data() {
                return {
                    isAdmin: !!loggerInitial.isAdmin,
                    entries: loggerInitial.entries || [],
                    groups: loggerInitial.groups || [],
                    filters: loggerInitial.filters || [],
                    targets: loggerInitial.targets || [],
                    environments: loggerInitial.environments || [],
                    activeView: 'entries',
                    searchQuery: '',

                    showEntryModal: false,
                    entryForm: this.blankEntryForm(),
                    selectedTargetId: '',
                    selectedEnvironmentKey: '',
                    browserPath: '/',
                    browserEntries: [],
                    browserParentPath: '/',
                    browserStatus: 'No directory loaded.',
                    browserLoading: false,
                    previewedFile: null,
                    previewLoading: false,
                    previewContent: 'No file selected.',
                    previewBlocked: false,
                    sourceDraftName: '',
                    entryMessage: '',
                    entryMessageType: '',
                    savingEntry: false,

                    showGroupModal: false,
                    groupForm: this.blankGroupForm(),
                    groupSearch: '',
                    groupMessage: '',
                    groupMessageType: '',
                    savingGroup: false,

                    showFilterModal: false,
                    filterForm: this.blankFilterForm(),
                    filterMessage: '',
                    filterMessageType: '',
                    savingFilter: false,
                };
            },

            computed: {
                computedStats() {
                    return {
                        entries: this.entries.length,
                        sources: this.entries.reduce((sum, entry) => sum + Number(entry.source_count || 0), 0),
                        groups: this.groups.length,
                        filters: this.filters.length,
                    };
                },

                filteredEntries() {
                    const q = this.searchQuery.trim().toLowerCase();
                    if (!q) return this.entries;
                    return this.entries.filter((entry) => {
                        return `${entry.name || ''} ${entry.mode || ''}`.toLowerCase().includes(q);
                    });
                },

                filteredGroups() {
                    const q = this.searchQuery.trim().toLowerCase();
                    if (!q) return this.groups;
                    return this.groups.filter((group) => `${group.name || ''}`.toLowerCase().includes(q));
                },

                filteredFilters() {
                    const q = this.searchQuery.trim().toLowerCase();
                    if (!q) return this.filters;
                    return this.filters.filter((filter) => {
                        return `${filter.name || ''} ${filter.pattern || ''} ${filter.owner_name || ''} ${filter.owner_type || ''}`.toLowerCase().includes(q);
                    });
                },

                currentEnvironment() {
                    return this.environments.find((environment) => environment.id === this.entryForm.environment_id) || null;
                },

                currentEnvironmentTargets() {
                    return this.currentEnvironment?.logCapableTargets || [];
                },

                selectedDirectTarget() {
                    return this.targets.find((target) => target.id === this.selectedTargetId) || null;
                },

                selectedEnvironmentTarget() {
                    return this.currentEnvironmentTargets.find((target) => target.environmentKey === this.selectedEnvironmentKey) || null;
                },

                selectedBrowseLabel() {
                    if (this.entryForm.mode === 'environment') {
                        const target = this.selectedEnvironmentTarget;
                        return target ? `${target.environmentKey} -> ${target.name}` : 'No environment target';
                    }

                    return this.selectedDirectTarget?.name || 'No target';
                },

                canBrowse() {
                    if (this.entryForm.mode === 'environment') {
                        return !!this.entryForm.environment_id && !!this.selectedEnvironmentKey;
                    }
                    return !!this.selectedTargetId;
                },

                browserRows() {
                    const rows = [];
                    if (this.browserParentPath && this.browserPath !== '/') {
                        rows.push({
                            key: 'parent',
                            name: 'Parent directory',
                            path: this.browserParentPath,
                            type: 'dir',
                            readable: true,
                            meta: this.browserParentPath || '/',
                            parent: true,
                        });
                    }

                    for (const entry of this.browserEntries || []) {
                        rows.push({
                            key: `${entry.type}:${entry.path}`,
                            name: entry.name || entry.path,
                            path: entry.path,
                            type: entry.type || 'file',
                            readable: !!entry.readable,
                            size: entry.size,
                            meta: this.browserEntryMeta(entry),
                        });
                    }
                    return rows;
                },

                filteredGroupPickerEntries() {
                    const q = this.groupSearch.trim().toLowerCase();
                    if (!q) return this.entries;
                    return this.entries.filter((entry) => `${entry.name || ''} ${entry.mode || ''}`.toLowerCase().includes(q));
                },

                filterOwnerOptions() {
                    return this.filterForm.owner_type === 'group' ? this.groups : this.entries;
                },

                filterOwnerLabel() {
                    const owner = this.filterOwnerOptions.find((item) => item.id === this.filterForm.owner_id);
                    return owner ? `${this.filterForm.owner_type}: ${owner.name}` : 'No owner selected';
                },
            },

            methods: {
                blankEntryForm() {
                    return {
                        id: '',
                        name: '',
                        mode: 'direct',
                        environment_id: '',
                        enabled: true,
                        sources: [],
                    };
                },

                blankGroupForm() {
                    return {
                        id: '',
                        name: '',
                        description: '',
                        entry_ids: [],
                    };
                },

                blankFilterForm() {
                    return {
                        id: '',
                        owner_type: 'entry',
                        owner_id: '',
                        name: '',
                        mode: 'contains_all',
                        pattern: '',
                        case_sensitive: false,
                        enabled: true,
                    };
                },

                toast(message, type = 'success') {
                    if (window.Toast && typeof window.Toast[type] === 'function') {
                        window.Toast[type](message);
                        return;
                    }
                    if (type === 'error') {
                        console.error(message);
                    }
                },

                entryHref(entry) {
                    return `entry.php?slug=${encodeURIComponent(entry.slug || entry.id)}`;
                },

                groupHref(group) {
                    return `group.php?slug=${encodeURIComponent(group.slug || group.id)}`;
                },

                formatSize(size) {
                    if (size === null || size === undefined || size === '') return '';
                    const numeric = Number(size);
                    if (!Number.isFinite(numeric)) return '';
                    if (numeric < 1024) return `${numeric} B`;
                    if (numeric < 1024 * 1024) return `${(numeric / 1024).toFixed(1)} KB`;
                    return `${(numeric / 1024 / 1024).toFixed(1)} MB`;
                },

                basename(path) {
                    const value = String(path || '').replace(/\/+$/, '');
                    if (!value || value === '/') return '';
                    return value.slice(value.lastIndexOf('/') + 1);
                },

                dirname(path) {
                    const value = String(path || '').replace(/\/+$/, '');
                    if (!value || value === '/') return '/';
                    const index = value.lastIndexOf('/');
                    return index <= 0 ? '/' : value.slice(0, index);
                },

                sourceKey(source, index) {
                    return `${source.target_id || source.environment_target_key || 'source'}:${source.path}:${index}`;
                },

                sourceTargetLabel(source) {
                    if (this.entryForm.mode === 'environment') {
                        return source.environment_target_key || 'environment';
                    }
                    const target = this.targets.find((item) => item.id === source.target_id);
                    return target?.name || source.target_id || 'target';
                },

                environmentKeyCovered(key) {
                    return this.entryForm.sources.some((source) => source.environment_target_key === key && source.path);
                },

                missingEnvironmentKeys() {
                    if (this.entryForm.mode !== 'environment') return [];
                    return this.currentEnvironmentTargets
                        .map((target) => target.environmentKey)
                        .filter((key) => key && !this.environmentKeyCovered(key));
                },

                canSaveEntry() {
                    if (!this.entryForm.name.trim() || this.entryForm.sources.length === 0) return false;
                    if (this.entryForm.mode === 'environment') {
                        return !!this.entryForm.environment_id && this.missingEnvironmentKeys().length === 0;
                    }
                    return true;
                },

                canSaveFilter() {
                    return !!this.filterForm.name.trim()
                        && !!this.filterForm.owner_type
                        && !!this.filterForm.owner_id
                        && !!this.filterForm.pattern.trim();
                },

                async reloadCollections() {
                    try {
                        const [entriesResponse, groupsResponse, filtersResponse] = await Promise.all([
                            fetch('/api/logger.php?action=entries'),
                            fetch('/api/logger.php?action=groups'),
                            this.isAdmin ? fetch('/api/logger.php?action=filters') : Promise.resolve(null),
                        ]);
                        const entriesData = await entriesResponse.json();
                        const groupsData = await groupsResponse.json();
                        const filtersData = filtersResponse ? await filtersResponse.json() : null;
                        if (entriesData.success) this.entries = entriesData.entries || [];
                        if (groupsData.success) this.groups = groupsData.groups || [];
                        if (filtersData?.success) this.filters = filtersData.filters || [];
                    } catch (error) {
                        this.toast(`Refresh failed: ${error.message}`, 'error');
                    }
                },

                openEntryModal(entry = null) {
                    this.entryMessage = '';
                    this.entryMessageType = '';
                    this.showEntryModal = true;
                    if (entry?.id) {
                        this.loadEntryForEdit(entry.id);
                        return;
                    }

                    this.entryForm = this.blankEntryForm();
                    this.entryForm.environment_id = this.environments[0]?.id || '';
                    this.selectedTargetId = this.targets[0]?.id || '';
                    this.selectFirstEnvironmentTarget(false);
                    this.resetBrowser();
                    this.$nextTick(() => {
                        if (this.canBrowse) this.loadBrowserPath('/');
                    });
                },

                closeEntryModal() {
                    this.showEntryModal = false;
                    this.resetBrowser();
                },

                async loadEntryForEdit(id) {
                    this.entryMessage = 'Loading entry...';
                    this.entryMessageType = '';
                    try {
                        const response = await fetch(`/api/logger.php?action=entry&id=${encodeURIComponent(id)}`);
                        const data = await response.json();
                        if (!data.success) {
                            this.entryMessage = data.error || 'Failed to load entry.';
                            this.entryMessageType = 'error';
                            return;
                        }

                        const entry = data.entry || {};
                        this.entryForm = {
                            id: entry.id || '',
                            name: entry.name || '',
                            mode: entry.mode || 'direct',
                            environment_id: entry.environment_id || this.environments[0]?.id || '',
                            enabled: !!entry.enabled,
                            sources: (entry.sources || []).map((source, index) => ({
                                source_name: source.source_name || source.name || '',
                                target_id: source.target_id || '',
                                environment_target_key: source.environment_target_key || '',
                                path: source.path || '',
                                target_type: source.target_type || '',
                                enabled: source.enabled !== false,
                                display_order: index,
                            })),
                        };

                        const firstSource = this.entryForm.sources[0] || null;
                        if (this.entryForm.mode === 'environment') {
                            this.selectedEnvironmentKey = firstSource?.environment_target_key || '';
                            if (!this.selectedEnvironmentKey) this.selectFirstEnvironmentTarget(false);
                        } else {
                            this.selectedTargetId = firstSource?.target_id || this.targets[0]?.id || '';
                        }
                        this.resetBrowser(this.dirname(firstSource?.path || '/'));
                        this.entryMessage = 'Entry loaded.';
                        this.entryMessageType = 'success';
                        this.$nextTick(() => {
                            if (this.canBrowse) this.loadBrowserPath(this.browserPath);
                        });
                    } catch (error) {
                        this.entryMessage = `Failed to load entry: ${error.message}`;
                        this.entryMessageType = 'error';
                    }
                },

                setEntryMode(mode) {
                    this.entryForm.mode = mode;
                    this.entryForm.sources = [];
                    if (mode === 'direct') {
                        this.selectedTargetId = this.selectedTargetId || this.targets[0]?.id || '';
                    } else {
                        this.entryForm.environment_id = this.entryForm.environment_id || this.environments[0]?.id || '';
                        this.selectFirstEnvironmentTarget(false);
                    }
                    this.resetBrowser();
                    this.$nextTick(() => {
                        if (this.canBrowse) this.loadBrowserPath('/');
                    });
                },

                selectDirectTarget(targetId) {
                    this.selectedTargetId = targetId;
                    this.resetBrowser();
                    this.loadBrowserPath('/');
                },

                selectFirstEnvironmentTarget(load = true) {
                    const targets = this.currentEnvironmentTargets;
                    this.selectedEnvironmentKey = targets[0]?.environmentKey || '';
                    this.resetBrowser();
                    if (load && this.canBrowse) {
                        this.loadBrowserPath('/');
                    }
                },

                selectEnvironmentTarget(key) {
                    this.selectedEnvironmentKey = key;
                    this.resetBrowser();
                    this.loadBrowserPath('/');
                },

                resetBrowser(path = '/') {
                    this.browserPath = path || '/';
                    this.browserEntries = [];
                    this.browserParentPath = '/';
                    this.browserStatus = 'No directory loaded.';
                    this.previewedFile = null;
                    this.previewLoading = false;
                    this.previewContent = 'No file selected.';
                    this.previewBlocked = false;
                    this.sourceDraftName = '';
                },

                browserEntryMeta(entry) {
                    const type = entry.type || 'file';
                    const readable = entry.readable ? '' : ' · not readable';
                    const size = entry.size !== null && entry.size !== undefined ? ` · ${this.formatSize(entry.size)}` : '';
                    return `${type}${readable}${size}`;
                },

                browserIcon(row) {
                    if (row.parent) return 'fa-arrow-up';
                    if (row.type === 'container') return 'fa-cube';
                    if (row.type === 'dir') return 'fa-folder';
                    if (row.type === 'symlink') return 'fa-link';
                    return 'fa-file-lines';
                },

                buildTargetUrl(action, path) {
                    const params = new URLSearchParams({ action, path: path || '/' });
                    if (this.entryForm.mode === 'environment') {
                        params.set('environmentId', this.entryForm.environment_id || '');
                        params.set('environmentTargetKey', this.selectedEnvironmentKey || '');
                    } else {
                        params.set('targetId', this.selectedTargetId || '');
                    }
                    return `/api/logger.php?${params.toString()}`;
                },

                async loadBrowserPath(path = null) {
                    if (!this.canBrowse) {
                        this.browserStatus = 'No log-capable target selected.';
                        return;
                    }

                    const requestedPath = path || this.browserPath || '/';
                    this.browserLoading = true;
                    this.browserStatus = `Loading ${requestedPath}...`;
                    this.browserEntries = [];
                    this.previewedFile = null;
                    this.previewContent = 'No file selected.';

                    try {
                        const response = await fetch(this.buildTargetUrl('browse', requestedPath));
                        const data = await response.json();
                        if (!data.success) {
                            this.browserStatus = data.error || 'Browse failed.';
                            return;
                        }

                        this.browserPath = data.path || requestedPath;
                        this.browserParentPath = data.parentPath || '/';
                        this.browserEntries = data.entries || [];
                        this.browserStatus = `${data.target?.name || this.selectedBrowseLabel}: ${this.browserEntries.length} items`;
                    } catch (error) {
                        this.browserStatus = `Browse failed: ${error.message}`;
                    } finally {
                        this.browserLoading = false;
                    }
                },

                openBrowserRow(row) {
                    if (row.type === 'dir' || row.type === 'container' || row.parent) {
                        this.loadBrowserPath(row.path || '/');
                        return;
                    }

                    if (!row.readable) {
                        this.previewedFile = row;
                        this.previewContent = 'This file is not readable.';
                        this.previewBlocked = true;
                        return;
                    }

                    this.previewFile(row);
                },

                async previewFile(row) {
                    this.previewedFile = row;
                    this.sourceDraftName = row.name || this.basename(row.path);
                    this.previewContent = 'Loading preview...';
                    this.previewLoading = true;
                    this.previewBlocked = true;

                    try {
                        const response = await fetch(`${this.buildTargetUrl('preview', row.path)}&limit=8192`);
                        const data = await response.json();
                        if (!data.success) {
                            this.previewContent = data.error || 'Preview failed.';
                            return;
                        }

                        this.previewBlocked = !data.previewable;
                        this.previewContent = data.previewable
                            ? (data.content || 'File is empty.')
                            : (data.message || 'This file cannot be previewed.');
                    } catch (error) {
                        this.previewContent = `Preview failed: ${error.message}`;
                    } finally {
                        this.previewLoading = false;
                    }
                },

                usePreviewedFile() {
                    if (!this.previewedFile || this.previewBlocked) return;
                    const name = this.sourceDraftName.trim() || this.previewedFile.name || this.basename(this.previewedFile.path) || 'log';
                    const source = {
                        source_name: name,
                        path: this.previewedFile.path || '',
                        target_type: this.entryForm.mode === 'environment'
                            ? (this.selectedEnvironmentTarget?.type || '')
                            : (this.selectedDirectTarget?.type || ''),
                        enabled: true,
                        display_order: this.entryForm.sources.length,
                    };

                    if (this.entryForm.mode === 'environment') {
                        source.environment_target_key = this.selectedEnvironmentKey || '';
                    } else {
                        source.target_id = this.selectedTargetId || '';
                    }

                    const duplicateIndex = this.entryForm.sources.findIndex((item) => {
                        return item.path === source.path
                            && (item.target_id || '') === (source.target_id || '')
                            && (item.environment_target_key || '') === (source.environment_target_key || '');
                    });

                    if (duplicateIndex >= 0) {
                        this.entryForm.sources.splice(duplicateIndex, 1, { ...this.entryForm.sources[duplicateIndex], source_name: source.source_name });
                        this.entryMessage = 'Source updated.';
                    } else {
                        this.entryForm.sources.push(source);
                        this.entryMessage = 'Source added.';
                    }
                    this.entryMessageType = 'success';
                },

                removeEntrySource(index) {
                    this.entryForm.sources.splice(index, 1);
                    this.entryForm.sources = this.entryForm.sources.map((source, displayOrder) => ({ ...source, display_order: displayOrder }));
                },

                async checkEntrySources() {
                    this.entryMessage = 'Checking sources...';
                    this.entryMessageType = '';
                    try {
                        const response = await fetch('/api/logger.php?action=validate-sources', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                entry: {
                                    mode: this.entryForm.mode,
                                    environment_id: this.entryForm.mode === 'environment' ? this.entryForm.environment_id : null,
                                },
                                sources: this.entryForm.sources,
                            }),
                        });
                        const data = await response.json();
                        const validation = data.validation || {};
                        if (data.success && validation.valid) {
                            this.entryMessage = 'Sources resolve.';
                            this.entryMessageType = 'success';
                            return;
                        }
                        this.entryMessage = (validation.errors || [data.error || 'Sources need attention.']).join(' ');
                        this.entryMessageType = 'error';
                    } catch (error) {
                        this.entryMessage = `Source check failed: ${error.message}`;
                        this.entryMessageType = 'error';
                    }
                },

                async saveEntry() {
                    if (!this.canSaveEntry()) return;
                    this.savingEntry = true;
                    this.entryMessage = 'Saving entry...';
                    this.entryMessageType = '';
                    try {
                        const payload = {
                            id: this.entryForm.id || undefined,
                            name: this.entryForm.name.trim(),
                            mode: this.entryForm.mode,
                            environment_id: this.entryForm.mode === 'environment' ? this.entryForm.environment_id : null,
                            enabled: !!this.entryForm.enabled,
                            sources: this.entryForm.sources.map((source, index) => ({ ...source, display_order: index })),
                        };
                        const response = await fetch('/api/logger.php?action=entry', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify(payload),
                        });
                        const data = await response.json();
                        if (!data.success) {
                            this.entryMessage = (data.errors || [data.error || 'Failed to save entry.']).join(' ');
                            this.entryMessageType = 'error';
                            return;
                        }
                        await this.reloadCollections();
                        this.closeEntryModal();
                        this.activeView = 'entries';
                        this.toast('Entry saved');
                    } catch (error) {
                        this.entryMessage = `Failed to save entry: ${error.message}`;
                        this.entryMessageType = 'error';
                    } finally {
                        this.savingEntry = false;
                    }
                },

                async deleteEntry(entry) {
                    if (!confirm(`Delete "${entry.name}"?`)) return;
                    try {
                        const response = await fetch(`/api/logger.php?action=entry&id=${encodeURIComponent(entry.id)}`, { method: 'DELETE' });
                        const data = await response.json();
                        if (!data.success) {
                            this.toast((data.errors || [data.error || 'Failed to delete entry.']).join(' '), 'error');
                            return;
                        }
                        await this.reloadCollections();
                        this.toast('Entry deleted');
                    } catch (error) {
                        this.toast(`Failed to delete entry: ${error.message}`, 'error');
                    }
                },

                openGroupModal(group = null) {
                    this.groupMessage = '';
                    this.groupMessageType = '';
                    this.groupSearch = '';
                    this.showGroupModal = true;
                    if (group?.id) {
                        this.loadGroupForEdit(group.id);
                        return;
                    }
                    this.groupForm = this.blankGroupForm();
                },

                closeGroupModal() {
                    this.showGroupModal = false;
                },

                async loadGroupForEdit(id) {
                    this.groupMessage = 'Loading group...';
                    try {
                        const response = await fetch(`/api/logger.php?action=group&id=${encodeURIComponent(id)}`);
                        const data = await response.json();
                        if (!data.success) {
                            this.groupMessage = data.error || 'Failed to load group.';
                            this.groupMessageType = 'error';
                            return;
                        }
                        const group = data.group || {};
                        this.groupForm = {
                            id: group.id || '',
                            name: group.name || '',
                            description: group.description || '',
                            entry_ids: (group.entries || []).map((entry) => entry.id).filter(Boolean),
                        };
                        this.groupMessage = 'Group loaded.';
                        this.groupMessageType = 'success';
                    } catch (error) {
                        this.groupMessage = `Failed to load group: ${error.message}`;
                        this.groupMessageType = 'error';
                    }
                },

                async saveGroup() {
                    if (!this.groupForm.name.trim()) return;
                    this.savingGroup = true;
                    this.groupMessage = 'Saving group...';
                    this.groupMessageType = '';
                    try {
                        const response = await fetch('/api/logger.php?action=group', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                id: this.groupForm.id || undefined,
                                name: this.groupForm.name.trim(),
                                description: this.groupForm.description.trim(),
                                entry_ids: this.groupForm.entry_ids,
                            }),
                        });
                        const data = await response.json();
                        if (!data.success) {
                            this.groupMessage = (data.errors || [data.error || 'Failed to save group.']).join(' ');
                            this.groupMessageType = 'error';
                            return;
                        }
                        await this.reloadCollections();
                        this.closeGroupModal();
                        this.activeView = 'groups';
                        this.toast('Group saved');
                    } catch (error) {
                        this.groupMessage = `Failed to save group: ${error.message}`;
                        this.groupMessageType = 'error';
                    } finally {
                        this.savingGroup = false;
                    }
                },

                async deleteGroup(group) {
                    if (!confirm(`Delete "${group.name}"?`)) return;
                    try {
                        const response = await fetch(`/api/logger.php?action=group&id=${encodeURIComponent(group.id)}`, { method: 'DELETE' });
                        const data = await response.json();
                        if (!data.success) {
                            this.toast((data.errors || [data.error || 'Failed to delete group.']).join(' '), 'error');
                            return;
                        }
                        await this.reloadCollections();
                        this.toast('Group deleted');
                    } catch (error) {
                        this.toast(`Failed to delete group: ${error.message}`, 'error');
                    }
                },

                openFilterModal(ownerType = null, owner = null, filter = null) {
                    this.filterMessage = '';
                    this.filterMessageType = '';
                    this.showFilterModal = true;
                    if (filter?.id) {
                        this.filterForm = {
                            id: filter.id || '',
                            owner_type: filter.owner_type || 'entry',
                            owner_id: filter.owner_id || '',
                            name: filter.name || '',
                            mode: filter.mode || 'contains_all',
                            pattern: filter.pattern || '',
                            case_sensitive: !!filter.case_sensitive,
                            enabled: filter.enabled !== false,
                        };
                        return;
                    }

                    const resolvedOwnerType = ownerType || (this.entries.length ? 'entry' : 'group');
                    const owners = resolvedOwnerType === 'group' ? this.groups : this.entries;
                    this.filterForm = this.blankFilterForm();
                    this.filterForm.owner_type = resolvedOwnerType;
                    this.filterForm.owner_id = owner?.id || owners[0]?.id || '';
                    if (!this.filterForm.owner_id) {
                        this.filterMessage = 'Create an entry or group before adding filters.';
                        this.filterMessageType = 'error';
                    }
                },

                closeFilterModal() {
                    this.showFilterModal = false;
                },

                selectFirstFilterOwner() {
                    this.filterForm.owner_id = this.filterOwnerOptions[0]?.id || '';
                },

                async saveFilter() {
                    if (!this.canSaveFilter()) return;
                    this.savingFilter = true;
                    this.filterMessage = 'Saving filter...';
                    this.filterMessageType = '';
                    try {
                        const response = await fetch('/api/logger.php?action=filter', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                id: this.filterForm.id || undefined,
                                owner_type: this.filterForm.owner_type,
                                owner_id: this.filterForm.owner_id,
                                name: this.filterForm.name.trim(),
                                mode: this.filterForm.mode,
                                pattern: this.filterForm.pattern.trim(),
                                case_sensitive: !!this.filterForm.case_sensitive,
                                enabled: !!this.filterForm.enabled,
                            }),
                        });
                        const data = await response.json();
                        if (!data.success) {
                            this.filterMessage = (data.errors || [data.error || 'Failed to save filter.']).join(' ');
                            this.filterMessageType = 'error';
                            return;
                        }
                        await this.reloadCollections();
                        this.closeFilterModal();
                        this.activeView = 'filters';
                        this.toast('Filter saved');
                    } catch (error) {
                        this.filterMessage = `Failed to save filter: ${error.message}`;
                        this.filterMessageType = 'error';
                    } finally {
                        this.savingFilter = false;
                    }
                },

                async deleteFilter(filter) {
                    if (!confirm(`Delete "${filter.name}"?`)) return;
                    try {
                        const response = await fetch(`/api/logger.php?action=filter&id=${encodeURIComponent(filter.id)}`, { method: 'DELETE' });
                        const data = await response.json();
                        if (!data.success) {
                            this.toast((data.errors || [data.error || 'Failed to delete filter.']).join(' '), 'error');
                            return;
                        }
                        await this.reloadCollections();
                        this.toast('Filter deleted');
                    } catch (error) {
                        this.toast(`Failed to delete filter: ${error.message}`, 'error');
                    }
                },
            },
        }).mount('#loggerWorkspace');
    </script>
</body>
</html>
