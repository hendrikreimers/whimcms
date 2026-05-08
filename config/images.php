<?php
declare(strict_types=1);

/**
 * Settings for the `{% image %}` template directive and its
 * cropped/resized cache.
 *
 * The directive is the only image-serving path. It produces variants
 * at template-render time, writes them to `<paths.var>/cache/img-cropped/`,
 * and emits URLs of the shape `/img-c/<basename>-<hash>.<ext>`. Those
 * URLs are then served read-only by `Image\CroppedServer` (no
 * client-driven cache writes — the URL space is bounded by what the
 * templates actually request).
 *
 * Knobs:
 *
 *   allowed_roots                 Top-level directories under the project
 *                                 root that source images may live in.
 *                                 Each entry is a relative path (no
 *                                 leading slash). Defaults to `assets`
 *                                 (raster content / photos) and
 *                                 `theme/assets` (theme-bundled raster).
 *                                 Anything not under one of these is
 *                                 rejected with an empty <img src=>.
 *                                 Legacy `allowed_root: '<single>'`
 *                                 (string) is still honoured for BC.
 *
 *   jpg_quality                   Encoder quality for JPEG/WebP variants
 *                                 (1..100; 85 is a safe default).
 *
 *   max_source_bytes              Decompression-bomb guard. Source
 *                                 images larger than this many bytes
 *                                 are skipped (the directive emits an
 *                                 empty src attribute and logs).
 *
 *   max_source_pixels             Same idea on the pixel-count side.
 *                                 Bounds GD's memory allocation:
 *                                 ~50 MP covers any realistic photo
 *                                 while keeping the worker below a few
 *                                 hundred MB of resident memory.
 *
 *   fallback_when_no_gd           What the directive does if ext-gd is
 *                                 not installed on the host.
 *                                   'serve_fail'     Default. Emit empty
 *                                                    src and log. The
 *                                                    broken image is
 *                                                    glaringly visible
 *                                                    — fast feedback
 *                                                    for the operator
 *                                                    to install GD.
 *                                   'serve_original' Emit the source URL
 *                                                    directly. Site keeps
 *                                                    working but ships
 *                                                    full-resolution
 *                                                    payloads.
 *                                 Unknown values are coerced to
 *                                 `serve_fail`.
 *
 *   cropped_cache_max_age         TTL (seconds) for files in
 *                                 `/cache/img-cropped/`. Older files get
 *                                 dropped at the next sweep. A still-
 *                                 referenced image regenerates on the
 *                                 next render with the same filename
 *                                 and a fresh mtime. Default 30 days.
 *                                 Floors at 1 hour internally.
 *
 *   cropped_cache_sweep_interval  Sweep interval (seconds) for
 *                                 `/cache/img-cropped/`. Sentinel-gated;
 *                                 runs at most once per interval per
 *                                 cache. Floors at 60 s. Triggered
 *                                 after each cache write the directive
 *                                 performs.
 *
 * The on-disk cache lives at `<paths.var>/cache/img-cropped/` and is
 * not configurable — runtime state belongs under paths.var, like
 * every other cache (content, mail-log, rate-limit, …).
 */

return [
    'images' => [
        'allowed_roots'                => ['assets', 'theme/assets'],
        'jpg_quality'                  => 85,
        'max_source_bytes'             => 25 * 1024 * 1024,  // 25 MB
        'max_source_pixels'            => 50_000_000,        // 50 MP
        'fallback_when_no_gd'          => 'serve_fail',
        'cropped_cache_max_age'        => 30 * 86400,        // 30 days
        'cropped_cache_sweep_interval' => 86400,             // 24 h
    ],
];
