---
name: controllers-resources-routes
description: Thin controllers delegating to Actions/Services, JsonResource / collection response shaping, consistent HTTP status codes, and clean route registration.
agents:
  - backend_agent
  - controller_resource_agent
allowed_tools:
  - file_reader
  - patch_writer
  - artisan_runner
explore_paths:
  - routes/**
  - app/Http/Controllers/**
  - app/Http/Requests/**
  - app/Http/Resources/**
  - app/Actions/**
  - app/Services/**
  - app/Models/**
modify_paths:
  - app/Http/Controllers/**
  - app/Http/Resources/**
  - routes/**
  - app/Domains/**/Controllers/**
ignore_paths:
  - vendor/**
  - node_modules/**
  - storage/**
  - bootstrap/cache/**
  - .git/**
  - database/**
  - resources/views/**
tags:
  - controllers
  - resources
  - routes
  - api
  - rest
  - laravel
---

# Controllers, API Resources, and Route Registration Standard

When creating API controllers in `app/Http/Controllers/Api/`, API resources in `app/Http/Resources/`, and routes in `routes/api.php`:

1. **Thin Controller Design:**
   - Controllers must remain thin coordinators. Inject FormRequests for validation and Actions/Services for execution.
   - Avoid database queries (`Model::query()->...`) or complex conditionals inside controllers.
   - Controller actions should generally contain 3–5 lines: validate, execute action, return resource.

2. **JsonResource Response Transformers:**
   - Always return typed `JsonResource` or Resource Collections rather than raw Eloquent models or unformatted arrays.
   - Transform attributes explicitly in `toArray(Request $request): array` with camelCase or snake_case matching project conventions.
   - Eager-load relations (`whenLoaded('relation')`) to prevent N+1 query performance degradation.

3. **Consistent HTTP Status Codes:**
   - `200 OK`: Successful retrieval (`index`, `show`) or updates (`update`).
   - `201 Created`: Successful entity creation (`store`).
   - `204 No Content`: Successful deletion without response body (`destroy`).
   - `422 Unprocessable Entity`: Automatic on FormRequest validation failure.

4. **RESTful Route Registration:**
   - Group API routes in `routes/api.php` with clear prefixes and middleware (`auth:sanctum`).
   - Use `Route::apiResource('tasks', TaskController::class);` or explicit HTTP verb methods (`Route::get`, `Route::post`, `Route::put`, `Route::delete`).

5. **Token-Budget & Minimal-Context Rules:**
   - Inspect ONLY `explore_paths`, modify ONLY `modify_paths`, and NEVER scan or load files outside these globs or in `ignore_paths`.
