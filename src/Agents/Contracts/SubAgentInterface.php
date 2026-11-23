<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Agents\Contracts;

use Yousef\LaraOrcest\Contracts\LlmDriverContract;
use Yousef\LaraOrcest\Contracts\ToolContract;
use Yousef\LaraOrcest\State\RunState;

interface SubAgentInterface
{
    /**
     * Unique identifier for this sub-agent (e.g. 'migration_designer').
     */
    public function key(): string;

    /**
     * Identifier of the parent lead agent (e.g. 'database_agent').
     */
    public function parentAgentKey(): string;

    /**
     * Human-readable role title for this sub-agent.
     */
    public function role(): string;

    /**
     * Primary mission/goal of the sub-agent.
     */
    public function goal(): string;

    /**
     * Behavioral backstory and persona of the sub-agent.
     */
    public function backstory(): string;

    /**
     * Strict directory and file path allow-list for this sub-agent (e.g. ['database/migrations/**', 'database/seeders/**']).
     *
     * @return array<int, string>
     */
    public function scopePaths(): array;

    /**
     * Least-privilege list of allowed tool names or classes for this sub-agent.
     *
     * @return array<int, string>
     */
    public function allowedTools(): array;

    /**
     * Sibling sub-agent keys that this sub-agent depends on before it can run.
     *
     * @return array<int, string>
     */
    public function dependsOn(): array;

    /**
     * Executes the sub-agent's task with scoped tools, active skills, and state context.
     *
     * @param  array<string, ToolContract>  $tools  Scoped and authorized tools for this sub-agent
     * @param  array<string, mixed>  $context  Task input, prompt, or parameters passed from parent lead
     * @param  array<string, mixed>  $options  Execution options (e.g. active_skills, dry_run, model)
     * @return array<string, mixed> Structured result containing output data, summary, and artifacts
     */
    public function execute(RunState $state, array $tools, LlmDriverContract $llm, array $context = [], array $options = []): array;
}
