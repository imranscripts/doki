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
        }

        .stat-card:hover {
            border-color: var(--accent-primary);
            transform: translateY(-2px);
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
            text-decoration: none;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 16px;
            transition: all 0.15s ease;
        }

        .action-card:hover {
            background: var(--bg-tertiary);
            border-color: var(--accent-primary);
            transform: translateY(-2px);
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
            background: linear-gradient(135deg, var(--accent-glow), rgba(139, 92, 246, 0.1));
            border: 1px solid var(--border-primary);
            border-radius: 16px;
            padding: 32px;
            margin-bottom: 32px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .welcome-content h2 {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .welcome-content p {
            color: var(--text-secondary);
            font-size: 15px;
        }

        .welcome-art {
            font-size: 64px;
            opacity: 0.3;
        }

        @media (max-width: 768px) {
            .welcome-banner {
                flex-direction: column;
                text-align: center;
            }

            .welcome-art {
                margin-top: 16px;
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
                    <h2>Welcome back, <?= htmlspecialchars($user['name']) ?> 👋</h2>
                    <p>Here's what's happening with your DevOps operations today.</p>
                </div>
                <div class="welcome-art">
                    <i class="fas fa-terminal"></i>
                </div>
            </div>

            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon blue">
                        <i class="fas fa-terminal"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-value" id="commandsCount">--</div>
                        <div class="stat-label">Available Commands</div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon green">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-value" id="successRate">--</div>
                        <div class="stat-label">Test Success Rate</div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon purple">
                        <i class="fas fa-theater-masks"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-value" id="testsCount">--</div>
                        <div class="stat-label">Playwright Tests</div>
                    </div>
                </div>

                <?php if (hasRole('admin')): ?>
                <div class="stat-card">
                    <div class="stat-icon yellow">
                        <i class="fas fa-server"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-value" id="targetsCount">--</div>
                        <div class="stat-label">Configured Targets</div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Quick Actions -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Quick Actions</h2>
                </div>
                <div class="quick-actions">
                    <a href="commands.php" class="action-card">
                        <div class="action-icon">
                            <i class="fas fa-play"></i>
                        </div>
                        <div class="action-info">
                            <h3>Run Command</h3>
                            <p>Execute a DevOps command</p>
                        </div>
                    </a>

                    <a href="playwright.php" class="action-card">
                        <div class="action-icon">
                            <i class="fas fa-theater-masks"></i>
                        </div>
                        <div class="action-info">
                            <h3>Run Tests</h3>
                            <p>Execute Playwright tests</p>
                        </div>
                    </a>

                    <a href="reports.php" class="action-card">
                        <div class="action-icon">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <div class="action-info">
                            <h3>View Reports</h3>
                            <p>Check test results</p>
                        </div>
                    </a>

                    <?php if (hasRole('admin')): ?>
                    <a href="config/targets.php" class="action-card">
                        <div class="action-icon">
                            <i class="fas fa-cog"></i>
                        </div>
                        <div class="action-info">
                            <h3>Configure</h3>
                            <p>Manage targets & secrets</p>
                        </div>
                    </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="card recent-activity">
                <div class="card-header">
                    <h2 class="card-title">Recent Activity</h2>
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
                        <i class="fas fa-sign-in-alt"></i>
                    </div>
                    <div class="activity-info">
                        <div class="activity-text">You signed in</div>
                        <div class="activity-time">Just now</div>
                    </div>
                </div>
                <div class="activity-item">
                    <div class="activity-icon">
                        <i class="fas fa-info-circle"></i>
                    </div>
                    <div class="activity-info">
                        <div class="activity-text">Activity tracking will be available after full implementation</div>
                        <div class="activity-time">--</div>
                    </div>
                </div>
            `;
        }
    </script>
</body>
</html>
