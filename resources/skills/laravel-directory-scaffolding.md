---
name: laravel-directory-scaffolding
description: Creating clean directory layouts (DDD/modular app/Domains/... or standard Laravel layout) and base files/routes inside targetProjectPath within PathGuard and scopePaths.
agents:
  - package_manager_agent
  - structure_scaffolder_agent
allowed_tools:
  - directory_scaffolder
  - file_reader
  - patch_writer
  - codebase_inspector
tags:
  - scaffolding
  - directories
  - ddd
  - modular
  - architecture
---

# Laravel Directory Scaffolding Guidelines

When scaffolding directory hierarchies and foundational file structures inside the target workspace:

1. **Architectural Layout Alignment:**
   - Inspect the `technical_plan` to identify whether the target architecture is standard Laravel or Modular Domain-Driven Design (DDD).
   - **Standard Layout:** `app/Models/`, `app/Http/Controllers/`, `app/Http/Requests/`, `app/Services/`, `database/migrations/`, `database/seeders/`.
   - **Modular / DDD Layout:** `app/Domains/{Domain}/Models/`, `app/Domains/{Domain}/Actions/`, `app/Domains/{Domain}/DTOs/`, `app/Domains/{Domain}/Repositories/`, `app/Domains/{Domain}/Providers/`.

2. **Sandboxed Directory Creation:**
   - Use `directory_scaffolder` with strict `PathGuard` boundaries.
   - Directories must be within permitted `scopePaths` (e.g. `app/**`, `routes/**`, `config/**`, `database/**`).
   - Never allow directory creation that traverses (`../`) or escapes `targetProjectPath`.

3. **Starter Files & Foundational Code:**
   - When creating starter files (e.g. domain service providers, module route files, base contracts), ensure they use strict typing (`declare(strict_types=1);`) and PSR-12 styling.
   - Place `.gitkeep` files in empty scaffolded directories to guarantee directory preservation in source control.

4. **Dry-Run & Idempotency:**
   - In `dry_run` mode, return planned directory lists and file paths without creating directories on disk.
   - Handle pre-existing directories gracefully without throwing errors or duplicating entries.
