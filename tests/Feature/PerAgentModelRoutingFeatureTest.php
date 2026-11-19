<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Yousef\LaraOrcest\Agents\ArchitectAgent;
use Yousef\LaraOrcest\Agents\BackendAgent;
use Yousef\LaraOrcest\LLM\Drivers\OpenAiCompatibleHttpDriver;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Tests\TestCase;
use Yousef\LaraOrcest\Tools\FileReaderTool;
use Yousef\LaraOrcest\Tools\PatchWriterTool;
use Yousef\LaraOrcest\Tools\Support\PathGuard;

class PerAgentModelRoutingFeatureTest extends TestCase
{
    public function test_per_agent_model_routing_and_observability_metadata(): void
    {
        // Configure specific model for architect, leaving backend_agent to default
        config([
            'lara-orcest.llm.agent_models.architect' => 'anthropic/claude-3-5-sonnet',
            'lara-orcest.llm.drivers.openai_compatible.model' => 'openai/gpt-4o-mini',
        ]);

        Http::fake([
            'http://localhost:4000/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'migrations' => ['create_users_table' => 'database/migrations/01.php'],
                                'models' => ['User' => 'app/Models/User.php'],
                                'controllers' => ['UserController' => 'app/Http/Controllers/UserController.php'],
                                'execution_steps' => ['Step 1'],
                            ]),
                        ],
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 120,
                    'completion_tokens' => 45,
                ],
            ], 200),
        ]);

        $driver = new OpenAiCompatibleHttpDriver(
            baseUrl: 'http://localhost:4000/v1',
            apiKey: 'sk-litellm-proxy'
        );

        $state = RunState::create('Design user system', ['run_id' => 'run_tracer_999']);
        $state = $state->withCurrentNode('architect');

        $architect = new ArchitectAgent();
        $tempDir = sys_get_temp_dir() . '/test_per_agent_' . bin2hex(random_bytes(4));
        mkdir($tempDir, 0755, true);

        $tools = [
            'file_reader' => new FileReaderTool(new PathGuard($tempDir)),
        ];

        $updatedState = $architect->execute($state, $tools, $driver);

        // Verify RunState token usage updated
        $this->assertEquals(120, $updatedState->guardrails['token_usage']['prompt_tokens']);
        $this->assertEquals(45, $updatedState->guardrails['token_usage']['completion_tokens']);
        $this->assertEquals(165, $updatedState->guardrails['token_usage']['total_tokens']);

        // Verify HTTP request used overridden model and tracing headers
        Http::assertSent(function ($request) {
            $data = $request->data();
            $modelIsOverridden = ($data['model'] ?? '') === 'anthropic/claude-3-5-sonnet';
            $runIdHeader = $request->hasHeader('X-Run-Id', 'run_tracer_999');
            $agentKeyHeader = $request->hasHeader('X-Agent-Key', 'architect');
            $metadataMatches = ($data['metadata']['agent_key'] ?? '') === 'architect'
                && ($data['metadata']['run_id'] ?? '') === 'run_tracer_999';

            return $modelIsOverridden && $runIdHeader && $agentKeyHeader && $metadataMatches;
        });

        // Clean up
        @rmdir($tempDir);
    }
}
