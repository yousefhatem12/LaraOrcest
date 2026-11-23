---
name: database-migration-hygiene
description: Best practices for creating reversible migrations, foreign keys, indexes, and Eloquent relationships.
agents:
  - database_agent
  - architect
  - backend_agent
allowed_tools:
  - file_reader
  - patch_writer
  - artisan_runner
tags:
  - database
  - migrations
  - eloquent
---

# Database Migration & Model Hygiene

When authoring database migrations, seeders, and Eloquent models:

1. **Reversible Migrations:**
   - Every `up()` migration must have a matching, fully tested `down()` method that safely drops created columns, indexes, or tables without data loss.
   - Use table-altering migrations (`Schema::table`) rather than recreating tables when adding columns to existing schemas.

2. **Foreign Keys & Indexing:**
   - Always index foreign key columns and frequently filtered fields (e.g. `status`, `created_at`, `tenant_id`).
   - Use `$table->foreignId('user_id')->constrained()->cascadeOnDelete();` for relational integrity.

3. **Eloquent Model Standards:**
   - Define explicit `$fillable` arrays on every model—never use empty `$guarded`.
   - Add return type hints to all relationship methods (e.g. `public function user(): BelongsTo`).
   - Use `$casts` or the `casts(): array` method for date/time columns, booleans, and JSON/array attributes.
