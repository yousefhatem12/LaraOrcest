---
name: laravel-safe-migrations
description: Clean Laravel migration conventions, column types, foreign keys, composite/unique indexes, safe rollbacks (down()), and ordering from technical_plan.
agents:
  - database_agent
  - migration_agent
allowed_tools:
  - file_reader
  - patch_writer
  - artisan_runner
explore_paths:
  - database/migrations/**
  - app/Models/**
  - app/Domains/**/Models/**
modify_paths:
  - database/migrations/**
ignore_paths:
  - vendor/**
  - node_modules/**
  - storage/**
  - bootstrap/cache/**
  - .git/**
  - app/Http/**
  - routes/**
  - resources/**
  - tests/**
tags:
  - database
  - migrations
  - schema
  - indexes
  - laravel
---

# Laravel Safe Migrations Standard

When creating or modifying database migration files in `database/migrations/`:

1. **Anonymous Migration Classes & Structure:**
   - Always return an anonymous migration class: `return new class extends Migration { ... };`.
   - Include strict typing: `declare(strict_types=1);` at the top of every migration file.
   - Separate table creation into `up()` and symmetric teardown in `down()`.

2. **Column Types & Constraints:**
   - Use `$table->id()` for primary keys (auto-incrementing unsignedBigInteger) or `$table->uuid('id')->primary()` where required.
   - Use explicit data types: `$table->string('name', 255)`, `$table->text('description')`, `$table->boolean('is_active')->default(true)`, `$table->json('payload')->nullable()`, `$table->timestamp('published_at')->nullable()`.
   - Never leave foreign keys unconstrained; use `$table->foreignId('parent_id')->constrained('parents')->cascadeOnDelete();`.

3. **Performance Indexing & Constraints:**
   - Add single-column or composite indexes for frequently queried columns: `$table->index(['tenant_id', 'status']);`.
   - Add unique constraints for logical business keys: `$table->unique(['project_id', 'slug']);`.

4. **Rollback Symmetry:**
   - Every `up()` must have a corresponding, non-destructive `down()`:
     - Table creation: `Schema::dropIfExists('table_name');`.
     - Added columns: `$table->dropForeign(['user_id']); $table->dropColumn('user_id');`.
   - Never omit or leave `down()` empty.

4. **Token-Budget & Minimal-Context Rules:**
   - Inspect ONLY `explore_paths`, modify ONLY `modify_paths`, and NEVER scan or load files outside these globs or in `ignore_paths`.
