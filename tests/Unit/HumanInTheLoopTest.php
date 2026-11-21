<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Yousef\LaraOrcest\Graph\DefaultGraphBuilder;
use Yousef\LaraOrcest\LLM\Drivers\FakeDriver;
use Yousef\LaraOrcest\Orchestrator;
use Yousef\LaraOrcest\State\Drivers\JsonCheckpointer;
use Yousef\LaraOrcest\State\RunState;

class HumanInTheLoopTest extends TestCase
{
    protected string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/lara_orcest_hitl_test_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tempDir . '/*');
        if ($files) {
            foreach ($files as $f) {
                @unlink($f);
            }
        }
        @rmdir($this->tempDir);
    }

    public function test_run_state_hitl_flags_and_pause_helpers(): void
    {
        // 1. Default state: HITL not enabled
        $state = RunState::create('Build billing');
        $this->assertFalse($state->isHumanReviewEnabled());
        $this->assertFalse($state->isHumanReviewSkipped());
        $this->assertFalse($state->shouldPauseAtGate('after_plan'));

        // 2. Interactive state: HITL enabled
        $interactiveState = RunState::create('Build billing', ['interactive' => true]);
        $this->assertTrue($interactiveState->isHumanReviewEnabled());
        $this->assertFalse($interactiveState->isHumanReviewSkipped());
        $this->assertTrue($interactiveState->shouldPauseAtGate('after_plan'));
        $this->assertTrue($interactiveState->shouldPauseAtGate('before_commit'));

        // 3. User opt-out via no-human-review completely skips review
        $optOutState = RunState::create('Build billing', [
            'interactive' => true,
            'no_human_review' => true,
        ]);
        $this->assertFalse($optOutState->isHumanReviewEnabled());
        $this->assertTrue($optOutState->isHumanReviewSkipped());
        $this->assertFalse($optOutState->shouldPauseAtGate('after_plan'));
        $this->assertFalse($optOutState->shouldPauseAtGate('before_commit'));

        // 4. Pausing at gate records paused status and context
        $pausedState = $interactiveState->pauseAtGate('after_plan', ['summary' => 'Plan ready']);
        $this->assertTrue($pausedState->isPaused());
        $this->assertEquals('after_plan', $pausedState->getPausedGate());
        $this->assertEquals(['summary' => 'Plan ready'], $pausedState->getPauseContext());

        // 5. Recording human approval resumes state and records review note
        $approvedState = $pausedState->recordHumanDecision('after_plan', 'approved', 'Looks solid');
        $this->assertTrue($approvedState->isRunning());
        $this->assertNull($approvedState->getPausedGate());
        $this->assertTrue($approvedState->isGateDecided('after_plan'));
        $this->assertFalse($approvedState->shouldPauseAtGate('after_plan')); // Won't pause again
        $this->assertTrue($approvedState->shouldPauseAtGate('before_commit')); // Subsequent gate still active

        $decisions = $approvedState->getHumanDecisions();
        $this->assertCount(1, $decisions);
        $this->assertEquals('after_plan', $decisions[0]['gate']);
        $this->assertEquals('approved', $decisions[0]['decision']);
        $this->assertEquals('Looks solid', $decisions[0]['notes']);

        // Reviews trail updated
        $latestReview = $approvedState->getLatestReview();
        $this->assertNotNull($latestReview);
        $this->assertEquals('approved', $latestReview['decision']);
        $this->assertContains('Looks solid', $latestReview['findings']);
    }

    public function test_graph_pauses_at_after_plan_gate_when_interactive(): void
    {
        $driver = new FakeDriver();
        $checkpointer = new JsonCheckpointer($this->tempDir);

        $graph = DefaultGraphBuilder::build(
            llm: $driver,
            checkpointer: $checkpointer
        );

        // Run with interactive mode enabled
        $state = RunState::create('Build invoice API', [
            'interactive' => true,
            'dry_run' => true,
        ]);

        $pausedState = $graph->run($state);

        // Should pause after architect planning
        $this->assertTrue($pausedState->isPaused());
        $this->assertEquals('after_plan', $pausedState->getPausedGate());
        $this->assertEquals('git_agent', $pausedState->currentNode); // Next node ready
        $this->assertNotEmpty($pausedState->technicalPlan);
        $this->assertNotEmpty($pausedState->backlog);

        // Checkpoint should be saved on disk
        $loaded = $checkpointer->load($pausedState->runId);
        $this->assertNotNull($loaded);
        $this->assertTrue($loaded->isPaused());
        $this->assertEquals('after_plan', $loaded->getPausedGate());
    }

    public function test_resuming_with_approval_completes_the_pipeline(): void
    {
        $driver = new FakeDriver();
        $checkpointer = new JsonCheckpointer($this->tempDir);

        $graph = DefaultGraphBuilder::build(
            llm: $driver,
            checkpointer: $checkpointer
        );

        // Enable only after_plan gate for targeted test
        $state = RunState::create('Build invoice API', [
            'interactive' => true,
            'gates' => ['after_plan' => true, 'before_commit' => false],
            'dry_run' => true,
        ]);

        $pausedState = $graph->run($state);
        $this->assertTrue($pausedState->isPaused());

        // Approve after_plan
        $resumedState = $pausedState->recordHumanDecision('after_plan', 'approved', 'Plan approved by lead');
        $checkpointer->save($resumedState);

        $finalState = $graph->run($resumedState);

        $this->assertTrue($finalState->isCompleted());
        $this->assertNotEmpty($finalState->artifacts);
        $this->assertTrue($finalState->isGateDecided('after_plan'));
    }

    public function test_resuming_with_rejection_fails_cleanly(): void
    {
        $driver = new FakeDriver();
        $checkpointer = new JsonCheckpointer($this->tempDir);

        $graph = DefaultGraphBuilder::build(
            llm: $driver,
            checkpointer: $checkpointer
        );

        $state = RunState::create('Build invoice API', [
            'interactive' => true,
            'dry_run' => true,
        ]);

        $pausedState = $graph->run($state);
        $this->assertTrue($pausedState->isPaused());

        // Reject via Orchestrator
        $orchestrator = new Orchestrator(checkpointer: $checkpointer);
        $rejectedState = $orchestrator->reject($pausedState->runId, 'Missing multi-tenant isolation');

        $this->assertTrue($rejectedState->isFailed());
        $this->assertStringContainsString('Human reviewer rejected run at gate [after_plan]', $rejectedState->getFailureReason());
        $this->assertStringContainsString('Missing multi-tenant isolation', $rejectedState->getFailureReason());

        // Verify persisted state
        $loaded = $checkpointer->load($pausedState->runId);
        $this->assertNotNull($loaded);
        $this->assertTrue($loaded->isFailed());
    }

    public function test_no_human_review_bypasses_all_review_gates(): void
    {
        $driver = new FakeDriver();
        $checkpointer = new JsonCheckpointer($this->tempDir);

        $graph = DefaultGraphBuilder::build(
            llm: $driver,
            checkpointer: $checkpointer
        );

        // User explicitly disables review via no_human_review
        $state = RunState::create('Build invoice API', [
            'interactive' => true,
            'no_human_review' => true,
            'dry_run' => true,
        ]);

        $finalState = $graph->run($state);

        // Should NOT pause at after_plan or before_commit
        $this->assertTrue($finalState->isCompleted());
        $this->assertNull($finalState->getPausedGate());
        $this->assertEmpty($finalState->getHumanDecisions());
    }

    public function test_before_commit_gate_pauses_before_final_commit(): void
    {
        $driver = new FakeDriver();
        $checkpointer = new JsonCheckpointer($this->tempDir);

        $graph = DefaultGraphBuilder::build(
            llm: $driver,
            checkpointer: $checkpointer
        );

        // Enable only before_commit gate
        $state = RunState::create('Build invoice API', [
            'interactive' => true,
            'gates' => ['after_plan' => false, 'before_commit' => true],
            'dry_run' => true,
        ]);

        $pausedState = $graph->run($state);

        // Should pause right before final completion
        $this->assertTrue($pausedState->isPaused());
        $this->assertEquals('before_commit', $pausedState->getPausedGate());
        $this->assertNotEmpty($pausedState->artifacts);
        $this->assertTrue($pausedState->areTestsPassing());

        // Approve before_commit via Orchestrator
        $orchestrator = new Orchestrator(checkpointer: $checkpointer);
        $completedState = $orchestrator->approve($pausedState->runId, 'Ready to commit');

        $this->assertTrue($completedState->isCompleted());
        $this->assertTrue($completedState->isGateDecided('before_commit'));
    }
}
