<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Agents;

use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Tools\PatchWriterTool;

class BackendAgent extends AbstractAgent
{
    public function getName(): string
    {
        return 'backend_agent';
    }

    public function getRole(): string
    {
        return 'Laravel Backend & API Engineer';
    }

    public function getGoal(): string
    {
        return 'Implement controllers, form requests, action/service classes, policies/middleware, and routes that satisfy the backlog and acceptance criteria.';
    }

    public function getBackstory(): string
    {
        return 'Pragmatic Laravel backend developer who validates inputs via Form Requests, keeps controllers thin, handles authorization cleanly, and follows the existing codebase style.';
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

        $parts = [
            "TECHNICAL PLAN:\n{$planJson}",
            "BACKLOG:\n{$backlogJson}",
        ];

        $scraping = $state->getScrapingResults();
        if (! empty($scraping)) {
            $contractJson = json_encode([
                'target_endpoint' => $scraping['target_endpoint'] ?? null,
                'http_method' => $scraping['http_method'] ?? 'POST',
                'headers' => $scraping['headers_used'] ?? [],
                'unified_payload' => $scraping['unified_payload'] ?? [],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $parts[] = "DESTINATION ENDPOINT CONTRACT & DATA PAYLOAD (from Web Scraping Agent):\n{$contractJson}";
        }

        $parts[] = "Please generate the exact code for the necessary controllers, form requests, actions, and route registrations.";

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
                                'description' => 'Target path (must start with app/, routes/, or config/).',
                            ],
                            'content' => [
                                'type' => 'string',
                                'description' => 'Complete PHP file code.',
                            ],
                            'summary' => [
                                'type' => 'string',
                                'description' => 'Summary of changes.',
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
        // Restrict to app/, routes/, config/
        $patchTool->setAllowedPrefixes(['app/', 'routes/', 'config/']);
        $patchTool->setDryRun($state->isDryRun());

        $files = (array) ($output['files'] ?? []);

        foreach ($files as $file) {
            $path = (string) ($file['path'] ?? '');
            $content = (string) ($file['content'] ?? '');
            $summary = (string) ($file['summary'] ?? 'Backend feature implementation');

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
