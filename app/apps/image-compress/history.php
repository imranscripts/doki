<?php
/**
 * Image Compressor - Compression History
 * 
 * Shows recent image compressions with download and management.
 */

require_once __DIR__ . '/../../includes/AppContext.php';

$app = new AppContext('image-compress');
$user = $app->requireAuth();
$layout = $app->getLayout();

// Load history
$history = [];
$content = $app->readFile('compressions.json');
if ($content) {
    $history = json_decode($content, true) ?: [];
}

// Calculate stats
$totalCompressions = count($history);
$totalOriginalSize = array_sum(array_column($history, 'size_original'));
$totalCompressedSize = array_sum(array_column($history, 'size_compressed'));
$totalSavings = $totalOriginalSize > 0 ? (1 - ($totalCompressedSize / $totalOriginalSize)) * 100 : 0;

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'clear') {
        // Clear all history and files
        $compressedDir = $app->getStoragePath() . '/compressed';
        $uploadsDir = $app->getStoragePath() . '/uploads';
        
        foreach ($history as $item) {
            @unlink($compressedDir . '/' . basename($item['compressed_file'] ?? ''));
            @unlink($uploadsDir . '/' . basename($item['original_file'] ?? ''));
        }
        
        $app->writeFile('compressions.json', '[]');
        $app->audit('history_cleared', null, 'Cleared all compression history');
        
        header('Location: history.php');
        exit;
        
    } elseif ($action === 'delete' && isset($_POST['compressed_file'])) {
        $compressedFile = basename($_POST['compressed_file']);
        $compressedPath = $app->getStoragePath() . '/compressed/' . $compressedFile;
        
        // Find and remove from history
        $originalFile = null;
        foreach ($history as $item) {
            if ($item['compressed_file'] === $compressedFile) {
                $originalFile = $item['original_file'] ?? null;
                break;
            }
        }
        
        // Delete files
        if (file_exists($compressedPath)) {
            @unlink($compressedPath);
        }
        if ($originalFile) {
            @unlink($app->getStoragePath() . '/uploads/' . basename($originalFile));
        }
        
        // Update history
        $history = array_filter($history, fn($item) => $item['compressed_file'] !== $compressedFile);
        $app->writeFile('compressions.json', json_encode(array_values($history), JSON_PRETTY_PRINT));
        $app->audit('image_deleted', $compressedFile, 'Deleted compressed image');
        
        header('Location: history.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $layout->renderHead('Image Compressor - History'); ?>
    <style>
        .history-container {
            max-width: 1000px;
        }

        /* Stats Cards */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 32px;
        }

        .stat-card {
            padding: 20px;
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 12px;
        }

        .stat-card-label {
            font-size: 13px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }

        .stat-card-value {
            font-size: 28px;
            font-weight: 700;
            color: var(--text-primary);
        }

        .stat-card-value.accent {
            color: #16a34a;
        }

        .stat-card-value.savings {
            color: #22c55e;
        }

        /* Header */
        .history-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 16px;
        }

        .history-title {
            font-size: 20px;
            font-weight: 600;
            color: var(--text-primary);
            margin: 0;
        }

        .header-actions {
            display: flex;
            gap: 12px;
        }

        .btn-secondary {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 16px;
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            color: var(--text-secondary);
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
        }

        .btn-secondary:hover {
            background: var(--bg-tertiary);
            border-color: var(--text-muted);
        }

        .btn-danger {
            color: #ef4444;
            border-color: rgba(239, 68, 68, 0.3);
        }

        .btn-danger:hover {
            background: rgba(239, 68, 68, 0.1);
            border-color: #ef4444;
        }

        /* History List */
        .history-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .history-item {
            display: grid;
            grid-template-columns: auto 1fr auto auto;
            gap: 20px;
            align-items: center;
            padding: 20px;
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            transition: all 0.2s;
        }

        .history-item:hover {
            border-color: var(--accent-primary);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .history-icon {
            width: 52px;
            height: 52px;
            border-radius: 10px;
            background: linear-gradient(135deg, #16a34a 0%, #15803d 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 22px;
            flex-shrink: 0;
        }

        .history-info {
            min-width: 0;
        }

        .history-filename {
            font-weight: 600;
            font-size: 15px;
            color: var(--text-primary);
            margin-bottom: 6px;
            word-break: break-word;
        }

        .history-meta {
            display: flex;
            gap: 16px;
            font-size: 13px;
            color: var(--text-secondary);
            flex-wrap: wrap;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .meta-item i {
            font-size: 11px;
            opacity: 0.7;
        }

        .savings-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 8px;
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid rgba(34, 197, 94, 0.3);
            border-radius: 6px;
            color: #22c55e;
            font-weight: 600;
            font-size: 12px;
        }

        .compression-level {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 8px;
            background: rgba(22, 163, 74, 0.1);
            border: 1px solid rgba(22, 163, 74, 0.3);
            border-radius: 6px;
            color: #16a34a;
            font-size: 12px;
        }

        .history-time {
            text-align: right;
            font-size: 13px;
            color: var(--text-muted);
            white-space: nowrap;
        }

        .history-time-date {
            font-weight: 500;
            color: var(--text-secondary);
        }

        .history-actions {
            display: flex;
            gap: 8px;
        }

        .action-btn {
            padding: 8px 14px;
            border: none;
            border-radius: 8px;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .action-btn-primary {
            background: linear-gradient(135deg, #16a34a 0%, #15803d 100%);
            color: white;
        }

        .action-btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(22, 163, 74, 0.3);
        }

        .action-btn-ghost {
            background: transparent;
            color: var(--text-secondary);
            border: 1px solid var(--border-color);
        }

        .action-btn-ghost:hover {
            background: var(--bg-tertiary);
        }

        .action-btn-delete {
            background: transparent;
            color: var(--text-muted);
            border: 1px solid transparent;
            padding: 8px 10px;
        }

        .action-btn-delete:hover {
            background: rgba(239, 68, 68, 0.1);
            color: #ef4444;
        }

        .action-btn-small {
            padding: 6px 10px;
            font-size: 12px;
            border-radius: 6px;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 80px 20px;
            color: var(--text-muted);
        }

        .empty-icon {
            font-size: 64px;
            margin-bottom: 24px;
            opacity: 0.3;
        }

        .empty-title {
            font-size: 20px;
            font-weight: 600;
            color: var(--text-secondary);
            margin: 0 0 8px 0;
        }

        .empty-text {
            margin-bottom: 24px;
        }

        .btn-primary {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            background: linear-gradient(135deg, #16a34a 0%, #15803d 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(22, 163, 74, 0.4);
        }

        /* Preview Modal */
        .modal-backdrop {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal-backdrop.show {
            display: flex;
        }

        .modal-content {
            background: var(--bg-primary);
            border-radius: 16px;
            width: 100%;
            max-width: 900px;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 20px;
            border-bottom: 1px solid var(--border-color);
        }

        .modal-title {
            font-weight: 600;
            font-size: 16px;
            color: var(--text-primary);
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 20px;
            color: var(--text-muted);
            cursor: pointer;
            padding: 4px 8px;
        }

        .modal-close:hover {
            color: var(--text-primary);
        }

        .modal-body {
            flex: 1;
            overflow: auto;
            padding: 20px;
            text-align: center;
        }

        .modal-compare {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }

        .compare-col {
            text-align: center;
        }

        .compare-label {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-secondary);
            margin-bottom: 6px;
        }

        .compare-meta {
            font-size: 12px;
            color: var(--text-muted);
            margin-bottom: 10px;
        }

        .compare-col img {
            max-width: 100%;
            max-height: 70vh;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }

        .compare-col.missing img { display: none; }
        .compare-col.missing .compare-meta { color: #ef4444; }

        /* Responsive */
        @media (max-width: 768px) {
            .history-item {
                grid-template-columns: auto 1fr;
                grid-template-rows: auto auto;
            }

            .history-time {
                grid-column: 2;
                text-align: left;
            }

            .history-actions {
                grid-column: 1 / -1;
                justify-content: flex-start;
            }

            .modal-compare {
                grid-template-columns: 1fr;
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

                <div class="history-container">
                    <?php if (!empty($history)): ?>
                    <!-- Stats -->
                    <div class="stats-row">
                        <div class="stat-card">
                            <div class="stat-card-label">Total Compressions</div>
                            <div class="stat-card-value accent"><?= number_format($totalCompressions) ?></div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-card-label">Original Size</div>
                            <div class="stat-card-value"><?= $app->formatSize($totalOriginalSize) ?></div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-card-label">Compressed Size</div>
                            <div class="stat-card-value"><?= $app->formatSize($totalCompressedSize) ?></div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-card-label">Total Savings</div>
                            <div class="stat-card-value savings"><?= number_format($totalSavings, 1) ?>%</div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Header -->
                    <div class="history-header">
                        <h2 class="history-title">Compression History</h2>
                        <?php if (!empty($history)): ?>
                        <div class="header-actions">
                            <a href="index.php" class="btn-secondary">
                                <i class="fas fa-plus"></i>
                                New Compression
                            </a>
                            <form method="POST" style="margin: 0;">
                                <input type="hidden" name="action" value="clear">
                                <button type="submit" class="btn-secondary btn-danger" 
                                        onclick="return confirm('Delete all history and compressed images? This cannot be undone.')">
                                    <i class="fas fa-trash"></i>
                                    Clear All
                                </button>
                            </form>
                        </div>
                        <?php endif; ?>
                    </div>

                    <?php if (empty($history)): ?>
                    <!-- Empty State -->
                    <div class="empty-state">
                        <div class="empty-icon">
                            <i class="fas fa-history"></i>
                        </div>
                        <h3 class="empty-title">No compressions yet</h3>
                        <p class="empty-text">Compress your first image to see your history here</p>
                        <a href="index.php" class="btn-primary">
                            <i class="fas fa-image"></i>
                            Compress an Image
                        </a>
                    </div>
                    <?php else: ?>
                    <!-- History List -->
                    <div class="history-list">
                        <?php foreach ($history as $item): ?>
                        <?php
                            $compressedExists = file_exists($app->getStoragePath() . '/compressed/' . ($item['compressed_file'] ?? ''));
                            $originalFile = $item['original_file'] ?? '';
                            $originalExists = $originalFile && file_exists($app->getStoragePath() . '/uploads/' . $originalFile);
                            $timestamp = strtotime($item['timestamp'] ?? 'now');
                            $savings = $item['savings_percent'] ?? 0;
                            $compressionLevel = $item['compression_level'] ?? 0;
                        ?>
                        <div class="history-item">
                            <div class="history-icon">
                                <i class="fas fa-compress-alt"></i>
                            </div>
                            <div class="history-info">
                                <div class="history-filename">
                                    <?= htmlspecialchars($item['original_name'] ?? 'Unknown') ?>
                                </div>
                                <div class="history-meta">
                                    <div class="meta-item">
                                        <i class="fas fa-file-image"></i>
                                        <span><?= $app->formatSize($item['size_original'] ?? 0) ?></span>
                                    </div>
                                    <div class="meta-item">
                                        <i class="fas fa-arrow-right"></i>
                                    </div>
                                    <div class="meta-item">
                                        <i class="fas fa-file-image"></i>
                                        <span><?= $app->formatSize($item['size_compressed'] ?? 0) ?></span>
                                    </div>
                                    <?php if ($savings > 0): ?>
                                    <div class="meta-item">
                                        <span class="savings-badge">
                                            <i class="fas fa-arrow-down"></i>
                                            <?= number_format($savings, 1) ?>% saved
                                        </span>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($compressionLevel > 0): ?>
                                    <div class="meta-item">
                                        <span class="compression-level">
                                            <i class="fas fa-sliders-h"></i>
                                            Level <?= $compressionLevel ?>%
                                        </span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="history-time">
                                <div class="history-time-date"><?= date('M j, Y', $timestamp) ?></div>
                                <div><?= date('g:i A', $timestamp) ?></div>
                            </div>
                            <div class="history-actions">
                                <?php if ($compressedExists || $originalExists): ?>
                                <button type="button" class="action-btn action-btn-ghost" 
                                        onclick="previewImages('<?= htmlspecialchars($item['compressed_file'] ?? '') ?>', '<?= htmlspecialchars($originalFile) ?>', '<?= htmlspecialchars($item['original_name'] ?? '') ?>', '<?= htmlspecialchars($app->formatSize($item['size_original'] ?? 0)) ?>', '<?= htmlspecialchars($app->formatSize($item['size_compressed'] ?? 0)) ?>')"
                                        title="Preview Before/After">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <?php endif; ?>
                                <?php if ($originalExists): ?>
                                <a href="download.php?type=original&file=<?= urlencode($originalFile) ?>" 
                                   class="action-btn action-btn-ghost action-btn-small"
                                   download
                                   title="Download Original">
                                    <i class="fas fa-file-arrow-down"></i>
                                    Original
                                </a>
                                <?php endif; ?>
                                <?php if ($compressedExists): ?>
                                <a href="download.php?file=<?= urlencode($item['compressed_file']) ?>" 
                                   class="action-btn action-btn-primary"
                                   download
                                   title="Download Image">
                                    <i class="fas fa-download"></i>
                                    Download
                                </a>
                                <?php elseif (!$compressedExists): ?>
                                <span class="action-btn action-btn-ghost" style="opacity: 0.5; cursor: default;">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    File missing
                                </span>
                                <?php endif; ?>
                                <form method="POST" style="margin: 0;">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="compressed_file" value="<?= htmlspecialchars($item['compressed_file'] ?? '') ?>">
                                    <button type="submit" class="action-btn action-btn-delete" 
                                            onclick="return confirm('Delete this compression?')"
                                            title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- Preview Modal -->
    <div class="modal-backdrop" id="previewModal">
        <div class="modal-content">
            <div class="modal-header">
                <span class="modal-title" id="previewTitle">Before / After</span>
                <button type="button" class="modal-close" onclick="closePreview()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="modal-compare">
                    <div class="compare-col" id="compareOriginal">
                        <div class="compare-label">Before</div>
                        <div class="compare-meta" id="previewOriginalMeta"></div>
                        <img id="previewOriginal" src="" alt="Original">
                    </div>
                    <div class="compare-col" id="compareCompressed">
                        <div class="compare-label">After</div>
                        <div class="compare-meta" id="previewCompressedMeta"></div>
                        <img id="previewCompressed" src="" alt="Compressed">
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function previewImages(compressedFilename, originalFilename, originalName, originalSize, compressedSize) {
            const modal = document.getElementById('previewModal');
            const title = document.getElementById('previewTitle');
            const originalCol = document.getElementById('compareOriginal');
            const compressedCol = document.getElementById('compareCompressed');
            const originalImg = document.getElementById('previewOriginal');
            const compressedImg = document.getElementById('previewCompressed');
            const originalMeta = document.getElementById('previewOriginalMeta');
            const compressedMeta = document.getElementById('previewCompressedMeta');
            
            title.textContent = originalName ? `Before / After — ${originalName}` : 'Before / After';
            
            if (originalFilename) {
                originalCol.classList.remove('missing');
                originalMeta.textContent = originalSize ? `Original • ${originalSize}` : 'Original';
                originalImg.src = 'download.php?type=original&file=' + encodeURIComponent(originalFilename);
            } else {
                originalCol.classList.add('missing');
                originalMeta.textContent = 'Original file missing';
                originalImg.src = '';
            }
            
            if (compressedFilename) {
                compressedCol.classList.remove('missing');
                compressedMeta.textContent = compressedSize ? `Compressed • ${compressedSize}` : 'Compressed';
                compressedImg.src = 'download.php?file=' + encodeURIComponent(compressedFilename);
            } else {
                compressedCol.classList.add('missing');
                compressedMeta.textContent = 'Compressed file missing';
                compressedImg.src = '';
            }

            modal.classList.add('show');
            
            // Close on backdrop click
            modal.onclick = function(e) {
                if (e.target === modal) closePreview();
            };
            
            // Close on Escape
            document.addEventListener('keydown', handleEscape);
        }

        function closePreview() {
            const modal = document.getElementById('previewModal');
            const originalImg = document.getElementById('previewOriginal');
            const compressedImg = document.getElementById('previewCompressed');
            
            modal.classList.remove('show');
            originalImg.src = '';
            compressedImg.src = '';
            document.removeEventListener('keydown', handleEscape);
        }

        function handleEscape(e) {
            if (e.key === 'Escape') closePreview();
        }
    </script>
</body>
</html>
