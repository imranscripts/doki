<?php
/**
 * Execution History Page - View past command executions
 */
require_once __DIR__ . '/includes/middleware.php';
requireAuth();
requireModuleAccess('history');

$user = getCurrentUser();
$layout = new Layout($user, 'history');
$isAdmin = in_array($user['role'], ['super-admin', 'admin']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $layout->renderHead('Execution History'); ?>
    <style>
        /* Jobs Table */
        .jobs-table {
            width: 100%;
            border-collapse: collapse;
        }

        .jobs-table th,
        .jobs-table td {
            padding: 12px 16px;
            text-align: left;
            border-bottom: 1px solid var(--border-primary);
        }

        .jobs-table th {
            background: var(--bg-tertiary);
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-muted);
        }

        .jobs-table tr:hover {
            background: var(--bg-hover);
        }

        .jobs-table tr {
            cursor: pointer;
        }

        /* Status badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
        }

        .status-badge.completed {
            background: rgba(63, 185, 80, 0.15);
            color: var(--success);
        }

        .status-badge.failed {
            background: rgba(248, 81, 73, 0.15);
            color: var(--error);
        }

        .status-badge.running {
            background: rgba(88, 166, 255, 0.15);
            color: var(--accent-primary);
        }

        .status-badge.pending {
            background: rgba(210, 153, 34, 0.15);
            color: var(--warning);
        }

        .history-mini-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-left: 8px;
            padding: 3px 8px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 600;
            background: rgba(88, 166, 255, 0.15);
            color: var(--accent-primary);
        }

        .status-badge.cancelled {
            background: rgba(139, 148, 158, 0.15);
            color: var(--text-muted);
        }

        /* Job detail modal */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.7);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            padding: 24px;
            opacity: 0;
            visibility: hidden;
            transition: all 0.2s ease;
        }

        .modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .modal {
            background: var(--bg-secondary);
            border: 1px solid var(--border-primary);
            border-radius: 16px;
            width: 100%;
            max-width: 900px;
            max-height: 90vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            transform: scale(0.95);
            transition: transform 0.2s ease;
        }

        .modal-overlay.active .modal {
            transform: scale(1);
        }

        .modal-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--border-primary);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .modal-title {
            font-size: 18px;
            font-weight: 600;
        }

        .modal-close {
            background: none;
            border: none;
            color: var(--text-muted);
            font-size: 20px;
            cursor: pointer;
            padding: 4px;
        }

        .modal-close:hover {
            color: var(--text-primary);
        }

        .modal-body {
            padding: 24px;
            overflow-y: auto;
            flex: 1;
        }

        .job-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .job-meta-item {
            background: var(--bg-tertiary);
            border-radius: 8px;
            padding: 12px 16px;
        }

        .job-meta-label {
            font-size: 12px;
            color: var(--text-muted);
            margin-bottom: 4px;
        }

        .job-meta-value {
            font-size: 14px;
            font-weight: 500;
        }

        .job-output {
            background: #0d1117;
            border: 1px solid var(--border-primary);
            border-radius: 8px;
            padding: 16px;
            font-family: 'JetBrains Mono', 'Fira Code', monospace;
            font-size: 13px;
            line-height: 1.5;
            color: #c9d1d9;
            max-height: 400px;
            overflow: auto;
            white-space: pre-wrap;
            word-break: break-all;
        }

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
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

        /* Filters */
        .filters {
            display: flex;
            gap: 12px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }

        .filter-select {
            padding: 8px 12px;
            border: 1px solid var(--border-primary);
            border-radius: 8px;
            background: var(--bg-tertiary);
            color: var(--text-primary);
            font-size: 14px;
            min-width: 150px;
        }
    </style>
</head>
<body>
    <?php $layout->renderSidebar(); ?>

    <main class="main-content">
        <div class="page-header">
            <div>
                <h1 class="page-title">Execution History</h1>
                <p class="page-subtitle">View past command executions</p>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters">
            <select class="filter-select" id="statusFilter" onchange="loadJobs()">
                <option value="">All Status</option>
                <option value="completed">Completed</option>
                <option value="failed">Failed</option>
                <option value="running">Running</option>
                <option value="cancelled">Cancelled</option>
            </select>
            <?php if ($isAdmin): ?>
            <select class="filter-select" id="userFilter" onchange="loadJobs()">
                <option value="mine">My Executions</option>
                <option value="all">All Users</option>
            </select>
            <?php endif; ?>
        </div>

        <!-- Jobs Table -->
        <div class="card">
            <table class="jobs-table">
                <thead>
                    <tr>
                        <th>Status</th>
                        <th>Command</th>
                        <th>Template</th>
                        <th>User</th>
                        <th>Started</th>
                        <th>Duration</th>
                    </tr>
                </thead>
                <tbody id="jobsBody">
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 40px;">
                            <i class="fas fa-spinner fa-spin"></i> Loading...
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </main>

    <!-- Job Detail Modal -->
    <div class="modal-overlay" id="jobModal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title" id="modalTitle">Job Details</h2>
                <button class="modal-close" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <div class="job-meta" id="jobMeta"></div>
                <h3 style="font-size: 14px; color: var(--text-muted); margin-bottom: 12px;">Output</h3>
                <pre class="job-output" id="jobOutput"></pre>
            </div>
        </div>
    </div>

    <script>
        const isAdmin = <?= json_encode($isAdmin) ?>;
        let jobs = [];

        document.addEventListener('DOMContentLoaded', loadJobs);

        async function loadJobs() {
            const status = document.getElementById('statusFilter').value;
            const userFilter = document.getElementById('userFilter')?.value || 'mine';
            
            let url = 'api/execute.php?action=jobs&limit=100';
            if (userFilter === 'all' && isAdmin) {
                url += '&all=1';
            }
            
            try {
                const response = await fetch(url);
                const data = await response.json();
                
                if (data.success) {
                    jobs = data.jobs;
                    
                    // Filter by status client-side
                    if (status) {
                        jobs = jobs.filter(j => j.status === status);
                    }
                    
                    renderJobs();
                } else {
                    Toast.error('Failed to load jobs: ' + data.error);
                }
            } catch (error) {
                console.error('Error loading jobs:', error);
                Toast.error('Failed to load jobs');
            }
        }

        function renderJobs() {
            const tbody = document.getElementById('jobsBody');
            
            if (jobs.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="6">
                            <div class="empty-state">
                                <i class="fas fa-history"></i>
                                <h3>No executions found</h3>
                                <p>Run a command to see execution history</p>
                            </div>
                        </td>
                    </tr>
                `;
                return;
            }
            
            tbody.innerHTML = jobs.map(job => {
                const duration = job.completed_at && job.started_at
                    ? formatDuration(new Date(job.completed_at) - new Date(job.started_at))
                    : job.status === 'running' ? 'In progress...' : '-';
                
                return `
                    <tr onclick="showJob('${job.id}')">
                        <td>
                            <span class="status-badge ${job.status}">
                                <i class="fas ${getStatusIcon(job.status)}"></i>
                                ${job.status}
                            </span>
                        </td>
                        <td>${escapeHtml(job.command_name)}${job.isDryRun ? ' <span class="history-mini-badge"><i class="fas fa-vial"></i> Dry run</span>' : ''}</td>
                        <td>${escapeHtml(job.template_id)}</td>
                        <td>${escapeHtml(job.username)}</td>
                        <td>${formatDate(job.started_at)}</td>
                        <td>${duration}</td>
                    </tr>
                `;
            }).join('');
        }

        function getStatusIcon(status) {
            const icons = {
                completed: 'fa-check-circle',
                failed: 'fa-times-circle',
                running: 'fa-spinner fa-spin',
                pending: 'fa-clock',
                cancelled: 'fa-ban'
            };
            return icons[status] || 'fa-question-circle';
        }

        function formatDate(dateStr) {
            if (!dateStr) return '-';
            const date = new Date(dateStr);
            return date.toLocaleString();
        }

        function formatDuration(ms) {
            if (ms < 1000) return ms + 'ms';
            if (ms < 60000) return Math.round(ms / 1000) + 's';
            if (ms < 3600000) return Math.round(ms / 60000) + 'm ' + Math.round((ms % 60000) / 1000) + 's';
            return Math.round(ms / 3600000) + 'h ' + Math.round((ms % 3600000) / 60000) + 'm';
        }

        async function showJob(jobId) {
            const job = jobs.find(j => j.id === jobId);
            if (!job) return;
            
            document.getElementById('modalTitle').textContent = job.command_name;
            
            document.getElementById('jobMeta').innerHTML = `
                <div class="job-meta-item">
                    <div class="job-meta-label">Status</div>
                    <div class="job-meta-value">
                        <span class="status-badge ${job.status}">
                            <i class="fas ${getStatusIcon(job.status)}"></i>
                            ${job.status}
                        </span>
                    </div>
                </div>
                <div class="job-meta-item">
                    <div class="job-meta-label">Exit Code</div>
                    <div class="job-meta-value">${job.exit_code ?? '-'}</div>
                </div>
                <div class="job-meta-item">
                    <div class="job-meta-label">Run Mode</div>
                    <div class="job-meta-value">${job.isDryRun ? 'Dry run' : 'Executed'}</div>
                </div>
                <div class="job-meta-item">
                    <div class="job-meta-label">Template</div>
                    <div class="job-meta-value">${escapeHtml(job.template_id)}</div>
                </div>
                <div class="job-meta-item">
                    <div class="job-meta-label">Target</div>
                    <div class="job-meta-value">${escapeHtml(job.target_id || 'None')}</div>
                </div>
                <div class="job-meta-item">
                    <div class="job-meta-label">User</div>
                    <div class="job-meta-value">${escapeHtml(job.username)}</div>
                </div>
                <div class="job-meta-item">
                    <div class="job-meta-label">Job ID</div>
                    <div class="job-meta-value" style="font-family: monospace; font-size: 12px;">${escapeHtml(job.id)}</div>
                </div>
            `;
            
            document.getElementById('jobOutput').textContent = job.output || 'No output';
            
            document.getElementById('jobModal').classList.add('active');
        }

        function closeModal() {
            document.getElementById('jobModal').classList.remove('active');
        }

        document.getElementById('jobModal').addEventListener('click', (e) => {
            if (e.target.classList.contains('modal-overlay')) {
                closeModal();
            }
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeModal();
        });

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    </script>
</body>
</html>
