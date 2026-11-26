<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Agents\SubAgents;

use Yousef\LaraOrcest\Agents\Teams\AbstractSubAgent;
use Yousef\LaraOrcest\Contracts\ToolContract;
use Yousef\LaraOrcest\State\RunState;

class ControllerResourceAgent extends AbstractSubAgent
{
    public function key(): string
    {
        return 'controller_resource_agent';
    }

    public function parentAgentKey(): string
    {
        return 'backend_agent';
    }

    public function role(): string
    {
        return 'Controller, API Resource & Route Specialist';
    }

    public function goal(): string
    {
        return 'Create thin API controllers delegating to Actions/Services, JsonResource response transformers, and register RESTful route endpoints.';
    }

    public function backstory(): string
    {
        return 'API design engineer who builds lightweight controllers, transforms models through typed JsonResource envelopes, and registers clean REST routes with appropriate middleware in routes/api.php.';
    }

    public function scopePaths(): array
    {
        return [
            'app/Http/Controllers/**',
            'app/Http/Resources/**',
            'routes/**',
            'app/Domains/**/Http/**',
        ];
    }

    public function allowedTools(): array
    {
        return ['file_reader', 'patch_writer', 'artisan_runner'];
    }

    public function dependsOn(): array
    {
        return ['service_action_agent'];
    }

    public function skills(): array
    {
        return ['controllers-resources-routes'];
    }

    protected function buildUserPrompt(RunState $state, array $context = []): string
    {
        $planJson = json_encode($context['technical_plan'] ?? $state->technicalPlan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $backlogJson = json_encode($context['backlog'] ?? $state->backlog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return "TECHNICAL PLAN:\n{$planJson}\n\n" .
            "PRODUCT BACKLOG:\n{$backlogJson}\n\n" .
            "Generate complete, strictly-typed API controllers, JsonResource transformers, and RESTful route declarations:\n" .
            "1. Target directories must strictly be app/Http/Controllers/, app/Http/Resources/, or routes/.\n" .
            "2. Controllers must remain thin, delegating to FormRequests and Actions/Services.\n" .
            "3. Format API responses using JsonResource classes with explicit attribute mappings.\n" .
            "4. Register routes in routes/api.php using apiResource or explicit verbs with status codes (200, 201, 204).";
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
                                'description' => 'Target path in app/Http/Controllers/, app/Http/Resources/, or routes/.',
                            ],
                            'content' => [
                                'type' => 'string',
                                'description' => 'Complete PHP code for the controller, resource, or routes file.',
                            ],
                            'summary' => [
                                'type' => 'string',
                                'description' => 'Brief summary of the controller, resource, or route changes.',
                            ],
                        ],
                        'required' => ['path', 'content', 'summary'],
                    ],
                    'description' => 'List of controller, resource, and route files to write.',
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
            $summary = (string) ($file['summary'] ?? 'Controller, resource, or route update');

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
            'summary' => "Created/updated {$count} controller, resource, and route files",
            'output' => $output,
            'artifacts' => $artifacts,
        ];
    }
}
