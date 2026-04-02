<?php
/**
 * SecretsStore.php - Centralized encrypted secrets storage
 *
 * Part of Doki v3 Architecture - Batch 11: Security Hardening
 *
 * Stores all secrets encrypted at rest using AES-256-GCM.
 * Key is stored on disk at app/data/keys/secrets.key (base64, 32 bytes).
 */

class SecretsStore {
    private const SECRETS_PATH = __DIR__ . '/../data/config/secrets.yaml';
    private const KEY_DIR = __DIR__ . '/../data/keys';
    private const KEY_PATH = __DIR__ . '/../data/keys/secrets.key';
    private const CIPHER = 'aes-256-gcm';
    private const IV_BYTES = 12;

    /**
     * Return current lock status for secrets store
     */
    public function getStatus(): array {
        $keyStatus = $this->getKeyStatus(false);
        if (!$keyStatus['ready']) {
            return [
                'locked' => true,
                'reason' => $keyStatus['reason'] ?? 'Secrets key unavailable'
            ];
        }

        return ['locked' => false, 'reason' => null];
    }

    /**
     * Load decrypted secrets from disk
     */
    public function loadSecrets(?callable $backupCallback = null): array {
        $this->requireKey();

        if (!file_exists(self::SECRETS_PATH)) {
            return [];
        }

        $content = file_get_contents(self::SECRETS_PATH);
        if ($content === false || trim($content) === '') {
            return [];
        }

        $data = yaml_parse($content);
        if ($data === false) {
            throw new RuntimeException('Failed to parse secrets file');
        }

        if ($this->isEncryptedEnvelope($data)) {
            return $this->decryptEnvelope($data);
        }

        // Plaintext detected - migrate to encrypted
        $secrets = $data['secrets'] ?? [];
        if (!is_array($secrets)) {
            throw new RuntimeException('Invalid secrets format');
        }

        if ($backupCallback) {
            $backupCallback();
        }

        $this->saveSecrets($secrets);
        return $secrets;
    }

    /**
     * Load decrypted secrets as YAML (for raw editor)
     */
    public function loadRawYaml(?callable $backupCallback = null): string {
        $secrets = $this->loadSecrets($backupCallback);
        $yaml = yaml_emit(['secrets' => $secrets], YAML_UTF8_ENCODING);
        if ($yaml === false) {
            throw new RuntimeException('Failed to render secrets YAML');
        }

        $yaml = preg_replace('/^---\n/', '', $yaml);
        $yaml = preg_replace('/\n\.\.\.$/', '', $yaml);
        return $yaml;
    }

    /**
     * Save secrets (encrypt and write)
     */
    public function saveSecrets(array $secrets): void {
        $this->requireKey();

        $envelope = $this->encryptToEnvelope($secrets);
        $yaml = yaml_emit($envelope, YAML_UTF8_ENCODING);
        if ($yaml === false) {
            throw new RuntimeException('Failed to serialize secrets');
        }

        $yaml = preg_replace('/^---\n/', '', $yaml);
        $yaml = preg_replace('/\n\.\.\.$/', '', $yaml);

        $dir = dirname(self::SECRETS_PATH);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true)) {
                throw new RuntimeException('Failed to create secrets directory');
            }
        }

        if (!is_writable($dir)) {
            throw new RuntimeException('Secrets directory is not writable');
        }

        $result = file_put_contents(self::SECRETS_PATH, $yaml);
        if ($result === false) {
            throw new RuntimeException('Failed to write secrets file');
        }
    }

    public function getKeyPath(): string {
        return self::KEY_PATH;
    }

    public function ensureKeyFile(): bool {
        return $this->getKeyStatus(true)['ready'] ?? false;
    }

    public function rotateKeyFile(): bool {
        if (file_exists(self::KEY_PATH) && !@unlink(self::KEY_PATH)) {
            return false;
        }

        return $this->createKeyFile();
    }

    /**
     * Check if data is an encrypted envelope
     */
    private function isEncryptedEnvelope(array $data): bool {
        $root = $data['secrets'] ?? null;
        if (!is_array($root)) {
            return false;
        }
        return !empty($root['__encrypted__']) && !empty($root['data']) && !empty($root['iv']);
    }

    /**
     * Encrypt secrets into envelope structure
     */
    private function encryptToEnvelope(array $secrets): array {
        $key = $this->loadKey();

        $plaintext = yaml_emit(['secrets' => $secrets], YAML_UTF8_ENCODING);
        if ($plaintext === false) {
            throw new RuntimeException('Failed to serialize secrets for encryption');
        }
        $plaintext = preg_replace('/^---\n/', '', $plaintext);
        $plaintext = preg_replace('/\n\.\.\.$/', '', $plaintext);

        $iv = random_bytes(self::IV_BYTES);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($ciphertext === false || $tag === '') {
            throw new RuntimeException('Failed to encrypt secrets');
        }

        return [
            'secrets' => [
                '__encrypted__' => true,
                'cipher' => self::CIPHER,
                'iv' => base64_encode($iv),
                'tag' => base64_encode($tag),
                'data' => base64_encode($ciphertext),
            ]
        ];
    }

    /**
     * Decrypt envelope to secrets array
     */
    private function decryptEnvelope(array $data): array {
        $key = $this->loadKey();
        $root = $data['secrets'] ?? [];

        $iv = base64_decode($root['iv'] ?? '', true);
        $tag = base64_decode($root['tag'] ?? '', true);
        $ciphertext = base64_decode($root['data'] ?? '', true);

        if ($iv === false || $tag === false || $ciphertext === false) {
            throw new RuntimeException('Invalid secrets envelope');
        }

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plaintext === false) {
            throw new RuntimeException('Failed to decrypt secrets');
        }

        $decoded = yaml_parse($plaintext);
        if ($decoded === false || !isset($decoded['secrets']) || !is_array($decoded['secrets'])) {
            throw new RuntimeException('Decrypted secrets are invalid');
        }

        return $decoded['secrets'];
    }

    /**
     * Ensure key exists and is valid
     */
    private function requireKey(): void {
        $status = $this->getKeyStatus(false);
        if (!$status['ready']) {
            throw new RuntimeException($status['reason'] ?? 'Secrets key unavailable');
        }
    }

    /**
     * Load key (binary)
     */
    private function loadKey(): string {
        $raw = trim((string)file_get_contents(self::KEY_PATH));
        $decoded = base64_decode($raw, true);
        if ($decoded === false || strlen($decoded) !== 32) {
            throw new RuntimeException('Invalid secrets key format');
        }

        return $decoded;
    }

    /**
     * Return key status, optionally auto-create
     */
    private function getKeyStatus(bool $autoCreate = false): array {
        if (!file_exists(self::KEY_PATH)) {
            if ($autoCreate) {
                $created = $this->createKeyFile();
                if (!$created) {
                    return ['ready' => false, 'reason' => 'Unable to create secrets key file'];
                }
            } else {
                return ['ready' => false, 'reason' => 'Secrets key file missing'];
            }
        }

        if (!is_readable(self::KEY_PATH)) {
            return ['ready' => false, 'reason' => 'Secrets key file is not readable'];
        }

        $raw = trim((string)file_get_contents(self::KEY_PATH));
        $decoded = base64_decode($raw, true);
        if ($decoded === false || strlen($decoded) !== 32) {
            return ['ready' => false, 'reason' => 'Secrets key file is invalid'];
        }

        return ['ready' => true, 'reason' => null];
    }

    /**
     * Create a new key file (base64 32 bytes)
     */
    private function createKeyFile(): bool {
        if (!is_dir(self::KEY_DIR)) {
            if (!@mkdir(self::KEY_DIR, 0700, true)) {
                return false;
            }
        }

        if (!is_writable(self::KEY_DIR)) {
            return false;
        }

        $key = base64_encode(random_bytes(32));
        $result = file_put_contents(self::KEY_PATH, $key . "\n");
        if ($result === false) {
            return false;
        }

        @chmod(self::KEY_PATH, 0600);
        return true;
    }
}
