# Install

Five-minute deployment from a fresh server.

## Requirements

- **PHP 8.1+** with extensions: `pcre`, `mbstring`, `json`, `filter`.
  (8.1 minimum because the engine uses `readonly` properties.)
  `gd` recommended (the image-resize endpoint falls back to streaming
  the original if missing). No Composer, no other PHP deps.
- **Apache** with `mod_rewrite` and `mod_headers` enabled. Other web
  servers work in principle (the routing happens in PHP), but the
  shipped `.htaccess` files are Apache-syntax — you'd translate them.
- **Writable** `var/state/` and `var/cache/` for the PHP process.
  Typically owned by the web-server user, mode 0700.
- **HTTPS at the host level** before the site goes live. The shipped
  `_htaccess_production` enables HSTS in stages; see
  [SECURITY.md](SECURITY.md) for the rollout sequence.

## Steps

1. **Upload everything** except files matching `_*` (those are
   alternative configs the host shouldn't serve).
2. **Pick the right `.htaccess`**:
   - Production / standalone domain → rename `_htaccess_production`
     to `.htaccess`.
   - Subfolder under an existing site whose parent `.htaccess` already
     handles security headers → use the bundled `.htaccess` (smaller,
     project-scoped).
3. **Make `var/` writable.** The runtime creates these on first hit:
   - `var/state/secret` — HMAC secret for CSRF + captcha tokens
   - `var/state/rate-limit/` — sliding rate-limit windows
   - `var/state/blocklist` — soft IP blocklist
   - `var/state/captcha-used/` — single-use markers
   - `var/state/captcha-miss/` — per-IP empty-captcha throttle counters
   - `var/state/mail-log/` — daily mail counters
   - `var/state/.cache-sweep-content` — sentinel for the content-cache sweeper
   - `var/state/.cache-sweep-img` — sentinel for the image-cache sweeper
   - `var/cache/img-cropped/<basename>-<hash>.<ext>` — cropped image variants written by `{% image %}` at template-render time
   - `var/cache/content/<hash>.cache` — parsed page content (HMAC-signed JSON)
4. **Edit `config/<section>.php`** — config is split per concern; each
   row below names the file the key lives in. These are the fields you
   must set for production:

   > **Boot-blocker — set this first.** `seo.canonical_hosts` (or
   > `seo.canonical_origin`) in `config/seo.php`. With both empty,
   > `Seo\Origin::resolve()` throws and **every request returns 500**.
   > The request `Host:` header is deliberately never used as a
   > fallback (see [SECURITY.md](SECURITY.md) → "SEO trust boundary").
   > Local dev: `'canonical_hosts' => ['localhost']`. Production: set
   > `canonical_origin` to your absolute HTTPS origin (e.g.
   > `'https://example.com'`).

   | Key | File | Why |
   |---|---|---|
   | `mail.enabled` | `config/mail.php` | **Default `false` — opt-in.** A fresh deploy ships with mail disabled so a wrong `recipient` / `from` value cannot accidentally send mail to a placeholder address before review. Submissions still run through every gate (CSRF, captcha, validator); the visitor sees a clear `mail_failed` banner — that's the signal to come here, set the addresses, and flip this to `true`. |
   | `mail.recipient` | `config/mail.php` | Where contact-form submissions land |
   | `mail.from` / `mail.from_name` | `config/mail.php` | Envelope sender (must align with SPF/DMARC for the domain) |
   | `seo.canonical_hosts` | `config/seo.php` | Allowlist of hostnames; first entry is canonical. **Required** unless `seo.canonical_origin` is set — `Seo\Origin::resolve()` throws at boot when both are empty (the request `Host:` header is never used as a fallback, so Host-header poisoning of canonical / OG / sitemap URLs is structurally impossible). For local dev, set `['localhost']`. |
   | `seo.canonical_origin` | `config/seo.php` | **Recommended for production.** Hard-set to the absolute HTTPS origin (e.g. `https://example.com`) for the strongest posture — overrides any `$_SERVER['HTTPS']` detection and is poisoning-immune behind a TLS-terminating proxy. If you set this, `canonical_hosts` becomes optional. |
   | `seo.indexable` | `config/seo.php` | `false` until launch (emits noindex + disallow-all robots.txt) |
   | `supported_langs` | `config/i18n.php` | Trim if you don't want every shipped language |
   | `debug` | `config/app.php` | Stays `false` in production (the committed default). Stack traces include filesystem paths; the diagnostic `X-H42-Error` and `X-H42-Cache` response headers are also gated on this flag. Flip to `true` for local diagnostic sessions only. |
   | `csrf.bind_strategy` | `config/security.php` | `'ip_ua'` (default) catches token replay across both networks and browsers. Switch to `'ua'` if mobile-IP-roaming false-positives become visible in real visitor traffic — UA-only binding tolerates IP changes. |
   | `images.fallback_when_no_gd` | `config/images.php` | `'serve_503'` (default) returns 503 when `ext-gd` is missing — fail-loud so missing GD is visible. Flip to `'serve_original'` only if GD genuinely cannot be installed and you accept that every visitor downloads full-resolution source bytes. |
   | `contact.honeypot_field` | `config/contact.php` | Leave `null` — the field name is then derived per-installation from the application secret, which keeps it out of bot dictionaries. Set a literal string only when an external integration needs a fixed name. |
   | `email_protection.enabled` | `config/email_protection.php` | `true` to obfuscate the address in HTML source (JS rehydrates client-side) |
   | `mail.log_enabled` | `config/mail.php` | `false` by default (data-minimisation). Flip to `true` only if you actually need the audit trail; pair with a privacy-policy mention because sender-confirmation log entries record the submitter email in plaintext for the configured retention window. |
5. **Smoke-test**: load `/`, then a sub-page, then submit the contact
   form once with a real address. With `mail.enabled = false` (the
   shipped default) the submission is accepted by every gate and the
   visitor sees a `mail_failed` banner — that confirms the pipeline
   works end-to-end without actually sending mail. Once `recipient`
   and `from` are set correctly and `mail.enabled = true` is flipped,
   re-submit and check that the email arrives. `/<lang>/imprint` and
   `/<lang>/privacy` should render — fill in the placeholder operator
   details in `content/<lang>/imprint.md` and `privacy.md` first.

6. **Strip the bundled showcase** if this is your own site: delete the
   four `demos/*` routes from `config/routes.php`, then delete the
   matching `theme/templates/layout-{business,personal,trainer,dev}.html`,
   the matching nav/footer partials, the matching `theme/styles/theme-*.css`,
   and the matching `content/en/demo-*.md`. The core layout / theme stays
   as your starting point — restyle `theme/styles/theme-core.css` or
   rename it.

## Filesystem layout

The bundled install ships with `paths.theme = 'theme'` set in
`config/app.php`, which collapses templates / styles / js / i18n /
theme-assets under one folder. To swap in a different theme, replace
the `theme/` directory wholesale (e.g. `mv theme theme.bak && cp -r
incoming theme`) — content under `content/` and raster site assets
under root `assets/` survive the swap.

For backwards compatibility with installs that have everything at
root, set `paths.theme = '.'` in `config/app.php`. The defaults in
all four `paths.*` keys are documented in `config/app.php` itself.

## Optional file-based logging

The host's PHP error log is the default sink (often delayed by
hours on shared hosts). For tail-able local debugging, set
`log_file: 'logs/whimcms.log'` in `config/app.php` (relative path
under `paths.var`). Records go to BOTH the host's error_log and the
project file. No rotation is built in — pair with `logrotate` for
production.

## Theme-config coupling

Themes are not just visual files. A theme that introduces its own
block types, page set, language list, or contact-form fields needs
matching configuration files. Treat configs as falling into three
classes:

| Config | Class | Theme-specific when … |
|---|---|---|
| `config/routes.php` | theme-bound | Theme has a different page set (slug list) |
| `config/i18n.php` | theme-bound | Theme ships in a different language set |
| `config/contact.php` | theme-bound | Form has additional / different fields |
| `config/email_protection.php` | theme-bound | i18n key paths to obfuscated addresses depend on theme's i18n shape |
| `config/seo.php` | deployment-bound | Identity (operator, organization, canonical hosts) — theme can ship sensible defaults |
| `config/mail.php` | deployment-bound | Recipient + sender domain — operator-set |
| `config/security.php` | deployment-bound | CSRF / captcha / rate-limit tuning |
| `config/images.php` | mostly site-bound | Should include `theme/assets` in `allowed_roots` if active theme bundles raster |
| `config/app.php`, `config/content.php` | site-bound | `paths`, `log_*`, `debug`, `allowed_layouts` |

### Convention for bundling

When you build or distribute a theme, ship its theme-bound configs
in a sibling folder:

```
theme.<name>/         the visual identity (templates, styles, js, i18n, assets)
config.<name>/        the theme-bound configs (routes, blocks, i18n, contact, …)
content.<name>/       optional — sample / starter content
assets.<name>/        optional — site-level raster the theme expects
```

A bundle is activated by swapping each `<name>` folder over the
in-place equivalent.

### Activating a bundled theme

Given a bundle named `<name>` distributed as the four sibling folders
above, the swap is:

```bash
# Snapshot current install
mv theme   theme.bak     && mv theme.<name>   theme
mv content content.bak   && mv content.<name> content
mv assets  assets.bak    && mv assets.<name>  assets

# Swap each theme-bound config (review first — see security note below):
for f in routes blocks i18n contact email_protection; do
  mv config/$f.php config.bak/      # backup current
  cp config.<name>/$f.php config/   # activate the bundle
done

# Optional: also swap deployment-bound configs if you trust the bundled values
# (cp config.<name>/seo.php config/  etc.)

# Bump CACHE_BUSTER in config/app.php so browsers reload the new CSS/JS
```

To switch back, reverse each `mv`. The `var/` directory and its
`.whimcms-state` marker stay untouched — runtime caches are bound
to the install, not the theme.

### Security warning — never adopt theme configs blind

A config bundle from an untrusted source can weaken the install in
ways that aren't obvious. Audit each file before activation:

- `security.php` — disabled CSRF, raised rate limits, captcha at
  difficulty 0, removed honeypot
- `mail.php` → `recipient` — redirect contact submissions to an
  attacker-controlled mailbox
- `contact.php` → `enabled = false` silently disables the form's
  POST guard; or `honeypot_field` set to a fixed name a bot
  dictionary recognises (defeats the auto-derived per-install name)
- `seo.php` → `canonical_hosts = []` — opens Host-header poisoning
  of canonical / OG / sitemap URLs
- `images.php` → `allowed_roots` — additional roots can expose
  directories you don't want publicly readable via the `{% image %}`
  directive (which then serves cropped variants under
  `/img-c/<basename>-<hash>.<ext>`)
- `email_protection.php` → `paths` — could redirect the obfuscated
  email widget to point at attacker-controlled i18n keys

Read every file. When in doubt, diff against the current `config/`
sibling and explicitly approve each value change.

## var/ directory ownership

On first boot, WhimCMS creates `paths.var` if missing and drops a
`.whimcms-state` marker file in it. On subsequent boots, the marker's
presence confirms ownership. **An existing-but-unmarked directory is
refused at boot** — this prevents `paths.var` from accidentally being
pointed at a directory containing pre-existing data (which the
sweepers might later clean up). If you really do want WhimCMS to
adopt an existing dir, create the marker manually:
`touch <var-path>/.whimcms-state`.

## What lives where

```
index.php                  three-line entry, hands off to Kernel
config/<section>.php       split per concern: app, i18n, routes, content,
                           seo, images, mail, email_protection, contact,
                           security, blocks. Loaded by Config::loadDir().
content/<lang>/<slug>.md   per-page block composition (you edit these)
i18n/<lang>.json           UI microcopy (nav, footer, forms, errors)
lib/WhimCMS/               PHP under namespace H42\WhimCMS\ (autoloaded)
templates/                 HTML templates (layout + block partials)
js/                        ES modules, no inline scripts
styles/                    base.css + one theme-<name>.css per bundled theme
assets/                    photos, logos, favicons (fonts loaded via Google Fonts)
var/                       runtime state + caches (deny-all .htaccess)
.htaccess                  test-deploy variant (project-scoped)
_htaccess_production       production variant — rename to .htaccess
_docs/                     this documentation
```

## Common deploy issues

**Mails go to spam.** `mail.from` must be a real address on the
domain you're sending from, and the domain needs SPF + DMARC records
that allow your host's MTA. Talk to your host. As a fallback, ship
a custom `Mail/SmtpTransport` (drop-in beside `Mail/PhpMailTransport`)
to route through an authenticated SMTP relay.

**`var/state/` not writable** — fatal on first hit, since `Secret::initialise`
needs to write the HMAC secret. Symptom: 500 with "Cannot write secret"
in the trace (debug=true). Fix: `chown` to the PHP user, mode 0700.

**Empty 500 page in the browser.** The shipped `.htaccess` has
`ErrorDocument default` for 4xx/5xx so a parent / host-level
configuration cannot replace your error body. If you removed those
lines and a parent `.htaccess` defines `ErrorDocument 500 /something`,
your error responses will be silently replaced. Re-add the
`ErrorDocument default` lines.

**Browser shows its own 5xx page even with debug=true.** The hardened
exception handler emits `Content-Type: text/html` with at least the
HTML doctype + a `<pre>` block, so Chromium-family browsers don't
substitute their friendly error page. If you still see the browser
default, check that no SAPI-level layer is dropping the response
body — `curl -i` will tell you whether bytes leave the server. With
`debug=true` the handler additionally sets an `X-H42-Error` header
as a diagnostic marker; in production (`debug=false`) the header is
deliberately suppressed so the exception class isn't disclosed for
fingerprinting, so don't rely on it being there in a prod-mode
inspection.

**Behind a CDN / reverse proxy.** Several defences key off the
client IP via `REMOTE_ADDR`. If you sit behind Cloudflare, an nginx,
a Kubernetes ingress, or similar — read the *Reverse proxy* section
of [SECURITY.md](SECURITY.md) **before** going live, or every visitor
will share the same rate-limit / blocklist / CSRF bucket.
