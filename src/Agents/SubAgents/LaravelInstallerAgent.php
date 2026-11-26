<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Agents\SubAgents;

use Yousef\LaraOrcest\Agents\Teams\AbstractSubAgent;
use Yousef\LaraOrcest\Contracts\ToolContract;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Support\Config;
use Yousef\LaraOrcest\Support\PackageSpecResolver;
use Yousef\LaraOrcest\Support\TerminalAnnouncer;
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

    public function skills(): array
    {
        return ['laravel-project-bootstrapping'];
    }

    protected function buildUserPrompt(RunState $state, array $context = []): string
    {
        $promptSpec = (string) ($context['prompt'] ?? $state->prompt);
        $planJson = json_encode($context['technical_plan'] ?? $state->technicalPlan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $specJson = json_encode($context['requirements_spec'] ?? $state->requirementsSpec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $targetPath = (string) ($context['target_project_path'] ?? $state->targetProjectPath ?? Config::basePath());
        $targetVersion = (string) ($context['target_laravel_version'] ?? PackageSpecResolver::resolveTargetLaravelVersion($state));

        return "USER PROMPT / TASK SPECIFICATION:\n{$promptSpec}\n\n" .
            "TARGET LARAVEL VERSION: laravel/laravel:{$targetVersion}\n" .
            "TARGET PROJECT PATH: {$targetPath}\n\n" .
            "TECHNICAL PLAN:\n{$planJson}\n\n" .
            "REQUIREMENTS SPECIFICATION:\n{$specJson}\n\n" .
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

        $targetPath = $state->targetProjectPath ?? Config::basePath();
        $isExisting = false;

        if ($targetPath !== '' && is_dir($targetPath)) {
            $hasComposer = file_exists($targetPath . DIRECTORY_SEPARATOR . 'composer.json');
            $hasArtisan = file_exists($targetPath . DIRECTORY_SEPARATOR . 'artisan');
            if ($hasComposer || $hasArtisan) {
                $isExisting = true;
            }
        }

        $targetVersion = PackageSpecResolver::resolveTargetLaravelVersion($state);
        $installedConstraint = $isExisting ? PackageSpecResolver::detectInstalledLaravelVersion($targetPath) : null;
        $createProjectTarget = PackageSpecResolver::resolveCreateProjectTarget($targetVersion);
        $artifacts = [];
        $composerResult = null;

        if ($isExisting) {
            $status = 'already_initialized';
            $action = 'verify_existing';
            $detectedNote = $installedConstraint !== null ? " (detected laravel/framework:{$installedConstraint})" : '';
            $summary = "Target workspace is already initialized with an existing Laravel application. Verified skeleton [laravel/laravel:{$targetVersion}]{$detectedNote}. Skipped create-project.";

            TerminalAnnouncer::announce("  [laravel_installer_agent] Existing Laravel application detected in [{$targetPath}]. Verified skeleton [laravel/laravel:{$targetVersion}]{$detectedNote}.");

            $artifacts[] = [
                'path' => 'composer.json',
                'action' => 'verified',
                'summary' => "Verified existing Laravel project installation [laravel/laravel:{$targetVersion}]{$detectedNote} in target workspace",
            ];
        } else {
            $status = 'initialized';
            $action = 'bootstrap_project';
            $summary = (string) ($output['summary'] ?? "Bootstrapped fresh Laravel application structure [laravel/laravel:{$targetVersion}].");

            TerminalAnnouncer::announce("  [laravel_installer_agent] Target Version: Preparing to install Laravel Skeleton [laravel/laravel:{$targetVersion}] into [{$targetPath}]");
            TerminalAnnouncer::announce("  [laravel_installer_agent] Executing: composer create-project {$createProjectTarget} . --prefer-dist --no-interaction");

            // Execute composer create-project
            $composerResult = $composerRunner->execute([
                'command' => 'create-project',
                'arguments' => [$createProjectTarget, '.', '--prefer-dist', '--no-interaction'],
            ]);

            $artifacts[] = [
                'path' => 'composer.json',
                'action' => $state->isDryRun() ? 'preview' : 'created',
                'summary' => $state->isDryRun()
                    ? "[DRY-RUN] Previewed composer create-project {$createProjectTarget}"
                    : "Created fresh Laravel application skeleton [{$createProjectTarget}] via composer create-project",
            ];
        }

        $resolvedOutput = array_merge($output, [
            'status' => $status,
            'action' => $action,
            'summary' => $summary,
            'target_laravel_version' => $targetVersion,
            'detected_laravel_version' => $installedConstraint,
            'prompt_specification' => $state->prompt,
            'target_project_path' => $targetPath,
            'is_existing' => $isExisting,
            'composer_result' => $composerResult,
        ]);

        return [
            'summary' => $summary,
            'output' => $resolvedOutput,
            'artifacts' => $artifacts,
        ];
    }
}
