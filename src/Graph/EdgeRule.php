<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Graph;

use Closure;
use Yousef\LaraOrcest\State\RunState;

class EdgeRule
{
    /**
     * @var callable|Closure|null
     */
    public readonly mixed $condition;

    /**
     * @param  string  $fromNode  Source node key
     * @param  string  $toNode  Target node key (or special terminal: '__completed__', '__failed__')
     * @param  callable|Closure|null  $condition  callable(RunState): bool
     * @param  int  $priority  Priority order (higher evaluated first)
     */
    public function __construct(
        public readonly string $fromNode,
        public readonly string $toNode,
        ?callable $condition = null,
        public readonly int $priority = 10
    ) {
        $this->condition = $condition;
    }

    /**
     * Creates an unconditional transition.
     */
    public static function always(string $fromNode, string $toNode, int $priority = 10): self
    {
        return new self($fromNode, $toNode, null, $priority);
    }

    /**
     * Creates a conditional transition based on a callback.
     */
    public static function when(string $fromNode, string $toNode, callable $condition, int $priority = 10): self
    {
        return new self($fromNode, $toNode, $condition, $priority);
    }

    /**
     * Checks if this transition edge evaluates to true for the given state.
     */
    public function matches(RunState $state): bool
    {
        if ($this->condition === null) {
            return true;
        }

        return (bool) ($this->condition)($state);
    }

    public function describe(): string
    {
        return "[{$this->fromNode} -> {$this->toNode} (priority: {$this->priority})]";
    }
}
