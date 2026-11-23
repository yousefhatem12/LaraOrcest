<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Unit;

use Yousef\LaraOrcest\Agents\SubAgents\ControllerResourceAgent;
use Yousef\LaraOrcest\Agents\SubAgents\RequestValidationAgent;
use Yousef\LaraOrcest\Agents\SubAgents\ServiceActionAgent;
use Yousef\LaraOrcest\LLM\Drivers\FakeDriver;
use Yousef\LaraOrcest\Skills\SkillRegistry;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Tests\TestCase;
use Yousef\LaraOrcest\Tools\ArtisanRunnerTool;
use Yousef\LaraOrcest\Tools\FileReaderTool;
use Yousef\LaraOrcest\Tools\PatchWriterTool;
use Yousef\LaraOrcest\Tools\ScopedToolProxy;
use Yousef\LaraOrcest\Tools\Support\PathGuard;

class BackendPodTest extends TestCase
{
    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/lara_orcest_be_test_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir . '/app/Http/Requests', 0777, true);
        mkdir($this->tempDir . '/app/Policies', 0777, true);
        mkdir($this->tempDir . '/app/Actions', 0777, true);
        mkdir($this->tempDir . '/app/Services', 0777, true);
        mkdir($this->tempDir . '/app/Http/Controllers/Api', 0777, true);
        mkdir($this->tempDir . '/app/Http/Resources', 0777, true);
        mkdir($this->tempDir . '/routes', 0777, true);
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

    public function test_backend_pod_skills_are_registered_and_parsed(): void
    {
        $registry = new SkillRegistry();
        $registry->loadAll();

        $this->assertTrue($registry->has('laravel-form-requests-authorization'));
        $this->assertTrue($registry->has('service-action-workflows'));
        $this->assertTrue($registry->has('controllers-resources-routes'));

        $reqSkill = $registry->get('laravel-form-requests-authorization');
        $this->assertNotNull($reqSkill);
        $this->assertTrue($reqSkill->appliesToAgent('backend_agent'));
        $this->assertTrue($reqSkill->appliesToAgent('request_validation_agent'));
        $this->assertTrue($reqSkill->permitsTool('patch_writer'));
        $this->assertContains('validation', $reqSkill->tags);

        $actSkill = $registry->get('service-action-workflows');
        $this->assertNotNull($actSkill);
        $this->assertTrue($actSkill->appliesToAgent('backend_agent'));
        $this->assertTrue($actSkill->appliesToAgent('service_action_agent'));
        $this->assertTrue($actSkill->permitsTool('patch_writer'));
        $this->assertContains('actions', $actSkill->tags);

        $ctrlSkill = $registry->get('controllers-resources-routes');
        $this->assertNotNull($ctrlSkill);
        $this->assertTrue($ctrlSkill->appliesToAgent('backend_agent'));
        $this->assertTrue($ctrlSkill->appliesToAgent('controller_resource_agent'));
        $this->assertTrue($ctrlSkill->permitsTool('patch_writer'));
        $this->assertContains('controllers', $ctrlSkill->tags);
    }

    public function test_request_validation_agent_properties_and_execution(): void
    {
        $agent = new RequestValidationAgent();

        $this->assertEquals('request_validation_agent', $agent->key());
        $this->assertEquals('backend_agent', $agent->parentAgentKey());
        $this->assertStringContainsString('FormRequest', $agent->role());
        $this->assertNotEmpty($agent->goal());
        $this->assertNotEmpty($agent->backstory());
        $this->assertEquals([
            'app/Http/Requests/**',
            'app/Domains/**/Requests/**',
            'app/Policies/**',
            'app/Domains/**/Policies/**',
        ], $agent->scopePaths());
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

        $state = RunState::create('Create StoreTaskRequest and TaskPolicy');
        $result = $agent->execute($state, $tools, $llm);

        $this->assertTrue($result['success']);
        $this->assertEquals('request_validation_agent', $result['sub_agent']);
        $this->assertCount(2, $result['artifacts']);

        $this->assertFileExists($this->tempDir . '/app/Http/Requests/StoreTaskRequest.php');
        $this->assertFileExists($this->tempDir . '/app/Policies/TaskPolicy.php');
    }

    public function test_request_validation_agent_scope_paths_enforcement(): void
    {
        $agent = new RequestValidationAgent();
        $guard = new PathGuard($this->tempDir);
        $patchTool = new PatchWriterTool(pathGuard: $guard);
        $proxy = new ScopedToolProxy($patchTool, $agent->scopePaths(), $guard, $agent);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('outside permitted scope');

        // Writing a controller through request_validation_agent must fail
        $proxy->execute([
            'path' => 'app/Http/Controllers/TaskController.php',
            'content' => "<?php\n\nclass TaskController {}\n",
            'summary' => 'Sneak a controller into requests agent',
        ]);
    }

    public function test_service_action_agent_properties_and_execution(): void
    {
        $agent = new ServiceActionAgent();

        $this->assertEquals('service_action_agent', $agent->key());
        $this->assertEquals('backend_agent', $agent->parentAgentKey());
        $this->assertStringContainsString('Action', $agent->role());
        $this->assertNotEmpty($agent->goal());
        $this->assertNotEmpty($agent->backstory());
        $this->assertEquals([
            'app/Services/**',
            'app/Actions/**',
            'app/Events/**',
            'app/Listeners/**',
            'app/Domains/**/Actions/**',
            'app/Domains/**/Services/**',
        ], $agent->scopePaths());
        $this->assertEquals(['file_reader', 'patch_writer', 'artisan_runner'], $agent->allowedTools());
        $this->assertEquals(['request_validation_agent'], $agent->dependsOn());

        $llm = new FakeDriver();
        $guard = new PathGuard($this->tempDir);
        $patchTool = new PatchWriterTool(pathGuard: $guard);
        $tools = [
            'file_reader' => new FileReaderTool($guard),
            'patch_writer' => new ScopedToolProxy($patchTool, $agent->scopePaths(), $guard, $agent),
            'artisan_runner' => new ArtisanRunnerTool($this->tempDir),
        ];

        $state = RunState::create('Create CreateTaskAction and TaskAssignmentService');
        $result = $agent->execute($state, $tools, $llm);

        $this->assertTrue($result['success']);
        $this->assertEquals('service_action_agent', $result['sub_agent']);
        $this->assertCount(2, $result['artifacts']);

        $this->assertFileExists($this->tempDir . '/app/Actions/CreateTaskAction.php');
        $this->assertFileExists($this->tempDir . '/app/Services/TaskAssignmentService.php');
    }

    public function test_service_action_agent_scope_paths_enforcement(): void
    {
        $agent = new ServiceActionAgent();
        $guard = new PathGuard($this->tempDir);
        $patchTool = new PatchWriterTool(pathGuard: $guard);
        $proxy = new ScopedToolProxy($patchTool, $agent->scopePaths(), $guard, $agent);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('outside permitted scope');

        // Writing routes through service_action_agent must fail
        $proxy->execute([
            'path' => 'routes/api.php',
            'content' => "<?php\n\n// routes\n",
            'summary' => 'Sneak route registration into actions agent',
        ]);
    }

    public function test_controller_resource_agent_properties_and_execution(): void
    {
        $agent = new ControllerResourceAgent();

        $this->assertEquals('controller_resource_agent', $agent->key());
        $this->assertEquals('backend_agent', $agent->parentAgentKey());
        $this->assertStringContainsString('Controller', $agent->role());
        $this->assertNotEmpty($agent->goal());
        $this->assertNotEmpty($agent->backstory());
        $this->assertEquals([
            'app/Http/Controllers/**',
            'app/Http/Resources/**',
            'routes/**',
            'app/Domains/**/Http/**',
        ], $agent->scopePaths());
        $this->assertEquals(['file_reader', 'patch_writer', 'artisan_runner'], $agent->allowedTools());
        $this->assertEquals(['service_action_agent'], $agent->dependsOn());

        $llm = new FakeDriver();
        $guard = new PathGuard($this->tempDir);
        $patchTool = new PatchWriterTool(pathGuard: $guard);
        $tools = [
            'file_reader' => new FileReaderTool($guard),
            'patch_writer' => new ScopedToolProxy($patchTool, $agent->scopePaths(), $guard, $agent),
            'artisan_runner' => new ArtisanRunnerTool($this->tempDir),
        ];

        $state = RunState::create('Create TaskController, TaskResource, and routes');
        $result = $agent->execute($state, $tools, $llm);

        $this->assertTrue($result['success']);
        $this->assertEquals('controller_resource_agent', $result['sub_agent']);
        $this->assertCount(3, $result['artifacts']);

        $this->assertFileExists($this->tempDir . '/app/Http/Controllers/Api/TaskController.php');
        $this->assertFileExists($this->tempDir . '/app/Http/Resources/TaskResource.php');
        $this->assertFileExists($this->tempDir . '/routes/api.php');
    }

    public function test_controller_resource_agent_scope_paths_enforcement(): void
    {
        $agent = new ControllerResourceAgent();
        $guard = new PathGuard($this->tempDir);
        $patchTool = new PatchWriterTool(pathGuard: $guard);
        $proxy = new ScopedToolProxy($patchTool, $agent->scopePaths(), $guard, $agent);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('outside permitted scope');

        // Writing a migration through controller_resource_agent must fail
        $proxy->execute([
            'path' => 'database/migrations/2026_01_01_000000_fake.php',
            'content' => "<?php\n\nreturn new class {};\n",
            'summary' => 'Sneak a migration into controllers agent',
        ]);
    }
}
