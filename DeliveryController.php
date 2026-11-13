<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Services\DomainException;
use App\Services\Ledger;
use App\Services\Notifier;
use App\Services\OrderStateMachine;

final class DeliveryController extends Controller
{
    private const JOB_SQL = "SELECT j.*, o.code, o.status AS order_status, o.ship_name, o.ship_phone, o.ship_address, o.total, o.delivery_method,
                s.name AS store_name, s.description AS store_desc
            FROM delivery_jobs j JOIN orders o ON o.id = j.order_id JOIN stores s ON s.id = o.store_id";

    public function dashboard(): void
    {
        $uid = $this->uid();
        $today = Db::all(self::JOB_SQL . " WHERE j.partner_id = ? AND date(j.updated_at) = date('now') ORDER BY j.updated_at DESC", [$uid]);
        $this->dash('delivery', 'delivery/dashboard', [
            'title' => 'Delivery dashboard', 'bal' => Ledger::balance($uid), 'today' => $today,
            'open' => (int)Db::val("SELECT COUNT(*) FROM delivery_jobs WHERE status='open' AND partner_id IS NULL"),
            'active' => (int)Db::val("SELECT COUNT(*) FROM delivery_jobs WHERE partner_id = ? AND status IN ('accepted','picked_up','out_for_delivery')", [$uid]),
            'done' => (int)Db::val("SELECT COUNT(*) FROM delivery_jobs WHERE partner_id = ? AND status='delivered'", [$uid]),
        ]);
    }

    public function jobs(): void
    {
        $this->dash('delivery', 'delivery/jobs', ['title' => 'Available jobs', 'jobs' => Db::all(self::JOB_SQL . " WHERE j.status='open' AND j.partner_id IS NULL AND o.status='packed' ORDER BY j.id")]);
    }

    public function mine(): void
    {
        $this->dash('delivery', 'delivery/mine', ['title' => 'My deliveries', 'jobs' => Db::all(self::JOB_SQL . ' WHERE j.partner_id = ? ORDER BY j.updated_at DESC LIMIT 100', [$this->uid()])]);
    }

    public function accept(array $p): void
    {
        $n = Db::run("UPDATE delivery_jobs SET partner_id = ?, status = 'accepted', updated_at = ? WHERE id = ? AND partner_id IS NULL AND status = 'open'", [$this->uid(), now(), (int)$p['id']]);
        if ($n !== 1) {
            throw new DomainException('Another courier already took this job.');
        }
        flash('success', 'Job accepted. Pick the parcel up from the store.');
        redirect('/delivery/mine');
    }

    public function step(array $p): void
    {
        $uid = $this->uid();
        $job = Db::one('SELECT j.*, o.delivery_code FROM delivery_jobs j JOIN orders o ON o.id = j.order_id WHERE j.id = ?', [(int)$p['id']]);
        if (!$job || (int)$job['partner_id'] !== $uid) {
            abort(403, 'This job is not assigned to you.');
        }
        $actor = ['kind' => 'delivery', 'id' => $uid];
        $oid = (int)$job['order_id'];
        switch ($p['step']) {
            case 'pickup':
                if ($job['status'] !== 'accepted') {
                    throw new DomainException('This job cannot be picked up now.');
                }
                OrderStateMachine::transition($oid, 'shipped', $actor, 'Picked up from store');
                flash('success', 'Marked as picked up.');
                break;
            case 'out':
                OrderStateMachine::transition($oid, 'out_for_delivery', $actor, 'Courier on the way');
                flash('success', 'Marked out for delivery.');
                break;
            case 'delivered':
                if (!hash_equals((string)$job['delivery_code'], trim(post('code')))) {
                    throw new DomainException('Wrong delivery code. Ask the buyer for the 4-digit code.');
                }
                OrderStateMachine::transition($oid, 'delivered', $actor, 'Delivered (code verified)');
                flash('success', 'Delivered. Your earning becomes available when the order completes.');
                break;
            case 'failed':
                $reason = trim(post('reason'));
                if ($reason === '' || in_array($job['status'], ['delivered', 'failed'], true)) {
                    throw new DomainException('Give a reason for the failed delivery.');
                }
                Db::run("UPDATE delivery_jobs SET status = 'failed', failed_reason = ?, updated_at = ? WHERE id = ?", [mb_substr($reason, 0, 200), now(), $job['id']]);
                Notifier::staff('delivery.failed', 'Failed delivery', 'Order #' . $oid . ': ' . $reason, '/admin/orders');
                flash('warn', 'Failed delivery recorded. Support will contact the buyer.');
                break;
            default:
                abort(404);
        }
        redirect('/delivery/mine');
    }
}
