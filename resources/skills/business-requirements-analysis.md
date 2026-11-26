---
name: business-requirements-analysis
description: Extract functional and non-functional requirements, business rules, domain invariants, edge cases, and authorization boundaries.
agents:
  - business_analyst
  - domain_rules_agent
  - product_manager
allowed_tools:
  - file_reader
explore_paths:
  - composer.json
  - routes/*.php
  - app/Models/*.php
  - app/Domains/**/Models/*.php
  - database/migrations/*.php
modify_paths: []
ignore_paths:
  - vendor/**
  - node_modules/**
  - storage/**
  - bootstrap/cache/**
  - .git/**
  - public/**
  - resources/views/**
tags:
  - requirements
  - analysis
  - domain-invariants
  - edge-cases
---

# Business Requirements & Domain Invariants Analysis

When analyzing user prompts and defining technical specifications for a Laravel project:

1. **Domain Entities & Invariants:**
   - Identify core business entities, their lifecycle states, and primary identifiers.
   - Enforce domain invariants: rules that must ALWAYS hold true regardless of user action (e.g., "An invoice cannot be marked as paid without a valid payment reference", "Tenant data must never be queryable across organization boundaries").
   - Explicitly define allowed and forbidden state machine transitions.

2. **Functional & Non-Functional Specifications:**
   - Functional requirements must be stated with clear inputs, operations, and observable outputs.
   - Non-functional requirements must address data integrity, query performance (indexing foreign keys), authorization policies, and rate limits.
   - Identify roles and permissions: clearly state who is permitted to perform each action using Laravel Gate/Policy paradigms.

3. **Edge Cases & Ambiguity Detection:**
   - Hunt for missing requirements: what happens on network timeouts, concurrent double submissions, negative values, null/empty payloads, or partial transactions?
   - Surface ambiguities early: do not assume default behavior if business implications are significant; document explicit assumptions.

4. **Token-Budget & Minimal-Context Rules:**
   - Inspect ONLY `explore_paths`, modify ONLY `modify_paths` (none — read-only analysis), and NEVER scan or load files outside these globs or in `ignore_paths`.
