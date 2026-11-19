<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Agents;

use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Tools\PatchWriterTool;

class DatabaseAgent extends AbstractAgent
{
    public function getName(): string
    {
        return 'database_agent';
    }

    public function getRole(): string
    {
        return 'Laravel Database & Eloquent Specialist';
    }

    public function getGoal(): string
    {
        return 'Create and update database migrations, foreign keys, indexes, factories, and Eloquent models with proper relationships, casts, and scopes.';
    }

    public function getBackstory(): string
    {
        return 'Careful data engineer who designs normalized, index-aware schemas and clean Eloquent models aligned with technical_plan and tenant/ownership rules.';
    }

    public function getAllowedTools(): array
    {
        return ['file_reader', 'patch_writer', 'artisan_runner'];
    }

    public function getStateOutputKey(): string
    {
        return 'artifacts';
    }

    protected function buildUserPrompt(RunState $state): string
    {
        $planJson = json_encode($state->technicalPlan, JSON_PRETTY_PRINT);
        $specJson = json_encode($state->requirementsSpec, JSON_PRETTY_PRINT);

        return "TECHNICAL PLAN:\n{$planJson}\n\nREQUIREMENTS:\n{$specJson}\n\nPlease generate the exact code for the necessary migrations, factories, and Eloquent models.";
    }

    protected function getOutputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'files' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'path' => [
                                'type' => 'string',
                                'description' => 'Target path (must start with database/ or app/Models/).',
                            ],
                            'content' => [
                                'type' => 'string',
                                'description' => 'Complete PHP file code.',
                            ],
                            'summary' => [
                                'type' => 'string',
                                'description' => 'Summary of the model or migration.',
                            ],
                        ],
                        'required' => ['path', 'content', 'summary'],
                    ],
                ],
            ],
            'required' => ['files'],
        ];
    }

    protected function applyOutput(RunState $state, array $output, array $tools): RunState
    {
        /** @var PatchWriterTool $patchTool */
        $patchTool = $this->getTool('patch_writer', $tools);
        // Restrict to database/ and app/Models/
        $patchTool->setAllowedPrefixes(['database/', 'app/Models/']);
        $patchTool->setDryRun($state->isDryRun());

        $files = (array) ($output['files'] ?? []);

        foreach ($files as $file) {
            $path = (string) ($file['path'] ?? '');
            $content = (string) ($file['content'] ?? '');
            $summary = (string) ($file['summary'] ?? 'Database schema/model update');

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
