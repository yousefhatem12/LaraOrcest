# Multi-Vendor / Multi-Tenant Platform with Domain-Driven Design (DDD)

This reference example demonstrates how to orchestrate the generation of a complex **Multi-Vendor / Multi-Tenant Marketplace Core** using **Domain-Driven Design (DDD)** principles in Laravel with **LaraOrcest**.

---

## 1. Orchestration Prompt

```text
Build a Multi-Vendor Marketplace Core with Tenancy, Catalog, and Orders Bounded Contexts using Domain-Driven Design:

1. Tenancy Bounded Context (app/Domains/Tenancy):
   - Tenant Model with id (uuid), name, slug, status (enum: pending, active, suspended).
   - Value Objects: TenantId, TenantStatus.
   - TenantResolverMiddleware: Resolves active tenant context from 'X-Tenant-ID' header or authenticated vendor, setting TenantContext.
   - RegisterVendorAction: Handles vendor self-registration and tenant initialization.
   - VendorRegistrationController: POST /api/v1/vendors/register.

2. Catalog Bounded Context (app/Domains/Catalog):
   - Product Model with id (uuid), tenant_id (foreign key), name, sku, price, stock_quantity, is_active.
   - Value Objects: ProductId, Money (amount, currency), SKU.
   - Tenant-Scoped Security: Compound unique index on ['tenant_id', 'sku'].
   - Global TenantScope: Automatically scopes all Product queries to the active TenantContext.
   - ProductCatalogController: Full CRUD for vendor catalog.
   - StoreProductRequest: Validates SKU uniqueness scoped strictly to the current tenant.

3. Orders Bounded Context (app/Domains/Orders):
   - Order & OrderLineItem Models: Customer orders containing items from potentially multiple vendors.
   - OrderSplitter Domain Service: Partitions multi-vendor customer carts into vendor-specific sub-orders.
   - PlaceCustomerOrderAction: Atomically creates parent order and vendor sub-orders with status 'pending_payment'.
   - CheckoutController: POST /api/v1/orders/checkout.

4. Cross-Tenant Security & Testing:
   - Feature tests asserting that Vendor A cannot view, update, or delete Vendor B's products (must return 404).
   - Tests verifying unauthenticated requests or missing X-Tenant-ID headers are rejected with 401/400.
   - Tests asserting that multi-vendor checkouts split correctly into vendor orders.

5. Documentation:
   - Generate OpenAPI 3.0+ specifications in docs/swagger.json with X-Tenant-ID header parameters and vendor bearer token schemes.
```

---

## 2. Recommended Per-Agent Model Routing (`.env`)

For complex architectural tasks like Domain-Driven Design, route analytical agents to frontier reasoning models while routing implementation boilerplate to cost-effective models:

```env
# ------------------------------------------------------------------------------
# Per-Agent Model Routing for DDD Architecture
# ------------------------------------------------------------------------------
# Analytical & Architectural Reasoning
LARA_ORCEST_MODEL_BUSINESS_ANALYST=claude-3-5-sonnet-20241022
LARA_ORCEST_MODEL_PRODUCT_MANAGER=gpt-4o
LARA_ORCEST_MODEL_ARCHITECT=claude-3-5-sonnet-20241022
LARA_ORCEST_MODEL_REVIEWER_AGENT=claude-3-5-sonnet-20241022

# Code Implementation & Boilerplate Generation
LARA_ORCEST_MODEL_DATABASE_AGENT=gpt-4o-mini
LARA_ORCEST_MODEL_BACKEND_AGENT=gpt-4o-mini
LARA_ORCEST_MODEL_SWAGGER_AGENT=gpt-4o-mini
LARA_ORCEST_MODEL_GIT_AGENT=gpt-4o-mini

# Automated Testing & Cyclic Bug Fixing
LARA_ORCEST_MODEL_QA_AGENT=gpt-4o
LARA_ORCEST_MODEL_FIXER_AGENT=claude-3-5-sonnet-20241022
```

---

## 3. Expected Bounded Context Structure

LaraOrcest generates code organized cleanly into four layers per Bounded Context:

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

---

## 4. CLI Execution Recipes

### A. Previewing with `--dry-run`
Inspect the proposed Bounded Contexts, migrations, and task list without writing files:

```bash
php artisan orcest:build \
  "Build a Multi-Vendor Marketplace Core with Tenancy, Catalog, and Orders Bounded Contexts using Domain-Driven Design" \
  --dry-run
```

### B. Interactive Human-in-the-Loop Review
Pause at the `after_plan` gate to inspect the DDD architecture before code generation:

```bash
php artisan orcest:build \
  "Build a Multi-Vendor Marketplace Core with Tenancy, Catalog, and Orders Bounded Contexts using Domain-Driven Design" \
  --interactive \
  --swagger \
  --swagger-format=both
```

When paused:
```bash
# Check status and proposed backlog
php artisan orcest:status

# Approve and resume
php artisan orcest:approve {run_id} --note="Approved DDD boundaries and tenant scoping"
```

### C. Unattended CI/CD Execution
Run continuously in automated continuous integration environments:

```bash
php artisan orcest:build \
  "Build a Multi-Vendor Marketplace Core with Tenancy, Catalog, and Orders Bounded Contexts using Domain-Driven Design" \
  --no-human-review \
  --swagger
```

---

## 5. Programmatic Execution Sample

```php
use Yousef\LaraOrcest\Facades\Orcest;
use Yousef\LaraOrcest\State\RunState;

$prompt = <<<'PROMPT'
Build a Multi-Vendor Marketplace Core with Tenancy, Catalog, and Orders Bounded Contexts using Domain-Driven Design:
- Strict TenantScope isolation across all catalog and order queries.
- TenantResolverMiddleware resolving X-Tenant-ID.
- OrderSplitter domain service partitioning multi-vendor carts.
- Cross-tenant security tests in tests/Feature/.
- OpenAPI 3.0+ documentation in docs/swagger.json.
PROMPT;

$state = Orcest::run($prompt, [
    'swagger' => true,
    'swagger_format' => 'both',
    'interactive' => true,
    'on_step' => function (RunState $s, string $node, string $msg) {
        logger()->info("[LaraOrcest Step {$s->guardrails['step_count']}] {$node}: {$msg}");
    },
]);

if ($state->isPaused()) {
    // Gate reached: inspect technical plan and approve
    $runId = $state->runId;
    $state = Orcest::approve($runId, 'Plan approved by Lead Architect');
}
```
