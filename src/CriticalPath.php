<?php

/**
 * ---------------------------------------------------------------------
 * Project Manager — CriticalPath
 * Critical Path Method (CPM): forward/backward pass over the
 * dependency graph already built by TaskDependency, to find each
 * task's float (slack) and flag the critical ones (float = 0).
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

class CriticalPath extends CommonDBTM
{
    /**
     * No table of its own — CPM is a pure computation over
     * TaskDependency's data, nothing to store. Reuses TaskDependency's
     * right: it's the same underlying capability (the dependency graph),
     * not a separate permission to configure.
     */
    public static $rightname = 'plugin_projectmanager_taskdependency';

    public static function getTypeName($nb = 0): string
    {
        return __('Critical path', 'projectmanager');
    }

    public static function getIcon(): string
    {
        return 'ti ti-route';
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

        return self::createTabEntry(__('Critical path', 'projectmanager'));
    }

    public static function displayTabContentForItem(
        CommonGLPI $item,
        $tabnum = 1,
        $withtemplate = 0
    ): bool {
        if (!($item instanceof Project)) {
            return false;
        }

        $computed = self::computeForProject($item->getID());

        TemplateRenderer::getInstance()->display(
            '@projectmanager/criticalpath.html.twig',
            [
                'rows'           => $computed['rows'],
                'cycle_detected' => $computed['cycleDetected'],
            ]
        );

        return true;
    }

    /**
     * Runs the CPM forward and backward pass for a project.
     *
     * Forward pass: earliest start/finish (ES/EF) per task, honoring
     * FS/SS/FF/SF + lag exactly like TaskDependency::rescheduleProject().
     * Backward pass: latest start/finish (LS/LF), working back from the
     * project's overall end (the latest EF among tasks with no
     * successor). Float = LS - ES; a task is critical when float is 0.
     *
     * @return array{
     *     rows: array<int, array{
     *         task_id: int, name: string, is_milestone: bool,
     *         es: string, ef: string, ls: string, lf: string,
     *         float_days: int, is_critical: bool
     *     }>,
     *     cycleDetected: bool
     * }
     */
    public static function computeForProject(int $projectId): array
    {
        $graph = TaskDependency::buildDependencyGraph($projectId);

        if ($graph['topoOrder'] === null) {
            return ['rows' => [], 'cycleDetected' => true];
        }

        $tasksData = $graph['tasksData'];
        $adjacency = $graph['adjacency'];
        $reverse   = $graph['reverse'];
        $topoOrder = $graph['topoOrder'];

        if (empty($tasksData)) {
            return ['rows' => [], 'cycleDetected' => false];
        }

        $duration = [];
        foreach ($tasksData as $id => $task) {
            $duration[$id] = TaskDependency::durationSeconds($task);
        }

        // ── Forward pass: earliest start (ES) / earliest finish (EF) ──
        $es = [];
        $ef = [];
        foreach ($tasksData as $id => $task) {
            $es[$id] = $task['plan_start_date'] ? strtotime($task['plan_start_date']) : time();
        }

        foreach ($topoOrder as $taskId) {
            $preds = $reverse[$taskId] ?? [];
            $minStart = 0;

            foreach ($preds as $pred) {
                $src = $pred['source'];
                if (!isset($es[$src])) {
                    continue;
                }

                $lag = $pred['lag'] * 86400;

                $candidate = match ($pred['type']) {
                    DependencyType::FS => $ef[$src] + $lag,
                    DependencyType::SS => $es[$src] + $lag,
                    DependencyType::FF => $ef[$src] + $lag - $duration[$taskId],
                    DependencyType::SF => $es[$src] + $lag - $duration[$taskId],
                    default             => $ef[$src] + $lag,
                };

                $minStart = max($minStart, $candidate);
            }

            if ($minStart > 0) {
                $es[$taskId] = $minStart;
            }
            $ef[$taskId] = $es[$taskId] + $duration[$taskId];
        }

        // Project completion = latest EF among tasks with no successor.
        $projectEnd = 0;
        foreach ($tasksData as $id => $task) {
            if (empty($adjacency[$id])) {
                $projectEnd = max($projectEnd, $ef[$id]);
            }
        }

        // ── Backward pass: latest finish (LF) / latest start (LS) ──
        $lf = [];
        $ls = [];
        foreach (array_reverse($topoOrder) as $taskId) {
            $succs = $adjacency[$taskId] ?? [];

            if (empty($succs)) {
                $lf[$taskId] = $projectEnd;
            } else {
                $minLf = null;
                foreach ($succs as $succId) {
                    // Find the dependency type/lag linking taskId -> succId.
                    foreach ($reverse[$succId] as $pred) {
                        if ($pred['source'] !== $taskId) {
                            continue;
                        }

                        $lag = $pred['lag'] * 86400;

                        $candidate = match ($pred['type']) {
                            DependencyType::FS => $ls[$succId] - $lag,
                            DependencyType::SS => $ls[$succId] - $lag + $duration[$taskId],
                            DependencyType::FF => $lf[$succId] - $lag,
                            DependencyType::SF => $lf[$succId] - $lag + $duration[$taskId],
                            default             => $ls[$succId] - $lag,
                        };

                        $minLf = $minLf === null ? $candidate : min($minLf, $candidate);
                    }
                }
                $lf[$taskId] = $minLf ?? $projectEnd;
            }

            $ls[$taskId] = $lf[$taskId] - $duration[$taskId];
        }

        // ── Assemble rows, sorted by earliest start then name ──
        $rows = [];
        foreach ($tasksData as $id => $task) {
            $floatDays = (int)round(($ls[$id] - $es[$id]) / 86400);

            $rows[] = [
                'task_id'      => $id,
                'name'         => $task['name'],
                'is_milestone' => (bool)$task['is_milestone'],
                'es'           => date('Y-m-d H:i:s', $es[$id]),
                'ef'           => date('Y-m-d H:i:s', $ef[$id]),
                'ls'           => date('Y-m-d H:i:s', $ls[$id]),
                'lf'           => date('Y-m-d H:i:s', $lf[$id]),
                'float_days'   => $floatDays,
                'is_critical'  => $floatDays <= 0,
            ];
        }

        usort($rows, fn ($a, $b) => $a['es'] <=> $b['es'] ?: $a['name'] <=> $b['name']);

        return ['rows' => $rows, 'cycleDetected' => false];
    }
}
