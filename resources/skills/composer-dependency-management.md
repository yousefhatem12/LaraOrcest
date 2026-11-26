---
name: composer-dependency-management
description: Installing and updating Composer packages requested by technical plan, validating package names/constraints, respecting dry_run and security gates, and recording installed packages.
agents:
  - package_manager_agent
  - dependency_resolver_agent
allowed_tools:
  - composer_runner
  - file_reader
  - codebase_inspector
explore_paths:
  - composer.json
  - composer.lock
  - config/**
modify_paths:
  - composer.json
  - composer.lock
ignore_paths:
  - vendor/**
  - node_modules/**
  - storage/**
  - bootstrap/cache/**
  - .git/**
  - app/**
  - database/**
  - resources/**
tags:
  - composer
  - dependencies
  - packages
  - validation
  - security
---

# Composer Dependency Management Guidelines

When evaluating, installing, and updating Composer packages requested by the `technical_plan` or project requirements:

1. **Package Name & Constraint Validation:**
   - Strictly validate that every requested package conforms to standard Packagist naming conventions: `vendor/package` (alphanumeric, dashes, underscores).
   - Reject any package names containing shell metacharacters, null bytes, or spaces.
   - Always use explicit, stable semantic version constraints (e.g. `^6.0`, `^11.0`) aligned with the target Laravel framework version.
   - Distinguish production dependencies (`composer require <pkg>`) from developer tooling (`composer require --dev <pkg>`, such as pest, phpstan, or laravel-pint).

2. **Secure Execution via `composer_runner`:**
   - Only execute allow-listed Composer subcommands (`require`, `install`, `update`, `show`, `dump-autoload`, `validate`, `audit`).
   - Run commands isolated to `targetProjectPath` via Symfony `Process`.
   - Pass `--no-interaction` to prevent hanging interactive prompts.

3. **Dry-Run Simulation & HITL Safety:**
   - When `dry_run` is active, never execute the real Composer process.
   - Return structured simulation payloads outlining the exact packages, version constraints, and command lines planned.
   - Respect Human-in-the-Loop review gates when dependency changes require human authorization.

4. **State Recording & Lockfile Hygiene:**
   - Record all required packages, versions, and installation outcomes in `RunState` artifacts and metadata.
   - Ensure lockfile consistency and run `composer validate` if needed to confirm valid schema.

5. **Token-Budget & Minimal-Context Rules:**
   - Inspect ONLY `explore_paths`, modify ONLY `modify_paths`, and NEVER scan or load files outside these globs or in `ignore_paths`.
