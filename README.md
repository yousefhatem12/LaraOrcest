<p align="center">
  <img src="art/logo.png" alt="LaraOrcest Logo" width="300" height="300">
</p>

<h1 align="center">LaraOrcest</h1>

<p align="center">
  <strong>Prompt-driven multi-agent orchestration for Laravel with specialized roles, stateful graph execution, automated tests, and safe Git workflows.</strong>
</p>

<p align="center">
  <a href="#built-in-agent-roster">Agents</a> •
  <a href="#local-development-setup">Setup</a> •
  <a href="#detailed-architecture--spec">Architecture</a> •
  <a href="./spec.md">Technical Spec</a>
</p>

---

## Vision

`LaraOrcest` brings a role-based multi-agent pod (CrewAI style) and a stateful directed graph with bounded feedback loops (LangGraph style) directly into Laravel applications via Composer—without requiring an external Python service.

## Built-in Agent Roster

1. **Business Analyst (`business_analyst`):** Extracts functional requirements, business rules, roles, and edge cases.
2. **Product Manager (`product_manager`):** Prioritizes tasks and defines clear acceptance criteria.
3. **Architect (`architect`):** Maps tasks to an idiomatic Laravel technical plan (migrations, models, actions/services, controllers, routes, tests).
4. **Git Agent (`git_agent`):** Creates a dedicated feature branch and records scoped commits after verified milestones.
5. **Database Agent (`database_agent`):** Creates and updates migrations, relationships, factories, and Eloquent models.
6. **Backend Agent (`backend_agent`):** Implements controllers, form requests, domain/service classes, and routes.
7. **QA Agent (`qa_agent`):** Writes tests based on acceptance criteria and runs the test suite.
8. **Fixer Agent (`fixer_agent`):** Diagnoses failures or review feedback and applies scoped fixes within retry limits.
9. **Reviewer Agent (`reviewer_agent`):** Audits changes against requirements, acceptance criteria, security, and scope guardrails.

## Local Development Setup

1. Create the standalone package repository (`LaraOrcest`).
2. Link it to a fresh Laravel test application using a local `path` repository in the test app's `composer.json`:

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

3. Publish configuration and migrations:

```bash
php artisan vendor:publish --tag=lara-orcest-config
php artisan vendor:publish --tag=lara-orcest-migrations
php artisan migrate
```

## LLM Proxy & LiteLLM Integration (Zero Python in Laravel)

`LaraOrcest` communicates with AI models using an **OpenAI-compatible HTTP driver** by default. This enables seamless connection to any local or self-hosted LLM proxy—such as **[LiteLLM](https://github.com/BerriAI/litellm)**, **vLLM**, **Ollama**, or **OpenRouter**—with **zero Python code or Python SDKs inside your Laravel project**.

### Connecting to a LiteLLM Proxy via `.env`

Add the following to your application's `.env`:

```dotenv
# Default driver uses OpenAI-compatible HTTP requests
LARA_ORCEST_LLM=openai_compatible

# Point directly to your local or remote LiteLLM proxy
LARA_ORCEST_LLM_BASE_URL=http://localhost:4000/v1
LARA_ORCEST_LLM_API_KEY=sk-litellm-proxy-key
LARA_ORCEST_LLM_MODEL=gpt-4o-mini
LARA_ORCEST_LLM_TIMEOUT=60
LARA_ORCEST_LLM_RETRIES=2

# Optional: Route specific agents to distinct proxy model aliases
LARA_ORCEST_MODEL_ARCHITECT=claude-3-5-sonnet
LARA_ORCEST_MODEL_REVIEWER_AGENT=o3-mini
LARA_ORCEST_MODEL_BACKEND_AGENT=gpt-4o-mini
```

### Benefits
- **No Python Runtime Required in PHP:** All communication happens over standard, highly optimized HTTP requests via Laravel's `Illuminate\Support\Facades\Http`.
- **Per-Agent Model Routing:** Assign complex reasoning tasks (e.g. `architect`, `reviewer_agent`) to heavier frontier models while routing fast implementation tasks (`backend_agent`, `database_agent`) to lightweight models.
- **Observability & Proxy Metadata:** Requests automatically pass `run_id`, `agent_key`, and `node_id` in both request headers (`X-Run-Id`, `X-Agent-Key`) and payload metadata for centralized tracing in LiteLLM, Langfuse, or OpenTelemetry.
- **Safe Secrets Handling:** API keys are never stored in `RunState` or serialized into checkpoints and are automatically redacted in error messages.

## Usage

### Artisan CLI

```bash
# Build a feature with the multi-agent pod
php artisan orcest:build "Create a task management API with projects and assignments"

# Preview plan and files without touching disk or Git
php artisan orcest:build "Add invoice billing" --dry-run

# Inspect previous orchestration runs
php artisan orcest:status
```

### Facade

```php
use Yousef\LaraOrcest\Facades\Orchestrator;

$state = Orchestrator::run('Build a notification system for overdue invoices', [
    'dry_run' => false,
    'max_fix_attempts' => 3,
]);

if ($state->isCompleted()) {
    echo "Feature built successfully on branch: {$state->gitState['feature_branch']}";
}
```

## Sandboxed Tools Layer & Security Boundaries

`LaraOrcest` implements strict process and path sandboxing so agents cannot compromise the application or host environment:

1. **PathGuard & Root Isolation (`PathGuard`):**
   - Resolves all paths relative to the project root (`base_path()`) and canonicalizes paths before access.
   - Prevents directory traversal (`../`) and symlink escapes outside the workspace.
   - Enforces an automated denylist blocking access to sensitive files and directories: `.env*`, `.git/`, `vendor/`, `node_modules/`, `storage/logs/`, `bootstrap/cache/`, `*.key`, `*.pem`.
   - Supports per-agent write allow-lists (e.g. scoping `DatabaseAgent` to `database/` and `app/Models/`, `BackendAgent` to `app/`, `routes/`, `config/`, and `QaAgent` to `tests/`).

2. **File Reader (`FileReaderTool`):**
   - Safe inspection of files with optional line slicing (`offset`, `max_lines`), byte capping (`max_bytes`), and existence checking.
   - Directory tree inspection and file searching (`path`, `recursive`, `pattern` glob filtering).

3. **Patch Writer (`PatchWriterTool`):**
   - Atomic file creation and full replacement with automatic parent directory generation.
   - Targeted search-and-replace (`target`, `replacement`) validating that target substrings exist before modification.
   - Safe deletion (`delete` action) within permitted allow-lists.
   - Unified diff generation (`diff`) and direct `RunState` artifact recording (`applyToState()`).

4. **Artisan Runner (`ArtisanRunnerTool`):**
   - Executes only allow-listed Artisan commands (e.g. `make:model`, `make:migration`, `make:controller`, `make:class`, `make:enum`, `make:interface`, `make:trait`, `route:list`, `migrate:status`).
   - Process-isolated via `Symfony\Component\Process\Process` (no raw shell string concatenation).
   - Blocks dangerous flags (`--force`, `--drop-views`, `--drop-types`, `--seed`) and rejects all shell metacharacters (`|`, `;`, `&`, `` ` ``, `$`, `<`, `>`, `\n`).

5. **Test Runner (`TestRunnerTool`):**
   - Executes automated tests via `php artisan test` with configurable timeout handling.
   - Returns structured output: `passed`, `exit_code`, `duration`, `summary`, and extracted `failures` array.

6. **Git Command (`GitCommandTool`):**
   - Safe, restricted Git operations: `status`, `current_branch`, `branch`, `create_branch`, `diff`, `stage_files`, and `commit`.
   - Blocks destructive operations: `push --force`, `reset --hard`, `clean -fd`, and direct commits/checkouts on protected branches (`main`, `master`, `production`, `staging`).
   - Redacts credentials in output (`https://[REDACTED_CREDENTIALS]@`).
   - Direct `RunState` synchronization (`applyToState()`).

7. **Dry-Run Mode Guarantee:**
   - When `$state->guardrails['dry_run']` is `true`, mutating tools return simulated execution payloads without altering files, running commands, or modifying Git branches/commits.

