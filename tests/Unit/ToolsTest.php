<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Yousef\LaraOrcest\Tools\ArtisanRunnerTool;
use Yousef\LaraOrcest\Tools\FileReaderTool;
use Yousef\LaraOrcest\Tools\GitCommandTool;
use Yousef\LaraOrcest\Tools\PatchWriterTool;
use Yousef\LaraOrcest\Tools\Support\PathGuard;

class ToolsTest extends TestCase
{
    protected string $tempDir;
    protected PathGuard $pathGuard;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/lara_orcest_tool_test_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir . '/app/Models', 0755, true);
        file_put_contents($this->tempDir . '/app/Models/User.php', "<?php\n\nclass User {}\n");

        $this->pathGuard = new PathGuard($this->tempDir);
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

    public function test_file_reader_reads_file(): void
    {
        $tool = new FileReaderTool($this->pathGuard);
        $result = $tool->execute(['path' => 'app/Models/User.php']);

        $this->assertEquals('app/Models/User.php', $result['path']);
        $this->assertStringContainsString('class User', $result['content']);
        $this->assertEquals(3, $result['total_lines']);
    }

    public function test_patch_writer_writes_and_generates_diff(): void
    {
        $tool = new PatchWriterTool($this->pathGuard, allowedPrefixes: ['app/']);
        $result = $tool->execute([
            'path' => 'app/Models/Task.php',
            'content' => "<?php\n\nclass Task {}\n",
            'summary' => 'Created Task model',
        ]);

        $this->assertEquals('app/Models/Task.php', $result['path']);
        $this->assertEquals('created', $result['action']);
        $this->assertFileExists($this->tempDir . '/app/Models/Task.php');
        $this->assertStringContainsString('+++ b/app/Models/Task.php', $result['diff']);
    }

    public function test_patch_writer_dry_run_does_not_touch_disk(): void
    {
        $tool = new PatchWriterTool($this->pathGuard, allowedPrefixes: ['app/'], dryRun: true);
        $result = $tool->execute([
            'path' => 'app/Models/DryModel.php',
            'content' => "<?php\n\nclass DryModel {}\n",
        ]);

        $this->assertTrue($result['dry_run']);
        $this->assertFileDoesNotExist($this->tempDir . '/app/Models/DryModel.php');
    }

    public function test_artisan_runner_blocks_unauthorized_commands(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not allowed');

        $tool = new ArtisanRunnerTool(projectRoot: $this->tempDir, allowedCommands: ['make:model']);
        $tool->execute(['command' => 'db:wipe']);
    }

    public function test_artisan_runner_blocks_shell_operators(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Shell metacharacters');

        $tool = new ArtisanRunnerTool(projectRoot: $this->tempDir, allowedCommands: ['make:model']);
        $tool->execute(['command' => 'make:model', 'arguments' => ['Task; rm -rf /']]);
    }

    public function test_git_tool_blocks_protected_branch_creation(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('protected branch [main] is forbidden');

        $git = new GitCommandTool($this->tempDir, protectedBranches: ['main', 'master'], dryRun: true);
        $git->createBranch('main');
    }
}
