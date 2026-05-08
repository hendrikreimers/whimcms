---
layout: default
meta:
  title: WhimCMS — a minimal, security-first PHP CMS
  description: Server-rendered, file-based, no build step. One repository you can read in an afternoon and host anywhere PHP runs.
---

::: hero
title: A minimal, security-first PHP CMS.
eyebrow: Open showcase
lede: Server-rendered. File-based. No build step. One repository you can read in an afternoon and host anywhere PHP runs.
image: /assets/images/placeholder/core/hero.jpg
align: start
focusX: 0.5
focusY: 0.5
ctaPrimary: See the themes
ctaPrimaryHref: #themes
ctaSecondary: Architecture
ctaSecondaryHref: #architecture
:::

::: stat-row
items:
  - value: 0
    label: Plugins
  - value: 0
    label: Database
  - value: 0
    label: Build steps
  - value: 0
    label: Composer deps
:::

::: feature-grid
items:
  - icon: layers
    title: Block-based content
    body: Pages are a stack of typed blocks. Each block declares its schema via a {@ block @} annotation in its partial — typos fail loud at parse time.
  - icon: shield
    title: Hardened by default
    body: Six layers gate every POST — CSRF, proof-of-work captcha, sliding rate limit, soft IP blocklist, per-installation honeypot, mandatory consent.
  - icon: bolt
    title: Focus-aware images
    body: {% image: '/assets/photo.jpg', width: 768, focusX: 0.5 %} in any template emits a focus-aware crop, caches it on disk, serves it under /img-c/<hash> with immutable headers. Read-only endpoint.
  - icon: globe
    title: Multilingual routing
    body: Per-language URL segments. Automatic hreflang and a working language switcher with no dead links.
  - icon: gauge
    title: HMAC-signed cache
    body: Page renders cache to disk as HMAC-signed JSON. A forged cache file is dropped before its bytes reach any parser — write-primitives elsewhere cannot escalate to code execution.
  - icon: lock
    title: Privacy-respecting
    body: Email obfuscation, no third-party scripts by default, no cookies on first page load.
id: features
eyebrow: How it composes
title: A lean block library — the same set drives every theme.
lede: Authors arrange blocks in markdown. Themes restyle them in CSS. The HTML stays the same.
:::

::: code-snippet
language: markdown
caption: For editors — a page is a stack of typed blocks in one .md file.
---
```
::: hero
eyebrow: Open showcase
title: Less platform, more website.
ctaPrimary: See themes
ctaPrimaryHref: #themes

::: pillars
eyebrow: Why
items:
  - title: Tiny attack surface
    body: No admin UI, no database, no Composer.
  - title: Markdown content
    body: One .md per page. Push, reload, done.
```
:::

::: code-snippet
language: html
caption: For developers — a new block type is one HTML file with a {@ block @} header. No registry edit, no config change.
---
```
{@ block
  required: items
  optional: id eyebrow title lede
@}
<section class="block block-pillars">
  <ul>
    {% for: attrs.items, as: 'item' %}
    <li>
      <h3>{{ item.title }}</h3>
      <p>{{ item.body }}</p>
    </li>
    {% endfor %}
  </ul>
</section>
```
:::

::: steps
items:
  - title: Upload everything
    body: SFTP the repo to any PHP 8.1+ host. Rename _htaccess_production to .htaccess.
  - title: Set one config value
    body: Edit config/seo.php — set canonical_hosts to your domain. The boot refuses to start without it, so Host-header poisoning of canonical, OG, and sitemap URLs is structurally impossible.
  - title: Edit content/ and reload
    body: One markdown file per page. Push, reload the browser. The content cache invalidates on mtime — no flush button, no admin UI.
eyebrow: For operators
title: From zero to live in three steps.
lede: No build, no Composer, no database. The deploy is uploading files.
:::

::: theme-showcase
items:
  - name: Business
    tagline: SaaS-style product landing
    href: ~/demos/business
    image: /assets/images/placeholder/demos/business/hero.jpg
    imageAlt: Business demo preview
  - name: Personal
    tagline: Creator and portfolio
    href: ~/demos/personal
    image: /assets/images/placeholder/demos/personal/hero.jpg
    imageAlt: Personal demo preview
  - name: Trainer
    tagline: Functional-fitness coach
    href: ~/demos/trainer
    image: /assets/images/placeholder/demos/trainer/hero.jpg
    imageAlt: Trainer demo preview
  - name: Dev
    tagline: Developer tool / CLI
    href: ~/demos/dev
    image: /assets/images/placeholder/demos/dev/hero.jpg
    imageAlt: Dev demo preview
id: themes
eyebrow: Bundled themes
title: One CMS, many faces.
lede: Each demo is the same WhimCMS install with a different layout file and stylesheet bundle. The block partials underneath are identical.
:::

::: prose
id: architecture
eyebrow: For auditors
title: Three subsystems, no surprises.
lede: A content engine, a template engine, a small kernel that wires them together. The whole repo reads in an afternoon.
---
The **content engine** parses each `.md` page into typed `Block` objects. There is no central block registry — every block partial declares its own schema via a `{@ block @}` annotation at the top of the file, harvested at boot. Adding a new block type is one new HTML file; nothing else changes.

The **template engine** is a small token-stream renderer with eight built-in directives (`if`, `for`, `include`, `image`, `blocks`, …). Output is HTML-escaped by default; raw output is one explicit, audit-tracked opt-in. The `{% image %}` directive is the only path that ever writes to the image cache — its read-only `/img-c/` endpoint cannot fan out cache writes via URL manipulation.

The contact form is the only write surface, gated by six independent layers and documented across **eight audit passes**, the most recent combining manual code review with an external pentest run using OWASP ZAP and Semgrep. Cache files are HMAC-signed, so a forged cache cannot escalate to code execution. Boot refuses to start without a canonical-host allowlist, so Host-header poisoning of canonical, OG, and sitemap URLs is structurally impossible.
:::

::: prose
id: admin
eyebrow: Optional admin
title: A companion, not a plugin.
lede: WhimCMS works as a pure file-edit CMS — open content/, push, reload. When that's not enough, WhimAdmin lives in the same repository as an optional admin panel, written by the same hand, audited under the same posture, with the same zero-runtime-dependency rule.
---
"Plugin" in the CMS world means third-party code with its own dependency graph and security posture, loaded at runtime through a hook bus or a marketplace. WhimCMS has none of that machinery — and adding WhimAdmin doesn't introduce any. It is one extra directory, hand-audited, that you can read in an afternoon, deploy or omit, delete or keep. The "0 plugins" claim stays true with it on disk.
:::

::: feature-grid
items:
  - icon: shield
    title: Two-factor login
    body: Password (Argon2id) plus 6-digit OTP delivered by mail. Sessions bind to IP + UA, rotate on auth upgrade, and timeout on idle and absolute clocks independently.
  - icon: layers
    title: Per-page version history
    body: Every save snapshots the pre-write content. Restore any prior version with one click. History is sentinel-swept after a configurable retention window so storage stays bounded.
  - icon: bolt
    title: Soft recycler for pages and assets
    body: Delete is never destructive. Pages move to content/.recycler/, assets to assets/.recycler/. Both are web-deny'd. Auto-sweep ages out entries you forgot about.
  - icon: globe
    title: Routes and languages editor
    body: URL segments per language and the supported-languages list edit through the UI. The writer round-trips every change through `require` before the rename — a serialiser regression cannot land a broken routes.php on disk.
  - icon: gauge
    title: Asset browser with content-sniffing upload
    body: Upload via the UI under a 10 MB cap. Extensions allowlisted; getimagesize verifies the bytes actually match the claimed format. SVG is intentionally excluded (inline-script vector).
  - icon: lock
    title: First-run setup without bootstrap mail
    body: A 32-byte token is minted on first request, HMAC-stored, plaintext-mirrored to a deny-all sidecar file the operator reads via SFTP. No bootstrap email, no admin enumeration vector, single-use.
id: admin-features
eyebrow: WhimAdmin
title: When file-edits aren't enough.
lede: Same code style, same audit posture, same zero-deps rule. Optional, deletable, deployable as a pure addition.
:::

::: prose
eyebrow: Honest disclosure
title: How this was actually built.
lede: This codebase was vibe-coded with an LLM (Claude) as primary author across multiple sessions. Three filters kept it production-grade.
---
**Domain-aware human review of the diff.** The owner inspected the code as it landed — not just runtime behaviour. Several real bugs were caught either by direct code reading or by running the site and noticing something visually off.

**Constraints stated up front and held.** "No external dependencies" was set at session zero and used as a refusal criterion every time a piece of code suggested pulling in a library. Without that explicit anchor, an LLM trends toward popular dependencies — that is what its training set rewards.

**Separate audit sessions, not inline self-review.** Eight audit passes ran in their own sessions with adversarial framing — the most recent combining manual code review with an external pentest run using OWASP ZAP and Semgrep. Building-mode and auditing-mode are different cognitive frames; an LLM mid-build does not reliably switch into auditor-mode mid-stream. Run separately, the audits caught issues the build sessions missed.

What this demonstrates: vibe-coded development can reach a hardened, production-grade result for a project of this size — given a domain-aware human in the loop and disciplined audit separation. What it does **not** demonstrate: that LLM-driven development is safe by default.
:::

::: contact
eyebrow: Get in touch
title: Questions, ideas, or a project?
lede: This form is fully wired — captcha, CSRF, rate-limit, honeypot. Send a real message.
directHeading: Or write directly
:::

::: end-cta
title: Pick a theme. Make it yours.
cta:
  label: See bundled themes
  href: #themes
body: Each demo is one layout file plus one stylesheet plus a markdown page. Strip the demos when you deploy your own site.
:::
