<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Services\CommissionEngine;
use App\Services\Ledger;

final class ResellerController extends Controller
{
    public function dashboard(): void
    {
        $uid = $this->uid();
        $clicks = (int)Db::val("SELECT COUNT(*) FROM referral_clicks WHERE kind='reseller' AND user_id = ?", [$uid]);
        $orders = (int)Db::val("SELECT COUNT(*) FROM orders WHERE reseller_id = ? AND status NOT IN ('pending_payment','cancelled')", [$uid]);
        $sales = (int)Db::val("SELECT COALESCE(SUM(subtotal),0) FROM orders WHERE reseller_id = ? AND status NOT IN ('pending_payment','cancelled','refunded')", [$uid]);
        $days = [];
        for ($i = 13; $i >= 0; $i--) {
            $days[date('Y-m-d', strtotime("-$i days"))] = 0;
        }
        foreach (Db::all("SELECT date(c.created_at) d, SUM(c.amount) a FROM commissions c WHERE c.user_id = ? AND c.party='reseller' AND c.status != 'reversed' AND c.created_at >= date('now','-14 days') GROUP BY d", [$uid]) as $r) {
            if (isset($days[$r['d']])) {
                $days[$r['d']] = (int)$r['a'];
            }
        }
        $me = \App\Core\Auth::user();
        $this->dash('reseller', 'reseller/dashboard', [
            'title' => 'Reseller dashboard', 'bal' => Ledger::balance($uid), 'clicks' => $clicks, 'orders' => $orders, 'sales' => $sales,
            'conv' => $clicks ? round(100 * $orders / $clicks, 1) : 0.0, 'days' => $days, 'ref' => $me['ref_code'],
            'recent' => $this->orderRows($uid, 5),
        ]);
    }

    public function products(): void
    {
        $rule = CommissionEngine::activeRule();
        $items = Db::all(
            "SELECT p.id, p.name, p.slug, p.image, p.base_price, c.slug AS cat_slug, s.name AS store_name,
                    (SELECT COALESCE(SUM(stock),0) FROM variants v WHERE v.product_id = p.id AND v.active = 1) AS stock
             FROM products p JOIN categories c ON c.id = p.category_id JOIN stores s ON s.id = p.store_id
             WHERE p.status='active' AND s.status='active' AND p.reseller_eligible = 1 ORDER BY p.id DESC"
        );
        foreach ($items as &$i) {
            $i['commission'] = CommissionEngine::share((int)$i['base_price'], (int)$rule['reseller_bps']);
        }
        unset($i);
        $this->dash('reseller', 'reseller/products', ['title' => 'Products to promote', 'items' => $items, 'ref' => \App\Core\Auth::user()['ref_code'], 'rate' => (int)$rule['reseller_bps'] / 100]);
    }

    public function orders(): void
    {
        $this->dash('reseller', 'reseller/orders', ['title' => 'Orders & earnings', 'rows' => $this->orderRows($this->uid(), 200), 'bal' => Ledger::balance($this->uid())]);
    }

    private function orderRows(int $uid, int $limit): array
    {
        return Db::all(
            "SELECT o.code, o.status, o.subtotal, o.created_at, c.amount AS commission, c.status AS cstatus
             FROM orders o LEFT JOIN commissions c ON c.order_id = o.id AND c.party='reseller'
             WHERE o.reseller_id = ? AND o.status != 'pending_payment' ORDER BY o.id DESC LIMIT $limit",
            [$uid]
        );
    }
}
