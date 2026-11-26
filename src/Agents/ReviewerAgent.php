<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Agents;

use Yousef\LaraOrcest\Contracts\LlmDriverContract;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Support\Config;
use Yousef\LaraOrcest\Tools\GitCommandTool;

class ReviewerAgent extends AbstractAgent
{
    public function getName(): string
    {
        return 'reviewer_agent';
    }

    public function getRole(): string
    {
        return 'Code Quality, Security & Scope Reviewer';
    }

    public function getGoal(): string
    {
        return 'Audit all changes against requirements_spec, backlog acceptance criteria, Laravel best practices, and security/scope guardrails.';
    }

    public function getBackstory(): string
    {
        return 'Strict staff-level reviewer who catches missing authorization checks, SQL/mass-assignment risks, out-of-scope file edits, and incomplete acceptance criteria before final sign-off.';
    }

    public function getAllowedTools(): array
    {
        return ['file_reader', 'git_command', 'patch_writer', 'artisan_runner'];
    }

    public function getStateOutputKey(): string
    {
        return 'reviews';
    }

    protected function getDefaultSkills(): array
    {
        return [
            'openapi-swagger-spec',
            'safe-git-release',
            'laravel-strict-standards',
        ];
    }

    public function execute(RunState $state, array $tools, LlmDriverContract $llm): RunState
    {
        $teamsEnabled = (bool) Config::get('lara-orcest.teams.enabled', true);
        $subRegistry = $this->getTeamDelegator()->getSubAgentRegistry();

        // 1. Perform review audit
        $state = parent::execute($state, $tools, $llm);

        if ($teamsEnabled) {
            try {
                // 2. Delegate to swagger_agent if swagger is requested/enabled and registered
                if ($subRegistry->has('swagger_agent') && $state->shouldRunSwagger()) {
                    $activeSkills = ['openapi-swagger-spec'];
                    $context = [
                        'requirements_spec' => $state->requirementsSpec,
                        'backlog' => $state->backlog,
                        'technical_plan' => $state->technicalPlan,
                        'artifacts' => $state->artifacts,
                        'reviews' => $state->reviews,
                        'prompt' => $state->prompt,
                        'target_project_path' => $state->targetProjectPath,
                        'workspace_mode' => $state->workspaceMode,
                    ];

                    $delegationResult = $this->delegateSubTasks(
                        state: $state,
                        subAgentKeys: ['swagger_agent'],
                        context: $context,
                        tools: $tools,
                        llm: $llm,
                        activeSkillNames: $activeSkills
                    );

                    $state = $delegationResult->state;
                    $swaggerRes = $delegationResult->getResult('swagger_agent');
                    if (! empty($swaggerRes['output']) && is_array($swaggerRes['output'])) {
                        $state = $state->withSwaggerDocs($swaggerRes['output']);
                    }
                }

                // 3. Delegate to git_agent for milestone branch and commit if review is approved
                if ($subRegistry->has('git_agent') && $state->isReviewApproved()) {
                    $activeSkills = ['safe-git-release'];
                    $context = [
                        'prompt' => $state->prompt,
                        'run_id' => $state->runId,
                        'artifacts' => $state->artifacts,
                        'reviews' => $state->reviews,
                        'target_project_path' => $state->targetProjectPath,
                        'workspace_mode' => $state->workspaceMode,
                    ];

                    $delegationResult = $this->delegateSubTasks(
                        state: $state,
                        subAgentKeys: ['git_agent'],
                        context: $context,
                        tools: $tools,
                        llm: $llm,
                        activeSkillNames: $activeSkills
                    );

                    $state = $delegationResult->state;
                    $gitRes = $delegationResult->getResult('git_agent');
                    if (! empty($gitRes['output']) && is_array($gitRes['output'])) {
                        $gitState = array_merge($state->gitState, $gitRes['output']);
                        $state = $state->withGitState($gitState);
                    }
                }
            } catch (\Throwable) {
                // Fallback gracefully
            }
        }

        return $state;
    }

    protected function buildUserPrompt(RunState $state): string
    {
        $specJson = json_encode($state->requirementsSpec, JSON_PRETTY_PRINT);
        $backlogJson = json_encode($state->backlog, JSON_PRETTY_PRINT);
        $artifactsJson = json_encode($state->artifacts, JSON_PRETTY_PRINT);
        $testResults = json_encode($state->testResults, JSON_PRETTY_PRINT);

        return "REQUIREMENTS SPEC:\n{$specJson}\n\nBACKLOG:\n{$backlogJson}\n\nARTIFACTS CREATED:\n{$artifactsJson}\n\nTEST SUITE OUTCOME:\n{$testResults}\n\nPlease audit all changes against requirements, best practices, security, and test results. Output 'approved' if all criteria are satisfied and tests pass, or 'revise' if corrections are needed.";
    }

    protected function getOutputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'decision' => [
                    'type' => 'string',
                    'enum' => ['approved', 'revise'],
                    'description' => 'Final review determination.',
                ],
                'findings' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'List of review findings, praises, or issues.',
                ],
                'constraints' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Specific constraints or required fixes if decision is revise.',
                ],
            ],
            'required' => ['decision', 'findings'],
        ];
    }

    protected function applyOutput(RunState $state, array $output, array $tools): RunState
    {
        $decision = (string) ($output['decision'] ?? 'revise');
        $findings = (array) ($output['findings'] ?? []);
        $constraints = (array) ($output['constraints'] ?? []);

        // Guardrail: If tests didn't pass, review CANNOT be approved
        if (! $state->areTestsPassing() && ! $state->isDryRun()) {
            $decision = 'revise';
            $findings[] = 'Automatically rejected: Automated test suite has failing tests.';
        }

        return $state->recordReview($decision, $findings, $constraints);
    }
}

