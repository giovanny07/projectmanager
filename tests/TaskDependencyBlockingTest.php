<?php

namespace GlpiPlugin\Projectmanager\Tests;

use GlpiPlugin\Projectmanager\Config;
use GlpiPlugin\Projectmanager\TaskDependency;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use ProjectTask;

/**
 * TaskDependency::onProjectTaskPreUpdate() reads Config through
 * Config::getInstance(), which caches a singleton for the whole PHP
 * process. Every test here runs in its own process so the config
 * change it makes is guaranteed to be read fresh, and never leaks
 * into other test files.
 */
class TaskDependencyBlockingTest extends ProjectManagerTestCase
{
    private ?array $originalConfig = null;

    protected function setUp(): void
    {
        parent::setUp();

        global $DB;
        $this->originalConfig = $DB->request([
            'FROM' => 'glpi_plugin_projectmanager_configs',
            'WHERE' => ['id' => 1],
        ])->current();
    }

    protected function tearDown(): void
    {
        if ($this->originalConfig !== null) {
            global $DB;
            $DB->update('glpi_plugin_projectmanager_configs', [
                'module_dependencies'       => $this->originalConfig['module_dependencies'],
                'block_unmet_dependencies'  => $this->originalConfig['block_unmet_dependencies'],
            ], ['id' => 1]);
            Config::resetInstance();
        }

        parent::tearDown();
    }

    private function setBlockingConfig(bool $moduleEnabled, bool $blockingEnabled): void
    {
        global $DB;
        $DB->update('glpi_plugin_projectmanager_configs', [
            'module_dependencies'      => $moduleEnabled ? 1 : 0,
            'block_unmet_dependencies' => $blockingEnabled ? 1 : 0,
        ], ['id' => 1]);

        // Kernel::boot() already primed Config's singleton (via
        // plugin_init_projectmanager()) before this test method ran, so
        // without this the write above would never be observed.
        Config::resetInstance();
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testBlocksStartingATaskWhenFinishToStartPredecessorIsIncomplete(): void
    {
        $this->setBlockingConfig(moduleEnabled: true, blockingEnabled: true);

        $projectId = $this->createTestProject();
        $a = $this->createTestTask($projectId, ['percent_done' => 50]);
        $b = $this->createTestTask($projectId, ['percent_done' => 0]);

        $dep = new TaskDependency();
        $dep->add(['projecttasks_id_source' => $a, 'projecttasks_id_target' => $b, 'type' => 'FS']);

        $task = new ProjectTask();
        $task->getFromDB($b);
        $task->input = ['id' => $b, 'percent_done' => 10];

        TaskDependency::onProjectTaskPreUpdate($task);

        $this->assertFalse($task->input, 'Starting B should have been vetoed while A is not done');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAllowsStartingATaskOnceFinishToStartPredecessorIsComplete(): void
    {
        $this->setBlockingConfig(moduleEnabled: true, blockingEnabled: true);

        $projectId = $this->createTestProject();
        $a = $this->createTestTask($projectId, ['percent_done' => 100]);
        $b = $this->createTestTask($projectId, ['percent_done' => 0]);

        $dep = new TaskDependency();
        $dep->add(['projecttasks_id_source' => $a, 'projecttasks_id_target' => $b, 'type' => 'FS']);

        $task = new ProjectTask();
        $task->getFromDB($b);
        $task->input = ['id' => $b, 'percent_done' => 10];

        TaskDependency::onProjectTaskPreUpdate($task);

        $this->assertSame(['id' => $b, 'percent_done' => 10], $task->input);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDoesNothingWhenBlockingIsDisabled(): void
    {
        $this->setBlockingConfig(moduleEnabled: true, blockingEnabled: false);

        $projectId = $this->createTestProject();
        $a = $this->createTestTask($projectId, ['percent_done' => 0]);
        $b = $this->createTestTask($projectId, ['percent_done' => 0]);

        $dep = new TaskDependency();
        $dep->add(['projecttasks_id_source' => $a, 'projecttasks_id_target' => $b, 'type' => 'FS']);

        $task = new ProjectTask();
        $task->getFromDB($b);
        $task->input = ['id' => $b, 'percent_done' => 10];

        TaskDependency::onProjectTaskPreUpdate($task);

        $this->assertNotFalse($task->input, 'Blocking is off, so the save must not be vetoed');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testStartToStartAllowsSuccessorAsSoonAsPredecessorStarted(): void
    {
        $this->setBlockingConfig(moduleEnabled: true, blockingEnabled: true);

        $projectId = $this->createTestProject();
        $a = $this->createTestTask($projectId, ['percent_done' => 10]);
        $b = $this->createTestTask($projectId, ['percent_done' => 0]);

        $dep = new TaskDependency();
        $dep->add(['projecttasks_id_source' => $a, 'projecttasks_id_target' => $b, 'type' => 'SS']);

        $task = new ProjectTask();
        $task->getFromDB($b);
        $task->input = ['id' => $b, 'percent_done' => 10];

        TaskDependency::onProjectTaskPreUpdate($task);

        $this->assertNotFalse($task->input, 'SS only needs the predecessor to have started');
    }
}
