# Safety, Sandboxed Tools & Human-in-the-Loop (HITL)

Autonomous code generation requires strict operational guardrails. An AI agent should never read environment secrets, execute arbitrary shell commands, drop database tables, or overwrite code on protected branches.

LaraOrcest implements multi-layered security controls:
1. **Sandboxed Tools Layer:** Dedicated tool classes with least-privilege policies.
2. **Path & Command Guardrails (`PathGuard`):** Filesystem confinement and denylist enforcement.
3. **Execution Guardrails:** Step limits and bug-fix loop quotas.
4. **Human-in-the-Loop (HITL) Gates:** Interactive checkpoints pausing execution for explicit human review.

---

## 1. Sandboxed Tools Architecture

Agents cannot execute arbitrary shell commands. They interact with the filesystem, Artisan, Git, and test runners strictly through authorized tool instances:

```
┌────────────────────────────────────────────────────────────────────────┐
│                          LaraOrcest Agents                             │
└────────┬──────────────┬──────────────┬──────────────┬──────────────────┘
         │              │              │              │
         ▼              ▼              ▼              ▼
  ┌─────────────┐┌─────────────┐┌─────────────┐┌─────────────┐┌─────────────┐
  │ FileReader  ││ PatchWriter ││ArtisanRunner││ TestRunner  ││ GitCommand  │
  └──────┬──────┘└──────┬──────┘└──────┬──────┘└──────┬──────┘└──────┬──────┘
         │              │              │              │              │
         ▼              ▼              ▼              ▼              ▼
  ┌────────────────────────────────────────────────────────────────────────┐
  │                    PathGuard & Security Enforcement                    │
  │  - Project Root Confinement    - Symlink Escape Prevention             │
  │  - Secret Files Denylist       - Artisan Command Whitelist             │
  │  - Forbidden Flags Blocker     - Protected Branch Safeguards           │
  └────────────────────────────────────────────────────────────────────────┘
```

### 1. `FileReaderTool`
- **Responsibility:** Safe inspection of files within the project root.
- **Guardrails:**
  - Enforces `PathGuard` confinement.
  - Blocks access to files exceeding `max_read_size` (default: 1MB).
  - Supports chunked reads (`offset`, `length`) to prevent prompt buffer overflows.

### 2. `PatchWriterTool`
- **Responsibility:** Creating and updating source files.
- **Guardrails:**
  - Validates destination paths via `PathGuard`.
  - Automatically creates missing parent directories securely.
  - **Dry-Run Aware:** When `$state->isDryRun()` is true, operations are logged without writing to disk.

### 3. `ArtisanRunnerTool`
- **Responsibility:** Executing approved Artisan generators and inspectors.
- **Command Whitelist:** Only commands explicitly permitted in `config('lara-orcest.tools.allowed_artisan_commands')` can be invoked:
  ```php
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
  ```
- **Forbidden Flags:** Dangerous flags are strictly rejected:
  - `--force`
  - `--drop-views`
  - `--drop-types`
  - `--seed`
- **Process Timeout:** Bound by `process_timeout` (default: 120s) to prevent stalled executions.

### 4. `TestRunnerTool`
- **Responsibility:** Running automated test suites via Symfony Process.
- **Execution:** Invokes `php artisan test --without-tty` or a custom test runner command defined in config.
- **Parsing:** Strips ANSI escape codes, extracting total test counts, assertions, and failure messages into structured JSON.
- **Safety:** Sandboxed to test execution; terminates processes exceeding timeout.

### 5. `GitCommandTool`
- **Responsibility:** Isolating agent work in dedicated Git branches.
- **Guardrails:**
  - **Branch Prefixes:** Prefixes branches with `orcest/` (e.g. `orcest/customer-feedback-api`).
  - **Protected Branches:** Prevents checking out or committing directly to `main`, `master`, `production`, or `staging`.
  - **Clean Working Tree:** Verifies there are no uncommitted changes before branch switching.

---

## 2. Filesystem Confinement (`PathGuard`)

All file read/write operations pass through `Yousef\LaraOrcest\Tools\Support\PathGuard`.

### Path Security Checks
1. **Canonical Root Confinement:** Resolves absolute paths against `Config::basePath()`. Paths outside the project root are rejected.
2. **Symlink Escape Detection:** Calls `realpath()` on existing files and confirms the target path begins with the project root.
3. **Sensitive Files Denylist:** The following path patterns are blocked unconditionally:
   ```text
   .env
   .env.*
   .git
   .git/*
   vendor
   node_modules
   storage/logs
   bootstrap/cache
   storage/oauth*
   *.key
   *.pem
   ```

---

## 3. Execution Guardrails

To prevent runaway token consumption and infinite loops:

- **Max Steps (`max_steps`):** Hard limit on the total number of node executions in a single run (default: 40). Exceeding this limit halts the run with an explicit failure.
- **Max Fix Attempts (`max_fix_attempts`):** Limits how many times `fixer_agent` can retry failing tests or addressing reviewer findings (default: 3). If tests still fail after 3 attempts, the pipeline fails fast rather than consuming infinite tokens.

---

## 4. Human-in-the-Loop (HITL) Controls

In enterprise applications, full autonomy is not always desired. You may want an engineer to approve the database schema before code is generated, or review the pull request diff before changes are committed.

LaraOrcest provides native **Human-in-the-Loop review gates**.

### Built-in Review Gates

| Gate Name | Execution Trigger | Context Provided to Reviewer |
| :--- | :--- | :--- |
| `after_plan` | After `architect` completes system blueprint. | Proposed backlog count, migrations list, models list, controllers list, and execution steps. |
| `before_commit` | After `reviewer_agent` approves and tests pass. | Generated artifact paths, automated test results, and reviewer sign-off findings. |

---

## 5. Using Human-in-the-Loop

### Enabling Interactive Review
Enable interactive gates using the `--interactive` flag:

```bash
php artisan orcest:build "Implement Webhook Delivery System" --interactive
```

Or enable it globally via `.env`:
```env
LARA_ORCEST_HITL_ENABLED=true
LARA_ORCEST_GATE_AFTER_PLAN=true
LARA_ORCEST_GATE_BEFORE_COMMIT=true
```

### Paused Execution State
When a gate is reached, execution halts cleanly, checkpoints state, and displays resume instructions:

```text
⏸ LaraOrcest paused for human review at gate: [after_plan] [Run ID: run_67e1a3b890f12]
Review Summary: Technical plan and backlog generated. Paused for human plan approval.
Planned Tasks: 3 tasks

To approve and resume execution:
  php artisan orcest:approve run_67e1a3b890f12
  # Or with note:  php artisan orcest:approve run_67e1a3b890f12 --note="Approved schema"
  # Or via build:  php artisan orcest:build --resume=run_67e1a3b890f12 --approve

To reject and halt:
  php artisan orcest:approve run_67e1a3b890f12 --reject --note="Requires Redis queue"
```

### Approving a Paused Run
Resume execution by approving the gate:

```bash
# Using orcest:approve
php artisan orcest:approve run_67e1a3b890f12 --note="Schema looks good, proceed with implementation"

# Or using orcest:build --resume
php artisan orcest:build --resume=run_67e1a3b890f12 --approve
```

### Rejecting a Paused Run
Reject the proposed changes and terminate the run:

```bash
php artisan orcest:approve run_67e1a3b890f12 --reject --note="Missing index on tenant_id column"
```

---

## 6. Opting Out of Human Review (Unattended Automation)

For CI/CD pipelines, nocturnal batch jobs, or developers who prefer full autonomy, you can bypass all review gates:

### Via CLI Option
Pass `--no-human-review` to bypass all gates:
```bash
php artisan orcest:build "Create refund transaction endpoint" --no-human-review
```

### Via Configuration / Environment
Set in `.env`:
```env
# Disable HITL globally
LARA_ORCEST_HITL_ENABLED=false

# Or explicitly enable skip_review
LARA_ORCEST_SKIP_HUMAN_REVIEW=true
```

When opted out, `GraphEngine` ignores all review gates and executes continuously from `business_analyst` through `reviewer_agent` to `__completed__`.

---

## Next Steps
- Explore real-world implementation walkthroughs: **[Use Cases](use-cases.md)**
- Review configuration options: **[Quickstart Guide](quickstart.md)**
