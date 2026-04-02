<?php
/**
 * onboarding.php - First-run setup wizard
 */

require_once __DIR__ . '/includes/session-bootstrap.php';
require_once __DIR__ . '/includes/OnboardingDebugLogger.php';

if (session_status() === PHP_SESSION_NONE) {
    doki_start_session();
}

require_once __DIR__ . '/includes/OnboardingManager.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

$onboarding = new OnboardingManager();
$completedResult = null;
$errorMessage = null;

OnboardingDebugLogger::log('page.load', [
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
    'state' => $onboarding->getState(),
    'sessionId' => session_id(),
    'wizardStep' => $_POST['wizardStep'] ?? null,
    'postKeys' => array_keys($_POST),
]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    OnboardingDebugLogger::log('post.received', [
        'sessionId' => session_id(),
        'wizardStep' => $_POST['wizardStep'] ?? null,
        'username' => $_POST['username'] ?? null,
        'enableStealth' => !empty($_POST['enableStealth']),
        'enableApps' => !empty($_POST['enableApps']),
        'enableWorkflows' => !empty($_POST['enableWorkflows']),
        'enableAppStudio' => !empty($_POST['enableAppStudio']),
        'enableAiFeatures' => !empty($_POST['enableAiFeatures']),
    ]);
    $completedResult = $onboarding->completeOnboarding($_POST);
    if (empty($completedResult['success'])) {
        OnboardingDebugLogger::log('post.failed', [
            'sessionId' => session_id(),
            'error' => $completedResult['error'] ?? 'unknown',
        ]);
        $errorMessage = $completedResult['error'] ?? 'Unable to complete onboarding.';
        $completedResult = null;
    } elseif (!empty($completedResult['redirect'])) {
        OnboardingDebugLogger::log('post.redirect', [
            'sessionId' => session_id(),
            'redirect' => (string)$completedResult['redirect'],
        ]);
        header('Location: ' . (string)$completedResult['redirect']);
        exit;
    }
}

if ($completedResult === null && !$onboarding->requiresOnboarding()) {
    OnboardingDebugLogger::log('page.auto_redirect_login', [
        'sessionId' => session_id(),
    ]);
    header('Location: /login.php');
    exit;
}

$status = $onboarding->getStatus();
$bootstrap = $status['bootstrap'];
$checks = $bootstrap['checks'] ?? [];
$blockingChecks = array_filter($checks, static fn(array $check): bool => !empty($check['blocking']) && empty($check['ok']));
$warningChecks = array_filter($checks, static fn(array $check): bool => empty($check['blocking']) && empty($check['ok']));

$posted = static function(string $key, string $default = ''): string {
    return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $default;
};

$checked = static function(string $key, bool $default): bool {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        return !empty($_POST[$key]);
    }
    return $default;
};

$defaults = [
    'username' => $posted('username', 'admin'),
    'name' => $posted('name', 'System Administrator'),
    'email' => $posted('email'),
    'enableStealth' => $checked('enableStealth', (bool)($status['stealth']['enabled'] ?? true)),
    'enableWorkflows' => $checked('enableWorkflows', (bool)($status['modules']['workflows'] ?? true)),
    'enableApps' => $checked('enableApps', (bool)($status['modules']['apps'] ?? true)),
    'enableAppStudio' => $checked('enableAppStudio', (bool)($status['modules']['appStudio'] ?? true)),
    'enableAiFeatures' => $checked('enableAiFeatures', (bool)($status['modules']['aiFeatures'] ?? true)),
];

$wizardSteps = ['account', 'stealth', 'modules', 'finish'];
$initialStep = 'account';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $requestedStep = trim((string)($_POST['wizardStep'] ?? ''));
    if (in_array($requestedStep, $wizardSteps, true)) {
        $initialStep = $requestedStep;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Doki - Onboarding</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <noscript>
        <style>
            .wizard-panel {
                display: block !important;
                min-height: 0 !important;
                margin-bottom: 18px;
            }

            .stepper,
            [data-next-step],
            [data-prev-step] {
                display: none !important;
            }

            .wizard-actions {
                justify-content: flex-start !important;
            }
        </style>
    </noscript>
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
            --shadow: 0 28px 60px rgba(0, 0, 0, 0.42);
        }

        * {
            box-sizing: border-box;
        }

        html {
            background: var(--bg-primary);
            color-scheme: dark;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: 'Outfit', sans-serif;
            color: var(--text-primary);
            background:
                radial-gradient(circle at top left, rgba(88, 166, 255, 0.18), transparent 28%),
                radial-gradient(circle at bottom right, rgba(56, 139, 253, 0.14), transparent 24%),
                linear-gradient(180deg, var(--bg-primary) 0%, #121821 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        .shell {
            width: min(720px, 100%);
        }

        .panel {
            background: linear-gradient(180deg, rgba(22, 27, 34, 0.96) 0%, rgba(13, 17, 23, 0.96) 100%);
            border: 1px solid var(--border-primary);
            border-radius: 28px;
            box-shadow: var(--shadow);
            backdrop-filter: blur(12px);
            padding: 32px;
        }

        .eyebrow {
            display: inline-block;
            margin-bottom: 12px;
            font-family: 'JetBrains Mono', monospace;
            font-size: 12px;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: var(--accent-primary);
        }

        .header h1 {
            margin: 0;
            font-size: 36px;
            line-height: 1.02;
        }

        .subtitle {
            margin: 12px 0 0;
            color: var(--text-secondary);
            line-height: 1.5;
        }

        .stepper {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 10px;
            margin: 28px 0 24px;
        }

        .step {
            padding: 12px 10px;
            border-radius: 16px;
            border: 1px solid var(--border-primary);
            background: rgba(33, 38, 45, 0.85);
            text-align: center;
            font-size: 13px;
            color: var(--text-secondary);
        }

        .step.is-active {
            border-color: rgba(88, 166, 255, 0.35);
            background: var(--accent-glow);
            color: var(--accent-primary);
            font-weight: 700;
        }

        .message {
            border-radius: 18px;
            padding: 14px 16px;
            margin-bottom: 20px;
            border: 1px solid transparent;
        }

        .message.error {
            background: rgba(248, 81, 73, 0.1);
            border-color: rgba(248, 81, 73, 0.24);
            color: var(--error);
        }

        .message.success {
            background: rgba(63, 185, 80, 0.12);
            border-color: rgba(63, 185, 80, 0.24);
            color: var(--success);
        }

        .wizard-panel {
            display: none;
            min-height: 340px;
        }

        .wizard-panel.is-active {
            display: block;
        }

        .wizard-panel h2 {
            margin: 0;
            font-size: 28px;
        }

        .wizard-copy {
            margin: 12px 0 0;
            color: var(--text-secondary);
            line-height: 1.55;
        }

        .section {
            margin-top: 24px;
            border: 1px solid var(--border-primary);
            border-radius: 22px;
            padding: 20px;
            background: rgba(22, 27, 34, 0.82);
        }

        .field-grid {
            display: grid;
            gap: 14px;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .field,
        .toggle-list,
        .summary-list,
        .issues-list {
            display: grid;
            gap: 12px;
        }

        .field.full {
            grid-column: 1 / -1;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-size: 14px;
            font-weight: 600;
        }

        input[type="text"],
        input[type="email"],
        input[type="password"] {
            width: 100%;
            border: 1px solid var(--border-primary);
            border-radius: 16px;
            padding: 14px 15px;
            font: inherit;
            background: var(--bg-tertiary);
            color: var(--text-primary);
        }

        .input-wrapper {
            position: relative;
        }

        .input-wrapper input {
            padding-right: 52px;
        }

        .password-reveal {
            display: none;
            width: 100%;
            min-height: 50px;
            border: 1px solid var(--border-primary);
            border-radius: 16px;
            padding: 14px 52px 14px 15px;
            font: inherit;
            background: var(--bg-tertiary);
            color: var(--text-primary);
            line-height: 1.4;
            overflow-x: auto;
            white-space: nowrap;
            user-select: text;
        }

        .input-wrapper.is-password-visible .password-input.actual {
            display: none;
        }

        .input-wrapper.is-password-visible .password-reveal {
            display: block;
        }

        .password-toggle {
            position: absolute;
            top: 50%;
            right: 12px;
            transform: translateY(-50%);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            border: 0;
            border-radius: 10px;
            background: transparent;
            color: var(--text-muted);
            cursor: pointer;
            z-index: 2;
            transition: background 0.2s ease, color 0.2s ease;
        }

        .password-toggle:hover {
            background: rgba(240, 246, 252, 0.08);
            color: var(--text-primary);
        }

        .password-toggle:focus-visible {
            outline: 2px solid rgba(88, 166, 255, 0.28);
            outline-offset: 1px;
        }

        .password-toggle svg {
            width: 18px;
            height: 18px;
            stroke: currentColor;
            fill: none;
            stroke-width: 1.8;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .password-toggle .icon-hide {
            display: none;
        }

        .password-toggle.is-visible .icon-show {
            display: none;
        }

        .password-toggle.is-visible .icon-hide {
            display: block;
        }

        input::placeholder {
            color: var(--text-muted);
        }

        input:focus {
            outline: 2px solid rgba(88, 166, 255, 0.2);
            border-color: rgba(88, 166, 255, 0.45);
        }

        .field-help,
        .field-note,
        .toggle-copy,
        .summary-copy,
        .issue-copy {
            color: var(--text-secondary);
            line-height: 1.5;
        }

        .field-note {
            font-size: 14px;
        }

        .toggle {
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 12px;
            padding: 14px 16px;
            border: 1px solid var(--border-primary);
            border-radius: 16px;
            background: rgba(33, 38, 45, 0.78);
        }

        .toggle input {
            margin-top: 4px;
            width: 18px;
            height: 18px;
            accent-color: var(--accent-primary);
        }

        .toggle-body {
            display: grid;
            gap: 4px;
            align-content: start;
        }

        .toggle-title {
            display: block;
            font-weight: 700;
        }

        .toggle-copy {
            display: block;
        }

        .summary-item,
        .issue-item {
            border: 1px solid var(--border-primary);
            border-radius: 16px;
            padding: 14px 16px;
            background: rgba(33, 38, 45, 0.76);
        }

        .stealth-guide {
            margin-top: 18px;
            border: 1px solid rgba(88, 166, 255, 0.22);
            border-radius: 18px;
            padding: 18px;
            background: rgba(88, 166, 255, 0.08);
        }

        .stealth-guide-title {
            margin: 0;
            font-size: 16px;
            font-weight: 700;
            color: var(--text-primary);
        }

        .stealth-guide-list {
            list-style: none;
            margin: 16px 0 0;
            padding: 0;
            display: grid;
            gap: 10px;
        }

        .stealth-guide-step {
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 12px;
            align-items: start;
            padding: 12px 14px;
            border: 1px solid rgba(88, 166, 255, 0.16);
            border-radius: 14px;
            background: rgba(13, 17, 23, 0.32);
        }

        .stealth-guide-number {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 28px;
            height: 28px;
            border-radius: 999px;
            background: rgba(88, 166, 255, 0.18);
            color: var(--accent-primary);
            font-family: 'JetBrains Mono', monospace;
            font-size: 13px;
            font-weight: 700;
        }

        .stealth-guide-step-title {
            display: block;
            font-weight: 700;
            color: var(--text-primary);
        }

        .stealth-guide-step-copy {
            display: block;
            margin-top: 4px;
            color: var(--text-secondary);
            line-height: 1.5;
        }

        .stealth-preview {
            margin-top: 16px;
            border: 1px solid var(--border-primary);
            border-radius: 18px;
            overflow: hidden;
            background: #d9dde3;
        }

        .stealth-preview-bar {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 14px;
            border-bottom: 1px solid #c2c8d0;
            background: #eef1f5;
        }

        .stealth-preview-dot {
            width: 9px;
            height: 9px;
            border-radius: 999px;
            background: #b3bac5;
        }

        .stealth-preview-url {
            margin-left: 6px;
            font-family: 'JetBrains Mono', monospace;
            font-size: 12px;
            color: #4f5968;
        }

        .stealth-preview-body {
            padding: 20px;
        }

        .stealth-preview-label {
            display: block;
            margin-bottom: 12px;
            font-family: 'JetBrains Mono', monospace;
            font-size: 12px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--text-muted);
        }

        .stealth-preview-page {
            border: 1px solid #d5d9df;
            border-radius: 14px;
            padding: 18px;
            background: #ffffff;
            color: #000000;
            font-family: Times, 'Times New Roman', serif;
        }

        .stealth-preview-page h3 {
            margin: 0;
            font-size: 2em;
            font-weight: 700;
            color: #000000;
        }

        .stealth-preview-page p {
            margin: 10px 0 0;
            color: #000000;
            line-height: 1.5;
        }

        .stealth-preview-page hr {
            border: 0;
            border-top: 1px solid #c7ccd3;
            margin: 16px 0 12px;
        }

        .stealth-preview-page address {
            font-style: normal;
            color: #000000;
            font-size: 14px;
        }

        .summary-label,
        .issue-label {
            display: block;
            margin-bottom: 6px;
            font-family: 'JetBrains Mono', monospace;
            font-size: 12px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--text-muted);
        }

        .summary-value {
            font-weight: 700;
        }

        .issue-item {
            border-color: rgba(248, 81, 73, 0.24);
            background: rgba(248, 81, 73, 0.08);
        }

        .wizard-actions {
            margin-top: 28px;
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: center;
        }

        .wizard-actions.end {
            justify-content: flex-start;
        }

        .actions-spacer {
            flex: 1;
        }

        .button-group {
            display: flex;
            gap: 12px;
        }

        .button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 48px;
            padding: 0 20px;
            border-radius: 999px;
            border: 1px solid transparent;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
        }

        .button.primary {
            background: linear-gradient(135deg, var(--accent-primary) 0%, var(--accent-secondary) 100%);
            color: #fff;
            box-shadow: 0 16px 32px rgba(56, 139, 253, 0.25);
        }

        .button.secondary {
            background: rgba(33, 38, 45, 0.92);
            border-color: var(--border-primary);
            color: var(--text-primary);
        }

        code {
            font-family: 'JetBrains Mono', monospace;
            background: rgba(88, 166, 255, 0.12);
            padding: 2px 6px;
            border-radius: 6px;
            font-size: 0.92em;
        }

        @media (max-width: 720px) {
            body {
                padding: 14px;
            }

            .panel {
                padding: 22px;
            }

            .stepper,
            .field-grid {
                grid-template-columns: 1fr;
            }

            .wizard-actions {
                flex-direction: column-reverse;
                align-items: stretch;
            }

            .button-group {
                width: 100%;
                justify-content: stretch;
            }

            .button {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="shell">
        <main class="panel">
            <header class="header">
                <span class="eyebrow">Doki setup</span>
                <h1>Finish setup</h1>
                <p class="subtitle">A few quick steps to create the first admin account.</p>
            </header>

            <?php if ($errorMessage !== null): ?>
                <div class="message error"><?= htmlspecialchars($errorMessage) ?></div>
            <?php endif; ?>

            <?php if (!$bootstrap['ready']): ?>
                <div class="message error">Setup is not ready yet. Fix the items below, then rerun <code>./setup.sh</code>.</div>
                <section class="section">
                    <h2>Setup issues</h2>
                    <div class="wizard-copy">These need to be fixed before the first admin account can be created.</div>
                    <div class="issues-list" style="margin-top: 20px;">
                        <?php foreach ($blockingChecks as $key => $check): ?>
                            <div class="issue-item">
                                <span class="issue-label"><?= htmlspecialchars($key) ?></span>
                                <div class="issue-copy"><?= htmlspecialchars((string)$check['message']) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
                <div class="wizard-actions end">
                    <a class="button secondary" href="/onboarding.php">Refresh checks</a>
                </div>
            <?php else: ?>
                <div class="stepper" aria-hidden="true">
                    <div class="step" data-step-indicator="account">1. Account</div>
                    <div class="step" data-step-indicator="stealth">2. Stealth</div>
                    <div class="step" data-step-indicator="modules">3. Modules</div>
                    <div class="step" data-step-indicator="finish">4. Finish</div>
                </div>

                <form method="post" id="onboardingWizard">
                    <input type="hidden" name="wizardStep" id="wizardStep" value="<?= htmlspecialchars($initialStep) ?>">

                    <section class="wizard-panel<?= $initialStep === 'account' ? ' is-active' : '' ?>" data-step="account">
                        <h2>Admin account</h2>
                        <p class="wizard-copy">Create the first super-admin for this install.</p>
                        <div class="section">
                            <div class="field-grid">
                                <div class="field">
                                    <label for="username">Username</label>
                                    <input id="username" name="username" type="text" value="<?= htmlspecialchars($defaults['username']) ?>" autocomplete="username" pattern="[A-Za-z0-9][A-Za-z0-9._-]{1,31}" required>
                                </div>
                                <div class="field">
                                    <label for="name">Display name</label>
                                    <input id="name" name="name" type="text" value="<?= htmlspecialchars($defaults['name']) ?>" autocomplete="name" required>
                                </div>
                                <div class="field full">
                                    <label for="email">Email</label>
                                    <input id="email" name="email" type="email" value="<?= htmlspecialchars($defaults['email']) ?>" autocomplete="email" placeholder="Optional">
                                </div>
                                <div class="field">
                                    <label for="password">Password</label>
                                    <div class="input-wrapper" data-password-pair="password">
                                        <input class="password-input actual" id="password" name="password" type="password" autocomplete="new-password" minlength="12" required>
                                        <div class="password-reveal" id="passwordVisible" aria-hidden="true"></div>
                                        <button class="password-toggle" type="button" onclick="toggleOnboardingPassword('password', this)" aria-label="Show password">
                                            <svg class="icon-show" viewBox="0 0 24 24" aria-hidden="true">
                                                <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6-10-6-10-6Z"></path>
                                                <circle cx="12" cy="12" r="3"></circle>
                                            </svg>
                                            <svg class="icon-hide" viewBox="0 0 24 24" aria-hidden="true">
                                                <path d="M3 3l18 18"></path>
                                                <path d="M10.6 10.7A3 3 0 0 0 12 15a3 3 0 0 0 2.3-1.1"></path>
                                                <path d="M9.5 5.3A11.3 11.3 0 0 1 12 5c6.5 0 10 7 10 7a18.5 18.5 0 0 1-4 4.8"></path>
                                                <path d="M6.6 6.7A18.3 18.3 0 0 0 2 12s3.5 7 10 7c1.7 0 3.1-.4 4.4-1"></path>
                                            </svg>
                                        </button>
                                    </div>
                                </div>
                                <div class="field">
                                    <label for="passwordConfirm">Confirm password</label>
                                    <div class="input-wrapper" data-password-pair="passwordConfirm">
                                        <input class="password-input actual" id="passwordConfirm" name="passwordConfirm" type="password" autocomplete="new-password" minlength="12" required>
                                        <div class="password-reveal" id="passwordConfirmVisible" aria-hidden="true"></div>
                                        <button class="password-toggle" type="button" onclick="toggleOnboardingPassword('passwordConfirm', this)" aria-label="Show password confirmation">
                                            <svg class="icon-show" viewBox="0 0 24 24" aria-hidden="true">
                                                <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6-10-6-10-6Z"></path>
                                                <circle cx="12" cy="12" r="3"></circle>
                                            </svg>
                                            <svg class="icon-hide" viewBox="0 0 24 24" aria-hidden="true">
                                                <path d="M3 3l18 18"></path>
                                                <path d="M10.6 10.7A3 3 0 0 0 12 15a3 3 0 0 0 2.3-1.1"></path>
                                                <path d="M9.5 5.3A11.3 11.3 0 0 1 12 5c6.5 0 10 7 10 7a18.5 18.5 0 0 1-4 4.8"></path>
                                                <path d="M6.6 6.7A18.3 18.3 0 0 0 2 12s3.5 7 10 7c1.7 0 3.1-.4 4.4-1"></path>
                                            </svg>
                                        </button>
                                    </div>
                                </div>
                                <div class="field-note full">Use 12+ characters with mixed case and a number.</div>
                            </div>
                        </div>
                        <div class="wizard-actions">
                            <div class="actions-spacer"></div>
                            <div class="button-group">
                                <button class="button primary" type="button" data-next-step="stealth">Next</button>
                            </div>
                        </div>
                    </section>

                    <section class="wizard-panel<?= $initialStep === 'stealth' ? ' is-active' : '' ?>" data-step="stealth">
                        <h2>Stealth mode</h2>
                        <p class="wizard-copy">Stealth hides the normal login page behind a fake not found page.</p>
                        <div class="stealth-guide">
                            <h3 class="stealth-guide-title">How login works when stealth is on</h3>
                            <ol class="stealth-guide-list">
                                <li class="stealth-guide-step">
                                    <span class="stealth-guide-number">1</span>
                                    <span>
                                        <span class="stealth-guide-step-title">Open your Doki URL</span>
                                        <span class="stealth-guide-step-copy">You go to Doki normally, but you do not see the login form yet.</span>
                                    </span>
                                </li>
                                <li class="stealth-guide-step">
                                    <span class="stealth-guide-number">2</span>
                                    <span>
                                        <span class="stealth-guide-step-title">You see a basic not found page</span>
                                        <span class="stealth-guide-step-copy">That plain page is expected. It is how Doki hides the login entrypoint.</span>
                                    </span>
                                </li>
                                <li class="stealth-guide-step">
                                    <span class="stealth-guide-number">3</span>
                                    <span>
                                        <span class="stealth-guide-step-title">Type the stealth key on that page</span>
                                        <span class="stealth-guide-step-copy">Use the key that <code>setup.sh</code> printed. There is no visible input box.</span>
                                    </span>
                                </li>
                                <li class="stealth-guide-step">
                                    <span class="stealth-guide-number">4</span>
                                    <span>
                                        <span class="stealth-guide-step-title">Login unlocks for your session</span>
                                        <span class="stealth-guide-step-copy">After that, you can reach the normal login page and sign in.</span>
                                    </span>
                                </li>
                            </ol>
                        </div>
                        <div class="section">
                            <div class="toggle-list">
                                <label class="toggle">
                                    <input type="hidden" name="enableStealth" value="0">
                                    <input type="checkbox" name="enableStealth" value="1" <?= $defaults['enableStealth'] ? 'checked' : '' ?>>
                                    <span class="toggle-body">
                                        <span class="toggle-title">Keep stealth enabled</span>
                                        <span class="toggle-copy">Leave this on if you want login hidden behind that extra step.</span>
                                    </span>
                                </label>
                            </div>
                            <div class="stealth-preview" aria-hidden="true">
                                <div class="stealth-preview-bar">
                                    <span class="stealth-preview-dot"></span>
                                    <span class="stealth-preview-dot"></span>
                                    <span class="stealth-preview-dot"></span>
                                    <span class="stealth-preview-url">https://your-doki/</span>
                                </div>
                                <div class="stealth-preview-body">
                                    <span class="stealth-preview-label">What visitors see before you type the key</span>
                                    <div class="stealth-preview-page">
                                        <h3>Not Found</h3>
                                        <p>The requested URL was not found on this server.</p>
                                        <hr>
                                        <address>Apache/2.4 Server at localhost Port 80</address>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="wizard-actions">
                            <div class="button-group">
                                <button class="button secondary" type="button" data-prev-step="account">Back</button>
                            </div>
                            <div class="button-group">
                                <button class="button primary" type="button" data-next-step="modules">Next</button>
                            </div>
                        </div>
                    </section>

                    <section class="wizard-panel<?= $initialStep === 'modules' ? ' is-active' : '' ?>" data-step="modules">
                        <h2>Quick-start modules</h2>
                        <p class="wizard-copy">Choose what should be enabled on first login.</p>
                        <div class="section">
                            <div class="toggle-list">
                                <label class="toggle">
                                    <input type="hidden" name="enableApps" value="0">
                                    <input type="checkbox" name="enableApps" value="1" <?= $defaults['enableApps'] ? 'checked' : '' ?>>
                                    <span class="toggle-body">
                                        <span class="toggle-title">Apps</span>
                                        <span class="toggle-copy">Apps hub and installed app runtime features.</span>
                                    </span>
                                </label>
                                <label class="toggle">
                                    <input type="hidden" name="enableWorkflows" value="0">
                                    <input type="checkbox" name="enableWorkflows" value="1" <?= $defaults['enableWorkflows'] ? 'checked' : '' ?>>
                                    <span class="toggle-body">
                                        <span class="toggle-title">Workflows</span>
                                        <span class="toggle-copy">Commands, templates, and history.</span>
                                    </span>
                                </label>
                                <label class="toggle">
                                    <input type="hidden" name="enableAppStudio" value="0">
                                    <input type="checkbox" name="enableAppStudio" value="1" <?= $defaults['enableAppStudio'] ? 'checked' : '' ?>>
                                    <span class="toggle-body">
                                        <span class="toggle-title">App Studio</span>
                                        <span class="toggle-copy">Workspace-based app building and previews.</span>
                                    </span>
                                </label>
                                <label class="toggle">
                                    <input type="hidden" name="enableAiFeatures" value="0">
                                    <input type="checkbox" name="enableAiFeatures" value="1" <?= $defaults['enableAiFeatures'] ? 'checked' : '' ?>>
                                    <span class="toggle-body">
                                        <span class="toggle-title">AI feature</span>
                                        <span class="toggle-copy">AI providers plus AI tools inside App Studio.</span>
                                    </span>
                                </label>
                            </div>
                        </div>
                        <div class="wizard-actions">
                            <div class="button-group">
                                <button class="button secondary" type="button" data-prev-step="stealth">Back</button>
                            </div>
                            <div class="button-group">
                                <button class="button primary" type="button" data-next-step="finish">Next</button>
                            </div>
                        </div>
                    </section>

                    <section class="wizard-panel<?= $initialStep === 'finish' ? ' is-active' : '' ?>" data-step="finish">
                        <h2>Finish setup</h2>
                        <p class="wizard-copy">Review the essentials, then create the account.</p>
                        <div class="section">
                            <div class="summary-list">
                                <div class="summary-item">
                                    <span class="summary-label">Admin</span>
                                    <div class="summary-value" data-summary="username"><?= htmlspecialchars($defaults['username']) ?></div>
                                </div>
                                <div class="summary-item">
                                    <span class="summary-label">Stealth</span>
                                    <div class="summary-value" data-summary="stealth"><?= $defaults['enableStealth'] ? 'Enabled' : 'Disabled' ?></div>
                                    <div class="summary-copy">If enabled, open the not found page and type the stealth key from <code>setup.sh</code>.</div>
                                </div>
                                <div class="summary-item">
                                    <span class="summary-label">Modules</span>
                                    <div class="summary-value" data-summary="modules">Apps, Workflows, App Studio, AI feature</div>
                                </div>
                            </div>
                        </div>
                        <div class="wizard-actions">
                            <div class="button-group">
                                <button class="button secondary" type="button" data-prev-step="modules">Back</button>
                            </div>
                            <div class="button-group">
                                <a class="button secondary" href="/onboarding.php">Reset</a>
                                <button class="button primary" type="submit">Finish onboarding</button>
                            </div>
                        </div>
                    </section>
                </form>
            <?php endif; ?>
        </main>
    </div>

    <?php if ($completedResult === null && !empty($bootstrap['ready'])): ?>
        <script>
        (() => {
            const steps = ['account', 'stealth', 'modules', 'finish'];
            const pendingCredentialStorageKey = 'doki.onboarding.credentials';
            const debugEndpoint = '/api/onboarding-debug.php';
            const form = document.getElementById('onboardingWizard');
            if (!form) {
                return;
            }

            function logClient(event, payload = {}) {
                const body = JSON.stringify({
                    event,
                    step: hiddenStep ? hiddenStep.value : null,
                    href: window.location.href,
                    ...payload,
                });

                try {
                    if (navigator.sendBeacon) {
                        navigator.sendBeacon(debugEndpoint, new Blob([body], { type: 'application/json' }));
                        return;
                    }
                } catch (_) {}

                try {
                    fetch(debugEndpoint, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        keepalive: true,
                        body,
                    }).catch(() => {});
                } catch (_) {}
            }

            try {
                window.sessionStorage.removeItem(pendingCredentialStorageKey);
            } catch (_) {}

            const hiddenStep = document.getElementById('wizardStep');
            const panels = new Map();
            document.querySelectorAll('[data-step]').forEach((panel) => {
                panels.set(panel.dataset.step, panel);
            });

            const indicators = new Map();
            document.querySelectorAll('[data-step-indicator]').forEach((indicator) => {
                indicators.set(indicator.dataset.stepIndicator, indicator);
            });

            const usernameInput = document.getElementById('username');
            const passwordInput = document.getElementById('password');
            const stealthInput = form.querySelector('input[type="checkbox"][name="enableStealth"]');
            const moduleInputs = [
                form.querySelector('input[type="checkbox"][name="enableApps"]'),
                form.querySelector('input[type="checkbox"][name="enableWorkflows"]'),
                form.querySelector('input[type="checkbox"][name="enableAppStudio"]'),
                form.querySelector('input[type="checkbox"][name="enableAiFeatures"]'),
            ];

            const summaryUsername = form.querySelector('[data-summary="username"]');
            const summaryStealth = form.querySelector('[data-summary="stealth"]');
            const summaryModules = form.querySelector('[data-summary="modules"]');
            const finishSubmitButton = form.querySelector('button[type="submit"]');
            let programmaticSubmit = false;

            logClient('page_ready');

            function currentModulesLabel() {
                const labels = [];
                if (moduleInputs[0] && moduleInputs[0].checked) labels.push('Apps');
                if (moduleInputs[1] && moduleInputs[1].checked) labels.push('Workflows');
                if (moduleInputs[2] && moduleInputs[2].checked) labels.push('App Studio');
                if (moduleInputs[3] && moduleInputs[3].checked) labels.push('AI feature');
                return labels.length > 0 ? labels.join(', ') : 'None';
            }

            function updateSummary() {
                if (summaryUsername && usernameInput) {
                    summaryUsername.textContent = usernameInput.value.trim() || 'Admin account';
                }
                if (summaryStealth && stealthInput) {
                    summaryStealth.textContent = stealthInput.checked ? 'Enabled' : 'Disabled';
                }
                if (summaryModules) {
                    summaryModules.textContent = currentModulesLabel();
                }
            }

            function showStep(step) {
                if (!steps.includes(step)) {
                    step = 'account';
                }

                panels.forEach((panel, key) => {
                    panel.classList.toggle('is-active', key === step);
                });

                indicators.forEach((indicator, key) => {
                    indicator.classList.toggle('is-active', key === step);
                });

                hiddenStep.value = step;
                updateSummary();
                logClient('step_shown', { step });
            }

            function validateAccountStep() {
                const accountPanel = panels.get('account');
                if (!accountPanel) {
                    return true;
                }

                const controls = accountPanel.querySelectorAll('input');
                for (const control of controls) {
                    if (!control.reportValidity()) {
                        return false;
                    }
                }

                const password = document.getElementById('password');
                const passwordConfirm = document.getElementById('passwordConfirm');
                if (password && passwordConfirm && password.value !== passwordConfirm.value) {
                    passwordConfirm.setCustomValidity('Passwords must match.');
                    passwordConfirm.reportValidity();
                    return false;
                }

                if (passwordConfirm) {
                    passwordConfirm.setCustomValidity('');
                }

                return true;
            }

            form.querySelectorAll('[data-next-step]').forEach((button) => {
                button.addEventListener('click', () => {
                    const target = button.getAttribute('data-next-step') || 'account';
                    if (hiddenStep.value === 'account' && !validateAccountStep()) {
                        logClient('next_blocked_validation', {
                            step: hiddenStep.value || 'account',
                        });
                        return;
                    }
                    logClient('next_clicked', {
                        from: hiddenStep.value || 'account',
                        to: target,
                    });
                    showStep(target);
                });
            });

            form.querySelectorAll('[data-prev-step]').forEach((button) => {
                button.addEventListener('click', () => {
                    const target = button.getAttribute('data-prev-step') || 'account';
                    logClient('back_clicked', {
                        from: hiddenStep.value || 'account',
                        to: target,
                    });
                    showStep(target);
                });
            });

            function offerBrowserPasswordSave(username, password) {
                if (username === '' || password === '') {
                    return;
                }

                if (!('PasswordCredential' in window) || !navigator.credentials || typeof navigator.credentials.store !== 'function') {
                    return;
                }

                try {
                    const credential = new PasswordCredential({
                        id: username,
                        password,
                        name: username,
                    });
                    navigator.credentials.store(credential).catch(() => {});
                } catch (_) {}
            }

            form.addEventListener('submit', (event) => {
                if (programmaticSubmit) {
                    logClient('submit_programmatic_bypass');
                    return;
                }

                event.preventDefault();

                hiddenStep.value = 'finish';
                const username = usernameInput ? usernameInput.value.trim() : '';
                const password = passwordInput ? passwordInput.value : '';
                logClient('submit_intercepted', {
                    username,
                    hasPassword: password !== '',
                });

                if (usernameInput && passwordInput) {
                    try {
                        window.sessionStorage.setItem(pendingCredentialStorageKey, JSON.stringify({
                            username,
                            password,
                            savedAt: Date.now(),
                        }));
                    } catch (_) {}
                }

                offerBrowserPasswordSave(username, password);
                logClient('password_save_requested', {
                    username,
                });

                if (finishSubmitButton) {
                    finishSubmitButton.disabled = true;
                }

                programmaticSubmit = true;
                logClient('form_submit_called', {
                    username,
                });
                form.submit();
            });

            if (usernameInput) {
                usernameInput.addEventListener('input', updateSummary);
            }
            if (stealthInput) {
                stealthInput.addEventListener('change', updateSummary);
            }
            moduleInputs.forEach((input) => {
                if (input) {
                    input.addEventListener('change', updateSummary);
                }
            });

            showStep(hiddenStep.value || 'account');
        })();

        function toggleOnboardingPassword(inputId, button) {
            const input = document.getElementById(inputId);
            if (!input || !button) {
                return;
            }

            const wrapper = button.closest('.input-wrapper');
            const visibleInput = document.getElementById(inputId + 'Visible');
            if (!wrapper || !visibleInput) {
                return;
            }

            const visible = wrapper.classList.contains('is-password-visible');
            if (!visible) {
                visibleInput.textContent = input.value;
            }

            if (document.activeElement && typeof document.activeElement.blur === 'function') {
                document.activeElement.blur();
            }

            wrapper.classList.toggle('is-password-visible', !visible);
            button.classList.toggle('is-visible', !visible);
            button.setAttribute('aria-pressed', visible ? 'false' : 'true');
            button.setAttribute('aria-label', visible ? 'Show password' : 'Hide password');
            button.focus();
        }

        document.addEventListener('DOMContentLoaded', () => {
            ['password', 'passwordConfirm'].forEach((inputId) => {
                const actual = document.getElementById(inputId);
                const mirror = document.getElementById(inputId + 'Visible');
                if (!actual || !mirror) {
                    return;
                }

                const sync = () => {
                    mirror.textContent = actual.value;
                };

                actual.addEventListener('input', sync);
                actual.addEventListener('change', sync);
                sync();
            });
        });
        </script>
    <?php endif; ?>
</body>
</html>
