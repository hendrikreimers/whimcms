# Content authoring

How to edit pages, add new ones, manage translations, and decide
what goes where.

## The four-tier model

```
content/<lang>/<slug>.md         per-page composition + prose    ← edit per page
content/_i18n_overlay.<lang>.json  editor i18n overrides          ← edit per locale (optional)
i18n/<lang>.json                  theme microcopy (chrome)        ← edit per locale
templates/                        visual rendering                ← engineering only
```

| Layer | Holds | Edited by |
|---|---|---|
| `content/<lang>/<slug>.md` | Page composition (which blocks, in what order), the prose inside them, plus per-page meta (title, description) in the front-matter | Authors / editors |
| `content/_i18n_overlay.<lang>.json` | Editor-controlled site-wide overrides on top of theme i18n: nav structure, footer copy (subject to an allowlist in `config/i18n.php`) | Authors / editors |
| `i18n/<lang>.json` | Theme-shipped chrome: form labels, error messages, ARIA strings, page-meta fallbacks | Engineers / theme authors |
| `templates/` | HTML structure of each block, layout, partials | Engineers |

**Rule of thumb:** if it's *what's on this page* (content + meta),
it's in `content/<lang>/<slug>.md`. If it's *what surrounds every
page* and editor-managed (nav, footer copy), it's in
`content/_i18n_overlay.<lang>.json`. If it's *part of the theme's
chrome* (form labels, errors, ARIA), it's in `i18n/<lang>.json`. If
it's *how it looks*, it's in `templates/`.

## Editing existing content

1. Find the `.md` for the page and language: `content/<lang>/<slug>.md`.
2. Open in any editor that **saves UTF-8 without BOM**. PhpStorm,
   VS Code, Sublime — all default to that. Plain Notepad on Windows
   may save as Latin-1; use a real editor.
3. Edit. Save. Reload the browser. The on-disk cache validates by
   `mtime`, so changes appear immediately.

If you break the syntax, the page renders a debug trace (when
`config/app.php → debug` is `true`) with the exact line number that
failed. Reload after fixing.

## File format

A page file has two parts: an optional **front-matter header** and a
sequence of **content blocks**.

```markdown
---
layout: default
meta:
  title: About — Example
  description: One sentence summary used as <meta description> and OG description.
---

::: sub-hero
eyebrow: Section eyebrow
title: A short headline with <em>one emphasised word.</em>
sub: One subtitle line beneath the headline.
bgImage: /assets/photos/hero-bg.jpg
:::

::: portrait
image: /assets/photos/placeholder.jpg
alt: Site Owner
name: Site Owner
role: Role / title
:::

::: end-cta
title: Closing CTA headline.
body: One- or two-sentence supporting line beneath the headline.
btn: Button label
:::
```

### Front-matter

Optional. If present, must be the very first thing in the file,
opened and closed with `---` on lines of their own.

| Key | Required | Notes |
|---|---|---|
| `layout` | optional | Whitelist in `config/content.php → content.allowed_layouts`. Defaults to `default`. Maps to `templates/layout.html` (or `layout-<other>.html`). The bundled showcase ships with `default, business, personal, trainer, dev`. |
| `meta.title` | optional | Used as `<title>` and OG/Twitter title. Falls back to `i18n/<lang>.json → meta.<slug>.title`. |
| `meta.description` | optional | Used as `<meta name="description">` and OG/Twitter description. Same fallback. |

Any other top-level key fails parsing — typos surface loud.

### Blocks

Each block opens with `::: <type>` on its own line and closes with
`:::` on its own line. Inside:

```
::: <type>
key: value         ← attribute
nested:
  innerKey: value  ← attribute on a nested map (max 1 level of nesting)
list:
  - itemKey: value ← attribute on a list item (map)
    other: value
  - itemKey: value
scalarList:
  - first item     ← list of plain strings
  - second item
---                ← optional separator: attributes above, Markdown body below
Body in **Markdown**, with paragraphs, *em*, lists, and [links](https://example.com).

The body is rendered by the safe-subset Markdown renderer. Empty by default.
:::
```

#### Attribute syntax

Strict mini-format. Hard rules:

- Indentation is **exactly two spaces** per level. **Tabs are forbidden.**
- Keys: `[a-zA-Z][a-zA-Z0-9_]{0,63}`. No dots, dashes, quotes.
- Values are the trimmed remainder of the line. **No quoting.**
  `value: "hello"` literally stores the string `"hello"` with the
  quotes — you almost never want that. Just write `value: hello`.
- Empty value (`key:` with nothing after the colon) means an empty
  string.
- Lists use `- ` prefix at column 2. List items are either:
  - all scalars (`- foo`, `- bar`, …), **or**
  - all maps (`- key: value` then continuation lines at column 4).
  Mixing scalars and maps inside one list is a parse error.
- Blank lines are allowed only between top-level keys. Don't put
  blank lines inside a list or a nested map.
- Maximum file size: 256 KiB. Maximum value length: 4096 bytes.
  Maximum 500 lines per attribute block.

If your value contains `: ` (a colon followed by a space) — like a
natural-language sentence — it works as a scalar item only when the
text *before* the colon doesn't look like a key (i.e. has spaces,
punctuation, or any non-key character). Examples:

```yaml
items:
  - Anyone with a deadline: a launch, a competition.   ← scalar, fine
  - heading: My heading                                    ← map item, fine
```

If you mean a scalar but the prefix happens to look key-shaped
(`- name: YourName`), you'd accidentally get a map item with `name`
key. There's no quoting to escape this; rephrase the sentence.

#### Markdown body (after `---`)

Optional. Activated by a `---` separator line inside the block.
Renders to safe HTML via `lib/WhimCMS/Content/Markdown.php`.

Supported:

- **Paragraphs** — text separated by blank lines
- **Headings** — `## h2`, `### h3`, `#### h4` (no h1, h5, h6)
- **Bold** — `**bold text**`
- **Italic / em** — `*italic*`
- **Inline code** — `` `code` ``
- **Fenced code blocks** — opened by ` ``` ` (optionally followed by a
  language tag matching `[a-z0-9_+-]+`), closed by a line containing
  only ` ``` `. Every character inside is HTML-escaped and emitted
  verbatim. Used by the `code-snippet` block whose multi-line code
  cannot fit in an attribute scalar.
- **Lists** — unordered, each item on its own line prefixed with `- `
- **Links** — `[text](href)` with allowlisted schemes only:
  `https:`, `mailto:`, `tel:`, relative `/...`, fragment `#...`,
  path markers `~/...` or `^/...`

Not supported (deliberately): inline HTML, images via `![]()`,
ordered lists, blockquotes, tables, footnotes, autolinks,
reference-style links, HTML entities, escape sequences. Adding any of
these requires a security review — see [SECURITY.md](SECURITY.md).

The **same scheme allowlist** also gates block-attribute hrefs
(`cta.href`, `tier.ctaHref`, `attrs.ctaPrimaryHref`, etc.) at
template render time via the `{% safe_href %}` directive — see
[TEMPLATING.md → safe_href directive](TEMPLATING.md#safe_href-directive).
A value like `cta.href: javascript:alert(1)` in a `.md` file
renders as empty `href=""` and produces a `safe_href: rejected
href` log entry; it does not reach the visitor's browser as an
executable URL.

## Path markers

Two marker characters resolve at parse time, in attribute values
**and** in Markdown link `href`s:

| Marker | Resolves to | Use for |
|---|---|---|
| `~/x` | `/<lang>/x` (multi-lang) or `/x` (single-lang) | Internal page links |
| `^/x` | `/<base>/x` | Language-independent assets |

Examples:

```yaml
ctaHref: ~/examplepage              # links to /<lang>/examplepage
ctaHref: ^/some-static.html     # links to /<base>/some-static.html
```

```markdown
[See the examples page](~/examplepage)
[Back to home](~/)
```

Both markers must be at the **start** of the value or the link href.
A marker mid-string is left as a literal `~` or `^`.

### Image paths are an exception — no marker

Image-path attributes (`image`, `bgImage`, gallery `path`, etc.) are
**base-relative without a marker**:

```yaml
bgImage: /assets/photos/hero-bg.jpg     # correct — base-relative
bgImage: ^/assets/photos/hero-bg.jpg    # WRONG — would double the base
```

Why: every block partial that takes an image path passes it to the
`{% image %}` directive, which validates the path against the
configured asset roots and emits a cache-relative URL:

```html
<img src="{% image: <path>, maxWidth: 1280 %}">
```

The directive resolves the path internally against the configured
asset roots. If the path were `^/assets/...` (already base-prefixed
by the path-marker resolver), it would fail the asset-roots
containment check and the directive would emit an empty URL. The
`^` marker is for *direct* references to base-rooted URLs (a
download link, an anchor `href`); the `{% image %}` directive
doesn't want that.

## Adding a new page

Three steps. None of them changes how existing pages render.

### 1. Add a route in `config/routes.php`

Each language gets a URL segment that maps to the canonical slug:

```php
'routes' => [
    'en' => [
        // …existing routes…
        'pricing' => 'pricing',
    ],
    'de' => [
        'preise' => 'pricing',
    ],
],
```

The slug must match `[a-zA-Z][a-zA-Z0-9_-]{0,40}`. Lower-case with
dashes is the convention (e.g. `pricing`, `demo-business`).

A slug doesn't need to be present in every language — `Router::
buildLangSwitch` filters out languages where the slug is missing,
so the language switcher never renders a dead link.

### 2. Create the content files

One per language the slug is published in, matching the slug:

```
content/en/pricing.md
content/de/pricing.md
```

Compose from the existing block vocabulary — see [BLOCKS.md](BLOCKS.md)
for every registered block, its attributes, and an example.

### 3. Decide whether the page needs nav

If the active theme has an editor-driven nav (the core theme does;
see [§ Editor overlay file](#editor-overlay-file) below), add the
page to `content/_i18n_overlay.<lang>.json` under `nav.primary`:

```json
{
  "nav": {
    "primary": [
      ...
      { "label": "Pricing", "slug": "pricing" }
    ]
  }
}
```

Reorder, relabel, or hide by editing the list — no template touch
needed.

Themes whose nav is still hardcoded (e.g. the demo themes
`business`, `personal`, `trainer`, `dev` — bundled as single-page
showcases) require editing the matching `templates/partials/nav-<theme>.html`
partial directly.

Pages reachable only via direct URL or in-page links don't need nav
edits — just the route + the content file.

That's the whole flow. No template file under `templates/pages/` is
needed; the layout renders blocks straight from the `.md`.

## Editor overlay file

`content/_i18n_overlay.<lang>.json` is an **optional** per-language
file that merges editor-controlled overrides on top of the theme's
`i18n/<lang>.json` at i18n load time. It's how an editor manages
nav structure and footer copy without touching theme files (which
would be lost on theme update).

Per-page meta (title, description) is intentionally *not* in this
file — it lives in the page's own `.md` front-matter and is edited
via the page editor.

### Format

Plain JSON. Same path markers as the theme i18n (`~/x` for
language-aware, `^/x` for base-relative) — resolved at load time.

```json
{
  "nav": {
    "primary": [
      { "label": "Features", "anchor": "features" },
      { "label": "Imprint",  "slug":   "imprint"  },
      { "label": "Docs", "children": [
        { "label": "Install", "slug":   "install" },
        { "label": "Blog",    "href":   "https://example.com/blog" }
      ]}
    ]
  },
  "footer": {
    "copy": "Custom footer line — replaces the theme default."
  }
}
```

### Nav item shapes (used by the core theme's nav partial)

| Shape | Use | Active state |
|---|---|---|
| `{ label, anchor }` | In-page hash link on home (`#features`) | — |
| `{ label, slug }` | Routed page in the active language; URL resolved via the slug→URL map | Auto, when `PAGE == slug` |
| `{ label, href }` | Direct URL escape hatch: cross-language link, external site. Validated via `safe_href` (https/mailto/tel/root-relative/anchor only) | None (no slug to compare) |
| `{ label, children: [...] }` | Dropdown group whose children use the same three shapes above | — |

**Preference order:** anchor > slug > href. Use `slug` for routed
pages whenever possible (active state, language flexibility); use
`href` only when slug-routing can't express the target (e.g.
cross-language demos: `^/en/demos/business` from the de overlay).

### Allowlist — the security boundary

`config/i18n.php → i18n_overlay.allowed_sections` defines which
top-level keys the overlay may contribute. Anything outside the
list is **silently dropped** during load — an editor cannot
overwrite `errors._404.title`, `a11y.skipLink`, `home.contact.form.errors.*`
or any chrome string even by writing the key. Default:

```php
'i18n_overlay' => [
    'allowed_sections' => ['nav', 'footer'],
],
```

Keep the list as tight as the editor surface requires. Broadening
it hands more of the dictionary to whoever can edit the file.

### Merge semantics

- Both sides associative → recursive merge per key. `nav` in the
  overlay merges with `nav` in the base.
- Either side is a sequential list → overlay value wholly replaces
  base value. `nav.primary: [...]` from the overlay defines the
  full list; the base's `nav.primary` (if any) is dropped.
- Anything else → overlay replaces base, or is added fresh.

### Failure modes

- File absent → no merge, behaviour identical to a pre-overlay
  deployment. Adding the file is the opt-in.
- File present but empty → treated as absent. Editor saving an
  empty file by accident doesn't hard-fail.
- Invalid JSON → throws `RuntimeException`, fail loud so editor
  mistakes surface on reload instead of silently half-merging.
- Top-level not an object → treated as absent.

## Adding a new block type to a page

If the page needs a section type that doesn't exist yet:

1. Write a block partial under `templates/partials/blocks/<type>.html`
   with a `{@ block @}` header at the top declaring its schema.
2. Use it in your `.md`.

That's it — no config edit, no class edit. The engine harvests the
header at boot. See
[TEMPLATING.md](TEMPLATING.md#adding-a-new-block-type) for the full
recipe with example.

## Adding a new language

1. Add the code to `config/i18n.php → supported_langs` (`en` and `de`
   are the bundled defaults).
2. Add an entry under `config/routes.php → routes`:

   ```php
   'fr' => [
       ''        => 'home',
       'a-propos' => 'about',
       // … one entry per page slug, like the other languages
   ],
   ```

3. Create `i18n/fr.json` with the same shape as `i18n/en.json`.
   The `meta.<slug>.title` and `.description` fallbacks live here.
4. Create `content/fr/<slug>.md` for every page. Easiest way is to
   copy the `en` files and translate.

If a content file is missing for a language, that page returns 404
in that language — there's no auto-fallback to a different language.

## When to use which file

Per-language strings live in three places, with editor sovereignty
ranked from highest to lowest:

| It is …                                          | Goes to … |
|---|---|
| Body copy you'd find in a CMS (paragraphs, headings, image alt-texts) | `content/<lang>/<slug>.md` |
| Section headlines, eyebrows, CTAs that vary per page | `content/<lang>/<slug>.md` |
| Lists where each item has labels and bodies (programs, voices, FAQs) | `content/<lang>/<slug>.md` |
| Nav structure (items, order, labels, dropdowns) | `content/_i18n_overlay.<lang>.json → nav` |
| Per-page meta (title, description) | `content/<lang>/<slug>.md` front-matter (with `i18n/<lang>.json → meta.<slug>` as fallback) |
| Footer copy the editor controls | `content/_i18n_overlay.<lang>.json → footer` |
| Form labels, placeholders, validation error messages | `i18n/<lang>.json` |
| ARIA labels, common UI strings ("Read more", "Back to home") | `i18n/<lang>.json` |
| Email addresses, social handles | `i18n/<lang>.json` (because `EmailProtection` reads them from there per `config/email_protection.php → email_protection.paths`) |
| Per-page meta fallback (when neither overlay nor `.md` sets its own) | `i18n/<lang>.json → meta.<slug>` |

When in doubt:
- A value that affects the **rendering of one specific page only** →
  `content/<lang>/<slug>.md`.
- A value the **editor manages but spans the site** (nav, footer copy,
  per-page meta override) → `content/_i18n_overlay.<lang>.json`.
- A value referenced from **backend code** (the contact controller,
  the mailer, the form validator) → `i18n/<lang>.json`.

## File-level limits

These are enforced by `PageLoader` and surface as a `ParseException`
on overrun:

| Limit | Default | Override |
|---|---|---|
| Max bytes per `.md` | 256 KiB | `config/content.php → content.max_bytes` |
| Max lines per attribute block | 500 | hard-coded in `AttributeParser::MAX_LINES` |
| Max value length | 4096 bytes | hard-coded in `AttributeParser::MAX_VALUE_LEN` |
| Max key length | 64 chars | hard-coded in `AttributeParser::MAX_KEY_LEN` |
| Max URL length in MD link | 2 KiB | hard-coded in `Markdown::MAX_HREF_BYTES` |

Real pages today are 5–15 KiB each, so these are DoS guards rather
than authoring constraints.
