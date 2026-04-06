<?php
/**
 * admin/history.php - Configuration History & Backup Browser
 * 
 * Part of Doki v3 Architecture - Batch 9: Audit & History
 */

require_once __DIR__ . '/../includes/middleware.php';
require_once __DIR__ . '/../includes/Layout.php';
require_once __DIR__ . '/../includes/ConfigManager.php';

// Require admin role
$user = requireRole('admin');
requireModuleAccess('config-history');
$isSuper = $user['role'] === 'super-admin';

// Create layout instance
$layout = new Layout($user, 'config-history');
$configManager = new ConfigManager();

// Handle restore action
$message = null;
$messageType = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'restore' && $isSuper) {
        $type = $_POST['type'] ?? '';
        $filename = $_POST['filename'] ?? '';
        
        if ($type && $filename) {
            $result = $configManager->restoreBackup($type, $filename, $user['id'], $user['username']);
            if ($result['success']) {
                $message = "Successfully restored {$type} from backup";
                $messageType = 'success';
            } else {
                $message = $result['error'] ?? 'Failed to restore backup';
                $messageType = 'error';
            }
        }
    }
}

// Get config types and their backups
$configTypes = ['targets', 'environments', 'secrets', 'commands'];
$allBackups = [];

foreach ($configTypes as $type) {
    $backups = $configManager->getBackups($type);
    $current = $configManager->load($type);
    $allBackups[$type] = [
        'backups' => $backups,
        'current' => $current,
        'currentSize' => strlen(yaml_emit($current ?: []))
    ];
}

// Selected backup for viewing
$viewType = $_GET['type'] ?? null;
$viewFile = $_GET['file'] ?? null;
$viewContent = null;
$currentContent = null;

if ($viewType && $viewFile) {
    $historyDir = __DIR__ . '/../data/config/.history';
    $backupPath = $historyDir . '/' . basename($viewFile);
    if (file_exists($backupPath)) {
        $viewContent = file_get_contents($backupPath);
    }
    $currentPath = __DIR__ . '/../data/config/' . $viewType . '.yaml';
    if (file_exists($currentPath)) {
        $currentContent = file_get_contents($currentPath);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $layout->renderHead('Configuration History'); ?>
    <style>
        .history-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 32px;
        }

        .config-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            overflow: hidden;
        }

        .config-card-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .config-card-title {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .config-card-title i {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
        }

        .config-card-title.targets i { background: rgba(59, 130, 246, 0.1); color: #3b82f6; }
        .config-card-title.environments i { background: rgba(34, 197, 94, 0.1); color: #22c55e; }
        .config-card-title.secrets i { background: rgba(245, 158, 11, 0.1); color: #f59e0b; }
        .config-card-title.commands i { background: rgba(168, 85, 247, 0.1); color: #a855f7; }

        .config-card-title h3 {
            margin: 0;
            font-size: 16px;
            text-transform: capitalize;
        }

        .backup-count {
            background: var(--bg-tertiary);
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            color: var(--text-secondary);
        }

        .config-card-body {
            padding: 12px 20px;
            max-height: 280px;
            overflow-y: auto;
        }

        .backup-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid var(--border-color);
        }

        .backup-item:last-child {
            border-bottom: none;
        }

        .backup-info {
            flex: 1;
        }

        .backup-date {
            font-size: 13px;
            color: var(--text-primary);
        }

        .backup-meta {
            font-size: 11px;
            color: var(--text-muted);
        }

        .backup-actions {
            display: flex;
            gap: 8px;
        }

        .backup-actions .btn {
            padding: 4px 10px;
            font-size: 12px;
        }

        .no-backups {
            text-align: center;
            padding: 24px;
            color: var(--text-muted);
        }

        /* Diff Viewer Modal */
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
            max-width: 1200px;
            width: 95%;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
        }

        .modal-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
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
            overflow-y: auto;
            flex: 1;
        }

        .modal-footer {
            padding: 16px 20px;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }

        .diff-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .diff-panel {
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            overflow: hidden;
        }

        .diff-panel-header {
            padding: 12px 16px;
            background: var(--bg-tertiary);
            border-bottom: 1px solid var(--border-color);
            font-size: 13px;
            font-weight: 600;
        }

        .diff-panel-header.backup {
            color: #f59e0b;
        }

        .diff-panel-header.current {
            color: #22c55e;
        }

        .diff-content {
            padding: 16px;
            font-family: 'JetBrains Mono', monospace;
            font-size: 12px;
            line-height: 1.5;
            white-space: pre-wrap;
            word-break: break-all;
            max-height: 500px;
            overflow-y: auto;
        }

        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .alert.success {
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid rgba(34, 197, 94, 0.3);
            color: #22c55e;
        }

        .alert.error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #ef4444;
        }

        .empty-state {
            text-align: center;
            padding: 48px;
            color: var(--text-secondary);
        }

        .empty-state > i {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.5;
        }

        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
        }

        @media (max-width: 768px) {
            .diff-container {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <?php $layout->renderSidebar(); ?>

        <main class="main-content">
            <div class="page-header">
                <div>
                    <h1 class="page-title">Configuration History</h1>
                    <p class="page-subtitle">Browse and restore previous configurations</p>
                </div>
                <a href="audit.php" class="btn btn-secondary">
                    <i class="fas fa-list"></i>
                    Audit Log
                </a>
            </div>

            <?php if ($message): ?>
            <div class="alert <?= $messageType ?>">
                <i class="fas fa-<?= $messageType === 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
                <?= htmlspecialchars($message) ?>
            </div>
            <?php endif; ?>

            <div class="history-grid">
                <?php foreach ($allBackups as $type => $data): ?>
                <div class="config-card">
                    <div class="config-card-header">
                        <div class="config-card-title <?= $type ?>">
                            <i class="fas fa-<?= 
                                $type === 'targets' ? 'server' : 
                                ($type === 'environments' ? 'layer-group' : 
                                ($type === 'secrets' ? 'key' : 'terminal')) 
                            ?>"></i>
                            <h3><?= ucfirst($type) ?></h3>
                        </div>
                        <span class="backup-count"><?= count($data['backups']) ?> backups</span>
                    </div>
                    <div class="config-card-body">
                        <?php if (empty($data['backups'])): ?>
                        <div class="no-backups">
                            <i class="fas fa-inbox"></i>
                            <p>No backups yet</p>
                        </div>
                        <?php else: ?>
                        <?php foreach ($data['backups'] as $backup): ?>
                        <div class="backup-item">
                            <div class="backup-info">
                                <div class="backup-date"><?= htmlspecialchars($backup['date']) ?></div>
                                <div class="backup-meta"><?= number_format($backup['size']) ?> bytes</div>
                            </div>
                            <div class="backup-actions">
                                <button class="btn btn-secondary" onclick="viewDiff('<?= $type ?>', '<?= htmlspecialchars($backup['filename']) ?>')">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <?php if ($isSuper): ?>
                                <button class="btn btn-warning" onclick="confirmRestore('<?= $type ?>', '<?= htmlspecialchars($backup['filename']) ?>', '<?= htmlspecialchars($backup['date']) ?>')">
                                    <i class="fas fa-undo"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </main>
    </div>

    <!-- Diff Viewer Modal -->
    <div class="modal-backdrop" id="diffModal">
        <div class="modal">
            <div class="modal-header">
                <h2><i class="fas fa-code-compare"></i> Compare Versions</h2>
                <button class="modal-close" onclick="closeDiffModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <div class="diff-container">
                    <div class="diff-panel">
                        <div class="diff-panel-header backup">
                            <i class="fas fa-history"></i> Backup Version
                            <span id="backupDate"></span>
                        </div>
                        <div class="diff-content" id="backupContent">Loading...</div>
                    </div>
                    <div class="diff-panel">
                        <div class="diff-panel-header current">
                            <i class="fas fa-file"></i> Current Version
                        </div>
                        <div class="diff-content" id="currentContent">Loading...</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeDiffModal()">Close</button>
                <?php if ($isSuper): ?>
                <button class="btn btn-warning" id="restoreFromDiff" onclick="restoreFromDiff()">
                    <i class="fas fa-undo"></i>
                    Restore This Backup
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Restore Confirmation Modal -->
    <div class="modal-backdrop" id="restoreModal">
        <div class="modal" style="max-width: 500px;">
            <div class="modal-header">
                <h2 style="color: var(--warning);"><i class="fas fa-exclamation-triangle"></i> Confirm Restore</h2>
                <button class="modal-close" onclick="closeRestoreModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to restore <strong id="restoreType"></strong> configuration from <strong id="restoreDate"></strong>?</p>
                <p style="color: var(--text-muted); font-size: 13px;">
                    <i class="fas fa-info-circle"></i>
                    A backup of the current configuration will be created before restoring.
                </p>
            </div>
            <div class="modal-footer">
                <form method="POST" id="restoreForm">
                    <input type="hidden" name="action" value="restore">
                    <input type="hidden" name="type" id="restoreTypeInput">
                    <input type="hidden" name="filename" id="restoreFilenameInput">
                    <button type="button" class="btn btn-secondary" onclick="closeRestoreModal()">Cancel</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-undo"></i>
                        Restore
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
        let currentDiffType = null;
        let currentDiffFile = null;

        async function viewDiff(type, filename) {
            currentDiffType = type;
            currentDiffFile = filename;
            
            document.getElementById('diffModal').classList.add('active');
            document.getElementById('backupContent').textContent = 'Loading...';
            document.getElementById('currentContent').textContent = 'Loading...';

            try {
                // Fetch backup content
                const response = await fetch(`../api/config.php?action=backup-content&type=${type}&filename=${encodeURIComponent(filename)}`);
                const data = await response.json();
                
                if (data.success) {
                    document.getElementById('backupContent').textContent = data.backupContent || '(empty)';
                    document.getElementById('currentContent').textContent = data.currentContent || '(empty)';
                    document.getElementById('backupDate').textContent = ` (${data.backupDate})`;
                } else {
                    document.getElementById('backupContent').textContent = 'Error: ' + (data.error || 'Failed to load');
                    document.getElementById('currentContent').textContent = 'Error: ' + (data.error || 'Failed to load');
                }
            } catch (error) {
                console.error('Failed to load diff:', error);
                document.getElementById('backupContent').textContent = 'Error loading content';
                document.getElementById('currentContent').textContent = 'Error loading content';
            }
        }

        function closeDiffModal() {
            document.getElementById('diffModal').classList.remove('active');
        }

        function confirmRestore(type, filename, date) {
            document.getElementById('restoreType').textContent = type;
            document.getElementById('restoreDate').textContent = date;
            document.getElementById('restoreTypeInput').value = type;
            document.getElementById('restoreFilenameInput').value = filename;
            document.getElementById('restoreModal').classList.add('active');
        }

        function closeRestoreModal() {
            document.getElementById('restoreModal').classList.remove('active');
        }

        function restoreFromDiff() {
            if (currentDiffType && currentDiffFile) {
                // Get the backup date from the modal
                const dateSpan = document.getElementById('backupDate').textContent;
                confirmRestore(currentDiffType, currentDiffFile, dateSpan);
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

        // Close modals on Escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal-backdrop.active').forEach(m => m.classList.remove('active'));
            }
        });
    </script>
</body>
</html>
