<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Agents\SubAgents;

use Yousef\LaraOrcest\Agents\Teams\AbstractSubAgent;
use Yousef\LaraOrcest\Contracts\ToolContract;
use Yousef\LaraOrcest\State\RunState;

class DomainRulesAgent extends AbstractSubAgent
{
    public function key(): string
    {
        return 'domain_rules_agent';
    }

    public function parentAgentKey(): string
    {
        return 'business_analyst';
    }

    public function role(): string
    {
        return 'Domain Rules & Business Invariants Specialist';
    }

    public function goal(): string
    {
        return 'Analyze user requirements to uncover deep domain rules, business invariants, state machine transitions, role authorizations, and boundary edge cases.';
    }

    public function backstory(): string
    {
        return 'Domain-Driven Design (DDD) specialist who guarantees that business logic constraints, authorization rules, and edge conditions are comprehensively documented before technical implementation begins.';
    }

    public function scopePaths(): array
    {
        return ['docs/**'];
    }

    public function allowedTools(): array
    {
        return ['file_reader'];
    }

    public function dependsOn(): array
    {
        return [];
    }

    public function skills(): array
    {
        return ['business-requirements-analysis'];
    }

    protected function buildUserPrompt(RunState $state, array $context = []): string
    {
        $prompt = (string) ($context['prompt'] ?? $state->prompt);

        return "ANALYZE DOMAIN RULES & INVARIANTS FOR PROMPT:\n{$prompt}\n\n" .
            "Identify:\n" .
            "1. Domain Invariants (rules that must always hold true)\n" .
            "2. State Machine Transitions (from_state -> to_state with conditions)\n" .
            "3. Boundary Edge Cases (unhappy paths, invalid inputs, concurrent requests)\n" .
            "4. Ambiguities or Missing Definitions";
    }

    protected function getOutputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'business_rules' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Explicit business invariants and domain validation rules.',
                ],
                'state_transitions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'entity' => ['type' => 'string'],
                            'from_state' => ['type' => 'string'],
                            'to_state' => ['type' => 'string'],
                            'condition' => ['type' => 'string'],
                        ],
                        'required' => ['entity', 'from_state', 'to_state'],
                    ],
                    'description' => 'Allowed entity lifecycle transitions.',
                ],
                'edge_cases' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Critical boundary edge cases and failure modes.',
                ],
                'ambiguities' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Unresolved ambiguities, missing specs, or open questions.',
                ],
            ],
            'required' => ['business_rules', 'edge_cases'],
        ];
    }

    protected function applyOutput(RunState $state, array $output, array $tools): array
    {
        $rulesCount = count((array) ($output['business_rules'] ?? []));
        $edgeCasesCount = count((array) ($output['edge_cases'] ?? []));

        return [
            'summary' => "Extracted {$rulesCount} domain rules and {$edgeCasesCount} edge cases",
            'output' => $output,
            'artifacts' => [],
        ];
    }
}
