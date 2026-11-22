<script setup lang="ts">
import { ref, onMounted, onUnmounted, computed } from 'vue'
import AnimatedIcon from './AnimatedIcon.vue'

const props = withDefaults(
  defineProps<{
    title: string
    description?: string
    icon?: string
    badge?: string
    badgeVariant?: 'brand' | 'cyan' | 'indigo' | 'emerald'
    link?: string
    spotlightColor?: string
  }>(),
  {
    description: '',
    icon: '',
    badge: '',
    badgeVariant: 'brand',
    link: '',
    spotlightColor: 'var(--lo-spotlight-color, rgba(255, 45, 32, 0.06))'
  }
)

const cardRef = ref<HTMLElement | null>(null)
const mouseX = ref(0)
const mouseY = ref(0)
const isHovered = ref(false)

const knownVectorIcons = new Set([
  'pod-roles', 'agents', 'agent',
  'directed-graph', 'graph', 'state-graph',
  'zero-python-proxy', 'proxy', 'litellm',
  'sandboxing', 'security', 'guardrails',
  'project-memory', 'memory', 'memorilla',
  'tests-openapi', 'tests', 'swagger',
  'multi-tenant-ddd', 'tenancy', 'ddd',
  'web-scraper', 'scrape', 'scraping',
  'brand-mark'
])

const isSvgIcon = computed(() => {
  if (!props.icon) return false
  const clean = props.icon.trim().toLowerCase().replace(/[_\s]+/g, '-')
  return knownVectorIcons.has(clean)
})

function onMouseMove(e: MouseEvent) {
  if (!cardRef.value) return
  const rect = cardRef.value.getBoundingClientRect()
  mouseX.value = e.clientX - rect.left
  mouseY.value = e.clientY - rect.top
}

function onMouseEnter() {
  isHovered.value = true
}

function onMouseLeave() {
  isHovered.value = false
}

onMounted(() => {
  const el = cardRef.value
  if (!el) return
  el.addEventListener('mousemove', onMouseMove)
  el.addEventListener('mouseenter', onMouseEnter)
  el.addEventListener('mouseleave', onMouseLeave)
})

onUnmounted(() => {
  const el = cardRef.value
  if (!el) return
  el.removeEventListener('mousemove', onMouseMove)
  el.removeEventListener('mouseenter', onMouseEnter)
  el.removeEventListener('mouseleave', onMouseLeave)
})
</script>

<template>
  <component
    :is="link ? 'a' : 'div'"
    :href="link || undefined"
    ref="cardRef"
    class="spotlight-card"
    :class="{ 'is-link': !!link, 'is-active': isHovered }"
    :style="{
      '--mouse-x': `${mouseX}px`,
      '--mouse-y': `${mouseY}px`,
      '--spotlight-color': spotlightColor
    }"
  >
    <!-- Filament + Next.js Hairline Inset Border & Surface Glow -->
    <div class="spotlight-glow" aria-hidden="true"></div>

    <!-- Card Content Structure -->
    <div class="card-inner">
      <div v-if="icon || badge || $slots.icon" class="card-header">
        <div class="card-icon-frame">
          <AnimatedIcon
            v-if="isSvgIcon"
            :name="icon"
            :size="22"
          />
          <template v-else-if="icon">{{ icon }}</template>
          <slot v-else name="icon"></slot>
        </div>

        <span
          v-if="badge"
          class="card-badge"
          :class="`badge-${badgeVariant}`"
        >
          {{ badge }}
        </span>
      </div>

      <h3 class="card-title">{{ title }}</h3>
      <p v-if="description" class="card-description">{{ description }}</p>

      <div class="card-body">
        <slot></slot>
      </div>

      <div v-if="link" class="card-footer">
        <span class="learn-more">
          Explore guide <span class="arrow">→</span>
        </span>
      </div>
    </div>
  </component>
</template>

<style scoped>
/* Filament + Next.js Card Aesthetic */
.spotlight-card {
  position: relative;
  border-radius: 14px;
  background: var(--lo-card-bg);
  border: 1px solid var(--lo-card-border);
  box-shadow: var(--lo-card-shadow);
  overflow: hidden;
  display: flex;
  flex-direction: column;
  padding: 1.5rem;
  text-decoration: none !important;
  color: inherit !important;
  transition: transform 0.22s cubic-bezier(0.16, 1, 0.3, 1),
              box-shadow 0.22s cubic-bezier(0.16, 1, 0.3, 1),
              border-color 0.22s ease;
  backdrop-filter: blur(12px);
  -webkit-backdrop-filter: blur(12px);
}

.spotlight-card.is-link:hover {
  transform: translateY(-3px);
  box-shadow: 0 10px 24px -5px rgba(15, 23, 42, 0.08);
  border-color: rgba(255, 45, 32, 0.35);
}

.dark .spotlight-card.is-link:hover {
  box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.15), 0 16px 36px -10px rgba(0, 0, 0, 0.7), 0 0 25px -5px rgba(255, 45, 32, 0.18);
  border-color: rgba(255, 45, 32, 0.45);
}

/* Interactive Cursor-Tracking Radial Spotlight Glow */
.spotlight-glow {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  pointer-events: none;
  opacity: 0;
  transition: opacity 0.3s ease;
  background: radial-gradient(
    400px circle at var(--mouse-x) var(--mouse-y),
    var(--spotlight-color),
    transparent 65%
  );
}

.spotlight-card.is-active .spotlight-glow {
  opacity: 1;
}

.card-inner {
  position: relative;
  z-index: 2;
  display: flex;
  flex-direction: column;
  height: 100%;
}

.card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 1.15rem;
}

/* Filament Precision Icon Frame */
.card-icon-frame {
  width: 42px;
  height: 42px;
  border-radius: 10px;
  background: var(--lo-icon-bg, #eef0f4);
  border: 1px solid var(--lo-icon-border, #d4d8e2);
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--vp-c-text-1);
  transition: all 0.22s cubic-bezier(0.16, 1, 0.3, 1);
}

.dark .card-icon-frame {
  background: var(--lo-icon-bg, #0E1626);
  border-color: var(--lo-icon-border, rgba(255, 255, 255, 0.1));
  box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.08), 0 2px 6px rgba(0, 0, 0, 0.3);
  color: #F8FAFC;
}

.spotlight-card:hover .card-icon-frame {
  border-color: rgba(255, 45, 32, 0.35);
  background: rgba(255, 45, 32, 0.08);
  transform: scale(1.05);
}

.dark .spotlight-card:hover .card-icon-frame {
  border-color: rgba(255, 45, 32, 0.45);
  background: rgba(255, 45, 32, 0.14);
}

/* Filament-Style Micro Badges */
.card-badge {
  font-size: 0.685rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  padding: 0.2rem 0.6rem;
  border-radius: 9999px;
  border: 1px solid transparent;
  line-height: 1.2;
}

.badge-brand {
  background: rgba(255, 45, 32, 0.07);
  color: #e02619;
  border-color: rgba(255, 45, 32, 0.22);
}
.dark .badge-brand {
  background: rgba(255, 45, 32, 0.15);
  color: #FF5A4F;
  border-color: rgba(255, 45, 32, 0.35);
}

.badge-cyan {
  background: rgba(6, 182, 212, 0.07);
  color: #0e7490;
  border-color: rgba(6, 182, 212, 0.22);
}
.dark .badge-cyan {
  background: rgba(6, 182, 212, 0.15);
  color: #38BDF8;
  border-color: rgba(6, 182, 212, 0.35);
}

.badge-indigo {
  background: rgba(99, 102, 241, 0.07);
  color: #4338ca;
  border-color: rgba(99, 102, 241, 0.22);
}
.dark .badge-indigo {
  background: rgba(99, 102, 241, 0.15);
  color: #818CF8;
  border-color: rgba(99, 102, 241, 0.35);
}

.badge-emerald {
  background: rgba(16, 185, 129, 0.07);
  color: #047857;
  border-color: rgba(16, 185, 129, 0.22);
}
.dark .badge-emerald {
  background: rgba(16, 185, 129, 0.15);
  color: #34D399;
  border-color: rgba(16, 185, 129, 0.35);
}

/* Next.js Crisp Typography */
.card-title {
  margin: 0 0 0.45rem 0 !important;
  font-size: 1.125rem !important;
  font-weight: 700 !important;
  line-height: 1.35 !important;
  letter-spacing: -0.015em;
  border: none !important;
  padding: 0 !important;
  color: var(--vp-c-text-1);
}

.card-description {
  margin: 0 0 1rem 0;
  font-size: 0.885rem;
  line-height: 1.55;
  color: var(--vp-c-text-2);
}

.card-body {
  flex: 1;
}

.card-footer {
  margin-top: 1rem;
  padding-top: 0.75rem;
  border-top: 1px solid var(--vp-c-divider);
}

.learn-more {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  font-size: 0.825rem;
  font-weight: 600;
  color: var(--vp-c-brand-1);
}

.arrow {
  transition: transform 0.2s ease;
}

.spotlight-card:hover .arrow {
  transform: translateX(4px);
}

@media (prefers-reduced-motion: reduce) {
  .spotlight-card,
  .card-icon-frame,
  .arrow {
    transition: none !important;
    transform: none !important;
  }
  .spotlight-glow {
    display: none !important;
  }
}
</style>
