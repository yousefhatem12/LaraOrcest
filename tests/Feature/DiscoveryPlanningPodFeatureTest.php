<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Yousef\LaraOrcest\Agents\BusinessAnalystAgent;
use Yousef\LaraOrcest\Agents\ProductManagerAgent;
use Yousef\LaraOrcest\Agents\SubAgents\BacklogSplitterAgent;
use Yousef\LaraOrcest\Agents\SubAgents\DomainRulesAgent;
use Yousef\LaraOrcest\Agents\SubAgents\WebScrapingSubAgent;
use Yousef\LaraOrcest\Agents\Teams\SubAgentRegistry;
use Yousef\LaraOrcest\LLM\Drivers\FakeDriver;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Support\Config;
use Yousef\LaraOrcest\Tests\TestCase;
use Yousef\LaraOrcest\Tools\FileReaderTool;
use Yousef\LaraOrcest\Tools\HttpDispatchTool;
use Yousef\LaraOrcest\Tools\HttpFetchTool;

class DiscoveryPlanningPodFeatureTest extends TestCase
{
    public function test_business_analyst_delegates_to_domain_rules_agent_with_skill_injection(): void
    {
        $baAgent = $this->app->make(BusinessAnalystAgent::class);
        $this->assertInstanceOf(BusinessAnalystAgent::class, $baAgent);

        $fakeLlm = new FakeDriver();
        $state = RunState::create('Build an organization subscription billing system with seat quotas');
        $tools = [
            'file_reader' => $this->app->make(FileReaderTool::class),
        ];

        // Ensure teams enabled
        Config::set('lara-orcest.teams.enabled', true);

        $resultState = $baAgent->execute($state, $tools, $fakeLlm);

        $this->assertNotEmpty($resultState->requirementsSpec);
        $this->assertArrayHasKey('domain_entities', $resultState->requirementsSpec);
        $this->assertArrayHasKey('business_rules', $resultState->requirementsSpec);
        $this->assertArrayHasKey('edge_cases', $resultState->requirementsSpec);

        // Verify delegation recorded in RunState
        $delegations = $resultState->getDelegations();
        $this->assertNotEmpty($delegations);

        $baDelegations = $resultState->getDelegationsForAgent('business_analyst');
        $this->assertCount(1, $baDelegations);
        $this->assertEquals('domain_rules_agent', $baDelegations[0]['sub_agent']);
        $this->assertEquals('completed', $baDelegations[0]['status']);
        $this->assertContains('business-requirements-analysis', $baDelegations[0]['active_skills']);
    }

    public function test_product_manager_delegates_to_backlog_splitter_agent_with_depends_on_and_scopes(): void
    {
        $pmAgent = $this->app->make(ProductManagerAgent::class);
        $this->assertInstanceOf(ProductManagerAgent::class, $pmAgent);

        $fakeLlm = new FakeDriver();
        $state = RunState::create('Implement user invitations', [
            'requirements_spec' => [
                'domain_entities' => ['Invitation', 'Team'],
                'functional_requirements' => ['Admins send invitations', 'Users accept invitations via token'],
                'business_rules' => ['Tokens expire after 48 hours'],
            ],
        ]);
        $tools = [
            'file_reader' => $this->app->make(FileReaderTool::class),
        ];

        Config::set('lara-orcest.teams.enabled', true);

        $resultState = $pmAgent->execute($state, $tools, $fakeLlm);

        $this->assertNotEmpty($resultState->backlog);
        $firstTask = $resultState->backlog[0];
        $this->assertArrayHasKey('id', $firstTask);
        $this->assertArrayHasKey('depends_on', $firstTask);
        $this->assertArrayHasKey('scope_paths', $firstTask);
        $this->assertArrayHasKey('acceptance_criteria', $firstTask);

        // Verify delegation recorded in RunState
        $pmDelegations = $resultState->getDelegationsForAgent('product_manager');
        $this->assertCount(1, $pmDelegations);
        $this->assertEquals('backlog_splitter_agent', $pmDelegations[0]['sub_agent']);
        $this->assertEquals('completed', $pmDelegations[0]['status']);
        $this->assertContains('backlog-task-decomposition', $pmDelegations[0]['active_skills']);
    }

    public function test_fallback_behavior_when_teams_are_disabled(): void
    {
        $baAgent = $this->app->make(BusinessAnalystAgent::class);
        $pmAgent = $this->app->make(ProductManagerAgent::class);

        $fakeLlm = new FakeDriver();
        $tools = [
            'file_reader' => $this->app->make(FileReaderTool::class),
        ];

        // Explicitly disable teams
        Config::set('lara-orcest.teams.enabled', false);

        $state = RunState::create('Build simple feedback form');

        $baResult = $baAgent->execute($state, $tools, $fakeLlm);
        $this->assertNotEmpty($baResult->requirementsSpec);
        $this->assertEmpty($baResult->getDelegations()); // No delegations!

        $pmResult = $pmAgent->execute($baResult, $tools, $fakeLlm);
        $this->assertNotEmpty($pmResult->backlog);
        $this->assertEmpty($pmResult->getDelegations()); // No delegations!
    }

    public function test_conditional_web_scraping_agent_dispatches_data_and_respects_dry_run(): void
    {
        Http::fake([
            'https://vendor-one.com*' => Http::response('<html><body><h1>Vendor One</h1><p>Widget: $99</p></body></html>', 200),
            'https://vendor-two.com*' => Http::response('<html><body><h1>Vendor Two</h1><p>Widget Pro: $149</p></body></html>', 200),
            'https://api.aggregator.com/feed*' => Http::response(['ack' => true], 200),
        ]);

        $subRegistry = $this->app->make(SubAgentRegistry::class);
        $this->assertTrue($subRegistry->has('domain_rules_agent'));
        $this->assertTrue($subRegistry->has('backlog_splitter_agent'));
        $this->assertTrue($subRegistry->has('web_scraping_agent'));

        $pmAgent = $this->app->make(ProductManagerAgent::class);
        $fakeLlm = new FakeDriver();
        $tools = [
            'file_reader' => $this->app->make(FileReaderTool::class),
            'http_fetch' => $this->app->make(HttpFetchTool::class),
            'http_dispatch' => $this->app->make(HttpDispatchTool::class),
        ];

        Config::set('lara-orcest.teams.enabled', true);

        // Execute delegation directly on web_scraping_agent
        $state = RunState::create('Scrape vendor catalogs', [
            'dry_run' => true,
            'web_scraping' => [
                'source_urls' => ['https://vendor-one.com', 'https://vendor-two.com'],
                'target_endpoint' => 'https://api.aggregator.com/feed',
                'method' => 'POST',
            ],
            'requirements_spec' => ['domain_entities' => ['VendorItem']],
        ]);

        $delegationResult = $pmAgent->delegateSubTasks(
            state: $state,
            subAgentKeys: ['web_scraping_agent'],
            context: [
                'requirements_spec' => $state->requirementsSpec,
                'prompt' => $state->prompt,
            ],
            tools: $tools,
            llm: $fakeLlm,
            activeSkillNames: ['two-site-scraping-dispatch']
        );

        $this->assertTrue($delegationResult->success);
        $scrapingResult = $delegationResult->getResult('web_scraping_agent');
        $this->assertNotNull($scrapingResult);
        $this->assertEquals('skipped_dry_run', $scrapingResult['output']['dispatch']['status']);

        // Check artifacts attributed to web_scraping_agent
        $artifacts = $delegationResult->state->artifacts;
        $this->assertNotEmpty($artifacts);
        $this->assertEquals('web_scraping_agent', $artifacts[0]['agent']);
    }
}
