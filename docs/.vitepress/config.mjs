/**
 * Documentation site (VitePress, built by .github/workflows/docs.yml).
 *
 * docs/nav.json stays the single table of contents: the sidebar of every
 * language is generated from it, with page titles taken from front matter.
 * English is served at the root (docs/en → /), Czech under /cs/.
 */
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { defineConfig } from 'vitepress'

const docs = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const nav = JSON.parse(fs.readFileSync(path.join(docs, 'nav.json'), 'utf8'))
const repository = 'https://github.com/NyonCode/laravel-pwa'

/** GitHub-compatible heading anchors, so links work on GitHub and on the site alike. */
const slugify = (text) =>
  text
    .toLowerCase()
    .replace(/[^\p{L}\p{N}\s_-]/gu, '')
    .trim()
    .replace(/\s/g, '-')

function title(language, page) {
  const source = fs.readFileSync(path.join(docs, language, page), 'utf8')

  return source.match(/^title:\s*(.+)$/m)?.[1].trim() ?? page.replace(/\.md$/, '')
}

function sidebar(language, prefix) {
  return nav.sections.map((section) => ({
    text: section.title[language],
    items: section.pages.map((page) => ({
      text: title(language, page),
      link: prefix + (page === 'index.md' ? '' : page.replace(/\.md$/, '')),
    })),
  }))
}

const labels = {
  en: { description: 'Production-grade PWA for Laravel', edit: 'Edit this page on GitHub', outline: 'On this page' },
  cs: { description: 'Progressive Web App pro Laravel připravená do produkce', edit: 'Upravit stránku na GitHubu', outline: 'Na této stránce' },
}

const locale = (language, prefix) => ({
  label: nav.languages[language],
  lang: language,
  link: prefix,
  description: labels[language].description,
  themeConfig: {
    sidebar: sidebar(language, prefix),
    editLink: { pattern: `${repository}/edit/main/docs/:path`, text: labels[language].edit },
    outline: { level: [2, 3], label: labels[language].outline },
  },
})

export default defineConfig({
  title: 'Laravel PWA',
  base: process.env.DOCS_BASE ?? '/laravel-pwa/',
  cleanUrls: true,
  lastUpdated: true,
  rewrites: { 'en/:rest*': ':rest*' },
  markdown: { anchor: { slugify } },
  head: [['meta', { name: 'theme-color', content: '#ff2d20' }]],
  locales: {
    root: locale('en', '/'),
    cs: locale('cs', '/cs/'),
  },
  themeConfig: {
    socialLinks: [{ icon: 'github', link: repository }],
    search: { provider: 'local' },
    footer: { message: 'Released under the MIT License.' },
  },
})
