<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Workspace;

use InvalidArgumentException;
use RuntimeException;
use Yousef\LaraOrcest\Config\OrchestratorConfig;
use Yousef\LaraOrcest\Contracts\CheckpointerContract;
use Yousef\LaraOrcest\Contracts\ProjectMemoryContract;
use Yousef\LaraOrcest\Contracts\ToolContract;
use Yousef\LaraOrcest\Memory\ProjectMemoryStore;
use Yousef\LaraOrcest\State\Drivers\JsonCheckpointer;
use Yousef\LaraOrcest\Support\Config;
use Yousef\LaraOrcest\Tools\ArtisanRunnerTool;
use Yousef\LaraOrcest\Tools\CodebaseInspectorTool;
use Yousef\LaraOrcest\Tools\ComposerRunnerTool;
use Yousef\LaraOrcest\Tools\DirectoryScaffolderTool;
use Yousef\LaraOrcest\Tools\FileReaderTool;
use Yousef\LaraOrcest\Tools\GitCommandTool;
use Yousef\LaraOrcest\Tools\HttpDispatchTool;
use Yousef\LaraOrcest\Tools\HttpFetchTool;
use Yousef\LaraOrcest\Tools\PatchWriterTool;
use Yousef\LaraOrcest\Tools\Support\PathGuard;
use Yousef\LaraOrcest\Tools\TestRunnerTool;
use Yousef\LaraOrcest\Tools\ToolRegistry;

class WorkspaceContext
{
    public const MODE_STANDALONE = 'standalone';
    public const MODE_EMBEDDED = 'embedded';

    protected string $orchestratorRoot;
    protected string $targetProjectPath;
    protected string $statePath;
    protected string $memoryPath;
    protected OrchestratorConfig $config;
    protected string $workspaceMode;

    public function __construct(
        string $targetProjectPath,
        ?string $orchestratorRoot = null,
        ?string $statePath = null,
        ?string $memoryPath = null,
        ?OrchestratorConfig $config = null,
        string $workspaceMode = self::MODE_STANDALONE
    ) {
        $this->targetProjectPath = $this->canonicalizePath($targetProjectPath);
        $this->orchestratorRoot = $orchestratorRoot !== null
            ? $this->canonicalizePath($orchestratorRoot)
            : (getcwd() ? $this->canonicalizePath(getcwd()) : $this->targetProjectPath);

        $this->config = $config ?: OrchestratorConfig::load($this->targetProjectPath);
        $this->workspaceMode = $workspaceMode;

        $defaultStateDir = $this->targetProjectPath . DIRECTORY_SEPARATOR . '.lara-orcest' . DIRECTORY_SEPARATOR . 'state';
        $configuredState = $this->config->get('checkpoint.path', $this->config->get('checkpointer.json_path'));
        $this->statePath = $statePath !== null
            ? $this->canonicalizePath($statePath)
            : ($configuredState ? $this->resolvePath($configuredState) : $defaultStateDir);

        $defaultMemoryFile = $this->targetProjectPath . DIRECTORY_SEPARATOR . '.lara-orcest' . DIRECTORY_SEPARATOR . 'memory.json';
        $configuredMemory = $this->config->get('memory.path');
        $this->memoryPath = $memoryPath !== null
            ? $this->canonicalizePath($memoryPath)
            : ($configuredMemory ? $this->resolvePath($configuredMemory) : $defaultMemoryFile);
    }

    /**
     * Create a WorkspaceContext with flexible options.
     *
     * @param  array<string, mixed>  $options
     */
    public static function create(string $targetProjectPath, array $options = []): self
    {
        $mode = (string) ($options['workspace_mode'] ?? $options['mode'] ?? self::MODE_STANDALONE);
        $orchestratorRoot = isset($options['orchestrator_root']) ? (string) $options['orchestrator_root'] : null;
        $statePath = isset($options['state_path']) ? (string) $options['state_path'] : null;
        $memoryPath = isset($options['memory_path']) ? (string) $options['memory_path'] : null;

        $config = null;
        if (isset($options['config']) && $options['config'] instanceof OrchestratorConfig) {
            $config = $options['config'];
        } elseif (isset($options['config_overrides']) && is_array($options['config_overrides'])) {
            $config = OrchestratorConfig::load($targetProjectPath, $options['config_overrides']);
        }

        return new self(
            targetProjectPath: $targetProjectPath,
            orchestratorRoot: $orchestratorRoot,
            statePath: $statePath,
            memoryPath: $memoryPath,
            config: $config,
            workspaceMode: $mode
        );
    }

    /**
     * Named constructor for standalone mode.
     *
     * @param  array<string, mixed>  $options
     */
    public static function standalone(string $targetProjectPath, array $options = []): self
    {
        $options['workspace_mode'] = self::MODE_STANDALONE;

        return self::create($targetProjectPath, $options);
    }

    /**
     * Named constructor for embedded mode inside host Laravel.
     *
     * @param  array<string, mixed>  $options
     */
    public static function embedded(?string $basePath = null, array $options = []): self
    {
        $path = $basePath ?: Config::basePath();
        $options['workspace_mode'] = self::MODE_EMBEDDED;

        return self::create($path, $options);
    }

    /**
     * Auto-detect workspace from current environment or explicit target path.
     */
    public static function detect(?string $explicitTarget = null, ?string $root = null): self
    {
        if ($explicitTarget !== null && trim($explicitTarget) !== '') {
            return self::standalone($explicitTarget);
        }

        $searchRoot = $root ?: (getcwd() ?: '.');
        $configFile = $searchRoot . DIRECTORY_SEPARATOR . '.lara-orcest' . DIRECTORY_SEPARATOR . 'config.json';

        if (file_exists($configFile)) {
            $data = json_decode((string) file_get_contents($configFile), true);
            if (is_array($data) && ! empty($data['target_path']) && is_string($data['target_path'])) {
                return self::standalone($data['target_path'], [
                    'orchestrator_root' => $searchRoot,
                ]);
            }
        }

        // If running inside Laravel app
        $container = \Illuminate\Container\Container::getInstance();
        if ($container !== null && method_exists($container, 'basePath')) {
            return self::embedded($container->basePath());
        }

        return self::standalone($searchRoot);
    }

    /**
     * Ensures required workspace directories (.lara-orcest, state, memory) exist.
     */
    public function ensureDirectoriesExist(): void
    {
        $orcestDir = $this->targetProjectPath . DIRECTORY_SEPARATOR . '.lara-orcest';
        if (! is_dir($orcestDir)) {
            @mkdir($orcestDir, 0755, true);
        }

        if (! is_dir($this->statePath)) {
            @mkdir($this->statePath, 0755, true);
        }

        $memoryDir = dirname($this->memoryPath);
        if (! is_dir($memoryDir)) {
            @mkdir($memoryDir, 0755, true);
        }

        // Write a .gitignore inside .lara-orcest if absent
        $gitignore = $orcestDir . DIRECTORY_SEPARATOR . '.gitignore';
        if (! file_exists($gitignore)) {
            @file_put_contents($gitignore, "# Ignore local run state and checkpoints\n*.json\nstate/\ncheckpoints/\n");
        }
    }

    /**
     * Resolves a relative path inside the target project workspace.
     */
    public function path(string $relative = ''): string
    {
        if ($relative === '' || $relative === '.') {
            return $this->targetProjectPath;
        }

        return $this->targetProjectPath . DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR);
    }

    /**
     * Resolves a checkpoint file path inside the workspace state directory.
     */
    public function stateFile(string $filename): string
    {
        return $this->statePath . DIRECTORY_SEPARATOR . ltrim($filename, DIRECTORY_SEPARATOR);
    }

    /**
     * Creates a PathGuard bounded strictly to this target project path.
     *
     * @param  array<int, string>|null  $denyPatterns
     */
    public function createPathGuard(?array $denyPatterns = null, ?int $maxFileSize = null): PathGuard
    {
        $deny = $denyPatterns ?? (array) ($this->config->get('tools.deny_paths', $this->config->get('sandbox.deny_paths')));
        $maxSize = $maxFileSize ?? (int) ($this->config->get('tools.max_read_size', $this->config->get('sandbox.max_file_size', 1_000_000)));

        return new PathGuard(
            projectRoot: $this->targetProjectPath,
            denyPatterns: ! empty($deny) ? $deny : null,
            maxFileSize: $maxSize > 0 ? $maxSize : 1_000_000,
            workspaceContext: $this
        );
    }

    /**
     * Creates a checkpointer saving to this workspace's state directory.
     */
    public function createCheckpointer(): CheckpointerContract
    {
        return new JsonCheckpointer(
            storagePath: $this->statePath,
            workspaceContext: $this
        );
    }

    /**
     * Creates a ProjectMemoryStore bound to this workspace.
     */
    public function createMemoryStore(): ProjectMemoryContract
    {
        return new ProjectMemoryStore(
            path: $this->memoryPath,
            pathGuard: $this->createPathGuard(),
            workspaceContext: $this
        );
    }

    /**
     * Creates the complete set of sandboxed tools scoped to this target project.
     *
     * @return array<string, ToolContract>
     */
    public function createTools(): array
    {
        $pathGuard = $this->createPathGuard();
        $targetRoot = $this->targetProjectPath;

        return [
            'file_reader' => new FileReaderTool(pathGuard: $pathGuard),
            'patch_writer' => new PatchWriterTool(pathGuard: $pathGuard),
            'artisan_runner' => new ArtisanRunnerTool(projectRoot: $targetRoot, workspaceContext: $this),
            'test_runner' => new TestRunnerTool(projectRoot: $targetRoot, workspaceContext: $this),
            'git_command' => new GitCommandTool(projectRoot: $targetRoot, pathGuard: $pathGuard, workspaceContext: $this),
            'http_fetch' => new HttpFetchTool(),
            'http_dispatch' => new HttpDispatchTool(),
            'codebase_inspector' => new CodebaseInspectorTool(pathGuard: $pathGuard, workspaceContext: $this),
            'composer_runner' => new ComposerRunnerTool(projectRoot: $targetRoot, workspaceContext: $this),
            'directory_scaffolder' => new DirectoryScaffolderTool(projectRoot: $targetRoot, pathGuard: $pathGuard, workspaceContext: $this),
        ];
    }

    /**
     * Creates a ToolRegistry loaded with all sandboxed tools scoped to this workspace.
     */
    public function createToolRegistry(): ToolRegistry
    {
        $pathGuard = $this->createPathGuard();
        $registry = new ToolRegistry($pathGuard);

        foreach ($this->createTools() as $tool) {
            $registry->register($tool);
        }

        return $registry;
    }

    public function getTargetProjectPath(): string
    {
        return $this->targetProjectPath;
    }

    public function getOrchestratorRoot(): string
    {
        return $this->orchestratorRoot;
    }

    public function getStatePath(): string
    {
        return $this->statePath;
    }

    public function getMemoryPath(): string
    {
        return $this->memoryPath;
    }

    public function getConfig(): OrchestratorConfig
    {
        return $this->config;
    }

    public function getWorkspaceMode(): string
    {
        return $this->workspaceMode;
    }

    public function isStandalone(): bool
    {
        return $this->workspaceMode === self::MODE_STANDALONE;
    }

    public function isEmbedded(): bool
    {
        return $this->workspaceMode === self::MODE_EMBEDDED;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'target_project_path' => $this->targetProjectPath,
            'orchestrator_root' => $this->orchestratorRoot,
            'state_path' => $this->statePath,
            'memory_path' => $this->memoryPath,
            'workspace_mode' => $this->workspaceMode,
            'config' => $this->config->toArray(),
        ];
    }

    protected function canonicalizePath(string $path): string
    {
        $trimmed = trim($path);
        if ($trimmed === '') {
            return getcwd() ?: '.';
        }

        $real = realpath($trimmed);
        if ($real !== false) {
            return rtrim($real, DIRECTORY_SEPARATOR);
        }

        // Relative path resolution against current directory
        if (! str_starts_with($trimmed, DIRECTORY_SEPARATOR) && ! (strlen($trimmed) > 2 && $trimmed[1] === ':')) {
            $combined = (getcwd() ?: '.') . DIRECTORY_SEPARATOR . $trimmed;
            $combinedReal = realpath($combined);

            return $combinedReal !== false ? rtrim($combinedReal, DIRECTORY_SEPARATOR) : rtrim($combined, DIRECTORY_SEPARATOR);
        }

        return rtrim($trimmed, DIRECTORY_SEPARATOR);
    }

    protected function resolvePath(string $path): string
    {
        if (str_starts_with($path, DIRECTORY_SEPARATOR) || (strlen($path) > 2 && $path[1] === ':')) {
            return $path;
        }

        return $this->targetProjectPath . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
    }
}
