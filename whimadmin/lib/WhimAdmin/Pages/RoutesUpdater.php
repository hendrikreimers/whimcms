<?php
declare(strict_types=1);

namespace H42\WhimAdmin\Pages;

use H42\WhimAdmin\Config\PhpArrayWriter;
use H42\WhimCMS\Content\Identifiers;

/**
 * High-level operations on `config/routes.php`.
 *
 * Built on top of the existing PhpArrayWriter (which whitelists this
 * file and runs a round-trip integrity probe before atomic rename).
 * This class adds the small operation vocabulary the tree mutator
 * needs:
 *
 *   readAll()                       — full routes payload
 *   read(lang)                      — map for one language
 *   addEntry(lang, url, slug)       — insert; rejects on collision
 *   removeBySlug(lang, slug)        — remove every URL pointing to slug
 *   renameSlug(lang, oldSlug, new)  — rewrite values; preserve URL keys
 *   changeUrl(lang, slug, newUrl)   — replace the URL key for a slug
 *   writeAll(payload)               — full atomic save via PhpArrayWriter
 *
 * The in-memory operations are pure transforms; persistence goes
 * through writeAll(). That keeps the multi-step tree mutator
 * conceptually two-phase (mutate-in-memory, then commit) and avoids
 * thrash on routes.php for a single user action that touches both
 * slug and URL.
 *
 * URL-path validation matches PhpArrayWriter::validateShape's segment
 * regex ('' or `^[a-zA-Z0-9_/-]{1,64}$`) — anything that wouldn't
 * survive the writer's shape validator is rejected up front so the
 * caller sees a clear error message rather than the writer's
 * fail-loud at commit time.
 */
final class RoutesUpdater
{
    private const URL_PATTERN = '#^[a-zA-Z0-9_/-]{1,64}$#';

    /** @var array<string, array<string, string>>|null  payload['routes'] cached after readAll() */
    private ?array $cache = null;

    public function __construct(
        private PhpArrayWriter $writer,
        private string $routesPath,    // <core>/config/routes.php — only used to load via require
    ) {
    }

    /**
     * @return array<string, array<string, string>>  lang => url => slug
     */
    public function readAll(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $this->cache = [];
        $real = realpath($this->routesPath);
        if ($real === false) {
            return $this->cache;
        }
        try {
            $loaded = require $real;
        } catch (\Throwable) {
            $loaded = null;
        }
        if (!is_array($loaded) || !is_array($loaded['routes'] ?? null)) {
            return $this->cache;
        }
        foreach ($loaded['routes'] as $lang => $map) {
            if (!is_string($lang) || !Identifiers::isValidLang($lang)) continue;
            if (!is_array($map)) continue;
            $clean = [];
            foreach ($map as $url => $slug) {
                if (!is_string($url) || !is_string($slug)) continue;
                if ($url !== '' && preg_match(self::URL_PATTERN, $url) !== 1) continue;
                if (!Identifiers::isValidSlug($slug)) continue;
                $clean[$url] = $slug;
            }
            $this->cache[$lang] = $clean;
        }
        return $this->cache;
    }

    /**
     * @return array<string, string>  url => slug for one language (empty if no entries)
     */
    public function read(string $lang): array
    {
        Identifiers::assertLang($lang);
        return $this->readAll()[$lang] ?? [];
    }

    public function urlForSlug(string $lang, string $slug): ?string
    {
        Identifiers::assertLang($lang);
        Identifiers::assertSlug($slug);
        foreach ($this->read($lang) as $url => $s) {
            if ($s === $slug) return $url;
        }
        return null;
    }

    public function slugExists(string $lang, string $slug): bool
    {
        return $this->urlForSlug($lang, $slug) !== null;
    }

    public function urlInUse(string $lang, string $url): bool
    {
        Identifiers::assertLang($lang);
        $this->validateUrl($url);
        return array_key_exists($url, $this->read($lang));
    }

    /**
     * Insert a new (url, slug) pair. Rejects on either collision.
     * In-memory only; call commit() to persist.
     */
    public function addEntry(string $lang, string $url, string $slug): void
    {
        Identifiers::assertLang($lang);
        Identifiers::assertSlug($slug);
        $this->validateUrl($url);
        $this->readAll(); // populate cache
        $this->cache[$lang] ??= [];
        if (isset($this->cache[$lang][$url])) {
            throw new \RuntimeException("Route URL '{$url}' already in use for language '{$lang}'.");
        }
        if (in_array($slug, $this->cache[$lang], true)) {
            throw new \RuntimeException("Slug '{$slug}' already routed in language '{$lang}'.");
        }
        $this->cache[$lang][$url] = $slug;
    }

    /**
     * Remove all URLs mapping to a given slug in one language.
     * No-op when the slug has no route entry.
     */
    public function removeBySlug(string $lang, string $slug): void
    {
        Identifiers::assertLang($lang);
        Identifiers::assertSlug($slug);
        $this->readAll();
        if (!isset($this->cache[$lang])) {
            return;
        }
        $next = [];
        foreach ($this->cache[$lang] as $u => $s) {
            if ($s !== $slug) {
                $next[$u] = $s;
            }
        }
        $this->cache[$lang] = $next;
    }

    /**
     * Rewrite slug values from $oldSlug to $newSlug for one language.
     * URL keys are preserved. Rejects when $newSlug already maps to a
     * different URL in the same language.
     */
    public function renameSlug(string $lang, string $oldSlug, string $newSlug): void
    {
        Identifiers::assertLang($lang);
        Identifiers::assertSlug($oldSlug);
        Identifiers::assertSlug($newSlug);
        if ($oldSlug === $newSlug) return;
        $this->readAll();
        if (!isset($this->cache[$lang])) {
            return;
        }
        if (in_array($newSlug, $this->cache[$lang], true)) {
            throw new \RuntimeException("Slug '{$newSlug}' already routed in language '{$lang}'.");
        }
        foreach ($this->cache[$lang] as $u => $s) {
            if ($s === $oldSlug) {
                $this->cache[$lang][$u] = $newSlug;
            }
        }
    }

    /**
     * Replace the URL key for a given slug in one language. The new
     * URL must not already be in use (any slug collision is reported
     * with the existing slug name so the UI can offer a meaningful
     * resolution path).
     */
    public function changeUrl(string $lang, string $slug, string $newUrl): void
    {
        Identifiers::assertLang($lang);
        Identifiers::assertSlug($slug);
        $this->validateUrl($newUrl);
        $this->readAll();
        if (!isset($this->cache[$lang])) {
            throw new \RuntimeException("Language '{$lang}' has no routes yet — cannot change URL.");
        }
        $oldUrl = null;
        foreach ($this->cache[$lang] as $u => $s) {
            if ($s === $slug) { $oldUrl = $u; break; }
        }
        if ($oldUrl === null) {
            throw new \RuntimeException("Slug '{$slug}' is not routed in language '{$lang}'.");
        }
        if ($oldUrl === $newUrl) return;
        if (isset($this->cache[$lang][$newUrl])) {
            $existing = $this->cache[$lang][$newUrl];
            throw new \RuntimeException(
                "Route URL '{$newUrl}' is already used by slug '{$existing}' in language '{$lang}'."
            );
        }
        unset($this->cache[$lang][$oldUrl]);
        $this->cache[$lang][$newUrl] = $slug;
    }

    /**
     * Persist the current in-memory routes table. Goes through the
     * existing PhpArrayWriter which re-runs its own validateShape
     * and round-trip probe before committing — two independent
     * validators in series.
     */
    public function commit(): void
    {
        $this->readAll();
        $payload = ['routes' => $this->cache ?? []];
        $this->writer->write(PhpArrayWriter::TARGET_ROUTES, $payload);
        // Force a re-read on next access so we pick up our own write.
        $this->cache = null;
    }

    /**
     * Discard in-memory mutations and re-read from disk.
     */
    public function reset(): void
    {
        $this->cache = null;
    }

    /**
     * Restore an explicit routes snapshot. Used by TreeMutator's
     * rollback path — a prior step took `readAll()` to capture the
     * pre-mutation state, then on failure replays the snapshot
     * through this entry point.
     *
     * Bypasses the in-memory cache deliberately: rollback must hit
     * disk regardless of the cache state, and the cache is invalidated
     * after the write so subsequent reads reflect the rollback.
     *
     * @param array<string, array<string, string>> $snapshot  lang => url => slug
     */
    public function forceWrite(array $snapshot): void
    {
        $payload = ['routes' => $snapshot];
        $this->writer->write(PhpArrayWriter::TARGET_ROUTES, $payload);
        $this->cache = null;
    }

    private function validateUrl(string $url): void
    {
        // Empty URL is the home page; allowed only with care from
        // the controller (only one home per language). Validation
        // here matches PhpArrayWriter::validateShape.
        if ($url === '') return;
        if (preg_match(self::URL_PATTERN, $url) !== 1) {
            throw new \RuntimeException("Bad route URL '{$url}' (allowed: a-z, 0-9, '_', '-', '/').");
        }
        if (strpos($url, '..') !== false) {
            throw new \RuntimeException("Route URL must not contain '..'.");
        }
        if (str_starts_with($url, '/') || str_ends_with($url, '/')) {
            throw new \RuntimeException("Route URL must not start or end with '/'.");
        }
    }
}
