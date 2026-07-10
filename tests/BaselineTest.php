<?php

namespace GlpiPlugin\Projectmanager\Tests;

use GlpiPlugin\Projectmanager\Baseline;

class BaselineTest extends ProjectManagerTestCase
{
    public function testSetBaselineCapturesCurrentPlannedDates(): void
    {
        $projectId = $this->createTestProject();
        $a = $this->createTestTask($projectId, [
            'plan_start_date' => '2026-01-01 08:00:00',
            'plan_end_date'   => '2026-01-05 17:00:00',
        ]);
        // No planned dates yet — must be skipped, not crash.
        $b = $this->createTestTask($projectId);

        $this->assertFalse(Baseline::hasBaseline($projectId));

        $result = Baseline::setBaselineForProject($projectId);

        $this->assertSame(1, $result['set']);
        $this->assertSame(1, $result['skipped']);
        $this->assertTrue(Baseline::hasBaseline($projectId));

        $rows = Baseline::getComparisonForProject($projectId);
        $rowsById = array_column($rows, null, 'task_id');

        $this->assertSame('2026-01-01 08:00:00', $rowsById[$a]['baseline_start']);
        $this->assertSame('2026-01-05 17:00:00', $rowsById[$a]['baseline_end']);
        $this->assertSame(0, $rowsById[$a]['start_variance']);
        $this->assertSame(0, $rowsById[$a]['end_variance']);
        $this->assertNull($rowsById[$b]['baseline_start']);
    }

    public function testVarianceReflectsHowFarThePlanHasMoved(): void
    {
        $projectId = $this->createTestProject();
        $a = $this->createTestTask($projectId, [
            'plan_start_date' => '2026-01-01 08:00:00',
            'plan_end_date'   => '2026-01-05 17:00:00',
        ]);

        Baseline::setBaselineForProject($projectId);

        // Simulate a cascade delay of 3 days on the current plan.
        global $DB;
        $DB->update('glpi_projecttasks', [
            'plan_start_date' => '2026-01-04 08:00:00',
            'plan_end_date'   => '2026-01-08 17:00:00',
        ], ['id' => $a]);

        $rows = Baseline::getComparisonForProject($projectId);
        $row  = array_column($rows, null, 'task_id')[$a];

        $this->assertSame(3, $row['start_variance']);
        $this->assertSame(3, $row['end_variance']);
    }

    public function testReSettingTheBaselineOverwritesThePreviousOne(): void
    {
        $projectId = $this->createTestProject();
        $a = $this->createTestTask($projectId, [
            'plan_start_date' => '2026-01-01 08:00:00',
            'plan_end_date'   => '2026-01-05 17:00:00',
        ]);

        Baseline::setBaselineForProject($projectId);

        global $DB;
        $DB->update('glpi_projecttasks', [
            'plan_start_date' => '2026-02-01 08:00:00',
            'plan_end_date'   => '2026-02-05 17:00:00',
        ], ['id' => $a]);

        Baseline::setBaselineForProject($projectId);

        // Still exactly one baseline row per task — an upsert, not a new row.
        $count = (int)$DB->request([
            'COUNT'  => 'c',
            'FROM'   => Baseline::getTable(),
            'WHERE'  => ['projecttasks_id' => $a],
        ])->current()['c'];
        $this->assertSame(1, $count);

        $rows = Baseline::getComparisonForProject($projectId);
        $row  = array_column($rows, null, 'task_id')[$a];
        $this->assertSame('2026-02-01 08:00:00', $row['baseline_start']);
        $this->assertSame(0, $row['start_variance']);
    }
}
