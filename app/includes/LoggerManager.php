<?php
/**
 * LoggerManager.php - App table storage for the core Logger app
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/LoggerFilterMatcher.php';
require_once __DIR__ . '/LoggerTargetResolver.php';

class LoggerManager {
    private PDO $db;

    private const ENTRIES_TABLE = 'app_logger_entries';
    private const SOURCES_TABLE = 'app_logger_entry_sources';
    private const GROUPS_TABLE = 'app_logger_groups';
    private const GROUP_ENTRIES_TABLE = 'app_logger_group_entries';
    private const FILTERS_TABLE = 'app_logger_filters';

    public function __construct(?PDO $db = null) {
        $this->db = $db ?? Database::getInstance();
        $this->ensureSchema();
    }

    public function ensureSchema(): void {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS " . self::ENTRIES_TABLE . " (
                id TEXT PRIMARY KEY,
                slug TEXT NOT NULL UNIQUE,
                name TEXT NOT NULL,
                mode TEXT NOT NULL DEFAULT 'direct' CHECK (mode IN ('direct', 'environment')),
                environment_id TEXT,
                enabled INTEGER NOT NULL DEFAULT 1,
                created_by TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS " . self::SOURCES_TABLE . " (
                id TEXT PRIMARY KEY,
                entry_id TEXT NOT NULL,
                source_name TEXT NOT NULL,
                target_id TEXT,
                environment_target_key TEXT,
                path TEXT NOT NULL,
                target_type TEXT,
                enabled INTEGER NOT NULL DEFAULT 1,
                display_order INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now')),
                FOREIGN KEY (entry_id) REFERENCES " . self::ENTRIES_TABLE . "(id) ON DELETE CASCADE
            )
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS " . self::GROUPS_TABLE . " (
                id TEXT PRIMARY KEY,
                slug TEXT NOT NULL UNIQUE,
                name TEXT NOT NULL,
                description TEXT,
                created_by TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS " . self::GROUP_ENTRIES_TABLE . " (
                group_id TEXT NOT NULL,
                entry_id TEXT NOT NULL,
                display_order INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (group_id, entry_id),
                FOREIGN KEY (group_id) REFERENCES " . self::GROUPS_TABLE . "(id) ON DELETE CASCADE,
                FOREIGN KEY (entry_id) REFERENCES " . self::ENTRIES_TABLE . "(id) ON DELETE CASCADE
            )
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS " . self::FILTERS_TABLE . " (
                id TEXT PRIMARY KEY,
                owner_type TEXT NOT NULL CHECK (owner_type IN ('entry', 'group')),
                owner_id TEXT NOT NULL,
                name TEXT NOT NULL,
                mode TEXT NOT NULL DEFAULT 'contains_all',
                pattern TEXT NOT NULL,
                case_sensitive INTEGER NOT NULL DEFAULT 0,
                enabled INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        ");

        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_logger_entries_slug ON " . self::ENTRIES_TABLE . " (slug)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_logger_sources_entry ON " . self::SOURCES_TABLE . " (entry_id, display_order)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_logger_groups_slug ON " . self::GROUPS_TABLE . " (slug)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_logger_group_entries_group ON " . self::GROUP_ENTRIES_TABLE . " (group_id, display_order)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_logger_filters_owner ON " . self::FILTERS_TABLE . " (owner_type, owner_id)");
    }

    public function listEntries(): array {
        $stmt = $this->db->query("
            SELECT
                e.*,
                COUNT(DISTINCT s.id) AS source_count,
                COUNT(DISTINCT f.id) AS filter_count
            FROM " . self::ENTRIES_TABLE . " e
            LEFT JOIN " . self::SOURCES_TABLE . " s ON s.entry_id = e.id
            LEFT JOIN " . self::FILTERS_TABLE . " f ON f.owner_type = 'entry' AND f.owner_id = e.id
            GROUP BY e.id
            ORDER BY lower(e.name) ASC
        ");

        return array_map([$this, 'normalizeEntryRow'], $stmt->fetchAll());
    }

    public function listGroups(): array {
        $stmt = $this->db->query("
            SELECT
                g.*,
                COUNT(DISTINCT ge.entry_id) AS entry_count,
                COUNT(DISTINCT s.id) AS source_count,
                COUNT(DISTINCT f.id) AS filter_count
            FROM " . self::GROUPS_TABLE . " g
            LEFT JOIN " . self::GROUP_ENTRIES_TABLE . " ge ON ge.group_id = g.id
            LEFT JOIN " . self::SOURCES_TABLE . " s ON s.entry_id = ge.entry_id
            LEFT JOIN " . self::FILTERS_TABLE . " f ON f.owner_type = 'group' AND f.owner_id = g.id
            GROUP BY g.id
            ORDER BY lower(g.name) ASC
        ");

        return array_map([$this, 'normalizeGroupRow'], $stmt->fetchAll());
    }

    public function getEntry(string $identifier): ?array {
        $stmt = $this->db->prepare("
            SELECT
                e.*,
                COUNT(DISTINCT s.id) AS source_count,
                COUNT(DISTINCT f.id) AS filter_count
            FROM " . self::ENTRIES_TABLE . " e
            LEFT JOIN " . self::SOURCES_TABLE . " s ON s.entry_id = e.id
            LEFT JOIN " . self::FILTERS_TABLE . " f ON f.owner_type = 'entry' AND f.owner_id = e.id
            WHERE e.id = ? OR e.slug = ?
            GROUP BY e.id
            LIMIT 1
        ");
        $stmt->execute([$identifier, $identifier]);
        $entry = $stmt->fetch();
        if (!$entry) {
            return null;
        }

        $entry = $this->normalizeEntryRow($entry);
        $entry['sources'] = $this->listEntrySources($entry['id']);
        $entry['filters'] = $this->listFilters('entry', $entry['id']);
        return $entry;
    }

    public function getGroup(string $identifier): ?array {
        $stmt = $this->db->prepare("
            SELECT
                g.*,
                COUNT(DISTINCT ge.entry_id) AS entry_count,
                COUNT(DISTINCT s.id) AS source_count,
                COUNT(DISTINCT f.id) AS filter_count
            FROM " . self::GROUPS_TABLE . " g
            LEFT JOIN " . self::GROUP_ENTRIES_TABLE . " ge ON ge.group_id = g.id
            LEFT JOIN " . self::SOURCES_TABLE . " s ON s.entry_id = ge.entry_id
            LEFT JOIN " . self::FILTERS_TABLE . " f ON f.owner_type = 'group' AND f.owner_id = g.id
            WHERE g.id = ? OR g.slug = ?
            GROUP BY g.id
            LIMIT 1
        ");
        $stmt->execute([$identifier, $identifier]);
        $group = $stmt->fetch();
        if (!$group) {
            return null;
        }

        $group = $this->normalizeGroupRow($group);
        $group['entries'] = $this->listGroupEntries($group['id']);
        $group['filters'] = $this->listFilters('group', $group['id']);
        return $group;
    }

    public function getStats(): array {
        return [
            'entries' => $this->countRows(self::ENTRIES_TABLE),
            'sources' => $this->countRows(self::SOURCES_TABLE),
            'groups' => $this->countRows(self::GROUPS_TABLE),
            'filters' => $this->countRows(self::FILTERS_TABLE),
        ];
    }

    public function saveEntry(array $payload, array $user, LoggerTargetResolver $resolver): array {
        $normalized = $this->normalizeEntryPayload($payload);
        if (!$normalized['valid']) {
            return ['success' => false, 'errors' => $normalized['errors']];
        }

        $entry = $normalized['entry'];
        $sources = $normalized['sources'];
        $validation = $resolver->validateEntrySources($entry, $sources);
        if (!$validation['valid']) {
            return ['success' => false, 'errors' => $validation['errors']];
        }

        $existing = null;
        if ($entry['id'] !== '') {
            $existing = $this->getEntry($entry['id']);
            if ($existing === null) {
                return ['success' => false, 'errors' => ['Logger entry not found']];
            }
        }

        $entryId = $existing['id'] ?? Database::generateUUID();
        $slug = $existing['slug'] ?? $this->uniqueSlug($entry['name']);
        $createdBy = $existing['created_by'] ?? ($user['id'] ?? null);

        try {
            $this->db->beginTransaction();

            if ($existing === null) {
                $stmt = $this->db->prepare("
                    INSERT INTO " . self::ENTRIES_TABLE . "
                        (id, slug, name, mode, environment_id, enabled, created_by, created_at, updated_at)
                    VALUES
                        (?, ?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))
                ");
                $stmt->execute([
                    $entryId,
                    $slug,
                    $entry['name'],
                    $entry['mode'],
                    $entry['environment_id'],
                    $entry['enabled'] ? 1 : 0,
                    $createdBy,
                ]);
            } else {
                $stmt = $this->db->prepare("
                    UPDATE " . self::ENTRIES_TABLE . "
                    SET name = ?,
                        mode = ?,
                        environment_id = ?,
                        enabled = ?,
                        updated_at = datetime('now')
                    WHERE id = ?
                ");
                $stmt->execute([
                    $entry['name'],
                    $entry['mode'],
                    $entry['environment_id'],
                    $entry['enabled'] ? 1 : 0,
                    $entryId,
                ]);

                $delete = $this->db->prepare("DELETE FROM " . self::SOURCES_TABLE . " WHERE entry_id = ?");
                $delete->execute([$entryId]);
            }

            $this->insertEntrySources($entryId, $entry, $sources, $resolver);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['success' => false, 'errors' => [$e->getMessage()]];
        }

        return [
            'success' => true,
            'entry' => $this->getEntry($entryId),
        ];
    }

    public function deleteEntry(string $identifier): array {
        $entry = $this->getEntry($identifier);
        if ($entry === null) {
            return ['success' => false, 'errors' => ['Logger entry not found']];
        }

        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare("DELETE FROM " . self::FILTERS_TABLE . " WHERE owner_type = 'entry' AND owner_id = ?");
            $stmt->execute([$entry['id']]);

            $stmt = $this->db->prepare("DELETE FROM " . self::GROUP_ENTRIES_TABLE . " WHERE entry_id = ?");
            $stmt->execute([$entry['id']]);

            $stmt = $this->db->prepare("DELETE FROM " . self::SOURCES_TABLE . " WHERE entry_id = ?");
            $stmt->execute([$entry['id']]);

            $stmt = $this->db->prepare("DELETE FROM " . self::ENTRIES_TABLE . " WHERE id = ?");
            $stmt->execute([$entry['id']]);

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['success' => false, 'errors' => [$e->getMessage()]];
        }

        return ['success' => true, 'deletedEntryId' => $entry['id']];
    }

    public function saveGroup(array $payload, array $user): array {
        $normalized = $this->normalizeGroupPayload($payload);
        if (!$normalized['valid']) {
            return ['success' => false, 'errors' => $normalized['errors']];
        }

        $group = $normalized['group'];
        $entryIds = $normalized['entryIds'];
        $existing = null;
        if ($group['id'] !== '') {
            $existing = $this->getGroup($group['id']);
            if ($existing === null) {
                return ['success' => false, 'errors' => ['Logger group not found']];
            }
        }

        $missingEntryIds = $this->missingEntryIds($entryIds);
        if ($missingEntryIds !== []) {
            return ['success' => false, 'errors' => ['Unknown logger entries: ' . implode(', ', $missingEntryIds)]];
        }

        $groupId = $existing['id'] ?? Database::generateUUID();
        $slug = $existing['slug'] ?? $this->uniqueGroupSlug($group['name']);
        $createdBy = $existing['created_by'] ?? ($user['id'] ?? null);

        try {
            $this->db->beginTransaction();

            if ($existing === null) {
                $stmt = $this->db->prepare("
                    INSERT INTO " . self::GROUPS_TABLE . "
                        (id, slug, name, description, created_by, created_at, updated_at)
                    VALUES
                        (?, ?, ?, ?, ?, datetime('now'), datetime('now'))
                ");
                $stmt->execute([
                    $groupId,
                    $slug,
                    $group['name'],
                    $group['description'],
                    $createdBy,
                ]);
            } else {
                $stmt = $this->db->prepare("
                    UPDATE " . self::GROUPS_TABLE . "
                    SET name = ?,
                        description = ?,
                        updated_at = datetime('now')
                    WHERE id = ?
                ");
                $stmt->execute([
                    $group['name'],
                    $group['description'],
                    $groupId,
                ]);

                $stmt = $this->db->prepare("DELETE FROM " . self::GROUP_ENTRIES_TABLE . " WHERE group_id = ?");
                $stmt->execute([$groupId]);
            }

            $stmt = $this->db->prepare("
                INSERT INTO " . self::GROUP_ENTRIES_TABLE . "
                    (group_id, entry_id, display_order)
                VALUES
                    (?, ?, ?)
            ");
            foreach ($entryIds as $index => $entryId) {
                $stmt->execute([$groupId, $entryId, $index]);
            }

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['success' => false, 'errors' => [$e->getMessage()]];
        }

        return [
            'success' => true,
            'group' => $this->getGroup($groupId),
        ];
    }

    public function deleteGroup(string $identifier): array {
        $group = $this->getGroup($identifier);
        if ($group === null) {
            return ['success' => false, 'errors' => ['Logger group not found']];
        }

        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare("DELETE FROM " . self::FILTERS_TABLE . " WHERE owner_type = 'group' AND owner_id = ?");
            $stmt->execute([$group['id']]);

            $stmt = $this->db->prepare("DELETE FROM " . self::GROUP_ENTRIES_TABLE . " WHERE group_id = ?");
            $stmt->execute([$group['id']]);

            $stmt = $this->db->prepare("DELETE FROM " . self::GROUPS_TABLE . " WHERE id = ?");
            $stmt->execute([$group['id']]);

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['success' => false, 'errors' => [$e->getMessage()]];
        }

        return ['success' => true, 'deletedGroupId' => $group['id']];
    }

    public function listAllFilters(): array {
        $stmt = $this->db->query("
            SELECT
                f.*,
                COALESCE(e.name, g.name, '') AS owner_name,
                COALESCE(e.slug, g.slug, '') AS owner_slug
            FROM " . self::FILTERS_TABLE . " f
            LEFT JOIN " . self::ENTRIES_TABLE . " e ON f.owner_type = 'entry' AND f.owner_id = e.id
            LEFT JOIN " . self::GROUPS_TABLE . " g ON f.owner_type = 'group' AND f.owner_id = g.id
            ORDER BY f.owner_type ASC, lower(COALESCE(e.name, g.name, '')) ASC, lower(f.name) ASC
        ");

        return array_map([$this, 'normalizeFilterRow'], $stmt->fetchAll());
    }

    public function listFilters(string $ownerType, string $ownerId): array {
        $ownerType = trim($ownerType);
        $ownerId = trim($ownerId);
        if (!in_array($ownerType, ['entry', 'group'], true) || $ownerId === '') {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT *
            FROM " . self::FILTERS_TABLE . "
            WHERE owner_type = ? AND owner_id = ?
            ORDER BY enabled DESC, lower(name) ASC
        ");
        $stmt->execute([$ownerType, $ownerId]);

        return array_map([$this, 'normalizeFilterRow'], $stmt->fetchAll());
    }

    public function getFilter(string $id): ?array {
        $id = trim($id);
        if ($id === '') {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT
                f.*,
                COALESCE(e.name, g.name, '') AS owner_name,
                COALESCE(e.slug, g.slug, '') AS owner_slug
            FROM " . self::FILTERS_TABLE . " f
            LEFT JOIN " . self::ENTRIES_TABLE . " e ON f.owner_type = 'entry' AND f.owner_id = e.id
            LEFT JOIN " . self::GROUPS_TABLE . " g ON f.owner_type = 'group' AND f.owner_id = g.id
            WHERE f.id = ?
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $filter = $stmt->fetch();

        return $filter ? $this->normalizeFilterRow($filter) : null;
    }

    public function saveFilter(array $payload, array $user): array {
        $normalized = $this->normalizeFilterPayload($payload);
        if (!$normalized['valid']) {
            return ['success' => false, 'errors' => $normalized['errors']];
        }

        $filter = $normalized['filter'];
        if (!$this->filterOwnerExists($filter['owner_type'], $filter['owner_id'])) {
            return ['success' => false, 'errors' => ['Filter owner not found']];
        }

        $existing = null;
        if ($filter['id'] !== '') {
            $existing = $this->getFilter($filter['id']);
            if ($existing === null) {
                return ['success' => false, 'errors' => ['Logger filter not found']];
            }
        }

        $filterId = $existing['id'] ?? Database::generateUUID();

        try {
            if ($existing === null) {
                $stmt = $this->db->prepare("
                    INSERT INTO " . self::FILTERS_TABLE . "
                        (id, owner_type, owner_id, name, mode, pattern, case_sensitive, enabled, created_at, updated_at)
                    VALUES
                        (?, ?, ?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))
                ");
                $stmt->execute([
                    $filterId,
                    $filter['owner_type'],
                    $filter['owner_id'],
                    $filter['name'],
                    $filter['mode'],
                    $filter['pattern'],
                    $filter['case_sensitive'] ? 1 : 0,
                    $filter['enabled'] ? 1 : 0,
                ]);
            } else {
                $stmt = $this->db->prepare("
                    UPDATE " . self::FILTERS_TABLE . "
                    SET owner_type = ?,
                        owner_id = ?,
                        name = ?,
                        mode = ?,
                        pattern = ?,
                        case_sensitive = ?,
                        enabled = ?,
                        updated_at = datetime('now')
                    WHERE id = ?
                ");
                $stmt->execute([
                    $filter['owner_type'],
                    $filter['owner_id'],
                    $filter['name'],
                    $filter['mode'],
                    $filter['pattern'],
                    $filter['case_sensitive'] ? 1 : 0,
                    $filter['enabled'] ? 1 : 0,
                    $filterId,
                ]);
            }
        } catch (Throwable $e) {
            return ['success' => false, 'errors' => [$e->getMessage()]];
        }

        return [
            'success' => true,
            'filter' => $this->getFilter($filterId),
        ];
    }

    public function deleteFilter(string $id): array {
        $filter = $this->getFilter($id);
        if ($filter === null) {
            return ['success' => false, 'errors' => ['Logger filter not found']];
        }

        $stmt = $this->db->prepare("DELETE FROM " . self::FILTERS_TABLE . " WHERE id = ?");
        $stmt->execute([$filter['id']]);

        return ['success' => true, 'deletedFilterId' => $filter['id']];
    }

    private function listEntrySources(string $entryId): array {
        $stmt = $this->db->prepare("
            SELECT *
            FROM " . self::SOURCES_TABLE . "
            WHERE entry_id = ?
            ORDER BY display_order ASC, lower(source_name) ASC
        ");
        $stmt->execute([$entryId]);

        return array_map([$this, 'normalizeSourceRow'], $stmt->fetchAll());
    }

    private function insertEntrySources(string $entryId, array $entry, array $sources, LoggerTargetResolver $resolver): void {
        $stmt = $this->db->prepare("
            INSERT INTO " . self::SOURCES_TABLE . "
                (id, entry_id, source_name, target_id, environment_target_key, path, target_type, enabled, display_order, created_at, updated_at)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))
        ");

        foreach (array_values($sources) as $index => $source) {
            $resolution = $resolver->resolveSourceTarget($entry, $source);
            $target = is_array($resolution['target'] ?? null) ? $resolution['target'] : null;
            $stmt->execute([
                Database::generateUUID(),
                $entryId,
                trim((string)($source['source_name'] ?? $source['name'] ?? '')),
                $entry['mode'] === 'direct' ? trim((string)($source['target_id'] ?? '')) : null,
                $entry['mode'] === 'environment' ? trim((string)($source['environment_target_key'] ?? '')) : null,
                trim((string)($source['path'] ?? '')),
                $target['type'] ?? ($source['target_type'] ?? null),
                array_key_exists('enabled', $source) ? ((bool)$source['enabled'] ? 1 : 0) : 1,
                array_key_exists('display_order', $source) ? (int)$source['display_order'] : $index,
            ]);
        }
    }

    private function normalizeEntryPayload(array $payload): array {
        $errors = [];
        $name = trim((string)($payload['name'] ?? ''));
        $mode = trim((string)($payload['mode'] ?? 'direct'));
        $enabled = array_key_exists('enabled', $payload) ? (bool)$payload['enabled'] : true;
        $environmentId = trim((string)($payload['environment_id'] ?? $payload['environmentId'] ?? ''));
        $sources = $payload['sources'] ?? [];

        if ($name === '') {
            $errors[] = 'Entry name is required';
        }
        if (!in_array($mode, ['direct', 'environment'], true)) {
            $errors[] = 'Entry mode must be direct or environment';
        }
        if ($mode === 'environment' && $environmentId === '') {
            $errors[] = 'Environment entries require an environment';
        }
        if (!is_array($sources) || array_is_list($sources) === false) {
            $errors[] = 'Sources must be a list';
            $sources = [];
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'entry' => [
                'id' => trim((string)($payload['id'] ?? '')),
                'name' => $name,
                'mode' => $mode,
                'environment_id' => $mode === 'environment' ? $environmentId : null,
                'enabled' => $enabled,
            ],
            'sources' => $this->normalizeSourcePayloads($sources),
        ];
    }

    private function normalizeSourcePayloads(array $sources): array {
        $normalized = [];
        foreach ($sources as $index => $source) {
            if (!is_array($source)) {
                continue;
            }
            $normalized[] = [
                'source_name' => trim((string)($source['source_name'] ?? $source['name'] ?? '')),
                'target_id' => trim((string)($source['target_id'] ?? $source['targetId'] ?? '')),
                'environment_target_key' => trim((string)($source['environment_target_key'] ?? $source['environmentTargetKey'] ?? '')),
                'path' => trim((string)($source['path'] ?? '')),
                'target_type' => trim((string)($source['target_type'] ?? $source['targetType'] ?? '')),
                'enabled' => array_key_exists('enabled', $source) ? (bool)$source['enabled'] : true,
                'display_order' => array_key_exists('display_order', $source) ? (int)$source['display_order'] : $index,
            ];
        }
        return $normalized;
    }

    private function normalizeGroupPayload(array $payload): array {
        $errors = [];
        $name = trim((string)($payload['name'] ?? ''));
        $description = trim((string)($payload['description'] ?? ''));
        $entryIds = $payload['entry_ids'] ?? $payload['entryIds'] ?? [];

        if ($name === '') {
            $errors[] = 'Group name is required';
        }
        if (!is_array($entryIds) || array_is_list($entryIds) === false) {
            $errors[] = 'Group entries must be a list';
            $entryIds = [];
        }

        $entryIds = array_values(array_unique(array_filter(array_map(
            fn($entryId) => trim((string)$entryId),
            $entryIds
        ))));

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'group' => [
                'id' => trim((string)($payload['id'] ?? '')),
                'name' => $name,
                'description' => $description,
            ],
            'entryIds' => $entryIds,
        ];
    }

    private function normalizeFilterPayload(array $payload): array {
        $errors = [];
        $ownerType = trim((string)($payload['owner_type'] ?? $payload['ownerType'] ?? ''));
        $ownerId = trim((string)($payload['owner_id'] ?? $payload['ownerId'] ?? ''));
        $name = trim((string)($payload['name'] ?? ''));
        $mode = LoggerFilterMatcher::normalizeMode((string)($payload['mode'] ?? 'contains_all'));
        $pattern = trim(str_replace(["\0", "\r", "\n"], ' ', (string)($payload['pattern'] ?? '')));
        $caseSensitive = array_key_exists('case_sensitive', $payload)
            ? (bool)$payload['case_sensitive']
            : (bool)($payload['caseSensitive'] ?? false);
        $enabled = array_key_exists('enabled', $payload) ? (bool)$payload['enabled'] : true;

        if (!in_array($ownerType, ['entry', 'group'], true)) {
            $errors[] = 'Filter owner type must be entry or group';
        }
        if ($ownerId === '') {
            $errors[] = 'Filter owner is required';
        }
        if ($name === '') {
            $errors[] = 'Filter name is required';
        }
        if ($pattern === '') {
            $errors[] = 'Filter pattern is required';
        }
        if (strlen($name) > 120) {
            $errors[] = 'Filter name must be 120 characters or fewer';
        }
        if (strlen($pattern) > 300) {
            $errors[] = 'Filter pattern must be 300 characters or fewer';
        }
        if (LoggerFilterMatcher::termsForPattern($pattern) === []) {
            $errors[] = 'Filter pattern needs at least one searchable term';
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'filter' => [
                'id' => trim((string)($payload['id'] ?? '')),
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
                'name' => substr($name, 0, 120),
                'mode' => $mode,
                'pattern' => substr($pattern, 0, 300),
                'case_sensitive' => $caseSensitive,
                'enabled' => $enabled,
            ],
        ];
    }

    private function filterOwnerExists(string $ownerType, string $ownerId): bool {
        $table = $ownerType === 'entry' ? self::ENTRIES_TABLE : self::GROUPS_TABLE;
        $stmt = $this->db->prepare("SELECT 1 FROM {$table} WHERE id = ? LIMIT 1");
        $stmt->execute([$ownerId]);
        return (bool)$stmt->fetch();
    }

    private function uniqueSlug(string $name): string {
        $base = $this->slugify($name);
        if ($base === '') {
            $base = 'logger-entry';
        }

        $slug = $base;
        $suffix = 2;
        while ($this->slugExists($slug)) {
            $slug = $base . '-' . $suffix;
            $suffix++;
        }

        return $slug;
    }

    private function uniqueGroupSlug(string $name): string {
        $base = $this->slugify($name);
        if ($base === '') {
            $base = 'logger-group';
        }

        $slug = $base;
        $suffix = 2;
        while ($this->groupSlugExists($slug)) {
            $slug = $base . '-' . $suffix;
            $suffix++;
        }

        return $slug;
    }

    private function slugify(string $value): string {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        $value = trim($value, '-');
        return substr($value, 0, 80);
    }

    private function slugExists(string $slug): bool {
        $stmt = $this->db->prepare("SELECT 1 FROM " . self::ENTRIES_TABLE . " WHERE slug = ? LIMIT 1");
        $stmt->execute([$slug]);
        return (bool)$stmt->fetch();
    }

    private function groupSlugExists(string $slug): bool {
        $stmt = $this->db->prepare("SELECT 1 FROM " . self::GROUPS_TABLE . " WHERE slug = ? LIMIT 1");
        $stmt->execute([$slug]);
        return (bool)$stmt->fetch();
    }

    private function missingEntryIds(array $entryIds): array {
        if ($entryIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($entryIds), '?'));
        $stmt = $this->db->prepare("SELECT id FROM " . self::ENTRIES_TABLE . " WHERE id IN ({$placeholders})");
        $stmt->execute($entryIds);
        $found = array_flip(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)));

        return array_values(array_filter($entryIds, fn(string $entryId): bool => !isset($found[$entryId])));
    }

    private function listGroupEntries(string $groupId): array {
        $stmt = $this->db->prepare("
            SELECT
                e.*,
                ge.display_order,
                COUNT(DISTINCT s.id) AS source_count
            FROM " . self::GROUP_ENTRIES_TABLE . " ge
            JOIN " . self::ENTRIES_TABLE . " e ON e.id = ge.entry_id
            LEFT JOIN " . self::SOURCES_TABLE . " s ON s.entry_id = e.id
            WHERE ge.group_id = ?
            GROUP BY e.id, ge.display_order
            ORDER BY ge.display_order ASC, lower(e.name) ASC
        ");
        $stmt->execute([$groupId]);

        return array_map([$this, 'normalizeEntryRow'], $stmt->fetchAll());
    }

    private function countRows(string $table): int {
        $stmt = $this->db->query("SELECT COUNT(*) AS count FROM {$table}");
        $row = $stmt->fetch();
        return (int)($row['count'] ?? 0);
    }

    private function normalizeEntryRow(array $row): array {
        $row['enabled'] = (bool)($row['enabled'] ?? false);
        $row['source_count'] = (int)($row['source_count'] ?? 0);
        $row['filter_count'] = (int)($row['filter_count'] ?? 0);
        return $row;
    }

    private function normalizeGroupRow(array $row): array {
        $row['entry_count'] = (int)($row['entry_count'] ?? 0);
        $row['source_count'] = (int)($row['source_count'] ?? 0);
        $row['filter_count'] = (int)($row['filter_count'] ?? 0);
        return $row;
    }

    private function normalizeSourceRow(array $row): array {
        $row['enabled'] = (bool)($row['enabled'] ?? false);
        $row['display_order'] = (int)($row['display_order'] ?? 0);
        return $row;
    }

    private function normalizeFilterRow(array $row): array {
        $row['mode'] = LoggerFilterMatcher::normalizeMode((string)($row['mode'] ?? 'contains_all'));
        $row['case_sensitive'] = (bool)($row['case_sensitive'] ?? false);
        $row['enabled'] = (bool)($row['enabled'] ?? false);
        $row['terms'] = LoggerFilterMatcher::termsForPattern((string)($row['pattern'] ?? ''));
        return $row;
    }
}
