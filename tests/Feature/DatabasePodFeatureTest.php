<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Feature;

use Yousef\LaraOrcest\Agents\DatabaseAgent;
use Yousef\LaraOrcest\Agents\SubAgents\MigrationAgent;
use Yousef\LaraOrcest\Agents\SubAgents\ModelEloquentAgent;
use Yousef\LaraOrcest\Agents\SubAgents\SeederAgent;
use Yousef\LaraOrcest\Agents\Teams\SubAgentRegistry;
use Yousef\LaraOrcest\LLM\Drivers\FakeDriver;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Support\Config;
use Yousef\LaraOrcest\Tests\TestCase;
use Yousef\LaraOrcest\Tools\ArtisanRunnerTool;
use Yousef\LaraOrcest\Tools\FileReaderTool;
use Yousef\LaraOrcest\Tools\PatchWriterTool;
use Yousef\LaraOrcest\Tools\Support\PathGuard;

class DatabasePodFeatureTest extends TestCase
{
    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/lara_orcest_db_feat_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir . '/app/Models', 0777, true);
        mkdir($this->tempDir . '/database/migrations', 0777, true);
        mkdir($this->tempDir . '/database/factories', 0777, true);
        mkdir($this->tempDir . '/database/seeders', 0777, true);
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

    public function test_database_agent_delegates_to_sub_agents_in_sequential_waves(): void
    {
        $dbAgent = $this->app->make(DatabaseAgent::class);
        $this->assertInstanceOf(DatabaseAgent::class, $dbAgent);

        $fakeLlm = new FakeDriver();
        $guard = new PathGuard($this->tempDir);
        $tools = [
            'file_reader' => new FileReaderTool($guard),
            'patch_writer' => new PatchWriterTool(pathGuard: $guard),
            'artisan_runner' => new ArtisanRunnerTool($this->tempDir),
        ];

        Config::set('lara-orcest.teams.enabled', true);

        $state = RunState::create('Create database layer for project and task tracking', [
            'technical_plan' => [
                'migrations' => [
                    'create_projects_table' => 'database/migrations/2026_01_01_000001_create_projects_table.php',
                    'create_tasks_table' => 'database/migrations/2026_01_01_000002_create_tasks_table.php',
                ],
                'models' => [
                    'Project' => 'app/Models/Project.php',
                    'Task' => 'app/Models/Task.php',
                ],
                'execution_steps' => [
                    'Run database migrations in chronological order',
                    'Implement Eloquent models and factories',
                    'Create idempotent seeders',
                ],
            ],
        ]);

        $resultState = $dbAgent->execute($state, $tools, $fakeLlm);

        // 1. Verify delegations tracked for database_agent
        $delegations = $resultState->getDelegationsForAgent('database_agent');
        $this->assertCount(3, $delegations);

        $subKeys = array_column($delegations, 'sub_agent');
        $this->assertEquals(['migration_agent', 'model_eloquent_agent', 'seeder_agent'], $subKeys);

        foreach ($delegations as $d) {
            $this->assertEquals('completed', $d['status']);
            $this->assertNotEmpty($d['active_skills']);
        }

        // 2. Verify artifacts collected in RunState with sub-agent attribution
        $artifacts = $resultState->artifacts;
        $this->assertNotEmpty($artifacts);

        $paths = array_column($artifacts, 'path');
        $this->assertContains('database/migrations/2026_01_01_000001_create_projects_table.php', $paths);
        $this->assertContains('database/migrations/2026_01_01_000002_create_tasks_table.php', $paths);
        $this->assertContains('app/Models/Project.php', $paths);
        $this->assertContains('app/Models/Task.php', $paths);
        $this->assertContains('database/factories/ProjectFactory.php', $paths);
        $this->assertContains('database/seeders/ProjectSeeder.php', $paths);
        $this->assertContains('database/seeders/DatabaseSeeder.php', $paths);

        // Verify sub-agent attribution on artifacts
        $migrationArts = array_filter($artifacts, fn ($a) => ($a['sub_agent'] ?? '') === 'migration_agent');
        $this->assertCount(2, $migrationArts);

        $modelArts = array_filter($artifacts, fn ($a) => ($a['sub_agent'] ?? '') === 'model_eloquent_agent');
        $this->assertCount(3, $modelArts);

        $seederArts = array_filter($artifacts, fn ($a) => ($a['sub_agent'] ?? '') === 'seeder_agent');
        $this->assertCount(2, $seederArts);

        // 3. Verify actual files created on disk
        $this->assertFileExists($this->tempDir . '/database/migrations/2026_01_01_000001_create_projects_table.php');
        $this->assertFileExists($this->tempDir . '/app/Models/Project.php');
        $this->assertFileExists($this->tempDir . '/database/seeders/ProjectSeeder.php');
    }

    public function test_database_agent_dry_run_mode_does_not_mutate_disk(): void
    {
        $dbAgent = $this->app->make(DatabaseAgent::class);
        $fakeLlm = new FakeDriver();
        $guard = new PathGuard($this->tempDir);
        $tools = [
            'file_reader' => new FileReaderTool($guard),
            'patch_writer' => new PatchWriterTool(pathGuard: $guard),
            'artisan_runner' => new ArtisanRunnerTool($this->tempDir),
        ];

        Config::set('lara-orcest.teams.enabled', true);

        $state = RunState::create('Preview database changes in dry run mode', [
            'dry_run' => true,
            'technical_plan' => [
                'migrations' => ['create_projects_table' => 'database/migrations/2026_01_01_000001_create_projects_table.php'],
            ],
        ]);

        $resultState = $dbAgent->execute($state, $tools, $fakeLlm);

        // Delegations should still complete
        $delegations = $resultState->getDelegationsForAgent('database_agent');
        $this->assertCount(3, $delegations);

        // Artifacts recorded as preview
        $artifacts = $resultState->artifacts;
        $this->assertNotEmpty($artifacts);
        foreach ($artifacts as $art) {
            $this->assertEquals('preview', $art['action']);
        }

        // No files actually written to disk in dry-run mode
        $this->assertFileDoesNotExist($this->tempDir . '/database/migrations/2026_01_01_000001_create_projects_table.php');
        $this->assertFileDoesNotExist($this->tempDir . '/app/Models/Project.php');
        $this->assertFileDoesNotExist($this->tempDir . '/database/seeders/ProjectSeeder.php');
    }

    public function test_database_agent_falls_back_when_teams_disabled(): void
    {
        $dbAgent = $this->app->make(DatabaseAgent::class);
        $fakeLlm = new FakeDriver();
        $guard = new PathGuard($this->tempDir);
        $tools = [
            'file_reader' => new FileReaderTool($guard),
            'patch_writer' => new PatchWriterTool(pathGuard: $guard),
            'artisan_runner' => new ArtisanRunnerTool($this->tempDir),
        ];

        Config::set('lara-orcest.teams.enabled', false);

        $state = RunState::create('Single agent execution without teams');
        $resultState = $dbAgent->execute($state, $tools, $fakeLlm);

        // No sub-agent delegations when teams are disabled
        $delegations = $resultState->getDelegationsForAgent('database_agent');
        $this->assertEmpty($delegations);

        // Artifacts produced via parent fallback execution
        $this->assertNotEmpty($resultState->artifacts);
    }

    public function test_database_sub_agent_model_routing_and_fallbacks(): void
    {
        Config::set('lara-orcest.llm.agent_models.database_agent', 'anthropic/claude-3-5-sonnet');
        Config::set('lara-orcest.llm.agent_models.migration_agent', 'openai/gpt-4o');
        Config::set('lara-orcest.llm.agent_models.model_eloquent_agent', null);
        Config::set('lara-orcest.llm.agent_models.seeder_agent', null);

        $migAgent = new MigrationAgent();
        $modelAgent = new ModelEloquentAgent();
        $seederAgent = new SeederAgent();

        $this->assertEquals('openai/gpt-4o', $migAgent->resolveModel());
        $this->assertEquals('anthropic/claude-3-5-sonnet', $modelAgent->resolveModel());
        $this->assertEquals('anthropic/claude-3-5-sonnet', $seederAgent->resolveModel());
    }

    public function test_service_provider_registers_database_sub_agents(): void
    {
        // 1. Container bindings
        $this->assertTrue($this->app->bound(MigrationAgent::class));
        $this->assertTrue($this->app->bound(ModelEloquentAgent::class));
        $this->assertTrue($this->app->bound(SeederAgent::class));

        // 2. SubAgentRegistry contains all three
        $subRegistry = $this->app->make(SubAgentRegistry::class);
        $this->assertTrue($subRegistry->has('migration_agent'));
        $this->assertTrue($subRegistry->has('model_eloquent_agent'));
        $this->assertTrue($subRegistry->has('seeder_agent'));

        // 3. Dependency ordering in registry
        $mig = $subRegistry->get('migration_agent');
        $this->assertEquals([], $mig->dependsOn());

        $model = $subRegistry->get('model_eloquent_agent');
        $this->assertEquals(['migration_agent'], $model->dependsOn());

        $seeder = $subRegistry->get('seeder_agent');
        $this->assertEquals(['model_eloquent_agent'], $seeder->dependsOn());
    }
}
