<script setup lang="ts">
import { computed } from 'vue'

const props = withDefaults(
  defineProps<{
    text: string
    icon?: string
    variant?: 'brand' | 'cyan' | 'indigo' | 'emerald'
    link?: string
  }>(),
  {
    icon: '✨',
    variant: 'brand',
    link: ''
  }
)

const badgeClass = computed(() => `shiny-badge-${props.variant}`)
</script>

<template>
  <component
    :is="link ? 'a' : 'div'"
    :href="link || undefined"
    class="shiny-badge"
    :class="[badgeClass, { 'is-link': !!link }]"
  >
    <!-- Shimmer reflection sweep -->
    <div class="shimmer-sweep" aria-hidden="true"></div>

    <!-- Inner content -->
    <span v-if="icon" class="badge-icon">{{ icon }}</span>
    <span class="badge-text">{{ text }}</span>

    <span v-if="link" class="badge-arrow">→</span>
  </component>
</template>

<style scoped>
.shiny-badge {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.35rem 1rem;
  border-radius: 9999px;
  font-size: 0.825rem;
  font-weight: 600;
  letter-spacing: 0.02em;
  position: relative;
  overflow: hidden;
  text-decoration: none !important;
  backdrop-filter: blur(10px);
  -webkit-backdrop-filter: blur(10px);
  transition: transform 0.25s ease, box-shadow 0.25s ease, border-color 0.25s ease;
  user-select: none;
  border: 1px solid transparent;
}

.shiny-badge.is-link:hover {
  transform: translateY(-2px) scale(1.02);
}

/* Variant: Brand (Laravel Red) */
.shiny-badge-brand {
  background: rgba(255, 45, 32, 0.06);
  border-color: rgba(255, 45, 32, 0.22);
  color: #e02619;
}
.dark .shiny-badge-brand {
  background: rgba(255, 45, 32, 0.14);
  border-color: rgba(255, 45, 32, 0.4);
  color: #FF6B62;
  box-shadow: 0 0 20px -5px rgba(255, 45, 32, 0.3);
}

/* Variant: Cyan */
.shiny-badge-cyan {
  background: rgba(6, 182, 212, 0.06);
  border-color: rgba(6, 182, 212, 0.22);
  color: #0e7490;
}
.dark .shiny-badge-cyan {
  background: rgba(6, 182, 212, 0.14);
  border-color: rgba(6, 182, 212, 0.4);
  color: #38BDF8;
  box-shadow: 0 0 20px -5px rgba(6, 182, 212, 0.3);
}

/* Variant: Indigo */
.shiny-badge-indigo {
  background: rgba(99, 102, 241, 0.06);
  border-color: rgba(99, 102, 241, 0.22);
  color: #4338ca;
}
.dark .shiny-badge-indigo {
  background: rgba(99, 102, 241, 0.14);
  border-color: rgba(99, 102, 241, 0.4);
  color: #818CF8;
  box-shadow: 0 0 20px -5px rgba(99, 102, 241, 0.3);
}

/* Variant: Emerald */
.shiny-badge-emerald {
  background: rgba(16, 185, 129, 0.06);
  border-color: rgba(16, 185, 129, 0.22);
  color: #047857;
}
.dark .shiny-badge-emerald {
  background: rgba(16, 185, 129, 0.14);
  border-color: rgba(16, 185, 129, 0.4);
  color: #34D399;
}

/* Shimmer Sweep Animation */
.shimmer-sweep {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background: linear-gradient(
    105deg,
    transparent 20%,
    rgba(255, 255, 255, 0.45) 50%,
    transparent 80%
  );
  transform: translateX(-150%);
  animation: shimmer 3.5s cubic-bezier(0.4, 0, 0.2, 1) infinite;
  pointer-events: none;
}

.dark .shimmer-sweep {
  background: linear-gradient(
    105deg,
    transparent 20%,
    rgba(255, 255, 255, 0.25) 50%,
    transparent 80%
  );
}

@keyframes shimmer {
  0% {
    transform: translateX(-150%);
  }
  35%, 100% {
    transform: translateX(150%);
  }
}

.badge-icon {
  font-size: 0.95rem;
  display: flex;
  align-items: center;
}

.badge-arrow {
  transition: transform 0.2s ease;
  font-size: 0.9rem;
}

.shiny-badge:hover .badge-arrow {
  transform: translateX(3px);
}

@media (prefers-reduced-motion: reduce) {
  .shimmer-sweep {
    animation: none;
    display: none;
  }
}
</style>
