<?php
declare(strict_types=1);

namespace H42\WhimCMS\Maintenance;

use H42\WhimCMS\Cache\Sweeper;
use H42\WhimCMS\Log;

/**
 * Single trigger point for all maintenance sweepers of one kernel.
 *
 * Why a shutdown hook and not "the end of Kernel::run()": many request
 * paths leave the process via `exit` (Responder redirects, the image
 * endpoint, the early bad-request rejection). A call placed after
 * dispatch() would miss all of them; `register_shutdown_function`
 * fires on every path, including exits and fatals.
 *
 * FPM is used opportunistically, never required: when
 * `fastcgi_finish_request()` exists (PHP-FPM), the hook flushes and
 * closes the response FIRST, so the visitor never waits for a sweep.
 * Under mod_php / CLI the function is absent and the visitor waits at
 * most for one bounded sweep pass — each sweeper caps its deletions
 * per run, so a large backlog converges over several requests instead
 * of stalling one.
 *
 * Cost model in the hot path: each registered sweeper is sentinel-
 * gated inside `Sweeper::sweepIfDue()` — on a request where nothing is
 * due, the whole hook is one `filemtime` call per sweeper.
 *
 * Both kernels use the same trigger — registerShutdownHook(). The
 * public site registers it unconditionally in bootstrap; whimadmin
 * registers it only inside its authed-session gate (unauthenticated
 * traffic must not cause filesystem work there). sweepDue() is public
 * because the hook closure calls it and a future caller may want
 * explicit placement, but nothing calls it directly today.
 *
 * Failure mode: best-effort. The base class already suppresses and
 * logs everything thrown inside a sweep; the extra guard here is
 * belt-and-braces so a defective sweeper can never break the others
 * or the response.
 */
final class Coordinator
{
    /** @var list<Sweeper> */
    private array $sweepers;

    private bool $registered = false;

    public function __construct(Sweeper ...$sweepers)
    {
        $this->sweepers = array_values($sweepers);
    }

    /**
     * Run every sweeper that is due. Cheap when none is: one sentinel
     * mtime check each.
     */
    public function sweepDue(): void
    {
        foreach ($this->sweepers as $sweeper) {
            try {
                $sweeper->sweepIfDue();
            } catch (\Throwable $e) {
                Log::error('Maintenance: sweeper failed', [
                    'sweeper' => $sweeper::class,
                    'class'   => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Register the end-of-request trigger. Idempotent — a second call
     * is a no-op, so kernels can call it unconditionally.
     */
    public function registerShutdownHook(): void
    {
        if ($this->registered) {
            return;
        }
        $this->registered = true;
        register_shutdown_function(function (): void {
            // Under FPM: hand the finished response to the client
            // before doing any filesystem work. Absent elsewhere.
            if (\function_exists('fastcgi_finish_request')) {
                \fastcgi_finish_request();
            }
            $this->sweepDue();
        });
    }
}
