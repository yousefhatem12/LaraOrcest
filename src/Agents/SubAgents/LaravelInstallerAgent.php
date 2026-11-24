<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Agents\SubAgents;

use Yousef\LaraOrcest\Agents\Teams\AbstractSubAgent;
use Yousef\LaraOrcest\Contracts\ToolContract;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Tools\ComposerRunnerTool;

class LaravelInstallerAgent extends AbstractSubAgent
{
    public function key(): string
    {
        return 'laravel_installer_agent';
    }

    public function parentAgentKey(): string
    {
        return 'package_manager_agent';
    }

    public function role(): string
    {
        return 'Laravel Installation & Environment Specialist';
    }

    public function goal(): string
    {
        return 'Bootstrap a clean, idiomatic Laravel project inside targetProjectPath if not already present, initialize .env and generate APP_KEY safely, and verify environment readiness.';
    }

    public function backstory(): string
    {
        return 'DevOps and Laravel foundational engineer responsible for initializing pristine Laravel applications, ensuring directory idempotency, and generating safe local environment configurations.';
    }

    public function scopePaths(): array
    {
        return [
            'composer.json',
            '.env.example',
            'artisan',
            'bootstrap/**',
            'config/**',
            'public/**',
            'storage/**',
            'database/**',
        ];
    }

    public function allowedTools(): array
    {
        return [
            'composer_runner',
            'file_reader',
            'patch_writer',
            'artisan_runner',
            'codebase_inspector',
        ];
    }

    public function dependsOn(): array
    {
        return [];
    }

    protected function buildUserPrompt(RunState $state, array $context = []): string
    {
        $planJson = json_encode($context['technical_plan'] ?? $state->technicalPlan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $specJson = json_encode($context['requirements_spec'] ?? $state->requirementsSpec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $targetPath = $state->targetProjectPath ?? 'current directory';

        return "TECHNICAL PLAN:\n{$planJson}\n\n" .
            "REQUIREMENTS SPECIFICATION:\n{$specJson}\n\n" .
            "TARGET PROJECT PATH: {$targetPath}\n\n" .
            "Evaluate and bootstrap the target Laravel project:\n" .
            "1. Inspect if Laravel is already installed (composer.json or artisan present).\n" .
            "2. If not initialized, specify the command to bootstrap (composer create-project laravel/laravel .).\n" .
            "3. Specify safe environment defaults (APP_NAME, APP_ENV, APP_KEY generation).\n" .
            "4. Never output or leak real secret credentials.";
    }

    protected function getOutputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => [
                    'type' => 'string',
                    'enum' => ['initialized', 'already_initialized', 'skipped'],
                    'description' => 'Installation status.',
                ],
                'action' => [
                    'type' => 'string',
                    'description' => 'Action performed or planned (e.g. bootstrap_project, verify_existing).',
                ],
                'composer_command' => [
                    'type' => 'string',
                    'description' => 'The composer command executed or planned.',
                ],
                'environment_defaults' => [
                    'type' => 'object',
                    'description' => 'Key-value pairs for environment configuration.',
                ],
                'summary' => [
                    'type' => 'string',
                    'description' => 'Summary of bootstrap results.',
                ],
            ],
            'required' => ['status', 'action', 'summary'],
        ];
    }

    protected function applyOutput(RunState $state, array $output, array $tools): array
    {
        /** @var ComposerRunnerTool|ToolContract $composerRunner */
        $composerRunner = $this->getTool('composer_runner', $tools);

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

        if ($isExisting) {
            $status = 'already_initialized';
            $action = 'verify_existing';
            $summary = 'Target workspace is already initialized with an existing Laravel application. Skipped create-project.';

            $artifacts[] = [
                'path' => 'composer.json',
                'action' => 'verified',
                'summary' => 'Verified existing Laravel project installation in target workspace',
            ];
        } else {
            $status = 'initialized';
            $action = 'bootstrap_project';
            $summary = (string) ($output['summary'] ?? 'Bootstrapped fresh Laravel application structure.');

            // Execute composer create-project
            $composerResult = $composerRunner->execute([
                'command' => 'create-project',
                'arguments' => ['laravel/laravel', '.', '--prefer-dist', '--no-interaction'],
            ]);

            $artifacts[] = [
                'path' => 'composer.json',
                'action' => $state->isDryRun() ? 'preview' : 'created',
                'summary' => $state->isDryRun()
                    ? '[DRY-RUN] Previewed composer create-project laravel/laravel'
                    : 'Created fresh Laravel application skeleton via composer create-project',
            ];
        }

        $resolvedOutput = array_merge($output, [
            'status' => $status,
            'action' => $action,
            'summary' => $summary,
            'is_existing' => $isExisting,
        ]);

        return [
            'summary' => $summary,
            'output' => $resolvedOutput,
            'artifacts' => $artifacts,
        ];
    }
}
