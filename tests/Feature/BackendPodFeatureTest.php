<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Feature;

use Yousef\LaraOrcest\Agents\BackendAgent;
use Yousef\LaraOrcest\Agents\SubAgents\ControllerResourceAgent;
use Yousef\LaraOrcest\Agents\SubAgents\RequestValidationAgent;
use Yousef\LaraOrcest\Agents\SubAgents\ServiceActionAgent;
use Yousef\LaraOrcest\Agents\Teams\SubAgentRegistry;
use Yousef\LaraOrcest\LLM\Drivers\FakeDriver;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Support\Config;
use Yousef\LaraOrcest\Tests\TestCase;
use Yousef\LaraOrcest\Tools\ArtisanRunnerTool;
use Yousef\LaraOrcest\Tools\FileReaderTool;
use Yousef\LaraOrcest\Tools\PatchWriterTool;
use Yousef\LaraOrcest\Tools\Support\PathGuard;

class BackendPodFeatureTest extends TestCase
{
    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/lara_orcest_be_feat_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir . '/app/Http/Requests', 0777, true);
        mkdir($this->tempDir . '/app/Policies', 0777, true);
        mkdir($this->tempDir . '/app/Actions', 0777, true);
        mkdir($this->tempDir . '/app/Services', 0777, true);
        mkdir($this->tempDir . '/app/Http/Controllers/Api', 0777, true);
        mkdir($this->tempDir . '/app/Http/Resources', 0777, true);
        mkdir($this->tempDir . '/routes', 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tempDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $file) {
                if ($file->isDir()) {
                    @rmdir($file->getRealPath());
                } else {
                    @unlink($file->getRealPath());
                }
            }
            @rmdir($this->tempDir);
        }
        parent::tearDown();
    }

    public function test_backend_agent_delegates_to_sub_agents_in_sequential_waves(): void
    {
        $backendAgent = $this->app->make(BackendAgent::class);
        $this->assertInstanceOf(BackendAgent::class, $backendAgent);

        $fakeLlm = new FakeDriver();
        $guard = new PathGuard($this->tempDir);
        $tools = [
            'file_reader' => new FileReaderTool($guard),
            'patch_writer' => new PatchWriterTool(pathGuard: $guard),
            'artisan_runner' => new ArtisanRunnerTool($this->tempDir),
        ];

        Config::set('lara-orcest.teams.enabled', true);

        $state = RunState::create('Implement backend layer for task management', [
            'technical_plan' => [
                'controllers' => [
                    'TaskController' => 'app/Http/Controllers/Api/TaskController.php',
                ],
                'requests' => [
                    'StoreTaskRequest' => 'app/Http/Requests/StoreTaskRequest.php',
                ],
                'resources' => [
                    'TaskResource' => 'app/Http/Resources/TaskResource.php',
                ],
                'routes' => [
                    'routes/api.php',
                ],
                'execution_steps' => [
                    'Implement FormRequests and Policies',
                    'Implement single-responsibility Actions and Services',
                    'Implement thin controllers and JsonResources',
                ],
            ],
            'backlog' => [
                ['id' => 'TASK-1', 'title' => 'Task endpoints'],
            ],
        ]);

        $resultState = $backendAgent->execute($state, $tools, $fakeLlm);

        // 1. Verify delegations tracked for backend_agent
        $delegations = $resultState->getDelegationsForAgent('backend_agent');
        $this->assertCount(3, $delegations);

        $subKeys = array_column($delegations, 'sub_agent');
        $this->assertEquals(['request_validation_agent', 'service_action_agent', 'controller_resource_agent'], $subKeys);

        foreach ($delegations as $d) {
            $this->assertEquals('completed', $d['status']);
            $this->assertNotEmpty($d['active_skills']);
        }

        // 2. Verify artifacts collected in RunState with sub-agent attribution
        $artifacts = $resultState->artifacts;
        $this->assertNotEmpty($artifacts);

        $paths = array_column($artifacts, 'path');
        $this->assertContains('app/Http/Requests/StoreTaskRequest.php', $paths);
        $this->assertContains('app/Policies/TaskPolicy.php', $paths);
        $this->assertContains('app/Actions/CreateTaskAction.php', $paths);
        $this->assertContains('app/Services/TaskAssignmentService.php', $paths);
        $this->assertContains('app/Http/Controllers/Api/TaskController.php', $paths);
        $this->assertContains('app/Http/Resources/TaskResource.php', $paths);
        $this->assertContains('routes/api.php', $paths);

        // Sub-agent attribution
        $reqArts = array_filter($artifacts, fn ($a) => ($a['sub_agent'] ?? $a['agent'] ?? '') === 'request_validation_agent');
        $this->assertCount(2, $reqArts);

        $actArts = array_filter($artifacts, fn ($a) => ($a['sub_agent'] ?? $a['agent'] ?? '') === 'service_action_agent');
        $this->assertCount(2, $actArts);

        $ctrlArts = array_filter($artifacts, fn ($a) => ($a['sub_agent'] ?? $a['agent'] ?? '') === 'controller_resource_agent');
        $this->assertCount(3, $ctrlArts);

        // 3. Verify files actually written to disk
        $this->assertFileExists($this->tempDir . '/app/Http/Requests/StoreTaskRequest.php');
        $this->assertFileExists($this->tempDir . '/app/Actions/CreateTaskAction.php');
        $this->assertFileExists($this->tempDir . '/app/Http/Controllers/Api/TaskController.php');
        $this->assertFileExists($this->tempDir . '/routes/api.php');
    }

    public function test_backend_agent_dry_run_mode_does_not_mutate_disk(): void
    {
        $backendAgent = $this->app->make(BackendAgent::class);
        $fakeLlm = new FakeDriver();
        $guard = new PathGuard($this->tempDir);
        $tools = [
            'file_reader' => new FileReaderTool($guard),
            'patch_writer' => new PatchWriterTool(pathGuard: $guard),
            'artisan_runner' => new ArtisanRunnerTool($this->tempDir),
        ];

        Config::set('lara-orcest.teams.enabled', true);

        $state = RunState::create('Preview backend implementation without disk changes', [
            'dry_run' => true,
            'technical_plan' => [
                'controllers' => ['TaskController' => 'app/Http/Controllers/Api/TaskController.php'],
            ],
            'backlog' => [['id' => 'TASK-1', 'title' => 'Task endpoints']],
        ]);

        $resultState = $backendAgent->execute($state, $tools, $fakeLlm);

        // Delegations should still complete
        $delegations = $resultState->getDelegationsForAgent('backend_agent');
        $this->assertCount(3, $delegations);

        // Artifacts recorded as preview
        $artifacts = $resultState->artifacts;
        $this->assertNotEmpty($artifacts);
        foreach ($artifacts as $art) {
            $this->assertEquals('preview', $art['action']);
        }

        // No files actually written to disk in dry-run mode
        $this->assertFileDoesNotExist($this->tempDir . '/app/Http/Requests/StoreTaskRequest.php');
        $this->assertFileDoesNotExist($this->tempDir . '/app/Actions/CreateTaskAction.php');
        $this->assertFileDoesNotExist($this->tempDir . '/app/Http/Controllers/Api/TaskController.php');
    }

    public function test_backend_agent_falls_back_when_teams_disabled(): void
    {
        $backendAgent = $this->app->make(BackendAgent::class);
        $fakeLlm = new FakeDriver();
        $guard = new PathGuard($this->tempDir);
        $tools = [
            'file_reader' => new FileReaderTool($guard),
            'patch_writer' => new PatchWriterTool(pathGuard: $guard),
            'artisan_runner' => new ArtisanRunnerTool($this->tempDir),
        ];

        Config::set('lara-orcest.teams.enabled', false);

        $state = RunState::create('Single agent backend execution');
        $resultState = $backendAgent->execute($state, $tools, $fakeLlm);

        // No sub-agent delegations when teams are disabled
        $delegations = $resultState->getDelegationsForAgent('backend_agent');
        $this->assertEmpty($delegations);

        // Artifacts produced via parent fallback execution
        $this->assertNotEmpty($resultState->artifacts);
    }

    public function test_backend_sub_agent_model_routing_and_fallbacks(): void
    {
        Config::set('lara-orcest.llm.agent_models.backend_agent', 'anthropic/claude-3-5-sonnet');
        Config::set('lara-orcest.llm.agent_models.request_validation_agent', 'openai/gpt-4o');
        Config::set('lara-orcest.llm.agent_models.service_action_agent', null);
        Config::set('lara-orcest.llm.agent_models.controller_resource_agent', null);

        $reqAgent = new RequestValidationAgent();
        $actAgent = new ServiceActionAgent();
        $ctrlAgent = new ControllerResourceAgent();

        $this->assertEquals('openai/gpt-4o', $reqAgent->resolveModel());
        $this->assertEquals('anthropic/claude-3-5-sonnet', $actAgent->resolveModel());
        $this->assertEquals('anthropic/claude-3-5-sonnet', $ctrlAgent->resolveModel());
    }

    public function test_service_provider_registers_backend_sub_agents(): void
    {
        // 1. Container bindings
        $this->assertTrue($this->app->bound(RequestValidationAgent::class));
        $this->assertTrue($this->app->bound(ServiceActionAgent::class));
        $this->assertTrue($this->app->bound(ControllerResourceAgent::class));

        // 2. SubAgentRegistry contains all three
        $subRegistry = $this->app->make(SubAgentRegistry::class);
        $this->assertTrue($subRegistry->has('request_validation_agent'));
        $this->assertTrue($subRegistry->has('service_action_agent'));
        $this->assertTrue($subRegistry->has('controller_resource_agent'));

        // 3. Dependency ordering in registry
        $req = $subRegistry->get('request_validation_agent');
        $this->assertEquals([], $req->dependsOn());

        $act = $subRegistry->get('service_action_agent');
        $this->assertEquals(['request_validation_agent'], $act->dependsOn());

        $ctrl = $subRegistry->get('controller_resource_agent');
        $this->assertEquals(['service_action_agent'], $ctrl->dependsOn());
    }
}
