<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/**
 * Wallet ledger. Entries are append-only (DB triggers block UPDATE/DELETE); corrections are new entries.
 * Two buckets: "pending" (held until the order completes) and "available" (withdrawable).
 */
final class Ledger
{
    public static function add(int $userId, ?int $orderId, string $type, string $bucket, int $amount, ?string $ref = null, ?string $note = null): int
    {
        if ($amount === 0) {
            return 0;
        }
        return Db::insert(
            'INSERT INTO ledger_entries (user_id, order_id, type, bucket, amount, ref, note) VALUES (?,?,?,?,?,?,?)',
            [$userId, $orderId, $type, $bucket, $amount, $ref, $note]
        );
    }

    /** @return array{available:int,pending:int,withdrawn:int,total_earnings:int} */
    public static function balance(int $userId): array
    {
        $row = Db::one(
            "SELECT
                COALESCE(SUM(CASE WHEN bucket='available' THEN amount END),0) AS available,
                COALESCE(SUM(CASE WHEN bucket='pending'   THEN amount END),0) AS pending,
                COALESCE(SUM(CASE WHEN type IN ('withdrawal_hold','withdrawal_reversal') THEN -amount END),0) AS withdrawn
             FROM ledger_entries WHERE user_id = ?",
            [$userId]
        );
        $a = (int)$row['available'];
        $p = (int)$row['pending'];
        $w = (int)$row['withdrawn'];
        return ['available' => $a, 'pending' => $p, 'withdrawn' => $w, 'total_earnings' => $a + $p + $w];
    }

    /** Net pending amount each user still has held against an order. @return array<int,int> user_id => amount */
    public static function pendingForOrder(int $orderId): array
    {
        $rows = Db::all(
            "SELECT user_id, SUM(amount) AS net FROM ledger_entries WHERE order_id = ? AND bucket = 'pending' GROUP BY user_id",
            [$orderId]
        );
        $out = [];
        foreach ($rows as $r) {
            if ((int)$r['net'] !== 0) {
                $out[(int)$r['user_id']] = (int)$r['net'];
            }
        }
        return $out;
    }

    public static function entries(int $userId, int $limit = 50, int $offset = 0): array
    {
        return Db::all(
            'SELECT l.*, o.code AS order_code FROM ledger_entries l LEFT JOIN orders o ON o.id = l.order_id
             WHERE l.user_id = ? ORDER BY l.id DESC LIMIT ? OFFSET ?',
            [$userId, $limit, $offset]
        );
    }
}
