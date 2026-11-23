<script setup lang="ts">
import { withBase } from 'vitepress'

withDefaults(
  defineProps<{
    src?: string
    poster?: string
    title?: string
    badge?: string
  }>(),
  {
    src: '/videos/lara-orcest-overview.mp4',
    poster: '/logo.png',
    title: 'LaraOrcest Architecture & Execution Walkthrough',
    badge: 'Silent Graphical Video • No Audio'
  }
)
</script>

<template>
  <div class="video-explainer-card">
    <div class="video-header">
      <div class="video-title-group">
        <span class="video-badge">{{ badge }}</span>
        <h4 class="video-title">{{ title }}</h4>
      </div>
      <span class="video-meta">720p HD • 30fps • 24s • Silent</span>
    </div>

    <div class="video-wrapper">
      <video
        controls
        muted
        playsinline
        preload="metadata"
        :poster="withBase(poster)"
        class="explainer-video"
      >
        <source :src="withBase(src)" type="video/mp4" />
        Your browser does not support the video tag.
      </video>
    </div>

    <div class="video-footer">
      <span class="footer-note">
        💡 <em>This graphical explainer video is intentionally silent (no audio track). It visualizes directed graph state progression, the 9 specialized pod roles, parallel wave scheduling, security boundaries, and Artisan CLI workflows.</em>
      </span>
    </div>
  </div>
</template>

<style scoped>
.video-explainer-card {
  margin: 2.25rem 0;
  padding: 1.25rem;
  border-radius: 16px;
  background: var(--lo-card-bg);
  border: 1px solid var(--lo-card-border);
  box-shadow: var(--lo-card-shadow);
  backdrop-filter: blur(12px);
  -webkit-backdrop-filter: blur(12px);
}

.video-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 0.85rem;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.video-badge {
  display: inline-block;
  font-size: 0.685rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  padding: 0.2rem 0.6rem;
  border-radius: 9999px;
  background: var(--vp-c-brand-soft);
  color: var(--vp-c-brand-1);
  border: 1px solid rgba(255, 45, 32, 0.25);
}

.video-title {
  margin: 0.35rem 0 0 0 !important;
  font-size: 1.15rem !important;
  font-weight: 700 !important;
  border: none !important;
  padding: 0 !important;
  color: var(--vp-c-text-1);
}

.video-meta {
  font-size: 0.775rem;
  font-family: monospace;
  color: var(--vp-c-text-3);
}

.video-wrapper {
  position: relative;
  width: 100%;
  border-radius: 10px;
  overflow: hidden;
  background: #090D16;
  border: 1px solid var(--vp-c-border);
  box-shadow: 0 4px 18px rgba(0, 0, 0, 0.15);
}

.explainer-video {
  width: 100%;
  display: block;
  max-height: 540px;
  object-fit: contain;
}

.video-footer {
  margin-top: 0.75rem;
  font-size: 0.825rem;
  line-height: 1.5;
  color: var(--vp-c-text-2);
}
</style>
