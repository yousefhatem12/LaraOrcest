<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Agents\Teams;

use Yousef\LaraOrcest\Agents\Contracts\SubAgentInterface;

class SubAgentRegistry
{
    /**
     * @var array<string, SubAgentInterface>
     */
    protected array $subAgents = [];

    /**
     * Register a sub-agent instance.
     */
    public function register(SubAgentInterface $subAgent): self
    {
        $this->subAgents[$subAgent->key()] = $subAgent;

        return $this;
    }

    /**
     * Unregister a sub-agent by key.
     */
    public function unregister(string $key): self
    {
        unset($this->subAgents[$key]);

        return $this;
    }

    /**
     * Check if a sub-agent is registered.
     */
    public function has(string $key): bool
    {
        return isset($this->subAgents[$key]);
    }

    /**
     * Retrieve a sub-agent by key.
     */
    public function get(string $key): ?SubAgentInterface
    {
        return $this->subAgents[$key] ?? null;
    }

    /**
     * Retrieve all sub-agents assigned to a specific parent lead agent.
     *
     * @return array<string, SubAgentInterface>
     */
    public function forParent(string $parentAgentKey): array
    {
        $matching = [];
        $needle = strtolower(trim($parentAgentKey));

        foreach ($this->subAgents as $key => $subAgent) {
            if (strtolower(trim($subAgent->parentAgentKey())) === $needle) {
                $matching[$key] = $subAgent;
            }
        }

        return $matching;
    }

    /**
     * Retrieve all registered sub-agents.
     *
     * @return array<string, SubAgentInterface>
     */
    public function all(): array
    {
        return $this->subAgents;
    }

    public function count(): int
    {
        return count($this->subAgents);
    }
}
