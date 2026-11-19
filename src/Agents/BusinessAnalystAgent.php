<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Agents;

use Yousef\LaraOrcest\State\RunState;

class BusinessAnalystAgent extends AbstractAgent
{
    public function getName(): string
    {
        return 'business_analyst';
    }

    public function getRole(): string
    {
        return 'Laravel Domain & Business Analyst';
    }

    public function getGoal(): string
    {
        return 'Transform a raw user prompt and existing codebase context into a clear, unambiguous requirements specification covering business rules, roles/permissions, workflows, and edge cases.';
    }

    public function getBackstory(): string
    {
        return 'Experienced product-minded systems analyst who prevents vague specifications and missing edge cases—such as billing states, tenant isolation, invalid transitions, and authorization boundaries—before any code is written.';
    }

    public function getAllowedTools(): array
    {
        return ['file_reader'];
    }

    public function getStateOutputKey(): string
    {
        return 'requirements_spec';
    }

    protected function buildUserPrompt(RunState $state): string
    {
        return "USER PROMPT:\n{$state->prompt}\n\nPlease analyze the domain requirements, business entities, validation rules, authorization roles, edge cases, and non-functional requirements.";
    }

    protected function getOutputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'domain_entities' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'List of core domain model names.',
                ],
                'functional_requirements' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Key functional capabilities required.',
                ],
                'business_rules' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Explicit business invariants and domain rules.',
                ],
                'roles_and_permissions' => [
                    'type' => 'object',
                    'description' => 'Mapping of roles to allowed operations.',
                ],
                'edge_cases' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Potential edge cases or boundary conditions.',
                ],
                'non_functional' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Performance, security, or tenant requirements.',
                ],
            ],
            'required' => ['domain_entities', 'functional_requirements', 'business_rules'],
        ];
    }

    protected function applyOutput(RunState $state, array $output, array $tools): RunState
    {
        return $state->withRequirementsSpec($output);
    }
}
