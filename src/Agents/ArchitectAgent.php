<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Agents;

use Yousef\LaraOrcest\Contracts\LlmDriverContract;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Support\Config;

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
        return ['file_reader', 'codebase_inspector'];
    }

    public function getStateOutputKey(): string
    {
        return 'technical_plan';
    }

    public function execute(RunState $state, array $tools, LlmDriverContract $llm): RunState
    {
        $teamsEnabled = (bool) Config::get('lara-orcest.teams.enabled', true);
        $subRegistry = $this->getTeamDelegator()->getSubAgentRegistry();

        $subKeys = ['ddd_boundary_agent', 'schema_planner_agent', 'api_contract_agent'];
        $hasAllSubAgents = true;
        foreach ($subKeys as $k) {
            if (! $subRegistry->has($k)) {
                $hasAllSubAgents = false;
                break;
            }
        }

        $hasPlanInputs = ! empty($state->requirementsSpec) || ! empty($state->backlog);

        if ($teamsEnabled && $hasAllSubAgents && $hasPlanInputs) {
            try {
                $activeSkills = [
                    'laravel-modular-ddd-architecture',
                    'relational-schema-indexing',
                    'rest-api-contract-design',
                ];

                $context = [
                    'requirements_spec' => $state->requirementsSpec,
                    'backlog' => $state->backlog,
                    'prompt' => $state->prompt,
                    'scraped_data' => $state->getScrapingResults(),
                    'project_memory' => $this->resolveProjectMemoryContext($state),
                ];

                $delegationResult = $this->delegateSubTasks(
                    state: $state,
                    subAgentKeys: $subKeys,
                    context: $context,
                    tools: $tools,
                    llm: $llm,
                    activeSkillNames: $activeSkills
                );

                $updatedState = $delegationResult->state;
                $dddOutput = (array) ($delegationResult->getResult('ddd_boundary_agent')['output'] ?? []);
                $schemaOutput = (array) ($delegationResult->getResult('schema_planner_agent')['output'] ?? []);
                $apiOutput = (array) ($delegationResult->getResult('api_contract_agent')['output'] ?? []);

                $migrations = (array) ($schemaOutput['migrations'] ?? []);
                $models = array_merge(
                    (array) ($schemaOutput['models'] ?? []),
                    (array) ($dddOutput['models'] ?? [])
                );
                $controllers = (array) ($apiOutput['controllers'] ?? []);
                $requests = (array) ($apiOutput['requests'] ?? []);
                $resources = (array) ($apiOutput['resources'] ?? []);
                $policies = (array) ($apiOutput['policies'] ?? []);
                $routes = (array) ($apiOutput['routes'] ?? ['routes/api.php']);

                $tests = [];
                foreach (array_keys($controllers) as $controller) {
                    $tests["Feature: {$controller}"] = "tests/Feature/{$controller}Test.php";
                }
                foreach (array_keys($models) as $model) {
                    $tests["Unit: {$model}"] = "tests/Unit/{$model}Test.php";
                }

                $executionSteps = [];
                $migrationOrder = (array) ($schemaOutput['migration_order'] ?? array_values($migrations));
                if (! empty($migrationOrder)) {
                    $executionSteps[] = 'Run database migrations in chronological order: ' . implode(', ', $migrationOrder);
                }
                if (! empty($models)) {
                    $executionSteps[] = 'Implement Eloquent models with relationships, casts, and fillable: ' . implode(', ', array_keys($models));
                }
                if (! empty($requests)) {
                    $executionSteps[] = 'Implement FormRequest validation rules: ' . implode(', ', array_keys($requests));
                }
                if (! empty($resources)) {
                    $executionSteps[] = 'Implement JsonResource response transformers: ' . implode(', ', array_keys($resources));
                }
                if (! empty($dddOutput['domain_services'])) {
                    $serviceNames = array_map(fn ($s) => is_array($s) ? ($s['class'] ?? '') : (string) $s, (array) $dddOutput['domain_services']);
                    $executionSteps[] = 'Implement domain actions and services: ' . implode(', ', array_filter($serviceNames));
                }
                if (! empty($controllers)) {
                    $executionSteps[] = 'Implement API controllers: ' . implode(', ', array_keys($controllers));
                }
                if (! empty($policies)) {
                    $executionSteps[] = 'Implement authorization policies: ' . implode(', ', array_keys($policies));
                }
                $executionSteps[] = 'Register endpoint routes in ' . implode(', ', $routes);
                $executionSteps[] = 'Execute automated feature and unit tests to verify compliance';

                $synthesizedPlan = [
                    'migrations' => $migrations,
                    'models' => $models,
                    'controllers' => $controllers,
                    'requests' => $requests,
                    'resources' => $resources,
                    'policies' => $policies,
                    'routes' => $routes,
                    'tests' => $tests,
                    'execution_steps' => $executionSteps,
                    'bounded_contexts' => $dddOutput['bounded_contexts'] ?? [],
                    'directory_structure' => $dddOutput['directory_structure'] ?? [],
                    'domain_services' => $dddOutput['domain_services'] ?? [],
                    'schema_details' => $schemaOutput['tables'] ?? [],
                    'indexing_strategy' => $schemaOutput['indexing_strategy'] ?? [],
                    'endpoints' => $apiOutput['endpoints'] ?? [],
                    'architectural_rules' => $dddOutput['architectural_rules'] ?? [],
                ];

                return $this->applyOutput($updatedState, $synthesizedPlan, $tools);
            } catch (\Throwable) {
                // Fallback gracefully
            }
        }

        return parent::execute($state, $tools, $llm);
    }

    protected function buildUserPrompt(RunState $state): string
    {
        $backlogJson = json_encode($state->backlog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $specJson = json_encode($state->requirementsSpec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $parts = [
            "REQUIREMENTS SPEC:\n{$specJson}",
            "BACKLOG:\n{$backlogJson}",
        ];

        $scraping = $state->getScrapingResults();
        if (! empty($scraping)) {
            $contractData = [
                'source_urls' => $scraping['source_urls'] ?? [],
                'target_endpoint' => $scraping['target_endpoint'] ?? null,
                'http_method' => $scraping['http_method'] ?? 'POST',
                'extraction_summary' => $scraping['extraction_summary'] ?? null,
                'unified_payload_schema' => ! empty($scraping['unified_payload']) ? array_keys((array) $scraping['unified_payload']) : [],
                'unified_payload' => $scraping['unified_payload'] ?? [],
            ];
            $scrapingJson = json_encode($contractData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $parts[] = "SCRAPED DATA & DESTINATION CONTRACT (from Web Scraping Agent):\n{$scrapingJson}";
        }

        $parts[] = "Design the Laravel technical implementation plan specifying file paths for migrations, models, controllers, requests, routes, and tests.";

        return implode("\n\n", $parts);
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

