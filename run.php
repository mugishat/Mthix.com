<?php
declare(strict_types=1);

/**
 * Business-rule tests. Run:  php tests/run.php
 * Uses its own throw-away database; never touches data/mthix.sqlite.
 * Covers the "critical scenarios" listed in the MTHIX master document: duplicate webhook, double submit,
 * cancellation after payment, refund after commission, stock race, duplicate withdrawal, state-machine abuse.
 */
$tmp = sys_get_temp_dir() . '/mthix_test_' . getmypid() . '.sqlite';
putenv('MTHIX_DB=' . $tmp);
putenv('MTHIX_ENV=dev');

require __DIR__ . '/../app/bootstrap.php';
require ROOT . '/database/seed.php';

use App\Core\Db;
use App\Services\CartService;
use App\Services\CheckoutService;
use App\Services\CommissionEngine as CE;
use App\Services\DomainException;
use App\Services\Ledger;
use App\Services\OrderStateMachine as OSM;
use App\Services\PaymentService;
use App\Services\WithdrawalService;

$pass = 0;
$fail = 0;
function ok(bool $cond, string $name): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  ok   $name\n";
    } else {
        $fail++;
        echo "  FAIL $name\n";
    }
}
function throws(callable $fn, string $name, ?string $contains = null): void
{
    try {
        $fn();
        ok(false, $name . ' (no exception)');
    } catch (DomainException $e) {
        ok($contains === null || stripos($e->getMessage(), $contains) !== false, $name);
    }
}
function fresh(): array
{
    global $tmp;
    Db::reset();
    foreach ([$tmp, $tmp . '-wal', $tmp . '-shm'] as $f) {
        @unlink($f);
    }
    seed_install_schema();
    $ids = seed_base();
    seed_catalog($ids);
    return $ids;
}
function bal(int $uid): array
{
    return Ledger::balance($uid);
}
function ledgerSumFor(int $orderId): int
{
    return (int)Db::val('SELECT COALESCE(SUM(amount),0) FROM ledger_entries WHERE order_id = ?', [$orderId]);
}

$rule = ['version' => 1, 'platform_bps' => 300, 'reseller_bps' => 400, 'affiliate_bps' => 100, 'low_profit_threshold' => 1000, 'low_profit_platform_bps' => 150];

echo "Commission engine\n";
$s = CE::split(100000, false, false, 0, $rule);
ok($s['seller']['amount'] === 97000 && $s['platform']['amount'] === 3000, 'seller only = 97/3');
$s = CE::split(100000, true, false, 0, $rule);
ok($s['seller']['amount'] === 93000 && $s['reseller']['amount'] === 4000 && $s['platform']['amount'] === 3000, 'seller+reseller = 93/4/3');
$s = CE::split(100000, false, true, 0, $rule);
ok($s['seller']['amount'] === 96000 && $s['affiliate']['amount'] === 1000, 'seller+affiliate = 96/1/3');
$s = CE::split(100000, true, true, 0, $rule);
ok($s['seller']['amount'] === 92000 && $s['reseller']['amount'] === 4000 && $s['affiliate']['amount'] === 1000 && $s['platform']['amount'] === 3000, 'all roles = 92/4/1/3');
$sumOk = true;
foreach ([1, 7, 99, 1234, 99999, 123457, 1350000] as $base) {
    foreach ([[0, 0], [1, 0], [0, 1], [1, 1]] as [$r, $a]) {
        $x = CE::split($base, (bool)$r, (bool)$a, 0, $rule);
        $sumOk = $sumOk && ($x['seller']['amount'] + $x['reseller']['amount'] + $x['affiliate']['amount'] + $x['platform']['amount'] === $base);
    }
}
ok($sumOk, 'parts always add up exactly to the product amount (rounding)');
$s = CE::split(10000, false, false, 9000, $rule);
ok($s['low_profit'] && $s['platform']['bps'] === 150 && $s['platform']['amount'] === 150, 'low-profit rule: platform takes 1.5% when seller profit < 1,000');
$s = CE::split(10000, false, false, 5000, $rule);
ok(!$s['low_profit'] && $s['platform']['bps'] === 300, 'normal rate when seller profit is healthy');

echo "\nFull transaction loop (product -> checkout -> payment -> order -> delivery -> settlement -> wallet)\n";
$ids = fresh();
$rc = (string)Db::val('SELECT ref_code FROM users WHERE id = ?', [$ids['reseller']]);
$ac = (string)Db::val('SELECT ref_code FROM users WHERE id = ?', [$ids['affiliate']]);
$vJbl = seed_variant($ids['p_jbl']);
$o = seed_order($ids['buyer'], [$vJbl => 2], 'paid', ['r' => $rc . ':' . $ids['p_jbl'], 'a' => $ac]);
ok($o['status'] === 'paid', 'order is paid after a successful payment');
ok((int)Db::val('SELECT amount FROM escrow WHERE order_id = ? AND status = "held"', [$o['id']]) === 173000, 'escrow holds product + delivery');
ok(bal($ids['seller'])['pending'] === 156400 && bal($ids['seller'])['available'] === 0, 'seller earnings are pending, not withdrawable');
ok(bal($ids['reseller'])['pending'] === 6800 && bal($ids['affiliate'])['pending'] === 1700, 'reseller 4% and affiliate 1% credited as pending');
ok(ledgerSumFor((int)$o['id']) === 173000, 'ledger for the order sums to exactly what the buyer paid');

$sellerActor = ['kind' => 'seller', 'id' => $ids['seller']];
$courier = ['kind' => 'delivery', 'id' => $ids['delivery']];
$buyer = ['kind' => 'buyer', 'id' => $ids['buyer']];
OSM::transition((int)$o['id'], 'confirmed', $sellerActor);
OSM::transition((int)$o['id'], 'packed', $sellerActor);
ok((int)Db::val('SELECT COUNT(*) FROM delivery_jobs WHERE order_id = ?', [$o['id']]) === 1, 'a delivery job is created when the order is packed');
Db::run("UPDATE delivery_jobs SET partner_id = ?, status = 'accepted' WHERE order_id = ?", [$ids['delivery'], $o['id']]);
OSM::transition((int)$o['id'], 'shipped', $courier);
OSM::transition((int)$o['id'], 'out_for_delivery', $courier);
OSM::transition((int)$o['id'], 'delivered', $courier);
ok(bal($ids['delivery'])['pending'] === 3000, 'courier delivery fee is pending after delivery');
OSM::transition((int)$o['id'], 'completed', $buyer);
ok(bal($ids['seller'])['available'] === 156400 && bal($ids['seller'])['pending'] === 0, 'seller earnings become available on completion');
ok(bal($ids['reseller'])['available'] === 6800 && bal($ids['affiliate'])['available'] === 1700, 'commissions become available on completion');
ok(bal($ids['delivery'])['available'] === 3000, 'courier fee becomes available on completion');
ok(bal($ids['admin'])['available'] === 5100 && bal($ids['admin'])['pending'] === 0, 'platform commission settled (3% of 170,000)');
ok((int)Db::val('SELECT SUM(amount) FROM ledger_entries WHERE order_id = ?', [$o['id']]) === 173000, 'ledger still sums to the amount paid (nothing created or lost)');
ok(Db::val('SELECT status FROM escrow WHERE order_id = ?', [$o['id']]) === 'released', 'escrow released');

echo "\nImmutability\n";
try {
    Db::run('UPDATE ledger_entries SET amount = 1 WHERE id = 1');
    ok(false, 'ledger UPDATE blocked');
} catch (\Throwable) {
    ok(true, 'ledger UPDATE blocked');
}
\App\Core\Audit::log(null, 'test.entry');
try {
    Db::run('DELETE FROM audit_log');
    ok(false, 'audit DELETE blocked');
} catch (\Throwable) {
    ok(true, 'audit DELETE blocked');
}

echo "\nPayments\n";
$ids = fresh();
$o = seed_order($ids['buyer'], [seed_variant($ids['p_jbl']) => 1], 'pending_payment');
$pay = PaymentService::initiate([$o], 'mtn')[0];
$count0 = (int)Db::val('SELECT COUNT(*) FROM ledger_entries');
ok(PaymentService::handleEvent($pay['external_ref'], 'success', $pay['amount']) === 'processed', 'webhook processed once');
$count1 = (int)Db::val('SELECT COUNT(*) FROM ledger_entries');
ok(PaymentService::handleEvent($pay['external_ref'], 'success', $pay['amount']) === 'duplicate', 'duplicate webhook is acknowledged');
ok((int)Db::val('SELECT COUNT(*) FROM ledger_entries') === $count1 && $count1 > $count0, 'duplicate webhook creates NO extra ledger entries');
ok((int)Db::val('SELECT COUNT(*) FROM escrow WHERE order_id = ?', [$o['id']]) === 1, 'only one escrow row');
ok(PaymentService::verifySignature('{"a":1}', hash_hmac('sha256', '{"a":1}', cfg('webhook_secret'))) && !PaymentService::verifySignature('{"a":1}', 'bad'), 'webhook signature check');

$o2 = seed_order($ids['buyer'], [seed_variant($ids['p_lamp']) => 1], 'pending_payment');
$p2 = PaymentService::initiate([$o2], 'airtel')[0];
ok(PaymentService::handleEvent($p2['external_ref'], 'success', $p2['amount'] - 1) === 'mismatch', 'wrong amount is never settled');
ok(Db::val('SELECT status FROM orders WHERE id = ?', [$o2['id']]) === 'pending_payment', 'order stays unpaid after an amount mismatch');
ok(PaymentService::handleEvent($p2['external_ref'], 'failed', $p2['amount']) === 'failed', 'failed payment recorded');
throws(fn() => PaymentService::handleEvent('NOPE-1', 'success', 1), 'unknown reference rejected');

$o3 = seed_order($ids['buyer2'], [seed_variant($ids['p_mat']) => 1], 'pending_payment');
$p3 = PaymentService::initiate([$o3], 'card')[0];
OSM::transition((int)$o3['id'], 'cancelled', OSM::system(), 'expired');
ok(PaymentService::handleEvent($p3['external_ref'], 'success', $p3['amount']) === 'orphan', 'payment arriving for a cancelled order is flagged, not settled');
ok((int)Db::val('SELECT COUNT(*) FROM escrow WHERE order_id = ?', [$o3['id']]) === 0, 'no escrow or ledger for the orphan payment');

echo "\nCancellation and refund\n";
$ids = fresh();
$stock0 = (int)Db::val('SELECT stock FROM variants WHERE id = ?', [seed_variant($ids['p_jbl'])]);
$o = seed_order($ids['buyer'], [seed_variant($ids['p_jbl']) => 2], 'confirmed', ['a' => (string)Db::val('SELECT ref_code FROM users WHERE id = ?', [$ids['affiliate']])]);
ok((int)Db::val('SELECT stock FROM variants WHERE id = ?', [seed_variant($ids['p_jbl'])]) === $stock0 - 2, 'stock reserved at checkout');
OSM::transition((int)$o['id'], 'cancelled', ['kind' => 'seller', 'id' => $ids['seller']], 'out of stock');
ok(ledgerSumFor((int)$o['id']) === 0, 'cancel after payment reverses every pending entry to zero');
ok(bal($ids['seller'])['pending'] === 0 && bal($ids['affiliate'])['pending'] === 0 && bal($ids['admin'])['pending'] === 0, 'nobody keeps money from a refunded order');
ok((int)Db::val('SELECT stock FROM variants WHERE id = ?', [seed_variant($ids['p_jbl'])]) === $stock0, 'stock returned on cancellation');
ok(Db::val('SELECT status FROM escrow WHERE order_id = ?', [$o['id']]) === 'refunded' && Db::val("SELECT status FROM payments WHERE order_id = ?", [$o['id']]) === 'refunded', 'escrow and payment marked refunded');
throws(fn() => OSM::transition((int)$o['id'], 'confirmed', ['kind' => 'seller', 'id' => $ids['seller']]), 'a cancelled order cannot be revived');

$o = seed_order($ids['buyer'], [seed_variant($ids['p_jbl']) => 1], 'delivered');
OSM::transition((int)$o['id'], 'refunded', ['kind' => 'admin', 'id' => $ids['admin']], 'damaged');
ok(ledgerSumFor((int)$o['id']) === 0, 'admin refund from delivered also zeroes the ledger (courier fee included)');

echo "\nStock race / overselling\n";
$ids = fresh();
$v = seed_variant($ids['p_jordan'], 2); // Black size 42, stock 3
ok((int)Db::val('SELECT stock FROM variants WHERE id = ?', [$v]) === 3, 'variant starts with 3 in stock');
seed_order($ids['buyer'], [$v => 2], 'pending_payment');
throws(fn() => seed_order($ids['buyer2'], [$v => 2], 'pending_payment'), 'second buyer cannot take more than what is left', 'stock');
ok((int)Db::val('SELECT stock FROM variants WHERE id = ?', [$v]) === 1, 'stock is exactly what is left (no negative, no phantom)');
$before = (int)Db::val('SELECT COUNT(*) FROM orders');
try {
    CheckoutService::place($ids['buyer2'], seed_lines([$v => 1, seed_variant($ids['p_hp']) => 99]), ['name' => 'A', 'phone' => '+250788000000', 'address' => 'X'], 'mthix');
} catch (DomainException) {
}
ok((int)Db::val('SELECT COUNT(*) FROM orders') === $before && (int)Db::val('SELECT stock FROM variants WHERE id = ?', [$v]) === 1, 'failed checkout rolls back completely (no half order, no lost stock)');

echo "\nState machine permissions\n";
$ids = fresh();
$o = seed_order($ids['buyer'], [seed_variant($ids['p_jbl']) => 1], 'paid');
throws(fn() => OSM::transition((int)$o['id'], 'confirmed', ['kind' => 'buyer', 'id' => $ids['buyer']]), 'buyer cannot confirm an order');
throws(fn() => OSM::transition((int)$o['id'], 'confirmed', ['kind' => 'seller', 'id' => $ids['seller2']]), "another seller cannot touch this store's order");
throws(fn() => OSM::transition((int)$o['id'], 'delivered', ['kind' => 'admin', 'id' => $ids['admin']]), 'no skipping steps (paid -> delivered)');
throws(fn() => OSM::transition((int)$o['id'], 'paid', ['kind' => 'seller', 'id' => $ids['seller']]), 'cannot "pay" an order by hand');
OSM::transition((int)$o['id'], 'confirmed', ['kind' => 'seller', 'id' => $ids['seller']]);
OSM::transition((int)$o['id'], 'packed', ['kind' => 'seller', 'id' => $ids['seller']]);
throws(fn() => OSM::transition((int)$o['id'], 'shipped', ['kind' => 'seller', 'id' => $ids['seller']]), 'seller cannot mark shipped (courier does)');
ok(Db::val('SELECT COUNT(*) FROM order_events WHERE order_id = ?', [$o['id']]) === 4, 'every transition left an event (placed, paid, confirmed, packed)');
$o = seed_order($ids['buyer'], [seed_variant($ids['p_jbl']) => 1], 'delivered');
Db::run("INSERT INTO disputes (order_id, opened_by, reason) VALUES (?,?,?)", [$o['id'], $ids['buyer'], 'not as described']);
throws(fn() => OSM::transition((int)$o['id'], 'completed', ['kind' => 'buyer', 'id' => $ids['buyer']]), 'open dispute blocks the buyer from releasing escrow', 'dispute');
throws(fn() => OSM::transition((int)$o['id'], 'completed', ['kind' => 'buyer', 'id' => $ids['buyer2']]), "a different buyer cannot confirm someone else's order");

echo "\nWithdrawals\n";
$ids = fresh();
$o = seed_order($ids['buyer'], [seed_variant($ids['p_iphone']) => 1], 'completed');
$avail = bal($ids['seller'])['available'];
ok($avail === 1309500 - 0 || $avail > 1000000, 'seller has available funds after a completed order (' . $avail . ')');
Db::run("UPDATE users SET kyc_status = 'none' WHERE id = ?", [$ids['seller']]);
throws(fn() => WithdrawalService::request($ids['seller'], 10000, 'mtn', '0788111222', str_repeat('a', 20)), 'KYC required to withdraw', 'verification');
Db::run("UPDATE users SET kyc_status = 'verified' WHERE id = ?", [$ids['seller']]);
throws(fn() => WithdrawalService::request($ids['seller'], 1000, 'mtn', '0788111222', str_repeat('b', 20)), 'below minimum rejected', 'minimum');
throws(fn() => WithdrawalService::request($ids['seller'], $avail + 1, 'mtn', '0788111222', str_repeat('c', 20)), 'cannot withdraw more than available', 'available');
$w = WithdrawalService::request($ids['seller'], 20000, 'mtn', '0788111222', 'idemkeyidemkey0001');
$w2 = WithdrawalService::request($ids['seller'], 20000, 'mtn', '0788111222', 'idemkeyidemkey0001');
ok($w['id'] === $w2['id'] && (int)Db::val('SELECT COUNT(*) FROM withdrawals') === 1, 'double-click creates only one withdrawal');
ok(bal($ids['seller'])['available'] === $avail - 20000, 'amount is held out of the available balance immediately');
ok($w['status'] === 'approved', 'small, clean withdrawal is auto-approved');
$big = WithdrawalService::request($ids['seller'], 600000, 'mtn', '0788333444', 'idemkeyidemkey0002');
ok($big['status'] === 'review' && str_contains($big['risk_flags'], 'large_amount'), 'large withdrawal goes to manual review');
WithdrawalService::reject((int)$big['id'], $ids['admin'], 'unclear source');
ok(bal($ids['seller'])['available'] === $avail - 20000, 'rejected withdrawal returns the money');
WithdrawalService::markPaid((int)$w['id'], $ids['finance']);
ok(Db::val('SELECT status FROM withdrawals WHERE id = ?', [$w['id']]) === 'paid', 'approved withdrawal can be paid');
throws(fn() => WithdrawalService::markPaid((int)$w['id'], $ids['finance']), 'cannot pay the same withdrawal twice');
ok(bal($ids['seller'])['total_earnings'] === $avail + bal($ids['seller'])['pending'], 'total earnings = available + pending + withdrawn');

echo "\nAttribution\n";
$ids = fresh();
$rc = (string)Db::val('SELECT ref_code FROM users WHERE id = ?', [$ids['reseller']]);
$ac = (string)Db::val('SELECT ref_code FROM users WHERE id = ?', [$ids['affiliate']]);
$o = seed_order($ids['buyer'], [seed_variant($ids['p_jbl']) => 1], 'paid', ['r' => $rc . ':' . $ids['p_sony']]);
ok($o['reseller_id'] === null, 'reseller is not credited when the shared product is not in the order');
$o = seed_order($ids['reseller'], [seed_variant($ids['p_jbl']) => 1], 'paid', ['r' => $rc . ':' . $ids['p_jbl'], 'a' => $ac]);
ok($o['reseller_id'] === null, 'a reseller cannot earn commission on their own purchase');
$o = seed_order($ids['buyer'], [seed_variant($ids['p_mat']) => 1], 'paid', ['r' => $rc . ':' . $ids['p_mat']]);
ok($o['reseller_id'] === null, 'products not eligible for resellers earn no reseller commission');

echo "\n" . ($fail === 0 ? "All $pass checks passed." : "$fail FAILED, $pass passed.") . "\n";
foreach ([$tmp, $tmp . '-wal', $tmp . '-shm'] as $f) {
    @unlink($f);
}
exit($fail === 0 ? 0 : 1);
