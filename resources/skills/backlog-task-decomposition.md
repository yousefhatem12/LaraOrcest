---
name: backlog-task-decomposition
description: Decompose specifications into atomic, prioritized implementation tasks with explicit depends_on dependencies, strict directory scope_paths, and acceptance criteria.
agents:
  - product_manager
  - backlog_splitter_agent
  - architect
allowed_tools:
  - file_reader
tags:
  - backlog
  - planning
  - dependencies
  - scoping
---

# Backlog Task Decomposition & Scoping

When transforming a requirements specification into an engineering backlog:

1. **Vertical Slicing & Atomicity:**
   - Decompose features into self-contained tasks that can be implemented, tested, and reviewed independently.
   - Avoid oversized monolithic tasks; separate database schema migrations, domain models, business service actions, and HTTP controllers/FormRequests.

2. **Explicit Dependency Graphs (`depends_on`):**
   - Each task must declare explicit `depends_on` references to preceding tasks (e.g. `TASK-001` migration must precede `TASK-002` Eloquent model).
   - Independent tasks must have empty `depends_on` arrays so that parallel scheduling waves can execute them concurrently without conflict.

3. **Strict Directory Scope Paths (`scope_paths`):**
   - Every task must define strict file path patterns it is authorized to touch (e.g. `['database/migrations/**']` for migration tasks, `['app/Models/**']` for model tasks, `['app/Http/Controllers/**', 'routes/api.php']` for endpoints).
   - Never use wildcard `*` for implementation tasks; enforce least-privilege directory boundaries to avoid cross-task file collisions.

4. **Verifiable Acceptance Criteria:**
   - Each task must include clear, testable acceptance criteria with expected HTTP status codes, validation responses, and database assertions.
