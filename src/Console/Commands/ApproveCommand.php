<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Yousef\LaraOrcest\Orchestrator;
use Yousef\LaraOrcest\Workspace\WorkspaceContext;

class ApproveCommand extends Command
{
    protected static $defaultName = 'approve';

    protected function configure(): void
    {
        $this
            ->setName('approve')
            ->setDescription('Approve or reject a paused run at its active review gate and resume execution.')
            ->addArgument('run-id', InputArgument::REQUIRED, 'Run ID to approve or reject')
            ->addOption('target', 't', InputOption::VALUE_OPTIONAL, 'Path to target Laravel project directory')
            ->addOption('reject', null, InputOption::VALUE_NONE, 'Reject the run instead of approving')
            ->addOption('note', null, InputOption::VALUE_OPTIONAL, 'Reviewer feedback notes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $runId = (string) $input->getArgument('run-id');
        $explicitTarget = $input->getOption('target');
        $reject = (bool) $input->getOption('reject');
        $note = $input->getOption('note') ? (string) $input->getOption('note') : null;

        $context = WorkspaceContext::detect($explicitTarget ? (string) $explicitTarget : null);
        $orchestrator = new Orchestrator(workspaceContext: $context);

        try {
            if ($reject) {
                $io->warning("Rejecting run [{$runId}]" . ($note ? ": {$note}" : ''));
                $state = $orchestrator->reject($runId, $note);
                $io->text("Run terminated with status: <fg=red>{$state->status}</>");
            } else {
                $io->info("Approving run [{$runId}]" . ($note ? ": {$note}" : ''));
                $state = $orchestrator->approve($runId, $note);
                if ($state->isCompleted()) {
                    $io->success("✔ Run [{$runId}] approved and completed successfully!");
                } elseif ($state->isPaused()) {
                    $io->warning("Run [{$runId}] progressed to next gate: [{$state->getPausedGate()}]");
                } else {
                    $io->text("Run [{$runId}] status: {$state->status}");
                }
            }
        } catch (\Throwable $e) {
            $io->error('Error processing review decision: ' . $e->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
