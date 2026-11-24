<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Yousef\LaraOrcest\Tools\ComposerRunnerTool;
use Yousef\LaraOrcest\Workspace\WorkspaceContext;

class ComposerRunnerToolTest extends TestCase
{
    private string $tempDir;
    private WorkspaceContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/composer_runner_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        file_put_contents($this->tempDir . '/composer.json', json_encode([
            'name' => 'test/app',
            'require' => ['php' => '^8.2'],
        ]));

        $this->context = WorkspaceContext::standalone($this->tempDir);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
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

    public function test_initializes_with_workspace_context(): void
    {
        $tool = new ComposerRunnerTool(
            allowedCommands: ['validate', 'dump-autoload', 'show'],
            workspaceContext: $this->context
        );

        $this->assertSame('composer_runner', $tool->getName());
        $this->assertContains('validate', $tool->getAllowedCommands());
        $this->assertSame(realpath($this->tempDir), $tool->getProjectRoot());
    }

    public function test_dry_run_simulates_execution(): void
    {
        $tool = new ComposerRunnerTool(
            allowedCommands: ['validate', 'dump-autoload'],
            dryRun: true,
            workspaceContext: $this->context
        );

        $result = $tool->execute([
            'command' => 'dump-autoload',
            'arguments' => ['--optimize'],
        ]);

        $this->assertTrue($result['successful']);
        $this->assertStringContainsString('[DRY-RUN]', $result['output']);
        $this->assertStringContainsString('dump-autoload', $result['output']);
        $this->assertStringContainsString('--optimize', $result['output']);
    }

    public function test_blocks_disallowed_subcommands(): void
    {
        $tool = new ComposerRunnerTool(
            allowedCommands: ['validate', 'show'],
            dryRun: true,
            workspaceContext: $this->context
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not allowed');

        $tool->execute([
            'command' => 'require',
            'arguments' => ['laravel/framework'],
        ]);
    }

    public function test_blocks_dangerous_shell_characters(): void
    {
        $tool = new ComposerRunnerTool(
            allowedCommands: ['dump-autoload'],
            dryRun: true,
            workspaceContext: $this->context
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Shell metacharacters detected');

        $tool->execute([
            'command' => 'dump-autoload',
            'arguments' => ['; rm -rf /'],
        ]);
    }
}
