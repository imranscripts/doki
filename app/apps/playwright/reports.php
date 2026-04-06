<?php
/**
 * Playwright App - Reports Page
 * 
 * View and manage test reports with filtering and cleanup.
 */

require_once __DIR__ . '/../../includes/AppContext.php';

$app = new AppContext('playwright');
$user = $app->requireAuth();
$layout = $app->getLayout();
$settings = $app->getSettings();
$isAdmin = $app->hasRole('admin');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $layout->renderHead('Playwright - Reports'); ?>
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
            flex-wrap: wrap;
            gap: 12px;
        }

        .toolbar-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .filter-btn {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            color: var(--text-secondary);
            cursor: pointer;
            font-size: 13px;
            transition: all 0.2s;
        }

        .filter-btn:hover, .filter-btn.active {
            border-color: var(--accent-primary);
            color: var(--accent-primary);
        }

        .filter-btn.active {
            background: var(--accent-glow);
        }

        /* Reports Table */
        .reports-table {
            width: 100%;
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            overflow: hidden;
        }

        .reports-table th,
        .reports-table td {
            padding: 14px 16px;
            text-align: left;
        }

        .reports-table thead {
            background: var(--bg-tertiary);
            border-bottom: 1px solid var(--border-color);
        }

        .reports-table th {
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            color: var(--text-muted);
            letter-spacing: 0.5px;
        }

        .reports-table tbody tr {
            border-bottom: 1px solid var(--border-color);
            transition: background 0.15s;
        }

        .reports-table tbody tr:last-child {
            border-bottom: none;
        }

        .reports-table tbody tr:hover {
            background: var(--bg-tertiary);
        }

        /* Status Badge */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-badge.passed, .status-badge.completed {
            background: rgba(34, 197, 94, 0.1);
            color: #22c55e;
        }

        .status-badge.failed {
            background: rgba(239, 68, 68, 0.1);
            color: #ef4444;
        }

        .status-badge.running {
            background: rgba(59, 130, 246, 0.1);
            color: #3b82f6;
        }

        .status-badge.running i {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .status-badge.error {
            background: rgba(245, 158, 11, 0.1);
            color: #f59e0b;
        }

        .status-badge.unknown {
            background: rgba(107, 114, 128, 0.1);
            color: #6b7280;
        }

        /* Latest Badge */
        .latest-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 8px;
            background: var(--accent-primary);
            color: white;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            margin-left: 8px;
        }

        /* Report Name */
        .report-name {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .report-name strong {
            font-size: 14px;
        }

        /* Test Count */
        .test-count {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 8px;
            background: var(--bg-tertiary);
            border-radius: 6px;
            font-size: 12px;
            color: var(--text-secondary);
        }

        /* Date */
        .report-date {
            font-size: 13px;
            color: var(--text-secondary);
        }

        /* Actions */
        .action-buttons {
            display: flex;
            gap: 8px;
        }

        /* Media badges */
        .media-badges {
            display: flex;
            gap: 6px;
            margin-top: 4px;
        }

        .media-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 6px;
            background: var(--bg-tertiary);
            border-radius: 4px;
            font-size: 11px;
            color: var(--text-muted);
        }

        .media-badge.videos {
            color: #a855f7;
            background: rgba(168, 85, 247, 0.1);
        }

        .media-badge.screenshots {
            color: #3b82f6;
            background: rgba(59, 130, 246, 0.1);
        }

        /* Video Modal */
        .video-modal .modal {
            max-width: 900px;
        }

        .video-player {
            width: 100%;
            border-radius: 8px;
            background: #000;
        }

        .video-nav {
            display: flex;
            gap: 8px;
            margin-bottom: 16px;
            overflow-x: auto;
            padding-bottom: 8px;
        }

        .video-thumb {
            padding: 8px 12px;
            background: var(--bg-tertiary);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            cursor: pointer;
            white-space: nowrap;
            font-size: 12px;
            transition: all 0.2s;
        }

        .video-thumb:hover {
            border-color: var(--accent-primary);
        }

        .video-thumb.active {
            background: var(--accent-primary);
            border-color: var(--accent-primary);
            color: white;
        }

        .screenshot-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 12px;
        }

        .screenshot-item {
            border-radius: 8px;
            overflow: hidden;
            cursor: pointer;
            border: 1px solid var(--border-color);
            transition: all 0.2s;
        }

        .screenshot-item:hover {
            border-color: var(--accent-primary);
            transform: scale(1.02);
        }

        .screenshot-item img {
            width: 100%;
            display: block;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 8px 14px;
            border: none;
            border-radius: 6px;
            font-size: 13px;
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

        .btn-danger {
            background: transparent;
            color: var(--danger);
            border: 1px solid var(--danger);
        }

        .btn-danger:hover {
            background: var(--danger);
            color: white;
        }

        .btn-sm {
            padding: 6px 10px;
            font-size: 12px;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 24px;
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 12px;
        }

        .empty-state > i {
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
        }

        /* Stats Bar */
        .stats-bar {
            display: flex;
            gap: 24px;
            margin-bottom: 24px;
            padding: 16px 20px;
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 12px;
        }

        .stat-item {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }

        .stat-icon.total {
            background: rgba(99, 102, 241, 0.1);
            color: var(--accent-primary);
        }

        .stat-icon.passed {
            background: rgba(34, 197, 94, 0.1);
            color: #22c55e;
        }

        .stat-icon.failed {
            background: rgba(239, 68, 68, 0.1);
            color: #ef4444;
        }

        .stat-value {
            font-size: 24px;
            font-weight: 600;
        }

        .stat-label {
            font-size: 12px;
            color: var(--text-muted);
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
            transition: all 0.2s;
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
            gap: 12px;
            justify-content: flex-end;
            padding: 16px 24px;
            border-top: 1px solid var(--border-color);
        }

        /* Log Viewer */
        .log-modal .modal {
            max-width: 1000px;
            height: 80vh;
        }

        .log-viewer {
            background: #1a1a2e;
            border-radius: 8px;
            padding: 16px;
            font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
            font-size: 12px;
            line-height: 1.5;
            color: #e0e0e0;
            overflow-y: auto;
            max-height: calc(80vh - 180px);
            white-space: pre-wrap;
            word-break: break-word;
        }

        .log-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
        }

        .log-status {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
        }

        .log-status.running {
            color: #3b82f6;
        }

        .log-status.completed {
            color: #22c55e;
        }

        .log-status.failed {
            color: #ef4444;
        }

        .log-actions {
            display: flex;
            gap: 8px;
        }

        /* Running indicator */
        .running-indicator {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            color: var(--text-muted);
            margin-left: 12px;
        }

        .running-indicator i {
            color: #3b82f6;
        }

        /* Project info */
        .project-info {
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 2px;
        }
    </style>
</head>
<body>
    <?php $layout->renderSidebar(); ?>
    
    <main class="main-content">
        <div class="content-area">
            <!-- App Header & Navigation (from AppContext) -->
            <?php $app->renderAppHeader(); ?>

            <div id="reports-app">
                <!-- Stats Bar -->
                <div class="stats-bar" v-if="reports.length > 0">
                    <div class="stat-item">
                        <div class="stat-icon total">
                            <i class="fas fa-file-alt"></i>
                        </div>
                        <div>
                            <div class="stat-value">{{ reports.length }}</div>
                            <div class="stat-label">Total Runs</div>
                        </div>
                    </div>
                    <div class="stat-item" v-if="runningCount > 0">
                        <div class="stat-icon" style="background: rgba(59, 130, 246, 0.1); color: #3b82f6;">
                            <i class="fas fa-spinner fa-spin"></i>
                        </div>
                        <div>
                            <div class="stat-value">{{ runningCount }}</div>
                            <div class="stat-label">Running</div>
                        </div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-icon passed">
                            <i class="fas fa-check"></i>
                        </div>
                        <div>
                            <div class="stat-value">{{ passedCount }}</div>
                            <div class="stat-label">Passed</div>
                        </div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-icon failed">
                            <i class="fas fa-times"></i>
                        </div>
                        <div>
                            <div class="stat-value">{{ failedCount }}</div>
                            <div class="stat-label">Failed</div>
                        </div>
                    </div>
                </div>

                <!-- Toolbar -->
                <div class="toolbar">
                    <div class="toolbar-left">
                        <button class="filter-btn" :class="{ active: filter === 'all' }" @click="filter = 'all'">
                            All ({{ reports.length }})
                        </button>
                        <button class="filter-btn" :class="{ active: filter === 'running' }" @click="filter = 'running'" v-if="runningCount > 0">
                            <i class="fas fa-spinner fa-spin"></i>
                            Running ({{ runningCount }})
                        </button>
                        <button class="filter-btn" :class="{ active: filter === 'passed' }" @click="filter = 'passed'">
                            <i class="fas fa-check-circle"></i>
                            Passed ({{ passedCount }})
                        </button>
                        <button class="filter-btn" :class="{ active: filter === 'failed' }" @click="filter = 'failed'">
                            <i class="fas fa-times-circle"></i>
                            Failed ({{ failedCount }})
                        </button>
                        <span v-if="runningCount > 0" class="running-indicator">
                            <i class="fas fa-circle-notch fa-spin"></i>
                            Auto-refreshing...
                        </span>
                    </div>
                    <?php if ($isAdmin): ?>
                    <button class="btn btn-secondary" @click="cleanupReports" :disabled="cleaning">
                        <i class="fas fa-broom"></i>
                        {{ cleaning ? 'Cleaning...' : 'Cleanup Old Reports' }}
                    </button>
                    <?php endif; ?>
                </div>

                <!-- Loading -->
                <div v-if="loading" class="empty-state">
                    <i class="fas fa-spinner fa-spin"></i>
                    <h3>Loading reports...</h3>
                </div>

                <!-- Empty State -->
                <div v-else-if="filteredReports.length === 0" class="empty-state">
                    <i class="fas fa-file-alt"></i>
                    <h3>No Reports Found</h3>
                    <p v-if="filter !== 'all'">No {{ filter }} reports. Try changing the filter.</p>
                    <p v-else>Run some Playwright tests to generate reports.</p>
                </div>

                <!-- Reports Table -->
                <table v-else class="reports-table">
                    <thead>
                        <tr>
                            <th>Status</th>
                            <th>Test Name</th>
                            <th>Files</th>
                            <th>Media</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="report in filteredReports" :key="report.id">
                            <td>
                                <span class="status-badge" :class="report.status">
                                    <i :class="getStatusIcon(report.status)"></i>
                                    {{ getStatusLabel(report.status) }}
                                </span>
                            </td>
                            <td>
                                <div class="report-name">
                                    <strong>{{ report.name }}</strong>
                                </div>
                                <div class="project-info" v-if="report.project">
                                    <i class="fas fa-folder"></i> {{ report.project }}
                                </div>
                            </td>
                            <td>
                                <span v-if="report.testCount" class="test-count">
                                    <i class="fas fa-vial"></i>
                                    {{ report.testCount }} file{{ report.testCount > 1 ? 's' : '' }}
                                </span>
                                <span v-else class="test-count">-</span>
                            </td>
                            <td>
                                <div class="media-badges">
                                    <span v-if="report.videos && report.videos.length" class="media-badge videos" @click="openMediaModal(report, 'videos')" style="cursor: pointer;">
                                        <i class="fas fa-video"></i>
                                        {{ report.videos.length }}
                                    </span>
                                    <span v-if="report.screenshots && report.screenshots.length" class="media-badge screenshots" @click="openMediaModal(report, 'screenshots')" style="cursor: pointer;">
                                        <i class="fas fa-image"></i>
                                        {{ report.screenshots.length }}
                                    </span>
                                    <span v-if="(!report.videos || !report.videos.length) && (!report.screenshots || !report.screenshots.length)" style="color: var(--text-muted);">-</span>
                                </div>
                            </td>
                            <td>
                                <span class="report-date">{{ formatDate(report.date) }}</span>
                                <div class="project-info" v-if="report.endTime && report.status !== 'running'">
                                    Duration: {{ formatDuration(report.date, report.endTime) }}
                                </div>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <button v-if="report.hasLog" class="btn btn-secondary btn-sm" @click="openLogModal(report)">
                                        <i class="fas fa-terminal"></i>
                                        Log
                                    </button>
                                    <a v-if="report.hasReport" :href="'../../' + report.path" target="_blank" class="btn btn-primary btn-sm">
                                        <i class="fas fa-chart-bar"></i>
                                        Report
                                    </a>
                                    <span v-if="!report.hasReport && report.status !== 'running'" style="color: var(--text-muted); font-size: 12px;">
                                        No report
                                    </span>
                                    <?php if ($isAdmin): ?>
                                    <button v-if="report.status !== 'running'" class="btn btn-danger btn-sm" @click="deleteReport(report)">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <!-- Media Modal -->
                <div class="modal-backdrop video-modal" :class="{ active: showMediaModal }" @click.self="showMediaModal = false">
                    <div class="modal">
                        <div class="modal-header">
                            <h2>
                                <i :class="mediaType === 'videos' ? 'fas fa-video' : 'fas fa-image'" style="margin-right: 8px;"></i>
                                {{ mediaType === 'videos' ? 'Test Videos' : 'Screenshots' }} - {{ mediaReport?.name }}
                            </h2>
                            <button class="modal-close" @click="showMediaModal = false">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        <div class="modal-body">
                            <!-- Videos -->
                            <div v-if="mediaType === 'videos'">
                                <div class="video-nav" v-if="mediaReport?.videos?.length > 1">
                                    <button 
                                        v-for="(video, index) in mediaReport.videos" 
                                        :key="video"
                                        class="video-thumb"
                                        :class="{ active: currentVideoIndex === index }"
                                        @click="currentVideoIndex = index"
                                    >
                                        Video {{ index + 1 }}
                                    </button>
                                </div>
                                <video 
                                    v-if="mediaReport?.videos?.length"
                                    class="video-player"
                                    controls
                                    :key="currentVideoUrl"
                                    :src="currentVideoUrl"
                                >
                                    Your browser does not support video playback.
                                </video>
                            </div>
                            <!-- Screenshots -->
                            <div v-if="mediaType === 'screenshots'">
                                <div class="screenshot-grid">
                                    <div 
                                        v-for="screenshot in mediaReport?.screenshots" 
                                        :key="screenshot"
                                        class="screenshot-item"
                                        @click="openFullImage(resolveMediaUrl(screenshot))"
                                    >
                                        <img :src="resolveMediaUrl(screenshot)" :alt="screenshot">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Log Modal -->
                <div class="modal-backdrop log-modal" :class="{ active: showLogModal }" @click.self="closeLogModal">
                    <div class="modal">
                        <div class="modal-header">
                            <h2>
                                <i class="fas fa-terminal" style="margin-right: 8px;"></i>
                                Test Output - {{ logReport?.name }}
                            </h2>
                            <button class="modal-close" @click="closeLogModal">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        <div class="modal-body">
                            <div class="log-toolbar">
                                <div class="log-status" :class="logStatus">
                                    <i :class="getStatusIcon(logStatus)"></i>
                                    <span>{{ getStatusLabel(logStatus) }}</span>
                                    <span v-if="logStatus === 'running'" style="margin-left: 8px; font-size: 11px; color: var(--text-muted);">
                                        (auto-refreshing)
                                    </span>
                                </div>
                                <div class="log-actions">
                                    <button class="btn btn-secondary btn-sm" @click="copyLog" title="Copy to clipboard">
                                        <i class="fas fa-copy"></i>
                                        Copy
                                    </button>
                                    <button v-if="logStatus === 'running'" class="btn btn-secondary btn-sm" @click="refreshLog" title="Refresh">
                                        <i class="fas fa-sync"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="log-viewer" ref="logViewer">{{ logContent || 'Loading...' }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/vue@3/dist/vue.global.prod.js"></script>
    <script>
        const app = Vue.createApp({
            data() {
                return {
                    reports: [],
                    loading: true,
                    filter: 'all',
                    cleaning: false,
                    showMediaModal: false,
                    mediaReport: null,
                    mediaType: 'videos',
                    currentVideoIndex: 0,
                    // Log modal
                    showLogModal: false,
                    logReport: null,
                    logContent: '',
                    logStatus: 'unknown',
                    logRefreshInterval: null,
                    // Auto-refresh
                    autoRefreshInterval: null
                };
            },

            computed: {
                filteredReports() {
                    if (this.filter === 'all') return this.reports;
                    if (this.filter === 'passed') {
                        return this.reports.filter(r => r.status === 'passed' || r.status === 'completed');
                    }
                    return this.reports.filter(r => r.status === this.filter);
                },

                passedCount() {
                    return this.reports.filter(r => r.status === 'passed' || r.status === 'completed').length;
                },

                failedCount() {
                    return this.reports.filter(r => r.status === 'failed').length;
                },

                runningCount() {
                    return this.reports.filter(r => r.status === 'running').length;
                },

                currentVideoUrl() {
                    if (!this.mediaReport?.videos?.length) return '';
                    const video = this.mediaReport.videos[this.currentVideoIndex];
                    return this.resolveMediaUrl(video);
                }
            },

            watch: {
                runningCount(newCount, oldCount) {
                    // Start or stop auto-refresh based on running tests
                    if (newCount > 0 && !this.autoRefreshInterval) {
                        this.startAutoRefresh();
                    } else if (newCount === 0 && this.autoRefreshInterval) {
                        this.stopAutoRefresh();
                    }
                }
            },

            methods: {
                async loadReports() {
                    try {
                        const response = await fetch('../../api/playwright.php?action=reports');
                        const data = await response.json();
                        if (data.success) {
                            this.reports = data.reports;
                        }
                    } catch (error) {
                        console.error('Failed to load reports:', error);
                        window.Toast?.error('Failed to load reports');
                    } finally {
                        this.loading = false;
                    }
                },

                startAutoRefresh() {
                    this.autoRefreshInterval = setInterval(() => {
                        this.loadReports();
                    }, 5000); // Refresh every 5 seconds
                },

                stopAutoRefresh() {
                    if (this.autoRefreshInterval) {
                        clearInterval(this.autoRefreshInterval);
                        this.autoRefreshInterval = null;
                    }
                },

                formatDate(dateStr) {
                    if (!dateStr) return '';
                    const date = new Date(dateStr);
                    if (isNaN(date.getTime())) return 'Unknown';
                    return date.toLocaleString();
                },

                formatDuration(startStr, endStr) {
                    if (!startStr || !endStr) return '';
                    const start = new Date(startStr);
                    const end = new Date(endStr);
                    
                    // Check for invalid dates
                    if (isNaN(start.getTime()) || isNaN(end.getTime())) return '';
                    
                    const diffMs = end - start;
                    
                    if (diffMs < 0) return '';
                    if (diffMs < 1000) return '< 1s';
                    if (diffMs < 60000) return Math.round(diffMs / 1000) + 's';
                    if (diffMs < 3600000) {
                        const mins = Math.floor(diffMs / 60000);
                        const secs = Math.round((diffMs % 60000) / 1000);
                        return mins + 'm ' + secs + 's';
                    }
                    const hours = Math.floor(diffMs / 3600000);
                    const mins = Math.round((diffMs % 3600000) / 60000);
                    return hours + 'h ' + mins + 'm';
                },

                getStatusIcon(status) {
                    return {
                        passed: 'fas fa-check-circle',
                        completed: 'fas fa-check-circle',
                        failed: 'fas fa-times-circle',
                        running: 'fas fa-spinner fa-spin',
                        error: 'fas fa-exclamation-triangle',
                        unknown: 'fas fa-question-circle'
                    }[status] || 'fas fa-question-circle';
                },

                getStatusLabel(status) {
                    return {
                        passed: 'Passed',
                        completed: 'Passed',
                        failed: 'Failed',
                        running: 'Running',
                        error: 'Error',
                        unknown: 'Unknown'
                    }[status] || status;
                },

                async openLogModal(report) {
                    this.logReport = report;
                    this.logContent = 'Loading...';
                    this.logStatus = report.status;
                    this.showLogModal = true;
                    
                    await this.refreshLog();
                    
                    // Start refresh interval if running
                    if (report.status === 'running') {
                        this.logRefreshInterval = setInterval(() => {
                            this.refreshLog();
                        }, 2000);
                    }
                },

                async refreshLog() {
                    if (!this.logReport) return;
                    
                    try {
                        const response = await fetch(`../../api/playwright.php?action=report-log&id=${this.logReport.id}`);
                        const data = await response.json();
                        
                        if (data.success) {
                            this.logContent = data.log;
                            this.logStatus = data.status;
                            
                            // Auto-scroll to bottom
                            this.$nextTick(() => {
                                const viewer = this.$refs.logViewer;
                                if (viewer) {
                                    viewer.scrollTop = viewer.scrollHeight;
                                }
                            });
                            
                            // Stop refresh if no longer running
                            if (!data.isRunning && this.logRefreshInterval) {
                                clearInterval(this.logRefreshInterval);
                                this.logRefreshInterval = null;
                                // Also refresh the main list to get updated status
                                this.loadReports();
                            }
                        }
                    } catch (error) {
                        console.error('Failed to load log:', error);
                    }
                },

                closeLogModal() {
                    this.showLogModal = false;
                    if (this.logRefreshInterval) {
                        clearInterval(this.logRefreshInterval);
                        this.logRefreshInterval = null;
                    }
                },

                copyLog() {
                    navigator.clipboard.writeText(this.logContent).then(() => {
                        window.Toast?.success('Log copied to clipboard');
                    }).catch(() => {
                        window.Toast?.error('Failed to copy');
                    });
                },

                async deleteReport(report) {
                    if (!confirm(`Delete report "${report.name}"?`)) return;

                    try {
                        const response = await fetch(`../../api/playwright.php?action=delete-report&id=${report.id}`, {
                            method: 'POST'
                        });
                        const data = await response.json();

                        if (data.success) {
                            window.Toast?.success('Report deleted');
                            this.loadReports();
                        } else {
                            window.Toast?.error(data.error || 'Failed to delete report');
                        }
                    } catch (error) {
                        console.error('Delete error:', error);
                        window.Toast?.error('Failed to delete report');
                    }
                },

                async cleanupReports() {
                    if (!confirm('Delete old reports based on retention settings?')) return;

                    this.cleaning = true;
                    try {
                        const response = await fetch('../../api/playwright.php?action=cleanup-reports', {
                            method: 'POST'
                        });
                        const data = await response.json();

                        if (data.success) {
                            window.Toast?.success(`Cleaned up ${data.deleted} report(s)`);
                            this.loadReports();
                        } else {
                            window.Toast?.error(data.error || 'Cleanup failed');
                        }
                    } catch (error) {
                        console.error('Cleanup error:', error);
                        window.Toast?.error('Cleanup failed');
                    } finally {
                        this.cleaning = false;
                    }
                },

                openMediaModal(report, type) {
                    this.mediaReport = report;
                    this.mediaType = type;
                    this.currentVideoIndex = 0;
                    this.showMediaModal = true;
                },

                resolveMediaUrl(mediaPath) {
                    if (!this.mediaReport || !mediaPath) return '';
                    if (mediaPath.startsWith('test-results/')) {
                        return '../../playwright-reports-archive/' + this.mediaReport.id + '/' + mediaPath;
                    }
                    return '../../' + this.mediaReport.dataPath + mediaPath;
                },

                openFullImage(url) {
                    if (!url) return;
                    window.open(url, '_blank');
                }
            },

            mounted() {
                this.loadReports();
            },

            beforeUnmount() {
                this.stopAutoRefresh();
                if (this.logRefreshInterval) {
                    clearInterval(this.logRefreshInterval);
                }
            }
        });

        app.mount('#reports-app');
    </script>
</body>
</html>
