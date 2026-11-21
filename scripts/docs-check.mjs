#!/usr/bin/env node
/**
 * Documentation check (no dependencies). Run: `node scripts/docs-check.mjs`.
 *
 * - every page in docs/nav.json exists in every language, and no page is unlisted;
 * - every page has front matter with a title and a description;
 * - translations keep the same outline: heading levels in the same order and the
 *   same number of code blocks as the default language;
 * - relative links resolve: target files exist and #anchors match a heading
 *   (GitHub slug rules), in the docs and in README.md / README.cs.md.
 */
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const docs = path.join(root, 'docs')
const errors = []
const fail = (file, message) => errors.push(`${path.relative(root, file)}: ${message}`)

const nav = JSON.parse(fs.readFileSync(path.join(docs, 'nav.json'), 'utf8'))
const languages = Object.keys(nav.languages ?? {})
const primary = nav.default ?? languages[0]
const pages = nav.sections.flatMap((section) => section.pages)

if (languages.length === 0 || !languages.includes(primary)) {
  errors.push('docs/nav.json: "languages" must list the "default" language')
}

for (const section of nav.sections) {
  for (const language of languages) {
    if (typeof section.title?.[language] !== 'string') {
      errors.push(`docs/nav.json: section ${JSON.stringify(section.title)} has no "${language}" title`)
    }
  }
}

if (new Set(pages).size !== pages.length) {
  errors.push('docs/nav.json: a page is listed twice')
}

/** Markdown without fenced code blocks, plus the number of blocks removed. */
function prose(source) {
  let blocks = 0
  let fence = null
  const lines = []

  for (const line of source.split('\n')) {
    const marker = line.match(/^\s*(```+|~~~+)/)

    if (marker && (fence === null || marker[1].startsWith(fence))) {
      if (fence === null) {
        fence = marker[1]
        blocks++
      } else {
        fence = null
      }
      lines.push('')
      continue
    }

    lines.push(fence === null ? line : '')
  }

  return { text: lines.join('\n'), blocks }
}

/** GitHub's heading anchor. */
function slug(heading) {
  return heading
    .replace(/\[([^\]]*)\]\([^)]*\)/g, '$1')
    .toLowerCase()
    .replace(/[^\p{L}\p{N}\s_-]/gu, '')
    .trim()
    .replace(/\s/g, '-')
}

const parsed = new Map()

/** Headings, anchors and links of one markdown file (cached). */
function parse(file) {
  if (parsed.has(file)) {
    return parsed.get(file)
  }

  const source = fs.readFileSync(file, 'utf8')
  const front = source.match(/^---\n([\s\S]*?)\n---\n/)
  const { text, blocks } = prose(front ? source.slice(front[0].length) : source)
  const headings = []
  const anchors = new Set()
  const seen = new Map()

  for (const match of text.matchAll(/^(#{1,6})\s+(.+?)\s*#*\s*$/gm)) {
    const base = slug(match[2])
    const count = seen.get(base) ?? 0
    seen.set(base, count + 1)
    anchors.add(count === 0 ? base : `${base}-${count}`)
    headings.push(match[1].length)
  }

  const links = [...text.replace(/`[^`\n]*`/g, '').matchAll(/\]\(([^)\s]+)(?:\s+"[^"]*")?\)/g)].map((m) => m[1])
  const result = { source, front: front?.[1] ?? null, headings, anchors, links, blocks }
  parsed.set(file, result)

  return result
}

function checkLinks(file) {
  for (const link of parse(file).links) {
    if (/^[a-z][a-z0-9+.-]*:/i.test(link)) {
      continue
    }

    const [target, anchor] = link.split('#')
    const resolved = target === '' ? file : path.resolve(path.dirname(file), decodeURI(target))

    if (!fs.existsSync(resolved)) {
      fail(file, `broken link ${link}`)
      continue
    }

    if (anchor !== undefined && resolved.endsWith('.md') && !parse(resolved).anchors.has(decodeURIComponent(anchor))) {
      fail(file, `no heading for anchor ${link}`)
    }
  }
}

for (const language of languages) {
  const dir = path.join(docs, language)
  const onDisk = fs.existsSync(dir) ? fs.readdirSync(dir).filter((name) => name.endsWith('.md')) : []

  for (const name of onDisk.filter((name) => !pages.includes(name))) {
    fail(path.join(dir, name), 'is not listed in docs/nav.json')
  }

  for (const page of pages) {
    const file = path.join(dir, page)

    if (!fs.existsSync(file)) {
      fail(file, 'is listed in docs/nav.json but missing')
      continue
    }

    const { front, headings, blocks } = parse(file)

    if (!front || !/^title: .+$/m.test(front) || !/^description: .+$/m.test(front)) {
      fail(file, 'needs front matter with title and description')
    }

    for (const line of (front ?? '').split('\n')) {
      const value = line.replace(/^[\w-]+:\s*/, '')

      if (!/^["']/.test(value) && (/: | #/.test(value) || /^[`[\]{}&*!|>%@<]/.test(value))) {
        fail(file, `front matter value must be quoted (YAML): ${line}`)
      }
    }

    checkLinks(file)

    // The site renders Markdown through Vue: a bare <tag> or {{ outside code breaks the build.
    prose(fs.readFileSync(file, 'utf8').replace(/^---\n[\s\S]*?\n---\n/, (m) => m.replace(/[^\n]/g, '')))
      .text.split('\n')
      .forEach((line, index) => {
        if (/<\/?[a-zA-Z]|\{\{/.test(line.replace(/`[^`]*`/g, ''))) {
          fail(file, `line ${index + 1}: wrap HTML tags and {{ }} in backticks`)
        }
      })

    if (language === primary) {
      continue
    }

    const original = path.join(docs, primary, page)

    if (!fs.existsSync(original)) {
      continue
    }

    const reference = parse(original)

    if (headings.join() !== reference.headings.join()) {
      fail(file, `heading outline differs from ${primary}/${page} (${headings.length} vs ${reference.headings.length} headings)`)
    }

    if (blocks !== reference.blocks) {
      fail(file, `has ${blocks} code blocks, ${primary}/${page} has ${reference.blocks}`)
    }
  }
}

for (const readme of ['README.md', 'README.cs.md']) {
  const file = path.join(root, readme)

  if (fs.existsSync(file)) {
    checkLinks(file)
  } else {
    fail(file, 'is missing')
  }
}

if (errors.length > 0) {
  console.error(errors.map((error) => `✗ ${error}`).join('\n'))
  console.error(`\n${errors.length} documentation problem(s).`)
  process.exit(1)
}

console.log(`✓ Docs OK: ${pages.length} pages × ${languages.length} languages (${languages.join(', ')})`)
