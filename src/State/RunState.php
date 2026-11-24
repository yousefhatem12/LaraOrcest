<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\State;

use InvalidArgumentException;
use Yousef\LaraOrcest\Graph\Exceptions\StateMergeConflictException;
use Yousef\LaraOrcest\Graph\ScopeConflictDetector;
use Yousef\LaraOrcest\Support\Config;

class RunState
{
    public const SCHEMA_VERSION = '1.0';

    public const STATUS_RUNNING = 'running';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    /**
     * @param  array<string, mixed>  $requirementsSpec
     * @param  array<int, mixed>  $backlog
     * @param  array<string, mixed>  $technicalPlan
     * @param  array<string, mixed>  $gitState
     * @param  array<int, array{path: string, action: string, summary: string, timestamp: string}>  $artifacts
     * @param  array<string, mixed>  $testResults
     * @param  array<int, array{decision: string, findings: array, constraints: array, timestamp: string}>  $reviews
     * @param  array{step_count: int, max_steps: int, fix_attempts: int, max_fix_attempts: int, dry_run: bool, token_usage: array{prompt_tokens: int, completion_tokens: int, total_tokens: int, cost?: float}}  $guardrails
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $runId,
        public readonly string $prompt,
        public readonly string $currentNode = 'start',
        public readonly string $status = self::STATUS_RUNNING,
        public readonly array $requirementsSpec = [],
        public readonly array $backlog = [],
        public readonly array $technicalPlan = [],
        public readonly array $gitState = [],
        public readonly array $artifacts = [],
        public readonly array $testResults = [],
        public readonly array $reviews = [],
        public readonly array $scrapingResults = [],
        public readonly array $delegations = [],
        public readonly array $guardrails = [
            'step_count' => 0,
            'max_steps' => 40,
            'fix_attempts' => 0,
            'max_fix_attempts' => 3,
            'dry_run' => false,
            'token_usage' => [
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'total_tokens' => 0,
                'cost' => 0.0,
            ],
        ],
        public readonly array $metadata = [],
        public readonly ?string $targetProjectPath = null,
        public readonly string $workspaceMode = 'embedded'
    ) {
        $this->validateStatus($status);
    }

    /**
     * Create a new RunState with initial configuration.
     *
     * @param  array<string, mixed>  $options
     */
    public static function create(string $prompt, array $options = []): self
    {
        $runId = (string) ($options['run_id'] ?? ('run_' . bin2hex(random_bytes(12))));
        $maxSteps = (int) ($options['max_steps'] ?? Config::get('lara-orcest.execution.max_steps', 40));
        $maxFixAttempts = (int) ($options['max_fix_attempts'] ?? Config::get('lara-orcest.execution.max_fix_attempts', 3));
        $dryRun = (bool) ($options['dry_run'] ?? Config::get('lara-orcest.execution.dry_run', false));

        $noReview = (bool) ($options['no_human_review'] ?? $options['skip_human_review'] ?? false);
        $skipReview = $noReview || (bool) ($options['skip_review'] ?? Config::get('lara-orcest.human_in_the_loop.skip_review', false));
        $interactive = (bool) ($options['interactive'] ?? false);
        $hitlEnabled = $interactive || (bool) ($options['human_in_the_loop']['enabled'] ?? $options['hitl'] ?? Config::get('lara-orcest.human_in_the_loop.enabled', false));
        $configGates = (array) Config::get('lara-orcest.human_in_the_loop.gates', ['after_plan' => true, 'before_commit' => true]);
        $gates = (array) ($options['human_in_the_loop']['gates'] ?? $options['gates'] ?? $configGates);

        $noMemory = (bool) ($options['no_memory'] ?? $options['skip_memory'] ?? false);
        $memoryConfig = (bool) Config::get('lara-orcest.memory.enabled', true);
        $memoryEnabled = ! $noMemory && (bool) ($options['memory_enabled'] ?? $memoryConfig);

        $noScrape = (bool) (
            $options['no_scrape']
            ?? $options['skip_scrape']
            ?? $options['no_web_scraping']
            ?? $options['skip_web_scraping']
            ?? $options['disable_web_scraping']
            ?? false
        );
        $scrapingConfigEnabled = (bool) Config::get('lara-orcest.web_scraping.enabled', true);
        $scrapingEnabled = ! $noScrape
            && (isset($options['web_scraping']['enabled']) ? (bool) $options['web_scraping']['enabled'] : true)
            && (isset($options['scraping_enabled']) ? (bool) $options['scraping_enabled'] : true)
            && $scrapingConfigEnabled;

        $scrapingResults = (array) ($options['scraping_results'] ?? []);
        $webScrapingMeta = (array) ($options['web_scraping'] ?? []);
        $webScrapingMeta['enabled'] = $scrapingEnabled;
        if ($noScrape) {
            $webScrapingMeta['disabled'] = true;
        }

        if (isset($options['source_urls'])) {
            $webScrapingMeta['source_urls'] = (array) $options['source_urls'];
        }
        if (isset($options['urls'])) {
            $webScrapingMeta['source_urls'] = (array) $options['urls'];
        }
        if (isset($options['target_endpoint'])) {
            $webScrapingMeta['target_endpoint'] = (string) $options['target_endpoint'];
        }
        if (isset($options['endpoint_url'])) {
            $webScrapingMeta['target_endpoint'] = (string) $options['endpoint_url'];
        }
        if (isset($options['endpoint'])) {
            $webScrapingMeta['target_endpoint'] = (string) $options['endpoint'];
        }
        if (isset($options['stop_after_scraping'])) {
            $webScrapingMeta['stop_after'] = (bool) $options['stop_after_scraping'];
        }

        $noSwagger = (bool) (
            $options['no_swagger']
            ?? $options['skip_swagger']
            ?? $options['disable_swagger']
            ?? false
        );
        $swaggerConfigEnabled = (bool) Config::get('lara-orcest.swagger.enabled', true);
        $swaggerExplicit = (bool) (
            $options['swagger']
            ?? $options['with_swagger']
            ?? false
        );
        $swaggerEnabled = ! $noSwagger
            && (isset($options['swagger']['enabled']) ? (bool) $options['swagger']['enabled'] : true)
            && $swaggerConfigEnabled;

        $swaggerMeta = (array) ($options['swagger'] ?? []);
        $swaggerMeta['enabled'] = $swaggerEnabled;
        if ($swaggerExplicit) {
            $swaggerMeta['explicit'] = true;
        }
        if ($noSwagger) {
            $swaggerMeta['disabled'] = true;
        }
        if (isset($options['swagger_format'])) {
            $swaggerMeta['format'] = (string) $options['swagger_format'];
        }
        if (isset($options['swagger_path'])) {
            $swaggerMeta['path'] = (string) $options['swagger_path'];
        }

        $noPackageManager = (bool) (
            $options['no_package_manager']
            ?? $options['skip_package_manager']
            ?? $options['disable_package_manager']
            ?? false
        );
        $pmConfigEnabled = (bool) Config::get('lara-orcest.package_manager.enabled', true);
        $pmEnabled = ! $noPackageManager
            && (isset($options['package_manager']['enabled']) ? (bool) $options['package_manager']['enabled'] : true)
            && $pmConfigEnabled;

        $pmMeta = (array) ($options['package_manager'] ?? []);
        $pmMeta['enabled'] = $pmEnabled;
        if ($noPackageManager) {
            $pmMeta['disabled'] = true;
        }

        $targetProjectPath = isset($options['target_project_path'])
            ? (string) $options['target_project_path']
            : (isset($options['target_path'])
                ? (string) $options['target_path']
                : (isset($options['target'])
                    ? (string) $options['target']
                    : null));

        $workspaceMode = (string) ($options['workspace_mode'] ?? ($targetProjectPath !== null ? 'standalone' : 'embedded'));

        return new self(
            runId: $runId,
            prompt: trim($prompt),
            currentNode: 'start',
            status: self::STATUS_RUNNING,
            requirementsSpec: (array) ($options['requirements_spec'] ?? $options['requirementsSpec'] ?? []),
            backlog: (array) ($options['backlog'] ?? []),
            technicalPlan: (array) ($options['technical_plan'] ?? $options['technicalPlan'] ?? []),
            gitState: (array) ($options['git_state'] ?? $options['gitState'] ?? []),
            artifacts: (array) ($options['artifacts'] ?? []),
            testResults: (array) ($options['test_results'] ?? $options['testResults'] ?? []),
            reviews: (array) ($options['reviews'] ?? []),
            scrapingResults: $scrapingResults,
            delegations: (array) ($options['delegations'] ?? []),
            guardrails: [
                'step_count' => 0,
                'max_steps' => $maxSteps,
                'fix_attempts' => 0,
                'max_fix_attempts' => $maxFixAttempts,
                'dry_run' => $dryRun,
                'token_usage' => [
                    'prompt_tokens' => 0,
                    'completion_tokens' => 0,
                    'total_tokens' => 0,
                    'cost' => 0.0,
                ],
            ],
            metadata: [
                'created_at' => date('c'),
                'schema_version' => self::SCHEMA_VERSION,
                'memory_enabled' => $memoryEnabled,
                'web_scraping' => $webScrapingMeta,
                'swagger' => $swaggerMeta,
                'package_manager' => $pmMeta,
                'human_in_the_loop' => [
                    'enabled' => $hitlEnabled,
                    'skip_review' => $skipReview,
                    'gates' => $gates,
                    'paused_gate' => null,
                    'pause_context' => null,
                    'decisions' => [],
                ],
            ],
            targetProjectPath: $targetProjectPath,
            workspaceMode: $workspaceMode
        );
    }

    /**
     * Named constructor for explicit run_id initialization.
     *
     * @param  array<string, mixed>  $options
     */
    public static function initial(string $runId, string $prompt, array $options = []): self
    {
        $options['run_id'] = $runId;

        return self::create($prompt, $options);
    }

    public function withCurrentNode(string $node): self
    {
        return new self(
            runId: $this->runId,
            prompt: $this->prompt,
            currentNode: $node,
            status: $this->status,
            requirementsSpec: $this->requirementsSpec,
            backlog: $this->backlog,
            technicalPlan: $this->technicalPlan,
            gitState: $this->gitState,
            artifacts: $this->artifacts,
            testResults: $this->testResults,
            reviews: $this->reviews,
            scrapingResults: $this->scrapingResults,
            delegations: $this->delegations,
            guardrails: $this->guardrails,
            metadata: $this->metadata,
            targetProjectPath: $this->targetProjectPath,
            workspaceMode: $this->workspaceMode
        );
    }

    public function withStatus(string $status): self
    {
        $this->validateStatus($status);

        return new self(
            runId: $this->runId,
            prompt: $this->prompt,
            currentNode: $this->currentNode,
            status: $status,
            requirementsSpec: $this->requirementsSpec,
            backlog: $this->backlog,
            technicalPlan: $this->technicalPlan,
            gitState: $this->gitState,
            artifacts: $this->artifacts,
            testResults: $this->testResults,
            reviews: $this->reviews,
            scrapingResults: $this->scrapingResults,
            delegations: $this->delegations,
            guardrails: $this->guardrails,
            metadata: array_merge($this->metadata, ['updated_at' => date('c')]),
            targetProjectPath: $this->targetProjectPath,
            workspaceMode: $this->workspaceMode
        );
    }

    public function withRequirementsSpec(array $spec): self
    {
        return new self(
            runId: $this->runId,
            prompt: $this->prompt,
            currentNode: $this->currentNode,
            status: $this->status,
            requirementsSpec: $spec,
            backlog: $this->backlog,
            technicalPlan: $this->technicalPlan,
            gitState: $this->gitState,
            artifacts: $this->artifacts,
            testResults: $this->testResults,
            reviews: $this->reviews,
            scrapingResults: $this->scrapingResults,
            delegations: $this->delegations,
            guardrails: $this->guardrails,
            metadata: $this->metadata,
            targetProjectPath: $this->targetProjectPath,
            workspaceMode: $this->workspaceMode
        );
    }

    public function withBacklog(array $backlog): self
    {
        return new self(
            runId: $this->runId,
            prompt: $this->prompt,
            currentNode: $this->currentNode,
            status: $this->status,
            requirementsSpec: $this->requirementsSpec,
            backlog: $backlog,
            technicalPlan: $this->technicalPlan,
            gitState: $this->gitState,
            artifacts: $this->artifacts,
            testResults: $this->testResults,
            reviews: $this->reviews,
            scrapingResults: $this->scrapingResults,
            delegations: $this->delegations,
            guardrails: $this->guardrails,
            metadata: $this->metadata,
            targetProjectPath: $this->targetProjectPath,
            workspaceMode: $this->workspaceMode
        );
    }

    public function withTechnicalPlan(array $plan): self
    {
        return new self(
            runId: $this->runId,
            prompt: $this->prompt,
            currentNode: $this->currentNode,
            status: $this->status,
            requirementsSpec: $this->requirementsSpec,
            backlog: $this->backlog,
            technicalPlan: $plan,
            gitState: $this->gitState,
            artifacts: $this->artifacts,
            testResults: $this->testResults,
            reviews: $this->reviews,
            scrapingResults: $this->scrapingResults,
            delegations: $this->delegations,
            guardrails: $this->guardrails,
            metadata: $this->metadata,
            targetProjectPath: $this->targetProjectPath,
            workspaceMode: $this->workspaceMode
        );
    }

    public function withGitState(array $gitState): self
    {
        return new self(
            runId: $this->runId,
            prompt: $this->prompt,
            currentNode: $this->currentNode,
            status: $this->status,
            requirementsSpec: $this->requirementsSpec,
            backlog: $this->backlog,
            technicalPlan: $this->technicalPlan,
            gitState: $gitState,
            artifacts: $this->artifacts,
            testResults: $this->testResults,
            reviews: $this->reviews,
            scrapingResults: $this->scrapingResults,
            delegations: $this->delegations,
            guardrails: $this->guardrails,
            metadata: $this->metadata,
            targetProjectPath: $this->targetProjectPath,
            workspaceMode: $this->workspaceMode
        );
    }

    public function withArtifacts(array $artifacts): self
    {
        return new self(
            runId: $this->runId,
            prompt: $this->prompt,
            currentNode: $this->currentNode,
            status: $this->status,
            requirementsSpec: $this->requirementsSpec,
            backlog: $this->backlog,
            technicalPlan: $this->technicalPlan,
            gitState: $this->gitState,
            artifacts: $artifacts,
            testResults: $this->testResults,
            reviews: $this->reviews,
            scrapingResults: $this->scrapingResults,
            delegations: $this->delegations,
            guardrails: $this->guardrails,
            metadata: $this->metadata,
            targetProjectPath: $this->targetProjectPath,
            workspaceMode: $this->workspaceMode
        );
    }

    public function withTestResults(array $testResults): self
    {
        return new self(
            runId: $this->runId,
            prompt: $this->prompt,
            currentNode: $this->currentNode,
            status: $this->status,
            requirementsSpec: $this->requirementsSpec,
            backlog: $this->backlog,
            technicalPlan: $this->technicalPlan,
            gitState: $this->gitState,
            artifacts: $this->artifacts,
            testResults: $testResults,
            reviews: $this->reviews,
            scrapingResults: $this->scrapingResults,
            delegations: $this->delegations,
            guardrails: $this->guardrails,
            metadata: $this->metadata,
            targetProjectPath: $this->targetProjectPath,
            workspaceMode: $this->workspaceMode
        );
    }

    public function withReviews(array $reviews): self
    {
        return new self(
            runId: $this->runId,
            prompt: $this->prompt,
            currentNode: $this->currentNode,
            status: $this->status,
            requirementsSpec: $this->requirementsSpec,
            backlog: $this->backlog,
            technicalPlan: $this->technicalPlan,
            gitState: $this->gitState,
            artifacts: $this->artifacts,
            testResults: $this->testResults,
            reviews: $reviews,
            scrapingResults: $this->scrapingResults,
            delegations: $this->delegations,
            guardrails: $this->guardrails,
            metadata: $this->metadata,
            targetProjectPath: $this->targetProjectPath,
            workspaceMode: $this->workspaceMode
        );
    }

    public function withGuardrails(array $guardrails): self
    {
        return new self(
            runId: $this->runId,
            prompt: $this->prompt,
            currentNode: $this->currentNode,
            status: $this->status,
            requirementsSpec: $this->requirementsSpec,
            backlog: $this->backlog,
            technicalPlan: $this->technicalPlan,
            gitState: $this->gitState,
            artifacts: $this->artifacts,
            testResults: $this->testResults,
            reviews: $this->reviews,
            scrapingResults: $this->scrapingResults,
            delegations: $this->delegations,
            guardrails: $guardrails,
            metadata: $this->metadata,
            targetProjectPath: $this->targetProjectPath,
            workspaceMode: $this->workspaceMode
        );
    }

    public function withMetadata(array $metadata): self
    {
        return new self(
            runId: $this->runId,
            prompt: $this->prompt,
            currentNode: $this->currentNode,
            status: $this->status,
            requirementsSpec: $this->requirementsSpec,
            backlog: $this->backlog,
            technicalPlan: $this->technicalPlan,
            gitState: $this->gitState,
            artifacts: $this->artifacts,
            testResults: $this->testResults,
            reviews: $this->reviews,
            scrapingResults: $this->scrapingResults,
            delegations: $this->delegations,
            guardrails: $this->guardrails,
            metadata: $metadata,
            targetProjectPath: $this->targetProjectPath,
            workspaceMode: $this->workspaceMode
        );
    }

    /**
     * Increments the graph execution step count by 1.
     */
    public function incrementStep(): self
    {
        return $this->incrementSteps();
    }

    /**
     * Increments the graph execution step count by 1.
     */
    public function incrementSteps(): self
    {
        $guardrails = $this->guardrails;
        $guardrails['step_count'] = ($guardrails['step_count'] ?? 0) + 1;

        return new self(
            runId: $this->runId,
            prompt: $this->prompt,
            currentNode: $this->currentNode,
            status: $this->status,
            requirementsSpec: $this->requirementsSpec,
            backlog: $this->backlog,
            technicalPlan: $this->technicalPlan,
            gitState: $this->gitState,
            artifacts: $this->artifacts,
            testResults: $this->testResults,
            reviews: $this->reviews,
            scrapingResults: $this->scrapingResults,
            delegations: $this->delegations,
            guardrails: $guardrails,
            metadata: $this->metadata,
            targetProjectPath: $this->targetProjectPath,
            workspaceMode: $this->workspaceMode
        );
    }

    /**
     * Increments the fix attempt counter by 1.
     */
    public function incrementFixAttempt(): self
    {
        return $this->incrementFixAttempts();
    }

    /**
     * Increments the fix attempt counter by 1.
     */
    public function incrementFixAttempts(): self
    {
        $guardrails = $this->guardrails;
        $guardrails['fix_attempts'] = ($guardrails['fix_attempts'] ?? 0) + 1;

        return new self(
            runId: $this->runId,
            prompt: $this->prompt,
            currentNode: $this->currentNode,
            status: $this->status,
            requirementsSpec: $this->requirementsSpec,
            backlog: $this->backlog,
            technicalPlan: $this->technicalPlan,
            gitState: $this->gitState,
            artifacts: $this->artifacts,
            testResults: $this->testResults,
            reviews: $this->reviews,
            scrapingResults: $this->scrapingResults,
            delegations: $this->delegations,
            guardrails: $guardrails,
            metadata: $this->metadata,
            targetProjectPath: $this->targetProjectPath,
            workspaceMode: $this->workspaceMode
        );
    }

    public function recordArtifact(string $path, string $action, string $summary, ?string $agent = null): self
    {
        // Normalize artifact path to be relative to targetProjectPath if absolute
        if ($this->targetProjectPath !== null && str_starts_with($path, $this->targetProjectPath)) {
            $path = ltrim(substr($path, strlen($this->targetProjectPath)), DIRECTORY_SEPARATOR . '/\\');
        }

        $artifacts = $this->artifacts;
        $entry = [
            'path' => $path,
            'action' => $action,
            'summary' => $summary,
            'timestamp' => date('c'),
        ];
        if ($agent !== null) {
            $entry['agent'] = $agent;
            $entry['sub_agent'] = $agent;
        }
        $artifacts[] = $entry;

        return new self(
            runId: $this->runId,
            prompt: $this->prompt,
            currentNode: $this->currentNode,
            status: $this->status,
            requirementsSpec: $this->requirementsSpec,
            backlog: $this->backlog,
            technicalPlan: $this->technicalPlan,
            gitState: $this->gitState,
            artifacts: $artifacts,
            testResults: $this->testResults,
            reviews: $this->reviews,
            scrapingResults: $this->scrapingResults,
            delegations: $this->delegations,
            guardrails: $this->guardrails,
            metadata: $this->metadata,
            targetProjectPath: $this->targetProjectPath,
            workspaceMode: $this->workspaceMode
        );
    }


    public function withDelegations(array $delegations): self
    {
        return new self(
            runId: $this->runId,
            prompt: $this->prompt,
            currentNode: $this->currentNode,
            status: $this->status,
            requirementsSpec: $this->requirementsSpec,
            backlog: $this->backlog,
            technicalPlan: $this->technicalPlan,
            gitState: $this->gitState,
            artifacts: $this->artifacts,
            testResults: $this->testResults,
            reviews: $this->reviews,
            scrapingResults: $this->scrapingResults,
            delegations: $delegations,
            guardrails: $this->guardrails,
            metadata: $this->metadata,
            targetProjectPath: $this->targetProjectPath,
            workspaceMode: $this->workspaceMode
        );
    }

    /**
     * @param  array<int, string>  $activeSkills
     * @param  array<string, mixed>  $output
     * @param  array<int, mixed>  $artifacts
     * @param  array<string, mixed>  $metadata
     */
    public function recordDelegation(
        string $subAgentKey,
        string $parentAgentKey,
        array $activeSkills,
        string $status,
        string $summary,
        array $output = [],
        array $artifacts = [],
        array $metadata = []
    ): self {
        $delegations = $this->delegations;
        $delegations[] = [
            'sub_agent' => $subAgentKey,
            'parent_agent' => $parentAgentKey,
            'active_skills' => $activeSkills,
            'status' => $status,
            'summary' => $summary,
            'output' => $output,
            'artifacts' => $artifacts,
            'metadata' => $metadata,
            'timestamp' => date('c'),
        ];

        return new self(
            runId: $this->runId,
            prompt: $this->prompt,
            currentNode: $this->currentNode,
            status: $this->status,
            requirementsSpec: $this->requirementsSpec,
            backlog: $this->backlog,
            technicalPlan: $this->technicalPlan,
            gitState: $this->gitState,
            artifacts: $this->artifacts,
            testResults: $this->testResults,
            reviews: $this->reviews,
            scrapingResults: $this->scrapingResults,
            delegations: $delegations,
            guardrails: $this->guardrails,
            metadata: $this->metadata,
            targetProjectPath: $this->targetProjectPath,
            workspaceMode: $this->workspaceMode
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getDelegations(): array
    {
        return $this->delegations;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getDelegationsForAgent(string $parentAgentKey): array
    {
        $matching = [];
        $needle = strtolower(trim($parentAgentKey));

        foreach ($this->delegations as $delegation) {
            if (isset($delegation['parent_agent']) && strtolower(trim((string) $delegation['parent_agent'])) === $needle) {
                $matching[] = $delegation;
            }
        }

        return $matching;
    }

    public function recordTestResult(bool $passed, int $exitCode, string $summary, string $output): self
    {
        $results = [
            'passed' => $passed,
            'exit_code' => $exitCode,
            'summary' => $summary,
            'output' => mb_substr($output, 0, 10000), // Bound size
            'timestamp' => date('c'),
        ];

        return new self(
            runId: $this->runId,
            prompt: $this->prompt,
            currentNode: $this->currentNode,
            status: $this->status,
            requirementsSpec: $this->requirementsSpec,
            backlog: $this->backlog,
            technicalPlan: $this->technicalPlan,
            gitState: $this->gitState,
            artifacts: $this->artifacts,
            testResults: $results,
            reviews: $this->reviews,
            scrapingResults: $this->scrapingResults,
            delegations: $this->delegations,
            guardrails: $this->guardrails,
            metadata: $this->metadata,
            targetProjectPath: $this->targetProjectPath,
            workspaceMode: $this->workspaceMode
        );
    }

    public function recordReview(string $decision, array $findings, array $constraints = []): self
    {
        $reviews = $this->reviews;
        $reviews[] = [
            'decision' => in_array($decision, ['approved', 'revise'], true) ? $decision : 'revise',
            'findings' => $findings,
            'constraints' => $constraints,
            'timestamp' => date('c'),
        ];

        return new self(
            runId: $this->runId,
            prompt: $this->prompt,
            currentNode: $this->currentNode,
            status: $this->status,
            requirementsSpec: $this->requirementsSpec,
            backlog: $this->backlog,
            technicalPlan: $this->technicalPlan,
            gitState: $this->gitState,
            artifacts: $this->artifacts,
            testResults: $this->testResults,
            reviews: $reviews,
            scrapingResults: $this->scrapingResults,
            delegations: $this->delegations,
            guardrails: $this->guardrails,
            metadata: $this->metadata,
            targetProjectPath: $this->targetProjectPath,
            workspaceMode: $this->workspaceMode
        );
    }

    public function recordTokenUsage(int $promptTokens, int $completionTokens, float $cost = 0.0): self
    {
        $guardrails = $this->guardrails;
        $usage = $guardrails['token_usage'] ?? [
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'total_tokens' => 0,
            'cost' => 0.0,
        ];

        $usage['prompt_tokens'] = ($usage['prompt_tokens'] ?? 0) + $promptTokens;
        $usage['completion_tokens'] = ($usage['completion_tokens'] ?? 0) + $completionTokens;
        $usage['total_tokens'] = ($usage['total_tokens'] ?? 0) + ($promptTokens + $completionTokens);
        $usage['cost'] = round(($usage['cost'] ?? 0.0) + $cost, 6);
        $guardrails['token_usage'] = $usage;

        return new self(
            runId: $this->runId,
            prompt: $this->prompt,
            currentNode: $this->currentNode,
            status: $this->status,
            requirementsSpec: $this->requirementsSpec,
            backlog: $this->backlog,
            technicalPlan: $this->technicalPlan,
            gitState: $this->gitState,
            artifacts: $this->artifacts,
            testResults: $this->testResults,
            reviews: $this->reviews,
            scrapingResults: $this->scrapingResults,
            delegations: $this->delegations,
            guardrails: $guardrails,
            metadata: $this->metadata,
            targetProjectPath: $this->targetProjectPath,
            workspaceMode: $this->workspaceMode
        );
    }

    public function getTotalTokens(): int
    {
        return (int) ($this->guardrails['token_usage']['total_tokens'] ?? 0);
    }

    public function getEstimatedCost(): float
    {
        return (float) ($this->guardrails['token_usage']['cost'] ?? 0.0);
    }

    public function pause(): self
    {
        return $this->withStatus(self::STATUS_PAUSED);
    }

    public function complete(): self
    {
        return $this->withStatus(self::STATUS_COMPLETED);
    }

    public function fail(?string $reason = null): self
    {
        $meta = $this->metadata;
        if ($reason !== null) {
            $meta['failure_reason'] = $reason;
        }

        return (new self(
            runId: $this->runId,
            prompt: $this->prompt,
            currentNode: $this->currentNode,
            status: self::STATUS_FAILED,
            requirementsSpec: $this->requirementsSpec,
            backlog: $this->backlog,
            technicalPlan: $this->technicalPlan,
            gitState: $this->gitState,
            artifacts: $this->artifacts,
            testResults: $this->testResults,
            reviews: $this->reviews,
            scrapingResults: $this->scrapingResults,
            delegations: $this->delegations,
            guardrails: $this->guardrails,
            metadata: array_merge($meta, ['updated_at' => date('c')]),
            targetProjectPath: $this->targetProjectPath,
            workspaceMode: $this->workspaceMode
        ));
    }

    public function getFailureReason(): ?string
    {
        return $this->metadata['failure_reason'] ?? null;
    }

    public function isRunning(): bool
    {
        return $this->status === self::STATUS_RUNNING;
    }

    public function isPaused(): bool
    {
        return $this->status === self::STATUS_PAUSED;
    }

    public function isDryRun(): bool
    {
        return (bool) ($this->guardrails['dry_run'] ?? false);
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function hasExceededSteps(): bool
    {
        $steps = $this->guardrails['step_count'] ?? 0;
        $maxSteps = $this->guardrails['max_steps'] ?? 40;

        return $steps >= $maxSteps;
    }

    public function hasExceededFixAttempts(): bool
    {
        $attempts = $this->guardrails['fix_attempts'] ?? 0;
        $maxAttempts = $this->guardrails['max_fix_attempts'] ?? 3;

        return $attempts >= $maxAttempts;
    }

    public function canAttemptFix(): bool
    {
        return ! $this->hasExceededFixAttempts() && ! $this->hasExceededSteps();
    }

    public function getLatestReview(): ?array
    {
        if (empty($this->reviews)) {
            return null;
        }

        return $this->reviews[array_key_last($this->reviews)];
    }

    public function isReviewApproved(): bool
    {
        $latest = $this->getLatestReview();

        return $latest !== null && ($latest['decision'] ?? '') === 'approved';
    }

    public function areTestsPassing(): bool
    {
        return ($this->testResults['passed'] ?? false) === true;
    }

    public function isHumanReviewEnabled(): bool
    {
        if ($this->isHumanReviewSkipped()) {
            return false;
        }

        return (bool) ($this->metadata['human_in_the_loop']['enabled'] ?? false);
    }

    public function isHumanReviewSkipped(): bool
    {
        return (bool) ($this->metadata['human_in_the_loop']['skip_review'] ?? false);
    }

    public function hasHumanGate(string $gate): bool
    {
        $gates = $this->metadata['human_in_the_loop']['gates'] ?? [];
        if (isset($gates[$gate])) {
            return (bool) $gates[$gate];
        }
        if (is_array($gates) && in_array($gate, $gates, true)) {
            return true;
        }

        return false;
    }

    /**
     * @return array<int, array{gate: string, decision: string, notes: ?string, timestamp: string}>
     */
    public function getHumanDecisions(): array
    {
        return (array) ($this->metadata['human_in_the_loop']['decisions'] ?? []);
    }

    public function isGateDecided(string $gate): bool
    {
        foreach ($this->getHumanDecisions() as $decision) {
            if (($decision['gate'] ?? '') === $gate) {
                return true;
            }
        }

        return false;
    }

    public function shouldPauseAtGate(string $gate): bool
    {
        if (! $this->isHumanReviewEnabled()) {
            return false;
        }

        if (! $this->hasHumanGate($gate)) {
            return false;
        }

        return ! $this->isGateDecided($gate);
    }

    /**
     * Pauses the run state at a human review gate with descriptive context.
     *
     * @param  array<string, mixed>  $context
     */
    public function pauseAtGate(string $gate, array $context = []): self
    {
        $meta = $this->metadata;
        $hitl = (array) ($meta['human_in_the_loop'] ?? []);
        $hitl['paused_gate'] = $gate;
        $hitl['pause_context'] = $context;
        $meta['human_in_the_loop'] = $hitl;

        return (new self(
            runId: $this->runId,
            prompt: $this->prompt,
            currentNode: $this->currentNode,
            status: self::STATUS_PAUSED,
            requirementsSpec: $this->requirementsSpec,
            backlog: $this->backlog,
            technicalPlan: $this->technicalPlan,
            gitState: $this->gitState,
            artifacts: $this->artifacts,
            testResults: $this->testResults,
            reviews: $this->reviews,
            scrapingResults: $this->scrapingResults,
            delegations: $this->delegations,
            guardrails: $this->guardrails,
            metadata: array_merge($meta, ['updated_at' => date('c')]),
            targetProjectPath: $this->targetProjectPath,
            workspaceMode: $this->workspaceMode
        ));
    }

    public function getPausedGate(): ?string
    {
        return $this->metadata['human_in_the_loop']['paused_gate'] ?? null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getPauseContext(): ?array
    {
        return $this->metadata['human_in_the_loop']['pause_context'] ?? null;
    }

    /**
     * Records a human approval or rejection decision and clears the paused state.
     */
    public function recordHumanDecision(string $gate, string $decision, ?string $notes = null): self
    {
        $normalizedDecision = in_array(strtolower($decision), ['approve', 'approved'], true) ? 'approved' : 'rejected';

        $meta = $this->metadata;
        $hitl = (array) ($meta['human_in_the_loop'] ?? []);
        $decisions = (array) ($hitl['decisions'] ?? []);
        $decisions[] = [
            'gate' => $gate,
            'decision' => $normalizedDecision,
            'notes' => $notes,
            'timestamp' => date('c'),
        ];
        $hitl['decisions'] = $decisions;
        $hitl['paused_gate'] = null;
        $hitl['pause_context'] = null;
        $hitl['resumed_gate'] = $gate;
        $meta['human_in_the_loop'] = $hitl;

        // Also record human feedback in the reviews array
        $reviews = $this->reviews;
        $reviews[] = [
            'decision' => $normalizedDecision === 'approved' ? 'approved' : 'revise',
            'findings' => $notes ? [$notes] : ["Human review determination: {$normalizedDecision} at gate [{$gate}]"],
            'constraints' => ['gate' => $gate, 'source' => 'human_in_the_loop'],
            'timestamp' => date('c'),
        ];

        return new self(
            runId: $this->runId,
            prompt: $this->prompt,
            currentNode: $this->currentNode,
            status: self::STATUS_RUNNING,
            requirementsSpec: $this->requirementsSpec,
            backlog: $this->backlog,
            technicalPlan: $this->technicalPlan,
            gitState: $this->gitState,
            artifacts: $this->artifacts,
            testResults: $this->testResults,
            reviews: $reviews,
            scrapingResults: $this->scrapingResults,
            delegations: $this->delegations,
            guardrails: $this->guardrails,
            metadata: array_merge($meta, ['updated_at' => date('c')]),
            targetProjectPath: $this->targetProjectPath,
            workspaceMode: $this->workspaceMode
        );
    }

    /**
     * Configures or updates human-in-the-loop review behavior for this run.
     *
     * @param  array<string, mixed>|null  $gates
     */
    public function withHumanReviewConfig(bool $enabled, bool $skipReview = false, ?array $gates = null): self
    {
        $meta = $this->metadata;
        $hitl = (array) ($meta['human_in_the_loop'] ?? []);
        $hitl['enabled'] = $enabled;
        $hitl['skip_review'] = $skipReview;
        if ($gates !== null) {
            $hitl['gates'] = $gates;
        }
        $meta['human_in_the_loop'] = $hitl;

        return new self(
            runId: $this->runId,
            prompt: $this->prompt,
            currentNode: $this->currentNode,
            status: $this->status,
            requirementsSpec: $this->requirementsSpec,
            backlog: $this->backlog,
            technicalPlan: $this->technicalPlan,
            gitState: $this->gitState,
            artifacts: $this->artifacts,
            testResults: $this->testResults,
            reviews: $this->reviews,
            scrapingResults: $this->scrapingResults,
            delegations: $this->delegations,
            guardrails: $this->guardrails,
            metadata: $meta,
            targetProjectPath: $this->targetProjectPath,
            workspaceMode: $this->workspaceMode
        );
    }

    /**
     * Stores structured scraping and endpoint dispatch results in state.
     *
     * @param  array<string, mixed>  $results
     */
    public function withScrapingResults(array $results): self
    {
        $sanitized = $this->sanitizeArray($results);
        $meta = $this->metadata;
        $meta['web_scraping'] = $sanitized;

        return new self(
            runId: $this->runId,
            prompt: $this->prompt,
            currentNode: $this->currentNode,
            status: $this->status,
            requirementsSpec: $this->requirementsSpec,
            backlog: $this->backlog,
            technicalPlan: $this->technicalPlan,
            gitState: $this->gitState,
            artifacts: $this->artifacts,
            testResults: $this->testResults,
            reviews: $this->reviews,
            scrapingResults: $sanitized,
            delegations: $this->delegations,
            guardrails: $this->guardrails,
            metadata: $meta,
            targetProjectPath: $this->targetProjectPath,
            workspaceMode: $this->workspaceMode
        );
    }

    /**
     * Returns the recorded web scraping results.
     *
     * @return array<string, mixed>
     */
    public function getScrapingResults(): array
    {
        if (! empty($this->scrapingResults)) {
            return $this->scrapingResults;
        }

        $meta = (array) ($this->metadata['web_scraping'] ?? []);
        if (isset($meta['extraction_summary']) || isset($meta['dispatch']) || isset($meta['unified_payload'])) {
            return $meta;
        }

        return [];
    }

    /**
     * Checks if web scraping is explicitly disabled in state, options, or configuration.
     */
    public function isWebScrapingDisabled(): bool
    {
        if (Config::get('lara-orcest.web_scraping.enabled') === false) {
            return true;
        }

        $meta = (array) ($this->metadata['web_scraping'] ?? []);
        if (isset($meta['enabled']) && $meta['enabled'] === false) {
            return true;
        }
        if (! empty($meta['disabled'])) {
            return true;
        }

        if (! empty($this->metadata['disable_web_scraping'])
            || ! empty($this->metadata['no_web_scraping'])
            || ! empty($this->metadata['no_scrape'])
            || ! empty($this->metadata['skip_scrape'])
            || ! empty($this->metadata['skip_web_scraping'])) {
            return true;
        }

        return false;
    }

    /**
     * Resolves target source website URLs (expecting two target URLs).
     * Searches in RunState metadata, requirements_spec, backlog, prompt, and configuration.
     *
     * @return array<int, string>
     */
    public function resolveScrapingUrls(): array
    {
        $meta = (array) ($this->metadata['web_scraping'] ?? []);

        // 1. RunState metadata / options
        $candidateUrls = null;
        if (! empty($meta['source_urls']) && is_array($meta['source_urls'])) {
            $candidateUrls = $meta['source_urls'];
        } elseif (! empty($meta['urls']) && is_array($meta['urls'])) {
            $candidateUrls = $meta['urls'];
        } elseif (! empty($this->metadata['source_urls']) && is_array($this->metadata['source_urls'])) {
            $candidateUrls = $this->metadata['source_urls'];
        } elseif (! empty($this->metadata['urls']) && is_array($this->metadata['urls'])) {
            $candidateUrls = $this->metadata['urls'];
        } elseif (! empty($this->scrapingResults['source_urls']) && is_array($this->scrapingResults['source_urls'])) {
            $candidateUrls = $this->scrapingResults['source_urls'];
        }

        // 2. requirements_spec
        if ($candidateUrls === null && ! empty($this->requirementsSpec)) {
            $specWs = (array) ($this->requirementsSpec['web_scraping'] ?? []);
            if (! empty($specWs['source_urls']) && is_array($specWs['source_urls'])) {
                $candidateUrls = $specWs['source_urls'];
            } elseif (! empty($specWs['urls']) && is_array($specWs['urls'])) {
                $candidateUrls = $specWs['urls'];
            } elseif (! empty($this->requirementsSpec['source_urls']) && is_array($this->requirementsSpec['source_urls'])) {
                $candidateUrls = $this->requirementsSpec['source_urls'];
            } elseif (! empty($this->requirementsSpec['urls']) && is_array($this->requirementsSpec['urls'])) {
                $candidateUrls = $this->requirementsSpec['urls'];
            } else {
                $extracted = self::extractHttpUrlsFromValue($this->requirementsSpec);
                if (count($extracted) >= 2) {
                    $candidateUrls = array_slice($extracted, 0, 2);
                }
            }
        }

        // 3. backlog
        if ($candidateUrls === null && ! empty($this->backlog)) {
            foreach ($this->backlog as $task) {
                $taskArr = is_array($task) ? $task : (method_exists($task, 'toArray') ? $task->toArray() : (array) $task);
                if (! empty($taskArr['web_scraping']['source_urls']) && is_array($taskArr['web_scraping']['source_urls'])) {
                    $candidateUrls = $taskArr['web_scraping']['source_urls'];
                    break;
                }
                if (! empty($taskArr['source_urls']) && is_array($taskArr['source_urls'])) {
                    $candidateUrls = $taskArr['source_urls'];
                    break;
                }
                if (! empty($taskArr['urls']) && is_array($taskArr['urls'])) {
                    $candidateUrls = $taskArr['urls'];
                    break;
                }
            }

            if ($candidateUrls === null) {
                $extracted = self::extractHttpUrlsFromValue($this->backlog);
                if (count($extracted) >= 2) {
                    $candidateUrls = array_slice($extracted, 0, 2);
                }
            }
        }

        // 4. prompt
        if ($candidateUrls === null && $this->prompt !== '') {
            $extracted = self::extractHttpUrlsFromValue($this->prompt);
            if (count($extracted) >= 2) {
                $candidateUrls = array_slice($extracted, 0, 2);
            } elseif (count($extracted) === 1) {
                $candidateUrls = $extracted;
            }
        }

        // 5. configuration
        if ($candidateUrls === null) {
            $cfgUrls = Config::get('lara-orcest.web_scraping.source_urls')
                ?? Config::get('lara-orcest.web_scraping.urls');
            if (! empty($cfgUrls) && is_array($cfgUrls)) {
                $candidateUrls = $cfgUrls;
            }
        }

        if ($candidateUrls === null) {
            return [];
        }

        $validated = [];
        foreach ($candidateUrls as $url) {
            if (is_string($url) && trim($url) !== '') {
                $trimmed = trim($url);
                if (filter_var($trimmed, FILTER_VALIDATE_URL) !== false) {
                    $validated[] = $trimmed;
                }
            }
        }

        if (count($validated) >= 2) {
            return array_slice($validated, 0, 2);
        }

        return $validated;
    }

    /**
     * Resolves the target destination endpoint URL.
     * Searches in RunState metadata, requirements_spec, backlog, prompt, and configuration.
     */
    public function resolveScrapingEndpoint(): ?string
    {
        $meta = (array) ($this->metadata['web_scraping'] ?? []);

        // 1. RunState metadata / options
        $endpoint = null;
        if (! empty($meta['target_endpoint'])) {
            $endpoint = (string) $meta['target_endpoint'];
        } elseif (! empty($meta['endpoint_url'])) {
            $endpoint = (string) $meta['endpoint_url'];
        } elseif (! empty($meta['endpoint'])) {
            $endpoint = (string) $meta['endpoint'];
        } elseif (! empty($this->metadata['target_endpoint'])) {
            $endpoint = (string) $this->metadata['target_endpoint'];
        } elseif (! empty($this->metadata['endpoint_url'])) {
            $endpoint = (string) $this->metadata['endpoint_url'];
        } elseif (! empty($this->metadata['endpoint'])) {
            $endpoint = (string) $this->metadata['endpoint'];
        } elseif (! empty($this->scrapingResults['target_endpoint'])) {
            $endpoint = (string) $this->scrapingResults['target_endpoint'];
        } elseif (! empty($this->scrapingResults['endpoint_url'])) {
            $endpoint = (string) $this->scrapingResults['endpoint_url'];
        }

        // 2. requirements_spec
        if ($endpoint === null && ! empty($this->requirementsSpec)) {
            $specWs = (array) ($this->requirementsSpec['web_scraping'] ?? []);
            if (! empty($specWs['target_endpoint'])) {
                $endpoint = (string) $specWs['target_endpoint'];
            } elseif (! empty($specWs['endpoint_url'])) {
                $endpoint = (string) $specWs['endpoint_url'];
            } elseif (! empty($specWs['endpoint'])) {
                $endpoint = (string) $specWs['endpoint'];
            } elseif (! empty($this->requirementsSpec['target_endpoint'])) {
                $endpoint = (string) $this->requirementsSpec['target_endpoint'];
            } elseif (! empty($this->requirementsSpec['endpoint_url'])) {
                $endpoint = (string) $this->requirementsSpec['endpoint_url'];
            } elseif (! empty($this->requirementsSpec['endpoint'])) {
                $endpoint = (string) $this->requirementsSpec['endpoint'];
            } else {
                $found = self::extractEndpointFromValue($this->requirementsSpec);
                if ($found !== null) {
                    $endpoint = $found;
                } else {
                    $extracted = self::extractHttpUrlsFromValue($this->requirementsSpec);
                    if (count($extracted) >= 3) {
                        $endpoint = $extracted[2];
                    }
                }
            }
        }

        // 3. backlog
        if ($endpoint === null && ! empty($this->backlog)) {
            foreach ($this->backlog as $task) {
                $taskArr = is_array($task) ? $task : (method_exists($task, 'toArray') ? $task->toArray() : (array) $task);
                if (! empty($taskArr['web_scraping']['target_endpoint'])) {
                    $endpoint = (string) $taskArr['web_scraping']['target_endpoint'];
                    break;
                }
                if (! empty($taskArr['target_endpoint'])) {
                    $endpoint = (string) $taskArr['target_endpoint'];
                    break;
                }
                if (! empty($taskArr['endpoint_url'])) {
                    $endpoint = (string) $taskArr['endpoint_url'];
                    break;
                }
                if (! empty($taskArr['endpoint'])) {
                    $endpoint = (string) $taskArr['endpoint'];
                    break;
                }
            }

            if ($endpoint === null) {
                $found = self::extractEndpointFromValue($this->backlog);
                if ($found !== null) {
                    $endpoint = $found;
                } else {
                    $extracted = self::extractHttpUrlsFromValue($this->backlog);
                    if (count($extracted) >= 3) {
                        $endpoint = $extracted[2];
                    }
                }
            }
        }

        // 4. prompt
        if ($endpoint === null && $this->prompt !== '') {
            $found = self::extractEndpointFromString($this->prompt);
            if ($found !== null) {
                $endpoint = $found;
            } else {
                $extracted = self::extractHttpUrlsFromValue($this->prompt);
                if (count($extracted) >= 3) {
                    $endpoint = $extracted[2];
                }
            }
        }

        // 5. configuration
        if ($endpoint === null) {
            $cfgEndpoint = Config::get('lara-orcest.web_scraping.target_endpoint')
                ?? Config::get('lara-orcest.web_scraping.endpoint_url')
                ?? Config::get('lara-orcest.web_scraping.default_endpoint')
                ?? Config::get('lara-orcest.web_scraping.endpoint');

            if (! empty($cfgEndpoint) && is_string($cfgEndpoint)) {
                $endpoint = (string) $cfgEndpoint;
            }
        }

        if ($endpoint !== null && trim($endpoint) !== '') {
            $trimmed = trim($endpoint);
            if (filter_var($trimmed, FILTER_VALIDATE_URL) !== false) {
                return $trimmed;
            }
        }

        return null;
    }

    /**
     * Checks if scraping target URLs are provided in metadata, spec, backlog, prompt, or config.
     * Expects exactly two target URLs.
     */
    public function hasScrapingUrls(): bool
    {
        return count($this->resolveScrapingUrls()) === 2;
    }

    /**
     * Checks if a destination endpoint is provided.
     */
    public function hasScrapingEndpoint(): bool
    {
        return $this->resolveScrapingEndpoint() !== null;
    }

    /**
     * Determines whether WebScrapingAgent should activate in the graph.
     * Requires:
     * - Web scraping is not explicitly disabled
     * - Two target source URLs are provided
     * - A target destination endpoint is provided
     */
    public function shouldRunWebScraper(): bool
    {
        return ! $this->isWebScrapingDisabled()
            && $this->hasScrapingUrls()
            && $this->hasScrapingEndpoint();
    }

    /**
     * Alias for shouldRunWebScraper().
     */
    public function shouldRunScraper(): bool
    {
        return $this->shouldRunWebScraper();
    }

    /**
     * Alias for shouldRunWebScraper().
     */
    public function shouldActivateScraper(): bool
    {
        return $this->shouldRunWebScraper();
    }

    /**
     * Configures or updates web scraping settings for this run.
     *
     * @param  array<int, string>|null  $sourceUrls
     */
    public function withWebScrapingConfig(
        bool $enabled,
        ?array $sourceUrls = null,
        ?string $targetEndpoint = null,
        ?bool $stopAfterScraping = null
    ): self {
        $meta = $this->metadata;
        $ws = (array) ($meta['web_scraping'] ?? []);
        $ws['enabled'] = $enabled;
        if (! $enabled) {
            $ws['disabled'] = true;
        } else {
            unset($ws['disabled']);
        }
        if ($sourceUrls !== null) {
            $ws['source_urls'] = $sourceUrls;
        }
        if ($targetEndpoint !== null) {
            $ws['target_endpoint'] = $targetEndpoint;
        }
        if ($stopAfterScraping !== null) {
            $ws['stop_after'] = $stopAfterScraping;
        }
        $meta['web_scraping'] = $ws;

        return new self(
            runId: $this->runId,
            prompt: $this->prompt,
            currentNode: $this->currentNode,
            status: $this->status,
            requirementsSpec: $this->requirementsSpec,
            backlog: $this->backlog,
            technicalPlan: $this->technicalPlan,
            gitState: $this->gitState,
            artifacts: $this->artifacts,
            testResults: $this->testResults,
            reviews: $this->reviews,
            scrapingResults: $this->scrapingResults,
            delegations: $this->delegations,
            guardrails: $this->guardrails,
            metadata: $meta,
            targetProjectPath: $this->targetProjectPath,
            workspaceMode: $this->workspaceMode
        );
    }

    /**
     * Records structured Swagger/OpenAPI documentation in state metadata.
     *
     * @param  array<string, mixed>  $docs
     */
    public function withSwaggerDocs(array $docs): self
    {
        $meta = $this->metadata;
        $meta['swagger_documentation'] = $docs;
        $meta['updated_at'] = date('c');

        return new self(
            runId: $this->runId,
            prompt: $this->prompt,
            currentNode: $this->currentNode,
            status: $this->status,
            requirementsSpec: $this->requirementsSpec,
            backlog: $this->backlog,
            technicalPlan: $this->technicalPlan,
            gitState: $this->gitState,
            artifacts: $this->artifacts,
            testResults: $this->testResults,
            reviews: $this->reviews,
            scrapingResults: $this->scrapingResults,
            delegations: $this->delegations,
            guardrails: $this->guardrails,
            metadata: $meta,
            targetProjectPath: $this->targetProjectPath,
            workspaceMode: $this->workspaceMode
        );
    }

    /**
     * Retrieves the stored Swagger/OpenAPI documentation object.
     *
     * @return array<string, mixed>|null
     */
    public function getSwaggerDocs(): ?array
    {
        return $this->metadata['swagger_documentation'] ?? null;
    }

    /**
     * Retrieves the raw OpenAPI specification array.
     *
     * @return array<string, mixed>|null
     */
    public function getSwaggerSpec(): ?array
    {
        return $this->metadata['swagger_documentation']['openapi_spec'] ?? null;
    }

    /**
     * Returns concise summary of the generated Swagger/OpenAPI documentation.
     */
    public function getSwaggerSummary(): ?string
    {
        return $this->metadata['swagger_documentation']['summary'] ?? null;
    }

    /**
     * Returns list of documented endpoint signatures (e.g. ['GET /api/tasks', 'POST /api/tasks']).
     *
     * @return array<int, string>
     */
    public function getSwaggerEndpoints(): array
    {
        $docs = (array) ($this->metadata['swagger_documentation'] ?? []);
        if (! empty($docs['endpoints_documented'])) {
            return (array) $docs['endpoints_documented'];
        }

        $endpoints = [];
        $specPaths = (array) ($docs['openapi_spec']['paths'] ?? []);
        foreach ($specPaths as $path => $ops) {
            if (is_array($ops)) {
                foreach (array_keys($ops) as $verb) {
                    if (in_array(strtolower((string) $verb), ['get', 'post', 'put', 'patch', 'delete', 'options', 'head'], true)) {
                        $endpoints[] = strtoupper((string) $verb) . ' ' . $path;
                    }
                }
            }
        }

        return $endpoints;
    }

    /**
     * Returns list of output file artifact paths for Swagger documentation.
     *
     * @return array<int, string>
     */
    public function getSwaggerOutputPaths(): array
    {
        $docs = (array) ($this->metadata['swagger_documentation'] ?? []);
        if (! empty($docs['output_paths'])) {
            return (array) $docs['output_paths'];
        }

        $files = (array) ($docs['files'] ?? []);
        $paths = [];
        foreach ($files as $f) {
            if (! empty($f['path'])) {
                $paths[] = (string) $f['path'];
            }
        }

        return $paths;
    }

    /**
     * Checks if Swagger documentation has been produced.
     */
    public function hasSwaggerDocs(): bool
    {
        return ! empty($this->metadata['swagger_documentation']);
    }

    /**
     * Checks if Swagger generation is disabled.
     */
    public function isSwaggerDisabled(): bool
    {
        if (Config::get('lara-orcest.swagger.enabled') === false) {
            return true;
        }

        $meta = (array) ($this->metadata['swagger'] ?? []);
        if (isset($meta['enabled']) && $meta['enabled'] === false) {
            return true;
        }

        if (! empty($meta['disabled'])) {
            return true;
        }

        return ! empty($this->metadata['disable_swagger'])
            || ! empty($this->metadata['no_swagger'])
            || ! empty($this->metadata['skip_swagger']);
    }

    /**
     * Checks if Swagger generation is enabled.
     */
    public function isSwaggerEnabled(): bool
    {
        return ! $this->isSwaggerDisabled();
    }

    /**
     * Determines whether API endpoints exist or were planned/implemented in the current run.
     */
    public function hasApiEndpoints(): bool
    {
        // 1. Check implemented artifacts
        foreach ($this->artifacts as $artifact) {
            $path = (string) ($artifact['path'] ?? '');
            if (
                str_starts_with($path, 'routes/api')
                || str_contains($path, 'Controllers/Api/')
                || str_contains($path, 'Requests/')
                || str_contains($path, 'Resources/')
            ) {
                return true;
            }
        }

        // 2. Check technical plan
        $routes = (array) ($this->technicalPlan['routes'] ?? []);
        foreach ($routes as $route) {
            $r = (string) $route;
            if (str_contains($r, 'routes/api') || str_contains($r, '/api/')) {
                return true;
            }
        }

        $controllers = (array) ($this->technicalPlan['controllers'] ?? []);
        foreach ($controllers as $controller) {
            $c = (string) $controller;
            if (str_contains($c, 'Controllers/Api/') || str_contains($c, 'Api\\') || str_ends_with($c, 'ApiController.php')) {
                return true;
            }
        }

        // 3. Check backlog tasks for API endpoint signatures
        foreach ($this->backlog as $task) {
            $title = (string) ($task['title'] ?? '');
            $desc = (string) ($task['description'] ?? '');
            if (preg_match('#\b(GET|POST|PUT|PATCH|DELETE)\s+/api/#i', $title . ' ' . $desc)) {
                return true;
            }

            $criteria = (array) ($task['acceptance_criteria'] ?? []);
            foreach ($criteria as $criterion) {
                if (preg_match('#\b(GET|POST|PUT|PATCH|DELETE)\s+/api/#i', (string) $criterion)) {
                    return true;
                }
            }
        }

        // 4. Check prompt or requirements for REST/API terms
        $text = strtolower($this->prompt);
        if (str_contains($text, 'api') || str_contains($text, 'endpoint') || str_contains($text, 'rest')) {
            return true;
        }

        return false;
    }

    /**
     * Determines whether SwaggerAgent should run in the orchestration graph.
     */
    public function shouldRunSwagger(): bool
    {
        if ($this->isSwaggerDisabled()) {
            return false;
        }

        $meta = (array) ($this->metadata['swagger'] ?? []);
        if (! empty($meta['explicit']) || ! empty($meta['run'])) {
            return true;
        }

        if (Config::get('lara-orcest.swagger.auto_generate', false)) {
            return true;
        }

        if ($this->detectSwaggerIntent()) {
            return true;
        }

        // Conditional activation: if enabled in configuration and API endpoints exist
        $conditional = (bool) Config::get('lara-orcest.swagger.conditional_activation', Config::get('lara-orcest.swagger.conditional', false));
        if ($conditional && $this->hasApiEndpoints()) {
            return true;
        }

        return false;
    }

    /**
     * Detects if the prompt or requirements indicate Swagger/OpenAPI generation intent.
     */
    public function detectSwaggerIntent(): bool
    {
        $text = strtolower($this->prompt);

        $keywords = [
            'swagger',
            'openapi',
            'api doc',
            'api-doc',
            'api docs',
            'api documentation',
            'document api',
            'documentation for backend',
            'document backend',
            'swagger doc',
            'openapi spec',
            'swagger spec',
        ];

        foreach ($keywords as $kw) {
            if (str_contains($text, $kw)) {
                return true;
            }
        }

        if (! empty($this->requirementsSpec['functional_requirements'])) {
            foreach ((array) $this->requirementsSpec['functional_requirements'] as $req) {
                $reqText = strtolower((string) $req);
                foreach ($keywords as $kw) {
                    if (str_contains($reqText, $kw)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Configures or updates Swagger settings for this run.
     */
    public function withSwaggerConfig(
        bool $enabled,
        ?string $format = null,
        ?string $path = null,
        ?bool $explicit = null
    ): self {
        $meta = $this->metadata;
        $swaggerMeta = (array) ($meta['swagger'] ?? []);
        $swaggerMeta['enabled'] = $enabled;
        if (! $enabled) {
            $swaggerMeta['disabled'] = true;
        } else {
            unset($swaggerMeta['disabled']);
        }
        if ($format !== null) {
            $swaggerMeta['format'] = $format;
        }
        if ($path !== null) {
            $swaggerMeta['path'] = $path;
        }
        if ($explicit !== null) {
            $swaggerMeta['explicit'] = $explicit;
        }
        $meta['swagger'] = $swaggerMeta;
        $meta['updated_at'] = date('c');

        return new self(
            runId: $this->runId,
            prompt: $this->prompt,
            currentNode: $this->currentNode,
            status: $this->status,
            requirementsSpec: $this->requirementsSpec,
            backlog: $this->backlog,
            technicalPlan: $this->technicalPlan,
            gitState: $this->gitState,
            artifacts: $this->artifacts,
            testResults: $this->testResults,
            reviews: $this->reviews,
            scrapingResults: $this->scrapingResults,
            delegations: $this->delegations,
            guardrails: $this->guardrails,
            metadata: $meta,
            targetProjectPath: $this->targetProjectPath,
            workspaceMode: $this->workspaceMode
        );
    }

    /**
     * Recursively extracts all valid HTTP/HTTPS URLs from any data structure.
     *
     * @return array<int, string>
     */
    private static function extractHttpUrlsFromValue(mixed $value): array
    {
        $urls = [];

        if (is_string($value)) {
            preg_match_all('/https?:\/\/[^\s\'"<>\)]+/i', $value, $matches);
            foreach ($matches[0] ?? [] as $url) {
                $trimmed = rtrim($url, '.,;:');
                if (filter_var($trimmed, FILTER_VALIDATE_URL) !== false) {
                    $urls[] = $trimmed;
                }
            }
        } elseif (is_array($value)) {
            foreach ($value as $item) {
                $urls = array_merge($urls, self::extractHttpUrlsFromValue($item));
            }
        } elseif (is_object($value)) {
            if (method_exists($value, 'toArray')) {
                $urls = array_merge($urls, self::extractHttpUrlsFromValue($value->toArray()));
            } else {
                $urls = array_merge($urls, self::extractHttpUrlsFromValue(get_object_vars($value)));
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * Recursively looks for an endpoint URL (either via key name or regex pattern) in a structure.
     */
    private static function extractEndpointFromValue(mixed $value): ?string
    {
        if (is_array($value)) {
            foreach (['target_endpoint', 'endpoint_url', 'endpoint', 'destination_endpoint', 'destination'] as $key) {
                if (! empty($value[$key]) && is_string($value[$key])) {
                    $trimmed = trim($value[$key]);
                    if (filter_var($trimmed, FILTER_VALIDATE_URL) !== false) {
                        return $trimmed;
                    }
                }
            }

            if (! empty($value['web_scraping']) && is_array($value['web_scraping'])) {
                $found = self::extractEndpointFromValue($value['web_scraping']);
                if ($found !== null) {
                    return $found;
                }
            }

            foreach ($value as $item) {
                if (is_string($item)) {
                    $found = self::extractEndpointFromString($item);
                    if ($found !== null) {
                        return $found;
                    }
                } elseif (is_array($item) || is_object($item)) {
                    $found = self::extractEndpointFromValue($item);
                    if ($found !== null) {
                        return $found;
                    }
                }
            }
        } elseif (is_string($value)) {
            return self::extractEndpointFromString($value);
        }

        return null;
    }

    private static function extractEndpointFromString(string $text): ?string
    {
        if (preg_match('/(?:target_endpoint|endpoint_url|endpoint|destination|send to|post to)\s*[:=]?\s*(https?:\/\/[^\s\'"<>\)]+)/i', $text, $matches)) {
            $trimmed = rtrim($matches[1], '.,;:');
            if (filter_var($trimmed, FILTER_VALIDATE_URL) !== false) {
                return $trimmed;
            }
        }

        return null;
    }

    /**
     * Checks if the workflow should terminate after scraping is completed.
     */
    public function shouldStopAfterScraping(): bool
    {
        return (bool) ($this->metadata['web_scraping']['stop_after'] ?? $this->metadata['stop_after_scraping'] ?? false);
    }

    /**
     * Checks if project memory is enabled for this run.
     */
    public function isMemoryEnabled(): bool
    {
        return (bool) ($this->metadata['memory_enabled'] ?? Config::get('lara-orcest.memory.enabled', true));
    }

    /**
     * Configures or updates project memory enablement for this run.
     */
    public function withMemoryConfig(bool $enabled): self
    {
        $meta = $this->metadata;
        $meta['memory_enabled'] = $enabled;

        return new self(
            runId: $this->runId,
            prompt: $this->prompt,
            currentNode: $this->currentNode,
            status: $this->status,
            requirementsSpec: $this->requirementsSpec,
            backlog: $this->backlog,
            technicalPlan: $this->technicalPlan,
            gitState: $this->gitState,
            artifacts: $this->artifacts,
            testResults: $this->testResults,
            reviews: $this->reviews,
            scrapingResults: $this->scrapingResults,
            delegations: $this->delegations,
            guardrails: $this->guardrails,
            metadata: $meta,
            targetProjectPath: $this->targetProjectPath,
            workspaceMode: $this->workspaceMode
        );
    }

    /**
     * Forks this state into an isolated branch context for parallel execution.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function forkBranch(string $branchId, array $metadata = []): self
    {
        $meta = $this->metadata;
        $meta['branch_id'] = $branchId;
        $meta['forked_from'] = [
            'step_count' => (int) ($this->guardrails['step_count'] ?? 0),
            'fix_attempts' => (int) ($this->guardrails['fix_attempts'] ?? 0),
            'token_usage' => $this->guardrails['token_usage'] ?? [
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'total_tokens' => 0,
                'cost' => 0.0,
            ],
            'artifact_count' => count($this->artifacts),
            'review_count' => count($this->reviews),
            'timestamp' => date('c'),
        ];

        if (! empty($metadata)) {
            $meta = array_merge($meta, $metadata);
        }

        return new self(
            runId: $this->runId,
            prompt: $this->prompt,
            currentNode: $this->currentNode,
            status: $this->status,
            requirementsSpec: $this->requirementsSpec,
            backlog: $this->backlog,
            technicalPlan: $this->technicalPlan,
            gitState: $this->gitState,
            artifacts: $this->artifacts,
            testResults: $this->testResults,
            reviews: $this->reviews,
            scrapingResults: $this->scrapingResults,
            delegations: $this->delegations,
            guardrails: $this->guardrails,
            metadata: $meta,
            targetProjectPath: $this->targetProjectPath,
            workspaceMode: $this->workspaceMode
        );
    }

    /**
     * Deterministically merges multiple parallel branch execution states into this state.
     *
     * @param  array<int|string, RunState>  $branchStates
     * @param  array<string, mixed>  $options  ['throw_on_conflict' => bool]
     */
    public function mergeParallelStates(array $branchStates, array $options = []): self
    {
        if (empty($branchStates)) {
            return $this;
        }

        $throwOnConflict = (bool) ($options['throw_on_conflict'] ?? false);

        // 1. Collect newly recorded artifacts per branch
        $branchAddedArtifacts = [];
        $failedBranches = [];
        $branchStatesList = [];

        $index = 0;
        foreach ($branchStates as $key => $branchState) {
            if (! ($branchState instanceof self)) {
                continue;
            }

            $branchId = (string) ($branchState->metadata['branch_id'] ?? (is_string($key) ? $key : ('branch_' . $index)));
            $branchStatesList[$branchId] = $branchState;

            if ($branchState->isFailed()) {
                $failedBranches[$branchId] = $branchState->getFailureReason() ?? 'Unknown branch failure';
            }

            // Identify artifacts added in this branch
            $baseArtifactCount = (int) ($branchState->metadata['forked_from']['artifact_count'] ?? count($this->artifacts));
            $addedArtifacts = array_slice($branchState->artifacts, $baseArtifactCount);

            $branchAddedArtifacts[$branchId] = $addedArtifacts;
            $index++;
        }

        // 2. Detect conflicting file mutations across parallel branches
        $conflicts = ScopeConflictDetector::findCollidingArtifacts($branchAddedArtifacts);
        if (! empty($conflicts)) {
            $conflictDetails = [];
            foreach ($conflicts as $path => $entries) {
                $branchesInvolved = array_unique(array_column($entries, 'branch'));
                $conflictDetails[] = "[Path: {$path} modified by branches: " . implode(', ', $branchesInvolved) . ']';
            }

            $message = 'Parallel execution state conflict: overlapping artifact modifications detected for paths: ' . implode('; ', $conflictDetails);

            if ($throwOnConflict) {
                throw new StateMergeConflictException($message, $conflicts);
            }

            return $this->fail($message);
        }

        // If any branch failed and not conflict-related, propagate failure
        if (! empty($failedBranches)) {
            $reasons = [];
            foreach ($failedBranches as $bId => $reason) {
                $reasons[] = "[{$bId}: {$reason}]";
            }

            return $this->fail('Parallel execution failed in branches: ' . implode(', ', $reasons));
        }

        // 3. Deterministically merge non-conflicting artifacts
        $mergedArtifacts = $this->artifacts;
        $existingKeys = [];
        foreach ($mergedArtifacts as $art) {
            $existingKeys[$art['path'] . '|' . $art['action']] = true;
        }

        foreach ($branchAddedArtifacts as $branchId => $artifacts) {
            foreach ($artifacts as $art) {
                $key = ($art['path'] ?? '') . '|' . ($art['action'] ?? '');
                if (! isset($existingKeys[$key])) {
                    $mergedArtifacts[] = $art;
                    $existingKeys[$key] = true;
                }
            }
        }

        // 4. Accumulate token usage & guardrails (steps, fix attempts)
        $guardrails = $this->guardrails;
        $usage = $guardrails['token_usage'] ?? [
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'total_tokens' => 0,
            'cost' => 0.0,
        ];

        $deltaPrompt = 0;
        $deltaCompletion = 0;
        $deltaCost = 0.0;
        $deltaSteps = 0;
        $deltaFixes = 0;

        foreach ($branchStatesList as $bState) {
            $baseUsage = $bState->metadata['forked_from']['token_usage'] ?? [
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'total_tokens' => 0,
                'cost' => 0.0,
            ];

            $bUsage = $bState->guardrails['token_usage'] ?? [];
            $deltaPrompt += max(0, ($bUsage['prompt_tokens'] ?? 0) - ($baseUsage['prompt_tokens'] ?? 0));
            $deltaCompletion += max(0, ($bUsage['completion_tokens'] ?? 0) - ($baseUsage['completion_tokens'] ?? 0));
            $deltaCost += max(0.0, (float) ($bUsage['cost'] ?? 0.0) - (float) ($baseUsage['cost'] ?? 0.0));

            $baseSteps = (int) ($bState->metadata['forked_from']['step_count'] ?? 0);
            $bSteps = (int) ($bState->guardrails['step_count'] ?? 0);
            $deltaSteps += max(0, $bSteps - $baseSteps);

            $baseFixes = (int) ($bState->metadata['forked_from']['fix_attempts'] ?? 0);
            $bFixes = (int) ($bState->guardrails['fix_attempts'] ?? 0);
            $deltaFixes += max(0, $bFixes - $baseFixes);
        }

        $usage['prompt_tokens'] = ($usage['prompt_tokens'] ?? 0) + $deltaPrompt;
        $usage['completion_tokens'] = ($usage['completion_tokens'] ?? 0) + $deltaCompletion;
        $usage['total_tokens'] = ($usage['prompt_tokens'] + $usage['completion_tokens']);
        $usage['cost'] = round(($usage['cost'] ?? 0.0) + $deltaCost, 6);
        $guardrails['token_usage'] = $usage;
        $guardrails['step_count'] = ($guardrails['step_count'] ?? 0) + $deltaSteps;
        $guardrails['fix_attempts'] = ($guardrails['fix_attempts'] ?? 0) + $deltaFixes;

        // 5. Merge test results
        $mergedTestResults = $this->testResults;
        $hasTestRun = false;
        $allPassed = true;
        $combinedSummary = [];
        $combinedOutput = [];
        $maxExitCode = 0;

        foreach ($branchStatesList as $branchId => $bState) {
            if (! empty($bState->testResults)) {
                $hasTestRun = true;
                $passed = (bool) ($bState->testResults['passed'] ?? false);
                if (! $passed) {
                    $allPassed = false;
                }
                $maxExitCode = max($maxExitCode, (int) ($bState->testResults['exit_code'] ?? 0));
                $combinedSummary[] = "[{$branchId}]: " . ($bState->testResults['summary'] ?? '');
                $combinedOutput[] = "=== [Branch {$branchId} Tests] ===\n" . ($bState->testResults['output'] ?? '');
            }
        }

        if ($hasTestRun) {
            $mergedTestResults = [
                'passed' => $allPassed,
                'exit_code' => $maxExitCode,
                'summary' => implode(' | ', $combinedSummary),
                'output' => mb_substr(implode("\n", $combinedOutput), 0, 20000),
                'timestamp' => date('c'),
            ];
        }

        // 6. Merge reviews
        $mergedReviews = $this->reviews;
        foreach ($branchStatesList as $branchId => $bState) {
            $baseRevCount = (int) ($bState->metadata['forked_from']['review_count'] ?? count($this->reviews));
            $addedReviews = array_slice($bState->reviews, $baseRevCount);
            foreach ($addedReviews as $rev) {
                $rev['branch_id'] = $branchId;
                $mergedReviews[] = $rev;
            }
        }

        // 7. Update backlog tasks if any tasks were marked completed in branches
        $mergedBacklog = $this->backlog;
        foreach ($branchStatesList as $bState) {
            foreach ($bState->backlog as $bTask) {
                $bId = is_array($bTask) ? ($bTask['id'] ?? null) : ($bTask->id ?? null);
                $bStatus = is_array($bTask) ? ($bTask['status'] ?? null) : ($bTask->status ?? null);

                if ($bId !== null && $bStatus === 'completed') {
                    foreach ($mergedBacklog as $idx => $mTask) {
                        $mId = is_array($mTask) ? ($mTask['id'] ?? null) : ($mTask->id ?? null);
                        if ($mId === $bId) {
                            if (is_array($mTask)) {
                                $mergedBacklog[$idx]['status'] = 'completed';
                            } else {
                                $mergedBacklog[$idx] = $mTask->withStatus('completed');
                            }
                        }
                    }
                }
            }
        }

        // 8. Merge Git State commits
        $gitState = $this->gitState;
        $existingHashes = array_column($gitState['commits'] ?? [], 'commit_hash');
        foreach ($branchStatesList as $bState) {
            if (! empty($bState->gitState['feature_branch'])) {
                $gitState['feature_branch'] = $bState->gitState['feature_branch'];
            }
            if (! empty($bState->gitState['base_branch'])) {
                $gitState['base_branch'] = $bState->gitState['base_branch'];
            }
            if (isset($bState->gitState['branch_created'])) {
                $gitState['branch_created'] = $bState->gitState['branch_created'];
            }
            if (! empty($bState->gitState['status'])) {
                $gitState['status'] = $bState->gitState['status'];
            }
            foreach ($bState->gitState['commits'] ?? [] as $commit) {
                $hash = $commit['commit_hash'] ?? null;
                if ($hash !== null && ! in_array($hash, $existingHashes, true)) {
                    $gitState['commits'][] = $commit;
                    $existingHashes[] = $hash;
                }
            }
        }

        // 9. Update metadata with merge log
        $meta = $this->metadata;
        $mergesLog = $meta['parallel_merges'] ?? [];
        $mergesLog[] = [
            'branches' => array_keys($branchStatesList),
            'artifacts_added' => count($mergedArtifacts) - count($this->artifacts),
            'delta_tokens' => ($deltaPrompt + $deltaCompletion),
            'delta_steps' => $deltaSteps,
            'timestamp' => date('c'),
        ];
        $meta['parallel_merges'] = $mergesLog;
        $meta['updated_at'] = date('c');

        $mergedScraping = $this->scrapingResults;
        foreach ($branchStatesList as $bState) {
            if (! empty($bState->scrapingResults)) {
                $mergedScraping = array_merge($mergedScraping, $bState->scrapingResults);
            }
        }

        $mergedDelegations = $this->delegations;
        foreach ($branchStatesList as $bState) {
            if (! empty($bState->delegations)) {
                $mergedDelegations = array_merge($mergedDelegations, $bState->delegations);
            }
        }

        return new self(
            runId: $this->runId,
            prompt: $this->prompt,
            currentNode: $this->currentNode,
            status: $this->status,
            requirementsSpec: $this->requirementsSpec,
            backlog: $mergedBacklog,
            technicalPlan: $this->technicalPlan,
            gitState: $gitState,
            artifacts: $mergedArtifacts,
            testResults: $mergedTestResults,
            reviews: $mergedReviews,
            scrapingResults: $mergedScraping,
            delegations: $mergedDelegations,
            guardrails: $guardrails,
            metadata: $meta,
            targetProjectPath: $this->targetProjectPath,
            workspaceMode: $this->workspaceMode
        );
    }

    /**
     * Serialize state to a pure JSON-serializable array without secrets.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        // Redact any accidental secrets from metadata
        $sanitizedMetadata = $this->sanitizeArray($this->metadata);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'run_id' => $this->runId,
            'prompt' => $this->prompt,
            'current_node' => $this->currentNode,
            'status' => $this->status,
            'target_project_path' => $this->targetProjectPath,
            'workspace_mode' => $this->workspaceMode,
            'requirements_spec' => $this->requirementsSpec,
            'backlog' => $this->backlog,
            'technical_plan' => $this->technicalPlan,
            'git_state' => $this->gitState,
            'artifacts' => $this->artifacts,
            'test_results' => $this->testResults,
            'reviews' => $this->reviews,
            'scraping_results' => $this->getScrapingResults(),
            'package_manager' => $this->getPackageManager(),
            'delegations' => $this->delegations,
            'guardrails' => $this->guardrails,
            'metadata' => $sanitizedMetadata,
        ];
    }

    /**
     * Reconstruct RunState from an array with validation.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        if (empty($data['run_id'])) {
            throw new InvalidArgumentException('Missing required run_id in RunState data');
        }

        return new self(
            runId: (string) $data['run_id'],
            prompt: (string) ($data['prompt'] ?? ''),
            currentNode: (string) ($data['current_node'] ?? 'start'),
            status: (string) ($data['status'] ?? self::STATUS_RUNNING),
            requirementsSpec: (array) ($data['requirements_spec'] ?? []),
            backlog: (array) ($data['backlog'] ?? []),
            technicalPlan: (array) ($data['technical_plan'] ?? []),
            gitState: (array) ($data['git_state'] ?? []),
            artifacts: (array) ($data['artifacts'] ?? []),
            testResults: (array) ($data['test_results'] ?? []),
            reviews: (array) ($data['reviews'] ?? []),
            scrapingResults: (array) ($data['scraping_results'] ?? ($data['metadata']['web_scraping'] ?? [])),
            delegations: (array) ($data['delegations'] ?? []),
            guardrails: (array) ($data['guardrails'] ?? [
                'step_count' => 0,
                'max_steps' => 40,
                'fix_attempts' => 0,
                'max_fix_attempts' => 3,
                'dry_run' => false,
                'token_usage' => [
                    'prompt_tokens' => 0,
                    'completion_tokens' => 0,
                    'total_tokens' => 0,
                    'cost' => 0.0,
                ],
            ]),
            metadata: (function () use ($data) {
                $meta = (array) ($data['metadata'] ?? []);
                if (isset($data['package_manager']) && is_array($data['package_manager'])) {
                    $meta['package_manager'] = array_merge((array) ($meta['package_manager'] ?? []), $data['package_manager']);
                }
                return $meta;
            })(),
            targetProjectPath: isset($data['target_project_path']) ? (string) $data['target_project_path'] : null,
            workspaceMode: (string) ($data['workspace_mode'] ?? 'embedded')
        );
    }

    /**
     * Records structured package manager / bootstrapping results in metadata.
     *
     * @param  array<string, mixed>  $data
     */
    public function withPackageManager(array $data): self
    {
        $meta = $this->metadata;
        $meta['package_manager'] = array_merge((array) ($meta['package_manager'] ?? []), $data);
        $meta['updated_at'] = date('c');

        return new self(
            runId: $this->runId,
            prompt: $this->prompt,
            currentNode: $this->currentNode,
            status: $this->status,
            requirementsSpec: $this->requirementsSpec,
            backlog: $this->backlog,
            technicalPlan: $this->technicalPlan,
            gitState: $this->gitState,
            artifacts: $this->artifacts,
            testResults: $this->testResults,
            reviews: $this->reviews,
            scrapingResults: $this->scrapingResults,
            delegations: $this->delegations,
            guardrails: $this->guardrails,
            metadata: $meta,
            targetProjectPath: $this->targetProjectPath,
            workspaceMode: $this->workspaceMode
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getPackageManager(): array
    {
        return (array) ($this->metadata['package_manager'] ?? []);
    }

    public function isProjectBootstrapped(): bool
    {
        $pm = $this->getPackageManager();

        return ! empty($pm['bootstrapped']);
    }

    public function shouldRunPackageManager(): bool
    {
        if ($this->isPackageManagerDisabled()) {
            return false;
        }

        return (bool) Config::get('lara-orcest.package_manager.enabled', true);
    }

    public function isPackageManagerDisabled(): bool
    {
        $pm = (array) ($this->metadata['package_manager'] ?? []);

        return ! empty($pm['disabled']);
    }

    public function withPackageManagerConfig(bool $enabled): self
    {
        $meta = $this->metadata;
        $pm = (array) ($meta['package_manager'] ?? []);
        $pm['enabled'] = $enabled;
        if (! $enabled) {
            $pm['disabled'] = true;
        } else {
            unset($pm['disabled']);
        }
        $meta['package_manager'] = $pm;

        return new self(
            runId: $this->runId,
            prompt: $this->prompt,
            currentNode: $this->currentNode,
            status: $this->status,
            requirementsSpec: $this->requirementsSpec,
            backlog: $this->backlog,
            technicalPlan: $this->technicalPlan,
            gitState: $this->gitState,
            artifacts: $this->artifacts,
            testResults: $this->testResults,
            reviews: $this->reviews,
            scrapingResults: $this->scrapingResults,
            delegations: $this->delegations,
            guardrails: $this->guardrails,
            metadata: $meta,
            targetProjectPath: $this->targetProjectPath,
            workspaceMode: $this->workspaceMode
        );
    }

    /**
     * Returns list of packages recorded as installed or requested.
     *
     * @return array<int, mixed>
     */
    public function getInstalledPackages(): array
    {
        $pm = $this->getPackageManager();

        return (array) ($pm['packages'] ?? []);
    }

    /**
     * Returns list of directories recorded as scaffolded.
     *
     * @return array<int, string>
     */
    public function getScaffoldedDirectories(): array
    {
        $pm = $this->getPackageManager();

        return (array) ($pm['scaffolded_directories'] ?? []);
    }

    
    public function getTargetProjectPath(): ?string
    {
        return $this->targetProjectPath;
    }

    public function getWorkspaceMode(): string
    {
        return $this->workspaceMode;
    }

    public function isStandalone(): bool
    {
        return $this->workspaceMode === 'standalone';
    }

    public function isEmbedded(): bool
    {
        return $this->workspaceMode === 'embedded';
    }

    public function withTargetProjectPath(?string $targetProjectPath): self
    {
        return new self(
            runId: $this->runId,
            prompt: $this->prompt,
            currentNode: $this->currentNode,
            status: $this->status,
            requirementsSpec: $this->requirementsSpec,
            backlog: $this->backlog,
            technicalPlan: $this->technicalPlan,
            gitState: $this->gitState,
            artifacts: $this->artifacts,
            testResults: $this->testResults,
            reviews: $this->reviews,
            scrapingResults: $this->scrapingResults,
            delegations: $this->delegations,
            guardrails: $this->guardrails,
            metadata: $this->metadata,
            targetProjectPath: $targetProjectPath,
            workspaceMode: $this->workspaceMode
        );
    }

    public function withWorkspaceMode(string $workspaceMode): self
    {
        return new self(
            runId: $this->runId,
            prompt: $this->prompt,
            currentNode: $this->currentNode,
            status: $this->status,
            requirementsSpec: $this->requirementsSpec,
            backlog: $this->backlog,
            technicalPlan: $this->technicalPlan,
            gitState: $this->gitState,
            artifacts: $this->artifacts,
            testResults: $this->testResults,
            reviews: $this->reviews,
            scrapingResults: $this->scrapingResults,
            delegations: $this->delegations,
            guardrails: $this->guardrails,
            metadata: $this->metadata,
            targetProjectPath: $this->targetProjectPath,
            workspaceMode: $workspaceMode
        );
    }

    private function validateStatus(string $status): void
    {
        $valid = [
            self::STATUS_RUNNING,
            self::STATUS_PAUSED,
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
        ];

        if (! in_array($status, $valid, true)) {
            throw new InvalidArgumentException("Invalid RunState status: [{$status}]");
        }
    }

    /**
     * @param  array<string, mixed>  $array
     * @return array<string, mixed>
     */
    private function sanitizeArray(array $array): array
    {
        $sensitiveKeys = ['api_key', 'apikey', 'token', 'secret', 'password', 'authorization'];
        $clean = [];

        foreach ($array as $k => $v) {
            if (in_array(strtolower((string) $k), $sensitiveKeys, true)) {
                $clean[$k] = '[REDACTED]';
            } elseif (is_array($v)) {
                $clean[$k] = $this->sanitizeArray($v);
            } else {
                $clean[$k] = $v;
            }
        }

        return $clean;
    }
}
