<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Contracts;

use Yousef\LaraOrcest\State\RunState;

interface AgentContract
{
    /**
     * Unique identifier for the agent (e.g. 'business_analyst').
     */
    public function getName(): string;

    /**
     * Human-readable role title (e.g. 'Laravel Domain & Business Analyst').
     */
    public function getRole(): string;

    /**
     * Core mission/goal of the agent.
     */
    public function getGoal(): string;

    /**
     * Backstory and behavioral persona of the agent.
     */
    public function getBackstory(): string;

    /**
     * List of allowed tool class names or identifiers.
     *
     * @return array<int, string>
     */
    public function getAllowedTools(): array;

    /**
     * Target key in RunState that this agent updates.
     */
    public function getStateOutputKey(): string;

    /**
     * Executes the agent's work against the given state, tools, and LLM driver.
     *
     * @param  array<string, ToolContract>  $tools
     */
    public function execute(RunState $state, array $tools, LlmDriverContract $llm): RunState;
}
