# Architecture

A tour of the codebase. After reading this you should be able to find
where any feature lives and trace a request end-to-end.

## Three subsystems

```
                        ┌───────────────────────┐
                        │  config/<section>.php │
                        └───────────┬───────────┘
                                    │
                        ┌───────────▼───────────┐
                        │  H42\WhimCMS\Kernel   │   request lifecycle
                        │ (lib/WhimCMS/Kernel)  │   bootstrap, dispatch
                        └───────────┬───────────┘
              ┌─────────────────────┼─────────────────────┐
              ▼                     ▼                     ▼
    ┌──────────────────┐ ┌──────────────────┐ ┌──────────────────────┐
    │  Content engine  │ │  Template engine │ │  Form pipeline       │
    │ lib/WhimCMS/     │ │ lib/WhimCMS/     │ │ lib/WhimCMS/         │
    │   Content/       │ │   Template/      │ │   Frontend/          │
    │  block-based MD  │ │  Twig-style      │ │     ContactController│
    │  parser + cache  │ │  tokenizer +     │ │   Form/Validator     │
    │                  │ │  directives +    │ │   Security/* +       │
    │                  │ │  annotations     │ │   Mail/* (transport) │
    └──────────────────┘ └──────────────────┘ └──────────────────────┘
                                  │
                        ┌─────────▼─────────┐
                        │  i18n/<lang>.json │   chrome microcopy
                        │  content/<lang>/  │   page MD + front-matter
                        └───────────────────┘
```

The three subsystems compose without depending on each other beyond
the shared `RenderContext` they all write to or read from.

## Repository layout

```
index.php                 three-line entry point

config/                   runtime config split per concern; loaded in
                          this order by Config::loadDir()
  app.php                 debug, log_level, template globals
  i18n.php                supported_langs, default_lang, detect_lang
  routes.php              URL segment → page slug, per language
  content.php             max_bytes, allowed_layouts, sweep interval
  seo.php                 canonical_origin/hosts, indexable, OG defaults,
                          JSON-LD person/organization
  images.php              widths whitelist, source caps, JPEG quality
  mail.php                recipient, from, daily cap, audit log knobs
  email_protection.php    obfuscation format + per-locale address paths
  contact.php             form-field schema + honeypot override
  security.php            csrf, captcha, rate_limit, blocklist tuning

  (Block-type schemas are NOT in config — each block partial declares
  its own schema via a `{@ block @}` annotation in its first lines.
  See _docs/BLOCKS.md.)

lib/                      PSR-4 autoloaded under namespace H42\WhimCMS\
  autoload.php            tiny PSR-4-shaped loader, no Composer
  WhimCMS/                everything below resolves under H42\WhimCMS\
    Kernel.php            orchestrator: bootstrap, dispatch, error handlers
    Config.php            dot-path config lookup, section-allowlist loader
    Log.php               level-filtered wrapper around error_log()
    Router.php            base-path detection + per-language URL→slug
    I18n.php              JSON dictionary loader, path-marker resolution,
                          optional editor-overlay merge
    I18nOverlay.php       editor-managed overlay loader (read + allowlist
                          filter + deep-merge); reads
                          content/_i18n_overlay.<lang>.json when present
    ErrorHandler.php      debug-aware fatal/exception/error handlers

    Content/              block-based content engine
      PageLoader.php      parse + validate + render + cache pipeline
                          (cache: HMAC-signed JSON in <hash>.cache files)
      Block.php           readonly value object: type, attrs, body
      Page.php            readonly value object: header, blocks
      BlockRegistry.php   allowed types, per-type schema, partial mapping
                          (populated at boot from `{@ block @}` annotations
                          harvested by the engine — no config file involved)
      AttributeParser.php strict mini-parser for front-matter & block attrs
      Markdown.php        safe-subset Markdown renderer
      CacheSweeper.php    orphan-aware cleanup of var/cache/content/
      ParseException.php  typed parse error with sourceLine
      ContentNotFoundException.php

    Template/             template engine
      Engine.php          entry: compile cache, render, boot annotation scan
      Tokenizer.php       template source → list<Token>; also scanAnnotations
      Token.php           readonly value object: type, payload, isClose,
                          closesWithType (block pairing carried on the token)
      Renderer.php        token stream → output, dispatches to directives
      Expression.php      sub-language: paths, literals, conditions
      Sanitizer.php       escape() / sanitizeEm() / stringify()
      Directive.php       interface for every directive (keywords + tokenize
                          + handles + render + renderBlock)
      AnnotationConsumer.php optional capability for directives that consume
                          {@ name @} compile-time annotations
      Annotation.php      readonly value object emitted by scanAnnotations
      BuiltInDirectives.php single canonical list of built-in directives
                          (Text, Var, Raw, Include, If, For, Html, Blocks,
                          Image, SafeHref, Lookup, Alias, Debug)
      Directives/         one file per directive type

    Frontend/             front-controller helpers — request-bound classes
                          the Kernel late-constructs and delegates to
      PageRenderer.php        renders a matched route or a 404 to the
                              response stream; owns layout/meta resolution
      ContactPostHandler.php  HTTP-glue for contact-form POSTs (size caps,
                              JSON vs form-encoded, master switch); hands
                              off to ContactController, then translates
                              the result back to a 303 / JSON / re-render
      ContactController.php   contact-form pipeline (the POST validator
                              + mailer chain itself)
      LanguageDetector.php    Accept-Language + URL-prefix → active lang
      RenderContext.php       builds the root render context from all sources

    Form/
      Validator.php       per-field validation rules

    Security/             site-hardening primitives — every class here is
                          security-relevant
      Secret.php          HMAC secret, lazy-init on first hit
      RateLimiter.php     sliding-window per IP-hash (request-level)
      Blocklist.php       soft strikes-and-block list (request-level)
      EmailProtection.php address obfuscation for HTML output
      Form/               form-submission defences — anything that gates
                          a POST before it reaches the controller
        Csrf.php          HMAC-signed token, scoped to a form via formId so
                          a token for one POST endpoint can't be replayed at
                          another. Client-binding strategy via
                          csrf.bind_strategy ('ip_ua' default, 'ua', 'none')
        Honeypot.php      derives the honeypot field name from the
                          application secret (per-installation, stable)
        Captcha/
          Captcha.php     proof-of-work challenge (128-bit salt)
          CaptchaStore.php  single-use replay protection
          CaptchaMissTracker.php  per-IP throttle for empty-captcha submits;
                          escalates to a Blocklist strike on threshold
      Http/
        RequestSecurity.php static helpers for inbound-request safety:
                          rejectUnsafeRequest (NUL/CR/LF in REQUEST_URI/
                          SCRIPT_NAME), clientIp (REMOTE_ADDR-only),
                          clientBindKey (CSRF client binding via Form/Csrf)

    Path/                 path-resolution + safety primitives
      PathResolver.php    boot-time: validate `paths.*` config, build
                          absolute paths, ensure var/ marker, realpath-contain
      AssetPathResolver.php runtime: URL `/<root>/.../*.<ext>` → realpath,
                          allowed-roots containment + extension whitelist

    Http/                 outbound-response helpers
      Responder.php       header + body emission (redirect, plain, json)
    Mail/                 transport-agnostic mailer
      Message.php Mailer.php Transport.php
      PhpMailTransport.php  default: PHP's mail()
      MailLog.php           audit log with TTL (off by default)
    Image/                server-side cropped/resized image generator,
                          driven entirely by the `{% image %}` directive
                          (no client-driven cache writes — the URL space
                          is bounded by what real templates request)
      Driver/
        GdDriver.php        wraps every GD call (load/probe/crop/save).
                            Single point of replacement if we ever swap
                            backend (ImageMagick, libvips, remote svc)
      CroppingProcessor.php focus-aware crop+resize math used by the
                            ImageDirective. Smart passthrough when
                            source already fits and no format change
      CroppedCache.php      hash-by-(path,mtime,params) cache layout
                            for /var/cache/img-cropped/
      CroppedServer.php     /img-c/<filename> endpoint. **Read-only.**
                            Files are written only by the
                            `{% image %}` directive at template-render
                            time, so the URL surface cannot fan-out
                            cache writes
      CroppedCacheSweeper.php  TTL-based orphan cleanup of
                            var/cache/img-cropped/
    Cache/                shared abstract base for cache sweepers
      Sweeper.php           sentinel-gated, lock-protected, root-confined,
                            lstat-based symlink/type rejection
    Seo/                  canonical / robots / sitemap / per-page SEO
      Origin.php Robots.php Sitemap.php PageSeo.php

content/                  page content (block compositions, root-level)
  <lang>/
    home.md  imprint.md  privacy.md  …
    demo-business.md demo-personal.md demo-trainer.md demo-dev.md
                          (bundled showcase only — strip for real deployments)

theme/                    The active visual identity. Configurable via
                          config/app.php → paths.theme. Default
                          `'theme'` (this folder); set to `'.'` to
                          collapse contents back to root for BC.
  templates/              Engine root (the template loader reads here)
    layout.html           core (default) layout — includes nav-core / footer-core
    layout-business.html  each demo theme has its own layout file …
    layout-personal.html  … + theme-<name>.css link + Google-Fonts <link>
    layout-trainer.html   + body class="theme-<name>" so CSS is fully scoped
    layout-dev.html
    pages/_404.html       legacy page-template, used only for the 404 path
    partials/             shared building blocks
      blocks/             every block-style partial registered with BlockRegistry
                          (hero, sub-hero, prose, pillars, feature-grid,
                          stat-row, voices, gallery, programs, pricing,
                          code-snippet, steps, theme-showcase, end-cta,
                          contact, legal-section, legal-sections)
      contact/            sub-includes used by the contact block:
                          form.html, error.html, consent-text.html
      nav-<theme>.html    one nav per theme (core/business/personal/trainer/dev)
      footer-<theme>.html one footer per theme
      theme-switcher.html floating switcher between bundled demo themes
      icons/glyph.html    inline-SVG icon switch (referenced by feature-grid etc.)
      section-head.html   eyebrow + title + lede helper used by many blocks
      picture.html        responsive-image emitter (used by image-bearing blocks)
      icon-arrow-out.html stand-alone arrow glyph used by buttons / links
    mail/                 mail body templates (subject + html + text)
    .htaccess             deny-all (templates are PHP-loaded, never URL-served)
  i18n/                   UI microcopy (nav, footer, forms, errors, meta fallback)
                          Independently configurable via paths.i18n; defaults to
                          theme/i18n so a theme-swap takes its dictionaries
                          along.
    en.json  de.json
    .htaccess             deny-all (PHP-loaded)
  styles/                 one cross-theme primitive + one stylesheet per theme
    base.css              reset + a11y helpers + reveal + lightbox primitives
    theme-core.css        default WhimCMS theme
    theme-business.css    bundled demo themes — each fully scoped under
    theme-personal.css    `body.theme-<name>` so themes never leak into each other
    theme-trainer.css
    theme-dev.css
  js/                     ES modules; main.js bootstraps everything
    main.js nav.js reveal.js contact-form.js lightbox.js email.js captcha.js
  assets/                 Theme identity assets (logos, favicons, vectors).
                          May ship raster images too — the image-server
                          accepts both /assets/ and /theme/assets/ as
                          source roots (see config/images.php → allowed_roots).
    favicon.svg
    whimcms-mark.svg

assets/                   Site-level raster content (photos, gallery, hero
                          stills). Always at root. Image-server source root.
                          Survives a theme swap.
  images/

var/                      Runtime state + caches (deny-all via var/.htaccess).
                          Path configurable via paths.var (relative under
                          rootDir only). On first boot, WhimCMS creates this
                          dir with a `.whimcms-state` marker; an existing
                          unmarked dir is refused to prevent accidental
                          ownership of pre-existing data.
  state/                  HMAC secret, rate limits, blocklist, mail log
  cache/img/              resized image variants
  cache/content/          parsed page content (HMAC-signed JSON, .cache)
  logs/                   optional project-local log file (only when
                          config/app.php → log_file is set)

theme.<name>/             Optional bundled theme alternates. The active
config.<name>/            theme is in `theme/`; alternatives sit beside it
content.<name>/           ready to swap by `mv`. `config.<name>/` holds
assets.<name>/            the theme-bound configs (routes, blocks, i18n,
                          contact, …); see _docs/INSTALL.md → "Theme-
                          config coupling" for the full mapping and the
                          security warning about adopting third-party
                          configs unaudited.
```

## Theme vs. config — what couples to what

Every config file falls into one of three classes for theme-swap
purposes:

| Class | Files | Behaviour on theme swap |
|---|---|---|
| **theme-bound** | `routes.php`, `i18n.php`, `contact.php`, `email_protection.php` | Active theme determines the right values — swap with the theme |
| **deployment-bound** | `seo.php`, `mail.php`, `security.php` | Operator decides; themes can ship sensible defaults but operator reviews |
| **site-bound** | `app.php`, `content.php`, `images.php` | Tied to the install/runtime, rarely swapped (with the small caveat that `images.allowed_roots` should include the active theme's asset root) |

Bundled-theme convention: ship theme-bound configs in `config.<name>/`
parallel to `theme.<name>/`. End user copies into `config/` after
review. See `_docs/INSTALL.md` for the full activation procedure
and the **never-adopt-blindly** security note.

## Request lifecycle

Walk through `lib/WhimCMS/Kernel.php`. Every request follows one of these paths.

### 1. Bootstrap (once per request)

`Kernel::bootstrap()`:

1. Load every `config/<section>.php` via `Config::loadDir()`. Sections are
   allowlisted in `Config::EXPECTED_SECTIONS`; an unexpected file in the
   directory is ignored, a missing required section throws at boot.
2. Set log level via `Log::setLevel()`.
3. Install error handlers (`installErrorHandlers()`):
   - `register_shutdown_function` — fatal-error safety net (catches
     PHP errors that bypass `set_exception_handler`).
   - `set_exception_handler` — produces the debug/production 500 page.
   - `set_error_handler` — re-throws PHP notices/warnings as
     `\ErrorException` so they don't slip through silently.
4. Resolve `paths.*` from config: validate each value against the
   strict allowlist regex (no `..`, no leading `/`, no control
   chars), build absolute paths under rootDir, run `ensureVarDir()`
   (creates `var/` with `.whimcms-state` marker on first boot, or
   refuses if the dir exists without the marker), then realpath-
   contain every path. Optionally route logs to a project-local file
   when `log_file` is set.
5. Instantiate `Engine` against `<paths.theme>/templates`. The engine
   constructor self-wires:
   - Instantiates every directive in `BuiltInDirectives::all()` (Text,
     Var, Raw, Include, If, For, Html, Blocks, Image, SafeHref,
     Lookup, Alias, Debug).
   - Builds keyword → directive and token-type → directive maps with
     conflict checks at boot.
   - Walks `partials/blocks/*.html`, harvests every `{@ block @}`
     annotation via `Tokenizer::scanAnnotations()`, and dispatches each
     to `BlocksDirective::consumeAnnotation()`, which registers the
     schema in the engine's `BlockRegistry`.

   Then set `I18n` directory to `paths.i18n`, derive `THEME_URL` (the
   URL prefix for theme-served assets), load supported languages and
   routes, and instantiate `LanguageDetector` from
   `(detect_lang, default_lang, supported_langs)` for both the
   dispatcher's redirect targets and the renderer's 404 path-language.
6. `bootstrapContent()`:
   - Construct `PageLoader` against `paths.content` with the
     configured size limit, layout allowlist, and the engine's
     populated `BlockRegistry` (for parse-time validation of `.md`
     content).

   The Kernel itself does not register any directive or block type —
   both are owned by the engine and self-discovered. It also does not
   render anything — see *Dispatch* below for the per-request
   late-construction of `PageRenderer` and `ContactPostHandler`.

### 2. Dispatch

`Kernel::dispatch()`:

1. Sanitize `REQUEST_URI` / `SCRIPT_NAME` via
   `Security\Http\RequestSecurity::rejectUnsafeRequest()` — a NUL/CR/LF
   in either field exits with `400 — Bad Request` before any path-parser
   touches them.
2. Detect base path (`Router::detectBasePath`).
3. Special-case routes:
   - `img-c/<filename>`    → `Image\CroppedServer::handle()`
                             (read-only; serves files the
                             `{% image %}` directive wrote earlier)
   - `robots.txt`          → `Seo\Robots::send()`
   - `sitemap.xml`         → `Seo\Sitemap::send()`
4. Resolve URL via `Router::resolvePath()`. Result: `(lang, slug, …)`,
   or `null` for 404, or a redirect tag (root → `/<lang>/`, bare
   segment → `/<lang>/segment`, legacy `.html` → canonical pretty URL).
   Redirects use `LanguageDetector::detect()` to pick the target.
5. Late-construct `Frontend\PageRenderer` with the resolved base path
   plus the bootstrap-time dependencies (engine, page loader, language
   detector). The renderer lives only for the rest of the request.
6. Branch:
   - `$resolved === null`            → `pageRenderer->renderNotFound($path)`
   - `$resolved['legacyHtml']`       → 301 redirect to canonical URL
   - `POST` to home                  → late-construct
                                       `Frontend\ContactPostHandler`,
                                       hand off; see *Form pipeline*
   - otherwise                       → `pageRenderer->render($resolved)`

### 3. PageRenderer::render (the happy path)

`Frontend\PageRenderer::render()`:

1. Load i18n: `I18n::load($lang, $basePath, $singleLang)` returns the
   active dictionary with path markers (`~/…`, `^/…`) already
   resolved. If `content/_i18n_overlay.<lang>.json` exists, its
   allowlisted sections (see `config/i18n.php → i18n_overlay.allowed_sections`)
   are deep-merged on top via `I18nOverlay` — the editor-controlled
   layer for nav structure, page-meta overrides, and footer copy
   without touching theme files.
2. Try to load page content: `pageLoader->load($lang, $slug, $basePath, $singleLang)`.
   - On `ContentNotFoundException`: fall back to legacy template path
     (only `_404.html` reaches this today; every content page has an `.md`).
   - On `ParseException` or other error: propagates to the exception
     handler, debug page or 500 response.
3. Resolve meta: prefer `Page::meta()` (front-matter), fall back to
   i18n `meta.<slug>`.
4. Resolve layout: `Page::layout()` → `PageRenderer::layoutTemplateName()`
   maps `default` → `layout`, `<other>` → `layout-<other>`. Whitelist
   enforced.
5. Build `RenderContext`: see below.
6. Render: `engine->render($layoutName, $context)` → response body.

### 4. ContactPostHandler::handle (when the contact form is submitted)

`Frontend\ContactPostHandler::handle()`:

1. Detect content-type (form-encoded vs JSON).
2. Honour the `contact.enabled` master switch — when off, drop POST at
   the door (404 JSON for AJAX clients, full 404 page rendered via
   `PageRenderer::renderNotFound` otherwise). No validator, no CSRF
   check, no captcha strike, no log entry.
3. Read body with explicit byte limits — `Content-Length` is a
   client hint, not authoritative. JSON path measures the actual
   `php://input`; form path relies on PHP's `post_max_size` plus a
   fast-path header check.
4. Build `ContactController::fromConfig(...)`, hand off `$post`.
5. The controller validates → if errors, return them; if OK,
   send mail via `Mailer`, return success.
6. Render decision:
   - JSON request → `Responder::contactJson()`
   - Success → 303 redirect to `/<lang>/?sent=1#contact` (PRG)
   - Validation failed → `pageRenderer->render($resolved, $formState)`
     so the contact block re-renders with errors and field values.

## RenderContext: the shared state

`lib/WhimCMS/Frontend/RenderContext.php → build()` is the single source
of truth for what every template (block or otherwise) sees at render
time. It returns one big array, merged with `globals` from config.

Keys it sets:

| Key | Source | Used by |
|---|---|---|
| `CURRENT_LANG` | `I18n::load()` | All templates: nav, footer, forms, contact |
| `LANG`, `LANGS` | Kernel | Layout `<html lang>`, language switcher |
| `PAGE` | Kernel | `<body data-page>`, nav-active state |
| `BASE` | Router | Asset URLs |
| `META` | merged front-matter + i18n | `<title>`, OG, Twitter, description |
| `BLOCKS` | `Page::blocks` (or null) | `{% blocks %}` directive |
| `PAGE_TEMPLATE` | Kernel | `_404.html` fallback path |
| `MULTI_LANG`, `LANG_SWITCH` | Router | Header language switcher |
| `URLS`, `CURRENT_PAGE_URL` | Router | All cross-links |
| `SEO` | `Seo\Origin` + Router | Canonical, hreflang, OG, Twitter, JSON-LD |
| `EMAIL` | `EmailProtection::buildContext()` | Contact block |
| `CAPTCHA` | `Captcha::issue()` | Contact form |
| `FORM_TOKEN` | `Csrf::issue()` (with `formId` scope, default `'contact'`) | Contact form |
| `FORM_*` | POST handler | Contact form re-render |
| `HONEYPOT_FIELD` | `Honeypot::resolveFieldName()` | Contact form (`name`/`id`/`for` of the hidden honeypot input) |

The block partials inherit this whole map; in the BlocksDirective
the only additions are `attrs` (set to the block's attribute map
parsed from `.md`) and `body` (pre-rendered Markdown HTML).
Everything else in the block partial — `CURRENT_LANG.x`, `URLS.about`,
`EMAIL.contact`, `CACHE_BUSTER` — comes from the parent context. The
two slots are deliberately separate: `attrs.x` is local block data,
`CURRENT_LANG.x` is the global language dictionary.

## Content pipeline (cold path)

When `PageLoader::load()` doesn't hit its cache:

```
content/<lang>/<slug>.md
        │
        ▼
PageLoader (lib/WhimCMS/Content/PageLoader.php)
  1. lang/slug regex check
  2. realpath containment under content/
  3. file_get_contents (size cap, UTF-8 hard check)
  4. front-matter split (---…--- block at top, optional)
  5. block stream split (::: <type> … :::)
  6. AttributeParser per attr block
  7. BlockRegistry::validate (required keys, no extras)
  8. PageLoader::resolvePaths (~/…, ^/…)
  9. Markdown::render per block body
 10. cache write (HMAC-signed JSON to var/cache/content/<sha256>.cache)
        │
        ▼
Page { header, blocks: list<Block> }
```

The Markdown renderer is a safe-subset, allowlist-driven HTML emitter —
no inline HTML, scheme-allowlisted links, htmlspecialchars on every
literal. See [TEMPLATING.md](TEMPLATING.md#markdown-safe-subset).

## Hot path: cache hit

`PageLoader::loadFromCache()` reads `var/cache/content/<key>.cache`:

- Cache key: `sha256(realpath | basePath | langRoot | singleLang)`.
- File format: `<hex-hmac>\n<json-payload>` — first line is HMAC-SHA-256
  of the payload computed with the application secret, followed by the
  JSON itself. Extension is `.cache`, never executed by Apache/PHP.
- Read pipeline: `file_get_contents` → split at first newline → verify
  HMAC with `hash_equals` (constant-time) → `json_decode`. The payload
  is **never** `include`d. A planted file with the wrong HMAC is dropped
  before its bytes reach any parser, so any hypothetical write-primitive
  elsewhere in the stack cannot escalate to code execution. Forging a
  valid cache file requires the application secret in `var/state/secret`,
  which is web-deny'd.
- The decoded array carries the source `mtime`. On read we compare
  against the live file's `mtime`; mismatch → treat as miss,
  regenerate.
- Atomic write via `tempfile + rename` so a partial write can never
  poison readers. Temp file is `chmod 0o600` before the rename.
- Any read failure (HMAC mismatch, malformed JSON, IO error, mtime
  drift) is caught and treated as a miss — last-resort safety.

## Template pipeline

Walk through `lib/WhimCMS/Template/`:

```
templates/<name>.html              source
        │
        ▼
Tokenizer  ────►  list<Token>      one per text run / directive / variable
        │
        ▼
Renderer   ────►  output bytes     dispatches each token to its directive
                                   pairs block-open with block-close,
                                   collects body tokens for block dirs
```

Each `Directive` (subclass under `lib/WhimCMS/Template/Directives/`) handles a
specific token type:

| Directive | Token(s) | Syntax |
|---|---|---|
| `TextDirective` | `text` | Plain text run |
| `VarDirective` | `var` | `{{ expr }}` — HTML-escaped |
| `RawDirective` | `raw` | `{!! expr !!}` — em-only sanitiser |
| `HtmlDirective` | `html` | `{% html: expr %}` — verbatim, only on trusted values |
| `IncludeDirective` | `include` | `{% include: 'path', attrs: expr %}` |
| `IfDirective` | `if_open`+`if_close` | `{% if: cond %} … {% endif %}` (no else; use two negated ifs) |
| `ForDirective` | `for_open`+`for_close`, `for_inline_include` | Block: `{% for: expr, as: 'name' %} … {% endfor %}`. Inline-include: `{% for: expr, as: 'name', include: 'path' %}`. `as:` is mandatory in both forms |
| `BlocksDirective` | `blocks` | `{% blocks %}` — iterate context BLOCKS, render each via its registered partial. Also implements `AnnotationConsumer` to ingest `{@ block @}` schema declarations at boot |
| `ImageDirective` | `image` | `{% image: '<asset-path>', width: N, height: N, focusX: F?, focusY: F?, format: '<fmt>'? %}` (crop-to-fit) or `{% image: '<asset-path>', maxWidth: N?, maxHeight: N?, format: '<fmt>'? %}` (scale-only). Emits a URL string for use in `<img src="…">`. Cache lives in `var/cache/img-cropped/` and is served by `Image\CroppedServer` at `/img-c/<filename>` |

The token cache lives in `Engine::$compiled` keyed by template name —
each template is tokenized once per request.

See [TEMPLATING.md](TEMPLATING.md) for the full directive reference
and how to add a new one.

## Why two engines instead of one?

The template engine renders HTML from templates + context. The content
engine parses Markdown + composition into `Block` objects. They're
different concerns:

- The template engine is **stateless and synchronous**: tokens in,
  bytes out. Same template + same context → same output.
- The content engine has an **on-disk cache** and per-page state
  (mtime, layout, meta, list of blocks) — it owns "what's on this page".

Treating them as separate libraries (each in its own `lib/<name>/`
namespace) makes the boundary explicit. The template engine doesn't
know what a Block is — `BlocksDirective` does, and it lives in
`lib/WhimCMS/Template/Directives/` because it's a directive, but it only
references `H42\WhimCMS\Content\Block` via the registry, not the loader.

## Where to look for X

| Need to … | Look at |
|---|---|
| Add a new page | [CONTENT.md](CONTENT.md) — write `.md`, add route |
| Add a new block type | [BLOCKS.md](BLOCKS.md) — one new partial under `templates/partials/blocks/<type>.html` with a `{@ block @}` header. No config edit. |
| Add a new directive | [TEMPLATING.md](TEMPLATING.md) — implement `Directive` (or also `AnnotationConsumer`), add to `BuiltInDirectives::all()`. The Tokenizer is keyword-agnostic and needs no edit. |
| Add a new language | [CONTENT.md](CONTENT.md) — `supported_langs` + new files |
| Add a new theme | one `templates/layout-<name>.html`, one `styles/theme-<name>.css` (scope under `.theme-<name>`), one `templates/partials/nav-<name>.html` + `footer-<name>.html`, one `content/<lang>/<slug>.md` with `layout: <name>` front-matter, plus the layout name in `config/content.php → allowed_layouts` |
| Strip the bundled demos | delete the four `demos/*` routes from `config/routes.php`, then delete `templates/layout-{business,personal,trainer,dev}.html` + matching nav/footer partials + matching `theme-*.css` + matching `content/en/demo-*.md` |
| Change the contact form | `templates/partials/contact/form.html` + `config/contact.php → contact.fields` |
| Adjust validation rules | `config/contact.php → contact.fields.<field>` (Validator picks them up) |
| Disable the contact pipeline entirely | `config/contact.php → contact.enabled = false` (POSTs return 404 immediately, before any gate runs) |
| Tighten security | [SECURITY.md](SECURITY.md) — defence layers + audit cadence |
| Trace a 500 | `config/app.php → debug => true`, reload — full stack trace inline; an `X-H42-Cache: hit\|miss\|write-failed\|no-content` header also appears on rendered pages |
