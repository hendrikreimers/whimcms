# WhimAdmin

A web-based content-management UI for [WhimCMS](../README.md).
Independent application, lives next to the WhimCMS core. The core
remains read-only from WhimAdmin's perspective — admin operations
write directly to the on-disk content/i18n/asset/config files that
the public site already reads.

## Status

Feature-complete for daily use:

- **Auth** — first-run setup, user/pass + e-mailed OTP login,
  HMAC-signed sessions, audit log
- **Pages** — list (grouped by language), create, edit, delete (soft,
  to recycler), per-block-type form generated from the partial's
  `{@ block @}` annotation + a sidecar JSON
- **Blocks** — collapsible cards with summary preview, drag-and-drop
  reorder, cut / paste / move-up / move-down, per-save history
  snapshot (last N kept)
- **Markdown body** — toolbar (B / I / H2 / H3 / H4 / list / link /
  inline code / fenced code block) for blocks that accept body
- **Routes** — table editor per language, persists `config/routes.php`
- **Languages** — add / remove / set-default / `Accept-Language`
  detect toggle, persists `config/i18n.php` and seeds
  `<theme>/i18n/<lang>.json` from the default-lang file
- **Assets** — browse with thumbnails, click-to-preview, upload
  (extension-allowlist), mkdir, rename, recycler with deny-all,
  manual purge
- **Asset picker** — datalist autocomplete on every image field plus a
  modal browser with thumbnails (filterable)

Internal docs live in [`_docs/`](_docs/README.md).

## Requirements

- WhimCMS core present at `../lib/` (PHP autoloader, security
  primitives, mail transport, template engine — consumed read-only).
- PHP 8.1+ with `pcre`, `mbstring`, `json`, `filter`, `password_*`
  (Argon2id support — bundled with PHP 8.1+).
- Apache with `mod_rewrite` and `mod_headers`. The shipped
  `whimadmin/.htaccess` handles front-controller routing and
  admin-side header overrides.
- Writable `whimadmin/var/` for the PHP user. Created on first boot
  with a `.whimadmin-state` ownership marker; an existing-but-
  unmarked directory is refused to prevent accidental adoption of
  pre-existing data (same posture as the core's `var/`).

## Deployment

WhimAdmin sits next to the core at the same web root:

```
/                        # WhimCMS core (public site)
├── index.php
├── lib/                 # core engine
├── theme/, content/, …
└── whimadmin/           # this admin UI, served at /whimadmin/
    ├── index.php
    ├── lib/
    ├── views/
    └── …
```

Visit `https://<host>/whimadmin/` to begin. On first hit, a setup
token is written to your host's PHP error log; visit
`/whimadmin/setup?token=<token>` to create the single admin user.

## First-run setup

1. Upload `whimadmin/` next to the core.
2. Make `whimadmin/var/` writable by the PHP user (mode 0700).
3. Visit `/whimadmin/`. A one-time setup token is generated and
   written to a private file inside your installation:
   `whimadmin/var/state/setup-token.txt`. The error log records
   only the location of the file, never the token itself.
4. Open the file via SSH/SFTP — it contains the full URL with the
   token inline and the expiry time.
5. Visit that URL.
6. Pick a username (3–32 chars, `[A-Za-z][A-Za-z0-9_-]*`), e-mail,
   and a password (≥ 12 chars, must contain at least one uppercase
   letter, one lowercase letter, one digit, and one special
   character). Submit.
7. Setup token is consumed: both the HMAC record and the plaintext
   sidecar file are deleted; you are redirected to `/whimadmin/login`.

The setup form is reachable only while no user record exists. Once
the user is created, `/setup` returns 404.

If the token expires before you use it, just reload `/whimadmin/`
once — a fresh token is minted and the sidecar file rewritten.

## Sign-in flow

1. Username + password (rate-limited per IP: default 5 attempts per
   5 minutes).
2. On valid credentials a 6-digit one-time code is mailed to the
   address on file (TTL 5 min, 5 wrong-attempt cap).
3. Enter the code. The session is rotated to the authenticated stage.
4. `/whimadmin/` shows the dashboard.

The mail uses the WhimCMS core's `Mail/PhpMailTransport` and the
sender envelope from `config/mail.php` (so SPF/DMARC alignment
carries over from the public site). Mail must be enabled in the
core (`mail.enabled = true`) for OTPs to deliver.

## Configuration

`whimadmin/config/app.php` ships with safe defaults. Notable knobs:

| Key | Default | Purpose |
|---|---|---|
| `debug` | `false` | Stack traces in 500 responses. Off in production. |
| `session.idle_seconds` | `1800` (30 min) | Idle timeout |
| `session.absolute_seconds` | `28800` (8 h) | Absolute lifetime |
| `session.bind_strategy` | `'ip_ua'` | Session client binding |
| `otp.ttl_seconds` | `300` (5 min) | OTP lifetime |
| `otp.max_attempts` | `5` | Wrong-code attempts before invalidation |
| `otp.digits` | `6` | Digit count |
| `rate_limit.window_seconds` | `300` | Sliding window for login |
| `rate_limit.max_attempts` | `5` | Attempts per window per IP |
| `setup.token_ttl_seconds` | `86400` (24 h) | Setup token lifetime |
| `content.history_max` | `10` | (Phase 4+) per-file version history |

## Layout

```
whimadmin/
├── index.php                    # front controller
├── .htaccess                    # rewrite + admin-side headers
├── lib/WhimAdmin/               # PSR-4: H42\WhimAdmin\
│   ├── autoload.php
│   ├── Kernel.php               # bootstrap + dispatch
│   ├── Config.php
│   ├── Path/PathResolver.php
│   ├── Http/{Request,Response,Router,Csrf}.php
│   ├── View/Renderer.php
│   ├── Audit/Log.php
│   └── Auth/                    # User, Otp, Session, controllers
├── views/                       # admin HTML templates
│   ├── layout.html, login.html, otp.html, …
│   └── mail/                    # OTP mail templates
├── styles/admin.css             # single stylesheet, no preprocessor
├── js/main.js                   # ES module, no bundler
├── config/
│   ├── app.php                  # tunables (above)
│   └── blocks/                  # (Phase 3) per-block-type field schemas
└── var/                         # runtime state, deny-all, marker-gated
    ├── state/
    │   ├── secret               # admin-only HMAC secret
    │   ├── auth/user.json       # single-user record (Argon2id hash)
    │   ├── auth/otp/            # one-time codes (HMAC-stored, TTL)
    │   ├── auth/sessions/       # session records
    │   ├── ratelimit/           # sliding-window state
    │   ├── setup-token.json     # one-shot setup token (HMAC, server-verified)
    │   └── setup-token.txt      # plaintext sidecar (operator reads via SFTP)
    └── logs/audit.log
```

## Security model

WhimAdmin's threat model and the layers that defend against it:

| Class | Defence |
|---|---|
| Brute-force / credential stuffing | per-IP rate-limit; Argon2id password hashing; 5-min OTP TTL; 5-attempt OTP cap |
| Session hijack | HMAC-signed cookie; HttpOnly + Secure (HTTPS) + SameSite=Strict; bound to client IP+UA; idle + absolute timeouts; rotate-on-elevation |
| CSRF | per-form HMAC-signed token, bound to client IP+UA, scoped to a `whimadmin:<formId>` so admin tokens are unusable on the public site |
| User enumeration | uniform "Invalid credentials." response; constant-time `password_verify` runs even when no user exists |
| Header injection | core `Message::stripHeaderUnsafe` strips CR/LF/NUL from all mail header fields |
| Path traversal | every state file path is built from server-controlled values (HMAC of username, hex random ids); regex-shape-checked before filesystem use |
| Setup-time race | first-run setup token issuance gated by `flock()` lockfile (same pattern as core's `Secret::initialiseUnderLock`) |
| Disk-write code execution | no `.cache` / `.php` files written from request inputs; user record is JSON; sessions are JSON |

Note: WhimAdmin does **not** ship its own CSP — the parent `.htaccess`
or core `_htaccess_production` already sets a strict `default-src
'self'` policy that covers admin pages. The admin's `<script
type="module">` hook respects that policy (no inline JS, no eval).

## Audit log

Append-only newline-JSON at `whimadmin/var/logs/audit.log`. Records
all auth events with HMAC-keyed IP hashes (raw IPs never on disk).
Pair with `logrotate` for production.

## Development

WhimAdmin follows the same conventions as the core:

- `declare(strict_types=1);` everywhere
- PSR-12 formatting
- `final class` by default
- typed properties, readonly where applicable
- no globals, no `extract()`, no string `eval()`
- atomic file writes (tempfile + rename, `LOCK_EX`)
- HMAC + `hash_equals` for any secret comparison
- file mode `0o600` on every state file we write
- no Composer dependencies (mirrors the core)
