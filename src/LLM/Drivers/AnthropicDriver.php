<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\LLM\Drivers;

use RuntimeException;
use Yousef\LaraOrcest\Contracts\LlmDriverContract;
use Yousef\LaraOrcest\Support\Config;
use Yousef\LaraOrcest\Support\HttpClient;

class AnthropicDriver implements LlmDriverContract
{
    public function __construct(
        protected ?string $apiKey = null,
        protected string $model = 'claude-3-5-sonnet-20241022',
        protected string $baseUrl = 'https://api.anthropic.com/v1',
        protected int $timeout = 60
    ) {
        $this->apiKey = $apiKey ?: (string) Config::get('lara-orcest.llm.providers.anthropic.api_key', '');
        $this->model = (string) Config::get('lara-orcest.llm.providers.anthropic.model', $this->model);
        $this->baseUrl = (string) Config::get('lara-orcest.llm.providers.anthropic.base_url', $this->baseUrl);
        $this->timeout = (int) Config::get('lara-orcest.llm.timeout', $this->timeout);
    }

    public function getName(): string
    {
        return 'anthropic';
    }

    public function chat(array $messages, array $options = []): array
    {
        $this->ensureApiKey();

        $systemPrompt = '';
        $claudeMessages = [];

        foreach ($messages as $msg) {
            if (($msg['role'] ?? '') === 'system') {
                $systemPrompt .= ($systemPrompt !== '' ? "\n\n" : '') . (string) $msg['content'];
            } else {
                $claudeMessages[] = [
                    'role' => $msg['role'] === 'user' ? 'user' : 'assistant',
                    'content' => (string) $msg['content'],
                ];
            }
        }

        $payload = [
            'model' => $options['model'] ?? $this->model,
            'max_tokens' => (int) ($options['max_tokens'] ?? 4096),
            'messages' => $claudeMessages,
        ];

        if ($systemPrompt !== '') {
            $payload['system'] = $systemPrompt;
        }

        $response = HttpClient::factory()->withHeaders([
            'x-api-key' => $this->apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])->timeout($this->timeout)->post(rtrim($this->baseUrl, '/') . '/messages', $payload);

        if (! $response->successful()) {
            throw new RuntimeException("Anthropic API request failed: [{$response->status()}] " . $response->body());
        }

        $json = $response->json();
        $content = (string) ($json['content'][0]['text'] ?? '');
        $usage = $json['usage'] ?? [];

        return [
            'content' => $content,
            'usage' => [
                'prompt_tokens' => (int) ($usage['input_tokens'] ?? 0),
                'completion_tokens' => (int) ($usage['output_tokens'] ?? 0),
                'total_tokens' => (int) (($usage['input_tokens'] ?? 0) + ($usage['output_tokens'] ?? 0)),
            ],
        ];
    }

    public function chatStructured(array $messages, array $schema, array $options = []): array
    {
        // Add JSON requirement to system or user prompt
        $schemaJson = json_encode($schema, JSON_PRETTY_PRINT);
        $enhancedMessages = $messages;
        $instruction = "\n\nCRITICAL: Respond ONLY with valid JSON conforming to this JSON Schema:\n{$schemaJson}\nNo markdown formatting, no preamble, output only raw JSON.";

        $lastIdx = array_key_last($enhancedMessages);
        if ($lastIdx !== null) {
            $enhancedMessages[$lastIdx]['content'] .= $instruction;
        }

        $res = $this->chat($enhancedMessages, $options);
        $raw = trim($res['content']);

        // Remove possible markdown ```json ... ``` wrapper
        if (str_starts_with($raw, '```')) {
            $raw = preg_replace('/^```(?:json)?\n?|\n?```$/i', '', $raw) ?? $raw;
            $raw = trim($raw);
        }

        $data = json_decode($raw, true);
        if (! is_array($data)) {
            throw new RuntimeException("Anthropic returned non-JSON structured response: {$raw}");
        }

        return [
            'data' => $data,
            'raw' => $raw,
            'usage' => $res['usage'],
        ];
    }

    protected function ensureApiKey(): void
    {
        if (empty($this->apiKey)) {
            throw new RuntimeException('Anthropic API key is missing. Set ANTHROPIC_API_KEY in your .env or configuration.');
        }
    }
}
