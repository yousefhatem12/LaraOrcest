<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Feature;

use Yousef\LaraOrcest\Agents\QaAgent;
use Yousef\LaraOrcest\Agents\ReviewerAgent;
use Yousef\LaraOrcest\Agents\SubAgents\FeatureTestAgent;
use Yousef\LaraOrcest\Agents\SubAgents\FixerSubAgent;
use Yousef\LaraOrcest\Agents\SubAgents\GitSubAgent;
use Yousef\LaraOrcest\Agents\SubAgents\SwaggerSubAgent;
use Yousef\LaraOrcest\Agents\Teams\SubAgentRegistry;
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

class QualityReleasePodFeatureTest extends TestCase
{
    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/lara_orcest_qr_feat_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir . '/tests/Feature', 0777, true);
        mkdir($this->tempDir . '/tests/Unit', 0777, true);
        mkdir($this->tempDir . '/app/Http/Requests', 0777, true);
        mkdir($this->tempDir . '/app/Http/Controllers/Api', 0777, true);
        mkdir($this->tempDir . '/docs', 0777, true);
    }

    protected function tearDown(): void
    {
        Config::set('lara-orcest.teams.enabled', true);
        Config::set('lara-orcest.swagger.enabled', true);

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

    public function test_qa_agent_delegates_to_feature_test_agent_and_records_test_results(): void
    {
        $qaAgent = $this->app->make(QaAgent::class);
        $this->assertInstanceOf(QaAgent::class, $qaAgent);

        $fakeLlm = new FakeDriver();
        $guard = new PathGuard($this->tempDir);
        $tools = [
            'file_reader' => new FileReaderTool($guard),
            'patch_writer' => new PatchWriterTool(pathGuard: $guard),
            'test_runner' => new class($this->tempDir) extends TestRunnerTool {
                public function execute(array $params): array
                {
                    return [
                        'passed' => true,
                        'exit_code' => 0,
                        'duration' => 0.05,
                        'summary' => 'Tests passed',
                        'output' => 'OK (1 test, 1 assertion)',
                    ];
                }
            },
            'artisan_runner' => new ArtisanRunnerTool($this->tempDir),
        ];

        Config::set('lara-orcest.teams.enabled', true);

        $state = RunState::create('Write tests and verify task management', [
            'backlog' => [
                [
                    'id' => 'TASK-1',
                    'title' => 'Task endpoints',
                    'acceptance_criteria' => ['GET /api/tasks returns 200'],
                ],
            ],
            'technical_plan' => [
                'controllers' => ['TaskController' => 'app/Http/Controllers/Api/TaskController.php'],
            ],
        ]);

        $resultState = $qaAgent->execute($state, $tools, $fakeLlm);

        // 1. Verify delegation recorded for qa_agent -> feature_test_agent
        $delegations = $resultState->getDelegationsForAgent('qa_agent');
        $this->assertNotEmpty($delegations);
        $this->assertEquals('feature_test_agent', $delegations[0]['sub_agent']);
        $this->assertContains('laravel-feature-testing', $delegations[0]['active_skills']);

        // 2. Verify test file was written to disk
        $this->assertFileExists($this->tempDir . '/tests/Feature/TaskApiTest.php');

        // 3. Verify testResults recorded in RunState
        $this->assertNotEmpty($resultState->testResults);
        $this->assertTrue($resultState->areTestsPassing());
    }

    public function test_qa_agent_triggers_fixer_agent_when_tests_fail_and_can_attempt_fix(): void
    {
        $qaAgent = $this->app->make(QaAgent::class);

        $fakeLlm = new FakeDriver();
        $guard = new PathGuard($this->tempDir);

        // Create a mock TestRunnerTool that fails on first run and passes on second run
        $mockTestRunner = new class($this->tempDir) extends TestRunnerTool {
            public int $runCount = 0;

            public function execute(array $params): array
            {
                $this->runCount++;
                if ($this->runCount === 1) {
                    return [
                        'passed' => false,
                        'exit_code' => 1,
                        'duration' => 0.1,
                        'summary' => '1 test failed: StoreTaskRequest validation error',
                        'output' => 'Failed asserting that 422 matches 201',
                    ];
                }

                return [
                    'passed' => true,
                    'exit_code' => 0,
                    'duration' => 0.1,
                    'summary' => 'All tests passed after fixer patch',
                    'output' => 'OK (1 test, 2 assertions)',
                ];
            }
        };

        $tools = [
            'file_reader' => new FileReaderTool($guard),
            'patch_writer' => new PatchWriterTool(pathGuard: $guard),
            'test_runner' => $mockTestRunner,
            'artisan_runner' => new ArtisanRunnerTool($this->tempDir),
        ];

        Config::set('lara-orcest.teams.enabled', true);

        $state = RunState::create('Execute and debug task management tests', [
            'backlog' => [['id' => 'TASK-1', 'title' => 'Task endpoints']],
            'technical_plan' => ['controllers' => ['TaskController' => 'app/Http/Controllers/Api/TaskController.php']],
        ]);

        $resultState = $qaAgent->execute($state, $tools, $fakeLlm);

        // 1. Verify fixer_agent was invoked
        $delegations = $resultState->getDelegationsForAgent('qa_agent');
        $this->assertCount(2, $delegations);
        $this->assertEquals('feature_test_agent', $delegations[0]['sub_agent']);
        $this->assertEquals('fixer_agent', $delegations[1]['sub_agent']);
        $this->assertContains('root-cause-test-fixing', $delegations[1]['active_skills']);

        // 2. Verify fix attempt was incremented
        $this->assertEquals(1, $resultState->guardrails['fix_attempts']);

        // 3. Verify final testResults are passing after retry
        $this->assertTrue($resultState->areTestsPassing());
        $this->assertStringContainsString('All tests passed after fixer patch', $resultState->testResults['summary']);
    }

    public function test_reviewer_agent_delegates_to_swagger_and_git_subagents(): void
    {
        $reviewerAgent = $this->app->make(ReviewerAgent::class);
        $this->assertInstanceOf(ReviewerAgent::class, $reviewerAgent);

        $fakeLlm = new FakeDriver();
        $guard = new PathGuard($this->tempDir);
        $tools = [
            'file_reader' => new FileReaderTool($guard),
            'patch_writer' => new PatchWriterTool(pathGuard: $guard),
            'git_command' => new GitCommandTool($this->tempDir, dryRun: true, pathGuard: $guard),
            'artisan_runner' => new ArtisanRunnerTool($this->tempDir),
        ];

        Config::set('lara-orcest.teams.enabled', true);
        Config::set('lara-orcest.swagger.enabled', true);

        $state = RunState::create('Review and finalize task management API', [
            'technical_plan' => [
                'routes' => ['routes/api.php'],
                'controllers' => ['TaskController' => 'app/Http/Controllers/Api/TaskController.php'],
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
        ]);

        $resultState = $reviewerAgent->execute($state, $tools, $fakeLlm);

        // 1. Review was approved
        $this->assertTrue($resultState->isReviewApproved());

        // 2. Swagger delegation occurred and file written
        $reviewerDelegations = $resultState->getDelegationsForAgent('reviewer_agent');
        $subAgentKeys = array_column($reviewerDelegations, 'sub_agent');
        $this->assertContains('swagger_agent', $subAgentKeys);
        $this->assertContains('git_agent', $subAgentKeys);

        // 3. Verify Swagger doc exists
        $this->assertFileExists($this->tempDir . '/docs/swagger.json');

        // 4. Verify Git state was updated
        $this->assertNotEmpty($resultState->gitState);
        $this->assertStringStartsWith('orcest/', $resultState->gitState['feature_branch']);
    }

    public function test_dry_run_mode_is_honored_across_quality_release_pod(): void
    {
        $qaAgent = $this->app->make(QaAgent::class);
        $reviewerAgent = $this->app->make(ReviewerAgent::class);

        $fakeLlm = new FakeDriver();
        $guard = new PathGuard($this->tempDir);
        $tools = [
            'file_reader' => new FileReaderTool($guard),
            'patch_writer' => new PatchWriterTool(pathGuard: $guard, dryRun: true),
            'test_runner' => new TestRunnerTool($this->tempDir, dryRun: true),
            'git_command' => new GitCommandTool($this->tempDir, dryRun: true, pathGuard: $guard),
            'artisan_runner' => new ArtisanRunnerTool($this->tempDir),
        ];

        Config::set('lara-orcest.teams.enabled', true);

        $state = RunState::create('Dry run audit of quality and release pod', [
            'dry_run' => true,
            'technical_plan' => ['routes' => ['routes/api.php']],
        ]);

        $qaResult = $qaAgent->execute($state, $tools, $fakeLlm);
        $this->assertTrue($qaResult->isDryRun());

        // Files in dry run are previewed rather than written to real disk
        $reviewResult = $reviewerAgent->execute($qaResult, $tools, $fakeLlm);
        $this->assertTrue($reviewResult->isDryRun());
        $this->assertTrue($reviewResult->isReviewApproved());
    }

    public function test_fallback_to_single_agent_when_teams_disabled(): void
    {
        $qaAgent = $this->app->make(QaAgent::class);
        $reviewerAgent = $this->app->make(ReviewerAgent::class);

        $fakeLlm = new FakeDriver();
        $guard = new PathGuard($this->tempDir);
        $tools = [
            'file_reader' => new FileReaderTool($guard),
            'patch_writer' => new PatchWriterTool(pathGuard: $guard),
            'test_runner' => new class($this->tempDir) extends TestRunnerTool {
                public function execute(array $params): array
                {
                    return [
                        'passed' => true,
                        'exit_code' => 0,
                        'duration' => 0.05,
                        'summary' => 'Passed',
                        'output' => 'OK',
                    ];
                }
            },
            'git_command' => new GitCommandTool($this->tempDir, dryRun: true, pathGuard: $guard),
            'artisan_runner' => new ArtisanRunnerTool($this->tempDir),
        ];

        Config::set('lara-orcest.teams.enabled', false);

        $state = RunState::create('Single agent fallback execution', [
            'test_results' => ['passed' => true, 'exit_code' => 0, 'summary' => 'Passed'],
        ]);

        $qaResult = $qaAgent->execute($state, $tools, $fakeLlm);
        $this->assertEmpty($qaResult->getDelegationsForAgent('qa_agent'));
        $this->assertTrue($qaResult->areTestsPassing());

        $reviewResult = $reviewerAgent->execute($qaResult, $tools, $fakeLlm);
        $this->assertEmpty($reviewResult->getDelegationsForAgent('reviewer_agent'));
        $this->assertTrue($reviewResult->isReviewApproved());
    }
}
