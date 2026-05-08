<?php
declare(strict_types=1);

namespace H42\WhimCMS\Frontend;

use H42\WhimCMS\I18n;

/**
 * Resolves the active language for a request.
 *
 * Two detection strategies, both bound to the same configured
 * (detectLang, defaultLang, supportedLangs) tuple:
 *
 *   - `detect()` — best-effort match against the `Accept-Language`
 *     header, falling back to defaultLang. Used by the dispatcher to
 *     pick the redirect target when the URL doesn't carry a language
 *     prefix yet (`/` → `/<lang>/`, `/segment` → `/<lang>/segment`).
 *
 *   - `forPath($path)` — extract the language from the leading URL
 *     segment when present (`/de/foo` → `de`); otherwise fall back to
 *     `detect()`. Used by the not-found path so a visitor mistyping
 *     a URL gets a 404 in the language they were already browsing.
 *
 * Pulled out of the Kernel into its own class so both the dispatcher
 * and the page renderer can share the same logic without duplicating
 * the "detection enabled?" / "is the result actually one we support?"
 * gating.
 */
final class LanguageDetector
{
    /**
     * @param list<string> $supportedLangs
     */
    public function __construct(
        private bool $detectLang,
        private string $defaultLang,
        private array $supportedLangs,
    ) {
    }

    /**
     * Pick the language for a request that has no language prefix yet.
     * Honours `detect_lang` config — when disabled, always returns
     * `defaultLang`. When enabled, parses the `Accept-Language` header
     * and verifies the result is in the supported list.
     */
    public function detect(): string
    {
        if (!$this->detectLang) {
            return $this->defaultLang;
        }
        $detected = I18n::detectFromAcceptLanguage(
            (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''),
            $this->supportedLangs,
            $this->defaultLang
        );
        return in_array($detected, $this->supportedLangs, true)
            ? $detected
            : $this->defaultLang;
    }

    /**
     * Pick the language for a path the router could not resolve (404).
     * Prefers the URL prefix (`/en/bla` → `en`, `/de/foo` → `de`) so
     * the 404 page renders in the language the visitor was already
     * browsing. Falls back to `detect()` when the path carries no
     * recognisable language prefix.
     *
     * In single-language mode there's only one possible answer; the
     * configured language is returned without parsing the path.
     */
    public function forPath(string $path): string
    {
        if (count($this->supportedLangs) === 1) {
            return $this->supportedLangs[0];
        }
        $firstSeg = explode('/', $path, 2)[0];
        if (in_array($firstSeg, $this->supportedLangs, true)) {
            return $firstSeg;
        }
        return $this->detect();
    }
}
