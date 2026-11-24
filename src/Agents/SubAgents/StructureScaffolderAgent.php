<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Agents\SubAgents;

use Yousef\LaraOrcest\Agents\Teams\AbstractSubAgent;
use Yousef\LaraOrcest\Contracts\ToolContract;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Tools\DirectoryScaffolderTool;

class StructureScaffolderAgent extends AbstractSubAgent
{
    public function key(): string
    {
        return 'structure_scaffolder_agent';
    }

    public function parentAgentKey(): string
    {
        return 'package_manager_agent';
    }

    public function role(): string
    {
        return 'Laravel Architecture & Directory Scaffolder';
    }

    public function goal(): string
    {
        return 'Scaffold required directory layouts (DDD/modular app/Domains/... or standard Laravel layout) and base files/routes inside targetProjectPath within PathGuard boundaries.';
    }

    public function backstory(): string
    {
        return 'System scaffolding engineer specializing in structuring clean Laravel project layouts, Domain-Driven Design boundaries, service provider scaffolding, and modular directory trees.';
    }

    public function scopePaths(): array
    {
        return [
            'app/**',
            'routes/**',
            'config/**',
            'database/**',
        ];
    }

    public function allowedTools(): array
    {
        return [
            'directory_scaffolder',
            'patch_writer',
            'file_reader',
            'codebase_inspector',
        ];
    }

    public function dependsOn(): array
    {
        return ['dependency_resolver_agent'];
    }

    protected function buildUserPrompt(RunState $state, array $context = []): string
    {
        $planJson = json_encode($context['technical_plan'] ?? $state->technicalPlan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $specJson = json_encode($context['requirements_spec'] ?? $state->requirementsSpec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return "TECHNICAL PLAN:\n{$planJson}\n\n" .
            "REQUIREMENTS SPECIFICATION:\n{$specJson}\n\n" .
            "Scaffold the required directories and base boilerplate files for the application:\n" .
            "1. Align with modular DDD (app/Domains/{Domain}/...) or standard Laravel layouts according to the technical plan.\n" .
            "2. Provide relative directory paths within allowed scope (app/**, routes/**, config/**, database/**).\n" .
            "3. Optionally provide starter files (e.g. domain README, starter service provider, or route files).\n" .
            "4. Ensure all created code follows PSR-12 and strict typing.";
    }

    protected function getOutputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'directories' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'List of relative directory paths to scaffold.',
                ],
                'files' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'path' => [
                                'type' => 'string',
                                'description' => 'Relative path of starter file.',
                            ],
                            'content' => [
                                'type' => 'string',
                                'description' => 'Initial file content.',
                            ],
                            'summary' => [
                                'type' => 'string',
                                'description' => 'Purpose of this file.',
                            ],
                        ],
                        'required' => ['path', 'content'],
                    ],
                    'description' => 'Starter or boilerplate files to create inside scaffolded directories.',
                ],
                'summary' => [
                    'type' => 'string',
                    'description' => 'Summary of scaffolded layout.',
                ],
            ],
            'required' => ['directories'],
        ];
    }

    protected function applyOutput(RunState $state, array $output, array $tools): array
    {
        /** @var DirectoryScaffolderTool|ToolContract $scaffolder */
        $scaffolder = $this->getTool('directory_scaffolder', $tools);

        $directories = (array) ($output['directories'] ?? []);
        $files = (array) ($output['files'] ?? []);

        $result = $scaffolder->execute([
            'directories' => $directories,
            'files' => $files,
        ]);

        $artifacts = [];
        foreach ((array) ($result['created_directories'] ?? []) as $dir) {
            $artifacts[] = [
                'path' => (string) $dir,
                'action' => $state->isDryRun() ? 'preview' : 'created',
                'summary' => "Scaffolded directory: {$dir}",
            ];
        }

        foreach ((array) ($result['created_files'] ?? []) as $f) {
            $artifacts[] = [
                'path' => (string) ($f['path'] ?? ''),
                'action' => $state->isDryRun() ? 'preview' : ($f['action'] ?? 'created'),
                'summary' => (string) ($f['summary'] ?? 'Starter file'),
            ];
        }

        $summary = (string) ($result['summary'] ?? ($output['summary'] ?? 'Scaffolded directories and starter files.'));

        return [
            'summary' => $summary,
            'output' => array_merge($output, [
                'scaffolded_directories' => $result['created_directories'] ?? $directories,
                'scaffolded_files' => $result['created_files'] ?? [],
            ]),
            'artifacts' => $artifacts,
        ];
    }
}
