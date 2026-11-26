<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Agents\SubAgents;

use Yousef\LaraOrcest\Agents\Teams\AbstractSubAgent;
use Yousef\LaraOrcest\Contracts\ToolContract;
use Yousef\LaraOrcest\State\RunState;

class DddBoundaryAgent extends AbstractSubAgent
{
    public function key(): string
    {
        return 'ddd_boundary_agent';
    }

    public function parentAgentKey(): string
    {
        return 'architect';
    }

    public function role(): string
    {
        return 'Modular DDD Architecture & Boundary Specialist';
    }

    public function goal(): string
    {
        return 'Define domain layers, bounded contexts, module boundaries, service/action contracts, and multi-tenant isolation invariants conforming to idiomatic Laravel conventions.';
    }

    public function backstory(): string
    {
        return 'Domain-Driven Design architect who balances clean architectural boundaries (Domain, Application, Infrastructure) with Laravel simplicity, avoiding excessive abstraction while guaranteeing tenant isolation and modularity.';
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

    public function skills(): array
    {
        return ['laravel-modular-ddd-architecture'];
    }

    protected function buildUserPrompt(RunState $state, array $context = []): string
    {
        $specJson = json_encode($context['requirements_spec'] ?? $state->requirementsSpec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $backlogJson = json_encode($context['backlog'] ?? $state->backlog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return "REQUIREMENTS SPECIFICATION:\n{$specJson}\n\n" .
            "PRODUCT BACKLOG:\n{$backlogJson}\n\n" .
            "Architect the Domain-Driven Design boundaries for this Laravel application:\n" .
            "1. Define Bounded Contexts / Modules (e.g. Identity, Billing, Tasks).\n" .
            "2. Establish Layered Boundaries (Domain Entities, Application Services/Actions, Infrastructure).\n" .
            "3. Specify Multi-Tenant Isolation Rules (tenant_id foreign keys, global query scopes, policies).\n" .
            "4. Enumerate Action / Service Contracts and Directory Layout.";
    }

    protected function getOutputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'bounded_contexts' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'entities' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                        ],
                        'required' => ['name', 'entities'],
                    ],
                    'description' => 'List of bounded contexts or modules.',
                ],
                'directory_structure' => [
                    'type' => 'object',
                    'description' => 'Recommended directory structure mapping.',
                ],
                'domain_services' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'class' => ['type' => 'string'],
                            'responsibility' => ['type' => 'string'],
                        ],
                        'required' => ['class', 'responsibility'],
                    ],
                    'description' => 'Domain services, actions, or repositories.',
                ],
                'tenant_isolation_strategy' => [
                    'type' => 'string',
                    'description' => 'Strategy for enforcing multi-tenant isolation.',
                ],
                'architectural_rules' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Key architectural invariants and coding constraints.',
                ],
            ],
            'required' => ['bounded_contexts', 'directory_structure', 'architectural_rules'],
        ];
    }

    protected function applyOutput(RunState $state, array $output, array $tools): array
    {
        $contextsCount = count((array) ($output['bounded_contexts'] ?? []));

        return [
            'summary' => "Architected {$contextsCount} bounded contexts and modular DDD directory layout",
            'output' => $output,
            'artifacts' => [],
        ];
    }
}
