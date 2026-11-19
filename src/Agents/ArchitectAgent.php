<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Agents;

use Yousef\LaraOrcest\State\RunState;

class ArchitectAgent extends AbstractAgent
{
    public function getName(): string
    {
        return 'architect';
    }

    public function getRole(): string
    {
        return 'Senior Laravel Software Architect';
    }

    public function getGoal(): string
    {
        return 'Design a clean, idiomatic Laravel technical plan mapping tasks to migrations, Eloquent models, relationships, form requests, actions/services, controllers, routes, and tests.';
    }

    public function getBackstory(): string
    {
        return 'Deeply familiar with Laravel conventions, service container bindings, multi-tenancy patterns, query performance, and modular architecture. Avoids over-engineering while keeping code maintainable.';
    }

    public function getAllowedTools(): array
    {
        return ['file_reader'];
    }

    public function getStateOutputKey(): string
    {
        return 'technical_plan';
    }

    protected function buildUserPrompt(RunState $state): string
    {
        $backlogJson = json_encode($state->backlog, JSON_PRETTY_PRINT);
        $specJson = json_encode($state->requirementsSpec, JSON_PRETTY_PRINT);

        return "REQUIREMENTS SPEC:\n{$specJson}\n\nBACKLOG:\n{$backlogJson}\n\nDesign the Laravel technical implementation plan specifying file paths for migrations, models, controllers, requests, routes, and tests.";
    }

    protected function getOutputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'migrations' => [
                    'type' => 'object',
                    'description' => 'Mapping of migration description to target file path.',
                ],
                'models' => [
                    'type' => 'object',
                    'description' => 'Mapping of model class to file path.',
                ],
                'controllers' => [
                    'type' => 'object',
                    'description' => 'Mapping of controller class to file path.',
                ],
                'requests' => [
                    'type' => 'object',
                    'description' => 'Mapping of form request class to file path.',
                ],
                'routes' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Route files to update.',
                ],
                'tests' => [
                    'type' => 'object',
                    'description' => 'Mapping of test class to file path.',
                ],
                'execution_steps' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Ordered sequence of development steps.',
                ],
            ],
            'required' => ['migrations', 'models', 'controllers', 'execution_steps'],
        ];
    }

    protected function applyOutput(RunState $state, array $output, array $tools): RunState
    {
        return $state->withTechnicalPlan($output);
    }
}
