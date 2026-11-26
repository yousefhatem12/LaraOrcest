<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\LLM\Drivers;

use RuntimeException;
use Yousef\LaraOrcest\Contracts\LlmDriverContract;
use Yousef\LaraOrcest\Support\Config;
use Yousef\LaraOrcest\Support\HttpClient;

class GeminiDriver implements LlmDriverContract
{
    public function __construct(
        protected ?string $apiKey = null,
        protected string $model = 'gemini-2.0-flash',
        protected string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta',
        protected int $timeout = 60
    ) {
        $this->apiKey = $apiKey ?: (string) Config::get('lara-orcest.llm.providers.gemini.api_key', '');
        $this->model = (string) Config::get('lara-orcest.llm.providers.gemini.model', $this->model);
        $this->baseUrl = (string) Config::get('lara-orcest.llm.providers.gemini.base_url', $this->baseUrl);
        $this->timeout = (int) Config::get('lara-orcest.llm.timeout', $this->timeout);
    }

    public function getName(): string
    {
        return 'gemini';
    }

    public function chat(array $messages, array $options = []): array
    {
        $this->ensureApiKey();

        $contents = [];
        $systemInstruction = null;

        foreach ($messages as $msg) {
            $role = ($msg['role'] ?? '') === 'system' ? 'system' : (($msg['role'] ?? '') === 'user' ? 'user' : 'model');
            if ($role === 'system') {
                $systemInstruction = ['parts' => [['text' => (string) $msg['content']]]];
            } else {
                $contents[] = [
                    'role' => $role,
                    'parts' => [['text' => (string) $msg['content']]],
                ];
            }
        }

        $payload = ['contents' => $contents];
        if ($systemInstruction !== null) {
            $payload['systemInstruction'] = $systemInstruction;
        }

        $modelName = $options['model'] ?? $this->model;
        $url = rtrim($this->baseUrl, '/') . "/models/{$modelName}:generateContent?key={$this->apiKey}";

        $response = HttpClient::factory()->timeout($this->timeout)->post($url, $payload);

        if (! $response->successful()) {
            throw new RuntimeException("Gemini API request failed: [{$response->status()}] " . $response->body());
        }

        $json = $response->json();
        $content = (string) ($json['candidates'][0]['content']['parts'][0]['text'] ?? '');
        $usage = $json['usageMetadata'] ?? [];

        return [
            'content' => $content,
            'usage' => [
                'prompt_tokens' => (int) ($usage['promptTokenCount'] ?? 0),
                'completion_tokens' => (int) ($usage['candidatesTokenCount'] ?? 0),
                'total_tokens' => (int) ($usage['totalTokenCount'] ?? 0),
            ],
        ];
    }

    public function chatStructured(array $messages, array $schema, array $options = []): array
    {
        $this->ensureApiKey();

        $contents = [];
        $systemInstruction = null;

        foreach ($messages as $msg) {
            $role = ($msg['role'] ?? '') === 'system' ? 'system' : (($msg['role'] ?? '') === 'user' ? 'user' : 'model');
            if ($role === 'system') {
                $systemInstruction = ['parts' => [['text' => (string) $msg['content']]]];
            } else {
                $contents[] = [
                    'role' => $role,
                    'parts' => [['text' => (string) $msg['content']]],
                ];
            }
        }

        $payload = [
            'contents' => $contents,
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'responseSchema' => $schema,
            ],
        ];

        if ($systemInstruction !== null) {
            $payload['systemInstruction'] = $systemInstruction;
        }

        $modelName = $options['model'] ?? $this->model;
        $url = rtrim($this->baseUrl, '/') . "/models/{$modelName}:generateContent?key={$this->apiKey}";

        $response = HttpClient::factory()->timeout($this->timeout)->post($url, $payload);

        if (! $response->successful()) {
            throw new RuntimeException("Gemini structured request failed: [{$response->status()}] " . $response->body());
        }

        $json = $response->json();
        $raw = (string) ($json['candidates'][0]['content']['parts'][0]['text'] ?? '{}');
        $data = json_decode($raw, true);

        if (! is_array($data)) {
            throw new RuntimeException("Gemini returned non-JSON response: {$raw}");
        }

        $usage = $json['usageMetadata'] ?? [];

        return [
            'data' => $data,
            'raw' => $raw,
            'usage' => [
                'prompt_tokens' => (int) ($usage['promptTokenCount'] ?? 0),
                'completion_tokens' => (int) ($usage['candidatesTokenCount'] ?? 0),
                'total_tokens' => (int) ($usage['totalTokenCount'] ?? 0),
            ],
        ];
    }

    protected function ensureApiKey(): void
    {
        if (empty($this->apiKey)) {
            throw new RuntimeException('Gemini API key is missing. Set GEMINI_API_KEY in your .env or configuration.');
        }
    }
}
