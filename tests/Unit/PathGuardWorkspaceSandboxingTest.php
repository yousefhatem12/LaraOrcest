<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Yousef\LaraOrcest\Tools\Support\PathGuard;
use Yousef\LaraOrcest\Workspace\WorkspaceContext;

class PathGuardWorkspaceSandboxingTest extends TestCase
{
    protected string $isolatedWorkspace;
    protected string $outsideDir;
    protected WorkspaceContext $context;
    protected PathGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $base = sys_get_temp_dir() . '/lara_sandboxing_' . bin2hex(random_bytes(4));
        $this->isolatedWorkspace = $base . '/target_workspace';
        $this->outsideDir = $base . '/outside_workspace';

        @mkdir($this->isolatedWorkspace . '/app/Models', 0755, true);
        @mkdir($this->isolatedWorkspace . '/routes', 0755, true);
        @mkdir($this->isolatedWorkspace . '/database/migrations', 0755, true);
        @mkdir($this->outsideDir, 0755, true);

        file_put_contents($this->isolatedWorkspace . '/app/Models/Invoice.php', '<?php namespace App\Models; class Invoice {}');
        file_put_contents($this->isolatedWorkspace . '/routes/api.php', '<?php // api routes');
        file_put_contents($this->outsideDir . '/secret.key', 'SUPER_SECRET_HOST_KEY');

        $this->context = WorkspaceContext::standalone($this->isolatedWorkspace);
        $this->guard = $this->context->createPathGuard();
    }

    protected function tearDown(): void
    {
        $base = dirname($this->isolatedWorkspace);
        if (is_dir($base)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $fileinfo) {
                $path = $fileinfo->getPathname();
                if ($fileinfo->isLink()) {
                    @unlink($path);
                } elseif ($fileinfo->isDir()) {
                    @rmdir($path);
                } else {
                    @unlink($path);
                }
            }
            @rmdir($base);
        }
        parent::tearDown();
    }

    public function test_allows_access_strictly_inside_target_project_path(): void
    {
        $resolved = $this->guard->resolveSafePath('app/Models/Invoice.php', mustExist: true);

        $this->assertSame(realpath($this->isolatedWorkspace . '/app/Models/Invoice.php'), $resolved);
        $this->assertTrue(str_starts_with($resolved, realpath($this->isolatedWorkspace)));
        $this->assertSame('app/Models/Invoice.php', $this->guard->getRelativePath($resolved));
    }

    public function test_allows_nested_directory_resolution_inside_target_path(): void
    {
        $resolvedDir = $this->guard->resolveSafeDirectory('routes', mustExist: true);

        $this->assertSame(realpath($this->isolatedWorkspace . '/routes'), $resolvedDir);
    }

    public function test_blocks_access_to_files_outside_target_project_path(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('escapes project root');

        $outsideFile = $this->outsideDir . '/secret.key';
        $this->guard->resolveSafePath($outsideFile);
    }

    public function test_blocks_path_traversal_escaping_target_workspace(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('escapes project root');

        $this->guard->resolveSafePath('../outside_workspace/secret.key');
    }

    public function test_blocks_deep_traversal_attempts(): void
    {
        $this->expectException(RuntimeException::class);

        $this->guard->resolveSafePath('app/Models/../../../../../../etc/passwd');
    }

    public function test_blocks_symlink_escaping_target_workspace(): void
    {
        $symlinkPath = $this->isolatedWorkspace . '/outside_link';
        @symlink($this->outsideDir, $symlinkPath);

        if (! is_link($symlinkPath)) {
            $this->markTestSkipped('Symlinks not supported in this environment');
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Symlink escape detected');

        $this->guard->resolveSafePath('outside_link/secret.key');
    }

    public function test_is_safe_returns_false_for_outside_paths(): void
    {
        $this->assertTrue($this->guard->isSafe('app/Models/Invoice.php'));
        $this->assertFalse($this->guard->isSafe('/etc/passwd'));
        $this->assertFalse($this->guard->isSafe('../outside_workspace/secret.key'));
    }
}
