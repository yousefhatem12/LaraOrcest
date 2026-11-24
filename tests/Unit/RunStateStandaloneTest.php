<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Yousef\LaraOrcest\State\RunState;

class RunStateStandaloneTest extends TestCase
{
    public function test_can_create_run_state_with_target_project_path_and_workspace_mode(): void
    {
        $state = RunState::create('Build an API endpoint', [
            'target_project_path' => '/var/www/my-laravel-project',
            'workspace_mode' => 'standalone',
        ]);

        $this->assertSame('/var/www/my-laravel-project', $state->getTargetProjectPath());
        $this->assertSame('standalone', $state->getWorkspaceMode());
        $this->assertTrue($state->isStandalone());
        $this->assertFalse($state->isEmbedded());
    }

    public function test_defaults_to_embedded_mode_when_unspecified(): void
    {
        $state = RunState::create('Build something');

        $this->assertNull($state->getTargetProjectPath());
        $this->assertSame('embedded', $state->getWorkspaceMode());
        $this->assertFalse($state->isStandalone());
        $this->assertTrue($state->isEmbedded());
    }

    public function test_preserves_target_project_path_across_state_mutations(): void
    {
        $state = RunState::create('Initial goal', [
            'target_project_path' => '/custom/path',
            'workspace_mode' => 'standalone',
        ]);

        $withStatus = $state->withStatus(RunState::STATUS_COMPLETED);
        $this->assertSame('/custom/path', $withStatus->getTargetProjectPath());
        $this->assertSame('standalone', $withStatus->getWorkspaceMode());

        $withNode = $withStatus->withCurrentNode('architect');
        $this->assertSame('/custom/path', $withNode->getTargetProjectPath());

        $withMeta = $withNode->withMetadata(['custom' => 'data']);
        $this->assertSame('/custom/path', $withMeta->getTargetProjectPath());

        $incremented = $withMeta->incrementSteps();
        $this->assertSame('/custom/path', $incremented->getTargetProjectPath());

        $withNewTarget = $incremented->withTargetProjectPath('/new/target/path');
        $this->assertSame('/new/target/path', $withNewTarget->getTargetProjectPath());
        $this->assertSame('standalone', $withNewTarget->getWorkspaceMode());

        $withEmbedded = $withNewTarget->withWorkspaceMode('embedded');
        $this->assertSame('/new/target/path', $withEmbedded->getTargetProjectPath());
        $this->assertSame('embedded', $withEmbedded->getWorkspaceMode());
        $this->assertTrue($withEmbedded->isEmbedded());
    }

    public function test_serialization_round_trip_preserves_target_project_path_and_workspace_mode(): void
    {
        $original = RunState::create('Serialize test', [
            'target_project_path' => '/opt/apps/my-app',
            'workspace_mode' => 'standalone',
        ]);

        $array = $original->toArray();
        $this->assertSame('/opt/apps/my-app', $array['target_project_path']);
        $this->assertSame('standalone', $array['workspace_mode']);

        $restored = RunState::fromArray($array);
        $this->assertSame($original->runId, $restored->runId);
        $this->assertSame('/opt/apps/my-app', $restored->getTargetProjectPath());
        $this->assertSame('standalone', $restored->getWorkspaceMode());
        $this->assertTrue($restored->isStandalone());
    }

    public function test_deserialization_defaults_for_backward_compatibility(): void
    {
        $legacyArray = [
            'run_id' => 'run-12345',
            'prompt' => 'Legacy test',
            'current_node' => 'start',
            'status' => 'running',
            'requirements_spec' => [],
            'backlog' => [],
            'technical_plan' => [],
            'git_state' => [],
            'artifacts' => [],
            'test_results' => [],
            'reviews' => [],
            'memory' => [],
            'guardrails' => [
                'step_count' => 0,
                'fix_attempts' => 0,
                'max_steps' => 40,
                'max_fix_attempts' => 3,
                'token_usage' => ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0],
            ],
            'created_at' => '2026-01-01T00:00:00+00:00',
            'updated_at' => '2026-01-01T00:00:00+00:00',
        ];

        $restored = RunState::fromArray($legacyArray);
        $this->assertNull($restored->getTargetProjectPath());
        $this->assertSame('embedded', $restored->getWorkspaceMode());
        $this->assertTrue($restored->isEmbedded());
        $this->assertFalse($restored->isStandalone());
    }

    public function test_fork_branch_and_merge_parallel_states_preserve_workspace_mode(): void
    {
        $parent = RunState::create('Parent run', [
            'target_project_path' => '/srv/projects/laravel',
            'workspace_mode' => 'standalone',
        ]);

        $branchA = $parent->forkBranch('branch-a');
        $this->assertSame('/srv/projects/laravel', $branchA->getTargetProjectPath());
        $this->assertSame('standalone', $branchA->getWorkspaceMode());

        $branchB = $parent->forkBranch('branch-b');
        $this->assertSame('/srv/projects/laravel', $branchB->getTargetProjectPath());
        $this->assertSame('standalone', $branchB->getWorkspaceMode());

        $merged = $parent->mergeParallelStates([$branchA, $branchB]);
        $this->assertSame('/srv/projects/laravel', $merged->getTargetProjectPath());
        $this->assertSame('standalone', $merged->getWorkspaceMode());
    }
}
