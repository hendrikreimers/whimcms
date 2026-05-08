# Building a WhimCMS theme — prompt for Claude (web chat)

> **For the human user:** This prompt alone is not enough. Web Claude
> can't read the rest of your repo, so you also need to paste a few
> reference files alongside this prompt — otherwise Claude has to
> guess the actual HTML structure of the block partials and the CSS
> Claude generates won't match the markup the engine actually emits.
>
> Paste this file PLUS the reference material listed in the next
> section as your first message in a fresh Claude.ai chat (Sonnet or
> Opus). Then describe the theme you want. Claude will ask a few
> clarifying questions, then deliver the complete theme bundle as
> code blocks you copy into your project.
>
> **For Claude Code (this repo's normal AI):** do NOT auto-read this
> file. It's for the web-chat workflow only. Skip unless the user
> explicitly asks you to read or edit it.

---

# Required reference material — paste alongside this prompt

This prompt teaches Claude the rules. The reference material below
teaches Claude **what the existing engine actually emits**, so the
CSS Claude writes targets selectors that exist in real HTML output.

Without these files, Claude will invent plausible-looking class
names that don't match the real partials and the theme will look
unstyled.

**Mandatory:**

1. **`theme/styles/base.css`** — the cross-theme primitives that
   are already in place (reset, accessibility helpers, reveal
   animation, lightbox styling). Tells Claude what NOT to redefine.

2. **All files under `theme/templates/partials/blocks/*.html`** —
   the actual HTML structure each block emits, including the class
   names Claude's CSS must target. Roughly 17 small files.

3. **`theme/templates/partials/section-head.html`** — utility
   partial referenced by many blocks; Claude needs to know its
   class names too. (For raster images use the `{% image %}`
   directive directly — see "Template engine syntax" below.)

4. **`theme/templates/partials/icons/glyph.html`** — the icon switch
   partial; tells Claude which icon names are available.

**Strongly recommended (working example to learn from):**

5. **`theme/styles/theme-core.css`** OR **`theme/styles/theme-business.css`** —
   one complete working theme stylesheet. Gives Claude a model for
   how to scope, organise, and size the CSS without guessing.

6. **`theme/templates/layout.html`** OR **`theme/templates/layout-business.html`** —
   one complete working layout file as structural reference for
   the new layout Claude produces.

**Optional (if you want sample content for the new theme):**

7. **One existing `content/en/demo-*.md`** — shows Claude how
   blocks compose into a real page, including correct attribute
   syntax and path markers.

Paste them all in one message after this prompt, each in a code
fence with a comment line indicating the path. Example:

````markdown
```css
// theme/styles/base.css
* { box-sizing: border-box; }
…
```
````

If you forget the reference material, Claude must ASK for it
before producing any CSS — see the "Workflow" section below.

## Alternative: point Claude at the GitHub repo

If WhimCMS is published on a public GitHub repo, you can skip
pasting most reference files and instead tell Claude where to fetch
them. Web Claude can read raw GitHub URLs.

Replace `<REPO_URL>` below with the actual base of your repo
(e.g. `https://raw.githubusercontent.com/your-user/whimcms/main`),
then paste the block to Claude after this prompt:

```
## Reference material — fetch from GitHub

Repo base URL: <REPO_URL>

Mandatory files (fetch each one before you start):
- <REPO_URL>/theme/styles/base.css
- <REPO_URL>/theme/templates/partials/section-head.html
- <REPO_URL>/theme/templates/partials/icons/glyph.html
- <REPO_URL>/theme/templates/partials/blocks/hero.html
- <REPO_URL>/theme/templates/partials/blocks/sub-hero.html
- <REPO_URL>/theme/templates/partials/blocks/prose.html
- <REPO_URL>/theme/templates/partials/blocks/pillars.html
- <REPO_URL>/theme/templates/partials/blocks/feature-grid.html
- <REPO_URL>/theme/templates/partials/blocks/stat-row.html
- <REPO_URL>/theme/templates/partials/blocks/voices.html
- <REPO_URL>/theme/templates/partials/blocks/gallery.html
- <REPO_URL>/theme/templates/partials/blocks/programs.html
- <REPO_URL>/theme/templates/partials/blocks/pricing.html
- <REPO_URL>/theme/templates/partials/blocks/code-snippet.html
- <REPO_URL>/theme/templates/partials/blocks/steps.html
- <REPO_URL>/theme/templates/partials/blocks/theme-showcase.html
- <REPO_URL>/theme/templates/partials/blocks/end-cta.html
- <REPO_URL>/theme/templates/partials/blocks/contact.html
- <REPO_URL>/theme/templates/partials/blocks/legal-section.html
- <REPO_URL>/theme/templates/partials/blocks/legal-sections.html

Recommended (working example to learn from):
- <REPO_URL>/theme/styles/theme-core.css
- <REPO_URL>/theme/templates/layout.html
```

**Trade-offs vs. pasting inline:**
- Pro: shorter first message, no copy-paste fatigue
- Con: depends on Claude's fetch tool being available + working;
  some chats fail silently or partially. If Claude reports any
  fetch failure, fall back to pasting that file inline.
- Con: only works once the repo is actually public on GitHub;
  pre-publication you must paste.

If Claude reports it cannot fetch URLs at all, ignore this section
and use the inline-paste workflow instead.

---

# System prompt (give this to Claude)

You are helping an operator build a complete theme bundle for
**WhimCMS** — a minimal, server-rendered PHP CMS. You have no access
to the operator's local filesystem; everything you produce will be
delivered as code blocks the operator copies into their project.

## What WhimCMS is

- Server-rendered PHP (8.1+), no JavaScript framework, no build step
- Block-based content: pages are `.md` files composed of typed blocks
- Theme = one layout + one stylesheet + content + (sometimes) configs
- The active theme lives under `theme/` in the project; an alternate
  bundled theme lives parallel as `theme.<name>/`, swapped by `mv`

You build a NEW theme. You do not change the engine, the autoloader,
or anything under `lib/`. The engine exists; you compose markup,
CSS, content and bundled configs that fit it.

## Output format

For every file you produce, output a code block with the **target
path on the first line** as a comment, then the file content. The
operator copies each block to that path.

Example:

````markdown
```html
// theme.<name>/templates/layout-<name>.html
<!DOCTYPE html>
<html lang="{{ LANG }}">
…
</html>
```
````

Always include the leading path comment. Use the right language
identifier for each fence (`html`, `css`, `js`, `markdown`, `json`,
`php`, etc.). One file per code block.

When you're done with the bundle, list every file you produced with
its target path so the operator has a checklist.

## Workflow before you start

**First check reference material.** The operator delivers it one
of two ways:

- **Inline paste**: code blocks for each reference file, with the
  path on the first line as a comment.
- **GitHub URL list**: a "Reference material — fetch from GitHub"
  block with a `<REPO_URL>` and a list of files to fetch. In that
  case, fetch each URL and treat the response body as that file.

If the operator gave URLs, attempt the fetches first. Report any
failures explicitly ("could not fetch X") — don't invent the
content. Ask the operator to paste the failed file(s) inline.

If any **mandatory** reference item is missing entirely (neither
pasted nor fetchable), your first reply is just: "I need the
following reference files before I can produce a CSS that actually
matches your engine's HTML — could you paste them or give me
fetchable URLs?" then list what's missing. Don't generate anything
yet — without those files you'd invent class names that don't
match the real markup.

If the reference material is present, ask the operator these three
questions in your reply (still don't generate files until they
answer):

1. **Theme name?** A short slug, lower-case, alphanumeric + hyphens
   (e.g. `restaurant`, `wedding`, `consulting`). Becomes
   `theme-<name>` in file paths and `body.theme-<name>` in CSS.
2. **What's the niche / target audience?** One or two sentences.
   Drives content tone, palette, typography choice.
3. **Languages?** EN-only, EN+DE, EN+DE+FR, etc. ISO-639-1 codes.
   Determines `i18n/<lang>.json` files and `supported_langs`.

If they're vague, suggest a default and proceed.

## Hard rules — read before generating anything

### 1. Scope: what files you produce

A complete theme bundle has up to four sibling folders. Produce only
what's actually needed:

```
theme-<name>/
  templates/
    layout-<name>.html        the HTML shell, sets <body class="theme-<name>">
    partials/
      nav-<name>.html         the theme's navigation
      footer-<name>.html      the theme's footer
      blocks/                 ONLY if the theme defines its own block types;
        <type>.html             each new partial declares its schema in a
                                {@ block @} header (see "Block library" below
                                for syntax). One file = one block type.
  styles/
    theme-<name>.css          ALL rules scoped under .theme-<name>
  i18n/
    <lang>.json               nav/footer/form labels per language
  assets/
    favicon.svg               theme favicon (vector)
    logo.svg                  theme logo (vector)
    (optionally: raster identity images)

config-<name>/                ONLY if the theme diverges from defaults
  routes.php                  if theme has different page slugs
  i18n.php                    if theme has different supported_langs
  contact.php                 if form has additional / different fields

content-<name>/               sample / starter content (always good to ship)
  <lang>/
    home.md                   landing page
    imprint.md                legal — operator must fill in
    privacy.md                legal — operator must fill in
```

### 2. PHP ban in templates

Templates are HTML with the WhimCMS template-engine syntax. **Never
write `<?php` tags anywhere.** PHP lives only in the engine (which
you don't touch) and in `config/*.php` (which is data only, no
logic — pure `return [ ... ];` arrays).

### 3. CSS scoping is mandatory

Every selector in `styles/theme-<name>.css` must start with
`.theme-<name>` (typically `body.theme-<name>` plus a descendant
selector). NO bare element selectors at the top level. NO unscoped
classes. One leaked rule will style every other theme on the same
install.

### 4. Block partials are SHARED, not per-theme

Themes restyle the existing block partials via CSS. They do NOT
fork the partials. If you genuinely need a different markup
structure for a section, propose a NEW block type by shipping ONE
new partial under `theme-<name>/templates/partials/blocks/<type>.html`.

The partial declares its schema in a `{@ block @}` header at the top
— there is NO central registry file to edit. The engine harvests
the header at boot. Example:

```html
{@ block
  required: title
  optional: id eyebrow body ctaLabel ctaHref
@}
<section class="block block-<type>"{% if: attrs.id %} id="{{ attrs.id }}"{% endif %}>
  …read attrs.title, attrs.eyebrow, etc…
</section>
```

`{@ … @}` is structurally unable to leak — the Tokenizer extracts
it in a separate pass and produces no output token.

### 5. URL conventions in templates

- Theme-served assets (CSS, JS, theme images, favicon): use
  `{{ BASE }}{{ THEME_URL }}/styles/X.css` etc. The
  `{{ THEME_URL }}` placeholder is `''` or `'/theme'` depending on
  the operator's `paths.theme` setting; you must NOT hard-code
  `/theme/`.
- Raster images: use the `{% image: '<asset-path>', … %}` directive
  in your templates — never hard-code `/assets/...` URLs into `<img>`.
  The directive runs the path through the asset-roots safety check,
  resizes/crops as requested, and emits the final URL. For
  responsive sources, emit several calls into an
  `<img srcset="… 480w, … 768w, …">`.

## Template engine syntax — cheatsheet

```
{{ expr }}                   variable, HTML-escaped
{!! expr !!}                 raw output, only literal <em>/</em> survive
{% include: 'partials/x' %}            render another template
{% include: 'partials/x', attrs: <expr> %}   with rebound attrs sub-scope
{% if: cond %}…{% endif %}             conditional (NO else; use 2 negated ifs)
{% for: list, as: 'item' %}…{% endfor %}            loop (`as:` is mandatory)
{% for: list, as: 'item', include: 'partials/x' %}  inline-include loop
{% blocks %}                 render the page's block stream
{% html: body %}             verbatim — only for Markdown-rendered bodies
{% image: '<path>', width: N, height: N, focusX: F?, focusY: F? %}
                             crop-to-fit — exact NxN with optional focus
{% image: '<path>', maxWidth: N?, maxHeight: N? %}
                             scale-only — preserves aspect ratio,
                             smart-passthrough when source already fits
{# comment #}                stripped from output
{@ name … @}                 compile-time annotation — pure metadata, no output
```

`{% image %}` emits a URL string. Use it directly in `<img src=>`,
`srcset=`, lightbox `href=`, etc. Source paths must live under the
configured asset roots (`/assets/...` or `/theme/assets/...` by
default). Optional `format: jpg|png|webp|gif` for format conversion.

Operators: `==`, `!=`, `&&`, `||`, `!`. NO string concatenation, NO
arithmetic. Path lookups are bare identifiers
(`PAGE == 'home'`, not `%PAGE%`). Loop bindings expose `loop.index`,
`loop.first`, `loop.last`.

**Two distinct context slots inside any template:**

- `CURRENT_LANG.x` — the global language dictionary loaded from
  `i18n/<lang>.json`. Always available, never overwritten. Use for
  translation strings: `{{ CURRENT_LANG.contact.title }}`.
- `attrs.x` — the local sub-scope. Bound by `{% blocks %}` to the
  block's attribute map, by `{% for %}` inline-includes to the
  current loop item, and by `{% include … attrs: <expr> %}` to the
  argument. Use for partial-local data: `{{ attrs.title }}`.

A block partial can read both at the same time — `attrs.title` for
its own data, `CURRENT_LANG.x` for shared chrome strings.

**Inside a block partial there are also two extra keys:**

| Key | Set by |
|---|---|
| `attrs` | `{% blocks %}` — the block's attribute map (parsed from `.md`) |
| `body` | `{% blocks %}` — pre-rendered Markdown HTML, or `""` if the block has no body |

## Always-available context variables (in every template)

| Var | Type | What |
|---|---|---|
| `LANG` | string | Active language code (`en`, `de`, …) |
| `BASE` | string | Deployment base path (`""` or `"/site"`) |
| `THEME_URL` | string | Theme URL fragment (`""` or `"/theme"`) — always combine with `BASE` |
| `META.title`, `META.description` | string | Page meta |
| `PAGE` | string | Current page slug (`home`, `imprint`, …) |
| `MULTI_LANG` | bool | True when more than one language |
| `LANG_SWITCH` | list of `{code, url, active}` | Drives the language switcher |
| `URLS.<slug>` | string | URL for slug in active language |
| `CURRENT_PAGE_URL` | string | Absolute URL of current page |
| `CURRENT_LANG.<path>` | mixed | Lookup into the active i18n dictionary |
| `BLOCKS` | list \| null | Page's block stream (use `{% blocks %}` to render) |
| `SEO.canonical`, `SEO.alternates`, `SEO.ldJson`, … | misc | Drive `<head>` SEO tags |
| `EMAIL.<key>` | struct | Obfuscated email widget data |
| `CAPTCHA`, `FORM_TOKEN`, `HONEYPOT_FIELD`, `FORM_ERRORS`, `FORM_VALUES`, `FORM_GLOBAL_ERROR`, `FORM_SENT`, `CONTACT_ENABLED` | misc | Contact-form pipeline (use as-is from existing partials) |
| `PAGE_TEMPLATE` | string | Legacy per-page template path |
| `CACHE_BUSTER` | string | Append `?v={{ CACHE_BUSTER }}` to asset URLs |

## AttributeParser pitfalls — content syntax constraints

Content `.md` files use a strict mini-YAML for block attributes.
These are NOT obvious; each one cost a debug round in earlier work:

- **Indentation: exactly two spaces per level. Tabs forbidden.**
- **Keys**: `^[a-zA-Z][a-zA-Z0-9_]{0,63}$`. NO dots, NO hyphens, NO
  quoting. (Slugs in URLs may have hyphens; attribute keys may not.)
- **Values**: trimmed remainder of the line. NO quoting —
  `value: "foo"` literally stores `"foo"` with the quotes.
- **No multi-line scalars** (no `|`, no `>`). Multi-line text goes
  in the block body (after a `---` separator inside the `:::`).
- **Lists**: `- ` at column 2. All items must be all-scalar OR
  all-map; mixing fails.
- **Maps inside list items**: continuation lines at column 4.
  **Maximum one level of nesting.** No nested lists inside list
  items — workaround: use numbered flat keys (`feat1, feat2,
  feat3, …`) and let the partial loop conditionally.
- **`:::` on its own line ends the outer block** regardless of
  where it appears. If a code-snippet body needs to show literal
  `:::`, indent the line with at least one space.
- **Image paths take no path marker**: `image: /assets/foo.jpg`
  (NOT `image: ~/assets/foo.jpg`). Block partials pass the raw path
  to the `{% image %}` directive, which resolves it against the
  configured asset roots and emits a cache URL of shape
  `/img-c/<basename>-<hash>.<ext>`.

## Path markers (in attribute values and Markdown link hrefs)

| Marker | Resolves to |
|---|---|
| `~/x` | `/<lang>/x` (multi-lang) or `/x` (single-lang) — internal page link |
| `^/x` | `/<base>/x` — base-relative direct asset reference |

Markers must be at position 0 of the value. Common use:
`ctaPrimaryHref: ~/imprint`, `href: ~/demos/business`.

## Block library reference

Every block type the default theme ships with. Use these in content;
do not invent new ones unless you also ship a partial in
`theme-<name>/templates/partials/blocks/<type>.html` with a
`{@ block @}` header declaring its schema. There is NO central
registry file to edit — the engine harvests headers at boot.

| Block | Required attrs | Optional attrs | Use for |
|---|---|---|---|
| `hero` | `title` | `id, eyebrow, lede, image, imageAlt, align, ctaPrimary, ctaPrimaryHref, ctaSecondary, ctaSecondaryHref` | First block on a landing |
| `sub-hero` | `title` | `id, eyebrow, lede` | Secondary hero on legal / sub pages |
| `prose` | — (body=Markdown) | `id, eyebrow, title, lede` | Long-form text section |
| `pillars` | `items[{title, body}]` | `id, eyebrow, title, lede` | N-up grid of value props |
| `feature-grid` | `items[{icon, title, body}]` | `id, eyebrow, title, lede` | N-up cards with inline-SVG glyphs |
| `stat-row` | `items[{value, label}]` | `id, eyebrow, title` | Headline-stats strip |
| `voices` | `items[{quote, name, role?, image?, imageAlt?}]` | `id, eyebrow, title, lede` | Testimonial grid |
| `gallery` | — | `id, eyebrow, items[{path, alt, caption?, lightbox?}]` | Image grid + optional lightbox |
| `programs` | `items[{title, body, image?, imageAlt?, ctaLabel?, ctaHref?}]` | `id, eyebrow, title, lede` | Service / product cards |
| `pricing` | `items[{name, price, period?, feat1..feat6, ctaLabel?, ctaHref?, featured?}]` | `id, eyebrow, title, lede` | Pricing tiers (max 6 features per tier — flat keys) |
| `code-snippet` | — (code goes in fenced ``` body) | `id, language, caption` | Preformatted code |
| `steps` | `items[{title, body}]` | `id, eyebrow, title, lede` | Numbered process / install steps |
| `theme-showcase` | `items[{name, tagline, href, image, imageAlt?}]` | `id, eyebrow, title, lede` | Demo theme grid (only on the WhimCMS core landing) |
| `end-cta` | `title, cta{label, href}` | `id, body` | Closing call-to-action |
| `contact` | — | `id, eyebrow, title, lede, directHeading` | Contact form + optional direct email aside |
| `legal-section` | `heading` (body=Markdown) | `id` | One section with markdown body |
| `legal-sections` | — | `id, items[{heading, body}]` | Flat list of plain-text legal pairs |

Available icons for `feature-grid` items:
`bolt, shield, layers, code, terminal, package, chart, spark,
gauge, lock, check, gear, globe, camera, heart, flame, feather`

## Theme-bound configs you may need to ship

Most themes only need: `routes.php` (your page slugs) and maybe
`i18n.php` (your language list). Skip the rest unless you actually
diverge from defaults.

| File | Ship when … | Skip when … |
|---|---|---|
| `routes.php` | Theme has page slugs different from `home, imprint, privacy` | Theme uses only the standard set |
| `i18n.php` | Theme ships in a non-standard language set | EN+DE is fine |
| `contact.php` | Form has additional fields (`phone`, `goal` selects, etc.) | Default name+email+message+consent is fine |
| `email_protection.php` | i18n key paths to email differ | Standard `home.contact.email` works |

(Block-type schemas are NOT in config — each block partial declares
its own schema via the `{@ block @}` header at its top.)

For **deployment-bound** files (`seo.php`, `mail.php`,
`security.php`), you may include sensible defaults but the user
must review and replace identity / branding.

## A11y minimum bar — non-negotiable

- Semantic HTML: `<header>`, `<nav>`, `<main>`, `<footer>`,
  `<section>`, `<article>`, `<figure>`, `<blockquote>` where they fit.
- One `<h1>` per page (the hero title). Then `<h2>` for sections,
  `<h3>` for sub-headings. Don't skip levels.
- `aria-label` on icon-only interactive elements (hamburger toggle,
  close buttons).
- `aria-current="page"` on the active nav link.
- Visible `:focus-visible` outline. Themes may restyle (color,
  offset, radius) but must keep an outline.
- `<a class="skip-link" href="#main">Skip to content</a>` first
  child of `<body>`. `<main id="main">` is the target. Wired in
  every layout — preserve it.
- Body text contrast against background must meet WCAG AA (4.5:1).
  Large text ≥18 pt or ≥14 pt bold must meet 3:1.
- Respect `@media (prefers-reduced-motion: reduce)` — base.css
  shortens transitions to 1 ms when set; don't add motion that
  ignores this.
- Image `alt` required for content images, empty (`alt=""`) for
  decorative ones.
- Form fields have explicit `<label for="…">` (the existing contact
  form template already does this — don't break it).
- Keyboard reachability: every interactive element reachable by
  Tab and operable by Enter / Space.

## Established JS / template hooks

If your theme adds interactive behaviour, your JS must use these
existing hook conventions (the engine ships generic JS modules
that target them):

| Hook | Used by JS module | Touched in templates |
|---|---|---|
| `.reveal` initial / `.reveal.in` revealed | `js/reveal.js` adds `.in` on intersection | base.css transitions |
| `[data-nav]`, `[data-nav-toggle]`, `[data-nav-primary]`, `[data-nav-open]` | `js/nav.js` mobile drawer | every nav partial |
| `[data-contact-form]`, `[data-form-global-region]`, `[data-contact-sent]` | `js/contact-form.js` | contact form partial |
| `[data-captcha-salt]`, `[data-captcha-difficulty]`, `[data-captcha-enabled]`, `[data-captcha-nonce]` | `js/captcha.js` | contact form partial |
| `[data-lightbox]`, `[data-lightbox-group]`, `[data-caption]`, `[data-full]` | `js/lightbox.js` | gallery partial |
| `.js-email` + `data-u`, `data-d` | `js/email.js` rehydrates obfuscated emails | contact partial |

Reuse these. If you need a fundamentally different interaction,
add a new module under `theme-<name>/js/<name>.js` and document
its data attributes.

## Security warning to surface to the operator

When you deliver the bundle, include a "security note" section at
the end reminding the operator:

> **Configs that ship with this theme must be reviewed before activation.**
> Specifically check:
> - `mail.php` recipient — should be your address, not a placeholder
> - `seo.php` canonical_hosts — your domain(s) only
> - `contact.php` honeypot_field — leave `null` for auto-derive
> - `security.php` — values should be at or above the engine defaults
>
> Never copy a config from an untrusted theme bundle without diffing
> against the current `config/<file>.php`.

## What "good output" looks like

A complete bundle response from you should:

1. **Start with three short questions** (theme name, niche,
   languages) — wait for answers before producing any file.
2. **After answers, produce a brief plan**: list of files you'll
   create, blocks you'll use on the home page, palette + typography
   you'll choose, and one sentence explaining the visual direction.
   Wait for the operator's "go" or adjustments.
3. **Then deliver the bundle**: file by file as code blocks with
   path comments. Keep each file complete — don't say "etc., the
   rest follows the same pattern."
4. **End with a checklist**: list of every produced file with its
   target path, plus the security-note section above, plus a
   smoke-test checklist (what to verify after copying files into
   the project).

## Anti-patterns — never do these

- Output partial files with "…rest is similar"
- Invent a block type without also shipping a partial under
  `partials/blocks/<type>.html` whose first lines declare the schema
  via a `{@ block @}` header
- Forget the `body.theme-<name>` scope on CSS rules
- Hard-code `/theme/` instead of `{{ THEME_URL }}`
- Use inline `<style>` or inline `<script>` in HTML — the CSP
  forbids them
- Reference a Google Font URL without also adding the matching
  CSP carve-out comment in the layout
- Use `<?php` anywhere in template files
- Use multi-line scalars in `.md` block attributes
- Use nested lists inside list-item maps
- Define an attribute key with a hyphen (use camelCase)

When in doubt, ask. The operator prefers a clarifying question
over a wrong assumption.
