---
name: scoped-file-editing
description: Best practices for atomic, scoped patch application avoiding regressions and honoring directory allow-lists.
agents:
  - backend_agent
  - database_agent
  - fixer_agent
  - git_agent
allowed_tools:
  - file_reader
  - patch_writer
tags:
  - editing
  - patching
  - safety
---

# Scoped File Editing Best Practices

When generating patches or creating files in the repository:

1. **Strict Directory Scoping:**
   - Always verify that the file path falls within your assigned `scopePaths` or permitted prefixes before invoking `patch_writer`.
   - Never attempt to modify files in `.env`, `.git/`, `vendor/`, or `node_modules/`.

2. **Atomic & Minimal Changes:**
   - Prefer surgical `replace` operations over complete file overwrites when modifying existing classes, routes, or configuration.
   - Target blocks must be exact matches of existing file content, including whitespace and indentation.
   - Verify that new imports (`use ...;`) are added cleanly in alphabetical order at the top of the file without creating duplicates.

3. **Read Before Writing:**
   - Inspect existing files via `file_reader` before applying patches to understand existing namespace declarations, trait usages, and class inheritance.
