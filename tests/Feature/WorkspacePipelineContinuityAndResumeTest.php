<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Feature;

use Yousef\LaraOrcest\LLM\Drivers\FakeDriver;
use Yousef\LaraOrcest\LLM\LlmManager;
use Yousef\LaraOrcest\Orchestrator;
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

class WorkspacePipelineContinuityAndResumeTest extends TestCase
{
    protected string $tempDir;
    protected WorkspaceContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/lara_orcest_pipe_resume_' . bin2hex(random_bytes(6));
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
        Config::set('lara-orcest.git.auto_commit', true);
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

    public function test_full_pipeline_orchestration_executes_against_target_project_path(): void
    {
        $llmManager = new LlmManager();
        $orchestrator = new Orchestrator(llmManager: $llmManager, workspaceContext: $this->context);

        $prompt = 'Build task management API with projects and tasks';
        $finalState = $orchestrator->run($prompt, [
            'target_project_path' => $this->tempDir,
            'llm' => 'fake',
            'no_human_review' => true,
        ]);

        // 1. Pipeline reached completed status
        $this->assertSame(RunState::STATUS_COMPLETED, $finalState->status);
        $this->assertSame($this->tempDir, $finalState->targetProjectPath);
        $this->assertSame('standalone', $finalState->workspaceMode);

        // 2. Checkpoint was persisted inside targetProjectPath/.lara-orcest/state
        $checkpointFile = $this->tempDir . '/.lara-orcest/state/' . $finalState->runId . '.json';
        $this->assertFileExists($checkpointFile);

        // 3. Artifacts generated have relative paths and exist on disk
        $this->assertNotEmpty($finalState->artifacts);
        foreach ($finalState->artifacts as $art) {
            $this->assertFalse(str_starts_with($art['path'], '/'));
            $this->assertFileExists($this->tempDir . '/' . $art['path']);
        }

        // 4. Git state updated with feature branch
        $this->assertNotEmpty($finalState->gitState);
        $this->assertStringStartsWith('orcest/', $finalState->gitState['feature_branch']);
    }

    public function test_dry_run_pipeline_leaves_disk_unmutated_in_target_project_path(): void
    {
        $llmManager = new LlmManager();
        $orchestrator = new Orchestrator(llmManager: $llmManager, workspaceContext: $this->context);

        $prompt = 'Dry run preview of notification system';
        $finalState = $orchestrator->run($prompt, [
            'target_project_path' => $this->tempDir,
            'dry_run' => true,
            'llm' => 'fake',
            'no_human_review' => true,
        ]);

        $this->assertSame(RunState::STATUS_COMPLETED, $finalState->status);
        $this->assertTrue($finalState->isDryRun());

        // In dry run, files are simulated rather than written to real disk
        $this->assertFileDoesNotExist($this->tempDir . '/app/Models/DryNotification.php');

        // Checkpoint is persisted in workspace state
        $checkpointFile = $this->tempDir . '/.lara-orcest/state/' . $finalState->runId . '.json';
        $this->assertFileExists($checkpointFile);
    }

    public function test_resume_checkpoint_continuity_across_pods_on_target_project_path(): void
    {
        $llmManager = new LlmManager();
        $orchestrator = new Orchestrator(llmManager: $llmManager, workspaceContext: $this->context);

        $prompt = 'Interactive billing workflow with review gates';

        // 1. Run with human-in-the-loop enabled at after_plan gate
        $pausedState = $orchestrator->run($prompt, [
            'target_project_path' => $this->tempDir,
            'llm' => 'fake',
            'human_in_the_loop' => [
                'enabled' => true,
                'skip_review' => false,
                'gates' => ['after_plan' => true, 'before_commit' => false],
            ],
        ]);

        // Verify paused at after_plan
        $this->assertSame(RunState::STATUS_PAUSED, $pausedState->status);
        $this->assertSame('after_plan', $pausedState->getPausedGate());
        $this->assertSame($this->tempDir, $pausedState->targetProjectPath);

        // Checkpoint file exists
        $checkpointFile = $this->tempDir . '/.lara-orcest/state/' . $pausedState->runId . '.json';
        $this->assertFileExists($checkpointFile);

        // 2. Resume with approved decision
        $resumedState = $orchestrator->resume($pausedState->runId, [
            'target_project_path' => $this->tempDir,
            'decision' => 'approved',
            'llm' => 'fake',
            'no_human_review' => true,
        ]);

        // Verify completion
        $this->assertSame(RunState::STATUS_COMPLETED, $resumedState->status);
        $this->assertNull($resumedState->getPausedGate());
        $this->assertSame($this->tempDir, $resumedState->targetProjectPath);

        // Human decision recorded in metadata
        $decisions = $resumedState->getHumanDecisions();
        $this->assertNotEmpty($decisions);
        $this->assertSame('approved', $decisions[0]['decision']);

        // Database and backend artifacts exist
        $this->assertNotEmpty($resumedState->artifacts);
    }
}
