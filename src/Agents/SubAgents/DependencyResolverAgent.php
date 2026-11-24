<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Agents\SubAgents;

use RuntimeException;
use Yousef\LaraOrcest\Agents\Teams\AbstractSubAgent;
use Yousef\LaraOrcest\Contracts\ToolContract;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Tools\ComposerRunnerTool;

class DependencyResolverAgent extends AbstractSubAgent
{
    public function key(): string
    {
        return 'dependency_resolver_agent';
    }

    public function parentAgentKey(): string
    {
        return 'package_manager_agent';
    }

    public function role(): string
    {
        return 'Composer Dependency & Package Resolver';
    }

    public function goal(): string
    {
        return 'Install and update Composer packages requested by technical_plan and requirements safely, validating package names and version constraints.';
    }

    public function backstory(): string
    {
        return 'Package manager specialist focused on resolving Composer requirements, maintaining lockfile consistency, preventing dependency conflicts, and ensuring package security.';
    }

    public function scopePaths(): array
    {
        return [
            'composer.json',
            'composer.lock',
        ];
    }

    public function allowedTools(): array
    {
        return [
            'composer_runner',
            'file_reader',
        ];
    }

    public function dependsOn(): array
    {
        return ['laravel_installer_agent'];
    }

    protected function buildUserPrompt(RunState $state, array $context = []): string
    {
        $planJson = json_encode($context['technical_plan'] ?? $state->technicalPlan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $specJson = json_encode($context['requirements_spec'] ?? $state->requirementsSpec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return "TECHNICAL PLAN:\n{$planJson}\n\n" .
            "REQUIREMENTS SPECIFICATION:\n{$specJson}\n\n" .
            "Identify all necessary Composer packages to install based on the technical plan.\n" .
            "1. Output valid package names in vendor/package format (e.g. spatie/laravel-permission).\n" .
            "2. Specify version constraints when appropriate (e.g. ^6.0).\n" .
            "3. Mark dev tooling with dev: true (e.g. testing or static analysis packages).\n" .
            "4. Provide a clear reason for each package.";
    }

    protected function getOutputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'packages' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => [
                                'type' => 'string',
                                'description' => 'Package name (e.g. spatie/laravel-permission).',
                            ],
                            'version' => [
                                'type' => 'string',
                                'description' => 'Semantic version constraint (e.g. ^6.0).',
                            ],
                            'dev' => [
                                'type' => 'boolean',
                                'description' => 'Whether this package is a development dependency.',
                            ],
                            'reason' => [
                                'type' => 'string',
                                'description' => 'Reason for installing this package.',
                            ],
                        ],
                        'required' => ['name'],
                    ],
                    'description' => 'List of Composer packages to install.',
                ],
                'summary' => [
                    'type' => 'string',
                    'description' => 'Summary of resolved packages.',
                ],
            ],
            'required' => ['packages'],
        ];
    }

    protected function applyOutput(RunState $state, array $output, array $tools): array
    {
        /** @var ComposerRunnerTool|ToolContract $composerRunner */
        $composerRunner = $this->getTool('composer_runner', $tools);

        $packages = (array) ($output['packages'] ?? []);
        $installedPackages = [];
        $artifacts = [];

        foreach ($packages as $pkg) {
            $name = trim((string) ($pkg['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            // Validate package name format: vendor/package
            if (! preg_match('/^[a-z0-9]([_.-]?[a-z0-9]+)*\/[a-z0-9]([_.-]?[a-z0-9]+)*$/i', $name)) {
                throw new RuntimeException("Invalid Composer package name format: [{$name}]");
            }

            $version = isset($pkg['version']) && trim((string) $pkg['version']) !== '' ? trim((string) $pkg['version']) : null;
            $isDev = (bool) ($pkg['dev'] ?? false);
            $reason = (string) ($pkg['reason'] ?? 'Required dependency');

            $pkgSpec = $version !== null ? "{$name}:{$version}" : $name;
            $args = [$pkgSpec, '--no-interaction'];
            if ($isDev) {
                $args[] = '--dev';
            }

            $result = $composerRunner->execute([
                'command' => 'require',
                'arguments' => $args,
            ]);

            $installedPackages[] = [
                'name' => $name,
                'version' => $version,
                'dev' => $isDev,
                'reason' => $reason,
                'result' => $result,
            ];

            $artifacts[] = [
                'path' => 'composer.json',
                'action' => $state->isDryRun() ? 'preview' : 'updated',
                'summary' => $state->isDryRun()
                    ? "[DRY-RUN] Planned require package {$pkgSpec}"
                    : "Installed package {$pkgSpec}",
            ];
        }

        $count = count($installedPackages);
        $summary = (string) ($output['summary'] ?? "Resolved and processed {$count} Composer packages.");

        return [
            'summary' => $summary,
            'output' => array_merge($output, ['installed_packages' => $installedPackages]),
            'artifacts' => $artifacts,
        ];
    }
}
