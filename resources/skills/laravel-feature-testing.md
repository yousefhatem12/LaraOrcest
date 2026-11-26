---
name: laravel-feature-testing
description: Writing comprehensive Feature and Unit tests covering happy paths, validation errors, authorization gates, and multi-tenant isolation invariants.
agents:
  - qa_agent
  - feature_test_agent
allowed_tools:
  - file_reader
  - patch_writer
  - test_runner
  - artisan_runner
explore_paths:
  - routes/**
  - app/Models/**
  - app/Http/Controllers/**
  - app/Http/Requests/**
  - app/Http/Resources/**
  - app/Actions/**
  - database/migrations/**
  - database/factories/**
  - tests/**
modify_paths:
  - tests/**
ignore_paths:
  - vendor/**
  - node_modules/**
  - storage/**
  - bootstrap/cache/**
  - .git/**
  - resources/views/**
tags:
  - testing
  - feature-tests
  - unit-tests
  - qa
  - pest
  - phpunit
  - acceptance-criteria
---

# Laravel Feature and Unit Testing Standards

When authoring automated tests in `tests/Feature/` or `tests/Unit/`:

1. **Strict Typing & Modern Test Architecture:**
   - Always declare `declare(strict_types=1);` in every test file.
   - For PHPUnit: extend `Tests\TestCase` and use `Illuminate\Foundation\Testing\RefreshDatabase` trait for database isolation.
   - For Pest: define tests using `test(...)` or `it(...)` with clear behavioral descriptions mirroring backlog acceptance criteria.

2. **Comprehensive Scenario Coverage:**
   - **Happy Path:** Verify correct HTTP status codes (`200 OK`, `201 Created`, `204 No Content`) and assert returned JSON structures (`assertJsonStructure`, `assertJsonPath`).
   - **Validation Failures:** Explicitly test invalid payloads, missing required fields, and boundary values; assert `422 Unprocessable Entity` with `assertJsonValidationErrors(['field'])`.
   - **Authorization Gates:** Test unauthenticated requests (`401 Unauthorized`) and unauthorized access (`403 Forbidden`) across roles and permission boundaries.
   - **Tenant & Domain Isolation:** Ensure users cannot access or mutate resources belonging to another tenant or project boundary.

3. **State Hygiene & Factory Usage:**
   - Use Eloquent model factories (`Model::factory()->create()`) for test arrange phase rather than raw DB queries.
   - Assert database side-effects using `assertDatabaseHas` and `assertDatabaseMissing`.
   - Verify dispatched events or jobs using `Event::fake()` or `Queue::fake()` where applicable.

4. **Directory Conformance:**
   - Restrict all test file creations strictly to `tests/**` (`tests/Feature/**` or `tests/Unit/**`).

5. **Token-Budget & Minimal-Context Rules:**
   - Inspect ONLY `explore_paths`, modify ONLY `modify_paths`, and NEVER scan or load files outside these globs or in `ignore_paths`.
