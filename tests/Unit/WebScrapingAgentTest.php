<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Unit;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Yousef\LaraOrcest\Agents\WebScrapingAgent;
use Yousef\LaraOrcest\LLM\Drivers\FakeDriver;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Tests\TestCase;
use Yousef\LaraOrcest\Tools\FileReaderTool;
use Yousef\LaraOrcest\Tools\Support\PathGuard;

class WebScrapingAgentTest extends TestCase
{
    protected WebScrapingAgent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = new WebScrapingAgent();
    }

    public function test_agent_metadata_and_capabilities(): void
    {
        $this->assertEquals('web_scraping_agent', $this->agent->getName());
        $this->assertEquals('scraping_results', $this->agent->getStateOutputKey());
        $this->assertStringContainsString('Scraping', $this->agent->getRole());
        $this->assertContains('file_reader', $this->agent->getAllowedTools());
    }

    public function test_resolves_and_validates_urls_from_metadata(): void
    {
        $state = RunState::create('Test prompt', [
            'web_scraping' => [
                'source_urls' => ['https://site-alpha.com/items', 'https://site-beta.com/items'],
                'target_endpoint' => 'https://api.destination.com/webhook',
                'method' => 'POST',
            ],
        ]);

        $resolved = $this->agent->resolveUrlsAndEndpoint($state);

        $this->assertCount(2, $resolved['source_urls']);
        $this->assertEquals('https://site-alpha.com/items', $resolved['source_urls'][0]);
        $this->assertEquals('https://site-beta.com/items', $resolved['source_urls'][1]);
        $this->assertEquals('https://api.destination.com/webhook', $resolved['target_endpoint']);
        $this->assertEquals('POST', $resolved['method']);
    }

    public function test_resolves_urls_from_prompt_text_automatically(): void
    {
        $prompt = 'Please scrape https://catalog-one.org/data and https://catalog-two.org/data and post findings to https://collector.io/v1/ingest';
        $state = RunState::create($prompt);

        $resolved = $this->agent->resolveUrlsAndEndpoint($state);

        $this->assertEquals('https://catalog-one.org/data', $resolved['source_urls'][0]);
        $this->assertEquals('https://catalog-two.org/data', $resolved['source_urls'][1]);
        $this->assertEquals('https://collector.io/v1/ingest', $resolved['target_endpoint']);
    }

    public function test_throws_exception_when_fewer_than_two_source_urls_provided(): void
    {
        $state = RunState::create('Only one url https://single-site.com', [
            'web_scraping' => [
                'source_urls' => ['https://single-site.com'],
                'target_endpoint' => 'https://api.destination.com/webhook',
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('WebScrapingAgent requires exactly two target website URLs to scrape, but received 1.');

        $this->agent->resolveUrlsAndEndpoint($state);
    }

    public function test_throws_exception_when_destination_endpoint_is_missing(): void
    {
        $state = RunState::create('Two urls without endpoint', [
            'web_scraping' => [
                'source_urls' => ['https://site-a.com', 'https://site-b.com'],
                'target_endpoint' => null,
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('WebScrapingAgent requires a target destination endpoint URL.');

        $this->agent->resolveUrlsAndEndpoint($state);
    }

    public function test_throws_exception_on_disallowed_url_scheme(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('URL scheme [ftp] is not allowed');

        $this->agent->validateUrl('ftp://files.example.com/data.csv');
    }

    public function test_guards_against_cloud_metadata_service_ssrf(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Access to cloud metadata host [169.254.169.254] is blocked');

        $this->agent->validateUrl('http://169.254.169.254/latest/meta-data');
    }

    public function test_cleans_html_markup_and_preserves_text_structure(): void
    {
        $rawHtml = '
            <html>
                <head>
                    <title>Products</title>
                    <script>console.log("secret tracker");</script>
                    <style>body { color: red; }</style>
                </head>
                <body>
                    <!-- Navigation comment -->
                    <h1>Product Catalog</h1>
                    <p>Item 1: <strong>Gadget X</strong> - Price: $29.99</p>
                    <div><p>Item 2: Widget Y &amp; Co</p></div>
                    <svg><path d="M0 0h24v24H0z"/></svg>
                </body>
            </html>
        ';

        $cleaned = $this->agent->cleanContent($rawHtml, 'text/html', 5000);

        $this->assertStringNotContainsString('console.log', $cleaned);
        $this->assertStringNotContainsString('color: red', $cleaned);
        $this->assertStringNotContainsString('<svg', $cleaned);
        $this->assertStringContainsString('Product Catalog', $cleaned);
        $this->assertStringContainsString('Gadget X', $cleaned);
        $this->assertStringContainsString('Widget Y & Co', $cleaned);
    }

    public function test_executes_scraping_and_dispatches_outbound_http_payload(): void
    {
        Http::fake([
            'https://site-one.com*' => Http::response('<html><body><h1>Site One Items</h1><p>SKU-100: $49</p></body></html>', 200),
            'https://site-two.com*' => Http::response('<html><body><h1>Site Two Items</h1><p>SKU-200: $79</p></body></html>', 200),
            'https://api.example.com/ingest*' => Http::response(['status' => 'success', 'record_id' => 'rec_987'], 201),
        ]);

        $state = RunState::create('Scrape product catalogs', [
            'dry_run' => false,
            'web_scraping' => [
                'source_urls' => ['https://site-one.com', 'https://site-two.com'],
                'target_endpoint' => 'https://api.example.com/ingest',
                'method' => 'POST',
                'headers' => ['Authorization' => 'Bearer secret-token-xyz'],
            ],
        ]);
        $state = $state->withRequirementsSpec([
            'domain_entities' => ['Product'],
            'business_rules' => ['Currency must be USD'],
        ]);
        $state = $state->withBacklog([
            ['id' => 'TASK-1', 'title' => 'Extract products', 'priority' => 1, 'acceptance_criteria' => ['Include sku and price']],
        ]);

        $fakeLlm = new FakeDriver();
        $fakeLlm->setCannedResponse('structured', [
            'extraction_summary' => 'Extracted 2 products from Site One and Site Two.',
            'site_1_data' => ['sku' => 'SKU-100', 'price' => 49.0],
            'site_2_data' => ['sku' => 'SKU-200', 'price' => 79.0],
            'unified_payload' => [
                'items' => [
                    ['sku' => 'SKU-100', 'price' => 49.0, 'source' => 'https://site-one.com'],
                    ['sku' => 'SKU-200', 'price' => 79.0, 'source' => 'https://site-two.com'],
                ],
                'currency' => 'USD',
            ],
            'warnings' => [],
        ]);

        $tools = ['file_reader' => new FileReaderTool(new PathGuard(sys_get_temp_dir()))];

        $updatedState = $this->agent->execute($state, $tools, $fakeLlm);

        // 1. Verify outbound request was sent
        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.example.com/ingest'
                && $request->method() === 'POST'
                && $request['currency'] === 'USD'
                && count($request['items']) === 2;
        });

        // 2. Verify state was updated
        $scrapingResults = $updatedState->getScrapingResults();
        $this->assertNotEmpty($scrapingResults);
        $this->assertEquals('sent', $scrapingResults['dispatch']['status']);
        $this->assertEquals(201, $scrapingResults['dispatch']['http_status']);
        $this->assertEquals(['https://site-one.com', 'https://site-two.com'], $scrapingResults['source_urls']);

        // 3. Verify artifact was recorded
        $artifacts = $updatedState->artifacts;
        $this->assertNotEmpty($artifacts);
        $this->assertEquals('web_scraping/payload.json', $artifacts[0]['path']);
        $this->assertEquals('dispatched', $artifacts[0]['action']);

        // 4. Verify sensitive header was redacted in state
        $this->assertEquals('[REDACTED]', $scrapingResults['headers_used']['Authorization']);
    }

    public function test_respects_dry_run_mode_without_sending_outbound_http_request(): void
    {
        Http::fake([
            'https://site-a.com*' => Http::response('Site A content', 200),
            'https://site-b.com*' => Http::response('Site B content', 200),
            'https://api.example.com/ingest*' => Http::response(['status' => 'never called'], 200),
        ]);

        $state = RunState::create('Preview product scraping in dry-run', [
            'dry_run' => true,
            'web_scraping' => [
                'source_urls' => ['https://site-a.com', 'https://site-b.com'],
                'target_endpoint' => 'https://api.example.com/ingest',
            ],
        ]);

        $fakeLlm = new FakeDriver();
        $fakeLlm->setCannedResponse('structured', [
            'extraction_summary' => 'Preview extraction summary',
            'unified_payload' => ['preview' => true],
        ]);

        $tools = ['file_reader' => new FileReaderTool(new PathGuard(sys_get_temp_dir()))];

        $updatedState = $this->agent->execute($state, $tools, $fakeLlm);

        // Assert destination endpoint was NEVER called
        Http::assertNotSent(function ($request) {
            return $request->url() === 'https://api.example.com/ingest';
        });

        $scrapingResults = $updatedState->getScrapingResults();
        $this->assertEquals('skipped_dry_run', $scrapingResults['dispatch']['status']);
        $this->assertNull($scrapingResults['dispatch']['http_status']);

        $artifacts = $updatedState->artifacts;
        $this->assertEquals('preview', $artifacts[0]['action']);
    }

    public function test_handles_failed_source_url_gracefully(): void
    {
        Http::fake([
            'https://working-site.com*' => Http::response('Valid articles', 200),
            'https://failing-site.com*' => Http::response('Internal Server Error', 500),
        ]);

        $state = RunState::create('Scrape with one failing site', [
            'dry_run' => true,
            'web_scraping' => [
                'source_urls' => ['https://working-site.com', 'https://failing-site.com'],
                'target_endpoint' => 'https://api.example.com/data',
            ],
        ]);

        $fakeLlm = new FakeDriver();
        $fakeLlm->setCannedResponse('structured', [
            'extraction_summary' => 'Extracted from working-site; failing-site was unreachable.',
            'unified_payload' => ['items' => ['Article 1']],
            'warnings' => ['failing-site returned HTTP 500'],
        ]);

        $tools = ['file_reader' => new FileReaderTool(new PathGuard(sys_get_temp_dir()))];

        $updatedState = $this->agent->execute($state, $tools, $fakeLlm);

        $results = $updatedState->getScrapingResults();
        $this->assertFalse($results['sources_fetched']['https://failing-site.com']['successful']);
        $this->assertTrue($results['sources_fetched']['https://working-site.com']['successful']);
        $this->assertContains('failing-site returned HTTP 500', $results['warnings']);
    }

    public function test_resolves_urls_and_endpoint_from_requirements_spec(): void
    {
        $state = RunState::create('Domain requirement prompt')
            ->withRequirementsSpec([
                'domain_entities' => ['ScrapedLead'],
                'source_urls' => ['https://leads-source-1.com/list', 'https://leads-source-2.com/list'],
                'target_endpoint' => 'https://crm.internal/api/leads',
            ]);

        $this->assertTrue($state->hasScrapingUrls());
        $this->assertTrue($state->hasScrapingEndpoint());
        $this->assertTrue($state->shouldRunWebScraper());

        $resolved = $this->agent->resolveUrlsAndEndpoint($state);
        $this->assertEquals(['https://leads-source-1.com/list', 'https://leads-source-2.com/list'], $resolved['source_urls']);
        $this->assertEquals('https://crm.internal/api/leads', $resolved['target_endpoint']);
    }

    public function test_resolves_urls_and_endpoint_from_backlog_tasks(): void
    {
        $state = RunState::create('Backlog requirement prompt')
            ->withBacklog([
                [
                    'id' => 'TASK-SCRAPE-1',
                    'title' => 'Extract store listings',
                    'priority' => 1,
                    'source_urls' => ['https://store-a.com/catalog', 'https://store-b.com/catalog'],
                    'target_endpoint' => 'https://ingest.retailer.com/v1/products',
                    'acceptance_criteria' => ['Extract store catalog'],
                ],
            ]);

        $this->assertTrue($state->hasScrapingUrls());
        $this->assertTrue($state->hasScrapingEndpoint());
        $this->assertTrue($state->shouldRunWebScraper());

        $resolved = $this->agent->resolveUrlsAndEndpoint($state);
        $this->assertEquals(['https://store-a.com/catalog', 'https://store-b.com/catalog'], $resolved['source_urls']);
        $this->assertEquals('https://ingest.retailer.com/v1/products', $resolved['target_endpoint']);
    }

    public function test_resolves_urls_and_endpoint_from_configuration(): void
    {
        config([
            'lara-orcest.web_scraping.source_urls' => ['https://cfg-alpha.org/data', 'https://cfg-beta.org/data'],
            'lara-orcest.web_scraping.default_endpoint' => 'https://api.destination-cfg.org/webhook',
        ]);

        $state = RunState::create('Run configured scraping task');

        $this->assertTrue($state->hasScrapingUrls());
        $this->assertTrue($state->hasScrapingEndpoint());
        $this->assertTrue($state->shouldRunWebScraper());

        $resolved = $this->agent->resolveUrlsAndEndpoint($state);
        $this->assertEquals(['https://cfg-alpha.org/data', 'https://cfg-beta.org/data'], $resolved['source_urls']);
        $this->assertEquals('https://api.destination-cfg.org/webhook', $resolved['target_endpoint']);
    }

    public function test_should_not_activate_when_only_urls_provided_without_endpoint(): void
    {
        config(['lara-orcest.web_scraping.default_endpoint' => null]);

        $state = RunState::create('Prompt with 2 urls: https://site-1.com and https://site-2.com');

        $this->assertTrue($state->hasScrapingUrls());
        $this->assertFalse($state->hasScrapingEndpoint());
        $this->assertFalse($state->shouldRunWebScraper(), 'Web scraping must NOT activate when endpoint is missing');
    }

    public function test_should_not_activate_when_only_one_url_provided(): void
    {
        $state = RunState::create('Prompt with 1 url https://single-site.com', [
            'web_scraping' => [
                'source_urls' => ['https://single-site.com'],
                'target_endpoint' => 'https://api.destination.com/ingest',
            ],
        ]);

        $this->assertFalse($state->hasScrapingUrls(), 'Expecting two target URLs; 1 URL should fail activation check');
        $this->assertTrue($state->hasScrapingEndpoint());
        $this->assertFalse($state->shouldRunWebScraper());
    }

    public function test_should_not_activate_when_explicitly_disabled_in_config_or_options(): void
    {
        // 1. Explicitly disabled via option
        $stateDisabledViaOption = RunState::create(
            'Scrape https://a.com and https://b.com and send to https://dest.com',
            ['no_scrape' => true]
        );
        $this->assertTrue($stateDisabledViaOption->isWebScrapingDisabled());
        $this->assertFalse($stateDisabledViaOption->shouldRunWebScraper());

        // 2. Explicitly disabled via metadata
        $stateDisabledViaMeta = RunState::create(
            'Scrape https://a.com and https://b.com and send to https://dest.com',
            ['disable_web_scraping' => true]
        );
        $this->assertTrue($stateDisabledViaMeta->isWebScrapingDisabled());
        $this->assertFalse($stateDisabledViaMeta->shouldRunWebScraper());

        // 3. Explicitly disabled via config
        config(['lara-orcest.web_scraping.enabled' => false]);
        $stateDisabledViaConfig = RunState::create('Scrape https://a.com and https://b.com and send to https://dest.com');
        $this->assertTrue($stateDisabledViaConfig->isWebScrapingDisabled());
        $this->assertFalse($stateDisabledViaConfig->shouldRunWebScraper());
    }

    public function test_cleanly_bypasses_when_no_scraping_requested(): void
    {
        config(['lara-orcest.web_scraping.default_endpoint' => null]);
        $state = RunState::create('Build a standard blog feature with comments');

        $this->assertFalse($state->hasScrapingUrls());
        $this->assertFalse($state->hasScrapingEndpoint());
        $this->assertFalse($state->isWebScrapingDisabled());
        $this->assertFalse($state->shouldRunWebScraper());
    }
}
