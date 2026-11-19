<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Unit;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Yousef\LaraOrcest\Agents\ArchitectAgent;
use Yousef\LaraOrcest\Agents\BackendAgent;
use Yousef\LaraOrcest\LLM\Drivers\OpenAiCompatibleHttpDriver;
use Yousef\LaraOrcest\LLM\LlmManager;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Tests\TestCase;

class OpenAiCompatibleHttpDriverTest extends TestCase
{
    public function test_resolves_chat_completions_url_accurately(): void
    {
        $driver1 = new OpenAiCompatibleHttpDriver(baseUrl: 'http://localhost:4000/v1');
        $this->assertEquals('http://localhost:4000/v1/chat/completions', $driver1->getChatCompletionsUrl());

        $driver2 = new OpenAiCompatibleHttpDriver(baseUrl: 'http://localhost:4000');
        $this->assertEquals('http://localhost:4000/v1/chat/completions', $driver2->getChatCompletionsUrl());

        $driver3 = new OpenAiCompatibleHttpDriver(baseUrl: 'http://localhost:4000/chat/completions');
        $this->assertEquals('http://localhost:4000/chat/completions', $driver3->getChatCompletionsUrl());

        $driver4 = new OpenAiCompatibleHttpDriver(baseUrl: 'https://api.openai.com/v1');
        $this->assertEquals('https://api.openai.com/v1/chat/completions', $driver4->getChatCompletionsUrl());
    }

    public function test_sends_chat_request_with_metadata_and_headers(): void
    {
        Http::fake([
            'http://localhost:4000/v1/chat/completions' => Http::response([
                'id' => 'chatcmpl-123',
                'model' => 'gpt-4o-mini',
                'choices' => [
                    [
                        'message' => [
                            'role' => 'assistant',
                            'content' => 'Hello from LiteLLM proxy!',
                        ],
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 25,
                    'completion_tokens' => 10,
                    'total_tokens' => 35,
                ],
            ], 200),
        ]);

        $driver = new OpenAiCompatibleHttpDriver(
            baseUrl: 'http://localhost:4000/v1',
            apiKey: 'sk-litellm-proxy-secret',
            model: 'gpt-4o-mini'
        );

        $result = $driver->chat(
            messages: [['role' => 'user', 'content' => 'Hi']],
            options: [
                'run_id' => 'run_abc123',
                'agent_key' => 'architect',
                'node_id' => 'architect_node',
            ]
        );

        $this->assertEquals('Hello from LiteLLM proxy!', $result['content']);
        $this->assertEquals(25, $result['usage']['prompt_tokens']);
        $this->assertEquals(10, $result['usage']['completion_tokens']);
        $this->assertEquals(35, $result['usage']['total_tokens']);

        Http::assertSent(function ($request) {
            $hasAuth = $request->hasHeader('Authorization', 'Bearer sk-litellm-proxy-secret');
            $hasRunId = $request->hasHeader('X-Run-Id', 'run_abc123');
            $hasAgent = $request->hasHeader('X-Agent-Key', 'architect');
            $data = $request->data();
            $metaMatches = ($data['metadata']['run_id'] ?? '') === 'run_abc123'
                && ($data['metadata']['agent_key'] ?? '') === 'architect';

            return $hasAuth && $hasRunId && $hasAgent && $metaMatches;
        });
    }

    public function test_chat_structured_parses_json_and_strips_markdown_fences(): void
    {
        Http::fake([
            'http://localhost:4000/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'role' => 'assistant',
                            'content' => "```json\n{\"decision\": \"approved\", \"score\": 95}\n```",
                        ],
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 40,
                    'completion_tokens' => 15,
                ],
            ], 200),
        ]);

        $driver = new OpenAiCompatibleHttpDriver(
            baseUrl: 'http://localhost:4000/v1',
            apiKey: 'sk-test'
        );

        $result = $driver->chatStructured(
            messages: [['role' => 'user', 'content' => 'Analyze']],
            schema: ['type' => 'object', 'properties' => ['decision' => ['type' => 'string']]]
        );

        $this->assertIsArray($result['data']);
        $this->assertEquals('approved', $result['data']['decision']);
        $this->assertEquals(95, $result['data']['score']);
        $this->assertEquals(40, $result['usage']['prompt_tokens']);
        $this->assertEquals(15, $result['usage']['completion_tokens']);
        $this->assertEquals(55, $result['usage']['total_tokens']);
    }

    public function test_omits_authorization_header_when_api_key_is_empty(): void
    {
        Http::fake([
            'http://localhost:4000/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => '{}']]],
                'usage' => [],
            ], 200),
        ]);

        $driver = new OpenAiCompatibleHttpDriver(
            baseUrl: 'http://localhost:4000/v1',
            apiKey: ''
        );

        $driver->chatStructured([['role' => 'user', 'content' => 'test']], []);

        Http::assertSent(function ($request) {
            return ! $request->hasHeader('Authorization');
        });
    }

    public function test_redacts_api_key_in_error_message(): void
    {
        Http::fake([
            'http://localhost:4000/v1/chat/completions' => Http::response(
                'Invalid token provided: sk-super-secret-key-12345',
                401
            ),
        ]);

        $driver = new OpenAiCompatibleHttpDriver(
            baseUrl: 'http://localhost:4000/v1',
            apiKey: 'sk-super-secret-key-12345',
            retries: 0
        );

        try {
            $driver->chat([['role' => 'user', 'content' => 'test']]);
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString('sk-super-secret-key-12345', $e->getMessage());
            $this->assertStringContainsString('[REDACTED_API_KEY]', $e->getMessage());
        }
    }

    public function test_llm_manager_resolves_openai_compatible_driver(): void
    {
        $manager = new LlmManager();
        $driver = $manager->driver('openai_compatible');

        $this->assertInstanceOf(OpenAiCompatibleHttpDriver::class, $driver);
        $this->assertEquals('openai_compatible', $driver->getName());
    }
}
