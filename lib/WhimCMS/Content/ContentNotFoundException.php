<?php
declare(strict_types=1);

namespace H42\WhimCMS\Content;

/**
 * Thrown by PageLoader when the content file for a (lang, slug) pair does
 * not exist on disk. Distinct from "file existed but was malformed",
 * which surfaces as a ParseException and ends up at the 500 path.
 *
 * Who catches it, and what actually happens:
 *
 *   Frontend\PageRenderer  sets $page = null and continues via the legacy
 *                          per-page template path (`pages/<slug>`). That
 *                          answers **200**, not 404 — a slug with a theme
 *                          template but no content file is a supported
 *                          arrangement, not an error.
 *   Seo\Sitemap            skips the (slug, lang) pair and carries on.
 *   Seo\LlmsTxt            same.
 *
 * ⚠️ The Kernel does NOT catch this exception and never has. This
 * docblock previously claimed "The Kernel translates this into a normal
 * 404 render" — wrong actor, wrong outcome. The 404 path is
 * PageRenderer::renderNotFound, reached by other means.
 */
final class ContentNotFoundException extends \RuntimeException
{
}
