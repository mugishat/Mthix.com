<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Db;

final class ShopController extends Controller
{
    private const CARD_SQL = "SELECT p.id, p.name, p.slug, p.image, p.brand, p.base_price, p.compare_price, c.slug AS cat_slug, s.name AS store_name,
            (SELECT MIN(v.price) FROM variants v WHERE v.product_id = p.id AND v.active = 1) AS min_price,
            (SELECT COALESCE(SUM(v.stock),0) FROM variants v WHERE v.product_id = p.id AND v.active = 1) AS stock,
            (SELECT AVG(r.rating) FROM reviews r WHERE r.product_id = p.id) AS rating,
            (SELECT COUNT(*) FROM reviews r WHERE r.product_id = p.id) AS reviews
        FROM products p JOIN categories c ON c.id = p.category_id JOIN stores s ON s.id = p.store_id
        WHERE p.status = 'active' AND s.status = 'active'";

    public function home(): void
    {
        $stats = [
            'Products' => (int)Db::val("SELECT COUNT(*) FROM products WHERE status = 'active'"),
            'Sellers'  => (int)Db::val("SELECT COUNT(*) FROM stores WHERE status = 'active'"),
            'Orders'   => (int)Db::val("SELECT COUNT(*) FROM orders WHERE status NOT IN ('pending_payment','cancelled')"),
            'Resellers & affiliates' => (int)Db::val("SELECT COUNT(DISTINCT user_id) FROM user_roles WHERE role IN ('reseller','affiliate')"),
            'Members'  => (int)Db::val('SELECT COUNT(*) FROM users'),
        ];
        $new = Db::all(self::CARD_SQL . ' ORDER BY p.id DESC LIMIT 8');
        $top = Db::all(self::CARD_SQL . " ORDER BY (SELECT COALESCE(SUM(i.qty),0) FROM order_items i JOIN orders o ON o.id = i.order_id WHERE i.product_id = p.id AND o.status NOT IN ('pending_payment','cancelled')) DESC, p.id LIMIT 4");
        $cats = Db::all('SELECT * FROM categories ORDER BY sort');
        $this->page('shop/home', ['title' => 'Buy, sell, share and earn', 'stats' => $stats, 'new' => $new, 'top' => $top, 'cats' => $cats]);
    }

    public function category(array $p): void
    {
        $cat = Db::one('SELECT * FROM categories WHERE slug = ?', [$p['slug']]);
        if (!$cat) {
            abort(404);
        }
        $this->listing('c.id = ?', [$cat['id']], $cat['name'], $cat);
    }

    public function search(): void
    {
        $q = get('q');
        if ($q === '') {
            redirect('/');
        }
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $this->listing("(p.name LIKE ? ESCAPE '\\' OR p.brand LIKE ? ESCAPE '\\' OR p.description LIKE ? ESCAPE '\\')", [$like, $like, $like], 'Results for “' . $q . '”', null);
    }

    private function listing(string $where, array $params, string $heading, ?array $cat): void
    {
        $sort = get('sort', 'new');
        $order = ['new' => 'p.id DESC', 'low' => 'min_price ASC', 'high' => 'min_price DESC', 'rating' => 'rating DESC, reviews DESC'][$sort] ?? 'p.id DESC';
        $sql = self::CARD_SQL . " AND $where";
        if (get('min') !== '' && ctype_digit(get('min'))) {
            $sql .= ' AND min_price >= ' . (int)get('min');
        }
        if (get('max') !== '' && ctype_digit(get('max'))) {
            $sql .= ' AND min_price <= ' . (int)get('max');
        }
        $total = (int)Db::val('SELECT COUNT(*) FROM (' . $sql . ')', $params);
        $pg = paginate($total, (int)get('page', '1'), 12);
        $items = Db::all($sql . " ORDER BY $order LIMIT {$pg['per']} OFFSET {$pg['offset']}", $params);
        $this->page('shop/list', [
            'title' => $heading, 'heading' => $heading, 'cat' => $cat, 'items' => $items, 'pg' => $pg,
            'cats' => Db::all('SELECT * FROM categories ORDER BY sort'), 'sort' => $sort,
        ]);
    }

    public function product(array $p): void
    {
        $prod = Db::one(
            "SELECT p.*, c.name AS cat_name, c.slug AS cat_slug, s.name AS store_name, s.slug AS store_slug, s.user_id AS store_user, u.kyc_status
             FROM products p JOIN categories c ON c.id = p.category_id JOIN stores s ON s.id = p.store_id JOIN users u ON u.id = s.user_id
             WHERE p.slug = ? AND s.status = 'active'",
            [$p['slug']]
        );
        // Drafts and suspended products are only visible to their owner and staff.
        $isOwner = $prod && Auth::id() === (int)$prod['store_user'];
        if (!$prod || ($prod['status'] !== 'active' && !$isOwner && !Auth::isStaff())) {
            abort(404);
        }
        Db::run('UPDATE products SET views = views + 1 WHERE id = ?', [$prod['id']]);

        $variants = Db::all('SELECT id, color, size, price, compare_price, stock FROM variants WHERE product_id = ? AND active = 1 ORDER BY id', [$prod['id']]);
        $reviews = Db::all(
            'SELECT r.*, u.name FROM reviews r JOIN users u ON u.id = r.user_id WHERE r.product_id = ? ORDER BY r.id DESC LIMIT 20',
            [$prod['id']]
        );
        $rating = (float)Db::val('SELECT COALESCE(AVG(rating),0) FROM reviews WHERE product_id = ?', [$prod['id']]);
        $related = Db::all(self::CARD_SQL . ' AND c.id = ? AND p.id != ? ORDER BY p.id DESC LIMIT 4', [$prod['category_id'], $prod['id']]);
        $inWishlist = Auth::check() && Db::val('SELECT COUNT(*) FROM wishlist WHERE user_id = ? AND product_id = ?', [Auth::id(), $prod['id']]);

        $this->page('shop/product', [
            'title' => $prod['name'], 'metaDescription' => mb_substr((string)$prod['description'], 0, 150),
            'p' => $prod, 'variants' => $variants, 'reviews' => $reviews, 'rating' => $rating, 'related' => $related,
            'colors' => array_values(array_unique(array_filter(array_column($variants, 'color')))),
            'sizes' => array_values(array_unique(array_filter(array_column($variants, 'size')))),
            'inWishlist' => (bool)$inWishlist, 'me' => Auth::user(),
        ]);
    }

    public function wishlistToggle(array $p): void
    {
        $id = (int)$p['id'];
        if (!Db::val("SELECT COUNT(*) FROM products WHERE id = ? AND status = 'active'", [$id])) {
            abort(404);
        }
        if (Db::run('DELETE FROM wishlist WHERE user_id = ? AND product_id = ?', [$this->uid(), $id]) === 0) {
            Db::run('INSERT INTO wishlist (user_id, product_id) VALUES (?,?)', [$this->uid(), $id]);
            flash('success', 'Saved to your wishlist.');
        } else {
            flash('info', 'Removed from your wishlist.');
        }
        $this->back('/account/wishlist');
    }

    public function comingSoon(array $p): void
    {
        $names = ['jobs' => 'Jobs & freelancing', 'services' => 'Services marketplace', 'academy' => 'MTHIX Academy'];
        if (!isset($names[$p['what']])) {
            abort(404);
        }
        $this->page('shop/coming_soon', ['title' => $names[$p['what']], 'name' => $names[$p['what']]]);
    }
}
