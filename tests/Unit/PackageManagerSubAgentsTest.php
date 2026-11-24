<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Yousef\LaraOrcest\Agents\SubAgents\DependencyResolverAgent;
use Yousef\LaraOrcest\Agents\SubAgents\LaravelInstallerAgent;
use Yousef\LaraOrcest\Agents\SubAgents\StructureScaffolderAgent;
use Yousef\LaraOrcest\LLM\Drivers\FakeDriver;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Tools\ComposerRunnerTool;
use Yousef\LaraOrcest\Tools\DirectoryScaffolderTool;
use Yousef\LaraOrcest\Tools\FileReaderTool;
use Yousef\LaraOrcest\Tools\PatchWriterTool;
use Yousef\LaraOrcest\Tools\Support\PathGuard;
use Yousef\LaraOrcest\Workspace\WorkspaceContext;

class PackageManagerSubAgentsTest extends TestCase
{
    private string $tempDir;
    private WorkspaceContext $context;
    private PathGuard $pathGuard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/pm_subagent_test_' . bin2hex(random_bytes(6));
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

    public function test_laravel_installer_agent_metadata_and_dependencies(): void
    {
        $agent = new LaravelInstallerAgent();

        $this->assertSame('laravel_installer_agent', $agent->key());
        $this->assertSame('package_manager_agent', $agent->parentAgentKey());
        $this->assertSame([], $agent->dependsOn());
        $this->assertContains('composer_runner', $agent->allowedTools());
        $this->assertContains('file_reader', $agent->allowedTools());
        $this->assertContains('patch_writer', $agent->allowedTools());
        $this->assertContains('composer.json', $agent->scopePaths());
        $this->assertContains('.env.example', $agent->scopePaths());
    }

    public function test_laravel_installer_agent_detects_already_initialized_project(): void
    {
        // Place composer.json to simulate an existing project
        file_put_contents($this->tempDir . '/composer.json', json_encode(['name' => 'existing/app']));

        $agent = new LaravelInstallerAgent();
        $fakeLlm = new FakeDriver();
        $tools = [
            'composer_runner' => new ComposerRunnerTool(dryRun: true, workspaceContext: $this->context),
            'file_reader' => new FileReaderTool(pathGuard: $this->pathGuard),
            'patch_writer' => new PatchWriterTool(pathGuard: $this->pathGuard),
        ];

        $state = RunState::create('Bootstrap project', [
            'target_project_path' => $this->tempDir,
            'dry_run' => true,
        ]);

        $result = $agent->execute($state, $tools, $fakeLlm);

        $this->assertTrue($result['success']);
        $this->assertSame('already_initialized', $result['output']['status']);
        $this->assertSame('verify_existing', $result['output']['action']);
        $this->assertStringContainsString('already initialized', $result['summary']);
        $this->assertSame('verified', $result['artifacts'][0]['action']);
    }

    public function test_laravel_installer_agent_bootstraps_clean_project_in_dry_run(): void
    {
        $agent = new LaravelInstallerAgent();
        $fakeLlm = new FakeDriver();
        $tools = [
            'composer_runner' => new ComposerRunnerTool(dryRun: true, workspaceContext: $this->context),
            'file_reader' => new FileReaderTool(pathGuard: $this->pathGuard),
            'patch_writer' => new PatchWriterTool(pathGuard: $this->pathGuard),
        ];

        $state = RunState::create('Bootstrap project', [
            'target_project_path' => $this->tempDir,
            'dry_run' => true,
        ]);

        $result = $agent->execute($state, $tools, $fakeLlm);

        $this->assertTrue($result['success']);
        $this->assertSame('initialized', $result['output']['status']);
        $this->assertSame('bootstrap_project', $result['output']['action']);
        $this->assertSame('preview', $result['artifacts'][0]['action']);
    }

    public function test_dependency_resolver_agent_metadata_and_dependencies(): void
    {
        $agent = new DependencyResolverAgent();

        $this->assertSame('dependency_resolver_agent', $agent->key());
        $this->assertSame('package_manager_agent', $agent->parentAgentKey());
        $this->assertSame(['laravel_installer_agent'], $agent->dependsOn());
        $this->assertContains('composer_runner', $agent->allowedTools());
        $this->assertContains('file_reader', $agent->allowedTools());
        $this->assertContains('composer.json', $agent->scopePaths());
        $this->assertContains('composer.lock', $agent->scopePaths());
    }

    public function test_dependency_resolver_agent_validates_package_name_format(): void
    {
        $agent = new DependencyResolverAgent();
        $fakeLlm = new FakeDriver([
            'structured' => [
                'packages' => [
                    ['name' => 'malicious; rm -rf /', 'version' => '^1.0'],
                ],
                'summary' => 'Malicious test package',
            ],
        ]);

        $tools = [
            'composer_runner' => new ComposerRunnerTool(dryRun: true, workspaceContext: $this->context),
            'file_reader' => new FileReaderTool(pathGuard: $this->pathGuard),
        ];

        $state = RunState::create('Install dependencies', [
            'target_project_path' => $this->tempDir,
            'dry_run' => true,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid Composer package name format');

        $agent->execute($state, $tools, $fakeLlm);
    }

    public function test_dependency_resolver_agent_processes_valid_packages_in_dry_run(): void
    {
        $agent = new DependencyResolverAgent();
        $fakeLlm = new FakeDriver([
            'structured' => [
                'packages' => [
                    ['name' => 'spatie/laravel-permission', 'version' => '^6.0', 'dev' => false, 'reason' => 'RBAC'],
                    ['name' => 'pestphp/pest', 'version' => '^3.0', 'dev' => true, 'reason' => 'Testing'],
                ],
                'summary' => 'Installed 2 packages',
            ],
        ]);

        $tools = [
            'composer_runner' => new ComposerRunnerTool(dryRun: true, workspaceContext: $this->context),
            'file_reader' => new FileReaderTool(pathGuard: $this->pathGuard),
        ];

        $state = RunState::create('Install dependencies', [
            'target_project_path' => $this->tempDir,
            'dry_run' => true,
        ]);

        $result = $agent->execute($state, $tools, $fakeLlm);

        $this->assertTrue($result['success']);
        $this->assertCount(2, $result['output']['installed_packages']);
        $this->assertCount(2, $result['artifacts']);
        $this->assertSame('preview', $result['artifacts'][0]['action']);
        $this->assertSame('preview', $result['artifacts'][1]['action']);
    }

    public function test_structure_scaffolder_agent_metadata_and_dependencies(): void
    {
        $agent = new StructureScaffolderAgent();

        $this->assertSame('structure_scaffolder_agent', $agent->key());
        $this->assertSame('package_manager_agent', $agent->parentAgentKey());
        $this->assertSame(['dependency_resolver_agent'], $agent->dependsOn());
        $this->assertContains('directory_scaffolder', $agent->allowedTools());
        $this->assertContains('patch_writer', $agent->allowedTools());
        $this->assertContains('app/**', $agent->scopePaths());
        $this->assertContains('routes/**', $agent->scopePaths());
    }

    public function test_structure_scaffolder_agent_scaffolds_in_dry_run(): void
    {
        $agent = new StructureScaffolderAgent();
        $fakeLlm = new FakeDriver([
            'structured' => [
                'directories' => [
                    'app/Domains/Invoicing/Models',
                    'app/Domains/Invoicing/Actions',
                ],
                'files' => [
                    [
                        'path' => 'app/Domains/Invoicing/README.md',
                        'content' => '# Invoicing Domain',
                        'summary' => 'Module README',
                    ],
                ],
                'summary' => 'Scaffolded invoicing domain',
            ],
        ]);

        $tools = [
            'directory_scaffolder' => new DirectoryScaffolderTool(dryRun: true, workspaceContext: $this->context),
            'file_reader' => new FileReaderTool(pathGuard: $this->pathGuard),
            'patch_writer' => new PatchWriterTool(pathGuard: $this->pathGuard),
        ];

        $state = RunState::create('Scaffold directories', [
            'target_project_path' => $this->tempDir,
            'dry_run' => true,
        ]);

        $result = $agent->execute($state, $tools, $fakeLlm);

        $this->assertTrue($result['success']);
        $this->assertCount(3, $result['artifacts']);
        $this->assertSame('preview', $result['artifacts'][0]['action']);
        $this->assertSame('preview', $result['artifacts'][1]['action']);
        $this->assertSame('preview', $result['artifacts'][2]['action']);
        $this->assertDirectoryDoesNotExist($this->tempDir . '/app/Domains/Invoicing/Models');
    }
}
