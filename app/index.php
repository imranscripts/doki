<?php
/**
 * index.php - Smart Landing Page Router
 *
 * Redirects authenticated users to their first accessible page
 * based on role and granular permissions.
 * Unauthenticated users see the stealth page or login page.
 */

require_once __DIR__ . '/includes/session-bootstrap.php';

if (session_status() === PHP_SESSION_NONE) {
    doki_start_session();
}

require_once __DIR__ . '/includes/StealthGuard.php';
require_once __DIR__ . '/includes/OnboardingManager.php';

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Route first-run installs to onboarding before any normal stealth/login logic.
$onboarding = new OnboardingManager();
if ($onboarding->requiresOnboarding()) {
    header('Location: /onboarding.php');
    exit;
}

// Check authentication
if (!isset($_SESSION['auth_token']) || !isset($_SESSION['user'])) {
    // Not authenticated - route to stealth or login
    if (StealthGuard::isEnabled()) {
        require __DIR__ . '/stealth.php';
        exit;
    }
    header('Location: /login.php');
    exit;
}

// Authenticated - find the best landing page
require_once __DIR__ . '/includes/Auth.php';
require_once __DIR__ . '/includes/PermissionManager.php';

$auth = new Auth();
$user = $auth->validateSession($_SESSION['auth_token']);

if (!$user) {
    // Session expired
    session_destroy();
    if (StealthGuard::isEnabled()) {
        header('Location: /');
    } else {
        header('Location: /login.php');
    }
    exit;
}

$_SESSION['user'] = $user;

$permManager = new PermissionManager();
$landingPage = $permManager->getFirstAccessiblePage($user);

if ($landingPage) {
    header('Location: ' . $landingPage);
} else {
    // No accessible pages at all - show a minimal message
    http_response_code(403);
    echo '<!DOCTYPE html><html><head><title>No Access</title></head>';
    echo '<body style="font-family: sans-serif; text-align: center; padding: 50px;">';
    echo '<h1>No Access</h1>';
    echo '<p>You do not have access to any pages. Contact your administrator.</p>';
    echo '<form method="post" action="/login.php?logout=1"><button type="submit">Logout</button></form>';
    echo '</body></html>';
}
exit;
