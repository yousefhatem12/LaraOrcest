<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Agents;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Support\Config;

class WebScrapingAgent extends AbstractAgent
{
    /**
     * @var array<string, array<string, mixed>>|null
     */
    protected ?array $fetchedSources = null;

    public function getName(): string
    {
        return 'web_scraping_agent';
    }

    public function getRole(): string
    {
        return 'Intelligent Web Scraping & Ingestion Agent';
    }

    public function getGoal(): string
    {
        return 'Extract and normalize relevant structured data from two target website URLs guided by requirements_spec and backlog acceptance criteria, and send the structured payload to a target destination endpoint.';
    }

    public function getBackstory(): string
    {
        return 'Resilient web automation and data ingestion engineer specialized in parsing diverse web pages, cleaning markup, mapping raw web content to strict domain schemas, and reliably forwarding data to downstream API endpoints.';
    }

    public function getAllowedTools(): array
    {
        return ['file_reader'];
    }

    public function getStateOutputKey(): string
    {
        return 'scraping_results';
    }

    /**
     * Build the user message based on current state, requirements, and scraped content.
     */
    protected function buildUserPrompt(RunState $state): string
    {
        // 1. Resolve & validate source URLs and target endpoint
        $resolved = $this->resolveUrlsAndEndpoint($state);
        $sourceUrls = $resolved['source_urls'];
        $targetEndpoint = $resolved['target_endpoint'];
        $method = $resolved['method'];

        // 2. Fetch both source URLs
        $timeout = (int) Config::get('lara-orcest.web_scraping.timeout', 30);
        $retries = (int) Config::get('lara-orcest.web_scraping.retries', 2);
        $sleep = (int) Config::get('lara-orcest.web_scraping.retry_sleep', 100);
        $maxChars = (int) Config::get('lara-orcest.web_scraping.max_content_chars', 10000);

        $site1Data = $this->fetchUrlContent($sourceUrls[0], $timeout, $retries, $sleep, $maxChars);
        $site2Data = $this->fetchUrlContent($sourceUrls[1], $timeout, $retries, $sleep, $maxChars);

        $this->fetchedSources = [
            $sourceUrls[0] => $site1Data,
            $sourceUrls[1] => $site2Data,
        ];

        // 3. Assemble prompt context
        $specJson = json_encode($state->requirementsSpec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $backlogJson = json_encode($state->backlog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $site1Content = $site1Data['content'];
        $site2Content = $site2Data['content'];

        return implode("\n\n", [
            "REQUIREMENTS SPECIFICATION (from Business Analyst):\n{$specJson}",
            "PRODUCT BACKLOG & ACCEPTANCE CRITERIA (from Product Manager):\n{$backlogJson}",
            "TARGET SOURCE WEBSITE 1 (URL: {$sourceUrls[0]} | Status: {$site1Data['status']}):\n{$site1Content}",
            "TARGET SOURCE WEBSITE 2 (URL: {$sourceUrls[1]} | Status: {$site2Data['status']}):\n{$site2Content}",
            "DESTINATION ENDPOINT:\nURL: {$targetEndpoint}\nMethod: {$method}",
            "INSTRUCTIONS:\n" .
            "1. Extract the required entities, attributes, and fields from both websites guided strictly by requirements_spec and backlog acceptance criteria.\n" .
            "2. Reconcile, normalize, and merge data from both sites into a unified, production-ready payload formatted for the destination endpoint.\n" .
            "3. Provide a clear extraction summary and record any missing attributes or edge case warnings.",
        ]);
    }

    /**
     * Define the expected JSON Schema for LLM output.
     *
     * @return array<string, mixed>
     */
    protected function getOutputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'extraction_summary' => [
                    'type' => 'string',
                    'description' => 'Summary of data extracted from both target websites.',
                ],
                'site_1_data' => [
                    'type' => 'object',
                    'description' => 'Structured entities extracted from the first source website.',
                ],
                'site_2_data' => [
                    'type' => 'object',
                    'description' => 'Structured entities extracted from the second source website.',
                ],
                'unified_payload' => [
                    'type' => 'object',
                    'description' => 'Unified, validated, and normalized payload combining data from both sites per BA and PM criteria, formatted for the destination endpoint.',
                ],
                'warnings' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Any warnings, missing attributes, or parsing discrepancies.',
                ],
            ],
            'required' => ['extraction_summary', 'unified_payload'],
        ];
    }

    /**
     * Apply structured output and authorized tool effects.
     *
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>  $tools
     */
    protected function applyOutput(RunState $state, array $output, array $tools): RunState
    {
        $resolved = $this->resolveUrlsAndEndpoint($state);
        $sourceUrls = $resolved['source_urls'];
        $targetEndpoint = $resolved['target_endpoint'];
        $method = $resolved['method'];
        $headers = $this->redactHeaders($resolved['headers']);

        $unifiedPayload = (array) ($output['unified_payload'] ?? []);
        $summary = (string) ($output['extraction_summary'] ?? 'Web scraping data extraction');
        $warnings = (array) ($output['warnings'] ?? []);

        // Dispatch to target endpoint (respecting dry_run)
        $isDryRun = $state->isDryRun();
        $dispatchResult = $this->dispatchToEndpoint(
            endpoint: $targetEndpoint,
            method: $method,
            payload: $unifiedPayload,
            headers: $resolved['headers'],
            dryRun: $isDryRun
        );

        $scrapingRecord = [
            'source_urls' => $sourceUrls,
            'target_endpoint' => $targetEndpoint,
            'http_method' => $method,
            'headers_used' => $headers,
            'sources_fetched' => $this->fetchedSources ?? [],
            'extraction_summary' => $summary,
            'site_1_data' => (array) ($output['site_1_data'] ?? []),
            'site_2_data' => (array) ($output['site_2_data'] ?? []),
            'unified_payload' => $unifiedPayload,
            'dispatch' => $dispatchResult,
            'warnings' => $warnings,
            'timestamp' => date('c'),
        ];

        // Clean up transient turn cache
        $this->fetchedSources = null;

        // Record in RunState and artifacts
        $action = $isDryRun ? 'preview' : ($dispatchResult['successful'] ? 'dispatched' : 'dispatch_failed');
        $state = $state->withScrapingResults($scrapingRecord);

        return $state->recordArtifact('web_scraping/payload.json', $action, $summary);
    }

    /**
     * Resolves and strictly validates source URLs and destination endpoint.
     *
     * @return array{source_urls: array<int, string>, target_endpoint: string, method: string, headers: array<string, string>}
     */
    public function resolveUrlsAndEndpoint(RunState $state): array
    {
        $meta = (array) ($state->metadata['web_scraping'] ?? []);

        // 1. Resolve source URLs
        $sourceUrls = $state->resolveScrapingUrls();

        // Validate exactly two source URLs
        $count = count($sourceUrls);
        if ($count < 2) {
            throw new InvalidArgumentException("WebScrapingAgent requires exactly two target website URLs to scrape, but received {$count}.");
        }

        $validatedSources = [
            $this->validateUrl((string) $sourceUrls[0]),
            $this->validateUrl((string) $sourceUrls[1]),
        ];

        // 2. Resolve destination endpoint
        $targetEndpoint = $state->resolveScrapingEndpoint();

        if (empty($targetEndpoint) || trim($targetEndpoint) === '') {
            throw new InvalidArgumentException('WebScrapingAgent requires a target destination endpoint URL.');
        }

        $validatedEndpoint = $this->validateUrl(trim($targetEndpoint), isTarget: true);

        // 3. Method and headers
        $method = strtoupper((string) ($meta['method'] ?? Config::get('lara-orcest.web_scraping.default_method', 'POST')));
        if (! in_array($method, ['POST', 'PUT', 'PATCH', 'GET'], true)) {
            $method = 'POST';
        }

        $headers = (array) ($meta['headers'] ?? []);

        return [
            'source_urls' => $validatedSources,
            'target_endpoint' => $validatedEndpoint,
            'method' => $method,
            'headers' => $headers,
        ];
    }

    /**
     * Validates URL scheme and security guardrails.
     */
    public function validateUrl(string $url, bool $isTarget = false): string
    {
        $url = trim($url);
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException("Invalid URL provided for web scraping: [{$url}]");
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $allowedSchemes = (array) Config::get('lara-orcest.web_scraping.allowed_schemes', ['http', 'https']);
        if (! in_array($scheme, $allowedSchemes, true)) {
            throw new InvalidArgumentException("URL scheme [{$scheme}] is not allowed for URL: [{$url}]");
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $allowPrivateIps = (bool) Config::get('lara-orcest.web_scraping.allow_private_ips', false);

        if (! $allowPrivateIps) {
            // Check cloud metadata service
            if ($host === '169.254.169.254' || $host === 'metadata.google.internal' || $host === '100.100.100.200') {
                throw new InvalidArgumentException("Access to cloud metadata host [{$host}] is blocked by security policy.");
            }

            // Check loopback & internal hostnames
            if ($host === 'localhost' || $host === '127.0.0.1' || $host === '0.0.0.0' || $host === '::1') {
                throw new InvalidArgumentException("Access to localhost / loopback address [{$host}] is restricted.");
            }
        }

        return $url;
    }

    /**
     * Fetches and sanitizes web content from a target URL.
     *
     * @return array{url: string, status: int, size_bytes: int, content: string, content_type: string, successful: bool, error: ?string}
     */
    public function fetchUrlContent(string $url, int $timeout, int $retries, int $sleep, int $maxChars): array
    {
        $userAgent = (string) Config::get(
            'lara-orcest.web_scraping.user_agent',
            'Mozilla/5.0 (compatible; LaraOrcestScraper/1.0; +https://github.com/yousefhatem/LaraOrcest)'
        );

        try {
            $response = Http::timeout($timeout)
                ->retry($retries, $sleep)
                ->withHeaders(['User-Agent' => $userAgent])
                ->get($url);

            $status = $response->status();
            $contentType = (string) ($response->header('Content-Type') ?? '');
            $rawBody = (string) $response->body();
            $size = strlen($rawBody);

            $maxSize = (int) Config::get('lara-orcest.web_scraping.max_response_size', 500000);
            if ($size > $maxSize) {
                $rawBody = substr($rawBody, 0, $maxSize);
            }

            $cleaned = $this->cleanContent($rawBody, $contentType, $maxChars);

            return [
                'url' => $url,
                'status' => $status,
                'size_bytes' => $size,
                'content' => $cleaned,
                'content_type' => $contentType,
                'successful' => $response->successful(),
                'error' => $response->successful() ? null : "HTTP status {$status}",
            ];
        } catch (Throwable $e) {
            return [
                'url' => $url,
                'status' => 0,
                'size_bytes' => 0,
                'content' => "[Error fetching URL: {$e->getMessage()}]",
                'content_type' => 'unknown',
                'successful' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Cleans raw HTML or JSON content to keep model context compact.
     */
    public function cleanContent(string $raw, string $contentType, int $maxChars): string
    {
        if (stripos($contentType, 'application/json') !== false || (str_starts_with(trim($raw), '{') && str_ends_with(trim($raw), '}'))) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $jsonStr = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: $raw;

                return mb_substr($jsonStr, 0, $maxChars);
            }
        }

        // HTML Cleaning
        // 1. Remove scripts, styles, svg, and head/meta noise
        $cleaned = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $raw) ?? $raw;
        $cleaned = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/<svg\b[^>]*>(.*?)<\/svg>/is', '', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/<!--(.*?)-->/s', '', $cleaned) ?? $cleaned;

        // 2. Convert block boundaries to newlines
        $cleaned = preg_replace('/<\/(p|div|h[1-6]|li|tr|section|article|header|footer)>/i', "\n", $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/<br\s*\/?>/i', "\n", $cleaned) ?? $cleaned;

        // 3. Strip remaining tags and decode entities
        $cleaned = strip_tags($cleaned);
        $cleaned = html_entity_decode($cleaned, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // 4. Normalize whitespace
        $lines = explode("\n", $cleaned);
        $filtered = [];
        foreach ($lines as $line) {
            $trimmed = trim(preg_replace('/\s+/', ' ', $line) ?? '');
            if ($trimmed !== '') {
                $filtered[] = $trimmed;
            }
        }

        $result = implode("\n", $filtered);

        if (mb_strlen($result) > $maxChars) {
            return mb_substr($result, 0, $maxChars) . "\n[... Content truncated for length]";
        }

        return $result;
    }

    /**
     * Dispatches extracted payload to the destination endpoint.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     * @return array{status: string, http_status: ?int, successful: bool, response_body: string, error: ?string}
     */
    protected function dispatchToEndpoint(string $endpoint, string $method, array $payload, array $headers, bool $dryRun): array
    {
        if ($dryRun) {
            return [
                'status' => 'skipped_dry_run',
                'http_status' => null,
                'successful' => true,
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
                'response_body' => $body,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Redacts secret values from request headers.
     *
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    protected function redactHeaders(array $headers): array
    {
        $sanitized = [];
        $sensitiveKeys = ['authorization', 'api-key', 'x-api-key', 'token', 'secret', 'bearer'];

        foreach ($headers as $key => $value) {
            $lowerKey = strtolower((string) $key);
            $isSensitive = false;
            foreach ($sensitiveKeys as $pattern) {
                if (str_contains($lowerKey, $pattern)) {
                    $isSensitive = true;
                    break;
                }
            }
            $sanitized[$key] = $isSensitive ? '[REDACTED]' : $value;
        }

        return $sanitized;
    }
}
