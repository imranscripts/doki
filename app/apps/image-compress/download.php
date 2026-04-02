<?php
/**
 * Image Compressor - Download Handler
 */

require_once __DIR__ . '/../../includes/AppContext.php';

$app = new AppContext('image-compress');
$user = $app->requireAuth();

$filename = basename($_GET['file'] ?? '');
if (!$filename) {
    http_response_code(400);
    die('Invalid file');
}

$type = $_GET['type'] ?? 'compressed';
$type = $type === 'original' ? 'original' : 'compressed';

$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
$allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

if (!in_array($ext, $allowed)) {
    http_response_code(400);
    die('Invalid file type');
}

$subdir = $type === 'original' ? 'uploads' : 'compressed';
$imagePath = $app->getStoragePath() . '/' . $subdir . '/' . $filename;

if (!file_exists($imagePath)) {
    http_response_code(404);
    die('File not found');
}

$app->audit($type === 'original' ? 'image_downloaded_original' : 'image_downloaded', $filename);

$mimeTypes = [
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'webp' => 'image/webp',
    'gif' => 'image/gif',
];

header('Content-Type: ' . ($mimeTypes[$ext] ?? 'application/octet-stream'));
header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
header('Content-Length: ' . filesize($imagePath));
readfile($imagePath);
exit;
