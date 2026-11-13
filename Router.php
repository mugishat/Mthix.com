<?php
declare(strict_types=1);

namespace App\Core;

final class Router
{
    private array $routes = [];

    /**
     * @param ?array $roles null = public; [] = any signed-in user; ['seller', ...] = needs one of those roles.
     */
    public function add(string $method, string $pattern, array $handler, ?array $roles = null, bool $csrf = true): void
    {
        $re = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
        $this->routes[] = ['m' => $method, 're' => $re, 'h' => $handler, 'roles' => $roles, 'csrf' => $csrf];
    }

    public function get(string $p, array $h, ?array $roles = null): void
    {
        $this->add('GET', $p, $h, $roles);
    }

    public function post(string $p, array $h, ?array $roles = null, bool $csrf = true): void
    {
        $this->add('POST', $p, $h, $roles, $csrf);
    }

    public function dispatch(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
        $path = rtrim($path, '/') ?: '/';

        foreach ($this->routes as $r) {
            if ($r['m'] !== $method || !preg_match($r['re'], $path, $m)) {
                continue;
            }
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);

            if ($r['roles'] !== null) {
                if (!Auth::check()) {
                    flash('warn', 'Please sign in to continue.');
                    redirect('/login?next=' . urlencode($path));
                }
                if ($r['roles'] !== [] && !Auth::hasAny($r['roles'])) {
                    abort(403, 'Your account does not have permission to open this page.');
                }
            }
            if ($method === 'POST' && $r['csrf'] && !Csrf::valid()) {
                abort(419, 'Your session expired. Go back, refresh the page and try again.');
            }

            [$class, $fn] = $r['h'];
            try {
                (new $class())->$fn($params);
            } catch (\App\Services\DomainException $e) {
                // Business-rule violations are shown to the user, never as a stack trace.
                if ($method === 'POST') {
                    flash('error', $e->getMessage());
                    redirect($_SERVER['HTTP_REFERER'] ?? '/');
                }
                abort(400, $e->getMessage());
            }
            return;
        }
        abort(404);
    }
}
