<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Graph\Exceptions;

use RuntimeException;

class CyclicDependencyException extends RuntimeException
{
    /**
     * @param  array<int, string>  $cycle
     */
    public function __construct(
        string $message,
        protected readonly array $cycle = [],
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Returns the detected cyclic dependency chain if available.
     *
     * @return array<int, string>
     */
    public function getCycle(): array
    {
        return $this->cycle;
    }
}
