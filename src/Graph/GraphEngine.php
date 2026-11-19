<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Graph;

use Closure;
use RuntimeException;
use Yousef\LaraOrcest\Contracts\CheckpointerContract;
use Yousef\LaraOrcest\Contracts\GraphNodeContract;
use Yousef\LaraOrcest\Contracts\LlmDriverContract;
use Yousef\LaraOrcest\Contracts\ToolContract;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Support\Config;
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

    /**
     * Factory method creating a default 9-agent GraphEngine.
     *
     * @param  array<string, ToolContract>|null  $tools
     */
    public static function createDefault(
        LlmDriverContract $llm,
        ?CheckpointerContract $checkpointer = null,
        ?array $tools = null
    ): self {
        return DefaultGraphBuilder::build($llm, $checkpointer, $tools);
    }

    public function addNode(GraphNodeContract $node): self
    {
        $this->nodes[$node->getKey()] = $node;

        return $this;
    }

    public function hasNode(string $key): bool
    {
        return isset($this->nodes[$key]);
    }

    public function getNode(string $key): ?GraphNodeContract
    {
        return $this->nodes[$key] ?? null;
    }

    /**
     * @return array<string, GraphNodeContract>
     */
    public function getNodes(): array
    {
        return $this->nodes;
    }

    public function addEdge(string $fromNode, string $toNode, ?callable $condition = null, int $priority = 10): self
    {
        $rule = new EdgeRule($fromNode, $toNode, $condition, $priority);
        $this->edges[$fromNode][] = $rule;

        // Sort edges by priority descending
        usort($this->edges[$fromNode], fn (EdgeRule $a, EdgeRule $b) => $b->priority <=> $a->priority);

        return $this;
    }

    /**
     * @return array<string, array<int, EdgeRule>>|array<int, EdgeRule>
     */
    public function getEdges(?string $fromNode = null): array
    {
        if ($fromNode !== null) {
            return $this->edges[$fromNode] ?? [];
        }

        return $this->edges;
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

    public function getCheckpointer(): ?CheckpointerContract
    {
        return $this->checkpointer;
    }

    /**
     * Executes the graph from the current state until a terminal node is reached or bounds exceeded.
     */
    public function run(RunState $state): RunState
    {
        // If state is already terminal, return immediately
        if ($state->isCompleted() || $state->isFailed()) {
            return $state;
        }

        // Resume if paused
        if ($state->isPaused()) {
            $state = $state->withStatus(RunState::STATUS_RUNNING);
            $this->saveCheckpoint($state);
        }

        $currentNode = $state->currentNode;
        if ($currentNode === 'start' || ! isset($this->nodes[$currentNode])) {
            $currentNode = self::NODE_START;
        }

        while ($state->status === RunState::STATUS_RUNNING) {
            // Guardrail check: max steps
            if ($state->hasExceededSteps()) {
                $reason = "Maximum step count ({$state->guardrails['max_steps']}) exceeded.";
                $state = $state->fail($reason);
                $this->saveCheckpoint($state);
                $this->notifyStep($state, $currentNode, $reason);
                break;
            }

            if (! isset($this->nodes[$currentNode])) {
                $reason = "Unknown graph node: [{$currentNode}].";
                $state = $state->fail($reason);
                $this->saveCheckpoint($state);
                $this->notifyStep($state, $currentNode, $reason);
                break;
            }

            $node = $this->nodes[$currentNode];

            // Notify before step
            $this->notifyStep($state, $currentNode, "Executing node: {$currentNode}");

            try {
                // Execute node
                $state = $node->execute($state);
            } catch (\Throwable $e) {
                $reason = "Node [{$currentNode}] encountered unhandled exception: " . $e->getMessage();
                $state = $state->fail($reason);
                $this->saveCheckpoint($state);
                $this->notifyStep($state, $currentNode, $reason);
                break;
            }

            // Persist checkpoint after node execution
            $this->saveCheckpoint($state);

            // If node execution failed state (e.g. step exceeded inside node), break
            if ($state->isFailed() || $state->isCompleted()) {
                break;
            }

            // Determine next transition
            try {
                $nextNode = $this->resolveNextNode($currentNode, $state);
            } catch (\Throwable $e) {
                $state = $state->fail("Edge resolution error: " . $e->getMessage());
                $this->saveCheckpoint($state);
                $this->notifyStep($state, $currentNode, $e->getMessage());
                break;
            }

            if ($nextNode === self::TERMINAL_COMPLETED) {
                $state = $this->finalizeRun($state);
                $state = $state->withStatus(RunState::STATUS_COMPLETED);
                $this->saveCheckpoint($state);
                $this->notifyStep($state, $currentNode, 'Run completed successfully.');
                break;
            }

            if ($nextNode === self::TERMINAL_FAILED) {
                $reason = 'Run terminated with failure determination.';
                if ($state->hasExceededFixAttempts()) {
                    $reason = "Maximum fix attempts ({$state->guardrails['max_fix_attempts']}) reached.";
                } elseif (! $state->areTestsPassing()) {
                    $reason = 'Automated tests failed and could not be corrected.';
                }
                $state = $state->fail($reason);
                $this->saveCheckpoint($state);
                $this->notifyStep($state, $currentNode, $reason);
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
        $autoCommit = (bool) Config::get('lara-orcest.git.auto_commit', true);

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
