<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Graph;

use Yousef\LaraOrcest\Agents\ArchitectAgent;
use Yousef\LaraOrcest\Agents\BackendAgent;
use Yousef\LaraOrcest\Agents\BusinessAnalystAgent;
use Yousef\LaraOrcest\Agents\DatabaseAgent;
use Yousef\LaraOrcest\Agents\FixerAgent;
use Yousef\LaraOrcest\Agents\GitAgent;
use Yousef\LaraOrcest\Agents\PackageManagerAgent;
use Yousef\LaraOrcest\Agents\ProductManagerAgent;
use Yousef\LaraOrcest\Agents\QaAgent;
use Yousef\LaraOrcest\Agents\ReviewerAgent;
use Yousef\LaraOrcest\Agents\SwaggerAgent;
use Yousef\LaraOrcest\Agents\WebScrapingAgent;
use Yousef\LaraOrcest\Contracts\CheckpointerContract;
use Yousef\LaraOrcest\Contracts\LlmDriverContract;
use Yousef\LaraOrcest\Contracts\ToolContract;
use Yousef\LaraOrcest\Graph\Nodes\AgentGraphNode;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Tools\ArtisanRunnerTool;
use Yousef\LaraOrcest\Tools\CodebaseInspectorTool;
use Yousef\LaraOrcest\Tools\ComposerRunnerTool;
use Yousef\LaraOrcest\Tools\DirectoryScaffolderTool;
use Yousef\LaraOrcest\Tools\FileReaderTool;
use Yousef\LaraOrcest\Tools\GitCommandTool;
use Yousef\LaraOrcest\Tools\HttpDispatchTool;
use Yousef\LaraOrcest\Tools\HttpFetchTool;
use Yousef\LaraOrcest\Tools\PatchWriterTool;
use Yousef\LaraOrcest\Tools\Support\PathGuard;
use Yousef\LaraOrcest\Tools\TestRunnerTool;
use Yousef\LaraOrcest\Workspace\WorkspaceContext;

class DefaultGraphBuilder
{
    /**
     * @param  array<string, ToolContract>|null  $tools
     */
    public static function build(
        LlmDriverContract $llm,
        ?CheckpointerContract $checkpointer = null,
        ?array $tools = null,
        bool $withScraper = false,
        bool $withSwagger = false,
        ?WorkspaceContext $workspaceContext = null,
        bool $withPackageManager = false
    ): GraphEngine {
        $engine = new GraphEngine($checkpointer, $workspaceContext);

        $tools = $tools ?: self::defaultTools($workspaceContext);

        $packageManagerEnabled = $withPackageManager;

        // 1. Instantiate built-in agents
        $agents = [
            new BusinessAnalystAgent(),
            new ProductManagerAgent(),
            new ArchitectAgent(),
            new GitAgent(),
            new DatabaseAgent(),
            new BackendAgent(),
            new QaAgent(),
            new FixerAgent(),
            new ReviewerAgent(),
        ];

        if ($packageManagerEnabled) {
            $agents[] = new PackageManagerAgent();
        }

        if ($withScraper) {
            $agents[] = new WebScrapingAgent();
        }

        if ($withSwagger) {
            $agents[] = new SwaggerAgent();
        }

        // 2. Register agent nodes
        foreach ($agents as $agent) {
            $engine->addNode(new AgentGraphNode($agent, $tools, $llm, $checkpointer));
        }

        // 3. Register sequential edges
        $engine->addEdge('business_analyst', 'product_manager');

        if ($withScraper) {
            // From product_manager: route to web_scraping_agent if scraping target URLs and endpoint are requested, else to architect
            $engine->addEdge('product_manager', 'web_scraping_agent', fn (RunState $s) => $s->shouldRunWebScraper(), 20);
            $engine->addEdge('product_manager', 'architect', fn (RunState $s) => ! $s->shouldRunWebScraper(), 10);

            // From web_scraping_agent: complete if configured, otherwise proceed to architect
            $engine->addEdge('web_scraping_agent', GraphEngine::TERMINAL_COMPLETED, fn (RunState $s) => $s->shouldStopAfterScraping(), 20);
            $engine->addEdge('web_scraping_agent', 'architect', fn (RunState $s) => ! $s->shouldStopAfterScraping(), 10);
        } else {
            $engine->addEdge('product_manager', 'architect');
        }

        $engine->addEdge('architect', 'git_agent');

        if ($packageManagerEnabled) {
            $engine->addEdge('git_agent', 'package_manager_agent', fn (RunState $s) => $s->shouldRunPackageManager(), 20);
            $engine->addEdge('git_agent', 'database_agent', fn (RunState $s) => ! $s->shouldRunPackageManager(), 10);
            $engine->addEdge('package_manager_agent', 'database_agent');
        } else {
            $engine->addEdge('git_agent', 'database_agent');
        }

        $engine->addEdge('database_agent', 'backend_agent');

        if ($withSwagger) {
            $engine->addEdge('backend_agent', 'swagger_agent', fn (RunState $s) => $s->shouldRunSwagger(), 20);
            $engine->addEdge('backend_agent', 'qa_agent', fn (RunState $s) => ! $s->shouldRunSwagger(), 10);
            $engine->addEdge('swagger_agent', 'qa_agent');
        } else {
            $engine->addEdge('backend_agent', 'qa_agent');
        }

        // 4. Conditional transitions from qa_agent
        // If tests pass -> reviewer_agent
        $engine->addEdge('qa_agent', 'reviewer_agent', fn (RunState $s) => $s->areTestsPassing() || $s->isDryRun(), 20);
        // If tests fail AND can fix -> fixer_agent
        $engine->addEdge('qa_agent', 'fixer_agent', fn (RunState $s) => ! $s->areTestsPassing() && $s->canAttemptFix(), 10);
        // If tests fail AND cannot fix -> TERMINAL_FAILED
        $engine->addEdge('qa_agent', GraphEngine::TERMINAL_FAILED, fn (RunState $s) => ! $s->areTestsPassing() && ! $s->canAttemptFix(), 5);

        // 5. From fixer_agent -> re-run qa_agent
        $engine->addEdge('fixer_agent', 'qa_agent');

        // 6. Conditional transitions from reviewer_agent
        // If approved -> TERMINAL_COMPLETED
        $engine->addEdge('reviewer_agent', GraphEngine::TERMINAL_COMPLETED, fn (RunState $s) => $s->isReviewApproved(), 20);
        // If revise AND can fix -> fixer_agent
        $engine->addEdge('reviewer_agent', 'fixer_agent', fn (RunState $s) => ! $s->isReviewApproved() && $s->canAttemptFix(), 10);
        // If revise AND cannot fix -> TERMINAL_FAILED
        $engine->addEdge('reviewer_agent', GraphEngine::TERMINAL_FAILED, fn (RunState $s) => ! $s->isReviewApproved() && ! $s->canAttemptFix(), 5);

        return $engine;
    }

    /**
     * Builds a dedicated standalone web scraping & data ingestion pipeline:
     * business_analyst -> product_manager -> web_scraping_agent -> TERMINAL_COMPLETED
     *
     * @param  array<string, ToolContract>|null  $tools
     */
    public static function buildScrapingPipeline(
        LlmDriverContract $llm,
        ?CheckpointerContract $checkpointer = null,
        ?array $tools = null,
        ?WorkspaceContext $workspaceContext = null
    ): GraphEngine {
        $engine = new GraphEngine($checkpointer, $workspaceContext);
        $tools = $tools ?: self::defaultTools($workspaceContext);

        $agents = [
            new BusinessAnalystAgent(),
            new ProductManagerAgent(),
            new WebScrapingAgent(),
        ];

        foreach ($agents as $agent) {
            $engine->addNode(new AgentGraphNode($agent, $tools, $llm, $checkpointer));
        }

        $engine->addEdge('business_analyst', 'product_manager');
        $engine->addEdge('product_manager', 'web_scraping_agent');
        $engine->addEdge('web_scraping_agent', GraphEngine::TERMINAL_COMPLETED);

        return $engine;
    }

    /**
     * Builds a dedicated standalone Swagger/OpenAPI documentation pipeline:
     * backend_agent -> swagger_agent -> TERMINAL_COMPLETED
     *
     * @param  array<string, ToolContract>|null  $tools
     */
    public static function buildSwaggerPipeline(
        LlmDriverContract $llm,
        ?CheckpointerContract $checkpointer = null,
        ?array $tools = null,
        ?WorkspaceContext $workspaceContext = null
    ): GraphEngine {
        $engine = new GraphEngine($checkpointer, $workspaceContext);
        $tools = $tools ?: self::defaultTools($workspaceContext);

        $agents = [
            new BackendAgent(),
            new SwaggerAgent(),
        ];

        foreach ($agents as $agent) {
            $engine->addNode(new AgentGraphNode($agent, $tools, $llm, $checkpointer));
        }

        $engine->addEdge('backend_agent', 'swagger_agent');
        $engine->addEdge('swagger_agent', GraphEngine::TERMINAL_COMPLETED);

        return $engine;
    }

    /**
     * Builds a dedicated standalone package manager & project bootstrapping pipeline:
     * package_manager_agent -> TERMINAL_COMPLETED
     *
     * @param  array<string, ToolContract>|null  $tools
     */
    public static function buildPackageManagerPipeline(
        LlmDriverContract $llm,
        ?CheckpointerContract $checkpointer = null,
        ?array $tools = null,
        ?WorkspaceContext $workspaceContext = null
    ): GraphEngine {
        $engine = new GraphEngine($checkpointer, $workspaceContext);
        $tools = $tools ?: self::defaultTools($workspaceContext);

        $agent = new PackageManagerAgent();
        $engine->addNode(new AgentGraphNode($agent, $tools, $llm, $checkpointer));
        $engine->addEdge('package_manager_agent', GraphEngine::TERMINAL_COMPLETED);

        return $engine;
    }

    /**
     * @return array<string, ToolContract>
     */
    public static function defaultTools(?WorkspaceContext $workspaceContext = null): array
    {
        if ($workspaceContext !== null) {
            return $workspaceContext->createTools();
        }

        $pathGuard = new PathGuard();

        return [
            'file_reader' => new FileReaderTool(pathGuard: $pathGuard),
            'patch_writer' => new PatchWriterTool(pathGuard: $pathGuard),
            'artisan_runner' => new ArtisanRunnerTool(),
            'test_runner' => new TestRunnerTool(),
            'git_command' => new GitCommandTool(pathGuard: $pathGuard),
            'http_fetch' => new HttpFetchTool(),
            'http_dispatch' => new HttpDispatchTool(),
            'codebase_inspector' => new CodebaseInspectorTool(pathGuard: $pathGuard),
            'composer_runner' => new ComposerRunnerTool(),
            'directory_scaffolder' => new DirectoryScaffolderTool(pathGuard: $pathGuard),
        ];
    }
}
