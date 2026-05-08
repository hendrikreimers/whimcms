<?php
declare(strict_types=1);

namespace H42\WhimAdmin\Content;

use H42\WhimAdmin\View\Renderer;

/**
 * Render a Block's editing form by walking its BlockSchema and the
 * current attribute values together. PHP-side recursion (rather than
 * template-side) is required because the engine's expression
 * sub-language has no string-concat / dynamic include — building
 * `name="block[0][attr][items][3][title]"` paths inline in templates
 * is impossible.
 *
 * Each per-type render method delegates to a small view partial in
 * `whimadmin/views/fields/<type>.html`, passing the right context.
 *
 * Output is HTML; values are htmlspecialchars-escaped via the engine
 * (`{{ }}`). The template element used for list-clone carries the
 * literal placeholder `__WIDX__` at the index position; the JS module
 * `js/fields/list.js` substitutes the next index when cloning.
 */
final class FormRenderer
{
    public const LIST_INDEX_PLACEHOLDER = '__WIDX__';

    public function __construct(
        private Renderer $views,
        private IconLibrary $icons,
    ) {
    }

    /**
     * Render one block's full form body (header bar with type/cut/move,
     * the schema fields, the optional body editor). The top-level
     * `block[<idx>]` envelope is the caller's responsibility.
     *
     * @param array<string, mixed> $attrs current attribute tree
     */
    public function renderBlock(string $path, BlockSchema $schema, array $attrs, ?string $body, int $index): string
    {
        $out = '';
        foreach ($schema->fields as $name => $field) {
            $value = $attrs[$name] ?? null;
            $required = in_array($name, $schema->required, true);
            $label = ($field->label ?? self::humanise($name)) . ($required ? ' *' : '');
            $out .= $this->renderField($path . '[' . $name . ']', $value, $field, $label);
        }
        // Render the body input when EITHER the schema declares a body
        // field (block accepts Markdown body by spec) OR the loaded
        // block currently has body content (preserves authored data
        // even for blocks without a sidecar body declaration).
        $shouldRenderBody = $schema->bodyField !== null || $body !== null;
        if ($shouldRenderBody) {
            $bodyField = $schema->bodyField ?? new FieldSchema('markdown');
            $bodyValue = $body ?? '';
            $bodyLabel = $bodyField->label ?? 'Body (Markdown)';
            $bodyPath  = $this->bodyPath($index);
            $out .= match ($bodyField->type) {
                'textarea' => $this->renderTextarea($bodyPath, $bodyValue, $bodyLabel),
                default    => $this->renderMarkdown($bodyPath, $bodyValue, $bodyLabel),
            };
        }
        return $out;
    }

    /**
     * Path used for the body of block at index $i. The page-level
     * decoder reads it via `$_POST['block'][$i]['body']`.
     */
    public function bodyPath(int $i): string
    {
        return 'block[' . $i . '][body]';
    }

    /** Recursive entry — dispatch on field.type. */
    public function renderField(string $path, mixed $value, FieldSchema $field, string $label): string
    {
        return match ($field->type) {
            'text', 'link', 'image' => $this->renderScalarInput($path, $field->type, self::str($value), $label),
            'textarea'              => $this->renderTextarea($path, self::str($value), $label),
            'markdown'              => $this->renderMarkdown($path, self::str($value), $label),
            'bool'                  => $this->renderBool($path, self::str($value), $label),
            'number'                => $this->renderNumber($path, self::str($value), $field, $label),
            'select'                => $this->renderSelect($path, self::str($value), $field, $label),
            'icon'                  => $this->renderIcon($path, self::str($value), $label),
            'list'                  => $this->renderList($path, is_array($value) ? $value : [], $field, $label),
            'map'                   => $this->renderMap($path, is_array($value) ? $value : [], $field, $label),
        };
    }

    // ---------- per-type renderers ----------

    private function renderScalarInput(string $path, string $type, string $value, string $label): string
    {
        return $this->views->render('fields/' . $type, [
            'NAME'  => $path,
            'VALUE' => $value,
            'LABEL' => $label,
        ]);
    }

    private function renderTextarea(string $path, string $value, string $label): string
    {
        return $this->views->render('fields/textarea', [
            'NAME'  => $path,
            'VALUE' => $value,
            'LABEL' => $label,
        ]);
    }

    private function renderMarkdown(string $path, string $value, string $label): string
    {
        return $this->views->render('fields/markdown', [
            'NAME'  => $path,
            'VALUE' => $value,
            'LABEL' => $label,
        ]);
    }

    private function renderBool(string $path, string $value, string $label): string
    {
        return $this->views->render('fields/bool', [
            'NAME'    => $path,
            'LABEL'   => $label,
            'CHECKED' => $value === 'true' ? 'yes' : '',
        ]);
    }

    private function renderNumber(string $path, string $value, FieldSchema $field, string $label): string
    {
        return $this->views->render('fields/number', [
            'NAME'  => $path,
            'VALUE' => $value === '' ? (string)($field->get('default') ?? '') : $value,
            'LABEL' => $label,
            'MIN'   => (string)($field->get('min')  ?? ''),
            'MAX'   => (string)($field->get('max')  ?? ''),
            'STEP'  => (string)($field->get('step') ?? 'any'),
        ]);
    }

    private function renderSelect(string $path, string $value, FieldSchema $field, string $label): string
    {
        $options = (array)($field->get('options') ?? []);
        $rendered = [];
        foreach ($options as $opt) {
            if (!is_string($opt)) continue;
            $rendered[] = ['value' => $opt, 'label' => $opt, 'selected' => $opt === $value];
        }
        return $this->views->render('fields/select', [
            'NAME'    => $path,
            'LABEL'   => $label,
            'OPTIONS' => $rendered,
        ]);
    }

    private function renderIcon(string $path, string $value, string $label): string
    {
        $names = $this->icons->names();
        $rendered = [];
        foreach ($names as $n) {
            $rendered[] = ['value' => $n, 'label' => $n, 'selected' => $n === $value];
        }
        return $this->views->render('fields/icon', [
            'NAME'           => $path,
            'LABEL'          => $label,
            'OPTIONS'        => $rendered,
            'NONE_SELECTED'  => $value === '' ? 'yes' : '',
        ]);
    }

    /**
     * @param list<mixed> $items
     */
    private function renderList(string $path, array $items, FieldSchema $field, string $label): string
    {
        $of = $field->get('of');
        if (!$of instanceof FieldSchema) {
            // Defensive — schema must specify item shape; without it
            // we can't render. Treat as empty.
            $of = new FieldSchema('text');
        }
        $itemsHtml = '';
        $i = 0;
        foreach ($items as $item) {
            $itemsHtml .= $this->renderListItem($path, $i, $item, $of);
            $i++;
        }
        $templateHtml = $this->renderListItem($path, self::LIST_INDEX_PLACEHOLDER, null, $of);

        return $this->views->render('fields/list', [
            'NAME'          => $path,
            'LABEL'         => $label,
            'ITEMS_HTML'    => $itemsHtml,
            'TEMPLATE_HTML' => $templateHtml,
        ]);
    }

    /**
     * Render a single list-item wrapper around the inner field
     * rendering. `$index` may be an integer or LIST_INDEX_PLACEHOLDER.
     */
    private function renderListItem(string $listPath, int|string $index, mixed $value, FieldSchema $itemField): string
    {
        $itemPath = $listPath . '[' . $index . ']';
        $inner = $this->renderField($itemPath, $value, $itemField, '');
        return $this->views->render('fields/list-item', [
            'INNER_HTML' => $inner,
        ]);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function renderMap(string $path, array $values, FieldSchema $field, string $label): string
    {
        $shape = $field->get('shape');
        if (!is_array($shape)) {
            $shape = [];
        }
        $itemsHtml = '';
        foreach ($shape as $key => $sub) {
            if (!$sub instanceof FieldSchema || !is_string($key)) continue;
            $value = $values[$key] ?? null;
            $subLabel = $sub->label ?? self::humanise($key);
            $itemsHtml .= $this->renderField($path . '[' . $key . ']', $value, $sub, $subLabel);
        }
        return $this->views->render('fields/map', [
            'NAME'       => $path,
            'LABEL'      => $label,
            'ITEMS_HTML' => $itemsHtml,
        ]);
    }

    // ---------- helpers ----------

    private static function str(mixed $v): string
    {
        return is_string($v) ? $v : '';
    }

    private static function humanise(string $key): string
    {
        // camelCase / kebab-case → "Camel case"
        $pretty = preg_replace('/([a-z])([A-Z])/', '$1 $2', $key) ?? $key;
        $pretty = str_replace(['-', '_'], ' ', $pretty);
        return ucfirst(strtolower($pretty));
    }
}
