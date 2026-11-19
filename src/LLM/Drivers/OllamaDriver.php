<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\LLM\Drivers;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Yousef\LaraOrcest\Contracts\LlmDriverContract;

class OllamaDriver implements LlmDriverContract
{
    public function __construct(
        protected string $baseUrl = 'http://127.0.0.1:11434',
        protected string $model = 'llama3.3:latest',
        protected int $timeout = 120
    ) {
        $this->baseUrl = function_exists('config') ? (string) config('lara-orcest.llm.providers.ollama.base_url', $this->baseUrl) : $this->baseUrl;
        $this->model = function_exists('config') ? (string) config('lara-orcest.llm.providers.ollama.model', $this->model) : $this->model;
        $this->timeout = function_exists('config') ? (int) config('lara-orcest.llm.timeout', $this->timeout) : $this->timeout;
    }

    public function getName(): string
    {
        return 'ollama';
    }

    public function chat(array $messages, array $options = []): array
    {
        $payload = [
            'model' => $options['model'] ?? $this->model,
            'messages' => $messages,
            'stream' => false,
        ];

        $response = Http::timeout($this->timeout)->post(rtrim($this->baseUrl, '/') . '/api/chat', $payload);

        if (! $response->successful()) {
            throw new RuntimeException("Ollama API request failed: [{$response->status()}] " . $response->body());
        }

        $json = $response->json();
        $content = (string) ($json['message']['content'] ?? '');

        return [
            'content' => $content,
            'usage' => [
                'prompt_tokens' => (int) ($json['prompt_eval_count'] ?? 0),
                'completion_tokens' => (int) ($json['eval_count'] ?? 0),
                'total_tokens' => (int) (($json['prompt_eval_count'] ?? 0) + ($json['eval_count'] ?? 0)),
            ],
        ];
    }

    public function chatStructured(array $messages, array $schema, array $options = []): array
    {
        $payload = [
            'model' => $options['model'] ?? $this->model,
            'messages' => $messages,
            'format' => 'json',
            'stream' => false,
        ];

        $response = Http::timeout($this->timeout)->post(rtrim($this->baseUrl, '/') . '/api/chat', $payload);

        if (! $response->successful()) {
            throw new RuntimeException("Ollama structured request failed: [{$response->status()}] " . $response->body());
        }

        $json = $response->json();
        $raw = (string) ($json['message']['content'] ?? '{}');
        $data = json_decode($raw, true);

        if (! is_array($data)) {
            throw new RuntimeException("Ollama returned non-JSON response: {$raw}");
        }

        return [
            'data' => $data,
            'raw' => $raw,
            'usage' => [
                'prompt_tokens' => (int) ($json['prompt_eval_count'] ?? 0),
                'completion_tokens' => (int) ($json['eval_count'] ?? 0),
                'total_tokens' => (int) (($json['prompt_eval_count'] ?? 0) + ($json['eval_count'] ?? 0)),
            ],
        ];
    }
}
