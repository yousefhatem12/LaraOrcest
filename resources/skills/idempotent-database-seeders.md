---
name: idempotent-database-seeders
description: Standards for writing structured, relational, and idempotent database seeders that populate development fixtures without duplicating records on re-runs.
agents:
  - database_agent
  - seeder_agent
allowed_tools:
  - file_reader
  - patch_writer
  - artisan_runner
tags:
  - seeders
  - database
  - fixtures
  - idempotent
  - laravel
---

# Idempotent Database Seeders Standard

When creating database seeders in `database/seeders/`:

1. **Strict Idempotency:**
   - Seeders must be safe to run multiple times without throwing unique constraint violations or duplicating data.
   - Prefer `firstOrCreate()`, `updateOrCreate()`, or `upsert()` over naive `create()` or raw `insert()`.
   - Match records on natural business keys (e.g. `['email' => 'admin@example.com']` or `['slug' => 'default-project']`).

2. **Structured & Ordered Seeding:**
   - Separate seeders by domain entity (e.g. `UserSeeder`, `RoleSeeder`, `ProjectSeeder`, `TaskSeeder`).
   - Wire all seeders in correct foreign key order inside `DatabaseSeeder::run()` via `$this->call([...])`.

3. **Factory & Relational Composition:**
   - For bulk demo data, leverage model factories with explicit relationship attachment.
   - Never hardcode production secrets or sensitive credentials in seeders. Use environment variables with safe development fallbacks.
