<?php
require_once __DIR__ . '/includes/AppManager.php';

$appId = trim((string)($_GET['appId'] ?? ''));
$relativePath = trim((string)($_GET['path'] ?? ''));

if ($appId === '' || preg_match('/^[a-z0-9_-]+$/i', $appId) !== 1) {
    http_response_code(404);
    exit('App not found');
}

if ($relativePath === '') {
    $relativePath = 'index.php';
}

$normalizedPath = trim(str_replace('\\', '/', $relativePath), '/');
if ($normalizedPath === '') {
    $normalizedPath = 'index.php';
}

if (
    preg_match('#(^|/)\.#', $normalizedPath) === 1
    || preg_match('#(^|/)node_modules(?:/|$)#i', $normalizedPath) === 1
) {
    http_response_code(403);
    exit('Forbidden');
}

$target = AppManager::resolveAppFilePath($appId, $normalizedPath);
if ($target === null) {
    http_response_code(404);
    exit('App file not found');
}

$extension = strtolower(pathinfo($target, PATHINFO_EXTENSION));
if ($extension === 'php') {
    $virtualScript = AppManager::buildVirtualAppScriptPath($appId, $normalizedPath);
    $virtualUri = '/apps/' . rawurlencode($appId) . '/' . str_replace('%2F', '/', rawurlencode($normalizedPath));

    $_SERVER['SCRIPT_FILENAME'] = $virtualScript;
    $_SERVER['SCRIPT_NAME'] = $virtualUri;
    $_SERVER['PHP_SELF'] = $virtualUri;

    $cwd = getcwd();
    @chdir(dirname($target));
    require $target;
    if (is_string($cwd) && $cwd !== '') {
        @chdir($cwd);
    }
    return;
}

$mimeTypes = [
    'css' => 'text/css; charset=UTF-8',
    'gif' => 'image/gif',
    'html' => 'text/html; charset=UTF-8',
    'ico' => 'image/x-icon',
    'jpeg' => 'image/jpeg',
    'jpg' => 'image/jpeg',
    'js' => 'application/javascript; charset=UTF-8',
    'mp4' => 'video/mp4',
    'png' => 'image/png',
    'svg' => 'image/svg+xml',
    'webm' => 'video/webm',
];

if (!isset($mimeTypes[$extension])) {
    http_response_code(403);
    exit('Forbidden');
}

header('Content-Type: ' . $mimeTypes[$extension]);
$size = filesize($target);
if (is_int($size) && $size >= 0) {
    header('Content-Length: ' . (string)$size);
}
readfile($target);
