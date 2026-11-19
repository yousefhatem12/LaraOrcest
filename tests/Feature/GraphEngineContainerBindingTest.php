<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Feature;

use Yousef\LaraOrcest\Contracts\CheckpointerContract;
use Yousef\LaraOrcest\Graph\GraphEngine;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\State\StateCheckpointer;
use Yousef\LaraOrcest\Tests\TestCase;

class GraphEngineContainerBindingTest extends TestCase
{
    public function test_checkpointer_and_graph_engine_are_bound_in_container(): void
    {
        $this->assertTrue($this->app->bound(StateCheckpointer::class));
        $this->assertTrue($this->app->bound(CheckpointerContract::class));
        $this->assertTrue($this->app->bound(GraphEngine::class));

        // Verify singleton checkpointer
        $checkpointer1 = $this->app->make(StateCheckpointer::class);
        $checkpointer2 = $this->app->make(CheckpointerContract::class);
        $this->assertSame($checkpointer1, $checkpointer2);

        // Verify GraphEngine resolves with 9 built-in nodes
        /** @var GraphEngine $graph */
        $graph = $this->app->make(GraphEngine::class);
        $this->assertInstanceOf(GraphEngine::class, $graph);

        $nodes = $graph->getNodes();
        $this->assertCount(9, $nodes);
        $this->assertTrue($graph->hasNode('business_analyst'));
        $this->assertTrue($graph->hasNode('product_manager'));
        $this->assertTrue($graph->hasNode('architect'));
        $this->assertTrue($graph->hasNode('git_agent'));
        $this->assertTrue($graph->hasNode('database_agent'));
        $this->assertTrue($graph->hasNode('backend_agent'));
        $this->assertTrue($graph->hasNode('qa_agent'));
        $this->assertTrue($graph->hasNode('fixer_agent'));
        $this->assertTrue($graph->hasNode('reviewer_agent'));
    }

    public function test_graph_engine_executes_dry_run_from_container(): void
    {
        /** @var GraphEngine $graph */
        $graph = $this->app->make(GraphEngine::class);

        $state = RunState::initial('run_container_dry', 'Build user profile API', [
            'dry_run' => true,
        ]);

        $finalState = $graph->run($state);

        $this->assertTrue($finalState->isCompleted());
        $this->assertTrue($finalState->isReviewApproved());
        $this->assertNotEmpty($finalState->artifacts);
    }
}
