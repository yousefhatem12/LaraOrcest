<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Support;

use Illuminate\Container\Container;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Facade;

class StandaloneBootstrap
{
    /**
     * Ensures a lightweight container is registered as the Laravel Facade root
     * when running outside a booted Laravel application so facade calls such as
     * Http::... never throw "A facade root has not been set.".
     */
    public static function ensureFacadeRoot(): void
    {
        if (Facade::getFacadeApplication() !== null) {
            return;
        }

        $container = new Container();
        $container->singleton(HttpFactory::class, static fn (): HttpFactory => HttpClient::factory());
        $container->alias(HttpFactory::class, 'http');

        Facade::setFacadeApplication($container);
    }
}
