<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Unit;

use InvalidArgumentException;
use Yousef\LaraOrcest\Agents\SubAgents\MigrationAgent;
use Yousef\LaraOrcest\Agents\SubAgents\ModelEloquentAgent;
use Yousef\LaraOrcest\Agents\SubAgents\SeederAgent;
use Yousef\LaraOrcest\LLM\Drivers\FakeDriver;
use Yousef\LaraOrcest\Skills\SkillRegistry;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Tests\TestCase;
use Yousef\LaraOrcest\Tools\ArtisanRunnerTool;
use Yousef\LaraOrcest\Tools\FileReaderTool;
use Yousef\LaraOrcest\Tools\PatchWriterTool;
use Yousef\LaraOrcest\Tools\ScopedToolProxy;
use Yousef\LaraOrcest\Tools\Support\PathGuard;

class DatabasePodTest extends TestCase
{
    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/lara_orcest_db_test_' . bin2hex(random_bytes(6));
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

    public function test_database_pod_skills_are_registered_and_parsed(): void
    {
        $registry = new SkillRegistry();
        $registry->loadAll();

        $this->assertTrue($registry->has('laravel-safe-migrations'));
        $this->assertTrue($registry->has('eloquent-models-and-factories'));
        $this->assertTrue($registry->has('idempotent-database-seeders'));

        $migSkill = $registry->get('laravel-safe-migrations');
        $this->assertNotNull($migSkill);
        $this->assertTrue($migSkill->appliesToAgent('database_agent'));
        $this->assertTrue($migSkill->appliesToAgent('migration_agent'));
        $this->assertTrue($migSkill->permitsTool('patch_writer'));
        $this->assertContains('migrations', $migSkill->tags);

        $modelSkill = $registry->get('eloquent-models-and-factories');
        $this->assertNotNull($modelSkill);
        $this->assertTrue($modelSkill->appliesToAgent('database_agent'));
        $this->assertTrue($modelSkill->appliesToAgent('model_eloquent_agent'));
        $this->assertTrue($modelSkill->permitsTool('patch_writer'));
        $this->assertContains('eloquent', $modelSkill->tags);

        $seederSkill = $registry->get('idempotent-database-seeders');
        $this->assertNotNull($seederSkill);
        $this->assertTrue($seederSkill->appliesToAgent('database_agent'));
        $this->assertTrue($seederSkill->appliesToAgent('seeder_agent'));
        $this->assertTrue($seederSkill->permitsTool('patch_writer'));
        $this->assertContains('seeders', $seederSkill->tags);
    }

    public function test_migration_agent_properties_and_execution(): void
    {
        $agent = new MigrationAgent();

        $this->assertEquals('migration_agent', $agent->key());
        $this->assertEquals('database_agent', $agent->parentAgentKey());
        $this->assertStringContainsString('Migration', $agent->role());
        $this->assertNotEmpty($agent->goal());
        $this->assertNotEmpty($agent->backstory());
        $this->assertEquals(['database/migrations/**'], $agent->scopePaths());
        $this->assertEquals(['file_reader', 'patch_writer', 'artisan_runner'], $agent->allowedTools());
        $this->assertEquals([], $agent->dependsOn());

        $llm = new FakeDriver();
        $guard = new PathGuard($this->tempDir);
        $patchTool = new PatchWriterTool(pathGuard: $guard);
        $tools = [
            'file_reader' => new FileReaderTool($guard),
            'patch_writer' => new ScopedToolProxy($patchTool, $agent->scopePaths(), $guard, $agent),
            'artisan_runner' => new ArtisanRunnerTool($this->tempDir),
        ];

        $state = RunState::create('Create projects and tasks tables');
        $result = $agent->execute($state, $tools, $llm);

        $this->assertTrue($result['success']);
        $this->assertEquals('migration_agent', $result['sub_agent']);
        $this->assertCount(2, $result['artifacts']);

        // Verify file created on disk
        $this->assertFileExists($this->tempDir . '/database/migrations/2026_01_01_000001_create_projects_table.php');
        $this->assertFileExists($this->tempDir . '/database/migrations/2026_01_01_000002_create_tasks_table.php');
    }

    public function test_migration_agent_scope_paths_enforcement(): void
    {
        $agent = new MigrationAgent();
        $guard = new PathGuard($this->tempDir);
        $patchTool = new PatchWriterTool(pathGuard: $guard);
        $proxy = new ScopedToolProxy($patchTool, $agent->scopePaths(), $guard, $agent);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('outside permitted scope');

        // Attempting to write a model through migration_agent tool proxy must fail
        $proxy->execute([
            'path' => 'app/Models/User.php',
            'content' => "<?php\n\nclass User {}\n",
            'summary' => 'Sneak a model into migrations agent',
        ]);
    }

    public function test_model_eloquent_agent_properties_and_execution(): void
    {
        $agent = new ModelEloquentAgent();

        $this->assertEquals('model_eloquent_agent', $agent->key());
        $this->assertEquals('database_agent', $agent->parentAgentKey());
        $this->assertStringContainsString('Model', $agent->role());
        $this->assertNotEmpty($agent->goal());
        $this->assertNotEmpty($agent->backstory());
        $this->assertEquals(['app/Models/**', 'app/Domains/**/Models/**', 'database/factories/**'], $agent->scopePaths());
        $this->assertEquals(['file_reader', 'patch_writer', 'artisan_runner'], $agent->allowedTools());
        $this->assertEquals(['migration_agent'], $agent->dependsOn());

        $llm = new FakeDriver();
        $guard = new PathGuard($this->tempDir);
        $patchTool = new PatchWriterTool(pathGuard: $guard);
        $tools = [
            'file_reader' => new FileReaderTool($guard),
            'patch_writer' => new ScopedToolProxy($patchTool, $agent->scopePaths(), $guard, $agent),
            'artisan_runner' => new ArtisanRunnerTool($this->tempDir),
        ];

        $state = RunState::create('Create Project and Task models and factories');
        $result = $agent->execute($state, $tools, $llm);

        $this->assertTrue($result['success']);
        $this->assertEquals('model_eloquent_agent', $result['sub_agent']);
        $this->assertCount(3, $result['artifacts']);

        // Verify files created on disk
        $this->assertFileExists($this->tempDir . '/app/Models/Project.php');
        $this->assertFileExists($this->tempDir . '/app/Models/Task.php');
        $this->assertFileExists($this->tempDir . '/database/factories/ProjectFactory.php');
    }

    public function test_model_eloquent_agent_scope_paths_enforcement(): void
    {
        $agent = new ModelEloquentAgent();
        $guard = new PathGuard($this->tempDir);
        $patchTool = new PatchWriterTool(pathGuard: $guard);
        $proxy = new ScopedToolProxy($patchTool, $agent->scopePaths(), $guard, $agent);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('outside permitted scope');

        // Attempting to write a controller through model_eloquent_agent tool proxy must fail
        $proxy->execute([
            'path' => 'app/Http/Controllers/TaskController.php',
            'content' => "<?php\n\nclass TaskController {}\n",
            'summary' => 'Sneak a controller into model agent',
        ]);
    }

    public function test_seeder_agent_properties_and_execution(): void
    {
        $agent = new SeederAgent();

        $this->assertEquals('seeder_agent', $agent->key());
        $this->assertEquals('database_agent', $agent->parentAgentKey());
        $this->assertStringContainsString('Seeder', $agent->role());
        $this->assertNotEmpty($agent->goal());
        $this->assertNotEmpty($agent->backstory());
        $this->assertEquals(['database/seeders/**'], $agent->scopePaths());
        $this->assertEquals(['file_reader', 'patch_writer', 'artisan_runner'], $agent->allowedTools());
        $this->assertEquals(['model_eloquent_agent'], $agent->dependsOn());

        $llm = new FakeDriver();
        $guard = new PathGuard($this->tempDir);
        $patchTool = new PatchWriterTool(pathGuard: $guard);
        $tools = [
            'file_reader' => new FileReaderTool($guard),
            'patch_writer' => new ScopedToolProxy($patchTool, $agent->scopePaths(), $guard, $agent),
            'artisan_runner' => new ArtisanRunnerTool($this->tempDir),
        ];

        $state = RunState::create('Create idempotent seeders');
        $result = $agent->execute($state, $tools, $llm);

        $this->assertTrue($result['success']);
        $this->assertEquals('seeder_agent', $result['sub_agent']);
        $this->assertCount(2, $result['artifacts']);

        // Verify files created on disk
        $this->assertFileExists($this->tempDir . '/database/seeders/ProjectSeeder.php');
        $this->assertFileExists($this->tempDir . '/database/seeders/DatabaseSeeder.php');
    }

    public function test_seeder_agent_scope_paths_enforcement(): void
    {
        $agent = new SeederAgent();
        $guard = new PathGuard($this->tempDir);
        $patchTool = new PatchWriterTool(pathGuard: $guard);
        $proxy = new ScopedToolProxy($patchTool, $agent->scopePaths(), $guard, $agent);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('outside permitted scope');

        // Attempting to write a migration through seeder_agent tool proxy must fail
        $proxy->execute([
            'path' => 'database/migrations/2026_01_01_000000_fake_table.php',
            'content' => "<?php\n\nreturn new class {};\n",
            'summary' => 'Sneak a migration into seeder agent',
        ]);
    }
}
