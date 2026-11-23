<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Unit;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Yousef\LaraOrcest\Agents\SubAgents\BacklogSplitterAgent;
use Yousef\LaraOrcest\Agents\SubAgents\DomainRulesAgent;
use Yousef\LaraOrcest\Agents\SubAgents\WebScrapingSubAgent;
use Yousef\LaraOrcest\LLM\Drivers\FakeDriver;
use Yousef\LaraOrcest\Skills\SkillRegistry;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Tests\TestCase;
use Yousef\LaraOrcest\Tools\FileReaderTool;
use Yousef\LaraOrcest\Tools\HttpDispatchTool;
use Yousef\LaraOrcest\Tools\HttpFetchTool;
use Yousef\LaraOrcest\Tools\Support\PathGuard;

class DiscoveryPlanningPodTest extends TestCase
{
    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/lara_orcest_discovery_test_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $files = glob($this->tempDir . '/*');
            if (is_array($files)) {
                foreach ($files as $file) {
                    @unlink($file);
                }
            }
            @rmdir($this->tempDir);
        }
        parent::tearDown();
    }

    public function test_discovery_pod_skills_are_registered_and_parsed(): void
    {
        $registry = new SkillRegistry();
        $registry->loadAll();

        $this->assertTrue($registry->has('business-requirements-analysis'));
        $this->assertTrue($registry->has('backlog-task-decomposition'));
        $this->assertTrue($registry->has('two-site-scraping-dispatch'));

        $baSkill = $registry->get('business-requirements-analysis');
        $this->assertNotNull($baSkill);
        $this->assertTrue($baSkill->appliesToAgent('business_analyst'));
        $this->assertTrue($baSkill->appliesToAgent('domain_rules_agent'));
        $this->assertTrue($baSkill->permitsTool('file_reader'));

        $pmSkill = $registry->get('backlog-task-decomposition');
        $this->assertNotNull($pmSkill);
        $this->assertTrue($pmSkill->appliesToAgent('product_manager'));
        $this->assertTrue($pmSkill->appliesToAgent('backlog_splitter_agent'));

        $scrapeSkill = $registry->get('two-site-scraping-dispatch');
        $this->assertNotNull($scrapeSkill);
        $this->assertTrue($scrapeSkill->appliesToAgent('web_scraping_agent'));
        $this->assertTrue($scrapeSkill->permitsTool('http_fetch'));
        $this->assertTrue($scrapeSkill->permitsTool('http_dispatch'));
    }

    public function test_http_fetch_tool_fetches_and_cleans_html(): void
    {
        Http::fake([
            'https://example.com/products' => Http::response('<html><head><script>alert("ad")</script></head><body><h1>Products</h1><p>Widget: $25</p></body></html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $tool = new HttpFetchTool();
        $this->assertEquals('http_fetch', $tool->getName());

        $result = $tool->execute(['url' => 'https://example.com/products']);
        $this->assertTrue($result['successful']);
        $this->assertEquals(200, $result['status']);
        $this->assertStringNotContainsString('<script>', $result['content']);
        $this->assertStringContainsString('Products', $result['content']);
        $this->assertStringContainsString('Widget: $25', $result['content']);
    }

    public function test_http_fetch_tool_blocks_ssrf_and_invalid_schemes(): void
    {
        $tool = new HttpFetchTool();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/(blocked by security policy|restricted)/i');

        $tool->execute(['url' => 'http://169.254.169.254/latest/meta-data']);
    }

    public function test_http_dispatch_tool_dispatches_and_respects_dry_run(): void
    {
        Http::fake([
            'https://api.destination.com/ingest' => Http::response(['status' => 'received'], 201),
        ]);

        $tool = new HttpDispatchTool();
        $this->assertEquals('http_dispatch', $tool->getName());

        // 1. Dry run enabled
        $tool->setDryRun(true);
        $this->assertTrue($tool->isDryRun());

        $dryResult = $tool->execute([
            'endpoint' => 'https://api.destination.com/ingest',
            'method' => 'POST',
            'payload' => ['item' => 'Widget A'],
        ]);

        $this->assertEquals('skipped_dry_run', $dryResult['status']);
        $this->assertTrue($dryResult['successful']);
        Http::assertNothingSent();

        // 2. Normal mode (live dispatch)
        $tool->setDryRun(false);
        $liveResult = $tool->execute([
            'endpoint' => 'https://api.destination.com/ingest',
            'method' => 'POST',
            'payload' => ['item' => 'Widget A'],
        ]);

        $this->assertEquals('sent', $liveResult['status']);
        $this->assertTrue($liveResult['successful']);
        $this->assertEquals(201, $liveResult['http_status']);
    }

    public function test_domain_rules_sub_agent_execution(): void
    {
        $subAgent = new DomainRulesAgent();
        $this->assertEquals('domain_rules_agent', $subAgent->key());
        $this->assertEquals('business_analyst', $subAgent->parentAgentKey());
        $this->assertContains('file_reader', $subAgent->allowedTools());

        $fakeLlm = new FakeDriver();
        $state = RunState::create('Create invoice system with multi-tenant isolation');
        $tools = ['file_reader' => new FileReaderTool(new PathGuard(projectRoot: $this->tempDir))];

        $result = $subAgent->execute($state, $tools, $fakeLlm);

        $this->assertTrue($result['success']);
        $this->assertEquals('domain_rules_agent', $result['sub_agent']);
        $this->assertArrayHasKey('business_rules', $result['output']);
        $this->assertArrayHasKey('state_transitions', $result['output']);
        $this->assertArrayHasKey('edge_cases', $result['output']);
        $this->assertNotEmpty($result['output']['business_rules']);
    }

    public function test_backlog_splitter_sub_agent_execution(): void
    {
        $subAgent = new BacklogSplitterAgent();
        $this->assertEquals('backlog_splitter_agent', $subAgent->key());
        $this->assertEquals('product_manager', $subAgent->parentAgentKey());
        $this->assertContains('file_reader', $subAgent->allowedTools());

        $fakeLlm = new FakeDriver();
        $state = RunState::create('Build task management', [
            'requirements_spec' => [
                'domain_entities' => ['Task', 'Project'],
                'functional_requirements' => ['CRUD tasks'],
            ],
        ]);
        $tools = ['file_reader' => new FileReaderTool(new PathGuard(projectRoot: $this->tempDir))];

        $result = $subAgent->execute($state, $tools, $fakeLlm);

        $this->assertTrue($result['success']);
        $this->assertEquals('backlog_splitter_agent', $result['sub_agent']);
        $tasks = $result['output']['tasks'];
        $this->assertNotEmpty($tasks);
        $this->assertArrayHasKey('depends_on', $tasks[0]);
        $this->assertArrayHasKey('scope_paths', $tasks[0]);
    }

    public function test_web_scraping_sub_agent_execution_and_dry_run(): void
    {
        Http::fake([
            'https://site-alpha.org*' => Http::response('<html><body><h2>Alpha Store</h2><p>Camera: $399</p></body></html>', 200),
            'https://site-beta.org*' => Http::response('<html><body><h2>Beta Store</h2><p>Lens: $150</p></body></html>', 200),
            'https://api.destination.org/ingest*' => Http::response(['batch' => 'ok'], 200),
        ]);

        $subAgent = new WebScrapingSubAgent();
        $this->assertEquals('web_scraping_agent', $subAgent->key());
        $this->assertEquals('product_manager', $subAgent->parentAgentKey());
        $this->assertContains('http_fetch', $subAgent->allowedTools());
        $this->assertContains('http_dispatch', $subAgent->allowedTools());

        $fakeLlm = new FakeDriver();
        $tools = [
            'file_reader' => new FileReaderTool(new PathGuard(projectRoot: $this->tempDir)),
            'http_fetch' => new HttpFetchTool(),
            'http_dispatch' => new HttpDispatchTool(),
        ];

        // 1. Dry run
        $state = RunState::create('Scrape catalog', [
            'dry_run' => true,
            'web_scraping' => [
                'source_urls' => ['https://site-alpha.org', 'https://site-beta.org'],
                'target_endpoint' => 'https://api.destination.org/ingest',
                'method' => 'POST',
            ],
        ]);

        $result = $subAgent->execute($state, $tools, $fakeLlm);

        $this->assertTrue($result['success']);
        $this->assertEquals('web_scraping_agent', $result['sub_agent']);
        $this->assertEquals('skipped_dry_run', $result['output']['dispatch']['status']);
        $this->assertEquals('preview', $result['artifacts'][0]['action']);

        // 2. Normal mode
        $normalState = RunState::create('Scrape catalog live', [
            'dry_run' => false,
            'web_scraping' => [
                'source_urls' => ['https://site-alpha.org', 'https://site-beta.org'],
                'target_endpoint' => 'https://api.destination.org/ingest',
                'method' => 'POST',
            ],
        ]);

        $liveResult = $subAgent->execute($normalState, $tools, $fakeLlm);
        $this->assertTrue($liveResult['success']);
        $this->assertEquals('sent', $liveResult['output']['dispatch']['status']);
        $this->assertEquals('dispatched', $liveResult['artifacts'][0]['action']);
    }
}
