<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Yousef\LaraOrcest\Tools\DirectoryScaffolderTool;
use Yousef\LaraOrcest\Tools\Support\PathGuard;
use Yousef\LaraOrcest\Workspace\WorkspaceContext;

class DirectoryScaffolderToolTest extends TestCase
{
    private string $tempDir;
    private WorkspaceContext $context;
    private PathGuard $pathGuard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/scaffolder_test_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0755, true);
        $this->context = WorkspaceContext::standalone($this->tempDir);
        $this->pathGuard = $this->context->createPathGuard();
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
        $tool = new DirectoryScaffolderTool(workspaceContext: $this->context);

        $this->assertSame('directory_scaffolder', $tool->getName());
        $this->assertSame(realpath($this->tempDir), $tool->getProjectRoot());
        $this->assertFalse($tool->isDryRun());
    }

    public function test_scaffolds_directories_and_starter_files(): void
    {
        $tool = new DirectoryScaffolderTool(workspaceContext: $this->context);

        $result = $tool->execute([
            'directories' => [
                'app/Domains/Invoicing/Models',
                'app/Domains/Invoicing/Actions',
            ],
            'files' => [
                [
                    'path' => 'app/Domains/Invoicing/Models/Invoice.php',
                    'content' => "<?php\n\nnamespace App\Domains\Invoicing\Models;\n\nclass Invoice {}\n",
                    'summary' => 'Invoice domain model',
                ],
            ],
        ]);

        $this->assertTrue($result['successful']);
        $this->assertFalse($result['dry_run']);
        $this->assertDirectoryExists($this->tempDir . '/app/Domains/Invoicing/Models');
        $this->assertDirectoryExists($this->tempDir . '/app/Domains/Invoicing/Actions');
        $this->assertFileExists($this->tempDir . '/app/Domains/Invoicing/Models/Invoice.php');
        $this->assertStringContainsString('class Invoice', (string) file_get_contents($this->tempDir . '/app/Domains/Invoicing/Models/Invoice.php'));
    }

    public function test_dry_run_mode_returns_planned_actions_without_mutating_disk(): void
    {
        $tool = new DirectoryScaffolderTool(
            dryRun: true,
            workspaceContext: $this->context
        );

        $result = $tool->execute([
            'directories' => ['app/Domains/Billing/Services'],
            'files' => [
                [
                    'path' => 'app/Domains/Billing/Services/PaymentGateway.php',
                    'content' => "<?php\n\nclass PaymentGateway {}\n",
                    'summary' => 'Payment service',
                ],
            ],
        ]);

        $this->assertTrue($result['successful']);
        $this->assertTrue($result['dry_run']);
        $this->assertContains('app/Domains/Billing/Services', $result['created_directories']);
        $this->assertCount(1, $result['created_files']);
        $this->assertStringContainsString('[DRY-RUN]', $result['summary']);

        // Verify no disk mutation occurred
        $this->assertDirectoryDoesNotExist($this->tempDir . '/app/Domains/Billing/Services');
    }

    public function test_enforces_pathguard_blocking_directory_traversal(): void
    {
        $tool = new DirectoryScaffolderTool(workspaceContext: $this->context);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Path escapes project root');

        $tool->execute([
            'directories' => ['../../escaped_dir'],
        ]);
    }

    public function test_enforces_pathguard_blocking_denied_paths(): void
    {
        $tool = new DirectoryScaffolderTool(workspaceContext: $this->context);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Access to denied directory pattern blocked');

        $tool->execute([
            'directories' => ['.git/custom_hooks'],
        ]);
    }

    public function test_enforces_allowed_prefixes(): void
    {
        $tool = new DirectoryScaffolderTool(
            workspaceContext: $this->context,
            allowedPrefixes: ['app/Domains/']
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('outside allowed scope');

        $tool->execute([
            'directories' => ['routes/api'],
        ]);
    }

    public function test_redacts_secrets_in_file_summaries(): void
    {
        $tool = new DirectoryScaffolderTool(
            dryRun: true,
            workspaceContext: $this->context
        );

        $result = $tool->execute([
            'directories' => ['config'],
            'files' => [
                [
                    'path' => 'config/service.php',
                    'content' => '<?php return [];',
                    'summary' => 'Service with api_key="sk-abcdef12345678901234567890"',
                ],
            ],
        ]);

        $this->assertTrue($result['successful']);
        $this->assertStringContainsString('[REDACTED', $result['created_files'][0]['summary']);
        $this->assertStringNotContainsString('sk-abcdef12345678901234567890', $result['created_files'][0]['summary']);
    }

    public function test_empty_directories_and_files_returns_graceful_no_op(): void
    {
        $tool = new DirectoryScaffolderTool(workspaceContext: $this->context);

        $result = $tool->execute([
            'directories' => [],
            'files' => [],
        ]);

        $this->assertTrue($result['successful']);
        $this->assertEmpty($result['created_directories']);
        $this->assertEmpty($result['created_files']);
    }
}
