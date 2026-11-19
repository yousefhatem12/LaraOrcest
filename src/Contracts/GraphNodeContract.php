<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Contracts;

use Yousef\LaraOrcest\State\RunState;

interface GraphNodeContract
{
    /**
     * Unique key identifying this node in the graph (e.g. 'business_analyst').
     */
    public function getKey(): string;

    /**
     * Executes the node's logic and returns the updated state.
     */
    public function execute(RunState $state): RunState;
}
