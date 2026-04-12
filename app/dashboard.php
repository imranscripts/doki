<?php
/**
 * dashboard.php - Main Dashboard
 * 
 * Part of Doki v3 Architecture - Batch 1: Auth & UI Foundation
 */

require_once __DIR__ . '/includes/middleware.php';
require_once __DIR__ . '/includes/Layout.php';

// Require authentication and module access
$user = requireModuleAccess('dashboard');

// Create layout instance
$layout = new Layout($user, 'dashboard');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $layout->renderHead('Dashboard'); ?>
    <style>
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 32px;
        }

        .stat-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-primary);
            border-radius: 12px;
            padding: 24px;
            display: flex;
            align-items: flex-start;
            gap: 16px;
            transition: all 0.15s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-card:hover {
            border-color: var(--accent-primary);
            transform: translateY(-2px);
        }

        .stat-card.placeholder {
            padding-top: 52px;
            background:
                linear-gradient(180deg, rgba(210, 153, 34, 0.12), transparent 110px),
                var(--bg-secondary);
            border-color: rgba(210, 153, 34, 0.28);
        }

        .stat-card.placeholder::before {
            content: '';
            position: absolute;
            inset: 0 auto auto 0;
            width: 100%;
            height: 10px;
            background: repeating-linear-gradient(
                135deg,
                rgba(210, 153, 34, 0.85) 0 14px,
                rgba(24, 24, 27, 0.08) 14px 28px
            );
            opacity: 0.5;
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .stat-icon.blue {
            background: var(--accent-glow);
            color: var(--accent-primary);
        }

        .stat-icon.green {
            background: rgba(63, 185, 80, 0.15);
            color: var(--success);
        }

        .stat-icon.yellow {
            background: rgba(210, 153, 34, 0.15);
            color: var(--warning);
        }

        .stat-icon.purple {
            background: rgba(139, 92, 246, 0.15);
            color: #a78bfa;
        }

        .stat-info {
            flex: 1;
        }

        .stat-value {
            font-size: 28px;
            font-weight: 700;
            line-height: 1;
            margin-bottom: 4px;
        }

        .stat-label {
            font-size: 14px;
            color: var(--text-secondary);
        }

        .stat-badge {
            position: absolute;
            top: 18px;
            right: 18px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            border-radius: 999px;
            background: rgba(210, 153, 34, 0.16);
            color: var(--warning);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .stat-footnote {
            margin-top: 10px;
            font-size: 12px;
            color: var(--text-muted);
            line-height: 1.5;
        }

        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
        }

        .action-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-primary);
            border-radius: 12px;
            padding: 20px;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 16px;
            transition: all 0.15s ease;
        }

        .action-card:not(.disabled):hover {
            background: var(--bg-tertiary);
            border-color: var(--accent-primary);
            transform: translateY(-2px);
        }

        .action-card.disabled {
            opacity: 0.72;
            cursor: not-allowed;
            filter: saturate(0.75);
            border-style: dashed;
        }

        .action-card.disabled .action-icon {
            color: var(--text-muted);
            background: rgba(255, 255, 255, 0.03);
        }

        .action-icon {
            width: 44px;
            height: 44px;
            background: var(--bg-tertiary);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            color: var(--accent-primary);
        }

        .action-info h3 {
            font-size: 15px;
            font-weight: 600;
            margin-bottom: 2px;
        }

        .action-info p {
            font-size: 13px;
            color: var(--text-muted);
        }

        .action-state {
            margin-top: 8px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 9px;
            border-radius: 999px;
            background: rgba(210, 153, 34, 0.16);
            color: var(--warning);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .recent-activity {
            margin-top: 32px;
        }

        .activity-item {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 16px 0;
            border-bottom: 1px solid var(--border-primary);
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-icon {
            width: 36px;
            height: 36px;
            background: var(--bg-tertiary);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-secondary);
        }

        .activity-info {
            flex: 1;
        }

        .activity-text {
            font-size: 14px;
        }

        .activity-text strong {
            color: var(--accent-primary);
        }

        .activity-time {
            font-size: 13px;
            color: var(--text-muted);
        }

        .welcome-banner {
            position: relative;
            overflow: hidden;
            background:
                radial-gradient(circle at top right, rgba(210, 153, 34, 0.28), transparent 34%),
                linear-gradient(135deg, rgba(210, 153, 34, 0.18), rgba(59, 130, 246, 0.08));
            border: 1px solid rgba(210, 153, 34, 0.28);
            border-radius: 16px;
            padding: 32px;
            margin-bottom: 32px;
            display: flex;
            align-items: stretch;
            justify-content: space-between;
            gap: 24px;
        }

        .welcome-banner::before {
            content: '';
            position: absolute;
            inset: 0 0 auto 0;
            height: 14px;
            background: repeating-linear-gradient(
                135deg,
                rgba(210, 153, 34, 0.85) 0 18px,
                rgba(24, 24, 27, 0.12) 18px 36px
            );
            opacity: 0.45;
        }

        .welcome-content {
            max-width: 640px;
            padding-top: 18px;
        }

        .welcome-status {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 14px;
            padding: 7px 12px;
            border-radius: 999px;
            background: rgba(210, 153, 34, 0.16);
            color: var(--warning);
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .welcome-content h2 {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .welcome-content p {
            color: var(--text-secondary);
            font-size: 15px;
            max-width: 58ch;
        }

        .welcome-panel {
            min-width: 280px;
            max-width: 340px;
            padding: 22px 20px;
            border-radius: 14px;
            background: rgba(15, 23, 42, 0.28);
            border: 1px solid rgba(255, 255, 255, 0.08);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.04);
            backdrop-filter: blur(6px);
        }

        .welcome-panel-title {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 14px;
            font-size: 14px;
            font-weight: 700;
        }

        .construction-list {
            margin: 0;
            padding-left: 18px;
            color: var(--text-secondary);
            font-size: 13px;
            line-height: 1.6;
        }

        .construction-list li + li {
            margin-top: 8px;
        }

        .construction-note {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            margin-bottom: 32px;
            padding: 18px 20px;
            border-radius: 14px;
            background: rgba(59, 130, 246, 0.08);
            border: 1px solid rgba(59, 130, 246, 0.18);
            color: var(--text-secondary);
        }

        .construction-note strong {
            display: block;
            color: var(--text-primary);
            margin-bottom: 4px;
        }

        .construction-note i {
            margin-top: 2px;
            color: var(--accent-primary);
        }

        .card-title-row {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 16px;
            width: 100%;
        }

        .card-caption {
            color: var(--text-muted);
            font-size: 13px;
            line-height: 1.5;
        }

        @media (max-width: 768px) {
            .welcome-banner {
                flex-direction: column;
            }

            .welcome-content {
                padding-top: 10px;
            }

            .welcome-panel {
                max-width: none;
            }

            .card-title-row {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <?php $layout->renderSidebar(); ?>

        <main class="main-content">
            <!-- Welcome Banner -->
            <div class="welcome-banner">
                <div class="welcome-content">
                    <div class="welcome-status">
                        <i class="fas fa-tools"></i>
                        Under construction
                    </div>
                    <h2>Your dashboard is cooking, <?= htmlspecialchars($user['name']) ?></h2>
                    <p>Soon you will have app widgets, quick actions, and more right here. For now, this page is just wearing a hard hat and looking important.</p>
                </div>
                <div class="welcome-panel">
                    <div class="welcome-panel-title">
                        <i class="fas fa-info-circle"></i>
                        Coming soon
                    </div>
                    <ul class="construction-list">
                        <li>App widgets for your favorite tools</li>
                        <li>Quick actions for everyday tasks</li>
                        <li>More handy dashboard magic</li>
                    </ul>
                </div>
            </div>

            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card placeholder">
                    <div class="stat-badge">Sample data</div>
                    <div class="stat-icon blue">
                        <i class="fas fa-terminal"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-value" id="commandsCount">--</div>
                        <div class="stat-label">Available Commands</div>
                        <div class="stat-footnote">Sample KPI for the preview layout.</div>
                    </div>
                </div>

                <div class="stat-card placeholder">
                    <div class="stat-badge">Sample data</div>
                    <div class="stat-icon green">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-value" id="successRate">--</div>
                        <div class="stat-label">Test Success Rate</div>
                        <div class="stat-footnote">Sample KPI for the preview layout.</div>
                    </div>
                </div>

                <div class="stat-card placeholder">
                    <div class="stat-badge">Sample data</div>
                    <div class="stat-icon purple">
                        <i class="fas fa-theater-masks"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-value" id="testsCount">--</div>
                        <div class="stat-label">Playwright Tests</div>
                        <div class="stat-footnote">Sample KPI for the preview layout.</div>
                    </div>
                </div>

                <?php if (hasRole('admin')): ?>
                <div class="stat-card placeholder">
                    <div class="stat-badge">Sample data</div>
                    <div class="stat-icon yellow">
                        <i class="fas fa-server"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-value" id="targetsCount">--</div>
                        <div class="stat-label">Configured Targets</div>
                        <div class="stat-footnote">Sample KPI for the preview layout.</div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <div class="construction-note">
                <i class="fas fa-info-circle"></i>
                <div>
                    <strong>Dashboard glow-up in progress</strong>
                    Soon this page will be packed with app widgets, quick actions, and more. Right now it is just here to hold the shape and look cute.
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title-row">
                        <h2 class="card-title">Quick Actions</h2>
                        <div class="card-caption">These are parked for now while the real dashboard lands.</div>
                    </div>
                </div>
                <div class="quick-actions">
                    <div class="action-card disabled" aria-disabled="true">
                        <div class="action-icon">
                            <i class="fas fa-play"></i>
                        </div>
                        <div class="action-info">
                            <h3>Run Command</h3>
                            <p>Execute a DevOps command</p>
                            <div class="action-state">
                                <i class="fas fa-ban"></i>
                                Coming soon
                            </div>
                        </div>
                    </div>

                    <div class="action-card disabled" aria-disabled="true">
                        <div class="action-icon">
                            <i class="fas fa-theater-masks"></i>
                        </div>
                        <div class="action-info">
                            <h3>Run Tests</h3>
                            <p>Execute Playwright tests</p>
                            <div class="action-state">
                                <i class="fas fa-ban"></i>
                                Coming soon
                            </div>
                        </div>
                    </div>

                    <div class="action-card disabled" aria-disabled="true">
                        <div class="action-icon">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <div class="action-info">
                            <h3>View Reports</h3>
                            <p>Check test results</p>
                            <div class="action-state">
                                <i class="fas fa-ban"></i>
                                Coming soon
                            </div>
                        </div>
                    </div>

                    <?php if (hasRole('admin')): ?>
                    <div class="action-card disabled" aria-disabled="true">
                        <div class="action-icon">
                            <i class="fas fa-cog"></i>
                        </div>
                        <div class="action-info">
                            <h3>Configure</h3>
                            <p>Manage targets & secrets</p>
                            <div class="action-state">
                                <i class="fas fa-ban"></i>
                                Coming soon
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="card recent-activity">
                <div class="card-header">
                    <div class="card-title-row">
                        <h2 class="card-title">Recent Activity</h2>
                        <div class="card-caption">A tiny sneak peek while the real home screen gets assembled.</div>
                    </div>
                </div>
                <div id="activityList">
                    <div class="activity-item">
                        <div class="activity-icon">
                            <i class="fas fa-spinner fa-spin"></i>
                        </div>
                        <div class="activity-info">
                            <div class="activity-text">Loading activity...</div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Load dashboard data
        document.addEventListener('DOMContentLoaded', () => {
            loadStats();
            loadActivity();
        });

        async function loadStats() {
            // For now, show placeholder values
            // These would come from actual API calls in a full implementation
            document.getElementById('commandsCount').textContent = '12';
            document.getElementById('successRate').textContent = '94%';
            document.getElementById('testsCount').textContent = '8';
            
            const targetsEl = document.getElementById('targetsCount');
            if (targetsEl) {
                targetsEl.textContent = '3';
            }
        }

        async function loadActivity() {
            const activityList = document.getElementById('activityList');
            
            // Show placeholder activity for now
            activityList.innerHTML = `
                <div class="activity-item">
                    <div class="activity-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="activity-info">
                        <div class="activity-text">Soon this space will show app widgets, quick actions, and the latest useful bits from across Doki.</div>
                        <div class="activity-time">Coming soon</div>
                    </div>
                </div>
                <div class="activity-item">
                    <div class="activity-icon">
                        <i class="fas fa-info-circle"></i>
                    </div>
                    <div class="activity-info">
                        <div class="activity-text">Until then, this dashboard is mostly vibes, scaffolding, and excellent future potential.</div>
                        <div class="activity-time">Still assembling</div>
                    </div>
                </div>
            `;
        }
    </script>
</body>
</html>
