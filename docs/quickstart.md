# Quickstart Guide

This guide walks you through setting up, configuring, and executing **LaraOrcest** (`yousef/lara-orcest`) inside a local Laravel application.

---

## 1. Prerequisites

Before installing LaraOrcest, verify that your environment meets the minimum requirements:

- **PHP:** 8.2 or higher
- **Laravel Framework:** 10.x, 11.x, or 12.x
- **Git:** Initialized in your target Laravel repository (`git rev-parse --is-inside-work-tree` returns `true`)
- **Composer:** 2.2 or higher
- **LLM Access:** An API key for OpenAI, Anthropic, Gemini, OpenRouter, or a local/remote OpenAI-compatible proxy (such as [LiteLLM](https://github.com/BerriAI/litellm) or Ollama).

---

## 2. Local Setup via Composer `path` Repository

To link and develop with the local `LaraOrcest` repository during development, register it as a Composer `path` repository inside your Laravel application's `composer.json`:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../LaraOrcest",
            "options": {
                "symlink": true
            }
        }
    ],
    "require-dev": {
        "yousef/lara-orcest": "*@dev"
    },
    "minimum-stability": "dev",
    "prefer-stable": true
}
```

Run `composer update` to install the package:

```bash
composer update yousef/lara-orcest
```

Once published to Packagist, standard installation requires only:

```bash
composer require yousef/lara-orcest --dev
```

---

## 3. Publish Configuration & Migrations

### Publish Configuration
Publish the package configuration file to `config/lara-orcest.php`:

```bash
php artisan vendor:publish --tag=lara-orcest-config
```

### Publish Checkpointer Migrations (Optional)
By default, LaraOrcest uses the lightweight filesystem checkpointer driver (`json`), storing run state snapshots inside `.lara-orcest/runs/`.

If you prefer database persistence (recommended for teams and production environments):

```bash
php artisan vendor:publish --tag=lara-orcest-migrations
php artisan migrate
```

Then set your checkpointer driver in `.env`:
```env
LARA_ORCEST_CHECKPOINTER=database
```

---

## 4. Configure Environment Variables

Add the necessary configuration keys to your Laravel application's `.env` file. By default, LaraOrcest connects to any OpenAI-compatible HTTP endpoint.

### Direct OpenAI Setup
```env
# Enable LaraOrcest
LARA_ORCEST_ENABLED=true

# LLM Driver Configuration
LARA_ORCEST_LLM=openai_compatible
LARA_ORCEST_LLM_BASE_URL=https://api.openai.com/v1
LARA_ORCEST_LLM_API_KEY=sk-proj-your-openai-api-key-here
LARA_ORCEST_LLM_MODEL=gpt-4o-mini
LARA_ORCEST_LLM_TIMEOUT=60
LARA_ORCEST_LLM_RETRIES=2
```

### LiteLLM Proxy Setup (Recommended for Enterprise)
If you run a local or remote LiteLLM proxy:
```env
LARA_ORCEST_ENABLED=true
LARA_ORCEST_LLM=openai_compatible
LARA_ORCEST_LLM_BASE_URL=http://127.0.0.1:4000/v1
LARA_ORCEST_LLM_API_KEY=sk-litellm-proxy-secret-key
LARA_ORCEST_LLM_MODEL=gpt-4o-mini
```

---

## 5. Previewing Execution with `--dry-run`

Before allowing agents to write files or create Git branches, preview the entire workflow safely using the `--dry-run` flag:

```bash
php artisan orcest:build "Create a customer feedback API with ratings and comments" --dry-run
```

### What Happens in Dry-Run Mode?
1. `business_analyst` analyzes the prompt and writes domain requirements.
2. `product_manager` drafts a prioritized backlog of tasks with acceptance criteria.
3. `architect` formulates a complete technical blueprint (migrations, models, controllers, routes).
4. `PatchWriterTool` intercepts all file modifications, logging them without modifying your disk.
5. `GitCommandTool` skips branch creation and commits.
6. The CLI displays the proposed tasks, mock artifacts, and step summaries cleanly.

---

## 6. Running a Full Build

When you are ready to generate code, run `orcest:build` without the `--dry-run` flag:

```bash
php artisan orcest:build "Create a customer feedback API with ratings (1-5), optional comments, and an admin moderation endpoint"
```

### Real-Time Console Output
LaraOrcest provides real-time visibility into each execution node:

```text
Starting LaraOrcest execution
Prompt: Create a customer feedback API with ratings (1-5), optional comments, and an admin moderation endpoint

[Step 1] business_analyst ............. Domain requirements and entities analyzed
[Step 2] product_manager .............. Backlog with 3 prioritized tasks created
[Step 3] architect .................... Architecture plan formulated
[Step 4] git_agent .................... Feature branch created [orcest/customer-feedback-api]
[Step 5] database_agent ............... Generated migration and Feedback model
[Step 6] backend_agent ................ Generated FeedbackController and FormRequests
[Step 7] qa_agent ..................... Generated tests/Feature/FeedbackApiTest.php
[Step 8] qa_agent ..................... Running test suite: php artisan test --without-tty
[Step 9] reviewer_agent ............... PSR-12 and security audit: APPROVED

✔ LaraOrcest completed successfully! [Run ID: run_67e1a3b890f12]

Backlog Tasks ................................................. 3 tasks planned
┌────────┬──────────────────────────────────────────┬──────────┬────────────────┐
│ ID     │ Title                                    │ Priority │ Criteria Count │
├────────┼──────────────────────────────────────────┼──────────┼────────────────┤
│ task_1 │ Feedback Migration and Eloquent Model    │ 1        │ 3              │
│ task_2 │ Feedback Submission & Moderation API     │ 2        │ 4              │
│ task_3 │ Feature Tests for Feedback Submission    │ 3        │ 4              │
└────────┴──────────────────────────────────────────┴──────────┴────────────────┘

Artifacts ....................................... 5 files generated/updated
┌────────────────────────────────────────────────────────┬────────┬────────────────────────────────┐
│ Path                                                   │ Action │ Summary                        │
├────────────────────────────────────────────────────────┼────────┼────────────────────────────────┤
│ database/migrations/2026_01_01_create_feedbacks_table  │ create │ Create feedbacks table         │
│ app/Models/Feedback.php                                │ create │ Eloquent model with casts      │
│ app/Http/Requests/StoreFeedbackRequest.php             │ create │ Form request validation rules  │
│ app/Http/Controllers/FeedbackController.php            │ create │ Submission & moderation logic  │
│ tests/Feature/FeedbackApiTest.php                      │ create │ Feature test coverage          │
└────────────────────────────────────────────────────────┴────────┴────────────────────────────────┘

Automated Tests ......................................................... PASSED
Summary: Tests: 4 passed (8 assertions)

Reviewer Sign-off ..................................................... APPROVED
 - Code adheres strictly to Laravel 11/12 conventions and PSR-12 formatting.
 - Authorization logic correctly prevents non-admin moderation.

Git Feature Branch ................................ orcest/customer-feedback-api
Steps Taken .................................................................. 9
Fix Attempts ............................................................. 0 / 3
Total Tokens Used ....................................................... 12,450
```

---

## 7. Inspecting Runs with `orcest:status`

To inspect the history of recent orchestration runs:

```bash
php artisan orcest:status
```

Output:
```text
Recent LaraOrcest Execution Runs:
┌─────────────────────┬───────────────────────────────────────────────┬──────────────────┬───────────┬─────────────────────┐
│ Run ID              │ Prompt                                        │ Current Node     │ Status    │ Updated At          │
├─────────────────────┼───────────────────────────────────────────────┼──────────────────┼───────────┼─────────────────────┤
│ run_67e1a3b890f12   │ Create a customer feedback API with rating... │ __completed__    │ completed │ 2026-11-22 20:15:30 │
│ run_67e199f1a0b34   │ Add Stripe webhook handling for subscrip...   │ after_plan       │ paused    │ 2026-11-22 19:40:12 │
└─────────────────────┴───────────────────────────────────────────────┴──────────────────┴───────────┴─────────────────────┘
```

To view comprehensive telemetry, artifacts, test output, and token usage for a specific run:

```bash
php artisan orcest:status run_67e1a3b890f12
```

---

## 8. Resuming Paused or Incomplete Runs

If a run paused at a Human-in-the-Loop gate (e.g. `after_plan` or `before_commit`) or halted unexpectedly, resume execution using the `--resume` option:

```bash
php artisan orcest:build --resume=run_67e199f1a0b34
```

### Resuming with Decision Flags
You can provide approval or rejection flags directly on resume:

```bash
# Approve the paused gate with notes
php artisan orcest:build --resume=run_67e199f1a0b34 --approve --note="Approved by lead engineer"

# Reject the paused plan and terminate the run
php artisan orcest:build --resume=run_67e199f1a0b34 --reject --note="Requires multi-tenant partitioning"
```

Alternatively, use the dedicated review command:
```bash
php artisan orcest:approve run_67e199f1a0b34 --note="Approved"
```

---

## 9. Common CLI Flags Reference

| Option | Description |
| :--- | :--- |
| `--prompt="Your prompt"` | Prompt string (alternative to argument). |
| `--dry-run` | Previews changes without touching the filesystem or Git. |
| `--interactive` | Enables interactive Human-in-the-Loop review gates. |
| `--no-human-review` | Completely bypasses human review gates for unattended CI/CD automation. |
| `--resume={run_id}` | Resumes a paused or stopped run from its persisted state checkpoint. |
| `--approve` | Approves a paused review gate when resuming. |
| `--reject` | Rejects a paused review gate when resuming. |
| `--note="Comment"` | Attaches an approval or rejection note. |
| `--swagger` | Enables automatic OpenAPI/Swagger documentation generation via `swagger_agent`. |
| `--no-swagger` | Explicitly disables Swagger generation. |
| `--swagger-format=json\|yaml\|both` | Sets the output format for generated OpenAPI specs. |
| `--swagger-path=docs/swagger.json` | Custom destination file path for Swagger specs. |
| `--no-scrape` | Skips `web_scraping_agent` even if URLs are present in prompt. |
| `--no-memory` | Disables Project Memory recall and post-run consolidation. |
| `--max-steps=40` | Overrides the maximum graph step guardrail limit. |
| `--max-fix-attempts=3` | Overrides the maximum cyclic bug-fix retry budget. |
| `--llm=ollama` | Overrides the default LLM driver for this specific run. |

---

## Next Steps
- Learn how the engine works under the hood: **[Architecture Guide](architecture.md)**
- Discover all specialized agents: **[Agents Guide](agents.md)**
- Read about sandbox tools and approval gates: **[Safety & HITL Guide](safety-and-hitl.md)**
- Explore practical real-world scenarios: **[Use Cases](use-cases.md)**
