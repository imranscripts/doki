<?php
/**
 * playwright.php - Playwright Tests Page (placeholder)
 * 
 * Part of Doki v3 Architecture - Batch 1: Auth & UI Foundation
 * 
 * This is a placeholder that will be fully implemented in a future batch
 */

require_once __DIR__ . '/includes/middleware.php';
require_once __DIR__ . '/includes/Layout.php';

// Require authentication
$user = requireAuthentication();

// Create layout instance
$layout = new Layout($user, 'playwright');
$isAdmin = in_array($user['role'], ['admin', 'super-admin'], true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $layout->renderHead('Playwright Tests'); ?>
    <style>
        .coming-soon {
            text-align: center;
            padding: 80px 24px;
        }

        .coming-soon-icon {
            font-size: 64px;
            color: var(--accent-primary);
            margin-bottom: 24px;
            opacity: 0.7;
        }

        .coming-soon h2 {
            font-size: 24px;
            margin-bottom: 12px;
        }

        .coming-soon p {
            color: var(--text-secondary);
            margin-bottom: 24px;
        }

        .legacy-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            background: var(--accent-glow);
            border: 1px solid var(--accent-primary);
            border-radius: 8px;
            color: var(--accent-primary);
            text-decoration: none;
            font-weight: 500;
            transition: all 0.15s ease;
        }

        .legacy-link:hover {
            background: var(--accent-primary);
            color: var(--bg-primary);
        }

        .runtime-alert {
            display: none;
            gap: 16px;
            align-items: flex-start;
            padding: 16px;
            border-radius: 12px;
            border: 1px solid var(--border-primary);
            background: var(--bg-secondary);
            margin-bottom: 24px;
        }

        .runtime-alert.visible {
            display: flex;
        }

        .runtime-alert.warning {
            border-color: rgba(245, 158, 11, 0.4);
            background: rgba(245, 158, 11, 0.08);
        }

        .runtime-alert.error {
            border-color: rgba(239, 68, 68, 0.5);
            background: rgba(239, 68, 68, 0.08);
        }

        .runtime-alert-icon {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            color: var(--text-primary);
            background: rgba(15, 23, 42, 0.06);
        }

        .runtime-alert-content h3 {
            margin: 0 0 6px 0;
            font-size: 16px;
        }

        .runtime-alert-content p {
            margin: 0;
            color: var(--text-secondary);
            font-size: 14px;
        }

        .runtime-alert-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 10px;
            padding: 8px 14px;
            border-radius: 8px;
            border: 1px solid var(--border-primary);
            background: var(--bg-primary);
            color: var(--text-primary);
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.15s ease;
        }

        .runtime-alert-link:hover {
            border-color: var(--accent-primary);
            color: var(--accent-primary);
        }
    </style>
</head>
<body>
    <div class="app-container">
        <?php $layout->renderSidebar(); ?>

        <main class="main-content">
            <div class="page-header">
                <div>
                    <h1 class="page-title">Playwright Tests</h1>
                    <p class="page-subtitle">Run and manage your Playwright test suites</p>
                </div>
            </div>

            <div class="card">
                <div id="runtime-alert" class="runtime-alert" data-is-admin="<?= $isAdmin ? '1' : '0' ?>">
                    <div class="runtime-alert-icon">
                        <i id="runtime-alert-icon" class="fas fa-triangle-exclamation"></i>
                    </div>
                    <div class="runtime-alert-content">
                        <h3 id="runtime-alert-title">Playwright not ready</h3>
                        <p id="runtime-alert-message">Playwright cannot run right now.</p>
                        <?php if ($isAdmin): ?>
                            <a href="admin/apps.php" class="runtime-alert-link">
                                <i class="fas fa-tools"></i>
                                Open Admin Apps
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="coming-soon">
                    <div class="coming-soon-icon">
                        <i class="fas fa-theater-masks"></i>
                    </div>
                    <h2>New Playwright UI Coming Soon</h2>
                    <p>We're building a new, improved interface for Playwright test management.</p>
                    <a href="index.php" class="legacy-link">
                        <i class="fas fa-terminal"></i>
                        Use Legacy Interface
                    </a>
                </div>
            </div>
        </main>
    </div>
    <script>
        (function() {
            const alertEl = document.getElementById('runtime-alert');
            if (!alertEl) return;

            const iconEl = document.getElementById('runtime-alert-icon');
            const titleEl = document.getElementById('runtime-alert-title');
            const messageEl = document.getElementById('runtime-alert-message');
            const isAdmin = alertEl.dataset.isAdmin === '1';

            function showAlert(kind, iconClass, title, message) {
                alertEl.classList.add('visible');
                alertEl.classList.remove('warning', 'error');
                alertEl.classList.add(kind);
                if (iconEl) {
                    iconEl.className = 'fas ' + iconClass;
                }
                if (titleEl) titleEl.textContent = title;
                if (messageEl) messageEl.textContent = message;
            }

            async function checkRuntime() {
                try {
                    const res = await fetch('api/playwright.php?action=runtime-status');
                    const data = await res.json();
                    if (!data.success) return;

                    const status = data.status || {};
                    const dockerAvailable = status.dockerAvailable;
                    const imageExists = status.imageExists;
                    const buildStatus = status.buildStatus?.status;

                    if (dockerAvailable && imageExists && buildStatus !== 'building') {
                        return;
                    }

                    if (!dockerAvailable) {
                        showAlert(
                            'error',
                            'fa-triangle-exclamation',
                            'Playwright is unavailable',
                            isAdmin
                                ? 'Docker is not available on this host. Tests will not run until Docker is available.'
                                : 'Playwright cannot run right now. Please contact an administrator.'
                        );
                        return;
                    }

                    if (buildStatus === 'building') {
                        showAlert(
                            'warning',
                            'fa-spinner fa-spin',
                            'Playwright is getting ready',
                            'The runner image is building. Please try again in a minute.'
                        );
                        return;
                    }

                    if (buildStatus === 'failed') {
                        showAlert(
                            'error',
                            'fa-circle-xmark',
                            'Playwright build failed',
                            isAdmin
                                ? 'The runner image failed to build. Rebuild it in Admin > Apps.'
                                : 'Playwright cannot run right now. Please contact an administrator.'
                        );
                        return;
                    }

                    if (!imageExists) {
                        showAlert(
                            'warning',
                            'fa-hammer',
                            'Playwright not ready',
                            isAdmin
                                ? 'The runner image has not been built yet. Build it in Admin > Apps before running tests.'
                                : 'Playwright cannot run yet. Please contact an administrator.'
                        );
                    }
                } catch (e) {
                    console.error('Playwright runtime check failed', e);
                }
            }

            checkRuntime();
        })();
    </script>
</body>
</html>
