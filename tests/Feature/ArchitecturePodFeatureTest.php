<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Feature;

use Yousef\LaraOrcest\Agents\ArchitectAgent;
use Yousef\LaraOrcest\Agents\SubAgents\ApiContractAgent;
use Yousef\LaraOrcest\Agents\SubAgents\DddBoundaryAgent;
use Yousef\LaraOrcest\Agents\SubAgents\SchemaPlannerAgent;
use Yousef\LaraOrcest\Agents\Teams\SubAgentRegistry;
use Yousef\LaraOrcest\Graph\DefaultGraphBuilder;
use Yousef\LaraOrcest\LLM\Drivers\FakeDriver;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Support\Config;
use Yousef\LaraOrcest\Tests\TestCase;
use Yousef\LaraOrcest\Tools\CodebaseInspectorTool;
use Yousef\LaraOrcest\Tools\FileReaderTool;
use Yousef\LaraOrcest\Tools\ToolRegistry;

class ArchitecturePodFeatureTest extends TestCase
{
    public function test_architect_agent_delegates_to_sub_agents_and_synthesizes_technical_plan(): void
    {
        $architect = $this->app->make(ArchitectAgent::class);
        $this->assertInstanceOf(ArchitectAgent::class, $architect);

        $fakeLlm = new FakeDriver();
        $state = RunState::create('Design task management system', [
            'requirements_spec' => [
                'domain_entities' => ['Task', 'Project', 'User'],
                'functional_requirements' => ['Create tasks', 'Assign tasks to users'],
                'business_rules' => ['Task belongs to project'],
            ],
            'backlog' => [
                [
                    'id' => 'TASK-1',
                    'title' => 'Create migrations and models',
                    'depends_on' => [],
                    'scope_paths' => ['database/migrations/**', 'app/Models/**'],
                ],
                [
                    'id' => 'TASK-2',
                    'title' => 'Create controllers and routes',
                    'depends_on' => ['TASK-1'],
                    'scope_paths' => ['app/Http/Controllers/**', 'routes/**'],
                ],
            ],
        ]);

        $tools = [
            'file_reader' => $this->app->make(FileReaderTool::class),
            'codebase_inspector' => $this->app->make(CodebaseInspectorTool::class),
        ];

        Config::set('lara-orcest.teams.enabled', true);
        Config::set('lara-orcest.teams.parallel_sub_agents', false);

        $resultState = $architect->execute($state, $tools, $fakeLlm);

        // Verify technical plan is synthesized
        $plan = $resultState->technicalPlan;
        $this->assertNotEmpty($plan);
        $this->assertNotEmpty($plan);

        // 1. Schema Planner contributions
        $this->assertArrayHasKey('migrations', $plan);
        $this->assertArrayHasKey('create_projects_table', $plan['migrations']);
        $this->assertArrayHasKey('create_tasks_table', $plan['migrations']);

        // 2. Models
        $this->assertArrayHasKey('models', $plan);
        $this->assertArrayHasKey('Project', $plan['models']);
        $this->assertArrayHasKey('Task', $plan['models']);

        // 3. API Contract contributions
        $this->assertArrayHasKey('controllers', $plan);
        $this->assertArrayHasKey('ProjectController', $plan['controllers']);
        $this->assertArrayHasKey('TaskController', $plan['controllers']);

        $this->assertArrayHasKey('requests', $plan);
        $this->assertArrayHasKey('StoreTaskRequest', $plan['requests']);

        $this->assertArrayHasKey('resources', $plan);
        $this->assertArrayHasKey('ProjectResource', $plan['resources']);
        $this->assertArrayHasKey('TaskResource', $plan['resources']);

        $this->assertArrayHasKey('policies', $plan);
        $this->assertArrayHasKey('TaskPolicy', $plan['policies']);

        $this->assertArrayHasKey('routes', $plan);
        $this->assertContains('routes/api.php', $plan['routes']);

        // 4. DDD Boundary contributions
        $this->assertArrayHasKey('bounded_contexts', $plan);
        $this->assertCount(2, $plan['bounded_contexts']);
        $this->assertArrayHasKey('directory_structure', $plan);
        $this->assertArrayHasKey('architectural_rules', $plan);

        // 5. Execution steps and tests
        $this->assertArrayHasKey('execution_steps', $plan);
        $this->assertNotEmpty($plan['execution_steps']);
        $this->assertArrayHasKey('tests', $plan);
        $this->assertArrayHasKey('Feature: TaskController', $plan['tests']);
        $this->assertArrayHasKey('Unit: Task', $plan['tests']);

        // 6. Verify delegation tracking
        $delegations = $resultState->getDelegationsForAgent('architect');
        $this->assertCount(3, $delegations);

        $subKeys = array_column($delegations, 'sub_agent');
        $this->assertContains('ddd_boundary_agent', $subKeys);
        $this->assertContains('schema_planner_agent', $subKeys);
        $this->assertContains('api_contract_agent', $subKeys);

        foreach ($delegations as $d) {
            $this->assertEquals('completed', $d['status']);
            $this->assertNotEmpty($d['active_skills']);
        }
    }

    public function test_architect_agent_executes_with_parallel_waves_enabled(): void
    {
        $architect = $this->app->make(ArchitectAgent::class);
        $fakeLlm = new FakeDriver();
        $state = RunState::create('Design parallel architecture plan', [
            'requirements_spec' => ['domain_entities' => ['Item']],
            'backlog' => [['id' => 'TASK-1', 'title' => 'Item task']],
        ]);

        $tools = [
            'file_reader' => $this->app->make(FileReaderTool::class),
            'codebase_inspector' => $this->app->make(CodebaseInspectorTool::class),
        ];

        Config::set('lara-orcest.teams.enabled', true);
        Config::set('lara-orcest.teams.parallel_sub_agents', true);

        $resultState = $architect->execute($state, $tools, $fakeLlm);

        $this->assertNotEmpty($resultState->technicalPlan);
        $this->assertArrayHasKey('migrations', $resultState->technicalPlan);
        $this->assertArrayHasKey('controllers', $resultState->technicalPlan);

        $delegations = $resultState->getDelegationsForAgent('architect');
        $this->assertCount(3, $delegations);
    }

    public function test_architect_agent_falls_back_when_teams_disabled(): void
    {
        $architect = $this->app->make(ArchitectAgent::class);
        $fakeLlm = new FakeDriver();
        $state = RunState::create('Design system without teams');
        $tools = [
            'file_reader' => $this->app->make(FileReaderTool::class),
            'codebase_inspector' => $this->app->make(CodebaseInspectorTool::class),
        ];

        Config::set('lara-orcest.teams.enabled', false);

        $resultState = $architect->execute($state, $tools, $fakeLlm);

        $this->assertNotEmpty($resultState->technicalPlan);
        $this->assertArrayHasKey('migrations', $resultState->technicalPlan);
        $this->assertArrayHasKey('models', $resultState->technicalPlan);

        // Fallback execution should not record sub-agent delegations
        $delegations = $resultState->getDelegationsForAgent('architect');
        $this->assertEmpty($delegations);
    }

    public function test_sub_agent_model_routing_and_fallbacks(): void
    {
        Config::set('lara-orcest.llm.agent_models.architect', 'anthropic/claude-3-5-sonnet');
        Config::set('lara-orcest.llm.agent_models.ddd_boundary_agent', 'openai/o3-mini');
        Config::set('lara-orcest.llm.agent_models.schema_planner_agent', null);

        $this->assertEquals('openai/o3-mini', Config::get('lara-orcest.llm.agent_models.ddd_boundary_agent'));
        $this->assertNull(Config::get('lara-orcest.llm.agent_models.schema_planner_agent'));
        $this->assertEquals('anthropic/claude-3-5-sonnet', Config::get('lara-orcest.llm.agent_models.architect'));
    }

    public function test_service_provider_registers_tools_and_sub_agents(): void
    {
        // 1. Tool singleton & binding
        $this->assertTrue($this->app->bound(CodebaseInspectorTool::class));
        $inspector = $this->app->make(CodebaseInspectorTool::class);
        $this->assertInstanceOf(CodebaseInspectorTool::class, $inspector);

        // 2. Sub-agent bindings
        $this->assertTrue($this->app->bound(DddBoundaryAgent::class));
        $this->assertTrue($this->app->bound(SchemaPlannerAgent::class));
        $this->assertTrue($this->app->bound(ApiContractAgent::class));

        // 3. SubAgentRegistry contains all 3
        $subRegistry = $this->app->make(SubAgentRegistry::class);
        $this->assertTrue($subRegistry->has('ddd_boundary_agent'));
        $this->assertTrue($subRegistry->has('schema_planner_agent'));
        $this->assertTrue($subRegistry->has('api_contract_agent'));

        // 4. ToolRegistry contains codebase_inspector
        $toolRegistry = $this->app->make(ToolRegistry::class);
        $this->assertTrue($toolRegistry->has('codebase_inspector'));

        // 5. DefaultGraphBuilder defaultTools includes codebase_inspector
        $defaultTools = DefaultGraphBuilder::defaultTools();
        $this->assertArrayHasKey('codebase_inspector', $defaultTools);
        $this->assertInstanceOf(CodebaseInspectorTool::class, $defaultTools['codebase_inspector']);
    }
}
