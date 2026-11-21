<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Yousef\LaraOrcest\Contracts\TaskDefinition;
use Yousef\LaraOrcest\Graph\Exceptions\CyclicDependencyException;
use Yousef\LaraOrcest\Graph\ParallelScheduler;

class ParallelSchedulerTest extends TestCase
{
    protected ParallelScheduler $scheduler;

    protected function setUp(): void
    {
        $this->scheduler = new ParallelScheduler();
    }

    public function test_independent_tasks_scheduled_in_first_wave(): void
    {
        $tasks = [
            new TaskDefinition(id: 'task_models', title: 'Models', description: 'Create models', priority: 10, scopePaths: ['app/Models/Task.php']),
            new TaskDefinition(id: 'task_routes', title: 'Routes', description: 'Define routes', priority: 5, scopePaths: ['routes/api.php']),
            new TaskDefinition(id: 'task_config', title: 'Config', description: 'Setup config', priority: 8, scopePaths: ['config/tasks.php']),
        ];

        $runnable = $this->scheduler->getRunnableTasks($tasks, maxConcurrency: 4);

        $this->assertCount(3, $runnable);
        // Priority order: 10 (task_models), 8 (task_config), 5 (task_routes)
        $this->assertEquals('task_models', $runnable[0]->id);
        $this->assertEquals('task_config', $runnable[1]->id);
        $this->assertEquals('task_routes', $runnable[2]->id);
    }

    public function test_concurrency_cap_limits_batch_size(): void
    {
        $tasks = [
            new TaskDefinition(id: 'task_1', title: 'Task 1', description: 'Task 1', priority: 1, scopePaths: ['path1']),
            new TaskDefinition(id: 'task_2', title: 'Task 2', description: 'Task 2', priority: 2, scopePaths: ['path2']),
            new TaskDefinition(id: 'task_3', title: 'Task 3', description: 'Task 3', priority: 3, scopePaths: ['path3']),
        ];

        $runnable = $this->scheduler->getRunnableTasks($tasks, maxConcurrency: 2);

        $this->assertCount(2, $runnable);
        // Higher priority first
        $this->assertEquals('task_3', $runnable[0]->id);
        $this->assertEquals('task_2', $runnable[1]->id);
    }

    public function test_dependent_tasks_wait_until_prerequisites_are_satisfied(): void
    {
        $tasks = [
            new TaskDefinition(id: 'migration', title: 'Create DB', description: 'Migration', priority: 10, scopePaths: ['database/']),
            new TaskDefinition(id: 'model', title: 'Create Model', description: 'Model', priority: 8, dependencies: ['migration'], scopePaths: ['app/Models/User.php']),
            new TaskDefinition(id: 'controller', title: 'Controller', description: 'Controller', priority: 5, dependencies: ['model'], scopePaths: ['app/Http/Controllers/']),
        ];

        // Wave 1: only 'migration' is runnable
        $wave1 = $this->scheduler->getRunnableTasks($tasks, completedIds: []);
        $this->assertCount(1, $wave1);
        $this->assertEquals('migration', $wave1[0]->id);

        // Wave 2: after 'migration' completes, 'model' becomes runnable
        $wave2 = $this->scheduler->getRunnableTasks($tasks, completedIds: ['migration']);
        $this->assertCount(1, $wave2);
        $this->assertEquals('model', $wave2[0]->id);

        // Wave 3: after 'model' completes, 'controller' is runnable
        $wave3 = $this->scheduler->getRunnableTasks($tasks, completedIds: ['migration', 'model']);
        $this->assertCount(1, $wave3);
        $this->assertEquals('controller', $wave3[0]->id);
    }

    public function test_overlapping_scope_paths_are_serialized_into_separate_waves(): void
    {
        $tasks = [
            // Task A and Task B both touch app/Models/Task.php (direct overlap)
            new TaskDefinition(id: 'task_a', title: 'Task A', description: 'A', priority: 10, scopePaths: ['app/Models/Task.php']),
            new TaskDefinition(id: 'task_b', title: 'Task B', description: 'B', priority: 8, scopePaths: ['app/Models/Task.php']),
            // Task C touches disjoint routes/api.php
            new TaskDefinition(id: 'task_c', title: 'Task C', description: 'C', priority: 5, scopePaths: ['routes/api.php']),
        ];

        // When evaluating wave 1: task_a and task_c can run, but task_b is excluded due to scope conflict with task_a
        $wave1 = $this->scheduler->getRunnableTasks($tasks, completedIds: [], maxConcurrency: 4);

        $this->assertCount(2, $wave1);
        $wave1Ids = array_column($wave1, 'id');
        $this->assertContains('task_a', $wave1Ids);
        $this->assertContains('task_c', $wave1Ids);
        $this->assertNotContains('task_b', $wave1Ids);

        // In wave 2 (after task_a and task_c complete), task_b runs safely
        $wave2 = $this->scheduler->getRunnableTasks($tasks, completedIds: ['task_a', 'task_c']);
        $this->assertCount(1, $wave2);
        $this->assertEquals('task_b', $wave2[0]->id);
    }

    public function test_directory_containment_scope_overlap_is_serialized(): void
    {
        $tasks = [
            // Task A touches directory app/Models
            new TaskDefinition(id: 'task_models_dir', title: 'Models Dir', description: 'Dir', priority: 10, scopePaths: ['app/Models']),
            // Task B touches specific file inside app/Models
            new TaskDefinition(id: 'task_user_model', title: 'User Model', description: 'File', priority: 8, scopePaths: ['app/Models/User.php']),
        ];

        $wave1 = $this->scheduler->getRunnableTasks($tasks, completedIds: [], maxConcurrency: 4);

        $this->assertCount(1, $wave1);
        $this->assertEquals('task_models_dir', $wave1[0]->id);
    }

    public function test_git_mutations_are_serialized_strictly(): void
    {
        $tasks = [
            new TaskDefinition(id: 'git_init', title: 'git_agent', description: 'Create branch', priority: 10, metadata: ['is_git_mutation' => true]),
            new TaskDefinition(id: 'backend', title: 'Backend', description: 'Build API', priority: 8, scopePaths: ['app/Http/']),
            new TaskDefinition(id: 'database', title: 'Database', description: 'Migrations', priority: 6, scopePaths: ['database/']),
        ];

        // Git mutation must run alone in a batch
        $wave1 = $this->scheduler->getRunnableTasks($tasks, completedIds: [], maxConcurrency: 4);

        $this->assertCount(1, $wave1);
        $this->assertEquals('git_init', $wave1[0]->id);

        // After git_init completes, remaining non-conflicting tasks run together
        $wave2 = $this->scheduler->getRunnableTasks($tasks, completedIds: ['git_init'], maxConcurrency: 4);
        $this->assertCount(2, $wave2);
        $wave2Ids = array_column($wave2, 'id');
        $this->assertContains('backend', $wave2Ids);
        $this->assertContains('database', $wave2Ids);
    }

    public function test_cyclic_dependencies_throw_cyclic_dependency_exception(): void
    {
        $tasks = [
            new TaskDefinition(id: 'task_x', title: 'X', description: 'X', dependencies: ['task_y']),
            new TaskDefinition(id: 'task_y', title: 'Y', description: 'Y', dependencies: ['task_z']),
            new TaskDefinition(id: 'task_z', title: 'Z', description: 'Z', dependencies: ['task_x']),
        ];

        $this->expectException(CyclicDependencyException::class);
        $this->expectExceptionMessage('Cyclic dependency detected');

        $this->scheduler->getRunnableTasks($tasks, completedIds: []);
    }

    public function test_schedule_batches_produces_full_deterministic_waves(): void
    {
        $tasks = [
            new TaskDefinition(id: 'db', title: 'DB', description: 'DB', priority: 10, scopePaths: ['database/']),
            new TaskDefinition(id: 'model_a', title: 'Model A', description: 'Model A', priority: 8, dependencies: ['db'], scopePaths: ['app/Models/A.php']),
            new TaskDefinition(id: 'model_b', title: 'Model B', description: 'Model B', priority: 8, dependencies: ['db'], scopePaths: ['app/Models/B.php']),
            new TaskDefinition(id: 'api', title: 'API', description: 'API', priority: 5, dependencies: ['model_a', 'model_b'], scopePaths: ['routes/api.php']),
        ];

        $batches = $this->scheduler->scheduleBatches($tasks, maxConcurrency: 4);

        $this->assertCount(3, $batches);

        // Wave 0: db
        $this->assertCount(1, $batches[0]);
        $this->assertEquals('db', $batches[0][0]->id);

        // Wave 1: model_a & model_b in parallel
        $this->assertCount(2, $batches[1]);
        $wave1Ids = array_column($batches[1], 'id');
        $this->assertContains('model_a', $wave1Ids);
        $this->assertContains('model_b', $wave1Ids);

        // Wave 2: api
        $this->assertCount(1, $batches[2]);
        $this->assertEquals('api', $batches[2][0]->id);
    }

    public function test_can_run_in_parallel_helper(): void
    {
        $taskA = new TaskDefinition(id: 'a', title: 'A', description: 'A', scopePaths: ['app/Models/User.php']);
        $taskB = new TaskDefinition(id: 'b', title: 'B', description: 'B', scopePaths: ['app/Models/Post.php']);
        $taskC = new TaskDefinition(id: 'c', title: 'C', description: 'C', scopePaths: ['app/Models/User.php']);
        $taskD = new TaskDefinition(id: 'd', title: 'D', description: 'D', dependencies: ['a'], scopePaths: ['routes/api.php']);
        $taskGit = new TaskDefinition(id: 'git', title: 'Git', description: 'Git branch', metadata: ['is_git_mutation' => true]);

        $this->assertTrue(ParallelScheduler::canRunInParallel($taskA, $taskB));
        $this->assertFalse(ParallelScheduler::canRunInParallel($taskA, $taskC)); // Scope conflict
        $this->assertFalse(ParallelScheduler::canRunInParallel($taskA, $taskD)); // Dependency
        $this->assertFalse(ParallelScheduler::canRunInParallel($taskA, $taskGit)); // Git mutation
    }
}
