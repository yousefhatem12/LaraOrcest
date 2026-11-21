<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Feature;

use Yousef\LaraOrcest\Agents\ArchitectAgent;
use Yousef\LaraOrcest\Contracts\ProjectMemoryContract;
use Yousef\LaraOrcest\Facades\Orcest;
use Yousef\LaraOrcest\LLM\Drivers\FakeDriver;
use Yousef\LaraOrcest\Memory\ProjectMemoryStore;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Tests\TestCase;
use Yousef\LaraOrcest\Tools\FileReaderTool;
use Yousef\LaraOrcest\Tools\Support\PathGuard;

class ProjectMemoryFeatureTest extends TestCase
{
    protected string $testDir;

    protected string $memoryPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testDir = sys_get_temp_dir() . '/lara_orcest_feature_mem_' . bin2hex(random_bytes(4));
        mkdir($this->testDir, 0755, true);
        mkdir($this->testDir . '/app/Models', 0755, true);

        // Initialize Git repo in sandbox for git_agent in non-dry-run mode
        shell_exec('git -C ' . escapeshellarg($this->testDir) . ' init -b main 2>/dev/null');
        shell_exec('git -C ' . escapeshellarg($this->testDir) . " config user.email 'test@example.com'");
        shell_exec('git -C ' . escapeshellarg($this->testDir) . " config user.name 'Test Runner'");
        file_put_contents($this->testDir . '/README.md', '# Test App');
        shell_exec('git -C ' . escapeshellarg($this->testDir) . " add . && git -C " . escapeshellarg($this->testDir) . " commit -m 'Initial commit' 2>/dev/null");

        $this->memoryPath = $this->testDir . '/.lara-orcest/memory.json';

        $this->app['config']->set('lara-orcest.tools.project_root', $this->testDir);
        $this->app['config']->set('lara-orcest.memory.enabled', true);
        $this->app['config']->set('lara-orcest.memory.path', $this->memoryPath);
        $this->app['config']->set('lara-orcest.memory.auto_index', false);
        $this->app['config']->set('lara-orcest.git.enabled', false); // disable real git commit in test runner

        // Bind custom path memory store for test sandbox
        $this->app->singleton(ProjectMemoryContract::class, function () {
            return new ProjectMemoryStore(
                path: $this->memoryPath,
                pathGuard: new PathGuard($this->testDir),
                enabled: true,
                autoIndex: false
            );
        });
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->testDir);
        parent::tearDown();
    }

    protected function deleteDirectory(string $dir): void
    {
        if (! file_exists($dir)) {
            return;
        }

        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    public function test_service_container_and_facade_provide_project_memory_store(): void
    {
        $store = $this->app->make(ProjectMemoryContract::class);
        $this->assertInstanceOf(ProjectMemoryStore::class, $store);

        $facadeStore = Orcest::memory();
        $this->assertInstanceOf(ProjectMemoryContract::class, $facadeStore);
    }

    public function test_agent_execution_injects_compact_project_memory_into_prompt(): void
    {
        /** @var ProjectMemoryContract $store */
        $store = $this->app->make(ProjectMemoryContract::class);
        $store->recordConvention('CustomArchitectureRule: All repositories must implement interface');
        $store->recordDecision('Base domain setup', 'Established Domain directory structure', ['files' => ['app/Domain']]);

        $fakeLlm = new FakeDriver();

        $agent = new ArchitectAgent();
        $state = RunState::create('Design the new user architecture', ['dry_run' => true]);
        $tools = ['file_reader' => new FileReaderTool(new PathGuard($this->testDir))];

        $agent->execute($state, $tools, $fakeLlm);

        $recordedCalls = $fakeLlm->getRecordedCalls();
        $this->assertNotEmpty($recordedCalls);

        $messages = $recordedCalls[0]['messages'] ?? [];
        $userMessageContent = '';
        foreach ($messages as $msg) {
            if ($msg['role'] === 'user') {
                $userMessageContent = (string) $msg['content'];
                break;
            }
        }

        $this->assertStringContainsString('PROJECT MEMORY & CODEBASE CONTEXT', $userMessageContent);
        $this->assertStringContainsString('CustomArchitectureRule', $userMessageContent);
        $this->assertStringContainsString('FileReaderTool', $userMessageContent);
    }

    public function test_build_command_with_no_memory_option_bypasses_memory(): void
    {
        $fakeLlm = new FakeDriver();

        $agent = new ArchitectAgent();
        $state = RunState::create('Design something', ['no_memory' => true]);
        $this->assertFalse($state->isMemoryEnabled());

        $tools = ['file_reader' => new FileReaderTool(new PathGuard($this->testDir))];
        $agent->execute($state, $tools, $fakeLlm);

        $recordedCalls = $fakeLlm->getRecordedCalls();
        $this->assertNotEmpty($recordedCalls);

        $messages = $recordedCalls[0]['messages'] ?? [];
        $userMessageContent = '';
        foreach ($messages as $msg) {
            if ($msg['role'] === 'user') {
                $userMessageContent = (string) $msg['content'];
                break;
            }
        }

        $this->assertStringNotContainsString('PROJECT MEMORY & CODEBASE CONTEXT', $userMessageContent);
    }

    public function test_build_command_executes_and_consolidates_memory_cleanly(): void
    {
        $state = RunState::create('Create a lightweight ping health-check endpoint', [
            'run_id' => 'run_ping_feat',
            'dry_run' => false,
        ]);
        $state = $state->recordArtifact('app/Http/Controllers/PingController.php', 'created', 'Ping controller');

        /** @var ProjectMemoryContract $store */
        $store = $this->app->make(ProjectMemoryContract::class);
        $store->consolidate($state);

        $this->assertFileExists($this->memoryPath);
        $data = json_decode(file_get_contents($this->memoryPath), true);

        $this->assertNotEmpty($data['decisions']);
        $this->assertStringContainsString('Create a lightweight ping health-check endpoint', $data['decisions'][0]['title']);
    }

    public function test_build_command_dry_run_does_not_mutate_memory(): void
    {
        $this->artisan('orcest:build', [
            'prompt' => 'Preview ping endpoint in dry-run',
            '--dry-run' => true,
            '--no-human-review' => true,
        ])->assertSuccessful();

        $this->assertFileDoesNotExist($this->memoryPath, 'Memory file must not exist when running in dry-run mode');
    }
}
