<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\State;

use InvalidArgumentException;
use Yousef\LaraOrcest\Contracts\CheckpointerContract;
use Yousef\LaraOrcest\State\Drivers\DatabaseCheckpointer;
use Yousef\LaraOrcest\State\Drivers\JsonCheckpointer;
use Yousef\LaraOrcest\Support\Config;

class StateCheckpointer implements CheckpointerContract
{
    protected ?CheckpointerContract $driver = null;

    public function __construct(?CheckpointerContract $driver = null)
    {
        $this->driver = $driver;
    }

    public function getDriver(): CheckpointerContract
    {
        if ($this->driver !== null) {
            return $this->driver;
        }

        $driverName = (string) Config::get('lara-orcest.checkpointer.driver', 'json');

        return $this->driver = match ($driverName) {
            'json' => new JsonCheckpointer(
                Config::get('lara-orcest.checkpointer.json_path')
            ),
            'database' => new DatabaseCheckpointer(
                Config::get('lara-orcest.checkpointer.table')
            ),
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

    public function list(int $limit = 20): array
    {
        return $this->getDriver()->list($limit);
    }
}
