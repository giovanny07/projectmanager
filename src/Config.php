<?php

/**
 * Project Manager — Config
 * Patrón: behaviors plugin (CommonDBTM, tabla propia, front propio).
 *
 * @author  IMAGUNET S.A.S.
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Projectmanager;

use CommonDBTM;
use CommonGLPI;
use DBConnection;
use Glpi\Application\View\TemplateRenderer;
use Migration;
use Plugin;
use Session;

class Config extends CommonDBTM
{
    private static ?self $_instance = null;

    public static $rightname = 'config';

    // ── Singleton (id=1 en tabla) ─────────────────────────────────────────

    public static function getInstance(): self
    {
        if (!isset(self::$_instance)) {
            self::$_instance = new self();
            if (!self::$_instance->getFromDB(1)) {
                self::$_instance->getEmpty();
            }
        }
        return self::$_instance;
    }

    // ── Metadatos ────────────────────────────────────────────────────────

    public static function getTypeName($nb = 0): string
    {
        return 'Project Manager';
    }

    public static function canCreate(): bool
    {
        return Session::haveRight('config', UPDATE);
    }

    public static function canView(): bool
    {
        return Session::haveRight('config', READ);
    }

    // ── Instalación (tabla propia) ────────────────────────────────────────

    public static function install(Migration $migration): void
    {
        global $DB;

        $table     = self::getTable();
        $charset   = DBConnection::getDefaultCharset();
        $collation = DBConnection::getDefaultCollation();
        $sign      = DBConnection::getDefaultPrimaryKeySignOption();

        if (!$DB->tableExists($table)) {
            $DB->doQuery("CREATE TABLE `{$table}` (
                `id`                  int {$sign} NOT NULL,
                `module_dependencies` tinyint NOT NULL DEFAULT 0,
                `module_risks`        tinyint NOT NULL DEFAULT 0,
                `module_dashboard`    tinyint NOT NULL DEFAULT 0,
                `module_evm`          tinyint NOT NULL DEFAULT 0,
                `cascade_auto`        tinyint NOT NULL DEFAULT 1,
                `cascade_log`         tinyint NOT NULL DEFAULT 1,
                `date_mod`            timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");

            $DB->doQuery("INSERT INTO `{$table}` (id, date_mod) VALUES (1, NOW())");
        }
    }

    public static function uninstall(): void
    {
        global $DB;
        $DB->dropTable(self::getTable(), true);
    }

    // ── Helpers de lectura ────────────────────────────────────────────────

    public static function getValue(string $key)
    {
        return self::getInstance()->fields[$key] ?? null;
    }

    /**
     * Alias de getValue() con soporte de valor por defecto.
     */
    public static function get(string $key, $default = null)
    {
        $val = self::getValue($key);
        return $val !== null ? $val : $default;
    }

    public static function isModuleEnabled(string $module): bool
    {
        return (bool)(int)self::getValue("module_{$module}");
    }

    // ── Tab en Setup > General ────────────────────────────────────────────

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if ($item->getType() === 'Config') {
            return self::createTabEntry(self::getTypeName());
        }
        return '';
    }

    public static function displayTabContentForItem(
        CommonGLPI $item,
        $tabnum = 1,
        $withtemplate = 0
    ): bool {
        if ($item->getType() === 'Config') {
            self::showConfigForm($item);
        }
        return true;
    }

    // ── Formulario ────────────────────────────────────────────────────────

    public static function showConfigForm($item = null): bool
    {
        if (!Session::haveRight('config', UPDATE)) {
            return false;
        }

        $config = self::getInstance();

        // getFormURL() retorna la URL correcta tanto para /plugins/ como /marketplace/
        $action = self::getFormURL();

        TemplateRenderer::getInstance()->display(
            '@projectmanager/config.form.html.twig',
            [
                'id'      => 1,
                'item'    => $config,
                'config'  => $config->fields,
                'action'  => $action,
                'version' => PLUGIN_PROJECTMANAGER_VERSION,
            ]
        );

        return true;
    }
}
