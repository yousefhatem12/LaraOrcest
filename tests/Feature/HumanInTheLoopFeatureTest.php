<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Feature;

use Yousef\LaraOrcest\Contracts\CheckpointerContract;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Tests\TestCase;

class HumanInTheLoopFeatureTest extends TestCase
{
    public function test_interactive_build_pauses_at_review_gate(): void
    {
        $this->artisan('orcest:build', [
            'prompt' => 'Create billing invoices',
            '--interactive' => true,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('paused for human review at gate: [after_plan]')
            ->expectsOutputToContain('php artisan orcest:approve')
            ->assertSuccessful();
    }

    public function test_orcest_approve_command_resumes_paused_run(): void
    {
        /** @var CheckpointerContract $checkpointer */
        $checkpointer = $this->app->make(CheckpointerContract::class);

        // Create a run paused at after_plan with before_commit disabled for simple resumption
        $state = RunState::initial('run_feature_hitl', 'Build auth API', [
            'interactive' => true,
            'gates' => ['after_plan' => true, 'before_commit' => false],
            'dry_run' => true,
        ]);
        $state = $state->withCurrentNode('git_agent')->pauseAtGate('after_plan', ['summary' => 'Plan generated']);
        $checkpointer->save($state);

        // Approve and resume
        $this->artisan('orcest:approve', [
            'run_id' => 'run_feature_hitl',
            '--note' => 'Approved by tech lead',
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Reviewing LaraOrcest run [run_feature_hitl] at gate: [after_plan]')
            ->expectsOutputToContain('Approved by tech lead')
            ->expectsOutputToContain('LaraOrcest completed successfully!')
            ->assertSuccessful();

        $loaded = $checkpointer->load('run_feature_hitl');
        $this->assertNotNull($loaded);
        $this->assertTrue($loaded->isCompleted());
        $this->assertTrue($loaded->isGateDecided('after_plan'));
    }

    public function test_orcest_approve_command_with_reject_terminates_run(): void
    {
        /** @var CheckpointerContract $checkpointer */
        $checkpointer = $this->app->make(CheckpointerContract::class);

        $state = RunState::initial('run_feature_reject', 'Build search API', [
            'interactive' => true,
            'dry_run' => true,
        ]);
        $state = $state->pauseAtGate('after_plan', ['summary' => 'Initial plan']);
        $checkpointer->save($state);

        $this->artisan('orcest:approve', [
            'run_id' => 'run_feature_reject',
            '--reject' => true,
            '--note' => 'Missing elasticsearch indexing',
        ])
            ->expectsOutputToContain('Rejecting run [run_feature_reject] at gate [after_plan]')
            ->expectsOutputToContain('Run [run_feature_reject] has been terminated with rejection status')
            ->assertSuccessful();

        $loaded = $checkpointer->load('run_feature_reject');
        $this->assertNotNull($loaded);
        $this->assertTrue($loaded->isFailed());
        $this->assertStringContainsString('Missing elasticsearch indexing', $loaded->getFailureReason());
    }

    public function test_no_human_review_option_bypasses_review_gates(): void
    {
        $this->artisan('orcest:build', [
            'prompt' => 'Create audit log system',
            '--interactive' => true,
            '--no-human-review' => true,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('LaraOrcest completed successfully!')
            ->assertSuccessful();
    }

    public function test_orcest_status_shows_paused_gate_when_run_is_paused(): void
    {
        /** @var CheckpointerContract $checkpointer */
        $checkpointer = $this->app->make(CheckpointerContract::class);

        $state = RunState::initial('run_status_paused', 'Paused run test');
        $state = $state->pauseAtGate('after_plan', ['summary' => 'Waiting for sign-off']);
        $checkpointer->save($state);

        $this->artisan('orcest:status', ['run_id' => 'run_status_paused'])
            ->expectsOutputToContain('Paused At Gate')
            ->expectsOutputToContain('after_plan')
            ->expectsOutputToContain('Resume command: php artisan orcest:approve run_status_paused')
            ->assertSuccessful();
    }
}
