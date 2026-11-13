<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Db;

/**
 * Payment records and gateway events.
 *
 * handleEvent() is the single entry point for a gateway result (real webhook or the dev simulator).
 * It is idempotent: each payment has a unique external reference, and a payment that has already been
 * processed is acknowledged but never processed twice, so a duplicated webhook cannot credit anyone twice.
 */
final class PaymentService
{
    /** Creates one "initiated" payment per order. @param array[] $orders */
    public static function initiate(array $orders, string $method): array
    {
        if (!isset(cfg('payment_methods')[$method])) {
            throw new DomainException('Choose a valid payment method.');
        }
        return Db::tx(function () use ($orders, $method): array {
            $out = [];
            foreach ($orders as $o) {
                if ($o['status'] !== 'pending_payment') {
                    throw new DomainException('Order ' . $o['code'] . ' is not waiting for payment.');
                }
                // A retry replaces any previous unfinished attempt.
                Db::run("UPDATE payments SET status = 'failed' WHERE order_id = ? AND status = 'initiated'", [$o['id']]);
                $ref = strtoupper($method) . '-' . strtoupper(bin2hex(random_bytes(6)));
                $txn = 'TXN-' . strtoupper(bin2hex(random_bytes(6)));
                $id = Db::insert(
                    'INSERT INTO payments (order_id, method, amount, external_ref, internal_txn) VALUES (?,?,?,?,?)',
                    [$o['id'], $method, $o['total'], $ref, $txn]
                );
                $out[] = ['id' => $id, 'order_id' => (int)$o['id'], 'external_ref' => $ref, 'amount' => (int)$o['total']];
            }
            return $out;
        });
    }

    public static function verifySignature(string $rawBody, string $signature): bool
    {
        $expected = hash_hmac('sha256', $rawBody, (string)cfg('webhook_secret'));
        return hash_equals($expected, $signature);
    }

    /** @return string processed|duplicate|failed|mismatch|orphan */
    public static function handleEvent(string $externalRef, string $status, int $amount, array $payload = []): string
    {
        return Db::tx(function () use ($externalRef, $status, $amount, $payload): string {
            $p = Db::one('SELECT * FROM payments WHERE external_ref = ?', [$externalRef]);
            if (!$p) {
                throw new DomainException('Unknown payment reference.');
            }
            if ($p['status'] !== 'initiated') {
                return 'duplicate'; // already processed (or superseded by a retry): acknowledge, do nothing
            }
            $order = Db::one('SELECT * FROM orders WHERE id = ?', [$p['order_id']]);

            if ($status !== 'success') {
                Db::run("UPDATE payments SET status = 'failed', payload = ? WHERE id = ?", [json_encode($payload), $p['id']]);
                Notifier::send((int)$order['buyer_id'], 'payment.failed', 'Payment failed', 'Order ' . $order['code'] . ' is still unpaid. You can try again.', '/pay/' . $order['group_code']);
                return 'failed';
            }

            if ((int)$p['amount'] !== $amount) {
                // The gateway says it collected a different amount than we asked for: never settle, flag for finance.
                Db::run("UPDATE payments SET recon_status = 'mismatch', payload = ? WHERE id = ?", [json_encode($payload), $p['id']]);
                Audit::log(null, 'payment.amount_mismatch', 'payment', (int)$p['id'], ['expected' => $p['amount'], 'got' => $amount]);
                Notifier::staff('payment.mismatch', 'Payment amount mismatch', $externalRef, '/admin/payments');
                return 'mismatch';
            }

            Db::run(
                "UPDATE payments SET status = 'success', recon_status = 'reconciled', paid_at = ?, payload = ? WHERE id = ?",
                [now(), json_encode($payload), $p['id']]
            );

            if ($order['status'] !== 'pending_payment') {
                // Money arrived for an order that was already cancelled (e.g. the reservation expired).
                Db::run("UPDATE payments SET recon_status = 'mismatch' WHERE id = ?", [$p['id']]);
                Audit::log(null, 'payment.orphan', 'payment', (int)$p['id'], ['order_status' => $order['status']]);
                Notifier::staff('payment.orphan', 'Payment received for a cancelled order', $order['code'] . ' — refund needed', '/admin/payments');
                return 'orphan';
            }

            Settlement::hold($order);
            OrderStateMachine::transition((int)$order['id'], 'paid', OrderStateMachine::system(), 'Payment ' . $externalRef);
            return 'processed';
        });
    }
}
