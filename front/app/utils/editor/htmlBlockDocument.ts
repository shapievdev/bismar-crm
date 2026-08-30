import type { ThemePreference } from '~/composables/useTheme'
import { htmlBlockRuntime } from './htmlBlockRuntime'

/**
 * Design tokens handed to an embedded block, one set per theme.
 *
 * They are copied from main.css rather than read from it: the block is a
 * separate document in a sandbox of its own, so it cannot see a single rule of
 * the application's stylesheet. Keep the two in step — the values here are the
 * subset the rules below actually use.
 */
const TOKENS: Record<'light' | 'dark', string> = {
  light: `
    color-scheme: light;

    --color-surface-sunken: #dfe1e1;
    --color-border: #dcdede;

    --color-text: #0f1211;
    --color-text-muted: #6a706e;

    --color-accent: #111413;
  `,
  dark: `
    color-scheme: dark;

    --color-surface-sunken: #101312;
    --color-border: #262a29;

    --color-text: #eef0ef;
    --color-text-muted: #9aa19f;

    --color-accent: #d3f84b;
  `,
}

/**
 * Typography and colour for author-supplied markup.
 *
 * Mirrors the article rules in main.css (`.prose-rendered`), so a raw HTML
 * block reads as part of the lesson rather than as a browser default: the
 * platform font instead of Times New Roman, our near-black text, and links in
 * the text colour rather than the browser's blue.
 *
 * Every rule is deliberately unqualified — plain `h2`, plain `a` — so that
 * anything the author writes, whether inline or in their own `<style>`, wins on
 * document order. This is a floor, not a straitjacket.
 *
 * The background stays transparent: the frame behind it carries the card's
 * tone, and so the block follows the theme without knowing the colour.
 */
function styles(theme: ThemePreference): string {
  const tokens = theme === 'dark' ? TOKENS.dark : TOKENS.light

  return `
:root {${tokens}}
${theme === 'system' ? `@media (prefers-color-scheme: dark) { :root {${TOKENS.dark}} }` : ''}

/*
 * Absolute path on purpose: a srcdoc document has no address of its own, so a
 * relative URL would be resolved against the page around it. The font is
 * served with a cross-origin header for exactly this request — see the route
 * rules in nuxt.config.ts.
 */
@font-face {
  font-family: 'Involve';
  src: url('/fonts/involve-variable.woff2') format('woff2-variations'),
       url('/fonts/involve-variable.woff2') format('woff2');
  font-weight: 400 700;
  font-style: normal;
  font-display: swap;
}

@font-face {
  font-family: 'Involve';
  src: url('/fonts/involve-variable-italic.woff2') format('woff2-variations'),
       url('/fonts/involve-variable-italic.woff2') format('woff2');
  font-weight: 400 700;
  font-style: italic;
  font-display: swap;
}

*,
*::before,
*::after { box-sizing: border-box; }

html,
body { background: transparent; }

/*
 * The inset of the article surface itself, so a pasted fragment does not sit on
 * the frame's edge. A whole page brings its own body rules and keeps them.
 */
body {
  margin: 0;
  padding: 1.1rem 1.25rem;
  overflow-wrap: break-word;
  color: var(--color-text);
  font-family: 'Involve', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
  font-size: 15px;
  line-height: 1.55;
  -webkit-font-smoothing: antialiased;
}

h1, h2, h3, h4, h5, h6 {
  line-height: 1.15;
  letter-spacing: -0.02em;
  font-weight: 500;
}

h1 { margin: 0 0 0.8rem; font-size: 1.7rem; font-weight: 600; }
h2 { margin: 1.8rem 0 0.6rem; font-size: 1.4rem; font-weight: 650; }
h3 { margin: 1.5rem 0 0.5rem; font-size: 1.18rem; font-weight: 620; }
h4, h5, h6 { margin: 1.25rem 0 0.4rem; font-size: 1.02rem; font-weight: 600; }

p { margin: 0 0 0.9rem; }

/* As on the rest of the site: a link is the text colour, marked by its rule. */
a {
  color: inherit;
  text-decoration-thickness: 1px;
  text-underline-offset: 3px;
}

ul, ol { margin: 0 0 0.9rem; padding-left: 1.4rem; }
li { margin: 0.2rem 0; }

blockquote {
  margin: 1.25rem 0;
  padding: 0.2rem 0 0.2rem 1rem;
  border-left: 3px solid var(--color-accent);
  color: var(--color-text-muted);
  font-style: italic;
}

code {
  padding: 0.1rem 0.3rem;
  border-radius: 10px;
  background: var(--color-surface-sunken);
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 0.88em;
}

pre {
  margin: 1.1rem 0;
  padding: 0.9rem 1rem;
  border-radius: 16px;
  background: var(--color-surface-sunken);
  overflow-x: auto;
}

pre code { padding: 0; background: none; }

img { max-width: 100%; height: auto; border-radius: 16px; }

hr { margin: 1.75rem 0; border: 0; border-top: 1px solid var(--color-border); }

table { width: 100%; margin: 1.25rem 0; border-collapse: collapse; }

th, td { padding: 0.5rem 0.65rem; border: 1px solid var(--color-border); vertical-align: top; }

th { background: var(--color-surface-sunken); font-weight: 600; text-align: left; }
`
}

/** A doctype, if the author pasted a whole document rather than a fragment. */
const DOCTYPE = /^\s*<!doctype[^>]*>/i

/**
 * The document an embedded HTML block is handed: the author's markup, dressed
 * in the site's typography and carrying the runtime that measures it.
 *
 * The stylesheet goes in *after* a doctype when one is present. Anything before
 * it — a stray `<style>` included — drops the document into quirks mode, where
 * the author's own layout starts behaving differently than it did wherever they
 * built it.
 */
export function htmlBlockDocument(markup: string, options: { theme: ThemePreference, token: string }): string {
  const doctype = markup.match(DOCTYPE)?.[0] ?? ''
  const prelude = `<style>${styles(options.theme)}</style>`

  return doctype + prelude + markup.slice(doctype.length) + htmlBlockRuntime(options.token)
}
