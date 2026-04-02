<?php
/**
 * recover.php - Break-glass first super-admin password reset
 */

require_once __DIR__ . '/includes/session-bootstrap.php';

if (session_status() === PHP_SESSION_NONE) {
    doki_start_session();
}

require_once __DIR__ . '/includes/StealthGuard.php';
require_once __DIR__ . '/includes/OnboardingManager.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

$onboarding = new OnboardingManager();
if ($onboarding->requiresOnboarding()) {
    header('Location: /onboarding.php');
    exit;
}

if (isset($_SESSION['auth_token']) && isset($_SESSION['user'])) {
    header('Location: /');
    exit;
}

if (!StealthGuard::isSessionUnlocked()) {
    header('Location: /');
    exit;
}

$status = $onboarding->getStatus();
$completed = null;
$errorMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $recoveryCode = (string)($_POST['recoveryCode'] ?? '');
    $newPassword = (string)($_POST['newPassword'] ?? '');
    $confirmPassword = (string)($_POST['confirmPassword'] ?? '');

    if ($newPassword !== $confirmPassword) {
        $errorMessage = 'Passwords must match.';
    } else {
        $completed = $onboarding->recoverFirstSuperAdmin($recoveryCode, $newPassword, $_SERVER['REMOTE_ADDR'] ?? '');
        if (empty($completed['success'])) {
            $errorMessage = $completed['error'] ?? 'Recovery failed.';
            $completed = null;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Doki - Recover Super-Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #101822;
            --panel: rgba(18, 28, 39, 0.92);
            --line: rgba(169, 192, 214, 0.14);
            --text: #edf4fb;
            --muted: #96a9bb;
            --accent: #f4b860;
            --accent-strong: #ffd8a5;
            --success: #74d99f;
            --error: #ff8c7a;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: 'Outfit', sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at top left, rgba(244, 184, 96, 0.18), transparent 26%),
                radial-gradient(circle at bottom right, rgba(116, 217, 159, 0.14), transparent 28%),
                linear-gradient(160deg, #0a1118 0%, #131d27 100%);
            display: grid;
            place-items: center;
            padding: 24px;
        }

        .panel {
            width: min(760px, 100%);
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 28px;
            padding: 28px;
            box-shadow: 0 30px 70px rgba(0, 0, 0, 0.32);
        }

        h1 {
            margin: 0 0 10px;
            font-size: 34px;
            line-height: 1.08;
        }

        p {
            color: var(--muted);
            line-height: 1.6;
        }

        .message {
            border-radius: 16px;
            padding: 14px 16px;
            margin: 20px 0;
            border: 1px solid transparent;
        }

        .message.error {
            background: rgba(255, 140, 122, 0.12);
            border-color: rgba(255, 140, 122, 0.28);
            color: var(--error);
        }

        .message.success {
            background: rgba(116, 217, 159, 0.12);
            border-color: rgba(116, 217, 159, 0.25);
            color: var(--success);
        }

        .card {
            border: 1px solid var(--line);
            border-radius: 20px;
            padding: 18px;
            background: rgba(255, 255, 255, 0.03);
            margin-top: 20px;
        }

        .meta-label {
            display: block;
            margin-bottom: 6px;
            font-size: 12px;
            font-family: 'JetBrains Mono', monospace;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--accent);
        }

        .meta-value {
            font-size: 15px;
            font-weight: 600;
            word-break: break-word;
        }

        form {
            margin-top: 22px;
            display: grid;
            gap: 16px;
        }

        label {
            display: grid;
            gap: 8px;
            font-size: 14px;
            font-weight: 600;
        }

        input {
            width: 100%;
            padding: 14px 15px;
            border-radius: 14px;
            border: 1px solid rgba(169, 192, 214, 0.18);
            background: rgba(255, 255, 255, 0.06);
            color: var(--text);
            font: inherit;
        }

        input:focus {
            outline: 2px solid rgba(244, 184, 96, 0.26);
            border-color: rgba(244, 184, 96, 0.34);
        }

        .button-row {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 8px;
        }

        .button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 48px;
            padding: 0 18px;
            border-radius: 999px;
            border: 1px solid transparent;
            text-decoration: none;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
        }

        .button.primary {
            background: linear-gradient(135deg, #f4b860 0%, #ffc97d 100%);
            color: #18232e;
        }

        .button.secondary {
            border-color: var(--line);
            background: rgba(255, 255, 255, 0.04);
            color: var(--text);
        }

        code {
            font-family: 'JetBrains Mono', monospace;
            background: rgba(255, 255, 255, 0.08);
            padding: 2px 6px;
            border-radius: 6px;
        }
    </style>
</head>
<body>
    <main class="panel">
        <h1>Recover the first super-admin</h1>
        <p>This is the local break-glass path for the first <code>super-admin</code>. It requires the recovery code stored in the local database and a new strong password.</p>

        <?php if ($errorMessage !== null): ?>
            <div class="message error"><?= htmlspecialchars($errorMessage) ?></div>
        <?php endif; ?>

        <?php if ($completed !== null): ?>
            <div class="message success">Recovery succeeded. All active sessions for the first super-admin were revoked and the recovery code was rotated.</div>
            <div class="button-row">
                <a class="button primary" href="/login.php?recovered=1">Return to login</a>
            </div>
        <?php else: ?>
            <div class="card">
                <span class="meta-label">How to get the code</span>
                <div class="meta-value">Query the <code>bootstrap_recovery</code> table from the local database. This code is never shown in the web UI.</div>
            </div>

            <div class="card">
                <span class="meta-label">Stealth reminder</span>
                <div class="meta-value">Stealth is currently <?= !empty($status['stealth']['enabled']) ? 'enabled' : 'disabled' ?>. If it stays enabled, you still need the stealth key to reach normal login after recovery.</div>
            </div>

            <form method="post">
                <label>
                    Recovery code
                    <input type="text" name="recoveryCode" autocomplete="one-time-code" required>
                </label>

                <label>
                    New password
                    <input type="password" name="newPassword" autocomplete="new-password" required>
                </label>

                <label>
                    Confirm new password
                    <input type="password" name="confirmPassword" autocomplete="new-password" required>
                </label>

                <div class="button-row">
                    <button class="button primary" type="submit">Reset super-admin password</button>
                    <a class="button secondary" href="/login.php">Back to login</a>
                </div>
            </form>
        <?php endif; ?>
    </main>
</body>
</html>
