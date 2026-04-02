<?php
/**
 * _p.php - Keystroke validation for stealth mode
 *
 * Part of Doki v3 Architecture - Batch 11: Security Hardening
 */

require_once __DIR__ . '/includes/session-bootstrap.php';

if (session_status() === PHP_SESSION_NONE) {
    doki_start_session();
}
header('Content-Type: text/plain; charset=UTF-8');

require_once __DIR__ . '/includes/StealthGuard.php';
require_once __DIR__ . '/includes/OnboardingManager.php';

$onboarding = new OnboardingManager();
if ($onboarding->requiresOnboarding()) {
    http_response_code(404);
    exit;
}

if (!StealthGuard::isEnabled()) {
    http_response_code(404);
    exit;
}

$nonce = $_GET['c'] ?? '';
if (!isset($_SESSION['stealth']['nonce']) || $nonce !== ($_SESSION['stealth']['nonce'] ?? '')) {
    http_response_code(404);
    exit;
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true) ?? [];
$char = $input['k'] ?? '';

if (!is_string($char) || strlen($char) !== 1) {
    http_response_code(404);
    exit;
}

try {
    $secret = StealthGuard::getSecret();
} catch (Exception $e) {
    http_response_code(404);
    exit;
}
$ttl = StealthGuard::getKeystrokeTtl();
$maxAttempts = StealthGuard::getMaxAttempts();
$lockoutBase = StealthGuard::getLockoutBaseMinutes();
$lockoutMax = StealthGuard::getLockoutMaxMinutes();

if (!isset($_SESSION['stealth'])) {
    $_SESSION['stealth'] = [
        'keystrokes' => [],
        'unlocked' => false,
        'unlock_time' => null,
        'failed_attempts' => 0,
        'lockout_until' => null,
        'lockout_logged_until' => null,
        'access_logged' => false,
    ];
}

$state = &$_SESSION['stealth'];
$now = time();
$sessionId = session_id();

if (empty($state['access_logged'])) {
    require_once __DIR__ . '/includes/Auth.php';
    $auth = new Auth();
    $auth->auditLog(
        null,
        null,
        'stealth_access',
        'auth',
        null,
        'Stealth access (session=' . $sessionId . ')',
        $_SERVER['REMOTE_ADDR'] ?? null
    );
    $state['access_logged'] = true;
}

if (!empty($state['lockout_until']) && $now < $state['lockout_until']) {
    if (empty($state['lockout_logged_until']) || $state['lockout_logged_until'] < $state['lockout_until']) {
        require_once __DIR__ . '/includes/Auth.php';
        $auth = new Auth();
        $auth->auditLog(
            null,
            null,
            'stealth_locked',
            'auth',
            null,
            'Stealth locked attempt (session=' . $sessionId . ')',
            $_SERVER['REMOTE_ADDR'] ?? null
        );
        $state['lockout_logged_until'] = $state['lockout_until'];
    }
    http_response_code(404);
    exit;
}

// Append keystroke
$state['keystrokes'][] = ['char' => $char, 'time' => $now];

// Trim old keystrokes
$cutoff = $now - $ttl;
$state['keystrokes'] = array_values(array_filter(
    $state['keystrokes'],
    fn($k) => $k['time'] >= $cutoff
));

// Build sequence
$sequence = implode('', array_map(fn($k) => $k['char'], $state['keystrokes']));

// Check match
if (str_ends_with($sequence, $secret)) {
    $state['unlocked'] = true;
    $state['unlock_time'] = $now;
    $state['keystrokes'] = [];
    $state['failed_attempts'] = 0;
    $state['lockout_until'] = null;
    $state['lockout_logged_until'] = null;
    require_once __DIR__ . '/includes/Auth.php';
    $auth = new Auth();
    $auth->auditLog(
        null,
        null,
        'stealth_unlock',
        'auth',
        null,
        'Stealth unlocked (session=' . $sessionId . ')',
        $_SERVER['REMOTE_ADDR'] ?? null
    );
    http_response_code(404);
    exit;
}

// Track failed attempts when sequence is at least secret length
if (strlen($sequence) >= strlen($secret)) {
    $state['failed_attempts']++;
    if ($state['failed_attempts'] >= $maxAttempts) {
        $multiplier = floor($state['failed_attempts'] / $maxAttempts);
        $minutes = min($lockoutMax, $lockoutBase * max(1, $multiplier));
        $state['lockout_until'] = $now + ($minutes * 60);
        require_once __DIR__ . '/includes/Auth.php';
        $auth = new Auth();
        $auth->auditLog(
            null,
            null,
            'stealth_lockout',
            'auth',
            null,
            'Stealth lockout triggered (' . $minutes . 'm, session=' . $sessionId . ')',
            $_SERVER['REMOTE_ADDR'] ?? null
        );
    }
}

http_response_code(404);
