<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tools;

use InvalidArgumentException;
use Throwable;
use Yousef\LaraOrcest\Contracts\ToolContract;
use Yousef\LaraOrcest\Support\Config;
use Yousef\LaraOrcest\Support\HttpClient;

class HttpFetchTool implements ToolContract
{
    public function getName(): string
    {
        return 'http_fetch';
    }

    public function getDescription(): string
    {
        return 'Safely fetches and sanitizes web content from an allowed HTTP/HTTPS URL with SSRF protection, size limits, and HTML cleaning.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'url' => [
                    'type' => 'string',
                    'description' => 'Target HTTP or HTTPS URL to fetch.',
                ],
                'timeout' => [
                    'type' => 'integer',
                    'description' => 'Request timeout in seconds (default 30).',
                ],
                'max_chars' => [
                    'type' => 'integer',
                    'description' => 'Maximum characters of cleaned content to return (default 10000).',
                ],
            ],
            'required' => ['url'],
        ];
    }

    public function execute(array $params): array
    {
        $url = trim((string) ($params['url'] ?? ''));
        if ($url === '') {
            throw new InvalidArgumentException('Target URL is required for http_fetch.');
        }

        $validatedUrl = $this->validateUrl($url);

        $timeout = (int) ($params['timeout'] ?? Config::get('lara-orcest.web_scraping.timeout', 30));
        $retries = (int) Config::get('lara-orcest.web_scraping.retries', 2);
        $sleep = (int) Config::get('lara-orcest.web_scraping.retry_sleep', 100);
        $maxChars = (int) ($params['max_chars'] ?? Config::get('lara-orcest.web_scraping.max_content_chars', 10000));
        $userAgent = (string) Config::get(
            'lara-orcest.web_scraping.user_agent',
            'Mozilla/5.0 (compatible; LaraOrcestScraper/1.0; +https://github.com/yousefhatem/LaraOrcest)'
        );

        try {
            $response = HttpClient::factory()->timeout($timeout)
                ->retry($retries, $sleep)
                ->withHeaders(['User-Agent' => $userAgent])
                ->get($validatedUrl);

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
                'url' => $validatedUrl,
                'status' => $status,
                'size_bytes' => $size,
                'content' => $cleaned,
                'content_type' => $contentType,
                'successful' => $response->successful(),
                'error' => $response->successful() ? null : "HTTP status {$status}",
            ];
        } catch (Throwable $e) {
            return [
                'url' => $validatedUrl,
                'status' => 0,
                'size_bytes' => 0,
                'content' => "[Error fetching URL: {$e->getMessage()}]",
                'content_type' => 'unknown',
                'successful' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function validateUrl(string $url): string
    {
        $url = trim($url);
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException("Invalid URL provided for http_fetch: [{$url}]");
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $allowedSchemes = (array) Config::get('lara-orcest.web_scraping.allowed_schemes', ['http', 'https']);
        if (! in_array($scheme, $allowedSchemes, true)) {
            throw new InvalidArgumentException("URL scheme [{$scheme}] is not allowed for URL: [{$url}]");
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

    public function cleanContent(string $raw, string $contentType, int $maxChars): string
    {
        if (stripos($contentType, 'application/json') !== false || (str_starts_with(trim($raw), '{') && str_ends_with(trim($raw), '}'))) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $jsonStr = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: $raw;

                return mb_substr($jsonStr, 0, $maxChars);
            }
        }

        $cleaned = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $raw) ?? $raw;
        $cleaned = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/<svg\b[^>]*>(.*?)<\/svg>/is', '', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/<!--(.*?)-->/s', '', $cleaned) ?? $cleaned;

        $cleaned = preg_replace('/<\/(p|div|h[1-6]|li|tr|section|article|header|footer)>/i', "\n", $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/<br\s*\/?>/i', "\n", $cleaned) ?? $cleaned;

        $cleaned = strip_tags($cleaned);
        $cleaned = html_entity_decode($cleaned, ENT_QUOTES | ENT_HTML5, 'UTF-8');

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
}
