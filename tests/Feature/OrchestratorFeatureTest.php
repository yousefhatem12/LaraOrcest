<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Feature;

use Yousef\LaraOrcest\Facades\Orchestrator;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Tests\TestCase;

class OrchestratorFeatureTest extends TestCase
{
    public function test_service_provider_registers_facade_and_bindings(): void
    {
        $this->assertTrue($this->app->bound('lara-orcest'));
        $this->assertInstanceOf(\Yousef\LaraOrcest\Orchestrator::class, $this->app->make('lara-orcest'));
    }

    public function test_facade_runs_orchestration_in_dry_run(): void
    {
        $result = Orchestrator::run('Build a notification system for invoices', [
            'dry_run' => true,
        ]);

        $this->assertInstanceOf(RunState::class, $result);
        $this->assertEquals(RunState::STATUS_COMPLETED, $result->status);
        $this->assertNotEmpty($result->requirementsSpec);
        $this->assertNotEmpty($result->backlog);
        $this->assertNotEmpty($result->technicalPlan);
        $this->assertTrue($result->isReviewApproved());
    }

    public function test_artisan_build_command_runs_successfully(): void
    {
        $this->artisan('orcest:build', [
            'prompt' => 'Create blog tags feature',
            '--dry-run' => true,
        ])
            ->assertSuccessful();
    }

    public function test_artisan_status_command_runs(): void
    {
        $this->artisan('orcest:status')
            ->assertSuccessful();
    }
}
