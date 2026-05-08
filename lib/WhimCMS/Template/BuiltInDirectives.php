<?php
declare(strict_types=1);

namespace H42\WhimCMS\Template;

/**
 * Single canonical list of built-in directives, instantiated once per
 * Engine. Adding a new directive type to the engine is one line here.
 *
 * The factory takes the Engine instance because some directives need a
 * back-reference (e.g. BlocksDirective resolves the BlockRegistry via
 * `$engine->blocks()` at render time). Most directives ignore the
 * argument; the uniform signature keeps the call site simple and means
 * future directives that need engine services don't require changes
 * elsewhere.
 *
 * The Engine consumes this list and self-derives:
 *   - the keyword → directive map  (Tokenizer dispatch)
 *   - the token-type → directive map  (Renderer dispatch)
 *   - the annotation registry  (any directive implementing
 *     AnnotationConsumer is auto-wired into the boot scan)
 *
 * Nothing else in the codebase references concrete directive classes by
 * name. The Tokenizer is keyword-agnostic; the Renderer is type-agnostic;
 * the Kernel does not see directives at all.
 */
final class BuiltInDirectives
{
    /**
     * @return list<Directive>
     */
    public static function all(Engine $engine): array
    {
        return [
            new Directives\TextDirective(),
            new Directives\VarDirective(),
            new Directives\RawDirective(),
            new Directives\IncludeDirective(),
            new Directives\IfDirective(),
            new Directives\ForDirective(),
            new Directives\HtmlDirective(),
            new Directives\BlocksDirective($engine),
            new Directives\ImageDirective($engine),
            new Directives\SafeHrefDirective(),
        ];
    }
}
