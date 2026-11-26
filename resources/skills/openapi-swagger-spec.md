---
name: openapi-swagger-spec
description: Generating and updating clean OpenAPI 3.0+ / Swagger specifications matching routes, FormRequests, API resources, and status codes.
agents:
  - reviewer_agent
  - swagger_agent
allowed_tools:
  - file_reader
  - patch_writer
  - artisan_runner
explore_paths:
  - routes/**
  - app/Http/Controllers/**
  - app/Http/Requests/**
  - app/Http/Resources/**
  - app/Models/**
  - docs/**
  - storage/api-docs/**
  - public/swagger.json
  - swagger.json
  - swagger.yaml
modify_paths:
  - docs/**
  - storage/api-docs/**
  - public/swagger.json
  - public/swagger.yaml
  - swagger.json
  - swagger.yaml
  - openapi.json
  - openapi.yaml
ignore_paths:
  - vendor/**
  - node_modules/**
  - storage/logs/**
  - bootstrap/cache/**
  - .git/**
  - database/seeders/**
  - tests/**
tags:
  - swagger
  - openapi
  - api-docs
  - documentation
  - reviewer
  - rest-api
---

# OpenAPI 3.0+ / Swagger Documentation Standards

When generating or updating API documentation in `docs/**`, `storage/api-docs/**`, or `openapi.json` / `openapi.yaml`:

1. **Specification Compliance:**
   - Target OpenAPI version `3.0.3` (or `3.1.0` where configured).
   - Ensure complete root objects: `openapi`, `info` (title, version, description), `paths`, `components` (schemas, securitySchemes).
   - Produce valid, parseable JSON or YAML without syntax errors.

2. **Path & Operation Accuracy:**
   - Every route from `routes/api.php` and controller actions must be documented with corresponding HTTP method (`get`, `post`, `put`, `patch`, `delete`).
   - Include descriptive `summary`, `description`, and appropriate `tags` (grouping endpoints by domain/resource).
   - Document path parameters (`in: path`, `required: true`) and query parameters (`in: query`) with strict data types.

3. **Request Bodies & Response Schemas:**
   - Mirror FormRequest validation rules in `requestBody` schemas (required properties, string lengths, patterns, enums).
   - Document standard response codes:
     - `200 OK` or `201 Created` with realistic JSON response schemas mirroring `JsonResource` transformers.
     - `400 Bad Request` / `422 Unprocessable Entity` validation error schemas.
     - `401 Unauthorized` / `403 Forbidden` for protected endpoints.
     - `404 Not Found` for resource lookups.

4. **Scope Safety:**
   - Restrict file output strictly to configured API documentation paths (`docs/swagger.json`, `docs/openapi.yaml`, `storage/api-docs/**`).

5. **Token-Budget & Minimal-Context Rules:**
   - Inspect ONLY `explore_paths`, modify ONLY `modify_paths`, and NEVER scan or load files outside these globs or in `ignore_paths`.
