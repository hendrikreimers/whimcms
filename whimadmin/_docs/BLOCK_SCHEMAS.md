# Block schemas

How the page editor knows which fields a block accepts, what type
each field is, and how to render it.

## Two-source resolution

For every block type that the active theme exposes, WhimAdmin
combines two pieces of data:

1. **The partial's `{@ block @}` annotation**
   `<theme>/templates/partials/blocks/<type>.html` carries this
   header (read by the public site's `BlocksDirective`):

   ```html
   {@ block
     required: title
     optional: id eyebrow lede image imageAlt align
   @}
   ```

   This is **authoritative for field NAMES** — both the public-side
   loader and WhimAdmin trust the same set of (required ∪ optional)
   keys. Adding a field anywhere else doesn't make it valid.

2. **The sidecar JSON**
   `whimadmin/config/blocks/<type>.json` describes each field's
   **TYPE** for the editor. Optional — missing fields fall through
   to a heuristic.

`BlockSchemaLoader::resolveSingle` cross-validates: every JSON
field MUST appear in the partial's annotation, otherwise boot
fails loud (drift catch).

## Sidecar JSON format

```json
{
  "label": "Hero",
  "description": "First block on a landing page.",
  "fields": {
    "title":            { "type": "text", "label": "Title (em allowed)" },
    "eyebrow":          { "type": "text" },
    "lede":             { "type": "textarea" },
    "image":            { "type": "image" },
    "imageAlt":         { "type": "text" },
    "align":            { "type": "select", "options": ["start", "center"], "default": "start" },
    "focusX":           { "type": "number", "min": 0, "max": 1, "step": 0.05, "default": 0.5 },
    "focusY":           { "type": "number", "min": 0, "max": 1, "step": 0.05, "default": 0.5 },
    "ctaPrimary":       { "type": "text" },
    "ctaPrimaryHref":   { "type": "link" },
    "ctaSecondary":     { "type": "text" },
    "ctaSecondaryHref": { "type": "link" }
  }
}
```

Top-level keys:

| Key | Required | Notes |
|---|---|---|
| `label` | no | Human label for the editor's block list. Defaults to type-name humanised. |
| `description` | no | Tooltip / sub-text. |
| `fields` | yes | Map of field-name → field-spec. Field name MUST be in the partial's `{@ block @}` annotation. |
| `body` | no | Optional Field-spec for the block's Markdown body (between `---` separator and closing `:::`). When set, the editor always shows a body input; when absent, the body input only appears for blocks that already have body content. |

## Field types

| Type | Renders as | Extra config |
|---|---|---|
| `text` | single-line `<input type="text">` | `label`, `default` |
| `textarea` | multi-line `<textarea>` | `label`, `default` |
| `markdown` | textarea + Markdown toolbar (B/I/H2/H3/H4/list/link/code/codeblock) | `label` |
| `image` | text input + Browse button + datalist autocomplete + asset-picker modal | `label` |
| `link` | text input (URL / `~/page` / `#anchor`) | `label` |
| `bool` | checkbox + hidden empty-value pair (so unchecked submits as empty string, matching the AttributeParser convention) | `label` |
| `number` | `<input type="number">` | `min`, `max`, `step`, `default` |
| `select` | `<select>` with options | `options` (list of strings), `default` |
| `icon` | `<select>` populated from icon names auto-discovered in `<theme>/templates/partials/icons/glyph.html` | `label` |
| `list` | repeating fieldset with add / remove / drag (in v2) | `of` (a Field-spec for each item) |
| `map` | inline sub-form | `shape` (object of nested Field-specs) |

## Lists of maps — the common case

Most "items" attributes in WhimCMS are lists of maps. Sidecar
example for `pillars.json`:

```json
{
  "label": "Pillars",
  "fields": {
    "items": {
      "type": "list",
      "of": {
        "type": "map",
        "shape": {
          "title": { "type": "text" },
          "body":  { "type": "textarea" }
        }
      }
    }
  }
}
```

The `AttributeParser` allows ONE level of map nesting inside a list
item. Nested lists inside list items are not supported (the parser
rejects them); use `feat1, feat2, …` flattened keys when more
structure is needed (see `pricing.json`).

## Heuristic fallback

When a field appears in the partial's `{@ block @}` annotation but
not in the sidecar JSON (or no sidecar exists at all), the editor
applies `BlockSchemaLoader::heuristic` — a name-based guess:

| Field name pattern | Field type |
|---|---|
| `icon` | `icon` |
| `image`, `bgImage`, `path`, `*Image`, `*Img` | `image` |
| `*Href` | `link` |
| `*Alt` | `text` |
| `body`, `lede`, `description` | `textarea` |
| `focusX`, `focusY` | `number` 0..1 step 0.05 |
| `items` | `list` of `text` (sidecar override needed for map shape) |
| `featured`, `lightbox`, `enabled` | `bool` |
| `align` | `select` `[start, center]` |
| (anything else) | `text` |

The heuristic is good enough that the bundled blocks render
correctly even WITHOUT their sidecar JSONs — but the editor experience
is noticeably nicer when typed (e.g. `feature-grid.items` is a list
of `{icon, title, body}` per the sidecar; the heuristic alone would
treat it as a list of strings).

## Adding a new field type

1. Pick a short identifier (e.g. `color`).
2. Create `whimadmin/views/fields/color.html`:

   ```html
   <label class="field">
     <span class="field-label">{{ LABEL }}</span>
     <input class="field-input" type="color" name="{{ NAME }}" value="{{ VALUE }}">
   </label>
   ```

3. Add the type to `FieldSchema::ALLOWED_TYPES`:
   `lib/WhimAdmin/Content/FieldSchema.php`.
4. Wire the rendering case in `FormRenderer::renderField` (the
   `match` expression at the top of the method).
5. Wire the decoding case in `FormDecoder::decodeValue`.
6. Reference the new type from a sidecar:
   `{ "type": "color" }`.

The `BlockSchemaLoader` rejects sidecar JSONs that reference an
unknown type (no partial in `views/fields/`) — saves you from
silent rendering failure.

## Adding a new block type

This is a **theme-side** task (the partial lives under
`<theme>/templates/partials/blocks/`). After the partial exists:

1. Drop `whimadmin/config/blocks/<type>.json` for typed editor UI.
2. Reload the editor — the BlockSchemaLoader picks it up next request.

If you skip step 1, the editor still works via heuristic fallback;
saves succeed; the form just isn't as ergonomic as for sidecar-
described blocks.

## Auditor's tour

If you're reviewing the block-form pipeline:

1. **Load** — `BlockSchemaLoader::all()` discovers partials, scans
   `{@ block @}` via the core's `Tokenizer`, looks up the sidecar,
   cross-validates field names, instantiates `BlockSchema`.
2. **Render** — `FormRenderer::renderBlock` walks the schema,
   dispatches each field to its `renderXxx` method, which delegates
   to the corresponding `views/fields/<type>.html`. Each value
   passes through the engine's HTML-escape (`{{ }}`) before reaching
   the DOM. List/map types recurse via PHP, NOT the engine (the
   engine has no string-concat in expressions).
3. **Decode** — `FormDecoder::decode` consumes `Request::postAll()`
   (a sanitised nested tree). Per-field decoding is type-aware;
   unknown values fall to scalar text. Empty fields are pruned so
   the on-disk source stays minimal.
4. **Save** — `PageRepository::save` runs the round-trip integrity
   check, takes a `HistoryStore` snapshot, atomic-writes via
   tempfile + rename. The public site's PageLoader detects the
   bumped mtime on next request and re-renders.

Every step is testable in isolation — feed `BlockSchemaLoader` a
test partial, feed `FormRenderer` a fake schema, etc. There is no
hidden global state; each instance is constructed fresh per request.
