<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest;

use Illuminate\Support\ServiceProvider;
use Yousef\LaraOrcest\Commands\OrcestBuildCommand;
use Yousef\LaraOrcest\Commands\OrcestStatusCommand;
use Yousef\LaraOrcest\Contracts\CheckpointerContract;
use Yousef\LaraOrcest\Graph\DefaultGraphBuilder;
use Yousef\LaraOrcest\Graph\GraphEngine;
use Yousef\LaraOrcest\LLM\Drivers\OpenAiCompatibleHttpDriver;
use Yousef\LaraOrcest\LLM\LlmManager;
use Yousef\LaraOrcest\State\StateCheckpointer;
use Yousef\LaraOrcest\Tools\ArtisanRunnerTool;
use Yousef\LaraOrcest\Tools\FileReaderTool;
use Yousef\LaraOrcest\Tools\GitCommandTool;
use Yousef\LaraOrcest\Tools\PatchWriterTool;
use Yousef\LaraOrcest\Tools\Support\PathGuard;
use Yousef\LaraOrcest\Tools\TestRunnerTool;

class LaraOrcestServiceProvider extends ServiceProvider
{
    /**
     * Register any package services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/lara-orcest.php',
            'lara-orcest'
        );

        $this->app->singleton(LlmManager::class, function () {
            return new LlmManager();
        });

        $this->app->singleton(OpenAiCompatibleHttpDriver::class, function ($app) {
            return $app->make(LlmManager::class)->driver('openai_compatible');
        });

        $this->app->singleton(PathGuard::class, function () {
            return new PathGuard();
        });

        $this->app->singleton(FileReaderTool::class, function ($app) {
            return new FileReaderTool($app->make(PathGuard::class));
        });

        $this->app->singleton(PatchWriterTool::class, function ($app) {
            return new PatchWriterTool($app->make(PathGuard::class));
        });

        $this->app->singleton(ArtisanRunnerTool::class, function () {
            return new ArtisanRunnerTool();
        });

        $this->app->singleton(TestRunnerTool::class, function () {
            return new TestRunnerTool();
        });

        $this->app->singleton(GitCommandTool::class, function ($app) {
            return new GitCommandTool(pathGuard: $app->make(PathGuard::class));
        });

        $this->app->singleton(StateCheckpointer::class, function () {
            return new StateCheckpointer();
        });

        $this->app->singleton(CheckpointerContract::class, function ($app) {
            return $app->make(StateCheckpointer::class);
        });

        $this->app->bind(GraphEngine::class, function ($app) {
            return DefaultGraphBuilder::build(
                llm: $app->make(LlmManager::class)->driver(),
                checkpointer: $app->make(CheckpointerContract::class)
            );
        });

        $this->app->singleton('lara-orcest', function ($app) {
            return new Orchestrator(
                llmManager: $app->make(LlmManager::class),
                checkpointer: $app->make(CheckpointerContract::class)
            );
        });

        $this->app->alias('lara-orcest', Orchestrator::class);
    }

    /**
     * Bootstrap any package services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            // Publish configuration
            $this->publishes([
                __DIR__ . '/../config/lara-orcest.php' => config_path('lara-orcest.php'),
            ], 'lara-orcest-config');

            // Publish database migrations
            $this->publishes([
                __DIR__ . '/../database/migrations/2026_01_01_000000_create_orcest_runs_table.php.stub' => database_path('migrations/' . date('Y_m_d_His') . '_create_orcest_runs_table.php'),
            ], 'lara-orcest-migrations');

            // Register artisan commands
            $this->commands([
                OrcestBuildCommand::class,
                OrcestStatusCommand::class,
            ]);
        }
    }
}
