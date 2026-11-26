<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Agents\SubAgents;

use InvalidArgumentException;
use Yousef\LaraOrcest\Agents\Teams\AbstractSubAgent;
use Yousef\LaraOrcest\Contracts\ToolContract;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Support\Config;
use Yousef\LaraOrcest\Tools\HttpDispatchTool;
use Yousef\LaraOrcest\Tools\HttpFetchTool;

class WebScrapingSubAgent extends AbstractSubAgent
{
    /**
     * @var array<string, array<string, mixed>>|null
     */
    protected ?array $fetchedSources = null;

    public function key(): string
    {
        return 'web_scraping_agent';
    }

    public function parentAgentKey(): string
    {
        return 'product_manager';
    }

    public function role(): string
    {
        return 'Intelligent Web Scraping & Ingestion Agent';
    }

    public function goal(): string
    {
        return 'Extract and normalize relevant structured data from two target website URLs guided by requirements_spec and backlog acceptance criteria, and send the structured payload to a target destination endpoint.';
    }

    public function backstory(): string
    {
        return 'Resilient web automation and data ingestion engineer specialized in parsing diverse web pages, cleaning markup, mapping raw web content to strict domain schemas, and reliably forwarding data to downstream API endpoints.';
    }

    public function scopePaths(): array
    {
        return ['docs/**', 'web_scraping/**'];
    }

    public function allowedTools(): array
    {
        return ['file_reader', 'http_fetch', 'http_dispatch'];
    }

    public function dependsOn(): array
    {
        return [];
    }

    public function skills(): array
    {
        return ['two-site-scraping-dispatch'];
    }

    protected function buildUserPrompt(RunState $state, array $context = []): string
    {
        $resolved = $this->resolveUrlsAndEndpoint($state);
        $sourceUrls = $resolved['source_urls'];
        $targetEndpoint = $resolved['target_endpoint'];
        $method = $resolved['method'];

        $fetchTool = new HttpFetchTool();
        $site1Data = $fetchTool->execute(['url' => $sourceUrls[0]]);
        $site2Data = $fetchTool->execute(['url' => $sourceUrls[1]]);

        $this->fetchedSources = [
            $sourceUrls[0] => $site1Data,
            $sourceUrls[1] => $site2Data,
        ];

        $specJson = json_encode($context['requirements_spec'] ?? $state->requirementsSpec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $backlogJson = json_encode($context['backlog'] ?? $state->backlog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $site1Content = (string) ($site1Data['content'] ?? '');
        $site2Content = (string) ($site2Data['content'] ?? '');

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

    protected function applyOutput(RunState $state, array $output, array $tools): array
    {
        $resolved = $this->resolveUrlsAndEndpoint($state);
        $sourceUrls = $resolved['source_urls'];
        $targetEndpoint = $resolved['target_endpoint'];
        $method = $resolved['method'];
        $headers = $this->redactHeaders($resolved['headers']);

        $unifiedPayload = (array) ($output['unified_payload'] ?? []);
        $summary = (string) ($output['extraction_summary'] ?? 'Web scraping data extraction');
        $warnings = (array) ($output['warnings'] ?? []);

        $isDryRun = $state->isDryRun();

        // Dispatch via HttpDispatchTool if available in authorized tools, else fallback
        $dispatchTool = isset($tools['http_dispatch']) && $tools['http_dispatch'] instanceof ToolContract
            ? $tools['http_dispatch']
            : new HttpDispatchTool(dryRun: $isDryRun);

        if (method_exists($dispatchTool, 'setDryRun')) {
            $dispatchTool->setDryRun($isDryRun);
        }

        $dispatchResult = $dispatchTool->execute([
            'endpoint' => $targetEndpoint,
            'method' => $method,
            'payload' => $unifiedPayload,
            'headers' => $resolved['headers'],
        ]);

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

        $this->fetchedSources = null;

        $action = $isDryRun ? 'preview' : (! empty($dispatchResult['successful']) ? 'dispatched' : 'dispatch_failed');

        return [
            'summary' => $summary,
            'output' => $scrapingRecord,
            'artifacts' => [
                [
                    'path' => 'web_scraping/payload.json',
                    'action' => $action,
                    'summary' => $summary,
                ],
            ],
        ];
    }

    public function resolveUrlsAndEndpoint(RunState $state): array
    {
        $meta = (array) ($state->metadata['web_scraping'] ?? []);
        $sourceUrls = $state->resolveScrapingUrls();

        $count = count($sourceUrls);
        if ($count < 2) {
            throw new InvalidArgumentException("WebScrapingAgent requires exactly two target website URLs to scrape, but received {$count}.");
        }

        $fetchTool = new HttpFetchTool();
        $validatedSources = [
            $fetchTool->validateUrl((string) $sourceUrls[0]),
            $fetchTool->validateUrl((string) $sourceUrls[1]),
        ];

        $targetEndpoint = $state->resolveScrapingEndpoint();
        if (empty($targetEndpoint) || trim($targetEndpoint) === '') {
            throw new InvalidArgumentException('WebScrapingAgent requires a target destination endpoint URL.');
        }

        $dispatchTool = new HttpDispatchTool();
        $validatedEndpoint = $dispatchTool->validateEndpoint(trim($targetEndpoint));

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
