<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\State\Drivers;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Yousef\LaraOrcest\Contracts\CheckpointerContract;
use Yousef\LaraOrcest\State\RunState;

class DatabaseCheckpointer implements CheckpointerContract
{
    protected string $table;

    public function __construct(?string $table = null)
    {
        $this->table = $table ?: (function_exists('config')
            ? config('lara-orcest.checkpointer.table', 'orcest_runs')
            : 'orcest_runs');
    }

    public function save(RunState $state): void
    {
        $payload = [
            'run_id' => $state->runId,
            'prompt' => $state->prompt,
            'current_node' => $state->currentNode,
            'status' => $state->status,
            'state' => json_encode($state->toArray(), JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ];

        $exists = DB::table($this->table)->where('run_id', $state->runId)->exists();

        if ($exists) {
            DB::table($this->table)->where('run_id', $state->runId)->update($payload);
        } else {
            $payload['created_at'] = now();
            DB::table($this->table)->insert($payload);
        }
    }

    public function load(string $runId): ?RunState
    {
        $row = DB::table($this->table)->where('run_id', $runId)->first();
        if (! $row) {
            return null;
        }

        $stateData = is_string($row->state) ? json_decode($row->state, true) : (array) $row->state;
        if (! is_array($stateData)) {
            throw new RuntimeException("Corrupted DB state data for run: {$runId}");
        }

        return RunState::fromArray($stateData);
    }

    public function list(int $limit = 20): array
    {
        $rows = DB::table($this->table)
            ->select(['run_id', 'prompt', 'current_node', 'status', 'updated_at'])
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        return $rows->map(fn ($r) => [
            'run_id' => (string) $r->run_id,
            'prompt' => (string) $r->prompt,
            'current_node' => (string) $r->current_node,
            'status' => (string) $r->status,
            'updated_at' => (string) $r->updated_at,
        ])->all();
    }
}
