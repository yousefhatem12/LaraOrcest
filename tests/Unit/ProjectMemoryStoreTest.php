<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Yousef\LaraOrcest\Memory\ProjectMemoryStore;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Tools\Support\PathGuard;

class ProjectMemoryStoreTest extends TestCase
{
    protected string $tempDir;

    protected string $memoryFile;

    protected PathGuard $pathGuard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/lara_orcest_memory_unit_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0755, true);
        mkdir($this->tempDir . '/app/Models', 0755, true);
        mkdir($this->tempDir . '/app/Http/Controllers', 0755, true);
        mkdir($this->tempDir . '/database/migrations', 0755, true);
        mkdir($this->tempDir . '/routes', 0755, true);

        // Pre-create sample files for indexing
        file_put_contents($this->tempDir . '/app/Models/User.php', '<?php namespace App\Models; class User {}');
        file_put_contents($this->tempDir . '/app/Models/Order.php', '<?php namespace App\Models; class Order {}');
        file_put_contents($this->tempDir . '/app/Http/Controllers/OrderController.php', '<?php namespace App\Http\Controllers; class OrderController {}');
        file_put_contents($this->tempDir . '/database/migrations/2026_01_01_000001_create_orders_table.php', '<?php');
        file_put_contents($this->tempDir . '/routes/api.php', '<?php');

        $this->memoryFile = $this->tempDir . '/.lara-orcest/memory.json';
        $this->pathGuard = new PathGuard($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->tempDir);
        parent::tearDown();
    }

    protected function deleteDirectory(string $dir): void
    {
        if (! file_exists($dir)) {
            return;
        }

        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    public function test_initializes_default_memory_structure_and_indexes_codebase(): void
    {
        $store = new ProjectMemoryStore(
            path: $this->memoryFile,
            pathGuard: $this->pathGuard,
            enabled: true,
            maxContextChars: 2000,
            maxItems: 10,
            autoIndex: true
        );

        $data = $store->load();

        $this->assertEquals(1, $data['schema_version']);
        $this->assertNotEmpty($data['codebase_index']['models']);
        $this->assertArrayHasKey('User', $data['codebase_index']['models']);
        $this->assertArrayHasKey('Order', $data['codebase_index']['models']);
        $this->assertArrayHasKey('OrderController', $data['codebase_index']['controllers']);
        $this->assertContains('routes/api.php', $data['codebase_index']['routes']);
        $this->assertNotEmpty($data['conventions']);
        $this->assertFileExists($this->memoryFile);
    }

    public function test_consolidates_run_artifacts_and_decisions_incrementally(): void
    {
        $store = new ProjectMemoryStore(
            path: $this->memoryFile,
            pathGuard: $this->pathGuard,
            enabled: true,
            autoIndex: false
        );

        $state1 = RunState::create('Implement customer checkout flow', [
            'run_id' => 'run_checkout_01',
            'dry_run' => false,
        ]);
        $state1 = $state1->recordArtifact('app/Models/Payment.php', 'created', 'Payment model');
        $state1 = $state1->recordArtifact('app/Http/Controllers/CheckoutController.php', 'created', 'Checkout controller');
        $state1 = $state1->withTechnicalPlan([
            'execution_steps' => [
                'Create Payment Eloquent model',
                'Implement CheckoutController process method',
            ],
        ]);

        $store->consolidate($state1);

        $decisions = $store->getDecisions();
        $this->assertCount(1, $decisions);
        $this->assertEquals('Implement customer checkout flow', $decisions[0]['title']);
        $this->assertContains('app/Models/Payment.php', $decisions[0]['files']);

        $index = $store->getIndex();
        $this->assertArrayHasKey('Payment', $index['models']);
        $this->assertArrayHasKey('CheckoutController', $index['controllers']);

        // Second run - incremental growth
        $state2 = RunState::create('Add invoice generation for orders', [
            'run_id' => 'run_invoice_02',
            'dry_run' => false,
        ]);
        $state2 = $state2->recordArtifact('app/Models/Invoice.php', 'created', 'Invoice model');

        $store->consolidate($state2);

        $this->assertCount(2, $store->getDecisions());
        $this->assertArrayHasKey('Invoice', $store->getIndex()['models']);
    }

    public function test_deduplicates_repeated_decisions_and_pitfalls(): void
    {
        $store = new ProjectMemoryStore(
            path: $this->memoryFile,
            pathGuard: $this->pathGuard,
            enabled: true,
            autoIndex: false
        );

        $state = RunState::create('Implement user authentication API', [
            'run_id' => 'run_auth_duplicate',
            'dry_run' => false,
        ]);
        $state = $state->recordArtifact('app/Models/User.php', 'modified', 'Auth tokens');

        // Consolidate first time
        $store->consolidate($state);
        $this->assertCount(1, $store->getDecisions());

        // Consolidate second time with same run_id and prompt
        $store->consolidate($state);
        $this->assertCount(1, $store->getDecisions(), 'Duplicate run decision should not create additional entries');
    }

    public function test_records_resolved_pitfalls_from_fixer_resolutions(): void
    {
        $store = new ProjectMemoryStore(
            path: $this->memoryFile,
            pathGuard: $this->pathGuard,
            enabled: true,
            autoIndex: false
        );

        $state = RunState::create('Fix foreign key constraint on order_items migration', [
            'run_id' => 'run_pitfall_test',
            'dry_run' => false,
        ]);

        $state = $state->withMetadata([
            'fixer_resolutions' => [
                [
                    'root_cause' => 'order_items migration referenced orders table before orders migration executed.',
                    'fixed_files' => ['database/migrations/2026_01_01_000002_create_order_items_table.php'],
                    'timestamp' => date('c'),
                ],
            ],
        ]);
        $state = $state->incrementFixAttempts();
        $state = $state->recordTestResult(true, 0, 'All tests passed after migration reorder', '');

        $store->consolidate($state);

        $pitfalls = $store->getPitfalls();
        $this->assertCount(1, $pitfalls);
        $this->assertStringContainsString('order_items migration referenced orders table', $pitfalls[0]['root_cause']);
        $this->assertStringContainsString('Applied fix to files', $pitfalls[0]['resolution']);
    }

    public function test_recall_for_context_selects_agent_scoped_slices_and_enforces_budget(): void
    {
        $store = new ProjectMemoryStore(
            path: $this->memoryFile,
            pathGuard: $this->pathGuard,
            enabled: true,
            maxContextChars: 800,
            autoIndex: true
        );

        $store->recordDecision(
            'Order checkout pipeline',
            'Built multi-step checkout with Stripe webhook listener',
            ['files' => ['app/Http/Controllers/OrderController.php']]
        );

        $store->recordPitfall(
            'Stripe webhook signature failure',
            'Missing STRIPE_WEBHOOK_SECRET in test environment caused 400 Bad Request',
            'Mocked WebhookSignatureValidator in test environment',
            ['paths' => ['tests/Feature/StripeWebhookTest.php']]
        );

        // 1. Recall for backend_agent with order query
        $backendRecall = $store->recallForContext('backend_agent', 'Implement order status update endpoint');

        $this->assertStringContainsString('PROJECT MEMORY & CODEBASE CONTEXT', $backendRecall);
        $this->assertStringContainsString('FileReaderTool', $backendRecall);
        $this->assertStringContainsString('OrderController', $backendRecall);
        $this->assertLessThanOrEqual(800, mb_strlen($backendRecall));

        // 2. Recall for fixer_agent with test failure query
        $fixerRecall = $store->recallForContext('fixer_agent', 'Debug Stripe webhook signature verification error');

        $this->assertStringContainsString('Missing STRIPE_WEBHOOK_SECRET', $fixerRecall);
        $this->assertStringContainsString('Mocked WebhookSignatureValidator', $fixerRecall);
        $this->assertLessThanOrEqual(800, mb_strlen($fixerRecall));
    }

    public function test_skips_memory_writes_when_disabled_or_during_dry_run(): void
    {
        // 1. Dry run should NOT write memory file
        $dryRunFile = $this->tempDir . '/.lara-orcest/dry_run_memory.json';
        $storeDry = new ProjectMemoryStore(
            path: $dryRunFile,
            pathGuard: $this->pathGuard,
            enabled: true,
            autoIndex: false
        );

        $dryState = RunState::create('Preview new notification system', [
            'dry_run' => true,
        ]);
        $dryState = $dryState->recordArtifact('app/Models/Notification.php', 'created', 'Notification model');

        $storeDry->consolidate($dryState);
        $this->assertFileDoesNotExist($dryRunFile, 'Memory file must NOT be written during dry_run');

        // 2. Disabled memory should return empty string for recall and skip consolidate
        $storeDisabled = new ProjectMemoryStore(
            path: $dryRunFile,
            pathGuard: $this->pathGuard,
            enabled: false,
            autoIndex: false
        );

        $this->assertSame('', $storeDisabled->recallForContext('architect', 'Build something'));

        $normalState = RunState::create('Feature requirement', ['dry_run' => false]);
        $storeDisabled->consolidate($normalState);
        $this->assertFileDoesNotExist($dryRunFile, 'Memory file must NOT be written when memory is disabled');
    }

    public function test_sanitizes_potential_secrets_from_stored_memory(): void
    {
        $store = new ProjectMemoryStore(
            path: $this->memoryFile,
            pathGuard: $this->pathGuard,
            enabled: true,
            autoIndex: false
        );

        $store->recordDecision(
            'Setup third-party integration',
            'Configured integration using api_key="sk-live1234567890abcdef1234567890" and Bearer secret_token_xyz',
            []
        );

        $decisions = $store->getDecisions();
        $this->assertCount(1, $decisions);
        $summary = $decisions[0]['summary'];

        $this->assertStringNotContainsString('sk-live1234567890abcdef1234567890', $summary);
        $this->assertStringContainsString('[REDACTED', $summary);
    }
}
