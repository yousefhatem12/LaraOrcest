<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Yousef\LaraOrcest\Graph\DefaultGraphBuilder;
use Yousef\LaraOrcest\LLM\Drivers\FakeDriver;
use Yousef\LaraOrcest\State\Drivers\JsonCheckpointer;
use Yousef\LaraOrcest\State\RunState;

class GraphEngineTest extends TestCase
{
    protected string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/lara_orcest_graph_test_' . bin2hex(random_bytes(4));
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

    public function test_dry_run_completes_full_pipeline_without_errors(): void
    {
        $driver = new FakeDriver();
        $checkpointer = new JsonCheckpointer($this->tempDir);

        $graph = DefaultGraphBuilder::build(
            llm: $driver,
            checkpointer: $checkpointer
        );

        $state = RunState::create('Create a Task API', ['dry_run' => true]);
        $finalState = $graph->run($state);

        $this->assertEquals(RunState::STATUS_COMPLETED, $finalState->status);
        $this->assertNotEmpty($finalState->requirementsSpec);
        $this->assertNotEmpty($finalState->backlog);
        $this->assertNotEmpty($finalState->technicalPlan);
        $this->assertNotEmpty($finalState->artifacts);
        $this->assertTrue($finalState->isReviewApproved());
        $this->assertGreaterThan(5, $finalState->guardrails['step_count']);
    }

    public function test_loop_terminates_when_step_limit_exceeded(): void
    {
        $driver = new FakeDriver();
        $checkpointer = new JsonCheckpointer($this->tempDir);

        $graph = DefaultGraphBuilder::build(
            llm: $driver,
            checkpointer: $checkpointer
        );

        // Cap at 3 steps
        $state = RunState::create('Create a Task API', ['max_steps' => 3, 'dry_run' => true]);
        $finalState = $graph->run($state);

        $this->assertEquals(RunState::STATUS_FAILED, $finalState->status);
        $this->assertEquals(3, $finalState->guardrails['step_count']);
    }

    public function test_checkpointer_saves_and_restores(): void
    {
        $checkpointer = new JsonCheckpointer($this->tempDir);
        $state = RunState::create('Test Checkpointer', ['dry_run' => true]);
        $state = $state->withCurrentNode('backend_agent');

        $checkpointer->save($state);

        $loaded = $checkpointer->load($state->runId);
        $this->assertNotNull($loaded);
        $this->assertEquals($state->runId, $loaded->runId);
        $this->assertEquals('backend_agent', $loaded->currentNode);

        $list = $checkpointer->list();
        $this->assertCount(1, $list);
        $this->assertEquals($state->runId, $list[0]['run_id']);
    }
}
