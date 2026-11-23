<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Agents\SubAgents;

use Yousef\LaraOrcest\Agents\Teams\AbstractSubAgent;
use Yousef\LaraOrcest\Contracts\ToolContract;
use Yousef\LaraOrcest\State\RunState;

class FeatureTestAgent extends AbstractSubAgent
{
    public function key(): string
    {
        return 'feature_test_agent';
    }

    public function parentAgentKey(): string
    {
        return 'qa_agent';
    }

    public function role(): string
    {
        return 'Laravel Feature & Unit Test Specialist';
    }

    public function goal(): string
    {
        return 'Write comprehensive feature and unit tests (Pest or PHPUnit) directly from backlog acceptance criteria and execute the test suite.';
    }

    public function backstory(): string
    {
        return 'Thorough test engineer who verifies happy paths, validation errors, authorization/tenant boundaries, and edge cases rather than superficial smoke checks.';
    }

    public function scopePaths(): array
    {
        return ['tests/**'];
    }

    public function allowedTools(): array
    {
        return ['file_reader', 'patch_writer', 'test_runner', 'artisan_runner'];
    }

    public function dependsOn(): array
    {
        return [];
    }

    protected function buildUserPrompt(RunState $state, array $context = []): string
    {
        $backlogJson = json_encode($context['backlog'] ?? $state->backlog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $artifactsJson = json_encode($context['artifacts'] ?? $state->artifacts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $specJson = json_encode($context['requirements_spec'] ?? $state->requirementsSpec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $planJson = json_encode($context['technical_plan'] ?? $state->technicalPlan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return "BACKLOG & ACCEPTANCE CRITERIA:\n{$backlogJson}\n\n" .
            "REQUIREMENTS SPECIFICATION:\n{$specJson}\n\n" .
            "TECHNICAL PLAN:\n{$planJson}\n\n" .
            "IMPLEMENTED ARTIFACTS:\n{$artifactsJson}\n\n" .
            "Please generate comprehensive feature and unit test files to thoroughly test the implementation against every acceptance criterion.\n" .
            "1. Every test file must target tests/ (e.g. tests/Feature/TaskApiTest.php or tests/Unit/TaskServiceTest.php).\n" .
            "2. Test happy paths (200/201), validation errors (422), authorization (403), and tenant/domain isolation.\n" .
            "3. Output strictly structured JSON matching the output schema.";
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
                    'description' => 'List of test files to generate.',
                ],
            ],
            'required' => ['test_files'],
        ];
    }

    protected function applyOutput(RunState $state, array $output, array $tools): array
    {
        $patchTool = $this->getTool('patch_writer', $tools);
        $testFiles = (array) ($output['test_files'] ?? []);
        $artifacts = [];

        foreach ($testFiles as $file) {
            $path = (string) ($file['path'] ?? '');
            $content = (string) ($file['content'] ?? '');
            $summary = (string) ($file['summary'] ?? 'Automated test suite');

            $res = $patchTool->execute([
                'path' => $path,
                'content' => $content,
                'summary' => $summary,
            ]);

            $artifacts[] = [
                'path' => $res['path'] ?? $path,
                'action' => $state->isDryRun() ? 'preview' : ($res['action'] ?? 'created'),
                'summary' => $summary,
            ];
        }

        $count = count($artifacts);

        return [
            'summary' => "Created/updated {$count} feature & unit test files",
            'output' => $output,
            'artifacts' => $artifacts,
        ];
    }
}
