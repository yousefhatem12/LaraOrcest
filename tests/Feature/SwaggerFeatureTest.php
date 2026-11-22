<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Yousef\LaraOrcest\Agents\SwaggerAgent;
use Yousef\LaraOrcest\Facades\Orcest;
use Yousef\LaraOrcest\Graph\DefaultGraphBuilder;
use Yousef\LaraOrcest\LLM\Drivers\FakeDriver;
use Yousef\LaraOrcest\LLM\Drivers\OpenAiCompatibleHttpDriver;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\State\StateCheckpointer;
use Yousef\LaraOrcest\Tests\TestCase;
use Yousef\LaraOrcest\Tools\FileReaderTool;
use Yousef\LaraOrcest\Tools\PatchWriterTool;
use Yousef\LaraOrcest\Tools\Support\PathGuard;

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
        $this->assertContains('GET /api/tasks', $result->getSwaggerEndpoints());
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
        $this->assertNotNull($state->getSwaggerSummary());
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

    public function test_orchestrator_conditionally_activates_swagger_when_endpoints_exist_and_conditional_enabled(): void
    {
        config(['lara-orcest.swagger.conditional_activation' => true]);

        $visitedNodes = [];

        // Prompt has API intent, fake driver creates API routes and controllers
        $state = Orcest::run('Create customers REST API endpoints', [
            'dry_run' => true,
            'on_step' => function (RunState $s, string $node) use (&$visitedNodes) {
                $visitedNodes[] = $node;
            },
        ]);

        $this->assertTrue($state->isCompleted());
        $this->assertContains('swagger_agent', $visitedNodes);
        $this->assertTrue($state->hasSwaggerDocs());

        config(['lara-orcest.swagger.conditional_activation' => false]);
    }

    public function test_orchestrator_bypasses_swagger_agent_for_standard_non_api_run(): void
    {
        $visitedNodes = [];

        $state = Orcest::run('Create a clean queue worker job for maintenance', [
            'dry_run' => true,
            'on_step' => function (RunState $s, string $node) use (&$visitedNodes) {
                $visitedNodes[] = $node;
            },
        ]);

        $this->assertTrue($state->isCompleted());
        $this->assertNotContains('swagger_agent', $visitedNodes);
        $this->assertFalse($state->hasSwaggerDocs());
    }

    public function test_per_agent_model_routing_for_swagger_agent(): void
    {
        config([
            'lara-orcest.llm.agent_models.swagger_agent' => 'anthropic/claude-3-5-sonnet',
            'lara-orcest.llm.drivers.openai_compatible.model' => 'openai/gpt-4o-mini',
        ]);

        Http::fake([
            'http://localhost:4000/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'openapi_spec' => [
                                    'openapi' => '3.0.3',
                                    'info' => ['title' => 'Per Agent Model API', 'version' => '1.0.0'],
                                    'paths' => ['/api/routed' => ['get' => ['summary' => 'Routed']]],
                                ],
                                'files' => [
                                    [
                                        'path' => 'docs/swagger.json',
                                        'content' => '{"openapi":"3.0.3"}',
                                        'summary' => 'Routed swagger file',
                                    ],
                                ],
                                'endpoints_documented' => ['GET /api/routed'],
                                'summary' => 'Swagger generated via Claude override',
                            ]),
                        ],
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 150,
                    'completion_tokens' => 75,
                ],
            ], 200),
        ]);

        $driver = new OpenAiCompatibleHttpDriver(
            baseUrl: 'http://localhost:4000/v1',
            apiKey: 'sk-litellm-proxy'
        );

        $state = RunState::create('Generate swagger API', [
            'run_id' => 'run_swagger_routed',
            'dry_run' => true,
        ]);
        $state = $state->withCurrentNode('swagger_agent');

        $swaggerAgent = new SwaggerAgent();
        $tempDir = sys_get_temp_dir() . '/test_swagger_routing_' . bin2hex(random_bytes(4));
        mkdir($tempDir, 0755, true);

        $tools = [
            'file_reader' => new FileReaderTool(new PathGuard($tempDir)),
            'patch_writer' => new PatchWriterTool(new PathGuard($tempDir)),
        ];

        $updatedState = $swaggerAgent->execute($state, $tools, $driver);

        $this->assertTrue($updatedState->hasSwaggerDocs());

        Http::assertSent(function ($request) {
            $data = $request->data();
            $modelIsOverridden = ($data['model'] ?? '') === 'anthropic/claude-3-5-sonnet';
            $agentKeyHeader = $request->hasHeader('X-Agent-Key', 'swagger_agent');
            $metadataMatches = ($data['metadata']['agent_key'] ?? '') === 'swagger_agent';

            return $modelIsOverridden && $agentKeyHeader && $metadataMatches;
        });

        @rmdir($tempDir);
    }
}
