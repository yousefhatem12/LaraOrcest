<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Yousef\LaraOrcest\Config\EnvCredentialManager;
use Yousef\LaraOrcest\Console\Concerns\InteractsWithOnboarding;
use Yousef\LaraOrcest\Orchestrator;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Support\TerminalAnnouncer;
use Yousef\LaraOrcest\Workspace\WorkspaceContext;

class BuildCommand extends Command
{
    use InteractsWithOnboarding;

    protected static $defaultName = 'build';

    protected function configure(): void
    {
        $this
            ->setName('build')
            ->setDescription('Execute the multi-agent orchestration pipeline against the managed target Laravel workspace.')
            ->addArgument('prompt', InputArgument::OPTIONAL, 'Feature requirement prompt to orchestrate')
            ->addOption('prompt', null, InputOption::VALUE_OPTIONAL, 'Feature requirement prompt alternative option')
            ->addOption('target', 't', InputOption::VALUE_OPTIONAL, 'Path to target Laravel project directory')
            ->addOption('laravel-version', null, InputOption::VALUE_OPTIONAL, 'Target Laravel version constraint to install (e.g. ^12.0, ^11.0)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview changes and plans without writing to disk or Git')
            ->addOption('interactive', 'i', InputOption::VALUE_NONE, 'Enable interactive human-in-the-loop review and approval gates')
            ->addOption('no-human-review', null, InputOption::VALUE_NONE, 'Bypass human-in-the-loop review gates and execute autonomously')
            ->addOption('resume', 'r', InputOption::VALUE_OPTIONAL, 'Resume execution from an existing run ID checkpoint')
            ->addOption('approve', null, InputOption::VALUE_NONE, 'Approve a paused run when resuming')
            ->addOption('reject', null, InputOption::VALUE_NONE, 'Reject a paused run when resuming')
            ->addOption('note', null, InputOption::VALUE_OPTIONAL, 'Human review or approval feedback notes')
            ->addOption('llm', null, InputOption::VALUE_OPTIONAL, 'LLM driver to use (openai_compatible, groq, openrouter, openai, anthropic, gemini, ollama, custom, fake)')
            ->addOption('max-steps', null, InputOption::VALUE_OPTIONAL, 'Maximum graph execution steps')
            ->addOption('max-fix-attempts', null, InputOption::VALUE_OPTIONAL, 'Maximum bug-fix loop retries')
            ->addOption('no-memory', null, InputOption::VALUE_NONE, 'Bypass project memory recall and consolidation')
            ->addOption('no-scrape', null, InputOption::VALUE_NONE, 'Bypass web scraping agent even if URLs are present')
            ->addOption('swagger', null, InputOption::VALUE_NONE, 'Generate Swagger / OpenAPI documentation for backend APIs')
            ->addOption('no-swagger', null, InputOption::VALUE_NONE, 'Bypass Swagger / OpenAPI documentation generation')
            ->addOption('swagger-format', null, InputOption::VALUE_OPTIONAL, 'Output format for OpenAPI documentation (json, yaml, both)')
            ->addOption('swagger-path', null, InputOption::VALUE_OPTIONAL, 'Custom output file path for Swagger documentation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $resumeId = (string) ($input->getOption('resume') ?? '');
        $prompt = (string) ($input->getArgument('prompt') ?: $input->getOption('prompt') ?: '');
        $dryRun = (bool) $input->getOption('dry-run');

        // 1. Resolve target workspace context
        $explicitTarget = $input->getOption('target');
        $context = WorkspaceContext::detect($explicitTarget ? (string) $explicitTarget : null);
        $context->ensureDirectoriesExist();

        // 1b. Resolve or prompt for target Laravel version (Step 2)
        $explicitLaravelVersion = trim((string) ($input->getOption('laravel-version') ?? ''));
        $savedLaravelVersion = $this->readSavedLaravelVersion($context->getTargetProjectPath());
        $configuredDefaultVersion = (string) $context->getConfig()->get('package_manager.default_laravel_version', '^12.0');
        $laravelVersion = $savedLaravelVersion ?? ($configuredDefaultVersion !== '' ? $configuredDefaultVersion : '^12.0');

        if ($explicitLaravelVersion !== '') {
            $laravelVersion = $explicitLaravelVersion;
            $io->text("Target Laravel Version: <info>{$laravelVersion}</info>");
        } elseif ($resumeId === '') {
            if ($savedLaravelVersion !== null) {
                $laravelVersion = $savedLaravelVersion;
                $io->text("Target Laravel Version (from workspace config): <info>{$laravelVersion}</info>");
            } elseif ($this->canPromptInteractively($input)) {
                $laravelVersion = $this->promptLaravelVersion($io, '^12.0');
            }
        }

        if ($resumeId === '') {
            $context->getConfig()->set('package_manager.default_laravel_version', $laravelVersion);
            $context->getConfig()->set('package_manager.laravel_version', $laravelVersion);
            $this->persistLaravelVersionToWorkspaceConfig($context->getTargetProjectPath(), $laravelVersion);
        }

        // 1c. Capture project prompt interactively when omitted (Step 3)
        if ($resumeId === '' && trim($prompt) === '' && $this->canPromptInteractively($input)) {
            $promptAnswer = $io->ask('Enter your project prompt / requirements');
            $prompt = is_string($promptAnswer) ? trim($promptAnswer) : '';
        }

        if ($resumeId === '' && trim($prompt) === '') {
            $io->error('Please provide a prompt argument or use --prompt="Your requirement", or use --resume=<run_id>.');

            return Command::FAILURE;
        }

        // 2. Build execution options
        $options = [
            'dry_run' => $dryRun,
            'workspace_context' => $context,
            'target_project_path' => $context->getTargetProjectPath(),
            'workspace_mode' => $context->getWorkspaceMode(),
            'laravel_version' => $laravelVersion,
            'package_manager' => [
                'laravel_version' => $laravelVersion,
            ],
        ];

        if ($input->getOption('max-steps')) {
            $options['max_steps'] = (int) $input->getOption('max-steps');
        }

        if ($input->getOption('max-fix-attempts')) {
            $options['max_fix_attempts'] = (int) $input->getOption('max-fix-attempts');
        }

        $explicitLlm = $input->getOption('llm') ? (string) $input->getOption('llm') : null;
        $skipCredentialCheck = ($resumeId !== '' && (bool) $input->getOption('reject'));

        if (! $skipCredentialCheck) {
            $detectedDriver = EnvCredentialManager::resolveActiveDriver($explicitLlm, $context->getConfig());

            if ($dryRun && $explicitLlm === null && $detectedDriver === null) {
                $options['llm'] = 'fake';
            } elseif ($detectedDriver === 'fake') {
                $options['llm'] = 'fake';
            } elseif ($detectedDriver !== null && EnvCredentialManager::hasCredentialsForDriver($detectedDriver, $context->getConfig())) {
                $options['llm'] = $detectedDriver;
            } elseif ($this->canPromptInteractively($input)) {
                $options['llm'] = $this->promptAndPersistLlmCredentials(
                    io: $io,
                    config: $context->getConfig(),
                    targetProjectPath: $context->getTargetProjectPath(),
                    presetProvider: $explicitLlm ?? $detectedDriver
                );
            } else {
                $envPath = EnvCredentialManager::resolveTargetEnvPath($context->getTargetProjectPath());
                $io->error(EnvCredentialManager::describeMissingCredentials($explicitLlm ?? $detectedDriver, $envPath));

                return Command::FAILURE;
            }
        }

        if ($input->getOption('interactive')) {
            $options['interactive'] = true;
        }

        if ($input->getOption('no-human-review')) {
            $options['no_human_review'] = true;
            $options['skip_review'] = true;
        }

        if ($input->getOption('approve')) {
            $options['decision'] = 'approved';
        }

        if ($input->getOption('reject')) {
            $options['decision'] = 'rejected';
        }

        if ($input->getOption('note')) {
            $options['note'] = (string) $input->getOption('note');
        }

        if ($input->getOption('no-memory')) {
            $options['no_memory'] = true;
            $options['memory_enabled'] = false;
        }

        if ($input->getOption('no-scrape')) {
            $options['no_scrape'] = true;
            $options['web_scraping'] = ['enabled' => false];
        }

        if ($input->getOption('swagger')) {
            $options['swagger'] = true;
            $options['with_swagger'] = true;
        }

        if ($input->getOption('no-swagger')) {
            $options['no_swagger'] = true;
            $options['swagger'] = ['enabled' => false];
        }

        if ($input->getOption('swagger-format')) {
            $options['swagger_format'] = (string) $input->getOption('swagger-format');
        }

        if ($input->getOption('swagger-path')) {
            $options['swagger_path'] = (string) $input->getOption('swagger-path');
        }

        // Live step progress listener
        $options['on_step'] = function (RunState $state, string $node, string $message) use ($output) {
            $step = $state->guardrails['step_count'] ?? 0;
            $output->writeln(sprintf('  <fg=cyan>[Step %d]</> <options=bold>%s</> <fg=gray>%s</>', $step, $node, $message));
        };

        // 3. Instantiate Orchestrator bound to target workspace
        $orchestrator = new Orchestrator(workspaceContext: $context);

        TerminalAnnouncer::setWriter(static function (string $message) use ($output): void {
            $output->writeln($message);
        });

        try {
            if ($resumeId !== '') {
                $io->title("Resuming LaraOrcest run [{$resumeId}]" . ($dryRun ? ' [DRY-RUN MODE]' : ''));
                $io->text("Target Workspace: <info>{$context->getTargetProjectPath()}</info>");

                try {
                    $state = $orchestrator->resume($resumeId, $options);
                } catch (\Throwable $e) {
                    $io->error('Resume error: ' . $e->getMessage());

                    return Command::FAILURE;
                }
            } else {
                $io->title('Starting LaraOrcest Standalone Orchestration' . ($dryRun ? ' [DRY-RUN MODE]' : ''));
                $io->text("Prompt: <comment>{$prompt}</comment>");
                $io->text("Target Workspace: <info>{$context->getTargetProjectPath()}</info>");
                $io->newLine();

                try {
                    $state = $orchestrator->run($prompt, $options);
                } catch (\Throwable $e) {
                    $io->error('Execution error: ' . $e->getMessage());

                    return Command::FAILURE;
                }
            }
        } finally {
            TerminalAnnouncer::setWriter(null);
        }

        $io->newLine();

        // 4. Handle Human-in-the-Loop Paused State
        if ($state->isPaused()) {
            $pausedGate = $state->getPausedGate() ?? 'review_gate';
            $io->warning("⏸ LaraOrcest paused for human review at gate: [{$pausedGate}] [Run ID: {$state->runId}]");

            $pauseContext = $state->getPauseContext();
            if (! empty($pauseContext['summary'])) {
                $io->text("<comment>Review Summary:</comment> {$pauseContext['summary']}");
            }
            if (! empty($pauseContext['artifacts'])) {
                $io->text('<comment>Proposed Artifacts:</comment> ' . implode(', ', (array) $pauseContext['artifacts']));
            }
            if (! empty($pauseContext['backlog_count'])) {
                $io->text("<comment>Planned Tasks:</comment> {$pauseContext['backlog_count']} tasks");
            }

            $io->newLine();
            $io->section('To approve and resume execution:');
            $io->text("  <info>bin/lara-orcest approve {$state->runId}</info>");
            $io->text("  <info>bin/lara-orcest build --resume={$state->runId} --approve</info>");
            $io->newLine();
            $io->section('To reject and halt:');
            $io->text("  <fg=red>bin/lara-orcest approve {$state->runId} --reject --note=\"Reason\"</>");

            return Command::SUCCESS;
        }

        // 5. Output Summary Results
        if ($state->isCompleted()) {
            $io->success("✔ LaraOrcest completed successfully! [Run ID: {$state->runId}]");
        } else {
            $io->warning("⚠ LaraOrcest terminated with status: {$state->status} [Run ID: {$state->runId}]");
            if ($state->getFailureReason()) {
                $io->text("<fg=red>Reason:</> {$state->getFailureReason()}");
            }
        }

        // Display Target Path and Mode
        $io->listing([
            "Target Project Path: {$state->getTargetProjectPath()}",
            "Workspace Mode: {$state->getWorkspaceMode()}",
        ]);

        // Display Package Manager Disclosure & Summary
        $pmData = $state->getPackageManager();
        if (! empty($pmData)) {
            $io->section('Package Manager & Dependencies');
            $pmLines = [
                'Target Laravel Version: ' . ($pmData['target_laravel_version'] ?? 'default'),
                'Prompt Specification: ' . ($pmData['prompt_specification'] ?? $state->prompt),
            ];
            if (! empty($pmData['target_packages'])) {
                $pmLines[] = 'Target Packages: ' . implode(', ', (array) $pmData['target_packages']);
            }
            if (! empty($pmData['scaffolded_directories'])) {
                $pmLines[] = 'Scaffolded Directories: ' . implode(', ', (array) $pmData['scaffolded_directories']);
            }
            $io->listing($pmLines);
        }

        // Display Tasks
        if (! empty($state->backlog)) {
            $io->section('Backlog Tasks (' . count($state->backlog) . ' planned)');
            $rows = [];
            foreach ($state->backlog as $task) {
                $rows[] = [
                    $task['id'] ?? 'N/A',
                    $task['title'] ?? '',
                    $task['priority'] ?? 1,
                    count($task['acceptance_criteria'] ?? []),
                ];
            }
            $table = new Table($output);
            $table->setHeaders(['ID', 'Title', 'Priority', 'Criteria Count'])->setRows($rows)->render();
        }

        // Display Generated Artifacts
        if (! empty($state->artifacts)) {
            $io->newLine();
            $io->section('Generated Artifacts (' . count($state->artifacts) . ' files)');
            $artifactRows = [];
            foreach ($state->artifacts as $art) {
                $artifactRows[] = [
                    $art['path'] ?? '',
                    $art['action'] ?? '',
                    $art['summary'] ?? '',
                ];
            }
            $table = new Table($output);
            $table->setHeaders(['Path', 'Action', 'Summary'])->setRows($artifactRows)->render();
        }

        // Display Swagger Documentation
        if ($state->hasSwaggerDocs()) {
            $io->newLine();
            $endpoints = $state->getSwaggerEndpoints();
            $io->section('Swagger / OpenAPI Documentation (' . count($endpoints) . ' endpoints documented)');
            $summary = $state->getSwaggerSummary();
            if ($summary !== null && $summary !== '') {
                $io->text("Summary: {$summary}");
            }
            $outputPaths = $state->getSwaggerOutputPaths();
            if (! empty($outputPaths)) {
                $io->text('Documentation file(s): ' . implode(', ', $outputPaths));
            }
        }

        // Display Test Outcomes
        if (! empty($state->testResults)) {
            $io->newLine();
            $passText = $state->areTestsPassing() ? '<fg=green>PASSED</>' : '<fg=red>FAILED</>';
            $io->section("Automated Tests: {$passText}");
            $io->text('Summary: ' . ($state->testResults['summary'] ?? 'N/A'));
        }

        // Display Review Determination
        $latestReview = $state->getLatestReview();
        if ($latestReview !== null) {
            $io->newLine();
            $decisionText = ($latestReview['decision'] ?? '') === 'approved' ? '<fg=green>APPROVED</>' : '<fg=yellow>REVISE</>';
            $io->section("Reviewer Sign-off: {$decisionText}");
            if (! empty($latestReview['findings'])) {
                foreach ($latestReview['findings'] as $finding) {
                    $io->text(" - {$finding}");
                }
            }
        }

        // Display Git State
        if (! empty($state->gitState['feature_branch'])) {
            $io->newLine();
            $io->text("<options=bold>Git Feature Branch:</> <info>{$state->gitState['feature_branch']}</info>");
        }

        // Guardrail and Token Stats
        $tokens = $state->guardrails['token_usage'] ?? [];
        $totalTokens = $tokens['total_tokens'] ?? 0;
        $steps = $state->guardrails['step_count'] ?? 0;
        $fixAttempts = $state->guardrails['fix_attempts'] ?? 0;

        $io->newLine();
        $io->listing([
            "Steps Taken: {$steps} / {$state->guardrails['max_steps']}",
            "Fix Attempts: {$fixAttempts} / {$state->guardrails['max_fix_attempts']}",
            'Total Tokens Used: ' . number_format($totalTokens),
        ]);

        return $state->isCompleted() ? Command::SUCCESS : Command::FAILURE;
    }
}
