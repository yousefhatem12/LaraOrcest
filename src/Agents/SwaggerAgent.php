<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Agents;

use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Support\Config;
use Yousef\LaraOrcest\Tools\PatchWriterTool;

class SwaggerAgent extends AbstractAgent
{
    public function getName(): string
    {
        return 'swagger_agent';
    }

    public function getRole(): string
    {
        return 'Laravel Swagger & OpenAPI Documentation Specialist';
    }

    public function getGoal(): string
    {
        return 'Generate comprehensive, accurate OpenAPI 3.0+ / Swagger specifications, schemas, endpoints, request parameters, response models, and API documentation for backend routes and controllers.';
    }

    public function getBackstory(): string
    {
        return 'Dedicated API documentation specialist who meticulously inspects Laravel routes, controllers, form requests, and models to produce rich, interactive OpenAPI/Swagger definitions, ensuring every endpoint has correct HTTP verbs, route paths, path/query parameters, request bodies with validation schemas, response codes (200, 201, 400, 401, 403, 404, 422, 500), and security definitions.';
    }

    public function getAllowedTools(): array
    {
        return ['file_reader', 'patch_writer', 'artisan_runner'];
    }

    public function getStateOutputKey(): string
    {
        return 'artifacts';
    }

    protected function buildUserPrompt(RunState $state): string
    {
        $planJson = json_encode($state->technicalPlan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $backlogJson = json_encode($state->backlog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $artifactsJson = json_encode($state->artifacts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $title = (string) Config::get('lara-orcest.swagger.title', 'Laravel Application API Documentation');
        $version = (string) Config::get('lara-orcest.swagger.version', '1.0.0');
        $openapiVersion = (string) Config::get('lara-orcest.swagger.openapi_version', '3.0.3');
        $format = (string) ($state->metadata['swagger']['format'] ?? Config::get('lara-orcest.swagger.output_format', 'json'));
        $targetPath = (string) ($state->metadata['swagger']['path'] ?? Config::get('lara-orcest.swagger.output_path', 'docs/swagger.json'));

        $parts = [
            "TECHNICAL ARCHITECTURE PLAN:\n{$planJson}",
            "BACKLOG & ACCEPTANCE CRITERIA:\n{$backlogJson}",
            "IMPLEMENTED BACKEND ARTIFACTS:\n{$artifactsJson}",
            "SWAGGER / OPENAPI CONFIGURATION:\n" . json_encode([
                'title' => $title,
                'version' => $version,
                'openapi_version' => $openapiVersion,
                'output_format' => $format,
                'target_path' => $targetPath,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            "INSTRUCTIONS:\n" .
            "1. Analyze all routes, controllers, form requests, and models implemented for the backend.\n" .
            "2. Generate a complete OpenAPI 3.0+ specification conforming to the standard (openapi, info, paths, components/schemas, components/securitySchemes).\n" .
            "3. Document every endpoint path, HTTP method, summary, description, request parameters, request body schemas, and response status codes (200, 201, 400, 401, 403, 404, 422, 500).\n" .
            "4. Provide the complete file content in the 'files' array targeting '{$targetPath}'. If format is YAML or 'both', also include the YAML specification.\n" .
            "5. Return the structured output matching the required schema.",
        ];

        return implode("\n\n", $parts);
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
                        'openapi' => [
                            'type' => 'string',
                            'description' => 'OpenAPI version, e.g. 3.0.3',
                        ],
                        'info' => [
                            'type' => 'object',
                            'properties' => [
                                'title' => ['type' => 'string'],
                                'version' => ['type' => 'string'],
                                'description' => ['type' => 'string'],
                            ],
                            'required' => ['title', 'version'],
                        ],
                        'paths' => [
                            'type' => 'object',
                            'description' => 'All documented API paths and operations.',
                        ],
                        'components' => [
                            'type' => 'object',
                            'description' => 'Reusable schemas, security schemes, parameters.',
                        ],
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
                                'description' => 'Target path (must start with docs/, storage/api-docs/, public/, config/, routes/, or app/).',
                            ],
                            'content' => [
                                'type' => 'string',
                                'description' => 'Complete file content (JSON, YAML, or PHP).',
                            ],
                            'summary' => [
                                'type' => 'string',
                                'description' => 'Summary of changes or file purpose.',
                            ],
                        ],
                        'required' => ['path', 'content', 'summary'],
                    ],
                ],
                'endpoints_documented' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'List of documented endpoint signatures (e.g. ["GET /api/tasks", "POST /api/tasks"]).',
                ],
                'summary' => [
                    'type' => 'string',
                    'description' => 'Summary of the generated API documentation.',
                ],
            ],
            'required' => ['openapi_spec', 'files', 'summary'],
        ];
    }

    protected function applyOutput(RunState $state, array $output, array $tools): RunState
    {
        /** @var PatchWriterTool $patchTool */
        $patchTool = $this->getTool('patch_writer', $tools);
        $patchTool->setAllowedPrefixes([
            'docs/',
            'storage/api-docs/',
            'public/',
            'app/',
            'routes/',
            'config/',
        ]);
        $patchTool->setDryRun($state->isDryRun());

        $files = (array) ($output['files'] ?? []);

        // Fallback: If openapi_spec is provided but files array is empty, generate standard docs/swagger.json
        if (empty($files) && ! empty($output['openapi_spec'])) {
            $defaultPath = (string) ($state->metadata['swagger']['path'] ?? Config::get('lara-orcest.swagger.output_path', 'docs/swagger.json'));
            $files[] = [
                'path' => $defaultPath,
                'content' => json_encode($output['openapi_spec'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                'summary' => 'OpenAPI / Swagger 3.0 specification generated by SwaggerAgent',
            ];
        }

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

            $state = $state->recordArtifact($res['path'], $res['action'], $summary);
        }

        return $state->withSwaggerDocs($output);
    }
}
