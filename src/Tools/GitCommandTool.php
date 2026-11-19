<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tools;

use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Process;
use Yousef\LaraOrcest\Contracts\ToolContract;
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
        return 'Executes safe, restricted Git operations for branch isolation and milestone commits.';
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
                'action' => [
                    'type' => 'string',
                    'enum' => ['status', 'current_branch', 'create_branch', 'diff', 'stage_files', 'commit'],
                    'description' => 'Safe Git operation to execute.',
                ],
                'branch' => [
                    'type' => 'string',
                    'description' => 'Branch name for create_branch action.',
                ],
                'files' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Specific file paths to stage in stage_files action.',
                ],
                'message' => [
                    'type' => 'string',
                    'description' => 'Commit message for commit action.',
                ],
            ],
            'required' => ['action'],
        ];
    }

    public function execute(array $params): array
    {
        $action = (string) ($params['action'] ?? '');

        return match ($action) {
            'status' => $this->getStatus(),
            'current_branch' => $this->getCurrentBranch(),
            'create_branch' => $this->createBranch((string) ($params['branch'] ?? '')),
            'diff' => $this->getDiff(),
            'stage_files' => $this->stageFiles((array) ($params['files'] ?? [])),
            'commit' => $this->commit((string) ($params['message'] ?? '')),
            default => throw new InvalidArgumentException("Unsupported Git action: [{$action}]"),
        };
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

    public function createBranch(string $branchName): array
    {
        $branchName = trim($branchName);
        if ($branchName === '') {
            throw new InvalidArgumentException('Branch name cannot be empty');
        }

        // Validate branch name against safe naming rules
        if (! preg_match('/^[a-zA-Z0-9_\-\/]+$/', $branchName)) {
            throw new RuntimeException("Invalid branch name format: [{$branchName}]");
        }

        // Disallow creating or switching directly to protected branch name
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

        // Refuse to create a new branch if working tree has untracked or modified files
        $status = $this->getStatus();
        if (! $status['clean']) {
            throw new RuntimeException('Cannot create feature branch: Git working tree is dirty. Stash or commit existing changes first.');
        }

        // Check if branch already exists
        $check = $this->runGit(['rev-parse', '--verify', 'refs/heads/' . $branchName], false);
        if ($check->isSuccessful()) {
            // Checkout existing branch
            $this->runGit(['checkout', $branchName]);
            $created = false;
        } else {
            // Create and checkout new branch
            $this->runGit(['checkout', '-b', $branchName]);
            $created = true;
        }

        return [
            'branch' => $branchName,
            'created' => $created,
            'dry_run' => false,
        ];
    }

    public function getDiff(): array
    {
        if ($this->dryRun) {
            return ['diff' => '[DRY-RUN] No disk diff available', 'dry_run' => true];
        }

        $process = $this->runGit(['diff', 'HEAD']);

        return [
            'diff' => mb_substr($process->getOutput(), 0, 10000),
            'dry_run' => false,
        ];
    }

    public function stageFiles(array $files): array
    {
        if (empty($files)) {
            return ['staged' => [], 'dry_run' => $this->dryRun];
        }

        $safeFiles = [];
        foreach ($files as $file) {
            $raw = (string) $file;
            // Verify path does not touch denied files (.env, etc.)
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
    }

    public function commit(string $message): array
    {
        $message = trim($message);
        if ($message === '') {
            throw new InvalidArgumentException('Commit message cannot be empty');
        }

        // Verify we are not committing directly to a protected branch
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
    }

    protected function runGit(array $arguments, bool $throwOnError = true): Process
    {
        $process = new Process(['git', ...$arguments], $this->projectRoot);
        $process->run();

        if ($throwOnError && ! $process->isSuccessful()) {
            $err = trim($process->getErrorOutput() ?: $process->getOutput());
            throw new RuntimeException("Git command [git " . implode(' ', $arguments) . "] failed: {$err}");
        }

        return $process;
    }
}
