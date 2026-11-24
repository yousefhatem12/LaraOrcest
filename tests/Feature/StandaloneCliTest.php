<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Feature;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Process\Process;
use Yousef\LaraOrcest\Console\LaraOrcestApplication;
use Yousef\LaraOrcest\State\Drivers\JsonCheckpointer;
use Yousef\LaraOrcest\State\RunState;

class StandaloneCliTest extends TestCase
{
    private string $tempWorkspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempWorkspace = sys_get_temp_dir() . '/lara_orcest_cli_test_' . uniqid();
        mkdir($this->tempWorkspace, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempWorkspace)) {
            $this->removeDirectory($this->tempWorkspace);
        }
        parent::tearDown();
    }

    private function removeDirectory(string $path): void
    {
        $files = array_diff(scandir($path) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $fullPath = $path . '/' . $file;
            is_dir($fullPath) ? $this->removeDirectory($fullPath) : unlink($fullPath);
        }
        rmdir($path);
    }

    public function test_application_initializes_with_expected_commands(): void
    {
        $app = new LaraOrcestApplication();
        $this->assertTrue($app->has('init'));
        $this->assertTrue($app->has('build'));
        $this->assertTrue($app->has('status'));
        $this->assertTrue($app->has('approve'));
    }

    public function test_init_command_creates_workspace_configuration(): void
    {
        $app = new LaraOrcestApplication();
        $tester = new ApplicationTester($app);

        $exitCode = $tester->run([
            'command' => 'init',
            'project-dir' => $this->tempWorkspace,
        ]);

        $this->assertSame(0, $exitCode);
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Workspace initialized successfully', $output);

        $configFile = $this->tempWorkspace . '/.lara-orcest/config.json';
        $this->assertFileExists($configFile);

        $configData = json_decode((string) file_get_contents($configFile), true);
        $this->assertSame(realpath($this->tempWorkspace), $configData['target_path']);
        $this->assertSame('standalone', $configData['workspace_mode']);
    }

    public function test_init_command_rejects_nonexistent_directory(): void
    {
        $app = new LaraOrcestApplication();
        $tester = new ApplicationTester($app);

        $exitCode = $tester->run([
            'command' => 'init',
            'project-dir' => $this->tempWorkspace . '/does_not_exist',
        ]);

        $this->assertSame(1, $exitCode);
        $display = preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '';
        $this->assertStringContainsString('does not exist', $display);
    }

    public function test_status_command_displays_run_state_and_workspace(): void
    {
        $app = new LaraOrcestApplication();
        $tester = new ApplicationTester($app);

        // 1. Init workspace
        $tester->run([
            'command' => 'init',
            'project-dir' => $this->tempWorkspace,
        ]);

        // 2. Create checkpoint in target workspace
        $stateDir = $this->tempWorkspace . '/.lara-orcest/state';
        if (! is_dir($stateDir)) {
            mkdir($stateDir, 0755, true);
        }
        $checkpointer = new JsonCheckpointer($stateDir);

        $state = RunState::create('Add User authentication with sanctum', [
            'target_project_path' => $this->tempWorkspace,
            'workspace_mode' => 'standalone',
        ])->withStatus(RunState::STATUS_COMPLETED);

        $checkpointer->save($state);

        // 3. Inspect status
        $exitCode = $tester->run([
            'command' => 'status',
            '--target' => $this->tempWorkspace,
        ]);

        $this->assertSame(0, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('LaraOrcest Standalone Control Plane Status', $display);
        $this->assertStringContainsString($state->runId, $display);
        $this->assertStringContainsString('completed', $display);
        $this->assertStringContainsString('Add User authentication with sanctum', $display);
        $this->assertStringContainsString(realpath($this->tempWorkspace), $display);
    }

    public function test_build_command_in_dry_run_mode(): void
    {
        $app = new LaraOrcestApplication();
        $tester = new ApplicationTester($app);

        $exitCode = $tester->run([
            'command' => 'build',
            'prompt' => 'Scaffold an Invoice model and migration',
            '--target' => $this->tempWorkspace,
            '--dry-run' => true,
            '--llm' => 'fake',
            '--no-human-review' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('DRY-RUN', $display);
        $this->assertStringContainsString('Scaffold an Invoice model and migration', $display);
        $this->assertStringContainsString(realpath($this->tempWorkspace), $display);
    }

    public function test_bin_lara_orcest_executable_runs_via_process(): void
    {
        $binary = dirname(__DIR__, 2) . '/bin/lara-orcest';
        $this->assertFileExists($binary);

        $process = new Process(['php', $binary, '--version']);
        $process->run();

        $this->assertSame(0, $process->getExitCode());
        $this->assertStringContainsString('LaraOrcest Standalone Control Plane', $process->getOutput());
    }

    public function test_bin_lara_orcest_executable_init_and_status_via_process(): void
    {
        $binary = dirname(__DIR__, 2) . '/bin/lara-orcest';

        $initProcess = new Process(['php', $binary, 'init', $this->tempWorkspace]);
        $initProcess->run();
        $this->assertSame(0, $initProcess->getExitCode());

        $statusProcess = new Process(['php', $binary, 'status', '--target=' . $this->tempWorkspace]);
        $statusProcess->run();
        $this->assertSame(0, $statusProcess->getExitCode());
        $this->assertStringContainsString(realpath($this->tempWorkspace), $statusProcess->getOutput());
    }
}
