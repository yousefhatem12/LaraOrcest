<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Agents\SubAgents;

use Yousef\LaraOrcest\Agents\Teams\AbstractSubAgent;
use Yousef\LaraOrcest\Contracts\ToolContract;
use Yousef\LaraOrcest\State\RunState;

class ServiceActionAgent extends AbstractSubAgent
{
    public function key(): string
    {
        return 'service_action_agent';
    }

    public function parentAgentKey(): string
    {
        return 'backend_agent';
    }

    public function role(): string
    {
        return 'Domain Action & Service Workflow Specialist';
    }

    public function goal(): string
    {
        return 'Implement single-responsibility Action classes, domain service workflows, database transactions, and event dispatches corresponding to the technical plan.';
    }

    public function backstory(): string
    {
        return 'Laravel application workflow engineer who isolates business logic into reusable, testable Action and Service classes, guarantees transactional integrity with DB::transaction, and dispatches domain events.';
    }

    public function scopePaths(): array
    {
        return [
            'app/Services/**',
            'app/Actions/**',
            'app/Events/**',
            'app/Listeners/**',
            'app/Domains/**/Actions/**',
            'app/Domains/**/Services/**',
        ];
    }

    public function allowedTools(): array
    {
        return ['file_reader', 'patch_writer', 'artisan_runner'];
    }

    public function dependsOn(): array
    {
        return ['request_validation_agent'];
    }

    public function skills(): array
    {
        return ['service-action-workflows'];
    }

    protected function buildUserPrompt(RunState $state, array $context = []): string
    {
        $planJson = json_encode($context['technical_plan'] ?? $state->technicalPlan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $specJson = json_encode($context['requirements_spec'] ?? $state->requirementsSpec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $backlogJson = json_encode($context['backlog'] ?? $state->backlog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return "TECHNICAL PLAN:\n{$planJson}\n\n" .
            "REQUIREMENTS SPECIFICATION:\n{$specJson}\n\n" .
            "PRODUCT BACKLOG:\n{$backlogJson}\n\n" .
            "Generate complete, strictly-typed Action and Service workflow classes:\n" .
            "1. Target directories must strictly be app/Actions/, app/Services/, or app/Events/.\n" .
            "2. Follow the single-responsibility Action pattern with an execute() method.\n" .
            "3. Wrap state-mutating multi-table operations in DB::transaction().\n" .
            "4. Dispatch domain events upon completion and throw strongly-typed exceptions on errors.";
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
                                'description' => 'Target path in app/Actions/, app/Services/, or app/Events/.',
                            ],
                            'content' => [
                                'type' => 'string',
                                'description' => 'Complete PHP code for the action or service class.',
                            ],
                            'summary' => [
                                'type' => 'string',
                                'description' => 'Brief summary of the action or service.',
                            ],
                        ],
                        'required' => ['path', 'content', 'summary'],
                    ],
                    'description' => 'List of action and service files to write.',
                ],
            ],
            'required' => ['files'],
        ];
    }

    protected function applyOutput(RunState $state, array $output, array $tools): array
    {
        $patchTool = $this->getTool('patch_writer', $tools);
        $files = (array) ($output['files'] ?? []);
        $artifacts = [];

        foreach ($files as $file) {
            $path = (string) ($file['path'] ?? '');
            $content = (string) ($file['content'] ?? '');
            $summary = (string) ($file['summary'] ?? 'Action or service workflow');

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
            'summary' => "Created/updated {$count} action and service workflow files",
            'output' => $output,
            'artifacts' => $artifacts,
        ];
    }
}
