<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Feature;

use RuntimeException;
use Yousef\LaraOrcest\Agents\SubAgents\MigrationAgent;
use Yousef\LaraOrcest\Agents\SwaggerAgent;
use Yousef\LaraOrcest\Agents\Teams\TeamDelegator;
use Yousef\LaraOrcest\LLM\Drivers\FakeDriver;
use Yousef\LaraOrcest\Skills\Skill;
use Yousef\LaraOrcest\Skills\SkillRegistry;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Tests\TestCase;
use Yousef\LaraOrcest\Tools\CodebaseInspectorTool;
use Yousef\LaraOrcest\Tools\FileReaderTool;
use Yousef\LaraOrcest\Tools\PatchWriterTool;
use Yousef\LaraOrcest\Tools\Support\PathGuard;
use Yousef\LaraOrcest\Tools\ToolRegistry;

class SkillFileBoundariesFeatureTest extends TestCase
{
    protected string $tempDir;
    protected PathGuard $pathGuard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/lara_orcest_skill_bounds_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir . '/app/Models', 0755, true);
        mkdir($this->tempDir . '/app/Http/Controllers', 0755, true);
        mkdir($this->tempDir . '/database/migrations', 0755, true);
        mkdir($this->tempDir . '/resources/views', 0755, true);
        mkdir($this->tempDir . '/routes', 0755, true);
        mkdir($this->tempDir . '/docs', 0755, true);
        mkdir($this->tempDir . '/vendor/some/pkg', 0755, true);

        file_put_contents($this->tempDir . '/composer.json', '{"name":"test/app"}');
        file_put_contents($this->tempDir . '/app/Models/Order.php', "<?php\nnamespace App\\Models;\nclass Order extends Model {}\n");
        file_put_contents($this->tempDir . '/app/Http/Controllers/OrderController.php', "<?php\nnamespace App\\Http\\Controllers;\nclass OrderController {}\n");
        file_put_contents($this->tempDir . '/database/migrations/2026_01_01_000001_create_orders_table.php', "<?php\n");
        file_put_contents($this->tempDir . '/resources/views/welcome.blade.php', "<h1>Welcome</h1>\n");
        file_put_contents($this->tempDir . '/routes/api.php', "<?php\n");
        file_put_contents($this->tempDir . '/vendor/some/pkg/Secret.php', "<?php\n");

        $this->pathGuard = new PathGuard($this->tempDir);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tempDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $file) {
                $file->isDir() ? @rmdir($file->getRealPath()) : @unlink($file->getRealPath());
            }
            @rmdir($this->tempDir);
        }
        parent::tearDown();
    }

    public function test_skill_registry_parses_and_exposes_explore_modify_and_ignore_paths(): void
    {
        /** @var SkillRegistry $registry */
        $registry = $this->app->make(SkillRegistry::class);

        $migrationSkill = $registry->get('laravel-safe-migrations');
        $this->assertNotNull($migrationSkill);
        $this->assertContains('database/migrations/**', $migrationSkill->explorePaths());
        $this->assertContains('app/Models/**', $migrationSkill->explorePaths());
        $this->assertSame(['database/migrations/**'], $migrationSkill->modifyPaths());
        $this->assertContains('vendor/**', $migrationSkill->ignorePaths());
        $this->assertContains('resources/**', $migrationSkill->ignorePaths());

        $exploreUnion = $registry->explorePaths('migration_agent', ['laravel-safe-migrations']);
        $this->assertContains('database/migrations/**', $exploreUnion);
        $this->assertContains('app/Models/**', $exploreUnion);

        $modify = $registry->modifyPaths('migration_agent', ['laravel-safe-migrations']);
        $this->assertSame(['database/migrations/**'], $modify);

        $ignore = $registry->ignorePaths('migration_agent', ['laravel-safe-migrations']);
        $this->assertContains('vendor/**', $ignore);

        $map = $registry->buildAllowedFileMap('migration_agent', ['laravel-safe-migrations'], 'database_agent', ['database/migrations/**']);
        $this->assertStringContainsString('ALLOWED FILE MAP', $map);
        $this->assertStringContainsString('Explore Only (Read):', $map);
        $this->assertStringContainsString('Can Modify (Read & Write): database/migrations/**', $map);
        $this->assertStringContainsString('Ignore (Never Load):', $map);
    }

    public function test_file_reader_and_codebase_inspector_filter_and_block_outside_explore_paths(): void
    {
        /** @var SkillRegistry $registry */
        $registry = $this->app->make(SkillRegistry::class);
        $migrationSkill = $registry->get('laravel-safe-migrations');
        $this->assertNotNull($migrationSkill);

        $toolRegistry = new ToolRegistry($this->pathGuard);
        $resolved = $toolRegistry->resolveForAgent(
            agent: new MigrationAgent(),
            availableTools: [
                'file_reader' => new FileReaderTool($this->pathGuard),
                'patch_writer' => new PatchWriterTool($this->pathGuard),
                'codebase_inspector' => new CodebaseInspectorTool($this->pathGuard),
            ],
            scopePaths: (new MigrationAgent())->scopePaths(),
            activeSkill: [$migrationSkill]
        );

        /** @var FileReaderTool|\Yousef\LaraOrcest\Tools\ScopedToolProxy $reader */
        $reader = $resolved['file_reader'];

        // Allowed read in explore_paths (app/Models/**) even though agent scopePaths is database/migrations/**
        $modelRead = $reader->execute(['path' => 'app/Models/Order.php']);
        $this->assertTrue($modelRead['exists']);
        $this->assertStringContainsString('class Order', (string) $modelRead['content']);

        // Recursive scan from root returns ONLY explore_paths + modify_paths entries (token saving!)
        $scan = $reader->execute(['path' => '.', 'recursive' => true]);
        $returnedPaths = array_column($scan['entries'], 'path');
        $this->assertContains('app/Models/Order.php', $returnedPaths);
        $this->assertContains('database/migrations/2026_01_01_000001_create_orders_table.php', $returnedPaths);
        $this->assertNotContains('resources/views/welcome.blade.php', $returnedPaths);
        $this->assertNotContains('app/Http/Controllers/OrderController.php', $returnedPaths);
        $this->assertNotContains('routes/api.php', $returnedPaths);
        $this->assertNotContains('vendor/some/pkg/Secret.php', $returnedPaths);

        // Direct read outside explore_paths must throw
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/outside skill explore_paths|ignore_paths/i');
        $reader->execute(['path' => 'resources/views/welcome.blade.php']);
    }

    public function test_patch_writer_blocks_writes_to_explore_only_paths_and_read_only_skills(): void
    {
        /** @var SkillRegistry $registry */
        $registry = $this->app->make(SkillRegistry::class);
        $migrationSkill = $registry->get('laravel-safe-migrations');
        $this->assertNotNull($migrationSkill);

        $toolRegistry = new ToolRegistry($this->pathGuard);
        $resolved = $toolRegistry->resolveForAgent(
            agent: new MigrationAgent(),
            availableTools: [
                'file_reader' => new FileReaderTool($this->pathGuard),
                'patch_writer' => new PatchWriterTool($this->pathGuard),
            ],
            scopePaths: (new MigrationAgent())->scopePaths(),
            activeSkill: [$migrationSkill]
        );

        $writer = $resolved['patch_writer'];

        // Writing inside modify_paths & scopePaths succeeds
        $ok = $writer->execute([
            'path' => 'database/migrations/2026_01_02_000001_create_invoices_table.php',
            'action' => 'write',
            'content' => "<?php\n",
            'summary' => 'Create invoices migration',
        ]);
        $this->assertSame('database/migrations/2026_01_02_000001_create_invoices_table.php', $ok['path']);

        // Writing to an explore-only file (app/Models/Order.php) is blocked
        try {
            $writer->execute([
                'path' => 'app/Models/Order.php',
                'action' => 'write',
                'content' => "<?php\n",
            ]);
            $this->fail('Expected write to explore-only path to throw RuntimeException.');
        } catch (RuntimeException $e) {
            $this->assertMatchesRegularExpression('/scope violation|modify_paths/i', $e->getMessage());
        }

        // Read-only skill with modify_paths: [] blocks ALL writes even when agent scopePaths is broader
        $readOnlySkill = new Skill(
            name: 'strict-read-only',
            description: 'Read only audit',
            agents: ['*'],
            allowedTools: ['file_reader', 'patch_writer'],
            explorePaths: ['app/Models/**'],
            modifyPaths: [],
            ignorePaths: ['vendor/**'],
            hasExplicitExplorePaths: true,
            hasExplicitModifyPaths: true
        );

        $readOnlyTools = $toolRegistry->resolveForAgent(
            agent: 'backend_agent',
            availableTools: [
                'file_reader' => new FileReaderTool($this->pathGuard),
                'patch_writer' => new PatchWriterTool($this->pathGuard),
            ],
            scopePaths: ['app/**'],
            activeSkill: [$readOnlySkill]
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/read-only|modify_paths/i');
        $readOnlyTools['patch_writer']->execute([
            'path' => 'app/Models/Order.php',
            'action' => 'write',
            'content' => "<?php\n",
        ]);
    }

    public function test_allowed_file_map_is_injected_into_lead_and_sub_agent_prompts(): void
    {
        // 1. Lead agent prompt injection (SwaggerAgent)
        $swaggerAgent = new SwaggerAgent();
        $llm = new FakeDriver();
        $state = RunState::create('Generate Swagger docs');
        $swaggerAgent->execute($state, [
            'file_reader' => new FileReaderTool($this->pathGuard),
            'patch_writer' => new PatchWriterTool($this->pathGuard),
        ], $llm);

        $leadSystem = '';
        foreach ($llm->getRecordedCalls()[0]['messages'] as $msg) {
            if ($msg['role'] === 'system') {
                $leadSystem = (string) $msg['content'];
            }
        }
        $this->assertStringContainsString('ALLOWED FILE MAP', $leadSystem);
        $this->assertStringContainsString('Explore Only (Read):', $leadSystem);
        $this->assertStringContainsString('Can Modify (Read & Write):', $leadSystem);
        $this->assertStringContainsString('docs/**', $leadSystem);

        // 2. Sub-agent prompt injection via TeamDelegator (MigrationAgent)
        $delegator = new TeamDelegator(
            subAgentRegistry: \Yousef\LaraOrcest\Agents\Teams\SubAgentRegistry::createDefault(),
            skillRegistry: $this->app->make(SkillRegistry::class),
            toolRegistry: new ToolRegistry($this->pathGuard)
        );
        $subLlm = new FakeDriver();
        $delegator->delegate(
            state: RunState::create('Create migration', ['dry_run' => true]),
            parentAgentKey: 'database_agent',
            subAgentKeys: ['migration_agent'],
            context: ['technical_plan' => ['migrations' => ['create_orders_table' => 'database/migrations/2026_01_01_000001_create_orders_table.php']]],
            tools: [
                'file_reader' => new FileReaderTool($this->pathGuard),
                'patch_writer' => new PatchWriterTool($this->pathGuard),
            ],
            llm: $subLlm,
            activeSkillNames: ['laravel-safe-migrations']
        );

        $subSystem = '';
        foreach ($subLlm->getRecordedCalls()[0]['messages'] as $msg) {
            if ($msg['role'] === 'system') {
                $subSystem = (string) $msg['content'];
            }
        }
        $this->assertStringContainsString('ALLOWED FILE MAP', $subSystem);
        $this->assertStringContainsString('Explore Only (Read): app/Models/**, app/Domains/**/Models/**', $subSystem);
        $this->assertStringContainsString('Can Modify (Read & Write): database/migrations/**', $subSystem);
        $this->assertStringContainsString('Ignore (Never Load):', $subSystem);
    }
}
