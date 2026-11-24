# lara_orcest --- Technical Specification & Agent Blueprint

## 1. Overview & Concept

**Package:** `yousefhatem12/lara-orcest`\
**Namespace:** `Yousef\LaraOrcest`\
**Package type:** Standalone Laravel Composer package\
**Purpose:** Bring prompt-driven, multi-agent orchestration into Laravel
applications using PHP and Laravel conventions.

`lara_orcest` combines:

-   **Role-Based Multi-Agent Pod (CrewAI style):** A fixed roster of
    specialized built-in agents. Each agent has an explicit role, goal,
    backstory, allowed tools, and defined state output.
-   **Stateful Directed Graph with Bounded Loops (LangGraph style):** A
    central `RunState` is passed across graph nodes. Conditional
    transitions, test/review feedback loops, and step/retry guardrails
    control execution.
-   **Safe Git & Test Execution:** Work is isolated on a dedicated Git
    branch, committed at verified milestones, and validated with
    automated tests (`php artisan test`). File paths and executable
    commands are restricted.

The package should be provider-agnostic at the LLM layer, supporting
OpenAI, Claude, Gemini, and local model providers through a common
driver contract.

## 2. Package Repository & Laravel Integration Rules

### Repository

-   Maintain `lara-orcest` in its **own standalone repository**.
-   Do not fork or clone `laravel/framework` or `laravel/laravel`.
-   Keep package source, tests, configuration, and Composer metadata
    inside this repository.

### Local development

Link the package to a fresh Laravel test application using a Composer
path repository in the test application's `composer.json`:

``` json
{
  "repositories": [
    {
      "type": "path",
      "url": "../lara-orcest",
      "options": {
        "symlink": true
      }
    }
  ],
  "require": {
    "yousefhatem12/lara-orcest": "*"
  }
}
```

Adjust the relative path to match the local directory layout.

### Auto-discovery

Register the service provider through Composer's Laravel package
discovery metadata:

``` json
{
  "extra": {
    "laravel": {
      "providers": [
        "Yousef\\LaraOrcest\\LaraOrcestServiceProvider"
      ]
    }
  }
}
```

### Runtime targets

-   PHP: `^8.2`
-   Laravel: `^11.0|^12.0`

## 3. System Architecture

``` text
+-------------------------------------------------------------------+
|                     Entry Layer (CLI / Facade)                    |
|   php artisan orcest:build "..."   |   Orchestrator::run(...)   |
+---------------------------------+---------------------------------+
                                  |
                                  v
+-------------------------------------------------------------------+
|                  Graph & State Engine (LangGraph style)           |
|  - Nodes (Agents/Steps)       - Edges (Conditional & Cyclic)      |
|  - RunState (Shared Memory, Plan, Git, Artifacts, Tests, Guardrails)|
|  - Checkpointer (DB / JSON persistence & resume)                  |
+---------------------------------+---------------------------------+
                                  |
             +--------------------+--------------------+
             |                                         |
             v                                         v
+-----------------------------------+     +-------------------------+
|     Fixed Agent Roster (CrewAI)   |     |     Sandboxed Tools      |
|  9 Built-in Agents with strict    | --> | - FileReaderTool        |
|  Role, Goal, Backstory & Tools    |     | - PatchWriterTool       |
+-----------------------------------+     | - ArtisanRunnerTool     |
             |                           | - TestRunnerTool        |
             +---------------------------| - GitCommandTool        |
                                         +-------------------------+
                                  |
                                  v
+-------------------------------------------------------------------+
|                 LLM Driver Layer (OpenAI/Claude/Gemini/Local)     |
|        Structured JSON Output + Token/Step/Retry Guardrails       |
+-------------------------------------------------------------------+
```

**Architecture type:** Hybrid Multi-Agent Pipeline + Bounded Feedback
Loops.

### Main execution flow

1.  **Sequential discovery and planning**
    -   `business_analyst`
    -   `product_manager`
    -   `architect`
2.  **Environment and implementation**
    -   `git_agent` --- create and verify an isolated feature branch.
    -   `database_agent` --- implement database structures and models.
    -   `backend_agent` --- implement application behavior and
        API/backend features.
    -   `qa_agent` --- create and execute tests.
3.  **Bounded correction and review**
    -   QA failures route to `fixer_agent`, then back to `qa_agent`.
    -   The loop is limited by `max_fix_attempts` (default: `3`).
    -   `reviewer_agent` approves the implementation or requests a
        targeted revision from `fixer_agent`.
    -   Finalization is allowed only after required tests pass and
        review is approved.

### Graph flow

``` text
START
  |
  v
business_analyst
  |
  v
product_manager
  |
  v
architect
  |
  v
git_agent
  |
  v
database_agent
  |
  v
backend_agent
  |
  v (when Swagger requested or enabled)
swagger_agent
  |
  v
qa_agent <---------------------+
  |                            |
  | tests fail                 | fix applied; retry QA
  v                            |
fixer_agent -------------------+
  |
  | tests pass
  v
reviewer_agent
  |             |
  | revise      | approved
  v             v
fixer_agent   FINALIZE / COMMIT
```

All graph transitions must be explicit. Conditional edges must inspect
the current `RunState`, and every cycle must be bounded.

## 4. Folder Structure

``` text
lara-orcest/
├── composer.json
├── config/
│   └── lara-orcest.php
├── src/
│   ├── LaraOrcestServiceProvider.php
│   ├── Facades/
│   │   └── Orchestrator.php
│   ├── Contracts/
│   │   ├── AgentContract.php
│   │   ├── GraphNodeContract.php
│   │   ├── ToolContract.php
│   │   ├── LlmDriverContract.php
│   │   └── TaskDefinition.php
│   ├── Agents/
│   │   ├── AbstractAgent.php
│   │   ├── BusinessAnalystAgent.php
│   │   ├── ProductManagerAgent.php
│   │   ├── ArchitectAgent.php
│   │   ├── GitAgent.php
│   │   ├── DatabaseAgent.php
│   │   ├── BackendAgent.php
│   │   ├── QaAgent.php
│   │   ├── FixerAgent.php
│   │   ├── ReviewerAgent.php
│   │   ├── SwaggerAgent.php
│   │   └── WebScrapingAgent.php
│   ├── Graph/
│   │   ├── GraphEngine.php
│   │   ├── EdgeRule.php
│   │   └── Nodes/
│   │       └── AgentGraphNode.php
│   ├── State/
│   │   ├── RunState.php
│   │   └── StateCheckpointer.php
│   ├── Tools/
│   │   ├── FileReaderTool.php
│   │   ├── PatchWriterTool.php
│   │   ├── ArtisanRunnerTool.php
│   │   ├── TestRunnerTool.php
│   │   └── GitCommandTool.php
│   ├── LLM/
│   │   └── LlmManager.php
│   └── Commands/
│       ├── OrcestBuildCommand.php
│       └── OrcestStatusCommand.php
├── database/
│   └── migrations/
└── tests/
```

## 5. Shared State (`RunState`) Schema

Every graph node reads and updates a shared `RunState` instance. The
state must serialize cleanly through `toArray()` and be restored through
`fromArray()`.

  ---------------------------------------------------------------------------
  Field                   Type                    Description
  ----------------------- ----------------------- ---------------------------
  `run_id`                `string`                Unique orchestration run
                                                  identifier.

  `prompt`                `string`                Original user request.

  `current_node`          `string`                Active graph node key.

  `status`                `string`                `running`, `paused`,
                                                  `completed`, or `failed`.

  `requirements_spec`     `array`                 Functional/non-functional
                                                  requirements, domain
                                                  entities, business rules,
                                                  roles, workflows, and edge
                                                  cases. Produced by
                                                  `business_analyst`.

  `backlog`               `array`                 Prioritized implementation
                                                  tasks/milestones with
                                                  explicit acceptance
                                                  criteria. Produced by
                                                  `product_manager`.

  `technical_plan`        `array`                 Laravel files to
                                                  create/update, schemas,
                                                  routes, services, and
                                                  execution order. Produced
                                                  by `architect`.

  `git_state`             `array`                 Base branch, feature branch
                                                  name, commits list, and
                                                  working-tree status.
                                                  Produced by `git_agent`.

  `artifacts`             `array`                 Created/modified files with
                                                  path, action, summary, and
                                                  timestamp.

  `test_results`          `array`                 Latest test status, exit
                                                  code, failure summary, and
                                                  logs. Produced by
                                                  `qa_agent`.

  `reviews`               `array`                 Reviewer decisions
                                                  (`approved` or `revise`),
                                                  findings, and constraints.

  `metadata.swagger_documentation` `array`         Structured OpenAPI/Swagger
                                                  specification, documented
                                                  endpoints, output paths, and
                                                  summary generated by
                                                  `swagger_agent`.

  `guardrails`            `array`                 Step count, maximum steps,
                                                  fix attempts, maximum fix
                                                  attempts, dry-run flag, and
                                                  token usage.

  `scraping_results`      `array`                 Web scraping extraction summary,
                                                  per-source scraped data, unified
                                                  payload, and destination endpoint
                                                  dispatch status. Produced by
                                                  `web_scraping_agent`.
  ---------------------------------------------------------------------------

### State requirements

-   Keep the state structure predictable and versionable.
-   Validate state data when restoring persisted runs.
-   Do not serialize secrets, API keys, or `.env` values into
    checkpoints or logs.
-   Record enough execution metadata to resume safely.
-   Persist state after meaningful node transitions and before/after
    external side effects.
-   Use explicit status transitions and reject invalid transitions.
-   Ensure `run_id` uniquely identifies a run.

## 6. Fixed Built-In Agents

The package ships with nine fixed agents. Each agent has a strict role,
goal, backstory, allowed tools, and defined state output.

### 6.1 Business Analyst (`business_analyst`)

**Role:** Laravel Domain & Business Analyst

**Goal:** Transform a raw user prompt and existing codebase context into
a clear, unambiguous requirements specification covering business rules,
roles/permissions, workflows, and edge cases.

**Backstory:** Experienced product-minded systems analyst who prevents
vague specifications and missing edge cases---such as billing states,
tenant isolation, invalid transitions, and authorization
boundaries---before any code is written.

**Allowed tools:** - `FileReaderTool` --- read-only inspection of
existing project structure and documentation.

**State output:** `requirements_spec`

### 6.2 Product Manager (`product_manager`)

**Role:** Technical Product Manager

**Goal:** Break down `requirements_spec` into ordered, scoped
implementation tasks with concrete, testable acceptance criteria.

**Backstory:** Pragmatic product manager focused on vertical slices,
clear priorities, and verifiable acceptance criteria so engineering and
QA share a single definition of done.

**Allowed tools:** - `FileReaderTool`

**State output:** `backlog`

### 6.3 Architect (`architect`)

**Role:** Senior Laravel Software Architect

**Goal:** Design a clean, idiomatic Laravel technical plan mapping tasks
to migrations, Eloquent models, relationships, form requests,
actions/services, controllers, routes, and tests.

**Backstory:** Deeply familiar with Laravel conventions, service
container bindings, multi-tenancy patterns, query performance, and
modular architecture. Avoids over-engineering while keeping code
maintainable.

**Allowed tools:** - `FileReaderTool`

**State output:** `technical_plan`

### 6.4 Git / Version Control Agent (`git_agent`)

**Role:** Source Control & Release Hygiene Agent

**Goal:** Isolate changes on a dedicated feature branch, create clean
commits after verified milestones, and prevent dirty or untracked
accidental overwrites.

**Backstory:** Disciplined release engineer who treats version control
as a safety net---ensuring every automated change is traceable,
diffable, and easy to revert.

**Allowed tools:** - `GitCommandTool`, restricted to safe branch
creation, status, diff, staging, and scoped commits. - No force-push or
destructive history rewrites on protected branches.

**State output:** `git_state`

### 6.5 Database Agent (`database_agent`)

**Role:** Laravel Database & Eloquent Specialist

**Goal:** Create and update database migrations, foreign keys, indexes,
factories, and Eloquent models with proper relationships, casts, and
scopes.

**Backstory:** Careful data engineer who designs normalized, index-aware
schemas and clean Eloquent models aligned with `technical_plan` and
tenant/ownership rules.

**Allowed tools:** - `FileReaderTool` - `PatchWriterTool`, scoped to
`database/` and `app/Models/` - `ArtisanRunnerTool`, for approved
commands such as `make:migration` and `make:model`

**State output:** `artifacts` (migrations, models, and factories)

### 6.6 Backend / Feature Agent (`backend_agent`)

**Role:** Laravel Backend & API Engineer

**Goal:** Implement controllers, form requests, action/service classes,
policies/middleware, and routes that satisfy the backlog and acceptance
criteria.

**Backstory:** Pragmatic Laravel backend developer who validates inputs
via Form Requests, keeps controllers thin, handles authorization
cleanly, and follows the existing codebase style.

**Allowed tools:** - `FileReaderTool` - `PatchWriterTool`, scoped to
`app/`, `routes/`, and `config/` - `ArtisanRunnerTool`, for approved
commands such as `make:controller` and `make:request`

**State output:** `artifacts` (controllers, requests, services/actions,
and routes)

### 6.7 QA / Test Agent (`qa_agent`)

**Role:** Laravel Automated QA & Test Engineer

**Goal:** Write comprehensive feature and unit tests (Pest or PHPUnit)
directly from backlog acceptance criteria and execute the test suite.

**Backstory:** Thorough test engineer who verifies happy paths,
validation errors, authorization/tenant boundaries, and edge cases
rather than superficial smoke checks.

**Allowed tools:** - `FileReaderTool` - `PatchWriterTool`, scoped to
`tests/` - `TestRunnerTool`, for `php artisan test`

**State output:** `test_results`

### 6.8 Fixer / Debugger Agent (`fixer_agent`)

**Role:** Root-Cause Bug Fixer & Debugger

**Goal:** Diagnose failing tests, runtime exceptions, or review
rejections and apply minimal, targeted fixes within `max_fix_attempts`.

**Backstory:** Calm, methodical debugger who reads stack traces and
diffs carefully, fixes root causes instead of masking errors, and
respects scope limits.

**Allowed tools:** - `FileReaderTool` - `PatchWriterTool` -
`ArtisanRunnerTool` - `TestRunnerTool`

All writes must still comply with the active path allow-list and the
originating task scope.

**State output:** `artifacts`, `test_results`, and updated
`guardrails.fix_attempts`

### 6.9 Reviewer / Guardrail Agent (`reviewer_agent`)

**Role:** Code Quality, Security & Scope Reviewer

**Goal:** Audit all changes against `requirements_spec`, backlog
acceptance criteria, Laravel best practices, and security/scope
guardrails.

**Backstory:** Strict staff-level reviewer who catches missing
authorization checks, SQL/mass-assignment risks, out-of-scope file
edits, and incomplete acceptance criteria before final sign-off.

**Allowed tools:** - `FileReaderTool` - `GitCommandTool`, read-only
diff/status operations.

**State output:** `reviews` (`approved` or `revise`, with actionable
findings)

### 6.10 Web Scraping & Ingestion Agent (`web_scraping_agent`)

**Role:** Web Data Extraction & Ingestion Specialist

**Goal:** Extract structured domain entities from two target website URLs, guided by business rules in `requirements_spec` (Business Analyst) and acceptance criteria in `backlog` (Product Manager), and transmit the validated payload to an external destination endpoint.

**Backstory:** Rigorous data engineer and web extraction specialist who cleans unstructured HTML/text/JSON, normalizes entities into consistent schemas, guards against SSRF / internal IP leakage, redacts sensitive headers, and enforces dry-run safety before dispatching payloads.

**Input Requirements:**
- Exactly two valid HTTP/HTTPS URLs (`source_urls` / `urls`).
- Target destination endpoint (`target_endpoint` / `endpoint_url`) with HTTP method (`POST`, `PUT`, `PATCH`, `GET`) and optional headers.
- Contextual guidance from `requirements_spec` and `backlog`.

**Allowed tools:**
- `FileReaderTool` — inspect workspace schemas and local mapping fixtures.
- Native HTTP Client — safe fetching and dispatching via Laravel's `Illuminate\Support\Facades\Http`.

**Safety & Guardrails:**
- **URL & SSRF Protection:** Validates schemes (`http`/`https`), rejects non-HTTP schemes (e.g., `file://`, `ftp://`), and blocks private/loopback and cloud metadata IPs (`169.254.169.254`, `127.0.0.1`, `10.*`, `192.168.*`, `172.16-31.*`) unless `allow_private_ips` is explicitly set.
- **HTML Sanitization:** Strips `<script>`, `<style>`, `<svg>`, comments, normalizes whitespace, and truncates raw responses to `max_content_chars` (default: 25,000) to keep prompt context bounded.
- **Dry-run Compliance:** In `--dry-run` mode, performs full extraction and constructs the outbound payload without issuing mutating HTTP requests to the target destination.
- **Header Sanitization:** Redacts Authorization, Token, and Secret headers when persisting request summaries in `RunState`.

**State output:** `scraping_results` (source URLs, extraction summary, normalized items, dispatch status, HTTP response status, error logs) and recorded artifact `web_scraping/payload.json`.

### 6.11 Swagger / OpenAPI Documentation Agent (`swagger_agent`)

**Role:** Laravel Swagger & OpenAPI Documentation Specialist

**Goal:** Generate comprehensive, accurate OpenAPI 3.0+ / Swagger specifications, schemas, endpoints, request parameters, response models, and interactive API documentation for backend routes and controllers.

**Backstory:** Dedicated API documentation specialist who meticulously inspects Laravel routes, controllers, form requests, and models to produce rich, interactive OpenAPI/Swagger definitions, ensuring every endpoint has correct HTTP verbs, route paths, path/query parameters, request bodies with validation schemas, response codes (200, 201, 400, 401, 403, 404, 422, 500), and security definitions.

**Input Requirements:**
- Inspected codebase files using `FileReaderTool` (`routes/api.php`, controllers, Form Requests, API Resources).
- Contextual guidance from `requirements_spec`, `backlog`, and `technical_plan`.
- Prior recorded `artifacts` in `RunState`.
- Swagger configuration options (`title`, `version`, `openapi_version`, `output_format`, `output_path`).

**Allowed tools:**
- `FileReaderTool` — inspect implemented routes, controllers, form requests, resources, and existing OpenAPI documentation files to enable incremental updates.
- `PatchWriterTool` — safely write or patch documentation files targeting allow-listed paths (`docs/`, `storage/api-docs/`, `public/`, or configured output path), respecting sandbox boundaries and dry-run guardrails.
- `ArtisanRunnerTool` — inspect registered route lists or trigger local documentation generators if available.

**Safety & Guardrails:**
- **Path Guardrails:** Writes are strictly restricted to allow-listed scopes (`docs/`, `storage/api-docs/`, `public/`, `routes/`, `config/`, `app/` and root `openapi.json` / `swagger.json`).
- **Dry-run Compliance:** In `--dry-run` mode or when `$state->isDryRun()` is active, generates and validates the complete OpenAPI schema in `RunState` and records artifacts without modifying files on disk.
- **Incremental Updates:** Reads existing OpenAPI specs when available and cleanly incorporates new or updated endpoints without clobbering existing configuration.
- **Validation Rules & Error Responses:** Infers request body schemas from Form Requests and documents realistic examples for success (200, 201) and error responses (400, 401, 404, 422).

**State output:** `artifacts` (recorded doc files), `metadata.swagger_documentation` (complete OpenAPI spec, documented endpoints list, output paths, and concise summary).

## 7. Tools Layer

Tools are the only mechanism through which agents inspect or modify the
target Laravel project. Every tool must enforce its own authorization
and scope checks; agent prompts are not a security boundary.

### 7.1 PathGuard (`Support\PathGuard`)

All filesystem-touching tools rely on `PathGuard` to enforce project boundary containment:
- **Canonical resolution:** Resolves all relative paths against `projectRoot` (defaulting to Laravel `base_path()`).
- **Traversal & Symlink protection:** Blocks path traversal (`../`) and detects symlink targets attempting to point outside the workspace.
- **Global Denylist:** Blocks access to sensitive patterns and files:
  - Environment files: `.env`, `.env.*`
  - Version control: `.git`, `.git/**`
  - Dependency caches: `vendor`, `vendor/**`, `node_modules`, `node_modules/**`
  - Application caches & logs: `storage/logs/**`, `bootstrap/cache/**`
  - Secrets & certificates: `*.key`, `*.pem`, `*.crt`
- **Scoped Allow-lists:** Validates that file operations stay within per-tool or per-agent permitted path prefixes (e.g., `app/`, `database/`, `routes/`, `tests/`).

### 7.2 `FileReaderTool`

- **Single file inspection:**
  - Reads text files up to configured `max_read_size` (default 500 KB) or per-call `max_bytes`.
  - Supports line slicing via `offset` and `max_lines`.
  - Detects non-existent paths gracefully (`exists: false`).
  - Returns `path`, `content`, `total_lines`, `size_bytes`, `truncated`, and `exists`.
- **Directory tree discovery:**
  - When given a directory path, returns recursive or shallow entries.
  - Supports glob/regex pattern filtering (e.g. `pattern: "*.php"`).
  - Automatically filters out denied directories (`vendor`, `node_modules`, `.git`, `storage/logs`).

### 7.3 `PatchWriterTool`

- **Actions:**
  - `write`: Creates new files or completely overwrites existing files within allowed prefixes.
  - `replace`: Performs targeted, deterministic search-and-replace using exact string matching (`target`, `replacement`), validating that the target exists before mutating.
  - `delete`: Safely removes files within allowed prefixes.
- **Atomic Operations & Diff Generation:**
  - Automatically generates unified diffs (`+++ b/...`) for all modifications.
  - Writes files atomically using temporary staging files.
- **Dry-Run & State Integration:**
  - Honors `$state->isDryRun()` / `dry_run: true`, returning planned diffs without modifying disk.
  - `applyToState(RunState $state, array $params)` records created/updated/deleted artifacts in `RunState::$artifacts`.

### 7.4 `ArtisanRunnerTool`

- **Process Isolation:**
  - Executes allow-listed commands via `Symfony\Component\Process\Process` (`[PHP_BINARY, 'artisan', $command, ...$args]`) inside `projectRoot`.
  - Never concatenates raw shell strings.
- **Allow-list Enforcement:**
  - Default allowed commands: `make:migration`, `make:model`, `make:controller`, `make:request`, `make:test`, `make:factory`, `make:seeder`, `make:resource`, `make:policy`, `make:rule`, `make:class`, `make:enum`, `make:interface`, `make:trait`, `route:list`, `migrate:status`, `test`.
  - Disallowed commands (e.g. `db:wipe`, `tinker`, `key:generate`) are strictly rejected.
- **Argument & Flag Sanitization:**
  - Blocks dangerous flags: `--force`, `--drop-views`, `--drop-types`, `--seed`.
  - Blocks shell metacharacters: `|`, `;`, `&`, '`', `$`, `<`, `>`, `\n`, `\r`, and null bytes.
- **Dry-run mode:** Returns simulation payload with `'dry_run' => true` without spawning child processes.

### 7.5 `TestRunnerTool`

- **Automated Test Execution:**
  - Executes configured test runner (default: `php artisan test --without-tty`).
  - Configurable execution timeout (default: 120s).
- **Structured Output Parsing:**
  - Returns structured array: `passed` (boolean), `exit_code`, `duration`, `summary`, `output`, `failures` (extracted failure descriptions), and `timed_out` (boolean).
  - Timeouts return `timed_out: true`, `exit_code: 124`, and `passed: false`.
- **Dry-run mode:** Returns simulated test pass payload (`passed: true`, `dry_run: true`).

### 7.6 `GitCommandTool`

- **Safe Fixed Actions:**
  - `status`: Returns `clean` boolean, `branch` name, `modified` files, and `untracked` files.
  - `current_branch`: Returns active branch name.
  - `branch`: Lists local branches.
  - `create_branch`: Validates branch name format (`^[a-zA-Z0-9_\-\/]+$`), blocks creation/checkout of protected branches (`main`, `master`, `production`, `staging`), checks for dirty working tree unless `allow_dirty: true`.
  - `diff`: Returns clean diff with credential redaction.
  - `stage_files` / `add`: Stages files validating each path against `PathGuard`.
  - `commit`: Commits staged changes; strictly blocks committing directly to protected branches.
- **Destructive Command Blocking:**
  - Strictly blocks `push --force`, `push -f`, `reset --hard`, `clean -f`, `clean -fd`, `clean -xdf`.
- **Credential Redaction:**
  - Automatically sanitizes tokens and credentials matching `https://*@` to `https://[REDACTED_CREDENTIALS]@`.
- **State Synchronization:**
  - `applyToState(RunState $state, array $params)` updates `RunState::$gitState` with `feature_branch`, `branch_created`, `commits`, and `status`.

## 8. Graph Engine & Execution Semantics

### 8.1 Core Components

- **`GraphEngine` (`src/Graph/GraphEngine.php`):** The directed graph execution orchestrator. Registers nodes and priority-ordered `EdgeRule` transitions, coordinates checkpoints, evaluates guardrails, and supports clean resume from intermediate states.
- **`EdgeRule` (`src/Graph/EdgeRule.php`):** Represents deterministic or conditional transitions (`fromNode -> toNode`) evaluated against `RunState`. Supports factory constructors (`always()`, `when()`) and priority sorting.
- **`AgentGraphNode` (`src/Graph/Nodes/AgentGraphNode.php`):** Adapts an `AgentContract` to `GraphNodeContract`. Increments execution steps, checks step caps, executes agent logic with assigned sandboxed tools, and triggers checkpoints.
- **`RunState` (`src/State/RunState.php`):** Pure serializable shared state passed through every node. Contains `run_id`, `prompt`, `current_node`, `status`, requirements, backlog, technical plan, git state, artifacts, test results, reviews, and guardrails budgets.
- **`StateCheckpointer` (`src/State/StateCheckpointer.php`):** Persistence manager supporting file-based JSON storage (`JsonCheckpointer`) and relational database storage (`DatabaseCheckpointer`), ensuring safe checkpointing and crash recovery.

### 8.2 Graph Execution Flow & Transitions

```text
business_analyst -> product_manager -> architect -> git_agent -> database_agent -> backend_agent -> qa_agent
                                                                                                        |
                                                            +-------------------------------------------+
                                                            |
                                                            v
                                                  [Tests Pass or Dry-run?]
                                                   /                     \
                                            (yes) /                       \ (no & canAttemptFix)
                                                 v                         v
                                          reviewer_agent <---------- fixer_agent
                                           /          \                   ^
                        (approved)        /            \ (revise & canFix) |
                                         v              +------------------+
                                [TERMINAL_COMPLETED]
                                         |
                                (if not dry_run)
                                         v
                                  Final Git Commit
```

#### Detailed Transition Rules & Priorities

1. **Sequential Foundation:**
   - `business_analyst` -> `product_manager` (unconditional)
   - `product_manager` -> `architect` (unconditional)
   - `architect` -> `git_agent` (unconditional)
   - `git_agent` -> `database_agent` (unconditional)
   - `database_agent` -> `backend_agent` (unconditional)
   - `backend_agent` -> `swagger_agent` (conditional: priority 20 when `shouldRunSwagger()` is true)
   - `backend_agent` -> `qa_agent` (conditional: priority 10 when `shouldRunSwagger()` is false)
   - `swagger_agent` -> `qa_agent` (unconditional)

2. **QA Agent Routing (`qa_agent`):**
   - **Priority 20:** If `areTestsPassing()` or `isDryRun()` -> `reviewer_agent`
   - **Priority 10:** If tests fail AND `canAttemptFix()` -> `fixer_agent`
   - **Priority 5:** If tests fail AND NOT `canAttemptFix()` -> `__failed__`

3. **Fixer Agent Routing (`fixer_agent`):**
   - Transition to `qa_agent` to re-run automated tests on the modified codebase.

4. **Reviewer Agent Routing (`reviewer_agent`):**
   - **Priority 20:** If `isReviewApproved()` -> `__completed__` (triggers automated Git milestone commit when `auto_commit` is enabled)
   - **Priority 10:** If decision is `revise` AND `canAttemptFix()` -> `fixer_agent`
   - **Priority 5:** If decision is `revise` AND NOT `canAttemptFix()` -> `__failed__`

### 8.3 Checkpoint Lifecycle & Resume

- **Save Boundaries:** State is persisted immediately upon run creation, after each node execution, and upon terminal status transition (`completed` or `failed`).
- **Atomic File Writing:** `JsonCheckpointer` writes checkpoints to unique temporary files before atomically renaming them into place to prevent corruption.
- **Crash Recovery & Resume:** Calling `Orchestrator::resume($runId)` restores the state from its last checkpoint, validates guardrails, and resumes execution from `$state->currentNode`.
- **Zero Secret Leakage:** `RunState::toArray()` automatically filters out sensitive keys (`api_key`, `token`, `secret`, `password`, `authorization`) before serialization.

### 8.4 Parallel / Sub-Graph Execution & Deterministic Scheduling

`LaraOrcest` provides safe, deterministic parallel and sub-graph execution for independent, non-conflicting tasks or branches, merging their state updates cleanly before downstream nodes proceed.

#### 1. Architecture & Node Primitives
- **`ParallelNode` (`src/Graph/Nodes/ParallelNode.php`):** Composite graph node encapsulating multiple independent branches. Executes runnable branches across scheduled waves, isolating per-branch outputs in forked `RunState` instances and fanning in via `RunState::mergeParallelStates()`.
- **`SubGraphNode` (`src/Graph/Nodes/SubGraphNode.php`):** Wraps an embedded `GraphEngine` instance as a single node in a parent graph, enabling hierarchical modular pipelines while maintaining checkpointing and step logging.
- **Convenience Engine APIs:** `GraphEngine::executeParallel()`, `GraphEngine::executeParallelTasks()`, and `GraphEngine::runSubGraph()`.

#### 2. Dependency & Scope Conflict Detection
- **Metadata:** Tasks, backlog items, and technical plan items define `depends_on` (prerequisite task IDs) and `scope_paths` (target filesystem resources/prefixes).
- **`ScopeConflictDetector` (`src/Graph/ScopeConflictDetector.php`):**
  - Canonical path normalization (slashes, redundant segments).
  - Exact match overlap detection.
  - Directory containment overlap (`app/Models` contains `app/Models/User.php`).
  - Wildcard / glob matching (`routes/*.php` matches `routes/api.php`).
  - Identifies overlapping mutations across parallel branches.

#### 3. Deterministic Wave Scheduling (`ParallelScheduler`)
- Evaluates candidate tasks whose `depends_on` prerequisites are fully satisfied.
- Sorts candidates deterministically: **Priority descending**, then **Task ID ascending** for 100% reproducible order.
- Greedily selects non-conflicting tasks whose `scope_paths` do not overlap with other tasks in the batch, up to configured `max_concurrency`.
- Tasks with conflicting scopes are serialized into consecutive execution waves.
- Detects circular dependencies via topological DFS traversal and throws `CyclicDependencyException`.

#### 4. Git Mutation Serialization & Repository Locking
- Git operations (`GitAgent` branch creation, file staging, and milestone commits) mutate `.git/index` and the working tree.
- Tasks involving Git mutations are strictly classified as **exclusive**: `ParallelScheduler` isolates them in single-task execution waves so they never run concurrently with other tasks.
- `GitCommandTool` enforces process-level mutual exclusion via `withGitLock()` on `.git/lara_orcest_git.lock`, ensuring working-tree integrity.

#### 5. Safe State Fan-In & Deterministic Merge
- **Branch Isolation:** Each parallel branch executes on a forked state (`RunState::forkBranch()`), tracking branch-specific delta metrics.
- **Artifact Conflict Safety:** `RunState::mergeParallelStates()` verifies that no two branches mutated the same file path. If collisions occur, execution terminates safely with detailed diagnostic messages, or throws `StateMergeConflictException` when strict mode is configured.
- **Guardrails & Token Accumulation:** Delta prompt tokens, completion tokens, costs, execution steps, and fix attempts are accumulated into the parent state without race conditions or lost progress.
- **Test Results & Reviews:** If any branch fails tests, `areTestsPassing()` returns false and all failure outputs are preserved with branch annotations. Reviews from all branches are aggregated chronologically.
- **Backlog Status:** Tasks completed by parallel branches are updated to `status = 'completed'` in the unified backlog.

### 8.5 Human-in-the-Loop (HITL) Review, Pause Gates & Autonomous Opt-Out

`LaraOrcest` provides native **Human-in-the-Loop** governance, allowing development teams to pause execution at critical milestones for human inspection and explicit approval, while empowering users to completely opt out of human review (`--no-human-review`) for fully autonomous execution.

#### 1. Review Gates Architecture
- **`after_plan` (Planning Gate):** Pauses execution immediately after the `architect` produces `requirements_spec`, prioritized `backlog`, and `technical_plan`, before code generation or Git mutations begin. Developers can verify schemas, endpoints, and task slices.
- **`before_commit` (Pre-Commit Gate):** Pauses execution after automated tests pass and `reviewer_agent` sign-off, right before the automated Git milestone commit (`finalizeRun`). Developers inspect generated artifacts and diffs before changes are finalized in Git.
- **Custom Node Gates:** Any graph node can be configured as a pause gate via `after_node:{node_key}`.

#### 2. User-Controlled Review Mode & Opt-Out Guarantees
- **Autonomous by Default:** Human-in-the-loop review is disabled by default (`human_in_the_loop.enabled = false`), ensuring unattended CLI and pipeline runs execute smoothly without pauses.
- **Opt-In Interactivity:** Passing `--interactive` enables pause gates for that specific execution run.
- **Explicit Autonomous Opt-Out (`--no-human-review`):** Passing `--no-human-review` (or configuring `skip_review: true`) guarantees that all human review gates are completely bypassed, even if interactive mode is requested or HITL is globally enabled.

#### 3. State Schema & Decision Tracking
- **Metadata Isolation:** HITL state is persisted in `RunState::$metadata['human_in_the_loop']` with `enabled`, `skip_review`, `gates`, `paused_gate`, `pause_context`, and `decisions`.
- **Review Trail:** Human approval and rejection determinations are recorded in `RunState::$reviews`, preserving feedback and review notes without leaking credentials or environment secrets.
- **Zero Redundant Execution:** When a paused run is approved, execution resumes cleanly from the subsequent node without re-executing already completed nodes.

#### 4. Ergonomic Approval & Rejection Flow
- **CLI Commands:**
  - `php artisan orcest:build "..." --interactive` (runs until first active gate and pauses).
  - `php artisan orcest:approve {run_id} [--note="..."]` (approves active gate and resumes execution).
  - `php artisan orcest:approve {run_id} --reject [--note="..."]` (rejects and halts cleanly with failure status).
  - `php artisan orcest:build --resume={run_id} --approve` (alternative resume syntax).
- **Facade API:**
  - `Orcest::approve(string $runId, ?string $notes = null)`
  - `Orcest::reject(string $runId, ?string $notes = null)`

### 8.6 Project Memory & Codebase Index (Memorilla Architecture)

LaraOrcest implements a persistent, compact project-level memory and codebase index inspired by the architectural principles of Memorilla, natively adapted to PHP 8.2+ and Laravel 11/12 without requiring Python, embeddings, or GPU training.

#### 1. Memorilla-Inspired Core Principles & Adaptation
- **Separation of Indexing from Recall:** The persistent store maintains a structured knowledge base across runs (`codebase_index`, `conventions`, `decisions`, `pitfalls`). Agent prompt construction never dumps raw code or the entire memory store; instead, a deterministic recall method (`recallForContext`) extracts only the most relevant, distilled context slice for the specific agent role and query.
- **Pairing Distilled Summaries with Exact Inspection:** Recalled context informs agents what models, controllers, migrations, and patterns exist while explicitly instructing them to use `FileReaderTool` to inspect exact lines and file contents as needed.
- **Incremental Growth Across Runs:** When a run completes successfully, new artifacts are categorized into `codebase_index`, architectural choices are captured as `decisions`, and root causes resolved by `FixerAgent` are logged as `pitfalls`.
- **Deduplication & Secret Sanitization:** Entries are deduplicated across runs to prevent memory bloat, and all text is sanitized to ensure API keys, bearer tokens, or secrets are never committed to disk.
- **Strict Context Budget:** Recalled memory enforces configurable character limits (`max_context_chars`) and item caps (`max_items`) to keep agent prompts lean, predictable, and cost-effective.

#### 2. Memory Store Schema (`.lara-orcest/memory.json`)
- `codebase_index`: Lightweight mapping of models (`app/Models/`), controllers (`app/Http/Controllers/`), migrations (`database/migrations/`), routes (`routes/`), and key directories.
- `conventions`: Project-specific and idiomatic Laravel conventions (e.g. strict types, thin controllers, FormRequest validation, feature tests).
- `decisions`: Architectural milestone records including run ID, prompt title, plan summary, deliverables, and timestamp.
- `pitfalls`: Failure modes diagnosed and resolved during execution, containing error summary, root cause, fix description, and affected files.
- `metadata`: Versioning, consolidation counts, and update timestamps.

#### 3. Agent & Graph Integration
- **AbstractAgent Context Injection:** Before dispatching an LLM turn, `AbstractAgent` calls `recallForContext($this->getName(), $state->prompt)` and prepends the distilled block to the user prompt.
- **FixerAgent Resolution Recording:** When `FixerAgent` applies patches, the diagnosed root cause and affected files are recorded in run state metadata.
- **GraphEngine Finalization:** When execution reaches terminal completion, `GraphEngine::finalizeRun` calls `ProjectMemoryStore::consolidate($state)` unless running in `dry_run` mode or memory is disabled.
- **Opt-Out Controls:** Memory can be globally disabled via `lara-orcest.memory.enabled = false` or per-run using `--no-memory`. Persistent memory files are never mutated during `--dry-run`.

## 9. Security & Operational Guardrails

### Path allow-lists and denylists

-   All file operations must remain inside the configured Laravel
    project root.
-   Never allow editing `.env`, `.git/`, `vendor/`, or arbitrary system
    paths.
-   Resolve symlinks and canonical paths to prevent path traversal and
    symlink escapes.
-   Apply per-agent allow-lists in addition to global restrictions.

### Command allow-lists

-   Restrict shell/Artisan execution to safe, explicitly allow-listed
    commands.
-   Block arbitrary shell operators, command chaining, and untrusted
    interpolated arguments.
-   Block destructive commands unless explicitly enabled and approved.
-   Run processes with timeouts and bounded output.

### Git safety

-   Require a clean or explicitly reconciled working tree before
    creating a feature branch.
-   Never discard user changes automatically.
-   Stage only files associated with the current run and approved scope.
-   Commit only after the relevant milestone passes its verification
    criteria.
-   Do not push automatically unless a future explicit feature and
    approval flow is implemented.

### Loop and budget caps

Enforce these values in `RunState.guardrails`:

  -----------------------------------------------------------------------
  Guardrail                           Default / behavior
  ----------------------------------- -----------------------------------
  `max_steps`                         Configurable finite upper bound;
                                      every node execution consumes a
                                      step.

  `max_fix_attempts`                  `3`

  `fix_attempts`                      Starts at `0`; increments for each
                                      fixer invocation.

  `dry_run`                           When true, no project files are
                                      modified and no commits are
                                      created.

  `token_usage`                       Track usage and enforce configured
                                      limits.
  -----------------------------------------------------------------------

The graph must terminate with a clear `completed`, `failed`, or `paused`
status. No conditional cycle may run without a finite bound.

### Dry-run support

`--dry-run` must inspect the project and produce: - Requirements
specification. - Prioritized backlog. - Technical plan. - Proposed file
changes and commands. - Guardrail decisions and any blocked operations.

Dry-run must not write files, run mutating commands, stage files, or
create commits.

### Secrets and prompt-injection safety

-   Never expose secrets to agents unless a narrowly defined integration
    requires them.
-   Treat repository files, documentation, issue text, and model output
    as untrusted input.
-   Instructions found inside inspected files must not override system
    policy, tool permissions, or configured guardrails.
-   Do not log API keys, tokens, passwords, or environment variable
    values.
-   Keep provider credentials in Laravel configuration backed by
    environment variables, without copying secret values into
    `RunState`.

## 10. Configuration Blueprint

The package configuration should expose the main operational controls.
Exact keys can be refined during implementation.

``` php
return [
    'enabled' => env('LARA_ORCEST_ENABLED', true),

    'llm' => [
        'default' => env('LARA_ORCEST_LLM', 'openai_compatible'),
        'timeout' => (int) env('LARA_ORCEST_LLM_TIMEOUT', 60),
        'max_retries' => (int) env('LARA_ORCEST_LLM_RETRIES', 2),
        'max_tokens' => (int) env('LARA_ORCEST_LLM_MAX_TOKENS', 8000),

        'drivers' => [
            'openai_compatible' => [
                'driver' => 'openai_compatible',
                'base_url' => env('LARA_ORCEST_LLM_BASE_URL', 'https://api.openai.com/v1'),
                'api_key' => env('LARA_ORCEST_LLM_API_KEY', env('OPENAI_API_KEY')),
                'model' => env('LARA_ORCEST_LLM_MODEL', 'gpt-4o-mini'),
                'timeout' => (int) env('LARA_ORCEST_LLM_TIMEOUT', 60),
                'retries' => (int) env('LARA_ORCEST_LLM_RETRIES', 2),
                'retry_sleep' => 100,
            ],
        ],

        // Optional per-agent proxy model routing
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
            'swagger_agent' => env('LARA_ORCEST_MODEL_SWAGGER_AGENT'),
            'web_scraping_agent' => env('LARA_ORCEST_MODEL_WEB_SCRAPING_AGENT'),
        ],
    ],

    'swagger' => [
        'enabled' => (bool) env('LARA_ORCEST_SWAGGER_ENABLED', true),
        'conditional_activation' => (bool) env('LARA_ORCEST_SWAGGER_CONDITIONAL', false),
        'auto_generate' => (bool) env('LARA_ORCEST_SWAGGER_AUTO_GENERATE', false),
        'title' => env('LARA_ORCEST_SWAGGER_TITLE', 'Laravel Application API Documentation'),
        'version' => env('LARA_ORCEST_SWAGGER_VERSION', '1.0.0'),
        'openapi_version' => env('LARA_ORCEST_SWAGGER_OPENAPI_VERSION', '3.0.3'),
        'output_format' => env('LARA_ORCEST_SWAGGER_FORMAT', 'json'),
        'output_path' => env('LARA_ORCEST_SWAGGER_PATH', 'docs/swagger.json'),
    ],

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

    'execution' => [
        'max_steps' => 40,
        'max_fix_attempts' => 3,
        'dry_run' => false,
        'process_timeout' => 120,
    ],

    'graph' => [
        'parallel' => [
            'enabled' => env('LARA_ORCEST_PARALLEL_ENABLED', true),
            'max_concurrency' => (int) env('LARA_ORCEST_MAX_CONCURRENCY', 4),
        ],
    ],

    'human_in_the_loop' => [
        'enabled' => env('LARA_ORCEST_HITL_ENABLED', false),
        'skip_review' => env('LARA_ORCEST_SKIP_HUMAN_REVIEW', false),
        'gates' => [
            'after_plan' => env('LARA_ORCEST_GATE_AFTER_PLAN', true),
            'before_commit' => env('LARA_ORCEST_GATE_BEFORE_COMMIT', true),
        ],
    ],

    'memory' => [
        'enabled' => env('LARA_ORCEST_MEMORY_ENABLED', true),
        'path' => env('LARA_ORCEST_MEMORY_PATH', '.lara-orcest/memory.json'),
        'max_context_chars' => (int) env('LARA_ORCEST_MEMORY_MAX_CHARS', 2500),
        'max_items' => (int) env('LARA_ORCEST_MEMORY_MAX_ITEMS', 15),
        'auto_index' => (bool) env('LARA_ORCEST_MEMORY_AUTO_INDEX', true),
    ],

    'git' => [
        'enabled' => true,
        'feature_branch_prefix' => 'orcest/',
        'protected_branches' => ['main', 'master'],
        'auto_commit' => true,
        'auto_push' => false,
    ],

    'sandbox' => [
        'project_root' => base_path(),
        'max_file_size' => 1_000_000,
        'deny_paths' => [
            '.env',
            '.git',
            'vendor',
        ],
    ],

    'checkpointer' => [
        'driver' => 'json', // json or database
    ],
];
```

### LiteLLM Proxy Integration (Zero Python in Laravel)

LaraOrcest connects to an OpenAI-compatible HTTP endpoint by default. Developers can run LiteLLM Proxy (`litellm --config config.yaml --port 4000`) and set:
```dotenv
LARA_ORCEST_LLM=openai_compatible
LARA_ORCEST_LLM_BASE_URL=http://localhost:4000/v1
LARA_ORCEST_LLM_API_KEY=sk-litellm-proxy-key
LARA_ORCEST_LLM_MODEL=gpt-4o-mini
LARA_ORCEST_MODEL_ARCHITECT=claude-3-5-sonnet
```
No Python code, sidecars, or Python packages are required inside Laravel. Proxy requests automatically convey `run_id`, `agent_key`, and `node_id` in both HTTP headers and request metadata for tracing in LiteLLM and Langfuse.

Configuration values are examples, not a finalized public API. Validate
them during service-provider boot or package initialization.

## 11. Public API & Artisan Commands

### Artisan

``` bash
php artisan orcest:build "Create a task management API with projects and assignments"
php artisan orcest:build "Add invoice management" --dry-run
php artisan orcest:status
```

Expected command responsibilities:

-   `orcest:build`: Validate the prompt/options, initialize `RunState`,
    execute or preview the graph, and display the final summary.
-   `orcest:status`: Show available run status information without
    exposing secrets.

### Facade

Illustrative usage:

``` php
use Yousef\LaraOrcest\Facades\Orchestrator;

$result = Orchestrator::run(
    'Create a task management API with projects and assignments',
    [
        'dry_run' => true,
        'max_fix_attempts' => 3,
    ]
);
```

The exact return type and facade method signatures should be defined in
the public API before release. Return a structured result containing the
run ID, status, summary, artifacts, test results, review decision, and
safe diagnostics.

## 12. Contracts & Responsibilities

  -----------------------------------------------------------------------
  Contract / class                    Responsibility
  ----------------------------------- -----------------------------------
  `AgentContract`                     Defines agent identity, role
                                      metadata, allowed tools, and
                                      execution interface.

  `GraphNodeContract`                 Defines node identity and state
                                      transition execution.

  `ToolContract`                      Defines a validated tool interface
                                      and tool metadata.

  `LlmDriverContract`                 Defines provider-neutral structured
                                      LLM requests and responses.

  `TaskDefinition`                    Represents a typed task with
                                      inputs, acceptance criteria, and
                                      metadata.

  `AbstractAgent`                     Shared agent behavior, prompt
                                      assembly, output validation, and
                                      tool authorization.

  `LlmManager`                        Resolves the configured LLM driver.

  `GraphEngine`                       Runs the graph and enforces
                                      transition and execution limits.

  `StateCheckpointer`                 Persists and restores safe run
                                      checkpoints.
  -----------------------------------------------------------------------

## 13. Testing Strategy

The package must be tested independently and through a fresh Laravel
test application.

### Unit tests

-   `RunState` serialization, restoration, validation, and schema
    versioning.
-   Graph node execution and conditional edge selection.
-   Step, retry, token, and fix-attempt guardrails.
-   Agent output schema validation.
-   Tool path validation and command allow-list enforcement.
-   Checkpoint persistence and resume validation.

### Security tests

-   Reject `../` path traversal.
-   Reject symlink escapes from the project root.
-   Reject writes to `.env`, `.git/`, and `vendor/`.
-   Reject unapproved Artisan commands and shell metacharacters.
-   Verify protected Git branches cannot be destructively modified.
-   Verify secrets never enter logs, artifacts, or serialized state.
-   Verify prompt-injection content in repository files cannot expand
    tool permissions.

### Integration tests

-   Service-provider registration and package auto-discovery.
-   Facade resolution through Laravel's service container.
-   Artisan command registration.
-   Composer path-repository installation.
-   Database and JSON checkpointer behavior.

### End-to-end tests

Using a fresh Laravel application: 1. Link the package through a
Composer path repository. 2. Run a dry-run orchestration and verify no
files or Git state are changed. 3. Run a controlled feature request in a
disposable test repository. 4. Verify the feature branch is created
safely. 5. Verify expected migrations, models, backend files, and tests
are generated. 6. Verify `php artisan test` is executed and results are
captured. 7. Simulate test failures and confirm the fixer/QA loop stops
at the configured limit. 8. Simulate reviewer rejection and confirm
bounded revision behavior. 9. Verify successful completion and milestone
commits. 10. Verify interrupted runs can be safely resumed.

Use fake LLM drivers and deterministic fixtures for most automated
tests. Keep real-provider tests optional and explicitly configured.

## 14. Suggested Build Sequence with Antigravity

Implement in small, verifiable milestones:

1.  **Package foundation**
    -   Initialize `composer.json`.
    -   Add package namespace/autoloading and Laravel auto-discovery.
    -   Create `config/lara-orcest.php`.
    -   Implement `LaraOrcestServiceProvider.php`.
2.  **Contracts and state**
    -   Implement `Contracts/*`.
    -   Implement `RunState.php`.
    -   Add state validation, serialization, and schema versioning.
    -   Implement `StateCheckpointer.php` with a simple JSON driver
        first, then database persistence.
3.  **Sandboxed tools**
    -   Implement `FileReaderTool`.
    -   Implement `PatchWriterTool`.
    -   Implement `ArtisanRunnerTool`.
    -   Implement `TestRunnerTool`.
    -   Implement `GitCommandTool`.
    -   Add focused security tests before connecting tools to agents.
4.  **LLM layer**
    -   Implement `LlmDriverContract`.
    -   Implement `LlmManager`.
    -   Add a fake deterministic driver for tests.
    -   Add one real provider integration, then expand to Claude,
        Gemini, and local providers.
5.  **Agents**
    -   Implement `AbstractAgent`.
    -   Implement the nine fixed agents.
    -   Define strict output schemas and tool permissions for each
        agent.
6.  **Graph engine**
    -   Implement `EdgeRule`.
    -   Implement `AgentGraphNode`.
    -   Implement `GraphEngine`.
    -   Add bounded QA/fixer and reviewer/fixer transitions.
7.  **Laravel interface**
    -   Implement the `Orchestrator` facade.
    -   Implement `orcest:build`.
    -   Implement `orcest:status`.
    -   Add dry-run and structured status output.
8.  **Integration and release**
    -   Link the package to a fresh Laravel test application using a
        Composer path repository with symlinking enabled.
    -   Run unit, security, integration, and end-to-end tests.
    -   Verify PHP 8.2 and Laravel 11/12 compatibility.
    -   Document installation, configuration, supported drivers,
        limitations, and safe usage.
    -   Tag a version only after the full test suite passes.

## 15. Definition of Done

The first usable release is ready when:

-   [x] The package installs from its standalone Composer repository.
-   [x] Laravel package auto-discovery registers the service provider.
-   [x] The facade and Artisan commands resolve correctly.
-   [x] `RunState` serializes, persists, restores, and validates
    correctly.
-   [x] All nine agents have explicit roles, goals, backstories, tool
    permissions, and output schemas.
-   [x] Graph transitions work and all loops are bounded.
-   [x] Tools enforce project-root, path, command, and Git safety
    boundaries.
-   [x] Dry-run performs no mutations.
-   [x] QA results and reviewer decisions control finalization.
-   [x] Fix attempts and total steps cannot exceed configured limits.
-   [x] Secrets are excluded from state, logs, and artifacts.
-   [x] Unit, security, integration, and end-to-end tests pass on
    supported targets.
-   [x] A fresh Laravel application can use the package through Composer
    path-repository development.

------------------------------------------------------------------------

**Project principle:** Agents propose and perform narrowly authorized
work; the graph controls execution; tools enforce security boundaries;
`RunState` records progress; tests and review determine whether a run
may finish.

------------------------------------------------------------------------

## 16. Modular Skills & Hierarchical Lead + Sub-Agent Teams (Pods) Architecture (Phase 0 Foundation)

### 16.1 Overview & Concept
To scale LaraOrcest to complex, enterprise-grade Laravel codebases without inflating prompt tokens or violating separation of concerns, the framework establishes a hierarchical delegation architecture:
- **Lead Agents (Core 9 Pod Leads):** Govern high-level graph nodes and orchestrate domain workflows.
- **Sub-Agents (`SubAgentInterface`):** Specialized worker agents with strict file directory allow-lists (`scopePaths`), minimal allowed tool sets (`allowedTools`), and explicit sibling dependency ordering (`dependsOn`).
- **Modular Skills (`SkillRegistry`):** Markdown-based domain guidelines featuring progressive disclosure (lightweight summaries for overview, full instructions only when active).
- **Scoped Tool Registry (`ToolRegistry` / `ScopedToolProxy`):** Wraps tools to strictly enforce sub-agent directory boundaries on top of global `PathGuard` security rules.

```
┌────────────────────────────────────────────────────────────────────────┐
│                   GraphEngine (Execution Nodes)                        │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │
                                    ▼
┌────────────────────────────────────────────────────────────────────────┐
│               Lead Agent (e.g. DatabaseAgent / BackendAgent)           │
│  - Receives high-level requirements and technical plan                 │
│  - Consults SkillRegistry for applicable domain skills                 │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │ delegates
                                    ▼
┌────────────────────────────────────────────────────────────────────────┐
│                  TeamDelegator (Wave Scheduler)                        │
│  - Evaluates SubAgent dependsOn() dependencies                        │
│  - Resolves topological waves (Sequential or Parallel)                 │
│  - Checks ScopeConflictDetector to avoid file collisions               │
└───────────────────┬────────────────────────────────┬───────────────────┘
                    │ Wave 0                         │ Wave 1
                    ▼                                ▼
┌──────────────────────────────────────┐  ┌──────────────────────────────┐
│ SubAgent A (MigrationDesigner)       │  │ SubAgent B (ModelBuilder)    │
│ - scopePaths: database/migrations/** │  │ - scopePaths: app/Models/**  │
│ - allowedTools: patch_writer, reader │  │ - allowedTools: patch_writer │
│ - activeSkills: migration-hygiene    │  │ - dependsOn: ['sub_a']       │
└───────────────────┬──────────────────┘  └──────────────┬───────────────┘
                    │                                    │
                    ▼                                    ▼
┌────────────────────────────────────────────────────────────────────────┐
│                  ScopedToolProxy & PathGuard Enforcement               │
│  - Verifies target paths match sub-agent scopePaths                   │
│  - Enforces global PathGuard denylists (.env, .git, vendor)            │
│  - Propagates dry_run and records artifacts with agent attribution     │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │
                                    ▼
┌────────────────────────────────────────────────────────────────────────┐
│                    RunState (Immutable State Tracking)                 │
│  - delegations: sub-agent key, parent lead, status, skills, output    │
│  - artifacts: recorded with sub-agent attribution                      │
│  - token_usage: aggregated across sub-agent executions                 │
└────────────────────────────────────────────────────────────────────────┘
```

### 16.2 Modular Skills Foundation (`src/Skills/`)
Skills provide explicit, structured instructions for coding standards, architectural conventions, and tool usage:
1. **Frontmatter Specification:**
   ```markdown
   ---
   name: laravel-strict-standards
   description: Enforce declare(strict_types=1), typed class properties, and FormRequest validation.
   agents:
     - backend_agent
     - architect
     - fixer_agent
   allowed_tools:
     - file_reader
     - patch_writer
   tags:
     - php
     - standards
   ---
   # Instructions
   ...
   ```
2. **Dual-Layer Loading:**
   - Package defaults: `resources/skills/*.md`
   - Custom project extensions: `.lara-orcest/skills/*.md` (when `lara-orcest.skills.allow_custom` is enabled). Custom skills can extend or override package skills by matching name.
3. **Progressive Disclosure:**
   - **Lightweight Summaries (`buildSummaries`):** Injected into system prompt overview (`name: description`), informing the model of available directives without burning context.
   - **Task-Specific Full Instructions (`buildInstructions`):** Resolved only for requested or active skills relevant to the task, bounded by `max_active_skills` (default: 5).

### 16.3 Hierarchical Sub-Agents & Team Delegation (`src/Agents/Teams/`)
- `SubAgentInterface`: Defines `key()`, `parentAgentKey()`, `role()`, `goal()`, `backstory()`, `scopePaths()`, `allowedTools()`, `dependsOn()`, and `execute()`.
- `AbstractSubAgent`: Base class handling prompt construction, schema validation, tool authorization, and model resolution.
- `TeamDelegator`: Orchestrates sub-agent execution:
  - Topologically sorts sub-agents into sequential or parallel waves based on `dependsOn()`.
  - Detects cyclic dependencies using Tarjan/DFS traversal and throws `CyclicDependencyException`.
  - Enforces per-sub-agent model overrides via `lara-orcest.llm.agent_models.<sub_agent_key>`, falling back to parent agent model, then default.
  - Propagates `dry_run` to prevent mutations during dry runs.
  - Aggregates structured outputs and records artifacts with attribution in `RunState`.

### 16.4 Scoped Tool Registry (`src/Tools/`)
- `ToolRegistry`: Manages tool instances and allows registering custom domain-specific closures via `registerFunction()`.
- `ScopedToolProxy`: Intercepts calls to underlying tools, validating target file paths against sub-agent `scopePaths()` using glob and prefix matching. Any operation targeting files outside assigned scopes throws a `RuntimeException`.

### 16.5 State & Configuration
- `RunState::$delegations`: Records sub-agent execution summaries, parent lead agent, active skills, status, output, and timestamp.
- `RunState::$artifacts`: Records optional `'agent'` attribution key identifying the generating sub-agent or lead agent.
- `config/lara-orcest.php`:
  - `teams.enabled` (bool, default `true`)
  - `teams.parallel_sub_agents` (bool, default `true`)
  - `teams.max_sub_agents_per_lead` (int, default `5`)
  - `skills.enabled` (bool, default `true`)
  - `skills.allow_custom` (bool, default `true`)
  - `skills.max_active_skills` (int, default `5`)

### 16.6 Pod Implementation Roadmap (Phases 1–5)
- **Phase 0 (Core Foundation - Completed):** `Skill`, `SkillRegistry`, `SubAgentInterface`, `AbstractSubAgent`, `SubAgentRegistry`, `TeamDelegator`, `ToolRegistry`, `ScopedToolProxy`, and `RunState` delegation tracking.
- **Phase 1 (Discovery & Planning Pod - Completed):** `BusinessAnalystAgent` & `ProductManagerAgent` as Lead Agents with `DomainRulesAgent` (`domain_rules_agent`), `BacklogSplitterAgent` (`backlog_splitter_agent`), and `WebScrapingSubAgent` (`web_scraping_agent`), supported by `HttpFetchTool` and `HttpDispatchTool`.
- **Phase 2 (Architecture Pod):** `ArchitectAgent` sub-agents (`ComponentDesigner`, `EventFlowPlanner`, `InterfaceContractAuthor`).
- **Phase 3 (Database Pod):** `DatabaseAgent` sub-agents (`MigrationDesigner`, `SchemaValidator`, `SeederBuilder`, `IndexOptimizer`).
- **Phase 4 (Backend Pod):** `BackendAgent` sub-agents (`RoutePlanner`, `ControllerBuilder`, `FormRequestBuilder`, `PolicyDesigner`, `ResourceTransformerBuilder`).
- **Phase 5 (Quality & Release Pod):** `QaAgent`, `FixerAgent`, and `ReviewerAgent` sub-agents (`UnitTestAuthor`, `RegressionTester`, `SecurityAuditor`, `ChangelogBuilder`).

------------------------------------------------------------------------

## 17. Discovery & Planning Pod (Phase 1 Specification)

### 17.1 Pod Overview & Structure
The **Discovery & Planning Pod** establishes the domain boundaries, specifications, and ordered engineering backlog for any requested feature or application before code generation begins:

```
┌────────────────────────────────────────────────────────────────────────┐
│                      Discovery & Planning Pod                          │
├───────────────────────────────────┬────────────────────────────────────┤
│  Lead Agent: BusinessAnalystAgent │  Lead Agent: ProductManagerAgent   │
│  - Requirements specification     │  - Ordered implementation backlog  │
│  - Domain model identification    │  - Vertical task slicing           │
│  - Active Skill:                  │  - Active Skill:                   │
│    business-requirements-analysis │    backlog-task-decomposition      │
└─────────────────┬─────────────────┴──────────────────┬─────────────────┘
                  │ delegates                          │ delegates
                  ▼                                    ▼
┌───────────────────────────────────┐┌───────────────────────────────────┐
│ Sub-Agent: DomainRulesAgent       ││ Sub-Agent: BacklogSplitterAgent   │
│ - Key: domain_rules_agent         ││ - Key: backlog_splitter_agent     │
│ - Parent: business_analyst        ││ - Parent: product_manager         │
│ - Focus: invariants, edge cases,  ││ - Focus: depends_on graph,        │
│   state machine transitions       ││   directory scope_paths           │
│ - Allowed Tools: file_reader      ││ - Allowed Tools: file_reader      │
│ - Scope Paths: ['docs/**']        ││ - Scope Paths: ['docs/**']        │
└───────────────────────────────────┘└─────────────────┬─────────────────┘
                                                       │ conditional
                                                       ▼
                                     ┌───────────────────────────────────┐
                                     │ Sub-Agent: WebScrapingSubAgent    │
                                     │ - Key: web_scraping_agent         │
                                     │ - Parent: product_manager         │
                                     │ - Focus: 2-site data extraction,  │
                                     │   normalization, & HTTP dispatch  │
                                     │ - Active Skill:                   │
                                     │   two-site-scraping-dispatch      │
                                     │ - Allowed Tools:                  │
                                     │   file_reader, http_fetch,        │
                                     │   http_dispatch                   │
                                     │ - Scope Paths:                    │
                                     │   ['docs/**', 'web_scraping/**']  │
                                     └───────────────────────────────────┘
```

### 17.2 Dedicated Skills & Progressive Disclosure
1. **`business-requirements-analysis.md`:**
   - Injected into `business_analyst` and `domain_rules_agent`.
   - Directs the extraction of functional requirements, non-functional requirements, domain invariants, entity life-cycle state machines, and boundary edge cases.
2. **`backlog-task-decomposition.md`:**
   - Injected into `product_manager` and `backlog_splitter_agent`.
   - Enforces atomic task breakdown with verifiable acceptance criteria, explicit `depends_on` task graphs (preventing deadlocks and enabling parallel wave execution), and least-privilege `scope_paths` allow-lists (e.g. `['database/migrations/**']`).
3. **`two-site-scraping-dispatch.md`:**
   - Injected into `web_scraping_agent` and `product_manager`.
   - Guides extraction from two source websites, schema mapping to BA/PM criteria, SSRF protection, sensitive header redaction, and `dry_run` preview safety.

### 17.3 Safe Tools (`HttpFetchTool` & `HttpDispatchTool`)
- **`HttpFetchTool` (`http_fetch`):**
  - Fetches and sanitizes HTML or JSON content from a target URL.
  - Enforces SSRF guardrails: blocks loopback (`127.0.0.1`, `localhost`) and cloud metadata services (`169.254.169.254`, `metadata.google.internal`) unless `lara-orcest.web_scraping.allow_private_ips` is explicitly enabled.
  - Cleans HTML markup (stripping scripts, styles, SVGs, and comments) to conserve prompt context tokens.
- **`HttpDispatchTool` (`http_dispatch`):**
  - Dispatches normalized JSON payloads to an external HTTP destination endpoint via `POST`, `PUT`, `PATCH`, or `GET`.
  - Implements `setDryRun(bool $dryRun)`: in `dry_run` mode, returns a structured preview without making live external HTTP calls.
  - Redacts sensitive authentication tokens and headers in logs and returned artifacts.

### 17.4 Lead Agent Delegation & Fallback Flow
- When `lara-orcest.teams.enabled` is `true`:
  - `BusinessAnalystAgent` delegates to `domain_rules_agent`, enriching its requirements specification with domain invariants and edge cases.
  - `ProductManagerAgent` delegates to `backlog_splitter_agent`, producing tasks with structured `depends_on` and `scope_paths`.
  - Delegations are recorded in `RunState::$delegations` with sub-agent keys, status, active skills, and summaries.
- When `lara-orcest.teams.enabled` is `false`:
  - Both agents gracefully fall back to direct single-turn execution, ensuring full backward compatibility.

## 18. Phase 2 — Architecture Pod (`architect`, `ddd_boundary_agent`, `schema_planner_agent`, `api_contract_agent`)

### 18.1 Pod Architecture & Hierarchy
The Architecture Pod translates business requirements and backlog stories into a robust, idiomatic Laravel technical implementation plan.

```
┌────────────────────────────────────────────────────────────────────────┐
│                          Architecture Pod                              │
├────────────────────────────────────────────────────────────────────────┤
│  Lead Agent: ArchitectAgent                                            │
│  - Synthesizes technical_plan in RunState                              │
│  - Active Skills: laravel-modular-ddd-architecture,                    │
│    relational-schema-indexing, rest-api-contract-design                │
│  - Allowed Tools: file_reader, codebase_inspector                      │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │ delegates in parallel waves
         ┌──────────────────────────┼──────────────────────────┐
         ▼                          ▼                          ▼
┌─────────────────────────┐┌─────────────────────────┐┌─────────────────────────┐
│ Sub-Agent:              ││ Sub-Agent:              ││ Sub-Agent:              │
│ DddBoundaryAgent        ││ SchemaPlannerAgent      ││ ApiContractAgent        │
│ - Key:                  ││ - Key:                  ││ - Key:                  │
│   ddd_boundary_agent    ││   schema_planner_agent  ││   api_contract_agent    │
│ - Parent: architect     ││ - Parent: architect     ││ - Parent: architect     │
│ - Focus: Bounded        ││ - Focus: Normalized     ││ - Focus: REST endpoints,│
│   contexts, layers,     ││   tables, migrations,   ││   controllers, Form-    │
│   services, tenant-     ││   models, relationships,││   Requests, Resources,  │
│   isolation invariants  ││   indexes, audit cols   ││   policies, api routes  │
│ - Allowed Tools:        ││ - Allowed Tools:        ││ - Allowed Tools:        │
│   file_reader,          ││   file_reader,          ││   file_reader,          │
│   codebase_inspector    ││   codebase_inspector    ││   codebase_inspector    │
│ - Scope: ['docs/**']    ││ - Scope: ['docs/**']    ││ - Scope: ['docs/**']    │
└─────────────────────────┘└─────────────────────────┘└─────────────────────────┘
```

### 18.2 Dedicated Architectural Skills
1. **`laravel-modular-ddd-architecture.md`:**
   - Injected into `architect` and `ddd_boundary_agent`.
   - Guides bounded context separation, application services/actions, domain models, and tenant isolation patterns while maintaining Laravel simplicity.
2. **`relational-schema-indexing.md`:**
   - Injected into `architect` and `schema_planner_agent`.
   - Dictates strict column typing, foreign key cascade behaviors, composite indexing for performance, and chronological migration sequencing.
3. **`rest-api-contract-design.md`:**
   - Injected into `architect` and `api_contract_agent`.
   - Standardizes RESTful endpoint design, FormRequest validation rules, JsonResource envelope transformers, policy gates, and OpenAPI 3.0 error schemas.

### 18.3 Read-Only Inspection Tool (`CodebaseInspectorTool`)
- **`CodebaseInspectorTool` (`codebase_inspector`):**
  - Read-only codebase explorer enforcing `PathGuard` and least privilege.
  - Supports operations:
    - `list_directory`: safe traversal of existing project structure.
    - `inspect_models`: lists existing Eloquent models in `app/Models/` or custom directories.
    - `inspect_migrations`: inspects existing migrations in `database/migrations/` to prevent timestamp conflicts.
    - `inspect_routes`: scans route declarations in `routes/` to prevent URI collisions.
    - `search_symbols`: searches classes and interfaces across permitted directories.
  - Guarantees zero file mutations during the architectural design phase.

### 18.4 Synthesis & Plan Assembly
- `ArchitectAgent` delegates to all three architectural sub-agents in parallel wave execution (when `teams.parallel_sub_agents` is enabled).
- Synthesizes outputs into `RunState::$technicalPlan`:
  - `migrations`: chronological list from `schema_planner_agent`.
  - `models`: merged models from schema planner and DDD boundary agent.
  - `controllers`, `requests`, `resources`, `policies`, `routes`: from `api_contract_agent`.
  - `bounded_contexts`, `directory_structure`, `domain_services`: from `ddd_boundary_agent`.
  - `execution_steps`: ordered workflow steps guiding downstream Database and Backend agents.
- Automatically falls back to single-turn prompt execution if `lara-orcest.teams.enabled` is `false`.

## 19. Phase 3 — Database Pod (`database_agent`, `migration_agent`, `model_eloquent_agent`, `seeder_agent`)

### 19.1 Pod Architecture & Sequential Wave Order
The Database Pod transforms the `technical_plan` schema specifications into production-ready migrations, strictly-typed Eloquent models, model factories, and idempotent seeders. Because models depend on migrations, and seeders depend on models, the sub-agents declare strict dependency relationships executed in sequential waves.

```
┌────────────────────────────────────────────────────────────────────────┐
│                            Database Pod                                │
├────────────────────────────────────────────────────────────────────────┤
│  Lead Agent: DatabaseAgent                                             │
│  - Aggregates database artifacts into RunState                         │
│  - Active Skills: laravel-safe-migrations,                             │
│    eloquent-models-and-factories, idempotent-database-seeders           │
│  - Allowed Tools: file_reader, patch_writer, artisan_runner            │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │ sequential waves (dependsOn)
                                    ▼
┌────────────────────────────────────────────────────────────────────────┐
│ Wave 0: MigrationAgent (key: migration_agent)                          │
│ - dependsOn: []                                                        │
│ - scopePaths: ['database/migrations/**']                               │
│ - allowedTools: ['file_reader', 'patch_writer', 'artisan_runner']      │
│ - Role: Anonymous migrations, foreign keys, indexes, reversible down() │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │ completes
                                    ▼
┌────────────────────────────────────────────────────────────────────────┐
│ Wave 1: ModelEloquentAgent (key: model_eloquent_agent)                 │
│ - dependsOn: ['migration_agent']                                       │
│ - scopePaths: ['app/Models/**', 'app/Domains/**/Models/**',            │
│                'database/factories/**']                                │
│ - allowedTools: ['file_reader', 'patch_writer', 'artisan_runner']      │
│ - Role: Strictly typed Eloquent models, relations, casts, factories    │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │ completes
                                    ▼
┌────────────────────────────────────────────────────────────────────────┐
│ Wave 2: SeederAgent (key: seeder_agent)                                │
│ - dependsOn: ['model_eloquent_agent']                                  │
│ - scopePaths: ['database/seeders/**']                                  │
│ - allowedTools: ['file_reader', 'patch_writer', 'artisan_runner']      │
│ - Role: Idempotent seeders (firstOrCreate), DatabaseSeeder composition │
└────────────────────────────────────────────────────────────────────────┘
```

### 19.2 Dedicated Database Skills
1. **`laravel-safe-migrations.md`:**
   - Injected into `database_agent` and `migration_agent`.
   - Enforces anonymous migration classes, strict typing, explicit column nullability/defaults, foreign key constraints with cascades, composite indexing, and reversible rollback symmetry in `down()`.
2. **`eloquent-models-and-factories.md`:**
   - Injected into `database_agent` and `model_eloquent_agent`.
   - Enforces strict typing, `$fillable` protection, typed relationship return types (`BelongsTo`, `HasMany`, etc.), attribute casts, and realistic model factories using Faker.
3. **`idempotent-database-seeders.md`:**
   - Injected into `database_agent` and `seeder_agent`.
   - Directs the use of `firstOrCreate()`, `updateOrCreate()`, or existence checks to prevent duplicate key errors on subsequent seeder runs, wiring seeders into `DatabaseSeeder::run()`.

### 19.3 Scoped Capabilities & Defense-in-Depth
- Each helper sub-agent is wrapped by `ScopedToolProxy` enforcing strict `scopePaths()` boundaries. For example, `MigrationAgent` is prevented from mutating files outside `database/migrations/**`.
- `ArtisanRunnerTool` enforces command allow-lists and blocks dangerous flags (`--force`, `--drop-views`, `--drop-types`, `--seed`) without human review.
- In `--dry-run` mode, file modifications return non-destructive diff previews.

### 19.4 Lead Agent Delegation & Fallback Flow
- When `lara-orcest.teams.enabled` is `true`:
  - `DatabaseAgent` delegates sequentially across Waves 0, 1, and 2.
  - Sub-agent artifacts are recorded in `RunState::$artifacts` with sub-agent attribution (`migration_agent`, `model_eloquent_agent`, `seeder_agent`).
  - Delegations are tracked in `RunState::$delegations`.
- When `lara-orcest.teams.enabled` is `false`:
  - `DatabaseAgent` falls back to single-turn prompt execution.

## 20. Phase 4 — Backend Pod (`backend_agent`, `request_validation_agent`, `service_action_agent`, `controller_resource_agent`)

### 20.1 Pod Architecture & Sequential Wave Order
The Backend Pod translates the `technical_plan` controller, request, and route specifications into concrete, production-ready backend code. Because services and actions depend on validated request boundaries, and controllers depend on actions/services, the sub-agents declare strict dependency relationships executed in sequential waves.

```
┌────────────────────────────────────────────────────────────────────────┐
│                              Backend Pod                               │
├────────────────────────────────────────────────────────────────────────┤
│  Lead Agent: BackendAgent                                              │
│  - Aggregates backend artifacts and routes into RunState               │
│  - Active Skills: laravel-form-requests-authorization,                 │
│    service-action-workflows, controllers-resources-routes              │
│  - Allowed Tools: file_reader, patch_writer, artisan_runner            │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │ sequential wave dispatch
                                    ▼
┌────────────────────────────────────────────────────────────────────────┐
│ Wave 0: RequestValidationAgent (key: request_validation_agent)         │
│ - dependsOn: []                                                        │
│ - scopePaths: ['app/Http/Requests/**', 'app/Domains/**/Requests/**',   │
│                'app/Policies/**', 'app/Domains/**/Policies/**']        │
│ - allowedTools: ['file_reader', 'patch_writer', 'artisan_runner']      │
│ - Role: FormRequest validation rules, authorization gates, Policies    │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │ completes
                                    ▼
┌────────────────────────────────────────────────────────────────────────┐
│ Wave 1: ServiceActionAgent (key: service_action_agent)                 │
│ - dependsOn: ['request_validation_agent']                              │
│ - scopePaths: ['app/Services/**', 'app/Actions/**', 'app/Events/**',   │
│                'app/Listeners/**', 'app/Domains/**/Actions/**',        │
│                'app/Domains/**/Services/**']                           │
│ - allowedTools: ['file_reader', 'patch_writer', 'artisan_runner']      │
│ - Role: Single-responsibility Actions, transactions, domain workflows  │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │ completes
                                    ▼
┌────────────────────────────────────────────────────────────────────────┐
│ Wave 2: ControllerResourceAgent (key: controller_resource_agent)       │
│ - dependsOn: ['service_action_agent']                                  │
│ - scopePaths: ['app/Http/Controllers/**', 'app/Http/Resources/**',     │
│                'routes/**', 'app/Domains/**/Http/**']                  │
│ - allowedTools: ['file_reader', 'patch_writer', 'artisan_runner']      │
│ - Role: Thin API controllers, JsonResources, RESTful route binding    │
└────────────────────────────────────────────────────────────────────────┘
```

### 20.2 Dedicated Backend Skills
1. **`laravel-form-requests-authorization.md`:**
   - Injected into `backend_agent` and `request_validation_agent`.
   - Enforces dedicated FormRequests, `rules()` validation arrays, tenant/project scoping rules, and Policy authorization gates.
2. **`service-action-workflows.md`:**
   - Injected into `backend_agent` and `service_action_agent`.
   - Directs single-responsibility Action classes (`execute()`), transactional safety with `DB::transaction()`, and domain event dispatches.
3. **`controllers-resources-routes.md`:**
   - Injected into `backend_agent` and `controller_resource_agent`.
   - Mandates thin controllers delegating to Actions/Services, `JsonResource` response transformers, consistent status codes (`200`, `201`, `204`), and RESTful route declarations in `routes/api.php`.

### 20.3 Scoped Capabilities & Defense-in-Depth
- Each helper sub-agent is wrapped by `ScopedToolProxy` enforcing strict `scopePaths()` boundaries. For example, `RequestValidationAgent` cannot mutate controllers or routes.
- `ArtisanRunnerTool` enforces command allow-lists and blocks destructive flags.
- In `--dry-run` mode, file modifications return non-destructive diff previews.

### 20.4 Lead Agent Delegation & Fallback Flow
- When `lara-orcest.teams.enabled` is `true`:
  - `BackendAgent` delegates sequentially across Waves 0, 1, and 2.
  - Sub-agent artifacts are recorded in `RunState::$artifacts` with sub-agent attribution (`request_validation_agent`, `service_action_agent`, `controller_resource_agent`).
  - Delegations are tracked in `RunState::$delegations`.
- When `lara-orcest.teams.enabled` is `false`:
  - `BackendAgent` falls back cleanly to single-turn prompt execution.

## 21. Phase 5 — Quality, Docs & Release Pod (`qa_agent`, `reviewer_agent`, `feature_test_agent`, `fixer_agent`, `swagger_agent`, `git_agent`)

### 21.1 Pod Architecture & Collaboration
The Quality, Docs & Release Pod ensures end-to-end reliability, API contract conformance, and git milestone safety. It organizes two Lead Agents (`qa_agent` and `reviewer_agent`) collaborating with specialized helper sub-agents.

```
┌────────────────────────────────────────────────────────────────────────┐
│                    Quality, Docs & Release Pod                         │
├────────────────────────────────────────────────────────────────────────┤
│  Lead Agent 1: QaAgent (Automated QA & Test Engineer)                  │
│  - Active Skills: laravel-feature-testing, root-cause-test-fixing      │
│  - Delegates test writing to feature_test_agent (Wave 0)               │
│  - Executes test_runner; triggers fixer_agent on test failure          │
│                                                                        │
│  Wave 0: FeatureTestAgent (key: feature_test_agent)                    │
│  - scopePaths: ['tests/**']                                            │
│  - allowedTools: ['file_reader', 'patch_writer', 'test_runner',         │
│                   'artisan_runner']                                    │
│  - Role: Generates comprehensive Pest/PHPUnit tests from acceptance    │
│          criteria and runs test runner                                 │
│                                                                        │
│  Conditional Retry: FixerSubAgent (key: fixer_agent)                   │
│  - dependsOn: ['feature_test_agent']                                   │
│  - scopePaths: ['app/**', 'database/**', 'routes/**', 'tests/**']      │
│  - allowedTools: ['file_reader', 'patch_writer', 'artisan_runner',     │
│                   'test_runner']                                       │
│  - Role: Root-cause debugging and surgical fixes within retry budget   │
├────────────────────────────────────────────────────────────────────────┤
│  Lead Agent 2: ReviewerAgent (Quality & Security Reviewer)             │
│  - Active Skills: openapi-swagger-spec, safe-git-release               │
│  - Performs comprehensive code audit & security evaluation             │
│                                                                        │
│  Docs Sub-Agent: SwaggerSubAgent (key: swagger_agent)                  │
│  - scopePaths: ['docs/**', 'storage/api-docs/**', 'openapi.json',      │
│                 'openapi.yaml']                                        │
│  - allowedTools: ['file_reader', 'patch_writer', 'artisan_runner']      │
│  - Role: Generates and updates OpenAPI 3.0+ / Swagger specifications   │
│                                                                        │
│  Release Sub-Agent: GitSubAgent (key: git_agent)                       │
│  - scopePaths: ['*']                                                   │
│  - allowedTools: ['git_command', 'file_reader']                        │
│  - Role: Feature branch creation, clean milestone commits, and status  │
└────────────────────────────────────────────────────────────────────────┘
```

### 21.2 Dedicated Quality, Docs & Release Skills
1. **`laravel-feature-testing.md`:**
   - Injected into `qa_agent` and `feature_test_agent`.
   - Directs test generation in `tests/Feature/**` and `tests/Unit/**`, covering happy paths (`200`/`201`), validation errors (`422`), authorization gates (`403`), and multi-tenant isolation.
2. **`root-cause-test-fixing.md`:**
   - Injected into `qa_agent` and `fixer_agent`.
   - Guides stack trace root-cause diagnosis, minimal surgical patching without weakening assertions, and strict adherence to the `max_fix_attempts` retry budget.
3. **`openapi-swagger-spec.md`:**
   - Injected into `reviewer_agent` and `swagger_agent`.
   - Enforces OpenAPI 3.0.3+ compliance, complete path/operation documentation, FormRequest request body validation schemas, and `JsonResource` response models.
4. **`safe-git-release.md`:**
   - Injected into `reviewer_agent` and `git_agent`.
   - Directs safe branch naming (`orcest/...`), protected branch safeguards (`main`, `master`, `production`), secret exclusion (`.env`), and dry-run commit simulation.

### 21.3 Scoped Capabilities & Defense-in-Depth
- `FeatureTestAgent` is restricted strictly to `tests/**` via `ScopedToolProxy`.
- `FixerSubAgent` is scoped to `['app/**', 'database/**', 'routes/**', 'tests/**']` and bounded by `max_fix_attempts`.
- `SwaggerSubAgent` is scoped strictly to documentation paths (`docs/**`, `storage/api-docs/**`, `openapi.json`, `openapi.yaml`).
- `GitSubAgent` operates with non-destructive dry-run simulation in `--dry-run` mode and respects Human-in-the-Loop gates (`before_commit`).

### 21.4 Lead Agent Delegation & Fallback Flow
- When `lara-orcest.teams.enabled` is `true`:
  - `QaAgent` delegates to `feature_test_agent`, runs `test_runner`, and delegates to `fixer_agent` if tests fail.
  - `ReviewerAgent` audits code, delegates to `swagger_agent` if OpenAPI docs are requested, and coordinates with `git_agent` for milestone commit upon approval.
- When `lara-orcest.teams.enabled` is `false`:
  - `QaAgent` and `ReviewerAgent` fall back to single-agent execution loops.






