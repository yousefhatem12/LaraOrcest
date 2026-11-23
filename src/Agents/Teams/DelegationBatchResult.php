<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Agents\Teams;

use Yousef\LaraOrcest\State\RunState;

class DelegationBatchResult
{
    /**
     * @param  array<string, mixed>  $results  Keyed by sub-agent key
     * @param  array<int, array<int, string>>  $waves  Sub-agent keys executed per wave
     */
    public function __construct(
        public readonly RunState $state,
        public readonly bool $success,
        public readonly array $results = [],
        public readonly array $waves = [],
        public readonly array $artifacts = []
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getResult(string $subAgentKey): ?array
    {
        return $this->results[$subAgentKey] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'waves' => $this->waves,
            'results' => $this->results,
            'artifacts' => $this->artifacts,
            'delegation_count' => count($this->results),
        ];
    }
}
