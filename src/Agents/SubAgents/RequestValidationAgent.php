<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Agents\SubAgents;

use Yousef\LaraOrcest\Agents\Teams\AbstractSubAgent;
use Yousef\LaraOrcest\Contracts\ToolContract;
use Yousef\LaraOrcest\State\RunState;

class RequestValidationAgent extends AbstractSubAgent
{
    public function key(): string
    {
        return 'request_validation_agent';
    }

    public function parentAgentKey(): string
    {
        return 'backend_agent';
    }

    public function role(): string
    {
        return 'Laravel FormRequest & Authorization Specialist';
    }

    public function goal(): string
    {
        return 'Create FormRequest validation rules, authorization logic, and Policy classes matching technical_plan and domain invariants.';
    }

    public function backstory(): string
    {
        return 'Laravel security and input validation specialist who builds robust FormRequest validation classes with custom rules, tenant-scoped database lookups, and strict Policy authorization gates.';
    }

    public function scopePaths(): array
    {
        return [
            'app/Http/Requests/**',
            'app/Domains/**/Requests/**',
            'app/Policies/**',
            'app/Domains/**/Policies/**',
        ];
    }

    public function allowedTools(): array
    {
        return ['file_reader', 'patch_writer', 'artisan_runner'];
    }

    public function dependsOn(): array
    {
        return [];
    }

    protected function buildUserPrompt(RunState $state, array $context = []): string
    {
        $planJson = json_encode($context['technical_plan'] ?? $state->technicalPlan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $specJson = json_encode($context['requirements_spec'] ?? $state->requirementsSpec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $backlogJson = json_encode($context['backlog'] ?? $state->backlog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return "TECHNICAL PLAN:\n{$planJson}\n\n" .
            "REQUIREMENTS SPECIFICATION:\n{$specJson}\n\n" .
            "PRODUCT BACKLOG:\n{$backlogJson}\n\n" .
            "Generate complete, strictly-typed FormRequest validation classes and authorization Policies:\n" .
            "1. Target directories must strictly be app/Http/Requests/ or app/Policies/.\n" .
            "2. Define explicit rules() with typed validation arrays.\n" .
            "3. Enforce authorization checks in authorize() and Policy classes.\n" .
            "4. Scope uniqueness and foreign key validations by tenant/project where appropriate.";
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
                                'description' => 'Target path in app/Http/Requests/ or app/Policies/.',
                            ],
                            'content' => [
                                'type' => 'string',
                                'description' => 'Complete PHP code for the request or policy class.',
                            ],
                            'summary' => [
                                'type' => 'string',
                                'description' => 'Brief summary of the validation or policy class.',
                            ],
                        ],
                        'required' => ['path', 'content', 'summary'],
                    ],
                    'description' => 'List of request and policy files to write.',
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
            $summary = (string) ($file['summary'] ?? 'Request validation or policy class');

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
            'summary' => "Created/updated {$count} request validation and policy files",
            'output' => $output,
            'artifacts' => $artifacts,
        ];
    }
}
