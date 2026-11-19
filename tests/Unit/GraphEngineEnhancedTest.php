<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Yousef\LaraOrcest\Contracts\CheckpointerContract;
use Yousef\LaraOrcest\Contracts\GraphNodeContract;
use Yousef\LaraOrcest\Graph\GraphEngine;
use Yousef\LaraOrcest\State\RunState;

class GraphEngineEnhancedTest extends TestCase
{
    protected function createMockNode(string $key, ?callable $action = null): GraphNodeContract
    {
        return new class($key, $action) implements GraphNodeContract {
            public function __construct(
                protected string $key,
                protected mixed $action = null
            ) {}

            public function getKey(): string
            {
                return $this->key;
            }

            public function execute(RunState $state): RunState
            {
                $state = $state->withCurrentNode($this->key);
                $state = $state->incrementSteps();

                if ($this->action !== null) {
                    return ($this->action)($state);
                }

                return $state;
            }
        };
    }

    public function test_node_registration_and_lookup(): void
    {
        $engine = new GraphEngine();
        $this->assertFalse($engine->hasNode('node_a'));
        $this->assertNull($engine->getNode('node_a'));
        $this->assertSame([], $engine->getNodes());

        $nodeA = $this->createMockNode('node_a');
        $nodeB = $this->createMockNode('node_b');

        $engine->addNode($nodeA);
        $engine->addNode($nodeB);

        $this->assertTrue($engine->hasNode('node_a'));
        $this->assertTrue($engine->hasNode('node_b'));
        $this->assertSame($nodeA, $engine->getNode('node_a'));
        $this->assertCount(2, $engine->getNodes());
    }

    public function test_edge_registration_and_priority_sorting(): void
    {
        $engine = new GraphEngine();
        $engine->addEdge('step_1', 'step_low', null, 5);
        $engine->addEdge('step_1', 'step_high', null, 25);
        $engine->addEdge('step_1', 'step_mid', null, 15);

        $edges = $engine->getEdges('step_1');
        $this->assertCount(3, $edges);
        $this->assertSame(25, $edges[0]->priority);
        $this->assertSame('step_high', $edges[0]->toNode);
        $this->assertSame(15, $edges[1]->priority);
        $this->assertSame('step_mid', $edges[1]->toNode);
        $this->assertSame(5, $edges[2]->priority);
        $this->assertSame('step_low', $edges[2]->toNode);
    }

    public function test_linear_progression_across_custom_nodes(): void
    {
        $engine = new GraphEngine();

        $engine->addNode($this->createMockNode('node_1', fn ($s) => $s->recordArtifact('file1.php', 'created', 'f1')));
        $engine->addNode($this->createMockNode('node_2', fn ($s) => $s->recordArtifact('file2.php', 'created', 'f2')));
        $engine->addNode($this->createMockNode('node_3', fn ($s) => $s->recordArtifact('file3.php', 'created', 'f3')));

        $engine->addEdge('node_1', 'node_2');
        $engine->addEdge('node_2', 'node_3');
        $engine->addEdge('node_3', GraphEngine::TERMINAL_COMPLETED);

        $state = RunState::initial('run_linear', 'Linear test');
        $state = $state->withCurrentNode('node_1');

        $finalState = $engine->run($state);

        $this->assertTrue($finalState->isCompleted());
        $this->assertSame(3, $finalState->guardrails['step_count']);
        $this->assertCount(3, $finalState->artifacts);
    }

    public function test_resume_from_paused_state(): void
    {
        $engine = new GraphEngine();

        $engine->addNode($this->createMockNode('node_start'));
        $engine->addNode($this->createMockNode('node_finish'));

        $engine->addEdge('node_start', 'node_finish');
        $engine->addEdge('node_finish', GraphEngine::TERMINAL_COMPLETED);

        // Create a paused state that was stopped at node_finish
        $state = RunState::initial('run_resume', 'Resume test');
        $state = $state->withCurrentNode('node_finish');
        $state = $state->pause();

        $this->assertTrue($state->isPaused());

        $finalState = $engine->run($state);

        $this->assertTrue($finalState->isCompleted());
        $this->assertSame(1, $finalState->guardrails['step_count']);
    }

    public function test_terminal_states_return_immediately(): void
    {
        $engine = new GraphEngine();
        $engine->addNode($this->createMockNode('node_a'));

        $completedState = RunState::initial('run_c', 'Completed test')->complete();
        $this->assertSame($completedState, $engine->run($completedState));

        $failedState = RunState::initial('run_f', 'Failed test')->fail('Failed already');
        $this->assertSame($failedState, $engine->run($failedState));
    }

    public function test_unhandled_node_exception_halts_graph_cleanly(): void
    {
        $engine = new GraphEngine();

        $failingNode = $this->createMockNode('exploding_node', function () {
            throw new RuntimeException('Database connection failed');
        });

        $engine->addNode($failingNode);
        $engine->addEdge('exploding_node', GraphEngine::TERMINAL_COMPLETED);

        $state = RunState::initial('run_err', 'Error test');
        $state = $state->withCurrentNode('exploding_node');

        $finalState = $engine->run($state);

        $this->assertTrue($finalState->isFailed());
        $this->assertStringContainsString('Database connection failed', $finalState->getFailureReason());
    }

    public function test_cyclic_reviewer_revise_loop_bounded_by_max_fix_attempts(): void
    {
        $engine = new GraphEngine();

        // Node that sets up review requiring revision
        $reviewerNode = $this->createMockNode('reviewer_agent', function (RunState $s) {
            return $s->recordReview('revise', ['Missing authorization policy']);
        });

        // Fixer node increments fix attempts
        $fixerNode = $this->createMockNode('fixer_agent', function (RunState $s) {
            return $s->incrementFixAttempts();
        });

        $qaNode = $this->createMockNode('qa_agent', function (RunState $s) {
            return $s->recordTestResult(true, 0, '1 passed', 'OK');
        });

        $engine->addNode($reviewerNode);
        $engine->addNode($fixerNode);
        $engine->addNode($qaNode);

        // Reviewer: if approved -> completed; if revise and can fix -> fixer_agent; if cannot fix -> failed
        $engine->addEdge('reviewer_agent', GraphEngine::TERMINAL_COMPLETED, fn ($s) => $s->isReviewApproved(), 20);
        $engine->addEdge('reviewer_agent', 'fixer_agent', fn ($s) => ! $s->isReviewApproved() && $s->canAttemptFix(), 10);
        $engine->addEdge('reviewer_agent', GraphEngine::TERMINAL_FAILED, fn ($s) => ! $s->isReviewApproved() && ! $s->canAttemptFix(), 5);

        // Fixer -> QA
        $engine->addEdge('fixer_agent', 'qa_agent');

        // QA -> Reviewer
        $engine->addEdge('qa_agent', 'reviewer_agent');

        $state = RunState::initial('run_rev_loop', 'Review loop test', [
            'max_fix_attempts' => 2,
        ]);
        $state = $state->withCurrentNode('reviewer_agent');

        $finalState = $engine->run($state);

        $this->assertTrue($finalState->isFailed());
        $this->assertSame(2, $finalState->guardrails['fix_attempts']);
        $this->assertStringContainsString('Maximum fix attempts (2) reached', $finalState->getFailureReason());
    }

    public function test_step_listener_and_checkpointer_integration(): void
    {
        $engine = new GraphEngine();

        $savedStates = [];
        $checkpointer = new class($savedStates) implements CheckpointerContract {
            public function __construct(public array &$states) {}
            public function save(RunState $state): void { $this->states[] = $state; }
            public function load(string $runId): ?RunState { return null; }
            public function list(int $limit = 20): array { return []; }
            public function exists(string $runId): bool { return false; }
            public function delete(string $runId): bool { return true; }
        };

        $engine->setCheckpointer($checkpointer);
        $this->assertSame($checkpointer, $engine->getCheckpointer());

        $loggedSteps = [];
        $engine->onStep(function (RunState $s, string $node, string $msg) use (&$loggedSteps) {
            $loggedSteps[] = ['node' => $node, 'msg' => $msg];
        });

        $engine->addNode($this->createMockNode('step_a'));
        $engine->addNode($this->createMockNode('step_b'));
        $engine->addEdge('step_a', 'step_b');
        $engine->addEdge('step_b', GraphEngine::TERMINAL_COMPLETED);

        $state = RunState::initial('run_listen', 'Listener test');
        $state = $state->withCurrentNode('step_a');

        $finalState = $engine->run($state);

        $this->assertTrue($finalState->isCompleted());
        $this->assertNotEmpty($loggedSteps);
        $this->assertNotEmpty($savedStates);
    }
}
