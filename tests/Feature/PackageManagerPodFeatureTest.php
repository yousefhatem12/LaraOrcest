<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Feature;

use Yousef\LaraOrcest\Agents\PackageManagerAgent;
use Yousef\LaraOrcest\Agents\SubAgents\DependencyResolverAgent;
use Yousef\LaraOrcest\Agents\SubAgents\LaravelInstallerAgent;
use Yousef\LaraOrcest\Agents\SubAgents\StructureScaffolderAgent;
use Yousef\LaraOrcest\Agents\Teams\SubAgentRegistry;
use Yousef\LaraOrcest\Graph\DefaultGraphBuilder;
use Yousef\LaraOrcest\LLM\Drivers\FakeDriver;
use Yousef\LaraOrcest\Skills\SkillRegistry;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Support\Config;
use Yousef\LaraOrcest\Tests\TestCase;
use Yousef\LaraOrcest\Tools\ComposerRunnerTool;
use Yousef\LaraOrcest\Tools\DirectoryScaffolderTool;
use Yousef\LaraOrcest\Tools\FileReaderTool;
use Yousef\LaraOrcest\Tools\PatchWriterTool;
use Yousef\LaraOrcest\Tools\Support\PathGuard;
use Yousef\LaraOrcest\Workspace\WorkspaceContext;

class PackageManagerPodFeatureTest extends TestCase
{
    protected string $tempDir;
    protected WorkspaceContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/lara_orcest_pm_feat_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0755, true);
        $this->context = WorkspaceContext::standalone($this->tempDir);
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

    public function test_sub_agents_and_tools_are_registered_in_container(): void
    {
        $this->assertTrue($this->app->bound(PackageManagerAgent::class));
        $this->assertTrue($this->app->bound(LaravelInstallerAgent::class));
        $this->assertTrue($this->app->bound(DependencyResolverAgent::class));
        $this->assertTrue($this->app->bound(StructureScaffolderAgent::class));
        $this->assertTrue($this->app->bound(DirectoryScaffolderTool::class));

        /** @var SubAgentRegistry $subRegistry */
        $subRegistry = $this->app->make(SubAgentRegistry::class);
        $this->assertTrue($subRegistry->has('laravel_installer_agent'));
        $this->assertTrue($subRegistry->has('dependency_resolver_agent'));
        $this->assertTrue($subRegistry->has('structure_scaffolder_agent'));

        $podSubAgents = $subRegistry->forParent('package_manager_agent');
        $this->assertCount(3, $podSubAgents);
        $this->assertArrayHasKey('laravel_installer_agent', $podSubAgents);
        $this->assertArrayHasKey('dependency_resolver_agent', $podSubAgents);
        $this->assertArrayHasKey('structure_scaffolder_agent', $podSubAgents);
    }

    public function test_skill_registry_loads_package_manager_skills(): void
    {
        /** @var SkillRegistry $registry */
        $registry = $this->app->make(SkillRegistry::class);

        $this->assertTrue($registry->has('laravel-project-bootstrapping'));
        $this->assertTrue($registry->has('composer-dependency-management'));
        $this->assertTrue($registry->has('laravel-directory-scaffolding'));

        $pmSkills = $registry->getForAgent('package_manager_agent');
        $this->assertArrayHasKey('laravel-project-bootstrapping', $pmSkills);
        $this->assertArrayHasKey('composer-dependency-management', $pmSkills);
        $this->assertArrayHasKey('laravel-directory-scaffolding', $pmSkills);

        $installerSkills = $registry->getForAgent('laravel_installer_agent', 'package_manager_agent');
        $this->assertArrayHasKey('laravel-project-bootstrapping', $installerSkills);

        $resolverSkills = $registry->getForAgent('dependency_resolver_agent', 'package_manager_agent');
        $this->assertArrayHasKey('composer-dependency-management', $resolverSkills);

        $scaffolderSkills = $registry->getForAgent('structure_scaffolder_agent', 'package_manager_agent');
        $this->assertArrayHasKey('laravel-directory-scaffolding', $scaffolderSkills);
    }

    public function test_package_manager_agent_delegates_in_sequential_order_in_dry_run(): void
    {
        /** @var PackageManagerAgent $pmAgent */
        $pmAgent = $this->app->make(PackageManagerAgent::class);
        $this->assertInstanceOf(PackageManagerAgent::class, $pmAgent);

        $fakeLlm = new FakeDriver();
        $pathGuard = $this->context->createPathGuard();
        $tools = [
            'composer_runner' => new ComposerRunnerTool(dryRun: true, workspaceContext: $this->context),
            'directory_scaffolder' => new DirectoryScaffolderTool(dryRun: true, workspaceContext: $this->context),
            'file_reader' => new FileReaderTool(pathGuard: $pathGuard),
            'patch_writer' => new PatchWriterTool(pathGuard: $pathGuard),
        ];

        Config::set('lara-orcest.teams.enabled', true);

        $state = RunState::create('Bootstrap ecommerce system with Stripe billing', [
            'target_project_path' => $this->tempDir,
            'dry_run' => true,
            'technical_plan' => [
                'composer_dependencies' => [
                    'spatie/laravel-permission' => '^6.0',
                ],
                'directories' => [
                    'app/Domains/Billing',
                    'app/Domains/Billing/Models',
                ],
            ],
        ]);

        $resultState = $pmAgent->execute($state, $tools, $fakeLlm);

        // 1. Verify delegations recorded in sequential order
        $delegations = $resultState->getDelegationsForAgent('package_manager_agent');
        $this->assertCount(3, $delegations);

        $keys = array_column($delegations, 'sub_agent');
        $this->assertSame(['laravel_installer_agent', 'dependency_resolver_agent', 'structure_scaffolder_agent'], $keys);

        foreach ($delegations as $d) {
            $this->assertSame('completed', $d['status']);
            $this->assertNotEmpty($d['active_skills']);
        }

        // 2. Verify state tracking in metadata
        $pmData = $resultState->getPackageManager();
        $this->assertTrue($resultState->isProjectBootstrapped());
        $this->assertSame('completed', $pmData['status']);
        $this->assertNotEmpty($pmData['packages']);
        $this->assertNotEmpty($pmData['scaffolded_directories']);

        // 3. Verify dry-run mode prevented actual disk mutations
        $this->assertDirectoryDoesNotExist($this->tempDir . '/app/Domains/Billing');
    }

    public function test_package_manager_agent_single_agent_fallback_when_teams_disabled(): void
    {
        Config::set('lara-orcest.teams.enabled', false);

        /** @var PackageManagerAgent $pmAgent */
        $pmAgent = $this->app->make(PackageManagerAgent::class);

        $fakeLlm = new FakeDriver();
        $pathGuard = $this->context->createPathGuard();
        $tools = [
            'composer_runner' => new ComposerRunnerTool(dryRun: true, workspaceContext: $this->context),
            'directory_scaffolder' => new DirectoryScaffolderTool(dryRun: true, workspaceContext: $this->context),
            'file_reader' => new FileReaderTool(pathGuard: $pathGuard),
            'patch_writer' => new PatchWriterTool(pathGuard: $pathGuard),
        ];

        $state = RunState::create('Bootstrap blog project', [
            'target_project_path' => $this->tempDir,
            'dry_run' => true,
        ]);

        $resultState = $pmAgent->execute($state, $tools, $fakeLlm);

        // No sub-agent delegations when teams are disabled
        $delegations = $resultState->getDelegationsForAgent('package_manager_agent');
        $this->assertEmpty($delegations);

        // State is still updated properly with artifacts and package manager data
        $this->assertTrue($resultState->isProjectBootstrapped());
        $this->assertNotEmpty($resultState->artifacts);
    }

    public function test_graph_wires_package_manager_agent_when_enabled(): void
    {
        $fakeLlm = new FakeDriver();

        // 1. Graph with package manager enabled (default)
        $graph = DefaultGraphBuilder::build($fakeLlm, withPackageManager: true);
        $this->assertTrue($graph->hasNode('package_manager_agent'));

        // Verify edge transitions
        $engineEdges = $graph->getEdges();
        $this->assertArrayHasKey('git_agent', $engineEdges);
        $this->assertArrayHasKey('package_manager_agent', $engineEdges);

        // 2. Graph with package manager disabled
        $graphDisabled = DefaultGraphBuilder::build($fakeLlm, withPackageManager: false);
        $this->assertFalse($graphDisabled->hasNode('package_manager_agent'));

        // 3. Standalone pipeline
        $pipeline = DefaultGraphBuilder::buildPackageManagerPipeline($fakeLlm);
        $this->assertTrue($pipeline->hasNode('package_manager_agent'));
        $this->assertCount(1, $pipeline->getNodes());
    }

    public function test_scope_paths_and_pathguard_prevent_subagent_boundary_escapes(): void
    {
        $fakeLlm = new FakeDriver([
            'structured' => [
                'directories' => [
                    '../../outside_workspace',
                ],
                'files' => [],
                'summary' => 'Invalid directory',
            ],
        ]);

        /** @var StructureScaffolderAgent $scaffolderAgent */
        $scaffolderAgent = $this->app->make(StructureScaffolderAgent::class);

        $pathGuard = $this->context->createPathGuard();
        $tools = [
            'directory_scaffolder' => new DirectoryScaffolderTool(dryRun: true, workspaceContext: $this->context),
            'file_reader' => new FileReaderTool(pathGuard: $pathGuard),
        ];

        $state = RunState::create('Scaffold', [
            'target_project_path' => $this->tempDir,
            'dry_run' => true,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Path escapes project root');

        $scaffolderAgent->execute($state, $tools, $fakeLlm);
    }
}
