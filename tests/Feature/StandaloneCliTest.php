<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Feature;

use Illuminate\Container\Container;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Process\Process;
use Yousef\LaraOrcest\Console\LaraOrcestApplication;
use Yousef\LaraOrcest\State\Drivers\JsonCheckpointer;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Support\Config;
use Yousef\LaraOrcest\Support\HttpClient;

class StandaloneCliTest extends TestCase
{
    private string $tempWorkspace;
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
        Config::clearOverrides();
        HttpClient::reset();
        $this->tempWorkspace = sys_get_temp_dir() . '/lara_orcest_cli_test_' . uniqid();
        mkdir($this->tempWorkspace, 0755, true);
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

        if (is_dir($this->tempWorkspace)) {
            $this->removeDirectory($this->tempWorkspace);
        }
        parent::tearDown();
    }

    private function removeDirectory(string $path): void
    {
        $files = array_diff(scandir($path) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $fullPath = $path . '/' . $file;
            is_dir($fullPath) ? $this->removeDirectory($fullPath) : unlink($fullPath);
        }
        rmdir($path);
    }

    public function test_application_initializes_with_expected_commands(): void
    {
        $app = new LaraOrcestApplication();
        $this->assertTrue($app->has('init'));
        $this->assertTrue($app->has('build'));
        $this->assertTrue($app->has('status'));
        $this->assertTrue($app->has('approve'));
    }

    public function test_init_command_creates_workspace_configuration(): void
    {
        $app = new LaraOrcestApplication();
        $tester = new ApplicationTester($app);

        $exitCode = $tester->run([
            'command' => 'init',
            'project-dir' => $this->tempWorkspace,
        ]);

        $this->assertSame(0, $exitCode);
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Workspace initialized successfully', $output);

        $configFile = $this->tempWorkspace . '/.lara-orcest/config.json';
        $this->assertFileExists($configFile);

        $configData = json_decode((string) file_get_contents($configFile), true);
        $this->assertSame(realpath($this->tempWorkspace), $configData['target_path']);
        $this->assertSame('standalone', $configData['workspace_mode']);
    }

    public function test_init_command_rejects_nonexistent_directory(): void
    {
        $app = new LaraOrcestApplication();
        $tester = new ApplicationTester($app);

        $exitCode = $tester->run([
            'command' => 'init',
            'project-dir' => $this->tempWorkspace . '/does_not_exist',
        ]);

        $this->assertSame(1, $exitCode);
        $display = preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '';
        $this->assertStringContainsString('does not exist', $display);
    }

    public function test_status_command_displays_run_state_and_workspace(): void
    {
        $app = new LaraOrcestApplication();
        $tester = new ApplicationTester($app);

        // 1. Init workspace
        $tester->run([
            'command' => 'init',
            'project-dir' => $this->tempWorkspace,
        ]);

        // 2. Create checkpoint in target workspace
        $stateDir = $this->tempWorkspace . '/.lara-orcest/state';
        if (! is_dir($stateDir)) {
            mkdir($stateDir, 0755, true);
        }
        $checkpointer = new JsonCheckpointer($stateDir);

        $state = RunState::create('Add User authentication with sanctum', [
            'target_project_path' => $this->tempWorkspace,
            'workspace_mode' => 'standalone',
        ])->withStatus(RunState::STATUS_COMPLETED);

        $checkpointer->save($state);

        // 3. Inspect status
        $exitCode = $tester->run([
            'command' => 'status',
            '--target' => $this->tempWorkspace,
        ]);

        $this->assertSame(0, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('LaraOrcest Standalone Control Plane Status', $display);
        $this->assertStringContainsString($state->runId, $display);
        $this->assertStringContainsString('completed', $display);
        $this->assertStringContainsString('Add User authentication with sanctum', $display);
        $this->assertStringContainsString(realpath($this->tempWorkspace), $display);
    }

    public function test_build_command_in_dry_run_mode(): void
    {
        $app = new LaraOrcestApplication();
        $tester = new ApplicationTester($app);

        $exitCode = $tester->run([
            'command' => 'build',
            'prompt' => 'Scaffold an Invoice model and migration',
            '--target' => $this->tempWorkspace,
            '--dry-run' => true,
            '--llm' => 'fake',
            '--no-human-review' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('DRY-RUN', $display);
        $this->assertStringContainsString('Scaffold an Invoice model and migration', $display);
        $this->assertStringContainsString(realpath($this->tempWorkspace), $display);
        $this->assertStringContainsString('Target Laravel Version', $display);
        $this->assertStringContainsString('spatie/laravel-permission:^6.0', $display);
        $this->assertDirectoryDoesNotExist($this->tempWorkspace . '/app/Domains/Billing');
    }

    public function test_bin_lara_orcest_executable_runs_via_process(): void
    {
        $binary = dirname(__DIR__, 2) . '/bin/lara-orcest';
        $this->assertFileExists($binary);

        $process = new Process(['php', $binary, '--version']);
        $process->run();

        $this->assertSame(0, $process->getExitCode());
        $this->assertStringContainsString('LaraOrcest Standalone Control Plane', $process->getOutput());
    }

    public function test_bin_lara_orcest_executable_init_and_status_via_process(): void
    {
        $binary = dirname(__DIR__, 2) . '/bin/lara-orcest';

        $initProcess = new Process(['php', $binary, 'init', $this->tempWorkspace]);
        $initProcess->run();
        $this->assertSame(0, $initProcess->getExitCode());

        $statusProcess = new Process(['php', $binary, 'status', '--target=' . $this->tempWorkspace]);
        $statusProcess->run();
        $this->assertSame(0, $statusProcess->getExitCode());
        $this->assertStringContainsString(realpath($this->tempWorkspace), $statusProcess->getOutput());
    }

    public function test_init_command_prompts_for_laravel_version_and_persists_to_config(): void
    {
        $app = new LaraOrcestApplication();
        $tester = new ApplicationTester($app);
        $tester->setInputs(['^11.0']);

        $exitCode = $tester->run([
            'command' => 'init',
            'project-dir' => $this->tempWorkspace,
        ]);

        $this->assertSame(0, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Which Laravel version would you like to install?', $display);
        $this->assertStringContainsString('Selected Laravel Version: ^11.0', $display);

        $configData = json_decode((string) file_get_contents($this->tempWorkspace . '/.lara-orcest/config.json'), true);
        $this->assertSame('^11.0', $configData['package_manager']['laravel_version']);
        $this->assertSame('^11.0', $configData['package_manager']['default_laravel_version']);
    }

    public function test_build_command_interactive_wizard_prompts_for_laravel_version_then_prompt(): void
    {
        $app = new LaraOrcestApplication();
        $tester = new ApplicationTester($app);
        $tester->setInputs(['^11.0', 'Build multi-tenant Subscription API']);

        $exitCode = $tester->run([
            'command' => 'build',
            '--target' => $this->tempWorkspace,
            '--dry-run' => true,
            '--llm' => 'fake',
            '--no-human-review' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Which Laravel version would you like to install?', $display);
        $this->assertStringContainsString('Selected Laravel Version: ^11.0', $display);
        $this->assertStringContainsString('Enter your project prompt / requirements', $display);
        $this->assertStringContainsString('Prompt: Build multi-tenant Subscription API', $display);
        $this->assertStringContainsString('laravel/laravel:^11.0', $display);
    }

    public function test_build_command_supports_non_interactive_laravel_version_flag(): void
    {
        $app = new LaraOrcestApplication();
        $tester = new ApplicationTester($app);

        $exitCode = $tester->run([
            'command' => 'build',
            'prompt' => 'Build Inventory API',
            '--target' => $this->tempWorkspace,
            '--laravel-version' => '^11.0',
            '--dry-run' => true,
            '--llm' => 'fake',
            '--no-human-review' => true,
            '--no-interaction' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Target Laravel Version: ^11.0', $display);
        $this->assertStringContainsString('laravel/laravel:^11.0', $display);
        $this->assertStringContainsString('Prompt: Build Inventory API', $display);
    }

    public function test_build_command_prompts_for_missing_credentials_persists_env_and_executes(): void
    {
        unset($_ENV['LARA_ORCEST_LLM'], $_SERVER['LARA_ORCEST_LLM']);
        putenv('LARA_ORCEST_LLM');

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);

        $factory = new Factory();
        $factory->fake([
            'https://api.groq.com/openai/v1/chat/completions' => function (Request $request) use ($factory) {
                $payload = $request->data();
                $system = (string) ($payload['messages'][0]['content'] ?? '');

                if (str_contains($system, 'Domain Rules')) {
                    $json = ['business_rules' => ['Invoices require positive amount'], 'edge_cases' => ['Duplicate ID']];
                } elseif (str_contains($system, 'Business Analyst')) {
                    $json = [
                        'domain_entities' => ['Invoice'],
                        'functional_requirements' => ['Create invoice'],
                        'business_rules' => ['Amount > 0'],
                    ];
                } else {
                    $json = [
                        'tasks' => [[
                            'id' => 'T1',
                            'title' => 'Implement Invoice API',
                            'priority' => 1,
                            'acceptance_criteria' => ['Returns 201'],
                        ]],
                    ];
                }

                return $factory->response([
                    'choices' => [['message' => ['content' => json_encode($json)]]],
                    'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 10, 'total_tokens' => 15],
                ], 200);
            },
        ]);
        HttpClient::setStandaloneFactory($factory);

        $app = new LaraOrcestApplication();
        $tester = new ApplicationTester($app);
        $tester->setInputs([
            '^12.0',
            'groq',
            'gsk_live_test_key_123',
            'secret_token_456',
        ]);

        $exitCode = $tester->run([
            'command' => 'build',
            'prompt' => 'Build an invoicing API',
            '--target' => $this->tempWorkspace,
            '--interactive' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringNotContainsString('A facade root has not been set', $display);
        $this->assertStringContainsString('Select your LLM provider', $display);
        $this->assertStringContainsString('Saved LLM credentials [groq]', $display);

        // Verify .env & .gitignore persisted
        $envFile = $this->tempWorkspace . '/.env';
        $this->assertFileExists($envFile);
        $envContent = (string) file_get_contents($envFile);
        $this->assertStringContainsString('LARA_ORCEST_LLM=groq', $envContent);
        $this->assertStringContainsString('GROQ_API_KEY=gsk_live_test_key_123', $envContent);
        $this->assertStringContainsString('LARA_ORCEST_LLM_API_SECRET=secret_token_456', $envContent);
        $this->assertFileExists($this->tempWorkspace . '/.gitignore');
        $this->assertStringContainsString('.env', (string) file_get_contents($this->tempWorkspace . '/.gitignore'));

        // Verify request was actually dispatched with the entered API key
        $factory->assertSent(static fn (Request $req) => $req->hasHeader('Authorization', 'Bearer gsk_live_test_key_123'));
    }

    public function test_build_command_auto_loads_existing_env_without_reprompting(): void
    {
        unset($_ENV['LARA_ORCEST_LLM'], $_SERVER['LARA_ORCEST_LLM']);
        putenv('LARA_ORCEST_LLM');

        file_put_contents(
            $this->tempWorkspace . '/.env',
            "LARA_ORCEST_LLM=groq\nGROQ_API_KEY=gsk_preconfigured_999\nLARA_ORCEST_LLM_API_SECRET=sec_preconfigured\n"
        );

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);

        $factory = new Factory();
        $factory->fake([
            'https://api.groq.com/openai/v1/chat/completions' => function (Request $request) use ($factory) {
                $payload = $request->data();
                $system = (string) ($payload['messages'][0]['content'] ?? '');

                if (str_contains($system, 'Domain Rules')) {
                    $json = ['business_rules' => ['Valid email required'], 'edge_cases' => []];
                } elseif (str_contains($system, 'Business Analyst')) {
                    $json = [
                        'domain_entities' => ['User'],
                        'functional_requirements' => ['User login'],
                        'business_rules' => ['Valid email required'],
                    ];
                } else {
                    $json = [
                        'tasks' => [[
                            'id' => 'T1',
                            'title' => 'User Login',
                            'priority' => 1,
                            'acceptance_criteria' => ['Returns JWT'],
                        ]],
                    ];
                }

                return $factory->response([
                    'choices' => [['message' => ['content' => json_encode($json)]]],
                    'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 10, 'total_tokens' => 15],
                ], 200);
            },
        ]);
        HttpClient::setStandaloneFactory($factory);

        $app = new LaraOrcestApplication();
        $tester = new ApplicationTester($app);

        $exitCode = $tester->run([
            'command' => 'build',
            'prompt' => 'Build user login endpoint',
            '--target' => $this->tempWorkspace,
            '--laravel-version' => '^12.0',
            '--interactive' => true,
            '--no-interaction' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringNotContainsString('Select your LLM provider', $display);
        $this->assertStringNotContainsString('A facade root has not been set', $display);
        $factory->assertSent(static fn (Request $req) => $req->hasHeader('Authorization', 'Bearer gsk_preconfigured_999'));
    }

    public function test_build_command_fails_actionably_when_non_interactive_and_credentials_missing(): void
    {
        unset($_ENV['LARA_ORCEST_LLM'], $_SERVER['LARA_ORCEST_LLM']);
        putenv('LARA_ORCEST_LLM');

        $app = new LaraOrcestApplication();
        $tester = new ApplicationTester($app);

        $exitCode = $tester->run([
            'command' => 'build',
            'prompt' => 'Build inventory service',
            '--target' => $this->tempWorkspace,
            '--laravel-version' => '^12.0',
            '--no-interaction' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $display = preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '';
        $this->assertStringContainsString('No LLM provider or API key is configured', $display);
        $this->assertStringContainsString('--llm=fake', $display);
        $this->assertStringContainsString('GROQ_API_KEY', $display);
    }

    public function test_bin_lara_orcest_process_loads_env_without_facade_root_error(): void
    {
        $binary = dirname(__DIR__, 2) . '/bin/lara-orcest';
        file_put_contents(
            $this->tempWorkspace . '/.env',
            "LARA_ORCEST_LLM=openai\nOPENAI_API_KEY=sk-local-test\nOPENAI_BASE_URL=http://127.0.0.1:1/v1\n"
        );

        $process = new Process(
            ['php', $binary, 'build', 'Build notes API', '--target=' . $this->tempWorkspace, '--laravel-version=^12.0', '--no-human-review', '--no-interaction'],
            env: ['LARA_ORCEST_LLM' => false]
        );
        $process->run();

        $combined = $process->getOutput() . $process->getErrorOutput();
        $this->assertStringNotContainsString('A facade root has not been set', $combined);
        $this->assertStringContainsString('business_analyst', $combined);
    }

    public function test_init_command_with_configure_llm_prompts_and_persists_env(): void
    {
        unset($_ENV['LARA_ORCEST_LLM'], $_SERVER['LARA_ORCEST_LLM']);
        putenv('LARA_ORCEST_LLM');

        $app = new LaraOrcestApplication();
        $tester = new ApplicationTester($app);
        $tester->setInputs([
            '^12.0',
            'gemini',
            'AIzaSyTestKey123',
            '',
        ]);

        $exitCode = $tester->run([
            'command' => 'init',
            'project-dir' => $this->tempWorkspace,
            '--configure-llm' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Saved LLM credentials [gemini]', $display);

        $envContent = (string) file_get_contents($this->tempWorkspace . '/.env');
        $this->assertStringContainsString('LARA_ORCEST_LLM=gemini', $envContent);
        $this->assertStringContainsString('GEMINI_API_KEY=AIzaSyTestKey123', $envContent);
    }
}
