import DefaultTheme from 'vitepress/theme'
import type { Theme } from 'vitepress'
import './custom.css'

import AuroraHero from './components/AuroraHero.vue'
import ShinyBadge from './components/ShinyBadge.vue'
import BlurRevealText from './components/BlurRevealText.vue'
import SpotlightCard from './components/SpotlightCard.vue'
import AgentFlowVisualizer from './components/AgentFlowVisualizer.vue'
import AnimatedIcon from './components/AnimatedIcon.vue'

export default {
  extends: DefaultTheme,
  enhanceApp({ app }) {
    // Register ReactBits-inspired interactive components globally
    app.component('AuroraHero', AuroraHero)
    app.component('ShinyBadge', ShinyBadge)
    app.component('BlurRevealText', BlurRevealText)
    app.component('SpotlightCard', SpotlightCard)
    app.component('AgentFlowVisualizer', AgentFlowVisualizer)
    app.component('AnimatedIcon', AnimatedIcon)
  }
} satisfies Theme
