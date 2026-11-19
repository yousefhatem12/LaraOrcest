<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tools;

use Symfony\Component\Process\Process;
use Yousef\LaraOrcest\Contracts\ToolContract;
use Yousef\LaraOrcest\Support\Config;

class TestRunnerTool implements ToolContract
{
    protected string $projectRoot;

    protected int $timeout;

    protected bool $dryRun;

    public function __construct(?string $projectRoot = null, int $timeout = 120, bool $dryRun = false)
    {
        $this->projectRoot = $projectRoot ?: Config::basePath();
        $this->timeout = $timeout;
        $this->dryRun = $dryRun;
    }

    public function getName(): string
    {
        return 'test_runner';
    }

    public function getDescription(): string
    {
        return 'Executes automated test suites (php artisan test) and captures detailed results.';
    }

    public function setDryRun(bool $dryRun): self
    {
        $this->dryRun = $dryRun;

        return $this;
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'filter' => [
                    'type' => 'string',
                    'description' => 'Optional filter for specific test method or class name.',
                ],
                'path' => [
                    'type' => 'string',
                    'description' => 'Optional specific test file path to run.',
                ],
            ],
        ];
    }

    public function execute(array $params): array
    {
        if ($this->dryRun) {
            return [
                'passed' => true,
                'exit_code' => 0,
                'duration' => 0.05,
                'summary' => '[DRY-RUN] Automated tests passed in simulation mode.',
                'output' => 'Tests: 1 passed (0 assertions) [DRY-RUN]',
                'failures' => [],
                'dry_run' => true,
            ];
        }

        $command = [PHP_BINARY, 'artisan', 'test', '--without-tty'];

        if (! empty($params['filter'])) {
            $filter = preg_replace('/[^a-zA-Z0-9_:\-]/', '', (string) $params['filter']);
            $command[] = "--filter={$filter}";
        }

        if (! empty($params['path'])) {
            $path = preg_replace('/[^a-zA-Z0-9_\-\/\.]/', '', (string) $params['path']);
            $command[] = $path;
        }

        $startTime = microtime(true);
        $process = new Process($command, $this->projectRoot, timeout: $this->timeout);

        try {
            $process->run();
            $duration = round(microtime(true) - $startTime, 2);
            $exitCode = $process->getExitCode() ?? 1;
            $output = $process->getOutput() . $process->getErrorOutput();
            $passed = $process->isSuccessful();
        } catch (\Throwable $e) {
            $duration = round(microtime(true) - $startTime, 2);
            $exitCode = 1;
            $output = "Test execution aborted: " . $e->getMessage();
            $passed = false;
        }

        $summary = $this->extractSummary($output, $passed);
        $failures = $this->extractFailures($output);

        return [
            'passed' => $passed,
            'exit_code' => $exitCode,
            'duration' => $duration,
            'summary' => $summary,
            'output' => mb_substr($output, 0, 10000),
            'failures' => $failures,
            'dry_run' => false,
        ];
    }

    protected function extractSummary(string $output, bool $passed): string
    {
        if (preg_match('/Tests:\s+([^\n\r]+)/', $output, $matches)) {
            return trim($matches[0]);
        }

        return $passed ? 'All tests passed successfully.' : 'Tests failed or encountered errors.';
    }

    /**
     * @return array<int, string>
     */
    protected function extractFailures(string $output): array
    {
        $failures = [];
        $lines = explode("\n", $output);

        foreach ($lines as $line) {
            if (str_contains($line, 'FAIL') || str_contains($line, 'FAILED') || str_contains($line, 'Error:')) {
                $trimmed = trim($line);
                if ($trimmed !== '' && ! in_array($trimmed, $failures, true)) {
                    $failures[] = $trimmed;
                }
            }
        }

        return array_slice($failures, 0, 10);
    }
}
