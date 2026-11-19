<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Agents;

use RuntimeException;
use Yousef\LaraOrcest\Contracts\AgentContract;
use Yousef\LaraOrcest\Contracts\LlmDriverContract;
use Yousef\LaraOrcest\Contracts\ToolContract;
use Yousef\LaraOrcest\State\RunState;

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

        // Call LLM with structured output schema
        $response = $llm->chatStructured($messages, $this->getOutputSchema());

        // Update token usage in state
        $usage = $response['usage'] ?? ['prompt_tokens' => 0, 'completion_tokens' => 0];
        $state = $state->recordTokenUsage(
            (int) ($usage['prompt_tokens'] ?? 0),
            (int) ($usage['completion_tokens'] ?? 0)
        );

        // Apply structured output and authorized tool effects
        return $this->applyOutput($state, $response['data'], $authorizedTools);
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
