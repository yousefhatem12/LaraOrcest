<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Contracts;

class TaskDefinition
{
    /**
     * @param  array<int, string>  $acceptanceCriteria
     * @param  array<int, string>  $dependencies
     * @param  array<string, mixed>  $metadata
     * @param  array<int, string>  $scopePaths
     */
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $description,
        public readonly int $priority = 1,
        public readonly array $acceptanceCriteria = [],
        public readonly array $dependencies = [],
        public readonly string $status = 'pending',
        public readonly array $metadata = [],
        public readonly array $scopePaths = []
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
            'depends_on' => $this->dependencies,
            'status' => $this->status,
            'metadata' => $this->metadata,
            'scope_paths' => $this->scopePaths,
        ];
    }

    /**
     * Reconstruct from array.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $deps = (array) ($data['depends_on'] ?? $data['dependencies'] ?? []);
        $scopes = (array) ($data['scope_paths'] ?? $data['scopes'] ?? []);

        return new self(
            id: (string) ($data['id'] ?? uniqid('task_')),
            title: (string) ($data['title'] ?? ''),
            description: (string) ($data['description'] ?? ''),
            priority: (int) ($data['priority'] ?? 1),
            acceptanceCriteria: (array) ($data['acceptance_criteria'] ?? []),
            dependencies: array_values(array_map('strval', $deps)),
            status: (string) ($data['status'] ?? 'pending'),
            metadata: (array) ($data['metadata'] ?? []),
            scopePaths: array_values(array_map('strval', $scopes)),
        );
    }

    /**
     * Returns task dependencies (alias for depends_on).
     *
     * @return array<int, string>
     */
    public function getDependencies(): array
    {
        return $this->dependencies;
    }

    /**
     * Returns scope paths (target files/directories).
     *
     * @return array<int, string>
     */
    public function getScopePaths(): array
    {
        return $this->scopePaths;
    }

    public function withStatus(string $status): self
    {
        return new self(
            id: $this->id,
            title: $this->title,
            description: $this->description,
            priority: $this->priority,
            acceptanceCriteria: $this->acceptanceCriteria,
            dependencies: $this->dependencies,
            status: $status,
            metadata: $this->metadata,
            scopePaths: $this->scopePaths,
        );
    }

    public function withMetadata(array $metadata): self
    {
        return new self(
            id: $this->id,
            title: $this->title,
            description: $this->description,
            priority: $this->priority,
            acceptanceCriteria: $this->acceptanceCriteria,
            dependencies: $this->dependencies,
            status: $this->status,
            metadata: $metadata,
            scopePaths: $this->scopePaths,
        );
    }

    public function withScopePaths(array $scopePaths): self
    {
        return new self(
            id: $this->id,
            title: $this->title,
            description: $this->description,
            priority: $this->priority,
            acceptanceCriteria: $this->acceptanceCriteria,
            dependencies: $this->dependencies,
            status: $this->status,
            metadata: $this->metadata,
            scopePaths: array_values(array_map('strval', $scopePaths)),
        );
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Determines whether this task involves Git mutations (creating branches, committing, staging).
     */
    public function isGitMutation(): bool
    {
        if (! empty($this->metadata['is_git_mutation']) || ! empty($this->metadata['exclusive'])) {
            return true;
        }

        $idLower = strtolower($this->id);
        $titleLower = strtolower($this->title);
        $descLower = strtolower($this->description);

        if ($idLower === 'git_agent' || str_starts_with($idLower, 'git_')) {
            return true;
        }

        return str_contains($titleLower, 'git agent')
            || str_contains($titleLower, 'git commit')
            || str_contains($descLower, 'git branch')
            || str_contains($descLower, 'create branch')
            || str_contains($descLower, 'git commit')
            || str_contains($descLower, 'git checkout');
    }
}
