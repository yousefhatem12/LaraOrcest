# Architecture & Engine

LaraOrcest is architected as a modular, deterministic state graph framework tailored specifically for Laravel application lifecycles. This document explains the internal mechanisms governing state transitions, persistence, scheduling, parallel execution, and memory management.

---

## 1. High-Level Architecture Overview

```
 ┌─────────────────────────────────────────────────────────────────────────────┐
 │                                Orchestrator                                 │
 └──────┬───────────────────────────────┬───────────────────────────────┬──────┘
        │                               │                               │
        ▼                               ▼                               ▼
 ┌───────────────┐              ┌───────────────┐              ┌───────────────┐
 │   RunState    │              │  GraphEngine  │              │ ProjectMemory │
 │  (Immutable)  │              │  (Lifecycle)  │              │  (Memorilla)  │
 └──────┬────────┘              └───────┬───────┘              └───────────────┘
        │                               │
        ▼                               ▼
 ┌───────────────┐              ┌──────────────────────────────────────────────┐
 │ Checkpointer  │              │ Nodes & EdgeRules                            │
 │ (JSON / DB)   │              │ - AgentGraphNode (9 Core + 2 Conditional)    │
 └───────────────┘              │ - ParallelNode (Waves & Fan-In)              │
                                │ - SubGraphNode (Nested Pipelines)            │
                                └──────────────────────────────────────────────┘
```

---

## 2. Immutable State Container: `RunState`

The entire lifecycle of an orchestration run is encapsulated in `Yousef\LaraOrcest\State\RunState`. To preserve determinism and eliminate race conditions, state transitions return **fresh, modified instances** rather than mutating state in place.

### Key State Fields

| Field | Type | Description |
| :--- | :--- | :--- |
| `runId` | `string` | Unique run identifier (e.g. `run_67e1a3b890f12`). |
| `prompt` | `string` | Original natural language requirement provided by the user. |
| `status` | `string` | Current status: `running`, `paused`, `completed`, `failed`. |
| `currentNode` | `string` | The active or most recently completed graph node key. |
| `requirementsSpec` | `array` | Structured domain requirements produced by `business_analyst`. |
| `backlog` | `array` | Ordered task definitions and acceptance criteria produced by `product_manager`. |
| `technicalPlan` | `array` | Architecture plan (migrations, models, controllers, routes) by `architect`. |
| `artifacts` | `array` | Ledger of files generated or patched (`path`, `action`, `summary`). |
| `testResults` | `array` | Test execution outcomes (`passed`, `summary`, `failures`, `output`) from `qa_agent`. |
| `reviews` | `array` | History of code audits and sign-offs from `reviewer_agent`. |
| `gitState` | `array` | Active feature branch name, initial commit SHA, and recorded commits. |
| `guardrails` | `array` | Execution limits tracking `step_count`, `fix_attempts`, and `token_usage`. |
| `metadata` | `array` | Extensible metadata storing HITL gate states, scraping results, and Swagger specs. |

### State Immutability Pattern
Every state modification returns a new `RunState` instance:

```php
// Adding an artifact returns a fresh RunState
$state = $state->recordArtifact(
    path: 'app/Models/Feedback.php',
    action: 'create',
    summary: 'Eloquent model for customer feedback'
);

// Incrementing execution steps
$state = $state->incrementSteps();
```

---

## 3. Persistence: `StateCheckpointer`

The `CheckpointerContract` ensures fault tolerance, enabling runs to pause at Human-in-the-Loop review gates and resume gracefully without data loss.

### Checkpointer Interface
```php
namespace Yousef\LaraOrcest\Contracts;

interface CheckpointerContract
{
    public function save(RunState $state): void;
    public function load(string $runId): ?RunState;
    public function list(int $limit = 20): array;
    public function delete(string $runId): bool;
}
```

### Supported Drivers

1. **JSON Filesystem Checkpointer (`JsonCheckpointer`):**
   - Default driver.
   - Saves state as indented JSON files in `.lara-orcest/runs/{run_id}.json`.
   - Requires zero database setup. Ideal for local development and CLI workflows.

2. **Database Checkpointer (`DatabaseCheckpointer`):**
   - Production-grade driver utilizing Laravel's Eloquent / Query Builder.
   - Stores runs in the `orcest_runs` table with JSON columns for state payloads.
   - Supports concurrent multi-user environments and administrative dashboards.

### Automatic Checkpointing Points
`GraphEngine` automatically invokes `$checkpointer->save($state)` at critical milestones:
- Upon initial run creation.
- Immediately after any node completes execution.
- When an execution pauses at a Human-in-the-Loop gate.
- Upon fan-in completion of a parallel wave.
- Upon reaching terminal success (`__completed__`) or failure (`__failed__`).

---

## 4. Conditional Edge Rules: `EdgeRule`

Transitions between nodes are modeled using `EdgeRule`. An edge defines a source node, a destination node, an optional conditional Closure, and an integer priority.

```php
namespace Yousef\LaraOrcest\Graph;

class EdgeRule
{
    public function __construct(
        public readonly string $fromNode,
        public readonly string $toNode,
        public readonly mixed $condition = null, // callable(RunState): bool
        public readonly int $priority = 10
    );
}
```

### Deterministic Resolution
When resolving the next node from `$currentNode`, `GraphEngine`:
1. Gathers all edges registered for `$currentNode`.
2. Sorts them in descending order of `priority`.
3. Evaluates each edge's condition against the current `RunState`.
4. Selects the **first edge whose condition evaluates to `true`**.

### Default Lifecycle Transitions
```php
// Unconditional transition
$engine->addEdge('business_analyst', 'product_manager');

// Conditional branching from QA based on test outcome
$engine->addEdge('qa_agent', 'reviewer_agent', fn (RunState $s) => $s->areTestsPassing() || $s->isDryRun(), priority: 20);
$engine->addEdge('qa_agent', 'fixer_agent', fn (RunState $s) => ! $s->areTestsPassing() && $s->canAttemptFix(), priority: 10);
$engine->addEdge('qa_agent', GraphEngine::TERMINAL_FAILED, fn (RunState $s) => ! $s->areTestsPassing() && ! $s->canAttemptFix(), priority: 5);

// Cyclic repair loop
$engine->addEdge('fixer_agent', 'qa_agent');
```

---

## 5. Execution Engine: `GraphEngine`

`GraphEngine` acts as the execution coordinator:

1. **Validation:** Checks step limits (`guardrails.step_count < guardrails.max_steps`).
2. **Node Invocation:** Dispatches the current node (`AgentGraphNode`, `ParallelNode`, or `SubGraphNode`).
3. **HITL Interception:** Inspects whether an approval gate applies after the node (e.g. `after_plan` or `before_commit`). If triggered, the run transitions to `paused` and yields control.
4. **Edge Evaluation:** Evaluates `EdgeRule` instances to determine the next node key.
5. **Terminal Finalization:**
   - On `__completed__`: Stages and commits artifacts to Git (`git.auto_commit`) and consolidates architectural memory into `.lara-orcest/memory.json`.
   - On `__failed__`: Records failure reasons and preserves diagnostic checkpoints.

---

## 6. Deterministic Parallel Execution

When multiple independent tasks must be performed simultaneously (e.g., generating 3 distinct models and migrations), LaraOrcest employs `ParallelScheduler` and `ScopeConflictDetector`.

```
                    ┌────────────────────────────────────────┐
                    │      Scheduled Backlog Tasks           │
                    │   (Task 1, Task 2, Task 3, Task 4)     │
                    └───────────────────┬────────────────────┘
                                        │
                                        ▼
                    ┌────────────────────────────────────────┐
                    │       ParallelScheduler Batches        │
                    │   Wave 0: [Task 1, Task 2] (Independent)│
                    │   Wave 1: [Task 3] (Depends on Task 1) │
                    │   Wave 2: [Task 4] (Scope Conflict)    │
                    └───────────────────┬────────────────────┘
                                        │
                         ┌──────────────┴──────────────┐
                         ▼                             ▼
                 ┌───────────────┐             ┌───────────────┐
                 │ Fork Branch 1 │             │ Fork Branch 2 │
                 │    Task 1     │             │    Task 2     │
                 └───────┬───────┘             └───────┬───────┘
                         │                             │
                         └──────────────┬──────────────┘
                                        ▼
                         ┌─────────────────────────────┐
                         │   Deterministic Fan-In      │
                         │    State Reconciliation     │
                         └─────────────────────────────┘
```

### 1. Dependency Resolution (`depends_on`)
Tasks can declare explicit prerequisites via `depends_on`. `ParallelScheduler` organizes tasks into dependency-free topological waves.

### 2. Cycle Detection (Tarjan / DFS)
If cyclical prerequisites exist (e.g. Task A depends on Task B, which depends on Task A), `ParallelScheduler::detectCycles()` intercepts the cycle before execution begins and throws a descriptive `CyclicDependencyException`.

### 3. Resource Scope Conflict Prevention (`scope_paths`)
Even if two tasks have no explicit dependencies, they **cannot run in parallel if they modify the same files or directories**.
`ScopeConflictDetector` verifies path boundaries:
- Exact path collisions (`app/Models/User.php` == `app/Models/User.php`).
- Directory containment (`app/Models` contains `app/Models/User.php`).
- Glob wildcards (`app/Models/*` matches `app/Models/User.php`).

Conflicting tasks are automatically partitioned into subsequent execution waves.

### 4. Branch Forking & Deterministic Fan-In
For each task in a parallel wave:
1. `RunState::forkBranch($branchKey)` creates an isolated child state with an active branch context.
2. The agent executes within this isolated sandbox branch.
3. Upon wave completion, `RunState::mergeParallelStates()` merges branch state updates:
   - **Artifacts:** Merged and deduplicated.
   - **Token Usage:** Aggregated across all concurrent worker threads.
   - **Guardrails:** Step counts unified.
   - **Conflict Interception:** If two branches authored contradictory changes to the same file without scope clearance, a `StateMergeConflictException` is raised.

---

## 7. Project Memory: Memorilla Architecture

Traditional agents inject entire files or multi-megabyte summaries into LLM prompts, leading to high token costs and attention degradation.

LaraOrcest implements the **Memorilla Architecture** (`ProjectMemoryStore`):
- **Compact File Storage:** Maintained locally in `.lara-orcest/memory.json`.
- **Decoupled Indexing & Recall:** Codebase indexing happens out-of-band or at run completion. Prompts only receive a small, relevant slice of memory.
- **Strict Character Budget:** Defaults to `2,500` characters (`max_context_chars`) and `15` items (`max_items`).
- **Paired with Tools:** Distilled memory entries serve as pointers; agents use `FileReaderTool` to inspect exact source lines when necessary.

### Memory Data Structure (`.lara-orcest/memory.json`)
```json
{
  "project": "MyLaravelApp",
  "conventions": [
    {
      "id": "conv_1",
      "category": "architecture",
      "rule": "All controller actions must be validated via FormRequest classes.",
      "applies_to": ["backend_agent", "architect"]
    }
  ],
  "codebase_index": {
    "models": { "User": "app/Models/User.php", "Feedback": "app/Models/Feedback.php" },
    "controllers": { "FeedbackController": "app/Http/Controllers/FeedbackController.php" },
    "directories": ["app/Models", "app/Http/Controllers", "database/migrations"]
  },
  "decisions": [
    {
      "id": "dec_8f12a",
      "run_id": "run_67e1a3b890f12",
      "title": "Customer Feedback API with Moderation",
      "summary": "Implemented feedback submission with 1-5 rating casts and admin approval flag.",
      "files": ["app/Models/Feedback.php", "app/Http/Controllers/FeedbackController.php"]
    }
  ],
  "pitfalls": [
    {
      "id": "pitfall_3b",
      "issue": "SQLite foreign key constraint failure during migration rollback",
      "root_cause": "Missing cascade on delete rule for feedback author",
      "resolution": "Added ->onDelete('cascade') to foreignId definition."
    }
  ]
}
```

### Lifecycle: Recall and Consolidation

1. **Context Recall (Before Turn):**
   `AbstractAgent::resolveProjectMemoryContext()` extracts relevant keywords from the user prompt and agent role, querying `ProjectMemoryStore::recallForContext()`. Only relevant conventions, decisions, and pitfalls fitting within the character budget are prepended to the prompt.

2. **Consolidation (After Run):**
   When `GraphEngine` reaches `__completed__`, `ProjectMemoryStore::consolidate($state)` updates `.lara-orcest/memory.json`:
   - Scans generated artifacts and indexes new models, controllers, and migrations.
   - Summarizes the architectural decision for the run.
   - Extracts root causes and fixes logged by `fixer_agent` into the pitfalls register so future runs avoid repeating the same bug.

---

## Next Steps
- Learn more about individual agent responsibilities: **[Agents Guide](agents.md)**
- Review security boundaries and Human-in-the-Loop review gates: **[Safety & HITL Guide](safety-and-hitl.md)**
- Walk through end-to-end execution scenarios: **[Use Cases](use-cases.md)**
