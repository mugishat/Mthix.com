<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/** Session cart: variant_id => qty. Prices and stock are always re-read from the database. */
final class CartService
{
    public static function add(int $variantId, int $qty): void
    {
        $v = self::variant($variantId);
        if (!$v) {
            throw new DomainException('That item is not available.');
        }
        $qty = max(1, min(20, $qty));
        $new = min(20, ($_SESSION['cart'][$variantId] ?? 0) + $qty);
        if ($new > (int)$v['stock']) {
            throw new DomainException('Only ' . (int)$v['stock'] . ' left in stock.');
        }
        $_SESSION['cart'][$variantId] = $new;
    }

    public static function set(int $variantId, int $qty): void
    {
        if ($qty <= 0) {
            unset($_SESSION['cart'][$variantId]);
            return;
        }
        $v = self::variant($variantId);
        if (!$v) {
            unset($_SESSION['cart'][$variantId]);
            return;
        }
        $_SESSION['cart'][$variantId] = min(20, $qty, max(1, (int)$v['stock']));
    }

    public static function clear(): void
    {
        unset($_SESSION['cart']);
    }

    private static function variant(int $id): ?array
    {
        return Db::one(
            "SELECT v.* FROM variants v JOIN products p ON p.id = v.product_id JOIN stores s ON s.id = p.store_id
             WHERE v.id = ? AND v.active = 1 AND p.status = 'active' AND s.status = 'active'",
            [$id]
        );
    }

    /** @return array<int,array> cart lines, with a flag when stock is too low */
    public static function lines(): array
    {
        $out = [];
        foreach ($_SESSION['cart'] ?? [] as $variantId => $qty) {
            $row = Db::one(
                "SELECT v.id AS variant_id, v.sku, v.color, v.size, v.price, v.cost_price, v.stock,
                        p.id AS product_id, p.name, p.slug, p.image, p.reseller_eligible, c.slug AS cat_slug,
                        s.id AS store_id, s.name AS store_name
                 FROM variants v JOIN products p ON p.id = v.product_id JOIN stores s ON s.id = p.store_id
                 JOIN categories c ON c.id = p.category_id
                 WHERE v.id = ? AND v.active = 1 AND p.status = 'active' AND s.status = 'active'",
                [$variantId]
            );
            if (!$row) {
                unset($_SESSION['cart'][$variantId]);
                continue;
            }
            $row['qty'] = (int)$qty;
            $row['line_total'] = (int)$row['price'] * (int)$qty;
            $row['short'] = (int)$row['stock'] < (int)$qty;
            $row['label'] = implode(' / ', array_filter([$row['color'], $row['size']]));
            $out[] = $row;
        }
        return $out;
    }

    public static function subtotal(array $lines): int
    {
        return array_sum(array_column($lines, 'line_total'));
    }
}
