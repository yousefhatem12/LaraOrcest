<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue'

const heroRef = ref<HTMLElement | null>(null)
const mouseX = ref(50)
const mouseY = ref(30)

function onMouseMove(e: MouseEvent) {
  if (!heroRef.value) return
  const rect = heroRef.value.getBoundingClientRect()
  mouseX.value = Math.round(((e.clientX - rect.left) / rect.width) * 100)
  mouseY.value = Math.round(((e.clientY - rect.top) / rect.height) * 100)
}

onMounted(() => {
  const el = heroRef.value
  if (!el) return
  el.addEventListener('mousemove', onMouseMove)
})

onUnmounted(() => {
  const el = heroRef.value
  if (!el) return
  el.removeEventListener('mousemove', onMouseMove)
})
</script>

<template>
  <div
    ref="heroRef"
    class="aurora-hero-container"
    :style="{
      '--aurora-x': `${mouseX}%`,
      '--aurora-y': `${mouseY}%`
    }"
  >
    <!-- Background Aurora Blobs -->
    <div class="aurora-backdrop" aria-hidden="true">
      <div class="aurora-blob blob-red"></div>
      <div class="aurora-blob blob-indigo"></div>
      <div class="aurora-blob blob-cyan"></div>
      <div class="aurora-spotlight"></div>
    </div>

    <!-- Foreground Content -->
    <div class="aurora-content">
      <div class="hero-badge-slot">
        <slot name="badge"></slot>
      </div>

      <div class="hero-logo-wrapper">
        <div class="logo-glow"></div>
        <img
          src="/logo.png"
          alt="LaraOrcest Logo"
          class="hero-logo"
          width="130"
          height="130"
        />
      </div>

      <div class="hero-title-slot">
        <slot name="title"></slot>
      </div>

      <div class="hero-subtitle-slot">
        <slot name="subtitle"></slot>
      </div>

      <div class="hero-actions-slot">
        <slot name="actions"></slot>
      </div>
    </div>
  </div>
</template>

<style scoped>
.aurora-hero-container {
  position: relative;
  overflow: hidden;
  padding: 4.5rem 1.5rem 3.5rem;
  margin: -1.5rem -1.5rem 2.5rem;
  border-radius: 24px;
  background: var(--vp-c-bg-alt);
  border: 1px solid var(--lo-card-border);
  box-shadow: var(--lo-card-shadow);
  display: flex;
  align-items: center;
  justify-content: center;
  text-align: center;
}

@media (min-width: 768px) {
  .aurora-hero-container {
    padding: 5.5rem 2.5rem 4.5rem;
    margin: -1.5rem 0 3.5rem;
  }
}

/* Aurora Backdrop */
.aurora-backdrop {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  overflow: hidden;
  pointer-events: none;
  z-index: 1;
}

.aurora-spotlight {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: radial-gradient(
    circle at var(--aurora-x) var(--aurora-y),
    rgba(255, 45, 32, 0.12) 0%,
    transparent 60%
  );
  transition: background 0.15s ease-out;
}

.dark .aurora-spotlight {
  background: radial-gradient(
    circle at var(--aurora-x) var(--aurora-y),
    rgba(255, 45, 32, 0.18) 0%,
    rgba(99, 102, 241, 0.10) 35%,
    transparent 65%
  );
}

.aurora-blob {
  position: absolute;
  border-radius: 50%;
  filter: blur(80px);
  opacity: 0.45;
  will-change: transform;
  animation: floatBlobs 16s ease-in-out infinite alternate;
}

.blob-red {
  width: 380px;
  height: 380px;
  background: #FF2D20;
  top: -100px;
  left: 20%;
  animation-duration: 14s;
}

.blob-indigo {
  width: 440px;
  height: 440px;
  background: #6366F1;
  top: 20%;
  right: 15%;
  animation-duration: 18s;
  animation-delay: -4s;
}

.blob-cyan {
  width: 320px;
  height: 320px;
  background: #06B6D4;
  bottom: -50px;
  left: 35%;
  animation-duration: 20s;
  animation-delay: -8s;
}

.dark .aurora-blob {
  opacity: 0.35;
  filter: blur(90px);
}

@keyframes floatBlobs {
  0% {
    transform: translate(0, 0) scale(1);
  }
  50% {
    transform: translate(40px, -30px) scale(1.12);
  }
  100% {
    transform: translate(-30px, 40px) scale(0.92);
  }
}

/* Foreground Content */
.aurora-content {
  position: relative;
  z-index: 2;
  max-width: 860px;
  margin: 0 auto;
}

.hero-logo-wrapper {
  position: relative;
  display: inline-block;
  margin-bottom: 1.5rem;
}

.hero-logo {
  position: relative;
  z-index: 2;
  filter: drop-shadow(0 12px 24px rgba(255, 45, 32, 0.25));
  transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1);
}

.hero-logo:hover {
  transform: scale(1.05) rotate(-2deg);
}

.logo-glow {
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  width: 140px;
  height: 140px;
  background: radial-gradient(circle, rgba(255, 45, 32, 0.4) 0%, rgba(99, 102, 241, 0.2) 60%, transparent 80%);
  border-radius: 50%;
  filter: blur(25px);
  z-index: 1;
  pointer-events: none;
}

.hero-title-slot {
  margin-bottom: 1rem;
}

.hero-subtitle-slot {
  margin-bottom: 2rem;
  font-size: 1.15rem;
  line-height: 1.6;
  color: var(--vp-c-text-2);
  max-width: 680px;
  margin-left: auto;
  margin-right: auto;
}

.hero-actions-slot {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 1rem;
  flex-wrap: wrap;
}

.hero-actions-slot :deep(a) {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0.65rem 1.35rem;
  font-size: 0.925rem;
  font-weight: 600;
  border-radius: 9999px;
  text-decoration: none !important;
  transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
}

.hero-actions-slot :deep(a.btn-brand) {
  background: var(--vp-c-brand-1);
  color: #FFFFFF !important;
  box-shadow: 0 4px 14px rgba(255, 45, 32, 0.35);
}

.hero-actions-slot :deep(a.btn-brand:hover) {
  background: var(--vp-c-brand-2);
  transform: translateY(-2px);
  box-shadow: 0 8px 24px rgba(255, 45, 32, 0.45);
}

.hero-actions-slot :deep(a.btn-alt) {
  background: var(--vp-c-bg-elv);
  color: var(--vp-c-text-1) !important;
  border: 1px solid var(--lo-card-border);
}

.hero-actions-slot :deep(a.btn-alt:hover) {
  background: var(--vp-c-bg-soft);
  border-color: var(--vp-c-brand-1);
  transform: translateY(-2px);
}

@media (prefers-reduced-motion: reduce) {
  .aurora-blob {
    animation: none !important;
  }
  .aurora-spotlight {
    display: none !important;
  }
}
</style>
