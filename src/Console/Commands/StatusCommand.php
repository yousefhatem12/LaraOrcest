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
use Yousef\LaraOrcest\Orchestrator;
use Yousef\LaraOrcest\Workspace\WorkspaceContext;

class StatusCommand extends Command
{
    protected static $defaultName = 'status';

    protected function configure(): void
    {
        $this
            ->setName('status')
            ->setDescription('Inspect RunState, workspace target path, and delegation checkpoints.')
            ->addArgument('run-id', InputArgument::OPTIONAL, 'Specific orchestration run ID to inspect')
            ->addOption('target', 't', InputOption::VALUE_OPTIONAL, 'Path to target Laravel project directory')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output status in JSON format');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $explicitTarget = $input->getOption('target');
        $asJson = (bool) $input->getOption('json');
        $runId = $input->getArgument('run-id');

        $context = WorkspaceContext::detect($explicitTarget ? (string) $explicitTarget : null);
        $orchestrator = new Orchestrator(workspaceContext: $context);

        if ($runId) {
            $data = $orchestrator->status((string) $runId);

            if (isset($data['error'])) {
                if ($asJson) {
                    $output->writeln((string) json_encode($data, JSON_PRETTY_PRINT));
                } else {
                    $io->error($data['error']);
                }

                return Command::FAILURE;
            }

            if ($asJson) {
                $output->writeln((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return Command::SUCCESS;
            }

            $io->title("Run Details: {$runId}");
            $io->listing([
                'Status: ' . ($data['status'] ?? 'unknown'),
                'Current Node: ' . ($data['current_node'] ?? 'unknown'),
                'Target Project Path: ' . ($data['target_project_path'] ?? $context->getTargetProjectPath()),
                'Workspace Mode: ' . ($data['workspace_mode'] ?? $context->getWorkspaceMode()),
                'Prompt: ' . ($data['prompt'] ?? ''),
            ]);

            if (! empty($data['paused_gate'])) {
                $io->warning("Run is paused at review gate: {$data['paused_gate']}");
                $io->text("Resume command: bin/lara-orcest approve {$runId}");
            }

            $guardrails = (array) ($data['guardrails'] ?? []);
            $stepCount = $guardrails['step_count'] ?? 0;
            $maxSteps = $guardrails['max_steps'] ?? 40;
            $fixAttempts = $guardrails['fix_attempts'] ?? 0;
            $maxFix = $guardrails['max_fix_attempts'] ?? 3;
            $tokens = $guardrails['token_usage'] ?? [];
            $totalTokens = $tokens['total_tokens'] ?? 0;

            $io->section('Execution Guardrails');
            $io->listing([
                "Steps Taken: {$stepCount} / {$maxSteps}",
                "Fix Attempts: {$fixAttempts} / {$maxFix}",
                'Total Tokens: ' . number_format((int) $totalTokens),
            ]);

            // Git State
            $git = (array) ($data['git_state'] ?? []);
            if (! empty($git['feature_branch'])) {
                $io->text("<options=bold>Git Branch:</> <info>{$git['feature_branch']}</info>");
            }
            if (! empty($git['commits'])) {
                $io->text('<options=bold>Commits Recorded:</> ' . count($git['commits']));
            }

            // Artifacts
            $artifacts = (array) ($data['artifacts'] ?? []);
            if (! empty($artifacts)) {
                $io->newLine();
                $io->section('Artifacts Created (' . count($artifacts) . ' files)');
                $artifactRows = [];
                foreach ($artifacts as $art) {
                    $artifactRows[] = [
                        $art['path'] ?? '',
                        $art['action'] ?? '',
                        $art['summary'] ?? '',
                    ];
                }
                $table = new Table($output);
                $table->setHeaders(['Path', 'Action', 'Summary'])->setRows($artifactRows)->render();
            }

            // Delegation Checkpoints (Hierarchical Pod delegations)
            $delegations = (array) ($data['delegations'] ?? []);
            if (! empty($delegations)) {
                $io->newLine();
                $io->section('Sub-Agent Delegation Checkpoints (' . count($delegations) . ' delegations)');
                $delRows = [];
                foreach ($delegations as $del) {
                    $skills = implode(', ', (array) ($del['active_skills'] ?? []));
                    $delRows[] = [
                        $del['sub_agent'] ?? '',
                        $del['parent_agent'] ?? '',
                        mb_strimwidth($skills, 0, 30, '...'),
                        $del['status'] ?? '',
                        mb_strimwidth((string) ($del['summary'] ?? ''), 0, 40, '...'),
                        $del['timestamp'] ?? '',
                    ];
                }
                $table = new Table($output);
                $table->setHeaders(['Sub-Agent', 'Lead Agent', 'Active Skills', 'Status', 'Summary', 'Timestamp'])->setRows($delRows)->render();
            }

            // Test Results
            $testResults = (array) ($data['test_results'] ?? []);
            if (! empty($testResults)) {
                $io->newLine();
                $passText = ($testResults['passed'] ?? false) ? '<fg=green>PASSED</>' : '<fg=red>FAILED</>';
                $io->section("Automated Tests: {$passText}");
                $io->text('Summary: ' . ($testResults['summary'] ?? 'N/A'));
            }

            // Reviews
            $reviews = (array) ($data['reviews'] ?? []);
            if (! empty($reviews)) {
                $io->newLine();
                $latestReview = end($reviews);
                $decisionText = ($latestReview['decision'] ?? '') === 'approved' ? '<fg=green>APPROVED</>' : '<fg=yellow>REVISE</>';
                $io->section("Latest Review: {$decisionText}");
                if (! empty($latestReview['findings'])) {
                    foreach ($latestReview['findings'] as $finding) {
                        $io->text(" - {$finding}");
                    }
                }
            }

            // Failure reason
            $meta = (array) ($data['metadata'] ?? []);
            if (! empty($meta['failure_reason'])) {
                $io->newLine();
                $io->text("<fg=red>Failure Reason:</> {$meta['failure_reason']}");
            }

            return Command::SUCCESS;
        }

        // List recent runs
        $runs = $orchestrator->status();

        if ($asJson) {
            $payload = [
                'target_project_path' => $context->getTargetProjectPath(),
                'workspace_mode' => $context->getWorkspaceMode(),
                'runs' => $runs,
            ];
            $output->writeln((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        $io->title('LaraOrcest Standalone Control Plane Status');
        $io->listing([
            "Target Workspace: {$context->getTargetProjectPath()}",
            "Workspace Mode: {$context->getWorkspaceMode()}",
            "State Path: {$context->getStatePath()}",
        ]);

        if (empty($runs)) {
            $io->info('No orchestration runs recorded yet in this workspace.');

            return Command::SUCCESS;
        }

        $io->section('Recent Execution Runs');
        $rows = [];
        foreach ($runs as $run) {
            $rows[] = [
                $run['run_id'] ?? 'N/A',
                mb_strimwidth((string) ($run['prompt'] ?? ''), 0, 45, '...'),
                $run['current_node'] ?? 'unknown',
                $run['status'] ?? 'unknown',
                $run['updated_at'] ?? 'N/A',
            ];
        }
        $table = new Table($output);
        $table->setHeaders(['Run ID', 'Prompt', 'Current Node', 'Status', 'Updated At'])->setRows($rows)->render();

        return Command::SUCCESS;
    }
}
