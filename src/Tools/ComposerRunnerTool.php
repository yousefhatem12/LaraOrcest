<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tools;

use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Process;
use Yousef\LaraOrcest\Contracts\ToolContract;
use Yousef\LaraOrcest\Support\Config;
use Yousef\LaraOrcest\Tools\Support\PathGuard;
use Yousef\LaraOrcest\Workspace\WorkspaceContext;

class ComposerRunnerTool implements ToolContract
{
    /**
     * @var array<int, string>
     */
    protected array $allowedCommands;

    protected string $projectRoot;

    protected int $timeout;

    protected bool $dryRun;

    protected ?WorkspaceContext $workspaceContext = null;

    protected PathGuard $pathGuard;

    /**
     * Dangerous flags that are strictly blocked.
     *
     * @var array<int, string>
     */
    protected array $blockedFlags = [
        '--system',
    ];

    /**
     * @param  array<int, string>|null  $allowedCommands
     */
    public function __construct(
        ?string $projectRoot = null,
        ?array $allowedCommands = null,
        int $timeout = 120,
        bool $dryRun = false,
        ?WorkspaceContext $workspaceContext = null,
        ?PathGuard $pathGuard = null
    ) {
        $this->workspaceContext = $workspaceContext;
        $this->projectRoot = $projectRoot ?: ($workspaceContext?->getTargetProjectPath() ?: Config::basePath());
        $this->pathGuard = $pathGuard ?: ($workspaceContext ? $workspaceContext->createPathGuard() : new PathGuard($this->projectRoot));

        $defaultCommands = [
            'validate',
            'show',
            'audit',
            'dump-autoload',
            'dumpautoload',
            'diagnose',
            'check-platform-reqs',
            'install',
            'require',
            'create-project',
            'remove',
            'update',
        ];

        $configuredCommands = $workspaceContext?->getConfig()->get('tools.allowed_composer_commands')
            ?: Config::get('lara-orcest.tools.allowed_composer_commands');

        $this->allowedCommands = $allowedCommands ?: (array) ($configuredCommands ?: $defaultCommands);

        $configuredTimeout = $workspaceContext?->getConfig()->get('tools.process_timeout')
            ?: Config::get('lara-orcest.tools.process_timeout');
        $this->timeout = $configuredTimeout ? (int) $configuredTimeout : $timeout;

        $this->dryRun = $dryRun;
    }

    public function getName(): string
    {
        return 'composer_runner';
    }

    public function getDescription(): string
    {
        return 'Executes safe, allow-listed Composer commands inside the target project workspace using Process isolation.';
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

    public function getAllowedCommands(): array
    {
        return $this->allowedCommands;
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

    public function getWorkspaceContext(): ?WorkspaceContext
    {
        return $this->workspaceContext;
    }

    public function setWorkspaceContext(WorkspaceContext $context): self
    {
        $this->workspaceContext = $context;
        $this->projectRoot = $context->getTargetProjectPath();
        $this->pathGuard = $context->createPathGuard();

        return $this;
    }

    public function getPathGuard(): PathGuard
    {
        return $this->pathGuard;
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'command' => [
                    'type' => 'string',
                    'description' => 'The allow-listed Composer command (e.g. dump-autoload, validate, show, create-project, require).',
                ],
                'arguments' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'List of arguments and options passed to Composer.',
                ],
            ],
            'required' => ['command'],
        ];
    }

    public function execute(array $params): array
    {
        if (empty($params['command'])) {
            throw new InvalidArgumentException("Missing 'command' parameter for composer_runner");
        }

        $command = trim((string) $params['command']);
        $arguments = array_values((array) ($params['arguments'] ?? []));

        $this->validateCommandName($command);

        foreach ($arguments as $arg) {
            $this->validateArgument((string) $arg);
        }

        if (! in_array($command, $this->allowedCommands, true)) {
            $allowed = implode(', ', $this->allowedCommands);
            throw new RuntimeException("Composer command [{$command}] is not allowed. Approved commands: [{$allowed}]");
        }

        if ($this->dryRun) {
            return [
                'command' => $command,
                'arguments' => $arguments,
                'exit_code' => 0,
                'output' => $this->redactSecrets("[DRY-RUN] Simulated execution: composer {$command} " . implode(' ', $arguments)),
                'successful' => true,
                'dry_run' => true,
            ];
        }

        $cmdArray = ['composer', $command, ...$arguments];
        $process = new Process($cmdArray, $this->projectRoot, timeout: $this->timeout);

        try {
            $process->run();
            $exitCode = $process->getExitCode() ?? 1;
            $rawOutput = mb_substr($process->getOutput() . $process->getErrorOutput(), 0, 10000);
            $output = $this->redactSecrets($rawOutput);
            $successful = $process->isSuccessful();
        } catch (\Throwable $e) {
            $exitCode = 1;
            $output = $this->redactSecrets("Composer execution failed: " . $e->getMessage());
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

    public function redactSecrets(string $text): string
    {
        $text = preg_replace('/https?:\/\/[^@\s]+@/', 'https://[REDACTED_CREDENTIALS]@', $text) ?? $text;
        $text = preg_replace('/(sk-[a-zA-Z0-9]{20,})/i', '[REDACTED_API_KEY]', $text) ?? $text;
        $text = preg_replace('/(bearer\s+[a-zA-Z0-9\-\._~\+\/]+=*)/i', 'Bearer [REDACTED_TOKEN]', $text) ?? $text;
        $text = preg_replace('/(api[_-]?key["\']?\s*[:=]\s*["\']?)([^"\'\s]+)/i', '$1[REDACTED]', $text) ?? $text;
        $text = preg_replace('/(APP_KEY\s*=\s*)([^\s\n]+)/i', '$1[REDACTED_APP_KEY]', $text) ?? $text;
        $text = preg_replace('/(DB_PASSWORD\s*=\s*)([^\s\n]+)/i', '$1[REDACTED_PASSWORD]', $text) ?? $text;

        return $text;
    }

    protected function validateCommandName(string $command): void
    {
        if (! preg_match('/^[a-zA-Z0-9_\-]+$/', $command)) {
            throw new RuntimeException("Invalid Composer command format: [{$command}]");
        }
    }

    protected function validateArgument(string $value): void
    {
        if (str_contains($value, "\0")) {
            throw new RuntimeException("Argument contains null bytes: [{$value}]");
        }

        if (preg_match('/[|;&`$<>\n\r]/', $value)) {
            throw new RuntimeException("Shell metacharacters detected in argument: [{$value}]");
        }

        foreach ($this->blockedFlags as $flag) {
            if ($value === $flag || str_starts_with($value, $flag . '=')) {
                throw new RuntimeException("Dangerous flag [{$flag}] is strictly blocked for Composer execution.");
            }
        }

        // Validate working-dir does not escape PathGuard
        if (str_starts_with($value, '--working-dir=')) {
            $dir = substr($value, strlen('--working-dir='));
            $this->pathGuard->resolveSafeDirectory($dir, mustExist: false);
        }
    }
}
