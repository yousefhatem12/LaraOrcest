<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Commands;

use Illuminate\Console\Command;
use Yousef\LaraOrcest\Orchestrator;
use Yousef\LaraOrcest\State\RunState;

class OrcestApproveCommand extends Command
{
    protected $signature = 'orcest:approve
                            {run_id : The run ID to approve or review}
                            {--reject : Reject the paused run instead of approving}
                            {--note= : Optional feedback or approval note}
                            {--no-human-review : Skip any future human review gates after this approval}
                            {--dry-run : Continue in dry-run mode}';

    protected $description = 'Approve or reject a paused LaraOrcest run at an active review gate';

    public function handle(Orchestrator $orchestrator): int
    {
        $runId = (string) $this->argument('run_id');
        $isReject = (bool) $this->option('reject');
        $note = $this->option('note') ? (string) $this->option('note') : null;
        $noHumanReview = (bool) $this->option('no-human-review');
        $dryRun = (bool) $this->option('dry-run');

        $statusData = $orchestrator->status($runId);
        if (isset($statusData['error'])) {
            $this->components->error("Run [{$runId}] not found.");

            return self::FAILURE;
        }

        $currentStatus = (string) ($statusData['status'] ?? '');
        $pausedGate = (string) ($statusData['paused_gate'] ?? ($statusData['metadata']['human_in_the_loop']['paused_gate'] ?? ''));

        if ($currentStatus !== RunState::STATUS_PAUSED) {
            $this->components->warn("Run [{$runId}] is not currently paused (status: {$currentStatus}).");

            return self::FAILURE;
        }

        $this->components->info("Reviewing LaraOrcest run [{$runId}] at gate: [" . ($pausedGate ?: 'gate') . ']');
        if ($note) {
            $this->line("Decision Note: <comment>{$note}</comment>");
        }

        $options = [
            'dry_run' => $dryRun,
        ];

        if ($noHumanReview) {
            $options['no_human_review'] = true;
            $options['skip_review'] = true;
        }

        $options['on_step'] = function (RunState $state, string $node, string $message) {
            $step = $state->guardrails['step_count'] ?? 0;
            $this->components->twoColumnDetail(
                "<fg=cyan>[Step {$step}]</> <options=bold>{$node}</>",
                "<fg=gray>{$message}</>"
            );
        };

        if ($isReject) {
            $this->components->warn("Rejecting run [{$runId}] at gate [{$pausedGate}]...");
            try {
                $state = $orchestrator->reject($runId, $note, $options);
            } catch (\Throwable $e) {
                $this->components->error("Rejection error: " . $e->getMessage());

                return self::FAILURE;
            }

            $this->components->info("Run [{$runId}] has been terminated with rejection status.");
            if ($state->getFailureReason()) {
                $this->line("<fg=red>Reason:</> {$state->getFailureReason()}");
            }

            return self::SUCCESS;
        }

        // Approve and resume
        $this->components->info("Approving run [{$runId}] and resuming pipeline...");

        try {
            $state = $orchestrator->approve($runId, $note, $options);
        } catch (\Throwable $e) {
            $this->components->error("Execution error on resume: " . $e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();

        if ($state->isPaused()) {
            $nextGate = $state->getPausedGate() ?? 'review_gate';
            $this->components->warn("⏸ LaraOrcest paused at next review gate: [{$nextGate}] [Run ID: {$state->runId}]");

            $context = $state->getPauseContext();
            if (! empty($context['summary'])) {
                $this->line("<comment>Review Summary:</comment> {$context['summary']}");
            }
            if (! empty($context['artifacts'])) {
                $this->line("<comment>Proposed Artifacts:</comment> " . implode(', ', (array) $context['artifacts']));
            }

            $this->newLine();
            $this->components->info("To approve next gate:");
            $this->line("  <fg=green>php artisan orcest:approve {$state->runId}</>");

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

        return $state->isCompleted() ? self::SUCCESS : self::FAILURE;
    }
}
