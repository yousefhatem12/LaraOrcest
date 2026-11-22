<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Feature;

use Yousef\LaraOrcest\Agents\SwaggerAgent;
use Yousef\LaraOrcest\Facades\Orcest;
use Yousef\LaraOrcest\Graph\DefaultGraphBuilder;
use Yousef\LaraOrcest\LLM\Drivers\FakeDriver;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\State\StateCheckpointer;
use Yousef\LaraOrcest\Tests\TestCase;

class SwaggerFeatureTest extends TestCase
{
    public function test_swagger_agent_is_bound_in_container(): void
    {
        $this->assertTrue($this->app->bound(SwaggerAgent::class));
        $this->assertInstanceOf(SwaggerAgent::class, $this->app->make(SwaggerAgent::class));
    }

    public function test_standalone_swagger_pipeline_executes_backend_and_swagger_agent(): void
    {
        $fakeLlm = new FakeDriver();
        $checkpointer = new StateCheckpointer();

        $engine = DefaultGraphBuilder::buildSwaggerPipeline($fakeLlm, $checkpointer);

        $state = RunState::create('Create task management API with swagger documentation', [
            'dry_run' => true,
        ]);

        $result = $engine->run($state);

        $this->assertTrue($result->isCompleted());
        $this->assertTrue($result->hasSwaggerDocs());

        $spec = $result->getSwaggerSpec();
        $this->assertSame('3.0.3', $spec['openapi']);
        $this->assertArrayHasKey('/api/tasks', $spec['paths']);
    }

    public function test_orchestrator_routes_through_swagger_agent_when_prompt_requests_swagger(): void
    {
        $visitedNodes = [];

        $state = Orcest::run('Implement customer API and make swagger documentation for backend', [
            'dry_run' => true,
            'on_step' => function (RunState $s, string $node) use (&$visitedNodes) {
                $visitedNodes[] = $node;
            },
        ]);

        $this->assertTrue($state->isCompleted());
        $this->assertContains('swagger_agent', $visitedNodes);
        $this->assertTrue($state->hasSwaggerDocs());
    }

    public function test_orchestrator_routes_through_swagger_agent_with_explicit_option(): void
    {
        $visitedNodes = [];

        $state = Orcest::run('Implement products API', [
            'dry_run' => true,
            'swagger' => true,
            'on_step' => function (RunState $s, string $node) use (&$visitedNodes) {
                $visitedNodes[] = $node;
            },
        ]);

        $this->assertTrue($state->isCompleted());
        $this->assertContains('swagger_agent', $visitedNodes);
        $this->assertTrue($state->hasSwaggerDocs());
    }

    public function test_orchestrator_bypasses_swagger_agent_when_no_swagger_specified(): void
    {
        $visitedNodes = [];

        $state = Orcest::run('Implement products API', [
            'dry_run' => true,
            'no_swagger' => true,
            'on_step' => function (RunState $s, string $node) use (&$visitedNodes) {
                $visitedNodes[] = $node;
            },
        ]);

        $this->assertTrue($state->isCompleted());
        $this->assertNotContains('swagger_agent', $visitedNodes);
        $this->assertFalse($state->hasSwaggerDocs());
    }
}
