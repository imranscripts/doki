<?php
/**
 * studio-ai-job.php - Background runner for Studio AI jobs
 */

declare(strict_types=1);

ignore_user_abort(true);
set_time_limit(0);

require_once __DIR__ . '/includes/WorkspaceAIJobManager.php';

$jobId = trim((string)($argv[1] ?? ''));
if ($jobId === '') {
    fwrite(STDERR, "Studio AI job ID is required.\n");
    exit(1);
}

$manager = new WorkspaceAIJobManager();
$result = $manager->runJob($jobId);

exit(!empty($result['success']) ? 0 : 1);
