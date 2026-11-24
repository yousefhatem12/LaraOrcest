---
name: laravel-project-bootstrapping
description: Guidelines for bootstrapping a clean Laravel project inside targetProjectPath, initializing .env and APP_KEY safely, and handling already-initialized target directories idempotently.
agents:
  - package_manager_agent
  - laravel_installer_agent
allowed_tools:
  - composer_runner
  - file_reader
  - patch_writer
  - artisan_runner
  - codebase_inspector
tags:
  - laravel
  - bootstrap
  - installation
  - composer
  - environment
---

# Laravel Project Bootstrapping Guidelines

When bootstrapping or verifying a Laravel project inside an isolated target workspace (`targetProjectPath`):

1. **Idempotency & Pre-flight Inspection:**
   - Always inspect the target directory before attempting to run installation commands.
   - If `composer.json` or `artisan` is already present, treat the target directory as **already initialized**.
   - Do NOT attempt to run destructive commands, re-run `composer create-project` into an occupied workspace, or overwrite existing application code.
   - For already-initialized workspaces, verify environment readiness (e.g. existence of `.env` or `.env.example`) and proceed safely.

2. **Fresh Project Initialization (`composer create-project`):**
   - If the target directory is empty or uninitialized, plan and invoke:
     `composer create-project laravel/laravel . --prefer-dist --no-interaction`
   - Use `composer_runner` to execute the command within the target project root.
   - Respect `dry_run` mode: when `dry_run` is enabled, generate and return the planned bootstrap actions without executing shell processes or mutating disk.

3. **Safe Environment & Key Configuration:**
   - Ensure an environment file (`.env`) is prepared from `.env.example` if `.env` does not already exist.
   - Trigger `php artisan key:generate` safely via `artisan_runner` to set the application encryption key (`APP_KEY`).
   - Strictly avoid leaking, echoing, or logging raw environment secrets or encryption keys. Redact any sensitive tokens before recording artifacts or output summaries.

4. **Sandbox Boundary Enforcement:**
   - All filesystem operations and process executions must be strictly confined to `targetProjectPath` through `PathGuard`.
   - Never attempt to write or execute outside the defined workspace boundary.
