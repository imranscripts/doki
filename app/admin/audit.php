<?php
/**
 * admin/audit.php - Audit Log Page (Super-Admin Only)
 * 
 * Part of Doki v3 Architecture - Batch 9: Audit & History
 */

require_once __DIR__ . '/../includes/middleware.php';
require_once __DIR__ . '/../includes/Layout.php';
require_once __DIR__ . '/../includes/Database.php';

// Require super-admin role
$user = requireRole('super-admin');
requireModuleAccess('audit');

// Create layout instance
$layout = new Layout($user, 'audit');

// Get audit logs from database
$db = Database::getInstance();

// Filters
$filterAction = $_GET['action_filter'] ?? '';
$filterUser = $_GET['user_filter'] ?? '';
$filterResource = $_GET['resource_filter'] ?? '';
$filterDateFrom = $_GET['date_from'] ?? '';
$filterDateTo = $_GET['date_to'] ?? '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 50;
$offset = ($page - 1) * $perPage;

// Build query with filters
$whereClause = [];
$params = [];

if ($filterAction) {
    $whereClause[] = "action = ?";
    $params[] = $filterAction;
}
if ($filterUser) {
    $whereClause[] = "(username LIKE ? OR user_id = ?)";
    $params[] = "%{$filterUser}%";
    $params[] = $filterUser;
}
if ($filterResource) {
    $whereClause[] = "(resource_type = ? OR resource_id LIKE ?)";
    $params[] = $filterResource;
    $params[] = "%{$filterResource}%";
}
if ($filterDateFrom) {
    $whereClause[] = "created_at >= ?";
    $params[] = $filterDateFrom . ' 00:00:00';
}
if ($filterDateTo) {
    $whereClause[] = "created_at <= ?";
    $params[] = $filterDateTo . ' 23:59:59';
}

$whereSQL = !empty($whereClause) ? 'WHERE ' . implode(' AND ', $whereClause) : '';

// Get total count with filters
$countSQL = "SELECT COUNT(*) as total FROM audit_log $whereSQL";
$countStmt = $db->prepare($countSQL);
$countStmt->execute($params);
$total = $countStmt->fetch()['total'];
$totalPages = ceil($total / $perPage);

// Get logs with filters
$sql = "SELECT * FROM audit_log $whereSQL ORDER BY created_at DESC LIMIT ? OFFSET ?";
$stmt = $db->prepare($sql);
$stmt->execute([...$params, $perPage, $offset]);
$logs = $stmt->fetchAll();

// Get distinct values for filter dropdowns
$actionsStmt = $db->query("SELECT DISTINCT action FROM audit_log ORDER BY action");
$allActions = $actionsStmt->fetchAll(PDO::FETCH_COLUMN);

$resourcesStmt = $db->query("SELECT DISTINCT resource_type FROM audit_log WHERE resource_type IS NOT NULL ORDER BY resource_type");
$allResources = $resourcesStmt->fetchAll(PDO::FETCH_COLUMN);

$usersStmt = $db->query("SELECT DISTINCT username FROM audit_log WHERE username IS NOT NULL ORDER BY username");
$allUsers = $usersStmt->fetchAll(PDO::FETCH_COLUMN);

// Build query string for pagination
$queryParams = [];
if ($filterAction) $queryParams['action_filter'] = $filterAction;
if ($filterUser) $queryParams['user_filter'] = $filterUser;
if ($filterResource) $queryParams['resource_filter'] = $filterResource;
if ($filterDateFrom) $queryParams['date_from'] = $filterDateFrom;
if ($filterDateTo) $queryParams['date_to'] = $filterDateTo;
$baseQuery = http_build_query($queryParams);
$exportCsvQuery = http_build_query(array_merge($queryParams, ['format' => 'csv']));
$exportJsonQuery = http_build_query(array_merge($queryParams, ['format' => 'json']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $layout->renderHead('Audit Log'); ?>
    <style>
        .audit-filters {
            display: flex;
            gap: 12px;
            margin-bottom: 24px;
            flex-wrap: wrap;
            align-items: flex-end;
            padding: 16px;
            background: var(--bg-secondary);
            border-radius: 8px;
            border: 1px solid var(--border-color);
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .filter-group label {
            font-size: 12px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .filter-group select,
        .filter-group input {
            padding: 8px 12px;
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            color: var(--text-primary);
            font-size: 13px;
            min-width: 140px;
        }

        .filter-group input[type="date"] {
            min-width: 130px;
        }

        .filter-actions {
            display: flex;
            gap: 8px;
        }

        .filter-actions .btn {
            padding: 8px 16px;
        }

        .active-filters {
            display: flex;
            gap: 8px;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }

        .filter-tag {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            background: var(--accent-glow);
            border: 1px solid var(--accent-primary);
            border-radius: 16px;
            font-size: 12px;
            color: var(--accent-primary);
        }

        .filter-tag a {
            color: inherit;
            opacity: 0.7;
        }

        .filter-tag a:hover {
            opacity: 1;
        }

        .stats-bar {
            display: flex;
            gap: 24px;
            padding: 12px 16px;
            background: var(--bg-tertiary);
            border-radius: 8px;
            margin-bottom: 16px;
            font-size: 14px;
        }

        .stat-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .stat-item i {
            color: var(--text-muted);
        }

        .stat-item strong {
            color: var(--accent-primary);
        }

        .log-action {
            font-family: 'JetBrains Mono', monospace;
            font-size: 13px;
            padding: 4px 10px;
            background: var(--bg-tertiary);
            border-radius: 4px;
        }

        /* Auth actions */
        .log-action.login_success { color: var(--success); background: rgba(34, 197, 94, 0.1); }
        .log-action.login_failed { color: var(--error); background: rgba(239, 68, 68, 0.1); }
        .log-action.logout { color: var(--text-secondary); }
        .log-action.user_created { color: var(--accent-primary); background: var(--accent-glow); }
        .log-action.user_updated { color: var(--warning); background: rgba(245, 158, 11, 0.1); }
        .log-action.user_deleted { color: var(--error); background: rgba(239, 68, 68, 0.1); }
        .log-action.password_changed { color: var(--warning); background: rgba(245, 158, 11, 0.1); }
        
        /* Config actions */
        .log-action.config_edit { color: #3b82f6; background: rgba(59, 130, 246, 0.1); }
        .log-action.config_restore { color: #8b5cf6; background: rgba(139, 92, 246, 0.1); }
        .log-action.secret_view { color: #f59e0b; background: rgba(245, 158, 11, 0.1); }
        .log-action.secret_edit { color: #f59e0b; background: rgba(245, 158, 11, 0.1); }
        
        /* Command actions */
        .log-action.command_execute { color: #10b981; background: rgba(16, 185, 129, 0.1); }
        .log-action.command_create { color: var(--accent-primary); background: var(--accent-glow); }
        .log-action.command_update { color: #3b82f6; background: rgba(59, 130, 246, 0.1); }
        .log-action.command_delete { color: var(--error); background: rgba(239, 68, 68, 0.1); }
        
        /* Playwright actions */
        .log-action.project_create { color: #a855f7; background: rgba(168, 85, 247, 0.1); }
        .log-action.project_update { color: #a855f7; background: rgba(168, 85, 247, 0.1); }
        .log-action.project_delete { color: var(--error); background: rgba(239, 68, 68, 0.1); }
        .log-action.test_run { color: #22c55e; background: rgba(34, 197, 94, 0.1); }

        .log-details {
            font-size: 13px;
            color: var(--text-muted);
            max-width: 300px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .table-wrapper {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .table {
            width: 100%;
            min-width: 980px;
        }

        .log-details-cell {
            max-width: 360px;
        }

        .log-details-text {
            font-size: 13px;
            color: var(--text-muted);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .details-toggle {
            margin-top: 6px;
            padding: 4px 8px;
            font-size: 12px;
            border-radius: 6px;
            border: 1px solid var(--border-color);
            background: var(--bg-tertiary);
            color: var(--text-secondary);
            cursor: pointer;
        }

        .details-row {
            display: none;
        }

        .details-row.active {
            display: table-row;
        }

        .details-content {
            padding: 12px 16px;
            font-size: 13px;
            color: var(--text-secondary);
            background: var(--bg-tertiary);
            border-top: 1px solid var(--border-color);
            white-space: pre-wrap;
            word-break: break-word;
        }

        .log-time {
            font-size: 13px;
            color: var(--text-muted);
            white-space: nowrap;
        }

        .log-ip {
            font-family: 'JetBrains Mono', monospace;
            font-size: 12px;
            color: var(--text-muted);
        }

        .pagination {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin-top: 24px;
        }

        .pagination a, .pagination span {
            padding: 8px 16px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 14px;
        }

        .pagination a {
            background: var(--bg-tertiary);
            color: var(--text-primary);
            border: 1px solid var(--border-primary);
        }

        .pagination a:hover {
            background: var(--bg-hover);
        }

        .pagination .current {
            background: var(--accent-primary);
            color: var(--bg-primary);
        }

        .pagination .disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .empty-state {
            text-align: center;
            padding: 48px;
            color: var(--text-secondary);
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.5;
        }
    </style>
</head>
<body>
    <div class="app-container">
        <?php $layout->renderSidebar(); ?>

        <main class="main-content">
            <div class="page-header">
                <div>
                    <h1 class="page-title">Audit Log</h1>
                    <p class="page-subtitle">Track all system actions and changes</p>
                </div>
                <div>
                    <a href="history.php" class="btn btn-secondary">
                        <i class="fas fa-history"></i>
                        Config History
                    </a>
                </div>
            </div>

            <!-- Filters -->
            <form method="GET" class="audit-filters">
                <div class="filter-group">
                    <label>Action</label>
                    <select name="action_filter">
                        <option value="">All Actions</option>
                        <?php foreach ($allActions as $action): ?>
                        <option value="<?= htmlspecialchars($action) ?>" <?= $filterAction === $action ? 'selected' : '' ?>>
                            <?= htmlspecialchars($action) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label>User</label>
                    <select name="user_filter">
                        <option value="">All Users</option>
                        <?php foreach ($allUsers as $username): ?>
                        <option value="<?= htmlspecialchars($username) ?>" <?= $filterUser === $username ? 'selected' : '' ?>>
                            <?= htmlspecialchars($username) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label>Resource Type</label>
                    <select name="resource_filter">
                        <option value="">All Resources</option>
                        <?php foreach ($allResources as $resource): ?>
                        <option value="<?= htmlspecialchars($resource) ?>" <?= $filterResource === $resource ? 'selected' : '' ?>>
                            <?= htmlspecialchars($resource) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label>From Date</label>
                    <input type="date" name="date_from" value="<?= htmlspecialchars($filterDateFrom) ?>">
                </div>
                
                <div class="filter-group">
                    <label>To Date</label>
                    <input type="date" name="date_to" value="<?= htmlspecialchars($filterDateTo) ?>">
                </div>
                
                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i>
                        Filter
                    </button>
                    <a href="../api/audit.php?<?= $exportCsvQuery ?>" class="btn btn-secondary">
                        <i class="fas fa-file-csv"></i>
                        Export CSV
                    </a>
                    <a href="../api/audit.php?<?= $exportJsonQuery ?>" class="btn btn-secondary">
                        <i class="fas fa-file-code"></i>
                        Export JSON
                    </a>
                    <?php if ($baseQuery): ?>
                    <a href="audit.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i>
                        Clear
                    </a>
                    <?php endif; ?>
                </div>
            </form>

            <!-- Active Filters -->
            <?php if ($baseQuery): ?>
            <div class="active-filters">
                <?php if ($filterAction): ?>
                <span class="filter-tag">
                    Action: <?= htmlspecialchars($filterAction) ?>
                    <a href="?<?= http_build_query(array_diff_key($queryParams, ['action_filter' => 1])) ?>"><i class="fas fa-times"></i></a>
                </span>
                <?php endif; ?>
                <?php if ($filterUser): ?>
                <span class="filter-tag">
                    User: <?= htmlspecialchars($filterUser) ?>
                    <a href="?<?= http_build_query(array_diff_key($queryParams, ['user_filter' => 1])) ?>"><i class="fas fa-times"></i></a>
                </span>
                <?php endif; ?>
                <?php if ($filterResource): ?>
                <span class="filter-tag">
                    Resource: <?= htmlspecialchars($filterResource) ?>
                    <a href="?<?= http_build_query(array_diff_key($queryParams, ['resource_filter' => 1])) ?>"><i class="fas fa-times"></i></a>
                </span>
                <?php endif; ?>
                <?php if ($filterDateFrom || $filterDateTo): ?>
                <span class="filter-tag">
                    Date: <?= $filterDateFrom ?: '...' ?> → <?= $filterDateTo ?: '...' ?>
                    <a href="?<?= http_build_query(array_diff_key($queryParams, ['date_from' => 1, 'date_to' => 1])) ?>"><i class="fas fa-times"></i></a>
                </span>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Stats Bar -->
            <div class="stats-bar">
                <div class="stat-item">
                    <i class="fas fa-list"></i>
                    <span><strong><?= number_format($total) ?></strong> entries<?= $baseQuery ? ' (filtered)' : '' ?></span>
                </div>
                <div class="stat-item">
                    <i class="fas fa-file-alt"></i>
                    <span>Page <strong><?= $page ?></strong> of <strong><?= max(1, $totalPages) ?></strong></span>
                </div>
            </div>

            <div class="card">
                <?php if (empty($logs)): ?>
                <div class="empty-state">
                    <i class="fas fa-clock-rotate-left"></i>
                    <h3>No audit logs yet</h3>
                    <p>Actions will be recorded here as users interact with the system</p>
                </div>
                <?php else: ?>
                <div class="table-wrapper">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>User</th>
                            <th>Action</th>
                            <th>Resource</th>
                            <th>Details</th>
                            <th>IP Address</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                        <tr>
                            <td class="log-time"><?= htmlspecialchars(date('Y-m-d H:i:s', strtotime($log['created_at']))) ?></td>
                            <td><?= htmlspecialchars($log['username'] ?? 'System') ?></td>
                            <td><span class="log-action <?= htmlspecialchars($log['action']) ?>"><?= htmlspecialchars($log['action']) ?></span></td>
                            <td>
                                <?php if ($log['resource_type']): ?>
                                <span class="log-resource">
                                    <?= htmlspecialchars($log['resource_type']) ?>
                                    <?php if ($log['resource_id']): ?>
                                    <small style="color: var(--text-muted);">/ <?= htmlspecialchars($log['resource_id']) ?></small>
                                    <?php endif; ?>
                                </span>
                                <?php else: ?>
                                <span style="color: var(--text-muted);">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="log-details-cell">
                                <?php $detailsText = $log['details'] ?? ''; ?>
                                <?php if ($detailsText): ?>
                                <div class="log-details-text" title="<?= htmlspecialchars($detailsText) ?>">
                                    <?= htmlspecialchars($detailsText) ?>
                                </div>
                                <button type="button" class="details-toggle" onclick="toggleDetails('details-<?= (int)$log['id'] ?>', this)">
                                    View
                                </button>
                                <?php else: ?>
                                <span style="color: var(--text-muted);">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="log-ip"><?= htmlspecialchars($log['ip_address'] ?? '-') ?></td>
                        </tr>
                        <?php if (!empty($detailsText)): ?>
                        <tr class="details-row" id="details-<?= (int)$log['id'] ?>">
                            <td colspan="6">
                                <div class="details-content"><?= htmlspecialchars($detailsText) ?></div>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>

                <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php 
                    $pageLink = function($p) use ($baseQuery) {
                        return '?' . ($baseQuery ? $baseQuery . '&' : '') . 'page=' . $p;
                    };
                    ?>
                    <?php if ($page > 1): ?>
                    <a href="<?= $pageLink($page - 1) ?>"><i class="fas fa-chevron-left"></i></a>
                    <?php else: ?>
                    <span class="disabled"><i class="fas fa-chevron-left"></i></span>
                    <?php endif; ?>

                    <?php
                    $start = max(1, $page - 2);
                    $end = min($totalPages, $page + 2);
                    
                    if ($start > 1): ?>
                    <a href="<?= $pageLink(1) ?>">1</a>
                    <?php if ($start > 2): ?><span>...</span><?php endif; ?>
                    <?php endif; ?>

                    <?php for ($i = $start; $i <= $end; $i++): ?>
                    <?php if ($i == $page): ?>
                    <span class="current"><?= $i ?></span>
                    <?php else: ?>
                    <a href="<?= $pageLink($i) ?>"><?= $i ?></a>
                    <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($end < $totalPages): ?>
                    <?php if ($end < $totalPages - 1): ?><span>...</span><?php endif; ?>
                    <a href="<?= $pageLink($totalPages) ?>"><?= $totalPages ?></a>
                    <?php endif; ?>

                    <?php if ($page < $totalPages): ?>
                    <a href="<?= $pageLink($page + 1) ?>"><i class="fas fa-chevron-right"></i></a>
                    <?php else: ?>
                    <span class="disabled"><i class="fas fa-chevron-right"></i></span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>

            <script>
                function toggleDetails(rowId, btn) {
                    const row = document.getElementById(rowId);
                    if (!row) return;
                    const isOpen = row.classList.toggle('active');
                    if (btn) {
                        btn.textContent = isOpen ? 'Hide' : 'View';
                    }
                }
            </script>
        </main>
    </div>
</body>
</html>
