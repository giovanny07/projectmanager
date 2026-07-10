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

define('PLUGIN_PROJECTMANAGER_VERSION',  '1.1.1');
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

    // CSRF compliance is mandatory in GLPI 11
    $PLUGIN_HOOKS['csrf_compliant']['projectmanager'] = true;

    $plugin = new Plugin();
    if (!$plugin->isActivated('projectmanager')) {
        return;
    }

    // Register Config as a tab on Setup > General (exact satisfactionpopup pattern)
    Plugin::registerClass(
        \GlpiPlugin\Projectmanager\Config::class,
        ['addtabon' => \Config::class]
    );

    // "Configure" link under Setup > Plugins
    $PLUGIN_HOOKS['config_page']['projectmanager'] = 'front/config.php';

    // Assets — engage pattern: lowercase, paths relative to public/
    $PLUGIN_HOOKS['add_css']['projectmanager']        = ['css/projectmanager.css'];
    $PLUGIN_HOOKS['add_javascript']['projectmanager'] = ['js/projectmanager.js'];

    // Read config to decide which modules to activate
    $config = \GlpiPlugin\Projectmanager\Config::getInstance()->fields;

    // Module: task dependencies
    if ((bool)(int)($config['module_dependencies'] ?? 0)) {
        // GLPI 11: tab on ProjectTask via registerClass (same as Config on \Config::class)
        Plugin::registerClass(
            \GlpiPlugin\Projectmanager\TaskDependency::class,
            ['addtabon' => \ProjectTask::class]
        );

        $PLUGIN_HOOKS['item_update']['projectmanager']['ProjectTask'] =
            ['GlpiPlugin\\Projectmanager\\TaskDependency', 'onProjectTaskUpdate'];

        // Real blocking (opt-in via Config::block_unmet_dependencies)
        $PLUGIN_HOOKS['pre_item_update']['projectmanager']['ProjectTask'] =
            ['GlpiPlugin\\Projectmanager\\TaskDependency', 'onProjectTaskPreUpdate'];

        // Baseline: tab on Project
        Plugin::registerClass(
            \GlpiPlugin\Projectmanager\Baseline::class,
            ['addtabon' => \Project::class]
        );

        // Critical path (CPM): tab on Project
        Plugin::registerClass(
            \GlpiPlugin\Projectmanager\CriticalPath::class,
            ['addtabon' => \Project::class]
        );
    }
}

function plugin_projectmanager_geturl(): string
{
    global $CFG_GLPI;
    return sprintf('%s/plugins/projectmanager/', $CFG_GLPI['url_base']);
}
