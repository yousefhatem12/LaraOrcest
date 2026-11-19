<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Agents;

use RuntimeException;
use Yousef\LaraOrcest\Contracts\AgentContract;
use Yousef\LaraOrcest\Contracts\LlmDriverContract;
use Yousef\LaraOrcest\Contracts\ToolContract;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Support\Config;

abstract class AbstractAgent implements AgentContract
{
    /**
     * Builds the system prompt for this agent.
     */
    protected function buildSystemPrompt(): string
    {
        return implode("\n\n", [
            "ROLE: {$this->getRole()}",
            "GOAL: {$this->getGoal()}",
            "BACKSTORY: {$this->getBackstory()}",
            "CONSTRAINTS & RULES:\n" .
            "- You are executing inside an idiomatic Laravel project.\n" .
            "- Output must conform strictly to the required JSON schema.\n" .
            "- Do not include markdown or explanations outside the JSON object.\n" .
            "- Respect strict project boundaries and follow Laravel security standards.\n" .
            "- Never access or request secret values (e.g. .env contents).",
        ]);
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
        // Filter tools to only those allowed for this agent
        $authorizedTools = $this->authorizeTools($tools);

        $messages = [
            ['role' => 'system', 'content' => $this->buildSystemPrompt()],
            ['role' => 'user', 'content' => $this->buildUserPrompt($state)],
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
     * Filter provided tools by the agent's allow-list.
     *
     * @param  array<string, ToolContract>  $tools
     * @return array<string, ToolContract>
     */
    protected function authorizeTools(array $tools): array
    {
        $allowed = $this->getAllowedTools();
        $authorized = [];

        foreach ($tools as $key => $tool) {
            if (in_array($tool->getName(), $allowed, true) || in_array($key, $allowed, true)) {
                $authorized[$tool->getName()] = $tool;
            }
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
}
