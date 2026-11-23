---
name: rest-api-contract-design
description: Define consistent REST endpoints, HTTP status codes, validation payloads, API Resource response transformations, auth/policy checks, and OpenAPI contracts.
agents:
  - architect
  - api_contract_agent
  - backend_agent
  - swagger_agent
allowed_tools:
  - file_reader
  - codebase_inspector
tags:
  - api
  - rest
  - contracts
  - openapi
  - resources
---

# REST API Contract & Interface Design

When designing RESTful APIs and endpoint interfaces in Laravel:

1. **RESTful Resource URIs & Verbs:**
   - Use plural nouns for resources (e.g., `/api/v1/projects`, `/api/v1/projects/{project}/tasks`).
   - Use standard HTTP verbs: `GET` (read), `POST` (create), `PUT`/`PATCH` (update), `DELETE` (remove).
   - Return appropriate HTTP status codes:
     - `200 OK` for successful reads and updates.
     - `201 Created` with created resource for successful creations.
     - `204 No Content` for successful deletions.
     - `401 Unauthorized` for missing/invalid authentication.
     - `403 Forbidden` for policy authorization failures.
     - `404 Not Found` for missing models or endpoints.
     - `422 Unprocessable Content` with standard validation error envelopes.

2. **Validation Requests & Response Transformers:**
   - Every mutating request must have a dedicated `FormRequest` class in `app/Http/Requests/`.
   - Every endpoint returning model data must transform output via an explicit `JsonResource` in `app/Http/Resources/` to prevent internal database field leakage (e.g. hashed passwords, internal foreign keys).

3. **Authentication & Authorization Contracts:**
   - Specify route middleware: `auth:sanctum` or session auth.
   - Map each action to a Policy method (e.g., `view`, `create`, `update`, `delete`) inside `app/Policies/`.
   - Ensure the API specification is aligned with OpenAPI 3.0 / Swagger schema conventions.
