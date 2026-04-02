<?php
/**
 * api/audit.php - Audit Log Export (CSV/JSON)
 *
 * Part of Doki v3 Architecture - Batch 11: Security Hardening
 */

require_once __DIR__ . '/../includes/session-bootstrap.php';
doki_start_session();

require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Auth.php';

$auth = new Auth();
$db = Database::getInstance();

// Require authentication
$token = $_SESSION['auth_token'] ?? null;
if (!$token) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$user = $auth->validateSession($token);
if (!$user || $user['role'] !== 'super-admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Super-admin access required']);
    exit;
}

$format = $_GET['format'] ?? 'json';
if (!in_array($format, ['json', 'csv'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid format']);
    exit;
}

// Filters
$filterAction = $_GET['action_filter'] ?? '';
$filterUser = $_GET['user_filter'] ?? '';
$filterResource = $_GET['resource_filter'] ?? '';
$filterDateFrom = $_GET['date_from'] ?? '';
$filterDateTo = $_GET['date_to'] ?? '';

$whereClause = [];
$params = [];

if ($filterAction) {
    $whereClause[] = "action = ?";
    $params[] = $filterAction;
}
if ($filterUser) {
    $whereClause[] = "(username LIKE ? OR user_id = ?)";
    $params[] = "%{$filterUser}%";
    $params[] = $filterUser;
}
if ($filterResource) {
    $whereClause[] = "(resource_type = ? OR resource_id LIKE ?)";
    $params[] = $filterResource;
    $params[] = "%{$filterResource}%";
}
if ($filterDateFrom) {
    $whereClause[] = "created_at >= ?";
    $params[] = $filterDateFrom . ' 00:00:00';
}
if ($filterDateTo) {
    $whereClause[] = "created_at <= ?";
    $params[] = $filterDateTo . ' 23:59:59';
}

$whereSQL = !empty($whereClause) ? 'WHERE ' . implode(' AND ', $whereClause) : '';

$sql = "SELECT * FROM audit_log $whereSQL ORDER BY created_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($format === 'json') {
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="audit-log.json"');
    echo json_encode($rows, JSON_PRETTY_PRINT);
    exit;
}

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="audit-log.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, array_keys($rows[0] ?? ['id','user_id','username','action','resource_type','resource_id','details','ip_address','created_at']));
foreach ($rows as $row) {
    fputcsv($out, $row);
}
fclose($out);
exit;
