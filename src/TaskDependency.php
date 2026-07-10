<?php

/**
 * ---------------------------------------------------------------------
 * Project Manager — TaskDependency
 * Dependency engine and cascade rescheduling.
 * ---------------------------------------------------------------------
 *
 * @author    IMAGUNET S.A.S.
 * @license   GPL-3.0-or-later
 */

namespace GlpiPlugin\Projectmanager;

use CommonDBTM;
use Glpi\Application\View\TemplateRenderer;
use ProjectTask;
use Session;
use Toolbox;
use Log;

/**
 * Manages dependencies between project tasks.
 *
 * Responsibilities:
 *  1. Dependency CRUD (inherits from CommonDBTM)
 *  2. Tab on ProjectTask (getTabNameForItem / displayTabContentForItem)
 *  3. PRE_ITEM_UPDATE hook → opt-in real blocking
 *  4. ITEM_UPDATE hook → cascade rescheduling engine
 *  5. Cycle detection (DFS)
 *  6. Topological order (Kahn's algorithm)
 */
class TaskDependency extends CommonDBTM
{
    /** Plugin's own right for this object */
    public static $rightname = 'plugin_projectmanager_taskdependency';

    /** Log changes in GLPI history */
    public $dohistory = true;

    // ── Metadata ─────────────────────────────────────────────────────

    public static function getTypeName($nb = 0): string
    {
        return _n('Task dependency', 'Task dependencies', $nb, 'projectmanager');
    }

    public static function getIcon(): string
    {
        return 'ti ti-git-branch';
    }

    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_projectmanager_taskdependencies';
    }

    // ── Tab on ProjectTask ────────────────────────────────────────────

    /**
     * Registers the tab and returns its label with a counter.
     */
    public function getTabNameForItem(\CommonGLPI $item, $withtemplate = 0): string
    {
        if (!($item instanceof ProjectTask)) {
            return '';
        }

        if (!Session::haveRight(self::$rightname, READ)) {
            return '';
        }

        $total = self::countPredecessors($item->getID())
               + self::countSuccessors($item->getID());

        return self::createTabEntry(
            __('Dependencies', 'projectmanager'),
            $total
        );
    }

    /**
     * Renders the tab content via Twig.
     */
    public static function displayTabContentForItem(
        \CommonGLPI $item,
        $tabnum = 1,
        $withtemplate = 0
    ): bool {
        if (!($item instanceof ProjectTask)) {
            return false;
        }

        $taskId = $item->getID();

        TemplateRenderer::getInstance()->display(
            '@projectmanager/taskdependency.list.html.twig',
            [
                'task'         => $item,
                'task_id'      => $taskId,
                'predecessors' => self::getPredecessorsOf($taskId),
                'successors'   => self::getSuccessorsOf($taskId),
                'dep_types'    => DependencyType::getAll(),
                'can_write'    => Session::haveRight(self::$rightname, UPDATE),
                'cascade_auto' => (bool)(int)Config::get('cascade_auto', '1'),
                'csrf_token'   => Session::getNewCSRFToken(),
                'ajax_url'     => \Plugin::getWebDir('projectmanager', true) . '/ajax/get_project_tasks.php',
            ]
        );

        return true;
    }

    // ── Hook: PRE_ITEM_UPDATE on ProjectTask (opt-in real blocking) ───

    /**
     * Fired by GLPI BEFORE a ProjectTask is saved. If
     * "block_unmet_dependencies" is enabled, vetoes the save (by
     * setting $item->input = false) when the task tries to
     * start/finish without its predecessors satisfying the
     * dependency (FS/SS/FF/SF). Disabled by default: without this
     * option, GLPI core only warns visually, never blocks.
     */
    public static function onProjectTaskPreUpdate(\CommonDBTM $item): void
    {
        if (!($item instanceof ProjectTask)) {
            return;
        }

        if (!Config::isModuleEnabled('dependencies')) {
            return;
        }

        if (!(bool)(int)Config::get('block_unmet_dependencies', '0')) {
            return;
        }

        if (!is_array($item->input)) {
            return;
        }

        $preds = self::getPredecessorsOf($item->getID());
        if (empty($preds)) {
            return;
        }

        $oldPercent = (int)($item->fields['percent_done'] ?? 0);
        $newPercent = isset($item->input['percent_done'])
            ? (int)$item->input['percent_done']
            : $oldPercent;

        $isStarting  = $newPercent > 0 && $oldPercent === 0;
        $isFinishing = $newPercent >= 100 && $oldPercent < 100;

        if (!$isStarting && !$isFinishing) {
            return;
        }

        foreach ($preds as $pred) {
            $predDone = (int)$pred['percent_done'];

            $violated = match ($pred['type']) {
                DependencyType::FS => $isStarting && $predDone < 100,
                DependencyType::SS => $isStarting && $predDone === 0,
                DependencyType::FF => $isFinishing && $predDone < 100,
                DependencyType::SF => $isFinishing && $predDone === 0,
                default             => false,
            };

            if ($violated) {
                Session::addMessageAfterRedirect(
                    sprintf(
                        __('Cannot save: predecessor "%1$s" does not satisfy the %2$s dependency yet.', 'projectmanager'),
                        $pred['source_name'],
                        $pred['type']
                    ),
                    false,
                    ERROR
                );
                $item->input = false;
                return;
            }
        }
    }

    // ── Hook: ITEM_UPDATE on ProjectTask ──────────────────────────────

    /**
     * Fired by GLPI when a ProjectTask is updated.
     * Only runs the cascade if the module is active and config allows it.
     */
    public static function onProjectTaskUpdate(\CommonDBTM $item): void
    {
        if (!($item instanceof ProjectTask)) {
            return;
        }

        if (!Config::isModuleEnabled('dependencies')) {
            return;
        }

        if (!(bool)(int)Config::get('cascade_auto', '1')) {
            return; // User prefers to recalculate manually
        }

        // Only reschedule if a schedule-relevant field actually changed
        $watched = ['plan_start_date', 'plan_end_date', 'real_end_date', 'percent_done'];
        $changed = false;

        foreach ($watched as $field) {
            if (isset($item->updates) && in_array($field, $item->updates, true)) {
                $changed = true;
                break;
            }
        }

        if (!$changed) {
            return;
        }

        $projectId = (int)($item->fields['projects_id'] ?? 0);
        if ($projectId > 0) {
            self::rescheduleProject($projectId, $item->getID());
        }
    }

    // ── Cascade rescheduling engine ───────────────────────────────────

    /**
     * Reschedules every task in a project honoring dependencies.
     *
     * Algorithm:
     *  1. Load the project's tasks and dependencies
     *  2. Build a directed graph
     *  3. Detect cycles with Kahn's algorithm (topological sort)
     *  4. Compute the earliest start date per FS/SS/FF/SF type and lag
     *  5. Apply new dates ONLY if they push the task forward
     *  6. Log to GLPI history if cascade_log is enabled
     *
     * @param int $projectId      Project to reschedule
     * @param int $changedTaskId  Task that triggered the recalculation (for logging)
     * @return array{rescheduled: int, errors: string[]}
     */
    public static function rescheduleProject(int $projectId, int $changedTaskId = 0): array
    {
        global $DB;

        $result = ['rescheduled' => 0, 'errors' => []];
        $logEnabled = (bool)(int)Config::get('cascade_log', '1');

        // 1. Load the project's tasks
        $tasksData = [];
        foreach ($DB->request([
            'FROM'  => 'glpi_projecttasks',
            'WHERE' => ['projects_id' => $projectId, 'is_deleted' => 0],
        ]) as $row) {
            $tasksData[(int)$row['id']] = $row;
        }

        if (count($tasksData) < 2) {
            return $result; // Not enough tasks for a cascade to be possible
        }

        $taskIds = array_keys($tasksData);

        // 2. Load dependencies between this project's tasks
        $adjacency = []; // source → [target, ...]
        $reverse   = []; // target → [{source, type, lag}, ...]
        $inDegree  = array_fill_keys($taskIds, 0);

        foreach ($DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['projecttasks_id_source' => $taskIds, 'is_deleted' => 0],
        ]) as $dep) {
            $src = (int)$dep['projecttasks_id_source'];
            $tgt = (int)$dep['projecttasks_id_target'];

            // Only dependencies between tasks of this same project
            if (!isset($tasksData[$src], $tasksData[$tgt])) {
                continue;
            }

            $adjacency[$src][] = $tgt;
            $reverse[$tgt][]   = [
                'source' => $src,
                'type'   => $dep['type'],
                'lag'    => (int)$dep['lag_days'],
            ];
            $inDegree[$tgt]++;
        }

        // 3. Kahn's algorithm: topological order + cycle detection
        $queue = [];
        foreach ($inDegree as $id => $deg) {
            if ($deg === 0) {
                $queue[] = $id;
            }
        }

        $topoOrder = [];
        while (!empty($queue)) {
            $cur = array_shift($queue);
            $topoOrder[] = $cur;
            foreach ($adjacency[$cur] ?? [] as $neighbor) {
                if (--$inDegree[$neighbor] === 0) {
                    $queue[] = $neighbor;
                }
            }
        }

        // If not every node with dependencies was processed → cycle
        $nodesWithDeps = array_unique(
            array_merge(array_keys($adjacency), array_keys($reverse))
        );
        if (count($topoOrder) < count($nodesWithDeps)) {
            $msg = __('Cycle detected in project dependencies. Cascade aborted.', 'projectmanager');
            $result['errors'][] = $msg;
            Toolbox::logInFile('php-errors', "ProjectManager: cycle in project #{$projectId}\n");
            return $result;
        }

        // 4. Compute earliest start in topological order
        $earlyStart = [];
        foreach ($tasksData as $id => $task) {
            $earlyStart[$id] = $task['plan_start_date']
                ? strtotime($task['plan_start_date'])
                : time();
        }

        foreach ($topoOrder as $taskId) {
            $preds = $reverse[$taskId] ?? [];
            if (empty($preds)) {
                continue;
            }

            $task    = $tasksData[$taskId];
            $minTs   = 0;

            foreach ($preds as $pred) {
                $src    = $tasksData[$pred['source']] ?? null;
                if (!$src) {
                    continue;
                }

                $lag       = $pred['lag'] * 86400;
                $srcEnd    = strtotime($src['real_end_date']   ?: ($src['plan_end_date']   ?: 'now'));
                $srcStart  = strtotime($src['real_start_date'] ?: ($src['plan_start_date'] ?: 'now'));
                $taskDur   = self::durationSeconds($task);

                $candidate = match ($pred['type']) {
                    DependencyType::FS => $srcEnd   + $lag,
                    DependencyType::SS => $srcStart + $lag,
                    DependencyType::FF => $srcEnd   + $lag - $taskDur,
                    DependencyType::SF => $srcStart + $lag - $taskDur,
                    default            => $srcEnd   + $lag,
                };

                $minTs = max($minTs, $candidate);
            }

            if ($minTs > 0) {
                $earlyStart[$taskId] = $minTs;
            }
        }

        // 5. Apply new dates only if they cause a delay (never shorten)
        foreach ($topoOrder as $taskId) {
            $task        = $tasksData[$taskId];
            $newStartTs  = $earlyStart[$taskId] ?? 0;
            $currStartTs = $task['plan_start_date'] ? strtotime($task['plan_start_date']) : 0;

            if ($newStartTs <= $currStartTs || $newStartTs === 0) {
                continue;
            }

            $delayDays  = (int)(($newStartTs - $currStartTs) / 86400);
            $dur        = self::durationSeconds($task);
            $newStart   = date('Y-m-d H:i:s', $newStartTs);
            $newEnd     = date('Y-m-d H:i:s', $newStartTs + $dur);

            // Direct DB update to avoid recursing into the hook
            $DB->update('glpi_projecttasks', [
                'plan_start_date' => $newStart,
                'plan_end_date'   => $newEnd,
                'date_mod'        => date('Y-m-d H:i:s'),
            ], ['id' => $taskId]);

            // Sync the local array so successors use the new date
            $tasksData[$taskId]['plan_start_date'] = $newStart;
            $tasksData[$taskId]['plan_end_date']   = $newEnd;

            // 6. GLPI history
            if ($logEnabled) {
                Log::history(
                    $taskId,
                    'ProjectTask',
                    [0, '', sprintf(
                        __('ProjectManager: rescheduled +%d day(s) by cascade from task #%d', 'projectmanager'),
                        $delayDays,
                        $changedTaskId
                    )],
                    'plugin_projectmanager',
                    Log::HISTORY_UPDATE_RELATION
                );
            }

            $result['rescheduled']++;
        }

        return $result;
    }

    // ── Graph queries ──────────────────────────────────────────────────

    public static function getPredecessorsOf(int $taskId): array
    {
        global $DB;

        $rows = [];
        foreach ($DB->request([
            'SELECT' => [
                'dep.id AS dep_id', 'dep.projecttasks_id_source',
                'dep.type', 'dep.lag_days',
                'task.name AS source_name', 'task.percent_done', 'task.is_milestone',
                'task.plan_start_date', 'task.plan_end_date', 'task.real_end_date',
            ],
            'FROM'      => [self::getTable() . ' AS dep'],
            'LEFT JOIN' => [
                'glpi_projecttasks AS task' => [
                    'ON' => ['task' => 'id', 'dep' => 'projecttasks_id_source'],
                ],
            ],
            'WHERE' => [
                'dep.projecttasks_id_target' => $taskId,
                'dep.is_deleted'             => 0,
                'task.is_deleted'            => 0,
            ],
            'ORDER' => 'task.name ASC',
        ]) as $row) {
            $rows[] = $row;
        }

        return $rows;
    }

    public static function getSuccessorsOf(int $taskId): array
    {
        global $DB;

        $rows = [];
        foreach ($DB->request([
            'SELECT' => [
                'dep.id AS dep_id', 'dep.projecttasks_id_target',
                'dep.type', 'dep.lag_days',
                'task.name AS target_name', 'task.percent_done', 'task.is_milestone',
                'task.plan_start_date', 'task.plan_end_date',
            ],
            'FROM'      => [self::getTable() . ' AS dep'],
            'LEFT JOIN' => [
                'glpi_projecttasks AS task' => [
                    'ON' => ['task' => 'id', 'dep' => 'projecttasks_id_target'],
                ],
            ],
            'WHERE' => [
                'dep.projecttasks_id_source' => $taskId,
                'dep.is_deleted'             => 0,
                'task.is_deleted'            => 0,
            ],
            'ORDER' => 'task.name ASC',
        ]) as $row) {
            $rows[] = $row;
        }

        return $rows;
    }

    public static function countPredecessors(int $taskId): int
    {
        return countElementsInTable(
            self::getTable(),
            ['projecttasks_id_target' => $taskId, 'is_deleted' => 0]
        );
    }

    public static function countSuccessors(int $taskId): int
    {
        return countElementsInTable(
            self::getTable(),
            ['projecttasks_id_source' => $taskId, 'is_deleted' => 0]
        );
    }

    // ── Cycle detection ────────────────────────────────────────────────

    /**
     * Checks whether adding source→target would create a cycle.
     * Uses DFS from target trying to reach source.
     */
    public static function wouldCreateCycle(int $sourceId, int $targetId): bool
    {
        return self::isReachable($targetId, $sourceId);
    }

    private static function isReachable(int $from, int $to, array &$visited = []): bool
    {
        if ($from === $to) {
            return true;
        }
        if (in_array($from, $visited, true)) {
            return false;
        }
        $visited[] = $from;

        foreach (self::getSuccessorsOf($from) as $succ) {
            if (self::isReachable((int)$succ['projecttasks_id_target'], $to, $visited)) {
                return true;
            }
        }

        return false;
    }

    // ── Input validation ────────────────────────────────────────────────

    public function prepareInputForAdd($input)
    {
        $input = parent::prepareInputForAdd($input);
        return $input !== false ? $this->validateInput($input, true) : false;
    }

    public function prepareInputForUpdate($input)
    {
        $input = parent::prepareInputForUpdate($input);
        return $input !== false ? $this->validateInput($input, false) : false;
    }

    private function validateInput(array $input, bool $isNew): array|false
    {
        $src = (int)($input['projecttasks_id_source'] ?? $this->fields['projecttasks_id_source'] ?? 0);
        $tgt = (int)($input['projecttasks_id_target'] ?? $this->fields['projecttasks_id_target'] ?? 0);

        if ($src > 0 && $tgt > 0 && $src === $tgt) {
            Session::addMessageAfterRedirect(
                __('A task cannot depend on itself.', 'projectmanager'),
                false,
                ERROR
            );
            return false;
        }

        if ($src > 0 && $tgt > 0 && ($isNew || isset($input['projecttasks_id_source'], $input['projecttasks_id_target']))) {
            if (self::wouldCreateCycle($src, $tgt)) {
                Session::addMessageAfterRedirect(
                    __('This dependency would create a cycle in the project schedule.', 'projectmanager'),
                    false,
                    ERROR
                );
                return false;
            }
        }

        if (isset($input['type']) && !DependencyType::isValid($input['type'])) {
            $input['type'] = DependencyType::FS;
        }

        if (isset($input['lag_days'])) {
            $input['lag_days'] = max(-999, min(999, (int)$input['lag_days']));
        }

        return $input;
    }

    // ── Private helper ───────────────────────────────────────────────

    /**
     * Planned duration of a task in seconds.
     * Uses plan_start_date / plan_end_date; falls back to planned_duration (minutes).
     */
    private static function durationSeconds(array $task): int
    {
        if ($task['plan_start_date'] && $task['plan_end_date']) {
            return max(0, strtotime($task['plan_end_date']) - strtotime($task['plan_start_date']));
        }
        if (!empty($task['planned_duration'])) {
            return (int)$task['planned_duration'] * 60;
        }
        return 0;
    }
}
