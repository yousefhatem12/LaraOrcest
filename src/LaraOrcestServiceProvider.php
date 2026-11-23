<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest;

use Illuminate\Support\ServiceProvider;
use Yousef\LaraOrcest\Agents\ArchitectAgent;
use Yousef\LaraOrcest\Agents\BackendAgent;
use Yousef\LaraOrcest\Agents\BusinessAnalystAgent;
use Yousef\LaraOrcest\Agents\DatabaseAgent;
use Yousef\LaraOrcest\Agents\FixerAgent;
use Yousef\LaraOrcest\Agents\GitAgent;
use Yousef\LaraOrcest\Agents\ProductManagerAgent;
use Yousef\LaraOrcest\Agents\QaAgent;
use Yousef\LaraOrcest\Agents\ReviewerAgent;
use Yousef\LaraOrcest\Agents\SubAgents\ApiContractAgent;
use Yousef\LaraOrcest\Agents\SubAgents\BacklogSplitterAgent;
use Yousef\LaraOrcest\Agents\SubAgents\ControllerResourceAgent;
use Yousef\LaraOrcest\Agents\SubAgents\DddBoundaryAgent;
use Yousef\LaraOrcest\Agents\SubAgents\DomainRulesAgent;
use Yousef\LaraOrcest\Agents\SubAgents\FeatureTestAgent;
use Yousef\LaraOrcest\Agents\SubAgents\FixerSubAgent;
use Yousef\LaraOrcest\Agents\SubAgents\GitSubAgent;
use Yousef\LaraOrcest\Agents\SubAgents\MigrationAgent;
use Yousef\LaraOrcest\Agents\SubAgents\ModelEloquentAgent;
use Yousef\LaraOrcest\Agents\SubAgents\RequestValidationAgent;
use Yousef\LaraOrcest\Agents\SubAgents\SchemaPlannerAgent;
use Yousef\LaraOrcest\Agents\SubAgents\SeederAgent;
use Yousef\LaraOrcest\Agents\SubAgents\ServiceActionAgent;
use Yousef\LaraOrcest\Agents\SubAgents\SwaggerSubAgent;
use Yousef\LaraOrcest\Agents\SubAgents\WebScrapingSubAgent;
use Yousef\LaraOrcest\Agents\SwaggerAgent;
use Yousef\LaraOrcest\Agents\Teams\SubAgentRegistry;
use Yousef\LaraOrcest\Agents\Teams\TeamDelegator;
use Yousef\LaraOrcest\Agents\WebScrapingAgent;
use Yousef\LaraOrcest\Commands\OrcestApproveCommand;
use Yousef\LaraOrcest\Commands\OrcestBuildCommand;
use Yousef\LaraOrcest\Commands\OrcestStatusCommand;
use Yousef\LaraOrcest\Contracts\CheckpointerContract;
use Yousef\LaraOrcest\Contracts\ProjectMemoryContract;
use Yousef\LaraOrcest\Graph\DefaultGraphBuilder;
use Yousef\LaraOrcest\Graph\GraphEngine;
use Yousef\LaraOrcest\LLM\Drivers\OpenAiCompatibleHttpDriver;
use Yousef\LaraOrcest\LLM\LlmManager;
use Yousef\LaraOrcest\Memory\ProjectMemoryStore;
use Yousef\LaraOrcest\Skills\SkillRegistry;
use Yousef\LaraOrcest\State\StateCheckpointer;
use Yousef\LaraOrcest\Tools\ArtisanRunnerTool;
use Yousef\LaraOrcest\Tools\CodebaseInspectorTool;
use Yousef\LaraOrcest\Tools\FileReaderTool;
use Yousef\LaraOrcest\Tools\GitCommandTool;
use Yousef\LaraOrcest\Tools\HttpDispatchTool;
use Yousef\LaraOrcest\Tools\HttpFetchTool;
use Yousef\LaraOrcest\Tools\PatchWriterTool;
use Yousef\LaraOrcest\Tools\Support\PathGuard;
use Yousef\LaraOrcest\Tools\TestRunnerTool;
use Yousef\LaraOrcest\Tools\ToolRegistry;

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

        $this->app->singleton(HttpFetchTool::class, function () {
            return new HttpFetchTool();
        });

        $this->app->singleton(HttpDispatchTool::class, function () {
            return new HttpDispatchTool();
        });

        $this->app->singleton(CodebaseInspectorTool::class, function ($app) {
            return new CodebaseInspectorTool(pathGuard: $app->make(PathGuard::class));
        });

        $this->app->singleton(StateCheckpointer::class, function () {
            return new StateCheckpointer();
        });

        $this->app->singleton(CheckpointerContract::class, function ($app) {
            return $app->make(StateCheckpointer::class);
        });

        $this->app->singleton(ProjectMemoryStore::class, function ($app) {
            return new ProjectMemoryStore(
                pathGuard: $app->make(PathGuard::class)
            );
        });

        $this->app->singleton(ProjectMemoryContract::class, function ($app) {
            return $app->make(ProjectMemoryStore::class);
        });

        $this->app->singleton(SkillRegistry::class, function () {
            $registry = new SkillRegistry();
            $registry->loadAll();

            return $registry;
        });

        $this->app->singleton(SubAgentRegistry::class, function () {
            $registry = new SubAgentRegistry();
            $registry->register(new DomainRulesAgent());
            $registry->register(new BacklogSplitterAgent());
            $registry->register(new WebScrapingSubAgent());
            $registry->register(new DddBoundaryAgent());
            $registry->register(new SchemaPlannerAgent());
            $registry->register(new ApiContractAgent());
            $registry->register(new MigrationAgent());
            $registry->register(new ModelEloquentAgent());
            $registry->register(new SeederAgent());
            $registry->register(new RequestValidationAgent());
            $registry->register(new ServiceActionAgent());
            $registry->register(new ControllerResourceAgent());
            $registry->register(new FeatureTestAgent());
            $registry->register(new FixerSubAgent());
            $registry->register(new SwaggerSubAgent());
            $registry->register(new GitSubAgent());

            return $registry;
        });

        $this->app->singleton(ToolRegistry::class, function ($app) {
            $registry = new ToolRegistry($app->make(PathGuard::class));
            $registry->register($app->make(FileReaderTool::class));
            $registry->register($app->make(PatchWriterTool::class));
            $registry->register($app->make(ArtisanRunnerTool::class));
            $registry->register($app->make(TestRunnerTool::class));
            $registry->register($app->make(GitCommandTool::class));
            $registry->register($app->make(HttpFetchTool::class));
            $registry->register($app->make(HttpDispatchTool::class));
            $registry->register($app->make(CodebaseInspectorTool::class));

            return $registry;
        });

        $this->app->singleton(TeamDelegator::class, function ($app) {
            return new TeamDelegator(
                subAgentRegistry: $app->make(SubAgentRegistry::class),
                skillRegistry: $app->make(SkillRegistry::class),
                toolRegistry: $app->make(ToolRegistry::class)
            );
        });

        $this->app->bind(GraphEngine::class, function ($app) {
            return DefaultGraphBuilder::build(
                llm: $app->make(LlmManager::class)->driver(),
                checkpointer: $app->make(CheckpointerContract::class)
            );
        });

        // Register built-in sub-agents
        $this->app->bind(DomainRulesAgent::class, fn () => new DomainRulesAgent());
        $this->app->bind(BacklogSplitterAgent::class, fn () => new BacklogSplitterAgent());
        $this->app->bind(WebScrapingSubAgent::class, fn () => new WebScrapingSubAgent());
        $this->app->bind(DddBoundaryAgent::class, fn () => new DddBoundaryAgent());
        $this->app->bind(SchemaPlannerAgent::class, fn () => new SchemaPlannerAgent());
        $this->app->bind(ApiContractAgent::class, fn () => new ApiContractAgent());
        $this->app->bind(MigrationAgent::class, fn () => new MigrationAgent());
        $this->app->bind(ModelEloquentAgent::class, fn () => new ModelEloquentAgent());
        $this->app->bind(SeederAgent::class, fn () => new SeederAgent());
        $this->app->bind(RequestValidationAgent::class, fn () => new RequestValidationAgent());
        $this->app->bind(ServiceActionAgent::class, fn () => new ServiceActionAgent());
        $this->app->bind(ControllerResourceAgent::class, fn () => new ControllerResourceAgent());

        // Register 9 built-in agents
        $this->app->bind(BusinessAnalystAgent::class, fn () => new BusinessAnalystAgent());
        $this->app->bind(ProductManagerAgent::class, fn () => new ProductManagerAgent());
        $this->app->bind(ArchitectAgent::class, fn () => new ArchitectAgent());
        $this->app->bind(GitAgent::class, fn () => new GitAgent());
        $this->app->bind(DatabaseAgent::class, fn () => new DatabaseAgent());
        $this->app->bind(BackendAgent::class, fn () => new BackendAgent());
        $this->app->bind(QaAgent::class, fn () => new QaAgent());
        $this->app->bind(FixerAgent::class, fn () => new FixerAgent());
        $this->app->bind(ReviewerAgent::class, fn () => new ReviewerAgent());
        $this->app->bind(WebScrapingAgent::class, fn () => new WebScrapingAgent());
        $this->app->bind(SwaggerAgent::class, fn () => new SwaggerAgent());

        $this->app->singleton('lara-orcest', function ($app) {
            return new Orchestrator(
                llmManager: $app->make(LlmManager::class),
                checkpointer: $app->make(CheckpointerContract::class)
            );
        });

        $this->app->alias('lara-orcest', Orchestrator::class);
        $this->app->alias('lara-orcest', 'orcest');
    }

    /**
     * Bootstrap any package services.
     */
    public function boot(): void
    {
        // Load translations and views
        $this->loadTranslationsFrom(__DIR__ . '/../resources/lang', 'lara-orcest');
        $this->loadJsonTranslationsFrom(__DIR__ . '/../resources/lang');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'emails');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'lara-orcest');

        if ($this->app->runningInConsole()) {
            // Publish configuration
            $this->publishes([
                __DIR__ . '/../config/lara-orcest.php' => config_path('lara-orcest.php'),
            ], 'lara-orcest-config');

            // Publish database migrations
            $this->publishes([
                __DIR__ . '/../database/migrations/2026_01_01_000000_create_orcest_runs_table.php.stub' => database_path('migrations/' . date('Y_m_d_His') . '_create_orcest_runs_table.php'),
            ], 'lara-orcest-migrations');

            // Publish language resource files
            $this->publishes([
                __DIR__ . '/../resources/lang' => $this->app->langPath(),
            ], 'lara-orcest-lang');

            // Publish email and notification views
            $this->publishes([
                __DIR__ . '/../resources/views' => resource_path('views/vendor/lara-orcest'),
            ], 'lara-orcest-views');

            // Publish skill definitions
            $this->publishes([
                __DIR__ . '/../resources/skills' => resource_path('skills/vendor/lara-orcest'),
            ], 'lara-orcest-skills');

            // Register artisan commands
            $this->commands([
                OrcestBuildCommand::class,
                OrcestStatusCommand::class,
                OrcestApproveCommand::class,
            ]);
        }
    }
}
