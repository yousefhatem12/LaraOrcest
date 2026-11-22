---
layout: home

hero:
  name: "LaraOrcest"
  text: "Multi-Agent Orchestration for Laravel"
  tagline: "Prompt-driven feature delivery combining CrewAI-style role specialization with LangGraph-style stateful graphs. 100% native PHP—zero Python code."
  image:
    src: /logo.png
    alt: LaraOrcest Logo
  actions:
    - theme: brand
      text: Quickstart Guide
      link: /quickstart
    - theme: alt
      text: Real-World Use Cases
      link: /use-cases
    - theme: alt
      text: View on GitHub
      link: https://github.com/yousefhatem12/LaraOrcest

features:
  - icon: 🤖
    title: CrewAI-Style Pod Roles
    details: 9 specialized agents (BA, Architect, Database, Backend, QA, Reviewer, etc.) with strict JSON schemas, least-privilege tools, and per-agent model routing.
  - icon: 🔄
    title: LangGraph-Style Directed Graphs
    details: Deterministic execution with immutable RunState, checkpointing, conditional EdgeRules, parallel scheduling waves, and bounded cyclic bug fixing.
  - icon: ⚡
    title: Zero Python & LiteLLM Native
    details: Runs natively in PHP 8.2+ via Composer. Direct HTTP driver connects to LiteLLM, Ollama, vLLM, OpenRouter, Anthropic, or OpenAI.
  - icon: 🛡️
    title: Sandboxed Tools & Guardrails
    details: PathGuard filesystem boundaries, Artisan command whitelisting, Git feature branch isolation, and Human-in-the-Loop approval gates.
  - icon: 🧠
    title: Memorilla Project Memory
    details: Compact codebase knowledge store (.lara-orcest/memory.json) indexing conventions, past architectural decisions, and bug-fix resolutions.
  - icon: 📖
    title: Automated Tests & OpenAPI Specs
    details: Generates comprehensive Pest/PHPUnit test suites and complete OpenAPI 3.0+ / Swagger documentation files automatically.
---

# LaraOrcest Documentation

Welcome to the documentation for **LaraOrcest** (`yousef/lara-orcest`), an enterprise-grade, prompt-driven multi-agent orchestration framework built exclusively for Laravel.

---

## What is LaraOrcest?

LaraOrcest transforms natural language requirements into fully tested, documented, and version-controlled Laravel features. It combines two foundational paradigms of modern agentic artificial intelligence natively inside your Laravel application:

1. **CrewAI-Style Role Specialization:** A dedicated roster of specialized software engineering agents—from Business Analysts and Software Architects to Database Specialists, QA Engineers, and Staff Reviewers—each with isolated responsibilities, strict system prompts, typed JSON output schemas, and least-privilege tool access.
2. **LangGraph-Style Stateful Directed Graphs:** A deterministic execution engine (`GraphEngine`) governing state progression through conditional edge rules (`EdgeRule`), transactional checkpointing (`StateCheckpointer`), bounded cyclic bug-fixing loops, deterministic parallel execution waves, and Human-in-the-Loop review gates.

```
                              ┌──────────────────────────────────┐
                              │     Natural Language Prompt      │
                              └────────────────┬─────────────────┘
                                               │
                                               ▼
                              ┌──────────────────────────────────┐
                              │       business_analyst           │
                              └────────────────┬─────────────────┘
                                               │
                                               ▼
                              ┌──────────────────────────────────┐
                              │        product_manager           │
                              └────────┬─────────────────┬───────┘
                                       │ (if scraping)   │
                                       ▼                 │
                       ┌───────────────────────────────┐ │
                       │      web_scraping_agent       │ │
                       └───────────────┬───────────────┘ │
                                       │                 │
                                       ▼                 ▼
                              ┌──────────────────────────────────┐
                              │           architect              │
                              └────────────────┬─────────────────┘
                                               │
                                               ▼
                              ┌──────────────────────────────────┐
                              │           git_agent              │
                              └────────────────┬─────────────────┘
                                               │
                                               ▼
                              ┌──────────────────────────────────┐
                              │        database_agent            │
                              └────────────────┬─────────────────┘
                                               │
                                               ▼
                              ┌──────────────────────────────────┐
                              │         backend_agent            │
                              └────────┬─────────────────┬───────┘
                                       │ (if swagger)    │
                                       ▼                 │
                       ┌───────────────────────────────┐ │
                       │        swagger_agent          │ │
                       └───────────────┬───────────────┘ │
                                       │                 │
                                       ▼                 ▼
                              ┌──────────────────────────────────┐
                              │            qa_agent              │
                              └────────────────┬─────────────────┘
                                               │
                                       [Tests Passing?]
                                       ├── No ──► fixer_agent ──┐
                                       │             ▲          │
                                       │             └──────────┘
                                       ▼ Yes
                              ┌──────────────────────────────────┐
                              │         reviewer_agent           │
                              └────────────────┬─────────────────┘
                                               │
                                       [Review Approved?]
                                       ├── No ──► fixer_agent
                                       ▼ Yes
                              ┌──────────────────────────────────┐
                              │    Git Milestone & Memory Store  │
                              └──────────────────────────────────┘
```

---

## Why LaraOrcest?

### 1. Zero Python Inside Laravel
Most agent frameworks (CrewAI, LangGraph, AutoGen) require running Python sidecars, external FastAPI microservices, or complex process bridges. LaraOrcest runs **100% natively in PHP 8.2+ via Composer**. There are no virtual environments, no daemon processes to manage, and no serialization bridges between PHP and Python.

### 2. OpenAI-Compatible & LiteLLM Proxy Native
LaraOrcest communicates with language models via the standard OpenAI HTTP protocol through its built-in `OpenAiCompatibleHttpDriver`. You can point LaraOrcest directly to:
- **[LiteLLM Proxy](https://github.com/BerriAI/litellm)** to manage rate limits, spend budgets, failover, and routing across 100+ LLM providers.
- **Local LLM Engines** such as **Ollama**, **vLLM**, or **LocalAI** for air-gapped, privacy-compliant development.
- **Commercial API Gateways** like **OpenRouter**, **OpenAI**, **Anthropic**, or **Google Gemini**.

### 3. Idiomatic Laravel Code Generation
LaraOrcest agents do not generate generic scripts. They generate idiomatic Laravel components that follow modern best practices:
- Normalized database migrations with foreign key constraints.
- Eloquent models with typed properties, relationships, and cast attributes.
- Form Requests with robust validation rules (`rules()` and `authorize()`).
- Resourceful API controllers returning structured JSON resources.
- Strict route definitions registered in `routes/api.php` or `routes/web.php`.
- Complete Pest or PHPUnit test suites covering happy paths, validation failures, and authorization edge cases.
- OpenAPI 3.0+ / Swagger API specifications in JSON or YAML.

### 4. Sandboxed Security & Guardrails
Autonomous code generation must be safe:
- **Filesystem Boundaries:** Agents operate within the project root. Access to critical paths (`.env`, `.git`, `vendor`, `node_modules`, secret keys) is blocked by `PathGuard`.
- **Artisan Command Whitelist:** Only approved generators (`make:*`, `route:list`, `migrate:status`, `test`) can be invoked. Destructive commands like `migrate:fresh`, `--force`, or `--drop-views` are blocked.
- **Git Branch Isolation:** Changes are authored on isolated feature branches (`orcest/...`). Working trees on `main` or `master` are protected.
- **Bounded Cyclic Loops:** If tests fail, `fixer_agent` iterates up to `max_fix_attempts` (default: 3) to prevent runaway token spend.
- **Human-in-the-Loop (HITL):** Configurable review gates pause execution after planning or before final commits for explicit human review.

### 5. Persistent Project Memory (Memorilla Architecture)
Inspired by modern compact memory architectures, LaraOrcest maintains a persistent codebase knowledge store in `.lara-orcest/memory.json`. It indexes conventions, architectural decisions, and bug-fix lessons from past runs, injecting targeted context into agent prompts within a strict token budget.

---

## Documentation Navigation

Explore the comprehensive LaraOrcest documentation guides:

| Guide | Description |
| :--- | :--- |
| **[Quickstart Guide](quickstart.md)** | Step-by-step installation, local setup via Composer `path` repository, `.env` setup, dry-run previews, running builds, and resuming runs. |
| **[Architecture & Engine](architecture.md)** | Detailed breakdown of `RunState`, `StateCheckpointer`, `EdgeRule`, `GraphEngine`, parallel scheduler (`depends_on`, `scope_paths`, fan-in), and Project Memory. |
| **[Agents & Model Routing](agents.md)** | In-depth documentation for all 9 core agents and 2 conditional agents (`web_scraping_agent`, `swagger_agent`), plus per-agent model routing (`llm.agent_models`). |
| **[Safety, Sandboxing & HITL](safety-and-hitl.md)** | Sandboxed tools (`FileReaderTool`, `PatchWriterTool`, `ArtisanRunnerTool`, `TestRunnerTool`, `GitCommandTool`), path security, and Human-in-the-Loop controls. |
| **[Practical Real-World Use Cases](use-cases.md)** | Practical walkthroughs: end-to-end feature delivery, two-site web scraping, multi-model proxy routing, interactive vs. unattended execution, and multi-vendor DDD platforms. |

---

## Directory Reference

- `config/lara-orcest.php`: Central configuration file for drivers, guardrails, memory, tools, and agents.
- `src/`: Core PHP framework implementation (Agents, Commands, Contracts, Graph, LLM Drivers, Memory, State, Tools).
- `examples/`: Ready-to-use configuration files, LiteLLM configs, prompt templates, and orchestration scripts.
- `spec.md`: Complete architectural and technical specification.
