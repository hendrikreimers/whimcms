<?php
declare(strict_types=1);

namespace H42\WhimAdmin\Http;

/**
 * Tiny method+path router.
 *
 * Whimadmin's URL surface is small (≲30 routes across all phases) and
 * fully internal — no operator-tunable routing — so a deliberately
 * simple table beats anything fancier. Routes are added at boot via
 * `add()` and matched via `match()`; first match wins.
 *
 * Path format: literal segments only. Variables like `:id` are not
 * supported on purpose — every parameterised input arrives via query
 * string or POST body, where it goes through explicit validation in
 * the controller.
 */
final class Router
{
    /**
     * @var list<array{method:string, path:string, handler:callable}>
     */
    private array $routes = [];

    /**
     * @param string $method  Uppercase HTTP method (GET/POST/...)
     * @param string $path    Path AFTER the basePath, leading slash stripped (e.g. 'login' or '')
     * @param callable $handler  Receives the Request, returns a Response
     */
    public function add(string $method, string $path, callable $handler): void
    {
        $methodUp = strtoupper($method);
        if (!in_array($methodUp, ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'HEAD'], true)) {
            throw new \InvalidArgumentException("Bad method: {$method}");
        }
        if ($path !== '' && preg_match('#^[a-zA-Z0-9_/-]+$#', $path) !== 1) {
            throw new \InvalidArgumentException("Bad route path: {$path}");
        }
        $this->routes[] = [
            'method'  => $methodUp,
            'path'    => $path,
            'handler' => $handler,
        ];
    }

    /**
     * Find the matching handler for a request. Returns null on no match.
     */
    public function match(string $method, string $path): ?callable
    {
        $methodUp = strtoupper($method);
        foreach ($this->routes as $r) {
            if ($r['path'] === $path && $r['method'] === $methodUp) {
                return $r['handler'];
            }
        }
        return null;
    }
}
