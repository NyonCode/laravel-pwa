#!/usr/bin/env node
/**
 * MCP server for nyoncode/laravel-pwa.
 *
 * Answers questions about the package from the *installed* copy — the English
 * docs in `docs/en/`, the agent guide in `ai/AGENTS.md`, signatures and docblocks parsed
 * out of `src/`, the keys of `config/pwa.php`, the artisan command signatures
 * and the browser client in `resources/js/pwa.js`. An agent asking
 * `describe_config` gets the default as it exists in the release it is coding
 * against, not as it existed in some training snapshot.
 *
 * Derived from the server shipped with nyoncode/laravel-package-toolkit.
 *
 * Zero dependencies, Node 18+. The MCP stdio transport is newline-delimited
 * JSON-RPC 2.0, which is small enough to implement here rather than pull in an
 * SDK that would have to be `npm install`ed inside `vendor/`.
 *
 *   node ai/mcp/server.mjs            speak MCP on stdin/stdout
 *   node ai/mcp/server.mjs --self-test  parse everything and print a summary
 */

import fs from 'node:fs'
import path from 'node:path'
import readline from 'node:readline'
import { fileURLToPath } from 'node:url'

const here = path.dirname(fileURLToPath(import.meta.url))
const packageRoot = path.resolve(here, '..', '..')
const navFile = path.join(packageRoot, 'docs', 'nav.json')
const docsDir = path.join(packageRoot, 'docs', 'en')
const srcDir = path.join(packageRoot, 'src')
const guideFile = path.join(packageRoot, 'ai', 'AGENTS.md')
const configFile = path.join(packageRoot, 'config', 'pwa.php')
const consoleDir = path.join(srcDir, 'Console')
const clientFile = path.join(packageRoot, 'resources', 'js', 'pwa.js')

const SERVER = { name: 'laravel-pwa', version: readPackageVersion() }
const FALLBACK_PROTOCOL = '2025-06-18'

/* ----------------------------------------------------------------- reading */

function readPackageVersion() {
  // The installed version is not in composer.json (Composer resolves it from the
  // VCS tag), so the changelog is the only thing on disk that knows.
  try {
    const changelog = fs.readFileSync(path.join(packageRoot, 'CHANGELOG.md'), 'utf8')
    const match = changelog.match(/^##\s*\[(\d+\.\d+\.\d+)\]/m)

    return match ? match[1] : '0.0.0'
  } catch {
    return '0.0.0'
  }
}

function parseFrontMatter(source) {
  const match = source.match(/^---\r?\n([\s\S]*?)\r?\n---\r?\n?/)

  if (!match) {
    return { data: {}, body: source }
  }

  const data = {}

  for (const line of match[1].split(/\r?\n/)) {
    const pair = line.match(/^([A-Za-z_][\w-]*):\s*(.*)$/)

    if (pair) {
      data[pair[1]] = pair[2].replace(/^["']|["']$/g, '').trim()
    }
  }

  return { data, body: source.slice(match[0].length) }
}

/**
 * The published pages, in navigation order.
 *
 * `docs/nav.json` is the table of contents; a page missing from it is not
 * served. If it cannot be read, every `.md` in `docs/en/` is served instead.
 */
function publishedPages() {
  try {
    const nav = JSON.parse(fs.readFileSync(navFile, 'utf8'))
    const files = (nav.sections ?? []).flatMap((section) => section.pages ?? [])

    return files.length > 0 ? files : null
  } catch {
    return null
  }
}

/** Every documentation page plus the agent guide, keyed by slug. */
const documents = (() => {
  const found = new Map()

  const listed = publishedPages()

  const files = listed
    ? listed.filter((name) => fs.existsSync(path.join(docsDir, name)))
    : fs.existsSync(docsDir)
      ? fs
          .readdirSync(docsDir)
          .filter((name) => name.endsWith('.md'))
          .sort()
      : []

  for (const name of files) {
    const slug = name === 'index.md' ? 'overview' : name.replace(/\.md$/, '')
    const source = fs.readFileSync(path.join(docsDir, name), 'utf8')
    const { data, body } = parseFrontMatter(source)
    const heading = body.match(/^#\s+(.+)$/m)

    found.set(slug, {
      slug,
      title: data.title ?? (heading ? heading[1].trim() : slug),
      description: data.description ?? '',
      file: path.relative(packageRoot, path.join(docsDir, name)),
      body: body.trim(),
    })
  }

  if (fs.existsSync(guideFile)) {
    found.set('agent-guide', {
      slug: 'agent-guide',
      title: 'Agent guide — the complete API in one file',
      description: 'Every builder, every gotcha, written for an agent rather than a reader.',
      file: path.relative(packageRoot, guideFile),
      body: fs.readFileSync(guideFile, 'utf8').trim(),
    })
  }

  return found
})()

/* -------------------------------------------------------------- php parsing */

/**
 * Which object a method lives on, and where application code calls it from.
 */
const API_GROUPS = [
  { owner: 'Pwa', file: path.join(srcDir, 'Pwa.php'), calledFrom: 'the Pwa facade (NyonCode\\LaravelPwa\\Facades\\Pwa) or app(Pwa::class)' },
  { owner: 'ManifestBuilder', file: path.join(srcDir, 'Manifest', 'ManifestBuilder.php'), calledFrom: 'Pwa::manifest() — usually in AppServiceProvider::boot()' },
  { owner: 'DisplayStrategy', file: path.join(srcDir, 'Manifest', 'DisplayStrategy.php'), calledFrom: 'config pwa.display.strategy (enum override|adaptive|fixed)' },
  { owner: 'ServiceWorkerBuilder', file: path.join(srcDir, 'ServiceWorker', 'ServiceWorkerBuilder.php'), calledFrom: 'Pwa::serviceWorker() — used by pwa:build and the /sw.js route' },
  { owner: 'Contracts', dir: path.join(srcDir, 'Contracts'), calledFrom: 'implement in the app: ManifestResolver (resolve), ManifestParameters (parameters); register with Pwa::manifest()->resolveUsing() or config pwa.manifest.resolvers' },
  { owner: 'InteractsWithPwa', file: path.join(srcDir, 'Testing', 'InteractsWithPwa.php'), calledFrom: 'trait for the app tests/TestCase.php; TestResponse macros assertPwaCacheable(), assertNotPwaCacheable(), assertPwaLogout(), assertPwaUser($user), assertValidManifest($strict) are registered by the package' },
  { owner: 'ManifestValidator', file: path.join(srcDir, 'Manifest', 'ManifestValidator.php'), calledFrom: 'app(ManifestValidator::class)->validate($manifest) — also behind assertValidManifest()' },
  { owner: 'CacheRule', file: path.join(srcDir, 'ServiceWorker', 'CacheRule.php'), calledFrom: 'Pwa::serviceWorker()->cache($pattern) — fluent rule; also built from config pwa.cache.rules' },
  { owner: 'Middleware', dir: path.join(srcDir, 'Http', 'Middleware'), calledFrom: 'route middleware pwa.remember, pwa.cache; PwaWebMiddleware is appended to the web group' },
  { owner: 'WebPush', file: path.join(srcDir, 'Push', 'WebPush.php'), calledFrom: 'the WebPush facade (NyonCode\\LaravelPwa\\Facades\\WebPush) — optional push module, pwa.push.enabled' },
  { owner: 'WebPushMessage', file: path.join(srcDir, 'Push', 'WebPushMessage.php'), calledFrom: 'returned from a notification toWebPush($notifiable) for WebPushChannel, or passed to WebPush::send()' },
  { owner: 'WebPushFake', file: path.join(srcDir, 'Testing', 'WebPushFake.php'), calledFrom: 'WebPush::fake() in application tests' },
]

/** Collapse a docblock to its prose, dropping the comment furniture and tags. */
function cleanDocblock(block) {
  if (!block) {
    return ''
  }

  const lines = block
    .replace(/^\/\*\*/, '')
    .replace(/\*\/$/, '')
    .split(/\r?\n/)
    .map((line) => line.replace(/^\s*\*\s?/, '').trimEnd())

  const prose = []
  const tags = []

  for (const line of lines) {
    if (/^\s*@/.test(line)) {
      tags.push(line.trim())
    } else if (tags.length === 0) {
      prose.push(line)
    }
  }

  const summary = prose.join('\n').trim().replace(/\n{3,}/g, '\n\n')
  const params = tags.filter((tag) => /^@(param|return|throws|var)\b/.test(tag))

  return [summary, params.join('\n')].filter(Boolean).join('\n\n')
}

/**
 * Public method signatures out of one PHP file.
 *
 * A regex, not a parser: the package's own source is uniformly formatted by
 * Pint, so every declaration is `public function name(...)` with the docblock
 * immediately above it. Anything a regex would get wrong here would also be
 * something Pint would have reformatted.
 */
function parseMethods(file) {
  const source = fs.readFileSync(file, 'utf8')
  const relative = path.relative(packageRoot, file)
  const methods = []

  const pattern = /(\/\*\*(?:(?!\*\/)[\s\S])*\*\/)?\s*public\s+(?:static\s+)?function\s+([A-Za-z_]\w*)\s*\(([\s\S]*?)\)\s*(?::\s*([^\n{;]+?))?\s*[{;]/g

  let match

  while ((match = pattern.exec(source)) !== null) {
    const [, doc, name, rawParameters, rawReturn] = match

    if (name === '__construct' || name.startsWith('__')) {
      continue
    }

    const parameters = rawParameters.replace(/\s*\n\s*/g, ' ').replace(/\s{2,}/g, ' ').trim()
    const returns = (rawReturn ?? '').trim()
    const line = source.slice(0, match.index + match[0].indexOf('function')).split('\n').length

    methods.push({
      name,
      signature: `${name}(${parameters})${returns ? `: ${returns}` : ''}`,
      returns,
      doc: cleanDocblock(doc),
      file: relative,
      line,
    })
  }

  return methods
}

function phpFilesIn(dir, shallow) {
  if (!fs.existsSync(dir)) {
    return []
  }

  return fs
    .readdirSync(dir, { withFileTypes: true })
    .flatMap((entry) => {
      const full = path.join(dir, entry.name)

      if (entry.isDirectory()) {
        return shallow ? [] : phpFilesIn(full, shallow)
      }

      return entry.name.endsWith('.php') ? [full] : []
    })
    .sort()
}

/** Every public method the package exposes, grouped by the object it lives on. */
const api = (() => {
  const all = []

  for (const group of API_GROUPS) {
    const files = group.file ? (fs.existsSync(group.file) ? [group.file] : []) : phpFilesIn(group.dir, group.shallow)

    for (const file of files) {
      for (const method of parseMethods(file)) {
        all.push({
          ...method,
          owner: group.owner,
          calledFrom: group.calledFrom,
          declaredIn: path.basename(file, '.php'),
        })
      }
    }
  }

  return all
})()

/* ------------------------------------------------------- config, commands */

/**
 * Flatten config/pwa.php into dot keys with the default as written.
 *
 * Line-based, which holds because the file is Pint-formatted with one key per
 * line: `'key' => value,` or `'key' => [` opening a nested array.
 */
const configKeys = (() => {
  if (!fs.existsSync(configFile)) return []

  const lines = fs.readFileSync(configFile, 'utf8').split(/\r?\n/)
  const stack = []
  const keys = []
  let section = ''
  let pendingComment = []

  for (const raw of lines) {
    const line = raw.trim()

    const heading = line.match(/^\|\s+([A-ZÁ-Ž].*)$/)
    if (heading && !/^\|-+/.test(line)) {
      if (!section || /^\|\s*$/.test(lines[lines.indexOf(raw) - 1] ?? '')) section = heading[1].trim()
    }
    if (/^\/\*/.test(line)) { section = ''; pendingComment = [] }

    if (line.startsWith('//')) { pendingComment.push(line.replace(/^\/\/\s*/, '')); continue }

    const open = line.match(/^'([\w-]+)'\s*=>\s*\[\s*(\/\/.*)?$/)
    if (open) {
      stack.push({ name: open[1], children: 0, section })
      pendingComment = []
      continue
    }

    const pair = line.match(/^'([\w-]+)'\s*=>\s*(.+?),?\s*(?:\/\/\s*(.*))?$/)
    if (pair) {
      const value = pair[2].replace(/,\s*$/, '').trim()
      stack.forEach((frame) => frame.children++)
      keys.push({
        key: [...stack.map((frame) => frame.name), pair[1]].join('.'),
        value,
        note: [pair[3], ...pendingComment].filter(Boolean).join(' ').trim(),
        section,
      })
      pendingComment = []
      continue
    }

    if (/^\],?$/.test(line)) {
      const frame = stack.pop()

      // An array whose entries are only commented examples is still a key.
      if (frame && frame.children === 0) {
        keys.push({
          key: [...stack.map((f) => f.name), frame.name].join('.'),
          value: '[]',
          note: pendingComment.join(' ').trim(),
          section: frame.section,
        })
      }
      pendingComment = []
    }
  }

  return keys
})()

/** Artisan commands: `$signature` and `$description` out of each class in src/Console. */
const commands = (() => {
  if (!fs.existsSync(consoleDir)) return []

  return fs
    .readdirSync(consoleDir)
    .filter((name) => name.endsWith('.php'))
    .sort()
    .map((name) => {
      const file = path.join(consoleDir, name)
      const source = fs.readFileSync(file, 'utf8')
      const signature = (source.match(/protected \$signature = '([\s\S]*?)';/) ?? [])[1] ?? ''
      const description = (source.match(/protected \$description = '([\s\S]*?)';/) ?? [])[1] ?? ''

      return {
        name: signature.trim().split(/\s+/)[0],
        signature: signature.replace(/\n\s+/g, '\n    ').trim(),
        description,
        file: path.relative(packageRoot, file),
      }
    })
    .filter((command) => command.name)
})()

/** window.Pwa members and emitted event names out of the client source. */
const client = (() => {
  if (!fs.existsSync(clientFile)) return { members: [], events: [] }

  const source = fs.readFileSync(clientFile, 'utf8')
  const block = (source.match(/window\.Pwa = \{([\s\S]*?)\n\};/) ?? [])[1] ?? ''
  const members = [...block.matchAll(/^\s{2}(\w+)(\s*[:(,])/gm)].map((m) => (m[2].trim() === '(' ? `${m[1]}()` : m[1]))
  const badge = /badge:\s*\{/.test(block) ? ['badge.set(n)', 'badge.clear()'] : []
  const events = [...new Set([...source.matchAll(/emit\('([\w-]+)'/g)].map((m) => m[1]))].sort()

  return { members: [...members.filter((m) => m !== 'badge'), ...badge], events }
})()

/* --------------------------------------------------------------- searching */

/** Split a document into `## heading` chunks so a hit can be quoted in context. */
function sectionsOf(document) {
  const lines = document.body.split(/\r?\n/)
  const sections = []
  let current = { heading: document.title, lines: [] }
  let inFence = false

  for (const line of lines) {
    if (/^\s*```/.test(line)) {
      inFence = !inFence
    }

    if (!inFence && /^#{2,3}\s+/.test(line)) {
      sections.push(current)
      current = { heading: line.replace(/^#{2,3}\s+/, '').trim(), lines: [] }
    } else {
      current.lines.push(line)
    }
  }

  sections.push(current)

  return sections.filter((section) => section.lines.join('').trim() !== '')
}

function scoreSection(section, document, terms) {
  const heading = section.heading.toLowerCase()
  const body = section.lines.join('\n').toLowerCase()
  const title = document.title.toLowerCase()

  let score = 0

  for (const term of terms) {
    // A term in the heading is the strongest signal a section is *about* it,
    // rather than merely mentioning it in passing.
    if (heading.includes(term)) score += 10
    if (title.includes(term)) score += 4

    const occurrences = body.split(term).length - 1
    score += Math.min(occurrences, 5)
  }

  // Every term present beats one term present many times.
  const matched = terms.filter((term) => heading.includes(term) || body.includes(term)).length

  return matched === terms.length ? score + 8 : matched === 0 ? 0 : score
}

function searchDocuments(query, limit) {
  const terms = query
    .toLowerCase()
    .split(/[^a-z0-9_:.-]+/)
    .filter((term) => term.length > 1)

  if (terms.length === 0) {
    return []
  }

  const hits = []

  for (const document of documents.values()) {
    for (const section of sectionsOf(document)) {
      const score = scoreSection(section, document, terms)

      if (score > 0) {
        hits.push({ document, section, score })
      }
    }
  }

  return hits.sort((a, b) => b.score - a.score).slice(0, limit)
}

/* ------------------------------------------------------------------- tools */

const tools = [
  {
    name: 'search_docs',
    description:
      'Search the laravel-pwa documentation and agent guide. Returns the matching sections in full, ' +
      'accurate for the installed release. Use this first for any "how do I…" question about the PWA.',
    inputSchema: {
      type: 'object',
      properties: {
        query: { type: 'string', description: 'Words to look for, e.g. "offline queue", "minimal-ui", "remember".' },
        limit: { type: 'integer', description: 'Maximum sections to return (default 5).', minimum: 1, maximum: 20 },
      },
      required: ['query'],
    },
    handler({ query, limit }) {
      const hits = searchDocuments(String(query), Number(limit) || 5)

      if (hits.length === 0) {
        return `No section matches "${query}". Try list_docs to see what pages exist, or list_api for method names.`
      }

      return hits
        .map(
          ({ document, section }) =>
            `## ${section.heading}\n_${document.title} — ${document.file}, read in full with get_doc("${document.slug}")_\n\n${section.lines
              .join('\n')
              .trim()}`,
        )
        .join('\n\n---\n\n')
    },
  },
  {
    name: 'list_docs',
    description: 'List every documentation page available to get_doc, with its one-line description.',
    inputSchema: { type: 'object', properties: {} },
    handler() {
      return [...documents.values()]
        .map((document) => `- \`${document.slug}\` — **${document.title}**${document.description ? `: ${document.description}` : ''}`)
        .join('\n')
    },
  },
  {
    name: 'get_doc',
    description: 'Read one documentation page in full, by the slug from list_docs.',
    inputSchema: {
      type: 'object',
      properties: { slug: { type: 'string', description: 'Page slug, e.g. "offline", "session", "agent-guide".' } },
      required: ['slug'],
    },
    handler({ slug }) {
      const document = documents.get(String(slug).replace(/\.md$/, ''))

      if (!document) {
        return `No page "${slug}". Available: ${[...documents.keys()].join(', ')}.`
      }

      return `# ${document.title}\n_${document.file}_\n\n${document.body}`
    },
  },
  {
    name: 'list_api',
    description:
      'List the public PHP methods the package exposes, parsed from the installed source. Grouped by ' +
      'the object they live on: Pwa (facade), ManifestBuilder, DisplayStrategy, ServiceWorkerBuilder, Middleware.',
    inputSchema: {
      type: 'object',
      properties: {
        owner: {
          type: 'string',
          description: 'Restrict to one object.',
          enum: ['Pwa', 'ManifestBuilder', 'DisplayStrategy', 'ServiceWorkerBuilder', 'Middleware'],
        },
        filter: { type: 'string', description: 'Substring the method name must contain, e.g. "share" or "csp".' },
      },
    },
    handler({ owner, filter }) {
      const needle = filter ? String(filter).toLowerCase() : null

      const selected = api.filter(
        (method) =>
          (!owner || method.owner === owner) && (!needle || method.name.toLowerCase().includes(needle)),
      )

      if (selected.length === 0) {
        return 'No method matches. Drop the filter, or try list_api with no arguments.'
      }

      const byOwner = new Map()

      for (const method of selected) {
        if (!byOwner.has(method.owner)) byOwner.set(method.owner, [])
        byOwner.get(method.owner).push(method)
      }

      return [...byOwner]
        .map(([name, methods]) => {
          const calledFrom = methods[0].calledFrom

          return `## ${name}\n_Called from ${calledFrom}._\n\n${methods
            .map((method) => `- \`${method.signature}\`  — ${method.declaredIn}`)
            .join('\n')}`
        })
        .join('\n\n')
    },
  },
  {
    name: 'describe_api',
    description:
      'The exact signature, docblock and source location of one method, parsed from the installed ' +
      'source. Use before calling any builder you are not certain of.',
    inputSchema: {
      type: 'object',
      properties: { method: { type: 'string', description: 'Method name, e.g. "shareTarget" or "cspHashes".' } },
      required: ['method'],
    },
    handler({ method }) {
      const wanted = String(method).replace(/\(.*$/, '').trim().toLowerCase()
      const exact = api.filter((entry) => entry.name.toLowerCase() === wanted)
      const matches = exact.length > 0 ? exact : api.filter((entry) => entry.name.toLowerCase().includes(wanted))

      if (matches.length === 0) {
        return `No method named "${method}". Use list_api with a filter to browse.`
      }

      return matches
        .slice(0, 8)
        .map(
          (entry) =>
            `### \`${entry.owner}::${entry.signature}\`\n\n` +
            `Declared in \`${entry.file}:${entry.line}\` (${entry.declaredIn}).\n` +
            `Called from ${entry.calledFrom}.\n` +
            (entry.doc ? `\n${entry.doc}\n` : '\n_No docblock._\n'),
        )
        .join('\n---\n\n')
    },
  },
  {
    name: 'describe_config',
    description:
      'Keys of config/pwa.php with their default value (as written in the installed config) and the ' +
      'comment above them. Pass a key or prefix ("cache", "session.keepalive_minutes"), or nothing for all.',
    inputSchema: {
      type: 'object',
      properties: { key: { type: 'string', description: 'Dot key or prefix, e.g. "display" or "sw.import".' } },
    },
    handler({ key }) {
      const wanted = key ? String(key).replace(/^pwa\./, '') : ''
      const selected = configKeys.filter((entry) => !wanted || entry.key === wanted || entry.key.startsWith(`${wanted}.`))

      if (selected.length === 0) {
        return `No config key "${key}". Top-level keys: ${[...new Set(configKeys.map((e) => e.key.split('.')[0]))].join(', ')}.`
      }

      return selected
        .map((entry) => `- \`pwa.${entry.key}\` = \`${entry.value}\`${entry.note ? ` — ${entry.note}` : ''}${entry.section ? `  _(${entry.section})_` : ''}`)
        .join('\n')
    },
  },
  {
    name: 'list_commands',
    description: 'Artisan commands the package registers (pwa:*), with their full signature and description, parsed from src/Console.',
    inputSchema: { type: 'object', properties: {} },
    handler() {
      return commands
        .map((command) => `### \`php artisan ${command.name}\`\n${command.description}\n\n\`\`\`\n${command.signature}\n\`\`\`\n_${command.file}_`)
        .join('\n\n')
    },
  },
  {
    name: 'describe_client',
    description:
      'The browser client: members of window.Pwa and the pwa:* DOM events it emits, parsed from ' +
      'resources/js/pwa.js, plus the client documentation page.',
    inputSchema: { type: 'object', properties: {} },
    handler() {
      const doc = documents.get('client')

      return [
        `## window.Pwa members (from ${path.relative(packageRoot, clientFile)})`,
        client.members.map((member) => `- \`${member}\``).join('\n'),
        '## Events emitted by the client (document, prefix "pwa:")',
        client.events.map((event) => `- \`pwa:${event}\``).join('\n'),
        'Service-worker messages are re-emitted with their own type: activated, offline, maintenance, logout, queue, replayed.',
        doc ? `---\n\n${doc.body}` : '',
      ].filter(Boolean).join('\n\n')
    },
  },
]

/* --------------------------------------------------------------- transport */

const toolsByName = new Map(tools.map((tool) => [tool.name, tool]))

function handle(request) {
  const { method, params = {} } = request

  if (method === 'initialize') {
    return {
      protocolVersion: typeof params.protocolVersion === 'string' ? params.protocolVersion : FALLBACK_PROTOCOL,
      capabilities: { tools: { listChanged: false } },
      serverInfo: SERVER,
      instructions:
        'Documentation and API surface for nyoncode/laravel-pwa (Progressive Web App for Laravel), read from ' +
        'the copy installed in this project. Prefer these tools over recalling the API: search_docs for a ' +
        'concept, describe_config for config keys and defaults, describe_api for PHP signatures, ' +
        'describe_client for window.Pwa and pwa:* events, list_commands for artisan commands, ' +
        'get_doc("agent-guide") for the whole thing in one read. The package does not implement push ' +
        'notifications; it only exposes sw.import for a push system.',
    }
  }

  if (method === 'ping') {
    return {}
  }

  if (method === 'tools/list') {
    return {
      tools: tools.map(({ name, description, inputSchema }) => ({ name, description, inputSchema })),
    }
  }

  if (method === 'tools/call') {
    const tool = toolsByName.get(params.name)

    if (!tool) {
      throw Object.assign(new Error(`Unknown tool: ${params.name}`), { code: -32602 })
    }

    try {
      return { content: [{ type: 'text', text: tool.handler(params.arguments ?? {}) }] }
    } catch (error) {
      // A tool that blows up reports through the result, not the protocol —
      // that is what lets the model see the message and try something else.
      return { content: [{ type: 'text', text: `Tool failed: ${error.message}` }], isError: true }
    }
  }

  throw Object.assign(new Error(`Unknown method: ${method}`), { code: -32601 })
}

function send(message) {
  process.stdout.write(`${JSON.stringify(message)}\n`)
}

function serve() {
  const input = readline.createInterface({ input: process.stdin })

  input.on('line', (line) => {
    const trimmed = line.trim()

    if (trimmed === '') {
      return
    }

    let request

    try {
      request = JSON.parse(trimmed)
    } catch {
      send({ jsonrpc: '2.0', id: null, error: { code: -32700, message: 'Parse error' } })

      return
    }

    // Notifications carry no id and take no reply.
    if (request.id === undefined || request.id === null) {
      return
    }

    try {
      send({ jsonrpc: '2.0', id: request.id, result: handle(request) })
    } catch (error) {
      send({ jsonrpc: '2.0', id: request.id, error: { code: error.code ?? -32603, message: error.message } })
    }
  })
}

function selfTest() {
  const owners = [...new Set(api.map((method) => method.owner))]
  const listed = publishedPages() ?? []

  console.log(`laravel-pwa MCP server ${SERVER.version}`)
  console.log(`  package root : ${packageRoot}`)
  console.log(`  documents    : ${documents.size} (${listed.length} in docs/nav.json + agent guide)`)
  console.log(`  api methods  : ${api.length} across ${owners.join(', ')}`)
  console.log(`  config keys  : ${configKeys.length}`)
  console.log(`  commands     : ${commands.map((command) => command.name).join(', ')}`)
  console.log(`  client       : ${client.members.length} members, ${client.events.length} events`)
  console.log(`  tools        : ${tools.map((tool) => tool.name).join(', ')}`)

  const probe = searchDocuments('offline queue', 1)
  console.log(`  search probe : ${probe.length ? `"${probe[0].section.heading}" in ${probe[0].document.slug}` : 'no hit'}`)

  const shortcut = api.find((method) => method.name === 'shortcut')
  console.log(`  shortcut     : ${shortcut ? shortcut.signature : 'NOT FOUND'}`)

  const strategy = configKeys.find((entry) => entry.key === 'display.strategy')
  console.log(`  config probe : ${strategy ? `display.strategy = ${strategy.value}` : 'NOT FOUND'}`)

  const failures = [
    documents.size !== listed.length + 1 && 'every page in docs/nav.json must exist',
    api.length === 0 && 'no API methods parsed',
    !shortcut && 'ManifestBuilder::shortcut not found',
    !strategy && 'config key display.strategy not found',
    !commands.some((command) => command.name === 'pwa:build') && 'pwa:build command not found',
    !client.members.includes('install') && 'window.Pwa.install not found',
    probe.length === 0 && 'search returned nothing',
  ].filter(Boolean)

  if (failures.length > 0) {
    console.error(`\n  ✗ Self-test failed:\n    - ${failures.join('\n    - ')}`)
    process.exit(1)
  }

  console.log('\n  ✓ Self-test passed.')
}

if (process.argv.includes('--self-test')) {
  selfTest()
} else {
  serve()
}
