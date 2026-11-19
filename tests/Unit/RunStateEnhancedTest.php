<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Yousef\LaraOrcest\State\RunState;

class RunStateEnhancedTest extends TestCase
{
    public function test_initial_named_constructor_creates_clean_instance(): void
    {
        $state = RunState::initial('custom_run_001', 'Build API', ['max_steps' => 25]);

        $this->assertSame('custom_run_001', $state->runId);
        $this->assertSame('Build API', $state->prompt);
        $this->assertSame('start', $state->currentNode);
        $this->assertSame(RunState::STATUS_RUNNING, $state->status);
        $this->assertSame(25, $state->guardrails['max_steps']);
        $this->assertSame(0, $state->guardrails['step_count']);
        $this->assertTrue($state->isRunning());
        $this->assertFalse($state->isPaused());
        $this->assertFalse($state->isCompleted());
        $this->assertFalse($state->isFailed());
    }

    public function test_step_counting_methods_and_aliases(): void
    {
        $state = RunState::create('Test Prompt', ['max_steps' => 2]);
        $this->assertSame(0, $state->guardrails['step_count']);
        $this->assertFalse($state->hasExceededSteps());

        $state = $state->incrementStep();
        $this->assertSame(1, $state->guardrails['step_count']);
        $this->assertFalse($state->hasExceededSteps());

        $state = $state->incrementSteps();
        $this->assertSame(2, $state->guardrails['step_count']);
        $this->assertTrue($state->hasExceededSteps());
    }

    public function test_fix_attempt_methods_and_aliases(): void
    {
        $state = RunState::create('Test Prompt', ['max_fix_attempts' => 2]);
        $this->assertSame(0, $state->guardrails['fix_attempts']);
        $this->assertTrue($state->canAttemptFix());
        $this->assertFalse($state->hasExceededFixAttempts());

        $state = $state->incrementFixAttempt();
        $this->assertSame(1, $state->guardrails['fix_attempts']);
        $this->assertTrue($state->canAttemptFix());
        $this->assertFalse($state->hasExceededFixAttempts());

        $state = $state->incrementFixAttempts();
        $this->assertSame(2, $state->guardrails['fix_attempts']);
        $this->assertTrue($state->hasExceededFixAttempts());
        $this->assertFalse($state->canAttemptFix());
    }

    public function test_status_transition_helpers(): void
    {
        $state = RunState::create('Test Prompt');
        $this->assertTrue($state->isRunning());

        $paused = $state->pause();
        $this->assertSame(RunState::STATUS_PAUSED, $paused->status);
        $this->assertTrue($paused->isPaused());
        $this->assertFalse($paused->isRunning());

        $completed = $paused->complete();
        $this->assertSame(RunState::STATUS_COMPLETED, $completed->status);
        $this->assertTrue($completed->isCompleted());

        $failed = $state->fail('Validation timeout');
        $this->assertSame(RunState::STATUS_FAILED, $failed->status);
        $this->assertTrue($failed->isFailed());
        $this->assertSame('Validation timeout', $failed->getFailureReason());
    }

    public function test_token_and_cost_accumulation(): void
    {
        $state = RunState::create('Test Prompt');
        $this->assertSame(0, $state->getTotalTokens());
        $this->assertSame(0.0, $state->getEstimatedCost());

        $state = $state->recordTokenUsage(100, 50, 0.002);
        $this->assertSame(150, $state->getTotalTokens());
        $this->assertSame(0.002, $state->getEstimatedCost());

        $state = $state->recordTokenUsage(200, 100, 0.004);
        $this->assertSame(450, $state->getTotalTokens());
        $this->assertSame(0.006, $state->getEstimatedCost());
    }

    public function test_serialization_sanitizes_secrets_in_metadata(): void
    {
        $state = RunState::create('Test Prompt');
        $state = $state->withMetadata([
            'environment' => 'testing',
            'api_key' => 'sk-secret-12345',
            'token' => 'bearer-secret-token',
            'nested' => [
                'password' => 'supersecret',
                'allowed' => 'public-info',
            ],
        ]);

        $serialized = $state->toArray();
        $this->assertSame('testing', $serialized['metadata']['environment']);
        $this->assertSame('[REDACTED]', $serialized['metadata']['api_key']);
        $this->assertSame('[REDACTED]', $serialized['metadata']['token']);
        $this->assertSame('[REDACTED]', $serialized['metadata']['nested']['password']);
        $this->assertSame('public-info', $serialized['metadata']['nested']['allowed']);
    }

    public function test_from_array_with_missing_optional_fields_defaults_cleanly(): void
    {
        $data = [
            'run_id' => 'run_minimal_123',
            'prompt' => 'Minimal Prompt',
        ];

        $state = RunState::fromArray($data);
        $this->assertSame('run_minimal_123', $state->runId);
        $this->assertSame('Minimal Prompt', $state->prompt);
        $this->assertSame('start', $state->currentNode);
        $this->assertSame(RunState::STATUS_RUNNING, $state->status);
        $this->assertSame([], $state->requirementsSpec);
        $this->assertSame([], $state->backlog);
        $this->assertSame([], $state->artifacts);
        $this->assertSame(40, $state->guardrails['max_steps']);
    }

    public function test_from_array_throws_on_missing_run_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required run_id');

        RunState::fromArray(['prompt' => 'No ID']);
    }

    public function test_with_artifacts_and_review_methods(): void
    {
        $state = RunState::create('Test Prompt');
        $state = $state->withArtifacts([
            ['path' => 'app/Models/User.php', 'action' => 'created', 'summary' => 'User model', 'timestamp' => '2026-01-01T00:00:00+00:00'],
        ]);
        $this->assertCount(1, $state->artifacts);

        $state = $state->recordReview('revise', ['Fix validation in controller'], ['Add unique rule']);
        $this->assertFalse($state->isReviewApproved());
        $latest = $state->getLatestReview();
        $this->assertSame('revise', $latest['decision']);
        $this->assertSame(['Fix validation in controller'], $latest['findings']);

        $state = $state->recordReview('approved', ['All looks great']);
        $this->assertTrue($state->isReviewApproved());
    }
}
