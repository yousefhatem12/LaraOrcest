<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tools;

use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Process;
use Yousef\LaraOrcest\Contracts\ToolContract;
use Yousef\LaraOrcest\Support\Config;

class ArtisanRunnerTool implements ToolContract
{
    /**
     * @var array<int, string>
     */
    protected array $allowedCommands;

    protected string $projectRoot;

    protected int $timeout;

    protected bool $dryRun;

    /**
     * @param  array<int, string>|null  $allowedCommands
     */
    public function __construct(
        ?string $projectRoot = null,
        ?array $allowedCommands = null,
        int $timeout = 60,
        bool $dryRun = false
    ) {
        $this->projectRoot = $projectRoot ?: Config::basePath();
        $this->allowedCommands = $allowedCommands ?: (array) Config::get('lara-orcest.sandbox.allowed_artisan_commands', ['make:migration', 'make:model', 'make:controller', 'make:request', 'make:factory', 'make:seeder', 'make:resource', 'migrate:status']);
        $this->timeout = $timeout;
        $this->dryRun = $dryRun;
    }

    public function getName(): string
    {
        return 'artisan_runner';
    }

    public function getDescription(): string
    {
        return 'Executes explicitly allowed Artisan commands safely inside the project.';
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
                'command' => [
                    'type' => 'string',
                    'description' => 'The Artisan command name (e.g. make:model).',
                ],
                'arguments' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'List of arguments and options passed to the command.',
                ],
            ],
            'required' => ['command'],
        ];
    }

    public function execute(array $params): array
    {
        if (empty($params['command'])) {
            throw new InvalidArgumentException("Missing 'command' parameter for artisan_runner");
        }

        $command = trim((string) $params['command']);
        $arguments = (array) ($params['arguments'] ?? []);

        // Disallow shell operators in command name or arguments
        $this->validateNoShellMetacharacters($command);
        foreach ($arguments as $arg) {
            $this->validateNoShellMetacharacters((string) $arg);
        }

        // Verify command is in allowed list
        if (! in_array($command, $this->allowedCommands, true)) {
            $allowed = implode(', ', $this->allowedCommands);
            throw new RuntimeException("Artisan command [{$command}] is not allowed. Approved commands: [{$allowed}]");
        }

        if ($this->dryRun) {
            return [
                'command' => $command,
                'arguments' => $arguments,
                'exit_code' => 0,
                'output' => "[DRY-RUN] Simulated execution: php artisan {$command} " . implode(' ', $arguments),
                'successful' => true,
                'dry_run' => true,
            ];
        }

        $cmdArray = [PHP_BINARY, 'artisan', $command, ...$arguments];
        $process = new Process($cmdArray, $this->projectRoot, timeout: $this->timeout);
        $process->run();

        $output = mb_substr($process->getOutput() . $process->getErrorOutput(), 0, 10000);

        return [
            'command' => $command,
            'arguments' => $arguments,
            'exit_code' => $process->getExitCode() ?? 1,
            'output' => $output,
            'successful' => $process->isSuccessful(),
            'dry_run' => false,
        ];
    }

    protected function validateNoShellMetacharacters(string $value): void
    {
        // Disallow characters used for shell chaining or redirection
        if (preg_match('/[|;&`$<>\n\r]/', $value)) {
            throw new RuntimeException("Shell metacharacters detected in argument: [{$value}]");
        }
    }
}
