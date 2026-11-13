<?php
use App\Core\Auth;
use App\Core\Db;

$u = Auth::user();
$titles = ['seller' => 'Seller', 'reseller' => 'Reseller', 'affiliate' => 'Affiliate', 'delivery' => 'Delivery partner', 'admin' => 'Admin', 'buyer' => 'Buyer'];
$store = $dashRole === 'seller' ? Db::one('SELECT * FROM stores WHERE user_id = ?', [$u['id']]) : null;
$map = ['seller' => ['Seller', '/seller'], 'reseller' => ['Reseller', '/reseller'], 'affiliate' => ['Affiliate', '/affiliate'], 'delivery' => ['Delivery', '/delivery'], 'admin' => ['Admin', '/admin']];
$switch = [];
foreach ($map as $role => [$label, $href]) {
    $has = $role === 'admin' ? Auth::isStaff() : in_array($role, $u['roles'], true);
    if ($has && $role !== $dashRole) {
        $switch[$href] = $label . ' dashboard';
    }
}
$menu = dash_menu($dashRole);
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(($title ?? 'Dashboard') . ' · MTHIX ' . ($titles[$dashRole] ?? '')) ?></title>
<link rel="stylesheet" href="<?= asset('app.css') ?>">
</head>
<body class="dash">
<aside class="side" aria-label="Main menu">
  <a class="logo" href="/"><b>M</b>THIX</a>
  <div class="who">
    <span class="avatar"><?= e(strtoupper(substr($store['name'] ?? $u['name'], 0, 1))) ?></span>
    <div><b><?= e($store['name'] ?? $u['name']) ?></b>
      <span><?= $u['kyc_status'] === 'verified' ? '✔ Verified ' : '' ?><?= e($titles[$dashRole] ?? '') ?></span></div>
  </div>
  <nav>
    <?php foreach ($menu as [$label, $href, $ic, $badge]): ?>
      <a href="<?= e($href) ?>" class="<?= dash_active($href) ? 'on' : '' ?>"><span class="ic"><?= icon($ic) ?></span><?= e($label) ?><?php if ($badge): ?><span class="bd"><?= (int)$badge ?></span><?php endif; ?></a>
    <?php endforeach; ?>
  </nav>
  <div class="switch">
    <a href="/">← Back to marketplace</a>
    <a href="/account">My buyer account</a>
    <?php foreach ($switch as $href => $label): ?><a href="<?= e($href) ?>"><?= e($label) ?></a><?php endforeach; ?>
  </div>
</aside>
<div class="dmain">
  <header class="dtop">
    <button class="btn btn-light btn-sm menu-btn" type="button" aria-label="Open menu">☰</button>
    <form action="/search" method="get" role="search"><input type="search" name="q" placeholder="Search the marketplace…" aria-label="Search"></form>
    <div class="grow"></div>
    <a class="icon-link" href="/notifications" aria-label="Notifications">🔔<?php if ($n = unread_notifications()): ?><span class="count"><?= $n ?></span><?php endif; ?></a>
    <a class="icon-link" href="/messages" aria-label="Messages">✉</a>
    <form method="post" action="/logout" class="inline" style="flex:none"><?= csrf_field() ?><button class="btn btn-light btn-sm" type="submit">Sign out</button></form>
  </header>
  <div class="dbody">
    <?php foreach (flashes() as $f): ?><div class="flash <?= e($f['type']) ?>" role="status"><?= e($f['msg']) ?></div><?php endforeach; ?>
    <?= $content ?>
  </div>
</div>
<script src="<?= asset('app.js') ?>" defer></script>
</body>
</html>
