<?php
include('../../../inc/includes.php');

/**
 * Project Manager — front/taskdependency.php
 * Controlador CRUD para dependencias entre tareas.
 *
 * @license GPL-3.0-or-later
 */

use GlpiPlugin\Projectmanager\TaskDependency;
use GlpiPlugin\Projectmanager\Config;


// Verificaciones de seguridad
Session::checkLoginUser();
Plugin::checkPluginState('projectmanager');
Session::checkCSRF($_POST);

if (!Config::isModuleEnabled('dependencies')) {
    Html::displayErrorAndDie(__('Dependencies module is not enabled.', 'projectmanager'));
}

$dep = new TaskDependency();

// ── Agregar nueva dependencia ────────────────────────────────────────
if (isset($_POST['add'])) {
    $dep->check(-1, CREATE, $_POST);

    $dep->add([
        'projecttasks_id_source' => (int)$_POST['projecttasks_id_source'],
        'projecttasks_id_target' => (int)$_POST['projecttasks_id_target'],
        'type'                   => $_POST['type'] ?? 'FS',
        'lag_days'               => (int)($_POST['lag_days'] ?? 0),
    ]);

    Html::back();
    exit;
}

// ── Eliminar dependencia ─────────────────────────────────────────────
if (isset($_POST['purge'])) {
    $dep->check((int)$_POST['id'], DELETE);
    $dep->delete(['id' => (int)$_POST['id']], /* force */ true);

    Html::back();
    exit;
}

// ── Recalcular cascada completa de un proyecto ───────────────────────
if (isset($_POST['reschedule_project'])) {
    $projectId = (int)($_POST['projects_id'] ?? 0);

    if ($projectId > 0) {
        $project = new Project();
        $project->check($projectId, UPDATE);

        $result = TaskDependency::rescheduleProject($projectId);

        if (!empty($result['errors'])) {
            foreach ($result['errors'] as $err) {
                Session::addMessageAfterRedirect($err, false, ERROR);
            }
        } else {
            Session::addMessageAfterRedirect(
                sprintf(
                    _n(
                        'Cascade complete: %d task rescheduled.',
                        'Cascade complete: %d tasks rescheduled.',
                        $result['rescheduled'],
                        'projectmanager'
                    ),
                    $result['rescheduled']
                ),
                false,
                INFO
            );
        }
    }

    Html::back();
    exit;
}

// Fallback: redirigir a proyectos
Html::redirect(GLPI_ROOT . '/front/project.php');
