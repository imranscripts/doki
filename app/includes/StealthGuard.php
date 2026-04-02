<?php
/**
 * StealthGuard.php - Hidden login utilities
 *
 * Part of Doki v3 Architecture - Batch 11: Security Hardening
 */

require_once __DIR__ . '/SystemSettingsManager.php';
require_once __DIR__ . '/session-bootstrap.php';

class StealthGuard {
    private const KEY_DIR = __DIR__ . '/../data/keys';
    private const SECRET_PATH = __DIR__ . '/../data/keys/stealth.secret';
    private const SECRET_WORD_COUNT = 2;
    private const SECRET_WORDS = [
        'amber', 'anchor', 'anvil', 'apple', 'apricot', 'arbor', 'ashen', 'aster', 'atlas', 'autumn',
        'badger', 'basil', 'basket', 'beacon', 'berry', 'biscuit', 'blossom', 'bluejay', 'bonfire', 'border',
        'breeze', 'bridge', 'brook', 'bucket', 'cabin', 'cactus', 'camel', 'candle', 'canvas', 'carrot',
        'castle', 'cedar', 'celery', 'cherry', 'chisel', 'cinder', 'cinnamon', 'citrus', 'clover', 'cobalt',
        'copper', 'coral', 'cosmos', 'cotton', 'cricket', 'crimson', 'cumin', 'curtain', 'dahlia', 'dancer',
        'dandelion', 'dawn', 'delta', 'desert', 'dinner', 'drift', 'dune', 'eagle', 'earth', 'ebony',
        'echo', 'ember', 'falcon', 'fennel', 'fern', 'field', 'finch', 'flame', 'flint', 'floral',
        'flower', 'forest', 'foxglove', 'frost', 'garden', 'garlic', 'ginger', 'glacier', 'glimmer', 'golden',
        'grain', 'granite', 'grove', 'harbor', 'hazel', 'heather', 'heron', 'hickory', 'honey', 'horizon',
        'indigo', 'island', 'ivory', 'jasmine', 'juniper', 'kettle', 'kindle', 'lagoon', 'lantern', 'laurel',
        'lavender', 'lemon', 'lemur', 'lilac', 'linen', 'lizard', 'lotus', 'magnet', 'maple', 'marble',
        'market', 'meadow', 'melon', 'mercury', 'meteor', 'midnight', 'minnow', 'mirror', 'misty', 'monarch',
        'moss', 'mountain', 'nectar', 'needle', 'night', 'nutmeg', 'oasis', 'oatmeal', 'ocean', 'olive',
        'onyx', 'orchid', 'otter', 'paper', 'pebble', 'pepper', 'petal', 'pickle', 'pillow', 'pine',
        'planet', 'plaza', 'pocket', 'prairie', 'quartz', 'quill', 'rabbit', 'raisin', 'raven', 'reef',
        'resin', 'ribbon', 'river', 'robin', 'rocket', 'rose', 'saddle', 'saffron', 'sage', 'sailor',
        'scarlet', 'shadow', 'silver', 'sketch', 'sleet', 'sorbet', 'sparrow', 'spindle', 'spirit', 'spring',
        'spruce', 'starling', 'stone', 'storm', 'stream', 'summer', 'sunrise', 'sunset', 'tablet', 'teacup',
        'thicket', 'thistle', 'thunder', 'timber', 'turtle', 'umber', 'valley', 'velvet', 'violet', 'walnut',
        'wander', 'water', 'weaver', 'whisper', 'willow', 'window', 'winter', 'wren', 'yarrow', 'zephyr',
    ];

    public static function isEnabled(): bool {
        return (bool)(self::getRuntimeSettings()['enabled'] ?? true);
    }

    public static function getSecret(): string {
        if (!file_exists(self::SECRET_PATH) || !is_readable(self::SECRET_PATH)) {
            throw new RuntimeException('Stealth secret unavailable');
        }
        $secret = trim((string)file_get_contents(self::SECRET_PATH));
        if ($secret === '') {
            throw new RuntimeException('Stealth secret unavailable');
        }
        return $secret;
    }

    public static function getSecretPath(): string {
        return self::SECRET_PATH;
    }

    public static function getWindowSeconds(): int {
        return (int)(self::getRuntimeSettings()['windowSeconds'] ?? 120);
    }

    public static function getKeystrokeTtl(): int {
        return (int)(self::getRuntimeSettings()['keystrokeTtl'] ?? 30);
    }

    public static function getMaxAttempts(): int {
        return (int)(self::getRuntimeSettings()['maxAttempts'] ?? 5);
    }

    public static function getLockoutBaseMinutes(): int {
        return (int)(self::getRuntimeSettings()['lockoutBaseMinutes'] ?? 5);
    }

    public static function getLockoutMaxMinutes(): int {
        return (int)(self::getRuntimeSettings()['lockoutMaxMinutes'] ?? 60);
    }

    public static function getRuntimeSettings(): array {
        try {
            return (new SystemSettingsManager())->getRuntimeStealthSettings();
        } catch (Throwable $e) {
            return [
                'enabled' => true,
                'windowSeconds' => 120,
                'keystrokeTtl' => 30,
                'maxAttempts' => 5,
                'lockoutBaseMinutes' => 5,
                'lockoutMaxMinutes' => 60,
                'configured' => [
                    'enabled' => true,
                    'windowSeconds' => 120,
                    'keystrokeTtl' => 30,
                    'maxAttempts' => 5,
                    'lockoutBaseMinutes' => 5,
                    'lockoutMaxMinutes' => 60,
                ],
                'overrideSources' => [
                    'enabled' => 'settings',
                    'windowSeconds' => 'settings',
                    'keystrokeTtl' => 'settings',
                    'maxAttempts' => 'settings',
                    'lockoutBaseMinutes' => 'settings',
                    'lockoutMaxMinutes' => 'settings',
                ],
            ];
        }
    }

    public static function isSecretReady(): bool {
        if (!file_exists(self::SECRET_PATH) || !is_readable(self::SECRET_PATH)) {
            return false;
        }
        return trim((string)@file_get_contents(self::SECRET_PATH)) !== '';
    }

    public static function ensureSecretFile(): bool {
        if (self::isSecretReady()) {
            return true;
        }

        self::createSecretFile();
        return self::isSecretReady();
    }

    public static function rotateSecret(): bool {
        if (file_exists(self::SECRET_PATH) && !@unlink(self::SECRET_PATH)) {
            return false;
        }

        self::createSecretFile();
        return file_exists(self::SECRET_PATH) && trim((string)@file_get_contents(self::SECRET_PATH)) !== '';
    }

    public static function isSessionUnlocked(): bool {
        if (!self::isEnabled()) {
            return true;
        }

        if (session_status() === PHP_SESSION_NONE) {
            doki_start_session();
        }

        $unlocked = !empty($_SESSION['stealth']['unlocked']);
        $unlockTime = (int)($_SESSION['stealth']['unlock_time'] ?? 0);
        if (!$unlocked || (time() - $unlockTime) > self::getWindowSeconds()) {
            $_SESSION['stealth']['unlocked'] = false;
            return false;
        }

        return true;
    }

    private static function createSecretFile(): void {
        if (!is_dir(self::KEY_DIR)) {
            @mkdir(self::KEY_DIR, 0700, true);
        }
        if (!is_writable(self::KEY_DIR)) {
            return;
        }

        $secret = self::generateWordSecret();
        file_put_contents(self::SECRET_PATH, $secret . "\n");
        @chmod(self::SECRET_PATH, 0600);
    }

    private static function generateWordSecret(): string {
        $words = self::SECRET_WORDS;
        $count = count($words);
        if ($count < self::SECRET_WORD_COUNT) {
            $raw = random_bytes(18);
            return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
        }

        $selected = [];
        $used = [];
        while (count($selected) < self::SECRET_WORD_COUNT) {
            $index = random_int(0, $count - 1);
            if (isset($used[$index])) {
                continue;
            }
            $used[$index] = true;
            $selected[] = $words[$index];
        }

        return implode('-', $selected);
    }
}
