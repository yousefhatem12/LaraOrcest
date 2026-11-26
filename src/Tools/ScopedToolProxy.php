<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tools;

use RuntimeException;
use Yousef\LaraOrcest\Contracts\ToolContract;
use Yousef\LaraOrcest\Tools\Support\PathGuard;

class ScopedToolProxy implements ToolContract
{
    protected PathGuard $pathGuard;

    /**
     * @param  array<int, string>  $scopePaths
     * @param  array<int, string>  $explorePaths
     * @param  array<int, string>  $modifyPaths
     * @param  array<int, string>  $ignorePaths
     */
    public function __construct(
        protected ToolContract $tool,
        protected array $scopePaths = [],
        ?PathGuard $pathGuard = null,
        mixed $agent = null,
        protected array $explorePaths = [],
        protected array $modifyPaths = [],
        protected array $ignorePaths = [],
        protected bool $hasExplicitExplorePaths = false,
        protected bool $hasExplicitModifyPaths = false
    ) {
        if ($pathGuard === null && method_exists($tool, 'getPathGuard') && $tool->getPathGuard() instanceof PathGuard) {
            $pathGuard = $tool->getPathGuard();
        }
        $this->pathGuard = $pathGuard ?: new PathGuard();
    }

    public function getPathGuard(): PathGuard
    {
        return $this->pathGuard;
    }

    public function getWorkspaceContext(): ?WorkspaceContext
    {
        if (method_exists($this->tool, 'getWorkspaceContext')) {
            return $this->tool->getWorkspaceContext();
        }

        return $this->pathGuard->getWorkspaceContext();
    }

    public function getName(): string
    {
        return $this->tool->getName();
    }

    public function getDescription(): string
    {
        return $this->tool->getDescription();
    }

    public function getSchema(): array
    {
        return $this->tool->getSchema();
    }

    public function getUnderlyingTool(): ToolContract
    {
        return $this->tool;
    }

    /**
     * @return array<int, string>
     */
    public function getScopePaths(): array
    {
        return $this->scopePaths;
    }

    public function setDryRun(bool $dryRun): self
    {
        if (method_exists($this->tool, 'setDryRun')) {
            $this->tool->setDryRun($dryRun);
        }

        return $this;
    }

    public function isDryRun(): bool
    {
        if (method_exists($this->tool, 'isDryRun')) {
            return (bool) $this->tool->isDryRun();
        }

        return false;
    }

    /**
     * @param  array<int, string>  $prefixes
     */
    public function setAllowedPrefixes(array $prefixes): self
    {
        if (method_exists($this->tool, 'setAllowedPrefixes')) {
            $this->tool->setAllowedPrefixes($prefixes);
        }

        return $this;
    }

    /**
     * @return array<int, string>
     */
    public function getAllowedPrefixes(): array
    {
        if (method_exists($this->tool, 'getAllowedPrefixes')) {
            return (array) $this->tool->getAllowedPrefixes();
        }

        return [];
    }

    /**
     * Forward auxiliary tool configuration calls cleanly to the wrapped tool.
     *
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        if (method_exists($this->tool, $method)) {
            $result = $this->tool->{$method}(...$arguments);

            return $result === $this->tool ? $this : $result;
        }

        throw new \BadMethodCallException('Call to undefined method ' . static::class . "::{$method}()");
    }

    public function execute(array $params): array
    {
        $isReadTool = $this->tool instanceof FileReaderTool || $this->tool instanceof CodebaseInspectorTool;
        $isWriteTool = $this->tool instanceof PatchWriterTool || $this->tool instanceof DirectoryScaffolderTool;

        $targetPaths = $this->extractTargetPaths($params);

        // 1. Always block targets matching skill ignore_paths
        if (! empty($this->ignorePaths)) {
            foreach ($targetPaths as $targetPath) {
                $rel = $this->resolveRelative($targetPath);
                if ($rel !== '.' && $this->pathGuard->matchesAnyGlob($rel, $this->ignorePaths)) {
                    throw new RuntimeException("Access blocked by skill ignore_paths: [{$rel}]");
                }
            }
        }

        // 2. Enforce read/explore vs write/modify boundaries
        if ($isReadTool) {
            if ($this->hasExplicitExplorePaths) {
                if (method_exists($this->tool, 'setAllowedExplorePaths')) {
                    $this->tool->setAllowedExplorePaths($this->explorePaths);
                }
                if (method_exists($this->tool, 'setIgnorePaths')) {
                    $this->tool->setIgnorePaths($this->ignorePaths);
                }

                foreach ($targetPaths as $targetPath) {
                    $this->validateExploreScope($targetPath);
                }
            } elseif (! empty($this->scopePaths)) {
                foreach ($targetPaths as $targetPath) {
                    $this->validateScope($targetPath);
                }
            }
        } else {
            if (! empty($this->scopePaths)) {
                foreach ($targetPaths as $targetPath) {
                    $this->validateScope($targetPath);
                }
            }

            if ($isWriteTool && $this->hasExplicitModifyPaths) {
                foreach ($targetPaths as $targetPath) {
                    $this->validateModifyScope($targetPath);
                }
            }
        }

        // 3. Propagate prefixes and skill modify/ignore rules to underlying write tools
        if ($this->tool instanceof PatchWriterTool) {
            if (! empty($this->scopePaths)) {
                $this->tool->setAllowedPrefixes($this->toPrefixes($this->scopePaths));
            }
            if ($this->hasExplicitModifyPaths) {
                $this->tool->setAllowedModifyPaths($this->modifyPaths, enforce: true);
            }
            if (! empty($this->ignorePaths)) {
                $this->tool->setIgnorePaths($this->ignorePaths);
            }
        }

        if ($this->tool instanceof DirectoryScaffolderTool && ! empty($this->scopePaths)) {
            $this->tool->setAllowedPrefixes($this->toPrefixes($this->scopePaths));
        }

        return $this->tool->execute($params);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<int, string>
     */
    protected function extractTargetPaths(array $params): array
    {
        $paths = [];
        if (isset($params['path']) && is_string($params['path']) && trim($params['path']) !== '') {
            $paths[] = $params['path'];
        }
        if (isset($params['directory']) && is_string($params['directory']) && trim($params['directory']) !== '') {
            $paths[] = $params['directory'];
        }
        if (isset($params['directories']) && is_array($params['directories'])) {
            foreach ($params['directories'] as $dir) {
                if (is_string($dir) && trim($dir) !== '') {
                    $paths[] = $dir;
                }
            }
        }
        if (isset($params['files']) && is_array($params['files'])) {
            foreach ($params['files'] as $f) {
                if (is_array($f) && isset($f['path']) && is_string($f['path']) && trim($f['path']) !== '') {
                    $paths[] = $f['path'];
                }
            }
        }

        return $paths;
    }

    /**
     * @param  array<int, string>  $globs
     * @return array<int, string>
     */
    protected function toPrefixes(array $globs): array
    {
        return array_map(
            static fn (string $p): string => rtrim(preg_replace('/(\/\*\*|\/\*|\*)$/', '', $p) ?? $p, '/\\') . '/',
            $globs
        );
    }

    protected function resolveRelative(string $path): string
    {
        $safePath = $this->pathGuard->resolveSafePath($path, mustExist: false);

        return $this->pathGuard->getRelativePath($safePath);
    }

    protected function validateExploreScope(string $path): void
    {
        $safePath = $this->pathGuard->resolveSafePath($path, mustExist: false);
        $rel = $this->pathGuard->getRelativePath($safePath);

        if (empty($this->explorePaths)) {
            throw new RuntimeException("Read access blocked: active skill does not allow reading [{$rel}].");
        }

        $isDir = is_dir($safePath);
        $allowed = $isDir
            ? $this->pathGuard->isDirectoryWithinGlobs($rel, $this->explorePaths)
            : $this->pathGuard->matchesAnyGlob($rel, $this->explorePaths);

        if (! $allowed) {
            $list = implode(', ', $this->explorePaths);
            throw new RuntimeException("Read access blocked: [{$rel}] is outside skill explore_paths/modify_paths: [{$list}]");
        }
    }

    protected function validateModifyScope(string $path): void
    {
        $rel = $this->resolveRelative($path);

        if (empty($this->modifyPaths)) {
            throw new RuntimeException("Skill modify_paths violation: Target path [{$rel}] cannot be modified because active skill is read-only.");
        }

        if (! $this->pathGuard->matchesAnyGlob($rel, $this->modifyPaths)) {
            $list = implode(', ', $this->modifyPaths);
            throw new RuntimeException("Skill modify_paths violation: Target path [{$rel}] is outside permitted modify_paths: [{$list}]");
        }
    }

    /**
     * Validates that the requested path matches one of the assigned scope paths.
     */
    protected function validateScope(string $path): void
    {
        $rel = $this->resolveRelative($path);

        if ($this->pathGuard->matchesAnyGlob($rel, $this->scopePaths)) {
            return;
        }

        $scopeList = implode(', ', $this->scopePaths);
        throw new RuntimeException("Sub-agent scope violation: Target path [{$rel}] is outside permitted scope: [{$scopeList}]");
    }
}
