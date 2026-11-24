<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tools;

use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Process;
use Yousef\LaraOrcest\Contracts\ToolContract;
use Yousef\LaraOrcest\Support\Config;
use Yousef\LaraOrcest\Workspace\WorkspaceContext;

class ArtisanRunnerTool implements ToolContract
{
    /**
     * @var array<int, string>
     */
    protected array $allowedCommands;

    protected string $projectRoot;

    protected int $timeout;

    protected bool $dryRun;

    protected ?WorkspaceContext $workspaceContext = null;

    /**
     * Dangerous flags that are strictly forbidden.
     *
     * @var array<int, string>
     */
    protected array $blockedFlags = [
        '--force',
        '--drop-views',
        '--drop-types',
        '--seed',
    ];

    /**
     * @param  array<int, string>|null  $allowedCommands
     */
    public function __construct(
        ?string $projectRoot = null,
        ?array $allowedCommands = null,
        int $timeout = 120,
        bool $dryRun = false,
        ?WorkspaceContext $workspaceContext = null
    ) {
        $this->workspaceContext = $workspaceContext;
        $this->projectRoot = $projectRoot ?: ($workspaceContext?->getTargetProjectPath() ?: Config::basePath());

        $defaultCommands = [
            'make:migration',
            'make:model',
            'make:controller',
            'make:request',
            'make:test',
            'make:factory',
            'make:seeder',
            'make:resource',
            'make:policy',
            'make:rule',
            'make:class',
            'make:enum',
            'make:interface',
            'make:trait',
            'route:list',
            'migrate:status',
            'test',
        ];

        $configuredCommands = $workspaceContext?->getConfig()->get(
            'tools.allowed_artisan_commands',
            $workspaceContext?->getConfig()->get('sandbox.allowed_artisan_commands')
        ) ?: Config::get(
            'lara-orcest.tools.allowed_artisan_commands',
            Config::get('lara-orcest.sandbox.allowed_artisan_commands')
        );

        $this->allowedCommands = $allowedCommands ?: (array) ($configuredCommands ?: $defaultCommands);

        $configuredTimeout = $workspaceContext?->getConfig()->get('tools.process_timeout')
            ?: Config::get('lara-orcest.tools.process_timeout');
        $this->timeout = $configuredTimeout ? (int) $configuredTimeout : $timeout;

        $this->dryRun = $dryRun;
    }

    public function getName(): string
    {
        return 'artisan_runner';
    }

    public function getDescription(): string
    {
        return 'Executes explicitly allowed Artisan commands safely inside the project using Process isolation.';
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

    public function getWorkspaceContext(): ?WorkspaceContext
    {
        return $this->workspaceContext;
    }

    public function setWorkspaceContext(WorkspaceContext $context): self
    {
        $this->workspaceContext = $context;
        $this->projectRoot = $context->getTargetProjectPath();

        return $this;
    }

    public function getProjectRoot(): string
    {
        return $this->projectRoot;
    }

    public function setProjectRoot(string $projectRoot): self
    {
        $this->projectRoot = $projectRoot;

        return $this;
    }

    public function getArtisanBinaryPath(): string
    {
        return $this->projectRoot . DIRECTORY_SEPARATOR . 'artisan';
    }

    public function hasArtisanBinary(): bool
    {
        return file_exists($this->getArtisanBinaryPath());
    }

    public function validateArtisanBinary(): void
    {
        if (! $this->hasArtisanBinary()) {
            throw new RuntimeException("Artisan binary not found at [{$this->getArtisanBinaryPath()}]. Target path must be an initialized Laravel project.");
        }
    }

    public function getAllowedCommands(): array
    {
        return $this->allowedCommands;
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'command' => [
                    'type' => 'string',
                    'description' => 'The allow-listed Artisan command name (e.g. make:model, route:list).',
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
        $arguments = array_values((array) ($params['arguments'] ?? []));

        // Validate command name
        $this->validateCommandName($command);

        // Disallow shell operators & dangerous flags in arguments
        foreach ($arguments as $arg) {
            $this->validateArgument((string) $arg);
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

        if (! $this->hasArtisanBinary()) {
            return [
                'command' => $command,
                'arguments' => $arguments,
                'exit_code' => 127,
                'output' => "Artisan binary not found at [{$this->getArtisanBinaryPath()}]. Target path must be an initialized Laravel project.",
                'successful' => false,
                'dry_run' => false,
            ];
        }

        $cmdArray = [PHP_BINARY, 'artisan', $command, ...$arguments];
        $process = new Process($cmdArray, $this->projectRoot, timeout: $this->timeout);

        try {
            $process->run();
            $exitCode = $process->getExitCode() ?? 1;
            $output = mb_substr($process->getOutput() . $process->getErrorOutput(), 0, 10000);
            $successful = $process->isSuccessful();
        } catch (\Throwable $e) {
            $exitCode = 1;
            $output = "Artisan execution failed: " . $e->getMessage();
            $successful = false;
        }

        return [
            'command' => $command,
            'arguments' => $arguments,
            'exit_code' => $exitCode,
            'output' => $output,
            'successful' => $successful,
            'dry_run' => false,
        ];
    }

    protected function validateCommandName(string $command): void
    {
        if (! preg_match('/^[a-zA-Z0-9_\-:]+$/', $command)) {
            throw new RuntimeException("Invalid Artisan command format: [{$command}]");
        }
    }

    protected function validateArgument(string $value): void
    {
        // Disallow null bytes
        if (str_contains($value, "\0")) {
            throw new RuntimeException("Argument contains null bytes: [{$value}]");
        }

        // Disallow characters used for shell chaining, redirection, or command interpolation
        if (preg_match('/[|;&`$<>\n\r]/', $value)) {
            throw new RuntimeException("Shell metacharacters detected in argument: [{$value}]");
        }

        // Disallow blocked dangerous flags
        foreach ($this->blockedFlags as $flag) {
            if ($value === $flag || str_starts_with($value, $flag . '=')) {
                throw new RuntimeException("Dangerous flag [{$flag}] is strictly blocked for Artisan execution.");
            }
        }
    }
}
