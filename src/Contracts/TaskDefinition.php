<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Contracts;

class TaskDefinition
{
    /**
     * @param  array<int, string>  $acceptanceCriteria
     * @param  array<int, string>  $dependencies
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $description,
        public readonly int $priority = 1,
        public readonly array $acceptanceCriteria = [],
        public readonly array $dependencies = [],
        public readonly string $status = 'pending',
        public readonly array $metadata = []
    ) {}

    /**
     * Convert to an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'priority' => $this->priority,
            'acceptance_criteria' => $this->acceptanceCriteria,
            'dependencies' => $this->dependencies,
            'status' => $this->status,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Reconstruct from array.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? uniqid('task_')),
            title: (string) ($data['title'] ?? ''),
            description: (string) ($data['description'] ?? ''),
            priority: (int) ($data['priority'] ?? 1),
            acceptanceCriteria: (array) ($data['acceptance_criteria'] ?? []),
            dependencies: (array) ($data['dependencies'] ?? []),
            status: (string) ($data['status'] ?? 'pending'),
            metadata: (array) ($data['metadata'] ?? []),
        );
    }
}
