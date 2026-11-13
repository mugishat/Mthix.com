<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/**
 * Escrow + commission + ledger glue for one order.
 *
 *   hold()           payment confirmed  -> escrow "held"; commissions + PENDING ledger entries for every party
 *   creditDelivery() delivered          -> delivery fee moves from the platform's pending to the courier's pending
 *   release()        order completed    -> every party's pending moves to AVAILABLE; escrow "released"
 *   refund()         cancelled/refunded -> all pending for the order is reversed; escrow "refunded"
 *
 * All of it runs inside the caller's transaction.
 */
final class Settlement
{
    public static function hold(array $order): void
    {
        $orderId = (int)$order['id'];
        if (Db::val('SELECT COUNT(*) FROM escrow WHERE order_id = ?', [$orderId])) {
            return; // already held: idempotent
        }
        $store = Db::one('SELECT user_id FROM stores WHERE id = ?', [$order['store_id']]);
        $items = Db::all('SELECT unit_cost, qty FROM order_items WHERE order_id = ?', [$orderId]);
        $cost = 0;
        foreach ($items as $i) {
            $cost += (int)$i['unit_cost'] * (int)$i['qty'];
        }

        $rule = CommissionEngine::activeRule();
        $split = CommissionEngine::split((int)$order['subtotal'], !empty($order['reseller_id']), !empty($order['affiliate_id']), $cost, $rule);

        $platformId = (int)cfg('platform_user_id');
        $parties = [
            'seller'    => [(int)$store['user_id'], 'sale'],
            'reseller'  => [(int)($order['reseller_id'] ?? 0), 'commission'],
            'affiliate' => [(int)($order['affiliate_id'] ?? 0), 'commission'],
            'platform'  => [$platformId, 'commission'],
        ];
        foreach ($parties as $party => [$userId, $type]) {
            if ($userId === 0 || ($party !== 'seller' && $split[$party]['amount'] === 0)) {
                continue;
            }
            Db::run(
                'INSERT INTO commissions (order_id, party, user_id, rate_bps, amount, rule_version) VALUES (?,?,?,?,?,?)',
                [$orderId, $party, $userId, $split[$party]['bps'], $split[$party]['amount'], $split['rule_version']]
            );
            Ledger::add($userId, $orderId, $type, 'pending', $split[$party]['amount'], $order['code'], ucfirst($party) . ' share');
        }
        // Delivery money is tracked separately from the product settlement; the platform holds it until a courier delivers.
        Ledger::add($platformId, $orderId, 'delivery_fee_held', 'pending', (int)$order['delivery_fee'], $order['code'], 'Delivery fee held');

        Db::run('INSERT INTO escrow (order_id, amount) VALUES (?, ?)', [$orderId, (int)$order['total']]);
    }

    public static function creditDelivery(array $order, int $partnerId): void
    {
        $fee = (int)$order['delivery_fee'];
        $key = 'delivery_payout';
        if ($fee === 0 || Db::val('SELECT COUNT(*) FROM ledger_entries WHERE order_id = ? AND type = ?', [$order['id'], $key])) {
            return;
        }
        $platformId = (int)cfg('platform_user_id');
        Ledger::add($platformId, (int)$order['id'], $key, 'pending', -$fee, $order['code'], 'Delivery fee paid to courier');
        Ledger::add($partnerId, (int)$order['id'], 'delivery_earning', 'pending', $fee, $order['code'], 'Delivery earning');
    }

    public static function release(array $order): void
    {
        $orderId = (int)$order['id'];
        foreach (Ledger::pendingForOrder($orderId) as $userId => $net) {
            if ($net > 0) {
                Ledger::add($userId, $orderId, 'release', 'pending', -$net, $order['code'], 'Escrow released');
                Ledger::add($userId, $orderId, 'release', 'available', $net, $order['code'], 'Escrow released');
            }
        }
        Db::run("UPDATE commissions SET status = 'available' WHERE order_id = ? AND status = 'pending'", [$orderId]);
        Db::run("UPDATE escrow SET status = 'released', released_at = ? WHERE order_id = ? AND status = 'held'", [now(), $orderId]);
    }

    public static function refund(array $order): void
    {
        $orderId = (int)$order['id'];
        foreach (Ledger::pendingForOrder($orderId) as $userId => $net) {
            Ledger::add($userId, $orderId, 'refund_reversal', 'pending', -$net, $order['code'], 'Order refunded');
        }
        Db::run("UPDATE commissions SET status = 'reversed' WHERE order_id = ? AND status = 'pending'", [$orderId]);
        Db::run("UPDATE escrow SET status = 'refunded', released_at = ? WHERE order_id = ? AND status = 'held'", [now(), $orderId]);
        Db::run("UPDATE payments SET status = 'refunded' WHERE order_id = ? AND status = 'success'", [$orderId]);
    }
}
