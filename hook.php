<?php

/**
 * ---------------------------------------------------------------------
 * Project Manager — hook.php
 * Instalación, desinstalación y callbacks de ítems GLPI.
 * ---------------------------------------------------------------------
 *
 * @author    IMAGUNET S.A.S.
 * @license   GPL-3.0-or-later
 */

use GlpiPlugin\Projectmanager\TaskDependency;
use GlpiPlugin\Projectmanager\Baseline;
use GlpiPlugin\Projectmanager\Config;

/**
 * Instala o actualiza las tablas del plugin.
 *
 * Reglas de naming GLPI (obligatorias para Marketplace):
 *   - Tablas propias:    glpi_plugin_<nombre>_<entidad>
 *   - Dropdowns:         glpi_plugin_<nombre>_<entidad>s  (plural)
 *
 * Nunca modificamos tablas del core de GLPI.
 */
function plugin_projectmanager_install(): bool
{
    global $DB;

    $migration = new Migration(PLUGIN_PROJECTMANAGER_VERSION);

    // ── Tabla: dependencias entre tareas ─────────────────────────────
    if (!$DB->tableExists(TaskDependency::getTable())) {
        $DB->doQuery("
            CREATE TABLE `" . TaskDependency::getTable() . "` (
                `id`                       INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `projecttasks_id_source`   INT UNSIGNED NOT NULL DEFAULT 0
                    COMMENT 'Tarea predecesora — FK a glpi_projecttasks',
                `projecttasks_id_target`   INT UNSIGNED NOT NULL DEFAULT 0
                    COMMENT 'Tarea sucesora   — FK a glpi_projecttasks',
                `type`                     VARCHAR(2)   NOT NULL DEFAULT 'FS'
                    COMMENT 'FS | SS | FF | SF',
                `lag_days`                 SMALLINT     NOT NULL DEFAULT 0
                    COMMENT 'Lag (+) o lead (-) en días',
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
              COMMENT='ProjectManager: dependencias entre tareas de proyecto'
        ");
    }

    // ── Tabla: línea base de cronograma por tarea ────────────────────
    if (!$DB->tableExists(Baseline::getTable())) {
        $DB->doQuery("
            CREATE TABLE `" . Baseline::getTable() . "` (
                `id`                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `projecttasks_id`      INT UNSIGNED NOT NULL
                    COMMENT 'Tarea — FK a glpi_projecttasks',
                `baseline_start_date`  TIMESTAMP    NULL DEFAULT NULL
                    COMMENT 'plan_start_date congelada al fijar la línea base',
                `baseline_end_date`    TIMESTAMP    NULL DEFAULT NULL
                    COMMENT 'plan_end_date congelada al fijar la línea base',
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
              COMMENT='ProjectManager: línea base de fechas planificadas por tarea'
        ");
    }

    // ── Tabla: configuración del plugin ──────────────────────────────
    // Patrón behaviors: tabla propia con id=1, no glpi_configs
    Config::install($migration);

    // Registrar los derechos propios en todos los perfiles (0 = sin acceso
    // por defecto; el admin los habilita en Administration > Profiles).
    // Sin esto las pestañas "Dependencies"/"Baseline" no aparecen para nadie,
    // ni Super-Admin.
    // ProfileRight::addProfileRights() NO es idempotente (INSERT sin
    // verificar existencia) — install() puede volver a ejecutarse (p.ej.
    // `plugin:install --force`), así que solo pedimos los derechos que
    // todavía no tengan ninguna fila, o revienta con clave duplicada.
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
 * Desinstala el plugin: elimina tablas y configuración.
 *
 * GLPI llama esta función solo cuando el admin hace "Desinstalar".
 * Jamás se llama al desactivar — los datos persisten entre activaciones.
 */
function plugin_projectmanager_uninstall(): bool
{
    global $DB;

    // Eliminar tablas en orden inverso a las FK
    $tables = [
        TaskDependency::getTable(),
        Baseline::getTable(),
    ];

    foreach ($tables as $table) {
        if ($DB->tableExists($table)) {
            // Desactivar FK check temporalmente para DROP seguro
            $DB->doQuery("SET FOREIGN_KEY_CHECKS=0");
            $DB->doQuery("DROP TABLE IF EXISTS `{$table}`");
            $DB->doQuery("SET FOREIGN_KEY_CHECKS=1");
        }
    }

    // Eliminar config del plugin de glpi_configs
    Config::uninstall();

    // Eliminar derechos del plugin de glpi_profilerights
    $DB->delete('glpi_profilerights', [
        'name' => ['LIKE', 'plugin_projectmanager_%'],
    ]);

    return true;
}
