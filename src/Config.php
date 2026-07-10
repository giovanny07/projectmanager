<?php

/**
 * Project Manager — Config
 * Pattern: behaviors plugin (CommonDBTM, own table, own front controller).
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

    // ── Singleton (id=1 in table) ───────────────────────────────────────

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

    /**
     * Clears the cached singleton so the next getInstance() re-reads the
     * DB. Production code never needs this (one request = one process =
     * config never changes mid-request); it exists for tests, where a
     * single long-lived process boots GLPI once and then needs to
     * exercise several different config values.
     */
    public static function resetInstance(): void
    {
        self::$_instance = null;
    }

    // ── Metadata ─────────────────────────────────────────────────────────

    public static function getTypeName($nb = 0): string
    {
        return 'Project Manager';
    }

    public static function getIcon(): string
    {
        return 'ti ti-layout-kanban';
    }

    public static function canCreate(): bool
    {
        return Session::haveRight('config', UPDATE);
    }

    public static function canView(): bool
    {
        return Session::haveRight('config', READ);
    }

    // ── Install (own table) ─────────────────────────────────────────────

    public static function install(Migration $migration): void
    {
        global $DB;

        $table     = self::getTable();
        $charset   = DBConnection::getDefaultCharset();
        $collation = DBConnection::getDefaultCollation();
        $sign      = DBConnection::getDefaultPrimaryKeySignOption();

        if (!$DB->tableExists($table)) {
            $DB->doQuery("CREATE TABLE `{$table}` (
                `id`                         int {$sign} NOT NULL,
                `module_dependencies`        tinyint NOT NULL DEFAULT 0,
                `module_risks`               tinyint NOT NULL DEFAULT 0,
                `module_dashboard`           tinyint NOT NULL DEFAULT 0,
                `module_evm`                 tinyint NOT NULL DEFAULT 0,
                `cascade_auto`               tinyint NOT NULL DEFAULT 1,
                `cascade_log`                tinyint NOT NULL DEFAULT 1,
                `block_unmet_dependencies`   tinyint NOT NULL DEFAULT 0,
                `date_mod`                   timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");

            $DB->doQuery("INSERT INTO `{$table}` (id, date_mod) VALUES (1, NOW())");
        } elseif (!$DB->fieldExists($table, 'block_unmet_dependencies')) {
            // Migration: existing installs don't have this column yet.
            $migration->addField(
                $table,
                'block_unmet_dependencies',
                'tinyint',
                ['value' => 0, 'after' => 'cascade_log']
            );
            $migration->migrationOneTable($table);
        }
    }

    public static function uninstall(): void
    {
        global $DB;
        $DB->dropTable(self::getTable(), true);
    }

    // ── Read helpers ─────────────────────────────────────────────────────

    public static function getValue(string $key)
    {
        return self::getInstance()->fields[$key] ?? null;
    }

    /**
     * Alias of getValue() with default-value support.
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

    // ── Tab on Setup > General ──────────────────────────────────────────

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

    // ── Form ─────────────────────────────────────────────────────────────

    public static function showConfigForm($item = null): bool
    {
        if (!Session::haveRight('config', UPDATE)) {
            return false;
        }

        $config = self::getInstance();

        // getFormURL() returns the correct URL for both /plugins/ and /marketplace/
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
