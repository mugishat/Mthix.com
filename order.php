<?php /** @var array $o,$items,$events,$comm,$next; @var ?array $pay,$job */
$flow = ['paid' => 'Paid', 'confirmed' => 'Confirmed', 'packed' => 'Packed', 'shipped' => 'Shipped', 'out_for_delivery' => 'Out for delivery', 'delivered' => 'Delivered', 'completed' => 'Completed'];
$keys = array_keys($flow); $pos = array_search($o['status'], $keys, true);
$reached = []; foreach ($events as $ev) { $reached[$ev['to_status']] = $ev['created_at']; }
$mine = array_values(array_filter($comm, fn($c) => $c['user_id'] == \App\Core\Auth::id() && $c['party'] === 'seller'))[0] ?? null; ?>
<div class="crumbs"><a href="/seller/orders">Orders</a> › <?= e($o['code']) ?></div>
<div class="page-head"><div><h1>Order <?= e($o['code']) ?> <?= status_badge($o['status']) ?></h1><div class="muted">Placed <?= dt($o['created_at']) ?></div></div>
  <div class="row no-print"><button class="btn btn-light" type="button" id="print-btn">Print packing slip</button><script src="<?= asset('seller.js') ?>" defer></script></div></div>

<div class="card mb"><ul class="timeline">
<?php foreach ($flow as $k => $label): $done = isset($reached[$k]); $cur = $o['status'] === $k; ?>
  <li class="<?= $done ? 'done' : '' ?> <?= $cur ? 'cur' : '' ?>"><i><?= $done ? '✓' : '' ?></i><b><?= e($label) ?></b><?= $done ? dt($reached[$k], 'M j, H:i') : 'Pending' ?></li>
<?php endforeach; ?></ul>
<?php if (in_array($o['status'], ['cancelled', 'refunded'], true)): ?><p class="neg mt">This order was <?= e(status_label($o['status'])) ?>. Any held funds were returned to the buyer.</p><?php endif; ?></div>

<?php if ($next): ?>
<div class="card mb no-print"><h3>Next step</h3><div class="row">
  <?php if (in_array('confirmed', $next, true)): ?>
    <form method="post" action="/seller/orders/<?= e($o['code']) ?>/confirm" data-once><?= csrf_field() ?><button class="btn" type="submit">Confirm order</button></form>
    <span class="muted">Confirm once you have the items and can fulfil this order.</span><?php endif; ?>
  <?php if (in_array('packed', $next, true)): ?>
    <form method="post" action="/seller/orders/<?= e($o['code']) ?>/pack" data-once><?= csrf_field() ?><button class="btn" type="submit">Mark as packed</button></form>
    <span class="muted">A courier job is created automatically and the buyer is notified.</span><?php endif; ?>
  <?php if (in_array('cancelled', $next, true)): ?>
    <form class="row" method="post" action="/seller/orders/<?= e($o['code']) ?>/cancel" data-once><?= csrf_field() ?>
      <input type="text" name="reason" placeholder="Reason for cancelling (the buyer sees this)" required minlength="3" style="width:290px">
      <button class="btn btn-danger" type="submit" data-confirm="Cancel this order? The buyer will be refunded and the stock returned.">Cancel order</button></form><?php endif; ?>
</div></div>
<?php elseif ($o['status'] === 'packed'): ?><div class="card mb"><b>Waiting for a courier.</b> <span class="muted">Hand the parcel over when a delivery partner arrives; they mark it as picked up.</span></div><?php endif; ?>

<div class="grid g3 mb">
  <div class="card"><h3>Customer</h3><p><b><?= e($o['ship_name']) ?></b><br><?= e($o['ship_phone']) ?></p></div>
  <div class="card"><h3>Delivery</h3><p><?= e($o['ship_address']) ?></p><p class="small muted"><?= e(cfg('delivery_methods')[$o['delivery_method']]['label'] ?? $o['delivery_method']) ?><?php if ($job && $job['partner']): ?> · Courier: <?= e($job['partner']) ?><?php endif; ?></p></div>
  <div class="card"><h3>Payment</h3><dl class="kv"><dt>Method</dt><dd><?= e(cfg('payment_methods')[$pay['method'] ?? ''] ?? '—') ?></dd><dt>Paid on</dt><dd><?= dt($o['paid_at']) ?></dd><dt>Reference</dt><dd class="small"><?= e($pay['internal_txn'] ?? '—') ?></dd></dl></div>
</div>

<div class="grid g-main mb">
  <div class="card"><h3>Items (<?= count($items) ?>)</h3><div class="table-wrap"><table><thead><tr><th>Product</th><th>SKU</th><th class="right">Price</th><th class="right">Qty</th><th class="right">Total</th></tr></thead><tbody>
    <?php foreach ($items as $i): ?><tr><td><b><?= e($i['product_name']) ?></b><div class="small muted"><?= e($i['variant_label']) ?></div></td><td class="mono"><?= e($i['sku'] ?? '') ?></td>
      <td class="right"><?= money($i['unit_price']) ?></td><td class="right"><?= (int)$i['qty'] ?></td><td class="right"><?= money($i['line_total']) ?></td></tr><?php endforeach; ?></tbody></table></div></div>
  <div class="card"><h3>Your settlement</h3><div class="totals">
    <div><span>Products</span><span><?= money($o['subtotal']) ?></span></div>
    <?php foreach ($comm as $c): if ($c['party'] === 'seller') { continue; } ?><div class="muted"><span><?= e(ucfirst($c['party'])) ?> (<?= $c['rate_bps'] / 100 ?>%)</span><span>− <?= money($c['amount']) ?></span></div><?php endforeach; ?>
    <div class="grand"><span>You receive</span><span><?= $mine ? money($mine['amount']) : '—' ?></span></div></div>
    <p class="hint">Delivery (<?= money($o['delivery_fee']) ?>) is paid by the buyer to the courier and is not part of your earnings. Your money is held in escrow and becomes available when the order is completed.<?= $mine ? ' Status: ' . e(status_label($mine['status'])) . '.' : '' ?></p></div>
</div>

<div class="card"><h3>History</h3><ul class="event-list">
<?php foreach (array_reverse($events) as $ev): ?><li><b><?= e(status_label($ev['to_status'])) ?></b> <span class="muted">· <?= e($ev['actor_kind']) ?> · <?= dt($ev['created_at']) ?></span><?= $ev['reason'] ? '<div class="small">' . e($ev['reason']) . '</div>' : '' ?></li><?php endforeach; ?></ul></div>
