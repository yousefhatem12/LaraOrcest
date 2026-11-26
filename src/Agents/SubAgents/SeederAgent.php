<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Agents\SubAgents;

use Yousef\LaraOrcest\Agents\Teams\AbstractSubAgent;
use Yousef\LaraOrcest\Contracts\ToolContract;
use Yousef\LaraOrcest\State\RunState;

class SeederAgent extends AbstractSubAgent
{
    public function key(): string
    {
        return 'seeder_agent';
    }

    public function parentAgentKey(): string
    {
        return 'database_agent';
    }

    public function role(): string
    {
        return 'Idempotent Database Seeder Specialist';
    }

    public function goal(): string
    {
        return 'Create structured, idempotent database seeders in database/seeders/ that populate development fixtures without duplicating records on subsequent runs.';
    }

    public function backstory(): string
    {
        return 'Specialist in test data and environment hydration who writes idempotent seeders using firstOrCreate and model factories, wiring them cleanly into DatabaseSeeder.';
    }

    public function scopePaths(): array
    {
        return ['database/seeders/**'];
    }

    public function allowedTools(): array
    {
        return ['file_reader', 'patch_writer', 'artisan_runner'];
    }

    public function dependsOn(): array
    {
        return ['model_eloquent_agent'];
    }

    public function skills(): array
    {
        return ['idempotent-database-seeders'];
    }

    protected function buildUserPrompt(RunState $state, array $context = []): string
    {
        $planJson = json_encode($context['technical_plan'] ?? $state->technicalPlan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $specJson = json_encode($context['requirements_spec'] ?? $state->requirementsSpec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return "TECHNICAL PLAN:\n{$planJson}\n\n" .
            "REQUIREMENTS SPECIFICATION:\n{$specJson}\n\n" .
            "Generate structured, idempotent database seeders in database/seeders/:\n" .
            "1. Target directory must be strictly database/seeders/.\n" .
            "2. Ensure idempotency using firstOrCreate(), updateOrCreate(), or existence checks.\n" .
            "3. Create domain-specific seeders (e.g. ProjectSeeder, UserSeeder).\n" .
            "4. Wire seeders cleanly into DatabaseSeeder calling \$this->call([...]).";
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
                                'description' => 'Target path in database/seeders/.',
                            ],
                            'content' => [
                                'type' => 'string',
                                'description' => 'Complete PHP code for the seeder class.',
                            ],
                            'summary' => [
                                'type' => 'string',
                                'description' => 'Brief summary of the seeder.',
                            ],
                        ],
                        'required' => ['path', 'content', 'summary'],
                    ],
                    'description' => 'List of seeder files to write.',
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
            $summary = (string) ($file['summary'] ?? 'Database seeder');

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
            'summary' => "Created/updated {$count} database seeder files",
            'output' => $output,
            'artifacts' => $artifacts,
        ];
    }
}
