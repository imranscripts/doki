<?php
/**
 * admin/updates.php - Doki release updates
 */

require_once __DIR__ . '/../includes/middleware.php';
require_once __DIR__ . '/../includes/Layout.php';

$user = requireRole('super-admin');
requireModuleAccess('doki.update');

$layout = new Layout($user, 'doki.update');

function doki_update_helper_url(): string {
    $url = trim((string)(getenv('DOKI_UPDATE_HELPER_URL') ?: ''));
    return $url !== '' ? rtrim($url, '/') : 'http://localhost:8100';
}

function doki_update_token_path(): string {
    return __DIR__ . '/../data/update-helper/tokens.json';
}

function doki_update_read_tokens(string $path): array {
    if (!is_file($path)) {
        return ['tokens' => []];
    }

    $decoded = json_decode((string)file_get_contents($path), true);
    return is_array($decoded) ? $decoded : ['tokens' => []];
}

function doki_update_mint_token(array $user): string {
    $path = doki_update_token_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }

    $token = bin2hex(random_bytes(32));
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $expires = $now->modify('+2 hours');
    $data = doki_update_read_tokens($path);
    $records = is_array($data['tokens'] ?? null) ? $data['tokens'] : [];
    $records = array_values(array_filter($records, static function ($record) use ($now): bool {
        if (!is_array($record)) {
            return false;
        }
        $expiresAt = (string)($record['expiresAt'] ?? '');
        if ($expiresAt === '') {
            return false;
        }
        try {
            return new DateTimeImmutable($expiresAt) > $now;
        } catch (Throwable $e) {
            return false;
        }
    }));

    $records[] = [
        'hash' => hash('sha256', $token),
        'createdAt' => $now->format(DateTimeInterface::ATOM),
        'expiresAt' => $expires->format(DateTimeInterface::ATOM),
        'createdBy' => (string)($user['id'] ?? ''),
        'createdByUsername' => (string)($user['username'] ?? 'super-admin'),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
    ];

    file_put_contents($path, json_encode(['tokens' => array_slice($records, -20)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
    @chmod($path, 0600);
    return $token;
}

$helperUrl = doki_update_helper_url();
$token = doki_update_mint_token($user);
$launchUrl = $helperUrl . '/?token=' . rawurlencode($token);
$currentVersion = trim((string)@file_get_contents(__DIR__ . '/../VERSION')) ?: 'unknown';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $layout->renderHead('Doki Updates'); ?>
    <style>
        .page-shell {
            padding: 28px;
            max-width: 1500px;
            margin: 0 auto;
        }

        .page-header {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 22px;
        }

        .page-title {
            margin: 0;
            font-size: 32px;
            font-weight: 700;
        }

        .page-subtitle {
            margin: 8px 0 0;
            max-width: 820px;
            color: var(--text-secondary);
            line-height: 1.5;
        }

        .helper-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
            justify-content: flex-end;
        }

        .helper-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border: 1px solid var(--border-primary);
            border-radius: 999px;
            color: var(--text-secondary);
            background: var(--bg-secondary);
            font-size: 13px;
            white-space: nowrap;
        }

        .helper-frame-wrap {
            border: 1px solid var(--border-primary);
            border-radius: 10px;
            overflow: hidden;
            background: var(--bg-secondary);
            min-height: 760px;
        }

        .helper-frame {
            display: block;
            width: 100%;
            height: min(82vh, 980px);
            min-height: 760px;
            border: 0;
            background: #0d1117;
        }

        .helper-note {
            margin: 0 0 14px;
            color: var(--text-secondary);
            line-height: 1.45;
            font-size: 13px;
        }

        @media (max-width: 900px) {
            .page-shell {
                padding: 18px;
            }

            .page-header {
                align-items: flex-start;
                flex-direction: column;
            }

            .helper-actions {
                justify-content: flex-start;
            }
        }
    </style>
</head>
<body>
    <?php $layout->renderSidebar(); ?>
    <main class="main-content">
        <div class="content-wrapper">
            <div class="page-shell">
                <div class="page-header">
                    <div>
                        <h1 class="page-title">Doki Updates</h1>
                        <p class="page-subtitle">
                            Review stable releases, check health, run migration dry-runs, and apply Doki updates from the host-side helper.
                        </p>
                    </div>
                    <div class="helper-actions">
                        <span class="helper-chip">
                            <i class="fas fa-code-branch"></i>
                            Current <?= htmlspecialchars($currentVersion) ?>
                        </span>
                        <a class="btn btn-secondary" href="<?= htmlspecialchars($launchUrl) ?>" target="_blank" rel="noopener">
                            <i class="fas fa-up-right-from-square"></i>
                            Open Helper
                        </a>
                    </div>
                </div>

                <p class="helper-note">
                    The embedded helper runs separately from the main Doki app, so it can continue reporting progress while core services restart.
                    Its access token expires after two hours and is only minted for super-admins.
                </p>

                <div class="helper-frame-wrap">
                    <iframe
                        class="helper-frame"
                        src="<?= htmlspecialchars($launchUrl) ?>"
                        title="Doki Update Helper"
                        referrerpolicy="no-referrer"
                    ></iframe>
                </div>
            </div>
        </div>
    </main>
</body>
</html>
