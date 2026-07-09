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

    // ── Tabla: configuración del plugin ──────────────────────────────
    // Patrón behaviors: tabla propia con id=1, no glpi_configs
    Config::install($migration);

    // Registrar el derecho propio en todos los perfiles (0 = sin acceso
    // por defecto; el admin lo habilita en Administration > Profiles).
    // Sin esto la pestaña "Dependencies" no aparece para nadie, ni Super-Admin.
    ProfileRight::addProfileRights([TaskDependency::$rightname]);

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
