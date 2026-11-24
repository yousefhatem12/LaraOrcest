<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Agents;

use Yousef\LaraOrcest\Contracts\LlmDriverContract;
use Yousef\LaraOrcest\Contracts\ToolContract;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Support\Config;
use Yousef\LaraOrcest\Tools\ComposerRunnerTool;
use Yousef\LaraOrcest\Tools\DirectoryScaffolderTool;

class PackageManagerAgent extends AbstractAgent
{
    public function getName(): string
    {
        return 'package_manager_agent';
    }

    public function getRole(): string
    {
        return 'Laravel Package Manager & Project Bootstrapper';
    }

    public function getGoal(): string
    {
        return 'Bootstrap target Laravel project, install required Composer packages, and scaffold initial directories and foundational structure according to the technical plan.';
    }

    public function getBackstory(): string
    {
        return 'Senior Laravel ecosystem specialist and DevOps lead who manages project bootstrapping, dependency resolution, lockfile hygiene, and directory layout scaffolding.';
    }

    public function getAllowedTools(): array
    {
        return [
            'composer_runner',
            'directory_scaffolder',
            'file_reader',
            'patch_writer',
            'codebase_inspector',
            'artisan_runner',
        ];
    }

    public function getStateOutputKey(): string
    {
        return 'artifacts';
    }

    public function execute(RunState $state, array $tools, LlmDriverContract $llm): RunState
    {
        $teamsEnabled = (bool) Config::get('lara-orcest.teams.enabled', true);
        $subRegistry = $this->getTeamDelegator()->getSubAgentRegistry();

        $subKeys = [
            'laravel_installer_agent',
            'dependency_resolver_agent',
            'structure_scaffolder_agent',
        ];

        $hasAllSubAgents = true;
        foreach ($subKeys as $k) {
            if (! $subRegistry->has($k)) {
                $hasAllSubAgents = false;
                break;
            }
        }

        if ($teamsEnabled && $hasAllSubAgents) {
            try {
                $activeSkills = [
                    'laravel-project-bootstrapping',
                    'composer-dependency-management',
                    'laravel-directory-scaffolding',
                ];

                $context = [
                    'requirements_spec' => $state->requirementsSpec,
                    'backlog' => $state->backlog,
                    'technical_plan' => $state->technicalPlan,
                    'prompt' => $state->prompt,
                    'target_project_path' => $state->targetProjectPath,
                    'workspace_mode' => $state->workspaceMode,
                    'project_memory' => $this->resolveProjectMemoryContext($state),
                ];

                $delegationResult = $this->delegateSubTasks(
                    state: $state,
                    subAgentKeys: $subKeys,
                    context: $context,
                    tools: $tools,
                    llm: $llm,
                    activeSkillNames: $activeSkills
                );

                $newState = $delegationResult->state;
                $subOutputs = $delegationResult->results;

                $installerOutput = (array) ($subOutputs['laravel_installer_agent']['output'] ?? []);
                $resolverOutput = (array) ($subOutputs['dependency_resolver_agent']['output'] ?? []);
                $scaffolderOutput = (array) ($subOutputs['structure_scaffolder_agent']['output'] ?? []);

                $pmData = [
                    'status' => 'completed',
                    'bootstrapped' => true,
                    'installation' => $installerOutput,
                    'packages' => $resolverOutput['installed_packages'] ?? [],
                    'scaffolded_directories' => $scaffolderOutput['scaffolded_directories'] ?? [],
                    'scaffolded_files' => $scaffolderOutput['scaffolded_files'] ?? [],
                    'delegations' => array_keys($delegationResult->results),
                    'completed_at' => date('c'),
                ];

                return $newState->withPackageManager($pmData);
            } catch (\Throwable) {
                // Fallback gracefully to single-agent execution
            }
        }

        return parent::execute($state, $tools, $llm);
    }

    protected function buildUserPrompt(RunState $state): string
    {
        $planJson = json_encode($state->technicalPlan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $specJson = json_encode($state->requirementsSpec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $targetPath = $state->targetProjectPath ?? 'current directory';

        $parts = [
            "TECHNICAL PLAN:\n{$planJson}",
            "REQUIREMENTS SPECIFICATION:\n{$specJson}",
            "TARGET PROJECT PATH: {$targetPath}",
            "Please bootstrap the target Laravel application, resolve and install required Composer packages, and scaffold the modular/standard directory structures.",
        ];

        return implode("\n\n", $parts);
    }

    protected function getOutputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'bootstrap' => [
                    'type' => 'object',
                    'properties' => [
                        'status' => ['type' => 'string'],
                        'action' => ['type' => 'string'],
                        'summary' => ['type' => 'string'],
                    ],
                    'required' => ['status', 'summary'],
                ],
                'packages' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'version' => ['type' => 'string'],
                            'dev' => ['type' => 'boolean'],
                            'reason' => ['type' => 'string'],
                        ],
                        'required' => ['name'],
                    ],
                ],
                'directories' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'files' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'path' => ['type' => 'string'],
                            'content' => ['type' => 'string'],
                            'summary' => ['type' => 'string'],
                        ],
                        'required' => ['path', 'content'],
                    ],
                ],
                'summary' => [
                    'type' => 'string',
                ],
            ],
            'required' => ['bootstrap', 'packages', 'directories', 'summary'],
        ];
    }

    protected function applyOutput(RunState $state, array $output, array $tools): RunState
    {
        $targetPath = $state->targetProjectPath;
        $isExisting = false;

        if ($targetPath !== null && is_dir($targetPath)) {
            $hasComposer = file_exists($targetPath . DIRECTORY_SEPARATOR . 'composer.json');
            $hasArtisan = file_exists($targetPath . DIRECTORY_SEPARATOR . 'artisan');
            if ($hasComposer || $hasArtisan) {
                $isExisting = true;
            }
        }

        $artifacts = [];

        // 1. Bootstrap
        if (isset($tools['composer_runner'])) {
            /** @var ComposerRunnerTool|ToolContract $composerRunner */
            $composerRunner = $tools['composer_runner'];
            if (! $isExisting) {
                $composerRunner->execute([
                    'command' => 'create-project',
                    'arguments' => ['laravel/laravel', '.', '--prefer-dist', '--no-interaction'],
                ]);
                $artifacts[] = [
                    'path' => 'composer.json',
                    'action' => $state->isDryRun() ? 'preview' : 'created',
                    'summary' => 'Bootstrapped fresh Laravel application skeleton',
                ];
            } else {
                $artifacts[] = [
                    'path' => 'composer.json',
                    'action' => 'verified',
                    'summary' => 'Verified existing Laravel project in target directory',
                ];
            }
        }

        // 2. Packages
        $installedPackages = [];
        if (isset($tools['composer_runner'])) {
            /** @var ComposerRunnerTool|ToolContract $composerRunner */
            $composerRunner = $tools['composer_runner'];
            foreach ((array) ($output['packages'] ?? []) as $pkg) {
                $name = trim((string) ($pkg['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $version = isset($pkg['version']) ? trim((string) $pkg['version']) : null;
                $isDev = (bool) ($pkg['dev'] ?? false);
                $pkgSpec = $version !== null ? "{$name}:{$version}" : $name;

                $args = [$pkgSpec, '--no-interaction'];
                if ($isDev) {
                    $args[] = '--dev';
                }

                $res = $composerRunner->execute([
                    'command' => 'require',
                    'arguments' => $args,
                ]);

                $installedPackages[] = [
                    'name' => $name,
                    'version' => $version,
                    'dev' => $isDev,
                    'result' => $res,
                ];

                $artifacts[] = [
                    'path' => 'composer.json',
                    'action' => $state->isDryRun() ? 'preview' : 'updated',
                    'summary' => "Required package {$pkgSpec}",
                ];
            }
        }

        // 3. Scaffolding
        $scaffoldedDirs = [];
        $scaffoldedFiles = [];
        if (isset($tools['directory_scaffolder'])) {
            /** @var DirectoryScaffolderTool|ToolContract $scaffolder */
            $scaffolder = $tools['directory_scaffolder'];
            $scaffoldRes = $scaffolder->execute([
                'directories' => (array) ($output['directories'] ?? []),
                'files' => (array) ($output['files'] ?? []),
            ]);

            $scaffoldedDirs = (array) ($scaffoldRes['created_directories'] ?? []);
            $scaffoldedFiles = (array) ($scaffoldRes['created_files'] ?? []);

            foreach ($scaffoldedDirs as $dir) {
                $artifacts[] = [
                    'path' => (string) $dir,
                    'action' => $state->isDryRun() ? 'preview' : 'created',
                    'summary' => "Scaffolded directory: {$dir}",
                ];
            }

            foreach ($scaffoldedFiles as $f) {
                $artifacts[] = [
                    'path' => (string) ($f['path'] ?? ''),
                    'action' => $state->isDryRun() ? 'preview' : ($f['action'] ?? 'created'),
                    'summary' => (string) ($f['summary'] ?? 'Starter file'),
                ];
            }
        }

        $currentState = $state;
        foreach ($artifacts as $art) {
            $currentState = $currentState->recordArtifact(
                path: $art['path'],
                action: $art['action'],
                summary: $art['summary'],
                agent: $this->getName()
            );
        }

        $pmData = [
            'status' => 'completed',
            'bootstrapped' => true,
            'is_existing' => $isExisting,
            'packages' => $installedPackages,
            'scaffolded_directories' => $scaffoldedDirs,
            'scaffolded_files' => $scaffoldedFiles,
            'summary' => (string) ($output['summary'] ?? 'Package manager executed successfully in single-agent fallback mode.'),
            'completed_at' => date('c'),
        ];

        return $currentState->withPackageManager($pmData);
    }
}
