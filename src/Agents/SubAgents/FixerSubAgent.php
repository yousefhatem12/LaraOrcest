<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Agents\SubAgents;

use Yousef\LaraOrcest\Agents\Teams\AbstractSubAgent;
use Yousef\LaraOrcest\Contracts\ToolContract;
use Yousef\LaraOrcest\State\RunState;

class FixerSubAgent extends AbstractSubAgent
{
    public function key(): string
    {
        return 'fixer_agent';
    }

    public function parentAgentKey(): string
    {
        return 'qa_agent';
    }

    public function role(): string
    {
        return 'Root-Cause Bug Fixer & Test Debugger';
    }

    public function goal(): string
    {
        return 'Diagnose failing tests, runtime exceptions, or review rejections and apply minimal, targeted fixes within max_fix_attempts.';
    }

    public function backstory(): string
    {
        return 'Calm, methodical debugger who reads stack traces and diffs carefully, fixes root causes instead of masking errors, and respects scope limits.';
    }

    public function scopePaths(): array
    {
        return ['app/**', 'database/**', 'routes/**', 'tests/**'];
    }

    public function allowedTools(): array
    {
        return ['file_reader', 'patch_writer', 'artisan_runner', 'test_runner'];
    }

    public function dependsOn(): array
    {
        return ['feature_test_agent'];
    }

    public function skills(): array
    {
        return ['root-cause-test-fixing'];
    }

    protected function buildUserPrompt(RunState $state, array $context = []): string
    {
        $testResults = json_encode($context['test_results'] ?? $state->testResults, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $review = json_encode($context['review'] ?? $state->getLatestReview(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $attempt = ($state->guardrails['fix_attempts'] ?? 0) + 1;
        $maxAttempts = $state->guardrails['max_fix_attempts'] ?? 3;

        return "DEBUGGING SESSION (Attempt {$attempt}/{$maxAttempts}):\n\n" .
            "TEST RESULTS:\n{$testResults}\n\n" .
            "REVIEW FEEDBACK:\n{$review}\n\n" .
            "Please identify the root cause and provide minimal, targeted file patches to resolve the failures.\n" .
            "1. Only modify files in app/**, database/**, routes/**, or tests/**.\n" .
            "2. Fix the underlying bug rather than weakening test assertions.\n" .
            "3. Output strictly structured JSON matching the output schema.";
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
                    'description' => 'List of fixed file patches.',
                ],
            ],
            'required' => ['root_cause', 'fixed_files'],
        ];
    }

    protected function applyOutput(RunState $state, array $output, array $tools): array
    {
        $patchTool = $this->getTool('patch_writer', $tools);
        $fixedFiles = (array) ($output['fixed_files'] ?? []);
        $rootCause = trim((string) ($output['root_cause'] ?? ''));
        $artifacts = [];

        foreach ($fixedFiles as $file) {
            $path = (string) ($file['path'] ?? '');
            $content = (string) ($file['content'] ?? '');
            $summary = (string) ($file['summary'] ?? 'Bug fix');

            $res = $patchTool->execute([
                'path' => $path,
                'content' => $content,
                'summary' => $summary,
            ]);

            $artifacts[] = [
                'path' => $res['path'] ?? $path,
                'action' => $state->isDryRun() ? 'preview' : ($res['action'] ?? 'modified'),
                'summary' => $summary,
            ];
        }

        $count = count($artifacts);

        return [
            'summary' => "Applied {$count} bug fix patches for: {$rootCause}",
            'output' => array_merge($output, [
                'root_cause' => $rootCause,
                'fixed_count' => $count,
            ]),
            'artifacts' => $artifacts,
        ];
    }
}
