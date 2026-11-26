<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\TestCase;
use Yousef\LaraOrcest\Agents\BusinessAnalystAgent;
use Yousef\LaraOrcest\Agents\WebScrapingAgent;
use Yousef\LaraOrcest\Config\EnvCredentialManager;
use Yousef\LaraOrcest\Config\OrchestratorConfig;
use Yousef\LaraOrcest\LLM\Drivers\AnthropicDriver;
use Yousef\LaraOrcest\LLM\Drivers\GeminiDriver;
use Yousef\LaraOrcest\LLM\Drivers\OllamaDriver;
use Yousef\LaraOrcest\LLM\Drivers\OpenAiCompatibleHttpDriver;
use Yousef\LaraOrcest\LLM\LlmManager;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Support\Config;
use Yousef\LaraOrcest\Support\HttpClient;
use Yousef\LaraOrcest\Support\StandaloneBootstrap;
use Yousef\LaraOrcest\Tools\HttpDispatchTool;
use Yousef\LaraOrcest\Tools\HttpFetchTool;
use Yousef\LaraOrcest\Workspace\WorkspaceContext;

class StandaloneExecutionNoFacadeErrorTest extends TestCase
{
    private string $tempDir;
    private array $envBackup = [];
    private array $serverBackup = [];
    private mixed $previousFacadeApp = null;
    private mixed $previousContainer = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->envBackup = $_ENV;
        $this->serverBackup = $_SERVER;
        $this->previousFacadeApp = Facade::getFacadeApplication();
        $this->previousContainer = Container::getInstance();

        // Simulate pure standalone execution with NO Laravel application or facade root
        unset($_ENV['LARA_ORCEST_LLM'], $_SERVER['LARA_ORCEST_LLM']);
        putenv('LARA_ORCEST_LLM');
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
        Config::clearOverrides();
        HttpClient::reset();

        $this->tempDir = sys_get_temp_dir() . '/lara_orcest_standalone_unit_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        HttpClient::reset();
        Config::clearOverrides();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApp);
        Container::setInstance($this->previousContainer);

        foreach (['LARA_ORCEST_LLM', 'GROQ_API_KEY', 'OPENAI_API_KEY', 'GEMINI_API_KEY', 'ANTHROPIC_API_KEY', 'OPENROUTER_API_KEY', 'LARA_ORCEST_LLM_API_KEY', 'LARA_ORCEST_LLM_API_SECRET', 'GROQ_BASE_URL', 'LARA_ORCEST_GROQ_MODEL'] as $var) {
            putenv($var);
        }
        $_ENV = $this->envBackup;
        $_SERVER = $this->serverBackup;

        if (is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }

        parent::tearDown();
    }

    private function removeDirectory(string $path): void
    {
        $items = array_diff(scandir($path) ?: [], ['.', '..']);
        foreach ($items as $item) {
            $full = $path . DIRECTORY_SEPARATOR . $item;
            is_dir($full) ? $this->removeDirectory($full) : @unlink($full);
        }
        @rmdir($path);
    }

    public function test_business_analyst_and_http_llm_drivers_execute_without_facade_root_error(): void
    {
        $this->assertNull(Facade::getFacadeApplication());
        $this->assertFalse(Container::getInstance()->bound('http'));

        $factory = new Factory();
        $factory->fake([
            'https://api.groq.com/openai/v1/chat/completions' => $factory->response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'domain_entities' => ['Invoice', 'Payment'],
                            'functional_requirements' => ['Create invoices'],
                            'business_rules' => ['Invoice total must be non-negative'],
                            'edge_cases' => ['Duplicate payment'],
                        ]),
                    ],
                ]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20, 'total_tokens' => 30],
            ], 200),
        ]);
        HttpClient::setStandaloneFactory($factory);

        Config::set('lara-orcest.llm.providers.groq.api_key', 'gsk_standalone_test');
        $context = WorkspaceContext::standalone($this->tempDir);
        $driver = (new LlmManager())->driver('groq');

        $state = RunState::create('Build an invoicing API', [
            'target_project_path' => $this->tempDir,
            'workspace_mode' => 'standalone',
            'memory_enabled' => false,
        ]);

        $agent = new BusinessAnalystAgent();
        $resultState = $agent->execute($state, $context->createTools(), $driver);

        $this->assertContains('Invoice', $resultState->requirementsSpec['domain_entities'] ?? []);
        $this->assertContains('Invoice total must be non-negative', $resultState->requirementsSpec['business_rules'] ?? []);
    }

    public function test_all_http_drivers_and_tools_work_without_laravel_facade_root(): void
    {
        $this->assertNull(Facade::getFacadeApplication());

        $factory = new Factory();
        $factory->fake([
            'https://api.openai.com/*' => $factory->response([
                'choices' => [['message' => ['content' => '{"ok":true}']]],
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
            ], 200),
            'https://api.anthropic.com/*' => $factory->response([
                'content' => [['text' => '{"ok":true}']],
                'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
            ], 200),
            'https://generativelanguage.googleapis.com/*' => $factory->response([
                'candidates' => [['content' => ['parts' => [['text' => '{"ok":true}']]]]],
                'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1, 'totalTokenCount' => 2],
            ], 200),
            'http://127.0.0.1:11434/*' => $factory->response([
                'message' => ['content' => '{"ok":true}'],
                'prompt_eval_count' => 1,
                'eval_count' => 1,
            ], 200),
            'https://example.com/*' => $factory->response('<html><body><p>Hello Standalone</p></body></html>', 200, ['Content-Type' => 'text/html']),
            'https://webhook.example.org/*' => $factory->response(['accepted' => true], 202),
        ]);
        HttpClient::setStandaloneFactory($factory);

        $openAi = new OpenAiCompatibleHttpDriver(apiKey: 'sk-test');
        $anthropic = new AnthropicDriver(apiKey: 'ant-test');
        $gemini = new GeminiDriver(apiKey: 'gem-test');
        $ollama = new OllamaDriver();

        $this->assertSame(['ok' => true], $openAi->chatStructured([['role' => 'user', 'content' => 'hi']], [])['data']);
        $this->assertSame(['ok' => true], $anthropic->chatStructured([['role' => 'user', 'content' => 'hi']], [])['data']);
        $this->assertSame(['ok' => true], $gemini->chatStructured([['role' => 'user', 'content' => 'hi']], [])['data']);
        $this->assertSame(['ok' => true], $ollama->chatStructured([['role' => 'user', 'content' => 'hi']], [])['data']);

        $fetchTool = new HttpFetchTool();
        $fetched = $fetchTool->execute(['url' => 'https://example.com/docs']);
        $this->assertTrue($fetched['successful']);
        $this->assertStringContainsString('Hello Standalone', $fetched['content']);

        $dispatchTool = new HttpDispatchTool();
        $dispatched = $dispatchTool->execute([
            'endpoint' => 'https://webhook.example.org/hook',
            'payload' => ['hello' => 'world'],
        ]);
        $this->assertTrue($dispatched['successful']);
        $this->assertSame(202, $dispatched['http_status']);

        $scraper = new WebScrapingAgent();
        $scraped = $scraper->fetchUrlContent('https://example.com/page', 10, 0, 0, 1000);
        $this->assertTrue($scraped['successful']);
    }

    public function test_standalone_bootstrap_sets_facade_root_for_direct_http_facade_calls(): void
    {
        $this->assertNull(Facade::getFacadeApplication());

        StandaloneBootstrap::ensureFacadeRoot();

        $this->assertNotNull(Facade::getFacadeApplication());

        $factory = new Factory();
        $factory->fake([
            'https://api.example.test/*' => $factory->response(['status' => 'ok'], 200),
        ]);
        Http::swap($factory);

        $response = Http::timeout(5)->get('https://api.example.test/ping');
        $this->assertTrue($response->successful());
        $this->assertSame('ok', $response->json('status'));

        $factory->assertSent(static fn (Request $req) => $req->url() === 'https://api.example.test/ping');
    }

    public function test_env_credential_manager_persists_without_duplicating_and_updates_gitignore(): void
    {
        $envPath = $this->tempDir . '/.env';
        file_put_contents($envPath, "APP_NAME=Demo\nLARA_ORCEST_LLM=openai\nOPENAI_API_KEY=old_key\n");

        EnvCredentialManager::persistToEnvFile($envPath, [
            'LARA_ORCEST_LLM' => 'groq',
            'GROQ_API_KEY' => 'gsk_new_key',
            'LARA_ORCEST_LLM_API_SECRET' => 'sec_123',
        ], ensureGitIgnore: true);

        $content = (string) file_get_contents($envPath);
        $this->assertSame(1, substr_count($content, 'LARA_ORCEST_LLM='));
        $this->assertStringContainsString('APP_NAME=Demo', $content);
        $this->assertStringContainsString('LARA_ORCEST_LLM=groq', $content);
        $this->assertStringContainsString('GROQ_API_KEY=gsk_new_key', $content);
        $this->assertStringContainsString('LARA_ORCEST_LLM_API_SECRET=sec_123', $content);

        $gitignore = (string) file_get_contents($this->tempDir . '/.gitignore');
        $this->assertStringContainsString('.env', $gitignore);

        // Loading env + hydrating runtime
        $config = OrchestratorConfig::load($this->tempDir);
        $this->assertSame('groq', EnvCredentialManager::resolveActiveDriver(null, $config));
        $this->assertTrue(EnvCredentialManager::hasCredentialsForDriver('groq', $config));
    }
}
