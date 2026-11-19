<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Tools\PatchWriterTool;
use Yousef\LaraOrcest\Tools\Support\PathGuard;

class PatchWriterToolEnhancedTest extends TestCase
{
    protected string $tempDir;
    protected PathGuard $pathGuard;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/lara_orcest_patchwriter_test_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir . '/app/Models', 0755, true);
        file_put_contents($this->tempDir . '/app/Models/User.php', "<?php\n\nclass User {\n    // todo\n}\n");

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

    public function test_creates_new_file_with_atomic_write(): void
    {
        $tool = new PatchWriterTool($this->pathGuard, ['app/']);
        $result = $tool->execute([
            'path' => 'app/Models/Invoice.php',
            'content' => "<?php\n\nclass Invoice {}\n",
            'summary' => 'Create Invoice model',
        ]);

        $this->assertEquals('created', $result['action']);
        $this->assertEquals('app/Models/Invoice.php', $result['path']);
        $this->assertFileExists($this->tempDir . '/app/Models/Invoice.php');
        $this->assertStringContainsString('+++ b/app/Models/Invoice.php', $result['diff']);
    }

    public function test_targeted_search_and_replace(): void
    {
        $tool = new PatchWriterTool($this->pathGuard, ['app/']);
        $result = $tool->execute([
            'action' => 'replace',
            'path' => 'app/Models/User.php',
            'target' => '// todo',
            'replacement' => 'protected $fillable = [\'name\'];',
            'summary' => 'Add fillable attributes',
        ]);

        $this->assertEquals('updated', $result['action']);
        $this->assertStringContainsString('+    protected $fillable = [\'name\'];', $result['diff']);

        $updatedContent = file_get_contents($this->tempDir . '/app/Models/User.php');
        $this->assertStringContainsString('protected $fillable', $updatedContent);
        $this->assertStringNotContainsString('// todo', $updatedContent);
    }

    public function test_replace_throws_exception_when_target_not_found(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Target string was not found');

        $tool = new PatchWriterTool($this->pathGuard, ['app/']);
        $tool->execute([
            'action' => 'replace',
            'path' => 'app/Models/User.php',
            'target' => 'non_existent_code_snippet',
            'replacement' => 'something_else',
        ]);
    }

    public function test_deletes_file_safely(): void
    {
        $tool = new PatchWriterTool($this->pathGuard, ['app/']);
        $result = $tool->execute([
            'action' => 'delete',
            'path' => 'app/Models/User.php',
            'summary' => 'Remove deprecated User model',
        ]);

        $this->assertEquals('deleted', $result['action']);
        $this->assertFileDoesNotExist($this->tempDir . '/app/Models/User.php');
        $this->assertStringContainsString('--- a/app/Models/User.php', $result['diff']);
    }

    public function test_dry_run_produces_diff_without_mutating_disk(): void
    {
        $tool = new PatchWriterTool($this->pathGuard, ['app/'], dryRun: true);
        $result = $tool->execute([
            'action' => 'write',
            'path' => 'app/Models/Product.php',
            'content' => "<?php\n\nclass Product {}\n",
        ]);

        $this->assertTrue($result['dry_run']);
        $this->assertFileDoesNotExist($this->tempDir . '/app/Models/Product.php');
        $this->assertStringContainsString('+++ b/app/Models/Product.php', $result['diff']);
    }

    public function test_apply_to_state_records_artifact_in_run_state(): void
    {
        $state = RunState::create('Build feature', ['dry_run' => false]);
        $tool = new PatchWriterTool($this->pathGuard, ['app/']);

        $outcome = $tool->applyToState($state, [
            'action' => 'write',
            'path' => 'app/Models/Task.php',
            'content' => "<?php\n\nclass Task {}\n",
            'summary' => 'Created Task model',
        ]);

        $this->assertCount(1, $outcome['state']->artifacts);
        $this->assertEquals('app/Models/Task.php', $outcome['state']->artifacts[0]['path']);
        $this->assertEquals('created', $outcome['state']->artifacts[0]['action']);
        $this->assertEquals('Created Task model', $outcome['state']->artifacts[0]['summary']);
    }

    public function test_apply_to_state_in_dry_run_does_not_modify_disk_or_artifacts(): void
    {
        $state = RunState::create('Build feature', ['dry_run' => true]);
        $tool = new PatchWriterTool($this->pathGuard, ['app/']);

        $outcome = $tool->applyToState($state, [
            'action' => 'write',
            'path' => 'app/Models/DryTask.php',
            'content' => "<?php\n\nclass DryTask {}\n",
        ]);

        $this->assertEmpty($outcome['state']->artifacts);
        $this->assertFileDoesNotExist($this->tempDir . '/app/Models/DryTask.php');
        $this->assertTrue($outcome['result']['dry_run']);
    }
}
