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

            $artifactsCount = count($data['artifacts'] ?? []);
            $this->components->twoColumnDetail('Artifacts Created', (string) $artifactsCount);

            $guardrails = $data['guardrails'] ?? [];
            $this->components->twoColumnDetail('Steps Taken', (string) ($guardrails['step_count'] ?? 0));
            $this->components->twoColumnDetail('Fix Attempts', (string) ($guardrails['fix_attempts'] ?? 0));

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
