<?php

declare(strict_types=1);

/**
 * Sample Programmatic Orchestration with LaraOrcest
 *
 * This script demonstrates how to trigger LaraOrcest workflows programmatically
 * from a Laravel controller, queued job, or scheduled command.
 */

use Yousef\LaraOrcest\Contracts\TaskDefinition;
use Yousef\LaraOrcest\Facades\Orcest;
use Yousef\LaraOrcest\Graph\ParallelScheduler;
use Yousef\LaraOrcest\Graph\ScopeConflictDetector;
use Yousef\LaraOrcest\State\RunState;

// -----------------------------------------------------------------------------
// 1. Triggering an Orchestration Run Programmatically
// -----------------------------------------------------------------------------
$prompt = 'Create a coupon validation API with discount limits and expiration checks';

$options = [
    // Safe dry-run preview (set to false for disk modification)
    'dry_run' => false,

    // Enable OpenAPI / Swagger documentation generation
    'swagger' => true,
    'swagger_format' => 'both', // json and yaml

    // Step-by-step progress listener for real-time logging / WebSocket broadcast
    'on_step' => function (RunState $state, string $node, string $message) {
        $step = $state->guardrails['step_count'] ?? 0;
        echo "[Step {$step}] Node: {$node} - {$message}\n";
    },

    // Execution limits
    'max_steps' => 35,
    'max_fix_attempts' => 3,
];

// Execute the run
$state = Orcest::run($prompt, $options);

// -----------------------------------------------------------------------------
// 2. Handling Human-in-the-Loop Review Gates Programmatically
// -----------------------------------------------------------------------------
if ($state->isPaused()) {
    $gate = $state->getPausedGate();
    echo "⏸ Run [{$state->runId}] paused at review gate: [{$gate}]\n";

    $context = $state->getPauseContext();
    echo "Summary: " . ($context['summary'] ?? 'N/A') . "\n";

    // You can programmatically inspect the proposal and approve/reject:
    // $approvedState = Orcest::approve($state->runId, 'Auto-approved by policy engine');
    // echo "Resumed run status: {$approvedState->status}\n";
    exit(0);
}

// -----------------------------------------------------------------------------
// 3. Inspecting the Final Output
// -----------------------------------------------------------------------------
if ($state->isCompleted()) {
    echo "✔ Run [{$state->runId}] completed successfully!\n";
    echo "Total files generated/updated: " . count($state->artifacts) . "\n";

    foreach ($state->artifacts as $artifact) {
        echo " - [{$artifact['action']}] {$artifact['path']}: {$artifact['summary']}\n";
    }

    echo "Tests passed: " . ($state->areTestsPassing() ? 'YES' : 'NO') . "\n";
    echo "Tokens used: " . ($state->guardrails['token_usage']['total_tokens'] ?? 0) . "\n";
} else {
    echo "⚠ Run failed with reason: " . $state->getFailureReason() . "\n";
}

// -----------------------------------------------------------------------------
// 4. Using ParallelScheduler & ScopeConflictDetector directly
// -----------------------------------------------------------------------------
$tasks = [
    TaskDefinition::fromArray([
        'id' => 'create_coupon_model',
        'title' => 'Coupon Model and Migration',
        'scope_paths' => ['app/Models/Coupon.php', 'database/migrations/*_create_coupons_table.php'],
    ]),
    TaskDefinition::fromArray([
        'id' => 'create_order_model',
        'title' => 'Order Model and Migration',
        'scope_paths' => ['app/Models/Order.php', 'database/migrations/*_create_orders_table.php'],
    ]),
    TaskDefinition::fromArray([
        'id' => 'create_coupon_controller',
        'title' => 'Coupon Controller',
        'depends_on' => ['create_coupon_model'],
        'scope_paths' => ['app/Http/Controllers/CouponController.php'],
    ]),
];

// Verify if tasks have conflicting scope paths
$conflict = ScopeConflictDetector::hasConflict(
    $tasks[0]->getScopePaths(),
    $tasks[1]->getScopePaths()
);
echo "Tasks 0 and 1 have conflict: " . ($conflict ? 'YES' : 'NO') . "\n"; // NO

// Schedule independent tasks into topological waves
$scheduler = new ParallelScheduler();
$taskMap = ParallelScheduler::normalizeTasks($tasks);
$batches = $scheduler->scheduleBatches($taskMap, maxConcurrency: 2);

echo "Scheduled " . count($batches) . " parallel execution wave(s).\n";
foreach ($batches as $waveIndex => $wave) {
    echo "Wave #{$waveIndex}: " . implode(', ', array_keys($wave)) . "\n";
}
