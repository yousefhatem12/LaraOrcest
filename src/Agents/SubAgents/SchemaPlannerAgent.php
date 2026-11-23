<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Agents\SubAgents;

use Yousef\LaraOrcest\Agents\Teams\AbstractSubAgent;
use Yousef\LaraOrcest\Contracts\ToolContract;
use Yousef\LaraOrcest\State\RunState;

class SchemaPlannerAgent extends AbstractSubAgent
{
    public function key(): string
    {
        return 'schema_planner_agent';
    }

    public function parentAgentKey(): string
    {
        return 'architect';
    }

    public function role(): string
    {
        return 'Relational Database Schema & Indexing Specialist';
    }

    public function goal(): string
    {
        return 'Design normalized database tables, primary/foreign keys, composite indexes, soft deletes, and chronological migration files for all domain entities.';
    }

    public function backstory(): string
    {
        return 'Database engineering specialist who guarantees referential integrity, indexes for high-frequency queries, audit trails, and zero-downtime reversible migrations.';
    }

    public function scopePaths(): array
    {
        return ['docs/**'];
    }

    public function allowedTools(): array
    {
        return ['file_reader', 'codebase_inspector'];
    }

    public function dependsOn(): array
    {
        return [];
    }

    protected function buildUserPrompt(RunState $state, array $context = []): string
    {
        $specJson = json_encode($context['requirements_spec'] ?? $state->requirementsSpec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $backlogJson = json_encode($context['backlog'] ?? $state->backlog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return "REQUIREMENTS SPECIFICATION:\n{$specJson}\n\n" .
            "PRODUCT BACKLOG:\n{$backlogJson}\n\n" .
            "Design the complete relational database schema and migration plan for Laravel:\n" .
            "1. Define all tables with typed columns, foreign keys, indexes, and soft-delete/audit columns.\n" .
            "2. Map migration file paths in strict chronological order in database/migrations/.\n" .
            "3. Map Eloquent model file paths in app/Models/ with relationship declarations.\n" .
            "4. Specify composite indexes and foreign key constraint rules.";
    }

    protected function getOutputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'tables' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'table' => ['type' => 'string'],
                            'columns' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'name' => ['type' => 'string'],
                                        'type' => ['type' => 'string'],
                                        'nullable' => ['type' => 'boolean'],
                                    ],
                                    'required' => ['name', 'type'],
                                ],
                            ],
                            'foreign_keys' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                            'indexes' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                        ],
                        'required' => ['table', 'columns'],
                    ],
                    'description' => 'Normalized table schemas with columns and keys.',
                ],
                'migrations' => [
                    'type' => 'object',
                    'description' => 'Mapping of migration description to file path in database/migrations/.',
                ],
                'models' => [
                    'type' => 'object',
                    'description' => 'Mapping of Eloquent model class to file path in app/Models/.',
                ],
                'migration_order' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Chronological order of migration execution.',
                ],
                'indexing_strategy' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Performance and composite indexing strategy.',
                ],
            ],
            'required' => ['tables', 'migrations', 'models'],
        ];
    }

    protected function applyOutput(RunState $state, array $output, array $tools): array
    {
        $tablesCount = count((array) ($output['tables'] ?? []));
        $migrationsCount = count((array) ($output['migrations'] ?? []));

        return [
            'summary' => "Designed schema with {$tablesCount} tables and {$migrationsCount} migrations",
            'output' => $output,
            'artifacts' => [],
        ];
    }
}
