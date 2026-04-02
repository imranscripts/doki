<?php
/**
 * AIProviderManager.php - App Studio AI provider management
 *
 * Supports local Ollama, native OpenAI, Anthropic Claude, MiniMax, and
 * OpenAI-compatible providers with customizable paths and request params.
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/ConfigManager.php';

class AIProviderManager {
    public const TYPE_OLLAMA = 'ollama';
    public const TYPE_OPENAI_COMPATIBLE = 'openai-compatible';

    private PDO $db;
    private ConfigManager $config;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->config = new ConfigManager();
    }

    /**
     * Return all configured AI providers with secrets masked.
     */
    public function getProviders(): array {
        $stmt = $this->db->query("
            SELECT *
            FROM ai_providers
            ORDER BY is_default DESC, name ASC
        ");

        $providers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn(array $provider) => $this->normalizeProviderRow($provider), $providers);
    }

    /**
     * Return a single provider by ID.
     */
    public function getProvider(string $id, bool $includeSecrets = false): ?array {
        $stmt = $this->db->prepare("SELECT * FROM ai_providers WHERE id = ?");
        $stmt->execute([$id]);
        $provider = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$provider) {
            return null;
        }

        return $this->normalizeProviderRow($provider, $includeSecrets);
    }

    /**
     * Return a provider with resolved API key for outbound requests.
     */
    public function getResolvedProvider(string $id): ?array {
        $provider = $this->getProvider($id, true);
        if (!$provider) {
            return null;
        }

        $provider['apiKey'] = $this->resolveApiKey($provider);
        return $provider;
    }

    /**
     * Create a provider.
     */
    public function createProvider(array $data, string $userId, string $username): array {
        $validated = $this->validateProviderInput($data, false);
        if (!$validated['success']) {
            return $validated;
        }

        $id = $validated['provider']['id'];
        if ($this->getProvider($id) !== null) {
            return ['success' => false, 'error' => 'Provider ID already exists'];
        }

        $provider = $validated['provider'];
        $secretId = null;
        if ($provider['apiKey'] !== null && $provider['apiKey'] !== '') {
            $secretId = $this->apiKeySecretId($id);
            try {
                $result = $this->config->saveSecret($secretId, $provider['apiKey'], $userId, $username);
                if (empty($result['success'])) {
                    return ['success' => false, 'error' => 'Failed to store API key'];
                }
            } catch (Throwable $e) {
                return ['success' => false, 'error' => 'Failed to store API key: ' . $e->getMessage()];
            }
        }

        $stmt = $this->db->prepare("
            INSERT INTO ai_providers (
                id, name, type, base_url, api_key_secret_id, default_model, is_default, metadata_json,
                created_by, updated_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $shouldSetDefault = $provider['isDefault'] || $this->countProviders() === 0;

        try {
            $this->db->beginTransaction();
            if ($shouldSetDefault) {
                $this->clearDefaultProviderFlag();
            }

            $stmt->execute([
                $provider['id'],
                $provider['name'],
                $provider['type'],
                $provider['baseUrl'],
                $secretId,
                $provider['defaultModel'],
                $shouldSetDefault ? 1 : 0,
                $provider['metadataJson'],
                $userId,
                $userId,
            ]);
            $this->db->commit();
        } catch (PDOException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['success' => false, 'error' => 'Database error: ' . $e->getMessage()];
        }

        return [
            'success' => true,
            'provider' => $this->getProvider($id),
        ];
    }

    /**
     * Update an existing provider.
     */
    public function updateProvider(string $id, array $data, string $userId, string $username): array {
        $existing = $this->getProvider($id, true);
        if (!$existing) {
            return ['success' => false, 'error' => 'Provider not found'];
        }

        $validated = $this->validateProviderInput(array_merge($existing, $data, ['id' => $id]), true);
        if (!$validated['success']) {
            return $validated;
        }

        $provider = $validated['provider'];
        $updates = [];
        $params = [];

        foreach ([
            'name' => $provider['name'],
            'type' => $provider['type'],
            'base_url' => $provider['baseUrl'],
            'default_model' => $provider['defaultModel'],
            'is_default' => $provider['isDefault'] ? 1 : 0,
            'metadata_json' => $provider['metadataJson'],
        ] as $field => $value) {
            $updates[] = "{$field} = ?";
            $params[] = $value;
        }

        if (array_key_exists('apiKey', $provider)) {
            if ($provider['apiKey'] === '') {
                $updates[] = "api_key_secret_id = NULL";
                $legacySecretId = $existing['api_key_secret_id'] ?? null;
                if (is_string($legacySecretId) && $legacySecretId !== '') {
                    try {
                        $this->config->deleteSecret($legacySecretId, $userId, $username);
                    } catch (Throwable $e) {
                        // Keep DB update authoritative even if cleanup fails.
                    }
                }
            } elseif ($provider['apiKey'] !== null) {
                $secretId = $this->apiKeySecretId($id);
                try {
                    $result = $this->config->saveSecret($secretId, $provider['apiKey'], $userId, $username);
                    if (empty($result['success'])) {
                        return ['success' => false, 'error' => 'Failed to store API key'];
                    }
                } catch (Throwable $e) {
                    return ['success' => false, 'error' => 'Failed to store API key: ' . $e->getMessage()];
                }

                $updates[] = "api_key_secret_id = ?";
                $params[] = $secretId;
            }
        }

        $updates[] = "updated_at = datetime('now')";
        $updates[] = "updated_by = ?";
        $params[] = $userId;
        $params[] = $id;

        $stmt = $this->db->prepare("UPDATE ai_providers SET " . implode(', ', $updates) . " WHERE id = ?");

        try {
            $this->db->beginTransaction();
            if ($provider['isDefault']) {
                $this->clearDefaultProviderFlag($id);
            }
            $stmt->execute($params);
            $this->db->commit();
        } catch (PDOException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['success' => false, 'error' => 'Database error: ' . $e->getMessage()];
        }

        return [
            'success' => true,
            'provider' => $this->getProvider($id),
        ];
    }

    /**
     * Delete a provider and its stored secret.
     */
    public function deleteProvider(string $id, string $userId, string $username): array {
        $provider = $this->getProvider($id, true);
        if (!$provider) {
            return ['success' => false, 'error' => 'Provider not found'];
        }

        $stmt = $this->db->prepare("DELETE FROM ai_providers WHERE id = ?");
        $stmt->execute([$id]);

        $secretId = $provider['api_key_secret_id'] ?? null;
        if (is_string($secretId) && $secretId !== '') {
            try {
                $this->config->deleteSecret($secretId, $userId, $username);
            } catch (Throwable $e) {
                // Secret cleanup is best-effort after the provider row is removed.
            }
        }

        return ['success' => true];
    }

    /**
     * Test provider connectivity and return a summary.
     */
    public function testProvider(string $id): array {
        $provider = $this->getResolvedProvider($id);
        if (!$provider) {
            return ['success' => false, 'error' => 'Provider not found'];
        }

        $modelsResult = $this->listModelsForProvider($provider);

        $stmt = $this->db->prepare("
            UPDATE ai_providers
            SET last_tested_at = datetime('now'),
                last_test_success = ?
            WHERE id = ?
        ");
        $stmt->execute([$modelsResult['success'] ? 1 : 0, $id]);

        if (!$modelsResult['success']) {
            return $modelsResult;
        }

        return [
            'success' => true,
            'message' => sprintf(
                'Connected. %d model%s available.',
                count($modelsResult['models']),
                count($modelsResult['models']) === 1 ? '' : 's'
            ),
            'models' => $modelsResult['models'],
            'recommendedModel' => $modelsResult['recommendedModel'] ?? null,
        ];
    }

    /**
     * Return a provider's model catalog.
     */
    public function listModels(string $id): array {
        $provider = $this->getResolvedProvider($id);
        if (!$provider) {
            return ['success' => false, 'error' => 'Provider not found'];
        }

        return $this->listModelsForProvider($provider);
    }

    /**
     * Return a model catalog using draft provider settings from the UI.
     */
    public function listModelsFromDraft(array $data): array {
        $validated = $this->validateProviderInput($data, true);
        if (!$validated['success']) {
            return $validated;
        }

        $apiKey = $validated['provider']['apiKey'] ?? null;
        $providerId = $validated['provider']['id'] ?? '';
        if (($apiKey === null || $apiKey === '') && is_string($providerId) && $providerId !== '') {
            $savedProvider = $this->getResolvedProvider($providerId);
            if (is_array($savedProvider) && !empty($savedProvider['apiKey'])) {
                $apiKey = (string)$savedProvider['apiKey'];
            }
        }

        $provider = [
            'id' => $validated['provider']['id'],
            'name' => $validated['provider']['name'],
            'type' => $validated['provider']['type'],
            'base_url' => $validated['provider']['baseUrl'],
            'baseUrl' => $validated['provider']['baseUrl'],
            'default_model' => $validated['provider']['defaultModel'],
            'defaultModel' => $validated['provider']['defaultModel'],
            'apiKey' => $apiKey,
            'metadata_json' => $validated['provider']['metadataJson'],
            'metadata' => $this->decodeMetadataJson($validated['provider']['metadataJson']),
        ];

        return $this->listModelsForProvider($provider);
    }

    /**
     * Pick the best available model for a provider.
     */
    public function chooseRecommendedModel(array $provider, array $models, ?string $requestedModel = null): ?string {
        $requested = trim((string)($requestedModel ?? ''));
        if ($requested !== '') {
            return $requested;
        }

        $defaultModel = trim((string)($provider['default_model'] ?? $provider['defaultModel'] ?? ''));
        if ($defaultModel !== '') {
            return $defaultModel;
        }

        if (empty($models)) {
            return null;
        }

        if (($provider['type'] ?? '') === self::TYPE_OLLAMA) {
            usort($models, function(array $a, array $b): int {
                $scoreA = $this->scoreOllamaModel($a['id'] ?? $a['name'] ?? '');
                $scoreB = $this->scoreOllamaModel($b['id'] ?? $b['name'] ?? '');
                if ($scoreA !== $scoreB) {
                    return $scoreB <=> $scoreA;
                }

                $sizeA = (int)($a['size'] ?? PHP_INT_MAX);
                $sizeB = (int)($b['size'] ?? PHP_INT_MAX);
                if ($sizeA !== $sizeB) {
                    return $sizeA <=> $sizeB;
                }

                return strcmp((string)($a['id'] ?? ''), (string)($b['id'] ?? ''));
            });

            return $models[0]['id'] ?? $models[0]['name'] ?? null;
        }

        usort($models, function(array $a, array $b): int {
            $scoreA = $this->scoreHostedModel($a['id'] ?? $a['name'] ?? '');
            $scoreB = $this->scoreHostedModel($b['id'] ?? $b['name'] ?? '');
            if ($scoreA !== $scoreB) {
                return $scoreB <=> $scoreA;
            }
            return strcmp((string)($a['id'] ?? ''), (string)($b['id'] ?? ''));
        });

        return $models[0]['id'] ?? $models[0]['name'] ?? null;
    }

    /**
     * Send a chat-style completion request to a provider.
     */
    public function sendChat(array $provider, string $model, array $messages): array {
        if (($provider['type'] ?? '') === self::TYPE_OLLAMA) {
            return $this->sendOllamaChat($provider, $model, $messages);
        }

        if ($this->isAnthropicProvider($provider)) {
            return $this->sendAnthropicMessagesChat($provider, $model, $messages);
        }

        if ($this->isNativeOpenAiProvider($provider)) {
            return $this->sendOpenAiResponsesChat($provider, $model, $messages);
        }

        return $this->sendOpenAiCompatibleChat($provider, $model, $messages);
    }

    /**
     * Validate and normalize provider input.
     */
    private function validateProviderInput(array $data, bool $updating): array {
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            return ['success' => false, 'error' => 'Provider name is required'];
        }

        $type = trim((string)($data['type'] ?? ''));
        if (!in_array($type, [self::TYPE_OLLAMA, self::TYPE_OPENAI_COMPATIBLE], true)) {
            return ['success' => false, 'error' => 'Provider type must be ollama or openai-compatible'];
        }

        $idSeed = trim((string)($data['id'] ?? ''));
        $id = $idSeed !== '' ? $this->slugify($idSeed) : $this->slugify($name);
        if ($id === '') {
            return ['success' => false, 'error' => 'Provider ID is invalid'];
        }

        $baseUrl = $this->normalizeBaseUrl($type, (string)($data['baseUrl'] ?? $data['base_url'] ?? ''));
        if ($baseUrl === '') {
            return ['success' => false, 'error' => 'API base URL is required'];
        }

        $defaultModel = trim((string)($data['defaultModel'] ?? $data['default_model'] ?? ''));
        $isDefault = $this->parseBooleanFlag($data['isDefault'] ?? $data['is_default'] ?? false);
        $apiKey = null;
        if (array_key_exists('apiKey', $data)) {
            $apiKey = (string)$data['apiKey'];
        } elseif (array_key_exists('api_key', $data)) {
            $apiKey = (string)$data['api_key'];
        } elseif (!$updating) {
            $apiKey = '';
        }

        $metadata = $data['metadata'] ?? null;
        $metadataJson = null;
        if (is_array($metadata) && !empty($metadata)) {
            $metadataJson = json_encode($metadata, JSON_UNESCAPED_SLASHES);
        } elseif (is_string($data['metadata_json'] ?? null) && trim((string)$data['metadata_json']) !== '') {
            $metadataJson = trim((string)$data['metadata_json']);
        }

        return [
            'success' => true,
            'provider' => [
                'id' => $id,
                'name' => $name,
                'type' => $type,
                'baseUrl' => $baseUrl,
                'defaultModel' => $defaultModel !== '' ? $defaultModel : null,
                'isDefault' => $isDefault,
                'apiKey' => $apiKey,
                'metadataJson' => $metadataJson,
            ],
        ];
    }

    /**
     * Normalize provider rows for API/UI consumption.
     */
    private function normalizeProviderRow(array $provider, bool $includeSecrets = false): array {
        $provider['base_url'] = $this->normalizeBaseUrl(
            (string)($provider['type'] ?? ''),
            (string)($provider['base_url'] ?? '')
        );
        $provider['baseUrl'] = $provider['base_url'];
        $provider['defaultModel'] = $provider['default_model'] ?? null;
        $provider['is_default'] = !empty($provider['is_default']) ? 1 : 0;
        $provider['isDefault'] = $provider['is_default'] === 1;
        $provider['hasApiKey'] = !empty($provider['api_key_secret_id']);
        $provider['metadata'] = $this->normalizeProviderMetadata(
            $this->decodeMetadataJson($provider['metadata_json'] ?? null),
            $provider['base_url']
        );
        $provider['supportsModelListing'] = $this->providerSupportsModelListing($provider);
        $provider['requiresApiKey'] = ($provider['type'] ?? '') !== self::TYPE_OLLAMA;

        if (!$includeSecrets) {
            $provider['apiKey'] = $provider['hasApiKey'] ? '••••••••' : '';
        }

        return $provider;
    }

    /**
     * Resolve API key from secrets store.
     */
    private function resolveApiKey(array $provider): ?string {
        $secretId = $provider['api_key_secret_id'] ?? null;
        if (!is_string($secretId) || $secretId === '') {
            return null;
        }

        return $this->config->getSecret($secretId);
    }

    /**
     * Return the number of configured providers.
     */
    private function countProviders(): int {
        $count = $this->db->query("SELECT COUNT(*) FROM ai_providers")->fetchColumn();
        return is_numeric($count) ? (int)$count : 0;
    }

    /**
     * Clear the default flag from all providers, optionally keeping one provider untouched.
     */
    private function clearDefaultProviderFlag(?string $excludeId = null): void {
        if ($excludeId !== null && $excludeId !== '') {
            $stmt = $this->db->prepare("UPDATE ai_providers SET is_default = 0 WHERE id != ?");
            $stmt->execute([$excludeId]);
            return;
        }

        $this->db->exec("UPDATE ai_providers SET is_default = 0");
    }

    /**
     * Normalize checkbox-style input into a strict boolean.
     */
    private function parseBooleanFlag(mixed $value): bool {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int)$value === 1;
        }

        $normalized = strtolower(trim((string)$value));
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Fetch models from a configured provider.
     */
    private function listModelsForProvider(array $provider): array {
        if (($provider['type'] ?? '') === self::TYPE_OLLAMA) {
            return $this->listOllamaModels($provider);
        }

        if ($this->isAnthropicProvider($provider)) {
            return $this->listAnthropicModels($provider);
        }

        return $this->listOpenAiCompatibleModels($provider);
    }

    /**
     * Fetch Ollama models using /api/tags.
     */
    private function listOllamaModels(array $provider): array {
        $url = rtrim((string)$provider['base_url'], '/') . '/api/tags';
        $response = $this->requestJson('GET', $url, []);
        if (!$response['success']) {
            return $response;
        }

        $models = [];
        foreach (($response['data']['models'] ?? []) as $model) {
            $name = trim((string)($model['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $models[] = [
                'id' => $name,
                'name' => $name,
                'label' => $name,
                'size' => is_numeric($model['size'] ?? null) ? (int)$model['size'] : null,
                'family' => $model['details']['family'] ?? null,
                'parameterSize' => $model['details']['parameter_size'] ?? null,
                'modifiedAt' => $model['modified_at'] ?? null,
            ];
        }

        $recommended = $this->chooseRecommendedModel($provider, $models);
        return [
            'success' => true,
            'models' => $models,
            'recommendedModel' => $recommended,
        ];
    }

    /**
     * Fetch OpenAI-compatible models using /models.
     */
    private function listOpenAiCompatibleModels(array $provider): array {
        $headers = $this->buildOpenAiCompatibleHeaders($provider);
        $baseUrl = rtrim((string)$provider['base_url'], '/');
        $modelsPath = $this->getOpenAiCompatibleModelsPath($provider);
        if ($modelsPath === '') {
            return [
                'success' => false,
                'error' => 'This provider does not expose a model-listing path. Set a default model manually or configure a models path.',
            ];
        }

        $url = $baseUrl . $modelsPath;
        $response = $this->requestJson('GET', $url, $headers);
        if (!$response['success']) {
            $fallback = $this->buildDocumentedModelFallback($provider, $response);
            if ($fallback !== null) {
                return $fallback;
            }
            return $this->normalizeOpenAiCompatibleError($provider, $response);
        }

        $models = [];
        foreach (($response['data']['data'] ?? []) as $model) {
            $id = trim((string)($model['id'] ?? ''));
            if ($id === '') {
                continue;
            }

            $models[] = [
                'id' => $id,
                'name' => $id,
                'label' => $id,
                'created' => $model['created'] ?? null,
                'ownedBy' => $model['owned_by'] ?? null,
            ];
        }

        if ($models === []) {
            $fallback = $this->buildDocumentedModelFallback($provider);
            if ($fallback !== null) {
                return $fallback;
            }
        }

        $recommended = $this->chooseRecommendedModel($provider, $models);
        return [
            'success' => true,
            'models' => $models,
            'recommendedModel' => $recommended,
        ];
    }

    /**
     * Fetch Anthropic models using /models.
     */
    private function listAnthropicModels(array $provider): array {
        $url = rtrim((string)$provider['base_url'], '/') . '/models';
        $headers = $this->buildAnthropicHeaders($provider);
        $response = $this->requestJson('GET', $url, $headers);
        if (!$response['success']) {
            return $response;
        }

        $models = [];
        foreach (($response['data']['data'] ?? []) as $model) {
            $id = trim((string)($model['id'] ?? ''));
            if ($id === '') {
                continue;
            }

            $displayName = trim((string)($model['display_name'] ?? ''));
            $models[] = [
                'id' => $id,
                'name' => $id,
                'label' => $displayName !== '' ? $displayName . ' (' . $id . ')' : $id,
                'created' => $model['created_at'] ?? null,
                'type' => $model['type'] ?? null,
            ];
        }

        $recommended = $this->chooseRecommendedModel($provider, $models);
        return [
            'success' => true,
            'models' => $models,
            'recommendedModel' => $recommended,
        ];
    }

    /**
     * Send a non-streaming Ollama /api/chat request.
     */
    private function sendOllamaChat(array $provider, string $model, array $messages): array {
        $url = rtrim((string)$provider['base_url'], '/') . '/api/chat';
        $payload = [
            'model' => $model,
            'stream' => false,
            'messages' => $messages,
            'options' => [
                'temperature' => 0.2,
            ],
        ];

        $response = $this->requestJson('POST', $url, [], $payload, 120);
        if (!$response['success']) {
            return $response;
        }

        return [
            'success' => true,
            'content' => (string)($response['data']['message']['content'] ?? ''),
            'usage' => [
                'inputTokens' => $response['data']['prompt_eval_count'] ?? null,
                'outputTokens' => $response['data']['eval_count'] ?? null,
            ],
            'raw' => $response['data'],
        ];
    }

    /**
     * Send a chat completion to an OpenAI-compatible API.
     */
    private function sendOpenAiCompatibleChat(array $provider, string $model, array $messages): array {
        $url = rtrim((string)$provider['base_url'], '/') . $this->getOpenAiCompatibleChatPath($provider);
        $headers = $this->buildOpenAiCompatibleHeaders($provider);
        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => 0.2,
            'response_format' => ['type' => 'json_object'],
        ];
        $payload = $this->mergeOpenAiCompatibleRequestParams($provider, $payload, $model, $messages);

        $response = $this->requestJson('POST', $url, $headers, $payload, 120);
        if ($this->shouldRetryWithoutResponseFormat($provider, $response, $payload)) {
            unset($payload['response_format']);
            $response = $this->requestJson('POST', $url, $headers, $payload, 120);
        }
        if ($this->shouldRetryWithMinimalPayload($provider, $response, $payload)) {
            $payload = [
                'model' => $model,
                'messages' => $messages,
            ];
            $response = $this->requestJson('POST', $url, $headers, $payload, 120);
        }

        if (!$response['success']) {
            return $this->normalizeOpenAiCompatibleError($provider, $response);
        }

        return [
            'success' => true,
            'content' => (string)($response['data']['choices'][0]['message']['content'] ?? ''),
            'usage' => [
                'inputTokens' => $response['data']['usage']['prompt_tokens'] ?? null,
                'outputTokens' => $response['data']['usage']['completion_tokens'] ?? null,
            ],
            'raw' => $response['data'],
        ];
    }

    /**
     * Send a Messages API request to Anthropic Claude.
     */
    private function sendAnthropicMessagesChat(array $provider, string $model, array $messages): array {
        $url = rtrim((string)$provider['base_url'], '/') . '/messages';
        $headers = $this->buildAnthropicHeaders($provider);
        [$system, $conversation] = $this->buildAnthropicConversation($messages);

        if ($conversation === []) {
            return ['success' => false, 'error' => 'Anthropic requests require at least one user or assistant message'];
        }

        $payload = [
            'model' => $model,
            'max_tokens' => 4096,
            'messages' => $conversation,
            'temperature' => 0.2,
        ];
        if ($system !== '') {
            $payload['system'] = $system;
        }

        $response = $this->requestJson('POST', $url, $headers, $payload, 120);
        if (!$response['success']) {
            return $response;
        }

        $content = $this->extractAnthropicOutputText($response['data']);
        if ($content === '') {
            return [
                'success' => false,
                'error' => 'The Claude response did not include any text output',
                'status' => (int)($response['status'] ?? 200),
                'body' => json_encode($response['data'], JSON_UNESCAPED_SLASHES),
            ];
        }

        return [
            'success' => true,
            'content' => $content,
            'usage' => [
                'inputTokens' => $response['data']['usage']['input_tokens'] ?? null,
                'outputTokens' => $response['data']['usage']['output_tokens'] ?? null,
            ],
            'raw' => $response['data'],
        ];
    }

    /**
     * Send a Responses API request to native OpenAI.
     */
    private function sendOpenAiResponsesChat(array $provider, string $model, array $messages): array {
        $url = rtrim((string)$provider['base_url'], '/') . '/responses';
        $headers = $this->buildOpenAiCompatibleHeaders($provider);
        $payload = [
            'model' => $model,
            'input' => $this->buildResponsesInput($messages),
            'text' => [
                'format' => ['type' => 'json_object'],
            ],
        ];

        $response = $this->requestJson('POST', $url, $headers, $payload, 120);
        if ($this->shouldRetryResponsesWithoutTextFormat($response, $payload)) {
            unset($payload['text']);
            $response = $this->requestJson('POST', $url, $headers, $payload, 120);
        }

        if (!$response['success']) {
            return $this->normalizeOpenAiCompatibleError($provider, $response);
        }

        $content = $this->extractResponsesOutputText($response['data']);
        if ($content === '') {
            $refusal = $this->extractResponsesRefusalText($response['data']);
            return [
                'success' => false,
                'error' => $refusal !== '' ? $refusal : 'The OpenAI response did not include any text output',
                'status' => (int)($response['status'] ?? 200),
                'body' => json_encode($response['data'], JSON_UNESCAPED_SLASHES),
            ];
        }

        return [
            'success' => true,
            'content' => $content,
            'usage' => [
                'inputTokens' => $response['data']['usage']['input_tokens'] ?? null,
                'outputTokens' => $response['data']['usage']['output_tokens'] ?? null,
            ],
            'raw' => $response['data'],
        ];
    }

    /**
     * Build headers for OpenAI-compatible APIs.
     */
    private function buildOpenAiCompatibleHeaders(array $provider): array {
        $headers = [];
        $apiKey = trim((string)($provider['apiKey'] ?? ''));
        if ($apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }

        if ($this->isOpenRouterProvider($provider)) {
            $headers[] = 'HTTP-Referer: https://doki.local';
            $headers[] = 'X-Title: Doki App Studio';
        }

        return $headers;
    }

    /**
     * Build headers for Anthropic Claude APIs.
     */
    private function buildAnthropicHeaders(array $provider): array {
        $headers = [];
        $apiKey = trim((string)($provider['apiKey'] ?? ''));
        if ($apiKey !== '') {
            $headers[] = 'x-api-key: ' . $apiKey;
        }

        $metadata = is_array($provider['metadata'] ?? null) ? $provider['metadata'] : [];
        $version = trim((string)($metadata['anthropicVersion'] ?? '2023-06-01'));
        $headers[] = 'anthropic-version: ' . ($version !== '' ? $version : '2023-06-01');

        return $headers;
    }

    /**
     * Convert Doki chat messages to the Anthropic Messages format.
     */
    private function buildAnthropicConversation(array $messages): array {
        $systemParts = [];
        $conversation = [];

        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }

            $role = strtolower(trim((string)($message['role'] ?? '')));
            $content = trim((string)($message['content'] ?? ''));
            if ($content === '') {
                continue;
            }

            if (in_array($role, ['system', 'developer'], true)) {
                $systemParts[] = $content;
                continue;
            }

            if (!in_array($role, ['user', 'assistant'], true)) {
                continue;
            }

            $conversation[] = [
                'role' => $role,
                'content' => $content,
            ];
        }

        return [
            implode("\n\n", $systemParts),
            $conversation,
        ];
    }

    /**
     * Convert chat-style messages into the Responses API input format.
     */
    private function buildResponsesInput(array $messages): array {
        $input = [];

        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }

            $role = strtolower(trim((string)($message['role'] ?? '')));
            $content = trim((string)($message['content'] ?? ''));
            if ($content === '') {
                continue;
            }

            if ($role === 'system') {
                $role = 'developer';
            }

            if (!in_array($role, ['developer', 'user', 'assistant'], true)) {
                continue;
            }

            $input[] = [
                'role' => $role,
                'content' => $content,
            ];
        }

        return $input;
    }

    /**
     * Perform a JSON HTTP request.
     */
    private function requestJson(string $method, string $url, array $headers = [], ?array $payload = null, int $timeout = 30): array {
        if (!function_exists('curl_init')) {
            return ['success' => false, 'error' => 'cURL is not available'];
        }

        $ch = curl_init($url);
        $body = null;

        $headers[] = 'Accept: application/json';
        if ($payload !== null) {
            $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
            if (!is_string($body)) {
                return ['success' => false, 'error' => 'Failed to encode JSON request body'];
            }
            $headers[] = 'Content-Type: application/json';
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($ch, CURLOPT_USERAGENT, 'Doki/1.0');
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($responseBody === false || $error !== '') {
            return ['success' => false, 'error' => 'Connection error: ' . $error, 'status' => $status];
        }

        $decoded = json_decode($responseBody, true);
        $embeddedError = $this->extractErrorPayload($decoded, $status, $responseBody);
        if ($embeddedError !== null) {
            return $embeddedError;
        }

        if ($status < 200 || $status >= 300) {
            $message = null;
            if (is_array($decoded)) {
                $message = $decoded['error']['message'] ?? $decoded['error'] ?? $decoded['message'] ?? null;
            }
            return [
                'success' => false,
                'error' => is_string($message) && $message !== '' ? $message : 'HTTP ' . $status,
                'status' => $status,
                'body' => $responseBody,
            ];
        }

        if (!is_array($decoded)) {
            return [
                'success' => false,
                'error' => 'Invalid JSON response from provider',
                'status' => $status,
                'body' => $responseBody,
            ];
        }

        return [
            'success' => true,
            'status' => $status,
            'data' => $decoded,
        ];
    }

    /**
     * Normalize provider base URLs.
     */
    private function normalizeBaseUrl(string $type, string $baseUrl): string {
        $baseUrl = trim($baseUrl);
        if ($baseUrl === '') {
            $baseUrl = $this->defaultBaseUrl($type);
        }

        return rtrim($baseUrl, '/');
    }

    /**
     * Return the default base URL for a provider type.
     */
    private function defaultBaseUrl(string $type): string {
        if ($type === self::TYPE_OLLAMA) {
            return 'http://host.docker.internal:11434';
        }

        if ($type === self::TYPE_OPENAI_COMPATIBLE) {
            return 'https://api.openai.com/v1';
        }

        return '';
    }

    /**
     * Return true when the provider points at OpenRouter.
     */
    private function isOpenRouterProvider(array $provider): bool {
        $baseUrl = strtolower((string)($provider['base_url'] ?? $provider['baseUrl'] ?? ''));
        return str_contains($baseUrl, 'openrouter.ai/');
    }

    /**
     * Return true when the provider is native OpenAI rather than a compatible gateway.
     */
    private function isNativeOpenAiProvider(array $provider): bool {
        if (($provider['type'] ?? '') !== self::TYPE_OPENAI_COMPATIBLE || $this->isOpenRouterProvider($provider)) {
            return false;
        }

        if ($this->getProviderPreset($provider) === 'openai') {
            return true;
        }

        $baseUrl = strtolower((string)($provider['base_url'] ?? $provider['baseUrl'] ?? ''));
        return str_contains($baseUrl, 'api.openai.com/');
    }

    /**
     * Return true when the provider should use Anthropic's Messages API.
     */
    private function isAnthropicProvider(array $provider): bool {
        if (($provider['type'] ?? '') !== self::TYPE_OPENAI_COMPATIBLE) {
            return false;
        }

        $preset = $this->getProviderPreset($provider);
        if ($preset === 'claude') {
            return true;
        }

        $baseUrl = strtolower((string)($provider['base_url'] ?? $provider['baseUrl'] ?? ''));
        return str_contains($baseUrl, 'api.anthropic.com/');
    }

    /**
     * Return true when the provider points at MiniMax.
     */
    private function isMiniMaxProvider(array $provider): bool {
        $preset = $this->getProviderPreset($provider);
        if ($preset === 'minimax') {
            return true;
        }

        $baseUrl = strtolower((string)($provider['base_url'] ?? $provider['baseUrl'] ?? ''));
        return str_contains($baseUrl, 'api.minimax.io/');
    }

    /**
     * Return the normalized preset for a provider.
     */
    private function getProviderPreset(array $provider): string {
        $metadata = is_array($provider['metadata'] ?? null)
            ? $this->normalizeProviderMetadata($provider['metadata'], (string)($provider['base_url'] ?? $provider['baseUrl'] ?? ''))
            : [];

        return strtolower(trim((string)($metadata['preset'] ?? 'custom-openai')));
    }

    /**
     * Sanitize stored metadata for predictable provider behavior.
     */
    private function normalizeProviderMetadata(array $metadata, string $baseUrl = ''): array {
        $baseUrl = strtolower(trim($baseUrl));
        $preset = strtolower(trim((string)($metadata['preset'] ?? '')));
        if ($preset === '') {
            if (str_contains($baseUrl, 'api.anthropic.com/')) {
                $preset = 'claude';
            } elseif (str_contains($baseUrl, 'openrouter.ai/')) {
                $preset = 'openrouter';
            } elseif (str_contains($baseUrl, 'api.minimax.io/')) {
                $preset = 'minimax';
            } elseif (str_contains($baseUrl, 'api.openai.com/')) {
                $preset = 'openai';
            } elseif ($baseUrl !== '') {
                $preset = 'custom-openai';
            }
        }

        if ($preset === '') {
            $preset = 'custom-openai';
        }

        $normalized = ['preset' => $preset];

        if ($preset === 'claude') {
            $version = trim((string)($metadata['anthropicVersion'] ?? $metadata['anthropic_version'] ?? '2023-06-01'));
            $normalized['anthropicVersion'] = $version !== '' ? $version : '2023-06-01';
        }

        $rawPaths = is_array($metadata['paths'] ?? null) ? $metadata['paths'] : [];
        $normalizedPaths = [];

        if (array_key_exists('chat', $rawPaths)) {
            $chatPath = $this->normalizeRelativeApiPath((string)$rawPaths['chat']);
            if ($chatPath !== '') {
                $normalizedPaths['chat'] = $chatPath;
            }
        } elseif (is_string($metadata['chatPath'] ?? null)) {
            $chatPath = $this->normalizeRelativeApiPath((string)$metadata['chatPath']);
            if ($chatPath !== '') {
                $normalizedPaths['chat'] = $chatPath;
            }
        }

        if (array_key_exists('models', $rawPaths)) {
            $rawModelsPath = trim((string)$rawPaths['models']);
            $normalizedPaths['models'] = $rawModelsPath === '' ? '' : $this->normalizeRelativeApiPath($rawModelsPath);
        } elseif (is_string($metadata['modelsPath'] ?? null)) {
            $rawModelsPath = trim((string)$metadata['modelsPath']);
            $normalizedPaths['models'] = $rawModelsPath === '' ? '' : $this->normalizeRelativeApiPath($rawModelsPath);
        }

        if ($normalizedPaths !== []) {
            $normalized['paths'] = $normalizedPaths;
        }

        $requestParams = $metadata['requestParams'] ?? $metadata['request_params'] ?? null;
        if (is_array($requestParams)) {
            $normalized['requestParams'] = $requestParams;
        }

        return $normalized;
    }

    /**
     * Return the configured OpenAI-style chat path for a provider.
     */
    private function getOpenAiCompatibleChatPath(array $provider): string {
        $metadata = is_array($provider['metadata'] ?? null) ? $provider['metadata'] : [];
        $path = trim((string)($metadata['paths']['chat'] ?? ''));
        return $path !== '' ? $path : '/chat/completions';
    }

    /**
     * Return the configured OpenAI-style models path for a provider.
     */
    private function getOpenAiCompatibleModelsPath(array $provider): string {
        if ($this->isOpenRouterProvider($provider)) {
            return '/models/user';
        }

        $metadata = is_array($provider['metadata'] ?? null) ? $provider['metadata'] : [];
        if (array_key_exists('models', $metadata['paths'] ?? [])) {
            return trim((string)$metadata['paths']['models']);
        }

        return '/models';
    }

    /**
     * Determine whether model listing should be offered for a provider.
     */
    private function providerSupportsModelListing(array $provider): bool {
        if (($provider['type'] ?? '') === self::TYPE_OLLAMA || $this->isAnthropicProvider($provider) || $this->isMiniMaxProvider($provider)) {
            return true;
        }

        return $this->getOpenAiCompatibleModelsPath($provider) !== '';
    }

    /**
     * Merge custom OpenAI-compatible request params into the request payload.
     */
    private function mergeOpenAiCompatibleRequestParams(array $provider, array $payload, string $model, array $messages): array {
        $metadata = is_array($provider['metadata'] ?? null) ? $provider['metadata'] : [];
        $requestParams = is_array($metadata['requestParams'] ?? null) ? $metadata['requestParams'] : [];
        if ($requestParams === []) {
            return $payload;
        }

        $merged = array_replace_recursive($payload, $requestParams);
        $merged['model'] = $model;
        $merged['messages'] = $messages;
        return $merged;
    }

    /**
     * Normalize one provider-relative API path.
     */
    private function normalizeRelativeApiPath(string $value): string {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return '/' . ltrim($value, '/');
    }

    /**
     * Return a documented fallback model list when a provider does not expose /models.
     */
    private function buildDocumentedModelFallback(array $provider, ?array $response = null): ?array {
        if (!$this->isMiniMaxProvider($provider)) {
            return null;
        }

        if (is_array($response)) {
            $status = (int)($response['status'] ?? 0);
            if (!in_array($status, [404, 405, 406, 410, 501], true)) {
                return null;
            }
        }

        $models = [
            ['id' => 'MiniMax-M2.7', 'name' => 'MiniMax-M2.7', 'label' => 'MiniMax-M2.7'],
            ['id' => 'MiniMax-M2.7-highspeed', 'name' => 'MiniMax-M2.7-highspeed', 'label' => 'MiniMax-M2.7-highspeed'],
            ['id' => 'MiniMax-M2.5', 'name' => 'MiniMax-M2.5', 'label' => 'MiniMax-M2.5'],
            ['id' => 'MiniMax-M2.5-highspeed', 'name' => 'MiniMax-M2.5-highspeed', 'label' => 'MiniMax-M2.5-highspeed'],
            ['id' => 'MiniMax-M2.1', 'name' => 'MiniMax-M2.1', 'label' => 'MiniMax-M2.1'],
            ['id' => 'MiniMax-M2.1-highspeed', 'name' => 'MiniMax-M2.1-highspeed', 'label' => 'MiniMax-M2.1-highspeed'],
            ['id' => 'MiniMax-M2', 'name' => 'MiniMax-M2', 'label' => 'MiniMax-M2'],
        ];

        return [
            'success' => true,
            'models' => $models,
            'recommendedModel' => $this->chooseRecommendedModel($provider, $models),
        ];
    }

    /**
     * Rewrite hosted-provider errors into more actionable guidance when possible.
     */
    private function normalizeOpenAiCompatibleError(array $provider, array $response): array {
        if ($this->isOpenRouterProvider($provider)) {
            $metadata = is_array($response['metadata'] ?? null) ? $response['metadata'] : [];
            $providerName = trim((string)($metadata['provider_name'] ?? ''));
            $rawSummary = $this->summarizeProviderRawError($metadata['raw'] ?? null);

            if ($providerName !== '' || stripos((string)($response['error'] ?? ''), 'Provider returned error') !== false) {
                $response['error'] = sprintf(
                    'OpenRouter provider error%s%s',
                    $providerName !== '' ? ' from ' . $providerName : '',
                    $rawSummary !== '' ? ': ' . $rawSummary : ''
                );
            }
        }

        if (
            $this->isOpenRouterProvider($provider)
            && stripos((string)($response['error'] ?? ''), 'No endpoints available matching your guardrail restrictions and data policy') !== false
        ) {
            $response['error'] = 'OpenRouter blocked this model under your current privacy or guardrail settings. Adjust Settings > Privacy, or choose a model that is eligible for your account.';
        }

        return $response;
    }

    /**
     * Extract a normalized error from a decoded JSON payload.
     */
    private function extractErrorPayload($decoded, int $status, string $responseBody): ?array {
        if (!is_array($decoded) || !is_array($decoded['error'] ?? null)) {
            return null;
        }

        $error = $decoded['error'];
        $message = trim((string)($error['message'] ?? ''));
        if ($message === '') {
            $fallback = $error['error'] ?? $decoded['message'] ?? null;
            $message = trim((string)$fallback);
        }
        if ($message === '') {
            $message = $status >= 400 ? ('HTTP ' . $status) : 'Provider returned error';
        }

        return [
            'success' => false,
            'error' => $message,
            'status' => (int)($error['code'] ?? $status),
            'body' => $responseBody,
            'metadata' => is_array($error['metadata'] ?? null) ? $error['metadata'] : null,
            'errorObject' => $error,
        ];
    }

    /**
     * Decide when to retry without structured output hints.
     */
    private function shouldRetryWithoutResponseFormat(array $provider, array $response, array $payload): bool {
        if (!array_key_exists('response_format', $payload) || !empty($response['success'])) {
            return false;
        }

        $status = (int)($response['status'] ?? 0);
        if (in_array($status, [400, 404, 415, 422], true)) {
            return true;
        }

        return $this->isOpenRouterProviderError($provider, $response);
    }

    /**
     * Decide when to retry a Responses API request without JSON mode.
     */
    private function shouldRetryResponsesWithoutTextFormat(array $response, array $payload): bool {
        if (!array_key_exists('text', $payload) || !empty($response['success'])) {
            return false;
        }

        $status = (int)($response['status'] ?? 0);
        return in_array($status, [400, 404, 415, 422], true);
    }

    /**
     * Decide when to retry an OpenRouter request with the minimal payload.
     */
    private function shouldRetryWithMinimalPayload(array $provider, array $response, array $payload): bool {
        if (!empty($response['success']) || !$this->isOpenRouterProvider($provider)) {
            return false;
        }

        if (array_keys($payload) === ['model', 'messages']) {
            return false;
        }

        $status = (int)($response['status'] ?? 0);
        if (in_array($status, [408, 429, 502, 503, 529], true)) {
            return true;
        }

        return $this->isOpenRouterProviderError($provider, $response);
    }

    /**
     * Return true when an OpenRouter response indicates a provider-level failure.
     */
    private function isOpenRouterProviderError(array $provider, array $response): bool {
        if (!$this->isOpenRouterProvider($provider) || !empty($response['success'])) {
            return false;
        }

        $metadata = is_array($response['metadata'] ?? null) ? $response['metadata'] : [];
        if (trim((string)($metadata['provider_name'] ?? '')) !== '') {
            return true;
        }

        return stripos((string)($response['error'] ?? ''), 'Provider returned error') !== false;
    }

    /**
     * Summarize an upstream provider error into one short line.
     */
    private function summarizeProviderRawError($raw): string {
        if (is_string($raw)) {
            return $this->truncateErrorText($raw);
        }

        if (is_array($raw)) {
            foreach ([
                ['error', 'message'],
                ['error', 'detail'],
                ['message'],
                ['detail'],
                ['details'],
                ['type'],
            ] as $path) {
                $value = $this->getNestedArrayValue($raw, $path);
                if (is_string($value) && trim($value) !== '') {
                    return $this->truncateErrorText($value);
                }
            }

            $encoded = json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (is_string($encoded) && $encoded !== '{}') {
                return $this->truncateErrorText($encoded);
            }
        }

        if (is_scalar($raw) && $raw !== '') {
            return $this->truncateErrorText((string)$raw);
        }

        return '';
    }

    /**
     * Read a nested value from an array path.
     */
    private function getNestedArrayValue(array $data, array $path) {
        $current = $data;
        foreach ($path as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * Trim provider error text to a UI-friendly length.
     */
    private function truncateErrorText(string $value, int $maxLength = 220): string {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);
        if ($value === '') {
            return '';
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($value) > $maxLength
                ? rtrim(mb_substr($value, 0, $maxLength - 1)) . '…'
                : $value;
        }

        return strlen($value) > $maxLength
            ? rtrim(substr($value, 0, $maxLength - 3)) . '...'
            : $value;
    }

    /**
     * Extract plain text from a Responses API payload.
     */
    private function extractResponsesOutputText(array $data): string {
        $outputText = trim((string)($data['output_text'] ?? ''));
        if ($outputText !== '') {
            return $outputText;
        }

        $segments = [];
        foreach (($data['output'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }

            foreach (($item['content'] ?? []) as $contentItem) {
                if (!is_array($contentItem)) {
                    continue;
                }

                $type = strtolower(trim((string)($contentItem['type'] ?? '')));
                if (!in_array($type, ['output_text', 'text'], true)) {
                    continue;
                }

                $text = $contentItem['text'] ?? '';
                if (is_array($text)) {
                    $text = $text['value'] ?? '';
                }

                $text = (string)$text;
                if ($text !== '') {
                    $segments[] = $text;
                }
            }
        }

        return implode('', $segments);
    }

    /**
     * Extract refusal text from a Responses API payload.
     */
    private function extractResponsesRefusalText(array $data): string {
        foreach (($data['output'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }

            foreach (($item['content'] ?? []) as $contentItem) {
                if (!is_array($contentItem)) {
                    continue;
                }

                if (strtolower(trim((string)($contentItem['type'] ?? ''))) !== 'refusal') {
                    continue;
                }

                $text = trim((string)($contentItem['refusal'] ?? $contentItem['text'] ?? ''));
                if ($text !== '') {
                    return $text;
                }
            }
        }

        return '';
    }

    /**
     * Extract text from an Anthropic Messages API response.
     */
    private function extractAnthropicOutputText(array $data): string {
        $segments = [];

        foreach (($data['content'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }

            if (strtolower(trim((string)($item['type'] ?? ''))) !== 'text') {
                continue;
            }

            $text = trim((string)($item['text'] ?? ''));
            if ($text !== '') {
                $segments[] = $text;
            }
        }

        return implode('', $segments);
    }

    /**
     * Decode metadata JSON into an array.
     */
    private function decodeMetadataJson(?string $metadataJson): array {
        if (!is_string($metadataJson) || trim($metadataJson) === '') {
            return [];
        }

        $decoded = json_decode($metadataJson, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Build the secret ID used for provider API keys.
     */
    private function apiKeySecretId(string $providerId): string {
        return 'ai-provider-' . $providerId . '-api-key';
    }

    /**
     * Convert a label to a stable slug.
     */
    private function slugify(string $value): string {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        return trim($value, '-');
    }

    /**
     * Score Ollama models, preferring code-capable and smaller local models.
     */
    private function scoreOllamaModel(string $modelId): int {
        $modelId = strtolower($modelId);
        $score = 0;

        if (preg_match('/coder|code/', $modelId) === 1) {
            $score += 100;
        }
        if (preg_match('/qwen|deepseek|codellama|starcoder|devstral|codestral/', $modelId) === 1) {
            $score += 60;
        }
        if (preg_match('/phi|gemma|llama|mistral/', $modelId) === 1) {
            $score += 20;
        }

        return $score;
    }

    /**
     * Score hosted models, preferring lower-cost small variants when obvious.
     */
    private function scoreHostedModel(string $modelId): int {
        $modelId = strtolower($modelId);
        $score = 0;

        if (preg_match('/sonnet|m2\.7|m2\.5|m2\.1|gpt-5-mini|gpt-4\.1-mini/', $modelId) === 1) {
            $score += 120;
        }
        if (preg_match('/mini|small|nano|haiku|flash/', $modelId) === 1) {
            $score += 80;
        }
        if (preg_match('/code|coder/', $modelId) === 1) {
            $score += 30;
        }
        if (preg_match('/gpt|claude|qwen|deepseek|minimax|m2\./', $modelId) === 1) {
            $score += 10;
        }

        return $score;
    }
}
