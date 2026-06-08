<?php
/**
 * Logger App - Legacy admin route.
 *
 * Logger administration now happens from the main workspace with modals.
 */

require_once __DIR__ . '/../../includes/AppContext.php';

$app = new AppContext('logger');
$app->requireRole('admin');

header('Location: index.php', true, 302);
exit;
