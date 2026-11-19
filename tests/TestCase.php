<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Yousef\LaraOrcest\Facades\Orchestrator;
use Yousef\LaraOrcest\LaraOrcestServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            LaraOrcestServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app): array
    {
        return [
            'Orchestrator' => Orchestrator::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('lara-orcest.enabled', true);
        $app['config']->set('lara-orcest.llm.default', 'fake');
        $app['config']->set('lara-orcest.checkpointer.driver', 'json');
        $app['config']->set('lara-orcest.checkpointer.json_path', sys_get_temp_dir() . '/lara-orcest-tests');
    }
}
