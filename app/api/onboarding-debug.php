<?php
/**
 * onboarding-debug.php - Temporary onboarding client-side diagnostics
 */

require_once __DIR__ . '/../includes/OnboardingDebugLogger.php';

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw ?: '{}', true);
if (!is_array($payload)) {
    $payload = [];
}

OnboardingDebugLogger::log(
    'client.' . trim((string)($payload['event'] ?? 'unknown')),
    [
        'step' => $payload['step'] ?? null,
        'username' => $payload['username'] ?? null,
        'message' => $payload['message'] ?? null,
        'href' => $payload['href'] ?? null,
        'userAgent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
    ]
);

echo json_encode(['success' => true]);
