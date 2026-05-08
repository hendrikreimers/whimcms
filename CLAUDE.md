# Working in this repo (for Claude)

This file is read automatically by Claude Code at the start of every
session. **Read it before any edit.** It encodes the rules a previous
session learned the hard way.

> **Sibling file `CLAUDE_CHAT.md` (project root) is not for Claude
> Code.** It's a self-contained prompt the human user pastes into
> Claude.ai web chat when generating a new theme without Claude Code.
> Do not auto-read it; do not maintain it as part of normal Claude
> Code edits unless the user explicitly asks. If you do touch it,
> remember it duplicates engine rules deliberately (web Claude
> can't read other files in this repo) — keep it in sync.

## What this project is

WhimCMS — server-rendered PHP CMS, intentionally minimal. PHP 8.1+,
no Composer, no database, no build step. The bundled install is a
**multi-theme showcase**: one core landing for the CMS itself plus
four demo themes that prove the same block library can power
radically different sites.

Full documentation: `_docs/INSTALL.md`, `ARCHITECTURE.md`,
`CONTENT.md`, `BLOCKS.md`, `TEMPLATING.md`, `SECURITY.md`.

## Hard rules

### Scope: where you may write

All "may write" zones below are inside the project root. With the
default `paths.theme = 'theme'`, `templates/`, `i18n/`, `styles/`,
`js/` live under `theme/`. With `paths.theme = '.'` they live at root
directly. Either layout is valid; the rules are the same.

| Path | You may | File types allowed |
|---|---|---|
| `<paths.theme>/templates/` | edit / add / delete | html only (with the WhimCMS template-engine syntax — never raw `<?php` tags) |
| `<paths.theme>/styles/` | edit / add / delete | css only |
| `<paths.theme>/js/` | edit / add / delete | js only (ES modules, no bundler) |
| `<paths.theme>/i18n/` (or `<paths.i18n>/`) | edit / add / delete | json only |
| `<paths.theme>/assets/` | edit / add / delete | svg, png, jpg, webp, woff2, ico — theme identity assets |
| `assets/` (root) | edit / add / delete | png, jpg, webp, gif — site-level raster content (image-server source) |
| `<paths.content>/` | edit / add / delete | md only |

### Scope: where you must ask each time first

| Path | Reason |
|---|---|
| `config/` | Runtime configuration. Most changes are reversible but the user wants visibility per change. **Always state what you'd change and why; wait for OK.** |
| `_docs/` | Public documentation. Treat as read-only unless the user explicitly grants editing rights for the session. |
| `README.md` | Public-facing pitch. Treat as read-only unless explicitly granted. |
| `.gitignore`, `CLAUDE.md` | Project root meta files. Ask before editing. |

### Scope: hands off entirely

| Path | Reason |
|---|---|
| `lib/` | The PHP engine. Audited, security-critical, **off-limits**. If a feature genuinely cannot be expressed in templates / content / config, surface it as a request — do not patch the engine. |
| `index.php` | Entry point. Same rule as `lib/`. |
| `.htaccess`, `_htaccess_production` | Apache configuration including security headers (CSP, HSTS, etc.). The user maintains these. |
| `var/` | Runtime state (cache, secret, rate-limit buckets, mail log). Never write. The PHP runtime owns this directory. |
| `_docs/audits/` | Internal hardening notes — gitignored, not for distribution. |

### Operations forbidden without explicit permission

- **Git operations** of any kind: `git init`, `git add`, `git commit`,
  `git push`, branch ops. The user runs git themselves, manually,
  outside any Claude session. Never propose, never execute.
- **Composer / npm install** — the project has zero external runtime
  dependencies and stays that way. Don't suggest libraries.
- **Adding admin UI / database / framework** — explicit non-goal.
  See `README.md → "Why this exists"`.
- **Writing executable code or scripts** — only one form of executable
  code is allowed by default: ES modules under `js/` (browser runtime,
  part of normal frontend work). Everything else needs **explicit
  per-case permission**:

  - **PHP** — never write `<?php` tags anywhere, including your
    "allowed" zones. PHP lives only in `lib/`, `index.php`, and
    `config/*.php` (where it is data only, no logic). All three are
    in the hands-off / ask-first zones above.
  - **Other languages** — Python, Bash / sh / batch / PowerShell,
    Perl, Ruby, compiled binaries (`.exe`, `.jar`, `.class`, `.wasm`,
    `.so`, …), bookmarklets, paste-able snippets — none without
    asking.
  - **Ad-hoc utility scripts** (the `.tmp_*.<ext>` convention) — also
    ask. Propose what the script does, which runtime it needs, where
    you'd save it. After it has run its purpose, delete it.

  **Permission is single-use, never session-wide.** A "yes, write
  that one Node script" does not authorise the next script. Ask
  again, every time.

## Architecture you need to know

### Three subsystems

```
config/      runtime configuration (split per concern, allowlisted)
content/     per-page composition in markdown (one .md per page per language)
theme/       the active visual identity — single swappable folder:
  templates/   HTML rendering (layout + per-theme nav/footer + per-block partials)
  i18n/        chrome microcopy (nav, footer, form labels, error messages)
  styles/      one base.css + one theme-<name>.css per bundled theme
  js/          ES modules, no bundler, no inline scripts
  assets/      theme identity assets (vector logos, favicons; raster optional)
assets/      site-level raster content (photos, gallery, hero stills) — root-level,
             always present, image-server source. Survives a theme swap.
```

The PHP engine in `lib/` reads `config/*.php`, parses
`<paths.content>/<lang>/<slug>.md`, loads `<paths.i18n>/<lang>.json`,
renders templates from `<paths.theme>/templates/`. **All paths are
configurable via `config/app.php → paths`.** Defaults match the
bundled showcase (`paths.theme = 'theme'`); set `paths.theme = '.'`
to collapse everything back to root for BC. You do not touch `lib/`.

### Theming

A theme is exactly four things, all under `<paths.theme>/`:

1. **One layout file** — `<paths.theme>/templates/layout-<name>.html`
   (HTML shell, sets `<body class="theme-<name>">`, links its theme
   CSS via `{{ BASE }}{{ THEME_URL }}/styles/theme-<name>.css`,
   loads its Google Fonts).
2. **One stylesheet** — `<paths.theme>/styles/theme-<name>.css`.
   **Every rule must be scoped under `body.theme-<name>` or
   `.theme-<name>`** so themes never leak into each other. No bare
   element selectors at the top level.
3. **Theme-specific partials** — `<paths.theme>/templates/partials/nav-<name>.html`
   and `footer-<name>.html`, included by the layout.
4. **At least one content file** — `<paths.content>/en/<slug>.md`
   (or `de/`) with `layout: <name>` in the front-matter.

Plus: the layout name must be in `config/content.php → allowed_layouts`
(ASK before editing). The default layout is `<paths.theme>/templates/layout.html`
itself (= the core theme); selecting `default` in front-matter resolves
there.

URL conventions inside layout/partial HTML:
- Theme-served assets (CSS, JS, theme images, favicon):
  `{{ BASE }}{{ THEME_URL }}/styles/X.css` etc. `THEME_URL` is `''`
  when paths.theme = '.', `'/theme'` when paths.theme = 'theme'.
- Image-server URLs (responsive raster) are emitted by the `{% image %}`
  template directive in each block partial. The directive resolves the
  source path against the configured asset roots, generates a focus-
  aware crop on first render, caches it on disk, and returns a URL of
  the shape `/img-c/<basename>-<hash>.<ext>`. The endpoint is read-only
  — only `{% image %}` ever writes to the cache.

The **block partials** under `templates/partials/blocks/` are
**theme-agnostic by default** — same HTML, restyled by each theme's
CSS via descendant selectors under `body.theme-<name>`. Do not fork
a partial just for visual differences (colour, spacing, typography
— those are CSS).

**Theme-specific variants are allowed when the layout geometry
fundamentally differs** — most commonly when a theme's CSS sets a
different `aspect-ratio` on the image container, so the
`{% image %}` directive needs different crop dimensions to avoid
shipping bytes the layout will discard via `object-fit: cover`.
Examples in this repo:

- `hero.html` (cinematic 1920×700 for the core landing)
- `hero-personal.html` (4:5 portrait, used by `::: hero-personal`
  in `demo-personal.md`)
- `hero-business.html` (4:3 landscape)
- `hero-overlay.html` (16:9 full-bleed, shared by trainer + dev)
- `programs.html` (4:3, default)
- `programs-personal.html` (3:4 portrait)

Each variant is just another block-type — same `{@ block @}` schema
mechanism, the filename = block-type convention, content `.md`
opts in via `::: <variant-name>`. The author of the content picks
the variant for their layout; the theme dev is responsible for
matching the variant's hardcoded crop dimensions to the CSS
geometry. `focusX`/`focusY` stay author-controllable so the editor
can reframe per-image without changing the variant.

### Theme-bound configuration

A complete theme is more than its visual files. Themes that diverge
from the bundled defaults need matching configs. Configs split into
three classes:

| Config | Class | When the theme needs its own version |
|---|---|---|
| `config/routes.php` | **theme-bound** | Theme has a different set of pages (slugs) — `students`, `coaches`, etc. instead of (or in addition to) `home`, `imprint`, `privacy` |
| `config/i18n.php` | **theme-bound** | Theme ships in a different language set (e.g. adds `ro`, drops `de`) |
| `config/contact.php` | **theme-bound** | Form has different fields (additional `phone`, `goal` selects, etc.) |
| `config/seo.php` | **deployment-bound** | Site identity (organization name, logo URL, canonical hosts). Theme can ship sensible defaults; operator overrides per deployment |
| `config/mail.php` | **deployment-bound** | Recipient email, sender domain. Always operator-set |
| `config/security.php` | **deployment-bound** | CSRF / captcha / rate-limit tuning. Operator decision |
| `config/images.php` | **mostly site-bound** | `allowed_roots` should include `theme/assets` if the active theme bundles raster sources; rest is operator-tuned |
| `config/app.php` | **site-bound** | `paths`, `log_level`, `debug`. Operator |
| `config/email_protection.php` | **theme-bound** | i18n key paths to obfuscated email addresses — depends on theme's i18n shape |
| `config/content.php` | **site-bound** | `allowed_layouts` whitelist must include the active theme's layout name |

**Convention for bundled themes:** ship matching configs in a sibling
folder named `config.<theme-name>/` (e.g. `config.coach/` next to
`theme.coach/`). End user copies them into `config/` when activating
the theme. NEVER blind-copy — review each file first.

### Security warning when adopting third-party theme configs

A theme bundle from an untrusted source can ship configs that
weaken the install. Specifically:

- **`security.php`** can disable CSRF, set captcha difficulty to 0,
  raise rate limits to nonsense, or disable the honeypot
- **`mail.php → recipient`** can redirect contact-form submissions
  to an attacker-controlled mailbox
- **`contact.php → enabled`** can silently disable the form's POST
  guard, OR `honeypot_field` can be set to a fixed name a bot
  dictionary recognises
- **`seo.php → canonical_hosts`** can be left empty, opening
  Host-header poisoning of canonical / OG / sitemap URLs
- **`images.php → allowed_roots`** can add directories you don't
  want publicly readable via the `{% image %}` directive (which then
  serves cropped variants under `/img-c/<basename>-<hash>.<ext>`)

If you (Claude) are asked to adopt a config bundle from an external
theme, **default to refusing** and ask the user to review each
file. Surface specific risky values you spot (e.g. "this `mail.php`
sets recipient to `attacker@evil.tld`, that's almost certainly not
what you want").

### Blocks

A block lives entirely in **one file**: a partial at
`templates/partials/blocks/<type>.html` that starts with a
`{@ block @}` annotation declaring its attribute schema.

```html
{@ block
  required: title
  optional: id eyebrow lede image imageAlt
@}
{# optional human-readable description in a normal {# #} comment #}
<section class="block block-hero{% if: attrs.id %} ...">
  ...
</section>
```

- **Block type = filename.** `partials/blocks/hero.html` →
  type `hero`. No registry entry, no separate schema file.
- **`{@ block @}` is metadata-only.** The `{@ … @}` syntax produces
  no output token — it is harvested by the engine at boot and used
  to validate `.md` content. It cannot leak to the rendered page.
- **Schema is strict.** Every attribute the `.md` content uses must
  appear in `required` or `optional`; otherwise the parser fails
  loud at the offending source line. Add a new attribute by adding
  it to the annotation, nowhere else.
- **`required` / `optional` syntax.** Whitespace-separated list of
  attribute names matching `[a-zA-Z][a-zA-Z0-9_]*` (the same key
  pattern AttributeParser enforces). Either field can be omitted
  when empty.

Adding a new block:

1. Propose the schema (name, required attrs, optional attrs) and
   the markup → wait for approval.
2. Once approved, create the partial with its `{@ block @}` header.
   That's the entire change — no config edits anywhere.

Existing blocks are catalogued in `_docs/BLOCKS.md` — read that
before inventing a new one; there's probably already a fit.

### Content syntax (`AttributeParser` is strict)

- Indentation: **exactly two spaces** per level. **Tabs forbidden.**
- Keys: `^[a-zA-Z][a-zA-Z0-9_]{0,63}$`. **No dots, no dashes, no quotes.**
- Values: trimmed remainder of the line. **No quoting** — `value: "foo"`
  literally stores `"foo"` with the quotes. Just write `value: foo`.
- **No multi-line scalars** (no `|`, no `>`). For multi-line content
  use the block body (after a `---` separator inside the `:::`).
- Lists: `- ` at column 2. All items in a list must be all-scalar OR
  all-map; mixing is a parse error.
- Maps inside lists: continuation lines at column 4. Max one level
  of nesting.
- A `key:` with no same-line value and no indented continuation is
  treated as the empty string.

### Pitfalls (caught the hard way — read once)

These are the constraints that look like they should work but do
not. Each one cost a debug round in a previous session.

- **No nested lists inside list-item maps** (depth-1 cap). The parser
  accepts `items: [{key: value, key: value}]` but rejects
  `items: [{key: value, sublist: [a, b, c]}]`. Workaround: flatten
  to numbered keys (`feat1, feat2, feat3, …`) and let the partial
  render only the slots that are present. See `pricing.html` for
  the established pattern.
- **No string concatenation or arithmetic in expressions.** The
  expression sub-language has `==`, `!=`, `&&`, `||`, `!` and
  literal/path lookups — that's it. `loop.index + 1` does not work;
  if you need numbered output, generate it via CSS `counter()` on an
  `<ol>` (see `block-steps` for the pattern). Dynamic include paths
  (`'partials/icons/' . attrs.name`) also do not work — use a single
  switch partial that branches on the name (see
  `partials/icons/glyph.html`).
- **`:::` on its own line ends the outer block** regardless of where
  it appears, including inside a fenced code body. If you need to
  show `:::` literally inside a `code-snippet` block body, indent
  the line by one or more spaces — the parser only matches the
  exact unindented `:::`.
- **Image paths take no path marker.** `image: ~/assets/foo.jpg` is
  wrong (the `~/` would resolve once and then the `{% image %}`
  directive would try to validate `/<lang>/assets/foo.jpg` against
  the allowed asset roots, fail containment, and return an empty
  URL). Image-path attributes are always raw base-relative:
  `image: /assets/foo.jpg`. Path markers `~/x` and `^/x` are only
  for internal page links (cta hrefs, Markdown link hrefs).
- **Slug names with dashes are fine in routes and i18n keys**
  (`demo-business`), but **attribute keys may not contain dashes**
  per the parser's KEY_PATTERN. So `cta-label` is invalid as an
  attribute key — use `ctaLabel` (camelCase is the convention).

### Path markers in attribute values and Markdown link hrefs

| Marker | Resolves to |
|---|---|
| `~/x` | `/<lang>/x` (multi-lang) or `/x` (single-lang) — internal page links |
| `^/x` | `/<base>/x` — base-relative direct asset references |

Markers must be at position 0 of the value. **Image paths
(`image:`, `bgImage:`, gallery `path:`) take a base-relative path
WITHOUT a marker** — block partials pass the raw path to the
`{% image %}` directive, which resolves it against the configured
asset roots and emits the cache URL (`/img-c/<basename>-<hash>.<ext>`).

### Template engine syntax

```
{{ expr }}             variable, HTML-escaped
{!! expr !!}           raw output, only literal <em>/</em> survive
{% include: 'path' %}  render another template
{% include: 'path', attrs: <expr> %}     render with rebound attrs scope
{% if: cond %}…{% endif %}               conditional (no else; use second negated if)
{% for: list, as: 'item' %}…{% endfor %} loop (`as:` is mandatory)
{% for: list, as: 'item', include: 'path' %}  inline-include loop (no body)
{% blocks %}           render the page's block stream
{% html: body %}       verbatim — restricted to Markdown-rendered bodies only
{# comment #}          stripped from output
{@ name … @}           compile-time annotation — pure metadata, no output
```

Expression operators: `==`, `!=`, `&&`, `||`, `!`. **No string
concatenation, no arithmetic.** Inside `{% if %}`, paths are bare
identifiers (`PAGE == 'home'`), not `%PAGE%`. Loop bindings expose
`loop.index`, `loop.first`, `loop.last`.

**Two distinct context slots:**

- `CURRENT_LANG.x` — the global language dictionary loaded from
  `<paths.i18n>/<lang>.json`. Always available, never overwritten.
  Use for translation strings: `{{ CURRENT_LANG.contact.title }}`.
- `attrs.x` — the local sub-scope. Bound by `{% blocks %}` (block
  attrs from `.md`), by `{% for %}` inline-includes (per-iteration
  item), and by `{% include 'x', attrs: <expr> %}`. Use for
  partial-local data: `{{ attrs.title }}`.

A block partial can read both at the same time — `attrs.title` for
its own data, `CURRENT_LANG.x` for shared chrome strings.

`{% html: %}` is audit-restricted. Only legitimate sources today:
`Markdown::render()` output passed in via the block `body` slot. Do
not `{% html: %}` arbitrary i18n strings or attribute values.

`{@ name … @}` annotations are extracted by the engine at boot and
dispatched to `AnnotationConsumer` directives. The first user is
`BlocksDirective` (consuming `{@ block @}` from each block partial
to register its schema). The annotation never produces an output
token — it's structurally impossible for its content to leak.

### Markdown safe subset

Used inside block bodies (after the `---` separator inside `:::`).
Supported: paragraphs, `## h2` / `### h3` / `#### h4`, unordered
lists (`- item`), inline `**bold**`, `*em*`, `` `code` ``,
`[text](href)` with allowlisted schemes (`https`, `mailto`, `tel`,
`/...`, `#...`, `~/...`, `^/...`), and fenced code blocks (` ``` `
optional language tag). Not supported: inline HTML, ordered lists,
blockquotes, tables, images via `![]()`, autolinks.

## Code-quality conventions

- **No inline JS or CSS.** All scripts go through `js/main.js` (ES
  module). All styles go through `styles/`. The CSP forbids inline
  in production — an inline handler will simply not execute.
- **No bare `<svg>` icons in block partials** — reuse
  `templates/partials/icons/glyph.html` (a switch over named icons).
  Add new icons there, not inline elsewhere.
- **Use `{% image: <path>, … %}` for any raster image** so it goes
  through the server-side cropping/resizing cache. Direct
  `<img src="/assets/...">` ships full-resolution bytes to every
  visitor and skips the security checks (`AssetPathResolver` +
  decompression-bomb caps). For responsive sources, emit several
  `{% image %}` calls into an `<img srcset="… 480w, … 768w, …">`.
- **`reveal` class** triggers a fade-in animation (via `js/reveal.js`)
  with a `prefers-reduced-motion` opt-out built into `base.css`. Add
  it to top-level block sections where appropriate; never required.
- **Theme CSS scoping is mandatory.** Every selector in a
  `theme-<name>.css` must start with `.theme-<name>` (typically
  `body.theme-<name>` plus a descendant selector). One leaked
  unscoped rule will style every other theme too.

## Established JS / template hooks

These class names and `data-*` attributes are the contract between
the templates and the JS modules. Renaming one side requires
renaming the other — and that's how an earlier session shipped a
silent regression where every `.reveal` element stayed invisible
because JS added a different class than CSS expected.

| Hook | Owned by JS | Touched in templates / CSS |
|---|---|---|
| `.reveal` initial state, `.reveal.in` revealed | `js/reveal.js` adds `.in` on intersect (and after a 1.5 s safety timeout) | `styles/base.css` provides the transition |
| `.scrolled` on the nav element | `js/nav.js` toggles based on scroll-Y | each `theme-*.css` may style `.nav-<name>.scrolled` |
| `[data-nav]`, `[data-nav-toggle]`, `[data-nav-primary]`, `[data-nav-open]` | `js/nav.js` reads these to wire the mobile drawer toggle | every `nav-<theme>.html` partial sets them |
| `[data-contact-form]`, `[data-form-global-region]`, `[data-contact-sent]` | `js/contact-form.js` (fetch-mode submit, error injection, success focus) | `templates/partials/contact/form.html` |
| `[data-captcha-salt]`, `[data-captcha-difficulty]`, `[data-captcha-enabled]`, `[data-captcha-nonce]` | `js/captcha.js` (proof-of-work computation) | `templates/partials/contact/form.html` |
| `[data-lightbox]`, `[data-lightbox-group]`, `[data-caption]`, `[data-full]` | `js/lightbox.js` | `templates/partials/blocks/gallery.html` |
| `.js-email` + `data-u`, `data-d` | `js/email.js` rehydrates the obfuscated address into a working `mailto:` | `templates/partials/blocks/contact.html` |
| `.skip-link` + `<main id="main">` | none — pure HTML/CSS a11y pattern | every layout file |

When you add a new JS module under `js/`: pick a `data-*` namespace
or class prefix, document it in this table, and reuse the established
hooks above when the behaviour overlaps.

## Accessibility minimum bar

These are the floor, not the ceiling. Every theme and every block
partial meets all of them. New work must too.

- **Semantic HTML.** `<header>`, `<nav>`, `<main>`, `<footer>`,
  `<section>`, `<article>`, `<figure>`, `<blockquote>` where they
  fit. `<div>` only when no semantic element matches.
- **One `<h1>` per page** (the hero title). Then `<h2>` for sections,
  `<h3>` for sub-headings. Don't skip levels.
- **`aria-label` on icon-only interactive elements** (the hamburger
  toggle, the lightbox close button, the language buttons in the
  switcher when displayed as just `EN · DE`).
- **`aria-current="page"`** on the active nav link, `aria-current="true"`
  on the active language in the switcher.
- **Focus-visible styles** are wired in `base.css` via the
  `:focus-visible` pseudo. Do not remove or override the outline
  for the sake of "looking cleaner" — themes may *restyle* the
  outline (color, offset, radius) but must keep it visible.
- **Skip-link** + `<main id="main">` in every layout — never strip
  these.
- **Contrast.** Body text against background must meet WCAG AA
  (4.5:1). Large text (≥18 pt or ≥14 pt bold) must meet 3:1.
  Each theme's palette in this repo has been chosen to clear that
  bar; if you tweak colours, re-check.
- **`prefers-reduced-motion`** is respected — `base.css` shortens
  every animation/transition to 1 ms when the user opts out, and
  the reveal-on-scroll opacity transition is wrapped in
  `@media (prefers-reduced-motion: no-preference)`. Don't add
  motion that ignores this.
- **Image alt text.** Required for content images, empty (`alt=""`)
  for purely decorative ones. Hero background images with `aria-hidden="true"`
  on the wrapper count as decorative.
- **Form fields** have explicit `<label for="…">`. Error messages
  are wired into a live region (the contact form already does this
  via `role="alert" aria-live="polite"`).
- **Keyboard reachability.** Every interactive element is reachable
  by Tab and operable by Enter / Space. Don't add `tabindex="-1"`
  except on the honeypot field (already done) and on success-focus
  targets that the JS will programmatically focus.

## When in doubt

Ask. The user prefers a one-line clarifying question over a wrong
implicit assumption. Especially:

- Before any change to `config/`, `_docs/`, `lib/`, `.htaccess`,
  `_htaccess_production`, `index.php`, `README.md`.
- Before adding a new block type, layout, language, or theme.
- Before deleting any file you didn't create in the current session.
- Before touching anything in `var/`.

When proposing a config change, name the file, the field, the old
value, the new value, and why.

## Identity rules (still apply)

- **Identity-by-config.** Site-specific strings (operator name, real
  email, real domain) live ONLY in `config/`, `content/`, `i18n/`,
  `templates/`, `styles/`, `assets/`. Code under `lib/`, `index.php`,
  `_docs/` (excluding `audits/`), `README.md`, `.htaccess`,
  `_htaccess_production` is generic.
- **Identity-obfuscation.** Server-internal surfaces use "WhimCMS"
  (log prefix `[WhimCMS]`, `__whimcms_text_mode__` constant).
  Attacker-visible surfaces keep "H42" (`X-Mailer: H42-Site`,
  `X-H42-Error` header, `=_h42_` MIME boundary) — light obfuscation
  so a public WhimCMS codebase doesn't fingerprint trivially. Don't
  unify these without a security review.

## Verification & local tooling

**You cannot run PHP from your shell** — there is no PHP runtime,
no `php -l`, no `php -S`. Code-correctness verification has to happen
via the user's browser. Keep each refactor step small enough that
the user can spot-check by reloading a page — don't stage 500-line
edits and declare them done.

For ad-hoc scripts (text transforms, file traversal, regex rewrites,
JSON validation), the rule under *Operations forbidden* applies: ask
**every time**, before writing the script. Two questions for the
user, in one message:

> 1. "What runtime do you have available locally — Node, Python,
>    Perl, something else? I'll tailor the script to that."
> 2. "Here's what the script would do: <one-line description>.
>    OK to write it once and run it?"

Common runtimes the user may have: Node (`node`), Python
(`python` / `python3`), Perl, Ruby, Bash / Git Bash. They might
also have Docker, in which case PHP can be run via container.

Once permission is granted for that one script:
- Save as `.tmp_*.<ext>` in the project root (the convention)
- Tell the user how to run it (one-liner)
- **Delete it after the result is in.** Don't leave scratch files
  lying around; the next script needs its own ask.

## Working rhythm with the user

The user prefers a **propose → approve → execute** cycle. Especially
for anything outside your default-allowed zones. Concretely:

- **Before non-trivial edits**: state what you'd change, where, and
  why. Wait for OK.
- **Before deleting files** you didn't create in this session: list
  them and ask.
- **Before adding something the user didn't ask for** (a new
  abstraction, a "nice-to-have" feature, an extra file): ask.
- **One-line clarifying questions** are welcome and preferred over
  wrong implicit assumptions.

After an edit batch, give a short report: what was created /
modified / deleted, with paths. The user reviews and gives the next
direction.

