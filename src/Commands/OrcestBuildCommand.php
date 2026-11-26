<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Commands;

use Illuminate\Console\Command;
use Yousef\LaraOrcest\Orchestrator;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Support\TerminalAnnouncer;

class OrcestBuildCommand extends Command
{
    protected $signature = 'orcest:build 
                            {prompt? : Feature requirement or prompt to orchestrate}
                            {--prompt= : Feature requirement or prompt (optional flag alternative)}
                            {--resume= : Resume execution from an existing run ID checkpoint}
                            {--interactive : Enable interactive human-in-the-loop review and approval gates}
                            {--no-human-review : Bypass human-in-the-loop review and execute autonomously}
                            {--approve : Approve a paused run when resuming}
                            {--reject : Reject a paused run when resuming}
                            {--note= : Human review or approval notes}
                            {--no-memory : Bypass project memory recall and consolidation}
                            {--no-scrape : Bypass web scraping agent even if URLs are present}
                            {--swagger : Generate Swagger / OpenAPI documentation for backend APIs}
                            {--no-swagger : Bypass Swagger / OpenAPI documentation generation}
                            {--swagger-format= : Output format for OpenAPI documentation (json, yaml, both)}
                            {--swagger-path= : Custom output file path for Swagger documentation}
                            {--dry-run : Preview changes and plans without writing to disk or Git}
                            {--max-fix-attempts= : Maximum bug-fix loop retries}
                            {--max-steps= : Maximum graph execution steps}
                            {--llm= : LLM driver to use (openai_compatible, fake, openai, anthropic, gemini, ollama)}';

    protected $description = 'Orchestrate prompt-driven multi-agent development for Laravel';

    public function handle(Orchestrator $orchestrator): int
    {
        $resumeId = (string) ($this->option('resume') ?? '');
        $dryRun = (bool) $this->option('dry-run');

        $options = [
            'dry_run' => $dryRun,
        ];

        if ($this->option('max-fix-attempts')) {
            $options['max_fix_attempts'] = (int) $this->option('max-fix-attempts');
        }

        if ($this->option('max-steps')) {
            $options['max_steps'] = (int) $this->option('max-steps');
        }

        if ($this->option('llm')) {
            $options['llm'] = (string) $this->option('llm');
        }

        if ($this->option('interactive')) {
            $options['interactive'] = true;
        }

        if ($this->option('no-human-review')) {
            $options['no_human_review'] = true;
            $options['skip_review'] = true;
        }

        if ($this->option('approve')) {
            $options['decision'] = 'approved';
        }

        if ($this->option('reject')) {
            $options['decision'] = 'rejected';
        }

        if ($this->option('note')) {
            $options['note'] = (string) $this->option('note');
        }

        if ($this->option('no-memory')) {
            $options['no_memory'] = true;
            $options['memory_enabled'] = false;
        }

        if ($this->option('no-scrape')) {
            $options['no_scrape'] = true;
            $options['web_scraping'] = ['enabled' => false];
        }

        if ($this->option('swagger')) {
            $options['swagger'] = true;
            $options['with_swagger'] = true;
        }

        if ($this->option('no-swagger')) {
            $options['no_swagger'] = true;
            $options['swagger'] = ['enabled' => false];
        }

        if ($this->option('swagger-format')) {
            $options['swagger_format'] = (string) $this->option('swagger-format');
        }

        if ($this->option('swagger-path')) {
            $options['swagger_path'] = (string) $this->option('swagger-path');
        }

        $options['on_step'] = function (RunState $state, string $node, string $message) {
            $step = $state->guardrails['step_count'] ?? 0;
            $this->components->twoColumnDetail(
                "<fg=cyan>[Step {$step}]</> <options=bold>{$node}</>",
                "<fg=gray>{$message}</>"
            );
        };

        TerminalAnnouncer::setWriter(function (string $message): void {
            $this->line($message);
        });

        try {
            if ($resumeId !== '') {
                $this->components->info("Resuming LaraOrcest run [{$resumeId}]" . ($dryRun ? " [DRY-RUN MODE]" : ""));

                try {
                    $state = $orchestrator->resume($resumeId, $options);
                } catch (\Throwable $e) {
                    $this->components->error("Resume error: " . $e->getMessage());

                    return self::FAILURE;
                }
            } else {
                $prompt = (string) ($this->argument('prompt') ?: $this->option('prompt'));
                if (trim($prompt) === '') {
                    $this->components->error('Please provide a prompt argument or use --prompt="Your requirement".');

                    return self::FAILURE;
                }

                $this->components->info("Starting LaraOrcest execution" . ($dryRun ? " [DRY-RUN MODE]" : ""));
                $this->line("Prompt: <comment>{$prompt}</comment>");

                try {
                    $state = $orchestrator->run($prompt, $options);
                } catch (\Throwable $e) {
                    $this->components->error("Execution error: " . $e->getMessage());

                    return self::FAILURE;
                }
            }
        } finally {
            TerminalAnnouncer::setWriter(null);
        }

        $this->newLine();

        if ($state->isPaused()) {
            $pausedGate = $state->getPausedGate() ?? 'review_gate';
            $this->components->warn("⏸ LaraOrcest paused for human review at gate: [{$pausedGate}] [Run ID: {$state->runId}]");

            $context = $state->getPauseContext();
            if (! empty($context['summary'])) {
                $this->line("<comment>Review Summary:</comment> {$context['summary']}");
            }
            if (! empty($context['artifacts'])) {
                $this->line("<comment>Proposed Artifacts:</comment> " . implode(', ', (array) $context['artifacts']));
            }
            if (! empty($context['backlog_count'])) {
                $this->line("<comment>Planned Tasks:</comment> {$context['backlog_count']} tasks");
            }

            $this->newLine();
            $this->components->info("To approve and resume execution:");
            $this->line("  <fg=green>php artisan orcest:approve {$state->runId}</>");
            $this->line("  <fg=gray># Or with note:  php artisan orcest:approve {$state->runId} --note=\"Approved\"</>");
            $this->line("  <fg=gray># Or via build:  php artisan orcest:build --resume={$state->runId} --approve</>");
            $this->newLine();
            $this->components->info("To reject and halt:");
            $this->line("  <fg=red>php artisan orcest:approve {$state->runId} --reject --note=\"Reason\"</>");

            return self::SUCCESS;
        }

        if ($state->isCompleted()) {
            $this->components->info("✔ LaraOrcest completed successfully! [Run ID: {$state->runId}]");
        } else {
            $this->components->warn("⚠ LaraOrcest terminated with status: {$state->status} [Run ID: {$state->runId}]");
            if ($state->getFailureReason()) {
                $this->line("<fg=red>Reason:</> {$state->getFailureReason()}");
            }
        }

        // Display Package Manager Disclosure & Summary
        $pmData = $state->getPackageManager();
        if (! empty($pmData)) {
            $this->components->twoColumnDetail(
                '<options=bold>Target Laravel Version</>',
                (string) ($pmData['target_laravel_version'] ?? 'default')
            );
            if (! empty($pmData['target_packages'])) {
                $this->components->twoColumnDetail(
                    '<options=bold>Target Packages</>',
                    implode(', ', (array) $pmData['target_packages'])
                );
            }
        }

        // Display Tasks
        if (! empty($state->backlog)) {
            $this->components->twoColumnDetail('<options=bold>Backlog Tasks</>', count($state->backlog) . ' tasks planned');
            $rows = [];
            foreach ($state->backlog as $task) {
                $rows[] = [
                    $task['id'] ?? 'N/A',
                    $task['title'] ?? '',
                    $task['priority'] ?? 1,
                    count($task['acceptance_criteria'] ?? []),
                ];
            }
            $this->table(['ID', 'Title', 'Priority', 'Criteria Count'], $rows);
        }

        // Display Generated Artifacts
        if (! empty($state->artifacts)) {
            $this->newLine();
            $this->components->twoColumnDetail('<options=bold>Artifacts</>', count($state->artifacts) . ' files generated/updated');
            $artifactRows = [];
            foreach ($state->artifacts as $art) {
                $artifactRows[] = [
                    $art['path'],
                    $art['action'],
                    $art['summary'],
                ];
            }
            $this->table(['Path', 'Action', 'Summary'], $artifactRows);
        }

        // Display Swagger / OpenAPI Documentation
        if ($state->hasSwaggerDocs()) {
            $this->newLine();
            $endpoints = $state->getSwaggerEndpoints();
            $endpointCount = count($endpoints);
            $this->components->twoColumnDetail('<options=bold>Swagger / OpenAPI</>', "{$endpointCount} endpoints documented");
            $summary = $state->getSwaggerSummary();
            if ($summary !== null && $summary !== '') {
                $this->line("Summary: {$summary}");
            }
            $outputPaths = $state->getSwaggerOutputPaths();
            if (! empty($outputPaths)) {
                $this->line("Documentation file(s): " . implode(', ', $outputPaths));
            }
        }

        // Display Test Outcomes
        if (! empty($state->testResults)) {
            $this->newLine();
            $passText = $state->areTestsPassing() ? '<fg=green>PASSED</>' : '<fg=red>FAILED</>';
            $this->components->twoColumnDetail('<options=bold>Automated Tests</>', $passText);
            $this->line("Summary: " . ($state->testResults['summary'] ?? 'N/A'));
        }

        // Display Review Determination
        $latestReview = $state->getLatestReview();
        if ($latestReview !== null) {
            $this->newLine();
            $decisionText = ($latestReview['decision'] ?? '') === 'approved' ? '<fg=green>APPROVED</>' : '<fg=yellow>REVISE</>';
            $this->components->twoColumnDetail('<options=bold>Reviewer Sign-off</>', $decisionText);
            if (! empty($latestReview['findings'])) {
                foreach ($latestReview['findings'] as $finding) {
                    $this->line(" - {$finding}");
                }
            }
        }

        // Display Git State
        if (! empty($state->gitState['feature_branch'])) {
            $this->newLine();
            $this->components->twoColumnDetail('<options=bold>Git Feature Branch</>', $state->gitState['feature_branch']);
        }

        // Display Guardrail and Token Stats
        $tokens = $state->guardrails['token_usage'] ?? [];
        $totalTokens = $tokens['total_tokens'] ?? 0;
        $steps = $state->guardrails['step_count'] ?? 0;
        $fixAttempts = $state->guardrails['fix_attempts'] ?? 0;

        $this->newLine();
        $this->components->twoColumnDetail('<options=bold>Steps Taken</>', (string) $steps);
        $this->components->twoColumnDetail('<options=bold>Fix Attempts</>', "{$fixAttempts} / {$state->guardrails['max_fix_attempts']}");
        $this->components->twoColumnDetail('<options=bold>Total Tokens Used</>', number_format($totalTokens));

        return $state->isCompleted() ? self::SUCCESS : self::FAILURE;
    }
}
