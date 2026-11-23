<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Agents\Teams;

use RuntimeException;
use Yousef\LaraOrcest\Agents\Contracts\SubAgentInterface;
use Yousef\LaraOrcest\Contracts\LlmDriverContract;
use Yousef\LaraOrcest\Contracts\ToolContract;
use Yousef\LaraOrcest\Skills\Skill;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Support\Config;

abstract class AbstractSubAgent implements SubAgentInterface
{
    /**
     * @return array<int, string>
     */
    public function scopePaths(): array
    {
        return [];
    }

    /**
     * @return array<int, string>
     */
    public function allowedTools(): array
    {
        return ['file_reader', 'patch_writer'];
    }

    /**
     * @return array<int, string>
     */
    public function dependsOn(): array
    {
        return [];
    }

    /**
     * Resolve model override for this sub-agent.
     * Order of precedence:
     * 1. Explicit sub-agent override in config (llm.agent_models.<sub_agent_key>)
     * 2. Parent model passed from parent lead agent
     * 3. Parent lead agent override in config (llm.agent_models.<parent_agent_key>)
     * 4. Null (falls back to default model)
     */
    public function resolveModel(?string $parentModel = null): ?string
    {
        $subKey = $this->key();
        $subConfig = Config::get("lara-orcest.llm.agent_models.{$subKey}");
        if (! empty($subConfig)) {
            return (string) $subConfig;
        }

        if (! empty($parentModel)) {
            return $parentModel;
        }

        $parentKey = $this->parentAgentKey();
        $parentConfig = Config::get("lara-orcest.llm.agent_models.{$parentKey}");
        if (! empty($parentConfig)) {
            return (string) $parentConfig;
        }

        $defaultModel = Config::get('lara-orcest.llm.default_model');
        if (! empty($defaultModel)) {
            return (string) $defaultModel;
        }

        return null;
    }

    /**
     * Builds the system prompt for this sub-agent, incorporating active skills and scope boundaries.
     *
     * @param  array<string, Skill>  $activeSkills
     */
    protected function buildSystemPrompt(array $activeSkills = []): string
    {
        $parts = [
            "ROLE: {$this->role()}",
            "PARENT AGENT: {$this->parentAgentKey()}",
            "GOAL: {$this->goal()}",
            "BACKSTORY: {$this->backstory()}",
        ];

        $scopes = $this->scopePaths();
        if (! empty($scopes)) {
            $parts[] = "ALLOWED SCOPE PATHS (Strict boundary - You may ONLY create or modify files matching these paths):\n" .
                implode("\n", array_map(fn ($p) => "- {$p}", $scopes));
        }

        $parts[] = "CONSTRAINTS & RULES:\n" .
            "- You are a specialized sub-agent executing inside an idiomatic Laravel project.\n" .
            "- You must strictly adhere to your assigned scope paths.\n" .
            "- Output must conform strictly to the required JSON schema without surrounding markdown.\n" .
            "- Respect strict project boundaries and follow Laravel security standards.\n" .
            "- Never access or request secret values (e.g. .env contents).";

        if (! empty($activeSkills)) {
            $skillSections = ["ASSIGNED SKILLS & DIRECTIVES:"];
            foreach ($activeSkills as $skill) {
                $skillSections[] = "### Skill: {$skill->name}\n{$skill->instructions}";
            }
            $parts[] = implode("\n\n", $skillSections);
        }

        return implode("\n\n", $parts);
    }

    /**
     * Build the user message based on state and delegated context.
     *
     * @param  array<string, mixed>  $context
     */
    abstract protected function buildUserPrompt(RunState $state, array $context = []): string;

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
     * @return array<string, mixed> Output summary, artifacts, and changes
     */
    abstract protected function applyOutput(RunState $state, array $output, array $tools): array;

    /**
     * Executes the sub-agent turn.
     *
     * @param  array<string, ToolContract>  $tools
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function execute(RunState $state, array $tools, LlmDriverContract $llm, array $context = [], array $options = []): array
    {
        $authorizedTools = $this->authorizeTools($tools);

        /** @var array<string, Skill> $activeSkills */
        $activeSkills = (array) ($options['active_skills'] ?? []);

        $systemPrompt = $this->buildSystemPrompt($activeSkills);
        $userPrompt = $this->buildUserPrompt($state, $context);

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ];

        $parentModel = isset($options['parent_model']) ? (string) $options['parent_model'] : null;
        $resolvedModel = $this->resolveModel($parentModel);

        $llmOptions = [
            'run_id' => $state->runId,
            'agent_key' => $this->key(),
            'parent_agent' => $this->parentAgentKey(),
            'node_id' => $state->currentNode,
        ];

        if ($resolvedModel !== null && $resolvedModel !== '') {
            $llmOptions['model'] = $resolvedModel;
        }

        try {
            $response = $llm->chatStructured($messages, $this->getOutputSchema(), $llmOptions);
        } catch (\Throwable $e) {
            throw new RuntimeException("SubAgent [{$this->key()}] LLM execution failed: " . $e->getMessage(), (int) $e->getCode(), $e);
        }

        $data = $response['data'] ?? null;
        if (! is_array($data)) {
            throw new RuntimeException("SubAgent [{$this->key()}] received malformed or empty structured output from LLM.");
        }

        $usage = $response['usage'] ?? [];
        $promptTokens = (int) ($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0);
        $completionTokens = (int) ($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0);

        // Apply structured output via scoped tools
        $applied = $this->applyOutput($state, $data, $authorizedTools);

        return [
            'success' => true,
            'sub_agent' => $this->key(),
            'parent_agent' => $this->parentAgentKey(),
            'output' => $applied['output'] ?? $data,
            'summary' => (string) ($applied['summary'] ?? "Sub-agent {$this->key()} executed successfully"),
            'artifacts' => (array) ($applied['artifacts'] ?? []),
            'token_usage' => [
                'prompt_tokens' => max(0, $promptTokens),
                'completion_tokens' => max(0, $completionTokens),
                'total_tokens' => max(0, $promptTokens + $completionTokens),
            ],
            'model_used' => $resolvedModel,
            'raw_response' => $response,
        ];
    }

    /**
     * Filter provided tools by the sub-agent's allowedTools allow-list.
     *
     * @param  array<string, ToolContract>  $tools
     * @return array<string, ToolContract>
     */
    protected function authorizeTools(array $tools): array
    {
        $allowed = $this->allowedTools();
        $authorized = [];

        foreach ($tools as $key => $tool) {
            if (in_array($tool->getName(), $allowed, true) || in_array($key, $allowed, true)) {
                $authorized[$tool->getName()] = $tool;
            }
        }

        return $authorized;
    }

    /**
     * Retrieve an authorized tool or throw exception if unauthorized.
     *
     * @param  array<string, ToolContract>  $tools
     */
    protected function getTool(string $name, array $tools): ToolContract
    {
        if (! isset($tools[$name])) {
            throw new RuntimeException("SubAgent [{$this->key()}] attempted to use unauthorized or missing tool [{$name}]");
        }

        return $tools[$name];
    }
}
