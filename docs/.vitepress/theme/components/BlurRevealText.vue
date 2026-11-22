<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'

const props = withDefaults(
  defineProps<{
    text: string
    tag?: string
    delay?: number
    stagger?: number
    highlight?: string | string[]
  }>(),
  {
    tag: 'h1',
    delay: 60,
    stagger: 45,
    highlight: ''
  }
)

const isMounted = ref(false)

onMounted(() => {
  // Activate animation after hydration
  isMounted.value = true
})

const words = computed(() => {
  return props.text.split(' ')
})

const highlightsList = computed(() => {
  if (!props.highlight) return []
  return Array.isArray(props.highlight) ? props.highlight : [props.highlight]
})

function isHighlighted(word: string): boolean {
  const cleanWord = word.replace(/[^a-zA-Z0-9-]/g, '').toLowerCase()
  return highlightsList.value.some((h) => h.toLowerCase() === cleanWord)
}
</script>

<template>
  <component :is="tag" class="blur-reveal-heading">
    <span
      v-for="(word, index) in words"
      :key="index"
      class="word-span"
      :class="{
        'is-revealed': isMounted,
        'is-highlighted': isHighlighted(word)
      }"
      :style="{
        transitionDelay: isMounted ? `${delay + index * stagger}ms` : '0ms'
      }"
    >
      {{ word }}&nbsp;
    </span>
  </component>
</template>

<style scoped>
.blur-reveal-heading {
  margin: 0;
  display: inline-block;
  line-height: 1.15;
  font-weight: 800;
  letter-spacing: -0.025em;
}

.word-span {
  display: inline-block;
  opacity: 0;
  filter: blur(10px);
  transform: translateY(14px);
  transition: opacity 0.65s cubic-bezier(0.16, 1, 0.3, 1),
              filter 0.65s cubic-bezier(0.16, 1, 0.3, 1),
              transform 0.65s cubic-bezier(0.16, 1, 0.3, 1);
  will-change: opacity, filter, transform;
}

.word-span.is-revealed {
  opacity: 1;
  filter: blur(0px);
  transform: translateY(0);
}

.word-span.is-highlighted {
  background: linear-gradient(135deg, #FF2D20 0%, #6366F1 50%, #06B6D4 100%);
  -webkit-background-clip: text;
  background-clip: text;
  -webkit-text-fill-color: transparent;
}

@media (prefers-reduced-motion: reduce) {
  .word-span {
    opacity: 1 !important;
    filter: none !important;
    transform: none !important;
    transition: none !important;
  }
}
</style>
