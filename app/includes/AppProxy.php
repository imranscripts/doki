<?php
/**
 * AppProxy
 * 
 * Routes requests to apps with custom runtime to their FPM containers.
 * Apps without custom runtime are served directly by Doki.
 */

require_once __DIR__ . '/AppImageBuilder.php';
require_once __DIR__ . '/FastCGIClient.php';
require_once __DIR__ . '/AppManager.php';

class AppProxy {
    private string $appId;
    private array $manifest;
    private AppImageBuilder $builder;
    
    public function __construct(string $appId) {
        $this->appId = $appId;
        $this->loadManifest();
        $this->builder = new AppImageBuilder($appId, $this->manifest);
    }
    
    /**
     * Load app manifest
     */
    private function loadManifest(): void {
        $manifestPath = AppManager::getAppManifestPath($this->appId);
        
        if (is_string($manifestPath) && file_exists($manifestPath) && function_exists('yaml_parse_file')) {
            $this->manifest = yaml_parse_file($manifestPath) ?: [];
        } else {
            $this->manifest = ['id' => $this->appId];
        }
    }
    
    /**
     * Check if this app needs to be proxied
     */
    public function needsProxy(): bool {
        return $this->builder->needsCustomImage();
    }
    
    /**
     * Ensure the app container is ready
     */
    public function ensureReady(): array {
        if (!$this->needsProxy()) {
            return ['success' => true, 'message' => 'No proxy needed'];
        }
        
        $status = $this->builder->getStatus();
        
        // If container is already running, we're good
        if ($status['containerRunning'] && $status['containerInfo']) {
            return [
                'success' => true,
                'containerInfo' => $status['containerInfo']
            ];
        }
        
        // Start the container
        $result = $this->builder->startContainer();
        
        return $result;
    }
    
    /**
     * Handle the current request, proxying if needed
     * 
     * Returns true if request was handled (proxied), false if should continue normally
     */
    public function handleRequest(): bool {
        if (!$this->needsProxy()) {
            return false; // Continue with normal execution
        }
        
        // Ensure container is ready
        $ready = $this->ensureReady();
        
        if (!$ready['success']) {
            // Container not ready - show error
            http_response_code(503);
            echo $this->renderErrorPage(
                'App Container Starting',
                'The app container is being prepared. Please refresh in a moment.',
                $ready['error'] ?? 'Container starting...'
            );
            return true;
        }
        
        $containerInfo = $ready['containerInfo'] ?? $this->builder->getContainerInfo();
        
        if (!$containerInfo) {
            http_response_code(500);
            echo $this->renderErrorPage(
                'Container Error',
                'Could not get container connection information.',
                'Container info unavailable'
            );
            return true;
        }
        
        // Proxy the request
        return $this->proxyRequest($containerInfo);
    }
    
    /**
     * Proxy a request to the FPM container
     */
    private function proxyRequest(array $containerInfo): bool {
        $client = new FastCGIClient($containerInfo['ip'], $containerInfo['port'], 60);
        
        // Determine the script to execute
        $scriptPath = $this->resolveProxyScriptPath();
        
        // Map to the actual script path inside the FPM container.
        $scriptFilename = AppManager::buildContainerAppScriptPath($this->appId, ltrim($scriptPath, '/'));
        
        // Build params
        $params = FastCGIClient::buildWebParams($scriptFilename, [
            'DOCUMENT_ROOT' => '/var/www/html',
            'SCRIPT_FILENAME' => $scriptFilename,
            'SCRIPT_NAME' => $scriptPath,
            'PHP_SELF' => $scriptPath,
            // Pass along session info
            'HTTP_COOKIE' => $_SERVER['HTTP_COOKIE'] ?? '',
        ]);
        
        // Get request body for POST/PUT
        $stdin = '';
        if (in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT', 'PATCH'])) {
            $stdin = file_get_contents('php://input');
            $params['CONTENT_LENGTH'] = strlen($stdin);
        }
        
        // Execute
        $result = $client->execute($scriptFilename, $params, $stdin);
        
        if (!$result['success']) {
            http_response_code(502);
            echo $this->renderErrorPage(
                'Proxy Error',
                'Failed to communicate with app container.',
                $result['error']
            );
            return true;
        }
        
        // Parse and send response
        $this->sendResponse($result['stdout']);
        
        // Log stderr if any
        if (!empty($result['stderr'])) {
            error_log("AppProxy[{$this->appId}] stderr: " . $result['stderr']);
        }
        
        return true;
    }
    
    /**
     * Parse FPM response and send to client
     */
    private function sendResponse(string $response): void {
        // Split headers and body
        $parts = explode("\r\n\r\n", $response, 2);
        
        if (count($parts) === 2) {
            $headerSection = $parts[0];
            $body = $parts[1];
            
            // Parse and send headers
            $headers = explode("\r\n", $headerSection);
            foreach ($headers as $header) {
                if (stripos($header, 'Status:') === 0) {
                    // Convert "Status: 200 OK" to proper HTTP status
                    $statusCode = (int) trim(substr($header, 7));
                    http_response_code($statusCode);
                } else if (!empty($header)) {
                    header($header);
                }
            }
            
            echo $body;
        } else {
            // No headers, just body
            echo $response;
        }
    }
    
    /**
     * Render an error page
     */
    private function renderErrorPage(string $title, string $message, string $details = ''): string {
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>{$title}</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, sans-serif; padding: 40px; background: #1a1a2e; color: #eee; }
        .container { max-width: 600px; margin: 0 auto; text-align: center; }
        h1 { color: #f59e0b; }
        .details { background: #16213e; padding: 20px; border-radius: 8px; margin-top: 20px; text-align: left; font-family: monospace; font-size: 14px; color: #94a3b8; }
        .retry { margin-top: 20px; }
        .retry a { color: #3b82f6; text-decoration: none; }
    </style>
    <meta http-equiv="refresh" content="5">
</head>
<body>
    <div class="container">
        <h1>{$title}</h1>
        <p>{$message}</p>
        <div class="details">{$details}</div>
        <p class="retry">This page will refresh automatically. <a href="">Click here to retry now</a></p>
    </div>
</body>
</html>
HTML;
    }

    private function resolveProxyScriptPath(): string {
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
     * Get container status
     */
    public function getStatus(): array {
        return $this->builder->getStatus();
    }
    
    /**
     * Stop the container
     */
    public function stopContainer(): array {
        return $this->builder->stopContainer();
    }
}

/**
 * Helper function to check if current request should be proxied
 */
function checkAppProxy(): bool {
    // Extract app ID from URL
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    
    if (preg_match('#^/apps/([a-z0-9_-]+)/#i', $requestUri, $matches)) {
        $appId = $matches[1];
        
        $proxy = new AppProxy($appId);
        
        if ($proxy->needsProxy()) {
            return $proxy->handleRequest();
        }
    }
    
    return false;
}
