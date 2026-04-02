<?php
/**
 * middleware.php - Authentication Middleware
 * 
 * Include this at the top of protected pages to ensure user is authenticated.
 * This file includes all common dependencies for protected pages.
 * 
 * Part of Doki v3 Architecture - Batch 1: Auth & UI Foundation
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Layout.php';
require_once __DIR__ . '/StealthGuard.php';
require_once __DIR__ . '/OnboardingManager.php';
require_once __DIR__ . '/session-bootstrap.php';

// Start session
if (session_status() === PHP_SESSION_NONE) {
    doki_start_session();
}

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

/**
 * Require authentication
 * Redirects to login page if not authenticated
 */
function requireAuthentication(): array {
    $onboarding = new OnboardingManager();
    if ($onboarding->requiresOnboarding()) {
        header('Location: /onboarding.php');
        exit;
    }

    $auth = new Auth();
    
    $token = $_SESSION['auth_token'] ?? null;
    if (!$token) {
        if (StealthGuard::isEnabled()) {
            header('Location: /');
        } else {
            header('Location: /login.php');
        }
        exit;
    }
    
    $user = $auth->validateSession($token);
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
    
    // Update session user data
    $_SESSION['user'] = $user;
    
    return $user;
}

/**
 * Require specific role
 * Shows 403 error if user doesn't have required role
 */
function requireRole(string $role): array {
    $user = requireAuthentication();
    $auth = new Auth();
    $auth->validateSession($_SESSION['auth_token']); // Set current user
    
    if (!$auth->hasRole($role)) {
        http_response_code(403);
        echo '<!DOCTYPE html><html><head><title>Access Denied</title></head>';
        echo '<body style="font-family: sans-serif; text-align: center; padding: 50px;">';
        echo '<h1>403 - Access Denied</h1>';
        echo '<p>You do not have permission to access this page.</p>';
        echo '<a href="/">Return to Home</a>';
        echo '</body></html>';
        exit;
    }
    
    return $user;
}

/**
 * Get current authenticated user
 * Returns null if not authenticated (doesn't redirect)
 */
function getCurrentUser(): ?array {
    return $_SESSION['user'] ?? null;
}

/**
 * Check if current user has role
 */
function hasRole(string $role): bool {
    $auth = new Auth();
    $token = $_SESSION['auth_token'] ?? null;
    if (!$token) return false;
    
    $auth->validateSession($token);
    return $auth->hasRole($role);
}

/**
 * Require access to a specific resource (granular permission check).
 * Checks both role-based and group/user permission overrides.
 */
function requireResourceAccess(string $resourceType, string $resourceId): array {
    $user = requireAuthentication();
    $auth = new Auth();
    $auth->validateSession($_SESSION['auth_token']);

    if (!$auth->canAccessResource($resourceType, $resourceId)) {
        http_response_code(403);
        echo '<!DOCTYPE html><html><head><title>Access Denied</title></head>';
        echo '<body style="font-family: sans-serif; text-align: center; padding: 50px;">';
        echo '<h1>403 - Access Denied</h1>';
        echo '<p>You do not have permission to access this resource.</p>';
        echo '<a href="/">Return to Home</a>';
        echo '</body></html>';
        exit;
    }

    return $user;
}

/**
 * Require access to a module (sidebar section).
 * Convenience wrapper around requireResourceAccess.
 */
function requireModuleAccess(string $moduleSlug): array {
    return requireResourceAccess('module', $moduleSlug);
}

// ============================================================================
// ALIASES - To prevent naming mistakes
// ============================================================================

/**
 * Alias for requireAuthentication()
 * Use either requireAuth() or requireAuthentication()
 */
function requireAuth(): array {
    return requireAuthentication();
}
