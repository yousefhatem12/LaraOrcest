---
name: laravel-form-requests-authorization
description: FormRequest validation rules, authorization (authorize() / policies), tenant-scoping checks, and safe input handling.
agents:
  - backend_agent
  - request_validation_agent
allowed_tools:
  - file_reader
  - patch_writer
  - artisan_runner
explore_paths:
  - app/Models/**
  - app/Domains/**/Models/**
  - app/Http/Requests/**
  - app/Policies/**
  - database/migrations/**
modify_paths:
  - app/Http/Requests/**
  - app/Policies/**
  - app/Domains/**/Requests/**
  - app/Domains/**/Policies/**
ignore_paths:
  - vendor/**
  - node_modules/**
  - storage/**
  - bootstrap/cache/**
  - .git/**
  - database/seeders/**
  - resources/views/**
tags:
  - validation
  - form-requests
  - authorization
  - policies
  - laravel
  - security
---

# Laravel FormRequests and Authorization Standards

When implementing FormRequests in `app/Http/Requests/` (or `app/Domains/**/Requests/`) and Policies in `app/Policies/`:

1. **Strict Typing & FormRequest Structure:**
   - Every FormRequest must declare `declare(strict_types=1);` and extend `Illuminate\Foundation\Http\FormRequest`.
   - The `authorize()` method must verify whether the authenticated user has permission to perform the action, delegating to Gate/Policy or returning a boolean check.
   - Always validate incoming inputs using typed array rules in `rules(): array`.

2. **Input Validation Rules:**
   - Never trust client inputs. Apply explicit scalar validation (`string`, `integer`, `boolean`, `numeric`, `array`).
   - Use `Rule::exists(...)` and `Rule::unique(...)` with explicit table and column references.
   - For multi-tenant applications, always scope uniqueness and existence checks by tenant:
     `Rule::exists('projects', 'id')->where('tenant_id', $this->user()->tenant_id)`.

3. **Safe Consumption:**
   - Never call `$request->all()` in downstream controllers or actions.
   - Always consume sanitized inputs using `$request->validated()` or `$request->safe()`.

4. **Policy Authorization Gates:**
   - Implement policies in `app/Policies/` with explicit method signatures matching standard CRUD operations (`viewAny`, `view`, `create`, `update`, `delete`, `restore`, `forceDelete`).
   - Return strict boolean values or `Illuminate\Auth\Access\Response` objects.

5. **Token-Budget & Minimal-Context Rules:**
   - Inspect ONLY `explore_paths`, modify ONLY `modify_paths`, and NEVER scan or load files outside these globs or in `ignore_paths`.
