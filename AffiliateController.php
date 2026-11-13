<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Services\Ledger;

final class AffiliateController extends Controller
{
    public function dashboard(): void
    {
        $uid = $this->uid();
        $clicks = (int)Db::val("SELECT COUNT(*) FROM referral_clicks WHERE kind='affiliate' AND user_id = ?", [$uid]);
        $orders = (int)Db::val("SELECT COUNT(*) FROM orders WHERE affiliate_id = ? AND status NOT IN ('pending_payment','cancelled')", [$uid]);
        $days = [];
        for ($i = 13; $i >= 0; $i--) {
            $days[date('Y-m-d', strtotime("-$i days"))] = 0;
        }
        foreach (Db::all("SELECT date(created_at) d, COUNT(*) n FROM referral_clicks WHERE kind='affiliate' AND user_id = ? AND created_at >= date('now','-14 days') GROUP BY d", [$uid]) as $r) {
            if (isset($days[$r['d']])) {
                $days[$r['d']] = (int)$r['n'];
            }
        }
        $this->dash('affiliate', 'affiliate/dashboard', [
            'title' => 'Affiliate dashboard', 'bal' => Ledger::balance($uid), 'clicks' => $clicks, 'orders' => $orders,
            'conv' => $clicks ? round(100 * $orders / $clicks, 1) : 0.0, 'days' => $days, 'ref' => Auth::user()['ref_code'],
            'recent' => $this->rows($uid, 5),
        ]);
    }

    public function orders(): void
    {
        $this->dash('affiliate', 'affiliate/orders', ['title' => 'Orders & earnings', 'rows' => $this->rows($this->uid(), 200), 'bal' => Ledger::balance($this->uid())]);
    }

    private function rows(int $uid, int $limit): array
    {
        return Db::all(
            "SELECT o.code, o.status, o.subtotal, o.created_at, c.amount AS commission, c.status AS cstatus
             FROM orders o LEFT JOIN commissions c ON c.order_id = o.id AND c.party='affiliate'
             WHERE o.affiliate_id = ? AND o.status != 'pending_payment' ORDER BY o.id DESC LIMIT $limit",
            [$uid]
        );
    }
}
