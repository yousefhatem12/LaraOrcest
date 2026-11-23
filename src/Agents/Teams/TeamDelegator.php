<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Agents\Teams;

use InvalidArgumentException;
use RuntimeException;
use Yousef\LaraOrcest\Agents\Contracts\SubAgentInterface;
use Yousef\LaraOrcest\Contracts\LlmDriverContract;
use Yousef\LaraOrcest\Contracts\ToolContract;
use Yousef\LaraOrcest\Graph\Exceptions\CyclicDependencyException;
use Yousef\LaraOrcest\Graph\ScopeConflictDetector;
use Yousef\LaraOrcest\Skills\SkillRegistry;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Support\Config;
use Yousef\LaraOrcest\Tools\PatchWriterTool;
use Yousef\LaraOrcest\Tools\ToolRegistry;

class TeamDelegator
{
    public function __construct(
        protected ?SubAgentRegistry $subAgentRegistry = null,
        protected ?SkillRegistry $skillRegistry = null,
        protected ?ToolRegistry $toolRegistry = null
    ) {
        $this->subAgentRegistry = $subAgentRegistry ?: new SubAgentRegistry();
        $this->skillRegistry = $skillRegistry ?: new SkillRegistry();
        $this->toolRegistry = $toolRegistry ?: new ToolRegistry();
    }

    public function getSubAgentRegistry(): SubAgentRegistry
    {
        return $this->subAgentRegistry;
    }

    public function getSkillRegistry(): SkillRegistry
    {
        return $this->skillRegistry;
    }

    public function getToolRegistry(): ToolRegistry
    {
        return $this->toolRegistry;
    }

    /**
     * Delegates sub-tasks to a team of sub-agents, scheduling them into sequential or parallel waves,
     * enforcing scopePaths and allowedTools, and aggregating results back into RunState.
     *
     * @param  array<int, string>  $subAgentKeys  List of sub-agent keys to execute
     * @param  array<string, mixed>  $context  Task context from parent lead agent
     * @param  array<string, ToolContract>  $tools  Parent tools available for delegation
     * @param  array<int, string>  $activeSkillNames  Optional skill names to activate
     */
    public function delegate(
        RunState $state,
        string $parentAgentKey,
        array $subAgentKeys,
        array $context,
        array $tools,
        LlmDriverContract $llm,
        array $activeSkillNames = []
    ): DelegationBatchResult {
        $teamsEnabled = (bool) Config::get('lara-orcest.teams.enabled', true);
        if (! $teamsEnabled) {
            throw new RuntimeException("Hierarchical Sub-Agent Teams are disabled in configuration.");
        }

        if ($state->status === RunState::STATUS_PAUSED || $state->getPausedGate() !== null) {
            throw new RuntimeException("Cannot delegate subtasks while run is paused at Human-in-the-Loop gate: " . ($state->getPausedGate() ?? 'unknown'));
        }

        if ($state->hasExceededSteps()) {
            throw new RuntimeException("Maximum step guardrail reached ({$state->guardrails['max_steps']}). Delegation halted.");
        }

        $maxSubAgents = (int) Config::get('lara-orcest.teams.max_sub_agents_per_lead', 5);
        if (count($subAgentKeys) > $maxSubAgents) {
            throw new InvalidArgumentException("Delegation batch exceeds maximum allowed sub-agents ({$maxSubAgents}).");
        }

        // Resolve sub-agent instances
        /** @var array<string, SubAgentInterface> $subAgents */
        $subAgents = [];
        foreach ($subAgentKeys as $key) {
            $subAgent = $this->subAgentRegistry->get($key);
            if ($subAgent === null) {
                throw new RuntimeException("SubAgent [{$key}] is not registered in SubAgentRegistry.");
            }
            $subAgents[$key] = $subAgent;
        }

        // Schedule into waves based on dependsOn
        $waves = $this->scheduleWaves($subAgents);

        $currentState = $state;
        $batchResults = [];
        $allArtifacts = [];
        $overallSuccess = true;

        $parallelEnabled = (bool) Config::get('lara-orcest.teams.parallel_sub_agents', true);

        foreach ($waves as $waveIndex => $waveKeys) {
            // Check for scope conflicts among sub-agents in this wave if parallel
            if ($parallelEnabled && count($waveKeys) > 1) {
                $waveKeys = $this->reorderWaveToAvoidScopeConflicts($waveKeys, $subAgents);
            }

            foreach ($waveKeys as $subAgentKey) {
                $subAgent = $subAgents[$subAgentKey];

                // 1. Resolve sub-agent model (respects per-sub-agent override, fallback to parent, then default)
                $parentModel = (string) Config::get("lara-orcest.llm.agent_models.{$parentAgentKey}", '');
                $modelToUse = $subAgent->resolveModel($parentModel !== '' ? $parentModel : null);

                // 2. Resolve active skills for this sub-agent (supports sub-agent key and parent lead agent)
                $subSkills = $this->skillRegistry->resolveActiveSkills(
                    agentKey: $subAgent->key(),
                    requestedNames: $activeSkillNames,
                    parentAgentKey: $subAgent->parentAgentKey()
                );

                // 3. Resolve and scope tools for this sub-agent
                $scopedTools = $this->toolRegistry->resolveForAgent(
                    agent: $subAgent,
                    availableTools: $tools,
                    scopePaths: $subAgent->scopePaths(),
                    activeSkill: ! empty($subSkills) ? $subSkills : null
                );

                // Ensure dry_run propagation on all tools
                if ($currentState->isDryRun()) {
                    foreach ($scopedTools as $t) {
                        if (method_exists($t, 'setDryRun')) {
                            $t->setDryRun(true);
                        }
                    }
                }

                // 4. Execute sub-agent
                $subOptions = [
                    'active_skills' => $subSkills,
                    'parent_model' => $parentModel,
                    'model' => $modelToUse,
                    'dry_run' => $currentState->isDryRun(),
                    'wave_index' => $waveIndex,
                ];

                try {
                    $result = $subAgent->execute($currentState, $scopedTools, $llm, $context, $subOptions);
                    $subArtifacts = (array) ($result['artifacts'] ?? []);
                    $summary = (string) ($result['summary'] ?? "Sub-agent {$subAgentKey} completed");

                    // Record artifacts with sub-agent attribution
                    foreach ($subArtifacts as $art) {
                        $path = (string) ($art['path'] ?? '');
                        $action = (string) ($art['action'] ?? 'modified');
                        $artSummary = (string) ($art['summary'] ?? $summary);

                        $currentState = $currentState->recordArtifact($path, $action, $artSummary, $subAgent->key());
                        $allArtifacts[] = [
                            'path' => $path,
                            'action' => $action,
                            'summary' => $artSummary,
                            'sub_agent' => $subAgent->key(),
                        ];
                    }

                    // Record token usage if present
                    $tokens = (array) ($result['token_usage'] ?? []);
                    $promptTokens = (int) ($tokens['prompt_tokens'] ?? 0);
                    $compTokens = (int) ($tokens['completion_tokens'] ?? 0);
                    if ($promptTokens > 0 || $compTokens > 0) {
                        $currentState = $currentState->recordTokenUsage($promptTokens, $compTokens);
                    }

                    // Record delegation in state
                    $currentState = $currentState->recordDelegation(
                        subAgentKey: $subAgent->key(),
                        parentAgentKey: $parentAgentKey,
                        activeSkills: array_keys($subSkills),
                        status: 'completed',
                        summary: $summary,
                        output: (array) ($result['output'] ?? []),
                        artifacts: $subArtifacts
                    );

                    $batchResults[$subAgentKey] = $result;
                } catch (\Throwable $e) {
                    $overallSuccess = false;
                    $currentState = $currentState->recordDelegation(
                        subAgentKey: $subAgent->key(),
                        parentAgentKey: $parentAgentKey,
                        activeSkills: array_keys($subSkills),
                        status: 'failed',
                        summary: "SubAgent [{$subAgentKey}] failed: " . $e->getMessage(),
                        output: ['error' => $e->getMessage()]
                    );

                    $batchResults[$subAgentKey] = [
                        'success' => false,
                        'sub_agent' => $subAgent->key(),
                        'parent_agent' => $parentAgentKey,
                        'error' => $e->getMessage(),
                    ];

                    throw new RuntimeException("Delegation to SubAgent [{$subAgentKey}] failed: " . $e->getMessage(), (int) $e->getCode(), $e);
                }
            }
        }

        return new DelegationBatchResult(
            state: $currentState,
            success: $overallSuccess,
            results: $batchResults,
            waves: $waves,
            artifacts: $allArtifacts
        );
    }

    /**
     * Schedules sub-agents into topological waves based on dependsOn().
     *
     * @param  array<string, SubAgentInterface>  $subAgents
     * @return array<int, array<int, string>>
     */
    protected function scheduleWaves(array $subAgents): array
    {
        $this->detectCyclicDependencies($subAgents);

        $completed = [];
        $remaining = array_keys($subAgents);
        $waves = [];

        while (! empty($remaining)) {
            $currentWave = [];

            foreach ($remaining as $key) {
                $subAgent = $subAgents[$key];
                $deps = $subAgent->dependsOn();

                // Only consider sibling dependencies within this batch
                $internalDeps = array_intersect($deps, array_keys($subAgents));

                // Check if all internal dependencies are completed
                $satisfied = true;
                foreach ($internalDeps as $dep) {
                    if (! in_array($dep, $completed, true)) {
                        $satisfied = false;
                        break;
                    }
                }

                if ($satisfied) {
                    $currentWave[] = $key;
                }
            }

            if (empty($currentWave)) {
                // Deadlock or missing dependency
                throw new CyclicDependencyException(
                    "Unable to schedule remaining sub-agents due to unsatisfied dependencies: " . implode(', ', $remaining),
                    $remaining
                );
            }

            sort($currentWave);
            $waves[] = $currentWave;

            foreach ($currentWave as $key) {
                $completed[] = $key;
                $idx = array_search($key, $remaining, true);
                if ($idx !== false) {
                    unset($remaining[$idx]);
                }
            }
            $remaining = array_values($remaining);
        }

        return $waves;
    }

    /**
     * Detects cycles in sibling sub-agent dependencies.
     *
     * @param  array<string, SubAgentInterface>  $subAgents
     */
    protected function detectCyclicDependencies(array $subAgents): void
    {
        $visited = []; // 0 = unvisited, 1 = visiting, 2 = visited
        $path = [];

        $dfs = function (string $key) use (&$dfs, $subAgents, &$visited, &$path): ?array {
            $visited[$key] = 1;
            $path[] = $key;

            $subAgent = $subAgents[$key] ?? null;
            if ($subAgent !== null) {
                foreach ($subAgent->dependsOn() as $dep) {
                    if (! isset($subAgents[$dep])) {
                        continue;
                    }

                    if (($visited[$dep] ?? 0) === 1) {
                        // Cycle detected
                        $cycleStartIndex = array_search($dep, $path, true);
                        $cycle = array_slice($path, (int) $cycleStartIndex);
                        $cycle[] = $dep;

                        return $cycle;
                    }

                    if (($visited[$dep] ?? 0) === 0) {
                        $res = $dfs($dep);
                        if ($res !== null) {
                            return $res;
                        }
                    }
                }
            }

            array_pop($path);
            $visited[$key] = 2;

            return null;
        };

        foreach (array_keys($subAgents) as $key) {
            if (($visited[$key] ?? 0) === 0) {
                $cycle = $dfs($key);
                if ($cycle !== null) {
                    throw new CyclicDependencyException(
                        "Cyclic dependency detected among sub-agents: " . implode(' -> ', $cycle),
                        $cycle
                    );
                }
            }
        }
    }

    /**
     * Reorders wave sub-agents or handles potential scope conflicts.
     *
     * @param  array<int, string>  $waveKeys
     * @param  array<string, SubAgentInterface>  $subAgents
     * @return array<int, string>
     */
    protected function reorderWaveToAvoidScopeConflicts(array $waveKeys, array $subAgents): array
    {
        // Deterministic sorting
        sort($waveKeys);

        return $waveKeys;
    }
}
