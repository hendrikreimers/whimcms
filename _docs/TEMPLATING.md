# Templating

The template engine, every directive, the expression sub-language,
and recipes for adding a new block type or a new directive.

For the request-level architecture (Kernel, RenderContext, Content
loader), see [ARCHITECTURE.md](ARCHITECTURE.md).

## Syntax

```
{{ expr }}             variable, HTML-escaped
{!! expr !!}           raw output (only literal <em> and </em> survive)
{% directive %}        directive — see table below
{# comment #}          stripped from output
{@ name … @}           compile-time annotation — pure metadata, no output
```

Plain `%`, `{`, `}` characters are literal text; only the specific
multi-character openers above start a token. CSS percentages,
URL-encoded sequences, stray braces in content — all survive intact.

### Directives

| Directive | Form | Effect |
|---|---|---|
| `var` | `{{ expr }}` | HTML-escape `expr` and emit |
| `raw` | `{!! expr !!}` | Sanitise to `<em>`-only allowlist; everything else escaped |
| `html` | `{% html: expr %}` | **No** sanitisation — emit verbatim. Audit-restricted; see below |
| `include` | `{% include: 'path' %}` or `{% include: 'path', attrs: expr %}` | Render another template. Optional `attrs:` rebinds the local sub-scope |
| `for` (block) | `{% for: expr, as: 'name' %}` … `{% endfor %}` | Loop over `expr`, bind each item to `name`. `as:` is mandatory. Inside the body, read items as `{{ name.x }}` |
| `for` (inline include) | `{% for: expr, as: 'name', include: 'path' %}` | Loop, render `path` per item. The item is bound as `attrs` so the included partial reads it via `{{ attrs.x }}`; `as:` is also bound for readability and is mandatory |
| `if` | `{% if: cond %}` … `{% endif %}` | Render body when `cond` is truthy. **No `else`** — write a second negated `{% if: !cond %}` |
| `blocks` | `{% blocks %}` | Iterate `BLOCKS` from context, render each via the registered partial |
| `image` | `{% image: '<asset-path>', <params> %}` | Server-side cropped/resized image variant. Emits the URL as a string for `<img src="…">`. See [§ Image directive](#image-directive) |
| `safe_href` | `{% safe_href: <expr> %}` | Evaluate `<expr>` to a URL, validate against the scheme allowlist, emit HTML-attribute-escaped. Use for any `href=` whose value comes from author-controlled content. See [§ safe_href directive](#safe_href-directive) |
| `lookup` | `{% lookup: <map>, key: <key> %}` | Evaluate `<map>` and `<key>`, return `map[key]` HTML-escaped. Closes the dot-path-only access gap when the key is only known at render time. See [§ lookup directive](#lookup-directive) |
| `alias` (block) | `{% alias: { name: <expr>, … } %}` … `{% endalias %}` | Bind named values into a block scope. See [§ alias directive](#alias-directive) |
| `debug` | `{% debug: <path-or-_all> %}` | Dev-only diagnostic. Gated by `config/app.php → debug`; emits a `<pre>` of context values. See [§ debug directive](#debug-directive) |

### `{@ @}` annotations

Annotations are declarative metadata for directives — block schemas,
cache hints, layout markers — anything a directive wants to attach
to a template at compile time without affecting its rendered output.
They are **structurally** unable to leak: the Tokenizer extracts
them in a separate pass that produces no output token.

```html
{@ block
  required: title
  optional: id eyebrow lede
@}
```

Body shape (between `{@` and `@}`):
- First non-blank line: the annotation name (e.g. `block`), matching
  `[a-z][a-z0-9-]*`. The Engine uses this to dispatch to the right
  `AnnotationConsumer`.
- Remaining lines: a key/value map in the same syntax as content
  attribute blocks, parsed via `AttributeParser`. Conventionally
  indented two spaces for visual alignment with the markers; the
  parser strips that indent before parsing.

The first user is `BlocksDirective`, which consumes `{@ block @}`
annotations from `partials/blocks/*.html` to register block schemas.
See [BLOCKS.md](BLOCKS.md) for that flow.

To add another annotation type, implement `AnnotationConsumer` on
your directive (declare `annotationNames()` and `eagerScanPaths()`,
implement `consumeAnnotation($templateName, $payload)`) and add the
directive to `BuiltInDirectives::all()`. The Engine's boot scan
auto-wires it.

### Image directive

`{% image: '<asset-path>', <params> %}` produces a cropped or resized
variant of an image asset and emits its URL as a string. The single
image-serving path in WhimCMS — for fixed-size images (avatars,
thumbnails, hero crops, card images) and for responsive variants
(emit several `{% image %}` calls into an `<img srcset="…">`).

```html
<!-- Crop-to-fit (exact dimensions, focus-aware) -->
<img src="{% image: '/assets/photos/avatar.jpg', width: 80, height: 80 %}" alt="..." />

<!-- Crop-to-fit with author-controllable focus -->
<img src="{% image: attrs.image, width: 1920, height: 700, focusX: attrs.focusX, focusY: attrs.focusY %}" alt="..." />

<!-- Scale-only (preserves aspect ratio) -->
<img src="{% image: '/assets/photos/photo.jpg', maxWidth: 1280 %}" alt="..." />

<!-- Format conversion -->
<img src="{% image: '/assets/photos/photo.jpg', maxWidth: 1280, format: 'webp' %}" alt="..." />
```

**Modes** (mutually exclusive — mixing fails at compile time):

- **Crop-to-fit** — `width: N, height: N` (both required), optional
  `focusX` / `focusY` (0.0..1.0, default 0.5/0.5) shift the crop
  window along the source's over-long axis. The result is exactly
  N×N pixels.

- **Scale-only** — `maxWidth: N` and/or `maxHeight: N`, no crop.
  Aspect ratio preserved. Either bound can be omitted (unconstrained
  on that axis). **Smart passthrough:** when the source already fits
  AND no format change is requested, the source URL is emitted
  directly — no cache file, no PHP-served bytes for an unchanged
  image.

**Optional in both modes:** `format: jpg | png | webp | gif` —
output format. Default mirrors the source. Forces a cache file even
when the source would otherwise pass through.

**`focusX` / `focusY` tolerate null evaluation.** Authors can write
`focusX: attrs.focusX` knowing the attribute is optional in the
content `.md` — null/missing/non-numeric falls through to centre
(0.5), no warning.

**URL output:**
- Cropped/rendered: `{{ BASE }}/img-c/<basename>-<hash>.<ext>` —
  served by `Image\CroppedServer`. The basename is cosmetic; the
  hash routes.
- Smart-passthrough: `{{ BASE }}<asset-path>` — direct to the source,
  Apache-served, no PHP hop.

**Why an explicit `<img width="…" height="…">` next to the
directive call?** The directive only emits the `src` URL; it does
not write `width`/`height` attributes. Setting them in the template
prevents layout shift at load. Use the rendered dimensions, not
the directive's source dimensions (e.g. directive renders 128×128 →
template uses `width="64" height="64"` for a 2×-density display).

**Failure modes:**

- Asset path doesn't resolve (typo, missing file, wrong extension)
  → empty string emitted, warning logged. The `<img src="">` shows
  a broken image — visible feedback for the author.
- GD missing → behaviour controlled by
  `images.cropped_fallback_when_no_gd` (default `serve_fail` →
  empty string + error log; alternative `serve_original` → emit
  the source URL).
- Decompression-bomb caps tripped → empty string, warning logged.

**Cache:** `var/cache/img-cropped/<basename>-<hash>.<ext>`. Hash
inputs: `(real source path, source mtime, params)`. A source change
produces a new filename — old cache becomes orphan and is dropped
by `CroppedCacheSweeper` on TTL (default 30 days).

**Security:** the directive runs the asset path through
`Path\AssetPathResolver` before any filesystem operation, so authors
cannot break out of the configured `images.allowed_roots`. The
endpoint (`Image\CroppedServer`) is read-only — only the directive
writes cache files, so the URL surface cannot fan out cache writes.

### `safe_href` directive

`{% safe_href: <expr> %}` evaluates `<expr>` to a string, validates
it against a strict URL-scheme allowlist, and emits the result
HTML-attribute-escaped. Use it everywhere an `href=` (or any
URL-bearing attribute) takes a value that originated in author-
controlled content — block attributes from `.md` files, i18n
microcopy, or any expression whose source isn't a compile-time
constant from `config/`.

**Why it exists.** Plain `{{ x }}` HTML-escapes the output (so the
attribute can't be broken out of) but does **not** filter URL
schemes. A literal `javascript:alert(1)` survives `{{ }}` and renders
as a clickable JS-execution link. `safe_href` closes that gap by
running the value through the same allowlist that gates Markdown
body links.

**Usage:**
```html
<a href="{% safe_href: attrs.cta.href %}">{{ attrs.cta.label }}</a>

<a href="{% safe_href: URLS.imprint %}">Imprint</a>

<a href="{% safe_href: tier.ctaHref %}">{{ tier.ctaLabel }}</a>
```

The `<expr>` is the same expression sub-language as `{{ }}` and
other directives — bare paths (`attrs.x.y`), object literals,
function-call-style is not supported, no string concatenation.
For multi-expression hrefs (`{{ BASE }}/path/{{ slug }}`), the
directive is not applicable; those values must be config- or
code-derived in their entirety to be safe (`BASE`, `URLS.x`,
already-validated `Identifiers`).

**Allowlist** (delegated to `H42\WhimCMS\Content\HrefSanitizer`):

- `https://...` — `@` in the authority is rejected (userinfo-form
  phishing block); `@` in the path / query / fragment is allowed
  (legitimate `?email=foo@bar.com`).
- `mailto:...` / `tel:...`
- `/...` — root-relative path. Scheme-relative `//host` is
  rejected.
- `#...` — in-page anchor.

**Rejected:** `http://`, `javascript:`, `data:`, `vbscript:`,
`file:`, `ftp:`, scheme-relative `//host`, URL-encoded scheme
variants (`javascript%3A...`), HTML-entity variants
(`javascript&#58;...`). Forbidden characters in the value include
control chars, attribute-breakers (`" < >`), and backslash
(URL-parser-confusion defence).

**Failure modes:**

- Empty input (optional attribute missing) → empty output, no log.
- Disallowed scheme / forbidden char / overlong (>2048 bytes) →
  empty output, `Log::warn` entry tagged `safe_href: rejected
  href` with the truncated value. The empty `href=""` causes a
  same-page reload on click (visibly broken in dev tools, but
  not exploitable).
- The directive **never throws**. One invalid href in a single
  block must not 500 the page.

**Output is HTML-attribute-safe.** The result of the allowlist may
contain `&`, `?`, `=`, `'`, etc. (legitimate URL chars); the
directive runs `Sanitizer::escape()` before returning so the
surrounding `href="..."` cannot be broken out of.

**Path markers:** by the time the directive runs, `~/...` and
`^/...` markers in attribute values have already been resolved by
`PageLoader::resolvePaths`. A raw marker reaching the directive is
rejected — that signals a resolution step was skipped upstream.

**When `{{ x }}` is sufficient.** If the value provably comes from
a non-author-controlled source — e.g., a hardcoded literal in the
template itself, or a context variable derived from `config/` and
not user-controllable — `{{ x }}` is enough. The migration in this
project, however, used `safe_href` even for config-derived hrefs
(`URLS.x`, `s.url`) as defence-in-depth: the cost is one allowlist
check per render, the gain is that any future change which lets
request input flow into routes / lang state is automatically
caught.

### `lookup` directive

`{% lookup: <map_expr>, key: <key_expr> %}` evaluates two expressions
to a map and a key, returns `map[key]` HTML-escaped.

**Why it exists.** The Expression sub-language supports only
dot-paths with literal segments — `URLS.imprint` works because
`imprint` is in the template source. `URLS[item.slug]` is not
expressible: there is no bracket-indexing form, and dot-paths
can't take a runtime value as a segment. This directive bridges
that gap for the cases where a key is only known at render time:
editor-driven nav data referencing routed slugs, code → message
lookups, anything that walks a map with a runtime key.

**Usage:**
```html
<a href="{% lookup: URLS, key: item.slug %}">{{ item.label }}</a>

<p>{% lookup: CURRENT_LANG.home.contact.form.errors, key: errorCode %}</p>
```

**Failure modes:**

- Map expression doesn't resolve to an array → empty output, `Log::warn`
  entry tagged `lookup: map expression did not resolve to an array`.
- Key not present in the map → empty output, `Log::warn` entry tagged
  `lookup: key not found in map` (truncated key for log hygiene).
- Looked-up value is an array/object → coerces to empty string via
  `Sanitizer::stringify` (consistent with `{{ }}`).
- The directive **never throws**.

**Output is HTML-escaped.** Same default as `{{ }}`. If you need
URL-attribute safety on top, wrap with `{% safe_href %}` — but
note that values pulled from server-built maps (like `URLS`)
don't usually need re-validation. `lookup` and `safe_href` are
orthogonal: one resolves the value, the other validates it.

**Text-mode** (`Engine::renderText`) skips the escape pass,
consistent with `{{ }}` in plain-text mail bodies.

### `alias` directive

`{% alias: { name: <expr>, … } %}` … `{% endalias %}` binds named
values into a block scope. Inside the body, `{{ name }}` refers to
the evaluated expression. The bindings are gone at `{% endalias %}`.

**Scope mechanism.** The directive merges the evaluated object onto
the body's render context — the same per-scope context-copy
mechanism that `for` uses for its `loop` / `item` / `attrs`
bindings, applied without iteration. PHP array value-types make
this safe: the parent context is never mutated.

**Usage:**
```html
{# Avoid evaluating the same expression twice. #}
{% alias: { isCurrent: PAGE == item.slug } %}
  <a href="{% lookup: URLS, key: item.slug %}"
     class="nav-link{% if: isCurrent %} is-current{% endif %}"
     {% if: isCurrent %}aria-current="page"{% endif %}>{{ item.label }}</a>
{% endalias %}

{# Give a long path a short local name. #}
{% alias: { tier: pricing.tiers.pro } %}
  <h3>{{ tier.title }}</h3>
  <p>{{ tier.price }} / {{ tier.period }}</p>
{% endalias %}
```

**Scope behaviour:**

- Body sees the parent context PLUS the merged aliases.
- `{% include %}` inside the body still gets the entire child
  context (parent + aliases) because `IncludeDirective` copies the
  context for the child render. To isolate, pass an explicit
  `attrs: <expr>` on the include.
- Outside the block, the parent context is unchanged.
- Aliases can shadow context names (an alias named `URLS` would
  hide the global). Don't shadow names the body still needs.

**Forgiving runtime.** If the argument doesn't evaluate to an array
(scalar passed, null path, etc.), the body still renders, but with
no new bindings — references to the missing names resolve to null,
identical to any other missing path. A `Log::warn` entry tagged
`alias: bindings expression did not resolve to an array` records
the misuse without 500-ing the page.

**Block, not iteration.** `alias` always renders its body — even
when the aliases array is empty. It's a binding form, not a loop.

### `debug` directive

`{% debug: _all %}` dumps the entire render context as
pretty-printed JSON inside a `<pre class="debug">` block. Excluded
top-level keys (CSRF token, captcha state, honeypot field name)
are filtered out.

`{% debug: <path> %}` dumps a single value at the given dot-path,
same formatting. If the path's first segment names an excluded
top-level key, the dump renders `[excluded by debug policy: <key>]`
instead — the exclusion applies whether the dump is enumerative
(`_all`) or targeted.

**Two gates, both required for any output:**

1. `config/app.php → debug` is truthy.
2. Not in text-mode render (mail-template flag absent).

When either gate fails, the directive renders an empty string —
silent. A forgotten `{% debug %}` left in a production template
emits nothing and never crashes.

**Excluded keys (`EXCLUDED_KEYS` in the directive class):**

- `FORM_TOKEN` — CSRF token; leaking it would enable authenticated
  POST replay.
- `CAPTCHA` — proof-of-work nonce + salt + difficulty; an attacker
  who sees the salt can pre-compute solutions for the form's
  grace period.
- `HONEYPOT_FIELD` — field name derived from the server secret;
  leaking it lets a bot avoid the trap.

Adding a key to the list is cheap; removing one is a security
decision that should be justified in code review.

**Usage:**
```html
{% debug: _all %}          {# whole context, minus excluded keys  #}
{% debug: URLS %}          {# one branch                          #}
{% debug: CURRENT_LANG.nav %}
{% debug: FORM_TOKEN %}    {# renders the excluded marker         #}
```

**Output security:**

- Always `htmlspecialchars`'d before emission, even though the
  surrounding tag is `<pre>`. Without escape, a value containing
  `</pre><script>…` would break out of the sandbox tag and execute.
  Defense-in-depth: gated PLUS escaped.
- `_all` enumerates **top-level keys only**. Nested sensitive data
  (none present today) would need its own filter pass if added in
  the future.

**Style:** `<pre class="debug">` so themes can style the dump
(monospace, max-height with scroll, background) if they want. No
baseline CSS is bundled; the block renders perfectly readable even
with no styles applied.

#### `{!! !!}` vs `{% html: %}`

Both bypass the default escape, but for different inputs:

- `{!! expr !!}` — sanitises to `<em>`-only. Designed for short i18n
  strings that highlight a word. Anything other than literal `<em>` /
  `</em>` is escaped to text.
- `{% html: expr %}` — emits verbatim with **no** post-processing.
  Use **only** on values produced by trusted server-side rendering
  with its own allowlist. The legitimate callers today are all
  `{% html: body %}` invocations in block partials whose `body`
  comes from `H42\WhimCMS\Content\Markdown::render()` (the safe
  Markdown subset): `partials/blocks/legal-section.html`,
  `partials/blocks/prose.html`, `partials/blocks/code-snippet.html`.
  Audit grep:
  ```
  grep -rn "% html:" templates/
  ```
  Every match must trace to a value that went through
  `Markdown::render()`. New uses require review.

## Expression sub-language

Inside directive bodies, expressions can be:

| Form | Example | Meaning |
|---|---|---|
| String literal | `'foo'` or `"foo"` | Quoted literal; `\\`, `\'`, `\"`, `\n`, `\t` work |
| Number | `42`, `3.14` | Coerced to `int` / `float` |
| Boolean / null | `true` / `false` / `null` | Literals |
| Bare path | `CURRENT_LANG.home.hero.title` | Dot-path lookup against the context |
| Wrapped path | `%CURRENT_LANG.home.hero.title%` | Same, explicit form (rarely needed) |
| Object literal | `{ key: value, key: value }` | Nested object, values are themselves expressions |
| Array literal | `[ a, b, c ]` | Inline list |

Conditions add operators: `==`, `!=`, `&&`, `||`, `!` (precedence:
`!` > `==`/`!=` > `&&` > `||`).

```
{% if: PAGE == 'home' && SEO.indexable %}…{% endif %}
{% if: !FORM_GLOBAL_ERROR %}…{% endif %}
{% if: PAGE == 'demo-business' %}…{% endif %}
```

Truthy semantics:
- `null` → false
- empty array → false; non-empty → true
- everything else → standard PHP `(bool)` cast

## Always-available context

Every template — layout or block partial — sees these keys, set in
`Frontend/RenderContext::build()`:

| Key | Type | Notes |
|---|---|---|
| `CURRENT_LANG` | array | Active language's i18n dictionary; path markers resolved |
| `LANG` | string | Two-letter code (`en`, `de`) |
| `LANGS` | list | All supported codes |
| `PAGE` | string | Slug of the current page (`home`, `about`, `_error`, …) |
| `BASE` | string | Deployment base path (e.g. `""` or `/site`) |
| `META` | `{title, description}` | Resolved from front-matter then i18n fallback |
| `BLOCKS` | list\<Block\> \| null | Set when the page has a `.md`; null for legacy / `_404` |
| `PAGE_TEMPLATE` | string | Legacy template path (used by `{% if: !BLOCKS %}`) |
| `MULTI_LANG` | bool | `false` when only one language is configured |
| `LANG_SWITCH` | list of `{code, url, active}` | Drives the language switcher |
| `URLS` | map | Slug-keyed URLs in the active language: `URLS.about` etc. |
| `CURRENT_PAGE_URL` | string | Full URL of the current page |
| `SEO` | array | Canonical, hreflang alternates, OG, Twitter, JSON-LD blob |
| `EMAIL` | map | Per-key obfuscated/raw struct from `EmailProtection` |
| `CAPTCHA` | array | Proof-of-work challenge struct |
| `CONTACT_ENABLED` | bool | Mirrors `config/contact.php → contact.enabled`. Used by the `contact` block partial to gate the form column |
| `THEME_URL` | string | URL prefix for theme-served assets, derived from `paths.theme`. `''` when paths.theme = '.', `'/theme'` when paths.theme = 'theme'. Use as `{{ BASE }}{{ THEME_URL }}/styles/X.css` etc. — never hard-code `/theme/` |
| `FORM_TOKEN` | string | Fresh CSRF token, every render |
| `FORM_SENT` | bool | `true` when redirected with `?sent=1` |
| `FORM_ERRORS` | map | Per-field error keys after a failed POST |
| `FORM_VALUES` | map | Field values to repopulate after a failed POST |
| `FORM_GLOBAL_ERROR` | string | Top-level error key (`token`, `rate_limit`, …) |
| `HONEYPOT_FIELD` | string | Field `name` for the contact-form honeypot input. Derived per-installation from the application secret (see `H42\WhimCMS\Security\Form\Honeypot::resolveFieldName`); the controller resolves the same value when reading `$_POST`. Use as `name="{{ HONEYPOT_FIELD }}"` — never hard-code a literal. |
| Plus everything under `config/app.php → globals` | | Currently `CACHE_BUSTER` |

Inside a block partial there are two extra keys:

| Key | Type | Set by |
|---|---|---|
| `attrs` | array | `BlocksDirective` — the block's attribute map (parsed from the `.md`) |
| `body` | string | `BlocksDirective` — pre-rendered Markdown HTML (or `""` if the block has no body) |

The `attrs` slot is the local sub-scope. It is rebound by every
context-narrowing directive:

- `BlocksDirective` rebinds `attrs` to a block's attribute map.
- `ForDirective` (inline-include form) rebinds `attrs` to the
  current loop item.
- `IncludeDirective` rebinds `attrs` when called with an
  `attrs: <expr>` argument.

Example sub-include from inside a block partial:
`{% include: 'partials/section-head', attrs: { eyebrow: attrs.eyebrow, title: attrs.title } %}`.

`CURRENT_LANG.x` (the global language dictionary) is **never**
rebound, so a block partial can read both at the same time —
`attrs.title` for its own data, `CURRENT_LANG.common.response` for
shared chrome strings.

## Sanitisation

Three output surfaces:

| Function | Used by | Behaviour |
|---|---|---|
| `Sanitizer::escape()` | `{{ }}` (default) | `htmlspecialchars(... ENT_QUOTES \| ENT_SUBSTITUTE, 'UTF-8')` |
| `Sanitizer::sanitizeEm()` | `{!! !!}` | Same escape, then restore literal `<em>` / `</em>` from sentinels |
| `Sanitizer::stringify()` | All output sites | Coerces non-string values to strings; arrays/objects render empty (deliberate — accidentally dumping a structure into HTML is rarely what we want) |

`{% html: %}` bypasses all three — that's the audit-restricted case.

## Path-marker resolution

In page content, two prefix characters resolve at parse time:

| Marker | Resolves to | Where |
|---|---|---|
| `~/x` | `/<lang>/x` (multi-lang) or `/x` (single-lang) | Attribute string values; Markdown link `href`s |
| `^/x` | `/<base>/x` | Same |

Markers must be at position 0 of the value. `{% include: 'partials/x' %}`
takes a path string but **not** a path marker — that include path is
project-internal and validated by `Engine::resolveTemplatePath`.

In the i18n dictionary, the same markers are resolved by `I18n::load`
when the JSON loads. So both content (`.md`) and chrome (`.json`)
behave the same.

## Markdown safe subset

The Markdown body of a block (everything after the `---` separator
inside `:::`) is rendered by `lib/WhimCMS/Content/Markdown.php`. The output
allowlist is small and explicit:

**Block-level**
- Paragraphs (text separated by blank lines)
- Headings: `## h2`, `### h3`, `#### h4` (no h1, h5, h6)
- Unordered lists: `- item` per line
- Fenced code blocks: opened by ` ``` ` (optionally followed by a
  language tag matching `[a-z0-9_+-]+`), closed by a line containing
  only ` ``` `. Body is HTML-escaped and emitted verbatim — no inline
  parsing inside, no path-marker resolution, no link processing.
  Used by the `code-snippet` block whose code body is too long to fit
  in an attribute scalar.

**Inline**
- `**bold**` → `<strong>`
- `*italic*` → `<em>`
- `` `code` `` → `<code>`
- `[text](href)` → `<a href="…">text</a>`

**Link `href` allowlist**
- `https://…` (no credentials in URL)
- `mailto:…`, `tel:…`
- Relative `/…` or `#…`
- Path markers `~/…` / `^/…`

Everything else: ignored as text. `<` / `>` / `&` always escape.
Inline HTML, images via `![]()`, ordered lists, blockquotes, tables,
footnotes, autolinks, reference-style links, HTML entities, escape
sequences — all unsupported. Adding any of these requires a
security review (see [SECURITY.md](SECURITY.md)).

DoS guards:
- Max input bytes: 256 KiB
- Max inline recursion depth: 3 (e.g. `**foo *bar* baz**`)
- Max URL length: 2 KiB

## Adding a new block type

End-to-end, ~3 minutes. Example: a `quote` block.

### 1. Write the block partial with its `{@ block @}` header

```
templates/partials/blocks/quote.html
```

```html
{@ block
  required: text
  optional: id attribution
@}
{# Block: quote — single-quote highlight section. #}
<section class="quote"{% if: attrs.id %} id="{{ attrs.id }}"{% endif %}>
  <div class="container">
    <blockquote class="quote-text">{{ attrs.text }}</blockquote>
    {% if: attrs.attribution %}
    <p class="quote-attr">— {{ attrs.attribution }}</p>
    {% endif %}
  </div>
</section>
```

That's the entire change — no config edit, no class edit. The Engine's
boot scan picks up the new partial automatically:

- **Block type** = filename without `.html` (`quote`).
- **Schema** = the `{@ block @}` header at the top.
- `required` and `optional` are space-separated lists of attribute
  names matching `[a-zA-Z][a-zA-Z0-9_]*`. Either field can be omitted
  when empty.

### 2. Use it in any `.md`

```yaml
::: quote
text: We don't rise to the level of our expectations; we fall to the level of our training.
attribution: Archilochus
:::
```

### Notes

- The block type (= filename) must match `[a-z][a-z0-9-]{0,40}`.
  Lowercase with hyphens — same convention as CSS class names.
- The partial path is resolved by `Engine::resolveTemplatePath` —
  filesystem-confined under `templates/`. A typo in the filename
  fails at the first `.md` reference with a clear "unknown block
  type" message.
- The `{@ block @}` header is parsed at boot. A malformed header,
  duplicated key, or invalid attribute name fails loud at boot, not
  at first render.
- `{@ … @}` produces no output token. The header cannot leak to the
  rendered page — a structural guarantee, not a sanitisation step.
- Required attributes are checked at parse time (loud failure on the
  source line). Type-shape isn't enforced beyond presence — block
  partials are the contract for shape. If a partial expects
  `attrs.items` to be a list and the `.md` provides a string, the
  partial renders empty.
- If the block has a Markdown body, emit it via `{% html: body %}`
  (see audit contract above). Empty body renders as empty string.
- Block partials inherit the parent context — `URLS`, `EMAIL`,
  `CACHE_BUSTER`, `CURRENT_LANG`, `BASE`, etc. are all available.
  The block's own attributes are at `attrs.<key>`; nested attributes
  at `attrs.<key>.<inner>`.

## Adding a new directive

When a built-in directive isn't expressive enough — for example, a
`{% pluralize: count, one: '…', many: '…' %}` directive.

The directive system is **self-registering**: each directive declares
its own keywords (Tokenizer dispatch), token types (Renderer dispatch),
and produces typed `Token`s itself. The Tokenizer is keyword-agnostic;
the Renderer is token-type-agnostic. Adding a directive does NOT
require changes to either.

### 1. Implement the directive

```
lib/WhimCMS/Template/Directives/PluralizeDirective.php
```

```php
<?php
declare(strict_types=1);

namespace H42\WhimCMS\Template\Directives;

use H42\WhimCMS\Template\{Directive, Expression, Renderer, Sanitizer, Token};

final class PluralizeDirective implements Directive
{
    public function keywords(): array
    {
        // Body keywords this directive owns inside {% ... %}.
        return ['pluralize'];
    }

    public function tokenize(string $keyword, array $args): Token
    {
        // Turn the parsed argument list into a typed token.
        return new Token('pluralize', [
            'count' => $args['pluralize'],
            'one'   => $args['one']  ?? "''",
            'many'  => $args['many'] ?? "''",
        ]);
    }

    public function handles(): array
    {
        // Token types produced by tokenize() that this directive renders.
        return ['pluralize'];
    }

    public function render(Token $token, array $ctx, Renderer $renderer): string
    {
        $n = (int)Expression::evaluate((string)$token->payload['count'], $ctx);
        $form = $n === 1
            ? Expression::evaluate((string)$token->payload['one'], $ctx)
            : Expression::evaluate((string)$token->payload['many'], $ctx);
        return Sanitizer::escape(Sanitizer::stringify($form));
    }

    public function renderBlock(Token $open, array $body, array $ctx, Renderer $renderer): string
    {
        throw new \LogicException('PluralizeDirective is not a block directive.');
    }
}
```

### 2. Register in `BuiltInDirectives::all()`

Open `lib/WhimCMS/Template/BuiltInDirectives.php` and add the new
class to the list:

```php
return [
    new Directives\TextDirective(),
    new Directives\VarDirective(),
    // … existing ones …
    new Directives\BlocksDirective($engine),
    new Directives\PluralizeDirective(),   // ← new
];
```

That's the entire wiring. The Engine builds keyword and token-type
maps from this list at construction time, with conflict checks
(two directives claiming the same keyword fails loud at boot).

### Notes

- **Block directives** (open + matching close, like `{% if %}…{% endif %}`):
  - `keywords()` lists both the open and close keywords (e.g.
    `['if', 'endif']`).
  - `tokenize()` returns a Token with `closesWithType` set on the
    open form, and `isClose: true` on the close form. The Renderer
    pairs them by these fields, never by querying the directive.
  - `renderBlock()` does the work; `render()` should throw.
  - See `IfDirective` and `ForDirective` for examples.

- **Non-block directives** (single-token like `{% include %}`):
  - `tokenize()` returns a plain Token with no `closesWithType`.
  - `render()` does the work; `renderBlock()` should throw.

- **Output-only directives** (like `var`, `raw`, `text` — produced
  directly by the Tokenizer for `{{ }}`, `{!! !!}`, plain text):
  return `[]` from `keywords()` and throw from `tokenize()`. They
  participate only in render-time dispatch via `handles()`.

- **Annotation-consuming directives**: also implement
  `AnnotationConsumer` (see `BlocksDirective` for the pattern).
  Declare `annotationNames()`, `eagerScanPaths()`, and
  `consumeAnnotation($templateName, $payload)`. The Engine's boot
  scan picks them up automatically; nothing else needs to know.

## Layouts

Pages render through `templates/layout.html` by default. To support
a different shell (e.g. for landing pages without a nav, or with
different scripts):

1. Create `templates/layout-<name>.html`. Copy the default and
   change what's needed.
2. Add `<name>` to `config/content.php → content.allowed_layouts`:
   ```php
   'content' => [
       'allowed_layouts' => ['default', 'landing'],
   ],
   ```
3. In the page's `.md` front-matter, set `layout: <name>`.

The kernel maps `layout: default` → `layout.html` and any other
allowlisted name `<x>` → `layout-<x>.html`. A name not in the
allowlist is a parse error — three independent gates (front-matter
allowlist check, kernel re-check, template-engine root containment)
between author input and a filesystem read.

## Engine internals (if you need to dig)

- **Tokenization** is single-pass over template source. Stops on
  `{{`, `{!!`, `{%`, `{#`; everything else accumulates into `text`
  tokens. Stray openers without close (`{% include` without `%}`)
  throw at tokenize time.
- **Token cache**: `Engine::$compiled` is keyed by template name, so
  the same template tokenises once per request even if rendered
  many times (`{% for: …, include: 'partials/door-card' %}` would
  otherwise re-tokenize the same partial per iteration).
- **Block-directive matching** is depth-balanced: `Renderer::collectBlock`
  pairs `for_open` with the next `for_close` at the same depth, so
  `{% for: a %}…{% for: b %}…{% endfor %}…{% endfor %}` works.
- **Path containment**: `Engine::resolveTemplatePath` checks the
  template name against `[A-Za-z0-9/_\-]+` (no `..`), then runs
  `realpath` and confirms the resolved file is strictly under
  `templates/`. Defence-in-depth against template-injection
  vectors.
- **Text mode**: `Engine::renderText()` flips a context flag that
  causes `{{ }}` and `{!! !!}` to skip escape entirely. Used by the
  Mailer to render `text/plain` bodies without `&amp;` litter.
  Inherits through `{% include %}` — same model as Twig's
  autoescape strategy.
