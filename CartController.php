<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\CartService;

final class CartController extends Controller
{
    public function show(): void
    {
        $lines = CartService::lines();
        $this->page('shop/cart', ['title' => 'Shopping cart', 'lines' => $lines, 'subtotal' => CartService::subtotal($lines)]);
    }

    public function add(): void
    {
        CartService::add((int)post('variant_id'), max(1, (int)post('qty', '1')));
        if (post('buy_now') === '1') {
            redirect('/checkout');
        }
        flash('success', 'Added to your cart.');
        redirect('/cart');
    }

    public function update(): void
    {
        CartService::set((int)post('variant_id'), (int)post('qty'));
        redirect('/cart');
    }

    public function remove(): void
    {
        CartService::set((int)post('variant_id'), 0);
        redirect('/cart');
    }

    public function clear(): void
    {
        CartService::clear();
        redirect('/cart');
    }
}
