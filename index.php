<?php use App\Core\Auth; ?>
<div class="page-head"><div><h1>Welcome back, <?= e(explode(' ', Auth::user()['name'] ?? '')[0] ?: 'friend') ?> 👋</h1><div class="muted">Discover, shop and enjoy the best on MTHIX.</div></div></div>
<div class="grid g4">
  <?php foreach ($counts as $l => $n): ?><div class="card stat"><div class="ico b">●</div><div><div class="lbl"><?= e($l) ?></div><div class="val"><?= (int)$n ?></div></div></div><?php endforeach; ?>
  <a class="card stat" href="/wallet" style="color:inherit"><div class="ico">◈</div><div><div class="lbl">Wallet (available)</div><div class="val"><?= money($bal['available']) ?></div></div></a>
</div>
<div class="card mt"><div class="card-head"><h3>Recent orders</h3><a href="/account/orders">View all</a></div>
<?php if (!$recent): ?><div class="empty">No orders yet. <a href="/">Start shopping</a></div><?php else: ?>
<div class="table-wrap"><table><thead><tr><th>Order</th><th>Store</th><th>Date</th><th>Status</th><th class="right">Total</th></tr></thead><tbody>
<?php foreach ($recent as $o): ?><tr><td><a class="mono" href="/account/orders/<?= e($o['code']) ?>"><?= e($o['code']) ?></a></td><td><?= e($o['store_name']) ?></td><td><?= dt($o['created_at'], 'M j, Y') ?></td><td><?= status_badge($o['status']) ?></td><td class="right"><?= money($o['total']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div>
