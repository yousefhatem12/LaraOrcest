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

    public function test_standard_workflow_bypasses_web_scraping_agent_cleanly(): void
    {
        config(['lara-orcest.web_scraping.default_endpoint' => null]);

        $visitedNodes = [];
        $state = Orcest::run('Implement a multi-tenant subscription billing model', [
            'dry_run' => true,
            'on_step' => function (RunState $s, string $node) use (&$visitedNodes) {
                $visitedNodes[] = $node;
            },
        ]);

        $this->assertContains('business_analyst', $visitedNodes);
        $this->assertContains('product_manager', $visitedNodes);
        $this->assertContains('architect', $visitedNodes);
        $this->assertNotContains('web_scraping_agent', $visitedNodes, 'Standard run must cleanly bypass web_scraping_agent without overhead');
        $this->assertTrue($state->isCompleted());
        $this->assertEmpty($state->getScrapingResults());
    }

    public function test_workflow_bypasses_scraper_when_urls_present_but_endpoint_missing(): void
    {
        config(['lara-orcest.web_scraping.default_endpoint' => null]);

        $visitedNodes = [];
        $state = Orcest::run('Compare documentation at https://laravel.com/docs and https://vuejs.org/guide', [
            'dry_run' => true,
            'on_step' => function (RunState $s, string $node) use (&$visitedNodes) {
                $visitedNodes[] = $node;
            },
        ]);

        $this->assertContains('product_manager', $visitedNodes);
        $this->assertContains('architect', $visitedNodes);
        $this->assertNotContains('web_scraping_agent', $visitedNodes, 'Must bypass web_scraping_agent when no destination endpoint is provided');
        $this->assertTrue($state->isCompleted());
    }

    public function test_workflow_bypasses_scraper_when_explicitly_disabled_via_option(): void
    {
        $visitedNodes = [];
        $state = Orcest::run('Scrape https://site1.com and https://site2.com and post to https://api.example.com/dest', [
            'dry_run' => true,
            'no_scrape' => true,
            'on_step' => function (RunState $s, string $node) use (&$visitedNodes) {
                $visitedNodes[] = $node;
            },
        ]);

        $this->assertContains('product_manager', $visitedNodes);
        $this->assertContains('architect', $visitedNodes);
        $this->assertNotContains('web_scraping_agent', $visitedNodes, 'Must bypass web_scraping_agent when explicitly disabled');
        $this->assertTrue($state->isCompleted());
    }

    public function test_workflow_executes_scraper_after_pm_and_before_architect_with_data_in_runstate(): void
    {
        Http::fake([
            'https://vendor-one.com*' => Http::response('<html><body><h1>Vendor One Products</h1><p>Widget A: $10</p></body></html>', 200),
            'https://vendor-two.com*' => Http::response('<html><body><h1>Vendor Two Products</h1><p>Widget B: $20</p></body></html>', 200),
            'https://api.aggregator.com/feed*' => Http::response(['status' => 'ingested'], 201),
        ]);

        $stepOrder = [];
        $state = Orcest::run('Scrape https://vendor-one.com and https://vendor-two.com and post to https://api.aggregator.com/feed', [
            'dry_run' => true,
            'on_step' => function (RunState $s, string $node) use (&$stepOrder) {
                $stepOrder[] = $node;
            },
        ]);

        $this->assertTrue($state->isCompleted());

        // Verify ordering: business_analyst -> product_manager -> web_scraping_agent -> architect
        $pmIndex = array_search('product_manager', $stepOrder, true);
        $scraperIndex = array_search('web_scraping_agent', $stepOrder, true);
        $architectIndex = array_search('architect', $stepOrder, true);

        $this->assertNotFalse($pmIndex, 'product_manager must execute');
        $this->assertNotFalse($scraperIndex, 'web_scraping_agent must execute');
        $this->assertNotFalse($architectIndex, 'architect must execute');

        $this->assertGreaterThan($pmIndex, $scraperIndex, 'web_scraping_agent must run after product_manager');
        $this->assertGreaterThan($scraperIndex, $architectIndex, 'architect must run after web_scraping_agent');

        // Verify scraped schema/data and endpoint contract are recorded in RunState
        $scraping = $state->getScrapingResults();
        $this->assertNotEmpty($scraping);
        $this->assertEquals('https://api.aggregator.com/feed', $scraping['target_endpoint']);
        $this->assertEquals(['https://vendor-one.com', 'https://vendor-two.com'], $scraping['source_urls']);
        $this->assertEquals('skipped_dry_run', $scraping['dispatch']['status']);
    }

    public function test_workflow_activates_scraper_when_requirements_spec_dynamically_provides_urls_and_endpoint(): void
    {
        Http::fake([
            'https://devices-a.com*' => Http::response('Device A data', 200),
            'https://devices-b.com*' => Http::response('Device B data', 200),
        ]);

        $fakeLlm = new FakeDriver();
        $fakeLlm->setCannedResponse('structured', [
            'domain_entities' => ['ScrapedDevice'],
            'functional_requirements' => ['Extract device catalog'],
            'business_rules' => ['Price in USD'],
            'source_urls' => ['https://devices-a.com/catalog', 'https://devices-b.com/catalog'],
            'target_endpoint' => 'https://api.inventory.test/devices',
            'tasks' => [
                [
                    'id' => 'TASK-1',
                    'title' => 'Process scraped devices',
                    'priority' => 1,
                    'acceptance_criteria' => ['Device model and price'],
                ],
            ],
            'extraction_summary' => 'Scraped devices from A and B',
            'site_1_data' => ['model' => 'Dev-1', 'price' => 100],
            'site_2_data' => ['model' => 'Dev-2', 'price' => 200],
            'unified_payload' => [
                'devices' => [
                    ['model' => 'Dev-1', 'price' => 100],
                    ['model' => 'Dev-2', 'price' => 200],
                ],
            ],
            'migrations' => ['create_devices_table' => 'database/migrations/2026_01_01_create_devices_table.php'],
            'models' => ['Device' => 'app/Models/Device.php'],
            'controllers' => ['DeviceController' => 'app/Http/Controllers/DeviceController.php'],
            'execution_steps' => ['Step 1: Migrate'],
            'decision' => 'approved',
            'findings' => ['Approved'],
        ]);

        $visited = [];
        $engine = DefaultGraphBuilder::build($fakeLlm, withScraper: true);
        $engine->onStep(function (RunState $s, string $node) use (&$visited) {
            $visited[] = $node;
        });

        // Prompt has NO URLs initially
        $state = RunState::create('Ingest devices from suppliers', [
            'dry_run' => true,
        ]);

        $finalState = $engine->run($state);

        $this->assertTrue($finalState->isCompleted());
        $this->assertContains('business_analyst', $visited);
        $this->assertContains('product_manager', $visited);
        $this->assertContains('web_scraping_agent', $visited, 'web_scraping_agent should activate when BA spec introduces scraping URLs and endpoint');
        $this->assertContains('architect', $visited);

        $scraping = $finalState->getScrapingResults();
        $this->assertNotEmpty($scraping);
        $this->assertEquals('https://api.inventory.test/devices', $scraping['target_endpoint']);
        $this->assertEquals(['https://devices-a.com/catalog', 'https://devices-b.com/catalog'], $scraping['source_urls']);
    }

    public function test_architect_and_downstream_agents_receive_scraped_data_and_endpoint_contract(): void
    {
        $state = RunState::create('Feature with scraping', ['dry_run' => true])
            ->withScrapingResults([
                'source_urls' => ['https://src1.com', 'https://src2.com'],
                'target_endpoint' => 'https://api.warehouse.internal/items',
                'http_method' => 'POST',
                'extraction_summary' => 'Extracted 5 warehouse items',
                'unified_payload' => [
                    'sku' => 'ITEM-99',
                    'price' => 49.99,
                    'stock' => 150,
                ],
                'headers_used' => ['X-Api-Key' => '[REDACTED]'],
            ])
            ->withRequirementsSpec(['domain_entities' => ['Item']])
            ->withBacklog([['id' => 'TASK-1', 'title' => 'Ingest items']]);

        // ArchitectAgent prompt inspection
        $architectAgent = new \Yousef\LaraOrcest\Agents\ArchitectAgent();
        $ref = new \ReflectionMethod($architectAgent, 'buildUserPrompt');
        $ref->setAccessible(true);
        $architectPrompt = $ref->invoke($architectAgent, $state);

        $this->assertStringContainsString('SCRAPED DATA & DESTINATION CONTRACT (from Web Scraping Agent):', $architectPrompt);
        $this->assertStringContainsString('https://api.warehouse.internal/items', $architectPrompt);
        $this->assertStringContainsString('https://src1.com', $architectPrompt);
        $this->assertStringContainsString('ITEM-99', $architectPrompt);

        // BackendAgent prompt inspection
        $backendAgent = new \Yousef\LaraOrcest\Agents\BackendAgent();
        $refBackend = new \ReflectionMethod($backendAgent, 'buildUserPrompt');
        $refBackend->setAccessible(true);
        $backendPrompt = $refBackend->invoke($backendAgent, $state);

        $this->assertStringContainsString('DESTINATION ENDPOINT CONTRACT & DATA PAYLOAD (from Web Scraping Agent):', $backendPrompt);
        $this->assertStringContainsString('https://api.warehouse.internal/items', $backendPrompt);

        // DatabaseAgent prompt inspection
        $databaseAgent = new \Yousef\LaraOrcest\Agents\DatabaseAgent();
        $refDb = new \ReflectionMethod($databaseAgent, 'buildUserPrompt');
        $refDb->setAccessible(true);
        $dbPrompt = $refDb->invoke($databaseAgent, $state);

        $this->assertStringContainsString('INGESTED DATA STRUCTURE / PAYLOAD (from Web Scraping Agent):', $dbPrompt);
        $this->assertStringContainsString('ITEM-99', $dbPrompt);
    }
}
