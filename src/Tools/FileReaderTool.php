<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tools;

use InvalidArgumentException;
use RuntimeException;
use Yousef\LaraOrcest\Contracts\ToolContract;
use Yousef\LaraOrcest\Tools\Support\PathGuard;

class FileReaderTool implements ToolContract
{
    public function __construct(
        protected ?PathGuard $pathGuard = null
    ) {
        $this->pathGuard = $pathGuard ?: new PathGuard();
    }

    public function getName(): string
    {
        return 'file_reader';
    }

    public function getDescription(): string
    {
        return 'Safely reads contents of a file within the Laravel project root.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => 'Relative or absolute path of the file to inspect.',
                ],
                'max_lines' => [
                    'type' => 'integer',
                    'description' => 'Maximum lines to return (default 500).',
                ],
                'offset' => [
                    'type' => 'integer',
                    'description' => 'Starting line offset (0-indexed, default 0).',
                ],
            ],
            'required' => ['path'],
        ];
    }

    public function execute(array $params): array
    {
        if (empty($params['path'])) {
            throw new InvalidArgumentException("Missing 'path' parameter for file_reader");
        }

        $safePath = $this->pathGuard->resolveSafePath((string) $params['path'], mustExist: true);

        if (! is_file($safePath)) {
            throw new RuntimeException("Target path is not a regular file: [{$safePath}]");
        }

        $size = filesize($safePath);
        if ($size > $this->pathGuard->getMaxFileSize()) {
            throw new RuntimeException("File exceeds maximum allowable size of {$this->pathGuard->getMaxFileSize()} bytes: [{$size}] bytes");
        }

        $lines = file($safePath, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new RuntimeException("Failed to read file: [{$safePath}]");
        }

        $totalLines = count($lines);
        $offset = max(0, (int) ($params['offset'] ?? 0));
        $maxLines = max(1, min(1000, (int) ($params['max_lines'] ?? 500)));

        $sliced = array_slice($lines, $offset, $maxLines);

        return [
            'path' => $this->pathGuard->getRelativePath($safePath),
            'total_lines' => $totalLines,
            'offset' => $offset,
            'returned_lines' => count($sliced),
            'content' => implode("\n", $sliced),
            'size' => $size,
        ];
    }
}
