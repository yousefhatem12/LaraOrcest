# lara_orcest --- Technical Specification & Agent Blueprint

## 1. Overview & Concept

**Package:** `yousef/lara-orcest`\
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
    "yousef/lara-orcest": "*"
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
│   │   └── ReviewerAgent.php
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

  `guardrails`            `array`                 Step count, maximum steps,
                                                  fix attempts, maximum fix
                                                  attempts, dry-run flag, and
                                                  token usage.
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

### Core components

-   `GraphEngine`: Executes nodes, evaluates transitions, enforces
    budgets, and coordinates checkpoints.
-   `EdgeRule`: Represents a conditional transition from one node to
    another.
-   `AgentGraphNode`: Adapts an agent to the graph-node contract.
-   `RunState`: Shared state passed through every node.
-   `StateCheckpointer`: Persists and restores execution state.

### Node contract

Each node should: 1. Validate that required upstream state exists. 2.
Read only the state fields and tools permitted for its role. 3. Request
structured output from its agent/LLM. 4. Validate the output against a
schema before applying it. 5. Update only its authorized state fields.
6. Append execution/artifact metadata. 7. Persist a checkpoint at the
appropriate boundary. 8. Return control to the graph engine for
transition selection.

### Structured LLM output

-   Require schema-constrained JSON wherever supported.
-   Validate all model output server-side.
-   Reject unknown fields or invalid enum values unless explicitly
    allowed.
-   Treat model output as untrusted data; never execute generated shell
    commands directly.
-   Retry malformed output only within a bounded retry budget.
-   Track token usage and configured cost limits.

### Checkpointing and resume

Support database and JSON-based persistence behind `StateCheckpointer`.

A checkpoint should include: - Run ID and schema version. - Current node
and run status. - Serialized state, excluding secrets. - Timestamp and
transition metadata.

Resume must validate checkpoint integrity, schema compatibility, project
identity, Git branch/worktree state, and guardrail budgets before
continuing. Do not blindly replay side effects after a crash; use
idempotency keys or operation records where necessary.

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
        ],
    ],

    'execution' => [
        'max_steps' => 40,
        'max_fix_attempts' => 3,
        'dry_run' => false,
        'process_timeout' => 120,
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

-   [ ] The package installs from its standalone Composer repository.
-   [ ] Laravel package auto-discovery registers the service provider.
-   [ ] The facade and Artisan commands resolve correctly.
-   [ ] `RunState` serializes, persists, restores, and validates
    correctly.
-   [ ] All nine agents have explicit roles, goals, backstories, tool
    permissions, and output schemas.
-   [ ] Graph transitions work and all loops are bounded.
-   [ ] Tools enforce project-root, path, command, and Git safety
    boundaries.
-   [ ] Dry-run performs no mutations.
-   [ ] QA results and reviewer decisions control finalization.
-   [ ] Fix attempts and total steps cannot exceed configured limits.
-   [ ] Secrets are excluded from state, logs, and artifacts.
-   [ ] Unit, security, integration, and end-to-end tests pass on
    supported targets.
-   [ ] A fresh Laravel application can use the package through Composer
    path-repository development.

------------------------------------------------------------------------

**Project principle:** Agents propose and perform narrowly authorized
work; the graph controls execution; tools enforce security boundaries;
`RunState` records progress; tests and review determine whether a run
may finish.
