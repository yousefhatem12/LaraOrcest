<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Yousef\LaraOrcest\Config\EnvCredentialManager;
use Yousef\LaraOrcest\Config\OrchestratorConfig;
use Yousef\LaraOrcest\Console\Concerns\InteractsWithOnboarding;
use Yousef\LaraOrcest\Workspace\WorkspaceContext;

class InitCommand extends Command
{
    use InteractsWithOnboarding;

    protected static $defaultName = 'init';

    protected function configure(): void
    {
        $this
            ->setName('init')
            ->setDescription('Initialize a LaraOrcest workspace configuration pointing to a target Laravel project directory.')
            ->addArgument('project-dir', InputArgument::OPTIONAL, 'Target Laravel project directory path (default: current directory)', '.')
            ->addOption('target', 't', InputOption::VALUE_OPTIONAL, 'Explicit target Laravel project directory path')
            ->addOption('laravel-version', null, InputOption::VALUE_OPTIONAL, 'Target Laravel version constraint to install (e.g. ^12.0, ^11.0)')
            ->addOption('format', 'f', InputOption::VALUE_OPTIONAL, 'Config file format (json or php)', 'json')
            ->addOption('create', 'c', InputOption::VALUE_NONE, 'Create target directory if it does not exist')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite existing configuration file if present')
            ->addOption('configure-llm', null, InputOption::VALUE_NONE, 'Interactively configure LLM provider and API credentials in .env');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $rawTarget = (string) ($input->getOption('target') ?: $input->getArgument('project-dir') ?: '.');
        $format = strtolower((string) ($input->getOption('format') ?: 'json'));
        $force = (bool) $input->getOption('force');
        $create = (bool) $input->getOption('create');

        // Resolve absolute target path
        if (! is_dir($rawTarget)) {
            if (! $create && $rawTarget !== '.') {
                $io->error("Target directory [{$rawTarget}] does not exist.");

                return Command::FAILURE;
            }
            @mkdir($rawTarget, 0755, true);
        }

        $canonicalTarget = realpath($rawTarget);
        if ($canonicalTarget === false) {
            $canonicalTarget = rtrim($rawTarget, DIRECTORY_SEPARATOR);
        }

        $io->title('LaraOrcest Workspace Initialization');

        // Check if target directory appears to be a Laravel application
        $hasArtisan = file_exists($canonicalTarget . DIRECTORY_SEPARATOR . 'artisan');
        $hasComposer = file_exists($canonicalTarget . DIRECTORY_SEPARATOR . 'composer.json');

        if (! $hasArtisan && ! $hasComposer) {
            $io->note("Target path [{$canonicalTarget}] does not contain an 'artisan' or 'composer.json' file yet. LaraOrcest will manage and scaffold it.");
        } else {
            $io->text("Discovered existing Laravel workspace at: <info>{$canonicalTarget}</info>");
        }

        $explicitVersion = trim((string) ($input->getOption('laravel-version') ?? ''));
        $savedVersion = $this->readSavedLaravelVersion($canonicalTarget);
        $defaultVersion = $savedVersion ?? '^12.0';

        if ($explicitVersion !== '') {
            $laravelVersion = $explicitVersion;
            $io->text("Target Laravel Version: <info>{$laravelVersion}</info>");
        } elseif ($this->canPromptInteractively($input)) {
            $laravelVersion = $this->promptLaravelVersion($io, $defaultVersion);
        } else {
            $laravelVersion = $defaultVersion;
        }

        // Initialize target directories
        $targetOrcestDir = $canonicalTarget . DIRECTORY_SEPARATOR . '.lara-orcest';
        if (! is_dir($targetOrcestDir)) {
            @mkdir($targetOrcestDir, 0755, true);
        }

        $stateDir = $targetOrcestDir . DIRECTORY_SEPARATOR . 'state';
        if (! is_dir($stateDir)) {
            @mkdir($stateDir, 0755, true);
        }

        $gitignore = $targetOrcestDir . DIRECTORY_SEPARATOR . '.gitignore';
        if (! file_exists($gitignore)) {
            @file_put_contents($gitignore, "# Ignore local run state and memory\n*.json\nstate/\ncheckpoints/\n");
        }

        $configData = [
            'target_path' => $canonicalTarget,
            'workspace_mode' => WorkspaceContext::MODE_STANDALONE,
            'created_at' => date('c'),
            'package_manager' => [
                'default_laravel_version' => $laravelVersion,
                'laravel_version' => $laravelVersion,
            ],
            'orchestration' => [
                'max_steps' => 40,
                'max_fix_attempts' => 3,
                'dry_run' => false,
            ],
            'tools' => [
                'process_timeout' => 120,
            ],
        ];

        $targetConfigFile = $targetOrcestDir . DIRECTORY_SEPARATOR . ($format === 'php' ? 'config.php' : 'config.json');

        if (file_exists($targetConfigFile) && ! $force) {
            $io->warning("Workspace configuration already exists at [{$targetConfigFile}]. Use --force to overwrite.");
        } else {
            if ($format === 'php') {
                $export = var_export($configData, true);
                $content = "<?php\n\ndeclare(strict_types=1);\n\nreturn {$export};\n";
            } else {
                $content = (string) json_encode($configData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            }

            @file_put_contents($targetConfigFile, $content);
            $io->success("Workspace initialized successfully! Configuration written to [{$targetConfigFile}]");
        }

        // Also place a config copy inside current directory if different and requested
        $cwd = getcwd() ?: '.';
        if (realpath($cwd) !== $canonicalTarget && file_exists($cwd)) {
            $cwdOrcestDir = $cwd . DIRECTORY_SEPARATOR . '.lara-orcest';
            if (is_dir($cwdOrcestDir)) {
                $cwdConfigFile = $cwdOrcestDir . DIRECTORY_SEPARATOR . ($format === 'php' ? 'config.php' : 'config.json');
                if (! file_exists($cwdConfigFile) || $force) {
                    if ($format === 'php') {
                        $export = var_export($configData, true);
                        $content = "<?php\n\ndeclare(strict_types=1);\n\nreturn {$export};\n";
                    } else {
                        $content = (string) json_encode($configData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                    }
                    @file_put_contents($cwdConfigFile, $content);
                }
            }
        }

        $io->listing([
            "Target Laravel Path: {$canonicalTarget}",
            "Target Laravel Version: {$laravelVersion}",
            "Workspace Mode: standalone",
            "State Directory: {$stateDir}",
            "Config File: {$targetConfigFile}",
        ]);

        // Load any existing .env files and optionally configure LLM credentials
        $config = OrchestratorConfig::load($canonicalTarget);
        $activeDriver = EnvCredentialManager::resolveActiveDriver(null, $config);
        $hasCredentials = $activeDriver !== null && EnvCredentialManager::hasCredentialsForDriver($activeDriver, $config);

        if ((bool) $input->getOption('configure-llm')) {
            if (! $hasCredentials || $force) {
                if ($this->canPromptInteractively($input)) {
                    $this->promptAndPersistLlmCredentials($io, $config, $canonicalTarget);
                } else {
                    $envPath = EnvCredentialManager::resolveTargetEnvPath($canonicalTarget);
                    $io->error(EnvCredentialManager::describeMissingCredentials($activeDriver, $envPath));

                    return Command::FAILURE;
                }
            } else {
                $io->text("Detected existing LLM credentials for provider [<info>{$activeDriver}</info>].");
            }
        } elseif (! $hasCredentials) {
            $io->note('No LLM API key detected in .env yet. Run `lara-orcest build` or `lara-orcest init --configure-llm` to configure your provider and API key interactively.');
        }

        $io->text('You can now run orchestration commands against this workspace:');
        $io->text("  <info>lara-orcest build \"Build a REST API for invoices\" --target=\"{$canonicalTarget}\"</info>");

        return Command::SUCCESS;
    }
}
