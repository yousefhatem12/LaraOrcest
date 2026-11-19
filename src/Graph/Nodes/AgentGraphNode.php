<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Graph\Nodes;

use Yousef\LaraOrcest\Contracts\AgentContract;
use Yousef\LaraOrcest\Contracts\CheckpointerContract;
use Yousef\LaraOrcest\Contracts\GraphNodeContract;
use Yousef\LaraOrcest\Contracts\LlmDriverContract;
use Yousef\LaraOrcest\Contracts\ToolContract;
use Yousef\LaraOrcest\State\RunState;

class AgentGraphNode implements GraphNodeContract
{
    /**
     * @param  array<string, ToolContract>  $tools
     */
    public function __construct(
        protected AgentContract $agent,
        protected array $tools,
        protected LlmDriverContract $llm,
        protected ?CheckpointerContract $checkpointer = null
    ) {}

    public function getKey(): string
    {
        return $this->agent->getName();
    }

    public function getAgent(): AgentContract
    {
        return $this->agent;
    }

    public function setCheckpointer(?CheckpointerContract $checkpointer): self
    {
        $this->checkpointer = $checkpointer;

        return $this;
    }

    public function execute(RunState $state): RunState
    {
        $state = $state->withCurrentNode($this->getKey());
        $state = $state->incrementSteps();

        // Check if step guardrail exceeded
        if ($state->hasExceededSteps()) {
            $failedState = $state->fail("Execution step limit exceeded at node [{$this->getKey()}].");
            $this->saveCheckpoint($failedState);

            return $failedState;
        }

        try {
            $updatedState = $this->agent->execute($state, $this->tools, $this->llm);
            $this->saveCheckpoint($updatedState);

            return $updatedState;
        } catch (\Throwable $e) {
            $failedState = $state->fail("Node [{$this->getKey()}] execution failed: " . $e->getMessage());
            $this->saveCheckpoint($failedState);

            throw $e;
        }
    }

    protected function saveCheckpoint(RunState $state): void
    {
        if ($this->checkpointer !== null) {
            $this->checkpointer->save($state);
        }
    }
}
