<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Unit;

use InvalidArgumentException;
use Yousef\LaraOrcest\Agents\SubAgents\ApiContractAgent;
use Yousef\LaraOrcest\Agents\SubAgents\DddBoundaryAgent;
use Yousef\LaraOrcest\Agents\SubAgents\SchemaPlannerAgent;
use Yousef\LaraOrcest\LLM\Drivers\FakeDriver;
use Yousef\LaraOrcest\Skills\SkillRegistry;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Tests\TestCase;
use Yousef\LaraOrcest\Tools\CodebaseInspectorTool;
use Yousef\LaraOrcest\Tools\FileReaderTool;
use Yousef\LaraOrcest\Tools\Support\PathGuard;

class ArchitecturePodTest extends TestCase
{
    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/lara_orcest_arch_test_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir . '/app/Models', 0777, true);
        mkdir($this->tempDir . '/database/migrations', 0777, true);
        mkdir($this->tempDir . '/routes', 0777, true);

        // Put sample files in temp dir
        file_put_contents(
            $this->tempDir . '/app/Models/User.php',
            "<?php\n\nnamespace App\\Models;\n\nuse Illuminate\\Database\\Eloquent\\Model;\n\nclass User extends Model {}\n"
        );
        file_put_contents(
            $this->tempDir . '/database/migrations/2026_01_01_000000_create_users_table.php',
            "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\n\nreturn new class extends Migration {};\n"
        );
        file_put_contents(
            $this->tempDir . '/routes/api.php',
            "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::get('/users', fn () => []);\n"
        );
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

    public function test_architecture_pod_skills_are_registered_and_parsed(): void
    {
        $registry = new SkillRegistry();
        $registry->loadAll();

        $this->assertTrue($registry->has('laravel-modular-ddd-architecture'));
        $this->assertTrue($registry->has('relational-schema-indexing'));
        $this->assertTrue($registry->has('rest-api-contract-design'));

        $dddSkill = $registry->get('laravel-modular-ddd-architecture');
        $this->assertNotNull($dddSkill);
        $this->assertTrue($dddSkill->appliesToAgent('architect'));
        $this->assertTrue($dddSkill->appliesToAgent('ddd_boundary_agent'));
        $this->assertTrue($dddSkill->permitsTool('file_reader'));
        $this->assertTrue($dddSkill->permitsTool('codebase_inspector'));
        $this->assertContains('ddd', $dddSkill->tags);

        $schemaSkill = $registry->get('relational-schema-indexing');
        $this->assertNotNull($schemaSkill);
        $this->assertTrue($schemaSkill->appliesToAgent('architect'));
        $this->assertTrue($schemaSkill->appliesToAgent('schema_planner_agent'));
        $this->assertTrue($schemaSkill->permitsTool('file_reader'));
        $this->assertTrue($schemaSkill->permitsTool('codebase_inspector'));
        $this->assertContains('schema', $schemaSkill->tags);

        $apiSkill = $registry->get('rest-api-contract-design');
        $this->assertNotNull($apiSkill);
        $this->assertTrue($apiSkill->appliesToAgent('architect'));
        $this->assertTrue($apiSkill->appliesToAgent('api_contract_agent'));
        $this->assertTrue($apiSkill->permitsTool('file_reader'));
        $this->assertTrue($apiSkill->permitsTool('codebase_inspector'));
        $this->assertContains('api', $apiSkill->tags);
    }

    public function test_codebase_inspector_tool_actions(): void
    {
        $guard = new PathGuard($this->tempDir);
        $tool = new CodebaseInspectorTool(pathGuard: $guard);

        $this->assertEquals('codebase_inspector', $tool->getName());
        $this->assertNotEmpty($tool->getDescription());
        $this->assertArrayHasKey('properties', $tool->getSchema());

        // 1. list_directory
        $listResult = $tool->execute(['action' => 'list_directory', 'path' => 'app']);
        $this->assertTrue($listResult['exists']);
        $entryNames = array_column($listResult['entries'], 'name');
        $this->assertContains('Models', $entryNames);

        // 2. inspect_models
        $modelsResult = $tool->execute(['action' => 'inspect_models', 'path' => 'app/Models']);
        $this->assertCount(1, $modelsResult['models']);
        $this->assertEquals('User', $modelsResult['models'][0]['class']);

        // 3. inspect_migrations
        $migrationsResult = $tool->execute(['action' => 'inspect_migrations', 'path' => 'database/migrations']);
        $this->assertCount(1, $migrationsResult['migrations']);
        $this->assertStringContainsString('create_users_table', $migrationsResult['migrations'][0]['name']);

        // 4. inspect_routes
        $routesResult = $tool->execute(['action' => 'inspect_routes', 'path' => 'routes/api.php']);
        $this->assertNotEmpty($routesResult['routes']);
        $this->assertContains('GET /users', $routesResult['routes'][0]['endpoints']);

        // 5. search_symbols
        $searchResult = $tool->execute(['action' => 'search_symbols', 'path' => 'app', 'pattern' => 'User']);
        $this->assertNotEmpty($searchResult['symbols']);
        $this->assertEquals('User', $searchResult['symbols'][0]['name']);

        // 6. Path traversal guard check
        $this->expectException(\RuntimeException::class);
        $tool->execute(['action' => 'list_directory', 'path' => '../../etc']);
    }

    public function test_ddd_boundary_agent_properties_and_execution(): void
    {
        $agent = new DddBoundaryAgent();

        $this->assertEquals('ddd_boundary_agent', $agent->key());
        $this->assertEquals('architect', $agent->parentAgentKey());
        $this->assertStringContainsString('DDD', $agent->role());
        $this->assertNotEmpty($agent->goal());
        $this->assertNotEmpty($agent->backstory());
        $this->assertEquals(['docs/**'], $agent->scopePaths());
        $this->assertEquals(['file_reader', 'codebase_inspector'], $agent->allowedTools());
        $this->assertEquals([], $agent->dependsOn());

        $llm = new FakeDriver();
        $state = RunState::create('Design task system');
        $tools = [
            'file_reader' => new FileReaderTool(new PathGuard($this->tempDir)),
            'codebase_inspector' => new CodebaseInspectorTool(pathGuard: new PathGuard($this->tempDir)),
        ];

        $result = $agent->execute($state, $tools, $llm);

        $this->assertTrue($result['success']);
        $this->assertEquals('ddd_boundary_agent', $result['sub_agent']);
        $this->assertArrayHasKey('bounded_contexts', $result['output']);
        $this->assertArrayHasKey('directory_structure', $result['output']);
        $this->assertArrayHasKey('architectural_rules', $result['output']);
        $this->assertCount(2, $result['output']['bounded_contexts']);
    }

    public function test_schema_planner_agent_properties_and_execution(): void
    {
        $agent = new SchemaPlannerAgent();

        $this->assertEquals('schema_planner_agent', $agent->key());
        $this->assertEquals('architect', $agent->parentAgentKey());
        $this->assertStringContainsString('Schema', $agent->role());
        $this->assertNotEmpty($agent->goal());
        $this->assertNotEmpty($agent->backstory());
        $this->assertEquals(['docs/**'], $agent->scopePaths());
        $this->assertEquals(['file_reader', 'codebase_inspector'], $agent->allowedTools());
        $this->assertEquals([], $agent->dependsOn());

        $llm = new FakeDriver();
        $state = RunState::create('Design task database schema');
        $tools = [
            'file_reader' => new FileReaderTool(new PathGuard($this->tempDir)),
            'codebase_inspector' => new CodebaseInspectorTool(pathGuard: new PathGuard($this->tempDir)),
        ];

        $result = $agent->execute($state, $tools, $llm);

        $this->assertTrue($result['success']);
        $this->assertEquals('schema_planner_agent', $result['sub_agent']);
        $this->assertArrayHasKey('tables', $result['output']);
        $this->assertArrayHasKey('migrations', $result['output']);
        $this->assertArrayHasKey('models', $result['output']);
        $this->assertArrayHasKey('migration_order', $result['output']);
        $this->assertCount(2, $result['output']['tables']);
    }

    public function test_api_contract_agent_properties_and_execution(): void
    {
        $agent = new ApiContractAgent();

        $this->assertEquals('api_contract_agent', $agent->key());
        $this->assertEquals('architect', $agent->parentAgentKey());
        $this->assertStringContainsString('API Contract', $agent->role());
        $this->assertNotEmpty($agent->goal());
        $this->assertNotEmpty($agent->backstory());
        $this->assertEquals(['docs/**'], $agent->scopePaths());
        $this->assertEquals(['file_reader', 'codebase_inspector'], $agent->allowedTools());
        $this->assertEquals([], $agent->dependsOn());

        $llm = new FakeDriver();
        $state = RunState::create('Design REST API contract');
        $tools = [
            'file_reader' => new FileReaderTool(new PathGuard($this->tempDir)),
            'codebase_inspector' => new CodebaseInspectorTool(pathGuard: new PathGuard($this->tempDir)),
        ];

        $result = $agent->execute($state, $tools, $llm);

        $this->assertTrue($result['success']);
        $this->assertEquals('api_contract_agent', $result['sub_agent']);
        $this->assertArrayHasKey('endpoints', $result['output']);
        $this->assertArrayHasKey('controllers', $result['output']);
        $this->assertArrayHasKey('requests', $result['output']);
        $this->assertArrayHasKey('routes', $result['output']);
        $this->assertCount(2, $result['output']['endpoints']);
    }
}
