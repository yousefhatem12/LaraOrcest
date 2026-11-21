<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Graph;

use InvalidArgumentException;
use RuntimeException;
use Yousef\LaraOrcest\Contracts\TaskDefinition;
use Yousef\LaraOrcest\Graph\Exceptions\CyclicDependencyException;
use Yousef\LaraOrcest\Support\Config;

class ParallelScheduler
{
    /**
     * Normalizes a collection of tasks into TaskDefinition instances keyed by ID.
     *
     * @param  array<int, TaskDefinition|array<string, mixed>>  $tasks
     * @return array<string, TaskDefinition>
     */
    public static function normalizeTasks(array $tasks): array
    {
        $normalized = [];

        foreach ($tasks as $key => $task) {
            if ($task instanceof TaskDefinition) {
                $item = $task;
            } elseif (is_array($task)) {
                $data = $task;
                if (! isset($data['id'])) {
                    $data['id'] = is_string($key) ? $key : ('task_' . $key);
                }
                $item = TaskDefinition::fromArray($data);
            } else {
                throw new InvalidArgumentException('Task must be a TaskDefinition instance or array');
            }

            $normalized[$item->id] = $item;
        }

        return $normalized;
    }

    /**
     * Detects cycles in task dependencies using Tarjan's or DFS topological traversal.
     *
     * @param  array<string, TaskDefinition>  $tasks
     * @return array<int, string> List of task IDs forming the cycle, or empty if acyclic.
     */
    public static function detectCycles(array $tasks): array
    {
        $visited = []; // 0 = unvisited, 1 = visiting, 2 = visited
        $path = [];
        $cycle = [];

        $dfs = function (string $id) use (&$dfs, $tasks, &$visited, &$path, &$cycle): bool {
            $visited[$id] = 1;
            $path[] = $id;

            $task = $tasks[$id] ?? null;
            if ($task !== null) {
                foreach ($task->getDependencies() as $depId) {
                    if (! isset($tasks[$depId])) {
                        // Dependency not in current task set (external or satisfied)
                        continue;
                    }

                    if (($visited[$depId] ?? 0) === 1) {
                        // Cycle detected
                        $cycleStartIndex = array_search($depId, $path, true);
                        $cycle = array_slice($path, (int) $cycleStartIndex);
                        $cycle[] = $depId;

                        return true;
                    }

                    if (($visited[$depId] ?? 0) === 0) {
                        if ($dfs($depId)) {
                            return true;
                        }
                    }
                }
            }

            array_pop($path);
            $visited[$id] = 2;

            return false;
        };

        foreach (array_keys($tasks) as $id) {
            if (($visited[$id] ?? 0) === 0) {
                if ($dfs($id)) {
                    return $cycle;
                }
            }
        }

        return [];
    }

    /**
     * Identifies runnable independent items whose dependencies are satisfied and whose scope_paths do not overlap.
     *
     * @param  array<int, TaskDefinition|array<string, mixed>>  $tasks
     * @param  array<int, string>  $completedIds
     * @param  array<int, string>  $currentlyRunningIds
     * @return array<int, TaskDefinition>
     */
    public function getRunnableTasks(
        array $tasks,
        array $completedIds = [],
        array $currentlyRunningIds = [],
        ?int $maxConcurrency = null
    ): array {
        $taskMap = self::normalizeTasks($tasks);
        $maxConcurrency = $maxConcurrency ?? (int) Config::get('lara-orcest.graph.parallel.max_concurrency', 4);

        if ($maxConcurrency < 1) {
            $maxConcurrency = 1;
        }

        $completedSet = array_flip($completedIds);
        $runningSet = array_flip($currentlyRunningIds);

        // Find candidate tasks whose dependencies are fully completed
        $candidates = [];
        $pendingCount = 0;

        foreach ($taskMap as $id => $task) {
            if (isset($completedSet[$id]) || $task->isCompleted()) {
                continue;
            }

            if (isset($runningSet[$id])) {
                continue;
            }

            $pendingCount++;

            // Check if all dependencies are satisfied
            $depsSatisfied = true;
            foreach ($task->getDependencies() as $dep) {
                if (! isset($completedSet[$dep])) {
                    $depsSatisfied = false;
                    break;
                }
            }

            if ($depsSatisfied) {
                $candidates[] = $task;
            }
        }

        // If tasks are pending but no candidates exist and nothing is running, check for deadlock/cycle
        if ($pendingCount > 0 && empty($candidates) && empty($currentlyRunningIds)) {
            $uncompletedTasks = array_filter(
                $taskMap,
                fn (TaskDefinition $t) => ! isset($completedSet[$t->id]) && ! $t->isCompleted()
            );

            $cycle = self::detectCycles($uncompletedTasks);
            if (! empty($cycle)) {
                throw new CyclicDependencyException(
                    'Cyclic dependency detected among tasks: ' . implode(' -> ', $cycle),
                    $cycle
                );
            }

            // Otherwise, check for missing unknown prerequisites
            $missingDeps = [];
            foreach ($uncompletedTasks as $t) {
                foreach ($t->getDependencies() as $d) {
                    if (! isset($taskMap[$d]) && ! isset($completedSet[$d])) {
                        $missingDeps[$t->id][] = $d;
                    }
                }
            }

            if (! empty($missingDeps)) {
                $msg = 'Tasks have unsatisfied dependencies not present in task graph: ';
                foreach ($missingDeps as $tId => $deps) {
                    $msg .= "[{$tId} needs " . implode(', ', $deps) . '] ';
                }
                throw new RuntimeException(trim($msg));
            }
        }

        if (empty($candidates)) {
            return [];
        }

        // Deterministic sorting: priority descending, then task ID ascending
        usort($candidates, function (TaskDefinition $a, TaskDefinition $b) {
            if ($a->priority !== $b->priority) {
                return $b->priority <=> $a->priority;
            }

            return strcmp($a->id, $b->id);
        });

        // Greedily select non-conflicting tasks for the batch
        $batch = [];
        $batchScopes = [];

        foreach ($candidates as $candidate) {
            // Check Git mutation serialization
            if ($candidate->isGitMutation()) {
                // Git mutations must run in complete isolation (serialized)
                if (empty($batch)) {
                    $batch[] = $candidate;
                    break; // Exclusively run this single Git task
                }
                // If batch already has non-git tasks, Git task waits for next batch
                continue;
            }

            // Check scope conflict against currently selected tasks in this batch
            $candScopes = $candidate->getScopePaths();
            if (! empty($candScopes) && ! empty($batchScopes)) {
                if (ScopeConflictDetector::hasConflict($candScopes, $batchScopes)) {
                    // Scope conflict! This candidate cannot run in parallel with the current wave.
                    continue;
                }
            }

            // Check candidate against running tasks scopes if any
            if (! empty($currentlyRunningIds) && ! empty($candScopes)) {
                $runningScopes = [];
                foreach ($currentlyRunningIds as $rId) {
                    if (isset($taskMap[$rId])) {
                        $runningScopes = array_merge($runningScopes, $taskMap[$rId]->getScopePaths());
                    }
                }
                if (ScopeConflictDetector::hasConflict($candScopes, $runningScopes)) {
                    continue;
                }
            }

            // Accept into batch
            $batch[] = $candidate;
            $batchScopes = array_merge($batchScopes, $candScopes);

            if (count($batch) >= $maxConcurrency) {
                break;
            }
        }

        return $batch;
    }

    /**
     * Schedules all tasks into an ordered sequence of parallel batches (waves).
     *
     * @param  array<int, TaskDefinition|array<string, mixed>>  $tasks
     * @return array<int, array<int, TaskDefinition>>
     */
    public function scheduleBatches(array $tasks, ?int $maxConcurrency = null): array
    {
        $taskMap = self::normalizeTasks($tasks);
        $completedIds = [];
        $batches = [];

        $totalTasks = count($taskMap);
        $iterations = 0;
        $maxIterations = $totalTasks * 2 + 10;

        while (count($completedIds) < $totalTasks && $iterations < $maxIterations) {
            $iterations++;

            $batch = $this->getRunnableTasks($taskMap, $completedIds, [], $maxConcurrency);
            if (empty($batch)) {
                break;
            }

            $batches[] = $batch;

            foreach ($batch as $task) {
                $completedIds[] = $task->id;
            }
        }

        if (count($completedIds) < $totalTasks) {
            throw new RuntimeException('Could not schedule all tasks due to unresolved dependency constraints or cycles.');
        }

        return $batches;
    }

    /**
     * Helper to test if two tasks can safely run concurrently.
     *
     * @param  TaskDefinition|array<string, mixed>  $taskA
     * @param  TaskDefinition|array<string, mixed>  $taskB
     */
    public static function canRunInParallel(TaskDefinition|array $taskA, TaskDefinition|array $taskB): bool
    {
        $a = $taskA instanceof TaskDefinition ? $taskA : TaskDefinition::fromArray($taskA);
        $b = $taskB instanceof TaskDefinition ? $taskB : TaskDefinition::fromArray($taskB);

        // Git mutations cannot run in parallel
        if ($a->isGitMutation() || $b->isGitMutation()) {
            return false;
        }

        // Dependency check
        if (in_array($b->id, $a->getDependencies(), true) || in_array($a->id, $b->getDependencies(), true)) {
            return false;
        }

        // Scope check
        if (ScopeConflictDetector::hasConflict($a->getScopePaths(), $b->getScopePaths())) {
            return false;
        }

        return true;
    }
}
