---
name: safe-git-release
description: Managing feature branches (orcest/...), clean milestone commits, sensitive file exclusion, and dry-run preview rules.
agents:
  - reviewer_agent
  - git_agent
allowed_tools:
  - git_command
  - file_reader
explore_paths:
  - composer.json
  - routes/**
  - app/**
  - database/**
  - tests/**
  - docs/**
modify_paths: []
ignore_paths:
  - vendor/**
  - node_modules/**
  - storage/**
  - bootstrap/cache/**
  - .git/**
  - .env*
tags:
  - git
  - release
  - branching
  - commit-hygiene
  - safety
  - milestone
---

# Safe Git Release and Branching Standards

When managing Git operations, branch isolation, and milestone commits:

1. **Branch Isolation Conventions:**
   - Always operate on dedicated feature branches prefixed with `orcest/` (e.g. `orcest/feature-task-management`).
   - Never commit directly to protected branches: `main`, `master`, `production`, `staging`.
   - Check current branch before performing any operations; branch from the verified base branch cleanly.

2. **Milestone Commit Hygiene:**
   - Stage only verified, deliberate artifacts belonging to the completed milestone.
   - Write clear, imperative commit messages following standard conventions (e.g. `feat(tasks): implement API endpoints and feature tests`).
   - Exclude dirty, untracked, or non-project files.

3. **Sensitive File & Secret Protection:**
   - Under NO circumstances stage `.env`, `.env.*`, private keys, SSL certificates, credentials, or vendor directories.
   - Validate `.gitignore` compliance before staging files.

4. **Dry-Run & Human-in-the-Loop Simulation:**
   - In dry-run mode (`--dry-run`), simulate Git branch creation and commit operations without touching disk or repo HEAD.
   - When Human-in-the-Loop gate `before_commit` is active, hold commit execution until explicit approval is granted.

5. **Token-Budget & Minimal-Context Rules:**
   - Inspect ONLY `explore_paths`, modify ONLY `modify_paths` (none — Git release operations only), and NEVER scan or load files outside these globs or in `ignore_paths`.
