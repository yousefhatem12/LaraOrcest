<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Agents\SubAgents;

use Yousef\LaraOrcest\Agents\Teams\AbstractSubAgent;
use Yousef\LaraOrcest\Contracts\ToolContract;
use Yousef\LaraOrcest\State\RunState;

class ApiContractAgent extends AbstractSubAgent
{
    public function key(): string
    {
        return 'api_contract_agent';
    }

    public function parentAgentKey(): string
    {
        return 'architect';
    }

    public function role(): string
    {
        return 'REST API Contract & Interface Designer';
    }

    public function goal(): string
    {
        return 'Define consistent RESTful endpoints, HTTP methods, validation requests, API Resource response transformations, authorization policies, and OpenAPI-compliant contracts.';
    }

    public function backstory(): string
    {
        return 'API design specialist ensuring strict RESTful conventions, consistent JSON envelope responses, typed FormRequests, policy authorization checks, and OpenAPI 3.0 schema alignment.';
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

    protected function buildUserPrompt(RunState $state, array $context = []): string
    {
        $specJson = json_encode($context['requirements_spec'] ?? $state->requirementsSpec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $backlogJson = json_encode($context['backlog'] ?? $state->backlog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return "REQUIREMENTS SPECIFICATION:\n{$specJson}\n\n" .
            "PRODUCT BACKLOG:\n{$backlogJson}\n\n" .
            "Design the complete RESTful API contract for Laravel:\n" .
            "1. Endpoints: URIs, HTTP verbs, and status codes (200, 201, 204, 403, 422).\n" .
            "2. Controllers: File paths in app/Http/Controllers/.\n" .
            "3. FormRequests: Validation request classes in app/Http/Requests/.\n" .
            "4. API Resources: Response transformer classes in app/Http/Resources/.\n" .
            "5. Policies: Authorization policy classes in app/Policies/.\n" .
            "6. Route files to update in routes/.";
    }

    protected function getOutputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'endpoints' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'method' => ['type' => 'string'],
                            'uri' => ['type' => 'string'],
                            'controller_action' => ['type' => 'string'],
                            'request_class' => ['type' => 'string'],
                            'resource_class' => ['type' => 'string'],
                            'auth_middleware' => ['type' => 'string'],
                            'status_codes' => [
                                'type' => 'array',
                                'items' => ['type' => 'integer'],
                            ],
                        ],
                        'required' => ['method', 'uri', 'controller_action'],
                    ],
                    'description' => 'List of API endpoint definitions.',
                ],
                'controllers' => [
                    'type' => 'object',
                    'description' => 'Mapping of controller class name to file path in app/Http/Controllers/.',
                ],
                'requests' => [
                    'type' => 'object',
                    'description' => 'Mapping of FormRequest class name to file path in app/Http/Requests/.',
                ],
                'resources' => [
                    'type' => 'object',
                    'description' => 'Mapping of JsonResource class name to file path in app/Http/Resources/.',
                ],
                'policies' => [
                    'type' => 'object',
                    'description' => 'Mapping of model/policy class name to file path in app/Policies/.',
                ],
                'routes' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Route files to update (e.g. routes/api.php).',
                ],
                'error_contracts' => [
                    'type' => 'object',
                    'description' => 'Standard error response structures.',
                ],
            ],
            'required' => ['endpoints', 'controllers', 'requests', 'routes'],
        ];
    }

    protected function applyOutput(RunState $state, array $output, array $tools): array
    {
        $endpointsCount = count((array) ($output['endpoints'] ?? []));

        return [
            'summary' => "Designed API contract with {$endpointsCount} REST endpoints",
            'output' => $output,
            'artifacts' => [],
        ];
    }
}
