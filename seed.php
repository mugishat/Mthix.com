<?php
declare(strict_types=1);

use App\Core\Db;
use App\Services\CartService;
use App\Services\CheckoutService;
use App\Services\OrderStateMachine as OSM;
use App\Services\PaymentService;
use App\Services\Inventory;

const DEMO_PASSWORD = 'Mthix@2026';

function seed_install_schema(): void
{
    Db::pdo()->exec(file_get_contents(ROOT . '/database/schema.sql'));
}

function seed_user(string $name, string $email, array $roles, string $kyc = 'verified', ?string $phone = null): int
{
    $id = Db::insert(
        'INSERT INTO users (name, email, phone, password_hash, kyc_status, ref_code, created_at) VALUES (?,?,?,?,?,?, datetime(\'now\',\'-60 days\'))',
        [$name, $email, $phone ?? '+250788' . random_int(100000, 999999), password_hash(DEMO_PASSWORD, PASSWORD_DEFAULT), $kyc, strtoupper(substr(bin2hex(random_bytes(4)), 0, 8))]
    );
    foreach ($roles as $r) {
        Db::run('INSERT INTO user_roles (user_id, role) VALUES (?,?)', [$id, $r]);
    }
    return $id;
}

function seed_store(int $userId, string $name, string $desc): int
{
    return Db::insert('INSERT INTO stores (user_id, name, slug, description) VALUES (?,?,?,?)', [$userId, $name, slugify($name), $desc]);
}

function seed_product(int $storeId, int $catId, string $name, string $brand, int $price, ?int $compare, string $desc, array $variants, bool $reseller = true): int
{
    $cost = (int)round($price * 0.85);
    $pid = Db::insert(
        "INSERT INTO products (store_id, category_id, name, slug, description, brand, base_price, compare_price, cost_price, status, reseller_eligible)
         VALUES (?,?,?,?,?,?,?,?,?, 'active', ?)",
        [$storeId, $catId, $name, slugify($name), $desc, $brand, $price, $compare, $cost, $reseller ? 1 : 0]
    );
    $prefix = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $name), 0, 3));
    $n = 0;
    foreach ($variants as [$color, $size, $vPrice, $stock]) {
        $n++;
        $vid = Db::insert(
            'INSERT INTO variants (product_id, sku, barcode, color, size, price, compare_price, cost_price, stock) VALUES (?,?,?,?,?,?,?,?,?)',
            [$pid, sprintf('%s-%s-%s-%03d', $prefix, strtoupper(substr((string)($color ?: 'STD'), 0, 3)), $size ?: 'STD', $n),
             '98765' . str_pad((string)$pid, 4, '0', STR_PAD_LEFT) . str_pad((string)$n, 3, '0', STR_PAD_LEFT),
             $color, $size, $vPrice ?? $price, $compare, (int)round(($vPrice ?? $price) * 0.85), $stock]
        );
        Db::run("INSERT INTO inventory_movements (variant_id, delta, reason) VALUES (?,?, 'initial')", [$vid, $stock]);
    }
    return $pid;
}

/** Minimal base data used by the app AND by the tests: platform account, categories, commission rule v1. */
function seed_base(): array
{
    $ids = [];
    $ids['admin']   = seed_user('MTHIX Platform', 'admin@mthix.rw', ['admin']);
    $ids['finance'] = seed_user('Grace Finance', 'finance@mthix.rw', ['finance_admin']);
    $ids['support'] = seed_user('Eric Support', 'support@mthix.rw', ['support_admin']);

    foreach (['Electronics', 'Fashion', 'Home & Living', 'Beauty', 'Sports', 'Automotive', 'Books', 'Kids & Babies', 'Health'] as $i => $c) {
        Db::run('INSERT INTO categories (name, slug, sort) VALUES (?,?,?)', [$c, slugify($c), $i]);
    }
    Db::run(
        "INSERT INTO commission_rules (version, platform_bps, reseller_bps, affiliate_bps, low_profit_threshold, low_profit_platform_bps, effective_from, created_by)
         VALUES (1, 300, 400, 100, 1000, 150, '2000-01-01 00:00:00', ?)",
        [$ids['admin']]
    );
    return $ids;
}

function seed_catalog(array &$ids): void
{
    $cat = fn(string $slug) => (int)Db::val('SELECT id FROM categories WHERE slug = ?', [$slug]);

    $ids['seller']  = seed_user('Jean Claude Store Owner', 'seller@mthix.rw', ['seller']);
    $ids['seller2'] = seed_user('TechWorld Owner', 'techworld@mthix.rw', ['seller']);
    $s1 = seed_store($ids['seller'], 'MTHIX Store', 'Quality electronics, fashion and gadgets with fast delivery across Kigali.');
    $s2 = seed_store($ids['seller2'], 'TechWorld RW', 'Laptops, phones and accessories from trusted brands.');
    $ids['store1'] = $s1;
    $ids['store2'] = $s2;

    $ids['p_iphone'] = seed_product($s1, $cat('electronics'), 'iPhone 14 Pro Max 256GB', 'Apple', 1350000, 1500000,
        'iPhone 14 Pro Max features a 6.7-inch Super Retina XDR display, A16 Bionic chip and a pro camera system.',
        [['Deep Purple', '256GB', 1350000, 15], ['Space Black', '256GB', 1350000, 12], ['Deep Purple', '512GB', 1550000, 6], ['Space Black', '512GB', 1550000, 4]]);
    $ids['p_jbl'] = seed_product($s1, $cat('electronics'), 'JBL Charge 5 Speaker', 'JBL', 85000, 95000,
        'Portable waterproof speaker with powerful JBL Original Pro Sound and 20 hours of playtime.', [['Black', null, 85000, 30], ['Blue', null, 85000, 18]]);
    $ids['p_jordan'] = seed_product($s1, $cat('fashion'), 'Nike Air Jordan 1 Retro High OG', 'Nike', 235000, 255000,
        'Classic high-top sneaker with premium leather and iconic colour blocking.',
        [['Black', '40', 235000, 12], ['Black', '41', 235000, 8], ['Black', '42', 235000, 3], ['Black', '43', 235000, 5], ['White', '40', 230000, 15], ['White', '42', 230000, 4]]);
    $ids['p_sony'] = seed_product($s1, $cat('electronics'), 'Sony WH-1000XM5 Headphones', 'Sony', 160000, 180000,
        'Industry-leading noise cancellation with up to 30 hours of battery life.', [['Black', null, 160000, 22], ['Silver', null, 160000, 9]]);
    $ids['p_lamp'] = seed_product($s1, $cat('home-living'), 'Smart LED Desk Lamp', 'Lumen', 25000, null,
        'Dimmable LED desk lamp with touch controls and USB charging port.', [['White', null, 25000, 40]]);
    $ids['p_mat'] = seed_product($s1, $cat('sports'), 'Premium Yoga Mat', 'Zen', 18000, 22000,
        'Non-slip 6mm yoga mat with carrying strap.', [['Green', null, 18000, 55], ['Blue', null, 18000, 40]], false);

    $ids['p_samsung'] = seed_product($s2, $cat('electronics'), 'Samsung Galaxy S23', 'Samsung', 950000, 1050000,
        'Flagship Galaxy with a 6.1-inch Dynamic AMOLED display and triple camera.', [['Phantom Black', '256GB', 950000, 11], ['Cream', '256GB', 950000, 7]]);
    $ids['p_hp'] = seed_product($s2, $cat('electronics'), 'HP Pavilion 15 Laptop', 'HP', 850000, null,
        '15.6-inch laptop, Intel Core i5, 16GB RAM, 512GB SSD.', [[null, '512GB', 850000, 7]]);
    $ids['p_watch'] = seed_product($s2, $cat('electronics'), 'Apple Watch Series 8', 'Apple', 400000, 440000,
        'Advanced health sensors, crash detection and an always-on Retina display.', [['Midnight', '45mm', 400000, 14], ['Starlight', '45mm', 400000, 10]]);
    $ids['p_canon'] = seed_product($s2, $cat('electronics'), 'Canon EOS Camera', 'Canon', 950000, null,
        'Mirrorless camera with 24.2MP sensor and 4K video.', [[null, null, 950000, 5]]);
    $ids['p_ps5'] = seed_product($s2, $cat('electronics'), 'PlayStation 5', 'Sony', 730000, 780000,
        'Next-gen console with ultra-high speed SSD and 4K gaming.', [['White', '825GB', 730000, 6]], false);
    $ids['p_serum'] = seed_product($s2, $cat('beauty'), 'Vitamin C Face Serum', 'Glow', 22000, null,
        'Brightening serum with 15% Vitamin C.', [[null, '30ml', 22000, 60]]);

    $ids['reseller']  = seed_user('Sarah Reseller', 'reseller@mthix.rw', ['reseller']);
    $ids['affiliate'] = seed_user('John Affiliate', 'affiliate@mthix.rw', ['affiliate']);
    $ids['delivery']  = seed_user('Jean Paul', 'delivery@mthix.rw', ['delivery']);
    $ids['buyer']     = seed_user('Jean Claude', 'buyer@mthix.rw', ['buyer'], 'none', '+250781123456');
    $ids['buyer2']    = seed_user('Aline Uwamahoro', 'aline@mthix.rw', ['buyer'], 'none', '+250785789123');
}

/** Build checkout lines straight from the DB (the same shape CartService::lines() returns). */
function seed_lines(array $pairs): array
{
    $prev = $_SESSION['cart'] ?? null;
    $_SESSION['cart'] = [];
    foreach ($pairs as $variantId => $qty) {
        $_SESSION['cart'][$variantId] = $qty;
    }
    $lines = CartService::lines();
    $_SESSION['cart'] = $prev ?? [];
    return $lines;
}

function seed_variant(int $productId, int $n = 0): int
{
    return (int)Db::val('SELECT id FROM variants WHERE product_id = ? ORDER BY id LIMIT 1 OFFSET ?', [$productId, $n]);
}

/** Places and pays an order through the real services, then advances it. Returns the order row. */
function seed_order(int $buyerId, array $pairs, string $stopAt, array $ref = [], string $method = 'mthix'): array
{
    $_COOKIE = [];
    if (!empty($ref['r'])) {
        $_COOKIE['mthix_r'] = $ref['r'];
    }
    if (!empty($ref['a'])) {
        $_COOKIE['mthix_a'] = $ref['a'];
    }
    $res = CheckoutService::place($buyerId, seed_lines($pairs), ['name' => 'Jean Claude', 'phone' => '+250781123456', 'address' => 'KG 123 St, Kigali, Nyarugenge'], $method);
    $order = $res['orders'][0];
    if ($stopAt === 'pending_payment') {
        return $order;
    }
    $pay = PaymentService::initiate([$order], 'mtn')[0];
    PaymentService::handleEvent($pay['external_ref'], 'success', $pay['amount'], ['seed' => true]);

    $steps = [
        'paid'             => null,
        'confirmed'        => ['confirmed', 'seller'],
        'packed'           => ['packed', 'seller'],
        'shipped'          => ['shipped', 'delivery'],
        'out_for_delivery' => ['out_for_delivery', 'delivery'],
        'delivered'        => ['delivered', 'delivery'],
        'completed'        => ['completed', 'buyer'],
    ];
    $storeUser = (int)Db::val('SELECT user_id FROM stores WHERE id = ?', [$order['store_id']]);
    $partner = (int)Db::val("SELECT u.id FROM users u JOIN user_roles r ON r.user_id = u.id AND r.role = 'delivery' LIMIT 1");
    foreach ($steps as $state => $step) {
        if ($step) {
            [$to, $kind] = $step;
            if ($to === 'shipped') {
                Db::run("UPDATE delivery_jobs SET partner_id = ?, status = 'accepted' WHERE order_id = ?", [$partner, $order['id']]);
            }
            $id = ['seller' => $storeUser, 'delivery' => $partner, 'buyer' => $buyerId][$kind];
            OSM::transition((int)$order['id'], $to, ['kind' => $kind, 'id' => $id]);
        }
        if ($state === $stopAt) {
            break;
        }
    }
    return Db::one('SELECT * FROM orders WHERE id = ?', [$order['id']]);
}

function seed_demo_orders(array $ids): void
{
    $rc = (string)Db::val('SELECT ref_code FROM users WHERE id = ?', [$ids['reseller']]);
    $ac = (string)Db::val('SELECT ref_code FROM users WHERE id = ?', [$ids['affiliate']]);
    $v = fn(string $k, int $n = 0) => seed_variant($ids[$k], $n);

    $plan = [
        [$ids['buyer'],  [$v('p_jordan', 0) => 1, $v('p_sony') => 1],     'completed', ['r' => $rc . ':' . $ids['p_sony'], 'a' => $ac], 14],
        [$ids['buyer2'], [$v('p_iphone', 1) => 1],                         'completed', ['a' => $ac], 12],
        [$ids['buyer'],  [$v('p_jbl') => 2],                               'completed', ['r' => $rc . ':' . $ids['p_jbl']], 9],
        [$ids['buyer2'], [$v('p_hp') => 1],                                'completed', [], 7],
        [$ids['buyer'],  [$v('p_watch') => 1],                             'delivered', [], 4],
        [$ids['buyer2'], [$v('p_samsung') => 1],                           'out_for_delivery', [], 3],
        [$ids['buyer'],  [$v('p_lamp') => 2, $v('p_mat') => 1],            'packed', [], 2],
        [$ids['buyer2'], [$v('p_serum') => 3],                             'confirmed', [], 1],
        [$ids['buyer'],  [$v('p_jordan', 4) => 1],                         'paid', [], 0],
        [$ids['buyer2'], [$v('p_canon') => 1],                             'pending_payment', [], 0],
    ];
    foreach ($plan as [$buyer, $pairs, $stop, $ref, $daysAgo]) {
        $o = seed_order($buyer, $pairs, $stop, $ref);
        if ($daysAgo > 0) {
            Db::run("UPDATE orders SET created_at = datetime('now', ?) WHERE id = ?", ["-$daysAgo days", $o['id']]);
        }
    }
    // One cancelled-after-payment order to exercise the refund path.
    $o = seed_order($ids['buyer'], [$v('p_serum') => 1], 'paid');
    OSM::transition((int)$o['id'], 'cancelled', ['kind' => 'seller', 'id' => $ids['seller2']], 'Out of stock at warehouse');

    // Earnings are available for completed orders: let the seller and reseller have a withdrawal history.
    Db::run("INSERT INTO reviews (product_id, user_id, order_id, rating, comment) SELECT ?, o.buyer_id, o.id, 5, 'Great product and fast delivery!' FROM orders o JOIN order_items i ON i.order_id = o.id WHERE i.product_id = ? AND o.status = 'completed' LIMIT 1", [$ids['p_sony'], $ids['p_sony']]);
}
