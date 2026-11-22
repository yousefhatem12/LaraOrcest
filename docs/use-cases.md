# Practical Real-World Use Cases

This guide walks you through five comprehensive real-world scenarios using LaraOrcest, demonstrating how to leverage its multi-agent capabilities, safety guardrails, proxy routing, and documentation generators.

---

## Use Case 1: End-to-End Laravel Feature Delivery

### Context & Objective
Your team needs a new **Customer Feedback & Rating API** in an existing Laravel application. The feature requires:
- A database migration creating the `feedbacks` table with columns: `id`, `user_id` (foreign key nullable for guests), `rating` (integer 1-5), `comment` (text nullable), `status` (enum: `pending`, `approved`, `rejected`), timestamps.
- An Eloquent `Feedback` model with proper casts, fillable attributes, and scopes.
- A `StoreFeedbackRequest` form request validating that ratings are between 1 and 5 and comments do not exceed 1,000 characters.
- A `FeedbackController` with `store` (public) and `index` (admin moderation) endpoints.
- Route registration in `routes/api.php`.
- Complete Pest or PHPUnit automated feature tests.
- Comprehensive OpenAPI 3.0+ documentation in `docs/swagger.json`.
- Automatic Git feature branch creation and milestone commit.

### Execution Command
```bash
php artisan orcest:build "Create a customer feedback API with rating (1-5), optional comments, and an admin moderation endpoint" --swagger --swagger-format=json
```

### Agent Progression & Execution Trace

1. **`business_analyst`:**
   - Evaluates domain requirements.
   - Identifies business invariants (ratings must be strictly 1-5; guest feedback is permitted; moderation status defaults to `pending`).
   - Writes `requirements_spec`.

2. **`product_manager`:**
   - Decomposes requirements into 3 prioritized tasks:
     - `task_1`: Database migration and `Feedback` model.
     - `task_2`: Form Request, API Controller, and Route bindings.
     - `task_3`: Feature test suite and validation edge cases.

3. **`architect`:**
   - Generates technical architecture blueprint mapping tasks to standard Laravel paths.

4. **`git_agent`:**
   - Verifies clean Git tree on `main`.
   - Creates and checks out branch `orcest/customer-feedback-api`.

5. **`database_agent`:**
   - Invokes `ArtisanRunnerTool` to run `make:migration create_feedbacks_table`.
   - Uses `PatchWriterTool` to write migration schema and `app/Models/Feedback.php`.

6. **`backend_agent`:**
   - Generates `app/Http/Requests/StoreFeedbackRequest.php`.
   - Generates `app/Http/Controllers/FeedbackController.php`.
   - Registers `Route::apiResource('feedback', FeedbackController::class);` in `routes/api.php`.

7. **`swagger_agent`:**
   - Analyzes routes, form request rules, and controller response structures.
   - Generates `docs/swagger.json` conforming to OpenAPI 3.0.3 with complete schemas, request body samples, and 201/422 response schemas.

8. **`qa_agent`:**
   - Writes `tests/Feature/FeedbackApiTest.php` covering valid submissions, validation failures for ratings > 5, and admin authorization.
   - Runs `TestRunnerTool` (`php artisan test --without-tty`).
   - Tests pass: 4 passed, 10 assertions.

9. **`reviewer_agent`:**
   - Inspects `git diff` against PSR-12 and Laravel best practices.
   - Issues `APPROVED` decision with zero security findings.

10. **Finalization (`GraphEngine`):**
    - Stages all generated artifacts.
    - Commits milestone to `orcest/customer-feedback-api`: `feat(orcest): implement Create a customer feedback API...`.
    - Updates `.lara-orcest/memory.json` with indexed `Feedback` model and architectural decision.

### Expected Artifacts
```text
database/migrations/2026_01_01_create_feedbacks_table.php
app/Models/Feedback.php
app/Http/Requests/StoreFeedbackRequest.php
app/Http/Controllers/FeedbackController.php
docs/swagger.json
tests/Feature/FeedbackApiTest.php
```

---

## Use Case 2: Two-Site Web Scraping & Ingestion Pipeline

### Context & Objective
You need to extract product pricing and availability data from two vendor websites, compare and normalize the schemas based on your business rules, and dispatch the unified payload to your internal catalog ingestion webhook (`https://api.mycompany.test/webhooks/vendor-catalog`).

The scraping must strictly follow:
- Extraction guided by `business_analyst` invariants and `product_manager` acceptance criteria.
- Scheme and IP safety (no access to internal/private IPs without explicit authorization).
- Preview safety using `--dry-run` to inspect extracted payloads before sending live network requests.

### Step 1: Previewing with `--dry-run`
Test the extraction and normalization without sending HTTP requests to the target endpoint:

```bash
php artisan orcest:build "Scrape vendor pricing from https://vendor-alpha.example.com/product/123 and https://vendor-beta.example.com/item/456 and dispatch unified catalog payload to https://api.mycompany.test/webhooks/vendor-catalog" --dry-run
```

#### What Happens in Dry-Run:
1. `business_analyst` specifies required fields: `sku`, `price`, `currency`, `in_stock`, `vendor_name`.
2. `product_manager` drafts mapping criteria reconciling price formats and currency symbols.
3. `web_scraping_agent`:
   - Fetches both URLs within safety limits (capped at 500KB response size, 10,000 characters prompt context).
   - Generates `site_1_data`, `site_2_data`, and `unified_payload`.
   - Records the dispatch action as `preview`.
   - Writes structured preview to `artifacts`: `web_scraping/payload.json`.
4. The terminal displays the unified payload and extraction summary with zero live side-effects.

### Step 2: Executing the Live Scraping Pipeline
Once verified, execute the live dispatch:

```bash
php artisan orcest:build "Scrape vendor pricing from https://vendor-alpha.example.com/product/123 and https://vendor-beta.example.com/item/456 and dispatch unified catalog payload to https://api.mycompany.test/webhooks/vendor-catalog"
```

### Resulting Output in `RunState` & Artifacts
The payload is dispatched via HTTP POST to the target endpoint, and the run records the following in `$state->metadata['web_scraping']`:

```json
{
  "source_urls": [
    "https://vendor-alpha.example.com/product/123",
    "https://vendor-beta.example.com/item/456"
  ],
  "target_endpoint": "https://api.mycompany.test/webhooks/vendor-catalog",
  "http_method": "POST",
  "extraction_summary": "Extracted SKU 123 from Vendor Alpha ($49.99, In Stock) and SKU 456 from Vendor Beta ($52.00, In Stock). Normalized to USD.",
  "unified_payload": {
    "normalized_sku": "SKU-PROD-123",
    "lowest_price": 49.99,
    "currency": "USD",
    "sources": [
      { "vendor": "Alpha", "price": 49.99, "available": true },
      { "vendor": "Beta", "price": 52.00, "available": true }
    ],
    "timestamp": "2026-11-22T20:30:00Z"
  },
  "dispatch": {
    "status_code": 200,
    "successful": true
  }
}
```

---

## Use Case 3: Multi-Model Routing via LLM Proxy (LiteLLM)

### Context & Objective
Running all tasks on top-tier reasoning models (like `claude-3-5-sonnet` or `o3-mini`) can be prohibitively expensive. Conversely, small models often produce flawed architectural decisions and overlook security edge cases.

In this use case, we configure **heterogeneous per-agent model routing** through a local or centralized [LiteLLM Proxy](https://github.com/BerriAI/litellm):
- **Frontier Reasoning Models (`claude-3-5-sonnet`):** For `architect`, `reviewer_agent`, and `fixer_agent`.
- **Fast / Cost-Effective Models (`gpt-4o-mini`):** For routine authoring in `database_agent`, `backend_agent`, `git_agent`, and `swagger_agent`.

### Step 1: Start LiteLLM Proxy
Create a `litellm-config.yaml` file (see `examples/litellm-config.yaml`) and start LiteLLM:

```bash
litellm --config examples/litellm-config.yaml --port 4000
```

### Step 2: Configure Laravel `.env`
Point LaraOrcest to the LiteLLM proxy endpoint and configure per-agent model routing:

```env
# Point to LiteLLM Proxy
LARA_ORCEST_LLM=openai_compatible
LARA_ORCEST_LLM_BASE_URL=http://127.0.0.1:4000/v1
LARA_ORCEST_LLM_API_KEY=sk-litellm-secret-key

# Default fallback model
LARA_ORCEST_LLM_MODEL=gpt-4o-mini

# Analytical & Reasoning Roles -> High-tier reasoning
LARA_ORCEST_MODEL_BUSINESS_ANALYST=claude-3-5-sonnet
LARA_ORCEST_MODEL_PRODUCT_MANAGER=gpt-4o
LARA_ORCEST_MODEL_ARCHITECT=claude-3-5-sonnet
LARA_ORCEST_MODEL_REVIEWER_AGENT=claude-3-5-sonnet
LARA_ORCEST_MODEL_FIXER_AGENT=claude-3-5-sonnet

# Routine Code Generation -> Fast & cheap
LARA_ORCEST_MODEL_GIT_AGENT=gpt-4o-mini
LARA_ORCEST_MODEL_DATABASE_AGENT=gpt-4o-mini
LARA_ORCEST_MODEL_BACKEND_AGENT=gpt-4o-mini
LARA_ORCEST_MODEL_SWAGGER_AGENT=gpt-4o-mini
LARA_ORCEST_MODEL_QA_AGENT=gpt-4o-mini
```

### Step 3: Run Orchestration
Run any feature prompt:
```bash
php artisan orcest:build "Add two-factor recovery codes generation endpoint"
```

### Outcome & Benefits
- High-level architecture and final security audits are performed by Claude 3.5 Sonnet.
- Eloquent migrations, standard boilerplate, and Swagger documentation are generated by GPT-4o Mini.
- **Result:** ~70% reduction in token costs with zero degradation in code quality or architectural rigor.

---

## Use Case 4: Human-in-the-Loop Approval vs. Unattended Execution

### Scenario A: Interactive Developer Review (`--interactive`)
When working on core domain logic, you want to review the database schema and technical plan before the agent starts writing code.

#### 1. Start Interactive Execution
```bash
php artisan orcest:build "Add subscription billing with Stripe webhook handlers" --interactive
```

#### 2. Gate Triggered (`after_plan`)
Execution runs through `business_analyst`, `product_manager`, and `architect`, then halts:

```text
[Step 1] business_analyst ............. Requirements specification drafted
[Step 2] product_manager .............. Backlog with 4 tasks prioritized
[Step 3] architect .................... Technical plan generated

⏸ LaraOrcest paused for human review at gate: [after_plan] [Run ID: run_67e1ff9801a]
Review Summary: Technical plan and backlog generated. Paused for human plan approval.
Planned Tasks: 4 tasks

To approve and resume execution:
  php artisan orcest:approve run_67e1ff9801a
```

#### 3. Inspecting the Proposal
Check the detailed state of the run before deciding:
```bash
php artisan orcest:status run_67e1ff9801a
```

#### 4. Approving the Plan
Resume execution:
```bash
php artisan orcest:approve run_67e1ff9801a --note="Approved schema and webhook signature validation"
```

The run resumes seamlessly, executes `git_agent`, `database_agent`, `backend_agent`, and `qa_agent`. If `before_commit` gate is enabled, it pauses once more for a final review before committing to Git.

---

### Scenario B: Unattended CI/CD Automation (`--no-human-review`)
In continuous integration pipelines or overnight batch jobs, human review is impossible. You need the agent to run autonomously, relying on automated tests and the `reviewer_agent` audit to decide completion.

#### 1. Run Unattended
```bash
php artisan orcest:build "Create healthcheck status endpoint returning redis, db, and cache status" --no-human-review
```

#### 2. Execution Behavior:
- All Human-in-the-Loop review gates (`after_plan`, `before_commit`) are bypassed.
- If automated tests fail, `fixer_agent` attempts repairs up to `max_fix_attempts` (default: 3).
- If tests pass and `reviewer_agent` approves, changes are automatically committed to the feature branch.
- If tests fail after 3 attempts, the command exits with code `1` (failing the CI build step).

---

## Use Case 5: Multi-Vendor / Multi-Tenant Platform using Domain-Driven Design (DDD)

### Context & Architecture Goal
Your engineering team is architecting an enterprise **Multi-Vendor Marketplace & SaaS Core** in Laravel. Unlike standard CRUD applications, this system requires strict **Domain-Driven Design (DDD)** layered boundaries and **absolute multi-tenant data isolation**:

1. **Three Bounded Contexts:**
   - **`Tenancy` Context:** Vendor onboarding, tenant provisioning, lifecycle management (`pending`, `active`, `suspended`), and tenant resolution middleware (`TenantResolverMiddleware`) resolving active tenant context via `X-Tenant-ID` header or authenticated vendor sessions.
   - **`Catalog` Context:** Vendor-managed product catalog, inventory tracking, and SKU uniqueness scoped strictly per tenant.
   - **`Orders` Context:** Customer checkout supporting multi-vendor carts, an `OrderSplitter` domain service that splits line items into vendor-specific sub-orders, and commission calculations.
2. **Strict Multi-Tenant Invariants:**
   - Every operational entity (`products`, `inventory`, `orders`, `order_items`) must be scoped by an indexed `tenant_id`.
   - Global Eloquent `TenantScope` must automatically apply to all catalog and order queries.
   - Cross-tenant access attempts (e.g. Vendor A attempting to inspect or modify Vendor B's inventory) must fail with `404 Not Found` to prevent leaking entity existence.
3. **Domain-Driven Directory Layout:**
   - Code must be organized by Bounded Context under `app/Domains/{Tenancy,Catalog,Orders}/` with isolated layers: `Domain/` (entities, value objects, domain events, repository interfaces), `Application/` (use-case actions, DTOs), `Infrastructure/` (Eloquent models, repositories, database migrations), and `Interfaces/Http/` (controllers, requests, resources).

---

### Recommended Model Routing (`llm.agent_models`)
Domain-Driven Design and multi-tenant security demand rigorous architectural reasoning. Generic LLMs often leak framework dependencies into the Domain layer or forget global tenant scopes.

We configure **high-reasoning frontier models** for analytical and auditing agents, while routing routine code authoring and testing to fast models:

```env
# High-reasoning models: Architecture, invariants, and staff audit
LARA_ORCEST_MODEL_BUSINESS_ANALYST=claude-3-5-sonnet-20241022
LARA_ORCEST_MODEL_PRODUCT_MANAGER=gpt-4o
LARA_ORCEST_MODEL_ARCHITECT=claude-3-5-sonnet-20241022
LARA_ORCEST_MODEL_REVIEWER_AGENT=claude-3-5-sonnet-20241022

# Cost-effective models: Routine migrations, models, and controllers
LARA_ORCEST_MODEL_DATABASE_AGENT=gpt-4o-mini
LARA_ORCEST_MODEL_BACKEND_AGENT=gpt-4o-mini
LARA_ORCEST_MODEL_SWAGGER_AGENT=gpt-4o-mini
LARA_ORCEST_MODEL_GIT_AGENT=gpt-4o-mini

# Dedicated coding & debugging models
LARA_ORCEST_MODEL_QA_AGENT=gpt-4o
LARA_ORCEST_MODEL_FIXER_AGENT=claude-3-5-sonnet-20241022
```

---

### Step-by-Step Agent Flow

```
                      ┌────────────────────────────────────────┐
                      │            business_analyst            │
                      │  (Extracts tenant isolation rules,     │
                      │   order-splitting & boundary invariants)│
                      └───────────────────┬────────────────────┘
                                          │
                                          ▼
                      ┌────────────────────────────────────────┐
                      │            product_manager             │
                      │  (Partitions into 4 tasks with         │
                      │   depends_on & scope_paths metadata)   │
                      └───────────────────┬────────────────────┘
                                          │
                                          ▼
                      ┌────────────────────────────────────────┐
                      │               architect                │
                      │  (Designs DDD directory structure,     │
                      │   Value Objects, Actions & TenantScope)│
                      └───────────────────┬────────────────────┘
                                          │
                                  [after_plan Gate]
                                  (Paused for HITL)
                                          │
                                          ▼
                      ┌────────────────────────────────────────┐
                      │               git_agent                │
                      │  (Creates orcest/multi-vendor-ddd-core)│
                      └───────────────────┬────────────────────┘
                                          │
                      ┌───────────────────┴────────────────────┐
                      │      Parallel Wave Scheduling          │
                      │  Wave 0: Tenancy Core                  │
                      │  Wave 1: Catalog & Orders Contexts     │
                      └───────────────────┬────────────────────┘
                                          │
                                          ▼
                      ┌────────────────────────────────────────┐
                      │        database & backend agents       │
                      │  (Authors migrations, Eloquent models, │
                      │   actions, requests, and controllers)  │
                      └───────────────────┬────────────────────┘
                                          │
                                          ▼
                      ┌────────────────────────────────────────┐
                      │             swagger_agent              │
                      │  (Generates OpenAPI spec with          │
                      │   X-Tenant-ID header security scheme)  │
                      └───────────────────┬────────────────────┘
                                          │
                                          ▼
                      ┌────────────────────────────────────────┐
                      │                qa_agent                │
                      │  (Writes cross-tenant isolation tests &│
                      │   executes php artisan test)           │
                      └───────────────────┬────────────────────┘
                                          │
                                  [Tests Passing?]
                                  ├── No ──► fixer_agent (patches leak)
                                  ▼ Yes
                      ┌────────────────────────────────────────┐
                      │             reviewer_agent             │
                      │  (Audits DDD layer purity & security)  │
                      └───────────────────┬────────────────────┘
                                          │
                                 [before_commit Gate]
                                          │
                                          ▼
                      ┌────────────────────────────────────────┐
                      │    Milestone Commit & Memory Store     │
                      └────────────────────────────────────────┘
```

#### 1. `business_analyst` (Requirements & Invariants)
- Identifies three Bounded Contexts: `Tenancy`, `Catalog`, and `Orders`.
- Formulates hard domain invariants:
  - **Invariant 1 (Mandatory Tenant Scoping):** Every table in Catalog and Orders must have an indexed `tenant_id` foreign key.
  - **Invariant 2 (Context Resolution):** Inbound HTTP requests must resolve tenant identity via `X-Tenant-ID` header or authenticated vendor credentials; missing or invalid tenants abort with `401 Unauthorized` or `400 Bad Request`.
  - **Invariant 3 (Order Splitting):** Single customer checkouts with items from multiple vendors must execute an atomic split into vendor-specific sub-orders.
  - **Invariant 4 (Zero Cross-Tenant Leakage):** Queries filtered by tenant must return `404 Not Found` if a vendor attempts to access another vendor's resource.

#### 2. `product_manager` (Task Decomposition & Scope Metadata)
Breaks down the feature into prioritized tasks annotated with dependencies (`depends_on`) and filesystem boundaries (`scope_paths`):
- `task_1`: **Tenancy Context Core**
  - Scope: `app/Domains/Tenancy/*`, `database/migrations/*_create_tenants_table.php`.
- `task_2`: **Catalog Context & Tenant Scoping**
  - Depends On: `task_1`.
  - Scope: `app/Domains/Catalog/*`, `database/migrations/*_create_products_table.php`.
- `task_3`: **Orders Context & Order Splitting Service**
  - Depends On: `task_1`, `task_2`.
  - Scope: `app/Domains/Orders/*`, `database/migrations/*_create_orders_table.php`.
- `task_4`: **Cross-Tenant Feature Test Suite & OpenAPI Docs**
  - Depends On: `task_1`, `task_2`, `task_3`.
  - Scope: `tests/Feature/*`, `docs/swagger.json`.

#### 3. `architect` (DDD System Design & Layering)
Designs the domain architecture conforming to clean architecture and DDD standards:
- **Value Objects:** `TenantId`, `ProductId`, `OrderId`, `Money` (immutable currency and amount).
- **Domain Services:** `OrderSplitter` responsible for partitioning line items into vendor sub-orders.
- **Global Scope:** `TenantScope` applying `where('tenant_id', '=', TenantContext::id())`.
- **Directory Layout:**
  ```text
  app/Domains/
  ├── Tenancy/
  │   ├── Domain/
  │   │   ├── Model/Tenant.php
  │   │   ├── ValueObject/TenantId.php
  │   │   ├── Enum/TenantStatus.php
  │   │   └── Repository/TenantRepositoryInterface.php
  │   ├── Application/
  │   │   ├── Actions/RegisterVendorAction.php
  │   │   └── DTO/VendorRegistrationData.php
  │   ├── Infrastructure/
  │   │   ├── Persistence/EloquentTenantRepository.php
  │   │   └── Scopes/TenantScope.php
  │   └── Interfaces/Http/
  │       ├── Controllers/VendorRegistrationController.php
  │       └── Middleware/TenantResolverMiddleware.php
  ├── Catalog/
  │   ├── Domain/
  │   │   ├── Model/Product.php
  │   │   ├── ValueObject/ProductId.php
  │   │   ├── ValueObject/Money.php
  │   │   └── ValueObject/SKU.php
  │   ├── Application/
  │   │   ├── Actions/CreateProductAction.php
  │   │   └── Actions/UpdateInventoryAction.php
  │   ├── Infrastructure/
  │   │   └── Persistence/EloquentProductRepository.php
  │   └── Interfaces/Http/
  │       ├── Controllers/ProductCatalogController.php
  │       └── Requests/StoreProductRequest.php
  └── Orders/
      ├── Domain/
      │   ├── Model/Order.php
      │   ├── Model/OrderLineItem.php
      │   └── Services/OrderSplitter.php
      ├── Application/
      │   └── Actions/PlaceCustomerOrderAction.php
      ├── Infrastructure/
      │   └── Persistence/EloquentOrderRepository.php
      └── Interfaces/Http/
          ├── Controllers/CheckoutController.php
          └── Resources/OrderResource.php
  ```

#### 4. `database_agent` & `backend_agent` (Parallel Wave Implementation)
- **Tenancy Implementation:** Authors migrations for `tenants` and `users` tables, `Tenant` model, and `TenantResolverMiddleware`.
- **Parallel Scheduling:** Because `Catalog` and `Orders` have non-overlapping `scope_paths`, `ParallelScheduler` executes their boilerplate generations across concurrent branches:
  - `database_agent` creates `products` table with compound unique index `['tenant_id', 'sku']` and `orders` table with foreign keys.
  - `backend_agent` implements `StoreProductRequest` enforcing per-tenant SKU validation:
    ```php
    Rule::unique('products')->where(fn ($query) => $query->where('tenant_id', $tenantId))
    ```
  - `backend_agent` implements `OrderSplitter` domain service:
    ```php
    public function split(CustomerCart $cart): Collection
    {
        return $cart->items->groupBy('product.tenant_id')->map(function ($items, $tenantId) {
            return new VendorSubOrder($tenantId, $items);
        });
    }
    ```

#### 5. `swagger_agent` (OpenAPI Spec with Tenant Headers)
- Inspects `TenantResolverMiddleware` and generates OpenAPI 3.0+ specifications in `docs/swagger.json`.
- Automatically injects the required tenant header parameter:
  ```json
  "parameters": [
    {
      "name": "X-Tenant-ID",
      "in": "header",
      "required": true,
      "schema": { "type": "string", "format": "uuid" },
      "description": "Unique identifier of the tenant context"
    }
  ]
  ```

#### 6. `qa_agent` & `fixer_agent` (Cross-Tenant Security Verification)
`qa_agent` writes focused feature tests asserting tenant isolation:
- `test_vendor_can_only_view_own_catalog_products()`
- `test_vendor_cannot_mutate_another_vendors_product()`: Asserts that querying product of Vendor A with Vendor B's tenant context yields `404 Not Found`.
- `test_request_without_tenant_header_is_rejected_by_middleware()`
- `test_multi_vendor_checkout_splits_orders_correctly()`
- Invokes `TestRunnerTool` (`php artisan test --without-tty`). If any query leaks cross-tenant records, `fixer_agent` intercepts the failure, patches the repository to include `TenantScope`, and re-runs tests until all pass.

#### 7. `reviewer_agent` & `git_agent`
- `reviewer_agent` audits the codebase:
  - Verifies that Domain models contain no Eloquent or database coupling.
  - Confirms all database queries are scoped by `tenant_id`.
  - Approves the implementation.
- `git_agent` stages all artifacts and commits to feature branch `orcest/multi-vendor-ddd-core`.

---

### Example CLI Commands

#### 1. Dry-Run Architectural Preview
Inspect the proposed DDD structure and tasks before writing any code:
```bash
php artisan orcest:build \
  "Build a Multi-Vendor Marketplace Core with Tenancy, Catalog, and Orders Bounded Contexts using Domain-Driven Design" \
  --dry-run
```

#### 2. Interactive Run with Human Review Gates
Run with interactive review gates (`after_plan` and `before_commit`) enabled:
```bash
php artisan orcest:build \
  "Build a Multi-Vendor Marketplace Core with Tenancy, Catalog, and Orders Bounded Contexts using Domain-Driven Design" \
  --interactive \
  --swagger \
  --swagger-format=both
```

#### 3. Inspecting Paused State
When execution pauses at `after_plan`, inspect the proposed DDD architecture:
```bash
php artisan orcest:status
php artisan orcest:status run_67e2a9b401f89
```

#### 4. Approving the Architectural Plan
Approve the proposed Bounded Contexts, Value Objects, and TenantScope:
```bash
php artisan orcest:approve run_67e2a9b401f89 --note="Approved DDD layout, Bounded Context boundaries, and TenantScope strategy"
```

#### 5. Unattended CI/CD Execution
For automated testing in continuous delivery pipelines:
```bash
php artisan orcest:build \
  "Build a Multi-Vendor Marketplace Core with Tenancy, Catalog, and Orders Bounded Contexts using Domain-Driven Design" \
  --no-human-review \
  --swagger
```

---

## Next Steps
- Review reference configuration files: **[Examples Directory](../examples/)**
- Check tool sandboxing details: **[Safety & HITL Guide](safety-and-hitl.md)**
