<?php
/**
 * Playwright App - Settings Page
 * 
 * Configure Playwright settings:
 * - Storage settings (reports dir, retention)
 * - Test defaults (timeout, retries, browser)
 * - Notifications (webhooks)
 * - Git providers (GitHub, GitLab tokens)
 * - Test environments (staging, production URLs)
 */

require_once __DIR__ . '/../../includes/AppContext.php';

$app = new AppContext('playwright');
$user = $app->requireRole('admin');  // Only admins can access settings
$layout = $app->getLayout();
$settings = $app->getSettings();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $layout->renderHead('Playwright - Settings'); ?>
    <style>
        .app-header {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 32px;
            padding-bottom: 24px;
            border-bottom: 1px solid var(--border-color);
        }

        .app-icon {
            width: 56px;
            height: 56px;
            border-radius: 12px;
            background: linear-gradient(135deg, #a855f7 0%, #7c3aed 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
        }

        .app-title h1 {
            font-size: 24px;
            margin: 0 0 4px 0;
        }

        .app-title p {
            color: var(--text-secondary);
            margin: 0;
            font-size: 14px;
        }

        .app-nav {
            display: flex;
            gap: 8px;
            margin-bottom: 24px;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 12px;
        }

        .app-nav-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 8px;
            color: var(--text-secondary);
            text-decoration: none;
            transition: all 0.2s;
        }

        .app-nav-item:hover {
            background: var(--bg-tertiary);
            color: var(--text-primary);
        }

        .app-nav-item.active {
            background: var(--accent-primary);
            color: white;
        }

        .settings-container {
            display: grid;
            gap: 24px;
            max-width: 900px;
        }

        .settings-section {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            overflow: hidden;
        }

        .section-header {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px 20px;
            background: var(--bg-tertiary);
            border-bottom: 1px solid var(--border-color);
        }

        .section-header i {
            font-size: 18px;
            color: var(--accent-primary);
        }

        .section-header h2 {
            font-size: 16px;
            font-weight: 600;
            margin: 0;
        }

        .section-content {
            padding: 20px;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 16px;
        }

        .form-row:last-child {
            margin-bottom: 0;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .form-label {
            font-size: 13px;
            font-weight: 500;
            color: var(--text-secondary);
        }

        .form-input, .form-select {
            padding: 10px 12px;
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            color: var(--text-primary);
            font-size: 14px;
        }

        .form-input:focus, .form-select:focus {
            outline: none;
            border-color: var(--accent-primary);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }

        .form-checkbox {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
        }

        .form-checkbox input {
            width: 18px;
            height: 18px;
            accent-color: var(--accent-primary);
        }

        .form-hint {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 4px;
        }

        /* List items (git providers, environments) */
        .list-container {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .list-item {
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 16px;
        }

        .list-item-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
        }

        .list-item-title {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
        }

        .list-item-title i {
            color: var(--text-secondary);
        }

        .list-item-actions {
            display: flex;
            gap: 8px;
        }

        .btn-icon {
            width: 32px;
            height: 32px;
            border: none;
            border-radius: 6px;
            background: var(--bg-tertiary);
            color: var(--text-secondary);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }

        .btn-icon:hover {
            background: var(--accent-primary);
            color: white;
        }

        .btn-icon.btn-danger:hover {
            background: var(--danger);
        }

        .add-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px;
            background: transparent;
            border: 2px dashed var(--border-color);
            border-radius: 8px;
            color: var(--text-secondary);
            cursor: pointer;
            transition: all 0.2s;
        }

        .add-btn:hover {
            border-color: var(--accent-primary);
            color: var(--accent-primary);
            background: rgba(99, 102, 241, 0.05);
        }

        /* Actions bar */
        .actions-bar {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            padding-top: 24px;
            margin-top: 24px;
            border-top: 1px solid var(--border-color);
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }

        .btn-primary {
            background: var(--accent-primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--accent-hover);
        }

        .btn-secondary {
            background: var(--bg-tertiary);
            color: var(--text-primary);
            border: 1px solid var(--border-color);
        }

        .btn-secondary:hover {
            background: var(--bg-primary);
        }

        /* Provider type badges */
        .provider-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .provider-badge.github {
            background: rgba(36, 41, 47, 0.1);
            color: #24292f;
        }

        .provider-badge.gitlab {
            background: rgba(226, 67, 41, 0.1);
            color: #e24329;
        }

        .provider-badge.bitbucket {
            background: rgba(0, 82, 204, 0.1);
            color: #0052cc;
        }

        /* Environment color indicator */
        .env-color {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            flex-shrink: 0;
        }

        /* Token field */
        .token-field {
            display: flex;
            gap: 8px;
        }

        .token-field .form-input {
            flex: 1;
            font-family: monospace;
        }

        .token-toggle {
            padding: 10px 12px;
            background: var(--bg-tertiary);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            color: var(--text-secondary);
            cursor: pointer;
        }

        .token-toggle:hover {
            color: var(--text-primary);
        }
    </style>
</head>
<body>
    <?php $layout->renderSidebar(); ?>
    
    <main class="main-content">
        <div class="content-area">
            <!-- App Header & Navigation (from AppContext) -->
            <?php $app->renderAppHeader(); ?>

            <!-- Settings Form -->
            <div id="settings-app">
                <div class="settings-container">
                    <!-- Storage Settings -->
                    <section class="settings-section">
                        <div class="section-header">
                            <i class="fas fa-database"></i>
                            <h2>Storage</h2>
                        </div>
                        <div class="section-content">
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Reports Directory</label>
                                    <input type="text" class="form-input" v-model="settings.storage.reportsDir">
                                    <span class="form-hint">Where HTML reports are stored</span>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Results Directory</label>
                                    <input type="text" class="form-input" v-model="settings.storage.resultsDir">
                                    <span class="form-hint">Where test artifacts are stored</span>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Max Reports to Keep</label>
                                    <input type="number" class="form-input" v-model.number="settings.storage.maxReports" min="1">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Retention (Days)</label>
                                    <input type="number" class="form-input" v-model.number="settings.storage.retentionDays" min="1">
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- Test Defaults -->
                    <section class="settings-section">
                        <div class="section-header">
                            <i class="fas fa-sliders-h"></i>
                            <h2>Test Defaults</h2>
                        </div>
                        <div class="section-content">
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Timeout (ms)</label>
                                    <input type="number" class="form-input" v-model.number="settings.defaults.timeout" min="1000" step="1000">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Retries</label>
                                    <input type="number" class="form-input" v-model.number="settings.defaults.retries" min="0">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Workers</label>
                                    <input type="number" class="form-input" v-model.number="settings.defaults.workers" min="1">
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Default Browser</label>
                                    <select class="form-select" v-model="settings.defaults.browser">
                                        <option value="chromium">Chromium</option>
                                        <option value="firefox">Firefox</option>
                                        <option value="webkit">WebKit</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">&nbsp;</label>
                                    <label class="form-checkbox">
                                        <input type="checkbox" v-model="settings.defaults.headless">
                                        Run in headless mode
                                    </label>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- Notifications -->
                    <section class="settings-section">
                        <div class="section-header">
                            <i class="fas fa-bell"></i>
                            <h2>Notifications</h2>
                        </div>
                        <div class="section-content">
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-checkbox">
                                        <input type="checkbox" v-model="settings.notifications.enabled">
                                        Enable notifications
                                    </label>
                                </div>
                            </div>
                            <div class="form-row" v-if="settings.notifications.enabled">
                                <div class="form-group">
                                    <label class="form-checkbox">
                                        <input type="checkbox" v-model="settings.notifications.onFailure">
                                        Notify on test failure
                                    </label>
                                </div>
                                <div class="form-group">
                                    <label class="form-checkbox">
                                        <input type="checkbox" v-model="settings.notifications.onSuccess">
                                        Notify on test success
                                    </label>
                                </div>
                            </div>
                            <div class="form-row" v-if="settings.notifications.enabled">
                                <div class="form-group" style="grid-column: 1 / -1;">
                                    <label class="form-label">Webhook URL</label>
                                    <input type="url" class="form-input" v-model="settings.notifications.webhookUrl" placeholder="https://hooks.slack.com/...">
                                    <span class="form-hint">Slack, Discord, or custom webhook endpoint</span>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- Git Providers - Now managed in Doki Sources -->
                    <section class="settings-section">
                        <div class="section-header">
                            <i class="fab fa-git-alt"></i>
                            <h2>Git Providers</h2>
                        </div>
                        <div class="section-content">
                            <div style="padding: 24px; background: var(--bg-tertiary); border-radius: 12px; text-align: center;">
                                <i class="fas fa-code-branch" style="font-size: 48px; color: var(--text-muted); margin-bottom: 16px;"></i>
                                <h3 style="margin: 0 0 8px 0;">Git Providers Moved</h3>
                                <p style="color: var(--text-secondary); margin: 0 0 16px 0;">
                                    Git providers are now managed centrally in <strong>Doki Sources</strong>.<br>
                                    This allows sharing providers across Apps, Templates, and Playwright projects.
                                </p>
                                <a href="../../admin/sources.php" class="btn btn-primary" style="display: inline-flex;">
                                    <i class="fas fa-external-link-alt"></i>
                                    Go to Doki Sources
                                </a>
                            </div>
                        </div>
                    </section>

                    <!-- Test Environments -->
                    <section class="settings-section">
                        <div class="section-header">
                            <i class="fas fa-globe"></i>
                            <h2>Test Environments</h2>
                        </div>
                        <div class="section-content">
                            <div class="list-container">
                                <div v-for="(env, index) in settings.testEnvironments" :key="index" class="list-item">
                                    <div class="list-item-header">
                                        <div class="list-item-title">
                                            <span class="env-color" :style="{ background: env.color || '#6b7280' }"></span>
                                            <span>{{ env.name || env.id || 'New Environment' }}</span>
                                        </div>
                                        <div class="list-item-actions">
                                            <button class="btn-icon btn-danger" @click="removeEnvironment(index)" title="Remove">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label class="form-label">ID</label>
                                            <input type="text" class="form-input" v-model="env.id" placeholder="staging">
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Name</label>
                                            <input type="text" class="form-input" v-model="env.name" placeholder="Staging">
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Color</label>
                                            <input type="color" class="form-input" v-model="env.color" style="height: 42px; padding: 4px;">
                                        </div>
                                    </div>
                                    <div class="form-row">
                                        <div class="form-group" style="grid-column: 1 / -1;">
                                            <label class="form-label">Base URL</label>
                                            <input type="url" class="form-input" v-model="env.baseUrl" placeholder="https://staging.example.com">
                                        </div>
                                    </div>
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label class="form-label">Username (optional)</label>
                                            <input type="text" class="form-input" v-model="env.username" placeholder="test-user">
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Password (optional)</label>
                                            <div class="token-field">
                                                <input 
                                                    :type="env.showPassword ? 'text' : 'password'" 
                                                    class="form-input" 
                                                    v-model="env.password"
                                                >
                                                <button type="button" class="token-toggle" @click="env.showPassword = !env.showPassword">
                                                    <i :class="env.showPassword ? 'fas fa-eye-slash' : 'fas fa-eye'"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="form-row">
                                        <div class="form-group" style="grid-column: 1 / -1;">
                                            <label class="form-label">Extra Variables (JSON)</label>
                                            <input type="text" class="form-input" v-model="env.extraVars" placeholder='{"API_KEY": "xxx"}'>
                                            <span class="form-hint">Additional environment variables for tests</span>
                                        </div>
                                    </div>
                                </div>

                                <button class="add-btn" @click="addEnvironment">
                                    <i class="fas fa-plus"></i>
                                    Add Test Environment
                                </button>
                            </div>
                        </div>
                    </section>
                </div>

                <!-- Actions Bar -->
                <div class="actions-bar">
                    <button class="btn btn-secondary" @click="resetSettings">
                        <i class="fas fa-undo"></i>
                        Reset
                    </button>
                    <button class="btn btn-primary" @click="saveSettings" :disabled="saving">
                        <i class="fas fa-save"></i>
                        {{ saving ? 'Saving...' : 'Save Settings' }}
                    </button>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/vue@3/dist/vue.global.prod.js"></script>
    <script>
        const initialSettings = <?php echo json_encode($settings); ?>;

        const app = Vue.createApp({
            data() {
                return {
                    settings: JSON.parse(JSON.stringify(initialSettings)),
                    originalSettings: JSON.parse(JSON.stringify(initialSettings)),
                    saving: false
                };
            },

            methods: {
                // Note: Git Providers are now managed in Admin > Sources (Batch 13)

                addEnvironment() {
                    if (!this.settings.testEnvironments) {
                        this.settings.testEnvironments = [];
                    }
                    this.settings.testEnvironments.push({
                        id: '',
                        name: '',
                        color: '#6b7280',
                        baseUrl: '',
                        username: '',
                        password: '',
                        extraVars: '',
                        showPassword: false
                    });
                },

                removeEnvironment(index) {
                    if (confirm('Remove this test environment?')) {
                        this.settings.testEnvironments.splice(index, 1);
                    }
                },

                resetSettings() {
                    if (confirm('Reset all settings to last saved values?')) {
                        this.settings = JSON.parse(JSON.stringify(this.originalSettings));
                    }
                },

                async saveSettings() {
                    this.saving = true;

                    // Clean up showToken/showPassword flags before saving
                    const cleanSettings = JSON.parse(JSON.stringify(this.settings));
                    if (cleanSettings.gitProviders) {
                        cleanSettings.gitProviders.forEach(p => delete p.showToken);
                    }
                    if (cleanSettings.testEnvironments) {
                        cleanSettings.testEnvironments.forEach(e => delete e.showPassword);
                    }

                    try {
                        const response = await fetch('../../api/apps.php?action=save-settings&app=playwright', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify(cleanSettings)
                        });

                        const data = await response.json();

                        if (data.success) {
                            window.Toast.success('Settings saved successfully');
                            this.originalSettings = JSON.parse(JSON.stringify(cleanSettings));
                        } else {
                            const errorMsg = data.errors ? data.errors.join(', ') : (data.error || 'Unknown error');
                            window.Toast.error('Failed to save: ' + errorMsg);
                        }
                    } catch (error) {
                        window.Toast.error('Failed to save settings: ' + error.message);
                        console.error('Save error:', error);
                    } finally {
                        this.saving = false;
                    }
                }
            },

            mounted() {
                // Ensure arrays exist
                if (!this.settings.gitProviders) this.settings.gitProviders = [];
                if (!this.settings.testEnvironments) this.settings.testEnvironments = [];
            }
        });

        app.mount('#settings-app');
    </script>
</body>
</html>
