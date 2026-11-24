<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Yousef\LaraOrcest\Config\OrchestratorConfig;
use Yousef\LaraOrcest\Support\Config;

class OrchestratorConfigTest extends TestCase
{
    protected string $tempDir;
    protected array $envBackup = [];
    protected array $serverBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->envBackup = $_ENV;
        $this->serverBackup = $_SERVER;
        Config::clearOverrides();
        $this->tempDir = sys_get_temp_dir() . '/lara_orcest_cfg_test_' . bin2hex(random_bytes(4));
        @mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        putenv('LARA_ORCEST_MAX_STEPS');
        putenv('LARA_ORCEST_MAX_FIX_ATTEMPTS');
        $_ENV = $this->envBackup;
        $_SERVER = $this->serverBackup;

        Config::clearOverrides();
        if (is_dir($this->tempDir)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tempDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $fileinfo) {
                ($fileinfo->isDir() ? 'rmdir' : 'unlink')($fileinfo->getRealPath());
            }
            @rmdir($this->tempDir);
        }
        parent::tearDown();
    }

    public function test_loads_defaults_without_laravel_boot(): void
    {
        $config = OrchestratorConfig::load($this->tempDir);

        $this->assertSame(40, (int) $config->get('execution.max_steps'));
        $this->assertSame(3, (int) $config->get('execution.max_fix_attempts'));
        $this->assertFalse((bool) $config->get('execution.dry_run'));
        $this->assertTrue($config->has('execution.max_steps'));
        $this->assertSame(realpath($this->tempDir), $config->getTargetProjectPath());
    }

    public function test_merges_target_project_config_json(): void
    {
        $orcestDir = $this->tempDir . '/.lara-orcest';
        @mkdir($orcestDir, 0755, true);

        $customConfig = [
            'execution' => [
                'max_steps' => 99,
                'max_fix_attempts' => 9,
            ],
            'custom_key' => 'custom_val',
        ];
        file_put_contents($orcestDir . '/config.json', json_encode($customConfig));

        $config = OrchestratorConfig::load($this->tempDir);

        $this->assertSame(99, $config->get('execution.max_steps'));
        $this->assertSame(9, $config->get('execution.max_fix_attempts'));
        $this->assertSame('custom_val', $config->get('custom_key'));
    }

    public function test_merges_target_project_env_file(): void
    {
        file_put_contents($this->tempDir . '/.env', "LARA_ORCEST_MAX_STEPS=75\nLARA_ORCEST_MAX_FIX_ATTEMPTS=5\n");

        $config = OrchestratorConfig::load($this->tempDir);

        $this->assertSame(75, (int) $config->get('execution.max_steps'));
        $this->assertSame(5, (int) $config->get('execution.max_fix_attempts'));
    }

    public function test_dot_notation_access_and_set(): void
    {
        $config = new OrchestratorConfig([
            'database' => [
                'driver' => 'mysql',
                'connections' => [
                    'primary' => ['host' => '127.0.0.1'],
                ],
            ],
        ]);

        $this->assertSame('mysql', $config->get('database.driver'));
        $this->assertSame('127.0.0.1', $config->get('database.connections.primary.host'));
        $this->assertSame('default_val', $config->get('database.non_existent', 'default_val'));

        $config->set('database.connections.primary.port', 3306);
        $this->assertSame(3306, $config->get('database.connections.primary.port'));
    }

    public function test_registers_into_support_config(): void
    {
        $config = OrchestratorConfig::load($this->tempDir, [
            'execution' => ['max_steps' => 88],
        ]);

        $this->assertSame(88, Config::get('execution.max_steps'));
        $this->assertSame(88, Config::get('lara-orcest.execution.max_steps'));
    }
}
