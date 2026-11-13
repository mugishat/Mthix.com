<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Db;
use App\Services\CommissionEngine;
use App\Services\DomainException;
use App\Services\Notifier;
use App\Services\OrderStateMachine;
use App\Services\WithdrawalService;

/**
 * Admin centre. Sub-roles: admin = everything; support_admin = users, KYC, products, orders, disputes;
 * finance_admin = payments, withdrawals, commissions. The Router already filters by role; every method
 * re-checks (defence in depth) and every sensitive action writes to the audit log.
 */
final class AdminController extends Controller
{
    private const SUPPORT = ['admin', 'support_admin'];
    private const FINANCE = ['admin', 'finance_admin'];

    private function need(array $roles): void
    {
        if (!Auth::hasAny($roles)) {
            abort(403, 'Your account does not have permission to open this page.');
        }
    }

    private function admin(string $view, array $data = []): void
    {
        $this->dash('admin', 'admin/' . $view, $data);
    }

    private function actor(): array
    {
        return ['kind' => 'admin', 'id' => $this->uid()];
    }

    // ------------------------------------------------------------------ dashboard
    public function dashboard(): void
    {
        $this->need(['admin', 'support_admin', 'finance_admin']);
        $paid = "status NOT IN ('pending_payment','cancelled')";
        $kpi = [
            'users'    => (int)Db::val('SELECT COUNT(*) FROM users'),
            'orders'   => (int)Db::val("SELECT COUNT(*) FROM orders WHERE $paid"),
            'revenue'  => (int)Db::val("SELECT COALESCE(SUM(amount),0) FROM commissions WHERE party = 'platform' AND status != 'reversed'"),
            'gmv'      => (int)Db::val("SELECT COALESCE(SUM(subtotal),0) FROM orders WHERE $paid AND status != 'refunded'"),
            'sellers'  => (int)Db::val("SELECT COUNT(*) FROM stores WHERE status = 'active'"),
            'products' => (int)Db::val("SELECT COUNT(*) FROM products WHERE status = 'active'"),
            'payouts'  => (int)Db::val("SELECT COALESCE(SUM(amount),0) FROM withdrawals WHERE status IN ('review','approved')"),
        ];

        // Sales per day, last 14 days (all days present, zero-filled).
        $byDay = array_column(Db::all(
            "SELECT date(paid_at) d, SUM(total) t FROM orders WHERE paid_at >= date('now','-13 days') AND $paid GROUP BY d"
        ), 't', 'd');
        $labels = $vals = [];
        for ($i = 13; $i >= 0; $i--) {
            $d = gmdate('Y-m-d', time() - $i * 86400);
            $labels[] = gmdate('M j', time() - $i * 86400);
            $vals[] = (int)($byDay[$d] ?? 0);
        }

        $status = [];
        foreach (Db::all('SELECT status, COUNT(*) n FROM orders GROUP BY status ORDER BY n DESC') as $r) {
            $status[status_label($r['status'])] = (int)$r['n'];
        }

        $health = [
            ['Database', true, 'Reachable'],
            ['Ledger vs escrow', $this->ledgerBalanced(), $this->ledgerBalanced() ? 'Balanced' : 'Check payments page'],
            ['Payment reconciliation', ($m = (int)Db::val("SELECT COUNT(*) FROM payments WHERE recon_status = 'mismatch'")) === 0, $m ? "$m mismatched" : 'All reconciled'],
            ['Stale unpaid orders', ($s = (int)Db::val("SELECT COUNT(*) FROM orders WHERE status = 'pending_payment' AND created_at < datetime('now','-1 day')")) === 0, $s ? "$s older than a day" : 'None'],
        ];

        $this->admin('dashboard', [
            'title' => 'Admin dashboard', 'kpi' => $kpi, 'labels' => $labels, 'vals' => $vals, 'status' => $status, 'health' => $health,
            'topSellers' => Db::all("SELECT s.name, COUNT(*) orders, SUM(o.subtotal) revenue FROM orders o JOIN stores s ON s.id = o.store_id
                                     WHERE o.$paid AND o.status != 'refunded' GROUP BY s.id ORDER BY revenue DESC LIMIT 5"),
            'recent' => Db::all('SELECT o.*, u.name AS buyer FROM orders o JOIN users u ON u.id = o.buyer_id ORDER BY o.id DESC LIMIT 6'),
            'queues' => [
                'kyc' => (int)Db::val("SELECT COUNT(*) FROM users WHERE kyc_status = 'pending'"),
                'withdrawals' => (int)Db::val("SELECT COUNT(*) FROM withdrawals WHERE status IN ('review','approved')"),
                'disputes' => (int)Db::val("SELECT COUNT(*) FROM disputes WHERE status = 'open'"),
            ],
        ]);
    }

    /** Every escrow row that is held must have its pending ledger entries; here: total pending ledger >= held escrow of live orders. */
    private function ledgerBalanced(): bool
    {
        $held = (int)Db::val("SELECT COALESCE(SUM(amount),0) FROM escrow WHERE status = 'held'");
        $pending = (int)Db::val("SELECT COALESCE(SUM(amount),0) FROM ledger_entries WHERE bucket = 'pending'");
        return $pending === $held;
    }

    // ------------------------------------------------------------------ users
    public function users(): void
    {
        $this->need(self::SUPPORT);
        $q = get('q');
        $role = get('role');
        $where = '1=1';
        $params = [];
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where .= " AND (u.name LIKE ? ESCAPE '\\' OR u.email LIKE ? ESCAPE '\\' OR u.phone LIKE ? ESCAPE '\\')";
            array_push($params, $like, $like, $like);
        }
        if ($role !== '') {
            $where .= ' AND EXISTS (SELECT 1 FROM user_roles r WHERE r.user_id = u.id AND r.role = ?)';
            $params[] = $role;
        }
        $pg = paginate((int)Db::val("SELECT COUNT(*) FROM users u WHERE $where", $params), (int)get('page', '1'), 20);
        $rows = Db::all(
            "SELECT u.*, (SELECT GROUP_CONCAT(role) FROM user_roles WHERE user_id = u.id) roles
             FROM users u WHERE $where ORDER BY u.id DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}",
            $params
        );
        $this->admin('users', ['title' => 'Users', 'rows' => $rows, 'pg' => $pg, 'q' => $q, 'role' => $role]);
    }

    public function userStatus(array $p): void
    {
        $this->need(['admin']);
        $id = (int)$p['id'];
        $status = post('status');
        if (!in_array($status, ['active', 'suspended', 'banned'], true)) {
            throw new DomainException('Choose a valid status.');
        }
        $u = Db::one('SELECT * FROM users WHERE id = ?', [$id]);
        if (!$u) {
            abort(404);
        }
        if ($id === $this->uid()) {
            throw new DomainException('You cannot change your own account status.');
        }
        $isStaff = Db::val("SELECT COUNT(*) FROM user_roles WHERE user_id = ? AND role IN ('admin','finance_admin','support_admin')", [$id]);
        if ($isStaff) {
            throw new DomainException('Staff accounts cannot be suspended here.');
        }
        Db::tx(function () use ($id, $status, $u): void {
            Db::run('UPDATE users SET status = ? WHERE id = ?', [$status, $id]);
            Audit::log($this->uid(), 'user.status', 'user', $id, ['from' => $u['status'], 'to' => $status, 'reason' => post('reason')]);
        });
        flash('success', $u['name'] . ' is now ' . $status . '.');
        redirect($_SERVER['HTTP_REFERER'] ?? '/admin/users');
    }

    // ------------------------------------------------------------------ KYC
    public function kyc(): void
    {
        $this->need(self::SUPPORT);
        $tab = get('tab', 'pending') === 'done' ? 'done' : 'pending';
        $rows = Db::all(
            "SELECT k.*, u.email, u.phone, u.created_at AS joined,
                    (SELECT GROUP_CONCAT(role) FROM user_roles WHERE user_id = u.id) roles
             FROM kyc_records k JOIN users u ON u.id = k.user_id
             WHERE k.status " . ($tab === 'pending' ? "= 'pending'" : "!= 'pending'") . ' ORDER BY k.id DESC LIMIT 100'
        );
        $this->admin('kyc', ['title' => 'KYC queue', 'rows' => $rows, 'tab' => $tab]);
    }

    public function kycDecide(array $p): void
    {
        $this->need(self::SUPPORT);
        $id = (int)$p['id'];
        $decision = post('decision');
        $note = trim(post('note'));
        if (!in_array($decision, ['verified', 'rejected'], true)) {
            throw new DomainException('Choose approve or reject.');
        }
        if ($decision === 'rejected' && $note === '') {
            throw new DomainException('Tell the user why the verification was rejected.');
        }
        Db::tx(function () use ($id, $decision, $note): void {
            $k = Db::one('SELECT * FROM kyc_records WHERE id = ?', [$id]);
            if (!$k || $k['status'] !== 'pending') {
                throw new DomainException('This verification was already reviewed.');
            }
            Db::run('UPDATE kyc_records SET status = ?, note = ?, reviewed_by = ?, reviewed_at = ? WHERE id = ?', [$decision, $note, $this->uid(), now(), $id]);
            Db::run('UPDATE users SET kyc_status = ? WHERE id = ?', [$decision, $k['user_id']]);
            Audit::log($this->uid(), 'kyc.' . $decision, 'kyc', $id, ['user_id' => $k['user_id'], 'note' => $note]);
            Notifier::send(
                (int)$k['user_id'], 'kyc.' . $decision,
                $decision === 'verified' ? 'Identity verified' : 'Verification needs attention',
                $decision === 'verified' ? 'You can now withdraw your earnings.' : $note,
                '/kyc'
            );
        });
        flash('success', 'Verification ' . ($decision === 'verified' ? 'approved' : 'rejected') . '.');
        redirect('/admin/kyc');
    }

    // ------------------------------------------------------------------ products
    public function products(): void
    {
        $this->need(self::SUPPORT);
        $status = get('status');
        $q = get('q');
        $where = '1=1';
        $params = [];
        if (in_array($status, ['draft', 'active', 'suspended', 'archived'], true)) {
            $where .= ' AND p.status = ?';
            $params[] = $status;
        }
        if ($q !== '') {
            $where .= " AND (p.name LIKE ? ESCAPE '\\' OR s.name LIKE ? ESCAPE '\\')";
            $like = '%' . addcslashes($q, '%_\\') . '%';
            array_push($params, $like, $like);
        }
        $base = 'FROM products p JOIN stores s ON s.id = p.store_id JOIN categories c ON c.id = p.category_id WHERE ' . $where;
        $pg = paginate((int)Db::val("SELECT COUNT(*) $base", $params), (int)get('page', '1'), 20);
        $rows = Db::all(
            "SELECT p.*, s.name AS store_name, c.name AS cat_name, c.slug AS cat_slug,
                    (SELECT COALESCE(SUM(stock),0) FROM variants WHERE product_id = p.id) stock $base ORDER BY p.id DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}",
            $params
        );
        $this->admin('products', ['title' => 'Products', 'rows' => $rows, 'pg' => $pg, 'status' => $status, 'q' => $q]);
    }

    public function productStatus(array $p): void
    {
        $this->need(self::SUPPORT);
        $id = (int)$p['id'];
        $status = post('status');
        if (!in_array($status, ['active', 'suspended', 'archived'], true)) {
            throw new DomainException('Choose a valid status.');
        }
        $prod = Db::one('SELECT p.*, s.user_id AS owner FROM products p JOIN stores s ON s.id = p.store_id WHERE p.id = ?', [$id]);
        if (!$prod) {
            abort(404);
        }
        Db::tx(function () use ($id, $status, $prod): void {
            Db::run('UPDATE products SET status = ? WHERE id = ?', [$status, $id]);
            Audit::log($this->uid(), 'product.' . $status, 'product', $id, ['from' => $prod['status'], 'reason' => post('reason')]);
            if ($status === 'suspended') {
                Notifier::send((int)$prod['owner'], 'product.suspended', 'Product suspended', $prod['name'] . ' was suspended by MTHIX.' . (post('reason') ? ' Reason: ' . post('reason') : ''), '/seller/products');
            }
        });
        flash('success', $prod['name'] . ' is now ' . $status . '.');
        redirect($_SERVER['HTTP_REFERER'] ?? '/admin/products');
    }

    // ------------------------------------------------------------------ orders
    public function orders(): void
    {
        $this->need(self::SUPPORT);
        $status = get('status');
        $q = get('q');
        $where = '1=1';
        $params = [];
        if ($status !== '' && isset(OrderStateMachine::FLOW[$status])) {
            $where .= ' AND o.status = ?';
            $params[] = $status;
        }
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where .= " AND (o.code LIKE ? ESCAPE '\\' OR u.name LIKE ? ESCAPE '\\' OR u.email LIKE ? ESCAPE '\\' OR s.name LIKE ? ESCAPE '\\')";
            array_push($params, $like, $like, $like, $like);
        }
        $base = "FROM orders o JOIN users u ON u.id = o.buyer_id JOIN stores s ON s.id = o.store_id WHERE $where";
        $pg = paginate((int)Db::val("SELECT COUNT(*) $base", $params), (int)get('page', '1'), 20);
        $rows = Db::all("SELECT o.*, u.name AS buyer, s.name AS store_name $base ORDER BY o.id DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}", $params);
        $this->admin('orders', ['title' => 'Orders', 'rows' => $rows, 'pg' => $pg, 'status' => $status, 'q' => $q]);
    }

    public function order(array $p): void
    {
        $this->need(self::SUPPORT);
        $o = Db::one(
            'SELECT o.*, u.name AS buyer, u.email AS buyer_email, s.name AS store_name FROM orders o
             JOIN users u ON u.id = o.buyer_id JOIN stores s ON s.id = o.store_id WHERE o.code = ?',
            [$p['code']]
        );
        if (!$o) {
            abort(404);
        }
        $id = (int)$o['id'];
        $this->admin('order', [
            'title' => 'Order ' . $o['code'], 'o' => $o,
            'items' => Db::all('SELECT * FROM order_items WHERE order_id = ?', [$id]),
            'events' => Db::all('SELECT * FROM order_events WHERE order_id = ? ORDER BY id', [$id]),
            'payments' => Db::all('SELECT * FROM payments WHERE order_id = ? ORDER BY id', [$id]),
            'escrow' => Db::one('SELECT * FROM escrow WHERE order_id = ?', [$id]),
            'commissions' => Db::all('SELECT c.*, u.name FROM commissions c JOIN users u ON u.id = c.user_id WHERE c.order_id = ? ORDER BY c.id', [$id]),
            'disputes' => Db::all('SELECT d.*, u.name FROM disputes d JOIN users u ON u.id = d.opened_by WHERE d.order_id = ? ORDER BY d.id', [$id]),
            'job' => Db::one('SELECT j.*, u.name AS partner FROM delivery_jobs j LEFT JOIN users u ON u.id = j.partner_id WHERE j.order_id = ?', [$id]),
            'next' => OrderStateMachine::allowedNext($o['status'], 'admin'),
        ]);
    }

    public function orderTransition(array $p): void
    {
        $this->need(self::SUPPORT);
        $o = Db::one('SELECT id FROM orders WHERE code = ?', [$p['code']]);
        if (!$o) {
            abort(404);
        }
        $to = post('to');
        $reason = trim(post('reason'));
        if ($reason === '') {
            throw new DomainException('Give a reason; it is recorded in the order history and the audit log.');
        }
        OrderStateMachine::transition((int)$o['id'], $to, $this->actor(), $reason);
        flash('success', 'Order moved to ' . status_label($to) . '.');
        redirect('/admin/orders/' . $p['code']);
    }

    // ------------------------------------------------------------------ disputes
    public function disputes(): void
    {
        $this->need(self::SUPPORT);
        $tab = get('tab', 'open') === 'resolved' ? 'resolved' : 'open';
        $rows = Db::all(
            'SELECT d.*, o.code, o.status AS order_status, o.total, u.name AS opener, s.name AS store_name
             FROM disputes d JOIN orders o ON o.id = d.order_id JOIN users u ON u.id = d.opened_by JOIN stores s ON s.id = o.store_id
             WHERE d.status = ? ORDER BY d.id DESC LIMIT 100',
            [$tab]
        );
        foreach ($rows as &$r) {
            $r['next'] = OrderStateMachine::allowedNext($r['order_status'], 'admin');
        }
        unset($r);
        $this->admin('disputes', ['title' => 'Disputes', 'rows' => $rows, 'tab' => $tab]);
    }

    public function disputeResolve(array $p): void
    {
        $this->need(self::SUPPORT);
        $id = (int)$p['id'];
        $outcome = post('outcome');   // none | refund | release
        $text = trim(post('resolution'));
        if ($text === '') {
            throw new DomainException('Write the resolution; both sides will see it.');
        }
        Db::tx(function () use ($id, $outcome, $text): void {
            $d = Db::one('SELECT * FROM disputes WHERE id = ?', [$id]);
            if (!$d || $d['status'] !== 'open') {
                throw new DomainException('This dispute is already resolved.');
            }
            $o = Db::one('SELECT * FROM orders WHERE id = ?', [$d['order_id']]);
            // Close the dispute first so the order can move again.
            Db::run("UPDATE disputes SET status = 'resolved', resolution = ?, resolved_by = ?, resolved_at = ? WHERE id = ?", [$text, $this->uid(), now(), $id]);
            Audit::log($this->uid(), 'dispute.resolve', 'dispute', $id, ['outcome' => $outcome, 'order' => $o['code']]);
            if ($outcome === 'refund') {
                $to = $o['status'] === 'delivered' ? 'refunded' : 'cancelled';
                OrderStateMachine::transition((int)$o['id'], $to, $this->actor(), 'Dispute #' . $id . ': ' . $text);
            } elseif ($outcome === 'release') {
                OrderStateMachine::transition((int)$o['id'], 'completed', $this->actor(), 'Dispute #' . $id . ': ' . $text);
            } elseif ($outcome !== 'none') {
                throw new DomainException('Choose an outcome.');
            }
            foreach ([(int)$o['buyer_id'], (int)Db::val('SELECT user_id FROM stores WHERE id = ?', [$o['store_id']])] as $uid) {
                Notifier::send($uid, 'dispute.resolved', 'Dispute resolved', $o['code'] . ': ' . $text, $uid === (int)$o['buyer_id'] ? '/account/orders/' . $o['code'] : '/seller/orders/' . $o['code']);
            }
        });
        flash('success', 'Dispute resolved.');
        redirect('/admin/disputes');
    }

    // ------------------------------------------------------------------ payments & escrow
    public function payments(): void
    {
        $this->need(self::FINANCE);
        $recon = get('recon');
        $where = in_array($recon, ['unreconciled', 'reconciled', 'mismatch'], true) ? 'WHERE p.recon_status = ' . Db::pdo()->quote($recon) : '';
        $pg = paginate((int)Db::val("SELECT COUNT(*) FROM payments p $where"), (int)get('page', '1'), 20);
        $rows = Db::all(
            "SELECT p.*, o.code, o.status AS order_status FROM payments p JOIN orders o ON o.id = p.order_id $where
             ORDER BY p.id DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}"
        );
        $escrow = Db::all("SELECT status, COUNT(*) n, COALESCE(SUM(amount),0) amount FROM escrow GROUP BY status");
        $held = Db::all("SELECT e.*, o.code, o.status AS order_status FROM escrow e JOIN orders o ON o.id = e.order_id WHERE e.status = 'held' ORDER BY e.id DESC LIMIT 50");
        $this->admin('payments', [
            'title' => 'Payments & escrow', 'rows' => $rows, 'pg' => $pg, 'recon' => $recon,
            'escrow' => array_column($escrow, null, 'status'), 'held' => $held,
            'mismatches' => (int)Db::val("SELECT COUNT(*) FROM payments WHERE recon_status = 'mismatch'"),
        ]);
    }

    // ------------------------------------------------------------------ withdrawals
    public function withdrawals(): void
    {
        $this->need(self::FINANCE);
        $tab = get('tab', 'queue');
        $where = match ($tab) {
            'paid' => "w.status IN ('paid','rejected')",
            'all' => '1=1',
            default => "w.status IN ('requested','review','approved','processing')",
        };
        $rows = Db::all(
            "SELECT w.*, u.name, u.email, u.kyc_status, u.created_at AS joined FROM withdrawals w JOIN users u ON u.id = w.user_id
             WHERE $where ORDER BY (w.status = 'review') DESC, w.id DESC LIMIT 100"
        );
        $this->admin('withdrawals', ['title' => 'Withdrawals', 'rows' => $rows, 'tab' => in_array($tab, ['paid', 'all'], true) ? $tab : 'queue']);
    }

    public function withdrawalDecide(array $p): void
    {
        $this->need(self::FINANCE);
        $id = (int)$p['id'];
        match ($p['decision']) {
            'approve' => WithdrawalService::approve($id, $this->uid()),
            'reject'  => WithdrawalService::reject($id, $this->uid(), trim(post('note'))),
            'paid'    => WithdrawalService::markPaid($id, $this->uid()),
            default   => abort(404),
        };
        flash('success', 'Withdrawal #' . $id . ' updated.');
        redirect('/admin/withdrawals');
    }

    // ------------------------------------------------------------------ commission rules
    public function commissions(): void
    {
        $this->need(self::FINANCE);
        $this->admin('commissions', [
            'title' => 'Commission rules',
            'rules' => Db::all('SELECT r.*, u.name AS author FROM commission_rules r LEFT JOIN users u ON u.id = r.created_by ORDER BY version DESC'),
            'active' => CommissionEngine::activeRule(),
            'earned' => Db::all("SELECT party, COUNT(*) n, COALESCE(SUM(amount),0) amount FROM commissions WHERE status != 'reversed' GROUP BY party"),
        ]);
    }

    /** Rules are never edited: a new version is inserted and applies to orders paid from its effective date. */
    public function commissionCreate(): void
    {
        $this->need(['admin']);
        $pct = fn(string $k): int => (int)round(((float)str_replace(',', '.', post($k, '0'))) * 100);
        $platform = $pct('platform'); $reseller = $pct('reseller'); $affiliate = $pct('affiliate'); $low = $pct('low_platform');
        $threshold = (int)post('threshold', '0');
        foreach ([$platform, $reseller, $affiliate, $low] as $bps) {
            if ($bps < 0 || $bps > 2500) {
                throw new DomainException('Each rate must be between 0% and 25%.');
            }
        }
        if ($platform + $reseller + $affiliate > 5000) {
            throw new DomainException('Combined rates above 50% would leave the seller with too little.');
        }
        if ($threshold < 0) {
            throw new DomainException('The low-profit threshold cannot be negative.');
        }
        $from = trim(post('effective_from'));
        $ts = $from === '' ? time() : strtotime($from . ' Africa/Kigali');
        if ($ts === false) {
            throw new DomainException('Enter a valid effective date.');
        }
        $effective = gmdate('Y-m-d H:i:s', $ts);
        $v = Db::tx(function () use ($platform, $reseller, $affiliate, $threshold, $low, $effective): int {
            $v = (int)Db::val('SELECT COALESCE(MAX(version),0) + 1 FROM commission_rules');
            Db::run(
                'INSERT INTO commission_rules (version, platform_bps, reseller_bps, affiliate_bps, low_profit_threshold, low_profit_platform_bps, effective_from, created_by)
                 VALUES (?,?,?,?,?,?,?,?)',
                [$v, $platform, $reseller, $affiliate, $threshold, $low, $effective, $this->uid()]
            );
            Audit::log($this->uid(), 'commission.rule_created', 'commission_rule', $v, compact('platform', 'reseller', 'affiliate', 'threshold', 'low', 'effective'));
            return $v;
        });
        flash('success', "Commission rule v$v saved. It applies to orders paid from the effective date; existing orders keep their rule.");
        redirect('/admin/commissions');
    }

    // ------------------------------------------------------------------ audit log
    public function audit(): void
    {
        $this->need(['admin']);
        $action = get('action');
        $actor = get('actor');
        $where = '1=1';
        $params = [];
        if ($action !== '') {
            $where .= " AND a.action LIKE ? ESCAPE '\\'";
            $params[] = addcslashes($action, '%_\\') . '%';
        }
        if ($actor !== '' && ctype_digit($actor)) {
            $where .= ' AND a.actor_id = ?';
            $params[] = (int)$actor;
        }
        $pg = paginate((int)Db::val("SELECT COUNT(*) FROM audit_log a WHERE $where", $params), (int)get('page', '1'), 30);
        $rows = Db::all(
            "SELECT a.*, u.name FROM audit_log a LEFT JOIN users u ON u.id = a.actor_id WHERE $where ORDER BY a.id DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}",
            $params
        );
        $this->admin('audit', ['title' => 'Audit log', 'rows' => $rows, 'pg' => $pg, 'action' => $action, 'actor' => $actor]);
    }
}
