<?php
/**
 * session-bootstrap.php - Shared session initialization
 */

if (!function_exists('doki_get_session_save_path')) {
    function doki_get_session_save_path(): string {
        $volumePath = '/var/www/sessions';
        if (is_dir($volumePath) || (!file_exists($volumePath) && @mkdir($volumePath, 01777, true))) {
            return $volumePath;
        }

        $tmpPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'doki-sessions';
        if (is_dir($tmpPath) || (!file_exists($tmpPath) && @mkdir($tmpPath, 01777, true))) {
            return $tmpPath;
        }

        $sharedPath = realpath(__DIR__ . '/../data');
        if (is_string($sharedPath) && $sharedPath !== '') {
            return $sharedPath . '/sessions';
        }

        return '/var/www/html/data/sessions';
    }
}

if (!function_exists('doki_prepare_session_storage')) {
    function doki_prepare_session_storage(): string {
        $path = doki_get_session_save_path();
        if (!is_dir($path)) {
            @mkdir($path, 01777, true);
        }
        if (is_dir($path)) {
            @chmod($path, 01777);
        }

        $resolved = realpath($path);
        $candidate = is_string($resolved) && $resolved !== '' ? $resolved : $path;
        if (@is_dir($candidate) && @is_writable($candidate)) {
            return $candidate;
        }

        if (!is_dir('/tmp')) {
            @mkdir('/tmp', 01777, true);
        }
        @chmod('/tmp', 01777);

        return '/tmp';
    }
}

if (!function_exists('doki_forget_session_cookie')) {
    function doki_forget_session_cookie(): void {
        $sessionName = session_name();
        unset($_COOKIE[$sessionName]);
        session_id('');

        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }

        $params = session_get_cookie_params();
        setcookie(
            $sessionName,
            '',
            [
                'expires' => time() - 3600,
                'path' => $params['path'] ?: '/',
                'domain' => $params['domain'] ?: '',
                'secure' => (bool)($params['secure'] ?? false),
                'httponly' => (bool)($params['httponly'] ?? true),
                'samesite' => $params['samesite'] ?? 'Lax',
            ]
        );
    }
}

if (!function_exists('doki_clear_stale_session_reference')) {
    function doki_clear_stale_session_reference(string $path): void {
        $sessionName = session_name();
        $requestedId = $_COOKIE[$sessionName] ?? '';
        if (!is_string($requestedId) || $requestedId === '') {
            return;
        }

        // PHP file sessions create paths in the form sess_<id>.
        if (!preg_match('/^[A-Za-z0-9,-]+$/', $requestedId)) {
            doki_forget_session_cookie();
            return;
        }

        $sessionFile = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'sess_' . $requestedId;
        if (is_file($sessionFile)) {
            return;
        }

        doki_forget_session_cookie();
    }
}

if (!function_exists('doki_start_session')) {
    function doki_start_session(): void {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }

        $path = doki_prepare_session_storage();
        if ($path !== '') {
            @session_save_path($path);
            doki_clear_stale_session_reference($path);
        }

        if (@session_start()) {
            return;
        }

        doki_forget_session_cookie();
        if (!@session_start()) {
            throw new RuntimeException('Unable to start PHP session.');
        }
    }
}
