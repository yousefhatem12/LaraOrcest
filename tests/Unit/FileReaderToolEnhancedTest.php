<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Yousef\LaraOrcest\Tools\FileReaderTool;
use Yousef\LaraOrcest\Tools\Support\PathGuard;

class FileReaderToolEnhancedTest extends TestCase
{
    protected string $tempDir;
    protected PathGuard $pathGuard;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/lara_orcest_filereader_test_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir . '/app/Models', 0755, true);
        mkdir($this->tempDir . '/app/Http/Controllers', 0755, true);
        mkdir($this->tempDir . '/routes', 0755, true);

        file_put_contents($this->tempDir . '/app/Models/User.php', "<?php\n\nnamespace App\\Models;\n\nclass User {}\n");
        file_put_contents($this->tempDir . '/app/Models/Post.php', "<?php\n\nnamespace App\\Models;\n\nclass Post {}\n");
        file_put_contents($this->tempDir . '/app/Http/Controllers/PostController.php', "<?php\n\nclass PostController {}\n");
        file_put_contents($this->tempDir . '/routes/api.php', "<?php\n\n// API Routes\n");
        file_put_contents($this->tempDir . '/routes/web.php', "<?php\n\n// Web Routes\n");
        file_put_contents($this->tempDir . '/.env', "APP_SECRET=123\n");

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

    public function test_reads_file_with_line_ranges(): void
    {
        $tool = new FileReaderTool($this->pathGuard);
        $result = $tool->execute([
            'path' => 'app/Models/User.php',
            'offset' => 2,
            'max_lines' => 2,
        ]);

        $this->assertTrue($result['exists']);
        $this->assertEquals('file', $result['type']);
        $this->assertEquals('app/Models/User.php', $result['path']);
        $this->assertEquals(2, $result['returned_lines']);
        $this->assertStringContainsString('namespace App\Models;', $result['content']);
        $this->assertTrue($result['truncated']);
    }

    public function test_reads_file_with_max_bytes_cap(): void
    {
        $tool = new FileReaderTool($this->pathGuard);
        $result = $tool->execute([
            'path' => 'app/Models/User.php',
            'max_bytes' => 10,
        ]);

        $this->assertTrue($result['exists']);
        $this->assertEquals(10, strlen($result['content']));
        $this->assertTrue($result['truncated']);
    }

    public function test_gracefully_handles_non_existent_file(): void
    {
        $tool = new FileReaderTool($this->pathGuard);
        $result = $tool->execute([
            'path' => 'app/Models/NonExistent.php',
            'must_exist' => false,
        ]);

        $this->assertFalse($result['exists']);
        $this->assertNull($result['content']);
        $this->assertEmpty($result['entries']);
    }

    public function test_throws_when_must_exist_is_true_and_file_missing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not exist');

        $tool = new FileReaderTool($this->pathGuard);
        $tool->execute([
            'path' => 'app/Models/Missing.php',
            'must_exist' => true,
        ]);
    }

    public function test_lists_flat_directory_contents(): void
    {
        $tool = new FileReaderTool($this->pathGuard);
        $result = $tool->execute([
            'path' => 'app/Models',
        ]);

        $this->assertTrue($result['exists']);
        $this->assertEquals('directory', $result['type']);
        $this->assertEquals('app/Models', $result['path']);
        $this->assertCount(2, $result['entries']);

        $paths = array_column($result['entries'], 'path');
        $this->assertContains('app/Models/User.php', $paths);
        $this->assertContains('app/Models/Post.php', $paths);
    }

    public function test_lists_directory_recursively_with_pattern(): void
    {
        $tool = new FileReaderTool($this->pathGuard);
        $result = $tool->execute([
            'path' => 'app',
            'recursive' => true,
            'pattern' => '*Controller.php',
        ]);

        $this->assertTrue($result['exists']);
        $this->assertEquals('directory', $result['type']);
        $this->assertCount(1, $result['entries']);
        $this->assertEquals('app/Http/Controllers/PostController.php', $result['entries'][0]['path']);
    }

    public function test_directory_listing_filters_out_denied_files(): void
    {
        $tool = new FileReaderTool($this->pathGuard);
        $result = $tool->execute([
            'path' => '.',
        ]);

        $paths = array_column($result['entries'], 'path');
        // Ensure .env is not listed in directory inspection
        $this->assertNotContains('.env', $paths);
    }
}
