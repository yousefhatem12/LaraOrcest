<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Yousef\LaraOrcest\Agents\Contracts\SubAgentInterface;
use Yousef\LaraOrcest\Agents\Teams\AbstractSubAgent;
use Yousef\LaraOrcest\Agents\Teams\SubAgentRegistry;
use Yousef\LaraOrcest\Agents\Teams\TeamDelegator;
use Yousef\LaraOrcest\Contracts\LlmDriverContract;
use Yousef\LaraOrcest\Contracts\ToolContract;
use Yousef\LaraOrcest\Graph\Exceptions\CyclicDependencyException;
use Yousef\LaraOrcest\LLM\Drivers\FakeDriver;
use Yousef\LaraOrcest\Skills\SkillRegistry;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Support\Config;
use Yousef\LaraOrcest\Tools\PatchWriterTool;
use Yousef\LaraOrcest\Tools\Support\PathGuard;
use Yousef\LaraOrcest\Tools\ToolRegistry;

class SubAgentAndTeamDelegatorTest extends TestCase
{
    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/lara_orcest_subagent_test_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0777, true);
        mkdir($this->tempDir . '/database/migrations', 0777, true);
        mkdir($this->tempDir . '/app/Models', 0777, true);
        mkdir($this->tempDir . '/app/Http/Controllers', 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tempDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $fileinfo) {
                if ($fileinfo->isDir()) {
                    @rmdir($fileinfo->getRealPath());
                } else {
                    @unlink($fileinfo->getRealPath());
                }
            }
            @rmdir($this->tempDir);
        }
        parent::tearDown();
    }

    public function test_sub_agent_registry_operations(): void
    {
        $registry = new SubAgentRegistry();
        $sub1 = $this->createMockSubAgent('migration_designer', 'database_agent', ['database/migrations/**']);
        $sub2 = $this->createMockSubAgent('model_builder', 'database_agent', ['app/Models/**']);
        $sub3 = $this->createMockSubAgent('route_planner', 'backend_agent', ['routes/**']);

        $registry->register($sub1)->register($sub2)->register($sub3);

        $this->assertTrue($registry->has('migration_designer'));
        $this->assertTrue($registry->has('model_builder'));
        $this->assertEquals(3, $registry->count());

        $dbSubAgents = $registry->forParent('database_agent');
        $this->assertCount(2, $dbSubAgents);
        $this->assertArrayHasKey('migration_designer', $dbSubAgents);
        $this->assertArrayHasKey('model_builder', $dbSubAgents);
        $this->assertArrayNotHasKey('route_planner', $dbSubAgents);
    }

    public function test_team_delegator_schedules_waves_and_executes_sequential_and_parallel(): void
    {
        $executionOrder = [];

        // SubAgent A: Wave 0 (no dependencies)
        $subA = new class($executionOrder) extends AbstractSubAgent {
            public function __construct(protected array &$order) {}
            public function key(): string { return 'sub_a'; }
            public function parentAgentKey(): string { return 'lead_agent'; }
            public function role(): string { return 'Role A'; }
            public function goal(): string { return 'Goal A'; }
            public function backstory(): string { return 'Backstory A'; }
            public function scopePaths(): array { return ['database/migrations/**']; }
            public function allowedTools(): array { return ['patch_writer']; }
            public function dependsOn(): array { return []; }
            protected function buildUserPrompt(RunState $state, array $context = []): string { return 'Prompt A'; }
            protected function getOutputSchema(): array { return ['type' => 'object']; }
            protected function applyOutput(RunState $state, array $output, array $tools): array {
                $this->order[] = 'sub_a';
                return ['summary' => 'Executed A', 'artifacts' => []];
            }
        };

        // SubAgent B: Wave 0 (no dependencies)
        $subB = new class($executionOrder) extends AbstractSubAgent {
            public function __construct(protected array &$order) {}
            public function key(): string { return 'sub_b'; }
            public function parentAgentKey(): string { return 'lead_agent'; }
            public function role(): string { return 'Role B'; }
            public function goal(): string { return 'Goal B'; }
            public function backstory(): string { return 'Backstory B'; }
            public function scopePaths(): array { return ['app/Models/**']; }
            public function allowedTools(): array { return ['patch_writer']; }
            public function dependsOn(): array { return []; }
            protected function buildUserPrompt(RunState $state, array $context = []): string { return 'Prompt B'; }
            protected function getOutputSchema(): array { return ['type' => 'object']; }
            protected function applyOutput(RunState $state, array $output, array $tools): array {
                $this->order[] = 'sub_b';
                return ['summary' => 'Executed B', 'artifacts' => []];
            }
        };

        // SubAgent C: Wave 1 (depends on sub_a and sub_b)
        $subC = new class($executionOrder) extends AbstractSubAgent {
            public function __construct(protected array &$order) {}
            public function key(): string { return 'sub_c'; }
            public function parentAgentKey(): string { return 'lead_agent'; }
            public function role(): string { return 'Role C'; }
            public function goal(): string { return 'Goal C'; }
            public function backstory(): string { return 'Backstory C'; }
            public function scopePaths(): array { return ['app/Http/Controllers/**']; }
            public function allowedTools(): array { return ['patch_writer']; }
            public function dependsOn(): array { return ['sub_a', 'sub_b']; }
            protected function buildUserPrompt(RunState $state, array $context = []): string { return 'Prompt C'; }
            protected function getOutputSchema(): array { return ['type' => 'object']; }
            protected function applyOutput(RunState $state, array $output, array $tools): array {
                $this->order[] = 'sub_c';
                return ['summary' => 'Executed C', 'artifacts' => []];
            }
        };

        $subRegistry = new SubAgentRegistry();
        $subRegistry->register($subA)->register($subB)->register($subC);

        $fakeLlm = new FakeDriver();

        $pathGuard = new PathGuard(projectRoot: $this->tempDir);
        $patchTool = new PatchWriterTool(pathGuard: $pathGuard);
        $tools = ['patch_writer' => $patchTool];

        $delegator = new TeamDelegator(subAgentRegistry: $subRegistry);
        $state = RunState::create('Test delegation waves');

        $result = $delegator->delegate(
            state: $state,
            parentAgentKey: 'lead_agent',
            subAgentKeys: ['sub_c', 'sub_b', 'sub_a'],
            context: ['task' => 'Build features'],
            tools: $tools,
            llm: $fakeLlm
        );

        $this->assertTrue($result->success);
        $this->assertCount(2, $result->waves);
        // Wave 0 must have sub_a and sub_b (in sorted order)
        $this->assertEquals(['sub_a', 'sub_b'], $result->waves[0]);
        // Wave 1 must have sub_c
        $this->assertEquals(['sub_c'], $result->waves[1]);

        // sub_c must execute AFTER sub_a and sub_b
        $this->assertEquals('sub_c', $executionOrder[2]);
        $this->assertContains('sub_a', array_slice($executionOrder, 0, 2));
        $this->assertContains('sub_b', array_slice($executionOrder, 0, 2));

        // State must record delegations
        $this->assertCount(3, $result->state->getDelegations());
    }

    public function test_team_delegator_detects_cyclic_dependencies(): void
    {
        $this->expectException(CyclicDependencyException::class);

        $subA = $this->createMockSubAgent('cycle_a', 'lead', ['app/**'], dependsOn: ['cycle_b']);
        $subB = $this->createMockSubAgent('cycle_b', 'lead', ['app/**'], dependsOn: ['cycle_a']);

        $registry = new SubAgentRegistry();
        $registry->register($subA)->register($subB);

        $delegator = new TeamDelegator(subAgentRegistry: $registry);
        $state = RunState::create('Test cycles');

        $delegator->delegate(
            state: $state,
            parentAgentKey: 'lead',
            subAgentKeys: ['cycle_a', 'cycle_b'],
            context: [],
            tools: [],
            llm: new FakeDriver()
        );
    }

    public function test_scope_paths_enforcement_blocks_unauthorized_file_mutations(): void
    {
        $pathGuard = new PathGuard(projectRoot: $this->tempDir);
        $patchTool = new PatchWriterTool(pathGuard: $pathGuard);

        $subAgent = new class extends AbstractSubAgent {
            public function key(): string { return 'restricted_worker'; }
            public function parentAgentKey(): string { return 'lead'; }
            public function role(): string { return 'Restricted Worker'; }
            public function goal(): string { return 'Goal'; }
            public function backstory(): string { return 'Backstory'; }
            public function scopePaths(): array { return ['database/migrations/**']; }
            public function allowedTools(): array { return ['patch_writer']; }
            protected function buildUserPrompt(RunState $state, array $context = []): string { return 'Prompt'; }
            protected function getOutputSchema(): array { return ['type' => 'object']; }
            protected function applyOutput(RunState $state, array $output, array $tools): array {
                /** @var PatchWriterTool $tool */
                $tool = $this->getTool('patch_writer', $tools);
                // Attempt to mutate outside scope: app/Http/Controllers/EvilController.php
                $tool->execute([
                    'path' => 'app/Http/Controllers/EvilController.php',
                    'action' => 'write',
                    'content' => '<?php // unauthorized',
                ]);
                return ['summary' => 'done'];
            }
        };

        $registry = new SubAgentRegistry();
        $registry->register($subAgent);

        $fakeLlm = new FakeDriver();

        $delegator = new TeamDelegator(subAgentRegistry: $registry);
        $state = RunState::create('Test scope enforcement');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/outside (permitted|allowed) scope/i');

        $delegator->delegate(
            state: $state,
            parentAgentKey: 'lead',
            subAgentKeys: ['restricted_worker'],
            context: [],
            tools: ['patch_writer' => $patchTool],
            llm: $fakeLlm
        );
    }

    public function test_allowed_tools_filtering_blocks_unauthorized_tool_access(): void
    {
        $subAgent = new class extends AbstractSubAgent {
            public function key(): string { return 'limited_tools_worker'; }
            public function parentAgentKey(): string { return 'lead'; }
            public function role(): string { return 'Role'; }
            public function goal(): string { return 'Goal'; }
            public function backstory(): string { return 'Backstory'; }
            public function scopePaths(): array { return ['database/**']; }
            public function allowedTools(): array { return ['file_reader']; } // Only file_reader!
            protected function buildUserPrompt(RunState $state, array $context = []): string { return 'Prompt'; }
            protected function getOutputSchema(): array { return ['type' => 'object']; }
            protected function applyOutput(RunState $state, array $output, array $tools): array {
                // Attempt to use patch_writer which is not in allowedTools
                $this->getTool('patch_writer', $tools);
                return ['summary' => 'done'];
            }
        };

        $registry = new SubAgentRegistry();
        $registry->register($subAgent);

        $fakeLlm = new FakeDriver();
        $delegator = new TeamDelegator(subAgentRegistry: $registry);
        $state = RunState::create('Test allowed tools');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/unauthorized or missing tool \[patch_writer\]/i');

        $delegator->delegate(
            state: $state,
            parentAgentKey: 'lead',
            subAgentKeys: ['limited_tools_worker'],
            context: [],
            tools: ['patch_writer' => new PatchWriterTool()],
            llm: $fakeLlm
        );
    }

    public function test_agent_models_routing_fallback_hierarchy(): void
    {
        $sub = new class extends AbstractSubAgent {
            public function key(): string { return 'custom_sub'; }
            public function parentAgentKey(): string { return 'custom_lead'; }
            public function role(): string { return 'Role'; }
            public function goal(): string { return 'Goal'; }
            public function backstory(): string { return 'Backstory'; }
            protected function buildUserPrompt(RunState $state, array $context = []): string { return 'Prompt'; }
            protected function getOutputSchema(): array { return ['type' => 'object']; }
            protected function applyOutput(RunState $state, array $output, array $tools): array { return []; }
        };

        // 1. When sub-agent has explicit override
        Config::set('lara-orcest.llm.agent_models.custom_sub', 'claude-3-haiku');
        Config::set('lara-orcest.llm.agent_models.custom_lead', 'claude-3-5-sonnet');
        $this->assertEquals('claude-3-haiku', $sub->resolveModel('parent-passed-model'));

        // 2. When sub-agent has no override, uses passed parent model
        Config::set('lara-orcest.llm.agent_models.custom_sub', null);
        $this->assertEquals('parent-passed-model', $sub->resolveModel('parent-passed-model'));

        // 3. When neither has override but parent key in config does
        $this->assertEquals('claude-3-5-sonnet', $sub->resolveModel(null));

        // 4. When neither has override, falls back to default_model
        Config::set('lara-orcest.llm.agent_models.custom_lead', null);
        Config::set('lara-orcest.llm.default_model', 'gpt-4o');
        $this->assertEquals('gpt-4o', $sub->resolveModel(null));

        // 5. When completely empty
        Config::set('lara-orcest.llm.default_model', null);
        $this->assertNull($sub->resolveModel(null));
    }

    public function test_team_delegator_halts_on_hitl_paused_state(): void
    {
        $registry = new SubAgentRegistry();
        $sub = $this->createMockSubAgent('dummy_sub', 'lead', ['app/**']);
        $registry->register($sub);

        $delegator = new TeamDelegator(subAgentRegistry: $registry);
        $state = RunState::create('Paused HITL run')
            ->pauseAtGate('after_plan', ['reason' => 'User review required']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/paused at Human-in-the-Loop gate/i');

        $delegator->delegate(
            state: $state,
            parentAgentKey: 'lead',
            subAgentKeys: ['dummy_sub'],
            context: [],
            tools: [],
            llm: new FakeDriver()
        );
    }

    public function test_team_delegator_halts_when_step_guardrail_exceeded(): void
    {
        $registry = new SubAgentRegistry();
        $sub = $this->createMockSubAgent('dummy_sub', 'lead', ['app/**']);
        $registry->register($sub);

        $delegator = new TeamDelegator(subAgentRegistry: $registry);
        // Step count 40 with max_steps 40
        $state = RunState::create('Exceeded steps run', ['max_steps' => 5]);
        for ($i = 0; $i < 5; $i++) {
            $state = $state->incrementStep();
        }

        $this->assertTrue($state->hasExceededSteps());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Maximum step guardrail reached/i');

        $delegator->delegate(
            state: $state,
            parentAgentKey: 'lead',
            subAgentKeys: ['dummy_sub'],
            context: [],
            tools: [],
            llm: new FakeDriver()
        );
    }

    public function test_team_delegator_restricts_tools_based_on_active_skill(): void
    {
        $pathGuard = new PathGuard(projectRoot: $this->tempDir);
        $reader = new \Yousef\LaraOrcest\Tools\FileReaderTool($pathGuard);
        $writer = new PatchWriterTool(pathGuard: $pathGuard);

        $sub = new class extends AbstractSubAgent {
            public function key(): string { return 'skill_scoped_worker'; }
            public function parentAgentKey(): string { return 'lead'; }
            public function role(): string { return 'Worker'; }
            public function goal(): string { return 'Goal'; }
            public function backstory(): string { return 'Backstory'; }
            public function scopePaths(): array { return ['database/**']; }
            public function allowedTools(): array { return ['file_reader', 'patch_writer']; }
            protected function buildUserPrompt(RunState $state, array $context = []): string { return 'Prompt'; }
            protected function getOutputSchema(): array { return ['type' => 'object']; }
            protected function applyOutput(RunState $state, array $output, array $tools): array {
                // If active skill restricted to file_reader only, patch_writer will throw unauthorized
                $this->getTool('patch_writer', $tools);
                return ['summary' => 'done'];
            }
        };

        $registry = new SubAgentRegistry();
        $registry->register($sub);

        $skillRegistry = new SkillRegistry();
        $skill = new \Yousef\LaraOrcest\Skills\Skill(
            name: 'read-only-audit',
            description: 'Enforce read only tools',
            agents: ['skill_scoped_worker'],
            allowedTools: ['file_reader']
        );
        $skillRegistry->register($skill);

        $delegator = new TeamDelegator(
            subAgentRegistry: $registry,
            skillRegistry: $skillRegistry
        );

        $state = RunState::create('Skill scoped tools test');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/unauthorized or missing tool \[patch_writer\]/i');

        $delegator->delegate(
            state: $state,
            parentAgentKey: 'lead',
            subAgentKeys: ['skill_scoped_worker'],
            context: [],
            tools: ['file_reader' => $reader, 'patch_writer' => $writer],
            llm: new FakeDriver(),
            activeSkillNames: ['read-only-audit']
        );
    }

    public function test_dry_run_propagation_to_tools(): void
    {
        $pathGuard = new PathGuard(projectRoot: $this->tempDir);
        $patchTool = new PatchWriterTool(pathGuard: $pathGuard);

        $sub = new class extends AbstractSubAgent {
            public function key(): string { return 'dry_runner'; }
            public function parentAgentKey(): string { return 'lead'; }
            public function role(): string { return 'Role'; }
            public function goal(): string { return 'Goal'; }
            public function backstory(): string { return 'Backstory'; }
            public function scopePaths(): array { return ['database/migrations/**']; }
            public function allowedTools(): array { return ['patch_writer']; }
            protected function buildUserPrompt(RunState $state, array $context = []): string { return 'Prompt'; }
            protected function getOutputSchema(): array { return ['type' => 'object']; }
            protected function applyOutput(RunState $state, array $output, array $tools): array {
                /** @var PatchWriterTool $tool */
                $tool = $this->getTool('patch_writer', $tools);
                $res = $tool->execute([
                    'path' => 'database/migrations/2026_01_01_dry_migration.php',
                    'action' => 'write',
                    'content' => '<?php // migration',
                    'summary' => 'Dry run migration',
                ]);
                return ['summary' => 'done', 'artifacts' => [$res]];
            }
        };

        $registry = new SubAgentRegistry();
        $registry->register($sub);

        $fakeLlm = new FakeDriver();
        $delegator = new TeamDelegator(subAgentRegistry: $registry);

        // State with dry_run = true
        $state = RunState::create('Dry run test', ['dry_run' => true]);

        $result = $delegator->delegate(
            state: $state,
            parentAgentKey: 'lead',
            subAgentKeys: ['dry_runner'],
            context: [],
            tools: ['patch_writer' => $patchTool],
            llm: $fakeLlm
        );

        $this->assertTrue($result->success);
        // Verify physical file was NOT created due to dry_run
        $this->assertFileDoesNotExist($this->tempDir . '/database/migrations/2026_01_01_dry_migration.php');
        // But artifact is recorded with attribution
        $artifacts = $result->state->artifacts;
        $this->assertNotEmpty($artifacts);
        $this->assertEquals('dry_runner', $artifacts[0]['agent']);
    }

    /**
     * Helper to create a dummy sub-agent.
     *
     * @param  array<int, string>  $scopePaths
     * @param  array<int, string>  $dependsOn
     */
    protected function createMockSubAgent(string $key, string $parent, array $scopePaths, array $dependsOn = []): SubAgentInterface
    {
        return new class($key, $parent, $scopePaths, $dependsOn) extends AbstractSubAgent {
            public function __construct(
                protected string $subKey,
                protected string $parentKey,
                protected array $scopes,
                protected array $deps
            ) {}
            public function key(): string { return $this->subKey; }
            public function parentAgentKey(): string { return $this->parentKey; }
            public function role(): string { return 'Role'; }
            public function goal(): string { return 'Goal'; }
            public function backstory(): string { return 'Backstory'; }
            public function scopePaths(): array { return $this->scopes; }
            public function dependsOn(): array { return $this->deps; }
            protected function buildUserPrompt(RunState $state, array $context = []): string { return 'Prompt'; }
            protected function getOutputSchema(): array { return ['type' => 'object']; }
            protected function applyOutput(RunState $state, array $output, array $tools): array { return ['summary' => 'ok']; }
        };
    }
}
