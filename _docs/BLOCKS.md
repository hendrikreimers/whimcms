# Block reference

Every block type that ships with the default theme. For each: required
attributes, optional attributes, a content example.

> **Where schemas live.** Each block's schema is declared in its own
> partial via a `{@ block @}` annotation at the top of
> `templates/partials/blocks/<type>.html`. There is no central
> registry file — adding a new block is one new partial. See
> [TEMPLATING.md → Adding a new block type](TEMPLATING.md#adding-a-new-block-type).

> **Universal optional `id`.** Every block listed below also accepts
> an `id` attribute that becomes the `id` of its outer `<section>`,
> usable as an in-page anchor target (`#contact`, `#features`, …).
> Not repeated in each table.

> **Convention.** Required attributes must be present and non-empty;
> missing one is a parse error. Optional attributes may be omitted.
> Unexpected attributes (typos, fields not in either list) are a parse
> error — typos fail loud.

> **Theme-agnostic by design.** Every block partial works across all
> bundled themes (core + business + personal + trainer + dev). Theme
> differentiation lives in CSS (`styles/theme-*.css`), not in the block
> partials. Adding a new block? Same rule: keep markup theme-agnostic,
> let the stylesheet do the visual work.

## Index

### Page heads
- [hero](#hero) — main hero, used as the first block on every theme's landing
- [sub-hero](#sub-hero) — quieter top-of-page for legal / sub-pages

### Content sections
- [prose](#prose) — long-form Markdown body
- [pillars](#pillars) — N-up grid of value props (text-only)
- [feature-grid](#feature-grid) — N-up cards with an inline-SVG icon
- [stat-row](#stat-row) — strip of headline stats (value + label)
- [voices](#voices) — testimonial / quote grid
- [gallery](#gallery) — image grid with optional lightbox
- [programs](#programs) — service / product cards with image + CTA
- [pricing](#pricing) — pricing tier cards
- [code-snippet](#code-snippet) — preformatted code with language label
- [steps](#steps) — numbered process / install steps
- [theme-showcase](#theme-showcase) — grid of demo theme cards (core landing only)

### Calls to action
- [end-cta](#end-cta) — closing call-to-action banner
- [contact](#contact) — contact form + optional direct email aside

### Legal pages
- [legal-section](#legal-section) — single section with Markdown body
- [legal-sections](#legal-sections) — flat list of `{heading, body}` pairs

---

## hero

First block on every theme's landing. Optional `image` makes it a cover
hero; without it, text-only.

| Attribute | Type | Required |
|---|---|---|
| `title` | string (`<em>` allowed) | yes |
| `eyebrow` | string | no |
| `lede` | string | no |
| `image` | path | no |
| `imageAlt` | string | no |
| `ctaPrimary` | string (label) | no |
| `ctaPrimaryHref` | string (href) | no |
| `ctaSecondary` | string (label) | no |
| `ctaSecondaryHref` | string (href) | no |
| `align` | `start` (default) or `center` | no |

```yaml
::: hero
eyebrow: Open showcase
title: A short headline with <em>one emphasised word.</em>
lede: One sentence sub-line.
ctaPrimary: Primary action
ctaPrimaryHref: ~/somewhere
ctaSecondary: Secondary action
ctaSecondaryHref: #anchor
image: /assets/images/hero.jpg
imageAlt: Cover image
:::
```

---

## sub-hero

Quieter hero for legal / non-landing pages.

| Attribute | Type | Required |
|---|---|---|
| `title` | string (`<em>` allowed) | yes |
| `eyebrow` | string | no |
| `lede` | string | no |

```yaml
::: sub-hero
eyebrow: Legal
title: Privacy
lede: One paragraph summary.
:::
```

---

## prose

Long-form Markdown body in a single section. Use this when you want a
real paragraph stack with headings and inline formatting (the `body`
goes through the safe-subset Markdown renderer — see TEMPLATING.md).

| Attribute | Type | Required |
|---|---|---|
| `eyebrow` | string | no |
| `title` | string | no |
| `lede` | string | no |

The block body (after the `---` separator) is the Markdown content.

```yaml
::: prose
eyebrow: About
title: About the studio.
---
For ten years I have made still photographs of objects and rooms.

The studio is a one-person operation. **Most projects** ship within
two weeks. *For larger work* I bring in a second pair of hands.
:::
```

---

## pillars

N-up grid of value props (text-only). For icon cards see `feature-grid`.

| Attribute | Type | Required |
|---|---|---|
| `items` | list of `{title, body}` | yes |
| `eyebrow` | string | no |
| `title` | string | no |
| `lede` | string | no |

```yaml
::: pillars
eyebrow: Why us
title: Three reasons.
items:
  - title: Tiny attack surface
    body: No admin UI, no database, no Composer dependencies.
  - title: Markdown content
    body: One .md file per page. Push, reload, done.
  - title: Runs anywhere
    body: PHP 8.1 and a static-file server.
:::
```

---

## feature-grid

N-up cards with an inline-SVG glyph + title + body. The glyph is
referenced by name (e.g. `bolt`, `shield`) and resolves to one of the
icons in `templates/partials/icons/glyph.html`. Available icons today:
`bolt, shield, layers, code, terminal, package, chart, spark, gauge,
lock, check, gear, globe, camera, heart, flame, feather`.

| Attribute | Type | Required |
|---|---|---|
| `items` | list of `{icon, title, body}` | yes |
| `eyebrow` | string | no |
| `title` | string | no |
| `lede` | string | no |

```yaml
::: feature-grid
eyebrow: How it works
title: Six things this CMS does.
items:
  - icon: layers
    title: Block-based content
    body: Pages are typed blocks defined in markdown.
  - icon: shield
    title: Hardened by default
    body: CSRF, captcha, rate-limit, honeypot.
:::
```

---

## stat-row

Strip of headline stats (value + label).

| Attribute | Type | Required |
|---|---|---|
| `items` | list of `{value, label}` | yes |
| `eyebrow` | string | no |
| `title` | string | no |

```yaml
::: stat-row
items:
  - value: 12k+
    label: Teams in production
  - value: 99.99%
    label: Uptime SLA
:::
```

---

## voices

Testimonial / quote grid. Each item renders as a `<figure>` with a
`<blockquote>` and a credit `<figcaption>`.

| Attribute | Type | Required |
|---|---|---|
| `items` | list of `{quote, name, role?, image?, imageAlt?}` | yes |
| `eyebrow` | string | no |
| `title` | string | no |
| `lede` | string | no |

```yaml
::: voices
eyebrow: Customers
title: What people say.
items:
  - quote: We replaced three vendors with one workspace.
    name: Mara Lindgren
    role: Head of Data, Northwind
    image: /assets/images/placeholder/shared/avatar-01.jpg
    imageAlt: Customer portrait
:::
```

---

## gallery

Image grid with optional lightbox per item. Items with `lightbox: true`
become clickable triggers handled by `js/lightbox.js`.

| Attribute | Type | Required |
|---|---|---|
| `items` | list of `{path, alt, caption?, lightbox?}` | no |
| `eyebrow` | string | no |
| `id` | string | no — grouping key for the lightbox when multiple galleries share a page |

```yaml
::: gallery
eyebrow: Work
id: work
items:
  - path: /assets/images/photo-01.jpg
    alt: Editorial still life
    caption: Object — Studio, 2024
    lightbox: true
  - path: /assets/images/photo-02.jpg
    alt: Architectural scene
    caption: Interior — Project X, 2024
    lightbox: true
:::
```

---

## programs

Service / product cards with image, title, body, and a CTA per item.

| Attribute | Type | Required |
|---|---|---|
| `items` | list of `{title, body, image?, imageAlt?, ctaLabel?, ctaHref?}` | yes |
| `eyebrow` | string | no |
| `title` | string | no |
| `lede` | string | no |

```yaml
::: programs
eyebrow: Services
title: How we work.
items:
  - title: Editorial sessions
    body: Half-day or full-day on location.
    image: /assets/images/sample.jpg
    imageAlt: Sample shoot
    ctaLabel: Book a session
    ctaHref: '#contact'
:::
```

---

## pricing

Pricing tier cards. `featured: true` highlights the recommended tier.
`features` is a list of strings, each rendered as a checked bullet.

| Attribute | Type | Required |
|---|---|---|
| `items` | list of `{name, price, period?, features?, ctaLabel?, ctaHref?, featured?}` | yes |
| `eyebrow` | string | no |
| `title` | string | no |
| `lede` | string | no |

```yaml
::: pricing
eyebrow: Pricing
title: Pick a tier.
items:
  - name: Starter
    price: $29
    period: per seat / month
    features:
      - 5 active workspaces
      - Email support
    ctaLabel: Start free trial
    ctaHref: '#contact'
  - name: Team
    price: $79
    period: per seat / month
    featured: true
    features:
      - 25 active workspaces
      - SSO and audit log
    ctaLabel: Start free trial
    ctaHref: '#contact'
:::
```

---

## code-snippet

Preformatted code with a language label. The code itself goes in the
block **body** as a fenced ` ``` ` block — multi-line code does not have
to fit in an attribute scalar. The `language` attribute drives the
visible label and a `data-lang` CSS hook; no syntax highlighting at the
markup layer.

| Attribute | Type | Required |
|---|---|---|
| `language` | string | no |
| `caption` | string | no |

```yaml
::: code-snippet
language: bash
caption: One binary, no dependencies.
---
\`\`\`
brew install halt
curl -sSL https://halt.dev/install.sh | sh
\`\`\`
:::
```

> **Note.** Inside the body, ` ``` ` opens and closes the fenced block.
> Inside the fence, every character is HTML-escaped and emitted
> verbatim. Avoid putting the literal string `:::` on its own line in
> the body — the outer block parser treats `:::` as the block closer
> regardless of context.

---

## steps

Numbered process / install steps. Numbers are generated by CSS via an
`<ol>` counter — markup uses semantic ordering, not hardcoded indices.

| Attribute | Type | Required |
|---|---|---|
| `items` | list of `{title, body}` | yes |
| `eyebrow` | string | no |
| `title` | string | no |
| `lede` | string | no |

```yaml
::: steps
eyebrow: Quickstart
title: Three commands.
items:
  - title: Install the binary
    body: Use the package manager you already trust.
  - title: Authenticate once
    body: Run `halt auth` and paste the token.
  - title: Run halt
    body: From any repo.
:::
```

---

## theme-showcase

Grid of demo-theme cards. Used only on the core landing to advertise
the bundled demo themes.

| Attribute | Type | Required |
|---|---|---|
| `items` | list of `{name, tagline, href, image, imageAlt?}` | yes |
| `eyebrow` | string | no |
| `title` | string | no |
| `lede` | string | no |

```yaml
::: theme-showcase
eyebrow: Bundled themes
title: One CMS, many faces.
items:
  - name: Business
    tagline: SaaS-style product landing
    href: ~/demos/business
    image: /assets/images/placeholder/demos/business/hero.jpg
    imageAlt: Business demo preview
:::
```

---

## end-cta

Closing call-to-action banner — one title, optional body, one CTA.

| Attribute | Type | Required |
|---|---|---|
| `title` | string (`<em>` allowed) | yes |
| `cta` | map `{label, href}` | yes |
| `body` | string | no |

```yaml
::: end-cta
title: Pick a theme. Make it yours.
body: Each demo is one layout file plus one stylesheet plus a markdown page.
cta:
  label: See bundled themes
  href: '#themes'
:::
```

---

## contact

Section with the contact form on one side and an optional direct
contact address aside on the other.

| Attribute | Type | Required |
|---|---|---|
| `eyebrow` | string | no |
| `title` | string | no |
| `lede` | string | no |
| `directHeading` | string | no — when present, the direct-contact aside renders with the obfuscated email from `EMAIL.contact` (sourced from i18n at `home.contact.email`) |

```yaml
::: contact
eyebrow: Get in touch
title: Tell us what you would like to build.
lede: A real form — captcha, CSRF, rate-limit, honeypot.
directHeading: Direct
:::
```

The form's labels / errors live in i18n at `home.contact.form.*`
(shared with the controller's POST re-render path). The form is gated
on `CONTACT_ENABLED` (set in `RenderContext` from `contact.enabled`):
when `false`, the form column is skipped, only the direct aside
remains. To remove the contact section entirely, drop the `contact`
block from your page's `.md`.

---

## legal-section

One heading + Markdown-rendered body. Use this when the section text
needs Markdown — paragraphs, line breaks, lists, **emphasis**.

| Attribute | Type | Required |
|---|---|---|
| `heading` | string | yes |

The block body (after `---`) is the Markdown content.

```yaml
::: legal-section
heading: Operator
---
**Your name** or company. Street and number. ZIP and city.

Contact via the [contact form](~/) on the home page.
:::
```

---

## legal-sections

Flat list of `{heading, body}` pairs. Each entry's `body` is plain text
(no Markdown). For Markdown bodies, use `legal-section` per section
instead.

| Attribute | Type | Required |
|---|---|---|
| `items` | list of `{heading, body}` | no |

```yaml
::: legal-sections
items:
  - heading: What this site stores
    body: Server access logs are kept by the host. The contact form does not write submitter data to disk by default.
  - heading: Cookies
    body: This site does not set cookies on first page load.
:::
```
