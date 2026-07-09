<?php

/**
 * ---------------------------------------------------------------------
 * Project Manager — Advanced project management for GLPI 11
 * ---------------------------------------------------------------------
 *
 * @author    IMAGUNET S.A.S. <dev@imagunet.com>
 * @copyright 2026 IMAGUNET S.A.S.
 * @license   GPL-3.0-or-later
 * @link      https://github.com/giovanny07/projectmanager
 */

define('PLUGIN_PROJECTMANAGER_VERSION',  '1.0.0');
define('PLUGIN_PROJECTMANAGER_MIN_GLPI', '11.0.0');
define('PLUGIN_PROJECTMANAGER_MAX_GLPI', '12.0.0');

function plugin_version_projectmanager(): array
{
    return [
        'name'         => 'Project Manager',
        'version'      => PLUGIN_PROJECTMANAGER_VERSION,
        'author'       => 'IMAGUNET S.A.S.',
        'license'      => 'GPL-3.0-or-later',
        'homepage'     => 'https://github.com/giovanny07/projectmanager',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_PROJECTMANAGER_MIN_GLPI,
                'max' => PLUGIN_PROJECTMANAGER_MAX_GLPI,
            ],
            'php'  => ['min' => '8.1'],
        ],
    ];
}

function plugin_projectmanager_check_prerequisites(): bool
{
    if (version_compare(GLPI_VERSION, PLUGIN_PROJECTMANAGER_MIN_GLPI, 'lt')) {
        echo sprintf('Project Manager requires GLPI %s or higher. Current: %s',
            PLUGIN_PROJECTMANAGER_MIN_GLPI, GLPI_VERSION);
        return false;
    }
    return true;
}

function plugin_projectmanager_check_config(): bool
{
    return true;
}

function plugin_init_projectmanager(): void
{
    global $PLUGIN_HOOKS;

    // CSRF obligatorio en GLPI 11
    $PLUGIN_HOOKS['csrf_compliant']['projectmanager'] = true;

    $plugin = new Plugin();
    if (!$plugin->isActivated('projectmanager')) {
        return;
    }

    // Registrar Config como tab en Setup > General (patrón satisfactionpopup exacto)
    Plugin::registerClass(
        \GlpiPlugin\Projectmanager\Config::class,
        ['addtabon' => \Config::class]
    );

    // Enlace "Configure" en Setup > Plugins
    $PLUGIN_HOOKS['config_page']['projectmanager'] = 'front/config.php';

    // Assets — patrón engage: lowercase, rutas relativas a public/
    $PLUGIN_HOOKS['add_css']['projectmanager']        = ['css/projectmanager.css'];
    $PLUGIN_HOOKS['add_javascript']['projectmanager'] = ['js/projectmanager.js'];

    // Leer config para activar módulos
    $config = \GlpiPlugin\Projectmanager\Config::getInstance()->fields;

    // Módulo: Dependencias de tareas
    if ((bool)(int)($config['module_dependencies'] ?? 0)) {
        // GLPI 11: pestaña en ProjectTask vía registerClass (igual que Config en \Config::class)
        Plugin::registerClass(
            \GlpiPlugin\Projectmanager\TaskDependency::class,
            ['addtabon' => \ProjectTask::class]
        );

        $PLUGIN_HOOKS['item_update']['projectmanager']['ProjectTask'] =
            ['GlpiPlugin\\Projectmanager\\TaskDependency', 'onProjectTaskUpdate'];

        // Línea base: pestaña en Project
        Plugin::registerClass(
            \GlpiPlugin\Projectmanager\Baseline::class,
            ['addtabon' => \Project::class]
        );
    }
}

function plugin_projectmanager_geturl(): string
{
    global $CFG_GLPI;
    return sprintf('%s/plugins/projectmanager/', $CFG_GLPI['url_base']);
}
