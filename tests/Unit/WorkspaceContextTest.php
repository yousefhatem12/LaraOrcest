<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Yousef\LaraOrcest\Config\OrchestratorConfig;
use Yousef\LaraOrcest\Tools\Support\PathGuard;
use Yousef\LaraOrcest\Workspace\WorkspaceContext;

class WorkspaceContextTest extends TestCase
{
    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/lara_orcest_ws_test_' . bin2hex(random_bytes(4));
        @mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tempDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $fileinfo) {
                ($fileinfo->isDir() ? 'rmdir' : 'unlink')($fileinfo->getRealPath());
            }
            @rmdir($this->tempDir);
        }
        parent::tearDown();
    }

    public function test_creates_standalone_workspace_context(): void
    {
        $context = WorkspaceContext::standalone($this->tempDir);

        $this->assertSame(realpath($this->tempDir), $context->getTargetProjectPath());
        $this->assertSame(WorkspaceContext::MODE_STANDALONE, $context->getWorkspaceMode());
        $this->assertTrue($context->isStandalone());
        $this->assertFalse($context->isEmbedded());
        $this->assertSame(realpath($this->tempDir) . DIRECTORY_SEPARATOR . '.lara-orcest' . DIRECTORY_SEPARATOR . 'state', $context->getStatePath());
        $this->assertSame(realpath($this->tempDir) . DIRECTORY_SEPARATOR . '.lara-orcest' . DIRECTORY_SEPARATOR . 'memory.json', $context->getMemoryPath());
        $this->assertInstanceOf(OrchestratorConfig::class, $context->getConfig());
    }

    public function test_creates_embedded_workspace_context(): void
    {
        $context = WorkspaceContext::embedded($this->tempDir);

        $this->assertSame(realpath($this->tempDir), $context->getTargetProjectPath());
        $this->assertSame(WorkspaceContext::MODE_EMBEDDED, $context->getWorkspaceMode());
        $this->assertTrue($context->isEmbedded());
        $this->assertFalse($context->isStandalone());
    }

    public function test_resolves_relative_and_state_paths(): void
    {
        $context = WorkspaceContext::standalone($this->tempDir);

        $this->assertSame(realpath($this->tempDir) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Models', $context->path('app/Models'));
        $this->assertSame(realpath($this->tempDir) . DIRECTORY_SEPARATOR . '.lara-orcest' . DIRECTORY_SEPARATOR . 'state' . DIRECTORY_SEPARATOR . 'run_123.json', $context->stateFile('run_123.json'));
    }

    public function test_ensure_directories_exist(): void
    {
        $context = WorkspaceContext::standalone($this->tempDir);
        $context->ensureDirectoriesExist();

        $this->assertDirectoryExists($this->tempDir . '/.lara-orcest');
        $this->assertDirectoryExists($this->tempDir . '/.lara-orcest/state');
        $this->assertFileExists($this->tempDir . '/.lara-orcest/.gitignore');
    }

    public function test_create_path_guard_is_bounded_to_target_path(): void
    {
        $context = WorkspaceContext::standalone($this->tempDir);
        $guard = $context->createPathGuard();

        $this->assertInstanceOf(PathGuard::class, $guard);
        $this->assertSame(realpath($this->tempDir), $guard->getProjectRoot());
        $this->assertSame($context, $guard->getWorkspaceContext());
    }

    public function test_create_tools_and_tool_registry(): void
    {
        $context = WorkspaceContext::standalone($this->tempDir);
        $tools = $context->createTools();

        $this->assertArrayHasKey('file_reader', $tools);
        $this->assertArrayHasKey('patch_writer', $tools);
        $this->assertArrayHasKey('artisan_runner', $tools);
        $this->assertArrayHasKey('test_runner', $tools);
        $this->assertArrayHasKey('git_command', $tools);
        $this->assertArrayHasKey('http_fetch', $tools);
        $this->assertArrayHasKey('http_dispatch', $tools);
        $this->assertArrayHasKey('codebase_inspector', $tools);
        $this->assertArrayHasKey('composer_runner', $tools);

        $registry = $context->createToolRegistry();
        $this->assertTrue($registry->has('composer_runner'));
        $this->assertTrue($registry->has('file_reader'));
    }

    public function test_to_array_serialization(): void
    {
        $context = WorkspaceContext::standalone($this->tempDir);
        $array = $context->toArray();

        $this->assertSame(realpath($this->tempDir), $array['target_project_path']);
        $this->assertSame('standalone', $array['workspace_mode']);
        $this->assertArrayHasKey('state_path', $array);
        $this->assertArrayHasKey('memory_path', $array);
        $this->assertArrayHasKey('config', $array);
    }
}
