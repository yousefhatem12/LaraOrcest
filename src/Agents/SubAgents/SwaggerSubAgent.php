<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Agents\SubAgents;

use Yousef\LaraOrcest\Agents\Teams\AbstractSubAgent;
use Yousef\LaraOrcest\Contracts\ToolContract;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Support\Config;

class SwaggerSubAgent extends AbstractSubAgent
{
    public function key(): string
    {
        return 'swagger_agent';
    }

    public function parentAgentKey(): string
    {
        return 'reviewer_agent';
    }

    public function role(): string
    {
        return 'Laravel OpenAPI & Swagger Documentation Specialist';
    }

    public function goal(): string
    {
        return 'Generate comprehensive, accurate OpenAPI 3.0+ / Swagger specifications, schemas, endpoints, request parameters, response models, and API documentation for backend routes and controllers.';
    }

    public function backstory(): string
    {
        return 'Dedicated API documentation specialist who meticulously inspects Laravel routes, controllers, form requests, and models to produce rich, interactive OpenAPI/Swagger definitions, ensuring every endpoint has correct HTTP verbs, route paths, path/query parameters, request bodies with validation schemas, response codes (200, 201, 400, 401, 403, 404, 422, 500), and security definitions.';
    }

    public function scopePaths(): array
    {
        return ['docs/**', 'storage/api-docs/**', 'openapi.json', 'openapi.yaml'];
    }

    public function allowedTools(): array
    {
        return ['file_reader', 'patch_writer', 'artisan_runner'];
    }

    public function dependsOn(): array
    {
        return [];
    }

    public function skills(): array
    {
        return ['openapi-swagger-spec'];
    }

    protected function buildUserPrompt(RunState $state, array $context = []): string
    {
        $planJson = json_encode($context['technical_plan'] ?? $state->technicalPlan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $backlogJson = json_encode($context['backlog'] ?? $state->backlog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $specJson = json_encode($context['requirements_spec'] ?? $state->requirementsSpec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $artifactsJson = json_encode($context['artifacts'] ?? $state->artifacts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $title = (string) Config::get('lara-orcest.swagger.title', 'Laravel Application API Documentation');
        $version = (string) Config::get('lara-orcest.swagger.version', '1.0.0');
        $openapiVersion = (string) Config::get('lara-orcest.swagger.openapi_version', '3.0.3');
        $format = (string) ($state->metadata['swagger']['format'] ?? Config::get('lara-orcest.swagger.output_format', 'json'));
        $targetPath = (string) ($state->metadata['swagger']['path'] ?? Config::get('lara-orcest.swagger.output_path', 'docs/swagger.json'));

        return "REQUIREMENTS SPECIFICATION:\n{$specJson}\n\n" .
            "BACKLOG & ACCEPTANCE CRITERIA:\n{$backlogJson}\n\n" .
            "TECHNICAL ARCHITECTURE PLAN:\n{$planJson}\n\n" .
            "IMPLEMENTED ARTIFACTS IN RUNSTATE:\n{$artifactsJson}\n\n" .
            "SWAGGER / OPENAPI CONFIGURATION:\n" . json_encode([
                'title' => $title,
                'version' => $version,
                'openapi_version' => $openapiVersion,
                'output_format' => $format,
                'target_path' => $targetPath,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n" .
            "INSTRUCTIONS:\n" .
            "1. Inspect all implemented routes, controllers, Form Requests, and API resources.\n" .
            "2. Generate or update a complete, standards-compliant OpenAPI 3.x specification.\n" .
            "3. Document every endpoint covering paths, methods, request bodies, query params, and responses.\n" .
            "4. Output the complete file content in the 'files' array targeting '{$targetPath}'.\n" .
            "5. Return the structured JSON matching the output schema.";
    }

    protected function getOutputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'openapi_spec' => [
                    'type' => 'object',
                    'description' => 'Complete OpenAPI 3.0+ specification document.',
                    'properties' => [
                        'openapi' => ['type' => 'string'],
                        'info' => [
                            'type' => 'object',
                            'properties' => [
                                'title' => ['type' => 'string'],
                                'version' => ['type' => 'string'],
                            ],
                            'required' => ['title', 'version'],
                        ],
                        'paths' => ['type' => 'object'],
                        'components' => ['type' => 'object'],
                    ],
                    'required' => ['openapi', 'info', 'paths'],
                ],
                'files' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'path' => [
                                'type' => 'string',
                                'description' => 'Target path (e.g. docs/swagger.json, storage/api-docs/api-docs.json, or openapi.json).',
                            ],
                            'content' => [
                                'type' => 'string',
                                'description' => 'Complete file content.',
                            ],
                            'summary' => [
                                'type' => 'string',
                                'description' => 'Summary of file purpose.',
                            ],
                        ],
                        'required' => ['path', 'content', 'summary'],
                    ],
                ],
                'endpoints_documented' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'summary' => [
                    'type' => 'string',
                    'description' => 'Summary of the generated API documentation.',
                ],
            ],
            'required' => ['openapi_spec', 'files', 'summary'],
        ];
    }

    protected function applyOutput(RunState $state, array $output, array $tools): array
    {
        $patchTool = $this->getTool('patch_writer', $tools);
        $files = (array) ($output['files'] ?? []);
        $artifacts = [];

        foreach ($files as $file) {
            $path = (string) ($file['path'] ?? '');
            $content = (string) ($file['content'] ?? '');
            $summary = (string) ($file['summary'] ?? 'Swagger / OpenAPI documentation');

            if ($path === '' || $content === '') {
                continue;
            }

            $res = $patchTool->execute([
                'path' => $path,
                'content' => $content,
                'summary' => $summary,
            ]);

            $artifacts[] = [
                'path' => $res['path'] ?? $path,
                'action' => $state->isDryRun() ? 'preview' : ($res['action'] ?? 'created'),
                'summary' => $summary,
            ];
        }

        $count = count($artifacts);
        $summary = (string) ($output['summary'] ?? "Generated OpenAPI documentation across {$count} file(s)");

        return [
            'summary' => $summary,
            'output' => $output,
            'artifacts' => $artifacts,
        ];
    }
}
