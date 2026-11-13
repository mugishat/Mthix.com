<?php
use App\Core\Auth;
use App\Core\Db;

$u = Auth::user();
$cats = Db::all('SELECT name, slug FROM categories ORDER BY sort');
$dashLinks = [];
if ($u) {
    $map = ['seller' => ['Seller dashboard', '/seller'], 'reseller' => ['Reseller dashboard', '/reseller'], 'affiliate' => ['Affiliate dashboard', '/affiliate'],
            'delivery' => ['Delivery dashboard', '/delivery'], 'admin' => ['Admin', '/admin'], 'finance_admin' => ['Finance admin', '/admin'], 'support_admin' => ['Support admin', '/admin']];
    foreach ($map as $role => $l) {
        if (in_array($role, $u['roles'], true)) {
            $dashLinks[$l[1]] = $l[0];
        }
    }
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(($title ?? 'Marketplace') . ' · MTHIX') ?></title>
<meta name="description" content="<?= e($metaDescription ?? 'MTHIX: buy, sell, share and earn on Rwanda’s marketplace and opportunity platform.') ?>">
<link rel="stylesheet" href="<?= asset('app.css') ?>">
</head>
<body class="public">
<header class="site-top">
  <div class="container">
    <a class="logo" href="/" aria-label="MTHIX home"><b>M</b>THIX</a>
    <form class="site-search" action="/search" method="get" role="search">
      <input type="search" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Search products, brands and categories…" aria-label="Search">
      <button class="btn" type="submit">Search</button>
    </form>
    <div class="top-actions">
      <?php if ($u): ?>
        <a class="icon-link" href="/account/wishlist" title="Wishlist" aria-label="Wishlist">♡</a>
        <a class="icon-link" href="/notifications" title="Notifications" aria-label="Notifications">🔔<?php if ($n = unread_notifications()): ?><span class="count"><?= $n ?></span><?php endif; ?></a>
      <?php endif; ?>
      <a class="icon-link" href="/cart" title="Cart" aria-label="Cart">🛒<?php if ($c = cart_count()): ?><span class="count"><?= $c ?></span><?php endif; ?></a>
      <?php if ($u): ?>
        <details class="user-menu">
          <summary><span class="avatar"><?= e(strtoupper(substr($u['name'], 0, 1))) ?></span><span><?= e(explode(' ', $u['name'])[0]) ?></span></summary>
          <div class="menu">
            <a href="/account">My account</a>
            <a href="/account/orders">My orders</a>
            <a href="/wallet">Wallet</a>
            <?php foreach ($dashLinks as $href => $label): ?><a href="<?= e($href) ?>"><?= e($label) ?></a><?php endforeach; ?>
            <form method="post" action="/logout"><?= csrf_field() ?><button type="submit">Sign out</button></form>
          </div>
        </details>
      <?php else: ?>
        <a class="btn btn-light btn-sm" href="/login">Login</a>
        <a class="btn btn-sm" href="/register">Register</a>
      <?php endif; ?>
    </div>
  </div>
</header>
<nav class="site-nav" aria-label="Categories">
  <div class="container">
    <?php foreach (array_slice($cats, 0, 6) as $c): ?><a href="/category/<?= e($c['slug']) ?>"><?= e($c['name']) ?></a><?php endforeach; ?>
    <a href="/coming-soon/jobs">Jobs</a><a href="/coming-soon/services">Services</a><a href="/coming-soon/academy">Academy</a>
    <a href="/register?role=seller" class="grow right">Become a seller</a>
  </div>
</nav>
<main class="page">
  <div class="container">
    <?php foreach (flashes() as $f): ?><div class="flash <?= e($f['type']) ?>" role="status"><?= e($f['msg']) ?></div><?php endforeach; ?>
    <?= $content ?>
  </div>
</main>
<footer class="footer">
  <div class="container row spread">
    <div><a class="logo" href="/"><b>M</b>THIX</a><div class="small">Build. Trade. Grow Together. · Kigali, Rwanda</div></div>
    <div class="small">Pay with MTN MoMo, Airtel Money or card · Escrow-protected orders · © <?= date('Y') ?> MTHIX Group Ltd</div>
  </div>
</footer>
<script src="<?= asset('app.js') ?>" defer></script>
</body>
</html>
