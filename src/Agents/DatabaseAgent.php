<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Agents;

use Yousef\LaraOrcest\Contracts\LlmDriverContract;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Support\Config;
use Yousef\LaraOrcest\Tools\PatchWriterTool;

class DatabaseAgent extends AbstractAgent
{
    public function getName(): string
    {
        return 'database_agent';
    }

    public function getRole(): string
    {
        return 'Laravel Database & Eloquent Specialist';
    }

    public function getGoal(): string
    {
        return 'Create and update database migrations, foreign keys, indexes, factories, and Eloquent models with proper relationships, casts, and scopes.';
    }

    public function getBackstory(): string
    {
        return 'Careful data engineer who designs normalized, index-aware schemas and clean Eloquent models aligned with technical_plan and tenant/ownership rules.';
    }

    public function getAllowedTools(): array
    {
        return ['file_reader', 'patch_writer', 'artisan_runner'];
    }

    public function getStateOutputKey(): string
    {
        return 'artifacts';
    }

    public function execute(RunState $state, array $tools, LlmDriverContract $llm): RunState
    {
        $teamsEnabled = (bool) Config::get('lara-orcest.teams.enabled', true);
        $subRegistry = $this->getTeamDelegator()->getSubAgentRegistry();

        $subKeys = ['migration_agent', 'model_eloquent_agent', 'seeder_agent'];
        $hasAllSubAgents = true;
        foreach ($subKeys as $k) {
            if (! $subRegistry->has($k)) {
                $hasAllSubAgents = false;
                break;
            }
        }

        if ($teamsEnabled && $hasAllSubAgents) {
            try {
                $activeSkills = [
                    'laravel-safe-migrations',
                    'eloquent-models-and-factories',
                    'idempotent-database-seeders',
                ];

                $context = [
                    'requirements_spec' => $state->requirementsSpec,
                    'backlog' => $state->backlog,
                    'technical_plan' => $state->technicalPlan,
                    'prompt' => $state->prompt,
                    'scraped_data' => $state->getScrapingResults(),
                    'project_memory' => $this->resolveProjectMemoryContext($state),
                    'target_project_path' => $state->targetProjectPath,
                    'workspace_mode' => $state->workspaceMode,
                    'package_manager' => $state->metadata['package_manager'] ?? [],
                    'artifacts' => $state->artifacts,
                ];

                $delegationResult = $this->delegateSubTasks(
                    state: $state,
                    subAgentKeys: $subKeys,
                    context: $context,
                    tools: $tools,
                    llm: $llm,
                    activeSkillNames: $activeSkills
                );

                return $delegationResult->state;
            } catch (\Throwable) {
                // Fallback gracefully
            }
        }

        return parent::execute($state, $tools, $llm);
    }

    protected function buildUserPrompt(RunState $state): string
    {
        $planJson = json_encode($state->technicalPlan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $specJson = json_encode($state->requirementsSpec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $parts = [
            "TECHNICAL PLAN:\n{$planJson}",
            "REQUIREMENTS:\n{$specJson}",
        ];

        $scraping = $state->getScrapingResults();
        if (! empty($scraping) && ! empty($scraping['unified_payload'])) {
            $payloadJson = json_encode($scraping['unified_payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $parts[] = "INGESTED DATA STRUCTURE / PAYLOAD (from Web Scraping Agent):\n{$payloadJson}";
        }

        $parts[] = "Please generate the exact code for the necessary migrations, factories, and Eloquent models.";

        return implode("\n\n", $parts);
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
                                'description' => 'Target path (must start with database/ or app/Models/).',
                            ],
                            'content' => [
                                'type' => 'string',
                                'description' => 'Complete PHP file code.',
                            ],
                            'summary' => [
                                'type' => 'string',
                                'description' => 'Summary of the model or migration.',
                            ],
                        ],
                        'required' => ['path', 'content', 'summary'],
                    ],
                ],
            ],
            'required' => ['files'],
        ];
    }

    protected function applyOutput(RunState $state, array $output, array $tools): RunState
    {
        /** @var PatchWriterTool $patchTool */
        $patchTool = $this->getTool('patch_writer', $tools);
        // Restrict to database/ and app/Models/
        $patchTool->setAllowedPrefixes(['database/', 'app/Models/']);
        $patchTool->setDryRun($state->isDryRun());

        $files = (array) ($output['files'] ?? []);

        foreach ($files as $file) {
            $path = (string) ($file['path'] ?? '');
            $content = (string) ($file['content'] ?? '');
            $summary = (string) ($file['summary'] ?? 'Database schema/model update');

            $res = $patchTool->execute([
                'path' => $path,
                'content' => $content,
                'summary' => $summary,
            ]);

            $state = $state->recordArtifact($res['path'], $res['action'], $summary);
        }

        return $state;
    }
}
