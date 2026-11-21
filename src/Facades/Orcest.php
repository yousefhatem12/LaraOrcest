<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Yousef\LaraOrcest\State\RunState run(string $prompt, array $options = [])
 * @method static \Yousef\LaraOrcest\State\RunState resume(string $runId, array $options = [])
 * @method static \Yousef\LaraOrcest\State\RunState approve(string $runId, ?string $notes = null, array $options = [])
 * @method static \Yousef\LaraOrcest\State\RunState reject(string $runId, ?string $notes = null, array $options = [])
 * @method static array status(?string $runId = null)
 * @method static \Yousef\LaraOrcest\Contracts\ProjectMemoryContract memory()
 *
 * @see \Yousef\LaraOrcest\Orchestrator
 */
class Orcest extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'lara-orcest';
    }
}
