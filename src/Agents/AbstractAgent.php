<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Agents;

use RuntimeException;
use Yousef\LaraOrcest\Contracts\AgentContract;
use Yousef\LaraOrcest\Contracts\LlmDriverContract;
use Yousef\LaraOrcest\Contracts\ToolContract;
use Yousef\LaraOrcest\Skills\Skill;
use Yousef\LaraOrcest\Skills\SkillRegistry;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Support\Config;
use Yousef\LaraOrcest\Tools\ToolRegistry;

abstract class AbstractAgent implements AgentContract
{
    /**
     * Builds the system prompt for this agent.
     */
    protected function buildSystemPrompt(?RunState $state = null): string
    {
        $parts = [
            "ROLE: {$this->getRole()}",
            "GOAL: {$this->getGoal()}",
            "BACKSTORY: {$this->getBackstory()}",
            "CONSTRAINTS & RULES:\n" .
            "- You are executing inside an idiomatic Laravel project.\n" .
            "- Output must conform strictly to the required JSON schema.\n" .
            "- Do not include markdown or explanations outside the JSON object.\n" .
            "- Respect strict project boundaries and follow Laravel security standards.\n" .
            "- Never access or request secret values (e.g. .env contents).",
        ];

        if ($state !== null) {
            $skillsSummary = $this->buildSkillsPromptSection($state, includeFullInstructions: false);
            if (! empty($skillsSummary)) {
                $parts[] = $skillsSummary;
            }

            $fileMap = $this->buildAllowedFileMapSection($state);
            if (! empty($fileMap)) {
                $parts[] = $fileMap;
            }

            // If explicit skills were requested in state metadata, inject their full instructions
            $activeSkillNames = (array) ($state->metadata['active_skills'] ?? []);
            if (! empty($activeSkillNames)) {
                $fullInstructions = $this->buildSkillsPromptSection($state, includeFullInstructions: true);
                if (! empty($fullInstructions)) {
                    $parts[] = $fullInstructions;
                }
            }
        }

        return implode("\n\n", $parts);
    }

    /**
     * Build the user message based on current state.
     */
    abstract protected function buildUserPrompt(RunState $state): string;

    /**
     * Define the expected JSON Schema for LLM output.
     *
     * @return array<string, mixed>
     */
    abstract protected function getOutputSchema(): array;

    /**
     * Apply the validated structured output to RunState and/or run authorized tools.
     *
     * @param  array<string, mixed>  $output
     * @param  array<string, ToolContract>  $tools
     */
    abstract protected function applyOutput(RunState $state, array $output, array $tools): RunState;

    /**
     * Executes the agent's turn.
     *
     * @param  array<string, ToolContract>  $tools
     */
    public function execute(RunState $state, array $tools, LlmDriverContract $llm): RunState
    {
        // Filter and scope tools to only those allowed for this agent and its active skills
        $authorizedTools = $this->authorizeTools($tools, $state);

        $userPrompt = $this->buildUserPrompt($state);

        // Inject compact project memory if enabled
        $memoryContext = $this->resolveProjectMemoryContext($state);
        if ($memoryContext !== null && $memoryContext !== '') {
            $userPrompt = $memoryContext . "\n\n" . $userPrompt;
        }

        $messages = [
            ['role' => 'system', 'content' => $this->buildSystemPrompt($state)],
            ['role' => 'user', 'content' => $userPrompt],
        ];

        // Observability context and per-agent model routing
        $options = [
            'run_id' => $state->runId,
            'agent_key' => $this->getName(),
            'node_id' => $state->currentNode,
        ];

        $agentModel = $this->resolveModel();
        if ($agentModel !== null && $agentModel !== '') {
            $options['model'] = $agentModel;
        }

        // Call LLM with structured output schema
        try {
            $response = $llm->chatStructured($messages, $this->getOutputSchema(), $options);
        } catch (\Throwable $e) {
            throw new RuntimeException("Agent [{$this->getName()}] LLM execution failed: " . $e->getMessage(), (int) $e->getCode(), $e);
        }

        $data = $response['data'] ?? null;
        if (! is_array($data)) {
            throw new RuntimeException("Agent [{$this->getName()}] received malformed or empty structured output from LLM.");
        }

        // Update token usage in state safely without breaking if usage is absent or mocked
        $usage = $response['usage'] ?? [];
        $promptTokens = (int) ($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0);
        $completionTokens = (int) ($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0);

        $state = $state->recordTokenUsage(max(0, $promptTokens), max(0, $completionTokens));

        // Apply structured output and authorized tool effects
        return $this->applyOutput($state, $data, $authorizedTools);
    }

    /**
     * Resolves an agent-specific model override if configured.
     */
    protected function resolveModel(): ?string
    {
        $agentKey = $this->getName();
        $configured = Config::get("lara-orcest.llm.agent_models.{$agentKey}");

        if (! empty($configured)) {
            return (string) $configured;
        }

        return null;
    }

    /**
     * Filter provided tools by the agent's allow-list and enforce skill explore/modify/ignore paths.
     *
     * @param  array<string, ToolContract>  $tools
     * @return array<string, ToolContract>
     */
    protected function authorizeTools(array $tools, ?RunState $state = null): array
    {
        $allowed = $this->getAllowedTools();
        $authorized = [];

        foreach ($tools as $key => $tool) {
            if (in_array($tool->getName(), $allowed, true) || in_array($key, $allowed, true)) {
                $authorized[$tool->getName()] = $tool;
            }
        }

        if (empty($authorized)) {
            return $authorized;
        }

        try {
            $registry = $this->getSkillRegistry();
            if ($registry->isEnabled()) {
                $requested = $state !== null
                    ? (array) ($state->metadata['active_skills'] ?? [])
                    : [];
                if (empty($requested)) {
                    $requested = $this->getSkills();
                }

                $activeSkills = $registry->resolveActiveSkills($this->getName(), $requested);
                if (! empty($activeSkills)) {
                    $toolRegistry = new ToolRegistry();

                    return $toolRegistry->resolveForAgent(
                        agent: $this,
                        availableTools: $authorized,
                        scopePaths: [],
                        activeSkill: $activeSkills
                    );
                }
            }
        } catch (\Throwable) {
            // Fall back gracefully to direct allow-list authorization
        }

        return $authorized;
    }

    /**
     * Retrieve an authorized tool or throw an exception if not permitted.
     *
     * @param  array<string, ToolContract>  $tools
     */
    protected function getTool(string $name, array $tools): ToolContract
    {
        if (! isset($tools[$name])) {
            throw new RuntimeException("Agent [{$this->getName()}] attempted to use unauthorized or missing tool [{$name}]");
        }

        return $tools[$name];
    }

    /**
     * Resolves compact project memory context for this agent turn if enabled.
     */
    protected function resolveProjectMemoryContext(RunState $state): ?string
    {
        if (! $state->isMemoryEnabled()) {
            return null;
        }

        try {
            $container = \Illuminate\Container\Container::getInstance();
            /** @var \Yousef\LaraOrcest\Contracts\ProjectMemoryContract $store */
            $store = ($container !== null && $container->bound(\Yousef\LaraOrcest\Contracts\ProjectMemoryContract::class))
                ? $container->make(\Yousef\LaraOrcest\Contracts\ProjectMemoryContract::class)
                : new \Yousef\LaraOrcest\Memory\ProjectMemoryStore();

            if (! $store->isEnabled()) {
                return null;
            }

            $context = $store->recallForContext($this->getName(), $state->prompt);

            return $context !== '' ? $context : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Resolves the SkillRegistry instance from container or fallback instantiation.
     */
    public function getSkillRegistry(): SkillRegistry
    {
        try {
            $container = \Illuminate\Container\Container::getInstance();
            if ($container !== null && $container->bound(SkillRegistry::class)) {
                return $container->make(SkillRegistry::class);
            }
        } catch (\Throwable) {
            // fallback
        }

        return new SkillRegistry();
    }

    /**
     * Default skills declared directly on this lead agent class.
     *
     * @return array<int, string>
     */
    protected function getDefaultSkills(): array
    {
        return [];
    }

    /**
     * Dedicated skills assigned to this lead agent.
     *
     * @return array<int, string>
     */
    public function getSkills(): array
    {
        $configSkills = (array) Config::get("lara-orcest.skills.agent_skills.{$this->getName()}", []);
        if (! empty($configSkills)) {
            return array_values(array_filter($configSkills, 'is_string'));
        }

        $defaults = $this->getDefaultSkills();
        if (! empty($defaults)) {
            return $defaults;
        }

        try {
            $matched = array_keys($this->getSkillRegistry()->getForAgent($this->getName()));
            if (! empty($matched)) {
                return $matched;
            }
        } catch (\Throwable) {
            // fallback gracefully
        }

        return [];
    }

    /**
     * Resolves active skills applicable to this agent.
     *
     * @return array<string, Skill>
     */
    public function resolveActiveSkills(RunState $state): array
    {
        $registry = $this->getSkillRegistry();
        $requested = (array) ($state->metadata['active_skills'] ?? []);
        if (empty($requested)) {
            $requested = $this->getSkills();
        }

        return $registry->resolveActiveSkills($this->getName(), $requested);
    }

    /**
     * Builds skills prompt section (summaries or full instructions) for progressive disclosure.
     */
    public function buildSkillsPromptSection(RunState $state, bool $includeFullInstructions = false): ?string
    {
        $registry = $this->getSkillRegistry();
        if (! $registry->isEnabled()) {
            return null;
        }

        $activeSkillNames = (array) ($state->metadata['active_skills'] ?? []);

        if ($includeFullInstructions) {
            $instructions = $registry->buildInstructions($this->getName(), $activeSkillNames);

            return $instructions !== '' ? $instructions : null;
        }

        $summary = $registry->buildSummaries($this->getName(), $activeSkillNames);

        return $summary !== '' ? $summary : null;
    }

    /**
     * Builds compact Allowed File Map (Explore Only vs Can Modify vs Ignore) for token-saving boundaries.
     */
    public function buildAllowedFileMapSection(RunState $state): ?string
    {
        $registry = $this->getSkillRegistry();
        if (! $registry->isEnabled()) {
            return null;
        }

        $requested = (array) ($state->metadata['active_skills'] ?? []);
        if (empty($requested)) {
            $requested = $this->getSkills();
        }

        $map = $registry->buildAllowedFileMap($this->getName(), $requested);

        return $map !== '' ? $map : null;
    }

    /**
     * Resolves the TeamDelegator instance from container or fallback instantiation.
     */
    public function getTeamDelegator(): \Yousef\LaraOrcest\Agents\Teams\TeamDelegator
    {
        try {
            $container = \Illuminate\Container\Container::getInstance();
            if ($container !== null && $container->bound(\Yousef\LaraOrcest\Agents\Teams\TeamDelegator::class)) {
                return $container->make(\Yousef\LaraOrcest\Agents\Teams\TeamDelegator::class);
            }
        } catch (\Throwable) {
            // fallback
        }

        return new \Yousef\LaraOrcest\Agents\Teams\TeamDelegator(
            subAgentRegistry: \Yousef\LaraOrcest\Agents\Teams\SubAgentRegistry::createDefault(),
            skillRegistry: $this->getSkillRegistry()
        );
    }

    /**
     * Delegates sub-tasks to registered sub-agents for this lead agent.
     *
     * @param  array<int, string>  $subAgentKeys
     * @param  array<string, mixed>  $context
     * @param  array<string, ToolContract>  $tools
     * @param  array<int, string>  $activeSkillNames
     */
    public function delegateSubTasks(
        RunState $state,
        array $subAgentKeys,
        array $context,
        array $tools,
        LlmDriverContract $llm,
        array $activeSkillNames = []
    ): \Yousef\LaraOrcest\Agents\Teams\DelegationBatchResult {
        return $this->getTeamDelegator()->delegate(
            state: $state,
            parentAgentKey: $this->getName(),
            subAgentKeys: $subAgentKeys,
            context: $context,
            tools: $tools,
            llm: $llm,
            activeSkillNames: $activeSkillNames
        );
    }
}
