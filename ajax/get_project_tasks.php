<?php

/**
 * Project Manager — ajax/get_project_tasks.php
 * splitcat/gantt (TECLIB) pattern: Session::checkLoginUser() + Html::header_nocache()
 *
 * @license GPL-3.0-or-later
 */

Session::checkLoginUser();

header('Content-Type: application/json; charset=utf-8');
Html::header_nocache();

$projectId     = (int)($_REQUEST['projects_id']     ?? 0);
$excludeTaskId = (int)($_REQUEST['exclude_task_id'] ?? 0);

if ($projectId <= 0) {
    echo json_encode(['tasks' => []]);
    exit;
}

$project = new Project();
if (!$project->can($projectId, READ)) {
    http_response_code(403);
    echo json_encode(['tasks' => []]);
    exit;
}

global $DB;
$tasks = [];

foreach ($DB->request([
    'SELECT' => ['id', 'name', 'percent_done', 'plan_start_date', 'plan_end_date'],
    'FROM'   => 'glpi_projecttasks',
    'WHERE'  => [
        'projects_id' => $projectId,
        'is_deleted'  => 0,
        ['id' => ['!=', $excludeTaskId]],
    ],
    'ORDER'  => 'name ASC',
]) as $row) {
    $tasks[] = [
        'id'           => (int)$row['id'],
        'name'         => $row['name'],
        'percent_done' => (int)$row['percent_done'],
        'end_date'     => $row['plan_end_date'],
    ];
}

echo json_encode(['tasks' => $tasks]);
