<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Graph\Nodes;

use Yousef\LaraOrcest\Contracts\AgentContract;
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
        protected LlmDriverContract $llm
    ) {}

    public function getKey(): string
    {
        return $this->agent->getName();
    }

    public function getAgent(): AgentContract
    {
        return $this->agent;
    }

    public function execute(RunState $state): RunState
    {
        $state = $state->withCurrentNode($this->getKey());
        $state = $state->incrementSteps();

        // Check if step guardrail exceeded
        if ($state->hasExceededSteps()) {
            return $state->withStatus(RunState::STATUS_FAILED);
        }

        return $this->agent->execute($state, $this->tools, $this->llm);
    }
}
