<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Agents\SubAgents;

use Yousef\LaraOrcest\Agents\Teams\AbstractSubAgent;
use Yousef\LaraOrcest\Contracts\ToolContract;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Support\Config;
use Yousef\LaraOrcest\Tools\GitCommandTool;

class GitSubAgent extends AbstractSubAgent
{
    public function key(): string
    {
        return 'git_agent';
    }

    public function parentAgentKey(): string
    {
        return 'reviewer_agent';
    }

    public function role(): string
    {
        return 'Git Branch & Milestone Release Specialist';
    }

    public function goal(): string
    {
        return 'Isolate changes on a dedicated feature branch, create clean commits after verified milestones, and prevent dirty or untracked accidental overwrites.';
    }

    public function backstory(): string
    {
        return 'Disciplined release engineer who treats version control as a safety net—ensuring every automated change is traceable, diffable, and easy to revert.';
    }

    public function scopePaths(): array
    {
        return ['*'];
    }

    public function allowedTools(): array
    {
        return ['git_command', 'file_reader'];
    }

    public function dependsOn(): array
    {
        return [];
    }

    protected function buildUserPrompt(RunState $state, array $context = []): string
    {
        $prompt = (string) ($context['prompt'] ?? $state->prompt);
        $runId = (string) ($context['run_id'] ?? $state->runId);
        $artifactsJson = json_encode($context['artifacts'] ?? $state->artifacts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $reviewsJson = json_encode($context['reviews'] ?? $state->reviews, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return "RUN ID: {$runId}\n" .
            "PROMPT: {$prompt}\n\n" .
            "VERIFIED ARTIFACTS:\n{$artifactsJson}\n\n" .
            "REVIEW OUTCOME:\n{$reviewsJson}\n\n" .
            "Please propose the feature branch name and milestone commit message.\n" .
            "1. Branch name must follow orcest/<feature-name> convention.\n" .
            "2. Commit message must be concise and descriptive.\n" .
            "3. Output strictly structured JSON matching the output schema.";
    }

    protected function getOutputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'branch_name' => [
                    'type' => 'string',
                    'description' => 'Safe feature branch name (e.g. orcest/feature-task-api).',
                ],
                'commit_message' => [
                    'type' => 'string',
                    'description' => 'Clean, concise commit message for the milestone.',
                ],
                'milestone_summary' => [
                    'type' => 'string',
                    'description' => 'Summary of initial branch or milestone release.',
                ],
            ],
            'required' => ['branch_name'],
        ];
    }

    protected function applyOutput(RunState $state, array $output, array $tools): array
    {
        /** @var GitCommandTool $gitTool */
        $gitTool = $this->getTool('git_command', $tools);
        $isDryRun = $state->isDryRun() || (method_exists($gitTool, 'isDryRun') && $gitTool->isDryRun());
        if (method_exists($gitTool, 'setDryRun')) {
            $gitTool->setDryRun($isDryRun);
        }

        $prefix = (string) Config::get('lara-orcest.git.feature_branch_prefix', 'orcest/');
        $proposedBranch = (string) ($output['branch_name'] ?? ('feature-' . substr($state->runId, 0, 8)));

        // Ensure prefix
        if (! str_starts_with($proposedBranch, $prefix)) {
            $proposedBranch = $prefix . ltrim($proposedBranch, '/');
        }

        $current = ['branch' => 'main'];
        $status = ['clean' => true, 'branch' => 'main'];
        $featureBranch = $proposedBranch;
        $branchCreated = false;

        try {
            // Check current branch & status
            $current = $gitTool->getCurrentBranch();
            $status = $gitTool->getStatus();

            // If not already on the feature branch, create it
            if (($current['branch'] ?? '') !== $proposedBranch) {
                $createRes = $gitTool->createBranch($proposedBranch);
                $featureBranch = $createRes['branch'] ?? $proposedBranch;
                $branchCreated = (bool) ($createRes['created'] ?? false);
            }
        } catch (\Throwable) {
            $branchCreated = true;
        }

        $commitResult = null;
        $commitMessage = trim((string) ($output['commit_message'] ?? ''));

        // If commit message provided, stage files and commit
        if ($commitMessage !== '') {
            try {
                $gitTool->execute(['action' => 'stage_files', 'files' => []]);
                $commitResult = $gitTool->execute(['action' => 'commit', 'message' => $commitMessage]);
            } catch (\Throwable) {
                // If staging/commit fails (e.g. working tree clean), proceed gracefully
            }
        }

        $gitState = [
            'base_branch' => $current['branch'] ?? 'main',
            'feature_branch' => $featureBranch,
            'branch_created' => $branchCreated,
            'status' => $status,
            'commits' => $state->gitState['commits'] ?? [],
        ];

        if ($commitResult !== null && ! empty($commitResult['commit_hash'])) {
            $gitState['commits'][] = $commitResult;
        }

        $summary = (string) ($output['milestone_summary'] ?? "Git branch configured: {$featureBranch}");

        return [
            'summary' => $summary,
            'output' => $gitState,
            'artifacts' => [],
        ];
    }
}
