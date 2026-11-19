<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Yousef\LaraOrcest\Contracts\CheckpointerContract;
use Yousef\LaraOrcest\State\Drivers\JsonCheckpointer;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\State\StateCheckpointer;

class StateCheckpointerEnhancedTest extends TestCase
{
    protected string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/lara_orcest_chkpt_test_' . bin2hex(random_bytes(4));
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

    public function test_json_checkpointer_save_load_exists_delete(): void
    {
        $checkpointer = new JsonCheckpointer($this->tempDir);
        $state = RunState::initial('run_chk_001', 'Test checkpoint feature', [
            'max_steps' => 15,
        ]);
        $state = $state->withCurrentNode('backend_agent');
        $state = $state->recordArtifact('app/Models/User.php', 'created', 'User model');

        $this->assertFalse($checkpointer->exists('run_chk_001'));
        $this->assertNull($checkpointer->load('run_chk_001'));

        // Save
        $checkpointer->save($state);

        $this->assertTrue($checkpointer->exists('run_chk_001'));

        // Load
        $loaded = $checkpointer->load('run_chk_001');
        $this->assertNotNull($loaded);
        $this->assertSame('run_chk_001', $loaded->runId);
        $this->assertSame('Test checkpoint feature', $loaded->prompt);
        $this->assertSame('backend_agent', $loaded->currentNode);
        $this->assertCount(1, $loaded->artifacts);
        $this->assertSame(15, $loaded->guardrails['max_steps']);

        // Delete
        $deleted = $checkpointer->delete('run_chk_001');
        $this->assertTrue($deleted);
        $this->assertFalse($checkpointer->exists('run_chk_001'));
        $this->assertNull($checkpointer->load('run_chk_001'));

        // Delete non-existent
        $this->assertFalse($checkpointer->delete('run_chk_001'));
    }

    public function test_json_checkpointer_list_ordering(): void
    {
        $checkpointer = new JsonCheckpointer($this->tempDir);

        $state1 = RunState::initial('run_alpha', 'First prompt');
        $checkpointer->save($state1);
        touch($this->tempDir . '/run_alpha.json', time() - 20);

        $state2 = RunState::initial('run_beta', 'Second prompt');
        $checkpointer->save($state2);
        touch($this->tempDir . '/run_beta.json', time() - 10);

        $state3 = RunState::initial('run_gamma', 'Third prompt');
        $checkpointer->save($state3);
        touch($this->tempDir . '/run_gamma.json', time());

        $list = $checkpointer->list(2);
        $this->assertCount(2, $list);
        $this->assertSame('run_gamma', $list[0]['run_id']);
        $this->assertSame('run_beta', $list[1]['run_id']);
    }

    public function test_state_checkpointer_delegates_to_driver(): void
    {
        $jsonDriver = new JsonCheckpointer($this->tempDir);
        $checkpointer = new StateCheckpointer($jsonDriver);

        $state = RunState::initial('run_delegate', 'Delegate check');
        $checkpointer->save($state);

        $this->assertTrue($checkpointer->exists('run_delegate'));
        $this->assertInstanceOf(RunState::class, $checkpointer->load('run_delegate'));
        $this->assertCount(1, $checkpointer->list());
        $this->assertTrue($checkpointer->delete('run_delegate'));
        $this->assertFalse($checkpointer->exists('run_delegate'));
    }

    public function test_state_checkpointer_allows_setting_driver(): void
    {
        $mockDriver = new class implements CheckpointerContract {
            public bool $saved = false;
            public function save(RunState $state): void { $this->saved = true; }
            public function load(string $runId): ?RunState { return null; }
            public function list(int $limit = 20): array { return []; }
            public function exists(string $runId): bool { return false; }
            public function delete(string $runId): bool { return true; }
        };

        $checkpointer = new StateCheckpointer();
        $checkpointer->setDriver($mockDriver);

        $state = RunState::initial('run_mock', 'Prompt');
        $checkpointer->save($state);

        $this->assertTrue($mockDriver->saved);
    }
}
