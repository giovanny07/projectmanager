<?php

/**
 * Project Manager — front/taskdependency.php
 * CRUD controller for task dependencies.
 *
 * No include() needed — LegacyFileLoadController bootstraps the environment
 * before invoking any file under front/ in GLPI 11.
 *
 * No explicit Session::checkCSRF() here: GLPI 11's kernel-level
 * CheckCsrfListener already validates (and consumes) the token for every
 * non-GET request before this controller runs. Calling checkCSRF() again
 * here fails legitimate requests, because the token was already spent.
 *
 * @license GPL-3.0-or-later
 */

use GlpiPlugin\Projectmanager\TaskDependency;
use GlpiPlugin\Projectmanager\Config;


// Security checks
Session::checkLoginUser();
(new Plugin())->checkPluginState('projectmanager');

if (!Config::isModuleEnabled('dependencies')) {
    Html::displayErrorAndDie(__('Dependencies module is not enabled.', 'projectmanager'));
}

$dep = new TaskDependency();

// ── Add new dependency ────────────────────────────────────────────────
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

// ── Delete dependency ─────────────────────────────────────────────────
if (isset($_POST['purge'])) {
    $dep->check((int)$_POST['id'], DELETE);
    $dep->delete(['id' => (int)$_POST['id']], /* force */ true);

    Html::back();
    exit;
}

// ── Recalculate a project's full cascade ──────────────────────────────
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

// Fallback: redirect to projects
Html::redirect(GLPI_ROOT . '/front/project.php');
