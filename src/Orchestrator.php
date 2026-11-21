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

class Orchestrator
{
    public function __construct(
        protected ?LlmManager $llmManager = null,
        protected ?CheckpointerContract $checkpointer = null
    ) {
        $this->llmManager = $llmManager ?: new LlmManager();
        $this->checkpointer = $checkpointer ?: new StateCheckpointer();
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

        $state = RunState::create($prompt, $options);
        $this->checkpointer->save($state);

        $driverName = (string) ($options['llm'] ?? $this->llmManager->getDefaultDriver());
        $llmDriver = $this->llmManager->driver($driverName);

        $graph = DefaultGraphBuilder::build(
            llm: $llmDriver,
            checkpointer: $this->checkpointer,
            withScraper: $state->hasScrapingUrls()
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
        $state = $this->checkpointer->load($runId);
        if ($state === null) {
            throw new InvalidArgumentException("No checkpoint found for run ID: [{$runId}]");
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
                $this->checkpointer->save($state);

                return $state;
            }

            // Approved decision
            $state = $state->recordHumanDecision($pausedGate, 'approved', $note);
            $this->checkpointer->save($state);
        }

        $driverName = (string) ($options['llm'] ?? $this->llmManager->getDefaultDriver());
        $llmDriver = $this->llmManager->driver($driverName);

        $graph = DefaultGraphBuilder::build(
            llm: $llmDriver,
            checkpointer: $this->checkpointer,
            withScraper: $state->hasScrapingUrls()
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

        return ($container !== null && $container->bound(ProjectMemoryContract::class))
            ? $container->make(ProjectMemoryContract::class)
            : new ProjectMemoryStore();
    }
}
