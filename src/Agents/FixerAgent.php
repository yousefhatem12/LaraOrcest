<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Agents;

use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Tools\PatchWriterTool;
use Yousef\LaraOrcest\Tools\TestRunnerTool;

class FixerAgent extends AbstractAgent
{
    public function getName(): string
    {
        return 'fixer_agent';
    }

    public function getRole(): string
    {
        return 'Root-Cause Bug Fixer & Debugger';
    }

    public function getGoal(): string
    {
        return 'Diagnose failing tests, runtime exceptions, or review rejections and apply minimal, targeted fixes within max_fix_attempts.';
    }

    public function getBackstory(): string
    {
        return 'Calm, methodical debugger who reads stack traces and diffs carefully, fixes root causes instead of masking errors, and respects scope limits.';
    }

    public function getAllowedTools(): array
    {
        return ['file_reader', 'patch_writer', 'artisan_runner', 'test_runner'];
    }

    public function getStateOutputKey(): string
    {
        return 'artifacts';
    }

    protected function buildUserPrompt(RunState $state): string
    {
        $testResults = json_encode($state->testResults, JSON_PRETTY_PRINT);
        $review = json_encode($state->getLatestReview(), JSON_PRETTY_PRINT);
        $attempt = ($state->guardrails['fix_attempts'] ?? 0) + 1;
        $maxAttempts = $state->guardrails['max_fix_attempts'] ?? 3;

        return "DEBUGGING SESSION (Attempt {$attempt}/{$maxAttempts}):\n\nTEST RESULTS:\n{$testResults}\n\nREVIEW FEEDBACK:\n{$review}\n\nPlease identify the root cause and provide minimal, targeted file patches to resolve the failures.";
    }

    protected function getOutputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'root_cause' => [
                    'type' => 'string',
                    'description' => 'Explanation of what failed and why.',
                ],
                'fixed_files' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'path' => [
                                'type' => 'string',
                                'description' => 'Target path (app/, database/, routes/, or tests/).',
                            ],
                            'content' => [
                                'type' => 'string',
                                'description' => 'Corrected complete file code.',
                            ],
                            'summary' => [
                                'type' => 'string',
                                'description' => 'Summary of fix applied.',
                            ],
                        ],
                        'required' => ['path', 'content', 'summary'],
                    ],
                ],
            ],
            'required' => ['root_cause', 'fixed_files'],
        ];
    }

    protected function applyOutput(RunState $state, array $output, array $tools): RunState
    {
        // Increment fix attempt count
        $state = $state->incrementFixAttempts();

        /** @var PatchWriterTool $patchTool */
        $patchTool = $this->getTool('patch_writer', $tools);
        // Allow fixing app, database, routes, tests
        $patchTool->setAllowedPrefixes(['app/', 'database/', 'routes/', 'tests/']);
        $patchTool->setDryRun($state->isDryRun());

        $fixedFiles = (array) ($output['fixed_files'] ?? []);
        $rootCause = trim((string) ($output['root_cause'] ?? ''));

        // Record fixer resolution in metadata for project memory consolidation
        if ($rootCause !== '') {
            $resolutions = (array) ($state->metadata['fixer_resolutions'] ?? []);
            $resolutions[] = [
                'root_cause' => $rootCause,
                'fixed_files' => array_column($fixedFiles, 'path'),
                'timestamp' => date('c'),
            ];
            $state = $state->withMetadata(array_merge($state->metadata, ['fixer_resolutions' => $resolutions]));
        }

        foreach ($fixedFiles as $file) {
            $path = (string) ($file['path'] ?? '');
            $content = (string) ($file['content'] ?? '');
            $summary = (string) ($file['summary'] ?? 'Bug fix');

            $res = $patchTool->execute([
                'path' => $path,
                'content' => $content,
                'summary' => $summary,
            ]);

            $state = $state->recordArtifact($res['path'], $res['action'], $summary);
        }

        return $state;
    }
}
