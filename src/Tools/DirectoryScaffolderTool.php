<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tools;

use InvalidArgumentException;
use RuntimeException;
use Yousef\LaraOrcest\Contracts\ToolContract;
use Yousef\LaraOrcest\Support\Config;
use Yousef\LaraOrcest\Tools\Support\PathGuard;
use Yousef\LaraOrcest\Workspace\WorkspaceContext;

class DirectoryScaffolderTool implements ToolContract
{
    protected string $projectRoot;

    protected PathGuard $pathGuard;

    protected bool $dryRun;

    protected ?WorkspaceContext $workspaceContext = null;

    /**
     * @var array<int, string>
     */
    protected array $allowedPrefixes = [];

    /**
     * @param  array<int, string>  $allowedPrefixes
     */
    public function __construct(
        ?string $projectRoot = null,
        ?PathGuard $pathGuard = null,
        bool $dryRun = false,
        ?WorkspaceContext $workspaceContext = null,
        array $allowedPrefixes = []
    ) {
        $this->workspaceContext = $workspaceContext;
        $this->projectRoot = $projectRoot ?: ($workspaceContext?->getTargetProjectPath() ?: Config::basePath());
        $this->pathGuard = $pathGuard ?: ($workspaceContext ? $workspaceContext->createPathGuard() : new PathGuard($this->projectRoot));
        $this->dryRun = $dryRun;
        $this->allowedPrefixes = $allowedPrefixes;
    }

    public function getName(): string
    {
        return 'directory_scaffolder';
    }

    public function getDescription(): string
    {
        return 'Safely creates and scaffolds directory structures and starter files inside the target project workspace within PathGuard boundaries.';
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

    /**
     * @param  array<int, string>  $prefixes
     */
    public function setAllowedPrefixes(array $prefixes): self
    {
        $this->allowedPrefixes = array_values($prefixes);

        return $this;
    }

    /**
     * @return array<int, string>
     */
    public function getAllowedPrefixes(): array
    {
        return $this->allowedPrefixes;
    }

    public function getProjectRoot(): string
    {
        return $this->projectRoot;
    }

    public function setProjectRoot(string $projectRoot): self
    {
        $this->projectRoot = $projectRoot;

        return $this;
    }

    public function getWorkspaceContext(): ?WorkspaceContext
    {
        return $this->workspaceContext;
    }

    public function setWorkspaceContext(WorkspaceContext $context): self
    {
        $this->workspaceContext = $context;
        $this->projectRoot = $context->getTargetProjectPath();
        $this->pathGuard = $context->createPathGuard();

        return $this;
    }

    public function getPathGuard(): PathGuard
    {
        return $this->pathGuard;
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'directories' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'List of relative directory paths to scaffold (e.g. app/Domains/Invoicing/Models).',
                ],
                'directory' => [
                    'type' => 'string',
                    'description' => 'Single relative directory path to scaffold.',
                ],
                'files' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'path' => [
                                'type' => 'string',
                                'description' => 'Relative path of starter file to create.',
                            ],
                            'content' => [
                                'type' => 'string',
                                'description' => 'Initial boilerplate code or content.',
                            ],
                            'summary' => [
                                'type' => 'string',
                                'description' => 'Brief description of the starter file.',
                            ],
                        ],
                        'required' => ['path'],
                    ],
                    'description' => 'Optional starter or boilerplate files to create inside scaffolded directories.',
                ],
                'mode' => [
                    'type' => 'integer',
                    'description' => 'Octal permissions for created directories (default: 0755).',
                ],
            ],
        ];
    }

    public function execute(array $params): array
    {
        $directories = [];
        if (! empty($params['directories']) && is_array($params['directories'])) {
            foreach ($params['directories'] as $dir) {
                if (is_string($dir) && trim($dir) !== '') {
                    $directories[] = trim($dir);
                }
            }
        }

        if (! empty($params['directory']) && is_string($params['directory'])) {
            $singleDir = trim($params['directory']);
            if ($singleDir !== '' && ! in_array($singleDir, $directories, true)) {
                $directories[] = $singleDir;
            }
        }

        $files = [];
        if (! empty($params['files']) && is_array($params['files'])) {
            $files = (array) $params['files'];
        }

        if (empty($directories) && empty($files)) {
            return [
                'successful' => true,
                'dry_run' => $this->dryRun,
                'created_directories' => [],
                'created_files' => [],
                'summary' => 'No directories or files to scaffold.',
            ];
        }

        $mode = isset($params['mode']) && is_int($params['mode']) ? $params['mode'] : 0755;

        // 1. Validate all directories through PathGuard
        $validatedDirs = [];
        foreach ($directories as $dir) {
            $safeDir = $this->pathGuard->resolveSafeDirectory($dir, $this->allowedPrefixes, mustExist: false);
            $relDir = $this->pathGuard->getRelativePath($safeDir);
            $validatedDirs[] = [
                'path' => $relDir,
                'absolute_path' => $safeDir,
            ];
        }

        // 2. Validate all files through PathGuard
        $validatedFiles = [];
        foreach ($files as $file) {
            if (! isset($file['path']) || ! is_string($file['path'])) {
                continue;
            }
            $filePath = trim($file['path']);
            $content = (string) ($file['content'] ?? '');
            $summary = (string) ($file['summary'] ?? "Starter file: {$filePath}");

            $safeFilePath = $this->pathGuard->resolveSafePath($filePath, $this->allowedPrefixes, mustExist: false);
            $relFilePath = $this->pathGuard->getRelativePath($safeFilePath);

            $validatedFiles[] = [
                'path' => $relFilePath,
                'absolute_path' => $safeFilePath,
                'content' => $content,
                'summary' => $summary,
            ];
        }

        // 3. Dry-run preview
        if ($this->dryRun) {
            $createdDirs = array_column($validatedDirs, 'path');
            $createdFilesList = array_map(function ($f) {
                return [
                    'path' => $f['path'],
                    'summary' => $this->redactSecrets($f['summary']),
                    'action' => 'preview',
                ];
            }, $validatedFiles);

            $summary = "[DRY-RUN] Planned scaffolding of " . count($createdDirs) . " directories and " . count($createdFilesList) . " files.";

            return [
                'successful' => true,
                'dry_run' => true,
                'created_directories' => $createdDirs,
                'created_files' => $createdFilesList,
                'summary' => $this->redactSecrets($summary),
            ];
        }

        // 4. Actual execution
        $createdDirs = [];
        foreach ($validatedDirs as $item) {
            $absPath = $item['absolute_path'];
            if (! is_dir($absPath)) {
                if (! @mkdir($absPath, $mode, true) && ! is_dir($absPath)) {
                    throw new RuntimeException("Failed to create directory: [{$item['path']}]");
                }
            }
            $createdDirs[] = $item['path'];
        }

        $createdFilesList = [];
        foreach ($validatedFiles as $item) {
            $absPath = $item['absolute_path'];
            $dir = dirname($absPath);
            if (! is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }

            $written = @file_put_contents($absPath, $item['content']);
            if ($written === false) {
                throw new RuntimeException("Failed to write starter file: [{$item['path']}]");
            }

            $createdFilesList[] = [
                'path' => $item['path'],
                'summary' => $this->redactSecrets($item['summary']),
                'action' => 'created',
            ];
        }

        $summary = "Scaffolded " . count($createdDirs) . " directories and " . count($createdFilesList) . " files.";

        return [
            'successful' => true,
            'dry_run' => false,
            'created_directories' => $createdDirs,
            'created_files' => $createdFilesList,
            'summary' => $this->redactSecrets($summary),
        ];
    }

    public function redactSecrets(string $text): string
    {
        $text = preg_replace('/https?:\/\/[^@\s]+@/', 'https://[REDACTED_CREDENTIALS]@', $text) ?? $text;
        $text = preg_replace('/(sk-[a-zA-Z0-9]{20,})/i', '[REDACTED_API_KEY]', $text) ?? $text;
        $text = preg_replace('/(bearer\s+[a-zA-Z0-9\-\._~\+\/]+=*)/i', 'Bearer [REDACTED_TOKEN]', $text) ?? $text;
        $text = preg_replace('/(api[_-]?key["\']?\s*[:=]\s*["\']?)([^"\'\s]+)/i', '$1[REDACTED]', $text) ?? $text;
        $text = preg_replace('/(APP_KEY\s*=\s*)([^\s\n]+)/i', '$1[REDACTED_APP_KEY]', $text) ?? $text;
        $text = preg_replace('/(DB_PASSWORD\s*=\s*)([^\s\n]+)/i', '$1[REDACTED_PASSWORD]', $text) ?? $text;

        return $text;
    }
}
