<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Yousef\LaraOrcest\Contracts\ToolContract;
use Yousef\LaraOrcest\Skills\Skill;
use Yousef\LaraOrcest\Tools\FileReaderTool;
use Yousef\LaraOrcest\Tools\PatchWriterTool;
use Yousef\LaraOrcest\Tools\ScopedToolProxy;
use Yousef\LaraOrcest\Tools\Support\PathGuard;
use Yousef\LaraOrcest\Tools\ToolRegistry;

class ToolRegistryAndScopingTest extends TestCase
{
    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/lara_orcest_tool_registry_test_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0777, true);
        mkdir($this->tempDir . '/database/migrations', 0777, true);
        mkdir($this->tempDir . '/app/Services', 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tempDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $fileinfo) {
                if ($fileinfo->isDir()) {
                    @rmdir($fileinfo->getRealPath());
                } else {
                    @unlink($fileinfo->getRealPath());
                }
            }
            @rmdir($this->tempDir);
        }
        parent::tearDown();
    }

    public function test_tool_registry_registration_and_retrieval(): void
    {
        $pathGuard = new PathGuard(projectRoot: $this->tempDir);
        $registry = new ToolRegistry($pathGuard);

        $fileReader = new FileReaderTool($pathGuard);
        $registry->register($fileReader);

        $this->assertTrue($registry->has('file_reader'));
        $this->assertFalse($registry->has('patch_writer'));
        $this->assertSame($fileReader, $registry->get('file_reader'));
    }

    public function test_tool_registry_register_function_closure(): void
    {
        $registry = new ToolRegistry();

        $registry->registerFunction(
            name: 'schema_inspector',
            description: 'Inspects database table columns',
            schema: [
                'type' => 'object',
                'properties' => ['table' => ['type' => 'string']],
                'required' => ['table'],
            ],
            handler: function (array $params) {
                return ['columns' => ['id', 'name', 'created_at']];
            }
        );

        $this->assertTrue($registry->has('schema_inspector'));
        $tool = $registry->get('schema_inspector');
        $this->assertNotNull($tool);
        $this->assertEquals('schema_inspector', $tool->getName());
        $this->assertEquals('Inspects database table columns', $tool->getDescription());

        $result = $tool->execute(['table' => 'users']);
        $this->assertEquals(['id', 'name', 'created_at'], $result['columns']);
    }

    public function test_scoped_tool_proxy_enforces_directory_scope(): void
    {
        $pathGuard = new PathGuard(projectRoot: $this->tempDir);
        $patchTool = new PatchWriterTool(pathGuard: $pathGuard);

        $proxy = new ScopedToolProxy(
            tool: $patchTool,
            scopePaths: ['database/migrations/**'],
            pathGuard: $pathGuard
        );

        // Allowed path
        $allowedResult = $proxy->execute([
            'path' => 'database/migrations/2026_01_01_create_posts_table.php',
            'action' => 'write',
            'content' => '<?php // posts table',
            'summary' => 'Create posts table',
        ]);
        $this->assertEquals('database/migrations/2026_01_01_create_posts_table.php', $allowedResult['path']);

        // Forbidden path outside scope
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/outside permitted scope/i');

        $proxy->execute([
            'path' => 'app/Services/PaymentGateway.php',
            'action' => 'write',
            'content' => '<?php // payment gateway',
        ]);
    }

    public function test_tool_registry_resolve_for_agent_with_skills_and_scopes(): void
    {
        $pathGuard = new PathGuard(projectRoot: $this->tempDir);
        $registry = new ToolRegistry($pathGuard);

        $reader = new FileReaderTool($pathGuard);
        $writer = new PatchWriterTool(pathGuard: $pathGuard);
        $registry->register($reader)->register($writer);

        // Active skill that permits only file_reader
        $skill = new Skill(
            name: 'read-only-analysis',
            description: 'Audit without modification',
            agents: ['*'],
            allowedTools: ['file_reader']
        );

        $resolved = $registry->resolveForAgent(
            agent: 'backend_agent',
            availableTools: ['file_reader' => $reader, 'patch_writer' => $writer],
            scopePaths: ['app/**'],
            activeSkill: $skill
        );

        // writer should be omitted because skill restricted tools to file_reader
        $this->assertArrayHasKey('file_reader', $resolved);
        $this->assertArrayNotHasKey('patch_writer', $resolved);
        $this->assertInstanceOf(ScopedToolProxy::class, $resolved['file_reader']);
    }
}
