<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\LLM\Drivers;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Yousef\LaraOrcest\Contracts\LlmDriverContract;
use Yousef\LaraOrcest\Support\Config;

class OpenAiDriver implements LlmDriverContract
{
    public function __construct(
        protected ?string $apiKey = null,
        protected string $model = 'gpt-4o',
        protected string $baseUrl = 'https://api.openai.com/v1',
        protected int $timeout = 60
    ) {
        $this->apiKey = $apiKey ?: (string) Config::get('lara-orcest.llm.providers.openai.api_key', '');
        $this->model = (string) Config::get('lara-orcest.llm.providers.openai.model', $this->model);
        $this->baseUrl = (string) Config::get('lara-orcest.llm.providers.openai.base_url', $this->baseUrl);
        $this->timeout = (int) Config::get('lara-orcest.llm.timeout', $this->timeout);
    }

    public function getName(): string
    {
        return 'openai';
    }

    public function chat(array $messages, array $options = []): array
    {
        $this->ensureApiKey();

        $payload = [
            'model' => $options['model'] ?? $this->model,
            'messages' => $messages,
            'temperature' => $options['temperature'] ?? 0.2,
        ];

        $response = Http::withToken($this->apiKey)
            ->timeout($this->timeout)
            ->post(rtrim($this->baseUrl, '/') . '/chat/completions', $payload);

        if (! $response->successful()) {
            throw new RuntimeException("OpenAI API request failed: [{$response->status()}] " . $response->body());
        }

        $json = $response->json();
        $content = (string) ($json['choices'][0]['message']['content'] ?? '');
        $usage = $json['usage'] ?? ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];

        return [
            'content' => $content,
            'usage' => [
                'prompt_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
                'completion_tokens' => (int) ($usage['completion_tokens'] ?? 0),
                'total_tokens' => (int) ($usage['total_tokens'] ?? 0),
            ],
        ];
    }

    public function chatStructured(array $messages, array $schema, array $options = []): array
    {
        $this->ensureApiKey();

        $payload = [
            'model' => $options['model'] ?? $this->model,
            'messages' => $messages,
            'temperature' => $options['temperature'] ?? 0.1,
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'structured_response',
                    'strict' => false,
                    'schema' => $schema,
                ],
            ],
        ];

        $response = Http::withToken($this->apiKey)
            ->timeout($this->timeout)
            ->post(rtrim($this->baseUrl, '/') . '/chat/completions', $payload);

        if (! $response->successful()) {
            // Fallback to json_object if json_schema unsupported on model
            $payload['response_format'] = ['type' => 'json_object'];
            $response = Http::withToken($this->apiKey)
                ->timeout($this->timeout)
                ->post(rtrim($this->baseUrl, '/') . '/chat/completions', $payload);
        }

        if (! $response->successful()) {
            throw new RuntimeException("OpenAI structured request failed: [{$response->status()}] " . $response->body());
        }

        $json = $response->json();
        $raw = (string) ($json['choices'][0]['message']['content'] ?? '{}');
        $data = json_decode($raw, true);

        if (! is_array($data)) {
            throw new RuntimeException("OpenAI returned non-JSON structured response: {$raw}");
        }

        $usage = $json['usage'] ?? ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];

        return [
            'data' => $data,
            'raw' => $raw,
            'usage' => [
                'prompt_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
                'completion_tokens' => (int) ($usage['completion_tokens'] ?? 0),
                'total_tokens' => (int) ($usage['total_tokens'] ?? 0),
            ],
        ];
    }

    protected function ensureApiKey(): void
    {
        if (empty($this->apiKey)) {
            throw new RuntimeException('OpenAI API key is missing. Set OPENAI_API_KEY in your .env or configuration.');
        }
    }
}
