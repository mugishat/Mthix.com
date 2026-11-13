<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\View;

function cfg(string $key, mixed $default = null): mixed
{
    return $GLOBALS['cfg'][$key] ?? $default;
}

/** HTML-escape. Every dynamic value in a view goes through this. */
function e(mixed $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** RWF has no minor unit; all money in the system is an integer number of francs. */
function money(int|float|null $n): string
{
    return 'RWF ' . number_format((int)round((float)($n ?? 0)));
}

function url(string $path = '/'): string
{
    return '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    $file = ROOT . '/public/assets/' . ltrim($path, '/');
    $v = is_file($file) ? filemtime($file) : 0;
    return '/assets/' . ltrim($path, '/') . '?v=' . $v;
}

function redirect(string $to): never
{
    header('Location: ' . $to);
    exit;
}

function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(Csrf::token()) . '">';
}

function old(string $key, string $default = ''): string
{
    return e($_SESSION['old'][$key] ?? $default);
}

function keep_old(array $data): void
{
    unset($data['password'], $data['password2'], $data['_csrf']);
    $_SESSION['old'] = $data;
}

function clear_old(): void
{
    unset($_SESSION['old']);
}

function now(): string
{
    return gmdate('Y-m-d H:i:s');
}

/** Stored timestamps are UTC; show them in Kigali time. */
function dt(?string $utc, string $fmt = 'M j, Y H:i'): string
{
    if (!$utc) {
        return '—';
    }
    $d = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    return $d->setTimezone(new DateTimeZone('Africa/Kigali'))->format($fmt);
}

function post(string $key, string $default = ''): string
{
    return trim((string)($_POST[$key] ?? $default));
}

function get(string $key, string $default = ''): string
{
    return trim((string)($_GET[$key] ?? $default));
}

function abort(int $code, string $message = ''): never
{
    http_response_code($code);
    $titles = [403 => 'Access denied', 404 => 'Page not found', 419 => 'Session expired', 500 => 'Something went wrong'];
    echo View::render('errors/error', [
        'code'    => $code,
        'title'   => $titles[$code] ?? 'Error',
        'message' => $message,
    ]);
    exit;
}

function status_label(string $s): string
{
    return ucwords(str_replace('_', ' ', $s));
}

function status_badge(string $s): string
{
    $map = [
        'pending_payment' => 'warn', 'paid' => 'info', 'confirmed' => 'info', 'packed' => 'info',
        'shipped' => 'purple', 'out_for_delivery' => 'purple', 'delivered' => 'ok', 'completed' => 'ok',
        'cancelled' => 'bad', 'refunded' => 'bad',
        'active' => 'ok', 'draft' => 'muted', 'suspended' => 'bad', 'archived' => 'muted', 'banned' => 'bad',
        'requested' => 'warn', 'review' => 'warn', 'approved' => 'info', 'processing' => 'purple', 'rejected' => 'bad',
        'verified' => 'ok', 'pending' => 'warn', 'none' => 'muted', 'open' => 'warn', 'resolved' => 'ok',
        'success' => 'ok', 'failed' => 'bad', 'initiated' => 'warn', 'held' => 'info', 'released' => 'ok',
        'accepted' => 'info', 'picked_up' => 'purple', 'available' => 'ok',
    ];
    $cls = $map[$s] ?? 'muted';
    return '<span class="badge badge-' . $cls . '">' . e(status_label($s)) . '</span>';
}

function emoji_for(?string $slug): string
{
    return [
        'electronics' => '📱', 'fashion' => '👟', 'home-living' => '🏠', 'beauty' => '💄',
        'sports' => '⚽', 'automotive' => '🚗', 'books' => '📚', 'kids-babies' => '🧸', 'health' => '💊',
    ][$slug ?? ''] ?? '📦';
}

function icon(string $name): string
{
    static $icons = [
        'home' => '⌂', 'box' => '▣', 'cart' => '🛒', 'wallet' => '◈', 'user' => '☺', 'chart' => '▤',
        'bell' => '🔔', 'msg' => '✉', 'link' => '⛓', 'truck' => '🚚', 'shield' => '⛨', 'cog' => '⚙',
        'list' => '☰', 'tag' => '◆', 'star' => '★', 'money' => '₣', 'users' => '☻', 'log' => '≣',
    ];
    return $icons[$name] ?? '•';
}

function unread_notifications(): int
{
    if (!Auth::check()) {
        return 0;
    }
    return (int)App\Core\Db::val('SELECT COUNT(*) FROM notifications WHERE user_id=? AND read_at IS NULL', [Auth::id()]);
}

function cart_count(): int
{
    return array_sum($_SESSION['cart'] ?? []);
}

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function slugify(string $s): string
{
    $s = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $s) ?? '', '-'));
    return $s !== '' ? $s : 'item';
}

function paginate(int $total, int $page, int $per): array
{
    $pages = max(1, (int)ceil($total / $per));
    $page = min(max(1, $page), $pages);
    return ['page' => $page, 'pages' => $pages, 'per' => $per, 'offset' => ($page - 1) * $per, 'total' => $total];
}
