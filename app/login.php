<?php
/**
 * login.php - Modern Login Page
 * 
 * Part of Doki v3 Architecture - Batch 1: Auth & UI Foundation
 */

require_once __DIR__ . '/includes/session-bootstrap.php';

doki_start_session();

require_once __DIR__ . '/includes/Auth.php';
require_once __DIR__ . '/includes/StealthGuard.php';
require_once __DIR__ . '/includes/OnboardingManager.php';

// Fresh installs must complete onboarding before normal login starts.
$onboarding = new OnboardingManager();
if ($onboarding->requiresOnboarding()) {
    header('Location: /onboarding.php');
    exit;
}

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

// If already authenticated, redirect to smart router
if (isset($_SESSION['auth_token']) && isset($_SESSION['user'])) {
    header('Location: /');
    exit;
}

// Stealth mode gate (default ON)
if (!StealthGuard::isSessionUnlocked()) {
    header('Location: /');
    exit;
}

$noticeMessage = null;
$errorMessage = null;
$submittedUsername = '';
if (($_GET['setup'] ?? '') === 'complete') {
    $noticeMessage = 'Onboarding is complete. Sign in with the super-admin account you just created.';
} elseif (($_GET['recovered'] ?? '') === '1') {
    $noticeMessage = 'Password recovery succeeded. Sign in with the new super-admin password.';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $submittedUsername = trim((string)($_POST['username'] ?? ''));
    $submittedPassword = (string)($_POST['password'] ?? '');

    if ($submittedUsername === '' || $submittedPassword === '') {
        $errorMessage = 'Username and password required.';
    } else {
        $auth = new Auth();
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $result = $auth->login($submittedUsername, $submittedPassword, $ip, $userAgent);

        if (!empty($result['success'])) {
            $_SESSION['auth_token'] = $result['token'];
            $_SESSION['user'] = $result['user'];
            header('Location: ' . ($result['redirect'] ?? '/'));
            exit;
        }

        $errorMessage = $result['error'] ?? 'Login failed.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Doki - Login</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --bg-primary: #0d1117;
            --bg-secondary: #161b22;
            --bg-tertiary: #21262d;
            --bg-hover: #30363d;
            --text-primary: #f0f6fc;
            --text-secondary: #8b949e;
            --text-muted: #6e7681;
            --accent-primary: #58a6ff;
            --accent-secondary: #388bfd;
            --accent-glow: rgba(88, 166, 255, 0.15);
            --success: #3fb950;
            --error: #f85149;
            --warning: #d29922;
            --border-primary: #30363d;
            --border-secondary: #21262d;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            scrollbar-width: thin;
            scrollbar-color: var(--bg-hover) var(--bg-secondary);
        }

        html {
            background: var(--bg-primary);
            color-scheme: dark;
        }

        body {
            font-family: 'Outfit', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            overflow: hidden;
        }

        /* Chrome/Safari scrollbar styling */
        *::-webkit-scrollbar {
            width: 10px;
            height: 10px;
        }

        *::-webkit-scrollbar-track {
            background: var(--bg-secondary);
        }

        *::-webkit-scrollbar-thumb {
            background-color: var(--bg-hover);
            border-radius: 999px;
            border: 2px solid var(--bg-secondary);
        }

        *::-webkit-scrollbar-thumb:hover {
            background-color: var(--border-primary);
        }

        *::-webkit-scrollbar-corner {
            background: var(--bg-secondary);
        }

        /* Left panel - branding */
        .brand-panel {
            width: 45%;
            background: linear-gradient(135deg, var(--bg-secondary) 0%, var(--bg-primary) 100%);
            padding: 60px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }

        .brand-panel::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: 
                radial-gradient(circle at 20% 30%, var(--accent-glow) 0%, transparent 50%),
                radial-gradient(circle at 80% 70%, rgba(56, 139, 253, 0.1) 0%, transparent 40%);
            animation: pulse 8s ease-in-out infinite alternate;
        }

        @keyframes pulse {
            0% { transform: translate(0, 0) scale(1); opacity: 0.5; }
            100% { transform: translate(-5%, 5%) scale(1.1); opacity: 1; }
        }

        .brand-content {
            position: relative;
            z-index: 1;
        }

        .brand-logo {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 48px;
        }

        .brand-logo .logo-icon {
            width: 64px;
            height: 64px;
            background: linear-gradient(135deg, var(--accent-primary), var(--accent-secondary));
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            color: var(--bg-primary);
            box-shadow: 0 8px 32px rgba(88, 166, 255, 0.3);
        }

        .brand-logo h1 {
            font-family: 'JetBrains Mono', monospace;
            font-size: 42px;
            font-weight: 600;
            letter-spacing: -1px;
            background: linear-gradient(135deg, var(--text-primary), var(--text-secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .brand-tagline {
            font-size: 22px;
            font-weight: 300;
            color: var(--text-secondary);
            line-height: 1.6;
            margin-bottom: 48px;
        }

        .brand-features {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .feature-item {
            display: flex;
            align-items: center;
            gap: 16px;
            color: var(--text-secondary);
            font-size: 15px;
        }

        .feature-item .icon {
            width: 40px;
            height: 40px;
            background: var(--bg-tertiary);
            border: 1px solid var(--border-primary);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--accent-primary);
            font-size: 16px;
        }

        /* Right panel - login form */
        .login-panel {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px;
        }

        .login-container {
            width: 100%;
            max-width: 400px;
        }

        .login-header {
            text-align: center;
            margin-bottom: 40px;
        }

        .login-header h2 {
            font-size: 28px;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .login-header p {
            color: var(--text-secondary);
            font-size: 15px;
        }

        .login-form {
            background: var(--bg-secondary);
            border: 1px solid var(--border-primary);
            border-radius: 16px;
            padding: 32px;
        }

        .form-group {
            margin-bottom: 24px;
        }

        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 500;
            color: var(--text-secondary);
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .input-wrapper {
            position: relative;
        }

        .input-wrapper .input-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 16px;
            pointer-events: none;
            transition: color 0.2s;
        }

        .input-wrapper input {
            width: 100%;
            padding: 14px 16px 14px 48px;
            background: var(--bg-tertiary);
            border: 2px solid var(--border-primary);
            border-radius: 10px;
            color: var(--text-primary);
            font-family: inherit;
            font-size: 15px;
            transition: all 0.2s;
        }

        .input-wrapper input::placeholder {
            color: var(--text-muted);
        }

        .input-wrapper input:focus {
            outline: none;
            border-color: var(--accent-primary);
            box-shadow: 0 0 0 4px var(--accent-glow);
        }

        .input-wrapper input:focus + .input-icon,
        .input-wrapper input:not(:placeholder-shown) + .input-icon {
            color: var(--accent-primary);
        }

        .password-toggle {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            padding: 4px;
            transition: color 0.2s;
        }

        .password-toggle:hover {
            color: var(--text-secondary);
        }

        .login-btn {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, var(--accent-primary), var(--accent-secondary));
            border: none;
            border-radius: 10px;
            color: var(--bg-primary);
            font-family: inherit;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .login-btn:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(88, 166, 255, 0.3);
        }

        .login-btn:active:not(:disabled) {
            transform: translateY(0);
        }

        .login-btn:disabled {
            opacity: 0.7;
            cursor: not-allowed;
        }

        .login-btn .spinner {
            width: 20px;
            height: 20px;
            border: 2px solid transparent;
            border-top-color: currentColor;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .error-message {
            background: rgba(248, 81, 73, 0.1);
            border: 1px solid var(--error);
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 24px;
            color: var(--error);
            font-size: 14px;
            display: none;
        }

        .error-message.visible {
            display: flex;
            align-items: center;
            gap: 10px;
            animation: shake 0.4s ease-in-out;
        }

        .notice-message {
            background: rgba(63, 185, 80, 0.12);
            border: 1px solid var(--success);
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 24px;
            color: #9ee6a8;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20%, 60% { transform: translateX(-8px); }
            40%, 80% { transform: translateX(8px); }
        }

        .login-footer {
            text-align: center;
            margin-top: 24px;
            color: var(--text-muted);
            font-size: 13px;
        }

        .login-footer a {
            color: var(--accent-primary);
            text-decoration: none;
        }

        .login-footer a:hover {
            text-decoration: underline;
        }

        /* Terminal decoration */
        .terminal-decoration {
            position: absolute;
            bottom: 40px;
            left: 60px;
            right: 60px;
            background: var(--bg-tertiary);
            border: 1px solid var(--border-primary);
            border-radius: 12px;
            padding: 20px;
            font-family: 'JetBrains Mono', monospace;
            font-size: 13px;
            opacity: 0.6;
        }

        .terminal-header {
            display: flex;
            gap: 8px;
            margin-bottom: 12px;
        }

        .terminal-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
        }

        .terminal-dot.red { background: var(--error); }
        .terminal-dot.yellow { background: var(--warning); }
        .terminal-dot.green { background: var(--success); }

        .terminal-content {
            color: var(--text-secondary);
        }

        .terminal-content .prompt {
            color: var(--success);
        }

        .terminal-content .command {
            color: var(--text-primary);
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .brand-panel {
                display: none;
            }

            .login-panel {
                padding: 24px;
            }
        }

        @media (max-width: 480px) {
            .login-form {
                padding: 24px;
            }

            .login-header h2 {
                font-size: 24px;
            }
        }
    </style>
</head>
<body>
    <!-- Brand Panel -->
    <div class="brand-panel">
        <div class="brand-content">
            <div class="brand-logo">
                <div class="logo-icon">
                    <i class="fas fa-terminal"></i>
                </div>
                <h1>doki</h1>
            </div>
            
            <p class="brand-tagline">
                Build apps, run workflows,<br>
                and create with AI.
            </p>
            
            <div class="brand-features">
                <div class="feature-item">
                    <div class="icon"><i class="fas fa-puzzle-piece"></i></div>
                    <span>Internal apps for your team</span>
                </div>
                <div class="feature-item">
                    <div class="icon"><i class="fas fa-repeat"></i></div>
                    <span>Reusable commands and templates</span>
                </div>
                <div class="feature-item">
                    <div class="icon"><i class="fas fa-robot"></i></div>
                    <span>AI-assisted app building</span>
                </div>
                <div class="feature-item">
                    <div class="icon"><i class="fas fa-users-gear"></i></div>
                    <span>Shared access and admin controls</span>
                </div>
            </div>
        </div>
        
        <div class="terminal-decoration">
            <div class="terminal-header">
                <div class="terminal-dot red"></div>
                <div class="terminal-dot yellow"></div>
                <div class="terminal-dot green"></div>
            </div>
            <div class="terminal-content">
                <span class="prompt">$</span> <span class="command">doki create app team-dashboard</span><br>
                <span style="color: var(--success);">✓</span> Workspace ready
            </div>
        </div>
    </div>

    <!-- Login Panel -->
    <div class="login-panel">
        <div class="login-container">
            <div class="login-header">
                <h2>Welcome back</h2>
                <p>Sign in to access your workspace.</p>
            </div>

            <?php if ($noticeMessage !== null): ?>
                <div class="notice-message">
                    <i class="fas fa-check-circle"></i>
                    <span><?= htmlspecialchars($noticeMessage) ?></span>
                </div>
            <?php endif; ?>

            <form class="login-form" id="loginForm" method="post" action="/login.php<?= (($_GET['setup'] ?? '') === 'complete') ? '?setup=complete' : ((($_GET['recovered'] ?? '') === '1') ? '?recovered=1' : '') ?>">
                <div class="error-message<?= $errorMessage !== null ? ' visible' : '' ?>" id="errorMessage">
                    <i class="fas fa-exclamation-circle"></i>
                    <span id="errorText"><?= htmlspecialchars((string)$errorMessage) ?></span>
                </div>

                <div class="form-group">
                    <label for="username">Username</label>
                    <div class="input-wrapper">
                        <input type="text" id="username" name="username" placeholder="Enter your username" autocomplete="username" value="<?= htmlspecialchars($submittedUsername) ?>" required>
                        <i class="fas fa-user input-icon"></i>
                    </div>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="input-wrapper">
                        <input type="password" id="password" name="password" placeholder="Enter your password" autocomplete="current-password" required>
                        <i class="fas fa-lock input-icon"></i>
                        <button type="button" class="password-toggle" onclick="togglePassword()">
                            <i class="fas fa-eye" id="toggleIcon"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="login-btn" id="loginBtn">
                    <span id="btnText">Sign In</span>
                    <span class="spinner" id="spinner" style="display: none;"></span>
                </button>
            </form>

            <div class="login-footer">
                <p><a href="/recover.php">Recover first super-admin access</a></p>
            </div>
        </div>
    </div>

    <script>
        const pendingCredentialStorageKey = 'doki.onboarding.credentials';
        const shouldOfferOnboardingCredentialSave = <?= (($_GET['setup'] ?? '') === 'complete') ? 'true' : 'false' ?>;
        const form = document.getElementById('loginForm');
        const errorMessage = document.getElementById('errorMessage');
        const errorText = document.getElementById('errorText');
        const loginBtn = document.getElementById('loginBtn');
        const btnText = document.getElementById('btnText');
        const spinner = document.getElementById('spinner');
        const usernameInput = document.getElementById('username');
        const passwordInput = document.getElementById('password');
        let autoSubmitTriggered = false;

        function consumePendingOnboardingCredentials() {
            if (!shouldOfferOnboardingCredentialSave) {
                return false;
            }

            let raw = null;
            try {
                raw = window.sessionStorage.getItem(pendingCredentialStorageKey);
            } catch (_) {
                return false;
            }

            if (!raw) {
                return false;
            }

            let pending = null;
            try {
                pending = JSON.parse(raw);
            } catch (_) {
                return false;
            }

            const username = typeof pending?.username === 'string' ? pending.username.trim() : '';
            const password = typeof pending?.password === 'string' ? pending.password : '';
            const savedAt = Number(pending?.savedAt || 0);

            if (username === '' || password === '') {
                return false;
            }

            if (!Number.isFinite(savedAt) || (Date.now() - savedAt) > (10 * 60 * 1000)) {
                return false;
            }

            if (usernameInput && usernameInput.value.trim() === '') {
                usernameInput.value = username;
            }
            if (passwordInput && passwordInput.value === '') {
                passwordInput.value = password;
            }

            return true;
        }

        function togglePassword() {
            const toggleIcon = document.getElementById('toggleIcon');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
            }
        }

        function hideError() {
            errorMessage.classList.remove('visible');
        }

        function setLoading(loading) {
            loginBtn.disabled = loading;
            btnText.style.display = loading ? 'none' : 'inline';
            spinner.style.display = loading ? 'block' : 'none';
        }

        form.addEventListener('submit', () => {
            hideError();
            setLoading(true);

            try {
                window.sessionStorage.removeItem(pendingCredentialStorageKey);
            } catch (_) {}
        });

        const hasPendingOnboardingCredentials = consumePendingOnboardingCredentials();
        if (hasPendingOnboardingCredentials && shouldOfferOnboardingCredentialSave && !<?= $errorMessage !== null ? 'true' : 'false' ?>) {
            window.requestAnimationFrame(() => {
                if (autoSubmitTriggered) {
                    return;
                }
                autoSubmitTriggered = true;
                setLoading(true);
                form.requestSubmit();
            });
        } else if (usernameInput && usernameInput.value.trim() !== '') {
            passwordInput?.focus();
        } else {
            usernameInput?.focus();
        }

        document.addEventListener('keypress', (e) => {
            if (e.key === 'Enter' && !loginBtn.disabled) {
                form.requestSubmit();
            }
        });
    </script>
</body>
</html>
