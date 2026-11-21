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
        public readonly array $metadata = []
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

        $scrapingResults = (array) ($options['scraping_results'] ?? []);
        $webScrapingMeta = (array) ($options['web_scraping'] ?? []);
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

        return new self(
            runId: $runId,
            prompt: trim($prompt),
            currentNode: 'start',
            status: self::STATUS_RUNNING,
            scrapingResults: $scrapingResults,
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
                'human_in_the_loop' => [
                    'enabled' => $hitlEnabled,
                    'skip_review' => $skipReview,
                    'gates' => $gates,
                    'paused_gate' => null,
                    'pause_context' => null,
                    'decisions' => [],
                ],
            ]
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
            guardrails: $this->guardrails,
            metadata: $this->metadata
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
            guardrails: $this->guardrails,
            metadata: array_merge($this->metadata, ['updated_at' => date('c')])
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
            guardrails: $this->guardrails,
            metadata: $this->metadata
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
            guardrails: $this->guardrails,
            metadata: $this->metadata
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
            guardrails: $this->guardrails,
            metadata: $this->metadata
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
            guardrails: $this->guardrails,
            metadata: $this->metadata
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
            guardrails: $this->guardrails,
            metadata: $this->metadata
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
            guardrails: $this->guardrails,
            metadata: $this->metadata
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
            guardrails: $this->guardrails,
            metadata: $this->metadata
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
            guardrails: $guardrails,
            metadata: $this->metadata
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
            guardrails: $this->guardrails,
            metadata: $metadata
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
            guardrails: $guardrails,
            metadata: $this->metadata
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
            guardrails: $guardrails,
            metadata: $this->metadata
        );
    }

    public function recordArtifact(string $path, string $action, string $summary): self
    {
        $artifacts = $this->artifacts;
        $artifacts[] = [
            'path' => $path,
            'action' => $action,
            'summary' => $summary,
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
            artifacts: $artifacts,
            testResults: $this->testResults,
            reviews: $this->reviews,
            scrapingResults: $this->scrapingResults,
            guardrails: $this->guardrails,
            metadata: $this->metadata
        );
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
            guardrails: $this->guardrails,
            metadata: $this->metadata
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
            guardrails: $this->guardrails,
            metadata: $this->metadata
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
            guardrails: $guardrails,
            metadata: $this->metadata
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
            guardrails: $this->guardrails,
            metadata: array_merge($meta, ['updated_at' => date('c')])
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
            guardrails: $this->guardrails,
            metadata: array_merge($meta, ['updated_at' => date('c')])
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
            guardrails: $this->guardrails,
            metadata: array_merge($meta, ['updated_at' => date('c')])
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
            guardrails: $this->guardrails,
            metadata: $meta
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
            guardrails: $this->guardrails,
            metadata: $meta
        );
    }

    /**
     * Returns the recorded web scraping results.
     *
     * @return array<string, mixed>
     */
    public function getScrapingResults(): array
    {
        return ! empty($this->scrapingResults) ? $this->scrapingResults : (array) ($this->metadata['web_scraping'] ?? []);
    }

    /**
     * Checks if scraping target URLs are provided in metadata, options, or prompt.
     */
    public function hasScrapingUrls(): bool
    {
        if (! empty($this->metadata['web_scraping']['source_urls']) || ! empty($this->metadata['source_urls']) || ! empty($this->metadata['urls'])) {
            return true;
        }

        if (! empty($this->scrapingResults['source_urls'])) {
            return true;
        }

        // Check if prompt requests web scraping and contains at least 2 HTTP/HTTPS URLs
        if (preg_match('/scrape|crawl|fetch|extract.*website|extract.*url/i', $this->prompt)) {
            preg_match_all('/https?:\/\/[^\s\'"<>\)]+/i', $this->prompt, $matches);
            if (count($matches[0] ?? []) >= 2) {
                return true;
            }
        }

        return false;
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
            guardrails: $this->guardrails,
            metadata: $meta
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
            guardrails: $this->guardrails,
            metadata: $meta
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
            guardrails: $guardrails,
            metadata: $meta
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
            'requirements_spec' => $this->requirementsSpec,
            'backlog' => $this->backlog,
            'technical_plan' => $this->technicalPlan,
            'git_state' => $this->gitState,
            'artifacts' => $this->artifacts,
            'test_results' => $this->testResults,
            'reviews' => $this->reviews,
            'scraping_results' => $this->getScrapingResults(),
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
            metadata: (array) ($data['metadata'] ?? [])
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
