---
name: laravel-modular-ddd-architecture
description: Design clean Bounded Contexts, layered boundaries (Domain, Application, Infrastructure), multi-tenant isolation, and idiomatic Laravel directory organization.
agents:
  - architect
  - ddd_boundary_agent
  - backend_agent
allowed_tools:
  - file_reader
  - codebase_inspector
explore_paths:
  - composer.json
  - app/Models/**
  - app/Domains/**
  - app/Services/**
  - app/Actions/**
  - app/Providers/**
  - routes/*.php
  - config/**
modify_paths: []
ignore_paths:
  - vendor/**
  - node_modules/**
  - storage/**
  - bootstrap/cache/**
  - .git/**
  - public/**
  - resources/views/**
tags:
  - architecture
  - ddd
  - modular
  - boundaries
  - multi-tenancy
---

# Laravel Modular DDD Architecture Guidelines

When architecting complex Laravel applications with Domain-Driven Design principles:

1. **Layered Separation of Concerns:**
   - **Domain Layer:** Pure business entities, value objects, domain invariants, and repository/service interfaces. No direct HTTP request or framework infrastructure dependencies.
   - **Application Layer:** Orchestration actions, jobs, event listeners, and data transfer objects (DTOs). Coordinates domain objects to fulfill user use-cases.
   - **Infrastructure Layer:** Concrete database queries, external API clients, file system storage, and third-party integrations implementing domain interfaces.
   - **Interfaces / HTTP Layer:** Controllers, FormRequests, API Resources, and Console Commands. Responsible only for transport, input validation, and output formatting.

2. **Bounded Contexts & Modularity:**
   - Group related capabilities into explicit modules or namespaces (e.g. `Billing`, `Subscriptions`, `Identity`, `Catalog`).
   - Prevent cross-boundary database coupling; communicate between contexts via domain events, contracts, or dedicated application services.
   - Avoid over-engineering: for straightforward CRUD features, adhere to idiomatic Laravel conventions (`app/Models`, `app/Services`, `app/Http/Controllers`) without unnecessary abstraction layers.

3. **Multi-Tenant Isolation Invariants:**
   - Enforce tenant isolation at the database, query scope, and policy levels.
   - Every tenant-owned resource must include foreign key linkage (e.g., `tenant_id` or `organization_id`) with database composite indexes.
   - Global scopes or automatic query filtering must be planned to prevent data leakage between tenants.

4. **Token-Budget & Minimal-Context Rules:**
   - Inspect ONLY `explore_paths`, modify ONLY `modify_paths` (none — read-only architecture design), and NEVER scan or load files outside these globs or in `ignore_paths`.
