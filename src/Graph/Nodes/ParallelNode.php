<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Graph\Nodes;

use Closure;
use InvalidArgumentException;
use Yousef\LaraOrcest\Contracts\GraphNodeContract;
use Yousef\LaraOrcest\Contracts\TaskDefinition;
use Yousef\LaraOrcest\Graph\GraphEngine;
use Yousef\LaraOrcest\Graph\ParallelScheduler;
use Yousef\LaraOrcest\Graph\ScopeConflictDetector;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Support\Config;

class ParallelNode implements GraphNodeContract
{
    /**
     * @var array<string, GraphNodeContract|Closure>
     */
    protected array $branches = [];

    /**
     * @var array<string, TaskDefinition>
     */
    protected array $branchTasks = [];

    protected ParallelScheduler $scheduler;

    /**
     * @param  array<string|int, GraphNodeContract|GraphEngine|Closure>  $branches
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        protected string $key,
        array $branches = [],
        ?ParallelScheduler $scheduler = null,
        protected ?int $maxConcurrency = null,
        protected array $options = []
    ) {
        $this->scheduler = $scheduler ?: new ParallelScheduler();

        foreach ($branches as $branchKey => $branch) {
            $this->addBranch($branchKey, $branch);
        }
    }

    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * Adds a branch to this parallel node.
     *
     * @param  string|int  $branchKey
     * @param  GraphNodeContract|GraphEngine|Closure  $branch
     * @param  array<int, string>  $dependsOn
     * @param  array<int, string>  $scopePaths
     * @param  int  $priority
     * @param  bool  $isGitMutation
     */
    public function addBranch(
        string|int $branchKey,
        GraphNodeContract|GraphEngine|Closure $branch,
        array $dependsOn = [],
        array $scopePaths = [],
        int $priority = 1,
        bool $isGitMutation = false
    ): self {
        $normalizedKey = is_string($branchKey) ? $branchKey : (
            $branch instanceof GraphNodeContract ? $branch->getKey() : ('branch_' . $branchKey)
        );

        if ($branch instanceof GraphEngine) {
            $node = new SubGraphNode($normalizedKey, $branch);
        } elseif ($branch instanceof GraphNodeContract || $branch instanceof Closure) {
            $node = $branch;
        } else {
            throw new InvalidArgumentException('Branch must be an instance of GraphNodeContract, GraphEngine, or Closure');
        }

        $this->branches[$normalizedKey] = $node;

        // Auto-detect Git mutation if node is named git_agent
        if (! $isGitMutation && ($normalizedKey === 'git_agent' || ($node instanceof GraphNodeContract && $node->getKey() === 'git_agent'))) {
            $isGitMutation = true;
        }

        $this->branchTasks[$normalizedKey] = new TaskDefinition(
            id: $normalizedKey,
            title: $normalizedKey,
            description: "Parallel branch {$normalizedKey}",
            priority: $priority,
            dependencies: $dependsOn,
            metadata: [
                'is_git_mutation' => $isGitMutation,
                'exclusive' => $isGitMutation,
            ],
            scopePaths: $scopePaths
        );

        return $this;
    }

    /**
     * @return array<string, GraphNodeContract|Closure>
     */
    public function getBranches(): array
    {
        return $this->branches;
    }

    public function execute(RunState $state): RunState
    {
        $state = $state->withCurrentNode($this->key);
        $state = $state->incrementSteps();

        if ($state->hasExceededSteps()) {
            return $state->fail("Execution step limit exceeded at parallel node [{$this->key}].");
        }

        if (empty($this->branches)) {
            return $state;
        }

        $parallelEnabled = (bool) Config::get('lara-orcest.graph.parallel.enabled', true);
        $maxConcurrency = $this->maxConcurrency ?? (int) Config::get('lara-orcest.graph.parallel.max_concurrency', 4);

        if (! $parallelEnabled || $maxConcurrency <= 1) {
            // Sequential execution with isolated states merged one by one
            return $this->executeSequentially($state);
        }

        // Schedule parallel waves based on dependencies, scopes, and Git exclusivity
        $batches = $this->scheduler->scheduleBatches($this->branchTasks, $maxConcurrency);

        foreach ($batches as $wave) {
            $waveBranchStates = [];

            foreach ($wave as $task) {
                $branchKey = $task->id;
                $branchHandler = $this->branches[$branchKey];

                // Fork isolated branch state
                $forkedState = $state->forkBranch($branchKey, [
                    'parallel_node' => $this->key,
                    'scope_paths' => $task->getScopePaths(),
                ]);

                // Execute branch
                if ($branchHandler instanceof GraphNodeContract) {
                    $branchResult = $branchHandler->execute($forkedState);
                } else {
                    $branchResult = $branchHandler($forkedState);
                }

                $waveBranchStates[$branchKey] = $branchResult;
            }

            // Fan-In / Merge wave states
            $state = $state->mergeParallelStates($waveBranchStates, $this->options);

            // If merge resulted in failure, halt further waves
            if ($state->isFailed()) {
                break;
            }
        }

        return $state;
    }

    protected function executeSequentially(RunState $state): RunState
    {
        foreach ($this->branches as $branchKey => $branchHandler) {
            $task = $this->branchTasks[$branchKey] ?? null;
            $forkedState = $state->forkBranch($branchKey, [
                'parallel_node' => $this->key,
                'scope_paths' => $task ? $task->getScopePaths() : [],
            ]);

            if ($branchHandler instanceof GraphNodeContract) {
                $branchResult = $branchHandler->execute($forkedState);
            } else {
                $branchResult = $branchHandler($forkedState);
            }

            $state = $state->mergeParallelStates([$branchKey => $branchResult], $this->options);

            if ($state->isFailed()) {
                break;
            }
        }

        return $state;
    }
}
