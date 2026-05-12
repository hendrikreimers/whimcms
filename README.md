# WhimCMS

Server-rendered PHP CMS — Markdown content blocks, custom template
engine, JSON-based i18n, no build step. Designed to run on shared
hosting and to keep its security surface small enough to audit by
hand.

The bundled install ships a **showcase**: one core landing for the
CMS itself, plus four demo themes that prove the same block library
can power radically different sites.

| Route | Theme | Uses |
|---|---|---|
| `/` | core | The WhimCMS pitch — DE + EN |
| `/demos/business` | business | SaaS / B2B product landing |
| `/demos/personal` | personal | Creator / portfolio |
| `/demos/trainer` | trainer | Functional-fitness coach |
| `/demos/dev` | dev | Developer tool / CLI |

For a real deployment, strip the four `/demos/*` routes and content,
and theme one layout for your site. See `_docs/INSTALL.md`.

**Full documentation lives in [`_docs/`](./_docs/).**

- [Install](./_docs/INSTALL.md) — five-minute deploy
- [Content](./_docs/CONTENT.md) — editing pages and adding new ones
- [Blocks](./_docs/BLOCKS.md) — every registered block type
- [Templating](./_docs/TEMPLATING.md) — engine reference + extending
- [Architecture](./_docs/ARCHITECTURE.md) — code tour
- [Security](./_docs/SECURITY.md) — threat model + audit history

## Stack signature

PHP 8.1+, Apache. No Composer, no `node_modules`, no framework, no
database. Templates and content are plain files; everything edits
with a text editor.

## Themes — what changes between them

Each bundled theme is exactly three things:

- **One layout file** under `templates/layout-<name>.html` (HTML shell + which fonts to load)
- **One stylesheet** under `styles/theme-<name>.css` (every rule scoped to `.theme-<name>`)
- **One markdown page** under `content/en/demo-<name>.md` (the actual content)

Plus per-theme nav/footer partials under `templates/partials/`. The
**block partials** under `templates/partials/blocks/` are shared by
every theme — same HTML, restyled by CSS. That's the design point.

## Editor vs. engineer

WhimCMS has no admin UI, but it does have a clean editor/engineer
split. An optional file `content/_i18n_overlay.<lang>.json` merges
editor-controlled overrides on top of the theme's `i18n/<lang>.json`
at load time, gated by an allowlist in `config/i18n.php`. The
editor uses it for:

- Nav structure (items, order, labels, dropdowns)
- Per-page meta overrides (title, description)
- Footer copy

The theme stays editor-untouchable. The core theme's nav partial
demonstrates the pattern — it renders generically from the overlay,
so adding a nav item or reordering is a JSON edit, not a template
edit. The four demo themes keep their nav hardcoded (single-page
showcases), illustrating the contrast.

See [`_docs/CONTENT.md → Editor overlay file`](./_docs/CONTENT.md#editor-overlay-file)
for the format and security model.

## Why this exists (and why not WordPress / TYPO3)

Built for the class of sites that's **too small for a CMS but too
much for static HTML**: 5–15 pages, multilingual, contact form,
content that changes monthly rather than daily. Practices, studios,
small associations, portfolios, single-product landing pages.

What this project deliberately doesn't have, by comparison:

| WordPress / TYPO3 has | This doesn't |
|---|---|
| Plugin ecosystem with weekly-CVE treadmill | No plugins. What's in the repo is what runs. |
| Theme marketplace shipping arbitrary PHP | No themes. Templates are author-edited HTML. |
| `wp-admin` / backend as login attack surface | No admin UI. Editor edits nav + labels via a JSON overlay in `content/`; engineers manage themes separately. SSH + text editor. |
| Composer dependency tree → supply-chain risk | No `vendor/`, no `composer.lock`. |
| Database → SQL-injection class to defend against | No DB. Content is files. |
| JS build pipeline (`node_modules/`, webpack, …) | No build step. ES modules served as-is. |
| Auto-updates that occasionally break the site | No auto-updates needed. PHP point-releases via host. |
| Backup-before-every-update operational burden | Edit-commit-push. Backup is `git`. |

What it has instead: one custom template engine, one small content
engine, hand-rolled defences (CSRF, captcha, rate limit, blocklist,
honeypot), six documented security audits. All hand-edited, all
auditable in an afternoon.

**Operational profile**: roughly **0 hours/month** maintenance after
launch, vs. ~2–4 hours/month for an equivalent WordPress site over
its lifetime. The engineering investment up front buys
maintenance-freedom afterwards.

**Reusability**: the stack (`lib/`, `templates/partials/blocks/`,
`_docs/`) is structured to be lifted into a second small site by
keeping the engine and swapping the block CSS, content, and
project-specific block types. The `H42\` namespace is intentionally
neutral.

If you need: blog with daily posts, complex e-commerce, multi-author
editing, draft/publish workflow, a real admin UI — use WordPress or
TYPO3, this is the wrong tool. If you need: a brochure site that
stays up for years without touching, with a contact form that doesn't
get spammed into oblivion — this is what this project is for.

## How this was actually built

Honest disclosure, because the methodology is part of the picture.

This codebase was **vibe-coded** with an LLM (Claude) as the primary
author across multiple sessions. The owner did not write most of the
code line-by-line, but did the harder work: setting non-negotiable
constraints up front (no third-party PHP deps, no Composer,
hardened-by-default, audit-first), reading the LLM's output critically,
correcting it directly when wrong (file edits, not just feedback),
pushing back on shaky reasoning, and running **separate audit
sessions** as an explicit control gate.

The result is **only** as solid as it is because of three filters
that ran on top of the LLM output:

1. **Domain-aware human review of the diff.** The owner inspected
   the code as it landed — not just runtime behaviour. Several real
   bugs (image-path double-base, `{!! body !!}` for pre-rendered
   Markdown HTML, vestigial schema entries, a `ParseException`
   property collision) were caught either by direct code reading or
   by the owner running the site and noticing something visually
   off. Some fixes the owner made directly in the editor; others
   were flagged back for the LLM to redo.

2. **Constraints stated up front and held.** "No external deps"
   was stated at session zero and used as a refusal criterion every
   time a new piece of code suggested pulling in a library. Without
   that explicit anchor, an LLM trends toward popular dependencies
   because that's what its training set rewards.

3. **Separate audit sessions, not inline self-review.** Eight audit
   passes ran in their own sessions with adversarial framing
   ("find the holes"), distinct from the building sessions
   ("make it work"). The most recent pass combined manual code
   review with an external pentest run using OWASP ZAP and
   Semgrep. Building-mode and auditing-mode are different
   cognitive frames; an LLM mid-build doesn't reliably switch into
   auditor-mode mid-stream. Run separately, the audit sessions
   caught issues the build sessions missed — every finding from
   those audit passes is one of those:
   `X-H42-Error` header leaking class names in production, the
   honeypot field name being a known-dictionary value, the captcha-
   miss path bypassing the strike escalation, the CSRF bind strategy
   defaulting to "too strict for mobile networks". None of those
   were caught during construction.

What this **does** demonstrate: vibe-coded development can reach a
production-grade hardened result for a project of this size and
scope, given a domain-aware human in the loop and disciplined
audit separation.

What this **does not** demonstrate: that vibe-coded development
produces safe code by default. The audit findings are exactly the
class of issue that, without the audit step, would have shipped
unnoticed and looked fine. The methodology is critically dependent
on the audit step; without it, the artifact is plausible but not
trustworthy.

Honest gap: there are **no automated tests**. The audit passes
substituted for them functionally, but a regression-protection
suite for the home-rolled parsers (`AttributeParser`, `Markdown`,
`Sweeper`) would be a roughly 3–4 hour investment with real return
the next time the engine is touched. That's the one piece of this
project that's not where I'd want it to be.
