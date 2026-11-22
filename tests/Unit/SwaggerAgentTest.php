<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Yousef\LaraOrcest\Agents\SwaggerAgent;
use Yousef\LaraOrcest\Contracts\LlmDriverContract;
use Yousef\LaraOrcest\LLM\Drivers\FakeDriver;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Tools\FileReaderTool;
use Yousef\LaraOrcest\Tools\PatchWriterTool;
use Yousef\LaraOrcest\Tools\Support\PathGuard;

class SwaggerAgentTest extends TestCase
{
    protected string $tempDir;
    protected PathGuard $pathGuard;
    protected array $tools;
    protected FakeDriver $fakeDriver;
    protected SwaggerAgent $agent;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/lara_orcest_swagger_test_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir . '/docs', 0755, true);
        mkdir($this->tempDir . '/app/Http/Controllers', 0755, true);
        mkdir($this->tempDir . '/routes', 0755, true);

        $this->pathGuard = new PathGuard($this->tempDir);
        $this->fakeDriver = new FakeDriver();
        $this->agent = new SwaggerAgent();

        $this->tools = [
            'file_reader' => new FileReaderTool($this->pathGuard),
            'patch_writer' => new PatchWriterTool($this->pathGuard),
        ];
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tempDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $fileinfo) {
                $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
                $todo($fileinfo->getRealPath());
            }
            rmdir($this->tempDir);
        }
    }

    public function test_agent_metadata_and_contracts(): void
    {
        $this->assertSame('swagger_agent', $this->agent->getName());
        $this->assertSame('Laravel Swagger & OpenAPI Documentation Specialist', $this->agent->getRole());
        $this->assertStringContainsString('OpenAPI', $this->agent->getGoal());
        $this->assertStringContainsString('interactive OpenAPI/Swagger', $this->agent->getBackstory());
        $this->assertContains('file_reader', $this->agent->getAllowedTools());
        $this->assertContains('patch_writer', $this->agent->getAllowedTools());
        $this->assertSame('artifacts', $this->agent->getStateOutputKey());
    }

    public function test_swagger_agent_execution_creates_documentation_file(): void
    {
        $state = RunState::initial('run_swagger_1', 'Implement task management API with swagger documentation');

        $updated = $this->agent->execute($state, $this->tools, $this->fakeDriver);

        $this->assertTrue($updated->hasSwaggerDocs());
        $this->assertNotNull($updated->getSwaggerDocs());
        $this->assertNotNull($updated->getSwaggerSpec());

        $spec = $updated->getSwaggerSpec();
        $this->assertSame('3.0.3', $spec['openapi']);
        $this->assertArrayHasKey('/api/tasks', $spec['paths']);

        $targetFile = $this->tempDir . '/docs/swagger.json';
        $this->assertFileExists($targetFile);

        $content = json_decode((string) file_get_contents($targetFile), true);
        $this->assertSame('3.0.3', $content['openapi']);
        $this->assertArrayHasKey('/api/tasks', $content['paths']);

        // Check artifacts recorded
        $this->assertNotEmpty($updated->artifacts);
        $artifactPaths = array_column($updated->artifacts, 'path');
        $this->assertContains('docs/swagger.json', $artifactPaths);
    }

    public function test_swagger_agent_fallback_generates_file_when_files_omitted(): void
    {
        $customDriver = new FakeDriver([
            'structured' => [
                'openapi_spec' => [
                    'openapi' => '3.0.3',
                    'info' => [
                        'title' => 'Fallback API',
                        'version' => '2.0.0',
                    ],
                    'paths' => [
                        '/api/products' => [
                            'get' => ['summary' => 'Get products'],
                        ],
                    ],
                ],
                'files' => [], // Empty files array
                'endpoints_documented' => ['GET /api/products'],
                'summary' => 'Fallback swagger doc',
            ],
        ]);

        $state = RunState::initial('run_swagger_fb', 'Generate swagger docs for products');
        $updated = $this->agent->execute($state, $this->tools, $customDriver);

        $this->assertTrue($updated->hasSwaggerDocs());
        $targetFile = $this->tempDir . '/docs/swagger.json';
        $this->assertFileExists($targetFile);

        $content = json_decode((string) file_get_contents($targetFile), true);
        $this->assertSame('Fallback API', $content['info']['title']);
    }

    public function test_swagger_agent_respects_dry_run(): void
    {
        $state = RunState::initial('run_swagger_dry', 'Make swagger documentation for backend', [
            'dry_run' => true,
        ]);

        $updated = $this->agent->execute($state, $this->tools, $this->fakeDriver);

        $this->assertTrue($updated->hasSwaggerDocs());
        // In dry run, file is not written to physical disk
        $targetFile = $this->tempDir . '/docs/swagger.json';
        $this->assertFileDoesNotExist($targetFile);

        // But artifact is recorded in state
        $this->assertNotEmpty($updated->artifacts);
    }

    public function test_swagger_intent_detection(): void
    {
        $state1 = RunState::initial('run_1', 'Make swagger documentation for backend');
        $this->assertTrue($state1->detectSwaggerIntent());
        $this->assertTrue($state1->shouldRunSwagger());

        $state2 = RunState::initial('run_2', 'Create REST API with openapi specification');
        $this->assertTrue($state2->detectSwaggerIntent());
        $this->assertTrue($state2->shouldRunSwagger());

        $state3 = RunState::initial('run_3', 'Build a simple queue worker without api');
        $this->assertFalse($state3->detectSwaggerIntent());
        $this->assertFalse($state3->shouldRunSwagger());

        // Explicit flag forces swagger run
        $state4 = RunState::initial('run_4', 'Build worker', ['swagger' => true]);
        $this->assertTrue($state4->shouldRunSwagger());

        // Disabling flag stops swagger run even with keywords
        $state5 = RunState::initial('run_5', 'Make swagger docs', ['no_swagger' => true]);
        $this->assertFalse($state5->shouldRunSwagger());
        $this->assertTrue($state5->isSwaggerDisabled());
    }

    public function test_swagger_agent_throws_on_llm_failure(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Agent [swagger_agent] LLM execution failed');

        $failingDriver = new class implements LlmDriverContract {
            public function getName(): string { return 'failing'; }
            public function chat(array $m, array $o = []): array { return []; }
            public function chatStructured(array $m, array $s, array $o = []): array {
                throw new RuntimeException('LLM service unavailable');
            }
        };

        $state = RunState::initial('run_throw', 'Make swagger docs');
        $this->agent->execute($state, $this->tools, $failingDriver);
    }
}
