<?php
/**
 * api/auth.php - Authentication API Endpoints
 * 
 * Part of Doki v3 Architecture - Batch 1: Auth & UI Foundation
 */

require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/StealthGuard.php';
require_once __DIR__ . '/../includes/OnboardingManager.php';
require_once __DIR__ . '/../includes/session-bootstrap.php';

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Content-Type: application/json');

// Start session
if (session_status() === PHP_SESSION_NONE) {
    doki_start_session();
}

$auth = new Auth();
$onboarding = new OnboardingManager();
$method = $_SERVER['REQUEST_METHOD'];

// Get request body for POST/PUT
$input = [];
if (in_array($method, ['POST', 'PUT'])) {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true) ?? [];
}

// Get action from query string
$action = $_GET['action'] ?? '';

// Helper functions
function jsonResponse(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function requireAuth(): array {
    global $auth;
    
    // Check for token in session or header
    $token = $_SESSION['auth_token'] ?? null;
    
    if (!$token) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            $token = $matches[1];
        }
    }
    
    if (!$token) {
        jsonResponse(['success' => false, 'error' => 'Not authenticated'], 401);
    }
    
    $user = $auth->validateSession($token);
    if (!$user) {
        jsonResponse(['success' => false, 'error' => 'Session expired'], 401);
    }
    
    return $user;
}

function requireRole(string $role): array {
    global $auth;
    $user = requireAuth();
    
    if (!$auth->hasRole($role)) {
        jsonResponse(['success' => false, 'error' => 'Access denied'], 403);
    }
    
    return $user;
}

function ensurePublicAuthAllowed(): void {
    global $onboarding;

    if ($onboarding->requiresOnboarding()) {
        jsonResponse(['success' => false, 'error' => 'Finish onboarding before signing in.'], 409);
    }

    if (StealthGuard::isEnabled() && !StealthGuard::isSessionUnlocked()) {
        jsonResponse(['success' => false, 'error' => 'Stealth verification required before signing in.'], 403);
    }
}

// Route actions
switch ($action) {
    case 'login':
        if ($method !== 'POST') {
            jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
        }
        ensurePublicAuthAllowed();
        
        $username = $input['username'] ?? '';
        $password = $input['password'] ?? '';
        
        if (empty($username) || empty($password)) {
            jsonResponse(['success' => false, 'error' => 'Username and password required'], 400);
        }
        
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        $result = $auth->login($username, $password, $ip, $userAgent);
        
        if ($result['success']) {
            // Store token in session
            $_SESSION['auth_token'] = $result['token'];
            $_SESSION['user'] = $result['user'];
            
            jsonResponse([
                'success' => true,
                'user' => $result['user'],
                'redirect' => '/'
            ]);
        } else {
            jsonResponse($result, $result['code'] ?? 401);
        }
        break;

    case 'logout':
        if ($method !== 'POST') {
            jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
        }
        
        $token = $_SESSION['auth_token'] ?? null;
        if ($token) {
            $auth->logout($token);
        }
        
        session_destroy();
        jsonResponse(['success' => true, 'redirect' => 'login.php']);
        break;

    case 'me':
        $user = requireAuth();
        jsonResponse(['success' => true, 'user' => $user]);
        break;

    case 'change-password':
        if ($method !== 'POST') {
            jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
        }
        
        $user = requireAuth();
        $currentPassword = $input['currentPassword'] ?? '';
        $newPassword = $input['newPassword'] ?? '';
        
        if (empty($currentPassword) || empty($newPassword)) {
            jsonResponse(['success' => false, 'error' => 'Both passwords required'], 400);
        }
        
        if (strlen($newPassword) < 8) {
            jsonResponse(['success' => false, 'error' => 'Password must be at least 8 characters'], 400);
        }
        
        $result = $auth->changePassword($user['id'], $currentPassword, $newPassword);
        jsonResponse($result, $result['success'] ? 200 : 400);
        break;

    // User management (super-admin only)
    case 'users':
        if ($method === 'GET') {
            requireRole('super-admin');
            $users = $auth->getAllUsers();
            jsonResponse(['success' => true, 'users' => $users]);
        }
        break;

    case 'user':
        $userId = $_GET['id'] ?? '';
        
        if ($method === 'GET') {
            requireRole('super-admin');
            $user = $auth->getUserById($userId);
            if ($user) {
                jsonResponse(['success' => true, 'user' => $user]);
            } else {
                jsonResponse(['success' => false, 'error' => 'User not found'], 404);
            }
        } elseif ($method === 'POST') {
            // Create user
            requireRole('super-admin');
            $result = $auth->createUser(
                $input['username'] ?? '',
                $input['email'] ?? '',
                $input['name'] ?? '',
                $input['password'] ?? '',
                $input['role'] ?? 'user'
            );
            jsonResponse($result, $result['success'] ? 201 : 400);
        } elseif ($method === 'PUT') {
            // Update user
            requireRole('super-admin');
            $result = $auth->updateUser($userId, $input);
            jsonResponse($result, $result['success'] ? 200 : 400);
        } elseif ($method === 'DELETE') {
            // Delete user
            requireRole('super-admin');
            $result = $auth->deleteUser($userId);
            jsonResponse($result, $result['success'] ? 200 : 400);
        }
        break;

    default:
        jsonResponse(['success' => false, 'error' => 'Unknown action'], 400);
}
