<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Yousef\LaraOrcest\Contracts\CheckpointerContract;
use Yousef\LaraOrcest\Facades\Orcest;
use Yousef\LaraOrcest\Graph\DefaultGraphBuilder;
use Yousef\LaraOrcest\LLM\Drivers\FakeDriver;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\State\StateCheckpointer;
use Yousef\LaraOrcest\Tests\TestCase;

class WebScrapingFeatureTest extends TestCase
{
    public function test_scraping_pipeline_executes_ba_pm_and_scraping_agent(): void
    {
        Http::fake([
            'https://site-alpha.com*' => Http::response('<html><body><h2>Electronics</h2><p>Camera: $499</p></body></html>', 200),
            'https://site-beta.com*' => Http::response('<html><body><h2>Gadgets</h2><p>Lens: $199</p></body></html>', 200),
            'https://api.aggregator.io/products*' => Http::response(['id' => 'batch_123', 'status' => 'processed'], 201),
        ]);

        $fakeLlm = new FakeDriver();
        $fakeLlm->setCannedResponse('structured', [
            // Can satisfy BA, PM, and Scraping depending on schema requested
            'domain_entities' => ['ScrapedProduct'],
            'functional_requirements' => ['Scrape camera prices', 'Normalize to USD'],
            'business_rules' => ['Price must be numeric'],
            'tasks' => [
                [
                    'id' => 'TASK-SCRAPE-1',
                    'title' => 'Extract camera listings',
                    'priority' => 1,
                    'acceptance_criteria' => ['Camera name', 'Price in USD'],
                ],
            ],
            'extraction_summary' => 'Scraped camera listings from alpha and beta sites',
            'site_1_data' => ['name' => 'Camera', 'price' => 499],
            'site_2_data' => ['name' => 'Lens', 'price' => 199],
            'unified_payload' => [
                'batch' => 'camera_equipment',
                'items' => [
                    ['name' => 'Camera', 'price' => 499],
                    ['name' => 'Lens', 'price' => 199],
                ],
            ],
            'warnings' => [],
        ]);

        $checkpointer = new StateCheckpointer();
        $engine = DefaultGraphBuilder::buildScrapingPipeline($fakeLlm, $checkpointer);

        $state = RunState::create('Scrape camera equipment from alpha and beta sites', [
            'dry_run' => false,
            'web_scraping' => [
                'source_urls' => ['https://site-alpha.com', 'https://site-beta.com'],
                'target_endpoint' => 'https://api.aggregator.io/products',
                'method' => 'POST',
            ],
        ]);

        $result = $engine->run($state);

        $this->assertTrue($result->isCompleted(), 'Scraping pipeline should complete successfully');
        $this->assertNotEmpty($result->requirementsSpec);
        $this->assertNotEmpty($result->backlog);

        $scraping = $result->getScrapingResults();
        $this->assertNotEmpty($scraping);
        $this->assertEquals('sent', $scraping['dispatch']['status']);
        $this->assertEquals(201, $scraping['dispatch']['http_status']);

        // Verify HTTP endpoint received the unified payload
        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.aggregator.io/products'
                && $request['batch'] === 'camera_equipment'
                && count($request['items']) === 2;
        });
    }

    public function test_orchestrator_routes_through_scraping_agent_when_urls_provided(): void
    {
        Http::fake([
            'https://news-a.com*' => Http::response('<html><body><h1>Breaking News A</h1></body></html>', 200),
            'https://news-b.com*' => Http::response('<html><body><h1>Breaking News B</h1></body></html>', 200),
            'https://api.newsfeed.com/articles*' => Http::response(['saved' => true], 200),
        ]);

        $visitedNodes = [];
        $state = Orcest::run('Scrape https://news-a.com and https://news-b.com and send to https://api.newsfeed.com/articles', [
            'dry_run' => true,
            'stop_after_scraping' => true,
            'on_step' => function (RunState $s, string $node) use (&$visitedNodes) {
                $visitedNodes[] = $node;
            },
        ]);

        $this->assertContains('business_analyst', $visitedNodes);
        $this->assertContains('product_manager', $visitedNodes);
        $this->assertContains('web_scraping_agent', $visitedNodes);
        $this->assertTrue($state->isCompleted());
    }

    public function test_scraping_results_serialization_and_checkpoint_persistence(): void
    {
        /** @var CheckpointerContract $checkpointer */
        $checkpointer = $this->app->make(CheckpointerContract::class);

        $state = RunState::initial('run_scrape_persistence', 'Scrape test', [
            'dry_run' => true,
        ]);
        $state = $state->withScrapingResults([
            'source_urls' => ['https://site1.com', 'https://site2.com'],
            'target_endpoint' => 'https://api.example.com/dest',
            'extraction_summary' => 'Persisted scraping results test',
            'dispatch' => [
                'status' => 'skipped_dry_run',
                'http_status' => null,
            ],
        ]);

        // 1. Test toArray / fromArray
        $arrayData = $state->toArray();
        $this->assertArrayHasKey('scraping_results', $arrayData);
        $this->assertEquals(['https://site1.com', 'https://site2.com'], $arrayData['scraping_results']['source_urls']);

        $restored = RunState::fromArray($arrayData);
        $this->assertEquals($state->getScrapingResults(), $restored->getScrapingResults());

        // 2. Test Checkpointer save and load
        $checkpointer->save($state);
        $loaded = $checkpointer->load('run_scrape_persistence');

        $this->assertNotNull($loaded);
        $this->assertEquals('Persisted scraping results test', $loaded->getScrapingResults()['extraction_summary']);
    }

    public function test_handles_destination_endpoint_http_error_gracefully(): void
    {
        Http::fake([
            'https://src-1.com*' => Http::response('Source 1 data', 200),
            'https://src-2.com*' => Http::response('Source 2 data', 200),
            'https://api.failing-endpoint.com/data*' => Http::response('Bad Gateway', 502),
        ]);

        $fakeLlm = new FakeDriver();
        $fakeLlm->setCannedResponse('structured', [
            'domain_entities' => ['Item'],
            'functional_requirements' => ['Extract'],
            'business_rules' => ['Valid'],
            'tasks' => [['id' => '1', 'title' => 'T1', 'priority' => 1, 'acceptance_criteria' => ['Ok']]],
            'extraction_summary' => 'Extracted items',
            'unified_payload' => ['item' => 'test'],
        ]);

        $engine = DefaultGraphBuilder::buildScrapingPipeline($fakeLlm);

        $state = RunState::create('Scrape and dispatch to failing endpoint', [
            'dry_run' => false,
            'web_scraping' => [
                'source_urls' => ['https://src-1.com', 'https://src-2.com'],
                'target_endpoint' => 'https://api.failing-endpoint.com/data',
            ],
        ]);

        $result = $engine->run($state);

        $scraping = $result->getScrapingResults();
        $this->assertEquals('failed', $scraping['dispatch']['status']);
        $this->assertEquals(502, $scraping['dispatch']['http_status']);
        $this->assertFalse($scraping['dispatch']['successful']);

        $artifacts = $result->artifacts;
        $this->assertNotEmpty($artifacts);
        $this->assertEquals('dispatch_failed', $artifacts[0]['action']);
    }
}
