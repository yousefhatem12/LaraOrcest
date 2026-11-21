<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Graph;

use Closure;
use RuntimeException;
use Yousef\LaraOrcest\Contracts\CheckpointerContract;
use Yousef\LaraOrcest\Contracts\GraphNodeContract;
use Yousef\LaraOrcest\Contracts\LlmDriverContract;
use Yousef\LaraOrcest\Contracts\TaskDefinition;
use Yousef\LaraOrcest\Contracts\ToolContract;
use Yousef\LaraOrcest\Graph\Nodes\ParallelNode;
use Yousef\LaraOrcest\Graph\Nodes\SubGraphNode;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Support\Config;
use Yousef\LaraOrcest\Tools\GitCommandTool;

class GraphEngine
{
    public const NODE_START = 'business_analyst';
    public const TERMINAL_COMPLETED = '__completed__';
    public const TERMINAL_FAILED = '__failed__';

    protected ?string $startNode = null;

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

    /**
     * Registers a parallel branch execution node in the graph.
     *
     * @param  array<string|int, GraphNodeContract|GraphEngine|Closure>  $branches
     * @param  array<string, mixed>  $options
     */
    public function addParallelNode(string $key, array $branches, ?int $maxConcurrency = null, array $options = []): self
    {
        $parallelNode = new ParallelNode($key, $branches, null, $maxConcurrency, $options);

        return $this->addNode($parallelNode);
    }

    /**
     * Executes a set of nodes or closures in parallel and merges their state updates deterministically.
     *
     * @param  array<string|int, GraphNodeContract|GraphEngine|Closure>  $nodes
     * @param  array<string, mixed>  $options
     */
    public function executeParallel(RunState $state, array $nodes, ?int $maxConcurrency = null, array $options = []): RunState
    {
        $key = 'parallel_' . bin2hex(random_bytes(4));
        $parallelNode = new ParallelNode($key, $nodes, null, $maxConcurrency, $options);

        $this->notifyStep($state, $key, 'Executing parallel branch group: ' . count($nodes) . ' branches');
        $updatedState = $parallelNode->execute($state);
        $this->saveCheckpoint($updatedState);

        return $updatedState;
    }

    /**
     * Executes tasks in parallel waves based on dependencies (depends_on) and resource scopes (scope_paths).
     *
     * @param  array<int, TaskDefinition|array<string, mixed>>  $tasks
     * @param  callable(TaskDefinition, RunState): RunState  $taskExecutor
     * @param  array<string, mixed>  $options
     */
    public function executeParallelTasks(
        RunState $state,
        array $tasks,
        callable $taskExecutor,
        ?int $maxConcurrency = null,
        array $options = []
    ): RunState {
        $scheduler = new ParallelScheduler();
        $taskMap = ParallelScheduler::normalizeTasks($tasks);
        $batches = $scheduler->scheduleBatches($taskMap, $maxConcurrency);

        foreach ($batches as $waveIndex => $wave) {
            $waveKey = "parallel_wave_{$waveIndex}";
            $this->notifyStep($state, $waveKey, "Executing parallel wave #{$waveIndex} with " . count($wave) . ' tasks');

            $waveBranchStates = [];
            foreach ($wave as $task) {
                $forkedState = $state->forkBranch($task->id, [
                    'wave_index' => $waveIndex,
                    'task_id' => $task->id,
                    'scope_paths' => $task->getScopePaths(),
                ]);

                $branchResult = $taskExecutor($task, $forkedState);
                $waveBranchStates[$task->id] = $branchResult;
            }

            // Fan-In / Merge wave states
            $state = $state->mergeParallelStates($waveBranchStates, $options);
            $this->saveCheckpoint($state);

            if ($state->isFailed()) {
                break;
            }
        }

        return $state;
    }

    /**
     * Executes a sub-graph cleanly, integrating checkpointing and step notifications.
     */
    public function runSubGraph(self $subGraph, RunState $state): RunState
    {
        if ($this->checkpointer !== null && $subGraph->getCheckpointer() === null) {
            $subGraph->setCheckpointer($this->checkpointer);
        }

        foreach ($this->stepListeners as $listener) {
            $subGraph->onStep($listener);
        }

        return $subGraph->run($state);
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

    public function setStartNode(?string $startNode): self
    {
        $this->startNode = $startNode;

        return $this;
    }

    public function getStartNode(): ?string
    {
        return $this->startNode;
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
        if ($currentNode === self::TERMINAL_COMPLETED) {
            $state = $this->finalizeRun($state);
            $state = $state->withStatus(RunState::STATUS_COMPLETED);
            $this->saveCheckpoint($state);
            $this->notifyStep($state, $currentNode, 'Run completed successfully.');

            return $state;
        }

        if ($currentNode === 'start' || ! isset($this->nodes[$currentNode])) {
            if ($this->startNode !== null && isset($this->nodes[$this->startNode])) {
                $currentNode = $this->startNode;
            } elseif (isset($this->nodes[self::NODE_START])) {
                $currentNode = self::NODE_START;
            } else {
                $currentNode = array_key_first($this->nodes) ?? self::NODE_START;
            }
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

            // If node execution failed, paused, or completed state, break
            if ($state->isFailed() || $state->isCompleted() || $state->isPaused()) {
                break;
            }

            // Check if human-in-the-loop gate applies after this node execution
            $gateName = null;
            if ($currentNode === 'architect' && $state->shouldPauseAtGate('after_plan')) {
                $gateName = 'after_plan';
            } elseif ($state->shouldPauseAtGate("after_node:{$currentNode}")) {
                $gateName = "after_node:{$currentNode}";
            }

            if ($gateName !== null) {
                try {
                    $nextNode = $this->resolveNextNode($currentNode, $state);
                } catch (\Throwable) {
                    $nextNode = self::TERMINAL_COMPLETED;
                }

                $context = [
                    'gate' => $gateName,
                    'node' => $currentNode,
                    'next_node' => $nextNode,
                    'summary' => "Completed node [{$currentNode}]. Paused for human review before proceeding to [{$nextNode}].",
                    'timestamp' => date('c'),
                ];

                if ($gateName === 'after_plan') {
                    $context['backlog_count'] = count($state->backlog);
                    $context['technical_plan'] = [
                        'migrations' => array_keys($state->technicalPlan['migrations'] ?? []),
                        'models' => array_keys($state->technicalPlan['models'] ?? []),
                        'controllers' => array_keys($state->technicalPlan['controllers'] ?? []),
                        'execution_steps' => count($state->technicalPlan['execution_steps'] ?? []),
                    ];
                    $context['summary'] = 'Technical plan and backlog generated. Paused for human plan approval.';
                }

                $state = $state->withCurrentNode($nextNode)->pauseAtGate($gateName, $context);
                $this->saveCheckpoint($state);
                $this->notifyStep($state, $gateName, "Run paused for human review at gate [{$gateName}].");
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
                // Check if human-in-the-loop gate applies before final milestone commit
                if ($state->shouldPauseAtGate('before_commit')) {
                    $context = [
                        'gate' => 'before_commit',
                        'node' => $currentNode,
                        'artifacts_count' => count($state->artifacts),
                        'artifacts' => array_column($state->artifacts, 'path'),
                        'tests_passed' => $state->areTestsPassing(),
                        'summary' => 'Automated tests passed and review approved. Paused for human approval before final Git commit.',
                        'timestamp' => date('c'),
                    ];

                    $state = $state->withCurrentNode(self::TERMINAL_COMPLETED)->pauseAtGate('before_commit', $context);
                    $this->saveCheckpoint($state);
                    $this->notifyStep($state, 'before_commit', 'Run paused for human approval at gate [before_commit].');
                    break;
                }

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

        // Consolidate project memory if enabled and not dry-run
        if ($state->isMemoryEnabled() && ! $state->isDryRun()) {
            try {
                $container = \Illuminate\Container\Container::getInstance();
                /** @var \Yousef\LaraOrcest\Contracts\ProjectMemoryContract $memoryStore */
                $memoryStore = ($container !== null && $container->bound(\Yousef\LaraOrcest\Contracts\ProjectMemoryContract::class))
                    ? $container->make(\Yousef\LaraOrcest\Contracts\ProjectMemoryContract::class)
                    : new \Yousef\LaraOrcest\Memory\ProjectMemoryStore();

                $memoryStore->consolidate($state);
            } catch (\Throwable) {
                // Memory consolidation errors should never prevent run finalization
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
