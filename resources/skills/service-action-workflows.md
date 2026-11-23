---
name: service-action-workflows
description: Clean Action/Service classes, database transactions where needed, domain events/exceptions, and DDD layer alignment without bloating controllers.
agents:
  - backend_agent
  - service_action_agent
allowed_tools:
  - file_reader
  - patch_writer
  - artisan_runner
tags:
  - actions
  - services
  - transactions
  - events
  - ddd
  - workflows
  - laravel
---

# Service and Action Workflows Standard

When implementing business logic, actions, and service workflows in `app/Actions/`, `app/Services/`, or `app/Domains/**/Actions/`:

1. **Single-Responsibility Action Pattern:**
   - Separate distinct state-changing business operations into discrete Action classes with an `execute()` or `handle()` method.
   - Actions should be named clearly after the business task they perform (e.g. `CreateTaskAction`, `AssignMemberToProjectAction`, `CancelSubscriptionAction`).
   - Type-hint input arguments explicitly using primitive types, models, or typed DTOs.

2. **Database Transaction Safety:**
   - Wrap operations that modify multiple database tables or dispatch external notifications in `DB::transaction(function () { ... })` to prevent partial updates and orphaned state.
   - Keep transactions concise to avoid holding database locks unnecessarily.

3. **Domain Events & Exception Handling:**
   - Dispatch domain events via `event(new TaskCreated($task))` after successful state transitions.
   - Throw explicit, strongly-typed domain exceptions (e.g. `QuotaExceededException`, `InvalidStateTransitionException`) rather than generic `\Exception` or `\RuntimeException`.
   - Never catch exceptions silently without logging or re-throwing.

4. **Decoupled Architecture:**
   - Prevent controller bloat. Controllers should only be responsible for parsing HTTP requests, invoking Actions/Services, and returning HTTP responses.
