<?php

/**
 * Project Manager — front/config.php
 *
 * Entry point linked from Setup > Plugins > Project Manager > Configure.
 * Redirects to the Config tab on the GLPI Setup > General page.
 *
 * No include() needed — LegacyFileLoadController bootstraps the environment
 * before invoking any file under front/ in GLPI 11.
 *
 * @license AGPL-3.0-or-later
 */

use GlpiPlugin\Projectmanager\Config;

Session::checkRight('config', UPDATE);

Html::redirect(
    \Config::getFormURL() . '?forcetab=' . urlencode(Config::class . '$1')
);
