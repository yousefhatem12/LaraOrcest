<p align="center">
  <img src="art/logo.png" alt="LaraOrcest Logo" width="300" height="300">
</p>

<h1 align="center">LaraOrcest</h1>

<p align="center">
  <strong>Prompt-driven multi-agent orchestration for Laravel with specialized roles, stateful graph execution, automated tests, and safe Git workflows.</strong>
</p>

<p align="center">
  <a href="#vision--core-philosophy">Vision</a> •
  <a href="#built-in-agent-roster">Agent Roster</a> •
  <a href="#directed-graph--workflow-lifecycle">Graph Lifecycle</a> •
  <a href="#installation--setup">Setup</a> •
  <a href="#llm-proxy--litellm-integration">LLM Proxy</a> •
  <a href="#usage--examples">Usage</a> •
  <a href="#sandboxed-tools--security-guardrails">Sandboxed Tools</a> •
  <a href="#configuration-reference">Configuration</a> •
  <a href="./spec.md">Technical Spec</a>
</p>

---

## Vision & Core Philosophy

**`LaraOrcest`** brings role-based multi-agent pods (CrewAI style) and stateful directed graphs with bounded feedback loops (LangGraph style) natively into Laravel applications via Composer—**with zero Python dependencies or external microservices**.

In modern software development, writing high-quality Laravel features requires more than a single LLM prompt. It requires:
1. **Requirements Analysis:** Uncovering domain invariants, authorization boundaries, and edge cases before writing code.
2. **Product Prioritization:** Scoping tasks into incremental slices with verifiable acceptance criteria.
3. **Architectural Planning:** Mapping models, migrations, form requests, controllers, policies, routes, and tests to idiomatic Laravel conventions.
4. **Git Branch Isolation:** Isolating changes on a dedicated feature branch to keep `main` clean and safe.
5. **Database & Backend Engineering:** Creating migrations, relationships, Eloquent models, and form-request-validated endpoints.
6. **Automated QA & Cyclic Bug Fixing:** Writing PHPUnit/Pest tests and iterating in a bounded loop until tests pass.
7. **Staff Reviewer Audit:** Inspecting code diffs against security standards, performance guidelines, and business specifications before committing.

`LaraOrcest` automates this entire lifecycle inside your existing Laravel codebase under strict sandboxed guardrails.

---

## Built-in Agent Roster

`LaraOrcest` includes a fixed roster of **9 specialized agents**, each with dedicated responsibilities, scoped tool access, structured JSON output schemas, and state field ownership:

| Agent Key | Role | Goal | Allowed Tools | Output State Field |
| :--- | :--- | :--- | :--- | :--- |
| **`business_analyst`** | Laravel Domain & Systems Analyst | Analyzes prompt and codebase to produce unambiguous requirements, entities, business rules, and edge cases. | `file_reader` | `requirements_spec` |
| **`product_manager`** | Technical Product Manager | Breaks down the specification into ordered tasks with testable acceptance criteria. | `file_reader` | `backlog` |
| **`architect`** | Senior Laravel Software Architect | Designs clean, idiomatic architecture mapping tasks to migrations, models, controllers, requests, routes, and tests. | `file_reader` | `technical_plan` |
| **`git_agent`** | Source Control & Release Hygiene Agent | Creates and isolates changes on a feature branch (`orcest/...`), verifies clean working trees, and manages milestone commits. | `git_command` | `git_state` |
| **`database_agent`** | Laravel Database & Eloquent Specialist | Generates normalized migrations, factories, relationships, and Eloquent models scoped to `database/` and `app/Models/`. | `file_reader`, `patch_writer`, `artisan_runner` | `artifacts` |
| **`backend_agent`** | Laravel Backend & API Engineer | Implements form requests, controllers, actions, and API routes scoped to `app/`, `routes/`, and `config/`. | `file_reader`, `patch_writer`, `artisan_runner` | `artifacts` |
| **`qa_agent`** | Automated QA & Test Engineer | Writes comprehensive feature/unit tests in `tests/` based on acceptance criteria and executes `php artisan test`. | `file_reader`, `patch_writer`, `test_runner` | `test_results` |
| **`fixer_agent`** | Root-Cause Bug Fixer & Debugger | Diagnoses failing tests or reviewer findings and applies targeted patches within `max_fix_attempts`. | `file_reader`, `patch_writer`, `artisan_runner`, `test_runner` | `artifacts`, `guardrails.fix_attempts` |
| **`reviewer_agent`** | Code Quality & Security Reviewer | Audits changes against requirements, security standards, and test results, approving or requesting revisions. | `file_reader`, `git_command` | `reviews` |

---

## Directed Graph & Workflow Lifecycle

Workflows in `LaraOrcest` execute deterministically through `GraphEngine`, evaluating conditional `EdgeRule` transitions against `RunState`:

```text
  [Start Prompt]
        │
        ▼
 business_analyst ──► product_manager ──► architect ──► git_agent ──► database_agent ──► backend_agent ──► qa_agent
                                                                                                            │
                                              ┌─────────────────────────────────────────────────────────────┘
                                              ▼
                                    [Tests Pass or Dry-run?]
                                     /                    \
                              (yes) /                      \ (no & canAttemptFix)
                                   ▼                        ▼
                            reviewer_agent ◄────────── fixer_agent
                             /          \                  ▲
          (approved)        /            \ (revise & canFix)│
                           ▼              └─────────────────┘
                  [TERMINAL_COMPLETED]
                           │
                 (if not dry_run)
                           ▼
               Automated Milestone Commit
```

### Feedback Loops & Guardrail Bounds
- **QA Feedback Loop:** If `qa_agent` test execution fails and fix budget remains (`fix_attempts < max_fix_attempts`), execution routes to `fixer_agent`. After applying code patches, `fixer_agent` loops back to `qa_agent` to re-run tests.
- **Reviewer Revision Loop:** If `reviewer_agent` determines revisions are needed (`revise`), execution routes to `fixer_agent` and re-runs tests through `qa_agent`.
- **Automatic Reject Safeguard:** If automated tests fail in non-dry-run mode, `reviewer_agent` strictly refuses approval and enforces a revision cycle.
- **Circuit Breaker Limits:** If `fix_attempts` reaches `max_fix_attempts` (default: 3) or graph execution steps exceed `max_steps` (default: 40), the engine terminates with `status = 'failed'`, recording the exact failure reason.

### Parallel & Sub-Graph Execution
`LaraOrcest` supports safe, deterministic concurrent sub-graph and parallel branch execution:
- **`ParallelNode` & `SubGraphNode`:** Embed composite parallel waves or nested child graphs into any workflow.
- **Deterministic Scheduling (`ParallelScheduler`):** Identifies independent tasks whose prerequisites (`depends_on`) are met and whose filesystem scopes (`scope_paths`) do not overlap, sorting by priority and task ID.
- **Git Mutation Serialization:** Git branch creation and milestone commits are isolated in serialized single-task waves with repository-level file locking (`withGitLock`).
- **Safe State Fan-In (`RunState::mergeParallelStates`):** Accumulates token usages, execution steps, and test diagnostics across branches while detecting and halting on conflicting file writes.

### Human-in-the-Loop (HITL) Review & Autonomous Execution
`LaraOrcest` allows development teams to insert explicit human review gates into the pipeline while preserving full autonomous capability:
- **Interactive Review Gates (`--interactive`):**
  - **`after_plan`:** Pauses execution after requirements and architecture design are formulated, allowing developers to review backlog tasks, schemas, and routes before any files or branches are modified.
  - **`before_commit`:** Pauses execution after automated tests pass and code review is signed off, allowing pre-commit audit of diffs before final Git milestone commits.
- **Autonomous Opt-Out (`--no-human-review`):** Users can explicitly bypass all human review gates per run or in configuration, guaranteeing uninterrupted automated execution.
- **Ergonomic CLI Approvals (`orcest:approve`):** Easily approve or reject paused runs with optional feedback notes directly from the command line.

### Project Memory & Codebase Index (Memorilla Architecture)
Inspired by the core architectural principles of Memorilla, `LaraOrcest` provides a persistent codebase knowledge store and index natively adapted for PHP 8.2+ and Laravel:
- **Separation of Indexing from Recall:** The persistent store (`.lara-orcest/memory.json`) maintains structured sections (`codebase_index`, `conventions`, `decisions`, `pitfalls`). Instead of bloating prompts with raw code or entire indices, task- and agent-scoped recall (`recallForContext`) selects only the most relevant, distilled context slice for the active agent and prompt.
- **Distilled Summaries Paired with `FileReaderTool`:** Recalled memory provides high-level architectural index and patterns, while explicitly directing agents to use `FileReaderTool` for exact lines and file inspections.
- **Incremental Growth Across Runs:** As features are built and reviewed, newly created models, controllers, and routes are indexed, technical decisions are archived, and bugs diagnosed/fixed by `FixerAgent` are captured as resolved pitfalls.
- **Deduplication & Secret Sanitization:** Prevents duplicate entries across iterations, caps items to `max_items`, and strictly redacts API keys, bearer tokens, or secrets from disk storage.
- **Context Budget & Opt-Out Controls:** Enforces a configurable character budget (`max_context_chars`, default: 2500) so agent turns stay lean. Can be opted out per-run with `--no-memory` or disabled via `config('lara-orcest.memory.enabled')`. Unaltered during `--dry-run`.

### Web Scraping & Ingestion (`WebScrapingAgent`)
`LaraOrcest` includes an automated web extraction and payload ingestion agent (`web_scraping_agent`) that executes within the graph after `business_analyst` and `product_manager`:
- **Guided by BA & PM Context:** Leverages the domain entities and business rules formulated by `business_analyst` along with acceptance criteria formulated by `product_manager` to extract structured payloads from two external websites.
- **Strict Two-URL Validation:** Requires exactly two valid HTTP/HTTPS URLs (`source_urls`), rejecting missing or invalid URLs early with clear diagnostics.
- **Sanitization & SSRF Guardrails:** Automatically cleans noisy HTML (scripts, styling, SVG, comments, whitespace) and bounds payload characters. Strictly guards against SSRF, loopback, and cloud metadata targets (`169.254.169.254`).
- **Dry-Run & Safe Dispatch:** Constructs the unified structured payload and records artifacts (`web_scraping/payload.json`). In `--dry-run` mode, skips external mutating HTTP requests while saving the preview payload; in standard runs, dispatches the payload via Laravel's `Http` client to the destination endpoint with configurable retries, redacting sensitive authorization headers in persistent state.

---

## Installation & Setup

### Local Package Development (Recommended for Testing)

1. Clone or place `LaraOrcest` alongside your Laravel test application:
   ```text
   projects/
   ├── my-laravel-app/
   └── LaraOrcest/
   ```

2. Link `LaraOrcest` to your Laravel app via Composer's `path` repository in `my-laravel-app/composer.json`:
   ```json
   "repositories": [
       {
           "type": "path",
           "url": "../LaraOrcest",
           "options": {
               "symlink": true
           }
       }
   ],
   "require": {
       "yousef/lara-orcest": "*"
   }
   ```

3. Update Composer dependencies in your Laravel app:
   ```bash
   composer update yousef/lara-orcest
   ```

4. Publish package configuration and database migration:
   ```bash
   php artisan vendor:publish --tag=lara-orcest-config
   php artisan vendor:publish --tag=lara-orcest-migrations
   php artisan migrate
   ```

---

## LLM Proxy & LiteLLM Integration

`LaraOrcest` communicates with AI models using an **OpenAI-compatible HTTP driver** (`OpenAiCompatibleHttpDriver`). This allows pointing directly to any local or self-hosted LLM proxy—such as **[LiteLLM](https://github.com/BerriAI/litellm)**, **vLLM**, **Ollama**, or **OpenRouter**—with **zero Python inside your Laravel application**.

### Connecting to LiteLLM via `.env`

Add the following to your application's `.env`:

```dotenv
# Default LLM driver
LARA_ORCEST_LLM=openai_compatible

# LiteLLM Proxy / OpenAI-Compatible Endpoint
LARA_ORCEST_LLM_BASE_URL=http://localhost:4000/v1
LARA_ORCEST_LLM_API_KEY=sk-litellm-proxy-key
LARA_ORCEST_LLM_MODEL=gpt-4o-mini
LARA_ORCEST_LLM_TIMEOUT=60
LARA_ORCEST_LLM_RETRIES=2

# Per-Agent Model Routing Overrides (Optional)
LARA_ORCEST_MODEL_ARCHITECT=claude-3-5-sonnet
LARA_ORCEST_MODEL_REVIEWER_AGENT=o3-mini
LARA_ORCEST_MODEL_BACKEND_AGENT=gpt-4o-mini
LARA_ORCEST_MODEL_DATABASE_AGENT=gpt-4o-mini
LARA_ORCEST_MODEL_WEB_SCRAPING_AGENT=gpt-4o-mini
```

### Key Architectural Advantages
- **Pure PHP HTTP Stack:** Uses Laravel's native `Illuminate\Support\Facades\Http` with built-in retry budgets, exponential backoff, and JSON schema constraints.
- **Per-Agent Model Routing:** Assign complex reasoning and design agents (`architect`, `reviewer_agent`) to heavy frontier models while routing repetitive coding tasks to fast, cost-effective models.
- **Observability Metadata:** Automatically injects `X-Run-Id`, `X-Agent-Key`, and payload metadata into every request for centralized tracing in LiteLLM, Langfuse, or OpenTelemetry.
- **Zero Secret Leakage:** API keys and credentials are never stored in `RunState` or serialized into checkpoints and are automatically redacted in error messages.

---

## Usage & Examples

### 1. Artisan CLI Commands

#### Build a Feature
```bash
# Build a feature with prompt argument
php artisan orcest:build "Create a task management API with projects, assignments, and status updates"

# Alternative syntax using --prompt flag
php artisan orcest:build --prompt="Add Stripe billing webhook processor"

# Dry-run mode: Preview requirements, backlog, code diffs, and test runs without altering disk or Git
php artisan orcest:build "Add two-factor authentication" --dry-run

# Custom guardrail bounds
php artisan orcest:build "Implement invoice PDF generation" --max-steps=30 --max-fix-attempts=2

# Human-in-the-Loop interactive review (pauses after plan & before commit)
php artisan orcest:build "Create subscription billing" --interactive

# Fully autonomous mode (explicitly bypasses all human review gates)
php artisan orcest:build "Create subscription billing" --no-human-review

# Bypass project memory recall and consolidation for a single run
php artisan orcest:build "Create subscription billing" --no-memory

# Route to a specific configured LLM driver (e.g. fake, openai, anthropic, ollama)
php artisan orcest:build "Create user profile API" --llm=openai_compatible

# Resume an interrupted or paused run from its last checkpoint
php artisan orcest:build --resume=run_a1b2c3d4e5f6
```

#### Approve or Reject a Paused Run
```bash
# Approve a paused run at its active gate and resume execution
php artisan orcest:approve run_a1b2c3d4e5f6

# Approve with feedback or approval note
php artisan orcest:approve run_a1b2c3d4e5f6 --note="Architecture approved by lead"

# Reject and terminate execution cleanly without committing changes
php artisan orcest:approve run_a1b2c3d4e5f6 --reject --note="Missing RBAC authorization policies"
```

#### Inspect Execution Runs & Status
```bash
# List recent execution runs in a formatted table
php artisan orcest:status

# Inspect full details for a specific run ID (artifacts, test results, reviews, tokens, git branch)
php artisan orcest:status run_a1b2c3d4e5f6
```

---

### 2. Facade Interface (`Orcest` or `Orchestrator`)

You can use either `Yousef\LaraOrcest\Facades\Orcest` or `Yousef\LaraOrcest\Facades\Orchestrator`:

```php
use Yousef\LaraOrcest\Facades\Orcest;
use Yousef\LaraOrcest\State\RunState;

// 1. Run a feature request
$state = Orcest::run('Implement blog tag categorization with many-to-many relationship', [
    'dry_run' => false,
    'max_fix_attempts' => 3,
    'max_steps' => 40,
    'on_step' => function (RunState $state, string $node, string $message) {
        logger()->info("[Step {$state->guardrails['step_count']}] Node: {$node} - {$message}");
    },
]);

if ($state->isCompleted()) {
    echo "Feature built successfully!\n";
    echo "Git Feature Branch: {$state->gitState['feature_branch']}\n";
    echo "Artifacts Generated: " . count($state->artifacts) . "\n";
    echo "Total Tokens Used: " . $state->getTotalTokens() . "\n";
} else {
    echo "Run failed at node [{$state->currentNode}]: {$state->getFailureReason()}\n";
}

// 2. Resume an existing run by its ID
$resumedState = Orcest::resume('run_a1b2c3d4e5f6');

// 3. Human-in-the-Loop: Approve or reject a paused run
$approvedState = Orcest::approve('run_a1b2c3d4e5f6', 'Architecture looks good');
// Or reject with feedback:
// $rejectedState = Orcest::reject('run_a1b2c3d4e5f6', 'Requires additional validation');

// 4. Inspect run status programmatically
$runSummary = Orcest::status('run_a1b2c3d4e5f6');
$allRecentRuns = Orcest::status(); // Array of recent run summaries

// 5. Access project memory and codebase index
$memory = Orcest::memory();
$codebaseIndex = $memory->getIndex();
$decisions = $memory->getDecisions();
$pitfalls = $memory->getPitfalls();

// 6. Web Scraping & Ingestion pipeline
$scrapingState = Orcest::run('Extract product catalogs and normalize categories', [
    'web_scraping' => [
        'source_urls' => [
            'https://vendor-alpha.com/products',
            'https://vendor-beta.com/items',
        ],
        'target_endpoint' => 'https://api.my-app.com/api/v1/ingest',
        'method' => 'POST',
        'headers' => [
            'Authorization' => 'Bearer secret-ingest-token',
        ],
        'stop_after_scraping' => true,
    ],
]);
$results = $scrapingState->getScrapingResults();
echo "Extraction status: " . $results['dispatch']['status'] . "\n";
```

---

## Sandboxed Tools & Security Guardrails

`LaraOrcest` enforces strict security boundaries at the tool layer. Prompts are treated as untrusted inputs; tools validate and restrict every disk, command, and Git operation:

### 1. PathGuard & Root Boundary (`PathGuard`)
- Resolves all paths strictly relative to the project root (`base_path()`).
- Rejects path traversal (`../`) and detects symlinks escaping outside the project root.
- **Global Denylist:** Blocks read/write access to sensitive files and directories:
  - Environment: `.env`, `.env.*`
  - Version Control: `.git`, `.git/**`
  - Dependencies: `vendor`, `vendor/**`, `node_modules`, `node_modules/**`
  - Logs & Caches: `storage/logs/**`, `bootstrap/cache/**`, `storage/oauth*`
  - Secrets & Certificates: `*.key`, `*.pem`, `*.crt`
- **Per-Agent Allow-Lists:**
  - `database_agent`: restricted to `database/` and `app/Models/`
  - `backend_agent`: restricted to `app/`, `routes/`, and `config/`
  - `qa_agent`: restricted strictly to `tests/`
  - `fixer_agent`: restricted to `app/`, `database/`, `routes/`, and `tests/`

### 2. File Reader Tool (`FileReaderTool`)
- Safe read-only inspection of single files with line slicing (`offset`, `max_lines`), byte capping (`max_bytes`), and existence checking.
- Directory tree inspection with pattern filtering (e.g. `pattern: "*.php"`), automatically excluding vendor and log directories.

### 3. Patch Writer Tool (`PatchWriterTool`)
- Supports atomic file creation (`write`), targeted search-and-replace (`replace`), and safe file removal (`delete`).
- Search-and-replace verifies that target substrings exist before modification.
- Generates unified diffs (`diff`) and records artifacts into `RunState::$artifacts`.
- Honors `$state->isDryRun()` without mutating disk.

### 4. Artisan Command Runner (`ArtisanRunnerTool`)
- Executes allow-listed commands via `Symfony\Component\Process\Process` (`[PHP_BINARY, 'artisan', $cmd, ...$args]`) inside `projectRoot`.
- **Approved Commands:** `make:migration`, `make:model`, `make:controller`, `make:request`, `make:test`, `make:factory`, `make:seeder`, `make:resource`, `make:policy`, `make:rule`, `make:class`, `make:enum`, `make:interface`, `make:trait`, `route:list`, `migrate:status`, `test`.
- Strictly blocks dangerous flags (`--force`, `--drop-views`, `--drop-types`, `--seed`) and shell metacharacters (`|`, `;`, `&`, `` ` ``, `$`, `<`, `>`, `\n`).

### 5. Automated Test Runner (`TestRunnerTool`)
- Runs test suites via `php artisan test --without-tty` with configurable process timeouts.
- Parses output into structured diagnostics: `passed`, `exit_code`, `duration`, `summary`, `failures`, and `timed_out` (handling exit code 124).

### 6. Git Operations (`GitCommandTool`)
- Restricted fixed commands: `status`, `current_branch`, `branch`, `create_branch`, `diff`, `stage_files`, and `commit`.
- Blocks destructive commands (`push --force`, `reset --hard`, `clean -fd`) and direct commits to protected branches (`main`, `master`, `production`, `staging`).
- Redacts credentials matching `https://*@` to `https://[REDACTED_CREDENTIALS]@`.

---

## State Persistence & Checkpointing

`LaraOrcest` persists execution state after each step and terminal event, allowing runs to be inspected or resumed without losing progress:

- **JSON Driver (Default):** Saves states to `storage/app/orcest/checkpoints/{run_id}.json`. Uses atomic file writes (writing to temporary staging files before renaming) to prevent corruption.
- **Database Driver:** Persists states in the `orcest_runs` database table via Laravel's query builder.
- **Configurable Storage:** Configure driver, file path, and table under `config/lara-orcest.php`:
  ```php
  'checkpoint' => [
      'driver' => env('LARA_ORCEST_CHECKPOINTER', 'json'), // 'json' or 'database'
      'path' => env('LARA_ORCEST_CHECKPOINT_DIR', null),   // defaults to storage/app/orcest/checkpoints
      'table' => 'orcest_runs',
  ],
  ```

---

## Configuration Reference

Key configuration options available in `config/lara-orcest.php`:

```php
return [
    // Enable or disable package globally
    'enabled' => (bool) env('LARA_ORCEST_ENABLED', true),

    // LLM Proxy & Provider settings
    'llm' => [
        'default' => env('LARA_ORCEST_LLM', 'openai_compatible'),
        'drivers' => [
            'openai_compatible' => [
                'base_url' => env('LARA_ORCEST_LLM_BASE_URL', 'https://api.openai.com/v1'),
                'api_key' => env('LARA_ORCEST_LLM_API_KEY', env('OPENAI_API_KEY')),
                'model' => env('LARA_ORCEST_LLM_MODEL', 'gpt-4o-mini'),
                'timeout' => (int) env('LARA_ORCEST_LLM_TIMEOUT', 60),
                'retries' => (int) env('LARA_ORCEST_LLM_RETRIES', 2),
            ],
        ],
        // Per-agent model routing
        'agent_models' => [
            'architect' => env('LARA_ORCEST_MODEL_ARCHITECT'),
            'reviewer_agent' => env('LARA_ORCEST_MODEL_REVIEWER_AGENT'),
            // ...
        ],
    ],

    // Guardrail budgets
    'execution' => [
        'max_steps' => (int) env('LARA_ORCEST_MAX_STEPS', 40),
        'max_fix_attempts' => (int) env('LARA_ORCEST_MAX_FIX_ATTEMPTS', 3),
        'dry_run' => (bool) env('LARA_ORCEST_DRY_RUN', false),
        'process_timeout' => (int) env('LARA_ORCEST_PROCESS_TIMEOUT', 120),
    ],

    // Parallel & sub-graph execution
    'graph' => [
        'parallel' => [
            'enabled' => (bool) env('LARA_ORCEST_PARALLEL_ENABLED', true),
            'max_concurrency' => (int) env('LARA_ORCEST_MAX_CONCURRENCY', 4),
        ],
    ],

    // Human-in-the-Loop review gates
    'human_in_the_loop' => [
        'enabled' => (bool) env('LARA_ORCEST_HITL_ENABLED', false),
        'skip_review' => (bool) env('LARA_ORCEST_SKIP_HUMAN_REVIEW', false),
        'gates' => [
            'after_plan' => (bool) env('LARA_ORCEST_GATE_AFTER_PLAN', true),
            'before_commit' => (bool) env('LARA_ORCEST_GATE_BEFORE_COMMIT', true),
        ],
    ],

    // Project memory & codebase index (Memorilla architecture)
    'memory' => [
        'enabled' => (bool) env('LARA_ORCEST_MEMORY_ENABLED', true),
        'path' => env('LARA_ORCEST_MEMORY_PATH', '.lara-orcest/memory.json'),
        'max_context_chars' => (int) env('LARA_ORCEST_MEMORY_MAX_CHARS', 2500),
        'max_items' => (int) env('LARA_ORCEST_MEMORY_MAX_ITEMS', 15),
        'auto_index' => (bool) env('LARA_ORCEST_MEMORY_AUTO_INDEX', true),
    ],

    // Web scraping & ingestion
    'web_scraping' => [
        'timeout' => (int) env('LARA_ORCEST_SCRAPING_TIMEOUT', 30),
        'retries' => (int) env('LARA_ORCEST_SCRAPING_RETRIES', 2),
        'retry_sleep' => (int) env('LARA_ORCEST_SCRAPING_RETRY_SLEEP', 100),
        'max_response_size' => (int) env('LARA_ORCEST_SCRAPING_MAX_SIZE', 2_000_000),
        'max_content_chars' => (int) env('LARA_ORCEST_SCRAPING_MAX_CHARS', 25_000),
        'allowed_schemes' => ['http', 'https'],
        'allow_private_ips' => (bool) env('LARA_ORCEST_SCRAPING_ALLOW_PRIVATE_IPS', false),
        'default_endpoint' => env('LARA_ORCEST_SCRAPING_DEFAULT_ENDPOINT'),
        'default_method' => env('LARA_ORCEST_SCRAPING_DEFAULT_METHOD', 'POST'),
        'user_agent' => env('LARA_ORCEST_SCRAPING_USER_AGENT', 'LaraOrcest-WebScraper/1.0 (+https://github.com/yousefhatem/LaraOrcest)'),
    ],

    // Git configuration
    'git' => [
        'enabled' => true,
        'feature_branch_prefix' => env('LARA_ORCEST_BRANCH_PREFIX', 'orcest/'),
        'protected_branches' => ['main', 'master', 'production', 'staging'],
        'auto_commit' => true,
        'auto_push' => false,
    ],

    // Tool boundaries and limits
    'tools' => [
        'max_read_size' => 1_000_000, // 1MB
        'deny_paths' => ['.env', '.git', 'vendor', 'node_modules', 'storage/logs', '*.key'],
        'process_timeout' => 120,
    ],

    // Checkpoint persistence
    'checkpoint' => [
        'driver' => env('LARA_ORCEST_CHECKPOINTER', 'json'),
        'path' => env('LARA_ORCEST_CHECKPOINT_DIR', null),
        'table' => 'orcest_runs',
    ],
];
```

---

## Testing & Quality Assurance

`LaraOrcest` maintains a comprehensive test suite covering unit, security, and feature integration:

```bash
# Run all package tests via PHPUnit
./vendor/bin/phpunit

# Run specific test suites
./vendor/bin/phpunit tests/Unit/AgentsExecutionTest.php
./vendor/bin/phpunit tests/Feature/ArtisanCommandsFeatureTest.php
./vendor/bin/phpunit tests/Unit/PathGuardSecurityTest.php
```

---

## License

`LaraOrcest` is open-sourced software licensed under the **[MIT License](LICENSE)**.
