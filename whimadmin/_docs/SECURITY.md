# Security

Threat model and the layers that defend against it. WhimAdmin is a
**high-value target** — it's the one place where authenticated state
mutations happen on a WhimCMS install. The defaults are conservative;
loosen only with deliberation.

## Threat model

The admin is reachable over HTTP(S) at `/whimadmin/` (or wherever
the operator has mounted the directory). Threat actors:

| Actor | Capability | Defence |
|---|---|---|
| Unauthenticated network visitor | GET/POST any admin URL | Auth gate on every authed route + uniform "Invalid credentials" response |
| Brute-force bot | High-rate credential attempts | Per-IP rate limiter (5 / 5 min default), constant-time `password_verify`, OTP attempt cap |
| Stolen mail account | Reads email but not password | Step 1 = password (Argon2id); OTP alone insufficient |
| Stolen password (phish, leak) | Knows password but no mail access | OTP step 2 gates final session |
| Session hijack via XSS | Reads `document.cookie` | `HttpOnly` cookie — JS cannot read it |
| Session hijack via network sniff | Captures cookie in transit | `Secure` flag (when HTTPS), `SameSite=Strict` |
| CSRF from third-party origin | POSTs forms cross-site | HMAC-signed CSRF token on every POST, formId-scoped, IP+UA-bound |
| Path traversal | `../` in slug / lang / asset path | Tight regex + `realpath` containment under root |
| Header injection | `\r\n` in mail header | Core's `Message::stripHeaderUnsafe`; no submitter-derived header values |
| Code execution via planted files | Drop `.php` in `var/cache` | All cache files HMAC-signed, never `include`d. Asset upload extension allowlist. |
| Disk filling | Many recycler files | Manual purge (operator decision). History prune on save. |
| Logged user accidents | Misclicks "Delete page" | Soft-delete to recycler; restore by re-edit |

### Out of scope

- Operator with filesystem access to `var/state/`. They can replace
  `secret`, `user.json`, etc. Trust model assumes the operator
  controls the box.
- Vulnerabilities in PHP itself (memory corruption, etc.) — keep
  PHP patched.
- Vulnerabilities in the underlying OS / Apache.
- DoS at the network layer (use a CDN / WAF).

## Defence layers

### Authentication

```
   visitor          /login (GET)        ── render form, issue CSRF token
       │
       │             /login (POST)
       ▼            ┌──────────────────────────────────────────────────┐
   rate limit ───▶  │  IP outside 5-per-5-min window → 429             │
                    │  CSRF fail → 400                                  │
                    │  Argon2id verify (always runs even if no user) →  │
                    │  on fail: "Invalid credentials." (uniform)        │
                    │  on pass: issue OTP, mail it, set pre-otp session │
                    └──────────────────────────────────────────────────┘
                                            │
                                            ▼
                       /otp (GET)  ── form, issue CSRF token
                       /otp (POST) ── validate code (HMAC, TTL, attempt
                                       cap) → upgrade session + rotate id
                                            │
                                            ▼
                       /          ── dashboard / pages
```

**Argon2id** with PHP defaults (≥64 MiB memory, ≥4 iterations, 1
thread on PHP 8.1+). The dummy hash in `UserStore::verify` keeps
the timing of the "no user exists" path identical.

**Password policy** (`UserStore::passwordPolicyError`):

- length 12..256 (counted in Unicode characters via `mb_strlen`)
- at least one uppercase letter (`\p{Lu}`)
- at least one lowercase letter (`\p{Ll}`)
- at least one digit (`\p{N}`)
- at least one "special" — anything that is neither a letter nor a digit

Each rule reports a SPECIFIC error message in the setup form so the
operator sees exactly what's missing rather than a generic
"Invalid password.". The composition rule is deliberately strict
(NIST 800-63B-3 actually argues against composition rules in
favour of length + breach-list checks, but in a single-user admin
install with no SSO and no breach-list service, locking out the
trivial all-lowercase case is the right default — an operator who
wants a 40-character passphrase can still comply by adding one
digit and one punctuation mark).

**OTP**: 6 digits from `random_int`, HMAC-stored
(`hash_hmac('sha256', $code, $secret)`), TTL 5 min, 5 wrong-attempt
cap → file invalidated. 1 M codespace × 5 attempts = ~5e-6
brute-force probability per code, throttled by the per-IP limiter
that's also active on `/otp`.

**OTP daily cap**: independent backstop against admin-side mail
flooding, configured via `whimadmin/config/app.php → otp.daily_max`
(default 50). One UTC-day-keyed counter under
`whimadmin/var/state/otp-mail-counter/`, ftruncate+fwrite under
`LOCK_EX`. Fail-closed on FS errors so an exhausted state
directory cannot silently disable the throttle. Independent of
core's `mail.daily_max` so a noisy public contact form cannot
lock out admin login mid-day, and a login-spammer flooding `/login`
cannot drain the host's per-day mail quota the public site relies
on. Set `otp.daily_max = 0` to disable (not recommended on shared
hosting).

**Anti-fixation**: on OTP success the session id is rotated. The
old `pre-otp` session file is deleted; a fresh `authed` session id
is minted with a new random 256-bit identifier.

**Setup token**: first-run only. Server writes the token's HMAC to
`var/state/setup-token.json` and the plaintext to `setup-token.txt`
inside the same directory (deny-all). Operator retrieves via SSH /
SFTP. Both files are deleted on successful setup; expired tokens
delete both files lazily.

### Sessions

| Property | Value |
|---|---|
| Storage | File per session under `var/state/auth/sessions/<id>.json` |
| Cookie | `whimadmin_sid = <id>.<hmac(id, secret)>` |
| Cookie flags | `HttpOnly`, `SameSite=Strict`, `Secure` (when HTTPS), `Path=<basePath>` |
| Bind | `bind_strategy = 'ip_ua'` by default — token mixes `sha256(ip|ua)` |
| Idle timeout | 30 min default |
| Absolute timeout | 8 h default |
| Revocation | Logout deletes the session file; cookie cleared |

The single-user model means there's no cross-session privilege
escalation surface. There IS, however, a multi-tab race: opening
two tabs and editing the same page concurrently is last-write-wins
on disk (with a history snapshot of each pre-write state).

### CSRF

Built on the core's `Security\Form\Csrf` primitive — HMAC-signed
token bound to client surfaces and form identity:

```
token = base64( <issued-ts> "." hmac(<ts> | <bindKey> | <formId>) )
```

WhimAdmin scopes every formId under `whimadmin:<form>` so a token
issued for the admin's login form cannot be replayed against the
public site's contact form (or vice versa). Bind strategy is
`ip_ua` (fixed in [Csrf.php](../lib/WhimAdmin/Http/Csrf.php))—
admin sessions don't roam networks the way mobile visitors do.

Form IDs in use:

- `whimadmin:setup`
- `whimadmin:login`
- `whimadmin:otp`
- `whimadmin:logout`
- `whimadmin:page-save`
- `whimadmin:page-new`
- `whimadmin:settings-routes`
- `whimadmin:settings-langs`
- `whimadmin:assets`

Every authed POST validates a token before any mutation.

### Path containment

Every loader / writer that takes a name from a request follows the
same two-step pattern (mirroring the core):

1. **Regex first.** Lang `^[a-z]{2}$`, slug `^[a-zA-Z][a-zA-Z0-9_-]{0,40}$`,
   block-type `^[a-z][a-z0-9-]{0,40}$`, asset filename
   `^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$`. No `..`, no NUL, no slashes.
2. **Realpath second.** `realpath()` plus `str_starts_with($real,
   $rootReal . DIRECTORY_SEPARATOR)` confirms the resolved path is
   strictly inside the expected root.

Applied in:

- `PageRepository::load` / `save` / `delete` (content root)
- `HistoryStore::snapshot` / `prune` (history dir under content root)
- `Recycler::recycle` / `purgeAll` / `list` (recycler dir under content root)
- `AssetBrowser::resolveDir` / `resolvePath` (asset root)
- `AssetBrowser::ensureRecycler` (asset recycler under asset root)

The `is_link()` + realpath-contain pattern in
`AssetBrowser::walkPaths` blocks the "symlink with image extension
pointing at /etc/passwd" trick.

### Output sanitisation

Three named modes via the core's template engine:

| Function | Used by | Behaviour |
|---|---|---|
| `Sanitizer::escape()` | `{{ }}` (default) | `htmlspecialchars` with `ENT_QUOTES \| ENT_SUBSTITUTE`, UTF-8 |
| `Sanitizer::sanitizeEm()` | `{!! !!}` | Escape, then restore `<em>`/`</em>` from sentinels |
| `HtmlDirective` | `{% html: %}` | Verbatim — only used with strings WhimAdmin produced server-side (FormRenderer output, layout `CONTENT`, list-template HTML) |

Auditors: every `{% html: %}` in WhimAdmin's `views/` is fed by a
PHP-side renderer (`FormRenderer`) that escapes user-controlled
values via the engine itself. There is no path from the user's POST
data to a `{% html: %}` argument.

### Atomic writes everywhere

Every state mutation goes through:

1. Build payload in memory.
2. Open `<target>.tmp.<random>` with `LOCK_EX`.
3. `chmod 0o600` (or `0o644` for files Apache must read).
4. `rename` atomically over the target.

A partial write cannot poison readers because `rename` is atomic
on POSIX. The temp file's random suffix prevents collisions on
concurrent saves of unrelated state.

Files using this pattern: `Secret`, `SetupTokenStore`, `OtpStore`,
`UserStore`, `Session`, `ClipboardStore`, `PageRepository`,
`HistoryStore`, `PhpArrayWriter`.

### Round-trip integrity check

Before `PageRepository::save` writes the new `.md` to disk, it
re-parses the serialised bytes via `PageDocument::fromSource`. A
serialiser regression that would corrupt the file is caught here,
**before** the rename — the existing on-disk content is preserved.

Same posture in `PhpArrayWriter::write`: probes the serialised PHP
file via `require` into a temp file and asserts the loaded value
equals the original payload before rename.

### File upload hardening

`AssetBrowser::upload`:

- `is_uploaded_file` check (must be a real upload)
- 10 MB hard cap
- Filename sanitised: drop directory components, replace any non-
  `[A-Za-z0-9._-]` with `_`, drop leading dots/dashes, cap 128 bytes
- Extension allowlist: `png, jpg, jpeg, webp, gif, woff2, ico`
- Content-sniffing for raster extensions: `getimagesize` must agree with
  the claimed extension; a `.png`-suffixed PHP file (or any other
  format mismatch) is refused
- Collision detection with `_<n>` suffix
- `mode 0o644` on the final file
- Realpath-contain the final path under `assetRealRoot`

`.php`, `.html`, `.js`, `.css` and similar are **rejected**. **SVG
is also rejected** despite being a vector format: an SVG can carry
inline `<script>` blocks, and a direct browser navigation to
`/assets/<name>.svg` parses the file with same-origin script
privileges (`<img src="…">` rendering is safe, but admins cannot
guarantee every consumer renders SVGs only via `<img>`). Operators
who need SVG branding SFTP it in manually; the admin UI no longer
accepts SVG uploads or surfaces them in the picker.

### Audit log

Append-only newline-JSON at `var/logs/audit.log`. Records all
authentication and write events with HMAC-keyed IP hashes (raw IPs
never on disk).

Vocabulary:

```
setup.token.generate    setup.token.consume    setup.token.invalid
setup.csrf.invalid      setup.create.fail
login.password.ok       login.password.fail    login.csrf.invalid
login.ratelimit         login.otp.sent         login.otp.send.fail
login.otp.fail          login.otp.ok           login.otp.csrf.invalid
login.otp.ratelimit     logout                 logout.csrf.invalid
page.save.ok            page.save.fail         page.csrf.invalid
page.create             page.delete
page.recycler.restore   page.recycler.restore.fail
page.recycler.purge
page.history.restore    page.history.restore.fail
settings.routes.save    settings.langs.save
asset.upload            asset.mkdir            asset.rename
asset.delete            asset.recycler.purge
sweep.ok                sweep.fail
```

Pair with `logrotate` for production. Sensitive fields (`password`,
`token`, `code`, `otp`, `cookie`) are auto-redacted by
`Audit\Log::sanitizeDetail` regardless of caller.

## Hardening checklist before going live

- [ ] HTTPS terminated at the host (CSP / cookie `Secure` flag rely on it)
- [ ] `whimadmin/config/app.php → debug = false`
- [ ] `whimadmin/var/` writable by the PHP user, `chmod 0o700`
- [ ] Core `config/mail.php → mail.enabled = true` and `mail.from` set
      to a real address that can authenticate via SPF / DMARC
- [ ] Core CSP applies to `/whimadmin/` (default-src 'self', script-src
      'self', no `'unsafe-inline'`) — verify via `curl -I /whimadmin/login`
- [ ] First-run setup completed (no `setup-token.txt` lingering)
- [ ] `whimadmin/var/logs/audit.log` rotated by logrotate or equivalent
- [ ] Rate-limit + session knobs in `whimadmin/config/app.php` reviewed
- [ ] `otp.daily_max` in `whimadmin/config/app.php` set to a sensible cap (default 50)
- [ ] `Apache` allows `mod_headers` + `mod_rewrite` (front-controller deps)

### Reverse proxy / CDN

`Session::deriveBindKey` and the `RateLimiter` use
`$_SERVER['REMOTE_ADDR']` as-is. Behind a CDN (Cloudflare, AWS ALB)
that becomes the proxy's IP — every visitor shares the rate-limit
bucket and the session bind. Adapt PHP-FPM / FastCGI to set `HTTPS=on`
from the proxy AND populate `REMOTE_ADDR` from the trusted proxy
header (or extend `Request::detectClientIp` with a trusted-proxy
allowlist analogous to the core's recipe in `_docs/SECURITY.md`).

## Audit cadence

Re-audit when:

- A new authed write surface is added (new controller / route).
- A new field type's partial accepts free-form HTML.
- `BlockSchemaLoader` gains a new annotation source.
- The core's PageLoader / cache layer changes.
- The deployment topology changes (CDN, reverse proxy added).
- Any CSP allowance changes in the host's `.htaccess`.

Treat each audit pass as adversarial — read the code with the
intent to break it, not to confirm what's there.
