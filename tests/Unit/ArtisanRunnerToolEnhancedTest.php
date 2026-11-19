<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Yousef\LaraOrcest\Tools\ArtisanRunnerTool;

class ArtisanRunnerToolEnhancedTest extends TestCase
{
    protected string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/lara_orcest_artisan_test_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0755, true);

        // Create a mock artisan executable script in temp dir
        $artisanContent = <<<'PHP'
<?php
$args = array_slice($argv, 1);
$cmd = $args[0] ?? '';
if ($cmd === 'fail:command') {
    fwrite(STDERR, "Error occurred\n");
    exit(1);
}
echo "Executed: " . implode(' ', $args);
exit(0);
PHP;
        file_put_contents($this->tempDir . '/artisan', $artisanContent);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempDir . '/artisan')) {
            unlink($this->tempDir . '/artisan');
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    public function test_tool_metadata_and_schema(): void
    {
        $tool = new ArtisanRunnerTool(projectRoot: $this->tempDir);

        $this->assertSame('artisan_runner', $tool->getName());
        $this->assertNotEmpty($tool->getDescription());
        $this->assertFalse($tool->isDryRun());

        $schema = $tool->getSchema();
        $this->assertSame('object', $schema['type']);
        $this->assertArrayHasKey('command', $schema['properties']);
        $this->assertArrayHasKey('arguments', $schema['properties']);
        $this->assertContains('command', $schema['required']);
    }

    public function test_default_allowed_commands_contain_modern_laravel_commands(): void
    {
        $tool = new ArtisanRunnerTool(projectRoot: $this->tempDir);
        $allowed = $tool->getAllowedCommands();

        $this->assertContains('make:model', $allowed);
        $this->assertContains('make:migration', $allowed);
        $this->assertContains('make:controller', $allowed);
        $this->assertContains('make:class', $allowed);
        $this->assertContains('make:enum', $allowed);
        $this->assertContains('make:interface', $allowed);
        $this->assertContains('make:trait', $allowed);
        $this->assertContains('route:list', $allowed);
        $this->assertContains('migrate:status', $allowed);
    }

    public function test_dry_run_mode_returns_simulated_payload(): void
    {
        $tool = new ArtisanRunnerTool(projectRoot: $this->tempDir, dryRun: true);

        $result = $tool->execute([
            'command' => 'make:model',
            'arguments' => ['Post', '--migration'],
        ]);

        $this->assertTrue($result['dry_run']);
        $this->assertTrue($result['successful']);
        $this->assertSame(0, $result['exit_code']);
        $this->assertSame('make:model', $result['command']);
        $this->assertSame(['Post', '--migration'], $result['arguments']);
        $this->assertStringContainsString('[DRY-RUN] Simulated execution', $result['output']);
    }

    public function test_set_dry_run_mutates_state(): void
    {
        $tool = new ArtisanRunnerTool(projectRoot: $this->tempDir);
        $this->assertFalse($tool->isDryRun());

        $tool->setDryRun(true);
        $this->assertTrue($tool->isDryRun());

        $tool->setDryRun(false);
        $this->assertFalse($tool->isDryRun());
    }

    public function test_throws_exception_when_command_missing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Missing 'command' parameter");

        $tool = new ArtisanRunnerTool(projectRoot: $this->tempDir);
        $tool->execute([]);
    }

    public function test_throws_exception_for_invalid_command_format(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid Artisan command format');

        $tool = new ArtisanRunnerTool(projectRoot: $this->tempDir);
        $tool->execute(['command' => 'make:model; rm -rf /']);
    }

    public function test_blocks_disallowed_artisan_commands(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Artisan command [db:wipe] is not allowed');

        $tool = new ArtisanRunnerTool(projectRoot: $this->tempDir, allowedCommands: ['make:model']);
        $tool->execute(['command' => 'db:wipe']);
    }

    public function test_blocks_tinker_command(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Artisan command [tinker] is not allowed');

        $tool = new ArtisanRunnerTool(projectRoot: $this->tempDir);
        $tool->execute(['command' => 'tinker']);
    }

    public function test_blocks_dangerous_flags(): void
    {
        $dangerousFlags = ['--force', '--force=true', '--drop-views', '--drop-types', '--seed'];

        $tool = new ArtisanRunnerTool(projectRoot: $this->tempDir, allowedCommands: ['migrate']);

        foreach ($dangerousFlags as $flag) {
            try {
                $tool->execute([
                    'command' => 'migrate',
                    'arguments' => [$flag],
                ]);
                $this->fail("Expected RuntimeException for dangerous flag: {$flag}");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('is strictly blocked for Artisan execution', $e->getMessage());
            }
        }
    }

    public function test_blocks_shell_operators_in_arguments(): void
    {
        $dangerousArgs = [
            'Test; rm -rf /',
            'Test && cat /etc/passwd',
            'Test | grep secret',
            'Test `id`',
            'Test $(whoami)',
            "Test\nrm -rf /",
            "Test\r\nrm -rf /",
            'Test > /tmp/output',
            'Test < /dev/zero',
        ];

        $tool = new ArtisanRunnerTool(projectRoot: $this->tempDir, allowedCommands: ['make:model']);

        foreach ($dangerousArgs as $arg) {
            try {
                $tool->execute([
                    'command' => 'make:model',
                    'arguments' => [$arg],
                ]);
                $this->fail("Expected RuntimeException for shell metacharacter: {$arg}");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('Shell metacharacters detected', $e->getMessage());
            }
        }
    }

    public function test_blocks_null_bytes_in_arguments(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Argument contains null bytes');

        $tool = new ArtisanRunnerTool(projectRoot: $this->tempDir, allowedCommands: ['make:model']);
        $tool->execute([
            'command' => 'make:model',
            'arguments' => ["User\0evil"],
        ]);
    }

    public function test_executes_allowed_command_successfully(): void
    {
        $tool = new ArtisanRunnerTool(projectRoot: $this->tempDir, allowedCommands: ['make:model']);
        $result = $tool->execute([
            'command' => 'make:model',
            'arguments' => ['Product', '--migration'],
        ]);

        $this->assertTrue($result['successful']);
        $this->assertSame(0, $result['exit_code']);
        $this->assertSame('make:model', $result['command']);
        $this->assertStringContainsString('Executed: make:model Product --migration', $result['output']);
        $this->assertFalse($result['dry_run']);
    }

    public function test_captures_failure_exit_code_and_stderr(): void
    {
        $tool = new ArtisanRunnerTool(projectRoot: $this->tempDir, allowedCommands: ['fail:command']);
        $result = $tool->execute([
            'command' => 'fail:command',
        ]);

        $this->assertFalse($result['successful']);
        $this->assertSame(1, $result['exit_code']);
        $this->assertStringContainsString('Error occurred', $result['output']);
    }
}
