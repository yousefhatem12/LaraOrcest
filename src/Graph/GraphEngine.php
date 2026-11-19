<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Graph;

use Closure;
use RuntimeException;
use Yousef\LaraOrcest\Contracts\CheckpointerContract;
use Yousef\LaraOrcest\Contracts\GraphNodeContract;
use Yousef\LaraOrcest\Contracts\ToolContract;
use Yousef\LaraOrcest\Graph\Nodes\AgentGraphNode;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Tools\GitCommandTool;

class GraphEngine
{
    public const NODE_START = 'business_analyst';
    public const TERMINAL_COMPLETED = '__completed__';
    public const TERMINAL_FAILED = '__failed__';

    /**
     * @var array<string, GraphNodeContract>
     */
    protected array $nodes = [];

    /**
     * @var array<string, array<int, EdgeRule>>
     */
    protected array $edges = [];

    protected ?CheckpointerContract $checkpointer = null;

    /**
     * @var array<int, Closure>
     */
    protected array $stepListeners = [];

    public function __construct(?CheckpointerContract $checkpointer = null)
    {
        $this->checkpointer = $checkpointer;
    }

    public function addNode(GraphNodeContract $node): self
    {
        $this->nodes[$node->getKey()] = $node;

        return $this;
    }

    public function addEdge(string $fromNode, string $toNode, ?Closure $condition = null, int $priority = 10): self
    {
        $rule = new EdgeRule($fromNode, $toNode, $condition, $priority);
        $this->edges[$fromNode][] = $rule;

        // Sort edges by priority descending
        usort($this->edges[$fromNode], fn (EdgeRule $a, EdgeRule $b) => $b->priority <=> $a->priority);

        return $this;
    }

    public function onStep(Closure $listener): self
    {
        $this->stepListeners[] = $listener;

        return $this;
    }

    public function setCheckpointer(?CheckpointerContract $checkpointer): self
    {
        $this->checkpointer = $checkpointer;

        return $this;
    }

    /**
     * Executes the graph from the current state until a terminal node is reached or bounds exceeded.
     */
    public function run(RunState $state): RunState
    {
        $currentNode = $state->currentNode;
        if ($currentNode === 'start' || ! isset($this->nodes[$currentNode])) {
            $currentNode = self::NODE_START;
        }

        while ($state->status === RunState::STATUS_RUNNING) {
            // Guardrail check: max steps
            if ($state->hasExceededSteps()) {
                $state = $state->withStatus(RunState::STATUS_FAILED);
                $this->saveCheckpoint($state);
                $this->notifyStep($state, $currentNode, 'Maximum step count exceeded.');
                break;
            }

            if (! isset($this->nodes[$currentNode])) {
                throw new RuntimeException("Unknown graph node: [{$currentNode}]");
            }

            $node = $this->nodes[$currentNode];

            // Notify before step
            $this->notifyStep($state, $currentNode, "Executing node: {$currentNode}");

            // Execute node
            $state = $node->execute($state);

            // Persist checkpoint after node execution
            $this->saveCheckpoint($state);

            // Determine next transition
            $nextNode = $this->resolveNextNode($currentNode, $state);

            if ($nextNode === self::TERMINAL_COMPLETED) {
                $state = $this->finalizeRun($state);
                $state = $state->withStatus(RunState::STATUS_COMPLETED);
                $this->saveCheckpoint($state);
                $this->notifyStep($state, $currentNode, 'Run completed successfully.');
                break;
            }

            if ($nextNode === self::TERMINAL_FAILED) {
                $state = $state->withStatus(RunState::STATUS_FAILED);
                $this->saveCheckpoint($state);
                $this->notifyStep($state, $currentNode, 'Run terminated with failure.');
                break;
            }

            $currentNode = $nextNode;
        }

        return $state;
    }

    /**
     * Resolves the next node based on registered edge rules.
     */
    public function resolveNextNode(string $currentNode, RunState $state): string
    {
        if (empty($this->edges[$currentNode])) {
            return self::TERMINAL_COMPLETED;
        }

        foreach ($this->edges[$currentNode] as $edge) {
            if ($edge->matches($state)) {
                return $edge->toNode;
            }
        }

        throw new RuntimeException("No transition rule matched from node [{$currentNode}]");
    }

    /**
     * Finalizes the run by committing milestone changes if enabled.
     */
    protected function finalizeRun(RunState $state): RunState
    {
        $autoCommit = (bool) \Yousef\LaraOrcest\Support\Config::get('lara-orcest.git.auto_commit', true);

        if ($autoCommit && ! $state->isDryRun() && ! empty($state->artifacts)) {
            // Commit verified changes
            try {
                $git = new GitCommandTool();
                $artifactPaths = array_column($state->artifacts, 'path');
                $git->stageFiles($artifactPaths);
                $commitRes = $git->commit("feat(orcest): implement {$state->prompt}");

                $gitState = $state->gitState;
                $commits = $gitState['commits'] ?? [];
                $commits[] = $commitRes;
                $gitState['commits'] = $commits;

                $state = $state->withGitState($gitState);
            } catch (\Throwable) {
                // If git commit fails (e.g. no changes to commit), do not break completion
            }
        }

        return $state;
    }

    protected function saveCheckpoint(RunState $state): void
    {
        if ($this->checkpointer !== null) {
            $this->checkpointer->save($state);
        }
    }

    protected function notifyStep(RunState $state, string $node, string $message): void
    {
        foreach ($this->stepListeners as $listener) {
            $listener($state, $node, $message);
        }
    }
}
