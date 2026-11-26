<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tools;

use InvalidArgumentException;
use Yousef\LaraOrcest\Contracts\ToolContract;
use Yousef\LaraOrcest\Support\Config;
use Yousef\LaraOrcest\Tools\Support\PathGuard;
use Yousef\LaraOrcest\Workspace\WorkspaceContext;

class CodebaseInspectorTool implements ToolContract
{
    protected PathGuard $pathGuard;
    protected ?WorkspaceContext $workspaceContext = null;

    /**
     * @var array<int, string>
     */
    protected array $allowedExplorePaths = [];

    /**
     * @var array<int, string>
     */
    protected array $ignorePaths = [];

    public function __construct(
        ?PathGuard $pathGuard = null,
        ?WorkspaceContext $workspaceContext = null
    ) {
        $this->workspaceContext = $workspaceContext;
        $this->pathGuard = $pathGuard ?: ($workspaceContext ? $workspaceContext->createPathGuard() : new PathGuard());
    }

    /**
     * @param  array<int, string>  $paths
     */
    public function setAllowedExplorePaths(array $paths): self
    {
        $this->allowedExplorePaths = array_values(array_filter($paths, static fn ($p) => is_string($p) && trim($p) !== ''));

        return $this;
    }

    /**
     * @return array<int, string>
     */
    public function getAllowedExplorePaths(): array
    {
        return $this->allowedExplorePaths;
    }

    /**
     * @param  array<int, string>  $paths
     */
    public function setIgnorePaths(array $paths): self
    {
        $this->ignorePaths = array_values(array_filter($paths, static fn ($p) => is_string($p) && trim($p) !== ''));

        return $this;
    }

    /**
     * @return array<int, string>
     */
    public function getIgnorePaths(): array
    {
        return $this->ignorePaths;
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
        return 'codebase_inspector';
    }

    public function getDescription(): string
    {
        return 'Safely inspects existing directory structure, models, migrations, routes, and class symbols without mutating files.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => ['list_directory', 'inspect_models', 'inspect_migrations', 'inspect_routes', 'search_symbols'],
                    'description' => 'Inspection action to perform.',
                ],
                'path' => [
                    'type' => 'string',
                    'description' => 'Target directory or file path relative to project root.',
                ],
                'pattern' => [
                    'type' => 'string',
                    'description' => 'Optional search pattern or glob string.',
                ],
            ],
            'required' => ['action'],
        ];
    }

    public function execute(array $params): array
    {
        $action = (string) ($params['action'] ?? 'list_directory');
        $path = (string) ($params['path'] ?? '');
        $pattern = (string) ($params['pattern'] ?? '');

        return match ($action) {
            'list_directory' => $this->listDirectory($path, $pattern),
            'inspect_models' => $this->inspectModels($path),
            'inspect_migrations' => $this->inspectMigrations($path),
            'inspect_routes' => $this->inspectRoutes($path),
            'search_symbols' => $this->searchSymbols($path, $pattern),
            default => throw new InvalidArgumentException("Unsupported inspection action [{$action}]."),
        };
    }

    /**
     * Lists files and folders in a directory safely.
     *
     * @return array{path: string, entries: array<int, array{name: string, type: string, size_bytes: int}>}
     */
    public function listDirectory(string $path, string $pattern = ''): array
    {
        $resolved = $this->pathGuard->resolveSafePath($path !== '' ? $path : '.', mustExist: false);
        $relTarget = $this->pathGuard->getRelativePath($resolved);
        if (! $this->isDirectoryPermitted($relTarget)) {
            return [
                'path' => $relTarget,
                'exists' => false,
                'entries' => [],
            ];
        }

        if (! is_dir($resolved)) {
            return [
                'path' => $relTarget,
                'exists' => false,
                'entries' => [],
            ];
        }

        $items = scandir($resolved);
        if (! is_array($items)) {
            return [
                'path' => $this->pathGuard->getRelativePath($resolved),
                'exists' => true,
                'entries' => [],
            ];
        }

        $entries = [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $full = $resolved . DIRECTORY_SEPARATOR . $item;
            $rel = $this->pathGuard->getRelativePath($full);

            // Skip denied paths
            if ($this->pathGuard->isDenied($rel) || $this->pathGuard->isDenied($item)) {
                continue;
            }

            $isDir = is_dir($full);
            if ($isDir ? ! $this->isDirectoryPermitted($rel) : ! $this->isFilePermitted($rel)) {
                continue;
            }

            if ($pattern !== '' && ! fnmatch($pattern, $item)) {
                continue;
            }

            $entries[] = [
                'name' => $item,
                'path' => $rel,
                'type' => $isDir ? 'directory' : 'file',
                'size_bytes' => $isDir ? 0 : (int) filesize($full),
            ];
        }

        return [
            'path' => $this->pathGuard->getRelativePath($resolved),
            'exists' => true,
            'entries' => $entries,
        ];
    }

    /**
     * Inspects existing Eloquent models in app/Models or app/.
     *
     * @return array{models: array<int, array{class: string, file: string}>}
     */
    public function inspectModels(string $path = ''): array
    {
        $dir = $path !== '' ? $path : 'app/Models';
        $resolved = $this->pathGuard->resolveSafePath($dir, mustExist: false);

        if (! is_dir($resolved)) {
            // Fallback to app/ if app/Models doesn't exist
            $dir = 'app';
            $resolved = $this->pathGuard->resolveSafePath($dir, mustExist: false);
        }

        if (! is_dir($resolved) || ! $this->isDirectoryPermitted($this->pathGuard->getRelativePath($resolved))) {
            return ['models' => []];
        }

        $files = glob($resolved . '/*.php') ?: [];
        $models = [];

        foreach ($files as $file) {
            $rel = $this->pathGuard->getRelativePath($file);
            if (! $this->isFilePermitted($rel)) {
                continue;
            }
            $content = (string) file_get_contents($file);
            $className = pathinfo($file, PATHINFO_FILENAME);

            if (str_contains($content, 'extends Model') || str_contains($content, 'extends Authenticatable')) {
                $models[] = [
                    'class' => $className,
                    'file' => $rel,
                ];
            }
        }

        return ['models' => $models];
    }

    /**
     * Inspects migration files to identify tables and schema structure.
     *
     * @return array{migrations: array<int, array{file: string, table: ?string, action: string}>}
     */
    public function inspectMigrations(string $path = ''): array
    {
        $dir = $path !== '' ? $path : 'database/migrations';
        $resolved = $this->pathGuard->resolveSafePath($dir, mustExist: false);

        if (! is_dir($resolved) || ! $this->isDirectoryPermitted($this->pathGuard->getRelativePath($resolved))) {
            return ['migrations' => []];
        }

        $files = glob($resolved . '/*.php') ?: [];
        sort($files);

        $migrations = [];
        foreach ($files as $file) {
            $rel = $this->pathGuard->getRelativePath($file);
            if (! $this->isFilePermitted($rel)) {
                continue;
            }
            $base = basename($file);
            $table = null;
            $action = 'migration';

            if (preg_match('/create_(.+)_table/', $base, $matches)) {
                $table = $matches[1];
                $action = 'create';
            } elseif (preg_match('/add_(.+)_to_(.+)_table/', $base, $matches)) {
                $table = $matches[2];
                $action = 'alter';
            }

            $migrations[] = [
                'file' => $rel,
                'name' => pathinfo($file, PATHINFO_FILENAME),
                'table' => $table,
                'action' => $action,
            ];
        }

        return ['migrations' => $migrations];
    }

    /**
     * Inspects route definitions in routes/api.php and routes/web.php.
     *
     * @return array{routes: array<int, array{file: string, endpoints: array<int, string>}>}
     */
    public function inspectRoutes(string $path = ''): array
    {
        $candidates = $path !== '' ? [$path] : ['routes/api.php', 'routes/web.php'];
        $results = [];

        foreach ($candidates as $cand) {
            $safe = $this->pathGuard->resolveSafePath($cand, mustExist: false);
            $rel = $this->pathGuard->getRelativePath($safe);
            if (! is_file($safe) || ! $this->isFilePermitted($rel)) {
                continue;
            }

            $content = (string) file_get_contents($safe);
            preg_match_all('/Route::(get|post|put|patch|delete|resource|apiResource)\s*\(\s*[\'"]([^\'"]+)[\'"]/i', $content, $matches, PREG_SET_ORDER);

            $endpoints = [];
            foreach ($matches as $match) {
                $endpoints[] = strtoupper($match[1]) . ' ' . $match[2];
            }

            $results[] = [
                'file' => $rel,
                'endpoints' => $endpoints,
            ];
        }

        return ['routes' => $results];
    }

    /**
     * Searches for class, interface, enum, or trait symbols.
     *
     * @return array{symbols: array<int, array{type: string, name: string, file: string}>}
     */
    public function searchSymbols(string $path = '', string $pattern = ''): array
    {
        $dir = $path !== '' ? $path : 'app';
        $resolved = $this->pathGuard->resolveSafePath($dir, mustExist: false);

        if (! is_dir($resolved) || ! $this->isDirectoryPermitted($this->pathGuard->getRelativePath($resolved))) {
            return ['symbols' => []];
        }

        $dirIterator = new \RecursiveDirectoryIterator($resolved, \RecursiveDirectoryIterator::SKIP_DOTS);
        $filterIterator = new \RecursiveCallbackFilterIterator(
            $dirIterator,
            function (\SplFileInfo $current): bool {
                $rel = $this->pathGuard->getRelativePath($current->getPathname());
                if ($this->pathGuard->isDenied($rel) || $this->pathGuard->isDenied($current->getFilename())) {
                    return false;
                }
                if (! empty($this->ignorePaths) && $this->pathGuard->matchesAnyGlob($rel, $this->ignorePaths)) {
                    return false;
                }
                if ($current->isDir() && ! empty($this->allowedExplorePaths)) {
                    return $this->pathGuard->isDirectoryWithinGlobs($rel, $this->allowedExplorePaths);
                }

                return true;
            }
        );
        $iterator = new \RecursiveIteratorIterator($filterIterator);

        $symbols = [];
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $rel = $this->pathGuard->getRelativePath($file->getRealPath());
            if (! $this->isFilePermitted($rel)) {
                continue;
            }

            $content = (string) file_get_contents($file->getRealPath());
            if (preg_match('/(class|interface|trait|enum)\s+([a-zA-Z0-9_]+)/i', $content, $m)) {
                $symName = $m[2];
                if ($pattern !== '' && ! fnmatch($pattern, $symName) && ! str_contains(strtolower($symName), strtolower($pattern))) {
                    continue;
                }

                $symbols[] = [
                    'type' => strtolower($m[1]),
                    'name' => $symName,
                    'file' => $rel,
                ];
            }
        }

        return ['symbols' => $symbols];
    }

    protected function isFilePermitted(string $relativePath): bool
    {
        if (! empty($this->ignorePaths) && $this->pathGuard->matchesAnyGlob($relativePath, $this->ignorePaths)) {
            return false;
        }

        if (! empty($this->allowedExplorePaths)) {
            return $this->pathGuard->matchesAnyGlob($relativePath, $this->allowedExplorePaths);
        }

        return true;
    }

    protected function isDirectoryPermitted(string $relativeDir): bool
    {
        if ($relativeDir !== '.' && ! empty($this->ignorePaths) && $this->pathGuard->matchesAnyGlob($relativeDir, $this->ignorePaths)) {
            return false;
        }

        if (! empty($this->allowedExplorePaths)) {
            return $this->pathGuard->isDirectoryWithinGlobs($relativeDir, $this->allowedExplorePaths);
        }

        return true;
    }
}
