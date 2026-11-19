<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Contracts;

interface ToolContract
{
    /**
     * Unique name of the tool (e.g. 'file_reader').
     */
    public function getName(): string;

    /**
     * Short description of what the tool accomplishes.
     */
    public function getDescription(): string;

    /**
     * JSON Schema array describing the input parameters.
     *
     * @return array<string, mixed>
     */
    public function getSchema(): array;

    /**
     * Executes the tool with the validated parameters.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function execute(array $params): array;
}
