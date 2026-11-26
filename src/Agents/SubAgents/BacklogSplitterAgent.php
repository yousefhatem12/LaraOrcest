<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Agents\SubAgents;

use Yousef\LaraOrcest\Agents\Teams\AbstractSubAgent;
use Yousef\LaraOrcest\Contracts\ToolContract;
use Yousef\LaraOrcest\State\RunState;

class BacklogSplitterAgent extends AbstractSubAgent
{
    public function key(): string
    {
        return 'backlog_splitter_agent';
    }

    public function parentAgentKey(): string
    {
        return 'product_manager';
    }

    public function role(): string
    {
        return 'Task Decomposition & Dependency Planner';
    }

    public function goal(): string
    {
        return 'Decompose a requirements specification into prioritized, atomic implementation tasks with explicit depends_on dependencies, strict scope_paths allow-lists, acceptance criteria, and risk assessments.';
    }

    public function backstory(): string
    {
        return 'Technical product owner and backlog engineer who breaks features down into vertical slices with explicit dependency ordering, strict directory scoping, and testable acceptance criteria.';
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
        return ['backlog-task-decomposition'];
    }

    protected function buildUserPrompt(RunState $state, array $context = []): string
    {
        $spec = $context['requirements_spec'] ?? $state->requirementsSpec;
        $specJson = json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return "REQUIREMENTS SPECIFICATION:\n{$specJson}\n\n" .
            "Decompose this specification into granular, prioritized engineering tasks.\n" .
            "Each task MUST have:\n" .
            "- id: Unique ID (e.g. TASK-001)\n" .
            "- title, description, priority (1=highest)\n" .
            "- acceptance_criteria: List of testable assertions\n" .
            "- depends_on: Array of preceding task IDs (empty if can start immediately)\n" .
            "- scope_paths: Specific directory allow-list (e.g. ['database/migrations/**'] or ['app/Models/**'])\n" .
            "- risk_assessment: Potential risks or complexity notes";
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
                            'depends_on' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                            'dependencies' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                            'scope_paths' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                            'risk_assessment' => ['type' => 'string'],
                        ],
                        'required' => ['id', 'title', 'priority', 'acceptance_criteria', 'depends_on', 'scope_paths'],
                    ],
                ],
            ],
            'required' => ['tasks'],
        ];
    }

    protected function applyOutput(RunState $state, array $output, array $tools): array
    {
        $rawTasks = (array) ($output['tasks'] ?? []);
        $normalized = [];

        foreach ($rawTasks as $task) {
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

        return [
            'summary' => 'Decomposed specification into ' . count($normalized) . ' scoped implementation tasks',
            'output' => ['tasks' => $normalized],
            'artifacts' => [],
        ];
    }
}
