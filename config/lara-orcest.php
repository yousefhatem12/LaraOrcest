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
];
