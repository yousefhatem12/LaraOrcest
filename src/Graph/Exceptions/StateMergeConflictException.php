<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Graph\Exceptions;

use RuntimeException;

class StateMergeConflictException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $conflicts
     */
    public function __construct(
        string $message,
        protected readonly array $conflicts = [],
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Returns the conflicting details.
     *
     * @return array<string, mixed>
     */
    public function getConflicts(): array
    {
        return $this->conflicts;
    }
}
