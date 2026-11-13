<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/**
 * Splits the PRODUCT amount of an order between seller, reseller, affiliate and platform.
 * Rules are versioned in the commission_rules table; every commission row records the version used.
 *
 *   seller only          97 / 3
 *   seller + reseller    93 / 4 / 3
 *   seller + affiliate   96 / 1 / 3
 *   all roles            92 / 4 / 1 / 3
 *
 * Low-profit rule: if the seller's profit (payout minus cost price) would fall below the threshold,
 * the platform takes the reduced rate instead (1.5% by default). Only applies when a cost price is known.
 * Rounding: each non-seller share is rounded half-up to whole francs; the seller gets the remainder, so the
 * parts always add up to the product amount exactly.
 */
final class CommissionEngine
{
    public static function activeRule(?string $at = null): array
    {
        $at ??= now();
        $rule = Db::one(
            'SELECT * FROM commission_rules WHERE effective_from <= ? ORDER BY version DESC LIMIT 1',
            [$at]
        );
        if (!$rule) {
            throw new DomainException('No commission rule is configured.');
        }
        return $rule;
    }

    public static function share(int $amount, int $bps): int
    {
        return intdiv($amount * $bps + 5000, 10000);
    }

    /**
     * @return array{seller:array{bps:int,amount:int},reseller:array{bps:int,amount:int},affiliate:array{bps:int,amount:int},platform:array{bps:int,amount:int},rule_version:int,low_profit:bool}
     */
    public static function split(int $base, bool $hasReseller, bool $hasAffiliate, int $costTotal, array $rule): array
    {
        if ($base < 0) {
            throw new \InvalidArgumentException('Base amount cannot be negative.');
        }
        $build = function (int $platformBps) use ($base, $hasReseller, $hasAffiliate, $rule): array {
            $rBps = $hasReseller ? (int)$rule['reseller_bps'] : 0;
            $aBps = $hasAffiliate ? (int)$rule['affiliate_bps'] : 0;
            $r = self::share($base, $rBps);
            $a = self::share($base, $aBps);
            $p = self::share($base, $platformBps);
            return [
                'seller'    => ['bps' => 10000 - $rBps - $aBps - $platformBps, 'amount' => $base - $r - $a - $p],
                'reseller'  => ['bps' => $rBps, 'amount' => $r],
                'affiliate' => ['bps' => $aBps, 'amount' => $a],
                'platform'  => ['bps' => $platformBps, 'amount' => $p],
            ];
        };

        $parts = $build((int)$rule['platform_bps']);
        $low = false;
        if ($costTotal > 0 && ($parts['seller']['amount'] - $costTotal) < (int)$rule['low_profit_threshold']) {
            $parts = $build((int)$rule['low_profit_platform_bps']);
            $low = true;
        }
        $parts['rule_version'] = (int)$rule['version'];
        $parts['low_profit'] = $low;
        return $parts;
    }
}
