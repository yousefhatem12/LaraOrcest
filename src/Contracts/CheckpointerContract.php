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
}
