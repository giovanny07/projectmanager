<?php

/**
 * Project Manager — ajax/reschedule.php
 * Endpoint JSON: replanificación en cascada desde el frontend.
 *
 * Patrón TECLIB: $AJAX_INCLUDE = 1 + Session::checkLoginUser()
 *
 * @license GPL-3.0-or-later
 */

$AJAX_INCLUDE = 1;

Session::checkLoginUser();

use GlpiPlugin\Projectmanager\TaskDependency;
use GlpiPlugin\Projectmanager\Config;

header('Content-Type: application/json; charset=utf-8');

if (!Config::isModuleEnabled('dependencies')) {
    echo json_encode(['success' => false, 'error' => 'Module disabled']);
    exit;
}

$projectId     = (int)($_POST['projects_id']    ?? 0);
$changedTaskId = (int)($_POST['changed_task_id'] ?? 0);

if ($projectId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid project']);
    exit;
}

$project = new Project();
if (!$project->can($projectId, UPDATE)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

$result = TaskDependency::rescheduleProject($projectId, $changedTaskId);

if (!empty($result['errors'])) {
    echo json_encode([
        'success' => false,
        'message' => implode(' ', $result['errors']),
    ]);
} else {
    echo json_encode([
        'success'     => true,
        'rescheduled' => $result['rescheduled'],
        'message'     => sprintf(
            _n('Cascade complete: %d task rescheduled.', 'Cascade complete: %d tasks rescheduled.', $result['rescheduled'], 'projectmanager'),
            $result['rescheduled']
        ),
    ]);
}
