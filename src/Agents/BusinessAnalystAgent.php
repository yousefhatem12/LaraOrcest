<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Agents;

use Yousef\LaraOrcest\Contracts\LlmDriverContract;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Support\Config;

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

    protected function getDefaultSkills(): array
    {
        return ['business-requirements-analysis'];
    }

    public function execute(RunState $state, array $tools, LlmDriverContract $llm): RunState
    {
        $teamsEnabled = (bool) Config::get('lara-orcest.teams.enabled', true);
        $subRegistry = $this->getTeamDelegator()->getSubAgentRegistry();

        if ($teamsEnabled && $subRegistry->has('domain_rules_agent')) {
            try {
                $delegationResult = $this->delegateSubTasks(
                    state: $state,
                    subAgentKeys: ['domain_rules_agent'],
                    context: [
                        'prompt' => $state->prompt,
                    ],
                    tools: $tools,
                    llm: $llm,
                    activeSkillNames: ['business-requirements-analysis']
                );

                $updatedState = $delegationResult->state;
                $domainRulesOutput = (array) ($delegationResult->getResult('domain_rules_agent')['output'] ?? []);

                $authorizedTools = $this->authorizeTools($tools);
                $userPrompt = $this->buildEnrichedUserPrompt($updatedState, $domainRulesOutput);

                $memoryContext = $this->resolveProjectMemoryContext($updatedState);
                if ($memoryContext !== null && $memoryContext !== '') {
                    $userPrompt = $memoryContext . "\n\n" . $userPrompt;
                }

                $messages = [
                    ['role' => 'system', 'content' => $this->buildSystemPrompt($updatedState)],
                    ['role' => 'user', 'content' => $userPrompt],
                ];

                $options = [
                    'run_id' => $updatedState->runId,
                    'agent_key' => $this->getName(),
                    'node_id' => $updatedState->currentNode,
                ];

                $agentModel = $this->resolveModel();
                if ($agentModel !== null && $agentModel !== '') {
                    $options['model'] = $agentModel;
                }

                $response = $llm->chatStructured($messages, $this->getOutputSchema(), $options);
                $data = $response['data'] ?? [];

                if (! empty($domainRulesOutput['business_rules']) && is_array($domainRulesOutput['business_rules'])) {
                    $data['business_rules'] = array_values(array_unique(array_merge(
                        (array) ($data['business_rules'] ?? []),
                        $domainRulesOutput['business_rules']
                    )));
                }

                if (! empty($domainRulesOutput['edge_cases']) && is_array($domainRulesOutput['edge_cases'])) {
                    $data['edge_cases'] = array_values(array_unique(array_merge(
                        (array) ($data['edge_cases'] ?? []),
                        $domainRulesOutput['edge_cases']
                    )));
                }

                if (! empty($domainRulesOutput['state_transitions']) && is_array($domainRulesOutput['state_transitions'])) {
                    $data['state_transitions'] = $domainRulesOutput['state_transitions'];
                }

                if (! empty($domainRulesOutput['ambiguities']) && is_array($domainRulesOutput['ambiguities'])) {
                    $data['ambiguities'] = $domainRulesOutput['ambiguities'];
                }

                $usage = $response['usage'] ?? [];
                $promptTokens = (int) ($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0);
                $completionTokens = (int) ($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0);
                $updatedState = $updatedState->recordTokenUsage(max(0, $promptTokens), max(0, $completionTokens));

                return $this->applyOutput($updatedState, $data, $authorizedTools);
            } catch (\Throwable) {
                // Fallback gracefully
            }
        }

        return parent::execute($state, $tools, $llm);
    }

    protected function buildEnrichedUserPrompt(RunState $state, array $domainRulesOutput = []): string
    {
        $prompt = $this->buildUserPrompt($state);

        if (! empty($domainRulesOutput)) {
            $rulesJson = json_encode($domainRulesOutput, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $prompt .= "\n\nDISCOVERED DOMAIN RULES & INVARIANTS (from DomainRulesAgent):\n{$rulesJson}\n" .
                "Incorporate these domain rules, state transitions, and edge cases into the complete requirements specification.";
        }

        return $prompt;
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

