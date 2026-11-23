<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Agents\SubAgents;

use Yousef\LaraOrcest\Agents\Teams\AbstractSubAgent;
use Yousef\LaraOrcest\Contracts\ToolContract;
use Yousef\LaraOrcest\State\RunState;

class MigrationAgent extends AbstractSubAgent
{
    public function key(): string
    {
        return 'migration_agent';
    }

    public function parentAgentKey(): string
    {
        return 'database_agent';
    }

    public function role(): string
    {
        return 'Laravel Database Migration Specialist';
    }

    public function goal(): string
    {
        return 'Create and update chronological, reversible Laravel migration files in database/migrations/ with accurate schema types, foreign key constraints, and performance indexes.';
    }

    public function backstory(): string
    {
        return 'Expert database engineer focused on schema integrity, zero-downtime migrations, foreign key cascades, composite indexing, and reversible rollback logic in Laravel.';
    }

    public function scopePaths(): array
    {
        return ['database/migrations/**'];
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
            "Generate complete, strictly-typed anonymous Laravel migration files for all planned tables.\n" .
            "1. Target directory must be strictly database/migrations/.\n" .
            "2. Order migrations chronologically matching foreign key dependencies.\n" .
            "3. Include both up() and down() methods with full rollback symmetry.\n" .
            "4. Define foreignId constrained relationships and composite indexes.";
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
                                'description' => 'Target path in database/migrations/.',
                            ],
                            'content' => [
                                'type' => 'string',
                                'description' => 'Complete PHP code for the migration file.',
                            ],
                            'summary' => [
                                'type' => 'string',
                                'description' => 'Brief summary of the migration purpose.',
                            ],
                        ],
                        'required' => ['path', 'content', 'summary'],
                    ],
                    'description' => 'List of migration files to write.',
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
            $summary = (string) ($file['summary'] ?? 'Database migration');

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
            'summary' => "Created/updated {$count} database migration files",
            'output' => $output,
            'artifacts' => $artifacts,
        ];
    }
}
