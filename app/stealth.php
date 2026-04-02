<?php
/**
 * stealth.php - Hidden login entrypoint
 *
 * Part of Doki v3 Architecture - Batch 11: Security Hardening
 */

require_once __DIR__ . '/includes/session-bootstrap.php';

if (session_status() === PHP_SESSION_NONE) {
    doki_start_session();
}

require_once __DIR__ . '/includes/StealthGuard.php';
require_once __DIR__ . '/includes/OnboardingManager.php';

$onboarding = new OnboardingManager();
if ($onboarding->requiresOnboarding()) {
    header('Location: /onboarding.php');
    exit;
}

if (!StealthGuard::isEnabled()) {
    header('Location: /login.php');
    exit;
}

// If already unlocked and within window, redirect to login
$unlockTime = $_SESSION['stealth']['unlock_time'] ?? 0;
$unlocked = $_SESSION['stealth']['unlocked'] ?? false;
if ($unlocked && (time() - $unlockTime) <= StealthGuard::getWindowSeconds()) {
    header('Location: /login.php');
    exit;
}

// Serve stealth page as 404 to discourage bots
http_response_code(404);
header('Content-Type: text/html; charset=UTF-8');

// Per-session nonce for the keystroke endpoint
if (empty($_SESSION['stealth']['nonce'])) {
    $_SESSION['stealth']['nonce'] = bin2hex(random_bytes(8));
}
$nonce = $_SESSION['stealth']['nonce'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 Not Found</title>
</head>
<body>
    <h1>Not Found</h1>
    <p>The requested URL was not found on this server.</p>
    <hr>
    <address>Apache/2.4 Server at localhost Port 80</address>
    <script>
let reloadTimer = null;
document.addEventListener('keydown', async (e) => {
    const k = e.key;
    if (!k || k.length !== 1) return;
    try {
        const u = atob('X3AucGhw') + '?c=' + <?= json_encode($nonce) ?>;
        await fetch(u, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ k })
        });
    } catch (_) {}
    if (reloadTimer) clearTimeout(reloadTimer);
    reloadTimer = setTimeout(() => {
        location.replace('/');
    }, 500);
});
    </script>
</body>
</html>
