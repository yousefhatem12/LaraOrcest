<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Yousef\LaraOrcest\Contracts\LlmDriverContract;
use Yousef\LaraOrcest\Contracts\ToolContract;
use Yousef\LaraOrcest\Graph\DefaultGraphBuilder;
use Yousef\LaraOrcest\LLM\Drivers\FakeDriver;
use Yousef\LaraOrcest\State\Drivers\JsonCheckpointer;
use Yousef\LaraOrcest\State\RunState;

class CyclicFixLoopTest extends TestCase
{
    protected string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/lara_orcest_loop_test_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tempDir . '/*');
        if ($files) {
            foreach ($files as $f) {
                @unlink($f);
            }
        }
        @rmdir($this->tempDir);
    }

    public function test_cyclic_fix_loop_retries_and_terminates_when_max_fix_attempts_reached(): void
    {
        $driver = new FakeDriver();
        $checkpointer = new JsonCheckpointer($this->tempDir);

        // Custom tools where test_runner always reports failure
        $failingTestRunner = new class implements ToolContract {
            public function getName(): string { return 'test_runner'; }
            public function getDescription(): string { return 'Failing test runner mock'; }
            public function getSchema(): array { return []; }
            public function execute(array $params): array {
                return [
                    'passed' => false,
                    'exit_code' => 1,
                    'duration' => 0.1,
                    'summary' => 'Tests: 1 failed',
                    'output' => 'FAILED: TaskApiTest::test_create_task',
                    'failures' => ['FAILED: TaskApiTest::test_create_task'],
                    'dry_run' => false,
                ];
            }
        };

        $tools = DefaultGraphBuilder::defaultTools();
        $tools['test_runner'] = $failingTestRunner;
        $tools['patch_writer'] = new \Yousef\LaraOrcest\Tools\PatchWriterTool(
            new \Yousef\LaraOrcest\Tools\Support\PathGuard($this->tempDir)
        );
        $tools['git_command'] = new class implements ToolContract {
            public function getName(): string { return 'git_command'; }
            public function getDescription(): string { return 'Mock git tool'; }
            public function getSchema(): array { return []; }
            public function execute(array $params): array { return []; }
            public function getCurrentBranch(): array { return ['branch' => 'main', 'dry_run' => false]; }
            public function getStatus(): array { return ['clean' => true, 'branch' => 'main', 'modified' => [], 'untracked' => []]; }
            public function createBranch(string $b, bool $allowDirty = false): array { return ['branch' => $b, 'created' => true]; }
            public function setDryRun(bool $d): void {}
        };

        $graph = DefaultGraphBuilder::build(
            llm: $driver,
            checkpointer: $checkpointer,
            tools: $tools
        );

        $recordedSteps = [];
        $graph->onStep(function (RunState $s, string $node) use (&$recordedSteps) {
            $recordedSteps[] = $node;
        });

        // Max fix attempts = 2
        $state = RunState::create('Create buggy feature', [
            'max_fix_attempts' => 2,
            'dry_run' => false,
        ]);

        $finalState = $graph->run($state);

        // Loop should have run fixer_agent 2 times, and then stopped with failed status
        $this->assertEquals(RunState::STATUS_FAILED, $finalState->status);
        $this->assertEquals(2, $finalState->guardrails['fix_attempts']);
        $this->assertFalse($finalState->areTestsPassing());

        // Count how many times fixer_agent was executed
        $fixerInvocations = count(array_filter($recordedSteps, fn ($n) => $n === 'fixer_agent'));
        $this->assertEquals(2, $fixerInvocations);
    }
}
