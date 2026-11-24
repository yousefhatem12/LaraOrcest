<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tools;

use InvalidArgumentException;
use RuntimeException;
use Yousef\LaraOrcest\Contracts\ToolContract;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Tools\Support\PathGuard;
use Yousef\LaraOrcest\Workspace\WorkspaceContext;

class PatchWriterTool implements ToolContract
{
    /**
     * @param  array<int, string>  $allowedPrefixes
     */
    public function __construct(
        protected ?PathGuard $pathGuard = null,
        protected array $allowedPrefixes = [],
        protected bool $dryRun = false,
        protected ?WorkspaceContext $workspaceContext = null
    ) {
        $this->pathGuard = $pathGuard ?: ($workspaceContext ? $workspaceContext->createPathGuard() : new PathGuard());
    }

    public function getPathGuard(): PathGuard
    {
        return $this->pathGuard;
    }

    public function setPathGuard(PathGuard $pathGuard): self
    {
        $this->pathGuard = $pathGuard;

        return $this;
    }

    public function getWorkspaceContext(): ?WorkspaceContext
    {
        return $this->workspaceContext;
    }

    public function setWorkspaceContext(WorkspaceContext $context): self
    {
        $this->workspaceContext = $context;
        $this->pathGuard = $context->createPathGuard();

        return $this;
    }

    public function getName(): string
    {
        return 'patch_writer';
    }

    public function getDescription(): string
    {
        return 'Safely creates, updates, search-and-replaces, or deletes allowed Laravel files within an approved directory scope.';
    }

    public function setAllowedPrefixes(array $prefixes): self
    {
        $this->allowedPrefixes = $prefixes;

        return $this;
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
                'path' => [
                    'type' => 'string',
                    'description' => 'Relative path of the file to create, patch, or delete.',
                ],
                'action' => [
                    'type' => 'string',
                    'enum' => ['write', 'replace', 'delete'],
                    'description' => 'Operation mode: write (full file), replace (targeted search-and-replace), delete (safe deletion).',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'Full file contents to write (for write action).',
                ],
                'target' => [
                    'type' => 'string',
                    'description' => 'Exact text snippet to replace in existing file (for replace action).',
                ],
                'replacement' => [
                    'type' => 'string',
                    'description' => 'Replacement text (for replace action).',
                ],
                'summary' => [
                    'type' => 'string',
                    'description' => 'Brief explanation of changes applied.',
                ],
            ],
            'required' => ['path'],
        ];
    }

    public function execute(array $params): array
    {
        if (empty($params['path'])) {
            throw new InvalidArgumentException("Missing 'path' parameter for patch_writer");
        }

        $rawPath = (string) $params['path'];
        $action = (string) ($params['action'] ?? (! empty($params['target']) ? 'replace' : 'write'));
        $summary = (string) ($params['summary'] ?? ($action === 'delete' ? 'Deleted file' : 'Updated file'));

        $safePath = $this->pathGuard->resolveSafePath($rawPath, $this->allowedPrefixes, mustExist: false);
        $relativePath = $this->pathGuard->getRelativePath($safePath);

        $exists = file_exists($safePath);
        $oldContent = $exists ? (file_get_contents($safePath) ?: '') : '';

        return match ($action) {
            'delete' => $this->handleDelete($safePath, $relativePath, $oldContent, $summary),
            'replace' => $this->handleReplace($safePath, $relativePath, $oldContent, $params, $summary),
            'write' => $this->handleWrite($safePath, $relativePath, $oldContent, $params, $summary, $exists),
            default => throw new InvalidArgumentException("Unsupported patch_writer action: [{$action}]"),
        };
    }

    /**
     * Executes the patch operation and automatically records the artifact in RunState if not in dry-run mode.
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

            if (! $state->isDryRun()) {
                $state = $state->recordArtifact(
                    $result['path'],
                    $result['action'],
                    $result['summary']
                );
            }

            return [
                'state' => $state,
                'result' => $result,
            ];
        } finally {
            $this->setDryRun($originalDryRun);
        }
    }

    protected function handleWrite(
        string $safePath,
        string $relativePath,
        string $oldContent,
        array $params,
        string $summary,
        bool $exists
    ): array {
        if (! isset($params['content'])) {
            throw new InvalidArgumentException("Missing 'content' parameter for write action");
        }

        $newContent = (string) $params['content'];
        $action = $exists ? 'updated' : 'created';
        $diff = $this->generateDiff($relativePath, $oldContent, $newContent);

        if (! $this->dryRun) {
            $this->writeContentAtomically($safePath, $newContent);
        }

        return [
            'path' => $relativePath,
            'action' => $action,
            'summary' => $summary,
            'diff' => $diff,
            'dry_run' => $this->dryRun,
            'bytes_written' => strlen($newContent),
        ];
    }

    protected function handleReplace(
        string $safePath,
        string $relativePath,
        string $oldContent,
        array $params,
        string $summary
    ): array {
        if (! file_exists($safePath)) {
            throw new RuntimeException("Cannot perform search-and-replace: file does not exist: [{$relativePath}]");
        }

        if (! isset($params['target'])) {
            throw new InvalidArgumentException("Missing 'target' parameter for replace action");
        }

        $target = (string) $params['target'];
        $replacement = (string) ($params['replacement'] ?? '');

        if ($target === '') {
            throw new InvalidArgumentException("Parameter 'target' cannot be empty for replace action");
        }

        if (! str_contains($oldContent, $target)) {
            throw new RuntimeException("Target string was not found in [{$relativePath}]");
        }

        $newContent = str_replace($target, $replacement, $oldContent);
        $diff = $this->generateDiff($relativePath, $oldContent, $newContent);

        if (! $this->dryRun) {
            $this->writeContentAtomically($safePath, $newContent);
        }

        return [
            'path' => $relativePath,
            'action' => 'updated',
            'summary' => $summary,
            'diff' => $diff,
            'dry_run' => $this->dryRun,
            'bytes_written' => strlen($newContent),
        ];
    }

    protected function handleDelete(
        string $safePath,
        string $relativePath,
        string $oldContent,
        string $summary
    ): array {
        if (! file_exists($safePath)) {
            return [
                'path' => $relativePath,
                'action' => 'deleted',
                'summary' => "File already did not exist: {$summary}",
                'diff' => '',
                'dry_run' => $this->dryRun,
                'bytes_written' => 0,
            ];
        }

        $diff = $this->generateDiff($relativePath, $oldContent, '');

        if (! $this->dryRun) {
            if (! unlink($safePath)) {
                throw new RuntimeException("Failed to delete file: [{$safePath}]");
            }
        }

        return [
            'path' => $relativePath,
            'action' => 'deleted',
            'summary' => $summary,
            'diff' => $diff,
            'dry_run' => $this->dryRun,
            'bytes_written' => 0,
        ];
    }

    protected function writeContentAtomically(string $safePath, string $content): void
    {
        $dir = dirname($safePath);
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException("Failed to create parent directory: [{$dir}]");
        }

        $tempPath = $safePath . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($tempPath, $content, LOCK_EX) === false) {
            throw new RuntimeException("Failed to write temporary file: [{$tempPath}]");
        }

        if (! rename($tempPath, $safePath)) {
            @unlink($tempPath);
            throw new RuntimeException("Failed to atomically commit file: [{$safePath}]");
        }
    }

    protected function generateDiff(string $filename, string $old, string $new): string
    {
        $oldLines = $old === '' ? [] : explode("\n", str_replace("\r\n", "\n", $old));
        $newLines = $new === '' ? [] : explode("\n", str_replace("\r\n", "\n", $new));

        $diff = ["--- a/{$filename}", "+++ b/{$filename}"];

        if (empty($oldLines) && ! empty($newLines)) {
            $diff[] = '@@ -0,0 +1,' . count($newLines) . ' @@';
            foreach ($newLines as $line) {
                $diff[] = '+' . $line;
            }

            return implode("\n", $diff);
        }

        if (! empty($oldLines) && empty($newLines)) {
            $diff[] = '@@ -1,' . count($oldLines) . ' +0,0 @@';
            foreach ($oldLines as $line) {
                $diff[] = '-' . $line;
            }

            return implode("\n", $diff);
        }

        $diff[] = '@@ -1,' . count($oldLines) . ' +1,' . count($newLines) . ' @@';
        foreach ($oldLines as $line) {
            $diff[] = '-' . $line;
        }
        foreach ($newLines as $line) {
            $diff[] = '+' . $line;
        }

        return implode("\n", $diff);
    }
}
