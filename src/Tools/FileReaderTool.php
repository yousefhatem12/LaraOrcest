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
        return 'Safely reads file contents or lists/searches directory trees within the allowed Laravel project boundaries.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => 'Relative or absolute path of the file or directory to inspect.',
                ],
                'max_lines' => [
                    'type' => 'integer',
                    'description' => 'Maximum lines to return for files (default 500).',
                ],
                'offset' => [
                    'type' => 'integer',
                    'description' => 'Starting line offset (0-indexed, default 0).',
                ],
                'max_bytes' => [
                    'type' => 'integer',
                    'description' => 'Optional maximum bytes to return for file content.',
                ],
                'recursive' => [
                    'type' => 'boolean',
                    'description' => 'Whether to recursively list directory contents (default false).',
                ],
                'pattern' => [
                    'type' => 'string',
                    'description' => 'Optional glob pattern filter when listing directories (e.g. *.php).',
                ],
                'must_exist' => [
                    'type' => 'boolean',
                    'description' => 'Whether to throw an exception if the path does not exist (default false).',
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

        $rawPath = (string) $params['path'];
        $mustExist = (bool) ($params['must_exist'] ?? false);

        $safePath = $this->pathGuard->resolveSafePath($rawPath, mustExist: false);
        $relativePath = $this->pathGuard->getRelativePath($safePath);

        if (! file_exists($safePath)) {
            if ($mustExist) {
                throw new RuntimeException("Target path does not exist: [{$safePath}]");
            }

            return [
                'exists' => false,
                'path' => $relativePath,
                'content' => null,
                'entries' => [],
                'truncated' => false,
            ];
        }

        // Handle Directory Listing
        if (is_dir($safePath)) {
            return $this->inspectDirectory($safePath, $relativePath, $params);
        }

        // Handle File Reading
        return $this->readFile($safePath, $relativePath, $params);
    }

    protected function inspectDirectory(string $absolutePath, string $relativePath, array $params): array
    {
        $recursive = (bool) ($params['recursive'] ?? false);
        $pattern = ! empty($params['pattern']) ? (string) $params['pattern'] : null;
        $maxEntries = 500;

        $entries = [];
        $truncated = false;

        if ($recursive) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($absolutePath, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            $iterator->setMaxDepth(5);

            foreach ($iterator as $item) {
                $itemAbsPath = $item->getPathname();
                $itemRelPath = $this->pathGuard->getRelativePath($itemAbsPath);

                if ($this->pathGuard->isDenied($itemRelPath) || $this->pathGuard->isDenied($item->getFilename())) {
                    continue;
                }

                if ($pattern !== null && ! fnmatch($pattern, $item->getFilename())) {
                    continue;
                }

                $entries[] = [
                    'path' => $itemRelPath,
                    'type' => $item->isDir() ? 'directory' : 'file',
                    'size' => $item->isFile() ? $item->getSize() : null,
                ];

                if (count($entries) >= $maxEntries) {
                    $truncated = true;
                    break;
                }
            }
        } else {
            $files = scandir($absolutePath) ?: [];
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }

                $itemAbsPath = $absolutePath . DIRECTORY_SEPARATOR . $file;
                $itemRelPath = $this->pathGuard->getRelativePath($itemAbsPath);

                if ($this->pathGuard->isDenied($itemRelPath) || $this->pathGuard->isDenied($file)) {
                    continue;
                }

                if ($pattern !== null && ! fnmatch($pattern, $file)) {
                    continue;
                }

                $entries[] = [
                    'path' => $itemRelPath,
                    'type' => is_dir($itemAbsPath) ? 'directory' : 'file',
                    'size' => is_file($itemAbsPath) ? filesize($itemAbsPath) : null,
                ];

                if (count($entries) >= $maxEntries) {
                    $truncated = true;
                    break;
                }
            }
        }

        return [
            'exists' => true,
            'type' => 'directory',
            'path' => $relativePath,
            'entries' => $entries,
            'total_entries' => count($entries),
            'truncated' => $truncated,
        ];
    }

    protected function readFile(string $absolutePath, string $relativePath, array $params): array
    {
        $size = filesize($absolutePath);
        if ($size > $this->pathGuard->getMaxFileSize()) {
            throw new RuntimeException("File exceeds maximum allowable size of {$this->pathGuard->getMaxFileSize()} bytes: [{$size}] bytes");
        }

        $lines = file($absolutePath, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new RuntimeException("Failed to read file: [{$absolutePath}]");
        }

        $totalLines = count($lines);
        $offset = max(0, (int) ($params['offset'] ?? 0));
        $maxLines = max(1, min(1000, (int) ($params['max_lines'] ?? 500)));

        $sliced = array_slice($lines, $offset, $maxLines);
        $content = implode("\n", $sliced);

        $truncated = ($offset + count($sliced)) < $totalLines;

        if (! empty($params['max_bytes'])) {
            $maxBytes = (int) $params['max_bytes'];
            if (strlen($content) > $maxBytes) {
                $content = substr($content, 0, $maxBytes);
                $truncated = true;
            }
        }

        return [
            'exists' => true,
            'type' => 'file',
            'path' => $relativePath,
            'total_lines' => $totalLines,
            'offset' => $offset,
            'returned_lines' => count($sliced),
            'content' => $content,
            'size' => $size,
            'truncated' => $truncated,
        ];
    }
}
