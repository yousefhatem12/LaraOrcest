<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Feature;

use Yousef\LaraOrcest\Contracts\CheckpointerContract;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Tests\TestCase;

class ArtisanCommandsFeatureTest extends TestCase
{
    public function test_orcest_build_with_argument(): void
    {
        $this->artisan('orcest:build', [
            'prompt' => 'Create blog tags feature',
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Starting LaraOrcest execution [DRY-RUN MODE]')
            ->expectsOutputToContain('LaraOrcest completed successfully!')
            ->assertSuccessful();
    }

    public function test_orcest_build_with_prompt_option(): void
    {
        $this->artisan('orcest:build', [
            '--prompt' => 'Create comments system',
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Prompt: Create comments system')
            ->assertSuccessful();
    }

    public function test_orcest_build_fails_when_prompt_empty(): void
    {
        $this->artisan('orcest:build')
            ->expectsOutputToContain('Please provide a prompt argument or use --prompt')
            ->assertFailed();
    }

    public function test_orcest_build_with_resume_flag(): void
    {
        /** @var CheckpointerContract $checkpointer */
        $checkpointer = $this->app->make(CheckpointerContract::class);

        $state = RunState::initial('run_cli_resume', 'Build invoices API', [
            'dry_run' => true,
        ]);
        $state = $state->withCurrentNode('backend_agent');
        $checkpointer->save($state);

        $this->artisan('orcest:build', [
            '--resume' => 'run_cli_resume',
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Resuming LaraOrcest run [run_cli_resume]')
            ->expectsOutputToContain('LaraOrcest completed successfully!')
            ->assertSuccessful();
    }

    public function test_orcest_status_lists_recent_runs(): void
    {
        /** @var CheckpointerContract $checkpointer */
        $checkpointer = $this->app->make(CheckpointerContract::class);

        $state = RunState::initial('run_status_list', 'Status list test');
        $checkpointer->save($state);

        $this->artisan('orcest:status')
            ->expectsOutputToContain('Recent LaraOrcest Execution Runs:')
            ->expectsOutputToContain('run_status_list')
            ->assertSuccessful();
    }

    public function test_orcest_status_shows_run_details(): void
    {
        /** @var CheckpointerContract $checkpointer */
        $checkpointer = $this->app->make(CheckpointerContract::class);

        $state = RunState::initial('run_status_detail', 'Status detail test');
        $state = $state->recordArtifact('app/Models/Item.php', 'created', 'Item model');
        $state = $state->recordTestResult(true, 0, '5 passed', 'OK');
        $state = $state->recordReview('approved', ['Code is clean and modular']);
        $checkpointer->save($state);

        $this->artisan('orcest:status', ['run_id' => 'run_status_detail'])
            ->expectsOutputToContain('Run Details: run_status_detail')
            ->expectsOutputToContain('Artifacts Created')
            ->expectsOutputToContain('app/Models/Item.php')
            ->expectsOutputToContain('Automated Tests')
            ->expectsOutputToContain('Latest Review Decision')
            ->assertSuccessful();
    }

    public function test_orcest_status_shows_error_for_invalid_run_id(): void
    {
        $this->artisan('orcest:status', ['run_id' => 'run_does_not_exist'])
            ->expectsOutputToContain('Run [run_does_not_exist] not found')
            ->assertFailed();
    }
}
