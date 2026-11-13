<div class="page-head"><h1>Payments &amp; escrow</h1></div>
<div class="grid g4 mb">
  <?php foreach (['held' => 'Held in escrow', 'released' => 'Released', 'refunded' => 'Refunded'] as $k => $l): ?>
    <div class="card"><div class="lbl muted"><?= $l ?></div><div class="val" style="font-size:21px;font-weight:800"><?= money($escrow[$k]['amount'] ?? 0) ?></div><div class="small muted"><?= (int)($escrow[$k]['n'] ?? 0) ?> orders</div></div>
  <?php endforeach; ?>
  <div class="card"><div class="lbl muted">Reconciliation mismatches</div><div class="val <?= $mismatches ? 'neg' : 'pos' ?>" style="font-size:21px;font-weight:800"><?= $mismatches ?></div><div class="small muted">Never settled automatically</div></div>
</div>
<div class="tabs"><?php foreach (['' => 'All', 'mismatch' => 'Mismatch', 'unreconciled' => 'Unreconciled', 'reconciled' => 'Reconciled'] as $k => $l): ?><a href="?recon=<?= $k ?>" class="<?= $recon === $k ? 'on' : '' ?>"><?= $l ?></a><?php endforeach; ?></div>
<div class="card mb"><div class="table-wrap"><table>
  <thead><tr><th>Reference</th><th>Order</th><th>Method</th><th class="right">Amount</th><th>Status</th><th>Reconciliation</th><th>Date</th></tr></thead><tbody>
  <?php foreach ($rows as $p): ?><tr class="<?= $p['recon_status'] === 'mismatch' ? 'row-bad' : '' ?>"><td class="mono"><?= e($p['external_ref']) ?></td><td><a href="/admin/orders/<?= e($p['code']) ?>"><?= e($p['code']) ?></a> <?= status_badge($p['order_status']) ?></td>
    <td><?= e($p['method']) ?></td><td class="right"><?= money($p['amount']) ?></td><td><?= status_badge($p['status']) ?></td>
    <td><?= $p['recon_status'] === 'mismatch' ? '<span class="badge badge-bad">Mismatch</span>' : e(status_label($p['recon_status'])) ?></td><td class="small nowrap"><?= e(dt($p['created_at'])) ?></td></tr>
  <?php endforeach; ?><?php if (!$rows): ?><tr><td colspan="7" class="empty">No payments.</td></tr><?php endif; ?></tbody></table></div>
  <?= App\Core\View::partial('admin_pager', ['pg' => $pg]) ?></div>
<div class="card"><h2>Escrow currently held</h2><div class="table-wrap"><table><thead><tr><th>Order</th><th>Order status</th><th class="right">Amount</th><th>Held since</th></tr></thead><tbody>
  <?php foreach ($held as $e): ?><tr><td><a href="/admin/orders/<?= e($e['code']) ?>"><?= e($e['code']) ?></a></td><td><?= status_badge($e['order_status']) ?></td><td class="right"><?= money($e['amount']) ?></td><td class="small"><?= e(dt($e['held_at'])) ?></td></tr><?php endforeach; ?>
  <?php if (!$held): ?><tr><td colspan="4" class="empty">Nothing in escrow.</td></tr><?php endif; ?></tbody></table></div></div>
<style>.row-bad{background:#fff5f5}</style>
