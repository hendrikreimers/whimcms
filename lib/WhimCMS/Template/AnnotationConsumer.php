<?php
declare(strict_types=1);

namespace H42\WhimCMS\Template;

/**
 * Optional capability interface a Directive can implement to receive
 * compile-time annotations.
 *
 * Annotations live in templates as `{@ <name>` … `@}` blocks. They are
 * parsed by the Tokenizer at compile time but produce no output token —
 * they are pure metadata. The Engine collects every annotation under the
 * scan paths declared by each AnnotationConsumer and forwards each
 * matching annotation to the consumer's consumeAnnotation() hook before
 * the first render.
 *
 * The mechanism is generic. The first user is BlocksDirective: it consumes
 * `{@ block @}` annotations from `partials/blocks/*.html` to build the
 * block-type → schema map. Future use cases (e.g. `{@ cache ttl: 60 @}`,
 * `{@ layout extends: base @}`) plug in via the same interface without
 * any change to the Tokenizer or Engine.
 *
 * Step 1 wires only the interface; the scanning + dispatch path lands
 * in step 2 alongside the `{@ @}` syntax in the Tokenizer.
 */
interface AnnotationConsumer
{
    /**
     * Annotation names this directive owns. The Engine maps each name to
     * this consumer at boot. Two consumers claiming the same name is a
     * boot-time error.
     *
     * @return list<string>
     */
    public function annotationNames(): array;

    /**
     * Glob patterns (relative to the template root) the Engine should
     * eagerly scan at boot to harvest annotations for this consumer.
     * Empty list means "scan nothing eagerly" — the consumer only sees
     * annotations from templates that get tokenised on the lazy path.
     *
     * @return list<string>
     */
    public function eagerScanPaths(): array;

    /**
     * Called once per template that contains an annotation owned by this
     * consumer. `$payload` is the merged map of all annotation key/value
     * pairs for this consumer in that template (a template may contain
     * multiple `{@ <name> … @}` blocks; the Engine merges them before
     * calling).
     *
     * `$templateName` is the name as it would be passed to
     * Engine::render() — relative to the template root, no extension.
     *
     * @param array<string, string> $payload
     */
    public function consumeAnnotation(string $templateName, array $payload): void;
}
