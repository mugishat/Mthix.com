<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/** Stock is reserved when an order is created and returned if the order is cancelled. Every change leaves a movement row. */
final class Inventory
{
    /** Atomic: the UPDATE only succeeds while enough stock remains, so concurrent checkouts cannot oversell. */
    public static function reserve(int $variantId, int $qty, int $orderId, ?int $actorId = null): void
    {
        $ok = Db::run('UPDATE variants SET stock = stock - ? WHERE id = ? AND stock >= ? AND active = 1', [$qty, $variantId, $qty]);
        if ($ok !== 1) {
            throw new DomainException('Sorry, one of the items just sold out or does not have enough stock.');
        }
        Db::run(
            "INSERT INTO inventory_movements (variant_id, delta, reason, order_id, actor_id) VALUES (?,?,'reserve',?,?)",
            [$variantId, -$qty, $orderId, $actorId]
        );
    }

    public static function releaseOrder(int $orderId, ?int $actorId = null): void
    {
        $already = Db::val("SELECT COUNT(*) FROM inventory_movements WHERE order_id = ? AND reason = 'release'", [$orderId]);
        if ((int)$already > 0) {
            return;
        }
        foreach (Db::all('SELECT variant_id, qty FROM order_items WHERE order_id = ?', [$orderId]) as $it) {
            Db::run('UPDATE variants SET stock = stock + ? WHERE id = ?', [$it['qty'], $it['variant_id']]);
            Db::run(
                "INSERT INTO inventory_movements (variant_id, delta, reason, order_id, actor_id) VALUES (?,?,'release',?,?)",
                [$it['variant_id'], $it['qty'], $orderId, $actorId]
            );
        }
    }

    public static function adjust(int $variantId, int $newStock, string $reason, int $actorId): void
    {
        if ($newStock < 0) {
            throw new DomainException('Stock cannot be negative.');
        }
        $current = (int)Db::val('SELECT stock FROM variants WHERE id = ?', [$variantId]);
        $delta = $newStock - $current;
        if ($delta === 0) {
            return;
        }
        Db::run('UPDATE variants SET stock = ? WHERE id = ?', [$newStock, $variantId]);
        Db::run(
            'INSERT INTO inventory_movements (variant_id, delta, reason, actor_id) VALUES (?,?,?,?)',
            [$variantId, $delta, $reason, $actorId]
        );
    }
}
