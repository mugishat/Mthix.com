<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

final class CheckoutService
{
    /** Groups cart lines per store (one order per store) and prices delivery. */
    public static function quote(array $lines, string $deliveryMethod): array
    {
        $methods = cfg('delivery_methods');
        if (!isset($methods[$deliveryMethod])) {
            throw new DomainException('Choose a delivery method.');
        }
        $groups = [];
        foreach ($lines as $l) {
            $groups[$l['store_id']]['store_name'] = $l['store_name'];
            $groups[$l['store_id']]['lines'][] = $l;
            $groups[$l['store_id']]['subtotal'] = ($groups[$l['store_id']]['subtotal'] ?? 0) + $l['line_total'];
        }
        $fee = (int)$methods[$deliveryMethod]['fee'];
        $subtotal = 0;
        foreach ($groups as &$g) {
            $g['delivery_fee'] = $fee;           // fee is per seller shipment
            $g['total'] = $g['subtotal'] + $fee;
            $subtotal += $g['subtotal'];
        }
        unset($g);
        $deliveryTotal = $fee * count($groups);
        $total = $subtotal + $deliveryTotal;
        $vat = (int)round($total * cfg('vat_percent') / (100 + cfg('vat_percent'))); // VAT included in prices
        return ['groups' => $groups, 'subtotal' => $subtotal, 'delivery' => $deliveryTotal, 'total' => $total, 'vat_included' => $vat];
    }

    /**
     * Creates the orders (status pending_payment) and reserves stock, atomically.
     * @return array{group_code:string,orders:array[]}
     */
    public static function place(int $buyerId, array $lines, array $ship, string $deliveryMethod): array
    {
        if (!$lines) {
            throw new DomainException('Your cart is empty.');
        }
        foreach (['name', 'phone', 'address'] as $f) {
            if (trim((string)($ship[$f] ?? '')) === '') {
                throw new DomainException('Please fill in your delivery name, phone and address.');
            }
        }
        if (!preg_match('/^\+?[0-9 ]{9,15}$/', $ship['phone'])) {
            throw new DomainException('Enter a valid phone number.');
        }

        return Db::tx(function () use ($buyerId, $lines, $ship, $deliveryMethod): array {
            $quote = self::quote($lines, $deliveryMethod);
            $ref = Referral::resolve($buyerId);
            $groupCode = 'G' . strtoupper(bin2hex(random_bytes(6)));
            $orders = [];

            foreach ($quote['groups'] as $storeId => $g) {
                $storeOwner = (int)Db::val('SELECT user_id FROM stores WHERE id = ?', [$storeId]);
                if ($storeOwner === $buyerId) {
                    throw new DomainException('You cannot buy from your own store.');
                }
                // Attribution: reseller only if the attributed product is in this order; never the store owner.
                $resellerId = null;
                if ($ref['reseller_id'] && $ref['reseller_id'] !== $storeOwner) {
                    foreach ($g['lines'] as $l) {
                        if ((int)$l['product_id'] === $ref['reseller_product'] && (int)$l['reseller_eligible'] === 1) {
                            $resellerId = $ref['reseller_id'];
                        }
                    }
                }
                $affiliateId = ($ref['affiliate_id'] && $ref['affiliate_id'] !== $storeOwner) ? $ref['affiliate_id'] : null;

                $orderId = Db::insert(
                    'INSERT INTO orders (code, group_code, buyer_id, store_id, subtotal, delivery_fee, total, delivery_method, delivery_code,
                                         ship_name, ship_phone, ship_address, reseller_id, affiliate_id)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    ['TMP' . bin2hex(random_bytes(6)), $groupCode, $buyerId, $storeId, $g['subtotal'], $g['delivery_fee'], $g['total'],
                     $deliveryMethod, (string)random_int(1000, 9999), $ship['name'], $ship['phone'], $ship['address'], $resellerId, $affiliateId]
                );
                $code = sprintf('MTHIX-%s-%06d', date('Y'), $orderId);
                Db::run('UPDATE orders SET code = ? WHERE id = ?', [$code, $orderId]);

                foreach ($g['lines'] as $l) {
                    Inventory::reserve((int)$l['variant_id'], (int)$l['qty'], $orderId, $buyerId);
                    Db::run(
                        'INSERT INTO order_items (order_id, variant_id, product_id, product_name, variant_label, unit_price, unit_cost, qty, line_total)
                         VALUES (?,?,?,?,?,?,?,?,?)',
                        [$orderId, $l['variant_id'], $l['product_id'], $l['name'], $l['label'], $l['price'], $l['cost_price'], $l['qty'], $l['line_total']]
                    );
                }
                Db::run(
                    "INSERT INTO order_events (order_id, from_status, to_status, actor_id, actor_kind, reason) VALUES (?, NULL, 'pending_payment', ?, 'buyer', 'Order placed')",
                    [$orderId, $buyerId]
                );
                $orders[] = Db::one('SELECT * FROM orders WHERE id = ?', [$orderId]);
            }
            return ['group_code' => $groupCode, 'orders' => $orders];
        });
    }

    /** Cancels unpaid orders whose stock reservation has expired. Run from cli/expire_orders.php (cron). */
    public static function expireUnpaid(): int
    {
        $minutes = (int)cfg('reservation_minutes');
        $rows = Db::all("SELECT id FROM orders WHERE status = 'pending_payment' AND created_at < datetime('now', ?)", ["-$minutes minutes"]);
        $n = 0;
        foreach ($rows as $r) {
            try {
                OrderStateMachine::transition((int)$r['id'], 'cancelled', OrderStateMachine::system(), 'Payment window expired');
                $n++;
            } catch (DomainException) {
                // moved on in the meantime (e.g. just paid): fine
            }
        }
        return $n;
    }

    /** Completes delivered orders the buyer never confirmed. Run from cli/auto_complete.php (cron). */
    public static function autoComplete(): int
    {
        $days = (int)cfg('auto_complete_days');
        $rows = Db::all("SELECT id FROM orders WHERE status = 'delivered' AND delivered_at < datetime('now', ?)", ["-$days days"]);
        $n = 0;
        foreach ($rows as $r) {
            try {
                OrderStateMachine::transition((int)$r['id'], 'completed', OrderStateMachine::system(), 'Auto-completed after ' . $days . ' days');
                $n++;
            } catch (DomainException) {
                // open dispute: leave for support
            }
        }
        return $n;
    }
}
