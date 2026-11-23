<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Unit;

use Yousef\LaraOrcest\Agents\SubAgents\FeatureTestAgent;
use Yousef\LaraOrcest\Agents\SubAgents\FixerSubAgent;
use Yousef\LaraOrcest\Agents\SubAgents\GitSubAgent;
use Yousef\LaraOrcest\Agents\SubAgents\SwaggerSubAgent;
use Yousef\LaraOrcest\LLM\Drivers\FakeDriver;
use Yousef\LaraOrcest\Skills\SkillRegistry;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Tests\TestCase;
use Yousef\LaraOrcest\Tools\ArtisanRunnerTool;
use Yousef\LaraOrcest\Tools\FileReaderTool;
use Yousef\LaraOrcest\Tools\GitCommandTool;
use Yousef\LaraOrcest\Tools\PatchWriterTool;
use Yousef\LaraOrcest\Tools\ScopedToolProxy;
use Yousef\LaraOrcest\Tools\Support\PathGuard;
use Yousef\LaraOrcest\Tools\TestRunnerTool;

class QualityReleasePodTest extends TestCase
{
    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/lara_orcest_qr_test_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir . '/tests/Feature', 0777, true);
        mkdir($this->tempDir . '/tests/Unit', 0777, true);
        mkdir($this->tempDir . '/app/Http/Requests', 0777, true);
        mkdir($this->tempDir . '/app/Http/Controllers/Api', 0777, true);
        mkdir($this->tempDir . '/docs', 0777, true);
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

    public function test_quality_release_pod_skills_are_registered_and_parsed(): void
    {
        $registry = new SkillRegistry();
        $registry->loadAll();

        $this->assertTrue($registry->has('laravel-feature-testing'));
        $this->assertTrue($registry->has('root-cause-test-fixing'));
        $this->assertTrue($registry->has('openapi-swagger-spec'));
        $this->assertTrue($registry->has('safe-git-release'));

        $testSkill = $registry->get('laravel-feature-testing');
        $this->assertNotNull($testSkill);
        $this->assertTrue($testSkill->appliesToAgent('qa_agent'));
        $this->assertTrue($testSkill->appliesToAgent('feature_test_agent'));
        $this->assertTrue($testSkill->permitsTool('patch_writer'));
        $this->assertTrue($testSkill->permitsTool('test_runner'));
        $this->assertContains('testing', $testSkill->tags);

        $fixSkill = $registry->get('root-cause-test-fixing');
        $this->assertNotNull($fixSkill);
        $this->assertTrue($fixSkill->appliesToAgent('qa_agent'));
        $this->assertTrue($fixSkill->appliesToAgent('fixer_agent'));
        $this->assertTrue($fixSkill->permitsTool('patch_writer'));
        $this->assertTrue($fixSkill->permitsTool('artisan_runner'));
        $this->assertContains('debugging', $fixSkill->tags);

        $swaggerSkill = $registry->get('openapi-swagger-spec');
        $this->assertNotNull($swaggerSkill);
        $this->assertTrue($swaggerSkill->appliesToAgent('reviewer_agent'));
        $this->assertTrue($swaggerSkill->appliesToAgent('swagger_agent'));
        $this->assertTrue($swaggerSkill->permitsTool('patch_writer'));
        $this->assertContains('swagger', $swaggerSkill->tags);

        $gitSkill = $registry->get('safe-git-release');
        $this->assertNotNull($gitSkill);
        $this->assertTrue($gitSkill->appliesToAgent('reviewer_agent'));
        $this->assertTrue($gitSkill->appliesToAgent('git_agent'));
        $this->assertTrue($gitSkill->permitsTool('git_command'));
        $this->assertContains('git', $gitSkill->tags);
    }

    public function test_feature_test_agent_properties_and_execution(): void
    {
        $agent = new FeatureTestAgent();

        $this->assertEquals('feature_test_agent', $agent->key());
        $this->assertEquals('qa_agent', $agent->parentAgentKey());
        $this->assertStringContainsString('Feature & Unit Test', $agent->role());
        $this->assertNotEmpty($agent->goal());
        $this->assertNotEmpty($agent->backstory());
        $this->assertEquals(['tests/**'], $agent->scopePaths());
        $this->assertEquals(['file_reader', 'patch_writer', 'test_runner', 'artisan_runner'], $agent->allowedTools());
        $this->assertEquals([], $agent->dependsOn());

        $guard = new PathGuard($this->tempDir);
        $tools = [
            'patch_writer' => new PatchWriterTool(pathGuard: $guard),
            'file_reader' => new FileReaderTool($guard),
            'test_runner' => new TestRunnerTool($this->tempDir, dryRun: true),
            'artisan_runner' => new ArtisanRunnerTool($this->tempDir),
        ];

        $fakeLlm = new FakeDriver();
        $state = RunState::create('Write tests for tasks feature');

        $result = $agent->execute($state, $tools, $fakeLlm);

        $this->assertTrue($result['success']);
        $this->assertEquals('feature_test_agent', $result['sub_agent']);
        $this->assertNotEmpty($result['artifacts']);
        $this->assertFileExists($this->tempDir . '/tests/Feature/TaskApiTest.php');
    }

    public function test_fixer_sub_agent_properties_and_execution(): void
    {
        $agent = new FixerSubAgent();

        $this->assertEquals('fixer_agent', $agent->key());
        $this->assertEquals('qa_agent', $agent->parentAgentKey());
        $this->assertStringContainsString('Bug Fixer', $agent->role());
        $this->assertNotEmpty($agent->goal());
        $this->assertNotEmpty($agent->backstory());
        $this->assertEquals(['app/**', 'database/**', 'routes/**', 'tests/**'], $agent->scopePaths());
        $this->assertEquals(['file_reader', 'patch_writer', 'artisan_runner', 'test_runner'], $agent->allowedTools());
        $this->assertEquals(['feature_test_agent'], $agent->dependsOn());

        $guard = new PathGuard($this->tempDir);
        $tools = [
            'patch_writer' => new PatchWriterTool(pathGuard: $guard),
            'file_reader' => new FileReaderTool($guard),
            'artisan_runner' => new ArtisanRunnerTool($this->tempDir),
            'test_runner' => new TestRunnerTool($this->tempDir, dryRun: true),
        ];

        $fakeLlm = new FakeDriver();
        $state = RunState::create('Fix failing tests');

        $result = $agent->execute($state, $tools, $fakeLlm, [
            'test_results' => ['passed' => false, 'output' => 'Validation error on StoreTaskRequest'],
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals('fixer_agent', $result['sub_agent']);
        $this->assertNotEmpty($result['artifacts']);
        $this->assertFileExists($this->tempDir . '/app/Http/Requests/StoreTaskRequest.php');
    }

    public function test_swagger_sub_agent_properties_and_execution(): void
    {
        $agent = new SwaggerSubAgent();

        $this->assertEquals('swagger_agent', $agent->key());
        $this->assertEquals('reviewer_agent', $agent->parentAgentKey());
        $this->assertStringContainsString('Swagger', $agent->role());
        $this->assertNotEmpty($agent->goal());
        $this->assertNotEmpty($agent->backstory());
        $this->assertEquals(['docs/**', 'storage/api-docs/**', 'openapi.json', 'openapi.yaml'], $agent->scopePaths());
        $this->assertEquals(['file_reader', 'patch_writer', 'artisan_runner'], $agent->allowedTools());
        $this->assertEquals([], $agent->dependsOn());

        $guard = new PathGuard($this->tempDir);
        $tools = [
            'patch_writer' => new PatchWriterTool(pathGuard: $guard),
            'file_reader' => new FileReaderTool($guard),
            'artisan_runner' => new ArtisanRunnerTool($this->tempDir),
        ];

        $fakeLlm = new FakeDriver();
        $state = RunState::create('Generate OpenAPI spec', [
            'technical_plan' => ['routes' => ['routes/api.php']],
        ]);

        $result = $agent->execute($state, $tools, $fakeLlm);

        $this->assertTrue($result['success']);
        $this->assertEquals('swagger_agent', $result['sub_agent']);
        $this->assertNotEmpty($result['artifacts']);
        $this->assertFileExists($this->tempDir . '/docs/swagger.json');
    }

    public function test_git_sub_agent_properties_and_execution(): void
    {
        $agent = new GitSubAgent();

        $this->assertEquals('git_agent', $agent->key());
        $this->assertEquals('reviewer_agent', $agent->parentAgentKey());
        $this->assertStringContainsString('Git Branch', $agent->role());
        $this->assertNotEmpty($agent->goal());
        $this->assertNotEmpty($agent->backstory());
        $this->assertEquals(['*'], $agent->scopePaths());
        $this->assertEquals(['git_command', 'file_reader'], $agent->allowedTools());
        $this->assertEquals([], $agent->dependsOn());

        $guard = new PathGuard($this->tempDir);
        $tools = [
            'git_command' => new GitCommandTool($this->tempDir, dryRun: true, pathGuard: $guard),
            'file_reader' => new FileReaderTool($guard),
        ];

        $fakeLlm = new FakeDriver();
        $state = RunState::create('Initialize milestone release');

        $result = $agent->execute($state, $tools, $fakeLlm);

        $this->assertTrue($result['success']);
        $this->assertEquals('git_agent', $result['sub_agent']);
        $this->assertArrayHasKey('branch_created', $result['output']);
        $this->assertStringStartsWith('orcest/', $result['output']['feature_branch']);
    }

    public function test_scope_paths_enforced_by_scoped_tool_proxy_for_qa_subagents(): void
    {
        $guard = new PathGuard($this->tempDir);
        $basePatchTool = new PatchWriterTool(pathGuard: $guard);

        // FeatureTestAgent allows ONLY tests/**
        $scopedPatchTool = new ScopedToolProxy(
            tool: $basePatchTool,
            scopePaths: ['tests/**'],
            pathGuard: $guard
        );

        // Writing to tests/** should succeed
        $res = $scopedPatchTool->execute([
            'path' => 'tests/Feature/ExampleTest.php',
            'content' => "<?php // test",
            'summary' => 'Test file',
        ]);
        $this->assertEquals('tests/Feature/ExampleTest.php', $res['path']);

        // Attempting to write to app/ via FeatureTestAgent proxy must throw RuntimeException
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('scope violation');
        $scopedPatchTool->execute([
            'path' => 'app/Models/User.php',
            'content' => "<?php // malicious write",
            'summary' => 'Out of scope write',
        ]);
    }
}
