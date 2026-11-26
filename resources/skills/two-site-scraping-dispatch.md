---
name: two-site-scraping-dispatch
description: Extract and reconcile structured data from two source websites aligned with BA/PM criteria, with SSRF protection, header redaction, and dry_run dispatch safety.
agents:
  - web_scraping_agent
  - product_manager
  - business_analyst
allowed_tools:
  - file_reader
  - http_fetch
  - http_dispatch
explore_paths:
  - config/lara-orcest.php
  - docs/**
  - web_scraping/**
modify_paths:
  - docs/**
  - web_scraping/**
ignore_paths:
  - vendor/**
  - node_modules/**
  - storage/**
  - bootstrap/cache/**
  - .git/**
  - .env*
tags:
  - web-scraping
  - ingestion
  - http-dispatch
  - dry-run
---

# Two-Site Web Scraping & Ingestion Guidelines

When scraping external websites and dispatching normalized data to an endpoint:

1. **Security & SSRF Guardrails:**
   - Validate URL schemes strictly (`http` or `https`).
   - Forbid connections to loopback (`127.0.0.1`, `localhost`, `::1`) and cloud metadata endpoints (`169.254.169.254`, `metadata.google.internal`) unless private IPs are explicitly allowed by configuration.
   - Redact all sensitive tokens and authorization keys from headers in logs, outputs, and review artifacts.

2. **Data Cleaning & Normalization:**
   - Strip markup noise (scripts, styles, SVGs, comments, tracking pixels) before passing web content into the model context window.
   - Reconcile disparate schemas from both websites into a single unified JSON payload guided strictly by the `requirements_spec` and `backlog` criteria.
   - Record warnings for missing fields or data anomalies instead of failing silently.

3. **Dispatch Safety & Dry-Run Mode:**
   - If `dry_run` is enabled, generate and validate the unified payload, but DO NOT issue live mutating HTTP calls to external endpoints; return a preview status with the payload.
   - If not in `dry_run`, dispatch via the configured HTTP method (`POST`, `PUT`, `PATCH`) with timeout and retry guardrails.

4. **Token-Budget & Minimal-Context Rules:**
   - Inspect ONLY `explore_paths`, modify ONLY `modify_paths`, and NEVER scan or load files outside these globs or in `ignore_paths`.
