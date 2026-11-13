<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/**
 * In-app notifications. Email/SMS/push channels are not wired up yet; add them here (queue + retries)
 * so every event keeps a single entry point.
 */
final class Notifier
{
    public static function send(int $userId, string $event, string $title, ?string $body = null, ?string $link = null): void
    {
        Db::run(
            'INSERT INTO notifications (user_id, event, title, body, link) VALUES (?,?,?,?,?)',
            [$userId, $event, $title, $body, $link]
        );
    }

    public static function staff(string $event, string $title, ?string $body = null, ?string $link = null): void
    {
        $ids = Db::all("SELECT DISTINCT user_id FROM user_roles WHERE role IN ('admin','finance_admin','support_admin')");
        foreach ($ids as $r) {
            self::send((int)$r['user_id'], $event, $title, $body, $link);
        }
    }
}
