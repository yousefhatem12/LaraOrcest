<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest;

use InvalidArgumentException;
use RuntimeException;
use Yousef\LaraOrcest\Contracts\CheckpointerContract;
use Yousef\LaraOrcest\Contracts\ProjectMemoryContract;
use Yousef\LaraOrcest\Graph\DefaultGraphBuilder;
use Yousef\LaraOrcest\Graph\GraphEngine;
use Yousef\LaraOrcest\LLM\LlmManager;
use Yousef\LaraOrcest\Memory\ProjectMemoryStore;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\State\StateCheckpointer;
use Yousef\LaraOrcest\Workspace\WorkspaceContext;

class Orchestrator
{
    protected WorkspaceContext $workspaceContext;

    public function __construct(
        protected ?LlmManager $llmManager = null,
        protected ?CheckpointerContract $checkpointer = null,
        ?WorkspaceContext $workspaceContext = null
    ) {
        $this->llmManager = $llmManager ?: new LlmManager();
        $this->workspaceContext = $workspaceContext ?: WorkspaceContext::embedded();
        $this->checkpointer = $checkpointer ?: $this->workspaceContext->createCheckpointer();
    }

    public function getWorkspaceContext(): WorkspaceContext
    {
        return $this->workspaceContext;
    }

    public function withWorkspaceContext(WorkspaceContext $context): self
    {
        return new self(
            llmManager: $this->llmManager,
            checkpointer: $this->checkpointer,
            workspaceContext: $context
        );
    }

    /**
     * Executes a new orchestration run.
     *
     * @param  array<string, mixed>  $options
     */
    public function run(string $prompt, array $options = []): RunState
    {
        $enabled = (bool) \Yousef\LaraOrcest\Support\Config::get('lara-orcest.enabled', true);
        if (! $enabled) {
            throw new RuntimeException('LaraOrcest is disabled in configuration.');
        }

        $context = $this->resolveWorkspaceContext($options);
        $options['target_project_path'] = $context->getTargetProjectPath();
        $options['workspace_mode'] = $context->getWorkspaceMode();

        $state = RunState::create($prompt, $options);
        $checkpointer = $this->checkpointer ?: $context->createCheckpointer();
        $checkpointer->save($state);

        $driverName = (string) ($options['llm'] ?? $this->llmManager->getDefaultDriver());
        $llmDriver = $this->llmManager->driver($driverName);

        $graph = DefaultGraphBuilder::build(
            llm: $llmDriver,
            checkpointer: $checkpointer,
            withScraper: ! $state->isWebScrapingDisabled(),
            withSwagger: ! $state->isSwaggerDisabled(),
            workspaceContext: $context,
            withPackageManager: ! $state->isPackageManagerDisabled()
        );

        if (isset($options['on_step']) && is_callable($options['on_step'])) {
            $graph->onStep($options['on_step']);
        }

        return $graph->run($state);
    }

    /**
     * Resumes an existing run from its checkpoint.
     *
     * @param  array<string, mixed>  $options
     */
    public function resume(string $runId, array $options = []): RunState
    {
        $context = $this->resolveWorkspaceContext($options);
        $checkpointer = $this->checkpointer ?: $context->createCheckpointer();

        $state = $checkpointer->load($runId);
        if ($state === null && $context !== $this->workspaceContext) {
            $checkpointer = $this->workspaceContext->createCheckpointer();
            $state = $checkpointer->load($runId);
        }

        if ($state === null) {
            throw new InvalidArgumentException("No checkpoint found for run ID: [{$runId}]");
        }

        // Adjust context if state was previously created for a different target project path
        if ($state->targetProjectPath !== null && $state->targetProjectPath !== $context->getTargetProjectPath()) {
            $context = WorkspaceContext::standalone($state->targetProjectPath, $options);
        }

        if ($state->isCompleted() || $state->isFailed()) {
            return $state;
        }

        // Apply review mode overrides if requested
        $noHumanReview = (bool) ($options['no_human_review'] ?? $options['skip_human_review'] ?? $options['skip_review'] ?? false);
        if ($noHumanReview) {
            $state = $state->withHumanReviewConfig(enabled: false, skipReview: true);
        }

        // If run is currently paused at a review gate, process decision
        $pausedGate = $state->getPausedGate();
        if ($pausedGate !== null) {
            $decision = strtolower((string) ($options['decision'] ?? (! empty($options['reject']) ? 'rejected' : 'approved')));
            $note = isset($options['note']) ? (string) $options['note'] : (isset($options['notes']) ? (string) $options['notes'] : null);

            if ($decision === 'reject' || $decision === 'rejected') {
                $state = $state->recordHumanDecision($pausedGate, 'rejected', $note);
                $state = $state->fail("Human reviewer rejected run at gate [{$pausedGate}]: " . ($note ?: 'No notes provided.'));
                $checkpointer->save($state);

                return $state;
            }

            // Approved decision
            $state = $state->recordHumanDecision($pausedGate, 'approved', $note);
            $checkpointer->save($state);
        }

        $driverName = (string) ($options['llm'] ?? $this->llmManager->getDefaultDriver());
        $llmDriver = $this->llmManager->driver($driverName);

        $graph = DefaultGraphBuilder::build(
            llm: $llmDriver,
            checkpointer: $checkpointer,
            withScraper: ! $state->isWebScrapingDisabled(),
            withSwagger: ! $state->isSwaggerDisabled(),
            workspaceContext: $context,
            withPackageManager: ! $state->isPackageManagerDisabled()
        );

        if (isset($options['on_step']) && is_callable($options['on_step'])) {
            $graph->onStep($options['on_step']);
        }

        return $graph->run($state);
    }

    /**
     * Approves a paused run at its active review gate and resumes execution.
     *
     * @param  array<string, mixed>  $options
     */
    public function approve(string $runId, ?string $notes = null, array $options = []): RunState
    {
        $options['decision'] = 'approved';
        if ($notes !== null) {
            $options['note'] = $notes;
        }

        return $this->resume($runId, $options);
    }

    /**
     * Rejects a paused run at its active review gate and terminates the run with a failed status.
     *
     * @param  array<string, mixed>  $options
     */
    public function reject(string $runId, ?string $notes = null, array $options = []): RunState
    {
        $options['decision'] = 'rejected';
        if ($notes !== null) {
            $options['note'] = $notes;
        }

        return $this->resume($runId, $options);
    }

    /**
     * Lists recent runs or returns state for a specific run.
     *
     * @return array<string, mixed>|array<int, mixed>
     */
    public function status(?string $runId = null): array
    {
        if ($runId !== null) {
            $state = $this->checkpointer->load($runId);
            if ($state === null) {
                return ['error' => "Run [{$runId}] not found."];
            }

            $data = $state->toArray();
            if ($state->isPaused()) {
                $data['paused_gate'] = $state->getPausedGate();
                $data['pause_context'] = $state->getPauseContext();
            }

            return $data;
        }

        return $this->checkpointer->list();
    }

    /**
     * Accesses the project memory store.
     */
    public function memory(): ProjectMemoryContract
    {
        $container = \Illuminate\Container\Container::getInstance();

        if ($container !== null && $container->bound(ProjectMemoryContract::class)) {
            return $container->make(ProjectMemoryContract::class);
        }

        return $this->workspaceContext !== null
            ? $this->workspaceContext->createMemoryStore()
            : new ProjectMemoryStore();
    }

    /**
     * Resolves the WorkspaceContext for a set of run options.
     *
     * @param  array<string, mixed>  $options
     */
    protected function resolveWorkspaceContext(array $options, ?RunState $existingState = null): WorkspaceContext
    {
        if (isset($options['workspace_context']) && $options['workspace_context'] instanceof WorkspaceContext) {
            return $options['workspace_context'];
        }

        $target = $options['target_project_path']
            ?? $options['target_path']
            ?? $options['target']
            ?? ($existingState?->targetProjectPath);

        if ($target !== null && trim((string) $target) !== '') {
            return WorkspaceContext::standalone((string) $target, $options);
        }

        return $this->workspaceContext;
    }
}
