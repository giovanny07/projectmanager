<?php

namespace GlpiPlugin\Projectmanager\Tests;

use GlpiPlugin\Projectmanager\CriticalPath;
use GlpiPlugin\Projectmanager\TaskDependency;

class CriticalPathTest extends ProjectManagerTestCase
{
    public function testALinearChainIsEntirelyCritical(): void
    {
        $projectId = $this->createTestProject();
        $a = $this->createTestTask($projectId, [
            'plan_start_date' => '2026-01-01 08:00:00',
            'plan_end_date'   => '2026-01-03 17:00:00',
        ]);
        $b = $this->createTestTask($projectId, [
            'plan_start_date' => '2026-01-01 08:00:00',
            'plan_end_date'   => '2026-01-02 17:00:00',
        ]);

        $dep = new TaskDependency();
        $dep->add(['projecttasks_id_source' => $a, 'projecttasks_id_target' => $b, 'type' => 'FS']);

        $result = CriticalPath::computeForProject($projectId);
        $this->assertFalse($result['cycleDetected']);

        $rows = array_column($result['rows'], null, 'task_id');
        $this->assertTrue($rows[$a]['is_critical']);
        $this->assertSame(0, $rows[$a]['float_days']);
        $this->assertTrue($rows[$b]['is_critical']);
        $this->assertSame(0, $rows[$b]['float_days']);
    }

    public function testAShorterParallelBranchHasPositiveFloatAndIsNotCritical(): void
    {
        $projectId = $this->createTestProject();
        $a = $this->createTestTask($projectId, [
            'plan_start_date' => '2026-01-01 08:00:00',
            'plan_end_date'   => '2026-01-03 17:00:00',
        ]);
        // Long branch: determines project completion.
        $b = $this->createTestTask($projectId, [
            'plan_start_date' => '2026-01-01 08:00:00',
            'plan_end_date'   => '2026-01-05 17:00:00',
        ]);
        // Short branch off the same predecessor: has slack.
        $c = $this->createTestTask($projectId, [
            'plan_start_date' => '2026-01-01 08:00:00',
            'plan_end_date'   => '2026-01-04 17:00:00',
        ]);

        $depB = new TaskDependency();
        $depB->add(['projecttasks_id_source' => $a, 'projecttasks_id_target' => $b, 'type' => 'FS']);
        $depC = new TaskDependency();
        $depC->add(['projecttasks_id_source' => $a, 'projecttasks_id_target' => $c, 'type' => 'FS']);

        $rows = array_column(CriticalPath::computeForProject($projectId)['rows'], null, 'task_id');

        $this->assertTrue($rows[$a]['is_critical']);
        $this->assertTrue($rows[$b]['is_critical'], 'The longer branch must be critical');
        $this->assertFalse($rows[$c]['is_critical'], 'The shorter parallel branch must have slack');
        $this->assertGreaterThan(0, $rows[$c]['float_days']);
    }

    public function testAnIsolatedTaskWithNoDependenciesIsCritical(): void
    {
        $projectId = $this->createTestProject();
        $a = $this->createTestTask($projectId, [
            'plan_start_date' => '2026-01-01 08:00:00',
            'plan_end_date'   => '2026-01-02 17:00:00',
        ]);

        $rows = array_column(CriticalPath::computeForProject($projectId)['rows'], null, 'task_id');

        // A single task with no predecessor/successor is, trivially, its
        // own critical path: it alone determines when the project ends.
        $this->assertTrue($rows[$a]['is_critical']);
        $this->assertSame(0, $rows[$a]['float_days']);
    }

    public function testCycleIsReportedInsteadOfComputingGarbage(): void
    {
        $projectId = $this->createTestProject();
        $a = $this->createTestTask($projectId);
        $b = $this->createTestTask($projectId);

        global $DB;
        $DB->insert(TaskDependency::getTable(), [
            'projecttasks_id_source' => $a, 'projecttasks_id_target' => $b, 'type' => 'FS',
        ]);
        $DB->insert(TaskDependency::getTable(), [
            'projecttasks_id_source' => $b, 'projecttasks_id_target' => $a, 'type' => 'FS',
        ]);

        $result = CriticalPath::computeForProject($projectId);

        $this->assertTrue($result['cycleDetected']);
        $this->assertSame([], $result['rows']);
    }
}
