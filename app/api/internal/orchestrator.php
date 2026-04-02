<?php
/**
 * Internal Orchestrator Control Plane API
 *
 * Actions:
 * - GET  ?action=config   -> returns templates/commands/targets/environments
 * - POST ?action=secrets  -> returns requested secrets
 * - POST ?action=events   -> accepts job/audit events
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);
ini_set('log_errors', '1');

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Content-Type: application/json');

require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/TemplateManager.php';
require_once __DIR__ . '/../../includes/CommandsManager.php';
require_once __DIR__ . '/../../includes/ConfigManager.php';
require_once __DIR__ . '/../../includes/PlaywrightProjectManager.php';

const MAX_OUTPUT_LENGTH = 1000000; // 1 MB
const MAX_STEP_OUTPUT_LENGTH = MAX_OUTPUT_LENGTH;

function jsonResponse(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function getAuthTokenFromRequest(): ?string {
    $token = $_SERVER['HTTP_X_DOKI_ORCH_TOKEN'] ?? null;
    if ($token) {
        return $token;
    }

    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
    if ($authHeader && preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
        return trim($m[1]);
    }

    return null;
}

function requireInternalAuth(): void {
    $expected = getenv('DOKI_ORCH_TOKEN');
    if (!$expected) {
        jsonResponse(['success' => false, 'error' => 'Internal token not configured'], 500);
    }

    $provided = getAuthTokenFromRequest();
    if (!$provided || !hash_equals($expected, $provided)) {
        jsonResponse(['success' => false, 'error' => 'Unauthorized'], 401);
    }
}

function ensureJobsTable(PDO $db): void {
    $db->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS execution_jobs (
            id TEXT PRIMARY KEY,
            command_id TEXT,
            command_name TEXT,
            template_id TEXT,
            target_id TEXT,
            user_id TEXT,
            username TEXT,
            status TEXT DEFAULT 'pending',
            is_dry_run INTEGER NOT NULL DEFAULT 0,
            inputs TEXT,
            plan_json TEXT,
            output TEXT DEFAULT '',
            exit_code INTEGER,
            error TEXT,
            started_at TEXT,
            completed_at TEXT,
            created_at TEXT DEFAULT (datetime('now'))
        )
    SQL);

    $db->exec(<<<SQL
        CREATE INDEX IF NOT EXISTS idx_jobs_user ON execution_jobs (user_id, created_at DESC)
    SQL);

    ensureColumnExists($db, 'execution_jobs', 'plan_json', 'TEXT');
    ensureColumnExists($db, 'execution_jobs', 'is_dry_run', 'INTEGER NOT NULL DEFAULT 0');
}

function ensureJobStepsTable(PDO $db): void {
    $db->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS execution_job_steps (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            job_id TEXT NOT NULL,
            step_id TEXT NOT NULL,
            step_index INTEGER DEFAULT 0,
            template_id TEXT,
            target_id TEXT,
            status TEXT DEFAULT 'pending',
            is_dry_run INTEGER NOT NULL DEFAULT 0,
            on_failure TEXT,
            depends_on_json TEXT,
            inputs TEXT,
            output TEXT DEFAULT '',
            exit_code INTEGER,
            error TEXT,
            started_at TEXT,
            completed_at TEXT,
            created_at TEXT DEFAULT (datetime('now')),
            UNIQUE(job_id, step_id)
        )
    SQL);

    $db->exec(<<<SQL
        CREATE INDEX IF NOT EXISTS idx_job_steps_job ON execution_job_steps (job_id, step_index, created_at)
    SQL);

    $db->exec(<<<SQL
        CREATE INDEX IF NOT EXISTS idx_job_steps_status ON execution_job_steps (status)
    SQL);

    ensureColumnExists($db, 'execution_job_steps', 'is_dry_run', 'INTEGER NOT NULL DEFAULT 0');
}

function ensureExecutionSchema(PDO $db): void {
    ensureJobsTable($db);
    ensureJobStepsTable($db);
}

function ensureColumnExists(PDO $db, string $table, string $column, string $definition): void {
    if (tableHasColumn($db, $table, $column)) {
        return;
    }

    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
        return;
    }

    $db->exec(sprintf('ALTER TABLE %s ADD COLUMN %s %s', $table, $column, $definition));
}

function tableHasColumn(PDO $db, string $table, string $column): bool {
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
        return false;
    }

    $stmt = $db->query(sprintf('PRAGMA table_info(%s)', $table));
    if ($stmt === false) {
        return false;
    }

    while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
        if (($row['name'] ?? '') === $column) {
            return true;
        }
    }
    return false;
}

function truncateOutput(string $output): string {
    if (strlen($output) <= MAX_OUTPUT_LENGTH) {
        return $output;
    }
    return substr($output, 0, MAX_OUTPUT_LENGTH) . "\n... (output truncated)";
}

function truncateStepOutput(string $output): string {
    if (strlen($output) <= MAX_STEP_OUTPUT_LENGTH) {
        return $output;
    }
    return substr($output, 0, MAX_STEP_OUTPUT_LENGTH) . "\n... (output truncated)";
}

function jsonField($value, string $fallback = '[]'): string {
    if ($value === null) {
        return $fallback;
    }
    if (is_string($value)) {
        $trimmed = trim($value);
        return $trimmed === '' ? $fallback : $trimmed;
    }
    $encoded = json_encode($value);
    return $encoded === false ? $fallback : $encoded;
}

function decodeJsonField($value, $fallback) {
    if (!is_string($value) || trim($value) === '') {
        return $fallback;
    }
    $decoded = json_decode($value, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return $fallback;
    }
    return $decoded;
}

function normalizeCommandMapsForJson(array $commands): array {
    foreach ($commands as $index => $command) {
        if (!is_array($command)) {
            continue;
        }

        if (array_key_exists('inputs', $command) && is_array($command['inputs']) && $command['inputs'] === []) {
            $command['inputs'] = (object)[];
        }
        if (array_key_exists('secrets', $command) && is_array($command['secrets']) && $command['secrets'] === []) {
            $command['secrets'] = (object)[];
        }

        if (isset($command['steps']) && is_array($command['steps'])) {
            foreach ($command['steps'] as $stepIndex => $step) {
                if (!is_array($step)) {
                    continue;
                }
                if (array_key_exists('inputs', $step) && is_array($step['inputs']) && $step['inputs'] === []) {
                    $step['inputs'] = (object)[];
                }
                if (array_key_exists('secrets', $step) && is_array($step['secrets']) && $step['secrets'] === []) {
                    $step['secrets'] = (object)[];
                }
                $command['steps'][$stepIndex] = $step;
            }
        }

        $commands[$index] = $command;
    }

    return $commands;
}

function normalizeEnvironmentsForJson($environments) {
    if (!is_array($environments) || $environments === []) {
        return (object)[];
    }

    $normalized = [];

    if (array_is_list($environments)) {
        foreach ($environments as $index => $environment) {
            if (!is_array($environment)) {
                continue;
            }

            $environmentId = trim((string)($environment['id'] ?? ''));
            if ($environmentId === '') {
                $environmentId = trim((string)($environment['name'] ?? ''));
            }
            if ($environmentId === '') {
                $environmentId = 'environment-' . ($index + 1);
            }

            unset($environment['id']);
            $normalized[$environmentId] = $environment;
        }
    } else {
        $normalized = $environments;
    }

    foreach ($normalized as $environmentId => $environment) {
        if (!is_array($environment)) {
            unset($normalized[$environmentId]);
            continue;
        }

        if (!array_key_exists('targets', $environment) || $environment['targets'] === null) {
            $environment['targets'] = (object)[];
        } elseif (is_array($environment['targets']) && $environment['targets'] === []) {
            $environment['targets'] = (object)[];
        }

        $normalized[$environmentId] = $environment;
    }

    return $normalized === [] ? (object)[] : $normalized;
}

function isTerminalStatus(string $status): bool {
    return in_array($status, ['completed', 'failed', 'cancelled', 'completed_with_warnings'], true);
}

function isTerminalStepStatus(string $status): bool {
    return in_array($status, ['completed', 'failed', 'cancelled', 'skipped'], true);
}

function insertJob(PDO $db, array $payload): void {
    $planJson = null;
    if (array_key_exists('planJson', $payload)) {
        $planJson = jsonField($payload['planJson'], 'null');
    } elseif (array_key_exists('plan', $payload)) {
        $planJson = jsonField($payload['plan'], 'null');
    }

    $stmt = $db->prepare(<<<SQL
        INSERT OR IGNORE INTO execution_jobs
        (id, command_id, command_name, template_id, target_id, user_id, username, status, is_dry_run, inputs, plan_json, started_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    SQL);

    $stmt->execute([
        $payload['jobId'] ?? null,
        $payload['commandId'] ?? null,
        $payload['commandName'] ?? null,
        $payload['templateId'] ?? null,
        $payload['targetId'] ?? null,
        $payload['userId'] ?? null,
        $payload['username'] ?? null,
        $payload['status'] ?? 'running',
        !empty($payload['isDryRun']) || !empty($payload['is_dry_run']) ? 1 : 0,
        jsonField($payload['inputs'] ?? [], '{}'),
        $planJson,
        $payload['startedAt'] ?? date('c'),
    ]);
}

function ensureJobExists(PDO $db, string $jobId): void {
    if ($jobId === '') {
        return;
    }

    $stmt = $db->prepare(<<<SQL
        INSERT OR IGNORE INTO execution_jobs
        (id, status, started_at)
        VALUES (?, ?, ?)
    SQL);
    $stmt->execute([
        $jobId,
        'pending',
        date('c'),
    ]);
}

function appendJobOutput(PDO $db, string $jobId, string $chunk): void {
    ensureJobExists($db, $jobId);

    $stmt = $db->prepare("SELECT output FROM execution_jobs WHERE id = ?");
    $stmt->execute([$jobId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $current = $row['output'] ?? '';

    $next = truncateOutput($current . $chunk);

    $update = $db->prepare("UPDATE execution_jobs SET output = ? WHERE id = ?");
    $update->execute([$next, $jobId]);
}

function updateJob(PDO $db, array $payload): void {
    $jobId = (string)($payload['jobId'] ?? '');
    if ($jobId === '') {
        return;
    }
    ensureJobExists($db, $jobId);

    $fields = [];
    $params = [];

    if (array_key_exists('status', $payload)) {
        $fields[] = 'status = ?';
        $params[] = $payload['status'];
    }

    if (array_key_exists('isDryRun', $payload) || array_key_exists('is_dry_run', $payload)) {
        $fields[] = 'is_dry_run = ?';
        $params[] = (!empty($payload['isDryRun']) || !empty($payload['is_dry_run'])) ? 1 : 0;
    }

    if (array_key_exists('exitCode', $payload)) {
        $fields[] = 'exit_code = ?';
        $params[] = $payload['exitCode'];
    }

    if (array_key_exists('error', $payload)) {
        $fields[] = 'error = ?';
        $params[] = $payload['error'];
    }

    if (array_key_exists('output', $payload)) {
        $fields[] = 'output = ?';
        $params[] = truncateOutput((string)($payload['output'] ?? ''));
    }

    if (array_key_exists('planJson', $payload)) {
        $fields[] = 'plan_json = ?';
        $params[] = jsonField($payload['planJson'], 'null');
    } elseif (array_key_exists('plan', $payload)) {
        $fields[] = 'plan_json = ?';
        $params[] = jsonField($payload['plan'], 'null');
    }

    if (!empty($payload['completedAt'])) {
        $fields[] = 'completed_at = ?';
        $params[] = $payload['completedAt'];
    } elseif (!empty($payload['status']) && isTerminalStatus((string)$payload['status'])) {
        $fields[] = 'completed_at = ?';
        $params[] = date('c');
    }

    if (empty($fields)) {
        return;
    }

    $params[] = $jobId;

    $sql = 'UPDATE execution_jobs SET ' . implode(', ', $fields) . ' WHERE id = ?';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
}

function insertJobStep(PDO $db, array $payload): void {
    $jobId = trim((string)($payload['jobId'] ?? ''));
    $stepId = trim((string)($payload['stepId'] ?? ''));
    if ($jobId === '' || $stepId === '') {
        return;
    }

    ensureJobExists($db, $jobId);

    $status = trim((string)($payload['status'] ?? 'pending'));
    if ($status === '') {
        $status = 'pending';
    }

    $stepIndexRaw = $payload['stepIndex'] ?? 0;
    $stepIndex = is_numeric($stepIndexRaw) ? (int)$stepIndexRaw : 0;
    $onFailure = $payload['onFailure'] ?? $payload['on_failure'] ?? null;
    $dependsOn = $payload['dependsOn'] ?? $payload['dependsOnJson'] ?? $payload['depends_on_json'] ?? [];

    $stmt = $db->prepare(<<<SQL
        INSERT OR IGNORE INTO execution_job_steps
        (job_id, step_id, step_index, template_id, target_id, status, is_dry_run, on_failure, depends_on_json, inputs, started_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    SQL);

    $stmt->execute([
        $jobId,
        $stepId,
        $stepIndex,
        $payload['templateId'] ?? null,
        $payload['targetId'] ?? null,
        $status,
        !empty($payload['isDryRun']) || !empty($payload['is_dry_run']) ? 1 : 0,
        $onFailure,
        jsonField($dependsOn, '[]'),
        jsonField($payload['inputs'] ?? [], '{}'),
        $payload['startedAt'] ?? date('c'),
    ]);
}

function appendJobStepOutput(PDO $db, string $jobId, string $stepId, string $chunk): void {
    if ($jobId === '' || $stepId === '') {
        return;
    }

    insertJobStep($db, ['jobId' => $jobId, 'stepId' => $stepId]);

    $stmt = $db->prepare("SELECT output FROM execution_job_steps WHERE job_id = ? AND step_id = ?");
    $stmt->execute([$jobId, $stepId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $current = $row['output'] ?? '';

    $next = truncateStepOutput($current . $chunk);

    $update = $db->prepare("UPDATE execution_job_steps SET output = ? WHERE job_id = ? AND step_id = ?");
    $update->execute([$next, $jobId, $stepId]);
}

function updateJobStep(PDO $db, array $payload): void {
    $jobId = trim((string)($payload['jobId'] ?? ''));
    $stepId = trim((string)($payload['stepId'] ?? ''));
    if ($jobId === '' || $stepId === '') {
        return;
    }

    insertJobStep($db, $payload);

    $fields = [];
    $params = [];

    if (array_key_exists('status', $payload)) {
        $fields[] = 'status = ?';
        $params[] = $payload['status'];
    }

    if (array_key_exists('isDryRun', $payload) || array_key_exists('is_dry_run', $payload)) {
        $fields[] = 'is_dry_run = ?';
        $params[] = (!empty($payload['isDryRun']) || !empty($payload['is_dry_run'])) ? 1 : 0;
    }

    if (array_key_exists('exitCode', $payload)) {
        $fields[] = 'exit_code = ?';
        $params[] = $payload['exitCode'];
    }

    if (array_key_exists('error', $payload)) {
        $fields[] = 'error = ?';
        $params[] = $payload['error'];
    }

    if (array_key_exists('output', $payload)) {
        $fields[] = 'output = ?';
        $params[] = truncateStepOutput((string)($payload['output'] ?? ''));
    }

    if (array_key_exists('templateId', $payload)) {
        $fields[] = 'template_id = ?';
        $params[] = $payload['templateId'];
    }

    if (array_key_exists('targetId', $payload)) {
        $fields[] = 'target_id = ?';
        $params[] = $payload['targetId'];
    }

    if (array_key_exists('stepIndex', $payload)) {
        $fields[] = 'step_index = ?';
        $params[] = is_numeric($payload['stepIndex']) ? (int)$payload['stepIndex'] : 0;
    }

    if (array_key_exists('onFailure', $payload)) {
        $fields[] = 'on_failure = ?';
        $params[] = $payload['onFailure'];
    } elseif (array_key_exists('on_failure', $payload)) {
        $fields[] = 'on_failure = ?';
        $params[] = $payload['on_failure'];
    }

    if (array_key_exists('dependsOn', $payload)) {
        $fields[] = 'depends_on_json = ?';
        $params[] = jsonField($payload['dependsOn'], '[]');
    } elseif (array_key_exists('dependsOnJson', $payload)) {
        $fields[] = 'depends_on_json = ?';
        $params[] = jsonField($payload['dependsOnJson'], '[]');
    } elseif (array_key_exists('depends_on_json', $payload)) {
        $fields[] = 'depends_on_json = ?';
        $params[] = jsonField($payload['depends_on_json'], '[]');
    }

    if (array_key_exists('inputs', $payload)) {
        $fields[] = 'inputs = ?';
        $params[] = jsonField($payload['inputs'], '{}');
    }

    if (!empty($payload['startedAt'])) {
        $fields[] = 'started_at = ?';
        $params[] = $payload['startedAt'];
    }

    if (!empty($payload['completedAt'])) {
        $fields[] = 'completed_at = ?';
        $params[] = $payload['completedAt'];
    } elseif (!empty($payload['status']) && isTerminalStepStatus((string)$payload['status'])) {
        $fields[] = 'completed_at = ?';
        $params[] = date('c');
    }

    if (empty($fields)) {
        return;
    }

    $params[] = $jobId;
    $params[] = $stepId;

    $sql = 'UPDATE execution_job_steps SET ' . implode(', ', $fields) . ' WHERE job_id = ? AND step_id = ?';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
}

function fetchJobSteps(PDO $db, string $jobId): array {
    $stmt = $db->prepare("
        SELECT * FROM execution_job_steps
        WHERE job_id = ?
        ORDER BY step_index ASC, created_at ASC, step_id ASC
    ");
    $stmt->execute([$jobId]);
    $steps = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($steps as &$step) {
        $step['inputs'] = decodeJsonField($step['inputs'] ?? '', []);
        $step['dependsOn'] = decodeJsonField($step['depends_on_json'] ?? '', []);
        $step['isDryRun'] = !empty($step['is_dry_run']);
    }
    return $steps;
}

function fetchJobStepSummary(PDO $db, string $jobId): array {
    $summary = [
        'total' => 0,
        'pending' => 0,
        'running' => 0,
        'completed' => 0,
        'failed' => 0,
        'cancelled' => 0,
        'skipped' => 0,
    ];

    $stmt = $db->prepare("
        SELECT status, COUNT(*) AS c
        FROM execution_job_steps
        WHERE job_id = ?
        GROUP BY status
    ");
    $stmt->execute([$jobId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $status = strtolower((string)($row['status'] ?? ''));
        $count = (int)($row['c'] ?? 0);
        $summary['total'] += $count;
        if (array_key_exists($status, $summary)) {
            $summary[$status] = $count;
        }
    }

    return $summary;
}

function normalizeJobRow(array $job): array {
    $job['inputs'] = decodeJsonField($job['inputs'] ?? '', []);
    $job['plan'] = decodeJsonField($job['plan_json'] ?? '', null);
    $job['isDryRun'] = !empty($job['is_dry_run']);
    return $job;
}

requireInternalAuth();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

$raw = file_get_contents('php://input');
$input = [];
if (!empty($raw)) {
    $input = json_decode($raw, true) ?? [];
}

switch ($action) {
    case 'config':
        if ($method !== 'GET') {
            jsonResponse(['success' => false, 'error' => 'GET required'], 405);
        }

        $templateManager = new TemplateManager();
        $templatesResult = $templateManager->getTemplates();
        if (empty($templatesResult['success'])) {
            jsonResponse(['success' => false, 'error' => $templatesResult['error'] ?? 'Failed to load templates'], 500);
        }

        $commandsManager = new CommandsManager();
        $commandsResult = $commandsManager->getCommands();
        if (empty($commandsResult['success'])) {
            jsonResponse(['success' => false, 'error' => $commandsResult['error'] ?? 'Failed to load commands'], 500);
        }
        $commandsPayload = normalizeCommandMapsForJson($commandsResult['commands'] ?? []);

        $config = new ConfigManager();
        $targets = $config->getTargets();
        $environments = normalizeEnvironmentsForJson($config->getEnvironments());

        jsonResponse([
            'success' => true,
            'bundle' => [
                'templates' => $templatesResult['templates'] ?? [],
                'commands' => $commandsPayload,
                'targets' => $targets,
                'environments' => $environments,
                'generatedAt' => date('c'),
            ],
        ]);
        break;

    case 'playwright-project':
        if ($method !== 'GET') {
            jsonResponse(['success' => false, 'error' => 'GET required'], 405);
        }

        $projectId = trim((string)($_GET['id'] ?? ''));
        $manager = new PlaywrightProjectManager();
        $defaultWrapper = $manager->getDefaultWrapperConfig();

        if ($projectId === '' || $projectId === '_default') {
            $playwrightRoot = __DIR__ . '/../../playwright';
            $metaFile = $playwrightRoot . '/project.yaml';
            $meta = [];
            if (file_exists($metaFile) && function_exists('yaml_parse_file')) {
                $meta = yaml_parse_file($metaFile) ?: [];
            }

            $wrapper = $defaultWrapper;
            if (!empty($meta['wrapperConfig']) && is_array($meta['wrapperConfig'])) {
                $wrapper = array_replace_recursive($defaultWrapper, $meta['wrapperConfig']);
            }

            jsonResponse([
                'success' => true,
                'project' => [
                    'id' => '_default',
                    'wrapperConfig' => $wrapper,
                ],
            ]);
        }

        $project = $manager->loadProject($projectId);
        if (!$project) {
            jsonResponse(['success' => false, 'error' => 'Project not found'], 404);
        }

        $wrapper = $defaultWrapper;
        if (!empty($project['wrapperConfig']) && is_array($project['wrapperConfig'])) {
            $wrapper = array_replace_recursive($defaultWrapper, $project['wrapperConfig']);
        }
        $project['wrapperConfig'] = $wrapper;

        jsonResponse([
            'success' => true,
            'project' => $project,
        ]);
        break;

    case 'job':
        if ($method !== 'GET') {
            jsonResponse(['success' => false, 'error' => 'GET required'], 405);
        }

        $jobId = $_GET['id'] ?? '';
        if ($jobId === '') {
            jsonResponse(['success' => false, 'error' => 'Job ID required'], 400);
        }

        $db = Database::getInstance();
        ensureExecutionSchema($db);
        $stmt = $db->prepare("SELECT * FROM execution_jobs WHERE id = ?");
        $stmt->execute([$jobId]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$job) {
            jsonResponse(['success' => false, 'error' => 'Job not found'], 404);
        }

        $job = normalizeJobRow($job);
        $job['steps'] = fetchJobSteps($db, $jobId);
        $job['stepsSummary'] = fetchJobStepSummary($db, $jobId);
        jsonResponse(['success' => true, 'job' => $job]);
        break;

    case 'jobs':
        if ($method !== 'GET') {
            jsonResponse(['success' => false, 'error' => 'GET required'], 405);
        }

        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
        if ($limit <= 0) {
            $limit = 50;
        }
        $limit = min($limit, 100);
        $includeSteps = true;
        if (isset($_GET['includeSteps'])) {
            $includeSteps = in_array(strtolower((string)$_GET['includeSteps']), ['1', 'true', 'yes'], true);
        }

        $db = Database::getInstance();
        ensureExecutionSchema($db);
        $userId = $_GET['userId'] ?? '';

        if ($userId !== '') {
            $stmt = $db->prepare("
                SELECT * FROM execution_jobs
                WHERE user_id = ?
                ORDER BY created_at DESC
                LIMIT ?
            ");
            $stmt->execute([$userId, $limit]);
        } else {
            $stmt = $db->prepare("
                SELECT * FROM execution_jobs
                ORDER BY created_at DESC
                LIMIT ?
            ");
            $stmt->execute([$limit]);
        }

        $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($jobs as &$job) {
            $job = normalizeJobRow($job);
            $job['stepsSummary'] = fetchJobStepSummary($db, (string)($job['id'] ?? ''));
            if ($includeSteps) {
                $job['steps'] = fetchJobSteps($db, (string)($job['id'] ?? ''));
            }
        }

        jsonResponse(['success' => true, 'jobs' => $jobs]);
        break;

    case 'secrets':
        if ($method !== 'POST') {
            jsonResponse(['success' => false, 'error' => 'POST required'], 405);
        }

        $config = new ConfigManager();
        $status = $config->getSecretsStatus();
        if (!empty($status['locked'])) {
            jsonResponse(['success' => false, 'locked' => true, 'error' => $status['reason'] ?? 'Secrets are locked'], 423);
        }

        $ids = $input['secretIds'] ?? [];
        if (!is_array($ids)) {
            jsonResponse(['success' => false, 'error' => 'secretIds must be an array'], 400);
        }

        $secrets = [];
        foreach ($ids as $id) {
            if (!is_string($id) || $id === '') {
                continue;
            }
            $secrets[$id] = $config->getSecret($id);
        }

        jsonResponse(['success' => true, 'secrets' => $secrets]);
        break;

    case 'events':
        if ($method !== 'POST') {
            jsonResponse(['success' => false, 'error' => 'POST required'], 405);
        }

        $events = $input['events'] ?? null;
        if (!is_array($events)) {
            jsonResponse(['success' => false, 'error' => 'events must be an array'], 400);
        }

        $db = Database::getInstance();
        ensureExecutionSchema($db);
        $auth = new Auth();

        $processed = 0;
        $errors = [];

        foreach ($events as $event) {
            $type = $event['type'] ?? '';
            $payload = $event['payload'] ?? [];
            try {
                switch ($type) {
                    case 'job.create':
                        insertJob($db, $payload);
                        break;
                    case 'job.append_output':
                        if (!empty($payload['jobId']) && isset($payload['chunk'])) {
                            appendJobOutput($db, $payload['jobId'], (string)$payload['chunk']);
                        }
                        break;
                    case 'job.update':
                        updateJob($db, $payload);
                        break;
                    case 'job.step.create':
                        insertJobStep($db, $payload);
                        break;
                    case 'job.step.append_output':
                        if (!empty($payload['jobId']) && !empty($payload['stepId']) && isset($payload['chunk'])) {
                            appendJobStepOutput($db, (string)$payload['jobId'], (string)$payload['stepId'], (string)$payload['chunk']);
                        }
                        break;
                    case 'job.step.update':
                        updateJobStep($db, $payload);
                        break;
                    case 'audit':
                        $auth->auditLog(
                            $payload['userId'] ?? null,
                            $payload['username'] ?? null,
                            $payload['action'] ?? 'orchestrator.event',
                            $payload['resourceType'] ?? null,
                            $payload['resourceId'] ?? null,
                            $payload['details'] ?? null,
                            $payload['ip'] ?? null
                        );
                        break;
                    default:
                        $errors[] = "Unknown event type: {$type}";
                        continue 2;
                }
                $processed++;
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }

        jsonResponse([
            'success' => empty($errors),
            'processed' => $processed,
            'errors' => $errors,
        ], empty($errors) ? 200 : 207);
        break;

    default:
        jsonResponse(['success' => false, 'error' => 'Unknown action'], 400);
}
