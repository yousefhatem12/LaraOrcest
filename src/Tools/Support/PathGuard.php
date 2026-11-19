<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tools\Support;

use RuntimeException;
use Yousef\LaraOrcest\Support\Config;

class PathGuard
{
    protected string $projectRoot;

    /**
     * @var array<int, string>
     */
    protected array $denyPatterns;

    protected int $maxFileSize;

    /**
     * @param  array<int, string>|null  $denyPatterns
     */
    public function __construct(?string $projectRoot = null, ?array $denyPatterns = null, int $maxFileSize = 1_000_000)
    {
        $this->projectRoot = $this->canonicalizeRoot($projectRoot ?: Config::basePath());
        $this->denyPatterns = $denyPatterns ?: (array) Config::get('lara-orcest.sandbox.deny_paths', ['.env', '.git', 'vendor']);
        $this->maxFileSize = $maxFileSize;
    }

    public function getProjectRoot(): string
    {
        return $this->projectRoot;
    }

    /**
     * Validates and resolves an absolute or relative path within the project root.
     *
     * @param  array<int, string>  $allowedPrefixes  Optional list of allowed relative prefixes (e.g. ['database/', 'app/Models/'])
     *
     * @throws RuntimeException If path violates security sandbox
     */
    public function resolveSafePath(string $path, array $allowedPrefixes = [], bool $mustExist = false): string
    {
        $normalized = $this->normalizePath($path);

        // Disallow null bytes
        if (str_contains($normalized, "\0")) {
            throw new RuntimeException("Path contains null bytes: [{$path}]");
        }

        // Check if inside project root
        $fullPath = str_starts_with($normalized, DIRECTORY_SEPARATOR)
            ? $normalized
            : $this->projectRoot . DIRECTORY_SEPARATOR . ltrim($normalized, DIRECTORY_SEPARATOR);

        // Disallow path traversal using '..'
        $parts = explode(DIRECTORY_SEPARATOR, $fullPath);
        $resolvedParts = [];
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if (empty($resolvedParts)) {
                    throw new RuntimeException("Path traversal attempt detected: [{$path}]");
                }
                array_pop($resolvedParts);
            } else {
                $resolvedParts[] = $part;
            }
        }
        $canonicalPath = DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $resolvedParts);

        // Ensure canonical path begins with project root
        if (! str_starts_with($canonicalPath, $this->projectRoot)) {
            throw new RuntimeException("Path escapes project root: [{$canonicalPath}]");
        }

        // Relative path inside project root
        $relativePath = ltrim(substr($canonicalPath, strlen($this->projectRoot)), DIRECTORY_SEPARATOR);

        // Check denylist patterns
        foreach ($this->denyPatterns as $pattern) {
            if ($this->matchesPattern($relativePath, $pattern) || $this->matchesPattern(basename($canonicalPath), $pattern)) {
                throw new RuntimeException("Access to denied path pattern [{$pattern}] blocked: [{$relativePath}]");
            }
        }

        // Check allowed prefixes if provided
        if (! empty($allowedPrefixes)) {
            $isAllowed = false;
            foreach ($allowedPrefixes as $prefix) {
                $normalizedPrefix = trim($prefix, DIRECTORY_SEPARATOR);
                if (str_starts_with($relativePath, $normalizedPrefix) || $relativePath === $normalizedPrefix) {
                    $isAllowed = true;
                    break;
                }
            }
            if (! $isAllowed) {
                $allowedList = implode(', ', $allowedPrefixes);
                throw new RuntimeException("Path [{$relativePath}] is outside allowed scope: [{$allowedList}]");
            }
        }

        // If file exists, check realpath to catch symlink escapes
        if (file_exists($canonicalPath)) {
            $real = realpath($canonicalPath);
            if ($real === false || ! str_starts_with($real, $this->projectRoot)) {
                throw new RuntimeException("Symlink escape detected for path: [{$canonicalPath}]");
            }
        } elseif ($mustExist) {
            throw new RuntimeException("Target file does not exist: [{$canonicalPath}]");
        }

        return $canonicalPath;
    }

    public function getMaxFileSize(): int
    {
        return $this->maxFileSize;
    }

    public function getRelativePath(string $absolutePath): string
    {
        if (str_starts_with($absolutePath, $this->projectRoot)) {
            return ltrim(substr($absolutePath, strlen($this->projectRoot)), DIRECTORY_SEPARATOR);
        }

        return $absolutePath;
    }

    protected function canonicalizeRoot(string $root): string
    {
        $real = realpath($root);
        if ($real === false) {
            return rtrim($root, DIRECTORY_SEPARATOR);
        }

        return rtrim($real, DIRECTORY_SEPARATOR);
    }

    protected function normalizePath(string $path): string
    {
        return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, trim($path));
    }

    protected function matchesPattern(string $path, string $pattern): bool
    {
        $pattern = trim($pattern, DIRECTORY_SEPARATOR);
        if ($pattern === '') {
            return false;
        }

        // Exact match or folder prefix match
        if ($path === $pattern || str_starts_with($path, $pattern . DIRECTORY_SEPARATOR)) {
            return true;
        }

        // Wildcard fnmatch
        return fnmatch($pattern, $path, FNM_PATHNAME) || fnmatch($pattern, basename($path));
    }
}
