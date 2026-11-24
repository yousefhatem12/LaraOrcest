<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\State;

use InvalidArgumentException;
use Yousef\LaraOrcest\Contracts\CheckpointerContract;
use Yousef\LaraOrcest\State\Drivers\DatabaseCheckpointer;
use Yousef\LaraOrcest\State\Drivers\JsonCheckpointer;
use Yousef\LaraOrcest\Support\Config;
use Yousef\LaraOrcest\Workspace\WorkspaceContext;

class StateCheckpointer implements CheckpointerContract
{
    protected ?CheckpointerContract $driver = null;
    protected ?WorkspaceContext $workspaceContext = null;

    public function __construct(
        ?CheckpointerContract $driver = null,
        ?WorkspaceContext $workspaceContext = null
    ) {
        $this->driver = $driver;
        $this->workspaceContext = $workspaceContext;
    }

    public function getDriver(): CheckpointerContract
    {
        if ($this->driver !== null) {
            return $this->driver;
        }

        $driverName = (string) ($this->workspaceContext?->getConfig()->get('checkpoint.driver')
            ?: Config::get('lara-orcest.checkpoint.driver', Config::get('lara-orcest.checkpointer.driver', 'json')));

        $jsonPath = $this->workspaceContext?->getConfig()->get('checkpoint.path')
            ?: Config::get('lara-orcest.checkpoint.path', Config::get('lara-orcest.checkpoint.json_path', Config::get('lara-orcest.checkpointer.json_path')));

        $table = $this->workspaceContext?->getConfig()->get('checkpoint.table')
            ?: Config::get('lara-orcest.checkpoint.table', Config::get('lara-orcest.checkpointer.table'));

        return $this->driver = match ($driverName) {
            'json' => new JsonCheckpointer($jsonPath ? (string) $jsonPath : null, $this->workspaceContext),
            'database' => new DatabaseCheckpointer($table ? (string) $table : null),
            default => throw new InvalidArgumentException("Unsupported checkpointer driver: [{$driverName}]"),
        };
    }

    public function setDriver(CheckpointerContract $driver): self
    {
        $this->driver = $driver;

        return $this;
    }

    public function save(RunState $state): void
    {
        $this->getDriver()->save($state);
    }

    public function load(string $runId): ?RunState
    {
        return $this->getDriver()->load($runId);
    }

    public function exists(string $runId): bool
    {
        return $this->getDriver()->exists($runId);
    }

    public function delete(string $runId): bool
    {
        return $this->getDriver()->delete($runId);
    }

    public function list(int $limit = 20): array
    {
        return $this->getDriver()->list($limit);
    }
}
