<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Yousef\LaraOrcest\Agents\ArchitectAgent;
use Yousef\LaraOrcest\Agents\BackendAgent;
use Yousef\LaraOrcest\Agents\BusinessAnalystAgent;
use Yousef\LaraOrcest\Agents\DatabaseAgent;
use Yousef\LaraOrcest\Agents\FixerAgent;
use Yousef\LaraOrcest\Agents\GitAgent;
use Yousef\LaraOrcest\Agents\ProductManagerAgent;
use Yousef\LaraOrcest\Agents\QaAgent;
use Yousef\LaraOrcest\Agents\ReviewerAgent;
use Yousef\LaraOrcest\Contracts\LlmDriverContract;
use Yousef\LaraOrcest\Contracts\ToolContract;
use Yousef\LaraOrcest\LLM\Drivers\FakeDriver;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Tools\FileReaderTool;
use Yousef\LaraOrcest\Tools\PatchWriterTool;
use Yousef\LaraOrcest\Tools\Support\PathGuard;

class AgentsExecutionTest extends TestCase
{
    protected string $tempDir;
    protected PathGuard $pathGuard;
    protected array $tools;
    protected FakeDriver $fakeDriver;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/lara_orcest_agents_test_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir . '/app/Models', 0755, true);
        mkdir($this->tempDir . '/database/migrations', 0755, true);
        mkdir($this->tempDir . '/routes', 0755, true);
        mkdir($this->tempDir . '/tests/Feature', 0755, true);

        $this->pathGuard = new PathGuard($this->tempDir);
        $this->fakeDriver = new FakeDriver();

        $fileReader = new FileReaderTool($this->pathGuard);
        $patchWriter = new PatchWriterTool($this->pathGuard);

        $mockGit = new class implements ToolContract {
            public function getName(): string { return 'git_command'; }
            public function getDescription(): string { return 'Mock Git'; }
            public function getSchema(): array { return []; }
            public function execute(array $p): array { return []; }
            public function getCurrentBranch(): array { return ['branch' => 'main', 'dry_run' => false]; }
            public function getStatus(): array { return ['clean' => true, 'branch' => 'main', 'modified' => [], 'untracked' => []]; }
            public function createBranch(string $b): array { return ['branch' => $b, 'created' => true]; }
            public function setDryRun(bool $d): void {}
        };

        $mockTestRunner = new class implements ToolContract {
            public function getName(): string { return 'test_runner'; }
            public function getDescription(): string { return 'Mock Test Runner'; }
            public function getSchema(): array { return []; }
            public function execute(array $p): array {
                return ['passed' => true, 'exit_code' => 0, 'summary' => '1 passed', 'output' => 'OK'];
            }
            public function setDryRun(bool $d): void {}
        };

        $mockArtisan = new class implements ToolContract {
            public function getName(): string { return 'artisan_runner'; }
            public function getDescription(): string { return 'Mock Artisan'; }
            public function getSchema(): array { return []; }
            public function execute(array $p): array {
                return ['successful' => true, 'exit_code' => 0, 'output' => 'Done'];
            }
            public function setDryRun(bool $d): void {}
        };

        $this->tools = [
            'file_reader' => $fileReader,
            'patch_writer' => $patchWriter,
            'git_command' => $mockGit,
            'test_runner' => $mockTestRunner,
            'artisan_runner' => $mockArtisan,
        ];
    }

    protected function tearDown(): void
    {
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

    public function test_business_analyst_agent_execution(): void
    {
        $agent = new BusinessAnalystAgent();
        $this->assertSame('business_analyst', $agent->getName());
        $this->assertNotEmpty($agent->getRole());
        $this->assertNotEmpty($agent->getGoal());
        $this->assertNotEmpty($agent->getBackstory());
        $this->assertSame(['file_reader'], $agent->getAllowedTools());
        $this->assertSame('requirements_spec', $agent->getStateOutputKey());

        $state = RunState::initial('run_ba', 'Build invoice billing');
        $updated = $agent->execute($state, $this->tools, $this->fakeDriver);

        $this->assertNotEmpty($updated->requirementsSpec);
        $this->assertArrayHasKey('domain_entities', $updated->requirementsSpec);
        $this->assertArrayHasKey('functional_requirements', $updated->requirementsSpec);
        $this->assertGreaterThan(0, $updated->getTotalTokens());
    }

    public function test_product_manager_agent_execution(): void
    {
        $agent = new ProductManagerAgent();
        $this->assertSame('product_manager', $agent->getName());
        $this->assertSame('backlog', $agent->getStateOutputKey());

        $state = RunState::initial('run_pm', 'Build invoice billing')
            ->withRequirementsSpec(['domain_entities' => ['Invoice']]);

        $updated = $agent->execute($state, $this->tools, $this->fakeDriver);

        $this->assertNotEmpty($updated->backlog);
        $this->assertArrayHasKey('id', $updated->backlog[0]);
        $this->assertArrayHasKey('acceptance_criteria', $updated->backlog[0]);
    }

    public function test_architect_agent_execution(): void
    {
        $agent = new ArchitectAgent();
        $this->assertSame('architect', $agent->getName());
        $this->assertSame('technical_plan', $agent->getStateOutputKey());

        $state = RunState::initial('run_arch', 'Build invoice billing')
            ->withBacklog([['id' => 'TASK-1', 'title' => 'Create invoices table']]);

        $updated = $agent->execute($state, $this->tools, $this->fakeDriver);

        $this->assertNotEmpty($updated->technicalPlan);
        $this->assertArrayHasKey('migrations', $updated->technicalPlan);
        $this->assertArrayHasKey('models', $updated->technicalPlan);
    }

    public function test_git_agent_execution(): void
    {
        $agent = new GitAgent();
        $this->assertSame('git_agent', $agent->getName());
        $this->assertSame('git_state', $agent->getStateOutputKey());

        $state = RunState::initial('run_git', 'Build invoice billing');
        $updated = $agent->execute($state, $this->tools, $this->fakeDriver);

        $this->assertNotEmpty($updated->gitState);
        $this->assertArrayHasKey('feature_branch', $updated->gitState);
        $this->assertSame('main', $updated->gitState['base_branch']);
    }

    public function test_database_agent_execution(): void
    {
        $agent = new DatabaseAgent();
        $this->assertSame('database_agent', $agent->getName());
        $this->assertSame('artifacts', $agent->getStateOutputKey());

        $state = RunState::initial('run_db', 'Build invoice billing');
        $updated = $agent->execute($state, $this->tools, $this->fakeDriver);

        $this->assertNotEmpty($updated->artifacts);
        $this->assertFileExists($this->tempDir . '/app/Models/Project.php');
    }

    public function test_backend_agent_execution(): void
    {
        $agent = new BackendAgent();
        $this->assertSame('backend_agent', $agent->getName());
        $this->assertSame('artifacts', $agent->getStateOutputKey());

        $state = RunState::initial('run_be', 'Build invoice billing');
        $updated = $agent->execute($state, $this->tools, $this->fakeDriver);

        $this->assertNotEmpty($updated->artifacts);
        $this->assertFileExists($this->tempDir . '/app/Http/Controllers/Api/TaskController.php');
    }

    public function test_qa_agent_execution(): void
    {
        $agent = new QaAgent();
        $this->assertSame('qa_agent', $agent->getName());
        $this->assertSame('test_results', $agent->getStateOutputKey());

        $state = RunState::initial('run_qa', 'Build invoice billing');
        $updated = $agent->execute($state, $this->tools, $this->fakeDriver);

        $this->assertNotEmpty($updated->testResults);
        $this->assertTrue($updated->areTestsPassing());
        $this->assertFileExists($this->tempDir . '/tests/Feature/TaskApiTest.php');
    }

    public function test_fixer_agent_execution(): void
    {
        $agent = new FixerAgent();
        $this->assertSame('fixer_agent', $agent->getName());

        $state = RunState::initial('run_fix', 'Build invoice billing')
            ->recordTestResult(false, 1, '1 failed', 'FAILED: test');

        $this->assertSame(0, $state->guardrails['fix_attempts']);

        $updated = $agent->execute($state, $this->tools, $this->fakeDriver);

        $this->assertSame(1, $updated->guardrails['fix_attempts']);
    }

    public function test_reviewer_agent_execution(): void
    {
        $agent = new ReviewerAgent();
        $this->assertSame('reviewer_agent', $agent->getName());
        $this->assertSame('reviews', $agent->getStateOutputKey());

        // When tests pass
        $statePassing = RunState::initial('run_rev_pass', 'Prompt')
            ->recordTestResult(true, 0, '1 passed', 'OK');

        $updatedPassing = $agent->execute($statePassing, $this->tools, $this->fakeDriver);
        $this->assertTrue($updatedPassing->isReviewApproved());

        // When tests fail and not dry-run: review must be forced to revise
        $stateFailing = RunState::initial('run_rev_fail', 'Prompt')
            ->recordTestResult(false, 1, '1 failed', 'FAILED');

        $updatedFailing = $agent->execute($stateFailing, $this->tools, $this->fakeDriver);
        $this->assertFalse($updatedFailing->isReviewApproved());
        $this->assertSame('revise', $updatedFailing->getLatestReview()['decision']);
    }

    public function test_agent_fails_gracefully_when_llm_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Agent [business_analyst] LLM execution failed');

        $failingDriver = new class implements LlmDriverContract {
            public function getName(): string { return 'failing'; }
            public function chat(array $m, array $o = []): array { return []; }
            public function chatStructured(array $m, array $s, array $o = []): array {
                throw new RuntimeException('Connection timed out to LLM proxy');
            }
        };

        $agent = new BusinessAnalystAgent();
        $state = RunState::initial('run_throw', 'Prompt');
        $agent->execute($state, $this->tools, $failingDriver);
    }
}
