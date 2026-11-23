<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tools;

use Closure;
use InvalidArgumentException;
use Yousef\LaraOrcest\Agents\Contracts\SubAgentInterface;
use Yousef\LaraOrcest\Contracts\AgentContract;
use Yousef\LaraOrcest\Contracts\ToolContract;
use Yousef\LaraOrcest\Skills\Skill;
use Yousef\LaraOrcest\Tools\Support\PathGuard;

class ToolRegistry
{
    /**
     * @var array<string, ToolContract>
     */
    protected array $tools = [];

    public function __construct(
        protected ?PathGuard $pathGuard = null
    ) {
        $this->pathGuard = $pathGuard ?: new PathGuard();
    }

    /**
     * Register a tool instance.
     */
    public function register(ToolContract $tool, bool $override = true): self
    {
        if ($override || ! isset($this->tools[$tool->getName()])) {
            $this->tools[$tool->getName()] = $tool;
        }

        return $this;
    }

    /**
     * Register a domain-specific callable as a lightweight ToolContract.
     *
     * @param  array<string, mixed>  $schema
     */
    public function registerFunction(string $name, string $description, array $schema, callable $handler): self
    {
        $closure = $handler instanceof Closure ? $handler : Closure::fromCallable($handler);

        $tool = new class($name, $description, $schema, $closure) implements ToolContract {
            public function __construct(
                protected string $toolName,
                protected string $toolDesc,
                protected array $toolSchema,
                protected Closure $toolHandler
            ) {}

            public function getName(): string
            {
                return $this->toolName;
            }

            public function getDescription(): string
            {
                return $this->toolDesc;
            }

            public function getSchema(): array
            {
                return $this->toolSchema;
            }

            public function execute(array $params): array
            {
                return ($this->toolHandler)($params);
            }
        };

        return $this->register($tool);
    }

    /**
     * Check if a tool is registered.
     */
    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /**
     * Get a registered tool by name.
     */
    public function get(string $name): ?ToolContract
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * Return all registered tools.
     *
     * @return array<string, ToolContract>
     */
    public function all(): array
    {
        return $this->tools;
    }

    /**
     * Resolve and scope tools for an Agent or SubAgent, honoring allowedTools, active skill restrictions, and scopePaths.
     *
     * @param  AgentContract|SubAgentInterface|string  $agent
     * @param  array<string, ToolContract>  $availableTools
     * @param  array<int, string>  $scopePaths
     * @param  Skill|array<int|string, Skill>|null  $activeSkill
     * @return array<string, ToolContract>
     */
    public function resolveForAgent(
        AgentContract|SubAgentInterface|string $agent,
        array $availableTools = [],
        array $scopePaths = [],
        Skill|array|null $activeSkill = null
    ): array {
        $allowedTools = [];
        $effectiveScopePaths = $scopePaths;

        if ($agent instanceof SubAgentInterface) {
            $allowedTools = $agent->allowedTools();
            if (empty($effectiveScopePaths)) {
                $effectiveScopePaths = $agent->scopePaths();
            }
        } elseif ($agent instanceof AgentContract) {
            $allowedTools = $agent->getAllowedTools();
        } elseif (is_string($agent)) {
            $allowedTools = $this->resolveAllowedToolsForAgentKey($agent, array_merge($this->tools, $availableTools));
        }

        // If active skill(s) define allowed_tools, filter against them
        $skillsList = is_array($activeSkill) ? $activeSkill : ($activeSkill !== null ? [$activeSkill] : []);
        foreach ($skillsList as $sk) {
            if ($sk instanceof Skill && ! empty($sk->allowedTools) && ! in_array('*', $sk->allowedTools, true)) {
                $allowedTools = array_values(array_filter($allowedTools, fn ($toolName) => $sk->permitsTool($toolName)));
            }
        }

        $allSourceTools = array_merge($this->tools, $availableTools);
        $resolved = [];

        foreach ($allowedTools as $toolName) {
            $tool = $allSourceTools[$toolName] ?? null;
            if ($tool === null) {
                continue;
            }

            // Wrap with ScopedToolProxy if scopePaths are defined
            if (! empty($effectiveScopePaths)) {
                $resolved[$toolName] = new ScopedToolProxy(
                    tool: $tool,
                    scopePaths: $effectiveScopePaths,
                    pathGuard: $this->pathGuard
                );
            } else {
                $resolved[$toolName] = $tool;
            }
        }

        return $resolved;
    }

    /**
     * Resolves default allowed tools for a string agent key.
     *
     * @param  array<string, ToolContract>  $allAvailableTools
     * @return array<int, string>
     */
    protected function resolveAllowedToolsForAgentKey(string $agentKey, array $allAvailableTools): array
    {
        $normalized = strtolower(trim($agentKey));

        // Known default agent tool mappings
        $defaults = [
            'business_analyst' => ['file_reader'],
            'domain_rules_agent' => ['file_reader'],
            'product_manager' => ['file_reader'],
            'backlog_splitter_agent' => ['file_reader'],
            'web_scraping_agent' => ['file_reader', 'http_fetch', 'http_dispatch'],
            'architect' => ['file_reader', 'codebase_inspector'],
            'ddd_boundary_agent' => ['file_reader', 'codebase_inspector'],
            'schema_planner_agent' => ['file_reader', 'codebase_inspector'],
            'api_contract_agent' => ['file_reader', 'codebase_inspector'],
            'database_agent' => ['file_reader', 'patch_writer', 'artisan_runner'],
            'migration_agent' => ['file_reader', 'patch_writer', 'artisan_runner'],
            'model_eloquent_agent' => ['file_reader', 'patch_writer', 'artisan_runner'],
            'seeder_agent' => ['file_reader', 'patch_writer', 'artisan_runner'],
            'backend_agent' => ['file_reader', 'patch_writer', 'artisan_runner'],
            'request_validation_agent' => ['file_reader', 'patch_writer', 'artisan_runner'],
            'service_action_agent' => ['file_reader', 'patch_writer', 'artisan_runner'],
            'controller_resource_agent' => ['file_reader', 'patch_writer', 'artisan_runner'],
            'qa_agent' => ['file_reader', 'patch_writer', 'test_runner', 'artisan_runner'],
            'feature_test_agent' => ['file_reader', 'patch_writer', 'test_runner', 'artisan_runner'],
            'fixer_agent' => ['file_reader', 'patch_writer', 'artisan_runner', 'test_runner'],
            'git_agent' => ['git_command', 'file_reader'],
            'reviewer_agent' => ['file_reader', 'git_command', 'patch_writer', 'artisan_runner'],
            'swagger_agent' => ['file_reader', 'patch_writer', 'artisan_runner'],
        ];

        if (isset($defaults[$normalized])) {
            return $defaults[$normalized];
        }

        // If the key is an exact tool name in available tools
        if (isset($allAvailableTools[$agentKey])) {
            return [$agentKey];
        }

        // Wildcard or unknown: return all available tool names
        return array_keys($allAvailableTools);
    }
}
