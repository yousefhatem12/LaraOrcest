<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Feature;

use InvalidArgumentException;
use RuntimeException;
use Yousef\LaraOrcest\Agents\SubAgents\ControllerResourceAgent;
use Yousef\LaraOrcest\Agents\SubAgents\FeatureTestAgent;
use Yousef\LaraOrcest\Agents\SubAgents\MigrationAgent;
use Yousef\LaraOrcest\Agents\SubAgents\RequestValidationAgent;
use Yousef\LaraOrcest\Tests\TestCase;
use Yousef\LaraOrcest\Tools\ArtisanRunnerTool;
use Yousef\LaraOrcest\Tools\FileReaderTool;
use Yousef\LaraOrcest\Tools\GitCommandTool;
use Yousef\LaraOrcest\Tools\PatchWriterTool;
use Yousef\LaraOrcest\Tools\ScopedToolProxy;
use Yousef\LaraOrcest\Tools\Support\PathGuard;
use Yousef\LaraOrcest\Tools\TestRunnerTool;
use Yousef\LaraOrcest\Workspace\WorkspaceContext;

class WorkspacePathGuardAndScopeEnforcementTest extends TestCase
{
    protected string $tempDir;
    protected WorkspaceContext $context;
    protected PathGuard $pathGuard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/lara_orcest_guard_scope_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0755, true);
        mkdir($this->tempDir . '/app/Models', 0755, true);
        mkdir($this->tempDir . '/database/migrations', 0755, true);
        mkdir($this->tempDir . '/app/Http/Controllers', 0755, true);
        mkdir($this->tempDir . '/app/Http/Requests', 0755, true);
        mkdir($this->tempDir . '/tests/Feature', 0755, true);
        mkdir($this->tempDir . '/routes', 0755, true);

        $this->context = WorkspaceContext::standalone($this->tempDir);
        $this->pathGuard = $this->context->createPathGuard();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tempDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $file) {
                if ($file->isDir()) {
                    @rmdir($file->getRealPath());
                } else {
                    @unlink($file->getRealPath());
                }
            }
            @rmdir($this->tempDir);
        }
        parent::tearDown();
    }

    public function test_path_guard_blocks_path_traversal_outside_target_project_path(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Path escapes project root');

        $this->pathGuard->resolveSafePath('../../etc/passwd');
    }

    public function test_path_guard_blocks_denied_patterns_in_target_project_path(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Access to denied path pattern blocked');

        $this->pathGuard->resolveSafePath('.env');
    }

    public function test_path_guard_blocks_git_directory_access(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Access to denied path pattern blocked');

        $this->pathGuard->resolveSafePath('.git/config');
    }

    public function test_file_reader_blocks_path_traversal(): void
    {
        $reader = new FileReaderTool(pathGuard: $this->pathGuard, workspaceContext: $this->context);

        $this->expectException(RuntimeException::class);
        $reader->execute(['path' => '../../../etc/hosts']);
    }

    public function test_patch_writer_blocks_path_traversal_and_unscoped_writes(): void
    {
        $writer = new PatchWriterTool(pathGuard: $this->pathGuard, allowedPrefixes: ['app/Models/'], workspaceContext: $this->context);

        // Outside allowed prefixes
        try {
            $writer->execute([
                'path' => 'routes/web.php',
                'content' => '<?php',
            ]);
            $this->fail('Expected out of scope prefix exception');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('outside allowed scope', $e->getMessage());
        }

        // Path traversal
        try {
            $writer->execute([
                'path' => '../escape.php',
                'content' => '<?php',
            ]);
            $this->fail('Expected path traversal exception');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('escapes project root', $e->getMessage());
        }
    }

    public function test_scoped_tool_proxy_blocks_migration_agent_from_writing_controllers(): void
    {
        $agent = new MigrationAgent();
        $underlyingWriter = new PatchWriterTool(pathGuard: $this->pathGuard, workspaceContext: $this->context);
        $scopedTool = new ScopedToolProxy($underlyingWriter, $agent->scopePaths(), $this->pathGuard);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Sub-agent scope violation');

        $scopedTool->execute([
            'path' => 'app/Http/Controllers/OrderController.php',
            'content' => '<?php class OrderController {}',
            'summary' => 'Out of scope write',
        ]);
    }

    public function test_scoped_tool_proxy_blocks_request_validation_agent_from_writing_migrations(): void
    {
        $agent = new RequestValidationAgent();
        $underlyingWriter = new PatchWriterTool(pathGuard: $this->pathGuard, workspaceContext: $this->context);
        $scopedTool = new ScopedToolProxy($underlyingWriter, $agent->scopePaths(), $this->pathGuard);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Sub-agent scope violation');

        $scopedTool->execute([
            'path' => 'database/migrations/2026_01_01_000001_create_orders_table.php',
            'content' => '<?php return new class {};',
            'summary' => 'Out of scope migration write',
        ]);
    }

    public function test_scoped_tool_proxy_blocks_feature_test_agent_from_writing_models(): void
    {
        $agent = new FeatureTestAgent();
        $underlyingWriter = new PatchWriterTool(pathGuard: $this->pathGuard, workspaceContext: $this->context);
        $scopedTool = new ScopedToolProxy($underlyingWriter, $agent->scopePaths(), $this->pathGuard);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Sub-agent scope violation');

        $scopedTool->execute([
            'path' => 'app/Models/Order.php',
            'content' => '<?php class Order {}',
            'summary' => 'Out of scope model write',
        ]);
    }

    public function test_scoped_tool_proxy_permits_writes_within_declared_scope(): void
    {
        $agent = new ControllerResourceAgent();
        $underlyingWriter = new PatchWriterTool(pathGuard: $this->pathGuard, workspaceContext: $this->context);
        $scopedTool = new ScopedToolProxy($underlyingWriter, $agent->scopePaths(), $this->pathGuard);

        $result = $scopedTool->execute([
            'path' => 'app/Http/Controllers/OrderController.php',
            'content' => "<?php\n\nnamespace App\Http\Controllers;\n\nclass OrderController {}\n",
            'summary' => 'Valid controller write',
        ]);

        $this->assertSame('app/Http/Controllers/OrderController.php', $result['path']);
        $this->assertFileExists($this->tempDir . '/app/Http/Controllers/OrderController.php');
    }

    public function test_artisan_runner_resolves_target_workspace_and_enforces_guards(): void
    {
        $artisan = new ArtisanRunnerTool(projectRoot: $this->tempDir, workspaceContext: $this->context);

        $this->assertSame($this->tempDir, $artisan->getProjectRoot());
        $this->assertFalse($artisan->hasArtisanBinary());

        // 1. Missing binary returns exit code 127 in live mode
        $result = $artisan->execute(['command' => 'make:model', 'arguments' => ['Product']]);
        $this->assertSame(127, $result['exit_code']);
        $this->assertFalse($result['successful']);
        $this->assertStringContainsString('Artisan binary not found', $result['output']);

        // 2. Validate artisan binary throws
        try {
            $artisan->validateArtisanBinary();
            $this->fail('Expected validateArtisanBinary to throw');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Artisan binary not found', $e->getMessage());
        }

        // 3. Command allow-list enforced
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not allowed');
        $artisan->execute(['command' => 'db:wipe']);
    }

    public function test_test_runner_resolves_target_workspace_and_validates_binaries(): void
    {
        $testRunner = new TestRunnerTool(projectRoot: $this->tempDir, workspaceContext: $this->context);

        $this->assertSame($this->tempDir, $testRunner->getProjectRoot());
        $this->assertFalse($testRunner->hasTestBinary());

        // 1. Missing binary returns exit code 127
        $result = $testRunner->execute([]);
        $this->assertSame(127, $result['exit_code']);
        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('No test runner (artisan, pest, phpunit) found', $result['summary']);

        // 2. Dry run bypasses execution safely
        $testRunner->setDryRun(true);
        $dryResult = $testRunner->execute([]);
        $this->assertTrue($dryResult['passed']);
        $this->assertSame(0, $dryResult['exit_code']);
        $this->assertTrue($dryResult['dry_run']);
    }

    public function test_git_command_tool_resolves_target_workspace_and_auto_inits_repository(): void
    {
        $git = new GitCommandTool(projectRoot: $this->tempDir, workspaceContext: $this->context);

        $this->assertSame($this->tempDir, $git->getProjectRoot());
        $this->assertFalse($git->isGitRepository());

        // Auto-initialize via action
        $initRes = $git->execute(['action' => 'init', 'branch' => 'main']);
        $this->assertTrue($initRes['initialized']);
        $this->assertTrue($git->isGitRepository());
        $this->assertDirectoryExists($this->tempDir . '/.git');

        // Check current branch
        $branchRes = $git->getCurrentBranch();
        $this->assertSame('main', $branchRes['branch']);

        // Create feature branch
        $createRes = $git->createBranch('orcest/test-feature');
        $this->assertSame('orcest/test-feature', $createRes['branch']);
        $this->assertTrue($createRes['created']);

        // Block protected branch check
        try {
            $git->createBranch('main');
            $this->fail('Expected protected branch creation to be blocked');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('protected branch [main] is forbidden', $e->getMessage());
        }
    }
}
