<div class="page-head"><h1>Withdrawals</h1></div>
<div class="tabs"><?php foreach (['queue' => 'To process', 'paid' => 'Paid / rejected', 'all' => 'All'] as $k => $l): ?><a href="?tab=<?= $k ?>" class="<?= $tab === $k ? 'on' : '' ?>"><?= $l ?></a><?php endforeach; ?></div>
<div class="card"><div class="table-wrap"><table>
  <thead><tr><th>#</th><th>User</th><th class="right">Amount</th><th>Pay to</th><th>Risk flags</th><th>Status</th><th>Action</th></tr></thead><tbody>
  <?php foreach ($rows as $w): ?><tr>
    <td>W<?= (int)$w['id'] ?><div class="small muted"><?= e(dt($w['created_at'])) ?></div></td>
    <td><b><?= e($w['name']) ?></b><div class="small muted"><?= e($w['email']) ?> · KYC <?= e($w['kyc_status']) ?></div></td>
    <td class="right"><b><?= money($w['amount']) ?></b></td><td><?= e($w['method']) ?><div class="mono"><?= e($w['account']) ?></div></td>
    <td><?php foreach (array_filter(explode(',', (string)$w['risk_flags'])) as $f): ?><span class="badge badge-warn"><?= e(status_label($f)) ?></span> <?php endforeach; ?><?= $w['risk_flags'] ? '' : '<span class="muted small">None</span>' ?></td>
    <td><?= status_badge($w['status']) ?><?php if ($w['note']): ?><div class="small muted"><?= e($w['note']) ?></div><?php endif; ?></td>
    <td><?php if (in_array($w['status'], ['review', 'approved'], true)): ?>
      <form method="post" action="/admin/withdrawals/<?= (int)$w['id'] ?>/x" class="row" style="gap:6px" data-once><?= csrf_field() ?>
        <input type="text" name="note" placeholder="Note (for reject)" aria-label="Note" style="width:130px">
        <?php if ($w['status'] === 'review'): ?><button class="btn btn-sm" type="submit" formaction="/admin/withdrawals/<?= (int)$w['id'] ?>/approve">Approve</button>
        <?php else: ?><button class="btn btn-sm" type="submit" formaction="/admin/withdrawals/<?= (int)$w['id'] ?>/paid" data-confirm="Confirm the money was sent to <?= e($w['account']) ?>?">Mark paid</button><?php endif; ?>
        <button class="btn btn-danger btn-sm" type="submit" formaction="/admin/withdrawals/<?= (int)$w['id'] ?>/reject" data-confirm="Reject and return the money to the user’s wallet?">Reject</button></form>
      <?php else: ?><span class="muted small">—</span><?php endif; ?></td></tr>
  <?php endforeach; ?><?php if (!$rows): ?><tr><td colspan="7" class="empty">Nothing here.</td></tr><?php endif; ?></tbody></table></div></div>
