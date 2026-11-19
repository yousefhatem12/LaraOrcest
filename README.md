<p align="center">
  <img src="art/logo.png" alt="LaraOrcest Logo" width="300" height="300">
</p>

<h1 align="center">LaraOrcest</h1>

<p align="center">
  <strong>Prompt-driven multi-agent orchestration for Laravel with specialized roles, stateful graph execution, automated tests, and safe Git workflows.</strong>
</p>

<p align="center">
  <a href="#built-in-agent-roster">Agents</a> •
  <a href="#local-development-setup">Setup</a> •
  <a href="#detailed-architecture--spec">Architecture</a> •
  <a href="./spec.md">Technical Spec</a>
</p>

---

## Vision

`LaraOrcest` brings a role-based multi-agent pod (CrewAI style) and a stateful directed graph with bounded feedback loops (LangGraph style) directly into Laravel applications via Composer—without requiring an external Python service.

## Built-in Agent Roster

1. **Business Analyst (`business_analyst`):** Extracts functional requirements, business rules, roles, and edge cases.
2. **Product Manager (`product_manager`):** Prioritizes tasks and defines clear acceptance criteria.
3. **Architect (`architect`):** Maps tasks to an idiomatic Laravel technical plan (migrations, models, actions/services, controllers, routes, tests).
4. **Git Agent (`git_agent`):** Creates a dedicated feature branch and records scoped commits after verified milestones.
5. **Database Agent (`database_agent`):** Creates and updates migrations, relationships, factories, and Eloquent models.
6. **Backend Agent (`backend_agent`):** Implements controllers, form requests, domain/service classes, and routes.
7. **QA Agent (`qa_agent`):** Writes tests based on acceptance criteria and runs the test suite.
8. **Fixer Agent (`fixer_agent`):** Diagnoses failures or review feedback and applies scoped fixes within retry limits.
9. **Reviewer Agent (`reviewer_agent`):** Audits changes against requirements, acceptance criteria, security, and scope guardrails.

## Local Development Setup

1. Create the standalone package repository (`LaraOrcest`).
2. Link it to a fresh Laravel test application using a local `path` repository in the test app's `composer.json`:

```json
"repositories": [
  {
    "type": "path",
    "url": "../LaraOrcest",
    "options": {
      "symlink": true
    }
  }
]
