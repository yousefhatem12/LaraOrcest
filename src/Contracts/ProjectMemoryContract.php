<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Contracts;

use Yousef\LaraOrcest\State\RunState;

interface ProjectMemoryContract
{
    /**
     * Recalls a compact, agent- and task-scoped slice of project memory.
     *
     * @param  array<string, mixed>  $options
     */
    public function recallForContext(string $agentKey, string $prompt, array $options = []): string;

    /**
     * Consolidates completed run state into the persistent project memory store.
     */
    public function consolidate(RunState $state): void;

    /**
     * Loads the raw memory data array.
     *
     * @return array<string, mixed>
     */
    public function load(): array;

    /**
     * Persists memory data array to disk.
     *
     * @param  array<string, mixed>  $data
     */
    public function save(array $data): void;

    /**
     * Returns the codebase index section.
     *
     * @return array<string, mixed>
     */
    public function getIndex(): array;

    /**
     * Returns the conventions section.
     *
     * @return array<int, array<string, string>|string>
     */
    public function getConventions(): array;

    /**
     * Returns the decisions section.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getDecisions(): array;

    /**
     * Returns the pitfalls section.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getPitfalls(): array;

    /**
     * Manually records an architectural or technical decision.
     *
     * @param  array<string, mixed>  $context
     */
    public function recordDecision(string $title, string $summary, array $context = []): self;

    /**
     * Manually records a resolved pitfall.
     *
     * @param  array<string, mixed>  $context
     */
    public function recordPitfall(string $issue, string $rootCause, string $resolution, array $context = []): self;

    /**
     * Manually records a project convention.
     */
    public function recordConvention(string $rule, string $category = 'general'): self;

    /**
     * Scans the project directory structure to populate or refresh codebase_index.
     */
    public function scanCodebase(?string $rootPath = null): self;

    /**
     * Clears all entries in the memory store.
     */
    public function clear(): void;

    /**
     * Checks if project memory is globally enabled.
     */
    public function isEnabled(): bool;
}
