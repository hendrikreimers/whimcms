# WhimCMS — Documentation

Server-rendered PHP CMS. Markdown content blocks, a custom template
engine, JSON-based i18n, and zero build pipeline. Everything edits
with a text editor;
nothing transpiles, nothing bundles, no `node_modules`.

## What's here

| Doc | When to read |
|---|---|
| [INSTALL.md](INSTALL.md) | Deploying for the first time, or moving hosts |
| [CONTENT.md](CONTENT.md) | Editing pages, adding pages, adding a language |
| [BLOCKS.md](BLOCKS.md) | Reference for every registered block type |
| [TEMPLATING.md](TEMPLATING.md) | Template-engine syntax and how to extend it |
| [ARCHITECTURE.md](ARCHITECTURE.md) | Code tour: classes, request lifecycle, data flow |
| [SECURITY.md](SECURITY.md) | Threat model, defence layers, deployment checks |

## The thirty-second tour

```
content/<lang>/<slug>.md   page content (block composition + prose)
i18n/<lang>.json           UI microcopy (nav, footer, forms, errors)
config/<section>.php       split per concern (app, i18n, routes, content,
                           seo, images, mail, email_protection, contact,
                           security, blocks)
templates/                 layout + per-block partials
lib/WhimCMS/               PHP code (PSR-4 under H42\WhimCMS\)
```

A request hits `index.php`, the `Kernel` resolves the URL to a
`(lang, slug)` pair, `PageLoader` parses `content/<lang>/<slug>.md`
into a list of typed `Block`s, the layout renders them via the
`{% blocks %}` directive, and chrome (nav, footer, forms) comes from
the i18n JSON. That's the whole picture.

If you want to **edit a paragraph**, open the matching `.md` file
under `content/<lang>/`. If you want to **change a nav label**, edit
`i18n/<lang>.json`. If you want to **add a new section type to the
toolbox**, you write a block partial and register it — see
[TEMPLATING.md](TEMPLATING.md).

## Stack signature

- PHP 8.1+ (uses `readonly` properties), Apache with `mod_rewrite` + `mod_headers`.
- No Composer, no vendor/, no third-party PHP libs. Everything in `lib/WhimCMS/` is hand-written.
- No JS framework. Plain ES modules under `js/`. CSP-strict (no inline scripts or styles).
- No CSS preprocessor. Four hand-edited CSS files under `styles/`.
- No CMS, no database. Content is files in `content/` and `i18n/`.
- One custom template engine (~600 lines under `lib/WhimCMS/Template/`).
- One custom content engine (~700 lines under `lib/WhimCMS/Content/`).

The intentional minimalism is documented in the internal audit
history — fewer moving parts, smaller attack surface, longer
half-life.

## Conventions

- **English everywhere in code and docs.** Site copy is per-language
  in `content/` and `i18n/`.
- **No emojis in code, comments, or docs** unless the user explicitly
  asks. Site copy can use any unicode it wants.
- **Strict typing.** Every PHP file starts with `declare(strict_types=1);`.
- **Hard-fail on bad input.** Unknown block type, missing required
  attribute, invalid UTF-8 — every one is a `ParseException` with a
  source line number, never a silent skip.
- **Audit before commit-to-prod.** When the surface changes, write
  an entry in the internal audit history before the change goes
  live.
