<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Agents;

use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Tools\GitCommandTool;

class GitAgent extends AbstractAgent
{
    public function getName(): string
    {
        return 'git_agent';
    }

    public function getRole(): string
    {
        return 'Source Control & Release Hygiene Agent';
    }

    public function getGoal(): string
    {
        return 'Isolate changes on a dedicated feature branch, create clean commits after verified milestones, and prevent dirty or untracked accidental overwrites.';
    }

    public function getBackstory(): string
    {
        return 'Disciplined release engineer who treats version control as a safety net—ensuring every automated change is traceable, diffable, and easy to revert.';
    }

    public function getAllowedTools(): array
    {
        return ['git_command'];
    }

    public function getStateOutputKey(): string
    {
        return 'git_state';
    }

    protected function buildUserPrompt(RunState $state): string
    {
        return "PROMPT: {$state->prompt}\nRUN ID: {$state->runId}\nPlease propose the feature branch name and initial milestone commit structure.";
    }

    protected function getOutputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'branch_name' => [
                    'type' => 'string',
                    'description' => 'Safe feature branch name (e.g. orcest/task-management-api).',
                ],
                'milestone_summary' => [
                    'type' => 'string',
                    'description' => 'Summary of initial branch initialization.',
                ],
            ],
            'required' => ['branch_name'],
        ];
    }

    protected function applyOutput(RunState $state, array $output, array $tools): RunState
    {
        /** @var GitCommandTool $gitTool */
        $gitTool = $this->getTool('git_command', $tools);
        if (method_exists($gitTool, 'setDryRun')) {
            $gitTool->setDryRun($state->isDryRun());
        }

        $prefix = (string) \Yousef\LaraOrcest\Support\Config::get('lara-orcest.git.feature_branch_prefix', 'orcest/');
        $proposedBranch = (string) ($output['branch_name'] ?? ('feature-' . substr($state->runId, 0, 8)));

        // Ensure prefix
        if (! str_starts_with($proposedBranch, $prefix)) {
            $proposedBranch = $prefix . ltrim($proposedBranch, '/');
        }

        // Check current branch & status
        $current = $gitTool->getCurrentBranch();
        $status = $gitTool->getStatus();

        $featureBranch = $proposedBranch;
        $branchCreated = false;

        // If not already on the feature branch, create it
        if ($current['branch'] !== $proposedBranch) {
            $createRes = $gitTool->createBranch($proposedBranch);
            $featureBranch = $createRes['branch'];
            $branchCreated = (bool) ($createRes['created'] ?? false);
        }

        $gitState = [
            'base_branch' => $current['branch'],
            'feature_branch' => $featureBranch,
            'branch_created' => $branchCreated,
            'status' => $status,
            'commits' => $state->gitState['commits'] ?? [],
        ];

        return $state->withGitState($gitState);
    }
}
