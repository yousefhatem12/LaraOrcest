import { defineConfig } from 'vitepress'

// Determine base URL: supports GitHub Pages (/<repo-name>/) or custom deployment
const base = process.env.VITEPRESS_BASE || (process.env.GITHUB_ACTIONS ? '/LaraOrcest/' : '/')

export default defineConfig({
  base,
  lang: 'en-US',
  title: 'LaraOrcest',
  description: 'Prompt-driven multi-agent orchestration for Laravel with specialized roles, stateful graph execution, and safe Git workflows.',

  head: [
    ['link', { rel: 'icon', type: 'image/png', href: `${base}logo.png` }],
    ['meta', { name: 'theme-color', content: '#ef4444' }],
    ['meta', { property: 'og:type', content: 'website' }],
    ['meta', { property: 'og:title', content: 'LaraOrcest - Multi-Agent Orchestration for Laravel' }],
    ['meta', { property: 'og:description', content: 'Prompt-driven feature delivery combining CrewAI-style role specialization with LangGraph-style stateful graphs. 100% native PHP.' }],
    ['meta', { property: 'og:image', content: `${base}logo.png` }]
  ],

  lastUpdated: true,
  cleanUrls: true,

  markdown: {
    languageAlias: {
      env: 'bash',
      dotenv: 'bash'
    }
  },

  // Ignore links pointing to outside docs/ (e.g. root spec.md or examples/ directory)
  ignoreDeadLinks: [
    /examples/,
    /spec\.md/,
  ],

  themeConfig: {
    logo: '/logo.png',
    siteTitle: 'LaraOrcest',

    nav: [
      { text: 'Overview', link: '/' },
      { text: 'Quickstart', link: '/quickstart' },
      { text: 'Architecture', link: '/architecture' },
      { text: 'Agents', link: '/agents' },
      { text: 'Safety & HITL', link: '/safety-and-hitl' },
      { text: 'Use Cases', link: '/use-cases' },
      {
        text: 'Resources',
        items: [
          { text: 'Examples Directory', link: 'https://github.com/yousefhatem12/LaraOrcest/tree/main/examples' },
          { text: 'Technical Spec', link: 'https://github.com/yousefhatem12/LaraOrcest/blob/main/spec.md' },
          { text: 'Packagist Package', link: 'https://packagist.org/packages/yousefhatem12/lara-orcest' }
        ]
      }
    ],

    sidebar: [
      {
        text: 'Getting Started',
        items: [
          { text: 'Introduction & Vision', link: '/' },
          { text: 'Quickstart Guide', link: '/quickstart' }
        ]
      },
      {
        text: 'Core Architecture',
        items: [
          { text: 'State Engine & Graphs', link: '/architecture' },
          { text: 'Agents & Model Routing', link: '/agents' },
          { text: 'Safety, Sandboxing & HITL', link: '/safety-and-hitl' }
        ]
      },
      {
        text: 'Real-World Guides',
        items: [
          { text: 'Practical Use Cases', link: '/use-cases' }
        ]
      },
      {
        text: 'Reference',
        items: [
          { text: 'Examples & Workflows', link: 'https://github.com/yousefhatem12/LaraOrcest/tree/main/examples' },
          { text: 'Technical Specification', link: 'https://github.com/yousefhatem12/LaraOrcest/blob/main/spec.md' }
        ]
      }
    ],

    search: {
      provider: 'local',
      options: {
        locales: {
          root: {
            translations: {
              button: {
                buttonText: 'Search documentation...',
                buttonAriaLabel: 'Search documentation'
              },
              modal: {
                noResultsText: 'No relevant results found',
                resetButtonTitle: 'Reset search',
                footer: {
                  selectText: 'to select',
                  navigateText: 'to navigate',
                  closeText: 'to close'
                }
              }
            }
          }
        }
      }
    },

    socialLinks: [
      { icon: 'github', link: 'https://github.com/yousefhatem12/LaraOrcest' }
    ],

    editLink: {
      pattern: 'https://github.com/yousefhatem12/LaraOrcest/edit/main/docs/:path',
      text: 'Edit this page on GitHub'
    },

    footer: {
      message: 'Released under the MIT License.',
      copyright: 'Copyright © 2026 Yousef Hatem'
    }
  }
})
