<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest;

use InvalidArgumentException;
use RuntimeException;
use Yousef\LaraOrcest\Contracts\CheckpointerContract;
use Yousef\LaraOrcest\Graph\DefaultGraphBuilder;
use Yousef\LaraOrcest\Graph\GraphEngine;
use Yousef\LaraOrcest\LLM\LlmManager;
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
            checkpointer: $this->checkpointer
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

        $driverName = (string) ($options['llm'] ?? $this->llmManager->getDefaultDriver());
        $llmDriver = $this->llmManager->driver($driverName);

        $graph = DefaultGraphBuilder::build(
            llm: $llmDriver,
            checkpointer: $this->checkpointer
        );

        if (isset($options['on_step']) && is_callable($options['on_step'])) {
            $graph->onStep($options['on_step']);
        }

        return $graph->run($state);
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

            return $state->toArray();
        }

        return $this->checkpointer->list();
    }
}
