<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Graph\Nodes;

use Yousef\LaraOrcest\Contracts\GraphNodeContract;
use Yousef\LaraOrcest\Graph\GraphEngine;
use Yousef\LaraOrcest\State\RunState;

class SubGraphNode implements GraphNodeContract
{
    public function __construct(
        protected string $key,
        protected GraphEngine $subGraph,
        protected ?string $startNode = null
    ) {}

    public function getKey(): string
    {
        return $this->key;
    }

    public function getSubGraph(): GraphEngine
    {
        return $this->subGraph;
    }

    public function execute(RunState $state): RunState
    {
        $state = $state->withCurrentNode($this->key);
        $state = $state->incrementSteps();

        if ($state->hasExceededSteps()) {
            return $state->fail("Execution step limit exceeded at sub-graph node [{$this->key}].");
        }

        // Determine starting node for the inner graph
        $subStart = $this->startNode ?? $this->subGraph->getStartNode();
        if ($subStart === null && ! $this->subGraph->hasNode($state->currentNode)) {
            $subStart = array_key_first($this->subGraph->getNodes());
        }

        if ($subStart !== null) {
            $state = $state->withCurrentNode($subStart);
        }

        $result = $this->subGraph->run($state);

        // If sub-graph completed successfully, reset to running with this node key
        // so outer graph can proceed across downstream transitions
        if ($result->isCompleted()) {
            $result = $result->withStatus(RunState::STATUS_RUNNING)->withCurrentNode($this->key);
        }

        return $result;
    }
}
