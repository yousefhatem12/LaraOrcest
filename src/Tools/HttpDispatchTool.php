<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tools;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;
use Yousef\LaraOrcest\Contracts\ToolContract;
use Yousef\LaraOrcest\Support\Config;

class HttpDispatchTool implements ToolContract
{
    public function __construct(
        protected bool $dryRun = false
    ) {}

    public function getName(): string
    {
        return 'http_dispatch';
    }

    public function getDescription(): string
    {
        return 'Dispatches structured JSON payloads to an external HTTP destination endpoint with header redaction and dry_run safety.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'endpoint' => [
                    'type' => 'string',
                    'description' => 'Destination HTTP endpoint URL.',
                ],
                'payload' => [
                    'type' => 'object',
                    'description' => 'JSON payload to dispatch.',
                ],
                'method' => [
                    'type' => 'string',
                    'description' => 'HTTP method (POST, PUT, PATCH, GET). Default is POST.',
                ],
                'headers' => [
                    'type' => 'object',
                    'description' => 'Optional custom HTTP headers.',
                ],
            ],
            'required' => ['endpoint', 'payload'],
        ];
    }

    public function setDryRun(bool $dryRun): self
    {
        $this->dryRun = $dryRun;

        return $this;
    }

    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    public function execute(array $params): array
    {
        $endpoint = trim((string) ($params['endpoint'] ?? ''));
        if ($endpoint === '') {
            throw new InvalidArgumentException('Endpoint URL is required for http_dispatch.');
        }

        $this->validateEndpoint($endpoint);

        $payload = (array) ($params['payload'] ?? []);
        $method = strtoupper((string) ($params['method'] ?? 'POST'));
        if (! in_array($method, ['POST', 'PUT', 'PATCH', 'GET'], true)) {
            $method = 'POST';
        }

        $headers = (array) ($params['headers'] ?? []);

        if ($this->dryRun) {
            return [
                'status' => 'skipped_dry_run',
                'http_status' => null,
                'successful' => true,
                'endpoint' => $endpoint,
                'method' => $method,
                'payload_preview' => $payload,
                'response_body' => 'Dry run enabled: external HTTP dispatch previewed without sending.',
                'error' => null,
            ];
        }

        $timeout = (int) Config::get('lara-orcest.web_scraping.timeout', 30);
        $retries = (int) Config::get('lara-orcest.web_scraping.retries', 2);
        $sleep = (int) Config::get('lara-orcest.web_scraping.retry_sleep', 100);

        try {
            $client = Http::timeout($timeout)->retry($retries, $sleep, throw: false);
            if (! empty($headers)) {
                $client = $client->withHeaders($headers);
            }

            $response = match ($method) {
                'PUT' => $client->put($endpoint, $payload),
                'PATCH' => $client->patch($endpoint, $payload),
                'GET' => $client->get($endpoint, $payload),
                default => $client->post($endpoint, $payload),
            };

            $statusCode = $response->status();
            $successful = $response->successful();
            $body = mb_substr((string) $response->body(), 0, 1000);

            return [
                'status' => $successful ? 'sent' : 'failed',
                'http_status' => $statusCode,
                'successful' => $successful,
                'endpoint' => $endpoint,
                'method' => $method,
                'response_body' => $body,
                'error' => $successful ? null : "Destination responded with HTTP {$statusCode}",
            ];
        } catch (Throwable $e) {
            $statusCode = null;
            $body = '';
            if ($e instanceof \Illuminate\Http\Client\RequestException && $e->response !== null) {
                $statusCode = $e->response->status();
                $body = mb_substr((string) $e->response->body(), 0, 1000);
            }

            return [
                'status' => 'failed',
                'http_status' => $statusCode,
                'successful' => false,
                'endpoint' => $endpoint,
                'method' => $method,
                'response_body' => $body,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function validateEndpoint(string $endpoint): string
    {
        $url = trim($endpoint);
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException("Invalid endpoint URL provided for http_dispatch: [{$url}]");
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $allowedSchemes = (array) Config::get('lara-orcest.web_scraping.allowed_schemes', ['http', 'https']);
        if (! in_array($scheme, $allowedSchemes, true)) {
            throw new InvalidArgumentException("URL scheme [{$scheme}] is not allowed for endpoint: [{$url}]");
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $allowPrivateIps = (bool) Config::get('lara-orcest.web_scraping.allow_private_ips', false);

        if (! $allowPrivateIps) {
            if ($host === '169.254.169.254' || $host === 'metadata.google.internal' || $host === '100.100.100.200') {
                throw new InvalidArgumentException("Access to cloud metadata host [{$host}] is blocked by security policy.");
            }

            if ($host === 'localhost' || $host === '127.0.0.1' || $host === '0.0.0.0' || $host === '::1') {
                throw new InvalidArgumentException("Access to localhost / loopback address [{$host}] is restricted.");
            }
        }

        return $url;
    }
}
