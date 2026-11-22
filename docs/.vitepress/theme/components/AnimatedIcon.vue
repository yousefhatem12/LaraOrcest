<script setup lang="ts">
import { computed } from 'vue'

const props = withDefaults(
  defineProps<{
    name: string
    size?: number | string
    strokeWidth?: number | string
    color?: string
    accentColor?: string
  }>(),
  {
    size: 24,
    strokeWidth: 1.75,
    color: 'currentColor',
    accentColor: 'var(--vp-c-brand-1)'
  }
)

const normalizedName = computed(() => {
  return props.name.trim().toLowerCase().replace(/[_\s]+/g, '-')
})

const iconSize = computed(() => {
  return typeof props.size === 'number' ? `${props.size}px` : props.size
})
</script>

<template>
  <span
    class="animated-icon"
    :class="`icon-${normalizedName}`"
    :style="{
      '--icon-size': iconSize,
      '--icon-stroke-width': strokeWidth,
      '--icon-color': color,
      '--icon-accent': accentColor
    }"
  >
    <!-- 1. Pod Roles / Specialized Agents -->
    <svg
      v-if="normalizedName === 'pod-roles' || normalizedName === 'agents' || normalizedName === 'agent'"
      viewBox="0 0 24 24"
      class="icon-svg"
      fill="none"
      stroke="currentColor"
      :stroke-width="strokeWidth"
      stroke-linecap="round"
      stroke-linejoin="round"
      aria-hidden="true"
    >
      <!-- Central Coordinator Node -->
      <circle cx="12" cy="12" r="3" class="draw-path accent-stroke" />
      <!-- Top Analyst Node -->
      <circle cx="12" cy="4" r="2" class="draw-path" />
      <!-- Bottom-Left Database Node -->
      <circle cx="5" cy="19" r="2" class="draw-path" />
      <!-- Bottom-Right Backend Node -->
      <circle cx="19" cy="19" r="2" class="draw-path" />
      <!-- Orchestration Links -->
      <line x1="12" y1="6" x2="12" y2="9" class="draw-path link-path" />
      <line x1="6.8" y1="17.5" x2="10" y2="13.8" class="draw-path link-path" />
      <line x1="17.2" y1="17.5" x2="14" y2="13.8" class="draw-path link-path" />
      <!-- Subtle Perimeter Arc -->
      <path d="M4.5 12a7.5 7.5 0 0 1 15 0" class="draw-path arc-path" stroke-dasharray="3 3" />
    </svg>

    <!-- 2. Directed Graphs (LangGraph-Style State Transitions) -->
    <svg
      v-else-if="normalizedName === 'directed-graph' || normalizedName === 'graph' || normalizedName === 'state-graph'"
      viewBox="0 0 24 24"
      class="icon-svg"
      fill="none"
      stroke="currentColor"
      :stroke-width="strokeWidth"
      stroke-linecap="round"
      stroke-linejoin="round"
      aria-hidden="true"
    >
      <!-- Node A -->
      <rect x="3" y="3.5" width="6" height="6" rx="2" class="draw-path" />
      <!-- Node B -->
      <rect x="15" y="3.5" width="6" height="6" rx="2" class="draw-path" />
      <!-- Node C (Terminal / Convergence) -->
      <rect x="9" y="15" width="6" height="6" rx="2" class="draw-path accent-stroke" />
      <!-- Forward Edge A -> B -->
      <path d="M9 6.5h6" class="draw-path link-path" />
      <path d="M13 4.5l2 2-2 2" class="draw-path link-path" />
      <!-- Transition Edge B -> C -->
      <path d="M18 9.5v2a3 3 0 0 1-3 3h-2" class="draw-path link-path" />
      <path d="M15 12.5l-2 2 2 2" class="draw-path link-path" />
      <!-- Cyclic Feedback Edge C -> A (Fixer Loop) -->
      <path d="M9 18H7a3 3 0 0 1-3-3V9.5" class="draw-path accent-stroke" />
      <path d="M2 11.5l2-2 2 2" class="draw-path accent-stroke" />
    </svg>

    <!-- 3. Zero Python & Proxy Native (LiteLLM / OpenAI Bridge) -->
    <svg
      v-else-if="normalizedName === 'zero-python-proxy' || normalizedName === 'proxy' || normalizedName === 'litellm'"
      viewBox="0 0 24 24"
      class="icon-svg"
      fill="none"
      stroke="currentColor"
      :stroke-width="strokeWidth"
      stroke-linecap="round"
      stroke-linejoin="round"
      aria-hidden="true"
    >
      <!-- Laravel Left Terminal Port -->
      <rect x="3" y="8" width="5" height="8" rx="1.5" class="draw-path accent-stroke" />
      <!-- LLM Right Gateway Port -->
      <rect x="16" y="8" width="5" height="8" rx="1.5" class="draw-path" />
      <!-- Direct Bridge Bus Traces -->
      <path d="M8 10.5h8" class="draw-path link-path" />
      <path d="M8 13.5h8" class="draw-path link-path" />
      <!-- Central Gateway Router (Diamond) -->
      <path d="M12 6.5l3 5.5-3 5.5-3-5.5z" class="draw-path accent-stroke" />
      <!-- Precision Vertical Signal Pointers -->
      <path d="M12 2.5v4" class="draw-path" />
      <path d="M12 17.5v4" class="draw-path" />
    </svg>

    <!-- 4. Sandboxing & Security Guardrails (PathGuard / HITL) -->
    <svg
      v-else-if="normalizedName === 'sandboxing' || normalizedName === 'security' || normalizedName === 'guardrails'"
      viewBox="0 0 24 24"
      class="icon-svg"
      fill="none"
      stroke="currentColor"
      :stroke-width="strokeWidth"
      stroke-linecap="round"
      stroke-linejoin="round"
      aria-hidden="true"
    >
      <!-- Outer Perimeter Shield -->
      <path
        d="M12 2.5L4 5.5v6.5c0 5.2 3.5 9.2 8 10.5 4.5-1.3 8-5.3 8-10.5V5.5l-8-3z"
        class="draw-path"
      />
      <!-- Concentric Inner Boundary (Sandbox Fence) -->
      <path
        d="M12 6.5L7 8.5v3.5c0 3.3 2.1 6.2 5 7.2 2.9-1 5-3.9 5-7.2V8.5L12 6.5z"
        class="draw-path accent-stroke"
      />
      <!-- Central Authorization Lock / Core Guard -->
      <circle cx="12" cy="12" r="1.5" class="draw-path" />
      <path d="M12 13.5v2" class="draw-path" />
    </svg>

    <!-- 5. Project Memory (Memorilla Architecture) -->
    <svg
      v-else-if="normalizedName === 'project-memory' || normalizedName === 'memory' || normalizedName === 'memorilla'"
      viewBox="0 0 24 24"
      class="icon-svg"
      fill="none"
      stroke="currentColor"
      :stroke-width="strokeWidth"
      stroke-linecap="round"
      stroke-linejoin="round"
      aria-hidden="true"
    >
      <!-- Horizontal Memory Cache Tiers -->
      <path d="M4 6.5h16" class="draw-path" />
      <path d="M4 12h16" class="draw-path" />
      <path d="M4 17.5h16" class="draw-path" />
      <!-- Targeted Recall Index Pins -->
      <circle cx="8" cy="6.5" r="1.75" class="draw-path accent-stroke" />
      <circle cx="16" cy="12" r="1.75" class="draw-path accent-stroke" />
      <circle cx="10" cy="17.5" r="1.75" class="draw-path accent-stroke" />
      <!-- Vertical Association Links -->
      <path d="M8 8.25v2" class="draw-path link-path" />
      <path d="M16 13.75v2" class="draw-path link-path" />
      <!-- Outer Architectural Bracket Boundaries -->
      <path d="M3 4v16 M21 4v16" class="draw-path" />
    </svg>

    <!-- 6. Automated Tests & OpenAPI / Swagger Specs -->
    <svg
      v-else-if="normalizedName === 'tests-openapi' || normalizedName === 'tests' || normalizedName === 'swagger'"
      viewBox="0 0 24 24"
      class="icon-svg"
      fill="none"
      stroke="currentColor"
      :stroke-width="strokeWidth"
      stroke-linecap="round"
      stroke-linejoin="round"
      aria-hidden="true"
    >
      <!-- Spec Document Sheet -->
      <path
        d="M6 3h9l5 5v13a1.5 1.5 0 0 1-1.5 1.5H6A1.5 1.5 0 0 1 4.5 21V4.5A1.5 1.5 0 0 1 6 3z"
        class="draw-path"
      />
      <!-- Folded Header Corner -->
      <path d="M15 3v5h5" class="draw-path" />
      <!-- Spec Schema Code Lines -->
      <path d="M8 11.5h4 M8 15h3" class="draw-path link-path" />
      <!-- Verification Badge & Assertion Check -->
      <circle cx="16" cy="16.5" r="4" class="draw-path accent-stroke" />
      <path d="M14.5 16.5l1.2 1.2 2.3-2.4" class="draw-path accent-stroke check-stroke" />
    </svg>

    <!-- 7. Multi-Vendor / Multi-Tenant DDD (Bounded Contexts) -->
    <svg
      v-else-if="normalizedName === 'multi-tenant-ddd' || normalizedName === 'tenancy' || normalizedName === 'ddd'"
      viewBox="0 0 24 24"
      class="icon-svg"
      fill="none"
      stroke="currentColor"
      :stroke-width="strokeWidth"
      stroke-linecap="round"
      stroke-linejoin="round"
      aria-hidden="true"
    >
      <!-- Top Domain Layer -->
      <path d="M12 2.5L3 6.5l9 4 9-4-9-4z" class="draw-path accent-stroke" />
      <!-- Middle Application Context Layer -->
      <path d="M3 10.5l9 4 9-4" class="draw-path link-path" />
      <!-- Lower Tenant Infrastructure & Scoping Layer -->
      <path d="M3 14.5l9 4 9-4" class="draw-path link-path" />
      <!-- Vertical Pillars / Isolation Boundary Walls -->
      <path d="M3 6.5v8 M21 6.5v8 M12 10.5v8" class="draw-path" />
    </svg>

    <!-- 8. Web Scraping & Ingestion (Dual Sources -> Target Endpoint) -->
    <svg
      v-else-if="normalizedName === 'web-scraper' || normalizedName === 'scrape' || normalizedName === 'scraping'"
      viewBox="0 0 24 24"
      class="icon-svg"
      fill="none"
      stroke="currentColor"
      :stroke-width="strokeWidth"
      stroke-linecap="round"
      stroke-linejoin="round"
      aria-hidden="true"
    >
      <!-- Target Website 1 Node -->
      <circle cx="6" cy="6.5" r="3" class="draw-path" />
      <path d="M4 6.5h4 M6 4.5v4" class="draw-path link-path" />
      <!-- Target Website 2 Node -->
      <circle cx="18" cy="6.5" r="3" class="draw-path" />
      <path d="M16 6.5h4 M18 4.5v4" class="draw-path link-path" />
      <!-- Converging Extraction Streams -->
      <path d="M6 9.5c0 3.5 5 4.5 5 7" class="draw-path link-path" />
      <path d="M18 9.5c0 3.5-5 4.5-5 7" class="draw-path link-path" />
      <!-- Target Destination Endpoint Box -->
      <rect x="9" y="16.5" width="6" height="5" rx="1.5" class="draw-path accent-stroke" />
      <circle cx="12" cy="19" r="0.75" class="draw-path accent-stroke" />
    </svg>

    <!-- 9. Brand Mark / Emblem (Geometric Robot + Laravel Angled Crest) -->
    <svg
      v-else
      viewBox="0 0 24 24"
      class="icon-svg"
      fill="none"
      stroke="currentColor"
      :stroke-width="strokeWidth"
      stroke-linecap="round"
      stroke-linejoin="round"
      aria-hidden="true"
    >
      <!-- Isometric Geometric Crest -->
      <path d="M12 2.5L3.5 7.5v9l8.5 5 8.5-5v-9l-8.5-5z" class="draw-path" />
      <!-- Structural Facet Lines -->
      <path d="M12 21.5V12.5 M3.5 7.5l8.5 5 8.5-5" class="draw-path link-path" />
      <!-- Center Sensor / Core Agent Pupil -->
      <circle cx="12" cy="12.5" r="2.25" class="draw-path accent-stroke" />
      <!-- Top Communication Pulse Beam -->
      <path d="M12 2.5v3" class="draw-path accent-stroke" />
    </svg>
  </span>
</template>

<style scoped>
.animated-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: var(--icon-size, 24px);
  height: var(--icon-size, 24px);
  color: var(--icon-color, currentColor);
  vertical-align: middle;
  transition: transform 0.25s cubic-bezier(0.16, 1, 0.3, 1),
              filter 0.25s ease;
}

.icon-svg {
  width: 100%;
  height: 100%;
  display: block;
  overflow: visible;
}

/* Stroke Drawing Animation Mechanics */
.draw-path {
  stroke-dasharray: 60;
  stroke-dashoffset: 60;
  animation: strokeDraw 0.95s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}

.link-path {
  stroke-dasharray: 40;
  stroke-dashoffset: 40;
  animation: strokeDraw 0.85s cubic-bezier(0.16, 1, 0.3, 1) 0.15s forwards;
  opacity: 0.85;
}

.arc-path {
  animation: strokeDraw 1.1s cubic-bezier(0.16, 1, 0.3, 1) 0.2s forwards;
  opacity: 0.6;
}

.accent-stroke {
  stroke: var(--icon-accent, var(--vp-c-brand-1));
  animation-delay: 0.25s;
}

.check-stroke {
  animation-delay: 0.4s;
}

@keyframes strokeDraw {
  0% {
    stroke-dashoffset: 60;
    opacity: 0.1;
  }
  30% {
    opacity: 1;
  }
  100% {
    stroke-dashoffset: 0;
    opacity: 1;
  }
}

/* Hover Accentuation & Re-draw Impulse */
:deep(.spotlight-card:hover) .animated-icon,
.animated-icon:hover {
  transform: scale(1.08);
}

:deep(.spotlight-card:hover) .accent-stroke,
.animated-icon:hover .accent-stroke {
  filter: drop-shadow(0 0 4px rgba(255, 45, 32, 0.5));
}

.dark :deep(.spotlight-card:hover) .accent-stroke,
.dark .animated-icon:hover .accent-stroke {
  filter: drop-shadow(0 0 6px rgba(255, 59, 48, 0.7));
}

/* Respect prefers-reduced-motion */
@media (prefers-reduced-motion: reduce) {
  .draw-path,
  .link-path,
  .arc-path,
  .accent-stroke,
  .check-stroke {
    stroke-dashoffset: 0 !important;
    stroke-dasharray: none !important;
    animation: none !important;
    transition: none !important;
    opacity: 1 !important;
  }
  .animated-icon {
    transform: none !important;
    transition: none !important;
  }
}
</style>
