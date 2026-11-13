<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Db;
use App\Core\View;
use App\Services\CommissionEngine;
use App\Services\DomainException;
use App\Services\Inventory;
use App\Services\Ledger;
use App\Services\OrderStateMachine;

/** Seller workspace: dashboard, catalogue, variants, orders, store settings. Every query is scoped to the signed-in seller's store. */
final class SellerController extends Controller
{
    private const COLOR_CODES = [
        'black' => 'BLK', 'white' => 'WHT', 'red' => 'RED', 'blue' => 'BLU', 'green' => 'GRN', 'yellow' => 'YEL', 'grey' => 'GRY',
        'gray' => 'GRY', 'brown' => 'BRN', 'silver' => 'SLV', 'gold' => 'GLD', 'pink' => 'PNK', 'orange' => 'ORG', 'purple' => 'PRP',
    ];
    private const LIVE = "('pending_payment','cancelled','refunded')";

    private function seller(string $view, array $data = []): void
    {
        $this->dash('seller', 'seller/' . $view, $data + ['store' => $this->storeOrFail()]);
    }

    private function ownProduct(int $id): array
    {
        $p = Db::one('SELECT p.*, c.slug AS cat_slug FROM products p JOIN categories c ON c.id = p.category_id WHERE p.id = ? AND p.store_id = ?', [$id, (int)$this->storeOrFail()['id']]);
        if (!$p) {
            abort(404);
        }
        return $p;
    }

    // ------------------------------------------------------------------ dashboard

    public function dashboard(): void
    {
        $store = $this->storeOrFail();
        $sid = (int)$store['id'];
        $live = self::LIVE;
        $month = date('Y-m-01');
        $prev = date('Y-m-01', strtotime('first day of last month'));

        $cur = Db::one("SELECT COALESCE(SUM(subtotal),0) AS sales, COUNT(*) AS n FROM orders WHERE store_id = ? AND status NOT IN $live AND created_at >= ?", [$sid, $month]);
        $old = Db::one("SELECT COALESCE(SUM(subtotal),0) AS sales, COUNT(*) AS n FROM orders WHERE store_id = ? AND status NOT IN $live AND created_at >= ? AND created_at < ?", [$sid, $prev, $month]);
        $delta = fn(int $a, int $b): ?float => $b > 0 ? round(100 * ($a - $b) / $b, 1) : null;

        $kpi = [
            'sales' => (int)$cur['sales'], 'sales_d' => $delta((int)$cur['sales'], (int)$old['sales']),
            'orders' => (int)$cur['n'], 'orders_d' => $delta((int)$cur['n'], (int)$old['n']),
            'products' => (int)Db::val("SELECT COUNT(*) FROM products WHERE store_id = ? AND status = 'active'", [$sid]),
            'views' => (int)Db::val('SELECT COALESCE(SUM(views),0) FROM products WHERE store_id = ?', [$sid]),
            'earned' => (int)Db::val("SELECT COALESCE(SUM(amount),0) FROM commissions WHERE user_id = ? AND party = 'seller' AND status != 'reversed'", [$this->uid()]),
        ];

        // sales per day, last 30 days
        $rows = Db::all("SELECT date(created_at) AS d, SUM(subtotal) AS s FROM orders WHERE store_id = ? AND status NOT IN $live AND created_at >= date('now','-29 days') GROUP BY d", [$sid]);
        $by = array_column($rows, 's', 'd');
        $labels = [];
        $vals = [];
        for ($i = 29; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-$i days"));
            $labels[] = date('j M', strtotime($day));
            $vals[] = (int)($by[$day] ?? 0);
        }

        $top = Db::all(
            "SELECT i.product_id, i.product_name, SUM(i.qty) AS sold, SUM(i.line_total) AS revenue FROM order_items i JOIN orders o ON o.id = i.order_id
             WHERE o.store_id = ? AND o.status NOT IN $live GROUP BY i.product_id ORDER BY sold DESC LIMIT 5",
            [$sid]
        );
        $counts = array_column(Db::all('SELECT status, COUNT(*) AS n FROM orders WHERE store_id = ? GROUP BY status', [$sid]), 'n', 'status');
        $g = fn(string ...$s) => array_sum(array_map(fn($x) => (int)($counts[$x] ?? 0), $s));
        $donut = ['Delivered' => $g('delivered', 'completed'), 'In transit' => $g('shipped', 'out_for_delivery'), 'Processing' => $g('paid', 'confirmed', 'packed'), 'Cancelled' => $g('cancelled', 'refunded')];

        $low = Db::all(
            "SELECT v.id, v.sku, v.color, v.size, v.stock, v.low_stock_alert, p.id AS pid, p.name FROM variants v JOIN products p ON p.id = v.product_id
             WHERE p.store_id = ? AND v.active = 1 AND p.status != 'archived' AND v.stock <= v.low_stock_alert ORDER BY v.stock, p.name LIMIT 8",
            [$sid]
        );
        $recent = Db::all("SELECT * FROM orders WHERE store_id = ? AND status != 'pending_payment' ORDER BY id DESC LIMIT 6", [$sid]);

        $this->seller('dashboard', [
            'title' => 'Seller dashboard', 'kpi' => $kpi, 'labels' => $labels, 'vals' => $vals, 'top' => $top, 'donut' => $donut,
            'low' => $low, 'recent' => $recent, 'bal' => Ledger::balance($this->uid()),
            'todo' => $g('paid', 'confirmed'), 'me' => \App\Core\Auth::user(),
        ]);
    }

    // ------------------------------------------------------------------ products

    public function products(): void
    {
        $sid = (int)$this->storeOrFail()['id'];
        $status = get('status');
        $q = get('q');
        $where = 'p.store_id = ?';
        $params = [$sid];
        if (in_array($status, ['active', 'draft', 'suspended', 'archived'], true)) {
            $where .= ' AND p.status = ?';
            $params[] = $status;
        }
        if ($q !== '') {
            $where .= " AND (p.name LIKE ? ESCAPE '\\' OR p.brand LIKE ? ESCAPE '\\')";
            $like = '%' . addcslashes($q, '%_\\') . '%';
            array_push($params, $like, $like);
        }
        $total = (int)Db::val("SELECT COUNT(*) FROM products p WHERE $where", $params);
        $pg = paginate($total, (int)get('page', '1'), 15);
        $items = Db::all(
            "SELECT p.*, c.name AS cat_name, c.slug AS cat_slug,
                (SELECT COUNT(*) FROM variants v WHERE v.product_id = p.id) AS variants,
                (SELECT COALESCE(SUM(v.stock),0) FROM variants v WHERE v.product_id = p.id AND v.active = 1) AS stock,
                (SELECT MIN(v.price) FROM variants v WHERE v.product_id = p.id AND v.active = 1) AS min_price,
                (SELECT MAX(v.price) FROM variants v WHERE v.product_id = p.id AND v.active = 1) AS max_price,
                (SELECT COALESCE(SUM(i.qty),0) FROM order_items i JOIN orders o ON o.id = i.order_id WHERE i.product_id = p.id AND o.status NOT IN " . self::LIVE . ") AS sold
             FROM products p JOIN categories c ON c.id = p.category_id WHERE $where ORDER BY p.id DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}",
            $params
        );
        $counts = array_column(Db::all('SELECT status, COUNT(*) AS n FROM products WHERE store_id = ? GROUP BY status', [$sid]), 'n', 'status');
        $this->seller('products', ['title' => 'Products', 'items' => $items, 'pg' => $pg, 'status' => $status, 'q' => $q, 'counts' => $counts]);
    }

    public function productForm(): void
    {
        $price = (int)old('base_price', '0');
        $this->seller('product_form', [
            'title' => 'Add product', 'p' => null, 'cats' => Db::all('SELECT * FROM categories ORDER BY sort'),
            'econ' => $this->economics($price, (int)old('cost_price', '0')), 'rule' => CommissionEngine::activeRule(),
        ]);
    }

    public function productCreate(): void
    {
        $store = $this->storeOrFail();
        [$d, $errors] = $this->readProduct();
        $stock = max(0, min(100000, (int)post('stock', '0')));
        if (!$errors) {
            try {
                $image = $this->upload();
            } catch (DomainException $e) {
                $errors[] = $e->getMessage();
            }
        }
        if ($errors) {
            keep_old($_POST);
            flash('error', implode(' ', $errors));
            redirect('/seller/products/new');
        }
        $id = Db::tx(function () use ($store, $d, $image, $stock): int {
            $slug = slugify($d['name']);
            while (Db::val('SELECT COUNT(*) FROM products WHERE slug = ?', [$slug])) {
                $slug .= '-' . random_int(10, 99);
            }
            $id = Db::insert(
                'INSERT INTO products (store_id, category_id, name, slug, description, brand, base_price, compare_price, cost_price, image, status, reseller_eligible)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                [$store['id'], $d['category_id'], $d['name'], $slug, $d['description'], $d['brand'], $d['base_price'], $d['compare_price'], $d['cost_price'], $image, 'draft', $d['reseller_eligible']]
            );
            $this->createVariant($id, $this->defaultPrefix($d['name']), null, null, $d['base_price'], $d['compare_price'], $d['cost_price'], $stock);
            if ($d['publish']) {
                Db::run("UPDATE products SET status = 'active' WHERE id = ?", [$id]);
            }
            Db::run("INSERT INTO inventory_movements (variant_id, delta, reason, actor_id) SELECT id, stock, 'initial', ? FROM variants WHERE product_id = ? AND stock > 0", [$this->uid(), $id]);
            return $id;
        });
        clear_old();
        flash('success', $d['publish'] ? 'Product published.' : 'Product saved as a draft.');
        redirect("/seller/products/$id/variants");
    }

    public function productEdit(array $p): void
    {
        $prod = $this->ownProduct((int)$p['id']);
        $this->seller('product_form', [
            'title' => 'Edit ' . $prod['name'], 'p' => $prod, 'cats' => Db::all('SELECT * FROM categories ORDER BY sort'),
            'econ' => $this->economics((int)$prod['base_price'], (int)$prod['cost_price']), 'rule' => CommissionEngine::activeRule(),
        ]);
    }

    public function productUpdate(array $p): void
    {
        $prod = $this->ownProduct((int)$p['id']);
        if ($prod['status'] === 'suspended') {
            throw new DomainException('This product was suspended by MTHIX support and cannot be edited.');
        }
        [$d, $errors] = $this->readProduct();
        $image = null;
        if (!$errors) {
            try {
                $image = $this->upload();
            } catch (DomainException $e) {
                $errors[] = $e->getMessage();
            }
        }
        if ($errors) {
            keep_old($_POST);
            flash('error', implode(' ', $errors));
            redirect("/seller/products/{$prod['id']}/edit");
        }
        Db::run(
            'UPDATE products SET category_id = ?, name = ?, description = ?, brand = ?, base_price = ?, compare_price = ?, cost_price = ?, reseller_eligible = ?, image = COALESCE(?, image) WHERE id = ?',
            [$d['category_id'], $d['name'], $d['description'], $d['brand'], $d['base_price'], $d['compare_price'], $d['cost_price'], $d['reseller_eligible'], $image, $prod['id']]
        );
        if ($image && $prod['image']) {
            @unlink(ROOT . '/public/uploads/' . basename((string)$prod['image']));
        }
        clear_old();
        flash('success', 'Product updated. Variant prices are edited in the Variant Builder.');
        redirect("/seller/products/{$prod['id']}/edit");
    }

    public function productStatus(array $p): void
    {
        $prod = $this->ownProduct((int)$p['id']);
        $to = post('status');
        if (!in_array($to, ['active', 'draft', 'archived'], true)) {
            throw new DomainException('Choose a valid status.');
        }
        if ($prod['status'] === 'suspended') {
            throw new DomainException('This product was suspended by MTHIX support. Contact support to have it reviewed.');
        }
        if ($to === 'active' && !(int)Db::val('SELECT COUNT(*) FROM variants WHERE product_id = ? AND active = 1', [$prod['id']])) {
            throw new DomainException('Add at least one active variant before publishing.');
        }
        Db::run('UPDATE products SET status = ? WHERE id = ?', [$to, $prod['id']]);
        flash('success', '“' . $prod['name'] . '” is now ' . status_label($to) . '.');
        $this->back('/seller/products');
    }

    // ------------------------------------------------------------------ variants

    public function variants(array $p): void
    {
        $prod = $this->ownProduct((int)$p['id']);
        $vars = Db::all('SELECT * FROM variants WHERE product_id = ? ORDER BY id', [$prod['id']]);
        $act = array_filter($vars, fn($v) => (int)$v['active'] === 1);
        $prices = array_column($act, 'price');
        $sum = [
            'total' => count($vars), 'stock' => array_sum(array_column($act, 'stock')),
            'in' => count(array_filter($act, fn($v) => $v['stock'] > $v['low_stock_alert'])),
            'low' => count(array_filter($act, fn($v) => $v['stock'] > 0 && $v['stock'] <= $v['low_stock_alert'])),
            'out' => count(array_filter($act, fn($v) => (int)$v['stock'] === 0)),
            'min' => $prices ? min($prices) : 0, 'max' => $prices ? max($prices) : 0, 'avg' => $prices ? (int)round(array_sum($prices) / count($prices)) : 0,
            'value' => array_sum(array_map(fn($v) => (int)$v['cost_price'] * (int)$v['stock'], $act)),
        ];
        $this->seller('variants', [
            'title' => 'Variant Builder', 'p' => $prod, 'vars' => $vars, 'sum' => $sum,
            'colors' => array_values(array_unique(array_filter(array_column($vars, 'color')))),
            'sizes' => array_values(array_unique(array_filter(array_column($vars, 'size')))),
            'prefix' => $vars ? preg_replace('/-.*/', '', (string)$vars[0]['sku']) : $this->defaultPrefix($prod['name']),
            'econ' => $this->economics($sum['min'] ?: (int)$prod['base_price'], (int)$prod['cost_price']),
        ]);
    }

    public function variantsGenerate(array $p): void
    {
        $prod = $this->ownProduct((int)$p['id']);
        $split = fn(string $s) => array_slice(array_values(array_unique(array_filter(array_map('trim', explode(',', $s)), fn($x) => $x !== '' && mb_strlen($x) <= 20))), 0, 12);
        $colors = $split(post('colors')) ?: [null];
        $sizes = $split(post('sizes')) ?: [null];
        if ($colors === [null] && $sizes === [null]) {
            throw new DomainException('Enter at least one colour or size.');
        }
        $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', post('prefix')) ?? '');
        $prefix = substr($prefix !== '' ? $prefix : $this->defaultPrefix($prod['name']), 0, 8);
        $stock = max(0, min(100000, (int)post('stock', '0')));

        $made = Db::tx(function () use ($prod, $colors, $sizes, $prefix, $stock): int {
            $n = 0;
            $total = (int)Db::val('SELECT COUNT(*) FROM variants WHERE product_id = ?', [$prod['id']]);
            foreach ($colors as $c) {
                foreach ($sizes as $s) {
                    $exists = Db::val('SELECT COUNT(*) FROM variants WHERE product_id = ? AND COALESCE(color,"") = ? AND COALESCE(size,"") = ?', [$prod['id'], (string)$c, (string)$s]);
                    if ($exists) {
                        continue;
                    }
                    if ($total + $n >= 100) {
                        throw new DomainException('A product can have at most 100 variants.');
                    }
                    $vid = $this->createVariant((int)$prod['id'], $prefix, $c, $s, (int)$prod['base_price'], $prod['compare_price'] !== null ? (int)$prod['compare_price'] : null, (int)$prod['cost_price'], $stock);
                    if ($stock > 0) {
                        Db::run("INSERT INTO inventory_movements (variant_id, delta, reason, actor_id) VALUES (?,?,'initial',?)", [$vid, $stock, $this->uid()]);
                    }
                    $n++;
                }
            }
            return $n;
        });
        // The automatic first variant of a new product is replaced once real options exist and it was never sold.
        $blank = Db::one('SELECT v.id FROM variants v WHERE v.product_id = ? AND v.color IS NULL AND v.size IS NULL', [$prod['id']]);
        if ($made > 0 && $blank && !Db::val('SELECT COUNT(*) FROM order_items WHERE variant_id = ?', [$blank['id']])) {
            Db::run('UPDATE variants SET active = 0 WHERE id = ?', [$blank['id']]);
        }
        flash($made ? 'success' : 'info', $made ? "$made variant(s) created with SKUs and barcodes." : 'Those combinations already exist.');
        redirect("/seller/products/{$prod['id']}/variants");
    }

    public function variantsSave(array $p): void
    {
        $prod = $this->ownProduct((int)$p['id']);
        $rows = $_POST['v'] ?? [];
        if (!is_array($rows) || !$rows) {
            throw new DomainException('Nothing to save.');
        }
        $uid = $this->uid();
        Db::tx(function () use ($prod, $rows, $uid): void {
            foreach ($rows as $id => $f) {
                $v = Db::one('SELECT * FROM variants WHERE id = ? AND product_id = ?', [(int)$id, $prod['id']]);
                if (!$v || !is_array($f)) {
                    continue;
                }
                $sku = trim((string)($f['sku'] ?? ''));
                if (!preg_match('/^[A-Za-z0-9._-]{3,40}$/', $sku)) {
                    throw new DomainException("SKU “{$sku}” is not valid (3–40 letters, numbers, dot, dash).");
                }
                if (Db::val('SELECT COUNT(*) FROM variants WHERE sku = ? AND id != ?', [$sku, $v['id']])) {
                    throw new DomainException("SKU {$sku} is already used by another variant.");
                }
                $num = fn(string $k): int => max(0, (int)preg_replace('/[^0-9]/', '', (string)($f[$k] ?? '0')));
                $compare = $num('compare_price');
                Db::run(
                    'UPDATE variants SET sku = ?, price = ?, compare_price = ?, cost_price = ?, low_stock_alert = ?, active = ? WHERE id = ?',
                    [$sku, $num('price'), $compare > 0 ? $compare : null, $num('cost_price'), $num('low_stock_alert'), !empty($f['active']) ? 1 : 0, $v['id']]
                );
                Inventory::adjust((int)$v['id'], min(100000, $num('stock')), 'adjustment', $uid);
            }
        });
        flash('success', 'Variants saved.');
        redirect("/seller/products/{$prod['id']}/variants");
    }

    // ------------------------------------------------------------------ orders

    public function orders(): void
    {
        $sid = (int)$this->storeOrFail()['id'];
        $tabs = [
            'all' => ['All', null], 'new' => ['To confirm', ['paid']], 'confirmed' => ['To pack', ['confirmed']], 'packed' => ['Packed', ['packed']],
            'transit' => ['In transit', ['shipped', 'out_for_delivery']], 'done' => ['Delivered', ['delivered', 'completed']], 'closed' => ['Cancelled / refunded', ['cancelled', 'refunded']],
        ];
        $tab = isset($tabs[get('tab')]) ? get('tab') : 'all';
        $where = 'o.store_id = ?';
        $params = [$sid];
        if ($tabs[$tab][1] === null) {
            $where .= " AND o.status != 'pending_payment'";
        } else {
            $where .= ' AND o.status IN (' . implode(',', array_fill(0, count($tabs[$tab][1]), '?')) . ')';
            array_push($params, ...$tabs[$tab][1]);
        }
        $q = get('q');
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where .= " AND (o.code LIKE ? ESCAPE '\\' OR o.ship_name LIKE ? ESCAPE '\\' OR o.ship_phone LIKE ? ESCAPE '\\')";
            array_push($params, $like, $like, $like);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', get('from'))) {
            $where .= ' AND date(o.created_at) >= ?';
            $params[] = get('from');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', get('to'))) {
            $where .= ' AND date(o.created_at) <= ?';
            $params[] = get('to');
        }
        $total = (int)Db::val("SELECT COUNT(*) FROM orders o WHERE $where", $params);
        $pg = paginate($total, (int)get('page', '1'), 15);
        $rows = Db::all(
            "SELECT o.*, (SELECT COUNT(*) FROM order_items i WHERE i.order_id = o.id) AS items,
                    (SELECT SUM(qty) FROM order_items i WHERE i.order_id = o.id) AS units FROM orders o WHERE $where ORDER BY o.id DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}",
            $params
        );
        $cnt = array_column(Db::all('SELECT status, COUNT(*) AS n FROM orders WHERE store_id = ? GROUP BY status', [$sid]), 'n', 'status');
        $badge = [];
        foreach ($tabs as $k => [, $st]) {
            $badge[$k] = $st === null ? array_sum($cnt) - (int)($cnt['pending_payment'] ?? 0) : array_sum(array_map(fn($s) => (int)($cnt[$s] ?? 0), $st));
        }
        $this->seller('orders', ['title' => 'Orders', 'rows' => $rows, 'pg' => $pg, 'tabs' => $tabs, 'tab' => $tab, 'badge' => $badge, 'q' => $q]);
    }

    public function order(array $p): void
    {
        $o = $this->ownOrder((string)$p['code']);
        $items = Db::all('SELECT i.*, v.sku FROM order_items i LEFT JOIN variants v ON v.id = i.variant_id WHERE i.order_id = ?', [$o['id']]);
        $events = Db::all('SELECT * FROM order_events WHERE order_id = ? ORDER BY id', [$o['id']]);
        $comm = Db::all('SELECT * FROM commissions WHERE order_id = ? ORDER BY id', [$o['id']]);
        $next = OrderStateMachine::allowedNext($o['status'], 'seller');
        $paid = Db::one("SELECT * FROM payments WHERE order_id = ? AND status = 'success' LIMIT 1", [$o['id']]);
        $this->seller('order', [
            'title' => 'Order ' . $o['code'], 'o' => $o, 'items' => $items, 'events' => $events, 'comm' => $comm, 'next' => $next, 'pay' => $paid,
            'job' => Db::one('SELECT j.*, u.name AS partner FROM delivery_jobs j LEFT JOIN users u ON u.id = j.partner_id WHERE j.order_id = ?', [$o['id']]),
        ]);
    }

    public function orderAction(array $p): void
    {
        $o = $this->ownOrder((string)$p['code']);
        $to = ['confirm' => 'confirmed', 'pack' => 'packed', 'cancel' => 'cancelled'][$p['action']] ?? null;
        if ($to === null) {
            abort(404);
        }
        $reason = post('reason');
        if ($to === 'cancelled' && mb_strlen($reason) < 3) {
            throw new DomainException('Give a short reason for cancelling; the buyer will see it.');
        }
        OrderStateMachine::transition((int)$o['id'], $to, ['kind' => 'seller', 'id' => $this->uid()], $reason !== '' ? mb_substr($reason, 0, 200) : null);
        flash('success', 'Order ' . $o['code'] . ' is now ' . status_label($to) . '.');
        redirect('/seller/orders/' . $o['code']);
    }

    private function ownOrder(string $code): array
    {
        $o = Db::one('SELECT * FROM orders WHERE code = ? AND store_id = ? AND status != ?', [$code, (int)$this->storeOrFail()['id'], 'pending_payment']);
        if (!$o) {
            abort(404);
        }
        return $o;
    }

    // ------------------------------------------------------------------ store settings

    public function store(): void
    {
        $this->seller('store', ['title' => 'Store settings', 'me' => \App\Core\Auth::user()]);
    }

    public function storeSave(): void
    {
        $store = $this->storeOrFail();
        $name = post('name');
        $desc = post('description');
        if (mb_strlen($name) < 3 || mb_strlen($name) > 60) {
            throw new DomainException('Store name must be 3–60 characters.');
        }
        if (mb_strlen($desc) > 500) {
            throw new DomainException('Keep the description under 500 characters.');
        }
        Db::run('UPDATE stores SET name = ?, description = ? WHERE id = ?', [$name, $desc !== '' ? $desc : null, $store['id']]);
        Audit::log($this->uid(), 'store.update', 'store', (int)$store['id']);
        flash('success', 'Store settings saved.');
        redirect('/seller/store');
    }

    // ------------------------------------------------------------------ helpers

    /** Per-unit money picture for a price: what the seller keeps in each selling scenario. */
    private function economics(int $price, int $cost): array
    {
        $rule = CommissionEngine::activeRule();
        $rows = [];
        foreach ([['Direct sale', false, false], ['Via a reseller', true, false], ['Via an affiliate', false, true], ['Reseller + affiliate', true, true]] as [$label, $r, $a]) {
            $s = CommissionEngine::split(max(0, $price), $r, $a, $cost, $rule);
            $rows[] = [
                'label' => $label, 'r' => $r, 'a' => $a, 'seller' => $s['seller']['amount'], 'platform' => $s['platform']['amount'],
                'reseller' => $s['reseller']['amount'], 'affiliate' => $s['affiliate']['amount'], 'profit' => $s['seller']['amount'] - $cost, 'low' => $s['low_profit'],
            ];
        }
        return $rows;
    }

    /** @return array{0:array,1:string[]} cleaned product fields and validation errors */
    private function readProduct(): array
    {
        $int = fn(string $k): int => (int)preg_replace('/[^0-9]/', '', post($k));
        $d = [
            'name' => post('name'), 'category_id' => (int)post('category_id'), 'brand' => mb_substr(post('brand'), 0, 60) ?: null,
            'description' => mb_substr(post('description'), 0, 5000) ?: null, 'base_price' => $int('base_price'),
            'compare_price' => $int('compare_price') ?: null, 'cost_price' => $int('cost_price'),
            'reseller_eligible' => post('reseller_eligible') === '1' ? 1 : 0, 'publish' => post('publish') === '1',
        ];
        $e = [];
        if (mb_strlen($d['name']) < 3 || mb_strlen($d['name']) > 120) {
            $e[] = 'Product name must be 3–120 characters.';
        }
        if (!Db::val('SELECT COUNT(*) FROM categories WHERE id = ?', [$d['category_id']])) {
            $e[] = 'Choose a category.';
        }
        if ($d['base_price'] < 100) {
            $e[] = 'Enter a selling price of at least RWF 100.';
        }
        if ($d['compare_price'] !== null && $d['compare_price'] <= $d['base_price']) {
            $e[] = 'The “compare at” price must be higher than the selling price.';
        }
        return [$d, $e];
    }

    /** Saves an uploaded product photo (jpeg/png/webp, max 2 MB) and returns its file name, or null if none was sent. */
    private function upload(): ?string
    {
        $f = $_FILES['image'] ?? null;
        if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            throw new DomainException('The photo could not be uploaded. Try a smaller file.');
        }
        if ($f['size'] > 2 * 1024 * 1024) {
            throw new DomainException('The photo is larger than 2 MB.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
        if ($ext === null || !@getimagesize($f['tmp_name'])) {
            throw new DomainException('Upload a JPG, PNG or WebP photo.');
        }
        $name = bin2hex(random_bytes(10)) . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], ROOT . '/public/uploads/' . $name)) {
            throw new DomainException('The photo could not be saved.');
        }
        return $name;
    }

    private function defaultPrefix(string $name): string
    {
        $p = '';
        foreach (preg_split('/\s+/', $name) ?: [] as $w) {
            $p .= strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $w) ?? '', 0, 1));
        }
        return substr($p !== '' ? $p : 'SKU', 0, 5);
    }

    private function colorCode(string $color): string
    {
        $k = strtolower(trim($color));
        return self::COLOR_CODES[$k] ?? strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $color) ?? '', 0, 3));
    }

    /** Inserts a variant with an auto SKU (PREFIX-COL-SIZE-NNN) and an EAN-13 style barcode. @return int variant id */
    private function createVariant(int $productId, string $prefix, ?string $color, ?string $size, int $price, ?int $compare, int $cost, int $stock): int
    {
        $parts = [$prefix];
        if ($color !== null && $color !== '') {
            $parts[] = $this->colorCode($color);
        }
        if ($size !== null && $size !== '') {
            $parts[] = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $size) ?? '');
        }
        $base = implode('-', $parts);
        $n = (int)Db::val('SELECT COUNT(*) FROM variants WHERE product_id = ?', [$productId]) + 1;
        do {
            $sku = sprintf('%s-%03d', $base, $n++);
        } while (Db::val('SELECT COUNT(*) FROM variants WHERE sku = ?', [$sku]));

        $id = Db::insert(
            'INSERT INTO variants (product_id, sku, color, size, price, compare_price, cost_price, stock) VALUES (?,?,?,?,?,?,?,?)',
            [$productId, $sku, $color, $size, $price, $compare, $cost, $stock]
        );
        $digits = '98' . str_pad((string)$id, 10, '0', STR_PAD_LEFT);
        $sum = 0;
        foreach (str_split($digits) as $i => $dgt) {
            $sum += (int)$dgt * ($i % 2 === 0 ? 1 : 3);
        }
        Db::run('UPDATE variants SET barcode = ? WHERE id = ?', [$digits . ((10 - $sum % 10) % 10), $id]);
        return $id;
    }
}
