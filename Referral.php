<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/**
 * Reseller / affiliate attribution.
 *   Reseller link : /product/{slug}?r=CODE   -> credited only for orders containing that product
 *   Affiliate link: /?a=CODE (any page)      -> credited for any order inside the attribution window
 * The last link a visitor opened wins. A user can never earn from their own purchase or their own store.
 */
final class Referral
{
    public static function capture(): void
    {
        $days = (int)cfg('attribution_days');
        $opts = ['expires' => time() + $days * 86400, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])];

        $r = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($_GET['r'] ?? '')) ?? '');
        if ($r !== '' && preg_match('#^/product/([^/]+)$#', (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), $m)) {
            $u = Db::one("SELECT u.id FROM users u JOIN user_roles r ON r.user_id = u.id AND r.role = 'reseller' WHERE u.ref_code = ? AND u.status = 'active'", [$r]);
            $p = Db::one("SELECT id FROM products WHERE slug = ? AND status = 'active' AND reseller_eligible = 1", [$m[1]]);
            if ($u && $p) {
                setcookie('mthix_r', $r . ':' . $p['id'], $opts);
                self::click('reseller', (int)$u['id'], (int)$p['id']);
            }
        }

        $a = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($_GET['a'] ?? '')) ?? '');
        if ($a !== '') {
            $u = Db::one("SELECT u.id FROM users u JOIN user_roles r ON r.user_id = u.id AND r.role = 'affiliate' WHERE u.ref_code = ? AND u.status = 'active'", [$a]);
            if ($u) {
                setcookie('mthix_a', $a, $opts);
                self::click('affiliate', (int)$u['id'], null);
            }
        }
    }

    private static function click(string $kind, int $userId, ?int $productId): void
    {
        $hash = hash('sha256', client_ip() . ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $dup = Db::val(
            "SELECT COUNT(*) FROM referral_clicks WHERE kind = ? AND user_id = ? AND ip_hash = ? AND COALESCE(product_id,0) = ? AND created_at > datetime('now','-1 hour')",
            [$kind, $userId, $hash, (int)$productId]
        );
        if (!$dup) {
            Db::run('INSERT INTO referral_clicks (kind, user_id, product_id, ip_hash) VALUES (?,?,?,?)', [$kind, $userId, $productId, $hash]);
        }
    }

    /** @return array{reseller_id:?int,reseller_product:?int,affiliate_id:?int} */
    public static function resolve(int $buyerId): array
    {
        $out = ['reseller_id' => null, 'reseller_product' => null, 'affiliate_id' => null];

        if (!empty($_COOKIE['mthix_r']) && preg_match('/^([A-Z0-9]+):(\d+)$/', (string)$_COOKIE['mthix_r'], $m)) {
            $u = Db::one("SELECT u.id FROM users u JOIN user_roles r ON r.user_id = u.id AND r.role = 'reseller' WHERE u.ref_code = ? AND u.status = 'active'", [$m[1]]);
            if ($u && (int)$u['id'] !== $buyerId) {
                $out['reseller_id'] = (int)$u['id'];
                $out['reseller_product'] = (int)$m[2];
            }
        }
        if (!empty($_COOKIE['mthix_a']) && preg_match('/^[A-Z0-9]+$/', (string)$_COOKIE['mthix_a'])) {
            $u = Db::one("SELECT u.id FROM users u JOIN user_roles r ON r.user_id = u.id AND r.role = 'affiliate' WHERE u.ref_code = ? AND u.status = 'active'", [$_COOKIE['mthix_a']]);
            if ($u && (int)$u['id'] !== $buyerId) {
                $out['affiliate_id'] = (int)$u['id'];
            }
        }
        return $out;
    }
}
