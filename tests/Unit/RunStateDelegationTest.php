<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Yousef\LaraOrcest\State\RunState;

class RunStateDelegationTest extends TestCase
{
    public function test_record_delegation_and_getters(): void
    {
        $state = RunState::create('Implement user authentication');

        $this->assertEmpty($state->getDelegations());

        $state = $state->recordDelegation(
            subAgentKey: 'migration_designer',
            parentAgentKey: 'database_agent',
            activeSkills: ['database-migration-hygiene', 'laravel-strict-standards'],
            status: 'completed',
            summary: 'Created users and password_resets tables',
            output: ['table' => 'users'],
            artifacts: [['path' => 'database/migrations/2026_01_01_create_users_table.php']]
        );

        $state = $state->recordDelegation(
            subAgentKey: 'route_planner',
            parentAgentKey: 'backend_agent',
            activeSkills: ['laravel-strict-standards'],
            status: 'completed',
            summary: 'Defined auth routes',
            output: ['routes' => ['/login', '/register']]
        );

        $delegations = $state->getDelegations();
        $this->assertCount(2, $delegations);

        $dbDelegations = $state->getDelegationsForAgent('database_agent');
        $this->assertCount(1, $dbDelegations);
        $this->assertEquals('migration_designer', $dbDelegations[0]['sub_agent']);
        $this->assertEquals(['database-migration-hygiene', 'laravel-strict-standards'], $dbDelegations[0]['active_skills']);

        $backendDelegations = $state->getDelegationsForAgent('backend_agent');
        $this->assertCount(1, $backendDelegations);
        $this->assertEquals('route_planner', $backendDelegations[0]['sub_agent']);
    }

    public function test_record_artifact_with_agent_attribution(): void
    {
        $state = RunState::create('Build feature');

        // Unattributed artifact (legacy style)
        $state = $state->recordArtifact('config/app.php', 'modified', 'Updated timezone');
        $this->assertArrayNotHasKey('agent', $state->artifacts[0]);

        // Attributed artifact (sub-agent or lead agent style)
        $state = $state->recordArtifact('database/migrations/001.php', 'created', 'Users migration', 'migration_designer');
        $this->assertArrayHasKey('agent', $state->artifacts[1]);
        $this->assertEquals('migration_designer', $state->artifacts[1]['agent']);
    }

    public function test_serialization_round_trip_with_delegations(): void
    {
        $state = RunState::create('Test state serialization');
        $state = $state->recordDelegation(
            subAgentKey: 'sub_worker',
            parentAgentKey: 'lead_worker',
            activeSkills: ['skill-1'],
            status: 'completed',
            summary: 'Worker done',
            output: ['key' => 'value']
        );

        $serialized = $state->toArray();
        $this->assertArrayHasKey('delegations', $serialized);
        $this->assertCount(1, $serialized['delegations']);

        $restored = RunState::fromArray($serialized);
        $this->assertCount(1, $restored->getDelegations());
        $this->assertEquals('sub_worker', $restored->getDelegations()[0]['sub_agent']);
        $this->assertEquals('completed', $restored->getDelegations()[0]['status']);
    }

    public function test_backward_compatibility_with_legacy_array_without_delegations(): void
    {
        $legacyData = [
            'schema_version' => '1.0',
            'run_id' => 'legacy_run_456',
            'prompt' => 'Old legacy run',
            'current_node' => 'database',
            'status' => 'running',
            'artifacts' => [
                ['path' => 'app/Models/User.php', 'action' => 'created', 'summary' => 'Model'],
            ],
            // NOTE: 'delegations' key is deliberately missing!
        ];

        $restored = RunState::fromArray($legacyData);
        $this->assertEquals('legacy_run_456', $restored->runId);
        $this->assertIsArray($restored->delegations);
        $this->assertEmpty($restored->delegations);
        $this->assertCount(1, $restored->artifacts);
    }

    public function test_merge_with_delegations_across_parallel_branches(): void
    {
        $base = RunState::create('Branch base');

        $branch1 = $base->recordDelegation(
            subAgentKey: 'branch_1_sub',
            parentAgentKey: 'lead',
            activeSkills: [],
            status: 'completed',
            summary: 'Branch 1 finished'
        );

        $branch2 = $base->recordDelegation(
            subAgentKey: 'branch_2_sub',
            parentAgentKey: 'lead',
            activeSkills: [],
            status: 'completed',
            summary: 'Branch 2 finished'
        );

        $merged = $base->mergeParallelStates([$branch1, $branch2]);
        $this->assertCount(2, $merged->getDelegations());
    }
}
