<?php /** @var array $rows,$pg,$tabs,$badge; @var string $tab,$q */ ?>
<div class="page-head"><div><h1>Orders</h1><div class="muted">Confirm, pack and track every order from your store.</div></div></div>
<div class="tabs"><?php foreach ($tabs as $k => [$label]): ?>
  <a class="<?= $tab === $k ? 'on' : '' ?>" href="?<?= e(http_build_query(['tab' => $k])) ?>"><?= e($label) ?> (<?= (int)$badge[$k] ?>)</a><?php endforeach; ?></div>
<form class="filters" method="get"><input type="hidden" name="tab" value="<?= e($tab) ?>">
  <div class="field"><label for="q">Search</label><input id="q" type="search" name="q" value="<?= e($q) ?>" placeholder="Order no., name or phone"></div>
  <div class="field"><label for="f">From</label><input id="f" type="date" name="from" value="<?= e(get('from')) ?>"></div>
  <div class="field"><label for="t">To</label><input id="t" type="date" name="to" value="<?= e(get('to')) ?>"></div>
  <button class="btn btn-light" type="submit">Filter</button></form>
<div class="card">
<?php if (!$rows): ?><div class="empty">No orders match.</div><?php else: ?>
<div class="table-wrap"><table><thead><tr><th>Order</th><th>Customer</th><th>Date</th><th class="right">Items</th><th class="right">Product total</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $o): ?><tr>
  <td><a href="/seller/orders/<?= e($o['code']) ?>"><b><?= e($o['code']) ?></b></a></td>
  <td><?= e($o['ship_name']) ?><div class="small muted"><?= e($o['ship_phone']) ?></div></td><td><?= dt($o['created_at']) ?></td>
  <td class="right"><?= (int)$o['units'] ?></td><td class="right"><?= money($o['subtotal']) ?></td><td><?= status_badge($o['status']) ?></td>
  <td class="right"><a class="btn btn-light btn-sm" href="/seller/orders/<?= e($o['code']) ?>"><?= in_array($o['status'], ['paid', 'confirmed'], true) ? 'Process' : 'View' ?></a></td></tr><?php endforeach; ?>
</tbody></table></div>
<?= \App\Core\View::render('seller/_pager', ['pg' => $pg], null) ?><?php endif; ?></div>
