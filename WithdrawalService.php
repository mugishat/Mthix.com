<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Db;

/**
 * Requested -> risk/KYC checks -> (manual review) -> Approved -> Processing -> Paid.
 * The amount is taken out of the available balance the moment it is requested (a ledger "hold"),
 * so the same money can never be requested twice.
 */
final class WithdrawalService
{
    public static function request(int $userId, int $amount, string $method, string $account, string $idemKey): array
    {
        if (!preg_match('/^[A-Za-z0-9]{16,64}$/', $idemKey)) {
            throw new DomainException('Invalid request. Refresh the page and try again.');
        }
        if (!isset(cfg('payment_methods')[$method]) && $method !== 'bank') {
            throw new DomainException('Choose a payout method.');
        }
        $account = trim($account);
        if ($account === '' || strlen($account) > 40) {
            throw new DomainException('Enter the account or phone number to pay out to.');
        }
        $min = (int)cfg('min_withdrawal');
        if ($amount < $min) {
            throw new DomainException('The minimum withdrawal is ' . money($min) . '.');
        }

        return Db::tx(function () use ($userId, $amount, $method, $account, $idemKey): array {
            $existing = Db::one('SELECT * FROM withdrawals WHERE idem_key = ?', [$idemKey]);
            if ($existing) {
                return $existing; // double submit: return the first result, create nothing
            }
            $user = Db::one('SELECT * FROM users WHERE id = ?', [$userId]);
            if ($user['kyc_status'] !== 'verified') {
                throw new DomainException('Complete identity verification (KYC) before withdrawing.');
            }
            $bal = Ledger::balance($userId);
            if ($amount > $bal['available']) {
                throw new DomainException('You only have ' . money($bal['available']) . ' available. Pending earnings unlock when orders complete.');
            }

            $flags = [];
            if ($amount >= (int)cfg('review_threshold')) {
                $flags[] = 'large_amount';
            }
            if ((int)Db::val("SELECT julianday('now') - julianday(?)", [$user['created_at']]) < (int)cfg('new_account_days')) {
                $flags[] = 'new_account';
            }
            if ((int)Db::val("SELECT COUNT(*) FROM withdrawals WHERE user_id = ? AND created_at > datetime('now','-1 day')", [$userId]) >= 3) {
                $flags[] = 'high_frequency';
            }
            if ((int)Db::val('SELECT COUNT(*) FROM withdrawals WHERE account = ? AND user_id != ?', [$account, $userId]) > 0) {
                $flags[] = 'shared_account';
            }

            $id = Db::insert(
                'INSERT INTO withdrawals (user_id, amount, method, account, status, risk_flags, idem_key) VALUES (?,?,?,?,?,?,?)',
                [$userId, $amount, $method, $account, $flags ? 'review' : 'approved', implode(',', $flags), $idemKey]
            );
            Ledger::add($userId, null, 'withdrawal_hold', 'available', -$amount, 'W' . $id, 'Withdrawal requested');
            if ($flags) {
                Notifier::staff('withdrawal.review', 'Withdrawal needs review', money($amount) . ' — ' . implode(', ', $flags), '/admin/withdrawals');
            }
            return Db::one('SELECT * FROM withdrawals WHERE id = ?', [$id]);
        });
    }

    public static function approve(int $id, int $adminId): void
    {
        Db::tx(function () use ($id, $adminId): void {
            $w = self::open($id, ['review']);
            Db::run("UPDATE withdrawals SET status = 'approved', reviewed_by = ?, reviewed_at = ? WHERE id = ?", [$adminId, now(), $id]);
            Audit::log($adminId, 'withdrawal.approve', 'withdrawal', $id, ['amount' => $w['amount']]);
            Notifier::send((int)$w['user_id'], 'withdrawal.approved', 'Withdrawal approved', money((int)$w['amount']) . ' is being processed.', '/wallet');
        });
    }

    public static function reject(int $id, int $adminId, string $note): void
    {
        Db::tx(function () use ($id, $adminId, $note): void {
            $w = self::open($id, ['review', 'approved']);
            Db::run("UPDATE withdrawals SET status = 'rejected', reviewed_by = ?, reviewed_at = ?, note = ? WHERE id = ?", [$adminId, now(), $note, $id]);
            Ledger::add((int)$w['user_id'], null, 'withdrawal_reversal', 'available', (int)$w['amount'], 'W' . $id, 'Withdrawal rejected');
            Audit::log($adminId, 'withdrawal.reject', 'withdrawal', $id, ['note' => $note]);
            Notifier::send((int)$w['user_id'], 'withdrawal.rejected', 'Withdrawal rejected', $note !== '' ? $note : 'Contact support for details.', '/wallet');
        });
    }

    /** Marks an approved withdrawal as paid out. The real payout call to the provider belongs here. */
    public static function markPaid(int $id, int $adminId): void
    {
        Db::tx(function () use ($id, $adminId): void {
            $w = self::open($id, ['approved']);
            Db::run("UPDATE withdrawals SET status = 'paid', paid_at = ?, reviewed_by = COALESCE(reviewed_by, ?) WHERE id = ?", [now(), $adminId, $id]);
            Audit::log($adminId, 'withdrawal.paid', 'withdrawal', $id, ['amount' => $w['amount']]);
            Notifier::send((int)$w['user_id'], 'withdrawal.paid', 'Withdrawal paid', money((int)$w['amount']) . ' was sent to ' . $w['account'] . '.', '/wallet');
        });
    }

    private static function open(int $id, array $allowed): array
    {
        $w = Db::one('SELECT * FROM withdrawals WHERE id = ?', [$id]);
        if (!$w || !in_array($w['status'], $allowed, true)) {
            throw new DomainException('This withdrawal cannot be changed in its current state.');
        }
        return $w;
    }
}
