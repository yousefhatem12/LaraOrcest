<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Agents;

use Yousef\LaraOrcest\Contracts\LlmDriverContract;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Support\Config;
use Yousef\LaraOrcest\Tools\PatchWriterTool;
use Yousef\LaraOrcest\Tools\TestRunnerTool;

class QaAgent extends AbstractAgent
{
    public function getName(): string
    {
        return 'qa_agent';
    }

    public function getRole(): string
    {
        return 'Laravel Automated QA & Test Engineer';
    }

    public function getGoal(): string
    {
        return 'Write comprehensive feature and unit tests (Pest or PHPUnit) directly from backlog acceptance criteria and execute the test suite.';
    }

    public function getBackstory(): string
    {
        return 'Thorough test engineer who verifies happy paths, validation errors, authorization/tenant boundaries, and edge cases rather than superficial smoke checks.';
    }

    public function getAllowedTools(): array
    {
        return ['file_reader', 'patch_writer', 'test_runner', 'artisan_runner'];
    }

    public function getStateOutputKey(): string
    {
        return 'test_results';
    }

    public function execute(RunState $state, array $tools, LlmDriverContract $llm): RunState
    {
        $teamsEnabled = (bool) Config::get('lara-orcest.teams.enabled', true);
        $subRegistry = $this->getTeamDelegator()->getSubAgentRegistry();

        if ($teamsEnabled && $subRegistry->has('feature_test_agent')) {
            try {
                // 1. Delegate test writing to feature_test_agent
                $delegation = $this->delegateSubTasks(
                    state: $state,
                    subAgentKeys: ['feature_test_agent'],
                    context: [
                        'requirements_spec' => $state->requirementsSpec,
                        'backlog' => $state->backlog,
                        'technical_plan' => $state->technicalPlan,
                        'artifacts' => $state->artifacts,
                        'prompt' => $state->prompt,
                        'target_project_path' => $state->targetProjectPath,
                        'workspace_mode' => $state->workspaceMode,
                        'project_memory' => $this->resolveProjectMemoryContext($state),
                    ],
                    tools: $tools,
                    llm: $llm,
                    activeSkillNames: ['laravel-feature-testing']
                );

                $state = $delegation->state;

                // 2. Execute test_runner tool
                /** @var TestRunnerTool $testRunner */
                $testRunner = $this->getTool('test_runner', $tools);
                if (method_exists($testRunner, 'setDryRun')) {
                    $testRunner->setDryRun($state->isDryRun());
                }

                $results = $testRunner->execute([]);
                $state = $state->recordTestResult(
                    passed: (bool) ($results['passed'] ?? false),
                    exitCode: (int) ($results['exit_code'] ?? 1),
                    summary: (string) ($results['summary'] ?? ''),
                    output: (string) ($results['output'] ?? '')
                );

                // 3. If tests failed and fixing is allowed and fixer_agent registered, trigger fix loop
                // (Only when fix loop is not managed by an outer GraphEngine that contains a dedicated fixer_agent node)
                $graphFixerLoop = (bool) ($state->metadata['graph_fixer_loop'] ?? false);
                if (! $graphFixerLoop && ! $state->areTestsPassing() && ! $state->isDryRun() && $state->canAttemptFix() && $subRegistry->has('fixer_agent')) {
                    $state = $state->incrementFixAttempts();

                    $fixDelegation = $this->delegateSubTasks(
                        state: $state,
                        subAgentKeys: ['fixer_agent'],
                        context: [
                            'test_results' => $state->testResults,
                            'review' => $state->getLatestReview(),
                            'artifacts' => $state->artifacts,
                            'requirements_spec' => $state->requirementsSpec,
                            'backlog' => $state->backlog,
                            'technical_plan' => $state->technicalPlan,
                            'target_project_path' => $state->targetProjectPath,
                            'workspace_mode' => $state->workspaceMode,
                            'project_memory' => $this->resolveProjectMemoryContext($state),
                        ],
                        tools: $tools,
                        llm: $llm,
                        activeSkillNames: ['root-cause-test-fixing']
                    );

                    $state = $fixDelegation->state;

                    // Re-run test runner to verify fix
                    $retryResults = $testRunner->execute([]);
                    $state = $state->recordTestResult(
                        passed: (bool) ($retryResults['passed'] ?? false),
                        exitCode: (int) ($retryResults['exit_code'] ?? 1),
                        summary: (string) ($retryResults['summary'] ?? ''),
                        output: (string) ($retryResults['output'] ?? '')
                    );
                }

                return $state;
            } catch (\Throwable) {
                // Fallback gracefully to single-agent execution
            }
        }

        return parent::execute($state, $tools, $llm);
    }

    protected function buildUserPrompt(RunState $state): string
    {
        $backlogJson = json_encode($state->backlog, JSON_PRETTY_PRINT);
        $artifactsJson = json_encode($state->artifacts, JSON_PRETTY_PRINT);

        return "BACKLOG & ACCEPTANCE CRITERIA:\n{$backlogJson}\n\nIMPLEMENTED ARTIFACTS:\n{$artifactsJson}\n\nPlease generate comprehensive feature and unit test files to thoroughly test the implementation against every acceptance criterion.";
    }

    protected function getOutputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'test_files' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'path' => [
                                'type' => 'string',
                                'description' => 'Target path (must start with tests/).',
                            ],
                            'content' => [
                                'type' => 'string',
                                'description' => 'PHPUnit/Pest test code.',
                            ],
                            'summary' => [
                                'type' => 'string',
                                'description' => 'Description of test scenarios covered.',
                            ],
                        ],
                        'required' => ['path', 'content', 'summary'],
                    ],
                ],
            ],
            'required' => ['test_files'],
        ];
    }

    protected function applyOutput(RunState $state, array $output, array $tools): RunState
    {
        /** @var PatchWriterTool $patchTool */
        $patchTool = $this->getTool('patch_writer', $tools);
        // Restrict strictly to tests/
        $patchTool->setAllowedPrefixes(['tests/']);
        $patchTool->setDryRun($state->isDryRun());

        $testFiles = (array) ($output['test_files'] ?? []);

        foreach ($testFiles as $file) {
            $path = (string) ($file['path'] ?? '');
            $content = (string) ($file['content'] ?? '');
            $summary = (string) ($file['summary'] ?? 'Automated test suite');

            $res = $patchTool->execute([
                'path' => $path,
                'content' => $content,
                'summary' => $summary,
            ]);

            $state = $state->recordArtifact($res['path'], $res['action'], $summary);
        }

        /** @var TestRunnerTool $testRunner */
        $testRunner = $this->getTool('test_runner', $tools);
        if (method_exists($testRunner, 'setDryRun')) {
            $testRunner->setDryRun($state->isDryRun());
        }

        $results = $testRunner->execute([]);

        return $state->recordTestResult(
            passed: (bool) ($results['passed'] ?? false),
            exitCode: (int) ($results['exit_code'] ?? 1),
            summary: (string) ($results['summary'] ?? ''),
            output: (string) ($results['output'] ?? '')
        );
    }
}

