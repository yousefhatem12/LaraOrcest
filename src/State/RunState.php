<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\State;

use InvalidArgumentException;
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

        return new self(
            runId: $runId,
            prompt: trim($prompt),
            currentNode: 'start',
            status: self::STATUS_RUNNING,
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
