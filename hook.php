<?php

/**
 * ---------------------------------------------------------------------
 * Project Manager — hook.php
 * Install, uninstall, and GLPI item callbacks.
 * ---------------------------------------------------------------------
 *
 * @author    IMAGUNET S.A.S.
 * @license   GPL-3.0-or-later
 */

use GlpiPlugin\Projectmanager\TaskDependency;
use GlpiPlugin\Projectmanager\Baseline;
use GlpiPlugin\Projectmanager\Config;

/**
 * Installs or updates the plugin's tables.
 *
 * GLPI naming rules (mandatory for the Marketplace):
 *   - Own tables:  glpi_plugin_<name>_<entity>
 *   - Dropdowns:   glpi_plugin_<name>_<entity>s  (plural)
 *
 * Never modify GLPI core tables.
 */
function plugin_projectmanager_install(): bool
{
    global $DB;

    $migration = new Migration(PLUGIN_PROJECTMANAGER_VERSION);

    // ── Table: dependencies between tasks ─────────────────────────────
    if (!$DB->tableExists(TaskDependency::getTable())) {
        $DB->doQuery("
            CREATE TABLE `" . TaskDependency::getTable() . "` (
                `id`                       INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `projecttasks_id_source`   INT UNSIGNED NOT NULL DEFAULT 0
                    COMMENT 'Predecessor task — FK to glpi_projecttasks',
                `projecttasks_id_target`   INT UNSIGNED NOT NULL DEFAULT 0
                    COMMENT 'Successor task   — FK to glpi_projecttasks',
                `type`                     VARCHAR(2)   NOT NULL DEFAULT 'FS'
                    COMMENT 'FS | SS | FF | SF',
                `lag_days`                 SMALLINT     NOT NULL DEFAULT 0
                    COMMENT 'Lag (+) or lead (-) in days',
                `is_deleted`               TINYINT(1)   NOT NULL DEFAULT 0,
                `date_creation`            TIMESTAMP    NULL DEFAULT NULL,
                `date_mod`                 TIMESTAMP    NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_source`  (`projecttasks_id_source`),
                KEY `idx_target`  (`projecttasks_id_target`),
                KEY `idx_deleted` (`is_deleted`),
                CONSTRAINT `fk_pm_dep_source`
                    FOREIGN KEY (`projecttasks_id_source`)
                    REFERENCES `glpi_projecttasks` (`id`)
                    ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT `fk_pm_dep_target`
                    FOREIGN KEY (`projecttasks_id_target`)
                    REFERENCES `glpi_projecttasks` (`id`)
                    ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
              COMMENT='ProjectManager: dependencies between project tasks'
        ");
    }

    // ── Table: schedule baseline per task ─────────────────────────────
    if (!$DB->tableExists(Baseline::getTable())) {
        $DB->doQuery("
            CREATE TABLE `" . Baseline::getTable() . "` (
                `id`                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `projecttasks_id`      INT UNSIGNED NOT NULL
                    COMMENT 'Task — FK to glpi_projecttasks',
                `baseline_start_date`  TIMESTAMP    NULL DEFAULT NULL
                    COMMENT 'plan_start_date frozen when the baseline was set',
                `baseline_end_date`    TIMESTAMP    NULL DEFAULT NULL
                    COMMENT 'plan_end_date frozen when the baseline was set',
                `date_set`             TIMESTAMP    NULL DEFAULT NULL,
                `users_id`             INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_task` (`projecttasks_id`),
                CONSTRAINT `fk_pm_baseline_task`
                    FOREIGN KEY (`projecttasks_id`)
                    REFERENCES `glpi_projecttasks` (`id`)
                    ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
              COMMENT='ProjectManager: baseline of planned dates per task'
        ");
    }

    // ── Table: plugin configuration ───────────────────────────────────
    // Behaviors pattern: own table with id=1, not glpi_configs
    Config::install($migration);

    // Register the plugin's own rights on every profile (0 = no access
    // by default; the admin enables them under Administration > Profiles).
    // Without this, the "Dependencies"/"Baseline" tabs never appear for
    // anyone, not even Super-Admin.
    // ProfileRight::addProfileRights() is NOT idempotent (raw INSERT,
    // no existence check) — install() can run again (e.g.
    // `plugin:install --force`), so only request rights that don't have
    // any row yet, or it crashes with a duplicate-key error.
    $newRights = array_filter(
        [TaskDependency::$rightname, Baseline::$rightname],
        static fn ($right) => !countElementsInTable('glpi_profilerights', ['name' => $right])
    );
    if (!empty($newRights)) {
        ProfileRight::addProfileRights(array_values($newRights));
    }

    $migration->executeMigration();

    return true;
}

/**
 * Uninstalls the plugin: removes tables and configuration.
 *
 * GLPI only calls this when the admin clicks "Uninstall".
 * Never called on deactivation — data persists across activations.
 */
function plugin_projectmanager_uninstall(): bool
{
    global $DB;

    // Drop tables in reverse FK order
    $tables = [
        TaskDependency::getTable(),
        Baseline::getTable(),
    ];

    foreach ($tables as $table) {
        if ($DB->tableExists($table)) {
            // Temporarily disable FK checks for a safe DROP
            $DB->doQuery("SET FOREIGN_KEY_CHECKS=0");
            $DB->doQuery("DROP TABLE IF EXISTS `{$table}`");
            $DB->doQuery("SET FOREIGN_KEY_CHECKS=1");
        }
    }

    // Remove the plugin's config from glpi_configs
    Config::uninstall();

    // Remove the plugin's rights from glpi_profilerights
    $DB->delete('glpi_profilerights', [
        'name' => ['LIKE', 'plugin_projectmanager_%'],
    ]);

    return true;
}
