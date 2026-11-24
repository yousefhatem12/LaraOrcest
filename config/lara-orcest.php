<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Package Enabled
    |--------------------------------------------------------------------------
    |
    | Globally enables or disables LaraOrcest agent execution.
    |
    */
    'enabled' => (bool) env('LARA_ORCEST_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | LLM Configuration
    |--------------------------------------------------------------------------
    |
    | Define the default LLM driver, request timeouts, retry budgets,
    | and credentials for supported AI model providers and LLM proxies.
    |
    */
    'llm' => [
        'default' => env('LARA_ORCEST_LLM', 'openai_compatible'),
        'timeout' => (int) env('LARA_ORCEST_LLM_TIMEOUT', 60),
        'max_retries' => (int) env('LARA_ORCEST_LLM_RETRIES', 2),
        'max_tokens' => (int) env('LARA_ORCEST_LLM_MAX_TOKENS', 8000),

        /*
        |--------------------------------------------------------------------------
        | OpenAI-Compatible HTTP Driver & Proxy Configuration (e.g. LiteLLM)
        |--------------------------------------------------------------------------
        |
        | By default, LaraOrcest connects to an OpenAI-compatible HTTP endpoint.
        | This allows directing traffic to any local or remote LLM proxy
        | (such as LiteLLM, vLLM, Ollama, OpenRouter, or native OpenAI)
        | without writing Python or managing Python SDKs in Laravel.
        |
        */
        'drivers' => [
            'openai_compatible' => [
                'driver' => 'openai_compatible',
                'base_url' => env('LARA_ORCEST_LLM_BASE_URL', 'https://api.openai.com/v1'),
                'api_key' => env('LARA_ORCEST_LLM_API_KEY', env('OPENAI_API_KEY')),
                'model' => env('LARA_ORCEST_LLM_MODEL', 'gpt-4o-mini'),
                'timeout' => (int) env('LARA_ORCEST_LLM_TIMEOUT', 60),
                'retries' => (int) env('LARA_ORCEST_LLM_RETRIES', 2),
                'retry_sleep' => 100, // milliseconds
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Per-Agent Model Routing Overrides
        |--------------------------------------------------------------------------
        |
        | Route specific agents to distinct model aliases or specialized reasoning
        | models at the proxy level (e.g. routing 'architect' to 'claude-3-5-sonnet'
        | while 'backend_agent' uses 'gpt-4o-mini').
        |
        */
        'agent_models' => [
            'business_analyst' => env('LARA_ORCEST_MODEL_BUSINESS_ANALYST'),
            'product_manager' => env('LARA_ORCEST_MODEL_PRODUCT_MANAGER'),
            'architect' => env('LARA_ORCEST_MODEL_ARCHITECT'),
            'git_agent' => env('LARA_ORCEST_MODEL_GIT_AGENT'),
            'database_agent' => env('LARA_ORCEST_MODEL_DATABASE_AGENT'),
            'backend_agent' => env('LARA_ORCEST_MODEL_BACKEND_AGENT'),
            'qa_agent' => env('LARA_ORCEST_MODEL_QA_AGENT'),
            'fixer_agent' => env('LARA_ORCEST_MODEL_FIXER_AGENT'),
            'reviewer_agent' => env('LARA_ORCEST_MODEL_REVIEWER_AGENT'),
            'web_scraping_agent' => env('LARA_ORCEST_MODEL_WEB_SCRAPING_AGENT'),
            'swagger_agent' => env('LARA_ORCEST_MODEL_SWAGGER_AGENT'),
            'package_manager_agent' => env('LARA_ORCEST_MODEL_PACKAGE_MANAGER_AGENT'),

            // Discovery & Planning Pod Sub-Agents
            'domain_rules_agent' => env('LARA_ORCEST_MODEL_DOMAIN_RULES_AGENT'),
            'backlog_splitter_agent' => env('LARA_ORCEST_MODEL_BACKLOG_SPLITTER_AGENT'),

            // Architecture Pod Sub-Agents
            'ddd_boundary_agent' => env('LARA_ORCEST_MODEL_DDD_BOUNDARY_AGENT'),
            'schema_planner_agent' => env('LARA_ORCEST_MODEL_SCHEMA_PLANNER_AGENT'),
            'api_contract_agent' => env('LARA_ORCEST_MODEL_API_CONTRACT_AGENT'),

            // Package Manager Pod Sub-Agents
            'laravel_installer_agent' => env('LARA_ORCEST_MODEL_LARAVEL_INSTALLER_AGENT'),
            'dependency_resolver_agent' => env('LARA_ORCEST_MODEL_DEPENDENCY_RESOLVER_AGENT'),
            'structure_scaffolder_agent' => env('LARA_ORCEST_MODEL_STRUCTURE_SCAFFOLDER_AGENT'),

            // Database Pod Sub-Agents
            'migration_agent' => env('LARA_ORCEST_MODEL_MIGRATION_AGENT'),
            'model_eloquent_agent' => env('LARA_ORCEST_MODEL_MODEL_ELOQUENT_AGENT'),
            'seeder_agent' => env('LARA_ORCEST_MODEL_SEEDER_AGENT'),

            // Backend Pod Sub-Agents
            'request_validation_agent' => env('LARA_ORCEST_MODEL_REQUEST_VALIDATION_AGENT'),
            'service_action_agent' => env('LARA_ORCEST_MODEL_SERVICE_ACTION_AGENT'),
            'controller_resource_agent' => env('LARA_ORCEST_MODEL_CONTROLLER_RESOURCE_AGENT'),

            // Quality, Docs & Release Pod Sub-Agents
            'feature_test_agent' => env('LARA_ORCEST_MODEL_FEATURE_TEST_AGENT'),
        ],

        'providers' => [
            'fake' => [
                'driver' => 'fake',
            ],
            'openai' => [
                'driver' => 'openai',
                'api_key' => env('OPENAI_API_KEY'),
                'model' => env('LARA_ORCEST_OPENAI_MODEL', 'gpt-4o'),
                'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            ],
            'anthropic' => [
                'driver' => 'anthropic',
                'api_key' => env('ANTHROPIC_API_KEY'),
                'model' => env('LARA_ORCEST_ANTHROPIC_MODEL', 'claude-3-5-sonnet-20241022'),
                'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com/v1'),
            ],
            'gemini' => [
                'driver' => 'gemini',
                'api_key' => env('GEMINI_API_KEY'),
                'model' => env('LARA_ORCEST_GEMINI_MODEL', 'gemini-2.0-flash'),
                'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
            ],
            'ollama' => [
                'driver' => 'ollama',
                'base_url' => env('OLLAMA_BASE_URL', 'http://127.0.0.1:11434'),
                'model' => env('LARA_ORCEST_OLLAMA_MODEL', 'llama3.3:latest'),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Execution Guardrails & Limits
    |--------------------------------------------------------------------------
    |
    | Guardrails that prevent infinite loops and runaway process execution.
    |
    */
    'execution' => [
        'max_steps' => (int) env('LARA_ORCEST_MAX_STEPS', 40),
        'max_fix_attempts' => (int) env('LARA_ORCEST_MAX_FIX_ATTEMPTS', 3),
        'dry_run' => (bool) env('LARA_ORCEST_DRY_RUN', false),
        'process_timeout' => (int) env('LARA_ORCEST_PROCESS_TIMEOUT', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Parallel & Sub-Graph Execution
    |--------------------------------------------------------------------------
    |
    | Configuration for parallel wave scheduling, task scope conflict resolution,
    | and concurrent sub-graph branch execution.
    |
    */
    'graph' => [
        'parallel' => [
            'enabled' => (bool) env('LARA_ORCEST_PARALLEL_ENABLED', true),
            'max_concurrency' => (int) env('LARA_ORCEST_MAX_CONCURRENCY', 4),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Hierarchical Lead + Sub-Agent Teams (Pods)
    |--------------------------------------------------------------------------
    |
    | Configuration for Lead Agents delegating scoped subtasks to specialized
    | Sub-Agents. Controls parallel execution of sub-agents and concurrency limits.
    |
    */
    'teams' => [
        'enabled' => (bool) env('LARA_ORCEST_TEAMS_ENABLED', true),
        'parallel_sub_agents' => (bool) env('LARA_ORCEST_PARALLEL_SUB_AGENTS', true),
        'max_sub_agents_per_lead' => (int) env('LARA_ORCEST_MAX_SUB_AGENTS_PER_LEAD', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Modular Agent Skills
    |--------------------------------------------------------------------------
    |
    | Configuration for markdown-based agent skills, progressive disclosure,
    | package defaults, and optional custom project overrides in .lara-orcest/skills/.
    |
    */
    'skills' => [
        'enabled' => (bool) env('LARA_ORCEST_SKILLS_ENABLED', true),
        'allow_custom' => (bool) env('LARA_ORCEST_ALLOW_CUSTOM_SKILLS', true),
        'max_active_skills' => (int) env('LARA_ORCEST_MAX_ACTIVE_SKILLS', 5),
        'path' => env('LARA_ORCEST_SKILLS_PATH', null),
        'custom_path' => env('LARA_ORCEST_CUSTOM_SKILLS_PATH', '.lara-orcest/skills'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Human-in-the-Loop (HITL) Review & Approval Gates
    |--------------------------------------------------------------------------
    |
    | Configuration for interactive human review gates where execution pauses
    | for explicit user approval (e.g. after planning, before final git commit).
    | Users can opt out per-run with --no-human-review or skip_review.
    |
    */
    'human_in_the_loop' => [
        'enabled' => (bool) env('LARA_ORCEST_HITL_ENABLED', false),
        'skip_review' => (bool) env('LARA_ORCEST_SKIP_HUMAN_REVIEW', false),
        'gates' => [
            'after_plan' => (bool) env('LARA_ORCEST_GATE_AFTER_PLAN', true),
            'before_commit' => (bool) env('LARA_ORCEST_GATE_BEFORE_COMMIT', true),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Project Memory & Codebase Index (Memorilla Architecture)
    |--------------------------------------------------------------------------
    |
    | Persistent, compact codebase knowledge store inspired by Memorilla.
    | Separates store indexing from task/agent recall, limits prompt bloat
    | with a compact context budget, and pairs distilled summaries with
    | exact file inspection via FileReaderTool.
    |
    */
    'memory' => [
        'enabled' => (bool) env('LARA_ORCEST_MEMORY_ENABLED', true),
        'path' => env('LARA_ORCEST_MEMORY_PATH', '.lara-orcest/memory.json'),
        'max_context_chars' => (int) env('LARA_ORCEST_MEMORY_MAX_CHARS', 2500),
        'max_items' => (int) env('LARA_ORCEST_MEMORY_MAX_ITEMS', 15),
        'auto_index' => (bool) env('LARA_ORCEST_MEMORY_AUTO_INDEX', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Web Scraping & Ingestion Agent Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for WebScrapingAgent fetching target website URLs, extracting
    | structured data guided by BA/PM requirements, and dispatching to endpoints.
    |
    */
    'web_scraping' => [
        'enabled' => (bool) env('LARA_ORCEST_SCRAPE_ENABLED', true),
        'timeout' => (int) env('LARA_ORCEST_SCRAPE_TIMEOUT', 30),
        'retries' => (int) env('LARA_ORCEST_SCRAPE_RETRIES', 2),
        'retry_sleep' => (int) env('LARA_ORCEST_SCRAPE_RETRY_SLEEP', 100), // ms
        'max_response_size' => (int) env('LARA_ORCEST_SCRAPE_MAX_SIZE', 500_000), // 500KB
        'max_content_chars' => (int) env('LARA_ORCEST_SCRAPE_MAX_CHARS', 10_000), // max chars per site in LLM prompt
        'allowed_schemes' => ['http', 'https'],
        'allow_private_ips' => (bool) env('LARA_ORCEST_SCRAPE_ALLOW_PRIVATE_IPS', false),
        'default_endpoint' => env('LARA_ORCEST_SCRAPE_DEFAULT_ENDPOINT', null),
        'target_endpoint' => env('LARA_ORCEST_SCRAPE_DEFAULT_ENDPOINT', null),
        'source_urls' => null,
        'default_method' => env('LARA_ORCEST_SCRAPE_DEFAULT_METHOD', 'POST'),
        'user_agent' => env('LARA_ORCEST_SCRAPE_USER_AGENT', 'Mozilla/5.0 (compatible; LaraOrcestScraper/1.0; +https://github.com/yousefhatem/LaraOrcest)'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Swagger / OpenAPI Documentation Agent Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for SwaggerAgent generating OpenAPI specifications, schemas,
    | parameter definitions, and documentation files for backend endpoints.
    |
    */
    'swagger' => [
        'enabled' => (bool) env('LARA_ORCEST_SWAGGER_ENABLED', true),
        'conditional_activation' => (bool) env('LARA_ORCEST_SWAGGER_CONDITIONAL', false),
        'auto_generate' => (bool) env('LARA_ORCEST_SWAGGER_AUTO_GENERATE', false),
        'title' => env('LARA_ORCEST_SWAGGER_TITLE', 'Laravel Application API Documentation'),
        'version' => env('LARA_ORCEST_SWAGGER_VERSION', '1.0.0'),
        'openapi_version' => env('LARA_ORCEST_SWAGGER_OPENAPI_VERSION', '3.0.3'),
        'output_format' => env('LARA_ORCEST_SWAGGER_FORMAT', 'json'), // 'json', 'yaml', or 'both'
        'output_path' => env('LARA_ORCEST_SWAGGER_PATH', 'docs/swagger.json'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Package Manager Pod & Workspace Bootstrapping
    |--------------------------------------------------------------------------
    |
    | Configuration for the PackageManager Pod (Lead Agent + Sub-Agents)
    | to bootstrap fresh Laravel projects, install packages, and scaffold directories.
    |
    */
    'package_manager' => [
        'enabled' => (bool) env('LARA_ORCEST_PACKAGE_MANAGER_ENABLED', true),
        'auto_bootstrap' => (bool) env('LARA_ORCEST_AUTO_BOOTSTRAP', true),
        'composer_binary' => env('LARA_ORCEST_COMPOSER_BINARY', 'composer'),
        'default_packages' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Git Hygiene & Safety
    |--------------------------------------------------------------------------
    |
    | Configuration for feature branch creation, safety checks, and commits.
    |
    */
    'git' => [
        'enabled' => (bool) env('LARA_ORCEST_GIT_ENABLED', true),
        'feature_branch_prefix' => env('LARA_ORCEST_BRANCH_PREFIX', 'orcest/'),
        'protected_branches' => ['main', 'master', 'production', 'staging'],
        'auto_commit' => (bool) env('LARA_ORCEST_AUTO_COMMIT', true),
        'auto_push' => false,
        'auto_init' => (bool) env('LARA_ORCEST_GIT_AUTO_INIT', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sandboxed Tools Configuration & Guardrails
    |--------------------------------------------------------------------------
    |
    | Defines path boundaries, denylists, allowable Artisan commands, test
    | runners, and execution limits for the Sandboxed Tools layer.
    |
    */
    'tools' => [
        'project_root' => env('LARA_ORCEST_PROJECT_ROOT', null),
        'max_read_size' => (int) env('LARA_ORCEST_MAX_FILE_SIZE', 1_000_000), // 1MB
        'deny_paths' => [
            '.env',
            '.env.*',
            '.git',
            '.git/*',
            'vendor',
            'node_modules',
            'storage/logs',
            'bootstrap/cache',
            'storage/oauth*',
            '*.key',
            '*.pem',
        ],
        'allowed_artisan_commands' => [
            'make:migration',
            'make:model',
            'make:controller',
            'make:request',
            'make:test',
            'make:factory',
            'make:seeder',
            'make:resource',
            'make:policy',
            'make:rule',
            'make:class',
            'make:enum',
            'make:interface',
            'make:trait',
            'route:list',
            'migrate:status',
            'test',
        ],
        'test_command' => null, // null defaults to [PHP_BINARY, 'artisan', 'test', '--without-tty']
        'process_timeout' => (int) env('LARA_ORCEST_PROCESS_TIMEOUT', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sandbox Boundaries (Backwards Compatibility Alias)
    |--------------------------------------------------------------------------
    */
    'sandbox' => [
        'project_root' => env('LARA_ORCEST_PROJECT_ROOT', null),
        'max_file_size' => (int) env('LARA_ORCEST_MAX_FILE_SIZE', 1_000_000), // 1MB
        'deny_paths' => [
            '.env',
            '.env.*',
            '.git',
            '.git/*',
            'vendor',
            'node_modules',
            'storage/logs',
            'bootstrap/cache',
            'storage/oauth*',
            '*.key',
            '*.pem',
        ],
        'allowed_artisan_commands' => [
            'make:migration',
            'make:model',
            'make:controller',
            'make:request',
            'make:test',
            'make:factory',
            'make:seeder',
            'make:resource',
            'make:policy',
            'make:rule',
            'make:class',
            'make:enum',
            'make:interface',
            'make:trait',
            'route:list',
            'migrate:status',
            'test',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Checkpointer Persistence
    |--------------------------------------------------------------------------
    |
    | Persistence driver used to checkpoint run state and allow resume.
    | Supported: 'json', 'database'.
    |
    */
    'checkpointer' => [
        'driver' => env('LARA_ORCEST_CHECKPOINTER', 'json'),
        'json_path' => env('LARA_ORCEST_CHECKPOINT_DIR', null),
        'table' => 'orcest_runs',
    ],

    /*
    |--------------------------------------------------------------------------
    | Checkpoint Configuration Alias
    |--------------------------------------------------------------------------
    */
    'checkpoint' => [
        'driver' => env('LARA_ORCEST_CHECKPOINTER', 'json'),
        'path' => env('LARA_ORCEST_CHECKPOINT_DIR', null),
        'table' => 'orcest_runs',
    ],
];
