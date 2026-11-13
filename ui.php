<?php
declare(strict_types=1);

/** View helpers: dashboard menus and small dependency-free SVG charts. */

use App\Core\Db;

function abbr(int|float $n): string
{
    $n = (float)$n;
    if (abs($n) >= 1000000) {
        return rtrim(rtrim(number_format($n / 1000000, 1), '0'), '.') . 'M';
    }
    if (abs($n) >= 1000) {
        return rtrim(rtrim(number_format($n / 1000, 1), '0'), '.') . 'K';
    }
    return (string)(int)$n;
}

const CHART_COLORS = ['#16a34a', '#3b82f6', '#f59e0b', '#8b5cf6', '#ef4444', '#94a3b8'];

/** @param string[] $labels @param array<string,int[]> $series name => values */
function chart_line(array $labels, array $series, int $w = 640, int $h = 230): string
{
    $pl = 46; $pr = 12; $pt = 12; $pb = 26;
    $max = 1;
    foreach ($series as $vals) {
        $max = max($max, ...($vals ?: [1]));
    }
    $step = 10 ** max(0, strlen((string)$max) - 1);
    $max = (int)(ceil($max / $step) * $step);
    $n = max(1, count($labels) - 1);
    $x = fn(int $i) => $pl + ($w - $pl - $pr) * ($i / $n);
    $y = fn(float $v) => $pt + ($h - $pt - $pb) * (1 - $v / $max);

    $svg = '<svg class="chart" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="Line chart">';
    for ($g = 0; $g <= 4; $g++) {
        $gv = $max * $g / 4;
        $svg .= '<line x1="' . $pl . '" x2="' . ($w - $pr) . '" y1="' . $y($gv) . '" y2="' . $y($gv) . '" class="grid"/>'
            . '<text x="' . ($pl - 6) . '" y="' . ($y($gv) + 4) . '" text-anchor="end" class="axis">' . abbr($gv) . '</text>';
    }
    $every = max(1, (int)ceil(count($labels) / 7));
    foreach ($labels as $i => $lab) {
        if ($i % $every === 0) {
            $svg .= '<text x="' . $x($i) . '" y="' . ($h - 8) . '" text-anchor="middle" class="axis">' . e($lab) . '</text>';
        }
    }
    $ci = 0;
    foreach ($series as $name => $vals) {
        $col = CHART_COLORS[$ci % count(CHART_COLORS)];
        $pts = [];
        foreach (array_values($vals) as $i => $v) {
            $pts[] = round($x($i), 1) . ',' . round($y((float)$v), 1);
        }
        if ($ci === 0 && $pts) {
            $svg .= '<polygon points="' . $pl . ',' . $y(0) . ' ' . implode(' ', $pts) . ' ' . $x(count($vals) - 1) . ',' . $y(0) . '" fill="' . $col . '" opacity=".08"/>';
        }
        $svg .= '<polyline points="' . implode(' ', $pts) . '" fill="none" stroke="' . $col . '" stroke-width="2.2" stroke-linejoin="round"><title>' . e($name) . '</title></polyline>';
        $ci++;
    }
    return $svg . '</svg>';
}

/** @param string[] $labels @param int[] $values */
function chart_bars(array $labels, array $values, int $w = 640, int $h = 230): string
{
    $pl = 46; $pr = 12; $pt = 12; $pb = 26;
    $max = max(1, ...($values ?: [1]));
    $step = 10 ** max(0, strlen((string)$max) - 1);
    $max = (int)(ceil($max / $step) * $step);
    $n = max(1, count($values));
    $slot = ($w - $pl - $pr) / $n;
    $bw = min(34, $slot * 0.6);
    $svg = '<svg class="chart" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="Bar chart">';
    for ($g = 0; $g <= 4; $g++) {
        $gv = $max * $g / 4;
        $yy = $pt + ($h - $pt - $pb) * (1 - $gv / $max);
        $svg .= '<line x1="' . $pl . '" x2="' . ($w - $pr) . '" y1="' . $yy . '" y2="' . $yy . '" class="grid"/>'
            . '<text x="' . ($pl - 6) . '" y="' . ($yy + 4) . '" text-anchor="end" class="axis">' . abbr($gv) . '</text>';
    }
    foreach (array_values($values) as $i => $v) {
        $bh = ($h - $pt - $pb) * ($v / $max);
        $bx = $pl + $slot * $i + ($slot - $bw) / 2;
        $svg .= '<rect x="' . round($bx, 1) . '" y="' . round($h - $pb - $bh, 1) . '" width="' . round($bw, 1) . '" height="' . round($bh, 1) . '" rx="3" fill="#16a34a"><title>' . e($labels[$i] ?? '') . ': ' . number_format($v) . '</title></rect>'
            . '<text x="' . round($bx + $bw / 2, 1) . '" y="' . ($h - 8) . '" text-anchor="middle" class="axis">' . e($labels[$i] ?? '') . '</text>';
    }
    return $svg . '</svg>';
}

/** @param array<string,int> $parts label => value */
function chart_donut(array $parts, string $center = '', string $sub = '', int $size = 170): string
{
    $total = array_sum($parts) ?: 1;
    $r = 62; $c = 2 * M_PI * $r; $off = 0.0; $i = 0;
    $svg = '<svg class="donut" viewBox="0 0 170 170" width="' . $size . '" height="' . $size . '" role="img" aria-label="Donut chart">';
    foreach ($parts as $label => $v) {
        if ($v <= 0) { $i++; continue; }
        $len = $c * $v / $total;
        $svg .= '<circle cx="85" cy="85" r="' . $r . '" fill="none" stroke="' . CHART_COLORS[$i % count(CHART_COLORS)] . '" stroke-width="20" stroke-dasharray="' . round($len, 2) . ' ' . round($c - $len, 2) . '" stroke-dashoffset="' . round(-$off, 2) . '" transform="rotate(-90 85 85)"><title>' . e($label) . ': ' . number_format($v) . '</title></circle>';
        $off += $len;
        $i++;
    }
    $svg .= '<text x="85" y="86" text-anchor="middle" class="donut-n">' . e($center) . '</text><text x="85" y="104" text-anchor="middle" class="axis">' . e($sub) . '</text></svg>';
    return $svg;
}

function chart_legend(array $parts): string
{
    $total = array_sum($parts) ?: 1;
    $html = '<ul class="legend">';
    $i = 0;
    foreach ($parts as $label => $v) {
        $html .= '<li><span class="dot" style="background:' . CHART_COLORS[$i % count(CHART_COLORS)] . '"></span>' . e($label)
            . '<b>' . number_format($v) . '</b><em>' . round(100 * $v / $total) . '%</em></li>';
        $i++;
    }
    return $html . '</ul>';
}

/** Sidebar entries per dashboard. @return array<int,array{0:string,1:string,2:string,3:?int}> */
function dash_menu(string $role): array
{
    $uid = (int)\App\Core\Auth::id();
    $unreadMsg = (int)Db::val('SELECT COUNT(*) FROM messages WHERE to_id = ? AND read_at IS NULL', [$uid]);
    $notif = unread_notifications();
    $common = [
        ['Messages', '/messages', 'msg', $unreadMsg ?: null],
        ['Notifications', '/notifications', 'bell', $notif ?: null],
        ['Wallet', '/wallet', 'wallet', null],
        ['Verification (KYC)', '/kyc', 'shield', null],
    ];
    switch ($role) {
        case 'seller':
            $sid = (int)Db::val('SELECT id FROM stores WHERE user_id = ?', [$uid]);
            $new = (int)Db::val("SELECT COUNT(*) FROM orders WHERE store_id = ? AND status IN ('paid','confirmed','packed')", [$sid]);
            return array_merge([
                ['Dashboard', '/seller', 'home', null],
                ['Products', '/seller/products', 'box', null],
                ['Orders', '/seller/orders', 'list', $new ?: null],
                ['Store settings', '/seller/store', 'cog', null],
            ], $common);
        case 'reseller':
            return array_merge([
                ['Dashboard', '/reseller', 'home', null],
                ['Products to promote', '/reseller/products', 'box', null],
                ['Orders & earnings', '/reseller/orders', 'list', null],
            ], $common);
        case 'affiliate':
            return array_merge([
                ['Dashboard', '/affiliate', 'home', null],
                ['Orders & earnings', '/affiliate/orders', 'list', null],
            ], $common);
        case 'delivery':
            return array_merge([
                ['Dashboard', '/delivery', 'home', null],
                ['Available jobs', '/delivery/jobs', 'truck', null],
                ['My deliveries', '/delivery/mine', 'list', null],
            ], $common);
        case 'admin':
            $kyc = (int)Db::val("SELECT COUNT(*) FROM users WHERE kyc_status = 'pending'");
            $wd = (int)Db::val("SELECT COUNT(*) FROM withdrawals WHERE status IN ('review','approved')");
            $dp = (int)Db::val("SELECT COUNT(*) FROM disputes WHERE status = 'open'");
            $items = [['Dashboard', '/admin', 'home', null]];
            if (\App\Core\Auth::hasAny(['admin', 'support_admin'])) {
                $items = array_merge($items, [
                    ['Users', '/admin/users', 'users', null],
                    ['KYC queue', '/admin/kyc', 'shield', $kyc ?: null],
                    ['Products', '/admin/products', 'box', null],
                    ['Orders', '/admin/orders', 'list', null],
                    ['Disputes', '/admin/disputes', 'tag', $dp ?: null],
                ]);
            }
            if (\App\Core\Auth::hasAny(['admin', 'finance_admin'])) {
                $items = array_merge($items, [
                    ['Payments & escrow', '/admin/payments', 'money', null],
                    ['Withdrawals', '/admin/withdrawals', 'wallet', $wd ?: null],
                    ['Commission rules', '/admin/commissions', 'chart', null],
                ]);
            }
            if (\App\Core\Auth::hasRole('admin')) {
                $items[] = ['Audit log', '/admin/audit', 'log', null];
            }
            $items[] = ['Notifications', '/notifications', 'bell', $notif ?: null];
            return $items;
        default: // buyer
            return [
                ['Overview', '/account', 'home', null],
                ['My orders', '/account/orders', 'list', null],
                ['Wishlist', '/account/wishlist', 'star', null],
                ['Messages', '/messages', 'msg', $unreadMsg ?: null],
                ['Notifications', '/notifications', 'bell', $notif ?: null],
                ['Wallet', '/wallet', 'wallet', null],
                ['Profile & security', '/account/settings', 'cog', null],
            ];
    }
}

function dash_active(string $href): bool
{
    $path = rtrim((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/') ?: '/';
    $roots = ['/seller', '/reseller', '/affiliate', '/delivery', '/admin', '/account'];
    return in_array($href, $roots, true) ? $path === $href : str_starts_with($path, $href);
}

function stars(float $rating): string
{
    $full = (int)round($rating);
    return '<span class="stars" aria-label="' . e(number_format($rating, 1)) . ' out of 5">' . str_repeat('★', $full) . str_repeat('☆', 5 - $full) . '</span>';
}

/** Product thumbnail: uploaded image, else a coloured tile with the category emoji. */
function product_thumb(array $p, string $cls = ''): string
{
    if (!empty($p['image'])) {
        return '<img class="thumb ' . e($cls) . '" src="/uploads/' . e($p['image']) . '" alt="' . e($p['name']) . '" loading="lazy">';
    }
    $hue = crc32((string)$p['name']) % 360;
    return '<span class="thumb ph ' . e($cls) . '" style="background:hsl(' . $hue . ',55%,93%)" aria-hidden="true">' . emoji_for($p['cat_slug'] ?? null) . '</span>';
}
