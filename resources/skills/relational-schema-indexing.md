---
name: relational-schema-indexing
description: Design normalized database schemas, primary/foreign key relationships, composite indexes, soft-deletes, and chronological migration ordering.
agents:
  - architect
  - schema_planner_agent
  - database_agent
allowed_tools:
  - file_reader
  - codebase_inspector
tags:
  - database
  - schema
  - indexes
  - migrations
  - foreign-keys
---

# Relational Database Schema & Indexing Standards

When planning database schemas, tables, relationships, and migration sequencing:

1. **Table Normalization & Integrity:**
   - Define explicit column types, character limits, nullability, and default values for every field.
   - Primary keys must use auto-incrementing big integers (`$table->id()`) or UUIDs/ULIDs where distributed uniqueness is required.
   - Use `$table->foreignId('parent_id')->constrained()->cascadeOnDelete();` for referential integrity.
   - Use explicit timestamps (`created_at`, `updated_at`) and soft deletes (`deleted_at`) where auditability or historical recovery is necessary.

2. **Indexing & Query Performance:**
   - Index all foreign key columns to avoid full table scans during joins and cascade lookups.
   - Define composite indexes for frequently paired query conditions (e.g., `['tenant_id', 'status']`, `['user_id', 'created_at']`).
   - Add unique constraints to natural business identifiers (e.g., `['organization_id', 'slug']` or `['email']`).

3. **Chronological Migration Sequencing:**
   - Migrations must be ordered chronologically so parent tables are created before child tables reference them via foreign keys.
   - Table-altering migrations must be segregated from table-creation migrations.
   - Every migration must be reversible with a clean, symmetry-preserving `down()` method.
