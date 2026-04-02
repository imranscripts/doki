<?php
/**
 * Image Compressor
 * 
 * Compress images with adjustable quality settings.
 * Uses ImageMagick for high-quality compression.
 */

// Bootstrap checks if this app needs to run in custom FPM container
require_once __DIR__ . '/../../includes/app-bootstrap.php';
require_once __DIR__ . '/../../includes/AppContext.php';

$app = new AppContext('image-compress');
$user = $app->requireAuth();
$layout = $app->getLayout();

if (!$app->can(AppCapabilities::EXEC_DOCKER)) {
    die("This app requires Docker execution capability.");
}

$allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

function iniSizeToBytes(?string $value): int {
    if ($value === null || $value === '') {
        return 0;
    }
    $value = trim($value);
    $last = strtolower(substr($value, -1));
    $num = (int)$value;
    switch ($last) {
        case 'g':
            $num *= 1024;
            // no break
        case 'm':
            $num *= 1024;
            // no break
        case 'k':
            $num *= 1024;
            break;
    }
    return $num;
}

$maxPostBytes = iniSizeToBytes(ini_get('post_max_size'));
$maxUploadBytes = iniSizeToBytes(ini_get('upload_max_filesize'));
$maxRequestBytes = 0;
if ($maxPostBytes > 0 && $maxUploadBytes > 0) {
    $maxRequestBytes = min($maxPostBytes, $maxUploadBytes);
} elseif ($maxPostBytes > 0) {
    $maxRequestBytes = $maxPostBytes;
} elseif ($maxUploadBytes > 0) {
    $maxRequestBytes = $maxUploadBytes;
}

function compressImage($app, string $inputPath, string $outputPath, int $quality): array {
    $images = $app->getRequiredImages();
    if (empty($images)) {
        return ['success' => false, 'error' => 'No image processing container configured'];
    }
    
    $image = $images[0]['image'];
    
    // Ensure image exists
    $imageCheck = $app->checkImageExists($image);
    if (!$imageCheck['exists']) {
        $pullResult = $app->pullImage($image);
        if (!$pullResult['success']) {
            return ['success' => false, 'error' => 'Failed to pull image processor'];
        }
    }
    
    $inputDir = dirname($inputPath);
    $outputDir = dirname($outputPath);
    $inputFile = basename($inputPath);
    $outputFile = basename($outputPath);
    
    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0755, true);
    }
    
    // ImageMagick compression command
    $ext = strtolower(pathinfo($outputFile, PATHINFO_EXTENSION));
    
    // Build compression command based on format
    // Lower quality = more compression = smaller file
    if (in_array($ext, ['jpg', 'jpeg'])) {
        // JPEG: -quality directly controls compression, -sampling-factor reduces chroma
        $cmd = sprintf(
            'magick "/input/%s" -strip -sampling-factor 4:2:0 -quality %d "/output/%s" 2>&1',
            $inputFile,
            $quality,
            $outputFile
        );
    } elseif ($ext === 'png') {
        // PNG: Convert to indexed color for small files, or use high compression
        if ($quality < 50) {
            // Aggressive: convert to 256 color palette
            $cmd = sprintf(
                'magick "/input/%s" -strip -colors 256 -depth 8 "/output/%s" 2>&1',
                $inputFile,
                $outputFile
            );
        } else {
            // Standard: just strip metadata and compress
            $cmd = sprintf(
                'magick "/input/%s" -strip -define png:compression-level=9 "/output/%s" 2>&1',
                $inputFile,
                $outputFile
            );
        }
    } elseif ($ext === 'webp') {
        $cmd = sprintf(
            'magick "/input/%s" -strip -quality %d -define webp:method=6 "/output/%s" 2>&1',
            $inputFile,
            $quality,
            $outputFile
        );
    } else {
        $cmd = sprintf(
            'magick "/input/%s" -strip -quality %d "/output/%s" 2>&1',
            $inputFile,
            $quality,
            $outputFile
        );
    }
    
    $result = $app->executeInContainer($image, $cmd, [
        'volumes' => [
            $inputDir . ':/input:ro',
            $outputDir . ':/output:rw'
        ],
        'workdir' => '/tmp',
        'timeout' => 60,
        'network' => 'none'
    ]);
    
    if (!$result['success']) {
        return ['success' => false, 'error' => 'Compression failed: ' . ($result['output'] ?? 'unknown error')];
    }
    
    if (!file_exists($outputPath)) {
        return ['success' => false, 'error' => 'Output file not created'];
    }
    
    return ['success' => true, 'size' => filesize($outputPath)];
}

function saveToHistory($app, array $record): void {
    $content = $app->readFile('compressions.json');
    $history = $content ? json_decode($content, true) : [];
    array_unshift($history, $record);
    $app->writeFile('compressions.json', json_encode(array_slice($history, 0, 100), JSON_PRETTY_PRINT));
}

// Handle download all (ZIP) - Uses native ZipArchive (available via custom runtime)
if (isset($_GET['action']) && $_GET['action'] === 'download-all' && !empty($_GET['files'])) {
    $files = explode(',', $_GET['files']);
    $compressedDir = $app->getStoragePath() . '/compressed';

    // Build a fallback map from originals -> compressed (in case originals are passed)
    $fileMap = [];
    $historyContent = $app->readFile('compressions.json');
    if ($historyContent) {
        $history = json_decode($historyContent, true) ?: [];
        foreach ($history as $item) {
            $compressedFile = basename($item['compressed_file'] ?? '');
            if ($compressedFile === '') continue;
            $originalFile = basename($item['original_file'] ?? '');
            $originalName = basename($item['original_name'] ?? '');
            if ($originalFile !== '') {
                $fileMap[$originalFile] = $compressedFile;
            }
            if ($originalName !== '') {
                $fileMap[$originalName] = $compressedFile;
            }
        }
    }
    
    $zipName = 'compressed-images-' . date('Y-m-d-His') . '.zip';
    $zipPath = sys_get_temp_dir() . '/' . $zipName;
    
    // Validate files exist
    $validFiles = [];
    foreach ($files as $file) {
        $file = basename($file); // Security: prevent path traversal
        $target = $file;
        if (!file_exists($compressedDir . '/' . $target) && isset($fileMap[$file])) {
            $target = $fileMap[$file];
        }
        if (file_exists($compressedDir . '/' . $target)) {
            $validFiles[] = $target;
        }
    }
    $validFiles = array_values(array_unique($validFiles));
    
    if (empty($validFiles)) {
        http_response_code(404);
        die('No valid files found');
    }
    
    // Create ZIP using native ZipArchive (provided by custom runtime)
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        http_response_code(500);
        die('Failed to create ZIP file');
    }
    
    foreach ($validFiles as $file) {
        $zip->addFile($compressedDir . '/' . $file, $file);
    }
    $zip->close();
    
    $app->audit('images_downloaded_bulk', null, count($validFiles) . ' images downloaded as ZIP');
    
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . rawurlencode($zipName) . '"');
    header('Content-Length: ' . filesize($zipPath));
    readfile($zipPath);
    @unlink($zipPath);
    exit;
}

// Handle AJAX compression
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    
    if (empty($_FILES['images'])) {
        $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > 0) {
            $maxPostLabel = ini_get('post_max_size') ?: 'unknown';
            $maxUploadLabel = ini_get('upload_max_filesize') ?: 'unknown';
            echo json_encode([
                'success' => false,
                'error' => "Upload too large or blocked. Max request size is {$maxPostLabel}, max file size is {$maxUploadLabel}.",
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'No files uploaded']);
        }
        exit;
    }
    
    // Compression level (0-100): higher = more compression = smaller file = lower quality
    $compressionLevel = (int)($_POST['compression'] ?? 50);
    $compressionLevel = max(0, min(100, $compressionLevel));
    // Convert to ImageMagick quality (100 = best quality, 1 = worst)
    $quality = max(1, 100 - $compressionLevel);
    
    $files = $_FILES['images'];
    $results = [];
    $uploadsDir = $app->getStoragePath() . '/uploads';
    $compressedDir = $app->getStoragePath() . '/compressed';
    
    if (!is_dir($uploadsDir)) mkdir($uploadsDir, 0755, true);
    if (!is_dir($compressedDir)) mkdir($compressedDir, 0755, true);
    
    $fileCount = is_array($files['name']) ? count($files['name']) : 1;
    
    for ($i = 0; $i < $fileCount; $i++) {
        $name = is_array($files['name']) ? $files['name'][$i] : $files['name'];
        $tmpName = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
        $error = is_array($files['error']) ? $files['error'][$i] : $files['error'];
        $size = is_array($files['size']) ? $files['size'][$i] : $files['size'];
        
        if ($error !== UPLOAD_ERR_OK) {
            $results[] = ['name' => $name, 'success' => false, 'error' => 'Upload failed'];
            continue;
        }
        
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExtensions)) {
            $results[] = ['name' => $name, 'success' => false, 'error' => 'Unsupported format'];
            continue;
        }
        
        $timestamp = date('Y-m-d_H-i-s');
        $safeName = substr(preg_replace('/[^a-zA-Z0-9._-]/', '_', pathinfo($name, PATHINFO_FILENAME)), 0, 80);
        $uploadPath = "{$uploadsDir}/{$safeName}_{$timestamp}.{$ext}";
        $compressedPath = "{$compressedDir}/{$safeName}_{$timestamp}_c{$compressionLevel}.{$ext}";
        
        if (!move_uploaded_file($tmpName, $uploadPath)) {
            $results[] = ['name' => $name, 'success' => false, 'error' => 'Failed to save file'];
            continue;
        }
        
        $result = compressImage($app, $uploadPath, $compressedPath, $quality);
        
        if ($result['success']) {
            $compressedSize = filesize($compressedPath);
            $savings = (1 - ($compressedSize / $size)) * 100;
            
            saveToHistory($app, [
                'original_name' => $name,
                'original_file' => basename($uploadPath),
                'compressed_file' => basename($compressedPath),
                'timestamp' => date('c'),
                'compression_level' => $compressionLevel,
                'size_original' => $size,
                'size_compressed' => $compressedSize,
                'savings_percent' => round($savings, 1),
            ]);
            
            $app->audit('image_compressed', $name, "Compressed at level {$compressionLevel}%");
            
            $results[] = [
                'name' => $name,
                'success' => true,
                'compressed' => basename($compressedPath),
                'download_url' => 'download.php?file=' . urlencode(basename($compressedPath)),
                'size_original' => $app->formatSize($size),
                'size_compressed' => $app->formatSize($compressedSize),
                'savings' => round($savings, 1),
            ];
        } else {
            @unlink($uploadPath);
            $results[] = ['name' => $name, 'success' => false, 'error' => $result['error']];
        }
    }
    
    $successCount = count(array_filter($results, fn($r) => $r['success']));
    echo json_encode([
        'success' => $successCount > 0,
        'message' => "{$successCount} of {$fileCount} image(s) compressed",
        'results' => $results,
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $layout->renderHead('Image Compressor'); ?>
    <style>
        .converter { max-width: 900px; margin: 0 auto; }
        
        .top-row {
            display: flex; gap: 16px; align-items: stretch; margin-bottom: 16px;
        }
        .top-row .info-card { flex: 1 1 auto; margin-bottom: 0; }
        .top-row .quality-control { flex: 0 0 25%; margin-bottom: 0; }
        .info-card {
            padding: 16px 18px; background: linear-gradient(135deg, rgba(14, 165, 233, 0.08), rgba(20, 184, 166, 0.05));
            border: 1px solid rgba(14, 165, 233, 0.2); border-radius: 12px; margin-bottom: 16px;
        }
        .info-card-title { font-size: 15px; font-weight: 600; color: #0ea5e9; margin: 0 0 10px 0; display: flex; align-items: center; gap: 8px; }
        .info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; margin-top: 10px; }
        .info-item { display: flex; align-items: start; gap: 8px; }
        .info-icon { font-size: 16px; color: #0ea5e9; margin-top: 2px; }
        .info-text { font-size: 13px; color: var(--text-secondary); line-height: 1.4; }
        
        .quality-control {
            padding: 14px 16px; background: linear-gradient(180deg, rgba(15, 23, 42, 0.02), rgba(15, 23, 42, 0));
            border-radius: 12px; border: 1px solid var(--border-color); margin-bottom: 22px;
        }
        .quality-header { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 8px; }
        .quality-label-group { display: flex; flex-direction: column; gap: 2px; }
        .quality-label { font-weight: 600; font-size: 14px; color: #0f172a; letter-spacing: 0.2px; }
        .quality-sub { font-size: 11px; color: var(--text-muted); }
        .quality-value {
            display: inline-flex; align-items: center; gap: 8px; padding: 4px 10px;
            background: rgba(14, 165, 233, 0.12); color: #0369a1;
            border-radius: 999px; font-weight: 700; font-size: 12px;
            border: 1px solid rgba(14, 165, 233, 0.25);
        }
        .quality-slider {
            width: 100%; height: 6px; border-radius: 999px;
            background: linear-gradient(to right, #38bdf8 0%, #14b8a6 100%);
            outline: none; appearance: none;
        }
        .quality-slider::-webkit-slider-thumb {
            appearance: none; width: 18px; height: 18px; border-radius: 50%;
            background: white; border: 2px solid #0ea5e9; cursor: pointer;
            box-shadow: 0 2px 8px rgba(14, 165, 233, 0.25);
        }
        .quality-slider::-moz-range-thumb {
            width: 18px; height: 18px; border-radius: 50%;
            background: white; border: 2px solid #0ea5e9; cursor: pointer;
            box-shadow: 0 2px 8px rgba(14, 165, 233, 0.25);
        }
        .quality-hints {
            display: flex; justify-content: space-between; margin-top: 6px;
            font-size: 11px; color: var(--text-muted);
        }
        @media (max-width: 720px) {
            .top-row { flex-direction: column; }
            .top-row .quality-control { flex: 1 1 auto; }
            .quality-header { flex-direction: column; align-items: flex-start; }
        }
        
        .upload-box { border: 2px dashed var(--border-color); border-radius: 16px; padding: 48px 32px; text-align: center; background: var(--bg-secondary); transition: all 0.3s; cursor: pointer; }
        .upload-box:hover { border-color: var(--accent-primary); background: var(--bg-tertiary); }
        .upload-box.dragover { border-color: #16a34a; background: rgba(22, 163, 74, 0.05); border-style: solid; }
        .upload-icon { font-size: 48px; color: #16a34a; margin-bottom: 16px; }
        .upload-text { font-size: 18px; font-weight: 500; margin-bottom: 8px; }
        .upload-hint { font-size: 14px; color: var(--text-muted); }
        .file-input { display: none; }
        
        .file-list { margin: 20px 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 16px; }
        .file-card {
            position: relative; background: var(--bg-secondary); border: 1px solid var(--border-color);
            border-radius: 12px; padding: 12px; transition: all 0.2s;
        }
        .file-card:hover { border-color: var(--accent-primary); box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .file-preview {
            width: 100%; height: 140px; border-radius: 8px; background: var(--bg-tertiary);
            display: flex; align-items: center; justify-content: center; margin-bottom: 12px;
            overflow: hidden;
        }
        .file-preview img { width: 100%; height: 100%; object-fit: cover; }
        .file-preview i { font-size: 48px; color: var(--text-muted); }
        .file-name { font-size: 13px; font-weight: 500; margin-bottom: 6px; word-break: break-word; }
        .file-size { font-size: 12px; color: var(--text-muted); margin-bottom: 8px; }
        .file-savings {
            display: inline-block; padding: 4px 8px; background: rgba(22, 163, 74, 0.1);
            border-radius: 4px; font-size: 11px; font-weight: 600; color: #16a34a;
        }
        .file-status {
            position: absolute; top: 16px; right: 16px; width: 24px; height: 24px;
            border-radius: 50%; background: white; display: flex; align-items: center;
            justify-content: center; font-size: 14px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .file-status.success { color: #22c55e; }
        .file-status.error { color: #ef4444; }
        .file-status.pending { color: var(--text-muted); }
        .file-actions {
            display: flex; gap: 6px; margin-top: 10px;
        }
        .file-actions .btn-sm {
            flex: 1; padding: 6px; font-size: 12px; border-radius: 6px;
            border: none; cursor: pointer; text-decoration: none; text-align: center;
        }
        .btn-compare {
            flex: 0 0 auto; width: 32px; padding: 6px;
            background: rgba(14, 165, 233, 0.12); color: #0369a1;
            border: 1px solid rgba(14, 165, 233, 0.25);
        }
        .btn-compare:hover { background: rgba(14, 165, 233, 0.2); }
        .btn-download { background: #16a34a; color: white; }
        .btn-download:hover { background: #15803d; }
        .btn-remove { background: var(--bg-tertiary); color: var(--text-secondary); }
        .btn-remove:hover { background: rgba(239, 68, 68, 0.1); color: #ef4444; }
        
        .actions { text-align: center; margin: 24px 0; }
        .btn-compress {
            display: inline-flex; align-items: center; gap: 10px; padding: 14px 40px;
            font-size: 16px; font-weight: 600; color: white;
            background: linear-gradient(135deg, #16a34a, #15803d);
            border: none; border-radius: 10px; cursor: pointer; transition: all 0.2s;
        }
        .btn-compress:hover:not(:disabled) { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(22, 163, 74, 0.4); }
        .btn-compress:disabled { opacity: 0.5; cursor: not-allowed; }
        .btn-clear {
            display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px;
            font-size: 14px; color: var(--text-secondary); background: var(--bg-secondary);
            border: 1px solid var(--border-color); border-radius: 8px; cursor: pointer; margin-left: 12px;
        }
        .btn-clear:hover { background: var(--bg-tertiary); }
        
        .progress-overlay {
            display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6);
            z-index: 1000; align-items: center; justify-content: center;
        }
        .progress-overlay.show { display: flex; }
        .progress-card {
            background: var(--bg-primary); padding: 40px; border-radius: 16px;
            text-align: center; min-width: 300px;
        }
        .spinner {
            width: 48px; height: 48px; border: 4px solid var(--border-color);
            border-top-color: #16a34a; border-radius: 50%;
            animation: spin 1s linear infinite; margin: 0 auto 16px;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        
        .formats {
            margin-top: 24px; padding: 16px; background: var(--bg-secondary);
            border-radius: 10px; text-align: center; font-size: 13px; color: var(--text-muted);
        }

        /* Preview Modal */
        .modal-backdrop {
            display: none; position: fixed; inset: 0; background: rgba(0, 0, 0, 0.7);
            z-index: 1000; align-items: center; justify-content: center; padding: 20px;
        }
        .modal-backdrop.show { display: flex; }
        .modal-content {
            background: var(--bg-primary); border-radius: 16px; width: 100%; max-width: 900px;
            max-height: 90vh; display: flex; flex-direction: column; overflow: hidden;
        }
        .modal-header {
            display: flex; justify-content: space-between; align-items: center;
            padding: 16px 20px; border-bottom: 1px solid var(--border-color);
        }
        .modal-title { font-weight: 600; font-size: 16px; color: var(--text-primary); }
        .modal-close {
            background: none; border: none; font-size: 20px; color: var(--text-muted);
            cursor: pointer; padding: 4px 8px;
        }
        .modal-close:hover { color: var(--text-primary); }
        .modal-body { flex: 1; overflow: auto; padding: 20px; text-align: center; }
        .modal-compare { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
        .compare-col { text-align: center; }
        .compare-label { font-size: 13px; font-weight: 600; color: var(--text-secondary); margin-bottom: 6px; }
        .compare-meta { font-size: 12px; color: var(--text-muted); margin-bottom: 10px; }
        .compare-col img {
            max-width: 100%; max-height: 70vh; border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }
        .compare-col.missing img { display: none; }
        .compare-col.missing .compare-meta { color: #ef4444; }
        @media (max-width: 768px) {
            .modal-compare { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <?php $layout->renderSidebar(); ?>
        <main class="main-content">
            <div class="content-area">
                <?php $app->renderAppHeader(); ?>
                
                <div class="converter">
                    <div class="top-row">
                        <div class="info-card">
                            <div class="info-card-title">
                                <i class="fas fa-lightbulb"></i>
                                Compression Guide
                            </div>
                            <p style="margin: 0 0 8px 0; font-size: 12px; color: var(--text-secondary);">
                                Smaller files mean less detail.
                            </p>
                            <div class="info-grid">
                                <div class="info-item">
                                    <div class="info-icon"><i class="fas fa-image"></i></div>
                                    <div class="info-text"><strong>0–30%:</strong> Best quality.</div>
                                </div>
                                <div class="info-item">
                                    <div class="info-icon"><i class="fas fa-balance-scale"></i></div>
                                    <div class="info-text"><strong>30–60%:</strong> Balanced.</div>
                                </div>
                                <div class="info-item">
                                    <div class="info-icon"><i class="fas fa-bolt"></i></div>
                                    <div class="info-text"><strong>60–100%:</strong> Smallest size.</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="quality-control">
                            <div class="quality-header">
                                <div class="quality-label-group">
                                    <span class="quality-label">Compression</span>
                                    <span class="quality-sub">Quality vs size</span>
                                </div>
                                <span class="quality-value">
                                    <i class="fas fa-compress-alt"></i>
                                    <span id="qualityValue">50</span>%
                                </span>
                            </div>
                            <input type="range" id="qualitySlider" class="quality-slider" min="0" max="100" value="50">
                            <div class="quality-hints">
                                <span>Quality</span>
                                <span>Size</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="upload-box" id="uploadBox">
                        <div class="upload-icon"><i class="fas fa-images"></i></div>
                        <div class="upload-text">Drop your images here</div>
                        <div class="upload-hint">or click to browse (multiple files supported)</div>
                        <input type="file" id="fileInput" class="file-input" 
                               accept=".jpg,.jpeg,.png,.webp,.gif" multiple>
                    </div>
                    
                    <div class="file-list" id="fileList"></div>
                    
                    <div class="actions" id="actions" style="display: none;">
                        <button type="button" class="btn-compress" id="btnCompress">
                            <i class="fas fa-compress-alt"></i>
                            <span id="btnCompressText">Compress Images</span>
                        </button>
                        <button type="button" class="btn-compress" id="btnDownloadAll" style="display: none;">
                            <i class="fas fa-download"></i>
                            <span id="btnDownloadAllText">Download All</span>
                        </button>
                        <button type="button" class="btn-clear" id="btnClear">
                            <i class="fas fa-times"></i> Clear All
                        </button>
                    </div>
                    
                    <div class="formats">
                        Supported formats: JPEG, PNG, WebP, GIF
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <div class="progress-overlay" id="progressOverlay">
        <div class="progress-card">
            <div class="spinner"></div>
            <div id="progressText">Compressing images...</div>
        </div>
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
        const uploadBox = document.getElementById('uploadBox');
        const fileInput = document.getElementById('fileInput');
        const fileList = document.getElementById('fileList');
        const actions = document.getElementById('actions');
        const qualitySlider = document.getElementById('qualitySlider');
        const qualityValue = document.getElementById('qualityValue');
        const btnCompress = document.getElementById('btnCompress');
        const btnCompressText = document.getElementById('btnCompressText');
        const btnDownloadAll = document.getElementById('btnDownloadAll');
        const btnDownloadAllText = document.getElementById('btnDownloadAllText');
        const btnClear = document.getElementById('btnClear');
        const progressOverlay = document.getElementById('progressOverlay');
        const maxPostBytes = <?= (int)$maxPostBytes ?>;
        const maxUploadBytes = <?= (int)$maxUploadBytes ?>;
        
        let selectedFiles = [];
        let compressedFiles = {};
        let compression = 50;
        let previewOriginalUrl = null;
        
        qualitySlider.addEventListener('input', (e) => {
            compression = parseInt(e.target.value);
            qualityValue.textContent = compression;
            updateUI();
        });
        
        function formatSize(bytes) {
            if (bytes === 0) return '0 B';
            const k = 1024, sizes = ['B', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
        }

        function checkUploadLimits(files) {
            if (!files || files.length === 0) return true;
            const tooLargeFiles = [];
            let totalBytes = 0;
            for (const file of files) {
                totalBytes += file.size || 0;
                if (maxUploadBytes > 0 && file.size > maxUploadBytes) {
                    tooLargeFiles.push(file.name);
                }
            }
            if (tooLargeFiles.length > 0) {
                alert(`These files exceed the per-file limit (${formatSize(maxUploadBytes)}):\n` + tooLargeFiles.join('\n'));
                return false;
            }
            if (maxPostBytes > 0 && totalBytes > maxPostBytes) {
                alert(`Total upload size (${formatSize(totalBytes)}) exceeds the request limit (${formatSize(maxPostBytes)}). Try fewer/smaller files.`);
                return false;
            }
            return true;
        }
        
        function isCompressedAtCurrentLevel(file) {
            const entry = compressedFiles[file.name];
            return entry && entry.success && entry.compression_level === compression;
        }

        function updateUI() {
            const pendingCount = selectedFiles.filter(f => !isCompressedAtCurrentLevel(f)).length;
            const successCount = Object.values(compressedFiles).filter(r => r.success).length;
            actions.style.display = selectedFiles.length > 0 ? '' : 'none';
            btnCompress.disabled = pendingCount === 0;
            btnCompressText.textContent = pendingCount > 1 ? `Compress ${pendingCount} Images` : 'Compress Image';
            btnDownloadAll.style.display = successCount > 1 ? '' : 'none';
            btnDownloadAllText.textContent = `Download All (${successCount})`;
        }

        
        function renderFileList() {
            fileList.innerHTML = selectedFiles.map((file, idx) => {
                const compressed = compressedFiles[file.name];
                const previewUrl = URL.createObjectURL(file);
                let statusIcon = '<i class="fas fa-clock pending"></i>';
                let actionsHtml = `<button class="btn-sm btn-remove" onclick="removeFile(${idx})"><i class="fas fa-times"></i></button>`;
                let savingsHtml = '';
                
                if (compressed?.success) {
                    statusIcon = '<i class="fas fa-check-circle success"></i>';
                    actionsHtml = `<button type="button" class="btn-sm btn-compare" onclick="previewImages(${idx})" title="Compare"><i class="fas fa-eye"></i></button>` +
                        `<a href="${compressed.download_url}" class="btn-sm btn-download" download><i class="fas fa-download"></i></a>` + actionsHtml;
                    savingsHtml = `<div class="file-savings">↓ ${compressed.savings}%</div>`;
                } else if (compressed) {
                    statusIcon = '<i class="fas fa-exclamation-circle error"></i>';
                }
                
                return `<div class="file-card">
                    <div class="file-status">${statusIcon}</div>
                    <div class="file-preview"><img src="${previewUrl}" alt="${file.name}"></div>
                    <div class="file-name">${file.name}</div>
                    <div class="file-size">${formatSize(file.size)}${compressed?.size_compressed ? ' → ' + compressed.size_compressed : ''}</div>
                    ${savingsHtml}
                    <div class="file-actions">${actionsHtml}</div>
                </div>`;
            }).join('');
            updateUI();
        }
        
        function addFiles(files) {
            const allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
            for (const file of files) {
                const ext = file.name.split('.').pop().toLowerCase();
                if (!allowed.includes(ext)) continue;
                if (!selectedFiles.find(f => f.name === file.name)) {
                    selectedFiles.push(file);
                }
            }
            renderFileList();
        }
        
        window.removeFile = function(idx) {
            const file = selectedFiles[idx];
            delete compressedFiles[file.name];
            selectedFiles.splice(idx, 1);
            renderFileList();
        };
        
        uploadBox.addEventListener('click', () => fileInput.click());
        fileInput.addEventListener('change', () => { addFiles(fileInput.files); fileInput.value = ''; });
        uploadBox.addEventListener('dragover', e => { e.preventDefault(); uploadBox.classList.add('dragover'); });
        uploadBox.addEventListener('dragleave', () => uploadBox.classList.remove('dragover'));
        uploadBox.addEventListener('drop', e => { e.preventDefault(); uploadBox.classList.remove('dragover'); addFiles(e.dataTransfer.files); });
        
        btnDownloadAll.addEventListener('click', () => {
            const successFiles = Object.values(compressedFiles).filter(r => r.success).map(r => r.compressed);
            if (successFiles.length > 0) {
                window.location.href = '?action=download-all&files=' + encodeURIComponent(successFiles.join(','));
            }
        });
        
        btnClear.addEventListener('click', () => {
            selectedFiles = [];
            compressedFiles = {};
            renderFileList();
        });
        
        btnCompress.addEventListener('click', async () => {
            const toCompress = selectedFiles.filter(f => !isCompressedAtCurrentLevel(f));
            if (toCompress.length === 0) return;
            if (!checkUploadLimits(toCompress)) return;
            
            progressOverlay.classList.add('show');
            const formData = new FormData();
            toCompress.forEach(f => formData.append('images[]', f));
            formData.append('compression', compression);
            
            try {
                const res = await fetch(location.pathname, {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                const data = await res.json();
                if (data.results) {
                    data.results.forEach(r => { 
                        r.compression_level = compression;
                        compressedFiles[r.name] = r; 
                    });
                }
                renderFileList();
            } catch (e) {
                alert('Error: ' + e.message);
            }
            progressOverlay.classList.remove('show');
        });

        function previewImages(idx) {
            const file = selectedFiles[idx];
            if (!file) return;
            const compressed = compressedFiles[file.name];
            if (!compressed?.success) return;

            const modal = document.getElementById('previewModal');
            const title = document.getElementById('previewTitle');
            const originalCol = document.getElementById('compareOriginal');
            const compressedCol = document.getElementById('compareCompressed');
            const originalImg = document.getElementById('previewOriginal');
            const compressedImg = document.getElementById('previewCompressed');
            const originalMeta = document.getElementById('previewOriginalMeta');
            const compressedMeta = document.getElementById('previewCompressedMeta');

            title.textContent = 'Before / After';

            if (previewOriginalUrl) {
                URL.revokeObjectURL(previewOriginalUrl);
            }
            previewOriginalUrl = URL.createObjectURL(file);

            originalCol.classList.remove('missing');
            originalMeta.textContent = `Original • ${formatSize(file.size)}`;
            originalImg.src = previewOriginalUrl;

            compressedCol.classList.remove('missing');
            compressedMeta.textContent = compressed.size_compressed ? `Compressed • ${compressed.size_compressed}` : 'Compressed';
            compressedImg.src = compressed.download_url;

            modal.classList.add('show');
            modal.onclick = function(e) {
                if (e.target === modal) closePreview();
            };
            document.addEventListener('keydown', handleEscape);
        }

        function closePreview() {
            const modal = document.getElementById('previewModal');
            const originalImg = document.getElementById('previewOriginal');
            const compressedImg = document.getElementById('previewCompressed');

            modal.classList.remove('show');
            originalImg.src = '';
            compressedImg.src = '';
            if (previewOriginalUrl) {
                URL.revokeObjectURL(previewOriginalUrl);
                previewOriginalUrl = null;
            }
            document.removeEventListener('keydown', handleEscape);
        }

        function handleEscape(e) {
            if (e.key === 'Escape') closePreview();
        }
    </script>
</body>
</html>
