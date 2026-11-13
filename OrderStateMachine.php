<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Db;

/**
 * The order lifecycle: Paid -> Confirmed -> Packed -> Shipped -> Out for delivery -> Delivered -> Completed
 * (plus Pending payment before it, and Cancelled / Refunded as exits).
 *
 * This class is the only place an order's status may change. It enforces allowed transitions, WHO may make each
 * one (including ownership: a seller can only move their own store's orders), records an event with actor, time
 * and reason, and runs the financial side-effects (escrow, ledger, stock) in the same transaction.
 *
 * Actor = ['kind' => buyer|seller|delivery|admin|system, 'id' => ?int]
 */
final class OrderStateMachine
{
    public const FLOW = [
        'pending_payment'  => ['paid' => ['system'], 'cancelled' => ['buyer', 'system', 'admin']],
        'paid'             => ['confirmed' => ['seller'], 'cancelled' => ['seller', 'admin']],
        'confirmed'        => ['packed' => ['seller'], 'cancelled' => ['seller', 'admin']],
        'packed'           => ['shipped' => ['delivery', 'admin'], 'cancelled' => ['admin']],
        'shipped'          => ['out_for_delivery' => ['delivery', 'admin']],
        'out_for_delivery' => ['delivered' => ['delivery', 'admin']],
        'delivered'        => ['completed' => ['buyer', 'system', 'admin'], 'refunded' => ['admin']],
        'completed'        => [],
        'cancelled'        => [],
        'refunded'         => [],
    ];

    public static function allowedNext(string $from, string $actorKind): array
    {
        $out = [];
        foreach (self::FLOW[$from] ?? [] as $to => $actors) {
            if (in_array($actorKind, $actors, true)) {
                $out[] = $to;
            }
        }
        return $out;
    }

    public static function system(): array
    {
        return ['kind' => 'system', 'id' => null];
    }

    public static function transition(int $orderId, string $to, array $actor, ?string $reason = null): array
    {
        return Db::tx(function () use ($orderId, $to, $actor, $reason): array {
            $order = Db::one('SELECT * FROM orders WHERE id = ?', [$orderId]);
            if (!$order) {
                throw new DomainException('Order not found.');
            }
            $from = $order['status'];
            $allowed = self::FLOW[$from][$to] ?? null;
            if ($allowed === null) {
                throw new DomainException('An order that is ' . status_label($from) . ' cannot move to ' . status_label($to) . '.');
            }
            if (!in_array($actor['kind'], $allowed, true)) {
                throw new DomainException('You are not allowed to make this change.');
            }
            self::assertOwnership($order, $actor);

            if ($to === 'completed' && $actor['kind'] !== 'admin'
                && Db::val("SELECT COUNT(*) FROM disputes WHERE order_id = ? AND status = 'open'", [$orderId])) {
                throw new DomainException('This order has an open dispute and can only be completed by support.');
            }

            $stamp = match ($to) {
                'paid' => 'paid_at', 'delivered' => 'delivered_at', 'completed' => 'completed_at', default => null,
            };
            $changed = Db::run(
                'UPDATE orders SET status = ?' . ($stamp ? ", $stamp = ?" : '') . ' WHERE id = ? AND status = ?',
                $stamp ? [$to, now(), $orderId, $from] : [$to, $orderId, $from]
            );
            if ($changed !== 1) {
                throw new DomainException('This order was just changed by someone else. Refresh and try again.');
            }
            Db::run(
                'INSERT INTO order_events (order_id, from_status, to_status, actor_id, actor_kind, reason) VALUES (?,?,?,?,?,?)',
                [$orderId, $from, $to, $actor['id'], $actor['kind'], $reason]
            );
            if (in_array($actor['kind'], ['admin'], true)) {
                Audit::log($actor['id'], 'order.' . $to, 'order', $orderId, ['from' => $from, 'reason' => $reason]);
            }

            $order['status'] = $to;
            self::sideEffects($order, $from, $to, $actor);
            return $order;
        });
    }

    private static function assertOwnership(array $order, array $actor): void
    {
        $id = $actor['id'] ?? null;
        $ok = match ($actor['kind']) {
            'buyer'    => (int)$order['buyer_id'] === $id,
            'seller'   => (int)Db::val('SELECT user_id FROM stores WHERE id = ?', [$order['store_id']]) === $id,
            'delivery' => (int)Db::val('SELECT partner_id FROM delivery_jobs WHERE order_id = ?', [$order['id']]) === $id,
            default    => true,
        };
        if (!$ok) {
            throw new DomainException('You are not allowed to make this change.');
        }
    }

    private static function sideEffects(array $order, string $from, string $to, array $actor): void
    {
        $id = (int)$order['id'];
        $link = '/account/orders/' . $order['code'];
        $storeUser = (int)Db::val('SELECT user_id FROM stores WHERE id = ?', [$order['store_id']]);

        switch ($to) {
            case 'paid':
                Notifier::send((int)$order['buyer_id'], 'payment.success', 'Payment received', 'Order ' . $order['code'] . ' is paid.', $link);
                Notifier::send($storeUser, 'order.new', 'New order ' . $order['code'], 'Confirm it to start fulfilment.', '/seller/orders/' . $order['code']);
                break;

            case 'confirmed':
                Notifier::send((int)$order['buyer_id'], 'order.confirmed', 'Order confirmed', $order['code'] . ' was confirmed by the seller.', $link);
                break;

            case 'packed':
                Db::run('INSERT OR IGNORE INTO delivery_jobs (order_id, fee) VALUES (?, ?)', [$id, (int)$order['delivery_fee']]);
                Notifier::send((int)$order['buyer_id'], 'order.packed', 'Order packed', $order['code'] . ' is packed and waiting for a courier.', $link);
                break;

            case 'shipped':
                Db::run("UPDATE delivery_jobs SET status = 'picked_up', updated_at = ? WHERE order_id = ?", [now(), $id]);
                Notifier::send((int)$order['buyer_id'], 'order.shipped', 'Order shipped', $order['code'] . ' has been picked up.', $link);
                break;

            case 'out_for_delivery':
                Db::run("UPDATE delivery_jobs SET status = 'out_for_delivery', updated_at = ? WHERE order_id = ?", [now(), $id]);
                Notifier::send((int)$order['buyer_id'], 'order.out_for_delivery', 'Out for delivery', 'Delivery code: ' . $order['delivery_code'] . ' (give it to the courier).', $link);
                break;

            case 'delivered':
                $job = Db::one('SELECT * FROM delivery_jobs WHERE order_id = ?', [$id]);
                Db::run("UPDATE delivery_jobs SET status = 'delivered', updated_at = ? WHERE order_id = ?", [now(), $id]);
                if ($job && $job['partner_id']) {
                    Settlement::creditDelivery($order, (int)$job['partner_id']);
                }
                Notifier::send((int)$order['buyer_id'], 'order.delivered', 'Delivered', 'Please confirm receipt so the seller can be paid.', $link);
                break;

            case 'completed':
                Settlement::release($order);
                Notifier::send($storeUser, 'order.completed', 'Order completed', $order['code'] . ': your earnings are now available.', '/seller/wallet');
                foreach (Db::all("SELECT user_id FROM commissions WHERE order_id = ? AND party IN ('reseller','affiliate')", [$id]) as $c) {
                    Notifier::send((int)$c['user_id'], 'commission.available', 'Commission available', 'Order ' . $order['code'] . ' completed.', '/wallet');
                }
                $job = Db::one('SELECT partner_id FROM delivery_jobs WHERE order_id = ?', [$id]);
                if ($job && $job['partner_id']) {
                    Notifier::send((int)$job['partner_id'], 'earning.available', 'Delivery earning available', $order['code'], '/wallet');
                }
                break;

            case 'cancelled':
                if ($from !== 'pending_payment') {
                    Settlement::refund($order);
                }
                Inventory::releaseOrder($id, $actor['id']);
                Db::run("UPDATE delivery_jobs SET status = 'failed', failed_reason = 'order cancelled' WHERE order_id = ? AND status != 'delivered'", [$id]);
                Notifier::send((int)$order['buyer_id'], 'order.cancelled', 'Order cancelled', $order['code'] . ($from !== 'pending_payment' ? ' — your payment will be refunded.' : ''), $link);
                if ($storeUser && $actor['kind'] !== 'seller') {
                    Notifier::send($storeUser, 'order.cancelled', 'Order cancelled', $order['code'], '/seller/orders/' . $order['code']);
                }
                break;

            case 'refunded':
                Settlement::refund($order);
                Notifier::send((int)$order['buyer_id'], 'order.refunded', 'Order refunded', $order['code'] . ' was refunded.', $link);
                Notifier::send($storeUser, 'order.refunded', 'Order refunded', $order['code'] . ' was refunded to the buyer.', '/seller/orders/' . $order['code']);
                break;
        }
    }
}
