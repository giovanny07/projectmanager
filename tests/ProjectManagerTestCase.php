<?php

namespace GlpiPlugin\Projectmanager\Tests;

use PHPUnit\Framework\TestCase;
use Project;
use ProjectTask;

/**
 * Base test case: creates a throwaway Project with a handful of
 * ProjectTasks against the real database, and cleans everything up
 * (including any dependency/baseline rows, via ON DELETE CASCADE) in
 * tearDown().
 */
abstract class ProjectManagerTestCase extends TestCase
{
    private array $createdTaskIds = [];
    private ?int $projectId = null;

    protected function createTestProject(string $name = 'PHPUnit test project'): int
    {
        $project = new Project();
        $this->projectId = (int)$project->add([
            'name'        => $name . ' ' . uniqid(),
            'entities_id' => 0,
        ]);

        $this->assertGreaterThan(0, $this->projectId, 'Failed to create the test project');

        return $this->projectId;
    }

    protected function createTestTask(int $projectId, array $fields = []): int
    {
        $task = new ProjectTask();
        $taskId = (int)$task->add(array_merge([
            'name'         => 'Task ' . uniqid(),
            'projects_id'  => $projectId,
            'percent_done' => 0,
        ], $fields));

        $this->assertGreaterThan(0, $taskId, 'Failed to create a test task');
        $this->createdTaskIds[] = $taskId;

        return $taskId;
    }

    protected function reloadTask(int $taskId): array
    {
        $task = new ProjectTask();
        $task->getFromDB($taskId);
        return $task->fields;
    }

    protected function tearDown(): void
    {
        $task = new ProjectTask();
        foreach ($this->createdTaskIds as $taskId) {
            $task->delete(['id' => $taskId], true);
        }
        $this->createdTaskIds = [];

        if ($this->projectId !== null) {
            $project = new Project();
            $project->delete(['id' => $this->projectId], true);
            $this->projectId = null;
        }

        parent::tearDown();
    }
}
