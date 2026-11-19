<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Agents;

use Yousef\LaraOrcest\State\RunState;
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
        return ['file_reader', 'patch_writer', 'test_runner'];
    }

    public function getStateOutputKey(): string
    {
        return 'test_results';
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
