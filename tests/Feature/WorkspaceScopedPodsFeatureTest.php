<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Feature;

use Yousef\LaraOrcest\Agents\BackendAgent;
use Yousef\LaraOrcest\Agents\DatabaseAgent;
use Yousef\LaraOrcest\Agents\QaAgent;
use Yousef\LaraOrcest\Agents\ReviewerAgent;
use Yousef\LaraOrcest\LLM\Drivers\FakeDriver;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Support\Config;
use Yousef\LaraOrcest\Tests\TestCase;
use Yousef\LaraOrcest\Tools\ArtisanRunnerTool;
use Yousef\LaraOrcest\Tools\FileReaderTool;
use Yousef\LaraOrcest\Tools\GitCommandTool;
use Yousef\LaraOrcest\Tools\PatchWriterTool;
use Yousef\LaraOrcest\Tools\Support\PathGuard;
use Yousef\LaraOrcest\Tools\TestRunnerTool;
use Yousef\LaraOrcest\Workspace\WorkspaceContext;

class WorkspaceScopedPodsFeatureTest extends TestCase
{
    protected string $tempDir;
    protected WorkspaceContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/lara_orcest_scoped_pods_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0755, true);
        mkdir($this->tempDir . '/app/Models', 0755, true);
        mkdir($this->tempDir . '/database/migrations', 0755, true);
        mkdir($this->tempDir . '/database/factories', 0755, true);
        mkdir($this->tempDir . '/database/seeders', 0755, true);
        mkdir($this->tempDir . '/app/Http/Controllers', 0755, true);
        mkdir($this->tempDir . '/app/Http/Requests', 0755, true);
        mkdir($this->tempDir . '/app/Http/Resources', 0755, true);
        mkdir($this->tempDir . '/app/Actions', 0755, true);
        mkdir($this->tempDir . '/routes', 0755, true);
        mkdir($this->tempDir . '/tests/Feature', 0755, true);
        mkdir($this->tempDir . '/docs', 0755, true);

        // Put dummy artisan script in target workspace
        file_put_contents($this->tempDir . '/artisan', "#!/usr/bin/env php\n<?php exit(0);\n");
        chmod($this->tempDir . '/artisan', 0755);

        // Put minimal composer.json
        file_put_contents($this->tempDir . '/composer.json', json_encode(['name' => 'test/app', 'require' => new \stdClass()]));

        // Put initial routes/api.php
        file_put_contents($this->tempDir . '/routes/api.php', "<?php\n\nuse Illuminate\Support\Facades\Route;\n");

        $this->context = WorkspaceContext::standalone($this->tempDir);
        $this->context->ensureDirectoriesExist();

        Config::set('lara-orcest.teams.enabled', true);
        Config::set('lara-orcest.swagger.enabled', true);
        Config::set('lara-orcest.git.auto_init', true);
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

    public function test_database_pod_writes_strictly_inside_target_project_path(): void
    {
        $dbAgent = $this->app->make(DatabaseAgent::class);
        $fakeLlm = new FakeDriver();
        $tools = $this->context->createTools();

        $state = RunState::create('Implement project database layer', [
            'target_project_path' => $this->tempDir,
            'workspace_mode' => 'standalone',
            'technical_plan' => [
                'migrations' => [
                    'create_projects_table' => 'database/migrations/2026_01_01_000001_create_projects_table.php',
                ],
                'models' => [
                    'Project' => 'app/Models/Project.php',
                ],
                'seeders' => [
                    'ProjectSeeder' => 'database/seeders/ProjectSeeder.php',
                ],
            ],
        ]);

        $resultState = $dbAgent->execute($state, $tools, $fakeLlm);

        // Verify artifacts exist on target disk
        $this->assertFileExists($this->tempDir . '/database/migrations/2026_01_01_000001_create_projects_table.php');
        $this->assertFileExists($this->tempDir . '/app/Models/Project.php');
        $this->assertFileExists($this->tempDir . '/database/seeders/ProjectSeeder.php');

        // Verify artifacts recorded in RunState have paths relative to targetProjectPath
        foreach ($resultState->artifacts as $art) {
            $this->assertFalse(str_starts_with($art['path'], '/'), "Artifact path [{$art['path']}] must be relative.");
            $this->assertFileExists($this->tempDir . '/' . $art['path']);
        }

        // Verify subagent delegations are recorded
        $delegations = $resultState->getDelegationsForAgent('database_agent');
        $this->assertNotEmpty($delegations);
        $keys = array_column($delegations, 'sub_agent');
        $this->assertContains('migration_agent', $keys);
        $this->assertContains('model_eloquent_agent', $keys);
        $this->assertContains('seeder_agent', $keys);
    }

    public function test_backend_pod_writes_strictly_inside_target_project_path(): void
    {
        $backendAgent = $this->app->make(BackendAgent::class);
        $fakeLlm = new FakeDriver();
        $tools = $this->context->createTools();

        $state = RunState::create('Implement task API and actions', [
            'target_project_path' => $this->tempDir,
            'workspace_mode' => 'standalone',
            'technical_plan' => [
                'requests' => [
                    'StoreTaskRequest' => 'app/Http/Requests/StoreTaskRequest.php',
                ],
                'actions' => [
                    'CreateTaskAction' => 'app/Actions/CreateTaskAction.php',
                ],
                'controllers' => [
                    'TaskController' => 'app/Http/Controllers/Api/TaskController.php',
                ],
                'routes' => ['routes/api.php'],
            ],
            'artifacts' => [
                ['path' => 'app/Models/Task.php', 'action' => 'created', 'summary' => 'Task Eloquent model'],
            ],
        ]);

        $resultState = $backendAgent->execute($state, $tools, $fakeLlm);

        // Verify backend files exist inside targetProjectPath
        $this->assertFileExists($this->tempDir . '/app/Http/Requests/StoreTaskRequest.php');
        $this->assertFileExists($this->tempDir . '/app/Actions/CreateTaskAction.php');
        $this->assertFileExists($this->tempDir . '/app/Http/Controllers/Api/TaskController.php');
        $this->assertFileExists($this->tempDir . '/routes/api.php');

        // Verify subagent delegations
        $delegations = $resultState->getDelegationsForAgent('backend_agent');
        $keys = array_column($delegations, 'sub_agent');
        $this->assertContains('request_validation_agent', $keys);
        $this->assertContains('service_action_agent', $keys);
        $this->assertContains('controller_resource_agent', $keys);
    }

    public function test_qa_pod_writes_tests_and_executes_test_runner_inside_target_project_path(): void
    {
        $qaAgent = $this->app->make(QaAgent::class);
        $fakeLlm = new FakeDriver();
        $tools = $this->context->createTools();

        // Put a mock test runner that succeeds
        $tools['test_runner'] = new class($this->tempDir, workspaceContext: $this->context) extends TestRunnerTool {
            public function execute(array $params): array
            {
                return [
                    'passed' => true,
                    'exit_code' => 0,
                    'duration' => 0.05,
                    'summary' => 'Tests: 1 passed in targetProjectPath',
                    'output' => 'OK (1 test, 2 assertions)',
                    'failures' => [],
                    'timed_out' => false,
                    'dry_run' => false,
                ];
            }
        };

        $state = RunState::create('Write tests for task management', [
            'target_project_path' => $this->tempDir,
            'workspace_mode' => 'standalone',
            'backlog' => [
                ['id' => 'TASK-1', 'title' => 'Task management API', 'acceptance_criteria' => ['GET /api/tasks returns 200']],
            ],
            'technical_plan' => [
                'controllers' => ['TaskController' => 'app/Http/Controllers/Api/TaskController.php'],
            ],
            'artifacts' => [
                ['path' => 'app/Http/Controllers/Api/TaskController.php', 'action' => 'created', 'summary' => 'TaskController'],
            ],
        ]);

        $resultState = $qaAgent->execute($state, $tools, $fakeLlm);

        // Verify feature test was written inside targetProjectPath
        $this->assertFileExists($this->tempDir . '/tests/Feature/TaskApiTest.php');

        // Verify test runner result was recorded cleanly
        $this->assertTrue($resultState->areTestsPassing());
        $this->assertStringContainsString('targetProjectPath', $resultState->testResults['summary']);

        // Verify feature_test_agent delegation recorded
        $delegations = $resultState->getDelegationsForAgent('qa_agent');
        $this->assertNotEmpty($delegations);
        $this->assertEquals('feature_test_agent', $delegations[0]['sub_agent']);
    }

    public function test_reviewer_pod_generates_swagger_and_git_commit_inside_target_project_path(): void
    {
        $reviewerAgent = $this->app->make(ReviewerAgent::class);
        $fakeLlm = new FakeDriver();
        $tools = $this->context->createTools();

        $state = RunState::create('Review and finalize invoice release', [
            'target_project_path' => $this->tempDir,
            'workspace_mode' => 'standalone',
            'technical_plan' => [
                'routes' => ['routes/api.php'],
                'controllers' => ['InvoiceController' => 'app/Http/Controllers/InvoiceController.php'],
            ],
            'test_results' => [
                'passed' => true,
                'exit_code' => 0,
                'summary' => 'Tests passed',
            ],
            'swagger' => ['enabled' => true],
            'metadata' => [
                'swagger' => ['enabled' => true, 'path' => 'docs/swagger.json'],
            ],
            'artifacts' => [
                ['path' => 'app/Http/Controllers/InvoiceController.php', 'action' => 'created', 'summary' => 'InvoiceController'],
            ],
        ]);

        $resultState = $reviewerAgent->execute($state, $tools, $fakeLlm);

        $this->assertTrue($resultState->isReviewApproved());

        // Verify Swagger docs written inside target workspace
        $this->assertFileExists($this->tempDir . '/docs/swagger.json');

        // Verify Git feature branch was created inside target workspace
        $this->assertNotEmpty($resultState->gitState);
        $this->assertStringStartsWith('orcest/', $resultState->gitState['feature_branch']);
        $this->assertTrue($resultState->gitState['branch_created']);
    }

    public function test_single_agent_fallback_runs_cleanly_in_target_project_path(): void
    {
        Config::set('lara-orcest.teams.enabled', false);

        $dbAgent = $this->app->make(DatabaseAgent::class);
        $backendAgent = $this->app->make(BackendAgent::class);
        $qaAgent = $this->app->make(QaAgent::class);
        $reviewerAgent = $this->app->make(ReviewerAgent::class);

        $fakeLlm = new FakeDriver();
        $tools = $this->context->createTools();
        $tools['test_runner'] = new class($this->tempDir, workspaceContext: $this->context) extends TestRunnerTool {
            public function execute(array $params): array
            {
                return [
                    'passed' => true,
                    'exit_code' => 0,
                    'duration' => 0.05,
                    'summary' => 'Single agent tests passed',
                    'output' => 'OK',
                    'failures' => [],
                    'timed_out' => false,
                    'dry_run' => false,
                ];
            }
        };

        $state = RunState::create('Single agent pipeline execution', [
            'target_project_path' => $this->tempDir,
            'workspace_mode' => 'standalone',
            'technical_plan' => [
                'migrations' => ['create_projects_table' => 'database/migrations/2026_01_01_000001_create_projects_table.php'],
                'models' => ['Project' => 'app/Models/Project.php'],
                'controllers' => ['TaskController' => 'app/Http/Controllers/Api/TaskController.php'],
            ],
        ]);

        // 1. Database
        $state = $dbAgent->execute($state, $tools, $fakeLlm);
        $this->assertEmpty($state->getDelegationsForAgent('database_agent'));
        $this->assertFileExists($this->tempDir . '/app/Models/Project.php');

        // 2. Backend
        $state = $backendAgent->execute($state, $tools, $fakeLlm);
        $this->assertEmpty($state->getDelegationsForAgent('backend_agent'));
        $this->assertFileExists($this->tempDir . '/app/Http/Controllers/Api/TaskController.php');

        // 3. QA
        $state = $qaAgent->execute($state, $tools, $fakeLlm);
        $this->assertEmpty($state->getDelegationsForAgent('qa_agent'));
        $this->assertTrue($state->areTestsPassing());

        // 4. Reviewer
        $state = $reviewerAgent->execute($state, $tools, $fakeLlm);
        $this->assertEmpty($state->getDelegationsForAgent('reviewer_agent'));
        $this->assertTrue($state->isReviewApproved());
    }
}
