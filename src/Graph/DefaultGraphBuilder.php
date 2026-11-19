<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Graph;

use Yousef\LaraOrcest\Agents\ArchitectAgent;
use Yousef\LaraOrcest\Agents\BackendAgent;
use Yousef\LaraOrcest\Agents\BusinessAnalystAgent;
use Yousef\LaraOrcest\Agents\DatabaseAgent;
use Yousef\LaraOrcest\Agents\FixerAgent;
use Yousef\LaraOrcest\Agents\GitAgent;
use Yousef\LaraOrcest\Agents\ProductManagerAgent;
use Yousef\LaraOrcest\Agents\QaAgent;
use Yousef\LaraOrcest\Agents\ReviewerAgent;
use Yousef\LaraOrcest\Contracts\CheckpointerContract;
use Yousef\LaraOrcest\Contracts\LlmDriverContract;
use Yousef\LaraOrcest\Contracts\ToolContract;
use Yousef\LaraOrcest\Graph\Nodes\AgentGraphNode;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Tools\ArtisanRunnerTool;
use Yousef\LaraOrcest\Tools\FileReaderTool;
use Yousef\LaraOrcest\Tools\GitCommandTool;
use Yousef\LaraOrcest\Tools\PatchWriterTool;
use Yousef\LaraOrcest\Tools\TestRunnerTool;

class DefaultGraphBuilder
{
    /**
     * @param  array<string, ToolContract>|null  $tools
     */
    public static function build(
        LlmDriverContract $llm,
        ?CheckpointerContract $checkpointer = null,
        ?array $tools = null
    ): GraphEngine {
        $engine = new GraphEngine($checkpointer);

        $tools = $tools ?: self::defaultTools();

        // 1. Instantiate 9 built-in agents
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

        // 2. Register agent nodes
        foreach ($agents as $agent) {
            $engine->addNode(new AgentGraphNode($agent, $tools, $llm));
        }

        // 3. Register sequential edges
        $engine->addEdge('business_analyst', 'product_manager');
        $engine->addEdge('product_manager', 'architect');
        $engine->addEdge('architect', 'git_agent');
        $engine->addEdge('git_agent', 'database_agent');
        $engine->addEdge('database_agent', 'backend_agent');
        $engine->addEdge('backend_agent', 'qa_agent');

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
     * @return array<string, ToolContract>
     */
    public static function defaultTools(): array
    {
        return [
            'file_reader' => new FileReaderTool(),
            'patch_writer' => new PatchWriterTool(),
            'artisan_runner' => new ArtisanRunnerTool(),
            'test_runner' => new TestRunnerTool(),
            'git_command' => new GitCommandTool(),
        ];
    }
}
