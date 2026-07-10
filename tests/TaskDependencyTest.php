<?php

namespace GlpiPlugin\Projectmanager\Tests;

use GlpiPlugin\Projectmanager\TaskDependency;
use ProjectTask;

class TaskDependencyTest extends ProjectManagerTestCase
{
    public function testCycleDetectionOnDirectPair(): void
    {
        $projectId = $this->createTestProject();
        $a = $this->createTestTask($projectId);
        $b = $this->createTestTask($projectId);

        $dep = new TaskDependency();
        $depId = $dep->add([
            'projecttasks_id_source' => $a,
            'projecttasks_id_target' => $b,
            'type'                   => 'FS',
        ]);
        $this->assertGreaterThan(0, $depId);

        // A -> B already exists, so B -> A would close a cycle.
        $this->assertTrue(TaskDependency::wouldCreateCycle($b, $a));

        // A third, unrelated task never creates a cycle.
        $c = $this->createTestTask($projectId);
        $this->assertFalse(TaskDependency::wouldCreateCycle($a, $c));
    }

    public function testAddingACyclicDependencyIsRejected(): void
    {
        $projectId = $this->createTestProject();
        $a = $this->createTestTask($projectId);
        $b = $this->createTestTask($projectId);

        $first = new TaskDependency();
        $this->assertGreaterThan(0, $first->add([
            'projecttasks_id_source' => $a,
            'projecttasks_id_target' => $b,
            'type'                   => 'FS',
        ]));

        $second = new TaskDependency();
        $result = $second->add([
            'projecttasks_id_source' => $b,
            'projecttasks_id_target' => $a,
            'type'                   => 'FS',
        ]);

        $this->assertFalse($result);
    }

    public function testATaskCannotDependOnItself(): void
    {
        $projectId = $this->createTestProject();
        $a = $this->createTestTask($projectId);

        $dep = new TaskDependency();
        $result = $dep->add([
            'projecttasks_id_source' => $a,
            'projecttasks_id_target' => $a,
            'type'                   => 'FS',
        ]);

        $this->assertFalse($result);
    }

    public function testInvalidTypeFallsBackToFS(): void
    {
        $projectId = $this->createTestProject();
        $a = $this->createTestTask($projectId);
        $b = $this->createTestTask($projectId);

        $dep = new TaskDependency();
        $depId = $dep->add([
            'projecttasks_id_source' => $a,
            'projecttasks_id_target' => $b,
            'type'                   => 'NOT_A_TYPE',
        ]);

        $this->assertGreaterThan(0, $depId);
        $dep->getFromDB($depId);
        $this->assertSame('FS', $dep->fields['type']);
    }

    public function testCascadeMovesSuccessorAfterFinishToStartPredecessor(): void
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
        $dep->add([
            'projecttasks_id_source' => $a,
            'projecttasks_id_target' => $b,
            'type'                   => 'FS',
            'lag_days'               => 0,
        ]);

        $result = TaskDependency::rescheduleProject($projectId);

        $this->assertSame(1, $result['rescheduled']);
        $this->assertSame([], $result['errors']);

        $bAfter = $this->reloadTask($b);
        $this->assertSame('2026-01-03 17:00:00', $bAfter['plan_start_date']);
        // B's original 33h duration (Jan 1 08:00 -> Jan 2 17:00) is preserved,
        // now shifted to start right after A ends.
        $this->assertSame('2026-01-05 02:00:00', $bAfter['plan_end_date']);
    }

    public function testCascadeNeverPullsATaskEarlier(): void
    {
        $projectId = $this->createTestProject();
        $a = $this->createTestTask($projectId, [
            'plan_start_date' => '2026-01-01 08:00:00',
            'plan_end_date'   => '2026-01-02 17:00:00',
        ]);
        // B is already scheduled well after A finishes.
        $b = $this->createTestTask($projectId, [
            'plan_start_date' => '2026-02-01 08:00:00',
            'plan_end_date'   => '2026-02-02 17:00:00',
        ]);

        $dep = new TaskDependency();
        $dep->add([
            'projecttasks_id_source' => $a,
            'projecttasks_id_target' => $b,
            'type'                   => 'FS',
        ]);

        $result = TaskDependency::rescheduleProject($projectId);

        $this->assertSame(0, $result['rescheduled']);
        $bAfter = $this->reloadTask($b);
        $this->assertSame('2026-02-01 08:00:00', $bAfter['plan_start_date']);
    }

    public function testCascadeDetectsCyclesInsertedDirectlyInDb(): void
    {
        // wouldCreateCycle() protects add(), but rescheduleProject() must
        // also defend itself against a cycle that ends up in the table
        // some other way (data import, manual SQL, a future bulk-add API).
        $projectId = $this->createTestProject();
        $a = $this->createTestTask($projectId);
        $b = $this->createTestTask($projectId);

        global $DB;
        $DB->insert(TaskDependency::getTable(), [
            'projecttasks_id_source' => $a,
            'projecttasks_id_target' => $b,
            'type'                   => 'FS',
        ]);
        $DB->insert(TaskDependency::getTable(), [
            'projecttasks_id_source' => $b,
            'projecttasks_id_target' => $a,
            'type'                   => 'FS',
        ]);

        $result = TaskDependency::rescheduleProject($projectId);

        $this->assertSame(0, $result['rescheduled']);
        $this->assertNotEmpty($result['errors']);
    }

    public function testCountersMatchActualDependencyRows(): void
    {
        $projectId = $this->createTestProject();
        $a = $this->createTestTask($projectId);
        $b = $this->createTestTask($projectId);
        $c = $this->createTestTask($projectId);

        $dep = new TaskDependency();
        $dep->add(['projecttasks_id_source' => $a, 'projecttasks_id_target' => $b, 'type' => 'FS']);
        $dep = new TaskDependency();
        $dep->add(['projecttasks_id_source' => $a, 'projecttasks_id_target' => $c, 'type' => 'FS']);

        $this->assertSame(2, TaskDependency::countSuccessors($a));
        $this->assertSame(0, TaskDependency::countPredecessors($a));
        $this->assertSame(1, TaskDependency::countPredecessors($b));
    }
}
