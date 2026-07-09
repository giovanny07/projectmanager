<?php

/**
 * ---------------------------------------------------------------------
 * Project Manager — Baseline
 * Línea base del cronograma: fechas planificadas originales por tarea,
 * capturadas explícitamente por el usuario, comparadas contra el plan
 * actual (que se mueve con la cascada de TaskDependency).
 * ---------------------------------------------------------------------
 *
 * @author    IMAGUNET S.A.S.
 * @license   GPL-3.0-or-later
 */

namespace GlpiPlugin\Projectmanager;

use CommonDBTM;
use CommonGLPI;
use Glpi\Application\View\TemplateRenderer;
use Project;
use Session;

class Baseline extends CommonDBTM
{
    /** Derecho propio del plugin para este objeto */
    public static $rightname = 'plugin_projectmanager_baseline';

    // ── Metadatos ────────────────────────────────────────────────────

    public static function getTypeName($nb = 0): string
    {
        return _n('Baseline', 'Baselines', $nb, 'projectmanager');
    }

    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_projectmanager_baselines';
    }

    // ── Pestaña en Project ────────────────────────────────────────────

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if (!($item instanceof Project)) {
            return '';
        }

        if (!Session::haveRight(self::$rightname, READ)) {
            return '';
        }

        return self::createTabEntry(__('Baseline', 'projectmanager'));
    }

    public static function displayTabContentForItem(
        CommonGLPI $item,
        $tabnum = 1,
        $withtemplate = 0
    ): bool {
        if (!($item instanceof Project)) {
            return false;
        }

        $projectId = $item->getID();

        TemplateRenderer::getInstance()->display(
            '@projectmanager/baseline.html.twig',
            [
                'project_id'   => $projectId,
                'rows'         => self::getComparisonForProject($projectId),
                'has_baseline' => self::hasBaseline($projectId),
                'can_write'    => Session::haveRight(self::$rightname, UPDATE),
            ]
        );

        return true;
    }

    // ── Consultas ──────────────────────────────────────────────────────

    public static function hasBaseline(int $projectId): bool
    {
        global $DB;

        $iterator = $DB->request([
            'SELECT'     => ['b.id'],
            'FROM'       => self::getTable() . ' AS b',
            'INNER JOIN' => [
                'glpi_projecttasks AS task' => [
                    'ON' => ['task' => 'id', 'b' => 'projecttasks_id'],
                ],
            ],
            'WHERE' => ['task.projects_id' => $projectId],
            'LIMIT' => 1,
        ]);

        return count($iterator) > 0;
    }

    /**
     * Compara, tarea por tarea, la línea base contra el plan actual.
     *
     * @return array<int, array{
     *     task_id: int, name: string,
     *     baseline_start: ?string, baseline_end: ?string,
     *     plan_start: ?string, plan_end: ?string,
     *     start_variance: ?int, end_variance: ?int
     * }>
     */
    public static function getComparisonForProject(int $projectId): array
    {
        global $DB;

        $rows = [];
        foreach ($DB->request([
            'SELECT'    => [
                'task.id', 'task.name', 'task.plan_start_date', 'task.plan_end_date',
                'b.baseline_start_date', 'b.baseline_end_date',
            ],
            'FROM'      => 'glpi_projecttasks AS task',
            'LEFT JOIN' => [
                self::getTable() . ' AS b' => [
                    'ON' => ['b' => 'projecttasks_id', 'task' => 'id'],
                ],
            ],
            'WHERE'  => ['task.projects_id' => $projectId, 'task.is_deleted' => 0],
            'ORDER'  => 'task.name ASC',
        ]) as $row) {
            $startVariance = null;
            $endVariance   = null;

            if ($row['baseline_start_date'] && $row['plan_start_date']) {
                $startVariance = (int)round(
                    (strtotime($row['plan_start_date']) - strtotime($row['baseline_start_date'])) / 86400
                );
            }
            if ($row['baseline_end_date'] && $row['plan_end_date']) {
                $endVariance = (int)round(
                    (strtotime($row['plan_end_date']) - strtotime($row['baseline_end_date'])) / 86400
                );
            }

            $rows[] = [
                'task_id'        => (int)$row['id'],
                'name'           => $row['name'],
                'baseline_start' => $row['baseline_start_date'],
                'baseline_end'   => $row['baseline_end_date'],
                'plan_start'     => $row['plan_start_date'],
                'plan_end'       => $row['plan_end_date'],
                'start_variance' => $startVariance,
                'end_variance'   => $endVariance,
            ];
        }

        return $rows;
    }

    // ── Captura de línea base ──────────────────────────────────────────

    /**
     * Captura (o sobreescribe) la línea base de todas las tareas del
     * proyecto con sus fechas planificadas actuales. Las tareas sin
     * fecha planificada se omiten (no hay nada que congelar todavía).
     *
     * @return array{set: int, skipped: int}
     */
    public static function setBaselineForProject(int $projectId): array
    {
        global $DB;

        $set     = 0;
        $skipped = 0;
        $now     = date('Y-m-d H:i:s');
        $userId  = Session::getLoginUserID();

        foreach ($DB->request([
            'FROM'  => 'glpi_projecttasks',
            'WHERE' => ['projects_id' => $projectId, 'is_deleted' => 0],
        ]) as $task) {
            if (!$task['plan_start_date'] || !$task['plan_end_date']) {
                $skipped++;
                continue;
            }

            $data = [
                'baseline_start_date' => $task['plan_start_date'],
                'baseline_end_date'   => $task['plan_end_date'],
                'date_set'            => $now,
                'users_id'            => $userId,
            ];

            $existing = $DB->request([
                'FROM'  => self::getTable(),
                'WHERE' => ['projecttasks_id' => $task['id']],
                'LIMIT' => 1,
            ])->current();

            if ($existing) {
                $DB->update(self::getTable(), $data, ['id' => $existing['id']]);
            } else {
                $data['projecttasks_id'] = $task['id'];
                $DB->insert(self::getTable(), $data);
            }

            $set++;
        }

        return ['set' => $set, 'skipped' => $skipped];
    }
}
