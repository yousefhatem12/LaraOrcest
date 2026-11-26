---
name: laravel-strict-standards
description: Enforce declare(strict_types=1), typed class properties, FormRequest validation, and idiomatic Laravel conventions.
agents:
  - backend_agent
  - architect
  - fixer_agent
  - qa_agent
  - reviewer_agent
  - database_agent
allowed_tools:
  - file_reader
  - patch_writer
explore_paths:
  - app/**
  - database/**
  - routes/**
  - tests/**
  - config/**
modify_paths: []
ignore_paths:
  - vendor/**
  - node_modules/**
  - storage/**
  - bootstrap/cache/**
  - .git/**
  - public/**
tags:
  - php
  - laravel
  - standards
  - typing
---

# Laravel Strict Standards

When implementing or modifying code in this Laravel application, always adhere strictly to the following standards:

1. **Strict Typing:**
   - Every single PHP file must declare `declare(strict_types=1);` at the very top immediately following the opening `<?php` tag.
   - Use explicit scalar and class type hints on all method arguments, return types, and class properties.
   - Avoid `mixed` unless interfacing with dynamic polymorphic payloads; prefer union types or generics where supported.

2. **Controller & Endpoint Architecture:**
   - Keep controllers thin. Do not execute heavy business logic or raw database queries inside controllers.
   - Delegate input validation to dedicated `FormRequest` classes inside `app/Http/Requests/`.
   - Never use `$request->all()`; always validate and use `$request->validated()`.
   - Return typed API Resource responses (`JsonResource`) from `app/Http/Resources/` for JSON APIs.

3. **Error Handling & Security:**
   - Avoid catching generic `\Exception` unless logging and re-throwing a domain-specific exception.
   - Never expose internal database error messages, stack traces, or environment secrets to HTTP clients.
   - Always verify authorization via Policies (`$this->authorize(...)` or `Gate::authorize(...)`).

4. **Token-Budget & Minimal-Context Rules:**
   - Inspect ONLY `explore_paths`, modify ONLY `modify_paths` (standards guideline — read-only), and NEVER scan or load files outside these globs or in `ignore_paths`.
