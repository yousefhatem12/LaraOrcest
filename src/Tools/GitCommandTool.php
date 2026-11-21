<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tools;

use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Process;
use Yousef\LaraOrcest\Contracts\ToolContract;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Support\Config;
use Yousef\LaraOrcest\Tools\Support\PathGuard;

class GitCommandTool implements ToolContract
{
    /**
     * @var array<int, string>
     */
    protected array $protectedBranches;

    protected string $projectRoot;

    protected bool $dryRun;

    protected PathGuard $pathGuard;

    /**
     * @var array<int, string>
     */
    protected array $blockedPatterns = [
        'push --force',
        'push -f',
        'reset --hard',
        'clean -f',
        'clean -fd',
        'clean -xdf',
    ];

    /**
     * @param  array<int, string>|null  $protectedBranches
     */
    public function __construct(
        ?string $projectRoot = null,
        ?array $protectedBranches = null,
        bool $dryRun = false,
        ?PathGuard $pathGuard = null
    ) {
        $this->projectRoot = $projectRoot ?: Config::basePath();
        $this->protectedBranches = $protectedBranches ?: (array) Config::get('lara-orcest.git.protected_branches', ['main', 'master', 'production', 'staging']);
        $this->dryRun = $dryRun;
        $this->pathGuard = $pathGuard ?: new PathGuard($this->projectRoot);
    }

    public function getName(): string
    {
        return 'git_command';
    }

    public function getDescription(): string
    {
        return 'Executes safe, restricted Git operations for branch isolation and milestone commits without exposing secrets.';
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
                'action' => [
                    'type' => 'string',
                    'enum' => ['status', 'current_branch', 'branch', 'create_branch', 'diff', 'stage_files', 'add', 'commit'],
                    'description' => 'Safe Git operation to execute.',
                ],
                'branch' => [
                    'type' => 'string',
                    'description' => 'Branch name for create_branch action.',
                ],
                'files' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Specific file paths to stage in stage_files or add action.',
                ],
                'message' => [
                    'type' => 'string',
                    'description' => 'Commit message for commit action.',
                ],
                'allow_dirty' => [
                    'type' => 'boolean',
                    'description' => 'Whether to permit branch creation when untracked files are present (default false).',
                ],
            ],
            'required' => ['action'],
        ];
    }

    public function execute(array $params): array
    {
        $action = (string) ($params['action'] ?? '');

        // Security check against blocked destructive operations
        foreach ($params as $paramValue) {
            if (is_string($paramValue)) {
                $this->validateNoBlockedOperations($paramValue);
            }
        }

        return match ($action) {
            'status' => $this->getStatus(),
            'current_branch' => $this->getCurrentBranch(),
            'branch' => $this->listBranches(),
            'create_branch' => $this->createBranch(
                (string) ($params['branch'] ?? ''),
                (bool) ($params['allow_dirty'] ?? false)
            ),
            'diff' => $this->getDiff(),
            'stage_files', 'add' => $this->stageFiles((array) ($params['files'] ?? [])),
            'commit' => $this->commit((string) ($params['message'] ?? '')),
            default => throw new InvalidArgumentException("Unsupported Git action: [{$action}]"),
        };
    }

    /**
     * Executes the Git operation and updates the RunState git_state cleanly.
     *
     * @param  array<string, mixed>  $params
     * @return array{state: RunState, result: array<string, mixed>}
     */
    public function applyToState(RunState $state, array $params): array
    {
        $originalDryRun = $this->dryRun;
        $this->setDryRun($state->isDryRun());

        try {
            $result = $this->execute($params);

            $gitState = $state->gitState;
            $action = (string) ($params['action'] ?? '');

            if ($action === 'create_branch') {
                $gitState['feature_branch'] = $result['branch'];
                $gitState['branch_created'] = $result['created'];
            } elseif ($action === 'commit' && ! empty($result['commit_hash'])) {
                $commits = $gitState['commits'] ?? [];
                $commits[] = $result;
                $gitState['commits'] = $commits;
            }

            if (isset($result['clean'])) {
                $gitState['status'] = $result;
            }

            $state = $state->withGitState($gitState);

            return [
                'state' => $state,
                'result' => $result,
            ];
        } finally {
            $this->setDryRun($originalDryRun);
        }
    }

    public function getStatus(): array
    {
        if ($this->dryRun) {
            return [
                'clean' => true,
                'branch' => 'dry-run-branch',
                'modified' => [],
                'untracked' => [],
                'dry_run' => true,
            ];
        }

        $process = $this->runGit(['status', '--porcelain', '-b']);
        $lines = explode("\n", trim($process->getOutput()));

        $branchLine = array_shift($lines) ?: '## unknown';
        $currentBranch = str_replace(['## ', '...'], ' ', $branchLine);
        $currentBranch = explode(' ', trim($currentBranch))[0];

        $modified = [];
        $untracked = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $status = substr($line, 0, 2);
            $file = trim(substr($line, 2));

            if (str_starts_with($status, '?')) {
                $untracked[] = $file;
            } else {
                $modified[] = $file;
            }
        }

        return [
            'clean' => empty($modified) && empty($untracked),
            'branch' => $currentBranch,
            'modified' => $modified,
            'untracked' => $untracked,
            'dry_run' => false,
        ];
    }

    public function getCurrentBranch(): array
    {
        if ($this->dryRun) {
            return ['branch' => 'dry-run-branch', 'dry_run' => true];
        }

        $process = $this->runGit(['rev-parse', '--abbrev-ref', 'HEAD']);
        $branch = trim($process->getOutput());

        return ['branch' => $branch, 'dry_run' => false];
    }

    public function listBranches(): array
    {
        if ($this->dryRun) {
            return [
                'branches' => ['main', 'dry-run-branch'],
                'current' => 'dry-run-branch',
                'dry_run' => true,
            ];
        }

        $process = $this->runGit(['branch', '--list']);
        $lines = explode("\n", trim($process->getOutput()));
        $branches = [];
        $current = 'unknown';

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (str_starts_with($line, '* ')) {
                $name = substr($line, 2);
                $current = $name;
                $branches[] = $name;
            } else {
                $branches[] = $line;
            }
        }

        return [
            'branches' => $branches,
            'current' => $current,
            'dry_run' => false,
        ];
    }

    public function createBranch(string $branchName, bool $allowDirty = false): array
    {
        return $this->withGitLock(function () use ($branchName, $allowDirty) {
            $branchName = trim($branchName);
            if ($branchName === '') {
                throw new InvalidArgumentException('Branch name cannot be empty');
            }

            if (! preg_match('/^[a-zA-Z0-9_\-\/]+$/', $branchName)) {
                throw new RuntimeException("Invalid branch name format: [{$branchName}]");
            }

            if (in_array(strtolower($branchName), $this->protectedBranches, true)) {
                throw new RuntimeException("Direct checkout/creation of protected branch [{$branchName}] is forbidden");
            }

            if ($this->dryRun) {
                return [
                    'branch' => $branchName,
                    'created' => true,
                    'dry_run' => true,
                ];
            }

            $status = $this->getStatus();
            if (! $status['clean'] && ! $allowDirty) {
                throw new RuntimeException('Cannot create feature branch: Git working tree is dirty. Stash or commit existing changes first.');
            }

            $check = $this->runGit(['rev-parse', '--verify', 'refs/heads/' . $branchName], false);
            if ($check->isSuccessful()) {
                $this->runGit(['checkout', $branchName]);
                $created = false;
            } else {
                $this->runGit(['checkout', '-b', $branchName]);
                $created = true;
            }

            return [
                'branch' => $branchName,
                'created' => $created,
                'dry_run' => false,
            ];
        });
    }

    public function getDiff(): array
    {
        if ($this->dryRun) {
            return ['diff' => '[DRY-RUN] No disk diff available', 'dry_run' => true];
        }

        $process = $this->runGit(['diff', 'HEAD']);

        return [
            'diff' => mb_substr($this->redactCredentials($process->getOutput()), 0, 10000),
            'dry_run' => false,
        ];
    }

    public function stageFiles(array $files): array
    {
        return $this->withGitLock(function () use ($files) {
            if (empty($files)) {
                return ['staged' => [], 'dry_run' => $this->dryRun];
            }

            $safeFiles = [];
            foreach ($files as $file) {
                $raw = (string) $file;
                $safePath = $this->pathGuard->resolveSafePath($raw, mustExist: false);
                $safeFiles[] = $this->pathGuard->getRelativePath($safePath);
            }

            if ($this->dryRun) {
                return [
                    'staged' => $safeFiles,
                    'dry_run' => true,
                ];
            }

            $this->runGit(['add', '--', ...$safeFiles]);

            return [
                'staged' => $safeFiles,
                'dry_run' => false,
            ];
        });
    }

    public function commit(string $message): array
    {
        return $this->withGitLock(function () use ($message) {
            $message = trim($message);
            if ($message === '') {
                throw new InvalidArgumentException('Commit message cannot be empty');
            }

            $current = $this->getCurrentBranch();
            if (in_array(strtolower($current['branch']), $this->protectedBranches, true)) {
                throw new RuntimeException("Committing directly to protected branch [{$current['branch']}] is forbidden. Create an isolated feature branch first.");
            }

            if ($this->dryRun) {
                return [
                    'committed' => true,
                    'message' => $message,
                    'commit_hash' => 'dry-run-hash',
                    'dry_run' => true,
                ];
            }

            $this->runGit(['commit', '-m', $message]);
            $hashProcess = $this->runGit(['rev-parse', '--short', 'HEAD']);

            return [
                'committed' => true,
                'message' => $message,
                'commit_hash' => trim($hashProcess->getOutput()),
                'dry_run' => false,
            ];
        });
    }

    /**
     * Serializes mutating Git operations using an exclusive file lock on the repository.
     */
    protected function withGitLock(callable $operation): mixed
    {
        $gitDir = $this->projectRoot . '/.git';
        if (! is_dir($gitDir)) {
            return $operation();
        }

        $lockFile = $gitDir . '/lara_orcest_git.lock';
        $handle = @fopen($lockFile, 'c+');
        if (! $handle) {
            return $operation();
        }

        try {
            flock($handle, LOCK_EX);

            return $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    protected function validateNoBlockedOperations(string $value): void
    {
        foreach ($this->blockedPatterns as $pattern) {
            if (stripos($value, $pattern) !== false) {
                throw new RuntimeException("Dangerous Git operation [{$pattern}] is strictly blocked.");
            }
        }
    }

    protected function redactCredentials(string $text): string
    {
        return preg_replace('/https?:\/\/[^@\s]+@/', 'https://[REDACTED_CREDENTIALS]@', $text) ?? $text;
    }

    protected function runGit(array $arguments, bool $throwOnError = true): Process
    {
        // Check arguments against blocked dangerous patterns
        foreach ($arguments as $arg) {
            $this->validateNoBlockedOperations((string) $arg);
        }

        $process = new Process(['git', ...$arguments], $this->projectRoot);
        $process->run();

        if ($throwOnError && ! $process->isSuccessful()) {
            $err = trim($process->getErrorOutput() ?: $process->getOutput());
            $sanitizedErr = $this->redactCredentials($err);
            throw new RuntimeException("Git command [git " . implode(' ', $arguments) . "] failed: {$sanitizedErr}");
        }

        return $process;
    }
}
