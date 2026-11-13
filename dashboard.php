<?php /** @var array $kpi,$labels,$vals,$top,$donut,$low,$recent,$bal,$store,$me; @var int $todo */
$d = function (?float $x): string {
    if ($x === null) { return ''; }
    return '<span class="sv-delta ' . ($x >= 0 ? 'pos' : 'neg') . '">' . ($x >= 0 ? '▲ ' : '▼ ') . abs($x) . '% vs last month</span>';
}; ?>
<div class="page-head">
  <div><h1>Seller dashboard</h1><div class="muted">Welcome back, <?= e(explode(' ', $me['name'])[0]) ?>. Here’s how <?= e($store['name']) ?> is doing.</div></div>
  <a class="btn" href="/seller/products/new">+ Add product</a>
</div>

<?php if ($me['kyc_status'] !== 'verified'): ?>
  <div class="sv-alert">Your identity is <b><?= e(status_label($me['kyc_status'])) ?></b>. Complete verification to withdraw your earnings. <a href="/kyc">Open verification →</a></div>
<?php endif; ?>
<?php if ($todo): ?>
  <div class="sv-alert"><b><?= $todo ?></b> order<?= $todo > 1 ? 's need' : ' needs' ?> your attention. <a href="/seller/orders?tab=new">Review orders →</a></div>
<?php endif; ?>

<div class="grid g5 mb">
  <div class="card stat"><span class="ico">₣</span><div><div class="lbl">Sales this month</div><div class="val"><?= money($kpi['sales']) ?></div><?= $d($kpi['sales_d']) ?></div></div>
  <div class="card stat"><span class="ico b">☰</span><div><div class="lbl">Orders</div><div class="val"><?= number_format($kpi['orders']) ?></div><?= $d($kpi['orders_d']) ?></div></div>
  <div class="card stat"><span class="ico p">▣</span><div><div class="lbl">Active products</div><div class="val"><?= number_format($kpi['products']) ?></div></div></div>
  <div class="card stat"><span class="ico a">◉</span><div><div class="lbl">Product views</div><div class="val"><?= number_format($kpi['views']) ?></div></div></div>
  <div class="card stat"><span class="ico">◈</span><div><div class="lbl">Total earnings</div><div class="val"><?= money($kpi['earned']) ?></div></div></div>
</div>

<div class="grid g-main mb">
  <div class="card"><div class="card-head"><h3>Sales, last 30 days</h3></div><?= chart_line($labels, ['Sales' => $vals]) ?></div>
  <div class="card"><div class="card-head"><h3>Wallet</h3><a href="/seller/wallet">Open</a></div>
    <dl class="kv">
      <dt>Available</dt><dd><?= money($bal['available']) ?></dd>
      <dt>Pending (in escrow)</dt><dd><?= money($bal['pending']) ?></dd>
      <dt>Withdrawn</dt><dd><?= money($bal['withdrawn']) ?></dd>
      <dt>Total earnings</dt><dd><?= money($bal['total_earnings']) ?></dd>
    </dl>
    <p class="hint">Pending money is released when the buyer confirms delivery.</p>
    <a class="btn btn-block" href="/seller/wallet">Withdraw earnings</a>
  </div>
</div>

<div class="grid g3 mb">
  <div class="card"><div class="card-head"><h3>Top selling products</h3><a href="/seller/products">View all</a></div>
    <?php if (!$top): ?><div class="empty">No sales yet.</div><?php else: ?>
    <div class="table-wrap"><table><thead><tr><th>Product</th><th class="right">Sold</th><th class="right">Revenue</th></tr></thead><tbody>
      <?php foreach ($top as $t): ?><tr><td><a href="/seller/products/<?= (int)$t['product_id'] ?>/edit"><?= e($t['product_name']) ?></a></td><td class="right"><?= (int)$t['sold'] ?></td><td class="right"><?= money($t['revenue']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
  </div>
  <div class="card"><div class="card-head"><h3>Orders by status</h3></div>
    <?php if (!array_sum($donut)): ?><div class="empty">No orders yet.</div><?php else: ?>
    <div class="row"><?= chart_donut($donut, (string)array_sum($donut), 'Orders', 150) ?><div class="grow"><?= chart_legend($donut) ?></div></div><?php endif; ?>
  </div>
  <div class="card"><div class="card-head"><h3>Low stock alert</h3><a href="/seller/products">Manage</a></div>
    <?php if (!$low): ?><div class="empty">All stocked up 👍</div><?php else: ?>
    <div class="table-wrap"><table><thead><tr><th>Item</th><th class="right">Left</th><th></th></tr></thead><tbody>
      <?php foreach ($low as $l): ?><tr>
        <td><a href="/seller/products/<?= (int)$l['pid'] ?>/variants"><?= e($l['name']) ?></a><div class="small muted"><?= e(implode(' / ', array_filter([$l['color'], $l['size']])) ?: $l['sku']) ?></div></td>
        <td class="right"><b><?= (int)$l['stock'] ?></b></td>
        <td><span class="badge <?= (int)$l['stock'] === 0 ? 'badge-bad' : 'badge-warn' ?>"><?= (int)$l['stock'] === 0 ? 'Out' : 'Low' ?></span></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
  </div>
</div>

<div class="card"><div class="card-head"><h3>Recent orders</h3><a href="/seller/orders">View all</a></div>
  <?php if (!$recent): ?><div class="empty">Orders will appear here once buyers pay.</div><?php else: ?>
  <div class="table-wrap"><table><thead><tr><th>Order</th><th>Customer</th><th>Date</th><th class="right">Amount</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($recent as $o): ?><tr>
      <td><a href="/seller/orders/<?= e($o['code']) ?>"><?= e($o['code']) ?></a></td><td><?= e($o['ship_name']) ?></td><td><?= dt($o['created_at']) ?></td>
      <td class="right"><?= money($o['subtotal']) ?></td><td><?= status_badge($o['status']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</div>
