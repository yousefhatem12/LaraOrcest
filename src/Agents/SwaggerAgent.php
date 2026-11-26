<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Agents;

use Yousef\LaraOrcest\Contracts\LlmDriverContract;
use Yousef\LaraOrcest\Contracts\ToolContract;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Support\Config;
use Yousef\LaraOrcest\Tools\PatchWriterTool;

class SwaggerAgent extends AbstractAgent
{
    /**
     * @var array<string, ToolContract>
     */
    protected array $activeTools = [];

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

    protected function getDefaultSkills(): array
    {
        return [
            'openapi-swagger-spec',
            'rest-api-contract-design',
        ];
    }

    /**
     * Executes the agent's turn, capturing authorized tools for prompt inspection.
     *
     * @param  array<string, ToolContract>  $tools
     */
    public function execute(RunState $state, array $tools, LlmDriverContract $llm): RunState
    {
        $this->activeTools = $this->authorizeTools($tools);

        try {
            return parent::execute($state, $tools, $llm);
        } finally {
            $this->activeTools = [];
        }
    }

    /**
     * Safely inspects relevant workspace files using FileReaderTool.
     *
     * @return array<int, array{path: string, content: string}>
     */
    protected function inspectWorkspaceFiles(RunState $state): array
    {
        $fileReader = $this->activeTools['file_reader'] ?? null;
        if (! $fileReader instanceof ToolContract) {
            return [];
        }

        $candidates = [];

        // 1. Standard API route files
        $candidates[] = 'routes/api.php';

        // 2. Route files from technical plan
        $routes = (array) ($state->technicalPlan['routes'] ?? []);
        foreach ($routes as $route) {
            if (is_string($route) && $route !== '') {
                $candidates[] = $route;
            }
        }

        // 3. Controllers from technical plan
        $controllers = (array) ($state->technicalPlan['controllers'] ?? []);
        foreach ($controllers as $controller) {
            if (is_string($controller) && $controller !== '') {
                $candidates[] = $controller;
            }
        }

        // 4. Form Requests from technical plan
        $requests = (array) ($state->technicalPlan['requests'] ?? []);
        foreach ($requests as $request) {
            if (is_string($request) && $request !== '') {
                $candidates[] = $request;
            }
        }

        // 5. API Resources & Models from technical plan
        $resources = (array) ($state->technicalPlan['resources'] ?? []);
        foreach ($resources as $resource) {
            if (is_string($resource) && $resource !== '') {
                $candidates[] = $resource;
            }
        }

        // 6. Implemented codebase files recorded in artifacts
        foreach ($state->artifacts as $artifact) {
            $path = (string) ($artifact['path'] ?? '');
            if (
                str_starts_with($path, 'routes/')
                || str_contains($path, 'Controllers/')
                || str_contains($path, 'Requests/')
                || str_contains($path, 'Resources/')
                || str_contains($path, 'Models/')
                || str_contains($path, 'swagger')
                || str_contains($path, 'openapi')
            ) {
                $candidates[] = $path;
            }
        }

        // 7. Check if existing swagger/openapi documentation exists at target path to allow incremental updates
        $targetPath = (string) ($state->metadata['swagger']['path'] ?? Config::get('lara-orcest.swagger.output_path', 'docs/swagger.json'));
        if ($targetPath !== '') {
            $candidates[] = $targetPath;
        }

        $candidates = array_unique($candidates);
        $inspected = [];

        foreach ($candidates as $path) {
            try {
                $res = $fileReader->execute([
                    'path' => $path,
                    'max_lines' => 300,
                ]);

                if (! empty($res['exists']) && isset($res['content']) && is_string($res['content'])) {
                    $inspected[] = [
                        'path' => (string) ($res['path'] ?? $path),
                        'content' => $res['content'],
                    ];
                }
            } catch (\Throwable) {
                // Ignore missing or inaccessible files gracefully
            }
        }

        return $inspected;
    }

    protected function buildUserPrompt(RunState $state): string
    {
        $planJson = json_encode($state->technicalPlan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $backlogJson = json_encode($state->backlog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $specJson = json_encode($state->requirementsSpec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $artifactsJson = json_encode($state->artifacts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $title = (string) Config::get('lara-orcest.swagger.title', 'Laravel Application API Documentation');
        $version = (string) Config::get('lara-orcest.swagger.version', '1.0.0');
        $openapiVersion = (string) Config::get('lara-orcest.swagger.openapi_version', '3.0.3');
        $format = (string) ($state->metadata['swagger']['format'] ?? Config::get('lara-orcest.swagger.output_format', 'json'));
        $targetPath = (string) ($state->metadata['swagger']['path'] ?? Config::get('lara-orcest.swagger.output_path', 'docs/swagger.json'));

        $inspectedFiles = $this->inspectWorkspaceFiles($state);
        $inspectedSection = '';
        if (! empty($inspectedFiles)) {
            $fileBlocks = [];
            foreach ($inspectedFiles as $f) {
                $fileBlocks[] = "--- File: {$f['path']} ---\n" . $f['content'];
            }
            $inspectedSection = "INSPECTED CODEBASE & API ARTIFACT FILES (via FileReaderTool):\n" . implode("\n\n", $fileBlocks);
        }

        $parts = [
            "REQUIREMENTS SPECIFICATION:\n{$specJson}",
            "BACKLOG & ACCEPTANCE CRITERIA:\n{$backlogJson}",
            "TECHNICAL ARCHITECTURE PLAN:\n{$planJson}",
            "IMPLEMENTED ARTIFACTS IN RUNSTATE:\n{$artifactsJson}",
        ];

        if ($inspectedSection !== '') {
            $parts[] = $inspectedSection;
        }

        $parts[] = "SWAGGER / OPENAPI CONFIGURATION:\n" . json_encode([
            'title' => $title,
            'version' => $version,
            'openapi_version' => $openapiVersion,
            'output_format' => $format,
            'target_path' => $targetPath,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $parts[] = "INSTRUCTIONS:\n" .
            "1. Inspect all implemented routes, controllers, Form Requests, and API resources.\n" .
            "2. Generate or update a complete, standards-compliant OpenAPI 3.x specification (openapi, info, paths, components/schemas, components/securitySchemes).\n" .
            "3. Document every endpoint covering:\n" .
            "   - Paths, HTTP methods (GET, POST, PUT, PATCH, DELETE), summaries, descriptions, and tags.\n" .
            "   - Request body schemas and validation rules inferred from Form Requests and requirements.\n" .
            "   - Path/query/header parameters with data types and descriptions.\n" .
            "   - Success responses (200, 201) and common error responses (400, 401, 404, 422) with realistic JSON examples.\n" .
            "4. Output the complete file content in the 'files' array targeting '{$targetPath}'. If format is 'yaml' or 'both', also provide the YAML file.\n" .
            "5. Return the structured JSON matching the output schema.";

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
                            'description' => 'All documented API paths, HTTP methods, tags, parameters, request bodies, and responses.',
                        ],
                        'components' => [
                            'type' => 'object',
                            'description' => 'Reusable schemas, security schemes, parameters, responses.',
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
                                'description' => 'Target path (e.g. docs/swagger.json, storage/api-docs/api-docs.json, or openapi.json).',
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

        $defaultPath = (string) ($state->metadata['swagger']['path'] ?? Config::get('lara-orcest.swagger.output_path', 'docs/swagger.json'));
        $targetPrefix = dirname($defaultPath);

        $allowedPrefixes = [
            'docs/',
            'storage/api-docs/',
            'public/',
            'app/',
            'routes/',
            'config/',
            'openapi.json',
            'openapi.yaml',
            'swagger.json',
            'swagger.yaml',
        ];

        if ($defaultPath !== '') {
            $allowedPrefixes[] = $defaultPath;
        }
        if ($targetPrefix !== '' && $targetPrefix !== '.') {
            $allowedPrefixes[] = rtrim($targetPrefix, '/') . '/';
        }

        $patchTool->setAllowedPrefixes(array_values(array_unique($allowedPrefixes)));
        $patchTool->setDryRun($state->isDryRun());

        $files = (array) ($output['files'] ?? []);

        // Fallback: If openapi_spec is provided but files array is empty, generate default target path
        if (empty($files) && ! empty($output['openapi_spec'])) {
            $format = (string) ($state->metadata['swagger']['format'] ?? Config::get('lara-orcest.swagger.output_format', 'json'));
            $isYaml = str_ends_with(strtolower($defaultPath), '.yaml') || str_ends_with(strtolower($defaultPath), '.yml') || $format === 'yaml';

            $files[] = [
                'path' => $defaultPath,
                'content' => $isYaml
                    ? $this->specToYamlString($output['openapi_spec'])
                    : json_encode($output['openapi_spec'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                'summary' => 'OpenAPI / Swagger 3.0 specification generated by SwaggerAgent',
            ];
        }

        $recordedPaths = [];
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

            $recordedPaths[] = $res['path'];
            $state = $state->recordArtifact($res['path'], $res['action'], $summary);
        }

        // Store concise summary of documented endpoints and output artifact paths in RunState
        $output['output_paths'] = array_values(array_unique($recordedPaths));

        if (empty($output['endpoints_documented']) && ! empty($output['openapi_spec']['paths'])) {
            $endpoints = [];
            foreach ($output['openapi_spec']['paths'] as $endpointPath => $operations) {
                if (is_array($operations)) {
                    foreach (array_keys($operations) as $method) {
                        if (in_array(strtolower((string) $method), ['get', 'post', 'put', 'patch', 'delete', 'options', 'head'], true)) {
                            $endpoints[] = strtoupper((string) $method) . ' ' . $endpointPath;
                        }
                    }
                }
            }
            $output['endpoints_documented'] = $endpoints;
        }

        return $state->withSwaggerDocs($output);
    }

    /**
     * Converts a structured OpenAPI spec array to a clean YAML string fallback.
     *
     * @param  array<string, mixed>  $data
     */
    protected function specToYamlString(array $data, int $indent = 0): string
    {
        $lines = [];
        $indentStr = str_repeat('  ', $indent);

        foreach ($data as $key => $value) {
            $prefix = $indentStr . $key . ':';
            if (is_array($value)) {
                if (empty($value)) {
                    $lines[] = $prefix . ' {}';
                } elseif (array_is_list($value)) {
                    $lines[] = $prefix;
                    foreach ($value as $item) {
                        if (is_array($item)) {
                            $nested = $this->specToYamlString($item, $indent + 2);
                            $firstLine = true;
                            foreach (explode("\n", $nested) as $line) {
                                if ($firstLine) {
                                    $lines[] = $indentStr . '  - ' . ltrim($line);
                                    $firstLine = false;
                                } elseif (trim($line) !== '') {
                                    $lines[] = $indentStr . '    ' . ltrim($line);
                                }
                            }
                        } else {
                            $lines[] = $indentStr . '  - ' . (is_bool($item) ? ($item ? 'true' : 'false') : (is_numeric($item) ? $item : "\"{$item}\""));
                        }
                    }
                } else {
                    $lines[] = $prefix;
                    $lines[] = $this->specToYamlString($value, $indent + 1);
                }
            } elseif (is_bool($value)) {
                $lines[] = $prefix . ' ' . ($value ? 'true' : 'false');
            } elseif (is_null($value)) {
                $lines[] = $prefix . ' null';
            } elseif (is_numeric($value)) {
                $lines[] = $prefix . ' ' . $value;
            } else {
                $escaped = str_replace(['"', "\n"], ['\"', '\n'], (string) $value);
                $lines[] = $prefix . ' "' . $escaped . '"';
            }
        }

        return implode("\n", array_filter($lines, fn ($l) => trim($l) !== ''));
    }
}
