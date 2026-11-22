<p align="center">
  <img src="art/logo.png" alt="LaraOrcest Logo" width="300" height="300">
</p>

<h1 align="center">LaraOrcest</h1>

<p align="center">
  <strong>Prompt-driven multi-agent orchestration for Laravel with specialized roles, stateful graph execution, automated tests, Swagger/OpenAPI generation, and safe Git workflows.</strong>
</p>

<p align="center">
  <a href="#vision--core-philosophy">Vision</a> •
  <a href="#built-in-agent-roster">Agent Roster</a> •
  <a href="#directed-graph--workflow-lifecycle">Graph Lifecycle</a> •
  <a href="#key-features">Key Features</a> •
  <a href="#installation--setup">Setup</a> •
  <a href="#llm-proxy--litellm-integration">LLM Proxy</a> •
  <a href="#usage--examples">Usage</a> •
  <a href="#how-to-test-laraorcest">How to Test</a> •
  <a href="#sandboxed-tools--security-guardrails">Sandboxed Tools</a> •
  <a href="#configuration-reference">Configuration</a> •
  <a href="./spec.md">Technical Spec</a>
</p>

---

## Vision & Core Philosophy

**`LaraOrcest`** brings role-based multi-agent pods (CrewAI style) and stateful directed graphs with bounded feedback loops (LangGraph style) natively into Laravel applications via Composer—**with zero Python dependencies or external microservices**.

In modern software development, writing high-quality Laravel features requires more than a single LLM prompt. It requires:
1. **Requirements Analysis:** Uncovering domain invariants, authorization boundaries, and edge cases before writing code.
2. **Product Prioritization:** Scoping requirements into incremental, testable backlog tasks with clear acceptance criteria.
3. **Architectural Planning:** Designing migrations, models, form requests, controllers, policies, routes, and tests conforming to Laravel conventions.
4. **Git Branch Isolation:** Isolating changes on a dedicated feature branch (`orcest/...`) to keep `main` clean and safe.
5. **Database & Backend Engineering:** Generating migrations, Eloquent relationships, and form-request-validated controllers.
6. **Swagger & OpenAPI Documentation:** Creating standardized OpenAPI 3.0+ specifications and API documentation for endpoints.
7. **Automated QA & Cyclic Bug Fixing:** Generating comprehensive feature/unit tests and iterating in a bounded loop until tests pass.
8. **Staff Reviewer Audit:** Inspecting code diffs against PSR-12, security standards, and business specifications before committing.

`LaraOrcest` automates this entire lifecycle inside your existing Laravel codebase under strict sandboxed security guardrails.

---

## Built-in Agent Roster

`LaraOrcest` includes a fixed roster of specialized agents, each with dedicated responsibilities, scoped tool access, structured JSON output schemas, and state field ownership:

| Agent Key | Role | Goal | Allowed Tools | Output State Field |
| :--- | :--- | :--- | :--- | :--- |
| **`business_analyst`** | Laravel Domain & Systems Analyst | Analyzes prompt and codebase to produce unambiguous requirements, entities, business rules, and edge cases. | `file_reader` | `requirements_spec` |
| **`product_manager`** | Technical Product Manager | Breaks down the specification into ordered tasks with testable acceptance criteria. | `file_reader` | `backlog` |
| **`web_scraping_agent`** | Intelligent Web Scraping & Ingestion Specialist | Extracts and normalizes data from target website URLs and dispatches unified payloads to destination endpoints. | `file_reader` | `scraping_results` |
| **`architect`** | Senior Laravel Software Architect | Designs clean, idiomatic architecture mapping tasks to migrations, models, controllers, requests, routes, and tests. | `file_reader` | `technical_plan` |
| **`git_agent`** | Source Control & Release Hygiene Agent | Creates and isolates changes on a feature branch (`orcest/...`), verifies clean working trees, and manages milestone commits. | `git_command` | `git_state` |
| **`database_agent`** | Laravel Database & Eloquent Specialist | Generates normalized migrations, factories, relationships, and Eloquent models scoped to `database/` and `app/Models/`. | `file_reader`, `patch_writer`, `artisan_runner` | `artifacts` |
| **`backend_agent`** | Laravel Backend & API Engineer | Implements form requests, controllers, actions, and API routes scoped to `app/`, `routes/`, and `config/`. | `file_reader`, `patch_writer`, `artisan_runner` | `artifacts` |
| **`swagger_agent`** | Laravel Swagger & OpenAPI Documentation Specialist | Generates comprehensive OpenAPI 3.0+ / Swagger specifications (JSON/YAML) and endpoints documentation scoped to `docs/` and `storage/api-docs/`. | `file_reader`, `patch_writer`, `artisan_runner` | `artifacts`, `metadata.swagger_documentation` |
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
 business_analyst
        │
        ▼
 product_manager ──(if scraping requested)──► web_scraping_agent ──► [Complete or Proceed]
        │                                                                   │
        ▼                                                                   ▼
    architect ◄─────────────────────────────────────────────────────────────┘
        │
        ▼
    git_agent (isolates branch orcest/...)
        │
        ▼
 database_agent (migrations & models)
        │
        ▼
  backend_agent (controllers, form requests & routes)
        │
        ▼
 [Swagger Requested?] ──(yes)──► swagger_agent (OpenAPI 3.0+ specs in docs/)
        │                                  │
        └──────────────(no)────────────────┘
        │
        ▼
     qa_agent (generates tests & runs php artisan test)
        │
        ▼
 [Tests Pass or Dry-Run?]
  /                   \
 / (yes)               \ (no & canAttemptFix)
▼                       ▼
reviewer_agent ◄─── fixer_agent (applies targeted patches & retries QA)
 /           \
/ (approved)  \ (revise & canFix)
▼              └──► fixer_agent
[TERMINAL_COMPLETED]
 │
 ▼ (if not dry_run)
Automated Milestone Commit
```

---

## Key Features

### 1. Swagger & OpenAPI Documentation Agent (`SwaggerAgent`)
Automates complete API documentation for generated and existing Laravel endpoints:
- **Comprehensive OpenAPI 3.0+ Generation:** Inspects the technical plan, backend controllers, form requests, models, and routes to generate OpenAPI 3.0+ documentation in JSON or YAML.
- **Accurate Schemas & Status Codes:** Generates path/query parameters, request body schemas, validation constraints, and standard HTTP response codes (`200`, `201`, `400`, `401`, `403`, `404`, `422`, `500`).
- **Flexible Target Locations:** Outputs specs directly to `docs/swagger.json`, `docs/swagger.yaml`, `storage/api-docs/api-docs.json` (L5-Swagger compliant), or custom paths via `config('lara-orcest.swagger.output_path')`.
- **Intent-Driven & CLI Controls:** Activates automatically when Swagger/OpenAPI keywords are in the prompt (e.g. *"make swagger documentation for backend"*), via `--swagger` in Artisan, or globally via `config('lara-orcest.swagger.auto_generate')`. Bypassed per-run with `--no-swagger`.
- **Dedicated Pipeline:** Use `DefaultGraphBuilder::buildSwaggerPipeline()` for lightweight standalone API documentation generation (`backend_agent -> swagger_agent -> completed`).

### 2. Intelligent Web Scraping & Ingestion (`WebScrapingAgent`)
Automates web data extraction and destination API dispatch:
- **Guided by Business Analysis:** Uses domain entities and acceptance criteria to extract structured payloads from two target websites.
- **Strict Two-URL Validation:** Requires exactly two valid HTTP/HTTPS URLs (`source_urls`), failing fast with clear diagnostics if invalid.
- **Sanitization & SSRF Guardrails:** Strips scripts, styles, SVGs, and comments while bounding payload size. Strictly blocks SSRF, loopback, and cloud metadata targets (`169.254.169.254`).
- **Dry-Run & Safe Dispatch:** Records preview payloads in `--dry-run` mode; dispatches HTTP payloads with configurable retry budgets and redacted headers in standard runs.
- **Dedicated Pipeline:** Use `DefaultGraphBuilder::buildScrapingPipeline()` for standalone scraping runs (`business_analyst -> product_manager -> web_scraping_agent -> completed`).

### 3. Parallel & Sub-Graph Wave Execution
Deterministic concurrent task scheduling for complex features:
- **`ParallelNode` & `SubGraphNode`:** Embed composite parallel waves or nested child graphs into any execution plan.
- **Conflict-Free Scheduling (`ParallelScheduler`):** Identifies independent tasks whose prerequisites (`depends_on`) are satisfied and whose file scopes (`scope_paths`) do not collide.
- **Scope Conflict Detection (`ScopeConflictDetector`):** Detects overlapping file mutations across branches and halts execution before corrupting state.
- **Git Mutation Serialization:** Isolates Git branch creation and commits into serialized single-task waves with file locking (`withGitLock`).
- **Safe State Merging (`RunState::mergeParallelStates`):** Combines token counts, step counts, and test diagnostics across parallel branches cleanly.

### 4. Human-in-the-Loop (HITL) Review & Approval Gates
Balance full automation with developer oversight:
- **Interactive Review Gates (`--interactive`):**
  - **`after_plan`:** Pauses execution after requirements and architecture are designed, allowing developers to audit tasks and routes before files are created.
  - **`before_commit`:** Pauses execution after tests pass and code review succeeds, allowing inspection of diffs before final Git milestone commits.
- **Autonomous Execution (`--no-human-review`):** Bypasses all review gates for automated CI/CD or overnight runs.
- **CLI Approvals (`orcest:approve`):** Review, approve, or reject paused runs with optional feedback notes directly from the command line.

### 5. Project Memory & Codebase Index (Memorilla Architecture)
Persistent project knowledge store that grows with your codebase:
- **Separation of Indexing from Recall:** The persistent store (`.lara-orcest/memory.json`) maintains structured sections (`codebase_index`, `conventions`, `decisions`, `pitfalls`). Instead of bloating prompts with raw code, agent-scoped recall (`recallForContext`) selects only the most relevant context slice.
- **Incremental Growth:** Automatically indexes newly created models, controllers, migrations, and routes. Captures technical decisions and resolves bugs diagnosed by `FixerAgent` as reusable pitfalls.
- **Deduplication & Secret Sanitization:** Caps entries to `max_items` and strictly redacts API keys, tokens, and passwords.
- **Budget Control:** Configurable character budget (`max_context_chars`, default: 2500). Can be bypassed per-run via `--no-memory`.

### 6. Multi-Lingual Mail Templates & Localization
Includes production-ready notification templates and Mailables:
- **Supported Locales:** English (`en`), Hindi (`hi`), and Chinese (`zh`) with JSON translations in `lang/` and `resources/lang/`.
- **Pre-Built Mailables:** `WelcomeMail`, `VerifyAccountMail`, `PasswordResetMail`, `TwoFactorRecoveryMail`, `OtpCodeMail`, `MeetingReminderMail`, and `MeetingNotificationMail`.
- **Responsive Blade Layout:** Standard responsive email layout in `resources/views/emails/layouts/default.blade.php`.

### 7. Sandboxed Tools & Security Guardrails
Prompts are treated as untrusted inputs with strict sandbox boundaries:
- **PathGuard:** Resolves paths strictly inside `projectRoot`, blocks symlink escapes, and enforces global deny patterns (`.env`, `.git`, `vendor`, `node_modules`, `storage/logs`, `*.key`).
- **FileReaderTool:** Safe line slicing (`offset`, `max_lines`) and byte limits (`max_bytes`).
- **PatchWriterTool:** Atomic file creation, search-and-replace, safe deletion, and unified diff generation with dry-run support.
- **ArtisanRunnerTool:** Executes allow-listed `make:*` commands and test commands; blocks dangerous flags (`--force`, `--drop-views`, `--seed`) and shell metacharacters.
- **TestRunnerTool:** Executes `php artisan test --without-tty` with timeouts and structured failure parsing.
- **GitCommandTool:** Manages branches, staging, and commits while blocking destructive commands (`push --force`, `reset --hard`) and direct commits to protected branches (`main`, `master`, `production`, `staging`).

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

### Connecting via `.env`

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
LARA_ORCEST_MODEL_SWAGGER_AGENT=gpt-4o-mini
LARA_ORCEST_MODEL_DATABASE_AGENT=gpt-4o-mini
LARA_ORCEST_MODEL_WEB_SCRAPING_AGENT=gpt-4o-mini
```

---

## Usage & Examples

### 1. Artisan CLI Commands

#### Build a Feature
```bash
# Build a feature from a prompt
php artisan orcest:build "Create a task management API with projects, assignments, and status updates"

# Build with Swagger/OpenAPI documentation generation
php artisan orcest:build "Create customer invoicing API" --swagger

# Bypass Swagger documentation generation
php artisan orcest:build "Create internal queue worker" --no-swagger

# Dry-run mode: Preview requirements, backlog, code diffs, and test runs without altering disk or Git
php artisan orcest:build "Add two-factor authentication" --dry-run

# Interactive Human-in-the-Loop review (pauses after plan & before commit)
php artisan orcest:build "Create subscription billing" --interactive

# Fully autonomous mode (bypasses all human review gates)
php artisan orcest:build "Create subscription billing" --no-human-review

# Bypass project memory recall and consolidation
php artisan orcest:build "Create temporary export" --no-memory

# Bypass web scraping agent even if URLs are present in prompt
php artisan orcest:build "Import products from catalog" --no-scrape

# Set custom guardrail limits
php artisan orcest:build "Implement complex report" --max-steps=30 --max-fix-attempts=2

# Route to a specific configured LLM driver (e.g. fake, openai, anthropic, ollama)
php artisan orcest:build "Create user profile API" --llm=openai_compatible

# Resume an interrupted or paused run from its last checkpoint
php artisan orcest:build --resume=run_a1b2c3d4e5f6
```

#### Approve or Reject a Paused Run
```bash
# Approve a paused run at its active gate and resume execution
php artisan orcest:approve run_a1b2c3d4e5f6

# Approve with an audit or feedback note
php artisan orcest:approve run_a1b2c3d4e5f6 --note="Architecture approved by lead"

# Reject and terminate execution cleanly without committing changes
php artisan orcest:approve run_a1b2c3d4e5f6 --reject --note="Missing RBAC authorization policies"
```

#### Inspect Execution Runs & Status
```bash
# List recent execution runs in a formatted table
php artisan orcest:status

# Inspect full details for a specific run ID (artifacts, tests, reviews, tokens, git branch)
php artisan orcest:status run_a1b2c3d4e5f6
```

---

### 2. Programmatic Usage via Facade (`Orcest` or `Orchestrator`)

```php
use Yousef\LaraOrcest\Facades\Orcest;
use Yousef\LaraOrcest\Graph\DefaultGraphBuilder;
use Yousef\LaraOrcest\State\RunState;

// 1. Run a full feature workflow with Swagger documentation
$state = Orcest::run('Implement order status update API and make swagger documentation for backend', [
    'dry_run' => false,
    'swagger' => true,
    'max_fix_attempts' => 3,
    'on_step' => function (RunState $state, string $node, string $message) {
        logger()->info("[Step {$state->guardrails['step_count']}] Node: {$node} - {$message}");
    },
]);

if ($state->isCompleted()) {
    echo "Feature built successfully!\n";
    echo "Git Branch: {$state->gitState['feature_branch']}\n";
    echo "Artifacts: " . count($state->artifacts) . "\n";
    
    // Check generated Swagger documentation
    if ($state->hasSwaggerDocs()) {
        $spec = $state->getSwaggerSpec();
        echo "OpenAPI Version: {$spec['openapi']}\n";
    }
}

// 2. Standalone Swagger Pipeline (backend_agent -> swagger_agent -> completed)
$swaggerEngine = DefaultGraphBuilder::buildSwaggerPipeline(
    llm: app(\Yousef\LaraOrcest\LLM\LlmManager::class)->driver()
);
$swaggerState = $swaggerEngine->run(RunState::create('Generate OpenAPI documentation for TaskController'));

// 3. Standalone Web Scraping Pipeline (BA -> PM -> WebScraper -> completed)
$scrapingState = Orcest::run('Extract electronics catalog from alpha and beta vendors', [
    'web_scraping' => [
        'source_urls' => ['https://site-a.com/catalog', 'https://site-b.com/catalog'],
        'target_endpoint' => 'https://api.my-app.com/v1/catalog-ingest',
        'method' => 'POST',
        'stop_after_scraping' => true,
    ],
]);
$results = $scrapingState->getScrapingResults();
echo "Scraping Status: " . $results['dispatch']['status'] . "\n";

// 4. Resume, approve, or reject paused runs
$resumedState = Orcest::resume('run_a1b2c3d4e5f6');
$approvedState = Orcest::approve('run_a1b2c3d4e5f6', 'Lead signed off');
$rejectedState = Orcest::reject('run_a1b2c3d4e5f6', 'Needs additional test cases');

// 5. Inspect status and project memory
$allRuns = Orcest::status();
$memory = Orcest::memory();
$codebaseIndex = $memory->getIndex();
```

---

## How to Test LaraOrcest

`LaraOrcest` provides multiple layers of automated testing and developer verification:

### 1. Running the Automated Test Suite (PHPUnit)

Run all package unit and feature tests:

```bash
# Run the complete test suite (236+ tests)
./vendor/bin/phpunit

# Run with testdox format for readable output
./vendor/bin/phpunit --testdox
```

### 2. Testing Specific Feature Suites

You can execute targeted test suites to verify individual subsystems:

```bash
# Test Swagger / OpenAPI Documentation Agent
./vendor/bin/phpunit tests/Unit/SwaggerAgentTest.php
./vendor/bin/phpunit tests/Feature/SwaggerFeatureTest.php

# Test Web Scraping & Ingestion Agent
./vendor/bin/phpunit tests/Unit/WebScrapingAgentTest.php
./vendor/bin/phpunit tests/Feature/WebScrapingFeatureTest.php

# Test Parallel Execution & Scope Conflict Detection
./vendor/bin/phpunit tests/Unit/ParallelExecutionTest.php
./vendor/bin/phpunit tests/Unit/ParallelSchedulerTest.php
./vendor/bin/phpunit tests/Unit/ScopeConflictDetectorTest.php

# Test Human-in-the-Loop Review Gates
./vendor/bin/phpunit tests/Unit/HumanInTheLoopTest.php
./vendor/bin/phpunit tests/Feature/HumanInTheLoopFeatureTest.php

# Test Project Memory & Codebase Index (Memorilla)
./vendor/bin/phpunit tests/Unit/ProjectMemoryStoreTest.php
./vendor/bin/phpunit tests/Feature/ProjectMemoryFeatureTest.php

# Test Multi-Lingual Mail Templates & Localization
./vendor/bin/phpunit tests/Feature/LocalizationAndNotificationTemplatesTest.php

# Test Sandboxed Tools & Security Boundaries
./vendor/bin/phpunit tests/Unit/PathGuardSecurityTest.php
./vendor/bin/phpunit tests/Unit/ArtisanRunnerToolEnhancedTest.php
./vendor/bin/phpunit tests/Unit/GitCommandToolEnhancedTest.php
./vendor/bin/phpunit tests/Unit/PatchWriterToolEnhancedTest.php
./vendor/bin/phpunit tests/Unit/FileReaderToolEnhancedTest.php
./vendor/bin/phpunit tests/Unit/TestRunnerToolEnhancedTest.php

# Test Agent Turn Execution & Error Handling
./vendor/bin/phpunit tests/Unit/AgentsExecutionTest.php
./vendor/bin/phpunit tests/Unit/CyclicFixLoopTest.php
```

### 3. Testing Safely Without LLM API Costs (`--llm=fake`)

Use the built-in deterministic `FakeDriver` to run tests and command simulations with **zero token cost and zero network calls**:

```bash
# Test feature orchestration with fake LLM driver in dry-run mode
php artisan orcest:build "Create user profile API with swagger documentation" --llm=fake --dry-run

# Test Swagger documentation generation with fake driver
php artisan orcest:build "Create customer invoice endpoints" --llm=fake --swagger --dry-run

# Test interactive review gates with fake driver
php artisan orcest:build "Create payment gateway" --llm=fake --interactive --dry-run
```

### 4. Testing in a Real Laravel Application

To test end-to-end integration inside an active Laravel project:

1. **Dry-Run Preview:**
   ```bash
   php artisan orcest:build "Create a Category CRUD with migration, model, controller, and tests" --dry-run
   ```
   Inspect the output to verify:
   - Requirements specification generated by `business_analyst`.
   - Backlog acceptance criteria generated by `product_manager`.
   - Architecture plan generated by `architect`.
   - Code previews for migrations, models, controllers, and tests.

2. **Full Execution (Live Feature Branch):**
   ```bash
   php artisan orcest:build "Create a Category CRUD with migration, model, controller, and tests" --swagger
   ```
   Verify that:
   - Git branch `orcest/category-crud` is created automatically.
   - Migration `database/migrations/*_create_categories_table.php` is generated.
   - Eloquent model `app/Models/Category.php` is created.
   - Controller `app/Http/Controllers/Api/CategoryController.php` is created.
   - Swagger documentation is written to `docs/swagger.json`.
   - Tests in `tests/Feature/CategoryApiTest.php` pass.
   - Milestone commit is recorded cleanly on the feature branch.

---

## Sandboxed Tools & Security Guardrails

`LaraOrcest` enforces strict security boundaries at the tool layer. Prompts are treated as untrusted inputs; tools validate and restrict every disk, command, and Git operation:

### 1. PathGuard & Root Boundary (`PathGuard`)
- Resolves all paths strictly relative to `base_path()`.
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
  - `swagger_agent`: restricted to `docs/`, `storage/api-docs/`, `public/`, `routes/`, and `config/`
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
            'backend_agent' => env('LARA_ORCEST_MODEL_BACKEND_AGENT'),
            'swagger_agent' => env('LARA_ORCEST_MODEL_SWAGGER_AGENT'),
            'database_agent' => env('LARA_ORCEST_MODEL_DATABASE_AGENT'),
            'web_scraping_agent' => env('LARA_ORCEST_MODEL_WEB_SCRAPING_AGENT'),
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

    // Swagger / OpenAPI documentation
    'swagger' => [
        'enabled' => (bool) env('LARA_ORCEST_SWAGGER_ENABLED', true),
        'auto_generate' => (bool) env('LARA_ORCEST_SWAGGER_AUTO_GENERATE', false),
        'title' => env('LARA_ORCEST_SWAGGER_TITLE', 'Laravel Application API Documentation'),
        'version' => env('LARA_ORCEST_SWAGGER_VERSION', '1.0.0'),
        'openapi_version' => env('LARA_ORCEST_SWAGGER_OPENAPI_VERSION', '3.0.3'),
        'output_format' => env('LARA_ORCEST_SWAGGER_FORMAT', 'json'), // 'json', 'yaml', or 'both'
        'output_path' => env('LARA_ORCEST_SWAGGER_PATH', 'docs/swagger.json'),
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
        'enabled' => (bool) env('LARA_ORCEST_SCRAPE_ENABLED', true),
        'timeout' => (int) env('LARA_ORCEST_SCRAPE_TIMEOUT', 30),
        'retries' => (int) env('LARA_ORCEST_SCRAPE_RETRIES', 2),
        'retry_sleep' => (int) env('LARA_ORCEST_SCRAPE_RETRY_SLEEP', 100),
        'max_response_size' => (int) env('LARA_ORCEST_SCRAPE_MAX_SIZE', 500_000),
        'max_content_chars' => (int) env('LARA_ORCEST_SCRAPE_MAX_CHARS', 10_000),
        'allowed_schemes' => ['http', 'https'],
        'allow_private_ips' => (bool) env('LARA_ORCEST_SCRAPE_ALLOW_PRIVATE_IPS', false),
        'default_endpoint' => env('LARA_ORCEST_SCRAPE_DEFAULT_ENDPOINT'),
        'default_method' => env('LARA_ORCEST_SCRAPE_DEFAULT_METHOD', 'POST'),
        'user_agent' => env('LARA_ORCEST_SCRAPE_USER_AGENT', 'Mozilla/5.0 (compatible; LaraOrcestScraper/1.0; +https://github.com/yousefhatem/LaraOrcest)'),
    ],

    // Git hygiene & safety
    'git' => [
        'enabled' => (bool) env('LARA_ORCEST_GIT_ENABLED', true),
        'feature_branch_prefix' => env('LARA_ORCEST_BRANCH_PREFIX', 'orcest/'),
        'protected_branches' => ['main', 'master', 'production', 'staging'],
        'auto_commit' => (bool) env('LARA_ORCEST_AUTO_COMMIT', true),
        'auto_push' => false,
    ],

    // Sandboxed tools boundaries
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

`LaraOrcest` is built with test-driven discipline and maintains 100% passing test suites:

```bash
# Run the complete test suite
./vendor/bin/phpunit
# OK (236 tests, 1207 assertions)
```

---

## License

`LaraOrcest` is open-sourced software licensed under the **[MIT License](LICENSE)**.
