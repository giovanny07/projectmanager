<?php

/**
 * ---------------------------------------------------------------------
 * Project Manager — Baseline
 * Schedule baseline: original planned dates per task, captured
 * explicitly by the user, compared against the current plan (which
 * moves with TaskDependency's cascade).
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
    /** Plugin's own right for this object */
    public static $rightname = 'plugin_projectmanager_baseline';

    // ── Metadata ─────────────────────────────────────────────────────

    public static function getTypeName($nb = 0): string
    {
        return _n('Baseline', 'Baselines', $nb, 'projectmanager');
    }

    public static function getIcon(): string
    {
        return 'ti ti-flag-3';
    }

    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_projectmanager_baselines';
    }

    // ── Tab on Project ───────────────────────────────────────────────

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

    // ── Queries ──────────────────────────────────────────────────────────

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
     * Compares, task by task, the baseline against the current plan.
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
                'task.id', 'task.name', 'task.is_milestone',
                'task.plan_start_date', 'task.plan_end_date',
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
                'is_milestone'   => (bool)$row['is_milestone'],
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

    // ── Baseline capture ─────────────────────────────────────────────────

    /**
     * Captures (or overwrites) the baseline of every task in the project
     * with its current planned dates. Tasks with no planned date yet are
     * skipped (there's nothing to freeze).
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
