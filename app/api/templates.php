<?php
/**
 * Templates API
 * Read-only endpoints for viewing template definitions
 */

require_once __DIR__ . '/../includes/session-bootstrap.php';
doki_start_session();
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/TemplateManager.php';

// Initialize
$db = Database::getInstance();
$auth = new Auth($db);
$templates = new TemplateManager();

// Check authentication
$token = $_SESSION['auth_token'] ?? null;
if (!$token) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$user = $auth->validateSession($token);
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Session expired']);
    exit;
}

// Get action
$action = $_GET['action'] ?? '';

function jsonResponse($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

switch ($action) {
    case 'list':
        // Get all templates
        $result = $templates->getTemplates();
        jsonResponse($result);
        break;
        
    case 'get':
        // Get a single template
        $id = $_GET['id'] ?? '';
        if (empty($id)) {
            jsonResponse(['success' => false, 'error' => 'Template ID required'], 400);
        }
        
        $template = $templates->getTemplate($id);
        if (!$template) {
            jsonResponse(['success' => false, 'error' => 'Template not found'], 404);
        }
        
        jsonResponse(['success' => true, 'template' => $template]);
        break;
        
    case 'categories':
        // Get template categories
        $categories = $templates->getCategories();
        jsonResponse(['success' => true, 'categories' => $categories]);
        break;
        
    case 'validate-inputs':
        // Validate inputs for a template
        $input = json_decode(file_get_contents('php://input'), true);
        $templateId = $input['templateId'] ?? '';
        $values = $input['values'] ?? [];
        
        if (empty($templateId)) {
            jsonResponse(['success' => false, 'error' => 'Template ID required'], 400);
        }
        
        $result = $templates->validateInputs($templateId, $values);
        jsonResponse(['success' => true, 'validation' => $result]);
        break;
        
    default:
        jsonResponse(['success' => false, 'error' => 'Unknown action'], 400);
}
