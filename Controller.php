<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\View;

abstract class Controller
{
    /** Public / buyer-facing page. */
    protected function page(string $view, array $data = []): void
    {
        echo View::render($view, $data, 'layouts/main');
    }

    /** Role dashboard page (sidebar layout). */
    protected function dash(string $role, string $view, array $data = []): void
    {
        echo View::render($view, $data + ['dashRole' => $role], 'layouts/dash');
    }

    protected function uid(): int
    {
        return (int)Auth::id();
    }

    protected function storeOrFail(): array
    {
        $s = Db::one('SELECT * FROM stores WHERE user_id = ?', [$this->uid()]);
        if (!$s) {
            abort(403, 'You do not have a store yet.');
        }
        return $s;
    }

    protected function back(string $fallback = '/'): never
    {
        $ref = $_SERVER['HTTP_REFERER'] ?? '';
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $ok = $ref !== '' && parse_url($ref, PHP_URL_HOST) === explode(':', $host)[0];
        redirect($ok ? $ref : $fallback);
    }

    protected function intParam(array $src, string $key, int $default = 0): int
    {
        return isset($src[$key]) ? (int)$src[$key] : $default;
    }
}
