<?php

/**
 * Project Manager — front/baseline.php
 * Controller: sets a project's schedule baseline.
 *
 * No include() needed — LegacyFileLoadController bootstraps the environment
 * before invoking any file under front/ in GLPI 11.
 *
 * No explicit Session::checkCSRF() here: GLPI 11's kernel-level
 * CheckCsrfListener already validates (and consumes) the token for every
 * non-GET request before this controller runs.
 *
 * @license GPL-3.0-or-later
 */

use GlpiPlugin\Projectmanager\Baseline;
use GlpiPlugin\Projectmanager\Config;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

Session::checkLoginUser();
(new Plugin())->checkPluginState('projectmanager');

if (!Config::isModuleEnabled('dependencies')) {
    Html::displayErrorAndDie(__('Dependencies module is not enabled.', 'projectmanager'));
}

if (isset($_POST['set_baseline'])) {
    $projectId = (int)($_POST['projects_id'] ?? 0);

    $project = new Project();
    $project->check($projectId, UPDATE);

    if (!Session::haveRight(Baseline::$rightname, UPDATE)) {
        throw new AccessDeniedHttpException();
    }

    $result = Baseline::setBaselineForProject($projectId);

    Session::addMessageAfterRedirect(
        sprintf(
            _n('Baseline set for %d task.', 'Baseline set for %d tasks.', $result['set'], 'projectmanager'),
            $result['set']
        ),
        false,
        INFO
    );

    if ($result['skipped'] > 0) {
        Session::addMessageAfterRedirect(
            sprintf(
                _n(
                    '%d task skipped (no planned dates yet).',
                    '%d tasks skipped (no planned dates yet).',
                    $result['skipped'],
                    'projectmanager'
                ),
                $result['skipped']
            ),
            false,
            WARNING
        );
    }

    Html::back();
    exit;
}

Html::redirect(GLPI_ROOT . '/front/project.php');
