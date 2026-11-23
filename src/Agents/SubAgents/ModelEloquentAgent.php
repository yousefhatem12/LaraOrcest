<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Agents\SubAgents;

use Yousef\LaraOrcest\Agents\Teams\AbstractSubAgent;
use Yousef\LaraOrcest\Contracts\ToolContract;
use Yousef\LaraOrcest\State\RunState;

class ModelEloquentAgent extends AbstractSubAgent
{
    public function key(): string
    {
        return 'model_eloquent_agent';
    }

    public function parentAgentKey(): string
    {
        return 'database_agent';
    }

    public function role(): string
    {
        return 'Eloquent Model & Factory Specialist';
    }

    public function goal(): string
    {
        return 'Implement strictly-typed Eloquent models, relationships, casts, scopes, and model factories corresponding to the planned database schema.';
    }

    public function backstory(): string
    {
        return 'Laravel domain modeling engineer who creates clean Eloquent models with mass-assignment protection, typed relationships, attribute casting, and comprehensive model factories.';
    }

    public function scopePaths(): array
    {
        return ['app/Models/**', 'app/Domains/**/Models/**', 'database/factories/**'];
    }

    public function allowedTools(): array
    {
        return ['file_reader', 'patch_writer', 'artisan_runner'];
    }

    public function dependsOn(): array
    {
        return ['migration_agent'];
    }

    protected function buildUserPrompt(RunState $state, array $context = []): string
    {
        $planJson = json_encode($context['technical_plan'] ?? $state->technicalPlan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $specJson = json_encode($context['requirements_spec'] ?? $state->requirementsSpec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $backlogJson = json_encode($context['backlog'] ?? $state->backlog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return "TECHNICAL PLAN:\n{$planJson}\n\n" .
            "REQUIREMENTS SPECIFICATION:\n{$specJson}\n\n" .
            "PRODUCT BACKLOG:\n{$backlogJson}\n\n" .
            "Generate complete, strictly-typed Eloquent models and model factories matching the database schema:\n" .
            "1. Target directories must be strictly app/Models/ or database/factories/.\n" .
            "2. Define explicit \$fillable attributes to protect against mass assignment.\n" .
            "3. Declare typed relationship return types (BelongsTo, HasMany, BelongsToMany).\n" .
            "4. Define attribute casts via casts(): array or \$casts.\n" .
            "5. Create corresponding Factory classes in database/factories/ using HasFactory trait.";
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
                                'description' => 'Target path in app/Models/ or database/factories/.',
                            ],
                            'content' => [
                                'type' => 'string',
                                'description' => 'Complete PHP code for the model or factory class.',
                            ],
                            'summary' => [
                                'type' => 'string',
                                'description' => 'Brief summary of the model or factory.',
                            ],
                        ],
                        'required' => ['path', 'content', 'summary'],
                    ],
                    'description' => 'List of model and factory files to write.',
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
            $summary = (string) ($file['summary'] ?? 'Eloquent model or factory');

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
            'summary' => "Created/updated {$count} Eloquent model and factory files",
            'output' => $output,
            'artifacts' => $artifacts,
        ];
    }
}
