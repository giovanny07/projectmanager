<?php

/**
 * Project Manager — front/config.form.php
 * Solo procesa POST del formulario de configuración y redirige.
 *
 * @license GPL-3.0-or-later
 */

use GlpiPlugin\Projectmanager\Config;

$config = new Config();

if (isset($_POST['update'])) {
    $config->check((int)($_POST['id'] ?? 1), UPDATE);

    foreach (['module_dependencies', 'module_risks', 'module_dashboard', 'module_evm', 'cascade_auto', 'cascade_log', 'block_unmet_dependencies'] as $key) {
        $_POST[$key] = (int)($_POST[$key] ?? 0) === 1 ? 1 : 0;
    }

    $config->update($_POST);

    Session::addMessageAfterRedirect(
        __('Configuration saved.', 'projectmanager'),
        false,
        INFO
    );
}

Html::redirect(
    \Config::getFormURL() . '?forcetab=' . urlencode(Config::class . '$1')
);
