<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Feature;

use Yousef\LaraOrcest\Agents\PackageManagerAgent;
use Yousef\LaraOrcest\Agents\SubAgents\DependencyResolverAgent;
use Yousef\LaraOrcest\Agents\SubAgents\LaravelInstallerAgent;
use Yousef\LaraOrcest\Agents\SubAgents\StructureScaffolderAgent;
use Yousef\LaraOrcest\Agents\Teams\SubAgentRegistry;
use Yousef\LaraOrcest\LLM\Drivers\FakeDriver;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Support\Config;
use Yousef\LaraOrcest\Support\TerminalAnnouncer;
use Yousef\LaraOrcest\Tests\TestCase;
use Yousef\LaraOrcest\Tools\ComposerRunnerTool;
use Yousef\LaraOrcest\Tools\DirectoryScaffolderTool;
use Yousef\LaraOrcest\Tools\FileReaderTool;
use Yousef\LaraOrcest\Tools\PatchWriterTool;
use Yousef\LaraOrcest\Tools\Support\PathGuard;
use Yousef\LaraOrcest\Workspace\WorkspaceContext;

class PackageManagerVersionDisclosureAndPromptTest extends TestCase
{
    protected string $tempDir;
    protected WorkspaceContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        TerminalAnnouncer::flush();
        $this->tempDir = sys_get_temp_dir() . '/lara_orcest_disc_test_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0755, true);
        $this->context = WorkspaceContext::standalone($this->tempDir);
    }

    protected function tearDown(): void
    {
        TerminalAnnouncer::flush();
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

    public function test_laravel_installer_agent_discloses_target_version_and_records_prompt_spec(): void
    {
        $installer = new LaravelInstallerAgent();
        $this->assertEquals(['laravel-project-bootstrapping'], $installer->skills());

        $composerRunner = new ComposerRunnerTool(dryRun: true, workspaceContext: $this->context);

        $state = RunState::create('Build multi-tenant Task Management API with Stripe integration', [
            'target_project_path' => $this->tempDir,
            'dry_run' => true,
        ]);

        $llm = new FakeDriver([
            'structured' => [
                'status' => 'initialized',
                'action' => 'bootstrap_project',
                'composer_command' => 'composer create-project laravel/laravel . --prefer-dist --no-interaction',
                'summary' => 'Bootstrapping fresh Laravel project',
            ],
        ]);

        $result = $installer->execute(
            state: $state,
            tools: ['composer_runner' => $composerRunner],
            llm: $llm,
            context: ['prompt' => $state->prompt]
        );

        $this->assertTrue($result['success']);
        $this->assertEquals('Build multi-tenant Task Management API with Stripe integration', $result['output']['prompt_specification']);
        $this->assertNotEmpty($result['output']['target_laravel_version']);

        $announcements = TerminalAnnouncer::getAnnouncements();
        $this->assertNotEmpty($announcements);

        $versionDisclosed = false;
        foreach ($announcements as $announcement) {
            if (str_contains($announcement, 'Target Version: Preparing to install Laravel Skeleton')) {
                $versionDisclosed = true;
                break;
            }
        }
        $this->assertTrue($versionDisclosed, 'Laravel installer agent must disclose the target Laravel version in terminal.');
    }

    public function test_dependency_resolver_agent_discloses_package_versions_and_records_prompt_spec(): void
    {
        $resolver = new DependencyResolverAgent();
        $this->assertEquals(['composer-dependency-management'], $resolver->skills());

        $composerRunner = new ComposerRunnerTool(dryRun: true, workspaceContext: $this->context);

        $state = RunState::create('Install Spatie Permissions and Pest for Task API', [
            'target_project_path' => $this->tempDir,
            'dry_run' => true,
        ]);

        $llm = new FakeDriver([
            'structured' => [
                'packages' => [
                    [
                        'name' => 'spatie/laravel-permission',
                        'version' => '^6.0',
                        'dev' => false,
                        'reason' => 'Role and permission management',
                    ],
                    [
                        'name' => 'pestphp/pest',
                        'version' => '^2.0',
                        'dev' => true,
                        'reason' => 'Modern PHP testing framework',
                    ],
                ],
                'summary' => 'Resolved 2 required packages',
            ],
        ]);

        $result = $resolver->execute(
            state: $state,
            tools: ['composer_runner' => $composerRunner],
            llm: $llm,
            context: ['prompt' => $state->prompt]
        );

        $this->assertTrue($result['success']);
        $this->assertEquals('Install Spatie Permissions and Pest for Task API', $result['output']['prompt_specification']);

        $announcements = TerminalAnnouncer::getAnnouncements();
        $this->assertNotEmpty($announcements);

        $foundSpatie = false;
        $foundPest = false;
        foreach ($announcements as $announcement) {
            if (str_contains($announcement, 'spatie/laravel-permission:^6.0')) {
                $foundSpatie = true;
            }
            if (str_contains($announcement, 'pestphp/pest:^2.0 (dev)')) {
                $foundPest = true;
            }
        }

        $this->assertTrue($foundSpatie, 'Dependency resolver must disclose spatie/laravel-permission version in terminal.');
        $this->assertTrue($foundPest, 'Dependency resolver must disclose pestphp/pest version in terminal.');
    }

    public function test_package_manager_agent_discloses_versions_ingests_prompt_and_respects_dry_run(): void
    {
        Config::set('lara-orcest.teams.enabled', true);

        $pmAgent = $this->app->make(PackageManagerAgent::class);
        $this->assertNotEmpty($pmAgent->getSkills());

        $state = RunState::create('Build multi-tenant Task & Project Management API', [
            'target_project_path' => $this->tempDir,
            'dry_run' => true,
            'technical_plan' => [
                'composer_dependencies' => [
                    'spatie/laravel-permission' => '^6.0',
                ],
            ],
        ]);

        $pathGuard = $this->context->createPathGuard();
        $tools = [
            'composer_runner' => new ComposerRunnerTool(dryRun: true, workspaceContext: $this->context),
            'directory_scaffolder' => new DirectoryScaffolderTool(dryRun: true, workspaceContext: $this->context),
            'file_reader' => new FileReaderTool(pathGuard: $pathGuard),
            'patch_writer' => new PatchWriterTool(pathGuard: $pathGuard),
        ];

        $llm = new FakeDriver();

        $finalState = $pmAgent->execute($state, $tools, $llm);

        $pmMetadata = $finalState->metadata['package_manager'] ?? [];
        $this->assertNotEmpty($pmMetadata);
        $this->assertEquals('completed', $pmMetadata['status']);
        $this->assertTrue($pmMetadata['bootstrapped']);
        $this->assertEquals('Build multi-tenant Task & Project Management API', $pmMetadata['prompt_specification']);

        $announcements = TerminalAnnouncer::getAnnouncements();
        $this->assertNotEmpty($announcements);

        $foundPrompt = false;
        $foundSkeleton = false;
        $foundPackage = false;

        foreach ($announcements as $msg) {
            if (str_contains($msg, '[package_manager_agent] Prompt Specification: Build multi-tenant Task & Project Management API')) {
                $foundPrompt = true;
            }
            if (str_contains($msg, 'Target Version: Preparing to install Laravel Skeleton')) {
                $foundSkeleton = true;
            }
            if (str_contains($msg, 'spatie/laravel-permission:^6.0')) {
                $foundPackage = true;
            }
        }

        $this->assertTrue($foundPrompt, 'PackageManagerAgent must disclose prompt specification in terminal.');
        $this->assertTrue($foundSkeleton, 'PackageManagerAgent must disclose target Laravel version in terminal.');
        $this->assertTrue($foundPackage, 'PackageManagerAgent must disclose target package versions in terminal.');

        // In dry-run mode, artifacts must be recorded as preview
        $artifacts = $finalState->artifacts;
        $this->assertNotEmpty($artifacts);
        foreach ($artifacts as $artifact) {
            $this->assertEquals('preview', $artifact['action']);
        }
    }

    public function test_package_manager_ingests_prompt_discloses_plan_laravel_version_and_modifies_target_workspace(): void
    {
        Config::set('lara-orcest.teams.enabled', true);

        $pmAgent = $this->app->make(PackageManagerAgent::class);

        // Pre-populate composer.json so ComposerRunnerTool does not fail offline while scaffolding executes live
        file_put_contents($this->tempDir . '/composer.json', json_encode([
            'name' => 'acme/billing-app',
            'require' => ['laravel/framework' => '^12.0'],
        ]));

        $prompt = 'Build Billing module with Stripe subscriptions and RBAC';
        $state = RunState::create($prompt, [
            'target_project_path' => $this->tempDir,
            'dry_run' => false,
            'technical_plan' => [
                'laravel_version' => '^12.0',
                'composer_dependencies' => [
                    'laravel/cashier' => '^15.0',
                ],
            ],
        ]);

        $pathGuard = $this->context->createPathGuard();
        $mockComposerRunner = new class(projectRoot: $this->tempDir, dryRun: false, workspaceContext: $this->context) extends ComposerRunnerTool {
            public array $executedCommands = [];
            public function execute(array $params): array
            {
                $this->executedCommands[] = $params;
                return [
                    'command' => $params['command'],
                    'arguments' => $params['arguments'] ?? [],
                    'exit_code' => 0,
                    'output' => 'Installed successfully',
                    'successful' => true,
                    'dry_run' => false,
                ];
            }
        };

        $tools = [
            'composer_runner' => $mockComposerRunner,
            'directory_scaffolder' => new DirectoryScaffolderTool(projectRoot: $this->tempDir, pathGuard: $pathGuard, dryRun: false, workspaceContext: $this->context),
            'file_reader' => new FileReaderTool(pathGuard: $pathGuard),
            'patch_writer' => new PatchWriterTool(pathGuard: $pathGuard),
        ];

        $llm = new FakeDriver();
        $finalState = $pmAgent->execute($state, $tools, $llm);

        // 1. Sub-agent prompts ingested developer prompt specification
        $recordedCalls = $llm->getRecordedCalls();
        $this->assertNotEmpty($recordedCalls);
        foreach ($recordedCalls as $call) {
            $userMsg = '';
            foreach ($call['messages'] as $m) {
                if ($m['role'] === 'user') {
                    $userMsg = (string) $m['content'];
                }
            }
            $this->assertStringContainsString($prompt, $userMsg);
        }

        // 2. Terminal disclosed Laravel version, detected constraint, and technical_plan package versions BEFORE install
        $announcements = TerminalAnnouncer::getAnnouncements();
        $joined = implode("\n", $announcements);
        $this->assertStringContainsString($prompt, $joined);
        $this->assertStringContainsString('laravel/laravel:^12.0', $joined);
        $this->assertStringContainsString('detected laravel/framework:^12.0', $joined);
        $this->assertStringContainsString('laravel/cashier:^15.0', $joined);
        $this->assertStringContainsString('spatie/laravel-permission:^6.0', $joined);

        // 3. Live execution modified targetProjectPath
        $this->assertDirectoryExists($this->tempDir . '/app/Domains/Billing/Models');
        $this->assertFileExists($this->tempDir . '/app/Domains/Billing/README.md');

        // 4. State recorded package_manager metadata cleanly
        $pmMeta = $finalState->getPackageManager();
        $this->assertSame($prompt, $pmMeta['prompt_specification']);
        $this->assertSame('^12.0', $pmMeta['target_laravel_version']);
        $this->assertContains('laravel/cashier:^15.0', $pmMeta['target_packages']);
    }

    public function test_interactive_hitl_pauses_before_package_manager_execution_and_no_human_review_runs_through(): void
    {
        file_put_contents($this->tempDir . '/artisan', "#!/usr/bin/env php\n<?php exit(0);\n");
        file_put_contents($this->tempDir . '/composer.json', json_encode(['name' => 'test/app', 'require' => ['laravel/framework' => '^12.0']]));
        mkdir($this->tempDir . '/app/Models', 0755, true);
        mkdir($this->tempDir . '/database/migrations', 0755, true);
        mkdir($this->tempDir . '/database/factories', 0755, true);
        mkdir($this->tempDir . '/database/seeders', 0755, true);
        mkdir($this->tempDir . '/app/Http/Controllers', 0755, true);
        mkdir($this->tempDir . '/app/Http/Requests', 0755, true);
        mkdir($this->tempDir . '/app/Http/Resources', 0755, true);
        mkdir($this->tempDir . '/app/Actions', 0755, true);
        mkdir($this->tempDir . '/routes', 0755, true);
        mkdir($this->tempDir . '/tests/Feature', 0755, true);
        mkdir($this->tempDir . '/docs', 0755, true);
        file_put_contents($this->tempDir . '/routes/api.php', "<?php\n");

        $orchestrator = new \Yousef\LaraOrcest\Orchestrator(workspaceContext: $this->context);

        // 1. --interactive pauses at after_plan BEFORE package_manager_agent mutates disk
        $paused = $orchestrator->run('Build Billing API with interactive gate', [
            'target_project_path' => $this->tempDir,
            'llm' => 'fake',
            'interactive' => true,
        ]);

        $this->assertTrue($paused->isPaused());
        $this->assertSame('after_plan', $paused->getPausedGate());
        $this->assertDirectoryDoesNotExist($this->tempDir . '/app/Domains/Billing');

        // 2. --no-human-review executes autonomously through PackageManager and downstream pods
        $completed = $orchestrator->run('Build Billing API autonomously', [
            'target_project_path' => $this->tempDir,
            'llm' => 'fake',
            'no_human_review' => true,
        ]);

        $this->assertTrue($completed->isCompleted());
        $this->assertDirectoryExists($this->tempDir . '/app/Domains/Billing/Models');
        $this->assertFileExists($this->tempDir . '/app/Models/Project.php');
    }
}
