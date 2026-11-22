<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue'

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
    spotlightColor: 'rgba(255, 45, 32, 0.15)'
  }
)

const cardRef = ref<HTMLElement | null>(null)
const mouseX = ref(0)
const mouseY = ref(0)
const isHovered = ref(false)

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
    <!-- Spotlight Surface Glow Overlay -->
    <div class="spotlight-glow" aria-hidden="true"></div>

    <!-- Card Content -->
    <div class="card-inner">
      <div v-if="icon || badge" class="card-header">
        <div v-if="icon" class="card-icon">{{ icon }}</div>
        <div v-else-if="$slots.icon" class="card-icon">
          <slot name="icon"></slot>
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
.spotlight-card {
  position: relative;
  border-radius: 16px;
  background: var(--lo-card-bg);
  border: 1px solid var(--lo-card-border);
  box-shadow: var(--lo-card-shadow);
  overflow: hidden;
  display: flex;
  flex-direction: column;
  padding: 1.5rem;
  text-decoration: none !important;
  color: inherit !important;
  transition: transform 0.25s cubic-bezier(0.16, 1, 0.3, 1),
              box-shadow 0.25s cubic-bezier(0.16, 1, 0.3, 1),
              border-color 0.25s ease;
  backdrop-filter: blur(12px);
  -webkit-backdrop-filter: blur(12px);
}

.spotlight-card.is-link:hover {
  transform: translateY(-4px);
  box-shadow: 0 16px 40px -12px rgba(255, 45, 32, 0.12);
  border-color: rgba(255, 45, 32, 0.35);
}

.dark .spotlight-card.is-link:hover {
  box-shadow: 0 16px 40px -12px rgba(0, 0, 0, 0.7), 0 0 30px -5px rgba(255, 45, 32, 0.15);
  border-color: rgba(255, 45, 32, 0.45);
}

/* Interactive Spotlight Glow following mouse */
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
    450px circle at var(--mouse-x) var(--mouse-y),
    var(--spotlight-color),
    transparent 70%
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
  margin-bottom: 1rem;
}

.card-icon {
  width: 42px;
  height: 42px;
  border-radius: 10px;
  background: rgba(255, 45, 32, 0.1);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.35rem;
  border: 1px solid rgba(255, 45, 32, 0.2);
}

.dark .card-icon {
  background: rgba(255, 45, 32, 0.16);
  border-color: rgba(255, 45, 32, 0.35);
}

.card-badge {
  font-size: 0.72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  padding: 0.25rem 0.65rem;
  border-radius: 9999px;
  border: 1px solid transparent;
}

.badge-brand {
  background: rgba(255, 45, 32, 0.1);
  color: #FF2D20;
  border-color: rgba(255, 45, 32, 0.25);
}

.badge-cyan {
  background: rgba(6, 182, 212, 0.1);
  color: #0891B2;
  border-color: rgba(6, 182, 212, 0.25);
}
.dark .badge-cyan { color: #38BDF8; }

.badge-indigo {
  background: rgba(99, 102, 241, 0.1);
  color: #4F46E5;
  border-color: rgba(99, 102, 241, 0.25);
}
.dark .badge-indigo { color: #818CF8; }

.badge-emerald {
  background: rgba(16, 185, 129, 0.1);
  color: #059669;
  border-color: rgba(16, 185, 129, 0.25);
}
.dark .badge-emerald { color: #34D399; }

.card-title {
  margin: 0 0 0.5rem 0 !important;
  font-size: 1.15rem !important;
  font-weight: 700 !important;
  line-height: 1.4 !important;
  border: none !important;
  padding: 0 !important;
}

.card-description {
  margin: 0 0 1rem 0;
  font-size: 0.9rem;
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
  font-size: 0.85rem;
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
  .spotlight-card {
    transition: none !important;
    transform: none !important;
  }
  .spotlight-glow {
    display: none !important;
  }
}
</style>
