<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Yousef\LaraOrcest\State\RunState;

class RunStateTest extends TestCase
{
    public function test_can_create_run_state_with_defaults(): void
    {
        $state = RunState::create('Build a billing invoice API');

        $this->assertNotEmpty($state->runId);
        $this->assertEquals('Build a billing invoice API', $state->prompt);
        $this->assertEquals('start', $state->currentNode);
        $this->assertEquals(RunState::STATUS_RUNNING, $state->status);
        $this->assertFalse($state->isDryRun());
        $this->assertEquals(0, $state->guardrails['step_count']);
        $this->assertEquals(0, $state->guardrails['fix_attempts']);
        $this->assertEquals(40, $state->guardrails['max_steps']);
        $this->assertEquals(3, $state->guardrails['max_fix_attempts']);
    }

    public function test_serialization_round_trip(): void
    {
        $state = RunState::create('Test feature', ['max_steps' => 20, 'dry_run' => true]);
        $state = $state->withCurrentNode('architect');
        $state = $state->incrementSteps();
        $state = $state->recordArtifact('app/Models/Invoice.php', 'created', 'Invoice model');
        $state = $state->recordTokenUsage(150, 50);

        $array = $state->toArray();
        $this->assertEquals(RunState::SCHEMA_VERSION, $array['schema_version']);
        $this->assertEquals('architect', $array['current_node']);
        $this->assertCount(1, $array['artifacts']);
        $this->assertEquals(200, $array['guardrails']['token_usage']['total_tokens']);

        $restored = RunState::fromArray($array);
        $this->assertEquals($state->runId, $restored->runId);
        $this->assertEquals($state->prompt, $restored->prompt);
        $this->assertEquals($state->currentNode, $restored->currentNode);
        $this->assertTrue($restored->isDryRun());
        $this->assertEquals(1, $restored->guardrails['step_count']);
    }

    public function test_invalid_status_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $state = RunState::create('Test');
        $state->withStatus('invalid_status');
    }

    public function test_guardrails_step_limit(): void
    {
        $state = RunState::create('Test', ['max_steps' => 2]);
        $this->assertFalse($state->hasExceededSteps());

        $state = $state->incrementSteps(); // 1
        $this->assertFalse($state->hasExceededSteps());

        $state = $state->incrementSteps(); // 2
        $this->assertTrue($state->hasExceededSteps());
    }

    public function test_guardrails_fix_attempts_limit(): void
    {
        $state = RunState::create('Test', ['max_fix_attempts' => 2]);
        $this->assertTrue($state->canAttemptFix());

        $state = $state->incrementFixAttempts(); // 1
        $this->assertTrue($state->canAttemptFix());

        $state = $state->incrementFixAttempts(); // 2
        $this->assertTrue($state->hasExceededFixAttempts());
        $this->assertFalse($state->canAttemptFix());
    }

    public function test_test_results_and_review_recording(): void
    {
        $state = RunState::create('Test');
        $state = $state->recordTestResult(true, 0, 'Tests: 5 passed', 'OK (5 tests)');

        $this->assertTrue($state->areTestsPassing());
        $this->assertEquals(0, $state->testResults['exit_code']);

        $state = $state->recordReview('approved', ['Code is clean'], []);
        $this->assertTrue($state->isReviewApproved());
    }
}
