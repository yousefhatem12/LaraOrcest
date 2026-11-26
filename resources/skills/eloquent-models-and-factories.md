---
name: eloquent-models-and-factories
description: Standards for creating rich, strictly-typed Eloquent models, relationships, casts, scopes, and realistic model factories.
agents:
  - database_agent
  - model_eloquent_agent
allowed_tools:
  - file_reader
  - patch_writer
  - artisan_runner
explore_paths:
  - database/migrations/**
  - app/Models/**
  - app/Domains/**/Models/**
  - database/factories/**
modify_paths:
  - app/Models/**
  - app/Domains/**/Models/**
  - database/factories/**
ignore_paths:
  - vendor/**
  - node_modules/**
  - storage/**
  - bootstrap/cache/**
  - .git/**
  - app/Http/**
  - routes/**
  - resources/**
tags:
  - eloquent
  - models
  - factories
  - laravel
  - relationships
---

# Eloquent Models and Model Factories Standard

When implementing Eloquent models in `app/Models/` (or `app/Domains/**/Models/`) and model factories in `database/factories/`:

1. **Model Architecture & Strict Typing:**
   - Every model must declare `declare(strict_types=1);`.
   - Use `$fillable` with explicit permitted attributes to guard against mass-assignment vulnerabilities (avoid empty `$guarded = []` in production models).
   - Use modern attribute casting via `protected function casts(): array` (or `protected $casts = [...]`):
     - Cast dates/timestamps to `datetime` or `immutable_datetime`.
     - Cast JSON columns to `array` or custom DTOs/Value Objects.
     - Cast boolean flags and enum columns explicitly.

2. **Strict Typed Relationships:**
   - Define explicit return types on all relationship methods:
     - `public function user(): BelongsTo`
     - `public function items(): HasMany`
     - `public function roles(): BelongsToMany`
   - Include reverse reciprocal relationships on both related models.

3. **Tenant & Global Query Scopes:**
   - Apply tenant-isolation scopes or global scopes (`apply(Builder $builder, Model $model)`) when multi-tenancy invariants require automatic filtering by `tenant_id` or `team_id`.

4. **Realistic Model Factories:**
   - Pair every model with a factory using `use HasFactory;`.
   - In `definition()`, provide realistic fake data using `fake()` helpers (e.g. `fake()->company()`, `fake()->unique()->safeEmail()`).
   - Use state transformations (e.g. `unverified()`, `published()`) for common domain variations.

4. **Token-Budget & Minimal-Context Rules:**
   - Inspect ONLY `explore_paths`, modify ONLY `modify_paths`, and NEVER scan or load files outside these globs or in `ignore_paths`.
