---
name: root-cause-test-fixing
description: Diagnosing failing test output or review constraints and applying minimal, targeted bug fixes within allowed scope paths and retry budgets.
agents:
  - qa_agent
  - fixer_agent
allowed_tools:
  - file_reader
  - patch_writer
  - artisan_runner
  - test_runner
explore_paths:
  - app/**
  - database/**
  - routes/**
  - tests/**
  - config/**
modify_paths:
  - app/**
  - database/**
  - routes/**
  - tests/**
ignore_paths:
  - vendor/**
  - node_modules/**
  - storage/**
  - bootstrap/cache/**
  - .git/**
  - public/**
  - resources/**
tags:
  - debugging
  - fixing
  - root-cause
  - qa
  - retry-budget
  - bug-resolution
---

# Root-Cause Test Fixing and Debugging Standards

When diagnosing test failures, runtime exceptions, or review rejection constraints:

1. **Root-Cause Analysis Over Symptom Masking:**
   - Carefully inspect stack traces, exception messages, and failing assertion diffs.
   - Diagnose the fundamental defect (e.g., missing database column, incorrect route binding, type mismatch, wrong status code) instead of altering the test expectation to falsely pass.
   - Never suppress exceptions, silence warnings, or comment out failing assertions.

2. **Minimal, Targeted File Patches:**
   - Restrict all modifications strictly to relevant scope paths: `app/**`, `database/**`, `routes/**`, and `tests/**`.
   - Apply surgical patches that fix the specific defect without refactoring unrelated components.
   - Maintain strict typing (`declare(strict_types=1);`) and PSR-12 formatting across modified files.

3. **Respect Retry Budgets & Loop Guardrails:**
   - Every fix execution increments the `fix_attempts` counter towards `max_fix_attempts` (default 3).
   - If a failure cannot be resolved within the retry budget, document the blockage cleanly in the root cause summary and avoid indefinite retry loops.
   - Record root cause summaries and resolutions in RunState metadata for post-run analysis.

4. **Token-Budget & Minimal-Context Rules:**
   - Inspect ONLY `explore_paths`, modify ONLY `modify_paths`, and NEVER scan or load files outside these globs or in `ignore_paths`.
