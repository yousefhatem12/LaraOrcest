<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Graph;

use Closure;
use Yousef\LaraOrcest\State\RunState;

class EdgeRule
{
    /**
     * @param  string  $fromNode  Source node key
     * @param  string  $toNode  Target node key (or special terminal: '__end__', '__fail__')
     * @param  Closure|null  $condition  callable(RunState): bool
     */
    public function __construct(
        public readonly string $fromNode,
        public readonly string $toNode,
        public readonly ?Closure $condition = null,
        public readonly int $priority = 10
    ) {}

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
}
