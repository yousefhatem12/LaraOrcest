<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\LLM\Drivers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Yousef\LaraOrcest\Contracts\LlmDriverContract;
use Yousef\LaraOrcest\Support\Config;

class OpenAiCompatibleHttpDriver implements LlmDriverContract
{
    public function __construct(
        protected ?string $baseUrl = null,
        protected ?string $apiKey = null,
        protected string $model = 'gpt-4o-mini',
        protected int $timeout = 60,
        protected int $retries = 2,
        protected int $retrySleep = 100
    ) {
        $this->baseUrl = $baseUrl ?: (string) Config::get(
            'lara-orcest.llm.drivers.openai_compatible.base_url',
            Config::get('lara-orcest.llm.providers.openai.base_url', 'https://api.openai.com/v1')
        );

        $this->apiKey = $apiKey !== null ? $apiKey : (string) Config::get(
            'lara-orcest.llm.drivers.openai_compatible.api_key',
            Config::get('lara-orcest.llm.providers.openai.api_key', '')
        );

        $this->model = (string) Config::get(
            'lara-orcest.llm.drivers.openai_compatible.model',
            Config::get('lara-orcest.llm.providers.openai.model', $this->model)
        );

        $this->timeout = (int) Config::get(
            'lara-orcest.llm.drivers.openai_compatible.timeout',
            Config::get('lara-orcest.llm.timeout', $this->timeout)
        );

        $this->retries = (int) Config::get(
            'lara-orcest.llm.drivers.openai_compatible.retries',
            Config::get('lara-orcest.llm.max_retries', $this->retries)
        );

        $this->retrySleep = (int) Config::get(
            'lara-orcest.llm.drivers.openai_compatible.retry_sleep',
            $this->retrySleep
        );
    }

    public function getName(): string
    {
        return 'openai_compatible';
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function chat(array $messages, array $options = []): array
    {
        $endpoint = $this->getChatCompletionsUrl();
        $model = (string) ($options['model'] ?? $this->model);

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => (float) ($options['temperature'] ?? 0.2),
        ];

        if (isset($options['max_tokens'])) {
            $payload['max_tokens'] = (int) $options['max_tokens'];
        }

        $payload = $this->attachContextMetadata($payload, $options);

        $response = $this->buildClient($options)->post($endpoint, $payload);

        if (! $response->successful()) {
            throw new RuntimeException($this->formatErrorMessage($response, $endpoint));
        }

        $json = $response->json();
        $content = (string) ($json['choices'][0]['message']['content'] ?? '');
        $usage = $this->normalizeUsage($json['usage'] ?? []);

        return [
            'content' => $content,
            'usage' => $usage,
            'metadata' => [
                'model' => (string) ($json['model'] ?? $model),
                'endpoint' => $endpoint,
                'run_id' => $options['run_id'] ?? null,
                'agent_key' => $options['agent_key'] ?? null,
            ],
        ];
    }

    public function chatStructured(array $messages, array $schema, array $options = []): array
    {
        $endpoint = $this->getChatCompletionsUrl();
        $model = (string) ($options['model'] ?? $this->model);

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => (float) ($options['temperature'] ?? 0.1),
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'structured_response',
                    'strict' => false,
                    'schema' => $schema,
                ],
            ],
        ];

        if (isset($options['max_tokens'])) {
            $payload['max_tokens'] = (int) $options['max_tokens'];
        }

        $payload = $this->attachContextMetadata($payload, $options);

        $response = $this->buildClient($options)->post($endpoint, $payload);

        // Fallback for proxies or models that only support json_object mode
        if (! $response->successful()) {
            $payload['response_format'] = ['type' => 'json_object'];
            $response = $this->buildClient($options)->post($endpoint, $payload);
        }

        if (! $response->successful()) {
            throw new RuntimeException($this->formatErrorMessage($response, $endpoint));
        }

        $json = $response->json();
        $raw = (string) ($json['choices'][0]['message']['content'] ?? '{}');
        $cleanedRaw = $this->cleanJsonString($raw);
        $data = json_decode($cleanedRaw, true);

        if (! is_array($data)) {
            throw new RuntimeException("OpenAI-compatible endpoint returned non-JSON response from model [{$model}]: {$raw}");
        }

        $usage = $this->normalizeUsage($json['usage'] ?? []);

        return [
            'data' => $data,
            'raw' => $cleanedRaw,
            'usage' => $usage,
            'metadata' => [
                'model' => (string) ($json['model'] ?? $model),
                'endpoint' => $endpoint,
                'run_id' => $options['run_id'] ?? null,
                'agent_key' => $options['agent_key'] ?? null,
            ],
        ];
    }

    /**
     * Resolves the full chat completions URL based on the provided base_url.
     */
    public function getChatCompletionsUrl(): string
    {
        $base = rtrim($this->baseUrl, '/');

        if (str_ends_with($base, '/chat/completions')) {
            return $base;
        }

        if (str_ends_with($base, '/v1')) {
            return $base . '/chat/completions';
        }

        if (str_contains($base, '/v1/')) {
            return $base . '/chat/completions';
        }

        return $base . '/v1/chat/completions';
    }

    /**
     * Builds the HTTP client with timeouts, retries, headers, and credentials.
     *
     * @param  array<string, mixed>  $options
     */
    protected function buildClient(array $options): PendingRequest
    {
        $timeout = (int) ($options['timeout'] ?? $this->timeout);

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        if (! empty($options['run_id'])) {
            $headers['X-Run-Id'] = (string) $options['run_id'];
        }

        if (! empty($options['agent_key'])) {
            $headers['X-Agent-Key'] = (string) $options['agent_key'];
        }

        if (! empty($options['node_id'])) {
            $headers['X-Node-Id'] = (string) $options['node_id'];
        }

        $client = Http::withHeaders($headers)->timeout($timeout);

        if (! empty($this->apiKey)) {
            $client = $client->withToken($this->apiKey);
        }

        if ($this->retries > 0) {
            $client = $client->retry($this->retries, $this->retrySleep, throw: false);
        }

        return $client;
    }

    /**
     * Injects tracing and observability metadata for proxies like LiteLLM.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected function attachContextMetadata(array $payload, array $options): array
    {
        $metadata = [];

        if (! empty($options['run_id'])) {
            $metadata['run_id'] = (string) $options['run_id'];
        }

        if (! empty($options['agent_key'])) {
            $metadata['agent_key'] = (string) $options['agent_key'];
        }

        if (! empty($options['node_id'])) {
            $metadata['node_id'] = (string) $options['node_id'];
        }

        if (! empty($metadata)) {
            $payload['metadata'] = $metadata;
        }

        return $payload;
    }

    /**
     * Strips Markdown formatting wrappers (e.g. ```json ... ```).
     */
    protected function cleanJsonString(string $raw): string
    {
        $trimmed = trim($raw);

        if (str_starts_with($trimmed, '```')) {
            $trimmed = preg_replace('/^```(?:json)?\s*/i', '', $trimmed) ?? $trimmed;
            $trimmed = preg_replace('/\s*```$/', '', $trimmed) ?? $trimmed;
            $trimmed = trim($trimmed);
        }

        return $trimmed;
    }

    /**
     * Normalizes token usage reporting across different proxies and models.
     *
     * @param  array<string, mixed>  $usage
     * @return array{prompt_tokens: int, completion_tokens: int, total_tokens: int}
     */
    protected function normalizeUsage(array $usage): array
    {
        $prompt = (int) ($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0);
        $completion = (int) ($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0);
        $total = (int) ($usage['total_tokens'] ?? ($prompt + $completion));

        return [
            'prompt_tokens' => max(0, $prompt),
            'completion_tokens' => max(0, $completion),
            'total_tokens' => max(0, $total),
        ];
    }

    /**
     * Formats error output without leaking secrets or API keys.
     */
    protected function formatErrorMessage(Response $response, string $endpoint): string
    {
        $body = $response->body();

        // Redact API key if present in response body or URL
        if (! empty($this->apiKey)) {
            $body = str_replace($this->apiKey, '[REDACTED_API_KEY]', $body);
        }

        $status = $response->status();

        return "OpenAI-compatible request to [{$endpoint}] failed with HTTP {$status}: {$body}";
    }
}
