<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Feature;

use Yousef\LaraOrcest\Facades\Orcest;
use Yousef\LaraOrcest\Facades\Orchestrator;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Tests\TestCase;

class OrcestFacadeFeatureTest extends TestCase
{
    public function test_orcest_facade_runs_and_completes_in_dry_run(): void
    {
        $state = Orcest::run('Create payment webhook processor', [
            'dry_run' => true,
        ]);

        $this->assertInstanceOf(RunState::class, $state);
        $this->assertTrue($state->isCompleted());
        $this->assertNotEmpty($state->artifacts);
        $this->assertTrue($state->isReviewApproved());
    }

    public function test_orcest_facade_status_and_resume(): void
    {
        $state = Orchestrator::run('Build auth tokens feature', [
            'dry_run' => true,
        ]);

        // Status check
        $status = Orcest::status($state->runId);
        $this->assertSame($state->runId, $status['run_id']);
        $this->assertSame(RunState::STATUS_COMPLETED, $status['status']);

        // Resume check
        $resumed = Orcest::resume($state->runId);
        $this->assertSame($state->runId, $resumed->runId);
        $this->assertTrue($resumed->isCompleted());
    }
}
