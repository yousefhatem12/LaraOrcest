<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tools;

use Symfony\Component\Process\Process;
use Yousef\LaraOrcest\Contracts\ToolContract;
use Yousef\LaraOrcest\Support\Config;

class TestRunnerTool implements ToolContract
{
    protected string $projectRoot;

    /**
     * @var array<int, string>
     */
    protected array $baseCommand;

    protected int $timeout;

    protected bool $dryRun;

    /**
     * @param  array<int, string>|null  $baseCommand
     */
    public function __construct(
        ?string $projectRoot = null,
        ?array $baseCommand = null,
        int $timeout = 120,
        bool $dryRun = false
    ) {
        $this->projectRoot = $projectRoot ?: Config::basePath();

        $configuredCmd = Config::get('lara-orcest.tools.test_command');
        if (is_array($configuredCmd) && ! empty($configuredCmd)) {
            $this->baseCommand = $configuredCmd;
        } elseif (is_string($configuredCmd) && trim($configuredCmd) !== '') {
            $this->baseCommand = explode(' ', trim($configuredCmd));
        } else {
            $this->baseCommand = $baseCommand ?: [PHP_BINARY, 'artisan', 'test', '--without-tty'];
        }

        $configuredTimeout = Config::get('lara-orcest.tools.process_timeout');
        $this->timeout = $configuredTimeout ? (int) $configuredTimeout : $timeout;

        $this->dryRun = $dryRun;
    }

    public function getName(): string
    {
        return 'test_runner';
    }

    public function getDescription(): string
    {
        return 'Executes automated Laravel test suites (php artisan test) safely via Process with structured failure analysis.';
    }

    public function setDryRun(bool $dryRun): self
    {
        $this->dryRun = $dryRun;

        return $this;
    }

    public function isDryRun(): bool
    {
        return $this->dryRun;
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
                'testsuite' => [
                    'type' => 'string',
                    'description' => 'Optional testsuite to run (e.g. Unit or Feature).',
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
                'timed_out' => false,
                'dry_run' => true,
            ];
        }

        $command = $this->baseCommand;

        if (! empty($params['filter'])) {
            $filter = preg_replace('/[^a-zA-Z0-9_:\-]/', '', (string) $params['filter']);
            $command[] = "--filter={$filter}";
        }

        if (! empty($params['testsuite'])) {
            $suite = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $params['testsuite']);
            $command[] = "--testsuite={$suite}";
        }

        if (! empty($params['path'])) {
            $path = preg_replace('/[^a-zA-Z0-9_\-\/\.]/', '', (string) $params['path']);
            $command[] = $path;
        }

        $startTime = microtime(true);
        $process = new Process($command, $this->projectRoot, timeout: $this->timeout);
        $timedOut = false;

        try {
            $process->run();
            $duration = round(microtime(true) - $startTime, 2);
            $exitCode = $process->getExitCode() ?? 1;
            $output = $process->getOutput() . $process->getErrorOutput();
            $passed = $process->isSuccessful();
        } catch (\Symfony\Component\Process\Exception\ProcessTimedOutException $e) {
            $duration = round(microtime(true) - $startTime, 2);
            $exitCode = 124;
            $output = "Test execution exceeded timeout of {$this->timeout} seconds.";
            $passed = false;
            $timedOut = true;
        } catch (\Throwable $e) {
            $duration = round(microtime(true) - $startTime, 2);
            $exitCode = 1;
            $output = "Test execution aborted: " . $e->getMessage();
            $passed = false;
        }

        $summary = $this->extractSummary($output, $passed, $timedOut);
        $failures = $this->extractFailures($output);

        return [
            'passed' => $passed,
            'exit_code' => $exitCode,
            'duration' => $duration,
            'summary' => $summary,
            'output' => mb_substr($output, 0, 10000),
            'failures' => $failures,
            'timed_out' => $timedOut,
            'dry_run' => false,
        ];
    }

    protected function extractSummary(string $output, bool $passed, bool $timedOut = false): string
    {
        if ($timedOut) {
            return "Test execution timed out after {$this->timeout}s.";
        }

        if (preg_match('/Tests:\s+([^\n\r]+)/', $output, $matches)) {
            return trim($matches[0]);
        }

        if (preg_match('/OK\s+\([^\n\r]+\)/', $output, $matches)) {
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
            $trimmed = trim($line);
            if (
                str_contains($trimmed, 'FAIL') ||
                str_contains($trimmed, 'FAILED') ||
                str_contains($trimmed, 'Error:') ||
                str_starts_with($trimmed, '1)') ||
                str_starts_with($trimmed, '2)') ||
                str_starts_with($trimmed, '3)')
            ) {
                if ($trimmed !== '' && ! in_array($trimmed, $failures, true)) {
                    $failures[] = $trimmed;
                }
            }
        }

        return array_slice($failures, 0, 10);
    }
}
