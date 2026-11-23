# Agents & Per-Agent Model Routing

LaraOrcest adopts a CrewAI-style role-based multi-agent paradigm. Rather than assigning all engineering responsibilities to a single generic prompt, LaraOrcest partitions the software delivery lifecycle across **specialized autonomous agents**.

Each agent possesses:
- A clearly defined **Role, Goal, and Backstory**.
- A **strict JSON Schema** enforcing structured LLM outputs.
- A **least-privilege tool whitelist** (`getAllowedTools()`).
- Ownership of specific fields within `RunState`.
- Support for **Per-Agent Model Routing**, allowing you to pair complex reasoning tasks with frontier models while routing routine code authoring to cost-effective models.

---

## 1. Built-in Agent Directory

| Agent Key | Class | Allowed Tools | Primary State Output | Default Scope / Paths |
| :--- | :--- | :--- | :--- | :--- |
| `business_analyst` | `BusinessAnalystAgent` | `file_reader` | `requirements_spec` | Read-only analysis |
| `product_manager` | `ProductManagerAgent` | `file_reader` | `backlog` | Read-only planning |
| `architect` | `ArchitectAgent` | `file_reader` | `technical_plan` | Read-only architecture |
| `git_agent` | `GitAgent` | `git_command` | `git_state` | Branch `orcest/...` |
| `database_agent` | `DatabaseAgent` | `file_reader`, `patch_writer`, `artisan_runner` | `artifacts` | `database/`, `app/Models/` |
| `backend_agent` | `BackendAgent` | `file_reader`, `patch_writer`, `artisan_runner` | `artifacts` | `app/`, `routes/`, `config/` |
| `qa_agent` | `QaAgent` | `file_reader`, `patch_writer`, `test_runner` | `test_results` | `tests/` |
| `fixer_agent` | `FixerAgent` | `file_reader`, `patch_writer`, `artisan_runner`, `test_runner` | `artifacts`, `guardrails.fix_attempts` | Project-wide bug repair |
| `reviewer_agent` | `ReviewerAgent` | `file_reader`, `git_command` | `reviews` | Read-only code audit |
| `web_scraping_agent` *(Conditional)* | `WebScrapingAgent` | `file_reader` | `scraping_results` | HTTP extraction & dispatch |
| `swagger_agent` *(Conditional)* | `SwaggerAgent` | `file_reader`, `patch_writer`, `artisan_runner` | `artifacts`, `metadata.swagger` | `docs/`, `storage/api-docs/` |

---

## 2. Core Agents Detailed Breakdown

### 1. `business_analyst` (Domain & Systems Analyst)
- **Role:** Extracts requirements, identifies domain entities, discovers unstated assumptions, and documents security/authorization rules.
- **Workflow:** Inspects existing domain models and policies using `FileReaderTool`. Generates an unambiguous `requirements_spec` JSON structure.
- **Output:** Stored in `$state->requirementsSpec`.

### 2. `product_manager` (Technical Product Manager)
- **Role:** Converts the `requirements_spec` into an ordered, testable backlog of discrete implementation tasks.
- **Workflow:** Defines atomic tasks with explicit acceptance criteria, expected artifact paths, priority weights, and inter-task dependencies (`depends_on`).
- **Output:** Stored in `$state->backlog`.

### 3. `architect` (Senior Software Architect)
- **Role:** Translates the product backlog into an idiomatic Laravel technical architecture plan conforming to Laravel 10/11/12 conventions.
- **Workflow:** Designs table migrations, Eloquent relationships, Casts, FormRequests, Controllers, API Resources, and Route definitions.
- **Output:** Stored in `$state->technicalPlan`.
- **HITL Integration:** When `--interactive` is enabled, execution automatically pauses immediately after `architect` at the `after_plan` gate.

### 4. `git_agent` (Source Control Hygiene Specialist)
- **Role:** Manages feature branch isolation and clean repository hygiene.
- **Workflow:**
  1. Checks `git status` to ensure the repository working tree is clean.
  2. Creates and checks out a dedicated feature branch using prefix convention `orcest/{feature-slug}`.
  3. Enforces protected branch safeguards (prevents touching `main`, `master`, `production`, `staging`).
- **Output:** Stored in `$state->gitState`.

### 5. `database_agent` (Database & Eloquent Specialist)
- **Role:** Authors database migrations, model definitions, casts, and model factories.
- **Workflow:**
  - Invokes `ArtisanRunnerTool` to execute `make:migration` and `make:model`.
  - Uses `PatchWriterTool` to write idiomatic schema blueprints and model relationships (`hasMany`, `belongsTo`, `casts()`).
- **Output:** Appends generated files to `$state->artifacts`.

### 6. `backend_agent` (Backend & API Engineer)
- **Role:** Implements HTTP layers, Form Request validation rules, controller actions, and API routes.
- **Workflow:**
  - Generates Form Requests (`make:request`) enforcing strict validation rules and authorization checks.
  - Generates Controllers (`make:controller`) returning JSON resources with appropriate HTTP status codes (200, 201, 404, 422).
  - Appends route definitions to `routes/api.php` or `routes/web.php`.
- **Output:** Appends generated files to `$state->artifacts`.

### 7. `qa_agent` (Automated QA & Test Engineer)
- **Role:** Writes automated feature and unit tests and executes the test suite.
- **Workflow:**
  - Generates comprehensive tests in `tests/Feature/` covering happy path assertions, validation errors, and authorization checks.
  - Invokes `TestRunnerTool` (`php artisan test --without-tty`).
  - Parses test output into structured pass/fail metrics.
- **Output:** Stored in `$state->testResults`.

### 8. `fixer_agent` (Root-Cause Bug Fixer)
- **Role:** Diagnoses failing assertions or reviewer objections and applies targeted code repairs.
- **Workflow:**
  - Triggered conditionally when `qa_agent` reports test failures or `reviewer_agent` requests revisions.
  - Analyzes the failure stack trace, reads offending files, and writes surgical patches via `PatchWriterTool`.
  - Re-triggers test execution.
  - Enforces `max_fix_attempts` (default: 3) to prevent runaway retry loops.
- **Output:** Updates `$state->artifacts` and increments `$state->guardrails['fix_attempts']`.

### 9. `reviewer_agent` (Staff Reviewer & Security Auditor)
- **Role:** Performs an objective staff-level code review of all authored changes.
- **Workflow:**
  - Inspects `git diff` and generated artifacts against PSR-12, Laravel security standards, and business criteria.
  - Evaluates authorization logic, SQL injection vectors, and validation completeness.
  - Issues an approval (`approved`) or requests revisions (`revise`) with actionable findings.
- **Output:** Stored in `$state->reviews`.

---

## 3. Optional & Conditional Agents

### 10. `web_scraping_agent` (Web Scraping & Ingestion Specialist)
The `web_scraping_agent` extracts structured data from external websites and forwards normalized payloads to destination endpoints.

#### Requirements & Capabilities
1. **Two Source URLs:** Strictly expects **exactly two target website URLs** to scrape and compare/reconcile.
2. **BA & PM Alignment:** Data extraction is guided directly by domain requirements (`requirements_spec`) and acceptance criteria (`backlog`).
3. **Payload Unification:** Combines extracted data from both websites into a single normalized payload (`unified_payload`).
4. **Endpoint Dispatch:** Dispatches the payload via HTTP (`POST`, `PUT`, `PATCH`) to a specified destination endpoint.
5. **Dry-Run Safety:** In `--dry-run` mode, scrapes and normalizes data into `artifacts` (`web_scraping/payload.json`) but skips external HTTP dispatch.

#### Activation
`web_scraping_agent` activates when:
- Two URLs and an endpoint are supplied via CLI or prompt.
- Metadata is passed programmatically via `$options['web_scraping']`.

```bash
# Example scraping run
php artisan orcest:build "Scrape pricing from https://store-a.example.com/item and https://store-b.example.com/item and dispatch to https://internal-api.example.com/webhooks/catalog" --dry-run
```

#### Security Guardrails
- **Scheme Validation:** Only `http` and `https` schemes allowed.
- **Private IP Protection:** Localhost and RFC-1918 private IP addresses (e.g. `127.0.0.1`, `192.168.x.x`, `10.x.x.x`) are blocked by default (`allow_private_ips => false`).
- **Payload Limits:** Maximum response size capped at 500KB (`max_response_size`), and maximum prompt context capped at 10,000 characters (`max_content_chars`).

---

### 11. `swagger_agent` (Swagger & OpenAPI Documentation Specialist)
The `swagger_agent` generates standardized OpenAPI 3.0+ documentation for backend endpoints.

#### Capabilities
- Analyzes routes, form requests, and controllers generated during the run.
- Generates OpenAPI 3.0+ compliant schemas, parameters, request bodies, and responses.
- Writes documentation in `json`, `yaml`, or `both` formats to `docs/swagger.json` (or configured `output_path`).

#### Activation
Activated when:
- `--swagger` flag is passed to `orcest:build`.
- `LARA_ORCEST_SWAGGER_AUTO_GENERATE=true` is enabled in `.env`.
- The prompt explicitly mentions `api`, `endpoint`, `rest`, or `swagger`.
- `LARA_ORCEST_SWAGGER_CONDITIONAL=true` is enabled and API routes are detected.

```bash
# Example run generating OpenAPI specs
php artisan orcest:build "Create a user notification preference API" --swagger --swagger-format=both
```

---

## 4. Per-Agent Model Routing

Different agents demand different model capabilities. Running high-volume routine tasks (like writing repetitive boilerplate) on expensive reasoning models wastes token budgets, while running architectural planning on small models can result in flawed system design.

LaraOrcest solves this with **Per-Agent Model Routing** (`llm.agent_models`).

### Architecture Flow

```
                               ┌────────────────────────────────┐
                               │   LaraOrcest Agent Executor    │
                               └───────────────┬────────────────┘
                                               │
                                 Agent Model Override Check
                                 Config: llm.agent_models.{agent}
                                               │
                                               ▼
                               ┌────────────────────────────────┐
                               │    OpenAI-Compatible Driver    │
                               │  (options['model'] overridden) │
                               └───────────────┬────────────────┘
                                               │ HTTP POST /v1/chat/completions
                                               ▼
                               ┌────────────────────────────────┐
                               │      LiteLLM / AI Gateway      │
                               └───────┬───────────────┬────────┘
                                       │               │
                     model: "claude-3-5-sonnet"  model: "gpt-4o-mini"
                                       ▼               ▼
                               ┌───────────────┐ ┌───────────────┐
                               │ Anthropic API │ │  OpenAI API   │
                               └───────────────┘ └───────────────┘
```

### Configuration in `.env`

You can assign specific models or LiteLLM aliases to individual agents:

```env
# Default model for unspecified agents
LARA_ORCEST_LLM_MODEL=gpt-4o-mini

# Frontier reasoning models for analytical agents
LARA_ORCEST_MODEL_BUSINESS_ANALYST=claude-3-5-sonnet-20241022
LARA_ORCEST_MODEL_PRODUCT_MANAGER=gpt-4o
LARA_ORCEST_MODEL_ARCHITECT=claude-3-5-sonnet-20241022
LARA_ORCEST_MODEL_REVIEWER_AGENT=claude-3-5-sonnet-20241022

# Fast, cost-efficient models for routine code authoring
LARA_ORCEST_MODEL_GIT_AGENT=gpt-4o-mini
LARA_ORCEST_MODEL_DATABASE_AGENT=gpt-4o-mini
LARA_ORCEST_MODEL_BACKEND_AGENT=gpt-4o-mini
LARA_ORCEST_MODEL_SWAGGER_AGENT=gpt-4o-mini

# Specialized coding / debugging models
LARA_ORCEST_MODEL_QA_AGENT=gpt-4o
LARA_ORCEST_MODEL_FIXER_AGENT=claude-3-5-sonnet-20241022

# Specialized extraction model
LARA_ORCEST_MODEL_WEB_SCRAPING_AGENT=gpt-4o-mini

# Sub-Agent overrides
LARA_ORCEST_MODEL_DOMAIN_RULES_AGENT=gpt-4o-mini
LARA_ORCEST_MODEL_BACKLOG_SPLITTER_AGENT=gpt-4o-mini
LARA_ORCEST_MODEL_DDD_BOUNDARY_AGENT=claude-3-5-sonnet-20241022
LARA_ORCEST_MODEL_SCHEMA_PLANNER_AGENT=claude-3-5-sonnet-20241022
LARA_ORCEST_MODEL_API_CONTRACT_AGENT=claude-3-5-sonnet-20241022
LARA_ORCEST_MODEL_MIGRATION_AGENT=gpt-4o-mini
LARA_ORCEST_MODEL_MODEL_ELOQUENT_AGENT=gpt-4o-mini
LARA_ORCEST_MODEL_SEEDER_AGENT=gpt-4o-mini
LARA_ORCEST_MODEL_REQUEST_VALIDATION_AGENT=gpt-4o-mini
LARA_ORCEST_MODEL_SERVICE_ACTION_AGENT=gpt-4o-mini
LARA_ORCEST_MODEL_CONTROLLER_RESOURCE_AGENT=gpt-4o-mini
LARA_ORCEST_MODEL_FEATURE_TEST_AGENT=gpt-4o
```

---

## 3. Hierarchical Agent Pods & Sub-Agents

When hierarchical teams are enabled (`lara-orcest.teams.enabled = true`), Lead Agents delegate specialized sub-tasks to helper sub-agents organized into 5 functional Pods:

### 1. Discovery & Planning Pod
- **Lead Agents:** `business_analyst`, `product_manager`
- **Sub-Agents:**
  - `domain_rules_agent` (`DomainRulesAgent`): Extracts domain invariants and state transitions.
  - `backlog_splitter_agent` (`BacklogSplitterAgent`): Decomposes requirements into atomic tasks with explicit dependencies.
  - `web_scraping_agent` (`WebScrapingSubAgent`): Extracts and normalizes structured data from two URLs.
- **Assigned Skills:** `business-requirements-analysis`, `backlog-task-decomposition`, `two-site-scraping-dispatch`.

### 2. Architecture Pod
- **Lead Agent:** `architect`
- **Sub-Agents:**
  - `ddd_boundary_agent` (`DddBoundaryAgent`): Layered DDD context boundaries and multi-tenant invariants.
  - `schema_planner_agent` (`SchemaPlannerAgent`): Relational schema tables, foreign keys, and indexes.
  - `api_contract_agent` (`ApiContractAgent`): REST API contracts, routes, and JSON resource schemas.
- **Assigned Skills:** `laravel-modular-ddd-architecture`, `relational-schema-indexing`, `rest-api-contract-design`.

### 3. Database Pod
- **Lead Agent:** `database_agent`
- **Sub-Agents:**
  - `migration_agent` (`MigrationAgent`, Wave 0): Anonymous migrations in `database/migrations/**`.
  - `model_eloquent_agent` (`ModelEloquentAgent`, Wave 1): Eloquent models, relationships, and factories.
  - `seeder_agent` (`SeederAgent`, Wave 2): Idempotent database seeders in `database/seeders/**`.
- **Assigned Skills:** `laravel-safe-migrations`, `eloquent-models-and-factories`, `idempotent-database-seeders`.

### 4. Backend Pod
- **Lead Agent:** `backend_agent`
- **Sub-Agents:**
  - `request_validation_agent` (`RequestValidationAgent`, Wave 0): FormRequests and Policies.
  - `service_action_agent` (`ServiceActionAgent`, Wave 1): Single-responsibility Actions and Services.
  - `controller_resource_agent` (`ControllerResourceAgent`, Wave 2): Thin controllers and JsonResources.
- **Assigned Skills:** `laravel-form-requests-authorization`, `service-action-workflows`, `controllers-resources-routes`.

### 5. Quality, Docs & Release Pod
- **Lead Agents:** `qa_agent`, `reviewer_agent`
- **Sub-Agents:**
  - `feature_test_agent` (`FeatureTestAgent`, Wave 0 under QA): Pest/PHPUnit feature and unit tests in `tests/**`.
  - `fixer_agent` (`FixerSubAgent`, conditional retry under QA): Root-cause bug repairs across `app/**`, `database/**`, `routes/**`, `tests/**`.
  - `swagger_agent` (`SwaggerSubAgent` under Reviewer): OpenAPI 3.0+ documentation in `docs/swagger.json`.
  - `git_agent` (`GitSubAgent` under Reviewer): Milestone release branching (`orcest/...`) and clean commits.
- **Assigned Skills:** `laravel-feature-testing`, `root-cause-test-fixing`, `openapi-swagger-spec`, `safe-git-release`.

### Model Resolution Hierarchy
When an agent executes an LLM turn, `AbstractAgent::resolveModel()` evaluates configuration in the following order:
1. **Agent-Specific Override:** `config("lara-orcest.llm.agent_models.{$agentKey}")`.
2. **Run-Level Override:** CLI `--llm` or `$options['llm']` parameter.
3. **Driver Default:** `config("lara-orcest.llm.drivers.openai_compatible.model")`.

---

## Next Steps
- Learn about the Sandboxed Tools and Human-in-the-Loop gates: **[Safety & HITL Guide](safety-and-hitl.md)**
- Review practical implementation recipes: **[Use Cases](use-cases.md)**
