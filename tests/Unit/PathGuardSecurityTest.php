<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Yousef\LaraOrcest\Tools\Support\PathGuard;

class PathGuardSecurityTest extends TestCase
{
    protected string $tempDir;
    protected PathGuard $guard;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/lara_orcest_security_test_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0755, true);
        mkdir($this->tempDir . '/app', 0755, true);
        mkdir($this->tempDir . '/database', 0755, true);
        file_put_contents($this->tempDir . '/.env', 'APP_KEY=secret');

        $this->guard = new PathGuard(
            projectRoot: $this->tempDir,
            denyPatterns: ['.env', '.env.*', '.git', 'vendor']
        );
    }

    protected function tearDown(): void
    {
        // Recursive remove temp dir
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

    public function test_blocks_path_traversal(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('escapes project root');
        $this->guard->resolveSafePath('../../etc/passwd');
    }

    public function test_blocks_null_bytes(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('null bytes');
        $this->guard->resolveSafePath("app/Models/User.php\0.jpg");
    }

    public function test_blocks_access_to_env(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Access to denied path pattern');
        $this->guard->resolveSafePath('.env');
    }

    public function test_blocks_access_to_env_variants(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Access to denied path pattern');
        $this->guard->resolveSafePath('.env.production');
    }

    public function test_enforces_directory_prefixes(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('outside allowed scope');
        $this->guard->resolveSafePath('app/Models/User.php', allowedPrefixes: ['database/']);
    }

    public function test_resolves_valid_allowed_path(): void
    {
        $path = $this->guard->resolveSafePath('app/Models/User.php', allowedPrefixes: ['app/']);
        $this->assertEquals($this->tempDir . '/app/Models/User.php', $path);
    }
}
