# Security

Threat model and the layers that defend against it.

A multi-pass adversarial audit history is maintained internally
(gitignored — not part of the distributed repository). The public
summary below tracks the same defence model the audits verify
against; when a pass finds something, the model and the fixes land
here at the same time.

The internal history covers, across multiple passes: an ASVS-style
application audit (mail relay, Host poisoning, CSRF binding,
captcha replay, header hardening); a dedicated content-engine
audit (path containment, markdown safe subset, attribute parser
limits, cache file format, block-type allowlist); a hardening pass
after the content engine landed; a full-stack re-trace of the
request lifecycle; an external-prompt adversarial review (OWASP
ASVS L2/L3 + CWE Top 25 + PHP-specific pitfalls); a session-
refactor audit covering the engine-level restructure; a
concurrency / mail-header / OTP-cap audit; and an external pentest
follow-up running OWASP ZAP (DAST) and Semgrep (SAST) against the
production `.htaccess` profile.

## Threat model

The site is **public, read-mostly, with a single write surface**:
the contact form. There is **no login**, no admin UI, no database, no
file uploads, no third-party JavaScript. The bundled showcase loads
its web fonts from Google Fonts (CDN — see the CSP carve-out in
`.htaccess`); the production profile in `_htaccess_production` keeps
the strict `'self'` default and self-hosting fonts under `/fonts` is
the privacy-stronger choice for real deployments. The author edits
content over SSH/SFTP — content files are trusted (filesystem-write
access is required to author them).

The model focuses on:

| Class | Concrete concern | Defence layer |
|---|---|---|
| Path traversal | Slug or asset path escaping the project root | Regex + `realpath` + `str_starts_with` containment in every loader |
| Output-side injection | XSS into rendered HTML | `Sanitizer::escape()` by default; explicit `{% html: %}` is the only opt-out and is audit-tracked |
| Form abuse | Spam, brute-force, mail relay | CSRF + honeypot + rate-limit + blocklist + captcha + per-field validation |
| Header injection | Mail headers, HTTP headers | `Message::stripHeaderUnsafe`, request URI rejection of `\0\r\n` |
| Open redirect / SSRF | None today (no user-supplied URLs are followed server-side) | N/A — would need to be re-audited if added |
| Information disclosure | Stack traces, server fingerprints, indexed pre-launch pages | `debug=false` in prod, `expose_php=Off`, `seo.indexable=false` until launch |
| Resource exhaustion | Decompression bombs, oversized parses, infinite loops | Per-component byte/depth/lines caps; image-server 25 MB / 50 MP caps |
| Host-header poisoning | Canonical / OG / sitemap URLs reflecting attacker's `Host:` | `seo.canonical_hosts` allowlist |
| Cache / log integrity | Cross-tenant leakage, replay, stale-orphan accumulation | Atomic writes, mtime invalidation, single-use captcha markers, blocklist with TTL. Cache cleanup via `H42\WhimCMS\Cache\Sweeper` subclasses — sentinel-gated, lock-protected, project-root-confined `realpath` containment, `lstat`-based symlink/type rejection on every destructive call |

## Defence layers

### Path containment

Every loader that accepts a name from the request follows the same
two-step pattern:

1. **Regex first.** `[a-zA-Z0-9_-]`-class patterns reject `..`, null
   bytes, slashes, control chars before any filesystem call.
2. **Realpath second.** `realpath()` plus a
   `str_starts_with($real, $rootReal . DIRECTORY_SEPARATOR)` check
   confirms the resolved path is strictly inside the expected root.

Applied in:

- `Engine::resolveTemplatePath` (template names)
- `I18n::load` (language file)
- `PageLoader::load` (slug + lang → `content/<lang>/<slug>.md`)
- `Image\CroppedServer::handle` (filenames under
  `var/cache/img-cropped/` — read-only; files are written only by
  the `{% image %}` directive at template-render time, so the URL
  surface cannot fan-out cache writes)
- `ImageDirective` (asset paths from template arguments, validated
  via `Path\AssetPathResolver` against the configured asset roots)

A regex match on a clever symlink could in principle land outside;
the realpath check is the second gate. Both must pass.

### Output sanitisation

Three named modes, no others:

| Function | Used by | Behaviour |
|---|---|---|
| `Sanitizer::escape()` | `{{ }}` (default) | Standard `htmlspecialchars` with `ENT_QUOTES \| ENT_SUBSTITUTE`, UTF-8 |
| `Sanitizer::sanitizeEm()` | `{!! !!}` (legacy raw) | Escape, then restore `<em>`/`</em>` from sentinels. No attributes survive |
| `HtmlDirective` | `{% html: %}` | **No** sanitisation. Audit-restricted to values from `Markdown::render` |

`Sanitizer::stringify()` is upstream of all three: arrays / objects
render as the empty string by design. Accidentally dumping a
structure into HTML is a clear bug, not silent garbage.

The Markdown safe-subset renderer (`Content/Markdown.php`) emits
HTML from a small explicit allowlist:

- Tags: `<p>`, `<ul>`, `<li>`, `<h2>`, `<h3>`, `<h4>`, `<strong>`,
  `<em>`, `<code>`, `<a href="…">`. Nothing else.
- Every literal text segment goes through `htmlspecialchars`.
- `<` / `>` / `&` in source are always escaped to entities.
- Link `href`s are scheme-allowlisted: `https:`, `mailto:`, `tel:`,
  relative `/…` / `#…`, path markers `~/…` / `^/…`. No `http:`,
  `javascript:`, `data:`, `vbscript:`, `file:`, no credentials in
  URL, no whitespace or control chars.

### Form pipeline

The contact form is the only write surface. Layered defences,
configurable across `config/security.php` (csrf, captcha, rate_limit,
blocklist), `config/contact.php` (fields, honeypot), and `config/mail.php`
(daily cap, audit log):

| Layer | Config | Mechanism |
|---|---|---|
| HMAC-signed CSRF token | `csrf` | Per-render token, scoped to a specific form via `formId` so a token issued for one POST endpoint cannot be replayed at another (`ContactController::FORM_ID = 'contact'` today; future endpoints pick distinct strings). Client-binding strategy via `csrf.bind_strategy` — `'ip_ua'` (default), `'ua'`, or `'none'`. See `Csrf::deriveBindKey` for the trade-offs |
| Form-timing window | `csrf.max_age`, `csrf.min_age` | Reject submissions that arrive too fast (bot) or too late (replay) |
| Honeypot field | `contact.honeypot_field` | Hidden input; non-empty → reject, soft-block. Field `name` is derived per-installation from the application secret by default (`H42\WhimCMS\Security\Form\Honeypot::resolveFieldName`), so common bot dictionaries get no signal. Optional config override is a literal name |
| Sliding-window rate limit | `rate_limit` | Per IP-hash bucket |
| Soft IP blocklist | `blocklist` | Strikes accumulate across rate-limit / captcha / honeypot violations; auto-cleanup |
| Per-field validation | `contact.fields.*` | Length, format, choice allowlists |
| Mandatory consent checkbox | `contact.fields.consent` | GDPR; rejected if unchecked |
| Mail daily cap | `mail.daily_max` | Independent backstop against runaway flooding |
| Audit log | `mail.log_*` | Day-bucketed, HMAC-keyed IP hash, TTL |
| Captcha (proof-of-work) | `captcha` | HMAC-signed challenge solved client-side; replay-protected via `CaptchaStore` single-use markers; salt 128-bit |
| Captcha-miss throttle | `captcha.miss_threshold`, `captcha.miss_window` | Sliding-window per-IP counter for empty-captcha submits; threshold escalates to a Blocklist strike so a bot can't grind through the rate-limit ceiling by simply omitting the captcha |

The captcha is the strongest of these — and it's the only one with
a cost: no-JS users can't solve the puzzle. Acceptable trade-off
for the project's threat model; flip `captcha.enabled => false` if
reach matters more.

### Headers + CSP

Set by `_htaccess_production` (production) or by a parent `.htaccess`
(test deploy). The site emits **no inline scripts or styles**, so
CSP is strict:

```
default-src 'self';
img-src 'self' data:;
script-src 'self';
style-src 'self';
font-src 'self';
media-src 'self';
connect-src 'self';
frame-ancestors 'none';
base-uri 'self';
form-action 'self'
```

Plus:

- `Strict-Transport-Security` — staged rollout (5 min → 1 year →
  preload). See `_htaccess_production` for the sequence and
  preconditions.
- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: DENY` (also `frame-ancestors 'none'` in CSP)
- `Referrer-Policy: no-referrer`
- `Cross-Origin-Opener-Policy: same-origin`
- `Cross-Origin-Embedder-Policy: require-corp`
- `Permissions-Policy` — explicit deny of geolocation, camera,
  microphone, payment, accelerometer, gyroscope, magnetometer
- `X-XSS-Protection: 0` (the modern recommendation — the legacy
  IE/Edge filter caused vulnerabilities of its own)
- `expose_php Off`, `Server` / `X-Powered-By` headers stripped
- `ErrorDocument <code> default` for 4xx/5xx so the host can't
  swallow PHP-emitted error bodies

### SEO trust boundary

The canonical, OG, Twitter, sitemap, and JSON-LD URLs all need a
trusted origin to embed. Without a guard, an attacker sending
`Host: evil.tld` would have their hostname appear in:

- the canonical `<link rel="canonical">`
- OG / Twitter share metadata (cached by social-card scrapers)
- the sitemap (cached by search engines)

`config/seo.php → seo.canonical_hosts` is an allowlist. The first entry
is the canonical host emitted on every page, regardless of the
request `Host:` header. List www / non-www variants if both should
resolve to the same site — only the first is used as canonical.

`seo.canonical_origin` overrides everything (full origin including
scheme). Useful when the production host differs in scheme/port from
the default.

If neither key is set, `Seo\Origin::resolve()` throws at boot —
the request `Host:` header is never used as a fallback, so a
forgotten config wipe cannot silently re-enable Host-header
poisoning. Local-dev installs set `'canonical_hosts' => ['localhost']`.

### Reverse proxy / CDN adaptation

Several defences key off the **client IP**:

- Rate limit (`RateLimiter`) — buckets requests per IP-hash.
- Soft blocklist (`Blocklist`) — strikes accumulate per IP-hash.
- Captcha-miss tracker (`CaptchaMissTracker`) — sliding-window per IP-hash.
- CSRF binding (`Csrf::deriveBindKey`) — token signature mixes the
  client surfaces selected by `csrf.bind_strategy` (default `'ip_ua'`
  = IP + UA, `'ua'` = UA-only, `'none'` = no client binding).
- Mail audit log (`MailLog`) — HMAC-keyed IP hash per send.

The IP comes from `$_SERVER['REMOTE_ADDR']` only — see
`Security\Http\RequestSecurity::clientIp()`. With direct hosting
(Apache talks to the visitor) that's correct.

**Behind a CDN, reverse proxy, or load balancer** (Cloudflare, Fastly,
nginx, K8s ingress, …) every request arrives with `REMOTE_ADDR` set
to the proxy's IP. Then:

- Every visitor shares the same rate-limit bucket → quickly self-DoSes.
- One bot strikes → everyone gets blocked.
- The captcha-miss tracker collapses onto one bucket — a single bot
  pushes the whole proxy IP into strike territory.
- Under `csrf.bind_strategy = 'ip_ua'` (the current default) the IP
  component degenerates to the proxy IP, leaving effective UA-only
  binding — same posture as the `'ua'` opt-out, just with more
  rejected legit sessions because the IP-component changed for
  everyone in lockstep.
- Mail-log "ip_hash" is the same for everyone.

**Adaptation required.** Add a small allowlist of trusted upstream
proxy IPs to `Security\Http\RequestSecurity::clientIp()` and read
the real client from the forwarding header — but **only** when the
request came from one of those allowlisted upstreams:

```php
private static function clientIp(): string
{
    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $trustedProxies = ['10.0.0.0/8', '203.0.113.42'];   // <-- your hop(s)
    if (self::ipInAny($remote, $trustedProxies)) {
        $xff = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        foreach (array_reverse(array_map('trim', explode(',', $xff))) as $hop) {
            if ($hop !== '' && filter_var($hop, FILTER_VALIDATE_IP) !== false
                && !self::ipInAny($hop, $trustedProxies)) {
                return $hop;
            }
        }
    }
    return filter_var($remote, FILTER_VALIDATE_IP) !== false ? $remote : '0.0.0.0';
}
```

Never trust `X-Forwarded-For` without the trusted-proxy gate. If you
do, anyone can spoof their source IP and bypass the rate limit, the
blocklist, and the CSRF binding.

If the upstream uses a vendor-specific header (Cloudflare's
`CF-Connecting-IP`, Akamai's `True-Client-IP`), prefer that over
`X-Forwarded-For` — those headers are rewritten by the vendor on
every hop, so a spoofed value never survives.

### Error path

PHP fatal errors that bypass `set_exception_handler` (memory limit,
stack overflow, parse error in an autoloaded file, undefined function
in an extension) are caught by `register_shutdown_function`:

1. Discard any output buffers.
2. Send `Content-Type: text/html`. The diagnostic
   `X-H42-Error` header is appended **only when `debug=true`** —
   in production responses the exception class / `fatal` marker is
   never disclosed to the client.
3. Emit a debug-mode trace or a minimal production page.
4. Force `flush()` so PHP-FPM doesn't drop the body on exit.

`set_exception_handler` itself is wrapped in a try/catch, with a
bare-bones fallback that uses only PHP built-ins (`error_log`,
`echo`) so a failure inside the handler can't leave the response
empty.

## Audit cadence

Re-run an audit when:

- A new write surface lands (auth, form, file upload, admin UI).
- A new external dependency is added (npm, composer, third-party
  CDN, social embed).
- The deployment topology changes (CDN added, reverse proxy added,
  hosting migrated).
- The CSP is widened (any `unsafe-inline`, any new `*-src` host).
- A new template directive or a new `{% html: %}` call site
  appears.
- A new content block type accepts free-form HTML or URL fragments.

Write each audit as a separate dated entry in the internal audit
folder. Follow the existing format: scope, threat model,
methodology, findings table with risk levels and resolutions,
items reviewed and accepted,
open items, verification checklist for re-running.

## Quick checks before going live

- `seo.indexable` flipped to `true`?
- `seo.canonical_hosts` set to your real production host(s)? For
  the strongest posture, also set `seo.canonical_origin` to the
  absolute HTTPS URL — it overrides any `$_SERVER['HTTPS']`
  detection and is poisoning-immune behind a TLS-terminating proxy.
- `mail.recipient` / `mail.from` set to a real address that can
  authenticate via SPF/DMARC?
- `debug` is `false`? (Stack traces and the diagnostic
  `X-H42-Error` header are both gated on this.)
- HSTS at the right stage? Production default is Stage 2
  (`max-age=31536000; includeSubDomains`) — only ship it once HTTPS is
  **fully** enforced for the apex AND every reachable subdomain, otherwise
  visitors are locked out for the full year. Stage 1 (5 min) remains
  available for first-deploy smoke testing on a fresh domain. Stage 3
  (`preload`) is essentially permanent and an explicit decision.
- `var/state/` and `var/cache/` writable, **not** web-accessible?
- `_htaccess_production` renamed to `.htaccess` (or parent
  `.htaccess` already enforces equivalent)?
- Behind a CDN/proxy? `Security\Http\RequestSecurity::clientIp()` adapted?
- `js/` and `styles/` cache-busted (`config/app.php → globals.CACHE_BUSTER`)
  if you've changed assets since the last deploy?

If any check is "no", the site can still go up — but the matching
defence isn't active. Document the gap.
