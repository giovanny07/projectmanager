<?php

/**
 * ---------------------------------------------------------------------
 * Project Manager — TaskDependency
 * Motor de dependencias y replanificación en cascada.
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
 * Tipos de dependencia entre tareas de proyecto.
 */
final class DependencyType
{
    const FS = 'FS'; // Finish-to-Start  (más común)
    const SS = 'SS'; // Start-to-Start
    const FF = 'FF'; // Finish-to-Finish
    const SF = 'SF'; // Start-to-Finish  (raro)

    public static function getAll(): array
    {
        return [
            self::FS => __('Finish-to-Start (FS)', 'projectmanager'),
            self::SS => __('Start-to-Start (SS)',  'projectmanager'),
            self::FF => __('Finish-to-Finish (FF)', 'projectmanager'),
            self::SF => __('Start-to-Finish (SF)', 'projectmanager'),
        ];
    }

    public static function isValid(string $type): bool
    {
        return in_array($type, [self::FS, self::SS, self::FF, self::SF], true);
    }
}

/**
 * Gestión de dependencias entre tareas de proyecto.
 *
 * Responsabilidades:
 *  1. CRUD de dependencias (hereda de CommonDBTM)
 *  2. Pestaña en ProjectTask (getTabNameForItem / displayTabContentForItem)
 *  3. Hook ITEM_UPDATE → motor de replanificación en cascada
 *  4. Detección de ciclos (DFS)
 *  5. Orden topológico (Kahn's algorithm)
 */
class TaskDependency extends CommonDBTM
{
    /** Derecho propio del plugin para este objeto */
    public static $rightname = 'plugin_projectmanager_taskdependency';

    /** Registra cambios en el historial de GLPI */
    public $dohistory = true;

    // ── Metadatos ────────────────────────────────────────────────────

    public static function getTypeName($nb = 0): string
    {
        return _n('Task dependency', 'Task dependencies', $nb, 'projectmanager');
    }

    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_projectmanager_taskdependencies';
    }

    // ── Pestaña en ProjectTask ───────────────────────────────────────

    /**
     * Registra la pestaña y retorna su label con contador.
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
     * Renderiza el contenido de la pestaña vía Twig.
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

    // ── Hook: ITEM_UPDATE en ProjectTask ─────────────────────────────

    /**
     * Disparado por GLPI cuando se actualiza una ProjectTask.
     * Solo ejecuta cascada si el módulo está activo y la config lo permite.
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
            return; // Usuario prefiere recalcular manualmente
        }

        // Solo replanificar si cambiaron campos relevantes al cronograma
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

    // ── Motor de replanificación en cascada ──────────────────────────

    /**
     * Replanifica todas las tareas de un proyecto respetando dependencias.
     *
     * Algoritmo:
     *  1. Carga tareas y dependencias del proyecto
     *  2. Construye grafo dirigido
     *  3. Detecta ciclos con Kahn's algorithm (topological sort)
     *  4. Calcula earliest start date por tipo FS/SS/FF/SF y lag
     *  5. Aplica nuevas fechas SOLO si hay desplazamiento hacia adelante
     *  6. Registra en historial GLPI si cascade_log está activo
     *
     * @param int $projectId      ID del proyecto a replanificar
     * @param int $changedTaskId  Tarea que disparó el recálculo (para log)
     * @return array{rescheduled: int, errors: string[]}
     */
    public static function rescheduleProject(int $projectId, int $changedTaskId = 0): array
    {
        global $DB;

        $result = ['rescheduled' => 0, 'errors' => []];
        $logEnabled = (bool)(int)Config::get('cascade_log', '1');

        // 1. Cargar tareas del proyecto
        $tasksData = [];
        foreach ($DB->request([
            'FROM'  => 'glpi_projecttasks',
            'WHERE' => ['projects_id' => $projectId, 'is_deleted' => 0],
        ]) as $row) {
            $tasksData[(int)$row['id']] = $row;
        }

        if (count($tasksData) < 2) {
            return $result; // Sin suficientes tareas no hay cascada posible
        }

        $taskIds = array_keys($tasksData);

        // 2. Cargar dependencias entre las tareas de este proyecto
        $adjacency = []; // source → [target, ...]
        $reverse   = []; // target → [{source, type, lag}, ...]
        $inDegree  = array_fill_keys($taskIds, 0);

        foreach ($DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['projecttasks_id_source' => $taskIds, 'is_deleted' => 0],
        ]) as $dep) {
            $src = (int)$dep['projecttasks_id_source'];
            $tgt = (int)$dep['projecttasks_id_target'];

            // Solo dependencias entre tareas del mismo proyecto
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

        // 3. Kahn's algorithm: orden topológico + detección de ciclos
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

        // Si no se procesaron todos los nodos con dependencias → ciclo
        $nodesWithDeps = array_unique(
            array_merge(array_keys($adjacency), array_keys($reverse))
        );
        if (count($topoOrder) < count($nodesWithDeps)) {
            $msg = __('Cycle detected in project dependencies. Cascade aborted.', 'projectmanager');
            $result['errors'][] = $msg;
            Toolbox::logError("ProjectManager: cycle in project #{$projectId}");
            return $result;
        }

        // 4. Calcular earliest start en orden topológico
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

        // 5. Aplicar nuevas fechas solo si hay retraso (nunca acortar)
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

            // Actualización directa en BD para evitar recursión del hook
            $DB->update('glpi_projecttasks', [
                'plan_start_date' => $newStart,
                'plan_end_date'   => $newEnd,
                'date_mod'        => date('Y-m-d H:i:s'),
            ], ['id' => $taskId]);

            // Sincronizar el array local para que las sucesoras usen fecha nueva
            $tasksData[$taskId]['plan_start_date'] = $newStart;
            $tasksData[$taskId]['plan_end_date']   = $newEnd;

            // 6. Historial GLPI
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

    // ── Consultas de grafo ───────────────────────────────────────────

    public static function getPredecessorsOf(int $taskId): array
    {
        global $DB;

        $rows = [];
        foreach ($DB->request([
            'SELECT' => [
                'dep.id AS dep_id', 'dep.projecttasks_id_source',
                'dep.type', 'dep.lag_days',
                'task.name AS source_name', 'task.percent_done',
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
                'task.name AS target_name', 'task.percent_done',
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

    // ── Detección de ciclos ──────────────────────────────────────────

    /**
     * Verifica si agregar source→target crearía un ciclo.
     * Usa DFS desde target buscando llegar a source.
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

    // ── Validación de input ──────────────────────────────────────────

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

    // ── Helper privado ───────────────────────────────────────────────

    /**
     * Duración planificada de una tarea en segundos.
     * Usa plan_start_date / plan_end_date; fallback a planned_duration en minutos.
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
