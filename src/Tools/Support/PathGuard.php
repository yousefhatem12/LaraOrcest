<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tools\Support;

use RuntimeException;
use Yousef\LaraOrcest\Support\Config;
use Yousef\LaraOrcest\Workspace\WorkspaceContext;

class PathGuard
{
    protected string $projectRoot;

    /**
     * @var array<int, string>
     */
    protected array $denyPatterns;

    protected int $maxFileSize;

    protected ?WorkspaceContext $workspaceContext = null;

    /**
     * @param  array<int, string>|null  $denyPatterns
     */
    public function __construct(
        ?string $projectRoot = null,
        ?array $denyPatterns = null,
        int $maxFileSize = 1_000_000,
        ?WorkspaceContext $workspaceContext = null
    ) {
        $this->workspaceContext = $workspaceContext;

        $rootConfig = $workspaceContext?->getConfig()->get('tools.project_root', $workspaceContext?->getConfig()->get('sandbox.project_root'))
            ?: Config::get('lara-orcest.tools.project_root', Config::get('lara-orcest.sandbox.project_root'));

        $effectiveRoot = $projectRoot
            ?: ($workspaceContext?->getTargetProjectPath()
            ?: ($rootConfig ?: Config::basePath()));

        $this->projectRoot = $this->canonicalizeRoot($effectiveRoot);

        $configuredDeny = $workspaceContext?->getConfig()->get('tools.deny_paths', $workspaceContext?->getConfig()->get('sandbox.deny_paths'))
            ?: Config::get('lara-orcest.tools.deny_paths', Config::get('lara-orcest.sandbox.deny_paths'));

        $defaultDeny = [
            '.env',
            '.env.*',
            '.git',
            '.git/*',
            'vendor',
            'node_modules',
            'storage/logs',
            'bootstrap/cache',
            'storage/oauth*',
            '*.key',
            '*.pem',
        ];

        $this->denyPatterns = $denyPatterns ?: (array) ($configuredDeny ?: $defaultDeny);

        $maxSizeConfig = $workspaceContext?->getConfig()->get('tools.max_read_size', $workspaceContext?->getConfig()->get('sandbox.max_file_size'))
            ?: Config::get('lara-orcest.tools.max_read_size', Config::get('lara-orcest.sandbox.max_file_size'));

        $this->maxFileSize = $maxSizeConfig ? (int) $maxSizeConfig : $maxFileSize;
    }

    public function getWorkspaceContext(): ?WorkspaceContext
    {
        return $this->workspaceContext;
    }

    public function setWorkspaceContext(WorkspaceContext $context): self
    {
        $this->workspaceContext = $context;
        $this->projectRoot = $this->canonicalizeRoot($context->getTargetProjectPath());

        return $this;
    }

    public function getProjectRoot(): string
    {
        return $this->projectRoot;
    }

    /**
     * Validates and resolves an absolute or relative file path within the project root.
     *
     * @param  array<int, string>  $allowedPrefixes  Optional list of allowed relative prefixes (e.g. ['database/', 'app/Models/'])
     *
     * @throws RuntimeException If path violates security sandbox
     */
    public function resolveSafePath(string $path, array $allowedPrefixes = [], bool $mustExist = false): string
    {
        $canonicalPath = $this->buildCanonicalPath($path);
        $relativePath = $this->getRelativePath($canonicalPath);

        // Symlink escape check (evaluated before denylist to prevent symlink traversal outside sandbox)
        if (file_exists($canonicalPath) || is_link($canonicalPath)) {
            $real = realpath($canonicalPath);
            if ($real === false || ! str_starts_with($real, $this->projectRoot)) {
                throw new RuntimeException("Symlink escape detected for path: [{$canonicalPath}]");
            }
        } elseif ($mustExist) {
            throw new RuntimeException("Target file does not exist: [{$canonicalPath}]");
        } else {
            $parent = dirname($canonicalPath);
            if (file_exists($parent)) {
                $realParent = realpath($parent);
                if ($realParent === false || ! str_starts_with($realParent, $this->projectRoot)) {
                    throw new RuntimeException("Symlink escape detected for path: [{$canonicalPath}]");
                }
            }
        }

        // Check denylist patterns
        if ($this->isDenied($relativePath) || $this->isDenied(basename($canonicalPath))) {
            throw new RuntimeException("Access to denied path pattern blocked: [{$relativePath}]");
        }

        // Check allowed prefixes if provided
        if (! empty($allowedPrefixes) && ! $this->matchesAllowedPrefixes($relativePath, $allowedPrefixes)) {
            $allowedList = implode(', ', $allowedPrefixes);
            throw new RuntimeException("Path [{$relativePath}] is outside allowed scope: [{$allowedList}]");
        }

        return $canonicalPath;
    }

    /**
     * Validates and resolves an existing or target directory path safely within project root.
     *
     * @param  array<int, string>  $allowedPrefixes
     *
     * @throws RuntimeException
     */
    public function resolveSafeDirectory(string $path, array $allowedPrefixes = [], bool $mustExist = true): string
    {
        $canonicalPath = $this->buildCanonicalPath($path);
        $relativePath = $this->getRelativePath($canonicalPath);

        // Symlink escape check
        if (file_exists($canonicalPath) || is_link($canonicalPath)) {
            $real = realpath($canonicalPath);
            if ($real === false || ! str_starts_with($real, $this->projectRoot)) {
                throw new RuntimeException("Symlink escape detected for directory: [{$canonicalPath}]");
            }
            if ($mustExist && ! is_dir($canonicalPath)) {
                throw new RuntimeException("Path is not a directory: [{$canonicalPath}]");
            }
        } elseif ($mustExist) {
            throw new RuntimeException("Target directory does not exist: [{$canonicalPath}]");
        } else {
            $parent = dirname($canonicalPath);
            if (file_exists($parent)) {
                $realParent = realpath($parent);
                if ($realParent === false || ! str_starts_with($realParent, $this->projectRoot)) {
                    throw new RuntimeException("Symlink escape detected for directory: [{$canonicalPath}]");
                }
            }
        }

        if ($this->isDenied($relativePath) || $this->isDenied(basename($canonicalPath))) {
            throw new RuntimeException("Access to denied directory pattern blocked: [{$relativePath}]");
        }

        if (! empty($allowedPrefixes) && ! $this->matchesAllowedPrefixes($relativePath, $allowedPrefixes)) {
            $allowedList = implode(', ', $allowedPrefixes);
            throw new RuntimeException("Directory [{$relativePath}] is outside allowed scope: [{$allowedList}]");
        }

        return $canonicalPath;
    }

    /**
     * Checks whether a path satisfies all safety criteria without throwing.
     *
     * @param  array<int, string>  $allowedPrefixes
     */
    public function isSafe(string $path, array $allowedPrefixes = []): bool
    {
        try {
            $this->resolveSafePath($path, $allowedPrefixes, mustExist: false);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Checks if a relative path matches any denied pattern.
     */
    public function isDenied(string $path): bool
    {
        $cleanPath = trim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);

        if ($cleanPath === '') {
            return false;
        }

        foreach ($this->denyPatterns as $pattern) {
            if ($this->matchesPattern($cleanPath, $pattern)) {
                return true;
            }
        }

        return false;
    }

    public function getMaxFileSize(): int
    {
        return $this->maxFileSize;
    }

    public function getRelativePath(string $absolutePath): string
    {
        if (str_starts_with($absolutePath, $this->projectRoot)) {
            $rel = ltrim(substr($absolutePath, strlen($this->projectRoot)), DIRECTORY_SEPARATOR);

            return $rel === '' ? '.' : $rel;
        }

        return $absolutePath;
    }

    protected function buildCanonicalPath(string $path): string
    {
        $normalized = $this->normalizePath($path);

        if (str_contains($normalized, "\0")) {
            throw new RuntimeException("Path contains null bytes: [{$path}]");
        }

        $fullPath = str_starts_with($normalized, DIRECTORY_SEPARATOR)
            ? $normalized
            : $this->projectRoot . DIRECTORY_SEPARATOR . ltrim($normalized, DIRECTORY_SEPARATOR);

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

        if (! str_starts_with($canonicalPath, $this->projectRoot)) {
            throw new RuntimeException("Path escapes project root: [{$canonicalPath}]");
        }

        return $canonicalPath;
    }

    protected function matchesAllowedPrefixes(string $relativePath, array $allowedPrefixes): bool
    {
        if ($relativePath === '.' || $relativePath === '') {
            return true;
        }

        foreach ($allowedPrefixes as $prefix) {
            $normalizedPrefix = trim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $prefix), DIRECTORY_SEPARATOR);
            if (str_starts_with($relativePath, $normalizedPrefix) || $relativePath === $normalizedPrefix) {
                return true;
            }
        }

        return false;
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
        $pattern = trim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $pattern), DIRECTORY_SEPARATOR);
        if ($pattern === '') {
            return false;
        }

        // Exact match or folder prefix match
        if ($path === $pattern || str_starts_with($path, $pattern . DIRECTORY_SEPARATOR)) {
            return true;
        }

        // Wildcard match on full relative path or basename
        return fnmatch($pattern, $path, FNM_PATHNAME) || fnmatch($pattern, basename($path));
    }
}
