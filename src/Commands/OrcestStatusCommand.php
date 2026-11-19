<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Commands;

use Illuminate\Console\Command;
use Yousef\LaraOrcest\Orchestrator;

class OrcestStatusCommand extends Command
{
    protected $signature = 'orcest:status {run_id? : Specific orchestration run ID}';

    protected $description = 'Display status of recent or specific LaraOrcest execution runs';

    public function handle(Orchestrator $orchestrator): int
    {
        $runId = $this->argument('run_id');

        if ($runId) {
            $data = $orchestrator->status((string) $runId);

            if (isset($data['error'])) {
                $this->components->error($data['error']);

                return self::FAILURE;
            }

            $this->components->info("Run Details: {$runId}");
            $this->components->twoColumnDetail('Status', (string) ($data['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Current Node', (string) ($data['current_node'] ?? 'unknown'));
            $this->components->twoColumnDetail('Prompt', (string) ($data['prompt'] ?? ''));

            $guardrails = $data['guardrails'] ?? [];
            $this->components->twoColumnDetail('Steps Taken', (string) ($guardrails['step_count'] ?? 0) . ' / ' . ($guardrails['max_steps'] ?? 40));
            $this->components->twoColumnDetail('Fix Attempts', (string) ($guardrails['fix_attempts'] ?? 0) . ' / ' . ($guardrails['max_fix_attempts'] ?? 3));

            $tokenUsage = $guardrails['token_usage'] ?? [];
            $this->components->twoColumnDetail('Total Tokens Used', number_format((int) ($tokenUsage['total_tokens'] ?? 0)));

            // Git State
            $git = $data['git_state'] ?? [];
            if (! empty($git['feature_branch'])) {
                $this->components->twoColumnDetail('Git Branch', (string) $git['feature_branch']);
            }
            if (! empty($git['commits'])) {
                $this->components->twoColumnDetail('Commits Recorded', (string) count($git['commits']));
            }

            // Artifacts
            $artifacts = (array) ($data['artifacts'] ?? []);
            $this->newLine();
            $this->components->twoColumnDetail('<options=bold>Artifacts Created</>', (string) count($artifacts));
            if (! empty($artifacts)) {
                $artifactRows = [];
                foreach ($artifacts as $art) {
                    $artifactRows[] = [
                        $art['path'] ?? '',
                        $art['action'] ?? '',
                        $art['summary'] ?? '',
                    ];
                }
                $this->table(['Path', 'Action', 'Summary'], $artifactRows);
            }

            // Test Results
            $testResults = (array) ($data['test_results'] ?? []);
            if (! empty($testResults)) {
                $this->newLine();
                $passText = ($testResults['passed'] ?? false) ? '<fg=green>PASSED</>' : '<fg=red>FAILED</>';
                $this->components->twoColumnDetail('<options=bold>Automated Tests</>', $passText);
                $this->line("Summary: " . ($testResults['summary'] ?? 'N/A'));
            }

            // Reviews
            $reviews = (array) ($data['reviews'] ?? []);
            if (! empty($reviews)) {
                $this->newLine();
                $latestReview = end($reviews);
                $decisionText = ($latestReview['decision'] ?? '') === 'approved' ? '<fg=green>APPROVED</>' : '<fg=yellow>REVISE</>';
                $this->components->twoColumnDetail('<options=bold>Latest Review Decision</>', $decisionText);
                if (! empty($latestReview['findings'])) {
                    foreach ($latestReview['findings'] as $finding) {
                        $this->line(" - {$finding}");
                    }
                }
            }

            // Failure reason
            $meta = (array) ($data['metadata'] ?? []);
            if (! empty($meta['failure_reason'])) {
                $this->newLine();
                $this->line("<fg=red>Failure Reason:</> {$meta['failure_reason']}");
            }

            return self::SUCCESS;
        }

        $runs = $orchestrator->status();

        if (empty($runs)) {
            $this->components->info('No orchestration runs recorded yet.');

            return self::SUCCESS;
        }

        $this->components->info('Recent LaraOrcest Execution Runs:');

        $rows = [];
        foreach ($runs as $run) {
            $rows[] = [
                $run['run_id'],
                mb_strimwidth($run['prompt'], 0, 45, '...'),
                $run['current_node'],
                $run['status'],
                $run['updated_at'],
            ];
        }

        $this->table(['Run ID', 'Prompt', 'Current Node', 'Status', 'Updated At'], $rows);

        return self::SUCCESS;
    }
}
