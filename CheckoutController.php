<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Services\CartService;
use App\Services\CheckoutService;
use App\Services\DomainException;
use App\Services\PaymentService;

final class CheckoutController extends Controller
{
    public function form(): void
    {
        $lines = CartService::lines();
        if (!$lines) {
            flash('info', 'Your cart is empty.');
            redirect('/cart');
        }
        $method = get('delivery', 'mthix');
        if (!isset(cfg('delivery_methods')[$method])) {
            $method = 'mthix';
        }
        $this->page('shop/checkout', [
            'title' => 'Checkout', 'quote' => CheckoutService::quote($lines, $method), 'method' => $method, 'me' => \App\Core\Auth::user(),
        ]);
    }

    public function place(): void
    {
        $lines = CartService::lines();
        foreach ($lines as $l) {
            if ($l['short']) {
                throw new DomainException($l['name'] . ' no longer has enough stock. Update your cart.');
            }
        }
        $res = CheckoutService::place(
            $this->uid(), $lines,
            ['name' => post('name'), 'phone' => post('phone'), 'address' => post('address')],
            post('delivery', 'mthix')
        );
        CartService::clear();
        redirect('/pay/' . $res['group_code']);
    }

    /** The buyer's unpaid orders in one checkout group. */
    private function groupOrders(string $group, ?string $status = null): array
    {
        $sql = 'SELECT * FROM orders WHERE group_code = ? AND buyer_id = ?' . ($status ? ' AND status = ?' : '');
        $rows = Db::all($sql, $status ? [$group, $this->uid(), $status] : [$group, $this->uid()]);
        if (!$rows) {
            abort(404);
        }
        return $rows;
    }

    public function pay(array $p): void
    {
        $orders = $this->groupOrders($p['group']);
        $unpaid = array_filter($orders, fn($o) => $o['status'] === 'pending_payment');
        if (!$unpaid) {
            redirect('/order/success/' . $p['group']);
        }
        $this->page('shop/pay', [
            'title' => 'Payment', 'orders' => $unpaid, 'group' => $p['group'],
            'total' => array_sum(array_column($unpaid, 'total')),
            'pending' => Db::all("SELECT * FROM payments WHERE status = 'initiated' AND order_id IN (" . implode(',', array_map('intval', array_column($unpaid, 'id'))) . ')'),
        ]);
    }

    public function payStart(array $p): void
    {
        $orders = $this->groupOrders($p['group'], 'pending_payment');
        PaymentService::initiate($orders, post('method'));
        flash('info', 'Payment request created. Approve it on your phone, then it will confirm here automatically.');
        redirect('/pay/' . $p['group']);
    }

    /** Development stand-in for the payment gateway: posts the same event a real webhook would. */
    public function simulate(array $p): void
    {
        if (cfg('env') !== 'dev') {
            abort(404);
        }
        $this->groupOrders($p['group']);
        $ok = post('result') === 'success';
        foreach (Db::all("SELECT pm.* FROM payments pm JOIN orders o ON o.id = pm.order_id WHERE o.group_code = ? AND o.buyer_id = ? AND pm.status = 'initiated'", [$p['group'], $this->uid()]) as $pm) {
            PaymentService::handleEvent($pm['external_ref'], $ok ? 'success' : 'failed', (int)$pm['amount'], ['simulated' => true]);
        }
        redirect($ok ? '/order/success/' . $p['group'] : '/pay/' . $p['group']);
    }

    public function success(array $p): void
    {
        $orders = $this->groupOrders($p['group']);
        $this->page('shop/success', ['title' => 'Order placed', 'orders' => $orders]);
    }

    /** Gateway callback: HMAC-signed JSON {reference, status, amount}. Idempotent (see PaymentService). */
    public function webhook(): void
    {
        $raw = (string)file_get_contents('php://input');
        if (!PaymentService::verifySignature($raw, (string)($_SERVER['HTTP_X_SIGNATURE'] ?? ''))) {
            http_response_code(401);
            echo json_encode(['error' => 'bad signature']);
            exit;
        }
        $d = json_decode($raw, true);
        if (!is_array($d) || !isset($d['reference'], $d['status'], $d['amount'])) {
            http_response_code(400);
            echo json_encode(['error' => 'bad payload']);
            exit;
        }
        try {
            $result = PaymentService::handleEvent((string)$d['reference'], (string)$d['status'], (int)$d['amount'], $d);
            echo json_encode(['result' => $result]);
        } catch (DomainException $e) {
            http_response_code(404);
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }
}
