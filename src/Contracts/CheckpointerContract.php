<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Contracts;

use Yousef\LaraOrcest\State\RunState;

interface CheckpointerContract
{
    /**
     * Persist the run state safely.
     */
    public function save(RunState $state): void;

    /**
     * Load a saved run state by its run ID.
     */
    public function load(string $runId): ?RunState;

    /**
     * List recent execution run summaries.
     *
     * @return array<int, array{run_id: string, prompt: string, current_node: string, status: string, updated_at: string}>
     */
    public function list(int $limit = 20): array;

    /**
     * Check if a checkpoint exists for the given run ID.
     */
    public function exists(string $runId): bool;

    /**
     * Delete a saved checkpoint by its run ID.
     */
    public function delete(string $runId): bool;
}
