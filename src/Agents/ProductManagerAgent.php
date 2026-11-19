<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Agents;

use Yousef\LaraOrcest\State\RunState;

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

    protected function buildUserPrompt(RunState $state): string
    {
        $specJson = json_encode($state->requirementsSpec, JSON_PRETTY_PRINT);

        return "REQUIREMENTS SPECIFICATION:\n{$specJson}\n\nPlease convert this specification into an ordered, prioritized implementation backlog. Each task must have concrete, testable acceptance criteria.";
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

        return $state->withBacklog($tasks);
    }
}
