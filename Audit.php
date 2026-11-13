<?php
declare(strict_types=1);

namespace App\Core;

/** Append-only audit trail (enforced by DB triggers). Sensitive admin/finance actions must call this. */
final class Audit
{
    public static function log(?int $actorId, string $action, ?string $entity = null, ?int $entityId = null, array|string|null $detail = null): void
    {
        Db::run(
            'INSERT INTO audit_log (actor_id, action, entity, entity_id, detail, ip) VALUES (?,?,?,?,?,?)',
            [$actorId, $action, $entity, $entityId, is_array($detail) ? json_encode($detail) : $detail, PHP_SAPI === 'cli' ? 'cli' : client_ip()]
        );
    }
}
