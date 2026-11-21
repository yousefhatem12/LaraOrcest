<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Yousef\LaraOrcest\Contracts\GraphNodeContract;
use Yousef\LaraOrcest\Contracts\TaskDefinition;
use Yousef\LaraOrcest\Graph\Exceptions\StateMergeConflictException;
use Yousef\LaraOrcest\Graph\GraphEngine;
use Yousef\LaraOrcest\Graph\Nodes\ParallelNode;
use Yousef\LaraOrcest\Graph\Nodes\SubGraphNode;
use Yousef\LaraOrcest\State\RunState;

class ParallelExecutionTest extends TestCase
{
    protected function createMockNode(string $key, callable $action): GraphNodeContract
    {
        return new class($key, $action) implements GraphNodeContract {
            public function __construct(
                protected string $key,
                protected mixed $action
            ) {}

            public function getKey(): string
            {
                return $this->key;
            }

            public function execute(RunState $state): RunState
            {
                $state = $state->withCurrentNode($this->key);
                $state = $state->incrementSteps();

                return ($this->action)($state);
            }
        };
    }

    public function test_independent_tasks_run_in_parallel_and_merge_artifacts_and_tokens_cleanly(): void
    {
        $nodeA = $this->createMockNode('worker_orders', function (RunState $s) {
            $s = $s->recordArtifact('app/Models/Order.php', 'created', 'Created Order model');
            return $s->recordTokenUsage(100, 50, 0.005);
        });

        $nodeB = $this->createMockNode('worker_invoices', function (RunState $s) {
            $s = $s->recordArtifact('app/Models/Invoice.php', 'created', 'Created Invoice model');
            return $s->recordTokenUsage(200, 80, 0.010);
        });

        $parallelNode = new ParallelNode('parallel_models');
        $parallelNode->addBranch('worker_orders', $nodeA, scopePaths: ['app/Models/Order.php']);
        $parallelNode->addBranch('worker_invoices', $nodeB, scopePaths: ['app/Models/Invoice.php']);

        $engine = new GraphEngine();
        $engine->addNode($parallelNode);
        $engine->addEdge('parallel_models', GraphEngine::TERMINAL_COMPLETED);

        $initialState = RunState::initial('run_parallel_1', 'Build billing system');
        $initialState = $initialState->recordTokenUsage(50, 20, 0.002); // Initial base tokens
        $initialState = $initialState->withCurrentNode('parallel_models');

        $finalState = $engine->run($initialState);

        $this->assertTrue($finalState->isCompleted());

        // Artifacts should contain both files without duplication
        $this->assertCount(2, $finalState->artifacts);
        $artifactPaths = array_column($finalState->artifacts, 'path');
        $this->assertContains('app/Models/Order.php', $artifactPaths);
        $this->assertContains('app/Models/Invoice.php', $artifactPaths);

        // Tokens should be cleanly accumulated:
        // Base: 50 prompt, 20 completion = 70 total
        // Worker A: 100 prompt, 50 completion = 150 total
        // Worker B: 200 prompt, 80 completion = 280 total
        // Expected total: 70 + 150 + 280 = 500 total tokens
        $this->assertEquals(500, $finalState->getTotalTokens());
        $this->assertEquals(350, $finalState->guardrails['token_usage']['prompt_tokens']);
        $this->assertEquals(150, $finalState->guardrails['token_usage']['completion_tokens']);
        $this->assertEquals(0.017, $finalState->getEstimatedCost());

        // Step count should accumulate parent step (1) + branch steps (1 + 1) = 3
        $this->assertEquals(3, $finalState->guardrails['step_count']);

        // Metadata should track parallel merges log
        $this->assertNotEmpty($finalState->metadata['parallel_merges']);
    }

    public function test_dependent_tasks_wait_until_prerequisites_complete(): void
    {
        $executionOrder = [];

        $nodeDb = $this->createMockNode('step_db', function (RunState $s) use (&$executionOrder) {
            $executionOrder[] = 'db';
            return $s->recordArtifact('database/migrations/2026_create_tasks.php', 'created', 'Tasks migration');
        });

        $nodeModel = $this->createMockNode('step_model', function (RunState $s) use (&$executionOrder) {
            $executionOrder[] = 'model';
            return $s->recordArtifact('app/Models/Task.php', 'created', 'Task model');
        });

        $nodeApi = $this->createMockNode('step_api', function (RunState $s) use (&$executionOrder) {
            $executionOrder[] = 'api';
            return $s->recordArtifact('routes/api.php', 'modified', 'Task routes');
        });

        $parallelNode = new ParallelNode('pipeline');
        // 'model' depends on 'db'
        $parallelNode->addBranch('step_model', $nodeModel, dependsOn: ['step_db'], scopePaths: ['app/Models/Task.php']);
        // 'api' depends on 'model'
        $parallelNode->addBranch('step_api', $nodeApi, dependsOn: ['step_model'], scopePaths: ['routes/api.php']);
        // 'db' has no dependencies
        $parallelNode->addBranch('step_db', $nodeDb, dependsOn: [], scopePaths: ['database/']);

        $engine = new GraphEngine();
        $engine->addNode($parallelNode);
        $engine->addEdge('pipeline', GraphEngine::TERMINAL_COMPLETED);

        $state = RunState::initial('run_dep_test', 'Pipeline test');
        $state = $state->withCurrentNode('pipeline');

        $finalState = $engine->run($state);

        $this->assertTrue($finalState->isCompleted());
        $this->assertEquals(['db', 'model', 'api'], $executionOrder);
        $this->assertCount(3, $finalState->artifacts);
    }

    public function test_overlapping_scope_paths_are_serialized_into_consecutive_waves(): void
    {
        $executionOrder = [];

        // Worker 1 and Worker 2 both modify app/Models/User.php
        $worker1 = $this->createMockNode('w1', function (RunState $s) use (&$executionOrder) {
            $executionOrder[] = 'w1';
            return $s->recordArtifact('app/Models/User.php', 'modified', 'W1 user update');
        });

        $worker2 = $this->createMockNode('w2', function (RunState $s) use (&$executionOrder) {
            $executionOrder[] = 'w2';
            return $s->recordArtifact('app/Models/User.php', 'modified', 'W2 user update');
        });

        $parallelNode = new ParallelNode('scoped_parallel');
        $parallelNode->addBranch('w1', $worker1, priority: 10, scopePaths: ['app/Models/User.php']);
        $parallelNode->addBranch('w2', $worker2, priority: 5, scopePaths: ['app/Models/User.php']);

        $engine = new GraphEngine();
        $engine->addNode($parallelNode);
        $engine->addEdge('scoped_parallel', GraphEngine::TERMINAL_COMPLETED);

        $state = RunState::initial('run_scope_test', 'Scope test');
        $state = $state->withCurrentNode('scoped_parallel');

        $finalState = $engine->run($state);

        // They are executed in serialized waves due to scope conflict, with higher priority running first
        $this->assertTrue($finalState->isCompleted());
        $this->assertEquals(['w1', 'w2'], $executionOrder);
    }

    public function test_conflicting_artifact_mutations_across_branches_fail_safely(): void
    {
        // Two parallel branches without declared scope boundaries that touch the same file
        $branchA = $this->createMockNode('branch_a', function (RunState $s) {
            return $s->recordArtifact('app/Models/Shared.php', 'modified', 'Branch A changes');
        });

        $branchB = $this->createMockNode('branch_b', function (RunState $s) {
            return $s->recordArtifact('app/Models/Shared.php', 'modified', 'Branch B changes');
        });

        $parallelNode = new ParallelNode('parallel_conflict');
        $parallelNode->addBranch('branch_a', $branchA);
        $parallelNode->addBranch('branch_b', $branchB);

        $engine = new GraphEngine();
        $engine->addNode($parallelNode);
        $engine->addEdge('parallel_conflict', GraphEngine::TERMINAL_COMPLETED);

        $state = RunState::initial('run_conflict_test', 'Conflict test');
        $state = $state->withCurrentNode('parallel_conflict');

        $finalState = $engine->run($state);

        $this->assertTrue($finalState->isFailed());
        $this->assertStringContainsString('Parallel execution state conflict', $finalState->getFailureReason());
        $this->assertStringContainsString('app/Models/Shared.php', $finalState->getFailureReason());
    }

    public function test_conflicting_artifact_mutations_can_throw_exception_when_configured(): void
    {
        $baseState = RunState::initial('run_strict', 'Strict conflict test');

        $forkA = $baseState->forkBranch('branch_a');
        $forkA = $forkA->recordArtifact('routes/api.php', 'modified', 'A edits');

        $forkB = $baseState->forkBranch('branch_b');
        $forkB = $forkB->recordArtifact('routes/api.php', 'modified', 'B edits');

        $this->expectException(StateMergeConflictException::class);
        $this->expectExceptionMessage('overlapping artifact modifications detected');

        $baseState->mergeParallelStates([$forkA, $forkB], ['throw_on_conflict' => true]);
    }

    public function test_sub_graph_node_executes_embedded_graph_cleanly(): void
    {
        $subGraph = new GraphEngine();
        $subNode1 = $this->createMockNode('sub_step_1', fn ($s) => $s->recordArtifact('sub1.php', 'created', 'sub1'));
        $subNode2 = $this->createMockNode('sub_step_2', fn ($s) => $s->recordArtifact('sub2.php', 'created', 'sub2'));

        $subGraph->addNode($subNode1);
        $subGraph->addNode($subNode2);
        $subGraph->addEdge('sub_step_1', 'sub_step_2');
        $subGraph->addEdge('sub_step_2', GraphEngine::TERMINAL_COMPLETED);

        $mainGraph = new GraphEngine();
        $mainGraph->addNode(new SubGraphNode('embedded_subgraph', $subGraph));
        $mainGraph->addEdge('embedded_subgraph', GraphEngine::TERMINAL_COMPLETED);

        $state = RunState::initial('run_subgraph', 'SubGraph test');
        $state = $state->withCurrentNode('embedded_subgraph');

        $finalState = $mainGraph->run($state);

        $this->assertTrue($finalState->isCompleted());
        $this->assertCount(2, $finalState->artifacts);
    }

    public function test_execute_parallel_tasks_convenience_method(): void
    {
        $engine = new GraphEngine();
        $state = RunState::initial('run_tasks', 'Parallel tasks test');

        $tasks = [
            ['id' => 't1', 'title' => 'T1', 'priority' => 10, 'scope_paths' => ['app/T1.php']],
            ['id' => 't2', 'title' => 'T2', 'priority' => 5, 'scope_paths' => ['app/T2.php']],
            ['id' => 't3', 'title' => 'T3', 'priority' => 1, 'depends_on' => ['t1', 't2'], 'scope_paths' => ['app/T3.php']],
        ];

        $finalState = $engine->executeParallelTasks($state, $tasks, function (TaskDefinition $task, RunState $branchState) {
            return $branchState->recordArtifact($task->getScopePaths()[0], 'created', "Built {$task->title}")
                ->recordTokenUsage(100, 20);
        });

        $this->assertFalse($finalState->isFailed());
        $this->assertCount(3, $finalState->artifacts);
        $this->assertEquals(360, $finalState->getTotalTokens());
    }

    public function test_test_results_and_reviews_merge_correctly(): void
    {
        $baseState = RunState::initial('run_tr', 'Test merge');

        $branchPass = $baseState->forkBranch('branch_pass');
        $branchPass = $branchPass->recordTestResult(true, 0, '2 passed', 'OK');
        $branchPass = $branchPass->recordReview('approved', ['Good code']);

        $branchFail = $baseState->forkBranch('branch_fail');
        $branchFail = $branchFail->recordTestResult(false, 1, '1 failed', 'Failure in TestB');
        $branchFail = $branchFail->recordReview('revise', ['Fix bug']);

        $merged = $baseState->mergeParallelStates([$branchPass, $branchFail]);

        $this->assertFalse($merged->areTestsPassing());
        $this->assertEquals(1, $merged->testResults['exit_code']);
        $this->assertStringContainsString('branch_fail', $merged->testResults['output']);
        $this->assertCount(2, $merged->reviews);
    }

    public function test_backlog_tasks_updated_to_completed_on_parallel_merge(): void
    {
        $task1 = ['id' => 'task_1', 'title' => 'T1', 'status' => 'pending'];
        $task2 = ['id' => 'task_2', 'title' => 'T2', 'status' => 'pending'];
        $task3 = ['id' => 'task_3', 'title' => 'T3', 'status' => 'pending'];

        $baseState = RunState::initial('run_backlog', 'Backlog merge test')
            ->withBacklog([$task1, $task2, $task3]);

        $branch1 = $baseState->forkBranch('b1');
        $branch1 = $branch1->withBacklog([
            ['id' => 'task_1', 'title' => 'T1', 'status' => 'completed'],
            $task2,
            $task3,
        ]);

        $branch2 = $baseState->forkBranch('b2');
        $branch2 = $branch2->withBacklog([
            $task1,
            ['id' => 'task_2', 'title' => 'T2', 'status' => 'completed'],
            $task3,
        ]);

        $merged = $baseState->mergeParallelStates([$branch1, $branch2]);

        $this->assertEquals('completed', $merged->backlog[0]['status']);
        $this->assertEquals('completed', $merged->backlog[1]['status']);
        $this->assertEquals('pending', $merged->backlog[2]['status']);
    }

    public function test_git_agent_mutations_serialized_in_parallel_execution(): void
    {
        $executionOrder = [];

        $gitNode = $this->createMockNode('git_agent', function (RunState $s) use (&$executionOrder) {
            $executionOrder[] = 'git';
            return $s->withGitState(['feature_branch' => 'orcest/feat-1']);
        });

        $backendNode = $this->createMockNode('backend_task', function (RunState $s) use (&$executionOrder) {
            $executionOrder[] = 'backend';
            return $s->recordArtifact('app/Services/Pay.php', 'created', 'Payment service');
        });

        $dbNode = $this->createMockNode('db_task', function (RunState $s) use (&$executionOrder) {
            $executionOrder[] = 'db';
            return $s->recordArtifact('database/pay.php', 'created', 'Payment migration');
        });

        $parallel = new ParallelNode('parallel_work');
        $parallel->addBranch('git_agent', $gitNode, priority: 10);
        $parallel->addBranch('backend_task', $backendNode, priority: 5, scopePaths: ['app/Services/Pay.php']);
        $parallel->addBranch('db_task', $dbNode, priority: 5, scopePaths: ['database/pay.php']);

        $engine = new GraphEngine();
        $engine->addNode($parallel);
        $engine->addEdge('parallel_work', GraphEngine::TERMINAL_COMPLETED);

        $state = RunState::initial('run_git_serial', 'Git serial test')
            ->withCurrentNode('parallel_work');

        $final = $engine->run($state);

        $this->assertTrue($final->isCompleted());
        // git_agent should run in isolation first, then backend and db run in parallel wave
        $this->assertEquals('git', $executionOrder[0]);
        $this->assertCount(3, $executionOrder);
        $this->assertEquals('orcest/feat-1', $final->gitState['feature_branch']);
    }

    public function test_step_listeners_notified_during_parallel_execution(): void
    {
        $engine = new GraphEngine();
        $notifiedSteps = [];

        $engine->onStep(function (RunState $state, string $node, string $message) use (&$notifiedSteps) {
            $notifiedSteps[] = ['node' => $node, 'message' => $message];
        });

        $state = RunState::initial('run_notify', 'Notify test');
        $tasks = [
            ['id' => 'step_a', 'title' => 'Step A', 'scope_paths' => ['app/A.php']],
            ['id' => 'step_b', 'title' => 'Step B', 'scope_paths' => ['app/B.php']],
        ];

        $final = $engine->executeParallelTasks($state, $tasks, function (TaskDefinition $t, RunState $s) {
            return $s->recordArtifact($t->getScopePaths()[0], 'created', 'done');
        });

        $this->assertFalse($final->isFailed());
        $this->assertNotEmpty($notifiedSteps);
        $this->assertStringContainsString('parallel_wave', $notifiedSteps[0]['node']);
    }
}
