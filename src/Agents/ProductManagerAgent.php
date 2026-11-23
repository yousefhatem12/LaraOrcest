<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Agents;

use Yousef\LaraOrcest\Contracts\LlmDriverContract;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Support\Config;

class ProductManagerAgent extends AbstractAgent
{
    public function getName(): string
    {
        return 'product_manager';
    }

    public function getRole(): string
    {
        return 'Technical Product Manager';
    }

    public function getGoal(): string
    {
        return 'Break down requirements_spec into ordered, scoped implementation tasks with concrete, testable acceptance criteria.';
    }

    public function getBackstory(): string
    {
        return 'Pragmatic product manager focused on vertical slices, clear priorities, and verifiable acceptance criteria so engineering and QA share a single definition of done.';
    }

    public function getAllowedTools(): array
    {
        return ['file_reader'];
    }

    public function getStateOutputKey(): string
    {
        return 'backlog';
    }

    public function execute(RunState $state, array $tools, LlmDriverContract $llm): RunState
    {
        $teamsEnabled = (bool) Config::get('lara-orcest.teams.enabled', true);
        $subRegistry = $this->getTeamDelegator()->getSubAgentRegistry();

        if ($teamsEnabled && $subRegistry->has('backlog_splitter_agent')) {
            try {
                $subKeys = ['backlog_splitter_agent'];
                $activeSkills = ['backlog-task-decomposition'];

                $delegationResult = $this->delegateSubTasks(
                    state: $state,
                    subAgentKeys: $subKeys,
                    context: [
                        'requirements_spec' => $state->requirementsSpec,
                        'prompt' => $state->prompt,
                    ],
                    tools: $tools,
                    llm: $llm,
                    activeSkillNames: $activeSkills
                );

                $updatedState = $delegationResult->state;
                $splitterResult = $delegationResult->getResult('backlog_splitter_agent');
                $tasks = (array) ($splitterResult['output']['tasks'] ?? []);

                if (! empty($tasks)) {
                    $updatedState = $this->applyOutput($updatedState, ['tasks' => $tasks], $tools);
                }

                return $updatedState;
            } catch (\Throwable) {
                // Fallback gracefully
            }
        }

        return parent::execute($state, $tools, $llm);
    }

    protected function buildUserPrompt(RunState $state): string
    {
        $specJson = json_encode($state->requirementsSpec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return "REQUIREMENTS SPECIFICATION:\n{$specJson}\n\nPlease convert this specification into an ordered, prioritized implementation backlog. Each task must have concrete, testable acceptance criteria, explicit depends_on dependencies, and directory scope_paths.";
    }

    protected function getOutputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'tasks' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'string'],
                            'title' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'priority' => ['type' => 'integer'],
                            'acceptance_criteria' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                            'dependencies' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                            'depends_on' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                            'scope_paths' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                            'risk_assessment' => ['type' => 'string'],
                        ],
                        'required' => ['id', 'title', 'priority', 'acceptance_criteria'],
                    ],
                ],
            ],
            'required' => ['tasks'],
        ];
    }

    protected function applyOutput(RunState $state, array $output, array $tools): RunState
    {
        $tasks = (array) ($output['tasks'] ?? []);
        $normalized = [];

        foreach ($tasks as $task) {
            if (! is_array($task)) {
                continue;
            }
            $deps = (array) ($task['depends_on'] ?? $task['dependencies'] ?? []);
            $scopes = (array) ($task['scope_paths'] ?? []);

            $normalized[] = array_merge($task, [
                'depends_on' => array_values(array_map('strval', $deps)),
                'dependencies' => array_values(array_map('strval', $deps)),
                'scope_paths' => array_values(array_map('strval', $scopes)),
            ]);
        }

        return $state->withBacklog($normalized);
    }
}

