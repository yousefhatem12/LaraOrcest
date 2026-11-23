<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Feature;

use Yousef\LaraOrcest\Agents\BackendAgent;
use Yousef\LaraOrcest\Agents\Teams\SubAgentRegistry;
use Yousef\LaraOrcest\Agents\Teams\TeamDelegator;
use Yousef\LaraOrcest\Skills\SkillRegistry;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Tests\TestCase;
use Yousef\LaraOrcest\Tools\ToolRegistry;

class SkillsAndTeamDelegationFeatureTest extends TestCase
{
    public function test_container_resolves_skills_teams_and_tools_singletons(): void
    {
        $skillRegistry = $this->app->make(SkillRegistry::class);
        $this->assertInstanceOf(SkillRegistry::class, $skillRegistry);
        $this->assertTrue($skillRegistry->has('laravel-strict-standards'));
        $this->assertTrue($skillRegistry->has('scoped-file-editing'));

        $subAgentRegistry = $this->app->make(SubAgentRegistry::class);
        $this->assertInstanceOf(SubAgentRegistry::class, $subAgentRegistry);

        $toolRegistry = $this->app->make(ToolRegistry::class);
        $this->assertInstanceOf(ToolRegistry::class, $toolRegistry);
        $this->assertTrue($toolRegistry->has('file_reader'));
        $this->assertTrue($toolRegistry->has('patch_writer'));
        $this->assertTrue($toolRegistry->has('artisan_runner'));
        $this->assertTrue($toolRegistry->has('test_runner'));
        $this->assertTrue($toolRegistry->has('git_command'));

        $delegator = $this->app->make(TeamDelegator::class);
        $this->assertInstanceOf(TeamDelegator::class, $delegator);
        $this->assertSame($subAgentRegistry, $delegator->getSubAgentRegistry());
        $this->assertSame($toolRegistry, $delegator->getToolRegistry());
    }

    public function test_agent_resolves_skills_and_progressive_disclosure(): void
    {
        $agent = $this->app->make(BackendAgent::class);
        $this->assertInstanceOf(BackendAgent::class, $agent);

        $state = RunState::create('Implement invoice processing API', [
            'active_skills' => ['laravel-strict-standards'],
        ]);

        $activeSkills = $agent->resolveActiveSkills($state);
        $this->assertArrayHasKey('laravel-strict-standards', $activeSkills);

        $summarySection = $agent->buildSkillsPromptSection($state, includeFullInstructions: false);
        $this->assertNotNull($summarySection);
        $this->assertStringContainsString('AVAILABLE SKILLS & GUIDELINES:', $summarySection);
        $this->assertStringContainsString('laravel-strict-standards:', $summarySection);

        $fullSection = $agent->buildSkillsPromptSection($state, includeFullInstructions: true);
        $this->assertNotNull($fullSection);
        $this->assertStringContainsString('ACTIVE SKILL INSTRUCTIONS:', $fullSection);
        $this->assertStringContainsString('### Skill: laravel-strict-standards', $fullSection);
    }

    public function test_lead_agent_delegate_sub_tasks_end_to_end(): void
    {
        $agent = $this->app->make(BackendAgent::class);
        $this->assertInstanceOf(BackendAgent::class, $agent);

        $subRegistry = $this->app->make(SubAgentRegistry::class);

        $dummySub = new class extends \Yousef\LaraOrcest\Agents\Teams\AbstractSubAgent {
            public function key(): string { return 'feature_test_sub'; }
            public function parentAgentKey(): string { return 'backend_agent'; }
            public function role(): string { return 'Feature Helper'; }
            public function goal(): string { return 'Assist backend agent'; }
            public function backstory(): string { return 'Helper backstory'; }
            public function scopePaths(): array { return ['app/Services/**']; }
            public function allowedTools(): array { return ['file_reader']; }
            protected function buildUserPrompt(RunState $state, array $context = []): string { return 'Prompt'; }
            protected function getOutputSchema(): array { return ['type' => 'object']; }
            protected function applyOutput(RunState $state, array $output, array $tools): array {
                return [
                    'summary' => 'Helper completed successfully',
                    'artifacts' => [
                        ['path' => 'app/Services/OrderService.php', 'action' => 'created', 'summary' => 'Service created'],
                    ],
                ];
            }
        };

        $subRegistry->register($dummySub);

        $state = RunState::create('Build order service');
        $tools = [
            'file_reader' => $this->app->make(\Yousef\LaraOrcest\Tools\FileReaderTool::class),
        ];
        $fakeLlm = new \Yousef\LaraOrcest\LLM\Drivers\FakeDriver();

        $batchResult = $agent->delegateSubTasks(
            state: $state,
            subAgentKeys: ['feature_test_sub'],
            context: ['service' => 'OrderService'],
            tools: $tools,
            llm: $fakeLlm,
            activeSkillNames: ['laravel-strict-standards']
        );

        $this->assertTrue($batchResult->success);
        $this->assertCount(1, $batchResult->results);
        $this->assertArrayHasKey('feature_test_sub', $batchResult->results);

        // State has recorded delegation
        $delegations = $batchResult->state->getDelegations();
        $this->assertCount(1, $delegations);
        $this->assertEquals('feature_test_sub', $delegations[0]['sub_agent']);
        $this->assertEquals('backend_agent', $delegations[0]['parent_agent']);
        $this->assertEquals(['laravel-strict-standards'], $delegations[0]['active_skills']);
        $this->assertEquals('completed', $delegations[0]['status']);

        // Artifact is attributed to sub-agent
        $this->assertCount(1, $batchResult->state->artifacts);
        $this->assertEquals('feature_test_sub', $batchResult->state->artifacts[0]['agent']);
    }
}
