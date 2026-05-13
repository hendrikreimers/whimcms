# Architecture

A tour of the WhimAdmin codebase. After reading this you should
recognise where any feature lives and trace a request end-to-end.

## Subsystems

```
                ┌────────────────────────────┐
                │   whimadmin/index.php      │
                │   3-line entry, hands off  │
                └─────────────┬──────────────┘
                              ▼
                ┌────────────────────────────┐
                │  H42\WhimAdmin\Kernel       │   bootstrap → dispatch
                │  (lib/WhimAdmin/Kernel.php) │
                └─────────────┬──────────────┘
   ┌──────────┬───────────┼───────────┬──────────┬──────────┬─────────┐
   ▼          ▼           ▼           ▼          ▼          ▼         ▼
 ┌──────┐┌────────┐ ┌──────────┐ ┌─────────┐ ┌────────┐ ┌────────┐
 │ Auth ││Content │ │  Pages   │ │Settings │ │ Assets │ │ View / │
 │      ││(blocks)│ │ (tree)   │ │(legacy) │ │        │ │Renderer│
 └──────┘└────────┘ └──────────┘ └─────────┘ └────────┘ └────────┘
       │          │            │           │
       │          ▼            ▼           ▼
       │   ┌────────────────────────────────────────┐
       │   │  WhimCMS core (read-only consumed)     │
       │   │  H42\WhimCMS\…                         │
       │   │   • AttributeParser  (content parse)   │
       │   │   • Tokenizer        ({@ block @} scan)│
       │   │   • Template\Engine  (admin views)     │
       │   │   • Security\Form\Csrf, RateLimiter,   │
       │   │     Secret, RequestSecurity            │
       │   │   • Mail\PhpMailTransport              │
       │   └────────────────────────────────────────┘
       ▼
 ┌──────────────────────────────────────────────┐
 │  whimadmin/var/  (own runtime state, deny-all,│
 │                   marker-gated, separate from │
 │                   core's var/)                │
 └──────────────────────────────────────────────┘
```

## Repository layout

```
whimadmin/
  index.php                  3-line entry point
  .htaccess                  rewrite + deny + admin headers

  lib/WhimAdmin/             PSR-4 autoloaded under H42\WhimAdmin\
    autoload.php             tiny PSR-4 loader
    Kernel.php               bootstrap, dispatch, auth-guard wiring
    Config.php               reads whimadmin/config/app.php
    ErrorHandler.php         debug-aware 500 / set_error_handler
    Path/PathResolver.php    var/ marker, deny-all .htaccess

    Auth/
      UserStore.php          single-user record, Argon2id hash
      SetupTokenStore.php    one-shot setup token (HMAC + plaintext sidecar)
      OtpStore.php           per-user 6-digit code, HMAC-stored, TTL
      OtpMailer.php          composes + transports OTP via core Mailer
      Session.php            stateful, HMAC-signed cookie, IP+UA bound
      LoginController.php    GET/POST /login (step 1: user/pass)
      OtpController.php      GET/POST /otp   (step 2: mail code)
      LogoutController.php   POST /logout
      SetupController.php    GET/POST /setup (first-run only)
      FirstRunController.php pre-setup dispatcher (when no user yet)

    Http/
      Request.php            sanitised view of $_SERVER/$_GET/$_POST
                             (recursive sanitiseTree for nested form data;
                              isHttps + siteRoot helpers)
      Response.php           value object → emit headers + body
      Router.php             literal-segment table, first-match wins
      Csrf.php               wraps core Csrf; formId scope `whimadmin:*`
      CookieJar.php          single point for cookie policy
                             (HttpOnly, Secure-on-HTTPS, SameSite=Strict)

    View/Renderer.php        wraps the core Engine pointed at views/
                             — adds page() helper that wraps inner
                             template inside layout via {% html: CONTENT %}
    Audit/Log.php            append-only newline-JSON log; HMAC-keyed IP

    Maintenance/
      RecyclerSweeper.php    sentinel-gated auto-sweep across the
                             page-recycler, page-history, and asset-
                             recycler trees. Runs once per configured
                             interval (default 1 day) on the first
                             authed admin request that arrives after
                             the interval has elapsed.

    Content/                 page editing
      Block.php              mutable DTO {type, attrs, body}
      BlockSchema.php        DTO {label, fields, required, bodyField}
      FieldSchema.php        one field's UI metadata (type, options, …)
      BlockSchemaLoader.php  resolves schema per block type:
                             {@ block @} (authoritative names) +
                             config/blocks/<type>.json (typed)
      IconLibrary.php        scrapes icon names from core's glyph.html
      PageDocument.php       round-trip-able MD ↔ block tree
      PageRepository.php     load + save + delete (via Recycler)
      HistoryStore.php       per-page version snapshots, prune to N
      Recycler.php           soft-delete to content/.recycler/
      ClipboardStore.php     per-user single-block clipboard for cut/paste
      FormRenderer.php       PHP-side recursion, dispatches to
                             views/fields/<type>.html partials
      FormDecoder.php        $_POST → PageDocument (preserves DOM order)
      PagesController.php    GET /pages/blocks + POST (block editor only;
                             page-meta moved to PagesTreeController)

    Config/
      PhpArrayWriter.php     safe writer for core's config/*.php
                             (whitelisted to TARGET_ROUTES + TARGET_I18N).
                             Routes are mutated through Pages/RoutesUpdater
                             (single in-process owner); i18n.php stays
                             operator-domain (SFTP-edited) — no admin route
                             exposes it.

    Assets/
      AssetBrowser.php       list, mkdir, upload, rename, recycle, purge,
                             allImagePaths (autocomplete + picker source)
      AssetsController.php   /assets, /assets/upload|mkdir|rename|delete,
                             /assets/recycler, /assets/recycler/purge

    Pages/                   tree-view editor — TYPO3-style split-view
      PageMetaFieldSchema.php  one field with type + target namespace
                               (overlay: / routes: / frontmatter:)
      PageType.php             one tree-node flavour: slug/href/anchor/folder
      PageTypeSchemaLoader.php loads whimadmin/config/page-types/*.json,
                               cross-validates targets against an allowlist
      TreeNode / TreeSection / LanguageTree / TreeView  DTOs
      TreeAggregator.php       read-only assembly of the tree from
                               overlay + routes + .md probe; content-
                               hash version for optimistic locking
      OverlayWriter.php        strict-shape-validated atomic JSON write
                               for content/_i18n_overlay.<lang>.json
                               (HrefSanitizer on every href, path-marker
                               support, depth + item cap, control-byte
                               reject)
      RoutesUpdater.php        higher-level ops on routes.php via the
                               existing PhpArrayWriter (renameSlug,
                               removeBySlug, changeUrl, addEntry)
      TreeMutator.php          single mutation surface — create/move/
                               rename/retype/delete/save. Every public
                               method wraps an *Impl in withLock()
                               (flock on whimadmin/var/state/
                               tree-mutation.lock). Multi-file commits
                               record reverse-order undo callbacks on
                               TreeMutationLog so a downstream failure
                               best-effort-restores prior state.
      TreeMutationLog.php      LIFO rollback queue
      TreeInternalException.php  publicMessage + debugDetail — internal
                                 structural failures gated by debug flag
      TreeVersionConflictException.php  thrown when client's
                                        expected version no longer matches
      PageMetaFormDecoder.php  POST/JSON → bucketed by target namespace,
                               per-field validators (slug / url-path /
                               anchor / layout / HrefSanitizer-on-link)
      PagesTreeController.php  HTML shell (GET /pages) +
                               JSON reads (GET /pages/tree, /tree/node,
                               /tree/types). CSRF token shipped inline
                               with the tree response.
      PagesTreeMutationController.php  six JSON POST endpoints; CSRF
                                       via X-CSRF-Token header (or
                                       body._csrf); error fan-out into
                                       409 / 400 / 500 by exception type.

  views/                     HTML templates (rendered via core Engine)
    layout.html              admin chrome + nav + module script
    setup-required.html      pre-setup landing
    setup.html, login.html, otp.html, dashboard.html
    pages/edit.html, block.html, block-unknown.html,
          recycler.html, history.html
    pages-tree/index.html        TYPO3-style split-view editor shell
                                 (tree on the left, page-meta form on
                                 the right). Hydrated by js/pages-tree/.
    fields/_router.html → not used; FormRenderer dispatches in PHP
    fields/text.html, textarea.html, markdown.html, image.html,
           link.html, bool.html, number.html, select.html, icon.html,
           list.html, list-item.html, map.html
    assets/list.html, recycler.html
    mail/otp.html, otp-text.html, otp-subject.html

  config/
    app.php                  operator-tunables (debug, session, OTP,
                             rate_limit, setup, content, mail prefix)
    blocks/<type>.json       per-block-type form schema (Phase 3 sidecars)

  styles/admin.css           single stylesheet, no preprocessor
  js/                        ES modules, no bundler
    main.js                  imports field widgets + DnD + asset picker
                             + global data-confirm handler
    fields/list.js           dynamic add/remove + reindex
    fields/markdown.js       Markdown toolbar (B/I/H2/H3/H4/list/link/code)
    blocks-dnd.js            drag-and-drop block reordering + renumber
    asset-picker.js          modal picker fed by <datalist id="asset-paths">
    pages-tree/              TYPO3-style page-tree editor (entry: main.js)
      api.js                 fetch wrapper, holds csrf + treeVersion
      render.js              clones view templates → DOM (no innerHTML)
      editor.js              right-pane form (text/bool/select/layout/url-path)
      dnd.js                 HTML5 DnD: before/after/into, new-page source
      dialogs.js             native <dialog> prompts/selects/confirms
      util.js                cssEscape helper

  var/                       runtime state, deny-all, marker-gated
    .whimadmin-state         ownership marker
    state/secret             HMAC secret (separate from core's)
    state/auth/user.json     single-user record
    state/auth/sessions/     active sessions
    state/auth/otp/          pending OTP codes
    state/clipboard/         per-user block clipboard
    state/ratelimit/         sliding-window state
    state/setup-token.json   first-run HMAC record
    state/setup-token.txt    first-run plaintext sidecar (operator reads)
    logs/audit.log           append-only audit trail
```

## Request lifecycle

`Kernel::run()` always does `bootstrap()` then `dispatch()`.

### Bootstrap (once per process)

`Kernel::bootstrap()`:

1. Load `whimadmin/config/app.php` via `Config::loadDir()`.
2. Load the WhimCMS core's `config/` via `CoreConfig::loadDir()` —
   needed for `mail.from`, `paths.theme`, `routes`, `supported_langs`.
3. Install the admin's `ErrorHandler` (debug-aware).
4. `PathResolver` validates / creates `whimadmin/var/` with the
   `.whimadmin-state` marker, drops a deny-all `.htaccess` inside it.
5. `Secret::load()` — reads or creates `var/state/secret`. Separate
   from the core's secret so a hypothetical compromise of one
   doesn't cross over.
6. Wire long-lived services: `Renderer` (engine over `views/`),
   `AuditLog`, `RateLimiter`, `UserStore`, `SetupTokenStore`,
   `OtpStore`, `Session`, `OtpMailer`.
7. `bootstrapContent()` adds: `PageRepository` (with `HistoryStore`
   + `Recycler`), `BlockSchemaLoader`, `FormRenderer` (with
   `IconLibrary`), `ClipboardStore`, `PhpArrayWriter`, `AssetBrowser`.

### Dispatch (per request)

`Kernel::dispatch()`:

1. `Request::fromGlobals()` sanitises the URI via core's
   `RequestSecurity::rejectUnsafeRequest` and builds a typed view of
   `$_SERVER`/`$_GET`/`$_POST`. The post / query trees are
   recursively sanitised (`Request::sanitiseTree`, depth-cap 16) so
   nested form payloads (`block[N][attr][items][M][title]`) survive
   intact while non-string non-array leaves are dropped.
2. If `UserStore::exists()` is false → `dispatchFirstRun()`:
   `FirstRunController` ensures a setup token, dispatches
   `/setup?token=…` GET/POST, falls back to the "Setup required"
   page for everything else.
3. Otherwise, build per-request services bound to the current
   client surface — `CookieJar::fromRequest(req)`, `Csrf` (with
   the request's IP+UA), session loaded from cookie.
4. Build the route table via `buildRouter()`. Authed routes are
   guarded by an `$authGuard` closure that returns null on pass-
   through, a `Response` redirect on fail. The pattern at every
   call site is `($g = $authGuard($r)) ?? $controller->method($r)`.
5. Match `(method, path)` against the table. No match → 404.
6. Invoke the handler, get a `Response`, send it.

### Auth guard semantics

```php
$authGuard = function (Request $r) use ($session): ?Response {
    if ($session === null)              return Response::redirect($r->url('login'));
    if ($session['stage'] !== 'authed') return Response::redirect($r->url('otp'));
    return null;
};
```

The closure captures the per-request `$session` array (or null).
`$session` is loaded once in `dispatch()` from the cookie via
`Session::load($cookie, ip, ua)` — a single point that:

- Verifies the cookie HMAC.
- Loads the session record JSON.
- Checks idle + absolute timeouts.
- Verifies bind-key still matches (IP+UA per default).
- Updates `last` timestamp atomically.
- Returns null on any failure (expired, bind-mismatch, malformed, …).

## RenderContext (admin's flavour)

The admin doesn't run the core's `Frontend\RenderContext`. Each
controller passes its own context dict to `Renderer::page($inner,
$context, $layout = 'layout')`, which:

1. Renders `$inner` (e.g. `pages/edit` for the block-editor, or `pages-tree/index` for the page-tree) with `$context`.
2. Sets `$context['CONTENT'] = <the inner HTML>`.
3. Renders `layout` with the augmented context. The layout uses
   `{% html: CONTENT %}` to embed the inner output verbatim.

The layout doesn't need block-discovery; the engine still scans
`partials/blocks/*.html` (none under `views/`) at boot but finds
nothing — graceful no-op.

## Storage zones

| Zone | Path | Owner | Web-served? |
|---|---|---|---|
| Admin runtime | `whimadmin/var/` | WhimAdmin (own marker) | No (deny-all .htaccess) |
| Admin views | `whimadmin/views/` | dev | No (deny-all) |
| Admin lib | `whimadmin/lib/` | dev | No (deny-all) |
| Admin config | `whimadmin/config/` | operator | No (deny-all) |
| Admin static | `whimadmin/{styles,js}/` | dev | Yes (Apache static) |
| Site content | `<core>/content/` | WhimCMS | No (deny-all) |
| Site i18n | `<core>/<paths.i18n>/` | WhimCMS | No (deny-all) |
| Site theme | `<core>/<paths.theme>/` | WhimCMS | Yes (styles/js/assets) |
| Site assets | `<core>/assets/` | WhimCMS | Yes (raster) |
| Site config | `<core>/config/` | WhimCMS | No (deny-all) |

WhimAdmin **writes to**:

- Its own `whimadmin/var/`
- `<core>/content/<lang>/<slug>.md` (page save)
- `<core>/content/.history/…` (snapshots)
- `<core>/content/.recycler/…` (soft delete)
- `<core>/content/_i18n_overlay.<lang>.json` (editor-owned nav/footer
  overlay; written via `Pages/OverlayWriter`)
- `<core>/config/routes.php` (PhpArrayWriter, whitelisted; routes are
  set via the per-page URL field in the tree editor)
- `<core>/assets/…` (upload, mkdir, rename, recycle)
- `<core>/assets/.recycler/…` (asset soft delete)

`<core>/config/i18n.php` and `<core>/<paths.i18n>/<lang>.json` are
operator-domain — they're edited via SFTP or theme distribution.
WhimAdmin's PhpArrayWriter does keep an `i18n` target in its
whitelist (dormant capability for a possible future settings
surface) but no controller currently invokes it.

WhimAdmin **never writes to**:

- `<core>/lib/`, `<core>/index.php`, `<core>/.htaccess`,
  `<core>/_htaccess_production`
- `<core>/var/` (the public-site cache invalidates by mtime, which
  WhimAdmin's `rename` naturally bumps)
- `<core>/<paths.theme>/` (template / styling work is dev-side)
- Other `<core>/config/*.php` files (operator-managed via SFTP)

## Reuse from the WhimCMS core

Read-only consumed via PSR-4. WhimAdmin is **layered above** the
core; the core has no awareness of admin code. Anything WhimAdmin
needs is imported at call-site, never patched.

| Core class | Used for |
|---|---|
| `Config` | Read core's runtime knobs (paths, mail, supported_langs) |
| `AttributeParser` | Parse the `key: value` mini-format inside `.md` and `{@ block @}` annotations — same authoring rules as the public site |
| `ParseException` | Typed error with `sourceLine` for clear save-error messages |
| `Template\Engine` | Render admin views (separate instance over `whimadmin/views/`) |
| `Template\Tokenizer` | Scan `{@ block @}` from theme partials (`scanAnnotations` is public) |
| `Security\Secret` | Init / read the admin's own HMAC secret (separate file in `whimadmin/var/state/secret`) |
| `Security\RateLimiter` | Sliding-window per-IP throttle on login + OTP |
| `Security\Form\Csrf` | HMAC-signed form tokens, scope-bound |
| `Security\Http\RequestSecurity` | Reject NUL/CR/LF in REQUEST_URI before any path code |
| `Mail\Message`, `Mail\PhpMailTransport` | OTP delivery via the core's mailer (envelope `mail.from` shared) |
| `Content\ContentNotFoundException` | Distinguish 404 from parse errors in the editor |

## Why the admin is a separate process root

- **Security blast radius.** A bug in admin code cannot affect a
  visitor that never hits `/whimadmin/`.
- **Independent dependency upgrades.** Core stays minimal (no auth,
  no editing). Admin is free to grow.
- **Operator can disable admin entirely.** `mv whimadmin
  whimadmin.disabled` and the public site is unaffected.
- **Single-binary feel preserved.** No Composer, no build step,
  same PHP 8.1+ runtime.
