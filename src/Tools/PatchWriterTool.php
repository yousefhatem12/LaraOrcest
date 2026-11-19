<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tools;

use InvalidArgumentException;
use RuntimeException;
use Yousef\LaraOrcest\Contracts\ToolContract;
use Yousef\LaraOrcest\Tools\Support\PathGuard;

class PatchWriterTool implements ToolContract
{
    /**
     * @param  array<int, string>  $allowedPrefixes
     */
    public function __construct(
        protected ?PathGuard $pathGuard = null,
        protected array $allowedPrefixes = [],
        protected bool $dryRun = false
    ) {
        $this->pathGuard = $pathGuard ?: new PathGuard();
    }

    public function getName(): string
    {
        return 'patch_writer';
    }

    public function getDescription(): string
    {
        return 'Safely creates or updates allowed Laravel files within an approved directory scope.';
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

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => 'Relative path of the file to create or update.',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'Full file contents to write.',
                ],
                'summary' => [
                    'type' => 'string',
                    'description' => 'Brief explanation of changes applied.',
                ],
            ],
            'required' => ['path', 'content'],
        ];
    }

    public function execute(array $params): array
    {
        if (empty($params['path'])) {
            throw new InvalidArgumentException("Missing 'path' parameter for patch_writer");
        }

        if (! isset($params['content'])) {
            throw new InvalidArgumentException("Missing 'content' parameter for patch_writer");
        }

        $rawPath = (string) $params['path'];
        $newContent = (string) $params['content'];
        $summary = (string) ($params['summary'] ?? 'Updated file');

        $safePath = $this->pathGuard->resolveSafePath($rawPath, $this->allowedPrefixes, mustExist: false);
        $relativePath = $this->pathGuard->getRelativePath($safePath);

        $exists = file_exists($safePath);
        $action = $exists ? 'updated' : 'created';
        $oldContent = $exists ? (file_get_contents($safePath) ?: '') : '';

        $diff = $this->generateDiff($relativePath, $oldContent, $newContent);

        if (! $this->dryRun) {
            $dir = dirname($safePath);
            if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
                throw new RuntimeException("Failed to create parent directory: [{$dir}]");
            }

            $tempPath = $safePath . '.' . bin2hex(random_bytes(6)) . '.tmp';
            if (file_put_contents($tempPath, $newContent, LOCK_EX) === false) {
                throw new RuntimeException("Failed to write temporary file: [{$tempPath}]");
            }

            if (! rename($tempPath, $safePath)) {
                @unlink($tempPath);
                throw new RuntimeException("Failed to atomically commit file: [{$safePath}]");
            }
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

    protected function generateDiff(string $filename, string $old, string $new): string
    {
        $oldLines = explode("\n", str_replace("\r\n", "\n", $old));
        $newLines = explode("\n", str_replace("\r\n", "\n", $new));

        $diff = ["--- a/{$filename}", "+++ b/{$filename}"];

        if ($old === '') {
            $diff[] = '@@ -0,0 +1,' . count($newLines) . ' @@';
            foreach ($newLines as $line) {
                $diff[] = '+' . $line;
            }

            return implode("\n", $diff);
        }

        // Minimal unified diff representation
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
