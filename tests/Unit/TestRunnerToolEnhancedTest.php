<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Yousef\LaraOrcest\Tools\TestRunnerTool;

class TestRunnerToolEnhancedTest extends TestCase
{
    protected string $tempDir;
    protected string $mockRunnerScript;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/lara_orcest_test_runner_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0755, true);

        $this->mockRunnerScript = $this->tempDir . '/mock_test_runner.php';
        $scriptContent = <<<'PHP'
<?php
$args = array_slice($argv, 1);

foreach ($args as $arg) {
    if (str_contains($arg, 'sleep')) {
        usleep(1500000); // 1.5 seconds
        exit(0);
    }
    if (str_contains($arg, 'fail')) {
        echo "FAILURES!\n";
        echo "Tests: 3 failed, 2 passed\n";
        echo "1) Tests\\Feature\\ExampleTest::test_failure\n";
        echo "Failed asserting that false is true.\n";
        exit(1);
    }
}

echo "OK (5 tests, 12 assertions)\n";
echo "Tests:  5 passed (12 assertions)\n";
exit(0);
PHP;
        file_put_contents($this->mockRunnerScript, $scriptContent);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->mockRunnerScript)) {
            unlink($this->mockRunnerScript);
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    public function test_tool_metadata_and_schema(): void
    {
        $tool = new TestRunnerTool(projectRoot: $this->tempDir);

        $this->assertSame('test_runner', $tool->getName());
        $this->assertNotEmpty($tool->getDescription());
        $this->assertFalse($tool->isDryRun());

        $schema = $tool->getSchema();
        $this->assertSame('object', $schema['type']);
        $this->assertArrayHasKey('filter', $schema['properties']);
        $this->assertArrayHasKey('path', $schema['properties']);
        $this->assertArrayHasKey('testsuite', $schema['properties']);
    }

    public function test_dry_run_mode(): void
    {
        $tool = new TestRunnerTool(projectRoot: $this->tempDir, dryRun: true);

        $result = $tool->execute(['filter' => 'ExampleTest']);

        $this->assertTrue($result['dry_run']);
        $this->assertTrue($result['passed']);
        $this->assertSame(0, $result['exit_code']);
        $this->assertFalse($result['timed_out']);
        $this->assertSame([], $result['failures']);
        $this->assertStringContainsString('[DRY-RUN]', $result['summary']);
    }

    public function test_set_dry_run_toggles(): void
    {
        $tool = new TestRunnerTool(projectRoot: $this->tempDir);
        $this->assertFalse($tool->isDryRun());

        $tool->setDryRun(true);
        $this->assertTrue($tool->isDryRun());

        $tool->setDryRun(false);
        $this->assertFalse($tool->isDryRun());
    }

    public function test_successful_test_execution_and_summary_parsing(): void
    {
        $tool = new TestRunnerTool(
            projectRoot: $this->tempDir,
            baseCommand: [PHP_BINARY, $this->mockRunnerScript]
        );

        $result = $tool->execute([]);

        $this->assertTrue($result['passed']);
        $this->assertSame(0, $result['exit_code']);
        $this->assertFalse($result['timed_out']);
        $this->assertFalse($result['dry_run']);
        $this->assertSame([], $result['failures']);
        $this->assertStringContainsString('5 passed', $result['summary']);
        $this->assertGreaterThanOrEqual(0, $result['duration']);
    }

    public function test_filtering_and_path_options(): void
    {
        $tool = new TestRunnerTool(
            projectRoot: $this->tempDir,
            baseCommand: [PHP_BINARY, $this->mockRunnerScript]
        );

        $result = $tool->execute([
            'filter' => 'test_user_can_login',
            'testsuite' => 'Feature',
            'path' => 'tests/Feature/AuthTest.php',
        ]);

        $this->assertTrue($result['passed']);
        $this->assertSame(0, $result['exit_code']);
    }

    public function test_failed_test_execution_extracts_failures(): void
    {
        $tool = new TestRunnerTool(
            projectRoot: $this->tempDir,
            baseCommand: [PHP_BINARY, $this->mockRunnerScript]
        );

        $result = $tool->execute(['filter' => 'simulate_fail']);

        $this->assertFalse($result['passed']);
        $this->assertSame(1, $result['exit_code']);
        $this->assertFalse($result['timed_out']);
        $this->assertNotEmpty($result['failures']);
        $this->assertContains('FAILURES!', $result['failures']);
        $this->assertStringContainsString('3 failed', $result['summary']);
    }

    public function test_timeout_handling(): void
    {
        $tool = new TestRunnerTool(
            projectRoot: $this->tempDir,
            baseCommand: [PHP_BINARY, $this->mockRunnerScript],
            timeout: 1
        );

        $result = $tool->execute(['filter' => 'simulate_sleep']);

        $this->assertFalse($result['passed']);
        $this->assertTrue($result['timed_out']);
        $this->assertSame(124, $result['exit_code']);
        $this->assertStringContainsString('timed out', $result['summary']);
    }
}
