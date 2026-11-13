<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Db;
use App\Services\DomainException;
use App\Services\Ledger;
use App\Services\Notifier;
use App\Services\OrderStateMachine as OSM;
use App\Services\WithdrawalService;

/** Buyer account, plus the pages every role shares: wallet, KYC, notifications, messages. */
final class AccountController extends Controller
{
    /** Which sidebar to show: the area the visitor came from, else their main role. */
    private function area(): string
    {
        $path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        foreach (['seller', 'reseller', 'affiliate', 'delivery', 'admin'] as $a) {
            if (str_starts_with($path, '/' . $a)) {
                return $a;
            }
        }
        $ref = (string)parse_url($_SERVER['HTTP_REFERER'] ?? '', PHP_URL_PATH);
        foreach (['seller', 'reseller', 'affiliate', 'delivery', 'admin'] as $a) {
            if (str_starts_with($ref, '/' . $a) && ($a === 'admin' ? Auth::isStaff() : Auth::hasRole($a))) {
                return $a;
            }
        }
        foreach (['seller', 'reseller', 'affiliate', 'delivery'] as $a) {
            if (Auth::hasRole($a) && str_starts_with($path, '/wallet')) {
                return $a;
            }
        }
        return 'buyer';
    }

    private function show(string $view, array $data): void
    {
        $this->dash($this->area(), $view, $data);
    }

    private function myOrder(string $code): array
    {
        $o = Db::one('SELECT o.*, s.name AS store_name FROM orders o JOIN stores s ON s.id = o.store_id WHERE o.code = ? AND o.buyer_id = ?', [$code, $this->uid()]);
        if (!$o) {
            abort(404);
        }
        return $o;
    }

    // ---------- buyer ----------
    public function index(): void
    {
        $id = $this->uid();
        $this->dash('buyer', 'account/index', [
            'title' => 'My account',
            'recent' => Db::all('SELECT o.*, s.name AS store_name FROM orders o JOIN stores s ON s.id = o.store_id WHERE o.buyer_id = ? ORDER BY o.id DESC LIMIT 5', [$id]),
            'counts' => [
                'Orders' => (int)Db::val('SELECT COUNT(*) FROM orders WHERE buyer_id = ?', [$id]),
                'Saved items' => (int)Db::val('SELECT COUNT(*) FROM wishlist WHERE user_id = ?', [$id]),
                'Unread messages' => (int)Db::val('SELECT COUNT(*) FROM messages WHERE to_id = ? AND read_at IS NULL', [$id]),
            ],
            'bal' => Ledger::balance($id),
        ]);
    }

    public function orders(): void
    {
        $status = get('status');
        $sql = 'SELECT o.*, s.name AS store_name FROM orders o JOIN stores s ON s.id = o.store_id WHERE o.buyer_id = ?';
        $args = [$this->uid()];
        if ($status !== '') {
            $sql .= ' AND o.status = ?';
            $args[] = $status;
        }
        $this->dash('buyer', 'account/orders', ['title' => 'My orders', 'orders' => Db::all($sql . ' ORDER BY o.id DESC LIMIT 100', $args), 'status' => $status]);
    }

    public function order(array $p): void
    {
        $o = $this->myOrder($p['code']);
        $this->dash('buyer', 'account/order', [
            'title' => 'Order ' . $o['code'], 'o' => $o,
            'items' => Db::all('SELECT i.*, p.slug FROM order_items i JOIN products p ON p.id = i.product_id WHERE i.order_id = ?', [$o['id']]),
            'events' => Db::all('SELECT * FROM order_events WHERE order_id = ? ORDER BY id', [$o['id']]),
            'dispute' => Db::one('SELECT * FROM disputes WHERE order_id = ? ORDER BY id DESC', [$o['id']]),
            'reviewed' => array_column(Db::all('SELECT product_id FROM reviews WHERE order_id = ? AND user_id = ?', [$o['id'], $this->uid()]), 'product_id'),
            'courier' => Db::one('SELECT u.name, u.phone FROM delivery_jobs j JOIN users u ON u.id = j.partner_id WHERE j.order_id = ?', [$o['id']]),
            'canCancel' => in_array('cancelled', OSM::allowedNext($o['status'], 'buyer'), true),
            'canConfirm' => in_array('completed', OSM::allowedNext($o['status'], 'buyer'), true),
        ]);
    }

    public function confirmReceipt(array $p): void
    {
        $o = $this->myOrder($p['code']);
        OSM::transition((int)$o['id'], 'completed', ['kind' => 'buyer', 'id' => $this->uid()], 'Buyer confirmed receipt');
        flash('success', 'Thanks! The seller has been paid.');
        redirect('/account/orders/' . $o['code']);
    }

    public function cancel(array $p): void
    {
        $o = $this->myOrder($p['code']);
        OSM::transition((int)$o['id'], 'cancelled', ['kind' => 'buyer', 'id' => $this->uid()], 'Cancelled by buyer');
        flash('success', 'Order cancelled.');
        redirect('/account/orders/' . $o['code']);
    }

    public function dispute(array $p): void
    {
        $o = $this->myOrder($p['code']);
        $reason = trim(post('reason'));
        if (!in_array($o['status'], ['shipped', 'out_for_delivery', 'delivered'], true)) {
            throw new DomainException('A dispute can be opened once the order has shipped.');
        }
        if (mb_strlen($reason) < 10) {
            throw new DomainException('Please describe the problem (at least 10 characters).');
        }
        if (Db::val("SELECT COUNT(*) FROM disputes WHERE order_id = ? AND status = 'open'", [$o['id']])) {
            throw new DomainException('A dispute is already open for this order.');
        }
        $id = Db::insert('INSERT INTO disputes (order_id, opened_by, reason) VALUES (?,?,?)', [$o['id'], $this->uid(), $reason]);
        Audit::log($this->uid(), 'dispute.open', 'dispute', $id);
        Notifier::staff('dispute.open', 'New dispute on ' . $o['code'], $reason, '/admin/disputes');
        flash('success', 'Dispute opened. Our support team will review it; funds stay in escrow meanwhile.');
        redirect('/account/orders/' . $o['code']);
    }

    public function review(array $p): void
    {
        $o = $this->myOrder($p['code']);
        if ($o['status'] !== 'completed') {
            throw new DomainException('You can review items once the order is completed.');
        }
        $rating = (int)post('rating');
        $productId = (int)post('product_id');
        if ($rating < 1 || $rating > 5 || !Db::val('SELECT COUNT(*) FROM order_items WHERE order_id = ? AND product_id = ?', [$o['id'], $productId])) {
            throw new DomainException('Choose a rating from 1 to 5.');
        }
        Db::run('INSERT OR IGNORE INTO reviews (product_id, user_id, order_id, rating, comment) VALUES (?,?,?,?,?)', [$productId, $this->uid(), $o['id'], $rating, mb_substr(trim(post('comment')), 0, 600)]);
        flash('success', 'Thanks for your review.');
        redirect('/account/orders/' . $o['code']);
    }

    public function wishlist(): void
    {
        $items = Db::all(
            "SELECT p.id, p.name, p.slug, p.image, p.base_price, c.slug AS cat_slug, (SELECT MIN(v.price) FROM variants v WHERE v.product_id = p.id AND v.active = 1) AS min_price
             FROM wishlist w JOIN products p ON p.id = w.product_id JOIN categories c ON c.id = p.category_id WHERE w.user_id = ? AND p.status = 'active'",
            [$this->uid()]
        );
        $this->dash('buyer', 'account/wishlist', ['title' => 'Wishlist', 'items' => $items]);
    }

    public function settings(): void
    {
        $this->dash('buyer', 'account/settings', ['title' => 'Profile & security', 'me' => Auth::user()]);
    }

    public function saveSettings(): void
    {
        $me = Auth::user();
        if (post('form') === 'password') {
            if (!password_verify((string)($_POST['current'] ?? ''), $me['password_hash'])) {
                throw new DomainException('Your current password is not correct.');
            }
            $new = (string)($_POST['password'] ?? '');
            if (strlen($new) < 8 || !preg_match('/[A-Za-z]/', $new) || !preg_match('/[0-9]/', $new)) {
                throw new DomainException('New password must be at least 8 characters with letters and numbers.');
            }
            if ($new !== (string)($_POST['password2'] ?? '')) {
                throw new DomainException('The two passwords do not match.');
            }
            Db::run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $me['id']]);
            Audit::log((int)$me['id'], 'user.password_change', 'user', (int)$me['id']);
            flash('success', 'Password changed.');
        } else {
            $name = trim(post('name'));
            $phone = preg_replace('/\s+/', '', post('phone')) ?? '';
            if (mb_strlen($name) < 2 || !preg_match('/^\+?[0-9]{9,15}$/', $phone)) {
                throw new DomainException('Enter your name and a valid phone number.');
            }
            Db::run('UPDATE users SET name = ?, phone = ? WHERE id = ?', [$name, $phone, $me['id']]);
            flash('success', 'Profile saved.');
        }
        redirect('/account/settings');
    }

    // ---------- wallet ----------
    public function wallet(): void
    {
        $id = $this->uid();
        $page = max(1, (int)get('page', '1'));
        $this->show('account/wallet', [
            'title' => 'Wallet', 'bal' => Ledger::balance($id), 'entries' => Ledger::entries($id, 25, ($page - 1) * 25), 'page' => $page,
            'withdrawals' => Db::all('SELECT * FROM withdrawals WHERE user_id = ? ORDER BY id DESC LIMIT 10', [$id]),
            'me' => Auth::user(), 'idem' => bin2hex(random_bytes(12)),
        ]);
    }

    public function withdraw(): void
    {
        $w = WithdrawalService::request($this->uid(), (int)post('amount'), post('method'), post('account'), post('idem'));
        flash('success', $w['status'] === 'review' ? 'Withdrawal requested. It needs a quick manual review.' : 'Withdrawal approved and queued for payout.');
        redirect('/wallet');
    }

    // ---------- KYC ----------
    public function kyc(): void
    {
        $this->show('account/kyc', ['title' => 'Verification (KYC)', 'me' => Auth::user(), 'rec' => Db::one('SELECT * FROM kyc_records WHERE user_id = ? ORDER BY id DESC', [$this->uid()])]);
    }

    public function kycSubmit(): void
    {
        $me = Auth::user();
        if (in_array($me['kyc_status'], ['pending', 'verified'], true)) {
            throw new DomainException('Your verification is already ' . $me['kyc_status'] . '.');
        }
        $name = trim(post('full_name'));
        $type = post('doc_type');
        $num = trim(post('doc_number'));
        if (mb_strlen($name) < 3 || !in_array($type, ['national_id', 'passport', 'driving_licence'], true) || strlen($num) < 6) {
            throw new DomainException('Fill in your full legal name, document type and document number.');
        }
        Db::tx(function () use ($me, $name, $type, $num): void {
            Db::run('INSERT INTO kyc_records (user_id, full_name, doc_type, doc_number, business_tin, payout_account) VALUES (?,?,?,?,?,?)',
                [$me['id'], $name, $type, $num, trim(post('business_tin')) ?: null, trim(post('payout_account')) ?: null]);
            Db::run("UPDATE users SET kyc_status = 'pending' WHERE id = ?", [$me['id']]);
            Audit::log((int)$me['id'], 'kyc.submit', 'user', (int)$me['id']);
            Notifier::staff('kyc.submitted', 'KYC submitted by ' . $me['name'], null, '/admin/kyc');
        });
        flash('success', 'Submitted. We will review your details shortly.');
        redirect('/kyc');
    }

    // ---------- notifications & messages ----------
    public function notifications(): void
    {
        $this->show('account/notifications', ['title' => 'Notifications', 'items' => Db::all('SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 60', [$this->uid()])]);
    }

    public function notificationsRead(): void
    {
        Db::run('UPDATE notifications SET read_at = ? WHERE user_id = ? AND read_at IS NULL', [now(), $this->uid()]);
        redirect('/notifications');
    }

    public function messages(): void
    {
        $id = $this->uid();
        $threads = Db::all(
            "SELECT u.id, u.name, MAX(m.id) AS last_id,
                    SUM(CASE WHEN m.to_id = :me AND m.read_at IS NULL THEN 1 ELSE 0 END) AS unread
             FROM messages m JOIN users u ON u.id = CASE WHEN m.from_id = :me THEN m.to_id ELSE m.from_id END
             WHERE m.from_id = :me OR m.to_id = :me GROUP BY u.id ORDER BY last_id DESC",
            ['me' => $id]
        );
        $this->show('account/messages', ['title' => 'Messages', 'threads' => $threads, 'with' => null, 'chat' => []]);
    }

    public function thread(array $p): void
    {
        $id = $this->uid();
        $other = Db::one('SELECT id, name FROM users WHERE id = ?', [(int)$p['id']]);
        if (!$other || (int)$other['id'] === $id) {
            abort(404);
        }
        Db::run('UPDATE messages SET read_at = ? WHERE to_id = ? AND from_id = ? AND read_at IS NULL', [now(), $id, $other['id']]);
        $threads = Db::all(
            "SELECT u.id, u.name, MAX(m.id) AS last_id, SUM(CASE WHEN m.to_id = :me AND m.read_at IS NULL THEN 1 ELSE 0 END) AS unread
             FROM messages m JOIN users u ON u.id = CASE WHEN m.from_id = :me THEN m.to_id ELSE m.from_id END
             WHERE m.from_id = :me OR m.to_id = :me GROUP BY u.id ORDER BY last_id DESC",
            ['me' => $id]
        );
        $chat = Db::all('SELECT * FROM messages WHERE (from_id = ? AND to_id = ?) OR (from_id = ? AND to_id = ?) ORDER BY id', [$id, $other['id'], $other['id'], $id]);
        $this->show('account/messages', ['title' => 'Chat with ' . $other['name'], 'threads' => $threads, 'with' => $other, 'chat' => $chat]);
    }

    public function sendMessage(): void
    {
        $to = (int)post('to');
        $body = trim(post('body'));
        if ($to === $this->uid() || !Db::val('SELECT COUNT(*) FROM users WHERE id = ?', [$to]) || $body === '' || mb_strlen($body) > 1000) {
            throw new DomainException('Write a message (up to 1000 characters).');
        }
        Db::run('INSERT INTO messages (from_id, to_id, body) VALUES (?,?,?)', [$this->uid(), $to, $body]);
        Notifier::send($to, 'message.new', 'New message from ' . Auth::user()['name'], mb_substr($body, 0, 80), '/messages/' . $this->uid());
        redirect('/messages/' . $to);
    }
}
