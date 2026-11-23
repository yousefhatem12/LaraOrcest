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
     */
    public function __construct(
        protected ToolContract $tool,
        protected array $scopePaths = [],
        ?PathGuard $pathGuard = null
    ) {
        $this->pathGuard = $pathGuard ?: new PathGuard();
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

    public function execute(array $params): array
    {
        // If scope paths are restricted and params target a path, enforce the scope boundary
        if (! empty($this->scopePaths) && isset($params['path'])) {
            $path = (string) $params['path'];
            $this->validateScope($path);
        }

        // If underlying tool is PatchWriterTool, configure its allowedPrefixes
        if ($this->tool instanceof PatchWriterTool && ! empty($this->scopePaths)) {
            $cleanPrefixes = array_map(function ($p) {
                return rtrim(preg_replace('/(\/\*\*|\/\*|\*)$/', '', $p) ?? $p, '/\\') . '/';
            }, $this->scopePaths);

            $this->tool->setAllowedPrefixes($cleanPrefixes);
        }

        return $this->tool->execute($params);
    }

    /**
     * Validates that the requested path matches one of the assigned scope paths.
     */
    protected function validateScope(string $path): void
    {
        $safePath = $this->pathGuard->resolveSafePath($path, mustExist: false);
        $rel = $this->pathGuard->getRelativePath($safePath);
        $relNormalized = trim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $rel), DIRECTORY_SEPARATOR);

        foreach ($this->scopePaths as $scope) {
            $cleanScope = trim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $scope), DIRECTORY_SEPARATOR);

            // Handle wildcards (e.g. database/migrations/** or app/Models/*)
            if (str_ends_with($cleanScope, '**') || str_ends_with($cleanScope, '*')) {
                $baseScope = rtrim(preg_replace('/(\/\*\*|\/\*|\*)$/', '', $cleanScope) ?? $cleanScope, DIRECTORY_SEPARATOR);
                if ($relNormalized === $baseScope || str_starts_with($relNormalized, $baseScope . DIRECTORY_SEPARATOR)) {
                    return;
                }
            }

            // Prefix or exact match
            if ($relNormalized === $cleanScope || str_starts_with($relNormalized, $cleanScope . DIRECTORY_SEPARATOR)) {
                return;
            }

            // Glob pattern match
            if (fnmatch($cleanScope, $relNormalized, FNM_PATHNAME) || fnmatch($cleanScope, basename($relNormalized))) {
                return;
            }
        }

        $scopeList = implode(', ', $this->scopePaths);
        throw new RuntimeException("Sub-agent scope violation: Target path [{$rel}] is outside permitted scope: [{$scopeList}]");
    }
}
