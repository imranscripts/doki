<?php
/**
 * App Bootstrap
 * 
 * Checks if app needs custom runtime and proxies to FPM container if ready.
 * If not ready, shows "unavailable" message - setup is done via admin panel.
 */

if (defined('DOKI_APP_BOOTSTRAP_RUN')) return;
define('DOKI_APP_BOOTSTRAP_RUN', true);

// Buffer output to prevent header issues
ob_start();

// Already in FPM container - continue normally
if (getenv('DOKI_FPM_CONTAINER') === 'true') {
    ob_end_flush();
    return;
}

// Extract app ID
$_bsScript = $_SERVER['SCRIPT_FILENAME'] ?? '';
if (!preg_match('#/apps/([a-z0-9_-]+)/#i', $_bsScript, $_bsMatch)) {
    ob_end_flush();
    return;
}

require_once __DIR__ . '/AppManager.php';

$_bsAppId = $_bsMatch[1];
$_bsManifest = AppManager::getAppManifestPath($_bsAppId);

if (!is_string($_bsManifest) || !file_exists($_bsManifest) || !function_exists('yaml_parse_file')) {
    ob_end_flush();
    return;
}

$_bsConfig = yaml_parse_file($_bsManifest) ?: [];
$_bsExtensions = $_bsConfig['runtime']['phpExtensions'] ?? [];

if (empty($_bsExtensions)) {
    // No custom runtime needed - flush buffer and continue
    ob_end_flush();
    unset($_bsScript, $_bsMatch, $_bsAppId, $_bsManifest, $_bsConfig, $_bsExtensions);
    return;
}

// Require authentication before any custom-runtime app response (including setup/unavailable pages)
_bsRequireAuthIfNeeded($_bsConfig);

// App needs custom runtime - check if ready
require_once __DIR__ . '/AppImageBuilder.php';

$_bsBuilder = new AppImageBuilder($_bsAppId, $_bsConfig);
$_bsStatus = $_bsBuilder->getStatus();

if (
    !($_bsStatus['containerRunning'] ?? false)
    && !($_bsStatus['runtimeStale'] ?? false)
    && ($_bsStatus['imageExists'] ?? false)
) {
    $startResult = $_bsBuilder->startContainer();
    if (!empty($startResult['success'])) {
        $_bsStatus = $_bsBuilder->getStatus();
    }
}

if ($_bsStatus['imageExists'] && $_bsStatus['containerRunning'] && $_bsStatus['containerInfo']) {
    // Ready - proxy the request
    require_once __DIR__ . '/FastCGIClient.php';
    
    $info = $_bsStatus['containerInfo'];
    $client = new FastCGIClient($info['ip'], $info['port'], 120); // Increased timeout for large responses
    
    $scriptPath = _bsResolveProxyScriptPath();
    $scriptFilename = "/var/www/html{$scriptPath}";
    
    $params = FastCGIClient::buildWebParams($scriptFilename);
    
    $stdin = '';
    if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['POST', 'PUT', 'PATCH'])) {
        // Check if this is a multipart form with files
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (strpos($contentType, 'multipart/form-data') !== false && !empty($_FILES)) {
            // Rebuild multipart body from $_POST and $_FILES
            $boundary = '----DokiProxy' . uniqid();
            $params['CONTENT_TYPE'] = 'multipart/form-data; boundary=' . $boundary;
            $stdin = _bsBuildMultipart($boundary, $_POST, $_FILES);
        } else {
            $stdin = file_get_contents('php://input');
        }
        $params['CONTENT_LENGTH'] = strlen($stdin);
    }
    
    $result = $client->execute($scriptFilename, $params, $stdin);
    
    // Debug logging
    error_log("AppBootstrap[{$_bsAppId}]: FPM response success=" . ($result['success'] ? 'true' : 'false'));
    error_log("AppBootstrap[{$_bsAppId}]: FPM stdout length=" . strlen($result['stdout'] ?? ''));
    error_log("AppBootstrap[{$_bsAppId}]: FPM stdout last 200 chars=" . substr($result['stdout'] ?? '', -200));
    if (!empty($result['stderr'])) {
        error_log("AppBootstrap[{$_bsAppId}]: FPM stderr=" . $result['stderr']);
    }
    
    if ($result['success']) {
        // Discard any buffered output before sending proxied response
        ob_end_clean();
        
        // Parse and send response
        $parts = explode("\r\n\r\n", $result['stdout'], 2);
        if (count($parts) === 2) {
            foreach (explode("\r\n", $parts[0]) as $header) {
                error_log("AppBootstrap[{$_bsAppId}]: Header: " . $header);
                if (stripos($header, 'Status:') === 0) {
                    http_response_code((int) trim(substr($header, 7)));
                } else if (!empty($header)) {
                    header($header);
                }
            }
            echo $parts[1];
        } else {
            echo $result['stdout'];
        }
        exit;
    }
    
    // Proxy failed - log and show error
    error_log("App proxy failed for {$_bsAppId}: " . ($result['error'] ?? 'unknown'));
}

// Not ready - show unavailable page
ob_end_clean();
_bsShowUnavailable($_bsAppId, $_bsConfig, $_bsStatus);
exit;

/**
 * Rebuild multipart form data from $_POST and $_FILES
 */
function _bsBuildMultipart(string $boundary, array $post, array $files): string {
    $body = '';
    
    // Add POST fields
    foreach ($post as $name => $value) {
        if (is_array($value)) {
            foreach ($value as $v) {
                $body .= "--{$boundary}\r\n";
                $body .= "Content-Disposition: form-data; name=\"{$name}[]\"\r\n\r\n";
                $body .= "{$v}\r\n";
            }
        } else {
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"{$name}\"\r\n\r\n";
            $body .= "{$value}\r\n";
        }
    }
    
    // Add files
    foreach ($files as $fieldName => $fileData) {
        if (is_array($fileData['name'])) {
            // Multiple files
            for ($i = 0; $i < count($fileData['name']); $i++) {
                if ($fileData['error'][$i] !== UPLOAD_ERR_OK) continue;
                
                $filename = $fileData['name'][$i];
                $type = $fileData['type'][$i] ?: 'application/octet-stream';
                $tmpName = $fileData['tmp_name'][$i];
                
                $body .= "--{$boundary}\r\n";
                $body .= "Content-Disposition: form-data; name=\"{$fieldName}[]\"; filename=\"{$filename}\"\r\n";
                $body .= "Content-Type: {$type}\r\n\r\n";
                $body .= file_get_contents($tmpName) . "\r\n";
            }
        } else {
            // Single file
            if ($fileData['error'] !== UPLOAD_ERR_OK) continue;
            
            $filename = $fileData['name'];
            $type = $fileData['type'] ?: 'application/octet-stream';
            $tmpName = $fileData['tmp_name'];
            
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"{$fieldName}\"; filename=\"{$filename}\"\r\n";
            $body .= "Content-Type: {$type}\r\n\r\n";
            $body .= file_get_contents($tmpName) . "\r\n";
        }
    }
    
    $body .= "--{$boundary}--\r\n";
    
    return $body;
}

function _bsResolveProxyScriptPath(): string {
    $scriptPath = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '';
    if (!is_string($scriptPath) || $scriptPath === '') {
        $scriptPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    }

    $scriptPath = parse_url($scriptPath, PHP_URL_PATH) ?: '/';

    if (preg_match('#^/apps/[a-z0-9_-]+/?$#i', $scriptPath) === 1) {
        return rtrim($scriptPath, '/') . '/index.php';
    }

    if (str_ends_with($scriptPath, '/')) {
        return $scriptPath . 'index.php';
    }

    return $scriptPath;
}

/**
 * Require authentication unless the app is explicitly marked public.
 */
function _bsRequireAuthIfNeeded(array $config): void {
    if (!empty($config['public'])) {
        return;
    }

    require_once __DIR__ . '/session-bootstrap.php';
    require_once __DIR__ . '/Auth.php';
    require_once __DIR__ . '/StealthGuard.php';
    require_once __DIR__ . '/OnboardingManager.php';

    if (session_status() === PHP_SESSION_NONE) {
        doki_start_session();
    }

    $onboarding = new OnboardingManager();
    if ($onboarding->requiresOnboarding()) {
        header('Location: /onboarding.php');
        exit;
    }

    $token = $_SESSION['auth_token'] ?? null;
    if (!$token) {
        if (StealthGuard::isEnabled()) {
            header('Location: /');
        } else {
            header('Location: /login.php');
        }
        exit;
    }

    $auth = new Auth();
    $user = $auth->validateSession($token);
    if (!$user) {
        session_destroy();
        if (StealthGuard::isEnabled()) {
            header('Location: /');
        } else {
            header('Location: /login.php');
        }
        exit;
    }

    // Keep session user data in sync, then release the lock to avoid blocking other requests
    $_SESSION['user'] = $user;
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
}

function _bsShowUnavailable(string $appId, array $config, array $status): void {
    $name = $config['name'] ?? $appId;
    $icon = $config['icon'] ?? 'fa-puzzle-piece';
    $color = $config['color'] ?? '#6366f1';
    $extensions = $config['runtime']['phpExtensions'] ?? [];
    
    $imageExists = $status['imageExists'] ?? false;
    $containerRunning = $status['containerRunning'] ?? false;
    
    http_response_code(503);
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($name) ?> - Setup Required</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #e2e8f0;
        }
        .container { max-width: 450px; text-align: center; padding: 40px; }
        .app-icon {
            width: 72px; height: 72px; border-radius: 18px;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 20px; font-size: 32px; color: white;
            background: <?= htmlspecialchars($color) ?>;
            opacity: 0.6;
        }
        h1 { font-size: 24px; margin-bottom: 12px; color: #f8fafc; }
        .message { color: #94a3b8; margin-bottom: 32px; line-height: 1.6; }
        .status-card {
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 12px; padding: 20px; margin-bottom: 24px;
        }
        .status-row {
            display: flex; justify-content: space-between; align-items: center;
            padding: 8px 0; border-bottom: 1px solid rgba(255,255,255,0.05);
        }
        .status-row:last-child { border-bottom: none; }
        .status-label { color: #94a3b8; font-size: 14px; }
        .status-value { font-size: 14px; font-weight: 500; }
        .status-value.ready { color: #22c55e; }
        .status-value.pending { color: #f59e0b; }
        .extensions { display: flex; flex-wrap: wrap; gap: 6px; justify-content: center; margin-bottom: 24px; }
        .ext-tag { padding: 4px 10px; background: rgba(255,255,255,0.1); border-radius: 12px; font-size: 12px; color: #94a3b8; }
        .btn-back {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 12px 24px; background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.2); border-radius: 8px;
            color: #e2e8f0; text-decoration: none; font-size: 14px;
            transition: all 0.2s;
        }
        .btn-back:hover { background: rgba(255,255,255,0.15); }
        .admin-hint { margin-top: 24px; font-size: 13px; color: #64748b; }
    </style>
    <meta http-equiv="refresh" content="5">
</head>
<body>
    <div class="container">
        <div class="app-icon"><i class="fas <?= htmlspecialchars($icon) ?>"></i></div>
        <h1><?= htmlspecialchars($name) ?></h1>
        <p class="message">This app requires additional setup before it can be used.</p>
        
        <div class="status-card">
            <div class="status-row">
                <span class="status-label">Runtime Image</span>
                <span class="status-value <?= $imageExists ? 'ready' : 'pending' ?>">
                    <?= $imageExists ? '<i class="fas fa-check"></i> Built' : '<i class="fas fa-clock"></i> Not built' ?>
                </span>
            </div>
            <div class="status-row">
                <span class="status-label">Container</span>
                <span class="status-value <?= $containerRunning ? 'ready' : 'pending' ?>">
                    <?= $containerRunning ? '<i class="fas fa-check"></i> Running' : '<i class="fas fa-clock"></i> Stopped' ?>
                </span>
            </div>
        </div>
        
        <div class="extensions">
            <?php foreach ($extensions as $ext): ?>
                <span class="ext-tag">php-<?= htmlspecialchars($ext) ?></span>
            <?php endforeach; ?>
        </div>
        
        <a href="/admin/apps.php" class="btn-back">
            <i class="fas fa-arrow-left"></i> Back to Apps
        </a>
        
        <p class="admin-hint">
            An administrator needs to set up this app's runtime environment.
        </p>
    </div>
</body>
</html>
    <?php
}
