<script setup lang="ts">
import { ref, computed } from 'vue'

interface FlowNode {
  id: string
  name: string
  title: string
  icon: string
  category: 'core' | 'conditional' | 'parallel' | 'cyclic' | 'terminal'
  description: string
  goal: string
  tools: string[]
  outputKey: string
  badge?: string
}

const withScraper = ref(true)
const withSwagger = ref(true)
const isCyclicFix = ref(false)
const selectedNodeId = ref<string>('architect')

const allNodes: Record<string, FlowNode> = {
  business_analyst: {
    id: 'business_analyst',
    name: 'Business Analyst',
    title: 'Domain & Systems Analyst',
    icon: '📋',
    category: 'core',
    description: 'Analyzes prompt requirements, extracts domain invariants, authorization boundaries, and edge cases.',
    goal: 'Produces unambiguous domain specification before any code is generated.',
    tools: ['file_reader'],
    outputKey: 'requirements_spec'
  },
  product_manager: {
    id: 'product_manager',
    name: 'Product Manager',
    title: 'Technical Product Manager',
    icon: '🎯',
    category: 'core',
    description: 'Decomposes specification into ordered, testable backlog tasks annotated with acceptance criteria and dependencies.',
    goal: 'Structures execution backlog with explicit depends_on and scope_paths.',
    tools: ['file_reader'],
    outputKey: 'backlog'
  },
  web_scraping_agent: {
    id: 'web_scraping_agent',
    name: 'Web Scraping Agent',
    title: 'Extraction & Ingestion Specialist',
    icon: '🌐',
    category: 'conditional',
    badge: 'Optional',
    description: 'Fetches exactly two target website URLs, normalizes raw markup into unified domain payload, and dispatches to endpoint.',
    goal: 'Structured 2-site data extraction guided by BA/PM criteria with dry-run safety.',
    tools: ['file_reader'],
    outputKey: 'scraping_results'
  },
  architect: {
    id: 'architect',
    name: 'Software Architect',
    title: 'Senior Laravel Architect',
    icon: '🏛️',
    category: 'core',
    badge: 'HITL Gate',
    description: 'Translates backlog into an idiomatic Laravel technical plan: migrations, models, form requests, controllers, routes, and tests.',
    goal: 'Designs robust system blueprint and triggers optional after_plan review gate.',
    tools: ['file_reader'],
    outputKey: 'technical_plan'
  },
  parallel_dev: {
    id: 'parallel_dev',
    name: 'Database & Backend',
    title: 'Parallel Wave Execution',
    icon: '⚡',
    category: 'parallel',
    badge: 'Parallel Wave',
    description: 'DatabaseAgent and BackendAgent execute concurrently across non-conflicting scope_paths to generate migrations and controllers.',
    goal: 'Rapid concurrent code generation with deterministic state fan-in.',
    tools: ['file_reader', 'patch_writer', 'artisan_runner'],
    outputKey: 'artifacts'
  },
  swagger_agent: {
    id: 'swagger_agent',
    name: 'Swagger Agent',
    title: 'OpenAPI Documentation Specialist',
    icon: '📜',
    category: 'conditional',
    badge: 'Optional',
    description: 'Analyzes generated routes, controllers, and form requests to author standardized OpenAPI 3.0+ specifications in JSON/YAML.',
    goal: 'Comprehensive API documentation scoped to docs/swagger.json.',
    tools: ['file_reader', 'patch_writer', 'artisan_runner'],
    outputKey: 'metadata.swagger_documentation'
  },
  qa_fixer: {
    id: 'qa_fixer',
    name: 'QA & Fixer Loop',
    title: 'Automated QA & Cyclic Bug Repair',
    icon: '🧪',
    category: 'cyclic',
    badge: 'Cyclic Loop',
    description: 'QaAgent generates and executes test suites. If assertions fail, FixerAgent diagnoses root causes and applies surgical repairs.',
    goal: 'Bounded test execution (php artisan test) iterating within max_fix_attempts budget.',
    tools: ['test_runner', 'patch_writer', 'artisan_runner'],
    outputKey: 'test_results, guardrails.fix_attempts'
  },
  reviewer_agent: {
    id: 'reviewer_agent',
    name: 'Reviewer Agent',
    title: 'Staff Reviewer & Auditor',
    icon: '🛡️',
    category: 'core',
    badge: 'Staff Audit',
    description: 'Audits git diff against PSR-12, Laravel security standards, and business requirements before issuing formal sign-off.',
    goal: 'Final gatekeeper approving changes or routing revisions to fixer.',
    tools: ['file_reader', 'git_command'],
    outputKey: 'reviews'
  },
  git_finalize: {
    id: 'git_finalize',
    name: 'Git Milestone',
    title: 'Release & Memory Store',
    icon: '🚀',
    category: 'terminal',
    badge: 'Milestone',
    description: 'Stages verified artifacts, creates milestone commit on feature branch (orcest/...), and consolidates Project Memory.',
    goal: 'Safe isolated release on feature branch and codebase memory update.',
    tools: ['git_command'],
    outputKey: 'git_state, memory.json'
  }
}

const activeNodes = computed(() => {
  const list: FlowNode[] = [
    allNodes.business_analyst,
    allNodes.product_manager
  ]
  if (withScraper.value) {
    list.push(allNodes.web_scraping_agent)
  }
  list.push(allNodes.architect)
  list.push(allNodes.parallel_dev)
  if (withSwagger.value) {
    list.push(allNodes.swagger_agent)
  }
  list.push(allNodes.qa_fixer)
  list.push(allNodes.reviewer_agent)
  list.push(allNodes.git_finalize)
  return list
})

const currentNode = computed(() => {
  return allNodes[selectedNodeId.value] || allNodes.architect
})

function selectNode(id: string) {
  selectedNodeId.value = id
}
</script>

<template>
  <div class="agent-flow-visualizer">
    <!-- Header with interactive configuration toggles -->
    <div class="flow-header">
      <div class="flow-title-group">
        <h3 class="flow-title">Stateful Directed Graph Engine</h3>
        <p class="flow-subtitle">
          Interactive workflow visualization. Click any agent node to inspect responsibilities, sandboxed tools, and state outputs.
        </p>
      </div>

      <div class="flow-toggles">
        <label class="toggle-label" title="Enable WebScrapingAgent conditional branch">
          <input type="checkbox" v-model="withScraper" class="toggle-checkbox" />
          <span class="toggle-text">Web Scraper</span>
        </label>
        <label class="toggle-label" title="Enable SwaggerAgent conditional branch">
          <input type="checkbox" v-model="withSwagger" class="toggle-checkbox" />
          <span class="toggle-text">Swagger Docs</span>
        </label>
        <label class="toggle-label" title="Simulate failing test -> FixerAgent cyclic recovery loop">
          <input type="checkbox" v-model="isCyclicFix" class="toggle-checkbox" />
          <span class="toggle-text">Fixer Loop</span>
        </label>
      </div>
    </div>

    <!-- Interactive Pipeline Map -->
    <div class="pipeline-track-wrapper">
      <div class="pipeline-track">
        <div
          v-for="(node, index) in activeNodes"
          :key="node.id"
          class="node-wrapper"
        >
          <!-- Active Agent Card -->
          <button
            type="button"
            class="flow-node-card"
            :class="{
              'is-selected': selectedNodeId === node.id,
              'is-cyclic-highlight': node.id === 'qa_fixer' && isCyclicFix
            }"
            @click="selectNode(node.id)"
          >
            <div class="node-icon-bubble">{{ node.icon }}</div>
            <div class="node-meta">
              <span class="node-name">{{ node.name }}</span>
              <span v-if="node.badge" class="node-badge">{{ node.badge }}</span>
            </div>
            <div class="node-step">0{{ index + 1 }}</div>
          </button>

          <!-- Animated Connector Edge -->
          <div
            v-if="index < activeNodes.length - 1"
            class="flow-connector"
            :class="{ 'connector-cyclic': isCyclicFix && node.id === 'qa_fixer' }"
          >
            <div class="connector-line"></div>
            <div class="pulse-packet" aria-hidden="true"></div>
            <span class="connector-arrow">▶</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Detailed Node Inspector Panel -->
    <div class="node-inspector">
      <div class="inspector-header">
        <div class="inspector-title-row">
          <span class="inspector-icon">{{ currentNode.icon }}</span>
          <div>
            <h4 class="inspector-agent-name">{{ currentNode.name }}</h4>
            <span class="inspector-role">{{ currentNode.title }}</span>
          </div>
        </div>
        <div class="inspector-tags">
          <span class="tag-pill tag-state">State Output: <code>${{ currentNode.outputKey }}</code></span>
        </div>
      </div>

      <p class="inspector-desc">{{ currentNode.description }}</p>
      <div class="inspector-goal">
        <strong>Goal:</strong> {{ currentNode.goal }}
      </div>

      <div class="inspector-tools">
        <span class="tools-label">Authorized Sandboxed Tools:</span>
        <div class="tool-pills">
          <span
            v-for="tool in currentNode.tools"
            :key="tool"
            class="tool-pill"
          >
            🛠️ {{ tool }}
          </span>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.agent-flow-visualizer {
  margin: 2.5rem 0;
  padding: 1.75rem;
  border-radius: 20px;
  background: var(--lo-card-bg);
  border: 1px solid var(--lo-card-border);
  box-shadow: var(--lo-card-shadow);
  backdrop-filter: blur(12px);
  -webkit-backdrop-filter: blur(12px);
}

.flow-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1.5rem;
  margin-bottom: 2rem;
  flex-wrap: wrap;
}

.flow-title {
  margin: 0 0 0.35rem 0 !important;
  font-size: 1.35rem !important;
  font-weight: 800 !important;
  border: none !important;
  padding: 0 !important;
}

.flow-subtitle {
  margin: 0;
  font-size: 0.875rem;
  color: var(--vp-c-text-2);
}

.flow-toggles {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  flex-wrap: wrap;
}

.toggle-label {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  font-size: 0.8rem;
  font-weight: 600;
  cursor: pointer;
  padding: 0.35rem 0.75rem;
  border-radius: 9999px;
  background: var(--vp-c-bg-soft);
  border: 1px solid var(--lo-card-border);
  transition: all 0.2s ease;
  user-select: none;
}

.toggle-label:hover {
  border-color: var(--vp-c-brand-1);
}

.toggle-checkbox {
  cursor: pointer;
  accent-color: var(--vp-c-brand-1);
}

/* Pipeline Track (Horizontal Scrollable) */
.pipeline-track-wrapper {
  overflow-x: auto;
  padding: 1rem 0.5rem 1.75rem;
  margin-bottom: 1.5rem;
  scrollbar-width: thin;
}

.pipeline-track {
  display: flex;
  align-items: center;
  gap: 0;
  min-width: max-content;
}

.node-wrapper {
  display: flex;
  align-items: center;
}

/* Node Button Card */
.flow-node-card {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  text-align: center;
  width: 135px;
  min-height: 120px;
  padding: 1rem 0.75rem;
  border-radius: 14px;
  background: var(--vp-c-bg-elv);
  border: 1px solid var(--lo-card-border);
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.04);
  cursor: pointer;
  transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
  color: inherit;
}

.flow-node-card:hover {
  transform: translateY(-4px);
  border-color: rgba(255, 45, 32, 0.4);
  box-shadow: 0 10px 24px -6px rgba(255, 45, 32, 0.15);
}

.flow-node-card.is-selected {
  border-color: var(--vp-c-brand-1);
  background: var(--vp-c-bg);
  box-shadow: 0 0 0 2px var(--vp-c-brand-1), 0 10px 25px -5px rgba(255, 45, 32, 0.25);
  transform: translateY(-4px);
}

.flow-node-card.is-cyclic-highlight {
  border-color: #F59E0B;
  box-shadow: 0 0 0 2px #F59E0B, 0 10px 25px -5px rgba(245, 158, 11, 0.3);
}

.node-icon-bubble {
  font-size: 1.6rem;
  margin-bottom: 0.4rem;
}

.node-name {
  font-size: 0.8rem;
  font-weight: 700;
  line-height: 1.25;
  display: block;
}

.node-badge {
  display: inline-block;
  font-size: 0.65rem;
  font-weight: 600;
  text-transform: uppercase;
  padding: 0.1rem 0.4rem;
  margin-top: 0.3rem;
  border-radius: 4px;
  background: var(--vp-c-brand-soft);
  color: var(--vp-c-brand-1);
}

.node-step {
  position: absolute;
  top: 6px;
  right: 8px;
  font-size: 0.65rem;
  font-weight: 700;
  color: var(--vp-c-text-3);
  font-family: monospace;
}

/* Connectors */
.flow-connector {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 48px;
  height: 24px;
}

.connector-line {
  position: absolute;
  top: 50%;
  left: 0;
  right: 0;
  height: 2px;
  background: var(--vp-c-divider);
  transform: translateY(-50%);
}

.pulse-packet {
  position: absolute;
  top: 50%;
  left: 0;
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: var(--vp-c-brand-1);
  transform: translateY(-50%);
  box-shadow: 0 0 8px var(--vp-c-brand-1);
  animation: pulseTravel 2.2s infinite linear;
}

.connector-arrow {
  position: absolute;
  right: -2px;
  top: 50%;
  transform: translateY(-50%);
  font-size: 0.65rem;
  color: var(--vp-c-text-3);
}

@keyframes pulseTravel {
  0% {
    left: 0%;
    opacity: 0;
  }
  20% {
    opacity: 1;
  }
  80% {
    opacity: 1;
  }
  100% {
    left: 100%;
    opacity: 0;
  }
}

/* Node Inspector Card */
.node-inspector {
  padding: 1.5rem;
  border-radius: 14px;
  background: var(--vp-c-bg-elv);
  border: 1px solid var(--lo-card-border);
}

.inspector-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 1rem;
  flex-wrap: wrap;
}

.inspector-title-row {
  display: flex;
  align-items: center;
  gap: 0.85rem;
}

.inspector-icon {
  font-size: 2rem;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 52px;
  height: 52px;
  border-radius: 12px;
  background: var(--vp-c-brand-soft);
  border: 1px solid rgba(255, 45, 32, 0.2);
}

.inspector-agent-name {
  margin: 0 !important;
  font-size: 1.25rem !important;
  font-weight: 800 !important;
  border: none !important;
  padding: 0 !important;
}

.inspector-role {
  font-size: 0.875rem;
  color: var(--vp-c-text-2);
}

.tag-state code {
  font-size: 0.8rem;
  color: var(--vp-c-brand-1);
  background: var(--vp-c-brand-soft);
  padding: 0.2rem 0.5rem;
  border-radius: 6px;
}

.inspector-desc {
  font-size: 0.95rem;
  line-height: 1.6;
  margin: 0 0 1rem 0;
  color: var(--vp-c-text-1);
}

.inspector-goal {
  padding: 0.75rem 1rem;
  border-radius: 8px;
  background: var(--vp-c-bg-soft);
  font-size: 0.9rem;
  line-height: 1.5;
  margin-bottom: 1.25rem;
  border-left: 3px solid var(--vp-c-brand-1);
}

.inspector-tools {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  flex-wrap: wrap;
}

.tools-label {
  font-size: 0.825rem;
  font-weight: 600;
  color: var(--vp-c-text-2);
}

.tool-pills {
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
}

.tool-pill {
  font-size: 0.775rem;
  font-weight: 600;
  font-family: monospace;
  padding: 0.25rem 0.65rem;
  border-radius: 6px;
  background: var(--vp-c-bg);
  border: 1px solid var(--lo-card-border);
}

@media (prefers-reduced-motion: reduce) {
  .pulse-packet {
    animation: none !important;
    display: none !important;
  }
}
</style>
