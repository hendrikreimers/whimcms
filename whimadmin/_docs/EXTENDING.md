# Extending WhimAdmin

Recipes for the most common dev tasks.

## Add a new authed route

1. Create the controller method (or new controller class). Should
   return a `H42\WhimAdmin\Http\Response`.
2. Wire it in `Kernel::buildRouter`:

   ```php
   $router->add('GET',  'my/path',
       fn(Request $r) => ($g = $authGuard($r)) ?? $myController->show($r));
   $router->add('POST', 'my/path',
       fn(Request $r) => ($g = $authGuard($r)) ?? $myController->submit($r));
   ```

3. CSRF: every POST handler must validate the form token. Pick a
   `formId` (e.g. `'my-form'`); the wrapper prefixes `whimadmin:` so
   admin tokens don't collide with the public site.

   ```php
   if (!$this->csrf->validateFromRequest($req, 'my-form')) {
       return $this->renderError('Form expired.');
   }
   ```

4. Add an audit event in `Audit\Log` vocabulary (`my.thing.ok`,
   `my.thing.fail`, `my.thing.csrf.invalid`).

## Add a new field type

See [BLOCK_SCHEMAS.md → Adding a new field type](BLOCK_SCHEMAS.md#adding-a-new-field-type).

Five touchpoints:

- `views/fields/<type>.html` (server-render)
- `FieldSchema::ALLOWED_TYPES` (allowlist)
- `FormRenderer::renderField` (PHP dispatch)
- `FormDecoder::decodeValue` (PHP decode)
- (optional) `js/fields/<type>.js` for client behaviour, imported in
  `js/main.js`

## Add a new audit event

1. Add the event name to the vocabulary list in
   [SECURITY.md → Audit log](SECURITY.md#audit-log).
2. Call `$this->audit->record('namespace.action.outcome', $req->clientIp(),
   $username, ['detail' => '...'])` from your controller.
3. Sensitive keys (`password`, `token`, `code`, `otp`, `cookie`,
   `secret`, `authorization`) are auto-redacted in the `detail` map
   by `Audit\Log::sanitizeDetail`. Don't try to outsmart this — add
   to the redact list if you have a new sensitive key.

## Add a new core-config write target

WhimAdmin's `PhpArrayWriter` whitelists which `<core>/config/*.php`
files it may write. Currently allowed: `routes`, `i18n`. To add
another:

1. Add a `TARGET_<NAME>` const to `PhpArrayWriter` and the path
   mapping in `PhpArrayWriter::pathFor`.
2. Add a shape validator in `PhpArrayWriter::validateShape` — strict.
   The validator MUST reject any unexpected top-level key, control
   characters in values, and types that wouldn't round-trip.
3. Build the controller that produces the payload and hands it to
   `writer->write(self::TARGET_NAME, $payload)`. The writer will
   probe-include the rendered file before rename — a serialiser
   regression cannot land bad bytes on disk.

Treat additions to `PhpArrayWriter` as security-critical reviews —
this class is the one path WhimAdmin can corrupt the public site's
boot sequence with.

## Add a new asset operation

`AssetBrowser` is the single-class API. Methods:

- `list(dir)`, `mkdir(dir, name)`, `upload(dir, $_FILES-entry)`,
- `rename(path, newName)`, `recycle(path)`,
- `recyclerList()`, `recyclerPurge()`,
- `allImagePaths(maxEntries)` — for autocomplete.

Adding e.g. a `copy(srcPath, destDir)` method:

1. Validate both paths via `resolvePath` / `resolveDir`.
2. Containment-check the resolved targets under `assetRealRoot`.
3. Use the same allowlist (`NAME_PATTERN`) on any new filename
   component.
4. Don't follow symlinks pointing outside `assetRealRoot`.
5. Wire a controller method + route + audit event + view hook.

## Reuse a core service

WhimAdmin's autoloader doesn't know about `H42\WhimCMS\…`. The core
autoloader is loaded first by `whimadmin/index.php`, so any
`H42\WhimCMS\X\Y` is reachable via `use` in WhimAdmin code.

Allowed: anything under `H42\WhimCMS\` that's a value-producing
service (Config, Tokenizer, AttributeParser, Mail\*, Security\*,
Template\Engine).

Forbidden: modifying core code in any way. If a feature needs a
core change, surface it as a request — don't patch.

## Run a one-off maintenance task

There's no admin "console" command runner today. For ad-hoc tasks
the operator either:

- Writes a tiny PHP script next to `whimadmin/index.php` that
  bootstraps the autoloaders, calls the relevant service, exits.
  (Treat as `.tmp_*.php` and delete after use.)
- SSHes in and edits files directly.

Phase 7+ might add a `whimadmin/maint/` directory with explicitly-
authorised maintenance scripts (recycler purge by age, audit-log
rotation, etc.).

## Testing without a PHP runtime locally

WhimAdmin assumes the developer can spin up a PHP 8.1+ environment
to verify changes (Apache + PHP-FPM in a container, the bundled
`php -S` dev server, or a local LAMP stack). There's no PHPUnit
suite shipped — testing today is end-to-end via the browser.

When changing the auth pipeline or a save flow:

1. Reset the test installation: `rm -rf whimadmin/var/state/auth`,
   reload `/whimadmin/`, follow the setup flow with the new code path.
2. Save a known-good page from the editor. Diff the on-disk `.md`
   against the original — any unexpected change is a round-trip bug.
3. Check `whimadmin/var/logs/audit.log` for the events you expected.
4. Drop a malformed file to test the unhappy path (e.g. write a
   `.md` with an unknown block type, then open the editor — it
   should show the unknown-block placeholder, not crash).

## Don't

- Don't `var_dump` / `dd` from a controller. Use `error_log` or the
  audit log.
- Don't read `$_SERVER` / `$_POST` directly inside controllers — go
  through `Request`.
- Don't bypass `CookieJar` to set a `Set-Cookie` header by hand —
  the jar enforces all the security flags.
- Don't bypass `PhpArrayWriter` to write a core config — handcrafted
  PHP serialisation is a re-audit if it goes wrong.
- Don't accept JSON input deeper than 16 levels (the `Request`
  sanitiser already enforces this).
- Don't add a new `{% html: %}` call site in `views/` without
  documenting the trusted-source argument.
